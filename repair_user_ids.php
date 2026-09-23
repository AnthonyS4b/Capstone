<?php
/**
 * Restores the PRIMARY KEY and AUTO_INCREMENT on `users.id`.
 *
 * `users.id` was declared NOT NULL with no primary key and no AUTO_INCREMENT,
 * so every account added from User roles was stored with id = 0. The session
 * check (security_require_login) treats a user id of 0 as "not signed in", so
 * such an account can sign in but every action afterwards answers
 * "Authentication required. Please log in again." A second new account would
 * also get id 0, leaving two people sharing one id.
 *
 * The repair moves each id-0 account to a fresh id above every id ever used
 * (including ids of deleted users still referenced in history, so old records
 * are never re-attributed), updates the rows that point at it, then adds the
 * primary key and AUTO_INCREMENT.
 *
 * Usage, from the project root:
 *     php repair_user_ids.php            # dry run, prints the plan
 *     php repair_user_ids.php --apply    # writes the changes
 *
 * Take a database backup before running with --apply:
 *     "D:\xampp\mysql\bin\mysqldump.exe" -u root espenida_pos > backup.sql
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This repair script is command line only.\n");
}

require_once __DIR__ . '/config/database.php';

$apply = in_array('--apply', $argv, true);
$pdo = getDBConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Every column that stores a user id
$refs = [
    ['login_sessions', 'user_id'], ['login_sessions', 'switched_from_id'],
    ['transactions', 'user_id'], ['sales', 'user_id'], ['inventory_history', 'user_id'],
    ['products', 'created_by'], ['products', 'updated_by'], ['products', 'deleted_by'], ['products', 'archived_by'],
    ['strategy_history', 'created_by'], ['categories', 'deleted_by'],
];

$zeroUsers = $pdo->query("SELECT id, first_name, last_name, email FROM users WHERE id = 0")->fetchAll(PDO::FETCH_ASSOC);
$dupes = $pdo->query("SELECT id, COUNT(*) n FROM users GROUP BY id HAVING n > 1 AND id <> 0")->fetchAll(PDO::FETCH_ASSOC);
$hasPk = (bool)$pdo->query("SHOW KEYS FROM users WHERE Key_name = 'PRIMARY'")->fetch();

// Highest id ever used anywhere, so a fresh id never collides with history
$highest = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM users")->fetchColumn();
foreach ($refs as [$t, $c]) {
    $highest = max($highest, (int)$pdo->query("SELECT COALESCE(MAX(`$c`), 0) FROM `$t`")->fetchColumn());
}

echo "users.id primary key present: " . ($hasPk ? 'yes' : 'NO') . "\n";
if ($dupes) {
    exit("Aborting: non-zero duplicate ids found: " . json_encode($dupes) . "\n");
}
if (count($zeroUsers) > 1) {
    exit("Aborting: more than one account has id 0; each needs its references sorted out by hand.\n");
}

$newId = null;
if ($zeroUsers) {
    $newId = $highest + 1;
    $u = $zeroUsers[0];
    echo "Account with id 0: {$u['first_name']} {$u['last_name']} <{$u['email']}> -> new id $newId\n";
    foreach ($refs as [$t, $c]) {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM `$t` WHERE `$c` = 0")->fetchColumn();
        if ($n) echo "  $t.$c: $n row(s) -> $newId\n";
    }
} else {
    echo "No account has id 0.\n";
}
$nextAuto = max($highest, (int)$newId) + 1;
echo "Then: PRIMARY KEY (id), id AUTO_INCREMENT, next id $nextAuto\n";

if (!$apply) {
    exit("\nDry run only. Re-run with --apply to write these changes.\n");
}

if ($newId !== null) {
    $pdo->beginTransaction();
    try {
        foreach ($refs as [$t, $c]) {
            $pdo->prepare("UPDATE `$t` SET `$c` = ? WHERE `$c` = 0")->execute([$newId]);
        }
        $pdo->prepare("UPDATE users SET id = ? WHERE id = 0")->execute([$newId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        exit("Failed, nothing changed: " . $e->getMessage() . "\n");
    }
}

// DDL commits on its own in MySQL, so it runs after the data move
if (!$hasPk) {
    $pdo->exec("ALTER TABLE users ADD PRIMARY KEY (id)");
}
$pdo->exec("ALTER TABLE users MODIFY id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT");
$pdo->exec("ALTER TABLE users AUTO_INCREMENT = " . (int)$nextAuto);

echo "\nDone.";
if ($newId !== null) echo " That account must sign out and back in once to pick up its new id.";
echo "\n";
