<?php
/**
 * Shared sidebar for every signed-in page.
 *
 * Include it where the old sidebar markup was:
 *     <?php include __DIR__ . '/includes/sidebar.php'; ?>
 *
 * Optional, set before including:
 *     $sidebar_account_modals = true;   // page renders #userProfileModal and #switchAccountModal
 *
 * IDs kept for existing page scripts: #sidebar, #collapseBtn, #collapseIcon,
 * #recommendationBtn, #logoutBtn. Styles live in assets/css/sidebar.css.
 */

// The session is the source of truth; some pages reuse $role/$first_name in loops
$sb_role     = $_SESSION['role'] ?? ($role ?? 'employee');
$sb_is_owner = in_array($sb_role, ['owner', 'admin'], true);
$sb_first    = $_SESSION['first_name'] ?? ($first_name ?? 'User');
$sb_last     = $_SESSION['last_name'] ?? ($last_name ?? '');
$sb_position = ($_SESSION['position'] ?? ($position ?? '')) ?: ucfirst($sb_role);
$sb_initials = strtoupper(substr($sb_first, 0, 1) . substr($sb_last, 0, 1));
$sb_page     = strtolower(basename($_SERVER['SCRIPT_NAME'] ?? ''));
$sb_home     = $sb_is_owner ? 'dashboard.php' : 'employee_dashboard.php';
$sb_account  = !empty($sidebar_account_modals);

require_once __DIR__ . '/avatar.php';
$sb_avatar   = current_user_avatar();

// [group label, [[href, icon, label, owner-only, extra attributes], ...]]
$sb_groups = [
    [null, [
        [$sb_home, 'fa-tachometer-alt', 'Dashboard', false, ''],
    ]],
    ['Sales', [
        ['PosUI_db.php', 'fa-cash-register', 'Point of Sale', false, ''],
        ['Sales_Transactions.php', 'fa-receipt', 'Sales transactions', true, ''],
    ]],
    ['Inventory', [
        ['categories.php', 'fa-layer-group', 'Inventory', false, ''],
        ['archive_products.php', 'fa-box-archive', 'Archive', false, ''],
        ['inventory_management.php', 'fa-history', 'Log history', false, ''],
    ]],
    ['Insights', [
        ['reco.php', 'fa-lightbulb', 'Recommendations', true, 'id="recommendationBtn"'],
    ]],
    ['Admin', [
        ['#roleManagementModal', 'fa-user-shield', 'User roles', true, 'data-bs-toggle="modal" data-bs-target="#roleManagementModal"'],
        ['admin_data_tools.php', 'fa-database', 'Backup & export', true, ''],
    ]],
];
?>
<aside class="sidebar sb" id="sidebar" aria-label="Main navigation">
    <div class="sb-brand">
        <a class="sb-brand-link" href="<?php echo $sb_home; ?>" title="Espenida's Pet &amp; Poultry Supply">
            <img src="assets/images/logo-mark.png" alt="" class="sb-logo">
            <span class="sb-brand-text">
                <span class="sb-brand-name">Espenida's</span>
                <span class="sb-brand-sub">Pet &amp; Poultry Supply</span>
            </span>
        </a>
        <button class="collapse-btn sb-collapse" id="collapseBtn" type="button" title="Collapse sidebar" aria-label="Collapse sidebar">
            <i class="fas fa-chevron-left" id="collapseIcon"></i>
        </button>
        <!-- Phones only: close the drawer (assets/js/responsive.js) -->
        <button class="sb-close" type="button" title="Close menu" aria-label="Close menu">
            <i class="fas fa-times" aria-hidden="true"></i>
        </button>
    </div>

    <nav class="sb-nav">
        <?php foreach ($sb_groups as [$sb_label, $sb_items]):
            $sb_items = array_filter($sb_items, fn($i) => !$i[3] || $sb_is_owner);
            if (!$sb_items) continue; ?>
            <div class="sb-group">
                <?php if ($sb_label): ?><div class="sb-group-label"><?php echo $sb_label; ?></div><?php endif; ?>
                <?php foreach ($sb_items as [$sb_href, $sb_icon, $sb_text, , $sb_attr]):
                    $sb_active = strtolower($sb_href) === $sb_page; ?>
                    <a class="sb-link<?php echo $sb_active ? ' active' : ''; ?>" href="<?php echo htmlspecialchars($sb_href); ?>"
                       title="<?php echo htmlspecialchars($sb_text); ?>" <?php echo $sb_attr; ?><?php echo $sb_active ? ' aria-current="page"' : ''; ?>>
                        <i class="fas <?php echo $sb_icon; ?>" aria-hidden="true"></i><span><?php echo htmlspecialchars($sb_text); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </nav>

    <div class="sb-footer">
        <div class="sb-user" title="<?php echo htmlspecialchars($sb_first . ' ' . $sb_last); ?>">
            <span class="sb-avatar" data-user-avatar>
                <?php if ($sb_avatar): ?><img src="<?php echo htmlspecialchars($sb_avatar); ?>" alt=""><?php else: ?><?php echo htmlspecialchars($sb_initials ?: 'U'); ?><?php endif; ?>
            </span>
            <span class="sb-user-text">
                <span class="sb-user-name"><?php echo htmlspecialchars(trim($sb_first . ' ' . $sb_last)); ?></span>
                <span class="sb-user-role"><?php echo htmlspecialchars($sb_position); ?></span>
            </span>
        </div>
        <?php if ($sb_account): ?>
            <button class="sb-link profile-btn" type="button" data-bs-toggle="modal" data-bs-target="#userProfileModal" title="My profile">
                <i class="fas fa-user-circle" aria-hidden="true"></i><span>My profile</span>
            </button>
            <button class="sb-link switch-account-btn" type="button" data-bs-toggle="modal" data-bs-target="#switchAccountModal" title="Switch account">
                <i class="fas fa-exchange-alt" aria-hidden="true"></i><span>Switch account</span>
            </button>
        <?php endif; ?>
        <a class="sb-link logout-btn" id="logoutBtn" href="logout.php" title="Log out">
            <i class="fas fa-sign-out-alt" aria-hidden="true"></i><span>Log out</span>
        </a>
    </div>
</aside>
