<?php
/**
 * Update Courses Table - Add Missing Fields
 * Run this script to update existing courses table with new fields
 */

require_once __DIR__ . '/../config/database.php';

$pdo = getDBConnection();
$errors = [];
$success = [];

echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <link rel="icon" href="/Iqra-College/assets/images/iqra2.png" type="image/png">
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Update Courses Table</title>
    <script src='https://cdn.tailwindcss.com'></script>
    <link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css'>
</head>
<body class='bg-gradient-to-br from-blue-50 to-blue-100 min-h-screen p-8'>
    <div class='max-w-4xl mx-auto'>
        <div class='bg-white rounded-2xl shadow-xl p-6'>
            <h1 class='text-3xl font-bold text-blue-600 mb-6'>
                <i class='fas fa-database mr-2'></i>Update Courses Table
            </h1>";

try {
    // Check if courses table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'courses'");
    if ($stmt->rowCount() == 0) {
        echo "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 px-4 py-3 rounded mb-4'>
                <i class='fas fa-exclamation-circle mr-2'></i>Courses table does not exist. Please run database.sql first.
              </div>";
    } else {
        // Get current columns
        $stmt = $pdo->query("SHOW COLUMNS FROM courses");
        $existingColumns = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $existingColumns[] = $row['Field'];
        }
        
        // Fields to add
        $fieldsToAdd = [
            'banner_image' => "ALTER TABLE courses ADD COLUMN banner_image VARCHAR(255) NULL AFTER thumbnail",
            'preview_video' => "ALTER TABLE courses ADD COLUMN preview_video VARCHAR(255) NULL AFTER banner_image",
            'discount_price' => "ALTER TABLE courses ADD COLUMN discount_price DECIMAL(10,2) DEFAULT NULL AFTER price",
            'is_free' => "ALTER TABLE courses ADD COLUMN is_free TINYINT(1) DEFAULT 0 AFTER discount_price",
            'access_days' => "ALTER TABLE courses ADD COLUMN access_days INT DEFAULT 0 COMMENT '0 = lifetime access' AFTER duration",
            'max_students' => "ALTER TABLE courses ADD COLUMN max_students INT DEFAULT NULL AFTER access_days",
            'enrolled_count' => "ALTER TABLE courses ADD COLUMN enrolled_count INT DEFAULT 0 AFTER max_students",
            'has_certificate' => "ALTER TABLE courses ADD COLUMN has_certificate TINYINT(1) DEFAULT 0 AFTER enrolled_count",
            'language' => "ALTER TABLE courses ADD COLUMN language VARCHAR(50) DEFAULT 'English' AFTER has_certificate",
            'meta_title' => "ALTER TABLE courses ADD COLUMN meta_title VARCHAR(255) NULL AFTER language",
            'meta_description' => "ALTER TABLE courses ADD COLUMN meta_description VARCHAR(500) NULL AFTER meta_title",
            'slug' => "ALTER TABLE courses ADD COLUMN slug VARCHAR(200) NULL AFTER title"
        ];
        
        // Add missing columns
        foreach ($fieldsToAdd as $field => $sql) {
            if (!in_array($field, $existingColumns)) {
                try {
                    $pdo->exec($sql);
                    $success[] = "Added column: <strong>$field</strong>";
                } catch (PDOException $e) {
                    $errors[] = "Failed to add column $field: " . $e->getMessage();
                }
            } else {
                $success[] = "Column <strong>$field</strong> already exists";
            }
        }
        
        // Add indexes
        try {
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_level ON courses(level)");
            $success[] = "Added index: <strong>idx_level</strong>";
        } catch (PDOException $e) {
            // Index might already exist
        }
        
        try {
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_price ON courses(price)");
            $success[] = "Added index: <strong>idx_price</strong>";
        } catch (PDOException $e) {
            // Index might already exist
        }
        
        // Add unique index on slug
        try {
            $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_slug ON courses(slug)");
            $success[] = "Added unique index: <strong>idx_slug</strong>";
        } catch (PDOException $e) {
            // Index might already exist
        }
        
        // Update enum types
        try {
            $pdo->exec("ALTER TABLE courses MODIFY COLUMN level ENUM('beginner', 'intermediate', 'advanced') DEFAULT 'beginner'");
            $success[] = "Updated <strong>level</strong> enum";
        } catch (PDOException $e) {
            $errors[] = "Failed to update level enum: " . $e->getMessage();
        }
        
        try {
            $pdo->exec("ALTER TABLE courses MODIFY COLUMN status ENUM('draft', 'published', 'archived') DEFAULT 'draft'");
            $success[] = "Updated <strong>status</strong> enum";
        } catch (PDOException $e) {
            $errors[] = "Failed to update status enum: " . $e->getMessage();
        }
        
        // Display results
        if (!empty($success)) {
            echo "<div class='bg-green-100 border-l-4 border-green-500 text-green-700 px-4 py-3 rounded mb-4'>
                    <h3 class='font-bold mb-2'><i class='fas fa-check-circle mr-2'></i>Success:</h3>
                    <ul class='list-disc list-inside space-y-1'>";
            foreach ($success as $msg) {
                echo "<li>$msg</li>";
            }
            echo "</ul></div>";
        }
        
        if (!empty($errors)) {
            echo "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 px-4 py-3 rounded mb-4'>
                    <h3 class='font-bold mb-2'><i class='fas fa-exclamation-circle mr-2'></i>Errors:</h3>
                    <ul class='list-disc list-inside space-y-1'>";
            foreach ($errors as $msg) {
                echo "<li>$msg</li>";
            }
            echo "</ul></div>";
        }
        
        // Show current table structure
        echo "<div class='mt-6'>
                <h3 class='text-xl font-bold mb-4'><i class='fas fa-table mr-2'></i>Current Courses Table Structure:</h3>
                <div class='overflow-x-auto'>
                    <table class='w-full border-collapse border border-gray-300'>
                        <thead>
                            <tr class='bg-gray-50'>
                                <th class='border border-gray-300 px-4 py-2 text-left'>Column</th>
                                <th class='border border-gray-300 px-4 py-2 text-left'>Type</th>
                                <th class='border border-gray-300 px-4 py-2 text-left'>Default</th>
                            </tr>
                        </thead>
                        <tbody>";
        
        $stmt = $pdo->query("SHOW COLUMNS FROM courses");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "<tr>
                    <td class='border border-gray-300 px-4 py-2 font-semibold'>{$row['Field']}</td>
                    <td class='border border-gray-300 px-4 py-2'>{$row['Type']}</td>
                    <td class='border border-gray-300 px-4 py-2'>" . ($row['Default'] ?? 'NULL') . "</td>
                  </tr>";
        }
        
        echo "</tbody></table></div></div>";
    }
} catch (PDOException $e) {
    echo "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 px-4 py-3 rounded mb-4'>
            <i class='fas fa-exclamation-circle mr-2'></i>Database Error: " . htmlspecialchars($e->getMessage()) . "
          </div>";
}

echo "<div class='mt-6'>
        <a href='/Iqra-College/admin/index.php' 
           class='inline-block bg-blue-500 text-white px-6 py-2 rounded-lg font-semibold hover:bg-blue-600'>
            <i class='fas fa-arrow-left mr-2'></i>Back to Admin Dashboard
        </a>
      </div>
    </div>
</body>
</html>";

?>
