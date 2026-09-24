<?php
session_start();
require_once __DIR__ . '/includes/security.php';
if (!isset($_SESSION['user_id'])) {
    $_SESSION['toast_message'] = [
        'type'    => 'warning',
        'title'   => 'Access Denied!',
        'message' => 'Please login first to access the dashboard.',
    ];
    header("Location: Login.php");
    exit();
}

// Employees have their own dashboard — redirect them away from the admin one
if (isset($_SESSION['role']) && $_SESSION['role'] !== 'owner') {
    header("Location: employee_dashboard.php");
    exit();
} 

require_once 'config/database.php';

$user_id    = $_SESSION['user_id'];
$first_name = $_SESSION['first_name'] ?? 'User';
$last_name  = $_SESSION['last_name']  ?? '';
$role       = $_SESSION['role']       ?? 'employee';
$position   = $_SESSION['position']   ?? '';
$email      = $_SESSION['email']      ?? '';

// Local logout removed - now handled by logout.php

$toast_message = $_SESSION['toast_message'] ?? null;
if ($toast_message) unset($_SESSION['toast_message']);

$is_owner = ($role === 'owner');

// ─── REAL DATABASE QUERIES ────────────────────────────────────────────────

$pdo = getDBConnection();

// ─── FIGURES ──────────────────────────────────────────────────────────────
// A transaction counts unless it has a voided sale against it. This is
// written as NOT EXISTS rather than a JOIN on `sales` on purpose: several
// transactions share id = 0 (the table has no primary key), and a join would
// multiply those rows into every SUM.
$notVoided = "NOT EXISTS (SELECT 1 FROM sales s WHERE s.transaction_id = t.id AND s.status = 'voided')";

$salesStmt = $pdo->query("
    SELECT
        COALESCE(SUM(CASE WHEN DATE(t.created_at) = CURDATE() THEN t.total_amount END), 0)                   AS today,
        COALESCE(SUM(CASE WHEN t.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)  THEN t.total_amount END), 0) AS week,
        COALESCE(SUM(CASE WHEN t.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN t.total_amount END), 0) AS month,
        COALESCE(SUM(CASE WHEN YEAR(t.created_at) = YEAR(NOW())                 THEN t.total_amount END), 0) AS year,

        -- Count rows, not DISTINCT ids: duplicate ids are real, separate sales
        SUM(CASE WHEN DATE(t.created_at) = CURDATE() THEN 1 ELSE 0 END)                                      AS orders_today,
        SUM(CASE WHEN DATE(t.created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY) THEN 1 ELSE 0 END)            AS orders_yesterday,
        COUNT(*)                                                                                             AS total_transactions,
        SUM(CASE WHEN t.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END)                      AS transactions_week,

        COALESCE(SUM(CASE WHEN DATE(t.created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                     THEN t.total_amount END), 0)                                                            AS sales_yesterday,

        -- Average order value over a stated window, so the figure and the
        -- trend beneath it describe the same thing
        COALESCE(AVG(CASE WHEN t.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                     THEN t.total_amount END), 0)                                                            AS avg_order_30d,
        COALESCE(AVG(CASE WHEN t.created_at <  DATE_SUB(NOW(), INTERVAL 30 DAY)
                           AND t.created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)
                     THEN t.total_amount END), 0)                                                            AS avg_order_prev30
    FROM transactions t
    WHERE $notVoided
");
$salesRow = $salesStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$sales_data = [
    'today'        => (float)($salesRow['today'] ?? 0),
    'week'         => (float)($salesRow['week'] ?? 0),
    'month'        => (float)($salesRow['month'] ?? 0),
    'year'         => (float)($salesRow['year'] ?? 0),
    'orders_today' => (int)($salesRow['orders_today'] ?? 0),
    'transactions' => (int)($salesRow['total_transactions'] ?? 0),
    'avg_order'    => (float)($salesRow['avg_order_30d'] ?? 0),
];

/**
 * Compare a current figure against a baseline and describe the movement.
 * When there is no baseline the card says so rather than inventing a number,
 * which is what the hard-coded "+12% from yesterday" strings used to do.
 *
 * $label is the comparison window, e.g. "vs yesterday".
 */
function trend_percent($current, $baseline, $label) {
    $baseline = (float)$baseline;
    $current  = (float)$current;

    if ($baseline <= 0) {
        return ['dir' => 'flat', 'text' => 'No prior data to compare'];
    }

    $change = (($current - $baseline) / $baseline) * 100;
    $dir    = $change >= 0.05 ? 'up' : ($change <= -0.05 ? 'down' : 'flat');

    if ($dir === 'flat') {
        return ['dir' => 'flat', 'text' => 'Unchanged ' . $label];
    }

    // PHP only expands \u{...} inside double quotes. Written as '\u2212' in a
    // single-quoted string it printed the escape itself onto the card.
    $sign = $change > 0 ? '+' : "\u{2212}";
    return ['dir' => $dir, 'text' => $sign . number_format(abs($change), 1) . '% ' . $label];
}

/**
 * Describe a plain count over a window, e.g. transactions in the last 7 days.
 */
function trend_count($count, $label) {
    $count = (int)$count;
    if ($count === 0) {
        return ['dir' => 'flat', 'text' => 'None ' . $label];
    }
    return ['dir' => 'up', 'text' => '+' . number_format($count) . ' ' . $label];
}

$trends = [
    'sales'     => trend_percent($sales_data['today'], $salesRow['sales_yesterday'] ?? 0, 'vs yesterday'),
    'orders'    => trend_percent($sales_data['orders_today'], $salesRow['orders_yesterday'] ?? 0, 'vs yesterday'),
    'customers' => trend_count($salesRow['transactions_week'] ?? 0, 'in the last 7 days'),
    'avg_order' => trend_percent($salesRow['avg_order_30d'] ?? 0, $salesRow['avg_order_prev30'] ?? 0, 'vs prior 30 days'),
];

// Low-stock alerts (stock < 10)
$alertStmt = $pdo->query("
    SELECT
        p.name    AS product,
        p.stock,
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

// Category sales breakdown (last 30 days).
// Line items carry their own created_at, so there is no need to join
// transactions or sales here - joining them multiplied the revenue of every
// transaction that shares id = 0, inflating this panel roughly fiftyfold.
$catStmt = $pdo->query("
    SELECT
        COALESCE(c.name, 'Uncategorised')        AS category,
        COALESCE(SUM(ti.quantity * ti.price), 0) AS revenue
    FROM transaction_items ti
    JOIN products p        ON p.id = ti.product_id
    LEFT JOIN categories c ON c.id = p.category_id
    WHERE ti.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
      AND NOT EXISTS (
          SELECT 1 FROM sales s
          WHERE s.transaction_id = ti.transaction_id AND s.status = 'voided'
      )
    GROUP BY c.id
    ORDER BY revenue DESC
    LIMIT 5
");
$cat_rows = $catStmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate category percentages
$cat_total = array_sum(array_column($cat_rows, 'revenue')) ?: 1;
$category_sales = array_map(function ($r) use ($cat_total) {
    return [
        'category'   => $r['category'],
        'revenue'    => (float)$r['revenue'],
        'percentage' => round(($r['revenue'] / $cat_total) * 100),
    ];
}, $cat_rows);

// Category colours, shared by the Sales by Category chart and the Category
// Performance list. Order is fixed (validated for colour-blind separation
// between neighbours) - append, don't reorder.
$chart_palette = ['#296a37', '#c48139', '#3174a7', '#a5492b', '#7e4d8a', '#829e4b'];

// Fetch users for role management (owners only)
$users = [];
if ($is_owner) {
    try {
        $stmt = $pdo->query("SELECT id, first_name, last_name, email, role, position, created_at FROM users ORDER BY id DESC");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching users: " . $e->getMessage());
    }
}
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="assets/css/dashboardCSS.css">
    <link rel="stylesheet" href="assets/css/reco.css">
    <link rel="stylesheet" href="assets/css/usermod.css">
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
    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>

    <?php $sidebar_account_modals = true; ?>
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    
    <!-- MAIN CONTENT AREA -->
    <div class="main-content">
        <!-- Welcome banner -->
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
                        <!-- Dropdown panel -->
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
        
        <!-- Quick actions -->
        <div class="quick-actions-grid">
            <a href="PosUI_db.php" class="quick-action-card">
                <div class="quick-action-icon">
                    <i class="fa-solid fa-bag-shopping"></i>
                </div>
                <div class="quick-action-text">
                    <span class="quick-action-label">New Sale</span>
                    <small class="quick-action-desc">Create transaction</small>
                </div>
            </a>
            <a href="categories.php" class="quick-action-card">
                <div class="quick-action-icon">
                    <i class="fas fa-plus-circle"></i>
                </div>
                <div class="quick-action-text">
                    <span class="quick-action-label">Add Product</span>
                    <small class="quick-action-desc">New inventory</small>
                </div>
            </a>
            <a href="sales_transactions.php" class="quick-action-card">
                <div class="quick-action-icon">
                    <i class="fas fa-chart-bar"></i>
                </div>
                <div class="quick-action-text">
                    <span class="quick-action-label">View Reports</span>
                    <small class="quick-action-desc">Analytics</small>
                </div>
            </a>
             <a href="archive_products.php" class="quick-action-card">
                <div class="quick-action-icon">
                    <i class="fa-solid fa-box-archive"></i>
                </div>
                <div class="quick-action-text">
                    <span class="quick-action-label">Archive</span>
                    <small class="quick-action-desc">Archive items</small>
                </div>
            </a>
        </div>
         
        <!-- Key figures -->
        <div class="stats-grid">
            <?php
            $stat_cards = [
                ['key' => 'sales',     'tone' => 'revenue',   'icon' => 'fa-peso-sign',  'label' => "Today's Sales",    'value' => '₱' . number_format($sales_data['today'])],
                ['key' => 'orders',    'tone' => 'orders',    'icon' => 'fa-cart-shopping', 'label' => 'Orders Today',     'value' => number_format($sales_data['orders_today'])],
                ['key' => 'customers', 'tone' => 'customers', 'icon' => 'fa-receipt',    'label' => 'Total Transactions', 'value' => number_format($sales_data['transactions'])],
                ['key' => 'avg_order', 'tone' => 'average',   'icon' => 'fa-chart-line', 'label' => 'Avg. Order Value', 'value' => '₱' . number_format($sales_data['avg_order'])],
            ];
            $trend_icon = ['up' => 'fa-arrow-trend-up', 'down' => 'fa-arrow-trend-down', 'flat' => 'fa-minus'];
            foreach ($stat_cards as $card):
                $t = $trends[$card['key']];
            ?>
            <div class="stat-card">
                <div class="stat-head">
                    <span class="stat-label"><?php echo $card['label']; ?></span>
                    <span class="stat-icon <?php echo $card['tone']; ?>"><i class="fa-solid <?php echo $card['icon']; ?>"></i></span>
                </div>
                <div class="stat-value"><?php echo $card['value']; ?></div>
                <div class="stat-change is-<?php echo $t['dir']; ?>">
                    <i class="fa-solid <?php echo $trend_icon[$t['dir']]; ?>"></i>
                    <span><?php echo htmlspecialchars($t['text']); ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Charts -->
        <div class="dashboard-grid">
            <div class="chart-container">
                <div class="chart-header">
                    <div class="chart-heading">
                        <div class="chart-title"><i class="fas fa-chart-column"></i> Sales Overview</div>
                        <div class="chart-summary">
                            <span class="chart-summary-value" id="salesTotal">&mdash;</span>
                            <span class="chart-summary-label" id="salesRangeLabel">last 7 days</span>
                        </div>
                    </div>
                    <div class="time-range">
                        <span class="range-btn" data-range="day">Day</span>
                        <span class="range-btn active" data-range="week">Week</span>
                        <span class="range-btn" data-range="month">Month</span>
                        <span class="range-btn" data-range="year">Year</span>
                    </div>
                </div>
                <div class="chart-wrapper" id="salesChartWrapper">
                    <canvas id="salesChart"></canvas>
                    <div class="chart-empty"><i class="fa-solid fa-receipt"></i><span>No sales in this period</span></div>
                </div>
            </div>

            <div class="chart-container">
                <div class="chart-header">
                    <div class="chart-heading">
                        <div class="chart-title"><i class="fas fa-tags"></i> Sales by Category</div>
                        <div class="chart-summary">
                            <span class="chart-summary-value" id="categoryTotal">&mdash;</span>
                            <span class="chart-summary-label">top 5 &middot; last 30 days</span>
                        </div>
                    </div>
                </div>
                <div class="chart-wrapper" id="categoryChartWrapper">
                    <canvas id="categoryChart"></canvas>
                    <div class="chart-empty"><i class="fa-solid fa-tags"></i><span>No category sales in the last 30 days</span></div>
                </div>
            </div>
        </div>
        
        <!-- Category performance and stock alerts -->
        <div class="dashboard-grid">
            <div class="chart-container scrollable-panel">
                <div class="chart-header">
                    <div class="chart-title"><i class="fas fa-chart-simple"></i> Category Performance</div>
                </div>
                <div class="scrollable-content">
                    <?php 
                    $colors = $chart_palette;
                    if (!$category_sales): ?>
                        <div class="panel-empty">
                            <i class="fa-solid fa-chart-simple"></i>
                            <p>No sales recorded in the last 30 days.</p>
                        </div>
                    <?php endif;
                    foreach ($category_sales as $index => $category): 
                    ?>
                    <div class="category-row">
                        <div class="category-item">
                            <span class="category-name">
                                <span class="category-color" style="--swatch: <?php echo $colors[$index % count($colors)]; ?>"></span>
                                <?php echo htmlspecialchars($category['category']); ?>
                            </span>
                            <span class="category-figures">
                                <strong><?php echo $category['percentage']; ?>%</strong>
                                <small>₱<?php echo number_format($category['revenue']); ?></small>
                            </span>
                        </div>
                        <div class="progress-bar-container">
                            <div class="progress-bar-fill" style="width: <?php echo $category['percentage']; ?>%; --swatch: <?php echo $colors[$index % count($colors)]; ?>"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="chart-container scrollable-panel">
                <div class="chart-header">
                    <div class="chart-title"><i class="fas fa-triangle-exclamation is-warning"></i> Low Stock Alerts</div>
                    <a href="categories.php" class="range-btn">Manage Stock</a>
                </div>
                <div class="scrollable-content">
                    <?php if (!$inventory_alerts): ?>
                        <div class="panel-empty">
                            <i class="fa-solid fa-circle-check"></i>
                            <p>Every product is above its stock threshold.</p>
                        </div>
                    <?php endif; ?>
                    <?php foreach ($inventory_alerts as $alert): ?>
                    <div class="alert-item">
                        <div>
                            <div class="alert-product"><?php echo htmlspecialchars($alert['product']); ?></div>
                            <div class="alert-stock">Stock: <strong><?php echo $alert['stock']; ?></strong> / <?php echo $alert['threshold']; ?></div>
                        </div>
                        <span class="status-badge status-<?php echo $alert['status']; ?>"><?php echo ucfirst($alert['status']); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/includes/profile_modal.php'; ?>

    <?php include __DIR__ . '/includes/user_roles_modal.php'; ?>

    <?php include __DIR__ . '/includes/switch_account_modal.php'; ?>

    <!-- Pass PHP data to JavaScript -->
    <script>
        const baseUrl = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/') + 1);
        const currentUser = { id: '<?php echo $user_id; ?>', firstName: '<?php echo addslashes($first_name); ?>', lastName: '<?php echo addslashes($last_name); ?>', email: '<?php echo addslashes($email); ?>', role: '<?php echo $role; ?>', position: '<?php echo addslashes($position); ?>' };
        const phpToastMessage = <?php echo $toast_message ? json_encode($toast_message) : 'null'; ?>;
        const isOwner = <?php echo $is_owner ? 'true' : 'false'; ?>;
        // Chart data from database
        const phpCategorySales = <?php echo json_encode($category_sales); ?>;
        const phpChartPalette = <?php echo json_encode($chart_palette); ?>;
        // ── Notification seed data (shared include) ─────────────────────────────
        <?php include 'includes/notifications.php'; ?>
    </script>
    <script src="assets/js/dashboardJS.js"></script>
    <script src="assets/js/userManagement.js?v=<?= filemtime(__DIR__ . '/assets/js/userManagement.js') ?>"></script>
    <script src="assets/js/notif.js?v=<?= filemtime(__DIR__ . '/assets/js/notif.js') ?>"></script>
    <script src="assets/js/sidebar-nav.js"></script>
</body>
</html>
