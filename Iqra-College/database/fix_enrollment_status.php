<?php
/**
 * Fix: Add enrollment_status (and assigned_level) to enrollments table
 * Run this if free course enrollment fails with "Unknown column 'enrollment_status'"
 * or if rbac_system.sql has not been run.
 *
 * Run from browser: http://localhost/Iqra-College/database/fix_enrollment_status.php
 * Or from CLI: php database/fix_enrollment_status.php
 */

require_once __DIR__ . '/../config/database.php';

$isCli = (php_sapi_name() === 'cli');

function out($msg, $isCli) {
    if ($isCli) {
        echo $msg . "\n";
    } else {
        echo nl2br(htmlspecialchars($msg)) . "<br>";
    }
}

try {
    $pdo = getDBConnection();

    // 1) enrollment_status
    $stmt = $pdo->query("SHOW COLUMNS FROM enrollments LIKE 'enrollment_status'");
    $has = $stmt && $stmt->rowCount() > 0;
    if (!$has) {
        $pdo->exec("ALTER TABLE enrollments ADD COLUMN enrollment_status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending' COMMENT 'Enrollment approval status' AFTER progress");
        out("✅ Added enrollments.enrollment_status", $isCli);
    } else {
        out("ℹ️ enrollments.enrollment_status already exists.", $isCli);
    }

    // 2) assigned_level (optional, for RBAC)
    $stmt = $pdo->query("SHOW COLUMNS FROM enrollments LIKE 'assigned_level'");
    $has = $stmt && $stmt->rowCount() > 0;
    if (!$has) {
        $pdo->exec("ALTER TABLE enrollments ADD COLUMN assigned_level ENUM('beginner', 'intermediate', 'advanced') NULL COMMENT 'Level assigned by admin' AFTER progress");
        out("✅ Added enrollments.assigned_level", $isCli);
    } else {
        out("ℹ️ enrollments.assigned_level already exists.", $isCli);
    }

    out("Done. Free course enrollment should work. Re-try 'Enroll & Start' on a free course.", $isCli);

} catch (PDOException $e) {
    out("❌ Error: " . $e->getMessage(), $isCli);
    out("\nYou can run this SQL manually in phpMyAdmin or MySQL:", $isCli);
    out("ALTER TABLE enrollments ADD COLUMN enrollment_status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending' AFTER progress;", $isCli);
    out("ALTER TABLE enrollments ADD COLUMN assigned_level ENUM('beginner', 'intermediate', 'advanced') NULL AFTER progress;", $isCli);
}
