<?php
// controllers/ProductController.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/product_units.php';

class ProductController {
    private $conn;
    
    public function __construct() {
        // Use PDO connection instead of MySQLi
        $this->conn = getDBConnection();
    }
    
    /**
     * Make the active batches add up to products.stock.
     * Excess batch units are removed nearest-expiry first (the order they would have sold in);
     * a shortfall becomes a new adjustment batch. Call inside a transaction.
     * Returns a log line describing the change, or null when already in sync.
     */
    private function reconcileBatchStock($productId, $expiryForShortfall = null) {
        $pStmt = $this->conn->prepare("SELECT stock, unit FROM products WHERE id = :id FOR UPDATE");
        $pStmt->execute([':id' => $productId]);
        $product = $pStmt->fetch(PDO::FETCH_ASSOC);
        $productStock = qty_to_hundredths($product['stock'] ?? 0);
        $unit = $product['unit'] ?? '';

        $sStmt = $this->conn->prepare("SELECT COALESCE(SUM(stock), 0) FROM product_batches WHERE product_id = :id AND status = 'active'");
        $sStmt->execute([':id' => $productId]);
        $batchStock = qty_to_hundredths($sStmt->fetchColumn());

        // In hundredths so Per Kilo stock (decimal kilograms) reconciles exactly
        $diff = $batchStock - $productStock;
        if ($diff === 0) {
            return null;
        }

        if ($diff > 0) {
            $batches = $this->conn->prepare("SELECT id, stock FROM product_batches WHERE product_id = :id AND status = 'active' AND stock > 0 ORDER BY CASE WHEN expiration_date IS NULL THEN 1 ELSE 0 END, expiration_date ASC, date_added ASC FOR UPDATE");
            $batches->execute([':id' => $productId]);
            $upd = $this->conn->prepare("UPDATE product_batches SET stock = stock - :qty WHERE id = :bid");

            $remaining = $diff;
            foreach ($batches->fetchAll(PDO::FETCH_ASSOC) as $batch) {
                if ($remaining <= 0) break;
                $deduct = min(qty_to_hundredths($batch['stock']), $remaining);
                $upd->execute([':qty' => hundredths_to_qty($deduct), ':bid' => $batch['id']]);
                $remaining -= $deduct;
            }
            $this->conn->prepare("UPDATE product_batches SET status = 'depleted' WHERE product_id = :id AND stock = 0 AND status = 'active'")
                ->execute([':id' => $productId]);

            return "Batches synced to stock: -" . format_unit_amount($diff / 100, $unit) . " removed from batches (nearest expiry first)";
        }

        $shortfall = -$diff;
        $this->conn->prepare("INSERT INTO product_batches (product_id, batch_no, stock, date_added, expiration_date) VALUES (:pid, :bno, :stk, :dadd, :exp)")
            ->execute([
                ':pid' => $productId,
                ':bno' => "B-ADJ-" . time() . "-" . $productId,
                ':stk' => hundredths_to_qty($shortfall),
                ':dadd' => date('Y-m-d'),
                ':exp' => $expiryForShortfall
            ]);

        return "Batches synced to stock: +" . format_unit_amount($shortfall / 100, $unit) . " added as an adjustment batch";
    }

    /**
     * Point products.expiration_date / date_added at the nearest-expiring active batch (FEFO).
     */
    private function syncProductExpiryFromBatches($productId) {
        $syncStmt = $this->conn->prepare("SELECT date_added, expiration_date FROM product_batches
                    WHERE product_id = :pid AND stock > 0 AND status = 'active'
                    ORDER BY CASE WHEN expiration_date IS NULL THEN 1 ELSE 0 END, expiration_date ASC, date_added ASC
                    LIMIT 1");
        $syncStmt->execute([':pid' => $productId]);
        $nextBatch = $syncStmt->fetch(PDO::FETCH_ASSOC);
        if ($nextBatch) {
            $this->conn->prepare("UPDATE products SET expiration_date = :exp, date_added = :da WHERE id = :id")
                ->execute([':exp' => $nextBatch['expiration_date'], ':da' => $nextBatch['date_added'], ':id' => $productId]);
        }
    }

    /**
     * Log activity to inventory_history table
     */
    private function logActivity($productId, $action, $changes, $productName = null) {
        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            $userId = $_SESSION['user_id'] ?? null;
            
            // If name not provided, try to fetch it
            if (!$productName && $productId) {
                $pStmt = $this->conn->prepare("SELECT name FROM products WHERE id = :id");
                $pStmt->bindParam(':id', $productId, PDO::PARAM_INT);
                $pStmt->execute();
                $pData = $pStmt->fetch(PDO::FETCH_ASSOC);
                $productName = $pData['name'] ?? "Unknown Product";
            }
            
            $sql = "INSERT INTO inventory_history (product_id, product_name, user_id, action, changes, created_at) 
                    VALUES (:product_id, :product_name, :user_id, :action, :changes, NOW())";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':product_id', $productId, PDO::PARAM_INT);
            $stmt->bindParam(':product_name', $productName, PDO::PARAM_STR);
            $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindParam(':action', $action, PDO::PARAM_STR);
            $stmt->bindParam(':changes', $changes, PDO::PARAM_STR);
            $stmt->execute();
            
            return true;
        } catch (Exception $e) {
            error_log("Error in logActivity: " . $e->getMessage());
            return false;
        }
    }
    
    // Get all active products for POS
    public function getActiveProducts() {
        try {
            $sql = "SELECT p.*, p.image, c.name as category_name, c.color as category_color,
                           sh.discount_applied, sh.original_price, sh.strategy_id, sh.discounted_price,
                           sh.paired_product_id, partner.name AS paired_product_name, sh.ended_at AS strategy_ended_at
                    FROM products p 
                    LEFT JOIN categories c ON p.category_id = c.id 
                    LEFT JOIN strategy_history sh ON p.id = sh.product_id AND sh.status = 'applied' AND (sh.ended_at IS NULL OR sh.ended_at > NOW())
                    LEFT JOIN products partner ON partner.id = sh.paired_product_id
                    WHERE p.deleted_at IS NULL AND p.status = 'active' AND c.status = 'active'
                      AND (p.expiration_date IS NULL OR p.expiration_date >= CURDATE())
                    ORDER BY p.category_id, p.name ASC";
            
            $stmt = $this->conn->query($sql);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return ['success' => true, 'data' => $products];
            
        } catch (Exception $e) {
            error_log("Error in getActiveProducts: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    // Get products by category
    public function getProductsByCategory($categoryId) {
        try {
            $sql = "SELECT 
                        p.id,
                        p.category_id,
                        p.name,
                        p.description,
                        p.price,
                        p.cost_price,
                        p.stock,
                        p.unit,
                        p.sku,
                        p.barcode,
                        p.image,
                        COALESCE(
                            (SELECT pb.expiration_date FROM product_batches pb 
                             WHERE pb.product_id = p.id AND pb.stock > 0 AND pb.status = 'active' 
                               AND pb.expiration_date IS NOT NULL
                             ORDER BY pb.expiration_date ASC LIMIT 1),
                            p.expiration_date
                        ) AS expiration_date,
                        COALESCE(
                            (SELECT pb2.date_added FROM product_batches pb2 
                             WHERE pb2.product_id = p.id AND pb2.stock > 0 AND pb2.status = 'active'
                             ORDER BY CASE WHEN pb2.expiration_date IS NULL THEN 1 ELSE 0 END, pb2.expiration_date ASC, pb2.date_added ASC LIMIT 1),
                            p.date_added
                        ) AS date_added,
                        p.created_at,
                        p.updated_at,
                        p.status,
                        c.name  AS category_name, 
                        c.color AS category_color,
                        DATEDIFF(CURDATE(), COALESCE(
                            (SELECT pb3.date_added FROM product_batches pb3 
                             WHERE pb3.product_id = p.id AND pb3.stock > 0 AND pb3.status = 'active'
                             ORDER BY CASE WHEN pb3.expiration_date IS NULL THEN 1 ELSE 0 END, pb3.expiration_date ASC, pb3.date_added ASC LIMIT 1),
                            p.date_added, DATE(p.created_at)
                        )) AS days_in_stock
                    FROM products p 
                    LEFT JOIN categories c ON p.category_id = c.id 
                    WHERE p.category_id = :category_id AND p.deleted_at IS NULL
                      AND (p.expiration_date IS NULL OR p.expiration_date >= CURDATE())
                    ORDER BY p.name ASC";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':category_id', $categoryId, PDO::PARAM_INT);
            $stmt->execute();
            
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return ['success' => true, 'data' => $products];
            
        } catch (Exception $e) {
            error_log("Error in getProductsByCategory: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    // Get expired products
    public function getExpiredProducts() {
        try {
            $sql = "SELECT 
                        p.id,
                        p.category_id,
                        p.name,
                        p.description,
                        p.price,
                        p.cost_price,
                        p.stock,
                        p.unit,
                        p.sku,
                        p.barcode,
                        p.image,
                        p.expiration_date,
                        p.date_added,
                        p.created_at,
                        p.updated_at,
                        p.status,
                        c.name  AS category_name, 
                        c.color AS category_color,
                        DATEDIFF(CURDATE(), COALESCE(
                            (SELECT pb3.date_added FROM product_batches pb3 
                             WHERE pb3.product_id = p.id AND pb3.stock > 0 AND pb3.status = 'active'
                             ORDER BY CASE WHEN pb3.expiration_date IS NULL THEN 1 ELSE 0 END, pb3.expiration_date ASC, pb3.date_added ASC LIMIT 1),
                            p.date_added, DATE(p.created_at)
                        )) AS days_in_stock
                    FROM products p 
                    LEFT JOIN categories c ON p.category_id = c.id 
                    WHERE p.deleted_at IS NULL
                      AND p.expiration_date IS NOT NULL 
                      AND p.expiration_date < CURDATE()
                    ORDER BY p.expiration_date ASC";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return ['success' => true, 'data' => $products];
            
        } catch (Exception $e) {
            error_log("Error in getExpiredProducts: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    // Get all products (active only)
    public function getAllProducts() {
        try {
            $sql = "SELECT 
                        p.id,
                        p.category_id,
                        p.name,
                        p.description,
                        p.price,
                        p.cost_price,
                        p.stock,
                        p.unit,
                        p.sku,
                        p.barcode,
                        p.image,
                        p.expiration_date,
                        p.created_at,
                        p.updated_at,
                        p.status,
                        c.name as category_name, 
                        c.color as category_color 
                    FROM products p 
                    LEFT JOIN categories c ON p.category_id = c.id 
                    WHERE p.deleted_at IS NULL
                    ORDER BY p.created_at DESC";
            
            $stmt = $this->conn->query($sql);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return ['success' => true, 'data' => $products];
            
        } catch (Exception $e) {
            error_log("Error in getAllProducts: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    // Get single product
    public function getProduct($id) {
        try {
            $sql = "SELECT 
                        p.id,
                        p.category_id,
                        p.name,
                        p.description,
                        p.price,
                        p.cost_price,
                        p.stock,
                        p.unit,
                        p.sku,
                        p.barcode,
                        p.image,
                        p.expiration_date,
                        p.date_added,
                        p.created_at,
                        p.updated_at,
                        p.status,
                        c.name as category_name 
                    FROM products p 
                    LEFT JOIN categories c ON p.category_id = c.id 
                    WHERE p.id = :id";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($product) {
                return ['success' => true, 'data' => $product];
            }
            return ['success' => false, 'message' => 'Product not found'];
            
        } catch (Exception $e) {
            error_log("Error in getProduct: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    // Get product by barcode for POS scanner
    public function getProductByBarcode($barcode) {
        try {
            $sql = "SELECT 
                        p.id,
                        p.category_id,
                        p.name,
                        p.description,
                        p.price,
                        p.cost_price,
                        p.stock,
                        p.unit,
                        p.sku,
                        p.barcode,
                        p.image,
                        p.expiration_date,
                        p.status,
                        c.name as category_name,
                        sh.discount_applied,
                        sh.original_price,
                        sh.strategy_id, sh.discounted_price, sh.paired_product_id,
                        partner.name AS paired_product_name, sh.ended_at AS strategy_ended_at
                    FROM products p 
                    LEFT JOIN categories c ON p.category_id = c.id 
                    LEFT JOIN strategy_history sh ON p.id = sh.product_id AND sh.status = 'applied' AND (sh.ended_at IS NULL OR sh.ended_at > NOW())
                    LEFT JOIN products partner ON partner.id = sh.paired_product_id
                    WHERE p.barcode = :barcode AND p.deleted_at IS NULL AND p.status = 'active' AND c.status = 'active'
                      AND (p.expiration_date IS NULL OR p.expiration_date >= CURDATE())";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':barcode', $barcode, PDO::PARAM_STR);
            $stmt->execute();
            
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($product) {
                return ['success' => true, 'data' => $product];
            }
            return ['success' => false, 'message' => 'Product not found'];
            
        } catch (Exception $e) {
            error_log("Error in getProductByBarcode: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    // Create product
    /**
     * Reject negative or non-numeric prices and stock before anything is saved.
     * The form blocks these too, but requests can come from anywhere.
     */
    private function validateProductNumbers($data) {
        $isMoney = fn($v) => is_numeric($v) && preg_match('/^\d+(\.\d{1,2})?$/', (string)$v);
        $price = $data['price'] ?? '';
        if (!$isMoney($price) || (float)$price <= 0) {
            return 'Selling price must be a number greater than 0.';
        }
        $cost = $data['cost_price'] ?? '';
        if ($cost !== '' && $cost !== null && !$isMoney($cost)) {
            return 'Cost price must be 0 or a positive number.';
        }
        // Per Kilo stock is kilograms with up to 2 decimals (10.5); Per Gram is whole grams;
        // other units are whole numbers
        $stock = $data['stock'] ?? 0;
        if (parse_unit_quantity($stock, unit_is_per_kilo($data['unit'] ?? ''), true) === null) {
            if (unit_is_per_kilo($data['unit'] ?? '')) {
                return 'Stock must be 0 or more kilograms, with up to 2 decimals (e.g. 25.5).';
            }
            return unit_is_per_gram($data['unit'] ?? '')
                ? 'Stock must be a whole number of grams, 0 or more (e.g. 500).'
                : 'Stock must be a whole number of 0 or more.';
        }
        return null;
    }

    public function createProduct($data) {
        if ($error = $this->validateProductNumbers($data)) {
            return ['success' => false, 'message' => $error];
        }
        // "12.50" for DECIMAL stock (kilograms for Per Kilo, whole numbers otherwise)
        $data['stock'] = hundredths_to_qty(qty_to_hundredths($data['stock'] ?? 0));
        try {
            // A SKU from the form (typed, or pre-filled when the form opened) that is already
            // taken is refused. That is almost always the same product sent twice — a second
            // click after a slow save — so it must not become a duplicate with a new SKU.
            $submittedSku = trim((string)($data['sku'] ?? ''));
            if ($submittedSku !== '') {
                $taken = $this->conn->prepare("SELECT name, deleted_at FROM products WHERE sku = :sku LIMIT 1");
                $taken->execute([':sku' => $submittedSku]);
                if ($owner = $taken->fetch(PDO::FETCH_ASSOC)) {
                    return ['success' => false, 'message' => 'SKU ' . $submittedSku . ' is already used by "' . $owner['name'] . '"'
                        . ($owner['deleted_at'] ? ' (in the archive)' : '') . '. If you just saved this product, it is already in the list.'];
                }
            }

            // Generate SKU if not provided
            $sku = $submittedSku !== '' ? $submittedSku : 'PRD' . time() . rand(100, 999);

            // Ensure the SKU is unique — regenerate up to 5 times if a collision is found
            $attempts = 0;
            while ($attempts < 5) {
                $skuCheck = $this->conn->prepare("SELECT id FROM products WHERE sku = :sku LIMIT 1");
                $skuCheck->bindParam(':sku', $sku, PDO::PARAM_STR);
                $skuCheck->execute();
                if (!$skuCheck->fetch()) {
                    break; // SKU is free, proceed
                }
                // Collision: generate a fresh unique SKU regardless of what was submitted
                $sku = 'PRD' . time() . rand(1000, 9999);
                $attempts++;
            }
            
            // Handle barcode - each barcode may belong to only one product
            $barcode = $this->normalizeBarcode($data['barcode'] ?? null);
            if ($conflict = $this->findBarcodeConflict($barcode)) {
                return ['success' => false, 'message' => $conflict];
            }

            // Handle unit - required field
            $unit = isset($data['unit']) ? $data['unit'] : '';
            
            // Handle cost_price
            $cost_price = isset($data['cost_price']) ? floatval($data['cost_price']) : 0;
            
            // Handle expiration_date
            $expiration_date = isset($data['expiration_date']) && !empty($data['expiration_date']) ? $data['expiration_date'] : null;
            
            // Handle image path
            $image = isset($data['image']) && !empty($data['image']) ? $data['image'] : null;
            
            // Handle date_added (for ML tracking)
            $date_added = isset($data['date_added']) && !empty($data['date_added']) ? $data['date_added'] : date('Y-m-d');
            
            // Get category name for the category field
            $categoryName = '';
            if (!empty($data['category_id'])) {
                $catStmt = $this->conn->prepare("SELECT name FROM categories WHERE id = :id");
                $catStmt->bindParam(':id', $data['category_id'], PDO::PARAM_INT);
                $catStmt->execute();
                $catResult = $catStmt->fetch(PDO::FETCH_ASSOC);
                if ($catResult) {
                    $categoryName = $catResult['name'];
                }
            }
            
            // Get current user ID from session
            $created_by = $_SESSION['user_id'] ?? null;
            
            // Validate required fields
            if (empty($data['category_id'])) {
                return ['success' => false, 'message' => 'Category ID is required'];
            }
            if (empty($data['name'])) {
                return ['success' => false, 'message' => 'Product name is required'];
            }
            if (!isset($data['price']) || $data['price'] === '') {
                return ['success' => false, 'message' => 'Price is required'];
            }
            if (empty($unit)) {
                return ['success' => false, 'message' => 'Unit/Measurement is required'];
            }
            if (!$created_by) {
                return ['success' => false, 'message' => 'User not logged in'];
            }
            
            // Insert product with all fields
            $sql = "INSERT INTO products (
                        category_id, 
                        name, 
                        description, 
                        price, 
                        cost_price,
                        stock, 
                        unit,
                        sku, 
                        barcode, 
                        image,
                        expiration_date,
                        date_added,
                        category, 
                        created_by,
                        created_at,
                        updated_at
                    ) VALUES (
                        :category_id, 
                        :name, 
                        :description, 
                        :price, 
                        :cost_price,
                        :stock, 
                        :unit,
                        :sku, 
                        :barcode, 
                        :image,
                        :expiration_date,
                        :date_added,
                        :category, 
                        :created_by,
                        NOW(),
                        NOW()
                    )";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':category_id', $data['category_id'], PDO::PARAM_INT);
            $stmt->bindParam(':name', $data['name'], PDO::PARAM_STR);
            $stmt->bindParam(':description', $data['description'], PDO::PARAM_STR);
            $stmt->bindParam(':price', $data['price'], PDO::PARAM_STR);
            $stmt->bindParam(':cost_price', $cost_price, PDO::PARAM_STR);
            $stmt->bindParam(':stock', $data['stock'], PDO::PARAM_STR);
            $stmt->bindParam(':unit', $unit, PDO::PARAM_STR);
            $stmt->bindParam(':sku', $sku, PDO::PARAM_STR);
            $stmt->bindParam(':barcode', $barcode, PDO::PARAM_STR);
            $stmt->bindParam(':image', $image, PDO::PARAM_STR);
            $stmt->bindParam(':expiration_date', $expiration_date, PDO::PARAM_STR);
            $stmt->bindParam(':date_added', $date_added, PDO::PARAM_STR);
            $stmt->bindParam(':category', $categoryName, PDO::PARAM_STR);
            $stmt->bindParam(':created_by', $created_by, PDO::PARAM_INT);
            
            if ($stmt->execute()) {
                $productId = $this->conn->lastInsertId();
                
                // Create initial batch if stock > 0
                if (isset($data['stock']) && $data['stock'] > 0) {
                    $batchSql = "INSERT INTO product_batches (product_id, batch_no, stock, date_added, expiration_date) VALUES (:pid, :bno, :stk, :dadd, :exp)";
                    $batchStmt = $this->conn->prepare($batchSql);
                    $bno = "B-" . time() . "-" . $productId;
                    $batchStmt->execute([
                        ':pid' => $productId,
                        ':bno' => $bno,
                        ':stk' => $data['stock'],
                        ':dadd' => $date_added,
                        ':exp' => $expiration_date
                    ]);
                }
                
                // LOG ACTIVITY
                $this->logActivity($productId, 'add', 'New product added to inventory', $data['name']);
                
                return [
                    'success' => true, 
                    'message' => 'Product created successfully', 
                    'id' => $productId,
                    'data' => [
                        'id' => $productId,
                        'sku' => $sku,
                        'barcode' => $barcode,
                        'unit' => $unit,
                        'cost_price' => $cost_price,
                        'image' => $image,
                        'expiration_date' => $expiration_date
                    ]
                ];
            } else {
                return ['success' => false, 'message' => 'Failed to create product'];
            }
            
        } catch (Exception $e) {
            error_log("Error in createProduct: " . $e->getMessage());
            error_log("Data: " . print_r($data, true));
            if ($this->isDuplicateBarcodeError($e)) {
                return ['success' => false, 'message' => 'That barcode is already used by another product.'];
            }
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }

    // A SKU no product has yet, for a product that ended up without one
    private function generateSku() {
        $check = $this->conn->prepare("SELECT 1 FROM products WHERE sku = :sku LIMIT 1");
        do {
            $sku = 'PRD' . time() . rand(1000, 9999);
            $check->execute([':sku' => $sku]);
        } while ($check->fetchColumn());
        return $sku;
    }

    // Blank barcodes are stored as NULL so any number of products can have none
    private function normalizeBarcode($barcode) {
        $barcode = trim((string)($barcode ?? ''));
        return $barcode === '' ? null : $barcode;
    }

    // Message naming the product that already has this barcode, or null if it is free.
    // Archived products keep their barcode, so restoring one can't create a duplicate.
    private function findBarcodeConflict($barcode, $excludeId = null) {
        if ($barcode === null) return null;

        $stmt = $this->conn->prepare("SELECT name, deleted_at FROM products WHERE barcode = :barcode AND id <> :id LIMIT 1");
        $stmt->execute([':barcode' => $barcode, ':id' => (int)($excludeId ?? 0)]);
        $owner = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$owner) return null;

        return 'Barcode ' . $barcode . ' is already used by "' . $owner['name'] . '"'
            . ($owner['deleted_at'] ? ' (in the archive)' : '') . '. Each product needs its own barcode.';
    }

    // The unique key on products.barcode catches two saves racing past the check above
    private function isDuplicateBarcodeError($e) {
        return $e instanceof PDOException && $e->getCode() == 23000
            && stripos($e->getMessage(), 'uniq_products_barcode') !== false;
    }

    // Update product
    public function updateProduct($id, $data) {
        if ($error = $this->validateProductNumbers($data)) {
            return ['success' => false, 'message' => $error];
        }
        $data['stock'] = hundredths_to_qty(qty_to_hundredths($data['stock'] ?? 0));
        try {
            // Get category name for the category field
            $categoryName = '';
            if (!empty($data['category_id'])) {
                $catStmt = $this->conn->prepare("SELECT name FROM categories WHERE id = :id");
                $catStmt->bindParam(':id', $data['category_id'], PDO::PARAM_INT);
                $catStmt->execute();
                $catResult = $catStmt->fetch(PDO::FETCH_ASSOC);
                if ($catResult) {
                    $categoryName = $catResult['name'];
                }
            }
            
            // SKU on an edit. An emptied box keeps the SKU the product already has: it used to
            // be saved as '' and the next product edited that way failed on the unique key.
            // A SKU that another product uses is refused by name instead of a database error.
            $sku = trim((string)($data['sku'] ?? ''));
            $currentSkuStmt = $this->conn->prepare("SELECT sku FROM products WHERE id = :id");
            $currentSkuStmt->execute([':id' => $id]);
            $currentSku = (string)$currentSkuStmt->fetchColumn();
            if ($sku === '') {
                $sku = $currentSku !== '' ? $currentSku : $this->generateSku();
            } elseif ($sku !== $currentSku) {
                $skuOwner = $this->conn->prepare("SELECT name, deleted_at FROM products WHERE sku = :sku AND id <> :id LIMIT 1");
                $skuOwner->execute([':sku' => $sku, ':id' => (int)$id]);
                if ($owner = $skuOwner->fetch(PDO::FETCH_ASSOC)) {
                    return ['success' => false, 'message' => 'SKU ' . $sku . ' is already used by "' . $owner['name'] . '"'
                        . ($owner['deleted_at'] ? ' (in the archive)' : '') . '. Each product needs its own SKU.'];
                }
            }

            $barcode = $this->normalizeBarcode($data['barcode'] ?? null);
            if ($conflict = $this->findBarcodeConflict($barcode, $id)) {
                return ['success' => false, 'message' => $conflict];
            }
            $unit = isset($data['unit']) ? $data['unit'] : '';
            $cost_price = isset($data['cost_price']) ? floatval($data['cost_price']) : 0;
            $expiration_date = isset($data['expiration_date']) && !empty($data['expiration_date']) ? $data['expiration_date'] : null;
            $date_added = isset($data['date_added']) && !empty($data['date_added']) ? $data['date_added'] : null;
            $image = isset($data['image']) && !empty($data['image']) ? $data['image'] : null;
            
            // Get current user ID from session for updated_by
            $updated_by = $_SESSION['user_id'] ?? null;
            
            // Update product
            // Only update image if a new one was provided
            $updateImage = ($image !== null);
            
            $sql = "UPDATE products SET 
                        category_id = :category_id,
                        name = :name,
                        description = :description,
                        price = :price,
                        cost_price = :cost_price,
                        stock = :stock,
                        unit = :unit,
                        sku = :sku,
                        barcode = :barcode,
                        " . ($updateImage ? "image = :image," : "") . "
                        expiration_date = :expiration_date,
                        date_added = :date_added,
                        category = :category,
                        updated_by = :updated_by,
                        updated_at = NOW()
                    WHERE id = :id";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':category_id', $data['category_id'], PDO::PARAM_INT);
            $stmt->bindParam(':name', $data['name'], PDO::PARAM_STR);
            $stmt->bindParam(':description', $data['description'], PDO::PARAM_STR);
            $stmt->bindParam(':price', $data['price'], PDO::PARAM_STR);
            $stmt->bindParam(':cost_price', $cost_price, PDO::PARAM_STR);
            $stmt->bindParam(':stock', $data['stock'], PDO::PARAM_STR);
            $stmt->bindParam(':unit', $unit, PDO::PARAM_STR);
            $stmt->bindParam(':sku', $sku, PDO::PARAM_STR);
            $stmt->bindParam(':barcode', $barcode, PDO::PARAM_STR);
            if ($updateImage) $stmt->bindParam(':image', $image, PDO::PARAM_STR);
            $stmt->bindParam(':expiration_date', $expiration_date, PDO::PARAM_STR);
            $stmt->bindParam(':date_added', $date_added, PDO::PARAM_STR);
            $stmt->bindParam(':category', $categoryName, PDO::PARAM_STR);
            $stmt->bindParam(':updated_by', $updated_by, PDO::PARAM_INT);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);

            $this->conn->beginTransaction();
            if ($stmt->execute()) {
                // Sync expiration date to the initial batch to ensure data consistency
                $batchStmt = $this->conn->prepare("UPDATE product_batches SET expiration_date = :expiration_date WHERE product_id = :id AND batch_no LIKE 'B-INIT-%'");
                $batchStmt->bindParam(':expiration_date', $expiration_date, PDO::PARAM_STR);
                $batchStmt->bindParam(':id', $id, PDO::PARAM_INT);
                $batchStmt->execute();

                // The edit form sets stock directly — keep the batches in step with it
                $batchChange = $this->reconcileBatchStock($id, $expiration_date);
                $this->syncProductExpiryFromBatches($id);

                // LOG ACTIVITY
                $this->logActivity($id, 'edit', 'Product details updated' . ($batchChange ? " ({$batchChange})" : ''), $data['name']);

                $this->conn->commit();
                return ['success' => true, 'message' => 'Product updated successfully'];
            } else {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'Failed to update product'];
            }

        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log("Error in updateProduct: " . $e->getMessage());
            if ($this->isDuplicateBarcodeError($e)) {
                return ['success' => false, 'message' => 'That barcode is already used by another product.'];
            }
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    // Update stock and status
    public function updateStock($id, $quantity, $type = 'add', $status = null, $batchData = null) {
        // A stock change needs a positive amount: a whole number (units, or grams for Per
        // Gram), or kilograms with up to 2 decimals for Per Kilo products. "-5", "5.5 pcs"
        // or "--22" are rejected instead of being silently treated as a status-only update.
        $unitStmt = $this->conn->prepare("SELECT unit FROM products WHERE id = :id");
        $unitStmt->execute([':id' => $id]);
        $unit = (string)$unitStmt->fetchColumn();
        $perKilo = unit_is_per_kilo($unit);
        if (in_array($type, ['add', 'remove'], true) && parse_unit_quantity($quantity, $perKilo) === null) {
            if ($perKilo) {
                return ['success' => false, 'message' => 'Quantity must be more than 0 kg, with up to 2 decimals (e.g. 2.5).'];
            }
            return ['success' => false, 'message' => unit_is_per_gram($unit)
                ? 'Quantity must be a whole number of grams greater than 0 (e.g. 250).'
                : 'Quantity must be a whole number greater than 0.'];
        }
        try {
            // Hundredths for the arithmetic, "12.50" for the DECIMAL columns
            $quantityHundredths = in_array($type, ['add', 'remove'], true) ? qty_to_hundredths(parse_unit_quantity($quantity, $perKilo)) : 0;
            $quantity = hundredths_to_qty($quantityHundredths);
            $quantityLabel = format_unit_amount($quantity, $unit);
            $hasValidStatus = ($status !== null && in_array($status, ['active', 'inactive']));

            // ── Status-only update (type = 'none' or quantity = 0) ──
            if ($type === 'none' || $quantityHundredths <= 0) {
                if ($hasValidStatus) {
                    $sql = "UPDATE products SET status = :status, updated_at = NOW() WHERE id = :id AND deleted_at IS NULL";
                    $stmt = $this->conn->prepare($sql);
                    $stmt->bindParam(':status', $status, PDO::PARAM_STR);
                    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
                    $stmt->execute();
                    
                    // LOG ACTIVITY
                    $this->logActivity($id, 'edit', "Status changed to $status");
                    return ['success' => true, 'message' => "Product status updated to $status"];
                }
                return ['success' => true, 'message' => 'No changes made'];
            }
            
            // Never create batches for a product that does not exist or is archived
            $exists = $this->conn->prepare("SELECT 1 FROM products WHERE id = :id AND deleted_at IS NULL");
            $exists->execute([':id' => $id]);
            if (!$exists->fetchColumn()) {
                return ['success' => false, 'message' => 'Product not found.'];
            }

            $this->conn->beginTransaction();

            // ── Stock update (add) ──
            if ($type === 'add') {
                $batchAction = $batchData['batch_action'] ?? 'new';
                $batchId = $batchData['batch_id'] ?? null;
                $expDate = !empty($batchData['expiration_date']) ? $batchData['expiration_date'] : null;
                $dateAdded = !empty($batchData['date_added']) ? $batchData['date_added'] : date('Y-m-d');
                
                if ($batchAction === 'existing' && !empty($batchId)) {
                    // Add to existing batch
                    $bSql = "UPDATE product_batches SET stock = stock + :qty WHERE id = :bid AND product_id = :pid";
                    $bStmt = $this->conn->prepare($bSql);
                    $bStmt->execute([':qty' => $quantity, ':bid' => $batchId, ':pid' => $id]);
                    if ($bStmt->rowCount() === 0) {
                        $this->conn->rollBack();
                        return ['success' => false, 'message' => 'Selected batch not found.'];
                    }
                } else {
                    // Create new batch
                    $bSql = "INSERT INTO product_batches (product_id, batch_no, stock, date_added, expiration_date) VALUES (:pid, :bno, :stk, :dadd, :exp)";
                    $bStmt = $this->conn->prepare($bSql);
                    $bno = "B-" . time() . "-" . $id . "-" . rand(10,99);
                    $bStmt->execute([
                        ':pid' => $id,
                        ':bno' => $bno,
                        ':stk' => $quantity,
                        ':dadd' => $dateAdded,
                        ':exp' => $expDate
                    ]);
                }
                
                // Update global product stock
                $sql = "UPDATE products SET stock = stock + :quantity";
                if ($hasValidStatus) $sql .= ", status = :status";
                $sql .= ", updated_at = NOW() WHERE id = :id AND deleted_at IS NULL";
                
                $stmt = $this->conn->prepare($sql);
                $stmt->bindParam(':quantity', $quantity, PDO::PARAM_STR);
                $stmt->bindParam(':id', $id, PDO::PARAM_INT);
                if ($hasValidStatus) $stmt->bindParam(':status', $status, PDO::PARAM_STR);
                $stmt->execute();
                
                // Sync product expiration_date & date_added to the nearest-expiring active batch (FEFO)
                $syncSql = "SELECT date_added, expiration_date FROM product_batches 
                            WHERE product_id = :pid AND stock > 0 AND status = 'active' 
                            ORDER BY CASE WHEN expiration_date IS NULL THEN 1 ELSE 0 END, expiration_date ASC, date_added ASC 
                            LIMIT 1";
                $syncStmt = $this->conn->prepare($syncSql);
                $syncStmt->execute([':pid' => $id]);
                $nextBatch = $syncStmt->fetch(PDO::FETCH_ASSOC);
                if ($nextBatch) {
                    $this->conn->prepare("UPDATE products SET expiration_date = :exp, date_added = :da WHERE id = :id")
                        ->execute([':exp' => $nextBatch['expiration_date'], ':da' => $nextBatch['date_added'], ':id' => $id]);
                }
                
                // LOG ACTIVITY
                $changeText = "+{$quantityLabel} added (Restock to " . ($batchAction==='existing' ? "existing batch" : "new batch") . ")";
                if ($hasValidStatus) $changeText .= ", status set to $status";
                $this->logActivity($id, 'restock', $changeText);
                
                $this->conn->commit();
                return ['success' => true, 'message' => 'Stock added successfully to batch'];
                
            } 
            // ── Stock update (remove) ──
            else if ($type === 'remove') {
                $batchId = $batchData['batch_id'] ?? null;
                
                // Verify product has enough global stock
                $checkStmt = $this->conn->prepare("SELECT stock FROM products WHERE id = :id AND deleted_at IS NULL FOR UPDATE");
                $checkStmt->execute([':id' => $id]);
                $pRow = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$pRow || qty_to_hundredths($pRow['stock']) < $quantityHundredths) {
                    $this->conn->rollBack();
                    return ['success' => false, 'message' => 'Insufficient global stock'];
                }
                
                if (!empty($batchId)) {
                    // Remove from specific batch
                    $bCheck = $this->conn->prepare("SELECT stock FROM product_batches WHERE id = :bid AND product_id = :pid FOR UPDATE");
                    $bCheck->execute([':bid' => $batchId, ':pid' => $id]);
                    $bRow = $bCheck->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$bRow || qty_to_hundredths($bRow['stock']) < $quantityHundredths) {
                        $this->conn->rollBack();
                        return ['success' => false, 'message' => 'Insufficient stock in the selected batch'];
                    }
                    
                    $this->conn->prepare("UPDATE product_batches SET stock = stock - :qty WHERE id = :bid")->execute([':qty'=>$quantity, ':bid'=>$batchId]);
                } else {
                    // Auto FIFO removal
                    $batches = $this->conn->prepare("SELECT id, stock FROM product_batches WHERE product_id = :id AND stock > 0 AND status = 'active' ORDER BY CASE WHEN expiration_date IS NULL THEN 1 ELSE 0 END, expiration_date ASC, date_added ASC FOR UPDATE");
                    $batches->execute([':id' => $id]);
                    
                    $remainingToRemove = $quantityHundredths;
                    while ($batch = $batches->fetch(PDO::FETCH_ASSOC)) {
                        if ($remainingToRemove <= 0) break;

                        $deduct = min(qty_to_hundredths($batch['stock']), $remainingToRemove);
                        $this->conn->prepare("UPDATE product_batches SET stock = stock - :qty WHERE id = :bid")->execute([':qty'=>hundredths_to_qty($deduct), ':bid'=>$batch['id']]);
                        $remainingToRemove -= $deduct;
                    }
                    
                    if ($remainingToRemove > 0) {
                        $this->conn->rollBack();
                        return ['success' => false, 'message' => 'Not enough stock across active batches.'];
                    }
                }
                
                // Update global product stock
                $sql = "UPDATE products SET stock = stock - :quantity";
                if ($hasValidStatus) $sql .= ", status = :status";
                $sql .= ", updated_at = NOW() WHERE id = :id AND deleted_at IS NULL";
                
                $stmt = $this->conn->prepare($sql);
                $stmt->bindParam(':quantity', $quantity, PDO::PARAM_STR);
                $stmt->bindParam(':id', $id, PDO::PARAM_INT);
                if ($hasValidStatus) $stmt->bindParam(':status', $status, PDO::PARAM_STR);
                $stmt->execute();
                
                // Deplete empty batches
                $this->conn->prepare("UPDATE product_batches SET status = 'depleted' WHERE product_id = :id AND stock = 0 AND status != 'depleted'")->execute([':id'=>$id]);

                // A depleted batch may have been the nearest-expiring one
                $this->syncProductExpiryFromBatches($id);

                // LOG ACTIVITY
                $changeText = "-{$quantityLabel} removed";
                if ($hasValidStatus) $changeText .= ", status set to $status";
                $this->logActivity($id, 'reduction', $changeText);
                
                $this->conn->commit();
                return ['success' => true, 'message' => 'Stock removed successfully'];
            }
            
            return ['success' => false, 'message' => 'Invalid action'];
            
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log("Error in updateStock: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }

    
    // Get product batches
    public function getProductBatches($productId) {
        try {
            $sql = "SELECT * FROM product_batches 
                    WHERE product_id = :id AND status = 'active' AND stock > 0 
                    ORDER BY CASE WHEN expiration_date IS NULL THEN 1 ELSE 0 END, expiration_date ASC, date_added ASC";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':id', $productId, PDO::PARAM_INT);
            $stmt->execute();
            $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return ['success' => true, 'data' => $batches];
        } catch (Exception $e) {
            error_log("Error in getProductBatches: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Update a single batch's expiration date and re-sync the parent product.
     */
    public function updateBatchExpiry($batchId, $newExpiry) {
        try {
            $this->conn->beginTransaction();

            // Get batch info
            $stmt = $this->conn->prepare("SELECT product_id, batch_no FROM product_batches WHERE id = :id");
            $stmt->execute([':id' => $batchId]);
            $batch = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$batch) {
                return ['success' => false, 'message' => 'Batch not found'];
            }

            $productId = $batch['product_id'];

            // Update the batch expiration date (allow NULL to clear it)
            $expValue = !empty($newExpiry) ? $newExpiry : null;
            $updStmt = $this->conn->prepare("UPDATE product_batches SET expiration_date = :exp WHERE id = :id");
            $updStmt->execute([':exp' => $expValue, ':id' => $batchId]);

            // Sync parent product to the nearest-expiring active batch (FEFO)
            $syncSql = "SELECT date_added, expiration_date FROM product_batches 
                        WHERE product_id = :pid AND stock > 0 AND status = 'active' 
                        ORDER BY CASE WHEN expiration_date IS NULL THEN 1 ELSE 0 END, expiration_date ASC, date_added ASC 
                        LIMIT 1";
            $syncStmt = $this->conn->prepare($syncSql);
            $syncStmt->execute([':pid' => $productId]);
            $nextBatch = $syncStmt->fetch(PDO::FETCH_ASSOC);
            if ($nextBatch) {
                $this->conn->prepare("UPDATE products SET expiration_date = :exp, date_added = :da WHERE id = :id")
                    ->execute([':exp' => $nextBatch['expiration_date'], ':da' => $nextBatch['date_added'], ':id' => $productId]);
            }

            // Log activity
            $expLabel = $expValue ? $expValue : 'none';
            $this->logActivity($productId, 'batch_update', "Batch #{$batch['batch_no']} expiration updated to {$expLabel}");

            $this->conn->commit();
            return ['success' => true, 'message' => 'Batch expiration updated successfully'];

        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log("Error in updateBatchExpiry: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }

    // ============ ARCHIVE METHODS ============
    
    // Get archived products (soft deleted)
    public function getArchivedProducts() {
        try {
            $sql = "SELECT 
                        p.id,
                        p.category_id,
                        p.name,
                        p.description,
                        p.price,
                        p.cost_price,
                        p.stock,
                        p.unit,
                        p.sku,
                        p.barcode,
                        p.image,
                        p.expiration_date,
                        p.created_at,
                        p.updated_at,
                        p.deleted_at,
                        p.archive_reason,
                        c.name as category_name, 
                        c.color as category_color,
                        DATE_FORMAT(p.deleted_at, '%M %d, %Y %h:%i %p') as formatted_deleted_at
                    FROM products p 
                    LEFT JOIN categories c ON p.category_id = c.id 
                    WHERE p.deleted_at IS NOT NULL
                    ORDER BY p.deleted_at DESC";
            
            $stmt = $this->conn->query($sql);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return ['success' => true, 'data' => $products];
            
        } catch (Exception $e) {
            error_log("Error in getArchivedProducts: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    // Soft delete product (move to archive)
    public function archiveProduct($id, $deleted_by = null, $archive_reason = null) {
        try {
            $sql = "UPDATE products SET deleted_at = NOW(), deleted_by = :deleted_by, archive_reason = :archive_reason WHERE id = :id AND deleted_at IS NULL";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':deleted_by', $deleted_by, PDO::PARAM_INT);
            $stmt->bindParam(':archive_reason', $archive_reason, PDO::PARAM_STR);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            
            if ($stmt->execute() && $stmt->rowCount() > 0) {
                // LOG ACTIVITY
                $this->logActivity($id, 'archive', 'Product moved to archive');
                
                return ['success' => true, 'message' => 'Product moved to archive'];
            } else {
                return ['success' => false, 'message' => 'Product not found or already archived'];
            }
        } catch (Exception $e) {
            error_log("Error in archiveProduct: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    // Restore product from archive
    public function restoreProduct($id) {
        try {
            $sql = "UPDATE products SET deleted_at = NULL, deleted_by = NULL, archive_reason = NULL WHERE id = :id";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            
            if ($stmt->execute() && $stmt->rowCount() > 0) {
                // LOG ACTIVITY
                $this->logActivity($id, 'restore', 'Product restored from archive');
                
                return ['success' => true, 'message' => 'Product restored successfully'];
            } else {
                return ['success' => false, 'message' => 'Product not found'];
            }
        } catch (Exception $e) {
            error_log("Error in restoreProduct: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    // Permanently delete product
    public function permanentDelete($id) {
        try {
            // Only owners may permanently delete
            if (session_status() === PHP_SESSION_NONE) session_start();
            if (($_SESSION['role'] ?? '') !== 'owner') {
                return ['success' => false, 'message' => 'Access denied. Only owners can permanently delete products.'];
            }

            // First, check if this product has any transaction records
            $checkSql = "SELECT COUNT(*) as count FROM transaction_items WHERE product_id = :id";
            $checkStmt = $this->conn->prepare($checkSql);
            $checkStmt->bindParam(':id', $id, PDO::PARAM_INT);
            $checkStmt->execute();
            $row = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($row['count'] > 0) {
                return ['success' => false, 'message' => 'Cannot delete product because it has transaction records. Archive it instead.'];
            }
            
            // Fetch name before deleting
            $nameStmt = $this->conn->prepare("SELECT name FROM products WHERE id = :id");
            $nameStmt->bindParam(':id', $id, PDO::PARAM_INT);
            $nameStmt->execute();
            $product = $nameStmt->fetch(PDO::FETCH_ASSOC);
            $productName = $product['name'] ?? "Unknown Product";
            
            // LOG ACTIVITY BEFORE DELETION (Foreign key requirements)
            $this->logActivity($id, 'delete', 'Product permanently deleted from archive', $productName);
            
            // If no transaction records, proceed with deletion
            $sql = "DELETE FROM products WHERE id = :id";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            
            if ($stmt->execute() && $stmt->rowCount() > 0) {
                return ['success' => true, 'message' => 'Product permanently deleted'];
            } else {
                return ['success' => false, 'message' => 'Product not found'];
            }
        } catch (Exception $e) {
            error_log("Error in permanentDelete: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    // Empty entire archive
    public function emptyArchive() {
        try {
            // Only owners may empty the archive
            if (session_status() === PHP_SESSION_NONE) session_start();
            if (($_SESSION['role'] ?? '') !== 'owner') {
                return ['success' => false, 'message' => 'Access denied. Only owners can empty the archive.'];
            }

            // First, get all archived product IDs
            $getSql = "SELECT id FROM products WHERE deleted_at IS NOT NULL";
            $getStmt = $this->conn->query($getSql);
            $productIds = $getStmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (empty($productIds)) {
                return ['success' => true, 'message' => '0 products permanently deleted', 'count' => 0];
            }
            
            // Check if any of these products have transaction records
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $checkSql = "SELECT COUNT(*) as count FROM transaction_items WHERE product_id IN ($placeholders)";
            $checkStmt = $this->conn->prepare($checkSql);
            
            foreach ($productIds as $key => $id) {
                $checkStmt->bindValue($key + 1, $id, PDO::PARAM_INT);
            }
            $checkStmt->execute();
            $row = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($row['count'] > 0) {
                return ['success' => false, 'message' => 'Cannot delete products that have transaction records. Archive them instead.'];
            }
            
            // LOG ACTIVITY BEFORE DELETION (Foreign key requirements)
            foreach ($productIds as $id) {
                // Fetch name before deleting (individual names for detailed logging)
                $nameStmt = $this->conn->prepare("SELECT name FROM products WHERE id = :id");
                $nameStmt->bindParam(':id', $id, PDO::PARAM_INT);
                $nameStmt->execute();
                $product = $nameStmt->fetch(PDO::FETCH_ASSOC);
                $productName = $product['name'] ?? "Unknown Product";
                
                $this->logActivity($id, 'delete', 'Bulk product deletion via Empty Archive', $productName);
            }

            $sql = "DELETE FROM products WHERE deleted_at IS NOT NULL";
            $stmt = $this->conn->query($sql);
            $count = $stmt->rowCount();
            
            return ['success' => true, 'message' => "$count products permanently deleted", 'count' => $count];
            
        } catch (Exception $e) {
            error_log("Error in emptyArchive: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    // Get archive statistics
    public function getArchiveStats() {
        try {
            $stats = [];
            
            // Total archived products
            $result = $this->conn->query("SELECT COUNT(*) as count FROM products WHERE deleted_at IS NOT NULL");
            $stats['total_archived'] = $result->fetch(PDO::FETCH_ASSOC)['count'];
            
            // Archived by category
            $sql = "SELECT c.name, COUNT(p.id) as count 
                    FROM products p 
                    LEFT JOIN categories c ON p.category_id = c.id 
                    WHERE p.deleted_at IS NOT NULL 
                    GROUP BY c.id 
                    ORDER BY count DESC LIMIT 5";
            $result = $this->conn->query($sql);
            $stats['by_category'] = $result->fetchAll(PDO::FETCH_ASSOC);
            
            // Oldest archived
            $result = $this->conn->query("SELECT MIN(deleted_at) as oldest FROM products WHERE deleted_at IS NOT NULL");
            $row = $result->fetch(PDO::FETCH_ASSOC);
            $stats['oldest_archive'] = $row['oldest'];
            
            return ['success' => true, 'data' => $stats];
            
        } catch (Exception $e) {
            error_log("Error in getArchiveStats: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
}
?>