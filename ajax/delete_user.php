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
    $check = $pdo->prepare("SELECT id, role, first_name, last_name FROM users WHERE id = ?");
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
    
    $name = trim($target['first_name'] . ' ' . $target['last_name']);

    // Someone who has made sales, changed stock or set up products is part of the
    // store's records. Deleting the row would leave all of that with no name
    // ("Unknown User" in Log history), so the account is switched off instead:
    // it can no longer sign in, and its name stays on everything it did.
    $history = $pdo->prepare("
        SELECT (SELECT COUNT(*) FROM transactions      WHERE user_id = :u1)
             + (SELECT COUNT(*) FROM sales             WHERE user_id = :u2)
             + (SELECT COUNT(*) FROM inventory_history WHERE user_id = :u3)
             + (SELECT COUNT(*) FROM strategy_history  WHERE created_by = :u4)
             + (SELECT COUNT(*) FROM products WHERE created_by = :u5 OR updated_by = :u6 OR deleted_by = :u7 OR archived_by = :u8)
             + (SELECT COUNT(*) FROM categories        WHERE deleted_by = :u9)
    ");
    $history->execute(array_fill_keys([':u1', ':u2', ':u3', ':u4', ':u5', ':u6', ':u7', ':u8', ':u9'], $user_id));

    // Either way they stop waiting on a "forgot PIN" request
    try {
        require_once dirname(__DIR__) . '/includes/pin_recovery.php';
        pin_recovery_resolve($pdo, $user_id, (int)$_SESSION['user_id']);
    } catch (PDOException $e) {
        error_log('delete_user.php PIN request: ' . $e->getMessage());
    }

    if ((int)$history->fetchColumn() > 0) {
        $pdo->prepare("UPDATE users SET is_active = 0 WHERE id = ?")->execute([$user_id]);
        echo json_encode([
            'success' => true,
            'deactivated' => true,
            'message' => $name . ' has sales or activity on record, so the account was deactivated instead of deleted. '
                . 'They can no longer sign in, and their name stays on past records.',
        ]);
        exit();
    }

    // Nothing on record: remove the account and the sign-in entries only it used
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM login_sessions WHERE user_id = ?")->execute([$user_id]);
    $pdo->prepare("DELETE FROM pin_reset_requests WHERE user_id = ?")->execute([$user_id]);
    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$user_id]);
    $pdo->commit();
    echo json_encode(['success' => true, 'deactivated' => false, 'message' => $name . ' has been deleted.']);
    
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('delete_user.php: ' . $e->getMessage());
    security_json_error('Unable to delete the user right now.', 500);
}
?>
