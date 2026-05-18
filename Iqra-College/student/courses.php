<?php
/**
 * Student - Courses (Browse, Enroll & View Details)
 * Unified page for browsing courses and viewing course details
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

requireRole('student');

$studentId = getCurrentUserId();
$pdo = getDBConnection();
$name = getCurrentUserName();
$studentCode = getUserCode($studentId);
$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($_GET['error']) && $error === '') {
    if ($_GET['error'] === 'not_enrolled') {
        $error = 'You must be enrolled in that course to view its details.';
    } else {
        $error = 'An error occurred. Please try again.';
    }
}

// Check if viewing a specific course detail
$viewMode = 'list'; // 'list' or 'detail'
$courseId = intval($_GET['id'] ?? 0);

// Handle free course enrollment (auto-start: enroll immediately and go to course)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'enroll_free') {
    $enrollCourseId = intval($_POST['course_id'] ?? 0);
    if ($enrollCourseId > 0) {
        try {
            $stmt = $pdo->prepare("SELECT id, is_free, price FROM courses WHERE id = ? AND status = 'published'");
            $stmt->execute([$enrollCourseId]);
            $courseRow = $stmt->fetch();
            if ($courseRow) {
                $isFreeCourse = ((int)($courseRow['is_free'] ?? 0) === 1) || (floatval($courseRow['price'] ?? 0) == 0);
                if (!$isFreeCourse) {
                    $error = 'This course is not free. Please use the payment option to enroll.';
                } else {
                    $stmt = $pdo->prepare("SELECT id FROM enrollments WHERE student_id = ? AND course_id = ?");
                    $stmt->execute([$studentId, $enrollCourseId]);
                    if ($stmt->fetch()) {
                        $error = 'You are already enrolled in this course.';
                    } else {
                        // enrollment_status may not exist if rbac_system.sql wasn't run
                        $hasStatus = false;
                        try {
                            $chk = $pdo->query("SHOW COLUMNS FROM enrollments LIKE 'enrollment_status'");
                            $hasStatus = $chk && $chk->rowCount() > 0;
                        } catch (PDOException $e) { /* ignore */ }
                        if ($hasStatus) {
                            $stmt = $pdo->prepare("INSERT INTO enrollments (student_id, course_id, enrollment_status) VALUES (?, ?, 'approved')");
                        } else {
                            $stmt = $pdo->prepare("INSERT INTO enrollments (student_id, course_id) VALUES (?, ?)");
                        }
                        $stmt->execute([$studentId, $enrollCourseId]);
                        header('Location: courses.php?id=' . $enrollCourseId . '&enrolled=1');
                        exit;
                    }
                }
            } else {
                $error = 'Course not found.';
            }
        } catch (PDOException $e) {
            error_log("Free enrollment error: " . $e->getMessage());
            $error = 'Failed to enroll. Please try again.';
        }
    } else {
        $error = 'Invalid course.';
    }
}

// Handle payment submission (paid courses) — creates payment (pending); cashier verifies then enrolls
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'enroll') {
    $payCourseId = intval($_POST['course_id'] ?? 0);
    $phoneNumber = sanitize($_POST['phone_number'] ?? '');
    $paymentMethod = sanitize($_POST['payment_method'] ?? 'cash');
    $paymentReference = sanitize($_POST['payment_reference'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    
    if ($payCourseId > 0 && $amount > 0) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM courses WHERE id = ?");
            $stmt->execute([$payCourseId]);
            $course = $stmt->fetch();
            
            if ($course) {
                $stmt = $pdo->prepare("SELECT id FROM enrollments WHERE student_id = ? AND course_id = ?");
                $stmt->execute([$studentId, $payCourseId]);
                if ($stmt->fetch()) {
                    $error = 'You are already enrolled in this course.';
                } else {
                    try {
                        $stmt = $pdo->prepare("INSERT INTO payments (student_id, course_id, amount, payment_method, payment_reference, phone_number, status, notes) 
                                              VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)");
                        $notes = "Payment for course: " . $course['title'];
                        $stmt->execute([$studentId, $payCourseId, $amount, $paymentMethod, $paymentReference, $phoneNumber, $notes]);
                        header('Location: courses.php?id=' . $payCourseId . '&pay=submitted');
                        exit;
                    } catch (PDOException $e) {
                        error_log("Payment submission error: " . $e->getMessage());
                        $error = 'Failed to submit payment. Please try again.';
                    }
                }
            } else {
                $error = 'Course not found.';
            }
        } catch (PDOException $e) {
            $error = 'Failed to process. Please try again.';
        }
    } else {
        $error = 'Please fill in amount and required fields.';
    }
}

// Success message when redirected after free enrollment
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['enrolled']) && $_GET['enrolled'] === '1' && intval($_GET['id'] ?? 0) > 0) {
    $success = 'You\'re enrolled! You can start the course below.';
}

// If course ID is provided, show detail view
if ($courseId > 0) {
    $course = getCourseById($courseId);
    if (!$course) {
        header('Location: courses.php');
        exit();
    }
    if (!empty($course['category_id'])) {
        try {
            $st = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
            $st->execute([$course['category_id']]);
            $course['category_name'] = $st->fetch()['name'] ?? null;
        } catch (PDOException $e) {
            $course['category_name'] = null;
        }
    } else {
        $course['category_name'] = null;
    }

    $viewMode = 'detail';
    
    // Not enrolled: show course info + Pay & Enroll form, or "pending" if payment submitted
    if (!isEnrolled($studentId, $courseId)) {
        $showEnrollOrPending = true;
        $hasPendingPayment = false;
        try {
            $st = $pdo->prepare("SELECT id FROM payments WHERE student_id = ? AND course_id = ? AND status = 'pending' LIMIT 1");
            $st->execute([$studentId, $courseId]);
            $hasPendingPayment = $st->fetch() !== false;
        } catch (PDOException $e) { /* ignore */ }
        if (isset($_GET['pay']) && $_GET['pay'] === 'submitted') {
            $success = 'Payment submitted successfully. Pending verification. The cashier will approve and you’ll get access soon.';
        }
        $teacher = getUserById($course['teacher_id'] ?? 0);
        $thumbnailPath = null;
        if (!empty($course['thumbnail']) && file_exists(__DIR__ . '/../uploads/courses/' . $course['thumbnail'])) {
            $thumbnailPath = '../uploads/courses/' . $course['thumbnail'];
        }
        try {
            $st = $pdo->prepare("SELECT name, email, phone FROM users WHERE id = ?");
            $st->execute([$studentId]);
            $studentForForm = $st->fetch(PDO::FETCH_ASSOC) ?: ['name' => $name, 'email' => '', 'phone' => ''];
        } catch (PDOException $e) {
            $studentForForm = ['name' => $name, 'email' => '', 'phone' => ''];
        }
    } else {
        $showEnrollOrPending = false;
        // Get student's assigned level for this course (RBAC)
        $studentLevel = getStudentAssignedLevel($studentId, $courseId);
    
    // Get enrollment details (progress is auto-calculated from lessons completed)
    try {
        $stmt = $pdo->prepare("SELECT enrolled_at, enrollment_status FROM enrollments WHERE student_id = ? AND course_id = ?");
        $stmt->execute([$studentId, $courseId]);
        $enrollment = $stmt->fetch();
        $enrollmentStatus = $enrollment['enrollment_status'] ?? 'approved';
        $progress = getCourseProgressFromLessons($studentId, $courseId);
    } catch (PDOException $e) {
        $progress = getCourseProgressFromLessons($studentId, $courseId);
        $enrollmentStatus = 'approved';
    }
    
    // Get course sections with lessons
    $sections = getSectionsWithLessons($courseId);
    
    // Filter lessons by student's assigned level (RBAC)
    if ($studentLevel) {
        foreach ($sections as &$section) {
            if (isset($section['lessons'])) {
                $section['lessons'] = array_filter($section['lessons'], function($lesson) use ($studentLevel) {
                    if (empty($lesson['level'])) {
                        return true;
                    }
                    $levelOrder = ['beginner' => 1, 'intermediate' => 2, 'advanced' => 3];
                    $studentOrder = $levelOrder[$studentLevel] ?? 0;
                    $lessonOrder = $levelOrder[$lesson['level']] ?? 0;
                    return $studentOrder >= $lessonOrder;
                });
            }
        }
    }
    
    // First lesson and first incomplete lesson (for Start/Continue button)
    $firstLessonId = null;
    foreach ($sections as $section) {
        $lessons = $section['lessons'] ?? [];
        if (!empty($lessons)) {
            $firstLessonId = (int)($lessons[0]['id'] ?? 0);
            if ($firstLessonId > 0) break;
        }
    }
    $firstIncompleteLessonId = null;
    try {
        $stmt = $pdo->prepare("
            SELECT l.id FROM lessons l
            LEFT JOIN sections s ON l.section_id = s.id
            LEFT JOIN lesson_progress lp ON lp.lesson_id = l.id AND lp.student_id = ?
            WHERE l.course_id = ? AND (lp.completed IS NULL OR lp.completed = 0)
            ORDER BY COALESCE(s.order_number, 999) ASC, COALESCE(l.order_number, 999) ASC, l.id ASC
            LIMIT 1
        ");
        $stmt->execute([$studentId, $courseId]);
        $row = $stmt->fetch();
        if ($row) $firstIncompleteLessonId = (int)$row['id'];
    } catch (PDOException $e) { /* ignore */ }
    $startLessonId = $firstIncompleteLessonId ?: $firstLessonId;
    $startButtonText = ($progress >= 100) ? 'Review' : ($progress > 0 ? 'Continue' : 'Start');
    
    // Get quizzes for this course
    try {
        $stmt = $pdo->prepare("SELECT q.*, 
                              (SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = q.id AND student_id = ?) as attempt_count,
                              (SELECT MAX(percentage) FROM quiz_attempts WHERE quiz_id = q.id AND student_id = ?) as best_score
                              FROM quizzes q
                              WHERE q.course_id = ? AND (q.is_published = 1 OR q.is_published IS NULL)
                              ORDER BY q.created_at DESC");
        $stmt->execute([$studentId, $studentId, $courseId]);
        $quizzes = $stmt->fetchAll();
    } catch (PDOException $e) {
        $quizzes = [];
    }
    
    // Get assignments for this course
    try {
        $stmt = $pdo->prepare("SELECT a.*, 
                              s.id as submission_id, s.submitted_at, s.score, s.status as submission_status
                              FROM assignments a
                              LEFT JOIN assignment_submissions s ON a.id = s.assignment_id AND s.student_id = ?
                              WHERE a.course_id = ?
                              ORDER BY a.due_date ASC");
        $stmt->execute([$studentId, $courseId]);
        $assignments = $stmt->fetchAll();
    } catch (PDOException $e) {
        $assignments = [];
    }
    
    // Get course materials
    try {
        $stmt = $pdo->prepare("SELECT m.* FROM materials m
                              JOIN lessons l ON m.lesson_id = l.id
                              WHERE l.course_id = ?
                              ORDER BY m.created_at DESC");
        $stmt->execute([$courseId]);
        $materials = $stmt->fetchAll();
    } catch (PDOException $e) {
        $materials = [];
    }
    
    // Get teacher info
    $teacher = getUserById($course['teacher_id']);
    
    // Get course thumbnail
    $thumbnailPath = null;
    if (!empty($course['thumbnail'])) {
        $fullPath = __DIR__ . '/../uploads/courses/' . $course['thumbnail'];
        if (file_exists($fullPath)) {
            $thumbnailPath = '../uploads/courses/' . $course['thumbnail'];
        }
    }
    }
    
    $pageTitle = htmlspecialchars($course['title']);
    $pageSubtitle = !empty($showEnrollOrPending) ? 'Enroll in this course' : 'Course Details & Learning Materials';
} else {
    // List view - Get filter parameters
    $search = sanitize($_GET['search'] ?? '');
    $categoryFilter = intval($_GET['category'] ?? 0);
    $levelFilter = sanitize($_GET['level'] ?? '');
    $priceFilter = sanitize($_GET['price'] ?? '');
    $enrolledFilter = sanitize($_GET['filter'] ?? '');
    
    // Get enrolled courses first
    $enrolledCourses = getEnrolledCourses($studentId);
    $enrolledCourseIds = array_column($enrolledCourses, 'id');
    
    // If filter is 'enrolled', only show enrolled courses
    if ($enrolledFilter === 'enrolled') {
        // Get enrolled courses with details
        $coursesQuery = "SELECT c.*, cat.name as category_name, u.name as teacher_name,
                        (SELECT COUNT(*) FROM enrollments WHERE course_id = c.id) as enrolled_count
                        FROM courses c
                        LEFT JOIN categories cat ON c.category_id = cat.id
                        LEFT JOIN users u ON c.teacher_id = u.id
                        WHERE c.status = 'published' AND c.id IN (" . implode(',', array_fill(0, count($enrolledCourseIds), '?')) . ")";
        
        $queryParams = $enrolledCourseIds;
        
        if (!empty($search)) {
            $coursesQuery .= " AND (c.title LIKE ? OR c.description LIKE ?)";
            $searchTerm = "%$search%";
            $queryParams[] = $searchTerm;
            $queryParams[] = $searchTerm;
        }
        
        if ($categoryFilter > 0) {
            $coursesQuery .= " AND c.category_id = ?";
            $queryParams[] = $categoryFilter;
        }
        
        if (!empty($levelFilter) && in_array($levelFilter, ['beginner', 'intermediate', 'advanced'])) {
            $coursesQuery .= " AND c.level = ?";
            $queryParams[] = $levelFilter;
        }
        
        if ($priceFilter === 'free') {
            $coursesQuery .= " AND (c.is_free = 1 OR c.price = 0)";
        } elseif ($priceFilter === 'paid') {
            $coursesQuery .= " AND c.is_free = 0 AND c.price > 0";
        }
        
        $coursesQuery .= " ORDER BY c.created_at DESC, c.id DESC";
        
        try {
            if (!empty($enrolledCourseIds)) {
                $stmt = $pdo->prepare($coursesQuery);
                $stmt->execute($queryParams);
                $allCourses = $stmt->fetchAll();
            } else {
                $allCourses = [];
            }
        } catch (PDOException $e) {
            $allCourses = [];
        }
        
        // All courses shown are enrolled
        $enrolled = $allCourses;
        $available = [];
    } else {
        // Get all published courses
        $coursesQuery = "SELECT c.*, cat.name as category_name, u.name as teacher_name,
                        (SELECT COUNT(*) FROM enrollments WHERE course_id = c.id) as enrolled_count
                        FROM courses c
                        LEFT JOIN categories cat ON c.category_id = cat.id
                        LEFT JOIN users u ON c.teacher_id = u.id
                        WHERE c.status = 'published'";
        
        $queryParams = [];
        
        if (!empty($search)) {
            $coursesQuery .= " AND (c.title LIKE ? OR c.description LIKE ?)";
            $searchTerm = "%$search%";
            $queryParams[] = $searchTerm;
            $queryParams[] = $searchTerm;
        }
        
        if ($categoryFilter > 0) {
            $coursesQuery .= " AND c.category_id = ?";
            $queryParams[] = $categoryFilter;
        }
        
        if (!empty($levelFilter) && in_array($levelFilter, ['beginner', 'intermediate', 'advanced'])) {
            $coursesQuery .= " AND c.level = ?";
            $queryParams[] = $levelFilter;
        }
        
        if ($priceFilter === 'free') {
            $coursesQuery .= " AND (c.is_free = 1 OR c.price = 0)";
        } elseif ($priceFilter === 'paid') {
            $coursesQuery .= " AND c.is_free = 0 AND c.price > 0";
        }
        
        $coursesQuery .= " ORDER BY c.created_at DESC, c.id DESC";
        
        try {
            $stmt = $pdo->prepare($coursesQuery);
            $stmt->execute($queryParams);
            $allCourses = $stmt->fetchAll();
        } catch (PDOException $e) {
            $allCourses = [];
        }
        
        // Separate courses into enrolled and available
        $enrolled = [];
        $available = [];
        
        foreach ($allCourses as $course) {
            if (in_array($course['id'], $enrolledCourseIds)) {
                $enrolled[] = $course;
            } else {
                $available[] = $course;
            }
        }
    }
    
    // Get categories for filter
    try {
        $stmt = $pdo->query("SELECT id, name FROM categories ORDER BY name");
        $categories = $stmt->fetchAll();
    } catch (PDOException $e) {
        $categories = [];
    }
    
    // Get student info for payment form
    try {
        $stmt = $pdo->prepare("SELECT name, email, phone FROM users WHERE id = ?");
        $stmt->execute([$studentId]);
        $student = $stmt->fetch();
    } catch (PDOException $e) {
        $student = ['name' => $name, 'email' => '', 'phone' => ''];
    }
    
    // Set page title based on filter
    if ($enrolledFilter === 'enrolled') {
        $pageTitle = 'My Enrolled Courses';
        $pageSubtitle = 'View all your enrolled courses';
    } else {
        $pageTitle = 'Available Courses';
        $pageSubtitle = 'Browse and enroll in courses you haven\'t joined yet';
    }
}

$currentPage = 'courses';
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo isset($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === 'enabled' ? 'dark' : ''; ?>">
<head>
    <link rel="icon" href="/Iqra-College/assets/images/iqra2.png" type="image/png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - IQRA Online College</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config={darkMode:'class',theme:{extend:{colors:{primary:{50:'#eff6ff',100:'#dbeafe',200:'#bfdbfe',300:'#93c5fd',400:'#60a5fa',500:'#3b82f6',600:'#2563eb',700:'#1d4ed8',800:'#1e40af',900:'#1e3a8a'}}}}};</script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .course-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .course-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }
        .fade-in {
            animation: fadeIn 0.6s ease-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .gradient-bg {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .section-collapsible {
            transition: all 0.3s ease;
        }
        .tab-button.active {
            position: relative;
        }
        .tab-button.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #667eea, #764ba2);
            border-radius: 2px;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-blue-50 via-purple-50 to-pink-50 dark:from-gray-900 dark:via-gray-800 dark:to-gray-900 min-h-screen">
    <?php include __DIR__ . '/../includes/student_sidebar.php'; ?>
    
    <div class="lg:ml-64">
        <div class="p-6 lg:p-8">
            <?php include __DIR__ . '/../includes/student_header.php'; ?>
            
            <?php if ($error): ?>
                <div class="bg-red-100 dark:bg-red-900/30 border-l-4 border-red-500 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg mb-6 fade-in shadow-lg">
                    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="bg-green-100 dark:bg-green-900/30 border-l-4 border-green-500 text-green-700 dark:text-green-400 px-4 py-3 rounded-lg mb-6 fade-in shadow-lg">
                    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <?php if ($viewMode === 'detail'): ?>
                <?php if (!empty($showEnrollOrPending)): ?>
                <!-- NOT ENROLLED: course info + Pay & Enroll or Pending -->
                <div class="bg-white dark:bg-gray-800 rounded-3xl shadow-2xl overflow-hidden mb-8 fade-in">
                    <div class="relative">
                        <?php if ($thumbnailPath): ?>
                            <img src="<?php echo htmlspecialchars($thumbnailPath); ?>" alt="<?php echo htmlspecialchars($course['title']); ?>" class="w-full h-72 object-cover">
                        <?php else: ?>
                            <div class="w-full h-72 bg-gradient-to-br from-primary-500 via-purple-600 to-pink-600 flex items-center justify-center">
                                <span class="text-white text-7xl font-bold"><?php echo strtoupper(substr($course['title'], 0, 1)); ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="absolute top-4 left-4">
                            <a href="courses.php" class="bg-white/90 dark:bg-gray-800/90 backdrop-blur-sm text-gray-800 dark:text-white px-5 py-2.5 rounded-xl font-semibold hover:bg-white dark:hover:bg-gray-800 transition-all shadow-xl hover:scale-105">
                                <i class="fas fa-arrow-left mr-2"></i>Back to Courses
                            </a>
                        </div>
                    </div>
                    <div class="p-8">
                        <h1 class="text-4xl lg:text-5xl font-extrabold text-gray-800 dark:text-white mb-6"><?php echo htmlspecialchars($course['title']); ?></h1>
                        <?php if ($course['description']): ?>
                            <p class="text-gray-600 dark:text-gray-300 text-lg mb-6 leading-relaxed"><?php echo nl2br(htmlspecialchars($course['description'])); ?></p>
                        <?php endif; ?>
                        <div class="flex flex-wrap gap-4 mb-6">
                            <?php if ($course['category_name'] ?? null): ?>
                                <span class="px-4 py-2 rounded-xl bg-primary-100 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300 font-semibold"><?php echo htmlspecialchars($course['category_name']); ?></span>
                            <?php endif; ?>
                            <span class="px-4 py-2 rounded-xl bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 font-semibold"><?php echo htmlspecialchars($teacher['name'] ?? 'N/A'); ?></span>
                            <span class="px-4 py-2 rounded-xl bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 font-semibold capitalize"><?php echo htmlspecialchars($course['level'] ?? 'beginner'); ?></span>
                        </div>
                        <?php if ($hasPendingPayment): ?>
                        <div class="p-6 rounded-2xl bg-amber-50 dark:bg-amber-900/20 border-2 border-amber-200 dark:border-amber-800">
                            <div class="flex items-start gap-4">
                                <div class="w-12 h-12 rounded-xl bg-amber-500 flex items-center justify-center text-white flex-shrink-0">
                                    <i class="fas fa-clock text-xl"></i>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-amber-800 dark:text-amber-200 mb-1">Payment pending verification</h3>
                                    <p class="text-amber-700 dark:text-amber-300">The cashier will verify your payment and approve your enrollment. You’ll get access to the course soon.</p>
                                    <a href="payments.php" class="inline-block mt-3 text-amber-700 dark:text-amber-300 font-semibold hover:underline">View your payments →</a>
                                </div>
                            </div>
                        </div>
                        <?php else:
                            $isFree = ((int)($course['is_free'] ?? 0) === 1) || (floatval($course['price'] ?? 0) == 0);
                            $finalPrice = (!empty($course['discount_price']) && (float)$course['discount_price'] > 0) ? (float)$course['discount_price'] : (float)($course['price'] ?? 0);
                        ?>
                        <div class="p-6 rounded-2xl border-2 border-primary-200 dark:border-primary-800 bg-primary-50/50 dark:bg-primary-900/10">
                            <h3 class="text-xl font-bold text-gray-800 dark:text-white mb-4"><?php echo $isFree ? 'Enroll for free' : 'Pay & Enroll'; ?></h3>
                            <?php if ($isFree): ?>
                            <form method="POST">
                                <input type="hidden" name="action" value="enroll_free">
                                <input type="hidden" name="course_id" value="<?php echo (int)$courseId; ?>">
                                <button type="submit" class="bg-gradient-to-r from-green-500 to-emerald-600 text-white px-8 py-4 rounded-xl font-bold hover:from-green-600 hover:to-emerald-700 shadow-lg transition-all">
                                    <i class="fas fa-play mr-2"></i>Enroll & Start
                                </button>
                            </form>
                            <?php else: ?>
                            <form method="POST" class="space-y-4 max-w-md">
                                <input type="hidden" name="action" value="enroll">
                                <input type="hidden" name="course_id" value="<?php echo (int)$courseId; ?>">
                                <input type="hidden" name="amount" value="<?php echo number_format($finalPrice, 2, '.', ''); ?>">
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Amount</label>
                                    <p class="text-2xl font-bold text-primary-600 dark:text-primary-400">$<?php echo number_format($finalPrice, 2); ?></p>
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Payment method</label>
                                    <select name="payment_method" required class="w-full px-4 py-3 border-2 border-gray-200 dark:border-gray-700 rounded-xl dark:bg-gray-700 dark:text-white focus:border-primary-500 focus:outline-none">
                                        <option value="cash">Cash</option>
                                        <option value="mobile_money">Mobile Money</option>
                                        <option value="bank_transfer">Bank Transfer</option>
                                        <option value="card">Card</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Reference / Receipt (optional)</label>
                                    <input type="text" name="payment_reference" placeholder="e.g. transaction ID" class="w-full px-4 py-3 border-2 border-gray-200 dark:border-gray-700 rounded-xl dark:bg-gray-700 dark:text-white focus:border-primary-500 focus:outline-none">
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Phone number</label>
                                    <input type="text" name="phone_number" value="<?php echo htmlspecialchars($studentForForm['phone'] ?? ''); ?>" placeholder="Your phone" class="w-full px-4 py-3 border-2 border-gray-200 dark:border-gray-700 rounded-xl dark:bg-gray-700 dark:text-white focus:border-primary-500 focus:outline-none">
                                </div>
                                <button type="submit" class="bg-gradient-to-r from-primary-500 to-purple-600 text-white px-8 py-4 rounded-xl font-bold hover:from-primary-600 hover:to-purple-700 shadow-lg transition-all">
                                    <i class="fas fa-check mr-2"></i>Pay & Enroll
                                </button>
                            </form>
                            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">After you pay, the cashier will verify and approve. You’ll get access once approved.</p>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php else: ?>
                <!-- ENROLLED: full course detail view -->
                <?php if (($enrollmentStatus ?? '') === 'pending'): ?>
                <div class="mb-6 p-4 rounded-xl bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 text-amber-800 dark:text-amber-200 flex items-center gap-3" role="status">
                    <i class="fas fa-clock text-amber-500 text-xl flex-shrink-0"></i>
                    <p class="text-sm font-medium">Your enrollment is pending payment verification. You can view the course structure, but full access will be granted once approved.</p>
                </div>
                <?php endif; ?>

                <!-- Course Header -->
                <div class="bg-white dark:bg-gray-800 rounded-3xl shadow-2xl overflow-hidden mb-8 fade-in">
                    <div class="relative">
                        <?php if ($thumbnailPath): ?>
                            <img src="<?php echo htmlspecialchars($thumbnailPath); ?>" 
                                 alt="<?php echo htmlspecialchars($course['title']); ?>" 
                                 class="w-full h-72 object-cover">
                        <?php else: ?>
                            <div class="w-full h-72 bg-gradient-to-br from-primary-500 via-purple-600 to-pink-600 flex items-center justify-center">
                                <span class="text-white text-7xl font-bold"><?php echo strtoupper(substr($course['title'], 0, 1)); ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <div class="absolute top-4 left-4">
                            <a href="courses.php" 
                               class="bg-white/90 dark:bg-gray-800/90 backdrop-blur-sm text-gray-800 dark:text-white px-5 py-2.5 rounded-xl font-semibold hover:bg-white dark:hover:bg-gray-800 transition-all shadow-xl hover:scale-105">
                                <i class="fas fa-arrow-left mr-2"></i>Back to Courses
                            </a>
                        </div>
                        
                        <div class="absolute top-4 right-4 flex gap-2">
                            <span class="bg-green-500 text-white px-4 py-2 rounded-full text-sm font-bold shadow-xl backdrop-blur-sm">
                                <i class="fas fa-check-circle mr-1"></i>Enrolled
                            </span>
                            <?php if (isset($studentLevel) && $studentLevel): ?>
                                <span class="bg-primary-500 text-white px-4 py-2 rounded-full text-sm font-bold shadow-xl backdrop-blur-sm capitalize">
                                    <i class="fas fa-signal mr-1"></i><?php echo $studentLevel; ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <!-- Progress bar on header -->
                        <div class="absolute bottom-0 left-0 right-0 h-2 bg-black/30">
                            <div class="h-full bg-white/90 transition-all duration-500" style="width: <?php echo (int)($progress ?? 0); ?>%"></div>
                        </div>
                    </div>
                    
                    <div class="p-8">
                        <div class="grid lg:grid-cols-3 gap-8">
                            <div class="lg:col-span-2">
                                <h1 class="text-4xl lg:text-5xl font-extrabold text-gray-800 dark:text-white mb-6 bg-gradient-to-r from-primary-600 to-purple-600 bg-clip-text text-transparent">
                                    <?php echo htmlspecialchars($course['title']); ?>
                                </h1>
                                
                                <?php if ($course['description']): ?>
                                    <p class="text-gray-600 dark:text-gray-300 text-lg mb-8 leading-relaxed">
                                        <?php echo nl2br(htmlspecialchars($course['description'])); ?>
                                    </p>
                                <?php endif; ?>
                                
                                <div class="grid md:grid-cols-2 gap-6">
                                    <?php if ($course['category_name'] ?? null): ?>
                                        <div class="flex items-center space-x-4 bg-gradient-to-r from-primary-50 to-purple-50 dark:from-primary-900/20 dark:to-purple-900/20 p-4 rounded-xl">
                                            <div class="bg-primary-500 p-3 rounded-xl shadow-lg">
                                                <i class="fas fa-folder text-white text-xl"></i>
                                            </div>
                                            <div>
                                                <p class="text-xs text-gray-500 dark:text-gray-400 font-semibold uppercase">Category</p>
                                                <p class="font-bold text-gray-800 dark:text-white text-lg"><?php echo htmlspecialchars($course['category_name']); ?></p>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="flex items-center space-x-4 bg-gradient-to-r from-purple-50 to-pink-50 dark:from-purple-900/20 dark:to-pink-900/20 p-4 rounded-xl">
                                        <div class="bg-purple-500 p-3 rounded-xl shadow-lg">
                                            <i class="fas fa-user-tie text-white text-xl"></i>
                                        </div>
                                        <div>
                                            <p class="text-xs text-gray-500 dark:text-gray-400 font-semibold uppercase">Instructor</p>
                                            <p class="font-bold text-gray-800 dark:text-white text-lg"><?php echo htmlspecialchars($teacher['name'] ?? 'N/A'); ?></p>
                                        </div>
                                    </div>
                                    
                                    <div class="flex items-center space-x-4 bg-gradient-to-r from-blue-50 to-cyan-50 dark:from-blue-900/20 dark:to-cyan-900/20 p-4 rounded-xl">
                                        <div class="bg-blue-500 p-3 rounded-xl shadow-lg">
                                            <i class="fas fa-signal text-white text-xl"></i>
                                        </div>
                                        <div>
                                            <p class="text-xs text-gray-500 dark:text-gray-400 font-semibold uppercase">Level</p>
                                            <p class="font-bold text-gray-800 dark:text-white text-lg capitalize"><?php echo htmlspecialchars($course['level'] ?? 'beginner'); ?></p>
                                        </div>
                                    </div>
                                    
                                    <?php if (!empty($course['duration']) && $course['duration'] > 0): ?>
                                        <div class="flex items-center space-x-4 bg-gradient-to-r from-orange-50 to-red-50 dark:from-orange-900/20 dark:to-red-900/20 p-4 rounded-xl">
                                            <div class="bg-orange-500 p-3 rounded-xl shadow-lg">
                                                <i class="fas fa-clock text-white text-xl"></i>
                                            </div>
                                            <div>
                                                <p class="text-xs text-gray-500 dark:text-gray-400 font-semibold uppercase">Duration</p>
                                                <p class="font-bold text-gray-800 dark:text-white text-lg"><?php echo $course['duration']; ?> hours</p>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Start button and progress in main content -->
                                <div class="flex flex-wrap items-center gap-4 mt-6">
                                    <?php if ($startLessonId): ?>
                                    <a href="lesson.php?id=<?php echo (int)$startLessonId; ?>" 
                                       class="inline-flex items-center gap-2 bg-gradient-to-r from-primary-500 to-purple-600 text-white px-8 py-4 rounded-xl font-bold hover:from-primary-600 hover:to-purple-700 shadow-lg hover:shadow-xl hover:scale-[1.02] transition-all">
                                        <i class="fas fa-play"></i><?php echo htmlspecialchars($startButtonText); ?>
                                    </a>
                                    <?php else: ?>
                                    <a href="#content-lessons" 
                                       class="inline-flex items-center gap-2 bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-300 px-8 py-4 rounded-xl font-bold hover:bg-gray-300 dark:hover:bg-gray-500 transition-all">
                                        <i class="fas fa-book"></i>View Lessons
                                    </a>
                                    <?php endif; ?>
                                    <?php if ($progress >= 100): ?>
                                    <a href="certificate.php?course_id=<?php echo (int)$courseId; ?>" 
                                       class="inline-flex items-center gap-2 bg-gradient-to-r from-amber-500 to-orange-600 text-white px-8 py-4 rounded-xl font-bold hover:from-amber-600 hover:to-orange-700 shadow-lg hover:shadow-xl transition-all">
                                        <i class="fas fa-certificate"></i>View Certificate
                                    </a>
                                    <?php endif; ?>
                                    <div class="flex items-center gap-3">
                                        <span class="text-sm font-semibold text-gray-600 dark:text-gray-400">Progress:</span>
                                        <div class="flex items-center gap-2 min-w-[140px]">
                                            <div class="flex-1 h-2.5 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                                                <div class="h-full bg-gradient-to-r from-primary-500 to-purple-500 rounded-full transition-all duration-500" style="width: <?php echo (int)$progress; ?>%"></div>
                                            </div>
                                            <span class="text-sm font-bold text-primary-600 dark:text-primary-400"><?php echo $progress; ?>%</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="lg:col-span-1">
                                <div class="bg-gradient-to-br from-primary-500 via-purple-600 to-pink-600 rounded-3xl p-8 text-white shadow-2xl">
                                    <h3 class="text-2xl font-bold mb-6">Your Progress</h3>
                                    <div class="mb-6">
                                        <div class="flex justify-between text-sm mb-3">
                                            <span class="font-semibold">Course Completion</span>
                                            <span class="font-bold text-xl"><?php echo $progress; ?>%</span>
                                        </div>
                                        <div class="w-full bg-white/20 rounded-full h-5 shadow-inner">
                                            <div class="bg-white h-5 rounded-full transition-all duration-700 shadow-lg" 
                                                 style="width: <?php echo $progress; ?>%"></div>
                                        </div>
                                    </div>
                                    
                                    <!-- Start / Continue / Review button -->
                                    <?php if ($startLessonId): ?>
                                    <a href="lesson.php?id=<?php echo (int)$startLessonId; ?>" 
                                       class="w-full flex items-center justify-center gap-2 py-4 px-6 rounded-xl font-bold bg-white text-primary-600 hover:bg-white/90 hover:scale-[1.02] transition-all shadow-lg mt-4">
                                        <i class="fas fa-play"></i><?php echo htmlspecialchars($startButtonText); ?>
                                    </a>
                                    <?php else: ?>
                                    <a href="#content-lessons" 
                                       class="w-full flex items-center justify-center gap-2 py-4 px-6 rounded-xl font-bold bg-white/20 hover:bg-white/30 transition-all mt-4">
                                        <i class="fas fa-book"></i>View Lessons
                                    </a>
                                    <?php endif; ?>
                                    <?php if ($progress >= 100): ?>
                                    <a href="certificate.php?course_id=<?php echo (int)$courseId; ?>" 
                                       class="w-full flex items-center justify-center gap-2 py-4 px-6 rounded-xl font-bold bg-white/20 hover:bg-white/30 border-2 border-white/40 transition-all mt-3">
                                        <i class="fas fa-certificate"></i>View Certificate
                                    </a>
                                    <?php endif; ?>
                                    
                                    <div class="space-y-4 mt-8">
                                        <div class="flex items-center justify-between text-sm bg-white/10 p-3 rounded-xl">
                                            <span><i class="fas fa-book mr-2"></i>Lessons</span>
                                            <span class="font-bold text-lg"><?php 
                                                $totalLessons = 0;
                                                foreach ($sections as $section) {
                                                    $totalLessons += count($section['lessons'] ?? []);
                                                }
                                                echo $totalLessons;
                                            ?></span>
                                        </div>
                                        <div class="flex items-center justify-between text-sm bg-white/10 p-3 rounded-xl">
                                            <span><i class="fas fa-question-circle mr-2"></i>Quizzes</span>
                                            <span class="font-bold text-lg"><?php echo count($quizzes); ?></span>
                                        </div>
                                        <div class="flex items-center justify-between text-sm bg-white/10 p-3 rounded-xl">
                                            <span><i class="fas fa-tasks mr-2"></i>Assignments</span>
                                            <span class="font-bold text-lg"><?php echo count($assignments); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Course Content Tabs -->
                <?php if (!empty($quizzes)): ?>
                <div class="mb-6 p-4 rounded-2xl bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-800 flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 rounded-xl bg-purple-500 text-white flex items-center justify-center shadow-lg">
                            <i class="fas fa-question-circle text-xl"></i>
                        </div>
                        <div>
                            <p class="font-bold text-purple-900 dark:text-purple-200">Quiz available</p>
                            <p class="text-sm text-purple-700 dark:text-purple-300">You can take the quiz now or after finishing lessons.</p>
                        </div>
                    </div>
                    <button onclick="showTab('quizzes')" class="px-5 py-2 rounded-xl bg-purple-600 text-white font-semibold shadow-lg hover:bg-purple-700 transition-all">
                        Go to Quizzes
                    </button>
                </div>
                <?php endif; ?>
                <div class="bg-white dark:bg-gray-800 rounded-3xl shadow-2xl mb-8 fade-in">
                    <div class="border-b border-gray-200 dark:border-gray-700">
                        <nav class="flex -mb-px overflow-x-auto">
                            <button onclick="showTab('lessons')" 
                                    id="tab-lessons"
                                    class="tab-button active px-8 py-5 font-bold text-primary-600 dark:text-primary-400 border-b-3 border-primary-600 dark:border-primary-400 whitespace-nowrap">
                                <i class="fas fa-book mr-2"></i>Lessons
                            </button>
                            <button onclick="showTab('quizzes')" 
                                    id="tab-quizzes"
                                    class="tab-button px-8 py-5 font-bold text-gray-500 dark:text-gray-400 hover:text-primary-600 dark:hover:text-primary-400 border-b-3 border-transparent whitespace-nowrap">
                                <i class="fas fa-question-circle mr-2"></i>Quizzes
                            </button>
                            <button onclick="showTab('assignments')" 
                                    id="tab-assignments"
                                    class="tab-button px-8 py-5 font-bold text-gray-500 dark:text-gray-400 hover:text-primary-600 dark:hover:text-primary-400 border-b-3 border-transparent whitespace-nowrap">
                                <i class="fas fa-tasks mr-2"></i>Assignments
                            </button>
                            <button onclick="showTab('materials')" 
                                    id="tab-materials"
                                    class="tab-button px-8 py-5 font-bold text-gray-500 dark:text-gray-400 hover:text-primary-600 dark:hover:text-primary-400 border-b-3 border-transparent whitespace-nowrap">
                                <i class="fas fa-file-download mr-2"></i>Materials
                            </button>
                        </nav>
                    </div>
                    
                    <div class="p-8">
                        <!-- Lessons Tab -->
                        <div id="content-lessons" class="tab-content">
                            <?php if (empty($sections) || (count($sections) == 0)): ?>
                                <div class="text-center py-16">
                                    <i class="fas fa-book-open text-7xl text-gray-400 mb-6"></i>
                                    <p class="text-2xl text-gray-600 dark:text-gray-400 font-semibold">No lessons available yet.</p>
                                </div>
                            <?php else: ?>
                                <div class="space-y-4">
                                    <?php foreach ($sections as $section): 
                                        $sectionLessons = $section['lessons'] ?? [];
                                        if (empty($sectionLessons)) continue;
                                    ?>
                                        <div class="border-2 border-gray-200 dark:border-gray-700 rounded-2xl overflow-hidden bg-gradient-to-r from-gray-50 to-white dark:from-gray-800 dark:to-gray-700">
                                            <button onclick="toggleSection(<?php echo $section['id'] ?? 'null'; ?>)" 
                                                    class="w-full flex items-center justify-between p-5 bg-gradient-to-r from-primary-50 to-purple-50 dark:from-primary-900/20 dark:to-purple-900/20 hover:from-primary-100 hover:to-purple-100 dark:hover:from-primary-900/30 dark:hover:to-purple-900/30 transition-all">
                                                <div class="flex items-center space-x-4">
                                                    <i class="fas fa-chevron-down text-primary-600 dark:text-primary-400 transform transition-transform text-xl" 
                                                       id="icon-<?php echo $section['id'] ?? 'null'; ?>"></i>
                                                    <h3 class="text-xl font-bold text-gray-800 dark:text-white">
                                                        <?php echo htmlspecialchars($section['title'] ?? 'Lessons'); ?>
                                                    </h3>
                                                    <span class="bg-primary-500 text-white px-4 py-1.5 rounded-full text-sm font-bold shadow-lg">
                                                        <?php echo count($sectionLessons); ?> lessons
                                                    </span>
                                                </div>
                                            </button>
                                            
                                            <div id="section-<?php echo $section['id'] ?? 'null'; ?>" class="hidden">
                                                <div class="p-5 space-y-3">
                                                    <?php foreach ($sectionLessons as $lesson): 
                                                        // Check if lesson is completed
                                                        $isCompleted = false;
                                                        try {
                                                            $stmt = $pdo->prepare("SELECT completed FROM lesson_progress WHERE student_id = ? AND lesson_id = ?");
                                                            $stmt->execute([$studentId, $lesson['id']]);
                                                            $progress = $stmt->fetch();
                                                            $isCompleted = $progress && $progress['completed'];
                                                        } catch (PDOException $e) {
                                                            $isCompleted = false;
                                                        }
                                                        
                                                        // Check level access
                                                        $canAccess = true;
                                                        if (!empty($lesson['level']) && $studentLevel) {
                                                            $levelOrder = ['beginner' => 1, 'intermediate' => 2, 'advanced' => 3];
                                                            $studentOrder = $levelOrder[$studentLevel] ?? 0;
                                                            $lessonOrder = $levelOrder[$lesson['level']] ?? 0;
                                                            $canAccess = $studentOrder >= $lessonOrder;
                                                        }
                                                    ?>
                                                        <a href="lesson.php?id=<?php echo $lesson['id']; ?>" 
                                                           class="flex items-center justify-between p-5 bg-white dark:bg-gray-800 rounded-xl hover:bg-gradient-to-r hover:from-primary-50 hover:to-purple-50 dark:hover:from-primary-900/20 dark:hover:to-purple-900/20 transition-all border-2 border-gray-200 dark:border-gray-700 hover:border-primary-300 dark:hover:border-primary-600 <?php echo !$canAccess ? 'opacity-50 cursor-not-allowed' : ''; ?>">
                                                            <div class="flex items-center space-x-5 flex-1">
                                                                <?php if ($isCompleted): ?>
                                                                    <div class="bg-gradient-to-br from-green-500 to-emerald-600 text-white w-12 h-12 rounded-xl flex items-center justify-center shadow-lg">
                                                                        <i class="fas fa-check text-lg"></i>
                                                                    </div>
                                                                <?php else: ?>
                                                                    <div class="bg-gradient-to-br from-gray-200 to-gray-300 dark:from-gray-700 dark:to-gray-600 text-gray-700 dark:text-gray-300 w-12 h-12 rounded-xl flex items-center justify-center shadow-md">
                                                                        <span class="text-lg font-bold"><?php echo $lesson['order_number'] ?? ''; ?></span>
                                                                    </div>
                                                                <?php endif; ?>
                                                                
                                                                <div class="flex-1">
                                                                    <h4 class="font-bold text-lg text-gray-800 dark:text-white mb-1">
                                                                        <?php echo htmlspecialchars($lesson['title']); ?>
                                                                    </h4>
                                                                    <div class="flex items-center space-x-5 mt-2 text-sm text-gray-500 dark:text-gray-400">
                                                                        <span><i class="fas fa-book-open mr-1"></i><?php echo htmlspecialchars($lesson['lesson_type'] ?? 'Lesson'); ?></span>
                                                                        <?php if (!empty($lesson['duration']) && $lesson['duration'] > 0): ?>
                                                                            <span><i class="fas fa-clock mr-1"></i><?php echo $lesson['duration']; ?> min</span>
                                                                        <?php endif; ?>
                                                                        <?php if (!empty($lesson['level'])): ?>
                                                                            <span class="capitalize bg-blue-100 dark:bg-blue-900/20 text-blue-700 dark:text-blue-400 px-2 py-1 rounded"><i class="fas fa-signal mr-1"></i><?php echo $lesson['level']; ?></span>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            
                                                            <?php if (!$canAccess): ?>
                                                                <span class="text-sm text-red-600 dark:text-red-400 font-bold bg-red-100 dark:bg-red-900/20 px-4 py-2 rounded-lg">
                                                                    <i class="fas fa-lock mr-1"></i>Level Restricted
                                                                </span>
                                                            <?php else: ?>
                                                                <i class="fas fa-arrow-right text-primary-600 dark:text-primary-400 text-xl"></i>
                                                            <?php endif; ?>
                                                        </a>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Quizzes Tab -->
                        <div id="content-quizzes" class="tab-content hidden">
                            <?php if (empty($quizzes)): ?>
                                <div class="text-center py-16">
                                    <i class="fas fa-question-circle text-7xl text-gray-400 mb-6"></i>
                                    <p class="text-2xl text-gray-600 dark:text-gray-400 font-semibold">No quizzes available yet.</p>
                                </div>
                            <?php else: ?>
                                <div class="grid md:grid-cols-2 gap-6">
                                    <?php foreach ($quizzes as $quiz): ?>
                                        <div class="bg-gradient-to-br from-gray-50 to-white dark:from-gray-700 dark:to-gray-800 rounded-2xl p-6 border-2 border-gray-200 dark:border-gray-600 hover:shadow-2xl transition-all hover:scale-105">
                                            <div class="flex items-start justify-between mb-5">
                                                <h4 class="text-xl font-bold text-gray-800 dark:text-white">
                                                    <?php echo htmlspecialchars($quiz['title']); ?>
                                                </h4>
                                                <?php if ($quiz['best_score'] !== null): ?>
                                                    <span class="bg-gradient-to-br from-green-500 to-emerald-600 text-white px-4 py-2 rounded-full text-sm font-bold shadow-lg">
                                                        <?php echo round($quiz['best_score']); ?>%
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <?php if ($quiz['description']): ?>
                                                <p class="text-sm text-gray-600 dark:text-gray-400 mb-5">
                                                    <?php echo htmlspecialchars($quiz['description']); ?>
                                                </p>
                                            <?php endif; ?>
                                            
                                            <div class="flex items-center justify-between text-sm text-gray-500 dark:text-gray-400 mb-5 bg-gray-100 dark:bg-gray-700 p-3 rounded-lg">
                                                <span><i class="fas fa-clock mr-1"></i><?php echo $quiz['duration'] ?? 0; ?> min</span>
                                                <span><i class="fas fa-redo mr-1"></i><?php echo $quiz['attempt_count'] ?? 0; ?> attempts</span>
                                                <span><i class="fas fa-trophy mr-1"></i><?php echo $quiz['passing_score'] ?? 60; ?>% to pass</span>
                                            </div>
                                            
                                            <a href="quiz.php?id=<?php echo $quiz['id']; ?>" 
                                               class="block w-full bg-gradient-to-r from-primary-500 to-purple-600 text-white px-6 py-3 rounded-xl text-center font-bold hover:from-primary-600 hover:to-purple-700 transition-all shadow-lg hover:shadow-xl">
                                                <i class="fas fa-play mr-2"></i>
                                                <?php echo ($quiz['attempt_count'] ?? 0) > 0 ? 'Retake Quiz' : 'Start Quiz'; ?>
                                            </a>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Assignments Tab -->
                        <div id="content-assignments" class="tab-content hidden">
                            <?php if (empty($assignments)): ?>
                                <div class="text-center py-16">
                                    <i class="fas fa-tasks text-7xl text-gray-400 mb-6"></i>
                                    <p class="text-2xl text-gray-600 dark:text-gray-400 font-semibold">No assignments available yet.</p>
                                </div>
                            <?php else: ?>
                                <div class="space-y-5">
                                    <?php foreach ($assignments as $assignment): 
                                        $dueDate = $assignment['due_date'] ? new DateTime($assignment['due_date']) : null;
                                        $now = new DateTime();
                                        $isOverdue = $dueDate && $dueDate < $now && empty($assignment['submission_id']);
                                        $isSubmitted = !empty($assignment['submission_id']);
                                        $isGraded = !empty($assignment['score']);
                                    ?>
                                        <div class="bg-gradient-to-br from-gray-50 to-white dark:from-gray-700 dark:to-gray-800 rounded-2xl p-6 border-2 border-gray-200 dark:border-gray-600 hover:shadow-xl transition-all">
                                            <div class="flex items-start justify-between mb-5">
                                                <div class="flex-1">
                                                    <h4 class="text-xl font-bold text-gray-800 dark:text-white mb-3">
                                                        <?php echo htmlspecialchars($assignment['title']); ?>
                                                    </h4>
                                                    <?php if ($assignment['description']): ?>
                                                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                                                            <?php echo htmlspecialchars($assignment['description']); ?>
                                                        </p>
                                                    <?php endif; ?>
                                                </div>
                                                
                                                <div class="ml-4">
                                                    <?php if ($isGraded): ?>
                                                        <span class="bg-gradient-to-br from-green-500 to-emerald-600 text-white px-4 py-2 rounded-full text-sm font-bold shadow-lg">
                                                            <i class="fas fa-check-circle mr-1"></i>Graded: <?php echo $assignment['score']; ?>/<?php echo $assignment['max_score'] ?? 100; ?>
                                                        </span>
                                                    <?php elseif ($isSubmitted): ?>
                                                        <span class="bg-gradient-to-br from-blue-500 to-cyan-600 text-white px-4 py-2 rounded-full text-sm font-bold shadow-lg">
                                                            <i class="fas fa-check mr-1"></i>Submitted
                                                        </span>
                                                    <?php elseif ($isOverdue): ?>
                                                        <span class="bg-gradient-to-br from-red-500 to-pink-600 text-white px-4 py-2 rounded-full text-sm font-bold shadow-lg">
                                                            <i class="fas fa-exclamation-triangle mr-1"></i>Overdue
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="bg-gradient-to-br from-yellow-500 to-orange-600 text-white px-4 py-2 rounded-full text-sm font-bold shadow-lg">
                                                            <i class="fas fa-clock mr-1"></i>Pending
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            
                                            <div class="flex items-center justify-between bg-gray-100 dark:bg-gray-700 p-4 rounded-xl">
                                                <div class="text-sm text-gray-600 dark:text-gray-400">
                                                    <?php if ($dueDate): ?>
                                                        <i class="fas fa-calendar-alt mr-2"></i>
                                                        Due: <?php echo $dueDate->format('M d, Y H:i'); ?>
                                                    <?php endif; ?>
                                                </div>
                                                
                                                <a href="assignments.php?course=<?php echo $courseId; ?>&assignment=<?php echo $assignment['id']; ?>" 
                                                   class="bg-gradient-to-r from-primary-500 to-purple-600 text-white px-6 py-2.5 rounded-xl text-sm font-bold hover:from-primary-600 hover:to-purple-700 transition-all shadow-lg">
                                                    <i class="fas fa-<?php echo $isSubmitted ? 'eye' : 'edit'; ?> mr-1"></i>
                                                    <?php echo $isSubmitted ? 'View Submission' : 'Submit Assignment'; ?>
                                                </a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Materials Tab -->
                        <div id="content-materials" class="tab-content hidden">
                            <?php if (empty($materials)): ?>
                                <div class="text-center py-16">
                                    <i class="fas fa-file-download text-7xl text-gray-400 mb-6"></i>
                                    <p class="text-2xl text-gray-600 dark:text-gray-400 font-semibold">No materials available yet.</p>
                                </div>
                            <?php else: ?>
                                <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
                                    <?php foreach ($materials as $material): ?>
                                        <div class="bg-gradient-to-br from-gray-50 to-white dark:from-gray-700 dark:to-gray-800 rounded-2xl p-5 border-2 border-gray-200 dark:border-gray-600 hover:shadow-2xl transition-all hover:scale-105">
                                            <div class="flex items-center space-x-4 mb-4">
                                                <div class="bg-gradient-to-br from-primary-500 to-purple-600 p-4 rounded-xl shadow-lg">
                                                    <i class="fas fa-file-<?php 
                                                        $ext = strtolower(pathinfo($material['file_name'], PATHINFO_EXTENSION));
                                                        echo in_array($ext, ['pdf']) ? 'pdf' : (in_array($ext, ['doc', 'docx']) ? 'word' : (in_array($ext, ['xls', 'xlsx']) ? 'excel' : 'alt'));
                                                    ?> text-white text-2xl"></i>
                                                </div>
                                                <div class="flex-1 min-w-0">
                                                    <h4 class="font-bold text-gray-800 dark:text-white truncate">
                                                        <?php echo htmlspecialchars($material['file_name']); ?>
                                                    </h4>
                                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                                        <?php 
                                                        $size = $material['file_size'] ?? 0;
                                                        echo $size > 0 ? number_format($size / 1024, 2) . ' KB' : 'Unknown size';
                                                        ?>
                                                    </p>
                                                </div>
                                            </div>
                                            
                                            <a href="../uploads/materials/<?php echo htmlspecialchars($material['file_path']); ?>" 
                                               download
                                               class="block w-full bg-gradient-to-r from-primary-500 to-purple-600 text-white px-5 py-3 rounded-xl text-center text-sm font-bold hover:from-primary-600 hover:to-purple-700 transition-all shadow-lg">
                                                <i class="fas fa-download mr-2"></i>Download
                                            </a>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <!-- end ENROLLED detail view -->

            <?php else: ?>
                <!-- COURSE LIST VIEW -->
                
                <!-- Filters -->
                <div class="bg-white dark:bg-gray-800 rounded-3xl shadow-2xl p-8 mb-8 fade-in">
                    <form method="GET" class="grid md:grid-cols-4 gap-6">
                        <input type="hidden" name="filter" value="<?php echo htmlspecialchars($enrolledFilter ?? ''); ?>">
                        <div>
                            <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-3">
                                <i class="fas fa-search mr-2 text-primary-500"></i>Search
                            </label>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search ?? ''); ?>" 
                                   placeholder="Search courses..."
                                   class="w-full px-5 py-3 border-2 border-gray-200 dark:border-gray-700 rounded-xl focus:border-primary-500 focus:outline-none dark:bg-gray-700 dark:text-white transition-all">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-3">
                                <i class="fas fa-folder mr-2 text-primary-500"></i>Category
                            </label>
                            <select name="category" 
                                    class="w-full px-5 py-3 border-2 border-gray-200 dark:border-gray-700 rounded-xl focus:border-primary-500 focus:outline-none dark:bg-gray-700 dark:text-white transition-all">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat['id']; ?>" <?php echo ($categoryFilter ?? 0) == $cat['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-3">
                                <i class="fas fa-signal mr-2 text-primary-500"></i>Level
                            </label>
                            <select name="level" 
                                    class="w-full px-5 py-3 border-2 border-gray-200 dark:border-gray-700 rounded-xl focus:border-primary-500 focus:outline-none dark:bg-gray-700 dark:text-white transition-all">
                                <option value="">All Levels</option>
                                <option value="beginner" <?php echo ($levelFilter ?? '') === 'beginner' ? 'selected' : ''; ?>>Beginner</option>
                                <option value="intermediate" <?php echo ($levelFilter ?? '') === 'intermediate' ? 'selected' : ''; ?>>Intermediate</option>
                                <option value="advanced" <?php echo ($levelFilter ?? '') === 'advanced' ? 'selected' : ''; ?>>Advanced</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-3">
                                <i class="fas fa-dollar-sign mr-2 text-primary-500"></i>Price
                            </label>
                            <select name="price" 
                                    class="w-full px-5 py-3 border-2 border-gray-200 dark:border-gray-700 rounded-xl focus:border-primary-500 focus:outline-none dark:bg-gray-700 dark:text-white transition-all">
                                <option value="">All Prices</option>
                                <option value="free" <?php echo ($priceFilter ?? '') === 'free' ? 'selected' : ''; ?>>Free</option>
                                <option value="paid" <?php echo ($priceFilter ?? '') === 'paid' ? 'selected' : ''; ?>>Paid</option>
                            </select>
                        </div>
                        
                        <div class="md:col-span-4 flex gap-4">
                            <button type="submit" 
                                    class="bg-gradient-to-r from-primary-500 to-purple-600 text-white px-8 py-3 rounded-xl font-bold hover:from-primary-600 hover:to-purple-700 transition-all shadow-lg hover:shadow-xl transform hover:scale-105">
                                <i class="fas fa-filter mr-2"></i>Apply Filters
                            </button>
                            <a href="courses.php" 
                               class="bg-gray-500 text-white px-8 py-3 rounded-xl font-bold hover:bg-gray-600 transition-all shadow-lg">
                                <i class="fas fa-times mr-2"></i>Clear
                            </a>
                        </div>
                    </form>
                </div>

                <?php if ($enrolledFilter !== 'enrolled' && !empty($enrolled)): ?>
                <!-- My Enrolled teaser when browsing All Courses -->
                <div class="mb-10 fade-in">
                    <h2 class="text-2xl font-extrabold text-gray-800 dark:text-white mb-4 flex items-center">
                        <i class="fas fa-check-circle text-green-500 mr-2 text-2xl"></i>My Enrolled
                        <a href="courses.php?filter=enrolled" class="ml-3 text-sm font-semibold text-primary-600 dark:text-primary-400 hover:underline">View all (<?php echo count($enrolled); ?>)</a>
                    </h2>
                    <div class="grid md:grid-cols-3 gap-4">
                        <?php foreach (array_slice($enrolled, 0, 3) as $course): 
                            $thumbnailPath = null;
                            if (!empty($course['thumbnail']) && file_exists(__DIR__ . '/../uploads/courses/' . $course['thumbnail'])) {
                                $thumbnailPath = '../uploads/courses/' . $course['thumbnail'];
                            }
                            $progress = getCourseProgressFromLessons($studentId, $course['id']);
                        ?>
                            <a href="courses.php?id=<?php echo (int)$course['id']; ?>" class="flex items-center gap-4 p-4 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 hover:border-green-400 dark:hover:border-green-600 hover:shadow-lg transition-all">
                                <?php if ($thumbnailPath): ?>
                                    <img src="<?php echo htmlspecialchars($thumbnailPath); ?>" alt="" class="w-16 h-16 rounded-lg object-cover flex-shrink-0">
                                <?php else: ?>
                                    <div class="w-16 h-16 rounded-lg bg-green-500 flex items-center justify-center flex-shrink-0 text-white font-bold"><?php echo strtoupper(substr($course['title'], 0, 1)); ?></div>
                                <?php endif; ?>
                                <div class="min-w-0 flex-1">
                                    <p class="font-bold text-gray-800 dark:text-white truncate"><?php echo htmlspecialchars($course['title']); ?></p>
                                    <div class="flex items-center gap-2 mt-1">
                                        <div class="flex-1 h-1.5 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                                            <div class="h-full bg-green-500 rounded-full" style="width:<?php echo (int)$progress; ?>%"></div>
                                        </div>
                                        <span class="text-xs font-semibold text-green-600 dark:text-green-400"><?php echo $progress; ?>%</span>
                                    </div>
                                </div>
                                <i class="fas fa-chevron-right text-gray-400 flex-shrink-0"></i>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Enrolled Courses (only when "Enrolled Courses" is selected in sidebar) -->
                <?php if ($enrolledFilter === 'enrolled' && !empty($enrolled)): ?>
                    <div class="mb-10 fade-in">
                        <h2 class="text-3xl font-extrabold text-gray-800 dark:text-white mb-6 flex items-center">
                            <i class="fas fa-check-circle text-green-500 mr-3 text-3xl"></i>My Enrolled Courses <span class="ml-3 bg-green-500 text-white px-4 py-1.5 rounded-full text-lg font-bold"><?php echo count($enrolled); ?></span>
                        </h2>
                        <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-8">
                            <?php foreach ($enrolled as $course): 
                                $thumbnailPath = null;
                                if (!empty($course['thumbnail'])) {
                                    $fullPath = __DIR__ . '/../uploads/courses/' . $course['thumbnail'];
                                    if (file_exists($fullPath)) {
                                        $thumbnailPath = '../uploads/courses/' . $course['thumbnail'];
                                    }
                                }
                                
                                // Get course progress (auto-calculated from lessons completed)
                                $progress = getCourseProgressFromLessons($studentId, $course['id']);
                            ?>
                                <div class="course-card bg-white dark:bg-gray-800 rounded-3xl shadow-2xl overflow-hidden border-2 border-green-300 dark:border-green-700 hover:border-green-500 dark:hover:border-green-500 transition-all cursor-pointer"
                                     onclick="window.location.href='courses.php?id=<?php echo $course['id']; ?>'">
                                    <div class="relative">
                                        <?php if ($thumbnailPath): ?>
                                            <img src="<?php echo htmlspecialchars($thumbnailPath); ?>" 
                                                 alt="<?php echo htmlspecialchars($course['title']); ?>" 
                                                 class="w-full h-56 object-cover">
                                        <?php else: ?>
                                            <div class="w-full h-56 bg-gradient-to-br from-green-400 via-green-500 to-green-600 flex items-center justify-center">
                                                <span class="text-white text-5xl font-bold"><?php echo strtoupper(substr($course['title'], 0, 1)); ?></span>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <!-- Enrolled Badge -->
                                        <div class="absolute top-4 right-4 bg-gradient-to-br from-green-500 to-emerald-600 text-white px-4 py-2 rounded-full text-sm font-bold shadow-xl flex items-center">
                                            <i class="fas fa-check-circle mr-1"></i>Enrolled
                                        </div>
                                        
                                        <!-- Progress Badge -->
                                        <?php if ($progress > 0): ?>
                                            <div class="absolute top-4 left-4 bg-gradient-to-br from-primary-500 to-purple-600 text-white px-4 py-2 rounded-full text-sm font-bold shadow-xl">
                                                <?php echo $progress; ?>% Complete
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="p-7">
                                        <h3 class="text-2xl font-bold text-gray-800 dark:text-white mb-4 line-clamp-2 hover:text-primary-600 dark:hover:text-primary-400 transition-colors">
                                            <?php echo htmlspecialchars($course['title']); ?>
                                        </h3>
                                        
                                        <div class="space-y-2 mb-5">
                                            <?php if ($course['category_name']): ?>
                                                <p class="text-sm text-gray-600 dark:text-gray-400">
                                                    <i class="fas fa-folder text-primary-500 mr-2"></i>
                                                    <?php echo htmlspecialchars($course['category_name']); ?>
                                                </p>
                                            <?php endif; ?>
                                            
                                            <p class="text-sm text-gray-600 dark:text-gray-400">
                                                <i class="fas fa-user-tie text-purple-500 mr-2"></i>
                                                <?php echo htmlspecialchars($course['teacher_name'] ?? 'N/A'); ?>
                                            </p>
                                            
                                            <p class="text-sm text-gray-600 dark:text-gray-400">
                                                <i class="fas fa-signal text-blue-500 mr-2"></i>
                                                <span class="capitalize"><?php echo htmlspecialchars($course['level'] ?? 'beginner'); ?></span>
                                            </p>
                                            
                                            <?php if (!empty($course['duration']) && $course['duration'] > 0): ?>
                                                <p class="text-sm text-gray-600 dark:text-gray-400">
                                                    <i class="fas fa-clock text-orange-500 mr-2"></i>
                                                    <?php echo $course['duration']; ?> hours
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <!-- Progress Bar -->
                                        <div class="mb-5">
                                            <div class="flex justify-between text-sm text-gray-600 dark:text-gray-400 mb-2">
                                                <span class="font-bold">Your Progress</span>
                                                <span class="font-extrabold text-primary-600 dark:text-primary-400 text-lg"><?php echo $progress; ?>%</span>
                                            </div>
                                            <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-4 shadow-inner">
                                                <div class="bg-gradient-to-r from-green-500 via-primary-500 to-purple-600 h-4 rounded-full transition-all duration-700 shadow-lg" 
                                                     style="width: <?php echo $progress; ?>%"></div>
                                            </div>
                                        </div>
                                        
                                        <!-- Action Buttons -->
                                        <div class="pt-5 border-t border-gray-200 dark:border-gray-700 space-y-3">
                                            <a href="courses.php?id=<?php echo $course['id']; ?>" 
                                               onclick="event.stopPropagation();"
                                               class="block w-full bg-gradient-to-r from-green-500 to-green-600 text-white px-6 py-4 rounded-xl text-center font-bold hover:from-green-600 hover:to-green-700 transition-all shadow-xl hover:shadow-2xl transform hover:scale-105">
                                                <i class="fas fa-<?php echo $progress >= 100 ? 'eye' : 'graduation-cap'; ?> mr-2"></i>
                                                <?php echo $progress >= 100 ? 'View Course' : ($progress > 0 ? 'Continue Learning' : 'Start Course'); ?>
                                            </a>
                                            <?php if ($progress >= 100): ?>
                                            <a href="certificate.php?course_id=<?php echo (int)$course['id']; ?>" 
                                               onclick="event.stopPropagation();"
                                               class="block w-full bg-gradient-to-r from-amber-500 to-orange-600 text-white px-6 py-3 rounded-xl text-center font-bold hover:from-amber-600 hover:to-orange-700 transition-all shadow-lg">
                                                <i class="fas fa-certificate mr-2"></i>View Certificate
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Available Courses -->
                <?php if ($enrolledFilter !== 'enrolled'): ?>
                <div class="fade-in">
                    <h2 class="text-3xl font-extrabold text-gray-800 dark:text-white mb-6 flex items-center">
                        <i class="fas fa-book-open text-primary-500 mr-3 text-3xl"></i>Available Courses <span class="ml-3 bg-primary-500 text-white px-4 py-1.5 rounded-full text-lg font-bold"><?php echo count($available); ?></span>
                    </h2>
                    
                    <?php if (empty($available)): ?>
                        <div class="bg-white dark:bg-gray-800 rounded-3xl shadow-2xl p-16 text-center">
                            <i class="fas fa-search text-7xl text-gray-400 mb-6"></i>
                            <p class="text-2xl text-gray-600 dark:text-gray-400 font-semibold mb-4">No courses found matching your filters.</p>
                            <a href="courses.php" class="mt-6 inline-block bg-gradient-to-r from-primary-500 to-purple-600 text-white px-8 py-3 rounded-xl font-bold hover:from-primary-600 hover:to-purple-700 transition-all shadow-lg">
                                <i class="fas fa-arrow-left mr-2"></i>Clear filters and view all courses
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-8">
                            <?php foreach ($available as $course): 
                                $thumbnailPath = null;
                                if (!empty($course['thumbnail'])) {
                                    $fullPath = __DIR__ . '/../uploads/courses/' . $course['thumbnail'];
                                    if (file_exists($fullPath)) {
                                        $thumbnailPath = '../uploads/courses/' . $course['thumbnail'];
                                    }
                                }
                                
                                $finalPrice = floatval($course['price'] ?? 0);
                                if (!empty($course['discount_price']) && $course['discount_price'] > 0) {
                                    $finalPrice = floatval($course['discount_price']);
                                }
                                $isFree = ($course['is_free'] ?? 0) == 1 || $finalPrice == 0;
                            ?>
                                <div class="course-card bg-white dark:bg-gray-800 rounded-3xl shadow-2xl overflow-hidden border-2 border-gray-200 dark:border-gray-700 hover:border-primary-400 dark:hover:border-primary-600">
                                    <?php if ($thumbnailPath): ?>
                                        <img src="<?php echo htmlspecialchars($thumbnailPath); ?>" 
                                             alt="<?php echo htmlspecialchars($course['title']); ?>" 
                                             class="w-full h-56 object-cover">
                                    <?php else: ?>
                                        <div class="w-full h-56 bg-gradient-to-br from-blue-400 via-blue-500 to-blue-600 flex items-center justify-center">
                                            <span class="text-white text-5xl font-bold"><?php echo strtoupper(substr($course['title'], 0, 1)); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="p-7">
                                        <h3 class="text-2xl font-bold text-gray-800 dark:text-white mb-3 line-clamp-2">
                                            <?php echo htmlspecialchars($course['title']); ?>
                                        </h3>
                                        
                                        <?php if ($course['description']): ?>
                                            <p class="text-sm text-gray-600 dark:text-gray-400 mb-5 line-clamp-2">
                                                <?php echo htmlspecialchars($course['description']); ?>
                                            </p>
                                        <?php endif; ?>
                                        
                                        <div class="space-y-2 mb-5">
                                            <?php if ($course['category_name']): ?>
                                                <p class="text-xs text-gray-600 dark:text-gray-400">
                                                    <i class="fas fa-folder text-primary-500 mr-1"></i>
                                                    <?php echo htmlspecialchars($course['category_name']); ?>
                                                </p>
                                            <?php endif; ?>
                                            
                                            <p class="text-xs text-gray-600 dark:text-gray-400">
                                                <i class="fas fa-user-tie text-purple-500 mr-1"></i>
                                                <?php echo htmlspecialchars($course['teacher_name'] ?? 'N/A'); ?>
                                            </p>
                                            
                                            <p class="text-xs text-gray-600 dark:text-gray-400">
                                                <i class="fas fa-signal mr-1"></i>
                                                <span class="capitalize"><?php echo htmlspecialchars($course['level'] ?? 'beginner'); ?></span>
                                            </p>
                                            
                                            <?php if (!empty($course['duration']) && $course['duration'] > 0): ?>
                                                <p class="text-xs text-gray-600 dark:text-gray-400">
                                                    <i class="fas fa-clock text-green-500 mr-1"></i>
                                                    <?php echo $course['duration']; ?> hours
                                                </p>
                                            <?php endif; ?>
                                            
                                            <?php if ($course['enrolled_count'] > 0): ?>
                                                <p class="text-xs text-gray-600 dark:text-gray-400">
                                                    <i class="fas fa-users text-blue-500 mr-1"></i>
                                                    <?php echo $course['enrolled_count']; ?> students
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="flex items-center justify-between pt-5 border-t border-gray-200 dark:border-gray-700">
                                            <div>
                                                <?php if ($isFree): ?>
                                                    <span class="text-3xl font-extrabold text-green-600 dark:text-green-400">FREE</span>
                                                <?php else: ?>
                                                    <div>
                                                        <?php if (!empty($course['discount_price']) && $course['discount_price'] > 0): ?>
                                                            <span class="text-lg text-gray-400 line-through">$<?php echo number_format($course['price'], 2); ?></span>
                                                            <span class="text-3xl font-extrabold text-primary-600 dark:text-primary-400">$<?php echo number_format($course['discount_price'], 2); ?></span>
                                                        <?php else: ?>
                                                            <span class="text-3xl font-extrabold text-primary-600 dark:text-primary-400">$<?php echo number_format($finalPrice, 2); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <?php if ($isFree): ?>
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="action" value="enroll_free">
                                                <input type="hidden" name="course_id" value="<?php echo (int)$course['id']; ?>">
                                                <button type="submit" class="bg-gradient-to-r from-green-500 to-emerald-600 text-white px-6 py-3 rounded-xl text-sm font-bold hover:from-green-600 hover:to-emerald-700 transition-all shadow-lg hover:shadow-xl">
                                                    <i class="fas fa-play mr-1"></i>Enroll & Start
                                                </button>
                                            </form>
                                            <?php else: ?>
                                            <button onclick="openEnrollModal(<?php echo htmlspecialchars(json_encode([
                                                'id' => $course['id'],
                                                'title' => $course['title'],
                                                'price' => $finalPrice,
                                                'is_free' => $isFree
                                            ])); ?>)" 
                                                    class="bg-gradient-to-r from-primary-500 to-purple-600 text-white px-6 py-3 rounded-xl text-sm font-bold hover:from-primary-600 hover:to-purple-700 transition-all shadow-lg hover:shadow-xl">
                                                <i class="fas fa-shopping-cart mr-1"></i>Enroll Now
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Enrollment Modal -->
                <div id="enrollModal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm flex items-center justify-center z-50 p-4">
                    <div class="bg-white dark:bg-gray-800 rounded-3xl p-8 max-w-md w-full max-h-[90vh] overflow-y-auto shadow-2xl">
                        <div class="flex justify-between items-center mb-6">
                            <h3 class="text-3xl font-extrabold text-gray-800 dark:text-white">Enroll in Course</h3>
                            <button onclick="closeEnrollModal()" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 text-2xl">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        
                        <form method="POST" id="enrollForm">
                            <input type="hidden" name="action" value="enroll">
                            <input type="hidden" name="course_id" id="modal_course_id">
                            <input type="hidden" name="amount" id="modal_amount">
                            
                            <div class="mb-6 p-5 bg-gradient-to-r from-primary-50 to-purple-50 dark:from-primary-900/20 dark:to-purple-900/20 rounded-2xl">
                                <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">Course</p>
                                <p class="font-bold text-lg text-gray-800 dark:text-white" id="modal_course_title"></p>
                                <p class="text-2xl font-extrabold text-primary-600 dark:text-primary-400 mt-3" id="modal_course_price"></p>
                            </div>
                            
                            <div class="mb-5">
                                <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">
                                    <i class="fas fa-user mr-1 text-primary-500"></i>Full Name *
                                </label>
                                <input type="text" value="<?php echo htmlspecialchars($student['name'] ?? ''); ?>" 
                                       class="w-full px-5 py-3 border-2 border-gray-200 dark:border-gray-700 rounded-xl focus:border-primary-500 focus:outline-none dark:bg-gray-700 dark:text-white transition-all"
                                       readonly>
                            </div>
                            
                            <div class="mb-5">
                                <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">
                                    <i class="fas fa-phone mr-1 text-primary-500"></i>Phone Number (Mobile Money) *
                                </label>
                                <input type="tel" name="phone_number" 
                                       value="<?php echo htmlspecialchars($student['phone'] ?? ''); ?>"
                                       required
                                       pattern="[0-9+\-\s()]+"
                                       placeholder="Enter your mobile money number"
                                       class="w-full px-5 py-3 border-2 border-gray-200 dark:border-gray-700 rounded-xl focus:border-primary-500 focus:outline-none dark:bg-gray-700 dark:text-white transition-all">
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">The phone number you used to send the payment</p>
                            </div>
                            
                            <div class="mb-5">
                                <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">
                                    <i class="fas fa-money-bill-wave mr-1 text-primary-500"></i>Payment Method *
                                </label>
                                <select name="payment_method" required 
                                        class="w-full px-5 py-3 border-2 border-gray-200 dark:border-gray-700 rounded-xl focus:border-primary-500 focus:outline-none dark:bg-gray-700 dark:text-white transition-all">
                                    <option value="cash">Cash Payment</option>
                                    <option value="mobile_money">Mobile Money</option>
                                    <option value="bank_transfer">Bank Transfer</option>
                                    <option value="online">Online Payment</option>
                                </select>
                            </div>
                            
                            <div class="mb-6">
                                <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">
                                    <i class="fas fa-receipt mr-1 text-primary-500"></i>Payment Reference (Optional)
                                </label>
                                <input type="text" name="payment_reference" 
                                       placeholder="Transaction ID, Receipt Number, etc."
                                       class="w-full px-5 py-3 border-2 border-gray-200 dark:border-gray-700 rounded-xl focus:border-primary-500 focus:outline-none dark:bg-gray-700 dark:text-white transition-all">
                            </div>
                            
                            <div class="bg-blue-50 dark:bg-blue-900/20 border-l-4 border-blue-500 p-5 rounded-xl mb-6">
                                <p class="text-sm text-blue-700 dark:text-blue-300">
                                    <i class="fas fa-info-circle mr-2"></i>
                                    <strong>Note:</strong> Your enrollment will be pending until payment is verified by the admin. You will receive a notification once approved.
                                </p>
                            </div>
                            
                            <div class="flex gap-4">
                                <button type="submit" 
                                        class="flex-1 bg-gradient-to-r from-primary-500 to-purple-600 text-white px-6 py-4 rounded-xl font-bold hover:from-primary-600 hover:to-purple-700 transition-all shadow-lg">
                                    <i class="fas fa-check mr-2"></i>Pay & Enroll
                                </button>
                                <button type="button" onclick="closeEnrollModal()" 
                                        class="bg-gray-500 text-white px-6 py-4 rounded-xl font-bold hover:bg-gray-600 transition-all shadow-lg">
                                    Cancel
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

            <?php endif; ?>
        </div>
    </div>

    <script>
        // Mobile menu toggle
        document.getElementById('mobile-menu-toggle')?.addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('-translate-x-full');
        });

        // User dropdown toggle
        document.getElementById('user-menu-button')?.addEventListener('click', function() {
            document.getElementById('user-dropdown').classList.toggle('hidden');
        });

        // Close dropdowns on outside click
        document.addEventListener('click', function(event) {
            const userMenu = document.getElementById('user-menu-button');
            const userDropdown = document.getElementById('user-dropdown');
            if (userMenu && userDropdown && !userMenu.contains(event.target) && !userDropdown.contains(event.target)) {
                userDropdown.classList.add('hidden');
            }
        });

        <?php if ($viewMode === 'detail'): ?>
        // Tab switching
        function showTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.add('hidden');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.tab-button').forEach(button => {
                button.classList.remove('active', 'text-primary-600', 'dark:text-primary-400', 'border-primary-600', 'dark:border-primary-400');
                button.classList.add('text-gray-500', 'dark:text-gray-400', 'border-transparent');
            });
            
            // Show selected tab content
            document.getElementById('content-' + tabName).classList.remove('hidden');
            
            // Add active class to selected tab
            const activeTab = document.getElementById('tab-' + tabName);
            activeTab.classList.add('active', 'text-primary-600', 'dark:text-primary-400', 'border-primary-600', 'dark:border-primary-400');
            activeTab.classList.remove('text-gray-500', 'dark:text-gray-400', 'border-transparent');
        }
        
        // Section toggle
        function toggleSection(sectionId) {
            const sectionContent = document.getElementById('section-' + sectionId);
            const icon = document.getElementById('icon-' + sectionId);
            
            if (sectionContent.classList.contains('hidden')) {
                sectionContent.classList.remove('hidden');
                icon.classList.add('rotate-180');
            } else {
                sectionContent.classList.add('hidden');
                icon.classList.remove('rotate-180');
            }
        }
        <?php else: ?>
        // Enrollment Modal
        function openEnrollModal(course) {
            document.getElementById('modal_course_id').value = course.id;
            document.getElementById('modal_course_title').textContent = course.title;
            document.getElementById('modal_amount').value = course.price;
            
            if (course.is_free) {
                document.getElementById('modal_course_price').textContent = 'FREE';
            } else {
                document.getElementById('modal_course_price').textContent = '$' + parseFloat(course.price).toFixed(2);
            }
            
            document.getElementById('enrollModal').classList.remove('hidden');
        }
        
        function closeEnrollModal() {
            document.getElementById('enrollModal').classList.add('hidden');
        }
        
        // Close modal on outside click
        document.getElementById('enrollModal')?.addEventListener('click', function(e) {
            if (e.target === this) {
                closeEnrollModal();
            }
        });
        <?php endif; ?>
        
        // Ensure dark mode is initialized on page load
        document.addEventListener('DOMContentLoaded', function() {
            const html = document.documentElement;
            const darkModeCookie = document.cookie.split('; ').find(row => row.startsWith('dark_mode='));
            if (darkModeCookie && darkModeCookie.split('=')[1] === 'enabled') {
                html.classList.add('dark');
            } else {
                html.classList.remove('dark');
            }
        });
    </script>
    
    <?php include __DIR__ . '/../includes/student_ai_button.php'; ?>
</body>
</html>
