<?php
/**
 * Create Cashier Account
 * Run this file once to create a cashier user
 * 
 * Usage: Open in browser or run via command line: php create_cashier.php
 */

require_once __DIR__ . '/../config/database.php';

// Cashier credentials
$cashierName = 'Cashier';
$cashierEmail = 'cashier@iqracollege.com';
$cashierPassword = 'cashier123'; // Change this to your desired password

try {
    $pdo = getDBConnection();
    
    // Check if cashier already exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND role = 'cashier'");
    $stmt->execute([$cashierEmail]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        echo "Cashier account already exists!\n";
        echo "Email: {$cashierEmail}\n";
        echo "To change password, update the password hash in the database.\n";
    } else {
        // Generate password hash
        $hashedPassword = password_hash($cashierPassword, PASSWORD_DEFAULT);
        
        // Insert cashier
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'cashier')");
        $stmt->execute([$cashierName, $cashierEmail, $hashedPassword]);
        
        echo "✓ Cashier account created successfully!\n\n";
        echo "Login Credentials:\n";
        echo "==================\n";
        echo "Email: {$cashierEmail}\n";
        echo "Password: {$cashierPassword}\n\n";
        echo "⚠️  IMPORTANT: Change the password after first login!\n";
        echo "Login URL: /Iqra-College/auth/login.php\n";
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "\nMake sure:\n";
    echo "1. Database connection is configured correctly\n";
    echo "2. The 'users' table exists\n";
    echo "3. The 'cashier' role is added to the users table (run add_cashier_system.sql first)\n";
}
