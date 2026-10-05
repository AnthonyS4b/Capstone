<?php

/** Shared authentication and request-security helpers. */

// The store runs on Philippine time. XAMPP's php.ini defaults to Europe/Berlin,
// which put PHP's dates 6 hours behind MySQL's (Asia/Singapore, also UTC+8).
date_default_timezone_set('Asia/Manila');

// Per Kilo quantities (decimal kilograms) vs whole-number units, used across pages
require_once __DIR__ . '/product_units.php';

function security_start_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
    security_sync_session_user();
}

function security_csrf_token(): string
{
    security_start_session();
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function security_json_error(string $message, int $status = 400): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $message, 'error' => $message]);
    exit;
}

function security_require_login(): void
{
    security_start_session();
    if (empty($_SESSION['user_id'])) {
        security_json_error('Authentication required. Please log in again.', 401);
    }
}

function security_require_role(array $roles): void
{
    security_require_login();
    if (!in_array($_SESSION['role'] ?? '', $roles, true)) {
        security_json_error('You are not authorized to perform this action.', 403);
    }
}

function security_request_token(): string
{
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (is_string($header) && $header !== '') return $header;
    $postToken = $_POST['csrf_token'] ?? '';
    return is_string($postToken) ? $postToken : '';
}

function security_require_csrf(): void
{
    security_start_session();
    $expected = $_SESSION['csrf_token'] ?? '';
    $received = security_request_token();
    if (!is_string($expected) || $expected === '' || $received === '' || !hash_equals($expected, $received)) {
        security_json_error('Your security token is invalid or expired. Refresh the page and try again.', 419);
    }
}

function security_require_post_csrf(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        security_require_csrf();
    }
}

function security_client_key(string $scope, string $subject = ''): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return hash('sha256', $scope . '|' . $ip . '|' . $subject);
}

/**
 * One wrong-PIN counter per account, shared by every place a PIN is typed:
 * login, switch account, change my PIN and the owner PIN check. Separate
 * counters used to add up (5 tries on the login page plus 5 on Switch account).
 * The id is normalised so "4" and "04" cannot open separate counters.
 */
/**
 * Why a new 4-digit PIN is too easy to guess, or null when it is fine.
 * Refused: consecutive digits up or down (1234, 6789, 4321) and one digit
 * repeated (1111). Used for sign-in PINs and the owner's recovery PIN.
 */
function security_weak_pin_reason(string $pin): ?string
{
    if (!preg_match('/^\d{4}$/', $pin)) return null; // length/format is checked separately
    if (count(array_unique(str_split($pin))) === 1) {
        return 'A PIN cannot be the same digit four times (like 1111). Choose a harder one.';
    }
    if (strpos('0123456789', $pin) !== false || strpos('9876543210', $pin) !== false) {
        return 'A PIN cannot be consecutive numbers (like 1234 or 4321). Choose a harder one.';
    }
    return null;
}

function security_pin_limit_key($userId): string
{
    return security_client_key('pin', (string)(int)$userId);
}

function security_rate_limit(string $key, int $maxAttempts, int $windowSeconds): array
{
    $now = time();
    $path = security_rate_limit_path($key);
    $handle = fopen($path, 'c+');
    if ($handle === false) return ['allowed' => false, 'retry_after' => $windowSeconds, 'attempts' => $maxAttempts];
    flock($handle, LOCK_EX);
    $raw = stream_get_contents($handle);
    $entry = json_decode($raw ?: '', true);
    if (!is_array($entry)) $entry = ['attempts' => 0, 'started_at' => $now];

    if (($now - (int)$entry['started_at']) >= $windowSeconds) {
        $entry = ['attempts' => 0, 'started_at' => $now];
        security_write_rate_entry($handle, $entry);
    }

    $retryAfter = max(0, $windowSeconds - ($now - (int)$entry['started_at']));
    $result = [
        'allowed' => (int)$entry['attempts'] < $maxAttempts,
        'retry_after' => $retryAfter,
        'attempts' => (int)$entry['attempts'],
    ];
    flock($handle, LOCK_UN);
    fclose($handle);
    return $result;
}

function security_record_failure(string $key): void
{
    $now = time();
    $handle = fopen(security_rate_limit_path($key), 'c+');
    if ($handle === false) return;
    flock($handle, LOCK_EX);
    $raw = stream_get_contents($handle);
    $entry = json_decode($raw ?: '', true);
    if (!is_array($entry)) $entry = ['attempts' => 0, 'started_at' => $now];
    $entry['attempts'] = (int)$entry['attempts'] + 1;
    security_write_rate_entry($handle, $entry);
    flock($handle, LOCK_UN);
    fclose($handle);
}

function security_clear_failures(string $key): void
{
    $handle = fopen(security_rate_limit_path($key), 'c+');
    if ($handle === false) return;
    flock($handle, LOCK_EX);
    security_write_rate_entry($handle, ['attempts' => 0, 'started_at' => time()]);
    flock($handle, LOCK_UN);
    fclose($handle);
}

function security_rate_limit_path(string $key): string
{
    $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'espenida_rate_limits';
    if (!is_dir($directory)) mkdir($directory, 0700, true);
    return $directory . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
}

function security_write_rate_entry($handle, array $entry): void
{
    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, json_encode($entry));
    fflush($handle);
}

/**
 * Re-read the signed-in account from the database once per request.
 *
 * The session is a copy taken at sign-in. Without this, an owner demoted to
 * employee kept owner access, and a deleted account kept working, until they
 * signed out. Name and position changes now show up straight away too.
 *
 * Pages start their own session before loading this file, so it runs from the
 * bottom of the file as well as from security_start_session().
 */
function security_sync_session_user(): void
{
    static $synced = false;
    if ($synced || session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['user_id'])) return;
    $synced = true;

    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/promotion_pricing.php';
    require_once __DIR__ . '/inventory_expiry.php';

    try {
        $pdo = getDBConnection();
        $st = $pdo->prepare('SELECT first_name, last_name, email, role, position, is_active FROM users WHERE id = ?');
        $st->execute([(int)$_SESSION['user_id']]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // A database hiccup should not sign everyone out
        error_log('security_sync_session_user: ' . $e->getMessage());
        return;
    }

    if (!$user || (int)$user['is_active'] !== 1) {
        // Deleted or deactivated: this session no longer belongs to anyone
        $_SESSION = [];
        session_regenerate_id(true);
        return;
    }

    foreach (['first_name', 'last_name', 'email', 'role', 'position'] as $field) {
        $_SESSION[$field] = $user[$field];
    }

    // Promotions that have run out give the regular price back before anything reads a price
    try {
        promotions_expire_due($pdo);
    } catch (Throwable $e) {
        error_log('promotions_expire_due: ' . $e->getMessage());
    }

    // Expired batches leave inventory so the fresh stock behind them can be sold
    try {
        inventory_expire_batches($pdo);
    } catch (Throwable $e) {
        error_log('inventory_expire_batches: ' . $e->getMessage());
    }
}

security_sync_session_user();
