<?php
/**
 * Diagnostic Script - Check Courses Table Structure
 * Run this to diagnose course creation issues
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireRole('teacher');

$pdo = getDBConnection();

?>
<!DOCTYPE html>
<html>
<head>
    <link rel="icon" href="/Iqra-College/assets/images/iqra2.png" type="image/png">
    <title>Courses Table Diagnostic</title>
    <style>
        body { font-family: Arial; max-width: 900px; margin: 50px auto; padding: 20px; }
        .success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 15px; margin: 10px 0; border-radius: 5px; }
        .error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 15px; margin: 10px 0; border-radius: 5px; }
        .info { background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; padding: 15px; margin: 10px 0; border-radius: 5px; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f2f2f2; }
        code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; }
    </style>
</head>
<body>
    <h1>Courses Table Diagnostic</h1>
    
    <?php
    try {
        // Check if table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'courses'");
        if ($stmt->rowCount() == 0) {
            echo "<div class='error'>❌ Courses table does NOT exist!</div>";
            echo "<div class='info'>Solution: Run <code>database/database.sql</code> in phpMyAdmin</div>";
            exit;
        }
        
        echo "<div class='success'>✅ Courses table exists</div>";
        
        // Get table structure
        $stmt = $pdo->query("DESCRIBE courses");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<h2>Table Structure:</h2>";
        echo "<table>";
        echo "<tr><th>Column</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
        
        $requiredColumns = ['id', 'title', 'teacher_id'];
        $foundColumns = [];
        
        foreach ($columns as $col) {
            $foundColumns[] = $col['Field'];
            $isRequired = in_array($col['Field'], $requiredColumns);
            $rowClass = $isRequired ? 'style="background: #fff3cd;"' : '';
            echo "<tr $rowClass>";
            echo "<td><strong>" . htmlspecialchars($col['Field']) . "</strong></td>";
            echo "<td>" . htmlspecialchars($col['Type']) . "</td>";
            echo "<td>" . htmlspecialchars($col['Null']) . "</td>";
            echo "<td>" . htmlspecialchars($col['Key']) . "</td>";
            echo "<td>" . htmlspecialchars($col['Default'] ?? 'NULL') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Check for missing required columns
        $missing = array_diff($requiredColumns, $foundColumns);
        if (!empty($missing)) {
            echo "<div class='error'>❌ Missing required columns: " . implode(', ', $missing) . "</div>";
        } else {
            echo "<div class='success'>✅ All required columns exist</div>";
        }
        
        // Check current user
        $teacherId = getCurrentUserId();
        echo "<div class='info'>";
        echo "<h3>Current Teacher Info:</h3>";
        echo "<p><strong>Teacher ID:</strong> " . htmlspecialchars($teacherId) . "</p>";
        
        try {
            $stmt = $pdo->prepare("SELECT id, name, email, role FROM users WHERE id = ?");
            $stmt->execute([$teacherId]);
            $user = $stmt->fetch();
            if ($user) {
                echo "<p><strong>Name:</strong> " . htmlspecialchars($user['name']) . "</p>";
                echo "<p><strong>Email:</strong> " . htmlspecialchars($user['email']) . "</p>";
                echo "<p><strong>Role:</strong> " . htmlspecialchars($user['role']) . "</p>";
            } else {
                echo "<p class='error'>User not found in database!</p>";
            }
        } catch (PDOException $e) {
            echo "<p class='error'>Error fetching user: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
        echo "</div>";
        
        // Test insert
        echo "<div class='info'>";
        echo "<h3>Test Insert:</h3>";
        try {
            $testTitle = "TEST_COURSE_" . time();
            $stmt = $pdo->prepare("INSERT INTO courses (title, teacher_id) VALUES (?, ?)");
            $stmt->execute([$testTitle, $teacherId]);
            $testId = $pdo->lastInsertId();
            
            echo "<p class='success'>✅ Test insert successful! Course ID: $testId</p>";
            
            // Delete test course
            $stmt = $pdo->prepare("DELETE FROM courses WHERE id = ?");
            $stmt->execute([$testId]);
            echo "<p>Test course deleted.</p>";
        } catch (PDOException $e) {
            echo "<p class='error'>❌ Test insert failed: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
        echo "</div>";
        
    } catch (PDOException $e) {
        echo "<div class='error'>";
        echo "<h2>❌ Database Error</h2>";
        echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
        echo "</div>";
    }
    ?>
    
    <div style="margin-top: 30px;">
        <a href="/Iqra-College/teacher/courses.php" style="background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">← Back to Courses</a>
    </div>
</body>
</html>
