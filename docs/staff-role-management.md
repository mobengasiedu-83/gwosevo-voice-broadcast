# Internal staff and role management

Super Administrators can open Administration → Role management to create custom staff roles and edit the permission sets of existing staff roles. Permissions are grouped by application area. Custom roles appear in the Internal staff creation and role-assignment selectors.

Client Member is excluded from staff role management. Super Administrator is protected because its access is granted by the platform authorization gate. Built-in role names remain fixed, but their permissions can be edited. Only unused custom roles can be deleted. A role with no permissions cannot open permission-protected workspace pages.

Staff creation and assignment require users.manage; role management itself requires Super Administrator. Grant users.manage only to trusted staff because it allows provisioning staff and assigning other internal roles. Non-super administrators cannot create or modify Super Administrators.

Role changes are audited and increment affected users' auth_version, requiring them to sign in again. Passwords, tokens and secrets are excluded from audit details. The last internal Super Administrator cannot be demoted through staff management.

The roles seeder creates missing built-in roles and permissions but preserves existing role permission sets. Re-running it does not overwrite changes made on the Role management screen. After deployment, run the normal migrations and clear application caches as described in the deployment guide; these screens introduce no new database tables.
