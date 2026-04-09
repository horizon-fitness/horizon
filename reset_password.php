<?php
session_start();
require_once 'db.php';

$error = '';
$success = '';
$branding = null;

// Use session-based authorization from OTP verification
$user_id = $_SESSION['reset_authorized_user_id'] ?? null;

if (!$user_id) {
    $_SESSION['reset_error'] = "Unauthorized access. Please start the recovery process again.";
    header("Location: forgot_password.php" . (isset($_GET['gym']) ? "?gym=" . urlencode($_GET['gym']) : ""));
    exit;
}

$temp_password = $_SESSION['temp_password'] ?? '';
unset($_SESSION['temp_password']);

// Fetch branding if gym slug is provided
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
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>Reset Password | Horizon Systems</title>

    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin="" />
    <link
        href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Lexend:wght@300;400;500;700;900&display=swap"
        rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap"
        rel="stylesheet" />

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
        *::-webkit-scrollbar {
            display: none;
        }

        * {
            scrollbar-width: none;
            -ms-overflow-style: none;
        }

        html,
        body {
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

        .strength-bar { height: 3px; border-radius: 2px; transition: all 0.3s ease; }
    </style>
</head>

<body class="font-sans antialiased min-h-screen flex flex-col hero-glow">

    <nav class="w-full px-8 py-6 flex justify-between items-center relative z-20">
        <a href="index.php" class="flex items-center gap-3 hover:opacity-80 transition-opacity">
            <div
                class="size-8 bg-primary/20 rounded-lg flex items-center justify-center border border-primary/30 overflow-hidden">
                <?php if ($branding && !empty($branding['logo_path'])): ?>
                    <img src="<?= $branding['logo_path'] ?>" class="size-full object-contain">
                <?php else: ?>
                    <img src="assests/horizon logo.png" alt="Horizon Logo" class="size-full object-contain rounded-lg">
                <?php endif; ?>
            </div>
            <h2 class="text-lg font-display font-bold text-white uppercase italic tracking-tighter">
                <?= $branding['gym_name'] ?? 'Horizon' ?> <span
                    class="text-primary"><?= $branding ? 'Portal' : 'System' ?></span>
            </h2>
        </a>

        <a href="forgot_password.php<?= isset($_GET['gym']) ? '?gym=' . htmlspecialchars($_GET['gym']) : '' ?>"
            class="text-[10px] font-display font-bold text-gray-500 hover:text-white transition-colors uppercase tracking-widest flex items-center gap-2">
            <span class="material-symbols-outlined text-sm">arrow_back</span>
            Back to Email Entry
        </a>
    </nav>

    <main class="flex-1 flex items-center justify-center p-6 relative z-10">
        <div class="dashboard-window w-full max-w-[440px] rounded-2xl p-10 md:p-12 relative overflow-hidden">
            <div
                class="absolute -top-24 -right-24 w-48 h-48 bg-primary/10 blur-[60px] rounded-full pointer-events-none">
            </div>

            <div class="relative z-10">
                <div class="text-center mb-6">
                    <div
                        class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/5 border border-white/10 text-primary text-[9px] font-black uppercase tracking-[0.2em] mb-4">
                        Update Security
                    </div>
                    <h1
                        class="text-3xl font-display font-black text-white uppercase italic tracking-tighter mb-2 whitespace-nowrap">
                        Reset <span class="text-primary">Password</span>
                    </h1>
                    <p class="text-[12px] text-gray-500 font-medium tracking-wide leading-relaxed">
                        Create a new, strong password for your account
                    </p>
                </div>

                <?php if (!empty($success)): ?>
                    <div id="success-alert"
                        class="mb-8 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-[11px] flex items-center gap-3 font-bold tracking-wide relative group animate-fade-in">
                        <span class="material-symbols-outlined text-lg">check_circle</span>
                        <span class="flex-1"><?= $success ?></span>
                    </div>
                <?php endif; ?>

                <?php if (!empty($error)): ?>
                    <div id="alert-message"
                        class="mb-8 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-[11px] flex items-center gap-3 font-bold tracking-wide relative group animate-fade-in">
                        <span class="material-symbols-outlined text-lg">error</span>
                        <span class="flex-1"><?= $error ?></span>
                        <button onclick="dismissAlert('alert-message')"
                            class="opacity-50 hover:opacity-100 transition-opacity">
                            <span class="material-symbols-outlined text-base">close</span>
                        </button>
                    </div>
                <?php endif; ?>

                <form action="action/process_password_reset.php" method="POST" class="space-y-4">
                    <?php if (isset($_GET['gym'])): ?>
                        <input type="hidden" name="gym" value="<?= htmlspecialchars($_GET['gym']) ?>">
                    <?php endif; ?>

                    <div class="space-y-2">
                        <label
                            class="text-[10px] font-display font-bold uppercase tracking-widest text-gray-500 ml-1">New
                            Password</label>
                        <div class="relative group input-gradient-focus rounded-xl transition-all">
                            <span
                                class="absolute left-4 top-1/2 -translate-y-1/2 material-symbols-outlined text-gray-600 group-focus-within:text-primary transition-colors text-xl">lock</span>
                            <input id="new-password"
                                class="flex h-14 w-full rounded-xl border border-white/5 bg-white/[0.02] pl-12 pr-14 text-sm text-white placeholder:text-gray-700 focus:outline-none transition-all"
                                name="password" placeholder="••••••••" required type="password" value="<?= htmlspecialchars($temp_password) ?>" />
                            <button type="button" onclick="togglePassword('new-password', 'eye-1')"
                                class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-600 hover:text-primary transition-colors">
                                <span id="eye-1" class="material-symbols-outlined text-[20px]">visibility</span>
                            </button>
                        </div>
                        <div class="mt-3 flex gap-1 h-1 px-1">
                            <div id="strength-bar-1" class="flex-1 rounded-full bg-white/5 transition-colors"></div>
                            <div id="strength-bar-2" class="flex-1 rounded-full bg-white/5 transition-colors"></div>
                            <div id="strength-bar-3" class="flex-1 rounded-full bg-white/5 transition-colors"></div>
                            <div id="strength-bar-4" class="flex-1 rounded-full bg-white/5 transition-colors"></div>
                        </div>
                        <p id="strength-text" class="text-[9px] font-bold uppercase tracking-widest text-gray-600 mt-2 ml-1">Strength: <span id="strength-label">None</span></p>

                        <!-- Password Requirements Checklist -->
                        <div class="grid grid-cols-2 gap-y-3 mt-5 px-1">
                            <div id="req-length" class="flex items-center gap-2 text-gray-600 transition-colors">
                                <span class="material-symbols-outlined text-sm transition-all">radio_button_unchecked</span>
                                <span class="text-[9px] font-bold uppercase tracking-widest">8+ Characters</span>
                            </div>
                            <div id="req-upper" class="flex items-center gap-2 text-gray-600 transition-colors">
                                <span class="material-symbols-outlined text-sm transition-all">radio_button_unchecked</span>
                                <span class="text-[9px] font-bold uppercase tracking-widest">Uppercase</span>
                            </div>
                            <div id="req-number" class="flex items-center gap-2 text-gray-600 transition-colors">
                                <span class="material-symbols-outlined text-sm transition-all">radio_button_unchecked</span>
                                <span class="text-[9px] font-bold uppercase tracking-widest">Number</span>
                            </div>
                            <div id="req-special" class="flex items-center gap-2 text-gray-600 transition-colors">
                                <span class="material-symbols-outlined text-sm transition-all">radio_button_unchecked</span>
                                <span class="text-[9px] font-bold uppercase tracking-widest">Special Char</span>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <label
                            class="text-[10px] font-display font-bold uppercase tracking-widest text-gray-500 ml-1">Confirm
                            Password</label>
                        <div class="relative group input-gradient-focus rounded-xl transition-all">
                            <span
                                class="absolute left-4 top-1/2 -translate-y-1/2 material-symbols-outlined text-gray-600 group-focus-within:text-primary transition-colors text-xl">lock_reset</span>
                            <input id="confirm-password"
                                class="flex h-14 w-full rounded-xl border border-white/5 bg-white/[0.02] pl-12 pr-14 text-sm text-white placeholder:text-gray-700 focus:outline-none transition-all"
                                name="confirm_password" placeholder="••••••••" required type="password" value="<?= htmlspecialchars($temp_password) ?>" />
                            <button type="button" onclick="togglePassword('confirm-password', 'eye-2')"
                                class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-600 hover:text-primary transition-colors">
                                <span id="eye-2" class="material-symbols-outlined text-[20px]">visibility</span>
                            </button>
                        </div>
                    </div>

                    <div class="pt-2">
                        <label class="flex items-center gap-3 cursor-pointer group">
                            <input type="checkbox" required class="size-4 rounded border-white/10 bg-white/5 text-primary focus:ring-primary focus:ring-offset-0 transition-all cursor-pointer">
                            <span class="text-[10px] text-gray-500 font-bold uppercase tracking-widest group-hover:text-gray-300 transition-colors">I confirm that I want to update my password</span>
                        </label>
                    </div>

                    <button
                        class="w-full h-14 mt-4 rounded-xl bg-primary hover:bg-primary-dark text-white font-display font-bold uppercase tracking-widest text-[11px] transition-all shadow-lg shadow-primary/20 flex items-center justify-center gap-3 hover:scale-[1.02] active:scale-[0.98]"
                        type="submit">
                        Update Password
                        <span class="material-symbols-outlined text-lg">security</span>
                    </button>
                </form>
            </div>
        </div>
    </main>

    <footer class="relative z-20 w-full py-6 text-center -mt-10">
        <p class="text-[9px] font-display font-bold text-gray-700 uppercase tracking-[0.4em]">
            © 2026 HORIZON SYSTEM. SECURE ENVIRONMENT.
        </p>
    </footer>

    <script>
        function togglePassword(inputId, eyeId) {
            const input = document.getElementById(inputId);
            const eye = document.getElementById(eyeId);
            if (input.type === 'password') {
                input.type = 'text';
                eye.textContent = 'visibility_off';
            } else {
                input.type = 'password';
                eye.textContent = 'visibility';
            }
        }

        function checkPasswordStrength(password) {
            const bars = [
                document.getElementById('strength-bar-1'),
                document.getElementById('strength-bar-2'),
                document.getElementById('strength-bar-3'),
                document.getElementById('strength-bar-4')
            ];
            const label = document.getElementById('strength-label');
            
            // Requirements indicators
            const reqs = {
                length: { el: document.getElementById('req-length'), check: password.length >= 8 },
                upper: { el: document.getElementById('req-upper'), check: /[A-Z]/.test(password) },
                number: { el: document.getElementById('req-number'), check: /[0-9]/.test(password) },
                special: { el: document.getElementById('req-special'), check: /[^A-Za-z0-9]/.test(password) }
            };

            let strength = 0;
            // Update requirements UI
            Object.keys(reqs).forEach(key => {
                const item = reqs[key];
                const icon = item.el.querySelector('.material-symbols-outlined');
                if (item.check) {
                    item.el.classList.remove('text-gray-600');
                    item.el.classList.add('text-primary');
                    icon.textContent = 'check_circle';
                    strength++;
                } else {
                    item.el.classList.add('text-gray-600');
                    item.el.classList.remove('text-primary');
                    icon.textContent = 'radio_button_unchecked';
                }
            });
            
            bars.forEach(bar => bar.className = 'flex-1 rounded-full bg-white/5 transition-colors');
            
            if (password.length === 0) {
                label.textContent = 'None';
                label.className = 'text-gray-600';
            } else {
                const colors = ['bg-red-500', 'bg-orange-500', 'bg-yellow-500', 'bg-emerald-500'];
                const labels = ['Weak', 'Fair', 'Good', 'Strong'];
                
                const displayStrength = Math.max(1, strength);
                for (let i = 0; i < displayStrength; i++) {
                    bars[i].className = 'flex-1 rounded-full ' + colors[displayStrength - 1];
                }
                label.textContent = labels[displayStrength - 1];
                label.className = colors[displayStrength - 1].replace('bg-', 'text-');
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

        window.onload = function () {
            // Setup password strength checking
            const passwordInput = document.getElementById('new-password');
            if (passwordInput) {
                passwordInput.addEventListener('input', (e) => checkPasswordStrength(e.target.value));
                // Initial check if value is pre-populated
                if (passwordInput.value) checkPasswordStrength(passwordInput.value);
            }

            // Auto-dismiss error alert after 10 seconds
            const alert = document.getElementById('alert-message');
            if (alert) {
                setTimeout(() => dismissAlert('alert-message'), 10000);
            }

            // Success redirection logic
            const successAlert = document.getElementById('success-alert');
            if (successAlert) {
                setTimeout(() => {
                    const gymParam = "<?= isset($_GET['gym']) ? '?gym=' . htmlspecialchars($_GET['gym']) : '' ?>";
                    window.location.href = "login.php" + gymParam;
                }, 3000); // Redirect after 3 seconds
            }
        };
    </script>

</body>

</html>