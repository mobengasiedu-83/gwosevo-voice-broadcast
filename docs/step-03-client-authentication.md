# Step 03 — Client registration and authentication

## Implemented flow

1. Open **Sign in → Create a client account** (`/register`). Enter full name, organization/account name, email address, mobile number and country, accept the Terms and Privacy Policy, then choose email OTP or SMS OTP. Both contacts are required; Ghana is currently the supported country.
2. VoiceCast sends a six-digit registration code by the matching channel: email through Laravel mail, or SMS through TXTConnect. Only the selected contact is verified initially. The other is saved with its own unverified status.
3. Enter the code, then create and confirm a password of 12–128 characters containing uppercase/lowercase letters, a number and a symbol.
4. Only after successful verification and password creation does VoiceCast create an active client account and its first Client user, in one transaction. No internal role can be selected or submitted through this flow.
5. Subsequent sign-in uses either saved email or normalized phone number **and password**. OTP is not part of routine login. Existing internal email/password login remains supported.
6. **Forgot password** sends a single-use, 30-minute recovery link to the matching verified contact on an active client account. Recovery does not sign the user in. After resetting, sign in using the new password.
7. **Client account → Account settings** allows name/password changes after current-password confirmation. Clients can verify the second contact or replace a contact through a session-bound OTP after confirming their current password. The old contact remains active until the replacement is verified. Account membership remains fixed.

Clients land on the restricted client workspace introduced in [Step 04](step-04-client-portal.md). Operational client modules follow their own permissions and execution gates. Public registration does not grant access to internal administration or approve audio.

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

- Codes are hashed, expire after five minutes and are tied to the initiating browser session. An account is not created before verification.
- Verification allows five failed attempts. Resending invalidates the previous code, preserves the failed-attempt count and grants the new code a five-minute expiry, and requires a 60-second wait.
- Registration delivery is limited to three requests per identifier per hour, across browser sessions. Routes also limit requests per IP and action. Login has IP and normalized-identifier limits.
- Recovery tokens are random 64-character secrets stored as SHA-256 hashes. New recovery requests invalidate previous links. Expired/consumed links cannot change a password.
- Recovery responds consistently for missing, suspended and active identities. Delivery failures do not expose provider responses or credentials.
- Reset/password changes rotate remember tokens, invalidate database sessions and invalidate recovery links. Authenticated-session middleware checks password changes on subsequent requests for other session drivers as well.
- Suspension continues to deny login, recovery and client routes. Client account isolation and internal-only permission boundaries remain in force.
- New users have only the empty Client role. Signup cannot override ownership, roles, caller ID or trunk settings.
- Scheduled cleanup removes challenges more than a day past expiry. Keep the Laravel scheduler running.
- Legacy phone-only users retain a null email; new public registrations require both contacts; a unique normalized `phone_e164` supports login. Rollback refuses while phone-only users exist, rather than silently deleting their accounts.

## Acceptance checks

Automated tests cover registration sequencing, code hashing/binding/expiry/replay, resend and guessing limits, phone normalization, role injection, password strength, suspended accounts, recovery expiry/reuse, session invalidation, settings authorization, delivery failure and TXTConnect acknowledgement handling. Existing isolation and broadcasting tests remain the regression baseline.

Before production onboarding, verify actual inbox/SMS delivery and recovery URLs on a controlled client account. The production media tasks in `reminder.md` remain separately deferred.

### OTP database history

`client_otp_logs` retains one metadata record per successfully dispatched registration OTP, including challenge ID, channel, masked recipient hint, requested/sent/expiry times, verification attempts, verified/consumed times and resend supersession. `sent` means transport/provider acceptance, not handset receipt. Failed delivery rolls back issuance and is diagnosed in the email/SMS operational logs. Expiry is determined by `expires_at`; the stored lifecycle status is not an indication that a code remains valid.

Registration precedes account creation, so `client_account_id` and `user_id` initially remain null. Successful password/account creation links all records for that challenge to the new account and user. History survives authentication-challenge pruning. Records contain no raw OTP or session secret; live verification continues to use the challenge's hashed code and existing expiry/attempt limits. This history starts with deployment and does not reconstruct previously sent OTPs. Apply `2026_10_24_060000_add_client_otp_logs` before deploying the registration service changes.


## Dual-contact signup and contact changes

Apply `2026_10_24_070000_add_registration_contact_details`. New users retain `country`, `terms_accepted_at` and `terms_version`. `CLIENT_TERMS_VERSION` identifies the combined Terms/Privacy policy version. The public `/AcceptTerms&PrivacyPolicy` page contains owner-requested dummy content, visibly marked as a development placeholder. Replace it with approved policy text before public signup. Optional `CLIENT_TERMS_URL` and `CLIENT_PRIVACY_URL` override the signup links. Existing users are not assigned fictional contacts or backfilled consent; pending old single-contact registrations must restart.

Both email and normalized phone are checked for uniqueness at issuance and again before account creation; database unique constraints remain authoritative during races. Password-only login accepts either saved contact when at least one is verified. Recovery and optional outbound alerts still require the specific destination to be verified. Dashboard and settings show email/mobile verification independently. Requiring both before paid campaigns remains a separate, unenabled recommendation; recording approval is unchanged. Optional company registration details, addresses and staff invitations remain subsequent account-management work.

Authenticated contact verification uses hashed, session-bound five-minute codes, five attempts, a 60-second cooldown and three requests per user per hour. Contact history is recorded in `client_otp_logs` with client/user IDs. Replacement rechecks uniqueness, invalidates prior recovery links and database sessions, rotates the remember token, and disables optional alerts on the replaced channel until the user opts in again. Email/SMS acceptance means successful submission; the SMSC acknowledgement is the final available SMS provider report, with no final telco DLR expected.
