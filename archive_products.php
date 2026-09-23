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
    <link rel="icon" type="image/x-icon" href="assets/images/favicon.ico?v=20260923">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/images/favicon-32x32.png?v=20260923">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/images/favicon-16x16.png?v=20260923">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/images/apple-touch-icon.png?v=20260923">
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
    <div class="spinner-overlay" id="loadingSpinner">
        <div class="loading-spinner"></div>
    </div>

    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>

    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <!-- MAIN CONTENT -->
    <div class="main-content">
        <!-- Welcome Banner -->
        <div class="welcome-banner">
            <div class="welcome-text">
                <h1>Archive</h1>
                <p>Archived products and categories. Restore them to inventory or delete them for good.</p>
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

        <!-- Summary -->
        <p class="inv-summary" id="statsContainer">
            <span><strong id="totalArchived">0</strong> archived products</span>
            <span>Oldest archived <strong id="oldestArchive">—</strong></span>
        </p>

        <!-- Archive Table Section -->
        <div class="archive-section">
            <div class="section-header">
                <!-- Tabs -->
                <ul class="nav nav-tabs" id="archiveTabs">
                    <li class="nav-item">
                        <a class="nav-link active" id="tab-products" href="#" onclick="switchTab('products'); return false;">Products</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="tab-categories" href="#" onclick="switchTab('categories'); return false;">Categories</a>
                    </li>
                </ul>
                <div class="inv-toolbar-actions">
                    <button class="inv-btn inv-btn-quiet" onclick="refreshArchive()">
                        <i class="fas fa-sync-alt"></i>Refresh
                    </button>
                    <?php if ($is_owner): ?>
                    <button class="inv-btn inv-btn-danger" onclick="emptyArchive()">
                        <i class="fas fa-trash-alt"></i>Empty archive
                    </button>
                    <?php endif; ?>
                </div>
            </div>


            <!-- Products Tab -->
            <div id="panel-products">
                <div class="table-responsive">
                    <table class="archive-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Category</th>
                                <th class="inv-num">Price</th>
                                <th class="inv-num">Stock</th>
                                <th>Archived</th>
                                <th><span class="visually-hidden">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody id="archiveTableBody">
                            <tr>
                                <td colspan="6" class="text-center py-4">
                                    <p class="inv-empty-text">Loading archive…</p>
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
                                <th class="inv-num">Products</th>
                                <th>Archived</th>
                                <th><span class="visually-hidden">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody id="categoryArchiveTableBody">
                            <tr>
                                <td colspan="5" class="text-center py-4">
                                    <p class="inv-empty-text">Loading…</p>
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
    <script src="assets/js/confirm-dialog.js?v=<?= filemtime(__DIR__ . '/assets/js/confirm-dialog.js') ?>"></script>
    <script src="assets/js/archive.js?v=<?= filemtime(__DIR__ . '/assets/js/archive.js') ?>"></script>
    <script src="assets/js/userManagement.js?v=<?= filemtime(__DIR__ . '/assets/js/userManagement.js') ?>"></script>
    <script src="assets/js/notif.js?v=<?= filemtime(__DIR__ . '/assets/js/notif.js') ?>"></script>
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
