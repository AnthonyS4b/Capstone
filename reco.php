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
    <link href="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="assets/css/responsive.css">
    <script src="assets/js/responsive.js"></script>
</head>
<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="fas fa-bars"></i>
    </button>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <div class="toast-container" id="toastContainer"></div>

    <!-- ═══════════════ SIDEBAR ═══════════════ -->
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
            <button class="dropdown-toggle" type="button" data-bs-toggle="dropdown">
                <i class="fas fa-cash-register"></i><span>Point of Sale</span>
            </button>
            <ul class="dropdown-menu">
                <li><a class="dropdown-item" href="PosUI_db.php"><i class="fas fa-cash-register"></i> Point of Sale</a></li>
                <li><a class="dropdown-item" href="Sales_Transactions.php"><i class="fas fa-history"></i> Sales Transactions</a></li>
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

        <div class="section-title"><span>RECOMMENDATIONS</span></div>
        <a href="reco.php" class="recommendation-btn active">
            <i class="fas fa-lightbulb"></i><span>Recommendations</span>
        </a>

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

        <div class="spacer"></div>
        <button class="logout-btn" id="logoutBtn">
            <i class="fas fa-sign-out-alt"></i><span>Log out</span>
        </button>
    </div>

    <!-- ═══════════════ MAIN CONTENT ═══════════════ -->
    <div class="main-content">
        <div class="welcome-banner">
            <div class="welcome-text">
                <h1><i class="fas fa-lightbulb me-2"></i>Recommendations</h1>
                <p>Advanced inventory analysis & sales forecasting</p>
            </div>
            <div class="banner-actions">
                <div class="status-indicator">
                    <div class="status-dot connected"></div>
                    <span class="status-label">Service: <span class="api-status">Connected</span> <i class="fas fa-check-circle ms-1"></i></span>
                </div>
                <?php if ($is_owner): ?>
                <button class="btn" onclick="openActiveStrategiesModal()" style="background: linear-gradient(135deg, #2c5530, #8B4513); color: white; font-weight: 600; border: none; box-shadow: 0 4px 6px -1px rgba(44, 85, 48, 0.2); margin-right: 10px;">
                    <i class="fas fa-tags me-1"></i> Active Promos
                </button>
                <button class="btn btn-update-analysis" id="trainModelBtn" title="Update Analysis">
                    <i class="fas fa-sync-alt me-2"></i>Update Analysis
                </button>
                <?php endif; ?>
                <div class="date-display">
                    <i class="fas fa-calendar-alt me-2"></i><?php echo date('M d, Y'); ?>
                </div>
            </div>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon orange">!</div>
                    <div>
                        <div class="stat-value" id="slowMovingCount">0</div>
                        <div class="stat-label">Slow-Moving Items</div>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon green">₱</div>
                    <div>
                        <div class="stat-value" id="potentialRevenue">₱0</div>
                        <div class="stat-label">Potential Revenue</div>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon blue">⏱</div>
                    <div>
                        <div class="stat-value" id="avgShelfLife">0 days</div>
                        <div class="stat-label">Avg. Shelf Time</div>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon blue">%</div>
                    <div>
                        <div class="stat-value" id="confidenceScore">80%</div>
                        <div class="stat-label">Accuracy Score</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <div class="filter-group">
                <button class="filter-btn active" data-filter="all">All Items</button>
                <button class="filter-btn" data-filter="critical">Critical</button>
                <button class="filter-btn" data-filter="slow">Slow Moving</button>
                <button class="filter-btn" data-filter="monitor">Monitor</button>
                <button class="filter-btn" data-filter="warning">Warning</button>
            </div>
            <div class="filter-group filter-tools">
                <div class="view-toggle" role="group" aria-label="Recommendation view">
                    <button type="button" class="view-toggle-btn active" data-view="list" aria-pressed="true" title="List view">
                        <i class="fas fa-list" aria-hidden="true"></i>
                        <span>List</span>
                    </button>
                    <button type="button" class="view-toggle-btn" data-view="card" aria-pressed="false" title="Card view">
                        <i class="fas fa-th-large" aria-hidden="true"></i>
                        <span>Cards</span>
                    </button>
                </div>
                <input type="text" class="form-control" id="searchBox" placeholder="Search products..." style="width: 250px;">
            </div>
        </div>

        <div class="recommendations-summary">
            <span id="recommendationResultCount" class="result-count" aria-live="polite">Loading recommendations...</span>
            <span class="list-view-hint"><i class="fas fa-info-circle" aria-hidden="true"></i> Select View Details for the full forecast.</span>
        </div>

        <div id="recommendationsListHeader" class="recommendations-list-header" hidden aria-hidden="true">
            <span>Product</span>
            <span>Risk &amp; price</span>
            <span>Stock activity</span>
            <span>Recommended action</span>
            <span class="text-end">Actions</span>
        </div>

        <!-- Recommendations: list view is the default -->
        <div id="recommendationsContainer" class="recommendations-grid list-view">
            <div class="empty-state" style="grid-column: 1/-1;">
                <div class="loading-spinner"></div>
                <h3>Loading Recommendations...</h3>
                <p>The recommendation engine is analyzing your inventory</p>
            </div>
        </div>
    </div>

    <!-- Forecast Modal -->
    <div class="modal fade" id="forecastModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Prediction Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div id="forecastContent">Loading...</div>
                </div>
                <div class="modal-footer" id="forecastModalFooter">
                    <button type="button" class="btn btn-outline-secondary btn-modal-close" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-success btn-modal-apply">
                        Apply Strategy
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Apply Strategy Modal -->
    <div class="modal fade" id="applyModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Apply Strategy</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div id="applyContent">Loading...</div>
                </div>
                <div class="smart-insights">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" id="confirmApplyBtn">
                        Apply Strategy
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Active Strategies Modal -->
    <div class="modal fade" id="activeStrategiesModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #2c5530, #8B4513); color: white;">
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

    <script src="assets/js/Reco.js?v=20260906-4"></script>
    <script src="assets/js/active_strategies.js?v=20260905-2"></script>
    <script src="assets/js/userManagement.js?v=20260814-1"></script>
    <script src="assets/js/sidebar-nav.js"></script>
</body>
</html>
