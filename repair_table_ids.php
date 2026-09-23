<?php
/**
 * Restores AUTO_INCREMENT (and, for product_batches, the PRIMARY KEY) on the
 * catalogue tables.
 *
 * `products.id`, `categories.id` and `ml_model_metrics.id` have a PRIMARY KEY
 * but no AUTO_INCREMENT; `product_batches.id` has neither. Every insert that
 * leaves out the id therefore writes id = 0:
 *
 *   - the next new product or category is stored as id 0 and the one after it
 *     fails with "Duplicate entry '0' for key 'PRIMARY'";
 *   - adding a product reads lastInsertId() for its first stock batch, which
 *     is 0, so the batch is filed under a product that does not exist;
 *   - every new stock batch gets id 0. Sales and stock-outs deduct with
 *     "UPDATE product_batches ... WHERE id = :bid", so once two batches share
 *     id 0 one deduction takes stock from both.
 *
 * No foreign key or other table stores a batch id, so id-0 batches are simply
 * renumbered (told apart by their unique batch_no). New product and category
 * ids start above every id still referenced anywhere, so old sales and log
 * entries can never point at a newly added product.
 *
 * Usage, from the project root:
 *     php repair_table_ids.php            # dry run, prints the plan
 *     php repair_table_ids.php --apply    # writes the changes
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

// table => [id column type, column that tells id-0 rows apart, columns elsewhere holding this table's ids]
$targets = [
    'categories'       => ['INT(10) UNSIGNED', null, [['products', 'category_id']]],
    'products'         => ['INT(10) UNSIGNED', null, [
        ['product_batches', 'product_id'], ['inventory_history', 'product_id'],
        ['transaction_items', 'product_id'], ['strategy_history', 'product_id'],
        ['ml_recommendation_logs', 'product_id'],
    ]],
    'product_batches'  => ['INT(11)', 'batch_no', []],
    'ml_model_metrics' => ['INT(10) UNSIGNED', null, []],
];

$plan = [];

foreach ($targets as $table => [$type, $keyColumn, $refs]) {
    $zeroRows = (int)$pdo->query("SELECT COUNT(*) FROM `$table` WHERE id = 0")->fetchColumn();
    $maxId    = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM `$table`")->fetchColumn();
    $hasPk    = (bool)$pdo->query("SHOW KEYS FROM `$table` WHERE Key_name = 'PRIMARY'")->fetch();
    $dupes    = (int)$pdo->query("SELECT COUNT(*) FROM (SELECT id FROM `$table` WHERE id <> 0 GROUP BY id HAVING COUNT(*) > 1) d")->fetchColumn();

    if ($dupes) fail("$table has $dupes non-zero id(s) used more than once; sort those out by hand");
    if ($zeroRows && $refs) {
        // Rows elsewhere pointing at id 0 would silently follow whichever row gets renumbered
        fail("$table has a row with id 0 that other tables may reference; renumber it by hand");
    }
    if ($zeroRows > 1) {
        if ($keyColumn === null) fail("$table has $zeroRows rows with id 0 and nothing to tell them apart");
        $distinct = (int)$pdo->query("SELECT COUNT(DISTINCT `$keyColumn`) FROM `$table` WHERE id = 0")->fetchColumn();
        if ($distinct !== $zeroRows) fail("$table rows with id 0 share a `$keyColumn`; cannot address them individually");
    }

    // Highest id ever used, including ids of rows that only survive in history
    $highest = $maxId;
    foreach ($refs as [$t, $c]) {
        $highest = max($highest, (int)$pdo->query("SELECT COALESCE(MAX(`$c`), 0) FROM `$t`")->fetchColumn());
    }

    $plan[$table] = compact('type', 'keyColumn', 'zeroRows', 'hasPk', 'highest');

    printf("%-17s primary key=%-3s id=0 rows=%d  highest id used=%d  -> ", $table, $hasPk ? 'yes' : 'NO', $zeroRows, $highest);
    if ($zeroRows) printf("renumber to %d..%d, ", $highest + 1, $highest + $zeroRows);
    printf("%sAUTO_INCREMENT, next id %d\n", $hasPk ? '' : 'PRIMARY KEY + ', $highest + $zeroRows + 1);

    if ($zeroRows && $keyColumn) {
        $st = $pdo->query("SELECT product_id, `$keyColumn` AS k, stock FROM `$table` WHERE id = 0 ORDER BY created_at, `$keyColumn`");
        foreach ($st as $r) echo "    $keyColumn {$r['k']} (product {$r['product_id']}, stock {$r['stock']})\n";
    }
}

if (!$apply) {
    echo "\nDry run. Re-run with --apply to write these changes.\n";
    exit(0);
}

// ----------------------------------------------------------------- apply ---
foreach ($plan as $table => $info) {
    $next = $info['highest'] + 1;

    if ($info['zeroRows'] > 0) {
        $pdo->beginTransaction();
        try {
            $key  = $info['keyColumn'];
            $keys = $key
                ? $pdo->query("SELECT `$key` FROM `$table` WHERE id = 0 ORDER BY created_at, `$key`")->fetchAll(PDO::FETCH_COLUMN)
                : [null];
            $update = $key
                ? $pdo->prepare("UPDATE `$table` SET id = :new WHERE id = 0 AND `$key` = :k")
                : $pdo->prepare("UPDATE `$table` SET id = :new WHERE id = 0");

            foreach ($keys as $k) {
                $update->execute($key ? [':new' => $next, ':k' => $k] : [':new' => $next]);
                if ($update->rowCount() !== 1) fail("$table: expected 1 row for $k, updated {$update->rowCount()}");
                $next++;
            }

            $left = (int)$pdo->query("SELECT COUNT(*) FROM `$table` WHERE id = 0")->fetchColumn();
            if ($left !== 0) fail("$table still has $left rows with id 0");

            $pdo->commit();
            echo "renumbered {$info['zeroRows']} row(s) in $table\n";
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            fail("$table rolled back, nothing changed: " . $e->getMessage());
        }
    }

    // DDL commits on its own in MySQL, so it runs after the data move
    if (!$info['hasPk']) {
        $pdo->exec("ALTER TABLE `$table` ADD PRIMARY KEY (id)");
    }
    $pdo->exec("ALTER TABLE `$table` MODIFY id {$info['type']} NOT NULL AUTO_INCREMENT");
    $pdo->exec("ALTER TABLE `$table` AUTO_INCREMENT = " . (int)$next);
    echo "ok: $table AUTO_INCREMENT, next id $next\n";
}

echo "\nDone. Add a product, then check it got a real id and its first stock batch points at it.\n";
