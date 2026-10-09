<?php

/**
 * The store's VAT rate.
 *
 * Prices are VAT-inclusive: the rate does not change what a customer pays, only how a
 * receipt splits the total into "Subtotal (VAT excl.)" and "VAT". The owner changes it
 * on the Inventory page. Every change is kept with the time it took effect (table
 * tax_rates), so a receipt opened or printed later shows the rate of the day of the
 * sale, not today's.
 */

/** Philippine VAT, and the rate of every sale made before the rate became editable. */
const TAX_DEFAULT_RATE = 12.0;

/** Make the tax_rates table. Called before the first change, never inside a transaction. */
function tax_ensure_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS tax_rates (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    rate DECIMAL(5,2) NOT NULL,
                    effective_from DATETIME NOT NULL,
                    changed_by INT UNSIGNED NULL DEFAULT NULL,
                    PRIMARY KEY (id),
                    KEY idx_tax_rates_effective (effective_from)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

/** Run a tax_rates query; null when the table does not exist yet (no rate was ever set). */
function tax_query(PDO $pdo, string $sql, array $params = []): ?PDOStatement
{
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st;
    } catch (PDOException $e) {
        if ($e->getCode() === '42S02') return null;
        throw $e;
    }
}

/** The VAT rate, in percent, in effect at $when ('Y-m-d H:i:s'); now when left out. */
function tax_rate_at(PDO $pdo, ?string $when = null): float
{
    $st = tax_query($pdo, "SELECT rate FROM tax_rates
                           WHERE effective_from <= COALESCE(?, NOW())
                           ORDER BY effective_from DESC, id DESC LIMIT 1", [$when]);
    $rate = $st ? $st->fetchColumn() : false;
    return $rate === false ? TAX_DEFAULT_RATE : (float)$rate;
}

/** A typed rate as a number, or null unless it is 0 to 100 with at most 2 decimals. */
function tax_rate_parse($value): ?float
{
    $text = is_scalar($value) ? trim((string)$value) : '';
    if (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $text) || (float)$text > 100) return null;
    return round((float)$text, 2);
}

/** "12" or "12.5": a rate without needless zeros, for "VAT 12%". */
function tax_rate_text(float $rate): string
{
    return rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
}

/** The rate now, and when and by whom it was last changed (both null if it never was). */
function tax_rate_info(PDO $pdo): array
{
    $st = tax_query($pdo, "SELECT t.rate, t.effective_from, TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS changed_by
                           FROM tax_rates t LEFT JOIN users u ON u.id = t.changed_by
                           WHERE t.effective_from <= NOW()
                           ORDER BY t.effective_from DESC, t.id DESC LIMIT 1");
    $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
    $rate = $row ? (float)$row['rate'] : TAX_DEFAULT_RATE;
    return [
        'rate' => $rate,
        'rate_text' => tax_rate_text($rate),
        'changed_at' => $row ? $row['effective_from'] : null,
        'changed_by' => $row ? ($row['changed_by'] ?: null) : null,
    ];
}

/** Make $rate the VAT rate from this moment on. Sales already made keep theirs. */
function tax_rate_set(PDO $pdo, float $rate, int $userId): void
{
    tax_ensure_schema($pdo);
    // The first change also writes down what applied until now, so earlier sales keep it
    if (!$pdo->query("SELECT 1 FROM tax_rates LIMIT 1")->fetchColumn()) {
        $pdo->prepare("INSERT INTO tax_rates (rate, effective_from, changed_by) VALUES (?, '2000-01-01 00:00:00', NULL)")
            ->execute([TAX_DEFAULT_RATE]);
    }
    $pdo->prepare("INSERT INTO tax_rates (rate, effective_from, changed_by) VALUES (?, NOW(), ?)")
        ->execute([$rate, $userId]);
}
