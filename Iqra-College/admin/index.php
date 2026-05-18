<?php
/**
 * Admin Dashboard
 * Professional admin interface with stats, quick actions, recent activity, and charts
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

requireRole('admin');

$pdo = getDBConnection();
$name = getCurrentUserName();
$stats = getDashboardStats();

// Enrollments count
try {
    $stmt = $pdo->query("SELECT COUNT(*) as c FROM enrollments");
    $stats['total_enrollments'] = (int) $stmt->fetch()['c'];
} catch (PDOException $e) {
    $stats['total_enrollments'] = 0;
}

// Pending payments count
$pendingPayments = 0;
try {
    $stmt = $pdo->query("SELECT COUNT(*) as c FROM payments WHERE status = 'pending' OR status IS NULL");
    $pendingPayments = (int) $stmt->fetch()['c'];
} catch (PDOException $e) {
    $pendingPayments = 0;
}

// Enrollments by month (last 6 months) for chart
$enrollmentsChartData = [];
try {
    $stmt = $pdo->query("
        SELECT DATE_FORMAT(enrolled_at,'%Y-%m') as mn, COUNT(*) as c
        FROM enrollments
        WHERE enrolled_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY mn
        ORDER BY mn
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $byMonth = [];
    foreach ($rows as $r) {
        $byMonth[$r['mn']] = (int) $r['c'];
    }
    for ($i = 5; $i >= 0; $i--) {
        $m = date('Y-m', strtotime("-$i months"));
        $enrollmentsChartData[] = ['month' => $m, 'count' => $byMonth[$m] ?? 0];
    }
} catch (PDOException $e) {
    for ($i = 5; $i >= 0; $i--) {
        $m = date('Y-m', strtotime("-$i months"));
        $enrollmentsChartData[] = ['month' => $m, 'count' => 0];
    }
}

// Upcoming events (next 5)
$upcomingEvents = [];
try {
    $stmt = $pdo->query("
        SELECT id, title, event_date, location, event_type
        FROM events
        WHERE event_date >= CURDATE()
        ORDER BY event_date ASC
        LIMIT 5
    ");
    $upcomingEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $upcomingEvents = [];
}

// Recent courses (last 5) with teacher name
$recentCourses = [];
try {
    $stmt = $pdo->query("
        SELECT c.id, c.title, c.status, c.created_at, u.name as teacher_name
        FROM courses c
        LEFT JOIN users u ON c.teacher_id = u.id
        ORDER BY c.created_at DESC
        LIMIT 5
    ");
    $recentCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recentCourses = [];
}

// Recent enrollments
$recentEnrollments = [];
try {
    $stmt = $pdo->query("
        SELECT e.id, e.enrolled_at, u.name as student_name, c.title as course_title
        FROM enrollments e
        JOIN users u ON e.student_id = u.id
        JOIN courses c ON e.course_id = c.id
        ORDER BY e.enrolled_at DESC
        LIMIT 8
    ");
    $recentEnrollments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recentEnrollments = [];
}

function timeAgo($date) {
    if (empty($date)) return '—';
    $t = strtotime($date);
    $d = time() - $t;
    if ($d < 60) return 'Just now';
    if ($d < 3600) return floor($d / 60) . ' min ago';
    if ($d < 86400) return floor($d / 3600) . ' h ago';
    if ($d < 604800) return floor($d / 86400) . ' days ago';
    return date('M j, Y', $t);
}

$currentPage = 'index.php';
$pageTitle = 'Dashboard';
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo isset($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === 'enabled' ? 'dark' : ''; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - Admin - IQRA Online College</title>
    <link rel="icon" href="/Iqra-College/assets/images/iqra2.png" type="image/png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        primary: { 50: '#eff6ff', 100: '#dbeafe', 200: '#bfdbfe', 300: '#93c5fd', 400: '#60a5fa', 500: '#3b82f6', 600: '#2563eb', 700: '#1d4ed8', 800: '#1e40af', 900: '#1e3a8a' }
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .fade-in { animation: fadeIn 0.5s ease-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .stat-card { transition: transform 0.2s ease, box-shadow 0.2s ease; }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 12px 24px rgba(0,0,0,0.08); }
        .action-card { transition: all 0.2s ease; }
        .action-card:hover { transform: translateY(-2px); box-shadow: 0 12px 24px rgba(0,0,0,0.1); }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 min-h-screen">
    <?php include __DIR__ . '/../includes/admin_sidebar.php'; ?>

    <div class="lg:ml-64">
        <!-- Header -->
        <header class="bg-white dark:bg-gray-800 shadow border-b border-gray-200 dark:border-gray-700 sticky top-0 z-20">
            <div class="px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <button id="mobile-menu-toggle" class="lg:hidden p-2 rounded-lg text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                            <i class="fas fa-bars text-xl"></i>
                        </button>
                        <div>
                            <h1 class="text-2xl font-bold text-gray-800 dark:text-white"><?php echo $pageTitle; ?></h1>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Admin dashboard</p>
                        </div>
                    </div>
                    <?php include __DIR__ . '/../includes/admin_header.php'; ?>
                </div>
            </div>
        </header>

        <main class="p-4 sm:p-6 lg:p-8">
            <!-- Welcome banner -->
            <div class="mb-6 p-6 rounded-2xl bg-gradient-to-r from-indigo-500 via-purple-600 to-indigo-700 dark:from-indigo-600 dark:via-purple-700 dark:to-indigo-800 text-white shadow-xl fade-in">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <h2 class="text-2xl sm:text-3xl font-bold">Welcome back, <?php echo htmlspecialchars(explode(' ', $name)[0]); ?>!</h2>
                        <p class="text-white/90 mt-1">
                            <?php
                            $hour = (int)date('G');
                            if ($hour < 12) echo 'Good morning';
                            elseif ($hour < 17) echo 'Good afternoon';
                            else echo 'Good evening';
                            ?> — here’s your LMS overview.
                        </p>
                    </div>
                    <div class="flex items-center gap-3 text-white/90">
                        <i class="far fa-calendar-alt text-xl"></i>
                        <span class="font-semibold"><?php echo date('l, F j, Y'); ?></span>
                    </div>
                </div>
            </div>

            <!-- Pending payments alert -->
            <?php if ($pendingPayments > 0): ?>
            <div class="mb-6 p-4 rounded-xl bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 flex items-center justify-between gap-4 flex-wrap">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-amber-500 flex items-center justify-center text-white">
                        <i class="fas fa-money-check-alt"></i>
                    </div>
                    <div>
                        <p class="font-bold text-amber-800 dark:text-amber-200"><?php echo (int)$pendingPayments; ?> payment<?php echo $pendingPayments !== 1 ? 's' : ''; ?> pending verification</p>
                        <p class="text-sm text-amber-700 dark:text-amber-300">Review in Cashier to verify.</p>
                    </div>
                </div>
                <a href="../cashier/payments.php?status=pending" class="flex-shrink-0 inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-white font-semibold transition-colors">
                    <i class="fas fa-arrow-right"></i> View payments
                </a>
            </div>
            <?php endif; ?>

            <!-- Stats -->
            <section class="mb-8 fade-in">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-4 sm:gap-6">
                    <div class="stat-card bg-white dark:bg-gray-800 rounded-2xl shadow border border-gray-100 dark:border-gray-700 p-6 border-l-4 border-indigo-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Courses</p>
                                <p class="text-3xl font-bold text-gray-800 dark:text-white mt-1"><?php echo (int)($stats['total_courses'] ?? 0); ?></p>
                            </div>
                            <div class="w-14 h-14 rounded-2xl bg-indigo-100 dark:bg-indigo-900/40 flex items-center justify-center">
                                <i class="fas fa-book text-indigo-600 dark:text-indigo-400 text-2xl"></i>
                            </div>
                        </div>
                    </div>
                    <div class="stat-card bg-white dark:bg-gray-800 rounded-2xl shadow border border-gray-100 dark:border-gray-700 p-6 border-l-4 border-emerald-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Students</p>
                                <p class="text-3xl font-bold text-gray-800 dark:text-white mt-1"><?php echo (int)($stats['total_students'] ?? 0); ?></p>
                            </div>
                            <div class="w-14 h-14 rounded-2xl bg-emerald-100 dark:bg-emerald-900/40 flex items-center justify-center">
                                <i class="fas fa-user-graduate text-emerald-600 dark:text-emerald-400 text-2xl"></i>
                            </div>
                        </div>
                    </div>
                    <div class="stat-card bg-white dark:bg-gray-800 rounded-2xl shadow border border-gray-100 dark:border-gray-700 p-6 border-l-4 border-violet-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Teachers</p>
                                <p class="text-3xl font-bold text-gray-800 dark:text-white mt-1"><?php echo (int)($stats['total_teachers'] ?? 0); ?></p>
                            </div>
                            <div class="w-14 h-14 rounded-2xl bg-violet-100 dark:bg-violet-900/40 flex items-center justify-center">
                                <i class="fas fa-chalkboard-teacher text-violet-600 dark:text-violet-400 text-2xl"></i>
                            </div>
                        </div>
                    </div>
                    <div class="stat-card bg-white dark:bg-gray-800 rounded-2xl shadow border border-gray-100 dark:border-gray-700 p-6 border-l-4 border-amber-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Quizzes</p>
                                <p class="text-3xl font-bold text-gray-800 dark:text-white mt-1"><?php echo (int)($stats['total_quizzes'] ?? 0); ?></p>
                            </div>
                            <div class="w-14 h-14 rounded-2xl bg-amber-100 dark:bg-amber-900/40 flex items-center justify-center">
                                <i class="fas fa-question-circle text-amber-600 dark:text-amber-400 text-2xl"></i>
                            </div>
                        </div>
                    </div>
                    <div class="stat-card bg-white dark:bg-gray-800 rounded-2xl shadow border border-gray-100 dark:border-gray-700 p-6 border-l-4 border-rose-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Enrollments</p>
                                <p class="text-3xl font-bold text-gray-800 dark:text-white mt-1"><?php echo (int)($stats['total_enrollments'] ?? 0); ?></p>
                            </div>
                            <div class="w-14 h-14 rounded-2xl bg-rose-100 dark:bg-rose-900/40 flex items-center justify-center">
                                <i class="fas fa-user-plus text-rose-600 dark:text-rose-400 text-2xl"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Quick Actions -->
            <section class="mb-8 fade-in">
                <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-4">Quick Actions</h2>
                <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <a href="teachers.php" class="action-card block bg-white dark:bg-gray-800 rounded-2xl shadow border border-gray-100 dark:border-gray-700 p-6 hover:border-indigo-300 dark:hover:border-indigo-600">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-xl bg-indigo-100 dark:bg-indigo-900/40 flex items-center justify-center">
                                <i class="fas fa-chalkboard-teacher text-indigo-600 dark:text-indigo-400 text-xl"></i>
                            </div>
                            <div>
                                <h3 class="font-bold text-gray-800 dark:text-white">Teachers & Cashiers</h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Manage teachers and cashiers</p>
                            </div>
                            <i class="fas fa-chevron-right text-gray-400 ml-auto"></i>
                        </div>
                    </a>
                    <a href="courses.php" class="action-card block bg-white dark:bg-gray-800 rounded-2xl shadow border border-gray-100 dark:border-gray-700 p-6 hover:border-emerald-300 dark:hover:border-emerald-600">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-xl bg-emerald-100 dark:bg-emerald-900/40 flex items-center justify-center">
                                <i class="fas fa-book text-emerald-600 dark:text-emerald-400 text-xl"></i>
                            </div>
                            <div>
                                <h3 class="font-bold text-gray-800 dark:text-white">Courses</h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Manage courses</p>
                            </div>
                            <i class="fas fa-chevron-right text-gray-400 ml-auto"></i>
                        </div>
                    </a>
                    <a href="categories.php" class="action-card block bg-white dark:bg-gray-800 rounded-2xl shadow border border-gray-100 dark:border-gray-700 p-6 hover:border-amber-300 dark:hover:border-amber-600">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-xl bg-amber-100 dark:bg-amber-900/40 flex items-center justify-center">
                                <i class="fas fa-folder text-amber-600 dark:text-amber-400 text-xl"></i>
                            </div>
                            <div>
                                <h3 class="font-bold text-gray-800 dark:text-white">Categories</h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Manage categories</p>
                            </div>
                            <i class="fas fa-chevron-right text-gray-400 ml-auto"></i>
                        </div>
                    </a>
                    <a href="students.php" class="action-card block bg-white dark:bg-gray-800 rounded-2xl shadow border border-gray-100 dark:border-gray-700 p-6 hover:border-violet-300 dark:hover:border-violet-600">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-xl bg-violet-100 dark:bg-violet-900/40 flex items-center justify-center">
                                <i class="fas fa-user-graduate text-violet-600 dark:text-violet-400 text-xl"></i>
                            </div>
                            <div>
                                <h3 class="font-bold text-gray-800 dark:text-white">Students</h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400">View students</p>
                            </div>
                            <i class="fas fa-chevron-right text-gray-400 ml-auto"></i>
                        </div>
                    </a>
                </div>
            </section>

            <!-- Access Control & Settings -->
            <section class="mb-8 fade-in">
                <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-4">Access Control & Settings</h2>
                <div class="grid sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-4">
                    <a href="assign-teachers.php" class="action-card block bg-white dark:bg-gray-800 rounded-2xl shadow border border-gray-100 dark:border-gray-700 p-5 hover:border-indigo-300 dark:hover:border-indigo-600 text-center">
                        <div class="w-12 h-12 rounded-xl bg-indigo-100 dark:bg-indigo-900/40 flex items-center justify-center mx-auto mb-3">
                            <i class="fas fa-link text-indigo-600 dark:text-indigo-400 text-xl"></i>
                        </div>
                        <h3 class="font-bold text-gray-800 dark:text-white text-sm">Assign Teachers</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">To courses & levels</p>
                    </a>
                    <a href="assign-students.php" class="action-card block bg-white dark:bg-gray-800 rounded-2xl shadow border border-gray-100 dark:border-gray-700 p-5 hover:border-teal-300 dark:hover:border-teal-600 text-center">
                        <div class="w-12 h-12 rounded-xl bg-teal-100 dark:bg-teal-900/40 flex items-center justify-center mx-auto mb-3">
                            <i class="fas fa-user-tag text-teal-600 dark:text-teal-400 text-xl"></i>
                        </div>
                        <h3 class="font-bold text-gray-800 dark:text-white text-sm">Assign Students</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Level assignment</p>
                    </a>
                    <a href="manage-users.php" class="action-card block bg-white dark:bg-gray-800 rounded-2xl shadow border border-gray-100 dark:border-gray-700 p-5 hover:border-rose-300 dark:hover:border-rose-600 text-center">
                        <div class="w-12 h-12 rounded-xl bg-rose-100 dark:bg-rose-900/40 flex items-center justify-center mx-auto mb-3">
                            <i class="fas fa-users-cog text-rose-600 dark:text-rose-400 text-xl"></i>
                        </div>
                        <h3 class="font-bold text-gray-800 dark:text-white text-sm">Manage Users</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Enable/disable accounts</p>
                    </a>
                    <a href="events.php" class="action-card block bg-white dark:bg-gray-800 rounded-2xl shadow border border-gray-100 dark:border-gray-700 p-5 hover:border-cyan-300 dark:hover:border-cyan-600 text-center">
                        <div class="w-12 h-12 rounded-xl bg-cyan-100 dark:bg-cyan-900/40 flex items-center justify-center mx-auto mb-3">
                            <i class="fas fa-calendar-check text-cyan-600 dark:text-cyan-400 text-xl"></i>
                        </div>
                        <h3 class="font-bold text-gray-800 dark:text-white text-sm">Events</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Calendar events</p>
                    </a>
                    <a href="voice-call-config.php" class="action-card block bg-white dark:bg-gray-800 rounded-2xl shadow border border-gray-100 dark:border-gray-700 p-5 hover:border-sky-300 dark:hover:border-sky-600 text-center">
                        <div class="w-12 h-12 rounded-xl bg-sky-100 dark:bg-sky-900/40 flex items-center justify-center mx-auto mb-3">
                            <i class="fas fa-phone text-sky-600 dark:text-sky-400 text-xl"></i>
                        </div>
                        <h3 class="font-bold text-gray-800 dark:text-white text-sm">Voice Call</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Configuration</p>
                    </a>
                </div>
            </section>

            <!-- Chart + Upcoming events + Recent courses -->
            <section class="mb-8 fade-in">
                <div class="grid lg:grid-cols-3 gap-6">
                    <!-- Enrollments chart -->
                    <div class="lg:col-span-2 bg-white dark:bg-gray-800 rounded-2xl shadow border border-gray-100 dark:border-gray-700 p-6">
                        <h2 class="text-lg font-bold text-gray-800 dark:text-white mb-4">Enrollments (last 6 months)</h2>
                        <div class="h-64">
                            <canvas id="enrollmentsChart"></canvas>
                        </div>
                    </div>
                    <!-- Upcoming events -->
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow border border-gray-100 dark:border-gray-700 p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h2 class="text-lg font-bold text-gray-800 dark:text-white">Upcoming Events</h2>
                            <a href="events.php" class="text-sm font-semibold text-indigo-600 dark:text-indigo-400 hover:underline">All</a>
                        </div>
                        <?php if (empty($upcomingEvents)): ?>
                            <p class="text-sm text-gray-500 dark:text-gray-400">No upcoming events.</p>
                        <?php else: ?>
                            <ul class="space-y-3">
                                <?php foreach ($upcomingEvents as $ev): ?>
                                <li class="flex items-start gap-3">
                                    <div class="w-10 h-10 rounded-lg bg-indigo-100 dark:bg-indigo-900/40 flex items-center justify-center flex-shrink-0">
                                        <i class="fas fa-calendar-day text-indigo-600 dark:text-indigo-400 text-sm"></i>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="font-medium text-gray-800 dark:text-white truncate"><?php echo htmlspecialchars($ev['title'] ?? '—'); ?></p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo !empty($ev['event_date']) ? date('M j, g:i A', strtotime($ev['event_date'])) : '—'; ?></p>
                                    </div>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <!-- Recent courses -->
            <section class="mb-8 fade-in">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-xl font-bold text-gray-800 dark:text-white">Recent Courses</h2>
                    <a href="courses.php" class="text-sm font-semibold text-indigo-600 dark:text-indigo-400 hover:underline">View all</a>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow border border-gray-100 dark:border-gray-700 overflow-hidden">
                    <?php if (empty($recentCourses)): ?>
                        <div class="p-12 text-center text-gray-500 dark:text-gray-400">
                            <i class="fas fa-book text-4xl mb-3 opacity-50"></i>
                            <p>No courses yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead class="bg-gray-50 dark:bg-gray-700/50">
                                    <tr>
                                        <th class="text-left px-6 py-4 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Course</th>
                                        <th class="text-left px-6 py-4 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Teacher</th>
                                        <th class="text-left px-6 py-4 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                                        <th class="text-left px-6 py-4 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Created</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                    <?php foreach ($recentCourses as $c): ?>
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors">
                                        <td class="px-6 py-4">
                                            <a href="courses.php" class="font-medium text-indigo-600 dark:text-indigo-400 hover:underline"><?php echo htmlspecialchars($c['title'] ?? '—'); ?></a>
                                        </td>
                                        <td class="px-6 py-4 text-gray-600 dark:text-gray-300"><?php echo htmlspecialchars($c['teacher_name'] ?? '—'); ?></td>
                                        <td class="px-6 py-4">
                                            <?php
                                            $st = $c['status'] ?? '';
                                            if ($st === 'published') echo '<span class="px-2 py-1 text-xs font-medium rounded-full bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300">Published</span>';
                                            elseif ($st === 'draft') echo '<span class="px-2 py-1 text-xs font-medium rounded-full bg-yellow-100 dark:bg-yellow-900/40 text-yellow-700 dark:text-yellow-300">Draft</span>';
                                            else echo '<span class="px-2 py-1 text-xs font-medium rounded-full bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400">' . htmlspecialchars($st ?: '—') . '</span>';
                                            ?>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400"><?php echo !empty($c['created_at']) ? date('M j, Y', strtotime($c['created_at'])) : '—'; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Recent Enrollments -->
            <section class="fade-in">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-xl font-bold text-gray-800 dark:text-white">Recent Enrollments</h2>
                    <a href="students.php" class="text-sm font-semibold text-indigo-600 dark:text-indigo-400 hover:underline">View all</a>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow border border-gray-100 dark:border-gray-700 overflow-hidden">
                    <?php if (empty($recentEnrollments)): ?>
                        <div class="p-12 text-center text-gray-500 dark:text-gray-400">
                            <i class="fas fa-inbox text-4xl mb-3 opacity-50"></i>
                            <p>No recent enrollments.</p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead class="bg-gray-50 dark:bg-gray-700/50">
                                    <tr>
                                        <th class="text-left px-6 py-4 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Student</th>
                                        <th class="text-left px-6 py-4 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Course</th>
                                        <th class="text-left px-6 py-4 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">When</th>
                                        <th class="text-left px-6 py-4 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Date</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                    <?php foreach ($recentEnrollments as $row): ?>
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors">
                                        <td class="px-6 py-4">
                                            <span class="font-medium text-gray-800 dark:text-white"><?php echo htmlspecialchars($row['student_name'] ?? '—'); ?></span>
                                        </td>
                                        <td class="px-6 py-4 text-gray-600 dark:text-gray-300"><?php echo htmlspecialchars($row['course_title'] ?? '—'); ?></td>
                                        <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400"><?php echo timeAgo($row['enrolled_at'] ?? null); ?></td>
                                        <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400"><?php echo !empty($row['enrolled_at']) ? date('M j, Y', strtotime($row['enrolled_at'])) : '—'; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var ctx = document.getElementById('enrollmentsChart');
        if (ctx) {
            var data = <?php echo json_encode($enrollmentsChartData); ?>;
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: data.map(function(d) { return d.month; }).map(function(m) {
                        var parts = m.split('-');
                        return new Date(parts[0], parts[1]-1).toLocaleDateString('en-US', { month: 'short', year: '2-digit' });
                    }),
                    datasets: [{
                        label: 'Enrollments',
                        data: data.map(function(d) { return d.count; }),
                        backgroundColor: 'rgba(99, 102, 241, 0.6)',
                        borderColor: 'rgb(99, 102, 241)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true, ticks: { stepSize: 1 } }
                    }
                }
            });
        }
    });
    </script>
</body>
</html>
