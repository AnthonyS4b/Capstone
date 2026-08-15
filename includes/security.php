<?php

/** Shared authentication and request-security helpers. */

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
