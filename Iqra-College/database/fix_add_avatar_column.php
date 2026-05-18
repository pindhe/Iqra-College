<?php
/**
 * Fix: Add Avatar Column to Users Table
 * Run this script to add the avatar column if it doesn't exist
 */

require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getDBConnection();
    
    // Check if avatar column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'avatar'");
    $columnExists = $stmt->rowCount() > 0;
    
    if (!$columnExists) {
        // Add the avatar column
        $pdo->exec("ALTER TABLE users ADD COLUMN avatar VARCHAR(255) NULL AFTER phone");
        echo "✅ Avatar column added successfully!\n";
    } else {
        echo "ℹ️ Avatar column already exists.\n";
    }
    
    // Verify the column was added
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'avatar'");
    if ($stmt->rowCount() > 0) {
        $column = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "✅ Column verified: " . $column['Field'] . " (" . $column['Type'] . ")\n";
    }
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "\nYou can also run this SQL manually:\n";
    echo "ALTER TABLE users ADD COLUMN avatar VARCHAR(255) NULL AFTER phone;\n";
}
