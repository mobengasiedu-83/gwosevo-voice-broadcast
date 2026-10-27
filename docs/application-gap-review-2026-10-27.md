# Application gap review — 27 October 2026

Repository review of routes, controllers, services, models, commands, scheduled jobs, views, frontend assets and module documentation. This updates the 26 October review after the staff/roles, guide, visual IVR and UI work. Module numbering follows that review's eighteen-module roadmap. No production host, live provider account or PBX was inspected, and no calls or payments were initiated. Re-reviewed with a fresh isolated full test run: 274 tests and 2,218 assertions passed in 66.14 seconds using APP_URL=http://localhost. PHP syntax checks passed for 197 files; public JavaScript syntax and the IVR smoke test passed. Production services were not exercised.

## Fresh error and risk findings

| Severity / status | Finding | Evidence and impact |
| --- | --- | --- |
| Medium — reproduced logic defect | IVR keypad field retains a custom validity error after rejecting invalid input | `public/js/ivr-builder.js` calls `setCustomValidity(...)`, restores the previous valid digit, and returns without clearing validity. A Node reproduction with the existing DOM mock confirmed that restored key `1` still carries the error. A real browser can block form submission until the field is corrected successfully or properties are recreated. Existing smoke tests omit this validation state. |
| Build verification blocked — environment | `npm run build` fails: `vite: command not found` | Frontend dependencies are absent from this checkout. This does not establish a deployed-page runtime failure: portal assets are served from public files, but the Vite build cannot currently be verified. Install locked dependencies and rerun the build. |
| High — billing policy gap, intentional current behavior | Wallet-less accounts pass billing admission | `Wallet::admission()` explicitly treats missing wallets as unpriced legacy operation. If all active clients must pay, onboarding and admission need a mandatory-wallet/tariff policy. This is an existing design choice, not a failed test. |
| Medium — concurrency review required | Role deletion/assignment locking is inconsistent | Role deletion locks the role; staff assignment locks users but does not lock/revalidate the selected role in that transaction. Review the assign/delete race on MariaDB. No concurrent production failure was reproduced. |
| Medium — browser/accessibility acceptance outstanding | New designer and portal styles lack real-browser verification in this session | The IVR test uses a lightweight DOM mock. Pointer capture, native constraint validation, touch dragging, overflow and keyboard/focus behavior still require browser tests. |

No application fixes were made during this review. These findings are distinct from unimplemented features below. Passing tests do not establish absence of bugs or external-service readiness.

## Verification performed

- Full isolated PHPUnit suite: **274 tests, 2,218 assertions, all passed**.
- PHP lint: **197 files, no syntax errors** across app/routes/config/database.
- Public JavaScript syntax checks and `tests/js/ivr-builder.test.cjs`: passed.
- Additional in-memory IVR invalid-key reproduction: confirmed persistent custom validity.
- `git diff --check`: passed.
- `npm run build`: blocked by missing Vite executable.
- No live calls, financial transactions, production service checks or provider acceptance tests were run.

## Module status

| Module | Status | Remaining work or evidence |
| --- | --- | --- |
| 01 Baseline audit, Git and backups | Partial / operational verification | Inventory and deployment documentation exist. Obtain a coordinated DB/private-audio/APP_KEY/PBX-config restore drill, approved recovery objectives and baseline sign-off. |
| 02 Local development environment | Implemented / verification | Local application and isolated tests run. Separately verify Redis/Horizon and scheduler in the intended development topology. |
| 03 AMI connection resilience | Implemented / live acceptance | Gateway, correlation, event processing, capacity controls and reconciliation exist. Demonstrate disconnect/reconnect, uncertain-send handling, replay, emergency stop and load behavior on the deployed PBX. |
| 04 Audio playback and PBX validation | Partial / live acceptance | Private media, checksums, approved-audio gates, playback events and CLI test tooling exist. Need full audible-call evidence, PBX HTTP/cache/codec diagnostics and a consolidated test/preflight UI. |
| 05 Client accounts and isolation | Implemented | Client model, memberships, tenancy services, scoped models and isolation tests exist. Complete production UAT across media, exports and jobs. |
| 06 Registration, OTP and login | Implemented / delivery acceptance | Registration, recovery, verified contacts, session controls, throttling and Turnstile exist. Verify actual email/SMS delivery and production challenge configuration. |
| 07 Admin client management | Implemented | Client directory, profile/status, memberships, activity and finance views exist. Complete operator acceptance and decide delegated client-role policy. |
| 08 Client dashboard and navigation | Implemented | Account metrics, profile/security, notices and wallet links exist. Client guide, themed navigation and setup cards now exist. Complete browser/accessibility UAT. |
| 09 Contacts, groups and imports | Implemented core | Scoped groups, CSV/XLS/XLSX mapping, queued imports, DND and consent exist. Broader contact enrichment/merge/retention features remain enhancements. |
| 10 Client audio and internal approval | Implemented core | Private upload/preview/download, rejection and resubmission, internal-only approval exist. Audio waveform/loudness analysis remains an enhancement. |
| 11 Campaigns, schedules and IVR | Implemented core | Frozen audiences, approval/version controls, scheduling, templates, pause/resume/cancel, media/DND/capacity and wallet admission exist. Advanced recurrence, campaign cost previews and IVR simulation remain. Bounded retry/backoff already exists. The visual IVR editor now exists. |
| 12 Call reconciliation and minute rating | Partial commercial integration | Exact duration arithmetic, tariff snapshots, exceptions and trusted-evidence file import exist. No automatic production high-precision CDR/CEL ingestion pipeline was found; validate and integrate a source or formally accept a manual operating workflow. |
| 13 Wallet, reservations and ledger | Implemented / commercial acceptance | Atomic reservations, settlement/release, immutable ledger, adjustments and reconciliation exist. Accounts without wallets are deliberately allowed unpriced operation. Decide whether paid accounts must fail closed without a wallet/tariff. Validate concurrent database behavior and maximum-call exposure. |
| 14 Payment gateway and top-ups | Partial external integration | Initiation, authenticated callbacks, idempotent credit, receipts, refunds and reconciliation exist against a configurable HTTP contract. Xcel-specific API/signature/verification/refund compatibility and settlement evidence remain unverified. |
| 15 Admin finance and reporting | Implemented core | Rate assignments, wallet administration, dispute credits, reconciliation and CSV statements/reports exist. Tax/invoice policy, separate carrier-cost/margin accounting and Finance acceptance remain. |
| 16 Notifications and enhanced reporting | Implemented core / enhancements pending | Personal inboxes, bell, queued email/SMS, delivery records and campaign CSV exist. Per-event/channel user preferences, retention, central delivery-failure monitoring and XLSX report output remain. |
| 17 Security, UAT and resilience | Pending completion | Automated coverage exists, but production security review, load/concurrency tests, actual PBX/provider acceptance, restore drills and release sign-off are not established by this checkout. |
| 18 Production deployment and paid pilot | In progress / pending acceptance | Production installation is in use according to the user; deployment/service templates exist. Verify actual HTTPS, worker/gateway/scheduler supervision, monitoring, rollback and controlled paid-pilot reconciliation before closure. |

## Features not implemented or incomplete

| Area | Remaining feature | Current implementation / distinction |
| --- | --- | --- |
| Customer integrations | Client API, API credentials/rotation, outbound event webhooks, signed delivery/retries | Inbound payment callbacks exist; they are not a customer integration API. |
| Call rating | Automatic trusted high-precision CDR/CEL ingestion | `billing:rate` imports verified JSON files. No continuous production adapter was found. |
| Payments | Verified Xcel-specific production integration | Configurable HTTP initiation and callback handling exist. Real API/signature/settlement compatibility remains unverified. |
| Campaign scheduling | Recurring schedules, holiday exclusions, expiry rules | One-off scheduling, timezone/windows and bounded retries exist. |
| Campaign budgeting | Whole-campaign cost estimate and affordability preview | Per-call holds and remaining wallet minutes exist. |
| IVR | Interactive simulator and browser-controlled test calls | Visual components, draggable cards, destination selectors, validation, duplication and immutable versions exist. Controlled CLI calling tools exist. |
| IVR | Draw connections directly between ports, persistent canvas arrangement, undo/redo, zoom/pan controls | Connections are currently edited with selectors; card positions are transient. |
| IVR | Multi-digit collection, webhook/API nodes, voicemail, bounded menu replay | Current compiler supports existing single-digit components and rejects graph loops. |
| Agent operations | Dedicated agent/queue administration and assignment workspace | Queue/agent telemetry, wallboard and allowlisted transfer routes exist. |
| Audio | Waveforms, loudness/silence analysis and consolidated browser diagnostics | Private uploads, previews, checksums and approval/deployment gates exist. |
| Contacts | Custom-field/tag management, manual merge, full erasure/retention workflows | Groups, deduplicating imports, consent and suppression exist. |
| Notifications | Per-event/channel preferences, template editor and central failed-delivery operations console | Inbox, bell, email/SMS jobs and delivery records exist; badge preference is implemented. |
| Reporting | XLSX report exports and richer IVR funnel analysis | CSV and node/digit/transfer outcomes exist. XLSX contact import is a separate implemented feature. |
| Finance | Invoices/tax workflow, separate carrier-cost and margin accounting | Wallet ledger, receipts, statements, refunds, disputes and finance reports exist. |
| Staff lifecycle | Dedicated disable/reactivate staff controls and onboarding invitation workflow | Staff creation, role assignment and session revocation exist. |
| Account security | MFA/authenticator enrollment and recovery codes | Passwords, contact OTP, Turnstile, throttling and session controls exist. |
| Operations | Comprehensive retention administration and evidence of successful disaster recovery | Event/identity pruning and deployment documentation exist. |

Some items above are enhancements beyond the implemented core; they are not all release blockers or automatically approved requirements.

## Implemented since the earlier review

- Internal staff creation, role assignment, permission-controlled navigation and audit records.
- Role management: custom roles, permission editing, protected system roles, unused custom-role deletion, session revocation and seed persistence.
- Client user guide and grouped admin/client sidebars.
- Wallet-based remaining billed minutes and a GHS 10 minimum online top-up.
- Visual IVR designer, component properties, recording/route selectors, draggable cards, connection display and local validation. JSON remains the saved representation; backend checks still apply.
- Client/admin themes, shared forms and improved workspace shortcuts.

## Configuration, acceptance and policy gaps

These are not proof of missing application code:

1. PBX audible playback, DTMF/timeout/opt-out/transfer acceptance, reconnect/reconciliation and emergency-stop verification on the deployed system.
2. Xcel end-to-end checkout, authenticated callbacks, refunds, fees and reconciliation against provider statements.
3. Actual email/SMS delivery and Turnstile configuration acceptance.
4. MariaDB concurrency/load testing, Redis/Horizon/gateway/scheduler supervision, monitoring and alerts.
5. HTTPS and production security review, backup/restore drill, rollback verification, accessibility/browser UAT and paid-pilot sign-off.
6. Decide whether every paid client must have a wallet and tariff. `Wallet::admission()` intentionally permits accounts without wallets to retain legacy unpriced operation.
7. Decide commercial tax/invoice, retention, consent/KYC and silent-playback policies where not already approved.

## Suggested order

Prioritize dependable timing ingestion and PBX acceptance, provider integration, mandatory paid-account billing policy, production recovery/security and a reconciled pilot. Then add IVR simulation/test-call tooling, campaign estimates, notification operations and reporting. API integrations, advanced scheduling, CRM features and accounting extensions can follow agreed business priorities.

## Evidence anchors

- `routes/web.php`, `routes/console.php`, `bootstrap/app.php`: exposed screens/endpoints and scheduling.
- `app/Services/Broadcast/`, `public/js/ivr-builder.js`, `resources/views/broadcast/visual-builder.blade.php`: execution/compiler and visual editing.
- `app/Console/Commands/RateCalls.php`, `config/call_rating.php`: trusted timing import.
- `app/Services/Billing/Wallet.php`, `app/Services/Payments/ConfiguredPaymentProvider.php`: billing policy and provider contract.
- `app/Http/Controllers/StaffUserController.php`, `RoleManagementController.php`: implemented internal access administration.
- `app/Services/Notifications/`, `app/Jobs/DeliverNotification.php`: delivery versus missing preference/template operations.
- `docs/module-12-call-rating.md` through `docs/module-16-notifications.md`, `docs/production-deployment.md`: acceptance requirements.
