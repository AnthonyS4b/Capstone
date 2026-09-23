<?php
/**
 * Shared "Switch account" modal (dashboard.php, employee_dashboard.php).
 * Two steps: pick an account → enter its PIN. Entering the PIN is the
 * confirmation, so there is no separate "are you sure" step.
 *
 * The form ids (#switchAccountForm, #selectedUserId, #accountPin,
 * #switchAccountError) are what the page scripts submit and report errors on.
 * Styles: assets/css/switch-account.css · Steps: assets/js/switch-account.js
 */
require_once __DIR__ . '/avatar.php';
require_once __DIR__ . '/../config/database.php';

$sw_uid = (int)($_SESSION['user_id'] ?? 0);
$sw_me = null;
$sw_accounts = [];
try {
    $sw_pdo = getDBConnection();
    $st = $sw_pdo->prepare('SELECT first_name, last_name, role, position, avatar FROM users WHERE id = ?');
    $st->execute([$sw_uid]);
    $sw_me = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    // Everyone else who can sign in, owners first
    $st = $sw_pdo->prepare("SELECT id, first_name, last_name, role, position, avatar
                            FROM users WHERE id != ? AND is_active = 1
                            ORDER BY CASE WHEN role = 'owner' THEN 0 ELSE 1 END, first_name, last_name");
    $st->execute([$sw_uid]);
    $sw_accounts = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('switch_account_modal: ' . $e->getMessage());
}
$sw_role_label = fn($r) => in_array($r, ['owner', 'admin'], true) ? 'Owner' : 'Employee';
?>
<link rel="stylesheet" href="assets/css/switch-account.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/switch-account.css'); ?>">
<script src="assets/js/switch-account.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/switch-account.js'); ?>" defer></script>

<div class="modal fade sw-modal" id="switchAccountModal" tabindex="-1" aria-labelledby="swTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="sw-head">
                <div>
                    <h2 class="sw-title" id="swTitle">Switch account</h2>
                    <p class="sw-sub">Hand over to someone else without logging out.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <?php if ($sw_me): ?>
                <div class="sw-current">
                    <?php echo user_avatar_html($sw_me['avatar'], $sw_me['first_name'], $sw_me['last_name'], 'sw-avatar sw-avatar-sm'); ?>
                    <span>Signed in as <strong><?php echo htmlspecialchars($sw_me['first_name'] . ' ' . $sw_me['last_name']); ?></strong></span>
                </div>
                <?php endif; ?>

                <!-- Step 1: pick an account -->
                <div id="accountList" class="sw-step">
                    <h3 class="sw-step-title">Switch to</h3>
                    <?php if ($sw_accounts): ?>
                        <div class="sw-list">
                            <?php foreach ($sw_accounts as $acc):
                                $acc_name = $acc['first_name'] . ' ' . $acc['last_name'];
                                $acc_role = $sw_role_label($acc['role']); ?>
                                <button type="button" class="sw-account"
                                        data-user-id="<?php echo (int)$acc['id']; ?>"
                                        data-user-name="<?php echo htmlspecialchars($acc_name); ?>">
                                    <?php echo user_avatar_html($acc['avatar'], $acc['first_name'], $acc['last_name'], 'sw-avatar sw-avatar-' . strtolower($acc_role)); ?>
                                    <span class="sw-account-text">
                                        <span class="sw-account-name"><?php echo htmlspecialchars($acc_name); ?></span>
                                        <span class="sw-account-meta">
                                            <span class="sw-role sw-role-<?php echo strtolower($acc_role); ?>"><?php echo $acc_role; ?></span>
                                            <?php echo htmlspecialchars($acc['position'] ?: ''); ?>
                                        </span>
                                    </span>
                                    <i class="fas fa-chevron-right sw-chevron" aria-hidden="true"></i>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="sw-empty">There are no other active accounts. An owner can add one in User roles.</p>
                    <?php endif; ?>
                </div>

                <!-- Kept for older page scripts; the two-step flow no longer shows it -->
                <div id="switchConfirmStep" hidden><p id="switchConfirmMsg"></p></div>

                <!-- Step 2: PIN -->
                <div id="quickLoginForm" class="sw-step" style="display:none;">
                    <div class="sw-chosen">
                        <span class="sw-chosen-avatar" id="swChosenAvatar"></span>
                        <div class="sw-chosen-text">
                            <span class="sw-chosen-label">Switching to</span>
                            <span class="sw-chosen-name" id="selectedAccountName"></span>
                        </div>
                        <button type="button" class="sw-link" onclick="cancelAccountSelection()">Change</button>
                    </div>

                    <form id="switchAccountForm" method="POST" action="switch_account.php" autocomplete="off" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(security_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="user_id" id="selectedUserId">
                        <input type="hidden" name="ajax" value="1">

                        <label class="sw-pin-label" for="accountPin">Their 4-digit PIN</label>
                        <input type="password" class="sw-pin" id="accountPin" name="pin" maxlength="4" pattern="\d{4}"
                               inputmode="numeric" autocomplete="off" required>
                        <div id="switchAccountError" class="sw-error" role="alert" aria-live="polite" style="display:none;"></div>

                        <button type="submit" class="sw-btn sw-btn-primary"><i class="fas fa-exchange-alt me-2"></i>Switch account</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
