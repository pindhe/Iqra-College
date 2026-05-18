<?php
/**
 * Teacher - Analytics
 * Course performance analytics and statistics
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

requireRole('teacher');

$teacherId = getCurrentUserId();
$pdo = getDBConnection();
$name = getCurrentUserName();

// Get overall statistics
try {
    $stmt = $pdo->prepare("SELECT 
                          COUNT(DISTINCT c.id) as total_courses,
                          COUNT(DISTINCT e.student_id) as total_students,
                          COUNT(DISTINCT l.id) as total_lessons,
                          COUNT(DISTINCT q.id) as total_quizzes,
                          AVG(e.progress) as avg_progress
                          FROM courses c
                          LEFT JOIN enrollments e ON c.id = e.course_id
                          LEFT JOIN lessons l ON c.id = l.course_id
                          LEFT JOIN quizzes q ON c.id = q.course_id
                          WHERE c.teacher_id = ?");
    $stmt->execute([$teacherId]);
    $stats = $stmt->fetch();
} catch (PDOException $e) {
    $stats = ['total_courses' => 0, 'total_students' => 0, 'total_lessons' => 0, 'total_quizzes' => 0, 'avg_progress' => 0];
}

// Get course performance
try {
    $stmt = $pdo->prepare("SELECT c.id, c.title, 
                          COUNT(DISTINCT e.student_id) as student_count,
                          AVG(e.progress) as avg_progress,
                          COUNT(DISTINCT l.id) as lesson_count
                          FROM courses c
                          LEFT JOIN enrollments e ON c.id = e.course_id
                          LEFT JOIN lessons l ON c.id = l.course_id
                          WHERE c.teacher_id = ?
                          GROUP BY c.id
                          ORDER BY avg_progress DESC");
    $stmt->execute([$teacherId]);
    $coursePerformance = $stmt->fetchAll();
} catch (PDOException $e) {
    $coursePerformance = [];
}

// Get quiz statistics
try {
    $stmt = $pdo->prepare("SELECT q.id, q.title, c.title as course_title,
                          COUNT(qa.id) as attempt_count,
                          AVG(qa.percentage) as avg_score
                          FROM quizzes q
                          JOIN courses c ON q.course_id = c.id
                          LEFT JOIN quiz_attempts qa ON q.id = qa.quiz_id
                          WHERE c.teacher_id = ?
                          GROUP BY q.id
                          ORDER BY attempt_count DESC
                          LIMIT 10");
    $stmt->execute([$teacherId]);
    $quizStats = $stmt->fetchAll();
} catch (PDOException $e) {
    $quizStats = [];
}

$pageTitle = 'Analytics';
$currentPage = 'analytics';
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo isset($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === 'enabled' ? 'dark' : ''; ?>">
<head>
    <link rel="icon" href="/Iqra-College/assets/images/iqra2.png" type="image/png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - Teacher</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50: '#eff6ff', 100: '#dbeafe', 200: '#bfdbfe', 300: '#93c5fd',
                            400: '#60a5fa', 500: '#3b82f6', 600: '#2563eb', 700: '#1d4ed8',
                            800: '#1e40af', 900: '#1e3a8a',
                        }
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .fade-in { animation: fadeIn 0.6s ease-out; }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body class="bg-gradient-to-br from-blue-50 via-purple-50 to-pink-50 dark:from-gray-900 dark:via-gray-800 dark:to-gray-900 min-h-screen">
    <?php include __DIR__ . '/../includes/teacher_sidebar.php'; ?>
    
    <div class="lg:ml-64">
        <nav class="bg-white dark:bg-gray-800 shadow-xl border-b border-gray-200 dark:border-gray-700">
            <div class="px-6 py-4">
                <div class="flex justify-between items-center">
                    <div class="flex items-center space-x-4">
                        <button id="mobile-menu-toggle" class="lg:hidden text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 p-2 rounded-lg transition-colors">
                            <i class="fas fa-bars text-xl"></i>
                        </button>
                        <div>
                            <h1 class="text-2xl font-extrabold text-gray-800 dark:text-white"><?php echo $pageTitle; ?></h1>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Course performance insights</p>
                        </div>
                    </div>
                    <div class="flex items-center space-x-3">
                        <?php include __DIR__ . '/../includes/teacher_header.php'; ?>
                    </div>
                </div>
            </div>
        </nav>

        <div class="p-6 lg:p-8">
            <!-- Overall Stats -->
            <div class="grid md:grid-cols-4 gap-6 mb-8 fade-in">
                <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-2xl shadow-xl p-6 text-white">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm opacity-90 mb-2">Total Courses</p>
                            <p class="text-4xl font-extrabold"><?php echo intval($stats['total_courses']); ?></p>
                        </div>
                        <i class="fas fa-book text-4xl opacity-20"></i>
                    </div>
                </div>
                
                <div class="bg-gradient-to-br from-green-500 to-emerald-600 rounded-2xl shadow-xl p-6 text-white">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm opacity-90 mb-2">Total Students</p>
                            <p class="text-4xl font-extrabold"><?php echo intval($stats['total_students']); ?></p>
                        </div>
                        <i class="fas fa-users text-4xl opacity-20"></i>
                    </div>
                </div>
                
                <div class="bg-gradient-to-br from-purple-500 to-pink-600 rounded-2xl shadow-xl p-6 text-white">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm opacity-90 mb-2">Total Lessons</p>
                            <p class="text-4xl font-extrabold"><?php echo intval($stats['total_lessons']); ?></p>
                        </div>
                        <i class="fas fa-book-open text-4xl opacity-20"></i>
                    </div>
                </div>
                
                <div class="bg-gradient-to-br from-orange-500 to-red-600 rounded-2xl shadow-xl p-6 text-white">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm opacity-90 mb-2">Avg Progress</p>
                            <p class="text-4xl font-extrabold"><?php echo round($stats['avg_progress'] ?? 0); ?>%</p>
                        </div>
                        <i class="fas fa-chart-line text-4xl opacity-20"></i>
                    </div>
                </div>
            </div>

            <!-- Course Performance -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-8 mb-8 fade-in">
                <h2 class="text-2xl font-bold text-gray-800 dark:text-white mb-6">
                    <i class="fas fa-chart-bar mr-2"></i>Course Performance
                </h2>
                
                <?php if (empty($coursePerformance)): ?>
                    <p class="text-gray-600 dark:text-gray-400">No course data available.</p>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($coursePerformance as $course): 
                            $progress = round($course['avg_progress'] ?? 0);
                        ?>
                            <div class="border-2 border-gray-200 dark:border-gray-700 rounded-xl p-6 hover:border-primary-400 dark:hover:border-primary-600 transition-all">
                                <div class="flex items-center justify-between mb-4">
                                    <div class="flex-1">
                                        <h3 class="text-xl font-bold text-gray-800 dark:text-white mb-2">
                                            <?php echo htmlspecialchars($course['title']); ?>
                                        </h3>
                                        <div class="flex items-center space-x-6 text-sm text-gray-600 dark:text-gray-400">
                                            <span><i class="fas fa-users mr-1"></i><?php echo intval($course['student_count']); ?> students</span>
                                            <span><i class="fas fa-book-open mr-1"></i><?php echo intval($course['lesson_count']); ?> lessons</span>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-3xl font-extrabold text-primary-600 dark:text-primary-400"><?php echo $progress; ?>%</div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400">Avg Progress</div>
                                    </div>
                                </div>
                                <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-4">
                                    <div class="bg-gradient-to-r from-primary-500 to-purple-600 h-4 rounded-full transition-all" 
                                         style="width: <?php echo $progress; ?>%"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Quiz Statistics -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-8 fade-in">
                <h2 class="text-2xl font-bold text-gray-800 dark:text-white mb-6">
                    <i class="fas fa-question-circle mr-2"></i>Quiz Performance
                </h2>
                
                <?php if (empty($quizStats)): ?>
                    <p class="text-gray-600 dark:text-gray-400">No quiz data available.</p>
                <?php else: ?>
                    <div class="grid md:grid-cols-2 gap-4">
                        <?php foreach ($quizStats as $quiz): 
                            $avgScore = round($quiz['avg_score'] ?? 0);
                        ?>
                            <div class="border-2 border-gray-200 dark:border-gray-700 rounded-xl p-5 hover:border-primary-400 dark:hover:border-primary-600 transition-all">
                                <h4 class="font-bold text-gray-800 dark:text-white mb-2">
                                    <?php echo htmlspecialchars($quiz['title']); ?>
                                </h4>
                                <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">
                                    <i class="fas fa-book mr-1"></i><?php echo htmlspecialchars($quiz['course_title']); ?>
                                </p>
                                <div class="flex items-center justify-between">
                                    <div>
                                        <span class="text-sm text-gray-600 dark:text-gray-400">Attempts: </span>
                                        <span class="font-bold text-primary-600 dark:text-primary-400"><?php echo intval($quiz['attempt_count']); ?></span>
                                    </div>
                                    <div class="text-right">
                                        <span class="text-2xl font-extrabold text-green-600 dark:text-green-400"><?php echo $avgScore; ?>%</span>
                                        <div class="text-xs text-gray-500 dark:text-gray-400">Avg Score</div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Ensure dark mode toggle works on this page
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
</body>
</html>
