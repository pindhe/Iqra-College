<?php
/**
 * Teacher - Assignments
 * View and grade student assignment submissions
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

function ensureAssignmentSchema($pdo) {
    $assignmentColumns = [
        "ALTER TABLE assignments ADD COLUMN section_id INT NULL",
        "ALTER TABLE assignments ADD COLUMN lesson_id INT NULL",
        "ALTER TABLE assignments ADD COLUMN description TEXT NULL",
        "ALTER TABLE assignments ADD COLUMN file_path VARCHAR(255) NULL",
        "ALTER TABLE assignments ADD COLUMN total_marks INT NULL DEFAULT 100",
        "ALTER TABLE assignments ADD COLUMN expire_date DATETIME NULL",
        "ALTER TABLE assignments ADD COLUMN created_by INT NULL",
        "ALTER TABLE assignments ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP",
        "ALTER TABLE assignments ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ];

    foreach ($assignmentColumns as $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            // Ignore if column already exists or table is missing.
        }
    }

    $submissionColumns = [
        "ALTER TABLE assignment_submissions ADD COLUMN file_path VARCHAR(255) NULL",
        "ALTER TABLE assignment_submissions ADD COLUMN content TEXT NULL",
        "ALTER TABLE assignment_submissions ADD COLUMN submitted_at DATETIME NULL",
        "ALTER TABLE assignment_submissions ADD COLUMN score INT NULL",
        "ALTER TABLE assignment_submissions ADD COLUMN feedback TEXT NULL",
        "ALTER TABLE assignment_submissions ADD COLUMN status VARCHAR(20) NULL",
        "ALTER TABLE assignment_submissions ADD COLUMN graded_by INT NULL",
        "ALTER TABLE assignment_submissions ADD COLUMN graded_at DATETIME NULL",
    ];

    foreach ($submissionColumns as $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            // Ignore if column already exists or table is missing.
        }
    }
}

function getTableColumns($pdo, $tableName) {
    static $cache = [];
    if (isset($cache[$tableName])) {
        return $cache[$tableName];
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM {$tableName}");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $cache[$tableName] = $columns;
        return $columns;
    } catch (PDOException $e) {
        $cache[$tableName] = [];
        return [];
    }
}

function uploadAssignmentFile($file, $uploadDir) {
    if (empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return '';
    }

    $allowedExtensions = ['pdf', 'doc', 'docx'];
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($extension, $allowedExtensions, true)) {
        return '';
    }

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }

    $fileName = 'assignment_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
    $destination = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fileName;

    if (move_uploaded_file($file['tmp_name'], $destination)) {
        return $fileName;
    }

    return '';
}

ensureAssignmentSchema($pdo);

// Courses, sections, lessons for selection
try {
    $stmt = $pdo->prepare("SELECT * FROM courses WHERE teacher_id = ? ORDER BY id DESC");
    $stmt->execute([$teacherId]);
    $teacherCourses = $stmt->fetchAll();
} catch (PDOException $e) {
    $teacherCourses = [];
}

try {
    $stmt = $pdo->prepare("SELECT s.*, c.title as course_title FROM sections s JOIN courses c ON s.course_id = c.id WHERE c.teacher_id = ? ORDER BY s.order_number ASC, s.id ASC");
    $stmt->execute([$teacherId]);
    $teacherSections = $stmt->fetchAll();
} catch (PDOException $e) {
    $teacherSections = [];
}

try {
    $stmt = $pdo->prepare("SELECT l.*, s.title as section_title, c.title as course_title FROM lessons l 
                          JOIN courses c ON l.course_id = c.id 
                          LEFT JOIN sections s ON l.section_id = s.id 
                          WHERE c.teacher_id = ? ORDER BY l.id ASC");
    $stmt->execute([$teacherId]);
    $teacherLessons = $stmt->fetchAll();
} catch (PDOException $e) {
    $teacherLessons = [];
}

// Handle assignment create/update/delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['save_assignment', 'update_assignment', 'delete_assignment'], true)) {
    $action = $_POST['action'];
    $assignmentId = intval($_POST['assignment_id'] ?? 0);

    if ($action === 'delete_assignment') {
        if ($assignmentId > 0) {
            try {
                $stmt = $pdo->prepare("SELECT a.file_path FROM assignments a JOIN courses c ON a.course_id = c.id WHERE a.id = ? AND c.teacher_id = ?");
                $stmt->execute([$assignmentId, $teacherId]);
                $assignment = $stmt->fetch();

                if ($assignment) {
                    $pdo->prepare("DELETE FROM assignment_submissions WHERE assignment_id = ?")->execute([$assignmentId]);
                    $pdo->prepare("DELETE a FROM assignments a JOIN courses c ON a.course_id = c.id WHERE a.id = ? AND c.teacher_id = ?")->execute([$assignmentId, $teacherId]);

                    if (!empty($assignment['file_path'])) {
                        $existingFile = __DIR__ . '/../uploads/assignments/' . $assignment['file_path'];
                        if (is_file($existingFile)) {
                            @unlink($existingFile);
                        }
                    }
                    $success = 'Assignment deleted successfully.';
                } else {
                    $error = 'Assignment not found.';
                }
            } catch (PDOException $e) {
                $error = 'Failed to delete assignment.';
            }
        } else {
            $error = 'Invalid assignment.';
        }
    } else {
        $courseId = intval($_POST['course_id'] ?? 0);
        $sectionId = intval($_POST['section_id'] ?? 0);
        $lessonId = intval($_POST['lesson_id'] ?? 0);
        $title = sanitize($_POST['title'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $totalMarks = intval($_POST['total_marks'] ?? 100);
        $expireDate = trim($_POST['expire_date'] ?? '');
        $expireDate = $expireDate !== '' ? $expireDate : null;

        if ($courseId <= 0 || $sectionId <= 0 || $lessonId <= 0 || $title === '') {
            $error = 'Please select course, section, and lesson, then enter a title.';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id FROM courses WHERE id = ? AND teacher_id = ?");
                $stmt->execute([$courseId, $teacherId]);
                if (!$stmt->fetch()) {
                    $error = 'Invalid course selection.';
                } else {
                    $filePath = '';
                    $uploadDir = __DIR__ . '/../uploads/assignments';

                    if (!empty($_FILES['assignment_file']['name'])) {
                        $filePath = uploadAssignmentFile($_FILES['assignment_file'], $uploadDir);
                        if ($filePath === '') {
                            $error = 'Invalid file type. Only PDF, DOC, DOCX are allowed.';
                        }
                    }

                    if (!$error) {
                        $columns = getTableColumns($pdo, 'assignments');
                        $data = [
                            'course_id' => $courseId,
                            'section_id' => $sectionId,
                            'lesson_id' => $lessonId,
                            'title' => $title,
                            'description' => $description,
                            'file_path' => $filePath,
                            'total_marks' => $totalMarks,
                            'max_score' => $totalMarks,
                            'expire_date' => $expireDate,
                            'due_date' => $expireDate,
                            'created_by' => $teacherId,
                        ];

                        if ($action === 'update_assignment') {
                            if ($assignmentId <= 0) {
                                $error = 'Invalid assignment selected.';
                            } else {
                                if ($filePath === '') {
                                    unset($data['file_path']);
                                }
                                $setParts = [];
                                $params = [];
                                foreach ($data as $column => $value) {
                                    if (in_array($column, $columns, true)) {
                                        $setParts[] = "{$column} = ?";
                                        $params[] = $value;
                                    }
                                }
                                if (!empty($setParts)) {
                                    $params[] = $assignmentId;
                                    $params[] = $teacherId;
                                    $sql = "UPDATE assignments a JOIN courses c ON a.course_id = c.id SET " . implode(', ', $setParts) . " WHERE a.id = ? AND c.teacher_id = ?";
                                    $stmt = $pdo->prepare($sql);
                                    $stmt->execute($params);
                                    $success = 'Assignment updated successfully.';
                                } else {
                                    $error = 'Unable to update assignment.';
                                }
                            }
                        } else {
                            $fields = [];
                            $placeholders = [];
                            $params = [];
                            foreach ($data as $column => $value) {
                                if (in_array($column, $columns, true)) {
                                    $fields[] = $column;
                                    $placeholders[] = '?';
                                    $params[] = $value;
                                }
                            }
                            if (!empty($fields)) {
                                $sql = "INSERT INTO assignments (" . implode(',', $fields) . ") VALUES (" . implode(',', $placeholders) . ")";
                                $stmt = $pdo->prepare($sql);
                                $stmt->execute($params);
                                $success = 'Assignment created successfully.';
                            } else {
                                $error = 'Unable to save assignment.';
                            }
                        }
                    }
                }
            } catch (PDOException $e) {
                $error = 'Failed to save assignment.';
            }
        }
    }
}

// Handle grading submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'grade') {
    $submissionId = intval($_POST['submission_id'] ?? 0);
    $score = intval($_POST['score'] ?? 0);
    $feedback = sanitize($_POST['feedback'] ?? '');
    $maxScore = intval($_POST['max_score'] ?? 100);
    
    if ($submissionId > 0 && $score >= 0 && $score <= $maxScore) {
        try {
            $stmt = $pdo->prepare("UPDATE assignment_submissions 
                                  SET score = ?, feedback = ?, graded_by = ?, graded_at = CURRENT_TIMESTAMP, status = 'graded'
                                  WHERE id = ?");
            $stmt->execute([$score, $feedback, $teacherId, $submissionId]);
            $success = 'Assignment graded successfully!';
        } catch (PDOException $e) {
            $error = 'Failed to grade assignment. Please try again.';
        }
    } else {
        $error = 'Invalid score. Please enter a score between 0 and ' . $maxScore . '.';
    }
}

// Get all assignments for teacher's courses
try {
    $stmt = $pdo->prepare("SELECT a.*, c.title as course_title, c.id as course_id,
                          sct.title as section_title, l.title as lesson_title,
                          COUNT(sub.id) as submission_count,
                          COUNT(CASE WHEN sub.status = 'submitted' OR sub.status IS NULL THEN 1 END) as pending_count
                          FROM assignments a
                          JOIN courses c ON a.course_id = c.id
                          LEFT JOIN sections sct ON a.section_id = sct.id
                          LEFT JOIN lessons l ON a.lesson_id = l.id
                          LEFT JOIN assignment_submissions sub ON a.id = sub.assignment_id
                          WHERE c.teacher_id = ?
                          GROUP BY a.id
                          ORDER BY COALESCE(a.expire_date, a.due_date) ASC, a.id DESC");
    $stmt->execute([$teacherId]);
    $assignments = $stmt->fetchAll();
} catch (PDOException $e) {
    $assignments = [];
}

// Get pending submissions
try {
    $stmt = $pdo->prepare("SELECT s.*, a.title as assignment_title, a.total_marks, a.max_score, a.expire_date, a.due_date,
                          c.title as course_title, u.name as student_name, u.id as student_code
                          FROM assignment_submissions s
                          JOIN assignments a ON s.assignment_id = a.id
                          JOIN courses c ON a.course_id = c.id
                          JOIN users u ON s.student_id = u.id
                          WHERE c.teacher_id = ? AND (s.status = 'submitted' OR s.status IS NULL)
                          ORDER BY s.submitted_at ASC");
    $stmt->execute([$teacherId]);
    $pendingSubmissions = $stmt->fetchAll();
} catch (PDOException $e) {
    $pendingSubmissions = [];
}

// All submissions grouped by assignment
try {
    $stmt = $pdo->prepare("SELECT s.*, a.id as assignment_id, a.title as assignment_title, a.total_marks, a.max_score,
                          c.title as course_title, u.name as student_name, u.id as student_code
                          FROM assignment_submissions s
                          JOIN assignments a ON s.assignment_id = a.id
                          JOIN courses c ON a.course_id = c.id
                          JOIN users u ON s.student_id = u.id
                          WHERE c.teacher_id = ?
                          ORDER BY s.submitted_at DESC");
    $stmt->execute([$teacherId]);
    $allSubmissions = $stmt->fetchAll();
} catch (PDOException $e) {
    $allSubmissions = [];
}

$submissionMap = [];
foreach ($allSubmissions as $submission) {
    $submissionMap[$submission['assignment_id']][] = $submission;
}

$pageTitle = 'Assignments';
$currentPage = 'assignments';
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
                            <p class="text-sm text-gray-500 dark:text-gray-400">Grade student submissions</p>
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

            <!-- Create / Update Assignment -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 mb-8 fade-in border border-gray-200 dark:border-gray-700">
                <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
                    <div>
                        <h2 class="text-2xl font-bold text-gray-800 dark:text-white">Create Assignment</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Upload assignments linked to course, section, and lesson.</p>
                    </div>
                    <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                        <i class="fas fa-info-circle"></i>
                        <span>PDF / DOC / DOCX only</span>
                    </div>
                </div>

                <form method="POST" enctype="multipart/form-data" id="assignmentForm" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <input type="hidden" name="action" id="assignment_action" value="save_assignment">
                    <input type="hidden" name="assignment_id" id="assignment_id" value="">

                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Course *</label>
                            <select name="course_id" id="course_select" required class="w-full px-4 py-2 border-2 border-gray-200 dark:border-gray-700 rounded-lg focus:border-primary-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                                <option value="">Select course</option>
                                <?php foreach ($teacherCourses as $course): ?>
                                    <option value="<?php echo (int)$course['id']; ?>"><?php echo htmlspecialchars($course['title']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Section *</label>
                            <select name="section_id" id="section_select" required class="w-full px-4 py-2 border-2 border-gray-200 dark:border-gray-700 rounded-lg focus:border-primary-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                                <option value="">Select section</option>
                                <?php foreach ($teacherSections as $section): ?>
                                    <option value="<?php echo (int)$section['id']; ?>" data-course="<?php echo (int)$section['course_id']; ?>">
                                        <?php echo htmlspecialchars($section['title']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Lesson *</label>
                            <select name="lesson_id" id="lesson_select" required class="w-full px-4 py-2 border-2 border-gray-200 dark:border-gray-700 rounded-lg focus:border-primary-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                                <option value="">Select lesson</option>
                                <?php foreach ($teacherLessons as $lesson): ?>
                                    <option value="<?php echo (int)$lesson['id']; ?>" data-course="<?php echo (int)$lesson['course_id']; ?>" data-section="<?php echo (int)$lesson['section_id']; ?>">
                                        <?php echo htmlspecialchars($lesson['title']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="space-y-4 lg:col-span-2">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Assignment Title *</label>
                            <input type="text" name="title" id="assignment_title" required class="w-full px-4 py-2 border-2 border-gray-200 dark:border-gray-700 rounded-lg focus:border-primary-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Description / Instructions</label>
                            <textarea name="description" id="assignment_description" rows="4" class="w-full px-4 py-2 border-2 border-gray-200 dark:border-gray-700 rounded-lg focus:border-primary-500 focus:outline-none dark:bg-gray-700 dark:text-white"></textarea>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Total Marks</label>
                                <input type="number" name="total_marks" id="assignment_marks" min="0" value="100" class="w-full px-4 py-2 border-2 border-gray-200 dark:border-gray-700 rounded-lg focus:border-primary-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Expire Date / Deadline</label>
                                <input type="datetime-local" name="expire_date" id="assignment_expire" class="w-full px-4 py-2 border-2 border-gray-200 dark:border-gray-700 rounded-lg focus:border-primary-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Assignment File (PDF/DOC)</label>
                            <input type="file" name="assignment_file" id="assignment_file" accept=".pdf,.doc,.docx" class="w-full px-4 py-2 border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-900 text-gray-700 dark:text-gray-300">
                        </div>
                        <div class="flex flex-wrap gap-3">
                            <button type="submit" class="bg-gradient-to-r from-primary-500 to-purple-600 text-white px-6 py-3 rounded-lg font-bold hover:from-primary-600 hover:to-purple-700 transition-all">
                                <i class="fas fa-upload mr-2"></i>Save Assignment
                            </button>
                            <button type="button" id="assignment_reset" class="bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200 px-6 py-3 rounded-lg font-bold hover:bg-gray-300 dark:hover:bg-gray-600 transition-all">
                                Reset
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Pending Submissions -->
            <?php if (!empty($pendingSubmissions)): ?>
                <div class="mb-8 fade-in">
                    <h2 class="text-2xl font-bold text-gray-800 dark:text-white mb-4 flex items-center">
                        <i class="fas fa-exclamation-triangle text-orange-500 mr-2"></i>
                        Pending Grading (<?php echo count($pendingSubmissions); ?>)
                    </h2>
                    <div class="space-y-4">
                        <?php foreach ($pendingSubmissions as $submission): ?>
                            <?php
                                $pendingMaxScore = intval($submission['total_marks'] ?? $submission['max_score'] ?? 100);
                                $pendingDueDate = $submission['expire_date'] ?? $submission['due_date'] ?? null;
                            ?>
                            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 border-2 border-orange-200 dark:border-orange-800">
                                <div class="flex items-start justify-between mb-4">
                                    <div class="flex-1">
                                        <h3 class="text-xl font-bold text-gray-800 dark:text-white mb-2">
                                            <?php echo htmlspecialchars($submission['assignment_title']); ?>
                                        </h3>
                                        <div class="flex items-center space-x-4 text-sm text-gray-600 dark:text-gray-400">
                                            <span><i class="fas fa-book mr-1"></i><?php echo htmlspecialchars($submission['course_title']); ?></span>
                                            <span><i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($submission['student_name']); ?> (ID: <?php echo (int)$submission['student_code']; ?>)</span>
                                            <span><i class="fas fa-clock mr-1"></i><?php echo date('M d, Y H:i', strtotime($submission['submitted_at'])); ?></span>
                                            <?php if ($pendingDueDate): ?>
                                                <span><i class="fas fa-calendar mr-1"></i>Due: <?php echo date('M d, Y H:i', strtotime($pendingDueDate)); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <button onclick="openGradeModal(<?php echo htmlspecialchars(json_encode($submission)); ?>)" 
                                            class="bg-gradient-to-r from-primary-500 to-purple-600 text-white px-6 py-2 rounded-lg font-semibold hover:from-primary-600 hover:to-purple-700 transition-all">
                                        <i class="fas fa-check mr-2"></i>Grade Now
                                    </button>
                                </div>
                                
                                <?php if ($submission['content']): ?>
                                    <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-4 mb-4">
                                        <p class="text-gray-700 dark:text-gray-300 whitespace-pre-wrap"><?php echo htmlspecialchars($submission['content']); ?></p>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($submission['file_path']): ?>
                                    <div class="mb-4">
                                        <a href="/Iqra-College/uploads/assignments/<?php echo htmlspecialchars($submission['file_path']); ?>" 
                                           target="_blank"
                                           class="inline-flex items-center space-x-2 bg-blue-100 dark:bg-blue-900/20 text-blue-700 dark:text-blue-400 px-4 py-2 rounded-lg hover:bg-blue-200 dark:hover:bg-blue-900/30 transition-all">
                                            <i class="fas fa-file-download"></i>
                                            <span>Download Submission</span>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- All Assignments -->
            <div class="fade-in">
                <h2 class="text-2xl font-bold text-gray-800 dark:text-white mb-4">
                    <i class="fas fa-list mr-2"></i>All Assignments
                </h2>
                
                <?php if (empty($assignments)): ?>
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-12 text-center">
                        <i class="fas fa-tasks text-6xl text-gray-400 mb-4"></i>
                        <p class="text-xl text-gray-600 dark:text-gray-400">No assignments found.</p>
                    </div>
                <?php else: ?>
                    <div class="grid md:grid-cols-2 gap-6">
                        <?php foreach ($assignments as $assignment):
                            $dueDateValue = $assignment['expire_date'] ?? $assignment['due_date'] ?? null;
                            $dueDate = $dueDateValue ? new DateTime($dueDateValue) : null;
                            $now = new DateTime();
                            $isOverdue = $dueDate && $dueDate < $now;
                            $displayMarks = intval($assignment['total_marks'] ?? $assignment['max_score'] ?? 100);
                            $assignmentSubmissions = $submissionMap[$assignment['id']] ?? [];
                        ?>
                            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 border-2 border-gray-200 dark:border-gray-700 hover:border-primary-400 dark:hover:border-primary-600 transition-all">
                                <div class="flex items-start justify-between gap-3 mb-4">
                                    <div class="flex-1">
                                        <h3 class="text-xl font-bold text-gray-800 dark:text-white mb-2">
                                            <?php echo htmlspecialchars($assignment['title']); ?>
                                        </h3>
                                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">
                                            <i class="fas fa-book mr-1"></i><?php echo htmlspecialchars($assignment['course_title']); ?>
                                        </p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                            <i class="fas fa-layer-group mr-1"></i><?php echo htmlspecialchars($assignment['section_title'] ?? 'No section'); ?>
                                            <span class="mx-2">•</span>
                                            <i class="fas fa-play-circle mr-1"></i><?php echo htmlspecialchars($assignment['lesson_title'] ?? 'No lesson'); ?>
                                        </p>
                                    </div>
                                    <span class="px-3 py-1 rounded-full text-xs font-bold <?php echo $isOverdue ? 'bg-red-100 dark:bg-red-900/20 text-red-700 dark:text-red-400' : 'bg-emerald-100 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400'; ?>">
                                        <?php echo $isOverdue ? 'Closed' : 'Open'; ?>
                                    </span>
                                </div>

                                <?php if (!empty($assignment['description'])): ?>
                                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-4 line-clamp-2">
                                        <?php echo htmlspecialchars($assignment['description']); ?>
                                    </p>
                                <?php endif; ?>

                                <div class="flex flex-wrap items-center justify-between gap-2 text-sm text-gray-500 dark:text-gray-400 mb-4">
                                    <?php if ($dueDate): ?>
                                        <span><i class="fas fa-calendar mr-1"></i>Deadline: <?php echo $dueDate->format('M d, Y H:i'); ?></span>
                                    <?php else: ?>
                                        <span><i class="fas fa-calendar mr-1"></i>No deadline</span>
                                    <?php endif; ?>
                                    <span><i class="fas fa-star mr-1"></i>Total: <?php echo $displayMarks; ?> pts</span>
                                </div>

                                <div class="pt-4 border-t border-gray-200 dark:border-gray-700 space-y-3">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <span class="text-sm text-gray-600 dark:text-gray-400">Submissions: </span>
                                            <span class="font-bold text-primary-600 dark:text-primary-400"><?php echo $assignment['submission_count']; ?></span>
                                        </div>
                                        <?php if ($assignment['pending_count'] > 0): ?>
                                            <span class="bg-orange-100 dark:bg-orange-900/20 text-orange-700 dark:text-orange-400 px-3 py-1 rounded-full text-xs font-bold">
                                                <?php echo $assignment['pending_count']; ?> pending
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="flex flex-wrap gap-2">
                                        <?php if (!empty($assignment['file_path'])): ?>
                                            <a href="/Iqra-College/uploads/assignments/<?php echo htmlspecialchars($assignment['file_path']); ?>" target="_blank" class="px-4 py-2 rounded-lg bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-400 text-sm font-semibold">
                                                <i class="fas fa-file-download mr-1"></i>Assignment File
                                            </a>
                                        <?php endif; ?>
                                        <button type="button"
                                                class="px-4 py-2 rounded-lg bg-primary-50 dark:bg-primary-900/20 text-primary-700 dark:text-primary-300 text-sm font-semibold"
                                                onclick="editAssignment(<?php echo htmlspecialchars(json_encode($assignment)); ?>)">
                                            <i class="fas fa-pen mr-1"></i>Edit
                                        </button>
                                        <form method="POST" onsubmit="return confirm('Delete this assignment?');">
                                            <input type="hidden" name="action" value="delete_assignment">
                                            <input type="hidden" name="assignment_id" value="<?php echo (int)$assignment['id']; ?>">
                                            <button type="submit" class="px-4 py-2 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 text-sm font-semibold">
                                                <i class="fas fa-trash mr-1"></i>Delete
                                            </button>
                                        </form>
                                    </div>

                                    <?php if (!empty($assignmentSubmissions)): ?>
                                        <div class="bg-gray-50 dark:bg-gray-900/40 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
                                            <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">
                                                <i class="fas fa-inbox mr-2"></i>Student Submissions
                                            </h4>
                                            <div class="space-y-3">
                                                <?php foreach ($assignmentSubmissions as $submission): 
                                                    $submissionScore = intval($submission['score'] ?? 0);
                                                    $submissionMax = intval($submission['total_marks'] ?? $submission['max_score'] ?? 100);
                                                    $submissionStatus = $submission['status'] ?: 'submitted';
                                                ?>
                                                    <div class="flex flex-wrap items-center justify-between gap-2 bg-white dark:bg-gray-800 rounded-lg p-3">
                                                        <div>
                                                            <p class="text-sm font-semibold text-gray-800 dark:text-white">
                                                                <?php echo htmlspecialchars($submission['student_name']); ?> (<?php echo (int)$submission['student_code']; ?>)
                                                            </p>
                                                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                                                Submitted: <?php echo $submission['submitted_at'] ? date('M d, Y H:i', strtotime($submission['submitted_at'])) : 'Unknown'; ?>
                                                            </p>
                                                        </div>
                                                        <div class="flex flex-wrap items-center gap-2">
                                                            <?php if (!empty($submission['file_path'])): ?>
                                                                <a href="/Iqra-College/uploads/assignments/<?php echo htmlspecialchars($submission['file_path']); ?>" target="_blank" class="text-xs px-3 py-1 rounded-full bg-blue-100 dark:bg-blue-900/20 text-blue-700 dark:text-blue-400">
                                                                    Download
                                                                </a>
                                                            <?php endif; ?>
                                                            <?php if ($submissionStatus === 'graded'): ?>
                                                                <span class="text-xs px-3 py-1 rounded-full bg-emerald-100 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400">
                                                                    Graded: <?php echo $submissionScore; ?>/<?php echo $submissionMax; ?>
                                                                </span>
                                                            <?php else: ?>
                                                                <button type="button" class="text-xs px-3 py-1 rounded-full bg-primary-500 text-white" onclick="openGradeModal(<?php echo htmlspecialchars(json_encode($submission)); ?>)">
                                                                    Grade
                                                                </button>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Grade Modal -->
    <div id="gradeModal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm flex items-center justify-center z-50 p-4">
        <div class="bg-white dark:bg-gray-800 rounded-3xl p-8 max-w-md w-full shadow-2xl">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-2xl font-bold text-gray-800 dark:text-white">Grade Assignment</h3>
                <button onclick="closeGradeModal()" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 text-2xl">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form method="POST" id="gradeForm">
                <input type="hidden" name="action" value="grade">
                <input type="hidden" name="submission_id" id="grade_submission_id">
                <input type="hidden" name="max_score" id="grade_max_score">
                
                <div class="mb-4">
                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Score *</label>
                    <input type="number" name="score" id="grade_score" required min="0" 
                           class="w-full px-4 py-2 border-2 border-gray-200 dark:border-gray-700 rounded-lg focus:border-primary-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Max score: <span id="grade_max_display"></span></p>
                </div>
                
                <div class="mb-6">
                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Feedback</label>
                    <textarea name="feedback" id="grade_feedback" rows="4" 
                              class="w-full px-4 py-2 border-2 border-gray-200 dark:border-gray-700 rounded-lg focus:border-primary-500 focus:outline-none dark:bg-gray-700 dark:text-white"></textarea>
                </div>
                
                <div class="flex gap-4">
                    <button type="submit" 
                            class="flex-1 bg-gradient-to-r from-primary-500 to-purple-600 text-white px-6 py-3 rounded-lg font-bold hover:from-primary-600 hover:to-purple-700 transition-all">
                        <i class="fas fa-check mr-2"></i>Submit Grade
                    </button>
                    <button type="button" onclick="closeGradeModal()" 
                            class="bg-gray-500 text-white px-6 py-3 rounded-lg font-bold hover:bg-gray-600 transition-all">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openGradeModal(submission) {
            document.getElementById('grade_submission_id').value = submission.id;
            const maxScore = submission.total_marks || submission.max_score || 100;
            document.getElementById('grade_max_score').value = maxScore;
            document.getElementById('grade_max_display').textContent = maxScore;
            document.getElementById('grade_score').max = maxScore;
            document.getElementById('grade_score').value = '';
            document.getElementById('grade_feedback').value = '';
            document.getElementById('gradeModal').classList.remove('hidden');
        }
        
        function closeGradeModal() {
            document.getElementById('gradeModal').classList.add('hidden');
        }

        function editAssignment(assignment) {
            document.getElementById('assignment_action').value = 'update_assignment';
            document.getElementById('assignment_id').value = assignment.id;
            document.getElementById('assignment_title').value = assignment.title || '';
            document.getElementById('assignment_description').value = assignment.description || '';
            document.getElementById('assignment_marks').value = assignment.total_marks || assignment.max_score || 100;
            document.getElementById('assignment_expire').value = assignment.expire_date ? assignment.expire_date.replace(' ', 'T') : (assignment.due_date ? assignment.due_date.replace(' ', 'T') : '');

            const courseSelect = document.getElementById('course_select');
            const sectionSelect = document.getElementById('section_select');
            const lessonSelect = document.getElementById('lesson_select');

            courseSelect.value = assignment.course_id || '';
            filterSections();
            sectionSelect.value = assignment.section_id || '';
            filterLessons();
            lessonSelect.value = assignment.lesson_id || '';

            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function resetAssignmentForm() {
            document.getElementById('assignment_action').value = 'save_assignment';
            document.getElementById('assignment_id').value = '';
            document.getElementById('assignment_title').value = '';
            document.getElementById('assignment_description').value = '';
            document.getElementById('assignment_marks').value = 100;
            document.getElementById('assignment_expire').value = '';
            document.getElementById('assignment_file').value = '';
            document.getElementById('course_select').value = '';
            filterSections();
            document.getElementById('section_select').value = '';
            filterLessons();
            document.getElementById('lesson_select').value = '';
        }

        function filterSections() {
            const courseId = document.getElementById('course_select').value;
            const sectionSelect = document.getElementById('section_select');
            Array.from(sectionSelect.options).forEach(option => {
                if (!option.value) return;
                const matches = option.dataset.course === courseId;
                option.hidden = !matches;
                if (!matches && option.selected) {
                    option.selected = false;
                }
            });
        }

        function filterLessons() {
            const courseId = document.getElementById('course_select').value;
            const sectionId = document.getElementById('section_select').value;
            const lessonSelect = document.getElementById('lesson_select');
            Array.from(lessonSelect.options).forEach(option => {
                if (!option.value) return;
                const matchesCourse = option.dataset.course === courseId;
                const matchesSection = option.dataset.section === sectionId;
                const matches = matchesCourse && matchesSection;
                option.hidden = !matches;
                if (!matches && option.selected) {
                    option.selected = false;
                }
            });
        }
        
        document.getElementById('gradeModal')?.addEventListener('click', function(e) {
            if (e.target === this) {
                closeGradeModal();
            }
        });

        document.getElementById('course_select')?.addEventListener('change', () => {
            filterSections();
            filterLessons();
        });

        document.getElementById('section_select')?.addEventListener('change', filterLessons);
        document.getElementById('assignment_reset')?.addEventListener('click', resetAssignmentForm);
        
        // Ensure dark mode toggle works on this page
        document.addEventListener('DOMContentLoaded', function() {
            const html = document.documentElement;
            const darkModeCookie = document.cookie.split('; ').find(row => row.startsWith('dark_mode='));
            if (darkModeCookie && darkModeCookie.split('=')[1] === 'enabled') {
                html.classList.add('dark');
            } else {
                html.classList.remove('dark');
            }

            filterSections();
            filterLessons();
        });
    </script>
</body>
</html>
