# Module 1 — Dashboard

Implemented against section 19.1 of the unified outbound dialer v2 specification.
After login, the default destination is `/dashboard`; an existing intended URL is preserved.

## Screens

- **Overview** (`/dashboard`): scoped campaign and call totals, selected-day answer rate, hourly volume, campaign completion, upcoming schedules, recent outcomes and operational attention items. Date, timezone and campaign filters apply to historical metrics. Running campaigns and active calls describe current state. Empty data has no invented percentages.
- **Live Calls** (`/dashboard/live`): paginated attempts, campaign/state/search filters, elapsed time, observed bridge state and a sanitized event timeline. No phone numbers, raw AMI payloads or credentials are exposed. Authorized supervisors can request an individual stop with a required audit reason.
- **Agent & Queue Wallboard** (`/dashboard/wallboard`): PBX queue counters and queue memberships, including ready, busy, paused, reported wrap-up and offline states. Members are paginated. Counts describe queue memberships rather than distinct people. No PBX queue snapshot means unavailable data, not zero ready agents.
- **System Health** (`/dashboard/health`): gateway heartbeat and channel snapshot freshness, Redis/database/storage probes, actual worker execution delay, scheduler heartbeat, failed jobs, unresolved attempts, event backlog and trunk allocation.

The responsive dark console follows the supplied layout. Existing modules link to their working routes; future modules remain visibly unavailable. Panels refresh every ten seconds, pause when the page is hidden or a panel control has focus, and retain the last result with a stale warning if refresh fails. A manual refresh is available.

## Permissions and scope

All existing roles receive `dashboard.view` and `dashboard.live`. Campaign Managers see only their own campaigns and attempts. Other seeded roles receive `dashboard.global`. Super Administrators, Telephony Administrators and Operators also receive `dashboard.wallboard` and `dashboard.health`.

Stopping requires both live-call access and the existing `broadcast.emergency` permission. Requests are audited and idempotent. The gateway uses its existing fresh channel snapshot and exact unique-ID/channel ownership checks before hanging up. The UI reports a stop request, not a successful hangup. Terminal calls cannot be stopped.

## Operating requirements

Apply migrations and seed permissions:

```sh
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
```

Keep the existing `broadcast:gateway` process running for call/channel and queue telemetry. It requests read-only `QueueStatus` snapshots every thirty seconds; queue permissions failures do not interrupt calling. A complete snapshot replaces queue state atomically, with intervening events replayed. Disconnection invalidates live queue availability. Queue snapshots become stale after 45 seconds; gateway/channel health requires evidence within 15 seconds.

Run Laravel's scheduler every minute (`php artisan schedule:run` from cron). The registered `dashboard:probe --scheduled` task records scheduler/database/Redis/storage status and enqueues an actual worker heartbeat. For an immediate infrastructure check use `php artisan dashboard:probe`; this does not claim that the scheduler ran.

Horizon's default supervisor includes `dashboard-health` and `default`; restart deployed workers after changing configuration. Other worker managers must consume both queues, for example:

```sh
php artisan queue:work --queue=dashboard-health,default
```

A synchronous queue is labelled synchronous rather than healthy background processing. Worker/probe readings expire after 180 seconds. Thresholds and polling interval are in `config/dashboard.php`. Storage checks report available disk space and missing approved audio files. Dashboard health never enables dispatch or replaces carrier/PBX acceptance checks.

## Verification

The full automated suite passes: 76 tests, 278 assertions. Coverage includes role boundaries, ownership checks, timezone/day aggregation, empty and stale telemetry, queue-member updates, actual worker delay, sanitized call detail, and audited stop requests. Desktop and mobile layouts were reviewed in a local headless browser using an isolated test database. Live PBX queue availability and worker/scheduler health remain dependent on those running services and their permissions; the UI explicitly reports missing evidence.
