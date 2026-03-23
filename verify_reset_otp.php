<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['reset_user_id'])) {
    header("Location: forgot_password.php");
    exit;
}

$error = '';
$success = '';
$branding = null;
$email = $_SESSION['reset_email'] ?? 'your email';

if (isset($_GET['gym'])) {
    $slug = $_GET['gym'];
    $stmtBranding = $pdo->prepare("SELECT tp.*, g.gym_name FROM tenant_pages tp JOIN gyms g ON tp.gym_id = g.gym_id WHERE tp.page_slug = ? LIMIT 1");
    $stmtBranding->execute([$slug]);
    $branding = $stmtBranding->fetch(PDO::FETCH_ASSOC);
}

if (isset($_SESSION['reset_error'])) {
    $error = $_SESSION['reset_error'];
    unset($_SESSION['reset_error']);
}

if (isset($_SESSION['reset_success'])) {
    $success = $_SESSION['reset_success'];
    unset($_SESSION['reset_success']);
}
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Verify Reset Code | Horizon Systems</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com"/>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin=""/>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Lexend:wght@300;400;500;700;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "<?= $branding['theme_color'] ?? '#7f13ec' ?>",
                        "primary-dark": "<?= $branding['theme_color'] ?? '#5e0eb3' ?>",
                        "background-dark": "<?= $branding['bg_color'] ?? '#050505' ?>", 
                    },
                    fontFamily: { 
                        "display": ["<?= $branding['font_family'] ?? 'Lexend' ?>", "sans-serif"],
                        "sans": ["Plus Jakarta Sans", "Inter", "sans-serif"]
                    },
                },
            },
        }
    </script>
    <style>
        html, body { 
            background-color: #050505 !important; 
            color: #f3f4f6;
            margin: 0;
            padding: 0;
            min-height: 100vh;
        }
        .hero-glow {
            background-image: radial-gradient(circle at 50% -10%, rgba(127, 19, 236, 0.12), transparent 70%);
        }
        .dashboard-window {
            background: #08080a;
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 40px 100px -20px rgba(0, 0, 0, 1);
        }
    </style>
</head>

<body class="font-sans antialiased min-h-screen flex flex-col hero-glow">

    <main class="flex-1 flex items-center justify-center p-6 relative z-10">
        <div class="dashboard-window w-full max-w-[420px] rounded-[32px] p-10 text-center relative overflow-hidden">
            <div class="absolute -top-24 -right-24 w-48 h-48 bg-primary/10 blur-[60px] rounded-full pointer-events-none"></div>
            
            <div class="mx-auto size-16 bg-primary/20 rounded-full flex items-center justify-center mb-6 border border-primary/30">
                <span class="material-symbols-outlined text-primary text-3xl">lock_reset</span>
            </div>

            <div class="space-y-3 mb-8">
                <h2 class="text-2xl font-black tracking-tight uppercase italic font-display">Verify Identity</h2>
                <p class="text-gray-400 text-sm font-medium">We've sent a 6-digit reset code to <br><span class="text-white"><?= htmlspecialchars($email) ?></span></p>
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
            </div>
            <?php endif; ?>

            <form method="POST" action="action/verify_reset_otp.php<?= isset($_GET['gym']) ? '?gym='.urlencode($_GET['gym']) : '' ?>" class="space-y-6">
                <div class="space-y-2 text-left">
                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">Reset Code</label>
                    <div class="relative group">
                        <span class="absolute left-4 top-1/2 -translate-y-1/2 material-symbols-outlined text-gray-600 group-focus-within:text-primary transition-colors">pin</span>
                        <input
                            class="flex h-14 w-full rounded-xl border border-white/5 bg-black/40 pl-12 pr-4 text-center text-xl tracking-[0.5em] text-white placeholder:text-gray-700 focus:outline-none focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all font-bold"
                            name="otp_code"
                            placeholder="••••••"
                            maxlength="6"
                            required
                            type="text"
                        />
                    </div>
                </div>

                <button
                    class="w-full h-14 mt-2 rounded-xl bg-primary hover:bg-primary-dark text-white font-black uppercase tracking-widest text-sm transition-all shadow-lg shadow-primary/25 flex items-center justify-center gap-3 hover:scale-[1.01] active:scale-[0.99]"
                    type="submit">
                    Verify Code
                    <span class="material-symbols-outlined text-xl">verified_user</span>
                </button>
            </form>

            <div class="mt-8">
                <p class="text-[11px] text-gray-500 font-medium font-display">
                    Didn't receive the code? 
                    <a href="action/send_reset_otp.php?resend=1<?= isset($_GET['gym']) ? '&gym='.urlencode($_GET['gym']) : '' ?>" class="text-primary font-black uppercase tracking-wider hover:underline ml-1">Resend Code</a>
                </p>
                <a href="forgot_password.php<?= isset($_GET['gym']) ? '?gym='.urlencode($_GET['gym']) : '' ?>" class="inline-block mt-4 text-[10px] text-gray-600 hover:text-white transition-colors font-bold uppercase tracking-widest">Back to Recovery</a>
            </div>
        </div>
    </main>

    <footer class="relative z-20 w-full py-6 text-center -mt-10">
        <p class="text-[9px] font-display font-bold text-gray-700 uppercase tracking-[0.4em]">
            © 2026 HORIZON SYSTEM. SECURE ENVIRONMENT.
        </p>
    </footer>

</body>
</html>
