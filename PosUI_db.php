<?php
session_start();
require_once __DIR__ . '/includes/security.php';
// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['toast_message'] = [
        'type' => 'warning',
        'title' => 'Access Denied!',
        'message' => 'Please login first to access the POS system.'
    ];
    header("Location: Login.php");
    exit();
}

// Get user info from session
$first_name = $_SESSION['first_name'] ?? 'User';
$last_name = $_SESSION['last_name'] ?? '';
$role = $_SESSION['role'] ?? 'employee';
$position = $_SESSION['position'] ?? '';
$user_id = $_SESSION['user_id'] ?? null;

// CRITICAL: Validate user_id is not empty
if (empty($user_id)) {
    error_log("PosUI_db.php: User ID is empty in session. Session data: " . print_r($_SESSION, true));
    $_SESSION['toast_message'] = [
        'type' => 'error',
        'title' => 'Session Error',
        'message' => 'User session is invalid. Please login again.'
    ];
    header("Location: Login.php");
    exit();
}

// Convert to integer for safety
$user_id = (int)$user_id;
$full_name = $first_name . ' ' . $last_name;

// Local logout removed - now handled by logout.php

// Initialize session cart if not exists
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Get categories from database for filters
require_once 'controllers/CategoryController.php';
$categoryController = new CategoryController();
$categoriesResult = $categoryController->getAllCategories();
$categories = $categoriesResult['success'] ? $categoriesResult['data'] : [];
$categories = array_filter($categories, function($cat) {
    return isset($cat['status']) && $cat['status'] === 'active';
});

$is_owner = ($role === 'owner' || $role === 'admin');
$toast_message = isset($_SESSION['toast_message']) ? $_SESSION['toast_message'] : null;
if ($toast_message) {
    unset($_SESSION['toast_message']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(security_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <script src="assets/js/security.js?v=20260814-1" defer></script>
    <title>POS System · Espenida's Pet & Poultry Supply</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="assets/css/dashboardCSS.css">
    <link rel="stylesheet" href="assets/css/PosCSS.css?v=20260814-1">
<link rel="stylesheet" href="assets/css/reco.css">
    <link rel="stylesheet" href="assets/css/transitions.css">
    <link rel="stylesheet" href="assets/css/notif.css">
    <style>
        /* Page-specific only. Product card, badge and stock colours live in
           PosCSS.css; duplicating them here silently overrode that file. */
        .scanner-status-text {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: var(--ink-muted);
        }

        .scanner-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--positive);
            display: inline-block;
            flex-shrink: 0;
            animation: scannerPulse 2.4s ease-in-out infinite;
        }

        .scanner-dot.scanning {
            background: var(--warning);
            animation: none;
        }

        @keyframes scannerPulse {
            0%, 100% { opacity: 1; }
            50%      { opacity: 0.35; }
        }

        @media (prefers-reduced-motion: reduce) {
            .scanner-dot { animation: none; }
        }
    </style>
    <link rel="stylesheet" href="assets/css/responsive.css">
    <script src="assets/js/responsive.js"></script>
</head>
<body class="page-loading" data-cashier="<?php echo htmlspecialchars($full_name); ?>" 
      data-role="<?php echo htmlspecialchars($role); ?>"
      data-user-id="<?php echo htmlspecialchars($user_id); ?>" onload="document.body.classList.remove('page-loading')">
    
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="fas fa-bars"></i>
    </button>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>

    <!-- POS Container -->
    <div class="pos-container">
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
                <button class="dropdown-toggle active" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-cash-register"></i><span>Point of Sale</span>
                </button>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item active" href="PosUI_db.php"><i class="fas fa-cash-register"></i>Point of Sale</a></li>
                    <?php if ($is_owner): ?>
                    <li><a class="dropdown-item" href="sales_transactions.php"><i class="fas fa-history"></i>Sales Transactions</a></li>
                    <?php endif; ?>
                </ul>
            </div>

            <div class="section-title"><span>INVENTORY MANAGEMENT</span></div>
            <div class="dropdown">
                <button class="dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
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
                        <li><a class="dropdown-item" href="#roleManagementModal" data-bs-toggle="modal" data-bs-target="#roleManagementModal"><i class="fas fa-lock"></i> User Roles</a></li>
                        <li><a class="dropdown-item" href="admin_data_tools.php"><i class="fas fa-database"></i> Backup &amp; Export</a></li>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="spacer"></div>
            <button type="button" class="logout-btn" id="logoutBtn">
                <i class="fas fa-sign-out-alt"></i><span>Log out</span>
            </button>
        </div>
        
        <!-- Main POS Area -->
        <div class="pos-main">
            <div class="pos-header">
                <h1><i class="fas fa-cash-register"></i> Point of Sale System</h1>
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
                    <button class="header-btn" onclick="openActiveStrategiesModal()">
                        <i class="fas fa-tags me-1"></i> Active Promos
                    </button>
                    <button class="refresh-btn" onclick="loadProducts()"><i class="fas fa-sync-alt"></i> Refresh</button>
                    <button class="category-btn" onclick="window.location.href='PosUI_db.php'"><i class="fas fa-plus"></i> New Transaction</button>
                </div>
            </div>
            
            <div class="pos-content">
                <div class="products-section">
                    <div class="products-header">
                        <div class="barcode-scanner">
                            <span class="scanner-status-text">
                                <span class="scanner-dot" id="scannerDot"></span>
                                Scanner ready — just scan a barcode
                            </span>
                            <input type="text" id="barcodeInput"
                                   autocomplete="off" autocorrect="off"
                                   autocapitalize="off" spellcheck="false"
                                   style="position:absolute;opacity:0;width:1px;height:1px;pointer-events:none;"
                                   aria-label="Barcode scanner input">
                        </div>
                        
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" id="searchInput" placeholder="Search products by name or SKU...">
                        </div>
                        
                        <div class="category-filters" id="categoryFilters">
                            <button class="category-btn active" data-category="all">All Products</button>
                            <?php foreach ($categories as $category): ?>
                                <button class="category-btn" data-category="<?php echo $category['id']; ?>">
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div class="products-grid" id="productsGrid">
                        <div class="text-center py-5">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading products...</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="cart-section">
                    <div class="cart-header">
                        <h2><i class="fas fa-shopping-cart"></i> Current Order</h2>
                        <button class="clear-cart" id="clearCartBtn"><i class="fas fa-trash"></i> Clear</button>
                    </div>
                    
                    <div class="cart-items" id="cartItems">
                        <div class="empty-cart">
                            <i class="fas fa-shopping-cart"></i>
                            <h4>Cart is Empty</h4>
                            <p>Click on products or scan barcode to add them to cart</p>
                        </div>
                    </div>
                    
                    <div class="cart-summary">
                        <div class="summary-row"><span>Subtotal:</span><span id="subtotal">₱0.00</span></div>
                        <div class="summary-row total"><span>Total:</span><span id="total">₱0.00</span></div>
                        
                        <div class="payment-section">
                            <div class="payment-input">
                                <span>₱</span>
                                <input type="number" id="paymentAmount" placeholder="Enter amount" min="0" step="0.01">
                            </div>
                            <div class="quick-cash-wrap" aria-label="Quick cash amounts">
                                <small class="quick-cash-label">Quick cash</small>
                                <div class="quick-cash-grid">
                                    <?php foreach ([100, 200, 500, 1000, 5000, 10000] as $cashAmount): ?>
                                        <button type="button" class="quick-cash-btn" data-cash-amount="<?php echo $cashAmount; ?>" data-cash-target="paymentAmount">₱<?php echo number_format($cashAmount); ?></button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="change-amount" id="changeAmount">Change: ₱0.00</div>
                            <button class="checkout-btn" id="checkoutBtn" disabled>
                                <i class="fas fa-check-circle"></i> Process Payment
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Payment Confirmation Modal -->
    <div class="modal fade" id="paymentConfirmModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
                <div class="modal-header" style="padding: 10px 15px;">
                    <h6 class="modal-title"><i class="fas fa-credit-card me-1"></i>Confirm Payment</h6>
                    <button type="button" class="btn-close btn-close-white btn-sm" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body p-3">
                    <!-- Order Summary -->
                    <div class="bg-light rounded p-2 mb-2" style="font-size: 13px;">
                        <div class="d-flex justify-content-between fw-bold mb-1">
                            <span>Total:</span>
                            <span id="confirmTotal" style="color:#2c5530;">₱0.00</span>
                        </div>
                        <div id="confirmItems" style="max-height: 100px; overflow-y: auto; font-size: 12px;"></div>
                    </div>

                    <div class="btn-group w-100 mb-2" role="group">
                        <input type="radio" class="btn-check" name="paymentMethod" id="methodCash" value="cash" checked>
                        <label class="btn btn-outline-success btn-sm py-1" for="methodCash">
                            <i class="fas fa-money-bill-wave"></i> Cash
                        </label>
                        
                        <input type="radio" class="btn-check" name="paymentMethod" id="methodGcash" value="gcash">
                        <label class="btn btn-outline-primary btn-sm py-1" for="methodGcash">
                            <i class="fas fa-mobile-alt"></i> GCash
                        </label>
                    </div>

                    <!-- Cash Section -->
                    <div id="cashPaymentSection">
                        <div class="input-group input-group-sm mb-1">
                            <span class="input-group-text bg-white">₱</span>
                            <input type="number" class="form-control" id="confirmPaymentAmount" placeholder="Amount" value="" step="0.01">
                        </div>
                        <div class="quick-cash-wrap quick-cash-modal" aria-label="Quick cash amounts">
                            <small class="quick-cash-label">Quick cash</small>
                            <div class="quick-cash-grid">
                                <?php foreach ([100, 200, 500, 1000, 5000, 10000] as $cashAmount): ?>
                                    <button type="button" class="quick-cash-btn" data-cash-amount="<?php echo $cashAmount; ?>" data-cash-target="confirmPaymentAmount">₱<?php echo number_format($cashAmount); ?></button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="bg-light rounded p-1 text-center mb-2">
                            <small class="text-muted">Change:</small>
                            <span class="fw-bold ms-2" style="color:#10b981;" id="confirmChangeDisplay">₱0.00</span>
                        </div>
                    </div>

                    <!-- GCash Section -->
                    <div id="gcashPaymentSection" style="display: none;">
                        <div class="text-center mb-2">
                            <div id="qrCodeContainer" style="position: relative; display: inline-block; cursor: pointer;" onclick="enlargeQRCode()">
                                <div id="qrLoadingSpinner" class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 10; display: none;">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <img id="gcashQRImage" src="assets/images/gcash.png" alt="GCash QR Code" 
        style="height: 120px; width: auto; opacity: 0; transition: opacity 0.5s; border: 2px solid #e2e8f0; border-radius: 8px;" 
        onload="window.imageLoaded && imageLoaded()"
        onerror="window.handleImageError && handleImageError()">
                                <div id="enlargeHint" class="small text-muted mt-1" style="display: none;">
                                    <i class="fas fa-search-plus"></i> Click to enlarge
                                </div>
                            </div>
                            <input type="text" class="form-control form-control-sm mt-2" id="gcashReference" placeholder="6-digit Reference #" maxlength="6" inputmode="numeric" pattern="\d{6}">
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer p-2">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-sm btn-success" id="confirmPaymentBtn">Pay</button>
                </div>
            </div>
        </div>
    </div>

    <!-- QR Code Enlarged Modal -->
    <div class="modal fade" id="qrEnlargeModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header" style="background: #4a6fa5; color: white; padding: 10px 15px;">
                    <h6 class="modal-title"><i class="fas fa-qrcode me-1"></i>GCash QR Code</h6>
                    <button type="button" class="btn-close btn-close-white btn-sm" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center p-4">
                    <img src="assets/images/gcash.png" alt="GCash QR Code - Enlarged" style="max-width: 100%; height: auto; max-height: 400px;" class="img-fluid">
                    <p class="mt-3 mb-0 small text-muted">Scan this QR code with your GCash app</p>
                </div>
                <div class="modal-footer p-2 justify-content-center">
                    <button type="button" class="btn btn-sm btn-primary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Receipt Modal -->
    <div class="modal fade" id="receiptModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header" style="background: #2c5530; color: white; border: none; padding: 10px 15px;">
                    <h5 class="modal-title"><i class="fas fa-receipt me-2"></i>Receipts</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" style="padding: 15px; background: white;">
                    <div id="receiptDetails"></div>
                    <div style="display: flex; gap: 10px; margin-top: 20px;">
                        <button class="btn btn-primary btn-sm" style="flex:1; background:#4a6fa5; border:none;" onclick="printReceipt()">
                            <i class="fas fa-print"></i> Print
                        </button>
                        <button class="btn btn-success btn-sm" style="flex:1; background:#2c5530; border:none;" data-bs-dismiss="modal" onclick="window.location.href='PosUI_db.php'">
                            <i class="fas fa-plus"></i> New Sale
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Active Strategies Modal -->
    <div class="modal fade" id="activeStrategiesModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-tags me-2"></i>Active Promotional Strategies</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" style="font-size: 14px;">
                            <thead class="table-light">
                                <tr>
                                    <th>Product</th>
                                    <th>Strategy Name</th>
                                    <th>Price Impact</th>
                                    <th>Duration</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody id="activeStrategiesTableBody">
                                <!-- Loaded via JS -->
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <?php include 'includes/user_roles_modal.php'; ?>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        const LOGGED_CASHIER = "<?php echo htmlspecialchars($full_name); ?>";
        const LOGGED_ROLE    = "<?php echo htmlspecialchars($role); ?>";
        const LOGGED_USER_ID = <?php echo (int)$user_id; ?>; // integer, not a string
        <?php
        if ($toast_message) {
            $t_type    = addslashes($toast_message['type']);
            $t_title   = addslashes($toast_message['title']);
            $t_message = addslashes($toast_message['message']);
            echo "const INITIAL_TOAST = { type: '{$t_type}', title: '{$t_title}', message: '{$t_message}' };";
        } else {
            echo "const INITIAL_TOAST = null;";
        }
        ?>
            // ... existing constants ...
    const LOGGED_FIRST_NAME = "<?php echo htmlspecialchars($first_name); ?>";
    const LOGGED_LAST_NAME = "<?php echo htmlspecialchars($last_name); ?>";
    const currentUser = { id: '<?php echo $user_id; ?>' };
    <?php include 'includes/notifications.php'; ?>
    </script>
    <script src="assets/js/PosJS.js?v=<?php echo time(); ?>"></script>
    <script src="assets/js/userManagement.js?v=20260814-1"></script>
    <script src="assets/js/notif.js"></script>
    <script src="assets/js/active_strategies.js?v=20260905-2"></script>
    <script src="assets/js/sidebar-nav.js"></script>
</body>
</html>
