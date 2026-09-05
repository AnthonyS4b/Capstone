<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/security.php';

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
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
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['pin'], $_POST['user_id'])) {
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
        } elseif ($user && password_verify($pin, $user['pin'])) {
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
} catch (PDOException $e) {
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Espenida's Pet & Poultry Supply — Point of Sale System Login">
    <title><?php echo SITE_NAME; ?> - POS Login</title>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/loginCSS.css">
    <style>
        /* Loading animation override */
        #loading-animation.hidden {
            opacity: 0;
            visibility: hidden;
        }

        <?php if (isset($error) || $logged_out): ?>#loading-animation {
            display: none !important;
        }

        <?php endif; ?>

        /* Disabled button styles */
        .keypad-btn:disabled {
            opacity: 0.35 !important;
            cursor: not-allowed !important;
            pointer-events: none !important;
        }

        .keypad-btn:disabled:hover {
            transform: none !important;
            background-color: inherit !important;
        }
    </style>
</head>

<body>
    <!-- Loading Animation -->
    <div id="loading-animation">
        <!-- Real logo in loading screen -->
        <img src="assets/images/espenidas_logo.jpg" class="logo-loading-img" alt="Espenida's Logo">
        <div class="progress-container">
            <div class="progress">
                <div class="progress-bar" id="progress-bar"></div>
            </div>
            <div class="percentage" id="percentage">0%</div>
        </div>
        <div class="loading-text">Connecting, Please wait...</div>
    </div>

    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>

    <!-- Watermark logo background -->
    <img src="assets/images/espenidas_logo.jpg" class="brand-logo-overlay" alt="">

    <!-- ===== MAIN PAGE WRAPPER ===== -->
    <div class="login-page-wrapper">

        <!-- HEADER: logo left, store name right -->
        <div class="login-header">
            <img src="assets/images/espenidas_logo.jpg" class="login-logo-img" alt="Espenida's Logo">
            <div class="login-header-text">
                <div class="login-store-name">Espenida's Pet &amp; Poultry Supply</div>
                <div class="login-header-sub">Point of Sale System &nbsp;·&nbsp; Staff Login</div>
            </div>
        </div>

        <!-- ===== LOGIN CARD ===== -->
        <div class="login-container position-relative">
            <?php if (isset($db_error)): ?>
                <span class="mode-badge"><i class="fas fa-wifi-slash me-1"></i>Offline Mode</span>
            <?php endif; ?>

            <?php if (isset($error)): ?>
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

            <div class="login-body">
                <?php if (isset($db_error)): ?>
                    <div class="alert alert-warning alert-dismissible fade show mb-4" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <?php echo $db_error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <form id="loginForm" method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(security_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="user_id" id="user_id">
                    <input type="hidden" name="pin" id="pin">

                    <div class="row g-0">
                        <!-- ===== LEFT — Account Selection ===== -->
                        <div class="col-md-6">
                            <p class="section-label">Select Account</p>

                            <div class="account-cards-list">
                                <!-- Employee Accounts -->
                                <?php
                                $employees = array_filter($users, function ($user) {
                                    return $user['role'] == 'employee';
                                });

                                if (!empty($employees)): ?>
                                    <p class="small mb-1" style="color:var(--text-muted);font-weight:600;font-size:0.7rem;letter-spacing:1px;text-transform:uppercase;">Employees</p>
                                    <?php foreach ($employees as $employee): ?>
                                        <div class="account-card"
                                            data-user-id="<?php echo $employee['id']; ?>"
                                            data-user-name="<?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?>"
                                            data-user-role="<?php echo $employee['role']; ?>"
                                            data-user-position="<?php echo htmlspecialchars($employee['position']); ?>">
                                            <div class="avatar bg-primary bg-opacity-10 text-primary">
                                                <?php echo strtoupper(substr($employee['first_name'], 0, 1)); ?>
                                            </div>
                                            <div class="ms-3">
                                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                                    <span style="font-weight:600;font-size:0.88rem;color:var(--text-dark);"><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></span>
                                                    <span class="account-tag employee">Employee</span>
                                                </div>
                                                <small style="color:var(--text-muted);font-size:0.76rem;"><?php echo htmlspecialchars($employee['position']); ?></small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>

                                <!-- Owner Accounts -->
                                <?php
                                $owners = array_filter($users, function ($user) {
                                    return $user['role'] == 'owner';
                                });

                                if (!empty($owners)): ?>
                                    <p class="small mt-2 mb-1" style="color:var(--text-muted);font-weight:600;font-size:0.7rem;letter-spacing:1px;text-transform:uppercase;">Owners</p>
                                    <?php foreach ($owners as $owner): ?>
                                        <div class="account-card"
                                            data-user-id="<?php echo $owner['id']; ?>"
                                            data-user-name="<?php echo htmlspecialchars($owner['first_name'] . ' ' . $owner['last_name']); ?>"
                                            data-user-role="<?php echo $owner['role']; ?>"
                                            data-user-position="<?php echo htmlspecialchars($owner['position']); ?>">
                                            <div class="avatar bg-info bg-opacity-10 text-info">
                                                <?php echo strtoupper(substr($owner['first_name'], 0, 1)); ?>
                                            </div>
                                            <div class="ms-3">
                                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                                    <span style="font-weight:600;font-size:0.88rem;color:var(--text-dark);"><?php echo htmlspecialchars($owner['first_name'] . ' ' . $owner['last_name']); ?></span>
                                                    <span class="account-tag owner">Owner</span>
                                                </div>
                                                <small style="color:var(--text-muted);font-size:0.76rem;"><?php echo htmlspecialchars($owner['position']); ?></small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Column Divider (desktop) -->
                        <div class="col-auto d-none d-md-block" style="width:1px;background:var(--border-color);margin:0 20px;"></div>

                        <!-- ===== RIGHT — PIN Entry ===== -->
                        <div class="col-md pin-section">
                            <div id="selectedAccountInfo" class="mb-3" style="display:none;">
                                <h6 class="mb-1" id="selectedName"></h6>
                                <small id="selectedRole"></small>
                            </div>

                            <p class="text-muted mb-1" id="pinPrompt" style="font-size:0.82rem;font-weight:500;">Select an account to enter PIN</p>

                            <!-- PIN Dots -->
                            <div class="pin-dots-row">
                                <?php for ($i = 0; $i < 4; $i++): ?>
                                    <div class="pin-dot" id="pinDot<?= $i ?>"></div>
                                <?php endfor; ?>
                            </div>

                            <!-- Keypad -->
                            <div class="keypad-grid">
                                <?php for ($i = 1; $i <= 9; $i++): ?>
                                    <button type="button" class="keypad-btn key" data-num="<?= $i ?>"><?= $i ?></button>
                                <?php endfor; ?>

                                <!-- Empty spacer -->
                                <div></div>

                                <button type="button" class="keypad-btn key" data-num="0">0</button>
                                <button type="button" id="backspace" class="keypad-btn">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-backspace" viewBox="0 0 16 16">
                                        <path d="M5.83 5.146a.5.5 0 0 0 0 .708L7.975 8l-2.147 2.146a.5.5 0 0 0 .707.708l2.147-2.147 2.146 2.147a.5.5 0 0 0 .707-.708L9.39 8l2.146-2.146a.5.5 0 0 0-.707-.708L8.683 7.293 6.536 5.146a.5.5 0 0 0-.707 0z" />
                                        <path d="M13.683 1a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2h-7.08a2 2 0 0 1-1.519-.698L.241 8.65a1 1 0 0 1 0-1.302L5.084 1.7A2 2 0 0 1 6.603 1h7.08zm-7.08 1a1 1 0 0 0-.76.35L1 8l4.844 5.65a1 1 0 0 0 .759.35h7.08a1 1 0 0 0 1-1V3a1 1 0 0 0-1-1h-7.08z" />
                                    </svg>
                                </button>
                            </div>

                            <!-- Help Note -->
                            <div class="mt-4">
                                <p style="color:var(--text-muted);font-size:0.75rem;text-align:center;">
                                    <i class="fas fa-lock me-1" style="color:var(--green-light);"></i>
                                    <i>Forgot PIN? Please contact the store owner for assistance.</i>
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- System Footer -->
                    <div class="system-footer">
                        <p class="mb-0">© <?php echo date('Y'); ?> <strong>Espenida's Pet &amp; Poultry Supply</strong> — All rights reserved.</p>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="assets/js/loginJS.js"></script>
</body>

</html>