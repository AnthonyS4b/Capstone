<?php
// Load database config early to ensure getDBConnection exists
$db_config = dirname(__DIR__) . '/config/database.php';
if (file_exists($db_config)) {
    require_once $db_config;
}

function recordUserSession($user_id, $action, $switched_from = null, $switched_from_id = null) {
    // If no database function available after attempt, store in session
    if (!function_exists('getDBConnection')) {
        if (!isset($_SESSION['session_tracking'])) {
            $_SESSION['session_tracking'] = [];
        }
        
        $_SESSION['session_tracking'][] = [
            'user_id' => $user_id,
            'action' => $action,
            'switched_from' => $switched_from,
            'timestamp' => date('Y-m-d H:i:s'),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ];
        return true;
    }
    
    try {
        $pdo = getDBConnection();
        
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        if ($action === 'logout') {
            // FIXED: First, find the most recent login for this user
            $stmt = $pdo->prepare("
                SELECT id FROM login_sessions 
                WHERE user_id = ? AND action = 'login' AND logout_time IS NULL
                ORDER BY login_time DESC LIMIT 1
            ");
            $stmt->execute([$user_id]);
            $login_session = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($login_session) {
                // Update that login session with logout time
                $stmt = $pdo->prepare("
                    UPDATE login_sessions 
                    SET logout_time = NOW(),
                        session_duration = TIMESTAMPDIFF(SECOND, login_time, NOW())
                    WHERE id = ?
                ");
                $stmt->execute([$login_session['id']]);
                
                // ALSO insert a logout record for better tracking
                $stmt = $pdo->prepare("
                    INSERT INTO login_sessions 
                    (user_id, action, ip_address, user_agent, login_time) 
                    VALUES (?, 'logout', ?, ?, NOW())
                ");
                $stmt->execute([$user_id, $ip_address, $user_agent]);
            } else {
                // No active login found, just insert a logout record
                $stmt = $pdo->prepare("
                    INSERT INTO login_sessions 
                    (user_id, action, ip_address, user_agent, login_time) 
                    VALUES (?, 'logout', ?, ?, NOW())
                ");
                $stmt->execute([$user_id, $ip_address, $user_agent]);
            }
        } 
        elseif ($action === 'login') {
            // Insert new login session
            $stmt = $pdo->prepare("
                INSERT INTO login_sessions 
                (user_id, action, ip_address, user_agent, login_time) 
                VALUES (?, 'login', ?, ?, NOW())
            ");
            $stmt->execute([$user_id, $ip_address, $user_agent]);
        }
        elseif ($action === 'switch') {
            // Insert switch record
            $stmt = $pdo->prepare("
                INSERT INTO login_sessions 
                (user_id, action, ip_address, user_agent, switched_from, switched_from_id, login_time) 
                VALUES (?, 'switch', ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$user_id, $ip_address, $user_agent, $switched_from, $switched_from_id]);
        }
        
        return true;
    } catch (Exception $e) {
        // Log error but don't break the application
        error_log("Session tracking error: " . $e->getMessage());
        
        // Store in session as backup
        if (!isset($_SESSION['session_tracking'])) {
            $_SESSION['session_tracking'] = [];
        }
        $_SESSION['session_tracking'][] = [
            'user_id' => $user_id,
            'action' => $action,
            'switched_from' => $switched_from,
            'timestamp' => date('Y-m-d H:i:s'),
            'error' => true
        ];
        
        return false;
    }
}

// Record login
function recordLogin($user_id) {
    return recordUserSession($user_id, 'login');
}

// Record logout  
function recordLogout($user_id) {
    return recordUserSession($user_id, 'logout');
}

// Record account switch
function recordSwitch($new_user_id, $old_user_email, $old_user_id) {
    return recordUserSession($new_user_id, 'switch', $old_user_email, $old_user_id);
}
?>