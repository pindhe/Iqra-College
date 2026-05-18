<?php
/**
 * Shared Teacher Sidebar Component
 * Include this in all teacher pages for consistent navigation
 */

require_once __DIR__ . '/auth.php';

// Get teacher data if not already loaded
if (!isset($teacherId)) {
    $teacherId = getCurrentUserId();
}
if (!isset($name)) {
    $name = getCurrentUserName();
}
if (!isset($pdo)) {
    $pdo = getDBConnection();
}

// Get user avatar if not already loaded
if (!isset($userAvatar)) {
    try {
        $stmt = $pdo->prepare("SELECT avatar FROM users WHERE id = ?");
        $stmt->execute([$teacherId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $userAvatar = $result['avatar'] ?? null;
    } catch (PDOException $e) {
        $userAvatar = null;
    }
}

// Get stats if not already loaded
if (!isset($coursesCount)) {
    try {
        $accessibleCourses = getTeacherAccessibleCourses($teacherId);
        $coursesCount = count($accessibleCourses);
    } catch (Exception $e) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM courses WHERE teacher_id = ?");
        $stmt->execute([$teacherId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $coursesCount = isset($result['count']) ? intval($result['count']) : 0;
    }
}

// Get pending assignments to grade
if (!isset($pendingGrading)) {
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
}

// Ensure counts are integers
if (isset($coursesCount) && is_array($coursesCount)) {
    $coursesCount = 0;
} elseif (isset($coursesCount)) {
    $coursesCount = intval($coursesCount);
} else {
    $coursesCount = 0;
}

$currentPage = $currentPage ?? basename($_SERVER['PHP_SELF']);
?>
<style>
    .sidebar-link {
        transition: all 0.3s ease;
    }
    .sidebar-link:hover {
        background: linear-gradient(90deg, rgba(99, 102, 241, 0.1), rgba(139, 92, 246, 0.1));
        transform: translateX(5px);
    }
    .sidebar-link.active {
        background: linear-gradient(90deg, rgba(99, 102, 241, 0.2), rgba(139, 92, 246, 0.2));
        border-left: 4px solid #6366f1;
        font-weight: bold;
    }
    .gradient-bg {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    }
    .dropdown-menu {
        max-height: 0;
        overflow: hidden;
        transition: max-height 0.3s ease-out;
    }
    .dropdown-menu.open {
        max-height: 500px;
        transition: max-height 0.3s ease-in;
    }
    .dropdown-toggle {
        cursor: pointer;
    }
    .dropdown-toggle i.fa-chevron-down {
        transition: transform 0.3s ease;
    }
    .dropdown-toggle.active i.fa-chevron-down {
        transform: rotate(180deg);
    }
    .dropdown-menu a {
        transition: all 0.2s ease;
    }
</style>

<!-- Mobile Sidebar Overlay -->
<div id="sidebar-overlay" class="lg:hidden fixed inset-0 bg-black bg-opacity-50 z-30 hidden"></div>

<!-- Sidebar -->
<aside id="sidebar" class="hidden lg:flex fixed left-0 top-0 h-full w-64 bg-white dark:bg-gray-800 border-r border-gray-200 dark:border-gray-700 z-40 flex-col transition-all duration-300">
    <!-- Logo & User Info -->
    <div class="p-6 border-b border-gray-200 dark:border-gray-700 bg-gradient-to-br from-white to-gray-50 dark:from-gray-800 dark:to-gray-900">
        <div class="flex items-center space-x-3 mb-4">
            <div class="relative group">
                <?php if (!empty($userAvatar) && file_exists(__DIR__ . '/../uploads/avatars/' . $userAvatar)): ?>
                    <img src="/Iqra-College/uploads/avatars/<?php echo htmlspecialchars($userAvatar); ?>" 
                         alt="Profile" 
                         class="w-14 h-14 rounded-2xl object-cover border-2 border-primary-500 shadow-lg group-hover:scale-110 transition-transform">
                <?php else: ?>
                    <div class="gradient-bg w-14 h-14 rounded-2xl flex items-center justify-center shadow-lg group-hover:scale-110 transition-transform">
                        <span class="text-white text-2xl font-bold">
                            <?php echo strtoupper(substr($name, 0, 1)); ?>
                        </span>
                    </div>
                <?php endif; ?>
                <div class="absolute -bottom-1 -right-1 w-6 h-6 bg-gradient-to-r from-green-400 to-emerald-500 rounded-full border-3 border-white dark:border-gray-800 shadow-md animate-pulse"></div>
            </div>
            <div class="flex-1">
                <h2 class="font-extrabold text-gray-800 dark:text-white text-lg"><?php echo htmlspecialchars(explode(' ', $name)[0]); ?></h2>
                <p class="text-xs text-gray-500 dark:text-gray-400 font-medium uppercase tracking-wide">Teacher</p>
            </div>
        </div>
    </div>
    
    <!-- Navigation -->
    <nav class="flex-1 overflow-y-auto p-4 space-y-1 scrollbar-thin">
        <a href="index.php" 
           class="sidebar-link <?php echo $currentPage === 'index.php' ? 'active' : ''; ?> flex items-center space-x-3 p-3 rounded-lg text-gray-700 dark:text-gray-300">
            <i class="fas fa-home text-primary-600 dark:text-primary-400 w-5"></i>
            <span class="font-medium">Dashboard</span>
        </a>
        
        <!-- My Courses Dropdown -->
        <div class="mb-1">
            <div class="dropdown-toggle sidebar-link <?php echo in_array($currentPage, ['create_course.php', 'list_courses.php', 'courses.php', 'lessons.php', 'quizzes.php']) ? 'active' : ''; ?> flex items-center justify-between p-3 rounded-lg text-gray-700 dark:text-gray-300 cursor-pointer" 
                 onclick="toggleDropdown(this)">
                <div class="flex items-center space-x-3">
                    <i class="fas fa-book text-primary-600 dark:text-primary-400 w-5"></i>
                    <span class="font-medium">My Courses</span>
                </div>
                <i class="fas fa-chevron-down text-xs text-gray-500 dark:text-gray-400 transition-transform"></i>
            </div>
            <div class="dropdown-menu ml-4 mt-1 space-y-1">
                <a href="create_course.php" 
                   class="sidebar-link <?php echo $currentPage === 'create_course.php' ? 'active' : ''; ?> flex items-center space-x-3 p-2 rounded-lg text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700">
                    <i class="fas fa-plus-circle text-primary-500 dark:text-primary-400 w-4"></i>
                    <span class="text-sm font-medium">Create Course</span>
                </a>
                <a href="list_courses.php" 
                   class="sidebar-link <?php echo $currentPage === 'list_courses.php' ? 'active' : ''; ?> flex items-center space-x-3 p-2 rounded-lg text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700">
                    <i class="fas fa-list text-primary-500 dark:text-primary-400 w-4"></i>
                    <span class="text-sm font-medium">List Courses</span>
                </a>
                <a href="quizzes.php" 
                   class="sidebar-link <?php echo $currentPage === 'quizzes.php' ? 'active' : ''; ?> flex items-center space-x-3 p-2 rounded-lg text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700">
                    <i class="fas fa-question-circle text-primary-500 dark:text-primary-400 w-4"></i>
                    <span class="text-sm font-medium">Quizzes</span>
                </a>
            </div>
        </div>
        
        <a href="assignments.php" 
           class="sidebar-link <?php echo $currentPage === 'assignments.php' ? 'active' : ''; ?> flex items-center space-x-3 p-3 rounded-lg text-gray-700 dark:text-gray-300">
            <i class="fas fa-tasks text-primary-600 dark:text-primary-400 w-5"></i>
            <span class="font-medium">Assignments</span>
        </a>
        
        <a href="students.php" 
           class="sidebar-link <?php echo $currentPage === 'students.php' ? 'active' : ''; ?> flex items-center space-x-3 p-3 rounded-lg text-gray-700 dark:text-gray-300">
            <i class="fas fa-users text-primary-600 dark:text-primary-400 w-5"></i>
            <span class="font-medium">Students</span>
        </a>
        
        <a href="analytics.php" 
           class="sidebar-link <?php echo $currentPage === 'analytics.php' ? 'active' : ''; ?> flex items-center space-x-3 p-3 rounded-lg text-gray-700 dark:text-gray-300">
            <i class="fas fa-chart-line text-primary-600 dark:text-primary-400 w-5"></i>
            <span class="font-medium">Analytics</span>
        </a>
        
        <a href="messages.php" 
           class="sidebar-link <?php echo $currentPage === 'messages.php' ? 'active' : ''; ?> flex items-center space-x-3 p-3 rounded-lg text-gray-700 dark:text-gray-300">
            <i class="fas fa-comments text-primary-600 dark:text-primary-400 w-5"></i>
            <span class="font-medium">Messages</span>
        </a>
        
        <div class="mb-1">
            <div class="dropdown-toggle sidebar-link <?php echo in_array($currentPage, ['calendar.php', 'events.php']) ? 'active' : ''; ?> flex items-center justify-between p-3 rounded-lg text-gray-700 dark:text-gray-300 cursor-pointer" 
                 onclick="toggleDropdown(this)">
                <div class="flex items-center space-x-3">
                    <i class="fas fa-calendar-alt text-primary-600 dark:text-primary-400 w-5"></i>
                    <span class="font-medium">Calendar</span>
                </div>
                <i class="fas fa-chevron-down text-xs text-gray-500 dark:text-gray-400 transition-transform"></i>
            </div>
            <div class="dropdown-menu ml-4 mt-1 space-y-1">
                <a href="calendar.php" 
                   class="sidebar-link <?php echo $currentPage === 'calendar.php' ? 'active' : ''; ?> flex items-center space-x-3 p-2 rounded-lg text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700">
                    <i class="fas fa-calendar-days text-primary-500 dark:text-primary-400 w-4"></i>
                    <span class="text-sm font-medium">Calendar</span>
                </a>
                <?php if (hasRole('admin')): ?>
                <a href="/Iqra-College/admin/events.php" 
                   class="sidebar-link flex items-center space-x-3 p-2 rounded-lg text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700">
                    <i class="fas fa-calendar-check text-primary-500 dark:text-primary-400 w-4"></i>
                    <span class="text-sm font-medium">Events (Manage)</span>
                </a>
                <?php else: ?>
                <a href="events.php" 
                   class="sidebar-link <?php echo $currentPage === 'events.php' ? 'active' : ''; ?> flex items-center space-x-3 p-2 rounded-lg text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700">
                    <i class="fas fa-calendar-check text-primary-500 dark:text-primary-400 w-4"></i>
                    <span class="text-sm font-medium">Events</span>
                </a>
                <?php endif; ?>
            </div>
        </div>
    </nav>
    
    <!-- Bottom Actions -->
    <div class="p-4 border-t border-gray-200 dark:border-gray-700 space-y-3">
        <button id="dark-mode-toggle" onclick="if(window.toggleDarkMode) window.toggleDarkMode();" class="w-full flex items-center justify-between px-3 py-3 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors group cursor-pointer">
            <div class="flex items-center space-x-2">
                <i class="fas fa-moon text-yellow-500 dark:hidden w-5"></i>
                <i class="fas fa-sun text-yellow-400 hidden dark:inline w-5"></i>
                <span class="font-medium">Dark Mode</span>
            </div>
            <div class="relative w-12 h-6 bg-gray-300 dark:bg-gray-600 rounded-full transition-colors">
                <div class="absolute top-1 left-1 w-4 h-4 bg-white rounded-full transition-transform dark:translate-x-6"></div>
            </div>
        </button>
        
        <a href="/Iqra-College/auth/logout.php" 
           class="flex items-center space-x-3 p-3 rounded-lg text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors">
            <i class="fas fa-sign-out-alt w-5"></i>
            <span class="font-medium">Logout</span>
        </a>
    </div>
</aside>

<!-- Mobile Sidebar -->
<aside id="mobile-sidebar" class="lg:hidden fixed left-0 top-0 h-full w-64 bg-white dark:bg-gray-800 border-r border-gray-200 dark:border-gray-700 z-40 flex flex-col transition-transform duration-300 -translate-x-full">
    <!-- Logo & User Info -->
    <div class="p-6 border-b border-gray-200 dark:border-gray-700 bg-gradient-to-br from-white to-gray-50 dark:from-gray-800 dark:to-gray-900">
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center space-x-3">
                <?php if (!empty($userAvatar) && file_exists(__DIR__ . '/../uploads/avatars/' . $userAvatar)): ?>
                    <img src="/Iqra-College/uploads/avatars/<?php echo htmlspecialchars($userAvatar); ?>" 
                         alt="Profile" 
                         class="w-12 h-12 rounded-2xl object-cover border-2 border-primary-500 shadow-lg">
                <?php else: ?>
                    <div class="gradient-bg w-12 h-12 rounded-2xl flex items-center justify-center shadow-lg">
                        <span class="text-white text-xl font-bold">
                            <?php echo strtoupper(substr($name, 0, 1)); ?>
                        </span>
                    </div>
                <?php endif; ?>
                <div>
                    <h2 class="font-extrabold text-gray-800 dark:text-white"><?php echo htmlspecialchars(explode(' ', $name)[0]); ?></h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Teacher</p>
                </div>
            </div>
            <button id="close-mobile-sidebar" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
    </div>
    
    <!-- Navigation -->
    <nav class="flex-1 overflow-y-auto p-4 space-y-1">
        <a href="index.php" 
           class="sidebar-link <?php echo $currentPage === 'index.php' ? 'active' : ''; ?> flex items-center space-x-3 p-3 rounded-lg text-gray-700 dark:text-gray-300">
            <i class="fas fa-home text-primary-600 dark:text-primary-400 w-5"></i>
            <span class="font-medium sidebar-text">Dashboard</span>
        </a>

        <div class="mb-1">
            <div class="dropdown-toggle sidebar-link <?php echo in_array($currentPage, ['create_course.php', 'list_courses.php', 'courses.php', 'lessons.php', 'quizzes.php']) ? 'active' : ''; ?> flex items-center justify-between p-3 rounded-lg text-gray-700 dark:text-gray-300 cursor-pointer" 
                 onclick="toggleDropdown(this)">
                <div class="flex items-center space-x-3">
                    <i class="fas fa-book text-primary-600 dark:text-primary-400 w-5"></i>
                    <span class="font-medium sidebar-text">My Courses</span>
                </div>
                <i class="fas fa-chevron-down text-xs text-gray-500 dark:text-gray-400 transition-transform"></i>
            </div>
            <div class="dropdown-menu ml-4 mt-1 space-y-1">
                <a href="create_course.php" 
                   class="sidebar-link <?php echo $currentPage === 'create_course.php' ? 'active' : ''; ?> flex items-center space-x-3 p-2 rounded-lg text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700">
                    <i class="fas fa-plus-circle text-primary-500 dark:text-primary-400 w-4"></i>
                    <span class="text-sm font-medium sidebar-text">Create Course</span>
                </a>
                <a href="list_courses.php" 
                   class="sidebar-link <?php echo $currentPage === 'list_courses.php' ? 'active' : ''; ?> flex items-center space-x-3 p-2 rounded-lg text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700">
                    <i class="fas fa-list text-primary-500 dark:text-primary-400 w-4"></i>
                    <span class="text-sm font-medium sidebar-text">List Courses</span>
                </a>
                <a href="lessons.php" 
                   class="sidebar-link <?php echo $currentPage === 'lessons.php' ? 'active' : ''; ?> flex items-center space-x-3 p-2 rounded-lg text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700">
                    <i class="fas fa-book-open text-primary-500 dark:text-primary-400 w-4"></i>
                    <span class="text-sm font-medium sidebar-text">Lessons</span>
                </a>
                <a href="quizzes.php" 
                   class="sidebar-link <?php echo $currentPage === 'quizzes.php' ? 'active' : ''; ?> flex items-center space-x-3 p-2 rounded-lg text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700">
                    <i class="fas fa-question-circle text-primary-500 dark:text-primary-400 w-4"></i>
                    <span class="text-sm font-medium sidebar-text">Quizzes</span>
                </a>
            </div>
        </div>
        
        <a href="assignments.php" 
           class="sidebar-link <?php echo $currentPage === 'assignments.php' ? 'active' : ''; ?> flex items-center space-x-3 p-3 rounded-lg text-gray-700 dark:text-gray-300">
            <i class="fas fa-tasks text-primary-600 dark:text-primary-400 w-5"></i>
            <span class="font-medium sidebar-text">Assignments</span>
        </a>
        
        <a href="students.php" 
           class="sidebar-link <?php echo $currentPage === 'students.php' ? 'active' : ''; ?> flex items-center space-x-3 p-3 rounded-lg text-gray-700 dark:text-gray-300">
            <i class="fas fa-users text-primary-600 dark:text-primary-400 w-5"></i>
            <span class="font-medium sidebar-text">Students</span>
        </a>
        
        <a href="analytics.php" 
           class="sidebar-link <?php echo $currentPage === 'analytics.php' ? 'active' : ''; ?> flex items-center space-x-3 p-3 rounded-lg text-gray-700 dark:text-gray-300">
            <i class="fas fa-chart-line text-primary-600 dark:text-primary-400 w-5"></i>
            <span class="font-medium sidebar-text">Analytics</span>
        </a>
        
        <a href="messages.php" 
           class="sidebar-link <?php echo $currentPage === 'messages.php' ? 'active' : ''; ?> flex items-center space-x-3 p-3 rounded-lg text-gray-700 dark:text-gray-300">
            <i class="fas fa-comments text-primary-600 dark:text-primary-400 w-5"></i>
            <span class="font-medium sidebar-text">Messages</span>
        </a>
        
        <div class="mb-1">
            <div class="dropdown-toggle sidebar-link <?php echo in_array($currentPage, ['calendar.php', 'events.php']) ? 'active' : ''; ?> flex items-center justify-between p-3 rounded-lg text-gray-700 dark:text-gray-300 cursor-pointer" 
                 onclick="toggleDropdown(this)">
                <div class="flex items-center space-x-3">
                    <i class="fas fa-calendar-alt text-primary-600 dark:text-primary-400 w-5"></i>
                    <span class="font-medium sidebar-text">Calendar</span>
                </div>
                <i class="fas fa-chevron-down text-xs text-gray-500 dark:text-gray-400 transition-transform"></i>
            </div>
            <div class="dropdown-menu ml-4 mt-1 space-y-1">
                <a href="calendar.php" 
                   class="sidebar-link <?php echo $currentPage === 'calendar.php' ? 'active' : ''; ?> flex items-center space-x-3 p-2 rounded-lg text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700">
                    <i class="fas fa-calendar-days text-primary-500 dark:text-primary-400 w-4"></i>
                    <span class="text-sm font-medium sidebar-text">Calendar</span>
                </a>
                <?php if (hasRole('admin')): ?>
                <a href="/Iqra-College/admin/events.php" 
                   class="sidebar-link flex items-center space-x-3 p-2 rounded-lg text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700">
                    <i class="fas fa-calendar-check text-primary-500 dark:text-primary-400 w-4"></i>
                    <span class="text-sm font-medium sidebar-text">Events (Manage)</span>
                </a>
                <?php else: ?>
                <a href="events.php" 
                   class="sidebar-link <?php echo $currentPage === 'events.php' ? 'active' : ''; ?> flex items-center space-x-3 p-2 rounded-lg text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700">
                    <i class="fas fa-calendar-check text-primary-500 dark:text-primary-400 w-4"></i>
                    <span class="text-sm font-medium sidebar-text">Events</span>
                </a>
                <?php endif; ?>
            </div>
        </div>
    </nav>
    
    <!-- Bottom Actions -->
    <div class="p-4 border-t border-gray-200 dark:border-gray-700 space-y-3">
        <button id="dark-mode-toggle-mobile" onclick="if(window.toggleDarkMode) window.toggleDarkMode();" class="w-full flex items-center justify-between px-3 py-3 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors group cursor-pointer">
            <div class="flex items-center space-x-2">
                <i class="fas fa-moon text-yellow-500 dark:hidden w-5"></i>
                <i class="fas fa-sun text-yellow-400 hidden dark:inline w-5"></i>
                <span class="font-medium sidebar-text">Dark Mode</span>
            </div>
            <div class="relative w-12 h-6 bg-gray-300 dark:bg-gray-600 rounded-full transition-colors sidebar-text">
                <div class="absolute top-1 left-1 w-4 h-4 bg-white rounded-full transition-transform dark:translate-x-6"></div>
            </div>
        </button>
        
        <a href="/Iqra-College/auth/logout.php" 
           class="flex items-center space-x-3 p-3 rounded-lg text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors">
            <i class="fas fa-sign-out-alt w-5"></i>
            <span class="font-medium sidebar-text">Logout</span>
        </a>
    </div>
</aside>

<script>
    // Dark mode toggle - Initialize immediately
    (function() {
        const html = document.documentElement;
        
        // Function to get cookie value
        function getCookie(name) {
            const value = `; ${document.cookie}`;
            const parts = value.split(`; ${name}=`);
            if (parts.length === 2) return parts.pop().split(';').shift();
            return null;
        }
        
        // Function to set cookie
        function setCookie(name, value, days) {
            const expires = new Date();
            expires.setTime(expires.getTime() + (days * 24 * 60 * 60 * 1000));
            document.cookie = `${name}=${value}; expires=${expires.toUTCString()}; path=/`;
        }
        
        // Check for saved dark mode preference (default to light mode)
        const darkModeCookie = getCookie('dark_mode');
        if (darkModeCookie === 'enabled') {
            html.classList.add('dark');
        } else {
            // Ensure light mode by default
            html.classList.remove('dark');
            if (!darkModeCookie) {
                setCookie('dark_mode', 'disabled', 365);
            }
        }
        
        // Toggle dark mode: click = switch to dark or light, saved in cookie (button uses onclick)
        window.toggleDarkMode = function() {
            const html = document.documentElement;
            if (html.classList.contains('dark')) {
                html.classList.remove('dark');
                setCookie('dark_mode', 'disabled', 365);
            } else {
                html.classList.add('dark');
                setCookie('dark_mode', 'enabled', 365);
            }
        };
    })();
    
    // Mobile sidebar toggle
    document.addEventListener('DOMContentLoaded', function() {
        const mobileMenuToggle = document.getElementById('mobile-menu-toggle');
        const mobileSidebar = document.getElementById('mobile-sidebar');
        const sidebarOverlay = document.getElementById('sidebar-overlay');
        const closeMobileSidebar = document.getElementById('close-mobile-sidebar');
        
        mobileMenuToggle?.addEventListener('click', function() {
            mobileSidebar?.classList.remove('-translate-x-full');
            sidebarOverlay?.classList.remove('hidden');
        });
        
        closeMobileSidebar?.addEventListener('click', function() {
            mobileSidebar?.classList.add('-translate-x-full');
            sidebarOverlay?.classList.add('hidden');
        });
        
        sidebarOverlay?.addEventListener('click', function() {
            mobileSidebar?.classList.add('-translate-x-full');
            sidebarOverlay?.classList.add('hidden');
        });
        
        // Auto-open dropdown if current page is in the dropdown menu
        const currentPage = '<?php echo $currentPage; ?>';
        const coursesPages = ['create_course.php', 'list_courses.php', 'courses.php', 'lessons.php', 'quizzes.php'];
        const calendarPages = ['calendar.php', 'events.php'];
        
        document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
            const dropdownMenu = toggle.nextElementSibling;
            if (dropdownMenu) {
                const toggleText = toggle.textContent || '';
                const isCoursesDropdown = toggleText.includes('My Courses');
                const isCalendarDropdown = toggleText.includes('Calendar');
                
                if ((isCoursesDropdown && coursesPages.includes(currentPage)) ||
                    (isCalendarDropdown && calendarPages.includes(currentPage))) {
                    dropdownMenu.classList.add('open');
                    toggle.classList.add('active');
                }
            }
        });
    });
    
    // Dropdown toggle function - Make available globally
    window.toggleDropdown = function(element) {
        const dropdown = element.nextElementSibling;
        if (!dropdown) return;
        
        const isOpen = dropdown.classList.contains('open');
        
        // Close all other dropdowns
        document.querySelectorAll('.dropdown-menu').forEach(menu => {
            if (menu !== dropdown) {
                menu.classList.remove('open');
                const prevToggle = menu.previousElementSibling;
                if (prevToggle && prevToggle.classList.contains('dropdown-toggle')) {
                    prevToggle.classList.remove('active');
                }
            }
        });
        
        // Toggle current dropdown
        if (isOpen) {
            dropdown.classList.remove('open');
            element.classList.remove('active');
        } else {
            dropdown.classList.add('open');
            element.classList.add('active');
        }
    };
    
</script>
