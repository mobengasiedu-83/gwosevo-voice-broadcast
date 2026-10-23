# Phase 4 interactive broadcasts

Phase 4 adds editable trunk caller IDs, campaign caller-ID selection, versioned IVR flows, permitted single-digit responses, opt-out suppression, agent destinations, scheduling, weighted admission and bounded retries. The implementation does not enable live dispatch. Existing Phase 3 PBX inventory permissions, verified capacity, audio deployment and carrier approval requirements still apply.

## Caller ID settings

Sign in as a Super Administrator or Telephony Administrator and open `/interactive`. Under Outbound caller IDs, set the trunk's default caller ID and any additional carrier-approved numbers. Include a private carrier approval reference. The working environment defaults have been imported as an inactive profile with unknown capacity; you can edit its caller ID immediately. Use the expandable capacity form only after obtaining measured/approved channel and CPS values. `broadcast:import-trunk` can repeat this import without overwriting existing profiles. Campaign Managers can then select one of these numbers in a draft campaign's broadcast configuration at `/broadcast`.

Caller ID is the outbound number displayed to the recipient; the destination remains the contact's number. The approved campaign snapshot stores the selected caller ID exactly, preserving national formats such as `0596921082`. Changing a trunk default never rewrites an approved campaign. Removing a number from both the default and additional allowlist blocks future origination using that number. Reconfigure and obtain a new approval as necessary. Header injection characters and arbitrary caller text are rejected.

## Flow builder and review

In `/interactive`, the flow builder provides a JSON editor with an Add node control and a visual node/edge preview. Select existing approved deployed audio IDs and allowlisted internal route IDs. The preview is advisory; saving validates the complete graph on the server. Cloning creates a new draft version in the same flow family; approved graph content is immutable through the application.

Supported nodes:

| Type | Required configuration | Behaviour |
| --- | --- | --- |
| playback / retry_prompt | audio_id, next | Play an approved prompt and continue |
| input / confirmation | audio_id, branches, invalid, timeout, timeout_seconds | Collect one digit, then follow an explicit branch |
| condition | branches, invalid, timeout | Branch on the prior permitted digit |
| transfer | route_id, next, unavailable | Check internal agent availability; attempt a transfer |
| opt_out | no additional fields | Emit opt-out and hang up |
| timeout | seconds, next | Bounded wait before continuing |
| end | no additional fields | Emit interactive completion and hang up |

Node IDs must start with a lowercase letter and contain only lowercase letters, digits and underscores (maximum 32 characters). Asterisk special extensions `s`, `h`, `i`, `t` and `e` are reserved. Every edge must reference a defined node. Unreachable nodes, cycles, graphs over 30 nodes and paths over 20 nodes are rejected. Timeout values are bounded to 1–30 seconds. Retry prompts are explicit nodes, not unbounded loops. To repeat a question, add a second input node and a final timeout/invalid destination. The generated context retains a 620-second absolute call ceiling, including transfers.

Example, replacing `AUDIO_ID` with a real approved deployed recording ID:

```json
{
  "start": "menu",
  "nodes": [
    {"id":"menu","type":"input","audio_id":1,"timeout_seconds":5,"branches":{"1":"done","9":"optout"},"invalid":"done","timeout":"done"},
    {"id":"optout","type":"opt_out"},
    {"id":"done","type":"end"}
  ]
}
```

A different Approver must review and approve the flow. A Telephony Administrator downloads its generated dialplan, installs it using the supported VitalPBX custom include mechanism, reloads safely and verifies it on the PBX. Compute SHA-256 of the installed generated file and record that checksum plus deployment/test evidence in the IVR deployment form. A checksum mismatch is rejected. Each version gets a separate `gwosevo-ivr-UUID` context. All referenced recordings must already be installed and allowlisted using the Phase 3 process.

The generated dialplan consumes the per-attempt authorization token, verifies each audio allowlist/file, validates playback/input outcomes, and executes only compiled graph destinations. It does not accept arbitrary dialplan code from the editor. The web application cannot remotely install or validate the dialplan by itself without PBX access.

## Interactive campaign setup

Create an interactive draft in `/workspace`. Configure it in `/broadcast` with opening audio, trunk, caller ID, deployed approved flow version, target contacts, calling window, timezone, rate/concurrency and retry policy. Submit for a separate user's approval. Approval freezes the flow graph, audio checksums, internal destinations, caller ID, audience and operational policy. A changed or disabled referenced route/audio/flow blocks new calls until corrected and reapproved.

Only valid branch digits from the approved graph are stored. Raw DTMFEnd events do not save arbitrary keypad content. Responses are deduplicated and associated with attempt/node/time. Opt-out events create global suppression within the same transaction as event processing, before the gateway can admit another call. During an AMI outage, new calls fail closed; do not run another origination path that ignores suppression.

## Agent transfers

Telephony Administrators allowlist existing numeric queue IDs or PJSIP extension IDs, with verification evidence. Public/external numbers, arbitrary contexts and arbitrary dial strings are not supported. Disable a route to block new calls referencing it.

Queue transfers check `QUEUE_MEMBER(queue,ready)` before entering the queue, with a 30-second queue wait. Queue `AgentConnect` confirms answer. Extension transfers check `DEVICE_STATE(PJSIP/extension)` for NOT_INUSE and use an answer subroutine to correlate the answered staff leg with the original broadcast. The main recipient leg retains its capacity reservation until hangup. Fresh PBX snapshots conservatively count additional staff legs against normal capacity. Agent state can change between the availability check and Dial/Queue; the dialplan handles unavailable/failed destinations through configured branches rather than claiming success.

Confirm the actual queue IDs, extension endpoints, device-state functions, queue event permissions and behaviour on your installed VitalPBX/Asterisk release. Existing inbound/contact-centre routing is not edited by the application.

## Scheduling and retries

Choose an IANA timezone and optional local datetime in the campaign configuration. The approved snapshot stores the schedule in UTC. Operator Start arms the approved campaign: a future schedule becomes `scheduled`; an immediate/past schedule becomes `running`. The Laravel minute scheduler activates due armed campaigns; mere campaign approval does not auto-start it. Calling windows are enforced in the approved timezone immediately before each originate. Daily windows must start and end on the same day.

Install the scheduler's systemd service/timer templates under `deploy/systemd`, adapting paths and dedicated user, or configure the usual minute `php artisan schedule:run` cron. Use one scheduling mechanism. For manual checks, `php artisan broadcast:activate-scheduled` activates due armed campaigns. Application downtime does not lose the database schedule; the next run activates due entries. Emergency cancellation prevents later scheduled activation.

Retries are limited to 1–5 total attempts and confirmed busy/no-answer/transient failure. Initial delay is 60–86400 seconds with exponential backoff capped at 86400 seconds. Due retries re-enter ordinary admission and recheck consent, suppression, windows and capacity. Unknown outcomes remain reserved for reconciliation; they are not immediately retried. Invalid numbers, opt-outs, successful playback/IVR completion, confirmed transfer and cancellations do not retry. The default remains one attempt.

Admission uses a persisted weighted fairness score: each reservation charges `1 / priority` for an approved priority weight of 1–10. Global/trunk/campaign caps and CPS remain authoritative. Priority never bypasses safety or capacity. Pausing stops new originates; authorized unsent work waits for resume, retaining its reservation.

## Reporting and acceptance

Campaign reports include node/digit response totals, opt-outs, transfer attempts and confirmed transfer answers alongside the existing call timeline and uncertain outcomes. Recorded playback completion is separate from interactive-flow completion and transfer answer. A CDR answer alone does not establish IVR completion.

Automated tests cover graph loops/reserved IDs, compilation, immutable approval/version cloning, self-approval rejection, caller ID permissions/allowlists, scheduled activation/cancellation, bounded backoff, unknown retention, permitted digit deduplication, opt-out suppression, transfer availability compilation, checksum deployment and populated pages.

Live acceptance remains pending installation and PBX tests of valid/invalid digits, timeout, opt-out, unavailable/answered agents, consent withdrawal before retry, AMI disconnect/recovery and existing contact-centre regression. The prior successful three-second national-format test established only a basic answered call; it did not validate these Phase 4 paths. Dispatch remains disabled until those prerequisites and verified capacity are satisfied.

## Application verification on 23 October 2026

The additive Phase 4 migration has been applied to local MariaDB. It is resumable after partial MariaDB DDL and preserves existing records. All 63 automated tests pass (218 assertions), including resumable migration and final-write window checks; generated Blade templates and builder JavaScript also validate. Live IVR, transfer and scheduler service acceptance still require deployment on the PBX/production host. No calls were placed during this implementation.
