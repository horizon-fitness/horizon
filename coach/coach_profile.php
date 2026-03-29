<?php
session_start();
require_once '../db.php';
require_once '../includes/audit_logger.php';

// Security Check
$role = strtolower($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || $role !== 'coach') {
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$gym_id = $_SESSION['gym_id'];
$username = $_SESSION['username'];
$coach_name = ($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '');

// Fetch Gym Details
$stmtGym = $pdo->prepare("SELECT * FROM gyms WHERE gym_id = ?");
$stmtGym->execute([$gym_id]);
$gym = $stmtGym->fetch();

// Active CMS Page (for logo & theme)
$stmtPage = $pdo->prepare("SELECT * FROM tenant_pages WHERE gym_id = ? LIMIT 1");
$stmtPage->execute([$gym_id]);
$page = $stmtPage->fetch();
$success_msg = $_SESSION['success_msg'] ?? '';
$error_msg = $_SESSION['error_msg'] ?? '';
unset($_SESSION['success_msg'], $_SESSION['error_msg']);

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
        
        log_audit_event($pdo, $user_id, $gym_id, 'Update', 'users', $user_id, $old_values, $new_values);
        
        $pdo->commit();
        $_SESSION['success_msg'] = "Profile updated successfully!";
        header("Location: coach_profile.php");
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error_msg'] = "Error updating profile: " . $e->getMessage();
        header("Location: coach_profile.php");
        exit;
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
        $user_row = $stmt->fetch();

        if (!password_verify($current_password, $user_row['password_hash'])) {
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

        $_SESSION['success_msg'] = "Password changed successfully!";
        header("Location: coach_profile.php");
        exit;
    } catch (Exception $e) {
        $_SESSION['error_msg'] = $e->getMessage();
        header("Location: coach_profile.php");
        exit;
    }
}

// Fetch Gym Details
$stmtGym = $pdo->prepare("SELECT * FROM gyms WHERE gym_id = ?");
$stmtGym->execute([$gym_id]);
$gym = $stmtGym->fetch();

// Fetch current user data
$stmt = $pdo->prepare("SELECT *, CONCAT(first_name, ' ', last_name) as fullname FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$pending_count = 0;
$stmtCoach = $pdo->prepare("SELECT coach_id FROM coaches WHERE user_id = ? AND gym_id = ? LIMIT 1");
$stmtCoach->execute([$user_id, $gym_id]);
$coach_data = $stmtCoach->fetch();
$coach_id = $coach_data ? $coach_data['coach_id'] : 0;
if ($coach_id > 0) {
    $stmtPending = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE coach_id = ? AND booking_status = 'Pending'");
    $stmtPending->execute([$coach_id]);
    $pending_count = $stmtPending->fetchColumn();
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
            theme: { extend: { colors: { "primary": "<?= $page['theme_color'] ?? '#8c2bee' ?>", "background-dark": "<?= $page['bg_color'] ?? '#0a090d' ?>", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)" }}}
        }
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: <?= $page['bg_color'] ?? '#0a090d' ?>; color: white; display: flex; flex-direction: row; min-h-screen: 100vh; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        
        /* Sidebar Hover Logic - MATCHING CORE DASHBOARD */
        .sidebar-nav {
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
        .sidebar-nav:hover {
            width: 300px; 
        }

        /* Main Content Adjustment */
        .main-content {
            margin-left: 110px; 
            flex: 1;
            min-width: 0;
            transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .sidebar-nav:hover ~ .main-content {
            margin-left: 300px; 
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
            margin-bottom: 0px !important; 
            pointer-events: auto;
        }
        .sidebar-nav:hover .mt-0 { margin-top: 0px !important; }

        .nav-link { 
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
        .nav-link:hover { background: rgba(255,255,255,0.05); color: white; }
        .active-nav { color: <?= $page['theme_color'] ?? '#8c2bee' ?> !important; position: relative; }
        .active-nav::after { 
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
        
        .input-field { background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.05); border-radius: 12px; padding: 12px 16px; color: white; font-size: 13px; transition: all 0.2s; backdrop-filter: blur(12px); }
        .input-field:focus { border-color: #8c2bee; outline: none; background: rgba(140,43,238,0.05); }
        
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
    </script>
</head>
<body class="antialiased flex flex-row min-h-screen">

<nav class="sidebar-nav flex flex-col fixed left-0 top-0 h-screen bg-background-dark border-r border-white/5 z-50">
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
            <span class="nav-text text-white font-black italic uppercase tracking-tighter text-base leading-none">Coach Dashboard</span>
        </div>
    </div>
    
    <div class="flex flex-col flex-1 overflow-y-auto no-scrollbar gap-0.5">
        <span class="nav-section-header text-[10px] font-black text-gray-500 uppercase tracking-widest px-[38px] mt-0">Main Menu</span>
        
        <a href="coach_dashboard.php" class="nav-link <?= (basename($_SERVER['PHP_SELF']) == 'coach_dashboard.php') ? 'active-nav' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">grid_view</span> 
            <span class="nav-text">Dashboard</span>
            <?php if($pending_count > 0): ?><span class="size-1.5 rounded-full bg-primary alert-dot ml-auto"></span><?php endif; ?>
        </a>
        
        <a href="coach_schedule.php" class="nav-link <?= (basename($_SERVER['PHP_SELF']) == 'coach_schedule.php') ? 'active-nav' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">edit_calendar</span> 
            <span class="nav-text">My Availability</span>
        </a>

        <a href="coach_members.php" class="nav-link <?= (basename($_SERVER['PHP_SELF']) == 'coach_members.php') ? 'active-nav' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">groups</span> 
            <span class="nav-text">My Members</span>
        </a>

        <div class="nav-section-header text-[10px] font-black text-gray-500 uppercase tracking-widest px-[38px] mt-4 mb-2">Training</div>

        <a href="coach_workouts.php" class="nav-link <?= (basename($_SERVER['PHP_SELF']) == 'coach_workouts.php') ? 'active-nav' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">fitness_center</span> 
            <span class="nav-text">Workouts</span>
        </a>
    </div>

    <div class="mt-auto pt-4 border-t border-white/10 flex flex-col gap-1 shrink-0 pb-6">
        <span class="nav-section-header text-[10px] font-black text-gray-500 uppercase tracking-widest px-[38px] mt-0 mb-2">Account</span>

        <a href="coach_profile.php" class="nav-link <?= (basename($_SERVER['PHP_SELF']) == 'coach_profile.php') ? 'active-nav' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">account_circle</span> 
            <span class="nav-text">Profile</span>
        </a>

        <a href="../logout.php" class="nav-link text-gray-400 hover:text-rose-500 transition-colors group">
            <span class="material-symbols-outlined text-xl shrink-0 group-hover:translate-x-1 transition-transform">logout</span>
            <span class="nav-text">Sign Out</span>
        </a>
    </div>
</nav>

<div class="main-content flex flex-col min-w-0">
    <main class="flex-1 p-6 md:p-10 max-w-[1000px] w-full mx-auto">
        <header class="mb-10 flex flex-col md:flex-row justify-between items-start md:items-end gap-6">
            <div>
                <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white leading-none">Coach <span class="text-primary">Profile</span></h2>
                <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-2">Manage your public identity and security settings</p>
            </div>
            <div class="flex flex-row items-center gap-4">
                <div class="px-6 py-3.5 rounded-2xl bg-white/5 border border-white/5 text-right flex flex-col items-end group shadow-sm hover:shadow-primary/10 transition-shadow">
                    <p id="headerClock" class="text-white font-black italic text-xl leading-none mb-1 group-hover:text-primary transition-colors">00:00:00 AM</p>
                    <p class="text-gray-500 text-[9px] font-black uppercase tracking-[0.2em] group-hover:text-white transition-colors"><?= date('l, M d') ?></p>
                </div>
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
                                        <?= strtoupper($user['first_name'][0] . ($user['last_name'][0] ?? '')) ?>
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

                <div class="p-8 border border-white/5 rounded-[24px] border-dashed text-center">
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