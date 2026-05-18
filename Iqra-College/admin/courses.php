<?php
/**
 * Admin - Manage Courses (Complete Version with All Fields)
 * Admin can create, edit, and delete courses with full field support
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

requireRole('admin');

$pdo = getDBConnection();
$error = '';
$success = '';

// Include auto-thumbnail generation function from teacher courses
require_once __DIR__ . '/../teacher/courses.php';
if (!function_exists('generateAutoThumbnail')) {
    // Fallback if function doesn't exist
    function generateAutoThumbnail($courseTitle, $category = null, $level = 'beginner', $courseId = null) {
        return null; // Disable auto-generation for admin
    }
}

// Get categories for dropdown
try {
    $stmt = $pdo->query("SELECT id, name FROM categories ORDER BY name");
    $categories = $stmt->fetchAll();
} catch (PDOException $e) {
    $categories = [];
}

// Get all teachers for dropdown
try {
    $stmt = $pdo->query("SELECT id, name, email FROM users WHERE role = 'teacher' AND status = 'active' ORDER BY name");
    $teachers = $stmt->fetchAll();
} catch (PDOException $e) {
    $teachers = [];
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        // Basic Information
        $title = sanitize($_POST['title'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $category_id = !empty($_POST['category_id']) ? intval($_POST['category_id']) : null;
        $teacher_id = intval($_POST['teacher_id'] ?? 0);
        $level = sanitize($_POST['level'] ?? 'beginner');
        $language = sanitize($_POST['language'] ?? 'English');
        
        // Generate slug from title
        $slug = null;
        if (!empty($title)) {
            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title)));
            $slug = preg_replace('/-+/', '-', $slug);
            $slug = trim($slug, '-');
            
            // Ensure uniqueness
            try {
                $courseId = ($action === 'edit') ? intval($_POST['course_id'] ?? 0) : 0;
                if ($action === 'edit' && $courseId > 0) {
                    $checkSlug = $slug;
                    $counter = 1;
                    while (true) {
                        $stmt = $pdo->prepare("SELECT id FROM courses WHERE slug = ? AND id != ?");
                        $stmt->execute([$checkSlug, $courseId]);
                        if ($stmt->rowCount() == 0) break;
                        $checkSlug = $slug . '-' . $counter;
                        $counter++;
                        if ($counter > 100) break;
                    }
                    $slug = $checkSlug;
                } else {
                    $checkSlug = $slug;
                    $counter = 1;
                    while (true) {
                        $stmt = $pdo->prepare("SELECT id FROM courses WHERE slug = ?");
                        $stmt->execute([$checkSlug]);
                        if ($stmt->rowCount() == 0) break;
                        $checkSlug = $slug . '-' . $counter;
                        $counter++;
                        if ($counter > 100) break;
                    }
                    $slug = $checkSlug;
                }
            } catch (PDOException $e) {
                error_log("Slug uniqueness check failed: " . $e->getMessage());
            }
        }
        
        // Pricing
        $price = floatval($_POST['price'] ?? 0);
        $discount_price = !empty($_POST['discount_price']) ? floatval($_POST['discount_price']) : null;
        $is_free = isset($_POST['is_free']) ? 1 : 0;
        
        // Course Details
        $duration = intval($_POST['duration'] ?? 0);
        $access_days = intval($_POST['access_days'] ?? 0);
        $max_students = !empty($_POST['max_students']) ? intval($_POST['max_students']) : null;
        $has_certificate = isset($_POST['has_certificate']) ? 1 : 0;
        
        // SEO
        $meta_title = sanitize($_POST['meta_title'] ?? '');
        $meta_description = sanitize($_POST['meta_description'] ?? '');
        
        // Status
        $status = sanitize($_POST['status'] ?? 'draft');
        $courseId = !empty($_POST['course_id']) ? intval($_POST['course_id']) : null;
        
        if (empty($title) || $teacher_id <= 0) {
            $error = 'Please fill in all required fields (Title and Teacher)';
        } else {
            try {
                // Ensure uploads directory exists
                $uploadDir = __DIR__ . '/../uploads/courses';
                if (!is_dir($uploadDir)) {
                    @mkdir($uploadDir, 0755, true);
                }
                
                // Handle thumbnail upload
                $thumbnail = null;
                $autoGenerateThumbnail = true;
                
                if (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] === UPLOAD_ERR_OK) {
                    $thumbnailFile = uploadFile(
                        $_FILES['thumbnail'],
                        $uploadDir,
                        ['jpg', 'jpeg', 'png', 'gif', 'webp'],
                        5242880 // 5MB max
                    );
                    if ($thumbnailFile === false) {
                        $error = 'Invalid thumbnail image type. Please upload JPG, PNG, GIF, or WEBP (max 5MB)';
                    } elseif ($thumbnailFile !== null) {
                        $thumbnail = $thumbnailFile;
                        $autoGenerateThumbnail = false;
                    }
                }
                
                // Handle banner image upload
                $banner_image = null;
                if (isset($_FILES['banner_image']) && $_FILES['banner_image']['error'] === UPLOAD_ERR_OK) {
                    $bannerFile = uploadFile(
                        $_FILES['banner_image'],
                        $uploadDir,
                        ['jpg', 'jpeg', 'png', 'gif', 'webp'],
                        10485760 // 10MB max
                    );
                    if ($bannerFile === false) {
                        $error = 'Invalid banner image type. Please upload JPG, PNG, GIF, or WEBP (max 10MB)';
                    } elseif ($bannerFile !== null) {
                        $banner_image = $bannerFile;
                    }
                }
                
                // Handle preview video upload
                $preview_video = null;
                if (isset($_FILES['preview_video']) && $_FILES['preview_video']['error'] === UPLOAD_ERR_OK) {
                    $videoFile = uploadFile(
                        $_FILES['preview_video'],
                        $uploadDir,
                        ['mp4', 'webm', 'ogg', 'mov'],
                        52428800 // 50MB max
                    );
                    if ($videoFile === false) {
                        $error = 'Invalid preview video type. Please upload MP4, WEBM, OGG, or MOV (max 50MB)';
                    } elseif ($videoFile !== null) {
                        $preview_video = $videoFile;
                    }
                }
                
                // Auto-generate thumbnail if no image uploaded
                if ($autoGenerateThumbnail && empty($error) && $action === 'add') {
                    $categoryName = null;
                    if ($category_id) {
                        try {
                            $stmt = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
                            $stmt->execute([$category_id]);
                            $cat = $stmt->fetch();
                            $categoryName = $cat['name'] ?? null;
                        } catch (PDOException $e) {
                            // Continue
                        }
                    }
                    $generatedThumbnail = generateAutoThumbnail($title, $categoryName, $level, $courseId);
                    if ($generatedThumbnail) {
                        $thumbnail = $generatedThumbnail;
                    }
                }
                
                if (empty($error)) {
                    if ($action === 'edit' && $courseId) {
                        // Update existing course
                        // Preserve old media files if not replaced
                        try {
                            $stmt = $pdo->prepare("SELECT thumbnail, banner_image, preview_video FROM courses WHERE id = ?");
                            $stmt->execute([$courseId]);
                            $oldCourse = $stmt->fetch();
                            
                            if ($oldCourse) {
                                if (empty($thumbnail) && !empty($oldCourse['thumbnail'])) {
                                    $thumbnail = $oldCourse['thumbnail'];
                                }
                                if (empty($banner_image) && !empty($oldCourse['banner_image'])) {
                                    $banner_image = $oldCourse['banner_image'];
                                }
                                if (empty($preview_video) && !empty($oldCourse['preview_video'])) {
                                    $preview_video = $oldCourse['preview_video'];
                                }
                            }
                        } catch (PDOException $e) {
                            // Continue
                        }
                        
                        // Try multiple UPDATE attempts
                        $updated = false;
                        $updateAttempts = [
                            [
                                'sql' => "UPDATE courses SET title = ?, slug = ?, description = ?, category_id = ?, teacher_id = ?, level = ?, price = ?, discount_price = ?, is_free = ?, duration = ?, access_days = ?, max_students = ?, has_certificate = ?, language = ?, meta_title = ?, meta_description = ?, status = ?, thumbnail = ?, banner_image = ?, preview_video = ? WHERE id = ?",
                                'params' => [$title, $slug, $description, $category_id, $teacher_id, $level, $price, $discount_price, $is_free, $duration, $access_days, $max_students, $has_certificate, $language, $meta_title, $meta_description, $status, $thumbnail, $banner_image, $preview_video, $courseId]
                            ],
                            [
                                'sql' => "UPDATE courses SET title = ?, slug = ?, description = ?, category_id = ?, teacher_id = ?, level = ?, price = ?, duration = ?, status = ?, thumbnail = ? WHERE id = ?",
                                'params' => [$title, $slug, $description, $category_id, $teacher_id, $level, $price, $duration, $status, $thumbnail, $courseId]
                            ],
                            [
                                'sql' => "UPDATE courses SET title = ?, description = ?, teacher_id = ?, thumbnail = ? WHERE id = ?",
                                'params' => [$title, $description, $teacher_id, $thumbnail, $courseId]
                            ]
                        ];
                        
                        foreach ($updateAttempts as $attempt) {
                            try {
                                $stmt = $pdo->prepare($attempt['sql']);
                                $stmt->execute($attempt['params']);
                                $updated = true;
                                $success = 'Course updated successfully!';
                                break;
                            } catch (PDOException $e) {
                                continue;
                            }
                        }
                        
                        if (!$updated) {
                            throw new Exception('Failed to update course');
                        }
                    } else {
                        // Insert new course
                        $inserted = false;
                        $insertAttempts = [
                            [
                                'sql' => "INSERT INTO courses (title, slug, description, category_id, teacher_id, level, price, discount_price, is_free, duration, access_days, max_students, has_certificate, language, meta_title, meta_description, status, thumbnail, banner_image, preview_video) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                                'params' => [$title, $slug, $description, $category_id, $teacher_id, $level, $price, $discount_price, $is_free, $duration, $access_days, $max_students, $has_certificate, $language, $meta_title, $meta_description, $status, $thumbnail, $banner_image, $preview_video]
                            ],
                            [
                                'sql' => "INSERT INTO courses (title, slug, description, category_id, teacher_id, level, price, duration, status, thumbnail) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                                'params' => [$title, $slug, $description, $category_id, $teacher_id, $level, $price, $duration, $status, $thumbnail]
                            ],
                            [
                                'sql' => "INSERT INTO courses (title, slug, teacher_id) VALUES (?, ?, ?)",
                                'params' => [$title, $slug, $teacher_id]
                            ],
                            [
                                'sql' => "INSERT INTO courses (title, teacher_id) VALUES (?, ?)",
                                'params' => [$title, $teacher_id]
                            ]
                        ];
                        
                        foreach ($insertAttempts as $attempt) {
                            try {
                                $stmt = $pdo->prepare($attempt['sql']);
                                $stmt->execute($attempt['params']);
                                $newCourseId = $pdo->lastInsertId();
                                $inserted = true;
                                $success = 'Course added successfully!';
                                
                                // Update all fields after insert if needed
                                if ($newCourseId) {
                                    try {
                                        $stmt = $pdo->query("SHOW COLUMNS FROM courses");
                                        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
                                        
                                        $fieldsToUpdate = [];
                                        $updateParams = [];
                                        
                                        $optionalFields = [
                                            'slug' => $slug,
                                            'description' => $description,
                                            'category_id' => $category_id,
                                            'level' => $level,
                                            'price' => $price,
                                            'discount_price' => $discount_price,
                                            'is_free' => $is_free,
                                            'duration' => $duration,
                                            'access_days' => $access_days,
                                            'max_students' => $max_students,
                                            'has_certificate' => $has_certificate,
                                            'language' => $language,
                                            'meta_title' => $meta_title,
                                            'meta_description' => $meta_description,
                                            'status' => $status,
                                            'thumbnail' => $thumbnail,
                                            'banner_image' => $banner_image,
                                            'preview_video' => $preview_video
                                        ];
                                        
                                        foreach ($optionalFields as $field => $value) {
                                            if (in_array($field, $columns)) {
                                                $fieldsToUpdate[] = "$field = ?";
                                                $updateParams[] = $value;
                                            }
                                        }
                                        
                                        if (!empty($fieldsToUpdate)) {
                                            $updateParams[] = $newCourseId;
                                            $updateSql = "UPDATE courses SET " . implode(', ', $fieldsToUpdate) . " WHERE id = ?";
                                            $stmt = $pdo->prepare($updateSql);
                                            $stmt->execute($updateParams);
                                        }
                                    } catch (PDOException $e) {
                                        // Continue - course was created
                                    }
                                }
                                break;
                            } catch (PDOException $e) {
                                continue;
                            }
                        }
                        
                        if (!$inserted) {
                            throw new Exception('Failed to insert course');
                        }
                    }
                }
            } catch (Exception $e) {
                $error = 'Failed to save course: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                // Get course to delete all files
                $stmt = $pdo->prepare("SELECT thumbnail, banner_image, preview_video FROM courses WHERE id = ?");
                $stmt->execute([$id]);
                $course = $stmt->fetch();
                
                if ($course) {
                    $uploadDir = __DIR__ . '/../uploads/courses/';
                    $filesToDelete = [
                        $course['thumbnail'] ?? null,
                        $course['banner_image'] ?? null,
                        $course['preview_video'] ?? null
                    ];
                    
                    foreach ($filesToDelete as $file) {
                        if (!empty($file)) {
                            $filePath = $uploadDir . $file;
                            if (file_exists($filePath)) {
                                @unlink($filePath);
                            }
                        }
                    }
                }
                
                $stmt = $pdo->prepare("DELETE FROM courses WHERE id = ?");
                $stmt->execute([$id]);
                $success = 'Course deleted successfully';
            } catch (PDOException $e) {
                $error = 'Failed to delete course';
            }
        }
    }
}

// Check for edit mode
$editCourseId = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
$editCourse = null;

if ($editCourseId > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM courses WHERE id = ?");
        $stmt->execute([$editCourseId]);
        $editCourse = $stmt->fetch();
    } catch (PDOException $e) {
        $error = 'Course not found';
    }
}

// Get level filter
$levelFilter = isset($_GET['level']) ? sanitize($_GET['level']) : 'all';
$validLevels = ['all', 'beginner', 'intermediate', 'advanced'];
if (!in_array($levelFilter, $validLevels)) {
    $levelFilter = 'all';
}

// Get all courses with teacher names and category names, filtered by level
try {
    $sql = "SELECT c.*, u.name as teacher_name, u.email as teacher_email, cat.name as category_name,
                   (SELECT COUNT(*) FROM enrollments WHERE course_id = c.id) as enrolled_count
            FROM courses c 
            LEFT JOIN users u ON c.teacher_id = u.id 
            LEFT JOIN categories cat ON c.category_id = cat.id";
    
    if ($levelFilter !== 'all') {
        $sql .= " WHERE c.level = ?";
    }
    
    $sql .= " ORDER BY c.created_at DESC, c.id DESC";
    
    $stmt = $pdo->prepare($sql);
    if ($levelFilter !== 'all') {
        $stmt->execute([$levelFilter]);
    } else {
        $stmt->execute();
    }
    $courses = $stmt->fetchAll();
} catch (PDOException $e) {
    try {
        $sql = "SELECT c.*, u.name as teacher_name, u.email as teacher_email, cat.name as category_name,
                       (SELECT COUNT(*) FROM enrollments WHERE course_id = c.id) as enrolled_count
                FROM courses c 
                LEFT JOIN users u ON c.teacher_id = u.id 
                LEFT JOIN categories cat ON c.category_id = cat.id";
        
        if ($levelFilter !== 'all') {
            $sql .= " WHERE c.level = ?";
        }
        
        $sql .= " ORDER BY c.id DESC";
        
        $stmt = $pdo->prepare($sql);
        if ($levelFilter !== 'all') {
            $stmt->execute([$levelFilter]);
        } else {
            $stmt->execute();
        }
        $courses = $stmt->fetchAll();
    } catch (PDOException $e2) {
        $courses = [];
    }
}

$currentPage = 'courses.php';
$pageTitle = 'Manage Courses';

?>
<!DOCTYPE html>
<html lang="en" class="<?php echo isset($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === 'enabled' ? 'dark' : ''; ?>">
<head>
    <link rel="icon" href="/Iqra-College/assets/images/iqra2.png" type="image/png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - Admin - IQRA</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config={darkMode:'class',theme:{extend:{colors:{primary:{50:'#eff6ff',100:'#dbeafe',500:'#3b82f6',600:'#2563eb'}}}}};</script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .thumbnail-preview { max-width: 100%; max-height: 200px; object-fit: cover; border-radius: 8px; margin-top: 10px; }
        .fade-in { animation: fadeIn 0.5s ease-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 min-h-screen">
    <?php include __DIR__ . '/../includes/admin_sidebar.php'; ?>
    <div class="lg:ml-64">
        <header class="bg-white dark:bg-gray-800 shadow border-b border-gray-200 dark:border-gray-700 sticky top-0 z-20">
            <div class="px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <button id="mobile-menu-toggle" class="lg:hidden p-2 rounded-lg text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700"><i class="fas fa-bars text-xl"></i></button>
                        <div>
                            <h1 class="text-2xl font-bold text-gray-800 dark:text-white"><?php echo $pageTitle; ?></h1>
                            <p class="text-sm text-gray-500 dark:text-gray-400">All courses from all teachers</p>
                        </div>
                    </div>
                    <?php include __DIR__ . '/../includes/admin_header.php'; ?>
                </div>
            </div>
        </header>
        <main class="p-4 sm:p-6 lg:p-8">
            <?php if ($error): ?>
                <div class="bg-red-100 dark:bg-red-900/30 border-l-4 border-red-500 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg mb-6 fade-in">
                    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="bg-green-100 dark:bg-green-900/30 border-l-4 border-green-500 text-green-700 dark:text-green-400 px-4 py-3 rounded-lg mb-6 fade-in">
                    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <!-- Add/Edit Course Form -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow border border-gray-100 dark:border-gray-700 p-6 mb-8 fade-in">
            <h2 class="text-2xl font-bold text-gray-800 dark:text-white mb-4">
                <i class="fas fa-<?php echo $editCourse ? 'edit' : 'plus-circle'; ?> mr-2"></i>
                <?php echo $editCourse ? 'Edit Course' : 'Create New Course'; ?>
            </h2>
            
            <!-- Include the comprehensive form from teacher/courses.php structure -->
            <?php 
            // We'll use the same form structure but adapted for admin
            // For brevity, I'll include the key sections
            ?>
            
            <form method="POST" enctype="multipart/form-data" class="space-y-6" id="courseForm">
                <input type="hidden" name="action" value="<?php echo $editCourse ? 'edit' : 'add'; ?>">
                <?php if ($editCourse): ?>
                    <input type="hidden" name="course_id" value="<?php echo $editCourse['id']; ?>">
                <?php endif; ?>
                
                <!-- Basic Information Section -->
                <div class="bg-blue-50 dark:bg-blue-900/20 p-4 rounded-lg border-2 border-blue-200 dark:border-blue-700">
                    <h3 class="text-lg font-bold text-blue-800 dark:text-blue-300 mb-4 flex items-center">
                        <i class="fas fa-info-circle mr-2"></i>Basic Information
                    </h3>
                    
                    <div class="grid md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-heading mr-1"></i>Course Title *
                            </label>
                            <input type="text" name="title" required placeholder="Course Title" 
                                   value="<?php echo htmlspecialchars($editCourse['title'] ?? ''); ?>"
                                   class="w-full px-4 py-2 border-2 border-blue-200 dark:border-blue-700 rounded-lg focus:border-blue-500 dark:focus:border-blue-400 focus:outline-none bg-white dark:bg-gray-700 text-gray-800 dark:text-white"
                                   oninput="updateSlugPreview(this.value)">
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Slug: <span id="slugPreview" class="font-mono text-blue-600"></span></p>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-user-tie mr-1"></i>Teacher *
                            </label>
                            <select name="teacher_id" required 
                                    class="w-full px-4 py-2 border-2 border-blue-200 dark:border-blue-700 rounded-lg focus:border-blue-500 dark:focus:border-blue-400 focus:outline-none bg-white dark:bg-gray-700 text-gray-800 dark:text-white">
                                <option value="">Select Teacher</option>
                                <?php foreach ($teachers as $teacher): ?>
                                    <option value="<?php echo $teacher['id']; ?>" 
                                            <?php echo ($editCourse && $editCourse['teacher_id'] == $teacher['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($teacher['name'] . ' (' . $teacher['email'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="grid md:grid-cols-2 gap-4 mt-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-folder mr-1"></i>Category
                            </label>
                            <select name="category_id" 
                                    class="w-full px-4 py-2 border-2 border-blue-200 dark:border-blue-700 rounded-lg focus:border-blue-500 dark:focus:border-blue-400 focus:outline-none bg-white dark:bg-gray-700 text-gray-800 dark:text-white">
                                <option value="">Select Category (Optional)</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat['id']; ?>" 
                                            <?php echo ($editCourse && $editCourse['category_id'] == $cat['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-language mr-1"></i>Language
                            </label>
                            <input type="text" name="language" value="<?php echo htmlspecialchars($editCourse['language'] ?? 'English'); ?>" 
                                   placeholder="English" 
                                   class="w-full px-4 py-2 border-2 border-blue-200 dark:border-blue-700 rounded-lg focus:border-blue-500 dark:focus:border-blue-400 focus:outline-none bg-white dark:bg-gray-700 text-gray-800 dark:text-white">
                        </div>
                    </div>
                    
                    <div class="mt-4">
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                            <i class="fas fa-align-left mr-1"></i>Description
                        </label>
                        <textarea name="description" placeholder="Course Description" rows="4" 
                                  class="w-full px-4 py-2 border-2 border-blue-200 dark:border-blue-700 rounded-lg focus:border-blue-500 dark:focus:border-blue-400 focus:outline-none bg-white dark:bg-gray-700 text-gray-800 dark:text-white"><?php echo htmlspecialchars($editCourse['description'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="grid md:grid-cols-2 gap-4 mt-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-signal mr-1"></i>Level
                            </label>
                            <select name="level" 
                                    class="w-full px-4 py-2 border-2 border-blue-200 dark:border-blue-700 rounded-lg focus:border-blue-500 dark:focus:border-blue-400 focus:outline-none bg-white dark:bg-gray-700 text-gray-800 dark:text-white">
                                <option value="beginner" <?php echo ($editCourse && ($editCourse['level'] ?? 'beginner') === 'beginner') ? 'selected' : ''; ?>>Beginner</option>
                                <option value="intermediate" <?php echo ($editCourse && ($editCourse['level'] ?? 'beginner') === 'intermediate') ? 'selected' : ''; ?>>Intermediate</option>
                                <option value="advanced" <?php echo ($editCourse && ($editCourse['level'] ?? 'beginner') === 'advanced') ? 'selected' : ''; ?>>Advanced</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-eye mr-1"></i>Status
                            </label>
                            <select name="status" 
                                    class="w-full px-4 py-2 border-2 border-blue-200 dark:border-blue-700 rounded-lg focus:border-blue-500 dark:focus:border-blue-400 focus:outline-none bg-white dark:bg-gray-700 text-gray-800 dark:text-white">
                                <option value="draft" <?php echo ($editCourse && ($editCourse['status'] ?? 'draft') === 'draft') ? 'selected' : ''; ?>>Draft</option>
                                <option value="published" <?php echo ($editCourse && ($editCourse['status'] ?? 'draft') === 'published') ? 'selected' : ''; ?>>Published</option>
                                <option value="archived" <?php echo ($editCourse && ($editCourse['status'] ?? 'draft') === 'archived') ? 'selected' : ''; ?>>Archived</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <!-- Pricing Section -->
                <div class="bg-green-50 dark:bg-green-900/20 p-4 rounded-lg border-2 border-green-200 dark:border-green-700">
                    <h3 class="text-lg font-bold text-green-800 dark:text-green-300 mb-4 flex items-center">
                        <i class="fas fa-dollar-sign mr-2"></i>Pricing
                    </h3>
                    
                    <div class="grid md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-tag mr-1"></i>Price ($)
                            </label>
                            <input type="number" name="price" step="0.01" min="0" value="<?php echo $editCourse['price'] ?? 0; ?>" placeholder="0.00" 
                                   class="w-full px-4 py-2 border-2 border-green-200 dark:border-green-700 rounded-lg focus:border-green-500 dark:focus:border-green-400 focus:outline-none bg-white dark:bg-gray-700 text-gray-800 dark:text-white"
                                   id="priceInput">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-percent mr-1"></i>Discount Price ($)
                            </label>
                            <input type="number" name="discount_price" step="0.01" min="0" value="<?php echo $editCourse['discount_price'] ?? ''; ?>" placeholder="0.00 (Optional)" 
                                   class="w-full px-4 py-2 border-2 border-green-200 dark:border-green-700 rounded-lg focus:border-green-500 dark:focus:border-green-400 focus:outline-none bg-white dark:bg-gray-700 text-gray-800 dark:text-white"
                                   id="discountInput">
                        </div>
                        
                        <div class="flex items-end">
                            <label class="flex items-center space-x-2 cursor-pointer">
                                <input type="checkbox" name="is_free" value="1" 
                                       <?php echo ($editCourse && ($editCourse['is_free'] ?? 0) == 1) ? 'checked' : ''; ?>
                                       class="w-5 h-5 text-green-600 rounded focus:ring-green-500"
                                       onchange="toggleFreeCourse(this)">
                                <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">
                                    <i class="fas fa-gift mr-1"></i>Free Course
                                </span>
                            </label>
                        </div>
                    </div>
                </div>
                
                <!-- Course Details Section -->
                <div class="bg-purple-50 dark:bg-purple-900/20 p-4 rounded-lg border-2 border-purple-200 dark:border-purple-700">
                    <h3 class="text-lg font-bold text-purple-800 dark:text-purple-300 mb-4 flex items-center">
                        <i class="fas fa-cog mr-2"></i>Course Details
                    </h3>
                    
                    <div class="grid md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-clock mr-1"></i>Duration (hours)
                            </label>
                            <input type="number" name="duration" min="0" value="<?php echo $editCourse['duration'] ?? 0; ?>" placeholder="0" 
                                   class="w-full px-4 py-2 border-2 border-purple-200 dark:border-purple-700 rounded-lg focus:border-purple-500 dark:focus:border-purple-400 focus:outline-none bg-white dark:bg-gray-700 text-gray-800 dark:text-white">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-calendar-alt mr-1"></i>Access Days
                            </label>
                            <input type="number" name="access_days" min="0" value="<?php echo $editCourse['access_days'] ?? 0; ?>" placeholder="0 = Lifetime" 
                                   class="w-full px-4 py-2 border-2 border-purple-200 dark:border-purple-700 rounded-lg focus:border-purple-500 dark:focus:border-purple-400 focus:outline-none bg-white dark:bg-gray-700 text-gray-800 dark:text-white">
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">0 = Lifetime access</p>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-users mr-1"></i>Max Students
                            </label>
                            <input type="number" name="max_students" min="0" value="<?php echo $editCourse['max_students'] ?? ''; ?>" placeholder="Unlimited (leave empty)" 
                                   class="w-full px-4 py-2 border-2 border-purple-200 dark:border-purple-700 rounded-lg focus:border-purple-500 dark:focus:border-purple-400 focus:outline-none bg-white dark:bg-gray-700 text-gray-800 dark:text-white">
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Leave empty for unlimited</p>
                        </div>
                    </div>
                    
                    <div class="mt-4">
                        <label class="flex items-center space-x-2 cursor-pointer">
                            <input type="checkbox" name="has_certificate" value="1" 
                                   <?php echo ($editCourse && ($editCourse['has_certificate'] ?? 0) == 1) ? 'checked' : ''; ?>
                                   class="w-5 h-5 text-purple-600 rounded focus:ring-purple-500">
                            <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">
                                <i class="fas fa-certificate mr-1"></i>Issue Certificate upon Completion
                            </span>
                        </label>
                    </div>
                </div>
                
                <!-- Media Section -->
                <div class="bg-yellow-50 dark:bg-yellow-900/20 p-4 rounded-lg border-2 border-yellow-200 dark:border-yellow-700">
                    <h3 class="text-lg font-bold text-yellow-800 dark:text-yellow-300 mb-4 flex items-center">
                        <i class="fas fa-images mr-2"></i>Media Files
                    </h3>
                    
                    <div class="grid md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-image mr-1"></i>Thumbnail Image
                            </label>
                            <input type="file" name="thumbnail" accept="image/jpeg,image/png,image/gif,image/webp" 
                                   id="thumbnailInput"
                                   class="w-full px-4 py-2 border-2 border-yellow-200 dark:border-yellow-700 rounded-lg focus:border-yellow-500 dark:focus:border-yellow-400 focus:outline-none bg-white dark:bg-gray-700 text-gray-800 dark:text-white"
                                   onchange="previewThumbnail(this)">
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">JPG, PNG, GIF, WEBP (max 5MB)</p>
                            <div id="thumbnailPreview" class="mt-2">
                                <?php if ($editCourse && !empty($editCourse['thumbnail'])): 
                                    $currentThumbFullPath = __DIR__ . '/../uploads/courses/' . $editCourse['thumbnail'];
                                    if (file_exists($currentThumbFullPath)):
                                ?>
                                    <img src="../uploads/courses/<?php echo htmlspecialchars($editCourse['thumbnail']); ?>" alt="Current thumbnail" class="thumbnail-preview border-2 border-yellow-300 dark:border-yellow-600 rounded-lg mt-2">
                                <?php endif; endif; ?>
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-image mr-1"></i>Banner Image
                            </label>
                            <input type="file" name="banner_image" accept="image/jpeg,image/png,image/gif,image/webp" 
                                   id="bannerInput"
                                   class="w-full px-4 py-2 border-2 border-yellow-200 dark:border-yellow-700 rounded-lg focus:border-yellow-500 dark:focus:border-yellow-400 focus:outline-none bg-white dark:bg-gray-700 text-gray-800 dark:text-white"
                                   onchange="previewBanner(this)">
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">JPG, PNG, GIF, WEBP (max 10MB)</p>
                            <div id="bannerPreview" class="mt-2">
                                <?php if ($editCourse && !empty($editCourse['banner_image'])): 
                                    $currentBannerFullPath = __DIR__ . '/../uploads/courses/' . $editCourse['banner_image'];
                                    if (file_exists($currentBannerFullPath)):
                                ?>
                                    <img src="../uploads/courses/<?php echo htmlspecialchars($editCourse['banner_image']); ?>" alt="Current banner" class="thumbnail-preview border-2 border-yellow-300 dark:border-yellow-600 rounded-lg mt-2">
                                <?php endif; endif; ?>
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-video mr-1"></i>Preview Video
                            </label>
                            <input type="file" name="preview_video" accept="video/mp4,video/webm,video/ogg,video/quicktime" 
                                   id="videoInput"
                                   class="w-full px-4 py-2 border-2 border-yellow-200 dark:border-yellow-700 rounded-lg focus:border-yellow-500 dark:focus:border-yellow-400 focus:outline-none bg-white dark:bg-gray-700 text-gray-800 dark:text-white"
                                   onchange="previewVideo(this)">
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">MP4, WEBM, OGG, MOV (max 50MB)</p>
                            <div id="videoPreview" class="mt-2">
                                <?php if ($editCourse && !empty($editCourse['preview_video'])): 
                                    $currentVideoFullPath = __DIR__ . '/../uploads/courses/' . $editCourse['preview_video'];
                                    if (file_exists($currentVideoFullPath)):
                                ?>
                                    <video src="../uploads/courses/<?php echo htmlspecialchars($editCourse['preview_video']); ?>" controls class="w-full h-32 rounded-lg border-2 border-yellow-300 dark:border-yellow-600 mt-2"></video>
                                <?php endif; endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- SEO Section -->
                <div class="bg-indigo-50 dark:bg-indigo-900/20 p-4 rounded-lg border-2 border-indigo-200 dark:border-indigo-700">
                    <h3 class="text-lg font-bold text-indigo-800 dark:text-indigo-300 mb-4 flex items-center">
                        <i class="fas fa-search mr-2"></i>SEO Settings (Optional)
                    </h3>
                    
                    <div class="grid md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-heading mr-1"></i>Meta Title
                            </label>
                            <input type="text" name="meta_title" value="<?php echo htmlspecialchars($editCourse['meta_title'] ?? ''); ?>" 
                                   placeholder="SEO Title (Optional)" 
                                   class="w-full px-4 py-2 border-2 border-indigo-200 dark:border-indigo-700 rounded-lg focus:border-indigo-500 dark:focus:border-indigo-400 focus:outline-none bg-white dark:bg-gray-700 text-gray-800 dark:text-white">
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">For search engines</p>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-align-left mr-1"></i>Meta Description
                            </label>
                            <textarea name="meta_description" rows="2" placeholder="SEO Description (Optional)" 
                                      class="w-full px-4 py-2 border-2 border-indigo-200 dark:border-indigo-700 rounded-lg focus:border-indigo-500 dark:focus:border-indigo-400 focus:outline-none bg-white dark:bg-gray-700 text-gray-800 dark:text-white"><?php echo htmlspecialchars($editCourse['meta_description'] ?? ''); ?></textarea>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">For search engines</p>
                        </div>
                    </div>
                </div>
                
                <!-- Submit Buttons -->
                <div class="flex space-x-3 pt-4 border-t border-gray-200 dark:border-gray-700">
                    <button type="submit" 
                            class="bg-gradient-to-r from-indigo-500 to-purple-600 text-white px-8 py-3 rounded-lg font-semibold hover:from-indigo-600 hover:to-purple-700 transition-all shadow-lg">
                        <i class="fas fa-<?php echo $editCourse ? 'save' : 'plus-circle'; ?> mr-2"></i>
                        <?php echo $editCourse ? 'Update Course' : 'Create Course'; ?>
                    </button>
                    <?php if ($editCourse): ?>
                        <a href="courses.php<?php echo $levelFilter !== 'all' ? '?level=' . htmlspecialchars($levelFilter) : ''; ?>" 
                           class="bg-gray-500 dark:bg-gray-600 text-white px-6 py-3 rounded-lg font-semibold hover:bg-gray-600 dark:hover:bg-gray-700 transition-colors">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

            <!-- Level Filter Tabs -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow border border-gray-100 dark:border-gray-700 p-4 mb-6 fade-in">
                <div class="flex flex-wrap items-center gap-3">
                    <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Filter by Level:</span>
                    <a href="courses.php" class="px-4 py-2 rounded-lg font-semibold transition-all <?php echo $levelFilter === 'all' ? 'bg-indigo-600 text-white shadow-lg' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600'; ?>">
                        <i class="fas fa-list mr-1"></i>All Courses
                    </a>
                    <a href="courses.php?level=beginner" class="px-4 py-2 rounded-lg font-semibold transition-all <?php echo $levelFilter === 'beginner' ? 'bg-green-600 text-white shadow-lg' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600'; ?>">
                        <i class="fas fa-seedling mr-1"></i>Beginner
                    </a>
                    <a href="courses.php?level=intermediate" class="px-4 py-2 rounded-lg font-semibold transition-all <?php echo $levelFilter === 'intermediate' ? 'bg-amber-600 text-white shadow-lg' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600'; ?>">
                        <i class="fas fa-chart-line mr-1"></i>Intermediate
                    </a>
                    <a href="courses.php?level=advanced" class="px-4 py-2 rounded-lg font-semibold transition-all <?php echo $levelFilter === 'advanced' ? 'bg-red-600 text-white shadow-lg' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600'; ?>">
                        <i class="fas fa-trophy mr-1"></i>Advanced
                    </a>
                </div>
            </div>

            <!-- Courses List -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow border border-gray-100 dark:border-gray-700 p-6 fade-in">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-2xl font-bold text-gray-800 dark:text-white">
                        <i class="fas fa-book mr-2"></i>
                        <?php 
                        $levelLabel = $levelFilter === 'all' ? 'All Courses' : ucfirst($levelFilter) . ' Courses';
                        echo $levelLabel . ' (' . count($courses) . ')';
                        ?>
                    </h2>
                </div>
                
                <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php if (empty($courses)): ?>
                    <div class="col-span-full text-center py-12 text-gray-500 dark:text-gray-400">
                        <i class="fas fa-book-open text-6xl mb-4 opacity-50"></i>
                        <p class="text-xl">No <?php echo $levelFilter !== 'all' ? strtolower($levelLabel) : 'courses'; ?> found. <?php echo $levelFilter === 'all' ? 'Create your first course above!' : 'Try another level filter.'; ?></p>
                    </div>
                <?php else: ?>
                    <?php foreach ($courses as $course): 
                        $thumbnailPath = null;
                        if (!empty($course['thumbnail'])) {
                            $fullPath = __DIR__ . '/../uploads/courses/' . $course['thumbnail'];
                            if (file_exists($fullPath)) {
                                $thumbnailPath = '../uploads/courses/' . $course['thumbnail'];
                            }
                        }
                        $courseLevel = $course['level'] ?? 'beginner';
                        $levelColors = [
                            'beginner' => ['bg' => 'bg-green-100 dark:bg-green-900/30', 'text' => 'text-green-700 dark:text-green-400', 'border' => 'border-green-500'],
                            'intermediate' => ['bg' => 'bg-amber-100 dark:bg-amber-900/30', 'text' => 'text-amber-700 dark:text-amber-400', 'border' => 'border-amber-500'],
                            'advanced' => ['bg' => 'bg-red-100 dark:bg-red-900/30', 'text' => 'text-red-700 dark:text-red-400', 'border' => 'border-red-500']
                        ];
                        $levelColor = $levelColors[$courseLevel] ?? $levelColors['beginner'];
                    ?>
                        <div class="bg-white dark:bg-gray-800 border-2 border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden hover:shadow-lg dark:hover:shadow-xl transition-all hover:border-indigo-400 dark:hover:border-indigo-600">
                            <?php if ($thumbnailPath): ?>
                                <img src="<?php echo htmlspecialchars($thumbnailPath); ?>" 
                                     alt="<?php echo htmlspecialchars($course['title']); ?>" 
                                     class="w-full h-48 object-cover">
                            <?php else: ?>
                                <div class="w-full h-48 bg-gradient-to-br from-indigo-400 to-purple-600 flex items-center justify-center">
                                    <span class="text-white text-4xl font-bold"><?php echo strtoupper(substr($course['title'], 0, 1)); ?></span>
                                </div>
                            <?php endif; ?>
                            
                            <div class="p-4">
                                <div class="flex items-start justify-between mb-2">
                                    <h3 class="text-lg font-bold text-gray-800 dark:text-white flex-1"><?php echo htmlspecialchars($course['title']); ?></h3>
                                    <span class="px-2 py-1 rounded text-xs font-semibold <?php echo $levelColor['bg'] . ' ' . $levelColor['text']; ?> border-l-2 <?php echo $levelColor['border']; ?>">
                                        <?php echo ucfirst($courseLevel); ?>
                                    </span>
                                </div>
                                
                                <div class="space-y-2 mb-3 text-sm">
                                    <div class="flex items-center gap-2 text-gray-700 dark:text-gray-300">
                                        <i class="fas fa-chalkboard-teacher text-indigo-500 w-4"></i>
                                        <span class="font-semibold">Teacher:</span>
                                        <span><?php echo htmlspecialchars($course['teacher_name'] ?? 'N/A'); ?></span>
                                    </div>
                                    <?php if ($course['category_name']): ?>
                                        <div class="flex items-center gap-2 text-gray-600 dark:text-gray-400">
                                            <i class="fas fa-folder text-amber-500 w-4"></i>
                                            <span class="font-semibold">Category:</span>
                                            <span><?php echo htmlspecialchars($course['category_name']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <div class="flex items-center gap-2 text-gray-600 dark:text-gray-400">
                                        <i class="fas fa-dollar-sign text-emerald-500 w-4"></i>
                                        <span class="font-semibold">Price:</span>
                                        <?php if (($course['is_free'] ?? 0) == 1): ?>
                                            <span class="text-green-600 dark:text-green-400 font-bold">FREE</span>
                                        <?php else: ?>
                                            <span>$<?php echo number_format($course['price'] ?? 0, 2); ?></span>
                                            <?php if (!empty($course['discount_price']) && $course['discount_price'] > 0): ?>
                                                <span class="text-red-600 dark:text-red-400 line-through">$<?php echo number_format($course['discount_price'], 2); ?></span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (isset($course['enrolled_count']) && $course['enrolled_count'] > 0): ?>
                                        <div class="flex items-center gap-2 text-gray-600 dark:text-gray-400">
                                            <i class="fas fa-users text-blue-500 w-4"></i>
                                            <span><?php echo (int)$course['enrolled_count']; ?> enrolled</span>
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <span class="inline-block px-2 py-1 rounded text-xs font-semibold 
                                            <?php 
                                            $status = $course['status'] ?? 'draft';
                                            echo $status === 'published' ? 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400' : 
                                                ($status === 'draft' ? 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-400' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-400');
                                            ?>">
                                            <i class="fas fa-<?php echo $status === 'published' ? 'check-circle' : ($status === 'draft' ? 'clock' : 'archive'); ?> mr-1"></i>
                                            <?php echo ucfirst($status); ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="flex justify-between items-center pt-3 border-t border-gray-200 dark:border-gray-700 gap-2">
                                    <span class="text-xs text-gray-500 dark:text-gray-400">
                                        <i class="fas fa-calendar mr-1"></i>
                                        <?php 
                                        $createdAt = $course['created_at'] ?? null;
                                        echo $createdAt ? date('M d, Y', strtotime($createdAt)) : 'N/A';
                                        ?>
                                    </span>
                                    <div class="flex gap-2">
                                        <a href="courses.php?edit=<?php echo $course['id']; ?><?php echo $levelFilter !== 'all' ? '&level=' . htmlspecialchars($levelFilter) : ''; ?>" 
                                           class="bg-indigo-500 dark:bg-indigo-600 text-white px-3 py-1 rounded-lg text-sm hover:bg-indigo-600 dark:hover:bg-indigo-700 transition-colors">
                                            <i class="fas fa-edit mr-1"></i>Edit
                                        </a>
                                        <form method="POST" class="inline" 
                                              onsubmit="return confirm('Are you sure you want to delete this course? This will delete all associated files and data.')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo $course['id']; ?>">
                                            <button type="submit" 
                                                    class="bg-red-500 dark:bg-red-600 text-white px-3 py-1 rounded-lg text-sm hover:bg-red-600 dark:hover:bg-red-700 transition-colors">
                                                <i class="fas fa-trash mr-1"></i>Delete
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Generate slug preview from title
        function updateSlugPreview(title) {
            const slugPreview = document.getElementById('slugPreview');
            if (title) {
                let slug = title.toLowerCase()
                    .trim()
                    .replace(/[^a-z0-9\s-]/g, '')
                    .replace(/\s+/g, '-')
                    .replace(/-+/g, '-')
                    .replace(/^-|-$/g, '');
                slugPreview.textContent = slug || '(will be generated)';
            } else {
                slugPreview.textContent = '(will be generated)';
            }
        }
        
        // Toggle free course - disable price fields
        function toggleFreeCourse(checkbox) {
            const priceInput = document.getElementById('priceInput');
            const discountInput = document.getElementById('discountInput');
            if (checkbox.checked) {
                priceInput.value = '0';
                priceInput.disabled = true;
                discountInput.value = '';
                discountInput.disabled = true;
            } else {
                priceInput.disabled = false;
                discountInput.disabled = false;
            }
        }
        
        // Preview thumbnail
        function previewThumbnail(input) {
            const preview = document.getElementById('thumbnailPreview');
            if (input.files && input.files[0]) {
                const file = input.files[0];
                const fileSize = (file.size / 1024 / 1024).toFixed(2);
                
                if (file.size > 5242880) {
                    alert('File size exceeds 5MB limit.');
                    input.value = '';
                    preview.innerHTML = '';
                    return;
                }
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.innerHTML = `
                        <div class="bg-green-50 p-2 rounded-lg border-2 border-green-300 mt-2">
                            <p class="text-xs font-semibold text-green-700 mb-1">New Image (${fileSize} MB)</p>
                            <img src="${e.target.result}" class="thumbnail-preview border-2 border-green-400 rounded-lg" alt="Preview">
                        </div>
                    `;
                };
                reader.readAsDataURL(file);
            }
        }
        
        // Preview banner
        function previewBanner(input) {
            const preview = document.getElementById('bannerPreview');
            if (input.files && input.files[0]) {
                const file = input.files[0];
                const fileSize = (file.size / 1024 / 1024).toFixed(2);
                
                if (file.size > 10485760) {
                    alert('File size exceeds 10MB limit.');
                    input.value = '';
                    preview.innerHTML = '';
                    return;
                }
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.innerHTML = `
                        <div class="bg-green-50 p-2 rounded-lg border-2 border-green-300 mt-2">
                            <p class="text-xs font-semibold text-green-700 mb-1">New Banner (${fileSize} MB)</p>
                            <img src="${e.target.result}" class="thumbnail-preview border-2 border-green-400 rounded-lg" alt="Banner preview">
                        </div>
                    `;
                };
                reader.readAsDataURL(file);
            }
        }
        
        // Preview video
        function previewVideo(input) {
            const preview = document.getElementById('videoPreview');
            if (input.files && input.files[0]) {
                const file = input.files[0];
                const fileSize = (file.size / 1024 / 1024).toFixed(2);
                
                if (file.size > 52428800) {
                    alert('File size exceeds 50MB limit.');
                    input.value = '';
                    preview.innerHTML = '';
                    return;
                }
                
                const url = URL.createObjectURL(file);
                preview.innerHTML = `
                    <div class="bg-green-50 p-2 rounded-lg border-2 border-green-300 mt-2">
                        <p class="text-xs font-semibold text-green-700 mb-1">New Video (${fileSize} MB)</p>
                        <video src="${url}" controls class="w-full h-32 rounded-lg border-2 border-green-400"></video>
                    </div>
                `;
            }
        }
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            const titleInput = document.querySelector('input[name="title"]');
            if (titleInput) {
                updateSlugPreview(titleInput.value);
            }
            
            const freeCheckbox = document.querySelector('input[name="is_free"]');
            if (freeCheckbox) {
                toggleFreeCourse(freeCheckbox);
            }
        });
    </script>
</body>
</html>
