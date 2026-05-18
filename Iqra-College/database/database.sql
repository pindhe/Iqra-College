-- ============================================
-- IQRA COLLEGE LMS - COMPLETE DATABASE SETUP
-- Consolidated Database Schema
-- ============================================

CREATE DATABASE IF NOT EXISTS iqra COLLATE utf8mb4_unicode_ci;
USE iqra;

-- ============================================
-- CORE TABLES
-- ============================================

-- Users Table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(50) UNIQUE NULL COMMENT 'Auto-generated Student ID',
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'teacher', 'student', 'cashier') NOT NULL,
    phone VARCHAR(20),
    avatar VARCHAR(255),
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_role (role),
    INDEX idx_student_id (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Categories Table
CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    slug VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    icon VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Courses Table
CREATE TABLE IF NOT EXISTS courses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    slug VARCHAR(200) UNIQUE,
    description TEXT,
    category_id INT,
    teacher_id INT NOT NULL,
    thumbnail VARCHAR(255),
    banner_image VARCHAR(255),
    preview_video VARCHAR(255),
    level ENUM('beginner', 'intermediate', 'advanced') DEFAULT 'beginner',
    price DECIMAL(10,2) DEFAULT 0.00,
    discount_price DECIMAL(10,2) DEFAULT NULL,
    is_free TINYINT(1) DEFAULT 0,
    duration INT DEFAULT 0 COMMENT 'Duration in hours',
    access_days INT DEFAULT 0 COMMENT '0 = lifetime access',
    max_students INT DEFAULT NULL,
    enrolled_count INT DEFAULT 0,
    has_certificate TINYINT(1) DEFAULT 0,
    language VARCHAR(50) DEFAULT 'English',
    meta_title VARCHAR(255),
    meta_description VARCHAR(500),
    status ENUM('draft', 'published', 'archived') DEFAULT 'draft',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    INDEX idx_teacher (teacher_id),
    INDEX idx_category (category_id),
    INDEX idx_status (status),
    INDEX idx_level (level),
    INDEX idx_price (price)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sections Table
CREATE TABLE IF NOT EXISTS sections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    order_number INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    INDEX idx_course (course_id),
    INDEX idx_order (order_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Enrollments Table
CREATE TABLE IF NOT EXISTS enrollments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    course_id INT NOT NULL,
    enrolled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    progress INT DEFAULT 0 COMMENT 'Progress percentage 0-100',
    assigned_level ENUM('beginner', 'intermediate', 'advanced') NULL COMMENT 'Level assigned by admin after enrollment',
    enrollment_status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending' COMMENT 'Enrollment approval status',
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    UNIQUE KEY unique_enrollment (student_id, course_id),
    INDEX idx_student (student_id),
    INDEX idx_course (course_id),
    INDEX idx_assigned_level (assigned_level),
    INDEX idx_enrollment_status (enrollment_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lessons Table
CREATE TABLE IF NOT EXISTS lessons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT NOT NULL,
    section_id INT NULL,
    level ENUM('beginner', 'intermediate', 'advanced') NULL COMMENT 'Level this lesson belongs to (NULL = all levels)',
    title VARCHAR(200) NOT NULL,
    content TEXT,
    video_url VARCHAR(500),
    video_file VARCHAR(500) NULL,
    audio_file VARCHAR(500) NULL,
    document_file VARCHAR(500) NULL,
    lesson_type ENUM('Grammar', 'Writing', 'Reading', 'Listening') NOT NULL,
    media_type VARCHAR(50) DEFAULT 'text',
    order_number INT DEFAULT 0,
    duration INT DEFAULT 0 COMMENT 'Duration in minutes',
    is_free BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE SET NULL,
    INDEX idx_course (course_id),
    INDEX idx_order (order_number),
    INDEX idx_lesson_level (level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Materials Table
CREATE TABLE IF NOT EXISTS materials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lesson_id INT NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_type VARCHAR(50),
    file_size INT DEFAULT 0 COMMENT 'Size in bytes',
    download_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE,
    INDEX idx_lesson (lesson_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quizzes Table
CREATE TABLE IF NOT EXISTS quizzes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    duration INT DEFAULT 0 COMMENT 'Duration in minutes',
    passing_score INT DEFAULT 60 COMMENT 'Passing percentage',
    max_attempts INT DEFAULT 1,
    is_published BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    INDEX idx_course (course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Questions Table
CREATE TABLE IF NOT EXISTS questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quiz_id INT NOT NULL,
    question TEXT NOT NULL,
    option_a VARCHAR(255) NOT NULL,
    option_b VARCHAR(255) NOT NULL,
    option_c VARCHAR(255) NOT NULL,
    option_d VARCHAR(255) NOT NULL,
    correct_answer ENUM('a', 'b', 'c', 'd') NOT NULL,
    points INT DEFAULT 1,
    explanation TEXT,
    order_number INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE,
    INDEX idx_quiz (quiz_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quiz Attempts Table
CREATE TABLE IF NOT EXISTS quiz_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    quiz_id INT NOT NULL,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    score INT DEFAULT 0,
    total_questions INT NOT NULL DEFAULT 0,
    percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    time_taken INT DEFAULT 0 COMMENT 'Time taken in seconds',
    status ENUM('in_progress', 'completed', 'abandoned') DEFAULT 'in_progress',
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE,
    INDEX idx_student (student_id),
    INDEX idx_quiz (quiz_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Student Answers Table
CREATE TABLE IF NOT EXISTS student_answers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    attempt_id INT NOT NULL,
    question_id INT NOT NULL,
    student_answer ENUM('a', 'b', 'c', 'd') NOT NULL,
    is_correct BOOLEAN DEFAULT FALSE,
    points_earned INT DEFAULT 0,
    answered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (attempt_id) REFERENCES quiz_attempts(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE,
    UNIQUE KEY unique_answer (attempt_id, question_id),
    INDEX idx_attempt (attempt_id),
    INDEX idx_question (question_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lesson Progress Table
CREATE TABLE IF NOT EXISTS lesson_progress (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    lesson_id INT NOT NULL,
    completed BOOLEAN DEFAULT FALSE,
    completed_at TIMESTAMP NULL,
    time_spent INT DEFAULT 0 COMMENT 'Time spent in seconds',
    last_accessed TIMESTAMP NULL,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE,
    UNIQUE KEY unique_progress (student_id, lesson_id),
    INDEX idx_student (student_id),
    INDEX idx_lesson (lesson_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payments Table
CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    course_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_method VARCHAR(50) DEFAULT 'cash',
    payment_reference VARCHAR(255),
    phone_number VARCHAR(20) NULL,
    status ENUM('pending', 'verified', 'rejected') DEFAULT 'pending',
    approval_status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending' COMMENT 'Admin approval status for cash payments',
    verified_by INT NULL COMMENT 'Cashier who verified',
    verified_at TIMESTAMP NULL,
    approved_by INT NULL COMMENT 'Admin who approved/rejected the payment',
    approved_at TIMESTAMP NULL COMMENT 'When payment was approved/rejected',
    rejection_reason TEXT NULL COMMENT 'Reason for rejection if rejected',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_student (student_id),
    INDEX idx_course (course_id),
    INDEX idx_status (status),
    INDEX idx_verified_by (verified_by),
    INDEX idx_phone_number (phone_number),
    INDEX idx_approval_status (approval_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payment Verifications Table
CREATE TABLE IF NOT EXISTS payment_verifications (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Assignments Table
CREATE TABLE IF NOT EXISTS assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    instructions TEXT,
    due_date DATETIME,
    max_score INT DEFAULT 100,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    INDEX idx_course (course_id),
    INDEX idx_due_date (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Assignment Submissions Table
CREATE TABLE IF NOT EXISTS assignment_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    assignment_id INT NOT NULL,
    student_id INT NOT NULL,
    content TEXT,
    file_path VARCHAR(500),
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    graded_at TIMESTAMP NULL,
    score INT NULL,
    feedback TEXT,
    graded_by INT NULL COMMENT 'Teacher who graded',
    status ENUM('submitted', 'graded', 'returned') DEFAULT 'submitted',
    FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (graded_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_assignment (assignment_id),
    INDEX idx_student (student_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Certificates Table
CREATE TABLE IF NOT EXISTS certificates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    course_id INT NOT NULL,
    certificate_number VARCHAR(100) UNIQUE NOT NULL,
    issued_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expiry_date DATE NULL,
    pdf_path VARCHAR(500),
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    INDEX idx_student (student_id),
    INDEX idx_course (course_id),
    INDEX idx_number (certificate_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Messages Table
CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    receiver_id INT NOT NULL,
    subject VARCHAR(200),
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    read_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_sender (sender_id),
    INDEX idx_receiver (receiver_id),
    INDEX idx_read (is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notifications Table
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info', 'success', 'warning', 'error') DEFAULT 'info',
    link VARCHAR(500),
    is_read BOOLEAN DEFAULT FALSE,
    read_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_read (is_read),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Announcements Table
CREATE TABLE IF NOT EXISTS announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL COMMENT 'Admin or Teacher who created',
    course_id INT NULL COMMENT 'NULL = global announcement',
    title VARCHAR(200) NOT NULL,
    content TEXT NOT NULL,
    priority ENUM('low', 'medium', 'high') DEFAULT 'medium',
    is_published BOOLEAN DEFAULT FALSE,
    published_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_course (course_id),
    INDEX idx_published (is_published)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Settings Table
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    setting_type ENUM('string', 'number', 'boolean', 'json') DEFAULT 'string',
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Activity Logs Table
CREATE TABLE IF NOT EXISTS activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(100) NOT NULL,
    description TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user (user_id),
    INDEX idx_action (action),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Password Resets Table
CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100) NOT NULL,
    token VARCHAR(255) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    used BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_token (token),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Attendance Table
CREATE TABLE IF NOT EXISTS attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    course_id INT NOT NULL,
    lesson_id INT NULL,
    class_date DATE NOT NULL,
    status ENUM('present', 'absent', 'late', 'excused') DEFAULT 'absent',
    notes TEXT,
    marked_by INT NULL COMMENT 'Teacher or Admin who marked',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE SET NULL,
    FOREIGN KEY (marked_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_student (student_id),
    INDEX idx_course (course_id),
    INDEX idx_date (class_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reviews Table
CREATE TABLE IF NOT EXISTS reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    course_id INT NOT NULL,
    rating INT NOT NULL CHECK (rating >= 1 AND rating <= 5),
    comment TEXT,
    is_approved BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    UNIQUE KEY unique_review (student_id, course_id),
    INDEX idx_student (student_id),
    INDEX idx_course (course_id),
    INDEX idx_rating (rating)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Course Requirements Table
CREATE TABLE IF NOT EXISTS course_requirements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT NOT NULL,
    required_course_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (required_course_id) REFERENCES courses(id) ON DELETE CASCADE,
    UNIQUE KEY unique_requirement (course_id, required_course_id),
    INDEX idx_course (course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Course Outcomes Table
CREATE TABLE IF NOT EXISTS course_outcomes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT NOT NULL,
    outcome TEXT NOT NULL,
    order_number INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    INDEX idx_course (course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- ADDITIONAL FEATURE TABLES
-- ============================================

-- Voice Calls Table
CREATE TABLE IF NOT EXISTS voice_calls (
    id INT AUTO_INCREMENT PRIMARY KEY,
    caller_number VARCHAR(20),
    caller_name VARCHAR(255),
    call_sid VARCHAR(100) UNIQUE,
    status ENUM('incoming', 'answered', 'completed', 'failed') DEFAULT 'incoming',
    transcript TEXT,
    ai_response TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_call_sid (call_sid),
    INDEX idx_created (created_at),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Voice Call Messages Table
CREATE TABLE IF NOT EXISTS voice_call_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    call_sid VARCHAR(100),
    user_message TEXT,
    ai_response TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_call_sid (call_sid),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Teacher Course Assignments Table (RBAC System)
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

-- Student Level Assignments Table (RBAC System)
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
-- TABLE MODIFICATIONS (Safe ALTER statements)
-- ============================================

-- Ensure avatar column exists in users table
SET @column_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'iqra' 
    AND TABLE_NAME = 'users' 
    AND COLUMN_NAME = 'avatar'
);

SET @sql = IF(@column_exists = 0,
    'ALTER TABLE users ADD COLUMN avatar VARCHAR(255) NULL AFTER phone',
    'SELECT "Avatar column already exists" AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Ensure phone_number column exists in payments table
SET @dbname = DATABASE();
SET @tablename = 'payments';
SET @columnname = 'phone_number';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname)
  ) > 0,
  'SELECT 1', 
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' VARCHAR(20) NULL AFTER payment_reference')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add index for phone_number if column exists
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname)
  ) > 0,
  CONCAT('ALTER TABLE ', @tablename, ' ADD INDEX IF NOT EXISTS idx_phone_number (phone_number)'),
  'SELECT 1'
));
PREPARE addIndexIfExists FROM @preparedStatement;
EXECUTE addIndexIfExists;
DEALLOCATE PREPARE addIndexIfExists;

-- Ensure courses table has all required columns
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = 'iqra' AND TABLE_NAME = 'courses' AND COLUMN_NAME = 'slug') = 0,
    'ALTER TABLE courses ADD COLUMN slug VARCHAR(200) NULL AFTER title',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add unique index on slug if it doesn't exist
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
     WHERE TABLE_SCHEMA = 'iqra' AND TABLE_NAME = 'courses' AND INDEX_NAME = 'idx_slug') = 0,
    'CREATE UNIQUE INDEX idx_slug ON courses(slug)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================
-- VIEWS
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
-- INITIAL DATA
-- ============================================

-- Insert default admin users
INSERT INTO users (name, email, password, role) VALUES
('Pindhe', 'pindhe@gmail.com', '$2y$12$8HXyvcxeznR513PlB/1dY.SOAbwk.eyHWoyb2eqfutrENzw/C.ScG', 'admin'),
('haji', 'haji@gmail.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'cashier')
ON DUPLICATE KEY UPDATE 
    name = VALUES(name),
    password = VALUES(password),
    role = VALUES(role);

-- Insert default categories
INSERT INTO categories (name, slug, description, icon) VALUES
('English Grammar', 'english-grammar', 'Learn English grammar rules and structures', '📚'),
('Writing', 'writing', 'Improve your writing skills', '✍️'),
('Reading', 'reading', 'Enhance reading comprehension', '📖'),
('Listening', 'listening', 'Practice listening skills', '🎧')
ON DUPLICATE KEY UPDATE name=name;

-- ============================================
-- SETTINGS
-- ============================================

-- Site Settings
INSERT INTO settings (setting_key, setting_value, setting_type, description) VALUES
('site_name', 'Iqra College LMS', 'string', 'Site name'),
('site_email', 'admin@iqracollege.com', 'string', 'Site contact email'),
('enable_registration', 'true', 'boolean', 'Enable student registration'),
('enable_certificates', 'true', 'boolean', 'Enable certificate generation'),
('payment_phone', '+1234567890', 'string', 'Payment phone number where students should send course fees')
ON DUPLICATE KEY UPDATE setting_key=setting_key;

-- API Keys Settings
INSERT INTO settings (setting_key, setting_value, setting_type, description, created_at, updated_at) VALUES
('openai_api_key', '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', 'string', 'OpenAI API key for voice call system', NOW(), NOW()),
('deepseek_api_key', 'sk-2cd0e7f1665d4afd989f10c30586b324', 'string', 'DeepSeek API key for AI chat assistant', NOW(), NOW())
ON DUPLICATE KEY UPDATE 
    setting_value = VALUES(setting_value),
    description = VALUES(description),
    updated_at = NOW();

-- Voice Call Settings
INSERT INTO settings (setting_key, setting_value, created_at, updated_at) VALUES
('voice_call_number', '+1234567890', NOW(), NOW()),
('voice_call_enabled', '1', NOW(), NOW())
ON DUPLICATE KEY UPDATE updated_at = NOW();

-- WhatsApp API Settings (for password reset)
INSERT INTO settings (setting_key, setting_value, setting_type, description) VALUES
('whatsapp_api_url', '', 'string', 'WhatsApp API endpoint URL (e.g., https://api.example.com/whatsapp/send)'),
('whatsapp_api_key', '', 'string', 'WhatsApp API key or token'),
('whatsapp_api_type', 'webhook', 'string', 'WhatsApp API type: webhook, twilio, chatapi, whatsapp_business'),
('whatsapp_api_token', '', 'string', 'Additional token for Twilio (auth token)'),
('whatsapp_from_number', '', 'string', 'WhatsApp sender number (for Twilio)')
ON DUPLICATE KEY UPDATE 
    setting_value = VALUES(setting_value),
    description = VALUES(description);

-- ============================================
-- COMPLETION MESSAGE
-- ============================================

SELECT 'Iqra College LMS database setup completed successfully!' as status;
SELECT 'All tables, views, and initial data have been created.' as message;
