<?php
session_start();

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
$dailyStmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(t.total_amount), 0)           AS daily_sales,
        COUNT(DISTINCT t.id)                        AS orders_today,
        COALESCE(SUM(ti.quantity), 0)               AS items_sold
    FROM transactions t
    JOIN sales s          ON s.transaction_id = t.id
    JOIN transaction_items ti ON ti.transaction_id = t.id
    WHERE DATE(t.created_at) = CURDATE()
      AND s.cashier_name = :name
      AND s.status = 'completed'
");
$dailyStmt->execute([':name' => $first_name . ' ' . $last_name]);
$dailyRow   = $dailyStmt->fetch(PDO::FETCH_ASSOC);
$daily_sales  = (float)($dailyRow['daily_sales']  ?? 0);
$orders_today = (int)  ($dailyRow['orders_today'] ?? 0);
$items_sold   = (int)  ($dailyRow['items_sold']   ?? 0);

// Total customers served today (each transaction = 1 customer)
$customers_today = $orders_today;

// ── This employee's own past transactions ─────────────────────────────────────
$myTxStmt = $pdo->prepare("
    SELECT
        t.id                                        AS raw_id,
        CONCAT('#TRX-', LPAD(t.id, 5, '0'))        AS trx_id,
        t.total_amount                              AS amount,
        COALESCE(s.status, 'completed')             AS status,
        DATE_FORMAT(t.created_at, '%b %d, %Y')     AS date,
        DATE_FORMAT(t.created_at, '%h:%i %p')      AS time,
        (SELECT COUNT(*) FROM transaction_items ti WHERE ti.transaction_id = t.id) AS item_count
    FROM transactions t
    JOIN sales s ON s.transaction_id = t.id
    WHERE s.cashier_name = :name
    ORDER BY t.created_at DESC
    LIMIT 20
");
$myTxStmt->execute([':name' => $first_name . ' ' . $last_name]);
$my_transactions = $myTxStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard · Espenida's Pet & Poultry Supply</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="assets/css/dashboardCSS.css">
    <link rel="stylesheet" href="assets/css/usermod.css">
    <link rel="stylesheet" href="assets/css/emp.css">
    <link rel="stylesheet" href="assets/css/notif.css">
    <link rel="stylesheet" href="assets/css/responsive.css">
    <script src="assets/js/responsive.js"></script>
</head>
<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="fas fa-bars"></i>
    </button>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <div class="toast-container" id="toastContainer"></div>

    <!-- ═══ SIDEBAR (employee — no Recommendations, no Sales Transactions) ═══ -->
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
                <li><a class="dropdown-item" href="PosUI_db.php"><i class="fas fa-cash-register"></i> Point of Sale</a></li>
                <!-- Sales Transactions removed — employees use "My Transactions" on this dashboard -->
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
            </ul>
        </div>

        <!-- Recommendations removed for employees -->

        <div class="spacer"></div>
        <button class="logout-btn" id="logoutBtn">
            <i class="fas fa-sign-out-alt"></i><span>Log out</span>
        </button>
    </div>

    <!-- ═══ MAIN CONTENT ═══ -->
    <div class="main-content">

        <!-- Welcome Banner -->
        <div class="welcome-banner" style="position:relative;background-color:#2c5530;overflow:visible;">
            <img src="assets/images/brand-logos.svg" alt="" style="position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover;opacity:.15;pointer-events:none;">
            <div style="position:relative;z-index:2;width:100%;display:flex;justify-content:space-between;align-items:center;">
                <div class="welcome-text">
                    <h1 style="font-size:22px!important;margin-bottom:3px;">
                        <i class="fas fa-paw"></i>
                        Welcome back, <?php echo htmlspecialchars($first_name . ' ' . $last_name); ?>!
                    </h1>
                    <p style="font-size:13px!important;opacity:.9;">
                        <?php echo date('l, F j, Y'); ?> · <?php echo htmlspecialchars($position ?: ucfirst($role)); ?>
                    </p>
                </div>
                <div class="d-flex align-items-center gap-3">
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
                    <div class="date-display" style="font-size:14px!important;padding:8px 16px;">
                        <i class="fas fa-calendar-alt me-2"></i><?php echo date('M d, Y'); ?>
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
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-peso-sign"></i></div>
                <div class="stat-info">
                    <div class="stat-label">My Sales Today</div>
                    <div class="stat-value">₱<?php echo number_format($daily_sales); ?></div>
                    <div class="stat-change"><i class="fas fa-calendar-day"></i> <?php echo date('M d, Y'); ?></div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-users"></i></div>
                <div class="stat-info">
                    <div class="stat-label">Customers Today</div>
                    <div class="stat-value"><?php echo number_format($customers_today); ?></div>
                    <div class="stat-change"><i class="fas fa-receipt"></i> Transactions served</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-boxes-stacked"></i></div>
                <div class="stat-info">
                    <div class="stat-label">Items Sold Today</div>
                    <div class="stat-value"><?php echo number_format($items_sold); ?></div>
                    <div class="stat-change"><i class="fas fa-box"></i> Units processed</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-shopping-cart"></i></div>
                <div class="stat-info">
                    <div class="stat-label">Orders Today</div>
                    <div class="stat-value"><?php echo number_format($orders_today); ?></div>
                    <div class="stat-change"><i class="fas fa-check-circle"></i> Completed transactions</div>
                </div>
            </div>
        </div>

        <!-- My Transactions Table -->
        <div class="table-container">
            <div class="section-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                <h5 style="margin:0;font-size:16px;font-weight:600;color:#1e293b;display:flex;align-items:center;gap:8px;">
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
            <table class="custom-table">
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
                        <td><strong style="color:#8B4513;"><?php echo htmlspecialchars($tx['trx_id']); ?></strong></td>
                        <td><?php echo htmlspecialchars($tx['date']); ?></td>
                        <td style="color:#64748b;"><?php echo htmlspecialchars($tx['time']); ?></td>
                        <td>
                            <span style="background:#f1f5f9;padding:2px 8px;border-radius:20px;font-size:12px;font-weight:600;">
                                <?php echo $tx['item_count']; ?> item<?php echo $tx['item_count'] != 1 ? 's' : ''; ?>
                            </span>
                        </td>
                        <td><strong style="color:#2c5530;">₱<?php echo number_format((float)$tx['amount'], 2); ?></strong></td>
                        <td>
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
                        <td>
                            <button class="btn-view-receipt"
                                onclick="viewReceipt(<?php echo (int)$tx['raw_id']; ?>, '<?php echo htmlspecialchars($tx['trx_id']); ?>')">
                                <i class="fas fa-eye me-1"></i>View
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
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

    <!-- ═══ MY PROFILE MODAL ═══ -->
    <div class="modal fade" id="userProfileModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-md">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h5 class="modal-title"><i class="fas fa-user-circle me-2"></i>My Profile</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-3">
                    <div class="d-flex align-items-center mb-3 p-2 bg-light rounded">
                        <div class="me-3"><i class="fas fa-user-circle fa-3x" style="color:#8B4513;"></i></div>
                        <div>
                            <h5 class="mb-1"><?php echo htmlspecialchars($first_name . ' ' . $last_name); ?></h5>
                            <div class="d-flex align-items-center">
                                <span class="badge bg-secondary me-2">
                                    <i class="fas fa-user me-1"></i>Employee
                                </span>
                                <small class="text-muted">ID: <?php echo htmlspecialchars($user_id); ?></small>
                            </div>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6"><div class="p-2 border rounded"><small class="text-muted d-block">Position</small><span class="fw-bold"><?php echo htmlspecialchars($position ?: 'Employee'); ?></span></div></div>
                        <div class="col-6"><div class="p-2 border rounded"><small class="text-muted d-block">Email</small><span class="fw-bold small"><?php echo htmlspecialchars($email ?: 'Not provided'); ?></span></div></div>
                        <div class="col-6"><div class="p-2 border rounded"><small class="text-muted d-block">Today's Sales</small><span class="fw-bold">₱<?php echo number_format($daily_sales); ?></span></div></div>
                        <div class="col-6"><div class="p-2 border rounded"><small class="text-muted d-block">Transactions Today</small><span class="fw-bold"><?php echo $orders_today; ?></span></div></div>
                    </div>

                    <!-- Change My PIN -->
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
                                        maxlength="4" placeholder="Enter current PIN">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small mb-1">New PIN</label>
                                    <input type="password" class="form-control form-control-sm" id="newPinSelf"
                                        maxlength="4" placeholder="4-digit new PIN">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small mb-1">Confirm New PIN</label>
                                    <input type="password" class="form-control form-control-sm" id="confirmPinSelf"
                                        maxlength="4" placeholder="Repeat new PIN">
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
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ SWITCH ACCOUNT MODAL ═══ -->
    <div class="modal fade" id="switchAccountModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-exchange-alt me-2"></i>Switch Account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-4">
                        <div class="switch-account-icon"><i class="fas fa-users fa-3x"></i></div>
                        <h5 class="mt-3">Select Account to Switch</h5>
                        <p class="text-muted">Choose a different account to continue working.</p>
                    </div>
                    <div class="current-user-info mb-4 p-3 bg-light rounded">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-user-circle fa-2x me-3" style="color:#2c5530;"></i>
                            <div>
                                <span class="badge bg-success mb-1">Current Account</span>
                                <h6 class="mb-1"><?php echo htmlspecialchars($first_name . ' ' . $last_name); ?></h6>
                                <small class="text-muted"><?php echo htmlspecialchars($position ?: ucfirst($role)); ?></small>
                            </div>
                        </div>
                    </div>
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
                            $stmt = $pdo->prepare("SELECT id, first_name, last_name, email, role, position FROM users WHERE id != ? ORDER BY id DESC LIMIT 5");
                            $stmt->execute([$user_id]);
                            $otherUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            if (!empty($otherUsers)):
                                foreach ($otherUsers as $account):
                        ?>
                        <div class="account-item p-3 mb-2 border rounded"
                             onclick="selectAccount(<?php echo $account['id']; ?>, '<?php echo htmlspecialchars($account['first_name'] . ' ' . $account['last_name']); ?>', '<?php echo htmlspecialchars($account['position'] ?: ucfirst($account['role'])); ?>', '<?php echo htmlspecialchars($first_name . ' ' . $last_name); ?>', '<?php echo htmlspecialchars($position ?: ucfirst($role)); ?>')">
                            <div class="d-flex align-items-center justify-content-between">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-user-circle fa-2x me-3" style="color:<?php echo $account['role'] === 'owner' ? '#e67e22' : '#8B4513'; ?>;"></i>
                                    <div>
                                        <h6 class="mb-1"><?php echo htmlspecialchars($account['first_name'] . ' ' . $account['last_name']); ?></h6>
                                        <small class="text-muted">
                                            <span class="badge bg-<?php echo $account['role'] === 'owner' ? 'warning' : 'secondary'; ?> me-2"><?php echo ucfirst($account['role']); ?></span>
                                            <?php echo htmlspecialchars($account['position'] ?: 'No position'); ?>
                                        </small>
                                    </div>
                                </div>
                                <i class="fas fa-chevron-right text-muted"></i>
                            </div>
                        </div>
                        <?php endforeach;
                        else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-users-slash fa-3x mb-3 text-muted"></i>
                            <p class="text-muted">No other accounts available</p>
                        </div>
                        <?php endif; } catch (PDOException $e) {
                            echo '<div class="alert alert-danger">Unable to load accounts.</div>';
                        } ?>
                    </div>
                    <div id="quickLoginForm" style="display:none;" class="mt-4 p-3 border-top">
                        <h6 class="mb-3"><i class="fas fa-lock me-2"></i>Enter PIN for <span id="selectedAccountName"></span></h6>
                        <form id="switchAccountForm" method="POST" action="switch_account.php">
                            <input type="hidden" name="user_id" id="selectedUserId">
                            <div class="mb-3">
                                <label class="form-label">PIN</label>
                                <input type="password" class="form-control" id="accountPin" name="pin" maxlength="4" pattern="\d{4}" placeholder="****" required>
                            </div>
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-brown"><i class="fas fa-exchange-alt me-2"></i>Switch Account</button>
                                <button type="button" class="btn btn-outline-secondary" onclick="cancelAccountSelection()">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
    const phpToastMessage = <?php echo $toast_message ? json_encode($toast_message) : 'null'; ?>;
    // ── Notification seed data (employee) ──────────────────────────────────────
    const phpNotifications = <?php
        $notifs = [];
        // Welcome note on shift start
        $notifs[] = [
            'id'    => 'shift-start',
            'type'  => 'info',
            'icon'  => 'fa-clock',
            'title' => 'Shift Started',
            'body'  => 'Good ' . (date('H') < 12 ? 'morning' : (date('H') < 17 ? 'afternoon' : 'evening')) . ', ' . htmlspecialchars($first_name) . '! Have a great shift.',
            'time'  => date('h:i A'),
            'read'  => false,
        ];
        // Sales milestone nudge
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

        // ── Low-stock alerts (stock < 10) ──
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
                $notifs[] = [
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

        // ── Latest transaction ──
        try {
            $latTxStmt = $pdo->query("
                SELECT CONCAT('#TRX-', LPAD(t.id, 5, '0')) AS trx_id,
                       COALESCE(s.cashier_name, 'N/A') AS cashier,
                       t.total_amount AS amount
                FROM transactions t LEFT JOIN sales s ON s.transaction_id = t.id
                ORDER BY t.created_at DESC LIMIT 1
            ");
            $lt = $latTxStmt->fetch(PDO::FETCH_ASSOC);
            if ($lt) {
                $notifs[] = [
                    'id'    => 'trx-latest',
                    'type'  => 'info',
                    'icon'  => 'fa-receipt',
                    'title' => 'New Transaction',
                    'body'  => 'Transaction ' . $lt['trx_id'] . ' processed by ' . $lt['cashier'],
                    'time'  => 'Today',
                    'read'  => false,
                ];
            }
        } catch (PDOException $e) { /* skip */ }

        echo json_encode($notifs);
    ?>;
    </script>
    <script src="assets/js/emp.js"></script>
    <script src="assets/js/notif.js"></script>
    <script src="assets/js/sidebar-nav.js"></script>
</body>
</html>