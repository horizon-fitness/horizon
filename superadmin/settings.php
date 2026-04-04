<?php
session_start();
require_once '../db.php';
require_once '../includes/mailer.php';

// Security Check
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'superadmin') {
    header("Location: ../login.php");
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Hex to RGB helper for dynamic transparency
function hexToRgb($hex)
{
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

// Migration: Update system_settings to include user_id if it doesn't exist
$pdo->exec("
        CREATE TABLE IF NOT EXISTS system_settings (
            user_id INT NOT NULL,
            setting_key VARCHAR(50) NOT NULL,
            setting_value TEXT,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, setting_key)
        )
    ");

// Check if we need to migrate from the old schema (where setting_key was the only PK)
$res = $pdo->query("SHOW COLUMNS FROM system_settings LIKE 'user_id'");
if (!$res->fetch()) {
    $pdo->exec("ALTER TABLE system_settings ADD COLUMN user_id INT NOT NULL DEFAULT 1 FIRST");
    $pdo->exec("ALTER TABLE system_settings DROP PRIMARY KEY, ADD PRIMARY KEY (user_id, setting_key)");
}

// Define Settings Scopes
$global_keys = ['max_staff', 'grace_period', 'default_status', 'system_name', 'system_logo'];

// Seed default settings for the CURRENT Superadmin (Personal) if missing
$stmtSeed = $pdo->prepare("INSERT IGNORE INTO system_settings (user_id, setting_key, setting_value) VALUES (?, ?, ?)");

// Personal Defaults
$personal_defaults = [
    ['theme_color', '#8c2bee'],
    ['secondary_color', '#a1a1aa'],
    ['text_color', '#d1d5db'],
    ['bg_color', '#0a090d']
];
foreach ($personal_defaults as $s)
    $stmtSeed->execute([$_SESSION['user_id'], $s[0], $s[1]]);

// Global Defaults (Rules & Brand)
$global_defaults = [
    ['max_staff', '10'],
    ['grace_period', '7'],
    ['default_status', 'Pending'],
    ['system_name', 'Horizon System'],
    ['system_logo', '']
];
foreach ($global_defaults as $s)
    $stmtSeed->execute([0, $s[0], $s[1]]);

// Fetch and Merge Settings
// 1. Fetch Global Settings
$stmtGlobal = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE user_id = 0");
$global_configs = $stmtGlobal->fetchAll(PDO::FETCH_KEY_PAIR);

// 2. Fetch User-Specific Settings
$stmtUser = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = ?");
$stmtUser->execute([$_SESSION['user_id']]);
$user_configs = $stmtUser->fetchAll(PDO::FETCH_KEY_PAIR);

// 3. Merge (User settings take precedence for overlapping keys if any)
$configs = array_merge($global_configs, $user_configs);

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    unset($_POST['save_settings']);
    $stmtUpdate = $pdo->prepare("INSERT INTO system_settings (user_id, setting_key, setting_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");

    try {
        $pdo->beginTransaction();
        foreach ($_POST as $key => $value) {
            $scope_id = in_array($key, $global_keys) ? 0 : $_SESSION['user_id'];
            $stmtUpdate->execute([$scope_id, $key, $value]);
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
    $username = trim($_POST['new_username']);
    $first_name = trim($_POST['new_first_name']);
    $middle_name = trim($_POST['new_middle_name'] ?? '');
    $last_name = trim($_POST['new_last_name']);
    $email = trim($_POST['new_email']);
    $contact_number = trim($_POST['new_contact_number']);
    $birth_date = trim($_POST['new_birth_date']);
    $sex = trim($_POST['new_sex']);
    $password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_new_password'];

    try {
        if (empty($username) || empty($first_name) || empty($last_name) || empty($email) || empty($password) || empty($contact_number)) {
            throw new Exception("Required fields are missing.");
        }

        // --- Server-Side Validation (Matching profile.php) ---
        if (preg_match('/[0-9]/', $first_name))
            throw new Exception("First name cannot contain numbers.");
        if (!empty($middle_name) && preg_match('/[0-9]/', $middle_name))
            throw new Exception("Middle name cannot contain numbers.");
        if (preg_match('/[0-9]/', $last_name))
            throw new Exception("Last name cannot contain numbers.");

        $raw_contact = str_replace(['-', ' '], '', $contact_number);
        if (!ctype_digit($raw_contact) || strlen($raw_contact) !== 11) {
            throw new Exception("Contact number must be exactly 11 digits.");
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !str_ends_with(strtolower($email), '@gmail.com')) {
            throw new Exception("Email must be a valid @gmail.com address.");
        }

        if ($birth_date > date('Y-m-d')) {
            throw new Exception("Birth date cannot be a future date.");
        }

        if ($password !== $confirm_password) {
            throw new Exception("Passwords do not match.");
        }
        if (strlen($password) < 8) {
            throw new Exception("Password must be at least 8 characters long.");
        }

        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? OR username = ?");
        $stmtCheck->execute([$email, $username]);
        if ($stmtCheck->fetchColumn() > 0) {
            throw new Exception("Email or Username already exists.");
        }

        $pdo->beginTransaction();

        $password_hash = password_hash($password, PASSWORD_BCRYPT);
        $stmtUser = $pdo->prepare("INSERT INTO users (username, first_name, middle_name, last_name, email, contact_number, birth_date, sex, password_hash, is_verified, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, NOW(), NOW())");
        $stmtUser->execute([$username, $first_name, $middle_name, $last_name, $email, $contact_number, $birth_date, $sex, $password_hash]);
        $new_user_id = $pdo->lastInsertId();

        // Fix to match DB schema (assigned_at instead of created_at if necessary, but following current file's existing structure)
        $stmtRole = $pdo->prepare("INSERT INTO user_roles (user_id, role_id, role_status, assigned_at) VALUES (?, 1, 'Active', NOW())");
        $stmtRole->execute([$new_user_id]);

        $pdo->commit();
        $success_msg = "New Superadmin account created successfully!";

        // Send Email Notification
        $login_url = (isset($_SERVER['HTTPS']) ? "https://" : "http://") . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/../login.php";
        $email_content = "
                <p>Hello <strong>$first_name $last_name</strong>,</p>
                <p>Your Superadmin account for <strong>Horizon Systems</strong> has been created successfully. Below are your login credentials:</p>
                <div style='background: #f8f8f8; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                    <p style='margin: 0;'><strong>Username:</strong> $username</p>
                    <p style='margin: 0;'><strong>Password:</strong> $password</p>
                    <p style='margin: 10px 0 0 0;'><strong>Login URL:</strong> <a href='$login_url'>$login_url</a></p>
                </div>
                <p>Please log in and update your security settings once you have access.</p>
                <p>Regards,<br>Horizon Management Team</p>";

        sendSystemEmail($email, "Welcome to Horizon Systems - Superadmin Access", getEmailTemplate("Account Created", $email_content));

    } catch (Exception $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        $error_msg = "Error creating account: " . $e->getMessage();
    }
}

$active_page = "settings";
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
            --primary-rgb:
                <?= hexToRgb($configs['theme_color'] ?? '#8c2bee') ?>
            ;
            --background:
                <?= $configs['bg_color'] ?? '#0a090d' ?>
            ;
            --highlight:
                <?= $configs['secondary_color'] ?? '#a1a1aa' ?>
            ;
            --text-main:
                <?= $configs['text_color'] ?? '#d1d5db' ?>
            ;
            --secondary-rgb: 161, 161, 170;
            --card-blur: 20px;
            --card-bg:
                <?= ($configs['auto_card_theme'] ?? '1') === '1' ? 'rgba(' . hexToRgb($configs['theme_color'] ?? '#8c2bee') . ', 0.05)' : ($configs['card_color'] ?? '#141216') ?>
            ;
        }

        body {
            font-family: '<?= $configs['font_family'] ?? 'Lexend' ?>', sans-serif;
            background-color: var(--background);
            color: var(--text-main);
        }

        .glass-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border, rgba(255, 255, 255, 0.05));
            border-radius: 24px;
            backdrop-filter: blur(var(--card-blur));
            box-shadow: var(--card-shadow, 0 10px 30px rgba(0, 0, 0, 0.2)), var(--card-glow, 0 0 0 transparent);
            transition: all 0.3s ease;
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

        /* Global Hidden Scrollbar - Sleek UI */
        ::-webkit-scrollbar {
            display: none;
        }

        * {
            -ms-overflow-style: none;
            /* IE and Edge */
            scrollbar-width: none;
            /* Firefox */
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
            color: var(--text-main);
            text-decoration: none;
        }

        .nav-link span.material-symbols-outlined {
            color: var(--highlight);
            opacity: 0.7;
            transition: all 0.3s ease;
        }

        .nav-link:hover {
            opacity: 0.8;
            /* Subtle feedback instead of color change */
        }

        .nav-link:hover span.material-symbols-outlined {
            /* No color change to match dashboard */
        }

        .active-nav {
            color: var(--primary) !important;
            position: relative;
        }

        .active-nav span.material-symbols-outlined {
            color: var(--primary) !important;
            opacity: 1 !important;
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
            opacity: 1;
            transition: opacity 0.3s ease;
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
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 12px 16px;
            color: white;
            font-size: 13px;
            transition: all 0.2s;
            backdrop-filter: blur(10px);
        }

        /* Dynamic Input Field Interaction */
        .input-field:hover {
            border-color: rgba(255, 255, 255, 0.2);
            background: rgba(255, 255, 255, 0.08);
        }

        .input-field:focus {
            border-color: var(--primary);
            outline: none;
            background: rgba(var(--primary-rgb), 0.05);
            /* Requires RGB variable */
            box-shadow: 0 0 20px rgba(var(--primary-rgb), 0.1);
        }

        .input-field option {
            background-color: #0d0c12;
            color: white;
        }

        .input-field:read-only {
            cursor: not-allowed;
            opacity: 0.6;
            background: rgba(255, 255, 255, 0.02);
        }

        /* Improved Dropdown Visibility */
        select.input-field {
            color-scheme: dark;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='white'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='Wait, simplified arrow'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 1em;
            padding-right: 2.5rem;
        }

        #superadminModal,
        #superadminReviewModal,
        #confirmActionModal {
            display: none;
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            left: 110px;
            z-index: 200;
            background: rgba(10, 9, 13, 0.85);
            backdrop-filter: blur(12px);
            align-items: center;
            justify-content: center;
            padding: 20px;
            transition: left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        #superadminModal.active,
        #superadminReviewModal.active,
        #confirmActionModal.active {
            display: flex !important;
        }

        .sidebar-nav:hover~#superadminModal,
        .sidebar-nav:hover~#superadminReviewModal,
        .sidebar-nav:hover~#confirmActionModal {
            left: 300px;
        }

        /* Improved Modal responsiveness and padding with hidden scrollbar */
        .modal-content-scroll {
            max-height: calc(90vh - 40px);
            overflow-y: auto;
            -ms-overflow-style: none;
            /* IE and Edge */
            scrollbar-width: none;
            /* Firefox */
        }

        .modal-content-scroll::-webkit-scrollbar {
            display: none;
            /* Chrome, Safari, and Opera */
        }
    </style>
    <script>
        function updateHeaderClock() {
            const clockEl = document.getElementById('headerClock');
            if (clockEl) { clockEl.textContent = new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' }); }
        }
        setInterval(updateHeaderClock, 1000);
        function toggleActionModal(show = true, title = '', msg = '', type = 'confirm', callback = null, autoOpen = false) {
            const modal = document.getElementById('confirmActionModal');
            if (!modal) return;

            if (show) {
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
            } else {
                modal.classList.remove('active');
                document.body.style.overflow = 'auto';
            }
        }

        function showActionModal(title, msg, type, callback, autoOpen) {
            toggleActionModal(true, title, msg, type, callback, autoOpen);
        }

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
                <div class="size-10 rounded-xl flex items-center justify-center shadow-lg shrink-0 overflow-hidden">
                    <?php if (!empty($configs['system_logo'])): ?>
                        <img src="<?= htmlspecialchars($configs['system_logo']) ?>" id="sidebarLogoPreview"
                            class="size-full object-contain rounded-xl">
                    <?php else: ?>
                        <img src="../assests/horizon logo.png" id="sidebarLogoPreview"
                            class="size-full object-contain rounded-xl transition-transform duration-500 hover:scale-110"
                            alt="Horizon Logo">
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
            <a href="../logout.php" class="nav-link hover:text-rose-500 transition-all group">
                <span class="material-symbols-outlined text-xl shrink-0 group-hover:text-rose-500">logout</span>
                <span class="nav-text group-hover:text-rose-500">Sign Out</span>
            </a>
        </div>
    </nav>

    <!-- Modals are now at the bottom -->

    <div class="main-content flex-1 flex flex-col min-w-0 overflow-y-auto">
        <main class="flex-1 p-6 md:p-10 max-w-[1400px] w-full mx-auto">
            <header class="mb-12 flex flex-row justify-between items-end gap-6">
                <div>
                    <h2 class="text-3xl font-black italic uppercase tracking-tighter leading-none">
                        <span class="text-[--text-main] opacity-80">SYSTEM</span>
                        <span class="text-primary">SETTINGS</span>
                    </h2>
                    <p
                        class="text-[--text-main] text-[10px] font-bold uppercase tracking-widest mt-2 px-1 opacity-80 uppercase">
                        Manage your system settings and look.</p>
                </div>
                <div class="flex items-end gap-8 text-right shrink-0">
                    <div class="flex flex-col items-end">
                        <p id="headerClock"
                            class="text-[--text-main] font-black italic text-2xl leading-none tracking-tighter uppercase transition-colors cursor-default">
                            00:00:00 AM</p>
                        <p class="text-primary text-[10px] font-black uppercase tracking-[0.2em] leading-none mt-2">
                            <?= date('l, M d, Y') ?>
                        </p>
                    </div>
                </div>
            </header>

            <div class="flex justify-end items-center mb-10 gap-4 transition-all">
                <?php if (isset($success_msg)): ?>
                    <div id="successAlert"
                        class="flex-1 px-8 h-[46px] bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-[10px] font-black uppercase tracking-widest rounded-xl flex items-center justify-between gap-3 transition-all duration-700">
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined text-sm text-emerald-500">check_circle</span>
                            <span><?= $success_msg ?></span>
                        </div>
                        <button type="button" onclick="this.parentElement.remove()"
                            class="hover:bg-emerald-500/10 p-1 rounded-lg transition-colors ml-2">
                            <span class="material-symbols-outlined text-sm">close</span>
                        </button>
                    </div>
                <?php endif; ?>

                <button type="button" onclick="confirmSaveConfigurations()"
                    class="bg-white/5 hover:bg-primary/20 px-8 h-[46px] rounded-xl text-[10px] font-black uppercase tracking-widest transition-all border border-white/10 hover:border-primary/30 flex items-center gap-3 active:scale-95 group shrink-0">
                    <span
                        class="material-symbols-outlined text-[--highlight] group-hover:text-white text-lg group-hover:scale-110 transition-transform">save</span>
                    <span class="text-[--text-main]">Save Changes</span>
                </button>

                <button type="button" onclick="toggleSuperadminModal(true)"
                    class="bg-primary hover:bg-primary/90 px-8 h-[46px] rounded-xl text-[10px] font-black uppercase tracking-widest transition-all shadow-lg shadow-primary/20 flex items-center gap-3 active:scale-95 group shrink-0">
                    <span
                        class="material-symbols-outlined text-lg text-[--highlight] group-hover:scale-110 transition-transform">person_add</span>
                    <span class="text-[--text-main]">Create Superadmin</span>
                </button>
            </div>

            <form action="" method="POST" enctype="multipart/form-data" class="space-y-8">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                    <!-- Global Customization Section -->
                    <div class="glass-card p-8 h-full">
                        <div class="flex items-center justify-between mb-8 text-primary">
                            <div class="flex items-center gap-4">
                                <div class="size-10 rounded-xl bg-primary/10 flex items-center justify-center">
                                    <span class="material-symbols-outlined text-primary">brush</span>
                                </div>
                                <div>
                                    <h3 class="text-sm font-black italic uppercase tracking-widest text-primary">System
                                        Appearance</h3>
                                    <p
                                        class="text-[10px] text-[--text-main] opacity-70 font-bold uppercase tracking-tight line-clamp-1">
                                        Brand identity & glassmorphism</p>
                                </div>
                            </div>
                            <button type="button" onclick="resetToDefaults()"
                                class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-white/5 border border-white/10 text-[--text-main] hover:text-white hover:bg-white/10 transition-all group shrink-0">
                                <span
                                    class="material-symbols-outlined text-sm group-hover:rotate-180 transition-transform duration-500">undo</span>
                                <span class="text-[9px] font-black uppercase tracking-wider">Reset</span>
                            </button>
                        </div>

                        <div class="space-y-8">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div class="flex flex-col gap-1.5">
                                    <label
                                        class="text-[9px] font-black uppercase text-[--text-main] opacity-70 tracking-widest ml-1">System
                                        Name</label>
                                    <input type="text" id="system_name_input" name="system_name" class="input-field"
                                        value="<?= htmlspecialchars($configs['system_name'] ?? 'Horizon System') ?>"
                                        readonly>
                                </div>
                                <div class="flex flex-col gap-1.5">
                                    <label
                                        class="text-[9px] font-black uppercase text-[--text-main] opacity-70 tracking-widest ml-1">Font
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

                            <div class="grid grid-cols-2 gap-6">
                                <div class="flex flex-col gap-1.5">
                                    <label
                                        class="text-[9px] font-black uppercase text-[--text-main] opacity-70 tracking-widest ml-1">Main
                                        Color</label>
                                    <div
                                        class="flex items-center gap-4 bg-white/5 p-2 rounded-xl border border-white/5">
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
                                        class="text-[9px] font-black uppercase text-[--text-main] opacity-70 tracking-widest ml-1">Icon
                                        Color</label>
                                    <div
                                        class="flex items-center gap-4 bg-white/5 p-2 rounded-xl border border-white/5">
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
                                        class="text-[9px] font-black uppercase text-[--text-main] opacity-70 tracking-widest ml-1">Text
                                        Color</label>
                                    <div
                                        class="flex items-center gap-4 bg-white/5 p-2 rounded-xl border border-white/5">
                                        <input type="color" id="text_color_input" name="text_color"
                                            oninput="updateLiveBranding()"
                                            value="<?= htmlspecialchars($configs['text_color'] ?? '#d1d5db') ?>"
                                            class="size-10 rounded-lg cursor-pointer bg-transparent border-none">
                                        <span id="text_hex_display"
                                            class="text-[10px] font-black uppercase text-gray-400"><?= $configs['text_color'] ?? '#D1D5DB' ?></span>
                                    </div>
                                </div>
                                <div class="flex flex-col gap-1.5">
                                    <label
                                        class="text-[9px] font-black uppercase text-[--text-main] opacity-70 tracking-widest ml-1">Background</label>
                                    <div
                                        class="flex items-center gap-4 bg-white/5 p-2 rounded-xl border border-white/5">
                                        <input type="color" id="bg_color_input" name="bg_color"
                                            oninput="updateLiveBranding()"
                                            value="<?= htmlspecialchars($configs['bg_color'] ?? '#0a090d') ?>"
                                            class="size-10 rounded-lg cursor-pointer bg-transparent border-none">
                                        <span id="bg_hex_display"
                                            class="text-[10px] font-black uppercase text-gray-400"><?= $configs['bg_color'] ?? '#0A090D' ?></span>
                                    </div>
                                </div>
                            </div>

                            <!-- Card Appearance Section -->
                            <div class="mt-6 pt-6 border-t border-white/5 space-y-4">
                                <div class="flex items-center justify-between">
                                    <h4 class="text-[9px] font-black uppercase tracking-[0.2em] text-primary">Card
                                        Appearance</h4>
                                    <label class="flex items-center gap-3 cursor-pointer group">
                                        <span
                                            class="text-[8px] font-bold uppercase tracking-widest text-[--text-main] opacity-70 group-hover:text-primary transition-colors">Sync
                                            Theme</span>
                                        <div class="relative inline-flex items-center">
                                            <input type="hidden" name="auto_card_theme" value="0">
                                            <input type="checkbox" id="auto_card_theme_input" name="auto_card_theme"
                                                value="1" onchange="updateLiveBranding()"
                                                <?= ($configs['auto_card_theme'] ?? '1') === '1' ? 'checked' : '' ?>
                                                class="sr-only peer">
                                            <div
                                                class="w-10 h-5 bg-white/5 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white/20 after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-primary/30 peer-checked:after:bg-primary transition-all border border-white/5">
                                            </div>
                                        </div>
                                    </label>
                                </div>

                                <div class="grid grid-cols-2 gap-6">
                                    <div class="flex flex-col gap-1.5">
                                        <label
                                            class="text-[9px] font-black uppercase text-[--text-main] opacity-70 tracking-widest ml-1">Surface
                                            Color</label>
                                        <div
                                            class="flex items-center gap-4 bg-white/5 p-2 rounded-xl border border-white/5">
                                            <input type="color" id="card_color_input" name="card_color"
                                                oninput="updateLiveBranding()"
                                                value="<?= htmlspecialchars($configs['card_color'] ?? '#141216') ?>"
                                                class="size-10 rounded-lg cursor-pointer bg-transparent border-none">
                                            <span id="card_hex_display"
                                                class="text-[10px] font-black uppercase text-gray-400"><?= $configs['card_color'] ?? '#141216' ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Gym Rules Section -->
                    <div class="glass-card p-8 h-full">
                        <div class="flex items-center gap-4 mb-8">
                            <div class="size-10 rounded-xl bg-primary/10 flex items-center justify-center">
                                <span class="material-symbols-outlined text-[--highlight]">gavel</span>
                            </div>
                            <div>
                                <h3 class="text-sm font-black italic uppercase tracking-widest text-primary">
                                    Rules for Gyms
                                </h3>
                                <p class="text-[10px] text-[--text-main] opacity-70 font-bold uppercase tracking-tight">
                                    Set global limits for all accounts</p>
                            </div>
                        </div>

                        <div class="space-y-8">
                            <div class="flex flex-col gap-1.5">
                                <label
                                    class="text-[9px] font-black uppercase text-[--text-main] opacity-70 tracking-widest ml-1">Max
                                    Staff
                                    Count</label>
                                <input type="number" name="max_staff" class="input-field"
                                    value="<?= htmlspecialchars($configs['max_staff'] ?? '10') ?>">
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label
                                    class="text-[9px] font-black uppercase text-[--text-main] opacity-70 tracking-widest ml-1">Wait
                                    Time
                                    (Days)</label>
                                <input type="number" name="grace_period" class="input-field"
                                    value="<?= htmlspecialchars($configs['grace_period'] ?? '7') ?>">
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label
                                    class="text-[9px] font-black uppercase text-[--text-main] opacity-70 tracking-widest ml-1">Account
                                    Status</label>
                                <select name="default_status" class="input-field cursor-pointer">
                                    <option value="Pending" <?= ($configs['default_status'] ?? '') === 'Pending' ? 'selected' : '' ?>>Pending Approval</option>
                                    <option value="Active" <?= ($configs['default_status'] ?? '') === 'Active' ? 'selected' : '' ?>>Auto-Approved</option>
                                </select>
                            </div>
                        </div>

                        <div class="mt-12 p-6 rounded-2xl bg-primary/5 border border-primary/10">
                            <div class="flex items-center gap-3 mb-3">
                                <span class="material-symbols-outlined text-primary text-base">info</span>
                                <span class="text-[10px] font-black uppercase tracking-widest text-primary">Policy
                                    info</span>
                            </div>
                            <p class="text-[10px] text-[--text-main] opacity-60 leading-relaxed font-medium">These rules
                                apply globally to ALL tenants in the system. Changes here affect account creation and
                                staff limitations across the entire platform.</p>
                        </div>
                    </div>
                </div>

                <div class="pt-6"></div>
            </form>
        </main>
    </div>

    <!-- START OF MODALS -->
    <div id="superadminModal" class="modal-overlay" onclick="if(event.target === this) toggleSuperadminModal(false)">
        <div class="glass-card w-full max-w-2xl p-0 relative overflow-hidden backdrop-blur-2xl">
            <button onclick="toggleSuperadminModal(false)"
                class="absolute top-8 right-8 size-10 rounded-xl bg-white/5 flex items-center justify-center text-[--secondary] hover:text-white transition-all z-20 hover:scale-110 active:scale-95">
                <span class="material-symbols-outlined text-xl">close</span>
            </button>

            <div class="modal-content-scroll p-10">
                <div class="flex items-center gap-4 mb-8">
                    <div class="size-12 rounded-2xl bg-primary/10 flex items-center justify-center">
                        <span class="material-symbols-outlined text-primary text-2xl">person_add</span>
                    </div>
                    <div>
                        <h3 class="text-xl font-black italic uppercase tracking-tighter">New <span
                                class="text-primary">Superadmin</span></h3>
                        <p class="text-[10px] text-[--secondary] opacity-70 font-bold uppercase tracking-widest">Give
                            admin access to
                            the system</p>
                    </div>
                </div>

                <form action="" method="POST" id="superadminCreationForm">
                    <input type="hidden" name="add_superadmin" value="1">
                    <div class="space-y-8">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div class="flex flex-col gap-1">
                                <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">First
                                    Name</label>
                                <input type="text" name="new_first_name" id="new_first_name" required
                                    class="input-field" placeholder="John">
                            </div>
                            <div class="flex flex-col gap-1">
                                <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Middle
                                    Name</label>
                                <input type="text" name="new_middle_name" id="new_middle_name" class="input-field"
                                    placeholder="Optional">
                            </div>
                            <div class="flex flex-col gap-1">
                                <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Last
                                    Name</label>
                                <input type="text" name="new_last_name" id="new_last_name" required class="input-field"
                                    placeholder="Doe">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="flex flex-col gap-1">
                                <label
                                    class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Username</label>
                                <input type="text" name="new_username" id="new_username" required class="input-field"
                                    placeholder="superadmin_dev">
                            </div>
                            <div class="flex flex-col gap-1">
                                <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Email
                                    Address</label>
                                <input type="email" name="new_email" id="new_email" required class="input-field"
                                    placeholder="example@gmail.com">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div class="flex flex-col gap-1">
                                <label
                                    class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Contact
                                    Number</label>
                                <input type="text" name="new_contact_number" id="new_contact_number" required
                                    class="input-field" placeholder="09XX-XXX-XXXX" oninput="formatContactNumber(this)">
                            </div>
                            <div class="flex flex-col gap-1">
                                <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Birth
                                    Date</label>
                                <input type="date" name="new_birth_date" id="new_birth_date" required
                                    class="input-field" max="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="flex flex-col gap-1">
                                <label
                                    class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Sex</label>
                                <select name="new_sex" id="new_sex" required class="input-field cursor-pointer">
                                    <option value="">Select Sex</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                        </div>

                        <div class="p-8 rounded-3xl bg-white/5 border border-white/5 space-y-6 mt-10 shadow-inner">
                            <h4
                                class="text-[10px] font-black text-primary uppercase tracking-widest flex items-center gap-2">
                                <span class="material-symbols-outlined text-base">security</span>
                                Security Protocol
                            </h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div class="flex flex-col gap-1">
                                    <label
                                        class="text-[9px] font-black uppercase text-[--text-main] opacity-70 tracking-widest ml-1">Password</label>
                                    <div class="relative group/pass">
                                        <input type="password" name="new_password" id="new_password" required
                                            class="input-field w-full pr-12" placeholder="Min. 8 characters"
                                            oninput="checkStrength(this.value)">
                                        <button type="button" onclick="togglePassword('new_password', 'icon_new')"
                                            class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-600 hover:text-white transition-colors">
                                            <span class="material-symbols-outlined text-lg"
                                                id="icon_new">visibility_off</span>
                                        </button>
                                    </div>
                                    <div class="h-1 w-full bg-white/5 rounded-full mt-2 overflow-hidden">
                                        <div id="strength-bar" class="h-full w-0 transition-all duration-300"></div>
                                    </div>
                                    <p id="strength-text"
                                        class="text-[8px] font-black uppercase tracking-widest mt-1 min-h-[10px]"></p>
                                    <div
                                        class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 mt-3 transition-all duration-500">
                                        <div id="req-length"
                                            class="flex items-center gap-2 text-gray-600 transition-colors">
                                            <span
                                                class="material-symbols-outlined text-sm">radio_button_unchecked</span>
                                            <span class="text-[8px] font-bold uppercase tracking-widest">Min. 8
                                                characters</span>
                                        </div>
                                        <div id="req-upper"
                                            class="flex items-center gap-2 text-gray-600 transition-colors">
                                            <span
                                                class="material-symbols-outlined text-sm">radio_button_unchecked</span>
                                            <span class="text-[8px] font-bold uppercase tracking-widest">One
                                                Uppercase</span>
                                        </div>
                                        <div id="req-number"
                                            class="flex items-center gap-2 text-gray-600 transition-colors">
                                            <span
                                                class="material-symbols-outlined text-sm">radio_button_unchecked</span>
                                            <span class="text-[8px] font-bold uppercase tracking-widest">One
                                                Number</span>
                                        </div>
                                        <div id="req-special"
                                            class="flex items-center gap-2 text-gray-600 transition-colors">
                                            <span
                                                class="material-symbols-outlined text-sm">radio_button_unchecked</span>
                                            <span class="text-[8px] font-bold uppercase tracking-widest">One Special
                                                Character</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex flex-col gap-1">
                                    <label
                                        class="text-[9px] font-black uppercase text-[--text-main] opacity-70 tracking-widest ml-1">Confirm
                                        Password</label>
                                    <div class="relative">
                                        <input type="password" name="confirm_new_password" id="confirm_new_password"
                                            required class="input-field w-full pr-12" placeholder="Repeat password">
                                        <button type="button"
                                            onclick="togglePassword('confirm_new_password', 'icon_confirm')"
                                            class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-600 hover:text-white transition-colors">
                                            <span class="material-symbols-outlined text-lg"
                                                id="icon_confirm">visibility_off</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end gap-4 pt-10">
                            <button type="button" onclick="toggleSuperadminModal(false)"
                                class="px-8 py-4 rounded-xl bg-white/5 text-[--secondary] text-[10px] font-black uppercase tracking-widest hover:bg-white/10 transition-all active:scale-95">Cancel</button>
                            <button type="button" onclick="validateSuperadminForm(event)"
                                class="px-10 py-4 rounded-xl bg-primary text-black text-[10px] font-black uppercase tracking-widest hover:opacity-90 transition-all shadow-lg shadow-primary/20 hover:scale-105 active:scale-95">Create
                                Account</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Review Confirmation Modal -->
    <div id="superadminReviewModal" class="modal-overlay" onclick="if(event.target === this) toggleReviewModal(false)">
        <div class="glass-card w-full max-w-lg p-10 relative overflow-hidden backdrop-blur-2xl">
            <div class="flex items-center gap-4 mb-8">
                <div class="size-12 rounded-2xl bg-amber-500/10 flex items-center justify-center">
                    <span class="material-symbols-outlined text-amber-500 text-2xl">fact_check</span>
                </div>
                <div>
                    <h3 class="text-xl font-black italic uppercase tracking-tighter text-white">Review Details</h3>
                    <p class="text-[10px] text-[--secondary] opacity-70 font-bold uppercase tracking-widest">Confirm
                        Information
                        Integrity</p>
                </div>
            </div>
            <div class="space-y-4 mb-10" id="reviewDetailsContainer"></div>
            <div class="p-6 rounded-2xl bg-primary/5 border border-primary/10 mb-10 flex items-start gap-4">
                <span class="material-symbols-outlined text-primary text-lg">info</span>
                <p class="text-[10px] text-gray-400 font-medium leading-relaxed">Confirming these details will create
                    the account and automatically send an email with login credentials to the recipient.</p>
            </div>
            <div class="flex justify-end gap-4">
                <button type="button" onclick="toggleReviewModal(false)"
                    class="px-8 py-4 rounded-xl bg-white/5 text-[--secondary] text-[10px] font-black uppercase tracking-widest hover:bg-white/10 transition-all active:scale-95">Back
                    to Edit</button>
                <button type="button" onclick="confirmAndSubmitSuperadmin()"
                    class="px-10 py-4 rounded-xl bg-primary text-black text-[10px] font-black uppercase tracking-widest hover:opacity-90 transition-all shadow-lg shadow-primary/20 hover:scale-105 active:scale-95">Final
                    Confirm</button>
            </div>
        </div>
    </div>

    <!-- Configuration Modal -->
    <div id="confirmActionModal" class="modal-overlay" onclick="if(event.target === this) toggleActionModal(false)">
        <div class="glass-card w-full max-w-lg p-10 relative text-center">
            <div class="size-16 rounded-2xl bg-primary/10 flex items-center justify-center mx-auto mb-6">
                <span id="confirmIcon" class="material-symbols-outlined text-primary text-3xl">warning</span>
            </div>
            <h3 id="confirmTitle" class="text-xl font-black italic uppercase tracking-tighter text-white mb-2">Confirm
                Changes</h3>
            <p id="confirmMessage"
                class="text-[10px] text-[--secondary] opacity-70 font-bold uppercase tracking-widest mb-10 leading-relaxed">
                Save these
                configurations to the system? This will update the appearance for all users immediately.</p>
            <div class="flex justify-center gap-4">
                <button type="button" onclick="toggleActionModal(false)"
                    class="px-8 py-3 rounded-xl bg-white/5 text-[--secondary] text-[10px] font-black uppercase tracking-widest hover:bg-white/10 transition-all">Cancel</button>
                <button id="confirmExecuteBtn" type="button"
                    class="px-10 py-3 rounded-xl bg-primary text-black text-[10px] font-black uppercase tracking-widest hover:bg-primary/90 hover:shadow-primary/30 transition-all shadow-lg shadow-primary/20">Confirm
                    Action</button>
            </div>
        </div>
    </div>
    <!-- END OF MODALS -->
    <script>
        function toggleSuperadminModal(show) {
            const modal = document.getElementById('superadminModal');
            if (modal) {
                modal.classList.toggle('active', show);
                document.body.style.overflow = show ? 'hidden' : 'auto';
            }
        }

        function togglePassword(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            if (input.type === "password") {
                input.type = "text";
                icon.innerText = "visibility";
            } else {
                input.type = "password";
                icon.innerText = "visibility_off";
            }
        }

        function formatContactNumber(input) {
            let val = input.value.replace(/\D/g, '');
            if (val.length > 11) val = val.substring(0, 11);

            let formatted = '';
            if (val.length > 0) {
                formatted += val.substring(0, 4);
                if (val.length > 4) {
                    formatted += '-' + val.substring(4, 7);
                    if (val.length > 7) {
                        formatted += '-' + val.substring(7, 11);
                    }
                }
            }
            input.value = formatted;
        }

        function checkStrength(password) {
            const bar = document.getElementById('strength-bar');
            const text = document.getElementById('strength-text');

            const requirements = {
                length: { el: document.getElementById('req-length'), met: password.length >= 8 },
                upper: { el: document.getElementById('req-upper'), met: /[A-Z]/.test(password) },
                number: { el: document.getElementById('req-number'), met: /[0-9]/.test(password) },
                special: { el: document.getElementById('req-special'), met: /[^A-Za-z0-9]/.test(password) }
            };

            let strength = 0;
            Object.values(requirements).forEach(req => {
                const icon = req.el.querySelector('.material-symbols-outlined');
                if (req.met) {
                    strength += 25;
                    req.el.classList.remove('text-gray-600');
                    req.el.classList.add('text-emerald-500');
                    icon.innerText = 'check_circle';
                } else {
                    req.el.classList.add('text-gray-600');
                    req.el.classList.remove('text-emerald-500');
                    icon.innerText = 'radio_button_unchecked';
                }
            });

            bar.style.width = strength + '%';

            if (strength === 0) {
                bar.className = 'h-full transition-all duration-300';
                text.innerText = '';
            } else if (strength <= 25) {
                bar.className = 'h-full bg-rose-500 transition-all duration-300';
                text.innerText = 'Weak';
                text.className = 'text-[8px] font-black uppercase tracking-widest mt-1 text-rose-500';
            } else if (strength <= 75) {
                bar.className = 'h-full bg-amber-500 transition-all duration-300';
                text.innerText = strength <= 50 ? 'Fair' : 'Good';
                text.className = 'text-[8px] font-black uppercase tracking-widest mt-1 text-amber-500';
            } else {
                bar.className = 'h-full bg-emerald-500 transition-all duration-300';
                text.innerText = 'Strong';
                text.className = 'text-[8px] font-black uppercase tracking-widest mt-1 text-emerald-500';
            }
        }

        function validateSuperadminForm(event) {
            const firstName = document.getElementById('new_first_name').value.trim();
            const middleName = document.getElementById('new_middle_name').value.trim();
            const lastName = document.getElementById('new_last_name').value.trim();
            const username = document.getElementById('new_username').value.trim();
            const email = document.getElementById('new_email').value.trim().toLowerCase();
            const contact = document.getElementById('new_contact_number').value.trim();
            const birthDate = document.getElementById('new_birth_date').value;
            const sex = document.getElementById('new_sex').value;
            const password = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_new_password').value;

            // Helper to show error
            const showError = (msg) => {
                showActionModal('Validation Error', msg, 'error', null, true);
            };

            // Basic Field Validation
            if (!username || !firstName || !lastName || !email || !contact || !password) {
                showError("Please fill out all required fields.");
                return;
            }

            // 1. Name Validation
            const nameRegex = /^[a-zA-Z\s]*$/;
            if (!nameRegex.test(firstName) || (middleName && !nameRegex.test(middleName)) || !nameRegex.test(lastName)) {
                showError("Names cannot contain numbers or special characters.");
                return;
            }

            // 2. Email Validation
            if (!email.endsWith('@gmail.com')) {
                showError("Please use a valid @gmail.com email address.");
                return;
            }

            // 3. Contact Validation
            const rawContact = contact.replace(/\D/g, '');
            if (rawContact.length !== 11) {
                showError("Contact number must be exactly 11 digits.");
                return;
            }

            // 4. Birth Date Validation
            const today = new Date().toISOString().split('T')[0];
            if (birthDate > today) {
                showError("Birth date cannot be in the future.");
                return;
            }

            // 5. Password Validation
            if (password.length < 8) {
                showError("Password must be at least 8 characters long.");
                return;
            }
            if (password !== confirmPassword) {
                showError("Passwords do not match.");
                return;
            }

            // If everything passes, show the review modal
            populateReviewModal({
                "Username": username,
                "Full Name": `${firstName} ${middleName} ${lastName}`,
                "Email": email,
                "Contact": contact,
                "Birth Date": birthDate,
                "Sex": sex
            });
            toggleReviewModal(true);
        }

        function populateReviewModal(data) {
            const container = document.getElementById('reviewDetailsContainer');
            container.innerHTML = '';
            for (const [label, value] of Object.entries(data)) {
                const row = document.createElement('div');
                row.className = 'flex justify-between items-center py-2 border-b border-white/5';
                row.innerHTML = `
                        <span class="text-[9px] text-[--secondary] opacity-70 font-bold uppercase tracking-widest">${label}</span>
                        <span class="text-[10px] text-white font-black italic uppercase tracking-tighter">${value || 'N/A'}</span>
                    `;
                container.appendChild(row);
            }
        }

        function toggleReviewModal(show) {
            const modal = document.getElementById('superadminReviewModal');
            if (modal) {
                modal.classList.toggle('active', show);
                document.body.style.overflow = show ? 'hidden' : 'auto';
            }
        }

        function confirmAndSubmitSuperadmin() {
            const form = document.getElementById('superadminCreationForm');
            if (form) {
                // Change the button state to show loading if needed
                form.submit();
            }
        }

        function resetToDefaults() {
            showActionModal(
                'Reset Branding',
                'Revert branding to system defaults? Unsaved progress will be lost.',
                'brush',
                () => {
                    const defaults = {
                        'system_name_input': 'Horizon System',
                        'theme_color_input': '#8C2BEE',
                        'secondary_color_input': '#A1A1AA',
                        'text_color_input': '#D1D5DB',
                        'bg_color_input': '#0A090D',
                        'card_color_input': '#141216',
                        'font_family_input': 'Lexend'
                    };

                    for (const [id, value] of Object.entries(defaults)) {
                        const el = document.getElementById(id);
                        if (el) el.value = value;
                    }

                    const autoSync = document.getElementById('auto_card_theme_input');
                    if (autoSync) autoSync.checked = true;

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

        function showActionModal(title, message, icon, onConfirm, isError = false) {
            document.getElementById('confirmTitle').textContent = title;
            document.getElementById('confirmMessage').textContent = message;
            document.getElementById('confirmIcon').textContent = icon === 'error' ? 'warning' : icon;

            const iconContainer = document.getElementById('confirmIcon').parentElement;
            const confirmBtn = document.getElementById('confirmExecuteBtn');

            if (isError) {
                iconContainer.classList.add('bg-rose-500/10');
                iconContainer.classList.remove('bg-primary/10');
                document.getElementById('confirmIcon').classList.add('text-rose-500');
                document.getElementById('confirmIcon').classList.remove('text-primary');

                confirmBtn.textContent = "Okay, I'll fix it";
                confirmBtn.onclick = () => toggleActionModal(false);
            } else {
                iconContainer.classList.add('bg-primary/10');
                iconContainer.classList.remove('bg-rose-500/10');
                document.getElementById('confirmIcon').classList.add('text-primary');
                document.getElementById('confirmIcon').classList.remove('text-rose-500');

                confirmBtn.textContent = "Confirm Action";
                confirmBtn.onclick = onConfirm;
            }

            toggleActionModal(true);
        }

        function toggleActionModal(show = true) {
            const modal = document.getElementById('confirmActionModal');
            if (modal) {
                modal.classList.toggle('active', show);
                document.body.style.overflow = show ? 'hidden' : 'auto';
            }
        }

        // --- Live Branding Engine ---
        function hexToRgb(hex) {
            const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
            return result ? {
                r: parseInt(result[1], 16),
                g: parseInt(result[2], 16),
                b: parseInt(result[3], 16)
            } : null;
        }

        function updateLiveBranding() {
            const root = document.documentElement;
            // Fetch All Branding Inputs
            const themeInput = document.getElementById('theme_color_input');
            const secondaryInput = document.getElementById('secondary_color_input');
            const textColorInput = document.getElementById('text_color_input');
            const bgInput = document.getElementById('bg_color_input');
            const fontInput = document.getElementById('font_family_input');
            const nameInput = document.getElementById('system_name_input');
            const isAutoCard = document.getElementById('auto_card_theme_input').checked;
            const cardColorInput = document.getElementById('card_color_input');

            if (!themeInput || !secondaryInput || !textColorInput || !bgInput) return;

            // 1. Update Core CSS Variables
            root.style.setProperty('--primary', themeInput.value);
            root.style.setProperty('--highlight', secondaryInput.value);
            root.style.setProperty('--text-main', textColorInput.value);
            root.style.setProperty('--background', bgInput.value);
            document.body.style.fontFamily = `'${fontInput.value}', sans-serif`;

            // 2. Update Sidebar & Clock Live
            const sidebarName = document.getElementById('sidebarSystemName');
            if (sidebarName && nameInput) sidebarName.textContent = nameInput.value;

            const headerClock = document.getElementById('headerClock');
            if (headerClock) headerClock.style.color = textColorInput.value;

            // 3. Generate RGB for Dynamic Transparency (Glassmorphism)
            const rgb = hexToRgb(themeInput.value);
            if (rgb) {
                const rgbVal = `${rgb.r}, ${rgb.g}, ${rgb.b}`;
                root.style.setProperty('--primary-rgb', rgbVal);

                // 4. Card Sync Logic (Auto vs Manual)
                if (isAutoCard) {
                    const autoCardColor = `rgba(${rgbVal}, 0.05)`;
                    root.style.setProperty('--card-bg', autoCardColor);
                    
                    // In Auto mode, we update the manual color picker to show current theme
                    if (cardColorInput) {
                        cardColorInput.value = themeInput.value;
                        document.getElementById('card_hex_display').innerText = themeInput.value.toUpperCase();
                        cardColorInput.parentElement.parentElement.style.opacity = '0.4';
                        cardColorInput.parentElement.parentElement.style.pointerEvents = 'none';
                    }
                } else if (cardColorInput) {
                    root.style.setProperty('--card-bg', cardColorInput.value);
                    document.getElementById('card_hex_display').innerText = cardColorInput.value.toUpperCase();
                    cardColorInput.parentElement.parentElement.style.opacity = '1';
                    cardColorInput.parentElement.parentElement.style.pointerEvents = 'auto';
                }
            }

            // 5. Update Hex Display Labels
            document.getElementById('theme_hex_display').innerText = themeInput.value.toUpperCase();
            document.getElementById('secondary_hex_display').innerText = secondaryInput.value.toUpperCase();
            document.getElementById('text_hex_display').innerText = textColorInput.value.toUpperCase();
            document.getElementById('bg_hex_display').innerText = bgInput.value.toUpperCase();
        }

        // --- Initialization & Lifecycle ---
        document.addEventListener('DOMContentLoaded', () => {
            // Apply initial branding state
            updateLiveBranding();

            // Auto-hide success alerts
            const successAlert = document.getElementById('successAlert');
            if (successAlert) {
                setTimeout(() => {
                    successAlert.style.opacity = '0';
                    successAlert.style.transform = 'translateY(-10px)';
                    setTimeout(() => successAlert.remove(), 500);
                }, 10000);
            }

            // Error handling for Superadmin Creation
            const errorMsg = "<?= addslashes($error_msg ?? '') ?>";
            if (errorMsg && errorMsg.includes('creating account')) {
                toggleSuperadminModal(true);
            }
        });

        function resetToDefaults() {
            showActionModal(
                'Reset Branding',
                'Restore theme and colors to system defaults? This will erase your current customizations.',
                'undo',
                () => {
                    document.getElementById('theme_color_input').value = '#8c2bee';
                    document.getElementById('secondary_color_input').value = '#a1a1aa';
                    document.getElementById('text_color_input').value = '#d1d5db';
                    document.getElementById('bg_color_input').value = '#0a090d';
                    document.getElementById('font_family_input').value = 'Lexend';
                    
                    const isAutoInput = document.getElementById('auto_card_theme_input');
                    if (isAutoInput) isAutoInput.checked = true;

                    updateLiveBranding();
                    toggleActionModal(false);
                }
            );
        }
    </script>
</body>
</html>
    </script>
</body>

</html>