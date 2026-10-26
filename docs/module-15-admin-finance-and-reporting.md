# Module 15 admin finance and reporting

Implemented against the 26 October 2026 Module 15 guide: **Rates, client assignments, wallet administration, disputes, reconciliations, per-attempt durations, charges and exports.** The release gate is that financial reports reconcile to the append-only ledger and authoritative call records.

This module is read-only over financial history. It does not rewrite ratings, ledger entries, payments or settlements. Manual credits, dispute resolution credits and refunds append new records.

## Rate plans and client assignments

`/billing/rates` requires `billing.manage` and provides:

- Immutable tariff versions with code, version, currency, minor-unit scale, minor units per billed minute, playback policy and approval evidence.
- Client assignment with a required reason. Assignments are audited as `billing.tariff_assigned`.
- A client-assignment table showing the assigned version, wallet denomination, available credit and a wallet/tariff mismatch warning.
- Summary counts for versions, assigned clients, clients without a tariff and mismatched wallets.

A tariff version cannot be edited or deleted. Creating a new commercial price means creating a new version and assigning it. Assigning a tariff whose currency or minor-unit scale differs from an existing wallet is rejected; otherwise admission would fail closed later for every call. The existing `php artisan billing:tariff` and `billing:assign` commands remain available for controlled deployment work.

## Wallet administration and manual credit

`/billing/wallets` and `/billing/wallets/{client}` already provide opening, funding, adjustments, reversals, holds, settlement history and a live ledger verification verdict. This module adds:

- A first-class **Manual credit** form and `POST /billing/wallets/{client}/credit` endpoint for **Administrator** and **Super Administrator** accounts with `billing.manage`.
- Positive-amount validation, a per-form idempotency key, one append-only `funding` entry, and an audit record. An external approval reference is optional because the authenticated administrator is the authorization; when omitted the ledger records the administrator identity and timestamp.
- A CSV statement download for the client ledger.

Clients cannot invoke the manual-credit or statement endpoints. Manual credit can never create a negative balance, and it does not bypass tariff admission.

## Billing disputes

`billing_disputes` records an immutable client or administrator dispute with an optional payment, attempt, ledger-entry or disputed-amount reference. `/billing/disputes` is the Finance queue; `/wallet/disputes` is the client's own read/submit view.

- Client submissions are scoped to the authenticated membership. Referenced payments, attempts and ledger entries must belong to that client.
- Finance resolves a dispute as `resolved` or `rejected` with a written resolution. A resolution may award a credit; the credit posts as an append-only `adjustment` entry with idempotency key `billing:dispute:{public_id}` and is linked from the dispute.
- A resolved or rejected dispute is final. Re-resolving cannot post a second credit.
- A client can see only its own disputes. Administrators with `billing.manage` see the queue across clients.
- A dispute credit is a wallet adjustment, not a provider refund. Provider refunds remain Module 14's separately audited operation.

## Finance report and reconciliation

`/billing/finance` requires `billing.manage` and reports by date range, client and currency:

- Attempts, answered attempts, verified duration, billable minutes, rated charges, reservation state, settlement amount and shortfall.
- Ledger movement for funding, adjustments, settlement debits and reversals, plus current available/reserved balances by currency.
- Payment credits, refunds and unconfirmed payment counts by currency.
- Per-client usage, current wallet balance, charges, settled amount, shortfall and open disputes.
- A reconciliation verdict that verifies every wallet's stored balances, ledger chain, hold lifecycle and settlement arithmetic, and reruns the read-only payment/callback reconciliation. It also surfaces unresolved billing exceptions and open disputes.

Opening the report never repairs a balance. If the ledger verification fails, the page says so and requires escalation before further credits.

## Exports

- `GET /billing/finance/export` streams the same per-attempt report rows as CSV.
- `GET /billing/wallets/{client}/statement` streams the administrator ledger statement.
- `GET /wallet/statement` streams the client's own ledger statement.

CSV exports are permission-scoped, sent with `private, no-store`, and neutralise spreadsheet formula prefixes in free-text fields. Amounts are exported as exact minor units plus currency and scale, not floats.

## Permissions, audit and roles

- All administrator finance surfaces require `billing.manage`; Super Administrator retains the global bypass.
- The seeder now provides an **Administrator** role with `billing.manage` and operational client/finance access, in addition to Super Administrator.
- Client surfaces require an active membership and `dashboard.view`, and remain tenant-scoped.
- Manual credits audit as `billing.wallet_funding`; assignments audit as `billing.tariff_assigned`; disputes audit as `billing.dispute_opened`, `billing.dispute_resolved` or `billing.dispute_rejected`; resolution credits also retain the ordinary wallet adjustment audit.

## Deployment

```sh
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan optimize:clear
php artisan view:cache
```

The migration adds reporting indexes and `billing_disputes`. Its `down()` intentionally refuses to destroy financial history; use a reviewed archival migration.

## Validation and remaining acceptance

`FinanceAdminModuleTest` covers Administrator/Super Administrator manual credit, client denial, positive and idempotent credit, immutable rate versions, client assignment, wallet-denomination rejection, finance report rows and CSV export, ledger-tamper detection without automatic repair, admin dispute credit once through the ledger, client dispute tenancy, and administrator/client statement exports. Module 13 wallet, Module 14 payment and the earlier campaign/rating suites remain regression coverage.

Remaining external/business acceptance: tax and invoice policy, credit-expiry policy, silent-answered-call dispute/refund policy, provider UAT and settlement evidence, and Finance sign-off on actual rates and approval references. This release does not move real money or invent commercial policy.
