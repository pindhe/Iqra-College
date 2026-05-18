<?php
/**
 * Student - Learning Materials (Modern Design)
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

requireRole('student');

$studentId = getCurrentUserId();
$pdo = getDBConnection();
$name = getCurrentUserName();

// Get all materials for enrolled courses
try {
    $stmt = $pdo->prepare("
        SELECT m.*, l.title as lesson_title, l.id as lesson_id,
               c.title as course_name, c.id as course_id
        FROM materials m
        JOIN lessons l ON m.lesson_id = l.id
        JOIN courses c ON l.course_id = c.id
        JOIN enrollments e ON c.id = e.course_id
        WHERE e.student_id = ? AND e.access_granted = 1
        ORDER BY c.title, l.order_number, m.created_at DESC
    ");
    $stmt->execute([$studentId]);
    $materials = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $materials = [];
}

// Group materials by course
$materialsByCourse = [];
foreach ($materials as $material) {
    $courseId = $material['course_id'];
    if (!isset($materialsByCourse[$courseId])) {
        $materialsByCourse[$courseId] = [
            'course_name' => $material['course_name'],
            'materials' => []
        ];
    }
    $materialsByCourse[$courseId]['materials'][] = $material;
}

// Count materials by type
$pdfCount = 0;
$videoCount = 0;
$documentCount = 0;
$otherCount = 0;

foreach ($materials as $material) {
    $fileType = strtolower($material['file_type'] ?? '');
    if (strpos($fileType, 'pdf') !== false) {
        $pdfCount++;
    } elseif (strpos($fileType, 'video') !== false || strpos($fileType, 'mp4') !== false) {
        $videoCount++;
    } elseif (strpos($fileType, 'word') !== false || strpos($fileType, 'doc') !== false || strpos($fileType, 'excel') !== false || strpos($fileType, 'xls') !== false) {
        $documentCount++;
    } else {
        $otherCount++;
    }
}

// Get file type icons
function getFileIcon($fileType) {
    $type = strtolower($fileType ?? '');
    if (strpos($type, 'pdf') !== false) return 'fa-file-pdf';
    if (strpos($type, 'word') !== false || strpos($type, 'doc') !== false) return 'fa-file-word';
    if (strpos($type, 'excel') !== false || strpos($type, 'xls') !== false) return 'fa-file-excel';
    if (strpos($type, 'powerpoint') !== false || strpos($type, 'ppt') !== false) return 'fa-file-powerpoint';
    if (strpos($type, 'image') !== false || strpos($type, 'jpg') !== false || strpos($type, 'png') !== false) return 'fa-file-image';
    if (strpos($type, 'video') !== false || strpos($type, 'mp4') !== false) return 'fa-file-video';
    if (strpos($type, 'audio') !== false || strpos($type, 'mp3') !== false) return 'fa-file-audio';
    return 'fa-file';
}

function getFileColor($fileType) {
    $type = strtolower($fileType ?? '');
    if (strpos($type, 'pdf') !== false) return 'from-red-500 to-red-700';
    if (strpos($type, 'word') !== false || strpos($type, 'doc') !== false) return 'from-blue-500 to-blue-700';
    if (strpos($type, 'excel') !== false || strpos($type, 'xls') !== false) return 'from-green-500 to-green-700';
    if (strpos($type, 'powerpoint') !== false || strpos($type, 'ppt') !== false) return 'from-orange-500 to-orange-700';
    if (strpos($type, 'image') !== false) return 'from-purple-500 to-purple-700';
    if (strpos($type, 'video') !== false || strpos($type, 'mp4') !== false) return 'from-pink-500 to-pink-700';
    if (strpos($type, 'audio') !== false || strpos($type, 'mp3') !== false) return 'from-indigo-500 to-indigo-700';
    return 'from-gray-500 to-gray-700';
}

function formatFileSize($bytes) {
    if (!$bytes) return 'Unknown size';
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}

$pageTitle = 'Learning Materials';
$pageSubtitle = 'Download course materials and resources';
$currentPage = 'materials';
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

            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="card-hover bg-gradient-to-br from-white to-blue-50 dark:from-gray-800 dark:to-gray-900 rounded-2xl p-6 border border-gray-200 dark:border-gray-700 shadow-lg stagger-item relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-blue-200 dark:bg-blue-900/30 rounded-full -mr-16 -mt-16 opacity-20"></div>
                    <div class="relative flex justify-between items-center">
                        <div>
                            <p class="text-gray-600 dark:text-gray-400 text-sm font-semibold uppercase tracking-wide mb-2">Total Materials</p>
                            <p class="text-4xl font-extrabold bg-gradient-to-r from-blue-600 to-blue-800 dark:from-blue-400 dark:to-blue-600 bg-clip-text text-transparent"><?php echo count($materials); ?></p>
                            <p class="text-xs text-gray-600 dark:text-gray-400 mt-2">available files</p>
                        </div>
                        <div class="bg-gradient-to-br from-blue-500 to-blue-700 p-4 rounded-2xl shadow-lg">
                            <i class="fas fa-file-alt text-white text-2xl"></i>
                        </div>
                    </div>
                </div>

                <div class="card-hover bg-gradient-to-br from-white to-red-50 dark:from-gray-800 dark:to-gray-900 rounded-2xl p-6 border border-gray-200 dark:border-gray-700 shadow-lg stagger-item relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-red-200 dark:bg-red-900/30 rounded-full -mr-16 -mt-16 opacity-20"></div>
                    <div class="relative flex justify-between items-center">
                        <div>
                            <p class="text-gray-600 dark:text-gray-400 text-sm font-semibold uppercase tracking-wide mb-2">PDF Files</p>
                            <p class="text-4xl font-extrabold bg-gradient-to-r from-red-600 to-red-800 dark:from-red-400 dark:to-red-600 bg-clip-text text-transparent"><?php echo $pdfCount; ?></p>
                            <p class="text-xs text-gray-600 dark:text-gray-400 mt-2">documents</p>
                        </div>
                        <div class="bg-gradient-to-br from-red-500 to-red-700 p-4 rounded-2xl shadow-lg">
                            <i class="fas fa-file-pdf text-white text-2xl"></i>
                        </div>
                    </div>
                </div>

                <div class="card-hover bg-gradient-to-br from-white to-pink-50 dark:from-gray-800 dark:to-gray-900 rounded-2xl p-6 border border-gray-200 dark:border-gray-700 shadow-lg stagger-item relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-pink-200 dark:bg-pink-900/30 rounded-full -mr-16 -mt-16 opacity-20"></div>
                    <div class="relative flex justify-between items-center">
                        <div>
                            <p class="text-gray-600 dark:text-gray-400 text-sm font-semibold uppercase tracking-wide mb-2">Videos</p>
                            <p class="text-4xl font-extrabold bg-gradient-to-r from-pink-600 to-pink-800 dark:from-pink-400 dark:to-pink-600 bg-clip-text text-transparent"><?php echo $videoCount; ?></p>
                            <p class="text-xs text-gray-600 dark:text-gray-400 mt-2">video files</p>
                        </div>
                        <div class="bg-gradient-to-br from-pink-500 to-pink-700 p-4 rounded-2xl shadow-lg">
                            <i class="fas fa-file-video text-white text-2xl"></i>
                        </div>
                    </div>
                </div>

                <div class="card-hover bg-gradient-to-br from-white to-green-50 dark:from-gray-800 dark:to-gray-900 rounded-2xl p-6 border border-gray-200 dark:border-gray-700 shadow-lg stagger-item relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-green-200 dark:bg-green-900/30 rounded-full -mr-16 -mt-16 opacity-20"></div>
                    <div class="relative flex justify-between items-center">
                        <div>
                            <p class="text-gray-600 dark:text-gray-400 text-sm font-semibold uppercase tracking-wide mb-2">Documents</p>
                            <p class="text-4xl font-extrabold bg-gradient-to-r from-green-600 to-emerald-600 dark:from-green-400 dark:to-emerald-400 bg-clip-text text-transparent"><?php echo $documentCount; ?></p>
                            <p class="text-xs text-gray-600 dark:text-gray-400 mt-2">Word/Excel files</p>
                        </div>
                        <div class="bg-gradient-to-br from-green-500 to-emerald-600 p-4 rounded-2xl shadow-lg">
                            <i class="fas fa-file-word text-white text-2xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Materials by Course -->
            <?php if (!empty($materialsByCourse)): ?>
                <?php foreach ($materialsByCourse as $courseId => $courseData): ?>
                    <div class="card-hover bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-lg fade-in mb-8">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                            <div class="flex items-center justify-between">
                                <h2 class="text-2xl font-bold text-gray-800 dark:text-white flex items-center">
                                    <i class="fas fa-book text-primary-600 dark:text-primary-400 mr-3"></i>
                                    <?php echo htmlspecialchars($courseData['course_name']); ?>
                                </h2>
                                <span class="px-3 py-1 bg-primary-100 dark:bg-primary-900/20 text-primary-800 dark:text-primary-400 rounded-full text-sm font-semibold">
                                    <?php echo count($courseData['materials']); ?> file<?php echo count($courseData['materials']) != 1 ? 's' : ''; ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="p-6 space-y-4">
                            <?php foreach ($courseData['materials'] as $material): ?>
                                <div class="bg-gradient-to-r from-gray-50 to-gray-100 dark:from-gray-700/50 dark:to-gray-800/50 rounded-xl p-6 border border-gray-200 dark:border-gray-700 hover:border-primary-300 dark:hover:border-primary-600 transition-all hover:shadow-lg">
                                    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                                        <div class="flex items-center space-x-4 flex-1">
                                            <div class="bg-gradient-to-br <?php echo getFileColor($material['file_type']); ?> p-4 rounded-xl shadow-lg">
                                                <i class="fas <?php echo getFileIcon($material['file_type']); ?> text-white text-2xl"></i>
                                            </div>
                                            <div class="flex-1">
                                                <h3 class="font-bold text-lg text-gray-800 dark:text-white mb-1">
                                                    <?php echo htmlspecialchars($material['file_name'] ?? 'Untitled File'); ?>
                                                </h3>
                                                <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">
                                                    <i class="fas fa-book-open mr-2 text-primary-600 dark:text-primary-400"></i>
                                                    <?php echo htmlspecialchars($material['lesson_title'] ?? 'Lesson Material'); ?>
                                                </p>
                                                <div class="flex flex-wrap items-center gap-4 text-xs text-gray-500 dark:text-gray-400">
                                                    <?php if ($material['file_type']): ?>
                                                        <span class="flex items-center">
                                                            <i class="fas fa-tag mr-2"></i>
                                                            <?php echo htmlspecialchars($material['file_type']); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ($material['file_size']): ?>
                                                        <span class="flex items-center">
                                                            <i class="fas fa-weight mr-2"></i>
                                                            <?php echo formatFileSize($material['file_size']); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ($material['download_count']): ?>
                                                        <span class="flex items-center">
                                                            <i class="fas fa-download mr-2"></i>
                                                            <?php echo $material['download_count']; ?> downloads
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ($material['created_at']): ?>
                                                        <span class="flex items-center">
                                                            <i class="fas fa-calendar mr-2"></i>
                                                            <?php echo date('M d, Y', strtotime($material['created_at'])); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="flex items-center space-x-3">
                                            <?php if (strpos(strtolower($material['file_type'] ?? ''), 'video') !== false || strpos(strtolower($material['file_type'] ?? ''), 'mp4') !== false): ?>
                                                <a href="/Iqra-College/uploads/materials/<?php echo htmlspecialchars($material['file_path']); ?>" 
                                                   target="_blank"
                                                   class="bg-gradient-to-r from-pink-600 to-pink-700 hover:from-pink-700 hover:to-pink-800 text-white px-4 py-2 rounded-xl font-semibold transition-all transform hover:scale-105 shadow-lg flex items-center space-x-2">
                                                    <i class="fas fa-play"></i>
                                                    <span>Watch</span>
                                                </a>
                                            <?php endif; ?>
                                            <a href="/Iqra-College/uploads/materials/<?php echo htmlspecialchars($material['file_path']); ?>" 
                                               download
                                               class="bg-gradient-to-r from-primary-600 to-primary-700 hover:from-primary-700 hover:to-primary-800 text-white px-6 py-3 rounded-xl font-semibold transition-all transform hover:scale-105 shadow-lg flex items-center space-x-2">
                                                <i class="fas fa-download"></i>
                                                <span>Download</span>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="card-hover bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-lg fade-in p-12 text-center">
                    <div class="inline-block bg-gray-100 dark:bg-gray-700 p-6 rounded-full mb-4">
                        <i class="fas fa-file-alt text-gray-400 dark:text-gray-500 text-5xl"></i>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-800 dark:text-white mb-2">No Materials Available</h3>
                    <p class="text-gray-600 dark:text-gray-400 mb-6">Learning materials will appear here when your teachers upload them.</p>
                    <a href="courses.php" class="inline-flex items-center space-x-2 bg-gradient-to-r from-primary-600 to-primary-700 hover:from-primary-700 hover:to-primary-800 text-white px-6 py-3 rounded-xl font-bold shadow-lg hover:shadow-xl transition-all transform hover:scale-105">
                        <i class="fas fa-book"></i>
                        <span>Browse Courses</span>
                    </a>
                </div>
            <?php endif; ?>
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
                    sidebar.classList.toggle('hidden');
                    sidebarOverlay.classList.toggle('hidden');
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
