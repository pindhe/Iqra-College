<?php
/**
 * Student - View Lesson (Professional E-Learning Platform)
 * Features:
 * - Secure video streaming
 * - Mandatory assignments per lesson
 * - Required quizzes per lesson
 * - Progress tracking and enforcement (no skipping)
 * - Final quiz unlock after all lessons complete
 * - Certificate generation after final quiz passed
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

requireRole('student');

$studentId = getCurrentUserId();
$lessonId = intval($_GET['id'] ?? 0);

if ($lessonId <= 0) {
    header('Location: /Iqra-College/student/courses.php');
    exit();
}

$pdo = getDBConnection();

// Get lesson
$lesson = getLessonById($lessonId);
if (!$lesson) {
    header('Location: /Iqra-College/student/courses.php');
    exit();
}

// Get course to verify enrollment
$course = getCourseById($lesson['course_id']);
if (!$course || !isEnrolled($studentId, $course['id'])) {
    header('Location: /Iqra-College/student/courses.php');
    exit();
}

// Get all lessons in this course for navigation
try {
    $stmt = $pdo->prepare("
        SELECT id, title, order_number, section_id
        FROM lessons 
        WHERE course_id = ? 
        ORDER BY order_number ASC, id ASC
    ");
    $stmt->execute([$course['id']]);
    $allLessons = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $currentIndex = -1;
    foreach ($allLessons as $index => $l) {
        if ($l['id'] == $lessonId) {
            $currentIndex = $index;
            break;
        }
    }
    
    $prevLesson = $currentIndex > 0 ? $allLessons[$currentIndex - 1] : null;
    $nextLesson = $currentIndex < count($allLessons) - 1 ? $allLessons[$currentIndex + 1] : null;
} catch (PDOException $e) {
    $prevLesson = null;
    $nextLesson = null;
}

// Get lesson progress
$progress = null;
$isCompleted = false;
try {
    $stmt = $pdo->prepare("
        SELECT completed, completed_at, time_spent 
        FROM lesson_progress 
        WHERE student_id = ? AND lesson_id = ?
    ");
    $stmt->execute([$studentId, $lessonId]);
    $progress = $stmt->fetch(PDO::FETCH_ASSOC);
    $isCompleted = $progress && $progress['completed'];
} catch (PDOException $e) {
    $isCompleted = false;
}

// Check for mandatory assignments for this lesson
$hasMandatoryAssignment = false;
$assignmentCompleted = false;
$lessonAssignments = [];
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM assignments LIKE 'lesson_id'");
    if ($stmt->rowCount() > 0) {
        $stmt = $pdo->prepare("
            SELECT a.*, 
                   (SELECT COUNT(*) FROM assignment_submissions 
                    WHERE assignment_id = a.id AND student_id = ? 
                    AND status IN ('submitted', 'graded', 'returned')) as submission_count
            FROM assignments a
            WHERE a.lesson_id = ? AND a.course_id = ?
        ");
        $stmt->execute([$studentId, $lessonId, $course['id']]);
        $lessonAssignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hasMandatoryAssignment = !empty($lessonAssignments);
        if ($hasMandatoryAssignment) {
            foreach ($lessonAssignments as $assignment) {
                if (intval($assignment['submission_count'] ?? 0) > 0) {
                    $assignmentCompleted = true;
                    break;
                }
            }
        }
    }
} catch (PDOException $e) {
    // lesson_id column might not exist
}

// Check for required quizzes for this lesson
$hasRequiredQuiz = false;
$quizPassed = false;
$lessonQuizzes = [];
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM quizzes LIKE 'lesson_id'");
    if ($stmt->rowCount() > 0) {
        $stmt = $pdo->prepare("
            SELECT q.*, 
                   (SELECT MAX(percentage) FROM quiz_attempts 
                    WHERE quiz_id = q.id AND student_id = ? 
                    AND status = 'completed') as best_score
            FROM quizzes q
            WHERE q.lesson_id = ? AND q.course_id = ? AND q.is_published = 1
        ");
        $stmt->execute([$studentId, $lessonId, $course['id']]);
        $lessonQuizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hasRequiredQuiz = !empty($lessonQuizzes);
        if ($hasRequiredQuiz) {
            $passingScore = intval($lessonQuizzes[0]['passing_score'] ?? 60);
            foreach ($lessonQuizzes as $quiz) {
                $bestScore = floatval($quiz['best_score'] ?? 0);
                if ($bestScore >= $passingScore) {
                    $quizPassed = true;
                    break;
                }
            }
        }
    }
} catch (PDOException $e) {
    // lesson_id column might not exist
}

// Check if previous lesson is completed (for unlocking next lesson)
$prevLessonCompleted = true;
if ($prevLesson) {
    try {
        $stmt = $pdo->prepare("
            SELECT completed FROM lesson_progress 
            WHERE student_id = ? AND lesson_id = ? AND completed = 1
        ");
        $stmt->execute([$studentId, $prevLesson['id']]);
        $prevLessonCompleted = $stmt->fetch() !== false;
    } catch (PDOException $e) {
        $prevLessonCompleted = false;
    }
}

// Check if this lesson can be accessed (previous lesson must be completed)
$canAccessLesson = true;
if ($currentIndex > 0 && !$prevLessonCompleted) {
    $canAccessLesson = false;
}

// Check for final quiz
$finalQuiz = null;
$finalQuizPassed = false;
try {
    $stmt = $pdo->prepare("
        SELECT * FROM quizzes 
        WHERE course_id = ? 
        AND (title LIKE '%Final%' OR title LIKE '%Exam%' OR title LIKE '%Final Exam%')
        AND is_published = 1
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$course['id']]);
    $finalQuiz = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($finalQuiz) {
        $stmt = $pdo->prepare("
            SELECT MAX(percentage) as best_score 
            FROM quiz_attempts 
            WHERE quiz_id = ? AND student_id = ? AND status = 'completed'
        ");
        $stmt->execute([$finalQuiz['id'], $studentId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $finalScore = floatval($result['best_score'] ?? 0);
        $finalQuizPassed = $finalScore >= intval($finalQuiz['passing_score'] ?? 60);
    }
} catch (PDOException $e) {
    // Continue
}

// Overall gating for this lesson
$canMarkLessonComplete = true;
if ($hasMandatoryAssignment && !$assignmentCompleted) {
    $canMarkLessonComplete = false;
}
if ($hasRequiredQuiz && !$quizPassed) {
    $canMarkLessonComplete = false;
}

// Handle mark as complete
$errorMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_complete'])) {
    // Server-side enforcement of requirements
    $completionErrors = [];
    if ($hasMandatoryAssignment && !$assignmentCompleted) {
        $completionErrors[] = 'Please submit the required assignment before completing this lesson.';
    }
    if ($hasRequiredQuiz && !$quizPassed) {
        $completionErrors[] = 'Please pass the required quiz before completing this lesson.';
    }

    if (!empty($completionErrors)) {
        $errorMessage = implode(' ', $completionErrors);
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO lesson_progress (student_id, lesson_id, completed, completed_at) 
                VALUES (?, ?, TRUE, NOW()) 
                ON DUPLICATE KEY UPDATE completed = TRUE, completed_at = NOW()
            ");
            $stmt->execute([$studentId, $lessonId]);
            $isCompleted = true;
            $progress = ['completed' => 1, 'completed_at' => date('Y-m-d H:i:s')];
            
            // Check if course is completed (all lessons done)
            $courseJustCompleted = false;
            try {
                $stmt = $pdo->prepare("
                    SELECT COUNT(l.id) as total_lessons,
                           COUNT(lp.lesson_id) as completed_lessons
                    FROM lessons l
                    LEFT JOIN lesson_progress lp ON l.id = lp.lesson_id 
                        AND lp.student_id = ? 
                        AND lp.completed = 1
                    WHERE l.course_id = ?
                ");
                $stmt->execute([$studentId, $course['id']]);
                $courseProgress = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($courseProgress && $courseProgress['total_lessons'] > 0 && 
                    $courseProgress['completed_lessons'] == $courseProgress['total_lessons']) {
                    $courseJustCompleted = true;
                }
            } catch (PDOException $e) {
                // Silent fail
            }
            
            // Auto-update enrollments.progress from lessons completed
            try {
                $newProgress = getCourseProgressFromLessons($studentId, $course['id']);
                $stmt = $pdo->prepare("UPDATE enrollments SET progress = ? WHERE student_id = ? AND course_id = ?");
                $stmt->execute([$newProgress, $studentId, $course['id']]);
            } catch (PDOException $e) {
                // progress column might not exist in some DBs
            }
            
            // Auto-redirect:
            // - If course just completed and a final quiz exists (and not yet passed), go to final quiz
            // - Else if course complete, go to certificate
            // - Else go to next lesson or back to course page
            if ($courseJustCompleted) {
                if ($finalQuiz && !$finalQuizPassed) {
                    header('Location: quiz.php?id=' . (int) $finalQuiz['id'] . '&final=1');
                    exit;
                }
                header('Location: certificate.php?course_id=' . (int)$course['id'] . '&congrats=1');
                exit;
            }
            if (!empty($nextLesson) && !empty($nextLesson['id'])) {
                header('Location: lesson.php?id=' . (int)$nextLesson['id']);
                exit;
            }
            header('Location: courses.php?id=' . (int)$course['id']);
            exit;
        } catch (PDOException $e) {
            $errorMessage = 'Failed to mark lesson as complete. Please try again.';
        }
    }
}

// Get materials
$materials = getMaterialsByLesson($lessonId);

// Get lesson statistics
try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT lp.student_id) as students_completed,
            AVG(lp.time_spent) as avg_time_spent
        FROM lesson_progress lp
        WHERE lp.lesson_id = ? AND lp.completed = 1
    ");
    $stmt->execute([$lessonId]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $stats = ['students_completed' => 0, 'avg_time_spent' => 0];
}

// Parse lesson content into sections
$lessonSections = [];
function parseLessonSections($content) {
    $content = trim((string) $content);
    if ($content === '') {
        return [];
    }
    $sections = [];
    if (preg_match('/^##\s+/m', $content)) {
        $parts = preg_split('/^##\s+(.+)$/m', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!empty($parts[0])) {
            $sections[] = ['title' => 'Overview', 'body' => trim($parts[0])];
        }
        for ($i = 1; $i < count($parts); $i += 2) {
            $title = trim($parts[$i] ?? '');
            $body = trim($parts[$i + 1] ?? '');
            if ($title !== '' || $body !== '') {
                $sections[] = ['title' => $title !== '' ? $title : 'Section', 'body' => $body];
            }
        }
        return $sections;
    }
    return [['title' => 'Lesson Content', 'body' => $content]];
}

function buildLessonBlocks($text) {
    $lines = preg_split("/\r\n|\n|\r/", (string) $text);
    $blocks = [];
    $buffer = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (preg_match('/^(TIP|NOTE|HIGHLIGHT|WARNING):\s*(.+)$/i', $trimmed, $matches)) {
            if (!empty($buffer)) {
                $blocks[] = ['type' => 'text', 'content' => implode("\n", $buffer)];
                $buffer = [];
            }
            $blocks[] = ['type' => 'highlight', 'label' => strtoupper($matches[1]), 'content' => $matches[2]];
            continue;
        }
        $buffer[] = $line;
    }
    if (!empty($buffer)) {
        $blocks[] = ['type' => 'text', 'content' => implode("\n", $buffer)];
    }
    return $blocks;
}

$totalLessons = count($allLessons);
$completedLessons = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM lesson_progress lp
        JOIN lessons l ON lp.lesson_id = l.id
        WHERE lp.student_id = ? AND lp.completed = 1 AND l.course_id = ?
    ");
    $stmt->execute([$studentId, $course['id']]);
    $completedLessons = (int) $stmt->fetchColumn();
} catch (PDOException $e) {
    $completedLessons = 0;
}
$courseProgressPercent = $totalLessons > 0 ? (int) round(($completedLessons / $totalLessons) * 100) : 0;
$lessonDurationLabel = !empty($lesson['duration']) ? ((int) $lesson['duration']) . ' min' : '—';
$lessonTypeLabel = !empty($lesson['lesson_type']) ? $lesson['lesson_type'] : 'Lesson';
$lessonContent = trim($lesson['content'] ?? '');
$lessonSections = parseLessonSections($lessonContent);

// Check for audio and document files
$audioFileUrl = null;
$documentFileUrl = null;

if (!empty($lesson['audio_file'])) {
    $audioFileName = basename($lesson['audio_file']);
    $audioPath = __DIR__ . '/../uploads/audio/' . $audioFileName;
    $legacyAudioPath = __DIR__ . '/../uploads/lessons/' . $audioFileName;
    if (file_exists($audioPath)) {
        $audioFileUrl = '/Iqra-College/uploads/audio/' . $audioFileName;
    } elseif (file_exists($legacyAudioPath)) {
        $audioFileUrl = '/Iqra-College/uploads/lessons/' . $audioFileName;
    }
}

if (!empty($lesson['document_file'])) {
    $docFileName = basename($lesson['document_file']);
    $docPath = __DIR__ . '/../uploads/materials/' . $docFileName;
    $legacyDocPath = __DIR__ . '/../uploads/lessons/' . $docFileName;
    if (file_exists($docPath)) {
        $documentFileUrl = '/Iqra-College/uploads/materials/' . $docFileName;
    } elseif (file_exists($legacyDocPath)) {
        $documentFileUrl = '/Iqra-College/uploads/lessons/' . $docFileName;
    }
}

// Check if we have any media files even if content is empty
$hasMediaFiles = !empty($lesson['video_url']) || !empty($videoFileUrl) || 
                 !empty($audioFileUrl) || !empty($documentFileUrl) || 
                 !empty($materials);

// Get video file URL
$videoFileUrl = null;
if (!empty($lesson['video_file'])) {
    $videoFileName = basename($lesson['video_file']);
    $videoPath = __DIR__ . '/../uploads/videos/' . $videoFileName;
    $legacyPath = __DIR__ . '/../uploads/lessons/' . $videoFileName;
    if (file_exists($videoPath)) {
        $videoFileUrl = '/Iqra-College/uploads/videos/' . $videoFileName;
    } elseif (file_exists($legacyPath)) {
        $videoFileUrl = '/Iqra-College/uploads/lessons/' . $videoFileName;
    }
}

$pageTitle = htmlspecialchars($lesson['title']);
$pageSubtitle = 'Lesson ' . ($currentIndex + 1) . ' of ' . count($allLessons) . ' in ' . htmlspecialchars($course['title']);
$currentPage = 'courses';
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
        .prose {
            max-width: none;
        }
        .prose h1, .prose h2, .prose h3 {
            color: inherit;
        }
        .prose p {
            margin-bottom: 1.5rem;
            line-height: 1.8;
        }
        .prose ul, .prose ol {
            margin-left: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .prose li {
            margin-bottom: 0.5rem;
        }
        .video-container {
            position: relative;
            padding-bottom: 56.25%;
            height: 0;
            overflow: hidden;
            border-radius: 1rem;
            background: #000;
        }
        .video-container video,
        .video-container iframe {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
        }
        .locked-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 1rem;
            z-index: 10;
        }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-200 min-h-screen transition-colors duration-300">
    <div class="flex">
        <?php include __DIR__ . '/../includes/student_sidebar.php'; ?>

        <main class="ml-0 lg:ml-64 flex-1 transition-all duration-300">
            <!-- Top Lesson Bar -->
            <div class="bg-gradient-to-r from-emerald-600 via-blue-600 to-purple-600 text-white px-4 lg:px-8 py-4 sticky top-0 z-20 shadow-lg">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <button id="mobile-menu-toggle" class="lg:hidden w-9 h-9 rounded-full bg-white/20 flex items-center justify-center hover:bg-white/30">
                            <i class="fas fa-bars"></i>
                        </button>
                        <a href="/Iqra-College/student/courses.php?id=<?php echo $course['id']; ?>" class="w-9 h-9 rounded-full bg-white/20 flex items-center justify-center hover:bg-white/30 hidden lg:flex">
                            <i class="fas fa-arrow-left"></i>
                        </a>
                        <div>
                            <p class="text-xs text-emerald-100">Course: <?php echo htmlspecialchars($course['title']); ?></p>
                            <p class="font-semibold text-sm">Your Progress: <?php echo $completedLessons; ?> / <?php echo $totalLessons; ?> (<?php echo $courseProgressPercent; ?>%)</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-4 text-sm">
                        <?php if ($isCompleted): ?>
                            <span class="px-4 py-2 rounded-full bg-white/20 text-white font-semibold flex items-center backdrop-blur-sm">
                                <i class="fas fa-check-circle mr-2"></i>Completed
                            </span>
                        <?php elseif ($canMarkLessonComplete): ?>
                            <form method="POST" class="inline">
                                <button type="submit" name="mark_complete" class="px-4 py-2 rounded-full bg-white text-emerald-700 font-semibold shadow-lg hover:shadow-xl transition-all">
                                    <i class="fas fa-check mr-2"></i>Mark as Complete
                                </button>
                            </form>
                        <?php else: ?>
                            <div class="px-4 py-2 rounded-full bg-amber-500/20 text-white font-semibold flex items-center backdrop-blur-sm" title="<?php echo htmlspecialchars($errorMessage ?: 'Complete required assignments and quizzes first'); ?>">
                                <i class="fas fa-lock mr-2"></i>Requirements Pending
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($errorMessage): ?>
                <div class="bg-red-100 dark:bg-red-900/30 border-l-4 border-red-500 text-red-700 dark:text-red-400 px-4 py-3 mx-4 mt-4 rounded-lg fade-in">
                    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($errorMessage); ?>
                </div>
            <?php endif; ?>

            <?php if (!$canAccessLesson): ?>
                <div class="bg-amber-100 dark:bg-amber-900/30 border-l-4 border-amber-500 text-amber-700 dark:text-amber-400 px-4 py-3 mx-4 mt-4 rounded-lg fade-in">
                    <i class="fas fa-lock mr-2"></i>Please complete the previous lesson before accessing this one.
                </div>
            <?php endif; ?>

            <div class="p-4 lg:p-8">
                <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
                    <!-- Course Content Sidebar -->
                    <aside class="lg:col-span-4 xl:col-span-3">
                        <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-lg overflow-hidden sticky top-24">
                            <div class="px-5 py-4 border-b border-gray-200 dark:border-gray-700 bg-gradient-to-r from-blue-50 to-purple-50 dark:from-gray-900 dark:to-gray-800">
                                <h3 class="text-lg font-bold text-gray-800 dark:text-white">Course Content</h3>
                                <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo $completedLessons; ?> / <?php echo $totalLessons; ?> completed</p>
                                <div class="mt-2 w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                                    <div class="bg-gradient-to-r from-emerald-500 to-blue-500 h-2 rounded-full transition-all duration-500" style="width: <?php echo $courseProgressPercent; ?>%"></div>
                                </div>
                            </div>
                            <div class="max-h-[70vh] overflow-y-auto">
                                <?php foreach (getSectionsWithLessons($course['id']) as $section): ?>
                                    <div class="border-b border-gray-100 dark:border-gray-700">
                                        <div class="px-5 py-3 bg-gray-50 dark:bg-gray-900/40 flex items-center justify-between">
                                            <p class="font-semibold text-gray-700 dark:text-gray-200 text-sm">
                                                <?php echo htmlspecialchars($section['title'] ?? 'Section'); ?>
                                            </p>
                                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                                <?php echo count($section['lessons'] ?? []); ?>
                                            </span>
                                        </div>
                                        <div class="px-4 py-2 space-y-2">
                                            <?php foreach (($section['lessons'] ?? []) as $index => $l): 
                                                $lessonCompleted = false;
                                                try {
                                                    $stmt = $pdo->prepare("SELECT completed FROM lesson_progress WHERE student_id = ? AND lesson_id = ? AND completed = 1");
                                                    $stmt->execute([$studentId, $l['id']]);
                                                    $lessonCompleted = $stmt->fetch() !== false;
                                                } catch (PDOException $e) { $lessonCompleted = false; }
                                                
                                                // Check if previous lesson is completed
                                                $canAccess = true;
                                                if ($index > 0) {
                                                    $prevL = ($section['lessons'] ?? [])[$index - 1] ?? null;
                                                    if ($prevL) {
                                                        try {
                                                            $stmt = $pdo->prepare("SELECT completed FROM lesson_progress WHERE student_id = ? AND lesson_id = ? AND completed = 1");
                                                            $stmt->execute([$studentId, $prevL['id']]);
                                                            $canAccess = $stmt->fetch() !== false;
                                                        } catch (PDOException $e) { $canAccess = false; }
                                                    }
                                                }
                                                
                                                $isCurrent = $l['id'] == $lessonId;
                                            ?>
                                                <?php if ($canAccess): ?>
                                                    <a href="lesson.php?id=<?php echo $l['id']; ?>" class="flex items-center gap-3 px-3 py-2 rounded-xl border transition-all <?php echo $isCurrent ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20 shadow-md' : 'border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/50'; ?>">
                                                <?php else: ?>
                                                    <div class="flex items-center gap-3 px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 opacity-60 cursor-not-allowed">
                                                <?php endif; ?>
                                                        <div class="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0 <?php echo $lessonCompleted ? 'bg-emerald-500 text-white' : ($canAccess ? 'bg-gray-200 dark:bg-gray-600 text-gray-600 dark:text-gray-200' : 'bg-gray-300 dark:bg-gray-700 text-gray-400'); ?>">
                                                            <?php if ($lessonCompleted): ?>
                                                                <i class="fas fa-check text-xs"></i>
                                                            <?php elseif (!$canAccess): ?>
                                                                <i class="fas fa-lock text-xs"></i>
                                                            <?php else: ?>
                                                                <i class="fas fa-play text-xs"></i>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="flex-1 min-w-0">
                                                            <p class="text-sm font-semibold text-gray-800 dark:text-white truncate"><?php echo htmlspecialchars($l['title']); ?></p>
                                                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                                                <?php if (!empty($l['duration'])): ?>
                                                                    <i class="fas fa-clock mr-1"></i><?php echo (int) $l['duration']; ?> min
                                                                <?php else: ?>
                                                                    <i class="fas fa-book-open mr-1"></i>Lesson
                                                                <?php endif; ?>
                                                            </p>
                                                        </div>
                                                <?php if ($canAccess): ?>
                                                    </a>
                                                <?php else: ?>
                                                    </div>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                
                                <!-- Final Quiz Section (Auto-show at end of course content) -->
                                <?php 
                                // Check if all lessons are completed
                                $allLessonsCompleted = false;
                                try {
                                    $stmt = $pdo->prepare("
                                        SELECT COUNT(l.id) as total_lessons,
                                               COUNT(lp.lesson_id) as completed_lessons
                                        FROM lessons l
                                        LEFT JOIN lesson_progress lp ON l.id = lp.lesson_id 
                                            AND lp.student_id = ? 
                                            AND lp.completed = 1
                                        WHERE l.course_id = ?
                                    ");
                                    $stmt->execute([$studentId, $course['id']]);
                                    $progressCheck = $stmt->fetch(PDO::FETCH_ASSOC);
                                    if ($progressCheck && $progressCheck['total_lessons'] > 0 && 
                                        $progressCheck['completed_lessons'] == $progressCheck['total_lessons']) {
                                        $allLessonsCompleted = true;
                                    }
                                } catch (PDOException $e) {
                                    $allLessonsCompleted = false;
                                }
                                
                                // Always show final quiz if it exists (locked if lessons not complete)
                                if ($finalQuiz): 
                                    $isCurrentQuiz = isset($_GET['quiz']) && $_GET['quiz'] == $finalQuiz['id'];
                                    $quizPassed = $finalQuizPassed ?? false;
                                    $canAccessQuiz = $allLessonsCompleted;
                                ?>
                                    <div class="border-t-2 border-gray-200 dark:border-gray-700 mt-4 pt-4">
                                        <div class="px-5 py-3 bg-gradient-to-r from-purple-50 to-pink-50 dark:from-purple-900/30 dark:to-pink-900/30 flex items-center justify-between">
                                            <p class="font-bold text-gray-800 dark:text-white text-sm flex items-center">
                                                <i class="fas fa-trophy text-purple-600 dark:text-purple-400 mr-2"></i>
                                                Final Quiz
                                            </p>
                                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                                1
                                            </span>
                                        </div>
                                        <div class="px-4 py-2">
                                            <?php if ($canAccessQuiz): ?>
                                                <a href="quiz.php?id=<?php echo $finalQuiz['id']; ?>&final=1" 
                                                   class="flex items-center gap-3 px-3 py-2 rounded-xl border transition-all <?php echo $isCurrentQuiz ? 'border-purple-500 bg-purple-50 dark:bg-purple-900/20 shadow-md' : 'border-purple-200 dark:border-purple-700 hover:bg-purple-50 dark:hover:bg-purple-700/50'; ?>">
                                            <?php else: ?>
                                                <div class="flex items-center gap-3 px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 opacity-60 cursor-not-allowed" 
                                                     title="Complete all lessons to unlock the final quiz">
                                            <?php endif; ?>
                                                    <div class="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0 <?php echo $quizPassed ? 'bg-emerald-500 text-white' : ($canAccessQuiz ? 'bg-purple-200 dark:bg-purple-600 text-purple-700 dark:text-purple-200' : 'bg-gray-300 dark:bg-gray-700 text-gray-400'); ?>">
                                                        <?php if ($quizPassed): ?>
                                                            <i class="fas fa-check text-xs"></i>
                                                        <?php elseif (!$canAccessQuiz): ?>
                                                            <i class="fas fa-lock text-xs"></i>
                                                        <?php else: ?>
                                                            <i class="fas fa-question-circle text-xs"></i>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="flex-1 min-w-0">
                                                        <p class="text-sm font-semibold text-gray-800 dark:text-white truncate">
                                                            <?php echo htmlspecialchars($finalQuiz['title']); ?>
                                                        </p>
                                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                                            <?php if ($quizPassed): ?>
                                                                <i class="fas fa-check-circle mr-1 text-emerald-600"></i>Passed
                                                            <?php elseif (!$canAccessQuiz): ?>
                                                                <i class="fas fa-lock mr-1"></i>Locked - Complete all lessons
                                                            <?php else: ?>
                                                                <i class="fas fa-clock mr-1"></i>Final Assessment
                                                            <?php endif; ?>
                                                        </p>
                                                    </div>
                                            <?php if ($canAccessQuiz): ?>
                                                </a>
                                            <?php else: ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </aside>

                    <!-- Lesson Area -->
                    <section class="lg:col-span-8 xl:col-span-9 space-y-6">
                        <!-- Lesson Header Card -->
                        <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-lg p-6 lg:p-8">
                            <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
                                <div>
                                    <div class="flex items-center gap-3 mb-2">
                                        <span class="px-3 py-1 rounded-full bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 text-xs font-semibold">
                                            <?php echo $lessonTypeLabel; ?>
                                        </span>
                                        <span class="px-3 py-1 rounded-full bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 text-xs font-semibold">
                                            <?php echo $lessonDurationLabel; ?>
                                        </span>
                                        <?php if ($hasMandatoryAssignment): ?>
                                            <span class="px-3 py-1 rounded-full <?php echo $assignmentCompleted ? 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300' : 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300'; ?> text-xs font-semibold">
                                                <i class="fas fa-tasks mr-1"></i><?php echo $assignmentCompleted ? 'Assignment Done' : 'Assignment Required'; ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($hasRequiredQuiz): ?>
                                            <span class="px-3 py-1 rounded-full <?php echo $quizPassed ? 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300' : 'bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300'; ?> text-xs font-semibold">
                                                <i class="fas fa-question-circle mr-1"></i><?php echo $quizPassed ? 'Quiz Passed' : 'Quiz Required'; ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <h1 class="text-3xl lg:text-4xl font-bold text-gray-800 dark:text-white mb-2"><?php echo htmlspecialchars($lesson['title']); ?></h1>
                                    <p class="text-gray-600 dark:text-gray-400">Lesson <?php echo $currentIndex + 1; ?> of <?php echo $totalLessons; ?></p>
                                </div>
                            </div>

                            <!-- Video Section -->
                            <?php if (!empty($lesson['video_url']) || !empty($videoFileUrl)): ?>
                                <div class="mb-6 relative">
                                    <div class="video-container">
                                        <?php if (!empty($lesson['video_url'])): ?>
                                            <iframe src="<?php echo htmlspecialchars($lesson['video_url']); ?>"
                                                    class="w-full h-full"
                                                    frameborder="0"
                                                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                                                    allowfullscreen>
                                            </iframe>
                                        <?php else: ?>
                                            <video controls class="w-full h-full" preload="metadata">
                                                <source src="<?php echo htmlspecialchars($videoFileUrl); ?>" type="video/mp4">
                                                Your browser does not support the video tag.
                                            </video>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Lesson Content -->
                            <div class="prose prose-lg dark:prose-invert max-w-none">
                                <?php if (empty($lessonSections) && empty($lessonContent)): ?>
                                    <!-- No text content; if there's media, just show the media above without any extra message -->
                                <?php elseif (empty($lessonSections)): ?>
                                    <!-- Fallback: Show raw content if parsing failed -->
                                    <div class="mb-8">
                                        <h3 class="text-2xl font-bold text-gray-800 dark:text-white mb-4">Lesson Content</h3>
                                        <div class="text-gray-700 dark:text-gray-300 leading-relaxed whitespace-pre-wrap">
                                            <?php echo nl2br(htmlspecialchars($lessonContent)); ?>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($lessonSections as $section): ?>
                                        <div class="mb-8">
                                            <h3 class="text-2xl font-bold text-gray-800 dark:text-white mb-4"><?php echo htmlspecialchars($section['title']); ?></h3>
                                            <?php foreach (buildLessonBlocks($section['body']) as $block): ?>
                                                <?php if ($block['type'] === 'highlight'): ?>
                                                    <div class="p-4 rounded-xl mb-4 <?php 
                                                        $label = strtoupper($block['label']);
                                                        if ($label === 'TIP') {
                                                            echo 'bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800';
                                                            $icon = 'fa-lightbulb';
                                                            $color = 'text-blue-800 dark:text-blue-300';
                                                        } elseif ($label === 'NOTE') {
                                                            echo 'bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-800';
                                                            $icon = 'fa-sticky-note';
                                                            $color = 'text-purple-800 dark:text-purple-300';
                                                        } elseif ($label === 'WARNING') {
                                                            echo 'bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800';
                                                            $icon = 'fa-exclamation-triangle';
                                                            $color = 'text-red-800 dark:text-red-300';
                                                        } else {
                                                            echo 'bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800';
                                                            $icon = 'fa-star';
                                                            $color = 'text-yellow-800 dark:text-yellow-300';
                                                        }
                                                    ?>">
                                                        <p class="<?php echo $color; ?>">
                                                            <i class="fas <?php echo $icon; ?> mr-2"></i><strong><?php echo htmlspecialchars($block['label']); ?>:</strong> <?php echo htmlspecialchars($block['content']); ?>
                                                        </p>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="text-gray-700 dark:text-gray-300 leading-relaxed whitespace-pre-wrap mb-4">
                                                        <?php echo nl2br(htmlspecialchars($block['content'])); ?>
                                                    </div>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Additional Media Files (Audio & Documents) -->
                            <?php if (!empty($audioFileUrl)): ?>
                                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 mb-6">
                                    <h4 class="text-lg font-bold text-gray-800 dark:text-white mb-4 flex items-center">
                                        <i class="fas fa-headphones text-purple-600 mr-2"></i>Audio Lesson
                                    </h4>
                                    <audio controls class="w-full rounded-lg">
                                        <source src="<?php echo htmlspecialchars($audioFileUrl); ?>" type="audio/mpeg">
                                        Your browser does not support the audio element.
                                    </audio>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($documentFileUrl)): ?>
                                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 mb-6">
                                    <h4 class="text-lg font-bold text-gray-800 dark:text-white mb-4 flex items-center">
                                        <i class="fas fa-file-pdf text-red-600 mr-2"></i>Lesson Document
                                    </h4>
                                    <a href="<?php echo htmlspecialchars($documentFileUrl); ?>" 
                                       target="_blank"
                                       class="inline-flex items-center px-6 py-3 rounded-lg bg-red-600 hover:bg-red-700 text-white font-semibold transition-all">
                                        <i class="fas fa-download mr-2"></i>Download Document
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Required Assignment Section -->
                        <?php if ($hasMandatoryAssignment): ?>
                            <div class="bg-white dark:bg-gray-800 rounded-2xl border-2 <?php echo $assignmentCompleted ? 'border-emerald-500' : 'border-amber-500'; ?> shadow-lg p-6">
                                <div class="flex items-center justify-between mb-4">
                                    <h3 class="text-xl font-bold text-gray-800 dark:text-white flex items-center">
                                        <i class="fas fa-tasks mr-2 <?php echo $assignmentCompleted ? 'text-emerald-600' : 'text-amber-600'; ?>"></i>
                                        Required Assignment<?php echo count($lessonAssignments) > 1 ? 's' : ''; ?>
                                    </h3>
                                    <?php if ($assignmentCompleted): ?>
                                        <span class="px-3 py-1 rounded-full bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300 text-sm font-semibold">
                                            <i class="fas fa-check-circle mr-1"></i>Completed
                                        </span>
                                    <?php else: ?>
                                        <span class="px-3 py-1 rounded-full bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300 text-sm font-semibold">
                                            <i class="fas fa-exclamation-circle mr-1"></i>Required
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="space-y-4">
                                    <?php foreach ($lessonAssignments as $assignment): ?>
                                        <div class="p-4 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/50">
                                            <h4 class="font-semibold text-gray-800 dark:text-white mb-2"><?php echo htmlspecialchars($assignment['title']); ?></h4>
                                            <?php if (!empty($assignment['description'])): ?>
                                                <p class="text-sm text-gray-600 dark:text-gray-400 mb-3"><?php echo nl2br(htmlspecialchars($assignment['description'])); ?></p>
                                            <?php endif; ?>
                                            <a href="assignments.php?course_id=<?php echo $course['id']; ?>&assignment_id=<?php echo $assignment['id']; ?>" 
                                               class="inline-flex items-center px-4 py-2 rounded-lg bg-primary-600 hover:bg-primary-700 text-white font-semibold transition-all">
                                                <i class="fas fa-arrow-right mr-2"></i>
                                                <?php echo intval($assignment['submission_count'] ?? 0) > 0 ? 'View Submission' : 'Submit Assignment'; ?>
                                            </a>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Required Quiz Section -->
                        <?php if ($hasRequiredQuiz): ?>
                            <div class="bg-white dark:bg-gray-800 rounded-2xl border-2 <?php echo $quizPassed ? 'border-emerald-500' : 'border-purple-500'; ?> shadow-lg p-6">
                                <div class="flex items-center justify-between mb-4">
                                    <h3 class="text-xl font-bold text-gray-800 dark:text-white flex items-center">
                                        <i class="fas fa-question-circle mr-2 <?php echo $quizPassed ? 'text-emerald-600' : 'text-purple-600'; ?>"></i>
                                        Required Quiz<?php echo count($lessonQuizzes) > 1 ? 'zes' : ''; ?>
                                    </h3>
                                    <?php if ($quizPassed): ?>
                                        <span class="px-3 py-1 rounded-full bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300 text-sm font-semibold">
                                            <i class="fas fa-check-circle mr-1"></i>Passed
                                        </span>
                                    <?php else: ?>
                                        <span class="px-3 py-1 rounded-full bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 text-sm font-semibold">
                                            <i class="fas fa-exclamation-circle mr-1"></i>Required
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="space-y-4">
                                    <?php foreach ($lessonQuizzes as $quiz): 
                                        $bestScore = floatval($quiz['best_score'] ?? 0);
                                        $passingScore = intval($quiz['passing_score'] ?? 60);
                                        $isPassed = $bestScore >= $passingScore;
                                    ?>
                                        <div class="p-4 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/50">
                                            <div class="flex items-center justify-between mb-2">
                                                <h4 class="font-semibold text-gray-800 dark:text-white"><?php echo htmlspecialchars($quiz['title']); ?></h4>
                                                <?php if ($isPassed): ?>
                                                    <span class="text-sm text-emerald-600 dark:text-emerald-400 font-semibold">
                                                        Best: <?php echo number_format($bestScore, 1); ?>% ✓
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (!empty($quiz['description'])): ?>
                                                <p class="text-sm text-gray-600 dark:text-gray-400 mb-3"><?php echo nl2br(htmlspecialchars($quiz['description'])); ?></p>
                                            <?php endif; ?>
                                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">
                                                Passing Score: <?php echo $passingScore; ?>% | 
                                                Duration: <?php echo !empty($quiz['duration']) ? $quiz['duration'] . ' min' : 'Unlimited'; ?>
                                            </p>
                                            <a href="quiz.php?id=<?php echo $quiz['id']; ?>" 
                                               class="inline-flex items-center px-4 py-2 rounded-lg bg-purple-600 hover:bg-purple-700 text-white font-semibold transition-all">
                                                <i class="fas fa-play mr-2"></i>
                                                <?php echo $isPassed ? 'Retake Quiz' : 'Take Quiz'; ?>
                                            </a>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Learning Materials -->
                        <?php if (!empty($materials)): ?>
                            <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-lg p-6">
                                <h3 class="text-xl font-bold text-gray-800 dark:text-white mb-4 flex items-center">
                                    <i class="fas fa-file-alt text-primary-600 mr-2"></i>Learning Resources
                                </h3>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <?php foreach ($materials as $material): ?>
                                        <a href="/Iqra-College/uploads/materials/<?php echo htmlspecialchars($material['file_path']); ?>" 
                                           target="_blank"
                                           class="flex items-center justify-between p-4 rounded-xl border border-gray-200 dark:border-gray-700 hover:shadow-md hover:border-primary-500 transition-all">
                                            <div class="flex items-center gap-3">
                                                <div class="w-12 h-12 rounded-lg bg-primary-100 dark:bg-primary-900/30 flex items-center justify-center">
                                                    <i class="fas fa-file-pdf text-primary-600 dark:text-primary-400 text-xl"></i>
                                                </div>
                                                <div>
                                                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                                        <?php echo htmlspecialchars($material['file_name'] ?? 'Resource'); ?>
                                                    </p>
                                                    <?php if (!empty($material['file_size'])): ?>
                                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                                            <?php echo number_format($material['file_size'] / 1024, 1); ?> KB
                                                        </p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <i class="fas fa-download text-gray-400 hover:text-primary-600 transition-colors"></i>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </section>
                </div>
            </div>

            <!-- Footer Action Bar -->
            <div class="sticky bottom-0 z-20 bg-white/95 dark:bg-gray-900/95 border-t border-gray-200 dark:border-gray-700 backdrop-blur px-4 lg:px-8 py-4 mt-10 shadow-lg">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="text-sm text-gray-600 dark:text-gray-400 flex items-center gap-2">
                        <i class="fas fa-cloud-check text-emerald-500"></i>
                        Progress autosaved
                    </div>
                    <div class="flex items-center gap-2">
                        <?php if ($prevLesson): ?>
                            <a href="lesson.php?id=<?php echo $prevLesson['id']; ?>" 
                               class="px-4 py-2 rounded-xl border border-gray-200 dark:border-gray-700 text-sm font-semibold text-gray-700 dark:text-gray-200 hover:shadow-md transition-all">
                                <i class="fas fa-chevron-left mr-2"></i>Previous Lesson
                            </a>
                        <?php endif; ?>
                        <?php if ($nextLesson): 
                            // Check if next lesson can be accessed
                            $canAccessNext = $isCompleted;
                        ?>
                            <?php if ($canAccessNext): ?>
                                <a href="lesson.php?id=<?php echo $nextLesson['id']; ?>" 
                                   class="px-6 py-2 rounded-xl bg-gradient-to-r from-primary-600 to-purple-600 hover:from-primary-700 hover:to-purple-700 text-white text-sm font-semibold shadow-lg transition-all">
                                    Continue Learning<i class="fas fa-chevron-right ml-2"></i>
                                </a>
                            <?php else: ?>
                                <div class="px-6 py-2 rounded-xl bg-gray-300 dark:bg-gray-700 text-gray-500 dark:text-gray-400 text-sm font-semibold cursor-not-allowed relative" 
                                     title="Complete this lesson (including required assignments and quizzes) to unlock the next lesson">
                                    <i class="fas fa-lock mr-2"></i>Continue Locked
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php if ($isCompleted && $finalQuiz && !$finalQuizPassed): ?>
                                <a href="quiz.php?id=<?php echo $finalQuiz['id']; ?>&final=1" 
                                   class="px-6 py-2 rounded-xl bg-gradient-to-r from-emerald-600 to-green-600 hover:from-emerald-700 hover:to-green-700 text-white text-sm font-semibold shadow-lg transition-all">
                                    <i class="fas fa-trophy mr-2"></i>Take Final Quiz
                                </a>
                            <?php elseif ($isCompleted): ?>
                                <a href="certificate.php?course_id=<?php echo $course['id']; ?>&congrats=1" 
                                   class="px-6 py-2 rounded-xl bg-gradient-to-r from-yellow-500 to-amber-600 hover:from-yellow-600 hover:to-amber-700 text-white text-sm font-semibold shadow-lg transition-all">
                                    <i class="fas fa-certificate mr-2"></i>View Certificate
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
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
                    if (sidebar) sidebar.classList.toggle('hidden');
                    if (sidebarOverlay) sidebarOverlay.classList.toggle('hidden');
                });
            }
            
            if (sidebarOverlay) {
                sidebarOverlay.addEventListener('click', () => {
                    if (sidebar) sidebar.classList.add('hidden');
                    sidebarOverlay.classList.add('hidden');
                });
            }
            
            // Track time spent on lesson
            let startTime = Date.now();
            let timeTracked = false;
            
            function trackTime() {
                if (timeTracked) return;
                const timeSpent = Math.floor((Date.now() - startTime) / 1000);
                if (timeSpent > 10) {
                    timeTracked = true;
                    fetch('lesson.php?id=<?php echo $lessonId; ?>', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'update_time=' + timeSpent
                    }).catch(() => {});
                }
            }
            
            // Track time every 30 seconds
            setInterval(trackTime, 30000);
            
            window.addEventListener('beforeunload', trackTime);
            
            // Ensure dark mode is initialized
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
