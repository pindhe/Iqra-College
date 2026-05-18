<?php
/**
 * Forgot Password Page
 * Request password reset via WhatsApp verification
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Redirect if already logged in
if (isLoggedIn()) {
    $role = getCurrentUserRole();
    if ($role === 'admin') {
        header('Location: /Iqra-College/admin/index.php');
    } elseif ($role === 'teacher') {
        header('Location: /Iqra-College/teacher/index.php');
    } elseif ($role === 'cashier') {
        header('Location: /Iqra-College/cashier/index.php');
    } else {
        header('Location: /Iqra-College/student/index.php');
    }
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitize($_POST['email'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');

    if (empty($email) && empty($phone)) {
        $error = 'Please enter either your email or phone number';
    } else {
        try {
            $pdo = getDBConnection();
            
            // Find user by email or phone
            if (!empty($email)) {
                $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
                $stmt->execute([$email]);
            } else {
                $stmt = $pdo->prepare("SELECT * FROM users WHERE phone = ?");
                $stmt->execute([$phone]);
            }
            
            $user = $stmt->fetch();

            if (!$user) {
                $error = 'No account found with the provided email or phone number';
            } else {
                // Check if user has a phone number
                if (empty($user['phone'])) {
                    $error = 'Your account does not have a phone number registered. Please contact admin to add your phone number.';
                } else {
                    // Generate verification code (6 digits)
                    $verificationCode = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
                    
                    // Generate token
                    $token = bin2hex(random_bytes(32));
                    
                    // Set expiration (15 minutes)
                    $expiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                    
                    // Delete old tokens for this user
                    $stmt = $pdo->prepare("DELETE FROM password_resets WHERE email = ?");
                    $stmt->execute([$user['email']]);
                    
                    // Insert new reset token
                    $stmt = $pdo->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)");
                    $stmt->execute([$user['email'], $token, $expiresAt]);
                    
                    // Send WhatsApp message
                    $message = "IQRA College Password Reset\n\nYour verification code is: *{$verificationCode}*\n\nThis code will expire in 15 minutes.\n\nIf you didn't request this, please ignore this message.";
                    
                    $whatsappSent = sendWhatsAppMessageAPI($user['phone'], $message);
                    
                    if ($whatsappSent) {
                        // Store verification code in session temporarily
                        $_SESSION['reset_token'] = $token;
                        $_SESSION['reset_code'] = $verificationCode;
                        $_SESSION['reset_email'] = $user['email'];
                        $_SESSION['reset_expires'] = $expiresAt;
                        
                        header('Location: reset-password.php?token=' . urlencode($token));
                        exit();
                    } else {
                        $error = 'Failed to send WhatsApp message. Please try again or contact support.';
                    }
                }
            }
        } catch (PDOException $e) {
            $error = 'An error occurred. Please try again.';
            error_log("Forgot password error: " . $e->getMessage());
        }
    }
}

// Background: try local images first
$imgDir = __DIR__ . '/../assets/images';
$authBg = 'https://i.pinimg.com/736x/04/02/8a/04028ade7be4053e3958619e085559dd.jpg';
if (file_exists($imgDir . '/auth-bg.jpg')) {
    $authBg = '../assets/images/auth-bg.jpg';
} elseif (file_exists($imgDir . '/auth-bg.png')) {
    $authBg = '../assets/images/auth-bg.png';
} elseif (file_exists($imgDir . '/arday.jpg')) {
    $authBg = '../assets/images/arday.jpg';
} elseif (file_exists($imgDir . '/arday.png')) {
    $authBg = '../assets/images/arday.png';
}

/**
 * Send WhatsApp Message via API
 * @param string $phone Phone number (with country code)
 * @param string $message Message to send
 * @return bool
 */
function sendWhatsAppMessageAPI($phone, $message) {
    // Remove any non-numeric characters except +
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    
    // Ensure phone starts with country code
    if (!preg_match('/^\+/', $phone)) {
        if (substr($phone, 0, 1) === '0') {
            $phone = '+62' . substr($phone, 1); // Example for Indonesia
        } else {
            $phone = '+62' . $phone; // Default country code
        }
    }
    
    // Use the API endpoint
    $apiUrl = '/Iqra-College/api/whatsapp-send.php';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'phone' => $phone,
        'message' => $message
    ]));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $result = json_decode($response, true);
        return isset($result['success']) && $result['success'] === true;
    }
    
    return false;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" href="/Iqra-College/assets/images/iqra2.png" type="image/png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - IQRA College</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: { 50: '#eff6ff', 100: '#dbeafe', 400: '#60a5fa', 500: '#3b82f6', 600: '#2563eb', 700: '#1d4ed8' }
                    }
                }
            }
        };
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', system-ui, sans-serif; }
        .auth-bg {
            background-image: url('<?php echo htmlspecialchars($authBg); ?>');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
        }
        @media (max-width: 768px) { .auth-bg { background-attachment: scroll; } }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-fade-up { opacity: 0; animation: fadeUp 0.5s ease-out forwards; }
        .animate-fade-up-1 { animation-delay: 0.08s; }
        .animate-fade-up-2 { animation-delay: 0.16s; }
        .animate-fade-up-3 { animation-delay: 0.24s; }
        .btn-primary {
            background: linear-gradient(135deg, #2563eb 0%, #3b82f6 50%, #60a5fa 100%);
            background-size: 200% 200%;
            box-shadow: 0 4px 14px 0 rgba(59, 130, 246, 0.4);
        }
        .btn-primary:hover { background-position: 100% 0; box-shadow: 0 6px 20px 0 rgba(59, 130, 246, 0.5); }
        .input-focus:focus { box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.25); }
        .glass {
            background: rgba(255, 255, 255, 0.97);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.8);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.12), 0 0 0 1px rgba(255, 255, 255, 0.5) inset;
        }
        .auth-card { position: relative; overflow: hidden; }
        .auth-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background: linear-gradient(90deg, #1d4ed8, #3b82f6, #60a5fa);
        }
        .link-accent { color: #2563eb; }
        .link-accent:hover { color: #1d4ed8; }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4 sm:p-6 auth-bg">
    <!-- Overlay -->
    <div class="fixed inset-0 bg-gradient-to-br from-blue-900/75 via-slate-800/65 to-slate-900/80 pointer-events-none" aria-hidden="true"></div>

    <div class="relative w-full max-w-md">
        <div class="glass rounded-2xl auth-card p-8 sm:p-10 animate-fade-up">
            <div class="text-center mb-8 animate-fade-up animate-fade-up-1">
                <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-gradient-to-br from-blue-500 to-blue-600 text-white shadow-lg shadow-blue-500/30 mb-4">
                    <i class="fas fa-key text-xl"></i>
                </div>
                <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-800 tracking-tight">Forgot Password</h1>
                <p class="text-slate-500 mt-1 text-sm">We'll send a verification code to your WhatsApp</p>
            </div>

            <?php if ($error): ?>
                <div class="mb-6 p-4 rounded-xl bg-rose-50 border border-rose-200/80 text-rose-700 text-sm flex items-center gap-3 animate-fade-up animate-fade-up-2" role="alert">
                    <i class="fas fa-circle-exclamation text-rose-500 flex-shrink-0"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="mb-6 p-4 rounded-xl bg-emerald-50 border border-emerald-200/80 text-emerald-800 text-sm flex items-start gap-3 animate-fade-up animate-fade-up-2" role="status">
                    <i class="fas fa-circle-check text-emerald-500 flex-shrink-0 mt-0.5"></i>
                    <div><?php echo $success; ?></div>
                </div>
            <?php endif; ?>

            <form method="POST" action="" class="space-y-5 animate-fade-up animate-fade-up-2" id="forgotForm">
                <div>
                    <label for="email" class="block text-sm font-semibold text-slate-700 mb-2">Email Address</label>
                    <div class="relative">
                        <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"><i class="fas fa-envelope text-sm"></i></span>
                        <input type="email" name="email" id="email" autocomplete="email"
                               class="w-full pl-11 pr-4 py-3.5 rounded-xl border-2 border-slate-200 focus:border-blue-500 focus:outline-none input-focus transition-all text-slate-800 placeholder-slate-400"
                               placeholder="you@example.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>
                </div>

                <div class="relative">
                    <div class="absolute inset-0 flex items-center">
                        <div class="w-full border-t border-slate-200"></div>
                    </div>
                    <div class="relative flex justify-center text-sm">
                        <span class="px-2 bg-white text-slate-500">OR</span>
                    </div>
                </div>

                <div>
                    <label for="phone" class="block text-sm font-semibold text-slate-700 mb-2">Phone Number</label>
                    <div class="relative">
                        <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"><i class="fas fa-phone text-sm"></i></span>
                        <input type="tel" name="phone" id="phone" autocomplete="tel"
                               class="w-full pl-11 pr-4 py-3.5 rounded-xl border-2 border-slate-200 focus:border-blue-500 focus:outline-none input-focus transition-all text-slate-800 placeholder-slate-400"
                               placeholder="+62xxxxxxxxxxx" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                    </div>
                    <p class="text-xs text-slate-500 mt-1">Include country code (e.g., +62 for Indonesia)</p>
                </div>

                <button type="submit" class="w-full btn-primary text-white font-bold py-3.5 px-6 rounded-xl transition-all duration-300 active:scale-[0.98] animate-fade-up animate-fade-up-3">
                    <i class="fab fa-whatsapp mr-2"></i>Send Verification Code
                </button>
            </form>

            <div class="mt-8 text-center animate-fade-up animate-fade-up-3">
                <p class="text-slate-600 text-sm">
                    Remember your password?
                    <a href="login.php" class="font-semibold link-accent hover:underline transition-colors">Sign in</a>
                </p>
                <p class="text-slate-600 text-sm mt-3">
                    <a href="../index.php" class="font-semibold link-accent hover:underline transition-colors inline-flex items-center gap-1.5">
                        <i class="fas fa-arrow-left text-xs"></i> Back to Home
                    </a>
                </p>
            </div>
        </div>
        <p class="text-center text-white/90 text-sm mt-6 drop-shadow-md">© IQRA College</p>
    </div>

    <script>
    (function() {
        var form = document.getElementById('forgotForm');
        if (form) {
            form.addEventListener('submit', function() {
                var submit = form.querySelector('button[type="submit"]');
                if (submit) {
                    submit.disabled = true;
                    submit.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Sending...';
                }
            });
        }
    })();
    </script>
</body>
</html>

