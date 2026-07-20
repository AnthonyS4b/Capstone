<?php
// controllers/TransactionController.php
require_once __DIR__ . '/../config/database.php';

class TransactionController
{
    private $conn;

    public function __construct()
    {
        $this->conn = getLegacyDBConnection();
    }

    /**
     * Log activity to inventory_history table
     */
    private function logActivity($productId, $action, $changes, $userId = null, $productName = null)
    {
        try {
            if (!$userId) {
                if (session_status() === PHP_SESSION_NONE) {
                    session_start();
                }
                $userId = $_SESSION['user_id'] ?? null;
            }

            // If name not provided, try to fetch it
            if (!$productName && $productId) {
                $pStmt = $this->conn->prepare("SELECT name FROM products WHERE id = ?");
                $pStmt->bind_param("i", $productId);
                $pStmt->execute();
                $pResult = $pStmt->get_result();
                $pData = $pResult->fetch_assoc();
                $productName = $pData['name'] ?? "Unknown Product";
                $pStmt->close();
            }

            $sql = "INSERT INTO inventory_history (product_id, product_name, user_id, action, changes, created_at) 
                    VALUES (?, ?, ?, ?, ?, NOW())";
            $stmt = $this->conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("isiss", $productId, $productName, $userId, $action, $changes);
                $stmt->execute();
                $stmt->close();
            }
            return true;
        } catch (Exception $e) {
            error_log("Error in logActivity (TransactionController): " . $e->getMessage());
            return false;
        }
    }

    // Get transactions with pagination and filters
    public function getTransactions($page = 1, $limit = 100, $filters = [])
    {
        try {
            $offset = ($page - 1) * $limit;

            // Using 'sales' table for display (it has more complete data)
            $sql = "SELECT s.*, u.first_name, u.last_name 
                    FROM sales s 
                    LEFT JOIN users u ON s.user_id = u.id 
                    WHERE 1=1";
            $countSql = "SELECT COUNT(*) as total FROM sales s WHERE 1=1";
            $params = [];
            $types = "";

            // Apply filters
            if (!empty($filters['search'])) {
                $sql .= " AND s.id LIKE ?";
                $countSql .= " AND s.id LIKE ?";
                $searchTerm = "%" . $filters['search'] . "%";
                $params[] = $searchTerm;
                $types .= "s";
            }

            if (!empty($filters['date_from'])) {
                $sql .= " AND DATE(s.created_at) >= ?";
                $countSql .= " AND DATE(s.created_at) >= ?";
                $params[] = $filters['date_from'];
                $types .= "s";
            }

            if (!empty($filters['date_to'])) {
                $sql .= " AND DATE(s.created_at) <= ?";
                $countSql .= " AND DATE(s.created_at) <= ?";
                $params[] = $filters['date_to'];
                $types .= "s";
            }

            if (!empty($filters['status']) && $filters['status'] !== 'all') {
                $sql .= " AND s.status = ?";
                $countSql .= " AND s.status = ?";
                $params[] = $filters['status'];
                $types .= "s";
            }

            $sql .= " ORDER BY s.created_at DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
            $types .= "ii";

            // Get total count
            $stmt = $this->conn->prepare($countSql);
            if (!empty($params)) {
                $countParams = array_slice($params, 0, count($params) - 2);
                $countTypes = substr($types, 0, -2);
                if (!empty($countParams)) {
                    $stmt->bind_param($countTypes, ...$countParams);
                }
            }
            $stmt->execute();
            $result = $stmt->get_result();
            $total = $result->fetch_assoc()['total'];

            // Get transactions
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();

            $transactions = [];
            while ($row = $result->fetch_assoc()) {
                // Get items for this transaction from transaction_items table
                $itemsStmt = $this->conn->prepare("SELECT * FROM transaction_items WHERE transaction_id = ?");
                $itemsStmt->bind_param("i", $row['id']);
                $itemsStmt->execute();
                $itemsResult = $itemsStmt->get_result();

                $items = [];
                while ($item = $itemsResult->fetch_assoc()) {
                    $items[] = $item;
                }

                $row['items'] = $items;
                $row['cashier_name'] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                if (empty($row['cashier_name'])) {
                    $row['cashier_name'] = 'Unknown';
                }

                $transactions[] = $row;
            }

            return [
                'success' => true,
                'data' => $transactions,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => ceil($total / $limit)
            ];

        } catch (Exception $e) {
            error_log("getTransactions error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }

    // Get single transaction
    public function getTransaction($id)
    {
        try {
            $stmt = $this->conn->prepare("SELECT s.*, u.first_name, u.last_name 
                                          FROM sales s 
                                          LEFT JOIN users u ON s.user_id = u.id 
                                          WHERE s.id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $transaction = $result->fetch_assoc();
                $transaction['cashier_name'] = trim(($transaction['first_name'] ?? '') . ' ' . ($transaction['last_name'] ?? ''));
                if (empty($transaction['cashier_name'])) {
                    $transaction['cashier_name'] = 'Unknown';
                }

                // Get items from transaction_items table
                $itemsStmt = $this->conn->prepare("SELECT * FROM transaction_items WHERE transaction_id = ?");
                $itemsStmt->bind_param("i", $id);
                $itemsStmt->execute();
                $itemsResult = $itemsStmt->get_result();

                $items = [];
                while ($item = $itemsResult->fetch_assoc()) {
                    $items[] = $item;
                }

                $transaction['items'] = $items;

                return ['success' => true, 'data' => $transaction];
            }

            return ['success' => false, 'message' => 'Transaction not found'];

        } catch (Exception $e) {
            error_log("getTransaction error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }

    // Save new transaction - FIXED VERSION WITH TRIGGER SUPPORT
    public function saveTransaction($data)
    {
        try {
            error_log("========== SAVE TRANSACTION START ==========");
            error_log("Data received in controller: " . print_r($data, true));

            // Make sure session is started
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }

            error_log("Session data in controller: " . print_r($_SESSION, true));

            // Get user_id from multiple sources
            $user_id = null;

            // First try from data (sent from JavaScript)
            if (isset($data['user_id']) && !empty($data['user_id'])) {
                $user_id = intval($data['user_id']);
                error_log("Using user_id from data: " . $user_id);
            }
            // Then try from session
            elseif (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
                $user_id = intval($_SESSION['user_id']);
                error_log("Using user_id from session: " . $user_id);
            }

            // If still no user_id, use a default (from your users table)
            if (empty($user_id)) {
                // From your login page, John Anthony has ID 4
                $user_id = 4;
                error_log("WARNING: Using default user_id: " . $user_id);
            }

            error_log("FINAL user_id being used: " . $user_id);

            // SERVER-SIDE: Validate GCash reference uniqueness using dedicated column
            if (!empty($data['payment_method']) && $data['payment_method'] === 'gcash' && !empty($data['gcash_reference'])) {
                $gcashRef = trim($data['gcash_reference']);
                $refCheck = $this->conn->prepare(
                    "SELECT id FROM sales WHERE gcash_reference = ? AND status = 'completed' LIMIT 1"
                );
                if ($refCheck) {
                    $refCheck->bind_param("s", $gcashRef);
                    $refCheck->execute();
                    $refCheck->store_result();
                    if ($refCheck->num_rows > 0) {
                        $refCheck->close();
                        return [
                            'success' => false,
                            'message' => 'GCash reference number has already been used. Please use a unique reference number.'
                        ];
                    }
                    $refCheck->close();
                }
            }

            $this->conn->begin_transaction();

            // Validate required data
            if (!isset($data['items']) || !is_array($data['items']) || count($data['items']) === 0) {
                throw new Exception('No items in transaction');
            }

            // FIRST: Insert into transactions table
            $transSql = "INSERT INTO transactions (
                    user_id, 
                    total_amount, 
                    payment_method, 
                    created_at
                ) VALUES (?, ?, ?, NOW())";

            $transStmt = $this->conn->prepare($transSql);
            if (!$transStmt) {
                throw new Exception('Failed to prepare transactions statement: ' . $this->conn->error);
            }

            $transStmt->bind_param(
                "ids",
                $user_id,
                $data['total'],
                $data['payment_method']
            );

            if (!$transStmt->execute()) {
                error_log("MySQL Error: " . $this->conn->error);
                throw new Exception('Failed to save to transactions table: ' . $this->conn->error);
            }

            $transaction_id = $this->conn->insert_id;
            error_log("✅ Transaction inserted with ID: " . $transaction_id);

            // SECOND: Insert into sales table
            $salesSql = "INSERT INTO sales (
                    id,
                    user_id, 
                    total, 
                    amount_paid, 
                    change_amount, 
                    payment_method, 
                    notes,
                    gcash_reference,
                    status,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'completed', NOW())";

            $salesStmt = $this->conn->prepare($salesSql);
            if (!$salesStmt) {
                throw new Exception('Failed to prepare sales statement: ' . $this->conn->error);
            }

            $gcashReference = !empty($data['gcash_reference']) ? trim($data['gcash_reference']) : null;

            $salesStmt->bind_param(
                "iidddsss",
                $transaction_id,
                $user_id,
                $data['total'],
                $data['payment'],
                $data['change'],
                $data['payment_method'],
                $data['notes'],
                $gcashReference
            );

            if (!$salesStmt->execute()) {
                error_log("❌ Sales insert error: " . $this->conn->error);
                throw new Exception('Failed to save to sales table: ' . $this->conn->error);
            }

            error_log("✅ Sales record inserted for transaction ID: " . $transaction_id);

            // THIRD: Insert items
            foreach ($data['items'] as $item) {
                if (!isset($item['id']) || !isset($item['quantity']) || !isset($item['price'])) {
                    throw new Exception('Invalid item data');
                }

                $itemSql = "INSERT INTO transaction_items (
                    transaction_id, 
                    product_id, 
                    quantity, 
                    price
                ) VALUES (?, ?, ?, ?)";

                $itemStmt = $this->conn->prepare($itemSql);
                if (!$itemStmt) {
                    throw new Exception('Failed to prepare item statement: ' . $this->conn->error);
                }

                $itemStmt->bind_param(
                    "iiid",
                    $transaction_id,
                    $item['id'],
                    $item['quantity'],
                    $item['price']
                );

                if (!$itemStmt->execute()) {
                    error_log("❌ Item insert error: " . $this->conn->error);
                    throw new Exception('Failed to save transaction item: ' . $this->conn->error);
                }

                error_log("  Applying FIFO batch deduction for product ID: " . $item['id']);
                $qtyToDeduct = $item['quantity'];
                
                // Get active batches (FIFO order)
                $batchSql = "SELECT id, stock FROM product_batches WHERE product_id = ? AND stock > 0 AND status = 'active' ORDER BY CASE WHEN expiration_date IS NULL THEN 1 ELSE 0 END, expiration_date ASC, date_added ASC FOR UPDATE";
                $batchStmt = $this->conn->prepare($batchSql);
                if (!$batchStmt) {
                    error_log("❌ Failed to prepare batch select: " . $this->conn->error);
                    throw new Exception('Failed to prepare batch deduction query: ' . $this->conn->error);
                }
                $batchStmt->bind_param("i", $item['id']);
                $batchStmt->execute();
                $batches = $batchStmt->get_result();
                
                while ($batch = $batches->fetch_assoc()) {
                    if ($qtyToDeduct <= 0) break;
                    
                    $deduct = min($batch['stock'], $qtyToDeduct);
                    
                    // Deduct from batch
                    $updBatch = $this->conn->prepare("UPDATE product_batches SET stock = stock - ? WHERE id = ?");
                    if (!$updBatch) {
                        error_log("❌ Failed to prepare batch update: " . $this->conn->error);
                        throw new Exception('Failed to prepare batch update: ' . $this->conn->error);
                    }
                    $updBatch->bind_param("ii", $deduct, $batch['id']);
                    if (!$updBatch->execute()) {
                        error_log("❌ Failed to execute batch update: " . $updBatch->error);
                        throw new Exception('Failed to execute batch update: ' . $updBatch->error);
                    }
                    error_log("  ✅ Deducted $deduct from batch ID: " . $batch['id']);
                    
                    $qtyToDeduct -= $deduct;
                }
                
                if ($qtyToDeduct > 0) {
                    throw new Exception('Not enough stock in product batches for ID: ' . $item['id']);
                }
                
                // Mark empty batches as depleted
                $depleteStmt = $this->conn->prepare("UPDATE product_batches SET status = 'depleted' WHERE product_id = ? AND stock = 0 AND status != 'depleted'");
                if ($depleteStmt) {
                    $depleteStmt->bind_param("i", $item['id']);
                    $depleteStmt->execute();
                    error_log("  ✅ Marked " . $depleteStmt->affected_rows . " batch(es) as depleted");
                }
                
                // Sync product expiration_date and date_added to the oldest remaining active batch
                $syncSql = "SELECT date_added, expiration_date FROM product_batches WHERE product_id = ? AND stock > 0 AND status = 'active' ORDER BY CASE WHEN expiration_date IS NULL THEN 1 ELSE 0 END, expiration_date ASC, date_added ASC LIMIT 1";
                $syncStmt = $this->conn->prepare($syncSql);
                if ($syncStmt) {
                    $syncStmt->bind_param("i", $item['id']);
                    $syncStmt->execute();
                    $nextBatch = $syncStmt->get_result()->fetch_assoc();
                    if ($nextBatch) {
                        $updProduct = $this->conn->prepare("UPDATE products SET expiration_date = ?, date_added = ? WHERE id = ?");
                        $updProduct->bind_param("ssi", $nextBatch['expiration_date'], $nextBatch['date_added'], $item['id']);
                        $updProduct->execute();
                        error_log("  ✅ Synced product info to next active batch (date_added: " . $nextBatch['date_added'] . ", exp: " . $nextBatch['expiration_date'] . ")");
                    }
                }

                // Update stock - FIXED: Added updated_by for the trigger
                error_log("  Updating stock for product ID: " . $item['id'] . " with user_id: " . $user_id);

                // IMPORTANT: Include updated_by for the after_product_update trigger
                $updateSql = "UPDATE products SET stock = stock - ?, updated_by = ? WHERE id = ? AND stock >= ?";
                $updateStmt = $this->conn->prepare($updateSql);
                if (!$updateStmt) {
                    throw new Exception('Failed to prepare stock update: ' . $this->conn->error);
                }

                $updateStmt->bind_param(
                    "iiii",
                    $item['quantity'],
                    $user_id,  // THIS IS THE FIX - provides user_id for the trigger
                    $item['id'],
                    $item['quantity']
                );

                if (!$updateStmt->execute()) {
                    error_log("❌ Stock update error: " . $this->conn->error);
                    throw new Exception('Failed to update stock: ' . $this->conn->error);
                }

                if ($updateStmt->affected_rows === 0) {
                    throw new Exception('Insufficient stock for product ID: ' . $item['id']);
                }

                error_log("  ✅ Stock updated successfully");
            }

            $this->conn->commit();
            error_log("✅ Transaction committed successfully for ID: " . $transaction_id);
            error_log("========== SAVE TRANSACTION END ==========");

            $transaction_number = 'TRX-' . str_pad($transaction_id, 6, '0', STR_PAD_LEFT);

            return [
                'success' => true,
                'message' => 'Transaction saved successfully',
                'data' => [
                    'id' => $transaction_id,
                    'transaction_number' => $transaction_number
                ]
            ];

        } catch (Exception $e) {
            if (isset($this->conn)) {
                $this->conn->rollback();
                error_log("❌ Transaction rolled back due to error");
            }
            error_log("❌ saveTransaction error: " . $e->getMessage());
            error_log("========== SAVE TRANSACTION ERROR ==========");
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // Void transaction
    public function voidTransaction($id, $voided_by)
    {
        try {
            $this->conn->begin_transaction();

            // Check if transaction exists in sales table
            $checkStmt = $this->conn->prepare("SELECT status FROM sales WHERE id = ?");
            $checkStmt->bind_param("i", $id);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            $transaction = $result->fetch_assoc();

            if (!$transaction) {
                throw new Exception('Transaction not found');
            }

            if ($transaction['status'] !== 'completed') {
                throw new Exception('Only completed transactions can be voided');
            }

            // Update transaction status in sales table
            $stmt = $this->conn->prepare("UPDATE sales SET status = 'voided' WHERE id = ?");
            $stmt->bind_param("i", $id);

            if (!$stmt->execute()) {
                throw new Exception('Failed to void transaction in sales: ' . $this->conn->error);
            }

            // Also update transactions table
            $transStmt = $this->conn->prepare("UPDATE transactions SET status = 'voided' WHERE id = ?");
            $transStmt->bind_param("i", $id);
            $transStmt->execute();

            // Get items to restore stock
            $itemsStmt = $this->conn->prepare("
                SELECT ti.*, p.name as product_name 
                FROM transaction_items ti
                LEFT JOIN products p ON ti.product_id = p.id
                WHERE ti.transaction_id = ?
            ");
            $itemsStmt->bind_param("i", $id);
            $itemsStmt->execute();
            $itemsResult = $itemsStmt->get_result();

            // Restore stock for each item - FIXED: Add updated_by for trigger
            while ($item = $itemsResult->fetch_assoc()) {
                if (!empty($item['product_id'])) {
                    // Restore to the newest batch
                    $checkBatch = $this->conn->prepare("SELECT id FROM product_batches WHERE product_id = ? ORDER BY id DESC LIMIT 1");
                    $checkBatch->bind_param("i", $item['product_id']);
                    $checkBatch->execute();
                    $bRes = $checkBatch->get_result();
                    if ($bRow = $bRes->fetch_assoc()) {
                        $updB = $this->conn->prepare("UPDATE product_batches SET stock = stock + ?, status = 'active' WHERE id = ?");
                        $updB->bind_param("ii", $item['quantity'], $bRow['id']);
                        $updB->execute();
                    } else {
                        $bno = "B-VOID-" . time() . "-" . $item['product_id'];
                        $bdate = date('Y-m-d');
                        $insB = $this->conn->prepare("INSERT INTO product_batches (product_id, batch_no, stock, date_added) VALUES (?, ?, ?, ?)");
                        $insB->bind_param("isis", $item['product_id'], $bno, $item['quantity'], $bdate);
                        $insB->execute();
                    }

                    $updateStmt = $this->conn->prepare("UPDATE products SET stock = stock + ?, updated_by = ? WHERE id = ?");
                    $updateStmt->bind_param("iii", $item['quantity'], $voided_by, $item['product_id']);

                    if (!$updateStmt->execute()) {
                        throw new Exception('Failed to restore stock: ' . $this->conn->error);
                    }
                    
                    // LOG ACTIVITY FOR EACH VOIDED ITEM
                    $this->logActivity(
                        $item['product_id'], 
                        'void', 
                        "Stock restored (+{$item['quantity']}) - Transaction #{$id} voided",
                        $voided_by,
                        $item['product_name']
                    );
                }
            }

            $this->conn->commit();

            return ['success' => true, 'message' => 'Transaction voided successfully'];

        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("voidTransaction error: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // Get statistics
    public function getStats()
    {
        try {
            $stats = [];

            // Total transactions from sales table
            $result = $this->conn->query("SELECT COUNT(*) as count FROM sales WHERE status = 'completed'");
            $stats['total_transactions'] = $result->fetch_assoc()['count'];

            // Total sales
            $result = $this->conn->query("SELECT COALESCE(SUM(total), 0) as total FROM sales WHERE status = 'completed'");
            $stats['total_sales'] = $result->fetch_assoc()['total'];

            // Today's sales
            $result = $this->conn->query("SELECT COALESCE(SUM(total), 0) as total FROM sales WHERE status = 'completed' AND DATE(created_at) = CURDATE()");
            $stats['today_sales'] = $result->fetch_assoc()['total'];

            // Average transaction
            $result = $this->conn->query("SELECT COALESCE(AVG(total), 0) as avg FROM sales WHERE status = 'completed'");
            $stats['avg_transaction'] = $result->fetch_assoc()['avg'];

            return ['success' => true, 'data' => $stats];

        } catch (Exception $e) {
            error_log("getStats error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
}