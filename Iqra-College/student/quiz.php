<?php
/**
 * Student - Take Quiz (Professional E-Learning Platform)
 * Features:
 * - Timer functionality for timed quizzes
 * - Progress tracking
 * - Professional UI/UX matching lesson page
 * - Dark mode support
 * - Mobile responsive
 * - Enhanced result display
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

requireRole('student');

$studentId = getCurrentUserId();
$quizId = intval($_GET['id'] ?? 0);
$isFinalQuiz = isset($_GET['final']) && $_GET['final'] == '1';

if ($quizId <= 0) {
    header('Location: /Iqra-College/student/courses.php');
    exit();
}

// Get quiz
$quiz = getQuizById($quizId);
if (!$quiz) {
    header('Location: /Iqra-College/student/courses.php');
    exit();
}

// Get course to verify enrollment
$course = getCourseById($quiz['course_id']);
if (!$course || !isEnrolled($studentId, $course['id'])) {
    header('Location: /Iqra-College/student/courses.php');
    exit();
}

// Get questions
$questions = getQuestionsByQuiz($quizId);

$pdo = getDBConnection();
$error = '';
$showResult = false;
$score = 0;
$totalQuestions = count($questions);
$totalPoints = 0;
foreach ($questions as $question) {
    $totalPoints += intval($question['points'] ?? 1);
}
$quizDuration = intval($quiz['duration'] ?? 0);
$displayTotalMarks = intval($quiz['total_marks'] ?? 0);
if ($displayTotalMarks <= 0) {
    $displayTotalMarks = $totalPoints;
}
$attemptId = null;
$studentAnswers = [];
$courseAverage = 0;
$passingScore = $quiz['passing_score'] ?? 60;

// Check previous attempts
$previousAttempts = [];
$bestScore = 0;
$maxAttempts = intval($quiz['max_attempts'] ?? 0);
try {
    $stmt = $pdo->prepare("
        SELECT id, score, percentage, completed_at, status
        FROM quiz_attempts 
        WHERE quiz_id = ? AND student_id = ?
        ORDER BY completed_at DESC
    ");
    $stmt->execute([$quizId, $studentId]);
    $previousAttempts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($previousAttempts)) {
        foreach ($previousAttempts as $attempt) {
            $attemptPercent = floatval($attempt['percentage'] ?? 0);
            if ($attemptPercent > $bestScore) {
                $bestScore = $attemptPercent;
            }
        }
    }
} catch (PDOException $e) {
    // Continue
}

// Check if can retake
$canRetake = true;
if ($maxAttempts > 0 && count($previousAttempts) >= $maxAttempts) {
    $canRetake = false;
}

// Handle quiz submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_quiz'])) {
    if (!$canRetake && $maxAttempts > 0) {
        $error = 'You have reached the maximum number of attempts for this quiz.';
    } else {
    $answers = $_POST['answers'] ?? [];
    $correctCount = 0;
        $pointsEarned = 0;
    
    foreach ($questions as $question) {
        $userAnswer = $answers[$question['id']] ?? '';
            $questionPoints = intval($question['points'] ?? 1);
        if ($userAnswer === $question['correct_answer']) {
            $correctCount++;
                $pointsEarned += $questionPoints;
            }
    }
    
    $score = $correctCount;
    $percentage = ($totalQuestions > 0) ? ($score / $totalQuestions) * 100 : 0;
    
    // Save quiz attempt and answers
    try {
        $pdo->beginTransaction();
        
            // Insert quiz attempt
        $attemptId = null;
        $inserted = false;
        $lastError = null;
        
        $insertAttempts = [
            [
                'sql' => "INSERT INTO quiz_attempts (student_id, quiz_id, score, total_questions, percentage, status, completed_at) VALUES (?, ?, ?, ?, ?, 'completed', NOW())",
                'params' => [$studentId, $quizId, $score, $totalQuestions, $percentage]
            ],
            [
                'sql' => "INSERT INTO quiz_attempts (student_id, quiz_id, score, total_questions, percentage, completed_at) VALUES (?, ?, ?, ?, ?, NOW())",
                'params' => [$studentId, $quizId, $score, $totalQuestions, $percentage]
            ],
            [
                'sql' => "INSERT INTO quiz_attempts (student_id, quiz_id, score, total_questions, percentage, status) VALUES (?, ?, ?, ?, ?, 'completed')",
                'params' => [$studentId, $quizId, $score, $totalQuestions, $percentage]
            ],
            [
                'sql' => "INSERT INTO quiz_attempts (student_id, quiz_id, score, total_questions, percentage) VALUES (?, ?, ?, ?, ?)",
                'params' => [$studentId, $quizId, $score, $totalQuestions, $percentage]
            ],
            [
                'sql' => "INSERT INTO quiz_attempts (student_id, quiz_id, score, status, completed_at) VALUES (?, ?, ?, 'completed', NOW())",
                'params' => [$studentId, $quizId, $score]
            ],
            [
                'sql' => "INSERT INTO quiz_attempts (student_id, quiz_id, score, completed_at) VALUES (?, ?, ?, NOW())",
                'params' => [$studentId, $quizId, $score]
            ],
            [
                'sql' => "INSERT INTO quiz_attempts (student_id, quiz_id, score, status) VALUES (?, ?, ?, 'completed')",
                'params' => [$studentId, $quizId, $score]
            ],
            [
                'sql' => "INSERT INTO quiz_attempts (student_id, quiz_id, score) VALUES (?, ?, ?)",
                'params' => [$studentId, $quizId, $score]
            ]
        ];
        
        foreach ($insertAttempts as $attempt) {
            try {
                $stmt = $pdo->prepare($attempt['sql']);
                $stmt->execute($attempt['params']);
                $attemptId = $pdo->lastInsertId();
                $inserted = true;
                    break;
            } catch (PDOException $e) {
                $lastError = $e;
                continue;
            }
        }
        
        if (!$inserted) {
            throw $lastError ?: new Exception('Failed to insert quiz attempt');
        }
        
            // Insert or update student answers
        foreach ($questions as $question) {
            $userAnswer = $answers[$question['id']] ?? '';
            $isCorrect = ($userAnswer === $question['correct_answer']);
            $pointsEarned = $isCorrect ? ($question['points'] ?? 1) : 0;
            
                try {
            $stmt = $pdo->prepare(
                "INSERT INTO student_answers (attempt_id, question_id, student_answer, is_correct, points_earned)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE student_answer = VALUES(student_answer), is_correct = VALUES(is_correct), points_earned = VALUES(points_earned)"
            );
            $stmt->execute([$attemptId, $question['id'], $userAnswer, $isCorrect ? 1 : 0, $pointsEarned]);
                } catch (PDOException $e) {
                    // Try without ON DUPLICATE KEY UPDATE
                    try {
                        $stmt = $pdo->prepare(
                            "INSERT INTO student_answers (attempt_id, question_id, student_answer, is_correct, points_earned)
                             VALUES (?, ?, ?, ?, ?)"
                        );
                        $stmt->execute([$attemptId, $question['id'], $userAnswer, $isCorrect ? 1 : 0, $pointsEarned]);
                    } catch (PDOException $e2) {
                        // Skip if table doesn't exist
                    }
                }
        }
        
        $pdo->commit();
        
        // Get student answers for display
            try {
        $stmt = $pdo->prepare("SELECT * FROM student_answers WHERE attempt_id = ?");
        $stmt->execute([$attemptId]);
        $studentAnswers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $studentAnswers = [];
            }
        
        // Create a map of question_id => student_answer
        $answerMap = [];
        foreach ($studentAnswers as $ans) {
            $answerMap[$ans['question_id']] = $ans;
        }
        
            // Calculate course average
        try {
            $stmt = $pdo->prepare("SELECT AVG(percentage) as avg_score FROM quiz_attempts qa 
                                  JOIN quizzes q ON qa.quiz_id = q.id 
                                  WHERE q.course_id = ? AND qa.student_id = ?");
            $stmt->execute([$course['id'], $studentId]);
            $result = $stmt->fetch();
            $courseAverage = $result['avg_score'] ?? 0;
        } catch (PDOException $e) {
            try {
                $stmt = $pdo->prepare("SELECT AVG((score * 100.0 / total_questions)) as avg_score FROM quiz_attempts qa 
                                      JOIN quizzes q ON qa.quiz_id = q.id 
                                      WHERE q.course_id = ? AND qa.student_id = ?");
                $stmt->execute([$course['id'], $studentId]);
                $result = $stmt->fetch();
                $courseAverage = $result['avg_score'] ?? 0;
            } catch (PDOException $e2) {
                $courseAverage = 0;
            }
        }
        
        $showResult = true;
            
            // If final quiz passed, redirect to certificate
            if ($isFinalQuiz && $percentage >= $passingScore) {
                header('Location: certificate.php?course_id=' . (int)$course['id'] . '&congrats=1');
                exit;
            }
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = 'Failed to save quiz attempt: ' . $e->getMessage();
        error_log('Quiz attempt error: ' . $e->getMessage());
        }
    }
}

// Get course progress for sidebar
$completedLessons = 0;
$totalLessons = 0;
$courseProgressPercent = 0;
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
    $progressData = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($progressData) {
        $totalLessons = intval($progressData['total_lessons']);
        $completedLessons = intval($progressData['completed_lessons']);
        $courseProgressPercent = $totalLessons > 0 ? round(($completedLessons / $totalLessons) * 100) : 0;
    }
} catch (PDOException $e) {
    // Continue
}
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo isset($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === 'enabled' ? 'dark' : ''; ?>">
<head>
    <link rel="icon" href="/Iqra-College/assets/images/iqra2.png" type="image/png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($quiz['title']); ?> - Quiz</title>
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
        }
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .progress-bar {
            transition: width 0.6s ease;
        }
        .timer-warning {
            animation: pulse 1s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-200 min-h-screen transition-colors duration-300">
    <div class="flex">
        <?php include __DIR__ . '/../includes/student_sidebar.php'; ?>

        <main class="ml-0 lg:ml-64 flex-1 transition-all duration-300">
            <!-- Top Quiz Bar -->
            <div class="bg-gradient-to-r from-purple-600 via-blue-600 to-emerald-600 text-white px-4 lg:px-8 py-4 sticky top-0 z-20 shadow-lg">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <button id="mobile-menu-toggle" class="lg:hidden w-9 h-9 rounded-full bg-white/20 flex items-center justify-center hover:bg-white/30">
                            <i class="fas fa-bars"></i>
                        </button>
                        <a href="/Iqra-College/student/courses.php?id=<?php echo $course['id']; ?>" class="w-9 h-9 rounded-full bg-white/20 flex items-center justify-center hover:bg-white/30 hidden lg:flex">
                            <i class="fas fa-arrow-left"></i>
                        </a>
                        <div>
                            <p class="text-xs text-purple-100">Course: <?php echo htmlspecialchars($course['title']); ?></p>
                            <p class="font-semibold text-sm"><?php echo htmlspecialchars($quiz['title']); ?></p>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <?php if (!$showResult && $quizDuration > 0): ?>
                            <div id="quiz-timer" class="px-4 py-2 rounded-full bg-white/20 backdrop-blur-sm flex items-center gap-2">
                                <i class="fas fa-clock"></i>
                                <span id="timer-display"><?php echo $quizDuration; ?>:00</span>
                            </div>
                        <?php endif; ?>
                        <?php if ($isFinalQuiz): ?>
                            <span class="px-3 py-1 rounded-full bg-yellow-500/30 text-white text-xs font-semibold">
                                <i class="fas fa-trophy mr-1"></i>Final Quiz
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="bg-red-100 dark:bg-red-900/30 border-l-4 border-red-500 text-red-700 dark:text-red-400 px-4 py-3 mx-4 mt-4 rounded-lg fade-in">
                    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <div class="p-4 lg:p-8">
            <?php if ($showResult): ?>
                    <!-- Results Section -->
                    <div class="max-w-5xl mx-auto space-y-6">
                        <!-- Score Card -->
                        <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-lg p-6 lg:p-8 fade-in">
                            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6 mb-6">
                        <div class="space-y-4">
                                    <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full text-sm font-semibold <?php echo ($percentage >= $passingScore) ? 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300' : 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300'; ?>">
                                        <i class="fas <?php echo ($percentage >= $passingScore) ? 'fa-check-circle' : 'fa-times-circle'; ?>"></i>
                                        <?php echo ($percentage >= $passingScore) ? 'Passed' : 'Not Passed'; ?>
                                    </div>
                                    <h2 class="text-3xl lg:text-4xl font-bold text-gray-800 dark:text-white">Quiz Completed!</h2>
                                    <p class="text-gray-600 dark:text-gray-400">Review your score and answers below.</p>
                                </div>
                                <div class="text-center">
                                    <div class="text-6xl font-bold text-gray-800 dark:text-white"><?php echo $score; ?>/<?php echo $totalQuestions; ?></div>
                                    <div class="text-2xl text-gray-600 dark:text-gray-400 mt-2"><?php echo number_format($percentage, 1); ?>%</div>
                                </div>
                            </div>
                            
                            <!-- Progress Bar -->
                            <div class="mb-6">
                                <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                                    <div class="progress-bar h-full <?php echo ($percentage >= $passingScore) ? 'bg-gradient-to-r from-emerald-500 to-green-500' : 'bg-gradient-to-r from-amber-500 to-orange-500'; ?>" style="width: <?php echo min(100, max(0, $percentage)); ?>%"></div>
                        </div>
                                <div class="mt-2 flex justify-between text-sm text-gray-500 dark:text-gray-400">
                                    <span>Passing score: <?php echo $passingScore; ?>%</span>
                                    <span><?php echo number_format($percentage, 1); ?>%</span>
                        </div>
                    </div>
                            
                            <!-- Stats Grid -->
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                                <div class="p-4 rounded-xl bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800">
                                    <p class="text-sm text-blue-600 dark:text-blue-400 mb-1">Total Marks</p>
                                    <p class="text-2xl font-bold text-blue-800 dark:text-blue-200"><?php echo $displayTotalMarks; ?></p>
                                </div>
                                <div class="p-4 rounded-xl bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-800">
                                    <p class="text-sm text-purple-600 dark:text-purple-400 mb-1">Course Average</p>
                                    <p class="text-2xl font-bold text-purple-800 dark:text-purple-200"><?php echo number_format($courseAverage, 1); ?>%</p>
                        </div>
                                <div class="p-4 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800">
                                    <p class="text-sm text-emerald-600 dark:text-emerald-400 mb-1">Best Score</p>
                                    <p class="text-2xl font-bold text-emerald-800 dark:text-emerald-200"><?php echo number_format($bestScore, 1); ?>%</p>
                    </div>
                        </div>
                            
                            <!-- Action Buttons -->
                            <div class="flex flex-wrap gap-3">
                                <a href="/Iqra-College/student/courses.php?id=<?php echo $course['id']; ?>" class="inline-flex items-center px-6 py-3 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-semibold transition-all">
                                    <i class="fas fa-arrow-left mr-2"></i>Back to Course
                                </a>
                                <?php if ($canRetake && $percentage < $passingScore): ?>
                                    <a href="quiz.php?id=<?php echo $quizId; ?><?php echo $isFinalQuiz ? '&final=1' : ''; ?>" class="inline-flex items-center px-6 py-3 rounded-lg bg-purple-600 hover:bg-purple-700 text-white font-semibold transition-all">
                                        <i class="fas fa-redo mr-2"></i>Retake Quiz
                                    </a>
                                <?php endif; ?>
                                <?php if ($isFinalQuiz && $percentage >= $passingScore): ?>
                                    <a href="certificate.php?course_id=<?php echo $course['id']; ?>&congrats=1" class="inline-flex items-center px-6 py-3 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-semibold transition-all">
                                        <i class="fas fa-certificate mr-2"></i>View Certificate
                                    </a>
                                <?php endif; ?>
                        </div>
                        </div>

                        <!-- Answers Review -->
                        <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-lg p-6 lg:p-8 fade-in">
                            <h3 class="text-2xl font-bold text-gray-800 dark:text-white mb-6 flex items-center">
                                <i class="fas fa-list-check mr-3 text-purple-600"></i>Your Answers
                            </h3>
                    <div class="space-y-6">
                        <?php foreach ($questions as $index => $question):
                            $studentAnswer = $answerMap[$question['id']] ?? null;
                            $userAnswer = $studentAnswer ? $studentAnswer['student_answer'] : '';
                            $isCorrect = $studentAnswer ? (bool)$studentAnswer['is_correct'] : false;
                            $optionLabels = ['a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D'];
                            $optionFields = ['a' => 'option_a', 'b' => 'option_b', 'c' => 'option_c', 'd' => 'option_d'];
                        ?>
                                    <div class="rounded-xl border-2 <?php echo $isCorrect ? 'border-emerald-300 bg-emerald-50/50 dark:bg-emerald-900/20 dark:border-emerald-700' : 'border-red-300 bg-red-50/50 dark:bg-red-900/20 dark:border-red-700'; ?> p-6">
                                <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
                                            <h4 class="text-lg font-semibold text-gray-800 dark:text-white">
                                        Question <?php echo $index + 1; ?>: <?php echo htmlspecialchars($question['question']); ?>
                                    </h4>
                                            <span class="px-3 py-1 rounded-full text-sm font-semibold <?php echo $isCorrect ? 'bg-emerald-500 text-white' : 'bg-red-500 text-white'; ?>">
                                                <i class="fas <?php echo $isCorrect ? 'fa-check' : 'fa-times'; ?> mr-1"></i>
                                        <?php echo $isCorrect ? 'Correct' : 'Incorrect'; ?>
                                    </span>
                                </div>
                                <div class="space-y-3">
                                    <?php foreach (['a', 'b', 'c', 'd'] as $option):
                                        $isCorrectAnswer = ($option === $question['correct_answer']);
                                        $isUserAnswer = ($option === $userAnswer);
                                                $optionClass = 'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800';
                                        $badge = '';
                                                $icon = '';

                                        if ($isCorrectAnswer) {
                                                    $optionClass = 'border-emerald-400 bg-emerald-100 dark:bg-emerald-900/30 dark:border-emerald-600';
                                                    $badge = 'Correct Answer';
                                                    $icon = 'fa-check-circle text-emerald-600 dark:text-emerald-400';
                                        } elseif ($isUserAnswer && !$isCorrect) {
                                                    $optionClass = 'border-red-400 bg-red-100 dark:bg-red-900/30 dark:border-red-600';
                                                    $badge = 'Your Answer (Incorrect)';
                                                    $icon = 'fa-times-circle text-red-600 dark:text-red-400';
                                        }
                                    ?>
                                                <div class="flex items-start gap-3 rounded-lg border-2 <?php echo $optionClass; ?> p-4">
                                                    <div class="h-8 w-8 rounded-full bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 flex items-center justify-center text-sm font-semibold flex-shrink-0">
                                                <?php echo $optionLabels[$option]; ?>
                                            </div>
                                            <div class="flex-1">
                                                        <p class="text-gray-800 dark:text-gray-200"><?php echo htmlspecialchars($question[$optionFields[$option]]); ?></p>
                                                <?php if ($badge): ?>
                                                            <p class="text-xs font-semibold mt-2 flex items-center gap-1">
                                                                <?php if ($icon): ?>
                                                                    <i class="fas <?php echo $icon; ?>"></i>
                                                                <?php endif; ?>
                                                                <span class="text-gray-600 dark:text-gray-400"><?php echo $badge; ?></span>
                                                            </p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                        </div>
                    </div>
                <?php elseif (empty($questions)): ?>
                    <!-- No Questions -->
                    <div class="max-w-2xl mx-auto bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-lg p-8 text-center fade-in">
                        <div class="w-20 h-20 mx-auto mb-4 rounded-full bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center">
                            <i class="fas fa-exclamation-triangle text-amber-600 dark:text-amber-400 text-3xl"></i>
                        </div>
                        <h2 class="text-2xl font-bold text-gray-800 dark:text-white mb-2">No Questions Available</h2>
                        <p class="text-gray-600 dark:text-gray-400 mb-6">Your teacher has not added questions for this quiz yet.</p>
                        <a href="/Iqra-College/student/courses.php?id=<?php echo $course['id']; ?>" class="inline-flex items-center px-6 py-3 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-semibold transition-all">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Course
                        </a>
                    </div>
                <?php else: ?>
                    <!-- Quiz Form -->
                    <div class="max-w-6xl mx-auto">
                        <div class="grid gap-6 lg:grid-cols-3">
                            <!-- Main Quiz Content -->
                            <form method="POST" id="quiz-form" class="space-y-6 lg:col-span-2">
                            <input type="hidden" name="submit_quiz" value="1">
                                
                            <?php foreach ($questions as $index => $question): ?>
                                    <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-lg p-6 fade-in" style="animation-delay: <?php echo $index * 0.1; ?>s">
                                        <div class="flex items-center justify-between mb-4 text-sm">
                                            <span class="text-gray-500 dark:text-gray-400">
                                                Question <?php echo $index + 1; ?> of <?php echo $totalQuestions; ?>
                                            </span>
                                            <span class="px-3 py-1 rounded-full bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 font-semibold">
                                                <?php echo intval($question['points'] ?? 1); ?> pts
                                            </span>
                                    </div>
                                        <h3 class="text-xl font-semibold text-gray-800 dark:text-white mb-6">
                                        <?php echo htmlspecialchars($question['question']); ?>
                                    </h3>
                                    <div class="space-y-3">
                                        <?php foreach (['a', 'b', 'c', 'd'] as $option):
                                            $optionId = 'q' . $question['id'] . '-' . $option;
                                            $optionValue = $question['option_' . $option] ?? '';
                                                if (empty($optionValue)) continue;
                                        ?>
                                            <div>
                                                <input id="<?php echo $optionId; ?>" type="radio"
                                                       name="answers[<?php echo $question['id']; ?>]"
                                                       value="<?php echo $option; ?>"
                                                       class="peer sr-only"
                                                           required>
                                                    <label for="<?php echo $optionId; ?>" class="flex items-center gap-4 rounded-xl border-2 border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-5 py-4 cursor-pointer transition-all hover:border-purple-300 dark:hover:border-purple-600 hover:bg-purple-50 dark:hover:bg-purple-900/20 peer-checked:border-purple-500 dark:peer-checked:border-purple-500 peer-checked:bg-purple-100 dark:peer-checked:bg-purple-900/30">
                                                        <span class="h-10 w-10 rounded-full bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 flex items-center justify-center text-sm font-bold flex-shrink-0">
                                                        <?php echo strtoupper($option); ?>
                                                    </span>
                                                        <span class="text-gray-800 dark:text-gray-200 flex-1"><?php echo htmlspecialchars($optionValue); ?></span>
                                                        <i class="fas fa-circle-check text-purple-500 opacity-0 peer-checked:opacity-100 transition-opacity"></i>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                                
                                <div class="flex justify-end gap-3 pt-4">
                                    <button type="button" onclick="if(confirm('Are you sure you want to submit? Make sure you have answered all questions.')) document.getElementById('quiz-form').submit();" 
                                            class="inline-flex items-center px-8 py-4 rounded-lg bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 text-white font-bold text-lg shadow-lg hover:shadow-xl transition-all">
                                        <i class="fas fa-paper-plane mr-2"></i>Submit Quiz
                                </button>
                            </div>
                        </form>
                            
                            <!-- Sidebar -->
                        <aside class="space-y-5">
                                <!-- Quiz Info Card -->
                                <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-lg p-6 sticky top-24">
                                    <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-4 flex items-center">
                                        <i class="fas fa-info-circle text-blue-600 mr-2"></i>Quiz Details
                                    </h3>
                                    <div class="space-y-3 text-sm">
                                        <div class="flex justify-between">
                                            <span class="text-gray-600 dark:text-gray-400">Questions:</span>
                                            <span class="font-semibold text-gray-800 dark:text-white"><?php echo $totalQuestions; ?></span>
                                        </div>
                                        <div class="flex justify-between">
                                            <span class="text-gray-600 dark:text-gray-400">Total Marks:</span>
                                            <span class="font-semibold text-gray-800 dark:text-white"><?php echo $displayTotalMarks; ?></span>
                                        </div>
                                        <div class="flex justify-between">
                                            <span class="text-gray-600 dark:text-gray-400">Passing Score:</span>
                                            <span class="font-semibold text-gray-800 dark:text-white"><?php echo $passingScore; ?>%</span>
                                        </div>
                                        <div class="flex justify-between">
                                            <span class="text-gray-600 dark:text-gray-400">Time Limit:</span>
                                            <span class="font-semibold text-gray-800 dark:text-white"><?php echo $quizDuration > 0 ? $quizDuration . ' min' : 'No limit'; ?></span>
                                        </div>
                                        <?php if ($maxAttempts > 0): ?>
                                            <div class="flex justify-between">
                                                <span class="text-gray-600 dark:text-gray-400">Max Attempts:</span>
                                                <span class="font-semibold text-gray-800 dark:text-white"><?php echo $maxAttempts; ?></span>
                                            </div>
                                            <div class="flex justify-between">
                                                <span class="text-gray-600 dark:text-gray-400">Your Attempts:</span>
                                                <span class="font-semibold text-gray-800 dark:text-white"><?php echo count($previousAttempts); ?>/<?php echo $maxAttempts; ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($bestScore > 0): ?>
                                            <div class="flex justify-between pt-2 border-t border-gray-200 dark:border-gray-700">
                                                <span class="text-gray-600 dark:text-gray-400">Best Score:</span>
                                                <span class="font-semibold text-emerald-600 dark:text-emerald-400"><?php echo number_format($bestScore, 1); ?>%</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- Progress Card -->
                                <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-lg p-6">
                                    <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-4 flex items-center">
                                        <i class="fas fa-chart-line text-purple-600 mr-2"></i>Progress
                                    </h3>
                                    <div class="space-y-2">
                                        <div class="flex justify-between text-sm">
                                            <span class="text-gray-600 dark:text-gray-400">Answered</span>
                                            <span class="font-semibold text-gray-800 dark:text-white" id="answered-count">0/<?php echo $totalQuestions; ?></span>
                                        </div>
                                        <div class="h-2 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                                            <div class="h-full bg-gradient-to-r from-purple-500 to-blue-500 transition-all duration-300" id="progress-bar" style="width: 0%"></div>
                                        </div>
                                </div>
                            </div>
                                
                                <!-- Instructions Card -->
                                <div class="bg-gradient-to-br from-purple-50 to-blue-50 dark:from-purple-900/20 dark:to-blue-900/20 rounded-2xl border border-purple-200 dark:border-purple-800 p-6">
                                    <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-3 flex items-center">
                                        <i class="fas fa-lightbulb text-yellow-500 mr-2"></i>Instructions
                                    </h3>
                                    <ul class="space-y-2 text-sm text-gray-700 dark:text-gray-300">
                                        <li class="flex items-start gap-2">
                                            <i class="fas fa-check-circle text-purple-600 mt-0.5"></i>
                                            <span>Read each question carefully</span>
                                        </li>
                                        <li class="flex items-start gap-2">
                                            <i class="fas fa-check-circle text-purple-600 mt-0.5"></i>
                                            <span>Select the best answer</span>
                                        </li>
                                        <li class="flex items-start gap-2">
                                            <i class="fas fa-check-circle text-purple-600 mt-0.5"></i>
                                            <span>Review before submitting</span>
                                        </li>
                                        <?php if ($quizDuration > 0): ?>
                                            <li class="flex items-start gap-2">
                                                <i class="fas fa-clock text-purple-600 mt-0.5"></i>
                                                <span>Quiz will auto-submit when time runs out</span>
                                            </li>
                                        <?php endif; ?>
                                </ul>
                            </div>
                            </aside>
                            </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <?php include __DIR__ . '/../includes/student_ai_button.php'; ?>

    <script>
        // Mobile menu toggle
        document.getElementById('mobile-menu-toggle')?.addEventListener('click', function() {
            const sidebar = document.querySelector('aside');
            if (sidebar) {
                sidebar.classList.toggle('hidden');
            }
        });

        // Quiz timer
        <?php if (!$showResult && $quizDuration > 0): ?>
        (function() {
            let timeLeft = <?php echo $quizDuration * 60; ?>; // Convert to seconds
            const timerDisplay = document.getElementById('timer-display');
            const timerContainer = document.getElementById('quiz-timer');
            const form = document.getElementById('quiz-form');
            
            function updateTimer() {
                const minutes = Math.floor(timeLeft / 60);
                const seconds = timeLeft % 60;
                timerDisplay.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
                
                if (timeLeft <= 300) { // 5 minutes
                    timerContainer.classList.add('timer-warning', 'bg-red-500/30');
                } else if (timeLeft <= 600) { // 10 minutes
                    timerContainer.classList.add('bg-amber-500/30');
                }
                
                if (timeLeft <= 0) {
                    alert('Time is up! Submitting your quiz...');
                    if (form) form.submit();
                    return;
                }
                
                timeLeft--;
            }
            
            updateTimer();
            const timerInterval = setInterval(updateTimer, 1000);
        })();
        <?php endif; ?>

        // Progress tracking
        (function() {
            const form = document.getElementById('quiz-form');
            if (!form) return;
            
            const answeredCount = document.getElementById('answered-count');
            const progressBar = document.getElementById('progress-bar');
            const totalQuestions = <?php echo $totalQuestions; ?>;
            
            function updateProgress() {
                const answered = form.querySelectorAll('input[type="radio"]:checked').length;
                const progress = (answered / totalQuestions) * 100;
                
                if (answeredCount) {
                    answeredCount.textContent = `${answered}/${totalQuestions}`;
                }
                if (progressBar) {
                    progressBar.style.width = `${progress}%`;
                }
            }
            
            form.addEventListener('change', updateProgress);
            updateProgress();
        })();
    </script>
</body>
</html>
