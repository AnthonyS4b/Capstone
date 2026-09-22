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

    $sign = $change > 0 ? '+' : '\u2212';
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
    <link rel="stylesheet" href="assets/css/notif.css">
    <link rel="stylesheet" href="assets/css/responsive.css">
    <script src="assets/js/responsive.js"></script>
</head>
<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="fas fa-bars"></i>
    </button>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>

    <!-- SIDEBAR -->
    <div class="sidebar" id="sidebar">
        <div class="store-brand">
            <div class="logo-container">
                <img src="assets/images/sidebar.jpg" alt="Espenida's Logo" class="store-logo-img">
            </div>
            <div class="store-name">
                <span class="store-name-main">Espenida's</span>
                <span class="store-name-sub">PET & POULTRY SUPPLY</span>
            </div>
        </div>
        
        <button class="collapse-btn" id="collapseBtn">
            <i class="fas fa-chevron-left" id="collapseIcon"></i>
            <span>Espenida Store</span>
        </button>

        <div class="section-title"><span>DASHBOARD</span></div>
        <button class="profile-btn" type="button" data-bs-toggle="modal" data-bs-target="#userProfileModal">
            <i class="fas fa-user-circle"></i><span>My Profile</span>
        </button>
        <button class="switch-account-btn" type="button" data-bs-toggle="modal" data-bs-target="#switchAccountModal">
            <i class="fas fa-exchange-alt"></i><span>Switch Account</span>
        </button>

        <div class="section-title"><span>POINT OF SALE</span></div>
        <div class="dropdown">
            <button class="dropdown-toggle" type="button" data-bs-toggle="dropdown">
                <i class="fas fa-cash-register"></i><span>Point of Sale</span>
            </button>
            <ul class="dropdown-menu">
                <li><a class="dropdown-item" href="PosUI_db.php"><i class="fas fa-cash-register"></i>Point of Sale</a></li>
                <li><a class="dropdown-item" href="sales_transactions.php"><i class="fas fa-history"></i>Sales Transactions</a></li>
            </ul>
        </div>

        <div class="section-title"><span>INVENTORY MANAGEMENT</span></div>
        <div class="dropdown">
            <button class="dropdown-toggle" type="button" data-bs-toggle="dropdown">
                <i class="fas fa-box"></i><span>Inventory Management</span>
            </button>
            <ul class="dropdown-menu">
                <li><a class="dropdown-item" href="categories.php"><i class="fa-solid fa-layer-group"></i>Inventory</a></li> 
                <li><a class="dropdown-item" href="Archive_products.php"><i class="fa-solid fa-box-archive"></i> Archive</a></li> 
                <li><a class="dropdown-item" href="Inventory_management.php"><i class="fa-solid fa-arrow-trend-down"></i>Log History</a></li> 
        </div>

        <div class="section-title"><span>RECOMMENDATIONS</span></div>
        <button class="recommendation-btn" id="recommendationBtn">
            <i class="fas fa-lightbulb"></i><span>Recommendations</span>
        </button>
        
        <?php if ($is_owner): ?>
            <div class="section-title"><span>SYSTEM & ADMIN</span></div>
            <div class="dropdown">
                <button class="dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    <i class="fa-solid fa-user"></i><span>System & Admin</span>
                </button>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#roleManagementModal"><i class="fas fa-lock"></i> User Roles</a></li>
                    <li><a class="dropdown-item" href="admin_data_tools.php"><i class="fas fa-database"></i> Backup &amp; Export</a></li>
                </ul>
            </div>
        <?php endif; ?>

        <div class="spacer"></div>
        <button class="logout-btn" id="logoutBtn">
            <i class="fas fa-sign-out-alt"></i><span>Log out</span>
        </button>
    </div>
    
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
                    <div class="chart-title"><i class="fas fa-chart-bar"></i> Sales Overview</div>
                    <div class="time-range">
                        <span class="range-btn" data-range="day">Day</span>
                        <span class="range-btn active" data-range="week">Week</span>
                        <span class="range-btn" data-range="month">Month</span>
                        <span class="range-btn" data-range="year">Year</span>
                    </div>
                </div>
                <div class="chart-wrapper"><canvas id="salesChart"></canvas></div>
            </div>
            
            <div class="chart-container">
                <div class="chart-header">
                    <div class="chart-title"><i class="fas fa-pie-chart"></i> Sales by Category</div>
                </div>
                <div class="chart-wrapper"><canvas id="categoryChart"></canvas></div>
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
                    $colors = ['#8B4513', '#2c5530', '#C47A3A', '#416937', '#d4a382'];
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

    <!-- Modals -->
    <div class="modal fade" id="userProfileModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-md">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h5 class="modal-title"><i class="fas fa-user-circle me-2"></i>My Profile</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-3">
                    <div class="d-flex align-items-center mb-3 p-2 bg-light rounded">
                        <div class="me-3"><i class="fas fa-user-circle fa-3x" style="color: #8B4513;"></i></div>
                        <div>
                            <h5 class="mb-1"><?php echo htmlspecialchars($first_name . ' ' . $last_name); ?></h5>
                            <div class="d-flex align-items-center">
                                <span class="badge bg-<?php echo $role === 'owner' ? 'danger' : 'secondary'; ?> me-2">
                                    <i class="fas fa-<?php echo $role === 'owner' ? 'crown' : 'user'; ?> me-1"></i>
                                    <?php echo ucfirst($role); ?>
                                </span>
                                <small class="text-muted">ID: <?php echo htmlspecialchars($user_id); ?></small>
                            </div>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6"><div class="p-2 border rounded"><small class="text-muted d-block">Position</small><span class="fw-bold"><?php echo htmlspecialchars($position ?: ucfirst($role)); ?></span></div></div>
                        <div class="col-6"><div class="p-2 border rounded"><small class="text-muted d-block">Email</small><span class="fw-bold small"><?php echo htmlspecialchars($email ?: 'Not provided'); ?></span></div></div>
                        <div class="col-6"><div class="p-2 border rounded"><small class="text-muted d-block">Member Since</small><span class="fw-bold"><?php echo date('M Y'); ?></span></div></div>
                        <div class="col-6"><div class="p-2 border rounded"><small class="text-muted d-block">Last Login</small><span class="fw-bold"><?php echo date('h:i A'); ?></span></div></div>
                    </div>
                    <div class="mb-2">
                        <button class="btn btn-sm btn-outline-secondary w-100 text-start" type="button" data-bs-toggle="collapse" data-bs-target="#permissionsCollapse">
                            <i class="fas fa-lock me-2"></i>View Permissions <i class="fas fa-chevron-down float-end"></i>
                        </button>
                        <div class="collapse mt-2" id="permissionsCollapse">
                            <div class="card card-body p-2">
                                <div class="row g-1">
                                    <?php
                                    $permissions = [
                                        'owner' => ['fa-crown' => 'Full Access', 'fa-users-cog' => 'User Mgmt', 'fa-chart-line' => 'Reports', 'fa-database' => 'Backup', 'fa-cog' => 'Settings'],
                                        'employee' => ['fa-cash-register' => 'POS', 'fa-box' => 'Inventory', 'fa-shopping-cart' => 'Orders', 'fa-search' => 'Search', 'fa-clipboard-list' => 'Sales']
                                    ];
                                    $current_permissions = $permissions[$role] ?? $permissions['employee'];
                                    foreach ($current_permissions as $icon => $permission): ?>
                                    <div class="col-6"><div class="small"><i class="fas <?php echo $icon; ?> text-brown me-1"></i><?php echo $permission; ?></div></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Change My PIN — available to all users for their own account -->
                    <div class="mb-2">
                        <button class="btn btn-sm btn-outline-secondary w-100 text-start" type="button"
                                data-bs-toggle="collapse" data-bs-target="#changePinCollapse">
                            <i class="fas fa-key me-2"></i>Change My PIN
                            <i class="fas fa-chevron-down float-end"></i>
                        </button>
                        <div class="collapse mt-2" id="changePinCollapse">
                            <div class="card card-body p-2">
                                <div class="mb-2">
                                    <label class="form-label small mb-1">Current PIN</label>
                                    <input type="password" class="form-control form-control-sm" id="currentPin"
                                           maxlength="4" pattern="\d{4}" placeholder="Enter current PIN">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small mb-1">New PIN</label>
                                    <input type="password" class="form-control form-control-sm" id="newPinSelf"
                                           maxlength="4" pattern="\d{4}" placeholder="4-digit new PIN">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small mb-1">Confirm New PIN</label>
                                    <input type="password" class="form-control form-control-sm" id="confirmPinSelf"
                                           maxlength="4" pattern="\d{4}" placeholder="Repeat new PIN">
                                </div>
                                <button class="btn btn-sm btn-brown w-100" onclick="changeOwnPin()">
                                    <i class="fas fa-save me-1"></i>Save New PIN
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="bg-light p-2 rounded small">
                        <div class="d-flex justify-content-between">
                            <span><i class="fas fa-clock me-1"></i>Session: <?php echo date('h:i A'); ?></span>
                            <span><i class="fas fa-globe me-1"></i><?php echo $_SERVER['REMOTE_ADDR']; ?></span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-brown" onclick="refreshProfileData()"><i class="fas fa-sync-alt me-1"></i>Refresh</button>
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <?php if ($is_owner): ?>
    <div class="modal fade" id="roleManagementModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title"><i class="fas fa-users-cog me-2"></i>User Role Management</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="row mb-4">
                        <div class="col-md-4"><div class="input-group"><span class="input-group-text"><i class="fas fa-search"></i></span><input type="text" class="form-control" id="userSearch" placeholder="Search users..."></div></div>
                        <div class="col-md-3"><select class="form-select" id="roleFilter"><option value="">All Roles</option><option value="owner">Owner</option><option value="employee">Employee</option></select></div>
                        <div class="col-md-5 text-end">
                            <button class="btn btn-success" onclick="addNewUser()"><i class="fas fa-user-plus me-2"></i>Add New User</button>
                            <button class="btn btn-brown" onclick="refreshUserList()"><i class="fas fa-sync-alt me-2"></i>Refresh</button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover" id="usersTable">
                            <thead><tr><th>ID</th><th>User</th><th>Email</th><th>Current Role</th><th>Position</th><th>Status</th><th>Actions</th></tr></thead>
                            <tbody id="usersTableBody">
                                <?php if (!empty($users)): foreach ($users as $user): ?>
                                <tr data-user-id="<?php echo $user['id']; ?>" data-role="<?php echo $user['role']; ?>">
                                    <td><span class="badge bg-secondary">#<?php echo $user['id']; ?></span></td>
                                    <td><div class="d-flex align-items-center"><i class="fas fa-user-circle fa-2x me-2 text-<?php echo $user['role'] === 'owner' ? 'danger' : 'secondary'; ?>"></i><div><strong><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></strong><br><small class="text-muted">ID: <?php echo $user['id']; ?></small></div></div></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td><span class="badge bg-<?php echo $user['role'] === 'owner' ? 'danger' : 'info'; ?> role-badge"><i class="fas fa-<?php echo $user['role'] === 'owner' ? 'crown' : 'user'; ?> me-1"></i><?php echo ucfirst($user['role']); ?></span></td>
                                    <td><?php echo htmlspecialchars($user['position'] ?? 'N/A'); ?></td>
                                    <td><span class="badge bg-success">Active</span></td>
                                    <td><button class="btn btn-sm btn-outline-primary" onclick="editUserRole(<?php echo $user['id']; ?>, '<?php echo addslashes($user['first_name']); ?>', '<?php echo addslashes($user['last_name']); ?>', '<?php echo addslashes($user['email']); ?>', '<?php echo $user['role']; ?>', '<?php echo addslashes($user['position'] ?? ''); ?>')"><i class="fas fa-edit"></i></button>
                                    <?php if ($user['id'] != $_SESSION['user_id']): ?><button class="btn btn-sm btn-outline-danger" onclick="deleteUser(<?php echo $user['id']; ?>, '<?php echo addslashes($user['first_name'] . ' ' . $user['last_name']); ?>')"><i class="fas fa-trash"></i></button><?php endif; ?></td>
                                </tr>
                                <?php endforeach; else: ?><tr><td colspan="7" class="text-center py-4"><i class="fas fa-users fa-3x mb-3 text-muted"></i><p class="text-muted">No users found</p></td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="modal fade" id="switchAccountModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title"><i class="fas fa-exchange-alt me-2"></i>Switch Account</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="text-center mb-4"><div class="switch-account-icon"><i class="fas fa-users fa-3x"></i></div><h5 class="mt-3">Select Account to Switch</h5><p class="text-muted">Choose a different account to continue working.</p></div>
                    <div class="current-user-info mb-4 p-3 bg-light rounded"><div class="d-flex align-items-center"><i class="fas fa-user-circle fa-2x me-3" style="color: #2c5530;"></i><div><span class="badge bg-success mb-1">Current Account</span><h6 class="mb-1"><?php echo htmlspecialchars($first_name . ' ' . $last_name); ?></h6><small class="text-muted"><?php echo htmlspecialchars($position ?: ucfirst($role)); ?></small></div></div></div>
                    <!-- Confirmation Step -->
                    <div id="switchConfirmStep" style="display:none;" class="text-center py-3">
                        <i class="fas fa-exchange-alt fa-2x text-warning mb-3"></i>
                        <p id="switchConfirmMsg" class="mb-4"></p>
                        <div class="d-grid gap-2">
                            <button type="button" class="btn btn-brown" onclick="confirmAccountSwitch()">
                                <i class="fas fa-check me-2"></i>Yes, Switch Account
                            </button>
                            <button type="button" class="btn btn-outline-secondary" onclick="cancelConfirmSwitch()">
                                <i class="fas fa-times me-2"></i>Cancel
                            </button>
                        </div>
                    </div>

                    <div class="account-list" id="accountList">
                        <h6 class="section-subtitle mb-3"><i class="fas fa-history me-2"></i>Available Accounts</h6>
                        <?php
                        try {
                            $pdo = getDBConnection();
                            $stmt = $pdo->prepare("SELECT id, first_name, last_name, email, role, position FROM users WHERE id != ? ORDER BY id DESC LIMIT 5");
                            $stmt->execute([$user_id]);
                            $otherUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            if (!empty($otherUsers)):
                                foreach ($otherUsers as $account):
                        ?>
                        <div class="account-item p-3 mb-2 border rounded" onclick="selectAccount(<?php echo $account['id']; ?>, '<?php echo htmlspecialchars($account['first_name'] . ' ' . $account['last_name']); ?>', '<?php echo htmlspecialchars($account['position'] ?: ucfirst($account['role'])); ?>', '<?php echo htmlspecialchars($first_name . ' ' . $last_name); ?>', '<?php echo htmlspecialchars($position ?: ucfirst($role)); ?>')">
                            <div class="d-flex align-items-center justify-content-between">
                                <div class="d-flex align-items-center"><i class="fas fa-user-circle fa-2x me-3" style="color: <?php echo $account['role'] === 'owner' ? '#e67e22' : '#8B4513'; ?>;"></i><div><h6 class="mb-1"><?php echo htmlspecialchars($account['first_name'] . ' ' . $account['last_name']); ?></h6><small class="text-muted"><span class="badge bg-<?php echo $account['role'] === 'owner' ? 'warning' : 'secondary'; ?> me-2"><?php echo ucfirst($account['role']); ?></span><?php echo htmlspecialchars($account['position'] ?: 'No position'); ?></small></div></div><i class="fas fa-chevron-right text-muted"></i>
                            </div>
                        </div>
                        <?php endforeach; else: ?><div class="text-center py-4"><i class="fas fa-users-slash fa-3x mb-3 text-muted"></i><p class="text-muted">No other accounts available</p></div><?php endif; } catch (PDOException $e) { echo '<div class="alert alert-danger">Unable to load accounts.</div>'; } ?>
                    </div>
                    <div id="quickLoginForm" style="display: none;" class="mt-4 p-3 border-top"><h6 class="mb-3"><i class="fas fa-lock me-2"></i>Enter PIN for <span id="selectedAccountName"></span></h6><form id="switchAccountForm" method="POST" action="switch_account.php"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(security_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="user_id" id="selectedUserId"><div class="mb-3"><label for="accountPin" class="form-label">PIN</label><input type="password" class="form-control" id="accountPin" name="pin" maxlength="4" pattern="\d{4}" placeholder="Enter Pin" required></div><div class="d-grid gap-2"><button type="submit" class="btn btn-brown"><i class="fas fa-exchange-alt me-2"></i>Switch Account</button><button type="button" class="btn btn-outline-secondary" onclick="cancelAccountSelection()">Cancel</button></div></form></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add New User Modal - FIX: Add this missing modal -->
<div class="modal fade" id="addUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-user-plus me-2"></i>Add New User
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="addUserForm">
                    <div class="mb-3">
                        <label class="form-label">First Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="first_name" id="newFirstName" required placeholder="Enter first name">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Last Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="last_name" id="newLastName" required placeholder="Enter last name">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" name="email" id="newEmail" required placeholder="Enter email address">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">PIN (4 digits) <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" name="pin" id="newPin" maxlength="4" pattern="\d{4}" required placeholder="1234">
                        <small class="text-muted">PIN must be exactly 4 digits</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role <span class="text-danger">*</span></label>
                        <select class="form-select" name="role" id="newRole" required>
                            <option value="">Select role...</option>
                            <option value="employee" selected>Employee</option>
                            <option value="owner">Owner</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Position</label>
                        <input type="text" class="form-control" name="position" id="newPosition" placeholder="e.g., Cashier, Manager">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-2"></i>Cancel
                </button>
                <button type="button" class="btn btn-success" onclick="saveNewUser()">
                    <i class="fas fa-save me-2"></i>Add User
                </button>
            </div>
        </div>
    </div>
</div>
<!-- Edit Role Modal -->
<div class="modal fade" id="editRoleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-edit me-2"></i>Edit User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">

                <!-- User identity -->
                <div class="d-flex align-items-center mb-3 p-2 bg-light rounded">
                    <i class="fas fa-user-circle fa-3x me-3 text-brown"></i>
                    <div>
                        <h6 class="mb-0 fw-bold" id="editUserName"></h6>
                        <small class="text-muted" id="editUserEmail"></small>
                    </div>
                    <input type="hidden" id="editUserId">
                </div>

                <!-- Role & Position -->
                <div class="mb-3">
                    <label class="form-label fw-semibold"><i class="fas fa-shield-alt me-1 text-brown"></i>Role</label>
                    <select class="form-select" id="userRoleSelect" onchange="updatePermissionCheckboxes(this.value)">
                        <option value="owner">Owner — Full system access</option>
                        <option value="employee">Employee — Basic access</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold"><i class="fas fa-briefcase me-1 text-brown"></i>Position / Title</label>
                    <input type="text" class="form-control" id="userPosition" placeholder="e.g., Store Manager, Cashier">
                </div>

                <hr class="my-3">

                <!-- Reset PIN — owner sets a new PIN for this user -->
                <!-- Owner CANNOT view the current PIN, only overwrite it -->
                <p class="mb-1 small fw-semibold text-uppercase text-muted" style="letter-spacing:.5px;">
                    <i class="fas fa-key me-1"></i>Reset User PIN
                </p>
                <p class="small text-muted mb-2">
                    Set a new 4-digit PIN for this user. Leave blank to keep the current PIN.
                    You cannot view their current PIN.
                </p>
                <div class="row g-2 mb-1">
                    <div class="col-6">
                        <input type="password" class="form-control form-control-sm" id="resetPinNew"
                               maxlength="4" pattern="\d{4}" placeholder="New PIN (4 digits)">
                    </div>
                    <div class="col-6">
                        <input type="password" class="form-control form-control-sm" id="resetPinConfirm"
                               maxlength="4" pattern="\d{4}" placeholder="Confirm PIN">
                    </div>
                </div>
                <small class="text-muted">Leave blank to keep the current PIN unchanged.</small>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-brown btn-sm" onclick="saveRoleChanges()">
                    <i class="fas fa-save me-1"></i>Save Changes
                </button>
            </div>
        </div>
    </div>
</div>

    <!-- Pass PHP data to JavaScript -->
    <script>
        const baseUrl = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/') + 1);
        const currentUser = { id: '<?php echo $user_id; ?>', firstName: '<?php echo addslashes($first_name); ?>', lastName: '<?php echo addslashes($last_name); ?>', email: '<?php echo addslashes($email); ?>', role: '<?php echo $role; ?>', position: '<?php echo addslashes($position); ?>' };
        const phpToastMessage = <?php echo $toast_message ? json_encode($toast_message) : 'null'; ?>;
        const isOwner = <?php echo $is_owner ? 'true' : 'false'; ?>;
        // Chart data from database
        const phpCategorySales = <?php echo json_encode($category_sales); ?>;
        // ── Notification seed data (shared include) ─────────────────────────────
        <?php include 'includes/notifications.php'; ?>
    </script>
    <script src="assets/js/dashboardJS.js"></script>
    <script src="assets/js/userManagement.js?v=20260814-1"></script>
    <script src="assets/js/notif.js"></script>
    <script src="assets/js/sidebar-nav.js"></script>
</body>
</html>
