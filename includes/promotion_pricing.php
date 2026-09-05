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
