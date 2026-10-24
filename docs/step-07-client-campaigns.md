# Step 07 — Client campaign creation and scheduling

Clients can create one-way campaigns at `/client/campaigns/create`, select an active account-owned group and approved recording, set a timezone and optional future start, configure a daily calling window and retries, then save to a review screen. Existing Stage 06 audio uploads, private previews and internal-only approval remain in use. Interactive campaign authoring remains Stage 11.

## Audience and ownership

Saving copies the group's current contact IDs into the campaign. Later membership changes require editing and saving the unlaunched campaign to refresh the audience. Empty groups and audiences larger than 10,000 are rejected. Inactive groups and Pending/Rejected audio cannot be selected. Caller ID, trunk, account, owner, campaign type, status, concurrency, CPS and priority are controlled by the server; extra submitted fields do not override them. New client campaigns use the configured default caller ID, one concurrent call, one CPS and priority one.

Account colleagues can view campaign reviews and reports. Only the creator can edit, launch, pause, resume, cancel or request a test. Cross-account identifiers are unavailable. Clients receive no internal campaign, approval or telephony permissions. Suspended accounts cannot use the workflow. Responses use private/no-store caching.

## Launch and scheduling

The review screen includes selected audio and private preview, audience eligibility/DND counts, schedule, calling window, current technical readiness, test runs and call outcomes. Saving does not place a call or arm a schedule. Start freezes the existing engine's immutable execution snapshot and either starts immediately or arms the future schedule. Only Draft/Ready campaigns can be edited. Existing audio approval and integrity, configured connection, verified capacity, DND, emergency stop, calling windows, gateway and dispatch checks remain authoritative. Readiness is not evidence that a recipient heard audio.

Pause stops new dialing. Resume preserves the execution snapshot and schedule. Cancellation uses the existing stop request behavior; termination depends on gateway confirmation. A reason is required and audited for each control. Technical exceptions are summarized on client screens without exposing infrastructure configuration or media tokens; internal staff retain the existing diagnostic tools.

## Controlled test calls and execution switch

Client execution is disabled by default. For an accepted restricted environment, administrators configure:

```dotenv
VOICE_BROADCAST_CLIENT_EXECUTION_ENABLED=true
VOICE_BROADCAST_CLIENT_TEST_NUMBERS=+233241234567
```

Test numbers are administrator-authorized E.164 numbers, and must exist as contacts in the client's account. The client chooses a recipient from that restricted set. The test creates a separate one-recipient campaign with one attempt, no future schedule and the same audio and calling window. It uses the ordinary engine and protected media path, including DND and capacity checks. It does not use the legacy internal-test CLI, place a direct AMI call from a web request, or alter the parent audience. An active test prevents another test for that campaign. Test runs cannot spawn additional test runs.

The execution switch gates Start/Resume, test requests, gateway reservation and final sending. Existing global dispatch enablement and nonzero channel/CPS capacity are also required. Disabling client execution does not hang up calls already sent. Refresh configuration caches and restart long-running gateway processes when changing configuration.

The review page links to each test's report. Clients must confirm audible full playback with the recipient; answered and playback-completed counts are presented separately. Tests in this change use fake storage and never connect to the PBX or place calls. Actual PBX and audible acceptance remain an operator task.

Do not enable a paid service through this stage. Pricing, wallet reservations/charging and verified payment top-ups belong to Stages 08–09. The feature flag does not provide billing or commercial readiness.

## Reports and deployment

Account-scoped, paginated reports show attempt numbers, states, outcomes, answer times and playback completion times. Raw event payloads, channel identifiers, caller ID, connection configuration, checksums and authorization tokens are not rendered. Completed is an execution state and may include failures. Reports can be refreshed to retrieve current outcomes.

No schema migration or role-seeder change is required. Deploy application code, Blade templates, CSS and routes, refresh application caches and restart long-running workers/gateway using the existing deployment procedure. Set test destinations and the execution flag only for the intended controlled environment; `.env.example` documents defaults, while existing `.env` values are left intact.

## Verification

The new feature tests cover server-controlled fields, account boundaries, approved-only audio, inactive/empty groups, future schedule validation, launch gating, immutable snapshots, pause/resume/cancel, DND and checksum failures, restricted test destinations, separate test execution, colleague read-only access and suspension. Existing client-audio and campaign/telephony tests provide approval and engine regression coverage.
