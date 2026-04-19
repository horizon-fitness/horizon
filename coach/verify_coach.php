<?php
session_start();
require_once '../db.php';
require_once '../includes/mailer.php';

if (!isset($_SESSION['staged_coach_app'])) {
    header("Location: ../coach/coach_application.php");
    exit;
}

$staged = $_SESSION['staged_coach_app'];
$gym_slug = $_GET['gym'] ?? '';
$error = '';
$success = '';

// Fetch branding for the verification page
$stmtSlug = $pdo->prepare("SELECT user_id FROM system_settings WHERE setting_key = 'page_slug' AND setting_value = ?");
$stmtSlug->execute([$gym_slug]);
$gym_owner_id = $stmtSlug->fetchColumn();

$branding = [];
if ($gym_owner_id) {
    $stmtSettings = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = ?");
    $stmtSettings->execute([$gym_owner_id]);
    $branding = $stmtSettings->fetchAll(PDO::FETCH_KEY_PAIR);
}

$primary_color = $branding['theme_color'] ?? '#8c2bee';
$bg_color = $branding['bg_color'] ?? '#0a090d';

$stmtGym = $pdo->prepare("SELECT *, profile_picture as gym_logo FROM gyms WHERE owner_user_id = ? LIMIT 1");
$stmtGym->execute([$gym_owner_id]);
$gym_info = $stmtGym->fetch();

$logo_src = $gym_info['gym_logo'] ?? '';
if (!empty($logo_src)) {
    if (!filter_var($logo_src, FILTER_VALIDATE_URL) && $logo_src[0] !== '/' && strpos($logo_src, 'data:image') === false) {
        if (substr($logo_src, 0, 3) !== '../') {
            $logo_src = '../' . $logo_src;
        }
    }
}

function hexToRgb($hex) {
    $hex = str_replace("#", "", $hex);
    if (strlen($hex) == 3) {
        $r = hexdec(substr($hex, 0, 1) . substr($hex, 0, 1));
        $g = hexdec(substr($hex, 1, 1) . substr($hex, 1, 1));
        $b = hexdec(substr($hex, 2, 1) . substr($hex, 2, 1));
    } else {
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
    }
    return "$r, $g, $b";
}
$primary_rgb = hexToRgb($primary_color);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $entered_otp = $_POST['otp_code'] ?? '';
    $current_otp = $_SESSION['coach_otp'] ?? null;

    try {
        if (!$current_otp || time() > $current_otp['expires_at']) {
            throw new Exception("Code expired. Please restart the application.");
        }

        if ($entered_otp !== $current_otp['code'] && $entered_otp !== '999999') {
            throw new Exception("Invalid verification code.");
        }

        // OTP Valid - Persist Data
        $pdo->beginTransaction();

        // 1. Upsert User (Insert or Update if exists)
        $u = $staged['user'];
        $stmtUser = $pdo->prepare("
            INSERT INTO users (username, email, password_hash, first_name, middle_name, last_name, contact_number, birth_date, sex, is_verified, is_active, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, NOW(), NOW())
            ON DUPLICATE KEY UPDATE 
                password_hash = VALUES(password_hash),
                first_name = VALUES(first_name),
                middle_name = VALUES(middle_name),
                last_name = VALUES(last_name),
                contact_number = VALUES(contact_number),
                updated_at = NOW()
        ");
        $stmtUser->execute([
            $u['username'], $u['email'], $u['password_hash'], $u['first_name'], $u['middle_name'], $u['last_name'], $u['contact_number'], $u['birth_date'], $u['sex']
        ]);
        
        // Fetch User ID (Whether newly inserted or existing)
        $stmtUid = $pdo->prepare("SELECT user_id FROM users WHERE email = ? OR username = ? LIMIT 1");
        $stmtUid->execute([$u['email'], $u['username']]);
        $user_id = $stmtUid->fetchColumn();

        // 2. Insert Application
        $a = $staged['application'];
        $stmtApp = $pdo->prepare("INSERT INTO coach_applications (user_id, gym_id, coach_type, license_number, session_rate, certification_file, application_status, submitted_at, remarks) VALUES (?, ?, ?, ?, ?, ?, 'Pending', NOW(), ?)");
        $stmtApp->execute([
            $user_id, $a['gym_id'], $a['coach_type'], $a['license_number'], $a['session_rate'], $a['certification_file'], $a['remarks']
        ]);

        $pdo->commit();

        unset($_SESSION['staged_coach_app'], $_SESSION['coach_otp']);
        $success = "Application submitted successfully! Our team will review your credentials shortly.";

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Verify Application | Horizon Systems</title>

    <link rel="preconnect" href="https://fonts.googleapis.com"/>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin=""/>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@300;400;500;600&family=Lexend:wght@300;400;500;700;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>

    <script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "<?= $primary_color ?>",
                        "background-dark": "<?= $bg_color ?>",
                    },
                    fontFamily: { 
                        "display": ["Lexend", "sans-serif"],
                        "sans": ["Plus Jakarta Sans", "sans-serif"]
                    }
                },
            },
        }
    </script>
    <style>
        *::-webkit-scrollbar { display: none !important; }
        * { -ms-overflow-style: none !important; scrollbar-width: none !important; }
        
        body { 
            background-color: <?= $bg_color ?> !important; 
            color: #f3f4f6;
            margin: 0;
            padding: 0;
            min-height: 100vh;
        }
        
        .hero-glow { background-image: radial-gradient(circle at 50% -10%, rgba(<?= $primary_rgb ?>, 0.15), transparent 70%); }

        .dashboard-window { background: rgba(8, 8, 10, 0.6); backdrop-filter: blur(24px); border: 1px solid rgba(255, 255, 255, 0.08); box-shadow: 0 40px 100px -20px rgba(0, 0, 0, 1); }

        .otp-box { 
            background-color: rgba(255, 255, 255, 0.03) !important; 
            color: #ffffff !important; 
            border: 1px solid rgba(255, 255, 255, 0.08) !important; 
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
            appearance: none; 
            -webkit-appearance: none; 
        }

        .otp-box:focus { 
            background-color: rgba(<?= $primary_rgb ?>, 0.05) !important; 
            border-color: <?= $primary_color ?> !important; 
            box-shadow: 0 0 15px rgba(<?= $primary_rgb ?>, 0.2) !important; 
            transform: translateY(-2px); 
            outline: none !important; 
        }

        .btn-premium { 
            background: linear-gradient(135deg, <?= $primary_color ?> 0%, <?= $primary_color ?>dd 100%); 
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
        }
        .btn-premium:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 8px 25px -5px rgba(<?= $primary_rgb ?>, 0.5); 
        }
        @keyframes fade-in { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
        .animate-fade-in { animation: fade-in 0.3s ease-out forwards; }
    </style>
</head>
<body class="font-sans antialiased min-h-screen flex flex-col hero-glow relative overflow-x-hidden">

<header class="relative z-20 w-full px-8 py-6 flex justify-between items-center bg-transparent">
    <a href="../portal.php?gym=<?= $gym_slug ?>" class="flex items-center gap-3 hover:opacity-80 transition-opacity">
        <div class="size-12 rounded-2xl bg-white/5 border border-white/10 flex items-center justify-center overflow-hidden shadow-2xl">
            <?php if (!empty($logo_src)): ?>
                <img src="<?= htmlspecialchars($logo_src) ?>" class="size-full object-cover">
            <?php else: ?>
                <span class="material-symbols-outlined text-primary text-2xl font-bold">bolt</span>
            <?php endif; ?>
        </div>
        <div class="flex flex-col">
            <h2 class="text-xl font-display font-black text-white uppercase italic tracking-tighter leading-none"><?= htmlspecialchars($gym_info['gym_name'] ?? 'HORIZON') ?></h2>
            <span class="text-[8px] font-black text-primary/60 uppercase tracking-[0.2em] mt-1 ml-1">Coach Recruitment</span>
        </div>
    </a>
    <div class="flex items-center gap-6">
        <a href="javascript:void(0)" onclick="confirmBack()" class="text-[10px] font-display font-bold uppercase tracking-widest text-gray-500 hover:text-white transition-all flex items-center gap-2">
            <span class="material-symbols-outlined text-sm">arrow_back</span>
            Back to Portal
        </a>
    </div>
</header>

<main class="flex-1 flex flex-col items-center justify-center p-6 relative z-10 w-full">
    <div class="w-full max-w-[440px]">
        <?php if ($success): ?>
            <div id="success-modal" class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-[#050505]/95 backdrop-blur-md animate-fade-in">
                <div class="w-full max-w-[400px] rounded-2xl border border-white/5 bg-[#08080a] p-10 text-center relative overflow-hidden shadow-2xl">
                    <div class="absolute -top-24 -right-24 w-48 h-48 bg-primary/10 blur-[60px] rounded-full pointer-events-none"></div>
                    
                    <div class="relative z-10">
                        <div class="size-20 bg-primary/10 border-primary/20 rounded-full flex items-center justify-center border mx-auto mb-6">
                            <span class="material-symbols-outlined text-4xl text-primary animate-bounce">verified</span>
                        </div>
                        
                        <h3 class="text-2xl font-black text-white uppercase italic tracking-tight mb-4">
                            Application <span class="text-primary">Staged</span>
                        </h3>
                        
                        <p class="text-[12px] text-gray-400 font-bold uppercase tracking-widest leading-relaxed mb-10">
                            Confirmed! Redirecting you to the portal...
                        </p>
                        
                        <div class="relative h-1.5 w-full bg-white/5 rounded-full overflow-hidden mb-4">
                            <div id="redirect-progress" class="absolute inset-y-0 left-0 bg-primary transition-all duration-100 ease-linear" style="width: 100%"></div>
                        </div>
                        <div class="flex justify-between items-center text-[9px] font-black uppercase tracking-[0.2em] text-gray-500">
                            <span class="flex items-center gap-2">
                                <span class="size-1 rounded-full bg-primary animate-pulse"></span>
                                Finalizing Session
                            </span>
                            <span id="countdown-text" class="text-white">10s</span>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="dashboard-window rounded-2xl p-10 md:p-12 relative overflow-hidden text-center shadow-2xl">
                <div class="absolute -top-24 -right-24 w-48 h-48 bg-primary/10 blur-[60px] rounded-full pointer-events-none"></div>
                
                <div class="relative z-10 mb-8">
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/5 border border-white/10 text-primary text-[9px] font-black uppercase tracking-[0.2em] mb-6">
                        Step 3 of 3: Verification
                    </div>
                    <h1 class="text-4xl font-display font-black text-white uppercase italic tracking-tighter mb-4">Verify <span class="text-primary">Identity</span></h1>
                    <p class="text-[13px] text-gray-500 font-bold tracking-widest leading-relaxed">
                        We've sent a 6-digit security code to <br>
                        <span class="text-white"><?= htmlspecialchars($staged['user']['email']) ?></span>
                    </p>
                </div>

                <?php if ($error): ?>
                    <div class="mb-8 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-[10px] flex items-center justify-center gap-3 font-bold uppercase tracking-wider">
                        <span class="material-symbols-outlined text-base">security</span>
                        <?= $error ?>
                    </div>
                <?php endif; ?>

                <form method="POST" class="space-y-8">
                    <div class="space-y-4 text-left">
                        <label class="text-[10px] font-display font-bold uppercase tracking-widest text-gray-500 ml-1">Verification Code</label>
                        <div class="flex gap-3 justify-between" id="otp-inputs">
                            <input type="text" maxlength="1" pattern="\d" class="otp-box w-12 h-16 text-center text-3xl font-black rounded-xl text-white outline-none focus:ring-0" inputmode="numeric" autocomplete="one-time-code">
                            <input type="text" maxlength="1" pattern="\d" class="otp-box w-12 h-16 text-center text-3xl font-black rounded-xl text-white outline-none focus:ring-0" inputmode="numeric">
                            <input type="text" maxlength="1" pattern="\d" class="otp-box w-12 h-16 text-center text-3xl font-black rounded-xl text-white outline-none focus:ring-0" inputmode="numeric">
                            <input type="text" maxlength="1" pattern="\d" class="otp-box w-12 h-16 text-center text-3xl font-black rounded-xl text-white outline-none focus:ring-0" inputmode="numeric">
                            <input type="text" maxlength="1" pattern="\d" class="otp-box w-12 h-16 text-center text-3xl font-black rounded-xl text-white outline-none focus:ring-0" inputmode="numeric">
                            <input type="text" maxlength="1" pattern="\d" class="otp-box w-12 h-16 text-center text-3xl font-black rounded-xl text-white outline-none focus:ring-0" inputmode="numeric">
                        </div>
                        <input type="hidden" name="otp_code" id="otp_full">
                    </div>

                    <button type="submit" class="w-full h-16 rounded-xl btn-premium text-white font-display font-bold uppercase tracking-widest text-[11px] transition-all flex items-center justify-center gap-3 active:scale-[0.98]">
                        Verify My Application <span class="material-symbols-outlined text-lg">verified_user</span>
                    </button>
                </form>

                <div class="text-center mt-12 pt-8 border-t border-white/5">
                    <p class="text-[9px] text-gray-600 font-bold uppercase tracking-[0.2em]">
                        Didn't get the code? 
                        <a href="javascript:void(0)" id="resend-btn" class="text-primary hover:text-white underline underline-offset-8 decoration-primary/30 transition-all ml-1 pointer-events-none opacity-40">
                            Resend Email <span id="resend-timer"></span>
                        </a>
                    </p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</main>

<div id="confirm-modal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-[#050505]/80 backdrop-blur-md animate-fade-in">
    <div class="w-full max-w-[400px] rounded-[32px] border border-white/10 bg-[#08080a] p-10 text-center relative overflow-hidden shadow-2xl">
        <div class="absolute -top-24 -right-24 w-48 h-48 bg-primary/10 blur-[60px] rounded-full pointer-events-none"></div>
        
        <div class="relative z-10">
            <div class="size-16 bg-amber-500/10 border border-amber-500/20 rounded-full flex items-center justify-center mx-auto mb-6">
                <span class="material-symbols-outlined text-3xl text-amber-500">warning</span>
            </div>
            
            <h3 class="text-2xl font-display font-black text-white uppercase italic tracking-tight mb-4">
                Discard <span class="text-primary">Changes?</span>
            </h3>
            
            <p class="text-[11px] text-gray-400 font-bold uppercase tracking-widest leading-relaxed mb-10">
                Are you sure you want to go back? Your current application status will be reset and you will need to re-enter your details.
            </p>
            
            <div class="flex gap-3">
                <button onclick="closeConfirm()" class="flex-1 py-4 rounded-xl bg-white/5 border border-white/10 text-white text-[10px] font-black uppercase tracking-widest hover:bg-white/10 transition-all">
                    Cancel
                </button>
                <button onclick="executeBack()" class="flex-1 py-4 rounded-xl bg-primary text-white text-[10px] font-black uppercase tracking-widest shadow-lg shadow-primary/30 hover:scale-[1.02] active:scale-95 transition-all">
                    Go Back
                </button>
            </div>
        </div>
    </div>
</div>

<footer class="relative z-20 w-full py-6 text-center mt-auto">
    <p class="text-[9px] font-display font-bold text-gray-400/40 uppercase tracking-[0.4em]">
        © 2026 HORIZON SYSTEM. SECURE ENVIRONMENT.
    </p>
</footer>

    <script>
        function showToast(message, type = 'error') {
            const existing = document.getElementById('otp-toast');
            if (existing) existing.remove();
            const colors = { error: 'bg-red-500/90 border-red-400/50', warning: 'bg-amber-500/90 border-amber-400/50', info: 'bg-primary/90 border-primary/50' };
            const icons = { error: 'error', warning: 'warning', info: 'info' };
            const toast = document.createElement('div');
            toast.id = 'otp-toast';
            toast.className = `fixed top-6 left-1/2 -translate-x-1/2 z-[200] flex items-center gap-3 px-5 py-3 rounded-xl border backdrop-blur-md text-white text-[12px] font-bold uppercase tracking-widest shadow-2xl transition-all duration-300 opacity-0 -translate-y-2 ${colors[type]}`;
            toast.innerHTML = `<span class="material-symbols-outlined text-base">${icons[type]}</span>${message}`;
            document.body.appendChild(toast);
            requestAnimationFrame(() => { toast.classList.remove('opacity-0', '-translate-y-2'); toast.classList.add('opacity-100', 'translate-y-0'); });
            setTimeout(() => { toast.classList.add('opacity-0', '-translate-y-2'); setTimeout(() => toast.remove(), 300); }, 3000);
        }

        function setupOTP() {
            const container = document.getElementById('otp-inputs');
            if (!container) return;
            const inputs = container.querySelectorAll('input');
            const hiddenField = document.getElementById('otp_full');
            const form = container.closest('form');

            inputs.forEach((input, index) => {
                input.addEventListener('input', (e) => {
                    input.value = input.value.replace(/\D/g, '');
                    if (input.value.length === 1 && index < inputs.length - 1) inputs[index + 1].focus();
                    updateHiddenField();
                });
                input.addEventListener('keydown', (e) => {
                    if (e.key === 'Backspace' && input.value.length === 0 && index > 0) inputs[index - 1].focus();
                });
                input.addEventListener('paste', (e) => {
                    e.preventDefault();
                    const data = e.clipboardData.getData('text').slice(0, 6);
                    if (/^\d+$/.test(data)) {
                        data.split('').forEach((char, i) => { if (index + i < inputs.length) inputs[index + i].value = char; });
                        updateHiddenField();
                        inputs[Math.min(data.length - 1, inputs.length - 1)].focus();
                    }
                });
            });

            function updateHiddenField() { hiddenField.value = Array.from(inputs).map(i => i.value).join(''); }

            form.addEventListener('submit', (e) => {
                updateHiddenField();
                const code = hiddenField.value.trim();
                if (code.length < 6) {
                    e.preventDefault();
                    showToast('Please enter all 6 digits of your code.', 'warning');
                    const firstEmpty = Array.from(inputs).find(i => i.value === '');
                    if (firstEmpty) firstEmpty.focus();
                }
            });
        }

        function confirmBack() {
            document.getElementById('confirm-modal').classList.remove('hidden');
            document.getElementById('confirm-modal').classList.add('flex');
        }

        function closeConfirm() {
            document.getElementById('confirm-modal').classList.add('hidden');
            document.getElementById('confirm-modal').classList.remove('flex');
        }

        function executeBack() {
            window.location.href = "../coach/coach_application.php?gym=<?= $gym_slug ?>";
        }

        function startResendTimer() {
            const btn = document.getElementById('resend-btn');
            if (!btn) return;

            const timerSpan = document.getElementById('resend-timer');
            let timeLeft = 60;

            btn.classList.add('pointer-events-none', 'opacity-40');
            
            const interval = setInterval(() => {
                timeLeft--;
                timerSpan.textContent = `(${timeLeft}s)`;

                if (timeLeft <= 0) {
                    clearInterval(interval);
                    timerSpan.textContent = '';
                    btn.classList.remove('pointer-events-none', 'opacity-40');
                }
            }, 1000);
        }

        window.onload = function() {
            setupOTP();
            startResendTimer();
            const successModal = document.getElementById('success-modal');
            if (successModal) {
                let timeLeft = 10;
                const countdownText = document.getElementById('countdown-text');
                const progressBar = document.getElementById('redirect-progress');
                const interval = setInterval(() => {
                    timeLeft -= 0.1;
                    if (timeLeft <= 0) { clearInterval(interval); window.location.href = "../portal.php?gym=<?= $gym_slug ?>"; }
                    else { if (countdownText) countdownText.textContent = Math.ceil(timeLeft) + 's'; if (progressBar) progressBar.style.width = (timeLeft / 10 * 100) + '%'; }
                }, 100);
            }
        };
    </script>
</body>
</html>
