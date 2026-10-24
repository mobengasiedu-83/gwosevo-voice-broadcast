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
