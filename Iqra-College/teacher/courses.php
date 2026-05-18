<?php
/**
 * Teacher - Manage Courses with Auto Thumbnail Generation
 * Automatically generates thumbnails for courses if not provided
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

requireRole('teacher');

$teacherId = getCurrentUserId();
$pdo = getDBConnection();
$name = getCurrentUserName();
$error = '';
$success = '';
$pageTitle = 'My Courses';
$currentPage = 'courses';

/**
 * Generate automatic thumbnail image for course
 * Creates a gradient image with course initial or category icon
 */
function generateAutoThumbnail($courseTitle, $category = null, $level = 'beginner', $courseId = null) {
    // Check if GD library is available
    if (!function_exists('imagecreatetruecolor')) {
        return null;
    }
    
    $uploadDir = __DIR__ . '/../uploads/courses';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }
    
    // Generate filename
    $filename = 'auto_' . ($courseId ?? time()) . '_' . md5($courseTitle) . '.png';
    $filepath = $uploadDir . '/' . $filename;
    
    // Color schemes based on level
    $colorSchemes = [
        'beginner' => [
            ['r' => 59, 'g' => 130, 'b' => 246], // Blue
            ['r' => 37, 'g' => 99, 'b' => 235]   // Darker Blue
        ],
        'intermediate' => [
            ['r' => 251, 'g' => 191, 'b' => 36],  // Yellow
            ['r' => 245, 'g' => 158, 'b' => 11]   // Darker Yellow
        ],
        'advanced' => [
            ['r' => 239, 'g' => 68, 'b' => 68],   // Red
            ['r' => 220, 'g' => 38, 'b' => 38]    // Darker Red
        ]
    ];
    
    $colors = $colorSchemes[$level] ?? $colorSchemes['beginner'];
    
    // Create image
    $width = 800;
    $height = 450;
    $image = @imagecreatetruecolor($width, $height);
    
    if (!$image) {
        return null;
    }
    
    // Create gradient
    for ($i = 0; $i < $height; $i++) {
        $ratio = $i / $height;
        $r = round($colors[0]['r'] + ($colors[1]['r'] - $colors[0]['r']) * $ratio);
        $g = round($colors[0]['g'] + ($colors[1]['g'] - $colors[0]['g']) * $ratio);
        $b = round($colors[0]['b'] + ($colors[1]['b'] - $colors[0]['b']) * $ratio);
        $color = imagecolorallocate($image, $r, $g, $b);
        imageline($image, 0, $i, $width, $i, $color);
    }
    
    // Add text (course initial or first letter)
    $initial = strtoupper(substr(trim($courseTitle), 0, 1));
    if (empty($initial) || !ctype_alpha($initial)) {
        $initial = 'C';
    }
    
    // Text color (white)
    $textColor = imagecolorallocate($image, 255, 255, 255);
    
    // Use built-in font (works without TTF)
    $fontSize = 5; // Large built-in font
    $fontWidth = imagefontwidth($fontSize) * strlen($initial);
    $fontHeight = imagefontheight($fontSize);
    $x = ($width - $fontWidth) / 2;
    $y = ($height - $fontHeight) / 2;
    
    // Draw text
    imagestring($image, $fontSize, $x, $y, $initial, $textColor);
    
    // Save image
    @imagepng($image, $filepath);
    imagedestroy($image);
    
    // Verify file was created
    if (file_exists($filepath)) {
        return $filename;
    }
    
    return null;
}

// Get categories for dropdown
try {
    $stmt = $pdo->query("SELECT id, name FROM categories ORDER BY name");
    $categories = $stmt->fetchAll();
} catch (PDOException $e) {
    $categories = [];
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        // Basic Information
        $title = sanitize($_POST['title'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $category_id = !empty($_POST['category_id']) ? intval($_POST['category_id']) : null;
        $level = sanitize($_POST['level'] ?? 'beginner');
        $language = sanitize($_POST['language'] ?? 'English');
        
        // Generate slug from title
        $slug = null;
        if (!empty($title)) {
            // If editing, check if title changed - if not, preserve existing slug
            if ($action === 'edit' && !empty($courseId)) {
                try {
                    $stmt = $pdo->prepare("SELECT title, slug FROM courses WHERE id = ? AND teacher_id = ?");
                    $stmt->execute([$courseId, $teacherId]);
                    $existingCourse = $stmt->fetch();
                    if ($existingCourse && $existingCourse['title'] === $title && !empty($existingCourse['slug'])) {
                        // Title hasn't changed, preserve existing slug
                        $slug = $existingCourse['slug'];
                    }
                } catch (PDOException $e) {
                    // Continue to generate new slug
                }
            }
            
            // Generate new slug if not preserved
            if (empty($slug)) {
                $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title)));
                $slug = preg_replace('/-+/', '-', $slug);
                $slug = trim($slug, '-');
                
                // Ensure uniqueness
                try {
                    if ($action === 'edit' && !empty($courseId)) {
                        $checkSlug = $slug;
                        $counter = 1;
                        while (true) {
                            $stmt = $pdo->prepare("SELECT id FROM courses WHERE slug = ? AND id != ?");
                            $stmt->execute([$checkSlug, $courseId]);
                            if ($stmt->rowCount() == 0) break;
                            $checkSlug = $slug . '-' . $counter;
                            $counter++;
                            if ($counter > 100) break; // Safety limit
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
                            if ($counter > 100) break; // Safety limit
                        }
                        $slug = $checkSlug;
                    }
                } catch (PDOException $e) {
                    // If slug column doesn't exist, just use generated slug
                    error_log("Slug uniqueness check failed: " . $e->getMessage());
                }
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
        
        if (empty($title)) {
            $error = 'Please enter course title';
        } elseif ($teacherId <= 0) {
            $error = 'Invalid teacher ID. Please log in again.';
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
                        error_log("Thumbnail uploaded successfully: " . $thumbnail);
                    }
                } elseif (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $uploadErrors = [
                        UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive',
                        UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive',
                        UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                        UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
                    ];
                    $errorMsg = $uploadErrors[$_FILES['thumbnail']['error']] ?? 'Unknown upload error';
                    $error = 'Thumbnail upload failed: ' . $errorMsg;
                }
                
                // Handle banner image upload
                $banner_image = null;
                if (isset($_FILES['banner_image']) && $_FILES['banner_image']['error'] === UPLOAD_ERR_OK) {
                    $bannerFile = uploadFile(
                        $_FILES['banner_image'],
                        $uploadDir,
                        ['jpg', 'jpeg', 'png', 'gif', 'webp'],
                        10485760 // 10MB max for banner
                    );
                    if ($bannerFile === false) {
                        $error = 'Invalid banner image type. Please upload JPG, PNG, GIF, or WEBP (max 10MB)';
                    } elseif ($bannerFile !== null) {
                        $banner_image = $bannerFile;
                        error_log("Banner image uploaded successfully: " . $banner_image);
                    }
                }
                
                // Handle preview video upload
                $preview_video = null;
                if (isset($_FILES['preview_video']) && $_FILES['preview_video']['error'] === UPLOAD_ERR_OK) {
                    $videoFile = uploadFile(
                        $_FILES['preview_video'],
                        $uploadDir,
                        ['mp4', 'webm', 'ogg', 'mov'],
                        52428800 // 50MB max for video
                    );
                    if ($videoFile === false) {
                        $error = 'Invalid preview video type. Please upload MP4, WEBM, OGG, or MOV (max 50MB)';
                    } elseif ($videoFile !== null) {
                        $preview_video = $videoFile;
                        error_log("Preview video uploaded successfully: " . $preview_video);
                    }
                }
                
                // Auto-generate thumbnail if no image uploaded
                if ($autoGenerateThumbnail && empty($error)) {
                    // Get category name if available
                    $categoryName = null;
                    if ($category_id) {
                        try {
                            $stmt = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
                            $stmt->execute([$category_id]);
                            $cat = $stmt->fetch();
                            $categoryName = $cat['name'] ?? null;
                        } catch (PDOException $e) {
                            // Category doesn't exist, continue
                        }
                    }
                    
                    // Generate auto thumbnail
                    $generatedThumbnail = generateAutoThumbnail($title, $categoryName, $level, $courseId);
                    if ($generatedThumbnail) {
                        $thumbnail = $generatedThumbnail;
                    } else {
                        // If generation fails, use null (course will still be created)
                        $thumbnail = null;
                    }
                }
                
                if (empty($error)) {
                    // Verify courses table exists
                    try {
                        $stmt = $pdo->query("SHOW TABLES LIKE 'courses'");
                        if ($stmt->rowCount() == 0) {
                            throw new Exception('Courses table does not exist. Please run the database setup script first.');
                        }
                    } catch (PDOException $e) {
                        throw new Exception('Database error: ' . $e->getMessage());
                    }
                    
                    if ($action === 'edit' && $courseId) {
                        // Update existing course
                        // Get old media files to preserve if not replaced
                        $oldThumbnail = null;
                        $oldBanner = null;
                        $oldVideo = null;
                        
                        try {
                            $stmt = $pdo->prepare("SELECT thumbnail, banner_image, preview_video FROM courses WHERE id = ? AND teacher_id = ?");
                            $stmt->execute([$courseId, $teacherId]);
                            $oldCourse = $stmt->fetch();
                            
                            if ($oldCourse) {
                                // Preserve old values if new ones aren't uploaded
                                if (empty($thumbnail) && !empty($oldCourse['thumbnail'])) {
                                    $thumbnail = $oldCourse['thumbnail'];
                                }
                                if (empty($banner_image) && !empty($oldCourse['banner_image'])) {
                                    $banner_image = $oldCourse['banner_image'];
                                }
                                if (empty($preview_video) && !empty($oldCourse['preview_video'])) {
                                    $preview_video = $oldCourse['preview_video'];
                                }
                                
                                // Store old values for cleanup
                                $oldThumbnail = $oldCourse['thumbnail'] ?? null;
                                $oldBanner = $oldCourse['banner_image'] ?? null;
                                $oldVideo = $oldCourse['preview_video'] ?? null;
                                
                                // Clean up old auto-generated thumbnails if replaced
                                if (!empty($thumbnail) && $thumbnail !== $oldThumbnail && !empty($oldThumbnail)) {
                                    $oldThumbPath = __DIR__ . '/../uploads/courses/' . $oldThumbnail;
                                    if (strpos($oldThumbnail, 'auto_') === 0 && file_exists($oldThumbPath)) {
                                        @unlink($oldThumbPath);
                                    }
                                }
                            }
                        } catch (PDOException $e) {
                            // Continue - will use new values
                            error_log("Error fetching old course data: " . $e->getMessage());
                        }
                        
                        // Try multiple UPDATE attempts with all fields
                        $updated = false;
                        $updateAttempts = [
                            // Full update with all fields
                            [
                                'sql' => "UPDATE courses SET title = ?, slug = ?, description = ?, category_id = ?, level = ?, price = ?, discount_price = ?, is_free = ?, duration = ?, access_days = ?, max_students = ?, has_certificate = ?, language = ?, meta_title = ?, meta_description = ?, status = ?, thumbnail = ?, banner_image = ?, preview_video = ? WHERE id = ? AND teacher_id = ?",
                                'params' => [$title, $slug, $description, $category_id, $level, $price, $discount_price, $is_free, $duration, $access_days, $max_students, $has_certificate, $language, $meta_title, $meta_description, $status, $thumbnail, $banner_image, $preview_video, $courseId, $teacherId]
                            ],
                            // Without optional fields
                            [
                                'sql' => "UPDATE courses SET title = ?, slug = ?, description = ?, category_id = ?, level = ?, price = ?, duration = ?, status = ?, thumbnail = ? WHERE id = ? AND teacher_id = ?",
                                'params' => [$title, $slug, $description, $category_id, $level, $price, $duration, $status, $thumbnail, $courseId, $teacherId]
                            ],
                            // Minimal update
                            [
                                'sql' => "UPDATE courses SET title = ?, description = ?, thumbnail = ? WHERE id = ? AND teacher_id = ?",
                                'params' => [$title, $description, $thumbnail, $courseId, $teacherId]
                            ]
                        ];
                        
                        foreach ($updateAttempts as $attempt) {
                            try {
                                $stmt = $pdo->prepare($attempt['sql']);
                                $stmt->execute($attempt['params']);
                                $updated = true;
                                
                                // Set success message based on thumbnail
                                if (!empty($thumbnail)) {
                                    if (strpos($thumbnail, 'auto_') !== 0) {
                                        $success = 'Course updated successfully with new uploaded thumbnail!';
                                    } else {
                                        $success = 'Course updated successfully!';
                                    }
                                } else {
                                    $success = 'Course updated successfully!';
                                }
                                
                                // Update all fields separately if UPDATE didn't include them
                                if ($courseId) {
                                    try {
                                        // Get all columns
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
                                            $updateParams[] = $courseId;
                                            $updateParams[] = $teacherId;
                                            $updateSql = "UPDATE courses SET " . implode(', ', $fieldsToUpdate) . " WHERE id = ? AND teacher_id = ?";
                                            $stmt = $pdo->prepare($updateSql);
                                            $stmt->execute($updateParams);
                                        }
                                    } catch (PDOException $e) {
                                        error_log("Failed to update course fields separately: " . $e->getMessage());
                                        // Continue - course was updated
                                    }
                                }
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
                        $lastError = '';
                        $insertAttempts = [
                            // Try with all fields first
                            [
                                'sql' => "INSERT INTO courses (title, slug, description, category_id, teacher_id, level, price, discount_price, is_free, duration, access_days, max_students, has_certificate, language, meta_title, meta_description, status, thumbnail, banner_image, preview_video) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                                'params' => [$title, $slug, $description, $category_id, $teacherId, $level, $price, $discount_price, $is_free, $duration, $access_days, $max_students, $has_certificate, $language, $meta_title, $meta_description, $status, $thumbnail, $banner_image, $preview_video]
                            ],
                            // Without optional fields
                            [
                                'sql' => "INSERT INTO courses (title, slug, description, category_id, teacher_id, level, price, duration, status, thumbnail) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                                'params' => [$title, $slug, $description, $category_id, $teacherId, $level, $price, $duration, $status, $thumbnail]
                            ],
                            // Minimal with slug
                            [
                                'sql' => "INSERT INTO courses (title, slug, teacher_id) VALUES (?, ?, ?)",
                                'params' => [$title, $slug, $teacherId]
                            ],
                            // Minimal without slug
                            [
                                'sql' => "INSERT INTO courses (title, teacher_id) VALUES (?, ?)",
                                'params' => [$title, $teacherId]
                            ]
                        ];
                        
                        foreach ($insertAttempts as $attempt) {
                            // Skip if condition is not met
                            if (isset($attempt['condition']) && !$attempt['condition']) {
                                continue;
                            }
                            
                            try {
                                $stmt = $pdo->prepare($attempt['sql']);
                                $stmt->execute($attempt['params']);
                                $inserted = true;
                                $newCourseId = $pdo->lastInsertId();
                                
                                // Update all fields after insert if INSERT was minimal
                                // This ensures all fields are saved even if INSERT didn't include them
                                if ($newCourseId) {
                                    try {
                                        // Try to update with all fields
                                        $updateFields = [];
                                        $updateParams = [];
                                        
                                        // Check which columns exist and build UPDATE query
                                        $stmt = $pdo->query("SHOW COLUMNS FROM courses");
                                        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
                                        
                                        $fieldsToUpdate = [
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
                                        
                                        foreach ($fieldsToUpdate as $field => $value) {
                                            if (in_array($field, $columns)) {
                                                $updateFields[] = "$field = ?";
                                                $updateParams[] = $value;
                                            }
                                        }
                                        
                                        if (!empty($updateFields)) {
                                            $updateParams[] = $newCourseId;
                                            $updateSql = "UPDATE courses SET " . implode(', ', $updateFields) . " WHERE id = ?";
                                            $stmt = $pdo->prepare($updateSql);
                                            $stmt->execute($updateParams);
                                            error_log("Course fields updated for course ID: " . $newCourseId);
                                        }
                                    } catch (PDOException $e) {
                                        error_log("Failed to update course fields: " . $e->getMessage());
                                        // Continue - course was created
                                    }
                                }
                                
                                // If auto-generation didn't happen and we still need a thumbnail
                                if ($autoGenerateThumbnail && empty($thumbnail) && $newCourseId) {
                                    // If no thumbnail was uploaded and auto-generation didn't happen, try now with course ID
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
                                    
                                    $newThumbnail = generateAutoThumbnail($title, $categoryName, $level, $newCourseId);
                                    if ($newThumbnail) {
                                        try {
                                            $stmt = $pdo->query("SHOW COLUMNS FROM courses LIKE 'thumbnail'");
                                            if ($stmt->rowCount() > 0) {
                                                $stmt = $pdo->prepare("UPDATE courses SET thumbnail = ? WHERE id = ?");
                                                $stmt->execute([$newThumbnail, $newCourseId]);
                                                $thumbnail = $newThumbnail;
                                                error_log("Auto-generated thumbnail saved for course ID: " . $newCourseId);
                                            }
                                        } catch (PDOException $e) {
                                            error_log("Failed to save auto-generated thumbnail: " . $e->getMessage());
                                        }
                                    }
                                }
                                
                                if (!empty($thumbnail)) {
                                    // Check if it's an uploaded thumbnail (not auto-generated)
                                    if (strpos($thumbnail, 'auto_') !== 0) {
                                        $success = 'Course created successfully with uploaded thumbnail!';
                                    } else {
                                        $success = 'Course created successfully with auto-generated thumbnail!';
                                    }
                                } else {
                                    $success = 'Course created successfully!';
                                }
                                break;
                            } catch (PDOException $e) {
                                $lastError = $e->getMessage();
                                error_log("Course insert attempt failed: " . $lastError);
                                continue;
                            }
                        }
                        
                        if (!$inserted) {
                            $errorDetails = htmlspecialchars($lastError);
                            throw new Exception('Failed to insert course. Error: ' . $errorDetails . '. <a href="/Iqra-College/teacher/check_courses_table.php" target="_blank" class="underline font-semibold">Click here to run diagnostic</a> or check if the courses table exists and has the required columns (title, teacher_id).');
                        }
                    }
                }
            } catch (PDOException $e) {
                $errorMsg = $e->getMessage();
                error_log("Course save PDO error: " . $errorMsg);
                $error = 'Failed to save course: ' . htmlspecialchars($errorMsg);
                
                // Provide helpful error messages
                if (strpos($errorMsg, "doesn't exist") !== false) {
                    $error .= '<br><small>Hint: The courses table may not exist. Please run the database setup script.</small>';
                } elseif (strpos($errorMsg, "Unknown column") !== false) {
                    $error .= '<br><small>Hint: The courses table structure may be outdated. Please check your database schema.</small>';
                } elseif (strpos($errorMsg, "foreign key constraint") !== false) {
                    $error .= '<br><small>Hint: Invalid teacher_id or category_id. Please check your user account and category selection.</small>';
                }
            } catch (Exception $e) {
                $errorMsg = $e->getMessage();
                error_log("Course save error: " . $errorMsg);
                $error = htmlspecialchars($errorMsg);
            }
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                // First, verify course belongs to this teacher
                $stmt = $pdo->prepare("SELECT id, title, thumbnail, banner_image, preview_video FROM courses WHERE id = ? AND teacher_id = ?");
                $stmt->execute([$id, $teacherId]);
                $course = $stmt->fetch();
                
                if (!$course) {
                    $error = 'Course not found or you do not have permission to delete it.';
                } else {
                    $courseTitle = $course['title'] ?? 'Course';
                    $uploadDir = __DIR__ . '/../uploads/courses/';
                    $filesDeleted = [];
                    $filesFailed = [];
                    
                    // Delete all associated files
                    $filesToDelete = [
                        'thumbnail' => $course['thumbnail'] ?? null,
                        'banner_image' => $course['banner_image'] ?? null,
                        'preview_video' => $course['preview_video'] ?? null
                    ];
                    
                    foreach ($filesToDelete as $fileType => $filename) {
                        if (!empty($filename)) {
                            $filePath = $uploadDir . $filename;
                            if (file_exists($filePath)) {
                                if (@unlink($filePath)) {
                                    $filesDeleted[] = $fileType;
                                } else {
                                    $filesFailed[] = $fileType;
                                }
                            }
                        }
                    }
                    
                    // Delete related data (enrollments, sections, lessons will be CASCADE deleted)
                    // But we can also explicitly delete them for better control
                    try {
                        // Delete enrollments
                        $stmt = $pdo->prepare("DELETE FROM enrollments WHERE course_id = ?");
                        $stmt->execute([$id]);
                    } catch (PDOException $e) {
                        // Table might not exist or CASCADE will handle it
                        error_log("Note: Could not delete enrollments (may be handled by CASCADE): " . $e->getMessage());
                    }
                    
                    try {
                        // Delete lessons (which may have files too)
                        $stmt = $pdo->prepare("SELECT video_file, audio_file, document_file FROM lessons WHERE course_id = ?");
                        $stmt->execute([$id]);
                        $lessons = $stmt->fetchAll();
                        
                        // Delete lesson files
                        foreach ($lessons as $lesson) {
                            $lessonFiles = [
                                $lesson['video_file'] ?? null,
                                $lesson['audio_file'] ?? null,
                                $lesson['document_file'] ?? null
                            ];
                            
                            foreach ($lessonFiles as $lessonFile) {
                                if (!empty($lessonFile)) {
                                    $lessonFilePath = __DIR__ . '/../uploads/lessons/' . $lessonFile;
                                    if (file_exists($lessonFilePath)) {
                                        @unlink($lessonFilePath);
                                    }
                                }
                            }
                        }
                        
                        // Delete lessons
                        $stmt = $pdo->prepare("DELETE FROM lessons WHERE course_id = ?");
                        $stmt->execute([$id]);
                    } catch (PDOException $e) {
                        error_log("Note: Could not delete lessons (may be handled by CASCADE): " . $e->getMessage());
                    }
                    
                    try {
                        // Delete sections
                        $stmt = $pdo->prepare("DELETE FROM sections WHERE course_id = ?");
                        $stmt->execute([$id]);
                    } catch (PDOException $e) {
                        error_log("Note: Could not delete sections (may be handled by CASCADE): " . $e->getMessage());
                    }
                    
                    try {
                        // Delete course materials
                        $stmt = $pdo->prepare("SELECT file_path FROM materials WHERE course_id = ?");
                        $stmt->execute([$id]);
                        $materials = $stmt->fetchAll();
                        
                        // Delete material files
                        foreach ($materials as $material) {
                            if (!empty($material['file_path'])) {
                                $materialPath = __DIR__ . '/../uploads/materials/' . basename($material['file_path']);
                                if (file_exists($materialPath)) {
                                    @unlink($materialPath);
                                }
                            }
                        }
                        
                        $stmt = $pdo->prepare("DELETE FROM materials WHERE course_id = ?");
                        $stmt->execute([$id]);
                    } catch (PDOException $e) {
                        error_log("Note: Could not delete materials: " . $e->getMessage());
                    }
                    
                    // Finally, delete the course itself
                    $stmt = $pdo->prepare("DELETE FROM courses WHERE id = ? AND teacher_id = ?");
                    $stmt->execute([$id, $teacherId]);
                    
                    if ($stmt->rowCount() > 0) {
                        $successMsg = "Course '<strong>" . htmlspecialchars($courseTitle) . "</strong>' deleted successfully from database.";
                        if (!empty($filesDeleted)) {
                            $successMsg .= " Deleted files: " . implode(', ', $filesDeleted) . ".";
                        }
                        if (!empty($filesFailed)) {
                            $successMsg .= " Warning: Could not delete some files: " . implode(', ', $filesFailed) . ".";
                        }
                        $success = $successMsg;
                    } else {
                        $error = 'Course could not be deleted. It may have already been deleted.';
                    }
                }
            } catch (PDOException $e) {
                error_log("Delete course error: " . $e->getMessage());
                $error = 'Failed to delete course: ' . htmlspecialchars($e->getMessage());
            } catch (Exception $e) {
                error_log("Delete course error: " . $e->getMessage());
                $error = 'Failed to delete course: ' . htmlspecialchars($e->getMessage());
            }
        } else {
            $error = 'Invalid course ID';
        }
    }
}

// Get editing course if ID provided
$editingCourse = null;
if (isset($_GET['edit'])) {
    $editId = intval($_GET['edit']);
    try {
        $stmt = $pdo->prepare("SELECT * FROM courses WHERE id = ? AND teacher_id = ?");
        $stmt->execute([$editId, $teacherId]);
        $editingCourse = $stmt->fetch();
    } catch (PDOException $e) {
        $error = 'Course not found';
    }
}

// Get teacher's courses - only assigned courses if RBAC is enabled
try {
    // Check if teacher_course_assignments table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'teacher_course_assignments'");
    if ($stmt->rowCount() > 0) {
        // Use RBAC: Get only assigned courses
        $stmt = $pdo->prepare("SELECT DISTINCT c.*, cat.name as category_name 
                              FROM courses c 
                              JOIN teacher_course_assignments tca ON c.id = tca.course_id
                              LEFT JOIN categories cat ON c.category_id = cat.id 
                              WHERE tca.teacher_id = ? 
                              ORDER BY c.created_at DESC, c.id DESC");
        $stmt->execute([$teacherId]);
        $courses = $stmt->fetchAll();
    } else {
        // Fallback: Get all courses created by teacher (backward compatibility)
        $stmt = $pdo->prepare("SELECT c.*, cat.name as category_name FROM courses c 
                              LEFT JOIN categories cat ON c.category_id = cat.id 
                              WHERE c.teacher_id = ? 
                              ORDER BY c.created_at DESC, c.id DESC");
        $stmt->execute([$teacherId]);
        $courses = $stmt->fetchAll();
    }
} catch (PDOException $e) {
    // Fallback if created_at doesn't exist
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'teacher_course_assignments'");
        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->prepare("SELECT DISTINCT c.*, cat.name as category_name 
                                  FROM courses c 
                                  JOIN teacher_course_assignments tca ON c.id = tca.course_id
                                  LEFT JOIN categories cat ON c.category_id = cat.id 
                                  WHERE tca.teacher_id = ? 
                                  ORDER BY c.id DESC");
            $stmt->execute([$teacherId]);
            $courses = $stmt->fetchAll();
        } else {
            $stmt = $pdo->prepare("SELECT c.*, cat.name as category_name FROM courses c 
                                  LEFT JOIN categories cat ON c.category_id = cat.id 
                                  WHERE c.teacher_id = ? 
                                  ORDER BY c.id DESC");
            $stmt->execute([$teacherId]);
            $courses = $stmt->fetchAll();
        }
    } catch (PDOException $e2) {
        $courses = [];
    }
}

?>
<!DOCTYPE html>
<html lang="en" class="<?php echo isset($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === 'enabled' ? 'dark' : ''; ?>">
<head>
    <link rel="icon" href="/Iqra-College/assets/images/iqra2.png" type="image/png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - Teacher</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .thumbnail-preview {
            max-width: 100%;
            max-height: 200px;
            object-fit: cover;
            border-radius: 8px;
            margin-top: 10px;
        }
        .gradient-bg {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
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
                            <p class="text-sm text-gray-500 dark:text-gray-400">Create and manage your courses</p>
                        </div>
                    </div>
                    <div class="flex items-center space-x-3">
                        <?php include __DIR__ . '/../includes/teacher_header.php'; ?>
                    </div>
                </div>
            </div>
        </nav>

        <div class="p-6 lg:p-8">
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
            <div class="bg-white dark:bg-gray-800 rounded-3xl shadow-2xl p-8 mb-8 fade-in">
                <h2 class="text-3xl font-extrabold text-gray-800 dark:text-white mb-6">
                <i class="fas fa-<?php echo $editingCourse ? 'edit' : 'plus-circle'; ?> mr-2"></i>
                <?php echo $editingCourse ? 'Edit Course' : 'Create New Course'; ?>
            </h2>
            <form method="POST" enctype="multipart/form-data" class="space-y-6" id="courseForm">
                <input type="hidden" name="action" value="<?php echo $editingCourse ? 'edit' : 'add'; ?>">
                <?php if ($editingCourse): ?>
                    <input type="hidden" name="course_id" value="<?php echo $editingCourse['id']; ?>">
                <?php endif; ?>
                
                <!-- Basic Information Section -->
                <div class="bg-gradient-to-r from-blue-50 to-blue-100 dark:from-blue-900/20 dark:to-blue-900/30 p-6 rounded-xl border-2 border-blue-200 dark:border-blue-800 mb-6">
                    <h3 class="text-xl font-bold text-blue-800 dark:text-blue-300 mb-4 flex items-center">
                        <i class="fas fa-info-circle mr-2"></i>Basic Information
                    </h3>
                    
                    <div class="grid md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-heading mr-1"></i>Course Title *
                            </label>
                            <input type="text" name="title" required placeholder="Course Title" 
                                   value="<?php echo htmlspecialchars($editingCourse['title'] ?? ''); ?>"
                                   class="w-full px-4 py-2 border-2 border-blue-200 dark:border-gray-700 rounded-lg focus:border-blue-500 focus:outline-none dark:bg-gray-700 dark:text-white"
                                   oninput="updateSlugPreview(this.value)">
                            <p class="text-xs text-gray-500 mt-1">Slug will be auto-generated: <span id="slugPreview" class="font-mono text-blue-600"></span></p>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-folder mr-1"></i>Category
                            </label>
                            <select name="category_id" 
                                    class="w-full px-4 py-2 border-2 border-blue-200 dark:border-gray-700 rounded-lg focus:border-blue-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                                <option value="">Select Category (Optional)</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat['id']; ?>" 
                                            <?php echo ($editingCourse && $editingCourse['category_id'] == $cat['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mt-4">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">
                            <i class="fas fa-align-left mr-1"></i>Description
                        </label>
                        <textarea name="description" placeholder="Course Description" rows="4" 
                                  class="w-full px-4 py-2 border-2 border-blue-200 rounded-lg focus:border-blue-500 focus:outline-none"><?php echo htmlspecialchars($editingCourse['description'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="grid md:grid-cols-2 gap-4 mt-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-signal mr-1"></i>Level
                            </label>
                            <select name="level" 
                                    class="w-full px-4 py-2 border-2 border-blue-200 dark:border-gray-700 rounded-lg focus:border-blue-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                                <option value="beginner" <?php echo ($editingCourse && ($editingCourse['level'] ?? 'beginner') === 'beginner') ? 'selected' : ''; ?>>Beginner</option>
                                <option value="intermediate" <?php echo ($editingCourse && ($editingCourse['level'] ?? 'beginner') === 'intermediate') ? 'selected' : ''; ?>>Intermediate</option>
                                <option value="advanced" <?php echo ($editingCourse && ($editingCourse['level'] ?? 'beginner') === 'advanced') ? 'selected' : ''; ?>>Advanced</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-language mr-1"></i>Language
                            </label>
                            <input type="text" name="language" value="<?php echo htmlspecialchars($editingCourse['language'] ?? 'English'); ?>" 
                                   placeholder="English" 
                                   class="w-full px-4 py-2 border-2 border-blue-200 dark:border-gray-700 rounded-lg focus:border-blue-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                        </div>
                    </div>
                </div>
                
                <!-- Pricing Section -->
                <div class="bg-gradient-to-r from-green-50 to-green-100 dark:from-green-900/20 dark:to-green-900/30 p-6 rounded-xl border-2 border-green-200 dark:border-green-800 mb-6">
                    <h3 class="text-lg font-bold text-green-800 mb-4 flex items-center">
                        <i class="fas fa-dollar-sign mr-2"></i>Pricing
                    </h3>
                    
                    <div class="grid md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-tag mr-1"></i>Price ($)
                            </label>
                            <input type="number" name="price" step="0.01" min="0" value="<?php echo $editingCourse['price'] ?? 0; ?>" placeholder="0.00" 
                                   class="w-full px-4 py-2 border-2 border-green-200 rounded-lg focus:border-green-500 focus:outline-none">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-percent mr-1"></i>Discount Price ($)
                            </label>
                            <input type="number" name="discount_price" step="0.01" min="0" value="<?php echo $editingCourse['discount_price'] ?? ''; ?>" placeholder="0.00 (Optional)" 
                                   class="w-full px-4 py-2 border-2 border-green-200 rounded-lg focus:border-green-500 focus:outline-none">
                        </div>
                        
                        <div class="flex items-end">
                            <label class="flex items-center space-x-2 cursor-pointer">
                                <input type="checkbox" name="is_free" value="1" 
                                       <?php echo ($editingCourse && ($editingCourse['is_free'] ?? 0) == 1) ? 'checked' : ''; ?>
                                       class="w-5 h-5 text-green-600 rounded focus:ring-green-500"
                                       onchange="toggleFreeCourse(this)">
                                <span class="text-sm font-semibold text-gray-700">
                                    <i class="fas fa-gift mr-1"></i>Free Course
                                </span>
                            </label>
                        </div>
                    </div>
                </div>
                
                <!-- Course Details Section -->
                <div class="bg-gradient-to-r from-purple-50 to-purple-100 dark:from-purple-900/20 dark:to-purple-900/30 p-6 rounded-xl border-2 border-purple-200 dark:border-purple-800 mb-6">
                    <h3 class="text-xl font-bold text-purple-800 dark:text-purple-300 mb-4 flex items-center">
                        <i class="fas fa-cog mr-2"></i>Course Details
                    </h3>
                    
                    <div class="grid md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-clock mr-1"></i>Duration (hours)
                            </label>
                            <input type="number" name="duration" min="0" value="<?php echo $editingCourse['duration'] ?? 0; ?>" placeholder="0" 
                                   class="w-full px-4 py-2 border-2 border-purple-200 rounded-lg focus:border-purple-500 focus:outline-none">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-calendar-alt mr-1"></i>Access Days
                            </label>
                            <input type="number" name="access_days" min="0" value="<?php echo $editingCourse['access_days'] ?? 0; ?>" placeholder="0 = Lifetime" 
                                   class="w-full px-4 py-2 border-2 border-purple-200 rounded-lg focus:border-purple-500 focus:outline-none">
                            <p class="text-xs text-gray-500 mt-1">0 = Lifetime access</p>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-users mr-1"></i>Max Students
                            </label>
                            <input type="number" name="max_students" min="0" value="<?php echo $editingCourse['max_students'] ?? ''; ?>" placeholder="Unlimited (leave empty)" 
                                   class="w-full px-4 py-2 border-2 border-purple-200 rounded-lg focus:border-purple-500 focus:outline-none">
                            <p class="text-xs text-gray-500 mt-1">Leave empty for unlimited</p>
                        </div>
                    </div>
                    
                    <div class="mt-4">
                        <label class="flex items-center space-x-2 cursor-pointer">
                            <input type="checkbox" name="has_certificate" value="1" 
                                   <?php echo ($editingCourse && ($editingCourse['has_certificate'] ?? 0) == 1) ? 'checked' : ''; ?>
                                   class="w-5 h-5 text-purple-600 rounded focus:ring-purple-500">
                            <span class="text-sm font-semibold text-gray-700">
                                <i class="fas fa-certificate mr-1"></i>Issue Certificate upon Completion
                            </span>
                        </label>
                    </div>
                </div>
                
                <!-- Media Section -->
                <div class="bg-gradient-to-r from-yellow-50 to-yellow-100 dark:from-yellow-900/20 dark:to-yellow-900/30 p-6 rounded-xl border-2 border-yellow-200 dark:border-yellow-800 mb-6">
                    <h3 class="text-lg font-bold text-yellow-800 mb-4 flex items-center">
                        <i class="fas fa-images mr-2"></i>Media Files
                    </h3>
                    
                    <div class="grid md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-image mr-1"></i>Thumbnail Image
                                <span class="text-xs text-gray-500 font-normal">(Auto-generated if not provided)</span>
                            </label>
                            <input type="file" name="thumbnail" accept="image/jpeg,image/png,image/gif,image/webp" 
                                   id="thumbnailInput"
                                   class="w-full px-4 py-2 border-2 border-yellow-200 rounded-lg focus:border-yellow-500 focus:outline-none"
                                   onchange="previewThumbnail(this)">
                            <p class="text-xs text-gray-500 mt-1">JPG, PNG, GIF, WEBP (max 5MB)</p>
                            <div id="thumbnailPreview" class="mt-2">
                                <?php if ($editingCourse && !empty($editingCourse['thumbnail'])): 
                                    $currentThumbPath = '/Iqra-College/uploads/courses/' . htmlspecialchars($editingCourse['thumbnail']);
                                    $currentThumbFullPath = __DIR__ . '/../uploads/courses/' . $editingCourse['thumbnail'];
                                    if (file_exists($currentThumbFullPath)):
                                ?>
                                    <img src="<?php echo $currentThumbPath; ?>" alt="Current thumbnail" class="thumbnail-preview border-2 border-yellow-300 rounded-lg mt-2">
                                <?php endif; endif; ?>
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-image mr-1"></i>Banner Image
                            </label>
                            <input type="file" name="banner_image" accept="image/jpeg,image/png,image/gif,image/webp" 
                                   id="bannerInput"
                                   class="w-full px-4 py-2 border-2 border-yellow-200 rounded-lg focus:border-yellow-500 focus:outline-none"
                                   onchange="previewBanner(this)">
                            <p class="text-xs text-gray-500 mt-1">JPG, PNG, GIF, WEBP (max 10MB)</p>
                            <div id="bannerPreview" class="mt-2">
                                <?php if ($editingCourse && !empty($editingCourse['banner_image'])): 
                                    $currentBannerPath = '/Iqra-College/uploads/courses/' . htmlspecialchars($editingCourse['banner_image']);
                                    $currentBannerFullPath = __DIR__ . '/../uploads/courses/' . $editingCourse['banner_image'];
                                    if (file_exists($currentBannerFullPath)):
                                ?>
                                    <img src="<?php echo $currentBannerPath; ?>" alt="Current banner" class="thumbnail-preview border-2 border-yellow-300 rounded-lg mt-2">
                                <?php endif; endif; ?>
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-video mr-1"></i>Preview Video
                            </label>
                            <input type="file" name="preview_video" accept="video/mp4,video/webm,video/ogg,video/quicktime" 
                                   id="videoInput"
                                   class="w-full px-4 py-2 border-2 border-yellow-200 rounded-lg focus:border-yellow-500 focus:outline-none"
                                   onchange="previewVideo(this)">
                            <p class="text-xs text-gray-500 mt-1">MP4, WEBM, OGG, MOV (max 50MB)</p>
                            <div id="videoPreview" class="mt-2">
                                <?php if ($editingCourse && !empty($editingCourse['preview_video'])): 
                                    $currentVideoPath = '/Iqra-College/uploads/courses/' . htmlspecialchars($editingCourse['preview_video']);
                                    $currentVideoFullPath = __DIR__ . '/../uploads/courses/' . $editingCourse['preview_video'];
                                    if (file_exists($currentVideoFullPath)):
                                ?>
                                    <video src="<?php echo $currentVideoPath; ?>" controls class="w-full h-32 rounded-lg border-2 border-yellow-300 mt-2"></video>
                                <?php endif; endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- SEO Section -->
                <div class="bg-gradient-to-r from-indigo-50 to-indigo-100 dark:from-indigo-900/20 dark:to-indigo-900/30 p-6 rounded-xl border-2 border-indigo-200 dark:border-indigo-800 mb-6">
                    <h3 class="text-lg font-bold text-indigo-800 mb-4 flex items-center">
                        <i class="fas fa-search mr-2"></i>SEO Settings (Optional)
                    </h3>
                    
                    <div class="grid md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-heading mr-1"></i>Meta Title
                            </label>
                            <input type="text" name="meta_title" value="<?php echo htmlspecialchars($editingCourse['meta_title'] ?? ''); ?>" 
                                   placeholder="SEO Title (Optional)" 
                                   class="w-full px-4 py-2 border-2 border-indigo-200 rounded-lg focus:border-indigo-500 focus:outline-none">
                            <p class="text-xs text-gray-500 mt-1">For search engines</p>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-align-left mr-1"></i>Meta Description
                            </label>
                            <textarea name="meta_description" rows="2" placeholder="SEO Description (Optional)" 
                                      class="w-full px-4 py-2 border-2 border-indigo-200 rounded-lg focus:border-indigo-500 focus:outline-none"><?php echo htmlspecialchars($editingCourse['meta_description'] ?? ''); ?></textarea>
                            <p class="text-xs text-gray-500 mt-1">For search engines</p>
                        </div>
                    </div>
                </div>
                
                <!-- Status Section -->
                <div class="bg-gray-50 p-4 rounded-lg border-2 border-gray-200">
                    <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
                        <i class="fas fa-toggle-on mr-2"></i>Publish Settings
                    </h3>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">
                            <i class="fas fa-eye mr-1"></i>Status
                        </label>
                        <select name="status" 
                                class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:border-gray-500 focus:outline-none">
                            <option value="draft" <?php echo ($editingCourse && ($editingCourse['status'] ?? 'draft') === 'draft') ? 'selected' : ''; ?>>Draft</option>
                            <option value="published" <?php echo ($editingCourse && ($editingCourse['status'] ?? 'draft') === 'published') ? 'selected' : ''; ?>>Published</option>
                            <option value="archived" <?php echo ($editingCourse && ($editingCourse['status'] ?? 'draft') === 'archived') ? 'selected' : ''; ?>>Archived</option>
                        </select>
                    </div>
                </div>
                
                <!-- Submit Buttons -->
                <div class="flex space-x-3 pt-4 border-t">
                    <button type="submit" 
                            class="bg-blue-500 text-white px-8 py-3 rounded-lg font-semibold hover:bg-blue-600 transition-colors shadow-lg">
                        <i class="fas fa-<?php echo $editingCourse ? 'save' : 'plus-circle'; ?> mr-2"></i>
                        <?php echo $editingCourse ? 'Update Course' : 'Create Course'; ?>
                    </button>
                    <?php if ($editingCourse): ?>
                        <a href="/Iqra-College/teacher/courses.php" 
                           class="bg-gray-500 text-white px-6 py-3 rounded-lg font-semibold hover:bg-gray-600 transition-colors">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Courses List -->
        <div class="mb-4">
            <h2 class="text-2xl font-bold text-gray-800 mb-2">
                <i class="fas fa-list mr-2"></i>All Courses (<?php echo count($courses); ?>)
            </h2>
            <p class="text-gray-600">All courses automatically have thumbnails - either uploaded or auto-generated</p>
        </div>
        
        <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php if (empty($courses)): ?>
                <div class="col-span-full text-center py-12 bg-white dark:bg-gray-800 rounded-2xl shadow-lg">
                    <i class="fas fa-book-open text-6xl text-gray-400 dark:text-gray-600 mb-4"></i>
                    <p class="text-xl text-gray-500 dark:text-gray-400">No courses created yet. Create your first course above!</p>
                </div>
            <?php else: ?>
                <?php foreach ($courses as $course): 
                    $thumbnailPath = null;
                    $isUploadedThumbnail = false;
                    
                    // Check if course has a thumbnail in database
                    if (!empty($course['thumbnail'])) {
                        $thumbnailFilename = $course['thumbnail'];
                        $fullPath = __DIR__ . '/../uploads/courses/' . $thumbnailFilename;
                        
                        // Check if it's an uploaded thumbnail (not auto-generated)
                        // Uploaded thumbnails don't start with 'auto_'
                        $isUploadedThumbnail = (strpos($thumbnailFilename, 'auto_') !== 0);
                        
                        // Verify file exists on disk
                        if (file_exists($fullPath)) {
                            $thumbnailPath = '/Iqra-College/uploads/courses/' . $thumbnailFilename;
                        } elseif ($isUploadedThumbnail) {
                            // Uploaded thumbnail file is missing - log but still try to show
                            error_log("Uploaded thumbnail file missing: " . $fullPath);
                            // Still set path in case file was moved or will be regenerated
                            $thumbnailPath = '/Iqra-College/uploads/courses/' . $thumbnailFilename;
                        }
                    }
                    
                    // Only auto-generate if no thumbnail exists in database AND no uploaded thumbnail
                    // Don't overwrite uploaded thumbnails
                    if (!$thumbnailPath && empty($course['thumbnail']) && !empty($course['title'])) {
                        try {
                            $categoryName = $course['category_name'] ?? null;
                            $level = $course['level'] ?? 'beginner';
                            $newThumbnail = generateAutoThumbnail($course['title'], $categoryName, $level, $course['id']);
                            
                            // Update database if generation succeeded
                            if ($newThumbnail) {
                                try {
                                    $stmt = $pdo->prepare("UPDATE courses SET thumbnail = ? WHERE id = ?");
                                    $stmt->execute([$newThumbnail, $course['id']]);
                                    $thumbnailPath = '/Iqra-College/uploads/courses/' . $newThumbnail;
                                } catch (PDOException $e) {
                                    // Continue without updating - thumbnail generation failed
                                    error_log("Failed to update thumbnail in database: " . $e->getMessage());
                                }
                            }
                        } catch (Exception $e) {
                            // Continue without thumbnail - show default gradient
                            error_log("Thumbnail generation error: " . $e->getMessage());
                        }
                    }
                ?>
                    <div class="bg-white dark:bg-gray-800 rounded-3xl shadow-xl overflow-hidden hover:shadow-2xl transition-all border-2 border-gray-200 dark:border-gray-700 hover:border-primary-400 dark:hover:border-primary-600">
                        <div class="relative">
                            <?php if ($thumbnailPath): ?>
                                <img src="<?php echo htmlspecialchars($thumbnailPath); ?>" 
                                     alt="<?php echo htmlspecialchars($course['title']); ?>" 
                                     class="w-full h-48 object-cover"
                                     onerror="this.onerror=null; this.parentElement.innerHTML='<div class=\'w-full h-48 bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center\'><span class=\'text-white text-4xl font-bold\'>' + '<?php echo strtoupper(substr($course['title'], 0, 1)); ?>' + '</span></div>';">
                                <?php if ($isUploadedThumbnail): ?>
                                    <div class="absolute top-3 right-3 bg-gradient-to-br from-green-500 to-emerald-600 text-white px-3 py-1 rounded-full text-xs font-bold shadow-lg flex items-center">
                                        <i class="fas fa-image mr-1"></i>Uploaded
                                    </div>
                                <?php else: ?>
                                    <div class="absolute top-3 right-3 bg-gradient-to-br from-blue-500 to-blue-600 text-white px-3 py-1 rounded-full text-xs font-bold shadow-lg flex items-center">
                                        <i class="fas fa-magic mr-1"></i>Auto
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="w-full h-48 bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center">
                                    <span class="text-white text-4xl font-bold"><?php echo strtoupper(substr($course['title'], 0, 1)); ?></span>
                                </div>
                                <div class="absolute top-3 right-3 bg-gray-500 text-white px-3 py-1 rounded-full text-xs font-bold shadow-lg flex items-center">
                                    <i class="fas fa-image mr-1"></i>No Image
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="p-6">
                            <h3 class="text-xl font-bold text-gray-800 dark:text-white mb-3"><?php echo htmlspecialchars($course['title']); ?></h3>
                            
                            <div class="space-y-2 mb-4">
                                <?php if ($course['category_name']): ?>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">
                                        <i class="fas fa-folder text-blue-500 mr-1"></i>
                                        <span class="font-semibold">Category:</span> <?php echo htmlspecialchars($course['category_name']); ?>
                                    </p>
                                <?php endif; ?>
                                
                                <p class="text-sm text-gray-600 dark:text-gray-400">
                                    <i class="fas fa-signal text-purple-500 mr-1"></i>
                                    <span class="font-semibold">Level:</span> 
                                    <span class="capitalize"><?php echo htmlspecialchars($course['level'] ?? 'beginner'); ?></span>
                                </p>
                                
                                <?php if (!empty($course['duration']) && $course['duration'] > 0): ?>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">
                                        <i class="fas fa-clock text-green-500 mr-1"></i>
                                        <span class="font-semibold">Duration:</span> <?php echo $course['duration']; ?> hours
                                    </p>
                                <?php endif; ?>
                                
                                <p class="text-sm text-gray-600 dark:text-gray-400">
                                    <i class="fas fa-dollar-sign text-yellow-500 mr-1"></i>
                                    <span class="font-semibold">Price:</span> 
                                    $<?php echo number_format($course['price'] ?? 0, 2); ?>
                                </p>
                                
                                <p class="text-sm">
                                    <span class="inline-block px-3 py-1 rounded-full text-xs font-bold 
                                        <?php 
                                        $status = $course['status'] ?? 'draft';
                                        echo $status === 'published' ? 'bg-green-100 dark:bg-green-900/20 text-green-700 dark:text-green-400' : 
                                            ($status === 'draft' ? 'bg-yellow-100 dark:bg-yellow-900/20 text-yellow-700 dark:text-yellow-400' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300');
                                        ?>">
                                        <i class="fas fa-circle text-xs mr-1"></i>
                                        <?php echo ucfirst($status); ?>
                                    </span>
                                </p>
                            </div>
                            
                            <?php if ($course['description']): ?>
                                <p class="text-gray-600 dark:text-gray-400 text-sm mb-4 line-clamp-2"><?php echo htmlspecialchars($course['description']); ?></p>
                            <?php endif; ?>
                            
                            <div class="flex justify-between items-center pt-4 border-t border-gray-200 dark:border-gray-700">
                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                    <i class="fas fa-calendar mr-1"></i>
                                    <?php echo date('M d, Y', strtotime($course['created_at'] ?? 'now')); ?>
                                </span>
                                <div class="flex space-x-2">
                                    <a href="/Iqra-College/teacher/courses.php?edit=<?php echo $course['id']; ?>" 
                                       class="bg-gradient-to-r from-blue-500 to-blue-600 text-white px-4 py-2 rounded-lg text-sm font-semibold hover:from-blue-600 hover:to-blue-700 transition-all shadow-lg">
                                        <i class="fas fa-edit mr-1"></i>Edit
                                    </a>
                                    <form method="POST" class="inline" onsubmit="return confirmDeleteCourse('<?php echo htmlspecialchars(addslashes($course['title'])); ?>', <?php echo $course['id']; ?>)">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $course['id']; ?>">
                                        <button type="submit" 
                                                class="bg-gradient-to-r from-red-500 to-red-600 text-white px-4 py-2 rounded-lg text-sm font-semibold hover:from-red-600 hover:to-red-700 transition-all shadow-lg">
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
            const priceInput = document.querySelector('input[name="price"]');
            const discountInput = document.querySelector('input[name="discount_price"]');
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
                    alert('File size exceeds 5MB limit. Please choose a smaller image.');
                    input.value = '';
                    preview.innerHTML = '';
                    return;
                }
                
                const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
                if (!allowedTypes.includes(file.type)) {
                    alert('Invalid file type. Please upload JPG, PNG, GIF, or WEBP image.');
                    input.value = '';
                    preview.innerHTML = '';
                    return;
                }
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.innerHTML = `
                        <div class="bg-green-50 p-2 rounded-lg border-2 border-green-300">
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
                        <div class="bg-green-50 p-2 rounded-lg border-2 border-green-300">
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
                    <div class="bg-green-50 p-2 rounded-lg border-2 border-green-300">
                        <p class="text-xs font-semibold text-green-700 mb-1">New Video (${fileSize} MB)</p>
                        <video src="${url}" controls class="w-full h-32 rounded-lg border-2 border-green-400"></video>
                    </div>
                `;
            }
        }
        
        // Confirm course deletion with detailed warning
        function confirmDeleteCourse(courseTitle, courseId) {
            const message = `⚠️ WARNING: This action cannot be undone!\n\n` +
                          `You are about to permanently delete:\n` +
                          `• Course: "${courseTitle}"\n` +
                          `• All course files (thumbnail, banner, preview video)\n` +
                          `• All lessons and lesson files\n` +
                          `• All sections\n` +
                          `• All materials\n` +
                          `• All student enrollments\n\n` +
                          `This will completely remove the course from the database and all associated files.\n\n` +
                          `Are you absolutely sure you want to delete this course?`;
            
            return confirm(message);
        }
        
        // Initialize slug preview on page load
        document.addEventListener('DOMContentLoaded', function() {
            const titleInput = document.querySelector('input[name="title"]');
            if (titleInput) {
                updateSlugPreview(titleInput.value);
            }
            
            // Initialize free course toggle
            const freeCheckbox = document.querySelector('input[name="is_free"]');
            if (freeCheckbox) {
                toggleFreeCourse(freeCheckbox);
            }
        });
        
        // Mobile menu toggle
        document.getElementById('mobile-menu-toggle')?.addEventListener('click', function() {
            const mobileSidebar = document.getElementById('mobile-sidebar');
            const sidebarOverlay = document.getElementById('sidebar-overlay');
            if (mobileSidebar && sidebarOverlay) {
                mobileSidebar.classList.remove('-translate-x-full');
                sidebarOverlay.classList.remove('hidden');
            }
        });
    </script>
        </div>
    </div>
</body>
</html>
