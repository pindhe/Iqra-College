<?php
/**
 * Database Setup Checker
 * Run this file to check if database and tables are set up correctly
 */

require_once __DIR__ . '/../config/database.php';

echo "<!DOCTYPE html>
<html>
<head>
    <link rel="icon" href="/Iqra-College/assets/images/iqra2.png" type="image/png">
    <title>Database Setup Check</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 10px; }
        .success { color: green; padding: 10px; background: #d4edda; border-radius: 5px; margin: 10px 0; }
        .error { color: red; padding: 10px; background: #f8d7da; border-radius: 5px; margin: 10px 0; }
        .info { color: blue; padding: 10px; background: #d1ecf1; border-radius: 5px; margin: 10px 0; }
        h1 { color: #333; }
        h2 { color: #666; margin-top: 20px; }
        code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; }
    </style>
</head>
<body>
<div class='container'>
    <h1>🔍 Database Setup Check</h1>";

try {
    $pdo = getDBConnection();
    echo "<div class='success'>✅ Database connection successful!</div>";
    
    // Check if database exists
    $stmt = $pdo->query("SELECT DATABASE() as db");
    $db = $stmt->fetch();
    echo "<div class='info'>📊 Current database: <code>" . htmlspecialchars($db['db']) . "</code></div>";
    
    // Required tables
    $requiredTables = [
        'users',
        'courses',
        'enrollments',
        'lessons',
        'materials',
        'quizzes',
        'questions',
        'results',
        'lesson_progress'
    ];
    
    echo "<h2>📋 Checking Tables</h2>";
    $allTablesExist = true;
    
    foreach ($requiredTables as $table) {
        try {
            $stmt = $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
            echo "<div class='success'>✅ Table <code>$table</code> exists</div>";
        } catch (PDOException $e) {
            echo "<div class='error'>❌ Table <code>$table</code> does NOT exist</div>";
            $allTablesExist = false;
        }
    }
    
    if ($allTablesExist) {
        echo "<h2>🎉 Setup Complete!</h2>";
        echo "<div class='success'>All tables are set up correctly. Your LMS is ready to use!</div>";
        echo "<p><a href='/Iqra-College/index.php' style='color: blue;'>Go to Home Page</a></p>";
    } else {
        echo "<h2>⚠️ Setup Required</h2>";
        echo "<div class='error'>Some tables are missing. Please follow these steps:</div>";
        echo "<ol>
            <li>Open phpMyAdmin: <a href='http://localhost/phpmyadmin' target='_blank'>http://localhost/phpmyadmin</a></li>
            <li>Select the <code>iqra</code> database (or create it if it doesn't exist)</li>
            <li>Click on the <strong>Import</strong> tab</li>
            <li>Choose file: <code>database/schema.sql</code></li>
            <li>Click <strong>Go</strong> to import</li>
            <li>Refresh this page to verify</li>
        </ol>";
        echo "<p><strong>Alternative:</strong> Copy and paste the contents of <code>database/schema.sql</code> into the SQL tab in phpMyAdmin and execute it.</p>";
    }
    
} catch (PDOException $e) {
    echo "<div class='error'>❌ Database connection failed: " . htmlspecialchars($e->getMessage()) . "</div>";
    echo "<p>Please check your database configuration in <code>config/database.php</code></p>";
}

echo "</div></body></html>";
?>
