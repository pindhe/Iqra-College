<?php
/**
 * Enhanced Student Dashboard
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

requireRole('student');

$studentId = getCurrentUserId();
$studentCode = getUserCode($studentId);
$pdo = getDBConnection();
$name = getCurrentUserName();

// Get enrolled courses count
$enrolledCourses = getEnrolledCourses($studentId);
$coursesCount = count($enrolledCourses);

// Get completed quizzes count
try {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM quiz_attempts WHERE student_id = ? AND status = 'completed'");
        $stmt->execute([$studentId]);
        $quizzesCount = $stmt->fetch()['count'];
    } catch (PDOException $e) {
        // If status column doesn't exist, use completed_at instead
        if (strpos($e->getMessage(), 'status') !== false) {
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM quiz_attempts WHERE student_id = ? AND completed_at IS NOT NULL");
            $stmt->execute([$studentId]);
            $quizzesCount = $stmt->fetch()['count'];
        } else {
            throw $e;
        }
    }
} catch (PDOException $e) {
    $quizzesCount = 0;
}

// Get average score
try {
    try {
        $stmt = $pdo->prepare("SELECT AVG(percentage) as avg_score FROM quiz_attempts WHERE student_id = ? AND status = 'completed'");
        $stmt->execute([$studentId]);
        $avgScore = $stmt->fetch()['avg_score'] ?? 0;
    } catch (PDOException $e) {
        // If status column doesn't exist, use completed_at instead
        if (strpos($e->getMessage(), 'status') !== false) {
            $stmt = $pdo->prepare("SELECT AVG(percentage) as avg_score FROM quiz_attempts WHERE student_id = ? AND completed_at IS NOT NULL");
            $stmt->execute([$studentId]);
            $avgScore = $stmt->fetch()['avg_score'] ?? 0;
        } else {
            throw $e;
        }
    }
} catch (PDOException $e) {
    $avgScore = 0;
}

// Get upcoming assignments
try {
    $stmt = $pdo->prepare("
        SELECT a.*, c.title as course_name, COALESCE(c.slug, c.course_code, '') as course_code,
               DATEDIFF(a.due_date, NOW()) as days_left
        FROM assignments a 
        JOIN courses c ON a.course_id = c.id 
        JOIN enrollments e ON c.id = e.course_id 
        WHERE e.student_id = ? 
        AND a.due_date > NOW()
        ORDER BY a.due_date ASC 
        LIMIT 5
    ");
    $stmt->execute([$studentId]);
    $upcomingAssignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $upcomingAssignments = [];
}

// Get recent activities
try {
    $stmt = $pdo->prepare("
        SELECT 
            'quiz_completed' as type,
            q.quiz_title,
            qa.completed_at as date,
            qa.percentage as score,
            c.title as course_name,
            COALESCE(c.slug, c.course_code, '') as course_code
        FROM quiz_attempts qa
        JOIN quizzes q ON qa.quiz_id = q.quiz_id
        JOIN courses c ON q.course_id = c.id
        WHERE qa.student_id = ? AND qa.completed_at IS NOT NULL
        UNION
        SELECT 
            'course_enrolled' as type,
            c.title,
            COALESCE(e.enrollment_date, e.enrolled_at) as date,
            NULL as score,
            c.title as course_name,
            COALESCE(c.slug, c.course_code, '') as course_code
        FROM enrollments e
        JOIN courses c ON e.course_id = c.id
        WHERE e.student_id = ?
        UNION
        SELECT 
            'assignment_submitted' as type,
            a.title,
            s.submitted_at as date,
            s.score as score,
            c.title as course_name,
            COALESCE(c.slug, c.course_code, '') as course_code
        FROM assignment_submissions s
        JOIN assignments a ON s.assignment_id = a.assignment_id
        JOIN courses c ON a.course_id = c.id
        WHERE s.student_id = ? AND s.submitted_at IS NOT NULL
        ORDER BY date DESC
        LIMIT 8
    ");
    $stmt->execute([$studentId, $studentId, $studentId]);
    $recentActivities = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recentActivities = [];
}

// Calculate progress for each course
$courseProgress = [];
$totalProgress = 0;
$completedCoursesCount = 0;

foreach ($enrolledCourses as $course) {
    $courseId = $course['course_id'] ?? $course['id'] ?? null;
    
    if ($courseId === null) {
        continue;
    }
    
    try {
        // Get total quizzes for this course
        $stmt = $pdo->prepare("SELECT COUNT(*) as total_quizzes FROM quizzes WHERE course_id = ?");
        $stmt->execute([$courseId]);
        $totalQuizzes = $stmt->fetch(PDO::FETCH_ASSOC)['total_quizzes'] ?? 0;
        
        // Get completed quizzes for this student in this course
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT q.quiz_id) as completed_quizzes
            FROM quiz_attempts qa
            JOIN quizzes q ON qa.quiz_id = q.quiz_id
            WHERE qa.student_id = ? 
            AND q.course_id = ? 
            AND qa.completed_at IS NOT NULL
        ");
        $stmt->execute([$studentId, $courseId]);
        $completedQuizzes = $stmt->fetch(PDO::FETCH_ASSOC)['completed_quizzes'] ?? 0;
        
        $percentage = ($totalQuizzes > 0) 
            ? round(($completedQuizzes / $totalQuizzes) * 100) 
            : 0;
            
        $courseProgress[$courseId] = [
            'percentage' => $percentage,
            'completed' => $completedQuizzes,
            'total' => $totalQuizzes,
            'course_name' => $course['course_name'] ?? $course['title'] ?? 'Unknown Course',
            'course_code' => $course['course_code'] ?? $course['slug'] ?? ''
        ];
        
        $totalProgress += $percentage;
        
        if ($percentage >= 100) {
            $completedCoursesCount++;
        }
    } catch (PDOException $e) {
        $courseProgress[$courseId] = [
            'percentage' => 0,
            'completed' => 0,
            'total' => 0,
            'course_name' => $course['course_name'] ?? $course['title'] ?? 'Unknown Course',
            'course_code' => $course['course_code'] ?? $course['slug'] ?? ''
        ];
    }
}

// Calculate overall progress
$overallProgress = $coursesCount > 0 ? round($totalProgress / $coursesCount) : 0;

// Pick first in-progress course for "Continue Learning"
$continueCourse = null;
foreach ($courseProgress as $cid => $p) {
    if (($p['percentage'] ?? 0) > 0 && ($p['percentage'] ?? 0) < 100) {
        $continueCourse = ['id' => $cid, 'name' => $p['course_name'], 'code' => $p['course_code'], 'pct' => $p['percentage']];
        break;
    }
}

// Get performance trend (last 6 months)
$performanceData = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(qa.completed_at, '%Y-%m') as month,
            AVG(qa.percentage) as avg_score,
            COUNT(*) as quiz_count
        FROM quiz_attempts qa
        WHERE qa.student_id = ? 
        AND qa.completed_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        AND qa.completed_at IS NOT NULL
        GROUP BY DATE_FORMAT(qa.completed_at, '%Y-%m')
        ORDER BY month
    ");
    $stmt->execute([$studentId]);
    $performanceData = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $performanceData = [];
}

// Get notifications count
$unreadNotifications = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE student_id = ? AND is_read = 0");
    $stmt->execute([$studentId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $unreadNotifications = $result['count'] ?? 0;
} catch (PDOException $e) {
    $unreadNotifications = 0;
}

// Get today's schedule
try {
    $stmt = $pdo->prepare("
        SELECT 
            c.title as course_name,
            COALESCE(c.slug, c.course_code, '') as course_code,
            s.schedule_date,
            s.start_time,
            s.end_time,
            s.type
        FROM schedules s
        JOIN courses c ON s.course_id = c.id
        JOIN enrollments e ON c.id = e.course_id
        WHERE e.student_id = ?
        AND DATE(s.schedule_date) = CURDATE()
        ORDER BY s.start_time
        LIMIT 3
    ");
    $stmt->execute([$studentId]);
    $todaysSchedule = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $todaysSchedule = [];
}

// Function to get activity icon
function getActivityIcon($type) {
    switch ($type) {
        case 'quiz_completed':
            return '<i class="fas fa-question-circle text-green-600 dark:text-green-400"></i>';
        case 'course_enrolled':
            return '<i class="fas fa-book text-blue-600 dark:text-blue-400"></i>';
        case 'assignment_submitted':
            return '<i class="fas fa-tasks text-purple-600 dark:text-purple-400"></i>';
        default:
            return '<i class="fas fa-history text-gray-400"></i>';
    }
}

// Function to format time ago
function timeAgo($date) {
    $time = strtotime($date);
    $time_difference = time() - $time;

    if ($time_difference < 1) { return 'just now'; }
    
    $condition = array(
        12 * 30 * 24 * 60 * 60 => 'year',
        30 * 24 * 60 * 60      => 'month',
        24 * 60 * 60           => 'day',
        60 * 60                => 'hour',
        60                     => 'minute',
        1                      => 'second'
    );

    foreach ($condition as $secs => $str) {
        $d = $time_difference / $secs;
        if ($d >= 1) {
            $t = round($d);
            return $t . ' ' . $str . ($t > 1 ? 's' : '') . ' ago';
        }
    }
}

$pageTitle = 'Dashboard';
$pageSubtitle = 'Track your learning progress and access your courses';
$currentPage = 'dashboard';
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo isset($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === 'enabled' ? 'dark' : ''; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - IQRA Online College</title>
    <link rel="icon" href="/Iqra-College/assets/images/iqra2.png" type="image/png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50: '#eff6ff',
                            100: '#dbeafe',
                            200: '#bfdbfe',
                            300: '#93c5fd',
                            400: '#60a5fa',
                            500: '#3b82f6',
                            600: '#2563eb',
                            700: '#1d4ed8',
                            800: '#1e40af',
                            900: '#1e3a8a',
                        }
                    },
                    animation: {
                        'float': 'float 6s ease-in-out infinite',
                        'pulse-slow': 'pulse 3s ease-in-out infinite',
                        'slide-in': 'slideIn 0.5s ease-out',
                        'bounce-slow': 'bounce 2s infinite',
                    },
                    keyframes: {
                        float: {
                            '0%, 100%': { transform: 'translateY(0)' },
                            '50%': { transform: 'translateY(-20px)' },
                        },
                        slideIn: {
                            '0%': { opacity: 0, transform: 'translateX(-20px)' },
                            '100%': { opacity: 1, transform: 'translateX(0)' },
                        }
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .gradient-text {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            background-size: 200% 200%;
            animation: gradient-shift 3s ease infinite;
        }
        @keyframes gradient-shift {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }
        .gradient-bg {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%);
            background-size: 200% 200%;
            animation: gradient-shift 3s ease infinite;
        }
        .glass-effect {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }
        .dark .glass-effect {
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.15);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        }
        .card-hover {
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
            overflow: hidden;
        }
        .card-hover::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }
        .card-hover:hover::before {
            left: 100%;
        }
        .card-hover:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15);
        }
        .dark .card-hover:hover {
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.4);
        }
        .progress-ring {
            transform: rotate(-90deg);
        }
        .progress-ring-circle {
            stroke-dasharray: 283;
            stroke-dashoffset: 283;
            transition: stroke-dashoffset 1s ease;
        }
        .sidebar-link {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
        }
        .sidebar-link::after {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%) scaleY(0);
            width: 3px;
            height: 0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            transition: all 0.3s ease;
            border-radius: 0 3px 3px 0;
        }
        .sidebar-link:hover {
            background: linear-gradient(90deg, rgba(59, 130, 246, 0.1), rgba(59, 130, 246, 0.05));
            transform: translateX(8px);
            padding-left: 1.25rem;
        }
        .sidebar-link:hover::after {
            height: 60%;
            transform: translateY(-50%) scaleY(1);
        }
        .dark .sidebar-link:hover {
            background: linear-gradient(90deg, rgba(59, 130, 246, 0.2), rgba(59, 130, 246, 0.1));
        }
        .sidebar-link.active {
            background: linear-gradient(90deg, rgba(59, 130, 246, 0.2), rgba(59, 130, 246, 0.1));
            border-right: 4px solid;
            border-image: linear-gradient(135deg, #667eea 0%, #764ba2 100%) 1;
        }
        .dark .sidebar-link.active {
            background: linear-gradient(90deg, rgba(59, 130, 246, 0.3), rgba(59, 130, 246, 0.15));
        }
        .sidebar-link.active::after {
            height: 70%;
            transform: translateY(-50%) scaleY(1);
        }
        .notification-badge {
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(1.1); }
        }
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .stagger-item {
            opacity: 0;
            transform: translateY(30px) scale(0.95);
            animation: slideInUp 0.6s cubic-bezier(0.4, 0, 0.2, 1) forwards;
        }
        @keyframes slideInUp {
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        .stagger-item:nth-child(1) { animation-delay: 0.1s; }
        .stagger-item:nth-child(2) { animation-delay: 0.2s; }
        .stagger-item:nth-child(3) { animation-delay: 0.3s; }
        .stagger-item:nth-child(4) { animation-delay: 0.4s; }
        .stagger-item:nth-child(5) { animation-delay: 0.5s; }
        .typewriter-text {
            overflow: hidden;
            border-right: .15em solid #3b82f6;
            white-space: nowrap;
            animation: typing 3.5s steps(40, end), blink-caret .75s step-end infinite;
        }
        @keyframes typing {
            from { width: 0 }
            to { width: 100% }
        }
        @keyframes blink-caret {
            from, to { border-color: transparent }
            50% { border-color: #3b82f6; }
        }
        .scrollbar-thin {
            scrollbar-width: thin;
            scrollbar-color: #cbd5e1 transparent;
        }
        .dark .scrollbar-thin {
            scrollbar-color: #4b5563 transparent;
        }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-200 min-h-screen transition-colors duration-300">
    <div class="flex">
        <?php include __DIR__ . '/../includes/student_sidebar.php'; ?>

        <main class="ml-0 lg:ml-64 flex-1 p-4 lg:p-8 transition-all duration-300">
            <?php include __DIR__ . '/../includes/student_header.php'; ?>

            <!-- Welcome banner -->
            <div class="mb-8 p-6 rounded-2xl bg-gradient-to-r from-primary-500 via-blue-600 to-primary-700 dark:from-primary-600 dark:via-blue-700 dark:to-primary-800 text-white shadow-xl stagger-item">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <h1 class="text-2xl sm:text-3xl font-bold">
                            Welcome back, <?php echo htmlspecialchars(explode(' ', $name)[0]); ?>!
                        </h1>
                        <p class="text-white/90 mt-1">
                            <?php
                            $hour = (int)date('G');
                            if ($hour < 12) echo 'Good morning';
                            elseif ($hour < 17) echo 'Good afternoon';
                            else echo 'Good evening';
                            ?>, here’s your learning overview.
                        </p>
                    </div>
                    <div class="flex items-center gap-3 text-white/90">
                        <i class="far fa-calendar-alt text-2xl"></i>
                        <span class="font-semibold"><?php echo date('l, F j, Y'); ?></span>
                    </div>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <!-- Enrolled Courses -->
                <div class="card-hover bg-gradient-to-br from-white to-blue-50 dark:from-gray-800 dark:to-gray-900 rounded-2xl p-6 border border-gray-200 dark:border-gray-700 shadow-lg hover:shadow-2xl stagger-item relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-primary-200 dark:bg-primary-900/30 rounded-full -mr-16 -mt-16 opacity-20"></div>
                    <div class="relative flex justify-between items-start">
                        <div class="flex-1">
                            <p class="text-gray-600 dark:text-gray-400 text-sm font-semibold mb-2 uppercase tracking-wide">Enrolled Courses</p>
                            <p class="text-4xl font-extrabold bg-gradient-to-r from-primary-600 to-primary-800 dark:from-primary-400 dark:to-primary-600 bg-clip-text text-transparent mb-3"><?php echo $coursesCount; ?></p>
                            <div class="flex items-center mt-4 space-x-2">
                                <div class="flex-1 bg-gray-200 dark:bg-gray-700 rounded-full h-2.5 overflow-hidden">
                                    <div class="bg-gradient-to-r from-primary-500 to-primary-700 dark:from-primary-400 dark:to-primary-600 h-2.5 rounded-full transition-all duration-1000" style="width: <?php echo $coursesCount > 0 ? min(100, ($completedCoursesCount/$coursesCount)*100) : 0; ?>%"></div>
                                </div>
                                <span class="text-xs font-semibold text-gray-700 dark:text-gray-300 whitespace-nowrap">
                                    <?php echo $completedCoursesCount; ?>/<?php echo $coursesCount; ?>
                                </span>
                            </div>
                        </div>
                        <div class="bg-gradient-to-br from-primary-500 to-primary-700 p-4 rounded-2xl shadow-lg transform hover:scale-110 transition-transform">
                            <i class="fas fa-book text-white text-2xl"></i>
                        </div>
                    </div>
                </div>
                
                <!-- Completed Quizzes -->
                <div class="card-hover bg-gradient-to-br from-white to-green-50 dark:from-gray-800 dark:to-gray-900 rounded-2xl p-6 border border-gray-200 dark:border-gray-700 shadow-lg hover:shadow-2xl stagger-item relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-green-200 dark:bg-green-900/30 rounded-full -mr-16 -mt-16 opacity-20"></div>
                    <div class="relative flex justify-between items-start">
                        <div class="flex-1">
                            <p class="text-gray-600 dark:text-gray-400 text-sm font-semibold mb-2 uppercase tracking-wide">Completed Quizzes</p>
                            <p class="text-4xl font-extrabold bg-gradient-to-r from-green-600 to-emerald-600 dark:from-green-400 dark:to-emerald-400 bg-clip-text text-transparent mb-3"><?php echo $quizzesCount; ?></p>
                            <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mt-2 flex items-center">
                                <i class="fas fa-arrow-up text-green-500 mr-2 animate-bounce"></i>
                                <?php echo $quizzesCount > 0 ? 'Active learner' : 'Start your first quiz'; ?>
                            </p>
                        </div>
                        <div class="bg-gradient-to-br from-green-500 to-emerald-600 p-4 rounded-2xl shadow-lg transform hover:scale-110 transition-transform">
                            <i class="fas fa-check-circle text-white text-2xl"></i>
                        </div>
                    </div>
                </div>
                
                <!-- Average Score -->
                <div class="card-hover bg-gradient-to-br from-white to-purple-50 dark:from-gray-800 dark:to-gray-900 rounded-2xl p-6 border border-gray-200 dark:border-gray-700 shadow-lg hover:shadow-2xl stagger-item relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-purple-200 dark:bg-purple-900/30 rounded-full -mr-16 -mt-16 opacity-20"></div>
                    <div class="relative flex justify-between items-start">
                        <div class="flex-1">
                            <p class="text-gray-600 dark:text-gray-400 text-sm font-semibold mb-2 uppercase tracking-wide">Average Score</p>
                            <p class="text-4xl font-extrabold bg-gradient-to-r from-purple-600 to-pink-600 dark:from-purple-400 dark:to-pink-400 bg-clip-text text-transparent mb-3"><?php echo number_format($avgScore, 1); ?>%</p>
                            <div class="flex items-center mt-2">
                                <i class="fas <?php echo $avgScore >= 70 ? 'fa-arrow-up text-green-500' : ($avgScore >= 50 ? 'fa-minus text-yellow-500' : 'fa-arrow-down text-red-500'); ?> text-sm mr-2"></i>
                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                    <?php echo $avgScore >= 70 ? 'Excellent' : ($avgScore >= 50 ? 'Good' : 'Needs improvement'); ?>
                                </span>
                            </div>
                        </div>
                        <div class="bg-gradient-to-br from-purple-500 to-pink-600 p-4 rounded-2xl shadow-lg transform hover:scale-110 transition-transform">
                            <i class="fas fa-chart-line text-white text-2xl"></i>
                        </div>
                    </div>
                </div>
                
                <!-- Overall Progress -->
                <div class="card-hover bg-gradient-to-br from-white to-blue-50 dark:from-gray-800 dark:to-gray-900 rounded-2xl p-6 border border-gray-200 dark:border-gray-700 shadow-lg hover:shadow-2xl stagger-item relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-blue-200 dark:bg-blue-900/30 rounded-full -mr-16 -mt-16 opacity-20"></div>
                    <div class="relative flex justify-between items-start">
                        <div class="flex-1">
                            <p class="text-gray-600 dark:text-gray-400 text-sm font-semibold mb-2 uppercase tracking-wide">Overall Progress</p>
                            <p class="text-4xl font-extrabold bg-gradient-to-r from-blue-600 to-cyan-600 dark:from-blue-400 dark:to-cyan-400 bg-clip-text text-transparent mb-3"><?php echo $overallProgress; ?>%</p>
                            <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mt-2">
                                <?php echo $overallProgress >= 80 ? '🎉 Great progress!' : '💪 Keep learning!'; ?>
                            </p>
                        </div>
                        <div class="relative w-20 h-20">
                            <svg class="progress-ring w-20 h-20 transform -rotate-90" viewBox="0 0 100 100">
                                <circle class="progress-ring-circle" 
                                        stroke="#e5e7eb" 
                                        stroke-width="10" 
                                        fill="transparent" 
                                        r="40" 
                                        cx="50" 
                                        cy="50"/>
                                <circle class="progress-ring-circle" 
                                        stroke="url(#gradient)" 
                                        stroke-width="10" 
                                        fill="transparent" 
                                        r="40" 
                                        cx="50" 
                                        cy="50"
                                        stroke-linecap="round"
                                        stroke-dasharray="251"
                                        stroke-dashoffset="<?php echo 251 - (251 * $overallProgress / 100); ?>"/>
                                <defs>
                                    <linearGradient id="gradient" x1="0%" y1="0%" x2="100%" y2="100%">
                                        <stop offset="0%" style="stop-color:#3b82f6;stop-opacity:1" />
                                        <stop offset="100%" style="stop-color:#06b6d4;stop-opacity:1" />
                                    </linearGradient>
                                </defs>
                            </svg>
                            <div class="absolute inset-0 flex items-center justify-center">
                                <span class="text-xs font-bold text-blue-600 dark:text-blue-400"><?php echo $overallProgress; ?>%</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Content Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Left Column -->
                <div class="lg:col-span-2 space-y-8">
                    <?php if ($continueCourse): ?>
                    <!-- Continue Learning -->
                    <a href="courses.php?id=<?php echo (int)$continueCourse['id']; ?>" class="block card-hover bg-white dark:bg-gray-800 rounded-2xl p-6 border-2 border-primary-200 dark:border-primary-800 hover:border-primary-400 dark:hover:border-primary-600 transition-all">
                        <div class="flex items-center justify-between gap-4">
                            <div class="flex items-center gap-4">
                                <div class="w-14 h-14 rounded-xl bg-gradient-to-br from-primary-500 to-primary-700 flex items-center justify-center shadow-lg">
                                    <i class="fas fa-play text-white text-xl"></i>
                                </div>
                                <div>
                                    <span class="text-xs font-semibold text-primary-600 dark:text-primary-400 uppercase tracking-wide">Continue learning</span>
                                    <h2 class="text-lg font-bold text-gray-800 dark:text-white mt-0.5"><?php echo htmlspecialchars($continueCourse['name']); ?></h2>
                                    <p class="text-sm text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($continueCourse['code']); ?></p>
                                </div>
                            </div>
                            <div class="text-right flex-shrink-0">
                                <div class="text-2xl font-bold text-primary-600 dark:text-primary-400"><?php echo (int)$continueCourse['pct']; ?>%</div>
                                <div class="w-24 h-2 bg-gray-200 dark:bg-gray-700 rounded-full mt-2 overflow-hidden">
                                    <div class="h-full bg-gradient-to-r from-primary-500 to-primary-600 rounded-full" style="width: <?php echo (int)$continueCourse['pct']; ?>%"></div>
                                </div>
                                <span class="text-xs text-gray-500 dark:text-gray-400 mt-1 inline-block">Resume</span>
                            </div>
                        </div>
                    </a>
                    <?php endif; ?>

                    <!-- Performance Chart -->
                    <div class="card-hover bg-white dark:bg-gray-800 rounded-2xl p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex justify-between items-center mb-6">
                            <h2 class="text-xl font-bold text-gray-800 dark:text-white">Performance Trend</h2>
                            <div class="flex items-center space-x-2">
                                <button class="text-sm px-3 py-1 bg-primary-100 dark:bg-primary-900/20 text-primary-600 dark:text-primary-400 rounded-lg font-medium">
                                    6 Months
                                </button>
                                <button class="text-sm px-3 py-1 bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400 rounded-lg font-medium">
                                    1 Year
                                </button>
                            </div>
                        </div>
                        
                        <div class="h-64">
                            <canvas id="performanceChart"></canvas>
                        </div>
                    </div>
                    
                    <!-- Enrolled Courses -->
                    <div class="card-hover bg-white dark:bg-gray-800 rounded-2xl p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex justify-between items-center mb-6">
                            <h2 class="text-xl font-bold text-gray-800 dark:text-white">My Courses</h2>
                            <a href="courses.php" class="text-primary-600 dark:text-primary-400 font-medium text-sm hover:underline flex items-center">
                                View All <i class="fas fa-arrow-right ml-2"></i>
                            </a>
                        </div>
                        
                        <div class="space-y-4">
                            <?php if (!empty($courseProgress)): ?>
                                <?php foreach (array_slice($courseProgress, 0, 4) as $courseId => $progress): ?>
                                    <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700/50 rounded-xl hover:bg-gray-100 dark:hover:bg-gray-700 transition-all cursor-pointer group"
                                         onclick="window.location.href='courses.php?id=<?php echo $courseId; ?>'">
                                        <div class="flex items-center space-x-4">
                                            <div class="relative">
                                                <div class="bg-gradient-to-br from-primary-500 to-primary-700 w-12 h-12 rounded-lg flex items-center justify-center group-hover:scale-110 transition-transform">
                                                    <i class="fas fa-book text-white"></i>
                                                </div>
                                                <?php if ($progress['percentage'] == 100): ?>
                                                    <div class="absolute -top-2 -right-2 w-6 h-6 bg-green-500 rounded-full flex items-center justify-center">
                                                        <i class="fas fa-check text-white text-xs"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <h3 class="font-bold text-gray-800 dark:text-white group-hover:text-primary-600 dark:group-hover:text-primary-400 transition-colors">
                                                    <?php echo htmlspecialchars($progress['course_name']); ?>
                                                </h3>
                                                <p class="text-sm text-gray-600 dark:text-gray-400"><?php echo $progress['course_code']; ?></p>
                                            </div>
                                        </div>
                                        
                                        <div class="text-right">
                                            <div class="flex items-center justify-end space-x-2 mb-2">
                                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300"><?php echo $progress['percentage']; ?>%</span>
                                                <div class="w-24 bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                                                    <div class="bg-gradient-to-r from-primary-500 to-primary-700 h-2 rounded-full" style="width: <?php echo $progress['percentage']; ?>%"></div>
                                                </div>
                                            </div>
                                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                                <?php echo $progress['completed']; ?> of <?php echo $progress['total']; ?> quizzes
                                            </p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-8">
                                    <div class="inline-block bg-gray-100 dark:bg-gray-700 p-6 rounded-full mb-4">
                                        <i class="fas fa-book text-gray-400 text-4xl"></i>
                                    </div>
                                    <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-2">No Courses Yet</h3>
                                    <p class="text-gray-600 dark:text-gray-400 mb-6">You haven't enrolled in any courses yet</p>
                                    <a href="courses.php" class="inline-flex items-center space-x-2 bg-primary-600 hover:bg-primary-700 text-white px-6 py-3 rounded-lg transition-colors">
                                        <i class="fas fa-plus"></i>
                                        <span>Browse Courses</span>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Right Column -->
                <div class="space-y-8">
                    <!-- Upcoming Assignments -->
                    <div class="card-hover bg-white dark:bg-gray-800 rounded-2xl p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex justify-between items-center mb-6">
                            <h2 class="text-xl font-bold text-gray-800 dark:text-white">Upcoming Assignments</h2>
                            <?php if (!empty($upcomingAssignments)): ?>
                                <span class="text-sm text-gray-600 dark:text-gray-400">
                                    <?php echo count($upcomingAssignments); ?> due
                                </span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="space-y-4">
                            <?php if (!empty($upcomingAssignments)): ?>
                                <?php foreach ($upcomingAssignments as $assignment): ?>
                                    <?php 
                                        $daysLeft = $assignment['days_left'] ?? 0;
                                        $isUrgent = $daysLeft <= 1;
                                        $isVeryUrgent = $daysLeft <= 0;
                                    ?>
                                    <div class="border border-gray-200 dark:border-gray-700 rounded-xl p-4 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                                        <div class="flex items-start justify-between mb-2">
                                            <h3 class="font-semibold text-gray-800 dark:text-white"><?php echo htmlspecialchars($assignment['title']); ?></h3>
                                            <span class="text-xs px-2 py-1 rounded-full 
                                                <?php echo $isVeryUrgent ? 'bg-red-500 text-white animate-pulse' : 
                                                      ($isUrgent ? 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-400' : 
                                                       'bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-400'); ?>">
                                                <?php echo $daysLeft <= 0 ? 'Due today' : ($daysLeft . ' day' . ($daysLeft != 1 ? 's' : '') . ' left'); ?>
                                            </span>
                                        </div>
                                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">
                                            <i class="fas fa-book mr-2"></i>
                                            <?php echo htmlspecialchars($assignment['course_name']); ?>
                                        </p>
                                        <div class="flex items-center justify-between text-sm">
                                            <span class="text-gray-500 dark:text-gray-400">
                                                <i class="far fa-clock mr-1"></i>
                                                Due: <?php echo date('M d, Y', strtotime($assignment['due_date'])); ?>
                                            </span>
                                            <a href="assignments.php" class="text-primary-600 dark:text-primary-400 hover:underline text-sm">
                                                View Details
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-8">
                                    <div class="inline-block bg-green-100 dark:bg-green-900/20 p-4 rounded-full mb-4">
                                        <i class="fas fa-check-circle text-green-600 dark:text-green-400 text-2xl"></i>
                                    </div>
                                    <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-2">No Pending Assignments</h3>
                                    <p class="text-gray-600 dark:text-gray-400">You're all caught up!</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Today's Schedule -->
                    <?php if (!empty($todaysSchedule)): ?>
                    <div class="card-hover bg-gradient-to-br from-primary-50 to-blue-50 dark:from-gray-800 dark:to-gray-900 border border-primary-200 dark:border-primary-900/30 rounded-2xl p-6">
                        <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-6">Today's Schedule</h2>
                        
                        <div class="space-y-4">
                            <?php foreach ($todaysSchedule as $schedule): ?>
                                <div class="bg-white/50 dark:bg-gray-700/30 backdrop-blur-sm rounded-xl p-4">
                                    <div class="flex items-start justify-between mb-2">
                                        <div>
                                            <h3 class="font-semibold text-gray-800 dark:text-white"><?php echo htmlspecialchars($schedule['course_name']); ?></h3>
                                            <p class="text-sm text-gray-600 dark:text-gray-400"><?php echo $schedule['course_code']; ?></p>
                                        </div>
                                        <span class="text-xs px-2 py-1 bg-primary-100 dark:bg-primary-900/30 text-primary-800 dark:text-primary-400 rounded-full capitalize">
                                            <?php echo $schedule['type']; ?>
                                        </span>
                                    </div>
                                    <div class="flex items-center text-sm text-gray-600 dark:text-gray-400">
                                        <i class="far fa-clock mr-2"></i>
                                        <span><?php echo date('g:i A', strtotime($schedule['start_time'])); ?> - <?php echo date('g:i A', strtotime($schedule['end_time'])); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Recent Activity -->
                    <div class="card-hover bg-white dark:bg-gray-800 rounded-2xl p-6 border border-gray-200 dark:border-gray-700">
                        <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-6">Recent Activity</h2>
                        
                        <div class="space-y-4">
                            <?php if (!empty($recentActivities)): ?>
                                <?php foreach ($recentActivities as $activity): ?>
                                    <div class="flex items-start space-x-3">
                                        <div class="mt-1">
                                            <div class="w-8 h-8 rounded-full flex items-center justify-center bg-gray-100 dark:bg-gray-700">
                                                <?php echo getActivityIcon($activity['type']); ?>
                                            </div>
                                        </div>
                                        <div class="flex-1">
                                            <p class="text-sm font-medium text-gray-800 dark:text-white">
                                                <?php 
                                                    switch ($activity['type']) {
                                                        case 'quiz_completed':
                                                            echo 'Completed quiz: ' . htmlspecialchars($activity['quiz_title']);
                                                            break;
                                                        case 'course_enrolled':
                                                            echo 'Enrolled in: ' . htmlspecialchars($activity['course_name']);
                                                            break;
                                                        case 'assignment_submitted':
                                                            echo 'Submitted assignment: ' . htmlspecialchars($activity['title']);
                                                            break;
                                                        default:
                                                            echo 'Activity recorded';
                                                    }
                                                ?>
                                            </p>
                                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                                <i class="far fa-clock mr-1"></i>
                                                <?php echo timeAgo($activity['date']); ?>
                                                <?php if ($activity['score']): ?>
                                                    • <i class="fas fa-chart-line mr-1"></i> Score: <?php echo number_format($activity['score'], 1); ?>%
                                                <?php endif; ?>
                                            </p>
                                            <?php if ($activity['course_code']): ?>
                                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                                <i class="fas fa-book mr-1"></i> <?php echo $activity['course_code']; ?>
                                            </p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-history text-gray-300 dark:text-gray-600 text-2xl mb-2"></i>
                                    <p class="text-gray-600 dark:text-gray-400 text-sm">No recent activity</p>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (!empty($recentActivities)): ?>
                        <a href="results.php" class="block text-center mt-6 text-primary-600 dark:text-primary-400 font-medium text-sm hover:underline">
                            <i class="fas fa-chart-bar mr-2"></i> View All Activity
                        </a>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Quick Actions -->
                    <div class="card-hover bg-white dark:bg-gray-800 rounded-2xl p-6 border border-gray-200 dark:border-gray-700">
                        <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-6">Quick Actions</h2>
                        
                        <div class="grid grid-cols-2 gap-3">
                            <a href="courses.php" 
                               class="flex flex-col items-center justify-center bg-primary-50 dark:bg-primary-900/20 p-4 rounded-xl hover:bg-primary-100 dark:hover:bg-primary-900/30 transition-colors group border border-transparent hover:border-primary-200 dark:hover:border-primary-800">
                                <i class="fas fa-book text-primary-600 dark:text-primary-400 text-xl mb-2 group-hover:scale-110 transition-transform"></i>
                                <p class="text-sm font-medium text-gray-800 dark:text-white">Browse Courses</p>
                            </a>
                            
                            <a href="quizzes.php" 
                               class="flex flex-col items-center justify-center bg-green-50 dark:bg-green-900/20 p-4 rounded-xl hover:bg-green-100 dark:hover:bg-green-900/30 transition-colors group border border-transparent hover:border-green-200 dark:hover:border-green-800">
                                <i class="fas fa-question-circle text-green-600 dark:text-green-400 text-xl mb-2 group-hover:scale-110 transition-transform"></i>
                                <p class="text-sm font-medium text-gray-800 dark:text-white">Take Quiz</p>
                            </a>
                            
                            <a href="assignments.php" 
                               class="flex flex-col items-center justify-center bg-amber-50 dark:bg-amber-900/20 p-4 rounded-xl hover:bg-amber-100 dark:hover:bg-amber-900/30 transition-colors group border border-transparent hover:border-amber-200 dark:hover:border-amber-800">
                                <i class="fas fa-tasks text-amber-600 dark:text-amber-400 text-xl mb-2 group-hover:scale-110 transition-transform"></i>
                                <p class="text-sm font-medium text-gray-800 dark:text-white">Assignments</p>
                            </a>
                            
                            <a href="messages.php" 
                               class="flex flex-col items-center justify-center bg-purple-50 dark:bg-purple-900/20 p-4 rounded-xl hover:bg-purple-100 dark:hover:bg-purple-900/30 transition-colors group border border-transparent hover:border-purple-200 dark:hover:border-purple-800">
                                <i class="fas fa-comments text-purple-600 dark:text-purple-400 text-xl mb-2 group-hover:scale-110 transition-transform"></i>
                                <p class="text-sm font-medium text-gray-800 dark:text-white">AI Assistant</p>
                            </a>

                            <button type="button" id="contact-quick-action"
                                class="flex flex-col items-center justify-center bg-sky-50 dark:bg-sky-900/20 p-4 rounded-xl hover:bg-sky-100 dark:hover:bg-sky-900/30 transition-colors group border border-transparent hover:border-sky-200 dark:hover:border-sky-800">
                                <i class="fas fa-headset text-sky-600 dark:text-sky-400 text-xl mb-2 group-hover:scale-110 transition-transform"></i>
                                <p class="text-sm font-medium text-gray-800 dark:text-white">Contact</p>
                            </button>
                            
                            <a href="profile.php" 
                               class="col-span-2 flex flex-col items-center justify-center bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-700/50 transition-colors group border border-slate-200 dark:border-slate-700">
                                <i class="fas fa-user text-slate-600 dark:text-slate-400 text-xl mb-2 group-hover:scale-110 transition-transform"></i>
                                <p class="text-sm font-medium text-gray-800 dark:text-white">Profile &amp; Settings</p>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Motivational Quote -->
            <div class="mt-8 text-center fade-in">
                <div class="inline-block bg-gradient-to-r from-primary-500 via-purple-500 to-pink-500 text-white px-8 py-4 rounded-2xl shadow-2xl transform hover:scale-105 transition-transform">
                    <div class="flex items-center justify-center mb-2">
                        <i class="fas fa-quote-left text-2xl opacity-50 mr-3"></i>
                        <p class="text-base font-medium italic max-w-2xl">
                            Education is the most powerful weapon which you can use to change the world.
                        </p>
                        <i class="fas fa-quote-right text-2xl opacity-50 ml-3"></i>
                    </div>
                    <p class="text-sm mt-3 opacity-90 font-semibold">- Nelson Mandela</p>
                </div>
            </div>
        </main>
    </div>


    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Mobile menu toggle
            const mobileMenuToggle = document.getElementById('mobile-menu-toggle');
            const sidebar = document.getElementById('sidebar');
            const sidebarOverlay = document.getElementById('sidebar-overlay');
            
            if (mobileMenuToggle) {
                mobileMenuToggle.addEventListener('click', () => {
                    sidebar.classList.remove('hidden');
                    sidebarOverlay.classList.remove('hidden');
                });
            }
            
            if (sidebarOverlay) {
                sidebarOverlay.addEventListener('click', () => {
                    sidebar.classList.add('hidden');
                    sidebarOverlay.classList.add('hidden');
                });
            }
            
            // User dropdown menu
            const userMenuButton = document.getElementById('user-menu-button');
            const userDropdown = document.getElementById('user-dropdown');
            
            if (userMenuButton && userDropdown) {
                userMenuButton.addEventListener('click', function(e) {
                    e.stopPropagation();
                    userDropdown.classList.toggle('hidden');
                });
                
                // Close dropdown when clicking outside
                document.addEventListener('click', function(e) {
                    if (!userMenuButton.contains(e.target) && !userDropdown.contains(e.target)) {
                        userDropdown.classList.add('hidden');
                    }
                });
                
                // Prevent dropdown from closing when clicking inside
                userDropdown.addEventListener('click', function(e) {
                    e.stopPropagation();
                });
            }

            // Contact footer toggle
            
            // Performance Chart
            const performanceData = <?php echo json_encode($performanceData); ?>;
            if (performanceData.length > 0) {
                const ctx = document.getElementById('performanceChart').getContext('2d');
                
                const labels = performanceData.map(item => {
                    const [year, month] = item.month.split('-');
                    return new Date(year, month - 1).toLocaleDateString('en-US', { month: 'short', year: '2-digit' });
                });
                
                const scores = performanceData.map(item => item.avg_score);
                const quizCounts = performanceData.map(item => item.quiz_count);
                
                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [
                            {
                                label: 'Average Score (%)',
                                data: scores,
                                borderColor: '#3b82f6',
                                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                                borderWidth: 3,
                                fill: true,
                                tension: 0.4,
                                yAxisID: 'y'
                            },
                            {
                                label: 'Quizzes Taken',
                                data: quizCounts,
                                borderColor: '#10b981',
                                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                                borderWidth: 2,
                                fill: false,
                                tension: 0.4,
                                yAxisID: 'y1',
                                borderDash: [5, 5]
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: {
                            mode: 'index',
                            intersect: false,
                        },
                        scales: {
                            x: {
                                grid: {
                                    color: 'rgba(0, 0, 0, 0.05)'
                                },
                                ticks: {
                                    color: '#6b7280'
                                }
                            },
                            y: {
                                type: 'linear',
                                display: true,
                                position: 'left',
                                grid: {
                                    color: 'rgba(0, 0, 0, 0.05)'
                                },
                                ticks: {
                                    color: '#6b7280',
                                    callback: function(value) {
                                        return value + '%';
                                    }
                                },
                                min: 0,
                                max: 100
                            },
                            y1: {
                                type: 'linear',
                                display: true,
                                position: 'right',
                                grid: {
                                    drawOnChartArea: false,
                                },
                                ticks: {
                                    color: '#6b7280'
                                },
                                min: 0
                            }
                        },
                        plugins: {
                            legend: {
                                labels: {
                                    color: '#6b7280',
                                    usePointStyle: true,
                                    pointStyle: 'circle'
                                }
                            },
                            tooltip: {
                                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                titleColor: '#ffffff',
                                bodyColor: '#ffffff',
                                borderColor: '#3b82f6',
                                borderWidth: 1,
                                callbacks: {
                                    label: function(context) {
                                        let label = context.dataset.label || '';
                                        if (label) {
                                            label += ': ';
                                        }
                                        if (context.datasetIndex === 0) {
                                            label += context.parsed.y.toFixed(1) + '%';
                                        } else {
                                            label += context.parsed.y + ' quizzes';
                                        }
                                        return label;
                                    }
                                }
                            }
                        }
                    }
                });
            } else {
                // Show placeholder message if no data
                document.getElementById('performanceChart').parentElement.innerHTML = `
                    <div class="text-center py-12">
                        <div class="inline-block bg-gray-100 dark:bg-gray-700 p-6 rounded-full mb-4">
                            <i class="fas fa-chart-line text-gray-400 text-4xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-2">No Performance Data Yet</h3>
                        <p class="text-gray-600 dark:text-gray-400">Complete some quizzes to see your performance trend</p>
                    </div>
                `;
            }
            
            // Animate progress rings
            const progressRings = document.querySelectorAll('.progress-ring-circle');
            progressRings.forEach(ring => {
                const offset = ring.getAttribute('stroke-dashoffset');
                ring.style.strokeDashoffset = offset;
            });
            
            // Typewriter effect
            const typewriterText = document.querySelector('.typewriter-text');
            if (typewriterText) {
                setTimeout(() => {
                    typewriterText.style.borderRight = 'none';
                }, 3500);
            }
            
            // Auto-hide sidebar on window resize
            window.addEventListener('resize', () => {
                if (window.innerWidth >= 1024) {
                    sidebar.classList.remove('hidden');
                    sidebarOverlay.classList.add('hidden');
                } else {
                    sidebar.classList.add('hidden');
                }
            });
            
            // Add animation to cards on scroll
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };
            
            const observer = new IntersectionObserver(function(entries) {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('fade-in');
                    }
                });
            }, observerOptions);
            
            document.querySelectorAll('.card-hover').forEach(card => {
                observer.observe(card);
            });
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + M to toggle mobile menu
            if ((e.ctrlKey || e.metaKey) && e.key === 'm') {
                e.preventDefault();
                const sidebar = document.getElementById('sidebar');
                const sidebarOverlay = document.getElementById('sidebar-overlay');
                if (window.innerWidth < 1024) {
                    sidebar.classList.toggle('hidden');
                    sidebarOverlay.classList.toggle('hidden');
                }
            }
            
            // Ctrl/Cmd + D to toggle dark mode
            if ((e.ctrlKey || e.metaKey) && e.key === 'd') {
                e.preventDefault();
                document.getElementById('dark-mode-toggle').click();
            }
        });
        
    </script>

    <!-- Mobile Sidebar Overlay -->
    <div id="sidebar-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-30 hidden lg:hidden"></div>
    
    <?php include __DIR__ . '/../includes/student_ai_button.php'; ?>
</body>
</html>
