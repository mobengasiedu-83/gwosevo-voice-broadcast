# Step 02 — Client accounts and data isolation

This stage adds the client account foundation to the existing Laravel application. It does not introduce public registration, OTP, phone login, a client campaign portal, wallet/billing or payments. Those remain later steps.

## Delivered

- Client organizations with UUID, name, Active/Suspended status, creation attribution and suspension reason/date.
- One account per client user; an account can have multiple users.
- Internal account creation/listing and Suspend/Reactivate controls at /client-accounts (Administration in the sidebar).
- clients.manage permission; Super Administrator receives it. Existing operational roles do not gain account management by default.
- A Client role with no internal operational permissions. Attaching internal roles or direct permissions to an account user cannot turn that user into an internal administrator.
- Authenticated clients land on a minimal /client account-status page. All internal screens, actions, media downloads and Horizon remain blocked for clients; client operational routes will be introduced explicitly in later stages.
- Suspension revokes database sessions and remember tokens, denies login and subsequent requests, pauses running/scheduled campaigns, blocks import chunks and prevents new/unsent call origination. Calls already in progress may finish. Reactivation never resumes campaigns automatically.
- Account attribution on contacts, groups, imports, audio, campaigns, templates, IVR flows and audit records.
- Account query scopes on those models and parent-derived scopes for campaign runs/versions/recipients, attempts/events, IVR responses and consent records.
- Cross-account group membership, campaign groups, recordings, audiences and IVR assets cannot be combined.
- Account-scoped contact deduplication. The same normalized phone number can exist independently in different accounts; existing internal contacts retain an internal namespace.
- Explicit account context for tenant-aware background work with restoration in a finally block to avoid context leakage between jobs.
- Suspended import batches become Blocked. Every processing chunk rechecks permissions, group/account matching and active status under the admission mutex.
- Existing records remain internal with null client_account_id; no users or business data are automatically reassigned.
- Ownership of an existing business record cannot be changed through Eloquent model updates. Existing client users cannot be moved between accounts through model updates.
- Audited account creation, status changes and console user provisioning.

## Provisioning

After deploying code, back up the database and apply the additive schema:

    cd /var/www/gwosevo-voice-broadcast
    sudo -u gwosevo php artisan migrate --force
    sudo -u gwosevo php artisan db:seed --class=RolesAndPermissionsSeeder --force
    sudo -u gwosevo php artisan optimize
    sudo -u gwosevo php artisan horizon:terminate
    sudo systemctl restart gwosevo-ami-gateway

Use a maintenance window with no active calls for the gateway restart. The scheduler, workers and web application must all use the same deployed schema. Do not run the new code against an old schema.

Sign in as Super Administrator, open Administration, and create a client account. Note its numeric ID, then provision a NEW client user interactively:

    sudo -u gwosevo php artisan client:user ACCOUNT_ID client@example.com --name="Client user"

The command requires an active account and a unique email. The password must have at least 12 characters, mixed case, a number and a symbol, and is entered without echo. It does not print or store a plaintext password. It does not migrate an existing internal user or grant internal campaign permissions.

The existing foundation:user command remains for internal accounts. Do not use it to provision a Client without an account relationship.

## Access boundaries

Account users are a separate identity class, even if they accidentally receive a staff role. They cannot approve audio, select a trunk, override caller ID, use internal contact/campaign actions or access internal reports. The temporary client landing page shows account status and sign-out only.

Internal review and operational access keeps the existing permission/ownership rules. Super Administrators retain cross-owner administration. Suspension does not delete records or rewrite immutable launch history.

DND/suppression remains a global operational exclusion, not a client-owned duplicate list. The shared carrier connection and verified capacities also remain internal operational resources.

Raw DB queries do not inherit Eloquent model scopes. Current raw operational screens are protected by the internal-only client boundary, and import processing checks its account explicitly. Any future client controller, export, job or API using raw queries must enforce the account boundary as well. Do not remove that boundary merely to expose internal pages as a client portal.

## Future client operational stages

The Client role intentionally has no operational grants in this stage. Stages 3–7 add registration/OTP/password workflows, client navigation and explicitly scoped operational actions. Reuse shared services only with account context and record-policy enforcement; do not grant internal broad permissions to make existing pages accessible.

OTP remains registration-only verification of either email or phone. Password creation and subsequent email-or-phone login are not built by Step 02.

Client billing, payments, user-specific notifications and public APIs remain unimplemented. The production tasks in reminder.md remain deferred.

## Migration and rollback

The schema retains existing null-account internal records. It replaces the global contacts phone uniqueness constraint with account_scope plus phone_e164. Never bulk-assign existing shared contacts or users to a client without a reviewed ownership/data migration.

Rollback deliberately refuses if different accounts now contain the same phone number: reinstating global uniqueness would otherwise lose or merge data. Back up and reconcile those records before attempting rollback. Account foreign keys restrict deletion while records/users still reference the account.

## Verification

ClientFoundationTest covers account management permission checks, audit, query and descendant isolation, identical phone numbers in different accounts, denial of internal screens and media downloads, accidental role escalation, suspension/login/session behavior, no automatic campaign resume, job context cleanup and cross-account membership/ownership rejection.

Existing contact, import, approval, dashboard and broadcast tests remain the regression baseline. No real client account, production call or external PBX change is created by automated tests.

### Client Management navigation

The internal sidebar exposes **Client Management** at `/client-accounts` for users with `clients.manage`. The `Administrator` and `Super Administrator` roles receive this permission. Apply `php artisan db:seed --class=ClientManagementPermissionsSeeder --force` on existing deployments; it adds the grants without replacing other role permissions. It does not assign roles to users. Client users remain excluded from internal account administration.
