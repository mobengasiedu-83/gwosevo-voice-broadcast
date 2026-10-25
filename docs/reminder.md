# Production Stability and Audio Playback Reminders

For the combined implementation backlog and current stage status, see [consolidated pending implementation](pending-implementation.md). The production and payment deferrals below remain in effect.

Status: Deferred until the move to production. These items are an implementation and acceptance checklist, not confirmation that production is ready.

The URL-mode status exit check and the campaign execution-versus-playback report summary are separate application cleanups. Deploy and validate those changes; the tasks below remain deferred.

| Item | Current status | What to fix or complete in production |
| --- | --- | --- |
| HTTP audio playback | PBX reports playback completion after the cache-permission fix. Actual audible full playback still needs confirmation. | Answer a controlled test call, confirm the full approved recording is audible and compare the result with the application playback-completion event. |
| Test Audio Call | A restricted CLI test exists for extension 208 and audio ID 1. | Add an authorized UI action to test a selected approved recording. Keep destinations controlled and log the actor, recording and test outcome. |
| Media preflight | Approval, local file, checksum and URL configuration are checked. | Verify from the PBX side: HTTP retrieval, correct WAV response, matching checksum, media-cache access and playback/codec support. Application-side URL validation alone is insufficient. |
| Media health indicator | Gateway and infrastructure health exist. | Show audio-delivery health separately with the last check time, result, failure reason and stale/unknown state. |
| Failure reporting | Reports show outcomes such as authorization_failed and playback_failed. | Explain AMI permission rejection, HTTP failures, unwritable PBX cache, missing files and invalid audio without exposing media tokens or credentials. |
| Dispatch protection | Existing checks block several invalid states. | Connect mandatory media-preflight results to admission so failed or stale checks block new calls. Keep existing DND, approval, capacity and emergency-stop checks. |
| Production diagnostics | Calling and Asterisk playback have worked in the supplied traces, but PBX warnings remain. | Resolve the CDR adaptive ODBC insert failure, review redundant AstDB authorization-key cleanup warnings, and verify deployment permissions and AMI capabilities. Preserve evidence that fixes do not interrupt ordinary PBX traffic. |

## Acceptance evidence to retain

- One explicitly selected controlled recipient answers and confirms the full approved recording is audible.
- The application records correlated answer, playback-start and playback-completion events.
- A failed HTTP fetch, changed checksum or unwritable media cache produces an actionable diagnostic.
- Mandatory failed or stale media checks block new calls once the preflight integration is implemented.
- Health screens distinguish gateway connectivity, application media checks and verified PBX playback evidence.
- CDR diagnostics, recovery steps and deployment ownership/permissions are documented.

## Operating notes

- Use approved recordings and the configured connection; do not bypass review or DND for testing.
- Do not interpret Completed as successful delivery. Use unique-recipient playback results and the attempt outcomes.
- Do not interpret a successful URL-mode status exit as a completed PBX media test.
- No automatic production calls or PBX changes are authorized by this reminder.

## Stage 09 — Payment gateway and top-ups deferred

Deferred at the owner's request on 24 October 2026. Do not enable payment collection or claim commercial payment readiness. No payment migration, checkout request or transaction-status call was executed during the initial investigation.

- Confirm the payment provider and merchant-specific API documentation. Xcel Business/PayGate was investigated as the proposed provider; verify authentication, initiation, authoritative status lookup, callback authentication, amounts/currency, fees, expiration, refunds and reversals before activation.
- Implement client top-up requests and hosted checkout, with immutable amount/account/reference binding and explicit pending, failed, expired and uncertain states.
- Credit wallets only after independent provider verification of successful payment, exact amount/currency, merchant and original reference. Browser redirects and callback payloads alone must never grant credit.
- Enforce idempotent ledger funding and callback replay protection; retain reconciliation evidence, account-scoped receipts and audited handling of uncertain payments.
- Add finance reconciliation screens/jobs, provider sandbox tests and controlled acceptance before configuring live credentials.
- Keep existing Stage 08 manual adjustments available to authorized finance staff; they are not verified gateway top-ups.

Resume Stage 09 before an online paid launch. Stage 10 notifications and analytics may proceed against existing campaign and ledger data without payment integration.

## Registration policies before public launch

Replace the dummy Terms and Privacy Policy at `/AcceptTerms&PrivacyPolicy` with approved content, complete the operator/contact/retention details, and set the combined `CLIENT_TERMS_VERSION`. Signup records acceptance of that version. Ghana is the currently supported registration country; additional countries require phone-normalization support. Business verification and staff invitations are now implemented in [Client Management](client-management-review.md); their production permissions and delivery require controlled acceptance. Optional MFA and branches remain later work. Requiring both contacts verified before paid campaigns remains a separate policy decision.
