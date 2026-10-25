cd /var/www/gwosevo-voice-broadcast

set -e

echo "=== INSTALL DEPENDENCIES ==="
sudo -u gwosevo composer install \
 --no-dev \
 --prefer-dist \
 --no-interaction \
 --optimize-autoloader

echo "=== RUN DATABASE MIGRATIONS ==="
sudo -u gwosevo php artisan migrate --force

echo "=== CLEAR OLD CACHES ==="
sudo -u gwosevo php artisan optimize:clear

echo "=== REBUILD PRODUCTION CACHES ==="
sudo -u gwosevo php artisan config:cache
sudo -u gwosevo php artisan route:cache
sudo -u gwosevo php artisan view:cache

echo "=== RESTART QUEUE WORKERS ==="
sudo -u gwosevo php artisan queue:restart

echo "=== VERIFY APPLICATION ==="
sudo -u gwosevo php artisan about --only=environment

echo "=== ENSURE APPLICATION IS ONLINE ==="
sudo -u gwosevo php artisan up

echo "=== UPDATE COMPLETED ==="

cd /var/www/gwosevo-voice-broadcast

echo "=== APPLICATION HEALTH ==="
curl -s -o /dev/null -w "HTTP %{http_code}\n" \
 http://173.16.254.253:85/up

echo "=== BROADCAST STATUS ==="
sudo -u gwosevo php artisan broadcast:status

echo "=== RECENT ERRORS ==="
sudo tail -n 30 storage/logs/laravel.log

sudo -u gwosevo php artisan optimize
sudo systemctl restart gwosevo-ami-gateway

### Permissions

cd /var/www/gwosevo-voice-broadcast
sudo chown -R gwosevo:gwosevo .
sudo find . -type d -exec chmod 755 {} \;
sudo find . -type f -exec chmod 644 {} \;
sudo chmod 600 .env
sudo chmod -R u+rwX,go-rwx storage bootstrap/cache

## Client accounts and authentication (Steps 02–03)

These steps add client account isolation and public email/phone registration. Before serving the new code on another server, run the migrations and role seeder; configure mail and TXTConnect SMS, then refresh application caches. See [Step 02](step-02-client-foundation.md) and [Step 03](step-03-client-authentication.md) for the commands, scope and verification checklist. Local application does not apply these changes to the production server automatically.

## Step 04 — Client portal

Run `php artisan migrate --force` and `php artisan client:sync-review-notices` after deploying Step 04, then refresh caches/processes using the normal deployment procedure. Include the new client portal CSS asset. Details: [Step 04 client portal](step-04-client-portal.md).

## Step 05 — Client contacts and imports

Step 05 needs no additional migration or role grants. Deploy the client contact templates/routes/controller and shared Imports service, refresh caches and restart the configured default queue workers. See [Step 05](step-05-client-contacts.md) for workflow and deployment details.

## Step 06 — Client audio library

No migration or new role grants are needed. Deploy the client audio controller/library/templates and shared audio service changes, then refresh caches/processes. Check private-storage write access and PHP/Apache upload limits. [Step 06 guide](step-06-client-audio.md).

## Step 07 — Client campaigns

No schema migration or new client permissions are required. Deploy the client campaign controller/service/templates and shared engine/gateway gates, refresh caches and restart long-running processes. Client execution defaults off; configure the restricted environment and authorized test numbers before enabling it. Existing `.env` values are unchanged. See [Step 07](step-07-client-campaigns.md). Real audible PBX acceptance and Stages 08–09 billing remain separate release requirements.

## Stage 08 — Pricing and wallets

Run the additive wallet migration and the `BillingPermissionsSeeder` and `DefaultRatePlanSeeder` seeders. They create financial tables/append-only guards, finance permissions and the owner-confirmed GHS 0.003/minute reference price; they do not assign accounts or grant balances. Billing admission defaults off. Assign prices, record verified manual credit and complete controlled acceptance before enabling `VOICE_BILLING_ENABLED`, then refresh caches and restart gateway/worker processes. [Stage 08 policy and deployment](step-08-wallet-billing.md).

## Configurable billing policy

Apply the additive `2026_10_24_040000_add_configurable_billing_policy` migration. Billing Administrators and Super Administrators can configure versioned prices, currency/precision, duration basis, rounding, failure treatment and actual-duration credit exposure at `/billing`. Assign a new policy version to an account for future launches. Actual-duration settlement requires verified duration evidence; existing runs and posted ledger entries retain their original policy. [Policy configuration](step-08-wallet-billing.md#administrator-configurable-billing-policies).

## Stage 10 — Notifications and reports

Apply the additive `2026_10_24_050000_add_notifications_and_reports` migration, deploy report/bell assets, refresh caches and restart queue workers. Keep the minute scheduler running for notice synchronization, report schedules and retention. External email/SMS notices require verified personal opt-in and `VOICE_ALERT_DELIVERY_ENABLED=true` (default false). No new role grants are needed. [Stage 10 operations and report definitions](step-10-notifications-reports.md). Stage 09 payment integration remains deferred in [reminders](reminder.md#stage-09--payment-gateway-and-top-ups-deferred).

## Dual-contact registration

Apply `2026_10_24_070000_add_registration_contact_details`, refresh caches and restart workers. Signup requires email, Ghana mobile, country and Terms/Privacy acceptance, verifies the selected contact by a five-minute OTP, and records combined policy version/timestamp. Existing clients retain their data. Contact settings allow verification and pending replacements, with OTP audit records. Replace the public dummy policy page before launch. [Authentication details](step-03-client-authentication.md#dual-contact-signup-and-contact-changes).

## Step 11 — Advanced campaigns and IVR

Apply `2026_10_24_080000_add_campaign_series`, deploy views/IVR assets, refresh caches and restart normal long-running processes. Keep the minute scheduler running for `campaigns:recurring` and existing activation/expiry maintenance. New IVR telemetry requires a new approved/deployed IVR version; existing installed versions remain unchanged. No dispatch flags, real schedules or PBX configuration are enabled by installation. [Step 11 operations](step-11-advanced-campaigns-ivr.md).

## Stage 12 external integrations

Apply the existing integration foundation migration and `2026_10_25_010000_complete_external_integrations`, deploy views/routes/services, refresh caches and restart gateway and queue processes through the normal deployment procedure. No new seeders or permission grants are needed. The minute scheduler runs `integrations:maintain`; this command schedules actual deliveries when enabled. Use `integrations:status` for read-only counts. API and webhook switches default off; no live `.env` values are changed by installation. See [Stage 12](step-12-integrations.md) for contracts, receiver signing, retention and release acceptance.

## Client Management lifecycle

Apply `2026_10_25_020000_add_client_management`, then run `php artisan db:seed --class=ClientLifecyclePermissionsSeeder --force`. Refresh application caches and restart the normal long-running workers/gateway through the controlled deployment procedure. Existing first account members become client Owners and other members client Administrators; review those assignments. The seeder registers permission names only, without changing any internal role grants. Assign approved staff grants separately. No financial permissions, balances, activation flags, messages or live calls are enabled. See [Client Management review and implementation](client-management-review.md) for permissions, delegation, private documents, invitations, restrictions and acceptance.
