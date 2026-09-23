<?php
require_once dirname(__DIR__) . '/includes/security.php';
security_start_session();
header('Content-Type: application/json');

security_require_role(['owner']);
security_require_csrf();

require_once '../config/database.php';

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Missing user ID']);
    exit();
}

$user_id = intval($input['user_id']);

// Prevent deleting yourself
if ($user_id == $_SESSION['user_id']) {
    echo json_encode(['success' => false, 'message' => 'Cannot delete your own account']);
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

    // The store must always keep an owner
    if ($target['role'] === 'owner') {
        $others = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'owner' AND is_active = 1 AND id <> ?");
        $others->execute([$user_id]);
        if ((int)$others->fetchColumn() === 0) {
            echo json_encode(['success' => false, 'message' => 'This is the only owner account and cannot be deleted.']);
            exit();
        }
    }
    
    // Delete user
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    
    if ($stmt->execute([$user_id])) {
        echo json_encode(['success' => true, 'message' => 'User deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete user']);
    }
    
} catch (PDOException $e) {
    error_log('delete_user.php: ' . $e->getMessage());
    security_json_error('Unable to delete the user right now.', 500);
}
?>
