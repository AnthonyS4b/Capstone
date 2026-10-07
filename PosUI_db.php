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
    <link rel="icon" type="image/x-icon" href="assets/images/favicon.ico?v=20260923">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/images/favicon-32x32.png?v=20260923">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/images/favicon-16x16.png?v=20260923">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/images/apple-touch-icon.png?v=20260923">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(security_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <script src="assets/js/security.js?v=20260814-1" defer></script>
    <title>POS System · Espenida's Pet & Poultry Supply</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="assets/css/dashboardCSS.css">
    <link rel="stylesheet" href="assets/css/PosCSS.css?v=<?= filemtime(__DIR__ . '/assets/css/PosCSS.css') ?>">
<link rel="stylesheet" href="assets/css/reco.css">
    <link rel="stylesheet" href="assets/css/transitions.css">
    <link rel="stylesheet" href="assets/css/notif.css?v=<?= filemtime(__DIR__ . '/assets/css/notif.css') ?>">
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
    <script src="assets/js/responsive.js?v=<?= filemtime(__DIR__ . '/assets/js/responsive.js') ?>"></script>
    <link rel="stylesheet" href="assets/css/sidebar.css?v=<?= filemtime(__DIR__ . '/assets/css/sidebar.css') ?>">
    <link rel="stylesheet" href="assets/css/pos-payment.css?v=<?= filemtime(__DIR__ . '/assets/css/pos-payment.css') ?>">
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
        <?php include __DIR__ . '/includes/sidebar.php'; ?>
        
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
                                <input type="text" id="paymentAmount" placeholder="Enter amount" inputmode="decimal" autocomplete="off" maxlength="12">
                            </div>
                            <div class="quick-cash-wrap" aria-label="Quick cash amounts">
                                <div class="quick-cash-head">
                                    <small class="quick-cash-label">Quick cash <span class="quick-cash-hint">· tap bills to add</span></small>
                                    <button type="button" class="quick-cash-clear" id="quickCashClear" data-cash-target="paymentAmount">Clear</button>
                                </div>
                                <div class="quick-cash-grid">
                                    <?php foreach ([100, 200, 500, 1000, 5000, 10000] as $cashAmount): ?>
                                        <button type="button" class="quick-cash-btn" data-cash-amount="<?php echo $cashAmount; ?>" data-cash-target="paymentAmount" data-cash-mode="add">+₱<?php echo number_format($cashAmount); ?></button>
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
    <div class="modal fade pay-modal" id="paymentConfirmModal" tabindex="-1" aria-hidden="true" aria-labelledby="payModalTitle">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="pay-head">
                    <h2 class="pay-title" id="payModalTitle">Confirm payment</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="pay-body">
                    <!-- Amount due + collapsible item list -->
                    <div class="pay-due">
                        <span class="pay-label">Amount due</span>
                        <span class="pay-due-value" id="confirmTotal" data-total="0">₱0.00</span>
                        <details class="pay-items">
                            <summary id="confirmItemCount">Items</summary>
                            <div id="confirmItems"></div>
                        </details>
                    </div>

                    <!-- Payment method -->
                    <div class="pay-methods" role="radiogroup" aria-label="Payment method">
                        <input type="radio" class="btn-check" name="paymentMethod" id="methodCash" value="cash" checked>
                        <label class="pay-method" for="methodCash"><i class="fas fa-money-bill-wave" aria-hidden="true"></i>Cash</label>

                        <input type="radio" class="btn-check" name="paymentMethod" id="methodGcash" value="gcash">
                        <label class="pay-method" for="methodGcash"><i class="fas fa-mobile-alt" aria-hidden="true"></i>GCash</label>
                    </div>

                    <!-- Cash -->
                    <div id="cashPaymentSection">
                        <label class="pay-label" for="confirmPaymentAmount">Cash received</label>
                        <div class="pay-amount">
                            <span class="pay-currency">₱</span>
                            <input type="text" id="confirmPaymentAmount" placeholder="0.00" value="" inputmode="decimal" autocomplete="off" maxlength="12">
                        </div>
                        <div class="pay-quick" id="confirmQuickCash" aria-label="Quick cash amounts"></div>
                        <div class="pay-change" id="confirmChangeRow">
                            <span id="confirmChangeLabel">Change</span>
                            <span id="confirmChangeDisplay">₱0.00</span>
                        </div>
                    </div>

                    <!-- GCash -->
                    <div id="gcashPaymentSection" style="display: none;">
                        <div class="pay-gcash">
                            <div id="qrCodeContainer" onclick="enlargeQRCode()" title="Click to enlarge">
                                <div id="qrLoadingSpinner" class="spinner-border" role="status" style="display: none;">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <img id="gcashQRImage" src="assets/images/gcash.png" alt="GCash QR code"
                                     style="opacity: 0; transition: opacity 0.5s;"
                                     onload="window.imageLoaded && imageLoaded()"
                                     onerror="window.handleImageError && handleImageError()">
                                <div id="enlargeHint" style="display: none;"><i class="fas fa-search-plus"></i> Click to enlarge</div>
                            </div>
                            <div class="pay-gcash-side">
                                <p>Ask the customer to scan and pay the exact amount, then type the reference number from their receipt.</p>
                                <label class="pay-label" for="gcashReference">Reference number</label>
                                <input type="text" class="pay-input" id="gcashReference" placeholder="13 digits" maxlength="13" inputmode="numeric" pattern="\d{13}" autocomplete="off">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="pay-foot">
                    <button type="button" class="pay-btn pay-btn-ghost" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="pay-btn pay-btn-primary" id="confirmPaymentBtn">
                        <i class="fas fa-check" aria-hidden="true"></i><span id="confirmPaymentBtnText">Complete sale</span>
                    </button>
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
    <div class="modal fade rcpt-modal" id="receiptModal" tabindex="-1" aria-hidden="true" aria-labelledby="rcptTitle">
        <!-- Scrollable: header and buttons stay put, long item lists scroll inside the body -->
        <div class="modal-dialog modal-dialog-scrollable modal-dialog-centered">
            <div class="modal-content">
                <div class="rcpt-head">
                    <span class="rcpt-check" aria-hidden="true"><i class="fas fa-check"></i></span>
                    <div class="rcpt-head-text">
                        <h2 class="rcpt-title" id="rcptTitle">Sale complete</h2>
                        <p class="rcpt-sub" id="rcptSummary"></p>
                    </div>
                    <div class="rcpt-change" id="rcptChange" hidden>
                        <span class="rcpt-change-label">Change due</span>
                        <span class="rcpt-change-value" id="rcptChangeValue">₱0.00</span>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="receiptDetails"></div>
                </div>
                <div class="modal-footer receipt-actions">
                    <button type="button" class="rcpt-btn rcpt-btn-ghost" onclick="printReceipt()">
                        <i class="fas fa-print" aria-hidden="true"></i>Print receipt
                    </button>
                    <button type="button" class="rcpt-btn rcpt-btn-primary" data-bs-dismiss="modal" onclick="window.location.href='PosUI_db.php'">
                        <i class="fas fa-plus" aria-hidden="true"></i>New sale
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/includes/active_promos_modal.php'; ?>

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
    <script src="assets/js/receipt.js?v=<?= filemtime(__DIR__ . '/assets/js/receipt.js') ?>"></script>
    <script src="assets/js/PosJS.js?v=<?php echo time(); ?>"></script>
    <script src="assets/js/userManagement.js?v=<?= filemtime(__DIR__ . '/assets/js/userManagement.js') ?>"></script>
    <script src="assets/js/notif.js?v=<?= filemtime(__DIR__ . '/assets/js/notif.js') ?>"></script>
    <script src="assets/js/active_strategies.js?v=<?= filemtime(__DIR__ . '/assets/js/active_strategies.js') ?>"></script>
    <script src="assets/js/sidebar-nav.js"></script>
</body>
</html>
