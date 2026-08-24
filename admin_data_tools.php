<?php
require_once __DIR__ . '/includes/security.php';
security_start_session();

if (empty($_SESSION['user_id'])) {
    header('Location: Login.php');
    exit;
}
if (($_SESSION['role'] ?? '') !== 'owner') {
    $_SESSION['toast_message'] = [
        'type' => 'warning',
        'title' => 'Access Denied',
        'message' => 'Backup and export tools are restricted to owners.'
    ];
    header('Location: employee_dashboard.php');
    exit;
}

$first_name = $_SESSION['first_name'] ?? 'Owner';
$last_name = $_SESSION['last_name'] ?? '';
$position = $_SESSION['position'] ?? 'Store Owner';
$csrf = security_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
    <title>Backup &amp; Export · Espenida's Pet &amp; Poultry Supply</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="assets/css/dashboardCSS.css">
    <link rel="stylesheet" href="assets/css/reco.css">
    <link rel="stylesheet" href="assets/css/usermod.css">
    <link rel="stylesheet" href="assets/css/responsive.css">
    <link rel="stylesheet" href="assets/css/admin-data-tools.css?v=20260814-2">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/security.js?v=20260814-1" defer></script>
</head>
<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="sidebar" id="sidebar">
        <div class="store-brand">
            <div class="logo-container">
                <img src="assets/images/sidebar.jpg" alt="Espenida's Logo" class="store-logo-img">
            </div>
            <div class="store-name">
                <span class="store-name-main">Espenida's</span>
                <span class="store-name-sub">PET &amp; POULTRY SUPPLY</span>
            </div>
        </div>

        <button class="collapse-btn" id="collapseBtn" type="button">
            <i class="fas fa-chevron-left" id="collapseIcon"></i><span>Espenida Store</span>
        </button>

        <div class="section-title"><span>DASHBOARD</span></div>
        <a class="profile-btn text-decoration-none" href="dashboard.php"><i class="fas fa-gauge-high"></i><span>Dashboard</span></a>

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
                <li><a class="dropdown-item" href="categories.php"><i class="fas fa-layer-group"></i> Inventory</a></li>
                <li><a class="dropdown-item" href="archive_products.php"><i class="fas fa-box-archive"></i> Archive</a></li>
                <li><a class="dropdown-item" href="inventory_management.php"><i class="fas fa-clock-rotate-left"></i> Log History</a></li>
            </ul>
        </div>

        <div class="section-title"><span>RECOMMENDATIONS</span></div>
        <a class="recommendation-btn" href="reco.php"><i class="fas fa-lightbulb"></i><span>Recommendations</span></a>

        <div class="section-title"><span>SYSTEM &amp; ADMIN</span></div>
        <div class="dropdown">
            <button class="dropdown-toggle active" type="button" data-bs-toggle="dropdown">
                <i class="fas fa-user-shield"></i><span>System &amp; Admin</span>
            </button>
            <ul class="dropdown-menu">
                <li><a class="dropdown-item" href="#roleManagementModal" data-bs-toggle="modal" data-bs-target="#roleManagementModal"><i class="fas fa-lock"></i> User Roles</a></li>
                <li><a class="dropdown-item active" href="admin_data_tools.php"><i class="fas fa-database"></i> Backup &amp; Export</a></li>
            </ul>
        </div>

        <div class="spacer"></div>
        <a class="logout-btn text-decoration-none" href="logout.php"><i class="fas fa-sign-out-alt"></i><span>Log out</span></a>
    </div>

    <main class="main-content">
        <section class="data-tools-header">
            <h1><i class="fas fa-database me-2"></i>Backup &amp; Export</h1>
            <p>Download secure copies of business data for recovery, reporting, and offline analysis.</p>
        </section>

        <div class="data-tools-grid">
            <section class="data-tool-card backup-card">
                <h2>Database Backup</h2>
                <p>Creates a complete SQL backup containing the database structure and current records.</p>
                <div class="tool-option">
                    <div><strong>Full SQL backup</strong><small>Use this file to restore the complete POS database.</small></div>
                    <?= download_form('database_backup', 'Download Full-Backup', 'fa-download', $csrf) ?>
                </div>
                <div class="security-note"><i class="fas fa-shield-halved me-2"></i>The backup is streamed directly to you and is not stored in the public website folder.</div>
            </section>

            <section class="data-tool-card export-card">
                <h2>CSV Exports</h2>
                <p>CSV files open in Microsoft Excel and other spreadsheet applications.</p>
                <div class="tool-option"><div><strong>Sales transactions</strong><small>Payments, totals, cashiers, statuses, and quantities.</small></div><?= download_form('sales_csv', 'Export Sales', 'fa-file-csv', $csrf) ?></div>
                <div class="tool-option"><div><strong>Current inventory</strong><small>Stock, prices, categories, values, and expiration dates.</small></div><?= download_form('inventory_csv', 'Export Stock', 'fa-boxes-stacked', $csrf) ?></div>
                <div class="tool-option"><div><strong>User accounts</strong><small>Roles and account status. PIN hashes are never exported.</small></div><?= download_form('users_csv', 'Export Users', 'fa-users', $csrf) ?></div>
                <div class="tool-option"><div><strong>Inventory activity</strong><small>Product changes and the users who performed them.</small></div><?= download_form('activity_csv', 'Export Activity', 'fa-clock-rotate-left', $csrf) ?></div>
            </section>
        </div>
    </main>

    <?php include __DIR__ . '/includes/user_roles_modal.php'; ?>
    <script src="assets/js/userManagement.js?v=20260814-1"></script>
    <script src="assets/js/responsive.js"></script>
    <script src="assets/js/sidebar-nav.js"></script>
    <script>
        document.getElementById('collapseBtn')?.addEventListener('click', function () {
            document.getElementById('sidebar')?.classList.toggle('collapsed');
            document.getElementById('collapseIcon')?.classList.toggle('fa-chevron-right');
        });
    </script>
</body>
</html>
<?php
function download_form(string $type, string $label, string $icon, string $csrf): string
{
    return '<form method="post" action="admin_data_download.php">'
        . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') . '">'
        . '<input type="hidden" name="type" value="' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '">'
        . '<button type="submit" class="btn-data-download"><i class="fas ' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . ' me-1"></i>'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</button></form>';
}
