    <?php
    require_once dirname(__DIR__) . '/includes/security.php';
    security_start_session();
    header('Content-Type: application/json');

    // Check if user is logged in and is owner
    security_require_role(['owner']);

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
        error_log('get_user.php: ' . $e->getMessage());
        security_json_error('Unable to load users right now.', 500);
    }
    ?>
