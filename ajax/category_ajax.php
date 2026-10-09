<?php
// ajax/category_ajax.php
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../error.log');

// Add this at the very beginning to catch any errors before they happen
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false, 
            'message' => 'An internal server error occurred.'
        ]);
    }
});

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__) . '/includes/security.php';
security_require_login();
security_require_post_csrf();

header('Content-Type: application/json');

try {
    require_once dirname(__DIR__) . '/controllers/CategoryController.php';
    require_once dirname(__DIR__) . '/controllers/ProductController.php';

    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    if (empty($action)) {
        echo json_encode(['success' => false, 'message' => 'No action specified']);
        exit;
    }

    // Employees may add products and manage stock (add/remove stock, status, batch
    // expiry); editing a product's details, archiving and categories stay owner-only
    $ownerOnlyActions = [
        'create_category', 'update_category', 'delete_category', 'archive_category',
        'restore_category', 'update_category_status', 'update_product',
        'archive_product', 'restore_product',
        'permanent_delete', 'empty_archive',
        'set_tax_rate'
    ];
    if (in_array($action, $ownerOnlyActions, true)) {
        security_require_role(['owner']);
    }

    $categoryController = new CategoryController();
    $productController = new ProductController();

    if ($action === 'test_connection') {
        echo json_encode(['success' => true, 'message' => 'Connection successful']);
        exit;
    }

    switch ($action) {

        // ── Category actions ────────────────────────────────────────────────

        case 'get_categories':
            echo json_encode($categoryController->getAllCategories());
            break;

        case 'get_category':
            $id = $_POST['id'] ?? 0;
            echo json_encode($categoryController->getCategory($id));
            break;

        case 'create_category':
            $data = [
                'name'        => $_POST['name']        ?? '',
                'description' => $_POST['description'] ?? '',
                'icon'        => $_POST['icon']        ?? 'fa-paw',
                'color'       => $_POST['color']       ?? '#4a6fa5',
                'status'      => $_POST['status']      ?? 'active'
            ];
            echo json_encode($categoryController->createCategory($data));
            break;

        case 'update_category':
            $id   = $_POST['id'] ?? 0;
            $data = [
                'name'        => $_POST['name']        ?? '',
                'description' => $_POST['description'] ?? '',
                'icon'        => $_POST['icon']        ?? 'fa-paw',
                'color'       => $_POST['color']       ?? '#4a6fa5',
                'status'      => $_POST['status']      ?? 'active'
            ];
            echo json_encode($categoryController->updateCategory($id, $data));
            break;

        case 'delete_category':
            $id = $_POST['id'] ?? 0;
            echo json_encode($categoryController->deleteCategory($id));
            break;

        case 'archive_category':
            $id         = $_POST['id']  ?? 0;
            $deleted_by = $_SESSION['user_id'] ?? null;
            echo json_encode($categoryController->archiveCategory($id, $deleted_by));
            break;

        case 'restore_category':
            $id = $_POST['id'] ?? 0;
            echo json_encode($categoryController->restoreCategory($id));
            break;

        case 'get_archived_categories':
            echo json_encode($categoryController->getArchivedCategories());
            break;

        case 'check_category_transactions':
            $id = intval($_POST['id'] ?? 0);
            echo json_encode($categoryController->checkCategoryTransactions($id));
            break;

        case 'update_category_status':
            $id     = intval($_POST['id']     ?? 0);
            $status = $_POST['status'] ?? '';
            if (!in_array($status, ['active', 'inactive'])) {
                echo json_encode(['success' => false, 'message' => 'Invalid status value.']);
                break;
            }
            echo json_encode($categoryController->updateCategoryStatus($id, $status));
            break;


        // ── Product actions ─────────────────────────────────────────────────

        case 'get_category_products':
            $categoryId = $_POST['category_id'] ?? 0;
            echo json_encode($productController->getProductsByCategory($categoryId));
            break;

        case 'get_expired_products':
            echo json_encode($productController->getExpiredProducts());
            break;

        case 'get_all_products':
            echo json_encode($productController->getAllProducts());
            break;

        case 'get_product':
            $id = $_POST['id'] ?? 0;
            echo json_encode($productController->getProduct($id));
            break;

        case 'create_product':
            error_log("CREATE PRODUCT RECEIVED: " . print_r($_POST, true));

            $data = [
                'category_id'     => $_POST['category_id'] ?? null,
                'name'            => $_POST['name']        ?? '',
                'description'     => $_POST['description'] ?? '',
                'price'           => $_POST['price']       ?? 0,
                'cost_price'      => $_POST['cost_price']  ?? 0,
                'stock'           => $_POST['stock']       ?? 0,
                'unit'            => $_POST['unit']        ?? '',
                'sku'             => $_POST['sku']         ?? '',
                'barcode'         => $_POST['barcode']     ?? '',
                'image'           => $_POST['image']       ?? '',
                'expiration_date' => $_POST['expiration_date'] ?? null,
                'date_added'      => $_POST['date_added']      ?? null
            ];

            if (empty($data['category_id'])) {
                echo json_encode(['success' => false, 'message' => 'Category ID is required']);
                break;
            }
            if (empty($data['name'])) {
                echo json_encode(['success' => false, 'message' => 'Product name is required']);
                break;
            }
            if (!isset($data['price']) || $data['price'] === '') {
                echo json_encode(['success' => false, 'message' => 'Price is required']);
                break;
            }
            if (empty($data['unit'])) {
                echo json_encode(['success' => false, 'message' => 'Unit/Measurement is required']);
                break;
            }

            $result = $productController->createProduct($data);
            error_log("CREATE PRODUCT RESULT: " . print_r($result, true));
            echo json_encode($result);
            break;

        case 'update_product':
            $id   = $_POST['id'] ?? 0;
            $data = [
                'category_id'     => $_POST['category_id'] ?? null,
                'name'            => $_POST['name']        ?? '',
                'description'     => $_POST['description'] ?? '',
                'price'           => $_POST['price']       ?? 0,
                'cost_price'      => $_POST['cost_price']  ?? 0,
                'stock'           => $_POST['stock']       ?? 0,
                'unit'            => $_POST['unit']        ?? '',
                'sku'             => $_POST['sku']         ?? '',
                'barcode'         => $_POST['barcode']     ?? '',
                'image'           => $_POST['image']       ?? '',
                'expiration_date' => $_POST['expiration_date'] ?? null,
                'date_added'      => $_POST['date_added']      ?? null
            ];
            echo json_encode($productController->updateProduct($id, $data));
            break;

        case 'update_stock':
            $id       = $_POST['id']       ?? 0;
            $quantity = $_POST['quantity'] ?? 0;
            $type     = $_POST['type']     ?? 'add';
            $status   = $_POST['status']   ?? null;
            $batchData = [
                'batch_action'      => $_POST['batch_action']      ?? null,
                'batch_id'          => $_POST['batch_id']          ?? null,
                'expiration_date'   => $_POST['batch_expiration_date'] ?? null,
                'date_added'        => $_POST['batch_date_added']  ?? null,
            ];
            echo json_encode($productController->updateStock($id, $quantity, $type, $status, $batchData));
            break;
            
        case 'get_product_batches':
            $id = $_POST['id'] ?? 0;
            echo json_encode($productController->getProductBatches($id));
            break;

        case 'update_batch_expiry':
            $batchId  = $_POST['batch_id']        ?? 0;
            $newExpiry = $_POST['expiration_date'] ?? null;
            echo json_encode($productController->updateBatchExpiry($batchId, $newExpiry));
            break;

        // ── POS-specific endpoints ───────────────────────────────────────────

        case 'get_pos_products':
            echo json_encode($productController->getActiveProducts());
            break;

        case 'get_product_by_barcode':
            $barcode = trim($_POST['barcode'] ?? $_GET['barcode'] ?? '');

            if (empty($barcode)) {
                echo json_encode(['success' => false, 'message' => 'Barcode is required']);
                break;
            }

            $result = $productController->getProductByBarcode($barcode);

            // Normalise the response so JS always gets id/name/price/stock keys
            if ($result['success'] && !empty($result['data'])) {
                $p = $result['data'];
                $result['data'] = [
                    'id'            => $p['id']            ?? $p['product_id'] ?? null,
                    'name'          => $p['name']          ?? $p['product_name'] ?? '',
                    'price'         => isset($p['price'])  ? (float)$p['price'] : 0.0,
                    'stock'         => isset($p['stock'])  ? (float)$p['stock'] : 0,  // kilograms for Per Kilo
                    'unit'          => $p['unit']          ?? '',
                    'sku'           => $p['sku']           ?? '',
                    'barcode'       => $p['barcode']       ?? '',
                    'category_id'   => $p['category_id']   ?? null,
                    'category_name' => $p['category_name'] ?? '',
                    'image'         => $p['image']         ?? '',
                    'discount_applied' => isset($p['discount_applied']) ? (float)$p['discount_applied'] : 0.0,
                    'original_price'   => isset($p['original_price']) ? (float)$p['original_price'] : 0.0,
                    'strategy_id'   => $p['strategy_id']   ?? null,
                    // The POS needs these to price a "Pair & save" line; without them a
                    // scanned product showed the full price while checkout charged the pair price
                    'discounted_price'    => isset($p['discounted_price']) ? (float)$p['discounted_price'] : null,
                    'paired_product_id'   => isset($p['paired_product_id']) ? (int)$p['paired_product_id'] : null,
                    'paired_product_name' => $p['paired_product_name'] ?? null,
                    'strategy_ended_at'   => $p['strategy_ended_at'] ?? null
                ];
            }

            echo json_encode($result);
            break;

        // ── Archive actions ──────────────────────────────────────────────────

        case 'get_archived_products':
            echo json_encode($productController->getArchivedProducts());
            break;

        case 'archive_product':
            $id         = $_POST['id']  ?? 0;
            $deleted_by = $_SESSION['user_id'] ?? null;
            $archive_reason = $_POST['archive_reason'] ?? null;
            echo json_encode($productController->archiveProduct($id, $deleted_by, $archive_reason));
            break;

        case 'restore_product':
            $id = $_POST['id'] ?? 0;
            echo json_encode($productController->restoreProduct($id));
            break;

        case 'permanent_delete':
            if (($_SESSION['role'] ?? '') !== 'owner') {
                echo json_encode(['success' => false, 'message' => 'Access denied. Only owners can permanently delete products.']);
                break;
            }
            $id = $_POST['id'] ?? 0;
            echo json_encode($productController->permanentDelete($id));
            break;

        case 'empty_archive':
            if (($_SESSION['role'] ?? '') !== 'owner') {
                echo json_encode(['success' => false, 'message' => 'Access denied. Only owners can empty the archive.']);
                break;
            }
            echo json_encode($productController->emptyArchive());
            break;

        case 'get_archive_stats':
            echo json_encode($productController->getArchiveStats());
            break;

        // ── VAT rate (includes/tax.php): shown on the Inventory page, changed by the owner ──

        case 'get_tax_rate':
            require_once dirname(__DIR__) . '/config/database.php';
            require_once dirname(__DIR__) . '/includes/tax.php';
            echo json_encode(['success' => true, 'data' => tax_rate_info(getDBConnection())]);
            break;

        case 'set_tax_rate':
            require_once dirname(__DIR__) . '/config/database.php';
            require_once dirname(__DIR__) . '/includes/tax.php';
            $rate = tax_rate_parse($_POST['rate'] ?? '');
            if ($rate === null) {
                echo json_encode(['success' => false, 'message' => 'Enter a rate from 0 to 100, for example 12 or 12.5.']);
                break;
            }
            $taxPdo = getDBConnection();
            if (abs(tax_rate_at($taxPdo) - $rate) < 0.005) {
                echo json_encode(['success' => false, 'message' => 'The VAT rate is already ' . tax_rate_text($rate) . '%.']);
                break;
            }
            tax_rate_set($taxPdo, $rate, (int)$_SESSION['user_id']);
            echo json_encode([
                'success' => true,
                'message' => 'VAT is now ' . tax_rate_text($rate) . '%. Receipts use it from this moment.',
                'data' => tax_rate_info($taxPdo),
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action: ' . $action]);
            break;
    }

} catch (Exception $e) {
    error_log("Exception in category_ajax.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    security_json_error('An internal server error occurred.', 500);
}
?>
