# Step 03 — Client registration and authentication

## Implemented flow

1. Open **Sign in → Create a client account** (`/register`). Enter a name, organization/account name and either an email address or a Ghana phone number.
2. VoiceCast sends a six-digit registration code by the matching channel: email through Laravel mail, or SMS through TXTConnect. Only the submitted identifier is verified; email and phone are alternatives, not two mandatory verification steps.
3. Enter the code, then create and confirm a password of 12–128 characters containing uppercase/lowercase letters, a number and a symbol.
4. Only after successful verification and password creation does VoiceCast create an active client account and its first Client user, in one transaction. No internal role can be selected or submitted through this flow.
5. Subsequent sign-in uses the verified email or normalized phone number **and password**. OTP is not part of routine login. Existing internal email/password login remains supported.
6. **Forgot password** sends a single-use, 30-minute recovery link to the matching verified contact on an active client account. Recovery does not sign the user in. After resetting, sign in using the new password.
7. **Client account → Account settings** allows name/password changes after current-password confirmation. Sign-in contact and account membership are read-only here, so an unverified replacement cannot take over an account.

Clients land on the restricted client workspace introduced in [Step 04](step-04-client-portal.md). Operational contacts/audio/campaign screens, billing and caller ID controls remain later stages. Public registration does not grant access to internal administration or approve audio.

## Delivery configuration

Email uses the existing `MAIL_*` settings. Configure a working SMTP or supported transactional mail transport. The log/array transports are development tools, not delivery to an inbox; production rejects them and the default log-fallback mailer for these messages.

TXTConnect integration uses the provider's documented JSON POST endpoint and checks its response for acceptance:

- Documentation: https://txtconnect.net/ApiDocumentation
- Endpoint: `https://api.txtconnect.net/dev/api/sms/send`
- Bearer API token, `to`, `from`, `unicode` and `sms` fields.
- Provider acceptance is not proof of handset delivery. Live delivery still needs a controlled acceptance test with your approved sender and SMS credit.

Configure on the server; never commit credentials:

```dotenv
CLIENT_REGISTRATION_ENABLED=true
CLIENT_SMS_DRIVER=txtconnect
TXTCONNECT_API_URL=https://api.txtconnect.net/dev/api/sms/send
TXTCONNECT_API_TOKEN=your-private-api-token
TXTCONNECT_SENDER_ID=your-approved-sender
```

SMS defaults to `disabled` until credentials are supplied. Phone registration then fails clearly rather than pretending to send a code. Email remains available independently. No real SMS or email is sent by automated tests.

Use a stable, externally reachable HTTPS `APP_URL` for recovery links in production. Configure secure session cookies and the correct proxy/HTTPS setup. Recovery links contain secrets: protect web access logs and do not copy live links into tickets. The reset page uses `no-referrer` and `no-store` response headers.

## Applying the changes

Step 02 and Step 03 migrations have been applied to the local development database. This does not deploy to production. For another installation, after database backup:

```bash
cd /var/www/gwosevo-voice-broadcast
sudo -u gwosevo php artisan migrate --force
sudo -u gwosevo php artisan db:seed --class=RolesAndPermissionsSeeder --force
sudo -u gwosevo php artisan optimize
sudo -u gwosevo php artisan horizon:terminate
```

Restart long-running application processes through the normal deployment procedure, arranging gateway restarts outside active calls. No PBX configuration change is required by registration/authentication.

Set `CLIENT_REGISTRATION_ENABLED=false` to stop new registration. Existing client login and recovery remain available. Existing internal users and business records retain their ownership.

## Security and limits

- Codes are hashed, expire after 10 minutes and are tied to the initiating browser session. An account is not created before verification.
- Verification allows five failed attempts. Resending invalidates the previous code, preserves the failed-attempt count and original expiry, and requires a 60-second wait.
- Registration delivery is limited to three requests per identifier per hour, across browser sessions. Routes also limit requests per IP and action. Login has IP and normalized-identifier limits.
- Recovery tokens are random 64-character secrets stored as SHA-256 hashes. New recovery requests invalidate previous links. Expired/consumed links cannot change a password.
- Recovery responds consistently for missing, suspended and active identities. Delivery failures do not expose provider responses or credentials.
- Reset/password changes rotate remember tokens, invalidate database sessions and invalidate recovery links. Authenticated-session middleware checks password changes on subsequent requests for other session drivers as well.
- Suspension continues to deny login, recovery and client routes. Client account isolation and internal-only permission boundaries remain in force.
- New users have only the empty Client role. Signup cannot override ownership, roles, caller ID or trunk settings.
- Scheduled cleanup removes challenges more than a day past expiry. Keep the Laravel scheduler running.
- Phone-only users have a null email; a unique normalized `phone_e164` supports login. Rollback refuses while phone-only users exist, rather than silently deleting their accounts.

## Acceptance checks

Automated tests cover registration sequencing, code hashing/binding/expiry/replay, resend and guessing limits, phone normalization, role injection, password strength, suspended accounts, recovery expiry/reuse, session invalidation, settings authorization, delivery failure and TXTConnect acknowledgement handling. Existing isolation and broadcasting tests remain the regression baseline.

Before production onboarding, verify actual inbox/SMS delivery and recovery URLs on a controlled client account. The production media tasks in `reminder.md` remain separately deferred.
