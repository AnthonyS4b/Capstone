<?php
// inventory_history.php - TRACKING ONLY version
session_start();
require_once __DIR__ . '/includes/security.php';
// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['toast_message'] = [
        'type' => 'warning',
        'title' => 'Access Denied!',
        'message' => 'Please login first to access inventory history.'
    ];
    header("Location: Login.php");
    exit();
}

// Database connection
require_once 'config/database.php';

// Get user info from session
$user_id = $_SESSION['user_id'];
$first_name = $_SESSION['first_name'] ?? 'User';
$last_name = $_SESSION['last_name'] ?? '';
$role = $_SESSION['role'] ?? 'employee';
$position = $_SESSION['position'] ?? '';
$email = $_SESSION['email'] ?? '';

$is_owner = ($role === 'owner');

// Local logout removed - now handled by logout.php

// Get categories (only for filtering)
require_once 'controllers/CategoryController.php';
$categoryController = new CategoryController();
$categoriesResult = $categoryController->getAllCategories();
$categories = $categoriesResult['success'] ? $categoriesResult['data'] : [];

// Get inventory items with tracking info (WHO ADDED THEM)
$inventory_items = [];
$deleted_items = [];
$activity_log = [];
$user_sessions = [];

try {
    $pdo = getDBConnection();
    
    // ===== 1. CURRENT INVENTORY - Shows who added each item =====
    $stmt = $pdo->query("
        SELECT 
            p.id,
            p.name,
            p.sku,
            p.price,
            p.stock,
            c.name as category_name,
            c.id as category_id,
            -- Who added this item (EMPLOYEE/OWNER NAME)
            CONCAT(u.first_name, ' ', u.last_name) as added_by_name,
            u.email as added_by_email,
            u.role as added_by_role,
            p.created_at as added_date,
            -- Who last edited this item
            CONCAT(uedit.first_name, ' ', uedit.last_name) as last_edited_by_name,
            p.updated_at as last_edited_date
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN users u ON p.created_by = u.id
        LEFT JOIN users uedit ON p.updated_by = uedit.id
        WHERE p.deleted_at IS NULL
        ORDER BY p.created_at DESC
    ");
    $inventory_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ===== 2. ARCHIVED/DELETED ITEMS - Shows who removed them =====
    $stmt = $pdo->query("
        SELECT 
            p.id,
            p.name,
            p.sku,
            c.name as category_name,
            -- Who deleted/archived this
            CONCAT(u.first_name, ' ', u.last_name) as action_by_name,
            u.role as action_by_role,
            u.email as action_by_email,
            -- What happened
            CASE 
                WHEN p.deleted_at IS NOT NULL THEN 'deleted'
                WHEN p.archived_at IS NOT NULL THEN 'archived'
            END as action_type,
            -- When it happened
            COALESCE(p.deleted_at, p.archived_at) as action_date
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN users u ON COALESCE(p.deleted_by, p.archived_by) = u.id
        WHERE p.deleted_at IS NOT NULL OR p.archived_at IS NOT NULL
        ORDER BY action_date DESC
        LIMIT 100
    ");
    $deleted_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ===== 3. COMPLETE ACTIVITY LOG - EVERY EDIT EVER MADE =====
    $stmt = $pdo->query("
        SELECT 
            h.*,
            -- Who did it (EMPLOYEE/OWNER NAME)
            CONCAT(u.first_name, ' ', u.last_name) as user_name,
            u.email as user_email,
            u.role as user_role,
            -- What product (Prefer history table name for persistence)
            COALESCE(h.product_name, p.name, 'Unknown Product') as product_name
        FROM inventory_history h
        LEFT JOIN users u ON h.user_id = u.id
        LEFT JOIN products p ON h.product_id = p.id
        ORDER BY h.created_at DESC
        LIMIT 500
    ");
    $activity_log = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ===== 4. USER SESSIONS - LOGIN/LOGOUT/SWITCH TIMES =====
    $stmt = $pdo->query("
        SELECT 
            ls.*,
            -- Who (EMPLOYEE/OWNER NAME)
            CONCAT(u.first_name, ' ', u.last_name) as user_name,
            u.email as user_email,
            u.role as user_role
        FROM login_sessions ls
        LEFT JOIN users u ON ls.user_id = u.id
        ORDER BY ls.login_time DESC
        LIMIT 200
    ");
    $user_sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error fetching inventory data: " . $e->getMessage());
}

// Get toast message
$toast_message = isset($_SESSION['toast_message']) ? $_SESSION['toast_message'] : null;
if ($toast_message) {
    unset($_SESSION['toast_message']);
}

// Pass data to JavaScript
$js_data = [
    'user_id' => $user_id,
    'toast_message' => $toast_message,
    'inventory_items' => $inventory_items,
    'deleted_items' => $deleted_items,
    'activity_log' => $activity_log,
    'user_sessions' => $user_sessions
];
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
    <title>Inventory History · Espenida's Pet & Poultry Supply</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="assets/css/dashboardCSS.css">
    <link rel="stylesheet" href="assets/css/PosCSS.css">
    <link rel="stylesheet" href="assets/css/inventory.css">
    <link rel="stylesheet" href="assets/css/reco.css">
    <link rel="stylesheet" href="assets/css/transitions.css">
    <link rel="stylesheet" href="assets/css/notif.css?v=<?= filemtime(__DIR__ . '/assets/css/notif.css') ?>">

    <link rel="stylesheet" href="assets/css/responsive.css">
    <link rel="stylesheet" href="assets/css/inventory-ui.css?v=<?= filemtime(__DIR__ . '/assets/css/inventory-ui.css') ?>">
    <script src="assets/js/responsive.js?v=<?= filemtime(__DIR__ . '/assets/js/responsive.js') ?>"></script>
    <link rel="stylesheet" href="assets/css/sidebar.css?v=<?= filemtime(__DIR__ . '/assets/css/sidebar.css') ?>">
</head>
<body class="inv-ui">
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="fas fa-bars"></i>
    </button>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>

    <div class="inventory-container">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>
        
        <!-- Main Inventory History Content -->
        <div class="inventory-main">
            <div class="inventory-header">
                <div class="inv-header-text">
                    <h1>Log History</h1>
                    <p>Who added, changed, archived and signed in — newest first.</p>
                </div>
                <div class="header-actions">
                    <!-- Notification Bell -->
                    <div class="notif-bell-wrap" id="notifBellWrap">
                        <button class="notif-bell-btn" id="notifBellBtn" aria-label="Notifications" aria-expanded="false">
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
                    <div class="date-time" id="currentDateTime"></div>
                    <button class="refresh-btn" onclick="location.reload()">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </div>
            </div>
            
            <!-- Tabs Navigation -->
            <div class="inventory-tabs">
                <ul class="nav nav-tabs" id="inventoryTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="current-tab" data-bs-toggle="tab" data-bs-target="#current" type="button" role="tab">
                            Added items
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="archived-tab" data-bs-toggle="tab" data-bs-target="#archived" type="button" role="tab">
                            Archived &amp; deleted
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="activity-tab" data-bs-toggle="tab" data-bs-target="#activity" type="button" role="tab">
                            Activity
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="sessions-tab" data-bs-toggle="tab" data-bs-target="#sessions" type="button" role="tab">
                            Sign-ins &amp; account switches
                        </button>
                    </li>
                </ul>
            </div>
            
            <!-- Tab Content -->
            <div class="inventory-content">
                <div class="tab-content" id="inventoryTabContent">
                    
                    <!-- ===== TAB 1: WHO ADDED ITEMS ===== -->
                    <div class="tab-pane fade show active" id="current" role="tabpanel">
                        <div class="action-bar">
                            <div class="search-box">
                                <i class="fas fa-search"></i>
                                <input type="text" id="searchInventory" placeholder="Search products or who added...">
                            </div>
                            <div class="filter-group">
                                <select class="filter-select" id="categoryFilter">
                                    <option value="">All Categories</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select class="filter-select" id="roleFilter">
                                    <option value="">All Users</option>
                                    <option value="owner">Owners Only</option>
                                    <option value="employee">Employees Only</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="inventory-table" id="inventoryTable">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>Category</th>
                                        <th class="inv-num">Price</th>
                                        <th class="inv-num">Stock</th>
                                        <th>Added by</th>
                                        <th>Added</th>
                                        <th>Last edited</th>
                                    </tr>
                                </thead>
                                <tbody id="inventoryTableBody">
                                    <?php if (!empty($inventory_items)): ?>
                                        <?php foreach ($inventory_items as $item): ?>
                                            <?php 
                                                $stock_class = 'stock-high';
                                                if ($item['stock'] <= 10) $stock_class = 'stock-low';
                                                elseif ($item['stock'] <= 30) $stock_class = 'stock-medium';
                                                
                                                $added_by_name = $item['added_by_name'] ?? 'Unknown User';
                                                $added_by_role = $item['added_by_role'] ?? 'employee';
                                                $added_by_email = $item['added_by_email'] ?? '';
                                                $added_initials = strtoupper(substr($added_by_name, 0, 1) . (substr($added_by_name, strpos($added_by_name, ' ') + 1, 1) ?: ''));
                                            ?>
                                            <tr data-id="<?php echo $item['id']; ?>"
                                                data-category="<?php echo $item['category_id']; ?>"
                                                data-stock="<?php echo $item['stock']; ?>"
                                                data-role="<?php echo $added_by_role; ?>">
                                                <td>
                                                    <span class="inv-name"><?php echo htmlspecialchars($item['name']); ?></span>
                                                    <?php if (!empty($item['sku'])): ?>
                                                        <span class="inv-sub"><?php echo htmlspecialchars($item['sku']); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="inv-muted-cell"><?php echo htmlspecialchars($item['category_name'] ?? 'Uncategorized'); ?></td>
                                                <td class="inv-num">₱<?php echo number_format($item['price'], 2); ?></td>
                                                <td class="inv-num <?php echo $item['stock'] <= 5 ? 'is-bad' : ($item['stock'] <= 15 ? 'is-warn' : ''); ?>"><?php echo (int)$item['stock']; ?></td>
                                                <td>
                                                    <span class="inv-person"><?php echo htmlspecialchars($added_by_name); ?></span>
                                                    <span class="inv-sub"><?php echo ucfirst($added_by_role); ?></span>
                                                </td>
                                                <td>
                                                    <span><?php echo date('M d, Y', strtotime($item['added_date'])); ?></span>
                                                    <span class="inv-sub"><?php echo date('h:i A', strtotime($item['added_date'])); ?></span>
                                                </td>
                                                <td>
                                                    <?php if (!empty($item['last_edited_by_name'])): ?>
                                                        <span class="inv-person"><?php echo htmlspecialchars($item['last_edited_by_name']); ?></span>
                                                        <span class="inv-sub"><?php echo date('M d, Y', strtotime($item['last_edited_date'])); ?></span>
                                                    <?php else: ?>
                                                        <span class="inv-muted">Never</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="inv-empty-cell"><p>No products found</p></td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- ===== TAB 2: WHO ARCHIVED/DELETED ITEMS ===== -->
                    <div class="tab-pane fade" id="archived" role="tabpanel">
                        <div class="action-bar">
                            <div class="search-box">
                                <i class="fas fa-search"></i>
                                <input type="text" id="searchArchived" placeholder="Search archived/deleted items...">
                            </div>
                            <div class="filter-group">
                                <select class="filter-select" id="archiveTypeFilter">
                                    <option value="">All Actions</option>
                                    <option value="archived">Archived Only</option>
                                    <option value="deleted">Deleted Only</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="inventory-table">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>Category</th>
                                        <th>Action</th>
                                        <th>By</th>
                                        <th>When</th>
                                        <th><span class="visually-hidden">Restore</span></th>
                                    </tr>
                                </thead>
                                <tbody id="archivedTableBody">
                                    <?php if (!empty($deleted_items)): ?>
                                        <?php foreach ($deleted_items as $item): ?>
                                            <?php
                                                $action_by_name = $item['action_by_name'] ?? 'Unknown User';
                                                $action_by_role = $item['action_by_role'] ?? 'employee';
                                                $action_type = $item['action_type'] ?? 'archived';
                                                $action_initials = strtoupper(substr($action_by_name, 0, 1) . (substr($action_by_name, strpos($action_by_name, ' ') + 1, 1) ?: ''));
                                            ?>
                                            <tr>
                                                <td>
                                                    <span class="inv-name"><?php echo htmlspecialchars($item['name']); ?></span>
                                                    <?php if (!empty($item['sku'])): ?>
                                                        <span class="inv-sub"><?php echo htmlspecialchars($item['sku']); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="inv-muted-cell"><?php echo htmlspecialchars($item['category_name'] ?? 'Uncategorized'); ?></td>
                                                <td><span class="action-badge action-<?php echo $action_type; ?>"><?php echo ucfirst($action_type); ?></span></td>
                                                <td>
                                                    <span class="inv-person"><?php echo htmlspecialchars($action_by_name); ?></span>
                                                    <span class="inv-sub"><?php echo ucfirst($action_by_role); ?></span>
                                                </td>
                                                <td>
                                                    <span><?php echo date('M d, Y', strtotime($item['action_date'])); ?></span>
                                                    <span class="inv-sub"><?php echo date('h:i A', strtotime($item['action_date'])); ?></span>
                                                </td>
                                                <td class="inv-actions">
                                                    <button class="inv-btn inv-btn-quiet inv-btn-sm" onclick="restoreProduct(<?php echo $item['id']; ?>)">
                                                        <i class="fas fa-undo-alt"></i>Restore
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="inv-empty-cell"><p>No archived or deleted items</p></td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- ===== TAB 3: WHO EDITED (ACTIVITY LOG) ===== -->
                    <div class="tab-pane fade" id="activity" role="tabpanel">
                        <div class="action-bar">
                            <div class="search-box">
                                <i class="fas fa-search"></i>
                                <input type="text" id="searchActivity" placeholder="Search by user, product, or action...">
                            </div>
                            <div class="filter-group">
                                <select class="filter-select" id="activityTypeFilter">
                                    <option value="">All Activities</option>
                                    <option value="add">Added</option>
                                    <option value="edit">Edited</option>
                                    <option value="archive">Archived</option>
                                    <option value="delete">Deleted</option>
                                    <option value="restore">Restored</option>
                                    <option value="void">Voided</option>
                                    <option value="restock">Restocked</option>
                                    <option value="reduction">Stock removed</option>
                                    <option value="expired">Expired stock removed</option>
                                </select>
                                <select class="filter-select" id="activityRoleFilter">
                                    <option value="">All Users</option>
                                    <option value="owner">Owners Only</option>
                                    <option value="employee">Employees Only</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="activity-log" id="activityLog">
                            <?php if (!empty($activity_log)): ?>
                                <?php foreach ($activity_log as $log): ?>
                                    <?php
                                        $icon_class = 'action-add';
                                        $icon = 'fa-plus-circle';
                                        $bg_class = 'action-add';
                                        
                                        if ($log['action'] === 'edit') {
                                            $icon_class = 'action-edit';
                                            $icon = 'fa-edit';
                                            $bg_class = 'action-edit';
                                        } elseif ($log['action'] === 'archive') {
                                            $icon_class = 'action-archive';
                                            $icon = 'fa-archive';
                                            $bg_class = 'action-archive';
                                        } elseif ($log['action'] === 'delete') {
                                            $icon_class = 'action-delete';
                                            $icon = 'fa-trash';
                                            $bg_class = 'action-delete';
                                        } elseif ($log['action'] === 'restore') {
                                            $icon_class = 'action-restore';
                                            $icon = 'fa-undo';
                                            $bg_class = 'action-restore';
                                        } elseif ($log['action'] === 'void' || $log['action'] === 'expired') {
                                            $icon_class = 'action-void';
                                            $icon = 'fa-ban';
                                            $bg_class = 'action-void';
                                        }

                                        // Entries with no user were made by the system itself
                                        // (expired stock removed, promotion prices restored)
                                        $is_system = empty($log['user_id']);
                                        $user_role = $is_system ? 'system' : ($log['user_role'] ?? 'employee');
                                    ?>
                                    <div class="session-item inv-log-row" data-role="<?php echo $user_role; ?>" data-action="<?php echo htmlspecialchars($log['action']); ?>">
                                        <time class="inv-log-time" datetime="<?php echo date('c', strtotime($log['created_at'])); ?>">
                                            <span><?php echo date('M d, Y', strtotime($log['created_at'])); ?></span>
                                            <span class="inv-sub"><?php echo date('h:i A', strtotime($log['created_at'])); ?></span>
                                        </time>
                                        <span class="action-badge <?php echo $icon_class; ?>"><?php echo ucfirst(htmlspecialchars(str_replace('_', ' ', $log['action']))); ?></span>
                                        <div class="inv-log-body">
                                            <div>
                                                <strong><?php echo htmlspecialchars($log['product_name'] ?? 'Unknown product'); ?></strong>
                                                <?php if (!empty($log['changes'])): ?>
                                                    <span class="inv-muted"> — <?php echo htmlspecialchars($log['changes']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($is_system): ?>
                                                <span class="inv-sub">by System · automatic</span>
                                            <?php else: ?>
                                                <span class="inv-sub">by <?php echo htmlspecialchars($log['user_name'] ?? 'Unknown user'); ?> · <?php echo ucfirst($user_role); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="inv-empty-text">No activity yet</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- ===== TAB 4: LOGIN TIMES & ACCOUNT SWAPS ===== -->
                    <div class="tab-pane fade" id="sessions" role="tabpanel">
                        <div class="action-bar">
                            <div class="search-box">
                                <i class="fas fa-search"></i>
                                <input type="text" id="searchSessions" placeholder="Search by user name...">
                            </div>
                            <div class="filter-group">
                                <select class="filter-select" id="sessionTypeFilter">
                                    <option value="">All Sessions</option>
                                    <option value="login">Logins Only</option>
                                    <option value="logout">Logouts Only</option>
                                    <option value="switch">Account Switches Only</option>
                                </select>
                                <select class="filter-select" id="sessionRoleFilter">
                                    <option value="">All Users</option>
                                    <option value="owner">Owners Only</option>
                                    <option value="employee">Employees Only</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="sessions-list" id="sessionsList">
                            <?php if (!empty($user_sessions)): ?>
                                <?php foreach ($user_sessions as $session): ?>
                                    <?php
                                        $badge_class = 'badge-login';
                                        $icon = 'fa-sign-in-alt';
                                        $bg_class = 'login';
                                        
                                        if ($session['action'] === 'switch') {
                                            $badge_class = 'badge-switch';
                                            $icon = 'fa-exchange-alt';
                                            $bg_class = 'switch';
                                        } elseif ($session['action'] === 'logout') {
                                            $badge_class = 'badge-logout';
                                            $icon = 'fa-sign-out-alt';
                                            $bg_class = 'logout';
                                        }
                                        
                                        $user_role = $session['user_role'] ?? 'employee';
                                    ?>
                                    <div class="session-item inv-log-row" data-role="<?php echo $user_role; ?>" data-action="<?php echo htmlspecialchars($session['action']); ?>">
                                        <time class="inv-log-time" datetime="<?php echo date('c', strtotime($session['login_time'])); ?>">
                                            <span><?php echo date('M d, Y', strtotime($session['login_time'])); ?></span>
                                            <span class="inv-sub"><?php echo date('h:i:s A', strtotime($session['login_time'])); ?></span>
                                        </time>
                                        <span class="session-badge <?php echo $badge_class; ?>"><?php echo $session['action'] === 'switch' ? 'Switch' : ucfirst(htmlspecialchars($session['action'])); ?></span>
                                        <div class="inv-log-body">
                                            <div>
                                                <strong><?php echo htmlspecialchars($session['user_name'] ?? 'Unknown user'); ?></strong>
                                                <span class="inv-muted"> · <?php echo ucfirst($user_role); ?></span>
                                                <?php if ($session['action'] === 'switch' && !empty($session['switched_from'])): ?>
                                                    <span class="inv-muted"> — switched from <?php echo htmlspecialchars($session['switched_from']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (!empty($session['ip_address'])): ?>
                                                <span class="inv-sub">IP <?php echo htmlspecialchars($session['ip_address']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="inv-empty-text">No sign-ins recorded</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Restore Confirmation Modal (Simplified) -->
    <div class="modal fade" id="restoreConfirmModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-undo me-2"></i>Confirm Restore
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center py-4">
                    <i class="fas fa-archive fa-4x text-success mb-3"></i>
                    <p>Restore this product to inventory?</p>
                    <p class="text-muted small">The product will reappear in your current inventory.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" id="confirmRestoreBtn">Restore</button>
                </div>
            </div>
        </div>
    </div>

    <?php include 'includes/user_roles_modal.php'; ?>

    <!-- Pass PHP data to JavaScript -->
    <script>
        const phpData = <?php echo json_encode($js_data); ?>;
        const ajaxUrl = 'ajax/inventory_ajax.php';
        const currentUser = { id: '<?php echo $user_id; ?>' };
        <?php include 'includes/notifications.php'; ?>
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="assets/js/inventory.js?v=<?= filemtime(__DIR__ . '/assets/js/inventory.js') ?>"></script>
    <script src="assets/js/userManagement.js?v=<?= filemtime(__DIR__ . '/assets/js/userManagement.js') ?>"></script>
    <script src="assets/js/notif.js?v=<?= filemtime(__DIR__ . '/assets/js/notif.js') ?>"></script>
    <script src="assets/js/sidebar-nav.js"></script>
</body>
</html>
