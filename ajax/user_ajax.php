<?php
    // ajax/user_ajax.php - PIN verification for owners
    require_once dirname(__DIR__) . '/includes/security.php';
    security_start_session();
    header('Content-Type: application/json');
    security_require_login();
    security_require_post_csrf();

    require_once '../config/database.php';

    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'verify_owner_pin':
            // Check if user is logged in
            if (!isset($_SESSION['user_id'])) {
                echo json_encode(['success' => false, 'message' => 'Not logged in']);
                exit();
            }
            
            $pin = $_POST['pin'] ?? '';
            $limitKey = security_pin_limit_key($_SESSION['user_id']);
            $limit = security_rate_limit($limitKey, 5, 300);
            if (!$limit['allowed']) {
                security_json_error('Too many incorrect attempts. Try again in a few minutes.', 429);
            }
            
            // Validate PIN format
            if (!preg_match('/^\d{4}$/', $pin)) {
                echo json_encode(['success' => false, 'message' => 'Invalid PIN format']);
                exit();
            }
            
            try {
                $pdo = getDBConnection();
                
                // Get current user and check if they're an owner and PIN matches
                $stmt = $pdo->prepare("SELECT id, role, pin FROM users WHERE id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user && $user['role'] === 'owner' && password_verify($pin, $user['pin'])) {
                    security_clear_failures($limitKey);
                    echo json_encode([
                        'success' => true,
                        'is_owner' => true,
                        'message' => 'PIN verified'
                    ]);
                } else {
                    security_record_failure($limitKey);
                    echo json_encode([
                        'success' => false,
                        'is_owner' => false,
                        'message' => 'Invalid PIN or not an owner'
                    ]);
                }
            } catch (PDOException $e) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Unable to verify the PIN right now.'
                ]);
            }
            break;
            
        // ── Owner resets another user's PIN ────────────────────────────────────
        // The owner cannot read or view the current PIN — they only set a new one.
        case 'reset_user_pin':
            if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'owner') {
                echo json_encode(['success' => false, 'message' => 'Unauthorized — owners only']);
                exit();
            }
            $target_id = intval($_POST['user_id'] ?? 0);
            $new_pin   = trim($_POST['new_pin']   ?? '');

            if (!preg_match('/^\d{4}$/', $new_pin)) {
                echo json_encode(['success' => false, 'message' => 'PIN must be exactly 4 digits']);
                exit();
            }
            if ($target_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
                exit();
            }

            try {
                $pdo  = getDBConnection();
                $hashed_pin = password_hash($new_pin, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET pin = ? WHERE id = ?");
                if ($stmt->execute([$hashed_pin, $target_id]) && $stmt->rowCount() > 0) {
                    echo json_encode(['success' => true, 'message' => 'PIN reset successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'User not found or PIN unchanged']);
                }
            } catch (PDOException $e) {
                error_log('reset_user_pin: ' . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Unable to reset the PIN right now.']);
            }
            break;

        // ── Any logged-in user changes their own PIN ────────────────────────
        // Requires the current PIN for verification before allowing the change.
        case 'change_own_pin':
            if (!isset($_SESSION['user_id'])) {
                echo json_encode(['success' => false, 'message' => 'Not logged in']);
                exit();
            }
            $current_pin = trim($_POST['current_pin'] ?? '');
            $new_pin     = trim($_POST['new_pin']     ?? '');

            if (!preg_match('/^\d{4}$/', $current_pin) || !preg_match('/^\d{4}$/', $new_pin)) {
                echo json_encode(['success' => false, 'message' => 'PIN must be exactly 4 digits']);
                exit();
            }
            if ($current_pin === $new_pin) {
                echo json_encode(['success' => false, 'message' => 'New PIN must be different from the current PIN']);
                exit();
            }

            // Same wrong-PIN counter as login and switch account
            $limitKey = security_pin_limit_key($_SESSION['user_id']);
            $limit = security_rate_limit($limitKey, 5, 300);
            if (!$limit['allowed']) {
                echo json_encode(['success' => false, 'message' => 'Too many incorrect attempts. Try again in a few minutes.']);
                exit();
            }

            try {
                $pdo  = getDBConnection();
                $stmt = $pdo->prepare("SELECT pin FROM users WHERE id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$user) {
                    echo json_encode(['success' => false, 'message' => 'User not found']);
                    exit();
                }
                if (!password_verify($current_pin, $user['pin'])) {
                    security_record_failure($limitKey);
                    // $limit['attempts'] is the count before this try; this one just failed.
                    $remaining = max(0, 5 - (int)($limit['attempts'] ?? 0) - 1);
                    echo json_encode(['success' => false, 'message' => $remaining > 0
                        ? 'Current PIN is incorrect. ' . $remaining . ' attempt' . ($remaining === 1 ? '' : 's') . ' remaining.'
                        : 'Current PIN is incorrect.']);
                    exit();
                }
                security_clear_failures($limitKey);

                $hashed_pin = password_hash($new_pin, PASSWORD_DEFAULT);
                $update = $pdo->prepare("UPDATE users SET pin = ? WHERE id = ?");
                if ($update->execute([$hashed_pin, $_SESSION['user_id']])) {
                    echo json_encode(['success' => true, 'message' => 'PIN changed successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to update PIN']);
                }
            } catch (PDOException $e) {
                error_log('change_own_pin: ' . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Unable to change the PIN right now.']);
            }
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
    ?>
