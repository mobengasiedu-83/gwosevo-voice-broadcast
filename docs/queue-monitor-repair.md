# Isolated queue telemetry and PBX investigation

The calling gateway must never issue QueueStatus. `dashboard:queues` owns a separate AMI connection and publishes only complete snapshots. It closes failed connections, marks queue telemetry unavailable, and waits 60 seconds before retrying. An OS lock prevents duplicate monitors on one host. Run a single monitor per application deployment.

## Production installation (administrator)

For the existing Plesk installation using gwosevo service names:

```sh
cd /var/www/vhosts/voicecast.gwosevo.com/httpdocs/gwosevo-voice-broadcast
cp deploy/plesk/voicecast-queue-monitor.service /etc/systemd/system/gwosevo-queue-monitor.service
systemd-analyze verify /etc/systemd/system/gwosevo-queue-monitor.service
systemctl daemon-reload
systemctl enable --now gwosevo-queue-monitor.service
systemctl restart gwosevo-ami-gateway.service
systemctl status gwosevo-ami-gateway.service gwosevo-queue-monitor.service --no-pager
journalctl -u gwosevo-queue-monitor.service -n 30 --no-pager
```

Use a coordinated maintenance window if calls are active. Do not start another broadcast gateway manually. The guarded full service installer also provisions the queue monitor, but requires dispatch disabled and calls drained.

## Root cause confirmed on 2026-10-27

The AMI connection crossed an IPsec tunnel from VoiceCast to the PBX. Small responses arrived, but larger queue responses stopped partway through. Subsequent requests on that connection could not complete. The initial symptom resembled a PBX queue action stall; authenticated PBX inspection showed that local `queue show` completed normally.

A size comparison confirmed that QueueStatus for Q5000 and Q6000 completed, while the larger Q8001 response did not. Reducing the tunnel route to MTU 1300 / advertised MSS 1260 immediately restored the full response: 7 QueueParams, 11 QueueMember, QueueStatusComplete, and a subsequent Pong. This confirms a tunnel packet-size / path-MTU problem, rather than establishing a queue-module deadlock.

The PBX reports VitalPBX 4.5.4-2, Asterisk 20.21.0 and AMI 9.0.0. No PBX modules, queue configuration, or PBX services were changed or restarted.

## Applied network repair

On the VoiceCast server, `/etc/strongswan.d/charon/kernel-netlink.conf` now contains these settings inside its existing `kernel-netlink` block:

```conf
mss = 1260
mtu = 1300
```

Original configuration backup: `/etc/strongswan.d/charon/kernel-netlink.conf.backup-20261027`.

The active route is:

```text
173.16.254.254 via 62.129.149.254 dev ens32 table 220
    src 62.129.149.157 mtu 1300 advmss 1260
```

These plugin settings apply to routes installed by strongSwan, rather than changing the public network interface MTU. Documentation: https://docs.strongswan.org/docs/latest/howtos/forwarding.html

The IPsec daemon had been started manually outside its enabled systemd service. With zero active PBX calls/channels and no active VoiceCast attempts/campaigns, it was stopped and started through `strongswan-starter.service` at 19:59:53 UTC. The tunnel re-established, and strongSwan automatically recreated the route with MTU 1300 / MSS 1260. This verifies persistence across daemon/tunnel restart. The service is enabled for boot and now actively supervises the daemon.

To inspect:

```sh
systemctl status strongswan-starter.service --no-pager
ipsec status
ip route show table 220
systemctl status gwosevo-ami-gateway.service gwosevo-queue-monitor.service --no-pager
```

Do not run `ipsec start` manually alongside systemd. For later tunnel maintenance use `systemctl restart strongswan-starter.service` in a coordinated maintenance window.

## Validation

- Local: 64 tests, 229 assertions passed across QueueMonitorTest, OneWayBroadcastTest and DashboardTest; formatting, shell syntax and whitespace checks passed.
- Queue isolation deployed at 19:52:09 UTC. Gateway remained healthy across repeated queue worker failures before the tunnel repair.
- After the network repair, dashboard telemetry became healthy with 7 queues and 11 members.
- After restarting the tunnel under systemd, the gateway remained healthy with fresh heartbeat and channel snapshot timestamps; the route retained the saved MTU/MSS values.

The queue monitor remains isolated as defense against future queue/network failures. Do not loosen gateway heartbeat or inventory checks to conceal failures.
