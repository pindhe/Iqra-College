<?php
/**
 * Student - Results & Analytics (Modern Design)
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

requireRole('student');

$studentId = getCurrentUserId();
$pdo = getDBConnection();

// Get all quiz attempts
$results = getStudentQuizAttempts($studentId);

// Calculate statistics
$totalQuizzes = count($results);
$totalScore = 0;
$highestScore = 0;
$lowestScore = 100;
$passedQuizzes = 0;
$failedQuizzes = 0;

$quizStats = [];
$courseStats = [];

foreach ($results as $result) {
    $score = $result['score'] ?? 0;
    $totalQuestions = $result['total_questions'] ?? 0;
    $percentage = $result['percentage'] ?? 0;
    
    // If percentage is missing but we have score and total_questions, calculate it
    if ($percentage == 0 && $totalQuestions > 0) {
        $percentage = ($score / $totalQuestions) * 100;
    }
    
    $totalScore += $percentage;
    
    if ($percentage > $highestScore) {
        $highestScore = $percentage;
    }
    
    if ($percentage < $lowestScore) {
        $lowestScore = $percentage;
    }
    
    if ($percentage >= 70) {
        $passedQuizzes++;
    } else {
        $failedQuizzes++;
    }
    
    // Group by quiz for stats
    $quizId = $result['quiz_id'] ?? 0;
    if ($quizId) {
        if (!isset($quizStats[$quizId])) {
            $quizStats[$quizId] = [
                'title' => $result['quiz_title'],
                'attempts' => 0,
                'scores' => []
            ];
        }
        $quizStats[$quizId]['attempts']++;
        $quizStats[$quizId]['scores'][] = $percentage;
    }
    
    // Group by course for stats
    $courseId = $result['course_id'] ?? 0;
    if ($courseId) {
        if (!isset($courseStats[$courseId])) {
            $courseStats[$courseId] = [
                'title' => $result['course_title'],
                'quizzes' => 0,
                'scores' => []
            ];
        }
        $courseStats[$courseId]['quizzes']++;
        $courseStats[$courseId]['scores'][] = $percentage;
    }
}

// Calculate averages
$averageScore = $totalQuizzes > 0 ? $totalScore / $totalQuizzes : 0;
$successRate = $totalQuizzes > 0 ? ($passedQuizzes / $totalQuizzes) * 100 : 0;

// Get recent activities
try {
    $stmt = $pdo->prepare("
        SELECT 
            'quiz_completed' as type,
            q.title as quiz_title,
            qa.completed_at as date,
            qa.percentage as score,
            c.title as course_name
        FROM quiz_attempts qa
        JOIN quizzes q ON qa.quiz_id = q.id
        JOIN courses c ON q.course_id = c.id
        WHERE qa.student_id = ? AND qa.completed_at IS NOT NULL
        ORDER BY qa.completed_at DESC
        LIMIT 10
    ");
    $stmt->execute([$studentId]);
    $recentActivities = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recentActivities = [];
}

// Get performance trend (last 5 quizzes)
try {
    $stmt = $pdo->prepare("
        SELECT percentage, completed_at
        FROM quiz_attempts
        WHERE student_id = ? AND completed_at IS NOT NULL AND percentage IS NOT NULL
        ORDER BY completed_at DESC
        LIMIT 5
    ");
    $stmt->execute([$studentId]);
    $trendData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $trendData = array_reverse($trendData); // Oldest to newest
} catch (PDOException $e) {
    $trendData = [];
}

$pageTitle = 'Results & Analytics';
$pageSubtitle = 'Track your learning performance and progress';
$currentPage = 'results';
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo isset($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === 'enabled' ? 'dark' : ''; ?>">
<head>
    <link rel="icon" href="/Iqra-College/assets/images/iqra2.png" type="image/png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - IQRA Online College</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        <?php include __DIR__ . '/../includes/student_sidebar.php'; ?>

        <main class="ml-0 lg:ml-64 flex-1 p-4 lg:p-8 transition-all duration-300">
            <?php include __DIR__ . '/../includes/student_header.php'; ?>

            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="card-hover bg-gradient-to-br from-white to-blue-50 dark:from-gray-800 dark:to-gray-900 rounded-2xl p-6 border border-gray-200 dark:border-gray-700 shadow-lg stagger-item relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-blue-200 dark:bg-blue-900/30 rounded-full -mr-16 -mt-16 opacity-20"></div>
                    <div class="relative flex justify-between items-center">
                        <div>
                            <p class="text-gray-600 dark:text-gray-400 text-sm font-semibold uppercase tracking-wide mb-2">Average Score</p>
                            <p class="text-4xl font-extrabold bg-gradient-to-r from-blue-600 to-blue-800 dark:from-blue-400 dark:to-blue-600 bg-clip-text text-transparent"><?php echo number_format($averageScore, 1); ?>%</p>
                            <p class="text-xs text-gray-600 dark:text-gray-400 mt-2">
                                <?php if ($averageScore >= 70): ?>
                                    <i class="fas fa-arrow-up text-green-500 mr-1"></i>Good performance
                                <?php elseif ($averageScore >= 50): ?>
                                    <i class="fas fa-minus text-yellow-500 mr-1"></i>Average performance
                                <?php else: ?>
                                    <i class="fas fa-arrow-down text-red-500 mr-1"></i>Needs improvement
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="bg-gradient-to-br from-blue-500 to-blue-700 p-4 rounded-2xl shadow-lg">
                            <i class="fas fa-chart-line text-white text-2xl"></i>
                        </div>
                    </div>
                </div>

                <div class="card-hover bg-gradient-to-br from-white to-purple-50 dark:from-gray-800 dark:to-gray-900 rounded-2xl p-6 border border-gray-200 dark:border-gray-700 shadow-lg stagger-item relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-purple-200 dark:bg-purple-900/30 rounded-full -mr-16 -mt-16 opacity-20"></div>
                    <div class="relative flex justify-between items-center">
                        <div>
                            <p class="text-gray-600 dark:text-gray-400 text-sm font-semibold uppercase tracking-wide mb-2">Total Quizzes</p>
                            <p class="text-4xl font-extrabold bg-gradient-to-r from-purple-600 to-purple-800 dark:from-purple-400 dark:to-purple-600 bg-clip-text text-transparent"><?php echo $totalQuizzes; ?></p>
                            <p class="text-xs text-gray-600 dark:text-gray-400 mt-2">attempts completed</p>
                        </div>
                        <div class="bg-gradient-to-br from-purple-500 to-purple-700 p-4 rounded-2xl shadow-lg">
                            <i class="fas fa-clipboard-check text-white text-2xl"></i>
                        </div>
                    </div>
                </div>

                <div class="card-hover bg-gradient-to-br from-white to-green-50 dark:from-gray-800 dark:to-gray-900 rounded-2xl p-6 border border-gray-200 dark:border-gray-700 shadow-lg stagger-item relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-green-200 dark:bg-green-900/30 rounded-full -mr-16 -mt-16 opacity-20"></div>
                    <div class="relative flex justify-between items-center">
                        <div>
                            <p class="text-gray-600 dark:text-gray-400 text-sm font-semibold uppercase tracking-wide mb-2">Success Rate</p>
                            <p class="text-4xl font-extrabold bg-gradient-to-r from-green-600 to-emerald-600 dark:from-green-400 dark:to-emerald-400 bg-clip-text text-transparent"><?php echo number_format($successRate, 1); ?>%</p>
                            <p class="text-xs text-gray-600 dark:text-gray-400 mt-2">
                                <?php echo $passedQuizzes; ?> passed / <?php echo $failedQuizzes; ?> failed
                            </p>
                        </div>
                        <div class="bg-gradient-to-br from-green-500 to-emerald-600 p-4 rounded-2xl shadow-lg">
                            <i class="fas fa-trophy text-white text-2xl"></i>
                        </div>
                    </div>
                </div>

                <div class="card-hover bg-gradient-to-br from-white to-orange-50 dark:from-gray-800 dark:to-gray-900 rounded-2xl p-6 border border-gray-200 dark:border-gray-700 shadow-lg stagger-item relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-orange-200 dark:bg-orange-900/30 rounded-full -mr-16 -mt-16 opacity-20"></div>
                    <div class="relative flex justify-between items-center">
                        <div>
                            <p class="text-gray-600 dark:text-gray-400 text-sm font-semibold uppercase tracking-wide mb-2">Score Range</p>
                            <p class="text-4xl font-extrabold bg-gradient-to-r from-orange-600 to-orange-800 dark:from-orange-400 dark:to-orange-600 bg-clip-text text-transparent"><?php echo number_format($highestScore, 1); ?>%</p>
                            <p class="text-xs text-gray-600 dark:text-gray-400 mt-2">
                                High: <?php echo number_format($highestScore, 1); ?>% | Low: <?php echo number_format($lowestScore, 1); ?>%
                            </p>
                        </div>
                        <div class="bg-gradient-to-br from-orange-500 to-orange-700 p-4 rounded-2xl shadow-lg">
                            <i class="fas fa-chart-area text-white text-2xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Content Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Left Column - Charts -->
                <div class="lg:col-span-2 space-y-8">
                    <!-- Performance Chart -->
                    <div class="card-hover bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-lg fade-in p-6">
                        <div class="flex justify-between items-center mb-6">
                            <h2 class="text-2xl font-bold text-gray-800 dark:text-white">Performance Trend</h2>
                            <div class="flex space-x-2">
                                <button class="text-sm px-4 py-2 rounded-xl bg-primary-100 dark:bg-primary-900/20 text-primary-600 dark:text-primary-400 font-semibold">Quizzes</button>
                                <button class="text-sm px-4 py-2 rounded-xl bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400 font-semibold">Courses</button>
                            </div>
                        </div>
                        
                        <div class="h-64">
                            <canvas id="performanceChart"></canvas>
                        </div>
                    </div>
                    
                    <!-- Quiz Results Table -->
                    <div class="card-hover bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-lg fade-in p-6">
                        <div class="flex justify-between items-center mb-6">
                            <h2 class="text-2xl font-bold text-gray-800 dark:text-white">Quiz Results</h2>
                            <span class="px-3 py-1 bg-primary-100 dark:bg-primary-900/20 text-primary-800 dark:text-primary-400 rounded-full text-sm font-semibold"><?php echo count($results); ?> results</span>
                        </div>
                        
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead>
                                    <tr class="border-b border-gray-200 dark:border-gray-700">
                                        <th class="text-left py-3 px-4 text-sm font-semibold text-gray-600 dark:text-gray-400">Quiz</th>
                                        <th class="text-left py-3 px-4 text-sm font-semibold text-gray-600 dark:text-gray-400">Course</th>
                                        <th class="text-left py-3 px-4 text-sm font-semibold text-gray-600 dark:text-gray-400">Score</th>
                                        <th class="text-left py-3 px-4 text-sm font-semibold text-gray-600 dark:text-gray-400">Status</th>
                                        <th class="text-left py-3 px-4 text-sm font-semibold text-gray-600 dark:text-gray-400">Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($results)): ?>
                                        <tr>
                                            <td colspan="5" class="py-12 text-center">
                                                <div class="inline-block bg-gray-100 dark:bg-gray-700 p-6 rounded-full mb-4">
                                                    <i class="fas fa-inbox text-gray-400 dark:text-gray-500 text-4xl"></i>
                                                </div>
                                                <p class="text-gray-600 dark:text-gray-400 font-semibold">No quiz results yet</p>
                                                <p class="text-sm text-gray-500 dark:text-gray-500 mt-2">Complete quizzes to see your results here</p>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($results as $result): ?>
                                            <?php 
                                                $score = $result['score'] ?? 0;
                                                $totalQuestions = $result['total_questions'] ?? 0;
                                                $percentage = $result['percentage'] ?? 0;
                                                
                                                if ($percentage == 0 && $totalQuestions > 0) {
                                                    $percentage = ($score / $totalQuestions) * 100;
                                                }
                                                
                                                $statusClass = $percentage >= 70 ? 'bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-400' : 
                                                             ($percentage >= 50 ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-400' : 
                                                             'bg-red-100 text-red-800 dark:bg-red-900/20 dark:text-red-400');
                                                $statusText = $percentage >= 70 ? 'Passed' : 
                                                            ($percentage >= 50 ? 'Average' : 'Failed');
                                            ?>
                                            <tr class="border-b border-gray-100 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                                                <td class="py-4 px-4">
                                                    <div class="font-semibold text-gray-800 dark:text-white"><?php echo htmlspecialchars($result['quiz_title'] ?? 'Unknown Quiz'); ?></div>
                                                </td>
                                                <td class="py-4 px-4">
                                                    <div class="text-sm text-gray-600 dark:text-gray-400"><?php echo htmlspecialchars($result['course_title'] ?? 'Unknown Course'); ?></div>
                                                </td>
                                                <td class="py-4 px-4">
                                                    <div class="flex items-center">
                                                        <div class="w-20 bg-gray-200 dark:bg-gray-700 rounded-full h-2 mr-3">
                                                            <div class="bg-gradient-to-r from-primary-500 to-primary-700 h-2 rounded-full transition-all" style="width: <?php echo $percentage; ?>%"></div>
                                                        </div>
                                                        <span class="font-bold <?php echo $percentage >= 70 ? 'text-green-600 dark:text-green-400' : ($percentage >= 50 ? 'text-yellow-600 dark:text-yellow-400' : 'text-red-600 dark:text-red-400'); ?>">
                                                            <?php echo number_format($percentage, 1); ?>%
                                                        </span>
                                                    </div>
                                                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                                        <?php echo $score; ?>/<?php echo $totalQuestions; ?> questions
                                                    </div>
                                                </td>
                                                <td class="py-4 px-4">
                                                    <span class="px-3 py-1 rounded-full text-xs font-semibold <?php echo $statusClass; ?>">
                                                        <?php echo $statusText; ?>
                                                    </span>
                                                </td>
                                                <td class="py-4 px-4">
                                                    <div class="text-sm text-gray-600 dark:text-gray-400">
                                                        <?php 
                                                            $completedAt = $result['completed_at'] ?? $result['started_at'] ?? null;
                                                            if ($completedAt) {
                                                                echo date('M d, Y', strtotime($completedAt));
                                                            } else {
                                                                echo 'N/A';
                                                            }
                                                        ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Right Column - Stats & Activities -->
                <div class="space-y-8">
                    <!-- Course Performance -->
                    <?php if (!empty($courseStats)): ?>
                        <div class="card-hover bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-lg fade-in p-6">
                            <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-6">Course Performance</h2>
                            
                            <div class="space-y-4">
                                <?php foreach ($courseStats as $courseId => $stats): ?>
                                    <?php 
                                        $avgScore = !empty($stats['scores']) ? array_sum($stats['scores']) / count($stats['scores']) : 0;
                                        $statusClass = $avgScore >= 70 ? 'text-green-600 dark:text-green-400' : 
                                                     ($avgScore >= 50 ? 'text-yellow-600 dark:text-yellow-400' : 'text-red-600 dark:text-red-400');
                                    ?>
                                    <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700/50 rounded-xl">
                                        <div class="flex items-center space-x-3">
                                            <div class="bg-gradient-to-br from-primary-500 to-primary-700 p-3 rounded-xl">
                                                <i class="fas fa-book text-white"></i>
                                            </div>
                                            <div>
                                                <p class="font-semibold text-gray-800 dark:text-white"><?php echo htmlspecialchars($stats['title']); ?></p>
                                                <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo count($stats['scores']); ?> quizzes</p>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <p class="font-bold text-lg <?php echo $statusClass; ?>"><?php echo number_format($avgScore, 1); ?>%</p>
                                            <div class="w-20 bg-gray-200 dark:bg-gray-700 rounded-full h-1.5 mt-1">
                                                <div class="bg-gradient-to-r from-primary-500 to-primary-700 h-1.5 rounded-full transition-all" style="width: <?php echo $avgScore; ?>%"></div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Recent Activities -->
                    <div class="card-hover bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-lg fade-in p-6">
                        <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-6">Recent Activities</h2>
                        
                        <div class="space-y-4">
                            <?php if (empty($recentActivities)): ?>
                                <div class="text-center py-8">
                                    <i class="fas fa-history text-gray-300 dark:text-gray-600 text-3xl mb-3"></i>
                                    <p class="text-gray-600 dark:text-gray-400 text-sm">No recent activities</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($recentActivities as $activity): ?>
                                    <div class="flex items-start space-x-3 p-3 bg-gray-50 dark:bg-gray-700/50 rounded-xl">
                                        <div class="bg-gradient-to-br from-primary-500 to-primary-700 p-2 rounded-lg">
                                            <i class="fas fa-clipboard-check text-white text-sm"></i>
                                        </div>
                                        <div class="flex-1">
                                            <p class="text-sm font-semibold text-gray-800 dark:text-white">
                                                Completed: <?php echo htmlspecialchars($activity['quiz_title']); ?>
                                            </p>
                                            <p class="text-xs text-gray-600 dark:text-gray-400 mt-1">
                                                <?php echo htmlspecialchars($activity['course_name']); ?> • 
                                                Score: <?php echo number_format($activity['score'], 1); ?>%
                                            </p>
                                            <p class="text-xs text-gray-500 dark:text-gray-500 mt-1">
                                                <?php echo date('M j, g:i A', strtotime($activity['date'])); ?>
                                            </p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Improvement Tips -->
                    <div class="card-hover bg-gradient-to-br from-primary-50 to-blue-50 dark:from-gray-800 dark:to-gray-900 border border-primary-200 dark:border-primary-800 rounded-2xl p-6 fade-in">
                        <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-4">Improvement Tips</h2>
                        
                        <div class="space-y-3">
                            <?php if ($averageScore < 70): ?>
                                <div class="flex items-start space-x-3 p-3 bg-white dark:bg-gray-800 rounded-xl">
                                    <i class="fas fa-lightbulb text-yellow-500 mt-1"></i>
                                    <div>
                                        <p class="text-sm font-semibold text-gray-800 dark:text-white">Review Incorrect Answers</p>
                                        <p class="text-xs text-gray-600 dark:text-gray-400">Go back and understand why you got questions wrong</p>
                                    </div>
                                </div>
                                
                                <div class="flex items-start space-x-3 p-3 bg-white dark:bg-gray-800 rounded-xl">
                                    <i class="fas fa-lightbulb text-yellow-500 mt-1"></i>
                                    <div>
                                        <p class="text-sm font-semibold text-gray-800 dark:text-white">Practice Regularly</p>
                                        <p class="text-xs text-gray-600 dark:text-gray-400">Consistent practice improves retention and understanding</p>
                                    </div>
                                </div>
                                
                                <div class="flex items-start space-x-3 p-3 bg-white dark:bg-gray-800 rounded-xl">
                                    <i class="fas fa-lightbulb text-yellow-500 mt-1"></i>
                                    <div>
                                        <p class="text-sm font-semibold text-gray-800 dark:text-white">Use Learning Materials</p>
                                        <p class="text-xs text-gray-600 dark:text-gray-400">Access course materials and notes for better preparation</p>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="flex items-start space-x-3 p-3 bg-white dark:bg-gray-800 rounded-xl">
                                    <i class="fas fa-star text-green-500 mt-1"></i>
                                    <div>
                                        <p class="text-sm font-semibold text-gray-800 dark:text-white">Great Job!</p>
                                        <p class="text-xs text-gray-600 dark:text-gray-400">You're performing well. Keep up the good work!</p>
                                    </div>
                                </div>
                                
                                <div class="flex items-start space-x-3 p-3 bg-white dark:bg-gray-800 rounded-xl">
                                    <i class="fas fa-star text-green-500 mt-1"></i>
                                    <div>
                                        <p class="text-sm font-semibold text-gray-800 dark:text-white">Challenge Yourself</p>
                                        <p class="text-xs text-gray-600 dark:text-gray-400">Try more advanced quizzes and courses</p>
                                    </div>
                                </div>
                                
                                <div class="flex items-start space-x-3 p-3 bg-white dark:bg-gray-800 rounded-xl">
                                    <i class="fas fa-star text-green-500 mt-1"></i>
                                    <div>
                                        <p class="text-sm font-semibold text-gray-800 dark:text-white">Help Others</p>
                                        <p class="text-xs text-gray-600 dark:text-gray-400">Consider helping fellow students with difficult concepts</p>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
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
            }
            
            // Initialize Performance Chart
            const ctx = document.getElementById('performanceChart');
            if (ctx) {
                const trendData = <?php echo json_encode($trendData); ?>;
                const labels = [];
                const scores = [];
                
                trendData.forEach((item, index) => {
                    labels.push(`Quiz ${index + 1}`);
                    scores.push(item.percentage || 0);
                });
                
                // If no data, show sample data
                if (scores.length === 0) {
                    labels.push('Quiz 1', 'Quiz 2', 'Quiz 3', 'Quiz 4', 'Quiz 5');
                    scores.push(65, 72, 68, 75, 80);
                }
                
                const isDark = document.documentElement.classList.contains('dark');
                const gridColor = isDark ? 'rgba(255, 255, 255, 0.05)' : 'rgba(0, 0, 0, 0.05)';
                const textColor = isDark ? '#e5e7eb' : '#6b7280';
                
                const chart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Score (%)',
                            data: scores,
                            borderColor: '#3b82f6',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            borderWidth: 3,
                            fill: true,
                            tension: 0.4,
                            pointBackgroundColor: '#3b82f6',
                            pointBorderColor: isDark ? '#1f2937' : '#ffffff',
                            pointBorderWidth: 2,
                            pointRadius: 6,
                            pointHoverRadius: 8
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                max: 100,
                                grid: {
                                    color: gridColor
                                },
                                ticks: {
                                    color: textColor,
                                    callback: function(value) {
                                        return value + '%';
                                    }
                                }
                            },
                            x: {
                                grid: {
                                    display: false
                                },
                                ticks: {
                                    color: textColor
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                backgroundColor: isDark ? '#1f2937' : '#ffffff',
                                titleColor: textColor,
                                bodyColor: textColor,
                                borderColor: isDark ? '#374151' : '#e5e7eb',
                                borderWidth: 1,
                                callbacks: {
                                    label: function(context) {
                                        return 'Score: ' + context.parsed.y + '%';
                                    }
                                }
                            }
                        }
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
