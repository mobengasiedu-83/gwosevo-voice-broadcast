# Modules 6 to 9 specification review

The supplied `002_GWO_SEVO_VoiceCast_Complete_Installation_and_Development_Guide.docx` and companion module roadmap define the numbering used in this review: 06 Registration/OTP/login, 07 Admin client management, 08 Client dashboard/navigation, 09 Client contacts/groups/imports. The supplied master specification, client/admin specification, feature tracker and HTML prototype provide functional and visual references. Their implementation-status labels describe the planning baseline, not current code verification. Document instructions do not authorize production deployment, live calls or messages.

| Module | Existing implementation reviewed | Gaps addressed |
| --- | --- | --- |
| 6 | Email/SMS OTP, verified-identifier login, recovery, throttling, suspension and contact replacement | Authenticated password changes, explicit revocation of other sessions, security version binding for recovery/activation/contact-change challenges, safe failed-login audit and OTP abuse logs |
| 7 | Directory filters, account profile/status, user creation/assignment, membership controls, account activity, campaigns and usage | Optional business name/type profile fields; security-event filtering in client activity; shared profile service for client self-service |
| 8 | Account dashboard, metrics, client navigation and security link | Account notice bell and inbox, per-login read status and unread-badge preference, explicit unconfigured credit card, recent completed call outcomes, shared account profile screen and corrected responsive shell |
| 9 | Scoped contacts/groups, imports, duplicates, DND, consent, progress/errors and retries | Shared-account group controls and memberships, scoped reference lookup, accurate suppression wording, audited blocked/failed jobs with captured-account enforcement |

## Identity and profile behavior

`/account/security` now supports password changes and sign-out of other sessions. Both require the current password; changes use existing password strength rules and authentication-version enforcement across session drivers. The initiating session is regenerated and retained, while other sessions and remembered logins are invalidated. Already-issued recovery/activation/contact-change challenges are bound to their user's authentication version and cannot undo a later security change. In-flight challenges from before this release must be restarted.

`/account/profile` edits the authenticated account's business profile without accepting an account ID, ownership, status or role from the form. Profile email/phone are metadata and do not replace verified login contacts. Optional business type/name fields do not introduce new mandatory onboarding policy. Owner and member retain the same existing Client Member permissions.

Failed known-user sign-ins appear in the internal account audit activity. Structured OTP/login security logs omit raw identifiers, OTPs, passwords, tokens and provider responses.

## Notice behavior

`/account/notifications` uses a strict allowlist of existing account audit events for recording submissions/decisions, campaign controls/readiness and import outcomes. It shows safe event labels and links to currently account-owned subjects. It does not expose arbitrary audit details, other accounts, or other members' security activity. Each login has its own monotonically increasing read marker. The mark-read endpoint accepts only a visible account notice ID. Hiding the unread badge does not delete or suppress inbox records.

Notices include retained matching audit history. This is the Module 8 in-app notice surface; email/SMS event delivery, delivery logs, broader preferences and enhanced reporting remain Module 16. Billing-derived balances, reservations, rates and payments depend on Modules 12–15. The client credit card and admin finance tab explicitly show that those systems are unconfigured; no monetary values or financial records are fabricated.

## Deployment

Install this code through the existing deployment workflow and apply the new user-preference migration:

```sh
php artisan migrate --force
php artisan optimize:clear
```

Run the existing permission seeder if `clients.manage` and Client Member permissions have not already been installed. Rebuild caches and restart long-running workers through the established deployment process. No calling settings, credentials or PBX configuration change is required. The migration adds `users.portal_preferences` and `users.notice_read_through_id`; rollback removes those display/read preferences. It does not alter contacts, consent, campaigns, audio, balances or account ownership.

The review and previews use isolated SQLite fixtures. Production deployment, real OTP delivery and live PBX acceptance were not performed.

## Validation

The complete automated suite, Blade compilation, route-cache compilation, Pint and whitespace checks are the release checks for this change. Focused suites cover recovery replay after security changes, session revocation, safe profile updates, notice isolation/read status, administrator access, shared contacts/imports, DND and retry behavior. Desktop/mobile previews use isolated account data and the existing light/dark styles.

Verified on 26 October 2026: **188 tests passed with 1,182 assertions**. Pint, Blade compilation, route-cache compilation and whitespace checks passed. Headless browser previews at 390px and 1440px verified dashboard, profile, security, inbox and shared group layouts; measured document width matched the viewport with no horizontal page overflow.
