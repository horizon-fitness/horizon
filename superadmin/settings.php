<?php 
session_start();
require_once '../db.php';

// Security Check
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'superadmin') {
    header("Location: ../login.php");
    exit;
}

// Initialize system_settings table if it doesn't exist
$pdo->exec("
    CREATE TABLE IF NOT EXISTS system_settings (
        setting_key VARCHAR(50) PRIMARY KEY,
        setting_value TEXT,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )
");

// Seed default settings if empty (Branding removed)
$pdo->exec("
    INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES 
    ('max_staff', '10'),
    ('grace_period', '7'),
    ('default_status', 'Pending')
");

// Fetch all settings
$stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
$configs = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    unset($_POST['save_settings']); 
    
    $stmtUpdate = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    
    try {
        foreach ($_POST as $key => $value) {
            $stmtUpdate->execute([$key, $value]);
            $configs[$key] = $value; 
        }
        $success_msg = "System configurations updated successfully!";
    } catch (PDOException $e) {
        $error_msg = "Error updating settings: " . $e->getMessage();
    }
}

// Handle New Superadmin Creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_superadmin'])) {
    $first_name = trim($_POST['new_first_name']);
    $last_name = trim($_POST['new_last_name']);
    $email = trim($_POST['new_email']);
    $password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_new_password'];

    try {
        if (empty($first_name) || empty($last_name) || empty($email) || empty($password)) {
            throw new Exception("All fields are required.");
        }
        if ($password !== $confirm_password) {
            throw new Exception("Passwords do not match.");
        }
        if (strlen($password) < 8) {
            throw new Exception("Password must be at least 8 characters long.");
        }

        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $stmtCheck->execute([$email]);
        if ($stmtCheck->fetchColumn() > 0) {
            throw new Exception("Email already exists.");
        }

        $pdo->beginTransaction();

        $password_hash = password_hash($password, PASSWORD_BCRYPT);
        $stmtUser = $pdo->prepare("INSERT INTO users (first_name, last_name, email, password_hash, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())");
        $stmtUser->execute([$first_name, $last_name, $email, $password_hash]);
        $new_user_id = $pdo->lastInsertId();

        $stmtRole = $pdo->prepare("INSERT INTO user_roles (user_id, role_id, role_status, created_at) VALUES (?, 1, 'Active', NOW())");
        $stmtRole->execute([$new_user_id]);

        $pdo->commit();
        $success_msg = "New Superadmin account created successfully!";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error_msg = "Error creating account: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>System Settings</title>
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
        
        /* Sidebar Hover Logic - ADJUSTED WIDTHS */
        .sidebar-nav {
            width: 110px; 
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .sidebar-nav:hover {
            width: 300px; 
        }

        /* Added: Scrollable container for links */
        .sidebar-scroll-container {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            padding-right: 4px;
        }

        /* Custom Scrollbar for the sidebar */
        .sidebar-scroll-container::-webkit-scrollbar {
            width: 4px;
        }
        .sidebar-scroll-container::-webkit-scrollbar-track {
            background: transparent;
        }
        .sidebar-scroll-container::-webkit-scrollbar-thumb {
            background: rgba(140, 43, 238, 0.1);
            border-radius: 10px;
        }
        .sidebar-nav:hover .sidebar-scroll-container::-webkit-scrollbar-thumb {
            background: rgba(140, 43, 238, 0.4);
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
        .sidebar-nav:hover .nav-section-header.mt-4 { margin-top: 0px !important; } 
        .sidebar-nav:hover .nav-section-header.mt-6 { margin-top: 0px !important; } 

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
        
        @media (max-width: 1023px) {
            .active-nav::after { display: none; }
        }

        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        .input-field { background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.05); border-radius: 12px; padding: 12px 16px; color: white; font-size: 13px; transition: all 0.2s; backdrop-filter: blur(12px); }
        .input-field:focus { border-color: #8c2bee; outline: none; background: rgba(140,43,238,0.05); }
        #superadminModal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.8); backdrop-filter: blur(8px); z-index: 1000; align-items: center; justify-content: center; }
        #superadminModal.active { display: flex; }
    </style>
    <script>
        function updateHeaderClock() {
            const clockEl = document.getElementById('headerClock');
            if (clockEl) { clockEl.textContent = new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' }); }
        }
        setInterval(updateHeaderClock, 1000);
        function toggleSuperadminModal(show = true) {
            const modal = document.getElementById('superadminModal');
            modal.classList.toggle('active', show);
            document.body.style.overflow = show ? 'hidden' : 'auto';
        }
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
    <main class="flex-1 p-6 md:p-10 max-w-[1400px] w-full mx-auto">
        <header class="mb-10 flex flex-row justify-between items-end gap-6">
            <div>
                <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white leading-none">System <span class="text-primary">Settings</span></h2>
                <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-2">Global Configuration & Control</p>
            </div>
            <div class="flex items-center gap-4">
                <button type="button" onclick="toggleSuperadminModal(true)" class="flex items-center gap-2 px-4 py-2 bg-primary/10 border border-primary/20 rounded-xl text-primary hover:bg-primary hover:text-black transition-all group">
                    <span class="material-symbols-outlined text-sm">person_add</span>
                    <span class="text-[10px] font-black uppercase tracking-widest">Create Superadmin</span>
                </button>
                <div class="text-right">
                    <p id="headerClock" class="text-white font-black italic text-xl tracking-tight leading-none mb-2">00:00:00 AM</p>
                    <p class="text-primary text-[9px] font-black uppercase tracking-[0.2em] opacity-80"><?= date('l, M d, Y') ?></p>
                </div>
            </div>
        </header>

        <?php if (isset($success_msg)): ?>
            <div class="mb-6 p-4 bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-[10px] font-black uppercase tracking-widest rounded-2xl flex items-center gap-3">
                <span class="material-symbols-outlined text-sm">check_circle</span> <?= $success_msg ?>
            </div>
        <?php endif; ?>

        <form action="" method="POST" class="space-y-8">
            <div class="glass-card p-8">
                <div class="flex items-center gap-4 mb-8">
                    <div class="size-10 rounded-xl bg-primary/10 flex items-center justify-center">
                        <span class="material-symbols-outlined text-primary">gavel</span>
                    </div>
                    <div>
                        <h3 class="text-sm font-black italic uppercase tracking-widest text-white">Tenant Limits & Rules</h3>
                        <p class="text-[10px] text-gray-500 font-bold uppercase">Global restrictions for gym owners</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="flex flex-col gap-2">
                        <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Max Staff per Tenant</label>
                        <input type="number" name="max_staff" class="input-field" value="<?= htmlspecialchars($configs['max_staff'] ?? '10') ?>">
                    </div>
                    <div class="flex flex-col gap-2">
                        <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Grace Period (Days)</label>
                        <input type="number" name="grace_period" class="input-field" value="<?= htmlspecialchars($configs['grace_period'] ?? '7') ?>">
                    </div>
                    <div class="flex flex-col gap-2">
                        <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">New Tenant Status</label>
                        <select name="default_status" class="input-field cursor-pointer">
                            <option value="Pending" <?= ($configs['default_status'] ?? '') === 'Pending' ? 'selected' : '' ?>>Pending Approval</option>
                            <option value="Active" <?= ($configs['default_status'] ?? '') === 'Active' ? 'selected' : '' ?>>Auto-Activate</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="flex justify-end pt-6">
                <button type="submit" name="save_settings" class="relative group overflow-hidden px-12 py-4 rounded-2xl bg-primary/10 border border-primary/20 text-white transition-all duration-500 hover:border-primary/50">
                    <div class="relative flex items-center gap-3">
                        <span class="text-[10px] font-black uppercase tracking-[0.2em]">Save Configurations</span>
                        <span class="material-symbols-outlined text-sm group-hover:translate-x-1 transition-transform">east</span>
                    </div>
                </button>
            </div>
        </form>

        <div id="superadminModal" onclick="if(event.target === this) toggleSuperadminModal(false)">
            <div class="glass-card w-full max-w-2xl p-10 relative">
                <button onclick="toggleSuperadminModal(false)" class="absolute top-6 right-6 size-10 rounded-xl bg-white/5 flex items-center justify-center text-gray-500 hover:text-white transition-all">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>

                <div class="flex items-center gap-4 mb-8">
                    <div class="size-12 rounded-2xl bg-primary/10 flex items-center justify-center">
                        <span class="material-symbols-outlined text-primary text-2xl">person_add</span>
                    </div>
                    <div>
                        <h3 class="text-xl font-black italic uppercase tracking-tighter text-white">New <span class="text-primary">Superadmin</span></h3>
                        <p class="text-[10px] text-gray-500 font-bold uppercase tracking-widest">Assign administrative privileges</p>
                    </div>
                </div>

                <form action="" method="POST" class="space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <input type="text" name="new_first_name" required class="input-field" placeholder="First Name">
                        <input type="text" name="new_last_name" required class="input-field" placeholder="Last Name">
                        <div class="md:col-span-2">
                            <input type="email" name="new_email" required class="input-field w-full" placeholder="Email Address">
                        </div>
                        <input type="password" name="new_password" required class="input-field" placeholder="Password (Min. 8)">
                        <input type="password" name="confirm_new_password" required class="input-field" placeholder="Confirm Password">
                    </div>
                    <div class="flex justify-end gap-4">
                        <button type="button" onclick="toggleSuperadminModal(false)" class="px-8 py-3 rounded-xl bg-white/5 text-gray-400 text-[10px] font-black uppercase">Cancel</button>
                        <button type="submit" name="add_superadmin" class="px-10 py-3 rounded-xl bg-primary text-black text-[10px] font-black uppercase">Create Account</button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>
</body>
</html>