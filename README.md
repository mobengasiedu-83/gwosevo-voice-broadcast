# GWO SEVO Voice Broadcast

GWO SEVO is a web application for managing outbound voice broadcast campaigns through VitalPBX/Asterisk. Teams can organize contacts, import spreadsheets, review recordings, select an outgoing caller ID, schedule campaigns, start or pause calling, and monitor call and playback outcomes from one operations console.

The application manages campaign decisions and call admission. Its persistent AMI gateway instructs VitalPBX to dial through the configured PJSIP connection. VitalPBX handles SIP calls and audio playback. In application-hosted audio mode, recordings remain in GWO SEVO's private storage and VitalPBX retrieves them through protected call-specific URLs.

## Typical workflow

1. Create a contact group, or choose an existing group.
2. Add contacts manually, add existing contacts to the group, or import CSV/Excel files.
3. Upload a recording and listen to its preview.
4. Create a campaign with a group, recording, caller ID choice, calling window and optional schedule.
5. Approve the recording. Linked draft campaigns move to **Ready**.
6. Open the campaign and resolve any launch requirements.
7. Click **Start**. A campaign without a future schedule runs immediately; a future schedule is armed and displays a countdown.
8. Monitor call attempts and playback results. Pause, resume or cancel as needed.

Ready indicates audio approval; it does not guarantee available PBX capacity or that calls have been placed. Running indicates an active execution; the call report supplies evidence of dialing, answering and playback completion.

## Features

### Operations dashboard

- Overview of campaigns, calls, answer rates, hourly volume, upcoming schedules and operational notices.
- Date, timezone and campaign filters for historical metrics.
- Live call list with state/search filters, elapsed time and sanitized event timelines.
- Authorized individual call-stop requests with audit reasons and gateway confirmation.
- Agent and queue wallboard using PBX snapshots and events, including ready, busy, paused and offline memberships.
- System health for database, Redis, storage, background workers, scheduler, gateway and channel-snapshot freshness.
- Periodic refresh, stale-data indicators, pagination and role-scoped visibility.
- Responsive shared navigation and persistent light/dark theme switching.

The wallboard reports PBX queue memberships. It does not provision agents or replace the PBX's queue management.

### Contacts and contact groups

- Contact directory with name/phone search, group filters, suppression filters and consent-record filters.
- Manual contact creation, detail editing, membership display and audit history.
- Ghana phone normalization into E.164 format; phone numbers remain immutable in the contact editor.
- Group creation, descriptions, categories, active/inactive status, search, sorting and member counts.
- Add an existing contact from a group page, or select a group directly on the contact's detail page.
- Remove contacts from a group without deleting the contact.
- Idempotent membership operations: repeated additions do not create duplicate memberships.
- Ownership checks for group management, with Super Administrator access across owners.

Group changes do not rewrite already launched campaign audiences. Edit and save an unlaunched campaign to resolve its selected group membership again.

### CSV and Excel imports

- CSV, XLS and XLSX uploads into an existing group.
- Downloadable contact-import template.
- Column mapping for phone numbers and optional names/sources.
- Validation preview before explicit confirmation.
- Restoration of missing national leading zeros for valid nine-digit Ghana numbers commonly produced by Excel.
- Existing-contact linking, duplicate detection and invalid-row quarantine.
- Private staging, import history, progress counts and downloadable row-error CSVs.
- Queued processing in bounded chunks, with resumable work and duplicate-safe confirmation/job delivery.
- Permission and group-status checks during processing.
- Formula-cell rejection and spreadsheet-safe error exports.

Current import limits are 10 MB, 10,000 contact rows and 50 columns. Excel imports read the first worksheet. Imports require a worker consuming the default queue.

### DND, suppression and optional consent records

- Searchable suppression/DNC records with reasons and effective dates.
- Immediate or future-effective exclusions.
- DND checked when reserving a call and again immediately before sending it.
- IVR opt-out actions that add the recipient to suppression.
- Optional purpose-specific consent records, including source, basis, reference, grant date and withdrawal history.

**Consent records are not required for calling. DND/suppression remains enforced.** Importing contacts does not remove their suppression entries.

### Audio library

- Private WAV upload, validation and canonical PCM storage.
- Browser preview and recording download.
- Pending, Approved and Rejected states with status filters.
- Approval, rejection reasons and resubmission controls.
- Checksum verification to detect missing or changed recordings.
- Linked draft campaigns automatically become Ready when their recording is approved.
- Application-hosted URL delivery or the older local-PBX installation mode.

The library accepts recordings; it is not a built-in general-purpose text-to-speech generator.

### Application-hosted audio

With URL delivery enabled, each call receives access only to the approved recording assets in its frozen run snapshot. Access checks include the attempt token, audio identity, SHA-256, sent/unfinished call state and a bounded access lifetime. Missing, altered, unrelated or unauthorized audio returns an error.

VitalPBX downloads the WAV when needed and may retain temporary media-cache files. Individual recordings do not need manual installation on the PBX. The PBX still needs the one-time URL-playback dialplan, HTTP media support, WAV support, a writable Asterisk cache directory and network access to the application.

Interactive prompts also support URL delivery. Generated IVR dialplans still require installation and deployment verification.

See [application-hosted audio setup](docs/application-hosted-audio.md).

### Campaign creation and lifecycle

- One-way broadcast and interactive IVR campaign types.
- Group-based audiences and advanced explicit contact-ID entry.
- Default caller ID or a campaign-specific outgoing number.
- Optional scheduling with timezone-aware countdowns.
- Daily calling windows, concurrency, calls-per-second limits, priority and retry policy.
- Start, pause, resume and cancel controls according to campaign state and permissions.
- Immutable launch snapshots recording audience, audio checksum, caller ID, connection, schedule, limits and IVR assets.
- Execution history and campaign run views.
- Campaign duplication into a separate execution draft.
- Versioned reusable templates that omit execution state, audience and launch schedule.
- Archive and restore controls, with restrictions on active executions.
- Priority/allocation views and weighted admission across running campaigns.

All campaigns resolve the connection named by **VITALPBX_TRUNK_NAME**. Users do not select a trunk per campaign. Leaving caller ID blank uses **VITALPBX_CALLER_ID**, falling back to the configured profile default if absent. A campaign can specify another syntactically valid outgoing number; acceptance of that identity still depends on the PBX/carrier.

Launched snapshots remain unchanged when settings, group membership or default caller ID later change.

### Voice and IVR

- Versioned flow builder with editable JSON, node creation and visual connection previews.
- Playback, digit input, conditions, confirmation, transfers, opt-out, retry prompts, timeout and end nodes.
- Explicit timeout/invalid-input handling and graph limits of 30 nodes and 20 steps per path.
- Rejection of loops, unreachable nodes, reserved identifiers and arbitrary transfer destinations.
- Flow review, approval, cloning and immutable approved versions.
- Generated Asterisk dialplan download and checksum-based deployment recording.
- Allowlisted internal extension/queue transfers and availability checks.
- Digit, opt-out and transfer outcome reporting.

### Telephony and dispatch controls

- Shared AMI/PJSIP connection configured through environment settings.
- Environment-default import into a matching database connection profile.
- Verified carrier channel limits, reserved ordinary-call capacity, safe broadcast capacity and CPS settings.
- Global, connection and campaign admission limits.
- Fresh gateway heartbeat and complete channel snapshots required for new calls.
- Per-attempt authorization before origination and exact identifiers for call correlation.
- Bounded retry/backoff policy and uncertain-call reconciliation.
- Individual and global emergency controls scoped to identified broadcast calls.
- Global emergency-stop reset without automatically restarting cancelled campaigns.

Importing a connection creates an inactive, unverified profile. Verify its actual capacity in Telephony before calling. Global channel/CPS values of zero block dispatch even if a campaign is Running.

### Reporting and audit

- Per-campaign call reports with outcomes and attempt states.
- Answer and playback-completion metrics.
- Audience progress: pending, excluded and terminal recipients.
- Interactive digit, opt-out and transfer summaries.
- Run identifiers and immutable launch configuration history.
- Audited campaign changes, approvals, controls, group membership, imports and telephony configuration.
- Configurable event retention and pruning.
- Reconciliation tooling for uncertain outcomes using verified external records.

Asterisk playback completion confirms that its playback application completed; it does not independently prove what a recipient heard. A campaign marked Completed can contain failed attempts. The report separately highlights zero, partial or all-recipient playback completion and counts unique recipients rather than retry attempts.

### Accounts and permissions

Authenticated access uses role-based permissions and ownership checks. Seeded roles are:

| Role | Main responsibilities |
|---|---|
| Super Administrator | Access across application permissions and owners, including approval exceptions |
| Telephony Administrator | Connection capacity, caller identity settings and PBX deployment controls |
| Campaign Manager | Contacts, groups, imports, audio uploads and owned campaign management |
| Approver | Audio/campaign/IVR review according to granted permissions |
| Operator | Campaign operations and permitted operational screens |
| Analyst | Campaign/report access |
| Auditor | Audit and permitted read access |

Super Administrator access does not bypass media integrity, verified capacity, DND, immutable run history or exact call-ownership checks. User provisioning is available through an interactive Artisan command; a complete graphical user-administration module is not supplied.

## Application architecture

    Browser
       |
    Apache + PHP-FPM
       |
    Laravel application ---- MariaDB
       |                         |
       +---- Redis / Horizon ----+  Imports and health jobs
       |
       +---- Scheduler              Scheduled activation and maintenance
       |
       +---- Persistent AMI gateway ---- VitalPBX ---- Configured carrier ---- Recipient
                                               |
                                               +---- Protected WAV fetch from application

The Laravel application is not a standalone SIP/RTP media engine. VitalPBX originates the carrier leg and plays the recording.

## Screens

| Address | Function |
|---|---|
| /dashboard | Operations overview |
| /dashboard/live | Live calls |
| /dashboard/wallboard | Agent/queue telemetry |
| /dashboard/health | Infrastructure and gateway health |
| /contacts | Contact directory |
| /contacts/groups | Group management |
| /contacts/imports | Imports and processing history |
| /contacts/suppressions | DND/suppression |
| /contacts/consents | Optional consent evidence |
| /audio | Recording library and review |
| /campaigns | Campaigns, scheduling and lifecycle |
| /campaigns/runs | Campaign executions |
| /broadcast | Broadcast operations and emergency controls |
| /interactive | Voice/IVR design, review and deployment |
| /telephony | Connection capacity, caller identity and transfer destinations |
| /horizon | Authorized queue monitoring |

Scheduling and Reports sidebar entries lead to implemented campaign schedule/run views. Unavailable sidebar modules are not complete standalone products: agent provisioning, external API integrations and graphical system administration are not implied by their labels.

## Requirements and production installation

- PHP 8.3 or a compatible newer version, Composer and required PHP extensions.
- Laravel 12, Livewire 3, Spatie permissions and PhpSpreadsheet dependencies.
- MariaDB/MySQL for production application data.
- Redis and Laravel Horizon for queued jobs.
- Apache with PHP-FPM, exposing only the public directory.
- Ubuntu 24.04 is the documented production platform.
- VitalPBX/Asterisk with suitable AMI permissions, PJSIP routing, verified carrier capacity and custom dialplan support.
- Application storage and Asterisk media cache writable by their respective service accounts.

The current screens use static assets in public/css and public/js; they do not require a Vite development server. Laravel's health endpoint is /up; it confirms application boot, not full PBX/worker readiness.

Follow the [production installation guide](docs/production-deployment.md) for packages, file transfer, database creation/migration, environment settings, permissions, Apache/PHP-FPM, background services, backups and checks.

For a fresh installation, the core application commands are:

    composer install --no-dev --prefer-dist --optimize-autoloader
    composer check-platform-reqs --no-dev
    cp .env.example .env

Configure .env before continuing:

    php artisan key:generate
    php artisan migrate --force
    php artisan db:seed --class=RolesAndPermissionsSeeder --force
    php artisan foundation:user admin@example.com --name="Administrator" --role="Super Administrator"
    php artisan optimize

For migration, preserve the existing APP_KEY, database and private recordings. Do not generate a new key or recreate existing users. Never commit .env, expose private storage, or use database-reset commands as a deployment step.

### Required background services

| Process | Command | Purpose |
|---|---|---|
| AMI gateway | php artisan broadcast:gateway | PBX telemetry, admission, origination and call events |
| Horizon | php artisan horizon | Default import queue and dashboard-health jobs |
| Scheduler | php artisan schedule:run every minute | Due campaigns, health probes and event pruning |

Systemd templates are in [deploy/systemd](deploy/systemd). Use one scheduler mechanism and one gateway for the installation. Restart long-running processes after relevant code/configuration updates.

### Important environment settings

| Setting | Purpose |
|---|---|
| APP_ENV / APP_DEBUG / APP_URL | Production mode, error visibility and browser base URL |
| DB_* / REDIS_* | Database and queue/cache connections |
| QUEUE_CONNECTION=redis | Horizon-backed background processing |
| VITALPBX_AMI_* | AMI host, port and credentials |
| VITALPBX_TRUNK_NAME | Exact shared PJSIP connection name |
| VITALPBX_CALLER_ID | Default outgoing caller ID |
| VITALPBX_NUMBER_FORMAT | National, international or E.164 dialing |
| VOICE_BROADCAST_ENABLED | Explicit live-dispatch switch |
| VOICE_BROADCAST_GLOBAL_CHANNELS | Maximum simultaneous broadcast reservations |
| VOICE_BROADCAST_GLOBAL_CPS | Global call-start rate |
| VOICE_BROADCAST_MEDIA_MODE | url or pbx recording delivery |
| VOICE_BROADCAST_MEDIA_BASE_URL | Application base URL reachable from VitalPBX |

Defaults intentionally do not enable calling. URL mode requires an address reachable from the PBX; localhost on that server points back to the PBX. Rebuild configuration and restart the gateway after changing environment settings.

## Operational commands

| Command | Function |
|---|---|
| php artisan vitalpbx:discover | Offline discovery/configuration checks |
| php artisan vitalpbx:discover --live | Adds AMI login/Ping checks |
| php artisan vitalpbx:test | AMI connection test |
| php artisan broadcast:import-trunk | Import configured connection defaults without inventing capacity |
| php artisan broadcast:inventory | Broadcast inventory |
| php artisan broadcast:status --json | Gateway, dispatch and capacity summary |
| php artisan dashboard:probe | Immediate infrastructure probe |
| php artisan horizon:status | Queue supervisor status |
| php artisan schedule:list | Registered scheduled tasks |
| php artisan broadcast:prune-events | Apply event-retention pruning |
| php artisan broadcast:reconcile --help | Verified external-record reconciliation options |
| php artisan foundation:user --help | Interactive account provisioning |

broadcast:status reports media_mode, media_configuration_valid and ready_audio. Its audio readiness requirement follows the selected delivery mode: URL mode requires approved, present, checksum-valid media and valid base-URL configuration; PBX mode additionally requires a recorded local installation. approved_deployed_audio is retained as an informational local-installation count. Status success does not verify PBX-side HTTP retrieval, cache access or audible playback.

The specialized broadcast:test-internal command places a real call and is currently restricted to extension 208 and audio ID 1. It is a diagnostic utility, not a campaign dispatcher. Discovery, status and health checks are distinct from a real call-and-audio acceptance test.

## Development and verification

Install development dependencies before running automated checks:

    composer install
    php artisan test

Tests cover permissions, ownership, contacts/imports, campaign lifecycle, configured connection resolution, caller identity, scheduling, admission, AMI events, DND, IVR and protected media delivery. Automated tests use their configured test database and fake media storage; they do not establish that a production carrier or RTP path works.

For live acceptance, use an explicitly selected test recipient and verify ringing, audible media and the application's call/playback outcomes. Keep credentials and call-specific media tokens out of shared logs.

## Documentation

- [Current campaign and audio workflow](docs/campaign-audio-workflow.md)
- [Contacts, groups and imports](docs/contacts-and-groups.md)
- [Dashboard and operational health](docs/module-1-dashboard.md)
- [Campaign module details](docs/module-2-campaigns.md)
- [Protected application-hosted audio](docs/application-hosted-audio.md)
- [Ubuntu/Apache production deployment](docs/production-deployment.md)
- [PBX telemetry troubleshooting](docs/pbx-telemetry-troubleshooting.md)
- [Foundation implementation](docs/phase-2-foundation.md)
- [One-way engine implementation](docs/phase-3-one-way-engine.md)
- [Interactive/IVR implementation](docs/phase-4-interactive.md)
- [Initial discovery records](docs/discovery/phase-1.md)

Phase documents retain historical implementation and acceptance notes. For current caller ID, optional-consent and audio-delivery behavior, use this README and the current workflow/audio guides.

Production work deferred until the move: [reminders](docs/reminder.md).
