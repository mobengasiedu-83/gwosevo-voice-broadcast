# PBX telemetry offline/stale

## Verified diagnosis (23 October 2026)

A bounded observation-only connection using the configured AMI account confirmed:

- Authentication succeeds; Ping/Pong succeeds.
- `CoreShowChannels` is not advertised to this account and returns `Permission denied`.
- Its fallback `Status` is also not advertised and returns `Permission denied`.
- `QueueStatus` is advertised and succeeds.
- No persistent `broadcast:gateway` process was running before the diagnostic.
- There were no reserved channels or uncertain attempts. Global channel/CPS limits were zero. The current dispatch flag was enabled; diagnostics explicitly used observation-only mode and placed no calls. No environment settings were changed.

Login/Ping cannot prove that active-channel inventory is available. The application deliberately keeps telemetry unhealthy until a complete inventory is received.

## PBX-side correction

Use the PBX's supported AMI-user administration interface for the account named by `VITALPBX_AMI_USERNAME`. Preserve existing rights and source-IP restrictions.

For channel observation, add **reporting** to the account's AMI **write/action** permissions and ensure **call** is present in **read/event** permissions. Asterisk registers `CoreShowChannels` under system/reporting authority; AMI uses action permissions even for this observational request. This does not require granting `all` or CLI command access. Verify requirements against the installed PBX using:

```text
manager show command CoreShowChannels
manager show command Status
manager show user <configured-AMI-username>
```

Apply the AMI configuration through the PBX UI. For an explicitly managed custom manager configuration, `manager reload` reloads manager settings; do not restart Asterisk or overwrite generated PBX files. Existing AMI sessions must reconnect to pick up changed permissions. Check event filters if inventory completion/events remain missing after permissions are corrected.

Sources: [Asterisk CoreShowChannels](https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/CoreShowChannels/) and [Asterisk manager action registration](https://github.com/asterisk/asterisk/blob/20/main/manager.c).

## Application-side verification and continuous operation

From the application directory, first verify without origination:

```sh
php artisan broadcast:gateway --observe --seconds=20
```

Expected terminal message: `AMI Pong and complete active-channel snapshot verified.` The bounded command intentionally disconnects on exit, so it cannot maintain healthy telemetry afterward.

Then run an ongoing observation gateway:

```sh
php artisan broadcast:gateway --observe
```

Keep it running under the deployment's process manager. On Linux, adapt `deploy/systemd/gwosevo-ami-gateway.service`; add `--observe` to its `ExecStart` for telemetry-only operation. Install only one gateway per database. Horizon alone does not run the AMI gateway. Normal dispatch mode is a separate operational decision governed by the existing verified capacity/approval safeguards.

Refresh the dashboard while the persistent gateway is connected. Its heartbeat and complete snapshot must remain within the fifteen-second freshness threshold. `php artisan broadcast:status --json` reports `gateway_healthy`; its overall exit code also considers calling readiness, so disabled/zero-capacity dispatch can still yield a failure exit code even when telemetry is healthy.

The PBX permission change cannot be performed from this workspace with the available application-only AMI access. Do not hide the warning or fabricate snapshot timestamps to clear it.
