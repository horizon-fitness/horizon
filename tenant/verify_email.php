<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['verify_user_id'])) {
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['verify_user_id'];
$email = $_SESSION['verify_email'];
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $entered_code = $_POST['otp_code'] ?? '';
    $current_date = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare("SELECT * FROM user_verifications WHERE user_id = ? AND verification_type = 'email' AND status = 'pending' ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$user_id]);
    $verification = $stmt->fetch();

    if ($verification) {
        if ($current_date > $verification['expires_at']) {
            $error = 'This verification code has expired. Please request a new one.';
            $updateExpired = $pdo->prepare("UPDATE user_verifications SET status = 'expired' WHERE verification_id = ?");
            $updateExpired->execute([$verification['verification_id']]);
        } elseif ($entered_code === $verification['code']) {
            try {
                $pdo->beginTransaction();

                $verifyUpdate = $pdo->prepare("UPDATE user_verifications SET status = 'verified', verified_at = ? WHERE verification_id = ?");
                $verifyUpdate->execute([$current_date, $verification['verification_id']]);

                $userUpdate = $pdo->prepare("UPDATE users SET is_verified = 1 WHERE user_id = ?");
                $userUpdate->execute([$user_id]);

                $pdo->commit();

                $success = 'Email successfully verified! Your gym application is now awaiting admin approval.';
                
                unset($_SESSION['verify_user_id']);
                unset($_SESSION['verify_email']);
                
                header("refresh:3;url=../login.php");

            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'A database error occurred. Please try again.';
            }
        } else {
            $error = 'Invalid verification code. Please try again.';
        }
    } else {
        $error = 'No pending verification found.';
    }
}
?>

<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Verify Email | Horizon Systems</title>

    <link href="https://fonts.googleapis.com" rel="preconnect"/>
    <link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>

    <script id="tailwind-config">
    tailwind.config = {
        darkMode: "class",
        theme: {
            extend: {
                colors: {
                    "primary": "#7f13ec",
                    "primary-hover": "#6a11c9",
                    "background-dark": "#0a090d",
                    "input-border": "#2d2838",
                },
                fontFamily: { "display": ["Lexend", "sans-serif"] },
            },
        },
    }
    </script>
</head>

<body class="bg-background-dark text-white font-display min-h-screen flex flex-col antialiased relative overflow-hidden">

<div class="fixed inset-0 z-0">
    <div class="absolute inset-0 bg-[#0a090d]/85 backdrop-blur-sm"></div>
    <div class="absolute inset-0 bg-gradient-to-t from-primary/10 to-transparent"></div>
</div>

<main class="flex-1 flex items-center justify-center p-4 relative z-10">
    <div class="w-full max-w-[420px] rounded-[32px] border border-white/5 bg-[#121017]/80 backdrop-blur-xl shadow-[0_0_50px_rgba(127,19,236,0.15)] p-10 text-center">
        
        <div class="mx-auto size-16 bg-primary/20 rounded-full flex items-center justify-center mb-6">
            <span class="material-symbols-outlined text-primary text-3xl">mark_email_read</span>
        </div>

        <div class="space-y-3 mb-8">
            <h2 class="text-2xl font-black tracking-tight uppercase italic">Verify Identity</h2>
            <p class="text-gray-400 text-sm font-medium">We've sent a 6-digit verification code to <br><span class="text-white"><?= htmlspecialchars($email) ?></span></p>
        </div>

        <?php if (!empty($error)): ?>
        <div class="mb-6 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-xs flex items-center justify-center gap-2 font-semibold">
            <span class="material-symbols-outlined text-[16px]">error</span>
            <?= $error ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
        <div class="mb-6 p-4 rounded-xl bg-green-500/10 border border-green-500/20 text-green-400 text-xs flex items-center justify-center gap-2 font-semibold">
            <span class="material-symbols-outlined text-[16px]">check_circle</span>
            <?= $success ?>
            <p class="mt-1 text-[10px] text-green-400/70">Redirecting to login...</p>
        </div>
        <?php else: ?>

        <form method="POST" class="space-y-6">
            <div class="space-y-2 text-left">
                <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">Secure Code</label>
                <div class="relative group">
                    <span class="absolute left-4 top-1/2 -translate-y-1/2 material-symbols-outlined text-gray-600 group-focus-within:text-primary transition-colors">pin</span>
                    <input
                        class="flex h-14 w-full rounded-xl border border-input-border bg-black/40 pl-12 pr-4 text-center text-xl tracking-[0.5em] text-white placeholder:text-gray-700 focus:outline-none focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all font-bold"
                        name="otp_code"
                        placeholder="••••••"
                        maxlength="6"
                        required
                        type="text"
                    />
                </div>
            </div>

            <button
                class="w-full h-14 mt-2 rounded-xl bg-primary hover:bg-primary-hover text-white font-black uppercase tracking-widest text-sm transition-all shadow-lg shadow-primary/25 flex items-center justify-center gap-3 hover:scale-[1.01] active:scale-[0.99]"
                type="submit">
                Verify Account
                <span class="material-symbols-outlined text-xl">verified_user</span>
            </button>
        </form>

        <div class="mt-8">
            <p class="text-[11px] text-gray-500 font-medium">
                Didn't receive the code? 
                <a class="text-primary font-black uppercase tracking-wider hover:underline ml-1 cursor-pointer">Resend OTP</a>
            </p>
        </div>

        <?php endif; ?>
    </div>
</main>

</body>
</html>