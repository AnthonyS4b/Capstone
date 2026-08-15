<?php

require_once __DIR__ . '/includes/security.php';
security_start_session();
security_require_role(['owner']);
security_require_csrf();
require_once __DIR__ . '/config/database.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    security_json_error('Invalid request method.', 405);
}

$type = (string)($_POST['type'] ?? '');
$allowed = ['database_backup', 'sales_csv', 'inventory_csv', 'users_csv', 'activity_csv'];
if (!in_array($type, $allowed, true)) {
    security_json_error('Invalid export type.', 400);
}

try {
    $pdo = getDBConnection();
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $stamp = date('Y-m-d_H-i-s');

    if ($type === 'database_backup') {
        stream_database_backup($pdo, "espenida_pos_backup_{$stamp}.sql");
    }

    $exports = [
        'sales_csv' => [
            'filename' => "sales_export_{$stamp}.csv",
            'sql' => "SELECT t.id AS transaction_id, t.created_at, CONCAT(u.first_name, ' ', u.last_name) AS cashier,
                            s.status, s.payment_method, s.gcash_reference, t.total_amount, s.amount_paid,
                            s.change_amount, COUNT(ti.id) AS line_items, COALESCE(SUM(ti.quantity), 0) AS units_sold
                     FROM transactions t
                     LEFT JOIN sales s ON s.transaction_id = t.id
                     LEFT JOIN users u ON u.id = t.user_id
                     LEFT JOIN transaction_items ti ON ti.transaction_id = t.id
                     GROUP BY t.id, t.created_at, u.first_name, u.last_name, s.status, s.payment_method,
                              s.gcash_reference, t.total_amount, s.amount_paid, s.change_amount
                     ORDER BY t.created_at DESC",
        ],
        'inventory_csv' => [
            'filename' => "inventory_export_{$stamp}.csv",
            'sql' => "SELECT p.id, p.sku, p.barcode, p.name, c.name AS category, p.unit, p.stock,
                            p.cost_price, p.price, (p.stock * p.cost_price) AS stock_cost_value,
                            (p.stock * p.price) AS stock_retail_value, p.expiration_date, p.status,
                            p.created_at, p.updated_at
                     FROM products p
                     LEFT JOIN categories c ON c.id = p.category_id
                     WHERE p.deleted_at IS NULL
                     ORDER BY c.name, p.name",
        ],
        'users_csv' => [
            'filename' => "users_export_{$stamp}.csv",
            'sql' => "SELECT id, first_name, last_name, email, role, position, is_active, created_at, updated_at
                     FROM users ORDER BY role, first_name, last_name",
        ],
        'activity_csv' => [
            'filename' => "inventory_activity_export_{$stamp}.csv",
            'sql' => "SELECT h.id, h.created_at, h.action, h.product_id, h.product_name,
                            CONCAT(u.first_name, ' ', u.last_name) AS performed_by, u.role, h.changes
                     FROM inventory_history h
                     LEFT JOIN users u ON u.id = h.user_id
                     ORDER BY h.created_at DESC",
        ],
    ];

    $export = $exports[$type];
    stream_csv($pdo, $export['sql'], $export['filename']);
} catch (Throwable $e) {
    error_log('Admin data export failed: ' . $e->getMessage());
    if (!headers_sent()) security_json_error('Unable to generate the requested download.', 500);
    exit;
}

function stream_database_backup(PDO $pdo, string $filename): void
{
    set_time_limit(0);
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    echo "-- Espenida POS database backup\n";
    echo "-- Generated: " . date(DATE_ATOM) . "\n\n";
    echo "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";

    $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
    foreach ($tables as $tableRow) {
        $table = $tableRow[0];
        $quotedTable = '`' . str_replace('`', '``', $table) . '`';
        $createRow = $pdo->query("SHOW CREATE TABLE {$quotedTable}")->fetch(PDO::FETCH_NUM);

        echo "-- Table: {$quotedTable}\n";
        echo "DROP TABLE IF EXISTS {$quotedTable};\n";
        echo $createRow[1] . ";\n\n";

        $stmt = $pdo->query("SELECT * FROM {$quotedTable}");
        $batch = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $values = array_map(static function ($value) use ($pdo) {
                return $value === null ? 'NULL' : $pdo->quote((string)$value);
            }, array_values($row));
            $batch[] = '(' . implode(', ', $values) . ')';

            if (count($batch) >= 100) {
                echo "INSERT INTO {$quotedTable} VALUES\n" . implode(",\n", $batch) . ";\n";
                $batch = [];
            }
        }
        if ($batch) echo "INSERT INTO {$quotedTable} VALUES\n" . implode(",\n", $batch) . ";\n";
        echo "\n";
    }

    echo "SET FOREIGN_KEY_CHECKS=1;\n";
    exit;
}

function stream_csv(PDO $pdo, string $sql, string $filename): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $output = fopen('php://output', 'wb');
    fwrite($output, "\xEF\xBB\xBF");
    $stmt = $pdo->query($sql);
    $headerWritten = false;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!$headerWritten) {
            fputcsv($output, array_keys($row));
            $headerWritten = true;
        }
        fputcsv($output, array_map('csv_safe_value', array_values($row)));
    }
    if (!$headerWritten) fputcsv($output, ['No records found']);
    fclose($output);
    exit;
}

function csv_safe_value($value): string
{
    $text = (string)($value ?? '');
    return preg_match('/^[=+\-@]/', $text) ? "'" . $text : $text;
}

