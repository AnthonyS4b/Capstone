<?php
// inventory_history.php - TRACKING ONLY version
session_start();
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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory History · Espenida's Pet & Poultry Supply</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="assets/css/dashboardCSS.css">
    <link rel="stylesheet" href="assets/css/PosCSS.css">
    <link rel="stylesheet" href="assets/css/inventory.css">
    <link rel="stylesheet" href="assets/css/reco.css">
    <link rel="stylesheet" href="assets/css/transitions.css">
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

    <div class="inventory-container">
        <!-- SIDEBAR - Same as Dashboard/POS -->
        <div class="sidebar" id="sidebar">
            <div class="store-brand">
                <div class="logo-container">
                    <!-- STORE LOGO & NAME SECTION - IMAGE VERSION -->
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

            <div class="sidebar-user-info">
                <div class="user-avatar-small">
                    <?php echo strtoupper(substr($first_name, 0, 1) . substr($last_name, 0, 1)); ?>
                </div>
                <div class="user-details-small">
                    <h4><?php echo htmlspecialchars($first_name . ' ' . $last_name); ?></h4>
                    <span><?php echo htmlspecialchars($position ?: ucfirst($role)); ?></span>
                </div>
            </div>

            <div class="section-title"><span>DASHBOARD</span></div>
            <a href="dashboard.php" class="dashboard-link">
                <i class="fas fa-tachometer-alt"></i><span>Dashboard</span>
            </a>

            <div class="section-title"><span>POINT OF SALE</span></div>
            <div class="dropdown">
                <button class="dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-cash-register"></i><span>Point of Sale</span>
                </button>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="PosUI_db.php"><i class="fas fa-cash-register"></i>Point of Sale</a></li>
                    <?php if ($is_owner): ?>
                    <li><a class="dropdown-item" href="sales_transactions.php"><i class="fas fa-history"></i>Sales Transactions</a></li>
                    <?php endif; ?>
                </ul>
            </div>

            <div class="section-title"><span>INVENTORY MANAGEMENT</span></div>
            <div class="dropdown">
                <button class="dropdown-toggle active" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-box"></i><span>Inventory Management</span>
                </button>
                <ul class="dropdown-menu">
                <li><a class="dropdown-item" href="categories.php"><i class="fa-solid fa-layer-group"></i>Inventory</a></li> 
                <li><a class="dropdown-item" href="Archive_products.php"><i class="fa-solid fa-box-archive"></i> Archive</a></li> 
                <li><a class="dropdown-item" href="Inventory_management.php"><i class="fa-solid fa-arrow-trend-down"></i>Log History</a></li> 
                </ul>
            </div>

            <?php if ($is_owner): ?>
            <div class="section-title"><span>RECOMMENDATIONS</span></div>
            <button class="recommendation-btn" id="recommendationBtn">
                <i class="fas fa-lightbulb"></i><span>Recommendations</span>
            </button>
            <?php endif; ?>
            
            <?php if ($is_owner): ?>
                <div class="section-title"><span>SYSTEM & ADMIN</span></div>
                <div class="dropdown">
                    <button class="dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fa-solid fa-user"></i><span>System & Admin</span>
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#roleManagementModal"><i class="fas fa-lock"></i> User Roles</a></li>
                        <li><a class="dropdown-item" href="#"><i class="fas fa-database"></i> End of Shifts</a></li>
                        <li><a class="dropdown-item" href="#"><i class="fas fa-cloud"></i> Sync</a></li>
                        <li><a class="dropdown-item" href="#"><i class="fas fa-cog"></i> Settings</a></li>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="spacer"></div>

            <button class="logout-btn" id="logoutBtn">
                <i class="fas fa-sign-out-alt"></i><span>Log out</span>
            </button>
        </div>
        
        <!-- Main Inventory History Content -->
        <div class="inventory-main">
            <div class="inventory-header">
                <h1><i class="fas fa-history"></i> Inventory & Log History</h1>
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
                            <i class="fas fa-box"></i>Added Items
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="archived-tab" data-bs-toggle="tab" data-bs-target="#archived" type="button" role="tab">
                            <i class="fas fa-archive"></i>Archived/Deleted
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="activity-tab" data-bs-toggle="tab" data-bs-target="#activity" type="button" role="tab">
                            <i class="fas fa-edit"></i>Activity Log
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="sessions-tab" data-bs-toggle="tab" data-bs-target="#sessions" type="button" role="tab">
                            <i class="fas fa-clock"></i> Login Times & Account Swaps
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
                                        <th>Price</th>
                                        <th>Stock</th>
                                        <th>👤 WHO ADDED IT</th>
                                        <th>Role</th>
                                        <th>📅 When Added</th>
                                        <th>Last Edited By</th>
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
                                                    <div class="d-flex align-items-center gap-2">
                                                        <i class="fas fa-box" style="color: #4a6fa5;"></i>
                                                        <div>
                                                            <div style="font-weight: 600;"><?php echo htmlspecialchars($item['name']); ?></div>
                                                            <?php if (!empty($item['sku'])): ?>
                                                                <small class="text-muted">SKU: <?php echo htmlspecialchars($item['sku']); ?></small>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td><?php echo htmlspecialchars($item['category_name'] ?? 'Uncategorized'); ?></td>
                                                <td><strong>₱<?php echo number_format($item['price'], 2); ?></strong></td>
                                                <td>
                                                    <span class="stock-badge <?php echo $stock_class; ?>">
                                                        <?php echo $item['stock']; ?> units
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="user-info-cell">
                                                        <div class="user-avatar <?php echo $added_by_role; ?>">
                                                            <?php echo $added_initials ?: 'U'; ?>
                                                        </div>
                                                        <div class="user-details">
                                                            <div class="user-name"><?php echo htmlspecialchars($added_by_name); ?></div>
                                                            <div class="user-email"><?php echo htmlspecialchars($added_by_email); ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="user-badge <?php echo $added_by_role; ?>">
                                                        <i class="fas fa-<?php echo $added_by_role === 'owner' ? 'crown' : 'user'; ?>"></i>
                                                        <?php echo ucfirst($added_by_role); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="timestamp">
                                                        <i class="far fa-calendar-alt"></i> 
                                                        <?php echo date('M d, Y', strtotime($item['added_date'])); ?><br>
                                                        <i class="far fa-clock"></i> 
                                                        <?php echo date('h:i A', strtotime($item['added_date'])); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if (!empty($item['last_edited_by_name'])): ?>
                                                        <span class="timestamp">
                                                            <i class="fas fa-user-edit"></i> 
                                                            <?php echo htmlspecialchars($item['last_edited_by_name']); ?><br>
                                                            <small><?php echo date('M d, Y', strtotime($item['last_edited_date'])); ?></small>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted">Never edited</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8" class="text-center py-4">
                                                <i class="fas fa-box-open fa-3x mb-3 text-muted"></i>
                                                <p class="text-muted">No products found</p>
                                            </td>
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
                                        <th>📋 Action</th>
                                        <th>👤 WHO Did It</th>
                                        <th>Role</th>
                                        <th>📅 When</th>
                                        <th>Restore</th>
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
                                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($item['name']); ?></div>
                                                    <?php if (!empty($item['sku'])): ?>
                                                        <small class="text-muted">SKU: <?php echo htmlspecialchars($item['sku']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($item['category_name'] ?? 'Uncategorized'); ?></td>
                                                <td>
                                                    <span class="action-badge action-<?php echo $action_type; ?>">
                                                        <i class="fas fa-<?php echo $action_type === 'deleted' ? 'trash' : 'archive'; ?>"></i>
                                                        <?php echo ucfirst($action_type); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="user-info-cell">
                                                        <div class="user-avatar <?php echo $action_by_role; ?>">
                                                            <?php echo $action_initials; ?>
                                                        </div>
                                                        <div class="user-details">
                                                            <div class="user-name"><?php echo htmlspecialchars($action_by_name); ?></div>
                                                            <div class="user-email"><?php echo htmlspecialchars($item['action_by_email'] ?? ''); ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="user-badge <?php echo $action_by_role; ?>">
                                                        <i class="fas fa-<?php echo $action_by_role === 'owner' ? 'crown' : 'user'; ?>"></i>
                                                        <?php echo ucfirst($action_by_role); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="timestamp">
                                                        <i class="far fa-calendar-alt"></i> 
                                                        <?php echo date('M d, Y', strtotime($item['action_date'])); ?><br>
                                                        <i class="far fa-clock"></i> 
                                                        <?php echo date('h:i A', strtotime($item['action_date'])); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <button class="btn btn-sm btn-outline-success" onclick="restoreProduct(<?php echo $item['id']; ?>)">
                                                        <i class="fas fa-undo"></i> Restore
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-4">
                                                <i class="fas fa-archive fa-3x mb-3 text-muted"></i>
                                                <p class="text-muted">No archived or deleted items</p>
                                            </td>
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
                                        } elseif ($log['action'] === 'void') {
                                            $icon_class = 'action-void';
                                            $icon = 'fa-ban';
                                            $bg_class = 'action-void';
                                        }
                                        
                                        $user_role = $log['user_role'] ?? 'employee';
                                    ?>
                                    <div class="session-item" data-role="<?php echo $user_role; ?>" data-action="<?php echo $log['action']; ?>">
                                        <div class="session-icon <?php echo $bg_class; ?>">
                                            <i class="fas <?php echo $icon; ?>"></i>
                                        </div>
                                        <div class="session-details">
                                            <div class="session-user">
                                                <strong><?php echo htmlspecialchars($log['user_name'] ?? 'Unknown User'); ?></strong>
                                                <span class="user-badge <?php echo $user_role; ?> ms-2">
                                                    <i class="fas fa-<?php echo $user_role === 'owner' ? 'crown' : 'user'; ?>"></i>
                                                    <?php echo ucfirst($user_role); ?>
                                                </span>
                                                <small class="text-muted ms-2">(<?php echo htmlspecialchars($log['user_email'] ?? ''); ?>)</small>
                                            </div>
                                            <div class="session-time">
                                                <span class="action-badge <?php echo $icon_class; ?> me-2">
                                                    <i class="fas <?php echo $icon; ?>"></i>
                                                    <?php echo ucfirst($log['action']); ?>
                                                </span>
                                                <strong><?php echo htmlspecialchars($log['product_name'] ?? 'Unknown Product'); ?></strong>
                                                <?php if (!empty($log['changes'])): ?>
                                                    <span class="text-muted">- <?php echo htmlspecialchars($log['changes']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="session-time">
                                                <i class="far fa-clock"></i> 
                                                <?php echo date('F d, Y h:i:s A', strtotime($log['created_at'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-history fa-3x mb-3 text-muted"></i>
                                    <p class="text-muted">No activity logs found</p>
                                </div>
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
                                    <div class="session-item" data-role="<?php echo $user_role; ?>" data-action="<?php echo $session['action']; ?>">
                                        <div class="session-icon <?php echo $bg_class; ?>">
                                            <i class="fas <?php echo $icon; ?>"></i>
                                        </div>
                                        <div class="session-details">
                                            <div class="session-user">
                                                <strong><?php echo htmlspecialchars($session['user_name'] ?? 'Unknown User'); ?></strong>
                                                <span class="user-badge <?php echo $user_role; ?> ms-2">
                                                    <i class="fas fa-<?php echo $user_role === 'owner' ? 'crown' : 'user'; ?>"></i>
                                                    <?php echo ucfirst($user_role); ?>
                                                </span>
                                                <small class="text-muted ms-2">(<?php echo htmlspecialchars($session['user_email'] ?? ''); ?>)</small>
                                            </div>
                                            <div class="session-time">
                                                <span class="session-badge <?php echo $badge_class; ?> me-2">
                                                    <?php echo ucfirst($session['action']); ?>
                                                </span>
                                                <?php if ($session['action'] === 'switch' && !empty($session['switched_from'])): ?>
                                                    <i class="fas fa-arrow-right"></i> 
                                                    Switched from <strong><?php echo htmlspecialchars($session['switched_from']); ?></strong>
                                                <?php endif; ?>
                                            </div>
                                            <div class="session-time">
                                                <i class="far fa-clock"></i> 
                                                <?php echo date('F d, Y h:i:s A', strtotime($session['login_time'])); ?>
                                            </div>
                                            <?php if (!empty($session['ip_address'])): ?>
                                                <div class="session-time">
                                                    <i class="fas fa-network-wired"></i> 
                                                    IP Address: <?php echo $session['ip_address']; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-users fa-3x mb-3 text-muted"></i>
                                    <p class="text-muted">No session logs found</p>
                                </div>
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

    <!-- Pass PHP data to JavaScript -->
    <script>
        const phpData = <?php echo json_encode($js_data); ?>;
        const ajaxUrl = 'ajax/inventory_ajax.php';
        const currentUser = { id: '<?php echo $user_id; ?>' };
        <?php include 'includes/notifications.php'; ?>
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="assets/js/inventory.js"></script>
    <script src="assets/js/notif.js"></script>
    <script src="assets/js/sidebar-nav.js"></script>
</body>
</html>