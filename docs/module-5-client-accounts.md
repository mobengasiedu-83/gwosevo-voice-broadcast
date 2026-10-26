# Module 5 client accounts and isolation

Implemented against the supplied 25 October 2026 development guide and client/admin specification. This module adds the account boundary to the existing application. Registration/OTP are now implemented in [Module 6](module-6-registration-and-turnstile.md). The client administration UI is implemented in [Module 7](module-7-client-administration.md); the dedicated client dashboard is implemented in [Module 8](module-8-client-dashboard.md).

## Account model

Clients have a UUID, name and pending, active, suspended or closed status. Memberships associate a login with one account, an owner/member designation and active/revoked status. Both membership roles currently use the same limited Client Member permissions; additional business role differentiation can be added separately.

One login belongs to one client account. Its membership identity cannot be reassigned. Use separate logins for different accounts. Internal staff retain their existing role-controlled operational access. Existing unassigned records retain NULL client ownership and remain in the internal workspace. If the database contains an earlier client_accounts/client_account_id schema, the migration preserves its explicit accounts, memberships and ownership; it does not guess which customer owns unassigned data. Legacy tables and their additional metadata remain intact. The migration can resume after partially completed DDL.

Contacts, groups, imports, campaigns, templates, recordings, IVR flows and audit records carry immutable account ownership. Consents, campaign versions/runs/recipients, calls, events and responses inherit their visibility from their parent records. The same phone number can exist independently in different accounts. Internal contacts retain their own uniqueness boundary.

Client permissions have an enforced ceiling even if an internal role is accidentally assigned. Clients cannot approve recordings/campaigns, administer telephony, use global dashboard/audit privileges, manage accounts, or use the global emergency switch. Caller-ID input is removed from client forms and ignored server-side. Existing capacity limits still apply.

## Enforcement

- HTTP queries and route binding scope client-owned records; hidden records return 404.
- Campaign save and launch validate that all contacts, groups, recordings and IVR prompts belong to the same account, including gateway execution without an HTTP session.
- Suspended/pending/closed accounts and revoked memberships cannot use the workspace. Inactive accounts cannot launch or dispatch new calls, and PBX media delivery is denied. Reactivation does not itself launch a campaign.
- Imports capture their account when queued, restore worker context after execution, and recheck permissions, membership/account status and group ownership. Revoked work is blocked. Error exports use the same authorization as import detail.
- PBX media tokens remain per-call and snapshot-bound; the endpoint also checks current account status and matching recording ownership.
- Client suppression entries apply within that account. Internal global suppression remains authoritative for every account. Clients do not see another account's suppression details.
- Provisioning, membership changes and status changes record append-only audit events, with a mandatory reason for account status changes.

There is no customer API in this checkout. Future API routes must use the same authentication/tenancy rules; raw query-builder SQL does not automatically receive Eloquent account scopes.

## Installation

Run in the application directory after deploying the reviewed code, using the appropriate PHP binary:

```sh
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan optimize:clear
```

Restart the existing gateway and Horizon through your established deployment process so they load the updated code. The migration was exercised in isolated test databases and applied successfully to the local MariaDB database, preserving its pre-existing account and membership. It does not provision sample customer accounts or alter live calling configuration. Production deployment remains separate. Its rollback deliberately refuses automatic execution because merging per-account duplicate phone numbers into a global unique column requires a reviewed data migration.

## Command-line provisioning

Use an authorized internal administrator's actual user ID for `--actor`. The command checks `clients.manage`; supplying an ID does not bypass authorization.

```sh
php artisan clients:manage create --actor=ADMIN_ID --name="Customer name"
php artisan clients:manage list --actor=ADMIN_ID
php artisan clients:manage status --actor=ADMIN_ID --client=CLIENT_ID --status=active --reason="Account onboarding completed"
```

Create a separate client login with an interactively entered password:

```sh
php artisan foundation:user client@example.com --name="Client user" --role="Client Member"
php artisan clients:manage member --actor=ADMIN_ID --client=CLIENT_ID --user=USER_ID --role=owner
```

An unassigned Client Member cannot access the workspace. Use the new user's actual database ID for `USER_ID`. Provisioning refuses to convert an internal privileged identity or a user with existing business records into a client member.

```sh
php artisan clients:manage status --actor=ADMIN_ID --client=CLIENT_ID --status=suspended --reason="Account review"
php artisan clients:manage status --actor=ADMIN_ID --client=CLIENT_ID --status=active --reason="Review completed"
php artisan clients:manage member --actor=ADMIN_ID --client=CLIENT_ID --user=USER_ID --status=revoked
```

Client logins currently use the existing sign-in and account-scoped operational screens. Public signup and OTP are documented in Module 6; the Clients administration screen is documented in Module 7.

## Validation

Validation on 25 October 2026: 141 tests passed with 751 assertions, including 20 ClientIsolationTest cases. Blade compilation, Pint checks and diff whitespace checks also passed.

The dedicated ClientIsolationTest covers account visibility, shared membership, forged foreign references, reports/dashboard JSON, media downloads, PBX capabilities, import/error-export authorization, queued account context, revocation/suspension, privilege escalation prevention, immutable ownership and audited provisioning. Existing internal workflow tests remain regression coverage. No live PBX call or production database change is required by these tests.
