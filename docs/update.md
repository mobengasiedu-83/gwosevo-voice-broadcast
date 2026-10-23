cd /var/www/gwosevo-voice-broadcast

# Stop immediately if a command fails

set -e

echo "=== 1. VERIFY DEPLOYMENT ==="

git status
git branch --show-current

# Require the expected branch and a clean working tree

test "$(git branch --show-current)" = "main"
test -z "$(git status --porcelain)"

echo "=== 2. FETCH LATEST CODE ==="

git fetch origin main

echo "=== 3. ENABLE MAINTENANCE MODE ==="

sudo -u gwosevo php artisan down

echo "=== 4. UPDATE APPLICATION ==="

git merge --ff-only origin/main

echo "=== 5. VERIFY COMPOSER OWNERSHIP ==="

sudo chown -R gwosevo:gwosevo vendor

echo "=== 6. INSTALL PHP DEPENDENCIES ==="

sudo -u gwosevo composer install \
 --no-dev \
 --prefer-dist \
 --no-interaction \
 --optimize-autoloader

echo "=== 7. RUN DATABASE MIGRATIONS ==="

sudo -u gwosevo php artisan migrate --force

echo "=== 8. CLEAR OLD LARAVEL CACHES ==="

sudo -u gwosevo php artisan optimize:clear

echo "=== 9. REBUILD PRODUCTION CACHES ==="

sudo -u gwosevo php artisan config:cache

sudo -u gwosevo php artisan route:cache

sudo -u gwosevo php artisan view:cache

echo "=== 10. RESTART QUEUE WORKERS ==="

sudo -u gwosevo php artisan queue:restart

echo "=== 11. VERIFY APPLICATION ==="

sudo -u gwosevo php artisan about --only=environment

sudo -u gwosevo php artisan route:list --path=pbx-media

sudo -u gwosevo php artisan route:list --path=internal-test-media

echo "=== 12. BRING APPLICATION ONLINE ==="

sudo -u gwosevo php artisan up

echo "=== DEPLOYMENT COMPLETE ==="
