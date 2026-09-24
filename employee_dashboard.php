<?php
session_start();
require_once __DIR__ . '/includes/security.php';

// ── Auth guard ────────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    $_SESSION['toast_message'] = [
        'type'    => 'warning',
        'title'   => 'Access Denied!',
        'message' => 'Please login first.',
    ];
    header("Location: Login.php");
    exit();
}

// Owners go to the admin dashboard — this page is employees only
if ($_SESSION['role'] === 'owner') {
    header("Location: dashboard.php");
    exit();
}

require_once 'config/database.php';

$user_id    = $_SESSION['user_id'];
$first_name = $_SESSION['first_name'] ?? 'User';
$last_name  = $_SESSION['last_name']  ?? '';
$role       = $_SESSION['role']       ?? 'employee';
$position   = $_SESSION['position']   ?? '';
$email      = $_SESSION['email']      ?? '';

$toast_message = $_SESSION['toast_message'] ?? null;
if ($toast_message) unset($_SESSION['toast_message']);

$pdo = getDBConnection();

// ── Employee-scoped stats (today only, tied to this cashier) ──────────────────

// Daily sales by this cashier
// Joining `sales` and `transaction_items` here would repeat each
// transaction once per line item and once per sale row, multiplying the
// totals. Filter with EXISTS and total the line items in a subquery instead.
$dailyStmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(t.total_amount), 0) AS daily_sales,
        COUNT(*)                         AS orders_today,
        COALESCE(SUM((
            SELECT COALESCE(SUM(ti.quantity), 0)
            FROM transaction_items ti
            WHERE ti.transaction_id = t.id
        )), 0)                           AS items_sold
    FROM transactions t
    WHERE DATE(t.created_at) = CURDATE()
      AND t.user_id = :uid
      AND EXISTS (
          SELECT 1 FROM sales s
          WHERE s.transaction_id = t.id
            AND s.status = 'completed'
      )
");
// Matched by account, not by the name on the sale, so a renamed cashier keeps their sales
$dailyStmt->execute([':uid' => (int)$user_id]);
$dailyRow   = $dailyStmt->fetch(PDO::FETCH_ASSOC);
$daily_sales  = (float)($dailyRow['daily_sales']  ?? 0);
$orders_today = (int)  ($dailyRow['orders_today'] ?? 0);
$items_sold   = (int)  ($dailyRow['items_sold']   ?? 0);

// Total customers served today (each transaction = 1 customer)
$customers_today = $orders_today;

// ── This employee's own past transactions (paginated) ─────────────────────────
$tx_per_page = 10;

$txCountStmt = $pdo->prepare("SELECT COUNT(*) FROM transactions t JOIN sales s ON s.transaction_id = t.id WHERE t.user_id = :uid");
$txCountStmt->execute([':uid' => (int)$user_id]);
$tx_total = (int)$txCountStmt->fetchColumn();
$tx_pages = max(1, (int)ceil($tx_total / $tx_per_page));
$tx_page = min($tx_pages, max(1, (int)($_GET['tx_page'] ?? 1)));
$tx_offset = ($tx_page - 1) * $tx_per_page;

$myTxStmt = $pdo->prepare("
    SELECT
        t.id                                        AS raw_id,
        CONCAT('TRX-', LPAD(t.id, 6, '0'))         AS trx_id,
        t.total_amount                              AS amount,
        COALESCE(s.status, 'completed')             AS status,
        DATE_FORMAT(t.created_at, '%b %d, %Y')     AS date,
        DATE_FORMAT(t.created_at, '%h:%i %p')      AS time,
        (SELECT COUNT(*) FROM transaction_items ti WHERE ti.transaction_id = t.id) AS item_count
    FROM transactions t
    JOIN sales s ON s.transaction_id = t.id
    WHERE t.user_id = :uid
    ORDER BY t.created_at DESC, t.id DESC
    LIMIT $tx_per_page OFFSET $tx_offset
");
$myTxStmt->execute([':uid' => (int)$user_id]);
$my_transactions = $myTxStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/x-icon" href="assets/images/favicon.ico?v=20260923">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/images/favicon-32x32.png?v=20260923">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/images/favicon-16x16.png?v=20260923">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/images/apple-touch-icon.png?v=20260923">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(security_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <script src="assets/js/security.js?v=20260814-1" defer></script>
    <title>Dashboard · Espenida's Pet & Poultry Supply</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="assets/css/dashboardCSS.css">
    <link rel="stylesheet" href="assets/css/usermod.css">
    <link rel="stylesheet" href="assets/css/emp.css?v=<?= filemtime(__DIR__ . '/assets/css/emp.css') ?>">
    <link rel="stylesheet" href="assets/css/notif.css?v=<?= filemtime(__DIR__ . '/assets/css/notif.css') ?>">
    <link rel="stylesheet" href="assets/css/responsive.css">
    <script src="assets/js/responsive.js?v=<?= filemtime(__DIR__ . '/assets/js/responsive.js') ?>"></script>
    <link rel="stylesheet" href="assets/css/sidebar.css?v=<?= filemtime(__DIR__ . '/assets/css/sidebar.css') ?>">
</head>
<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="fas fa-bars"></i>
    </button>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <div class="toast-container" id="toastContainer"></div>

    <?php $sidebar_account_modals = true; ?>
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <!-- ═══ MAIN CONTENT ═══ -->
    <div class="main-content">

        <!-- Welcome Banner -->
        <div class="welcome-banner dashboard-banner">
            <img src="assets/images/brand-logos.svg" alt="" aria-hidden="true" class="welcome-banner-art">
            <div class="welcome-banner-inner">
                <div class="welcome-text">
                    <h1>Welcome back, <?php echo htmlspecialchars($first_name); ?></h1>
                    <p>
                        <?php echo date('l, F j, Y'); ?> · <?php echo htmlspecialchars($position ?: ucfirst($role)); ?>
                    </p>
                </div>
                <div class="welcome-banner-actions">
                    <!-- Notification Bell -->
                    <div class="notif-bell-wrap" id="notifBellWrap">
                        <button class="notif-bell-btn" id="notifBellBtn" aria-label="Notifications">
                            <i class="fas fa-bell"></i>
                            <span class="notif-badge" id="notifBadge" style="display:none;">0</span>
                        </button>
                        <div class="notif-panel" id="notifPanel">
                            <div class="notif-panel-header">
                                <span><i class="fas fa-bell me-2"></i>Notifications</span>
                                <button class="notif-mark-all" id="notifMarkAll">Mark all read</button>
                            </div>
                            <div class="notif-list" id="notifList">
                                <div class="notif-empty" id="notifEmpty">
                                    <i class="fas fa-check-circle"></i>
                                    <p>You're all caught up!</p>
                                </div>
                            </div>
                            <div class="notif-panel-footer" id="notifFooter" style="display:none;">
                                <span id="notifFooterText"></span>
                            </div>
                        </div>
                    </div>
                    <div class="date-display">
                        <i class="fas fa-calendar-alt"></i><?php echo date('M d, Y'); ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions (POS, Product Category, Archive) -->
        <div class="quick-actions-grid">
            <a href="PosUI_db.php" class="quick-action-card">
                <div class="quick-action-icon si-green">
                    <i class="fas fa-cash-register"></i>
                </div>
                <div class="quick-action-text">
                    <span class="quick-action-label">New Sale</span>
                    <small class="quick-action-desc">Open POS</small>
                </div>
            </a>
            <a href="categories.php" class="quick-action-card">
                <div class="quick-action-icon si-brown">
                    <i class="fas fa-layer-group"></i>
                </div>
                <div class="quick-action-text">
                    <span class="quick-action-label">Product Categories</span>
                    <small class="quick-action-desc">Browse inventory</small>
                </div>
            </a>
            <a href="Archive_products.php" class="quick-action-card">
                <div class="quick-action-icon si-brown">
                    <i class="fa-solid fa-box-archive"></i>
                </div>
                <div class="quick-action-text">
                    <span class="quick-action-label">Archive</span>
                    <small class="quick-action-desc">Archived items</small>
                </div>
            </a>
        </div>

        <!-- Stats (employee-scoped: today only) -->
        <div class="stats-grid">
            <?php
            $emp_cards = [
                ['tone' => 'revenue',   'icon' => 'fa-peso-sign',    'label' => 'My Sales Today',   'value' => '₱' . number_format($daily_sales),      'note' => date('M d, Y')],
                ['tone' => 'customers', 'icon' => 'fa-users',        'label' => 'Customers Today',  'value' => number_format($customers_today),         'note' => 'Transactions served'],
                ['tone' => 'average',   'icon' => 'fa-boxes-stacked','label' => 'Items Sold Today', 'value' => number_format($items_sold),              'note' => 'Units processed'],
                ['tone' => 'orders',    'icon' => 'fa-receipt',      'label' => 'Orders Today',     'value' => number_format($orders_today),            'note' => 'Completed transactions'],
            ];
            foreach ($emp_cards as $card): ?>
            <div class="stat-card">
                <div class="stat-head">
                    <span class="stat-label"><?php echo $card['label']; ?></span>
                    <span class="stat-icon <?php echo $card['tone']; ?>"><i class="fa-solid <?php echo $card['icon']; ?>"></i></span>
                </div>
                <div class="stat-value"><?php echo $card['value']; ?></div>
                <div class="stat-change"><span><?php echo htmlspecialchars($card['note']); ?></span></div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- My Transactions Table -->
        <div class="table-container my-tx-container" id="my-transactions">
            <div class="section-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                <h5 style="margin:0;font-size:16px;font-weight:600;color:#1e293b;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <i class="fas fa-history" style="color:#8B4513;"></i>
                    My Transactions
                    <span style="font-size:11px;font-weight:400;color:#64748b;margin-left:4px;">— your personal transaction history</span>
                </h5>
                <!-- "View All" to full sales page removed — employees see only their own records here -->
            </div>

            <?php if (empty($my_transactions)): ?>
            <div class="text-center py-5" style="color:#94a3b8;">
                <i class="fas fa-receipt fa-3x mb-3"></i>
                <p class="mb-0">No transactions found.</p>
                <small>Transactions you process in the POS will appear here.</small>
            </div>
            <?php else: ?>
            <div class="my-tx-scroll">
            <table class="custom-table my-tx-table">
                <thead>
                    <tr>
                        <th>Transaction ID</th>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Items</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Receipt</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($my_transactions as $tx): ?>
                    <tr>
                        <td data-label="Transaction"><strong style="color:#8B4513;"><?php echo htmlspecialchars($tx['trx_id']); ?></strong></td>
                        <td data-label="Date"><?php echo htmlspecialchars($tx['date']); ?></td>
                        <td data-label="Time" style="color:#64748b;"><?php echo htmlspecialchars($tx['time']); ?></td>
                        <td data-label="Items">
                            <span style="background:#f1f5f9;padding:2px 8px;border-radius:20px;font-size:12px;font-weight:600;">
                                <?php echo $tx['item_count']; ?> item<?php echo $tx['item_count'] != 1 ? 's' : ''; ?>
                            </span>
                        </td>
                        <td data-label="Amount"><strong style="color:#2c5530;">₱<?php echo number_format((float)$tx['amount'], 2); ?></strong></td>
                        <td data-label="Status">
                            <?php
                            $statusMap = [
                                'completed' => ['class' => 'status-completed', 'label' => 'Completed'],
                                'voided'    => ['class' => 'status-critical',  'label' => 'Voided'],
                                'pending'   => ['class' => 'status-pending',   'label' => 'Pending'],
                            ];
                            $s = $statusMap[$tx['status']] ?? ['class' => 'status-completed', 'label' => ucfirst($tx['status'])];
                            ?>
                            <span class="status-badge <?php echo $s['class']; ?>"><?php echo $s['label']; ?></span>
                        </td>
                        <td data-label="Receipt">
                            <button class="btn-view-receipt"
                                onclick="viewReceipt(<?php echo (int)$tx['raw_id']; ?>, '<?php echo htmlspecialchars($tx['trx_id']); ?>')">
                                <i class="fas fa-eye me-1"></i>View
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <?php
            $tx_from = $tx_offset + 1;
            $tx_to = $tx_offset + count($my_transactions);
            // Window of page numbers around the current page
            $tx_window_start = max(1, $tx_page - 2);
            $tx_window_end = min($tx_pages, $tx_page + 2);
            $txPageUrl = function ($n) {
                return '?' . htmlspecialchars(http_build_query(array_merge($_GET, ['tx_page' => $n]))) . '#my-transactions';
            };
            ?>
            <div class="my-tx-pagination">
                <span class="my-tx-count">Showing <?php echo $tx_from; ?>–<?php echo $tx_to; ?> of <?php echo number_format($tx_total); ?></span>
                <?php if ($tx_pages > 1): ?>
                <nav aria-label="Transaction pages">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?php echo $tx_page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo $txPageUrl($tx_page - 1); ?>" aria-label="Previous"><i class="fas fa-chevron-left"></i></a>
                        </li>
                        <?php if ($tx_window_start > 1): ?>
                            <li class="page-item"><a class="page-link" href="<?php echo $txPageUrl(1); ?>">1</a></li>
                            <?php if ($tx_window_start > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                        <?php endif; ?>
                        <?php for ($n = $tx_window_start; $n <= $tx_window_end; $n++): ?>
                            <li class="page-item <?php echo $n === $tx_page ? 'active' : ''; ?>" <?php echo $n === $tx_page ? 'aria-current="page"' : ''; ?>>
                                <a class="page-link" href="<?php echo $txPageUrl($n); ?>"><?php echo $n; ?></a>
                            </li>
                        <?php endfor; ?>
                        <?php if ($tx_window_end < $tx_pages): ?>
                            <?php if ($tx_window_end < $tx_pages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                            <li class="page-item"><a class="page-link" href="<?php echo $txPageUrl($tx_pages); ?>"><?php echo $tx_pages; ?></a></li>
                        <?php endif; ?>
                        <li class="page-item <?php echo $tx_page >= $tx_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo $txPageUrl($tx_page + 1); ?>" aria-label="Next"><i class="fas fa-chevron-right"></i></a>
                        </li>
                    </ul>
                </nav>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- /.main-content -->

    <!-- ═══ RECEIPT MODAL ═══ -->
    <div class="modal fade receipt-modal" id="receiptModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-md">
            <div class="modal-content">
                <!-- Header filled by JS -->
                <div class="receipt-header" id="receiptModalHeader">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="receipt-store-name"><i class="fas fa-paw me-2"></i>Espenida's</div>
                            <div class="receipt-sub">Pet & Poultry Supply</div>
                        </div>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="mt-3">
                        <div style="font-size:11px;opacity:.7;text-transform:uppercase;letter-spacing:1px;">Transaction</div>
                        <div class="receipt-trx-id" id="receiptTrxId">—</div>
                    </div>
                </div>

                <!-- Body -->
                <div class="modal-body p-0">
                    <!-- Loading state -->
                    <div class="receipt-loading" id="receiptLoading">
                        <div class="spinner-border mb-3" role="status"></div>
                        <span style="font-size:13px;">Loading receipt…</span>
                    </div>

                    <!-- Populated receipt -->
                    <div id="receiptContent" style="display:none;">
                        <div id="receiptPrintArea">
                            <div class="receipt-body">
                                <!-- Meta info -->
                                <div class="mt-3 mb-3" id="receiptMeta"></div>

                                <hr class="receipt-divider">

                                <!-- Items -->
                                <div class="receipt-items-header d-flex">
                                    <span style="flex:1;">Product</span>
                                    <span style="width:50px;text-align:center;">Qty</span>
                                    <span style="width:80px;text-align:right;">Subtotal</span>
                                </div>
                                <div id="receiptItems"></div>

                                <hr class="receipt-divider">

                                <!-- Totals -->
                                <div class="receipt-total-section" id="receiptTotals"></div>

                                <!-- Notes -->
                                <div id="receiptNotes" style="display:none;margin-top:12px;font-size:12px;color:#64748b;background:#fffbeb;padding:8px 12px;border-radius:6px;border-left:3px solid #f59e0b;">
                                    <i class="fas fa-sticky-note me-1"></i><span id="receiptNotesText"></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Error state -->
                    <div id="receiptError" style="display:none;" class="text-center py-4 px-3">
                        <i class="fas fa-exclamation-circle fa-2x mb-2" style="color:#ef4444;"></i>
                        <p class="mb-0 text-danger" id="receiptErrorMsg">Failed to load receipt.</p>
                    </div>
                </div>

                <!-- Footer -->
                <div class="modal-footer py-2" id="receiptFooter" style="display:none!important;">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-sm btn-brown" onclick="printReceipt()">
                        <i class="fas fa-print me-1"></i>Print
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/includes/profile_modal.php'; ?>

    <?php include __DIR__ . '/includes/switch_account_modal.php'; ?>
    <script>
    const phpToastMessage = <?php echo $toast_message ? json_encode($toast_message) : 'null'; ?>;
    // ── Notification seed data (employee) ──────────────────────────────────────
    const phpNotifications = <?php
        $notifs       = [];  // priority items — shown at TOP
        $notif_alerts = [];  // inventory warnings — shown below

        // ── 1. Today's Activity (TOP) ──
        try {
            $todayEmpStmt = $pdo->query("
                SELECT COUNT(*) AS cnt
                FROM transactions t
                JOIN sales s ON s.transaction_id = t.id
                WHERE DATE(t.created_at) = CURDATE()
                  AND s.status = 'completed'
            ");
            $todayEmpCount = (int)($todayEmpStmt->fetchColumn() ?: 0);
            if ($todayEmpCount > 0) {
                $notifs[] = [
                    'id'    => 'today-activity-' . date('Ymd') . '-' . $todayEmpCount,
                    'type'  => 'success',
                    'icon'  => 'fa-chart-line',
                    'title' => "Today's Activity",
                    'body'  => $todayEmpCount . ' completed transaction' . ($todayEmpCount !== 1 ? 's' : '') . ' today.',
                    'time'  => date('h:i A'),
                    'read'  => false,
                ];
            }
        } catch (PDOException $e) { /* skip */ }

        // ── 2. Sales milestone nudge ──
        if ($orders_today >= 5) {
            $notifs[] = [
                'id'    => 'sales-milestone',
                'type'  => 'success',
                'icon'  => 'fa-trophy',
                'title' => 'Great Work!',
                'body'  => 'You\'ve completed ' . $orders_today . ' transaction' . ($orders_today != 1 ? 's' : '') . ' today. Keep it up!',
                'time'  => 'Today',
                'read'  => false,
            ];
        }

        // ── 3. Shift start greeting ──
        $notifs[] = [
            'id'    => 'shift-start',
            'type'  => 'info',
            'icon'  => 'fa-clock',
            'title' => 'Shift Started',
            'body'  => 'Good ' . (date('H') < 12 ? 'morning' : (date('H') < 17 ? 'afternoon' : 'evening')) . ', ' . htmlspecialchars($first_name) . '! Have a great shift.',
            'time'  => date('h:i A'),
            'read'  => false,
        ];

        // ── 4. Low-stock alerts (below priority items) ──
        try {
            $lowStockStmt = $pdo->query("
                SELECT p.name AS product, p.stock,
                       CASE WHEN p.stock <= 5 THEN 'critical' ELSE 'low' END AS status
                FROM products p
                WHERE p.deleted_at IS NULL AND p.archived_at IS NULL AND p.stock < 10
                ORDER BY p.stock ASC LIMIT 6
            ");
            foreach ($lowStockStmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $lvl = $a['status'] === 'critical' ? 'critical' : 'warning';
                $notif_alerts[] = [
                    'id'   => 'stock-' . preg_replace('/\W/', '-', strtolower($a['product'])),
                    'type' => $lvl,
                    'icon' => $lvl === 'critical' ? 'fa-exclamation-circle' : 'fa-exclamation-triangle',
                    'title'=> $a['product'],
                    'body' => 'Stock at ' . $a['stock'] . ' unit' . ($a['stock'] != 1 ? 's' : '') . ' — below threshold',
                    'time' => 'Just now',
                    'read' => false,
                ];
            }
        } catch (PDOException $e) { /* skip */ }

        // Merge: priority first, then alerts
        echo json_encode(array_merge($notifs, $notif_alerts));
    ?>;
    </script>
    <script src="assets/js/receipt.js?v=<?= filemtime(__DIR__ . '/assets/js/receipt.js') ?>"></script>
    <script src="assets/js/emp.js?v=<?= filemtime(__DIR__ . '/assets/js/emp.js') ?>"></script>
    <script src="assets/js/notif.js?v=<?= filemtime(__DIR__ . '/assets/js/notif.js') ?>"></script>
    <script src="assets/js/sidebar-nav.js"></script>
</body>
</html>