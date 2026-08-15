<?php
session_start();
require_once __DIR__ . '/includes/security.php';
security_require_login();

require_once 'config/database.php';
require_once 'includes/session_tracker.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'], $_POST['pin'])) {
    security_require_csrf();
    $target_user_id = $_POST['user_id'];
    $pin = $_POST['pin'];
    $limitKey = security_client_key('switch_account', (string)$target_user_id);
    $limit = security_rate_limit($limitKey, 5, 300);
    if (!$limit['allowed']) {
        security_json_error('Too many incorrect attempts. Try again in a few minutes.', 429);
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
            
            // Set success message
            $_SESSION['toast_message'] = [
                'type' => 'success',
                'title' => 'Account Switched!',
                'message' => 'Welcome back, ' . $user['first_name'] . '! Your store progress continues.'
            ];
            
            header("Location: dashboard.php");
            exit();
        } else {
            security_record_failure($limitKey);
            // Invalid PIN
            $_SESSION['toast_message'] = [
                'type' => 'error',
                'title' => 'Switch Failed',
                'message' => 'Invalid PIN. Please try again.'
            ];
            header("Location: dashboard.php");
            exit();
        }
    } catch (PDOException $e) {
        error_log("Switch account error: " . $e->getMessage());
        $_SESSION['toast_message'] = [
            'type' => 'error',
            'title' => 'Error',
            'message' => 'An error occurred. Please try again.'
        ];
        header("Location: dashboard.php");
        exit();
    }
} else {
    header("Location: dashboard.php");
    exit();
}
?>
