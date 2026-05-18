<?php
/**
 * Fix Database Structure
 * This script will check and fix the payments table structure
 * Run this in your browser: http://localhost/Iqra-College/database/fix_database.php
 */

require_once __DIR__ . '/../config/database.php';

$errors = [];
$success = [];

try {
    $pdo = getDBConnection();
    
    echo "<!DOCTYPE html><html><head><title>Database Fix</title><link rel=\"icon\" href=\"/Iqra-College/assets/images/iqra2.png\" type=\"image/png\">";
    echo "<style>body{font-family:Arial;max-width:800px;margin:50px auto;padding:20px;}";
    echo ".success{background:#d4edda;border:1px solid #c3e6cb;color:#155724;padding:15px;margin:10px 0;border-radius:5px;}";
    echo ".error{background:#f8d7da;border:1px solid #f5c6cb;color:#721c24;padding:15px;margin:10px 0;border-radius:5px;}";
    echo ".info{background:#d1ecf1;border:1px solid #bee5eb;color:#0c5460;padding:15px;margin:10px 0;border-radius:5px;}";
    echo "code{background:#f4f4f4;padding:2px 6px;border-radius:3px;}</style></head><body>";
    echo "<h1>Database Structure Fix</h1>";
    
    // Step 1: Check if payments table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'payments'");
    $tableExists = $stmt->rowCount() > 0;
    
    if (!$tableExists) {
        echo "<div class='error'>❌ Payments table does NOT exist. Creating it now...</div>";
        
        // Disable foreign key checks temporarily
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        
        // Create payments table
        $pdo->exec("
            CREATE TABLE payments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                course_id INT NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                payment_method VARCHAR(50) DEFAULT 'cash',
                payment_reference VARCHAR(255),
                phone_number VARCHAR(20) NULL,
                status ENUM('pending', 'verified', 'rejected') DEFAULT 'pending',
                verified_by INT NULL COMMENT 'Cashier who verified',
                verified_at TIMESTAMP NULL,
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
                FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_student (student_id),
                INDEX idx_course (course_id),
                INDEX idx_status (status),
                INDEX idx_verified_by (verified_by),
                INDEX idx_phone_number (phone_number)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        // Re-enable foreign key checks
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        
        echo "<div class='success'>✅ Payments table created successfully!</div>";
    } else {
        echo "<div class='info'>ℹ️ Payments table exists. Checking structure...</div>";
        
        // Check table structure
        $stmt = $pdo->query("DESCRIBE payments");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $requiredColumns = [
            'id', 'student_id', 'course_id', 'amount', 
            'payment_method', 'payment_reference', 'phone_number',
            'status', 'verified_by', 'verified_at', 'notes', 'created_at', 'updated_at'
        ];
        
        $missing = array_diff($requiredColumns, $columns);
        
        if (!empty($missing)) {
            echo "<div class='error'>❌ Missing columns: " . implode(', ', $missing) . "</div>";
            
            // Check if phone_number is missing (most common case)
            if (in_array('phone_number', $missing)) {
                echo "<div class='info'>Adding phone_number column...</div>";
                try {
                    $pdo->exec("ALTER TABLE payments ADD COLUMN phone_number VARCHAR(20) NULL AFTER payment_reference");
                    $pdo->exec("ALTER TABLE payments ADD INDEX idx_phone_number (phone_number)");
                    echo "<div class='success'>✅ phone_number column added successfully!</div>";
                    // Remove phone_number from missing list
                    $missing = array_diff($missing, ['phone_number']);
                } catch (PDOException $e) {
                    echo "<div class='error'>⚠️ Could not add phone_number: " . htmlspecialchars($e->getMessage()) . "</div>";
                }
            }
            
            // If other columns are missing, recreate table
            if (!empty($missing)) {
                echo "<div class='info'>Recreating table with correct structure...</div>";
                
                // Drop dependent tables first to avoid foreign key constraint errors
                $pdo->exec("DROP TABLE IF EXISTS payment_verifications");
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
                $pdo->exec("DROP TABLE IF EXISTS payments");
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
                $pdo->exec("
                    CREATE TABLE payments (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        student_id INT NOT NULL,
                        course_id INT NOT NULL,
                        amount DECIMAL(10,2) NOT NULL,
                        payment_method VARCHAR(50) DEFAULT 'cash',
                        payment_reference VARCHAR(255),
                        phone_number VARCHAR(20) NULL,
                        status ENUM('pending', 'verified', 'rejected') DEFAULT 'pending',
                        verified_by INT NULL COMMENT 'Cashier who verified',
                        verified_at TIMESTAMP NULL,
                        notes TEXT,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
                        FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
                        FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL,
                        INDEX idx_student (student_id),
                        INDEX idx_course (course_id),
                        INDEX idx_status (status),
                        INDEX idx_verified_by (verified_by),
                        INDEX idx_phone_number (phone_number)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
                
                // Re-enable foreign key checks
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
                
                echo "<div class='success'>✅ Payments table recreated with correct structure!</div>";
            }
        } else {
            // Check if phone_number exists specifically
            if (!in_array('phone_number', $columns)) {
                echo "<div class='info'>Adding phone_number column...</div>";
                try {
                    $pdo->exec("ALTER TABLE payments ADD COLUMN phone_number VARCHAR(20) NULL AFTER payment_reference");
                    $pdo->exec("ALTER TABLE payments ADD INDEX idx_phone_number (phone_number)");
                    echo "<div class='success'>✅ phone_number column added successfully!</div>";
                } catch (PDOException $e) {
                    echo "<div class='error'>⚠️ Could not add phone_number: " . htmlspecialchars($e->getMessage()) . "</div>";
                }
            } else {
                echo "<div class='success'>✅ Payments table structure is correct!</div>";
            }
        }
    }
    
    // Step 2: Check payment_verifications table
    $stmt = $pdo->query("SHOW TABLES LIKE 'payment_verifications'");
    if ($stmt->rowCount() == 0) {
        echo "<div class='info'>Creating payment_verifications table...</div>";
        
        $pdo->exec("
            CREATE TABLE payment_verifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                course_id INT NOT NULL,
                payment_id INT NOT NULL,
                verified_by INT NOT NULL COMMENT 'Cashier who verified',
                verified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                status ENUM('active', 'revoked') DEFAULT 'active',
                FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
                FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
                FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE CASCADE,
                UNIQUE KEY unique_student_course (student_id, course_id),
                INDEX idx_student (student_id),
                INDEX idx_course (course_id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        echo "<div class='success'>✅ Payment verifications table created!</div>";
    } else {
        echo "<div class='success'>✅ Payment verifications table exists!</div>";
    }
    
    // Step 3: Check if student_id column exists in users table
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'student_id'");
    if ($stmt->rowCount() == 0) {
        echo "<div class='info'>Adding student_id column to users table...</div>";
        
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN student_id VARCHAR(50) UNIQUE NULL AFTER id");
            $pdo->exec("ALTER TABLE users ADD INDEX idx_student_id (student_id)");
            echo "<div class='success'>✅ Student ID column added!</div>";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column') === false) {
                echo "<div class='error'>⚠️ Could not add student_id: " . htmlspecialchars($e->getMessage()) . "</div>";
            }
        }
    } else {
        echo "<div class='success'>✅ Student ID column exists!</div>";
    }
    
    // Step 4: Check cashier role
    $stmt = $pdo->query("SHOW COLUMNS FROM users WHERE Field = 'role'");
    $roleColumn = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($roleColumn && strpos($roleColumn['Type'], 'cashier') === false) {
        echo "<div class='info'>Adding cashier role to users table...</div>";
        
        try {
            $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'teacher', 'student', 'cashier') NOT NULL");
            echo "<div class='success'>✅ Cashier role added!</div>";
        } catch (PDOException $e) {
            echo "<div class='error'>⚠️ Could not add cashier role: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    } else {
        echo "<div class='success'>✅ Cashier role exists!</div>";
    }
    
    echo "<div class='success' style='margin-top:30px;'>";
    echo "<h2>✅ Database Fix Complete!</h2>";
    echo "<p>You can now try submitting a payment again.</p>";
    echo "<p><a href='/Iqra-College/student/courses.php' style='color:#155724;font-weight:bold;'>Go to Courses →</a></p>";
    echo "</div>";
    
} catch (PDOException $e) {
    echo "<div class='error'>";
    echo "<h2>❌ Error</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Please check your database connection in <code>config/database.php</code></p>";
    echo "</div>";
}

echo "</body></html>";
