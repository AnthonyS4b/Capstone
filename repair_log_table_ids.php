<?php
/**
 * Restores AUTO_INCREMENT on the two logging tables.
 *
 * `inventory_history.id` and `login_sessions.id` were declared NOT NULL with a
 * PRIMARY KEY but no AUTO_INCREMENT. Every insert therefore wrote id = 0. The
 * first one succeeded; every insert after that failed with
 * "Duplicate entry '0' for key 'PRIMARY'".
 *
 * Both call sites catch the exception and only write to error_log, so the
 * product edit or the login still completed while the audit row was quietly
 * dropped. The visible symptom is a Log History page that stops gaining new
 * entries.
 *
 * Nothing references either id, so the repair only has to renumber the single
 * colliding row per table and restore AUTO_INCREMENT.
 *
 * Usage, from the project root:
 *     php repair_log_table_ids.php            # dry run, prints the plan
 *     php repair_log_table_ids.php --apply    # writes the changes
 *
 * Take a database backup before running with --apply:
 *     "D:\xampp\mysql\bin\mysqldump.exe" -u root espenida_pos > backup.sql
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This repair script is command line only.\n");
}

require_once __DIR__ . '/config/database.php';

$apply = in_array('--apply', $argv ?? [], true);
$pdo   = getDBConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function fail($msg) { fwrite(STDERR, "ABORT: $msg\n"); exit(1); }

// The ordering column differs between the two tables.
$targets = [
    'inventory_history' => 'created_at',
    'login_sessions'    => 'login_time',
];

$plan = [];

foreach ($targets as $table => $timeColumn) {
    $zeroRows = (int)$pdo->query("SELECT COUNT(*) FROM `$table` WHERE id = 0")->fetchColumn();
    $maxId    = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM `$table`")->fetchColumn();
    $total    = (int)$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();

    if ($zeroRows > 1) {
        // Rows sharing id 0 cannot be told apart by id. Address them by their
        // timestamp instead, which has to be unique for that to be safe.
        $distinctStamps = (int)$pdo->query(
            "SELECT COUNT(DISTINCT `$timeColumn`) FROM `$table` WHERE id = 0"
        )->fetchColumn();
        if ($distinctStamps !== $zeroRows) {
            fail("$table has $zeroRows rows with id 0 sharing a `$timeColumn`; cannot address them individually");
        }
    }

    $plan[$table] = [
        'time_column' => $timeColumn,
        'zero_rows'   => $zeroRows,
        'max_id'      => $maxId,
        'total'       => $total,
        'next_id'     => $maxId + 1,
    ];

    printf("%-20s rows=%-6d id=0 rows=%-3d max id=%-6d will renumber to %d..%d\n",
        $table, $total, $zeroRows, $maxId,
        $maxId + 1, $maxId + max($zeroRows, 1));
}

if (!$apply) {
    echo "\nDry run. Re-run with --apply to write these changes.\n";
    exit(0);
}

// ----------------------------------------------------------------- apply ---
foreach ($plan as $table => $info) {
    if ($info['zero_rows'] > 0) {
        $pdo->beginTransaction();
        try {
            $rows = $pdo->query(
                "SELECT `{$info['time_column']}` AS stamp FROM `$table` WHERE id = 0 ORDER BY `{$info['time_column']}`"
            )->fetchAll(PDO::FETCH_COLUMN);

            $next = $info['next_id'];
            $update = $pdo->prepare(
                "UPDATE `$table` SET id = :new WHERE id = 0 AND `{$info['time_column']}` = :stamp"
            );

            foreach ($rows as $stamp) {
                $update->execute([':new' => $next, ':stamp' => $stamp]);
                if ($update->rowCount() !== 1) {
                    fail("$table row at $stamp: expected 1 row, updated {$update->rowCount()}");
                }
                $next++;
            }

            $left = (int)$pdo->query("SELECT COUNT(*) FROM `$table` WHERE id = 0")->fetchColumn();
            if ($left !== 0) fail("$table still has $left rows with id 0");

            $pdo->commit();
            echo "renumbered {$info['zero_rows']} row(s) in $table\n";
        } catch (Throwable $e) {
            $pdo->rollBack();
            fail("$table rolled back, nothing changed: " . $e->getMessage());
        }
    }

    // DDL cannot take part in the transaction above, so it runs afterwards.
    try {
        $pdo->exec("ALTER TABLE `$table` MODIFY id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT");
        echo "ok: AUTO_INCREMENT restored on $table\n";
    } catch (PDOException $e) {
        // Re-running the script should not be an error.
        echo "skipped (" . $e->getCode() . "): $table\n";
    }
}

echo "\nDone. Edit a product and confirm a new row appears in Log History.\n";
