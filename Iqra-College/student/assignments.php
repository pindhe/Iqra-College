<?php
/**
 * Student - Assignments (Modern Design)
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

requireRole('student');

$studentId = getCurrentUserId();
$pdo = getDBConnection();
$name = getCurrentUserName();
$studentCode = getUserCode($studentId);
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

function uploadSubmissionFile($file, $uploadDir) {
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

    $fileName = 'submission_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
    $destination = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fileName;

    if (move_uploaded_file($file['tmp_name'], $destination)) {
        return $fileName;
    }

    return '';
}

ensureAssignmentSchema($pdo);

// Handle assignment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_assignment'])) {
    $assignmentId = intval($_POST['assignment_id'] ?? 0);
    $comment = sanitize($_POST['comment'] ?? '');

    if ($assignmentId <= 0) {
        $error = 'Invalid assignment.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT a.*, c.id as course_id FROM assignments a JOIN courses c ON a.course_id = c.id WHERE a.id = ?");
            $stmt->execute([$assignmentId]);
            $assignment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$assignment || !isEnrolled($studentId, $assignment['course_id'])) {
                $error = 'You are not enrolled in this course.';
            } else {
                $dueDateValue = $assignment['expire_date'] ?? $assignment['due_date'] ?? null;
                if ($dueDateValue && new DateTime($dueDateValue) < new DateTime()) {
                    $error = 'Submission deadline has passed. Assignment is closed.';
                } else {
                    $stmt = $pdo->prepare("SELECT id, file_path FROM assignment_submissions WHERE assignment_id = ? AND student_id = ?");
                    $stmt->execute([$assignmentId, $studentId]);
                    $existingSubmission = $stmt->fetch(PDO::FETCH_ASSOC);

                    $uploadDir = __DIR__ . '/../uploads/assignments';
                    $filePath = uploadSubmissionFile($_FILES['submission_file'] ?? [], $uploadDir);

                    if ($filePath === '' && !$existingSubmission) {
                        $error = 'Please upload your submission file (PDF/DOC).';
                    } elseif ($filePath === '' && $existingSubmission) {
                        $filePath = $existingSubmission['file_path'] ?? '';
                    }

                    if (!$error) {
                        $columns = getTableColumns($pdo, 'assignment_submissions');
                        $data = [
                            'assignment_id' => $assignmentId,
                            'student_id' => $studentId,
                            'file_path' => $filePath,
                            'content' => $comment,
                            'status' => 'submitted',
                            'submitted_at' => date('Y-m-d H:i:s'),
                        ];

                        if ($existingSubmission) {
                            $setParts = [];
                            $params = [];
                            foreach ($data as $column => $value) {
                                if (in_array($column, $columns, true)) {
                                    $setParts[] = "{$column} = ?";
                                    $params[] = $value;
                                }
                            }
                            $params[] = $existingSubmission['id'];
                            $stmt = $pdo->prepare("UPDATE assignment_submissions SET " . implode(', ', $setParts) . " WHERE id = ?");
                            $stmt->execute($params);
                            $success = 'Assignment submission updated successfully.';
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
                            $stmt = $pdo->prepare("INSERT INTO assignment_submissions (" . implode(',', $fields) . ") VALUES (" . implode(',', $placeholders) . ")");
                            $stmt->execute($params);
                            $success = 'Assignment submitted successfully.';
                        }
                    }
                }
            }
        } catch (PDOException $e) {
            $error = 'Failed to submit assignment.';
        }
    }
}

// Get all assignments for enrolled courses
try {
    $stmt = $pdo->prepare("
        SELECT a.*, c.title as course_name, c.id as course_id,
               sct.title as section_title, l.title as lesson_title,
               s.id as submission_id, s.submitted_at, s.score, s.status as submission_status, s.feedback, s.graded_at, s.file_path as submission_file
        FROM assignments a
        JOIN courses c ON a.course_id = c.id
        JOIN enrollments e ON c.id = e.course_id
        LEFT JOIN sections sct ON a.section_id = sct.id
        LEFT JOIN lessons l ON a.lesson_id = l.id
        LEFT JOIN assignment_submissions s ON a.id = s.assignment_id AND s.student_id = ?
        WHERE e.student_id = ?
        ORDER BY COALESCE(a.expire_date, a.due_date) ASC
    ");
    $stmt->execute([$studentId, $studentId]);
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $assignments = [];
}

// Separate assignments by status
$upcoming = [];
$submitted = [];
$overdue = [];
$graded = [];

$now = new DateTime();

foreach ($assignments as $assignment) {
    $dueDateValue = $assignment['expire_date'] ?? $assignment['due_date'] ?? null;
    $dueDate = $dueDateValue ? new DateTime($dueDateValue) : null;
    $isSubmitted = !empty($assignment['submission_id']);
    $isGraded = !empty($assignment['score']);
    
    if ($isGraded) {
        $graded[] = $assignment;
    } elseif ($isSubmitted) {
        $submitted[] = $assignment;
    } elseif ($dueDate && $dueDate < $now) {
        $overdue[] = $assignment;
    } else {
        $upcoming[] = $assignment;
    }
}

$pageTitle = 'My Assignments';
$pageSubtitle = 'View and submit your course assignments';
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

            <!-- Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <!-- Upcoming -->
                <div class="card-hover bg-gradient-to-br from-white to-blue-50 dark:from-gray-800 dark:to-gray-900 rounded-2xl p-6 border border-gray-200 dark:border-gray-700 shadow-lg stagger-item relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-blue-200 dark:bg-blue-900/30 rounded-full -mr-16 -mt-16 opacity-20"></div>
                    <div class="relative flex justify-between items-center">
                        <div>
                            <p class="text-gray-600 dark:text-gray-400 text-sm font-semibold uppercase tracking-wide mb-2">Upcoming</p>
                            <p class="text-4xl font-extrabold bg-gradient-to-r from-blue-600 to-blue-800 dark:from-blue-400 dark:to-blue-600 bg-clip-text text-transparent"><?php echo count($upcoming); ?></p>
                        </div>
                        <div class="bg-gradient-to-br from-blue-500 to-blue-700 p-4 rounded-2xl shadow-lg">
                            <i class="fas fa-clock text-white text-2xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Overdue -->
                <div class="card-hover bg-gradient-to-br from-white to-red-50 dark:from-gray-800 dark:to-gray-900 rounded-2xl p-6 border border-gray-200 dark:border-gray-700 shadow-lg stagger-item relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-red-200 dark:bg-red-900/30 rounded-full -mr-16 -mt-16 opacity-20"></div>
                    <div class="relative flex justify-between items-center">
                        <div>
                            <p class="text-gray-600 dark:text-gray-400 text-sm font-semibold uppercase tracking-wide mb-2">Overdue</p>
                            <p class="text-4xl font-extrabold bg-gradient-to-r from-red-600 to-red-800 dark:from-red-400 dark:to-red-600 bg-clip-text text-transparent"><?php echo count($overdue); ?></p>
                        </div>
                        <div class="bg-gradient-to-br from-red-500 to-red-700 p-4 rounded-2xl shadow-lg">
                            <i class="fas fa-exclamation-triangle text-white text-2xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Submitted -->
                <div class="card-hover bg-gradient-to-br from-white to-yellow-50 dark:from-gray-800 dark:to-gray-900 rounded-2xl p-6 border border-gray-200 dark:border-gray-700 shadow-lg stagger-item relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-yellow-200 dark:bg-yellow-900/30 rounded-full -mr-16 -mt-16 opacity-20"></div>
                    <div class="relative flex justify-between items-center">
                        <div>
                            <p class="text-gray-600 dark:text-gray-400 text-sm font-semibold uppercase tracking-wide mb-2">Submitted</p>
                            <p class="text-4xl font-extrabold bg-gradient-to-r from-yellow-600 to-yellow-800 dark:from-yellow-400 dark:to-yellow-600 bg-clip-text text-transparent"><?php echo count($submitted); ?></p>
                        </div>
                        <div class="bg-gradient-to-br from-yellow-500 to-yellow-700 p-4 rounded-2xl shadow-lg">
                            <i class="fas fa-paper-plane text-white text-2xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Graded -->
                <div class="card-hover bg-gradient-to-br from-white to-green-50 dark:from-gray-800 dark:to-gray-900 rounded-2xl p-6 border border-gray-200 dark:border-gray-700 shadow-lg stagger-item relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-green-200 dark:bg-green-900/30 rounded-full -mr-16 -mt-16 opacity-20"></div>
                    <div class="relative flex justify-between items-center">
                        <div>
                            <p class="text-gray-600 dark:text-gray-400 text-sm font-semibold uppercase tracking-wide mb-2">Graded</p>
                            <p class="text-4xl font-extrabold bg-gradient-to-r from-green-600 to-emerald-600 dark:from-green-400 dark:to-emerald-400 bg-clip-text text-transparent"><?php echo count($graded); ?></p>
                        </div>
                        <div class="bg-gradient-to-br from-green-500 to-emerald-600 p-4 rounded-2xl shadow-lg">
                            <i class="fas fa-check-circle text-white text-2xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Overdue Assignments -->
            <?php if (!empty($overdue)): ?>
            <div class="card-hover bg-white dark:bg-gray-800 rounded-2xl p-6 border border-gray-200 dark:border-gray-700 shadow-lg mb-8 fade-in">
                <div class="flex items-center space-x-3 mb-6">
                    <div class="bg-gradient-to-br from-red-500 to-red-700 p-3 rounded-xl">
                        <i class="fas fa-exclamation-triangle text-white text-xl"></i>
                    </div>
                    <h2 class="text-2xl font-bold text-gray-800 dark:text-white">Overdue Assignments</h2>
                    <span class="ml-auto bg-red-500 text-white text-sm px-3 py-1 rounded-full font-semibold">
                        <?php echo count($overdue); ?>
                    </span>
                </div>
                <div class="space-y-4">
                    <?php foreach ($overdue as $assignment): ?>
                        <div class="p-6 bg-gradient-to-r from-red-50 to-pink-50 dark:from-red-900/20 dark:to-pink-900/20 border-l-4 border-red-500 rounded-xl hover:shadow-lg transition-all">
                            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                                <div class="flex-1">
                                    <div class="flex items-center space-x-3 mb-2">
                                        <h3 class="text-lg font-bold text-gray-800 dark:text-white"><?php echo htmlspecialchars($assignment['title']); ?></h3>
                                        <span class="px-3 py-1 bg-red-500 text-white text-xs rounded-full font-semibold animate-pulse">
                                            OVERDUE
                                        </span>
                                    </div>
                                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">
                                        <i class="fas fa-book text-primary-600 dark:text-primary-400 mr-2"></i>
                                        <?php echo htmlspecialchars($assignment['course_name'] ?? 'Unknown Course'); ?>
                                    </p>
                                    <p class="text-sm font-semibold text-red-600 dark:text-red-400">
                                        <i class="fas fa-calendar-times mr-2"></i>
                                        Due: <?php echo $assignment['due_date'] ? date('M j, Y g:i A', strtotime($assignment['due_date'])) : 'No due date'; ?>
                                    </p>
                                    <?php if (!empty($assignment['description'])): ?>
                                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-3 line-clamp-2">
                                            <?php echo htmlspecialchars(substr($assignment['description'], 0, 150)); ?>...
                                        </p>
                                    <?php endif; ?>
                                    <div class="mt-4 flex flex-wrap items-center gap-3 text-xs text-gray-600 dark:text-gray-400">
                                        <span class="px-2 py-1 bg-white dark:bg-gray-700 rounded-full">
                                            <i class="fas fa-layer-group mr-1"></i><?php echo htmlspecialchars($assignment['section_title'] ?? 'No section'); ?>
                                        </span>
                                        <span class="px-2 py-1 bg-white dark:bg-gray-700 rounded-full">
                                            <i class="fas fa-play-circle mr-1"></i><?php echo htmlspecialchars($assignment['lesson_title'] ?? 'No lesson'); ?>
                                        </span>
                                        <span class="px-2 py-1 bg-white dark:bg-gray-700 rounded-full">
                                            <i class="fas fa-star mr-1"></i><?php echo intval($assignment['total_marks'] ?? $assignment['max_score'] ?? 100); ?> pts
                                        </span>
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-3">
                                    <?php if (!empty($assignment['file_path'])): ?>
                                        <a href="/Iqra-College/uploads/assignments/<?php echo htmlspecialchars($assignment['file_path']); ?>" target="_blank"
                                           class="bg-white dark:bg-gray-800 text-red-600 dark:text-red-300 px-5 py-3 rounded-xl font-bold shadow-lg hover:shadow-xl transition-all whitespace-nowrap">
                                            <i class="fas fa-file-download mr-2"></i>Download
                                        </a>
                                        <button type="button" onclick="printAssignment('/Iqra-College/uploads/assignments/<?php echo htmlspecialchars($assignment['file_path']); ?>')"
                                                class="bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 px-5 py-3 rounded-xl font-bold shadow-lg hover:shadow-xl transition-all whitespace-nowrap">
                                            <i class="fas fa-print mr-2"></i>Print
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="mt-4 bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 rounded-lg p-4 text-sm">
                                <i class="fas fa-lock mr-2"></i>Submission deadline has passed. Assignment is closed.
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Upcoming Assignments -->
            <?php if (!empty($upcoming)): ?>
            <div class="card-hover bg-white dark:bg-gray-800 rounded-2xl p-6 border border-gray-200 dark:border-gray-700 shadow-lg mb-8 fade-in">
                <div class="flex items-center space-x-3 mb-6">
                    <div class="bg-gradient-to-br from-blue-500 to-blue-700 p-3 rounded-xl">
                        <i class="fas fa-clock text-white text-xl"></i>
                    </div>
                    <h2 class="text-2xl font-bold text-gray-800 dark:text-white">Upcoming Assignments</h2>
                    <span class="ml-auto bg-blue-500 text-white text-sm px-3 py-1 rounded-full font-semibold">
                        <?php echo count($upcoming); ?>
                    </span>
                </div>
                <div class="space-y-4">
                    <?php foreach ($upcoming as $assignment): ?>
                        <?php
                            $dueDate = $assignment['due_date'] ? new DateTime($assignment['due_date']) : null;
                            $daysLeft = $dueDate ? $now->diff($dueDate)->days : null;
                            $isUrgent = $daysLeft !== null && $daysLeft <= 2;
                        ?>
                        <div class="p-6 bg-gradient-to-r from-gray-50 to-white dark:from-gray-700/50 dark:to-gray-800 rounded-xl border <?php echo $isUrgent ? 'border-yellow-400 dark:border-yellow-600' : 'border-gray-200 dark:border-gray-700'; ?> hover:shadow-lg transition-all">
                            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                                <div class="flex-1">
                                    <div class="flex items-center space-x-3 mb-2">
                                        <h3 class="text-lg font-bold text-gray-800 dark:text-white"><?php echo htmlspecialchars($assignment['title']); ?></h3>
                                        <?php if ($isUrgent): ?>
                                            <span class="px-3 py-1 bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-400 text-xs rounded-full font-semibold">
                                                URGENT
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">
                                        <i class="fas fa-book text-primary-600 dark:text-primary-400 mr-2"></i>
                                        <?php echo htmlspecialchars($assignment['course_name'] ?? 'Unknown Course'); ?>
                                    </p>
                                    <div class="flex items-center space-x-4 text-sm">
                                        <p class="text-gray-600 dark:text-gray-400">
                                            <i class="fas fa-calendar mr-2"></i>
                                            Due: <?php echo $dueDate ? $dueDate->format('M j, Y g:i A') : 'No due date'; ?>
                                        </p>
                                        <?php if ($daysLeft !== null): ?>
                                            <span class="px-3 py-1 rounded-full text-xs font-semibold <?php echo $isUrgent ? 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-400' : 'bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-400'; ?>">
                                                <?php echo $daysLeft; ?> day<?php echo $daysLeft != 1 ? 's' : ''; ?> left
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($assignment['description'])): ?>
                                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-3 line-clamp-2">
                                            <?php echo htmlspecialchars(substr($assignment['description'], 0, 150)); ?>...
                                        </p>
                                    <?php endif; ?>
                                    <div class="mt-4 flex flex-wrap items-center gap-3 text-xs text-gray-600 dark:text-gray-400">
                                        <span class="px-2 py-1 bg-white dark:bg-gray-700 rounded-full">
                                            <i class="fas fa-layer-group mr-1"></i><?php echo htmlspecialchars($assignment['section_title'] ?? 'No section'); ?>
                                        </span>
                                        <span class="px-2 py-1 bg-white dark:bg-gray-700 rounded-full">
                                            <i class="fas fa-play-circle mr-1"></i><?php echo htmlspecialchars($assignment['lesson_title'] ?? 'No lesson'); ?>
                                        </span>
                                        <span class="px-2 py-1 bg-white dark:bg-gray-700 rounded-full">
                                            <i class="fas fa-star mr-1"></i><?php echo intval($assignment['total_marks'] ?? $assignment['max_score'] ?? 100); ?> pts
                                        </span>
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-3">
                                    <?php if (!empty($assignment['file_path'])): ?>
                                        <a href="/Iqra-College/uploads/assignments/<?php echo htmlspecialchars($assignment['file_path']); ?>" target="_blank"
                                           class="bg-white dark:bg-gray-800 text-primary-600 dark:text-primary-400 px-5 py-3 rounded-xl font-bold shadow-lg hover:shadow-xl transition-all whitespace-nowrap">
                                            <i class="fas fa-file-download mr-2"></i>Download
                                        </a>
                                        <button type="button" onclick="printAssignment('/Iqra-College/uploads/assignments/<?php echo htmlspecialchars($assignment['file_path']); ?>')"
                                                class="bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 px-5 py-3 rounded-xl font-bold shadow-lg hover:shadow-xl transition-all whitespace-nowrap">
                                            <i class="fas fa-print mr-2"></i>Print
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="mt-5 bg-white dark:bg-gray-900/40 border border-gray-200 dark:border-gray-700 rounded-xl p-4">
                                <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">
                                    <i class="fas fa-paper-plane mr-2"></i>Submit Assignment
                                </h4>
                                <form method="POST" enctype="multipart/form-data" class="grid gap-3">
                                    <input type="hidden" name="submit_assignment" value="1">
                                    <input type="hidden" name="assignment_id" value="<?php echo (int)$assignment['id']; ?>">
                                    <textarea name="comment" rows="3" placeholder="Optional comment to your teacher"
                                              class="w-full px-4 py-2 border-2 border-gray-200 dark:border-gray-700 rounded-lg focus:border-primary-500 focus:outline-none dark:bg-gray-700 dark:text-white"></textarea>
                                    <input type="file" name="submission_file" accept=".pdf,.doc,.docx" required
                                           class="w-full px-4 py-2 border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-900 text-gray-700 dark:text-gray-300">
                                    <button type="submit"
                                            class="bg-gradient-to-r from-primary-600 to-primary-700 hover:from-primary-700 hover:to-primary-800 text-white px-5 py-3 rounded-lg font-bold">
                                        Submit Assignment
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Submitted Assignments -->
            <?php if (!empty($submitted)): ?>
            <div class="card-hover bg-white dark:bg-gray-800 rounded-2xl p-6 border border-gray-200 dark:border-gray-700 shadow-lg mb-8 fade-in">
                <div class="flex items-center space-x-3 mb-6">
                    <div class="bg-gradient-to-br from-yellow-500 to-yellow-700 p-3 rounded-xl">
                        <i class="fas fa-paper-plane text-white text-xl"></i>
                    </div>
                    <h2 class="text-2xl font-bold text-gray-800 dark:text-white">Submitted Assignments</h2>
                    <span class="ml-auto bg-yellow-500 text-white text-sm px-3 py-1 rounded-full font-semibold">
                        <?php echo count($submitted); ?>
                    </span>
                </div>
                <div class="space-y-4">
                    <?php foreach ($submitted as $assignment): ?>
                        <div class="p-6 bg-gradient-to-r from-yellow-50 to-amber-50 dark:from-yellow-900/20 dark:to-amber-900/20 border-l-4 border-yellow-500 rounded-xl hover:shadow-lg transition-all">
                            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                                <div class="flex-1">
                                    <div class="flex items-center space-x-3 mb-2">
                                        <h3 class="text-lg font-bold text-gray-800 dark:text-white"><?php echo htmlspecialchars($assignment['title']); ?></h3>
                                        <span class="px-3 py-1 bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-400 text-xs rounded-full font-semibold">
                                            PENDING
                                        </span>
                                    </div>
                                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">
                                        <i class="fas fa-book text-primary-600 dark:text-primary-400 mr-2"></i>
                                        <?php echo htmlspecialchars($assignment['course_name'] ?? 'Unknown Course'); ?>
                                    </p>
                                    <div class="flex items-center space-x-4 text-sm">
                                        <p class="text-gray-600 dark:text-gray-400">
                                            <i class="fas fa-paper-plane mr-2"></i>
                                            Submitted: <?php echo $assignment['submitted_at'] ? date('M j, Y g:i A', strtotime($assignment['submitted_at'])) : 'Unknown'; ?>
                                        </p>
                                        <span class="px-3 py-1 bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-400 rounded-full text-xs font-semibold">
                                            <i class="fas fa-hourglass-half mr-1"></i>Awaiting grading
                                        </span>
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-3">
                                    <?php if (!empty($assignment['file_path'])): ?>
                                        <a href="/Iqra-College/uploads/assignments/<?php echo htmlspecialchars($assignment['file_path']); ?>" target="_blank"
                                           class="bg-white dark:bg-gray-800 text-yellow-700 dark:text-yellow-300 px-5 py-3 rounded-xl font-bold shadow-lg hover:shadow-xl transition-all whitespace-nowrap">
                                            <i class="fas fa-file-download mr-2"></i>Assignment
                                        </a>
                                    <?php endif; ?>
                                    <?php if (!empty($assignment['submission_file'])): ?>
                                        <a href="/Iqra-College/uploads/assignments/<?php echo htmlspecialchars($assignment['submission_file']); ?>" target="_blank"
                                           class="bg-white dark:bg-gray-800 text-yellow-700 dark:text-yellow-300 px-5 py-3 rounded-xl font-bold shadow-lg hover:shadow-xl transition-all whitespace-nowrap">
                                            <i class="fas fa-cloud-download-alt mr-2"></i>Your Submission
                                        </a>
                                    <?php endif; ?>
                                    <?php if (!empty($assignment['file_path'])): ?>
                                        <button type="button" onclick="printAssignment('/Iqra-College/uploads/assignments/<?php echo htmlspecialchars($assignment['file_path']); ?>')"
                                                class="bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 px-5 py-3 rounded-xl font-bold shadow-lg hover:shadow-xl transition-all whitespace-nowrap">
                                            <i class="fas fa-print mr-2"></i>Print
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Graded Assignments -->
            <?php if (!empty($graded)): ?>
            <div class="card-hover bg-white dark:bg-gray-800 rounded-2xl p-6 border border-gray-200 dark:border-gray-700 shadow-lg fade-in">
                <div class="flex items-center space-x-3 mb-6">
                    <div class="bg-gradient-to-br from-green-500 to-emerald-600 p-3 rounded-xl">
                        <i class="fas fa-check-circle text-white text-xl"></i>
                    </div>
                    <h2 class="text-2xl font-bold text-gray-800 dark:text-white">Graded Assignments</h2>
                    <span class="ml-auto bg-green-500 text-white text-sm px-3 py-1 rounded-full font-semibold">
                        <?php echo count($graded); ?>
                    </span>
                </div>
                <div class="space-y-4">
                    <?php foreach ($graded as $assignment): ?>
                        <?php
                            $score = intval($assignment['score']);
                            $maxScore = intval($assignment['max_score'] ?? 100);
                            $percentage = $maxScore > 0 ? round(($score / $maxScore) * 100) : 0;
                            $isExcellent = $percentage >= 90;
                            $isGood = $percentage >= 70;
                        ?>
                        <div class="p-6 bg-gradient-to-r from-green-50 to-emerald-50 dark:from-green-900/20 dark:to-emerald-900/20 border-l-4 border-green-500 rounded-xl hover:shadow-lg transition-all">
                            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                                <div class="flex-1">
                                    <div class="flex items-center space-x-3 mb-2">
                                        <h3 class="text-lg font-bold text-gray-800 dark:text-white"><?php echo htmlspecialchars($assignment['title']); ?></h3>
                                        <span class="px-3 py-1 bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-400 text-xs rounded-full font-semibold">
                                            GRADED
                                        </span>
                                    </div>
                                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">
                                        <i class="fas fa-book text-primary-600 dark:text-primary-400 mr-2"></i>
                                        <?php echo htmlspecialchars($assignment['course_name'] ?? 'Unknown Course'); ?>
                                    </p>
                                    <div class="flex items-center space-x-6 mb-3">
                                        <div class="flex items-center space-x-2">
                                            <div class="bg-gradient-to-br <?php echo $isExcellent ? 'from-green-500 to-emerald-600' : ($isGood ? 'from-blue-500 to-blue-600' : 'from-yellow-500 to-yellow-600'); ?> p-2 rounded-lg">
                                                <i class="fas fa-trophy text-white"></i>
                                            </div>
                                            <div>
                                                <p class="text-2xl font-extrabold <?php echo $isExcellent ? 'text-green-600 dark:text-green-400' : ($isGood ? 'text-blue-600 dark:text-blue-400' : 'text-yellow-600 dark:text-yellow-400'); ?>">
                                                    <?php echo $score; ?>/<?php echo $maxScore; ?>
                                                </p>
                                                <p class="text-xs text-gray-500 dark:text-gray-400">Score</p>
                                            </div>
                                        </div>
                                        <div class="flex items-center space-x-2">
                                            <div class="w-16 h-16 relative">
                                                <svg class="transform -rotate-90" viewBox="0 0 100 100">
                                                    <circle cx="50" cy="50" r="40" stroke="#e5e7eb" stroke-width="8" fill="none"/>
                                                    <circle cx="50" cy="50" r="40" 
                                                            stroke="<?php echo $isExcellent ? '#10b981' : ($isGood ? '#3b82f6' : '#eab308'); ?>" 
                                                            stroke-width="8" 
                                                            fill="none"
                                                            stroke-dasharray="<?php echo 2 * M_PI * 40; ?>"
                                                            stroke-dashoffset="<?php echo 2 * M_PI * 40 * (1 - $percentage / 100); ?>"
                                                            stroke-linecap="round"/>
                                                </svg>
                                                <div class="absolute inset-0 flex items-center justify-center">
                                                    <span class="text-xs font-bold <?php echo $isExcellent ? 'text-green-600 dark:text-green-400' : ($isGood ? 'text-blue-600 dark:text-blue-400' : 'text-yellow-600 dark:text-yellow-400'); ?>">
                                                        <?php echo $percentage; ?>%
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">
                                            <i class="fas fa-calendar mr-2"></i>
                                            Graded: <?php echo $assignment['graded_at'] ? date('M j, Y', strtotime($assignment['graded_at'])) : 'Recently'; ?>
                                        </p>
                                    </div>
                                    <?php if (!empty($assignment['feedback'])): ?>
                                        <div class="mt-4 p-4 bg-white dark:bg-gray-700 rounded-lg border border-gray-200 dark:border-gray-600">
                                            <p class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                                                <i class="fas fa-comment-dots text-primary-600 dark:text-primary-400 mr-2"></i>Feedback:
                                            </p>
                                            <p class="text-sm text-gray-600 dark:text-gray-400 italic">"<?php echo htmlspecialchars($assignment['feedback']); ?>"</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="flex flex-wrap gap-3">
                                    <?php if (!empty($assignment['file_path'])): ?>
                                        <a href="/Iqra-College/uploads/assignments/<?php echo htmlspecialchars($assignment['file_path']); ?>" target="_blank"
                                           class="bg-white dark:bg-gray-800 text-green-700 dark:text-green-300 px-5 py-3 rounded-xl font-bold shadow-lg hover:shadow-xl transition-all whitespace-nowrap">
                                            <i class="fas fa-file-download mr-2"></i>Assignment
                                        </a>
                                    <?php endif; ?>
                                    <?php if (!empty($assignment['submission_file'])): ?>
                                        <a href="/Iqra-College/uploads/assignments/<?php echo htmlspecialchars($assignment['submission_file']); ?>" target="_blank"
                                           class="bg-white dark:bg-gray-800 text-green-700 dark:text-green-300 px-5 py-3 rounded-xl font-bold shadow-lg hover:shadow-xl transition-all whitespace-nowrap">
                                            <i class="fas fa-cloud-download-alt mr-2"></i>Your Submission
                                        </a>
                                    <?php endif; ?>
                                    <?php if (!empty($assignment['file_path'])): ?>
                                        <button type="button" onclick="printAssignment('/Iqra-College/uploads/assignments/<?php echo htmlspecialchars($assignment['file_path']); ?>')"
                                                class="bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 px-5 py-3 rounded-xl font-bold shadow-lg hover:shadow-xl transition-all whitespace-nowrap">
                                            <i class="fas fa-print mr-2"></i>Print
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (empty($assignments)): ?>
                <div class="card-hover bg-white dark:bg-gray-800 rounded-2xl p-12 text-center border border-gray-200 dark:border-gray-700 shadow-lg fade-in">
                    <div class="inline-block bg-gray-100 dark:bg-gray-700 p-6 rounded-full mb-4">
                        <i class="fas fa-tasks text-gray-400 dark:text-gray-500 text-5xl"></i>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-800 dark:text-white mb-2">No Assignments Yet</h3>
                    <p class="text-gray-600 dark:text-gray-400 mb-6">You don't have any assignments at the moment.</p>
                    <a href="courses.php" class="inline-flex items-center space-x-2 bg-gradient-to-r from-primary-600 to-primary-700 hover:from-primary-700 hover:to-primary-800 text-white px-6 py-3 rounded-xl font-bold shadow-lg hover:shadow-xl transition-all">
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
        function printAssignment(url) {
            const win = window.open(url, '_blank');
            if (win) {
                win.addEventListener('load', () => {
                    win.print();
                });
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Mobile menu toggle
            const mobileMenuToggle = document.getElementById('mobile-menu-toggle');
            const sidebar = document.getElementById('sidebar');
            const sidebarOverlay = document.getElementById('sidebar-overlay');
            
            if (mobileMenuToggle) {
                mobileMenuToggle.addEventListener('click', () => {
                    sidebar.classList.remove('hidden');
                    sidebarOverlay.classList.remove('hidden');
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
            
            // Dark mode toggle
            const darkModeToggle = document.getElementById('dark-mode-toggle');
            if (darkModeToggle) {
                darkModeToggle.addEventListener('click', () => {
                    const html = document.documentElement;
                    const isDark = html.classList.contains('dark');
                    
                    if (isDark) {
                        html.classList.remove('dark');
                        document.cookie = 'dark_mode=disabled; path=/; max-age=' + 60*60*24*30;
                        darkModeToggle.querySelector('i').className = 'fas fa-moon text-yellow-500 w-5';
                    } else {
                        html.classList.add('dark');
                        document.cookie = 'dark_mode=enabled; path=/; max-age=' + 60*60*24*30;
                        darkModeToggle.querySelector('i').className = 'fas fa-sun text-yellow-400 w-5';
                    }
                });
            }
            
            // Ensure dark mode is initialized on page load
            const html = document.documentElement;
            const darkModeCookie = document.cookie.split('; ').find(row => row.startsWith('dark_mode='));
            if (darkModeCookie && darkModeCookie.split('=')[1] === 'enabled') {
                html.classList.add('dark');
            } else {
                html.classList.remove('dark');
            }
        });
    </script>
    
    <?php include __DIR__ . '/../includes/student_ai_button.php'; ?>
</body>
</html>
