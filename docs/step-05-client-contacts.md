# Step 05 — Client contacts, groups and imports

## Delivered workflow

Sign in as a client, then use **Contact groups → Create a group → Lists & imports**. Upload a CSV/XLS/XLSX file, map the phone/name/source columns, inspect the preview and confirm. Alternatively, add a contact directly from Contacts or an active group's page.

- Account-scoped contact directory with name/phone search, group and DND filters, contact details and group memberships.
- Manual contact creation with Ghana phone normalization, optional immediate group membership and account-level duplicate reuse. Reusing a number does not overwrite an existing contact's name/source.
- Editable names/sources. Phone numbers remain fixed to preserve history; use a new contact for a new number.
- Group creation/editing, categories, descriptions, active/inactive status and member lists. Add/remove memberships from the contact page; create a new contact directly inside a group.
- Shared account ownership: active client users can manage contacts/groups/imports within their organization, including those created by colleagues. This does not grant internal `contacts.manage` permission or access to staff screens.
- Existing safe CSV/XLS/XLSX reader and staged queue engine reused through explicit client/account checks. Uploads require an active group in the same account.
- Limits remain 10 MB, 10,000 contact rows and 50 columns. The existing reader rejects formulas and malformed/unsupported workbooks.
- Missing national zero restored for valid nine-digit Ghana numbers imported from Excel; all numbers normalize to E.164.
- Preview, column mapping, within-upload duplicate quarantine, existing account contact reuse, invalid-row quarantine and escaped CSV error downloads.
- Explicit confirmation, idempotent chunk processing, progress polling, history and resuming failed/blocked imports. A repeated confirmation does not import rows twice. Imported contacts do not grant consent or bypass DND.
- DND flags shown only for contacts in the current account. A client may record an opt-out for one of its own contacts. Suppression remains global and effective at dispatch, including when the same phone exists in another account. Other clients' reasons, actors and suppression entries are never listed.
- Clients cannot remove DND entries through these screens; request internal review. No new global suppression administration permission is granted.
- Business changes are audited; imported data, snapshots and existing history are preserved. Inactive groups reject new membership/import additions. Group changes do not rewrite existing campaign audience snapshots.
- Cross-account group/contact/import IDs fail access checks, including mapping, confirmation, progress and error-download endpoints. Suspension blocks client requests and new import processing chunks.

## Routes

- `/client/contacts` — directory and manual creation.
- `/client/contacts/{contact}` — details, groups and DND.
- `/client/groups` and `/client/groups/{group}` — groups and memberships.
- `/client/imports` — upload and history.
- `/client/imports/{batch}` — map, preview, confirm/resume and progress.
- `/client/imports/template` — CSV template.

No schema or role-seeder change is required for Step 05. Client operations are authorized explicitly by active account membership, independently of internal operational permissions.

## Deployment and workers

Deploy the controller, shared import service changes, route/middleware/provider updates and client templates. Clear/rebuild caches and restart queue workers so imports use the new account-aware authorization:

```bash
cd /var/www/gwosevo-voice-broadcast
sudo -u gwosevo php artisan optimize
sudo -u gwosevo php artisan horizon:terminate
```

With Horizon managed by systemd, verify it restarts and consumes the **default** queue. If using a standard queue worker instead, use `php artisan queue:restart` and ensure the process manager restarts it. Configure only the worker system used by your deployment.

Upload/mapping/preview are synchronous. Confirmed imports use the existing queue; a queued batch needs a running default worker. Restore a failed queue connection and use Resume for failed/blocked batches once their account/group is active. This step does not change the PBX or originate calls.

## Verification

Tests cover account boundaries for directories/groups/memberships/imports, tenant-specific deduplication, manual creation, immutable phone updates, inactive group protection, shared-account collaboration, CSV mapping/confirmation/idempotence, Excel numeric-prefix restoration, download access, DND privacy and suspension blocking. Existing parser tests retain XLS/XLSX/formula safety coverage, and the internal contact/import suite remains the regression baseline.

Client audio upload/preview is now available in [Step 06](step-06-client-audio.md). Client campaign controls remain Step 07.
