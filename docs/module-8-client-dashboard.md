# Module 8 client dashboard

Client members now receive an account dashboard at the existing `/dashboard` landing route after sign-in, first-contact activation and registration. Internal users retain the operational dashboard. Reviewed against Module 08 of the supplied 25 October 2026 Installation and Development Guide and master specification.

The dashboard includes account name/status/membership role, account-wide campaign/contact/group/recording totals, first-use setup links, campaign and recording status summaries, recent campaigns with report links, recent imports, the signed-in user's recent safe activity events and account-security access.

Call results support date, timezone and account-owned campaign selection. Daily results include sent calls, completed answered calls, confirmed playback and answer rate; current in-progress and uncertain calls are labeled separately. Hourly counts have an accessible table. Results refresh through the filter form. JSON requests return the account-scoped panel and generation time with private/no-store caching, matching the existing dashboard response contract.

A dedicated client navigation shell applies to existing workspace screens. It links to account-scoped campaigns, contacts, imports, recordings, IVR, reports and security without internal administration, gateway health or telephony configuration. Existing backend permission, ownership, consent and launch checks still apply. Account members share account-wide dashboard totals. Personal activity never includes audit details or other members' sign-in contacts.

No billing, balance, payment or quota values are fabricated: these systems are not configured in this checkout. The review adds user display/read preferences through the migration described in [Modules 6–9 review](modules-6-9-review.md). Calling configuration is unchanged.

Validation covers empty and populated dashboards, shared membership visibility, foreign-account exclusion in HTML/JSON and campaign filters, date/timezone boundaries, inactive and revoked accounts, unassigned client logins, internal dashboard preservation and client navigation on existing screens.

Verification on 26 October 2026: the full suite passed with 179 tests and 1,058 assertions. The dashboard tests were rerun after adding the client live-call navigation and suppressing internal operational alerts. Blade compilation, Pint and whitespace checks passed.

The specification review adds a scoped notification bell/inbox with per-login read status, an explicit unconfigured credit card, recent completed call outcomes, editable account profile, unread-badge preference and responsive shell fixes. See [Modules 6–9 review](modules-6-9-review.md).
