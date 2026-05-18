<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

requireRole('admin');

$pdo = getDBConnection();
$statusFilter = isset($_GET['status']) && in_array($_GET['status'], ['pending', 'verified', 'rejected', 'all'], true)
    ? $_GET['status'] : 'all';
$courseFilter = isset($_GET['course_id']) ? max(0, (int)$_GET['course_id']) : 0;

$sql = "
    SELECT p.*, u.name as student_name, u.student_id, u.email as student_email,
           c.title as course_name, c.id as course_id,
           verifier.name as verified_by_name
    FROM payments p
    JOIN users u ON p.student_id = u.id
    JOIN courses c ON p.course_id = c.id
    LEFT JOIN users verifier ON p.verified_by = verifier.id
    WHERE 1=1
";
$params = [];
if ($statusFilter === 'pending') {
    $sql .= " AND (p.status = 'pending' OR p.status IS NULL)";
} elseif ($statusFilter === 'verified') {
    $sql .= " AND p.status = 'verified'";
} elseif ($statusFilter === 'rejected') {
    $sql .= " AND p.status = 'rejected'";
}
if ($courseFilter > 0) {
    $sql .= " AND p.course_id = ?";
    $params[] = $courseFilter;
}
$sql .= " ORDER BY p.created_at DESC LIMIT 200";

try {
    $stmt = $params ? $pdo->prepare($sql) : $pdo->query($sql);
    if ($params) $stmt->execute($params);
    else $stmt->execute();
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
    $countPending = $countVerified = $countRejected = 0;
}

// Courses for filter dropdown (only if we use course filter)
$coursesForFilter = [];
try {
    $stmt = $pdo->query("SELECT id, title FROM courses ORDER BY title");
    $coursesForFilter = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

$currentPage = 'payments.php';
$pageTitle = 'Payments';
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo isset($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === 'enabled' ? 'dark' : ''; ?>">
<head>
    <link rel="icon" href="/Iqra-College/assets/images/iqra2.png" type="image/png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - Admin - IQRA College</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config={darkMode:'class',theme:{extend:{colors:{primary:{50:'#eff6ff',100:'#dbeafe',500:'#3b82f6',600:'#2563eb'}}}}});</script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>.fade-in{animation:fadeIn 0.4s ease-out forwards;} @keyframes fadeIn{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:translateY(0);}}</style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 min-h-screen">
    <?php include __DIR__ . '/../includes/admin_sidebar.php'; ?>
    <div class="lg:ml-64">
        <header class="bg-white dark:bg-gray-800 shadow border-b border-gray-200 dark:border-gray-700 sticky top-0 z-20">
            <div class="px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <button id="mobile-menu-toggle" class="lg:hidden p-2 rounded-lg text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700"><i class="fas fa-bars text-xl"></i></button>
                        <div>
                            <h1 class="text-2xl font-bold text-gray-800 dark:text-white"><?php echo $pageTitle; ?></h1>
                            <p class="text-sm text-gray-500 dark:text-gray-400">View all payments</p>
                        </div>
                    </div>
                    <?php include __DIR__ . '/../includes/admin_header.php'; ?>
                </div>
            </div>
        </header>

        <main class="p-4 sm:p-6 lg:p-8">
            <!-- Filters -->
            <div class="flex flex-wrap items-center gap-3 mb-6">
                <a href="payments.php?status=all<?php echo $courseFilter ? '&course_id='.$courseFilter : ''; ?>" class="px-4 py-2 rounded-xl font-semibold transition-all <?php echo $statusFilter === 'all' ? 'bg-indigo-500 text-white shadow' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border border-gray-200 dark:border-gray-700 hover:border-indigo-400'; ?>">
                    All
                </a>
                <a href="payments.php?status=pending<?php echo $courseFilter ? '&course_id='.$courseFilter : ''; ?>" class="px-4 py-2 rounded-xl font-semibold transition-all <?php echo $statusFilter === 'pending' ? 'bg-amber-500 text-white shadow' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border border-gray-200 dark:border-gray-700 hover:border-amber-400'; ?>">
                    <i class="fas fa-clock mr-1.5 text-amber-500"></i>Pending (<?php echo $countPending; ?>)
                </a>
                <a href="payments.php?status=verified<?php echo $courseFilter ? '&course_id='.$courseFilter : ''; ?>" class="px-4 py-2 rounded-xl font-semibold transition-all <?php echo $statusFilter === 'verified' ? 'bg-emerald-500 text-white shadow' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border border-gray-200 dark:border-gray-700 hover:border-emerald-400'; ?>">
                    <i class="fas fa-check-circle mr-1.5 text-emerald-500"></i>Verified (<?php echo $countVerified; ?>)
                </a>
                <a href="payments.php?status=rejected<?php echo $courseFilter ? '&course_id='.$courseFilter : ''; ?>" class="px-4 py-2 rounded-xl font-semibold transition-all <?php echo $statusFilter === 'rejected' ? 'bg-red-500 text-white shadow' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border border-gray-200 dark:border-gray-700 hover:border-red-400'; ?>">
                    <i class="fas fa-times-circle mr-1.5 text-red-500"></i>Rejected (<?php echo $countRejected; ?>)
                </a>
                <?php if (!empty($coursesForFilter)): ?>
                <form method="get" class="flex items-center gap-2 ml-auto">
                    <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
                    <select name="course_id" onchange="this.form.submit()" class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-800 dark:text-white px-3 py-2 text-sm">
                        <option value="0">All courses</option>
                        <?php foreach ($coursesForFilter as $co): ?>
                        <option value="<?php echo (int)$co['id']; ?>" <?php echo $courseFilter === (int)$co['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($co['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <?php endif; ?>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow border border-gray-100 dark:border-gray-700 overflow-hidden fade-in">
                <?php if (empty($payments)): ?>
                    <div class="text-center py-16">
                        <i class="fas fa-receipt text-5xl text-gray-300 dark:text-gray-600 mb-4"></i>
                        <p class="text-gray-600 dark:text-gray-400">No payments match the filters</p>
                        <a href="payments.php" class="inline-block mt-4 text-indigo-600 dark:text-indigo-400 font-semibold hover:underline">Clear filters</a>
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
                                    <th class="px-4 py-3">Method</th>
                                    <th class="px-4 py-3">Reference</th>
                                    <th class="px-4 py-3">Status</th>
                                    <th class="px-4 py-3">Verified by</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                <?php foreach ($payments as $p):
                                    $st = $p['status'] ?? '';
                                    $isPending = $st === 'pending' || $st === '' || $st === null;
                                    $isVerified = $st === 'verified';
                                    $isRejected = $st === 'rejected';
                                ?>
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors">
                                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">
                                            <?php echo date('M j, Y g:i A', strtotime($p['created_at'] ?? 'now')); ?>
                                        </td>
                                        <td class="px-4 py-3">
                                            <a href="courses.php" class="font-semibold text-indigo-600 dark:text-indigo-400 hover:underline"><?php echo htmlspecialchars($p['course_name'] ?? '—'); ?></a>
                                        </td>
                                        <td class="px-4 py-3">
                                            <p class="font-medium text-gray-800 dark:text-white"><?php echo htmlspecialchars($p['student_name'] ?? '—'); ?></p>
                                            <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($p['student_id'] ?? '—'); ?></p>
                                        </td>
                                        <td class="px-4 py-3 font-semibold text-gray-800 dark:text-white">$<?php echo number_format((float)($p['amount'] ?? 0), 2); ?></td>
                                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400"><?php echo htmlspecialchars(ucfirst(str_replace('_',' ', $p['payment_method'] ?? '—'))); ?></td>
                                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400"><?php echo htmlspecialchars($p['payment_reference'] ?? '—'); ?></td>
                                        <td class="px-4 py-3">
                                            <?php if ($isPending): ?>
                                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400">
                                                    <i class="fas fa-clock mr-1"></i>Pending
                                                </span>
                                                <a href="../cashier/index.php" class="block text-xs text-indigo-600 dark:text-indigo-400 hover:underline mt-1">Verify in Cashier →</a>
                                            <?php elseif ($isVerified): ?>
                                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400">
                                                    <i class="fas fa-check mr-1"></i>Verified
                                                </span>
                                            <?php elseif ($isRejected): ?>
                                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400">
                                                    <i class="fas fa-times mr-1"></i>Rejected
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400"><?php echo htmlspecialchars($st ?: '—'); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">
                                            <?php echo htmlspecialchars($p['verified_by_name'] ?? '—'); ?>
                                            <?php if (!empty($p['verified_at'])): ?>
                                                <br><span class="text-xs text-gray-500"><?php echo date('M j, g:i A', strtotime($p['verified_at'])); ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400 border-t border-gray-100 dark:border-gray-700">Showing up to 200 most recent. Verify pending payments in <a href="../cashier/index.php" class="text-indigo-600 dark:text-indigo-400 hover:underline">Cashier</a>.</p>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>
