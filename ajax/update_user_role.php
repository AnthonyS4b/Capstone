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
    $check = $pdo->prepare("SELECT id, role FROM users WHERE id = ?");
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
    
    // Update user
    $stmt = $pdo->prepare("UPDATE users SET role = ?, position = ? WHERE id = ?");
    
    if ($stmt->execute([$role, $position, $user_id])) {
        echo json_encode(['success' => true, 'message' => 'User role updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update user role']);
    }
    
} catch (PDOException $e) {
    error_log('update_user_role.php: ' . $e->getMessage());
    security_json_error('Unable to update the user role right now.', 500);
}
?>
