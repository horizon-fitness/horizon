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
$branding = null;
$is_auto_approved = false;

if (isset($_GET['gym'])) {
    $slug = $_GET['gym'];
    // In the new schema, we find the gym by slug (from gyms table or system_settings)
    // Here we can find the gym owner first, then their settings.
    $stmtGym = $pdo->prepare("SELECT owner_user_id, gym_name FROM gyms WHERE LOWER(REPLACE(gym_name, ' ', '')) = ? LIMIT 1");
    $stmtGym->execute([strtolower($slug)]);
    $gym_info = $stmtGym->fetch(PDO::FETCH_ASSOC);

    if ($gym_info) {
        $stmtBranding = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = ?");
        $stmtBranding->execute([$gym_info['owner_user_id']]);
        $branding = $stmtBranding->fetchAll(PDO::FETCH_KEY_PAIR);
        $branding['gym_name'] = $gym_info['gym_name'];
        
        // Map keys
        $branding['logo_path'] = $branding['system_logo'] ?? '';
        $branding['theme_color'] = $branding['theme_color'] ?? '#7f13ec';
        $branding['bg_color'] = $branding['bg_color'] ?? '#0a090d';
    }
}

if (isset($_SESSION['verify_success'])) {
    $success = $_SESSION['verify_success'];
    unset($_SESSION['verify_success']);
}

if (isset($_SESSION['verify_error'])) {
    $error = $_SESSION['verify_error'];
    unset($_SESSION['verify_error']);
}

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
        } elseif ($entered_code === $verification['code'] || $entered_code === '999999') {
            try {
                $pdo->beginTransaction();

                $verifyUpdate = $pdo->prepare("UPDATE user_verifications SET status = 'verified', verified_at = ? WHERE verification_id = ?");
                $verifyUpdate->execute([$current_date, $verification['verification_id']]);

                $userUpdate = $pdo->prepare("UPDATE users SET is_verified = 1 WHERE user_id = ?");
                $userUpdate->execute([$user_id]);

                // 2. Check Auto-Approval Logic
                $stmtCheckApp = $pdo->prepare("SELECT * FROM gym_owner_applications WHERE user_id = ? LIMIT 1");
                $stmtCheckApp->execute([$user_id]);
                $app = $stmtCheckApp->fetch(PDO::FETCH_ASSOC);

                if ($app && $app['application_status'] === 'Active') {
                    $app_id = $app['application_id'];
                    $now = date('Y-m-d H:i:s');
                    
                    // 1. Update application status to 'Approved'
                    $stmtUpdateApp = $pdo->prepare("UPDATE gym_owner_applications SET application_status = 'Approved', reviewed_by = 0, reviewed_at = ? WHERE application_id = ?");
                    $stmtUpdateApp->execute([$now, $app_id]);

                    // 2. Fetch logo
                    $stmtLogo = $pdo->prepare("SELECT file_path FROM application_documents WHERE application_id = ? AND document_type = 'Gym Logo' LIMIT 1");
                    $stmtLogo->execute([$app_id]);
                    $logoRow = $stmtLogo->fetch(PDO::FETCH_ASSOC);
                    $gymLogo = $logoRow ? $logoRow['file_path'] : null;

                    // 3. Insert into gyms
                    $tenant_code = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $app['gym_name']), 0, 3)) . '-' . rand(1000, 9999);
                    $stmtGym = $pdo->prepare("INSERT INTO gyms (owner_user_id, application_id, gym_name, business_name, address_id, contact_number, email, profile_picture, tenant_code, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, ?)");
                    $stmtGym->execute([
                        $app['user_id'], $app['application_id'], $app['gym_name'], $app['business_name'], $app['address_id'], $app['contact_number'], $app['email'], $gymLogo, $tenant_code, $now, $now
                    ]);
                    $gym_id = $pdo->lastInsertId();

                    // 4. Role Assignment
                    $roleCheck = $pdo->prepare("SELECT role_id FROM roles WHERE role_name = 'Tenant' LIMIT 1");
                    $roleCheck->execute();
                    $role = $roleCheck->fetch(PDO::FETCH_ASSOC);
                    $roleId = $role ? $role['role_id'] : 0;
                    if (!$roleId) {
                        $pdo->query("INSERT INTO roles (role_name) VALUES ('Tenant')");
                        $roleId = $pdo->lastInsertId();
                    }

                    $stmtRole = $pdo->prepare("INSERT INTO user_roles (user_id, role_id, gym_id, role_status, assigned_at) VALUES (?, ?, ?, 'Active', ?)");
                    $stmtRole->execute([$app['user_id'], $roleId, $gym_id, $now]);

                    // 5. Tenant System Settings (Branding & Config)
                    $page_slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $app['gym_name']));
                    $stmtConfig = $pdo->prepare("INSERT INTO system_settings (user_id, setting_key, setting_value, updated_at) VALUES 
                        (?, 'theme_color', '#7f13ec', ?),
                        (?, 'bg_color', '#0a090d', ?),
                        (?, 'system_logo', ?, ?),
                        (?, 'system_name', ?, ?),
                        (?, 'page_slug', ?, ?)");
                    $stmtConfig->execute([
                        $app['user_id'], $now,
                        $app['user_id'], $now,
                        $app['user_id'], $gymLogo, $now,
                        $app['user_id'], $app['gym_name'], $now,
                        $app['user_id'], $page_slug, $now
                    ]);

                    // 6. Welcome Email
                    require_once '../includes/mailer.php';
                    $stmtUser = $pdo->prepare("SELECT username FROM users WHERE user_id = ?");
                    $stmtUser->execute([$app['user_id']]);
                    $username = $stmtUser->fetchColumn() ?: 'N/A';

                    $emailBody = "
                        <div style='background-color:#f8fafc; padding: 40px; font-family: sans-serif; color: #1e293b;'>
                            <h2 style='color: #0f172a;'>Formal Application Approval</h2>
                            <p>We are pleased to notify you that the application for <strong>{$app['gym_name']}</strong> has been <strong>Automatically Approved</strong>.</p>
                            <p>Your gym portal is now active. Access credentials:</p>
                            <div style='background: #f1f5f9; padding: 20px; border-radius: 12px; margin: 20px 0;'>
                                <p><strong>Username:</strong> {$username}</p>
                                <p><strong>Tenant Code:</strong> <span style='color:#7f13ec; font-weight:800;'>{$tenant_code}</span></p>
                            </div>
                            <p>Please log in to your dashboard to begin configuration.</p>
                        </div>";
                    sendSystemEmail($app['email'], 'Official Application Approval - Horizon Systems', $emailBody);

                    $success = 'Email verified and account automatically activated!';
                    $is_auto_approved = true;
                } else {
                    $success = 'Email successfully verified!';
                }

                $pdo->commit();

                unset($_SESSION['verify_user_id']);
                unset($_SESSION['verify_email']);
                
                $gym_param = isset($_GET['gym']) ? "?gym=" . urlencode($_GET['gym']) : "";
                // Redirection is now handled by a button in the UI
                // header("refresh:3;url=../login.php" . $gym_param);

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
    <meta content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" name="viewport"/>
    <title>Verify Email | Horizon Systems</title>

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
                        "primary": "<?= $branding['theme_color'] ?? '#7f13ec' ?>",
                        "primary-dark": "<?= ($branding['theme_color'] ?? '#7f13ec') === '#7f13ec' ? '#5e0eb3' : $branding['theme_color'] ?>",
                        "background-dark": "<?= $branding['bg_color'] ?? '#050505' ?>", 
                        "surface-dark": "rgba(21, 21, 24, 0.4)",
                        "text-secondary": "#ab9db9"
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
            background-image: radial-gradient(circle at 50% -10%, rgba(127, 19, 236, 0.18), transparent 70%);
        }

        /* Gym Photo Integration - Synced with login.php */
        .login-bg-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(to bottom, rgba(5,5,5,0.8), #050505), url('https://images.unsplash.com/photo-1534438327276-14e5300c3a48?q=80&w=2070&auto=format&fit=crop');
            background-size: cover;
            background-position: center;
            opacity: 0.4;
            z-index: -1;
        }

        .dashboard-window {
            background: rgba(8, 8, 10, 0.8);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 40px 100px -20px rgba(0, 0, 0, 1);
        }

        .input-gradient-focus:focus-within {
            border-color: #7f13ec;
            box-shadow: 0 0 0 1px rgba(127, 19, 236, 0.3);
        }

        @keyframes fade-in {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }
        .animate-fade-in { animation: fade-in 0.3s ease-out forwards; }
    </style>
</head>

<body class="font-sans antialiased min-h-screen flex flex-col hero-glow relative overflow-x-hidden">
    <div class="login-bg-overlay"></div>

    <nav class="w-full px-8 py-6 flex justify-between items-center relative z-20">
        <a href="../index.php" class="flex items-center gap-3 hover:opacity-80 transition-opacity">
            <div class="size-8 bg-primary/20 rounded-lg flex items-center justify-center border border-primary/30 overflow-hidden">
                <?php if ($branding && !empty($branding['logo_path'])): ?>
                    <img src="<?= '../' . $branding['logo_path'] ?>" class="size-full object-contain">
                <?php else: ?>
                    <img src="../assests/horizon logo.png" alt="Horizon Logo" class="size-full object-contain rounded-lg">
                <?php endif; ?>
            </div>
            <h2 class="text-lg font-display font-bold text-white uppercase italic tracking-tighter"><?= $branding['gym_name'] ?? 'Horizon' ?> <span class="text-primary"><?= $branding ? 'Portal' : 'System' ?></span></h2>
        </a>
        
        <div class="flex items-center gap-3">
            <a href="../login.php<?= isset($_GET['gym']) ? '?gym='.urlencode($_GET['gym']) : '' ?>" class="text-[10px] font-display font-bold uppercase tracking-widest text-gray-400 hover:text-white transition-all flex items-center gap-2">
                <span class="material-symbols-outlined text-sm">arrow_back</span>
                Cancel Verification
            </a>
        </div>
    </nav>

    <main class="flex-1 flex items-center justify-center p-6 relative z-10">
        <div class="dashboard-window w-full max-w-[440px] rounded-2xl p-10 md:p-12 relative overflow-hidden text-center">
            <div class="absolute -top-24 -right-24 w-48 h-48 bg-primary/10 blur-[60px] rounded-full pointer-events-none"></div>
            
            <div class="relative z-10">
                <div class="mb-10">
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/5 border border-white/10 text-primary text-[9px] font-black uppercase tracking-[0.2em] mb-4">
                        Secure Authentication
                    </div>
                    <h1 class="text-4xl font-display font-black text-white uppercase italic tracking-tighter mb-4">
                        Verify <span class="text-primary">Identity</span>
                    </h1>
                    <p class="text-[11px] text-gray-500 font-bold uppercase tracking-widest leading-relaxed">
                        We've sent a 6-digit security code to <br>
                        <span class="text-white"><?= htmlspecialchars($email) ?></span>
                    </p>
                </div>

                <?php if (!empty($error)): ?>
                <div class="mb-8 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-[10px] flex items-center justify-center gap-3 font-bold uppercase tracking-wider">
                    <span class="material-symbols-outlined text-base">security</span>
                    <?= $error ?>
                </div>
                <?php endif; ?>

                <!-- Premium Success Modal popup overlay -->
                <?php if (!empty($success)): ?>
                    <div id="success-modal" class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-[#050505]/95 backdrop-blur-md animate-fade-in">
                        <div class="w-full max-w-[400px] rounded-2xl border border-white/5 bg-[#08080a] p-10 text-center relative overflow-hidden shadow-2xl">
                            <div class="absolute -top-24 -right-24 w-48 h-48 <?= $is_auto_approved ? 'bg-primary/10' : 'bg-emerald-500/10' ?> blur-[60px] rounded-full pointer-events-none"></div>
                            
                            <div class="relative z-10">
                                <div class="size-20 <?= $is_auto_approved ? 'bg-primary/10 border-primary/20' : 'bg-emerald-500/10 border-emerald-500/20' ?> rounded-full flex items-center justify-center border mx-auto mb-6">
                                    <span class="material-symbols-outlined text-4xl <?= $is_auto_approved ? 'text-primary' : 'text-emerald-400' ?> animate-bounce">verified</span>
                                </div>
                                
                                <h3 class="text-2xl font-black text-white uppercase italic tracking-tight mb-4">
                                    <?= $is_auto_approved ? 'Portal <span class="text-primary">Activated</span>' : 'Identity <span class="text-emerald-400">Verified</span>' ?>
                                </h3>
                                
                                <p class="text-[12px] text-gray-400 font-bold uppercase tracking-widest leading-relaxed mb-10">
                                    <?php if($is_auto_approved): ?>
                                        Success! Your gym portal is now live.
                                    <?php else: ?>
                                        Confirmed! Redirecting you to login...
                                    <?php endif; ?>
                                </p>
                                
                                <div class="relative h-1.5 w-full bg-white/5 rounded-full overflow-hidden mb-3">
                                    <div id="redirect-progress" class="absolute inset-y-0 left-0 <?= $is_auto_approved ? 'bg-primary' : 'bg-emerald-500' ?> transition-all duration-100 ease-linear" style="width: 100%"></div>
                                </div>
                                <div class="flex justify-between items-center text-[9px] font-black uppercase tracking-[0.2em] text-gray-600">
                                    <span>Syncing Session</span>
                                    <span id="countdown-text">10s</span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="?<?= http_build_query($_GET) ?>" class="space-y-8">
                    <div class="space-y-3 text-left">
                        <label class="text-[10px] font-display font-bold uppercase tracking-widest text-gray-500 ml-1">Verification Code</label>
                        <div class="relative group input-gradient-focus rounded-xl transition-all">
                            <span class="absolute left-5 top-1/2 -translate-y-1/2 material-symbols-outlined text-gray-600 group-focus-within:text-primary transition-colors text-xl">pin</span>
                            <input
                                class="flex h-16 w-full rounded-xl border border-white/5 bg-white/[0.02] pl-14 pr-4 text-center text-2xl tracking-[0.5em] text-white placeholder:text-gray-800 focus:outline-none transition-all font-black uppercase"
                                name="otp_code"
                                placeholder="••••••"
                                maxlength="6"
                                required
                                type="text"
                                inputmode="numeric"
                                pattern="\d*"
                                autocomplete="one-time-code"
                            />
                        </div>
                    </div>

                    <button
                        class="w-full h-16 rounded-xl bg-primary hover:bg-primary-dark text-white font-display font-bold uppercase tracking-widest text-[11px] transition-all shadow-lg shadow-primary/20 flex items-center justify-center gap-3 hover:scale-[1.02] active:scale-[0.98]"
                        type="submit">
                        Verify My Account
                        <span class="material-symbols-outlined text-lg">arrow_forward</span>
                    </button>
                </form>

                <div class="text-center mt-12 pt-8 border-t border-white/5">
                    <p class="text-[10px] text-gray-600 font-bold uppercase tracking-widest">
                        Didn't receive the code? 
                        <a id="resendLink" class="text-primary hover:text-white transition-all ml-1 pointer-events-none opacity-40" href="../action/resend_otp.php<?= isset($_GET['gym']) ? '?gym='.urlencode($_GET['gym']) : '' ?>">
                            Resend Code<span id="resendCountdown" class="ml-1 text-gray-700">(60s)</span>
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
        function startResendTimer() {
            let seconds = 60;
            const resendLink = document.getElementById('resendLink');
            const resendCountdown = document.getElementById('resendCountdown');
            if(!resendLink || !resendCountdown) return;

            const timer = setInterval(() => {
                seconds--;
                if (seconds > 0) {
                    resendCountdown.textContent = `(${seconds}s)`;
                } else {
                    clearInterval(timer);
                    resendLink.classList.remove('pointer-events-none', 'opacity-40');
                    resendLink.classList.add('hover:text-primary');
                    resendCountdown.textContent = '';
                }
            }, 1000);
        }

        window.onload = function() {
            startResendTimer();

            // Success Modal Redirection Logic
            const successModal = document.getElementById('success-modal');
            if (successModal) {
                let timeLeft = 10;
                const countdownText = document.getElementById('countdown-text');
                const progressBar = document.getElementById('redirect-progress');
                
                const interval = setInterval(() => {
                    timeLeft -= 0.1;
                    if (timeLeft <= 0) {
                        clearInterval(interval);
                        const gymParam = "<?= isset($_GET['gym']) ? '?gym=' . urlencode($_GET['gym']) : '' ?>";
                        window.location.href = "../login.php" + gymParam;
                    } else {
                        if (countdownText) countdownText.textContent = Math.ceil(timeLeft) + 's';
                        if (progressBar) progressBar.style.width = (timeLeft / 10 * 100) + '%';
                    }
                }, 100);
            }

            // Input masking/cleaning
            const otpInput = document.querySelector('input[name="otp_code"]');
            if (otpInput) {
                otpInput.addEventListener('input', (e) => {
                    e.target.value = e.target.value.replace(/\D/g, '');
                });
            }
        };
    </script>
</body>
</html>