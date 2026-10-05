<?php
/**
 * Forgot PIN.
 *
 * Owner:    resets their own 4-digit sign-in PIN at the login screen with a separate
 *           4-digit recovery PIN (always different from the sign-in PIN), set beforehand in My profile (users.recovery_pin,
 *           hashed like the sign-in PIN).
 * Employee: sends a request from the login screen. The owner sees it in the
 *           notification bell and sets a new PIN in User roles, which closes it.
 *
 * Used by Login.php, ajax/user_ajax.php, ajax/get_user.php and includes/notifications.php.
 */
require_once __DIR__ . '/security.php';

const RECOVERY_PIN_LENGTH = 4;

/**
 * Add the recovery PIN column and the request table when they are missing,
 * so a database imported from an older export keeps working.
 */
function pin_recovery_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    // Cheap reads first: this runs on owner page loads (the bell), DDL only when something is missing
    $hasColumn = (bool)$pdo->query("SHOW COLUMNS FROM users LIKE 'recovery_pin'")->fetch();
    $hasTable = (bool)$pdo->query("SHOW TABLES LIKE 'pin_reset_requests'")->fetch();
    if ($hasColumn && $hasTable) return;
    if (!$hasColumn) $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS recovery_pin VARCHAR(255) NULL DEFAULT NULL AFTER pin");
    if (!$hasTable) $pdo->exec("CREATE TABLE IF NOT EXISTS pin_reset_requests (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id INT UNSIGNED NOT NULL,
                    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    resolved_at DATETIME NULL DEFAULT NULL,
                    resolved_by INT UNSIGNED NULL DEFAULT NULL,
                    PRIMARY KEY (id),
                    KEY idx_pin_requests_open (resolved_at, user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function pin_recovery_limit_key($userId): string
{
    return security_client_key('recovery-pin', (string)(int)$userId);
}

/** Whether this owner has a recovery PIN, i.e. can reset their PIN at the login screen. */
function pin_recovery_is_set(PDO $pdo, int $userId): bool
{
    pin_recovery_ensure_schema($pdo);
    $st = $pdo->prepare("SELECT recovery_pin FROM users WHERE id = ?");
    $st->execute([$userId]);
    return (string)$st->fetchColumn() !== '';
}

/**
 * Owner at the login screen: check the recovery PIN, then set a new sign-in PIN.
 * Returns ['success' => bool, 'message' => string].
 */
function pin_recovery_reset_owner_pin(PDO $pdo, $userId, string $recoveryPin, string $newPin, string $confirmPin): array
{
    pin_recovery_ensure_schema($pdo);
    $userId = (int)$userId;

    if (!preg_match('/^\d{' . RECOVERY_PIN_LENGTH . '}$/', $recoveryPin)) {
        return ['success' => false, 'message' => 'The recovery PIN is ' . RECOVERY_PIN_LENGTH . ' digits.'];
    }
    if (!preg_match('/^\d{4}$/', $newPin)) {
        return ['success' => false, 'message' => 'The new PIN must be exactly 4 digits.'];
    }
    if ($newPin !== $confirmPin) {
        return ['success' => false, 'message' => 'The new PIN and its confirmation do not match.'];
    }
    if ($newPin === $recoveryPin) {
        return ['success' => false, 'message' => 'Choose a new PIN that is different from your recovery PIN.'];
    }
    if ($weak = security_weak_pin_reason($newPin)) {
        return ['success' => false, 'message' => $weak];
    }

    // 5 wrong recovery PINs lock this for 15 minutes
    $limitKey = pin_recovery_limit_key($userId);
    $limit = security_rate_limit($limitKey, 5, 900);
    if (!$limit['allowed']) {
        return ['success' => false, 'message' => 'Too many incorrect recovery PINs. Try again in 15 minutes.'];
    }

    $st = $pdo->prepare("SELECT role, recovery_pin FROM users WHERE id = ? AND is_active = 1");
    $st->execute([$userId]);
    $user = $st->fetch(PDO::FETCH_ASSOC);
    if (!$user || $user['role'] !== 'owner') {
        return ['success' => false, 'message' => 'Only the owner can reset a PIN this way.'];
    }
    if ((string)$user['recovery_pin'] === '') {
        return ['success' => false, 'message' => 'No recovery PIN has been set for this account, so the PIN cannot be reset here.'];
    }
    if (!password_verify($recoveryPin, $user['recovery_pin'])) {
        security_record_failure($limitKey);
        $remaining = max(0, 5 - (int)($limit['attempts'] ?? 0) - 1);
        return ['success' => false, 'message' => $remaining > 0
            ? 'Incorrect recovery PIN. ' . $remaining . ' attempt' . ($remaining === 1 ? '' : 's') . ' remaining.'
            : 'Incorrect recovery PIN.'];
    }

    $pdo->prepare("UPDATE users SET pin = ? WHERE id = ?")->execute([password_hash($newPin, PASSWORD_DEFAULT), $userId]);
    security_clear_failures($limitKey);
    security_clear_failures(security_pin_limit_key($userId)); // the old wrong sign-in attempts no longer apply
    error_log("PIN reset with the recovery PIN for owner #{$userId}");
    return ['success' => true, 'message' => 'Your PIN has been reset. Sign in with your new PIN.'];
}

/**
 * Employee at the login screen: ask the owner for a new PIN.
 * Returns ['success' => bool, 'message' => string].
 */
function pin_recovery_request_reset(PDO $pdo, $userId): array
{
    pin_recovery_ensure_schema($pdo);
    $userId = (int)$userId;

    $st = $pdo->prepare("SELECT role FROM users WHERE id = ? AND is_active = 1");
    $st->execute([$userId]);
    $role = $st->fetchColumn();
    if ($role === false) {
        return ['success' => false, 'message' => 'This account is not active.'];
    }
    if ($role === 'owner') {
        return ['success' => false, 'message' => 'Owners reset their PIN with their recovery PIN.'];
    }

    $open = $pdo->prepare("SELECT 1 FROM pin_reset_requests WHERE user_id = ? AND resolved_at IS NULL LIMIT 1");
    $open->execute([$userId]);
    if ($open->fetchColumn()) {
        return ['success' => true, 'message' => 'The owner has already been told. Ask them to set a new PIN for you.'];
    }

    // Stops one device from filling the owner's bell
    $limitKey = security_client_key('pin-request');
    $limit = security_rate_limit($limitKey, 10, 3600);
    if (!$limit['allowed']) {
        return ['success' => false, 'message' => 'Too many requests from this device. Please ask the owner directly.'];
    }
    security_record_failure($limitKey);

    $pdo->prepare("INSERT INTO pin_reset_requests (user_id, requested_at) VALUES (?, NOW())")->execute([$userId]);
    return ['success' => true, 'message' => 'The owner has been notified. They will set a new PIN for you.'];
}

/** Close the open request(s) of a user whose PIN the owner has just reset. */
function pin_recovery_resolve(PDO $pdo, int $userId, int $ownerId): void
{
    pin_recovery_ensure_schema($pdo);
    $pdo->prepare("UPDATE pin_reset_requests SET resolved_at = NOW(), resolved_by = ?
                   WHERE user_id = ? AND resolved_at IS NULL")->execute([$ownerId, $userId]);
}

/** Open requests from active users, oldest first: id, user_id, requested_at, first_name, last_name. */
function pin_recovery_open_requests(PDO $pdo): array
{
    pin_recovery_ensure_schema($pdo);
    return $pdo->query("SELECT r.id, r.user_id, r.requested_at, u.first_name, u.last_name
                        FROM pin_reset_requests r
                        JOIN users u ON u.id = r.user_id AND u.is_active = 1
                        WHERE r.resolved_at IS NULL
                        ORDER BY r.requested_at")->fetchAll(PDO::FETCH_ASSOC);
}
