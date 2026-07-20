<?php
// ajax/ml_recommendation_ajax.php
// Handles communication between PHP frontend and Python ML API

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

session_start();

define('ML_API_URL', 'http://127.0.0.1:5000');
define('ML_API_TIMEOUT', 30);

/**
 * Make cURL request to ML API
 */
function call_ml_api($endpoint, $method = 'GET', $data = null) {
    $url = ML_API_URL . $endpoint;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_TIMEOUT, ML_API_TIMEOUT);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } else {
            // Send empty JSON object to satisfy the Python backend if needed
            curl_setopt($ch, CURLOPT_POSTFIELDS, '{}'); 
        }
    }
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    if ($curl_error) {
        return [
            'success' => false,
            'error' => 'API Connection Error: ' . $curl_error,
            'http_code' => $http_code
        ];
    }
    
    $decoded = json_decode($response, true);
    $decoded['http_code'] = $http_code;
    $decoded['success'] = ($http_code >= 200 && $http_code < 300);
    
    return $decoded;
}

/**
 * Get all recommendations — tries ML model first, falls back to DB rules
 */
if (isset($_GET['action']) && $_GET['action'] === 'get_all_recommendations') {
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;

    // Try full ML recommendations first
    $result = call_ml_api("/api/recommendations?limit=" . $limit);

    // If ML model not trained or API down, fall back to DB-rules endpoint
    if (!$result['success'] || empty($result['recommendations'])) {
        $result = call_ml_api("/api/recommendations/db?limit=" . $limit);
        $result['source'] = $result['source'] ?? 'db_rules';
    }

    if ($result['success']) {
        echo json_encode([
            'success'         => true,
            'recommendations' => $result['recommendations'] ?? [],
            'total'           => count($result['recommendations'] ?? []),
            'source'          => $result['source'] ?? 'ml_model',
            'cached_at'       => date('Y-m-d H:i:s'),
        ]);
    } else {
        http_response_code(503);
        echo json_encode([
            'success' => false,
            'error'   => 'ML API is offline. Start the server with: python ml/api_server.py',
        ]);
    }
    exit;
}

/**
 * Get recommendation for single product
 */
if (isset($_GET['action']) && $_GET['action'] === 'get_product_recommendation') {
    $product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
    
    if ($product_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'product_id required']);
        exit;
    }
    
    $result = call_ml_api("/api/recommendations?product_id=" . $product_id);
    
    if ($result['success'] && isset($result['recommendations'][0])) {
        echo json_encode([
            'success' => true,
            'recommendation' => $result['recommendations'][0]
        ]);
    } else {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'No recommendation found for this product'
        ]);
    }
    exit;
}

/**
 * Get forecast for product
 */
if (isset($_GET['action']) && $_GET['action'] === 'get_forecast') {
    $product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
    $days_ahead = isset($_GET['days_ahead']) ? intval($_GET['days_ahead']) : 30;
    
    if ($product_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'product_id required']);
        exit;
    }
    
    $result = call_ml_api("/api/forecast", 'POST', [
        'product_id' => $product_id,
        'days_ahead' => $days_ahead
    ]);
    
    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'forecast' => $result
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $result['error'] ?? 'Forecast generation failed'
        ]);
    }
    exit;
}

/**
 * Train/Retrain the ML model
 */
if (isset($_POST['action']) && $_POST['action'] === 'train_model') {
    // Check authorization
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'owner') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    
    $result = call_ml_api("/api/train-model", 'POST', []);
    
    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'message' => 'Model training completed',
            'model_info' => $result['model_info'] ?? null
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $result['error'] ?? 'Model training failed'
        ]);
    }
    exit;
}

/**
 * Save applied recommendation
 */
if (isset($_POST['action']) && $_POST['action'] === 'save_recommendation') {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'owner') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    $strategy_id = isset($_POST['strategy_id']) ? htmlspecialchars($_POST['strategy_id']) : '';
    $discount = isset($_POST['discount_percentage']) ? floatval($_POST['discount_percentage']) : 0;
    $notes = isset($_POST['notes']) ? htmlspecialchars($_POST['notes']) : '';
    
    if ($product_id <= 0 || empty($strategy_id)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'product_id and strategy_id required']);
        exit;
    }
    
    $result = call_ml_api("/api/save-recommendation", 'POST', [
        'product_id' => $product_id,
        'strategy_id' => $strategy_id,
        'discount_percentage' => $discount,
        'notes' => $notes
    ]);
    
    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'message' => 'Recommendation saved successfully'
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $result['error'] ?? 'Failed to save recommendation'
        ]);
    }
    exit;
}

/**
 * Get model information
 */
if (isset($_GET['action']) && $_GET['action'] === 'model_info') {
    $result = call_ml_api("/api/model-info");
    
    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'model_info' => $result
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Could not retrieve model information'
        ]);
    }
    exit;
}

/**
 * API Health check
 */
if (isset($_GET['action']) && $_GET['action'] === 'health_check') {
    $result = call_ml_api("/health");
    
    echo json_encode([
        'success' => $result['success'] ?? false,
        'api_status' => $result['status'] ?? 'unavailable',
        'model_loaded' => $result['model_loaded'] ?? false,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

/**
 * Get active strategies
 */
if (isset($_GET['action']) && $_GET['action'] === 'get_active_strategies') {
    $result = call_ml_api("/api/active-strategies");
    
    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'strategies' => $result['strategies'] ?? []
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $result['error'] ?? 'Could not retrieve active strategies'
        ]);
    }
    exit;
}

/**
 * Cancel an active strategy
 */
if (isset($_POST['action']) && $_POST['action'] === 'cancel_strategy') {
    if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'owner' && $_SESSION['role'] !== 'admin')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }

    $history_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    
    if ($history_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Valid history ID required']);
        exit;
    }
    
    $result = call_ml_api("/api/cancel-strategy", 'POST', ['id' => $history_id]);
    
    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'message' => $result['message'] ?? 'Strategy cancelled successfully'
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $result['error'] ?? 'Failed to cancel strategy'
        ]);
    }
    exit;
}

// Default response
http_response_code(400);
echo json_encode(['success' => false, 'error' => 'No valid action specified']);

