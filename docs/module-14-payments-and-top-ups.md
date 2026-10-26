# Module 14 online payments and top-ups

Implemented against the 26 October 2026 Module 14 guide. This module adds verified online top-ups to the Module 13 prepaid wallet. It does not change call pricing, settlement, dispatch admission or manual Finance posting; it only creates a second, provider-verified path to post a `funding` entry.

The provider adapter is configuration-driven and **disabled by default**. The Xcel API contract, sandbox credentials, settlement timing and webhook signature scheme have not been verified yet. Until they are verified in provider UAT, `PAYMENT_TOPUPS_ENABLED=false` and `PAYMENT_XCEL_DRIVER=disabled` must remain in every environment.

## Configuration

| Variable | Default | Purpose |
| --- | --- | --- |
| `PAYMENT_TOPUPS_ENABLED` | `false` | Master switch for new client top-ups. |
| `PAYMENT_PROVIDER` | `xcel` | Active provider key and callback path segment. |
| `PAYMENT_MIN_AMOUNT_MINOR` | `1000` | Minimum top-up in integer minor units. GHS wallets enforce a floor of GHS 10.00. |
| `PAYMENT_MAX_AMOUNT_MINOR` | `1000000` | Maximum top-up in integer minor units. |
| `PAYMENT_PENDING_TTL_MINUTES` | `30` | Client-visible review window (clamped to 5–1440); never auto-cancels a paid intent. |
| `PAYMENT_XCEL_DRIVER` | `disabled` | `http` enables live initiation. |
| `PAYMENT_XCEL_BASE_URL` | empty | Provider API base URL. |
| `PAYMENT_XCEL_API_KEY` | empty | Bearer token for initiation. |
| `PAYMENT_XCEL_INITIATE_PATH` | `/payments` | Initiation path appended to the base URL. |
| `PAYMENT_XCEL_HTTP_TIMEOUT` | `10` | Initiation timeout, clamped to 3–60 seconds. |
| `PAYMENT_XCEL_WEBHOOK_SECRET` | empty | HMAC-SHA256 shared secret. |
| `PAYMENT_XCEL_SIGNATURE_HEADER` | `X-Payment-Signature` | Signature header, with or without `sha256=`. |
| `PAYMENT_XCEL_TIMESTAMP_HEADER` | `X-Payment-Timestamp` | Unix timestamp header. |
| `PAYMENT_XCEL_WEBHOOK_TOLERANCE` | `300` | Replay tolerance, clamped to 30–3600 seconds. |

`PaymentGateway::enabled()` requires the master switch, the `http` driver, a base URL, an API key and a webhook secret. Callback verification only requires the HTTP driver and webhook secret, so pending payments can settle after new top-ups are disabled. Missing configuration fails closed.

The documented initiation request is JSON `{reference, amount_minor, currency, minor_unit_scale, description, callback_url, return_url}` with `Idempotency-Key: <payment public id>`. A successful response must contain `provider_reference` (or `reference`, `data.reference`, `data.id`, `id`) and a safe absolute `checkout_url` (or `authorization_url`, `data.checkout_url`, `data.authorization_url`). These field names are the integration contract to confirm during UAT; they are not a claim about Xcel's current production API.


## Data model and immutable history

`payment_intents` stores one attempt per top-up: `public_id` UUID, client, wallet, provider, optional provider reference, exact integer `amount_minor`, currency, scale, status, safe checkout URL, request key, initiator, TTL, final-state timestamps, optional ledger link, refund state and metadata. Amount, client, wallet, provider, currency, scale and request key are immutable after creation. Intents cannot be deleted and the unique `(provider, provider_reference)` and `request_key` keys prevent duplicate provider objects and duplicate submissions.

`payment_events` stores every verified callback once per `(provider, provider_event_id)`, with intent/client links, event type, provider status, reference, amount, currency, scale, result, encrypted payload, SHA-256 payload hash and processing timestamp. Events are append-only. Reconciliation and the admin console read the hash and safe fields; raw payloads are encrypted at rest and never rendered.

`payment_reconciliations` stores append-only runs with actor, provider, status, checked/credited/pending/failed/refunded counts, issue count and a JSON summary. Runs cannot be updated or deleted. Reconciliation never posts balances; every listed issue requires manual investigation.

The only wallet write from this module is `Wallet::creditPayment()`, which appends a normal `funding` entry with idempotency key `payment:<public_id>`. It locks the wallet, refuses tenant/currency/scale mismatches and returns the existing entry on replay. Refunds use the existing `Wallet::reverse()` path, producing a separate `reversal` entry linked by `reversal_of_id`; no funding or receipt row is edited.

## Lifecycle and idempotency

`pending → succeeded | failed | cancelled`, and `succeeded → refunded`. A paid or refunded intent is terminal: a later failure, cancellation or duplicate success cannot undo it. A repeat of the same provider event returns the already-recorded event. A success for an already-succeeded intent with a new event id is recorded as `duplicate` and never credits twice. A failed or cancelled intent can still be credited by a later verified success because the provider may report a delayed capture; the unique request key and ledger key still prevent a double credit.

A client POST carries a UUID `request_key`, stored as `payment:<client_id>:<uuid>`. Replaying the same form submission, including a double click, returns the original intent and the original checkout URL. If two inserts race, the unique request key resolves to the winner. Provider initiation uses the payment public id as `Idempotency-Key`. Provider connection, HTTP or response-contract failures mark the intent `failed` with `failure_code=provider_error` and no ledger link; no money is assumed taken and no credit is posted.

The intent status alone never credits a wallet. Credit requires a verified `succeeded` callback, exact integer amount, exact currency and scale, matching provider reference when both sides have one, and a payment event type beginning with `payment`. Pending callbacks only update `verified_at`. A failed or cancelled callback posts no credit. Callback processing is one database transaction with a row lock on the intent.

The pending window stored in `expires_at` is a review/checkout hint, not an automatic cancellation. A late verified success still credits the wallet because money may already have been taken; only a verified failure or cancellation is terminal for a pending intent. Reconciliation flags stale pending intents so Finance can investigate them with the provider.

## Callback verification

`POST /payments/webhook/{provider}` is outside authentication and CSRF protection. It is safe only because every request is verified before state changes:

- Provider path must equal the configured provider key.
- Body must be non-empty and at most 32 KB.
- `X-Payment-Timestamp` must be digits and within the configured tolerance (30–3600 seconds).
- `X-Payment-Signature` must be HMAC-SHA256 over `<timestamp>.<raw body>` using the webhook secret; `sha256=` is accepted, comparison is constant-time.
- JSON must parse as an object with a valid event id (≤190), status (≤24), UUID reference, positive integer amount, three-letter uppercase currency, scale 0–6, optional provider reference (≤190), event type (≤64) and optional message (≤255).

Missing or bad signature/timestamp/body returns `401 {"error":"invalid_callback"}` and records no event. Unsupported provider paths return 404. Verified but unsafe payloads are recorded for review and return 200 with their result: `unknown_intent`, `amount_mismatch`, `currency_mismatch`, `provider_reference_mismatch`, `unsupported_status` or `credit_failed`. An unexpected processing error returns 500 so the provider can retry. Logs contain provider, reason and IP, never the secret, signature or payload.

## Refunds and failure handling

A refund is recorded only for a `succeeded` intent that has a credit timestamp and ledger entry. The action requires `billing.manage`, a provider refund reference, a reason and an approval reference. It locks the intent, calls `Wallet::reverse()` and sets `refunded`, `refunded_at` and `refund_reference`, with metadata recording the approval and reversal entry id. A second refund is rejected. Refund attempts by client members are forbidden. Recording a refund asserts that the provider refund has already happened; this module does not call a provider refund API because that contract is unverified.

Failed or cancelled payments retain their safe failure code/message and are visible to the client and Finance. They never have a wallet link. An initiation outage marks the intent failed; the client sees "No money was taken" style messaging rather than an unhandled error. Provider callbacks that disagree with the intent are recorded as exceptions for reconciliation, not silently accepted or used to overwrite history.

## Client and Finance screens

`/wallet/payments` lists the signed-in client's own intents with status, amount, credit time and receipt links; `/wallet/payments/{public_id}` shows details and verified provider updates, and `/wallet/payments/{public_id}/receipt` is available only when the payment is paid (including refunded history). All queries are restricted by client membership and wallet ownership, responses are `private, no-store`, and another tenant's UUID returns 404. `/wallet` now includes the top-up form when the gateway is enabled, limits from configuration, and links to payment history and receipts. When disabled, clients are told to contact Finance.

`/billing/payments` requires `billing.manage` and shows status counts, filters (status, provider, receipt/reference/client search), recent reconciliation runs and a read-only reconcile action. `/billing/payments/{public_id}` shows the intent, immutable request key, audit timestamps, ledger and reversal links, an integrity verdict from `Payments::inspect()`, paginated verified callbacks (hash only, no payload) and the refund form when eligible. `/billing/wallets/{client}` and the admin navigation link to the client-filtered payment list.

## Reconciliation

`Payments::reconcile()` is read-only with respect to balances. It checks final intents for credit timestamps, provider references, missing ledger entries, amount/currency/wallet/type mismatches, credits on unconfirmed intents, succeeded intents with reversals and refunded intents without reversals; it flags stale pending intents older than twice the TTL; it verifies each `payment:` ledger key has an intent and matches the linked entry; and it counts callback exceptions. It persists one immutable run and audits `billing.payments_reconciled`. The admin page reports "OK" or "Attention" and states that no balances were changed automatically.

## Commands and deployment

Deploy code, migrate and seed permissions; do not enable live payments in the same release without UAT sign-off:

```sh
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan optimize:clear
```

The migration creates `payment_intents`, `payment_events` and `payment_reconciliations`; its `down()` intentionally refuses to drop financial history. Review the generated schema, back up the database and archive any payment evidence before a reviewed rollback migration.

After provider UAT, configure secrets through the environment (never commit them), set `PAYMENT_XCEL_DRIVER=http`, then enable `PAYMENT_TOPUPS_ENABLED=true` and run the documented callback test against the provider sandbox. Confirm the exact field names, signature header/timestamp algorithm, idempotency behaviour, refund timing and settlement reporting in writing before production enablement. Keep the provider disabled if any item is unconfirmed.

## Validation and remaining acceptance

`PaymentModuleTest` covers idempotent client initiation and checkout redirect, exact pending amount with no premature credit, HMAC and timestamp rejection, duplicate event and duplicate success handling, amount/currency/provider-reference/unknown-intent mismatches, failed callbacks, delayed success after failure, provider outage fail-closed, single refund with reversal, refund denial for clients, reconciliation counts, tenant isolation, receipt/status pages, Finance permission gating, admin pages and disabled-provider messaging. Module 13 wallet, rating, campaign and gateway suites remain regression coverage.

Remaining acceptance is external: Xcel sandbox/API contract verification, real checkout redirect and return flow, provider webhook signature compatibility, refund settlement evidence, provider reconciliation export, fee accounting, PCI/sensitive-data review, and operational monitoring/alerting for callback failures and stale pending payments. No live provider credentials or real money movements are part of this implementation.

