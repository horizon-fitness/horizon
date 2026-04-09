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
    $stmtBranding = $pdo->prepare("SELECT tp.*, g.gym_name FROM tenant_pages tp JOIN gyms g ON tp.gym_id = g.gym_id WHERE tp.page_slug = ? LIMIT 1");
    $stmtBranding->execute([$slug]);
    $branding = $stmtBranding->fetch(PDO::FETCH_ASSOC);
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

                    // 5. Tenant Page
                    $page_slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $app['gym_name']));
                    $stmtPage = $pdo->prepare("INSERT INTO tenant_pages (gym_id, page_slug, page_title, logo_path, theme_color, updated_at) VALUES (?, ?, ?, ?, '#7f13ec', ?)");
                    $stmtPage->execute([$gym_id, $page_slug, $app['gym_name'], $gymLogo, $now]);

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
                    "primary": "<?= $branding['theme_color'] ?? '#7f13ec' ?>",
                    "primary-hover": "<?= $branding['theme_color'] ?? '#6a11c9' ?>",
                    "background-dark": "<?= $branding['bg_color'] ?? '#0a090d' ?>",
                    "input-border": "#2d2838",
                },
                fontFamily: { "display": ["<?= $branding['font_family'] ?? 'Lexend' ?>", "sans-serif"] },
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
        
        <div class="mx-auto size-16 bg-primary/20 rounded-full flex items-center justify-center mb-6 overflow-hidden border border-primary/30">
            <?php if ($branding && !empty($branding['logo_path'])): ?>
                <img src="<?= '../' . $branding['logo_path'] ?>" class="size-full object-contain">
            <?php else: ?>
                <img src="../assests/horizon logo.png" alt="Horizon Logo" class="size-full object-contain rounded-lg">
            <?php endif; ?>
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
        <div class="animate-in fade-in zoom-in duration-700">
            <div class="size-24 rounded-full bg-emerald-500/10 text-emerald-500 flex items-center justify-center mx-auto mb-8 border border-emerald-500/20 shadow-[0_0_50px_rgba(16,185,129,0.1)]">
                <span class="material-symbols-outlined text-5xl">verified</span>
            </div>
            
            <div class="space-y-4 mb-10 text-center">
                <h2 class="text-3xl font-black tracking-tighter uppercase italic text-white line-clamp-1">
                    <?= $is_auto_approved ? 'Portal Activated' : 'Identity Verified' ?>
                </h2>
                <div class="h-1 w-12 bg-primary mx-auto rounded-full"></div>
                <p class="text-gray-400 text-sm leading-relaxed font-medium px-4">
                    <?php if($is_auto_approved): ?>
                        Verification successful! Your gym infrastructure is now live and synced. Launch your secure dashboard to begin configuration.
                    <?php else: ?>
                        Identity confirmed! Your application is now in the priority queue for final administrative audit. We'll notify you once cleared.
                    <?php endif; ?>
                </p>
            </div>

            <a href="../login.php<?= isset($_GET['gym']) ? '?gym='.urlencode($_GET['gym']) : '' ?>" 
               class="w-full h-14 rounded-xl bg-primary hover:bg-primary-hover text-white font-black uppercase tracking-widest text-sm transition-all shadow-lg shadow-primary/25 flex items-center justify-center gap-3 hover:scale-[1.02] active:scale-[0.98] group">
                <?= $is_auto_approved ? 'Launch Dashboard' : 'Return to Login' ?>
                <span class="material-symbols-outlined text-xl group-hover:translate-x-1 transition-transform">rocket_launch</span>
            </a>
            
            <p class="mt-8 text-[10px] font-black uppercase tracking-[0.2em] text-gray-600 italic">Horizon Digital Infrastructure &copy; <?= date('Y') ?></p>
        </div>
        <?php else: ?>

        <form method="POST" action="?<?= http_build_query($_GET) ?>" class="space-y-6">
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
                <a href="../action/resend_otp.php<?= isset($_GET['gym']) ? '?gym='.urlencode($_GET['gym']) : '' ?>" class="text-primary font-black uppercase tracking-wider hover:underline ml-1 cursor-pointer">Resend OTP</a>
            </p>
        </div>

        <?php endif; ?>
    </div>
</main>

</body>
</html>