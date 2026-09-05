-- Run once before deploying the cross-sell pairing code.
ALTER TABLE strategy_history ADD COLUMN paired_product_id INT(10) UNSIGNED DEFAULT NULL;

-- Old cross-sell records were unconditional discounts. Restore their prices
-- and retire them so an owner can reapply with an explicit partner.
START TRANSACTION;
UPDATE products p JOIN strategy_history sh ON sh.product_id = p.id
SET p.price = sh.original_price, p.updated_at = NOW()
WHERE sh.strategy_id = 'cross_sell_pairing' AND sh.status = 'applied'
  AND sh.paired_product_id IS NULL AND p.price = sh.discounted_price;
UPDATE strategy_history
SET status = 'cancelled', ended_at = NOW(),
    outcome_notes = CONCAT(COALESCE(outcome_notes, ''), ' [Reapply with a paired product]')
WHERE strategy_id = 'cross_sell_pairing' AND status = 'applied' AND paired_product_id IS NULL;
COMMIT;
