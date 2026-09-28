<?php
// ajax/ml_recommendation_ajax.php
// Handles communication between PHP frontend and Python ML API

header('Content-Type: application/json');

require_once dirname(__DIR__) . '/includes/security.php';
security_start_session();
security_require_login();
security_require_post_csrf();

// Top level on purpose: database.php keeps its settings in globals
require_once dirname(__DIR__) . '/config/database.php';

define('ML_API_URL', 'http://127.0.0.1:5000');
define('ML_API_TIMEOUT', 30);

// ─── ML server auto-start ────────────────────────────────────────────────────
// If the Python server (ml/api_server.py) is not running, the first request
// that needs it starts it in the background - nobody has to use a terminal.
//  - Only one request launches it; others wait on a lock, then use it.
//  - The Python that has the ML packages is found once and remembered in
//    ml/logs/python_path.txt. Set the ML_PYTHON environment variable (full
//    path to python.exe) to force one; delete the file to search again.
//  - Server output goes to ml/logs/ml_server.log (ml/ is not web-accessible).
define('ML_SERVER_HOST', '127.0.0.1');   // must match API_CONFIG in ml/ml_config.py
define('ML_SERVER_PORT', 5000);
define('ML_START_TIMEOUT', 45);          // seconds to wait for a new server to come up
define('ML_RETRY_COOLDOWN', 60);         // after a failed start, don't retry for this long
define('ML_REQUIRED_MODULES', 'flask, flask_cors, pandas, numpy, sklearn, pymysql, joblib');

function ml_server_dir() {
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ml';
}

function ml_log_dir() {
    $dir = ml_server_dir() . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

function ml_server_is_up($timeout = 0.5) {
    $fp = @fsockopen(ML_SERVER_HOST, ML_SERVER_PORT, $errno, $errstr, $timeout);
    if (!$fp) return false;
    fclose($fp);
    return true;
}

/**
 * Full path of a Python interpreter that can run api_server.py, or null.
 */
function ml_find_python() {
    $cacheFile = ml_log_dir() . DIRECTORY_SEPARATOR . 'python_path.txt';
    $cached = is_file($cacheFile) ? trim((string)file_get_contents($cacheFile)) : '';
    if ($cached !== '' && is_file($cached)) return $cached;

    $root = dirname(__DIR__);
    $candidates = array_filter([
        getenv('ML_PYTHON') ?: null,
        $root . '\\.venv\\Scripts\\python.exe',
        $root . '\\ml\\.venv\\Scripts\\python.exe',
        'py',      // Windows Python launcher
        'python',  // whatever is on PATH
        'python3',
    ]);

    foreach ($candidates as $candidate) {
        if (strpbrk($candidate, '\\/') !== false && !is_file($candidate)) continue;

        // Prints the real interpreter path only if every package imports
        $output = [];
        $code = 1;
        @exec('"' . $candidate . '" -c "import sys, ' . ML_REQUIRED_MODULES . '; print(sys.executable)" 2>' .
            (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'), $output, $code);

        $exe = trim((string)end($output));
        if ($code === 0 && $exe !== '' && is_file($exe)) {
            @file_put_contents($cacheFile, $exe);
            return $exe;
        }
    }
    return null;
}

/**
 * Launch api_server.py in the background. Returns once the process is
 * started, not once it is ready.
 */
function ml_launch_server($python) {
    $dir = ml_server_dir();
    $log = ml_log_dir() . DIRECTORY_SEPARATOR . 'ml_server.log';

    if (PHP_OS_FAMILY !== 'Windows') {
        @exec('cd ' . escapeshellarg($dir) . ' && ML_SERVER_LOG=' . escapeshellarg($log) .
            ' nohup ' . escapeshellarg($python) . ' api_server.py >/dev/null 2>&1 &', $out, $code);
        return $code === 0;
    }

    // pythonw.exe runs without a console window (and matches ml/stop_server.vbs)
    $pythonw = dirname($python) . DIRECTORY_SEPARATOR . 'pythonw.exe';
    $exe = is_file($pythonw) ? $pythonw : $python;

    // Start-Process without redirection goes through ShellExecute, so the
    // server inherits no handles from Apache - it can never keep port 80 busy
    // after Apache restarts. api_server.py writes its own log (ML_SERVER_LOG).
    $q = function ($s) { return "'" . str_replace("'", "''", $s) . "'"; };
    $script = '$env:ML_SERVER_LOG = ' . $q($log) . '; ' .
        'Start-Process -FilePath ' . $q($exe) . " -ArgumentList 'api_server.py'" .
        ' -WorkingDirectory ' . $q($dir) . ' -WindowStyle Hidden';
    $encoded = base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'));

    @exec('powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand ' . $encoded, $out, $code);
    return $code === 0;
}

/**
 * Make sure the ML server is running, starting it if needed.
 * Returns true once the server accepts connections.
 */
function ml_server_ensure_running() {
    if (ml_server_is_up()) return true;

    $logDir = ml_log_dir();
    $failedFile = $logDir . DIRECTORY_SEPARATOR . 'autostart_failed_at.txt';

    // A start just failed: answer fast instead of making every request wait
    if (is_file($failedFile) && time() - (int)file_get_contents($failedFile) < ML_RETRY_COOLDOWN) {
        return false;
    }

    $lock = @fopen($logDir . DIRECTORY_SEPARATOR . 'autostart.lock', 'c');
    if ($lock) flock($lock, LOCK_EX); // concurrent requests queue here

    try {
        // Another request may have started it while this one waited
        if (ml_server_is_up()) return true;

        @set_time_limit(ML_START_TIMEOUT + 60);

        $python = ml_find_python();
        if ($python !== null && ml_launch_server($python)) {
            $deadline = microtime(true) + ML_START_TIMEOUT;
            while (microtime(true) < $deadline) {
                usleep(500000);
                if (ml_server_is_up()) {
                    @unlink($failedFile);
                    return true;
                }
            }
        }

        error_log('ML autostart failed: ' . ($python === null
            ? 'no Python with the ML packages found (set ML_PYTHON or install ml/requirements.txt)'
            : 'server did not start within ' . ML_START_TIMEOUT . 's, see ml/logs/ml_server.log'));
        @file_put_contents($failedFile, (string)time());
        return false;
    } finally {
        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

/**
 * Make cURL request to ML API
 */
function call_ml_api($endpoint, $method = 'GET', $data = null, $allowAutostart = true) {
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
    $curl_errno = curl_errno($ch);
    curl_close($ch);

    // Server not running: start it and try once more
    if ($curl_errno === CURLE_COULDNT_CONNECT && $allowAutostart && ml_server_ensure_running()) {
        return call_ml_api($endpoint, $method, $data, false);
    }

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
 * Add each product's image path to the recommendations. The ML API does not
 * return images, so they are looked up here in one query. Failures are
 * non-fatal: the page falls back to a placeholder.
 */
function attach_product_images(array $recs) {
    $ids = array_values(array_unique(array_filter(array_map(
        fn($r) => (int)($r['product_id'] ?? 0), $recs
    ))));
    if (!$ids) return $recs;

    try {
        $pdo = getDBConnection();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT id, image FROM products WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $images = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $e) {
        error_log('attach_product_images: ' . $e->getMessage());
        return $recs;
    }

    foreach ($recs as &$r) {
        $r['image'] = $images[(int)($r['product_id'] ?? 0)] ?? null;
    }
    unset($r);
    return $recs;
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
        $recs = attach_product_images($result['recommendations'] ?? []);
        echo json_encode([
            'success'         => true,
            'recommendations' => $recs,
            'total'           => count($result['recommendations'] ?? []),
            'source'          => $result['source'] ?? 'ml_model',
            'cached_at'       => date('Y-m-d H:i:s'),
        ]);
    } else {
        http_response_code(503);
        echo json_encode([
            'success' => false,
            'error'   => 'The ML server could not be started automatically. See ml/logs/ml_server.log.',
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

if (($_GET['action'] ?? '') === 'get_pairing_products') {
    $productId = intval($_GET['product_id'] ?? 0);
    $result = call_ml_api('/api/pairing-products?product_id=' . $productId);
    http_response_code($result['success'] ? 200 : ($result['http_code'] ?: 503));
    echo json_encode($result);
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
        'notes' => $notes,
        'paired_product_id' => intval($_POST['paired_product_id'] ?? 0),
        'user_id' => $_SESSION['user_id']
    ]);
    
    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'message' => $result['message'] ?? 'Recommendation saved successfully'
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
            'strategies' => attach_product_images($result['strategies'] ?? [])
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

