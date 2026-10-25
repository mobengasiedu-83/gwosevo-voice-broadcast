# Module 6 registration and authentication

The supplied specification requires registration by email or phone, verification before password setup, future sign-in using a verified identifier, independent recovery/contact-change verification, abuse limits and suspension enforcement. These flows now use the Module 5 client account boundary.

## Available screens

- `/register`: name, account name, country, broadcasting purpose, registration notice acceptance and the selected email/SMS identifier.
- `/verify-contact`: six-digit OTP verification and controlled resend.
- `/set-password`: password setup after successful verification, with matching confirmation and at least 12 characters including uppercase, lowercase, numbers and symbols.
- `/login`: verified email or phone and password. Ordinary sign-in does not send an OTP.
- `/forgot-password`: independent OTP recovery using an eligible account contact.
- `/account/security`: accessible from the client account menu. Requires the current password and a fresh OTP to add or replace a contact. The other contact's verification status is unchanged.
- `/registration-notice`: describes data collected, verification, account boundaries and the accepted notice version. It is an onboarding notice; the operator's full service terms and retention policy remain separately maintained.

An administrator-provisioned client with no verified contact completes a one-time verification after entering the correct existing password. This preserves Module 5 provisioning without treating an unverified optional secondary contact as a valid sign-in/recovery identifier.

## Verification controls

OTP hashes, encrypted identifiers and encrypted registration details are stored in a dedicated `identity_challenges` table. Codes are random, six digits, short-lived, single-use and tied to a secret in the initiating browser session. Row locks protect attempt counting and consumption. Password setup is rejected until the contact is verified; code verification alone does not create an account.

Defaults: 10-minute expiry, five incorrect attempts per challenge, a 60-second resend cooldown, three sends per identifier per 15 minutes and ten sends per IP per hour. A shared cache lock serializes requests for the same identifier. Route throttles and an additional identifier-based login limiter also apply. An unknown recovery contact or duplicate registration receives the same generic eligibility response without sending a code.

Registration verifies only the selected contact. An account and owner membership are created atomically after verification and password setup. New users receive only Client Member permissions. Optional internal activation can keep the account pending. Recovery does not reactivate suspended accounts. Password recovery and contact replacement rotate the remember token and authentication version, invalidating old sessions. Recovery returns to sign-in rather than automatically authenticating.

Registration, first contact verification, sign-in, recovery and contact changes are recorded in the existing audit trail. No password, raw code or provider credential is included in audit details. Codes, passwords and Turnstile tokens are excluded from flashed validation input. Delivery errors invalidate the challenge and display a generic error.

Expired challenge records are deleted by the daily `identity:prune` task after the configured retention period. Existing older `client_auth_challenges` tables are not used or deleted by this implementation.

## Existing email and TxtConnect configuration

The implementation reuses the existing `MAIL_*`, `CLIENT_SMS_DRIVER` and `TXTCONNECT_*` settings. No production credentials were replaced and no real verification message was sent during automated testing.

```dotenv
CLIENT_REGISTRATION_ENABLED=true
CLIENT_REGISTRATION_AUTO_ACTIVATE=true
CLIENT_OTP_MINUTES=10
CLIENT_OTP_MAX_ATTEMPTS=5
CLIENT_CHALLENGE_RETENTION_DAYS=7
CLIENT_TERMS_VERSION=2026-10-25

CLIENT_SMS_DRIVER=txtconnect
TXTCONNECT_API_URL=https://api.txtconnect.net/dev/api/sms/send
TXTCONNECT_API_TOKEN=YOUR_EXISTING_TOKEN
TXTCONNECT_SENDER_ID=YOUR_APPROVED_SENDER
TXTCONNECT_UNICODE=0
MAIL_TIMEOUT=10
```

Email uses the configured Laravel mailer. Direct log/array mail delivery is refused in production. Set `CLIENT_REGISTRATION_AUTO_ACTIVATE=false` when new accounts should wait for internal activation. Set `CLIENT_REGISTRATION_ENABLED=false` to disable public signup; recovery and existing sign-in remain available.

The TxtConnect adapter follows the provider's [POST API documentation](https://txtconnect.net/ApiDocumentation): bearer authentication, `to`, `from`, `unicode` and `sms` fields, and successful provider status `000` with `in_error=false`. Provider acceptance is not treated as contact verification; the recipient must enter the code.

## Cloudflare Turnstile setup

Turnstile is implemented on login, registration, recovery, code verification/resend, password setup and contact-change submissions. Each submission is validated against Cloudflare Siteverify on the server, including the expected action and an allowed hostname. Network failures, missing keys, invalid responses and used tokens fail closed when enabled. Successful tokens also receive a short-lived local replay marker in the shared cache.

1. Create a Turnstile widget for your development/production hostname in Cloudflare.
2. Add the public site key and private secret key to `.env`; only the site key appears in page markup.
3. Set allowed hostnames without a scheme, path or port. Separate multiple hostnames with commas. If blank, the hostname from `APP_URL` is used.
4. Set `TURNSTILE_ENABLED=true` and refresh configuration.

```dotenv
TURNSTILE_ENABLED=true
TURNSTILE_SITE_KEY=YOUR_PUBLIC_SITE_KEY
TURNSTILE_SECRET_KEY=YOUR_PRIVATE_SECRET_KEY
TURNSTILE_HOSTNAMES=voice.example.com
TURNSTILE_TIMEOUT=8
```

`.env` and `.env.example` contain these settings. Turnstile remains disabled until real widget keys are supplied; enabling it with missing keys blocks protected submissions. Never put the secret key in Blade, JavaScript or source control.

Cloudflare documents the mandatory server validation, five-minute token lifetime and single-use behavior in its [validation guide](https://developers.cloudflare.com/turnstile/get-started/server-side-validation/). Its [testing guide](https://developers.cloudflare.com/turnstile/troubleshooting/testing/) provides development-only test keys. Tests in this repository fake provider responses and do not contact Cloudflare.

## Deployment and checks

```sh
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan optimize:clear
php artisan view:cache
```

Refresh cached configuration after changing `.env` using the established deployment process. Preserve the original `APP_KEY`: it decrypts pending verification challenges as well as existing encrypted data. Restart long-running application processes when deploying new code. The migration preserves existing users and phone verification fields and supports databases where earlier identity columns already exist.

Acceptance coverage includes email/SMS registration, password gating, OTP expiry/replay/attempts/resend, browser-session binding, duplicate identifiers, provider rejection, recovery, account suspension, first-sign-in verification, authenticated contact changes and Turnstile success/replay/action/hostname/network-error checks. All delivery tests use fake providers. A final live delivery check should use an account and recipient chosen by the operator.

## Validation result

On 25 October 2026, all 173 tests passed with 965 assertions, including 32 new identity and Turnstile tests. Blade compilation, Pint checks and diff whitespace checks passed. The local MariaDB migration completed successfully. SMTP and TxtConnect configuration presence was checked without exposing credentials; live delivery and a real Cloudflare widget were not exercised. Turnstile is currently disabled because its keys have not been supplied. Production was not changed.
