## Recovering a partially applied notification migration

If `2026_10_26_180000_add_notifications` fails because `user_notifications`
already exists, the table exists while Laravel still considers the migration
pending. MySQL can retain tables created before a later migration step fails.
Check the earlier deployment error or logs for the original cause.

Back up the production database, then deploy the corrected migration from this
repository. It creates each missing notification table independently and preserves
existing rows. Run:

```sh
cd /var/www/gwosevo-voice-broadcast
sudo -u gwosevo php artisan migrate:status
sudo -u gwosevo php artisan migrate --force
sudo -u gwosevo php artisan migrate:status
```

The migration should now show as Ran. If another error appears, resolve that
error before continuing the update steps below. Existing tables are assumed to
have the expected schema; the retry does not repair missing columns, indexes, or
foreign keys. Inspect `SHOW CREATE TABLE user_notifications` and, if present,
`SHOW CREATE TABLE notification_deliveries` when investigating schema differences.
Do not drop production tables, run `migrate:fresh`, or manually mark the migration
as completed to bypass the error.

## Production update

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

echo "=== SEED ROLES AND PERMISSIONS ==="
sudo -u gwosevo php artisan db:seed --class=RolesAndPermissionsSeeder --force

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

review module 7 and apply any missing features and functions.
review module 10 and apply any missing features and functions.
review module 11 and apply any missing features and functions.
review module 12 and apply any missing features and functions.
review module 13 and apply any missing features and functions.
review module 14 and apply any missing features and functions.
review module 15 and apply any missing features and functions.
review module 16 and apply any missing features and functions.
