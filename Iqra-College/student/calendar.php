<?php
/**
 * Student - Calendar (Modern Design)
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

requireRole('student');

$studentId = getCurrentUserId();
$pdo = getDBConnection();
$name = getCurrentUserName();
$studentCode = getUserCode($studentId);

// Get upcoming assignments
try {
    $stmt = $pdo->prepare("
        SELECT a.*, c.title as course_name, c.id as course_id
        FROM assignments a
        JOIN courses c ON a.course_id = c.id
        JOIN enrollments e ON c.id = e.course_id
        WHERE e.student_id = ? AND a.due_date >= CURDATE()
        ORDER BY a.due_date ASC
        LIMIT 20
    ");
    $stmt->execute([$studentId]);
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $assignments = [];
}

// Get upcoming quizzes
try {
    $stmt = $pdo->prepare("
        SELECT q.*, c.title as course_name, c.id as course_id
        FROM quizzes q
        JOIN courses c ON q.course_id = c.id
        JOIN enrollments e ON c.id = e.course_id
        WHERE e.student_id = ? AND (q.is_published = 1 OR q.is_published IS NULL)
        ORDER BY q.created_at DESC
        LIMIT 20
    ");
    $stmt->execute([$studentId]);
    $quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $quizzes = [];
}

// Combine and sort events
$events = [];
foreach ($assignments as $assignment) {
    if ($assignment['due_date']) {
        $events[] = [
            'type' => 'assignment',
            'title' => $assignment['title'],
            'date' => $assignment['due_date'],
            'course' => $assignment['course_name'],
            'id' => $assignment['id']
        ];
    }
}

// Get current month and year
$currentMonth = isset($_GET['month']) ? intval($_GET['month']) : date('n');
$currentYear = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

// Get first day of month and number of days
$firstDay = mktime(0, 0, 0, $currentMonth, 1, $currentYear);
$daysInMonth = date('t', $firstDay);
$dayOfWeek = date('w', $firstDay); // 0 = Sunday

// Previous and next month
$prevMonth = $currentMonth - 1;
$prevYear = $currentYear;
if ($prevMonth < 1) {
    $prevMonth = 12;
    $prevYear--;
}

$nextMonth = $currentMonth + 1;
$nextYear = $currentYear;
if ($nextMonth > 12) {
    $nextMonth = 1;
    $nextYear++;
}

// Count events for stats
$todayEvents = array_filter($events, function($e) {
    return date('Y-m-d', strtotime($e['date'])) === date('Y-m-d');
});
$thisWeekEvents = array_filter($events, function($e) {
    $eventDate = strtotime($e['date']);
    $weekStart = strtotime('monday this week');
    $weekEnd = strtotime('sunday this week');
    return $eventDate >= $weekStart && $eventDate <= $weekEnd;
});
$thisMonthEvents = array_filter($events, function($e) use ($currentMonth, $currentYear) {
    $eventDate = strtotime($e['date']);
    return date('n', $eventDate) == $currentMonth && date('Y', $eventDate) == $currentYear;
});

$pageTitle = 'Calendar';
$pageSubtitle = 'View your upcoming assignments and events';
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo isset($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === 'enabled' ? 'dark' : ''; ?>">
<head>
    <link rel="icon" href="/Iqra-College/assets/images/iqra2.png" type="image/png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - IQRA Online College</title>
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
        .card-hover {
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        .card-hover:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15);
        }
        .dark .card-hover:hover {
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.4);
        }
        .calendar-day {
            min-height: 120px;
            transition: all 0.3s ease;
        }
        .calendar-day:hover {
            transform: scale(1.05);
            z-index: 10;
        }
        .calendar-day.has-events {
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
        }
        .dark .calendar-day.has-events {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.2) 0%, rgba(59, 130, 246, 0.1) 100%);
        }
        .calendar-day.today {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            border: 3px solid #3b82f6;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
        .dark .calendar-day.today {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.3) 0%, rgba(59, 130, 246, 0.2) 100%);
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
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-200 min-h-screen transition-colors duration-300">
    <div class="flex">
        <?php include __DIR__ . '/../includes/student_sidebar.php'; ?>

        <main class="ml-0 lg:ml-64 flex-1 p-4 lg:p-8 transition-all duration-300">
            <?php include __DIR__ . '/../includes/student_header.php'; ?>

            <!-- Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                <div class="card-hover bg-gradient-to-br from-white to-blue-50 dark:from-gray-800 dark:to-gray-900 rounded-2xl p-6 border border-gray-200 dark:border-gray-700 shadow-lg stagger-item relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-blue-200 dark:bg-blue-900/30 rounded-full -mr-16 -mt-16 opacity-20"></div>
                    <div class="relative flex justify-between items-center">
                        <div>
                            <p class="text-gray-600 dark:text-gray-400 text-sm font-semibold uppercase tracking-wide mb-2">Today</p>
                            <p class="text-4xl font-extrabold bg-gradient-to-r from-blue-600 to-blue-800 dark:from-blue-400 dark:to-blue-600 bg-clip-text text-transparent"><?php echo count($todayEvents); ?></p>
                            <p class="text-xs text-gray-600 dark:text-gray-400 mt-2">events scheduled</p>
                        </div>
                        <div class="bg-gradient-to-br from-blue-500 to-blue-700 p-4 rounded-2xl shadow-lg">
                            <i class="fas fa-calendar-day text-white text-2xl"></i>
                        </div>
                    </div>
                </div>

                <div class="card-hover bg-gradient-to-br from-white to-purple-50 dark:from-gray-800 dark:to-gray-900 rounded-2xl p-6 border border-gray-200 dark:border-gray-700 shadow-lg stagger-item relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-purple-200 dark:bg-purple-900/30 rounded-full -mr-16 -mt-16 opacity-20"></div>
                    <div class="relative flex justify-between items-center">
                        <div>
                            <p class="text-gray-600 dark:text-gray-400 text-sm font-semibold uppercase tracking-wide mb-2">This Week</p>
                            <p class="text-4xl font-extrabold bg-gradient-to-r from-purple-600 to-pink-600 dark:from-purple-400 dark:to-pink-400 bg-clip-text text-transparent"><?php echo count($thisWeekEvents); ?></p>
                            <p class="text-xs text-gray-600 dark:text-gray-400 mt-2">upcoming events</p>
                        </div>
                        <div class="bg-gradient-to-br from-purple-500 to-pink-600 p-4 rounded-2xl shadow-lg">
                            <i class="fas fa-calendar-week text-white text-2xl"></i>
                        </div>
                    </div>
                </div>

                <div class="card-hover bg-gradient-to-br from-white to-green-50 dark:from-gray-800 dark:to-gray-900 rounded-2xl p-6 border border-gray-200 dark:border-gray-700 shadow-lg stagger-item relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-green-200 dark:bg-green-900/30 rounded-full -mr-16 -mt-16 opacity-20"></div>
                    <div class="relative flex justify-between items-center">
                        <div>
                            <p class="text-gray-600 dark:text-gray-400 text-sm font-semibold uppercase tracking-wide mb-2">This Month</p>
                            <p class="text-4xl font-extrabold bg-gradient-to-r from-green-600 to-emerald-600 dark:from-green-400 dark:to-emerald-400 bg-clip-text text-transparent"><?php echo count($thisMonthEvents); ?></p>
                            <p class="text-xs text-gray-600 dark:text-gray-400 mt-2">total events</p>
                        </div>
                        <div class="bg-gradient-to-br from-green-500 to-emerald-600 p-4 rounded-2xl shadow-lg">
                            <i class="fas fa-calendar-alt text-white text-2xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Calendar -->
                <div class="lg:col-span-2 card-hover bg-white dark:bg-gray-800 rounded-2xl p-6 border border-gray-200 dark:border-gray-700 shadow-lg fade-in">
                    <!-- Calendar Header -->
                    <div class="flex items-center justify-between mb-6">
                        <div class="flex items-center space-x-4">
                            <a href="?month=<?php echo $prevMonth; ?>&year=<?php echo $prevYear; ?>" 
                               class="bg-white dark:bg-gray-700 p-3 rounded-xl shadow-md hover:shadow-lg transition-all hover:scale-110 border border-gray-200 dark:border-gray-600">
                                <i class="fas fa-chevron-left text-gray-600 dark:text-gray-400"></i>
                            </a>
                            <h2 class="text-2xl font-extrabold text-gray-800 dark:text-white">
                                <?php echo date('F Y', $firstDay); ?>
                            </h2>
                            <a href="?month=<?php echo $nextMonth; ?>&year=<?php echo $nextYear; ?>" 
                               class="bg-white dark:bg-gray-700 p-3 rounded-xl shadow-md hover:shadow-lg transition-all hover:scale-110 border border-gray-200 dark:border-gray-600">
                                <i class="fas fa-chevron-right text-gray-600 dark:text-gray-400"></i>
                            </a>
                        </div>
                        <a href="?month=<?php echo date('n'); ?>&year=<?php echo date('Y'); ?>" 
                           class="bg-gradient-to-r from-primary-600 to-primary-700 hover:from-primary-700 hover:to-primary-800 text-white px-6 py-3 rounded-xl font-bold shadow-lg hover:shadow-xl transition-all transform hover:scale-105">
                            <i class="fas fa-calendar-day mr-2"></i>Today
                        </a>
                    </div>
                    
                    <!-- Calendar Days Header -->
                    <div class="grid grid-cols-7 gap-2 mb-3">
                        <?php 
                        $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                        foreach ($days as $day): 
                        ?>
                            <div class="text-center font-bold text-gray-600 dark:text-gray-400 py-2 text-sm uppercase tracking-wide">
                                <?php echo $day; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Calendar Grid -->
                    <div class="grid grid-cols-7 gap-2">
                        <?php
                        // Empty cells for days before the first day of month
                        for ($i = 0; $i < $dayOfWeek; $i++):
                        ?>
                            <div class="calendar-day bg-gray-100 dark:bg-gray-700 rounded-xl border border-gray-200 dark:border-gray-600"></div>
                        <?php endfor; ?>
                        
                        <?php
                        // Days of the month
                        $today = date('Y-m-d');
                        for ($day = 1; $day <= $daysInMonth; $day++):
                            $dateStr = sprintf('%04d-%02d-%02d', $currentYear, $currentMonth, $day);
                            $isToday = $dateStr === $today;
                            $dayEvents = array_filter($events, function($e) use ($dateStr) {
                                return date('Y-m-d', strtotime($e['date'])) === $dateStr;
                            });
                            $hasEvents = count($dayEvents) > 0;
                        ?>
                            <div class="calendar-day p-2 rounded-xl border-2 <?php echo $isToday ? 'today border-primary-500' : ($hasEvents ? 'has-events border-primary-200 dark:border-primary-800' : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800'); ?>">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="font-bold text-lg <?php echo $isToday ? 'text-primary-600 dark:text-primary-400' : 'text-gray-800 dark:text-white'; ?>">
                                        <?php echo $day; ?>
                                    </span>
                                    <?php if ($isToday): ?>
                                        <span class="w-2 h-2 bg-primary-500 rounded-full animate-pulse"></span>
                                    <?php endif; ?>
                                </div>
                                <div class="space-y-1">
                                    <?php foreach (array_slice($dayEvents, 0, 2) as $event): ?>
                                        <div class="text-xs p-1.5 rounded-lg truncate <?php echo $event['type'] === 'assignment' ? 'bg-gradient-to-r from-red-500 to-red-600 text-white' : 'bg-gradient-to-r from-blue-500 to-blue-600 text-white'; ?>" 
                                             title="<?php echo htmlspecialchars($event['title']); ?>">
                                            <i class="fas <?php echo $event['type'] === 'assignment' ? 'fa-tasks' : 'fa-question-circle'; ?> mr-1"></i>
                                            <?php echo htmlspecialchars(substr($event['title'], 0, 15)); ?>...
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (count($dayEvents) > 2): ?>
                                        <div class="text-xs text-center text-gray-600 dark:text-gray-400 font-semibold">
                                            +<?php echo count($dayEvents) - 2; ?> more
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <!-- Upcoming Events Sidebar -->
                <div class="space-y-6">
                    <!-- Today's Events -->
                    <?php if (!empty($todayEvents)): ?>
                    <div class="card-hover bg-gradient-to-br from-blue-50 to-primary-50 dark:from-blue-900/20 dark:to-primary-900/20 rounded-2xl p-6 border border-blue-200 dark:border-blue-800 shadow-lg fade-in">
                        <div class="flex items-center space-x-3 mb-4">
                            <div class="bg-gradient-to-br from-blue-500 to-primary-600 p-3 rounded-xl">
                                <i class="fas fa-calendar-day text-white"></i>
                            </div>
                            <h2 class="text-xl font-bold text-gray-800 dark:text-white">Today's Events</h2>
                        </div>
                        <div class="space-y-3">
                            <?php foreach ($todayEvents as $event): ?>
                                <div class="p-4 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm">
                                    <div class="flex items-start space-x-3">
                                        <div class="bg-gradient-to-br <?php echo $event['type'] === 'assignment' ? 'from-red-500 to-red-600' : 'from-blue-500 to-blue-600'; ?> p-2 rounded-lg">
                                            <i class="fas <?php echo $event['type'] === 'assignment' ? 'fa-tasks' : 'fa-question-circle'; ?> text-white text-sm"></i>
                                        </div>
                                        <div class="flex-1">
                                            <h3 class="font-semibold text-gray-800 dark:text-white text-sm"><?php echo htmlspecialchars($event['title']); ?></h3>
                                            <p class="text-xs text-gray-600 dark:text-gray-400 mt-1"><?php echo htmlspecialchars($event['course']); ?></p>
                                            <p class="text-xs text-gray-500 dark:text-gray-500 mt-1">
                                                <i class="far fa-clock mr-1"></i>
                                                <?php echo date('g:i A', strtotime($event['date'])); ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Upcoming Events -->
                    <div class="card-hover bg-white dark:bg-gray-800 rounded-2xl p-6 border border-gray-200 dark:border-gray-700 shadow-lg fade-in">
                        <div class="flex items-center space-x-3 mb-4">
                            <div class="bg-gradient-to-br from-purple-500 to-pink-600 p-3 rounded-xl">
                                <i class="fas fa-list-ul text-white"></i>
                            </div>
                            <h2 class="text-xl font-bold text-gray-800 dark:text-white">Upcoming Events</h2>
                        </div>
                        
                        <?php if (!empty($events)): ?>
                            <?php 
                            usort($events, function($a, $b) {
                                return strtotime($a['date']) - strtotime($b['date']);
                            });
                            $upcomingEvents = array_filter($events, function($e) {
                                return strtotime($e['date']) >= strtotime('today');
                            });
                            ?>
                            <div class="space-y-3 max-h-96 overflow-y-auto scrollbar-thin">
                                <?php foreach (array_slice($upcomingEvents, 0, 10) as $event): 
                                    $eventDate = new DateTime($event['date']);
                                    $now = new DateTime();
                                    $daysDiff = $now->diff($eventDate)->days;
                                    $isUrgent = $daysDiff <= 2;
                                ?>
                                    <div class="p-4 bg-gray-50 dark:bg-gray-700/50 rounded-xl border <?php echo $isUrgent ? 'border-red-300 dark:border-red-700' : 'border-gray-200 dark:border-gray-600'; ?> hover:shadow-md transition-all">
                                        <div class="flex items-start space-x-3">
                                            <div class="bg-gradient-to-br <?php echo $event['type'] === 'assignment' ? 'from-red-500 to-red-600' : 'from-blue-500 to-blue-600'; ?> p-2 rounded-lg">
                                                <i class="fas <?php echo $event['type'] === 'assignment' ? 'fa-tasks' : 'fa-question-circle'; ?> text-white text-sm"></i>
                                            </div>
                                            <div class="flex-1">
                                                <div class="flex items-center space-x-2 mb-1">
                                                    <h3 class="font-semibold text-gray-800 dark:text-white text-sm"><?php echo htmlspecialchars($event['title']); ?></h3>
                                                    <?php if ($isUrgent): ?>
                                                        <span class="px-2 py-0.5 bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-400 text-xs rounded-full font-semibold">
                                                            URGENT
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <p class="text-xs text-gray-600 dark:text-gray-400 mb-2">
                                                    <i class="fas fa-book text-primary-600 dark:text-primary-400 mr-1"></i>
                                                    <?php echo htmlspecialchars($event['course']); ?>
                                                </p>
                                                <div class="flex items-center justify-between">
                                                    <p class="text-xs text-gray-500 dark:text-gray-500">
                                                        <i class="far fa-calendar mr-1"></i>
                                                        <?php echo $eventDate->format('M j, Y'); ?>
                                                    </p>
                                                    <?php if ($daysDiff <= 7): ?>
                                                        <span class="text-xs font-semibold <?php echo $isUrgent ? 'text-red-600 dark:text-red-400' : 'text-orange-600 dark:text-orange-400'; ?>">
                                                            <?php echo $daysDiff; ?> day<?php echo $daysDiff != 1 ? 's' : ''; ?> left
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <?php if ($event['type'] === 'assignment'): ?>
                                            <div class="mt-3 pt-3 border-t border-gray-200 dark:border-gray-600">
                                                <a href="assignment.php?id=<?php echo $event['id']; ?>" 
                                                   class="block text-center bg-gradient-to-r <?php echo $isUrgent ? 'from-red-600 to-red-700 hover:from-red-700 hover:to-red-800' : 'from-primary-600 to-primary-700 hover:from-primary-700 hover:to-primary-800'; ?> text-white px-4 py-2 rounded-lg text-sm font-semibold shadow-md hover:shadow-lg transition-all">
                                                    <i class="fas fa-eye mr-2"></i>View Details
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-8">
                                <div class="inline-block bg-gray-100 dark:bg-gray-700 p-6 rounded-full mb-4">
                                    <i class="fas fa-calendar-times text-gray-400 dark:text-gray-500 text-3xl"></i>
                                </div>
                                <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-2">No Upcoming Events</h3>
                                <p class="text-gray-600 dark:text-gray-400 text-sm">You're all caught up!</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Mobile Sidebar Overlay -->
    <div id="sidebar-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-30 hidden lg:hidden"></div>

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
                
                document.addEventListener('click', function(e) {
                    if (!userMenuButton.contains(e.target) && !userDropdown.contains(e.target)) {
                        userDropdown.classList.add('hidden');
                    }
                });
                
                userDropdown.addEventListener('click', function(e) {
                    e.stopPropagation();
                });
            }
            
        });
    </script>
    
    <?php include __DIR__ . '/../includes/student_ai_button.php'; ?>
</body>
</html>
