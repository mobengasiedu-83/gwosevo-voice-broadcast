# Deployment runbook — voicecast.gwosevo.com (Plesk)

Operational companion to `docs/plesk-production-deployment.md` (which explains _why_ the safeguards exist). This file is the concrete, copy-pasteable procedure for our host. Verified against this checkout on 27 October 2026: Laravel 12.70.1, PHP 8.3.35, 290 tests / 2,320 assertions passing, and `php artisan optimize` serving `/`, `/login` and `/registration-notice` with HTTP 200 over a real HTTP server.

## Fixed paths and values

| Item                          | Value                                                                             |
| ----------------------------- | --------------------------------------------------------------------------------- |
| Application root              | `/var/www/vhosts/voicecast.gwosevo.com/httpdocs/gwosevo-voice-broadcast`          |
| Plesk document root (must be) | `/var/www/vhosts/voicecast.gwosevo.com/httpdocs/gwosevo-voice-broadcast/public`   |
| Public URL                    | `https://voicecast.gwosevo.com` (no `/public`, no port)                           |
| PHP (FPM + CLI)               | `/opt/plesk/php/8.4/bin/php`                                                      |
| Composer                      | `/usr/lib/plesk-9.0/composer.phar`                                                |
| Repository / branch           | `github.com/mobengasiedu-83/gwosevo-voice-broadcast`, branch `v2`                 |
| Database                      | MySQL or MariaDB, `utf8mb4`, dedicated user from Plesk                            |
| Redis                         | required — `QUEUE_CONNECTION=redis`, Horizon                                      |
| Node / npm                    | **not required on the server** (see §2.3)                                         |
| Subscription user             | owner of the vhost: `stat -c '%U' /var/www/vhosts/voicecast.gwosevo.com/httpdocs` |

## 1. Answer first: what can go live now, and on what conditions

| Surface                                                                         | Deployable now?                           | Condition                                                                                                                             |
| ------------------------------------------------------------------------------- | ----------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| Web portal (login, dashboard, contacts, audio, campaigns, IVR, wallet, reports) | **Yes**, once §2 is done and §3 passes §4 | All §1.1 gates closed                                                                                                                 |
| Live calling / dispatch                                                         | **No**                                    | `VOICE_BROADCAST_ENABLED=false` until PBX dialplan, audio delivery, DTMF/opt-out/transfer and emergency stop pass controlled UAT      |
| Online top-ups                                                                  | **No**                                    | `PAYMENT_TOPUPS_ENABLED=false` until the Xcel contract, signature verification and settlement reconciliation are verified             |
| Automatic paid-volume rating                                                    | **No**                                    | No continuous CDR/CEL ingestion exists; `billing:rate` imports verified JSON files only. Agree the production evidence workflow first |

### 1.1 Release gates — all must be true before `git pull` on the server

1. **The release is on `origin/v2`.** Verify the intended release commit exists on origin/v2 before deployment. Commit and push this document (and anything else still local) before pulling — a server pull only ever sees pushed commits.
2. `php artisan test` is green on the commit being released (currently 290 passed, 1 skipped).
3. The target commit hash is written down in the release log.
4. Plesk Backup Manager snapshot of _files + database_ taken in the last 24 h.
5. `VOICE_BROADCAST_ENABLED=false` and `PAYMENT_TOPUPS_ENABLED=false` for this deployment unless §5 says otherwise.

## 2. One-time server configuration (do this once per host)

Skip to §3 if the site is already installed and serving. Everything below is a Plesk UI action plus one command.

### 2.1 Domain, certificate and document root

1. Plesk → **Websites & Domains → voicecast.gwosevo.com → Hosting Settings**:
    - Document root: `httpdocs/gwosevo-voice-broadcast/public`
    - Preferred domain: the `https://` variant; enable **HTTPS redirection** (301).
2. Install a Let's Encrypt certificate for `voicecast.gwosevo.com`, select it for the domain, confirm auto-renewal.
3. Never leave the app root (`httpdocs/gwosevo-voice-broadcast`) as the document root — `.env`, `storage/` and `database/` would be web-reachable.

If Plesk cannot change the document root for this subscription, the fallback is a rewrite in `httpdocs/.htaccess` targeting `gwosevo-voice-broadcast/public`; `APP_URL` must still be `https://voicecast.gwosevo.com` and `/public` must never appear in generated links. Changing the document root is the supported option.

### 2.2 PHP handler and settings

Plesk → **PHP Settings** for the domain: PHP **8.4** run as **FPM application served by Apache** (simplest `.htaccess`/front-controller behaviour) or **FPM served by nginx** (see §2.6 for the trusted-proxy consequence). FPM and CLI must be the same PHP build.

> **Pick one version and use it everywhere.** This host currently invokes `/opt/plesk/php/8.4/bin/php` and this runbook now uses the same version. `composer.json` allows `^8.2`, so either works — but the FPM handler, `artisan` in the deploy script, the scheduler task, Horizon and the gateway must all resolve to the _same_ binary. Run `/opt/plesk/php/8.4/bin/php -v` (or `8.3`), then use that path consistently; a mixed-version host produces extension differences (`redis`, `pcntl`) that only surface in the background processes.

```
memory_limit = 256M          # 512M recommended for XLSX contact imports
upload_max_filesize = 12M    # config/voice_broadcast.php caps audio at 10 MB
post_max_size = 16M
max_execution_time = 120
```

### 2.3 Extensions and assets

Required, verified with `composer check-platform-reqs --no-dev`: `ctype`, `dom`, `fileinfo`, `filter`, `gd`, `hash`, `iconv`, `json`, `libxml`, `mbstring`, `openssl`, `pcre`, `session`, `simplexml`, `tokenizer`, `xml`, `xmlreader`, `xmlwriter`, `zip`, `zlib`, `pdo_mysql`. Horizon additionally needs **`pcntl`**, **`posix`** and **`redis`** in the CLI build; FPM needs `redis` too (cache/queue).

```bash
/opt/plesk/php/8.4/bin/php -m | tr '\n' ' ' | grep -oE 'pcntl|posix|redis|pdo_mysql|gd|zip' | sort -u
```

**No frontend build step is needed on the server.** This checkout has zero `@vite` usages; every stylesheet and script is a static file committed under `public/css`, `public/js` and `public/images`. So `npm ci && npm run build` is not part of deployment (`public/build` is gitignored and unused). Do not install Node on production for this app.

### 2.4 Database

1. Plesk → **Databases → Add Database**: MySQL, e.g. `voicecast`, dedicated user with a long password, charset `utf8mb4`, collation `utf8mb4_unicode_ci`.
2. `pdo_mysql` must target that instance; do not set `DB_SOCKET` in production.
3. Grant only on that schema. Keep MySQL and PHP timezones consistent (`Africa/Accra`) — holds and campaign activation are time-sensitive.

### 2.5 Redis

Redis is mandatory for the queue and Horizon, and is the recommended cache store.

```bash
sudo apt-get install -y redis-server plesk-php84-redis
sudo sed -i 's/^# *requirepass .*/requirepass CHANGE_ME/; s/^bind .*/bind 127.0.0.1 ::1/' /etc/redis/redis.conf
sudo systemctl enable --now redis-server
redis-cli -a CHANGE_ME ping     # -> PONG
```

Then restart the PHP handler (`plesk bin php_handler --restart` or the Plesk UI) so `redis` loads, and confirm with `/opt/plesk/php/8.4/bin/php -m | grep redis`.

### 2.6 Production `.env`

Create it on the server — never copy the development `.env` (it carries ServBay paths such as `DB_SOCKET`, a local `APP_URL` and debug settings).

```bash
cd /var/www/vhosts/voicecast.gwosevo.com/httpdocs/gwosevo-voice-broadcast
cp .env.example .env
```

Set at minimum:

```
APP_NAME="GWO SEVO"
APP_ENV=production
APP_KEY=                                   # generate once, then back it up
APP_DEBUG=false
APP_URL=https://voicecast.gwosevo.com
APP_TIMEZONE=Africa/Accra

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=voicecast
DB_USERNAME=voicecast
DB_PASSWORD=...
# DB_SOCKET must stay unset

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true
SESSION_DOMAIN=voicecast.gwosevo.com
SESSION_SAME_SITE=lax

QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=CHANGE_ME

MAIL_MAILER=smtp
MAIL_HOST=...            # real SMTP; "log" silently discards account mail
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS="no-reply@gwosevo.com"
MAIL_FROM_NAME="${APP_NAME}"

CLIENT_SMS_DRIVER=txtconnect
TXTCONNECT_API_TOKEN=...
TXTCONNECT_SENDER_ID=...

TURNSTILE_ENABLED=false  # only true together with keys + hostnames below
TURNSTILE_SITE_KEY=
TURNSTILE_SECRET_KEY=
TURNSTILE_HOSTNAMES=voicecast.gwosevo.com

CLIENT_REGISTRATION_ENABLED=true
CLIENT_TERMS_VERSION=2026-10-25

VOICE_BROADCAST_ENABLED=false
VOICE_BROADCAST_GLOBAL_CHANNELS=0
VOICE_BROADCAST_GLOBAL_CPS=0
VOICE_BROADCAST_MEDIA_MODE=url
VOICE_BROADCAST_MEDIA_BASE_URL=https://voicecast.gwosevo.com

PAYMENT_TOPUPS_ENABLED=false
NOTIFICATIONS_ENABLED=true
BILLING_HOLD_CLEANUP_ENABLED=false
BILLING_MAX_CALL_SECONDS=900
```

`TURNSTILE_ENABLED=true` without valid keys blocks login _and_ registration — enable it only when the production keys are installed.

**Trusted proxy check (nginx-terminated TLS only).** `bootstrap/app.php` does not call `trustProxies`. With _FPM served by Apache_, PHP sees `HTTPS=on` directly and everything works. If the domain runs _FPM served by nginx_, add

```php
$middleware->trustProxies(at: ['127.0.0.1', '::1']);
```

inside `->withMiddleware(...)` and verify §4.3. Otherwise Laravel can emit `http://` asset URLs and `SESSION_SECURE_COOKIE=true` cookies get dropped (the login form appears to reset forever).

### 2.7 Ownership and permissions

Run as the subscription user, never as root for app files:

```bash
cd /var/www/vhosts/voicecast.gwosevo.com/httpdocs/gwosevo-voice-broadcast
chmod 600 .env
chmod -R ug+rwX storage bootstrap/cache
chmod -R u+rwX,go-w storage
```

`storage/app/private` holds client audio and the media the PBX fetches; back it up with the database and never `chown` it to another user.

### 2.8 First install: key, schema, roles, administrator

Only on a brand-new installation (never on an existing one — regenerating `APP_KEY` breaks encrypted columns and all sessions):

```bash
set -e
APP=/var/www/vhosts/voicecast.gwosevo.com/httpdocs/gwosevo-voice-broadcast
PHP=/opt/plesk/php/8.4/bin/php
COMPOSER=/usr/lib/plesk-9.0/composer.phar
cd "$APP"

"$PHP" artisan key:generate --force
"$PHP" "$COMPOSER" install --no-dev --prefer-dist --no-interaction --optimize-autoloader
"$PHP" artisan migrate --force
"$PHP" artisan db:seed --class=RolesAndPermissionsSeeder --force
"$PHP" artisan foundation:user techmgmt@gwosevo.com --name="Administrator" --role="Super Administrator"
"$PHP" artisan optimize
```

`foundation:user` prompts for a password (minimum 12 characters) and seeds the role matrix itself. It is the only supported way to create the first production administrator.

**Never run `DevAccountsSeeder` on production.** It restores known development credentials and refuses to run outside `local`/`development`/`testing` — that guard is intentional.

### 2.9 Background processes — what must run, and under what

| #   | Process                                   | Supervisor                                             | Command                     | Needed for                                                                                                                                      |
| --- | ----------------------------------------- | ------------------------------------------------------ | --------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | Web / PHP-FPM 8.4                         | Plesk                                                  | — (handler)                 | all HTTP traffic                                                                                                                                |
| 2   | **Scheduler** (every minute)              | Plesk Scheduled Task _or_ systemd timer — pick **one** | `artisan schedule:run`      | campaign activation, dashboard probes, event/identity pruning, hourly hold expiry                                                               |
| 3   | **Horizon** (persistent)                  | systemd unit                                           | `artisan horizon`           | queued contact imports, notifications (email/SMS), dashboard probes — queues `default` + `dashboard-health`, up to 10 processes in `production` |
| 4   | **AMI gateway** (persistent, exactly one) | systemd unit                                           | `artisan broadcast:gateway` | the AMI event socket and call dispatch. Leave it stopped while `VOICE_BROADCAST_ENABLED=false`; never run two against one database              |
| 5   | MySQL / MariaDB                           | Plesk service                                          | —                           | data                                                                                                                                            |
| 6   | Redis                                     | systemd `redis-server`                                 | —                           | queue, Horizon, cache                                                                                                                           |

There is no `queue:work` cron: Horizon owns the queue. Do not add a second worker or a `schedule:work` loop alongside these.

### 2.10 Scheduler (every minute)

Plesk → **Scheduled Tasks → Add Task**, run as the subscription user, _not_ chrooted:

```
cd /var/www/vhosts/voicecast.gwosevo.com/httpdocs/gwosevo-voice-broadcast && /opt/plesk/php/8.4/bin/php artisan schedule:run
```

Schedule: `* * * * *`. Enable failure e-mail notifications for the task. Register the tasks once and verify with `artisan schedule:list` (§4.2) — currently six entries including `broadcast:activate-scheduled` and `dashboard:probe --scheduled`.

Alternatively use the repository timer (`deploy/systemd/gwosevo-scheduler.service` + `.timer`, paths edited per §8.1). Using both Plesk cron _and_ the timer double-runs every task; `withoutOverlapping()` limits damage but not duplicate work — choose one.

### 2.11 Horizon and gateway services (root / server administrator)

Copy, edit, enable. The repository templates target a bare-metal layout (`/var/www/...`, user `gwosevo`, `/usr/bin/php`), so every path must be corrected for Plesk.

```bash
sudo cp deploy/systemd/gwosevo-horizon.service /etc/systemd/system/voicecast-horizon.service
sudo cp deploy/systemd/gwosevo-ami-gateway.service /etc/systemd/system/voicecast-ami-gateway.service
sudoedit /etc/systemd/system/voicecast-horizon.service
sudoedit /etc/systemd/system/voicecast-ami-gateway.service
sudo systemctl daemon-reload
sudo systemctl enable --now voicecast-horizon.service
# gateway only when dispatching is approved:
sudo systemctl enable --now voicecast-ami-gateway.service
sudo systemctl status voicecast-horizon.service --no-pager
```

In both units set `User=`/`Group=` to the Plesk subscription user and group, `WorkingDirectory=/var/www/vhosts/voicecast.gwosevo.com/httpdocs/gwosevo-voice-broadcast`, `ExecStart=/opt/plesk/php/8.4/bin/php artisan horizon` (or `... artisan broadcast:gateway`), and `ReadWritePaths=` to `<APP>/storage <APP>/bootstrap/cache`. Keep `ProtectSystem=strict`; if `ProtectHome=true` blocks anything, remove that line rather than loosening the rest. Full unit text is in §8.1.

### 2.12 Horizon dashboard access

`app/Providers/HorizonServiceProvider.php` defines `viewHorizon` with an **empty allowlist**, so `/horizon` returns 403 for everyone in production. That is safe by default. If operators need the dashboard, add the staff addresses:

```php
Gate::define('viewHorizon', fn ($user = null) => in_array(optional($user)->email, [
    'techmgmt@gwosevo.com',
]));
```

Then `artisan optimize` and restart Horizon. Do not expose `/horizon` publicly without this gate.

### 2.13 Backups, logs and monitoring

1. **Plesk Backup Manager**: daily, files + database, retained off-host. Explicitly include `storage/app/private` (client recordings) and `.env`.
2. `LOG_STACK=daily` keeps 14 rotated files in `storage/logs`; ship `laravel.log` and the systemd journal to monitoring.
3. Alert on: `/up` non-200, `horizon:status` not `running`, failed jobs, `billing.hold_cleanup_failed` log entries, disk usage of `storage/app/private`.
4. Restore drill before go-live: restore DB + storage into a scratch database and boot the app against it.

## 3. Deploying a release (every pull)

Prerequisites: §1.1 gates closed, the commit is on `origin/v2`, and you have a fresh backup. Run as the subscription user from the app root; the service restarts need root.

Confirm the Composer path once per host — `ls -l /usr/lib/plesk-9.0/composer.phar` should show a phar; otherwise use `which composer` and pass that file (never a shell wrapper) to PHP.

```bash
set -euo pipefail

APP=/var/www/vhosts/voicecast.gwosevo.com/httpdocs/gwosevo-voice-broadcast
PHP=/opt/plesk/php/8.4/bin/php
COMPOSER=/usr/lib/plesk-9.0/composer.phar
cd "$APP"

# 1. Confirm the server has no local edits (only .env and storage/ are ignored)
git status --short          # must be empty — stop and investigate otherwise
git rev-parse HEAD          # the release you are replacing

# 2. Put the site into maintenance mode (web only; it does not stop the gateway)
"$PHP" artisan down --retry=60

# 3. Fetch the reviewed commit
git fetch origin --prune
git switch v2 || git switch -c v2 --track origin/v2     # first deploy on this host only
git merge --ff-only origin/v2
git rev-parse HEAD          # record this SHA in the release log

# 4. Dependencies
"$PHP" "$COMPOSER" install --no-dev --prefer-dist --no-interaction --optimize-autoloader
"$PHP" "$COMPOSER" check-platform-reqs --no-dev

# 5. Schema + role/permission matrix
"$PHP" artisan migrate --force
"$PHP" artisan db:seed --class=RolesAndPermissionsSeeder --force

# 6. Rebuild caches (config, events, routes, views)
"$PHP" artisan optimize:clear
"$PHP" artisan optimize

# 7. Permissions and secrets
chmod 600 .env
chmod -R ug+rwX storage bootstrap/cache

# 8. Reload workers (root; ask the server admin if you deploy unprivileged)
sudo systemctl restart voicecast-horizon.service
sudo systemctl status  voicecast-horizon.service --no-pager

# 9. Verify, then reopen
"$PHP" artisan migrate:status
"$PHP" artisan up
curl -fsS https://voicecast.gwosevo.com/up
```

Notes that matter:

- **`artisan optimize` is safe here** — verified on Laravel 12.70.1: config, events, routes and views all cache successfully and `/`, `/login`, `/registration-notice` still return 200 with caches built. The two closure routes (`Route::get('/', fn () => view('welcome'))` and `/registration-notice`) are serialised correctly. If you ever see a 404 on `/` while other pages work, a stale route cache is the cause: `artisan optimize:clear && artisan optimize`.
- **No `npm` step.** Assets are static files in `public/`; nothing references `@vite`.
- **`migrate --force`** is required because `APP_ENV=production` disables interactive confirmation. Never run `migrate:fresh`, `db:wipe`, `composer update` or `key:generate` on production.
- **`RolesAndPermissionsSeeder`** is the only seeder to run; it is additive (it creates missing roles/permissions and their matrix) and does not touch client data.
- **No `storage:link`** — broadcast audio is served privately through the application, not from `public/storage`.
- If this release contains only configuration changes, run steps 6, 8 and 9 (skip 3–5).
- **If the release changes calling behaviour**, stop the gateway first, let active calls finish, deploy, then start the gateway. Never let two gateways touch one database. Uncertain call outcomes are closed with `artisan broadcast:reconcile <cdr.json> --actor=<id> --evidence=<ref>` once verified CDR evidence exists; there is no automatic drain command.
- **Deploying unprivileged?** Create a narrow sudoers rule for exactly `systemctl restart voicecast-horizon.service voicecast-ami-gateway.service`, or keep service restarts with the server administrator. Do not give the web/FPM user sudo.

### 3.1 Ordering when the scheduler matters

The scheduler fires every minute. Deploying between `artisan down` and `artisan up` can let `broadcast:activate-scheduled` start a campaign against half-updated code. If the release touches campaign activation, dispatch, billing or the AMI gateway, disable the Plesk scheduled task for the duration of the deploy and re-enable it after step 9.

## 4. Verification after every deploy

### 4.1 Blocking checks

```bash
curl -fsS -o /dev/null -w '%{http_code}\n' https://voicecast.gwosevo.com/up          # 200
curl -fsS -o /dev/null -w '%{http_code}\n' https://voicecast.gwosevo.com/login       # 200
curl -sI https://voicecast.gwosevo.com/ | grep -iE '^(HTTP/|location)'                     # 200 / https redirect
curl -sI https://voicecast.gwosevo.com/login | grep -i 'strict-transport-security'         # only if HSTS is enabled in Plesk
/opt/plesk/php/8.4/bin/php artisan horizon:status                                   # running
/opt/plesk/php/8.4/bin/php artisan queue:failed                                     # empty
/opt/plesk/php/8.4/bin/php artisan schedule:list                                    # five entries
/opt/plesk/php/8.4/bin/php artisan migrate:status | grep -c Pending                 # 0
```

### 4.2 Manual smoke test (5 minutes)

1. Log in as the production administrator; confirm the dashboard renders with CSS (`GET /css/voicecast.css` → 200, correct MIME).
2. Confirm `/horizon` returns 403 for a non-allowlisted user (§2.12 behaviour).
3. Upload a small audio file → appears in the private library and previews.
4. Import a 3-row contact CSV → job appears in Horizon and completes; contacts visible.
5. Create a draft campaign → estimate/validation works; do **not** launch if dispatch is disabled.
6. Add a wallet credit as an administrator → ledger entry appears and the client balance changes.
7. Send a test notification → real SMTP/SMS delivery confirmed in `notifications` records.
8. Check `storage/logs/laravel.log` for new errors and `journalctl -u voicecast-horizon -n 50`.

### 4.3 HTTPS and session check (only if nginx terminates TLS)

```bash
/opt/plesk/php/8.4/bin/php artisan tinker --execute="echo url('/');"   # must print https://voicecast.gwosevo.com
```

If it prints `http://`, apply the `trustProxies` change in §2.6, `artisan optimize`, and reload FPM. Symptom if skipped: login appears to succeed then bounces back to `/login` because the secure session cookie is discarded.

## 5. Feature flags and the go-live sequence

Everything below is `.env` + `artisan optimize` + restarting the workers. Never flip a flag and a code release in the same step.

| Stage                                | Flags                                                                                                                                                                                                 | Exit criteria                                                                                                        |
| ------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------- |
| **A. Portal only** (recommended now) | `VOICE_BROADCAST_ENABLED=false`, `PAYMENT_TOPUPS_ENABLED=false`, `BILLING_HOLD_CLEANUP_ENABLED=false`                                                                                                 | §4 passes; staff and one pilot client can log in, upload audio, build a campaign and see the wallet                  |
| **B. Internal dispatch rehearsal**   | `VOICE_BROADCAST_ENABLED=true`, `VOICE_BROADCAST_GLOBAL_CHANNELS`/`_CPS` at carrier-approved values, gateway unit started (begin with `broadcast:gateway --observe` to watch events without dialling) | audible playback, DTMF, timeout, opt-out, transfer, disconnect/reconnect and emergency stop verified on test numbers |
| **C. Live campaign dispatch**        | stage B plus complete `VITALPBX_*` capacity values, and `BILLING_MAX_CALL_SECONDS` aligned with the installed dialplan timeout (currently `TIMEOUT(absolute)=620` vs 900 s holds)                     | wallet hold/settle/release reconciled against real calls; rated evidence ingested                                    |
| **D. Online top-ups**                | `PAYMENT_TOPUPS_ENABLED=true`, `PAYMENT_XCEL_DRIVER=http`, `PAYMENT_XCEL_*` credentials                                                                                                               | Xcel signature/timestamp verified, callback replay tested, refund path tested                                        |
| **E. Automatic hold cleanup**        | `BILLING_HOLD_CLEANUP_ENABLED=true`, `BILLING_HOLD_CLEANUP_ACTOR_ID=<internal billing.manage user>`                                                                                                   | age/batch policy approved and the scheduler confirmed running                                                        |

Enabling dispatch without a supervised gateway means campaigns activate on schedule and never dial. `broadcast:status --json` is the fastest proof of gateway liveness.

## 6. Rollback

```bash
set -euo pipefail
APP=/var/www/vhosts/voicecast.gwosevo.com/httpdocs/gwosevo-voice-broadcast
PHP=/opt/plesk/php/8.4/bin/php
COMPOSER=/usr/lib/plesk-9.0/composer.phar
cd "$APP"

sudo systemctl stop voicecast-ami-gateway.service   # if it was running
"$PHP" artisan down
git rev-parse HEAD | tee ~/release-rollback-from.txt
PREVIOUS=$(git rev-parse HEAD~1)                    # or the SHA from the release log
git switch --detach "$PREVIOUS"
"$PHP" "$COMPOSER" install --no-dev --prefer-dist --no-interaction --optimize-autoloader
"$PHP" artisan optimize:clear
"$PHP" artisan optimize
chmod -R ug+rwX storage bootstrap/cache
sudo systemctl restart voicecast-horizon.service
curl -fsS https://voicecast.gwosevo.com/up && echo
"$PHP" artisan up
```

Downgrades are only safe when the release added no destructive migration. If it dropped or renamed columns, restore the database (and `storage/app/private`) from the pre-deploy backup rather than reverting code alone. Do not "fix forward" a broken financial ledger without a reconciliation plan.

## 7. Troubleshooting

| Symptom                                                          | Cause                                                                                                                  | Fix                                                                                         |
| ---------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------- |
| 500 on every page right after deploy                             | stale caches from the previous release, or `vendor/` not installed                                                     | `artisan optimize:clear`, re-run steps 4 and 6                                              |
| `/` is 404 but `/login` works                                    | stale route cache built from a different route set                                                                     | `artisan optimize:clear && artisan optimize`                                                |
| `Vite manifest not found`                                        | someone introduced `@vite` in a view                                                                                   | build assets or remove the directive — this app serves static files from `public/`          |
| Login bounces back to `/login`                                   | `SESSION_SECURE_COOKIE=true` while HTTPS is not detected (nginx proxy), `APP_KEY` changed, or `sessions` table missing | see §4.3; never regenerate `APP_KEY` on an existing install                                 |
| `419 Page Expired` on POST                                       | session cookie dropped (same cause) or a form posted while the site was in maintenance mode                            | fix sessions, reload, retry                                                                 |
| "These credentials do not match our records" for a known account | production database has no users — migrations only create tables                                                       | `artisan foundation:user ...`; never seed development accounts                              |
| Login/registration blocked by captcha errors                     | `TURNSTILE_ENABLED=true` with empty or mismatched keys                                                                 | set keys and `TURNSTILE_HOSTNAMES=voicecast.gwosevo.com`, reload FPM, or disable            |
| Queue appears idle: no imports, no notifications                 | Horizon not running, or `QUEUE_CONNECTION` is not `redis`                                                              | `artisan horizon:status`, `journalctl -u voicecast-horizon -n 100`, verify `REDIS_PASSWORD` |
| Jobs fail with `Connection refused`                              | `redis-server` down                                                                                                    | `systemctl status redis-server`, then restart Horizon                                       |
| Scheduled campaigns never start                                  | scheduler not registered, or it fails silently                                                                         | run `artisan schedule:run` manually and read the output; check the Plesk task log           |
| 403 at `/horizon`                                                | the gate allowlist is empty (by design)                                                                                | §2.12                                                                                       |
| Permission denied writing logs or cache                          | ownership drifted after a root deploy                                                                                  | `chown -R <subscription-user>:<group> storage bootstrap/cache`                              |
| Audio uploads rejected                                           | Plesk `upload_max_filesize`/`post_max_size` below the 10 MB cap                                                        | raise both, restart FPM                                                                     |
| `check-platform-reqs` fails                                      | missing `pcntl`/`posix`/`redis` in the CLI build                                                                       | install `plesk-php84-redis`, restart the handler                                            |

## 8. Appendix

### 8.1 Service units (Plesk-corrected)

`/etc/systemd/system/voicecast-horizon.service`

```ini
[Unit]
Description=GWO SEVO Redis queue workers (Horizon)
After=network-online.target redis-server.service
Wants=network-online.target

[Service]
Type=simple
User=voicecast.gwosevo.co_bpehug59avj

WorkingDirectory=/var/www/vhosts/voicecast.gwosevo.com/httpdocs/gwosevo-voice-broadcast
ExecStart=/opt/plesk/php/8.4/bin/php artisan horizon
Restart=always
RestartSec=5
TimeoutStopSec=300
KillSignal=SIGTERM
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ReadWritePaths=/var/www/vhosts/voicecast.gwosevo.com/httpdocs/gwosevo-voice-broadcast/storage /var/www/vhosts/voicecast.gwosevo.com/httpdocs/gwosevo-voice-broadcast/bootstrap/cache
UMask=0007
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
```

`/etc/systemd/system/voicecast-ami-gateway.service`

```ini
[Unit]
Description=GWO SEVO persistent broadcast AMI gateway
After=network-online.target
Wants=network-online.target
StartLimitIntervalSec=120
StartLimitBurst=5

[Service]
Type=simple
User=voicecast.gwosevo.co_bpehug59avj

WorkingDirectory=/var/www/vhosts/voicecast.gwosevo.com/httpdocs/gwosevo-voice-broadcast
ExecStart=/opt/plesk/php/8.4/bin/php artisan broadcast:gateway
Restart=always
RestartSec=10
TimeoutStopSec=20
KillSignal=SIGTERM
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ReadWritePaths=/var/www/vhosts/voicecast.gwosevo.com/httpdocs/gwosevo-voice-broadcast/storage /var/www/vhosts/voicecast.gwosevo.com/httpdocs/gwosevo-voice-broadcast/bootstrap/cache
UMask=0077
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
```

Replace `PLESK_USER`/`PLESK_GROUP` with the subscription owner (commonly `<domain-user>:psacln`). `ProtectHome=true` is omitted on purpose: Plesk subscription paths are sometimes unreachable with it enabled, and a unit that refuses to start is worse than one with a slightly wider filesystem view.

### 8.2 Quick command reference

```bash
PHP=/opt/plesk/php/8.4/bin/php
$PHP artisan horizon:status                 # queue supervisor state
$PHP artisan horizon:terminate              # graceful worker recycle (systemd restarts it)
$PHP artisan queue:failed                   # failed jobs
$PHP artisan queue:retry all                # only after the cause is fixed
$PHP artisan broadcast:status --json        # gateway + campaign operational state
$PHP artisan schedule:list                  # the five scheduled tasks
$PHP artisan migrate:status                 # pending migrations
$PHP artisan foundation:user <email> --name="<name>" --role="Super Administrator"
$PHP artisan billing:wallet-show <client>   # wallet snapshot
$PHP artisan billing:wallet-adjust <client> # authorised adjustment with actor/reason
```

### 8.3 Definition of done for this deployment

- `https://voicecast.gwosevo.com` serves TLS with HSTS; `/up` and `/login` return 200 with stylesheets loading.
- Horizon, Redis, MySQL and the scheduler are running and supervised; the gateway is either correctly running (stage B+) or deliberately stopped (stage A).
- `git rev-parse HEAD` matches the recorded release commit, and a restore drill has been performed.
- Dispatch and payments stay disabled until their stage in §5 is signed off.

Confirmed deployment identity: subscription user `voicecast.gwosevo.co_bpehug59avj`, PHP `/opt/plesk/php/8.4/bin/php`, application `/var/www/vhosts/voicecast.gwosevo.com/httpdocs/gwosevo-voice-broadcast`. SSH authentication must succeed before this host can be inspected or deployed. Credentials are not stored in this document.

The Plesk service units omit Group so systemd uses the configured user’s primary group. Verify ownership on the server before enabling services.

Ready-to-install Plesk units are in `deploy/plesk/`. Install them only after checking existing services, ensuring no duplicate gateway, and verifying the subscription path and PHP extensions. SSH currently authenticates but exits before commands execute; enable subscription /bin/bash access to continue deployment. No production inspection or update has completed.

## Release preparation — 27 October 2026

Local release checks: 292 PHPUnit tests / 2,326 assertions, no failures or errors, one optional browser-fixture test skipped; IVR JavaScript smoke checks and Vite build passed. Added explicit campaign per-call-hold admission messages and a regression test. Wallet test fixtures now set their own 900-second exposure instead of inheriting a developer .env value. Production .env values were not changed.

Read-only live checks returned HTTP 200 for / and /up. Corrected credentials authenticated, but both interactive SSH and a remote-command probe immediately exited, so production files, database, services and release revision could not be inspected or changed. Enable subscription SSH /bin/bash access and confirm service restart privileges before continuing. A backup and active-call check are still required before deployment.
