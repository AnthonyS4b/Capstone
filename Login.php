<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/avatar.php';
require_once __DIR__ . '/includes/pin_recovery.php';

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

/**
 * Reply to a fetch submission and stop. The plain form post path below is
 * untouched so the page still works without JavaScript.
 */
function login_json(bool $ok, string $title, string $message, ?string $redirect = null): void
{
    http_response_code($ok ? 200 : 401);
    header('Content-Type: application/json');
    echo json_encode([
        'success'  => $ok,
        'title'    => $title,
        'message'  => $message,
        'redirect' => $redirect,
    ]);
    exit;
}

try {
    // Create database connection
    $db = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Handle login
    $isAjax = (
        (isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_POST['ajax']) && $_POST['ajax'] === '1')
    );

    // Forgot PIN (JSON only): owner resets with the recovery PIN, employee asks the owner
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['forgot_action'], $_POST['user_id'])) {
        security_require_csrf();
        $forgot = $_POST['forgot_action'] === 'owner_reset'
            ? pin_recovery_reset_owner_pin($db, $_POST['user_id'], trim((string)($_POST['recovery_pin'] ?? '')),
                trim((string)($_POST['new_pin'] ?? '')), trim((string)($_POST['confirm_pin'] ?? '')))
            : ($_POST['forgot_action'] === 'employee_request'
                ? pin_recovery_request_reset($db, $_POST['user_id'])
                : ['success' => false, 'message' => 'Unknown request.']);
        header('Content-Type: application/json');
        echo json_encode($forgot);
        exit;
    }

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

        $limitKey = security_pin_limit_key($user_id);
        $limit = security_rate_limit($limitKey, 5, 300);

        if (!$limit['allowed']) {
            $error = 'Too many incorrect attempts. Please try again in a few minutes.';
            if ($isAjax) {
                login_json(false, 'Too Many Attempts', $error);
            }
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

            if ($isAjax) {
                login_json(true, 'Welcome ' . $user['first_name'] . '!',
                    'Signed in. Taking you to your dashboard.', 'dashboard.php');
            }

            // Redirect to dashboard
            header("Location: dashboard.php");
            exit();
        } else {
            security_record_failure($limitKey);
            // $limit['attempts'] is the count before this try; this one failed.
            $remaining = max(0, 5 - (int)($limit['attempts'] ?? 0) - 1);
            $error = $remaining > 0
                ? 'Incorrect PIN. ' . $remaining . ' attempt' . ($remaining === 1 ? '' : 's') . ' remaining.'
                : 'Incorrect PIN.';
            if ($isAjax) {
                login_json(false, 'Login Failed', $error);
            }
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="description" content="Espenida's Pet & Poultry Supply — Point of Sale System Login">
    <meta name="theme-color" content="#2c5530">
    <title><?php echo SITE_NAME; ?> - POS Login</title>
    <link rel="icon" type="image/x-icon" href="assets/images/favicon.ico?v=20260923">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/images/favicon-32x32.png?v=20260923">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/images/favicon-16x16.png?v=20260923">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/images/apple-touch-icon.png?v=20260923">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/loginCSS.css?v=<?= filemtime(__DIR__ . '/assets/css/loginCSS.css') ?>">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <?php if (isset($error) || $logged_out): ?>
        <style>#loading-animation { display: none !important; }</style>
    <?php endif; ?>
</head>

<body>
    <!-- Loading Animation -->
    <div id="loading-animation">
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
                                    <strong>Login Failed!</strong> <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
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

    <main class="lg-shell">
        <div class="lg-card">

            <!-- Brand panel: store identity and the till's date and time -->
            <aside class="lg-brand">
                <div class="lg-brand-id">
                    <span class="lg-mark"><img src="assets/images/favicon-192x192.png" alt=""></span>
                    <div>
                        <p class="lg-store">Espenida's Pet &amp; Poultry Supply</p>
                        <p class="lg-store-sub">Point of sale</p>
                    </div>
                </div>
                <div class="lg-clock">
                    <span class="lg-time" id="lgTime"><?= date('g:i A') ?></span>
                    <span class="lg-date" id="lgDate"><?= date('l, F j') ?></span>
                </div>
                <p class="lg-brand-foot">&copy; <?= date('Y') ?> Espenida's Pet &amp; Poultry Supply</p>
            </aside>

            <section class="lg-panel">
                <?php if (isset($db_error)): ?>
                    <div class="lg-alert" role="alert">
                        <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
                        <span><strong>Offline.</strong> <?php echo htmlspecialchars($db_error); ?></span>
                    </div>
                <?php endif; ?>

                <form id="loginForm" method="POST" action="" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(security_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="user_id" id="user_id">
                    <input type="hidden" name="pin" id="pin">
                    <input type="hidden" name="ajax" value="1">

                    <!-- Step 1: choose an account -->
                    <div class="lg-step" id="lgStepPick">
                        <h1 class="lg-title">Who's signing in?</h1>
                        <p class="lg-sub">Tap your name, then enter your PIN.</p>

                        <?php if ($users): ?>
                            <div class="lg-accounts">
                                <?php foreach ($users as $user):
                                    $full_name = $user['first_name'] . ' ' . $user['last_name'];
                                    $is_owner  = $user['role'] === 'owner';
                                    $position  = trim((string)$user['position']) ?: ($is_owner ? 'Owner' : 'Employee'); ?>
                                    <button type="button" class="account-card<?= $is_owner ? ' is-owner' : '' ?>"
                                        data-user-id="<?= (int)$user['id'] ?>"
                                        data-user-name="<?= htmlspecialchars($full_name) ?>"
                                        data-user-role="<?= htmlspecialchars($user['role']) ?>"
                                        data-user-position="<?= htmlspecialchars($position) ?>">
                                        <?= user_avatar_html($user['avatar'] ?? null, $user['first_name'], $user['last_name'], 'lg-avatar') ?>
                                        <span class="lg-acc-name"><?= htmlspecialchars($full_name) ?></span>
                                        <span class="lg-acc-pos"><?= htmlspecialchars($position) ?></span>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        <?php elseif (!isset($db_error)): ?>
                            <p class="lg-empty">No active accounts. Ask the store owner to reactivate yours.</p>
                        <?php endif; ?>

                        <p class="lg-help"><i class="fas fa-lock" aria-hidden="true"></i>Forgot your PIN? Tap your name, then choose “Forgot PIN?”.</p>
                    </div>

                    <!-- Step 2: PIN for the chosen account -->
                    <div class="lg-step pin-section" id="lgStepPin" tabindex="-1" hidden>
                        <button type="button" class="lg-back" id="lgBack">
                            <i class="fas fa-arrow-left" aria-hidden="true"></i>All accounts
                        </button>

                        <div id="selectedAccountInfo">
                            <span class="lg-avatar lg-avatar-lg" id="lgChosenAvatar" aria-hidden="true"></span>
                            <h1 class="lg-title" id="selectedName"></h1>
                            <p id="selectedRole"></p>
                        </div>

                        <p id="pinPrompt">Enter your 4-digit PIN</p>

                        <div class="pin-dots-row" aria-hidden="true">
                            <?php for ($i = 0; $i < 4; $i++): ?>
                                <span class="pin-dot" id="pinDot<?= $i ?>"></span>
                            <?php endfor; ?>
                        </div>

                        <!-- Stays put until the next attempt, unlike the one-second toast -->
                        <div id="loginError" class="lg-error" role="alert" aria-live="polite" style="display:none;"></div>

                        <div class="keypad-grid">
                            <?php for ($i = 1; $i <= 9; $i++): ?>
                                <button type="button" class="keypad-btn key" data-num="<?= $i ?>"><?= $i ?></button>
                            <?php endfor; ?>
                            <span></span>
                            <button type="button" class="keypad-btn key" data-num="0">0</button>
                            <button type="button" id="backspace" class="keypad-btn" aria-label="Delete last digit">
                                <i class="fas fa-delete-left" aria-hidden="true"></i>
                            </button>
                        </div>

                        <button type="button" class="lg-forgot" id="lgForgotBtn">Forgot PIN?</button>
                    </div>
                </form>

                <!-- Step 3: forgot PIN. Its own form so its fields never reach the sign-in post. -->
                <form class="lg-step lg-forgot-step" id="lgStepForgot" tabindex="-1" hidden autocomplete="off" novalidate>
                    <button type="button" class="lg-back" id="lgForgotBack">
                        <i class="fas fa-arrow-left" aria-hidden="true"></i>Back to PIN
                    </button>
                    <h1 class="lg-title">Forgot PIN</h1>
                    <p class="lg-sub" id="lgForgotFor"></p>

                    <!-- Owner: recovery PIN + new PIN -->
                    <div id="lgForgotOwner" hidden>
                        <p class="lg-forgot-text">Enter your <strong>recovery PIN</strong> (the 4-digit PIN you set in My profile, not your sign-in PIN), then choose a new sign-in PIN.</p>
                        <label class="lg-field">
                            <span>Recovery PIN</span>
                            <input type="password" class="lg-input" id="lgRecoveryPin" inputmode="numeric" maxlength="4" autocomplete="off">
                        </label>
                        <div class="lg-field-row">
                            <label class="lg-field">
                                <span>New PIN</span>
                                <input type="password" class="lg-input" id="lgNewPin" inputmode="numeric" maxlength="4" autocomplete="new-password">
                            </label>
                            <label class="lg-field">
                                <span>Confirm new PIN</span>
                                <input type="password" class="lg-input" id="lgConfirmPin" inputmode="numeric" maxlength="4" autocomplete="new-password">
                            </label>
                        </div>
                        <button type="submit" class="lg-primary" id="lgOwnerResetBtn">Reset PIN</button>
                    </div>

                    <!-- Employee: tell the owner -->
                    <div id="lgForgotEmployee" hidden>
                        <p class="lg-forgot-text">Only the store owner can give you a new PIN. Send them a request: it appears in their notifications, and they will set a new PIN for you.</p>
                        <button type="submit" class="lg-primary" id="lgEmployeeRequestBtn">
                            <i class="fas fa-bell" aria-hidden="true"></i>Notify the owner
                        </button>
                    </div>

                    <div class="lg-forgot-msg" id="lgForgotMsg" role="status" aria-live="polite" hidden></div>
                </form>
            </section>
        </div>
    </main>

    <script src="assets/js/LoginJS.js?v=<?= filemtime(__DIR__ . '/assets/js/LoginJS.js') ?>"></script>
</body>

</html>
