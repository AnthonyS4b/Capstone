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
//  - The Python that has the ML packages is remembered in ml/logs/python_path.txt
//    (checked again each start, so a path copied from another PC is harmless).
//    Set the ML_PYTHON environment variable (full path to python.exe) to force one.
//  - On a new PC where Python is installed but the packages are not, it runs
//    "pip install -r ml/requirements.txt" once in the background (needs internet)
//    and the Recommendations page shows "setting up" until it is done.
//  - Server output goes to ml/logs/ml_server.log, pip's to ml/logs/pip_install.log.
define('ML_SERVER_HOST', '127.0.0.1');   // must match API_CONFIG in ml/ml_config.py
define('ML_SERVER_PORT', 5000);
define('ML_START_TIMEOUT', 45);          // seconds to wait for a new server to come up
define('ML_RETRY_COOLDOWN', 60);         // after a failed start, don't retry for this long
define('ML_INSTALL_TIMEOUT', 1200);      // a package install still running after this is treated as stuck
define('ML_REQUIRED_MODULES', 'flask, flask_cors, pandas, numpy, sklearn, pymysql, joblib');

// Why the last start attempt failed, for the message on the Recommendations page:
// 'no_python' | 'installing' | 'install_failed' | 'start_failed'
$GLOBALS['ml_autostart_problem'] = null;

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
function ml_python_candidates() {
    $cacheFile = ml_log_dir() . DIRECTORY_SEPARATOR . 'python_path.txt';
    $cached = is_file($cacheFile) ? trim((string)file_get_contents($cacheFile)) : '';
    $root = dirname(__DIR__);
    return array_values(array_unique(array_filter([
        getenv('ML_PYTHON') ?: null,
        $cached ?: null,
        $root . '\\.venv\\Scripts\\python.exe',
        $root . '\\ml\\.venv\\Scripts\\python.exe',
        'py',      // Windows Python launcher
        'python',  // whatever is on PATH
        'python3',
    ])));
}

/** Run $code with a candidate interpreter; returns sys.executable it printed, or null. */
function ml_probe_python($candidate, $imports) {
    if (strpbrk($candidate, '\\/') !== false && !is_file($candidate)) return null;
    $output = [];
    $code = 1;
    @exec('"' . $candidate . '" -c "import sys' . ($imports !== '' ? ', ' . $imports : '') .
        '; assert sys.version_info >= (3, 9); print(sys.executable)" 2>' .
        (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'), $output, $code);
    $exe = trim((string)end($output));
    return ($code === 0 && $exe !== '' && is_file($exe)) ? $exe : null;
}

/**
 * Full path of a Python interpreter that can run api_server.py, or null.
 * Every candidate, the remembered one included, must import all ML packages.
 */
function ml_find_python() {
    foreach (ml_python_candidates() as $candidate) {
        if ($exe = ml_probe_python($candidate, ML_REQUIRED_MODULES)) {
            @file_put_contents(ml_log_dir() . DIRECTORY_SEPARATOR . 'python_path.txt', $exe);
            return $exe;
        }
    }
    return null;
}

/** Any Python 3.9+ at all (packages or not), or null when none is installed. */
function ml_find_any_python() {
    foreach (ml_python_candidates() as $candidate) {
        if ($exe = ml_probe_python($candidate, '')) return $exe;
    }
    return null;
}

/**
 * Package install state: 'none' (never started), 'running', 'done' or 'failed'.
 * The background job writes pip_install.done with pip's exit code when it ends.
 */
function ml_install_state() {
    $dir = ml_log_dir();
    $started = $dir . DIRECTORY_SEPARATOR . 'pip_install.started';
    $done = $dir . DIRECTORY_SEPARATOR . 'pip_install.done';
    if (!is_file($started)) return 'none';
    if (is_file($done)) return trim((string)file_get_contents($done)) === '0' ? 'done' : 'failed';
    return (time() - filemtime($started) < ML_INSTALL_TIMEOUT) ? 'running' : 'failed';
}

/** Start "pip install -r requirements.txt" in the background with $python. */
function ml_start_package_install($python) {
    $dir = ml_log_dir();
    @unlink($dir . DIRECTORY_SEPARATOR . 'pip_install.done');
    @file_put_contents($dir . DIRECTORY_SEPARATOR . 'pip_install.started', date('c'));

    $req = ml_server_dir() . DIRECTORY_SEPARATOR . 'requirements.txt';
    $log = $dir . DIRECTORY_SEPARATOR . 'pip_install.log';
    $doneFile = $dir . DIRECTORY_SEPARATOR . 'pip_install.done';

    if (PHP_OS_FAMILY !== 'Windows') {
        @exec('nohup sh -c ' . escapeshellarg(escapeshellarg($python) . ' -m pip install --disable-pip-version-check -r ' .
            escapeshellarg($req) . ' > ' . escapeshellarg($log) . ' 2>&1; echo $? > ' . escapeshellarg($doneFile)) . ' >/dev/null 2>&1 &');
        return;
    }

    // A hidden PowerShell runs pip and records its exit code. The job is written to a
    // small script file and started through Start-Process, so it inherits nothing
    // from Apache (same as the server launch) and the command line stays short.
    $q = function ($s) { return "'" . str_replace("'", "''", $s) . "'"; };
    $jobFile = $dir . DIRECTORY_SEPARATOR . 'pip_install.ps1';
    @file_put_contents($jobFile,
        '& ' . $q($python) . ' -m pip install --disable-pip-version-check -r ' . $q($req) . ' *> ' . $q($log) . "\r\n" .
        'Set-Content -Path ' . $q($doneFile) . " -Value \$LASTEXITCODE\r\n");
    $script = "Start-Process -FilePath 'powershell' -WindowStyle Hidden -ArgumentList '-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File'," . $q($jobFile);
    $encoded = base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'));
    @exec('powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand ' . $encoded);
}

/** Last useful line of the pip log, to say why an install failed. */
function ml_install_error_hint() {
    $log = ml_log_dir() . DIRECTORY_SEPARATOR . 'pip_install.log';
    if (!is_file($log)) return '';
    $text = (string)file_get_contents($log);
    if (substr($text, 0, 2) === "\xFF\xFE") $text = mb_convert_encoding(substr($text, 2), 'UTF-8', 'UTF-16LE');
    $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $text))));
    foreach (array_reverse($lines) as $line) {
        if (stripos($line, 'error') !== false) return $line;
    }
    return $lines ? end($lines) : '';
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

    // Packages are still installing: nothing to start yet
    if (ml_install_state() === 'running') {
        $GLOBALS['ml_autostart_problem'] = 'installing';
        return false;
    }

    // A start just failed: answer fast instead of making every request wait
    if (is_file($failedFile) && time() - (int)file_get_contents($failedFile) < ML_RETRY_COOLDOWN) {
        $GLOBALS['ml_autostart_problem'] = trim((string)@file_get_contents($logDir . DIRECTORY_SEPARATOR . 'autostart_problem.txt')) ?: 'start_failed';
        return false;
    }

    $lock = @fopen($logDir . DIRECTORY_SEPARATOR . 'autostart.lock', 'c');
    if ($lock) flock($lock, LOCK_EX); // concurrent requests queue here

    try {
        // Another request may have started it while this one waited
        if (ml_server_is_up()) return true;

        @set_time_limit(ML_START_TIMEOUT + 60);

        $python = ml_find_python();

        if ($python === null) {
            // New PC: Python may be installed without the ML packages yet
            $installState = ml_install_state();
            if ($installState === 'running') {
                $GLOBALS['ml_autostart_problem'] = 'installing';
                return false;
            }
            $anyPython = ml_find_any_python();
            if ($anyPython === null) {
                $GLOBALS['ml_autostart_problem'] = 'no_python';
                @file_put_contents($logDir . DIRECTORY_SEPARATOR . 'autostart_problem.txt', 'no_python');
                error_log('ML autostart failed: no Python 3.9+ found (install it from python.org with "Add to PATH")');
                @file_put_contents($failedFile, (string)time());
                return false;
            }
            // Install when never tried, or retry 10 minutes after one that did not
            // work (no internet at the time, for example)
            $doneFile = $logDir . DIRECTORY_SEPARATOR . 'pip_install.done';
            $lastEnded = is_file($doneFile) ? filemtime($doneFile) : 0;
            if ($installState === 'none' || time() - $lastEnded > 600) {
                ml_start_package_install($anyPython);
                $GLOBALS['ml_autostart_problem'] = 'installing';
                return false;
            }
            $GLOBALS['ml_autostart_problem'] = 'install_failed';
            @file_put_contents($logDir . DIRECTORY_SEPARATOR . 'autostart_problem.txt', 'install_failed');
            @file_put_contents($failedFile, (string)time());
            return false;
        }

        if (ml_launch_server($python)) {
            $deadline = microtime(true) + ML_START_TIMEOUT;
            while (microtime(true) < $deadline) {
                usleep(500000);
                if (ml_server_is_up()) {
                    @unlink($failedFile);
                    return true;
                }
            }
        }

        error_log('ML autostart failed: server did not start within ' . ML_START_TIMEOUT . 's, see ml/logs/ml_server.log');
        $GLOBALS['ml_autostart_problem'] = 'start_failed';
        @file_put_contents($logDir . DIRECTORY_SEPARATOR . 'autostart_problem.txt', 'start_failed');
        // Search again next time instead of reusing an interpreter that just failed
        @unlink($logDir . DIRECTORY_SEPARATOR . 'python_path.txt');
        @file_put_contents($failedFile, (string)time());
        return false;
    } finally {
        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

/** What to tell the Recommendations page when the ML server is not available. */
function ml_unavailable_response() {
    switch ($GLOBALS['ml_autostart_problem'] ?? null) {
        case 'installing':
            return ['reason' => 'installing', 'retry_after' => 20, 'error' =>
                'Setting up the recommendation engine for the first time: installing its Python packages. ' .
                'This needs internet and can take a few minutes. The page will retry by itself.'];
        case 'no_python':
            return ['reason' => 'no_python', 'error' =>
                'Python is not installed. Install Python 3 from python.org (tick "Add python.exe to PATH"), then refresh this page.'];
        case 'install_failed':
            $hint = ml_install_error_hint();
            return ['reason' => 'install_failed', 'error' =>
                'Installing the recommendation engine\'s Python packages did not work' . ($hint !== '' ? ' (' . $hint . ')' : '') .
                '. Check the internet connection; it tries again automatically in 10 minutes. Details: ml/logs/pip_install.log.'];
        default:
            return ['reason' => 'start_failed', 'error' =>
                'The ML server could not be started automatically. See ml/logs/ml_server.log, then refresh.'];
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
        echo json_encode(['success' => false] + ml_unavailable_response());
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

