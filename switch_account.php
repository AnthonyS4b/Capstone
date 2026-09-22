<?php
session_start();
require_once __DIR__ . '/includes/security.php';
security_require_login();

require_once 'config/database.php';
require_once 'includes/session_tracker.php';

/**
 * The PIN form posts over fetch so a wrong PIN can be reported without
 * reloading the page, which used to close the modal and make the cashier
 * reselect the account and retype the PIN. A plain form post still works
 * when JavaScript is unavailable, and that path keeps redirecting.
 */
$isAjax = (
    (isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_POST['ajax']) && $_POST['ajax'] === '1')
);

/**
 * Answer in whichever form the caller expects, then stop.
 */
function switch_respond(bool $isAjax, bool $ok, string $title, string $message, string $redirect = 'dashboard.php'): void
{
    if ($isAjax) {
        http_response_code($ok ? 200 : 401);
        header('Content-Type: application/json');
        echo json_encode([
            'success'  => $ok,
            'title'    => $title,
            'message'  => $message,
            'redirect' => $ok ? $redirect : null,
        ]);
        exit;
    }

    $_SESSION['toast_message'] = [
        'type'    => $ok ? 'success' : 'error',
        'title'   => $title,
        'message' => $message,
    ];
    header('Location: ' . $redirect);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'], $_POST['pin'])) {
    security_require_csrf();
    $target_user_id = $_POST['user_id'];
    $pin = $_POST['pin'];
    $limitKey = security_client_key('switch_account', (string)$target_user_id);
    $limit = security_rate_limit($limitKey, 5, 300);
    if (!$limit['allowed']) {
        switch_respond($isAjax, false, 'Too Many Attempts',
            'Too many incorrect attempts. Try again in a few minutes.');
    }
    
    try {
        $pdo = getDBConnection();
        
        // Verify PIN and get user data (also check is_active)
        $stmt = $pdo->prepare("SELECT id, first_name, last_name, email, role, position, pin FROM users WHERE id = ? AND is_active = 1");
        $stmt->execute([$target_user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($pin, $user['pin'])) {
            security_clear_failures($limitKey);
            // Store current session data to preserve store progress
            $current_session_data = $_SESSION;
            
            // Get current user info BEFORE switching (for tracking)
            $old_user_id = $_SESSION['user_id'] ?? null;
            $old_user_email = $_SESSION['email'] ?? null;
            $old_user_name = $_SESSION['first_name'] ?? 'Unknown';
            
            // ===== RECORD LOGOUT FOR OLD USER =====
            if ($old_user_id) {
                recordLogout($old_user_id);
                error_log("Logout recorded for old user ID: " . $old_user_id);
            }
            
            // Clear session but keep specific data if needed
            $_SESSION = array();
            
            // Regenerate session ID for security
            session_regenerate_id(true);
            
            // Set new user session
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['last_name'] = $user['last_name'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['position'] = $user['position'];
            
            // Preserve store progress
            if (isset($current_session_data['cart'])) {
                $_SESSION['cart'] = $current_session_data['cart'];
            }
            if (isset($current_session_data['store_settings'])) {
                $_SESSION['store_settings'] = $current_session_data['store_settings'];
            }
            
            // ===== RECORD SWITCH FOR NEW USER =====
            recordSwitch($user['id'], $old_user_email, $old_user_id);
            error_log("Switch recorded for new user: " . $user['first_name'] . " from: " . $old_user_name);
            
            // Queue the toast for whichever page we land on next
            $_SESSION['toast_message'] = [
                'type'    => 'success',
                'title'   => 'Account Switched!',
                'message' => 'Welcome back, ' . $user['first_name'] . '! Your store progress continues.'
            ];

            switch_respond($isAjax, true, 'Account Switched!',
                'Welcome back, ' . $user['first_name'] . '!');
        } else {
            security_record_failure($limitKey);
            // $limit['attempts'] is the count before this try; this one just failed.
            $remaining = max(0, 5 - (int)($limit['attempts'] ?? 0) - 1);
            $message = $remaining > 0
                ? 'Incorrect PIN. ' . $remaining . ' attempt' . ($remaining === 1 ? '' : 's') . ' remaining.'
                : 'Incorrect PIN.';
            switch_respond($isAjax, false, 'Switch Failed', $message);
        }
    } catch (PDOException $e) {
        error_log("Switch account error: " . $e->getMessage());
        switch_respond($isAjax, false, 'Error', 'An error occurred. Please try again.');
    }
} else {
    header("Location: dashboard.php");
    exit();
}
?>
