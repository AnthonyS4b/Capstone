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
        $stmt = $pdo->query("SELECT id, first_name, last_name, email, role, position, avatar, is_active, created_at FROM users ORDER BY id DESC");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Only hand out photo paths that point at a real file in the avatar folder
        foreach ($users as &$u) {
            $a = $u['avatar'] ?? null;
            $u['avatar'] = ($a && strpos($a, 'assets/uploads/avatars/') === 0 && is_file(dirname(__DIR__) . '/' . $a)) ? $a : null;
        }
        unset($u);

        // Who asked for a new PIN at the login screen (shown as a tag in User roles)
        try {
            require_once dirname(__DIR__) . '/includes/pin_recovery.php';
            $requested = array_flip(array_map('intval', array_column(pin_recovery_open_requests($pdo), 'user_id')));
            foreach ($users as &$u) {
                $u['pin_requested'] = isset($requested[(int)$u['id']]);
            }
            unset($u);
        } catch (PDOException $e) {
            error_log('get_user.php PIN requests: ' . $e->getMessage());
        }

        echo json_encode([
            'success' => true,
            'users' => $users
        ]);
        
    } catch (PDOException $e) {
        error_log('get_user.php: ' . $e->getMessage());
        security_json_error('Unable to load users right now.', 500);
    }
    ?>
