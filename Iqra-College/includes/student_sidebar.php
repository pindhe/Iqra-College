<?php
/**
 * Enhanced Student Sidebar Component
 * Modern, responsive sidebar with improved UX
 */

// Get student data if not already loaded
if (!isset($studentId)) {
    $studentId = getCurrentUserId();
}
if (!isset($name)) {
    $name = getCurrentUserName();
}
if (!isset($studentCode)) {
    $studentCode = getUserCode($studentId);
}
if (!isset($pdo)) {
    $pdo = getDBConnection();
}

// Get user avatar if not already loaded
if (!isset($userAvatar)) {
    try {
        $stmt = $pdo->prepare("SELECT avatar FROM users WHERE id = ?");
        $stmt->execute([$studentId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $userAvatar = $result['avatar'] ?? null;
    } catch (PDOException $e) {
        $userAvatar = null;
    }
}

// Get stats if not already loaded
if (!isset($coursesCount)) {
    $enrolledCourses = getEnrolledCourses($studentId);
    $coursesCount = count($enrolledCourses);
}

if (!isset($upcomingAssignments)) {
    try {
        $stmt = $pdo->prepare("
            SELECT a.*, c.title as course_name, c.slug as course_code,
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
}

if (!isset($unreadNotifications)) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$studentId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $unreadNotifications = isset($result['count']) ? intval($result['count']) : 0;
    } catch (PDOException $e) {
        $unreadNotifications = 0;
    }
}

// Ensure $unreadNotifications is always an integer
if (isset($unreadNotifications) && is_array($unreadNotifications)) {
    $unreadNotifications = 0;
} elseif (isset($unreadNotifications)) {
    $unreadNotifications = intval($unreadNotifications);
} else {
    $unreadNotifications = 0;
}

// Get current page for active state
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<style>
    .sidebar-link {
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
    }
    .sidebar-link::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 0;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        transition: width 0.3s ease;
        border-radius: 0 8px 8px 0;
    }
    .sidebar-link:hover::before {
        width: 4px;
    }
    .sidebar-link:hover {
        background: rgba(99, 102, 241, 0.08);
        transform: translateX(4px);
    }
    .sidebar-link.active {
        background: linear-gradient(90deg, rgba(99, 102, 241, 0.15), rgba(99, 102, 241, 0.05));
        border-left: 4px solid #6366f1;
        font-weight: 600;
        color: #6366f1;
    }
    .sidebar-link.active::before {
        width: 4px;
    }
    .dark .sidebar-link.active {
        background: linear-gradient(90deg, rgba(99, 102, 241, 0.25), rgba(99, 102, 241, 0.1));
        color: #818cf8;
    }
    .gradient-bg {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    }
    .dropdown-menu {
        max-height: 0;
        overflow: hidden;
        transition: max-height 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        opacity: 0;
    }
    .dropdown-menu.open {
        max-height: 500px;
        opacity: 1;
        transition: max-height 0.4s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.3s ease 0.1s;
    }
    .dropdown-toggle {
        cursor: pointer;
    }
    .dropdown-toggle i.fa-chevron-down {
        transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .dropdown-toggle.active i.fa-chevron-down {
        transform: rotate(180deg);
    }
    .dropdown-menu a {
        transition: all 0.2s ease;
        position: relative;
    }
    .dropdown-menu a::before {
        content: '';
        position: absolute;
        left: 0;
        top: 50%;
        transform: translateY(-50%);
        width: 0;
        height: 0;
        border-left: 3px solid transparent;
        border-top: 3px solid transparent;
        border-bottom: 3px solid transparent;
        transition: all 0.2s ease;
    }
    .dropdown-menu a:hover::before,
    .dropdown-menu a.active::before {
        width: 6px;
        height: 6px;
        border-left: 3px solid #6366f1;
        border-top: 3px solid transparent;
        border-bottom: 3px solid transparent;
        left: -8px;
    }
    .notification-badge {
        animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
    }
    @keyframes pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.7; }
    }
    .sidebar-scroll {
        scrollbar-width: thin;
        scrollbar-color: rgba(99, 102, 241, 0.3) transparent;
    }
    .sidebar-scroll::-webkit-scrollbar {
        width: 6px;
    }
    .sidebar-scroll::-webkit-scrollbar-track {
        background: transparent;
    }
    .sidebar-scroll::-webkit-scrollbar-thumb {
        background: rgba(99, 102, 241, 0.3);
        border-radius: 3px;
    }
    .sidebar-scroll::-webkit-scrollbar-thumb:hover {
        background: rgba(99, 102, 241, 0.5);
    }
    .profile-card {
        background: linear-gradient(135deg, rgba(99, 102, 241, 0.1) 0%, rgba(139, 92, 246, 0.1) 100%);
        backdrop-filter: blur(10px);
    }
    .dark .profile-card {
        background: linear-gradient(135deg, rgba(99, 102, 241, 0.2) 0%, rgba(139, 92, 246, 0.2) 100%);
    }
    .nav-icon {
        transition: transform 0.2s ease;
    }
    .sidebar-link:hover .nav-icon {
        transform: scale(1.1);
    }
    .sidebar-link.active .nav-icon {
        transform: scale(1.15);
    }
</style>

<!-- Mobile Overlay -->
<div id="sidebar-overlay" class="lg:hidden fixed inset-0 bg-black bg-opacity-50 z-30 hidden transition-opacity duration-300" onclick="closeSidebar()"></div>

<!-- Sidebar -->
<aside id="sidebar" class="fixed left-0 top-0 h-full w-64 bg-white dark:bg-gray-800 border-r border-gray-200 dark:border-gray-700 z-40 flex-col transition-all duration-300 transform -translate-x-full lg:translate-x-0">
    <!-- Logo & User Info -->
    <div class="p-6 border-b border-gray-200 dark:border-gray-700 bg-gradient-to-br from-white via-gray-50 to-white dark:from-gray-800 dark:via-gray-900 dark:to-gray-800">
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center space-x-3 flex-1">
                <div class="relative group">
                    <a href="profile.php" class="block">
                        <?php if (!empty($userAvatar) && file_exists(__DIR__ . '/../uploads/avatars/' . $userAvatar)): ?>
                            <img src="/Iqra-College/uploads/avatars/<?php echo htmlspecialchars($userAvatar); ?>" 
                                 alt="Profile" 
                                 class="w-14 h-14 rounded-xl object-cover border-2 border-primary-500 shadow-lg hover:shadow-xl transition-all cursor-pointer">
                        <?php else: ?>
                            <div class="gradient-bg w-14 h-14 rounded-xl flex items-center justify-center shadow-lg hover:shadow-xl transition-all cursor-pointer group-hover:scale-105">
                                <span class="text-white text-2xl font-bold">
                                    <?php echo strtoupper(substr($name, 0, 1)); ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </a>
                    <div class="absolute -bottom-1 -right-1 w-5 h-5 bg-green-500 rounded-full border-2 border-white dark:border-gray-800 shadow-md">
                        <div class="w-full h-full bg-green-400 rounded-full animate-ping opacity-75"></div>
                    </div>
                </div>
                <div class="flex-1 min-w-0">
                    <a href="profile.php" class="block hover:opacity-80 transition-opacity">
                        <h2 class="font-extrabold text-gray-800 dark:text-white text-lg truncate"><?php echo htmlspecialchars(explode(' ', $name)[0]); ?></h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400 font-medium uppercase tracking-wide">Student</p>
                    </a>
                </div>
            </div>
            <button onclick="closeSidebar()" class="lg:hidden text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <div class="profile-card p-4 rounded-xl border border-primary-200 dark:border-primary-800 shadow-sm">
            <p class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2 flex items-center">
                <i class="fas fa-hashtag text-primary-600 dark:text-primary-400 mr-2"></i>
                <span class="font-mono text-primary-700 dark:text-primary-300"><?php echo htmlspecialchars($studentCode); ?></span>
            </p>
            <p class="text-xs text-gray-600 dark:text-gray-400 flex items-center">
                <i class="fas fa-book-bookmark text-primary-600 dark:text-primary-400 mr-2"></i>
                <span class="font-medium"><?php echo intval($coursesCount); ?> enrolled course<?php echo $coursesCount != 1 ? 's' : ''; ?></span>
            </p>
        </div>
    </div>
    
    <!-- Navigation -->
    <nav class="flex-1 overflow-y-auto p-4 space-y-1 sidebar-scroll">
        <a href="index.php" 
           class="sidebar-link <?php echo $currentPage === 'index.php' ? 'active' : ''; ?> flex items-center space-x-3 p-3 rounded-lg text-gray-700 dark:text-gray-300 group">
            <i class="fas fa-gauge-high nav-icon text-primary-600 dark:text-primary-400 w-5"></i>
            <span class="font-medium">Dashboard</span>
        </a>
        
        <div class="mb-1">
            <div class="dropdown-toggle sidebar-link <?php echo $currentPage === 'courses.php' ? 'active' : ''; ?> flex items-center justify-between p-3 rounded-lg text-gray-700 dark:text-gray-300 cursor-pointer group" 
                 onclick="toggleDropdown(this)">
                <div class="flex items-center space-x-3 flex-1">
                    <i class="fas fa-graduation-cap nav-icon text-primary-600 dark:text-primary-400 w-5"></i>
                    <span class="font-medium">My Courses</span>
                    <?php if (intval($coursesCount) > 0): ?>
                        <span class="ml-auto bg-primary-500 text-white text-xs px-2 py-1 rounded-full font-semibold shadow-sm">
                            <?php echo intval($coursesCount); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <i class="fas fa-chevron-down text-xs text-gray-500 dark:text-gray-400 transition-transform ml-2"></i>
            </div>
            <div class="dropdown-menu ml-4 mt-1 space-y-1">
                <a href="courses.php" 
                   class="sidebar-link <?php echo $currentPage === 'courses.php' ? 'active' : ''; ?> flex items-center space-x-3 p-2 rounded-lg text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700">
                    <i class="fas fa-list-ul text-primary-500 dark:text-primary-400 w-4"></i>
                    <span class="text-sm font-medium">All Courses</span>
                </a>
                <a href="courses.php?filter=enrolled" 
                   class="sidebar-link flex items-center space-x-3 p-2 rounded-lg text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700">
                    <i class="fas fa-book-open text-primary-500 dark:text-primary-400 w-4"></i>
                    <span class="text-sm font-medium">Enrolled Courses</span>
                </a>
            </div>
        </div>
        
        <div class="mb-1">
            <div class="dropdown-toggle sidebar-link <?php echo in_array($currentPage, ['assignments.php', 'quizzes.php', 'results.php']) ? 'active' : ''; ?> flex items-center justify-between p-3 rounded-lg text-gray-700 dark:text-gray-300 cursor-pointer group" 
                 onclick="toggleDropdown(this)">
                <div class="flex items-center space-x-3 flex-1">
                    <i class="fas fa-clipboard-list nav-icon text-primary-600 dark:text-primary-400 w-5"></i>
                    <span class="font-medium">Academic</span>
                    <?php if (!empty($upcomingAssignments)): ?>
                        <span class="ml-auto bg-red-500 text-white text-xs px-2 py-1 rounded-full font-semibold notification-badge shadow-sm">
                            <?php echo count($upcomingAssignments); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <i class="fas fa-chevron-down text-xs text-gray-500 dark:text-gray-400 transition-transform ml-2"></i>
            </div>
            <div class="dropdown-menu ml-4 mt-1 space-y-1">
                <a href="assignments.php" 
                   class="sidebar-link <?php echo $currentPage === 'assignments.php' ? 'active' : ''; ?> flex items-center space-x-3 p-2 rounded-lg text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700">
                    <i class="fas fa-file-pen text-primary-500 dark:text-primary-400 w-4"></i>
                    <span class="text-sm font-medium">Assignments</span>
                    <?php if (!empty($upcomingAssignments)): ?>
                        <span class="ml-auto bg-red-500 text-white text-xs px-2 py-1 rounded-full font-semibold">
                            <?php echo count($upcomingAssignments); ?>
                        </span>
                    <?php endif; ?>
                </a>
                <a href="quizzes.php" 
                   class="sidebar-link <?php echo $currentPage === 'quizzes.php' ? 'active' : ''; ?> flex items-center space-x-3 p-2 rounded-lg text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700">
                    <i class="fas fa-pen-to-square text-primary-500 dark:text-primary-400 w-4"></i>
                    <span class="text-sm font-medium">Quizzes</span>
                </a>
                <a href="results.php" 
                   class="sidebar-link <?php echo $currentPage === 'results.php' ? 'active' : ''; ?> flex items-center space-x-3 p-2 rounded-lg text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700">
                    <i class="fas fa-trophy text-primary-500 dark:text-primary-400 w-4"></i>
                    <span class="text-sm font-medium">Results</span>
                </a>
            </div>
        </div>
        
        <a href="materials.php" 
           class="sidebar-link <?php echo $currentPage === 'materials.php' ? 'active' : ''; ?> flex items-center space-x-3 p-3 rounded-lg text-gray-700 dark:text-gray-300 group">
            <i class="fas fa-folder-open nav-icon text-primary-600 dark:text-primary-400 w-5"></i>
            <span class="font-medium">Materials</span>
        </a>
        
        <div class="mb-1">
            <div class="dropdown-toggle sidebar-link <?php echo in_array($currentPage, ['calendar.php', 'events.php']) ? 'active' : ''; ?> flex items-center justify-between p-3 rounded-lg text-gray-700 dark:text-gray-300 cursor-pointer group" 
                 onclick="toggleDropdown(this)">
                <div class="flex items-center space-x-3 flex-1">
                    <i class="fas fa-calendar-days nav-icon text-primary-600 dark:text-primary-400 w-5"></i>
                    <span class="font-medium">Calendar</span>
                </div>
                <i class="fas fa-chevron-down text-xs text-gray-500 dark:text-gray-400 transition-transform ml-2"></i>
            </div>
            <div class="dropdown-menu ml-4 mt-1 space-y-1">
                <a href="calendar.php" 
                   class="sidebar-link <?php echo $currentPage === 'calendar.php' ? 'active' : ''; ?> flex items-center space-x-3 p-2 rounded-lg text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700">
                    <i class="fas fa-calendar-days text-primary-500 dark:text-primary-400 w-4"></i>
                    <span class="text-sm font-medium">Calendar</span>
                </a>
                <a href="events.php" 
                   class="sidebar-link <?php echo $currentPage === 'events.php' ? 'active' : ''; ?> flex items-center space-x-3 p-2 rounded-lg text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700">
                    <i class="fas fa-calendar-check text-primary-500 dark:text-primary-400 w-4"></i>
                    <span class="text-sm font-medium">Events</span>
                </a>
            </div>
        </div>
        
        <a href="payments.php" 
           class="sidebar-link <?php echo $currentPage === 'payments.php' ? 'active' : ''; ?> flex items-center space-x-3 p-3 rounded-lg text-gray-700 dark:text-gray-300 group">
            <i class="fas fa-wallet nav-icon text-primary-600 dark:text-primary-400 w-5"></i>
            <span class="font-medium">Payments</span>
        </a>
        
        <a href="messages.php" 
           class="sidebar-link <?php echo ($currentPage === 'messages.php' || $currentPage === 'messages') ? 'active' : ''; ?> flex items-center space-x-3 p-3 rounded-lg text-gray-700 dark:text-gray-300 group">
            <i class="fas fa-envelope nav-icon text-primary-600 dark:text-primary-400 w-5"></i>
            <span class="font-medium">Messages</span>
        </a>
        
        <a href="profile.php" 
           class="sidebar-link <?php echo $currentPage === 'profile.php' ? 'active' : ''; ?> flex items-center space-x-3 p-3 rounded-lg text-gray-700 dark:text-gray-300 group">
            <i class="fas fa-user nav-icon text-primary-600 dark:text-primary-400 w-5"></i>
            <span class="font-medium">Profile</span>
        </a>
        
        <a href="settings.php" 
           class="sidebar-link <?php echo $currentPage === 'settings.php' ? 'active' : ''; ?> flex items-center space-x-3 p-3 rounded-lg text-gray-700 dark:text-gray-300 group">
            <i class="fas fa-cog nav-icon text-primary-600 dark:text-primary-400 w-5"></i>
            <span class="font-medium">Settings</span>
        </a>
    </nav>
    
    <!-- Bottom Actions -->
    <div class="p-4 border-t border-gray-200 dark:border-gray-700 space-y-3 bg-gray-50 dark:bg-gray-900/50">
        <div class="flex items-center justify-between px-3">
            <button type="button" id="dark-mode-toggle" onclick="if(window.toggleDarkMode) window.toggleDarkMode();" class="flex items-center space-x-2 text-gray-700 dark:text-gray-300 hover:text-primary-600 dark:hover:text-primary-400 transition-colors cursor-pointer group">
                <i class="fas fa-moon text-yellow-500 dark:hidden w-5 group-hover:rotate-12 transition-transform"></i>
                <i class="fas fa-sun text-yellow-400 hidden dark:inline w-5 group-hover:rotate-12 transition-transform"></i>
                <span class="font-medium">Dark Mode</span>
            </button>
            <a href="notifications.php" class="relative group">
                <i class="fas fa-bell text-gray-500 hover:text-primary-600 dark:hover:text-primary-400 transition-colors text-xl group-hover:animate-pulse"></i>
                <?php if (intval($unreadNotifications) > 0): ?>
                    <span class="absolute -top-1 -right-1 w-5 h-5 bg-red-500 text-white text-xs rounded-full flex items-center justify-center font-bold shadow-lg notification-badge">
                        <?php echo intval($unreadNotifications) > 9 ? '9+' : intval($unreadNotifications); ?>
                    </span>
                <?php endif; ?>
            </a>
        </div>
        
        <a href="/Iqra-College/auth/logout.php" 
           class="flex items-center space-x-3 p-3 rounded-lg text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition-all group">
            <i class="fas fa-right-from-bracket w-5 group-hover:translate-x-1 transition-transform"></i>
            <span class="font-medium">Logout</span>
        </a>
    </div>
</aside>

<script>
    // Dark mode: init from cookie and define toggle
    (function(){
        function getCookie(n){const v='; '+document.cookie;const p=v.split('; '+n+'=');return p.length===2?p.pop().split(';').shift():null;}
        function setCookie(n,v,d){const e=new Date();e.setTime(e.getTime()+(d*864e5));document.cookie=n+'='+v+'; expires='+e.toUTCString()+'; path=/';}
        var h=document.documentElement;
        if(getCookie('dark_mode')==='enabled'){h.classList.add('dark');} else {h.classList.remove('dark');if(!getCookie('dark_mode'))setCookie('dark_mode','disabled',365);}
        window.toggleDarkMode=function(){if(h.classList.contains('dark')){h.classList.remove('dark');setCookie('dark_mode','disabled',365);}else{h.classList.add('dark');setCookie('dark_mode','enabled',365);}};
    })();
    
    // Mobile sidebar functions
    function openSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebar-overlay');
        if (sidebar && overlay) {
            sidebar.classList.remove('-translate-x-full');
            overlay.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }
    }
    
    function closeSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebar-overlay');
        if (sidebar && overlay) {
            sidebar.classList.add('-translate-x-full');
            overlay.classList.add('hidden');
            document.body.style.overflow = '';
        }
    }
    
    // Make functions globally available
    window.openSidebar = openSidebar;
    window.closeSidebar = closeSidebar;
    
    // Auto-open dropdown if current page is in the dropdown menu
    document.addEventListener('DOMContentLoaded', function() {
        const currentPage = '<?php echo $currentPage; ?>';
        const coursesPages = ['courses.php'];
        const academicPages = ['assignments.php', 'quizzes.php', 'results.php'];
        const calendarPages = ['calendar.php', 'events.php'];
        
        document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
            const dropdownMenu = toggle.nextElementSibling;
            if (dropdownMenu) {
                const toggleText = toggle.textContent || '';
                const isCoursesDropdown = toggleText.includes('My Courses');
                const isAcademicDropdown = toggleText.includes('Academic');
                const isCalendarDropdown = toggleText.includes('Calendar');
                
                if ((isCoursesDropdown && coursesPages.includes(currentPage)) ||
                    (isAcademicDropdown && academicPages.includes(currentPage)) ||
                    (isCalendarDropdown && calendarPages.includes(currentPage))) {
                    dropdownMenu.classList.add('open');
                    toggle.classList.add('active');
                }
            }
        });
        
        // Mobile menu toggle button handler
        const mobileMenuToggle = document.getElementById('mobile-menu-toggle');
        if (mobileMenuToggle) {
            mobileMenuToggle.addEventListener('click', function(e) {
                e.stopPropagation();
                openSidebar();
            });
        }
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
    
    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function(e) {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebar-overlay');
        const mobileMenuToggle = document.getElementById('mobile-menu-toggle');
        
        if (sidebar && !sidebar.contains(e.target) && !mobileMenuToggle?.contains(e.target)) {
            if (window.innerWidth < 1024) {
                closeSidebar();
            }
        }
    });
    
    // Close sidebar on escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeSidebar();
        }
    });
</script>
