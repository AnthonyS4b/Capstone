<?php
// config/database.php - Updated to match your PDO setup

// The store runs on Philippine time. XAMPP's php.ini defaults to Europe/Berlin,
// which put PHP's dates 6 hours behind MySQL's (Asia/Singapore, also UTC+8).
date_default_timezone_set('Asia/Manila');

// Settings live in constants: this file is sometimes first loaded from inside a
// function (e.g. includes/avatar.php), where plain variables would stay local to
// that function and every later getDBConnection() would connect as user ''.
// (Distinct names so config/config.php's DB_* constants never collide.)
defined('POS_DB_HOST') || define('POS_DB_HOST', 'localhost');
defined('POS_DB_USER') || define('POS_DB_USER', 'root');
defined('POS_DB_PASS') || define('POS_DB_PASS', '');
defined('POS_DB_NAME') || define('POS_DB_NAME', 'espenida_pos');

// Kept for older code that reads these variables after including this file
$host = POS_DB_HOST;
$username = POS_DB_USER;
$password = POS_DB_PASS;
$database = POS_DB_NAME;

function getDBConnection() {
    try {
        $pdo = new PDO('mysql:host=' . POS_DB_HOST . ';dbname=' . POS_DB_NAME, POS_DB_USER, POS_DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch(PDOException $e) {
        die("Connection failed: " . $e->getMessage());
    }
}

// For backward compatibility with mysqli code, you can also add this function
function getLegacyDBConnection() {
    $conn = new mysqli(POS_DB_HOST, POS_DB_USER, POS_DB_PASS, POS_DB_NAME);
    
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    return $conn;
}
?>