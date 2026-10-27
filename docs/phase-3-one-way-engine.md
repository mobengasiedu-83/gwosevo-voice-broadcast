# Phase 3 one-way broadcast engine

The application implements one-way campaign configuration, immutable approval snapshots, audio validation/approval/deployment evidence, PJSIP trunk inventory, persistent AMI control, admission limits, durable call events, pause/emergency controls, reconciliation and core reports. Open `/broadcast` after signing in. Live dispatch defaults to disabled.

## Verification record on 22 October 2026

The Phase 3 migration and updated role permissions were applied successfully to the configured local MariaDB database. The existing `.env` AMI account was used for read-only live checks. Login succeeds, but the PBX rejects both `CoreShowChannels` and `Status`; endpoint/version discovery was also unavailable. The gateway correctly refuses to mark the channel inventory healthy and prevents origination. The telephony owner must review supported AMI actions/account privileges and provide the verified trunk/capacity/deployment evidence before live acceptance can finish. `.env` now explicitly keeps dispatch disabled and global channels/CPS at zero.

The automated suite passes 44 tests. An HTTP walkthrough against an isolated temporary SQLite application verified CSRF/session login, populated workspace/operations pages, draft creation, report rendering and disabling a test trunk. Browser visual inspection was unavailable in this session. No calls were placed, and the PBX dialplan/audio were not installed remotely.

## Deployment prerequisites

The existing `.env` AMI credentials are reused through `config/services.php`; credentials never appear in browser pages, event payloads or inventory output. At implementation time `.env` contained AMI host, port, username and secret only. Carrier limits, approved trunk/caller ID, reserved normal capacity and PBX deployment evidence were not configured. Do not infer carrier capacity from endpoint inventory or the 100-channel engineering target.

1. Apply `php artisan migrate` and `php artisan db:seed --class=RolesAndPermissionsSeeder`.
2. Provision distinct users for Campaign Manager, Approver, Telephony Administrator and Operator using `foundation:user`. Super Administrator can use emergency controls but cannot approve their own campaign/audio.
3. Use `php artisan broadcast:inventory` to read Asterisk version and PJSIP endpoint names. Endpoint lists also include extensions; the telephony owner must identify and approve the actual trunk.
4. In `/broadcast`, record a verified trunk: exact endpoint name, approved caller ID, carrier channels/CPS, reserved normal channels, measured safe broadcast capacity, number format and private evidence reference. No arbitrary AMI header/channel strings are accepted.
5. Install the custom dialplan and approved audio on the PBX as described below. Verify the installed Asterisk version supports AMI Originate `ChannelId`, asynchronous originate, CoreShowChannels (or Status), UserEvent, DBPut and DBDel. Verify AMI event permissions include Newchannel, Newstate, OriginateResponse, UserEvent and Hangup.
6. Set `VOICE_BROADCAST_GLOBAL_CHANNELS` and `VOICE_BROADCAST_GLOBAL_CPS` to verified limits. Keep `VOICE_BROADCAST_ENABLED=false` until the controlled acceptance checks pass. Reload cached configuration and restart the gateway after settings change.

## Audio and dialplan installation

Upload WAV, MP3, M4A, AAC, OGG, Opus, FLAC, AIFF, WMA or audio-only WebM, at most 600 seconds / 10 MB. Incompatible formats are converted automatically before review. The PBX artifact is always verified 8 kHz mono 16-bit PCM WAV, with metadata stripped and a SHA-256 checksum. See [automatic audio conversion](automatic-audio-conversion.md) for dependencies and background-worker setup.

A different user with `audio.approve` must review and approve the recording. Download the canonical WAV from the private library, then transfer it securely to the PBX. Its UUID and SHA-256 are displayed in the library.

The telephony owner must install `deploy/asterisk/gwosevo-broadcast.conf` through the **supported VitalPBX custom dialplan mechanism** and reload using the installed version's supported procedure. Do not change generated configuration or include the context in public/inbound dialplan paths. Review `${ASTDATADIR}/sounds` against the installed sounds directory. Only PJSIP outbound calls are supported by this engine; Local channels, forwarding and transfers require separate validation before use.

On the PBX, run the supplied installer after reviewing the approved artifact:

```sh
sh install-approved-audio.sh AUDIO_UUID APPROVED_SHA256 /private/staging/AUDIO_UUID.wav
```

The script verifies SHA-256, installs a controlled filename under `/var/lib/asterisk/sounds/gwosevo`, and records an AstDB `vb-audio` allowlist checksum. If the PBX uses a different sounds root, set `PBX_SOUNDS_DIR` and review the dialplan path before installation. Verify `database show vb-audio`, actual PBX file checksum and dialplan loading. Record the matching PBX checksum and private evidence reference in the library's deployment form. This is a human verification attestation; Laravel does not have SSH access or prove the remote filesystem contents by itself.

The gateway creates a random per-attempt AstDB authorization token before origination. The context validates UUIDs/token/checksum, consumes the authorization token once, checks the audio allowlist and file, and emits playback completion only after `PLAYBACKSTATUS=SUCCESS`. Early hangup skips that completion event. Audio lookup is restricted to `gwosevo/UUID`; user-controlled filenames and application arguments cannot be supplied. Unused terminal tokens are deleted by the gateway. After reconciling all old attempts and confirming there are no active broadcasts, the telephony owner can remove stale `vb-auth` keys during maintenance.

## Campaign approval and operation

1. Record contacts and purpose-specific consent in `/workspace`. Note the contact IDs; suppression takes precedence over consent.
2. Create a one-way draft. In `/broadcast`, configure its approved/deployed audio, verified trunk, contact IDs, concurrency, CPS and daily calling window (Africa/Accra). Windows must start and end within the same day.
3. Submit the campaign in `/workspace`. The separate approver reviews the configuration in `/broadcast` and approves in `/workspace`.
4. Approval captures target phone snapshots, audio UUID/checksum, trunk/caller ID/number format, purpose, timezone, daily window, concurrency/CPS and a single-attempt policy. Old Phase 2 approvals without a broadcast snapshot cannot start.
5. The Operator starts the approved campaign. The gateway selects eligible recipients, checks consent/suppression and the calling window, reserves capacity, and checks eligibility again at the final socket write.
6. Pause stops new origination; existing calls finish. Authorized but unsent requests wait for resume with their reservations retained; calls outside the daily window also wait rather than being dropped. Emergency stop cancels the broadcast campaign and requests hangup only on channels whose observed Uniqueid and exact channel name match the broadcast attempt in a fresh complete PBX snapshot. Global emergency stop is restricted to `broadcast.emergency`; resetting it never restarts cancelled campaigns.

Phase 3 is one-way only, with at most one attempt per campaign/contact. Unknown, excluded, cancelled and completed recipients are not retried automatically. Advanced scheduling, retry policy execution, weighted campaign priorities and interactive IVR belong to Phase 4. Phase 3 cycles least-recently-dispatched campaigns for basic fairness.

## Gateway service and admission

```sh
php artisan broadcast:gateway --observe --seconds=15
php artisan broadcast:gateway
```

Observation mode disables Originate, Hangup and AstDB writes. It still records correlated events and internal gateway health. The command verifies Pong and a complete channel snapshot before reporting a successful bounded observation. Run only one gateway in normal operation. A database lease prevents a second instance from taking over a fresh lease; a dead instance's lease becomes eligible after 60 seconds. Every critical admission/control transaction locks the singleton gateway row in MariaDB before changing ledger state.

Use the provided `deploy/systemd/gwosevo-ami-gateway.service` on Ubuntu. Adapt the dedicated account, PHP executable and application path to the actual server. Protect `.env` with that account's permissions and permit only its private gateway host to reach AMI. Start the service after database/network availability; use journald for sanitized failure diagnostics. The gateway authenticates once per connection, reads framed AMI continuously, serializes writes, sends Ping, collects active-channel snapshots (CoreShowChannels with a documented Status fallback) and reconnects with exponential backoff. SIGTERM/SIGINT closes the connection and makes outstanding sent attempts uncertain.

The admission ledger and internal dispatch mailbox are **database backed**. They deliberately do not use queue worker count as telephony capacity. This avoids losing reservations through Redis eviction or queue redelivery. Other platform queues/Horizon remain separate. A reservation is inserted before AMI authorization; durable send intent is committed before network origination. A partial write or database failure cannot turn a possibly sent call into a retryable recipient. Definitely unsent intents are cancelled on disconnect; potentially sent attempts retain capacity as `unknown`.

Global, trunk and campaign channel/CPS limits are independent. Broadcast channels are bounded by carrier channels minus reserved normal channels and measured safe PBX capacity. All observed non-broadcast PBX channels are conservatively counted against each trunk's capacity, even if some use a different trunk; this may reduce throughput but protects ordinary traffic. Snapshot freshness, gateway health, global stop, consent and suppression all fail closed. Any uncertain attempt blocks further admission until reconciliation. Configuration changes require restarting the gateway to reload environment settings.

CPS timing is persisted with fractional-second precision and charged at the actual serialized originate write. Approved asset identities must still match at send time. A successful AMI Response or OriginateResponse never marks a call answered. Capacity remains reserved until the own call leg hangs up or verified reconciliation proves termination. Reservations do not expire solely because a timer elapsed.

For machine-readable monitoring run `php artisan broadcast:status --json`. It exits nonzero when gateway freshness, enabled limits, verified assets or uncertainty checks do not permit dispatch. A stopped observation process intentionally reports offline after releasing its lease.

## Events and reconciliation

Only correlated broadcast events are stored; unrelated contact-centre events and keypad input are discarded. A bounded field allowlist excludes credentials, tokens and dedicated caller-number fields from raw payloads. Observed channel identifiers can still contain routing information and remain restricted database data. Deterministic event hashes deduplicate events. Event storage commits before processing; unprocessed events replay when the gateway starts/reconnects. Terminal/milestone transitions tolerate duplicate and late events. A late authoritative playback completion can correct a prior early-hangup classification without releasing capacity twice.

After an AMI interruption, run the observing or normal gateway until it has a fresh complete active-channel snapshot. Export verified PBX CDR/CEL evidence with the preassigned Uniqueid/attempt UUID. For each uncertain attempt that actually terminated, prepare a bounded private JSON array:

```json
[
  {
    "attempt_uuid": "11111111-1111-4111-8111-111111111111",
    "uniqueid": "11111111-1111-4111-8111-111111111111",
    "ended_at": "2026-10-22T14:00:00Z",
    "disposition": "ANSWERED",
    "billable_seconds": 12
  }
]
```

While the gateway is running and its snapshot is fresh:

```sh
php artisan broadcast:reconcile /private/cdr-evidence.json --actor=TELEPHONY_USER_ID --evidence=private/cdr/batch-reference
```

Only `ANSWERED`, `BUSY`, `NO ANSWER` and `FAILED` dispositions are accepted. The service rejects active matching/linked legs, stale snapshots, mismatched IDs and inconsistent end times. Reconciliation is audited and idempotent per terminal attempt. Records commit individually; fix a rejected record and rerun the batch. CDR evidence of answer cannot prove playback completion; such calls become `answered_unknown` unless an authoritative completion event exists. If a durable intent has no matching CDR/CEL evidence, retain it as unknown and investigate with the PBX owner; absence from a snapshot alone never clears a reservation.

Processed raw events on terminal attempts are pruned after 30 days by `broadcast:prune-events`, scheduled daily. Unprocessed/uncertain events remain available for recovery; milestone summaries, approvals and audit records remain. Configure Laravel's minute scheduler on the deployment host. Longer-term partitioning, archival and automated CDR adapters belong to hardening.

## Reports and verification

Campaign reports at `/broadcast/campaigns/ID/report` show audience states, attempt outcomes, active/uncertain counts, answer rate and playback completion rate, plus a paginated attempt timeline. Reports distinguish confirmed playback from answer and show uncertain data explicitly. Server-side authorization restricts managers to their own campaign reports; Analysts, Operators, Approvers and Auditors have organization visibility. Telephony users do not receive contact/report permissions by default.

Run `php artisan test`. Automated scenarios cover a complete one-way call; AMI acceptance without answer; duplicate/late events; own-leg correlation; global/trunk/campaign reservations; reserved normal traffic; consent/suppression before reservation and before send; pause; emergency controls; stale health; failed/partial writes; durable send intent; CPS; unknown call retention; CDR reconciliation; WAV checksums/format; legacy approval rejection; permissions and rendered populated views. The framing test uses local socket pairs; it never contacts the PBX.

Live acceptance still requires the installed custom dialplan, deployed approved recording, verified carrier/trunk limits and an authorised test recipient. Verify playback success, missing-audio failure, early hangup, disconnect/recovery, pause/emergency and ordinary contact-centre regression. Start load tests at 5 channels and stage 20/50/100 only within approved limits and recorded abort thresholds. Do not label the 100-call target verified until measured acceptance evidence exists.

## Rollback

Stop the gateway service and keep dispatch disabled. Reconcile or deliberately finish all identified broadcast calls before changing PBX configuration. Restore the prior supported custom context and remove only GWO SEVO audio/allowlist entries after confirming no active use. Retain the database ledger, evidence and audits for investigation. Avoid destructive migration rollback on a database containing campaign history; roll forward with a reviewed corrective migration.

## Protocol references

Implementation follows the official [AMI Originate action](https://docs.asterisk.org/Latest_API/API_Documentation/AMI_Actions/Originate/), [DBPut action](https://docs.asterisk.org/Latest_API/API_Documentation/AMI_Actions/DBPut/), [Playback application](https://docs.asterisk.org/Latest_API/API_Documentation/Dialplan_Applications/Playback/), [DB_DELETE function](https://docs.asterisk.org/Latest_API/API_Documentation/Dialplan_Functions/DB_DELETE/), and [CoreShowChannel event](https://docs.asterisk.org/Latest_API/API_Documentation/AMI_Events/CoreShowChannel/) and [Status action](https://docs.asterisk.org/Latest_API/API_Documentation/AMI_Actions/Status/). Validate these against the actual installed Asterisk release before PBX deployment.

## Confirmed MTN trunk dial settings

The user-confirmed answered test on 22 October 2026 used PJSIP endpoint `196.201.48.139`, national destination `0531005130`, and caller ID `0596921082`. `udp-provider-MTN-SIP` is the VitalPBX display name; using it as the endpoint failed. The international-format request received SIP 404; the national-format call was answered. This confirms the tested combination, not the carrier's channel/CPS capacity or recording playback.

The gateway now supports `national`, `international` and `e164` destination formats per trunk. Contacts and approved target snapshots remain in +233 E.164; only the final dial string is converted. Caller ID is sent exactly as approved. Endpoint validation accepts dot-separated identifiers while still rejecting AMI header separators and dial-string injection characters.

`.env` records `VITALPBX_TRUNK_NAME=196.201.48.139`, `VITALPBX_CALLER_ID=0596921082` and `VITALPBX_NUMBER_FORMAT=national`. The Add verified trunk form uses these as defaults. Save the trunk there with independently verified capacity and evidence before configuring and approving a campaign. These environment defaults do not create an active verified database profile or alter previously approved snapshots. Dispatch remains disabled, with global channel/CPS limits at zero, until deployment and capacity verification are complete.

Phase 4 now extends this engine with configurable caller IDs, IVR, schedules and bounded retries. See [Phase 4](phase-4-interactive.md); existing Phase 3 campaign snapshots retain their original single-attempt policy.
