# Module 13 calling wallet

Implemented against the 26 October 2026 Module 13 guide. Calling credit is prepaid: Finance opens a wallet, posts approved funding, and each billable call is admitted only against credit that is already held. Prices still come exclusively from approved Module 12 tariffs; this module adds no pricing policy and no automatic top-ups.

## Balances, holds and ledger

`wallets` holds one account per client with a three-letter currency, a minor-unit scale and integer `available_minor`/`reserved_minor` balances. Amounts are never floats. `Wallet::amount()` renders a decimal string for display and `Wallet::parse()` rejects over-precision, zero and oversized input, so a posted amount always matches the stored integer exactly.

`wallet_reservations` is unique per campaign recipient for a campaign hold and per `call_attempt_id` for an attempt hold. Interactive attempts and attempts without a measured recording cover the maximum possible charge for one attempt: `ceil(BILLING_MAX_CALL_SECONDS / 60)` billed minutes at the attempt's frozen tariff rate. The default `BILLING_MAX_CALL_SECONDS=900` therefore holds fifteen minutes; at an approved rate of 30 minor units per minute the hold is 450 minor units. Values outside 60–86,400 seconds are rejected rather than silently clamped.

One-way campaigns reserve the calculated recording cost instead, never the maximum call exposure. At launch the hold is `ceil(recording milliseconds / 60,000)` billed minutes at the frozen tariff rate — the same `ceil_minute_v1` rounding that settlement applies — multiplied by the eligible contacts in the campaign audience (the frozen contact group plus any explicitly added contact IDs, less DND/suppression and changed contacts). A 45-second recording for 500 contacts at 30 minor units per minute therefore holds 500 billed minutes (15,000 minor units), not 500 fifteen-minute calls. `wallet_campaign_recipient_unique` keeps one hold per recipient per launch snapshot, dispatch converts that hold into the attempt's reservation without debiting again, and unused holds are released when a recipient is excluded or the campaign is cancelled or completes. A recording with no measured length refuses launch with `duration_missing` instead of quoting the maximum exposure.

`wallet_ledger_entries` is append-only. Every balance movement appends exactly one entry carrying type (`funding`, `adjustment`, `reservation`, `release`, `settlement`, `reversal`), signed available/reserved deltas, resulting balances, currency, scale, reason, approval reference, actor, optional attempt/reservation links, an idempotency key and an optional `reversal_of_id`. Keys are unique: `billing:reservation:{attempt}`, `billing:release:{attempt}`, `billing:settlement:{attempt}`, `billing:reversal:{entry}` and admin-supplied or `admin:{uuid}` for funding. Replaying the same request returns the original entry; reusing a key against another wallet is rejected. The migration's `down()` refuses to drop financial history.

## Admission and fail-closed origination

Admission is evaluated before origination and refuses with one of `wallet_missing`, `tariff_missing`, `tariff_not_priced`, `currency_mismatch`, `duration_missing` or `insufficient_credit`. Accounts without a wallet are "not applicable": they keep the previous manual/unpriced behaviour until Finance opens one, so enabling this module does not retroactively block existing clients.

A wallet-backed account must have a tariff snapshot whose currency and scale match the wallet, a priceable rate and available credit at least equal to one full hold. Opening a wallet while an approved tariff is assigned requires the same currency and minor-unit scale; a mismatch is rejected so Finance corrects the denomination before funding. The engine reserves credit inside a locked transaction immediately after creating the attempt; if the reservation fails, the attempt is not originated and the decline is surfaced to the operator. Accounts without enough credit for one full call cannot start or continue a campaign.

A duplicate reserve for the same attempt returns the existing hold instead of holding twice. An attempt whose reservation was already released or settled can never be reserved again.

## Settlement, release and shortfall

Module 12 ratings settle the wallet exactly once. Settlement releases the hold back to available credit, then debits `min(charge, available)`. A charge above the hold is still applied in full when credit allows; any uncovered remainder is recorded on the reservation as `shortfall_minor`, raised as a `wallet_shortfall` billing exception and written to the audit log with charge, paid and shortfall amounts. Nothing is silently forgiven and no negative balance is ever created.

Replays and resubmitted evidence never double-charge. A rating whose attempt predates the wallet returns `no_reservation` and leaves history untouched. A rating whose currency does not match the wallet is held for review rather than converted.

Definitively unanswered attempts (no answer, busy, failed) release the hold with the outcome recorded as the release reason and no usage charge. Attempts still awaiting trusted rating evidence keep their hold, because the charge is unknown; `billing:wallet-expire` is the reviewed backstop for holds that never receive final evidence. It releases only holds older than the requested age (default 24 hours, maximum 720) whose attempt has no final rating, and it appends the same `release` entry, so it is idempotent. Expiry records the billing administrator as the release actor and writes a `billing.wallet_hold_expired` audit entry. Expiry never writes off a charge: a later settlement still debits available credit and raises a shortfall exception if the credit has since been used.

## Administration and visibility

`billing:wallet-open`, `billing:wallet-adjust`, `billing:wallet-show` and `billing:wallet-expire` require `--actor` with `billing.manage` (seeded for Super Administrator). `billing:wallet-adjust` takes `--kind=funding|adjustment`, `--amount`, `--reason`, `--approval` and optional `--key`; funding must be positive, and a debit larger than available credit is refused in favour of a reviewed receivable. `billing:wallet-show` prints stored balances, ledger sums, held reservations, chain and lifecycle reconciliation, and exits unsuccessfully when any check fails, so it can gate monitoring.

`/billing/wallets` and `/billing/wallets/{client}` are restricted to `billing.manage`, are marked `private, no-store`, and show the ledger, holds, reversal state, an on-page reconciliation verdict, assigned tariff, one-call exposure, full-hold coverage and stale-hold backlog. Ledger and reservation filters narrow the console without changing balances. Only funding and adjustment entries can be reversed, an entry can be reversed once, and a reversal is a new entry with its own approval reference. Settlements are corrected with a reviewed adjustment referencing the attempt, never by editing history. `/clients/{client}?tab=finance` adds the same wallet, tariff and reconciliation view to the client record for `billing.manage` holders; managers with only `clients.manage` see that finance detail requires the billing permission.

The client portal implements the tracker's Wallet → Balance and Transactions items. `/wallet` is read-only, tenant-scoped and shows available/reserved credit, calls in flight, paid-to-date, a low-credit indicator based on one full hold at the assigned tariff, optional type/state filters, the append-only ledger and call holds; the dashboard credit panel links to it and surfaces the same low-credit notice. Clients can never reach the admin console or another tenant's wallet. Online top-up initiation, verified callbacks and receipts are implemented in Module 14; administrator and client ledger statement exports are implemented in Module 15.

## Deploying

Deploy code, migrate, seed the permission, then restart long-running gateway/workers through the normal service process:

```sh
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan optimize:clear
```

The migration only creates the three wallet tables. It does not open wallets, post funding, assign tariffs or change dispatch behaviour. Rollback requires a reviewed archival migration.

After Finance approval:

```sh
php artisan billing:wallet-open CLIENT_ID --currency=GHS --scale=2 --reason="Approved prepaid onboarding" --actor=ADMIN_ID
php artisan billing:wallet-adjust CLIENT_ID --kind=funding --amount=250.00 --reason="Approved prepaid top-up" --approval="FIN-REFERENCE" --actor=ADMIN_ID
php artisan billing:wallet-show CLIENT_ID --actor=ADMIN_ID
php artisan billing:wallet-expire --hours=24 --actor=ADMIN_ID
```

## Validation and remaining acceptance

Tests cover exact minor-unit funding, idempotent replay, cross-wallet key rejection, over-debit refusal, reversal permitted once and only for funding/adjustment, permission and tenancy denial, admission refusal without wallet/tariff/match/credit/duration, tariff-matched wallet opening, launch blocking while unfunded, launch holds equal to recorded minutes × eligible contacts with full release on cancel, unmeasured recordings refusing launch without holding credit, held-credit arithmetic, reserve fail-closed and duplicate holds, settle-once with replay, unanswered release, shortfall exception and audit, stale-hold expiry with actor audit and idempotent ledger append, read-only client wallet balance/history with filters and tenant isolation, admin coverage and backlog summaries, billing-permission gating on the client finance tab, ledger-chain and settlement-lifecycle tamper detection, denomination immutability after history, and one-call affordability. The Module 12 rating, campaign, gateway and audio suites remain regression coverage.

Local schema installation and permission seeding are separate from production deployment. No production currency, tariff, funding, approval reference or `BILLING_MAX_CALL_SECONDS` value was configured here; confirm the maximum billable call duration with Finance before enabling wallet-backed campaigns. Prepaid invoicing/receipting, payment-gateway top-ups and Finance's shortfall recovery process remain external release gates.

## Mandatory client billing update — 27 October 2026

All client-owned campaigns now require a wallet before launch and before each call reservation, including previously approved campaigns. Missing wallets decline with wallet_missing. The assigned tariff must match the wallet denomination, be priceable, and have enough available credit for the hold the campaign actually needs: one-way launches hold the measured recording cost for every eligible contact in the audience, while interactive launches keep the configured maximum call hold. Reviewed zero-rate tariffs retain their existing behavior. Internal campaigns with no client_id remain outside client wallet billing.

Before enabling calls for a client, Finance must open its wallet, assign the approved matching tariff, and load sufficient credit. No balance or tariff is fabricated automatically. This does not retroactively charge historical calls.

## Scheduled hold cleanup

The application registers `billing:wallet-expire --scheduled` hourly with overlap protection. Configure the following production environment settings after approving the age threshold against normal CDR/CEL delays:

```
BILLING_HOLD_CLEANUP_ENABLED=true
BILLING_HOLD_CLEANUP_ACTOR_ID=<internal billing.manage user ID>
BILLING_HOLD_CLEANUP_HOURS=24
BILLING_HOLD_CLEANUP_LIMIT=500
```

The default is disabled, with no actor selected. Missing/unauthorized actors fail closed. Age is bounded to 1–720 hours and batches to 1–1000 holds. Manual invocation retains its existing actor/hours/limit options. Rebuild config after deployment, ensure the existing scheduler timer/cron runs, and check `php artisan schedule:list`. Do not add another scheduling mechanism if one is already running.

Monitor structured application logs: `billing.hold_cleanup_invalid_actor`, `billing.hold_cleanup_failed`, `billing.hold_cleanup_disabled`, `billing.hold_cleanup_backlog`, and `billing.hold_cleanup_completed`. Completion includes released/remaining counts. Connect failure/backlog events to the production log alerting system; these logs alone do not send email/SMS alerts. Investigate recurring backlogs or stale rated holds rather than blindly increasing expiry throughput. Review release audit entries and reconcile late timing evidence; expiration does not forgive later charges.
