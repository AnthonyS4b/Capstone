<?php
// ajax/inventory_ajax.php - TRACKING ONLY VERSION
require_once dirname(__DIR__) . '/includes/security.php';
security_start_session();
header('Content-Type: application/json');

security_require_login();
security_require_post_csrf();

require_once '../config/database.php';

$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? '';

if (in_array($action, ['restore_product', 'get_user_sessions', 'get_filtered_activity', 'get_user_stats', 'get_account_switches'], true)) {
    security_require_role(['owner']);
}

try {
    $pdo = getDBConnection();
    
    switch ($action) {
        
        // ===== 1. GET SINGLE PRODUCT DETAILS (for viewing only) =====
        case 'get_product':
            $id = $_POST['id'] ?? 0;
            $stmt = $pdo->prepare("
                SELECT 
                    p.*,
                    c.name as category_name,
                    CONCAT(u.first_name, ' ', u.last_name) as created_by_name,
                    u.role as created_by_role,
                    CONCAT(ue.first_name, ' ', ue.last_name) as edited_by_name
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                LEFT JOIN users u ON p.created_by = u.id
                LEFT JOIN users ue ON p.updated_by = ue.id
                WHERE p.id = ?
            ");
            $stmt->execute([$id]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($product) {
                echo json_encode(['success' => true, 'data' => $product]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Product not found']);
            }
            break;
        
        // ===== 2. RESTORE PRODUCT (from archive/deleted) =====
        case 'restore_product':
            $id = $_POST['id'] ?? 0;
            
            // Get product info for logging
            $stmt = $pdo->prepare("SELECT name FROM products WHERE id = ?");
            $stmt->execute([$id]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Restore the product (remove delete/archive flags)
            $stmt = $pdo->prepare("
                UPDATE products 
                SET deleted_at = NULL, 
                    deleted_by = NULL, 
                    archived_at = NULL, 
                    archived_by = NULL,
                    updated_at = NOW(),
                    updated_by = ?
                WHERE id = ?
            ");
            
            if ($stmt->execute([$user_id, $id])) {
                // LOG THIS RESTORE ACTION
                $log_stmt = $pdo->prepare("
                    INSERT INTO inventory_history 
                    (product_id, product_name, user_id, action, changes, created_at) 
                    VALUES (?, ?, ?, 'restore', 'Product restored from archive/deleted', NOW())
                ");
                $log_stmt->execute([$id, $product['name'] ?? 'Unknown Product', $user_id]);
                
                echo json_encode(['success' => true, 'message' => 'Product restored successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to restore product']);
            }
            break;
        
        // ===== 3. GET COMPLETE HISTORY FOR A PRODUCT =====
        case 'get_product_history':
            $id = $_POST['id'] ?? 0;
            
            $stmt = $pdo->prepare("
                SELECT 
                    h.*,
                    CONCAT(u.first_name, ' ', u.last_name) as user_name,
                    u.role as user_role,
                    u.email as user_email
                FROM inventory_history h
                LEFT JOIN users u ON h.user_id = u.id
                WHERE h.product_id = ?
                ORDER BY h.created_at DESC
            ");
            $stmt->execute([$id]);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'data' => $history]);
            break;
        
        // ===== 4. GET ALL USER SESSIONS (logins/logouts/switches) =====
        case 'get_user_sessions':
            $days = $_POST['days'] ?? 7; // Last 7 days by default
            
            $stmt = $pdo->prepare("
                SELECT 
                    ls.*,
                    CONCAT(u.first_name, ' ', u.last_name) as user_name,
                    u.email as user_email,
                    u.role as user_role
                FROM login_sessions ls
                LEFT JOIN users u ON ls.user_id = u.id
                WHERE ls.login_time >= DATE_SUB(NOW(), INTERVAL ? DAY)
                ORDER BY ls.login_time DESC
            ");
            $stmt->execute([$days]);
            $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'data' => $sessions]);
            break;
        
        // ===== 5. GET ACTIVITY LOG WITH FILTERS =====
        case 'get_filtered_activity':
            $action_filter = $_POST['action'] ?? '';
            $user_filter = $_POST['user_id'] ?? '';
            $days = $_POST['days'] ?? 30;
            
            $sql = "
                SELECT 
                    h.*,
                    CONCAT(u.first_name, ' ', u.last_name) as user_name,
                    u.email as user_email,
                    u.role as user_role,
                    COALESCE(h.product_name, p.name, 'Unknown Product') as product_name
                FROM inventory_history h
                LEFT JOIN users u ON h.user_id = u.id
                LEFT JOIN products p ON h.product_id = p.id
                WHERE h.created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
            ";
            
            $params = [':days' => $days];
            
            if (!empty($action_filter)) {
                $sql .= " AND h.action = :action";
                $params[':action'] = $action_filter;
            }
            
            if (!empty($user_filter)) {
                $sql .= " AND h.user_id = :user_id";
                $params[':user_id'] = $user_filter;
            }
            
            $sql .= " ORDER BY h.created_at DESC LIMIT 500";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'data' => $activities]);
            break;
        
        // ===== 6. GET STATISTICS (who did what count) =====
        case 'get_user_stats':
            // Count actions by user
            $stmt = $pdo->query("
                SELECT 
                    CONCAT(u.first_name, ' ', u.last_name) as user_name,
                    u.role,
                    COUNT(h.id) as total_actions,
                    SUM(CASE WHEN h.action = 'add' THEN 1 ELSE 0 END) as adds,
                    SUM(CASE WHEN h.action = 'edit' THEN 1 ELSE 0 END) as edits,
                    SUM(CASE WHEN h.action = 'delete' THEN 1 ELSE 0 END) as deletes,
                    SUM(CASE WHEN h.action = 'archive' THEN 1 ELSE 0 END) as archives,
                    SUM(CASE WHEN h.action = 'restore' THEN 1 ELSE 0 END) as restores
                FROM users u
                LEFT JOIN inventory_history h ON u.id = h.user_id
                GROUP BY u.id
                ORDER BY total_actions DESC
                LIMIT 20
            ");
            $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'data' => $stats]);
            break;
        
        // ===== 7. GET ACCOUNT SWITCH HISTORY =====
        case 'get_account_switches':
            $stmt = $pdo->query("
                SELECT 
                    ls.*,
                    CONCAT(u.first_name, ' ', u.last_name) as user_name,
                    u.email,
                    u.role
                FROM login_sessions ls
                LEFT JOIN users u ON ls.user_id = u.id
                WHERE ls.action = 'switch'
                ORDER BY ls.login_time DESC
                LIMIT 100
            ");
            $switches = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'data' => $switches]);
            break;
        
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    
} catch (PDOException $e) {
    error_log("Inventory AJAX Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
?>
