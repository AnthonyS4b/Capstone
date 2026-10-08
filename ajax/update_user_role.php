<?php
require_once dirname(__DIR__) . '/includes/security.php';
security_start_session();
header('Content-Type: application/json');

security_require_role(['owner']);
security_require_csrf();

require_once '../config/database.php';

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['user_id']) || !isset($input['role'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

$user_id = intval($input['user_id']);
$role = $input['role'];
$position = isset($input['position']) ? $input['position'] : '';

// Validate role
if (!in_array($role, ['owner', 'employee'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid role']);
    exit();
}

try {
    $pdo = getDBConnection();
    
    // Check if user exists
    $check = $pdo->prepare("SELECT id, role, is_active FROM users WHERE id = ?");
    $check->execute([$user_id]);
    $target = $check->fetch(PDO::FETCH_ASSOC);

    if (!$target) {
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit();
    }

    // The store must always keep an owner, or nobody could reach the owner pages again
    if ($target['role'] === 'owner' && $role !== 'owner') {
        $others = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'owner' AND is_active = 1 AND id <> ?");
        $others->execute([$user_id]);
        if ((int)$others->fetchColumn() === 0) {
            echo json_encode(['success' => false, 'message' => 'This is the only owner account. Make someone else an owner first, then change this role.']);
            exit();
        }
    }
    
    // Promoting an employee uses one of the owner places (a deactivated account takes
    // its place when it is reactivated). Counted and saved in one transaction.
    $pdo->beginTransaction();
    if ($role === 'owner' && $target['role'] !== 'owner' && (int)$target['is_active'] === 1
        && security_active_owner_count($pdo, $user_id) >= MAX_OWNER_ACCOUNTS) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => security_owner_limit_message()]);
        exit();
    }

    // Update user
    $stmt = $pdo->prepare("UPDATE users SET role = ?, position = ? WHERE id = ?");

    if ($stmt->execute([$role, $position, $user_id])) {
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'User role updated successfully']);
    } else {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Failed to update user role']);
    }

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('update_user_role.php: ' . $e->getMessage());
    security_json_error('Unable to update the user role right now.', 500);
}
?>
