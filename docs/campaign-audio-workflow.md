# Campaign and audio workflow

This flow supersedes the earlier separate campaign-review step for new campaigns.

1. Create a group in **Contacts & Lists → Contact groups**.
2. Upload contacts, map columns, review and confirm the import.
3. Upload a named recording in **Audio** (`/audio`). It starts as **Pending**. Use the inline player to listen before review.
4. Create a campaign: choose group, recording, caller ID/outgoing number, type and optional schedule. Advanced calling-window, limit and retry settings are collapsed by default.
5. Approve the recording in **Audio → Approve audio**. Matching unarchived draft campaigns become **Ready**. A campaign created with already-approved audio is Ready when saved.
6. Open the campaign and use **Start**, **Pause**, **Resume**, **Cancel**, or **Edit campaign**, according to state. These controls are near the top of the page. Draft and Ready campaigns can be edited; launched executions keep their immutable snapshots. Duplicate an executed campaign to edit a new run.

## Ready and schedules

Ready means the selected recording is approved. It does not claim that the WAV is installed on the PBX, that capacity is available, or that recipients have consent. Launch still validates installed/checksummed media, the verified configured trunk, caller ID, audience and calling policy. Dispatch continues to enforce fresh PBX telemetry, global/trunk limits, windows, consent and suppression.

Start without a future schedule creates a run and sets it Running immediately; actual call admission remains subject to those checks. For a future schedule, **Start schedule** arms the execution and starts its countdown. Merely approving audio or viewing the countdown does not place calls. The countdown distinguishes unarmed and armed schedules; when due, it reports that the execution awaits dispatch. The gateway can activate due schedules during admission, and the existing scheduler command provides a periodic fallback. The page refreshes status automatically without interrupting focused form inputs.

Starting captures the audience/configuration in a new immutable launch snapshot and records the actual launch authorizer. The audio approver is recorded separately in the audit trail; it is not represented as a separate campaign approval.

## Configured trunk and caller ID

New campaigns always resolve the exact `VITALPBX_TRUNK_NAME` profile. A missing, inactive or unverified configured profile never causes fallback to another trunk. The editor displays that configured profile even if verification is incomplete; Start remains subject to technical readiness. A posted alternate trunk ID cannot change the selected trunk. Caller IDs are chosen from, and validated against, the configured profile's default/approved numbers.

## Audio library

`/audio` contains only recordings, status filters, inline players and recording actions, plus the upload form. The mixed broadcast operations screen links here instead of duplicating the library.

- Pending: authorized reviewers may **Approve** or **Reject** with a reason.
- Rejected: uploader or Super Administrator may **Resubmit for review**; the rejection remains in the audit history.
- Approved: audio is available for campaigns. A telephony administrator records actual PBX installation checksum/evidence separately; approval never fabricates deployment.
- All authorized viewers may preview/download WAVs. Preview supports HTTP byte ranges, is authenticated and uses private/no-store responses.

Uploads retain the existing 8 kHz mono, 16-bit PCM WAV validation, canonicalization, size/duration limits and checksum verification.

## Super Administrator

The existing **Super Administrator** role now grants every application permission through the authorization gate, including future permissions and Horizon. Direct permission checks and owner scopes also respect this role. It can see/manage other owners' campaigns, templates, groups and import batches; approve its own audio/campaigns/IVR; and use operational controls. Self audio approval and state changes are audited.

Normal roles retain their permission and ownership limits. Non-super reviewers cannot approve their own audio or IVR. Campaign owners with campaign-management permission may operate/cancel their own campaigns; other users need the operational permissions. Global emergency controls remain separately authorized.

Super Administrator access does not make invalid media valid, alter immutable historical snapshots, invent consent/deployment evidence, activate unverified capacity, or bypass exact channel ownership checks.

## Deployment and verification

Run `php artisan migrate --force` for audio rejection metadata. Restart long-running workers after deployment. This change does not start any campaign or originate calls automatically.

**110 tests passed, 545 assertions.** Blade compilation, JavaScript syntax checks and PHP formatting checks passed.

Tests cover approval-to-Ready transitions, self approval exceptions, cross-owner administration, audio rejection/resubmission, preview range requests, owner controls, scheduled activation/countdowns, exact trunk resolution and media integrity. The audio library and campaign controls are also reviewed using an isolated local preview.
