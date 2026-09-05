<?php
require_once __DIR__ . '/../includes/promotion_pricing.php';

function check_total(array $items, float $expected): void {
    $lines = price_promotion_items($items);
    $total = array_sum(array_map(function ($line) { return $line['price'] * $line['quantity']; }, $lines));
    if (abs($total - $expected) > 0.00001) throw new Exception("Expected $expected, got $total");
    if (array_sum(array_column($items, 'quantity')) !== array_sum(array_column($lines, 'quantity'))) {
        throw new Exception('Pricing changed stock quantities');
    }
}
$target = ['id' => 1, 'name' => 'Slow item', 'price' => 220, 'quantity' => 3,
    'strategy_id' => 'cross_sell_pairing', 'paired_product_id' => 2, 'discounted_price' => 198];
$partner = ['id' => 2, 'name' => 'Partner', 'price' => 50, 'quantity' => 1];
check_total([$target], 660);
check_total([$target, $partner], 688);
check_total([$partner, $target], 688);
$partner['quantity'] = 5;
check_total([$target, $partner], 844);
$partner['id'] = 3;
check_total([$target, $partner], 910);
$target['paired_product_id'] = 1;
check_total([$target], 660);
$target['strategy_id'] = null;
check_total([$target], 660);
echo "PASS: standalone, matched quantities, excess partners, wrong partner, self pairing, inactive promotion and stock quantities\n";
