# Module 7 client administration

The Clients workspace is available at `/clients` to internal users with `clients.manage`. Client identities cannot acquire that permission, even through an accidentally assigned internal role.

The existing administration controller and account services were present but had no routes or views. This update connects them to the portal and adds:

- Search by account name, UUID or user name/email/phone; status and creation-date filters, sorting, pagination and status totals.
- Pending account creation, editable business/contact profile and reason-required account status changes.
- Overview counts and call usage, account users and verification indicators, campaigns, import history and filtered audit activity.
- Client user creation with strong confirmed passwords and first-sign-in contact verification; assignment of eligible existing email logins; reason-required membership role/access changes.
- Permission checks before account lookup, account-bound membership lookup, audit records and private non-cacheable account pages.

Owner and member continue to share the existing Client Member permission ceiling. Profile contact fields do not change verified sign-in contacts. Account suspension prevents access and new dispatch through existing enforcement; reactivation does not launch campaigns. Creation sends no notification: administrators must share temporary credentials securely.

Finance explicitly reports that billing is not configured. No billing ledger or payment integration exists in this checkout. The dedicated client dashboard is implemented in [Module 8](module-8-client-dashboard.md).

Deploy through the existing application deployment process. No new schema migration or telephony configuration change is required. Seed existing permissions if they have not yet been installed.

Validation: the full `php artisan test` suite passed (176 tests); Blade compilation, Pint and diff whitespace checks passed. ClientAdminTest covers all account sections, filters, profile/status updates, user creation, membership isolation, privilege escalation rejection and unauthorized administration requests. Tests use isolated SQLite and do not place calls.

The supplied-specification review adds optional business name/type fields and includes failed sign-ins, lockouts, password changes and explicit session revocations in the login/security activity filter. Finance remains dependent on the later billing modules. See [Modules 6–9 review](modules-6-9-review.md).
