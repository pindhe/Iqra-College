<?php
/**
 * Teacher - Calendar
 * View schedule and calendar events
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

requireRole('teacher');

$teacherId = getCurrentUserId();
$pdo = getDBConnection();
$name = getCurrentUserName();

// Get current month/year
$currentMonth = intval($_GET['month'] ?? date('n'));
$currentYear = intval($_GET['year'] ?? date('Y'));

// Get assignments with due dates
try {
    $stmt = $pdo->prepare("SELECT a.*, c.title as course_title
                          FROM assignments a
                          JOIN courses c ON a.course_id = c.id
                          WHERE c.teacher_id = ? AND a.due_date IS NOT NULL
                          ORDER BY a.due_date ASC");
    $stmt->execute([$teacherId]);
    $assignments = $stmt->fetchAll();
} catch (PDOException $e) {
    $assignments = [];
}

// Get upcoming events (assignments due dates)
$events = [];
foreach ($assignments as $assignment) {
    if ($assignment['due_date']) {
        $date = new DateTime($assignment['due_date']);
        $dateKey = $date->format('Y-m-d');
        if (!isset($events[$dateKey])) {
            $events[$dateKey] = [];
        }
        $events[$dateKey][] = [
            'type' => 'assignment',
            'title' => $assignment['title'],
            'course' => $assignment['course_title'],
            'time' => $date->format('H:i')
        ];
    }
}

// Calendar helper functions
function getDaysInMonth($month, $year) {
    return cal_days_in_month(CAL_GREGORIAN, $month, $year);
}

function getFirstDayOfMonth($month, $year) {
    return date('w', mktime(0, 0, 0, $month, 1, $year));
}

$daysInMonth = getDaysInMonth($currentMonth, $currentYear);
$firstDay = getFirstDayOfMonth($currentMonth, $currentYear);
$monthName = date('F', mktime(0, 0, 0, $currentMonth, 1, $currentYear));

$pageTitle = 'Calendar';
$currentPage = 'calendar';
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
        .calendar-day {
            min-height: 100px;
        }
        .calendar-day.has-events {
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.1), rgba(139, 92, 246, 0.1));
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
                            <p class="text-sm text-gray-500 dark:text-gray-400">View schedule and events</p>
                        </div>
                    </div>
                    <div class="flex items-center space-x-3">
                        <?php include __DIR__ . '/../includes/teacher_header.php'; ?>
                    </div>
                </div>
            </div>
        </nav>

        <div class="p-6 lg:p-8">
            <!-- Calendar Navigation -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 mb-6 fade-in">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center space-x-4">
                        <a href="?month=<?php echo $currentMonth == 1 ? 12 : $currentMonth - 1; ?>&year=<?php echo $currentMonth == 1 ? $currentYear - 1 : $currentYear; ?>" 
                           class="bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 px-4 py-2 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-all">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                        <h2 class="text-2xl font-bold text-gray-800 dark:text-white">
                            <?php echo $monthName . ' ' . $currentYear; ?>
                        </h2>
                        <a href="?month=<?php echo $currentMonth == 12 ? 1 : $currentMonth + 1; ?>&year=<?php echo $currentMonth == 12 ? $currentYear + 1 : $currentYear; ?>" 
                           class="bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 px-4 py-2 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-all">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </div>
                    <a href="?month=<?php echo date('n'); ?>&year=<?php echo date('Y'); ?>" 
                       class="bg-gradient-to-r from-primary-500 to-purple-600 text-white px-6 py-2 rounded-lg font-semibold hover:from-primary-600 hover:to-purple-700 transition-all">
                        Today
                    </a>
                </div>
            </div>

            <!-- Calendar Grid -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 fade-in">
                <div class="grid grid-cols-7 gap-2 mb-2">
                    <?php 
                    $dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                    foreach ($dayNames as $day): 
                    ?>
                        <div class="text-center font-bold text-gray-700 dark:text-gray-300 py-2">
                            <?php echo $day; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="grid grid-cols-7 gap-2">
                    <?php 
                    // Empty cells for days before month starts
                    for ($i = 0; $i < $firstDay; $i++): 
                    ?>
                        <div class="calendar-day border-2 border-transparent rounded-lg"></div>
                    <?php endfor; ?>
                    
                    <?php 
                    // Days of the month
                    for ($day = 1; $day <= $daysInMonth; $day++): 
                        $dateKey = sprintf('%04d-%02d-%02d', $currentYear, $currentMonth, $day);
                        $dayEvents = $events[$dateKey] ?? [];
                        $isToday = ($day == date('j') && $currentMonth == date('n') && $currentYear == date('Y'));
                    ?>
                        <div class="calendar-day border-2 <?php echo $isToday ? 'border-primary-500 dark:border-primary-400' : 'border-gray-200 dark:border-gray-700'; ?> rounded-lg p-2 <?php echo !empty($dayEvents) ? 'has-events' : ''; ?>">
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-sm font-bold <?php echo $isToday ? 'text-primary-600 dark:text-primary-400' : 'text-gray-700 dark:text-gray-300'; ?>">
                                    <?php echo $day; ?>
                                </span>
                                <?php if (!empty($dayEvents)): ?>
                                    <span class="bg-primary-500 text-white text-xs px-2 py-0.5 rounded-full">
                                        <?php echo count($dayEvents); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="space-y-1">
                                <?php foreach (array_slice($dayEvents, 0, 2) as $event): ?>
                                    <div class="text-xs bg-orange-100 dark:bg-orange-900/20 text-orange-700 dark:text-orange-400 px-2 py-1 rounded truncate" 
                                         title="<?php echo htmlspecialchars($event['title'] . ' - ' . $event['course']); ?>">
                                        <i class="fas fa-tasks mr-1"></i><?php echo htmlspecialchars($event['title']); ?>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (count($dayEvents) > 2): ?>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        +<?php echo count($dayEvents) - 2; ?> more
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>

            <!-- Upcoming Events -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 mt-6 fade-in">
                <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-4">
                    <i class="fas fa-calendar-alt mr-2"></i>Upcoming Assignments
                </h2>
                <div class="space-y-3">
                    <?php if (empty($assignments)): ?>
                        <p class="text-gray-600 dark:text-gray-400">No upcoming assignments.</p>
                    <?php else: ?>
                        <?php foreach (array_slice($assignments, 0, 10) as $assignment): 
                            $dueDate = new DateTime($assignment['due_date']);
                            $isOverdue = $dueDate < new DateTime();
                        ?>
                            <div class="border-2 border-gray-200 dark:border-gray-700 rounded-lg p-4 hover:border-primary-400 dark:hover:border-primary-600 transition-all <?php echo $isOverdue ? 'bg-red-50 dark:bg-red-900/20' : ''; ?>">
                                <div class="flex items-start justify-between">
                                    <div class="flex-1">
                                        <h4 class="font-bold text-gray-800 dark:text-white mb-1">
                                            <?php echo htmlspecialchars($assignment['title']); ?>
                                        </h4>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">
                                            <i class="fas fa-book mr-1"></i><?php echo htmlspecialchars($assignment['course_title']); ?>
                                        </p>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-sm font-semibold <?php echo $isOverdue ? 'text-red-600 dark:text-red-400' : 'text-gray-700 dark:text-gray-300'; ?>">
                                            <?php echo $dueDate->format('M d, Y'); ?>
                                        </div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400">
                                            <?php echo $dueDate->format('H:i'); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
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
