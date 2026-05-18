<?php
/**
 * Student - Payments (Modern Design)
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

requireRole('student');

$studentId = getCurrentUserId();
$studentCode = getUserCode($studentId);
$pdo = getDBConnection();
$name = getCurrentUserName();
$success = '';
$error = '';

// Get all payments for this student
try {
    $stmt = $pdo->prepare("
        SELECT 
            p.*,
            c.title as course_title,
            c.price as course_price,
            u.name as verified_by_name
        FROM payments p
        JOIN courses c ON p.course_id = c.id
        LEFT JOIN users u ON p.verified_by = u.id
        WHERE p.student_id = ?
        ORDER BY p.created_at DESC
    ");
    $stmt->execute([$studentId]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $payments = [];
}

// Get payment statistics
$totalPaid = 0;
$pendingCount = 0;
$verifiedCount = 0;
$rejectedCount = 0;
$pendingAmount = 0;

foreach ($payments as $payment) {
    if ($payment['status'] === 'verified') {
        $totalPaid += floatval($payment['amount']);
        $verifiedCount++;
    } elseif ($payment['status'] === 'pending') {
        $pendingAmount += floatval($payment['amount']);
        $pendingCount++;
    } elseif ($payment['status'] === 'rejected') {
        $rejectedCount++;
    }
}

function getStatusBadge($status) {
    switch ($status) {
        case 'verified':
            return '<span class="px-3 py-1 bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-400 rounded-full text-xs font-semibold flex items-center">
                        <i class="fas fa-check-circle mr-1.5"></i>Verified
                    </span>';
        case 'pending':
            return '<span class="px-3 py-1 bg-yellow-100 text-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-400 rounded-full text-xs font-semibold flex items-center animate-pulse">
                        <i class="fas fa-clock mr-1.5"></i>Pending
                    </span>';
        case 'rejected':
            return '<span class="px-3 py-1 bg-red-100 text-red-800 dark:bg-red-900/20 dark:text-red-400 rounded-full text-xs font-semibold flex items-center">
                        <i class="fas fa-times-circle mr-1.5"></i>Rejected
                    </span>';
        default:
            return '<span class="px-3 py-1 bg-gray-100 text-gray-800 dark:bg-gray-900/20 dark:text-gray-400 rounded-full text-xs font-semibold">' . ucfirst($status) . '</span>';
    }
}

function getPaymentMethodIcon($method) {
    $methods = [
        'mobile_money' => 'fa-mobile-alt',
        'bank_transfer' => 'fa-university',
        'cash' => 'fa-money-bill',
        'card' => 'fa-credit-card',
    ];
    return $methods[$method] ?? 'fa-money-bill';
}

$pageTitle = 'Payments';
$pageSubtitle = 'View and track all your course payments';
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

            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="card-hover bg-gradient-to-br from-white to-green-50 dark:from-gray-800 dark:to-gray-900 rounded-2xl p-6 border border-gray-200 dark:border-gray-700 shadow-lg stagger-item relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-green-200 dark:bg-green-900/30 rounded-full -mr-16 -mt-16 opacity-20"></div>
                    <div class="relative flex justify-between items-center">
                        <div>
                            <p class="text-gray-600 dark:text-gray-400 text-sm font-semibold uppercase tracking-wide mb-2">Total Paid</p>
                            <p class="text-4xl font-extrabold bg-gradient-to-r from-green-600 to-emerald-600 dark:from-green-400 dark:to-emerald-400 bg-clip-text text-transparent">$<?php echo number_format($totalPaid, 2); ?></p>
                            <p class="text-xs text-gray-600 dark:text-gray-400 mt-2">
                                <i class="fas fa-check-circle text-green-500 mr-1"></i>
                                <?php echo $verifiedCount; ?> verified
                            </p>
                        </div>
                        <div class="bg-gradient-to-br from-green-500 to-emerald-600 p-4 rounded-2xl shadow-lg">
                            <i class="fas fa-check-circle text-white text-2xl"></i>
                        </div>
                    </div>
                </div>

                <div class="card-hover bg-gradient-to-br from-white to-blue-50 dark:from-gray-800 dark:to-gray-900 rounded-2xl p-6 border border-gray-200 dark:border-gray-700 shadow-lg stagger-item relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-blue-200 dark:bg-blue-900/30 rounded-full -mr-16 -mt-16 opacity-20"></div>
                    <div class="relative flex justify-between items-center">
                        <div>
                            <p class="text-gray-600 dark:text-gray-400 text-sm font-semibold uppercase tracking-wide mb-2">Verified</p>
                            <p class="text-4xl font-extrabold bg-gradient-to-r from-blue-600 to-blue-800 dark:from-blue-400 dark:to-blue-600 bg-clip-text text-transparent"><?php echo $verifiedCount; ?></p>
                            <p class="text-xs text-gray-600 dark:text-gray-400 mt-2">payments confirmed</p>
                        </div>
                        <div class="bg-gradient-to-br from-blue-500 to-blue-700 p-4 rounded-2xl shadow-lg">
                            <i class="fas fa-check text-white text-2xl"></i>
                        </div>
                    </div>
                </div>

                <div class="card-hover bg-gradient-to-br from-white to-yellow-50 dark:from-gray-800 dark:to-gray-900 rounded-2xl p-6 border border-gray-200 dark:border-gray-700 shadow-lg stagger-item relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-yellow-200 dark:bg-yellow-900/30 rounded-full -mr-16 -mt-16 opacity-20"></div>
                    <div class="relative flex justify-between items-center">
                        <div>
                            <p class="text-gray-600 dark:text-gray-400 text-sm font-semibold uppercase tracking-wide mb-2">Pending</p>
                            <p class="text-4xl font-extrabold bg-gradient-to-r from-yellow-600 to-yellow-800 dark:from-yellow-400 dark:to-yellow-600 bg-clip-text text-transparent"><?php echo $pendingCount; ?></p>
                            <p class="text-xs text-gray-600 dark:text-gray-400 mt-2">
                                $<?php echo number_format($pendingAmount, 2); ?> awaiting
                            </p>
                        </div>
                        <div class="bg-gradient-to-br from-yellow-500 to-yellow-700 p-4 rounded-2xl shadow-lg">
                            <i class="fas fa-clock text-white text-2xl"></i>
                        </div>
                    </div>
                </div>

                <div class="card-hover bg-gradient-to-br from-white to-red-50 dark:from-gray-800 dark:to-gray-900 rounded-2xl p-6 border border-gray-200 dark:border-gray-700 shadow-lg stagger-item relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-red-200 dark:bg-red-900/30 rounded-full -mr-16 -mt-16 opacity-20"></div>
                    <div class="relative flex justify-between items-center">
                        <div>
                            <p class="text-gray-600 dark:text-gray-400 text-sm font-semibold uppercase tracking-wide mb-2">Rejected</p>
                            <p class="text-4xl font-extrabold bg-gradient-to-r from-red-600 to-red-800 dark:from-red-400 dark:to-red-600 bg-clip-text text-transparent"><?php echo $rejectedCount; ?></p>
                            <p class="text-xs text-gray-600 dark:text-gray-400 mt-2">need attention</p>
                        </div>
                        <div class="bg-gradient-to-br from-red-500 to-red-700 p-4 rounded-2xl shadow-lg">
                            <i class="fas fa-times-circle text-white text-2xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Payments List -->
            <div class="card-hover bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-lg fade-in">
                <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                    <div class="flex items-center justify-between">
                        <h2 class="text-2xl font-bold text-gray-800 dark:text-white">All Payments</h2>
                        <div class="flex items-center space-x-2">
                            <select class="border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 rounded-xl px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
                                <option value="all">All Status</option>
                                <option value="verified">Verified</option>
                                <option value="pending">Pending</option>
                                <option value="rejected">Rejected</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="p-6">
                    <?php if (empty($payments)): ?>
                        <div class="text-center py-12">
                            <div class="inline-block bg-gray-100 dark:bg-gray-700 p-6 rounded-full mb-4">
                                <i class="fas fa-credit-card text-gray-400 dark:text-gray-500 text-5xl"></i>
                            </div>
                            <h3 class="text-2xl font-bold text-gray-800 dark:text-white mb-2">No Payments Yet</h3>
                            <p class="text-gray-600 dark:text-gray-400 mb-6">You haven't made any payments yet. Enroll in a course to get started!</p>
                            <a href="courses.php" class="inline-flex items-center space-x-2 bg-gradient-to-r from-primary-600 to-primary-700 hover:from-primary-700 hover:to-primary-800 text-white px-6 py-3 rounded-xl font-bold shadow-lg hover:shadow-xl transition-all transform hover:scale-105">
                                <i class="fas fa-book"></i>
                                <span>Browse Courses</span>
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($payments as $payment): ?>
                                <div class="border border-gray-200 dark:border-gray-700 rounded-xl p-6 hover:shadow-lg transition-all bg-gradient-to-r from-white to-gray-50 dark:from-gray-800 dark:to-gray-900">
                                    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                                        <div class="flex-1">
                                            <div class="flex items-center space-x-3 mb-3">
                                                <div class="bg-gradient-to-br from-primary-500 to-primary-700 p-3 rounded-xl">
                                                    <i class="fas fa-book text-white text-xl"></i>
                                                </div>
                                                <div class="flex-1">
                                                    <h3 class="text-lg font-bold text-gray-800 dark:text-white">
                                                        <?php echo htmlspecialchars($payment['course_title']); ?>
                                                    </h3>
                                                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                                                        Payment ID: #<?php echo $payment['id']; ?>
                                                    </p>
                                                </div>
                                                <?php echo getStatusBadge($payment['status']); ?>
                                            </div>
                                            
                                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mt-4">
                                                <div class="p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                                                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-1 uppercase tracking-wide">Amount</p>
                                                    <p class="text-xl font-extrabold bg-gradient-to-r from-primary-600 to-primary-800 dark:from-primary-400 dark:to-primary-600 bg-clip-text text-transparent">
                                                        $<?php echo number_format($payment['amount'], 2); ?>
                                                    </p>
                                                </div>
                                                
                                                <div class="p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                                                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-1 uppercase tracking-wide">Payment Method</p>
                                                    <p class="font-semibold text-gray-800 dark:text-white flex items-center">
                                                        <i class="fas <?php echo getPaymentMethodIcon($payment['payment_method']); ?> mr-2 text-primary-600 dark:text-primary-400"></i>
                                                        <?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?>
                                                    </p>
                                                </div>
                                                
                                                <?php if ($payment['payment_reference']): ?>
                                                <div class="p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                                                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-1 uppercase tracking-wide">Reference</p>
                                                    <p class="font-semibold text-gray-800 dark:text-white font-mono text-sm">
                                                        <?php echo htmlspecialchars($payment['payment_reference']); ?>
                                                    </p>
                                                </div>
                                                <?php endif; ?>
                                                
                                                <div class="p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                                                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-1 uppercase tracking-wide">Date</p>
                                                    <p class="font-semibold text-gray-800 dark:text-white">
                                                        <?php echo date('M d, Y', strtotime($payment['created_at'])); ?>
                                                    </p>
                                                </div>
                                            </div>
                                            
                                            <?php if ($payment['notes']): ?>
                                            <div class="mt-4 p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-200 dark:border-blue-800">
                                                <p class="text-xs text-gray-500 dark:text-gray-400 mb-1 uppercase tracking-wide">Notes</p>
                                                <p class="text-sm text-gray-700 dark:text-gray-300"><?php echo htmlspecialchars($payment['notes']); ?></p>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($payment['status'] === 'verified' && $payment['verified_by_name']): ?>
                                            <div class="mt-4 flex items-center text-sm text-green-600 dark:text-green-400 bg-green-50 dark:bg-green-900/20 p-3 rounded-lg">
                                                <i class="fas fa-check-circle mr-2"></i>
                                                <span class="font-semibold">Verified by <?php echo htmlspecialchars($payment['verified_by_name']); ?></span>
                                                <?php if ($payment['verified_at']): ?>
                                                    <span class="ml-2 text-gray-600 dark:text-gray-400">
                                                        on <?php echo date('M d, Y g:i A', strtotime($payment['verified_at'])); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
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
            
        });
    </script>
    
    <?php include __DIR__ . '/../includes/student_ai_button.php'; ?>
</body>
</html>
