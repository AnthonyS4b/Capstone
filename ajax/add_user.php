    <?php
    require_once dirname(__DIR__) . '/includes/security.php';
    security_start_session();
    header('Content-Type: application/json');

    // Enable error reporting for debugging
    error_reporting(E_ALL);
    ini_set('display_errors', 1);

    // Log to PHP error log
    error_log("=== add_user.php called ===");

    security_require_role(['owner']);
    security_require_csrf();

    // Get the raw POST data
    $input = file_get_contents('php://input');

    if (!$input) {
        error_log("No input received");
        echo json_encode(['success' => false, 'message' => 'No data received']);
        exit();
    }

    // Decode JSON
    $data = json_decode($input, true);

    if ($data === null) {
        error_log("JSON decode error: " . json_last_error_msg());
        echo json_encode(['success' => false, 'message' => 'Invalid JSON: ' . json_last_error_msg()]);
        exit();
    }

    // Validate required fields
    $required = ['first_name', 'last_name', 'email', 'pin', 'role'];
    $missing = [];

    foreach ($required as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            $missing[] = $field;
            error_log("Missing field: $field");
        }
    }

    if (!empty($missing)) {
        echo json_encode([
            'success' => false, 
            'message' => 'Missing required fields: ' . implode(', ', $missing)
        ]);
        exit();
    }

    // Sanitize and assign variables
    $first_name = trim($data['first_name']);
    $last_name = trim($data['last_name']);
    $email = trim($data['email']);
    $pin = trim($data['pin']);
    $role = $data['role'];
    $position = isset($data['position']) ? trim($data['position']) : '';

    error_log("Processing: first_name=$first_name, last_name=$last_name, email=$email, role=$role");

    // Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        error_log("Invalid email: $email");
        echo json_encode(['success' => false, 'message' => 'Invalid email format']);
        exit();
    }

    // Validate role
    if (!in_array($role, ['owner', 'employee'])) {
        error_log("Invalid role: $role");
        echo json_encode(['success' => false, 'message' => 'Invalid role']);
        exit();
    }

    // Validate PIN (4 digits)
    if (!preg_match('/^\d{4}$/', $pin)) {
        error_log("Invalid PIN format: $pin");
        echo json_encode(['success' => false, 'message' => 'PIN must be exactly 4 digits']);
        exit();
    }

    require_once '../config/database.php';

    try {
        $pdo = getDBConnection();
        error_log("Database connected");
        
        // Check if email already exists
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$email]);
        
        if ($check->rowCount() > 0) {
            error_log("Email already exists: $email");
            echo json_encode(['success' => false, 'message' => 'Email already exists']);
            exit();
        }
        
        // Hash PIN before storing (never store plaintext)
        $hashed_pin = password_hash($pin, PASSWORD_DEFAULT);
        
        // Insert new user
        $stmt = $pdo->prepare("INSERT INTO users (first_name, last_name, email, pin, role, position) VALUES (?, ?, ?, ?, ?, ?)");
        
        if ($stmt->execute([$first_name, $last_name, $email, $hashed_pin, $role, $position])) {
            $newId = $pdo->lastInsertId();
            error_log("User added successfully with ID: $newId");
            echo json_encode([
                'success' => true,
                'message' => 'User added successfully',
                'user_id' => $newId
            ]);
        } else {
            error_log("Failed to insert user");
            echo json_encode(['success' => false, 'message' => 'Failed to add user']);
        }
        
    } catch (PDOException $e) {
        error_log("Database error in add_user.php: " . $e->getMessage());
        security_json_error('Unable to add the user right now.', 500);
    }
    ?>
