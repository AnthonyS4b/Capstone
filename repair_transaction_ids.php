<?php
/**
 * Repairs the missing primary keys on transactions / sales / transaction_items.
 *
 * `transactions.id` was declared NOT NULL with no AUTO_INCREMENT and no
 * PRIMARY KEY, so every POS insert stored id = 0 and lastInsertId() returned
 * 0. Sales and line items were then all linked to transaction 0, which made
 * receipts print as #TRX-00000 and multiplied every dashboard total.
 *
 * Step 1 renumbers the colliding rows, pairing each transaction with its sale
 * and its line item by timestamp order and verifying the amounts agree.
 * Step 2 adds the PRIMARY KEY and AUTO_INCREMENT so new rows number
 * themselves from then on.
 *
 * Usage, from the project root:
 *     php repair_transaction_ids.php            # dry run, prints the plan
 *     php repair_transaction_ids.php --apply    # writes the changes
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

// ---------------------------------------------------------------- gather ---
$txns  = $pdo->query("SELECT total_amount, created_at FROM transactions
                      WHERE id = 0 ORDER BY created_at, total_amount")->fetchAll(PDO::FETCH_ASSOC);
$sales = $pdo->query("SELECT total, created_at FROM sales
                      WHERE transaction_id = 0 ORDER BY created_at, total")->fetchAll(PDO::FETCH_ASSOC);
$items = $pdo->query("SELECT product_id, quantity, price, created_at FROM transaction_items
                      WHERE transaction_id = 0 ORDER BY created_at, price")->fetchAll(PDO::FETCH_ASSOC);

if (!$txns) {
    echo "No transactions carry id 0. Nothing to renumber.\n";
}

if (count($sales) !== count($txns)) {
    fail(sprintf('%d orphaned transactions but %d sales rows', count($txns), count($sales)));
}
if (count($items) !== count($txns)) {
    // Each orphaned transaction so far has exactly one line item. If that ever
    // stops being true the rows cannot be paired positionally, so stop rather
    // than guess which item belongs to which sale.
    fail(sprintf('%d orphaned transactions but %d line items; cannot pair them one to one', count($txns), count($items)));
}

// Rows are addressed by created_at, so it has to be unique inside each set.
foreach ([['transactions', $txns], ['sales', $sales], ['transaction_items', $items]] as $pair) {
    list($label, $set) = $pair;
    $stamps = array_column($set, 'created_at');
    if (count(array_unique($stamps)) !== count($stamps)) {
        fail("$label has orphaned rows sharing a created_at; cannot address them individually");
    }
}

// --------------------------------------------------------------- pairing ---
$nextTxn  = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM transactions")->fetchColumn() + 1;
$nextSale = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM sales")->fetchColumn() + 1;
$nextItem = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM transaction_items")->fetchColumn() + 1;

$plan = [];
foreach ($txns as $k => $t) {
    $s = $sales[$k];
    $i = $items[$k];

    // The pairing is only trustworthy if the money lines up on all three rows.
    $tTotal = (float)$t['total_amount'];
    if (abs($tTotal - (float)$s['total']) > 0.005) {
        fail(sprintf('row %d: transaction total %.2f does not match sale total %.2f', $k, $tTotal, $s['total']));
    }
    if (abs($tTotal - ((float)$i['quantity'] * (float)$i['price'])) > 0.005) {
        fail(sprintf('row %d: transaction total %.2f does not match line total %.2f', $k, $tTotal, $i['quantity'] * $i['price']));
    }
    // Same POS request, so the rows are written within a few seconds.
    if (abs(strtotime($t['created_at']) - strtotime($s['created_at'])) > 120) {
        fail(sprintf('row %d: transaction at %s but sale at %s', $k, $t['created_at'], $s['created_at']));
    }

    $plan[] = [
        'txn_id'  => $nextTxn++,
        'sale_id' => $nextSale++,
        'item_id' => $nextItem++,
        'total'   => $tTotal,
        'txn_at'  => $t['created_at'],
        'sale_at' => $s['created_at'],
        'item_at' => $i['created_at'],
        'product' => $i['product_id'],
    ];
}

if ($plan) {
    printf("%-6s %-8s %-8s %-10s %-21s %s\n", 'TXN', 'SALE', 'ITEM', 'TOTAL', 'TRANSACTION AT', 'PRODUCT');
    foreach ($plan as $p) {
        printf("%-6d %-8d %-8d %-10.2f %-21s %s\n",
            $p['txn_id'], $p['sale_id'], $p['item_id'], $p['total'], $p['txn_at'], $p['product']);
    }
}

if (!$apply) {
    echo "\nDry run. Re-run with --apply to write these changes.\n";
    exit(0);
}

// ----------------------------------------------------------------- apply ---
if ($plan) {
    $pdo->beginTransaction();
    try {
        $uT = $pdo->prepare("UPDATE transactions      SET id = :new WHERE id = 0 AND created_at = :at");
        $uS = $pdo->prepare("UPDATE sales             SET id = :sid, transaction_id = :tid WHERE transaction_id = 0 AND id = 0 AND created_at = :at");
        $uI = $pdo->prepare("UPDATE transaction_items SET id = :iid, transaction_id = :tid WHERE transaction_id = 0 AND id = 0 AND created_at = :at");

        foreach ($plan as $p) {
            $uT->execute([':new' => $p['txn_id'], ':at' => $p['txn_at']]);
            if ($uT->rowCount() !== 1) fail("transaction at {$p['txn_at']}: expected 1 row, updated {$uT->rowCount()}");

            $uS->execute([':sid' => $p['sale_id'], ':tid' => $p['txn_id'], ':at' => $p['sale_at']]);
            if ($uS->rowCount() !== 1) fail("sale at {$p['sale_at']}: expected 1 row, updated {$uS->rowCount()}");

            $uI->execute([':iid' => $p['item_id'], ':tid' => $p['txn_id'], ':at' => $p['item_at']]);
            if ($uI->rowCount() !== 1) fail("line item at {$p['item_at']}: expected 1 row, updated {$uI->rowCount()}");
        }

        $left = (int)$pdo->query("SELECT COUNT(*) FROM transactions WHERE id = 0")->fetchColumn()
              + (int)$pdo->query("SELECT COUNT(*) FROM sales WHERE transaction_id = 0")->fetchColumn()
              + (int)$pdo->query("SELECT COUNT(*) FROM transaction_items WHERE transaction_id = 0")->fetchColumn();
        if ($left !== 0) fail("$left rows still carry id 0 after renumbering");

        $pdo->commit();
        echo "\nRenumbered " . count($plan) . " transactions.\n";
    } catch (Throwable $e) {
        $pdo->rollBack();
        fail('rolled back, nothing was changed: ' . $e->getMessage());
    }
}

// DDL cannot take part in the transaction above, so it runs afterwards.
$ddl = [
    "ALTER TABLE transactions      MODIFY id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (id)",
    "ALTER TABLE sales             MODIFY id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (id)",
    "ALTER TABLE transaction_items MODIFY id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (id)",
    "ALTER TABLE sales             ADD INDEX idx_sales_transaction (transaction_id)",
    "ALTER TABLE transaction_items ADD INDEX idx_items_transaction (transaction_id)",
];
foreach ($ddl as $sql) {
    try {
        $pdo->exec($sql);
        echo "ok: $sql\n";
    } catch (PDOException $e) {
        // Re-running the script should not be an error once a key already exists.
        echo "skipped (" . $e->getCode() . "): $sql\n";
    }
}

echo "\nDone. Ring up one test sale and confirm it gets a non-zero id.\n";
