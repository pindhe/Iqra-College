<?php
/**
 * Teacher - List Courses
 * Page for viewing and managing all courses
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
$pageTitle = 'List Courses';
$currentPage = 'list_courses';
ensureQuizSchema($pdo);

// Handle file uploads for lessons
function uploadFile($file, $uploadDir, $allowedTypes) {
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $fileName = $file['name'];
    $fileTmp = $file['tmp_name'];
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedTypes, true)) {
        return false;
    }
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }
    $uniqueName = uniqid() . '_' . time() . '.' . $ext;
    $uploadPath = $uploadDir . '/' . $uniqueName;
    if (move_uploaded_file($fileTmp, $uploadPath)) {
        return $uniqueName;
    }
    return null;
}

function ensureQuizSchema($pdo) {
    $columns = [];
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM quizzes");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    } catch (PDOException $e) {
        return;
    }
    $quizAdds = [];
    if (!in_array('section_id', $columns, true)) {
        $quizAdds[] = "ADD COLUMN section_id INT NULL";
    }
    if (!in_array('lesson_id', $columns, true)) {
        $quizAdds[] = "ADD COLUMN lesson_id INT NULL";
    }
    if (!in_array('total_marks', $columns, true)) {
        $quizAdds[] = "ADD COLUMN total_marks INT DEFAULT 0";
    }
    if (!empty($quizAdds)) {
        try {
            $pdo->exec("ALTER TABLE quizzes " . implode(', ', $quizAdds));
        } catch (PDOException $e) {
            // Ignore if permissions or db doesn't allow
        }
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM questions");
        $qColumns = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    } catch (PDOException $e) {
        return;
    }
    $questionAdds = [];
    if (!in_array('question_type', $qColumns, true)) {
        $questionAdds[] = "ADD COLUMN question_type VARCHAR(20) DEFAULT 'single'";
    }
    if (!in_array('options_json', $qColumns, true)) {
        $questionAdds[] = "ADD COLUMN options_json TEXT NULL";
    }
    if (!in_array('correct_answers', $qColumns, true)) {
        $questionAdds[] = "ADD COLUMN correct_answers TEXT NULL";
    }
    if (!empty($questionAdds)) {
        try {
            $pdo->exec("ALTER TABLE questions " . implode(', ', $questionAdds));
        } catch (PDOException $e) {
            // Ignore
        }
    }
}

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

// Handle section/lesson actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Handle sample course generation
    if ($action === 'generate_samples') {
        // First check if courses table exists and has required columns
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'courses'");
            if ($stmt->rowCount() === 0) {
                $error = 'Courses table does not exist. Please run the database setup script first.';
            } else {
                // Check for required columns
                $stmt = $pdo->query("SHOW COLUMNS FROM courses LIKE 'title'");
                if ($stmt->rowCount() === 0) {
                    $error = 'Courses table is missing required columns. Please check your database schema.';
                } else {
                    $result = autoGenerateSampleCourses($pdo, $teacherId);
                    if ($result['success']) {
                        $success = $result['message'];
                        // Redirect to refresh the page and show new courses
                        header('Location: /Iqra-College/teacher/list_courses.php?success=' . urlencode($success));
                        exit;
                    } else {
                        $error = $result['message'] ?? 'Failed to generate sample courses. You may already have courses, or there was an error.';
                    }
                }
            }
        } catch (PDOException $e) {
            $error = 'Database error: ' . htmlspecialchars($e->getMessage()) . '. Please check your database connection and permissions.';
        }
    } elseif ($action === 'add_section' || $action === 'update_section') {
        $courseId = intval($_POST['course_id'] ?? 0);
        $sectionId = intval($_POST['section_id'] ?? 0);
        $title = sanitize($_POST['section_title'] ?? '');
        $order = intval($_POST['order_number'] ?? 0);
        if ($courseId <= 0 || $title === '') {
            $error = 'Please provide a section title.';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id FROM courses WHERE id = ? AND teacher_id = ?");
                $stmt->execute([$courseId, $teacherId]);
                if (!$stmt->fetch()) {
                    $error = 'Invalid course.';
                } else {
                    if ($action === 'add_section') {
                        $stmt = $pdo->prepare("INSERT INTO sections (course_id, title, order_number) VALUES (?, ?, ?)");
                        $stmt->execute([$courseId, $title, $order]);
                        $success = 'Section added successfully.';
                    } else {
                        $stmt = $pdo->prepare("UPDATE sections s JOIN courses c ON s.course_id = c.id SET s.title = ?, s.order_number = ? WHERE s.id = ? AND c.teacher_id = ?");
                        $stmt->execute([$title, $order, $sectionId, $teacherId]);
                        $success = 'Section updated successfully.';
                    }
                }
            } catch (PDOException $e) {
                $error = 'Failed to save section.';
            }
        }
    } elseif ($action === 'delete_section') {
        $sectionId = intval($_POST['section_id'] ?? 0);
        if ($sectionId > 0) {
            try {
                $stmt = $pdo->prepare("DELETE s FROM sections s JOIN courses c ON s.course_id = c.id WHERE s.id = ? AND c.teacher_id = ?");
                $stmt->execute([$sectionId, $teacherId]);
                $success = 'Section deleted successfully.';
            } catch (PDOException $e) {
                $error = 'Failed to delete section.';
            }
        }
    } elseif (in_array($action, ['add_lesson', 'update_lesson', 'delete_lesson'], true)) {
        $courseId = intval($_POST['course_id'] ?? 0);
        $lessonId = intval($_POST['lesson_id'] ?? 0);
        if ($action === 'delete_lesson') {
            if ($lessonId > 0) {
                try {
                    $stmt = $pdo->prepare("DELETE l FROM lessons l JOIN courses c ON l.course_id = c.id WHERE l.id = ? AND c.teacher_id = ?");
                    $stmt->execute([$lessonId, $teacherId]);
                    $success = 'Lesson deleted successfully.';
                } catch (PDOException $e) {
                    $error = 'Failed to delete lesson.';
                }
            }
        } else {
            $sectionId = intval($_POST['section_id'] ?? 0);
            $title = sanitize($_POST['title'] ?? '');
            $content = sanitize($_POST['content'] ?? '');
            $lessonType = sanitize($_POST['lesson_type'] ?? 'Grammar');
            $mediaType = sanitize($_POST['media_type'] ?? 'text');
            $order = intval($_POST['order_number'] ?? 0);
            if ($courseId <= 0 || $sectionId <= 0 || $title === '') {
                $error = 'Please select a section and enter a lesson title.';
            } else {
                try {
                    $stmt = $pdo->prepare("SELECT id FROM courses WHERE id = ? AND teacher_id = ?");
                    $stmt->execute([$courseId, $teacherId]);
                    if (!$stmt->fetch()) {
                        $error = 'Invalid course.';
                    } else {
                        $videoFile = null;
                        $audioFile = null;
                        $documentFile = null;
                        if ($mediaType === 'video' && isset($_FILES['video_file']) && $_FILES['video_file']['error'] === UPLOAD_ERR_OK) {
                            $videoFile = uploadFile($_FILES['video_file'], __DIR__ . '/../uploads/videos', ['mp4', 'avi', 'mov', 'wmv', 'flv']);
                            if ($videoFile === false) $error = 'Invalid video file type';
                        }
                        if ($mediaType === 'audio' && isset($_FILES['audio_file']) && $_FILES['audio_file']['error'] === UPLOAD_ERR_OK) {
                            $audioFile = uploadFile($_FILES['audio_file'], __DIR__ . '/../uploads/audio', ['mp3', 'wav', 'ogg', 'm4a']);
                            if ($audioFile === false) $error = 'Invalid audio file type';
                        }
                        if ($mediaType === 'document' && isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
                            $documentFile = uploadFile($_FILES['document_file'], __DIR__ . '/../uploads/materials', ['pdf', 'doc', 'docx', 'txt']);
                            if ($documentFile === false) $error = 'Invalid document file type';
                        }
                        if (empty($error)) {
                            if ($action === 'add_lesson') {
                                $stmt = $pdo->prepare("INSERT INTO lessons (course_id, section_id, title, content, lesson_type, media_type, video_file, audio_file, document_file, order_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                                $stmt->execute([$courseId, $sectionId, $title, $content, $lessonType, $mediaType, $videoFile, $audioFile, $documentFile, $order]);
                                $success = 'Lesson added successfully.';
                            } else {
                                $stmt = $pdo->prepare("UPDATE lessons l JOIN courses c ON l.course_id = c.id SET l.section_id = ?, l.title = ?, l.content = ?, l.lesson_type = ?, l.media_type = ?, l.video_file = COALESCE(?, l.video_file), l.audio_file = COALESCE(?, l.audio_file), l.document_file = COALESCE(?, l.document_file), l.order_number = ? WHERE l.id = ? AND c.teacher_id = ?");
                                $stmt->execute([$sectionId, $title, $content, $lessonType, $mediaType, $videoFile, $audioFile, $documentFile, $order, $lessonId, $teacherId]);
                                $success = 'Lesson updated successfully.';
                            }
                        }
                    }
                } catch (PDOException $e) {
                    $error = 'Failed to save lesson.';
                }
            }
        }
    } elseif (in_array($action, ['add_quiz', 'update_quiz', 'delete_quiz', 'add_question', 'update_question', 'delete_question'], true)) {
        if ($action === 'delete_quiz') {
            $quizId = intval($_POST['quiz_id'] ?? 0);
            if ($quizId > 0) {
                try {
                    $stmt = $pdo->prepare("DELETE q FROM quizzes q JOIN courses c ON q.course_id = c.id WHERE q.id = ? AND c.teacher_id = ?");
                    $stmt->execute([$quizId, $teacherId]);
                    $success = 'Quiz deleted successfully.';
                } catch (PDOException $e) {
                    $error = 'Failed to delete quiz.';
                }
            }
        } elseif ($action === 'add_quiz' || $action === 'update_quiz') {
            $courseId = intval($_POST['course_id'] ?? 0);
            $sectionId = intval($_POST['section_id'] ?? 0);
            $lessonId = intval($_POST['lesson_id'] ?? 0);
            $lessonId = $lessonId > 0 ? $lessonId : null;
            $title = sanitize($_POST['quiz_title'] ?? '');
            $description = sanitize($_POST['quiz_description'] ?? '');
            $duration = intval($_POST['duration'] ?? 0);
            $totalMarks = intval($_POST['total_marks'] ?? 0);
            $passingScore = intval($_POST['passing_score'] ?? 60);
            $status = $_POST['status'] ?? 'draft';
            $isPublished = $status === 'published' ? 1 : 0;
            $quizId = intval($_POST['quiz_id'] ?? 0);

            if ($courseId <= 0 || $sectionId <= 0 || $title === '') {
                $error = 'Select a section to continue.';
            } else {
                try {
                    $stmt = $pdo->prepare("SELECT id FROM courses WHERE id = ? AND teacher_id = ?");
                    $stmt->execute([$courseId, $teacherId]);
                    if (!$stmt->fetch()) {
                        $error = 'Invalid course.';
                    } else {
                        if ($action === 'add_quiz') {
                            try {
                                $stmt = $pdo->prepare("INSERT INTO quizzes (course_id, section_id, lesson_id, title, description, duration, total_marks, passing_score, is_published) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                                $stmt->execute([$courseId, $sectionId, $lessonId, $title, $description, $duration, $totalMarks, $passingScore, $isPublished]);
                            } catch (PDOException $e) {
                                try {
                                    $stmt = $pdo->prepare("INSERT INTO quizzes (course_id, title, description, duration, passing_score, is_published) VALUES (?, ?, ?, ?, ?, ?)");
                                    $stmt->execute([$courseId, $title, $description, $duration, $passingScore, $isPublished]);
                                } catch (PDOException $e2) {
                                    try {
                                        $stmt = $pdo->prepare("INSERT INTO quizzes (course_id, title, description, duration, passing_score) VALUES (?, ?, ?, ?, ?)");
                                        $stmt->execute([$courseId, $title, $description, $duration, $passingScore]);
                                    } catch (PDOException $e3) {
                                        $stmt = $pdo->prepare("INSERT INTO quizzes (course_id, title, duration, passing_score) VALUES (?, ?, ?, ?)");
                                        $stmt->execute([$courseId, $title, $duration, $passingScore]);
                                    }
                                }
                            }
                            $success = 'Quiz created successfully.';
                        } else {
                            try {
                                $stmt = $pdo->prepare("UPDATE quizzes q JOIN courses c ON q.course_id = c.id SET q.section_id = ?, q.lesson_id = ?, q.title = ?, q.description = ?, q.duration = ?, q.total_marks = ?, q.passing_score = ?, q.is_published = ? WHERE q.id = ? AND c.teacher_id = ?");
                                $stmt->execute([$sectionId, $lessonId, $title, $description, $duration, $totalMarks, $passingScore, $isPublished, $quizId, $teacherId]);
                            } catch (PDOException $e) {
                                try {
                                    $stmt = $pdo->prepare("UPDATE quizzes q JOIN courses c ON q.course_id = c.id SET q.title = ?, q.description = ?, q.duration = ?, q.passing_score = ?, q.is_published = ? WHERE q.id = ? AND c.teacher_id = ?");
                                    $stmt->execute([$title, $description, $duration, $passingScore, $isPublished, $quizId, $teacherId]);
                                } catch (PDOException $e2) {
                                    try {
                                        $stmt = $pdo->prepare("UPDATE quizzes q JOIN courses c ON q.course_id = c.id SET q.title = ?, q.description = ?, q.duration = ?, q.passing_score = ? WHERE q.id = ? AND c.teacher_id = ?");
                                        $stmt->execute([$title, $description, $duration, $passingScore, $quizId, $teacherId]);
                                    } catch (PDOException $e3) {
                                        $stmt = $pdo->prepare("UPDATE quizzes q JOIN courses c ON q.course_id = c.id SET q.title = ?, q.duration = ?, q.passing_score = ? WHERE q.id = ? AND c.teacher_id = ?");
                                        $stmt->execute([$title, $duration, $passingScore, $quizId, $teacherId]);
                                    }
                                }
                            }
                            $success = 'Quiz updated successfully.';
                        }
                    }
                } catch (PDOException $e) {
                    $error = 'Failed to save quiz: ' . $e->getMessage();
                }
            }
        } else {
            $quizId = intval($_POST['quiz_id'] ?? 0);
            $questionId = intval($_POST['question_id'] ?? 0);
            $questionText = sanitize($_POST['question'] ?? '');
            $questionType = $_POST['question_type'] ?? 'single';
            $points = intval($_POST['points'] ?? 1);
            $explanation = sanitize($_POST['explanation'] ?? '');
            $order = intval($_POST['order_number'] ?? 0);
            $options = $_POST['options'] ?? [];
            $correctAnswers = $_POST['correct_answers'] ?? '';
            $correctAnswersArr = is_array($correctAnswers) ? $correctAnswers : [$correctAnswers];

            if ($action === 'delete_question') {
                if ($questionId > 0) {
                    try {
                        $stmt = $pdo->prepare("DELETE ques FROM questions ques JOIN quizzes q ON ques.quiz_id = q.id JOIN courses c ON q.course_id = c.id WHERE ques.id = ? AND c.teacher_id = ?");
                        $stmt->execute([$questionId, $teacherId]);
                        $success = 'Question deleted successfully.';
                    } catch (PDOException $e) {
                        $error = 'Failed to delete question.';
                    }
                }
            } else {
                if ($quizId <= 0 || $questionText === '') {
                    $error = 'Question text is required.';
                } else {
                    $optionsJson = !empty($options) ? json_encode(array_values($options)) : null;
                    $correctJson = !empty($correctAnswersArr) ? json_encode(array_values($correctAnswersArr)) : null;
                    $optionA = $options[0] ?? '';
                    $optionB = $options[1] ?? '';
                    $optionC = $options[2] ?? '';
                    $optionD = $options[3] ?? '';
                    $correctSingle = $correctAnswersArr[0] ?? 'a';

                    try {
                        if ($action === 'add_question') {
                            $stmt = $pdo->prepare("INSERT INTO questions (quiz_id, question, question_type, options_json, correct_answers, option_a, option_b, option_c, option_d, correct_answer, points, explanation, order_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            $stmt->execute([$quizId, $questionText, $questionType, $optionsJson, $correctJson, $optionA, $optionB, $optionC, $optionD, $correctSingle, $points, $explanation, $order]);
                            $success = 'Question added successfully.';
                        } else {
                            $stmt = $pdo->prepare("UPDATE questions ques JOIN quizzes q ON ques.quiz_id = q.id JOIN courses c ON q.course_id = c.id SET ques.question = ?, ques.question_type = ?, ques.options_json = ?, ques.correct_answers = ?, ques.option_a = ?, ques.option_b = ?, ques.option_c = ?, ques.option_d = ?, ques.correct_answer = ?, ques.points = ?, ques.explanation = ?, ques.order_number = ? WHERE ques.id = ? AND c.teacher_id = ?");
                            $stmt->execute([$questionText, $questionType, $optionsJson, $correctJson, $optionA, $optionB, $optionC, $optionD, $correctSingle, $points, $explanation, $order, $questionId, $teacherId]);
                            $success = 'Question updated successfully.';
                        }
                    } catch (PDOException $e) {
                        // Fallback to legacy schema
                        if ($action === 'add_question') {
                            $stmt = $pdo->prepare("INSERT INTO questions (quiz_id, question, option_a, option_b, option_c, option_d, correct_answer, points, explanation, order_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            $stmt->execute([$quizId, $questionText, $optionA, $optionB, $optionC, $optionD, $correctSingle, $points, $explanation, $order]);
                            $success = 'Question added successfully.';
                        } else {
                            $stmt = $pdo->prepare("UPDATE questions ques JOIN quizzes q ON ques.quiz_id = q.id JOIN courses c ON q.course_id = c.id SET ques.question = ?, ques.option_a = ?, ques.option_b = ?, ques.option_c = ?, ques.option_d = ?, ques.correct_answer = ?, ques.points = ?, ques.explanation = ?, ques.order_number = ? WHERE ques.id = ? AND c.teacher_id = ?");
                            $stmt->execute([$questionText, $optionA, $optionB, $optionC, $optionD, $correctSingle, $points, $explanation, $order, $questionId, $teacherId]);
                            $success = 'Question updated successfully.';
                        }
                    }
                }
            }
        }
    }
}

// Handle delete action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
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

// Check for success message from create_course.php redirect
if (isset($_GET['success'])) {
    $success = htmlspecialchars($_GET['success']);
}

/**
 * Auto-generate sample courses if no courses exist
 * Returns array with 'success' boolean and 'message' string
 */
function autoGenerateSampleCourses($pdo, $teacherId) {
    $errors = [];
    $insertedCount = 0;
    
    try {
        // Check if teacher already has courses
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM courses WHERE teacher_id = ?");
        $stmt->execute([$teacherId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (isset($result['count']) && intval($result['count']) > 0) {
            return ['success' => false, 'message' => 'You already have courses. Delete existing courses first if you want to regenerate samples.'];
        }
        
        // Get available categories
        try {
            $stmt = $pdo->query("SELECT id, name FROM categories ORDER BY id LIMIT 4");
            $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $categories = [];
            $errors[] = "Could not fetch categories: " . $e->getMessage();
        }
        
        if (empty($categories)) {
            // Create default categories if none exist
            $defaultCategories = [
                ['name' => 'English Grammar', 'slug' => 'english-grammar'],
                ['name' => 'Writing', 'slug' => 'writing'],
                ['name' => 'Reading', 'slug' => 'reading'],
                ['name' => 'Listening', 'slug' => 'listening']
            ];
            
            $createdCategories = 0;
            foreach ($defaultCategories as $cat) {
                try {
                    // Try with slug first
                    try {
                        $stmt = $pdo->prepare("INSERT INTO categories (name, slug) VALUES (?, ?)");
                        $stmt->execute([$cat['name'], $cat['slug']]);
                        $createdCategories++;
                    } catch (PDOException $e1) {
                        // Try without slug
                        try {
                            $stmt = $pdo->prepare("INSERT INTO categories (name) VALUES (?)");
                            $stmt->execute([$cat['name']]);
                            $createdCategories++;
                        } catch (PDOException $e2) {
                            // Category might already exist, try to get it
                            try {
                                $stmt = $pdo->prepare("SELECT id, name FROM categories WHERE name = ?");
                                $stmt->execute([$cat['name']]);
                                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                                if ($existing) {
                                    $createdCategories++;
                                }
                            } catch (PDOException $e3) {
                                $errors[] = "Could not create category '{$cat['name']}': " . $e3->getMessage();
                            }
                        }
                    }
                } catch (PDOException $e) {
                    $errors[] = "Error with category '{$cat['name']}': " . $e->getMessage();
                }
            }
            
            // Re-fetch categories
            try {
                $stmt = $pdo->query("SELECT id, name FROM categories ORDER BY id LIMIT 4");
                $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $categories = [];
                $errors[] = "Could not re-fetch categories: " . $e->getMessage();
            }
            
            if (empty($categories)) {
                return ['success' => false, 'message' => 'Failed to create or retrieve categories. Please create categories first in the admin panel.'];
            }
        }
        
        // Sample courses data
        $sampleCourses = [
            [
                'title' => 'Introduction to English Grammar',
                'description' => 'Learn the fundamentals of English grammar including parts of speech, sentence structure, and basic rules. Perfect for beginners who want to build a strong foundation.',
                'category_id' => $categories[0]['id'] ?? null,
                'level' => 'beginner',
                'price' => 0.00,
                'is_free' => 1,
                'duration' => 20,
                'status' => 'published',
                'language' => 'English'
            ],
            [
                'title' => 'Advanced Writing Techniques',
                'description' => 'Master advanced writing skills including essay writing, creative writing, and professional communication. Improve your writing style and clarity.',
                'category_id' => $categories[1]['id'] ?? null,
                'level' => 'advanced',
                'price' => 99.99,
                'is_free' => 0,
                'duration' => 40,
                'status' => 'published',
                'language' => 'English'
            ],
            [
                'title' => 'Reading Comprehension Mastery',
                'description' => 'Enhance your reading skills with comprehensive exercises and strategies. Learn to analyze texts, understand context, and improve vocabulary.',
                'category_id' => $categories[2]['id'] ?? null,
                'level' => 'intermediate',
                'price' => 79.99,
                'is_free' => 0,
                'duration' => 30,
                'status' => 'published',
                'language' => 'English'
            ],
            [
                'title' => 'English Listening Practice',
                'description' => 'Improve your listening skills with audio exercises, conversations, and pronunciation practice. Perfect for students who want to understand spoken English better.',
                'category_id' => $categories[3]['id'] ?? null,
                'level' => 'beginner',
                'price' => 49.99,
                'is_free' => 0,
                'duration' => 25,
                'status' => 'published',
                'language' => 'English'
            ],
            [
                'title' => 'Intermediate Grammar Workshop',
                'description' => 'Deep dive into intermediate grammar concepts including complex sentences, tenses, and advanced structures. Build on your basic grammar knowledge.',
                'category_id' => $categories[0]['id'] ?? null,
                'level' => 'intermediate',
                'price' => 69.99,
                'is_free' => 0,
                'duration' => 35,
                'status' => 'draft',
                'language' => 'English'
            ],
            [
                'title' => 'Creative Writing Fundamentals',
                'description' => 'Explore the art of creative writing including storytelling, character development, and narrative techniques. Unlock your creative potential.',
                'category_id' => $categories[1]['id'] ?? null,
                'level' => 'beginner',
                'price' => 59.99,
                'is_free' => 0,
                'duration' => 28,
                'status' => 'published',
                'language' => 'English'
            ]
        ];
        
        // Insert sample courses
        foreach ($sampleCourses as $index => $courseData) {
            try {
                // Generate slug (check if slug column exists first)
                $slug = null;
                try {
                    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $courseData['title'])));
                    $slug = preg_replace('/-+/', '-', $slug);
                    $slug = trim($slug, '-');
                    
                    // Ensure unique slug
                    $originalSlug = $slug;
                    $counter = 1;
                    $maxAttempts = 100;
                    while ($counter < $maxAttempts) {
                        try {
                            $stmt = $pdo->prepare("SELECT id FROM courses WHERE slug = ?");
                            $stmt->execute([$slug]);
                            if ($stmt->fetch()) {
                                $slug = $originalSlug . '-' . $counter;
                                $counter++;
                            } else {
                                break;
                            }
                        } catch (PDOException $e) {
                            // Slug column might not exist, set to null
                            $slug = null;
                            break;
                        }
                    }
                } catch (Exception $e) {
                    $slug = null;
                }
                
                // Try multiple insert attempts with different field combinations
                $insertAttempts = [
                    // Full insert with all fields including slug
                    [
                        'sql' => "INSERT INTO courses (title, slug, description, category_id, teacher_id, level, price, is_free, duration, status, language) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        'params' => [
                            $courseData['title'],
                            $slug,
                            $courseData['description'],
                            $courseData['category_id'],
                            $teacherId,
                            $courseData['level'],
                            $courseData['price'],
                            $courseData['is_free'],
                            $courseData['duration'],
                            $courseData['status'],
                            $courseData['language']
                        ]
                    ],
                    // Without slug
                    [
                        'sql' => "INSERT INTO courses (title, description, category_id, teacher_id, level, price, is_free, duration, status, language) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        'params' => [
                            $courseData['title'],
                            $courseData['description'],
                            $courseData['category_id'],
                            $teacherId,
                            $courseData['level'],
                            $courseData['price'],
                            $courseData['is_free'],
                            $courseData['duration'],
                            $courseData['status'],
                            $courseData['language']
                        ]
                    ],
                    // Minimal required fields
                    [
                        'sql' => "INSERT INTO courses (title, teacher_id, level, price, status) VALUES (?, ?, ?, ?, ?)",
                        'params' => [
                            $courseData['title'],
                            $teacherId,
                            $courseData['level'],
                            $courseData['price'],
                            $courseData['status']
                        ]
                    ],
                    // Absolute minimum
                    [
                        'sql' => "INSERT INTO courses (title, teacher_id) VALUES (?, ?)",
                        'params' => [
                            $courseData['title'],
                            $teacherId
                        ]
                    ]
                ];
                
                $inserted = false;
                $lastError = '';
                foreach ($insertAttempts as $attempt) {
                    try {
                        $stmt = $pdo->prepare($attempt['sql']);
                        $stmt->execute($attempt['params']);
                        $inserted = true;
                        $insertedCount++;
                        
                        // Generate auto thumbnail for the course
                        $courseId = $pdo->lastInsertId();
                        if ($courseId && function_exists('generateAutoThumbnail')) {
                            $categoryName = null;
                            if ($courseData['category_id']) {
                                foreach ($categories as $cat) {
                                    if ($cat['id'] == $courseData['category_id']) {
                                        $categoryName = $cat['name'];
                                        break;
                                    }
                                }
                            }
                            
                            try {
                                $thumbnail = generateAutoThumbnail(
                                    $courseData['title'],
                                    $categoryName,
                                    $courseData['level'],
                                    $courseId
                                );
                                
                                if ($thumbnail) {
                                    try {
                                        $stmt = $pdo->prepare("UPDATE courses SET thumbnail = ? WHERE id = ?");
                                        $stmt->execute([$thumbnail, $courseId]);
                                    } catch (PDOException $e) {
                                        // Continue even if thumbnail update fails
                                    }
                                }
                            } catch (Exception $e) {
                                // Thumbnail generation failed, continue
                            }
                        }
                        break;
                    } catch (PDOException $e) {
                        $lastError = $e->getMessage();
                        // Try next attempt
                        continue;
                    }
                }
                
                if (!$inserted) {
                    $errors[] = "Failed to create course '{$courseData['title']}': " . $lastError;
                }
            } catch (Exception $e) {
                $errors[] = "Error creating course '{$courseData['title']}': " . $e->getMessage();
            }
        }
        
        // If using RBAC, assign courses to teacher
        if ($insertedCount > 0) {
            try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'teacher_course_assignments'");
    if ($stmt->rowCount() > 0) {
                    // Get all courses we just created
                    $stmt = $pdo->prepare("SELECT id FROM courses WHERE teacher_id = ? ORDER BY id DESC LIMIT ?");
                    $stmt->execute([$teacherId, $insertedCount]);
                    $createdCourses = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    foreach ($createdCourses as $courseId) {
                        try {
                            $stmt = $pdo->prepare("INSERT IGNORE INTO teacher_course_assignments (teacher_id, course_id) VALUES (?, ?)");
                            $stmt->execute([$teacherId, $courseId]);
                        } catch (PDOException $e) {
                            // Continue
                        }
                    }
                }
            } catch (PDOException $e) {
                // RBAC table might not exist, continue
            }
        }
        
        if ($insertedCount > 0) {
            $message = "Successfully created {$insertedCount} sample course(s).";
            if (!empty($errors)) {
                $message .= " Some errors occurred: " . implode('; ', array_slice($errors, 0, 3));
            }
            return ['success' => true, 'message' => $message, 'count' => $insertedCount];
    } else {
            $errorMsg = !empty($errors) ? implode('; ', $errors) : 'Unknown error occurred';
            return ['success' => false, 'message' => 'Failed to create any courses. ' . $errorMsg];
        }
    } catch (Exception $e) {
        $errorMsg = "Error in autoGenerateSampleCourses: " . $e->getMessage();
        error_log($errorMsg);
        return ['success' => false, 'message' => $errorMsg];
    }
}

// Auto-generate sample courses if none exist (only on main list page, not when viewing/editing)
if (!isset($_GET['view']) && !isset($_GET['edit_section']) && !isset($_GET['edit_lesson']) && !isset($_GET['edit_quiz']) && !isset($_GET['edit_question']) && !isset($_POST['action'])) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM courses WHERE teacher_id = ?");
        $stmt->execute([$teacherId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (isset($result['count']) && intval($result['count']) === 0) {
            // No courses exist, generate samples automatically
            $result = autoGenerateSampleCourses($pdo, $teacherId);
            if ($result['success']) {
                // Refresh the page to show the new courses
                $message = urlencode($result['message']);
                header('Location: /Iqra-College/teacher/list_courses.php?auto_generated=1&msg=' . $message);
                exit;
            } else {
                // Log error but don't show it automatically (user can manually generate)
                error_log("Auto-generation failed: " . $result['message']);
            }
    }
} catch (PDOException $e) {
        // Continue even if check fails
        error_log("Error checking course count: " . $e->getMessage());
    }
}

// Show success message if courses were auto-generated
if (isset($_GET['auto_generated']) && $_GET['auto_generated'] === '1') {
    $success = isset($_GET['msg']) ? urldecode($_GET['msg']) : 'Sample courses have been automatically created for you! You can now view, edit, or delete them as needed.';
}

// Highlight newly created course if redirected from create page
$newCourseId = isset($_GET['new_course']) ? intval($_GET['new_course']) : 0;

// Get filter and search parameters
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
$filterStatus = isset($_GET['status']) ? $_GET['status'] : '';
$filterCategory = isset($_GET['category']) ? intval($_GET['category']) : 0;
$filterLevel = isset($_GET['level']) ? $_GET['level'] : '';
$sortBy = isset($_GET['sort']) ? $_GET['sort'] : 'date_desc';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 12;
$offset = ($page - 1) * $perPage;

// Get all categories for filter dropdown
try {
    $stmt = $pdo->query("SELECT id, name FROM categories ORDER BY name");
    $allCategories = $stmt->fetchAll();
} catch (PDOException $e) {
    $allCategories = [];
}

// Build query with filters
$whereConditions = [];
$params = [];
$joinClause = '';

// Check if teacher_course_assignments table exists
$useRBAC = false;
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'teacher_course_assignments'");
    $useRBAC = $stmt->rowCount() > 0;
} catch (PDOException $e) {
    $useRBAC = false;
}

if ($useRBAC) {
    // Use RBAC: Show courses from assignments OR courses created by teacher (fallback)
    $joinClause = "LEFT JOIN teacher_course_assignments tca ON c.id = tca.course_id AND tca.teacher_id = ?";
    $whereConditions[] = "(tca.teacher_id = ? OR c.teacher_id = ?)";
    $params[] = $teacherId;
    $params[] = $teacherId;
    $params[] = $teacherId;
} else {
    // Non-RBAC: Show courses created by teacher
    $whereConditions[] = "c.teacher_id = ?";
    $params[] = $teacherId;
}

// Add search filter
if (!empty($searchQuery)) {
    $whereConditions[] = "(c.title LIKE ? OR c.description LIKE ?)";
    $searchParam = '%' . $searchQuery . '%';
    $params[] = $searchParam;
    $params[] = $searchParam;
}

// Add status filter
if (!empty($filterStatus)) {
    $whereConditions[] = "c.status = ?";
    $params[] = $filterStatus;
}

// Add category filter
if ($filterCategory > 0) {
    $whereConditions[] = "c.category_id = ?";
    $params[] = $filterCategory;
}

// Add level filter
if (!empty($filterLevel)) {
    $whereConditions[] = "c.level = ?";
    $params[] = $filterLevel;
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Build sort clause
$sortClause = 'ORDER BY ';
switch ($sortBy) {
    case 'name_asc':
        $sortClause .= 'c.title ASC';
        break;
    case 'name_desc':
        $sortClause .= 'c.title DESC';
        break;
    case 'date_asc':
        $sortClause .= 'c.created_at ASC, c.id ASC';
        break;
    case 'date_desc':
    default:
        $sortClause .= 'c.created_at DESC, c.id DESC';
        break;
    case 'price_asc':
        $sortClause .= 'c.price ASC';
        break;
    case 'price_desc':
        $sortClause .= 'c.price DESC';
        break;
}

// Get total count for pagination
try {
    $countQuery = "SELECT COUNT(DISTINCT c.id) as total 
                                  FROM courses c 
                   $joinClause
                                  LEFT JOIN categories cat ON c.category_id = cat.id 
                   $whereClause";
    $stmt = $pdo->prepare($countQuery);
    $stmt->execute($params);
    $totalCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    $totalPages = ceil($totalCount / $perPage);
} catch (PDOException $e) {
    error_log("Count query error: " . $e->getMessage());
    $totalCount = 0;
    $totalPages = 0;
}

// Get teacher's courses with filters, pagination, and statistics
try {
    $query = "SELECT DISTINCT c.*, 
              cat.name as category_name,
              (SELECT COUNT(*) FROM enrollments WHERE course_id = c.id) as enrolled_count,
              (SELECT COUNT(*) FROM lessons WHERE course_id = c.id) as lessons_count,
              (SELECT COUNT(*) FROM quizzes WHERE course_id = c.id) as quizzes_count,
              (SELECT COUNT(*) FROM sections WHERE course_id = c.id) as sections_count
              FROM courses c 
              $joinClause
              LEFT JOIN categories cat ON c.category_id = cat.id 
              $whereClause
              $sortClause
              LIMIT ? OFFSET ?";
    
    $paramsWithLimit = array_merge($params, [$perPage, $offset]);
    $stmt = $pdo->prepare($query);
    $stmt->execute($paramsWithLimit);
            $courses = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Main query error: " . $e->getMessage());
    // Fallback if created_at doesn't exist
    try {
        // Try without created_at in ORDER BY
        $fallbackSort = str_replace('c.created_at DESC, c.id DESC', 'c.id DESC', $sortClause);
        $fallbackSort = str_replace('c.created_at ASC, c.id ASC', 'c.id ASC', $fallbackSort);
        
        $query = "SELECT DISTINCT c.*, 
                  cat.name as category_name,
                  (SELECT COUNT(*) FROM enrollments WHERE course_id = c.id) as enrolled_count,
                  (SELECT COUNT(*) FROM lessons WHERE course_id = c.id) as lessons_count,
                  (SELECT COUNT(*) FROM quizzes WHERE course_id = c.id) as quizzes_count,
                  (SELECT COUNT(*) FROM sections WHERE course_id = c.id) as sections_count
                  FROM courses c 
                  $joinClause
                                  LEFT JOIN categories cat ON c.category_id = cat.id 
                  $whereClause
                  $fallbackSort
                  LIMIT ? OFFSET ?";
        
        $paramsWithLimit = array_merge($params, [$perPage, $offset]);
        $stmt = $pdo->prepare($query);
        $stmt->execute($paramsWithLimit);
            $courses = $stmt->fetchAll();
    } catch (PDOException $e2) {
        error_log("Fallback query error: " . $e2->getMessage());
        $courses = [];
        $totalCount = 0;
        $totalPages = 0;
    }
}

$viewCourseId = isset($_GET['view']) ? intval($_GET['view']) : 0;
$viewCourse = null;
$viewSections = [];
$viewLessons = [];
$viewQuizzes = [];
$viewQuestionsByQuiz = [];
$editSectionId = isset($_GET['edit_section']) ? intval($_GET['edit_section']) : 0;
$editLessonId = isset($_GET['edit_lesson']) ? intval($_GET['edit_lesson']) : 0;
$editQuizId = isset($_GET['edit_quiz']) ? intval($_GET['edit_quiz']) : 0;
$editQuestionId = isset($_GET['edit_question']) ? intval($_GET['edit_question']) : 0;
$editSection = null;
$editLesson = null;
$editQuiz = null;
$editQuestion = null;
if ($viewCourseId > 0) {
    foreach ($courses as $course) {
        if ((int) $course['id'] === $viewCourseId) {
            $viewCourse = $course;
            break;
        }
    }
    if (!$viewCourse) {
        $error = 'Course not found or you do not have permission to view it.';
    }
}

if ($viewCourse) {
    // Get course statistics
    $courseStats = [
        'sections_count' => 0,
        'lessons_count' => 0,
        'quizzes_count' => 0,
        'enrolled_count' => 0,
        'completed_count' => 0,
        'assignments_count' => 0
    ];
    
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM sections WHERE course_id = ?");
        $stmt->execute([$viewCourseId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $courseStats['sections_count'] = intval($result['count'] ?? 0);
    } catch (PDOException $e) {}
    
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM lessons WHERE course_id = ?");
        $stmt->execute([$viewCourseId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $courseStats['lessons_count'] = intval($result['count'] ?? 0);
    } catch (PDOException $e) {}
    
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM quizzes WHERE course_id = ?");
        $stmt->execute([$viewCourseId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $courseStats['quizzes_count'] = intval($result['count'] ?? 0);
    } catch (PDOException $e) {}
    
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM enrollments WHERE course_id = ?");
        $stmt->execute([$viewCourseId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $courseStats['enrolled_count'] = intval($result['count'] ?? 0);
    } catch (PDOException $e) {}
    
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM enrollments WHERE course_id = ? AND progress = 100");
        $stmt->execute([$viewCourseId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $courseStats['completed_count'] = intval($result['count'] ?? 0);
    } catch (PDOException $e) {}
    
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM assignments WHERE course_id = ?");
        $stmt->execute([$viewCourseId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $courseStats['assignments_count'] = intval($result['count'] ?? 0);
    } catch (PDOException $e) {}
    
    // Get recent enrollments
    $recentEnrollments = [];
    try {
        $stmt = $pdo->prepare("SELECT e.*, u.name as student_name, u.email as student_email 
                               FROM enrollments e 
                               JOIN users u ON e.student_id = u.id 
                               WHERE e.course_id = ? 
                               ORDER BY e.enrolled_at DESC 
                               LIMIT 10");
        $stmt->execute([$viewCourseId]);
        $recentEnrollments = $stmt->fetchAll();
    } catch (PDOException $e) {
        $recentEnrollments = [];
    }
    
    try {
        $stmt = $pdo->prepare("SELECT s.* FROM sections s WHERE s.course_id = ? ORDER BY s.order_number, s.id");
        $stmt->execute([$viewCourseId]);
        $viewSections = $stmt->fetchAll();
    } catch (PDOException $e) {
        $viewSections = [];
    }
    try {
        $stmt = $pdo->prepare("SELECT l.*, s.title as section_title FROM lessons l LEFT JOIN sections s ON l.section_id = s.id WHERE l.course_id = ? ORDER BY COALESCE(s.order_number, 999), s.id, l.order_number, l.id");
        $stmt->execute([$viewCourseId]);
        $viewLessons = $stmt->fetchAll();
    } catch (PDOException $e) {
        $viewLessons = [];
    }
    try {
        $stmt = $pdo->prepare("SELECT q.*, s.title as section_title, l.title as lesson_title 
                               FROM quizzes q 
                               LEFT JOIN sections s ON q.section_id = s.id 
                               LEFT JOIN lessons l ON q.lesson_id = l.id 
                               WHERE q.course_id = ? 
                               ORDER BY q.created_at DESC");
        $stmt->execute([$viewCourseId]);
        $viewQuizzes = $stmt->fetchAll();
    } catch (PDOException $e) {
        $viewQuizzes = [];
    }
    if (!empty($viewQuizzes)) {
        $quizIds = array_column($viewQuizzes, 'id');
        $placeholders = str_repeat('?,', count($quizIds) - 1) . '?';
        try {
            $stmt = $pdo->prepare("SELECT * FROM questions WHERE quiz_id IN ($placeholders) ORDER BY quiz_id, order_number, id");
            $stmt->execute($quizIds);
            $questions = $stmt->fetchAll();
            foreach ($questions as $q) {
                $viewQuestionsByQuiz[$q['quiz_id']][] = $q;
            }
        } catch (PDOException $e) {
            $viewQuestionsByQuiz = [];
        }
    }
    if ($editSectionId > 0) {
        foreach ($viewSections as $section) {
            if ((int) $section['id'] === $editSectionId) {
                $editSection = $section;
                break;
            }
        }
    }
    if ($editLessonId > 0) {
        foreach ($viewLessons as $lesson) {
            if ((int) $lesson['id'] === $editLessonId) {
                $editLesson = $lesson;
                break;
            }
        }
    }
    if ($editQuizId > 0) {
        foreach ($viewQuizzes as $quiz) {
            if ((int) $quiz['id'] === $editQuizId) {
                $editQuiz = $quiz;
                break;
            }
        }
    }
    if ($editQuestionId > 0 && !empty($viewQuestionsByQuiz)) {
        foreach ($viewQuestionsByQuiz as $questions) {
            foreach ($questions as $q) {
                if ((int) $q['id'] === $editQuestionId) {
                    $editQuestion = $q;
                    break 2;
                }
            }
        }
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
                            <p class="text-sm text-gray-500 dark:text-gray-400">View and manage all your courses</p>
                        </div>
                    </div>
                    <div class="flex items-center space-x-3">
                        <?php 
                        // Check if teacher has any courses at all (not just filtered results)
                        $hasAnyCourses = false;
                        try {
                            $checkStmt = $pdo->prepare("SELECT COUNT(*) as count FROM courses WHERE teacher_id = ?");
                            $checkStmt->execute([$teacherId]);
                            $checkResult = $checkStmt->fetch(PDO::FETCH_ASSOC);
                            $hasAnyCourses = isset($checkResult['count']) && intval($checkResult['count']) > 0;
                        } catch (PDOException $e) {
                            $hasAnyCourses = false;
                        }
                        ?>
                        <?php if (!$hasAnyCourses && !isset($_GET['view'])): ?>
                            <form method="POST" action="" class="inline" onsubmit="return confirm('This will create 6 sample courses. Continue?');">
                                <input type="hidden" name="action" value="generate_samples">
                                <button type="submit" 
                                        class="bg-gradient-to-r from-purple-500 to-purple-600 text-white px-6 py-2 rounded-lg font-semibold hover:from-purple-600 hover:to-purple-700 transition-all shadow-lg">
                                    <i class="fas fa-magic mr-2"></i>Generate Sample Courses
                                </button>
                            </form>
                        <?php endif; ?>
                        <a href="/Iqra-College/teacher/create_course.php" 
                           class="bg-gradient-to-r from-green-500 to-emerald-600 text-white px-6 py-2 rounded-lg font-semibold hover:from-green-600 hover:to-emerald-700 transition-all shadow-lg">
                            <i class="fas fa-plus mr-2"></i>Create New Course
                        </a>
                        <?php include __DIR__ . '/../includes/teacher_header.php'; ?>
                    </div>
                </div>
            </div>
        </nav>

        <div class="p-6 lg:p-8">
            <?php if ($error): ?>
                <div class="bg-red-100 dark:bg-red-900/30 border-l-4 border-red-500 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg mb-6 fade-in">
                    <i class="fas fa-exclamation-circle mr-2"></i><?php echo $error; ?>
                </div>
            <?php endif; ?>
        
            <?php if ($success): ?>
                <div class="bg-green-100 dark:bg-green-900/30 border-l-4 border-green-500 text-green-700 dark:text-green-400 px-4 py-3 rounded-lg mb-6 fade-in">
                    <i class="fas fa-check-circle mr-2"></i><?php echo $success; ?>
                </div>
            <?php endif; ?>

            <?php if ($viewCourse): ?>
                <?php
                $viewThumb = null;
                if (!empty($viewCourse['thumbnail'])) {
                    $thumbFile = $viewCourse['thumbnail'];
                    $thumbPath = __DIR__ . '/../uploads/courses/' . $thumbFile;
                    if (file_exists($thumbPath)) {
                        $viewThumb = '/Iqra-College/uploads/courses/' . $thumbFile;
                    }
                }
                ?>
                <!-- Course Header with Stats -->
                <div class="bg-gradient-to-r from-blue-600 via-purple-600 to-pink-600 rounded-3xl shadow-2xl p-8 mb-8 fade-in text-white">
                    <div class="flex items-start justify-between gap-4 flex-wrap">
                        <div class="flex-1">
                            <div class="flex items-center gap-3 mb-2">
                                <span class="px-3 py-1 rounded-full bg-white/20 text-sm font-semibold"><?php echo htmlspecialchars($viewCourse['category_name'] ?? 'Course'); ?></span>
                                <span class="px-3 py-1 rounded-full bg-white/20 text-sm font-semibold capitalize"><?php echo htmlspecialchars($viewCourse['level'] ?? 'beginner'); ?></span>
                                <span class="px-3 py-1 rounded-full bg-white/20 text-sm font-semibold capitalize"><?php echo htmlspecialchars($viewCourse['status'] ?? 'draft'); ?></span>
                        </div>
                            <h2 class="text-4xl font-extrabold mb-2"><?php echo htmlspecialchars($viewCourse['title']); ?></h2>
                            <p class="text-blue-100 text-lg">Complete course overview and management</p>
                        </div>
                        <div class="flex gap-2">
                            <a href="/Iqra-College/teacher/list_courses.php" class="bg-white/20 hover:bg-white/30 text-white px-4 py-2 rounded-lg text-sm font-semibold transition-all backdrop-blur-sm">
                            <i class="fas fa-arrow-left mr-1"></i>Back to list
                        </a>
                            <a href="/Iqra-College/teacher/create_course.php?edit=<?php echo $viewCourse['id']; ?>"
                               class="bg-white text-blue-600 px-4 py-2 rounded-lg text-sm font-semibold hover:bg-blue-50 transition-all shadow-lg">
                                <i class="fas fa-pen mr-1"></i>Edit Course
                            </a>
                    </div>
                    </div>
                    
                    <!-- Quick Stats -->
                    <div class="grid grid-cols-2 md:grid-cols-6 gap-4 mt-8">
                        <div class="bg-white/10 backdrop-blur-sm rounded-xl p-4 text-center border border-white/20">
                            <div class="text-3xl font-bold mb-1"><?php echo $courseStats['sections_count']; ?></div>
                            <div class="text-sm text-blue-100"><i class="fas fa-list mr-1"></i>Sections</div>
                        </div>
                        <div class="bg-white/10 backdrop-blur-sm rounded-xl p-4 text-center border border-white/20">
                            <div class="text-3xl font-bold mb-1"><?php echo $courseStats['lessons_count']; ?></div>
                            <div class="text-sm text-blue-100"><i class="fas fa-book mr-1"></i>Lessons</div>
                        </div>
                        <div class="bg-white/10 backdrop-blur-sm rounded-xl p-4 text-center border border-white/20">
                            <div class="text-3xl font-bold mb-1"><?php echo $courseStats['quizzes_count']; ?></div>
                            <div class="text-sm text-blue-100"><i class="fas fa-question-circle mr-1"></i>Quizzes</div>
                        </div>
                        <div class="bg-white/10 backdrop-blur-sm rounded-xl p-4 text-center border border-white/20">
                            <div class="text-3xl font-bold mb-1"><?php echo $courseStats['enrolled_count']; ?></div>
                            <div class="text-sm text-blue-100"><i class="fas fa-users mr-1"></i>Students</div>
                        </div>
                        <div class="bg-white/10 backdrop-blur-sm rounded-xl p-4 text-center border border-white/20">
                            <div class="text-3xl font-bold mb-1"><?php echo $courseStats['completed_count']; ?></div>
                            <div class="text-sm text-blue-100"><i class="fas fa-check-circle mr-1"></i>Completed</div>
                        </div>
                        <div class="bg-white/10 backdrop-blur-sm rounded-xl p-4 text-center border border-white/20">
                            <div class="text-3xl font-bold mb-1"><?php echo $courseStats['assignments_count']; ?></div>
                            <div class="text-sm text-blue-100"><i class="fas fa-tasks mr-1"></i>Assignments</div>
                        </div>
                    </div>
                </div>
                
                <!-- Navigation Tabs -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-lg p-4 mb-6 border border-gray-200 dark:border-gray-700 sticky top-4 z-10">
                    <div class="flex flex-wrap gap-3">
                        <a href="#overview" class="nav-tab px-4 py-2 rounded-lg bg-primary-600 text-white text-sm font-semibold shadow transition-all" data-section="overview">
                            <i class="fas fa-info-circle mr-1"></i>Overview
                        </a>
                        <a href="#sections" class="nav-tab px-4 py-2 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 text-sm font-semibold hover:bg-gray-200 dark:hover:bg-gray-600 transition-all" data-section="sections">
                            <i class="fas fa-list mr-1"></i>Sections (<?php echo $courseStats['sections_count']; ?>)
                        </a>
                        <a href="#lessons" class="nav-tab px-4 py-2 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 text-sm font-semibold hover:bg-gray-200 dark:hover:bg-gray-600 transition-all" data-section="lessons">
                            <i class="fas fa-book mr-1"></i>Lessons (<?php echo $courseStats['lessons_count']; ?>)
                        </a>
                        <a href="#quizzes" class="nav-tab px-4 py-2 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 text-sm font-semibold hover:bg-gray-200 dark:hover:bg-gray-600 transition-all" data-section="quizzes">
                            <i class="fas fa-question-circle mr-1"></i>Quizzes (<?php echo $courseStats['quizzes_count']; ?>)
                        </a>
                        <a href="#enrollments" class="nav-tab px-4 py-2 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 text-sm font-semibold hover:bg-gray-200 dark:hover:bg-gray-600 transition-all" data-section="enrollments">
                            <i class="fas fa-users mr-1"></i>Enrollments (<?php echo $courseStats['enrolled_count']; ?>)
                        </a>
                    </div>
                </div>
                
                <!-- Course Overview Section -->
                <div id="overview" class="bg-white dark:bg-gray-800 rounded-3xl shadow-2xl p-8 mb-8 fade-in border border-gray-200 dark:border-gray-700 scroll-mt-24">
                    <h3 class="text-2xl font-bold text-gray-800 dark:text-white mb-6 flex items-center">
                        <i class="fas fa-info-circle mr-2 text-primary-600"></i>Course Overview
                    </h3>
                    
                    <div class="grid lg:grid-cols-3 gap-6">
                        <div class="lg:col-span-1">
                            <?php if ($viewThumb): ?>
                                <img src="<?php echo htmlspecialchars($viewThumb); ?>" alt="" class="w-full h-64 rounded-2xl object-cover shadow-lg">
                            <?php else: ?>
                                <div class="w-full h-64 rounded-2xl bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center shadow-lg">
                                    <span class="text-white text-6xl font-bold"><?php echo strtoupper(substr($viewCourse['title'], 0, 1)); ?></span>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Course Info Cards -->
                            <div class="mt-4 space-y-3">
                                <div class="bg-gradient-to-r from-blue-50 to-blue-100 dark:from-blue-900/20 dark:to-blue-900/30 rounded-xl p-4 border border-blue-200 dark:border-blue-800">
                                    <p class="text-xs text-blue-600 dark:text-blue-400 mb-1">Price</p>
                                    <p class="text-2xl font-bold text-blue-800 dark:text-blue-300">
                                        <?php echo ($viewCourse['is_free'] ?? 0) ? '<span class="text-green-600">FREE</span>' : ('$' . number_format($viewCourse['price'] ?? 0, 2)); ?>
                                    </p>
                                    <?php if (!empty($viewCourse['discount_price']) && $viewCourse['discount_price'] < $viewCourse['price']): ?>
                                        <p class="text-sm text-gray-600 line-through">$<?php echo number_format($viewCourse['price'], 2); ?></p>
                                        <p class="text-sm text-green-600 font-semibold">Now: $<?php echo number_format($viewCourse['discount_price'], 2); ?></p>
                                    <?php endif; ?>
                            </div>
                                
                                <div class="bg-gradient-to-r from-purple-50 to-purple-100 dark:from-purple-900/20 dark:to-purple-900/30 rounded-xl p-4 border border-purple-200 dark:border-purple-800">
                                    <p class="text-xs text-purple-600 dark:text-purple-400 mb-1">Status</p>
                                    <p class="text-lg font-bold text-purple-800 dark:text-purple-300 capitalize">
                                        <?php echo htmlspecialchars($viewCourse['status'] ?? 'draft'); ?>
                                    </p>
                                </div>
                                
                                <div class="flex gap-2">
                                <a href="/Iqra-College/teacher/create_course.php?edit=<?php echo $viewCourse['id']; ?>"
                                       class="flex-1 bg-gradient-to-r from-blue-500 to-blue-600 text-white px-4 py-3 rounded-lg text-sm font-semibold hover:from-blue-600 hover:to-blue-700 transition-all shadow-lg text-center">
                                    <i class="fas fa-pen mr-1"></i>Edit Course
                                </a>
                                    <form method="POST" class="flex-1" onsubmit="return confirmDeleteCourse('<?php echo htmlspecialchars(addslashes($viewCourse['title'])); ?>', <?php echo $viewCourse['id']; ?>)">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $viewCourse['id']; ?>">
                                    <button type="submit"
                                                class="w-full bg-gradient-to-r from-red-500 to-red-600 text-white px-4 py-3 rounded-lg text-sm font-semibold hover:from-red-600 hover:to-red-700 transition-all shadow-lg">
                                        <i class="fas fa-trash mr-1"></i>Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                            </div>
                        
                        <div class="lg:col-span-2 space-y-6">
                            <!-- Description -->
                            <div class="rounded-2xl border-2 border-gray-200 dark:border-gray-700 p-6 bg-gray-50 dark:bg-gray-900/50">
                                <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-3 flex items-center">
                                    <i class="fas fa-align-left mr-2 text-primary-600"></i>Course Description
                                </h3>
                                <p class="text-gray-600 dark:text-gray-400 leading-relaxed">
                                    <?php echo !empty($viewCourse['description']) ? nl2br(htmlspecialchars($viewCourse['description'])) : '<span class="text-gray-400 italic">No description provided.</span>'; ?>
                                </p>
                            </div>
                            
                            <!-- Course Details Grid -->
                            <div class="grid sm:grid-cols-2 gap-4">
                                <div class="rounded-xl border border-gray-200 dark:border-gray-700 p-4 bg-white dark:bg-gray-800">
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-1 flex items-center">
                                        <i class="fas fa-globe mr-1"></i>Language
                                    </p>
                                    <p class="font-semibold text-gray-800 dark:text-white text-lg"><?php echo htmlspecialchars($viewCourse['language'] ?? 'English'); ?></p>
                                </div>
                                <div class="rounded-xl border border-gray-200 dark:border-gray-700 p-4 bg-white dark:bg-gray-800">
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-1 flex items-center">
                                        <i class="fas fa-clock mr-1"></i>Duration
                                    </p>
                                    <p class="font-semibold text-gray-800 dark:text-white text-lg"><?php echo htmlspecialchars($viewCourse['duration'] ?? 0); ?> hours</p>
                                </div>
                                <div class="rounded-xl border border-gray-200 dark:border-gray-700 p-4 bg-white dark:bg-gray-800">
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-1 flex items-center">
                                        <i class="fas fa-calendar-alt mr-1"></i>Access Days
                                    </p>
                                    <p class="font-semibold text-gray-800 dark:text-white text-lg">
                                        <?php echo ($viewCourse['access_days'] ?? 0) == 0 ? 'Lifetime' : htmlspecialchars($viewCourse['access_days']) . ' days'; ?>
                                    </p>
                                </div>
                                <div class="rounded-xl border border-gray-200 dark:border-gray-700 p-4 bg-white dark:bg-gray-800">
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-1 flex items-center">
                                        <i class="fas fa-users mr-1"></i>Max Students
                                    </p>
                                    <p class="font-semibold text-gray-800 dark:text-white text-lg">
                                        <?php echo !empty($viewCourse['max_students']) ? htmlspecialchars($viewCourse['max_students']) : 'Unlimited'; ?>
                                    </p>
                                </div>
                                <div class="rounded-xl border border-gray-200 dark:border-gray-700 p-4 bg-white dark:bg-gray-800">
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-1 flex items-center">
                                        <i class="fas fa-certificate mr-1"></i>Certificate
                                    </p>
                                    <p class="font-semibold text-gray-800 dark:text-white text-lg">
                                        <?php echo ($viewCourse['has_certificate'] ?? 0) ? '<span class="text-green-600">Yes</span>' : '<span class="text-gray-400">No</span>'; ?>
                                    </p>
                                </div>
                                <div class="rounded-xl border border-gray-200 dark:border-gray-700 p-4 bg-white dark:bg-gray-800">
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-1 flex items-center">
                                        <i class="fas fa-calendar mr-1"></i>Created
                                    </p>
                                    <p class="font-semibold text-gray-800 dark:text-white text-lg">
                                        <?php echo date('M d, Y', strtotime($viewCourse['created_at'] ?? 'now')); ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Enrollments Section -->
                <div id="enrollments" class="bg-white dark:bg-gray-800 rounded-3xl shadow-2xl p-8 mb-8 fade-in border border-gray-200 dark:border-gray-700 scroll-mt-24">
                    <h3 class="text-2xl font-bold text-gray-800 dark:text-white mb-6 flex items-center">
                        <i class="fas fa-users mr-2 text-green-600"></i>Student Enrollments (<?php echo $courseStats['enrolled_count']; ?>)
                    </h3>
                    
                    <?php if (empty($recentEnrollments)): ?>
                        <div class="text-center py-12 bg-gray-50 dark:bg-gray-900/50 rounded-xl">
                            <i class="fas fa-user-slash text-5xl text-gray-400 dark:text-gray-600 mb-4"></i>
                            <p class="text-gray-500 dark:text-gray-400">No students enrolled yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead>
                                    <tr class="border-b border-gray-200 dark:border-gray-700">
                                        <th class="text-left py-3 px-4 text-sm font-semibold text-gray-700 dark:text-gray-300">Student</th>
                                        <th class="text-left py-3 px-4 text-sm font-semibold text-gray-700 dark:text-gray-300">Email</th>
                                        <th class="text-left py-3 px-4 text-sm font-semibold text-gray-700 dark:text-gray-300">Enrolled</th>
                                        <th class="text-left py-3 px-4 text-sm font-semibold text-gray-700 dark:text-gray-300">Progress</th>
                                        <th class="text-left py-3 px-4 text-sm font-semibold text-gray-700 dark:text-gray-300">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentEnrollments as $enrollment): ?>
                                        <tr class="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-900/50">
                                            <td class="py-3 px-4">
                                                <div class="font-semibold text-gray-800 dark:text-white"><?php echo htmlspecialchars($enrollment['student_name'] ?? 'Unknown'); ?></div>
                                            </td>
                                            <td class="py-3 px-4 text-sm text-gray-600 dark:text-gray-400"><?php echo htmlspecialchars($enrollment['student_email'] ?? '—'); ?></td>
                                            <td class="py-3 px-4 text-sm text-gray-600 dark:text-gray-400">
                                                <?php echo date('M d, Y', strtotime($enrollment['enrolled_at'] ?? 'now')); ?>
                                            </td>
                                            <td class="py-3 px-4">
                                                <div class="flex items-center gap-2">
                                                    <div class="flex-1 bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                                                        <div class="bg-primary-600 h-2 rounded-full" style="width: <?php echo min(100, max(0, intval($enrollment['progress'] ?? 0))); ?>%"></div>
                                                    </div>
                                                    <span class="text-sm font-semibold text-gray-700 dark:text-gray-300"><?php echo intval($enrollment['progress'] ?? 0); ?>%</span>
                                                </div>
                                            </td>
                                            <td class="py-3 px-4">
                                                <?php 
                                                $progress = intval($enrollment['progress'] ?? 0);
                                                $statusClass = $progress == 100 ? 'bg-green-100 text-green-700 dark:bg-green-900/20 dark:text-green-400' : 
                                                               ($progress > 0 ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/20 dark:text-blue-400' : 
                                                               'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300');
                                                $statusText = $progress == 100 ? 'Completed' : ($progress > 0 ? 'In Progress' : 'Not Started');
                                                ?>
                                                <span class="px-3 py-1 rounded-full text-xs font-semibold <?php echo $statusClass; ?>">
                                                    <?php echo $statusText; ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($courseStats['enrolled_count'] > 10): ?>
                            <div class="mt-4 text-center">
                                <a href="/Iqra-College/teacher/students.php?course_id=<?php echo $viewCourseId; ?>" 
                                   class="text-primary-600 dark:text-primary-400 hover:underline font-semibold">
                                    View all <?php echo $courseStats['enrolled_count']; ?> enrolled students →
                                </a>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <div id="sections" class="bg-white dark:bg-gray-800 rounded-3xl shadow-2xl p-8 mb-8 fade-in border border-gray-200 dark:border-gray-700 scroll-mt-24">
                    <h3 class="text-2xl font-bold text-gray-800 dark:text-white mb-6 flex items-center">
                        <i class="fas fa-list mr-2 text-blue-600"></i>Course Sections
                    </h3>
                    <form method="POST" class="grid md:grid-cols-3 gap-4 mb-6">
                        <input type="hidden" name="action" value="<?php echo $editSection ? 'update_section' : 'add_section'; ?>">
                        <input type="hidden" name="course_id" value="<?php echo $viewCourseId; ?>">
                        <?php if ($editSection): ?>
                            <input type="hidden" name="section_id" value="<?php echo $editSection['id']; ?>">
                        <?php endif; ?>
                        <input type="text" name="section_title" required placeholder="Section Title" value="<?php echo htmlspecialchars($editSection['title'] ?? ''); ?>"
                               class="px-4 py-2 border-2 border-blue-200 dark:border-gray-700 rounded-lg focus:border-blue-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                        <input type="number" name="order_number" placeholder="Order" value="<?php echo htmlspecialchars($editSection['order_number'] ?? 0); ?>"
                               class="px-4 py-2 border-2 border-blue-200 dark:border-gray-700 rounded-lg focus:border-blue-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                        <button type="submit" class="bg-green-500 text-white px-6 py-2 rounded-lg font-semibold">
                            <?php echo $editSection ? 'Update Section' : 'Add Section'; ?>
                        </button>
                    </form>
                    <?php if (empty($viewSections)): ?>
                        <p class="text-gray-500">No sections yet.</p>
                    <?php else: ?>
                        <div class="grid md:grid-cols-2 gap-4">
                            <?php foreach ($viewSections as $section): ?>
                                <div class="p-4 rounded-2xl border border-gray-200 dark:border-gray-700 flex items-center justify-between">
                                    <div>
                                        <p class="font-semibold text-gray-800 dark:text-white"><?php echo htmlspecialchars($section['title']); ?></p>
                                        <p class="text-xs text-gray-500">Order: <?php echo $section['order_number']; ?></p>
                                    </div>
                                    <div class="flex gap-2">
                                        <a href="/Iqra-College/teacher/list_courses.php?view=<?php echo $viewCourseId; ?>&edit_section=<?php echo $section['id']; ?>" class="px-3 py-1 rounded-lg bg-blue-500 text-white text-sm">Edit</a>
                                        <form method="POST" onsubmit="return confirm('Delete section?')">
                                            <input type="hidden" name="action" value="delete_section">
                                            <input type="hidden" name="section_id" value="<?php echo $section['id']; ?>">
                                            <button type="submit" class="px-3 py-1 rounded-lg bg-red-500 text-white text-sm">Delete</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div id="lessons" class="bg-white dark:bg-gray-800 rounded-3xl shadow-2xl p-8 mb-8 fade-in border border-gray-200 dark:border-gray-700 scroll-mt-24">
                    <h3 class="text-2xl font-bold text-gray-800 dark:text-white mb-6 flex items-center">
                        <i class="fas fa-book mr-2 text-green-600"></i>Course Lessons
                    </h3>
                    <?php if (empty($viewSections)): ?>
                        <div class="bg-amber-50 border border-amber-200 text-amber-700 px-4 py-3 rounded-lg mb-4">
                            Please create a section before adding a lesson.
                        </div>
                    <?php endif; ?>
                    <form method="POST" enctype="multipart/form-data" class="grid md:grid-cols-2 gap-4 mb-6">
                        <input type="hidden" name="action" value="<?php echo $editLesson ? 'update_lesson' : 'add_lesson'; ?>">
                        <input type="hidden" name="course_id" value="<?php echo $viewCourseId; ?>">
                        <?php if ($editLesson): ?>
                            <input type="hidden" name="lesson_id" value="<?php echo $editLesson['id']; ?>">
                        <?php endif; ?>
                        <select name="section_id" required class="px-4 py-2 border-2 border-blue-200 dark:border-gray-700 rounded-lg focus:border-blue-500 focus:outline-none dark:bg-gray-700 dark:text-white" <?php echo empty($viewSections) ? 'disabled' : ''; ?>>
                            <option value="">Select Section</option>
                            <?php foreach ($viewSections as $section): ?>
                                <option value="<?php echo $section['id']; ?>" <?php echo ($editLesson && $editLesson['section_id'] == $section['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($section['title']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="title" required placeholder="Lesson Title" value="<?php echo htmlspecialchars($editLesson['title'] ?? ''); ?>"
                               class="px-4 py-2 border-2 border-blue-200 dark:border-gray-700 rounded-lg focus:border-blue-500 focus:outline-none dark:bg-gray-700 dark:text-white" <?php echo empty($viewSections) ? 'disabled' : ''; ?>>
                        <select name="lesson_type" class="px-4 py-2 border-2 border-blue-200 dark:border-gray-700 rounded-lg focus:border-blue-500 focus:outline-none dark:bg-gray-700 dark:text-white" <?php echo empty($viewSections) ? 'disabled' : ''; ?>>
                            <option value="Writing" <?php echo ($editLesson && $editLesson['lesson_type'] == 'Writing') ? 'selected' : ''; ?>>Writing</option>
                            <option value="Reading" <?php echo ($editLesson && $editLesson['lesson_type'] == 'Reading') ? 'selected' : ''; ?>>Reading</option>
                            <option value="Listening" <?php echo ($editLesson && $editLesson['lesson_type'] == 'Listening') ? 'selected' : ''; ?>>Listening</option>
                        </select>
                        <select name="media_type" class="px-4 py-2 border-2 border-blue-200 dark:border-gray-700 rounded-lg focus:border-blue-500 focus:outline-none dark:bg-gray-700 dark:text-white" <?php echo empty($viewSections) ? 'disabled' : ''; ?>>
                            <option value="text" <?php echo ($editLesson && ($editLesson['media_type'] ?? 'text') == 'text') ? 'selected' : ''; ?>>Text</option>
                            <option value="video" <?php echo ($editLesson && ($editLesson['media_type'] ?? '') == 'video') ? 'selected' : ''; ?>>Video</option>
                            <option value="audio" <?php echo ($editLesson && ($editLesson['media_type'] ?? '') == 'audio') ? 'selected' : ''; ?>>Audio</option>
                            <option value="document" <?php echo ($editLesson && ($editLesson['media_type'] ?? '') == 'document') ? 'selected' : ''; ?>>Document</option>
                        </select>
                        <textarea name="content" rows="4" placeholder="Lesson content" class="md:col-span-2 px-4 py-2 border-2 border-blue-200 dark:border-gray-700 rounded-lg focus:border-blue-500 focus:outline-none dark:bg-gray-700 dark:text-white" <?php echo empty($viewSections) ? 'disabled' : ''; ?>><?php echo htmlspecialchars($editLesson['content'] ?? ''); ?></textarea>
                        <input type="number" name="order_number" placeholder="Order" value="<?php echo htmlspecialchars($editLesson['order_number'] ?? 0); ?>"
                               class="px-4 py-2 border-2 border-blue-200 dark:border-gray-700 rounded-lg focus:border-blue-500 focus:outline-none dark:bg-gray-700 dark:text-white" <?php echo empty($viewSections) ? 'disabled' : ''; ?>>
                        <div class="md:col-span-2 grid md:grid-cols-3 gap-3">
                            <input type="file" name="video_file" accept="video/*" class="px-3 py-2 border border-gray-200 rounded-lg" <?php echo empty($viewSections) ? 'disabled' : ''; ?>>
                            <input type="file" name="audio_file" accept="audio/*" class="px-3 py-2 border border-gray-200 rounded-lg" <?php echo empty($viewSections) ? 'disabled' : ''; ?>>
                            <input type="file" name="document_file" accept=".pdf,.doc,.docx,.txt" class="px-3 py-2 border border-gray-200 rounded-lg" <?php echo empty($viewSections) ? 'disabled' : ''; ?>>
                        </div>
                        <button type="submit" class="bg-blue-500 text-white px-6 py-2 rounded-lg font-semibold md:col-span-2" <?php echo empty($viewSections) ? 'disabled' : ''; ?>>
                            <?php echo $editLesson ? 'Update Lesson' : 'Add Lesson'; ?>
                        </button>
                    </form>
                    <?php if (empty($viewLessons)): ?>
                        <p class="text-gray-500">No lessons yet.</p>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($viewLessons as $lesson): ?>
                                <div class="p-4 rounded-2xl border border-gray-200 dark:border-gray-700 flex items-center justify-between">
                                    <div>
                                        <p class="font-semibold text-gray-800 dark:text-white"><?php echo htmlspecialchars($lesson['title']); ?></p>
                                        <p class="text-xs text-gray-500"><?php echo htmlspecialchars($lesson['section_title'] ?? 'No Section'); ?> • <?php echo htmlspecialchars($lesson['lesson_type']); ?></p>
                                    </div>
                                    <div class="flex gap-2">
                                        <a href="/Iqra-College/teacher/list_courses.php?view=<?php echo $viewCourseId; ?>&edit_lesson=<?php echo $lesson['id']; ?>" class="px-3 py-1 rounded-lg bg-blue-500 text-white text-sm">Edit</a>
                                        <form method="POST" onsubmit="return confirm('Delete lesson?')">
                                            <input type="hidden" name="action" value="delete_lesson">
                                            <input type="hidden" name="lesson_id" value="<?php echo $lesson['id']; ?>">
                                            <button type="submit" class="px-3 py-1 rounded-lg bg-red-500 text-white text-sm">Delete</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div id="quizzes" class="bg-white dark:bg-gray-800 rounded-3xl shadow-2xl p-8 mb-8 fade-in border border-gray-200 dark:border-gray-700 scroll-mt-24">
                    <h3 class="text-2xl font-bold text-gray-800 dark:text-white mb-6 flex items-center">
                        <i class="fas fa-question-circle mr-2 text-purple-600"></i>Course Quizzes
                    </h3>
                    <?php if (empty($viewLessons)): ?>
                        <div class="bg-amber-50 border border-amber-200 text-amber-700 px-4 py-3 rounded-lg mb-4">
                            Please create a lesson before adding a quiz.
                        </div>
                    <?php endif; ?>
                    <form method="POST" class="grid md:grid-cols-2 gap-4 mb-6">
                        <input type="hidden" name="action" value="<?php echo $editQuiz ? 'update_quiz' : 'add_quiz'; ?>">
                        <input type="hidden" name="course_id" value="<?php echo $viewCourseId; ?>">
                        <?php if ($editQuiz): ?>
                            <input type="hidden" name="quiz_id" value="<?php echo $editQuiz['id']; ?>">
                        <?php endif; ?>
                        <select name="section_id" id="quiz_section_select" required class="px-4 py-2 border-2 border-purple-200 dark:border-gray-700 rounded-lg focus:border-purple-500 focus:outline-none dark:bg-gray-700 dark:text-white" <?php echo empty($viewLessons) ? 'disabled' : ''; ?>>
                            <option value="">Select Section</option>
                            <?php foreach ($viewSections as $section): ?>
                                <option value="<?php echo $section['id']; ?>" <?php echo ($editQuiz && $editQuiz['section_id'] == $section['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($section['title']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="lesson_id" value="">
                        <input type="text" name="quiz_title" required placeholder="Quiz Title" value="<?php echo htmlspecialchars($editQuiz['title'] ?? ''); ?>"
                               class="px-4 py-2 border-2 border-purple-200 dark:border-gray-700 rounded-lg focus:border-purple-500 focus:outline-none dark:bg-gray-700 dark:text-white" <?php echo empty($viewLessons) ? 'disabled' : ''; ?>>
                        <textarea name="quiz_description" rows="2" placeholder="Quiz Description (optional)" class="md:col-span-2 px-4 py-2 border-2 border-purple-200 dark:border-gray-700 rounded-lg focus:border-purple-500 focus:outline-none dark:bg-gray-700 dark:text-white" <?php echo empty($viewLessons) ? 'disabled' : ''; ?>><?php echo htmlspecialchars($editQuiz['description'] ?? ''); ?></textarea>
                        <input type="number" name="duration" placeholder="Time limit (minutes)" value="<?php echo htmlspecialchars($editQuiz['duration'] ?? 0); ?>"
                               class="px-4 py-2 border-2 border-purple-200 dark:border-gray-700 rounded-lg focus:border-purple-500 focus:outline-none dark:bg-gray-700 dark:text-white" <?php echo empty($viewLessons) ? 'disabled' : ''; ?>>
                        <input type="number" name="total_marks" placeholder="Total marks" value="<?php echo htmlspecialchars($editQuiz['total_marks'] ?? 0); ?>"
                               class="px-4 py-2 border-2 border-purple-200 dark:border-gray-700 rounded-lg focus:border-purple-500 focus:outline-none dark:bg-gray-700 dark:text-white" <?php echo empty($viewLessons) ? 'disabled' : ''; ?>>
                        <input type="number" name="passing_score" placeholder="Passing score (%)" value="<?php echo htmlspecialchars($editQuiz['passing_score'] ?? 60); ?>"
                               class="px-4 py-2 border-2 border-purple-200 dark:border-gray-700 rounded-lg focus:border-purple-500 focus:outline-none dark:bg-gray-700 dark:text-white" <?php echo empty($viewLessons) ? 'disabled' : ''; ?>>
                        <select name="status" class="px-4 py-2 border-2 border-purple-200 dark:border-gray-700 rounded-lg focus:border-purple-500 focus:outline-none dark:bg-gray-700 dark:text-white" <?php echo empty($viewLessons) ? 'disabled' : ''; ?>>
                            <option value="draft" <?php echo ($editQuiz && !($editQuiz['is_published'] ?? 0)) ? 'selected' : ''; ?>>Draft</option>
                            <option value="published" <?php echo ($editQuiz && ($editQuiz['is_published'] ?? 0)) ? 'selected' : ''; ?>>Published</option>
                        </select>
                        <button type="submit" class="bg-purple-600 text-white px-6 py-2 rounded-lg font-semibold md:col-span-2" <?php echo empty($viewLessons) ? 'disabled' : ''; ?>>
                            <?php echo $editQuiz ? 'Update Quiz' : 'Create Quiz'; ?>
                        </button>
                    </form>

                    <div class="grid lg:grid-cols-2 gap-6">
                        <div class="border border-gray-200 dark:border-gray-700 rounded-2xl p-4">
                            <h4 class="text-lg font-bold text-gray-800 dark:text-white mb-3">Quiz Questions</h4>
                            <?php if (empty($viewQuizzes)): ?>
                                <p class="text-gray-500">No quizzes yet.</p>
                            <?php else: ?>
                                <form method="POST" class="space-y-3">
                                    <input type="hidden" name="action" value="<?php echo $editQuestion ? 'update_question' : 'add_question'; ?>">
                                    <?php if ($editQuestion): ?>
                                        <input type="hidden" name="question_id" value="<?php echo $editQuestion['id']; ?>">
                                    <?php endif; ?>
                                    <select name="quiz_id" required class="w-full px-4 py-2 border-2 border-purple-200 dark:border-gray-700 rounded-lg focus:border-purple-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                                        <option value="">Select Quiz</option>
                                        <?php foreach ($viewQuizzes as $quiz): ?>
                                            <option value="<?php echo $quiz['id']; ?>" <?php echo ($editQuestion && $editQuestion['quiz_id'] == $quiz['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($quiz['title']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <textarea name="question" required rows="2" placeholder="Question text"
                                              class="w-full px-4 py-2 border-2 border-purple-200 dark:border-gray-700 rounded-lg focus:border-purple-500 focus:outline-none dark:bg-gray-700 dark:text-white"><?php echo htmlspecialchars($editQuestion['question'] ?? ''); ?></textarea>
                                    <select name="question_type" id="question_type" class="w-full px-4 py-2 border-2 border-purple-200 dark:border-gray-700 rounded-lg focus:border-purple-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                                        <option value="single">Multiple choice (single)</option>
                                        <option value="multiple">Multiple choice (multiple)</option>
                                        <option value="true_false">True / False</option>
                                        <option value="short">Short answer</option>
                                    </select>
                                    <div id="option_fields" class="grid md:grid-cols-2 gap-3">
                                        <input type="text" name="options[]" placeholder="Option A" class="px-4 py-2 border-2 border-purple-200 dark:border-gray-700 rounded-lg focus:border-purple-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                                        <input type="text" name="options[]" placeholder="Option B" class="px-4 py-2 border-2 border-purple-200 dark:border-gray-700 rounded-lg focus:border-purple-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                                        <input type="text" name="options[]" placeholder="Option C" class="px-4 py-2 border-2 border-purple-200 dark:border-gray-700 rounded-lg focus:border-purple-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                                        <input type="text" name="options[]" placeholder="Option D" class="px-4 py-2 border-2 border-purple-200 dark:border-gray-700 rounded-lg focus:border-purple-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                                    </div>
                                    <input type="text" name="correct_answers" placeholder="Correct answer (A/B/C/D or comma separated)"
                                           class="w-full px-4 py-2 border-2 border-purple-200 dark:border-gray-700 rounded-lg focus:border-purple-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                                    <div class="grid grid-cols-2 gap-3">
                                        <input type="number" name="points" placeholder="Points" class="px-4 py-2 border-2 border-purple-200 dark:border-gray-700 rounded-lg focus:border-purple-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                                        <input type="number" name="order_number" placeholder="Order" class="px-4 py-2 border-2 border-purple-200 dark:border-gray-700 rounded-lg focus:border-purple-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                                    </div>
                                    <textarea name="explanation" rows="2" placeholder="Explanation (optional)" class="w-full px-4 py-2 border-2 border-purple-200 dark:border-gray-700 rounded-lg focus:border-purple-500 focus:outline-none dark:bg-gray-700 dark:text-white"><?php echo htmlspecialchars($editQuestion['explanation'] ?? ''); ?></textarea>
                                    <button type="submit" class="w-full bg-purple-600 text-white px-6 py-2 rounded-lg font-semibold">
                                        <?php echo $editQuestion ? 'Update Question' : 'Add Question'; ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <div class="border border-gray-200 dark:border-gray-700 rounded-2xl p-4">
                            <h4 class="text-lg font-bold text-gray-800 dark:text-white mb-3">Quiz List</h4>
                            <?php if (empty($viewQuizzes)): ?>
                                <p class="text-gray-500">No quizzes created.</p>
                            <?php else: ?>
                                <div class="space-y-3">
                                    <?php foreach ($viewQuizzes as $quiz): ?>
                                        <div class="p-4 rounded-2xl border border-gray-200 dark:border-gray-700">
                                            <div class="flex items-center justify-between">
                                                <div>
                                                    <p class="font-semibold text-gray-800 dark:text-white"><?php echo htmlspecialchars($quiz['title']); ?></p>
                                            <p class="text-xs text-gray-500"><?php echo htmlspecialchars($quiz['section_title'] ?? ''); ?></p>
                                                </div>
                                                <span class="text-xs px-2 py-1 rounded-full <?php echo ($quiz['is_published'] ?? 0) ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-700'; ?>">
                                                    <?php echo ($quiz['is_published'] ?? 0) ? 'Published' : 'Draft'; ?>
                                                </span>
                                            </div>
                                            <div class="mt-3 flex gap-2">
                                                <a href="/Iqra-College/teacher/list_courses.php?view=<?php echo $viewCourseId; ?>&edit_quiz=<?php echo $quiz['id']; ?>" class="px-3 py-1 rounded-lg bg-blue-500 text-white text-sm">Edit</a>
                                                <a href="/Iqra-College/student/quiz.php?id=<?php echo $quiz['id']; ?>" target="_blank" class="px-3 py-1 rounded-lg bg-purple-500 text-white text-sm">Preview</a>
                                                <form method="POST" onsubmit="return confirm('Delete quiz?')">
                                                    <input type="hidden" name="action" value="delete_quiz">
                                                    <input type="hidden" name="quiz_id" value="<?php echo $quiz['id']; ?>">
                                                    <button type="submit" class="px-3 py-1 rounded-lg bg-red-500 text-white text-sm">Delete</button>
                                                </form>
                                            </div>
                                            <?php if (!empty($viewQuestionsByQuiz[$quiz['id']])): ?>
                                                <div class="mt-3 space-y-2">
                                                    <?php foreach ($viewQuestionsByQuiz[$quiz['id']] as $q): ?>
                                                        <div class="text-xs text-gray-600 dark:text-gray-400 flex items-center justify-between">
                                                            <span><?php echo htmlspecialchars($q['question']); ?></span>
                                                            <div class="flex gap-2">
                                                                <a href="/Iqra-College/teacher/list_courses.php?view=<?php echo $viewCourseId; ?>&edit_question=<?php echo $q['id']; ?>" class="text-blue-600">Edit</a>
                                                                <form method="POST">
                                                                    <input type="hidden" name="action" value="delete_question">
                                                                    <input type="hidden" name="question_id" value="<?php echo $q['id']; ?>">
                                                                    <button type="submit" class="text-red-600">Delete</button>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            <?php endif; ?>

        <!-- Courses List -->
        <?php if (!$viewCourse): ?>
        <div class="mb-6 fade-in">
            <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center mb-6 gap-4">
                <div>
                    <h2 class="text-3xl font-extrabold text-gray-800 dark:text-white mb-2">
                        <i class="fas fa-list mr-2"></i>All Courses (<?php echo $totalCount; ?>)
                    </h2>
                    <p class="text-gray-600 dark:text-gray-400">Manage and organize all your courses</p>
                </div>
            </div>

            <!-- Search and Filter Section -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-lg p-6 mb-6 border border-gray-200 dark:border-gray-700">
                <form method="GET" action="" class="space-y-4">
                    <!-- Search Bar -->
                    <div class="relative">
                        <input type="text" 
                               name="search" 
                               value="<?php echo htmlspecialchars($searchQuery); ?>" 
                               placeholder="Search courses by title or description..." 
                               class="w-full px-4 py-3 pl-12 border-2 border-gray-200 dark:border-gray-700 rounded-lg focus:border-primary-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                        <i class="fas fa-search absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                    </div>

                    <!-- Filters Row -->
                    <div class="grid md:grid-cols-4 gap-4">
                        <!-- Status Filter -->
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                                <i class="fas fa-filter mr-1"></i>Status
                            </label>
                            <select name="status" class="w-full px-4 py-2 border-2 border-gray-200 dark:border-gray-700 rounded-lg focus:border-primary-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                                <option value="">All Statuses</option>
                                <option value="published" <?php echo $filterStatus === 'published' ? 'selected' : ''; ?>>Published</option>
                                <option value="draft" <?php echo $filterStatus === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                <option value="archived" <?php echo $filterStatus === 'archived' ? 'selected' : ''; ?>>Archived</option>
                            </select>
                        </div>

                        <!-- Category Filter -->
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                                <i class="fas fa-folder mr-1"></i>Category
                            </label>
                            <select name="category" class="w-full px-4 py-2 border-2 border-gray-200 dark:border-gray-700 rounded-lg focus:border-primary-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                                <option value="0">All Categories</option>
                                <?php foreach ($allCategories as $cat): ?>
                                    <option value="<?php echo $cat['id']; ?>" <?php echo $filterCategory == $cat['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Level Filter -->
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                                <i class="fas fa-signal mr-1"></i>Level
                            </label>
                            <select name="level" class="w-full px-4 py-2 border-2 border-gray-200 dark:border-gray-700 rounded-lg focus:border-primary-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                                <option value="">All Levels</option>
                                <option value="beginner" <?php echo $filterLevel === 'beginner' ? 'selected' : ''; ?>>Beginner</option>
                                <option value="intermediate" <?php echo $filterLevel === 'intermediate' ? 'selected' : ''; ?>>Intermediate</option>
                                <option value="advanced" <?php echo $filterLevel === 'advanced' ? 'selected' : ''; ?>>Advanced</option>
                            </select>
                        </div>

                        <!-- Sort Filter -->
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                                <i class="fas fa-sort mr-1"></i>Sort By
                            </label>
                            <select name="sort" class="w-full px-4 py-2 border-2 border-gray-200 dark:border-gray-700 rounded-lg focus:border-primary-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                                <option value="date_desc" <?php echo $sortBy === 'date_desc' ? 'selected' : ''; ?>>Newest First</option>
                                <option value="date_asc" <?php echo $sortBy === 'date_asc' ? 'selected' : ''; ?>>Oldest First</option>
                                <option value="name_asc" <?php echo $sortBy === 'name_asc' ? 'selected' : ''; ?>>Name (A-Z)</option>
                                <option value="name_desc" <?php echo $sortBy === 'name_desc' ? 'selected' : ''; ?>>Name (Z-A)</option>
                                <option value="price_asc" <?php echo $sortBy === 'price_asc' ? 'selected' : ''; ?>>Price (Low-High)</option>
                                <option value="price_desc" <?php echo $sortBy === 'price_desc' ? 'selected' : ''; ?>>Price (High-Low)</option>
                            </select>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex gap-3">
                        <button type="submit" class="bg-gradient-to-r from-primary-500 to-primary-600 text-white px-6 py-2 rounded-lg font-semibold hover:from-primary-600 hover:to-primary-700 transition-all shadow-lg">
                            <i class="fas fa-search mr-2"></i>Apply Filters
                        </button>
                        <a href="/Iqra-College/teacher/list_courses.php" class="bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 px-6 py-2 rounded-lg font-semibold hover:bg-gray-300 dark:hover:bg-gray-600 transition-all">
                            <i class="fas fa-redo mr-2"></i>Reset
                        </a>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php if (empty($courses)): ?>
                <div class="col-span-full text-center py-12 bg-white dark:bg-gray-800 rounded-2xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <i class="fas fa-<?php echo (!empty($searchQuery) || !empty($filterStatus) || $filterCategory > 0 || !empty($filterLevel)) ? 'search' : 'book-open'; ?> text-6xl text-gray-400 dark:text-gray-600 mb-4"></i>
                    <?php if (!empty($searchQuery) || !empty($filterStatus) || $filterCategory > 0 || !empty($filterLevel)): ?>
                        <p class="text-xl text-gray-500 dark:text-gray-400 mb-2">No courses found matching your filters.</p>
                        <p class="text-sm text-gray-400 dark:text-gray-500 mb-4">Try adjusting your search criteria or filters.</p>
                        <a href="/Iqra-College/teacher/list_courses.php" 
                           class="inline-block bg-gradient-to-r from-primary-500 to-primary-600 text-white px-6 py-3 rounded-lg font-semibold hover:from-primary-600 hover:to-primary-700 transition-all shadow-lg">
                            <i class="fas fa-redo mr-2"></i>Clear Filters
                        </a>
                    <?php else: ?>
                        <p class="text-xl text-gray-500 dark:text-gray-400 mb-2">No courses created yet.</p>
                        <p class="text-sm text-gray-400 dark:text-gray-500 mb-4">Sample courses are being generated automatically, or you can create your own.</p>
                        <div class="flex gap-3 justify-center">
                    <a href="/Iqra-College/teacher/create_course.php" 
                       class="inline-block bg-gradient-to-r from-green-500 to-emerald-600 text-white px-6 py-3 rounded-lg font-semibold hover:from-green-600 hover:to-emerald-700 transition-all shadow-lg">
                        <i class="fas fa-plus mr-2"></i>Create Your First Course
                    </a>
                            <form method="POST" action="" class="inline" onsubmit="return confirm('This will create 6 sample courses. Continue?');">
                                <input type="hidden" name="action" value="generate_samples">
                                <button type="submit" 
                                        class="bg-gradient-to-r from-purple-500 to-purple-600 text-white px-6 py-3 rounded-lg font-semibold hover:from-purple-600 hover:to-purple-700 transition-all shadow-lg">
                                    <i class="fas fa-magic mr-2"></i>Generate Sample Courses
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
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
                    <div class="course-card bg-white dark:bg-gray-800 rounded-3xl shadow-xl overflow-hidden hover:shadow-2xl transition-all border-2 <?php echo ($newCourseId > 0 && (int)$course['id'] === $newCourseId) ? 'border-green-500 dark:border-green-600 ring-4 ring-green-200 dark:ring-green-900/50' : 'border-gray-200 dark:border-gray-700 hover:border-primary-400 dark:hover:border-primary-600'; ?> fade-in cursor-pointer"
                         data-href="/Iqra-College/teacher/list_courses.php?view=<?php echo $course['id']; ?>"
                         id="course-<?php echo $course['id']; ?>">
                        <?php if ($newCourseId > 0 && (int)$course['id'] === $newCourseId): ?>
                            <div class="absolute top-3 left-3 z-10 bg-gradient-to-r from-green-500 to-emerald-600 text-white px-4 py-2 rounded-lg text-sm font-bold shadow-lg flex items-center animate-pulse">
                                <i class="fas fa-star mr-2"></i>New Course!
                            </div>
                        <?php endif; ?>
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

                            <!-- Course Statistics -->
                            <div class="grid grid-cols-2 gap-2 mb-4 p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                                <div class="text-center">
                                    <div class="text-lg font-bold text-primary-600 dark:text-primary-400">
                                        <?php echo intval($course['enrolled_count'] ?? 0); ?>
                                    </div>
                                    <div class="text-xs text-gray-600 dark:text-gray-400">
                                        <i class="fas fa-users mr-1"></i>Students
                                    </div>
                                </div>
                                <div class="text-center">
                                    <div class="text-lg font-bold text-green-600 dark:text-green-400">
                                        <?php echo intval($course['lessons_count'] ?? 0); ?>
                                    </div>
                                    <div class="text-xs text-gray-600 dark:text-gray-400">
                                        <i class="fas fa-book mr-1"></i>Lessons
                                    </div>
                                </div>
                                <div class="text-center">
                                    <div class="text-lg font-bold text-purple-600 dark:text-purple-400">
                                        <?php echo intval($course['quizzes_count'] ?? 0); ?>
                                    </div>
                                    <div class="text-xs text-gray-600 dark:text-gray-400">
                                        <i class="fas fa-question-circle mr-1"></i>Quizzes
                                    </div>
                                </div>
                                <div class="text-center">
                                    <div class="text-lg font-bold text-blue-600 dark:text-blue-400">
                                        <?php echo intval($course['sections_count'] ?? 0); ?>
                                    </div>
                                    <div class="text-xs text-gray-600 dark:text-gray-400">
                                        <i class="fas fa-list mr-1"></i>Sections
                                    </div>
                                </div>
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
                                    <a href="/Iqra-College/teacher/create_course.php?edit=<?php echo $course['id']; ?>" 
                                       class="bg-gradient-to-r from-indigo-500 to-indigo-600 text-white px-4 py-2 rounded-lg text-sm font-semibold hover:from-indigo-600 hover:to-indigo-700 transition-all shadow-lg">
                                        <i class="fas fa-pen mr-1"></i>Edit
                                    </a>
                                    <a href="/Iqra-College/teacher/list_courses.php?view=<?php echo $course['id']; ?>" 
                                       class="bg-gradient-to-r from-blue-500 to-blue-600 text-white px-4 py-2 rounded-lg text-sm font-semibold hover:from-blue-600 hover:to-blue-700 transition-all shadow-lg">
                                        <i class="fas fa-eye mr-1"></i>Details
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

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="mt-8 flex justify-center items-center gap-2">
                <?php
                $queryParams = $_GET;
                unset($queryParams['page']);
                $queryString = http_build_query($queryParams);
                $baseUrl = '/Iqra-College/teacher/list_courses.php' . (!empty($queryString) ? '?' . $queryString : '');
                ?>
                
                <!-- Previous Button -->
                <?php if ($page > 1): ?>
                    <?php
                    $prevQueryParams = $queryParams;
                    $prevQueryParams['page'] = $page - 1;
                    $prevUrl = '/Iqra-College/teacher/list_courses.php?' . http_build_query($prevQueryParams);
                    ?>
                    <a href="<?php echo htmlspecialchars($prevUrl); ?>" 
                       class="px-4 py-2 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-700 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition-all">
                        <i class="fas fa-chevron-left mr-1"></i>Previous
                    </a>
                <?php else: ?>
                    <span class="px-4 py-2 bg-gray-100 dark:bg-gray-800 border border-gray-300 dark:border-gray-700 rounded-lg text-gray-400 dark:text-gray-600 cursor-not-allowed">
                        <i class="fas fa-chevron-left mr-1"></i>Previous
                    </span>
                <?php endif; ?>

                <!-- Page Numbers -->
                <div class="flex gap-2">
                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    
                    if ($startPage > 1): 
                        $firstQueryParams = $queryParams;
                        $firstQueryParams['page'] = 1;
                        $firstUrl = '/Iqra-College/teacher/list_courses.php?' . http_build_query($firstQueryParams);
                    ?>
                        <a href="<?php echo htmlspecialchars($firstUrl); ?>" 
                           class="px-4 py-2 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-700 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-primary-50 dark:hover:bg-gray-700 transition-all">
                            1
                        </a>
                        <?php if ($startPage > 2): ?>
                            <span class="px-4 py-2 text-gray-500">...</span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php for ($i = $startPage; $i <= $endPage; $i++): 
                        $pageQueryParams = $queryParams;
                        $pageQueryParams['page'] = $i;
                        $pageUrl = '/Iqra-College/teacher/list_courses.php?' . http_build_query($pageQueryParams);
                    ?>
                        <a href="<?php echo htmlspecialchars($pageUrl); ?>" 
                           class="px-4 py-2 rounded-lg transition-all <?php echo $i == $page 
                               ? 'bg-primary-600 text-white font-bold' 
                               : 'bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-primary-50 dark:hover:bg-gray-700'; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($endPage < $totalPages): 
                        $lastQueryParams = $queryParams;
                        $lastQueryParams['page'] = $totalPages;
                        $lastUrl = '/Iqra-College/teacher/list_courses.php?' . http_build_query($lastQueryParams);
                    ?>
                        <?php if ($endPage < $totalPages - 1): ?>
                            <span class="px-4 py-2 text-gray-500">...</span>
                        <?php endif; ?>
                        <a href="<?php echo htmlspecialchars($lastUrl); ?>" 
                           class="px-4 py-2 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-700 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-primary-50 dark:hover:bg-gray-700 transition-all">
                            <?php echo $totalPages; ?>
                        </a>
                    <?php endif; ?>
                </div>

                <!-- Next Button -->
                <?php if ($page < $totalPages): 
                    $nextQueryParams = $queryParams;
                    $nextQueryParams['page'] = $page + 1;
                    $nextUrl = '/Iqra-College/teacher/list_courses.php?' . http_build_query($nextQueryParams);
                ?>
                    <a href="<?php echo htmlspecialchars($nextUrl); ?>" 
                       class="px-4 py-2 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-700 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition-all">
                        Next<i class="fas fa-chevron-right ml-1"></i>
                    </a>
                <?php else: ?>
                    <span class="px-4 py-2 bg-gray-100 dark:bg-gray-800 border border-gray-300 dark:border-gray-700 rounded-lg text-gray-400 dark:text-gray-600 cursor-not-allowed">
                        Next<i class="fas fa-chevron-right ml-1"></i>
                    </span>
                <?php endif; ?>
            </div>
            
            <div class="mt-4 text-center text-sm text-gray-600 dark:text-gray-400">
                Showing <?php echo $offset + 1; ?> - <?php echo min($offset + $perPage, $totalCount); ?> of <?php echo $totalCount; ?> courses
            </div>
        <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <script>
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

        // Make course card clickable to open details
        document.querySelectorAll('.course-card').forEach(card => {
            card.addEventListener('click', (event) => {
                if (event.target.closest('a, button, form, input, textarea, select')) {
                    return;
                }
                const href = card.getAttribute('data-href');
                if (href) {
                    window.location.href = href;
                }
            });
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
        
        // Ensure dark mode toggle works on this page
        document.addEventListener('DOMContentLoaded', function() {
            const html = document.documentElement;
            const darkModeCookie = document.cookie.split('; ').find(row => row.startsWith('dark_mode='));
            if (darkModeCookie && darkModeCookie.split('=')[1] === 'enabled') {
                html.classList.add('dark');
            } else {
                html.classList.remove('dark');
            }

            // Auto-focus search input when page loads if there's a search query
            const searchInput = document.querySelector('input[name="search"]');
            if (searchInput && searchInput.value) {
                searchInput.focus();
                searchInput.setSelectionRange(searchInput.value.length, searchInput.value.length);
            }

            // Add smooth scroll to top when pagination is clicked
            document.querySelectorAll('a[href*="page="]').forEach(link => {
                link.addEventListener('click', function(e) {
                    // Allow normal navigation
                    // Optionally scroll to top smoothly
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                });
            });

            // Scroll to newly created course if present
            <?php if ($newCourseId > 0): ?>
            const newCourseElement = document.getElementById('course-<?php echo $newCourseId; ?>');
            if (newCourseElement) {
                // Scroll to the new course after a short delay
                setTimeout(() => {
                    newCourseElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    // Add a flash animation
                    newCourseElement.style.animation = 'pulse 2s ease-in-out';
                    
                    // Remove highlight after 5 seconds
                    setTimeout(() => {
                        newCourseElement.classList.remove('ring-4', 'ring-green-200', 'dark:ring-green-900/50');
                        newCourseElement.classList.add('border-gray-200', 'dark:border-gray-700');
                        const newBadge = newCourseElement.querySelector('.animate-pulse');
                        if (newBadge) {
                            newBadge.style.display = 'none';
                        }
                    }, 5000);
                }, 300);
            }
            <?php endif; ?>
            
            // Smooth scroll for navigation tabs
            document.querySelectorAll('.nav-tab').forEach(tab => {
                tab.addEventListener('click', function(e) {
                    e.preventDefault();
                    const targetId = this.getAttribute('href').substring(1);
                    const targetElement = document.getElementById(targetId);
                    
                    if (targetElement) {
                        // Update active tab
                        document.querySelectorAll('.nav-tab').forEach(t => {
                            t.classList.remove('bg-primary-600', 'text-white', 'shadow');
                            t.classList.add('bg-gray-100', 'dark:bg-gray-700', 'text-gray-700', 'dark:text-gray-300');
                        });
                        this.classList.remove('bg-gray-100', 'dark:bg-gray-700', 'text-gray-700', 'dark:text-gray-300');
                        this.classList.add('bg-primary-600', 'text-white', 'shadow');
                        
                        // Smooth scroll to section
                        const offset = 100;
                        const elementPosition = targetElement.getBoundingClientRect().top;
                        const offsetPosition = elementPosition + window.pageYOffset - offset;
                        
                        window.scrollTo({
                            top: offsetPosition,
                            behavior: 'smooth'
                        });
                    }
                });
            });
            
            // Update active tab on scroll
            const sections = ['overview', 'sections', 'lessons', 'quizzes', 'enrollments'];
            const updateActiveTab = () => {
                const scrollPos = window.scrollY + 150;
                
                sections.forEach(section => {
                    const element = document.getElementById(section);
                    if (element) {
                        const top = element.offsetTop;
                        const bottom = top + element.offsetHeight;
                        
                        if (scrollPos >= top && scrollPos < bottom) {
                            document.querySelectorAll('.nav-tab').forEach(tab => {
                                if (tab.getAttribute('data-section') === section) {
                                    tab.classList.remove('bg-gray-100', 'dark:bg-gray-700', 'text-gray-700', 'dark:text-gray-300');
                                    tab.classList.add('bg-primary-600', 'text-white', 'shadow');
                                } else {
                                    tab.classList.remove('bg-primary-600', 'text-white', 'shadow');
                                    tab.classList.add('bg-gray-100', 'dark:bg-gray-700', 'text-gray-700', 'dark:text-gray-300');
                                }
                            });
                        }
                    }
                });
            };
            
            window.addEventListener('scroll', updateActiveTab);
            updateActiveTab(); // Initial check
        });

        // Add loading state to filter form submission
        const filterForm = document.querySelector('form[method="GET"]');
        if (filterForm) {
            filterForm.addEventListener('submit', function() {
                const submitBtn = filterForm.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Loading...';
                    submitBtn.disabled = true;
                }
            });
        }
    </script>
</body>
</html>
