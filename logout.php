<?php
// Start output buffering to prevent header errors
ob_start();
session_start();

// Include session tracker
require_once 'includes/session_tracker.php';

// Safe output buffer clearing
if (ob_get_level()) ob_clean();

// ===== TRACK THIS LOGOUT =====
if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    // Record logout BEFORE destroying session
    recordLogout($_SESSION['user_id']);
    
    // Optional: Log for debugging
    error_log("Logout recorded for user ID: " . $_SESSION['user_id']);
} else {
    error_log("Logout attempted but no user_id in session");
}

// Clear all session variables
$_SESSION = array();

// If it's desired to kill the session, also delete the session cookie.
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy session
session_destroy();

// Redirect to login page
header("Location: Login.php?logged_out=1");
exit();
?>