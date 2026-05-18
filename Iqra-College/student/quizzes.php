<?php
/**
 * Student - Quizzes (Modern Design)
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

requireRole('student');

$studentId = getCurrentUserId();
$pdo = getDBConnection();
$name = getCurrentUserName();

// Get all quizzes for enrolled courses
try {
    $stmt = $pdo->prepare("
        SELECT q.*, c.title as course_name, c.id as course_id,
               qa.id as attempt_id, qa.score, qa.percentage, qa.completed_at, qa.status,
               (SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = q.id AND student_id = ?) as attempt_count
        FROM quizzes q
        JOIN courses c ON q.course_id = c.id
        JOIN enrollments e ON c.id = e.course_id
        LEFT JOIN quiz_attempts qa ON q.id = qa.quiz_id AND qa.student_id = ? AND qa.status = 'completed'
        WHERE e.student_id = ? AND (q.is_published = 1 OR q.is_published IS NULL)
        ORDER BY q.created_at DESC
    ");
    $stmt->execute([$studentId, $studentId, $studentId]);
    $quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $quizzes = [];
}

// Separate quizzes by status
$available = [];
$inProgress = [];
$completed = [];

foreach ($quizzes as $quiz) {
    $hasAttempt = !empty($quiz['attempt_id']);
    $isCompleted = !empty($quiz['completed_at']) || ($quiz['status'] ?? '') === 'completed';
    $maxAttempts = intval($quiz['max_attempts'] ?? 1);
    $attemptCount = intval($quiz['attempt_count'] ?? 0);
    $canRetake = $attemptCount < $maxAttempts;
    
    // Check for in-progress attempts
    try {
        $progressStmt = $pdo->prepare("
            SELECT id FROM quiz_attempts 
            WHERE quiz_id = ? AND student_id = ? AND status = 'in_progress'
            ORDER BY started_at DESC LIMIT 1
        ");
        $progressStmt->execute([$quiz['id'], $studentId]);
        $inProgressAttempt = $progressStmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $inProgressAttempt = false;
    }
    
    if ($inProgressAttempt) {
        $inProgress[] = $quiz;
    } elseif ($isCompleted) {
        $completed[] = $quiz;
    } else {
        $available[] = $quiz;
    }
}

// Calculate average score for completed quizzes
$avgScore = 0;
if (!empty($completed)) {
    $totalScore = 0;
    $count = 0;
    foreach ($completed as $quiz) {
        if (!empty($quiz['percentage'])) {
            $totalScore += floatval($quiz['percentage']);
            $count++;
        }
    }
    $avgScore = $count > 0 ? round($totalScore / $count, 1) : 0;
}

$pageTitle = 'Quizzes';
$pageSubtitle = 'Take quizzes and track your progress';
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
        .progress-ring {
            transform: rotate(-90deg);
        }
        .progress-ring-circle {
            transition: stroke-dashoffset 0.35s;
            transform: rotate(-90deg);
            transform-origin: 50% 50%;
        }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-200 min-h-screen transition-colors duration-300">
    <div class="flex">
        <?php 
        $currentPage = 'quizzes';
        include __DIR__ . '/../includes/student_sidebar.php'; 
        ?>

        <main class="ml-0 lg:ml-64 flex-1 p-4 lg:p-8 transition-all duration-300">
            <?php include __DIR__ . '/../includes/student_header.php'; ?>

            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="card-hover bg-gradient-to-br from-white to-blue-50 dark:from-gray-800 dark:to-gray-900 rounded-2xl p-6 border border-gray-200 dark:border-gray-700 shadow-lg stagger-item relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-blue-200 dark:bg-blue-900/30 rounded-full -mr-16 -mt-16 opacity-20"></div>
                    <div class="relative flex justify-between items-center">
                        <div>
                            <p class="text-gray-600 dark:text-gray-400 text-sm font-semibold uppercase tracking-wide mb-2">Available</p>
                            <p class="text-4xl font-extrabold bg-gradient-to-r from-blue-600 to-blue-800 dark:from-blue-400 dark:to-blue-600 bg-clip-text text-transparent"><?php echo count($available); ?></p>
                            <p class="text-xs text-gray-600 dark:text-gray-400 mt-2">ready to take</p>
                        </div>
                        <div class="bg-gradient-to-br from-blue-500 to-blue-700 p-4 rounded-2xl shadow-lg">
                            <i class="fas fa-play-circle text-white text-2xl"></i>
                        </div>
                    </div>
                </div>

                <div class="card-hover bg-gradient-to-br from-white to-yellow-50 dark:from-gray-800 dark:to-gray-900 rounded-2xl p-6 border border-gray-200 dark:border-gray-700 shadow-lg stagger-item relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-yellow-200 dark:bg-yellow-900/30 rounded-full -mr-16 -mt-16 opacity-20"></div>
                    <div class="relative flex justify-between items-center">
                        <div>
                            <p class="text-gray-600 dark:text-gray-400 text-sm font-semibold uppercase tracking-wide mb-2">In Progress</p>
                            <p class="text-4xl font-extrabold bg-gradient-to-r from-yellow-600 to-yellow-800 dark:from-yellow-400 dark:to-yellow-600 bg-clip-text text-transparent"><?php echo count($inProgress); ?></p>
                            <p class="text-xs text-gray-600 dark:text-gray-400 mt-2">continue quiz</p>
                        </div>
                        <div class="bg-gradient-to-br from-yellow-500 to-yellow-700 p-4 rounded-2xl shadow-lg">
                            <i class="fas fa-clock text-white text-2xl"></i>
                        </div>
                    </div>
                </div>

                <div class="card-hover bg-gradient-to-br from-white to-green-50 dark:from-gray-800 dark:to-gray-900 rounded-2xl p-6 border border-gray-200 dark:border-gray-700 shadow-lg stagger-item relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-green-200 dark:bg-green-900/30 rounded-full -mr-16 -mt-16 opacity-20"></div>
                    <div class="relative flex justify-between items-center">
                        <div>
                            <p class="text-gray-600 dark:text-gray-400 text-sm font-semibold uppercase tracking-wide mb-2">Completed</p>
                            <p class="text-4xl font-extrabold bg-gradient-to-r from-green-600 to-emerald-600 dark:from-green-400 dark:to-emerald-400 bg-clip-text text-transparent"><?php echo count($completed); ?></p>
                            <p class="text-xs text-gray-600 dark:text-gray-400 mt-2">quizzes finished</p>
                        </div>
                        <div class="bg-gradient-to-br from-green-500 to-emerald-600 p-4 rounded-2xl shadow-lg">
                            <i class="fas fa-check-circle text-white text-2xl"></i>
                        </div>
                    </div>
                </div>

                <div class="card-hover bg-gradient-to-br from-white to-purple-50 dark:from-gray-800 dark:to-gray-900 rounded-2xl p-6 border border-gray-200 dark:border-gray-700 shadow-lg stagger-item relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-purple-200 dark:bg-purple-900/30 rounded-full -mr-16 -mt-16 opacity-20"></div>
                    <div class="relative flex justify-between items-center">
                        <div>
                            <p class="text-gray-600 dark:text-gray-400 text-sm font-semibold uppercase tracking-wide mb-2">Average Score</p>
                            <p class="text-4xl font-extrabold bg-gradient-to-r from-purple-600 to-purple-800 dark:from-purple-400 dark:to-purple-600 bg-clip-text text-transparent"><?php echo $avgScore; ?>%</p>
                            <p class="text-xs text-gray-600 dark:text-gray-400 mt-2">overall performance</p>
                        </div>
                        <div class="bg-gradient-to-br from-purple-500 to-purple-700 p-4 rounded-2xl shadow-lg">
                            <i class="fas fa-trophy text-white text-2xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Available Quizzes -->
            <?php if (!empty($available)): ?>
            <div class="card-hover bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-lg fade-in mb-8">
                <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                    <div class="flex items-center justify-between">
                        <h2 class="text-2xl font-bold text-gray-800 dark:text-white flex items-center">
                            <i class="fas fa-play-circle text-blue-600 dark:text-blue-400 mr-3"></i>
                            Available Quizzes
                        </h2>
                        <span class="px-3 py-1 bg-blue-100 dark:bg-blue-900/20 text-blue-800 dark:text-blue-400 rounded-full text-sm font-semibold">
                            <?php echo count($available); ?> quizzes
                        </span>
                    </div>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <?php foreach ($available as $quiz): ?>
                            <div class="bg-gradient-to-br from-blue-50 to-blue-100 dark:from-blue-900/20 dark:to-blue-800/20 rounded-xl p-6 border border-blue-200 dark:border-blue-800 hover:shadow-xl transition-all transform hover:scale-105">
                                <div class="flex items-start justify-between mb-4">
                                    <div class="bg-gradient-to-br from-blue-500 to-blue-700 p-3 rounded-xl">
                                        <i class="fas fa-question-circle text-white text-xl"></i>
                                    </div>
                                    <?php if ($quiz['max_attempts'] > 1): ?>
                                        <span class="px-2 py-1 bg-blue-200 dark:bg-blue-800 text-blue-800 dark:text-blue-200 rounded-lg text-xs font-semibold">
                                            <?php echo $quiz['max_attempts']; ?> attempts
                                        </span>
                                    <?php endif; ?>
                                </div>
                                
                                <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-2">
                                    <?php echo htmlspecialchars($quiz['title']); ?>
                                </h3>
                                <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                                    <i class="fas fa-book mr-2 text-blue-600 dark:text-blue-400"></i>
                                    <?php echo htmlspecialchars($quiz['course_name'] ?? 'Unknown Course'); ?>
                                </p>
                                
                                <div class="space-y-2 mb-4">
                                    <?php if ($quiz['duration']): ?>
                                    <div class="flex items-center text-sm text-gray-600 dark:text-gray-400">
                                        <i class="fas fa-clock mr-2 text-blue-600 dark:text-blue-400"></i>
                                        <span><?php echo $quiz['duration']; ?> minutes</span>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($quiz['passing_score']): ?>
                                    <div class="flex items-center text-sm text-gray-600 dark:text-gray-400">
                                        <i class="fas fa-trophy mr-2 text-yellow-600 dark:text-yellow-400"></i>
                                        <span>Pass: <?php echo $quiz['passing_score']; ?>%</span>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php
                                    try {
                                        $qStmt = $pdo->prepare("SELECT COUNT(*) as count FROM questions WHERE quiz_id = ?");
                                        $qStmt->execute([$quiz['id']]);
                                        $qCount = $qStmt->fetch(PDO::FETCH_ASSOC);
                                    } catch (PDOException $e) {
                                        $qCount = ['count' => 0];
                                    }
                                    ?>
                                    <div class="flex items-center text-sm text-gray-600 dark:text-gray-400">
                                        <i class="fas fa-list-ol mr-2 text-purple-600 dark:text-purple-400"></i>
                                        <span><?php echo $qCount['count']; ?> questions</span>
                                    </div>
                                </div>
                                
                                <a href="quiz.php?id=<?php echo $quiz['id']; ?>" 
                                   class="block w-full bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white text-center py-3 rounded-xl font-bold shadow-lg hover:shadow-xl transition-all transform hover:scale-105">
                                    <i class="fas fa-play mr-2"></i>Start Quiz
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- In Progress Quizzes -->
            <?php if (!empty($inProgress)): ?>
            <div class="card-hover bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-lg fade-in mb-8">
                <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                    <div class="flex items-center justify-between">
                        <h2 class="text-2xl font-bold text-gray-800 dark:text-white flex items-center">
                            <i class="fas fa-clock text-yellow-600 dark:text-yellow-400 mr-3 animate-pulse"></i>
                            In Progress
                        </h2>
                        <span class="px-3 py-1 bg-yellow-100 dark:bg-yellow-900/20 text-yellow-800 dark:text-yellow-400 rounded-full text-sm font-semibold">
                            <?php echo count($inProgress); ?> quizzes
                        </span>
                    </div>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <?php foreach ($inProgress as $quiz): ?>
                            <div class="bg-gradient-to-br from-yellow-50 to-yellow-100 dark:from-yellow-900/20 dark:to-yellow-800/20 rounded-xl p-6 border border-yellow-200 dark:border-yellow-800 hover:shadow-xl transition-all transform hover:scale-105">
                                <div class="flex items-start justify-between mb-4">
                                    <div class="bg-gradient-to-br from-yellow-500 to-yellow-700 p-3 rounded-xl">
                                        <i class="fas fa-hourglass-half text-white text-xl"></i>
                                    </div>
                                    <span class="px-2 py-1 bg-yellow-200 dark:bg-yellow-800 text-yellow-800 dark:text-yellow-200 rounded-lg text-xs font-semibold animate-pulse">
                                        In Progress
                                    </span>
                                </div>
                                
                                <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-2">
                                    <?php echo htmlspecialchars($quiz['title']); ?>
                                </h3>
                                <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                                    <i class="fas fa-book mr-2 text-yellow-600 dark:text-yellow-400"></i>
                                    <?php echo htmlspecialchars($quiz['course_name'] ?? 'Unknown Course'); ?>
                                </p>
                                
                                <div class="mb-4 p-3 bg-yellow-100 dark:bg-yellow-900/30 rounded-lg">
                                    <p class="text-sm text-yellow-800 dark:text-yellow-200 flex items-center">
                                        <i class="fas fa-exclamation-circle mr-2"></i>
                                        Continue where you left off
                                    </p>
                                </div>
                                
                                <a href="quiz.php?id=<?php echo $quiz['id']; ?>" 
                                   class="block w-full bg-gradient-to-r from-yellow-600 to-yellow-700 hover:from-yellow-700 hover:to-yellow-800 text-white text-center py-3 rounded-xl font-bold shadow-lg hover:shadow-xl transition-all transform hover:scale-105">
                                    <i class="fas fa-arrow-right mr-2"></i>Continue Quiz
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Completed Quizzes -->
            <?php if (!empty($completed)): ?>
            <div class="card-hover bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-lg fade-in">
                <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                    <div class="flex items-center justify-between">
                        <h2 class="text-2xl font-bold text-gray-800 dark:text-white flex items-center">
                            <i class="fas fa-check-circle text-green-600 dark:text-green-400 mr-3"></i>
                            Completed Quizzes
                        </h2>
                        <span class="px-3 py-1 bg-green-100 dark:bg-green-900/20 text-green-800 dark:text-green-400 rounded-full text-sm font-semibold">
                            <?php echo count($completed); ?> quizzes
                        </span>
                    </div>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <?php foreach ($completed as $quiz): ?>
                            <?php
                                $percentage = floatval($quiz['percentage'] ?? 0);
                                $passingScore = floatval($quiz['passing_score'] ?? 60);
                                $passed = $percentage >= $passingScore;
                                $circumference = 2 * M_PI * 40;
                                $offset = $circumference - ($percentage / 100) * $circumference;
                            ?>
                            <div class="bg-gradient-to-br from-green-50 to-emerald-50 dark:from-green-900/20 dark:to-emerald-800/20 rounded-xl p-6 border border-green-200 dark:border-green-800 hover:shadow-xl transition-all transform hover:scale-105">
                                <div class="flex items-start justify-between mb-4">
                                    <div class="bg-gradient-to-br <?php echo $passed ? 'from-green-500 to-emerald-600' : 'from-red-500 to-red-600'; ?> p-3 rounded-xl">
                                        <i class="fas <?php echo $passed ? 'fa-check-circle' : 'fa-times-circle'; ?> text-white text-xl"></i>
                                    </div>
                                    <div class="relative w-16 h-16">
                                        <svg class="progress-ring w-16 h-16">
                                            <circle class="text-gray-200 dark:text-gray-700" stroke="currentColor" stroke-width="6" fill="transparent" r="40" cx="50%" cy="50%"/>
                                            <circle class="progress-ring-circle <?php echo $passed ? 'text-green-500' : 'text-red-500'; ?>" 
                                                    stroke="currentColor" 
                                                    stroke-width="6" 
                                                    fill="transparent" 
                                                    r="40" 
                                                    cx="50%" 
                                                    cy="50%"
                                                    stroke-dasharray="<?php echo $circumference; ?>"
                                                    stroke-dashoffset="<?php echo $offset; ?>"/>
                                        </svg>
                                        <div class="absolute inset-0 flex items-center justify-center">
                                            <span class="text-sm font-bold <?php echo $passed ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'; ?>">
                                                <?php echo number_format($percentage, 0); ?>%
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                
                                <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-2">
                                    <?php echo htmlspecialchars($quiz['title']); ?>
                                </h3>
                                <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                                    <i class="fas fa-book mr-2 text-green-600 dark:text-green-400"></i>
                                    <?php echo htmlspecialchars($quiz['course_name'] ?? 'Unknown Course'); ?>
                                </p>
                                
                                <div class="space-y-2 mb-4">
                                    <div class="flex items-center justify-between p-3 bg-white dark:bg-gray-800 rounded-lg">
                                        <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Score</span>
                                        <span class="text-lg font-bold <?php echo $passed ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'; ?>">
                                            <?php echo number_format($percentage, 1); ?>%
                                        </span>
                                    </div>
                                    
                                    <?php if ($quiz['completed_at']): ?>
                                    <div class="flex items-center text-sm text-gray-600 dark:text-gray-400">
                                        <i class="fas fa-calendar-check mr-2 text-green-600 dark:text-green-400"></i>
                                        <span>Completed: <?php echo date('M j, Y', strtotime($quiz['completed_at'])); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="flex items-center">
                                        <?php if ($passed): ?>
                                            <span class="px-3 py-1 bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-400 rounded-full text-xs font-semibold">
                                                <i class="fas fa-trophy mr-1"></i>Passed
                                            </span>
                                        <?php else: ?>
                                            <span class="px-3 py-1 bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-400 rounded-full text-xs font-semibold">
                                                <i class="fas fa-times-circle mr-1"></i>Failed
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <a href="quiz.php?id=<?php echo $quiz['id']; ?>&view=results" 
                                   class="block w-full bg-gradient-to-r <?php echo $passed ? 'from-green-600 to-emerald-700 hover:from-green-700 hover:to-emerald-800' : 'from-gray-600 to-gray-700 hover:from-gray-700 hover:to-gray-800'; ?> text-white text-center py-3 rounded-xl font-bold shadow-lg hover:shadow-xl transition-all transform hover:scale-105">
                                    <i class="fas fa-chart-line mr-2"></i>View Results
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Empty State -->
            <?php if (empty($quizzes)): ?>
                <div class="card-hover bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-lg fade-in p-12 text-center">
                    <div class="inline-block bg-gray-100 dark:bg-gray-700 p-6 rounded-full mb-4">
                        <i class="fas fa-question-circle text-gray-400 dark:text-gray-500 text-5xl"></i>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-800 dark:text-white mb-2">No Quizzes Available</h3>
                    <p class="text-gray-600 dark:text-gray-400 mb-6">You don't have any quizzes available yet. Enroll in courses to access quizzes!</p>
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
