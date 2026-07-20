<?php
// config/config.php

// Database configuration for espenida_pos
define('DB_HOST', 'localhost');
define('DB_NAME', 'espenida_pos');  // Your database name
define('DB_USER', 'root');           // Your MySQL username
define('DB_PASS', '');               // Your MySQL password (empty for XAMPP default)

// Site configuration
define('SITE_NAME', 'Espenida POS System');

// Try to establish database connection
try {
    $db = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    // Log the error to a file
    $db = null;
    error_log("Database connection failed: " . $e->getMessage(), 3, __DIR__ . '/error.log');
}
?>