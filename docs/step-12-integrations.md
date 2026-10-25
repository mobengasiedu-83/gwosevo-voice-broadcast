# Stage 12 external APIs webhooks and commercial controls

Stage 12 adds client-managed scoped credentials, account API quotas, draft/import/report endpoints, signed asynchronous webhooks and internal integration-plan administration. It extends the existing services; it does not collect payments or enable campaign dispatch. Production load/security/recovery acceptance remains an operational release gate.

## Setup and activation

Deploy the code and apply both the existing integration foundation migration and the additive `2026_10_25_010000_complete_external_integrations` migration through the normal deployment procedure. Back up the existing database and APP_KEY first. The latter creates the event outbox, subscription cursors, delivery redaction marker and supporting indexes. No seeders or new role grants are required; staff use the existing `clients.manage` permission.

```sh
php artisan migrate --force
php artisan queue:restart
```

Refresh production configuration/routes/views and restart the configured gateway/Horizon processes using the deployment runbook. Do not run new business updates against the old schema. Keep the minute scheduler and default queue workers running. Webhook jobs use a 30-second job timeout, a 10-second HTTP timeout and one queue attempt; configure worker timeout/retry_after so a job cannot execute concurrently after its reservation expires.

Both switches default off and are added only to `.env.example`; installation does not change the live `.env`:

```dotenv
VOICE_API_ENABLED=false
VOICE_WEBHOOK_DELIVERY_ENABLED=false
```

Enable only in the intended accepted environment after tests, credentials, public HTTPS receivers, queue monitoring and operational ownership are in place. Refresh cached configuration and restart long-running processes after changes. These switches do not enable global dispatch, client execution, billing or payment collection.

## Client and staff screens

Clients use **API & webhooks** at `/client/integrations`. At least one verified contact and an active client account are required. Each user sees/manages their own credentials, subscriptions and delivery history; quota usage is shared across the account. Colleagues cannot revoke another user's key. Suspended accounts cannot use management or the API, and queued sends recheck account eligibility.

A credential has a name, selected scopes and a 1–365 day expiry. At most ten unexpired, unrevoked credentials exist per account. The bearer secret appears only in the creation/replacement response, with private no-store and no-referrer headers; only its SHA-256 hash is stored. It is not flashed to session storage. Replacing a credential atomically revokes the old key and creates a replacement with its scopes and remaining expiry rounded up to a day. Revocation and issuance are audited. Requests already in progress may finish; writes recheck revocation under the admission locks.

Webhook secrets are encrypted using APP_KEY and displayed once on creation/replacement. Rotation applies to future sends, including pending retries; an in-flight request can carry the previous signature. Disable stops future eligible sends; in-flight sends may finish. To change an endpoint or event selection, disable it and create a new subscription. Keep the previous receiver secret briefly during a coordinated rotation if an in-flight send is possible.

Staff with `clients.manage` use **Integrations & API** at `/integration-plans`. They create bounded quota plans, assign a plan or restore defaults, and inspect daily account usage. Plan creation/assignment is audited. Plans set requests per minute/day, imported rows/day, drafts/day and active webhook count. They are separate from financial rate versions and trunk capacity. Zero denies the corresponding new usage. Lowering a subscription limit prevents new subscriptions but does not automatically disable existing ones; coordinate those removals with the client. Existing plans have no edit/delete UI; create another plan and assign it for an attributable policy change.

Default account limits are 60 requests/minute, 10,000 requests/day, 10,000 imported rows/day, 100 drafts/day and 3 active webhooks. Credentials share account buckets in UTC. Accepted authentication/scope checks consume request quota, including replays and downstream validation failures. Row/draft quota and business changes roll back on failed writes. An additional IP throttle allows at most 120 API requests/minute and may affect clients sharing an IP. JSON bodies are limited to 1 MiB.

## API contract

Base URL: `/api/v1`. Supply `Authorization: Bearer vc_UUID.SECRET` and `Accept: application/json`. API responses passing credential middleware include `X-Request-ID` and private/no-store caching. Logs contain request/credential IDs, route name and status, not bearer values or request bodies. IP-throttle rejection occurs before credential middleware.

| Method and path | Scope | Behavior |
| --- | --- | --- |
| GET `/contacts` | contacts.read | Account contacts, 50/page |
| GET `/groups` | contacts.read | Active account groups, 50/page |
| POST `/contact-imports` | contacts.write | Stage/map/preview up to 1,000 JSON rows; 201 |
| GET `/contact-imports/{id}` | contacts.read | Scoped import status/counts |
| POST `/contact-imports/{id}/confirm` | contacts.write | Queue a previewed import after transaction commit; 202 |
| GET `/campaigns` | campaigns.read | Account campaigns, 50/page |
| GET `/campaigns/{id}` | campaigns.read | Scoped status/schedule |
| POST `/campaigns` | campaigns.write | Create a one-way draft from an active owned group and approved owned audio; 201 |
| GET `/reports` | reports.read | Scoped campaign/attempt/IVR/billing report with summary and 50 rows/page |

Import JSON contains `group_id` and `rows`, each with `phone` and optional `name`/`source`. Existing normalization, duplicate handling, DND and import worker checks remain in use. Confirmation uses the same private import records as the portal.

Campaign JSON requires `name`, `purpose`, `source_group_id`, `audio_id`, `timezone`, `window_start`, `window_end`, `max_attempts` and `retry_delay_seconds`. Optional advanced fields are `retry_outcomes`, `excluded_dates_text` and `expires_on`. The server controls owner/account/type/caller identity/capacity/status. There is no API launch, approval, payment or audio-upload endpoint. Starting a draft through the portal still uses normal approval/media/wallet/capacity/DND/emergency gates.

Report query parameters are `kind` (campaigns/attempts/ivr/billing), `from`, `to`, `timezone`, optional `campaign_id`/`status` and `page`. Client/owner override filters are forbidden. Defaults use the last 30 calendar days in Africa/Accra; maximum range is 366 days. See [report definitions](step-10-notifications-reports.md) for timestamp bases, paired denominators, exact money and currency separation. Standard list responses use Laravel pagination (`data`, page metadata and links). Reports return `filters`, `summary`, `headers`, `data`, `page`, `last_page`; data rows align with headers.

Every POST requires an `Idempotency-Key` of 8–100 ASCII letters, digits, underscores or hyphens. Keys are scoped to the credential and bind the method/path/exact raw JSON bytes. Successful status/body are replayed for 24 hours with `Idempotency-Replayed: true`; changed requests or expired keys return 409. Errors are not cached and do not commit partial business changes. Never reuse a key for a different operation, and do not automatically resubmit uncertain operations with a new key or replacement credential: reconcile their original status first. Expired key/request hashes remain as tombstones; response bodies are cleared 30 days after expiry.

Writes use the existing engine admission mutex before account/credential locks, keeping their lock order compatible with campaign execution. This serializes writes with other admission work; production throughput must be measured before increasing quotas. Per-minute/daily request consumption is atomic, and exceeding either rolls back both increments.

Expected statuses: 401 invalid/expired/revoked credential; 403 scope/account denial; 404 unavailable account record; 409 idempotency conflict/expiry; 413 oversized body; 422 validation/state rejection; 429 quota/IP throttling; 503 disabled API. Server errors return a generic message. Account quota 429 includes `Retry-After: 60`; daily quota may still be exhausted after that wait. Reconcile usage rather than retrying continuously.

Machine-readable contract: [OpenAPI](api/openapi.json). Example requests: [Postman collection](api/voicecast.postman_collection.json). Import the collection locally, set the accepted base URL, token and fixture IDs, and select requests deliberately. The collection includes writes that create drafts and stage/confirm imports; running it is not part of deployment. Its generated idempotency key is new for each execution; use a fixed key for a replay test. No credentials are bundled.

## Webhook events and signatures

Supported events are `campaign.completed`, `campaign.failed`, `campaign.cancelled` and `call.outcome`. They represent execution/evidence state, not proof of audible delivery or a financial settlement. Call evidence corrections can create a new event for the same attempt; reconcile by attempt ID as well as deduplicating delivery IDs.

Campaign/call model saves capture compact events; the engine completion and bulk emergency cancellation paths also capture events. Business transactions and the outbox share the database transaction. No network I/O occurs on the call-processing path. Direct future bulk/SQL updates bypass model events and must explicitly capture the outbox; tests cover current completion/cancellation paths.

The minute `integrations:maintain` task processes at most 200 outbox events per active subscription each pass, with a persistent cursor. Account locking orders event commits and cursor advancement to avoid losing concurrent events. Unique event and subscription/event keys prevent duplicates. New subscriptions start after the current event ID, without historical backfill. Outbox capture continues while delivery is disabled; enabling delivery can send retained events generated after subscription creation. Payloads are retained for 30 days, so older paused backlog is not recoverable from this outbox.

Example body:

```json
{"id":"delivery-uuid","type":"call.outcome","occurred_at":"2026-10-25 10:00:00","data":{"campaign_id":42,"attempt_id":"attempt-uuid","outcome":"playback_failed","answered":true,"playback_completed":false}}
```

Campaign data contains `campaign_id` and `status`. Bodies omit recipient names/numbers, media URLs/tokens, connection credentials, private billing evidence and secrets. `occurred_at` is an event capture time in UTC. Payload bytes and delivery UUID remain unchanged across retries.

Headers:

- `X-VoiceCast-Delivery`: delivery UUID, also present in body `id`.
- `X-VoiceCast-Timestamp`: Unix seconds for this send attempt.
- `X-VoiceCast-Signature`: `v1=` followed by lowercase hex HMAC-SHA256 of `timestamp + "." + delivery_uuid + "." + raw_body`, using the subscription secret.

Receivers must validate the signature with constant-time comparison over the unmodified raw body, check the body/header ID match, reject timestamps outside an agreed window (for example five minutes with synchronized clocks), and persist/deduplicate delivery UUIDs. Verify authentication before any side effects. Return 2xx after durably accepting the event; process business work asynchronously. The stable ID permits a receiver to acknowledge duplicates without repeating side effects.

Only public IPv4 HTTPS endpoints on port 443 are supported. URLs cannot contain credentials, query strings or fragments. All resolved A records must pass global-address checks, including rejection of private, link-local/metadata, shared, benchmark and multicast ranges. Every send re-resolves/revalidates and pins one approved address using cURL `CURLOPT_RESOLVE`; TLS verification stays enabled, proxies are disabled, redirects are not followed and response consumption is capped at 64 KiB. cURL is required. IPv6-only endpoints are not supported. These controls use [cURL address pinning](https://curl.se/libcurl/c/CURLOPT_RESOLVE.html).

2xx marks sent. Non-2xx or transport failure schedules exponential retry after 60, 120, 240 and 480 seconds, up to five total attempts within seven days of delivery creation. Redirects are failures. Duplicate queued jobs cannot claim a completed/in-flight delivery twice. Transport timeouts can be ambiguous and may produce duplicate receiver delivery, so receiver deduplication is mandatory.

A send interrupted for over five minutes becomes `unknown` and is not automatically replayed. The client can request a retry after explicitly confirming receiver reconciliation, within the same five-attempt/seven-day limit. Suspending an account, disabling a subscription or invalidating its owner prevents a pending job from sending when it rechecks eligibility. Disabling switches does not recall already in-flight requests. Pending/failed deliveries expire after seven days even while delivery is disabled.

## Operations retention and acceptance

`php artisan integrations:maintain` performs real enabled delivery scheduling and retention; do not invoke it as a harmless production probe. The scheduler registers it every minute with overlap protection. `php artisan integrations:status` is read-only and reports flags and queue outcome counts. Delivery logs rotate daily in `storage/logs/webhooks-YYYY-MM-DD.log`; API logs use `api-YYYY-MM-DD.log`. Never add raw receiver responses, tokens, signatures or bodies to logs.

Outbox bodies are cleared after 30 days. Non-pending/non-sending delivery bodies are redacted after 30 days; event keys, delivery metadata and cursors remain to preserve replay safety. Daily/minute usage is removed after 30 days. Credential/subscription audit history and replay tombstones are retained; define an account archival policy before bulk deletion. No retention task rewrites the financial ledger.

Before production activation:

1. Run the integration and full regression suites, then verify account/scope/suspension boundaries on the production database engine.
2. Verify one controlled receiver signature, rejection of tampering/stale timestamps, duplicate acceptance, rotation, non-2xx retry, unknown-send reconciliation and private-address/redirect denial.
3. Load-test the approved account count/request/import/export mix with real MariaDB/Redis workers; measure API latency, outbox age, worker throughput, queue growth, engine admission wait and tenant fairness. The local 450-event backlog test demonstrates bounded cursor catch-up, not a production capacity certification.
4. Verify database/APP_KEY restore, interrupted worker recovery, scheduler restart and rollback in a controlled environment. Stop new API traffic/delivery before rolling back; do not drop the event outbox or credential tables while the new code is active.
5. Set supported quotas, release owner, alerting for unknown/exhausted/old pending deliveries and recovery objectives from measured evidence.

Stage 09 payments and production audible PBX acceptance remain separately deferred. API availability does not bypass those release requirements.

## Local verification on 25 October 2026

Both Stage 12 migrations were applied successfully to the existing local MySQL database through `/Applications/ServBay/tmp/mysql-8.4.sock`. The configured TCP connection at 127.0.0.1:3306 refused access; a process-only `DB_SOCKET` override was used, without editing `.env`.

The full regression suite passed with 254 tests and 1,731 assertions. The integration suite includes a 450-event bounded backlog check, transactional rollback, scoped access, quota behavior, signing/retries, credential lifecycle, retention and emergency cancellation. Formatting and whitespace checks passed; local JSON structure checks cover all nine documented endpoints. Postman CLI specification lint could not complete because its macOS Keychain access failed (`SecItemCopyMatching -50`); no live collection was executed. Production receiver, concurrency/load and recovery acceptance remain pending.
