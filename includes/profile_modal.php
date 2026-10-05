<?php
/**
 * Shared "My profile" modal (dashboard.php, employee_dashboard.php).
 * Styles: assets/css/profile.css · Photo upload: assets/js/profile.js
 * The Change PIN fields keep their ids for the page's changeOwnPin().
 *
 * Optional page variables: $daily_sales, $orders_today (shown to employees).
 */
require_once __DIR__ . '/avatar.php';
require_once __DIR__ . '/../config/database.php';

$pf_uid  = (int)($_SESSION['user_id'] ?? 0);
$pf_user = ['first_name' => '', 'last_name' => '', 'email' => '', 'role' => 'employee', 'position' => '', 'created_at' => null];
$pf_logins = [];
try {
    $pf_pdo = getDBConnection();
    $st = $pf_pdo->prepare('SELECT first_name, last_name, email, role, position, created_at FROM users WHERE id = ?');
    $st->execute([$pf_uid]);
    $pf_user = $st->fetch(PDO::FETCH_ASSOC) ?: $pf_user;

    // Two most recent sign-ins: this session, and the one before it
    $st = $pf_pdo->prepare("SELECT login_time FROM login_sessions
                            WHERE user_id = ? AND action IN ('login', 'switch')
                            ORDER BY login_time DESC LIMIT 2");
    $st->execute([$pf_uid]);
    $pf_logins = $st->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    error_log('profile_modal: ' . $e->getMessage());
}

$pf_name     = trim($pf_user['first_name'] . ' ' . $pf_user['last_name']);
$pf_role     = $pf_user['role'] ?: 'employee';
$pf_is_owner = in_array($pf_role, ['owner', 'admin'], true);
$pf_avatar   = current_user_avatar();
$pf_initials = strtoupper(substr($pf_user['first_name'], 0, 1) . substr($pf_user['last_name'], 0, 1)) ?: 'U';
$pf_fmt      = fn($d, $f) => $d ? date($f, strtotime($d)) : null;
$pf_since    = $pf_fmt($pf_user['created_at'], 'M j, Y');
$pf_session  = $pf_fmt($pf_logins[0] ?? null, 'M j, g:i A');
$pf_previous = $pf_fmt($pf_logins[1] ?? null, 'M j, Y · g:i A');

// Owner only: whether a recovery PIN exists for "Forgot PIN" at the login screen
$pf_has_recovery = false;
if ($pf_is_owner && isset($pf_pdo)) {
    try {
        require_once __DIR__ . '/pin_recovery.php';
        $pf_has_recovery = pin_recovery_is_set($pf_pdo, $pf_uid);
    } catch (Throwable $e) {
        error_log('profile_modal recovery: ' . $e->getMessage());
    }
}

$pf_access = $pf_is_owner
    ? ['Point of Sale & sales history', 'Inventory, archive & log history', 'Recommendations & promotions', 'User roles', 'Backup & export']
    : ['Point of Sale', 'Inventory & archive', 'Your own transactions'];
?>
<link rel="stylesheet" href="assets/css/profile.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/profile.css'); ?>">
<script src="assets/js/profile.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/profile.js'); ?>" defer></script>

<div class="modal fade pf-modal" id="userProfileModal" tabindex="-1" aria-labelledby="pfTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-body">
                <!-- Brand strip the photo overlaps (inside the scroll area so nothing is clipped) -->
                <div class="pf-cover">
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <!-- Identity -->
                <div class="pf-identity">
                    <div class="pf-photo-wrap">
                        <span class="pf-photo" id="pfPhoto" data-user-avatar data-initials="<?php echo htmlspecialchars($pf_initials); ?>">
                            <?php if ($pf_avatar): ?>
                                <img src="<?php echo htmlspecialchars($pf_avatar); ?>" alt="">
                            <?php else: ?>
                                <?php echo htmlspecialchars($pf_initials); ?>
                            <?php endif; ?>
                        </span>
                        <label class="pf-photo-btn" for="pfPhotoInput" title="Change profile picture">
                            <i class="fas fa-camera" aria-hidden="true"></i>
                            <span class="visually-hidden">Change profile picture</span>
                        </label>
                        <input type="file" id="pfPhotoInput" accept="image/jpeg,image/png,image/webp" hidden>
                    </div>
                    <div class="pf-identity-text">
                        <h2 class="pf-name" id="pfTitle"><?php echo htmlspecialchars($pf_name); ?></h2>
                        <p class="pf-role">
                            <span class="pf-role-tag <?php echo $pf_is_owner ? 'is-owner' : ''; ?>"><?php echo $pf_is_owner ? 'Owner' : 'Employee'; ?></span>
                            <?php echo htmlspecialchars($pf_user['position'] ?: ''); ?>
                        </p>
                        <div class="pf-photo-actions">
                            <label class="pf-link" for="pfPhotoInput"><?php echo $pf_avatar ? 'Change photo' : 'Add a photo'; ?></label>
                            <button type="button" class="pf-link pf-link-muted" id="pfPhotoRemove" <?php echo $pf_avatar ? '' : 'hidden'; ?>>Remove</button>
                        </div>
                        <p class="pf-photo-status" id="pfPhotoStatus" aria-live="polite"></p>
                    </div>
                </div>

                <!-- Details -->
                <dl class="pf-details">
                    <div><dt>Email</dt><dd><?php echo htmlspecialchars($pf_user['email'] ?: 'Not provided'); ?></dd></div>
                    <div><dt>Member since</dt><dd><?php echo htmlspecialchars($pf_since ?: '—'); ?></dd></div>
                    <div><dt>Previous sign-in</dt><dd><?php echo htmlspecialchars($pf_previous ?: 'This is your first'); ?></dd></div>
                    <div><dt>This session</dt><dd><?php echo htmlspecialchars($pf_session ? 'Since ' . $pf_session : '—'); ?></dd></div>
                </dl>

                <?php if (!$pf_is_owner && isset($daily_sales, $orders_today)): ?>
                <!-- Employee: today at a glance -->
                <div class="pf-section">
                    <h3 class="pf-section-title">Today</h3>
                    <div class="pf-stats">
                        <div><span class="pf-stat-value">₱<?php echo number_format((float)$daily_sales, 2); ?></span><span class="pf-stat-label">Your sales</span></div>
                        <div><span class="pf-stat-value"><?php echo (int)$orders_today; ?></span><span class="pf-stat-label">Transactions</span></div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Access -->
                <div class="pf-section">
                    <h3 class="pf-section-title">What you can access</h3>
                    <ul class="pf-access">
                        <?php foreach ($pf_access as $item): ?>
                            <li><i class="fas fa-check" aria-hidden="true"></i><?php echo htmlspecialchars($item); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <!-- Change PIN (ids used by changeOwnPin()) -->
                <div class="pf-section">
                    <button class="pf-toggle collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#changePinCollapse" aria-expanded="false" aria-controls="changePinCollapse">
                        <span><i class="fas fa-key" aria-hidden="true"></i>Change my PIN</span>
                        <i class="fas fa-chevron-down pf-chevron" aria-hidden="true"></i>
                    </button>
                    <div class="collapse" id="changePinCollapse">
                        <div class="pf-pin-form">
                            <div class="pf-field">
                                <label for="currentPin">Current PIN</label>
                                <input type="password" class="pf-input pf-pin" id="currentPin" maxlength="4" inputmode="numeric" autocomplete="current-password">
                            </div>
                            <div class="pf-field">
                                <label for="newPinSelf">New PIN</label>
                                <input type="password" class="pf-input pf-pin" id="newPinSelf" maxlength="4" inputmode="numeric" autocomplete="new-password">
                            </div>
                            <div class="pf-field">
                                <label for="confirmPinSelf">Confirm</label>
                                <input type="password" class="pf-input pf-pin" id="confirmPinSelf" maxlength="4" inputmode="numeric" autocomplete="new-password">
                            </div>
                            <button type="button" class="pf-btn pf-btn-primary" onclick="changeOwnPin()">Save new PIN</button>
                        </div>
                    </div>
                </div>

                <?php if ($pf_is_owner): ?>
                <!-- Recovery PIN: resets the sign-in PIN from the login screen ("Forgot PIN?") -->
                <div class="pf-section">
                    <button class="pf-toggle collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#recoveryPinCollapse" aria-expanded="false" aria-controls="recoveryPinCollapse">
                        <span><i class="fas fa-life-ring" aria-hidden="true"></i>Recovery PIN
                            <small class="pf-recovery-state <?php echo $pf_has_recovery ? 'is-set' : ''; ?>" id="pfRecoveryState"><?php echo $pf_has_recovery ? 'Set' : 'Not set'; ?></small>
                        </span>
                        <i class="fas fa-chevron-down pf-chevron" aria-hidden="true"></i>
                    </button>
                    <div class="collapse" id="recoveryPinCollapse">
                        <p class="pf-recovery-note">If you forget your sign-in PIN, tap your name on the login screen, choose <strong>Forgot PIN?</strong> and enter this 4-digit recovery PIN to choose a new one. It must be different from your sign-in PIN; keep it somewhere safe.</p>
                        <div class="pf-pin-form">
                            <div class="pf-field">
                                <label for="recoveryCurrentPin">Sign-in PIN</label>
                                <input type="password" class="pf-input pf-pin" id="recoveryCurrentPin" maxlength="4" inputmode="numeric" autocomplete="current-password">
                            </div>
                            <div class="pf-field">
                                <label for="recoveryPinNew">Recovery PIN</label>
                                <input type="password" class="pf-input pf-pin" id="recoveryPinNew" maxlength="4" inputmode="numeric" autocomplete="new-password">
                            </div>
                            <div class="pf-field">
                                <label for="recoveryPinConfirm">Confirm</label>
                                <input type="password" class="pf-input pf-pin" id="recoveryPinConfirm" maxlength="4" inputmode="numeric" autocomplete="new-password">
                            </div>
                            <button type="button" class="pf-btn pf-btn-primary" onclick="saveRecoveryPin()">Save recovery PIN</button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="pf-foot">
                <button type="button" class="pf-btn" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
