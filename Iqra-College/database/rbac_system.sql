-- ============================================
-- RBAC SYSTEM - Role-Based Access Control
-- Teacher-Course-Level Assignments & Student Level Management
-- ============================================

USE iqra;

-- ============================================
-- 1. Teacher Course & Level Assignments
-- ============================================
-- Admin assigns teachers to specific courses and levels
CREATE TABLE IF NOT EXISTS teacher_course_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    course_id INT NOT NULL,
    level ENUM('beginner', 'intermediate', 'advanced') NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    assigned_by INT COMMENT 'Admin who made the assignment',
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_assignment (teacher_id, course_id, level),
    INDEX idx_teacher (teacher_id),
    INDEX idx_course (course_id),
    INDEX idx_level (level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 2. Student Level Assignments
-- ============================================
-- Admin assigns students to levels after enrollment
-- This determines what content they can access
CREATE TABLE IF NOT EXISTS student_level_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    course_id INT NOT NULL,
    level ENUM('beginner', 'intermediate', 'advanced') NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    assigned_by INT COMMENT 'Admin who made the assignment',
    notes TEXT COMMENT 'Admin notes about the assignment',
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_student_course_level (student_id, course_id, level),
    INDEX idx_student (student_id),
    INDEX idx_course (course_id),
    INDEX idx_level (level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 3. Update Enrollments Table
-- ============================================
-- Add level assignment reference to enrollments
ALTER TABLE enrollments 
ADD COLUMN IF NOT EXISTS assigned_level ENUM('beginner', 'intermediate', 'advanced') NULL 
COMMENT 'Level assigned by admin after enrollment' AFTER progress,
ADD COLUMN IF NOT EXISTS enrollment_status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending' 
COMMENT 'Enrollment approval status' AFTER assigned_level,
ADD INDEX IF NOT EXISTS idx_assigned_level (assigned_level),
ADD INDEX IF NOT EXISTS idx_enrollment_status (enrollment_status);

-- ============================================
-- 4. Update Payments Table
-- ============================================
-- Add admin approval fields for cash payments
ALTER TABLE payments
ADD COLUMN IF NOT EXISTS approval_status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending' 
COMMENT 'Admin approval status for cash payments' AFTER status,
ADD COLUMN IF NOT EXISTS approved_by INT NULL 
COMMENT 'Admin who approved/rejected the payment' AFTER approval_status,
ADD COLUMN IF NOT EXISTS approved_at TIMESTAMP NULL 
COMMENT 'When payment was approved/rejected' AFTER approved_by,
ADD COLUMN IF NOT EXISTS rejection_reason TEXT NULL 
COMMENT 'Reason for rejection if rejected' AFTER approved_at,
ADD INDEX IF NOT EXISTS idx_approval_status (approval_status),
ADD FOREIGN KEY IF NOT EXISTS fk_approved_by (approved_by) REFERENCES users(id) ON DELETE SET NULL;

-- ============================================
-- 5. Update Users Table
-- ============================================
-- Ensure status field exists (should already exist)
ALTER TABLE users
MODIFY COLUMN IF EXISTS status ENUM('active', 'inactive') DEFAULT 'active' 
COMMENT 'User account status - admin can enable/disable';

-- ============================================
-- 6. Update Courses Table
-- ============================================
-- Ensure level field exists (should already exist)
ALTER TABLE courses
MODIFY COLUMN IF EXISTS level ENUM('beginner', 'intermediate', 'advanced') DEFAULT 'beginner';

-- ============================================
-- 7. Update Lessons Table
-- ============================================
-- Add level field to lessons so lessons can be assigned to specific levels
ALTER TABLE lessons
ADD COLUMN IF NOT EXISTS level ENUM('beginner', 'intermediate', 'advanced') NULL 
COMMENT 'Level this lesson belongs to (NULL = all levels)' AFTER course_id,
ADD INDEX IF NOT EXISTS idx_lesson_level (level);

-- ============================================
-- 8. Helper Views (Optional - for easier queries)
-- ============================================

-- View: Teachers with their assigned courses and levels
CREATE OR REPLACE VIEW v_teacher_assignments AS
SELECT 
    tca.id,
    tca.teacher_id,
    u.name as teacher_name,
    u.email as teacher_email,
    tca.course_id,
    c.title as course_title,
    tca.level,
    tca.assigned_at,
    admin.name as assigned_by_name
FROM teacher_course_assignments tca
JOIN users u ON tca.teacher_id = u.id
JOIN courses c ON tca.course_id = c.id
LEFT JOIN users admin ON tca.assigned_by = admin.id
WHERE u.role = 'teacher' AND u.status = 'active';

-- View: Students with their level assignments
CREATE OR REPLACE VIEW v_student_levels AS
SELECT 
    sla.id,
    sla.student_id,
    u.name as student_name,
    u.email as student_email,
    u.student_id as student_code,
    sla.course_id,
    c.title as course_title,
    sla.level,
    sla.assigned_at,
    admin.name as assigned_by_name,
    e.enrollment_status,
    e.progress
FROM student_level_assignments sla
JOIN users u ON sla.student_id = u.id
JOIN courses c ON sla.course_id = c.id
LEFT JOIN users admin ON sla.assigned_by = admin.id
LEFT JOIN enrollments e ON e.student_id = sla.student_id AND e.course_id = sla.course_id
WHERE u.role = 'student' AND u.status = 'active';

-- ============================================
-- 9. Sample Data (Optional - for testing)
-- ============================================
-- Uncomment to add sample assignments

/*
-- Example: Assign teacher to Beginner level of a course
INSERT INTO teacher_course_assignments (teacher_id, course_id, level, assigned_by)
SELECT 
    (SELECT id FROM users WHERE role = 'teacher' LIMIT 1),
    (SELECT id FROM courses LIMIT 1),
    'beginner',
    (SELECT id FROM users WHERE role = 'admin' LIMIT 1);
*/

-- ============================================
-- END OF RBAC SYSTEM SETUP
-- ============================================
