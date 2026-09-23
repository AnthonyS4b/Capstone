<?php
/**
 * Current user's profile picture path (or null), cached in the session.
 * The cache is keyed by user id, so switching accounts never shows the
 * previous person's photo.
 */
if (!function_exists('current_user_avatar')) {
    function current_user_avatar(): ?string
    {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) return null;

        if (($_SESSION['avatar_uid'] ?? null) !== $uid || !array_key_exists('avatar', $_SESSION)) {
            $avatar = null;
            try {
                require_once __DIR__ . '/../config/database.php';
                $stmt = getDBConnection()->prepare('SELECT avatar FROM users WHERE id = ?');
                $stmt->execute([$uid]);
                $avatar = $stmt->fetchColumn() ?: null;
            } catch (Throwable $e) {
                error_log('current_user_avatar: ' . $e->getMessage());
            }
            $_SESSION['avatar'] = $avatar;
            $_SESSION['avatar_uid'] = $uid;
        }

        $path = $_SESSION['avatar'];
        // Only ever hand back files from our own avatar folder
        return ($path && strpos($path, 'assets/uploads/avatars/') === 0
            && is_file(__DIR__ . '/../' . $path)) ? $path : null;
    }
}

if (!function_exists('user_avatar_html')) {
    /**
     * Photo (if the file exists) or initials for any user row.
     * $class sizes/styles the element; initials are always escaped.
     */
    function user_avatar_html(?string $avatar, string $first, string $last, string $class = ''): string
    {
        $initials = strtoupper(substr($first, 0, 1) . substr($last, 0, 1)) ?: '?';
        $ok = $avatar && strpos($avatar, 'assets/uploads/avatars/') === 0 && is_file(__DIR__ . '/../' . $avatar);
        $inner = $ok
            ? '<img src="' . htmlspecialchars($avatar) . '" alt="">'
            : htmlspecialchars($initials);
        return '<span class="' . htmlspecialchars($class) . '" aria-hidden="true">' . $inner . '</span>';
    }
}
