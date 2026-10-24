# Stage 08 — Pricing wallet and billing ledger

Stage 08 adds versioned prices, one wallet per client account, an append-only ledger, per-attempt credit reservations, answered-call settlement, audited adjustments/refunds and reconciliation. Existing approvals, DND, media integrity, capacity controls and client isolation remain authoritative. Payment gateway/top-up integration is Stage 09 and is not part of this change.

## Default reference billing policy

The owner confirmed the following reference policy on 24 October 2026. Administrators can now create and assign alternative policy versions using the configuration screen described below:

- Currency: Ghana cedi (GHS).
- Price: **0.30 pesewas per minute**, interpreted literally as **GHS 0.003 per minute**.
- Duration basis: approved recording length, rounded up to a whole minute for each answered attempt.
- Answered calls are chargeable even when playback fails or is silent. A dispute can receive an audited refund.
- Unanswered calls, including busy and no answer, have no calling debit.

The reference wallet denomination stores integers in units of GHS 0.001, preserving this fractional-pesewa price exactly. Other denominations use the precision selected in their assigned policy. Monetary input is parsed as an exact decimal string. Extra precision is rejected rather than rounded. For example, a 60-second recording costs GHS 0.003 per answered attempt; 60.001 seconds costs GHS 0.006. An early hangup after answer still uses the full rounded recording length. A test run is subject to the same policy.

## Pricing and wallet administration

Internal staff with `billing.view` can open `/billing`; `billing.manage` authorizes price creation, assignment, manual adjustments, refunds and review decisions. Super Administrators and the new Billing Administrator role receive these permissions. Clients receive no internal finance permissions. The separate billing permission seeder adds these grants without replacing existing role permissions.

A price version fixes its currency, precision, amount, recording basis, minute rounding, answered-playback-failure treatment, author and approval/evidence reference. Changing any price or billing rule creates a new version. Assignment affects future launches. Existing executions keep their launch price, recording length and per-call charge even when the wallet is assigned a newer version.

Assigning a price creates an empty wallet if needed. It never grants credit. Wallet account, currency and precision cannot be changed. Manual credits/debits require an authorized actor, reason/evidence and idempotency reference. Repeating the same request returns its original entry; changing the details under an existing reference is rejected. Debits cannot consume held funds or create a negative balance. Funding here is an audited manual adjustment, not a claim that a payment provider verified a transaction.

A refund points to its original call-charge ledger entry. Multiple partial refunds are permitted up to the original charge; excessive refunds and cross-wallet charge references are rejected. Corrections are compensating entries. Ledger entries and rate versions reject updates and deletion at both the model layer and through database triggers on the supported MariaDB/MySQL and SQLite backends.

Clients can open `/client/wallet` to see balance, reserved credit, available credit, current assigned price and paginated transaction history. They cannot choose an account ID, edit money or view staff evidence. Shared account colleagues see the same account wallet. Suspension and private/no-store response controls apply.

## Call integration and accounting

At launch, an enabled billing engine requires an assigned price, a reconciled wallet and enough available credit for at least one call. It freezes the complete billing policy in the existing immutable campaign version/run snapshot. The review screen displays the selected policy and a call-price or reservation estimate for each selected recipient. This estimate is not a reservation for the entire campaign; later admission always depends on available credit.

Before admitting each attempt, the gateway reserves its frozen recording-based charge or actual-duration exposure estimate. The application uses the existing gateway admission mutex, wallet row locks, database transactions and unique attempt/idempotency keys to serialize competing campaigns and financial operations. Both admission and final network sending verify that the priced attempt has an intact funded reservation. Insufficient funds leave remaining recipients pending; further dialing can continue after verified credit is added. No alternative wallet, price or trunk is silently selected.

After a confirmed terminal outcome:

- Sent and answered: settle once using the frozen policy, release the remaining hold and retain any immutable charge entry. The default reference policy charges rounded recording length even on playback failure; configured alternatives can waive or review failures, or require verified actual duration.
- Confirmed unanswered or unsent: release the hold without a calling debit.
- Uncertain outcome: retain held funds for reconciliation and avoid uncontrolled redial or deductions.
- Late answer evidence after a prior release: mark for manual billing review, without automatically taking newly available funds. Staff must resolve call evidence before charging or releasing an uncertain call.

The normal event processor, authorization-failure path, disconnect handling and existing verified CDR reconciliation use the same accounting service. Event replay cannot create another debit. Turning off new billing admission does not erase existing holds or stop settlement of already sent priced calls.

Reservations are separate mutable operational records; posted money is append-only. Balance equals ledger credits plus debits. Reserved credit equals the sum of held reservation amounts. Settlement totals are compared with call-charge entries. A detected accounting mismatch blocks financial admission/adjustments and retains affected settlement for review instead of guessing a repair.

## Reconciliation and disputes

`/billing/wallets/{wallet}` shows the ledger, holds, review reasons and a consistency result. Staff may resolve a review with a recorded evidence reference only after the call has a confirmed end. Charging requires sent and answered evidence and sufficient unreserved funds. The existing telephony reconciliation command remains the way to resolve uncertain call termination using verified CDR evidence and a fresh channel snapshot.

Read-only account-wide consistency audit:

```bash
php artisan billing:reconcile
php artisan billing:reconcile --wallet=1
```

The command reports mismatches and review counts, returns failure for inconsistent totals and never rewrites money. Investigate discrepancies using the ledger, reservations and audit trail. Do not edit posted financial rows. Refund confirmed playback disputes through a linked positive compensating entry.

## Local setup and deployment

Run the additive migration, additive billing permission seeder and confirmed reference price seeder:

```bash
php artisan migrate --force
php artisan db:seed --class=BillingPermissionsSeeder --force
php artisan db:seed --class=DefaultRatePlanSeeder --force
```

The reference seeder requires an existing Super Administrator, creates `ghs-recording` v1 only when that plan does not already exist and does not assign accounts or fund wallets. The schema migration creates append-only database triggers; the deployment database user must be able to create them. Existing account, campaign, call and audio data are retained.

Billing admission defaults off:

```dotenv
VOICE_BILLING_ENABLED=false
```

Assign the confirmed price to intended accounts, record verified manual credit and complete controlled acceptance before enabling it. Client execution also requires the separate Stage 07 execution flag, normal global dispatch enablement and all PBX/capacity gates. A configured wallet cannot bypass billing by switching billing off. Old unpriced executions cannot acquire a guessed price: create a fresh priced execution after configuration. Internal non-client campaigns remain outside client charging.

After deployment refresh configuration/routes/views and restart long-running gateway/worker processes through the existing procedure. Configuration changes do not instantly reach an already running gateway. Disabling admission does not hang up calls already sent. Stage 09 verified payments remain necessary for an online paid service.

## Verification

Automated tests use isolated SQLite databases and fake audio/AMI. They cover exact fractional-pesewa pricing, minute boundaries, no automatic seed credit, immutable prices/ledger rows, insufficient funds, competing campaigns, duplicate reservations/events/adjustments, frozen prices, answered failed playback, refunds and refund caps, busy/no-answer release, authorization failures, uncertain holds, late answers, final-send gating, reconciliation mismatches, client isolation and finance access. SQLite tests exercise serialized admission behavior; they are not a production MariaDB load test or an audible PBX acceptance call.

## Administrator configurable billing policies

Billing Administrators and Super Administrators can now open **Wallets & Pricing → Configure billing policy** at `/billing`. A new version can configure:

- Currency, accounting precision and exact price per minute.
- Recording length or verified actual answered duration.
- Duration rounded upward in 1, 6, 30 or 60 second increments, with a minimum of one increment for an answered call.
- Confirmed playback failures: charge, waive or retain funds for administrator review.
- Credit reservation duration for actual-duration billing, from 1 to 86,400 seconds.

The final monetary amount rounds upward to the wallet's smallest accounting unit. For example, GHS 0.003/minute with 6-second rounding and a 61-second duration becomes 66 billable seconds and GHS 0.004 at three-decimal accounting precision. This rule avoids floating-point money calculations.

Use **Use as starting point** beside an existing policy to populate the form. Save with a reason/evidence reference, then assign the new version to the intended client account. Saving does not automatically assign it to every account. The original GHS 0.003, recording-length, whole-minute, charge-failures reference policy remains unchanged. Old price records and posted ledger entries remain append-only; current campaign runs continue using their frozen policy. Existing wallet currency/precision cannot be converted by assigning an incompatible policy.

Actual-duration pricing currently supports one-way campaigns. Its reservation duration estimates exposure and does not impose a call timeout. At termination, a call without verified `billable_seconds` retains its hold for review. The normal verified CDR reconciliation path can supply duration for uncertain calls; a finance administrator can also provide verified answered seconds and an evidence reference on an ended call's review form. Application event-receipt timestamps are not treated as verified duration. Charges within the hold settle once; a charge exceeding the reservation requires an explicit review decision and sufficient additional available credit. No automatic overdraft occurs.

A policy-based playback waiver releases the hold without a debit. A review policy retains the hold until a finance administrator explicitly charges or releases it. Replaying events does not reopen an explicit policy waiver or administrator release. Silent playback cannot be inferred merely from an answer or playback-completion event; recipient-reported disputes continue to use audited refunds or review decisions.

Apply `2026_10_24_040000_add_configurable_billing_policy` when deploying this update. It adds a reservation-duration setting with a backward-compatible default, a nullable verified-duration field and durable playback-failure timestamps; it does not rewrite historical policies, balances or snapshots. No new permission grants are required.

If confirmed failure evidence arrives after a charge was posted, a frozen waiver policy adds one linked compensating refund, bounded by any amount not already refunded. The original debit is retained. A review policy flags the posted charge for an audited refund decision; it cannot retroactively hold money already settled. Late evidence never rewrites a ledger entry.
