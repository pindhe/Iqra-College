<?php
/**
 * Teacher - Profile (Modern Design)
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

requireRole('teacher');

$teacherId = getCurrentUserId();
$pdo = getDBConnection();
$name = getCurrentUserName();
$success = '';
$error = '';

// Get user data
$user = getUserById($teacherId);

// Handle profile image upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_avatar'])) {
    if (isset($_FILES['avatar'])) {
        $uploadError = $_FILES['avatar']['error'];
        
        // Check for upload errors
        if ($uploadError !== UPLOAD_ERR_OK) {
            $uploadErrors = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive in php.ini',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive in HTML form',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
            ];
            $error = $uploadErrors[$uploadError] ?? 'Unknown upload error (Error code: ' . $uploadError . ')';
        } else {
            $uploadDir = __DIR__ . '/../uploads/avatars';
            if (!is_dir($uploadDir)) {
                if (!@mkdir($uploadDir, 0755, true)) {
                    $error = 'Failed to create upload directory. Please check permissions.';
                }
            }
            
            // Check if directory is writable
            if (empty($error) && is_dir($uploadDir) && !is_writable($uploadDir)) {
                $error = 'Upload directory is not writable. Please check permissions on uploads/avatars/ directory.';
            }
            
            if (empty($error)) {
                $avatarFile = uploadFile(
                    $_FILES['avatar'],
                    $uploadDir,
                    ['jpg', 'jpeg', 'png', 'gif', 'webp'],
                    5242880 // 5MB max
                );
                
                if ($avatarFile === false) {
                    $error = 'Invalid image type or file too large. Please upload JPG, PNG, GIF, or WEBP (max 5MB)';
                } elseif ($avatarFile === null) {
                    $error = 'Failed to upload file. Please check file permissions and try again.';
                } else {
                    try {
                        // Delete old avatar if exists
                        if (!empty($user['avatar'])) {
                            $oldAvatarPath = __DIR__ . '/../uploads/avatars/' . $user['avatar'];
                            if (file_exists($oldAvatarPath)) {
                                @unlink($oldAvatarPath);
                            }
                        }
                        
                        $stmt = $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?");
                        $stmt->execute([$avatarFile, $teacherId]);
                        $success = 'Profile image updated successfully';
                        $user = getUserById($teacherId);
                    } catch (PDOException $e) {
                        // Delete the uploaded file if database update fails
                        if (file_exists($uploadDir . '/' . $avatarFile)) {
                            @unlink($uploadDir . '/' . $avatarFile);
                        }
                        $error = 'Failed to update profile image: ' . $e->getMessage();
                    }
                }
            }
        }
    } else {
        $error = 'Please select an image to upload';
    }
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $newName = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    
    if (empty($newName)) {
        $error = 'Name is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->execute([$email, $teacherId]);
            if ($stmt->fetch()) {
                $error = 'Email is already taken';
            } else {
                $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ?");
                $stmt->execute([$newName, $email, $phone, $teacherId]);
                $success = 'Profile updated successfully';
                $user = getUserById($teacherId);
                $name = $user['name'];
            }
        } catch (PDOException $e) {
            $error = 'Failed to update profile';
        }
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        $error = 'All password fields are required';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'New passwords do not match';
    } elseif (strlen($newPassword) < 6) {
        $error = 'Password must be at least 6 characters';
    } else {
        if (password_verify($currentPassword, $user['password'])) {
            try {
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([$hashedPassword, $teacherId]);
                $success = 'Password changed successfully';
            } catch (PDOException $e) {
                $error = 'Failed to change password';
            }
        } else {
            $error = 'Current password is incorrect';
        }
    }
}

// Get stats
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM courses WHERE teacher_id = ?");
    $stmt->execute([$teacherId]);
    $coursesCount = $stmt->fetch()['count'] ?? 0;
} catch (PDOException $e) {
    $coursesCount = 0;
}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM lessons l 
                          JOIN courses c ON l.course_id = c.id 
                          WHERE c.teacher_id = ?");
    $stmt->execute([$teacherId]);
    $lessonsCount = $stmt->fetch()['count'] ?? 0;
} catch (PDOException $e) {
    $lessonsCount = 0;
}

try {
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT e.student_id) as count 
                          FROM enrollments e
                          JOIN courses c ON e.course_id = c.id
                          WHERE c.teacher_id = ?");
    $stmt->execute([$teacherId]);
    $studentsCount = $stmt->fetch()['count'] ?? 0;
} catch (PDOException $e) {
    $studentsCount = 0;
}

$pageTitle = 'My Profile';
$currentPage = 'profile';
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
        .fade-in {
            animation: fadeIn 0.6s ease-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .card-hover {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .card-hover:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
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
                            <p class="text-sm text-gray-500 dark:text-gray-400">Manage your account settings and information</p>
                        </div>
                    </div>
                    <div class="flex items-center space-x-3">
                        <?php include __DIR__ . '/../includes/teacher_header.php'; ?>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Content -->
        <div class="p-6 lg:p-8">
            <?php if ($success): ?>
                <div class="mb-6 p-4 bg-gradient-to-r from-green-500 to-emerald-600 text-white rounded-xl shadow-lg fade-in">
                    <div class="flex items-center space-x-3">
                        <i class="fas fa-check-circle text-2xl"></i>
                        <span class="font-semibold"><?php echo htmlspecialchars($success); ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="mb-6 p-4 bg-gradient-to-r from-red-500 to-pink-600 text-white rounded-xl shadow-lg fade-in">
                    <div class="flex items-center space-x-3">
                        <i class="fas fa-exclamation-circle text-2xl"></i>
                        <span class="font-semibold"><?php echo htmlspecialchars($error); ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8 fade-in">
                <div class="card-hover bg-gradient-to-br from-blue-500 to-blue-600 rounded-3xl shadow-2xl p-6 text-white">
                    <div class="flex justify-between items-center">
                        <div>
                            <p class="text-sm opacity-90 font-semibold mb-2">My Courses</p>
                            <p class="text-4xl font-extrabold"><?php echo intval($coursesCount); ?></p>
                        </div>
                        <div class="bg-white/20 p-4 rounded-xl">
                            <i class="fas fa-book text-2xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="card-hover bg-gradient-to-br from-green-500 to-emerald-600 rounded-3xl shadow-2xl p-6 text-white">
                    <div class="flex justify-between items-center">
                        <div>
                            <p class="text-sm opacity-90 font-semibold mb-2">Total Lessons</p>
                            <p class="text-4xl font-extrabold"><?php echo intval($lessonsCount); ?></p>
                        </div>
                        <div class="bg-white/20 p-4 rounded-xl">
                            <i class="fas fa-book-open text-2xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="card-hover bg-gradient-to-br from-purple-500 to-pink-600 rounded-3xl shadow-2xl p-6 text-white">
                    <div class="flex justify-between items-center">
                        <div>
                            <p class="text-sm opacity-90 font-semibold mb-2">Total Students</p>
                            <p class="text-4xl font-extrabold"><?php echo intval($studentsCount); ?></p>
                        </div>
                        <div class="bg-white/20 p-4 rounded-xl">
                            <i class="fas fa-users text-2xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Profile Image Section -->
            <div class="card-hover bg-white dark:bg-gray-800 rounded-3xl p-8 border border-gray-200 dark:border-gray-700 shadow-xl mb-8">
                <div class="flex items-center space-x-3 mb-6">
                    <div class="bg-gradient-to-br from-purple-500 to-pink-600 p-3 rounded-xl">
                        <i class="fas fa-image text-white text-xl"></i>
                    </div>
                    <h2 class="text-2xl font-bold text-gray-800 dark:text-white">Profile Image</h2>
                </div>
                <div class="flex flex-col md:flex-row items-center md:items-start space-y-6 md:space-y-0 md:space-x-8">
                    <div class="flex-shrink-0">
                        <div class="relative">
                            <?php if (!empty($user['avatar']) && file_exists(__DIR__ . '/../uploads/avatars/' . $user['avatar'])): ?>
                                <img src="/Iqra-College/uploads/avatars/<?php echo htmlspecialchars($user['avatar']); ?>" 
                                     alt="Profile Image" 
                                     class="w-32 h-32 rounded-full object-cover border-4 border-primary-500 shadow-xl">
                            <?php else: ?>
                                <div class="w-32 h-32 rounded-full bg-gradient-to-br from-primary-500 via-purple-500 to-pink-500 flex items-center justify-center border-4 border-primary-500 shadow-xl">
                                    <span class="text-white text-4xl font-bold">
                                        <?php echo strtoupper(substr($name, 0, 1)); ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                            <div class="absolute -bottom-2 -right-2 w-8 h-8 bg-green-500 rounded-full border-4 border-white dark:border-gray-800 flex items-center justify-center">
                                <i class="fas fa-check text-white text-xs"></i>
                            </div>
                        </div>
                    </div>
                    <div class="flex-1 w-full">
                        <form method="POST" action="" enctype="multipart/form-data" class="space-y-4">
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                                    <i class="fas fa-upload mr-2"></i>Upload New Image
                                </label>
                                <input type="file" name="avatar" accept="image/jpeg,image/jpg,image/png,image/gif,image/webp" 
                                       class="w-full px-4 py-3 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-primary-500 focus:border-transparent text-gray-800 dark:text-white transition-colors file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-primary-500 file:text-white hover:file:bg-primary-600 cursor-pointer">
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">
                                    <i class="fas fa-info-circle mr-1"></i>JPG, PNG, GIF, or WEBP (max 5MB)
                                </p>
                            </div>
                            <button type="submit" name="upload_avatar" 
                                    class="w-full md:w-auto bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 text-white py-3 px-6 rounded-xl font-bold shadow-lg hover:shadow-xl transition-all transform hover:scale-105">
                                <i class="fas fa-upload mr-2"></i>Upload Image
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Profile Information -->
                <div class="card-hover bg-white dark:bg-gray-800 rounded-3xl p-8 border border-gray-200 dark:border-gray-700 shadow-xl">
                    <div class="flex items-center space-x-3 mb-6">
                        <div class="bg-gradient-to-br from-blue-500 to-blue-600 p-3 rounded-xl">
                            <i class="fas fa-user text-white text-xl"></i>
                        </div>
                        <h2 class="text-2xl font-bold text-gray-800 dark:text-white">Profile Information</h2>
                    </div>
                    <form method="POST" action="" class="space-y-6">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Full Name</label>
                            <input type="text" name="name" value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>" 
                                   class="w-full px-4 py-3 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-primary-500 focus:border-transparent text-gray-800 dark:text-white transition-colors" required>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Email</label>
                            <input type="email" name="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" 
                                   class="w-full px-4 py-3 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-primary-500 focus:border-transparent text-gray-800 dark:text-white transition-colors" required>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Phone</label>
                            <input type="text" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" 
                                   class="w-full px-4 py-3 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-primary-500 focus:border-transparent text-gray-800 dark:text-white transition-colors">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Role</label>
                            <input type="text" value="Teacher" disabled 
                                   class="w-full px-4 py-3 bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-xl text-gray-600 dark:text-gray-400 font-semibold">
                        </div>
                        <button type="submit" name="update_profile" 
                                class="w-full bg-gradient-to-r from-primary-600 to-primary-700 hover:from-primary-700 hover:to-primary-800 text-white py-3 rounded-xl font-bold shadow-lg hover:shadow-xl transition-all transform hover:scale-105">
                            <i class="fas fa-save mr-2"></i>Update Profile
                        </button>
                    </form>
                </div>

                <!-- Change Password -->
                <div class="card-hover bg-white dark:bg-gray-800 rounded-3xl p-8 border border-gray-200 dark:border-gray-700 shadow-xl">
                    <div class="flex items-center space-x-3 mb-6">
                        <div class="bg-gradient-to-br from-green-500 to-emerald-600 p-3 rounded-xl">
                            <i class="fas fa-lock text-white text-xl"></i>
                        </div>
                        <h2 class="text-2xl font-bold text-gray-800 dark:text-white">Change Password</h2>
                    </div>
                    <form method="POST" action="" class="space-y-6">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Current Password</label>
                            <input type="password" name="current_password" 
                                   class="w-full px-4 py-3 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-primary-500 focus:border-transparent text-gray-800 dark:text-white transition-colors" required>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">New Password</label>
                            <input type="password" name="new_password" minlength="6"
                                   class="w-full px-4 py-3 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-primary-500 focus:border-transparent text-gray-800 dark:text-white transition-colors" required>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-2"><i class="fas fa-info-circle mr-1"></i>Must be at least 6 characters</p>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Confirm New Password</label>
                            <input type="password" name="confirm_password" minlength="6"
                                   class="w-full px-4 py-3 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-primary-500 focus:border-transparent text-gray-800 dark:text-white transition-colors" required>
                        </div>
                        <button type="submit" name="change_password" 
                                class="w-full bg-gradient-to-r from-green-600 to-emerald-600 hover:from-green-700 hover:to-emerald-700 text-white py-3 rounded-xl font-bold shadow-lg hover:shadow-xl transition-all transform hover:scale-105">
                            <i class="fas fa-key mr-2"></i>Change Password
                        </button>
                    </form>
                </div>
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
            // The dark mode toggle is handled by the sidebar script
            // This ensures the page responds to dark mode changes
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
