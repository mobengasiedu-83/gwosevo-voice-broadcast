# Production installation — Ubuntu 24.04

This guide assumes a separate Ubuntu 24.04 application server at 173.16.254.253, Apache port 85, MariaDB and Redis on the same server, and VitalPBX on its existing server. Do not install this stack onto the PBX itself without reviewing its existing services. Adapt commands for another operating system.

The application path is /var/www/gwosevo-voice-broadcast. The browser and protected-media base URL will both be http://173.16.254.253:85, without /public. Apache exposes only the public directory.

## 1. Choose fresh installation or migration

For a fresh installation, create a new database, a new APP_KEY and a new administrator.

To keep existing users, groups, campaigns and recordings, migrate the existing database and private storage instead. Preserve the original APP_KEY securely; do not generate a replacement key. Copy storage/app/private, including broadcast/audio, along with the database. Back up the database, .env and private storage together.

Do not run two gateways against the same production database or carry active/scheduled test campaigns into production unnoticed. Stop the old gateway and workers, pause calling, and put the old web app in maintenance mode before the final migration copy. Resolve any active/uncertain calls before cutting over. No migrate:fresh or database reset is required.

## 2. Install server packages

Run as a server administrator:

    sudo apt update
    sudo apt install apache2 mariadb-server redis-server composer unzip rsync php8.3-cli php8.3-fpm php8.3-mysql php8.3-redis php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-gd php8.3-bcmath php8.3-intl
    sudo systemctl enable --now mariadb redis-server php8.3-fpm apache2

Use PHP 8.3 or compatible newer PHP for CLI and FPM. A locked dependency, spatie/laravel-permission, requires 8.3. Horizon also needs CLI pcntl/posix support; check php -m. Composer's platform check below is authoritative for installed extensions.

The current Blade screens reference static files in public/css and public/js directly, so Node and a Vite build are not required for these screens. Deploy those directories. If you later enable @vite assets, build them in CI using a Node version compatible with Vite 7 and ship public/build. This checkout currently has no package-lock.json; generate/review one with npm install before using npm ci.

Create a dedicated service account and destination (skip account creation if already present):

    sudo useradd --system --create-home --home-dir /var/lib/gwosevo --shell /bin/bash gwosevo
    sudo install -d -o gwosevo -g gwosevo -m 755 /var/www/gwosevo-voice-broadcast

Transfer the project source into that directory using SFTP/rsync and your deployment account. Include composer.lock, public assets, app, bootstrap, config, database, resources, routes, artisan and deploy. Exclude local .env, node_modules, vendor, public/hot, logs and compiled cache files. Transfer private audio separately for a migration. Do not expose the project directory through a generic web root.

After transfer:

    sudo chown -R gwosevo:gwosevo /var/www/gwosevo-voice-broadcast
    sudo -iu gwosevo

The following application commands run in this dedicated account:

    cd /var/www/gwosevo-voice-broadcast
    composer install --no-dev --prefer-dist --optimize-autoloader
    composer check-platform-reqs --no-dev

Do not use npm run dev or php artisan serve in production.

## 3. Configure database and environment

As the server administrator, open sudo mariadb and run the following, replacing the password privately:

    CREATE DATABASE gwosevo_voice_broadcast CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CREATE USER 'gwosevo'@'localhost' IDENTIFIED BY 'REPLACE_WITH_A_STRONG_PASSWORD';
    GRANT ALL PRIVILEGES ON gwosevo_voice_broadcast.* TO 'gwosevo'@'localhost';

For migration, import the final database backup into this database before running migrations.

As gwosevo, copy .env.example to .env for a fresh installation, or securely install the migrated configuration. Set:

    APP_NAME="GWO SEVO Dialer"
    APP_ENV=production
    APP_DEBUG=false
    APP_URL=http://173.16.254.253:85

    DB_CONNECTION=mysql
    DB_HOST=localhost
    DB_PORT=3306
    DB_DATABASE=gwosevo_voice_broadcast
    DB_USERNAME=gwosevo
    DB_PASSWORD=YOUR_DATABASE_PASSWORD

    QUEUE_CONNECTION=redis
    CACHE_STORE=redis
    SESSION_DRIVER=database
    SESSION_SECURE_COOKIE=false
    REDIS_CLIENT=phpredis
    REDIS_HOST=127.0.0.1
    REDIS_PORT=6379

    VOICE_BROADCAST_ENABLED=false
    VOICE_BROADCAST_MEDIA_MODE=url
    VOICE_BROADCAST_MEDIA_BASE_URL=http://173.16.254.253:85

Set the existing VITALPBX_AMI_HOST, PORT, USERNAME and SECRET securely, and the approved configured trunk, caller ID and verified capacity settings. Import/copy actual settings; do not substitute guessed capacity. Leave dispatch disabled through installation.

HTTP is the currently requested address. Before exposing logins/media outside a trusted network, configure a trusted HTTPS hostname and change both base URLs plus SESSION_SECURE_COOKIE=true.

    chmod 600 .env

Fresh installation only:

    php artisan key:generate

Then, for either fresh or migrated installations:

    php artisan migrate --force
    php artisan db:seed --class=RolesAndPermissionsSeeder --force

Fresh installation only (password entered interactively):

    php artisan foundation:user admin@example.com --name="Administrator" --role="Super Administrator"

A migrated database keeps its existing users and password hashes. Do not recreate those users.

## 4. PHP-FPM and Apache

As the server administrator, create /etc/php/8.3/fpm/pool.d/gwosevo.conf:

    [gwosevo]
    user = gwosevo
    group = gwosevo
    listen = /run/php/gwosevo.sock
    listen.owner = www-data
    listen.group = www-data
    listen.mode = 0660
    pm = dynamic
    pm.max_children = 10
    pm.start_servers = 2
    pm.min_spare_servers = 2
    pm.max_spare_servers = 4
    clear_env = yes
    php_admin_value[upload_max_filesize] = 12M
    php_admin_value[post_max_size] = 16M
    php_admin_value[memory_limit] = 256M
    php_admin_flag[display_errors] = off

The web process, Horizon and gateway deliberately run as the same application user so private audio created by any of them remains readable by the others. Tune process counts to server memory and traffic.

From the application directory, as the server administrator:

    sudo chmod -R u+rwX storage bootstrap/cache
    sudo a2enmod rewrite proxy proxy_fcgi setenvif headers
    sudo cp deploy/apache/gwosevo.conf /etc/apache2/sites-available/gwosevo.conf

Edit /etc/apache2/ports.conf and add Listen 85 once, unless Apache already listens on port 85. Keep existing listeners and unrelated sites intact. Ensure no other service owns port 85.

    sudo a2ensite gwosevo.conf
    sudo php-fpm8.3 -t
    sudo apache2ctl configtest
    sudo systemctl reload php8.3-fpm apache2

Confirm parent directories and public files are readable/traversable by www-data; keep .env mode 600 and private storage out of the web root. Never use chmod 777. Keep public/.htaccess in the deployment: the virtual host permits its Laravel rewrite rules.

The dedicated FPM pool above is required because this virtual host uses /run/php/gwosevo.sock. Do not combine it with a mod_php handler for this site. No global Apache PHP-handler change is needed for unrelated sites. The earlier Nginx template is optional and is not used for your Apache deployment.

Allow TCP 85 from browsers that need the application and from VitalPBX through your server/network firewall. Allow the application server to reach the PBX's configured AMI port. Keep database and Redis access local. If using UFW, allow your actual SSH management port before changing firewall rules.

The Apache template excludes media URLs from access logs because they contain call credentials. Also redact these URLs in any upstream proxy/APM logs.

## 5. Start the three background components

As the application user, cache configuration:

    php artisan optimize

As the server administrator:

    sudo cp deploy/systemd/gwosevo-ami-gateway.service /etc/systemd/system/
    sudo cp deploy/systemd/gwosevo-horizon.service /etc/systemd/system/
    sudo cp deploy/systemd/gwosevo-scheduler.service /etc/systemd/system/
    sudo cp deploy/systemd/gwosevo-scheduler.timer /etc/systemd/system/
    sudo systemctl daemon-reload
    sudo systemctl enable --now gwosevo-horizon gwosevo-ami-gateway gwosevo-scheduler.timer

These templates use /usr/bin/php, user/group gwosevo and /var/www/gwosevo-voice-broadcast. Adjust them if your paths differ. Run exactly one gateway for this installation. Do not add a second cron scheduler when using the supplied timer.

    sudo systemctl status gwosevo-horizon gwosevo-ami-gateway gwosevo-scheduler.timer
    sudo journalctl -u gwosevo-ami-gateway -n 50
    sudo journalctl -u gwosevo-horizon -n 50
    sudo systemctl list-timers gwosevo-scheduler.timer

Horizon processes both default imports and dashboard-health jobs. Starting only a web server will leave imports waiting and PBX telemetry stale.

## 6. One-time VitalPBX URL-playback setup

Follow docs/application-hosted-audio.md. In particular:

- Confirm res_http_media_cache, its dependencies and WAV support on the PBX.
- Install deploy/asterisk/gwosevo-broadcast-url.conf using VitalPBX's supported custom dialplan mechanism, then reload and verify the gwosevo-broadcast-url context.
- Keep that context out of public/inbound dialplan routes.
- Confirm VitalPBX can reach http://173.16.254.253:85. No per-recording WAV copies are needed.
- For IVR, install the regenerated approved flow dialplan and record its actual checksum. Flow dialplan deployment remains necessary even though recordings are application-hosted.

Do not replace VitalPBX-generated configuration files wholesale.

## 7. Verify before enabling calls

From the application server:

    curl -f http://173.16.254.253:85/up

Repeat this check FROM the PBX, then verify the login page and log in. The /up check confirms Laravel boots; it does not prove database, queues or PBX readiness.

As gwosevo:

    php artisan vitalpbx:test
    php artisan broadcast:status
    php artisan horizon:status
    php artisan schedule:list

In the UI, verify fresh gateway heartbeat/channel snapshot, healthy infrastructure, completed contact imports, previewable approved audio, configured caller ID/trunk, verified capacity and no unreviewed scheduled executions.

Do not run the old local gateway once production owns the installation. Keep dispatch disabled until the PBX URL context is installed and the above checks pass. Set verified nonzero global channel/CPS limits, enable dispatch deliberately, rebuild config and restart the gateway. Then run one explicitly chosen test call, confirm actual audio and playback completion before starting a group campaign.

## 8. Updates and backups

Back up database, .env/APP_KEY, private recordings and PBX custom configuration. Test restoring them together. Never commit .env or distribute it with a source archive.

For later deployments, use a maintenance window with no active calls: pause launches, stop the gateway, back up, deploy code/build artifacts, composer install --no-dev, migrate --force, optimize, then restart the gateway and gracefully recycle Horizon:

    sudo -u gwosevo /usr/bin/php artisan horizon:terminate
    sudo systemctl restart gwosevo-ami-gateway
    sudo systemctl reload php8.3-fpm

Run artisan commands from the application directory. The Horizon systemd unit restarts it after termination. Review migrations before deciding whether code-only rollback is safe; do not undo database changes blindly.

References:
- https://laravel.com/docs/12.x/deployment
- https://laravel.com/docs/12.x/horizon
