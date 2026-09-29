<?php
require_once __DIR__ . '/product_units.php';

/**
 * Remove expired stock from inventory so the fresh stock behind it can be sold.
 *
 * A product shows as Expired, and is hidden from the POS, while its
 * nearest-expiring batch is past its date. Writing that batch off (stock to 0,
 * status 'expired') lets the next batch take over: the product's expiry moves
 * to the next batch, or is cleared when nothing is left, so the product reads
 * "out of stock" rather than "expired" until it is restocked.
 *
 * Every write-off is recorded in Log History (action 'expired') with the units
 * and their value at cost, so the loss stays visible.
 *
 * Runs on every signed-in request (includes/security.php). Returns how many
 * batches were written off.
 */
function inventory_expire_batches(PDO $pdo): int
{
    $due = $pdo->query("SELECT id FROM product_batches
                        WHERE status = 'active' AND stock > 0
                          AND expiration_date IS NOT NULL AND expiration_date < CURDATE()
                        ORDER BY product_id, expiration_date, id")->fetchAll(PDO::FETCH_COLUMN);
    $removed = 0;
    foreach ($due as $batchId) {
        $pdo->beginTransaction();
        try {
            // Locked so two requests arriving together cannot both write it off
            $st = $pdo->prepare("SELECT b.product_id, b.batch_no, b.stock, b.expiration_date, p.name, p.cost_price, p.unit
                                 FROM product_batches b
                                 JOIN products p ON p.id = b.product_id
                                 WHERE b.id = ? AND b.status = 'active' AND b.stock > 0
                                   AND b.expiration_date < CURDATE()
                                 FOR UPDATE");
            $st->execute([$batchId]);
            $batch = $st->fetch(PDO::FETCH_ASSOC);
            if (!$batch) {
                $pdo->rollBack();
                continue;
            }
            $units = hundredths_to_qty(qty_to_hundredths($batch['stock'])); // exact, kilograms included

            $pdo->prepare("UPDATE product_batches SET stock = 0, status = 'expired' WHERE id = ?")
                ->execute([$batchId]);
            $pdo->prepare("UPDATE products SET stock = GREATEST(stock - ?, 0), updated_at = NOW() WHERE id = ?")
                ->execute([$units, $batch['product_id']]);

            // The product now follows its next batch, or has no expiry when nothing is left
            $next = $pdo->prepare("SELECT date_added, expiration_date FROM product_batches
                                   WHERE product_id = ? AND stock > 0 AND status = 'active'
                                   ORDER BY CASE WHEN expiration_date IS NULL THEN 1 ELSE 0 END, expiration_date ASC, date_added ASC
                                   LIMIT 1");
            $next->execute([$batch['product_id']]);
            if ($nextBatch = $next->fetch(PDO::FETCH_ASSOC)) {
                $pdo->prepare("UPDATE products SET expiration_date = ?, date_added = ? WHERE id = ?")
                    ->execute([$nextBatch['expiration_date'], $nextBatch['date_added'], $batch['product_id']]);
            } else {
                $pdo->prepare("UPDATE products SET expiration_date = NULL WHERE id = ?")
                    ->execute([$batch['product_id']]);
            }

            $pdo->prepare("INSERT INTO inventory_history (product_id, product_name, user_id, action, changes, created_at)
                           VALUES (?, ?, NULL, 'expired', ?, NOW())")
                ->execute([
                    $batch['product_id'],
                    $batch['name'],
                    sprintf('Expired stock removed: -%s from batch %s (expired %s), ₱%s at cost',
                        format_unit_amount($units, $batch['unit']),
                        $batch['batch_no'],
                        date('M j, Y', strtotime($batch['expiration_date'])),
                        number_format((float)$units * (float)$batch['cost_price'], 2)),
                ]);

            $pdo->commit();
            $removed++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('inventory_expire_batches #' . $batchId . ': ' . $e->getMessage());
        }
    }
    return $removed;
}
