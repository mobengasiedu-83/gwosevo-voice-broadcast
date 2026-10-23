# Phase 2 foundation

The foundation adds a session-authenticated Blade workspace at `/workspace`, seven permission roles, Ghana contact normalization and deduplication, contact groups, consent evidence and withdrawal, global suppression, draft campaigns, separate-user approval, version snapshots and transactional audit records.

## Setup and access

Migrations are additive; existing placeholder records are retained with nullable new identity fields. New application-created contacts and campaigns receive UUIDs. Legacy placeholder records require real inventory data before use.

```sh
php artisan migrate
php artisan db:seed --class=RolesAndPermissionsSeeder
php artisan foundation:user admin@example.com --name="Administrator" --role="Super Administrator"
php artisan foundation:user approver@example.com --name="Approver" --role="Approver"
```

User provisioning asks for a password interactively (minimum 12 characters). No default account or password is seeded. Open `/login`, then `/workspace`. Create a campaign as its owner, submit a reason for review, and sign in as a different approver to approve or reject it. Rejection returns the campaign to draft for editing. Pending and approved campaigns cannot be edited through the service. The role seeder is repeatable and synchronizes the documented permission sets.

## Permissions and scope

Campaign Managers manage shared contacts and their own drafts. Approvers review campaigns across the organization and cannot review their own. Super Administrators have foundation permissions but still cannot self-approve. Analysts, Operators and Auditors can view organization campaigns. Auditors can also read contacts/consent and audit history. Telephony Administrators have a reserved telephony permission; telephony views belong to a later phase. Operational and reporting permissions reserve future capabilities and do not enable dispatch.

All mutations enforce permissions on the server. Campaign ownership and review state are checked again inside a transaction with a row lock. Consent recording, withdrawal, suppression, group assignment and campaign changes append audit records in the same transaction. Audit records avoid copying phone numbers or consent evidence into audit details.

Approved version snapshots and audit entries reject Eloquent instance edits/deletes. This is application-level protection: direct SQL and bulk query operations can bypass model events. Production immutability requires restricted database credentials and separately controlled append-only archival/backup storage. Keep database administrative access outside ordinary application use.

## Boundaries

Approval in this phase approves the foundation campaign definition only. It does not authorize dialing. The Phase 3 engine must capture approved audio checksum, target-list snapshot, caller ID, trunk, rate limits and retry policy before launch, then recheck consent and suppression immediately before origination. Phase 2 itself provides no originate endpoint. Phase 3 now adds guarded start controls and the gateway; see [Phase 3](phase-3-one-way-engine.md) for deployment and current verification status.

CSV/XLSX imports, audio workflows, live IVR, scheduling, dispatch, reconciliation, reports, MFA and password recovery remain later work. Users are provisioned through the local console; there is no public registration or role-assignment endpoint. Contact consent timestamps use the application timezone (UTC by default); the audit table displays UTC. Consent evidence references must point to controlled storage.

## Verification

Run `php artisan test`. The suite covers sign-in, guest protection, contact normalization and duplicates, denied writes, consent withdrawal, suppression, group membership, draft editing, owner checks, self-approval rejection, review transitions, repeat review rejection, version immutability, audit deletion protection, workspace rendering and repeatable role seeding.

On 22 October 2026, all 17 tests passed with 67 assertions using isolated SQLite. The configured local MariaDB schema was migrated and the seven roles seeded separately. No outbound calls were made.
