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

review module 7 and apply any missing features and functions.
review module 10 and apply any missing features and functions.
review module 11 and apply any missing features and functions.
review module 12 and apply any missing features and functions.
review module 13 and apply any missing features and functions.
