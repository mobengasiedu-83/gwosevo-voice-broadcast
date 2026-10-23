# Contacts and contact groups

The dedicated screens follow the supplied Contact Groups dashboard while retaining the application's shared navigation and light/dark themes.

- `/contacts/groups`: real group/contact/import/suppression totals; group search, category/status/sort filters, pagination, create/edit, active/inactive status, member management and upload entry point.
- `/contacts`: normalized contact directory, name/phone/group/consent/suppression filters, creation and contact detail/history. Phone numbers are immutable on the edit screen so consent cannot silently transfer to a different number.
- `/contacts/imports`: owner-scoped upload history and batch progress; upload → column mapping → validation preview → explicit confirmation. A CSV template is downloadable from the upload panel.
- `/contacts/suppressions`: searchable global DNC records and scheduled/immediate exclusions. An immediate opt-out moves a future exclusion forward; later requests never postpone an existing exclusion.
- `/contacts/consents`: purpose-specific evidence, source, lawful basis, grant date and withdrawal status. Record/withdraw evidence on a contact's detail screen.

## Groups and campaign snapshots

Groups have owner, description, category and active status. Managers access their own groups/imports. Auditors with the existing `audit.view` permission can read groups across owners; writes still require owner and `contacts.manage`. The contact directory retains the existing application's shared `contacts.view` scope.

Adding/removing group members is idempotent and audited. Inactive groups cannot receive imports or be newly resolved as a campaign source. Existing approved campaign audience snapshots remain unchanged when group metadata, active status or membership changes. No groups/contacts are deleted by deactivation.

## Imports

Supported formats: UTF-8 CSV and Excel XLS/XLSX, up to 10 MB, 10,000 contact rows and 50 columns per file. The first worksheet is read. Valid nine-digit Ghana numbers have their missing national leading zero restored before E.164 normalization; formula cells are rejected. XLSX expanded content is bounded to 30 MB; external XML entities are rejected. Legacy XLS uses the pinned PhpSpreadsheet dependency without formula evaluation. See [PhpSpreadsheet reader documentation](https://phpspreadsheet.readthedocs.io/en/latest/topics/reading-files/).

Uploads are staged as private database rows; no contacts change before confirmation. Map the phone column and optional name/source columns. Preview normalizes Ghana numbers to E.164 and distinguishes valid rows, existing contacts, duplicates within the file and invalid/quarantined rows. Existing contacts are linked to the chosen group without overwriting their details. In-file duplicates and invalid rows are skipped. Error CSV downloads require batch ownership and escape spreadsheet formula prefixes.

Confirmed imports run in transactions of 250 rows on the default queue. Set `QUEUE_CONNECTION=redis` when using Horizon; a database connection instead requires a database queue worker. Progress refreshes automatically while queued/processing. Jobs time out after 45 seconds, below the worker/queue reservation timeout. Each row has a durable commit marker; repeated confirmation/job delivery and resumed batches cannot double-count or relink the same row twice. Progress counts **linked rows**, including existing contacts. Processing rechecks the actor's permission and group ownership/status on every chunk; revoked access or inactive groups block further work. Failed/blocked imports can resume remaining rows after the cause is corrected.

Importing never creates consent, removes suppression, enables dispatch or changes approved campaign audiences. Eligible campaign recipients still require purpose-specific consent and pass suppression checks immediately before calling.

## Deployment

Install locked dependencies with `composer install`, then run:

```sh
php artisan migrate --force
```

Keep a worker consuming the default queue (the existing Horizon/default supervisor does this):

```sh
php artisan queue:work --queue=default
```

Restart long-running workers through the deployment's service manager after code/dependency deployment. A queued batch indicates that a worker has not yet completed it; enqueuing alone does not claim success. No PBX access or call origination is required for these screens.

## Verification

 PHP formatting and Blade compilation checks passed. The populated groups dashboard was visually reviewed, and groups/directory/import pages were measured at 390px with no document overflow.

Feature tests cover group/directory access boundaries, creation/editing/memberships, filters, staging and mapping, deduplication/quarantine, explicit idempotent confirmation, bounded/resumable processing, immutable phone editing, consent and suppression views, immediate opt-out precedence, spreadsheet-formula rejection, escaped CSV exports and HTML escaping. Visual review uses an isolated SQLite preview and sample group, separate from the configured application database.
