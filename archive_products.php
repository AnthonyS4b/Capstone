<?php
// archive_products.php
session_start();
require_once __DIR__ . '/includes/security.php';
require_once 'config/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['toast_message'] = [
        'type' => 'warning',
        'title' => 'Access Denied!',
        'message' => 'Please login first.'
    ];
    header("Location: Login.php");
    exit();
}

// Get user info from session
$user_id = $_SESSION['user_id'];
$first_name = $_SESSION['first_name'] ?? 'User';
$last_name = $_SESSION['last_name'] ?? '';
$role = $_SESSION['role'] ?? 'employee';
$position = $_SESSION['position'] ?? '';

// Handle logout
// Local logout removed - now handled by logout.php

$is_owner = ($role === 'owner');
$toast_message = isset($_SESSION['toast_message']) ? $_SESSION['toast_message'] : null;
if ($toast_message) {
    unset($_SESSION['toast_message']);
}

// Get base URL for AJAX calls
$ajax_url = 'ajax/category_ajax.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(security_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <script src="assets/js/security.js?v=20260814-1" defer></script>
    <title>Archive Products · Espenida's Pet & Poultry Supply</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="assets/css/reco.css">
    <link rel="stylesheet" href="assets/css/archivecss.css">
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
    <div class="spinner-overlay" id="loadingSpinner">
        <div class="loading-spinner"></div>
    </div>

    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>

    <!-- SIDEBAR - Copy your sidebar from categories.php -->
    <div class="sidebar" id="sidebar">
         <!-- STORE LOGO & NAME SECTION - IMAGE VERSION -->
        <div class="store-brand">
            <div class="logo-container">
                <!-- YOUR STORE IMAGE LOGO -->
                <img src="assets/images/sidebar.jpg" alt="Espenida's Logo" class="store-logo-img">
            </div>
            <div class="store-name">
                <span class="store-name-main">Espenida's</span>
                <span class="store-name-sub">PET & POULTRY SUPPLY</span>
            </div>
        </div>
        
        <!-- COLLAPSE BUTTON -->
        <button class="collapse-btn" id="collapseBtn">
            <i class="fas fa-chevron-left" id="collapseIcon"></i>
            <span>Espenida Store</span>
        </button>

        <!-- User Info -->
        <div class="sidebar-user-info">
            <div class="user-avatar-small">
                <?php echo strtoupper(substr($first_name, 0, 1) . substr($last_name, 0, 1)); ?>
            </div>
            <div class="user-details-small">
                <h4><?php echo htmlspecialchars($first_name . ' ' . $last_name); ?></h4>
                <span><?php echo htmlspecialchars($position ?: ucfirst($role)); ?></span>
            </div>
        </div>

        <!-- DASHBOARD -->
        <div class="section-title"><span>DASHBOARD</span></div>
        <a href="dashboard.php" class="dashboard-link">
            <i class="fas fa-tachometer-alt"></i><span>Dashboard</span>
        </a>

        <!-- POINT OF SALE -->
        <div class="section-title"><span>POINT OF SALE</span></div>
        <div class="dropdown">
            <button class="dropdown-toggle" type="button" data-bs-toggle="dropdown">
                <i class="fas fa-cash-register"></i><span>Point of Sale</span>
            </button>
            <ul class="dropdown-menu">
              <li><a class="dropdown-item" href="PosUI_db.php"><i class="fas fa-cash-register"></i>Point of Sale</a></li>
                <?php if ($is_owner): ?>
                <li><a class="dropdown-item" href="sales_transactions.php"><i class="fas fa-history"></i>Sales Transactions</a></li>
                <?php endif; ?>
            </ul>
        </div>

        <!-- INVENTORY MANAGEMENT -->
        <div class="section-title"><span>INVENTORY MANAGEMENT</span></div>
        <div class="dropdown">
            <button class="dropdown-toggle active" type="button" data-bs-toggle="dropdown">
                <i class="fas fa-box"></i><span>Inventory Management</span>
            </button>
            <ul class="dropdown-menu">
                <li><a class="dropdown-item" href="categories.php"><i class="fa-solid fa-layer-group"></i>Inventory</a></li> 
                <li><a class="dropdown-item" href="Archive_products.php"><i class="fa-solid fa-box-archive"></i> Archive</a></li> 
                <li><a class="dropdown-item" href="Inventory_management.php"><i class="fa-solid fa-arrow-trend-down"></i>Log History</a></li> 
            </ul>
        </div>

        <?php if ($is_owner): ?>
         <!-- RECOMMENDATION BUTTON -->
        <div class="section-title">
            <span>RECOMMENDATIONS</span>
        </div>
        <button class="recommendation-btn" id="recommendationBtn">
            <i class="fas fa-lightbulb"></i>
            <span>Recommendations</span>
        </button>
        <?php endif; ?>
        
        <!-- SYSTEM & ADMIN -->
        <?php if ($is_owner): ?>
            <div class="section-title"><span>SYSTEM & ADMIN</span></div>
            <div class="dropdown">
                <button class="dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    <i class="fa-solid fa-user"></i><span>System & Admin</span>
                </button>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="#roleManagementModal" data-bs-toggle="modal" data-bs-target="#roleManagementModal"><i class="fas fa-lock"></i> User Roles</a></li>
                    <li><a class="dropdown-item" href="admin_data_tools.php"><i class="fas fa-database"></i> Backup &amp; Export</a></li>
                </ul>
            </div>
        <?php endif; ?>

        <!-- SPACER -->
        <div class="spacer"></div>

        <!-- LOGOUT BUTTON -->
        <button class="logout-btn" id="logoutBtn">
            <i class="fas fa-sign-out-alt"></i><span>Log out</span>
        </button>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main-content">
        <!-- Welcome Banner -->
        <div class="welcome-banner">
            <div class="welcome-text">
                <h1><i class="fas fa-box-archive me-2"></i>Archives</h1>
                <p>Manage your archived and deleted products</p>
            </div>
            <div class="d-flex align-items-center gap-3">
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
                <div class="date-display">
                    <i class="fas fa-calendar-alt me-2"></i><?php echo date('M d, Y'); ?>
                </div>
            </div>
        </div>

        <!-- Error Display -->
        <div id="errorContainer" style="display: none;"></div>

        <!-- Stats Cards -->
        <div class="stats-grid" id="statsContainer">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-box-archive"></i></div>
                <div class="stat-info">
                    <h3 id="totalArchived">0</h3>
                    <p>Total Archived</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-undo-alt"></i></div>
                <div class="stat-info">
                    <h3 id="restorable">0</h3>
                    <p>Restorable Items</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-clock"></i></div>
                <div class="stat-info">
                    <h3 id="oldestArchive">-</h3>
                    <p>Oldest Archive</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-database"></i></div>
                <div class="stat-info">
                    <h3 id="storageUsed">0 KB</h3>
                    <p>Storage Used</p>
                </div>
            </div>
        </div>

        <!-- Archive Table Section -->
        <div class="archive-section">
            <div class="section-header">
                <h5><i class="fas fa-list me-2" style="color: #4a6fa5;"></i>Archive</h5>
                <div>
                    <button class="btn btn-outline-secondary btn-sm me-2" onclick="testConnection()">
                        <i class="fas fa-plug me-1"></i>Test Connection
                    </button>
                    <button class="btn btn-outline-secondary btn-sm me-2" onclick="refreshArchive()">
                        <i class="fas fa-sync-alt me-1"></i>Refresh
                    </button>
                    <?php if ($is_owner): ?>
                    <button class="btn btn-danger btn-sm" onclick="emptyArchive()">
                        <i class="fas fa-trash-alt me-1"></i>Empty Archive
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Tabs -->
            <ul class="nav nav-tabs mb-3" id="archiveTabs">
                <li class="nav-item">
                    <a class="nav-link active" id="tab-products" href="#" onclick="switchTab('products'); return false;">
                        <i class="fas fa-box me-1"></i>Products
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="tab-categories" href="#" onclick="switchTab('categories'); return false;">
                        <i class="fas fa-layer-group me-1"></i>Categories
                    </a>
                </li>
            </ul>

            <!-- Products Tab -->
            <div id="panel-products">
                <div class="table-responsive">
                    <table class="archive-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Category</th>
                                <th>Price</th>
                                <th>Stock</th>
                                <th>Archived Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="archiveTableBody">
                            <tr>
                                <td colspan="6" class="text-center py-4">
                                    <div class="empty-state">
                                        <i class="fas fa-box-open"></i>
                                        <h5>No Archived Products</h5>
                                        <p class="text-muted">Loading archive data...</p>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Categories Tab -->
            <div id="panel-categories" style="display:none;">
                <div class="table-responsive">
                    <table class="archive-table">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Description</th>
                                <th>Products Inside</th>
                                <th>Archived Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="categoryArchiveTableBody">
                            <tr>
                                <td colspan="5" class="text-center py-4">
                                    <div class="empty-state">
                                        <i class="fas fa-layer-group"></i>
                                        <h5>No Archived Categories</h5>
                                        <p class="text-muted">Loading...</p>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <?php include 'includes/user_roles_modal.php'; ?>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="assets/js/archive.js"></script>
    <script src="assets/js/userManagement.js?v=20260814-1"></script>
    <script src="assets/js/notif.js"></script>
    <script src="assets/js/sidebar-nav.js"></script>
    <script>
      // Pass PHP variables to JavaScript
        const ajaxUrl = 'ajax/category_ajax.php';
        const isOwner = <?php echo $is_owner ? 'true' : 'false'; ?>;
        const currentUser = { id: '<?php echo $user_id; ?>' };
        <?php include 'includes/notifications.php'; ?>
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            loadArchivedProducts();
            
            <?php if ($toast_message): ?>
            showToast('<?php echo $toast_message['type']; ?>', '<?php echo $toast_message['title']; ?>', '<?php echo $toast_message['message']; ?>');
            <?php endif; ?>
        });
    </script>
</body>
</html>
