    <?php
    session_start();
    header('Content-Type: application/json');

    // Check if user is logged in and is owner
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'owner') {
        echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
        exit();
    }

    require_once '../config/database.php';

    try {
        $pdo = getDBConnection();
        
        // Fetch all users
        $stmt = $pdo->query("SELECT id, first_name, last_name, email, role, position, created_at FROM users ORDER BY id DESC");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'users' => $users
        ]);
        
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    }
    ?>