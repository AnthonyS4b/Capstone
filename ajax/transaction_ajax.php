<?php
// ajax/transaction_ajax.php

ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../error.log');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

header('Content-Type: application/json');

require_once dirname(__DIR__) . '/includes/security.php';
require_once dirname(__DIR__) . '/includes/promotion_pricing.php';
security_require_login();
security_require_post_csrf();

try {
    require_once dirname(__DIR__) . '/config/database.php';

    if (!function_exists('getDBConnection')) {
        throw new Exception('Database connection function not found');
    }

    $pdo = getDBConnection();

    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    if (empty($action)) {
        throw new Exception('No action specified');
    }

    switch ($action) {
        case 'save_transaction':
            // Get user_id from multiple sources
            $user_id = null;
            
            // Source 1: Check session
            if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
                $user_id = (int)$_SESSION['user_id'];
                error_log("Found user_id in session: $user_id");
            } else {
                // No valid session — reject the request
                // SECURITY: Never accept user_id from POST or auto-assign from DB
                error_log("CRITICAL: No user_id in session. Rejecting transaction.");
                echo json_encode([
                    'success' => false,
                    'message' => 'Session expired. Please log in again.'
                ]);
                exit;
            }
            
            // Final validation
            if ($user_id <= 0) {
                error_log("CRITICAL: Invalid user_id after all attempts: $user_id");
                echo json_encode([
                    'success' => false,
                    'message' => 'Invalid user ID. Please log in again.'
                ]);
                exit;
            }
            
            // Verify user exists and is active
            $verifyStmt = $pdo->prepare("
                SELECT id, first_name, last_name, role, is_active 
                FROM users 
                WHERE id = ? AND is_active = 1
            ");
            $verifyStmt->execute([$user_id]);
            $user = $verifyStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                error_log("User ID $user_id not found or inactive in database!");
                echo json_encode([
                    'success' => false,
                    'message' => 'User not found or inactive. Please log in again.'
                ]);
                exit;
            }
            
            error_log("Validated user: " . $user['first_name'] . " " . $user['last_name'] . " (ID: $user_id, Role: " . $user['role'] . ")");
            
            // Get items
            $items = isset($_POST['items']) ? json_decode($_POST['items'], true) : [];
            if (empty($items)) {
                echo json_encode(['success' => false, 'message' => 'No items in transaction']);
                exit;
            }
            
            // Get transaction data
            $total = isset($_POST['total']) ? (float)$_POST['total'] : 0;
            $payment = isset($_POST['payment']) ? (float)$_POST['payment'] : 0;
            $change = isset($_POST['change']) ? (float)$_POST['change'] : 0;
            $payment_method = isset($_POST['payment_method']) ? $_POST['payment_method'] : 'cash';
            $cashier = isset($_POST['cashier']) ? $_POST['cashier'] : $user['first_name'] . ' ' . $user['last_name'];
            $cashier_role = isset($_POST['cashier_role']) ? $_POST['cashier_role'] : $user['role'];
            $notes = isset($_POST['notes']) ? $_POST['notes'] : '';
            $gcash_reference = isset($_POST['gcash_reference']) ? trim($_POST['gcash_reference']) : null;
            
            error_log("Transaction data: User_ID=$user_id, Total=$total, Payment=$payment, Method=$payment_method");

            // SERVER-SIDE: Check GCash reference uniqueness BEFORE starting transaction
            if ($payment_method === 'gcash' && !empty($gcash_reference)) {
                // Validate format: exactly 6 digits only
                if (!preg_match('/^\d{6}$/', $gcash_reference)) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Invalid GCash reference. Must be exactly 6 digits.'
                    ]);
                    exit;
                }
                // Check if already used in DB
                $refCheck = $pdo->prepare("SELECT id FROM sales WHERE gcash_reference = ? AND status = 'completed' LIMIT 1");
                $refCheck->execute([$gcash_reference]);
                if ($refCheck->fetch()) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'GCash reference number has already been used. Please use a unique reference number.'
                    ]);
                    exit;
                }
            }
            
            // Start database transaction
            $pdo->beginTransaction();
            
            try {
                // Never trust browser-submitted names, prices, totals, or change.
                // Lock and reload every product so stock and pricing are authoritative.
                if (!in_array($payment_method, ['cash', 'gcash'], true)) {
                    throw new Exception('Unsupported payment method');
                }

                $productStmt = $pdo->prepare("
                    SELECT
                        p.id, p.name, p.price, p.stock,
                        sh.strategy_id, sh.paired_product_id, sh.discounted_price,
                        CASE WHEN sh.strategy_id = 'cross_sell_pairing' THEN p.price
                             ELSE COALESCE(sh.discounted_price, p.price) END AS effective_price
                    FROM products p
                    LEFT JOIN strategy_history sh ON sh.id = (
                        SELECT latest.id FROM strategy_history latest
                        WHERE latest.product_id = p.id AND latest.status = 'applied'
                          AND (latest.ended_at IS NULL OR latest.ended_at > NOW())
                        ORDER BY latest.id DESC LIMIT 1
                    )
                    WHERE p.id = ?
                      AND p.deleted_at IS NULL
                      AND p.status = 'active'
                      AND (p.expiration_date IS NULL OR p.expiration_date >= CURDATE())
                    FOR UPDATE
                ");

                // Combine duplicate product lines before checking stock or pairing quantities.
                $cartQuantities = [];
                foreach ($items as $submittedItem) {
                    $id = filter_var($submittedItem['id'] ?? null, FILTER_VALIDATE_INT);
                    $qty = filter_var($submittedItem['quantity'] ?? null, FILTER_VALIDATE_INT);
                    if (!$id || $id <= 0 || !$qty || $qty <= 0) {
                        throw new Exception('Invalid product or quantity in the cart');
                    }
                    $cartQuantities[$id] = ($cartQuantities[$id] ?? 0) + $qty;
                }
                ksort($cartQuantities);
                $items = [];
                foreach ($cartQuantities as $id => $qty) {
                    $items[] = ['id' => $id, 'quantity' => $qty];
                }

                $validatedItems = [];
                $calculatedTotal = 0.0;
                foreach ($items as $submittedItem) {
                    $productId = filter_var($submittedItem['id'] ?? null, FILTER_VALIDATE_INT);
                    $quantity = filter_var($submittedItem['quantity'] ?? null, FILTER_VALIDATE_INT);
                    if (!$productId || !$quantity || $quantity <= 0) {
                        throw new Exception('Invalid product or quantity in the cart');
                    }

                    $productStmt->execute([$productId]);
                    $product = $productStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$product) {
                        throw new Exception('A cart product is unavailable or expired');
                    }
                    if ((int)$product['stock'] < $quantity) {
                        throw new Exception('Insufficient stock for product: ' . $product['name']);
                    }

                    $unitPrice = round((float)$product['effective_price'], 2);
                    if ($unitPrice < 0) {
                        throw new Exception('Invalid database price for product: ' . $product['name']);
                    }

                    $validatedItems[] = [
                        'id' => (int)$product['id'],
                        'name' => $product['name'],
                        'price' => $unitPrice,
                        'quantity' => $quantity,
                        'strategy_id' => $product['strategy_id'],
                        'paired_product_id' => $product['paired_product_id'],
                        'discounted_price' => $product['discounted_price'],
                    ];
                    $calculatedTotal += $unitPrice * $quantity;
                }

                $items = price_promotion_items($validatedItems);
                $calculatedTotal = array_sum(array_map(function ($item) {
                    return $item['price'] * $item['quantity'];
                }, $items));
                $total = round($calculatedTotal, 2);
                if ($total <= 0) {
                    throw new Exception('Transaction total must be greater than zero');
                }

                if ($payment_method === 'cash') {
                    if ($payment + 0.00001 < $total) {
                        throw new Exception('Insufficient cash payment');
                    }
                    $change = round($payment - $total, 2);
                } else {
                    $payment = $total;
                    $change = 0.0;
                    if (empty($gcash_reference)) {
                        throw new Exception('GCash reference is required');
                    }
                }

                $cashier = $user['first_name'] . ' ' . $user['last_name'];
                $cashier_role = $user['role'];

                // 1. Insert into transactions table with EXPLICIT user_id
                $stmt = $pdo->prepare("
                    INSERT INTO transactions (
                        user_id, 
                        items, 
                        total_amount, 
                        payment_method, 
                        created_at
                    ) VALUES (
                        :user_id, 
                        :items, 
                        :total, 
                        :payment_method, 
                        NOW()
                    )
                ");
                
                $result = $stmt->execute([
                    ':user_id' => $user_id,
                    ':items' => json_encode($items),
                    ':total' => $total,
                    ':payment_method' => $payment_method
                ]);
                
                if (!$result) {
                    throw new Exception("Failed to insert transaction: " . implode(", ", $stmt->errorInfo()));
                }
                
                $transaction_id = $pdo->lastInsertId();
                error_log("Transaction inserted with ID: $transaction_id");
                
                // 2. Insert into transaction_items table
                $itemStmt = $pdo->prepare("
                    INSERT INTO transaction_items (
                        transaction_id, 
                        product_id, 
                        quantity, 
                        price
                    ) VALUES (
                        :transaction_id, 
                        :product_id, 
                        :quantity, 
                        :price
                    )
                ");
                
                foreach ($items as $item) {
                    $itemStmt->execute([
                        ':transaction_id' => $transaction_id,
                        ':product_id' => $item['id'],
                        ':quantity' => $item['quantity'],
                        ':price' => $item['price']
                    ]);
                }
                
                // 3. Update product stock
                $stockStmt = $pdo->prepare("
                    UPDATE products 
                    SET stock = stock - :quantity,
                        updated_by = :user_id,
                        updated_at = NOW()
                    WHERE id = :product_id AND stock >= :quantity
                ");
                
                foreach ($items as $item) {
                    $stockStmt->execute([
                        ':quantity' => $item['quantity'],
                        ':product_id' => $item['id'],
                        ':user_id' => $user_id
                    ]);
                    
                    if ($stockStmt->rowCount() == 0) {
                        throw new Exception("Insufficient stock for product: " . $item['name']);
                    }
                    
                    // ── FIFO Batch Deduction ───────────────────────────────
                    $qtyToDeduct = (int)$item['quantity'];
                    
                    $batchSelStmt = $pdo->prepare("
                        SELECT id, stock FROM product_batches 
                        WHERE product_id = :pid AND stock > 0 AND status = 'active' 
                        ORDER BY CASE WHEN expiration_date IS NULL THEN 1 ELSE 0 END, expiration_date ASC, date_added ASC
                    ");
                    $batchSelStmt->execute([':pid' => $item['id']]);
                    $batches = $batchSelStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($batches as $batch) {
                        if ($qtyToDeduct <= 0) break;
                        
                        $deduct = min((int)$batch['stock'], $qtyToDeduct);
                        
                        $batchUpdStmt = $pdo->prepare("UPDATE product_batches SET stock = stock - :deduct WHERE id = :bid");
                        $batchUpdStmt->execute([':deduct' => $deduct, ':bid' => $batch['id']]);
                        
                        $qtyToDeduct -= $deduct;
                    }
                    
                    // Mark zero-stock batches as depleted
                    $deplStmt = $pdo->prepare("UPDATE product_batches SET status = 'depleted' WHERE product_id = :pid AND stock = 0 AND status = 'active'");
                    $deplStmt->execute([':pid' => $item['id']]);
                    
                    // Sync product info to the oldest remaining active batch
                    $syncStmt = $pdo->prepare("
                        SELECT date_added, expiration_date FROM product_batches 
                        WHERE product_id = :pid AND stock > 0 AND status = 'active' 
                        ORDER BY CASE WHEN expiration_date IS NULL THEN 1 ELSE 0 END, expiration_date ASC, date_added ASC LIMIT 1
                    ");
                    $syncStmt->execute([':pid' => $item['id']]);
                    $nextBatch = $syncStmt->fetch(PDO::FETCH_ASSOC);
                    if ($nextBatch) {
                        $syncProd = $pdo->prepare("UPDATE products SET expiration_date = :exp, date_added = :da WHERE id = :pid");
                        $syncProd->execute([
                            ':exp' => $nextBatch['expiration_date'],
                            ':da'  => $nextBatch['date_added'],
                            ':pid' => $item['id']
                        ]);
                    }
                    // ── End FIFO ───────────────────────────────────────────
                }
                
                // 4. Insert into sales table
                $salesStmt = $pdo->prepare("
                    INSERT INTO sales (
                        transaction_id,
                        user_id,
                        total,
                        amount_paid,
                        change_amount,
                        payment_method,
                        notes,
                        gcash_reference,
                        cashier_name,
                        cashier_role,
                        status,
                        created_at
                    ) VALUES (
                        :transaction_id,
                        :user_id,
                        :total,
                        :amount_paid,
                        :change_amount,
                        :payment_method,
                        :notes,
                        :gcash_reference,
                        :cashier_name,
                        :cashier_role,
                        'completed',
                        NOW()
                    )
                ");
                
                $salesResult = $salesStmt->execute([
                    ':transaction_id' => $transaction_id,
                    ':user_id'        => $user_id,
                    ':total'          => $total,
                    ':amount_paid'    => $payment,
                    ':change_amount'  => $change,
                    ':payment_method' => $payment_method,
                    ':notes'          => $notes,
                    ':gcash_reference' => !empty($gcash_reference) ? $gcash_reference : null,
                    ':cashier_name'   => $cashier,
                    ':cashier_role'   => $cashier_role
                ]);
                
                if (!$salesResult) {
                    throw new Exception("Failed to insert sales record: " . implode(", ", $salesStmt->errorInfo()));
                }
                
                // Commit transaction
                $pdo->commit();
                
                error_log("=== TRANSACTION COMPLETED SUCCESSFULLY ===");
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Transaction completed successfully',
                    'data' => [
                        'id' => $transaction_id,
                        'transaction_number' => 'TRX-' . str_pad($transaction_id, 6, '0', STR_PAD_LEFT),
                        'items' => $items,
                        'total' => $total,
                        'payment' => $payment,
                        'change' => $change,
                        'payment_method' => $payment_method
                    ]
                ]);
                
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("TRANSACTION ERROR: " . $e->getMessage());
                error_log("Stack trace: " . $e->getTraceAsString());
                echo json_encode([
                    'success' => false,
                    'message' => 'Transaction failed: ' . $e->getMessage()
                ]);
            }
            break;
            
        case 'get_transactions':
            $page   = isset($_POST['page'])   ? max(1, (int)$_POST['page'])   : 1;
            $limit  = isset($_POST['limit'])  ? min(10000, max(1, (int)$_POST['limit'])) : 50;
            $offset = ($page - 1) * $limit;
            $search    = trim((string)($_POST['search'] ?? ''));
            $is_date   = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
            $date_from = $is_date($_POST['date_from'] ?? null) ? $_POST['date_from'] : null;
            $date_to   = $is_date($_POST['date_to'] ?? null)   ? $_POST['date_to']   : null;
            $status    = in_array($_POST['status'] ?? '', ['completed', 'voided'], true) ? $_POST['status'] : null;

            $where  = [];
            $params = [];

            if (($_SESSION['role'] ?? '') !== 'owner') {
                $where[] = 't.user_id = :session_user_id';
                $params[':session_user_id'] = (int)$_SESSION['user_id'];
            }

            // IDs are shown as TRX-000123, so accept that form, a bare number, or part of either.
            // A half-typed "TRX-" prefix on its own filters nothing yet.
            if ($search !== '' && !preg_match('/^t(r(x-?)?)?$/i', $search)) {
                $like = '%' . addcslashes($search, '%_\\') . '%';
                $or = [];
                if (preg_match('/^(?:trx-?)?(\d+)$/i', $search, $m)) {
                    // Digits: the transaction number, and from 6 digits up also a GCash
                    // reference (6 digits at the POS, 10 on older sales). Shorter numbers
                    // stay ID-only so "15" does not pull in every reference containing 15.
                    $or[] = "LPAD(t.id, 6, '0') LIKE :search_id";
                    $params[':search_id'] = '%' . $m[1] . '%';
                    if (strlen($m[1]) >= 6 && stripos($search, 'trx') !== 0) {
                        $or[] = 's.gcash_reference LIKE :search_ref';
                        $or[] = 's.notes LIKE :search_note';
                        $params[':search_ref']  = '%' . $m[1] . '%';
                        $params[':search_note'] = '%GCash Ref:%' . $m[1] . '%';
                    }
                } else {
                    $or[] = 's.cashier_name LIKE :search_name';
                    $params[':search_name'] = $like;
                }
                $where[] = '(' . implode(' OR ', $or) . ')';
            }
            if ($date_from) {
                $where[] = "t.created_at >= :date_from";
                $params[':date_from'] = $date_from . ' 00:00:00';
            }
            if ($date_to) {
                $where[] = "t.created_at < DATE_ADD(:date_to, INTERVAL 1 DAY)";
                $params[':date_to'] = $date_to;
            }
            if ($status) {
                $where[] = "s.status = :status";
                $params[':status'] = $status;
            }

            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

            // Count query
            $countQuery = "
                SELECT COUNT(DISTINCT t.id) AS total
                FROM transactions t
                LEFT JOIN sales s ON t.id = s.transaction_id
                $whereClause
            ";
            $countStmt = $pdo->prepare($countQuery);
            foreach ($params as $key => $value) {
                $countStmt->bindValue($key, $value);
            }
            $countStmt->execute();
            $total = (int)$countStmt->fetch(PDO::FETCH_ASSOC)['total'];

            // ── FIX: Single query with GROUP BY instead of N+1 item_count loop ──
            $query = "
                SELECT
                    t.id,
                    t.user_id,
                    t.items,
                    t.total_amount,
                    t.payment_method,
                    t.created_at,
                    s.status,
                    s.amount_paid,
                    s.change_amount,
                    s.cashier_name,
                    s.notes,
                    s.cashier_role,
                    COUNT(ti.id) AS item_count
                FROM transactions t
                LEFT JOIN sales s             ON t.id = s.transaction_id
                LEFT JOIN transaction_items ti ON t.id = ti.transaction_id
                $whereClause
                GROUP BY t.id, s.status, s.amount_paid, s.change_amount,
                         s.cashier_name, s.notes, s.cashier_role
                ORDER BY t.created_at DESC
                LIMIT :limit OFFSET :offset
            ";

            $stmt = $pdo->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Normalise types
            foreach ($transactions as &$tx) {
                $tx['total_amount']  = (float)$tx['total_amount'];
                $tx['amount_paid']   = isset($tx['amount_paid'])   ? (float)$tx['amount_paid']   : 0;
                $tx['change_amount'] = isset($tx['change_amount']) ? (float)$tx['change_amount'] : 0;
                $tx['status']        = $tx['status'] ?? 'completed';
                $tx['item_count']    = (int)$tx['item_count'];
            }

            echo json_encode([
                'success'     => true,
                'data'        => $transactions,
                'page'        => $page,
                'total_pages' => (int)ceil($total / $limit),
                'total_count' => $total,
            ]);
            break;
            
        case 'get_transaction':
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid transaction ID']);
                break;
            }
            
            $stmt = $pdo->prepare("
                SELECT 
                    t.id,
                    t.user_id,
                    t.items,
                    t.total_amount,
                    t.payment_method,
                    t.created_at,
                    s.status,
                    s.amount_paid,
                    s.change_amount,
                    s.cashier_name,
                    s.notes,
                    s.cashier_role
                FROM transactions t
                LEFT JOIN sales s ON t.id = s.transaction_id
                WHERE t.id = ?
                  AND (? = 'owner' OR t.user_id = ?)
            ");
            $stmt->execute([$id, $_SESSION['role'] ?? '', (int)$_SESSION['user_id']]);
            $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$transaction) {
                echo json_encode(['success' => false, 'message' => 'Transaction not found']);
                break;
            }
            
            // Get items
            $itemsStmt = $pdo->prepare("
                SELECT 
                    ti.*,
                    p.name as product_name,
                    p.sku as product_sku
                FROM transaction_items ti
                LEFT JOIN products p ON ti.product_id = p.id
                WHERE ti.transaction_id = ?
            ");
            $itemsStmt->execute([$id]);
            $transaction['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format numbers
            $transaction['total_amount'] = (float)$transaction['total_amount'];
            $transaction['amount_paid'] = isset($transaction['amount_paid']) ? (float)$transaction['amount_paid'] : 0;
            $transaction['change_amount'] = isset($transaction['change_amount']) ? (float)$transaction['change_amount'] : 0;
            $transaction['status'] = $transaction['status'] ?? 'completed';
            
            echo json_encode([
                'success' => true,
                'data' => $transaction
            ]);
            break;
            
        case 'void_transaction':
            security_require_role(['owner']);
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            $user_id = $_SESSION['user_id'] ?? null;
            
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid transaction ID']);
                break;
            }
            
            $pdo->beginTransaction();

            try {
                // Lock the sale before looking at its status. A second void request
                // (double click, two tabs) waits here, then finds it already voided,
                // so stock can never be put back twice.
                $checkStmt = $pdo->prepare("
                    SELECT t.created_at, s.status
                    FROM transactions t
                    LEFT JOIN sales s ON s.transaction_id = t.id
                    WHERE t.id = ?
                    FOR UPDATE
                ");
                $checkStmt->execute([$id]);
                $sale = $checkStmt->fetch(PDO::FETCH_ASSOC);

                if (!$sale) {
                    throw new Exception('Transaction not found');
                }
                if (($sale['status'] ?? 'completed') !== 'completed') {
                    throw new Exception('Only completed transactions can be voided (this one is ' . $sale['status'] . ')');
                }

                // Get transaction items with product names for logging
                $stmt = $pdo->prepare("
                    SELECT ti.*, p.name as product_name
                    FROM transaction_items ti
                    LEFT JOIN products p ON ti.product_id = p.id
                    WHERE ti.transaction_id = ?
                ");
                $stmt->execute([$id]);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (empty($items)) {
                    throw new Exception('Transaction items not found');
                }

                $updateStmt = $pdo->prepare("UPDATE sales SET status = 'voided' WHERE transaction_id = ?");
                $updateStmt->execute([$id]);

                // Restore stock and LOG ACTIVITY
                $stockStmt = $pdo->prepare("
                    UPDATE products
                    SET stock = stock + ?,
                        updated_by = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");

                // The sale took units nearest-expiry first from batches that existed then,
                // and a batch it touched has updated_at at or after the sale. Return the
                // units to the best match so products.stock and the batches stay equal.
                // If that batch has expired since, the returned units reopen it and the
                // expiry sweep (includes/inventory_expiry.php) writes them off and logs it.
                $batchPick = $pdo->prepare("
                    SELECT id, batch_no FROM product_batches
                    WHERE product_id = :pid AND date_added <= DATE(:sold_at)
                    ORDER BY (updated_at >= :sold_at2) DESC,
                             CASE WHEN expiration_date IS NULL THEN 1 ELSE 0 END,
                             expiration_date ASC, date_added ASC, id ASC
                    LIMIT 1
                    FOR UPDATE
                ");
                $batchReturn = $pdo->prepare("
                    UPDATE product_batches
                    SET stock = stock + ?, status = 'active'
                    WHERE id = ?
                ");
                $batchNew = $pdo->prepare("
                    INSERT INTO product_batches (product_id, batch_no, stock, date_added, expiration_date)
                    VALUES (?, ?, ?, CURDATE(), NULL)
                ");
                $expirySync = $pdo->prepare("
                    SELECT date_added, expiration_date FROM product_batches
                    WHERE product_id = ? AND stock > 0 AND status = 'active'
                    ORDER BY CASE WHEN expiration_date IS NULL THEN 1 ELSE 0 END, expiration_date ASC, date_added ASC
                    LIMIT 1
                ");
                $productExpiry = $pdo->prepare("UPDATE products SET expiration_date = ?, date_added = ? WHERE id = ?");

                $logStmt = $pdo->prepare("
                    INSERT INTO inventory_history (product_id, product_name, user_id, action, changes, created_at)
                    VALUES (?, ?, ?, 'void', ?, NOW())
                ");

                foreach ($items as $item) {
                    $qty = (int)$item['quantity'];
                    $stockStmt->execute([$qty, $user_id, $item['product_id']]);

                    $batchPick->execute([
                        ':pid'      => $item['product_id'],
                        ':sold_at'  => $sale['created_at'],
                        ':sold_at2' => $sale['created_at'],
                    ]);
                    $batch = $batchPick->fetch(PDO::FETCH_ASSOC);
                    if ($batch) {
                        $batchReturn->execute([$qty, $batch['id']]);
                        $batchNote = 'batch ' . $batch['batch_no'];
                    } else {
                        // No batch left from before the sale: keep the units in a batch of their own
                        $batchNo = 'B-VOID-' . $id . '-' . $item['product_id'];
                        $batchNew->execute([$item['product_id'], $batchNo, $qty]);
                        $batchNote = 'new batch ' . $batchNo;
                    }

                    // A returned batch may now be the nearest-expiring one
                    $expirySync->execute([$item['product_id']]);
                    if ($next = $expirySync->fetch(PDO::FETCH_ASSOC)) {
                        $productExpiry->execute([$next['expiration_date'], $next['date_added'], $item['product_id']]);
                    }

                    $changes = "Stock restored (+{$qty}) to {$batchNote} - Transaction #{$id} voided via Sales UI";
                    $logStmt->execute([$item['product_id'], $item['product_name'], $user_id, $changes]);
                }

                $pdo->commit();

                echo json_encode([
                    'success' => true,
                    'message' => 'Transaction voided successfully'
                ]);

            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("VOID ERROR: " . $e->getMessage());
                $already = strpos($e->getMessage(), 'Only completed') === 0;
                echo json_encode([
                    'success' => false,
                    'message' => $already ? 'This transaction has already been voided.' : 'Failed to void the transaction.'
                ]);
            }
            break;

        case 'get_stats':
            security_require_role(['owner']);
            $statsStmt = $pdo->query("
                SELECT 
                    COUNT(DISTINCT t.id) as total_transactions,
                    COALESCE(SUM(t.total_amount), 0) as total_sales,
                    COALESCE(SUM(CASE WHEN DATE(t.created_at) = CURDATE() THEN t.total_amount ELSE 0 END), 0) as today_sales,
                    COALESCE(AVG(t.total_amount), 0) as avg_transaction
                FROM transactions t
                LEFT JOIN sales s ON t.id = s.transaction_id
                WHERE s.status IS NULL OR s.status != 'voided'
            ");
            $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'total_transactions' => (int)$stats['total_transactions'],
                    'total_sales' => (float)$stats['total_sales'],
                    'today_sales' => (float)$stats['today_sales'],
                    'avg_transaction' => (float)$stats['avg_transaction']
                ]
            ]);
            break;
            
        default:
            error_log("Invalid action received: " . $action);
            echo json_encode([
                'success' => false, 
                'message' => 'Invalid action: ' . $action,
                'available_actions' => ['test', 'save_transaction', 'get_transactions', 'get_transaction', 'void_transaction', 'get_stats']
            ]);
            break;
    }
    
} catch (Exception $e) {
    error_log("Transaction AJAX error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    echo json_encode([
        'success' => false,
        'message' => 'An internal server error occurred.'
    ]);
}
?>
