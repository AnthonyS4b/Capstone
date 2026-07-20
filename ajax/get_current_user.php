<?php
session_start();
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false, 
        'message' => 'Not logged in',
        'user_id' => null
    ]);
    exit();
}

require_once dirname(__DIR__) . '/config/database.php';

try {
    $pdo = getDBConnection();
    
    $user_id = (int)$_SESSION['user_id'];
    
    $stmt = $pdo->prepare("SELECT id, first_name, last_name, email, role, position, is_active, created_at FROM users WHERE id = ? AND is_active = 1");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        echo json_encode([
            'success' => true,
            'user_id' => $user['id'],
            'user' => $user
        ]);
    } else {
        echo json_encode([
            'success' => false, 
            'message' => 'User not found or inactive',
            'user_id' => null
        ]);
    }
    
} catch (PDOException $e) {
    error_log("get_current_user error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred',
        'user_id' => null
    ]);
}
?>