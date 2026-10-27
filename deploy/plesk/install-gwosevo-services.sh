#!/usr/bin/env bash
set -euo pipefail
APP=/var/www/vhosts/voicecast.gwosevo.com/httpdocs/gwosevo-voice-broadcast
SITE_USER=voicecast.gwosevo.co_bpehug59avj
PHP_BIN=/opt/plesk/php/8.4/bin/php
[[ ${EUID} -eq 0 ]] || { echo 'Run this installer as root (system services require administrator access).'; exit 1; }
[[ -f "$APP/artisan" && -x "$PHP_BIN" ]] || { echo 'Application or PHP path missing.'; exit 1; }
cd "$APP"
# Refuse service replacement while calling is enabled or calls remain unresolved.
runuser -u "$SITE_USER" -- "$PHP_BIN" <<'PHP'
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('voice_broadcast.enabled')) { fwrite(STDERR,"Disable dispatch and drain calls before installing services.\n"); exit(1); }
$active = Illuminate\Support\Facades\DB::table('call_attempts')->whereIn('state',['authorizing','ready','sending','sent','active','unknown'])->exists();
if ($active) { fwrite(STDERR,"Unresolved calls exist; reconcile before installing services.\n"); exit(1); }
echo "Application preflight passed; dispatch remains disabled.\n";
PHP
if crontab -u "$SITE_USER" -l 2>/dev/null | grep -v '^#' | grep -q 'artisan.*schedule:'; then
    echo 'An artisan scheduler exists in the subscription crontab. Remove that duplicate through Plesk before enabling this timer.'; exit 1
fi
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
BACKUP=/etc/systemd/system/gwosevo-backup-$STAMP
mkdir -m 700 "$BACKUP"
for name in voicecast-horizon.service voicecast-ami-gateway.service gwosevo-horizon.service gwosevo-ami-gateway.service gwosevo-scheduler.service gwosevo-scheduler.timer; do
    [[ ! -f /etc/systemd/system/$name ]] || cp -a "/etc/systemd/system/$name" "$BACKUP/"
done
for pair in 'horizon:horizon' 'ami-gateway:broadcast:gateway'; do
    name=${pair%%:*}; command=${pair#*:}
    cat > "/etc/systemd/system/gwosevo-$name.service" <<UNIT
[Unit]
Description=GWO SEVO $name
After=network-online.target
Wants=network-online.target
StartLimitIntervalSec=120
StartLimitBurst=5
[Service]
Type=simple
User=$SITE_USER
WorkingDirectory=$APP
ExecStart=$PHP_BIN artisan $command
Restart=always
RestartSec=10
TimeoutStopSec=300
KillSignal=SIGTERM
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ReadWritePaths=$APP/storage $APP/bootstrap/cache
UMask=0077
StandardOutput=journal
StandardError=journal
[Install]
WantedBy=multi-user.target
UNIT
done
cat > /etc/systemd/system/gwosevo-scheduler.service <<UNIT
[Unit]
Description=GWO SEVO Laravel minute scheduler
After=network-online.target
[Service]
Type=oneshot
User=$SITE_USER
WorkingDirectory=$APP
ExecStart=$PHP_BIN artisan schedule:run
NoNewPrivileges=true
PrivateTmp=true
UMask=0077
UNIT
cat > /etc/systemd/system/gwosevo-scheduler.timer <<'UNIT'
[Unit]
Description=Run GWO SEVO Laravel scheduler every minute
[Timer]
OnCalendar=*-*-* *:*:00
Persistent=true
Unit=gwosevo-scheduler.service
[Install]
WantedBy=timers.target
UNIT
systemd-analyze verify /etc/systemd/system/gwosevo-{ami-gateway,horizon,scheduler}.service /etc/systemd/system/gwosevo-scheduler.timer
systemctl daemon-reload
# Replace the old named services; never run both copies.
for old in voicecast-horizon.service voicecast-ami-gateway.service; do
    if systemctl cat "$old" >/dev/null 2>&1; then systemctl disable --now "$old"; fi
done
systemctl enable --now gwosevo-horizon.service gwosevo-ami-gateway.service gwosevo-scheduler.timer
systemctl start gwosevo-scheduler.service
systemctl --no-pager --full status gwosevo-horizon.service gwosevo-ami-gateway.service gwosevo-scheduler.timer
systemctl --no-pager --full show gwosevo-scheduler.service -p Result -p ExecMainStatus
systemctl list-timers gwosevo-scheduler.timer --no-pager
printf 'Unit backups: %s\n' "$BACKUP"
printf 'Check gateway journal and PBX heartbeat; active systemd status alone is not AMI connectivity.\n'
