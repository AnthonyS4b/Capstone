<?php
// setup_database.php - Run this once to create your database

$host = 'localhost';
$username = 'root';
$password = '';

try {
    // First connect without database to create it
    $pdo = new PDO("mysql:host=$host", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create database
    $sql = "CREATE DATABASE IF NOT EXISTS espenida_pos";
    $pdo->exec($sql);
    echo "Database created successfully<br>";
    
    // Select the database
    $pdo->exec("USE espenida_pos");
    
    // Create users table
    $sql = "CREATE TABLE IF NOT EXISTS users (
        id INT PRIMARY KEY AUTO_INCREMENT,
        first_name VARCHAR(50) NOT NULL,
        last_name VARCHAR(50) NOT NULL,
        email VARCHAR(100) UNIQUE,
        pin VARCHAR(10) NOT NULL,
        role ENUM('owner', 'employee') NOT NULL DEFAULT 'employee',
        position VARCHAR(100) NOT NULL,
        is_active BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";
    $pdo->exec($sql);
    echo "Users table created successfully<br>";
    
    $stmt = $pdo->prepare("INSERT INTO users (first_name, last_name, email, pin, role, position) 
                           VALUES (?, ?, ?, ?, ?, ?)");
    
    foreach ($users as $user) {
        $stmt->execute($user);
    }
    echo "Sample users inserted successfully<br>";
    
    echo "<br><strong>Setup complete! You can now use your login system.</strong>";
    echo "<br><br>Test users:";
    echo "<ul>";
    echo "<li>Kenneth - PIN: 1234 (Store Manager)</li>";
    echo "<li>Princess - PIN: 5678 (Cashier)</li>";
    echo "<li>Ezzar - PIN: 9012 (Cashier)</li>";
    echo "<li>John Anthony - PIN: 4321 (Store Owner)</li>";
    echo "<li>Anthony - PIN: 8765 (Co-Owner)</li>";
    echo "</ul>";
    
} catch(PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>