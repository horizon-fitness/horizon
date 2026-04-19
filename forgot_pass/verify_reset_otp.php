<?php
session_start();
require_once '../db.php';

$error = '';
$success = '';
$branding = null;

// Ensure we have a user to reset for
if (!isset($_SESSION['reset_user_id'])) {
    header("Location: forgot_password.php");
    exit;
}

$display_email = $_SESSION['reset_email'] ?? 'your email';

// Fetch branding if gym slug is provided
if (isset($_GET['gym'])) {
    $slug = $_GET['gym'];
    // Lookup gym by tenant_code
    $stmtG = $pdo->prepare("SELECT gym_id, gym_name, profile_picture as logo_path, owner_user_id FROM gyms WHERE LOWER(tenant_code) = LOWER(?) LIMIT 1");
    $stmtG->execute([$slug]);
    $gym = $stmtG->fetch(PDO::FETCH_ASSOC);

    if ($gym) {
        // Fetch branding from system_settings
        $stmtS = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = ? OR user_id = 0 ORDER BY user_id ASC");
        $stmtS->execute([$gym['owner_user_id']]);
        $settings = $stmtS->fetchAll(PDO::FETCH_KEY_PAIR);

        $branding = [
            'gym_id' => $gym['gym_id'],
            'gym_name' => $gym['gym_name'],
            'logo_path' => $gym['logo_path'],
            'theme_color' => $settings['theme_color'] ?? '#7f13ec',
            'bg_color' => $settings['bg_color'] ?? '#050505',
            'font_family' => $settings['font_family'] ?? 'Lexend'
        ];
    }
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
    <title>Verify Code | Horizon Systems</title>
    
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
        /* Invisible Scroll System */
        *::-webkit-scrollbar { display: none; }
        * { -ms-overflow-style: none; scrollbar-width: none; }

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
        .input-gradient-focus:focus-within {
            border-color: #7f13ec;
            box-shadow: 0 0 0 1px rgba(127, 19, 236, 0.3);
        }
        .otp-box {
            background-color: #08080a !important;
            color: #ffffff !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            appearance: none;
            -webkit-appearance: none;
        }
        .otp-box:focus {
            background-color: rgba(127, 19, 236, 0.05) !important;
            border-color: #7f13ec !important;
            box-shadow: 0 0 15px rgba(127, 19, 236, 0.2) !important;
            transform: translateY(-2px);
            outline: none !important;
        }
        /* Anti-Autofill and forced background fix */
        .otp-box:-webkit-autofill,
        .otp-box:-webkit-autofill:hover, 
        .otp-box:-webkit-autofill:focus {
            -webkit-text-fill-color: #ffffff !important;
            -webkit-box-shadow: 0 0 0px 1000px #08080a inset !important;
            transition: background-color 5000s ease-in-out 0s;
        }
    </style>
</head>

<body class="font-sans antialiased min-h-screen flex flex-col hero-glow">

    <nav class="w-full px-8 py-6 flex justify-between items-center relative z-20">
        <a href="../index.php" class="flex items-center gap-3 hover:opacity-80 transition-opacity">
            <div class="size-8 bg-primary/20 rounded-lg flex items-center justify-center border border-primary/30 overflow-hidden">
                <?php if ($branding && !empty($branding['logo_path'])): ?>
                    <img src="<?= $branding['logo_path'] ?>" class="size-full object-contain">
                <?php else: ?>
                    <img src="../assests/horizon logo.png" alt="Horizon Logo" class="size-full object-contain rounded-lg">
                <?php endif; ?>
            </div>
            <h2 class="text-lg font-display font-bold text-white uppercase italic tracking-tighter"><?= $branding['gym_name'] ?? 'Horizon' ?> <span class="text-primary"><?= $branding ? 'Portal' : 'System' ?></span></h2>
        </a>

        <a href="forgot_password.php<?= isset($_GET['gym']) ? '?gym='.htmlspecialchars($_GET['gym']) : '' ?>" class="text-[10px] font-display font-bold text-gray-500 hover:text-white transition-colors uppercase tracking-widest flex items-center gap-2">
            <span class="material-symbols-outlined text-sm">arrow_back</span>
            Back to Email Entry
        </a>
    </nav>

    <main class="flex-1 flex items-center justify-center p-6 relative z-10">
        <div class="dashboard-window w-full max-w-[440px] rounded-2xl p-10 md:p-12 relative overflow-hidden">
            <div class="absolute -top-24 -right-24 w-48 h-48 bg-primary/10 blur-[60px] rounded-full pointer-events-none"></div>
            
            <div class="relative z-10 space-y-8">
                <div class="text-center">
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/5 border border-white/10 text-primary text-[9px] font-black uppercase tracking-[0.2em] mb-4">
                        Verification Required
                    </div>
                    <h1 class="text-3xl font-display font-black text-white uppercase italic tracking-tighter mb-6 whitespace-nowrap">
                        Check your <span class="text-primary">Email</span>
                    </h1>
                    <p class="text-xs text-gray-400 font-medium tracking-wide leading-relaxed">
                        We've sent a 6-digit code to <span class="text-white font-bold"><?= htmlspecialchars($display_email) ?></span>.<br>
                        It expires in 15 minutes.
                    </p>
                </div>

                <?php if (!empty($error)): ?>
                <div id="alert-message" class="mb-8 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-[11px] flex items-center gap-3 font-bold tracking-wide relative group animate-fade-in">
                    <span class="material-symbols-outlined text-lg">error</span>
                    <span class="flex-1"><?= $error ?></span>
                    <button onclick="dismissAlert('alert-message')" class="opacity-50 hover:opacity-100 transition-opacity">
                        <span class="material-symbols-outlined text-base">close</span>
                    </button>
                </div>
                <?php endif; ?>

                <?php if (!empty($success)): ?>
                <div id="alert-message" class="mb-8 p-4 rounded-xl bg-green-500/10 border border-green-500/20 text-green-400 text-[11px] flex items-center gap-3 font-bold tracking-wide relative group animate-fade-in">
                    <span class="material-symbols-outlined text-lg">check_circle</span>
                    <span class="flex-1"><?= $success ?></span>
                    <button onclick="dismissAlert('alert-message')" class="opacity-50 hover:opacity-100 transition-opacity">
                        <span class="material-symbols-outlined text-base">close</span>
                    </button>
                </div>
                <?php endif; ?>

                <form action="action/verify_reset_otp.php<?= isset($_GET['gym']) ? '?gym='.htmlspecialchars($_GET['gym']) : '' ?>" method="POST" class="space-y-10">
                    <div class="space-y-4">
                        <label class="text-[10px] font-display font-bold uppercase tracking-widest text-gray-500 ml-1">Verification Code</label>
                        <div class="flex gap-3 justify-between" id="otp-inputs">
                            <input type="text" maxlength="1" pattern="\d" class="otp-box w-12 h-16 text-center text-3xl font-black rounded-xl text-white outline-none focus:ring-0" inputmode="numeric">
                            <input type="text" maxlength="1" pattern="\d" class="otp-box w-12 h-16 text-center text-3xl font-black rounded-xl text-white outline-none focus:ring-0" inputmode="numeric">
                            <input type="text" maxlength="1" pattern="\d" class="otp-box w-12 h-16 text-center text-3xl font-black rounded-xl text-white outline-none focus:ring-0" inputmode="numeric">
                            <input type="text" maxlength="1" pattern="\d" class="otp-box w-12 h-16 text-center text-3xl font-black rounded-xl text-white outline-none focus:ring-0" inputmode="numeric">
                            <input type="text" maxlength="1" pattern="\d" class="otp-box w-12 h-16 text-center text-3xl font-black rounded-xl text-white outline-none focus:ring-0" inputmode="numeric">
                            <input type="text" maxlength="1" pattern="\d" class="otp-box w-12 h-16 text-center text-3xl font-black rounded-xl text-white outline-none focus:ring-0" inputmode="numeric">
                        </div>
                        <input type="hidden" name="otp_code" id="otp_full_code">
                    </div>

                    <button
                        class="w-full h-14 mt-4 rounded-xl bg-primary hover:bg-primary-dark text-white font-display font-bold uppercase tracking-widest text-[11px] transition-all shadow-lg shadow-primary/20 flex items-center justify-center gap-3 hover:scale-[1.02] active:scale-[0.98]"
                        type="submit">
                        Verify Code
                        <span class="material-symbols-outlined text-lg">verified_user</span>
                    </button>
                </form>

                <div class="text-center pt-8 border-t border-white/5">
                    <p class="text-[10px] text-gray-600 font-bold uppercase tracking-widest">
                        Didn't receive the code? 
                        <a id="resendLink" class="text-primary hover:text-white transition-colors ml-1 pointer-events-none opacity-50" href="action/resend_reset_otp.php<?= isset($_GET['gym']) ? '?gym='.htmlspecialchars($_GET['gym']) : '' ?>">
                            Resend Code<span id="resendCountdown"> (60s)</span>
                        </a>
                    </p>
                </div>


            </div>
        </div>
    </main>

    <footer class="relative z-20 w-full py-6 text-center -mt-10">
        <p class="text-[9px] font-display font-bold text-gray-700 uppercase tracking-[0.4em]">
            © 2026 HORIZON SYSTEM. SECURE ENVIRONMENT.
        </p>
    </footer>

    <script>
    function setupOTP() {
        const container = document.getElementById('otp-inputs');
        const inputs = container.querySelectorAll('input');
        const hiddenField = document.getElementById('otp_full_code');

        inputs.forEach((input, index) => {
            // Handle typing
            input.addEventListener('input', (e) => {
                input.value = input.value.replace(/\D/g, '');
                if (input.value.length === 1) {
                    if (index < inputs.length - 1) inputs[index + 1].focus();
                }
                updateHiddenField();
            });

            // Handle backspace
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Backspace' && input.value.length === 0 && index > 0) {
                    inputs[index - 1].focus();
                }
            });

            // Handle paste
            input.addEventListener('paste', (e) => {
                e.preventDefault();
                const data = e.clipboardData.getData('text').slice(0, 6);
                if (/^\d+$/.test(data)) {
                    data.split('').forEach((char, i) => {
                        if (index + i < inputs.length) {
                            inputs[index + i].value = char;
                        }
                    });
                    const nextIndex = Math.min(index + data.length, inputs.length - 1);
                    inputs[nextIndex].focus();
                    updateHiddenField();
                }
            });
        });

        function updateHiddenField() {
            const val = Array.from(inputs).map(i => i.value).join('');
            hiddenField.value = val;
        }
    }

    function dismissAlert(id) {
        const alert = document.getElementById(id);
        if (alert) {
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-10px)';
            alert.style.transition = 'all 0.5s ease';
            setTimeout(() => alert.remove(), 500);
        }
    }

    function startResendTimer() {
        let seconds = 60;
        const resendLink = document.getElementById('resendLink');
        const resendCountdown = document.getElementById('resendCountdown');
        
        const timer = setInterval(() => {
            seconds--;
            if (seconds > 0) {
                resendCountdown.textContent = ` (${seconds}s)`;
            } else {
                clearInterval(timer);
                resendLink.classList.remove('pointer-events-none', 'opacity-50');
                resendCountdown.textContent = '';
            }
        }, 1000);
    }
    
    // Start the functions on page load
    window.onload = function() {
        startResendTimer();
        setupOTP();
        
        // Auto-dismiss alert after 10 seconds
        const alert = document.getElementById('alert-message');
        if (alert) {
            setTimeout(() => dismissAlert('alert-message'), 10000);
        }
    };
    </script>
</body>
</html>
