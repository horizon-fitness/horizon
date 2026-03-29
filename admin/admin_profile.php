<?php
session_start();
require_once '../db.php';
require_once '../includes/audit_logger.php';

// Security Check
$role = strtolower($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || ($role !== 'staff' && $role !== 'coach')) {
    header("Location: ../login.php");
    exit;
}

$gym_id = $_SESSION['gym_id'];
$user_id = $_SESSION['user_id'];
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

// Handle Personal Info Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $now = date('Y-m-d H:i:s');

    try {
        $pdo->beginTransaction();
        
        // Fetch old values for audit
        $stmtOld = $pdo->prepare("SELECT first_name, last_name, email, phone_number, profile_picture FROM users WHERE user_id = ?");
        $stmtOld->execute([$user_id]);
        $old_values = $stmtOld->fetch(PDO::FETCH_ASSOC);

        $profile_picture = convertFileToBase64('profile_picture');
        
        if ($profile_picture) {
            $stmtUpdate = $pdo->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, phone_number = ?, profile_picture = ?, updated_at = ? WHERE user_id = ?");
            $params = [$first_name, $last_name, $email, $phone, $profile_picture, $now, $user_id];
            $new_values = ['first_name' => $first_name, 'last_name' => $last_name, 'email' => $email, 'phone_number' => $phone, 'profile_picture' => '[IMAGE DATA]'];
        } else {
            $stmtUpdate = $pdo->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, phone_number = ?, updated_at = ? WHERE user_id = ?");
            $params = [$first_name, $last_name, $email, $phone, $now, $user_id];
            $new_values = ['first_name' => $first_name, 'last_name' => $last_name, 'email' => $email, 'phone_number' => $phone];
        }

        $stmtUpdate->execute($params);
        
        // Log Audit Event
        log_audit_event($pdo, $user_id, $gym_id, 'Update', 'users', $user_id, $old_values, $new_values);
        
        $pdo->commit();
        $success_msg = "Profile updated successfully!";
        
        // Refresh session data
        $_SESSION['first_name'] = $first_name;
        $_SESSION['last_name'] = $last_name;
        $_SESSION['email'] = $email;
        $_SESSION['phone_number'] = $phone;
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error_msg = "Error updating profile: " . $e->getMessage();
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
            throw new Exception("New passwords do not match.");
        }

        if (strlen($new_password) < 8) {
            throw new Exception("New password must be at least 8 characters long.");
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

// Fetch current user data and gym branding
$stmtUser = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmtUser->execute([$user_id]);
$user = $stmtUser->fetch(PDO::FETCH_ASSOC);

$stmtGym = $pdo->prepare("SELECT * FROM gyms WHERE gym_id = ?");
$stmtGym->execute([$gym_id]);
$gym = $stmtGym->fetch();

$stmtPage = $pdo->prepare("SELECT * FROM tenant_pages WHERE gym_id = ? LIMIT 1");
$stmtPage->execute([$gym_id]);
$page = $stmtPage->fetch();

$page_title = "Staff Profile";
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title><?= $page_title ?> | Staff Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "<?= $page['theme_color'] ?? '#8c2bee' ?>", "background-dark": "<?= $page['bg_color'] ?? '#0a090d' ?>", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)" }}}
        }
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: <?= $page['bg_color'] ?? '#0a090d' ?>; color: white; display: flex; flex-direction: row; min-h-screen: 100vh; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        
        /* Sidebar Hover Logic - MATCHING CORE DASHBOARD */
        .side-nav {
            width: 110px; 
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            position: fixed;
            left: 0;
            top: 0;
            height: 100vh;
            z-index: 50;
        }
        .side-nav:hover {
            width: 300px; 
        }

        /* Main Content Adjustment */
        .main-content {
            margin-left: 110px; 
            flex: 1;
            min-width: 0;
            transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .side-nav:hover ~ .main-content {
            margin-left: 300px; 
        }

        .nav-label {
            opacity: 0;
            transform: translateX(-15px);
            transition: all 0.3s ease-in-out;
            white-space: nowrap;
            pointer-events: none;
        }
        .side-nav:hover .nav-label {
            opacity: 1;
            transform: translateX(0);
            pointer-events: auto;
        }

        .nav-section-label {
            max-height: 0;
            opacity: 0;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            margin: 0 !important;
            pointer-events: none;
        }
        .side-nav:hover .nav-section-label {
            max-height: 20px;
            opacity: 1;
            margin-bottom: 0px !important; 
            pointer-events: auto;
        }
        .side-nav:hover .mt-0 { margin-top: 0px !important; } 

        .nav-item { 
            display: flex; 
            align-items: center; 
            gap: 16px; 
            padding: 10px 38px; 
            transition: all 0.2s ease; 
            text-decoration: none; 
            white-space: nowrap; 
            font-size: 13px; 
            font-weight: 700; 
            letter-spacing: 0.02em; 
            color: #94a3b8;
        }
        .nav-item:hover { background: rgba(255,255,255,0.05); color: white; }
        .nav-item.active { color: <?= $page['theme_color'] ?? '#8c2bee' ?> !important; position: relative; }
        .nav-item.active::after { 
            content: ''; 
            position: absolute; 
            right: 0px; 
            top: 50%;
            transform: translateY(-50%);
            width: 4px; 
            height: 24px; 
            background: <?= $page['theme_color'] ?? '#8c2bee' ?>; 
            border-radius: 4px 0 0 4px; 
        }
        
        .input-field { background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.05); border-radius: 12px; padding: 12px 16px; color: white; font-size: 13px; transition: all 0.2s; backdrop-filter: blur(12px); width: 100%; }
        .input-field:focus { border-color: <?= $page['theme_color'] ?? '#8c2bee' ?>; outline: none; background: rgba(140,43,238,0.05); }
        
        .strength-bar { height: 4px; border-radius: 2px; transition: all 0.3s ease; width: 0; }
        .strength-weak { background-color: #ef4444; width: 33.33%; }
        .strength-medium { background-color: #f59e0b; width: 66.66%; }
        .strength-strong { background-color: #10b981; width: 100%; }
        
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
    <script>
        function updateHeaderClock() {
            const now = new Date();
            const clockEl = document.getElementById('headerClock');
            if (clockEl) {
                clockEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            }
        }
        setInterval(updateHeaderClock, 1000);
        window.addEventListener('DOMContentLoaded', updateHeaderClock);

        function togglePassword(id, btn) {
            const input = document.getElementById(id);
            const icon = btn.querySelector('.material-symbols-outlined');
            if (input.type === 'password') {
                input.type = 'text';
                icon.textContent = 'visibility_off';
            } else {
                input.type = 'password';
                icon.textContent = 'visibility';
            }
        }

        function checkPasswordStrength(password) {
            let strength = 0;
            const indicator = document.getElementById('strength-indicator');
            const text = document.getElementById('strength-text');
            
            if (password.length >= 8) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[a-z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^A-Za-z0-9]/.test(password)) strength++;

            indicator.className = 'strength-bar';
            
            if (password.length === 0) {
                text.textContent = '';
            } else if (strength <= 2) {
                indicator.classList.add('strength-weak');
                text.textContent = 'Weak';
                text.className = 'text-[10px] font-black uppercase tracking-widest text-red-500 mt-1';
            } else if (strength <= 4) {
                indicator.classList.add('strength-medium');
                text.textContent = 'Medium';
                text.className = 'text-[10px] font-black uppercase tracking-widest text-amber-500 mt-1';
            } else {
                indicator.classList.add('strength-strong');
                text.textContent = 'Strong';
                text.className = 'text-[10px] font-black uppercase tracking-widest text-emerald-500 mt-1';
            }
        }
    </script>
</head>
<body class="antialiased flex flex-row min-h-screen">

<nav class="side-nav flex flex-col fixed left-0 top-0 h-screen bg-background-dark border-r border-white/5 z-50">
    <div class="px-7 py-8">
        <div class="flex items-center gap-[6px]">
            <div class="size-10 rounded-xl bg-primary flex items-center justify-center shadow-lg shrink-0 overflow-hidden">
                <?php if (!empty($page['logo_path'])): 
                    $logo_src = (strpos($page['logo_path'], 'data:image') === 0) ? $page['logo_path'] : '../' . $page['logo_path'];
                ?>
                    <img src="<?= $logo_src ?>" class="size-full object-contain">
                <?php else: ?>
                    <span class="material-symbols-outlined text-white text-2xl">bolt</span>
                <?php endif; ?>
            </div>
            <span class="nav-label text-white font-black italic uppercase tracking-tighter text-base leading-none">Staff Portal</span>
        </div>
    </div>
    
    <div class="flex flex-col flex-1 overflow-y-auto no-scrollbar gap-0.5">
        <span class="nav-section-label text-[10px] font-black text-gray-500 uppercase tracking-widest px-[38px] mt-0">Main Menu</span>
        
        <a href="admin_dashboard.php" class="nav-item <?= (basename($_SERVER['PHP_SELF']) == 'admin_dashboard.php') ? 'active' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">grid_view</span>
            <span class="nav-label">Dashboard</span>
        </a>

        <a href="register_member.php" class="nav-item <?= (basename($_SERVER['PHP_SELF']) == 'register_member.php') ? 'active' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">person_add</span>
            <span class="nav-label">Walk-in Member</span>
        </a>

        <span class="nav-section-label text-[10px] font-black text-gray-500 uppercase tracking-widest px-[38px] mt-4 mb-2">Management</span>
        
        <a href="admin_users.php" class="nav-item <?= (basename($_SERVER['PHP_SELF']) == 'admin_users.php') ? 'active' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">group</span>
            <span class="nav-label">My Users</span>
        </a>
        
        <a href="admin_transaction.php" class="nav-item <?= (basename($_SERVER['PHP_SELF']) == 'admin_transaction.php') ? 'active' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">receipt_long</span>
            <span class="nav-label">Transactions</span>
        </a>

        <a href="admin_appointment.php" class="nav-item <?= (basename($_SERVER['PHP_SELF']) == 'admin_appointment.php') ? 'active' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">event_note</span>
            <span class="nav-label">Bookings</span>
        </a>

        <a href="admin_attendance.php" class="nav-item <?= (basename($_SERVER['PHP_SELF']) == 'admin_attendance.php') ? 'active' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">history</span>
            <span class="nav-label">Attendance</span>
        </a>

        <a href="admin_report.php" class="nav-item <?= (basename($_SERVER['PHP_SELF']) == 'admin_report.php') ? 'active' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">description</span>
            <span class="nav-label">Reports</span>
        </a>
    </div>

    <div class="mt-auto pt-4 border-t border-white/10 flex flex-col gap-1 shrink-0 pb-6">
        <span class="nav-section-label text-[10px] font-black text-gray-500 uppercase tracking-widest px-[38px] mt-0 mb-2">Account</span>

        <a href="admin_profile.php" class="nav-item <?= (basename($_SERVER['PHP_SELF']) == 'admin_profile.php') ? 'active' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">account_circle</span>
            <span class="nav-label">Profile</span>
        </a>

        <a href="../logout.php" class="nav-item text-gray-400 hover:text-rose-500 transition-colors group">
            <span class="material-symbols-outlined text-xl shrink-0 group-hover:translate-x-1 transition-transform">logout</span>
            <span class="nav-label">Sign Out</span>
        </a>
    </div>
</nav>

<div class="main-content flex flex-col min-w-0">
    <main class="flex-1 p-6 md:p-10 max-w-[1200px] w-full mx-auto">
        <header class="mb-10 flex flex-row justify-between items-end gap-6">
            <div>
                <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white leading-none">Staff <span class="text-primary">Profile</span></h2>
                <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-2">Personal Identity & Security</p>
            </div>
            <div class="text-right">
                <p id="headerClock" class="text-white font-black italic text-xl tracking-tight leading-none mb-2">00:00:00 AM</p>
                <p class="text-primary text-[9px] font-black uppercase tracking-[0.2em] opacity-80"><?= date('l, M d, Y') ?></p>
            </div>
        </header>

        <?php if ($success_msg): ?>
        <div class="mb-8 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-[10px] font-black uppercase tracking-widest flex items-center gap-3">
            <span class="material-symbols-outlined text-sm">check_circle</span>
            <?= htmlspecialchars($success_msg) ?>
        </div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
        <div class="mb-8 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-[10px] font-black uppercase tracking-widest flex items-center gap-3">
            <span class="material-symbols-outlined text-sm">error</span>
            <?= htmlspecialchars($error_msg) ?>
        </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <!-- Profile Info Column -->
            <div class="md:col-span-2 space-y-8">
                <form action="" method="POST" enctype="multipart/form-data" class="glass-card p-8 space-y-8">
                    <div class="flex items-center gap-4 mb-4">
                        <div class="size-10 rounded-xl bg-primary/10 flex items-center justify-center">
                            <span class="material-symbols-outlined text-primary">person</span>
                        </div>
                        <div>
                            <h3 class="text-sm font-black italic uppercase tracking-widest">Personal Information</h3>
                            <p class="text-[9px] text-gray-500 font-bold uppercase tracking-wider">Update your staff identity</p>
                        </div>
                    </div>

                    <div class="flex flex-col md:flex-row gap-8 items-start">
                        <div class="shrink-0 flex flex-col items-center gap-4">
                            <div class="size-32 rounded-[2rem] bg-white/5 border border-white/10 overflow-hidden relative group">
                                <?php if ($user['profile_picture']): ?>
                                    <img src="<?= $user['profile_picture'] ?>" class="size-full object-cover">
                                <?php else: ?>
                                    <div class="size-full flex items-center justify-center bg-primary/5 text-primary text-4xl font-black italic">
                                        <?= strtoupper($user['first_name'][0] . $user['last_name'][0]) ?>
                                    </div>
                                <?php endif; ?>
                                <label class="absolute inset-0 bg-black/60 opacity-0 group-hover:opacity-100 transition-opacity flex flex-col items-center justify-center cursor-pointer">
                                    <span class="material-symbols-outlined text-2xl mb-1">photo_camera</span>
                                    <span class="text-[8px] font-black uppercase tracking-widest">Change</span>
                                    <input type="file" name="profile_picture" class="hidden" accept="image/*">
                                </label>
                            </div>
                        </div>

                        <div class="flex-1 grid grid-cols-1 md:grid-cols-2 gap-6 w-full">
                            <div class="flex flex-col gap-2">
                                <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">First Name</label>
                                <input type="text" name="first_name" class="input-field" value="<?= htmlspecialchars($user['first_name']) ?>" required>
                            </div>
                            <div class="flex flex-col gap-2">
                                <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Last Name</label>
                                <input type="text" name="last_name" class="input-field" value="<?= htmlspecialchars($user['last_name']) ?>" required>
                            </div>
                            <div class="flex flex-col gap-2">
                                <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Email Address</label>
                                <input type="email" name="email" class="input-field" value="<?= htmlspecialchars($user['email']) ?>" required>
                            </div>
                            <div class="flex flex-col gap-2">
                                <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Phone Number</label>
                                <input type="text" name="phone" class="input-field" value="<?= htmlspecialchars($user['phone_number']) ?>" required>
                            </div>
                        </div>
                    </div>

                    <div class="pt-4 flex justify-end">
                        <button type="submit" name="update_profile" class="px-8 py-3 rounded-xl bg-primary hover:bg-primary/90 text-white text-[10px] font-black uppercase italic tracking-widest shadow-lg shadow-primary/20 transition-all active:scale-[0.98] flex items-center gap-2">
                            <span class="material-symbols-outlined text-sm">save</span> Update Profile
                        </button>
                    </div>
                </form>

                <div class="glass-card p-8 border border-white/5 bg-white/5">
                    <div class="flex items-center gap-4 mb-6">
                        <div class="size-10 rounded-xl bg-white/5 flex items-center justify-center">
                            <span class="material-symbols-outlined text-gray-400">info</span>
                        </div>
                        <div>
                            <h3 class="text-sm font-black italic uppercase tracking-widest">Account Details</h3>
                            <p class="text-[9px] text-gray-500 font-bold uppercase tracking-wider">Internal system references</p>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-6">
                        <div>
                            <p class="text-[8px] font-black uppercase text-gray-600 tracking-[0.2em] mb-1">Username</p>
                            <p class="text-xs font-bold italic text-white">@<?= htmlspecialchars($user['username']) ?></p>
                        </div>
                        <div>
                            <p class="text-[8px] font-black uppercase text-gray-600 tracking-[0.2em] mb-1">Account Created</p>
                            <p class="text-xs font-bold italic text-white"><?= date('M d, Y', strtotime($user['created_at'])) ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Security Column -->
            <div class="space-y-8">
                <form action="" method="POST" class="glass-card p-8 space-y-6 border border-primary/20 bg-primary/[0.02]">
                    <div class="flex items-center gap-4 mb-2">
                        <div class="size-10 rounded-xl bg-primary/20 flex items-center justify-center">
                            <span class="material-symbols-outlined text-primary">security</span>
                        </div>
                        <div>
                            <h3 class="text-sm font-black italic uppercase tracking-widest">Security</h3>
                            <p class="text-[9px] text-gray-500 font-bold uppercase tracking-wider">Update credentials</p>
                        </div>
                    </div>

                    <div class="space-y-5">
                        <div class="flex flex-col gap-2">
                            <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Current Password</label>
                            <input type="password" name="current_password" class="input-field" required>
                        </div>
                        <div class="h-px bg-white/5 mx-2"></div>
                        <div class="flex flex-col gap-2">
                            <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">New Password</label>
                            <input type="password" name="new_password" id="new_password" onkeyup="checkPasswordStrength(this.value)" class="input-field" placeholder="At least 8 characters" required>
                            <div class="w-full bg-white/5 h-1 rounded-full mt-2 overflow-hidden">
                                <div id="strength-indicator" class="strength-bar"></div>
                            </div>
                            <p id="strength-text" class="text-[10px] font-black uppercase tracking-widest"></p>
                        </div>
                        <div class="flex flex-col gap-2">
                            <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Confirm New Password</label>
                            <input type="password" name="confirm_password" id="confirm_password" class="input-field" required>
                        </div>
                    </div>

                    <div class="pt-4">
                        <button type="submit" name="change_password" class="w-full py-4 rounded-xl border border-primary/30 hover:bg-primary/10 text-primary text-[10px] font-black uppercase italic tracking-widest transition-all active:scale-[0.98] flex items-center justify-center gap-2">
                            <span class="material-symbols-outlined text-sm">lock_reset</span> Change Password
                        </button>
                    </div>
                </form>

                <div class="p-8 border border-white/5 rounded-3xl text-center">
                    <span class="material-symbols-outlined text-3xl text-gray-600 mb-3">shield_lock</span>
                    <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest italic leading-relaxed">
                        Security Notice: Ensure your password is unique and not used elsewhere.
                    </p>
                </div>
            </div>
        </div>
    </main>
</div>

</body>
</html>