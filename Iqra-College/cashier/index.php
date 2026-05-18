<?php
/**
 * Cashier Dashboard
 * Payment verification and student management
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

requireRole('cashier');

$cashierId = getCurrentUserId();
$pdo = getDBConnection();
$name = getCurrentUserName();
$success = '';
$error = '';

// Handle payment verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_payment'])) {
    $paymentId = intval($_POST['payment_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($paymentId > 0 && in_array($action, ['verify', 'reject'])) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM payments WHERE id = ?");
            $stmt->execute([$paymentId]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($payment) {
                $courseTitle = '';
                try {
                    $st = $pdo->prepare("SELECT title FROM courses WHERE id = ?");
                    $st->execute([$payment['course_id']]);
                    $courseTitle = $st->fetchColumn() ?: 'the course';
                } catch (PDOException $e) { $courseTitle = 'the course'; }
                $courseId = (int)($payment['course_id'] ?? 0);
                $studentId = (int)($payment['student_id'] ?? 0);
                $linkCourse = $courseId > 0 ? ('courses.php?id=' . $courseId) : 'courses.php';

                if ($action === 'verify') {
                    $stmt = $pdo->prepare("UPDATE payments SET status = 'verified', verified_by = ?, verified_at = NOW() WHERE id = ?");
                    $stmt->execute([$cashierId, $paymentId]);

                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO payment_verifications (student_id, course_id, payment_id, verified_by, status)
                            VALUES (?, ?, ?, ?, 'active')
                            ON DUPLICATE KEY UPDATE payment_id = VALUES(payment_id), verified_by = VALUES(verified_by), verified_at = NOW(), status = 'active'
                        ");
                        $stmt->execute([$payment['student_id'], $payment['course_id'], $paymentId, $cashierId]);
                    } catch (PDOException $e) { /* table may not exist */ }

                    $stmt = $pdo->prepare("INSERT IGNORE INTO enrollments (student_id, course_id) VALUES (?, ?)");
                    $stmt->execute([$payment['student_id'], $payment['course_id']]);
                    try {
                        $pdo->prepare("UPDATE enrollments SET enrollment_status = 'approved' WHERE student_id = ? AND course_id = ?")->execute([$payment['student_id'], $payment['course_id']]);
                    } catch (PDOException $e) { /* column may not exist */ }

                    // Notify student: accepted — can start course
                    try {
                        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, link) VALUES (?, ?, ?, 'success', ?)");
                        $stmt->execute([
                            $studentId,
                            'Course accepted',
                            'Your payment for «' . $courseTitle . '» has been accepted. You can now start the course.',
                            $linkCourse
                        ]);
                    } catch (PDOException $e) { /* ignore */ }

                    $success = 'Payment approved successfully! Student has been enrolled and can access the course.';
                } else {
                    $stmt = $pdo->prepare("UPDATE payments SET status = 'rejected', verified_by = ?, verified_at = NOW() WHERE id = ?");
                    $stmt->execute([$cashierId, $paymentId]);

                    // Notify student: not accepted
                    try {
                        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, link) VALUES (?, ?, ?, 'error', ?)");
                        $stmt->execute([
                            $studentId,
                            'Payment not accepted',
                            'Your payment for «' . $courseTitle . '» was not accepted. Please contact the cashier or try again.',
                            $linkCourse
                        ]);
                    } catch (PDOException $e) { /* ignore */ }

                    $success = 'Payment rejected.';
                }
            } else {
                $error = 'Payment not found.';
            }
        } catch (PDOException $e) {
            $error = 'Failed to verify payment: ' . $e->getMessage();
        }
    }
}

// Get pending payments
try {
    $stmt = $pdo->prepare("
        SELECT p.*, u.name as student_name, u.student_id, u.email as student_email, u.phone as student_phone,
               c.title as course_name, c.price as course_price, c.id as course_id
        FROM payments p
        JOIN users u ON p.student_id = u.id
        JOIN courses c ON p.course_id = c.id
        WHERE p.status = 'pending'
        ORDER BY p.created_at DESC
    ");
    $stmt->execute();
    $pendingPayments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $pendingPayments = [];
}

// Get verified payments (recent)
try {
    $stmt = $pdo->prepare("
        SELECT p.*, u.name as student_name, u.student_id, u.email as student_email, c.title as course_name, cashier.name as verified_by_name
        FROM payments p
        JOIN users u ON p.student_id = u.id
        JOIN courses c ON p.course_id = c.id
        LEFT JOIN users cashier ON p.verified_by = cashier.id
        WHERE p.status = 'verified'
        ORDER BY p.verified_at DESC
        LIMIT 20
    ");
    $stmt->execute();
    $verifiedPayments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $verifiedPayments = [];
}

// Statistics
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE status = 'pending'");
    $stmt->execute();
    $pendingCount = (int) $stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE status = 'verified' AND DATE(verified_at) = CURDATE()");
    $stmt->execute();
    $todayVerified = (int) $stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'verified' AND DATE(verified_at) = CURDATE()");
    $stmt->execute();
    $todayRevenue = (float) $stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE status = 'verified' AND verified_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
    $stmt->execute();
    $weekVerified = (int) $stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'verified' AND verified_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
    $stmt->execute();
    $weekRevenue = (float) $stmt->fetchColumn();
} catch (PDOException $e) {
    $pendingCount = 0;
    $todayVerified = 0;
    $todayRevenue = 0.0;
    $weekVerified = 0;
    $weekRevenue = 0.0;
}

$currentPage = 'index.php';
$pageTitle = 'Dashboard';
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo isset($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === 'enabled' ? 'dark' : ''; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - Cashier - IQRA College</title>
    <link rel="icon" href="/Iqra-College/assets/images/iqra2.png" type="image/png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config={darkMode:'class',theme:{extend:{colors:{emerald:{400:'#34d399',500:'#10b981',600:'#059669',700:'#047857'}}}}};</script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .fade-in { animation: fadeIn 0.4s ease-out forwards; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .stat-card { transition: transform 0.2s ease, box-shadow 0.2s ease; }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 12px 24px rgba(0,0,0,0.08); }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 min-h-screen">
    <?php include __DIR__ . '/../includes/cashier_sidebar.php'; ?>
    <div class="lg:ml-64">
        <header class="bg-white dark:bg-gray-800 shadow border-b border-gray-200 dark:border-gray-700 sticky top-0 z-20">
            <div class="px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <button id="mobile-menu-toggle" class="lg:hidden p-2 rounded-lg text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700"><i class="fas fa-bars text-xl"></i></button>
                        <div>
                            <h1 class="text-2xl font-bold text-gray-800 dark:text-white"><?php echo $pageTitle; ?></h1>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Cashier dashboard</p>
                        </div>
                    </div>
                    <?php include __DIR__ . '/../includes/cashier_header.php'; ?>
                </div>
            </div>
        </header>

        <main class="p-4 sm:p-6 lg:p-8">
            <?php if ($success): ?>
                <div class="mb-6 p-4 bg-emerald-50 dark:bg-emerald-900/20 border-l-4 border-emerald-500 text-emerald-800 dark:text-emerald-300 rounded-xl flex items-center gap-3" role="alert">
                    <i class="fas fa-check-circle text-emerald-500"></i>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="mb-6 p-4 bg-red-50 dark:bg-red-900/20 border-l-4 border-red-500 text-red-700 dark:text-red-300 rounded-xl flex items-center gap-3" role="alert">
                    <i class="fas fa-exclamation-circle text-red-500"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <!-- Welcome banner -->
            <div class="mb-6 p-6 rounded-2xl bg-gradient-to-r from-emerald-500 via-teal-600 to-emerald-700 dark:from-emerald-600 dark:via-teal-700 dark:to-emerald-800 text-white shadow-xl fade-in">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <h2 class="text-2xl sm:text-3xl font-bold">Welcome, <?php echo htmlspecialchars(explode(' ', $name)[0]); ?>!</h2>
                        <p class="text-white/90 mt-1">
                            <?php
                            $hour = (int)date('G');
                            if ($hour < 12) echo 'Good morning';
                            elseif ($hour < 17) echo 'Good afternoon';
                            else echo 'Good evening';
                            ?> — verify payments and enroll students.
                        </p>
                    </div>
                    <div class="flex items-center gap-3 text-white/90">
                        <i class="far fa-calendar-alt text-xl"></i>
                        <span class="font-semibold"><?php echo date('l, F j, Y'); ?></span>
                    </div>
                </div>
            </div>

            <!-- Stats: Pending, Verified Today, Today's Revenue -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 sm:gap-6 mb-4">
                <a href="#pending-section" class="block focus:outline-none focus:ring-2 focus:ring-emerald-400 focus:ring-offset-2 dark:focus:ring-offset-gray-900 rounded-2xl">
                    <div class="stat-card bg-white dark:bg-gray-800 rounded-2xl shadow border border-amber-200 dark:border-amber-800/50 p-6 flex items-center justify-between fade-in hover:border-amber-400 dark:hover:border-amber-600 transition-all h-full">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Pending</p>
                            <p class="text-3xl font-bold text-amber-500 dark:text-amber-400 mt-1"><?php echo $pendingCount; ?></p>
                            <?php if ($pendingCount > 0): ?>
                                <p class="text-xs text-amber-600 dark:text-amber-400 mt-1.5">Verify now ↓</p>
                            <?php endif; ?>
                        </div>
                        <div class="w-14 h-14 rounded-2xl bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-clock text-amber-500 dark:text-amber-400 text-2xl"></i>
                        </div>
                    </div>
                </a>
                <div class="stat-card bg-white dark:bg-gray-800 rounded-2xl shadow border border-emerald-200 dark:border-emerald-800/50 p-6 flex items-center justify-between fade-in hover:border-emerald-400 dark:hover:border-emerald-600 transition-all">
                    <div>
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Verified Today</p>
                        <p class="text-3xl font-bold text-emerald-500 dark:text-emerald-400 mt-1"><?php echo $todayVerified; ?></p>
                    </div>
                    <div class="w-14 h-14 rounded-2xl bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center">
                        <i class="fas fa-check-circle text-emerald-500 dark:text-emerald-400 text-2xl"></i>
                    </div>
                </div>
                <div class="stat-card bg-white dark:bg-gray-800 rounded-2xl shadow border border-blue-200 dark:border-blue-800/50 p-6 flex items-center justify-between fade-in hover:border-blue-400 dark:hover:border-blue-600 transition-all">
                    <div>
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Today's Revenue</p>
                        <p class="text-3xl font-bold text-blue-500 dark:text-blue-400 mt-1">$<?php echo number_format($todayRevenue, 2); ?></p>
                    </div>
                    <div class="w-14 h-14 rounded-2xl bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center">
                        <i class="fas fa-dollar-sign text-blue-500 dark:text-blue-400 text-2xl"></i>
                    </div>
                </div>
            </div>

            <!-- This week -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-8">
                <div class="bg-white/80 dark:bg-gray-800/80 rounded-xl border border-gray-200 dark:border-gray-700 px-5 py-4 flex items-center justify-between">
                    <span class="text-sm text-gray-600 dark:text-gray-400">Verified (7 days)</span>
                    <span class="font-bold text-emerald-600 dark:text-emerald-400"><?php echo $weekVerified; ?></span>
                </div>
                <div class="bg-white/80 dark:bg-gray-800/80 rounded-xl border border-gray-200 dark:border-gray-700 px-5 py-4 flex items-center justify-between">
                    <span class="text-sm text-gray-600 dark:text-gray-400">Revenue (7 days)</span>
                    <span class="font-bold text-blue-600 dark:text-blue-400">$<?php echo number_format($weekRevenue, 2); ?></span>
                </div>
            </div>

            <!-- Pending Payments -->
            <div id="pending-section" class="bg-white dark:bg-gray-800 rounded-2xl shadow border border-gray-100 dark:border-gray-700 p-6 mb-8">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-bold text-gray-800 dark:text-white flex items-center gap-2">
                        <i class="fas fa-clock text-amber-500"></i>
                        Pending Verifications
                    </h2>
                    <?php if (count($pendingPayments) > 0): ?>
                        <span class="px-3 py-1.5 bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 rounded-full text-sm font-semibold">
                            <?php echo count($pendingPayments); ?> pending
                        </span>
                    <?php endif; ?>
                </div>

                <?php if (empty($pendingPayments)): ?>
                    <div class="text-center py-16">
                        <div class="w-20 h-20 rounded-full bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-check-double text-emerald-500 dark:text-emerald-400 text-3xl"></i>
                        </div>
                        <p class="text-gray-600 dark:text-gray-400 text-lg">No pending payments</p>
                        <p class="text-gray-500 dark:text-gray-500 text-sm mt-1">All requests have been processed</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-6">
                        <?php foreach ($pendingPayments as $p): ?>
                            <div class="rounded-xl border-2 border-amber-200 dark:border-amber-800 bg-amber-50/50 dark:bg-amber-900/10 p-5">
                                <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-5">
                                    <div class="flex-1 min-w-0">
                                        <div class="flex flex-wrap items-start justify-between gap-4 mb-4">
                                            <div>
                                                <h3 class="text-lg font-bold text-gray-800 dark:text-white"><?php echo htmlspecialchars($p['course_name']); ?></h3>
                                                <p class="text-sm text-gray-500 dark:text-gray-400">#<?php echo (int)$p['course_id']; ?></p>
                                            </div>
                                            <div class="text-right">
                                                <p class="text-xs text-gray-500 dark:text-gray-400">Amount</p>
                                                <p class="text-2xl font-bold text-amber-600 dark:text-amber-400">$<?php echo number_format((float)$p['amount'], 2); ?></p>
                                                <?php if (isset($p['course_price']) && $p['course_price'] && abs((float)$p['amount'] - (float)$p['course_price']) > 0.01): ?>
                                                    <p class="text-xs text-red-600 dark:text-red-400">Expected $<?php echo number_format((float)$p['course_price'], 2); ?></p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                                            <div class="p-3 rounded-lg bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700">
                                                <p class="text-xs text-gray-500 dark:text-gray-400">Student</p>
                                                <p class="font-semibold text-gray-800 dark:text-white"><?php echo htmlspecialchars($p['student_name']); ?></p>
                                                <p class="text-sm text-emerald-600 dark:text-emerald-400"><?php echo htmlspecialchars($p['student_id'] ?? '—'); ?></p>
                                            </div>
                                            <div class="p-3 rounded-lg bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700">
                                                <p class="text-xs text-gray-500 dark:text-gray-400">Contact</p>
                                                <p class="text-sm text-gray-700 dark:text-gray-300"><?php echo htmlspecialchars($p['student_email']); ?></p>
                                                <?php if (!empty($p['student_phone'])): ?>
                                                    <p class="text-sm text-gray-600 dark:text-gray-400"><?php echo htmlspecialchars($p['student_phone']); ?></p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="flex flex-wrap gap-4 text-sm text-gray-600 dark:text-gray-400">
                                            <span><i class="fas fa-wallet mr-1 text-amber-500"></i> <?php echo ucfirst(str_replace('_', ' ', $p['payment_method'] ?? 'cash')); ?></span>
                                            <?php if (!empty($p['payment_reference'])): ?>
                                                <span><i class="fas fa-hashtag mr-1"></i> <?php echo htmlspecialchars($p['payment_reference']); ?></span>
                                            <?php endif; ?>
                                            <span><i class="fas fa-calendar mr-1"></i> <?php echo date('M j, Y g:i A', strtotime($p['created_at'])); ?></span>
                                        </div>
                                        <?php if (!empty($p['notes'])): ?>
                                            <div class="mt-3 p-3 rounded-lg bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800">
                                                <p class="text-xs font-semibold text-blue-600 dark:text-blue-400 mb-1">Notes</p>
                                                <p class="text-sm text-blue-800 dark:text-blue-300"><?php echo htmlspecialchars($p['notes']); ?></p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex lg:flex-col gap-2 lg:min-w-[180px]">
                                        <form method="POST" class="flex-1 lg:flex-none">
                                            <input type="hidden" name="payment_id" value="<?php echo (int)$p['id']; ?>">
                                            <input type="hidden" name="action" value="verify">
                                            <button type="submit" name="verify_payment" class="w-full bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-600 hover:to-emerald-700 text-white px-4 py-3 rounded-xl font-semibold shadow-lg hover:shadow-emerald-500/25 transition-all">
                                                <i class="fas fa-check-circle mr-2"></i>Approve & Enroll
                                            </button>
                                        </form>
                                        <form method="POST" class="flex-1 lg:flex-none" onsubmit="return confirm('Reject this payment? The student will need to resubmit.');">
                                            <input type="hidden" name="payment_id" value="<?php echo (int)$p['id']; ?>">
                                            <input type="hidden" name="action" value="reject">
                                            <button type="submit" name="verify_payment" class="w-full bg-red-500 hover:bg-red-600 dark:bg-red-600 dark:hover:bg-red-700 text-white px-4 py-3 rounded-xl font-semibold transition-colors">
                                                <i class="fas fa-times-circle mr-2"></i>Reject
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Recent Verified -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow border border-gray-100 dark:border-gray-700 p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-xl font-bold text-gray-800 dark:text-white flex items-center gap-2">
                        <i class="fas fa-history text-emerald-500"></i>
                        Recently Verified
                    </h2>
                    <a href="payments.php" class="text-sm font-semibold text-emerald-600 dark:text-emerald-400 hover:underline">View all →</a>
                </div>
                <?php if (empty($verifiedPayments)): ?>
                    <div class="text-center py-12 rounded-xl bg-gray-50 dark:bg-gray-700/30 border border-dashed border-gray-200 dark:border-gray-600">
                        <i class="fas fa-receipt text-4xl text-gray-300 dark:text-gray-500 mb-3"></i>
                        <p class="text-gray-500 dark:text-gray-400">No verified payments yet</p>
                        <p class="text-sm text-gray-400 dark:text-gray-500 mt-1">Approved payments will appear here</p>
                        <a href="payments.php" class="inline-block mt-4 text-emerald-600 dark:text-emerald-400 font-semibold hover:underline">Payment history</a>
                    </div>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($verifiedPayments as $v): ?>
                            <div class="flex items-center justify-between p-4 rounded-xl bg-emerald-50/50 dark:bg-emerald-900/10 border border-emerald-200 dark:border-emerald-800">
                                <div>
                                    <p class="font-semibold text-gray-800 dark:text-white"><?php echo htmlspecialchars($v['course_name']); ?></p>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">
                                        <?php echo htmlspecialchars($v['student_name']); ?> (<?php echo htmlspecialchars($v['student_id'] ?? '—'); ?>) · $<?php echo number_format((float)$v['amount'], 2); ?>
                                    </p>
                                    <p class="text-xs text-gray-500 dark:text-gray-500 mt-1">
                                        <?php echo htmlspecialchars($v['verified_by_name'] ?? '—'); ?> · <?php echo date('M j, g:i A', strtotime($v['verified_at'])); ?>
                                    </p>
                                </div>
                                <span class="px-3 py-1.5 bg-emerald-200 dark:bg-emerald-800 text-emerald-800 dark:text-emerald-300 rounded-full text-sm font-semibold">Verified</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>
