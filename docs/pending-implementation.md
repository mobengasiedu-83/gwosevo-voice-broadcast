# VoiceCast consolidated pending implementation

Reviewed 25 October 2026 against the four supplied Word documents, current source, migrations, tests and implementation guides.

The core client workflow and billing ledger have been implemented. The remaining critical work is production media assurance and acceptance, public-launch preparation, deferred payment integration, and completion of the partial external integration stage. Implementation in this repository does not establish deployment or production acceptance.

## Current stage status

| Original stage | Repository status | Remaining work |
| --- | --- | --- |
| 01 Production stability and audio | Partially implemented; production work explicitly deferred | PBX-side preflight, mandatory freshness gate, media health, internal selected-audio test UI, actionable diagnostics and audible acceptance |
| 02 Client accounts and isolation | Implemented | Deployment, migration/permission verification and cross-account UAT |
| 03 Registration and authentication | Implemented, including dual-contact signup and contact changes | Approved public policies, production delivery acceptance and remaining onboarding policy decisions |
| 04 Client portal | Implemented | Production deployment and responsive/accessibility UAT |
| 05 Client contacts and imports | Implemented | Production import/worker and account-isolation acceptance; optional contact enhancements below |
| 06 Client audio | Implemented | Production private-storage, approval and delivery acceptance; optional diagnostics below |
| 07 Client campaigns | Implemented, including restricted client test runs | Controlled PBX acceptance and explicit execution configuration |
| 08 Pricing wallet and ledger | Implemented, including configurable billing policies | Production database/financial acceptance, policy assignment and reconciliation |
| 09 Payments and top-ups | Deferred at owner's request on 24 October | Provider verification and complete payment workflow before online paid launch |
| 10 Notifications and reports | Implemented | Production scheduler/workers, verified channel acceptance and permission/export UAT |
| 11 Advanced campaigns and IVR | Implemented | Production recurrence acceptance and approval/install/checksum verification for new IVR telemetry |
| 12 APIs webhooks and commercial scale | Partial foundation | Credential lifecycle, webhooks, commercial administration, tests, documentation and load/recovery acceptance |

Evidence: [Steps 02–03](step-02-client-foundation.md), [authentication](step-03-client-authentication.md), [portal](step-04-client-portal.md), [contacts](step-05-client-contacts.md), [audio](step-06-client-audio.md), [campaigns](step-07-client-campaigns.md), [billing](step-08-wallet-billing.md), [reports](step-10-notifications-reports.md), [advanced campaigns](step-11-advanced-campaigns-ivr.md).

## Ordered remaining work

Items below are review recommendations and acceptance criteria. Deferred work stays deferred until separately resumed. This review does not authorize calls, payment collection, deployment or PBX changes.

### 1 Complete production media assurance

Priority P0. Finish the application changes before the controlled production acceptance exercise. Operational work remains deferred to the production move under [reminder.md](reminder.md).

- [ ] **MEDIA-01 PBX-side preflight:** add durable check results for approved assets and the configured PBX path. Verify HTTP retrieval, actual WAV response, checksum, cache writability and playback/codec support. Record check time, sanitized reason and the asset/configuration identity checked. Application URL validation is already present and is insufficient on its own.
- [ ] **MEDIA-02 Admission integration:** require a successful fresh preflight at launch and before new origination. Failed, absent or stale results must block new dialing. Cover interactive prompt assets as well as the main recording. Preserve approval, DND, billing, capacity and emergency-stop controls.
- [ ] **MEDIA-03 Audio delivery health:** show last result/time, failure reason and unknown/stale state separately from AMI connectivity and application-side file validation. Add repeated-media-failure alerts without claiming that an answer proves playback.
- [ ] **MEDIA-04 Internal selected-audio test UI:** allow an authorized operator to select an approved recording and an allowlisted recipient, explicitly confirm and retain actor/asset/attempt/outcome evidence. Reuse normal controls. Restricted client campaign test runs already exist; the legacy internal CLI remains fixed to extension 208/audio 1.
- [ ] **MEDIA-05 Failure diagnostics:** make HTTP failure, AMI rejection, invalid/missing media and unwritable cache actionable in health/report views. Never include credentials or call-specific media tokens.
- [ ] **MEDIA-06 PBX production fixes:** resolve adaptive ODBC CDR insertion failures, review redundant AstDB authorization cleanup warnings and verify deployed permissions/AMI capabilities. Confirm ordinary PBX traffic is unaffected.
- [ ] **MEDIA-07 Audible acceptance:** retain recipient confirmation of the full approved recording plus correlated answer, playback-start and playback-completion events. Demonstrate HTTP/checksum/cache failure behavior and stale-preflight admission rejection.

Source evidence: `app/Services/Broadcast/Readiness.php` checks approval, local existence/checksum and media configuration or recorded PBX installation. It does not establish a fresh PBX retrieval/cache/playback result. The production reminder records the remaining acceptance work.

Completion gate: successful audible controlled playback, useful failure evidence and enforced failed/stale preflight blocking. A URL-mode status success or Completed campaign label does not satisfy this gate.

### 2 Prepare the implemented platform for release

Priority P0 for public-launch policy content and operational release gates; P1 for module UAT. Can proceed alongside application media work without enabling live dispatch.

- [ ] **RELEASE-01 Public policies:** replace dummy Terms/Privacy content at `/AcceptTerms&PrivacyPolicy`; supply operator/contact/retention details and set the accepted `CLIENT_TERMS_VERSION`.
- [ ] **RELEASE-02 Deployment inventory:** compare target migrations and permission grants with the repository, including billing triggers and integration schema. Back up database/private audio/APP_KEY, deploy matching code/assets/schema, refresh caches and restart long-running processes through the deployment procedure. Verify workers, gateway and minute scheduler use the same release. Do not blindly rerun historical commands or assume local migrations reached production.
- [ ] **RELEASE-03 Client security UAT:** test account A/B access across records, media, imports, reports/exports and jobs; suspended accounts; staff-only approval; server-controlled caller identity; verification/recovery; personal notice visibility. Include keyboard/mobile use of the client workflow.
- [ ] **RELEASE-04 Delivery and reporting UAT:** verify real production OTP email/SMS, opt-in alert delivery, bounded retries, private CSV/XLSX downloads, scheduled exports and retention. Keep external alert delivery off until accepted. Development email logs may contain full message contents and need restricted access.
- [ ] **RELEASE-05 Financial acceptance:** verify assigned immutable policies, exact denomination, append-only database guards on the target MariaDB/MySQL deployment, concurrent reservation/settlement, replay safety, insufficient credit, uncertain outcomes, refunds and ledger reconciliation. Document handling of existing unpriced executions before enabling billing admission.
- [ ] **RELEASE-06 Campaign and IVR UAT:** verify recurring manual/automatic paths, missed/overlap/exclusion/expiry behavior and scheduler recovery. Approve/deploy new IVR versions and record actual installed checksums for richer telemetry. Test controlled DTMF, transfers, timeout and opt-out behavior; simulation alone is insufficient.
- [ ] **RELEASE-07 Recovery and operations:** demonstrate database/private-audio restore, worker/gateway restart, rollback, failed-job handling and monitoring. Record operational owner, incident escalation, capacity evidence and agreed recovery objectives.

Completion gate: retained UAT/recovery evidence and deliberate release configuration. Review `VOICE_BROADCAST_ENABLED`, global channel/CPS limits, client execution, billing and alert switches together; installation does not imply activation. See [deployment](production-deployment.md), [update notes](update.md) and each module's deployment section.

### 3 Resume Stage 09 payments when requested

Priority P1; required before online paid launch. Depends on the implemented Stage 08 ledger and verified provider contract. Remains explicitly deferred, rather than the next automatically authorized implementation.

- [ ] **PAY-01 Provider contract:** confirm provider/merchant-specific documentation. Xcel Business/PayGate is a proposal, not a verified active integration. Establish authentication, initiation, authoritative verification, callback authenticity, currency/amount precision, fees, expiry, refunds and reversals.
- [ ] **PAY-02 Payment records and checkout:** implement top-up intents, immutable account/amount/currency/reference binding and pending/success/failed/expired/uncertain states; add client checkout and scoped status screens.
- [ ] **PAY-03 Verified wallet funding:** independently verify payment success and exact merchant/reference/amount/currency before credit. Use transaction-safe idempotent ledger posting and replay protection. Redirects or callback payloads alone must never create spendable credit.
- [ ] **PAY-04 Finance operations:** add reconciliation jobs/screens, uncertain-payment review, scoped receipts/statements and audited refund/reversal handling. Define invoices/taxes if commercially required.
- [ ] **PAY-05 Acceptance:** cover duplicate/out-of-order callbacks, forged notifications, mismatched amounts, expired/uncertain payments and concurrent funding; complete provider sandbox and controlled acceptance before live credentials/payment collection.

Completion gate: only verified successful payments fund once, and provider transactions reconcile with ledger postings. Existing manual finance adjustments remain available to authorized staff and do not count as gateway integration.

### 4 Complete Stage 12 external integration

Priority P2 after core release gates; no need to delay non-API client UAT for this stage.

Existing source includes `/api/v1` contact/group reads, staged contact imports/confirmation, campaign drafts/status and reports; scoped expiring credential issuance service; credential/idempotency middleware; usage limits; and tables for plans and webhooks. API and webhook delivery configuration defaults off.

- [ ] **API-01 Credential lifecycle:** add authorized issuance/list/revocation/rotation management, expiry and audit UX or a documented provisioning interface. The issuance service alone is not a complete client lifecycle.
- [ ] **API-02 API hardening and regression coverage:** verify account/scope/suspension boundaries, revocation, quotas, malformed/oversized requests, concurrent idempotency/replay, imports and draft-only behavior. Review failure/rollback and usage-counter behavior. No dedicated integration test file was found in the reviewed tree.
- [ ] **API-03 Signed webhooks:** implement subscription management, protected secret lifecycle, event production, signed delivery jobs, bounded retries/backoff, replay identifiers, delivery history and reconciliation. Validate outbound destinations against SSRF/private-network access and redirects. The reviewed tree has webhook schema/configuration but no delivery job or scheduled processor.
- [ ] **API-04 Commercial plan administration:** implement validated plan creation/assignment and accountable usage visibility. Separate API quotas from calling capacity and monetary pricing; plan tables/default limits are only a foundation.
- [ ] **API-05 Operations and documentation:** document endpoints, scopes, error/pagination/idempotency contracts, signing/replay rules and examples; add retention for usage/idempotency/delivery records, monitoring and deployment instructions. Postman collections/CLI checks can support sandbox verification.
- [ ] **API-06 Scale acceptance:** load-test expected API/import/export/dial-admission workloads; verify queue backpressure, tenant fairness, database indexes and recovery. Establish supported limits from measurements.

Source evidence: `routes/api.php`, `app/Http/Controllers/Api/ClientApiController.php`, `app/Services/Integrations/Access.php`, API middleware, `config/integrations.php` and `2026_10_24_090000_add_external_integrations.php`. No production API readiness is asserted by their presence.

Completion gate: tested account-scoped APIs, manageable credentials/quotas, signed recoverable webhooks, published contracts and measured scale limits. Approval, wallet, DND and PBX gates must remain authoritative.

### 5 Retain optional product extensions as a separate backlog

Priority P3. These remain proposals from the full roadmap, not prerequisites for every initial release.

- [ ] Contact tags/custom fields, import templates, controlled deduplication/merge preserving suppression/consent, bulk opt-out imports and broader contact retention controls. Existing imports, deduplication, consent records and immediate pre-dial DND checks should be reused.
- [ ] Audio waveform and loudness diagnostics. Existing duration/format validation and canonicalization do not deliver the full proposed diagnostic UI.
- [ ] Execution comparison and richer campaign template experience where existing duplication/templates/report drill-down do not meet the final product requirements. Define the missing behavior before building another subsystem.
- [ ] Decide whether clients need interactive campaign authoring and a client-scoped IVR editor. The current client campaign service remains one-way; the implemented internal visual IVR editor does not establish client IVR authoring. If included, add client-owned flow selection, review/deployment boundaries and isolation tests through the shared IVR services.
- [ ] Client business verification, self-service staff invitations/delegated roles and branches if approved. The account model already supports multiple users; this does not imply invitation UX exists.
- [ ] Additional registration countries after country-specific phone normalization and verification support; Ghana is currently supported.
- [ ] Enterprise integrations and financial documents beyond the initially approved payment/statement scope.

## Decisions already resolved and decisions still required

Do not reopen the original monetary ambiguity as if it were unresolved. [Stage 08](step-08-wallet-billing.md) records owner confirmation on 24 October: GHS 0.003/minute (0.30 pesewas), approved recording duration rounded up to whole minutes, answered calls charged including playback failure, and unanswered calls free. Audited refunds handle disputes. Administrators can create/assign alternative versioned policies; existing runs retain their frozen policy.

Remaining decisions include provider/merchant contract, production-assigned price policies and any taxes/fees/expiry, public policy content and retention, whether both contacts must be verified before paid campaigns, business verification/invitation/branch scope, supported load/capacity, recovery objectives and operational ownership. No delivery dates or estimates are committed by this review.

## Reconciliation of the supplied documents

- The 23 October application overview is an internal-platform baseline. Later source and step guides supersede its gaps where implementation now exists.
- The 12-stage development order remains a useful dependency map, but its “0 of 16” checklist is stale. Do not use it as the current completion count.
- The full roadmap's “planned” client, wallet, notification and advanced-feature labels are historical. Its optional extensions and acceptance requirements still need explicit tracking.
- The exported reminder predates later client campaign tests and payment/policy reminders. The client test workflow does not complete the separate internal selected-recording test UI or prove audible PBX playback.
- Earlier step guides describe their own delivery point, including “future/unimplemented” statements that later stages supersede. Use this tracker with the latest module guide and source rather than concatenating those statements into new work.

## Review boundaries and maintenance

This is a repository and document gap review, not an exhaustive security audit or live environment inspection. Production migration state, PBX health, carrier delivery, merchant capability and actual enabled flags were not verified. No production commands, calls or payment requests were executed.

Review verification: `php artisan test --compact` passed on 25 October 2026 with 235 tests and 1,588 assertions. `git diff --check` also passed. These checks support the repository baseline; they do not close the production or payment acceptance items above.

For each item, record owner, dependencies, implementation PR, automated evidence, target-environment acceptance and deployment notes before closing it. Keep implementation complete, deployed and operationally accepted as separate states. Preserve the four original Word documents as historical references.
