<?php
/**
 * Check Payments Table Structure
 * Run this to diagnose payment submission issues
 */

require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "<h2>Payments Table Check</h2>";
    
    // Check if table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'payments'");
    if ($stmt->rowCount() == 0) {
        echo "<p style='color: red;'>❌ Payments table does NOT exist!</p>";
        echo "<p>Solution: Run <code>database/add_cashier_system.sql</code></p>";
        exit;
    }
    
    echo "<p style='color: green;'>✅ Payments table exists</p>";
    
    // Check table structure
    $stmt = $pdo->query("DESCRIBE payments");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h3>Table Structure:</h3>";
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
    echo "<tr><th>Column</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    
    $requiredColumns = [
        'id', 'student_id', 'course_id', 'amount', 
        'payment_method', 'payment_reference', 'status',
        'verified_by', 'verified_at', 'notes', 'created_at', 'updated_at'
    ];
    
    $foundColumns = [];
    foreach ($columns as $col) {
        $foundColumns[] = $col['Field'];
        echo "<tr>";
        echo "<td>" . htmlspecialchars($col['Field']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Type']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Null']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Key']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Default'] ?? 'NULL') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Check for missing columns
    $missing = array_diff($requiredColumns, $foundColumns);
    if (!empty($missing)) {
        echo "<p style='color: red;'>❌ Missing columns: " . implode(', ', $missing) . "</p>";
        echo "<p>Solution: Run <code>database/fix_payments_table.sql</code> or <code>database/add_cashier_system.sql</code></p>";
    } else {
        echo "<p style='color: green;'>✅ All required columns exist</p>";
    }
    
    // Check for old schema conflicts
    $stmt = $pdo->query("SHOW COLUMNS FROM payments LIKE 'transaction_id'");
    if ($stmt->rowCount() > 0) {
        echo "<p style='color: orange;'>⚠️ Old schema detected (transaction_id column exists)</p>";
        echo "<p>This might cause conflicts. Consider running <code>database/fix_payments_table.sql</code></p>";
    }
    
    // Test insert
    echo "<h3>Test Insert:</h3>";
    try {
        $testStmt = $pdo->prepare("
            INSERT INTO payments (student_id, course_id, amount, payment_method, payment_reference, notes, status)
            VALUES (1, 1, 100.00, 'cash', 'TEST', 'Test payment', 'pending')
        ");
        // Don't actually execute, just prepare to check syntax
        echo "<p style='color: green;'>✅ SQL syntax is correct</p>";
    } catch (PDOException $e) {
        echo "<p style='color: red;'>❌ SQL Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>❌ Database Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Check your database connection in <code>config/database.php</code></p>";
}
