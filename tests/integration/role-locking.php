<?php

// Standalone integration check. Creates/drops only a uniquely named scratch database.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$config = $app['db']->connection()->getConfig();
if (! in_array($config['driver'], ['mysql', 'mariadb'], true)) {
    fwrite(STDERR, "Configure a MySQL/MariaDB connection before running this check.\n");
    exit(2);
}
$dsn = ! empty($config['unix_socket'])
    ? 'mysql:unix_socket='.$config['unix_socket'].';charset=utf8mb4'
    : 'mysql:host='.$config['host'].';port='.$config['port'].';charset=utf8mb4';
$connect = fn () => new PDO($dsn, $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION] + ($config['options'] ?? []));
$database = 'gwosevo_lockcheck_'.bin2hex(random_bytes(6));
$created = false;
$a = $b = null;
$check = function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
try {
    $a = $connect();
    $version = $a->query('SELECT VERSION()')->fetchColumn();
    echo 'Database server: '.$version."\n";
    if (stripos($version, 'MariaDB') === false && ! in_array('--allow-mysql', $argv, true)) {
        echo "MariaDB acceptance NOT run: configured server is not MariaDB. Use --allow-mysql only for an additional MySQL compatibility check.\n";
        exit(2);
    }
    $a->exec('CREATE DATABASE `'.$database.'`');
    $created = true;
    $a->exec('USE `'.$database.'`');
    $b = $connect();
    $b->exec('USE `'.$database.'`');
    $check($a->query('SELECT CONNECTION_ID()')->fetchColumn() !== $b->query('SELECT CONNECTION_ID()')->fetchColumn(), 'Connections must be independent.');
    foreach ([$a, $b] as $pdo) {
        $pdo->exec('SET SESSION innodb_lock_wait_timeout=1');
    }
    $a->exec('CREATE TABLE roles (id BIGINT PRIMARY KEY, name VARCHAR(80) UNIQUE, guard_name VARCHAR(20)) ENGINE=InnoDB');
    $a->exec('CREATE TABLE model_has_roles (role_id BIGINT, model_id BIGINT, PRIMARY KEY(role_id,model_id), FOREIGN KEY(role_id) REFERENCES roles(id) ON DELETE CASCADE) ENGINE=InnoDB');
    $a->exec("INSERT INTO roles VALUES (1,'Race role','web')");
    $lock = function (PDO $pdo) {
        return $pdo->query("SELECT * FROM roles WHERE name='Race role' AND guard_name='web' FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
    };
    $blocked = function (PDO $pdo) use ($lock, $check): void {
        $pdo->beginTransaction();
        try {
            $lock($pdo);
            throw new RuntimeException('Conflicting role lock unexpectedly succeeded.');
        } catch (PDOException $e) {
            $check(($e->errorInfo[1] ?? null) === 1205, 'Expected a lock-wait timeout, not another database error.');
        } finally {
            $pdo->rollBack();
        }
    };
    // Assignment locks the parent before writing membership. Deletion must wait.
    $a->beginTransaction();
    $check((bool) $lock($a), 'Role missing before assignment.');
    $a->exec('INSERT INTO model_has_roles VALUES (1,101)');
    $blocked($b);
    $a->commit();
    $b->beginTransaction();
    $lock($b);
    $check((int) $b->query('SELECT COUNT(*) FROM model_has_roles WHERE role_id=1')->fetchColumn() === 1, 'Deletion must see the committed assignment and refuse.');
    $b->commit();
    echo "PASS: assignment-first blocks deletion, which then sees the assigned user.\n";
    // Deletion wins after an earlier validation read; assignment must recheck under lock.
    $a->exec('DELETE FROM model_has_roles');
    $check((bool) $lock($b), 'Validation read must initially find the role.');
    $a->beginTransaction();
    $lock($a);
    $a->exec('DELETE FROM roles WHERE id=1');
    $blocked($b);
    $a->commit();
    $b->beginTransaction();
    $check($lock($b) === false, 'Assignment must reject the role after deletion wins.');
    $b->rollBack();
    $check((int) $a->query('SELECT COUNT(*) FROM model_has_roles')->fetchColumn() === 0, 'No orphan assignments permitted.');
    echo "PASS: deletion-first blocks assignment, which then finds the role unavailable.\n";
    // A rolled-back assignment must not leave a phantom role membership.
    $a->exec("INSERT INTO roles VALUES (1,'Race role','web')");
    $a->beginTransaction();
    $lock($a);
    $a->exec('INSERT INTO model_has_roles VALUES (1,102)');
    $blocked($b);
    $a->rollBack();
    $b->beginTransaction();
    $lock($b);
    $check((int) $b->query('SELECT COUNT(*) FROM model_has_roles')->fetchColumn() === 0, 'Rollback left a phantom membership.');
    $b->exec('DELETE FROM roles WHERE id=1');
    $b->commit();
    echo "PASS: assignment rollback allows safe deletion.\n";
    echo stripos($version, 'MariaDB') !== false ? "MariaDB two-connection lock checks passed.\n" : "MySQL compatibility checks passed; MariaDB acceptance remains outstanding.\n";
} catch (Throwable $e) {
    // Connection messages may include infrastructure details; do not print credentials.
    fwrite(STDERR, 'Role-lock integration check failed ('.$e::class."). Check connectivity, scratch-database privileges and lock assertions.\n");
    $resultCode = 1;
} finally {
    foreach ([$a, $b] as $pdo) {
        if ($pdo && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
    if ($created) {
        $a->exec('DROP DATABASE `'.$database.'`');
        echo "Scratch database removed.\n";
    }
}

exit($resultCode ?? 0);
