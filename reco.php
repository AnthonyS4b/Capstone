<?php
// Recommendations Page
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

// Employees are not allowed to access recommendations
if (!$is_owner) {
    $_SESSION['toast_message'] = [
        'type'    => 'warning',
        'title'   => 'Access Denied!',
        'message' => 'Recommendations are restricted to owners only.'
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
    <title>Recommendations</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="assets/css/dashboardCSS.css">
    <link rel="stylesheet" href="assets/css/PosCSS.css">
    <link rel="stylesheet" href="assets/css/reco.css?v=20260906-2">
    <link rel="stylesheet" href="assets/css/reco-page.css?v=<?= filemtime(__DIR__ . '/assets/css/reco-page.css') ?>">
    <link href="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="assets/css/responsive.css">
    <script src="assets/js/responsive.js?v=<?= filemtime(__DIR__ . '/assets/js/responsive.js') ?>"></script>
    <link rel="stylesheet" href="assets/css/sidebar.css?v=<?= filemtime(__DIR__ . '/assets/css/sidebar.css') ?>">
</head>
<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="fas fa-bars"></i>
    </button>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <div class="toast-container" id="toastContainer"></div>

    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <!-- ═══════════════ MAIN CONTENT ═══════════════ -->
    <div class="main-content reco-page">
        <header class="rp-header">
            <div class="rp-header-text">
                <h1>Recommendations</h1>
                <p>
                    Products that need attention, ranked by risk.
                    <span class="rp-engine">
                        <span class="status-dot connected" aria-hidden="true"></span>
                        Engine <span class="api-status">Connected</span>
                    </span>
                </p>
            </div>
            <?php if ($is_owner): ?>
            <div class="rp-header-actions">
                <button type="button" class="rp-btn rp-btn-ghost" onclick="openActiveStrategiesModal()">
                    <i class="fas fa-tags" aria-hidden="true"></i> Active promos
                </button>
                <button type="button" class="rp-btn rp-btn-light" id="trainModelBtn">
                    <i class="fas fa-sync-alt me-2" aria-hidden="true"></i>Update Analysis
                </button>
            </div>
            <?php endif; ?>
        </header>

        <!-- Summary figures -->
        <section class="rp-kpis" aria-label="Summary">
            <div class="rp-kpi">
                <span class="rp-kpi-label">Slow-moving items</span>
                <span class="rp-kpi-value" id="slowMovingCount">0</span>
                <span class="rp-kpi-note" id="slowMovingNote">&nbsp;</span>
            </div>
            <div class="rp-kpi">
                <span class="rp-kpi-label">Projected monthly revenue</span>
                <span class="rp-kpi-value" id="potentialRevenue">₱0</span>
                <span class="rp-kpi-note">Forecast sales × current price</span>
            </div>
            <div class="rp-kpi">
                <span class="rp-kpi-label">Average time in stock</span>
                <span class="rp-kpi-value" id="avgShelfLife">0 days</span>
                <span class="rp-kpi-note">Across flagged products</span>
            </div>
            <div class="rp-kpi">
                <span class="rp-kpi-label">Model confidence</span>
                <span class="rp-kpi-value" id="confidenceScore">—</span>
                <span class="rp-kpi-note">Average for these forecasts</span>
            </div>
        </section>

        <!-- Toolbar -->
        <div class="rp-toolbar">
            <div class="rp-tabs" role="tablist" aria-label="Filter by risk">
                <button class="filter-btn active" data-filter="all">All <span class="rp-count" data-count="all"></span></button>
                <button class="filter-btn" data-filter="critical">Critical <span class="rp-count" data-count="critical"></span></button>
                <button class="filter-btn" data-filter="warning">Warning <span class="rp-count" data-count="warning"></span></button>
                <button class="filter-btn" data-filter="monitor">Monitor <span class="rp-count" data-count="monitor"></span></button>
                <button class="filter-btn" data-filter="slow">Slow moving <span class="rp-count" data-count="slow"></span></button>
            </div>
            <div class="rp-toolbar-right">
                <label class="rp-search">
                    <i class="fas fa-search" aria-hidden="true"></i>
                    <input type="search" id="searchBox" placeholder="Search product or category" aria-label="Search products">
                </label>
                <div class="rp-view" role="group" aria-label="Layout">
                    <button type="button" class="view-toggle-btn active" data-view="list" aria-pressed="true" title="List">
                        <i class="fas fa-list" aria-hidden="true"></i><span class="visually-hidden">List</span>
                    </button>
                    <button type="button" class="view-toggle-btn" data-view="card" aria-pressed="false" title="Cards">
                        <i class="fas fa-th-large" aria-hidden="true"></i><span class="visually-hidden">Cards</span>
                    </button>
                </div>
            </div>
        </div>

        <section class="rp-panel">
            <div class="rp-panel-meta">
                <span id="recommendationResultCount" aria-live="polite">Loading…</span>
            </div>

            <div id="recommendationsListHeader" class="rp-row rp-head" hidden aria-hidden="true">
                <span>Product</span>
                <span>Risk</span>
                <span class="rp-num">Price</span>
                <span class="rp-num">Stock</span>
                <span class="rp-num">Days in stock</span>
                <span class="rp-num">Sales</span>
                <span>Recommendation</span>
                <span></span>
            </div>

            <!-- List view is the default -->
            <div id="recommendationsContainer" class="rp-list list-view">
                <div class="rp-empty">
                    <div class="loading-spinner"></div>
                    <p>Analysing inventory…</p>
                </div>
            </div>
        </section>
    </div>

    <!-- Forecast Modal -->
    <div class="modal fade rp-modal" id="forecastModal" tabindex="-1" aria-labelledby="forecastModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="rp-modal-head">
                    <h2 class="rp-modal-kicker" id="forecastModalTitle">Recommendation details</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="forecastContent">Loading...</div>
                </div>
                <div class="rp-modal-foot" id="forecastModalFooter">
                    <button type="button" class="rp-btn rp-btn-ghost-dark btn-modal-close" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="rp-btn rp-btn-primary btn-modal-apply">Apply strategy</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Apply Strategy Modal -->
    <div class="modal fade rp-modal" id="applyModal" tabindex="-1" aria-labelledby="applyModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="rp-modal-head">
                    <h2 class="rp-modal-title" id="applyModalTitle">Apply strategy</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="applyContent">Loading...</div>
                </div>
                <div class="rp-modal-foot">
                    <button type="button" class="rp-btn rp-btn-ghost-dark" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="rp-btn rp-btn-primary" id="confirmApplyBtn">Apply strategy</button>
                </div>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/includes/active_promos_modal.php'; ?>

    <?php include 'includes/user_roles_modal.php'; ?>

    <!-- filemtime busts the cache whenever this file actually changes, so a
         fix cannot sit invisible behind a stale copy in the browser. -->
    <script src="assets/js/Reco.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/Reco.js') ?: time(); ?>"></script>
    <script src="assets/js/active_strategies.js?v=<?= filemtime(__DIR__ . '/assets/js/active_strategies.js') ?>"></script>
    <script src="assets/js/userManagement.js?v=<?= filemtime(__DIR__ . '/assets/js/userManagement.js') ?>"></script>
    <script src="assets/js/sidebar-nav.js"></script>
</body>
</html>
