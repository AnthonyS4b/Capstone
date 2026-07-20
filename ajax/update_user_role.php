<?php
session_start();
header('Content-Type: application/json');

// Check if user is logged in and is owner
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'owner') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

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
    $check = $pdo->prepare("SELECT id FROM users WHERE id = ?");
    $check->execute([$user_id]);
    
    if ($check->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit();
    }
    
    // Update user
    $stmt = $pdo->prepare("UPDATE users SET role = ?, position = ? WHERE id = ?");
    
    if ($stmt->execute([$role, $position, $user_id])) {
        echo json_encode(['success' => true, 'message' => 'User role updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update user role']);
    }
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>