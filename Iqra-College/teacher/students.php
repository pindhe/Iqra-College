<?php
/**
 * Teacher - Students
 * View students enrolled in teacher's courses
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

requireRole('teacher');

$teacherId = getCurrentUserId();
$pdo = getDBConnection();
$name = getCurrentUserName();

// Get filter parameters
$courseFilter = intval($_GET['course'] ?? 0);
$search = sanitize($_GET['search'] ?? '');

// Get teacher's courses for filter
try {
    $accessibleCourses = getTeacherAccessibleCourses($teacherId);
    $courses = $accessibleCourses;
} catch (Exception $e) {
    $stmt = $pdo->prepare("SELECT id, title FROM courses WHERE teacher_id = ? ORDER BY title");
    $stmt->execute([$teacherId]);
    $courses = $stmt->fetchAll();
}

// Get students enrolled in teacher's courses
$studentsQuery = "SELECT DISTINCT u.id, u.name, u.email, u.student_id as student_code,
                 COUNT(DISTINCT e.course_id) as enrolled_courses,
                 AVG(e.progress) as avg_progress
                 FROM users u
                 JOIN enrollments e ON u.id = e.student_id
                 JOIN courses c ON e.course_id = c.id
                 WHERE u.role = 'student' AND u.status = 'active' AND c.teacher_id = ?";

$queryParams = [$teacherId];

if ($courseFilter > 0) {
    $studentsQuery .= " AND e.course_id = ?";
    $queryParams[] = $courseFilter;
}

if (!empty($search)) {
    $studentsQuery .= " AND (u.name LIKE ? OR u.email LIKE ? OR u.student_id LIKE ?)";
    $searchTerm = "%$search%";
    $queryParams[] = $searchTerm;
    $queryParams[] = $searchTerm;
    $queryParams[] = $searchTerm;
}

$studentsQuery .= " GROUP BY u.id ORDER BY u.name ASC";

try {
    $stmt = $pdo->prepare($studentsQuery);
    $stmt->execute($queryParams);
    $students = $stmt->fetchAll();
} catch (PDOException $e) {
    $students = [];
}

// Get student course enrollments
$studentCourses = [];
foreach ($students as $student) {
    try {
        $stmt = $pdo->prepare("SELECT c.id, c.title, e.progress, e.enrolled_at, e.assigned_level
                              FROM enrollments e
                              JOIN courses c ON e.course_id = c.id
                              WHERE e.student_id = ? AND c.teacher_id = ?
                              ORDER BY e.enrolled_at DESC");
        $stmt->execute([$student['id'], $teacherId]);
        $studentCourses[$student['id']] = $stmt->fetchAll();
    } catch (PDOException $e) {
        $studentCourses[$student['id']] = [];
    }
}

$pageTitle = 'Students';
$currentPage = 'students';
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
                            <p class="text-sm text-gray-500 dark:text-gray-400">View enrolled students (<?php echo count($students); ?>)</p>
                        </div>
                    </div>
                    <div class="flex items-center space-x-3">
                        <?php include __DIR__ . '/../includes/teacher_header.php'; ?>
                    </div>
                </div>
            </div>
        </nav>

        <div class="p-6 lg:p-8">
            <!-- Filters -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 mb-8 fade-in">
                <form method="GET" class="grid md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                            <i class="fas fa-search mr-1"></i>Search
                        </label>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                               placeholder="Search by name, email, or ID..."
                               class="w-full px-4 py-2 border-2 border-gray-200 dark:border-gray-700 rounded-lg focus:border-primary-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                            <i class="fas fa-book mr-1"></i>Course
                        </label>
                        <select name="course" 
                                class="w-full px-4 py-2 border-2 border-gray-200 dark:border-gray-700 rounded-lg focus:border-primary-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                            <option value="">All Courses</option>
                            <?php foreach ($courses as $course): ?>
                                <option value="<?php echo $course['id']; ?>" <?php echo $courseFilter == $course['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($course['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="flex items-end">
                        <button type="submit" 
                                class="w-full bg-gradient-to-r from-primary-500 to-purple-600 text-white px-6 py-2 rounded-lg font-semibold hover:from-primary-600 hover:to-purple-700 transition-all">
                            <i class="fas fa-filter mr-2"></i>Apply Filters
                        </button>
                    </div>
                </form>
            </div>

            <!-- Students List -->
            <?php if (empty($students)): ?>
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-12 text-center">
                    <i class="fas fa-users text-6xl text-gray-400 mb-4"></i>
                    <p class="text-xl text-gray-600 dark:text-gray-400">No students found.</p>
                </div>
            <?php else: ?>
                <div class="space-y-4 fade-in">
                    <?php foreach ($students as $student): 
                        $courses = $studentCourses[$student['id']] ?? [];
                        $avgProgress = round($student['avg_progress'] ?? 0);
                    ?>
                        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 border-2 border-gray-200 dark:border-gray-700 hover:border-primary-400 dark:hover:border-primary-600 transition-all">
                            <div class="flex items-start justify-between mb-4">
                                <div class="flex items-center space-x-4">
                                    <div class="bg-gradient-to-br from-primary-500 to-purple-600 w-16 h-16 rounded-xl flex items-center justify-center shadow-lg">
                                        <span class="text-white text-2xl font-bold"><?php echo strtoupper(substr($student['name'], 0, 1)); ?></span>
                                    </div>
                                    <div>
                                        <h3 class="text-xl font-bold text-gray-800 dark:text-white">
                                            <?php echo htmlspecialchars($student['name']); ?>
                                        </h3>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">
                                            <i class="fas fa-envelope mr-1"></i><?php echo htmlspecialchars($student['email']); ?>
                                        </p>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">
                                            <i class="fas fa-id-card mr-1"></i><?php echo htmlspecialchars($student['student_code'] ?? 'N/A'); ?>
                                        </p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="bg-primary-100 dark:bg-primary-900/20 text-primary-700 dark:text-primary-400 px-4 py-2 rounded-lg">
                                        <div class="text-2xl font-bold"><?php echo $avgProgress; ?>%</div>
                                        <div class="text-xs">Avg Progress</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <div class="flex justify-between text-sm text-gray-600 dark:text-gray-400 mb-2">
                                    <span>Overall Progress</span>
                                    <span class="font-bold"><?php echo $avgProgress; ?>%</span>
                                </div>
                                <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-3">
                                    <div class="bg-gradient-to-r from-primary-500 to-purple-600 h-3 rounded-full transition-all" 
                                         style="width: <?php echo $avgProgress; ?>%"></div>
                                </div>
                            </div>
                            
                            <div class="pt-4 border-t border-gray-200 dark:border-gray-700">
                                <div class="flex items-center justify-between mb-3">
                                    <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">
                                        <i class="fas fa-book mr-1"></i>Enrolled Courses (<?php echo count($courses); ?>)
                                    </span>
                                </div>
                                <div class="space-y-2">
                                    <?php foreach ($courses as $course): 
                                        $progress = intval($course['progress'] ?? 0);
                                    ?>
                                        <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-3">
                                            <div class="flex items-center justify-between mb-2">
                                                <span class="font-semibold text-gray-800 dark:text-white"><?php echo htmlspecialchars($course['title']); ?></span>
                                                <span class="text-sm text-gray-600 dark:text-gray-400"><?php echo $progress; ?>%</span>
                                            </div>
                                            <div class="w-full bg-gray-200 dark:bg-gray-600 rounded-full h-2">
                                                <div class="bg-green-500 h-2 rounded-full transition-all" 
                                                     style="width: <?php echo $progress; ?>%"></div>
                                            </div>
                                            <?php if ($course['assigned_level']): ?>
                                                <span class="text-xs text-blue-600 dark:text-blue-400 mt-1 inline-block">
                                                    <i class="fas fa-signal mr-1"></i><?php echo ucfirst($course['assigned_level']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
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
