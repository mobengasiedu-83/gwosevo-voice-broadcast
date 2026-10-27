# Internal staff and role management

Super Administrators can open Administration → Role management to create custom staff roles and edit the permission sets of existing staff roles. Permissions are grouped by application area. Custom roles appear in the Internal staff creation and role-assignment selectors.

Client Member is excluded from staff role management. Super Administrator is protected because its access is granted by the platform authorization gate. Built-in role names remain fixed, but their permissions can be edited. Only unused custom roles can be deleted. A role with no permissions cannot open permission-protected workspace pages.

Staff creation and assignment require users.manage; role management itself requires Super Administrator. Grant users.manage only to trusted staff because it allows provisioning staff and assigning other internal roles. Non-super administrators cannot create or modify Super Administrators.

Role changes are audited and increment affected users' auth_version, requiring them to sign in again. Passwords, tokens and secrets are excluded from audit details. The last internal Super Administrator cannot be demoted through staff management.

The roles seeder creates missing built-in roles and permissions but preserves existing role permission sets. Re-running it does not overwrite changes made on the Role management screen. After deployment, run the normal migrations and clear application caches as described in the deployment guide; these screens introduce no new database tables.

Role concurrency: staff creation/reassignment and role update/deletion lock the role row before user rows. Assignment revalidates the role after obtaining the lock. If deletion wins, assignment reports that the role is unavailable; if assignment wins, deletion sees the assignment and refuses. Automated coverage simulates deletion between validation and assignment. Real two-connection MariaDB lock-contention acceptance remains pending.

## Real database lock acceptance

Run the standalone two-connection test with the PHP executable used by the application:

```
php tests/integration/role-locking.php
```

It loads the application's database connection settings, verifies the server is MariaDB, creates a uniquely named `gwosevo_lockcheck_*` scratch database, and opens two independent PDO connections. It tests assignment-first, deletion-first after an earlier validation read, and assignment rollback. A one-second lock-wait timeout proves the other connection cannot acquire the held role lock. After the winner commits/rolls back, the losing operation rereads the role and memberships. Cleanup removes the scratch database even after assertion failure. The configured application database is not migrated or modified. The database account needs CREATE/DROP DATABASE privileges; use a controlled integration environment rather than expanding production account privileges.

For an additional MySQL compatibility check:

```
php tests/integration/role-locking.php --allow-mysql
```

On 27 October 2026 the configured local server reported MySQL 8.4.6. All three scenarios passed, and scratch cleanup succeeded. This verifies InnoDB locking behavior with the SQL protocol used by the controllers; HTTP authorization, audit and application transactions remain covered by the feature tests. It is not a MariaDB result or a stress/load test. Run the default command against an isolated MariaDB test environment if MariaDB is the deployment target. If production uses MySQL, verify against that exact production engine/version instead. Never point migration/reset-based PHPUnit tests at the production database.
