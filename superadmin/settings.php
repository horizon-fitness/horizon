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

// Seed default settings if empty
$pdo->exec("
    INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES 
    ('max_staff', '10'),
    ('grace_period', '7'),
    ('default_status', 'Pending'),
    ('system_name', 'Horizon System'),
    ('theme_color', '#8c2bee'),
    ('secondary_color', '#a1a1aa'),
    ('bg_color', '#0a090d'),
    ('font_family', 'Lexend'),
    ('system_logo', '')
");

// Fetch all settings
$stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
$configs = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    unset($_POST['save_settings']);

    $stmtUpdate = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");

    try {
        $pdo->beginTransaction();
        foreach ($_POST as $key => $value) {
            $stmtUpdate->execute([$key, $value]);
            $configs[$key] = $value;
        }
        $pdo->commit();
        $success_msg = "System configurations updated successfully!";
    } catch (PDOException $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
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
        if ($pdo->inTransaction())
            $pdo->rollBack();
        $error_msg = "Error creating account: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html class="dark" lang="en">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>System Settings</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap"
        rel="stylesheet" />
    <link
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200"
        rel="stylesheet" />
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "var(--primary)", "background": "var(--background)", "secondary": "var(--secondary)", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)" } } }
        }
    </script>
    <style>
        :root {
            --primary:
                <?= $configs['theme_color'] ?? '#8c2bee' ?>
            ;
            --background:
                <?= $configs['bg_color'] ?? '#0a090d' ?>
            ;
            --secondary:
                <?= $configs['secondary_color'] ?? '#a1a1aa' ?>
            ;
        }

        body {
            font-family: '<?= $configs['font_family'] ?? 'Lexend' ?>', sans-serif;
            background-color: var(--background);
            color: white;
        }

        .glass-card {
            background: #14121a;
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 24px;
        }

        .sidebar-nav {
            width: 110px;
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            position: fixed;
            left: 0;
            top: 0;
            bottom: 0;
            z-index: 50;
            background: var(--background);
            border-right: 1px solid rgba(255, 255, 255, 0.05);
        }

        .sidebar-nav:hover {
            width: 300px;
        }

        .main-content {
            margin-left: 110px;
            flex: 1;
            min-width: 0;
            transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .sidebar-nav:hover~.main-content {
            margin-left: 300px;
        }

        .sidebar-scroll-container {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
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
            padding: 0 38px;
            pointer-events: none;
        }

        .sidebar-nav:hover .nav-section-header {
            max-height: 20px;
            opacity: 1;
            margin-bottom: 8px !important;
            pointer-events: auto;
        }

        .sidebar-nav:hover .nav-section-header.mt-4 {
            margin-top: 12px !important;
        }

        .sidebar-nav:hover .nav-section-header.mt-6 {
            margin-top: 16px !important;
        }

        .sidebar-content {
            display: flex;
            flex-direction: column;
            gap: 2px;
            transition: all 0.3s ease-in-out;
        }

        .sidebar-nav:hover .sidebar-content {
            gap: 4px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 10px 38px;
            transition: all 0.2s;
            white-space: nowrap;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.05em;
            color: #94a3b8;
            text-decoration: none;
        }

        .active-nav {
            color: var(--primary) !important;
            position: relative;
        }

        .active-nav::after {
            content: '';
            position: absolute;
            right: 0px;
            top: 50%;
            transform: translateY(-50%);
            width: 4px;
            height: 24px;
            background: var(--primary);
            border-radius: 4px 0 0 4px;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .sidebar-nav:hover .active-nav::after {
            opacity: 1;
        }

        @media (max-width: 1023px) {
            .active-nav::after {
                display: none;
            }
        }

        .no-scrollbar::-webkit-scrollbar {
            display: none;
        }

        .no-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        .input-field {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 12px;
            padding: 12px 16px;
            color: white;
            font-size: 13px;
            transition: all 0.2s;
            backdrop-filter: blur(12px);
        }

        .input-field:focus {
            border-color: #8c2bee;
            outline: none;
            background: rgba(140, 43, 238, 0.05);
        }

        #superadminModal,
        #confirmActionModal {
            display: none;
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            left: 110px;
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(12px);
            z-index: 200;
            align-items: center;
            justify-content: center;
            transition: left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        #superadminModal.active,
        #confirmActionModal.active {
            display: flex !important;
        }

        .sidebar-nav:hover~#superadminModal,
        .sidebar-nav:hover~#confirmActionModal {
            left: 300px;
        }
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

    <nav id="liveSidebar" class="sidebar-nav z-50 flex flex-col no-scrollbar">
        <div class="px-7 py-5 mb-2 shrink-0">
            <div class="flex items-center gap-4">
                <div
                    class="size-10 rounded-xl bg-primary/20 flex items-center justify-center shadow-lg shrink-0 overflow-hidden">
                    <?php if (!empty($configs['system_logo'])): ?>
                        <img src="<?= htmlspecialchars($configs['system_logo']) ?>" class="size-full object-cover">
                    <?php else: ?>
                        <span class="material-symbols-outlined text-primary text-2xl">bolt</span>
                    <?php endif; ?>
                </div>
                <h1 id="sidebarSystemName"
                    class="nav-text text-lg font-black italic uppercase tracking-tighter text-white">
                    <?= htmlspecialchars($configs['system_name'] ?? 'Horizon System') ?>
                </h1>
            </div>
        </div>

        <div class="sidebar-scroll-container no-scrollbar space-y-1 pb-4">
            <div class="nav-section-header mb-2">
                <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Overview</span>
            </div>
            <a href="superadmin_dashboard.php"
                class="nav-link <?= ($active_page == 'dashboard') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">grid_view</span>
                <span class="nav-text">Dashboard</span>
            </a>

            <div class="nav-section-header mb-2 mt-4">
                <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Management</span>
            </div>
            <a href="tenant_management.php" class="nav-link <?= ($active_page == 'tenants') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">business</span>
                <span class="nav-text">Tenant Management</span>
            </a>

            <a href="subscription_logs.php"
                class="nav-link <?= ($active_page == 'subscriptions') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">history_edu</span>
                <span class="nav-text">Subscription Logs</span>
            </a>

            <a href="real_time_occupancy.php" class="nav-link <?= ($active_page == 'occupancy') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">group</span>
                <span class="nav-text">Real-Time Occupancy</span>
            </a>

            <a href="recent_transaction.php"
                class="nav-link <?= ($active_page == 'transactions') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">receipt_long</span>
                <span class="nav-text">Recent Transactions</span>
            </a>

            <div class="nav-section-header mb-2 mt-4">
                <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">System</span>
            </div>
            <a href="system_alerts.php" class="nav-link <?= ($active_page == 'alerts') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">notifications_active</span>
                <span class="nav-text">System Alerts</span>
            </a>

            <a href="system_reports.php" class="nav-link <?= ($active_page == 'reports') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">analytics</span>
                <span class="nav-text">Reports</span>
            </a>

            <a href="sales_report.php" class="nav-link <?= ($active_page == 'sales_report') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">monitoring</span>
                <span class="nav-text">Sales Reports</span>
            </a>

            <a href="audit_logs.php" class="nav-link <?= ($active_page == 'audit_logs') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">assignment</span>
                <span class="nav-text">Audit Logs</span>
            </a>

            <a href="backup.php" class="nav-link <?= ($active_page == 'backup') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">backup</span>
                <span class="nav-text">Backup</span>
            </a>
        </div>

        <div class="mt-auto pt-4 border-t border-white/10 flex flex-col gap-1 shrink-0 pb-6">
            <div class="nav-section-header mb-2">
                <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Account</span>
            </div>
            <a href="settings.php" class="nav-link <?= ($active_page == 'settings') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">settings</span>
                <span class="nav-text">Settings</span>
            </a>
            <a href="profile.php" class="nav-link <?= ($active_page == 'profile') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">person</span>
                <span class="nav-text">Profile</span>
            </a>
            <a href="../logout.php" class="nav-link text-gray-400 hover:text-rose-500 transition-colors">
                <span class="material-symbols-outlined text-xl shrink-0">logout</span>
                <span class="nav-text">Sign Out</span>
            </a>
        </div>
    </nav>

    <div id="superadminModal" onclick="if(event.target === this) toggleSuperadminModal(false)">
        <div class="glass-card w-full max-w-2xl p-10 relative">
            <button onclick="toggleSuperadminModal(false)"
                class="absolute top-6 right-6 size-10 rounded-xl bg-white/5 flex items-center justify-center text-gray-500 hover:text-white transition-all">
                <span class="material-symbols-outlined text-xl">close</span>
            </button>

            <div class="flex items-center gap-4 mb-8">
                <div class="size-12 rounded-2xl bg-primary/10 flex items-center justify-center">
                    <span class="material-symbols-outlined text-primary text-2xl">person_add</span>
                </div>
                <div>
                    <h3 class="text-xl font-black italic uppercase tracking-tighter text-white">New <span
                            class="text-primary">Superadmin</span></h3>
                    <p class="text-[10px] text-gray-500 font-bold uppercase tracking-widest">Give admin access to the
                        system</p>
                </div>
            </div>

            <form action="" method="POST" class="space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <input type="text" name="new_first_name" required class="input-field" placeholder="First Name">
                    <input type="text" name="new_last_name" required class="input-field" placeholder="Last Name">
                    <div class="md:col-span-2">
                        <input type="email" name="new_email" required class="input-field w-full"
                            placeholder="Email Address">
                    </div>
                    <input type="password" name="new_password" required class="input-field"
                        placeholder="Password (Min. 8)">
                    <input type="password" name="confirm_new_password" required class="input-field"
                        placeholder="Confirm Password">
                </div>
                <div class="flex justify-end gap-4">
                    <button type="button" onclick="toggleSuperadminModal(false)"
                        class="px-8 py-3 rounded-xl bg-white/5 text-gray-400 text-[10px] font-black uppercase">Cancel</button>
                    <button type="submit" name="add_superadmin"
                        class="px-10 py-3 rounded-xl bg-primary text-black text-[10px] font-black uppercase">Create
                        Account</button>
                </div>
            </form>
        </div>
    </div>

    <div id="confirmActionModal" onclick="if(event.target === this) toggleActionModal(false)">
        <div class="glass-card w-full max-w-lg p-10 relative text-center">
            <div class="size-16 rounded-2xl bg-primary/10 flex items-center justify-center mx-auto mb-6">
                <span id="confirmIcon" class="material-symbols-outlined text-primary text-3xl">warning</span>
            </div>
            <h3 id="confirmTitle" class="text-xl font-black italic uppercase tracking-tighter text-white mb-2">Confirm
                Changes</h3>
            <p id="confirmMessage" class="text-[10px] text-gray-500 font-bold uppercase tracking-widest mb-10 leading-relaxed">
                Save these configurations to the system? This will update the appearance for all users immediately.</p>

            <div class="flex justify-center gap-4">
                <button type="button" onclick="toggleActionModal(false)"
                    class="px-8 py-3 rounded-xl bg-white/5 text-gray-400 text-[10px] font-black uppercase tracking-widest hover:bg-white/10 transition-all">Cancel</button>
                <button id="confirmExecuteBtn" type="button"
                    class="px-10 py-3 rounded-xl bg-primary text-black text-[10px] font-black uppercase tracking-widest hover:bg-white transition-all shadow-lg shadow-primary/20">Confirm
                    Action</button>
            </div>
        </div>
    </div>

    <div class="main-content flex-1 flex flex-col min-w-0 overflow-y-auto">
        <main class="flex-1 p-6 md:p-10 max-w-[1400px] w-full mx-auto">
            <header class="mb-10 flex flex-row justify-between items-end gap-6">
                <div>
                    <h2 class="text-4xl font-black italic uppercase tracking-tighter text-white leading-none">Settings
                    </h2>
                    <p class="text-gray-500 text-[10px] font-bold uppercase tracking-widest mt-2">Manage your system
                        settings and look.</p>
                </div>
                <div class="flex items-center gap-4">
                    <button type="button" onclick="toggleSuperadminModal(true)"
                        class="flex items-center gap-2 px-4 py-2 bg-primary/10 border border-primary/20 rounded-xl text-primary hover:bg-primary hover:text-black transition-all group">
                        <span class="material-symbols-outlined text-sm">person_add</span>
                        <span class="text-[10px] font-black uppercase tracking-widest">Create Superadmin</span>
                    </button>
                    <div class="text-right">
                        <p id="headerClock"
                            class="text-white font-black italic text-2xl tracking-tight leading-none mb-1">00:00:00 AM
                        </p>
                        <p class="text-primary text-[10px] font-black uppercase tracking-[0.2em]">
                            <?= date('l, M d, Y') ?>
                        </p>
                    </div>
                </div>
            </header>

            <?php if (isset($success_msg)): ?>
                <div id="successAlert"
                    class="mb-6 p-4 bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-[10px] font-black uppercase tracking-widest rounded-2xl flex items-center gap-3 transition-all duration-500">
                    <span class="material-symbols-outlined text-sm text-emerald-500">check_circle</span>
                    <span class="flex-1"><?= $success_msg ?></span>
                    <button type="button" onclick="this.parentElement.remove()"
                        class="hover:bg-emerald-500/10 p-1 rounded-lg transition-colors">
                        <span class="material-symbols-outlined text-sm">close</span>
                    </button>
                </div>
            <?php endif; ?>

            <form action="" method="POST" enctype="multipart/form-data" class="space-y-8">
                <!-- Global Customization Section -->
                <div class="glass-card p-8">
                    <div class="flex items-center justify-between mb-8">
                        <div class="flex items-center gap-4">
                            <div class="size-10 rounded-xl bg-primary/10 flex items-center justify-center">
                                <span class="material-symbols-outlined text-primary">brush</span>
                            </div>
                            <div>
                                <h3 class="text-sm font-black italic uppercase tracking-widest text-white">System
                                    Appearance</h3>
                                <p class="text-[10px] text-gray-500 font-bold uppercase tracking-tight">Your brand
                                    identity and logo</p>
                            </div>
                        </div>
                        <button type="button" onclick="resetToDefaults()"
                            class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-white/5 border border-white/10 text-gray-400 hover:text-white hover:bg-white/10 transition-all group">
                            <span
                                class="material-symbols-outlined text-sm group-hover:rotate-180 transition-transform duration-500">undo</span>
                            <span class="text-[9px] font-black uppercase tracking-wider">Reset</span>
                        </button>
                    </div>

                    <div class="space-y-8">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">System
                                    Name</label>
                                <input type="text" id="system_name_input" name="system_name"
                                    oninput="updateLiveBranding()" class="input-field"
                                    value="<?= htmlspecialchars($configs['system_name'] ?? 'Horizon System') ?>"
                                    placeholder="Enter your system brand name">
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Font
                                    Style</label>
                                <select id="font_family_input" name="font_family" onchange="updateLiveBranding()"
                                    class="input-field cursor-pointer">
                                    <option value="Lexend" <?= ($configs['font_family'] ?? '') === 'Lexend' ? 'selected' : '' ?>>Lexend (Default)</option>
                                    <option value="Inter" <?= ($configs['font_family'] ?? '') === 'Inter' ? 'selected' : '' ?>>Inter</option>
                                    <option value="Outfit" <?= ($configs['font_family'] ?? '') === 'Outfit' ? 'selected' : '' ?>>Outfit</option>
                                    <option value="Plus Jakarta Sans" <?= ($configs['font_family'] ?? '') === 'Plus Jakarta Sans' ? 'selected' : '' ?>>Plus Jakarta Sans</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Main
                                    Color</label>
                                <div class="flex items-center gap-4 bg-white/5 p-2 rounded-xl border border-white/5">
                                    <input type="color" id="theme_color_input" name="theme_color"
                                        oninput="updateLiveBranding()"
                                        value="<?= htmlspecialchars($configs['theme_color'] ?? '#8c2bee') ?>"
                                        class="size-10 rounded-lg cursor-pointer bg-transparent border-none">
                                    <span id="theme_hex_display"
                                        class="text-[10px] font-black uppercase text-gray-400"><?= $configs['theme_color'] ?? '#8C2BEE' ?></span>
                                </div>
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label
                                    class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Highlight
                                    Color</label>
                                <div class="flex items-center gap-4 bg-white/5 p-2 rounded-xl border border-white/5">
                                    <input type="color" id="secondary_color_input" name="secondary_color"
                                        oninput="updateLiveBranding()"
                                        value="<?= htmlspecialchars($configs['secondary_color'] ?? '#a1a1aa') ?>"
                                        class="size-10 rounded-lg cursor-pointer bg-transparent border-none">
                                    <span id="secondary_hex_display"
                                        class="text-[10px] font-black uppercase text-gray-400"><?= $configs['secondary_color'] ?? '#A1A1AA' ?></span>
                                </div>
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label
                                    class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Background
                                    Color</label>
                                <div class="flex items-center gap-4 bg-white/5 p-2 rounded-xl border border-white/5">
                                    <input type="color" id="bg_color_input" name="bg_color"
                                        oninput="updateLiveBranding()"
                                        value="<?= htmlspecialchars($configs['bg_color'] ?? '#0a090d') ?>"
                                        class="size-10 rounded-lg cursor-pointer bg-transparent border-none">
                                    <span id="bg_hex_display"
                                        class="text-[10px] font-black uppercase text-gray-400"><?= $configs['bg_color'] ?? '#0A0D0D' ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="glass-card p-8">
                    <div class="flex items-center gap-4 mb-8">
                        <div class="size-10 rounded-xl bg-primary/10 flex items-center justify-center">
                            <span class="material-symbols-outlined text-primary">gavel</span>
                        </div>
                        <div>
                            <h3 class="text-sm font-black italic uppercase tracking-widest text-white">Rules for Gyms
                            </h3>
                            <p class="text-[10px] text-gray-500 font-bold uppercase tracking-tight">Set global limits
                                for all accounts</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Max Staff
                                Count</label>
                            <input type="number" name="max_staff" class="input-field"
                                value="<?= htmlspecialchars($configs['max_staff'] ?? '10') ?>">
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Wait Time
                                (Days)</label>
                            <input type="number" name="grace_period" class="input-field"
                                value="<?= htmlspecialchars($configs['grace_period'] ?? '7') ?>">
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Account
                                Status</label>
                            <select name="default_status" class="input-field cursor-pointer">
                                <option value="Pending" <?= ($configs['default_status'] ?? '') === 'Pending' ? 'selected' : '' ?>>Pending Approval</option>
                                <option value="Active" <?= ($configs['default_status'] ?? '') === 'Active' ? 'selected' : '' ?>>Auto-Approved</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end pt-6">
                    <button type="button" onclick="confirmSaveConfigurations()"
                        class="relative group overflow-hidden px-12 py-4 rounded-2xl bg-primary/10 border border-primary/20 text-white transition-all duration-500 hover:border-primary/50">
                        <div class="relative flex items-center gap-3">
                            <span class="text-[10px] font-black uppercase tracking-[0.2em]">Save Configurations</span>
                            <span
                                class="material-symbols-outlined text-sm group-hover:translate-x-1 transition-transform">east</span>
                        </div>
                    </button>
                </div>
            </form>
        </main>
    </div>
    <script>
        function resetToDefaults() {
            showActionModal(
                'Reset Branding',
                'Revert branding to system defaults? Unsaved progress will be lost.',
                'brush',
                () => {
                    const defaults = {
                        'system_name_input': 'Horizon System',
                        'theme_color_input': '#8c2bee',
                        'secondary_color_input': '#a1a1aa',
                        'bg_color_input': '#0a090d',
                        'font_family_input': 'Lexend'
                    };

                    for (const [id, value] of Object.entries(defaults)) {
                        const el = document.getElementById(id);
                        if (el) el.value = value;
                    }
                    updateLiveBranding();
                    toggleActionModal(false);
                }
            );
        }

        function confirmSaveConfigurations() {
            showActionModal(
                'Save Changes',
                'Update system configurations? This will apply new branding and rules across all platforms immediately.',
                'save',
                () => {
                    const form = document.querySelector('form');
                    const hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = 'save_settings';
                    hiddenInput.value = '1';
                    form.appendChild(hiddenInput);
                    form.submit();
                }
            );
        }

        function showActionModal(title, message, icon, onConfirm) {
            document.getElementById('confirmTitle').textContent = title;
            document.getElementById('confirmMessage').textContent = message;
            document.getElementById('confirmIcon').textContent = icon;

            const confirmBtn = document.getElementById('confirmExecuteBtn');
            const newBtn = confirmBtn.cloneNode(true);
            confirmBtn.parentNode.replaceChild(newBtn, confirmBtn);

            newBtn.addEventListener('click', onConfirm);
            toggleActionModal(true);
        }

        function toggleActionModal(show = true) {
            const modal = document.getElementById('confirmActionModal');
            if (modal) {
                modal.classList.toggle('active', show);
                document.body.style.overflow = show ? 'hidden' : 'auto';
            }
        }

        function updateLiveBranding() {
            const nameInput = document.getElementById('system_name_input');
            const themeInput = document.getElementById('theme_color_input');
            const secondaryInput = document.getElementById('secondary_color_input');
            const bgInput = document.getElementById('bg_color_input');
            const fontInput = document.getElementById('font_family_input');

            // Update Sidebar Name
            if (nameInput) document.getElementById('sidebarSystemName').textContent = nameInput.value;

            // Update CSS Variables
            document.documentElement.style.setProperty('--primary', themeInput.value);
            document.documentElement.style.setProperty('--background', bgInput.value);
            document.documentElement.style.setProperty('--secondary', secondaryInput.value);

            // Update Body Styles
            document.body.style.fontFamily = `'${fontInput.value}', sans-serif`;
            document.body.style.backgroundColor = bgInput.value;

            // Update Hex Displays
            if (document.getElementById('theme_hex_display')) document.getElementById('theme_hex_display').textContent = themeInput.value.toUpperCase();
            if (document.getElementById('secondary_hex_display')) document.getElementById('secondary_hex_display').textContent = secondaryInput.value.toUpperCase();
            if (document.getElementById('bg_hex_display')) document.getElementById('bg_hex_display').textContent = bgInput.value.toUpperCase();
        }

        // Auto-hide success message after 15 seconds
        document.addEventListener('DOMContentLoaded', () => {
            const successAlert = document.getElementById('successAlert');
            if (successAlert) {
                setTimeout(() => {
                    successAlert.style.opacity = '0';
                    successAlert.style.transform = 'translateY(-10px)';
                    setTimeout(() => successAlert.remove(), 500);
                }, 15000);
            }
        });
    </script>
</body>

</html>