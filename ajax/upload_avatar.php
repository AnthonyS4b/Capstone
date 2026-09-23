<?php
/**
 * ajax/upload_avatar.php
 * The signed-in user's own profile picture.
 *
 *   POST action=upload, file 'avatar'  (JPEG/PNG/WebP, max 2 MB)
 *   POST action=remove
 *
 * The browser crops and resizes to a 256 px square before sending, but
 * nothing here trusts that: the file type is read from its content, it must
 * decode as an image, and it is stored under a generated name with an
 * extension chosen by the server, so it can never be served as a script.
 *
 * Returns JSON { success, avatar, message }.
 */
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../error.log');

header('Content-Type: application/json');

require_once dirname(__DIR__) . '/includes/security.php';
require_once dirname(__DIR__) . '/config/database.php';

security_require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    security_json_error('Invalid request method', 405);
}
security_require_csrf();

$userId   = (int)$_SESSION['user_id'];
$action   = $_POST['action'] ?? 'upload';
$relDir   = 'assets/uploads/avatars/';
$absDir   = dirname(__DIR__) . '/' . $relDir;

/** Delete a previously stored avatar, but only if it really is one of ours. */
function avatar_delete_file(?string $path, string $relDir, string $absDir): void
{
    if (!$path || strpos($path, $relDir) !== 0) return;
    $file = $absDir . basename($path);
    if (is_file($file)) @unlink($file);
}

try {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare('SELECT avatar FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $current = $stmt->fetchColumn();
    if ($current === false) {
        security_json_error('User not found', 404);
    }

    // ── Remove ──
    if ($action === 'remove') {
        $pdo->prepare('UPDATE users SET avatar = NULL WHERE id = ?')->execute([$userId]);
        avatar_delete_file($current, $relDir, $absDir);
        $_SESSION['avatar'] = null;
        $_SESSION['avatar_uid'] = $userId;
        echo json_encode(['success' => true, 'avatar' => null, 'message' => 'Profile picture removed']);
        exit;
    }

    // ── Upload ──
    if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] === UPLOAD_ERR_NO_FILE) {
        security_json_error('Choose a picture to upload');
    }
    $file = $_FILES['avatar'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        security_json_error($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE
            ? 'That picture is too large (2 MB max)'
            : 'Upload failed. Please try again.');
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        security_json_error('That picture is too large (2 MB max)');
    }

    $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime  = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $info  = @getimagesize($file['tmp_name']);
    if (!isset($types[$mime]) || !$info || $info[0] < 16 || $info[1] < 16 || $info[0] > 4096 || $info[1] > 4096) {
        security_json_error('Please use a JPG, PNG or WebP picture');
    }

    if (!is_dir($absDir) && !mkdir($absDir, 0755, true)) {
        throw new RuntimeException('Cannot create avatar folder');
    }
    $name = 'u' . $userId . '_' . bin2hex(random_bytes(6)) . '.' . $types[$mime];
    if (!move_uploaded_file($file['tmp_name'], $absDir . $name)) {
        throw new RuntimeException('Cannot save uploaded avatar');
    }

    $path = $relDir . $name;
    $pdo->prepare('UPDATE users SET avatar = ? WHERE id = ?')->execute([$path, $userId]);
    avatar_delete_file($current, $relDir, $absDir);
    $_SESSION['avatar'] = $path;
    $_SESSION['avatar_uid'] = $userId;

    echo json_encode(['success' => true, 'avatar' => $path, 'message' => 'Profile picture updated']);
} catch (Throwable $e) {
    error_log('upload_avatar: ' . $e->getMessage());
    security_json_error('Could not update your picture. Please try again.', 500);
}
