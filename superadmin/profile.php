<?php 
session_start();
require_once '../db.php';
require_once '../includes/audit_logger.php';

// Security Check: Only Superadmin can access
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'superadmin') {
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$success_msg = $_SESSION['success_msg'] ?? '';
$error_msg = $_SESSION['error_msg'] ?? '';
unset($_SESSION['success_msg'], $_SESSION['error_msg']);

// Helper function for Base64 conversion (matching Horizon pattern)
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
    $contact_number = trim($_POST['contact_number']);
    $now = date('Y-m-d H:i:s');

    try {
        $pdo->beginTransaction();
        
        // Fetch old values for audit
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
        
        log_audit_event($pdo, $user_id, null, 'Update', 'users', $user_id, $old_values, $new_values);
        
        $pdo->commit();
        $success_msg = "Profile updated successfully!";
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
        $user = $stmt->fetch();

        if (!password_verify($current_password, $user['password_hash'])) {
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

        log_audit_event($pdo, $user_id, null, 'Update', 'users', $user_id, ['password' => 'CHANGED'], ['password' => 'CHANGED']);

        $success_msg = "Password changed successfully!";
    } catch (Exception $e) {
        $error_msg = $e->getMessage();
    }
}

// Fetch current user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$page_title = "My Profile";
$active_page = "profile";
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title><?= $page_title ?> | Horizon System</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "#8c2bee", "background-dark": "#0a090d", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)"}}}
        }
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        
        /* Sidebar Hover Logic */
        .sidebar-nav {
            width: 110px;
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
        }
        .sidebar-nav:hover {
            width: 300px;
        }
        .nav-text {
            opacity: 0;
            transform: translateX(-15px);
            transition: all 0.3s ease-in-out;
            white-space: nowrap;
            pointer-events: none;
        }
        .sidebar-nav:hover .nav-text {
            opacity: 1;
            transform: translateX(0);
            pointer-events: auto;
        }

        .nav-section-header {
            max-height: 0;
            opacity: 0;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            margin: 0 !important;
            pointer-events: none;
        }
        .sidebar-nav:hover .nav-section-header {
            max-height: 20px;
            opacity: 1;
            margin-bottom: 0.5rem !important;
            pointer-events: auto;
        }
        .sidebar-nav:hover .nav-section-header.mt-4 { margin-top: 0.75rem !important; }
        .sidebar-nav:hover .nav-section-header.mt-6 { margin-top: 1.25rem !important; }

        .sidebar-content {
            gap: 2px;
            transition: all 0.3s ease-in-out;
        }
        .sidebar-nav:hover .sidebar-content {
            gap: 4px;
        }

        .nav-link { font-size: 11px; font-weight: 800; letter-spacing: 0.05em; transition: all 0.2s; white-space: nowrap; }
        .active-nav { color: #8c2bee !important; position: relative; }
        .active-nav::after { 
            content: ''; 
            position: absolute; 
            right: 0px; 
            top: 50%;
            transform: translateY(-50%);
            width: 4px; 
            height: 20px; 
            background: #8c2bee; 
            border-radius: 99px; 
        }
        
        .input-field { background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.05); border-radius: 12px; padding: 12px 16px; color: white; font-size: 13px; transition: all 0.2s; backdrop-filter: blur(12px); }
        .input-field:focus { border-color: #8c2bee; outline: none; background: rgba(140,43,238,0.05); }
        
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
    <script>
        function updateHeaderClock() {
            const now = new Date();
            const clockEl = document.getElementById('headerClock');
            if (clockEl) {
                clockEl.textContent = now.toLocaleTimeString('en-US', { 
                    hour: '2-digit', 
                    minute: '2-digit', 
                    second: '2-digit' 
                });
            }
        }
        setInterval(updateHeaderClock, 1000);
        window.addEventListener('DOMContentLoaded', updateHeaderClock);
    </script>
</head>
<body class="antialiased flex flex-row min-h-screen">

<nav class="sidebar-nav bg-[#0a090d] border-r border-white/5 sticky top-0 h-screen px-7 py-8 z-50 shrink-0 flex flex-col">
    <div class="mb-4 shrink-0"> 
        <div class="flex items-center gap-4 mb-4"> 
            <div class="size-10 rounded-xl bg-[#7f13ec] flex items-center justify-center shadow-lg shrink-0">
                <span class="material-symbols-outlined text-white text-2xl">bolt</span>
            </div>
            <h1 class="nav-text text-xl font-black italic uppercase tracking-tighter text-white">Horizon System</h1>
        </div>
    </div>
    
    <div class="flex-1 overflow-y-auto no-scrollbar space-y-1 pr-2">
        <div class="nav-section-header px-0 mb-2">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Overview</span>
        </div>
        <a href="superadmin_dashboard.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'dashboard') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">grid_view</span> 
            <span class="nav-text">Dashboard</span>
        </a>
        
        <div class="nav-section-header px-0 mb-2 mt-4">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Management</span>
        </div>
        <a href="tenant_management.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'tenants') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">business</span> 
            <span class="nav-text">Tenant Management</span>
        </a>

        <a href="subscription_logs.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'subscriptions') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">history_edu</span> 
            <span class="nav-text">Subscription Logs</span>
        </a>

        <a href="real_time_occupancy.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'occupancy') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">group</span> 
            <span class="nav-text">Real-Time Occupancy</span>
        </a>

        <a href="recent_transaction.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'transactions') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">receipt_long</span> 
            <span class="nav-text">Recent Transactions</span>
        </a>

        <div class="nav-section-header px-0 mb-2 mt-4">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">System</span>
        </div>
        <a href="system_alerts.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'alerts') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">notifications_active</span> 
            <span class="nav-text">System Alerts</span>
        </a>

        <a href="system_reports.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'reports') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">analytics</span> 
            <span class="nav-text">Reports</span>
        </a>

        <a href="sales_report.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'sales_report') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">monitoring</span> 
            <span class="nav-text">Sales Reports</span>
        </a>

        <a href="audit_logs.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'audit_logs') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">assignment</span> 
            <span class="nav-text">Audit Logs</span>
        </a>

        <a href="backup.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'backup') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">backup</span> 
            <span class="nav-text">Backup</span>
        </a>
    </div>

    <div class="mt-auto pt-4 border-t border-white/10 flex flex-col gap-2 shrink-0">
        <div class="nav-section-header px-0 mb-0">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Account</span>
        </div>
        <a href="settings.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'settings') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">settings</span> 
            <span class="nav-text">Settings</span>
        </a>
        <a href="profile.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'profile') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">person</span> 
            <span class="nav-text">Profile</span>
        </a>
        <a href="../logout.php" class="text-gray-400 hover:text-rose-500 transition-colors flex items-center gap-4 group py-2">
            <span class="material-symbols-outlined group-hover:translate-x-1 transition-transform text-xl shrink-0">logout</span>
            <span class="nav-link nav-text">Sign Out</span>
        </a>
    </div>
</nav>

<div class="flex-1 flex flex-col min-w-0 overflow-y-auto">
    <main class="flex-1 p-6 md:p-10 max-w-[1000px] w-full mx-auto">
        <header class="mb-10 flex flex-row justify-between items-end gap-6">
            <div>
                <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white leading-none">Admin <span class="text-primary">Profile</span></h2>
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
                            <p class="text-[9px] text-gray-500 font-bold uppercase tracking-wider">Update your public identity</p>
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
                                <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Contact Number</label>
                                <input type="text" name="contact_number" class="input-field" value="<?= htmlspecialchars($user['contact_number']) ?>" required>
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
                            <p class="text-xs font-bold italic text-white"><?= htmlspecialchars($user['username']) ?></p>
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
                            <input type="password" name="new_password" class="input-field" placeholder="At least 8 characters" required>
                        </div>
                        <div class="flex flex-col gap-2">
                            <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="input-field" required>
                        </div>
                    </div>

                    <div class="pt-4">
                        <button type="submit" name="change_password" class="w-full py-4 rounded-xl border border-primary/30 hover:bg-primary/10 text-primary text-[10px] font-black uppercase italic tracking-widest transition-all active:scale-[0.98] flex items-center justify-center gap-2">
                            <span class="material-symbols-outlined text-sm">lock_reset</span> Change Password
                        </button>
                    </div>
                </form>

                <div class="p-8 dashed-container text-center">
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
