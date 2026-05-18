<?php
/**
 * Cashier - Payment History
 * View pending, verified, and rejected payments
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

requireRole('cashier');

$pdo = getDBConnection();
$cashierId = getCurrentUserId();
$success = '';
$error = '';

function sendPaymentStatusEmail($to, $studentName, $courseTitle, $status, $courseId) {
    if (!validateEmail($to)) {
        return false;
    }
    $subjectStatus = $status === 'verified' ? 'Payment Accepted' : 'Payment Rejected';
    $subject = 'IQRA College - ' . $subjectStatus;
    $courseLink = 'http://localhost/Iqra-College/student/courses.php?id=' . $courseId;
    $statusText = $status === 'verified'
        ? "Your payment for \"$courseTitle\" has been accepted. You can now start the course."
        : "Your payment for \"$courseTitle\" was not accepted. Please contact the cashier or try again.";
    $message = "Hello $studentName,\n\n$statusText\n\nCourse link: $courseLink\n\nRegards,\nIQRA College";
    $headers = "From: support@iqracollege.com\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    return @mail($to, $subject, $message, $headers);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_payment'])) {
    $paymentId = intval($_POST['payment_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($paymentId > 0 && in_array($action, ['verify', 'reject'], true)) {
        try {
            $stmt = $pdo->prepare("
                SELECT p.*, u.name as student_name, u.email as student_email, c.title as course_title
                FROM payments p
                JOIN users u ON p.student_id = u.id
                JOIN courses c ON p.course_id = c.id
                WHERE p.id = ?
            ");
            $stmt->execute([$paymentId]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($payment) {
                $currentStatus = $payment['status'] ?? 'pending';
                if ($currentStatus === 'verified' || $currentStatus === 'rejected') {
                    $error = 'This payment has already been processed.';
                } else {
                    $courseId = (int)($payment['course_id'] ?? 0);
                    $studentId = (int)($payment['student_id'] ?? 0);
                    $courseTitle = $payment['course_title'] ?? 'the course';
                    $studentName = $payment['student_name'] ?? 'Student';
                    $studentEmail = $payment['student_email'] ?? '';
                    $linkCourse = '/Iqra-College/student/courses.php?id=' . $courseId;

                    if ($action === 'verify') {
                        $stmt = $pdo->prepare("UPDATE payments SET status = 'verified', verified_by = ?, verified_at = NOW() WHERE id = ?");
                        $stmt->execute([$cashierId, $paymentId]);

                        try {
                            $stmt = $pdo->prepare("
                                INSERT INTO payment_verifications (student_id, course_id, payment_id, verified_by, status)
                                VALUES (?, ?, ?, ?, 'active')
                                ON DUPLICATE KEY UPDATE payment_id = VALUES(payment_id), verified_by = VALUES(verified_by), verified_at = NOW(), status = 'active'
                            ");
                            $stmt->execute([$studentId, $courseId, $paymentId, $cashierId]);
                        } catch (PDOException $e) { /* table may not exist */ }

                        $stmt = $pdo->prepare("INSERT IGNORE INTO enrollments (student_id, course_id) VALUES (?, ?)");
                        $stmt->execute([$studentId, $courseId]);
                        try {
                            $pdo->prepare("UPDATE enrollments SET enrollment_status = 'approved' WHERE student_id = ? AND course_id = ?")->execute([$studentId, $courseId]);
                        } catch (PDOException $e) { /* column may not exist */ }

                        try {
                            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, link) VALUES (?, ?, ?, 'success', ?)");
                            $stmt->execute([
                                $studentId,
                                'Course accepted',
                                'Your payment for «' . $courseTitle . '» has been accepted. You can now start the course.',
                                $linkCourse
                            ]);
                        } catch (PDOException $e) { /* ignore */ }

                        sendPaymentStatusEmail($studentEmail, $studentName, $courseTitle, 'verified', $courseId);
                        $success = 'Payment approved successfully. Student enrolled and notified.';
                    } else {
                        $stmt = $pdo->prepare("UPDATE payments SET status = 'rejected', verified_by = ?, verified_at = NOW() WHERE id = ?");
                        $stmt->execute([$cashierId, $paymentId]);

                        try {
                            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, link) VALUES (?, ?, ?, 'error', ?)");
                            $stmt->execute([
                                $studentId,
                                'Payment not accepted',
                                'Your payment for «' . $courseTitle . '» was not accepted. Please contact the cashier or try again.',
                                $linkCourse
                            ]);
                        } catch (PDOException $e) { /* ignore */ }

                        sendPaymentStatusEmail($studentEmail, $studentName, $courseTitle, 'rejected', $courseId);
                        $success = 'Payment rejected.';
                    }
                }
            } else {
                $error = 'Payment not found.';
            }
        } catch (PDOException $e) {
            $error = 'Failed to process payment.';
        }
    }
}
$statusFilter = isset($_GET['status']) && in_array($_GET['status'], ['pending', 'verified', 'rejected', 'all'], true)
    ? $_GET['status'] : 'all';

$sql = "
    SELECT p.*, u.name as student_name, u.student_id, u.email as student_email,
           c.title as course_name, cashier.name as verified_by_name
    FROM payments p
    JOIN users u ON p.student_id = u.id
    JOIN courses c ON p.course_id = c.id
    LEFT JOIN users cashier ON p.verified_by = cashier.id
    WHERE 1=1
";
if ($statusFilter === 'pending') {
    $sql .= " AND (p.status = 'pending' OR p.status IS NULL)";
} elseif ($statusFilter === 'verified') {
    $sql .= " AND p.status = 'verified'";
} elseif ($statusFilter === 'rejected') {
    $sql .= " AND p.status = 'rejected'";
} else {
    $sql .= " AND p.status IN ('pending', 'verified', 'rejected')";
}
$sql .= " ORDER BY CASE WHEN p.status = 'pending' OR p.status IS NULL THEN 0 ELSE 1 END, COALESCE(p.verified_at, p.created_at) DESC LIMIT 150";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $payments = [];
}

// Counts for filter chips
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM payments WHERE status = 'pending' OR status IS NULL");
    $countPending = (int) $stmt->fetchColumn();
    $stmt = $pdo->query("SELECT COUNT(*) FROM payments WHERE status = 'verified'");
    $countVerified = (int) $stmt->fetchColumn();
    $stmt = $pdo->query("SELECT COUNT(*) FROM payments WHERE status = 'rejected'");
    $countRejected = (int) $stmt->fetchColumn();
} catch (PDOException $e) {
    $countPending = 0;
    $countVerified = 0;
    $countRejected = 0;
}

// Today's summary
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM payments WHERE status = 'verified' AND DATE(verified_at) = CURDATE()");
    $todayVerified = (int) $stmt->fetchColumn();
    $stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'verified' AND DATE(verified_at) = CURDATE()");
    $todayRevenue = (float) $stmt->fetchColumn();
} catch (PDOException $e) {
    $todayVerified = 0;
    $todayRevenue = 0.0;
}

$currentPage = 'payments.php';
$pageTitle = 'Payment History';
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo isset($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === 'enabled' ? 'dark' : ''; ?>">
<head>
    <link rel="icon" href="/Iqra-College/assets/images/iqra2.png" type="image/png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - Cashier - IQRA College</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config={darkMode:'class',theme:{extend:{colors:{emerald:{500:'#10b981',600:'#059669'}}}}};</script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>.fade-in { animation: fadeIn 0.4s ease-out forwards; } @keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }</style>
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
                            <p class="text-sm text-gray-500 dark:text-gray-400">All payment statuses</p>
                        </div>
                    </div>
                    <?php include __DIR__ . '/../includes/cashier_header.php'; ?>
                </div>
            </div>
        </header>

        <main class="p-4 sm:p-6 lg:p-8">
            <?php if ($success): ?>
                <div class="mb-6 p-4 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 text-emerald-700 dark:text-emerald-300">
                    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="mb-6 p-4 rounded-xl bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300">
                    <i class="fas fa-times-circle mr-2"></i><?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            <!-- Today's summary -->
            <div class="mb-6 p-4 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 flex flex-wrap items-center gap-6">
                <div class="flex items-center gap-2">
                    <i class="fas fa-check-circle text-emerald-500"></i>
                    <span class="text-gray-700 dark:text-gray-300"><strong>Verified Today:</strong> <?php echo $todayVerified; ?></span>
                </div>
                <div class="flex items-center gap-2">
                    <i class="fas fa-dollar-sign text-blue-500"></i>
                    <span class="text-gray-700 dark:text-gray-300"><strong>Today's Revenue:</strong> $<?php echo number_format($todayRevenue, 2); ?></span>
                </div>
            </div>

            <!-- Filters -->
            <div class="flex flex-wrap gap-2 mb-6">
                <a href="payments.php?status=all" class="px-4 py-2 rounded-xl font-semibold transition-all <?php echo $statusFilter === 'all' ? 'bg-emerald-500 text-white shadow' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border border-gray-200 dark:border-gray-700 hover:border-emerald-400'; ?>">
                    All
                </a>
                <a href="payments.php?status=pending" class="px-4 py-2 rounded-xl font-semibold transition-all <?php echo $statusFilter === 'pending' ? 'bg-amber-500 text-white shadow' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border border-gray-200 dark:border-gray-700 hover:border-amber-400'; ?>">
                    <i class="fas fa-clock mr-1.5 text-amber-500"></i>Pending (<?php echo $countPending; ?>)
                </a>
                <a href="payments.php?status=verified" class="px-4 py-2 rounded-xl font-semibold transition-all <?php echo $statusFilter === 'verified' ? 'bg-emerald-500 text-white shadow' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border border-gray-200 dark:border-gray-700 hover:border-emerald-400'; ?>">
                    <i class="fas fa-check-circle mr-1.5 text-emerald-500"></i>Verified (<?php echo $countVerified; ?>)
                </a>
                <a href="payments.php?status=rejected" class="px-4 py-2 rounded-xl font-semibold transition-all <?php echo $statusFilter === 'rejected' ? 'bg-red-500 text-white shadow' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border border-gray-200 dark:border-gray-700 hover:border-red-400'; ?>">
                    <i class="fas fa-times-circle mr-1.5 text-red-500"></i>Rejected (<?php echo $countRejected; ?>)
                </a>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow border border-gray-100 dark:border-gray-700 overflow-hidden">
                <?php if (empty($payments)): ?>
                    <div class="text-center py-16">
                        <i class="fas fa-receipt text-5xl text-gray-300 dark:text-gray-600 mb-4"></i>
                        <p class="text-gray-600 dark:text-gray-400">No payments in this list</p>
                        <a href="index.php" class="inline-block mt-4 text-emerald-600 dark:text-emerald-400 font-semibold hover:underline">Go to Dashboard</a>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="bg-gray-50 dark:bg-gray-700/50 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                    <th class="px-4 py-3">Date</th>
                                    <th class="px-4 py-3">Course</th>
                                    <th class="px-4 py-3">Student</th>
                                    <th class="px-4 py-3">Amount</th>
                                    <th class="px-4 py-3">Status</th>
                                    <th class="px-4 py-3">Verified by</th>
                                    <th class="px-4 py-3">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                <?php foreach ($payments as $p): 
                                    $date = !empty($p['verified_at']) ? $p['verified_at'] : $p['created_at'];
                                    $st = $p['status'] ?? '';
                                    $isPending = $st === 'pending' || $st === '';
                                    $isVerified = $st === 'verified';
                                ?>
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors">
                                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">
                                            <?php echo date('M j, Y g:i A', strtotime($date)); ?>
                                        </td>
                                        <td class="px-4 py-3">
                                            <p class="font-semibold text-gray-800 dark:text-white"><?php echo htmlspecialchars($p['course_name']); ?></p>
                                        </td>
                                        <td class="px-4 py-3">
                                            <p class="font-medium text-gray-800 dark:text-white"><?php echo htmlspecialchars($p['student_name']); ?></p>
                                            <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($p['student_id'] ?? '—'); ?></p>
                                        </td>
                                        <td class="px-4 py-3 font-semibold text-gray-800 dark:text-white">$<?php echo number_format((float)$p['amount'], 2); ?></td>
                                        <td class="px-4 py-3">
                                            <?php if ($isVerified): ?>
                                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400">
                                                    <i class="fas fa-check mr-1"></i>Verified
                                                </span>
                                            <?php elseif ($isPending): ?>
                                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400">
                                                    <i class="fas fa-clock mr-1"></i>Pending
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400">
                                                    <i class="fas fa-times mr-1"></i>Rejected
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400"><?php echo htmlspecialchars($p['verified_by_name'] ?? '—'); ?></td>
                                        <td class="px-4 py-3">
                                            <?php if ($isPending): ?>
                                                <form method="POST" class="flex flex-wrap gap-2">
                                                    <input type="hidden" name="payment_id" value="<?php echo (int)$p['id']; ?>">
                                                    <input type="hidden" name="verify_payment" value="1">
                                                    <button type="submit" name="action" value="verify" class="px-3 py-1.5 rounded-lg bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-semibold">
                                                        Verify
                                                    </button>
                                                    <button type="submit" name="action" value="reject" class="px-3 py-1.5 rounded-lg bg-red-500 hover:bg-red-600 text-white text-xs font-semibold">
                                                        Reject
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-gray-400">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>
