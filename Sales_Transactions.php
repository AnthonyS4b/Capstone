<?php
// Sales_Transactions.php
session_start();
require_once __DIR__ . '/includes/security.php';
require_once 'config/config.php';

if (!isset($_SESSION['user_id'])) {
    $_SESSION['toast_message'] = [
        'type'    => 'warning',
        'title'   => 'Access Denied!',
        'message' => 'Please login first.'
    ];
    header("Location: Login.php");
    exit();
}

$user_id    = $_SESSION['user_id'];
$first_name = $_SESSION['first_name'] ?? 'User';
$last_name  = $_SESSION['last_name']  ?? '';
$role       = $_SESSION['role']       ?? 'employee';
$position   = $_SESSION['position']   ?? '';

// Local logout removed - now handled by logout.php

$is_owner      = ($role === 'owner' || $role === 'admin');

// Employees are not allowed to access sales transactions
if (!$is_owner) {
    $_SESSION['toast_message'] = [
        'type'    => 'warning',
        'title'   => 'Access Denied!',
        'message' => 'Sales Transactions are restricted to owners only.'
    ];
    header("Location: employee_dashboard.php");
    exit();
}

$toast_message = $_SESSION['toast_message'] ?? null;
if ($toast_message) unset($_SESSION['toast_message']);
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
    <title>Sales Transactions · Espenida's Pet & Poultry Supply</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="assets/css/dashboardCSS.css">
    <link rel="stylesheet" href="assets/css/PosCSS.css">
    <link rel="stylesheet" href="assets/css/reco.css">
    <link rel="stylesheet" href="assets/css/salestrans.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/salestrans.css') ?: time(); ?>">
    <link rel="stylesheet" href="assets/css/transitions.css">
    <link rel="stylesheet" href="assets/css/notif.css?v=<?= filemtime(__DIR__ . '/assets/css/notif.css') ?>">
    <link rel="stylesheet" href="assets/css/responsive.css">
    <script src="assets/js/responsive.js?v=<?= filemtime(__DIR__ . '/assets/js/responsive.js') ?>"></script>
    <link rel="stylesheet" href="assets/css/sidebar.css?v=<?= filemtime(__DIR__ . '/assets/css/sidebar.css') ?>">
</head>
<body class="page-loading" onload="document.body.classList.remove('page-loading')">
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="fas fa-bars"></i>
    </button>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="spinner-overlay" id="loadingSpinner">
        <div class="loading-spinner"></div>
    </div>

    <div class="toast-container" id="toastContainer"></div>

    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <!-- ═══════════════ MAIN CONTENT ═══════════════ -->
    <div class="main-content">
        <div class="welcome-banner">
            <div class="welcome-text">
                <h1><i class="fas fa-history me-2"></i>Sales Transactions</h1>
                <p>View and manage all your sales transactions</p>
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

        <!-- Stats -->
        <div class="stats-grid" id="statsContainer">
            <?php
            // Label, icon tone and element id for each readout. Matches the
            // card structure used on the dashboard.
            $txn_stats = [
                ['id' => 'totalTransactions', 'tone' => 'count',   'icon' => 'fa-receipt',      'label' => 'Total Transactions', 'value' => '0',  'note' => 'All time · voided excluded', 'note_icon' => 'fa-clock-rotate-left'],
                ['id' => 'totalSales',        'tone' => 'revenue', 'icon' => 'fa-peso-sign',    'label' => 'Total Sales',        'value' => '₱0', 'note' => 'All-time revenue',            'note_icon' => 'fa-coins'],
                ['id' => 'todaySales',        'tone' => 'today',   'icon' => 'fa-calendar-day', 'label' => "Today's Sales",      'value' => '₱0', 'note' => date('l, M j'),                'note_icon' => 'fa-calendar'],
                ['id' => 'avgTransaction',    'tone' => 'average', 'icon' => 'fa-chart-line',   'label' => 'Average Transaction','value' => '₱0', 'note' => 'Per sale, all time',          'note_icon' => 'fa-scale-balanced'],
            ];
            foreach ($txn_stats as $card): ?>
            <div class="stat-card">
                <div class="stat-head">
                    <span class="stat-label"><?php echo $card['label']; ?></span>
                    <span class="stat-icon <?php echo $card['tone']; ?>"><i class="fa-solid <?php echo $card['icon']; ?>"></i></span>
                </div>
                <div class="stat-value is-loading" id="<?php echo $card['id']; ?>"><?php echo $card['value']; ?></div>
                <div class="stat-change is-flat">
                    <i class="fa-solid <?php echo $card['note_icon']; ?>"></i>
                    <span><?php echo htmlspecialchars($card['note']); ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Filters -->
        <div class="filter-section">
            <div class="filter-group">
                <label for="searchInput"><i class="fas fa-search"></i></label>
                <input type="search" id="searchInput" placeholder="Search ID, cashier or GCash ref" autocomplete="off" aria-label="Search transactions">
            </div>
            <div class="filter-group">
                <label for="dateFrom" class="filter-label-text">From</label>
                <input type="date" id="dateFrom">
            </div>
            <div class="filter-group">
                <label for="dateTo" class="filter-label-text">To</label>
                <input type="date" id="dateTo">
            </div>
            <div class="filter-group">
                <label><i class="fas fa-filter"></i></label>
                <select id="statusFilter" aria-label="Status">
                    <option value="all">All Status</option>
                    <option value="completed">Completed</option>
                    <option value="voided">Voided</option>
                </select>
            </div>
            <div class="filter-actions">
                <button type="button" class="btn-filter" onclick="applyFilters()" title="Apply the date and status filters"><i class="fas fa-filter me-1"></i>Apply</button>
                <button type="button" class="btn-reset"  onclick="resetFilters()"><i class="fas fa-undo me-1"></i>Reset</button>
            </div>
        </div>

        <!-- Table -->
        <div class="transactions-section">
            <div class="section-header">
                <h5><i class="fas fa-list me-2" style="color:#4a6fa5;"></i>Transaction History</h5>
                <div>
                    <button class="btn-export me-2" onclick="exportTransactions()">
                        <i class="fas fa-download me-1"></i>Export
                    </button>
                    <button class="btn btn-outline-secondary btn-sm" onclick="refreshTransactions()">
                        <i class="fas fa-sync-alt me-1"></i>Refresh
                    </button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="transactions-table">
                    <thead>
                        <tr>
                            <th>Transaction ID</th>
                            <th>Date & Time</th>
                            <th>Items</th>
                            <th>Total Amount</th>
                            <th>Payment Method</th>
                            <th>Amount Paid</th>
                            <th>Change</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="transactionsTableBody">
                        <tr>
                            <td colspan="9" class="text-center py-4">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="pagination-wrapper">
                <span id="pageInfo" class="text-muted" style="font-size:13px;"></span>
                <ul class="pagination" id="pagination"></ul>
            </div>
        </div>
    </div>

    <!-- ═══════════════ TRANSACTION DETAIL MODAL ═══════════════ -->
    <div class="modal fade tx-modal" id="transactionModal" tabindex="-1" aria-hidden="true" aria-labelledby="txModalTitle">
        <!-- Scrollable: header and actions stay visible however long the order is -->
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="tx-head">
                    <div class="tx-head-text">
                        <h2 class="tx-title" id="txModalTitle">Transaction</h2>
                        <p class="tx-sub" id="txModalSub"></p>
                    </div>
                    <span class="tx-status" id="txModalStatus"></span>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="transactionDetails"></div>
                </div>
                <div class="modal-footer tx-foot">
                    <button type="button" class="tx-btn tx-btn-danger" id="voidBtn"
                            onclick="voidTransaction()" style="display:none;">
                        <i class="fas fa-ban" aria-hidden="true"></i>Void
                    </button>
                    <span class="tx-spacer"></span>
                    <button type="button" class="tx-btn tx-btn-ghost" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="tx-btn tx-btn-primary" onclick="printTransaction()">
                        <i class="fas fa-print" aria-hidden="true"></i>Print receipt
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════ VOID CONFIRMATION MODAL ═══════════════ -->
    <div class="modal fade" id="voidConfirmModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content border-0 shadow">
                <div class="modal-header border-0 pb-0"
                     style="background:linear-gradient(135deg,#7f1d1d,#dc2626);color:white;border-radius:8px 8px 0 0;">
                    <h6 class="modal-title fw-bold">
                        <i class="fas fa-exclamation-triangle me-2"></i>Void Transaction
                    </h6>
                    <button type="button" class="btn-close btn-close-white btn-sm"
                            data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body text-center py-4">
                    <!-- Warning icon -->
                    <div class="mb-3">
                        <div style="width:60px;height:60px;background:#fee2e2;border-radius:50%;
                                    display:flex;align-items:center;justify-content:center;margin:0 auto;">
                            <i class="fas fa-ban fa-2x" style="color:#dc2626;"></i>
                        </div>
                    </div>

                    <h6 class="fw-bold mb-1">Are you sure?</h6>
                    <p class="text-muted small mb-3">
                        You are about to void transaction<br>
                        <strong id="voidTxNumber" class="text-danger"></strong>
                    </p>

                    <!-- What happens note -->
                    <div class="alert alert-warning py-2 px-3 text-start mb-0" style="font-size:12px;">
                        <i class="fas fa-info-circle me-1"></i>
                        <strong>This will:</strong>
                        <ul class="mb-0 mt-1 ps-3">
                            <li>Mark the transaction as <strong>Voided</strong></li>
                            <li>Restore stock for all items in this transaction</li>
                        </ul>
                        <div class="mt-1 text-danger fw-bold">
                            <i class="fas fa-lock me-1"></i>This action cannot be undone.
                        </div>
                    </div>
                </div>

                <div class="modal-footer border-0 pt-0 justify-content-center gap-2">
                    <button type="button" class="btn btn-secondary btn-sm px-4"
                            data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger btn-sm px-4"
                            id="confirmVoidBtn">
                        <i class="fas fa-ban me-1"></i>Yes, Void
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php include 'includes/user_roles_modal.php'; ?>

    <!-- Scripts: jQuery MUST come before Bootstrap and salestrans.js -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Defined before salestrans.js so it can read them immediately
        const isOwner = <?php echo $is_owner ? 'true' : 'false'; ?>;
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
    </script>
    <!-- Navigation JS -->
    <script src="assets/js/navigation.js"></script>
    <!-- salestrans.js loads AFTER jQuery — its own DOMContentLoaded handles init -->
    <script src="assets/js/receipt.js?v=<?= filemtime(__DIR__ . '/assets/js/receipt.js') ?>"></script>
    <script src="assets/js/salestrans.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/salestrans.js') ?: time(); ?>"></script>
    <script src="assets/js/userManagement.js?v=<?= filemtime(__DIR__ . '/assets/js/userManagement.js') ?>"></script>
    <script>
        const currentUser = { id: '<?php echo $user_id; ?>' };
        <?php include 'includes/notifications.php'; ?>
    </script>
    <script src="assets/js/notif.js?v=<?= filemtime(__DIR__ . '/assets/js/notif.js') ?>"></script>
    <script src="assets/js/sidebar-nav.js"></script>
    <script>
        // Navigation auto-initializes but ensure it's ready
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof SmoothNavigator !== 'undefined') {
                new SmoothNavigator();
            }
        });
    </script>
</body>
</html>
