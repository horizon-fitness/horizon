<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['recovery_user_id'])) {
    header("Location: forgot_password.php");
    exit;
}

$user_id = $_SESSION['recovery_user_id'];
$email = $_SESSION['recovery_email'];
$error = '';
$success = '';
$branding = null;

if (isset($_GET['gym'])) {
    $slug = $_GET['gym'];
    $stmtBranding = $pdo->prepare("SELECT tp.*, g.gym_name FROM tenant_pages tp JOIN gyms g ON tp.gym_id = g.gym_id WHERE tp.page_slug = ? LIMIT 1");
    $stmtBranding->execute([$slug]);
    $branding = $stmtBranding->fetch(PDO::FETCH_ASSOC);
}

if (isset($_SESSION['reset_success'])) {
    $success = $_SESSION['reset_success'];
    unset($_SESSION['reset_success']);
}

if (isset($_SESSION['reset_error'])) {
    $error = $_SESSION['reset_error'];
    unset($_SESSION['reset_error']);
}
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Verify Recovery | Horizon Systems</title>
    
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
                        "surface-dark": "rgba(21, 21, 24, 0.4)",
                    },
                    fontFamily: { 
                        "display": ["<?= $branding['font_family'] ?? 'Lexend' ?>", "sans-serif"],
                        "sans": ["Plus Jakarta Sans", "Inter", "sans-serif"]
                    },
                    borderRadius: { 'custom': '12px' }
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

    <nav class="w-full px-8 py-6 flex justify-between items-center relative z-20">
        <a href="index.php" class="flex items-center gap-3 hover:opacity-80 transition-opacity">
            <div class="size-8 bg-primary/20 rounded-lg flex items-center justify-center border border-primary/30 overflow-hidden">
                <?php if ($branding && !empty($branding['logo_path'])): ?>
                    <img src="<?= $branding['logo_path'] ?>" class="size-full object-contain">
                <?php else: ?>
                    <span class="material-symbols-outlined text-primary text-xl">blur_on</span>
                <?php endif; ?>
            </div>
            <h2 class="text-lg font-display font-bold text-white uppercase italic tracking-tighter"><?= $branding['gym_name'] ?? 'Horizon' ?> <span class="text-primary"><?= $branding ? 'Portal' : 'System' ?></span></h2>
        </a>
    </nav>

    <main class="flex-1 flex items-center justify-center p-6 relative z-10">
        <div class="dashboard-window w-full max-w-[440px] rounded-2xl p-10 md:p-12 relative overflow-hidden">
            <div class="absolute -top-24 -right-24 w-48 h-48 bg-primary/10 blur-[60px] rounded-full pointer-events-none"></div>
            
            <div class="relative z-10">
                <div class="text-center mb-10">
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/5 border border-white/10 text-primary text-[9px] font-black uppercase tracking-[0.2em] mb-4">
                        OTP Verification
                    </div>
                    <h1 class="text-4xl font-display font-black text-white uppercase italic tracking-tighter mb-2">
                        Verify <span class="text-primary">Identity</span>
                    </h1>
                    <p class="text-xs text-gray-400 font-medium uppercase tracking-widest">Code sent to <?= htmlspecialchars($email) ?></p>
                </div>

                <?php if (!empty($error)): ?>
                <div class="mb-8 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-[11px] flex items-center gap-3 font-bold uppercase tracking-wider">
                    <span class="material-symbols-outlined text-lg">error</span>
                    <?= $error ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($success)): ?>
                <div class="mb-8 p-4 rounded-xl bg-green-500/10 border border-green-500/20 text-green-400 text-[11px] flex items-center gap-3 font-bold uppercase tracking-wider">
                    <span class="material-symbols-outlined text-lg">check_circle</span>
                    <?= $success ?>
                </div>
                <?php endif; ?>

                <form action="action/verify_recovery.php" method="POST" class="space-y-6">
                    <?php if (isset($_GET['gym'])): ?>
                        <input type="hidden" name="gym" value="<?= htmlspecialchars($_GET['gym']) ?>">
                    <?php endif; ?>
                    
                    <div class="space-y-2">
                        <label class="text-[10px] font-display font-bold uppercase tracking-widest text-gray-500 ml-1">6-Digit Code</label>
                        <div class="relative group">
                            <input
                                class="flex h-14 w-full rounded-xl border border-white/5 bg-white/[0.02] text-center text-2xl tracking-[0.5em] text-white placeholder:text-gray-700 focus:outline-none focus:border-primary/50 transition-all font-bold"
                                name="otp_code"
                                placeholder="••••••"
                                maxlength="6"
                                required
                                type="text"
                                autofocus
                            />
                        </div>
                    </div>

                    <button
                        class="w-full h-14 mt-6 rounded-xl bg-primary hover:bg-primary-dark text-white font-display font-bold uppercase tracking-widest text-[11px] transition-all shadow-lg shadow-primary/20 flex items-center justify-center gap-3 hover:scale-[1.02] active:scale-[0.98]"
                        type="submit">
                        Verify Code
                        <span class="material-symbols-outlined text-lg">verified_user</span>
                    </button>
                </form>

                <div class="text-center mt-10 pt-8 border-t border-white/5">
                    <p class="text-[11px] text-gray-500 font-medium">
                        Didn't receive the code? 
                        <a href="action/send_recovery_otp.php?resend=1<?= isset($_GET['gym']) ? '&gym='.urlencode($_GET['gym']) : '' ?>" class="text-primary font-black uppercase tracking-wider hover:underline ml-1">Resend</a>
                    </p>
                </div>
            </div>
        </div>
    </main>

</body>
</html>
