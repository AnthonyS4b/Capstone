<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/security.php';

// Check if user is already logged in
if(isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

// Detect redirect from logout — skip loading animation in this case
$logged_out = isset($_GET['logged_out']) && $_GET['logged_out'] === '1';

// Include session tracker
require_once 'includes/session_tracker.php';

// Database configuration - Direct connection without external config file
$host = 'localhost';
$dbname = 'espenida_pos';
$username = 'root';
$password = '';
$db = null;
$users = [];
$db_error = null;
$error = null;

try {
    // Create database connection
    $db = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Handle login
    if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['pin'], $_POST['user_id'])) {
        security_require_csrf();
        $pin = $_POST['pin'];
        $user_id = $_POST['user_id'];
        
        // Fetch user by ID only (PIN is hashed — must verify in PHP)
        $query = "SELECT * FROM users WHERE id = :id AND is_active = 1";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $user_id);
        $stmt->execute();
        $user = $stmt->fetch();
        
        $limitKey = security_client_key('login', (string)$user_id);
        $limit = security_rate_limit($limitKey, 5, 300);

        if (!$limit['allowed']) {
            $error = 'Too many incorrect attempts. Please try again in a few minutes.';
        } elseif($user && password_verify($pin, $user['pin'])) {
            security_clear_failures($limitKey);
            session_regenerate_id(true);
            
            // Set session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['last_name'] = $user['last_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['position'] = $user['position'];
            $_SESSION['email'] = $user['email'];
            
            // ===== TRACK THIS LOGIN =====
            recordLogin($user['id']);
            
            // Set welcome toast message
            $_SESSION['toast_message'] = [
                'type' => 'success',
                'title' => 'Welcome ' . $user['first_name'] . '!',
                'message' => 'You have successfully logged in. Redirecting to dashboard...'
            ];
            
            // Redirect to dashboard
            header("Location: dashboard.php");
            exit();
        } else {
            security_record_failure($limitKey);
            $error = "Invalid PIN! Please try again.";
        }
    }
    
    // Fetch all active users
    $query = "SELECT * FROM users WHERE is_active = 1 ORDER BY 
              CASE WHEN role = 'owner' THEN 1 ELSE 2 END, 
              first_name ASC";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $users = $stmt->fetchAll();
    
} catch(PDOException $e) {
    error_log('Login database connection failed: ' . $e->getMessage());
    $db_error = 'The database is unavailable. Login is disabled until the connection is restored.';
    $users = [];
}

// Site configuration
define('SITE_NAME', 'Espenida\'s Pet & Poultry Supply');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo SITE_NAME; ?> - POS Login</title>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/loginCSS.css">
    <style>
        /* Loading animation */
        #loading-animation.hidden {
            opacity: 0;
            visibility: hidden;
        }
        
        <?php if(isset($error) || $logged_out): ?>
        #loading-animation {
            display: none !important;
        }
        <?php endif; ?>
        
        /* Timer animation */
        @keyframes timer {
            from { width: 100%; }
            to { width: 0%; }
        }
        
        /* Disabled button styles */
        .keypad-btn:disabled {
            opacity: 0.5 !important;
            cursor: not-allowed !important;
            pointer-events: none !important;
        }

        .keypad-btn:disabled:hover {
            transform: none !important;
            background-color: inherit !important;
        }

        body.dark-mode .keypad-btn:disabled {
            opacity: 0.3 !important;
        }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center vh-100"> 
    <!-- Loading Animation -->
    <div id="loading-animation">
        <div class="logo-container">
            <div class="logo-outline">
                <svg viewBox="0 0 400 200" xmlns="http://www.w3.org/2000/svg">
                    <text x="200" y="90" text-anchor="middle" font-size="60" font-weight="bold" font-family="Arial Black, sans-serif" fill="#ddd">Espenida's</text>
                    <text x="200" y="150" text-anchor="middle" font-size="24" font-weight="600" letter-spacing="4" font-family="Arial, sans-serif" fill="#ddd">PET AND POULTRY SUPPLY</text>
                </svg>
            </div>
            <div class="logo-fill">
                <svg viewBox="0 0 400 200" xmlns="http://www.w3.org/2000/svg">
                    <text x="200" y="90" text-anchor="middle" font-size="60" font-weight="bold" font-family="Arial Black, sans-serif" fill="#2c3e50">Espenida's</text>
                    <text x="200" y="150" text-anchor="middle" font-size="24" font-weight="600" letter-spacing="4" font-family="Arial, sans-serif" fill="#3498db">PET AND POULTRY SUPPLY</text>
                </svg>
            </div>
        </div>
        <div class="progress-container">
            <div class="progress">
                <div class="progress-bar" id="progress-bar"></div>
            </div>
            <div class="percentage" id="percentage">0%</div>
        </div>
        <div class="loading-text">Connecting, Please wait..</div>
    </div>
    
    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>
    
    <!-- Logo background -->
    <?php if(file_exists('624605347_1619002695771508_5938881516375662906_n.jpg')): ?>
    <img src="624605347_1619002695771508_5938881516375662906_n.jpg" class="brand-logo-overlay" alt="Espenida's Logo">
    <?php endif; ?>
    
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-12">
                <div class="brand-header">
                    <div class="brand-name">Espenida's</div>
                    <div class="brand-tagline">PET & POULTRY SUPPLY</div>
                    <p class="text-muted mt-2 mb-0">Point of Sale System</p>
                    <?php if(isset($db_error)): ?>
                        <div class="alert alert-warning alert-dismissible fade show mt-3" role="alert">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <?php echo $db_error; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="login-container position-relative">
                    <?php if(isset($db_error)): ?>
                        <span class="mode-badge">Offline Mode</span>
                    <?php endif; ?>
                    
                    <?php if(isset($error)): ?>
                        <script>
                            if (!window.toastShown) {
                                window.toastShown = true;
                                function showErrorToast() {
                                    var toastContainer = document.getElementById('toastContainer');
                                    if (!toastContainer) return;
                                    toastContainer.innerHTML = '';
                                    var toastId = 'toast-error-' + Date.now();
                                    var toastHtml = `
                                        <div id="${toastId}" class="toast align-items-center text-white bg-danger border-0 mb-2 show" role="alert">
                                            <div class="d-flex">
                                                <div class="toast-body">
                                                    <i class="fas fa-exclamation-circle me-2"></i>
                                                    <strong>Login Failed!</strong> <?php echo addslashes($error); ?>
                                                </div>
                                                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                                            </div>
                                            <div class="toast-timer"></div>
                                        </div>
                                    `;
                                    toastContainer.insertAdjacentHTML('beforeend', toastHtml);
                                    setTimeout(function() {
                                        var toast = document.getElementById(toastId);
                                        if (toast && toast.parentNode) toast.remove();
                                    }, 1000);
                                }
                                if (document.readyState === 'loading') {
                                    document.addEventListener('DOMContentLoaded', showErrorToast);
                                } else {
                                    showErrorToast();
                                }
                            }
                        </script>
                    <?php endif; ?>
                    
                    <form id="loginForm" method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(security_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="user_id" id="user_id">
                        <input type="hidden" name="pin" id="pin">
                        
                        <div class="row">
                            <!-- LEFT - Account Selection -->
                            <div class="col-md-6">
                                <p class="text-muted mb-3" style="font-weight: 500;">Select Account</p>
                                
                                <!-- Employee Accounts -->
                                <?php
                                $employees = array_filter($users, function($user) {
                                    return $user['role'] == 'employee';
                                });
                                
                                if(!empty($employees)): ?>
                                <div class="mb-3">
                                    <p class="small text-muted mb-2" style="font-weight: 500;">Employees</p>
                                    <?php foreach($employees as $employee): ?>
                                    <div class="d-flex align-items-center bg-white p-3 mb-3 shadow-sm account-card" 
                                         data-user-id="<?php echo $employee['id']; ?>" 
                                         data-user-name="<?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?>"
                                         data-user-role="<?php echo $employee['role']; ?>"
                                         data-user-position="<?php echo htmlspecialchars($employee['position']); ?>">
                                        <div class="avatar bg-primary bg-opacity-10 text-primary">
                                            <?php echo strtoupper(substr($employee['first_name'], 0, 1)); ?>
                                        </div>
                                        <div class="ms-3">
                                            <div class="d-flex align-items-center">
                                                <span style="font-weight: 500;"><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></span>
                                                <span class="account-tag employee ms-2">Employee</span>
                                            </div>
                                            <small class="text-muted"><?php echo htmlspecialchars($employee['position']); ?></small>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Owner Accounts -->
                                <?php
                                $owners = array_filter($users, function($user) {
                                    return $user['role'] == 'owner';
                                });
                                
                                if(!empty($owners)): ?>
                                <div class="mb-3">
                                    <p class="small text-muted mb-2" style="font-weight: 500;">Owners</p>
                                    <?php foreach($owners as $owner): ?>
                                    <div class="d-flex align-items-center bg-white p-3 mb-3 shadow-sm account-card" 
                                         data-user-id="<?php echo $owner['id']; ?>" 
                                         data-user-name="<?php echo htmlspecialchars($owner['first_name'] . ' ' . $owner['last_name']); ?>"
                                         data-user-role="<?php echo $owner['role']; ?>"
                                         data-user-position="<?php echo htmlspecialchars($owner['position']); ?>">
                                        <div class="avatar bg-info bg-opacity-10 text-info">
                                            <?php echo strtoupper(substr($owner['first_name'], 0, 1)); ?>
                                        </div>
                                        <div class="ms-3">
                                            <div class="d-flex align-items-center">
                                                <span style="font-weight: 500;"><?php echo htmlspecialchars($owner['first_name'] . ' ' . $owner['last_name']); ?></span>
                                                <span class="account-tag owner ms-2">Owner</span>
                                            </div>
                                            <small class="text-muted"><?php echo htmlspecialchars($owner['position']); ?></small>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- RIGHT - PIN Entry -->
                            <div class="col-md-6 text-center">
                                <div id="selectedAccountInfo" class="mb-3" style="display: none;">
                                    <h6 class="mb-1" id="selectedName" style="font-weight: 600;"></h6>
                                    <small class="text-muted" id="selectedRole"></small>
                                </div>
                                
                                <p class="text-muted mb-3" id="pinPrompt" style="font-weight: 500;">Select an account to enter PIN</p>
                                
                                <!-- PIN DOTS -->
                                <div class="d-flex justify-content-center gap-3 mb-4">
                                    <?php for ($i=0; $i<4; $i++): ?>
                                        <div class="pin-dot" id="pinDot<?= $i ?>"></div>
                                    <?php endfor; ?>
                                </div>
                                
                                
                                <div class="d-grid gap-3 justify-content-center" style="grid-template-columns: repeat(3, 85px);">
                                    <?php for ($i=1; $i<=9; $i++): ?>
                                        <button type="button" class="btn btn-light shadow-sm keypad-btn key" data-num="<?= $i ?>">
                                            <?= $i ?>
                                        </button>
                                    <?php endfor; ?>
                                    
                                    <div></div>
                                    
                                    <button type="button" class="btn btn-light shadow-sm keypad-btn key" data-num="0">0</button>
                                    <button type="button" id="backspace" class="btn btn-dark shadow-sm keypad-btn">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-backspace" viewBox="0 0 16 16">
                                            <path d="M5.83 5.146a.5.5 0 0 0 0 .708L7.975 8l-2.147 2.146a.5.5 0 0 0 .707.708l2.147-2.147 2.146 2.147a.5.5 0 0 0 .707-.708L9.39 8l2.146-2.146a.5.5 0 0 0-.707-.708L8.683 7.293 6.536 5.146a.5.5 0 0 0-.707 0z"/>
                                            <path d="M13.683 1a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2h-7.08a2 2 0 0 1-1.519-.698L.241 8.65a1 1 0 0 1 0-1.302L5.084 1.7A2 2 0 0 1 6.603 1h7.08zm-7.08 1a1 1 0 0 0-.76.35L1 8l4.844 5.65a1 1 0 0 0 .759.35h7.08a1 1 0 0 0 1-1V3a1 1 0 0 0-1-1h-7.08z"/>
                                        </svg>
                                    </button>
                                </div>
                                
                                <!-- PIN Help Note -->
                                <div class="mt-4">
                                    <p class="text-muted small">
                                        <i>Forgot PIN? Please contact the store owner for assistance.</i>
                                    </p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- System Footer -->
                        <div class="system-footer">
                            <p class="mb-1">© <?php echo date('Y'); ?> Espenida's Pet & Poultry Supply</p>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <script src="assets/js/loginJS.js"></script>
</body>
</html>
