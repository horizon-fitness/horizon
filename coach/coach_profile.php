<?php
session_start();
require_once '../db.php';
require_once '../includes/audit_logger.php';

// Security Check
$role_session = strtolower($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || $role_session !== 'coach') {
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$gym_id = $_SESSION['gym_id'];
$success_msg = '';
$error_msg = '';

// Helper function for Base64 conversion
function convertFileToBase64($fileInputName) {
    if (isset($_FILES[$fileInputName]) && $_FILES[$fileInputName]['error'] == 0) {
        $tmpPath = $_FILES[$fileInputName]['tmp_name'];
        $fileType = $_FILES[$fileInputName]['type'];
        $fileData = file_get_contents($tmpPath);
        return 'data:' . $fileType . ';base64,' . base64_encode($fileData);
    }
    return null;
}

// Handle Profile Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $contact_number = trim($_POST['phone']);
    $now = date('Y-m-d H:i:s');

    try {
        $pdo->beginTransaction();
        
        $stmtOld = $pdo->prepare("SELECT first_name, last_name, email, contact_number, profile_picture FROM users WHERE user_id = ?");
        $stmtOld->execute([$user_id]);
        $old_values = $stmtOld->fetch(PDO::FETCH_ASSOC);

        $profile_picture = convertFileToBase64('profile_picture');
        
        if ($profile_picture) {
            $stmtUpdate = $pdo->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, contact_number = ?, profile_picture = ?, updated_at = ? WHERE user_id = ?");
            $params = [$first_name, $last_name, $email, $contact_number, $profile_picture, $now, $user_id];
            $new_values = ['first_name' => $first_name, 'last_name' => $last_name, 'email' => $email, 'contact_number' => $contact_number, 'profile_picture' => '[IMAGE DATA]'];
        } else {
            $stmtUpdate = $pdo->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, contact_number = ?, updated_at = ? WHERE user_id = ?");
            $params = [$first_name, $last_name, $email, $contact_number, $now, $user_id];
            $new_values = ['first_name' => $first_name, 'last_name' => $last_name, 'email' => $email, 'contact_number' => $contact_number];
        }

        $stmtUpdate->execute($params);
        log_audit_event($pdo, $user_id, $gym_id, 'Update', 'users', $user_id, $old_values, $new_values);
        $pdo->commit();
        $success_msg = "Profile updated successfully!";
        $_SESSION['first_name'] = $first_name;
        $_SESSION['last_name'] = $last_name;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error_msg = "Error: " . $e->getMessage();
    }
}

// Handle Password Change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    try {
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $user_data = $stmt->fetch();

        if (!password_verify($current_password, $user_data['password_hash'])) {
            throw new Exception("Incorrect current password.");
        }
        if ($new_password !== $confirm_password) {
            throw new Exception("Passwords do not match.");
        }
        if (strlen($new_password) < 8) {
            throw new Exception("Must be at least 8 characters.");
        }

        $new_hash = password_hash($new_password, PASSWORD_BCRYPT);
        $stmtUpdate = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE user_id = ?");
        $stmtUpdate->execute([$new_hash, $user_id]);

        log_audit_event($pdo, $user_id, $gym_id, 'Update', 'users', $user_id, ['password' => 'CHANGED'], ['password' => 'CHANGED']);
        $success_msg = "Password changed successfully!";
    } catch (Exception $e) {
        $error_msg = $e->getMessage();
    }
}

// FETCH Comprehensive Data
$stmtUser = $pdo->prepare("
    SELECT u.*, c.coach_type, c.specialization, c.status as coach_status
    FROM users u
    INNER JOIN coaches c ON u.user_id = c.user_id
    WHERE u.user_id = ? AND c.gym_id = ?
");
$stmtUser->execute([$user_id, $gym_id]);
$user = $stmtUser->fetch(PDO::FETCH_ASSOC);

$stmtGym = $pdo->prepare("SELECT * FROM gyms WHERE gym_id = ?");
$stmtGym->execute([$gym_id]);
$gym = $stmtGym->fetch();

$stmtPage = $pdo->prepare("SELECT * FROM tenant_pages WHERE gym_id = ? LIMIT 1");
$stmtPage->execute([$gym_id]);
$tenant_config = $stmtPage->fetch();

$pending_count = 0;
if ($user['coach_status'] === 'Active') {
    $stmtCoach = $pdo->prepare("SELECT coach_id FROM coaches WHERE user_id = ? AND gym_id = ? LIMIT 1");
    $stmtCoach->execute([$user_id, $gym_id]);
    $coach_data = $stmtCoach->fetch();
    $coach_id = $coach_data ? $coach_data['coach_id'] : 0;
    if ($coach_id > 0) {
        $stmtPending = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE coach_id = ? AND booking_status = 'Pending'");
        $stmtPending->execute([$coach_id]);
        $pending_count = $stmtPending->fetchColumn();
    }
}
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Coach Profile | Horizon System</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "<?= $tenant_config['theme_color'] ?? '#8c2bee' ?>", "background-dark": "<?= $tenant_config['bg_color'] ?? '#0a090d' ?>", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)" }}}
        }
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: <?= $tenant_config['bg_color'] ?? '#0a090d' ?>; color: white; display: flex; flex-direction: row; min-height: 100vh; overflow: hidden; }
        .glass-card { background: #14121a; border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 20px; }
        :root { --nav-width: 100px; }
        body:has(.side-nav:hover) { --nav-width: 280px; }
        .side-nav { width: var(--nav-width); transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1); overflow: hidden; display: flex; flex-direction: column; position: fixed; left: 0; top: 0; height: 100vh; z-index: 110; }
        .main-content { margin-left: var(--nav-width); flex: 1; min-width: 0; transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1); overflow-y: auto; overflow-x: hidden; }
        .nav-label { opacity: 0; transform: translateX(-15px); transition: all 0.3s ease-in-out; white-space: nowrap; pointer-events: none; }
        .side-nav:hover .nav-label { opacity: 1; transform: translateX(0); pointer-events: auto; }
        .nav-section-label { max-height: 0; opacity: 0; overflow: hidden; transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); margin: 0 !important; pointer-events: none; }
        .side-nav:hover .nav-section-label { max-height: 20px; opacity: 1; margin-bottom: 8px !important; pointer-events: auto; }
        .nav-item { display: flex; align-items: center; gap: 16px; padding: 10px 32px; transition: all 0.2s ease; text-decoration: none; white-space: nowrap; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; }
        .nav-item:hover { background: rgba(255, 255, 255, 0.05); color: white; }
        .nav-item.active { color: <?= $tenant_config['theme_color'] ?? '#8c2bee' ?> !important; position: relative; }
        .nav-item.active::after { content: ''; position: absolute; right: 0px; top: 50%; transform: translateY(-50%); width: 4px; height: 20px; background: <?= $tenant_config['theme_color'] ?? '#8c2bee' ?>; border-radius: 4px 0 0 4px; }
        .input-box { background: rgba(255, 255, 255, 0.03); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; color: white; padding: 10px 14px; font-size: 12px; font-weight: 500; outline: none; transition: all 0.2s; width: 100%; }
        .input-box:focus { border-color: <?= $tenant_config['theme_color'] ?? '#8c2bee' ?>; background: rgba(255, 255, 255, 0.06); }
        .strength-bar { height: 4px; border-radius: 2px; transition: all 0.3s ease; width: 0; }
        .strength-weak { background-color: #ef4444; width: 33.33%; }
        .strength-medium { background-color: #f59e0b; width: 66.66%; }
        .strength-strong { background-color: #10b981; width: 100%; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        .alert-dot { animation: pulse 2s infinite; }
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.4; } 100% { opacity: 1; } }
    </style>
    <script>
        function updateHeaderClock() {
            const now = new Date();
            const clockEl = document.getElementById('headerClock');
            if (clockEl) clockEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }
        setInterval(updateHeaderClock, 1000);
        window.addEventListener('DOMContentLoaded', updateHeaderClock);

        function checkPasswordStrength(password) {
            let strength = 0;
            const indicator = document.getElementById('strength-indicator');
            const text = document.getElementById('strength-text');
            if (password.length >= 8) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^A-Za-z0-9]/.test(password)) strength++;
            indicator.className = 'strength-bar';
            if (password.length === 0) {
                text.textContent = '';
            } else if (strength <= 1) {
                indicator.classList.add('strength-weak');
                text.textContent = 'Weak';
                text.className = 'text-[8px] font-black uppercase tracking-widest text-rose-500 mt-1';
            } else if (strength <= 3) {
                indicator.classList.add('strength-medium');
                text.textContent = 'Medium';
                text.className = 'text-[8px] font-black uppercase tracking-widest text-amber-500 mt-1';
            } else {
                indicator.classList.add('strength-strong');
                text.textContent = 'Strong';
                text.className = 'text-[8px] font-black uppercase tracking-widest text-emerald-500 mt-1';
            }
        }

        function previewProfileImage(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const img = document.getElementById('profilePreviewImg');
                    const placeholder = document.getElementById('profilePlaceholder');
                    if (img) { img.src = e.target.result; img.classList.remove('hidden'); }
                    if (placeholder) placeholder.classList.add('hidden');
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        function togglePassword(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            if (input.type === 'password') {
                input.type = 'text';
                icon.textContent = 'visibility';
            } else {
                input.type = 'password';
                icon.textContent = 'visibility_off';
            }
        }
    </script>
</head>
<body class="antialiased flex h-screen overflow-hidden">

<nav class="side-nav flex flex-col fixed left-0 top-0 h-screen bg-background-dark border-r border-white/5 no-print">
    <div class="px-7 py-8 mb-4 shrink-0">
        <div class="flex items-center gap-4">
            <div class="size-10 rounded-xl bg-primary flex items-center justify-center shadow-lg shrink-0 overflow-hidden">
                <?php if (!empty($tenant_config['logo_path'])): 
                    $logo_src = (strpos($tenant_config['logo_path'], 'data:image') === 0) ? $tenant_config['logo_path'] : '../' . $tenant_config['logo_path']; ?>
                    <img src="<?= $logo_src ?>" class="size-full object-contain">
                <?php else: ?>
                    <span class="material-symbols-outlined text-white text-2xl">bolt</span>
                <?php endif; ?>
            </div>
            <h1 class="nav-label text-lg font-black italic uppercase tracking-tighter text-white">Coach Portal</h1>
        </div>
    </div>
    <div class="flex-1 overflow-y-auto no-scrollbar space-y-1">
        <div class="nav-section-label px-[38px] mb-2"><span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Menu</span></div>
        <a href="coach_dashboard.php" class="nav-item"><span class="material-symbols-outlined text-xl shrink-0">grid_view</span><span class="nav-label">Dashboard</span></a>
        
        <div class="nav-section-label px-[38px] mb-2 mt-6"><span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Schedule</span></div>
        <a href="coach_schedule.php" class="nav-item"><span class="material-symbols-outlined text-xl shrink-0">calendar_today</span><span class="nav-label">My Schedule</span></a>
        <a href="coach_members.php" class="nav-item"><span class="material-symbols-outlined text-xl shrink-0">groups</span><span class="nav-label">Members</span></a>
        
    </div>
    <div class="mt-auto pt-4 border-t border-white/10 shrink-0 pb-6">
        <div class="nav-section-label px-[38px] mb-2"><span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Account</span></div>
        <a href="coach_profile.php" class="nav-item active"><span class="material-symbols-outlined text-xl shrink-0">account_circle</span><span class="nav-label">Profile</span></a>
        <a href="../logout.php" class="nav-item text-gray-400 hover:text-rose-500 transition-colors group">
            <span class="material-symbols-outlined text-xl shrink-0 group-hover:translate-x-1 transition-transform">logout</span>
            <span class="nav-label whitespace-nowrap">Sign Out</span>
        </a>
    </div>
</nav>

<div class="main-content flex-1 overflow-y-auto no-scrollbar">
    <main class="p-10 max-w-[1100px] mx-auto pb-20">
        <header class="mb-12 flex flex-row justify-between items-end gap-6">
            <div>
                <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white leading-none">Coach <span class="text-primary opacity-60">Profile</span></h2>
                <p class="text-gray-500 text-[10px] font-bold uppercase tracking-widest mt-2 px-1 opacity-60">Personal Identity & Security</p>
            </div>
            <div class="flex items-end gap-8 text-right shrink-0 no-print">
                <div class="flex flex-col items-end">
                    <p id="headerClock" class="text-white font-black italic text-2xl leading-none tracking-tighter">00:00:00 AM</p>
                    <p class="text-primary text-[10px] font-black uppercase tracking-[0.2em] leading-none mt-2"><?= date('l, M d, Y') ?></p>
                </div>
            </div>
        </header>

        <?php if ($success_msg): ?>
            <div class="mb-6 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-[9px] font-black uppercase tracking-[0.2em] flex items-center gap-3">
                <span class="material-symbols-outlined text-base">check_circle</span> <?= $success_msg ?>
            </div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
            <div class="mb-6 p-4 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-400 text-[9px] font-black uppercase tracking-[0.2em] flex items-center gap-3">
                <span class="material-symbols-outlined text-base">error</span> <?= $error_msg ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <div class="lg:col-span-2 space-y-6">
                <form action="" method="POST" enctype="multipart/form-data" class="glass-card p-8 space-y-5 border border-white/5 relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-48 h-48 bg-primary/5 rounded-full -translate-y-1/2 translate-x-1/2 blur-3xl"></div>
                    <div class="flex items-center gap-3 relative z-10 border-b border-white/5 pb-4 mb-2">
                        <div class="size-10 rounded-xl bg-primary/10 flex items-center justify-center text-primary border border-primary/20">
                            <span class="material-symbols-outlined text-xl">person</span>
                        </div>
                        <div>
                            <h3 class="text-[11px] font-black italic uppercase tracking-[0.2em] text-white">PROFILE</h3>
                            <p class="text-[8px] font-bold text-gray-500 uppercase tracking-widest mt-0.5">Edit your public coach details</p>
                        </div>
                    </div>

                    <div class="flex flex-col md:flex-row gap-8 items-start relative z-10">
                        <div class="shrink-0 mx-auto md:mx-0">
                            <div class="size-28 rounded-[28px] bg-white/5 border border-white/10 overflow-hidden relative group cursor-pointer shadow-2xl">
                                <?php if (!empty($user['profile_picture'])): ?>
                                    <img id="profilePreviewImg" src="<?= $user['profile_picture'] ?>" class="size-full object-cover transition-transform duration-700 group-hover:scale-110">
                                <?php else: ?>
                                    <img id="profilePreviewImg" src="" class="size-full object-cover transition-transform duration-700 group-hover:scale-110 hidden">
                                    <div id="profilePlaceholder" class="size-full flex items-center justify-center bg-gradient-to-br from-primary/20 via-primary/10 to-transparent text-primary text-3xl font-black italic group-hover:scale-110 transition-transform duration-500">
                                        <?= strtoupper($user['first_name'][0] . ($user['last_name'][0] ?? '')) ?>
                                    </div>
                                <?php endif; ?>
                                <label class="absolute inset-0 bg-black/70 opacity-0 group-hover:opacity-100 transition-all duration-300 flex flex-col items-center justify-center gap-1.5 backdrop-blur-sm">
                                    <span class="material-symbols-outlined text-white text-2xl">add_a_photo</span>
                                    <span class="text-[8px] font-black uppercase text-white tracking-[0.2em]">Update</span>
                                    <input type="file" name="profile_picture" class="hidden" accept="image/*" onchange="previewProfileImage(this)">
                                </label>
                            </div>
                        </div>

                        <div class="flex-1 grid grid-cols-1 md:grid-cols-2 gap-5 w-full">
                            <div class="space-y-1.5">
                                <p class="text-[9px] font-black uppercase tracking-widest text-primary/60 ml-1">First Name</p>
                                <input type="text" name="first_name" value="<?= htmlspecialchars($user['first_name']) ?>" class="input-box" required>
                            </div>
                            <div class="space-y-1.5">
                                <p class="text-[9px] font-black uppercase tracking-widest text-primary/60 ml-1">Last Name</p>
                                <input type="text" name="last_name" value="<?= htmlspecialchars($user['last_name']) ?>" class="input-box" required>
                            </div>
                            <div class="space-y-1.5 md:col-span-2">
                                <p class="text-[9px] font-black uppercase tracking-widest text-gray-500 ml-1 opacity-60">Email Address</p>
                                <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" class="input-box" required>
                            </div>
                            <div class="space-y-1.5 md:col-span-2">
                                <p class="text-[9px] font-black uppercase tracking-widest text-gray-500 ml-1 opacity-60">Contact Number</p>
                                <input type="text" name="phone" value="<?= htmlspecialchars($user['contact_number'] ?? '') ?>" class="input-box" placeholder="+63 000 000 0000">
                            </div>
                        </div>
                    </div>

                    <div class="pt-5 border-t border-white/5 flex justify-end relative z-10">
                        <button type="submit" name="update_profile" class="px-8 py-3 bg-primary text-white rounded-xl text-[9px] font-black uppercase italic tracking-[0.2em] shadow-lg shadow-primary/20 hover:scale-[1.02] transition-all active:scale-[0.98] flex items-center gap-2.5">
                            <span class="material-symbols-outlined text-base">verified</span> Save Changes
                        </button>
                    </div>
                </form>

                <div class="glass-card p-6 border border-white/5 bg-white/[0.01] grid grid-cols-1 sm:grid-cols-3 gap-6 text-center sm:text-left">
                    <div class="px-2 overflow-hidden">
                        <p class="text-[8px] font-black uppercase text-gray-600 tracking-[0.2em] mb-1.5">Username</p>
                        <p class="text-[11px] font-black italic text-white tracking-tighter">@<?= htmlspecialchars($user['username']) ?></p>
                    </div>
                    <div class="px-2 overflow-hidden border-x border-white/5">
                        <p class="text-[8px] font-black uppercase text-gray-600 tracking-[0.2em] mb-1.5">Coach Type</p>
                        <p class="text-[11px] font-black italic uppercase text-primary tracking-tighter">
                            <?= htmlspecialchars(strtoupper($user['coach_type'] ?? 'COACH')) ?></p>
                    </div>
                    <div class="px-2 overflow-hidden">
                        <p class="text-[8px] font-black uppercase text-gray-600 tracking-[0.2em] mb-1.5">Specialization</p>
                        <p class="text-[11px] font-black italic uppercase text-white tracking-tighter">
                            <?= htmlspecialchars(strtoupper($user['specialization'] ?? 'GENERAL')) ?></p>
                    </div>
                </div>
            </div>

            <div class="space-y-6">
                <form action="" method="POST" class="glass-card p-7 space-y-6 border border-primary/10 bg-primary/[0.03] relative overflow-hidden group">
                    <div class="absolute top-0 right-0 w-24 h-24 bg-primary/10 rounded-full translate-x-1/2 -translate-y-1/2 blur-2xl group-hover:bg-primary/20 transition-colors"></div>
                    <div class="flex items-center gap-3 relative z-10">
                        <div class="size-10 rounded-xl bg-primary/20 flex items-center justify-center text-primary">
                            <span class="material-symbols-outlined text-xl">security</span></div>
                        <div>
                            <h3 class="text-[11px] font-black italic uppercase tracking-[0.2em] text-white">Security</h3>
                            <p class="text-[8px] font-bold text-gray-500 uppercase tracking-widest mt-0.5">Change password</p>
                        </div>
                    </div>
                    <div class="space-y-4 relative z-10">
                        <div class="space-y-1.5">
                            <p class="text-[9px] font-black uppercase tracking-widest text-primary/60 ml-0.5">Current Password</p>
                            <div class="relative">
                                <input type="password" id="current_password" name="current_password" class="input-box bg-white/5 border-white/10 pr-10" required>
                                <button type="button" onclick="togglePassword('current_password', 'current_pass_icon')" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-white transition-colors"><span id="current_pass_icon" class="material-symbols-outlined text-base">visibility_off</span></button>
                            </div>
                        </div>
                        <div class="space-y-1.5">
                            <p class="text-[9px] font-black uppercase tracking-widest text-primary/60 ml-0.5">New Password</p>
                            <div class="relative">
                                <input type="password" id="new_password" name="new_password" onkeyup="checkPasswordStrength(this.value)" class="input-box bg-white/5 border-white/10 pr-10" required>
                                <button type="button" onclick="togglePassword('new_password', 'new_pass_icon')" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-white transition-colors"><span id="new_pass_icon" class="material-symbols-outlined text-base">visibility_off</span></button>
                            </div>
                            <div class="w-full bg-white/5 h-1 rounded-full mt-2 overflow-hidden">
                                <div id="strength-indicator" class="strength-bar"></div>
                            </div>
                            <div id="strength-text"></div>
                        </div>
                        <div class="space-y-1.5">
                            <p class="text-[9px] font-black uppercase tracking-widest text-primary/60 ml-0.5">Confirm Password</p>
                            <div class="relative">
                                <input type="password" id="confirm_password" name="confirm_password" class="input-box bg-white/5 border-white/10 pr-10" required>
                                <button type="button" onclick="togglePassword('confirm_password', 'confirm_pass_icon')" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-white transition-colors"><span id="confirm_pass_icon" class="material-symbols-outlined text-base">visibility_off</span></button>
                            </div>
                        </div>
                    </div>
                    <div class="pt-2 relative z-10">
                        <button type="submit" name="change_password" class="w-full py-3 border border-primary/30 rounded-xl text-[9px] font-black uppercase italic tracking-[0.2em] text-primary hover:bg-primary/10 transition-all flex items-center justify-center gap-2.5">
                            <span class="material-symbols-outlined text-base">lock_reset</span> Update Key
                        </button>
                    </div>
                </form>
                <div class="glass-card p-6 border border-white/5 bg-gradient-to-br from-white/[0.01] to-transparent text-center">
                    <div class="size-16 rounded-[24px] bg-primary mx-auto mb-4 flex items-center justify-center shadow-2xl overflow-hidden">
                        <?php if (!empty($tenant_config['logo_path'])): 
                            $logo_src = (strpos($tenant_config['logo_path'], 'data:image') === 0) ? $tenant_config['logo_path'] : '../' . $tenant_config['logo_path']; ?>
                            <img src="<?= $logo_src ?>" class="size-full object-contain">
                        <?php else: ?>
                            <span class="material-symbols-outlined text-white text-3xl">bolt</span>
                        <?php endif; ?>
                    </div>
                    <h4 class="text-[11px] font-black italic uppercase tracking-tighter text-white"><?= htmlspecialchars($gym['gym_name'] ?? 'Horizon Gym') ?></h4>
                    <p class="text-[7px] font-bold text-gray-600 uppercase tracking-widest mt-1 italic opacity-60">Verified Member Since <?= date('Y', strtotime($user['created_at'])) ?></p>
                </div>
            </div>
        </div>
    </main>
</div>
</body>
</html>