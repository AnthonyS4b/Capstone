<?php

/** Price validated, unique cart products using promotion data loaded by the server. */
function price_promotion_items(array $items): array
{
    $quantities = [];
    foreach ($items as $item) {
        $quantities[(int)$item['id']] = (int)$item['quantity'];
    }
    $priced = [];
    foreach ($items as $item) {
        $quantity = (int)$item['quantity'];

        // Buy 1 Take 1: every second unit of the same product is free, so an odd
        // unit (or a single one) is charged the regular price
        if (($item['strategy_id'] ?? '') === 'buy_one_take_one') {
            $free = intdiv($quantity, 2);
            $base = ['id' => (int)$item['id'], 'name' => $item['name']];
            $priced[] = $base + ['price' => round((float)$item['price'], 2), 'quantity' => $quantity - $free];
            if ($free > 0) {
                $priced[] = $base + ['price' => 0.0, 'quantity' => $free];
            }
            continue;
        }

        $pairedQuantity = 0;
        $partnerId = (int)($item['paired_product_id'] ?? 0);
        if (($item['strategy_id'] ?? '') === 'cross_sell_pairing'
            && $partnerId > 0 && $partnerId !== (int)$item['id']
            && isset($item['discounted_price'])) {
            $pairedQuantity = min($quantity, $quantities[$partnerId] ?? 0);
        }
        $base = ['id' => (int)$item['id'], 'name' => $item['name']];
        if ($pairedQuantity > 0) {
            $priced[] = $base + [
                'price' => round((float)$item['discounted_price'], 2),
                'quantity' => $pairedQuantity,
            ];
        }
        if ($quantity > $pairedQuantity) {
            $priced[] = $base + [
                'price' => round((float)$item['price'], 2),
                'quantity' => $quantity - $pairedQuantity,
            ];
        }
    }
    return $priced;
}

/**
 * Close promotions whose end date has passed and put the regular price back.
 *
 * Applying a discount promotion writes the promotional price into
 * products.price, so something has to undo that when the promotion runs out.
 * The regular price is restored only while the product still carries the
 * promotional price: if the owner changed the price during the promotion,
 * that newer price is kept. A newer promotion on the same product owns the
 * price, so an older one ending leaves it alone.
 *
 * Runs on every signed-in request (includes/security.php), before any page or
 * checkout reads a price. Returns how many promotions were closed.
 */
function promotions_expire_due(PDO $pdo): int
{
    $due = $pdo->query("SELECT id FROM strategy_history
                        WHERE status = 'applied' AND ended_at IS NOT NULL AND ended_at <= NOW()
                        ORDER BY ended_at, id")->fetchAll(PDO::FETCH_COLUMN);
    $closed = 0;
    foreach ($due as $historyId) {
        $pdo->beginTransaction();
        try {
            // Locked so two requests arriving together cannot both close it
            $st = $pdo->prepare("SELECT sh.product_id, sh.strategy_id, sh.original_price, sh.discounted_price, p.name
                                 FROM strategy_history sh LEFT JOIN products p ON p.id = sh.product_id
                                 WHERE sh.id = ? AND sh.status = 'applied' FOR UPDATE");
            $st->execute([$historyId]);
            $promo = $st->fetch(PDO::FETCH_ASSOC);
            if (!$promo) {
                $pdo->rollBack();
                continue;
            }

            $pdo->prepare("UPDATE strategy_history SET status = 'completed' WHERE id = ?")->execute([$historyId]);

            // Pairing and Buy 1 Take 1 never change products.price, so there is nothing to restore
            if (!in_array($promo['strategy_id'], ['cross_sell_pairing', 'buy_one_take_one'], true)) {
                $newer = $pdo->prepare("SELECT 1 FROM strategy_history
                                        WHERE product_id = ? AND id <> ? AND status = 'applied'
                                          AND (ended_at IS NULL OR ended_at > NOW()) LIMIT 1");
                $newer->execute([$promo['product_id'], $historyId]);

                if (!$newer->fetchColumn()) {
                    $restore = $pdo->prepare("UPDATE products SET price = ?, updated_at = NOW()
                                              WHERE id = ? AND ABS(price - ?) < 0.005");
                    $restore->execute([$promo['original_price'], $promo['product_id'], $promo['discounted_price']]);

                    if ($restore->rowCount() > 0) {
                        $pdo->prepare("INSERT INTO inventory_history (product_id, product_name, user_id, action, changes, created_at)
                                       VALUES (?, ?, NULL, 'edit', ?, NOW())")
                            ->execute([
                                $promo['product_id'],
                                $promo['name'],
                                sprintf('Promotion ended: price restored from ₱%s to ₱%s',
                                    number_format((float)$promo['discounted_price'], 2),
                                    number_format((float)$promo['original_price'], 2)),
                            ]);
                    }
                }
            }

            $pdo->commit();
            $closed++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('promotions_expire_due #' . $historyId . ': ' . $e->getMessage());
        }
    }
    return $closed;
}
