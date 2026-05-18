<?php
/**
 * Helper Functions
 * Utility functions for the LMS
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Sanitize input data
 * @param string $data
 * @return string
 */
if (!function_exists('sanitize')) {
    function sanitize($data) {
        return htmlspecialchars(strip_tags(trim($data)));
    }
}

/**
 * Validate email
 * @param string $email
 * @return bool
 */
if (!function_exists('validateEmail')) {
    function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

/**
 * Get user by ID
 * @param int $userId
 * @return array|null
 */
function getUserById($userId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetch();
}

/**
 * Get all courses
 * @return array
 */
function getAllCourses() {
    $pdo = getDBConnection();
    try {
        $stmt = $pdo->query("SELECT c.*, u.name as teacher_name FROM courses c 
                            LEFT JOIN users u ON c.teacher_id = u.id 
                            ORDER BY c.created_at DESC");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        // If created_at doesn't exist, order by id
        if (strpos($e->getMessage(), 'created_at') !== false) {
            $stmt = $pdo->query("SELECT c.*, u.name as teacher_name FROM courses c 
                                LEFT JOIN users u ON c.teacher_id = u.id 
                                ORDER BY c.id DESC");
            return $stmt->fetchAll();
        }
        throw $e;
    }
}

/**
 * Get course by ID
 * @param int $courseId
 * @return array|null
 */
function getCourseById($courseId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT c.*, u.name as teacher_name FROM courses c 
                          LEFT JOIN users u ON c.teacher_id = u.id 
                          WHERE c.id = ?");
    $stmt->execute([$courseId]);
    return $stmt->fetch();
}

/**
 * Get courses by teacher
 * @param int $teacherId
 * @return array
 */
function getCoursesByTeacher($teacherId) {
    $pdo = getDBConnection();
    try {
        $stmt = $pdo->prepare("SELECT * FROM courses WHERE teacher_id = ? ORDER BY created_at DESC");
        $stmt->execute([$teacherId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        // If created_at doesn't exist, order by id
        if (strpos($e->getMessage(), 'created_at') !== false) {
            $stmt = $pdo->prepare("SELECT * FROM courses WHERE teacher_id = ? ORDER BY id DESC");
            $stmt->execute([$teacherId]);
            return $stmt->fetchAll();
        }
        throw $e;
    }
}

/**
 * Get enrolled courses for student
 * @param int $studentId
 * @return array
 */
function getEnrolledCourses($studentId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT c.*, u.name as teacher_name, e.enrolled_at 
                          FROM enrollments e 
                          JOIN courses c ON e.course_id = c.id 
                          LEFT JOIN users u ON c.teacher_id = u.id 
                          WHERE e.student_id = ? 
                          ORDER BY e.enrolled_at DESC");
    $stmt->execute([$studentId]);
    return $stmt->fetchAll();
}

/**
 * Check if student is enrolled in course
 * @param int $studentId
 * @param int $courseId
 * @return bool
 */
function isEnrolled($studentId, $courseId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT id FROM enrollments WHERE student_id = ? AND course_id = ?");
    $stmt->execute([$studentId, $courseId]);
    return $stmt->fetch() !== false;
}

/**
 * Get lessons for a course
 * @param int $courseId
 * @return array
 */
function getLessonsByCourse($courseId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM lessons WHERE course_id = ? ORDER BY id ASC");
    $stmt->execute([$courseId]);
    return $stmt->fetchAll();
}

/**
 * Get sections with lessons for a course
 * @param int $courseId
 * @return array
 */
function getSectionsWithLessons($courseId) {
    $pdo = getDBConnection();
    // Get sections
    $stmt = $pdo->prepare("SELECT * FROM sections WHERE course_id = ? ORDER BY order_number ASC, id ASC");
    $stmt->execute([$courseId]);
    $sections = $stmt->fetchAll();
    
    // Get all lessons for the course
    $stmt = $pdo->prepare("SELECT * FROM lessons WHERE course_id = ? ORDER BY id ASC");
    $stmt->execute([$courseId]);
    $allLessons = $stmt->fetchAll();
    
    // Organize lessons by section
    $lessonsBySection = [];
    $lessonsWithoutSection = [];
    
    foreach ($allLessons as $lesson) {
        if ($lesson['section_id']) {
            $lessonsBySection[$lesson['section_id']][] = $lesson;
        } else {
            $lessonsWithoutSection[] = $lesson;
        }
    }
    
    // Attach lessons to sections
    foreach ($sections as &$section) {
        $section['lessons'] = $lessonsBySection[$section['id']] ?? [];
    }
    
    // Add lessons without section as a pseudo-section
    if (!empty($lessonsWithoutSection)) {
        $sections[] = [
            'id' => null,
            'title' => 'Lessons',
            'lessons' => $lessonsWithoutSection
        ];
    }
    
    return $sections;
}

/**
 * Get lesson by ID
 * @param int $lessonId
 * @return array|null
 */
function getLessonById($lessonId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM lessons WHERE id = ?");
    $stmt->execute([$lessonId]);
    return $stmt->fetch();
}

/**
 * Check if lesson is completed by student
 * @param int $studentId
 * @param int $lessonId
 * @return bool
 */
function isLessonCompleted($studentId, $lessonId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT completed FROM lesson_progress WHERE student_id = ? AND lesson_id = ?");
    $stmt->execute([$studentId, $lessonId]);
    $result = $stmt->fetch();
    return $result && $result['completed'] == 1;
}

/**
 * Get next lesson in course
 * @param int $courseId
 * @param int $currentLessonId
 * @return array|null
 */
function getNextLesson($courseId, $currentLessonId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM lessons WHERE course_id = ? AND id > ? ORDER BY id ASC LIMIT 1");
    $stmt->execute([$courseId, $currentLessonId]);
    return $stmt->fetch();
}

/**
 * Check if section is completed by student
 * @param int $studentId
 * @param int $sectionId
 * @return bool
 */
function isSectionCompleted($studentId, $sectionId) {
    $pdo = getDBConnection();
    // Get all lessons for section
    $stmt = $pdo->prepare("SELECT id FROM lessons WHERE section_id = ?");
    $stmt->execute([$sectionId]);
    $sectionLessons = $stmt->fetchAll();
    
    if (empty($sectionLessons)) {
        return false; // No lessons in section
    }
    
    // Check if all lessons in section are completed
    $lessonIds = array_column($sectionLessons, 'id');
    $placeholders = str_repeat('?,', count($lessonIds) - 1) . '?';
    $stmt = $pdo->prepare("SELECT COUNT(*) as completed_count FROM lesson_progress 
                          WHERE student_id = ? AND lesson_id IN ($placeholders) AND completed = 1");
    $params = array_merge([$studentId], $lessonIds);
    $stmt->execute($params);
    $result = $stmt->fetch();
    
    return $result['completed_count'] == count($lessonIds);
}

/**
 * Check if course is completed by student
 * @param int $studentId
 * @param int $courseId
 * @return bool
 */
function isCourseCompleted($studentId, $courseId) {
    $pdo = getDBConnection();
    // Get all lessons for course
    $stmt = $pdo->prepare("SELECT id FROM lessons WHERE course_id = ?");
    $stmt->execute([$courseId]);
    $allLessons = $stmt->fetchAll();
    
    if (empty($allLessons)) {
        return false; // No lessons, course not completed
    }
    
    // Check if all lessons are completed
    $lessonIds = array_column($allLessons, 'id');
    $placeholders = str_repeat('?,', count($lessonIds) - 1) . '?';
    $stmt = $pdo->prepare("SELECT COUNT(*) as completed_count FROM lesson_progress 
                          WHERE student_id = ? AND lesson_id IN ($placeholders) AND completed = 1");
    $params = array_merge([$studentId], $lessonIds);
    $stmt->execute($params);
    $result = $stmt->fetch();
    
    return $result['completed_count'] == count($lessonIds);
}

/**
 * Get course progress percentage from lesson completion (auto-calculated)
 * @param int $studentId
 * @param int $courseId
 * @return int 0-100
 */
function getCourseProgressFromLessons($studentId, $courseId) {
    $pdo = getDBConnection();
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM lessons WHERE course_id = ?");
        $stmt->execute([$courseId]);
        $total = (int) ($stmt->fetch()['total'] ?? 0);
        if ($total === 0) {
            return 0;
        }
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as completed FROM lesson_progress lp
            JOIN lessons l ON lp.lesson_id = l.id
            WHERE l.course_id = ? AND lp.student_id = ? AND lp.completed = 1
        ");
        $stmt->execute([$courseId, $studentId]);
        $completed = (int) ($stmt->fetch()['completed'] ?? 0);
        return (int) round(($completed / $total) * 100);
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * Generate certificate for completed course
 * @param int $studentId
 * @param int $courseId
 * @return int|null Certificate ID
 */
function generateCertificate($studentId, $courseId) {
    $pdo = getDBConnection();
    
    // Check if certificate already exists
    $stmt = $pdo->prepare("SELECT id FROM certificates WHERE student_id = ? AND course_id = ?");
    $stmt->execute([$studentId, $courseId]);
    $existing = $stmt->fetch();
    if ($existing) {
        return $existing['id'];
    }
    
    // Generate certificate number
    $certificateNumber = 'IQRA-' . date('Y') . '-' . str_pad($studentId, 4, '0', STR_PAD_LEFT) . '-' . str_pad($courseId, 4, '0', STR_PAD_LEFT);
    
    // Insert certificate
    try {
        // Try with certificate_number first
        try {
            $stmt = $pdo->prepare("INSERT INTO certificates (student_id, course_id, certificate_number) VALUES (?, ?, ?)");
            $stmt->execute([$studentId, $courseId, $certificateNumber]);
            return $pdo->lastInsertId();
        } catch (PDOException $e) {
            // If certificate_number column doesn't exist, insert without it
            if (strpos($e->getMessage(), 'certificate_number') !== false) {
                $stmt = $pdo->prepare("INSERT INTO certificates (student_id, course_id) VALUES (?, ?)");
                $stmt->execute([$studentId, $courseId]);
                return $pdo->lastInsertId();
            }
            // If certificate number already exists, try again with timestamp
            $certificateNumber = 'IQRA-' . date('YmdHis') . '-' . $studentId . '-' . $courseId;
            $stmt = $pdo->prepare("INSERT INTO certificates (student_id, course_id, certificate_number) VALUES (?, ?, ?)");
            $stmt->execute([$studentId, $courseId, $certificateNumber]);
            return $pdo->lastInsertId();
        }
    } catch (PDOException $e) {
        // Final fallback - insert without certificate_number
        $stmt = $pdo->prepare("INSERT INTO certificates (student_id, course_id) VALUES (?, ?)");
        $stmt->execute([$studentId, $courseId]);
        return $pdo->lastInsertId();
    }
}

/**
 * Get materials for a lesson
 * @param int $lessonId
 * @return array
 */
function getMaterialsByLesson($lessonId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM materials WHERE lesson_id = ?");
    $stmt->execute([$lessonId]);
    return $stmt->fetchAll();
}

/**
 * Get quizzes for a course
 * @param int $courseId
 * @return array
 */
function getQuizzesByCourse($courseId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM quizzes WHERE course_id = ? ORDER BY created_at DESC");
    $stmt->execute([$courseId]);
    return $stmt->fetchAll();
}

/**
 * Get quiz by ID
 * @param int $quizId
 * @return array|null
 */
function getQuizById($quizId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM quizzes WHERE id = ?");
    $stmt->execute([$quizId]);
    return $stmt->fetch();
}

/**
 * Get questions for a quiz
 * @param int $quizId
 * @return array
 */
function getQuestionsByQuiz($quizId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM questions WHERE quiz_id = ? ORDER BY id ASC");
    $stmt->execute([$quizId]);
    return $stmt->fetchAll();
}

/**
 * Get quiz attempt for student
 * @param int $studentId
 * @param int $quizId
 * @return array|null
 */
function getQuizAttempt($studentId, $quizId) {
    $pdo = getDBConnection();
    try {
        // Try with status column first
        $stmt = $pdo->prepare("SELECT * FROM quiz_attempts WHERE student_id = ? AND quiz_id = ? AND status = 'completed' ORDER BY completed_at DESC LIMIT 1");
        $stmt->execute([$studentId, $quizId]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        $errorMsg = $e->getMessage();
        // If status column doesn't exist, try completed_at
        if (strpos($errorMsg, 'status') !== false) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM quiz_attempts WHERE student_id = ? AND quiz_id = ? AND completed_at IS NOT NULL ORDER BY completed_at DESC LIMIT 1");
                $stmt->execute([$studentId, $quizId]);
                return $stmt->fetch();
            } catch (PDOException $e2) {
                // If completed_at also doesn't exist, just get the latest attempt
                if (strpos($e2->getMessage(), 'completed_at') !== false) {
                    $stmt = $pdo->prepare("SELECT * FROM quiz_attempts WHERE student_id = ? AND quiz_id = ? ORDER BY id DESC LIMIT 1");
                    $stmt->execute([$studentId, $quizId]);
                    return $stmt->fetch();
                }
                throw $e2;
            }
        }
        // If completed_at column doesn't exist (without status error)
        if (strpos($errorMsg, 'completed_at') !== false) {
            $stmt = $pdo->prepare("SELECT * FROM quiz_attempts WHERE student_id = ? AND quiz_id = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$studentId, $quizId]);
            return $stmt->fetch();
        }
        throw $e;
    }
}

/**
 * Get all quiz attempts for student
 * @param int $studentId
 * @return array
 */
function getStudentQuizAttempts($studentId) {
    $pdo = getDBConnection();
    try {
        // Try with status column first
        $stmt = $pdo->prepare("SELECT qa.*, q.title as quiz_title, c.title as course_title 
                              FROM quiz_attempts qa 
                              JOIN quizzes q ON qa.quiz_id = q.id 
                              JOIN courses c ON q.course_id = c.id 
                              WHERE qa.student_id = ? AND qa.status = 'completed'
                              ORDER BY qa.completed_at DESC");
        $stmt->execute([$studentId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        $errorMsg = $e->getMessage();
        // If status column doesn't exist, try completed_at
        if (strpos($errorMsg, 'status') !== false) {
            try {
                $stmt = $pdo->prepare("SELECT qa.*, q.title as quiz_title, c.title as course_title 
                                      FROM quiz_attempts qa 
                                      JOIN quizzes q ON qa.quiz_id = q.id 
                                      JOIN courses c ON q.course_id = c.id 
                                      WHERE qa.student_id = ? AND qa.completed_at IS NOT NULL
                                      ORDER BY qa.completed_at DESC");
                $stmt->execute([$studentId]);
                return $stmt->fetchAll();
            } catch (PDOException $e2) {
                // If completed_at also doesn't exist, just get all attempts
                if (strpos($e2->getMessage(), 'completed_at') !== false) {
                    $stmt = $pdo->prepare("SELECT qa.*, q.title as quiz_title, c.title as course_title 
                                          FROM quiz_attempts qa 
                                          JOIN quizzes q ON qa.quiz_id = q.id 
                                          JOIN courses c ON q.course_id = c.id 
                                          WHERE qa.student_id = ?
                                          ORDER BY qa.id DESC");
                    $stmt->execute([$studentId]);
                    return $stmt->fetchAll();
                }
                throw $e2;
            }
        }
        // If completed_at column doesn't exist (without status error)
        if (strpos($errorMsg, 'completed_at') !== false) {
            $stmt = $pdo->prepare("SELECT qa.*, q.title as quiz_title, c.title as course_title 
                                  FROM quiz_attempts qa 
                                  JOIN quizzes q ON qa.quiz_id = q.id 
                                  JOIN courses c ON q.course_id = c.id 
                                  WHERE qa.student_id = ?
                                  ORDER BY qa.id DESC");
            $stmt->execute([$studentId]);
            return $stmt->fetchAll();
        }
        throw $e;
    }
}

/**
 * Get sections by course
 * @param int $courseId
 * @return array
 */
function getSectionsByCourse($courseId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM sections WHERE course_id = ? ORDER BY order_number ASC, id ASC");
    $stmt->execute([$courseId]);
    return $stmt->fetchAll();
}

/**
 * Get section by ID
 * @param int $sectionId
 * @return array|null
 */
function getSectionById($sectionId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM sections WHERE id = ?");
    $stmt->execute([$sectionId]);
    return $stmt->fetch();
}

/**
 * Upload file helper function
 * @param array $file $_FILES array element
 * @param string $uploadDir Directory to upload to
 * @param array $allowedTypes Array of allowed file extensions
 * @param int $maxSize Maximum file size in bytes (default 50MB)
 * @return string|false|null Returns filename on success, false on invalid type, null on error
 */
if (!function_exists('uploadFile')) {
    function uploadFile($file, $uploadDir, $allowedTypes, $maxSize = 52428800) {
        if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
            return null;
        }
        
        $fileName = $file['name'];
        $fileTmp = $file['tmp_name'];
        $fileSize = $file['size'];
        $fileType = $file['type'];
        
        // Check file size
        if ($fileSize > $maxSize) {
            return false;
        }
        
        // Check file type
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedTypes)) {
            return false;
        }
        
        // Generate unique filename
        $uniqueName = uniqid() . '_' . time() . '.' . $ext;
        $uploadPath = $uploadDir . '/' . $uniqueName;
        
        // Create directory if it doesn't exist
        if (!is_dir($uploadDir)) {
            if (!@mkdir($uploadDir, 0755, true)) {
                error_log("Failed to create upload directory: $uploadDir");
                return null;
            }
        }
        
        // Check if directory is writable
        if (!is_writable($uploadDir)) {
            error_log("Upload directory is not writable: $uploadDir");
            return null;
        }
        
        if (move_uploaded_file($fileTmp, $uploadPath)) {
            return $uniqueName;
        } else {
            error_log("Failed to move uploaded file from $fileTmp to $uploadPath");
            return null;
        }
    }
}

/**
 * Get dashboard statistics
 * @return array
 */
function getDashboardStats() {
    $pdo = getDBConnection();
    
    $stats = [];
    
    try {
        // Total courses
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM courses");
        $stats['total_courses'] = $stmt->fetch()['count'];
        
        // Total students
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE role = 'student'");
        $stats['total_students'] = $stmt->fetch()['count'];
        
        // Total teachers
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE role = 'teacher'");
        $stats['total_teachers'] = $stmt->fetch()['count'];
        
        // Total quizzes
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM quizzes");
        $stats['total_quizzes'] = $stmt->fetch()['count'];
    } catch (PDOException $e) {
        // Return default values on error
        $stats = [
            'total_courses' => 0,
            'total_students' => 0,
            'total_teachers' => 0,
            'total_quizzes' => 0
        ];
    }
    
    return $stats;
}

/**
 * Generate unique Student ID
 * Format: STU-YYYY-XXXXX (e.g., STU-2024-00001)
 * @param PDO $pdo
 * @return string
 */
function generateStudentId($pdo) {
    $year = date('Y');
    $prefix = "STU-{$year}-";
    
    // Get the last student ID for this year
    try {
        $stmt = $pdo->prepare("SELECT student_id FROM users WHERE student_id LIKE ? ORDER BY student_id DESC LIMIT 1");
        $stmt->execute([$prefix . '%']);
        $lastId = $stmt->fetchColumn();
        
        if ($lastId) {
            // Extract the number part and increment
            $number = intval(substr($lastId, strlen($prefix))) + 1;
        } else {
            $number = 1;
        }
        
        // Format with leading zeros (5 digits)
        $studentId = $prefix . str_pad($number, 5, '0', STR_PAD_LEFT);
        
        // Double-check uniqueness (in case of race condition)
        $stmt = $pdo->prepare("SELECT id FROM users WHERE student_id = ?");
        $stmt->execute([$studentId]);
        if ($stmt->fetch()) {
            // If exists, try next number
            $number++;
            $studentId = $prefix . str_pad($number, 5, '0', STR_PAD_LEFT);
        }
        
        return $studentId;
    } catch (PDOException $e) {
        // Fallback: use timestamp-based ID
        return $prefix . str_pad(rand(10000, 99999), 5, '0', STR_PAD_LEFT);
    }
}

/**
 * Check if student has paid for a course
 * @param int $studentId
 * @param int $courseId
 * @return bool
 */
function hasPaidForCourse($studentId, $courseId) {
    $pdo = getDBConnection();
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM payment_verifications 
            WHERE student_id = ? AND course_id = ? AND status = 'active'
        ");
        $stmt->execute([$studentId, $courseId]);
        return $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Get student payment status for all courses
 * @param int $studentId
 * @return array
 */
function getStudentPaymentStatus($studentId) {
    $pdo = getDBConnection();
    try {
        $stmt = $pdo->prepare("
            SELECT course_id, status, verified_at 
            FROM payment_verifications 
            WHERE student_id = ? AND status = 'active'
        ");
        $stmt->execute([$studentId]);
        $result = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $result[$row['course_id']] = $row;
        }
        return $result;
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Get system setting value
 * @param string $key
 * @param mixed $default
 * @return mixed
 */
function getSetting($key, $default = null) {
    $pdo = getDBConnection();
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetchColumn();
        return $result !== false ? $result : $default;
    } catch (PDOException $e) {
        return $default;
    }
}

/**
 * Get user code (student_id) for a user
 * @param int $userId
 * @return string|null
 */
function getUserCode($userId) {
    $pdo = getDBConnection();
    try {
        $stmt = $pdo->prepare("SELECT student_id FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $result = $stmt->fetchColumn();
        return $result !== false ? $result : null;
    } catch (PDOException $e) {
        return null;
    }
}

// ============================================
// RBAC HELPER FUNCTIONS
// ============================================

/**
 * Check if teacher is assigned to a course and level
 * @param int $teacherId
 * @param int $courseId
 * @param string $level
 * @return bool
 */
function isTeacherAssignedToCourseLevel($teacherId, $courseId, $level) {
    $pdo = getDBConnection();
    try {
        $stmt = $pdo->prepare("SELECT id FROM teacher_course_assignments 
                              WHERE teacher_id = ? AND course_id = ? AND level = ?");
        $stmt->execute([$teacherId, $courseId, $level]);
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        // Table might not exist yet - return true for backward compatibility
        error_log("teacher_course_assignments table check: " . $e->getMessage());
        return true; // Allow access if table doesn't exist
    }
}

/**
 * Get all courses and levels assigned to a teacher
 * @param int $teacherId
 * @return array
 */
function getTeacherAssignments($teacherId) {
    $pdo = getDBConnection();
    try {
        $stmt = $pdo->prepare("SELECT tca.*, c.title as course_title, c.thumbnail 
                              FROM teacher_course_assignments tca
                              JOIN courses c ON tca.course_id = c.id
                              WHERE tca.teacher_id = ?
                              ORDER BY c.title, tca.level");
        $stmt->execute([$teacherId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        // Table might not exist - return empty array
        error_log("getTeacherAssignments error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get courses accessible to a teacher (only assigned courses)
 * @param int $teacherId
 * @return array
 */
function getTeacherAccessibleCourses($teacherId) {
    $pdo = getDBConnection();
    try {
        // Check if teacher_course_assignments table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'teacher_course_assignments'");
        if ($stmt->rowCount() > 0) {
            // Use assignments table
            $stmt = $pdo->prepare("SELECT DISTINCT c.*, u.name as teacher_name
                                  FROM courses c
                                  JOIN teacher_course_assignments tca ON c.id = tca.course_id
                                  LEFT JOIN users u ON c.teacher_id = u.id
                                  WHERE tca.teacher_id = ?
                                  ORDER BY c.title");
            $stmt->execute([$teacherId]);
            return $stmt->fetchAll();
        } else {
            // Fallback: return all courses created by teacher (backward compatibility)
            return getCoursesByTeacher($teacherId);
        }
    } catch (PDOException $e) {
        error_log("getTeacherAccessibleCourses error: " . $e->getMessage());
        return getCoursesByTeacher($teacherId);
    }
}

/**
 * Get students enrolled in a specific course and level
 * @param int $courseId
 * @param string $level
 * @return array
 */
function getStudentsByCourseLevel($courseId, $level) {
    $pdo = getDBConnection();
    try {
        $stmt = $pdo->prepare("SELECT DISTINCT u.*, e.progress, e.enrolled_at, sla.assigned_at as level_assigned_at
                              FROM users u
                              JOIN enrollments e ON u.id = e.student_id
                              JOIN student_level_assignments sla ON u.id = sla.student_id AND e.course_id = sla.course_id
                              WHERE e.course_id = ? AND sla.level = ? AND u.role = 'student' AND u.status = 'active'
                              ORDER BY u.name");
        $stmt->execute([$courseId, $level]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        // If table doesn't exist, return empty array
        error_log("getStudentsByCourseLevel error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get student's assigned level for a course
 * @param int $studentId
 * @param int $courseId
 * @return string|null
 */
function getStudentAssignedLevel($studentId, $courseId) {
    $pdo = getDBConnection();
    try {
        $stmt = $pdo->prepare("SELECT level FROM student_level_assignments 
                              WHERE student_id = ? AND course_id = ?");
        $stmt->execute([$studentId, $courseId]);
        $result = $stmt->fetchColumn();
        return $result !== false ? $result : null;
    } catch (PDOException $e) {
        // Check enrollments table for backward compatibility
        try {
            $stmt = $pdo->prepare("SELECT assigned_level FROM enrollments 
                                  WHERE student_id = ? AND course_id = ?");
            $stmt->execute([$studentId, $courseId]);
            $result = $stmt->fetchColumn();
            return $result !== false ? $result : null;
        } catch (PDOException $e2) {
            error_log("getStudentAssignedLevel error: " . $e2->getMessage());
            return null;
        }
    }
}

/**
 * Check if student can access a lesson based on their assigned level
 * @param int $studentId
 * @param int $courseId
 * @param string $lessonLevel Lesson's level (null = accessible to all)
 * @return bool
 */
function canStudentAccessLesson($studentId, $courseId, $lessonLevel) {
    // If lesson has no level restriction, allow access
    if (empty($lessonLevel)) {
        return true;
    }
    
    $studentLevel = getStudentAssignedLevel($studentId, $courseId);
    if (empty($studentLevel)) {
        return false; // No level assigned yet
    }
    
    // Level hierarchy: beginner < intermediate < advanced
    $levelOrder = ['beginner' => 1, 'intermediate' => 2, 'advanced' => 3];
    $studentOrder = $levelOrder[$studentLevel] ?? 0;
    $lessonOrder = $levelOrder[$lessonLevel] ?? 0;
    
    // Student can only access lessons at or below their assigned level
    return $studentOrder >= $lessonOrder;
}

/**
 * Assign teacher to course and level (Admin only)
 * @param int $teacherId
 * @param int $courseId
 * @param string $level
 * @param int $assignedBy Admin ID
 * @return bool
 */
function assignTeacherToCourseLevel($teacherId, $courseId, $level, $assignedBy) {
    $pdo = getDBConnection();
    try {
        $stmt = $pdo->prepare("INSERT INTO teacher_course_assignments 
                              (teacher_id, course_id, level, assigned_by) 
                              VALUES (?, ?, ?, ?)
                              ON DUPLICATE KEY UPDATE assigned_by = ?, assigned_at = CURRENT_TIMESTAMP");
        return $stmt->execute([$teacherId, $courseId, $level, $assignedBy, $assignedBy]);
    } catch (PDOException $e) {
        error_log("assignTeacherToCourseLevel error: " . $e->getMessage());
        return false;
    }
}

/**
 * Assign student to level for a course (Admin only)
 * @param int $studentId
 * @param int $courseId
 * @param string $level
 * @param int $assignedBy Admin ID
 * @param string $notes Optional notes
 * @return bool
 */
function assignStudentToLevel($studentId, $courseId, $level, $assignedBy, $notes = '') {
    $pdo = getDBConnection();
    try {
        // Insert into student_level_assignments
        $stmt = $pdo->prepare("INSERT INTO student_level_assignments 
                              (student_id, course_id, level, assigned_by, notes) 
                              VALUES (?, ?, ?, ?, ?)
                              ON DUPLICATE KEY UPDATE level = ?, assigned_by = ?, assigned_at = CURRENT_TIMESTAMP, notes = ?");
        $result = $stmt->execute([$studentId, $courseId, $level, $assignedBy, $notes, $level, $assignedBy, $notes]);
        
        // Also update enrollments table
        try {
            $stmt = $pdo->prepare("UPDATE enrollments 
                                  SET assigned_level = ?, enrollment_status = 'approved'
                                  WHERE student_id = ? AND course_id = ?");
            $stmt->execute([$level, $studentId, $courseId]);
        } catch (PDOException $e) {
            // Column might not exist yet
            error_log("Update enrollments.assigned_level error: " . $e->getMessage());
        }
        
        return $result;
    } catch (PDOException $e) {
        error_log("assignStudentToLevel error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get all students waiting for level assignment
 * @return array
 */
function getStudentsPendingLevelAssignment() {
    $pdo = getDBConnection();
    try {
        $stmt = $pdo->query("SELECT DISTINCT e.student_id, e.course_id, u.name as student_name, 
                            u.email, u.student_id as student_code, c.title as course_title,
                            e.enrolled_at, e.enrollment_status
                            FROM enrollments e
                            JOIN users u ON e.student_id = u.id
                            JOIN courses c ON e.course_id = c.id
                            LEFT JOIN student_level_assignments sla ON e.student_id = sla.student_id AND e.course_id = sla.course_id
                            WHERE u.role = 'student' AND u.status = 'active'
                            AND (sla.id IS NULL OR e.enrollment_status = 'pending')
                            ORDER BY e.enrolled_at DESC");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("getStudentsPendingLevelAssignment error: " . $e->getMessage());
        return [];
    }
}
