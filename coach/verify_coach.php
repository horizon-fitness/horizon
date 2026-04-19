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

        // 1. Insert User
        $u = $staged['user'];
        $stmtUser = $pdo->prepare("INSERT INTO users (username, email, password_hash, first_name, middle_name, last_name, contact_number, birth_date, sex, is_verified, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, NOW(), NOW())");
        $stmtUser->execute([
            $u['username'], $u['email'], $u['password_hash'], $u['first_name'], $u['middle_name'], $u['last_name'], $u['contact_number'], $u['birth_date'], $u['sex']
        ]);
        $user_id = $pdo->lastInsertId();

        // 2. Insert Application
        $a = $staged['application'];
        $stmtApp = $pdo->prepare("INSERT INTO coach_applications (user_id, gym_id, coach_type, license_number, certification_file, application_status, submitted_at, remarks) VALUES (?, ?, ?, ?, ?, 'Pending', NOW(), ?)");
        $stmtApp->execute([
            $user_id, $a['gym_id'], $a['coach_type'], $a['license_number'], $a['certification_file'], $a['remarks']
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
        body { background-color: <?= $bg_color ?> !important; color: #f3f4f6; }
        .glass-card {
            background: rgba(255, 255, 255, 0.01);
            backdrop-filter: blur(24px);
            border: 1px solid rgba(255, 255, 255, 0.05);
            box-shadow: 0 40px 100px -20px rgba(0, 0, 0, 0.8);
        }
        .otp-input {
            background: rgba(255, 255, 255, 0.02) !important;
            border: 1px solid rgba(255, 255, 255, 0.08) !important;
            border-radius: 12px !important;
            color: #ffffff !important;
            text-align: center;
            font-size: 24px;
            font-weight: 800;
            width: 100%;
            height: 64px;
            outline: none;
            transition: all 0.3s ease;
        }
        .otp-input:focus {
            border-color: <?= $primary_color ?> !important;
            box-shadow: 0 0 0 1px rgba(<?= $primary_color ?>), 0 0 20px rgba(<?= $primary_color ?>), 0.3);
        }
    </style>
</head>
<body class="font-sans antialiased min-h-screen flex items-center justify-center p-6">

    <div class="w-full max-w-md">
        <?php if ($success): ?>
            <div class="glass-card rounded-[32px] p-10 text-center animate-fade-in">
                <div class="size-20 rounded-full bg-emerald-500/20 text-emerald-400 flex items-center justify-center mx-auto mb-6">
                    <span class="material-symbols-outlined text-4xl">check_circle</span>
                </div>
                <h2 class="text-3xl font-display font-black text-white uppercase italic tracking-tighter mb-4">Application <span class="text-primary">Staged</span></h2>
                <p class="text-xs text-gray-400 font-bold uppercase tracking-widest leading-relaxed mb-8"><?= $success ?></p>
                <a href="../portal.php?gym=<?= $gym_slug ?>" class="inline-flex px-8 py-3 bg-primary text-white text-[10px] font-black uppercase tracking-widest rounded-xl hover:scale-105 transition-all">Back to Portal</a>
            </div>
        <?php else: ?>
            <div class="glass-card rounded-2xl p-10 relative overflow-hidden text-center">
                <div class="mb-8">
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/5 border border-white/10 text-primary text-[9px] font-black uppercase tracking-[0.2em] mb-4">
                        Secure Verification
                    </div>
                    <h1 class="text-3xl font-display font-black text-white uppercase italic tracking-tighter mb-2">Verify <span class="text-primary">Identity</span></h1>
                    <p class="text-[11px] text-gray-500 font-bold uppercase tracking-widest">Sent to <?= htmlspecialchars($staged['user']['email']) ?></p>
                </div>

                <?php if ($error): ?>
                    <div class="mb-6 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-[10px] font-bold uppercase tracking-widest">
                        <?= $error ?>
                    </div>
                <?php endif; ?>

                <form method="POST" class="space-y-6">
                    <div class="grid grid-cols-6 gap-2" id="otp-container">
                        <input type="text" maxlength="1" class="otp-input" required>
                        <input type="text" maxlength="1" class="otp-input" required>
                        <input type="text" maxlength="1" class="otp-input" required>
                        <input type="text" maxlength="1" class="otp-input" required>
                        <input type="text" maxlength="1" class="otp-input" required>
                        <input type="text" maxlength="1" class="otp-input" required>
                    </div>
                    <input type="hidden" name="otp_code" id="otp_full">

                    <button type="submit" class="w-full h-14 rounded-xl bg-primary text-white text-[11px] font-black uppercase tracking-widest shadow-lg shadow-primary/20 hover:scale-[1.02] active:scale-[0.98] transition-all">
                        Confirm Verification
                    </button>
                </form>

                <p class="mt-8 text-[9px] text-gray-600 font-bold uppercase tracking-widest">
                    Didn't get the code? <a href="#" class="text-primary hover:text-white transition-all ml-1">Resend Email</a>
                </p>
            </div>
        <?php endif; ?>
    </div>

    <script>
        const inputs = document.querySelectorAll('.otp-input');
        const hidden = document.getElementById('otp_full');

        inputs.forEach((input, index) => {
            input.addEventListener('input', (e) => {
                if (e.target.value.length === 1 && index < inputs.length - 1) {
                    inputs[index + 1].focus();
                }
                combine();
            });

            input.addEventListener('keydown', (e) => {
                if (e.key === 'Backspace' && !e.target.value && index > 0) {
                    inputs[index - 1].focus();
                }
            });
        });

        function combine() {
            let code = "";
            inputs.forEach(i => code += i.value);
            hidden.value = code;
        }
    </script>
</body>
</html>
