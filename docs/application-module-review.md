# Application module review

Reviewed 26 October 2026 against the supplied installation guide, eighteen-module roadmap, master application specification, AMI/billing specification, client/admin specification, feature/permissions tracker and HTML prototype. Source review covers routes, controllers, services, models, migrations, views, scheduled commands, deployment files and automated tests. This is a repository assessment; production state and external service acceptance were not inspected. The user's report confirms the production migration issue is resolved.

The documents are requirements references. Their embedded instructions and historical Pending labels are not fresh authorization or current implementation evidence. Roadmap module numbers are used below; older repository documents use different numbering (for example, `module-1-dashboard.md` and `module-2-campaigns.md`).

## Eighteen-module status

Implemented means substantive application code exists, not that production acceptance is complete.

| Module | Status | Remaining work or evidence |
| --- | --- | --- |
| 01 Baseline audit, Git and backups | Partial / operational verification | Inventory and deployment documentation exist. Obtain a coordinated DB/private-audio/APP_KEY/PBX-config restore drill, approved recovery objectives and baseline sign-off. |
| 02 Local development environment | Implemented / verification | Local application and isolated tests run. Separately verify Redis/Horizon and scheduler in the intended development topology. |
| 03 AMI connection resilience | Implemented / live acceptance | Gateway, correlation, event processing, capacity controls and reconciliation exist. Demonstrate disconnect/reconnect, uncertain-send handling, replay, emergency stop and load behavior on the deployed PBX. |
| 04 Audio playback and PBX validation | Partial / live acceptance | Private media, checksums, approved-audio gates, playback events and CLI test tooling exist. Need full audible-call evidence, PBX HTTP/cache/codec diagnostics and a consolidated test/preflight UI. |
| 05 Client accounts and isolation | Implemented | Client model, memberships, tenancy services, scoped models and isolation tests exist. Complete production UAT across media, exports and jobs. |
| 06 Registration, OTP and login | Implemented / delivery acceptance | Registration, recovery, verified contacts, session controls, throttling and Turnstile exist. Verify actual email/SMS delivery and production challenge configuration. |
| 07 Admin client management | Implemented | Client directory, profile/status, memberships, activity and finance views exist. Complete operator acceptance and decide delegated client-role policy. |
| 08 Client dashboard and navigation | Implemented | Account metrics, profile/security, notices and wallet links exist. Complete browser/accessibility UAT. |
| 09 Contacts, groups and imports | Implemented core | Scoped groups, CSV/XLS/XLSX mapping, queued imports, DND and consent exist. Broader contact enrichment/merge/retention features remain enhancements. |
| 10 Client audio and internal approval | Implemented core | Private upload/preview/download, rejection and resubmission, internal-only approval exist. Audio waveform/loudness analysis remains an enhancement. |
| 11 Campaigns, schedules and IVR | Implemented core | Frozen audiences, approval/version controls, scheduling, templates, pause/resume/cancel, media/DND/capacity and wallet admission exist. Advanced recurrence, retry controls, cost previews and IVR simulation remain. |
| 12 Call reconciliation and minute rating | Partial commercial integration | Exact duration arithmetic, tariff snapshots, exceptions and trusted-evidence file import exist. No automatic production high-precision CDR/CEL ingestion pipeline was found; validate and integrate a source or formally accept a manual operating workflow. |
| 13 Wallet, reservations and ledger | Implemented / commercial acceptance | Atomic reservations, settlement/release, immutable ledger, adjustments and reconciliation exist. Accounts without wallets are deliberately allowed unpriced operation. Decide whether paid accounts must fail closed without a wallet/tariff. Validate concurrent database behavior and maximum-call exposure. |
| 14 Payment gateway and top-ups | Partial external integration | Initiation, authenticated callbacks, idempotent credit, receipts, refunds and reconciliation exist against a configurable HTTP contract. Xcel-specific API/signature/verification/refund compatibility and settlement evidence remain unverified. |
| 15 Admin finance and reporting | Implemented core | Rate assignments, wallet administration, dispute credits, reconciliation and CSV statements/reports exist. Tax/invoice policy, separate carrier-cost/margin accounting and Finance acceptance remain. |
| 16 Notifications and enhanced reporting | Implemented core / enhancements pending | Personal inboxes, bell, queued email/SMS, delivery records and campaign CSV exist. Per-event/channel user preferences, retention, central delivery-failure monitoring and XLSX report output remain. |
| 17 Security, UAT and resilience | Pending completion | Automated coverage exists, but production security review, load/concurrency tests, actual PBX/provider acceptance, restore drills and release sign-off are not established by this checkout. |
| 18 Production deployment and paid pilot | In progress / pending acceptance | Production installation is in use according to the user; deployment/service templates exist. Verify actual HTTPS, worker/gateway/scheduler supervision, monitoring, rollback and controlled paid-pilot reconciliation before closure. |

## Pending development beyond the core roadmap

These are requirements/extensions in the broader specifications; they are not newly assigned module numbers.

| Area | Evidence and remaining scope |
| --- | --- |
| Client API and outbound event webhooks | No customer API routes, credential lifecycle, subscriptions or outbound webhook delivery worker found. `bootstrap/app.php` loads web/console routes; the payment webhook is an inbound provider callback, not a client integration API. Add scoped credentials, rotation/rate limits, signed deliveries, retries and replay/idempotency controls. |
| Advanced scheduling and campaign estimates | Existing scheduler arms one-off executions. No recurrence/holiday/expiry engine or campaign-wide cost-estimate UI found. Existing per-call hold exposure is not a whole-campaign estimate. |
| Advanced IVR | JSON validation, node insertion and visual cards exist in `public/js/ivr-builder.js`. Drag-and-connect editing, interactive execution simulation and dedicated funnel analysis remain extensions. Preserve existing approval/deployment checks. |
| Agents and queues administration | The staff navigation explicitly has no route for Agents & Queues. Queue/agent telemetry and wallboard exist; a separate administrative workspace, assignments and richer agent workflows were not found. Predictive dialing is outside the present core. |
| Audio diagnostics and operator test console | Controlled CLI test tooling and private media exist. Dedicated browser test-call/preflight controls, actual waveform/loudness analysis and consolidated PBX media diagnostics remain incomplete. |
| Contact enrichment and governance | Imports deduplicate and retain suppression/consent safeguards. Custom tag/field management, consent-preserving manual merge and broader retention/export/erasure workflows were not found as dedicated features. |
| Notification administration | Current templates are code-based; personal delivery state exists. No central template editor/delivery operations console or event/channel preference model found. |
| Enhanced reporting and accounting | CSV exports exist. No XLSX report export, invoice model/workflow, separate telecom-cost ledger or margin reporting found. Implement tax/invoices only after policy is decided. |
| Internal access administration | Roles, permission checks and seeding/CLI access exist. A dedicated browser-based staff Users & Roles management area was not found. |
| Recovery and retention operations | Backup/restore instructions and some pruning jobs exist. A documented successful restore and approved retention coverage for contacts/audio/audit/financial/notification records remain outstanding. |

## Highest priority before a paid release

1. Validate full audible PBX playback and reconnect/emergency-stop behavior with recorded evidence.
2. Validate a millisecond-precision own-leg timing source and establish dependable ingestion into rating/settlement.
3. Verify the real Xcel provider contract end to end, including status verification, callbacks, refunds and settlement reconciliation.
4. Decide paid-account wallet/tariff requirements and enforce onboarding policy. Current `Wallet::admission()` allows accounts without wallets.
5. Complete Module 17 production security, concurrency/load, restore and business UAT; finish Module 18 with a controlled reconciled paid pilot.

Confirmed billing arithmetic is already implemented: unanswered zero, answered minimum one minute, ceiling of actual answered duration divided by 60,000 ms. Older tracker questions about duration basis and minute rounding are superseded by the master/AMI specification. Actual rates, silent-playback treatment, taxes, retention, mandatory KYC and delegated roles still need explicit business decisions.

## Source anchors

- Roadmap: supplied `002_GWO_SEVO_VoiceCast_Module_Development_Flow_and_Roadmap.docx`, sections 2–3 and 7–8.
- Master specification: supplied `GWO_SEVO_VoiceCast_Complete_Master_Application_Specification.docx`, unified roadmap; Part A sections 7–12; Part B timing, wallet and acceptance sections.
- `routes/web.php`, `routes/console.php`, `bootstrap/app.php`: application surfaces and scheduled work.
- `app/Services/Broadcast/`: call engine, AMI gateway, readiness, audio and IVR implementation.
- `app/Services/Billing/Rating.php`, `config/call_rating.php`, `app/Console/Commands/RateCalls.php`: trusted timing import and rating.
- `app/Services/Billing/Wallet.php`: admission, holds, settlement and ledger checks.
- `app/Services/Payments/ConfiguredPaymentProvider.php`: generic configurable provider contract.
- `app/Services/Notifications/`, `app/Jobs/DeliverNotification.php`: inbox and delivery implementation.
- `resources/views/dashboard/layout.blade.php`, `public/js/ivr-builder.js`: unavailable navigation and current IVR editor.
- `docs/module-12-call-rating.md` through `docs/module-16-notifications.md`, `docs/production-deployment.md`: implementation and remaining acceptance notes. Earlier module notes contain historical gaps superseded by later modules; these were checked against code.

## Verification results

`APP_URL=http://localhost php artisan test` passed: **263 tests, 2,037 assertions**, 67.60 seconds. The configured local URL includes `/gwosevo-voice-broadcast/public`; running the suite without overriding it produced 160 failures, including widespread HTTP 404 responses. A focused normalized-URL run passed all eight example/notification tests, followed by the full passing run. No `.env` or application code was changed during this review.

The suite uses isolated SQLite and fake external services. It does not establish MariaDB locking under load, live Redis/process supervision, audible PBX playback, real OTP delivery, provider compatibility or successful production recovery. `git diff --check` reported no whitespace errors. Only this review document was added.
