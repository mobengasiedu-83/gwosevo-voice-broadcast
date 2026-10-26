# Module 9 client contacts groups and imports

Reviewed against the supplied 25 October 2026 Installation and Development Guide (18-module sequence), Master Application Specification and Client/Admin Features and Permissions Tracker. Module 9 is contacts/groups/imports; payment top-ups are Module 14 in this sequence.

The existing contact workspace and account services provide scoped contact creation, Ghana phone normalization, search, detail and name/source editing; group creation/renaming/deactivation and membership changes; consent evidence/withdrawal; DND; and CSV/XLS/XLSX upload, mapping, validation, duplicate handling, queued processing, progress, retry and quarantine exports. The required contact operations are add, search, view and update. Phone editing remains restricted to preserve consent history; group deactivation preserves historical audience snapshots.

This review fixes shared-account UI controls: colleagues can now see their account's contact group memberships and access the group settings, add/remove controls and upload form already permitted by the server. Suppression labels distinguish an account exclusion from an internal global exclusion. Foreign and missing contact/group references both use scoped lookup instead of global existence validation.

Blocked imports and exhausted job failures now create safe account audit events shown in the client inbox. A failed job verifies its captured account before changing a batch. Repeated processing/failure callbacks do not duplicate completed imports or terminal notices. Raw worker exceptions are not exposed in the inbox.

## Verification

ClientContactsModuleTest covers colleague group management, foreign-account denial, upload/mapping/preview/confirmation, duplicates and invalid rows, idempotent processing, preserved existing contact data, unchanged consent and suppression, DND launch exclusion, protected error exports, inactive-group blocking and captured-account job failures. Existing ContactsWorkspaceTest covers CSV/XLS/XLSX parsing, formula rejection, quarantine CSV escaping, bounded/resumable processing and internal authorization. ClientIsolationTest covers revoked memberships, suspended accounts and queued account context.

See [Modules 6–9 review](modules-6-9-review.md) for deployment and combined verification.
