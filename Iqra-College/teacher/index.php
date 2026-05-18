<?php
/**
 * Teacher Dashboard
 * Modern dashboard with stats, quick actions, and recent activities
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

requireRole('teacher');

$teacherId = getCurrentUserId();
$pdo = getDBConnection();
$name = getCurrentUserName();

// Get teacher's courses count (using RBAC if available)
try {
    $accessibleCourses = getTeacherAccessibleCourses($teacherId);
    $coursesCount = count($accessibleCourses);
} catch (Exception $e) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM courses WHERE teacher_id = ?");
    $stmt->execute([$teacherId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $coursesCount = isset($result['count']) ? intval($result['count']) : 0;
}

// Get teacher's lessons count
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM lessons l 
                          JOIN courses c ON l.course_id = c.id 
                          WHERE c.teacher_id = ?");
    $stmt->execute([$teacherId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $lessonsCount = isset($result['count']) ? intval($result['count']) : 0;
} catch (PDOException $e) {
    $lessonsCount = 0;
}

// Get teacher's quizzes count
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM quizzes q 
                          JOIN courses c ON q.course_id = c.id 
                          WHERE c.teacher_id = ?");
    $stmt->execute([$teacherId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $quizzesCount = isset($result['count']) ? intval($result['count']) : 0;
} catch (PDOException $e) {
    $quizzesCount = 0;
}

// Get teacher's assignments count
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM assignments a 
                          JOIN courses c ON a.course_id = c.id 
                          WHERE c.teacher_id = ?");
    $stmt->execute([$teacherId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $assignmentsCount = isset($result['count']) ? intval($result['count']) : 0;
} catch (PDOException $e) {
    $assignmentsCount = 0;
}

// Get total students enrolled in teacher's courses
try {
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT e.student_id) as count 
                          FROM enrollments e
                          JOIN courses c ON e.course_id = c.id
                          WHERE c.teacher_id = ?");
    $stmt->execute([$teacherId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $studentsCount = isset($result['count']) ? intval($result['count']) : 0;
} catch (PDOException $e) {
    $studentsCount = 0;
}

// Get recent courses (limit 5)
try {
    $stmt = $pdo->prepare("SELECT c.*, 
                          (SELECT COUNT(*) FROM enrollments WHERE course_id = c.id) as enrolled_count
                          FROM courses c 
                          WHERE c.teacher_id = ? 
                          ORDER BY c.created_at DESC, c.id DESC 
                          LIMIT 5");
    $stmt->execute([$teacherId]);
    $recentCourses = $stmt->fetchAll();
} catch (PDOException $e) {
    $recentCourses = [];
}

// Get pending assignments to grade
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as count 
                          FROM assignment_submissions s
                          JOIN assignments a ON s.assignment_id = a.id
                          JOIN courses c ON a.course_id = c.id
                          WHERE c.teacher_id = ? AND (s.status = 'submitted' OR s.status IS NULL)");
    $stmt->execute([$teacherId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $pendingGrading = isset($result['count']) ? intval($result['count']) : 0;
} catch (PDOException $e) {
    $pendingGrading = 0;
}

$pageTitle = 'Dashboard';
$currentPage = 'index';
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo isset($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === 'enabled' ? 'dark' : ''; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - Teacher</title>
    <link rel="icon" href="/Iqra-College/assets/images/iqra2.png" type="image/png">
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
        .fade-in {
            animation: fadeIn 0.6s ease-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .stat-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .stat-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }
        .action-card {
            transition: all 0.3s ease;
        }
        .action-card:hover {
            transform: translateY(-5px) scale(1.05);
        }
    </style>
</head>
<body class="bg-gradient-to-br from-blue-50 via-purple-50 to-pink-50 dark:from-gray-900 dark:via-gray-800 dark:to-gray-900 min-h-screen">
    <?php include __DIR__ . '/../includes/teacher_sidebar.php'; ?>
    
    <div class="lg:ml-64">
        <!-- Top Navigation -->
        <nav class="bg-white dark:bg-gray-800 shadow-xl border-b border-gray-200 dark:border-gray-700">
            <div class="px-6 py-4">
                <div class="flex justify-between items-center">
                    <div class="flex items-center space-x-4">
                        <button id="mobile-menu-toggle" class="lg:hidden text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 p-2 rounded-lg transition-colors">
                            <i class="fas fa-bars text-xl"></i>
                        </button>
                        <div>
                            <h1 class="text-2xl font-extrabold text-gray-800 dark:text-white"><?php echo $pageTitle; ?></h1>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Teacher dashboard</p>
                        </div>
                    </div>
                    <div class="flex items-center space-x-3">
                        <?php include __DIR__ . '/../includes/teacher_header.php'; ?>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Dashboard Content -->
        <div class="p-6 lg:p-8">
        <!-- Pending grading alert -->
        <?php if ($pendingGrading > 0): ?>
        <div class="mb-6 p-4 rounded-xl bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 flex items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-amber-500 flex items-center justify-center text-white">
                    <i class="fas fa-clipboard-check"></i>
                </div>
                <div>
                    <p class="font-bold text-amber-800 dark:text-amber-200"><?php echo (int)$pendingGrading; ?> submission<?php echo $pendingGrading !== 1 ? 's' : ''; ?> pending to grade</p>
                    <p class="text-sm text-amber-700 dark:text-amber-300">Review and grade student work in Assignments.</p>
                </div>
            </div>
            <a href="assignments.php" class="flex-shrink-0 inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-white font-semibold transition-colors">
                <i class="fas fa-arrow-right"></i> Grade now
            </a>
        </div>
        <?php endif; ?>

        <!-- Welcome banner -->
        <div class="mb-8 p-6 rounded-2xl bg-gradient-to-r from-primary-500 via-blue-600 to-primary-700 dark:from-primary-600 dark:via-blue-700 dark:to-primary-800 text-white shadow-xl">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h2 class="text-2xl sm:text-3xl font-bold">Welcome back, <?php echo htmlspecialchars(explode(' ', $name)[0]); ?>!</h2>
                    <p class="text-white/90 mt-1">
                        <?php
                        $hour = (int)date('G');
                        if ($hour < 12) echo 'Good morning';
                        elseif ($hour < 17) echo 'Good afternoon';
                        else echo 'Good evening';
                        ?> — here’s your teaching overview.
                    </p>
                </div>
                <div class="flex items-center gap-3 text-white/90">
                    <i class="far fa-calendar-alt text-xl"></i>
                    <span class="font-semibold"><?php echo date('l, F j, Y'); ?></span>
                </div>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="grid md:grid-cols-2 lg:grid-cols-5 gap-6 mb-8 fade-in">
            <!-- My Courses Card -->
            <div class="stat-card bg-gradient-to-br from-blue-500 to-blue-600 rounded-3xl shadow-2xl p-6 text-white">
                <div class="flex items-center justify-between mb-4">
                    <div class="bg-white/20 p-3 rounded-xl">
                        <i class="fas fa-book text-2xl"></i>
                    </div>
                    <div class="text-right">
                        <p class="text-sm opacity-90 font-semibold">My Courses</p>
                        <p class="text-4xl font-extrabold"><?php echo intval($coursesCount); ?></p>
                    </div>
                </div>
                <a href="list_courses.php" class="text-sm opacity-90 hover:opacity-100 flex items-center">
                    View all <i class="fas fa-arrow-right ml-2"></i>
                </a>
            </div>
            
            <!-- My Lessons Card -->
            <div class="stat-card bg-gradient-to-br from-green-500 to-emerald-600 rounded-3xl shadow-2xl p-6 text-white">
                <div class="flex items-center justify-between mb-4">
                    <div class="bg-white/20 p-3 rounded-xl">
                        <i class="fas fa-book-open text-2xl"></i>
                    </div>
                    <div class="text-right">
                        <p class="text-sm opacity-90 font-semibold">Lessons</p>
                        <p class="text-4xl font-extrabold"><?php echo intval($lessonsCount); ?></p>
                    </div>
                </div>
                <a href="lessons.php" class="text-sm opacity-90 hover:opacity-100 flex items-center">
                    Manage <i class="fas fa-arrow-right ml-2"></i>
                </a>
            </div>
            
            <!-- My Quizzes Card -->
            <div class="stat-card bg-gradient-to-br from-purple-500 to-pink-600 rounded-3xl shadow-2xl p-6 text-white">
                <div class="flex items-center justify-between mb-4">
                    <div class="bg-white/20 p-3 rounded-xl">
                        <i class="fas fa-question-circle text-2xl"></i>
                    </div>
                    <div class="text-right">
                        <p class="text-sm opacity-90 font-semibold">Quizzes</p>
                        <p class="text-4xl font-extrabold"><?php echo intval($quizzesCount); ?></p>
                    </div>
                </div>
                <a href="quizzes.php" class="text-sm opacity-90 hover:opacity-100 flex items-center">
                    Create <i class="fas fa-arrow-right ml-2"></i>
                </a>
            </div>
            
            <!-- Assignments Card -->
            <div class="stat-card bg-gradient-to-br from-orange-500 to-red-600 rounded-3xl shadow-2xl p-6 text-white">
                <div class="flex items-center justify-between mb-4">
                    <div class="bg-white/20 p-3 rounded-xl">
                        <i class="fas fa-tasks text-2xl"></i>
                    </div>
                    <div class="text-right">
                        <p class="text-sm opacity-90 font-semibold">Assignments</p>
                        <p class="text-4xl font-extrabold"><?php echo intval($assignmentsCount); ?></p>
                    </div>
                </div>
                <?php if ($pendingGrading > 0): ?>
                    <div class="mt-2 bg-red-500/30 px-3 py-1 rounded-lg text-xs font-bold">
                        <?php echo $pendingGrading; ?> pending
                    </div>
                <?php endif; ?>
                <a href="assignments.php" class="text-sm opacity-90 hover:opacity-100 flex items-center mt-2">
                    Grade <i class="fas fa-arrow-right ml-2"></i>
                </a>
            </div>
            
            <!-- Students Card -->
            <div class="stat-card bg-gradient-to-br from-cyan-500 to-blue-600 rounded-3xl shadow-2xl p-6 text-white">
                <div class="flex items-center justify-between mb-4">
                    <div class="bg-white/20 p-3 rounded-xl">
                        <i class="fas fa-users text-2xl"></i>
                    </div>
                    <div class="text-right">
                        <p class="text-sm opacity-90 font-semibold">Students</p>
                        <p class="text-4xl font-extrabold"><?php echo intval($studentsCount); ?></p>
                    </div>
                </div>
                <p class="text-xs opacity-75">Enrolled in your courses</p>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8 fade-in">
            <a href="list_courses.php" 
               class="action-card bg-white dark:bg-gray-800 rounded-3xl shadow-xl p-8 text-center border-2 border-blue-200 dark:border-blue-800 hover:border-blue-400 dark:hover:border-blue-600 transition-all group">
                <div class="bg-gradient-to-br from-blue-500 to-blue-600 w-16 h-16 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-lg group-hover:scale-110 transition-transform">
                    <i class="fas fa-book text-white text-2xl"></i>
                </div>
                <h3 class="text-xl font-bold text-gray-800 dark:text-white mb-2">My Courses</h3>
                <p class="text-sm text-gray-600 dark:text-gray-400">Create and manage courses</p>
            </a>
            
            <a href="lessons.php" 
               class="action-card bg-white dark:bg-gray-800 rounded-3xl shadow-xl p-8 text-center border-2 border-green-200 dark:border-green-800 hover:border-green-400 dark:hover:border-green-600 transition-all group">
                <div class="bg-gradient-to-br from-green-500 to-emerald-600 w-16 h-16 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-lg group-hover:scale-110 transition-transform">
                    <i class="fas fa-book-open text-white text-2xl"></i>
                </div>
                <h3 class="text-xl font-bold text-gray-800 dark:text-white mb-2">Lessons</h3>
                <p class="text-sm text-gray-600 dark:text-gray-400">Add lessons and materials</p>
            </a>
            
            <a href="quizzes.php" 
               class="action-card bg-white dark:bg-gray-800 rounded-3xl shadow-xl p-8 text-center border-2 border-purple-200 dark:border-purple-800 hover:border-purple-400 dark:hover:border-purple-600 transition-all group">
                <div class="bg-gradient-to-br from-purple-500 to-pink-600 w-16 h-16 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-lg group-hover:scale-110 transition-transform">
                    <i class="fas fa-question-circle text-white text-2xl"></i>
                </div>
                <h3 class="text-xl font-bold text-gray-800 dark:text-white mb-2">Quizzes</h3>
                <p class="text-sm text-gray-600 dark:text-gray-400">Create quizzes and questions</p>
            </a>
            
            <a href="assignments.php" 
               class="action-card bg-white dark:bg-gray-800 rounded-3xl shadow-xl p-8 text-center border-2 border-orange-200 dark:border-orange-800 hover:border-orange-400 dark:hover:border-orange-600 transition-all group">
                <div class="bg-gradient-to-br from-orange-500 to-red-600 w-16 h-16 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-lg group-hover:scale-110 transition-transform">
                    <i class="fas fa-tasks text-white text-2xl"></i>
                </div>
                <h3 class="text-xl font-bold text-gray-800 dark:text-white mb-2">Assignments</h3>
                <p class="text-sm text-gray-600 dark:text-gray-400">Grade student submissions</p>
                <?php if ($pendingGrading > 0): ?>
                    <span class="mt-2 inline-block bg-red-500 text-white px-3 py-1 rounded-full text-xs font-bold">
                        <?php echo $pendingGrading; ?> pending
                    </span>
                <?php endif; ?>
            </a>
        </div>

        <!-- Recent Courses -->
        <?php if (!empty($recentCourses)): ?>
            <div class="bg-white dark:bg-gray-800 rounded-3xl shadow-2xl p-8 mb-8 fade-in">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-2xl font-extrabold text-gray-800 dark:text-white">
                        <i class="fas fa-clock text-primary-500 dark:text-primary-400 mr-2"></i>Recent Courses
                    </h3>
                    <a href="list_courses.php" class="text-primary-600 dark:text-primary-400 font-semibold hover:underline">
                        View All <i class="fas fa-arrow-right ml-1"></i>
                    </a>
                </div>
                
                <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($recentCourses as $course): 
                        $thumbnailPath = null;
                        if (!empty($course['thumbnail']) && file_exists(__DIR__ . '/../uploads/courses/' . $course['thumbnail'])) {
                            $thumbnailPath = '../uploads/courses/' . $course['thumbnail'];
                        }
                    ?>
                        <div class="bg-gradient-to-br from-gray-50 to-white dark:from-gray-700 dark:to-gray-800 rounded-2xl shadow-lg overflow-hidden border-2 border-gray-200 dark:border-gray-700 hover:border-primary-400 dark:hover:border-primary-600 transition-all cursor-pointer"
                             onclick="window.location.href='create_course.php?edit=<?php echo $course['id']; ?>'">
                            <div class="relative">
                                <?php if ($thumbnailPath): ?>
                                    <img src="<?php echo htmlspecialchars($thumbnailPath); ?>" 
                                         alt="<?php echo htmlspecialchars($course['title']); ?>" 
                                         class="w-full h-40 object-cover">
                                <?php else: ?>
                                    <div class="w-full h-40 bg-gradient-to-br from-primary-500 to-purple-600 flex items-center justify-center">
                                        <span class="text-white text-4xl font-bold"><?php echo strtoupper(substr($course['title'], 0, 1)); ?></span>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="absolute top-3 right-3">
                                    <?php 
                                    $st = $course['status'] ?? 'draft';
                                    $stClass = $st === 'published' ? 'bg-green-500' : ($st === 'draft' ? 'bg-yellow-500' : 'bg-gray-500');
                                    ?>
                                    <span class="<?php echo $stClass; ?> text-white px-3 py-1 rounded-full text-xs font-bold shadow-lg capitalize">
                                        <?php echo htmlspecialchars($st); ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="p-5">
                                <h4 class="text-lg font-bold text-gray-800 dark:text-white mb-2 line-clamp-2">
                                    <?php echo htmlspecialchars($course['title']); ?>
                                </h4>
                                
                                <div class="flex items-center justify-between text-sm text-gray-600 dark:text-gray-400 mb-3">
                                    <span>
                                        <i class="fas fa-users mr-1"></i>
                                        <?php echo intval($course['enrolled_count'] ?? 0); ?> students
                                    </span>
                                    <?php if (!empty($course['level'])): ?>
                                        <span class="capitalize bg-blue-100 dark:bg-blue-900/20 text-blue-700 dark:text-blue-400 px-2 py-1 rounded">
                                            <?php echo htmlspecialchars($course['level']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="pt-3 border-t border-gray-200 dark:border-gray-700">
                                    <a href="create_course.php?edit=<?php echo $course['id']; ?>" 
                                       onclick="event.stopPropagation();"
                                       class="block w-full bg-gradient-to-r from-primary-500 to-purple-600 text-white px-4 py-2 rounded-lg text-center font-semibold hover:from-primary-600 hover:to-purple-700 transition-all">
                                        <i class="fas fa-edit mr-2"></i>Manage Course
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Additional Quick Links -->
        <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-6 fade-in">
            <a href="analytics.php" class="block bg-gradient-to-br from-blue-500 to-cyan-600 rounded-3xl shadow-2xl p-6 text-white hover:opacity-95 transition-opacity">
                <div class="flex items-center space-x-4 mb-4">
                    <div class="bg-white/20 p-4 rounded-xl">
                        <i class="fas fa-chart-line text-2xl"></i>
                    </div>
                    <div>
                        <h4 class="text-xl font-bold">Analytics</h4>
                        <p class="text-sm opacity-90">Track course performance</p>
                    </div>
                </div>
                <span class="text-sm opacity-90 hover:opacity-100 flex items-center">View Reports <i class="fas fa-arrow-right ml-2"></i></span>
            </a>
            
            <a href="messages.php" class="block bg-gradient-to-br from-purple-500 to-pink-600 rounded-3xl shadow-2xl p-6 text-white hover:opacity-95 transition-opacity">
                <div class="flex items-center space-x-4 mb-4">
                    <div class="bg-white/20 p-4 rounded-xl">
                        <i class="fas fa-comments text-2xl"></i>
                    </div>
                    <div>
                        <h4 class="text-xl font-bold">Messages</h4>
                        <p class="text-sm opacity-90">Communicate with students</p>
                    </div>
                </div>
                <span class="text-sm opacity-90 hover:opacity-100 flex items-center">Open Messages <i class="fas fa-arrow-right ml-2"></i></span>
            </a>
            
            <a href="calendar.php" class="block bg-gradient-to-br from-green-500 to-emerald-600 rounded-3xl shadow-2xl p-6 text-white hover:opacity-95 transition-opacity">
                <div class="flex items-center space-x-4 mb-4">
                    <div class="bg-white/20 p-4 rounded-xl">
                        <i class="fas fa-calendar-alt text-2xl"></i>
                    </div>
                    <div>
                        <h4 class="text-xl font-bold">Schedule</h4>
                        <p class="text-sm opacity-90">Manage your calendar</p>
                    </div>
                </div>
                <span class="text-sm opacity-90 hover:opacity-100 flex items-center">View Calendar <i class="fas fa-arrow-right ml-2"></i></span>
            </a>
            
            <a href="profile.php" class="block bg-gradient-to-br from-slate-500 to-slate-700 rounded-3xl shadow-2xl p-6 text-white hover:opacity-95 transition-opacity">
                <div class="flex items-center space-x-4 mb-4">
                    <div class="bg-white/20 p-4 rounded-xl">
                        <i class="fas fa-user text-2xl"></i>
                    </div>
                    <div>
                        <h4 class="text-xl font-bold">Profile</h4>
                        <p class="text-sm opacity-90">Account and settings</p>
                    </div>
                </div>
                <span class="text-sm opacity-90 hover:opacity-100 flex items-center">Edit Profile <i class="fas fa-arrow-right ml-2"></i></span>
            </a>
        </div>
        </div>
    </div>

    <script>
        // Mobile menu toggle
        document.getElementById('mobile-menu-toggle')?.addEventListener('click', function() {
            const mobileSidebar = document.getElementById('mobile-sidebar');
            const sidebarOverlay = document.getElementById('sidebar-overlay');
            if (mobileSidebar && sidebarOverlay) {
                mobileSidebar.classList.remove('-translate-x-full');
                sidebarOverlay.classList.remove('hidden');
            }
        });
        
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
