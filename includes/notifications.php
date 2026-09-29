<?php
require_once __DIR__ . '/product_units.php'; // format_unit_amount() for low-stock alerts
/**
 * includes/notifications.php
 * ──────────────────────────────────────────────────────────────────────────
 * Shared notification data generator for the notification bell.
 * Include this file AFTER you have a $pdo (or call getDBConnection()),
 * and AFTER session variables ($role, $first_name, etc.) are available.
 *
 * It outputs ONE <script> block that declares:
 *   const phpNotifications = [ ... ];
 *
 * Works for both owner (dashboard.php style) and employee pages.
 * ──────────────────────────────────────────────────────────────────────────
 */

// Ensure we have a PDO connection — support both $pdo and $db from config.php
if (!isset($pdo)) {
    if (isset($db) && $db instanceof PDO) {
        $pdo = $db;
    } else {
        require_once __DIR__ . '/../config/database.php';
        $pdo = getDBConnection();
    }
}

// Gather role info from session (should already be set by calling page)
$_notif_role       = $_SESSION['role']       ?? 'employee';
$_notif_first_name = $_SESSION['first_name'] ?? 'User';
$_notif_last_name  = $_SESSION['last_name']  ?? '';
$_notif_is_owner   = ($_notif_role === 'owner' || $_notif_role === 'admin');

$_notif_items  = [];  // priority items shown at TOP
$_notif_alerts = [];  // inventory warnings shown BELOW

// ─── 1. Latest transaction — shown FIRST at top (OWNERS ONLY) ────────────
if ($_notif_is_owner) {
    try {
        $recentTxStmt = $pdo->query("
            SELECT
                CONCAT('#TRX-', LPAD(t.id, 5, '0'))    AS trx_id,
                COALESCE(s.cashier_name, 'N/A')         AS cashier,
                t.total_amount                           AS amount,
                COALESCE(s.status, 'completed')          AS status,
                DATE_FORMAT(t.created_at, '%b %d, %Y')  AS date_str
            FROM transactions t
            LEFT JOIN sales s ON s.transaction_id = t.id
            ORDER BY t.created_at DESC
            LIMIT 1
        ");
        $latestTx = $recentTxStmt->fetch(PDO::FETCH_ASSOC);

        if ($latestTx) {
            $_notif_items[] = [
                'id'   => 'trx-' . preg_replace('/\W/', '', $latestTx['trx_id']),
                'type' => 'info',
                'icon' => 'fa-receipt',
                'title'=> 'New Transaction',
                'body' => 'Transaction ' . $latestTx['trx_id'] . ' processed by ' . $latestTx['cashier']
                         . ' — ₱' . number_format((float)$latestTx['amount'], 2),
                'time' => $latestTx['date_str'] ?? 'Today',
                'read' => false,
            ];
        }
    } catch (PDOException $e) {
        error_log('Notification: recent-tx query failed — ' . $e->getMessage());
    }
}

// ─── 2. Today's activity count — shown SECOND at top ──────────────────
try {
    $todayTxStmt = $pdo->query("
        SELECT COUNT(*) AS cnt
        FROM transactions t
        JOIN sales s ON s.transaction_id = t.id
        WHERE DATE(t.created_at) = CURDATE()
          AND s.status = 'completed'
    ");
    $todayCount = (int)($todayTxStmt->fetchColumn() ?: 0);

    if ($todayCount > 0) {
        $_notif_items[] = [
            'id'   => 'today-sales-summary-' . date('Ymd') . '-' . $todayCount,
            'type' => 'success',
            'icon' => 'fa-chart-line',
            'title'=> 'Today\'s Activity',
            'body' => $todayCount . ' completed transaction' . ($todayCount !== 1 ? 's' : '') . ' today.',
            'time' => date('h:i A'),
            'read' => false,
        ];
    }
} catch (PDOException $e) {
    error_log('Notification: today-tx-count query failed — ' . $e->getMessage());
}

// ─── 3. Low-stock alerts (stock < 10) — shown to ALL roles ─────────────
try {
    $alertStmt = $pdo->query("
        SELECT
            p.name    AS product,
            p.stock,
            p.unit,
            10        AS threshold,
            CASE WHEN p.stock <= 5 THEN 'critical' ELSE 'low' END AS status
        FROM products p
        WHERE p.deleted_at IS NULL
          AND p.archived_at IS NULL
          AND p.stock < 10
        ORDER BY p.stock ASC
        LIMIT 6
    ");
    $inventory_alerts = $alertStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($inventory_alerts as $a) {
        $lvl = $a['status'] === 'critical' ? 'critical' : 'warning';
        $_notif_alerts[] = [
            'id'   => 'stock-' . preg_replace('/\W/', '-', strtolower($a['product'])),
            'type' => $lvl,
            'icon' => $lvl === 'critical' ? 'fa-exclamation-circle' : 'fa-exclamation-triangle',
            'title'=> $a['product'],
            'body' => 'Stock at ' . format_unit_amount($a['stock'], $a['unit'] ?? '') . ' — below threshold (' . $a['threshold'] . ')',
            'time' => 'Just now',
            'read' => false,
        ];
    }
} catch (PDOException $e) {
    error_log('Notification: low-stock query failed — ' . $e->getMessage());
}

// ─── 4. Expired stock removed — written off automatically in the last 7 days ─
// Expired batches leave inventory on their own (includes/inventory_expiry.php),
// so products no longer sit in an "expired" state; report the write-offs instead.
try {
    $expiredStmt = $pdo->query("
        SELECT h.id, h.product_name, h.changes, h.created_at
        FROM inventory_history h
        WHERE h.action = 'expired'
          AND h.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY h.created_at DESC
        LIMIT 6
    ");
    foreach ($expiredStmt->fetchAll(PDO::FETCH_ASSOC) as $ex) {
        $_notif_alerts[] = [
            'id'   => 'expired-removed-' . (int)$ex['id'],
            'type' => 'critical',
            'icon' => 'fa-calendar-times',
            'title'=> $ex['product_name'] ?: 'Expired stock',
            'body' => preg_replace('/^Expired stock removed: -/', 'Removed ', (string)$ex['changes']),
            'time' => date('M d', strtotime($ex['created_at'])),
            'read' => false,
        ];
    }
} catch (PDOException $e) {
    error_log('Notification: expired-stock query failed — ' . $e->getMessage());
}

// ─── 5. Expiring soon — within 30 days ─────────────────────────────────
try {
    $expSoonStmt = $pdo->query("
        SELECT
            p.name            AS product,
            p.expiration_date AS exp_date,
            DATEDIFF(p.expiration_date, CURDATE()) AS days_left
        FROM products p
        WHERE p.deleted_at IS NULL
          AND p.archived_at IS NULL
          AND p.expiration_date IS NOT NULL
          AND p.expiration_date >= CURDATE()
          AND p.expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ORDER BY p.expiration_date ASC
        LIMIT 6
    ");
    $expiring_soon = $expSoonStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($expiring_soon as $es) {
        $daysLeft = (int)$es['days_left'];
        $dateStr  = date('M d, Y', strtotime($es['exp_date']));
        $lvl      = $daysLeft <= 7 ? 'critical' : 'warning';
        $_notif_alerts[] = [
            'id'   => 'expiring-' . preg_replace('/\W/', '-', strtolower($es['product'])),
            'type' => $lvl,
            'icon' => 'fa-clock',
            'title'=> $es['product'],
            'body' => 'Expires in ' . $daysLeft . ' day' . ($daysLeft != 1 ? 's' : '') . ' ('. $dateStr .')',
            'time' => $daysLeft <= 7 ? 'Urgent' : 'Soon',
            'read' => false,
        ];
    }
} catch (PDOException $e) {
    error_log('Notification: expiring-soon query failed — ' . $e->getMessage());
}

// ─── Merge: priority items first, then inventory alerts ────────────────
$_notif_items = array_merge($_notif_items, $_notif_alerts);

// ─── Output the JS constant ────────────────────────────────────────────
?>
const phpNotifications = <?php echo json_encode($_notif_items, JSON_UNESCAPED_UNICODE); ?>;
