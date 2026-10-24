# Step 11 — Advanced campaigns and IVR

## Recurring campaigns

Use **Recurring Campaigns** in the internal sidebar (`/campaign-series`) or **Recurring campaigns** in the client sidebar (`/client/campaigns/series`). Select a campaign, daily/weekly/monthly frequency, first/last dates and a local time in the template timezone. An owner may keep at most ten active schedules, bounded to one year. Monthly schedules retain the original day and clamp short months (31 January → 28 February → 31 March). Local wall-clock time follows the chosen timezone, including DST normalization.

The series stores an immutable template copy: name, purpose, type, audience IDs, recording/IVR references and campaign configuration. Editing the source later does not rewrite the series; stop it and create another schedule for changed content. Audience membership is frozen rather than reread from groups. Media, recipient ownership, DND and launch readiness are still checked against current data. Each launched occurrence receives its own campaign/run/immutable launch snapshot and the current assigned billing policy.

By default, an occurrence creates a **ready** campaign for manual review and launch. The optional automatic-start checkbox invokes the normal Engine launch service. It does not bypass client execution flags, account status, permissions, media/IVR review, billing, calling windows, capacity or emergency-stop protections. Scheduler processing creates launch state; actual dialing remains the existing gateway's responsibility. No live schedules are created merely by installing this stage.

Slots are processed once, with a unique series/UTC-slot constraint and database admission locking. A source campaign or earlier occurrence that is running, paused or scheduled blocks overlaps. An earlier draft/ready occurrence also blocks subsequent materialization until reviewed and launched or cancelled. Explicit excluded dates and campaign expiry skip slots. Slots over 15 minutes late are recorded as missed; the next future slot is selected without a catch-up calling burst. A launch/access validation failure records a sanitized blocked reason and stops the series for review. Archived, cancelled or stopped sources stop future work. Stopping a series leaves already-created campaigns under their individual controls. History records ready/started/skipped/blocked outcomes and child campaign links.

Clients manage their own series only, within their active account. Internal authors need campaign-management permission; automatic schedules additionally require broadcast-operation permission at creation. Super Administrators can inspect/stop series. Permissions and access are rechecked when a slot runs.

## Retry reasons, exclusions and expiry

Both campaign editors expose selected retry outcomes: busy, no answer and failed. Select none to disable retries. The existing maximum of five attempts, exponential delay and one-day delay cap remain. Unknown outcomes are never automatically retried; existing opt-out, completed playback/IVR, successful-transfer and DND restrictions remain. Legacy snapshots retain their original combined policy.

Enter up to 100 explicit holiday/excluded dates, separated by commas or whitespace, in the campaign timezone. No external national holiday calendar is assumed. An optional last calling date is inclusive. These settings freeze into launch snapshots and are checked both during admission and immediately before an originate write. Already-active calls are not forcibly interrupted by a date boundary. Minute maintenance excludes pending recipients after expiry; running campaigns complete once no pending/reserved/active/unknown recipients remain. Uncertain calls still require reconciliation.

## Visual IVR editor and simulator

**Voice & IVR** now offers editable node cards, start-node selection, editable destinations and digit branches, node removal, a branching diagram and an optional advanced JSON editor. Existing node limits (30), path-depth limit (20), no loops/unreachable nodes, approved media and allowlisted internal transfer destinations still apply. Saving always creates a new version under the existing approval/deployment workflow. There are no PBX writes from the editor.

The server-validated simulator accepts comma-separated inputs. Keypad digits select branches; `timeout` represents no input; transfer outcomes are `answered`, `no_answer` or `unavailable`. Missing input defaults to timeout/unavailable. The trace identifies each node, decision and next destination. A simulated opt-out creates no suppression, and simulation creates no call attempts, charges or PBX actions. Prompt playback is simulated, not an audible media acceptance test. Saving and deployment remain separate from simulation.

## IVR response analysis

Newly saved flows carry telemetry version 2. Their generated dialplans emit correlated invalid-input and timeout events, alongside existing permitted digits, transfer, completion and opt-out events. The application validates the node and call correlation, deduplicates events and stores `response_type` as digit/invalid/timeout. Invalid raw digits are discarded rather than retained.

The IVR report includes response classification, unique responding attempts, valid digits, invalid inputs and timeouts, plus node/response breakdowns and private CSV/XLSX exports. The Calls and playback dataset retains transfer-answer and opt-out metrics. Report dates continue to use response time for IVR and attempt creation time for attempts; these datasets should not be treated as identical denominators.

Already-approved legacy IVR graphs retain their existing generated dialplan behavior and checksum workflow. Richer telemetry requires saving/approving a new version, installing its generated dialplan through the existing operator procedure and recording its actual installed checksum. No production telemetry is fabricated or retroactively inferred for old flows.

## Deployment and verification

Apply the additive `2026_10_24_080000_add_campaign_series` migration, deploy the IVR JavaScript/CSS and views, refresh caches and restart normal long-running processes through the existing deployment procedure. No new permissions or role grants are required. Keep the minute scheduler running:

```sh
php artisan migrate --force
php artisan queue:restart
```

The scheduled `campaigns:recurring` command processes up to 50 due series per invocation, without overlapping command executions. `broadcast:activate-scheduled` also handles expiry maintenance. Manual invocation processes actual due schedules; use it only when intended. There is no activation of payment integration, global dialing or client execution flags in this deployment.

Automated tests cover recurrence idempotency, frozen configuration, overlap/missed/holiday slots, stopping, account boundaries, blocked and successful launch paths, monthly/DST scheduling, expiry, selected retry reasons, non-persistent simulation and versioned/correlated/deduplicated IVR telemetry. Production still requires controlled audible media and IVR acceptance; the simulator does not establish PBX readiness. Stage 09 payments remain deferred in the reminder list.
