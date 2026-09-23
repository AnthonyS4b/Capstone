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

// What each download contains, so the owner knows before downloading
$counts = ['sales' => null, 'inventory' => null, 'users' => null, 'activity' => null];
$db = ['tables' => null, 'bytes' => null, 'latest_sale' => null];
try {
    require_once __DIR__ . '/config/database.php';
    $pdo = getDBConnection();
    $counts['sales']     = (int)$pdo->query('SELECT COUNT(*) FROM transactions')->fetchColumn();
    $counts['inventory'] = (int)$pdo->query('SELECT COUNT(*) FROM products WHERE deleted_at IS NULL')->fetchColumn();
    $counts['users']     = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $counts['activity']  = (int)$pdo->query('SELECT COUNT(*) FROM inventory_history')->fetchColumn();
    $db['latest_sale']   = $pdo->query('SELECT MAX(created_at) FROM transactions')->fetchColumn() ?: null;

    // SHOW TABLE STATUS is fast; information_schema is very slow on this server
    $status = $pdo->query('SHOW TABLE STATUS')->fetchAll(PDO::FETCH_ASSOC);
    $db['tables'] = count($status);
    $db['bytes']  = array_sum(array_map(fn($t) => (int)$t['Data_length'] + (int)$t['Index_length'], $status));
} catch (Throwable $e) {
    error_log('admin_data_tools counts: ' . $e->getMessage());
}

function human_bytes(?int $bytes): string
{
    if ($bytes === null) return '-';
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $n = (float)$bytes;
    while ($n >= 1024 && $i < count($units) - 1) { $n /= 1024; $i++; }
    return ($i === 0 ? (string)$n : number_format($n, 1)) . ' ' . $units[$i];
}

function rows_label(?int $n): string
{
    return $n === null ? '' : number_format($n) . ' row' . ($n === 1 ? '' : 's');
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
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
    <title>Backup &amp; Export · Espenida's Pet &amp; Poultry Supply</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="assets/css/dashboardCSS.css">
    <link rel="stylesheet" href="assets/css/reco.css">
    <link rel="stylesheet" href="assets/css/usermod.css">
    <link rel="stylesheet" href="assets/css/responsive.css">
    <link rel="stylesheet" href="assets/css/admin-data-tools.css?v=<?= filemtime(__DIR__ . '/assets/css/admin-data-tools.css') ?>">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/security.js?v=20260814-1" defer></script>
    <link rel="stylesheet" href="assets/css/sidebar.css?v=<?= filemtime(__DIR__ . '/assets/css/sidebar.css') ?>">
</head>
<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content dt-page">
        <header class="dt-header">
            <h1>Backup &amp; export</h1>
            <p>Keep a copy of your data, or open it in Excel.</p>
        </header>

        <!-- Full backup -->
        <section class="dt-card dt-backup">
            <div class="dt-backup-main">
                <span class="dt-icon dt-icon-brand" aria-hidden="true"><i class="fas fa-database"></i></span>
                <div class="dt-backup-text">
                    <h2>Full database backup</h2>
                    <p>Everything in the system products, sales, users, settings in one <code>.sql</code> file you can restore from.</p>
                    <ul class="dt-facts">
                        <li><strong><?= $db['tables'] ?? '-' ?></strong> tables</li>
                        <li>about <strong><?= htmlspecialchars(human_bytes($db['bytes'])) ?></strong></li>
                        <?php if ($db['latest_sale']): ?>
                            <li>latest sale <strong><?= htmlspecialchars(date('M j, Y g:i A', strtotime($db['latest_sale']))) ?></strong></li>
                        <?php endif; ?>
                    </ul>
                </div>
                <?= download_form('database_backup', 'Download backup', 'fa-download', $csrf, 'dt-btn dt-btn-primary') ?>
            </div>
            <div class="dt-notes">
                <p><i class="fas fa-lock" aria-hidden="true"></i>The file goes straight to your computer; no copy is left on the website. It contains customer and staff data, so keep it somewhere private.</p>
                <details>
                    <summary>How to restore a backup</summary>
                    <ol>
                        <li>Open <strong>phpMyAdmin</strong> and select the <code>espenida_pos</code> database.</li>
                        <li>Go to <strong>Import</strong>, choose the downloaded <code>.sql</code> file and click <strong>Import</strong>.</li>
                        <li>Restoring replaces every table with the contents of the backup.</li>
                    </ol>
                </details>
            </div>
        </section>

        <!-- CSV exports -->
        <section class="dt-card">
            <div class="dt-card-head">
                <h2>Spreadsheet exports</h2>
                <p>CSV files that open in Excel or Google Sheets.</p>
            </div>
            <ul class="dt-exports">
                <li>
                    <span class="dt-icon" aria-hidden="true"><i class="fas fa-receipt"></i></span>
                    <div class="dt-export-text">
                        <strong>Sales transactions</strong>
                        <span>Date, cashier, payment method, totals, change and units sold per sale.</span>
                    </div>
                    <span class="dt-count"><?= rows_label($counts['sales']) ?></span>
                    <?= download_form('sales_csv', 'Download', 'fa-file-csv', $csrf) ?>
                </li>
                <li>
                    <span class="dt-icon" aria-hidden="true"><i class="fas fa-boxes"></i></span>
                    <div class="dt-export-text">
                        <strong>Current inventory</strong>
                        <span>Stock, cost and selling price, stock value and expiry for every active product.</span>
                    </div>
                    <span class="dt-count"><?= rows_label($counts['inventory']) ?></span>
                    <?= download_form('inventory_csv', 'Download', 'fa-file-csv', $csrf) ?>
                </li>
                <li>
                    <span class="dt-icon" aria-hidden="true"><i class="fas fa-users"></i></span>
                    <div class="dt-export-text">
                        <strong>User accounts</strong>
                        <span>Names, roles, positions and status. PINs are never exported.</span>
                    </div>
                    <span class="dt-count"><?= rows_label($counts['users']) ?></span>
                    <?= download_form('users_csv', 'Download', 'fa-file-csv', $csrf) ?>
                </li>
                <li>
                    <span class="dt-icon" aria-hidden="true"><i class="fas fa-history"></i></span>
                    <div class="dt-export-text">
                        <strong>Inventory activity</strong>
                        <span>Every product change and who made it, newest first.</span>
                    </div>
                    <span class="dt-count"><?= rows_label($counts['activity']) ?></span>
                    <?= download_form('activity_csv', 'Download', 'fa-file-csv', $csrf) ?>
                </li>
            </ul>
        </section>
    </main>

    <?php include __DIR__ . '/includes/user_roles_modal.php'; ?>
    <script src="assets/js/userManagement.js?v=<?= filemtime(__DIR__ . '/assets/js/userManagement.js') ?>"></script>
    <script src="assets/js/responsive.js?v=<?= filemtime(__DIR__ . '/assets/js/responsive.js') ?>"></script>
    <script src="assets/js/sidebar-nav.js"></script>
    <script>
        document.querySelectorAll('form.dt-download').forEach(function (form) {
            form.addEventListener('submit', function () {
                const btn = form.querySelector('button');
                const label = btn.querySelector('span');
                const original = label.textContent;
                setTimeout(function () { btn.disabled = true; label.textContent = 'Preparing...'; }, 0);
                setTimeout(function () { btn.disabled = false; label.textContent = original; }, 4000);
            });
        });

        document.getElementById('collapseBtn')?.addEventListener('click', function () {
            document.getElementById('sidebar')?.classList.toggle('collapsed');
            document.getElementById('collapseIcon')?.classList.toggle('fa-chevron-right');
        });
    </script>
</body>
</html>
<?php
function download_form(string $type, string $label, string $icon, string $csrf, string $class = 'dt-btn'): string
{
    return '<form method="post" action="admin_data_download.php" class="dt-download">'
        . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') . '">'
        . '<input type="hidden" name="type" value="' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '">'
        . '<button type="submit" class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"><i class="fas ' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '" aria-hidden="true"></i>'
        . '<span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span></button></form>';
}
