# Plesk production deployment — voicecast.gwosevo.com

Reviewed 27 October 2026. This is a Linux Plesk runbook, not proof of production readiness. Confirm the OS, subscription user, actual project root, available PHP handler and service names before executing it. Portal deployment for controlled acceptance is distinct from enabling live calls or payments.

## Plesk setup

1. Create/configure voicecast.gwosevo.com and its DNS. Install a valid certificate, select it for the domain, enable HTTPS redirection and check certificate renewal.
2. Put the repository outside its own public web root. Set Hosting Settings → Document root to the repository's public directory (e.g. voicecast.gwosevo.com/httpdocs/public, depending on subscription layout). Do not add /public to APP_URL.
3. Select Plesk PHP 8.3 FPM with Apache proxy mode for the simplest use of public/.htaccess. If using nginx-only FPM, configure/test Laravel front-controller routing through Plesk-supported settings instead. Do not copy the standalone port-85 Apache/nginx templates over Plesk's generated vhosts.
4. Use the Plesk subscription system user for deployment, FPM ownership and background workers. Do not recursively chown this site to the old gwosevo user or replace Plesk ownership on the subscription.
5. Check PHP extensions with Composer platform requirements. MySQL PDO, mbstring, XML, curl, zip and required PHP packages must be present; Horizon also needs redis, pcntl and posix in CLI. CLI and FPM must use compatible PHP/extensions. Use the actual Plesk PHP executable, for example /opt/plesk/php/8.3/bin/php.
6. Create the application database/user in Plesk and install/configure a local or secured Redis service. Set upload limits to match the application's audio/import limits; test uploads through both nginx and FPM.
7. Restrict AMI network access to the application host and approved PBX; keep Redis/database private. Exclude/redact credential-bearing /pbx-media/ and internal-test-media URLs in proxy, web-server and monitoring logs. Verify the PBX can fetch HTTPS protected media with a trusted certificate.

## Production environment

Configure secrets on the server, never by copying development .env wholesale:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://voicecast.gwosevo.com
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=database
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
VOICE_BROADCAST_ENABLED=false
VOICE_BROADCAST_MEDIA_MODE=url
VOICE_BROADCAST_MEDIA_BASE_URL=https://voicecast.gwosevo.com
PAYMENT_TOPUPS_ENABLED=false
```

Configure the actual DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD (remove any copied ServBay DB_SOCKET or DB_URL override), Redis authentication if used, SMTP, SMS, Turnstile site/secret keys for the production domain, PBX AMI credentials, approved trunk/caller ID and verified capacity. Match BILLING_MAX_CALL_SECONDS to the installed one-way and generated IVR timeout policy. Configure CALL_RATING_TRUSTED_SOURCES only for validated precision timing evidence; this does not itself create an ingestion pipeline.

For hold cleanup configure BILLING_HOLD_CLEANUP_ACTOR_ID with an internal billing.manage user, review age/batch policy, then enable BILLING_HOLD_CLEANUP_ENABLED. Until configured, leave it false. Connect structured failure/backlog logs to monitoring. Configure notification channel credentials and verify real delivery. Horizon currently consumes dashboard-health/default; add any custom notification/import queue to its supervisor configuration.

Migration: preserve APP_KEY, existing database, and storage/app/private together. Do not regenerate APP_KEY or lose private recordings. Fresh installation only: generate a new key and create an initial administrator. Do not seed demonstration data.

## After pulling a reviewed release

Back up database, private storage and .env first using Plesk Backup Manager or your approved coordinated backup procedure. Pause calling, drain/reconcile active and uncertain attempts and stop the old gateway before a cutover. `artisan down` alone does not stop the AMI gateway. If changing servers, stop the old workers/gateway and ensure scheduled campaigns do not unexpectedly fire on the new host. Never run two gateways against the same database.

Run these commands as the subscription user, from the real application directory. The values below are examples to replace. Composer must be an actual Composer PHP script/phar, not a shell wrapper when passed to PHP.

```bash
set -e
APP=/var/www/vhosts/REPLACE_SUBSCRIPTION/REPLACE_PROJECT
PHP=/opt/plesk/php/8.3/bin/php
COMPOSER=/usr/lib/plesk-9.0/composer.phar
cd "$APP"

git status --short
# Stop if the server has uncommitted changes; preserve .env and storage.
# Optional when updating an existing installed application:
"$PHP" artisan down --retry=60

git fetch origin
# Use v2 only after the latest reviewed commit is actually on origin/v2.
git switch v2
git pull --ff-only origin v2
git rev-parse HEAD

"$PHP" "$COMPOSER" install --no-dev --prefer-dist --no-interaction --optimize-autoloader
"$PHP" "$COMPOSER" check-platform-reqs --no-dev
"$PHP" artisan optimize:clear
"$PHP" artisan migrate --force
"$PHP" artisan db:seed --class=RolesAndPermissionsSeeder --force

# Prefer building in CI and deploying public/build; otherwise use compatible Node/npm:
npm ci
npm run build
# Do not run npm dev or artisan serve on production.

chmod 600 .env
chmod -R u+rwX,go-rwx storage bootstrap/cache
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan view:cache
"$PHP" artisan migrate:status
"$PHP" artisan schedule:list
"$PHP" artisan up
```

Do not blindly run the chmod command if FPM/workers use different users; first align ownership or apply reviewed group ACLs. Public assets must remain readable by Plesk's web server. Do not run migrate:fresh, composer update or key:generate on an existing production installation. No storage:link is required for private broadcast audio.

Ensure the latest changes were pushed before pulling; a local branch/commit is not a deployed release. Record the released commit hash. The repository review did not verify origin/v2 contents.

## Background processes — root/server administrator setup

Three components are required: Horizon, one persistent AMI gateway, and the minute scheduler. Use systemd/Supervisor for long-running processes; do not run the gateway from cron. With only shared-hosting access, ask the host to provision these services; do not enable live calling until they can be supervised reliably.

Copy the two repository service templates to uniquely named live service units, then EDIT them before enabling:

```bash
sudo cp deploy/systemd/gwosevo-horizon.service /etc/systemd/system/voicecast-horizon.service
sudo cp deploy/systemd/gwosevo-ami-gateway.service /etc/systemd/system/voicecast-ami-gateway.service
sudoedit /etc/systemd/system/voicecast-horizon.service
sudoedit /etc/systemd/system/voicecast-ami-gateway.service
```

In BOTH units replace User/Group with the Plesk subscription user/group, WorkingDirectory with the actual application root, ExecStart's PHP with the Plesk PHP path, and ReadWritePaths with the real storage/bootstrap/cache paths. Review Redis service dependencies for the server's service name and ensure sandbox settings permit traversing the subscription paths. ExecStart must remain `PHP artisan horizon` or `PHP artisan broadcast:gateway` respectively.

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now voicecast-horizon.service
# Start the gateway only after the previous gateway is stopped and configuration is reviewed:
sudo systemctl enable --now voicecast-ami-gateway.service
```

In Plesk Scheduled Tasks add one non-chrooted subscription-user command every minute (or use Laravel Toolkit scheduling, or the adapted repository timer — choose ONE):

```
cd /ABSOLUTE/APP/ROOT && /opt/plesk/php/8.3/bin/php artisan schedule:run
```

Enable failure notifications for the scheduled task. Chrooted shells may need host-specific path adjustments. Do not enable a second generic Plesk queue worker alongside Horizon for the same deployment. The existing schedule handles campaign activation, dashboard probes, event/identity pruning and configured hourly hold cleanup.

After EACH code/config update, restart the two supervised services as server administrator to load the release:

```bash
sudo systemctl restart voicecast-horizon.service
sudo systemctl restart voicecast-ami-gateway.service
sudo systemctl status voicecast-horizon.service voicecast-ami-gateway.service --no-pager
```

Perform restarts only after the call-drain/reconciliation step. Configure an authorized service-management mechanism if deployment runs without root; do not give the web process broad sudo access. If workers are supervised under another manager, use its restart mechanism instead.

## Verification before enabling calls/payments

```bash
curl --fail --silent --show-error https://voicecast.gwosevo.com/up
# From application directory as subscription user:
/opt/plesk/php/8.3/bin/php artisan horizon:status
/opt/plesk/php/8.3/bin/php artisan schedule:list
/opt/plesk/php/8.3/bin/php artisan broadcast:status
/opt/plesk/php/8.3/bin/php artisan queue:failed
```

Check systemd journal and Laravel logs, HTTPS/redirection, login, role/client isolation, upload/preview, queued import, real OTP/notifications, minute scheduler execution and client wallet/tariff prerequisites. /up proves application boot, not PBX/billing/provider readiness. Validate audible playback, DTMF/timeout/opt-out/transfer, disconnect/reconnect, emergency stop, trusted timing and wallet settlement/release before enabling dispatch. Verify provider contract/callback/refund reconciliation before enabling top-ups. Re-cache config and restart services after flag changes.

If deployment fails, keep dispatch disabled and the site in maintenance until resolved. Code rollback may require a coordinated database/storage restore; do not blindly roll back financial migrations. Retain the prior release and backup for the approved recovery plan.

## References

- Plesk Laravel Toolkit: https://doc.plesk.com/en-US/obsidian/administrator-guide/website-management/laravel-toolkit.80010/
- Plesk document root: https://support.plesk.com/hc/en-us/articles/12377087631255-How-to-change-the-document-root-for-a-single-domain-on-Plesk
- Plesk scheduler: https://support.plesk.com/hc/en-us/articles/12377989953303-How-to-enable-scheduled-tasks-for-Laravel-project-in-Laravel-Toolkit
