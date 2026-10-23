# Production Stability and Audio Playback Reminders

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
