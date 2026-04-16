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

// Handle Immediate Plan Actions (Archive/Restore)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['immediate_plan_action'])) {
    try {
        $plan_id = intval($_POST['target_plan_id']);
        $action = $_POST['immediate_plan_action'];

        if ($action === 'archive') {
            $stmt = $pdo->prepare("UPDATE website_plans SET is_active = 0 WHERE website_plan_id = ?");
            $stmt->execute([$plan_id]);
            $_SESSION['success_msg'] = "Plan successfully archived immediately!";
        } elseif ($action === 'restore') {
            $stmt = $pdo->prepare("UPDATE website_plans SET is_active = 1 WHERE website_plan_id = ?");
            $stmt->execute([$plan_id]);
            $_SESSION['success_msg'] = "Plan successfully restored immediately!";
        }
    } catch (Exception $e) {
        $_SESSION['error_msg'] = "Immediate action failed: " . $e->getMessage();
    }

    header("Location: " . $_SERVER['PHP_SELF'] . "?tab=plans");
    exit;
}

// Pull messages from session if exists
if (isset($_SESSION['success_msg'])) {
    $success_msg = $_SESSION['success_msg'];
    unset($_SESSION['success_msg']);
}
if (isset($_SESSION['error_msg'])) {
    $error_msg = $_SESSION['error_msg'];
    unset($_SESSION['error_msg']);
}

// Current Active Tab Detection for Zero-Flash Rendering
$current_tab = $_GET['tab'] ?? ($_POST['active_tab'] ?? 'look');

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
    ['bg_color', '#0a090d'],
    ['tab_active_text', '#ffffff'],
    ['auto_tab_sync', '1']
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

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    unset($_POST['save_settings']);
    $pdo->beginTransaction();
    try {

        // 2. Handle EXISTING Plans Update (Metadata Only)
        if (isset($_POST['plans']) && is_array($_POST['plans'])) {
            $stmtUpdatePlan = $pdo->prepare("UPDATE website_plans SET plan_name = ?, price = ?, billing_cycle = ?, duration_months = ?, badge_text = ? WHERE website_plan_id = ?");
            $stmtDelFeatures = $pdo->prepare("DELETE FROM website_plan_features WHERE website_plan_id = ?");
            $stmtInsFeature = $pdo->prepare("INSERT INTO website_plan_features (website_plan_id, feature_name) VALUES (?, ?)");

            foreach ($_POST['plans'] as $id => $data) {
                $stmtUpdatePlan->execute([
                    $data['name'],
                    $data['price'],
                    $data['billing'],
                    $data['duration'],
                    $data['badge_text'] ?? null,
                    $id
                ]);

                // Sync Features
                $stmtDelFeatures->execute([$id]);
                $featuresList = array_filter(array_map('trim', explode(',', $data['features'])));
                foreach ($featuresList as $fName) {
                    $stmtInsFeature->execute([$id, $fName]);
                }
            }
        }

        // 2.5 Handle ARCHIVE / UNARCHIVE Changes
        if (isset($_POST['delete_plans']) && is_array($_POST['delete_plans'])) {
            $stmtDeactivate = $pdo->prepare("UPDATE website_plans SET is_active = 0 WHERE website_plan_id = ?");
            foreach ($_POST['delete_plans'] as $del_id) {
                $stmtDeactivate->execute([$del_id]);
            }
        }

        if (isset($_POST['unarchive_plans']) && is_array($_POST['unarchive_plans'])) {
            $stmtReactivate = $pdo->prepare("UPDATE website_plans SET is_active = 1 WHERE website_plan_id = ?");
            foreach ($_POST['unarchive_plans'] as $un_id) {
                $stmtReactivate->execute([$un_id]);
            }
        }

        // 3. Handle NEW Plans Creation
        if (isset($_POST['new_plans']) && is_array($_POST['new_plans'])) {
            $stmtInsPlan = $pdo->prepare("INSERT INTO website_plans (plan_name, price, billing_cycle, duration_months, badge_text, is_active) VALUES (?, ?, ?, ?, ?, 1)");
            $stmtInsFeature = $pdo->prepare("INSERT INTO website_plan_features (website_plan_id, feature_name) VALUES (?, ?)");

            foreach ($_POST['new_plans'] as $data) {
                if (empty($data['name']))
                    continue; // Skip empty new plan fields

                $stmtInsPlan->execute([
                    $data['name'],
                    $data['price'],
                    $data['billing'],
                    $data['duration'],
                    $data['badge_text'] ?? null
                ]);
                $new_id = $pdo->lastInsertId();

                $featuresList = array_filter(array_map('trim', explode(',', $data['features'])));
                foreach ($featuresList as $fName) {
                    $stmtInsFeature->execute([$new_id, $fName]);
                }
            }
        }

        // 4. Handle Global & User Settings (Theme, Names, etc.)
        $stmtUpdate = $pdo->prepare("INSERT INTO system_settings (user_id, setting_key, setting_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");

        foreach ($_POST as $key => $value) {
            if (is_array($value) || in_array($key, ['delete_plans', 'unarchive_plans', 'perm_delete_plans']))
                continue;

            $scope_id = in_array($key, $global_keys) ? 0 : $_SESSION['user_id'];
            $stmtUpdate->execute([$scope_id, $key, $value]);
            $configs[$key] = $value;
        }

        $pdo->commit();
        $success_msg = "System configurations updated successfully!";
    } catch (Exception $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        $error_msg = $e->getMessage();
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

        if (strtolower($username) === strtolower($email)) {
            throw new Exception("Username and Email address cannot be the same for security reasons.");
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

// Fetch and Merge Settings (Fetch AFTER potential updates for UI consistency)
// 1. Fetch Global Settings
$stmtGlobal = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE user_id = 0");
$global_configs = $stmtGlobal->fetchAll(PDO::FETCH_KEY_PAIR);

// 2. Fetch User-Specific Settings
$stmtUser = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = ?");
$stmtUser->execute([$_SESSION['user_id']]);
$user_configs = $stmtUser->fetchAll(PDO::FETCH_KEY_PAIR);

// 3. Merge (User settings take precedence for overlapping keys if any)
$configs = array_merge($global_configs, $user_configs);

// 4. Fetch Website Plans (3NF Compatibility)
$stmtPlans = $pdo->prepare("SELECT * FROM website_plans ORDER BY website_plan_id DESC");
$stmtPlans->execute();
$all_plans = $stmtPlans->fetchAll();

$active_website_plans = [];
$archived_website_plans = [];

foreach ($all_plans as $p) {
    $stmtF = $pdo->prepare("SELECT feature_name FROM website_plan_features WHERE website_plan_id = ?");
    $stmtF->execute([$p['website_plan_id']]);
    $p['features'] = implode(', ', $stmtF->fetchAll(PDO::FETCH_COLUMN));

    if (($p['is_active'] ?? 1) == 1) {
        $active_website_plans[] = $p;
    } else {
        $archived_website_plans[] = $p;
    }
}

// Ensure Active Plans stay sorted by Price (Lowest to Highest) as requested
usort($active_website_plans, fn($a, $b) => $a['price'] <=> $b['price']);

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
            --tab-active-text:
                <?= $configs['tab_active_text'] ?? '#ffffff' ?>
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
            flex: 1;
            min-width: 0;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
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
            transition: border-color 0.2s, background-color 0.2s, box-shadow 0.2s, opacity 0.2s;
            backdrop-filter: blur(10px);
        }

        /* Dynamic Input Field Interaction */
        .input-field:hover {
            border-color: rgba(255, 255, 255, 0.2);
            background-color: rgba(255, 255, 255, 0.08);
        }

        .input-field:focus {
            border-color: var(--primary);
            outline: none;
            background-color: rgba(var(--primary-rgb), 0.05);
            /* Requires RGB variable */
            box-shadow: 0 0 20px rgba(var(--primary-rgb), 0.1);
        }

        .input-field option {
            background-color: #0d0c12;
            color: white;
        }

        .input-field:read-only:not(select) {
            cursor: not-allowed;
            opacity: 0.6;
            background: rgba(255, 255, 255, 0.02);
        }

        /* Improved Dropdown Visibility */
        select.input-field {
            appearance: none;
            -webkit-appearance: none;
            color-scheme: dark;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='white' stroke-opacity='0.5'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 1em;
            padding-right: 2.5rem;
            cursor: pointer !important;
        }

        #superadminModal,
        #superadminReviewModal,
        #confirmActionModal,
        #planViewModal {
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
        #confirmActionModal.active,
        #planViewModal.active {
            display: flex !important;
        }

        .sidebar-nav:hover~#superadminModal,
        .sidebar-nav:hover~#superadminReviewModal,
        .sidebar-nav:hover~#confirmActionModal,
        .sidebar-nav:hover~#planViewModal {
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

        /* Plan Card View Mode Styles */
        .plan-edit-state {
            display: none;
        }

        .glass-card.is-editing .plan-edit-state {
            display: block;
        }

        .glass-card.is-editing .plan-preview-state {
            display: none;
        }

        .view-box {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 0.75rem;
            padding: 0.85rem 1.25rem;
            color: white;
            font-size: 0.875rem;
            font-weight: 600;
            min-height: 48px;
            display: flex;
            align-items: center;
        }

        .view-box-long {
            align-items: flex-start;
            line-height: 1.6;
            min-height: 100px;
        }
    </style>
    <script>
        function updateHeaderClock() {
            const clockEl = document.getElementById('headerClock');
            if (clockEl) { clockEl.textContent = new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' }); }
        }
        setInterval(updateHeaderClock, 1000);
    </script>
</head>

<body class="antialiased flex flex-row min-h-screen">

    <?php include '../includes/superadmin_sidebar.php'; ?>

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
                        class="text-[--text-main] text-[12px] font-bold uppercase tracking-widest mt-2 px-1 opacity-80 uppercase">
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



            <style>
                .tab-btn {
                    color: var(--text-main);
                    opacity: 0.5;
                }

                .tab-btn:hover {
                    opacity: 1;
                }

                .tab-btn.active {
                    background: var(--primary);
                    color: var(--tab-active-text, white);
                    opacity: 1;
                    box-shadow: 0 10px 20px rgba(var(--primary-rgb), 0.2);
                }

                .tab-content {
                    display: none;
                }

                .tab-content.active {
                    display: block;
                }
            </style>



            <?php if (isset($success_msg)): ?>
                <div id="successAlert"
                    class="mb-6 px-8 h-[46px] bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-[10px] font-black uppercase tracking-widest rounded-xl flex items-center justify-between transition-all duration-700 select-none">
                    <div class="flex items-center gap-3">
                        <span class="material-symbols-outlined text-sm text-emerald-500">check_circle</span>
                        <span><?= $success_msg ?></span>
                    </div>
                    <button type="button" onclick="this.parentElement.remove()"
                        class="text-emerald-500/50 hover:text-emerald-500 transition-colors p-2 shrink-0 outline-none">
                        <span class="material-symbols-outlined text-base">close</span>
                    </button>
                </div>
            <?php endif; ?>

            <?php if (isset($error_msg)): ?>
                <div id="errorAlert"
                    class="mb-6 px-8 h-[46px] bg-rose-500/10 border border-rose-500/20 text-rose-400 text-[10px] font-black uppercase tracking-widest rounded-xl flex items-center justify-between transition-all duration-700 select-none">
                    <div class="flex items-center gap-3">
                        <span class="material-symbols-outlined text-sm text-rose-500">warning</span>
                        <span><?= $error_msg ?></span>
                    </div>
                    <button type="button" onclick="this.parentElement.remove()"
                        class="text-rose-500/50 hover:text-rose-500 transition-colors p-2 shrink-0 outline-none">
                        <span class="material-symbols-outlined text-base">close</span>
                    </button>
                </div>
            <?php endif; ?>

            <div class="flex justify-between items-center mb-10 gap-6 transition-all">
                <!-- Tab Switcher -->
                <div
                    class="flex items-center gap-2 p-1 bg-white/5 border border-white/10 rounded-xl w-fit shrink-0 h-[46px]">
                    <button type="button" onclick="switchTab('look')" id="tab-look"
                        class="tab-btn <?= $current_tab === 'look' ? 'active' : '' ?> px-8 h-full rounded-lg text-[12px] font-black uppercase tracking-widest transition-all">
                        System Look
                    </button>
                    <button type="button" onclick="switchTab('plans')" id="tab-plans"
                        class="tab-btn <?= $current_tab === 'plans' ? 'active' : '' ?> px-8 h-full rounded-lg text-[12px] font-black uppercase tracking-widest transition-all">
                        System Plan
                    </button>
                </div>


                <div class="flex items-center gap-4 flex-1 justify-end">
                    <button type="button" onclick="confirmSaveConfigurations()"
                        class="bg-white/5 hover:bg-primary/20 px-8 h-[46px] rounded-xl text-[12px] font-black uppercase tracking-widest transition-all border border-white/10 hover:border-primary/30 flex items-center gap-3 active:scale-95 group shrink-0">
                        <span
                            class="material-symbols-outlined text-[--highlight] group-hover:text-white text-lg group-hover:scale-110 transition-transform">save</span>
                        <span class="text-[--text-main]">Save Changes</span>
                    </button>

                    <button type="button" onclick="toggleSuperadminModal(true)"
                        class="bg-primary hover:bg-primary/90 px-8 h-[46px] rounded-xl text-[12px] font-black uppercase tracking-widest transition-all border border-white/10 shadow-lg shadow-primary/20 flex items-center gap-3 active:scale-95 group shrink-0">
                        <span
                            class="material-symbols-outlined text-lg text-[--highlight] group-hover:scale-110 transition-transform">person_add</span>
                        <span class="text-[--text-main]">Create Superadmin</span>
                    </button>
                </div>
            </div>

            <form action="" method="POST" enctype="multipart/form-data" id="mainSettingsForm">
                <input type="hidden" name="active_tab" id="activeTabInput"
                    value="<?= htmlspecialchars($current_tab) ?>">
                <div id="content-look" class="tab-content <?= $current_tab === 'look' ? 'active' : '' ?> space-y-8">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                        <!-- Global Customization Section -->
                        <div class="glass-card p-8 h-full">
                            <div class="flex items-center justify-between mb-8 text-primary">
                                <div class="flex items-center gap-4">
                                    <div class="size-10 rounded-xl bg-primary/10 flex items-center justify-center">
                                        <span class="material-symbols-outlined text-[--highlight]">brush</span>
                                    </div>
                                    <div>
                                        <h3 class="text-sm font-black italic uppercase tracking-widest text-primary">
                                            System
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
                                    <span class="text-[11px] font-black uppercase tracking-wider">Reset</span>
                                </button>
                            </div>

                            <div class="space-y-8">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div class="flex flex-col gap-1.5">
                                        <label
                                            class="text-[11px] font-black uppercase text-[--text-main] opacity-70 tracking-widest ml-1">System
                                            Name</label>
                                        <input type="text" id="system_name_input" name="system_name"
                                            class="input-field !bg-white/[0.02] cursor-not-allowed opacity-60"
                                            value="<?= htmlspecialchars($configs['system_name'] ?? 'Horizon System') ?>"
                                            readonly>
                                    </div>
                                </div>

                                <div class="grid grid-cols-2 gap-6">
                                    <div class="flex flex-col gap-1.5">
                                        <label
                                            class="text-[11px] font-black uppercase text-[--text-main] opacity-70 tracking-widest ml-1">Main
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
                                            class="text-[11px] font-black uppercase text-[--text-main] opacity-70 tracking-widest ml-1">Icon
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
                                            class="text-[11px] font-black uppercase text-[--text-main] opacity-70 tracking-widest ml-1">Text
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
                                    <p
                                        class="text-[10px] text-[--text-main] opacity-70 font-bold uppercase tracking-tight">
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
                                    <span class="material-symbols-outlined text-[--highlight] text-lg">info</span>
                                    <span class="text-[12px] font-black uppercase tracking-widest text-primary">Policy
                                        info</span>
                                </div>
                                <p class="text-[12px] text-[--text-main] opacity-70 leading-relaxed font-bold">These
                                    rules
                                    apply globally to ALL tenants in the system. Changes here affect account creation
                                    and
                                    staff limitations across the entire platform.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="content-plans" class="tab-content <?= $current_tab === 'plans' ? 'active' : '' ?> space-y-8">
                    <div
                        class="flex flex-col lg:flex-row justify-between items-start lg:items-center bg-white/5 p-6 rounded-2xl border border-white/5 gap-6">
                        <div>
                            <h4 class="text-sm font-black italic uppercase tracking-widest text-white">System
                                Subscription Plans</h4>
                            <p class="text-[11px] text-gray-500 font-bold uppercase tracking-tight mt-1">Manage what
                                plans are available for new gyms</p>
                        </div>

                        <div class="flex items-center gap-3 w-full lg:w-auto">
                            <button type="button" id="addNewPlanBtn" onclick="addNewPlanCard()"
                                class="px-5 py-2 rounded-xl bg-primary/10 border border-white/10 text-primary text-[11px] font-black uppercase tracking-widest hover:bg-primary hover:text-white transition-all flex items-center gap-2 group">
                                <span
                                    class="material-symbols-outlined text-sm group-hover:scale-110 transition-transform">add_circle</span>
                                Add New Plan
                            </button>

                            <!-- View Toggle Switcher -->
                            <div
                                class="flex items-center gap-1 p-1 bg-white/5 border border-white/10 rounded-xl ml-auto">
                                <button type="button" id="planViewToggleActive" onclick="switchPlanView('active')"
                                    class="px-5 py-2 rounded-lg bg-primary text-[--tab-active-text] text-[11px] font-black uppercase tracking-widest transition-all">
                                    Active
                                </button>
                                <button type="button" id="planViewToggleArchived" onclick="switchPlanView('archived')"
                                    class="px-5 py-2 rounded-lg text-[--text-main] hover:text-white text-[11px] font-black uppercase tracking-widest transition-all opacity-70">
                                    Archived (<?= count($archived_website_plans) ?>)
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Active Plans Container -->
                    <div id="activePlansContainer" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                        <?php if (empty($active_website_plans)): ?>
                            <div class="col-span-full py-20 text-center opacity-30 animate-in fade-in duration-500">
                                <span class="text-[10px] font-black uppercase tracking-[0.2em]">No active website plans
                                    found</span>
                            </div>
                        <?php else: ?>
                            <?php foreach ($active_website_plans as $plan): ?>
                                <div class="glass-card p-8 flex flex-col gap-6 relative group/plan transition-all duration-300"
                                    id="plan-card-<?= $plan['website_plan_id'] ?>">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center gap-4">
                                            <div class="size-10 rounded-xl bg-primary/10 flex items-center justify-center">
                                                <span class="material-symbols-outlined text-primary">workspace_premium</span>
                                            </div>
                                            <h3
                                                class="text-sm font-black italic uppercase tracking-widest text-primary plan-title-preview">
                                                <?= htmlspecialchars($plan['plan_name']) ?>
                                            </h3>
                                        </div>
                                        <div class="flex items-center gap-2 transition-all">
                                            <button type="button" onclick="toggleEditPlan(<?= $plan['website_plan_id'] ?>)"
                                                class="size-8 rounded-lg bg-primary/10 text-primary flex items-center justify-center hover:bg-primary hover:text-white transition-all active:scale-95"
                                                title="Edit Plan">
                                                <span class="material-symbols-outlined text-base">edit</span>
                                            </button>
                                            <button type="button" onclick="markPlanForArchival(<?= $plan['website_plan_id'] ?>)"
                                                class="size-8 rounded-lg bg-primary/10 text-[--text-main] flex items-center justify-center hover:bg-primary hover:text-white transition-all active:scale-95"
                                                title="Archive Plan">
                                                <span class="material-symbols-outlined text-base">archive</span>
                                            </button>
                                        </div>
                                    </div>

                                    <!-- PREVIEW STATE: Structured View mirroring Edit Mode -->
                                    <div class="plan-preview-state space-y-4">
                                        <div class="flex flex-col gap-1.5">
                                            <label
                                                class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Plan
                                                Name</label>
                                            <div class="view-box text-white"><?= htmlspecialchars($plan['plan_name']) ?></div>
                                        </div>

                                        <div class="grid grid-cols-2 gap-4">
                                            <div class="flex flex-col gap-1.5">
                                                <label
                                                    class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Price
                                                    (₱)</label>
                                                <div class="view-box"><?= number_format($plan['price']) ?>.00</div>
                                            </div>
                                            <div class="flex flex-col gap-1.5">
                                                <label
                                                    class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Duration
                                                    (Months)</label>
                                                <div class="view-box"><?= htmlspecialchars($plan['duration_months']) ?></div>
                                            </div>
                                        </div>

                                        <div class="grid grid-cols-2 gap-4">
                                            <div class="flex flex-col gap-1.5">
                                                <label
                                                    class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Billing
                                                    Cycle Text</label>
                                                <div class="view-box"><?= htmlspecialchars($plan['billing_cycle']) ?></div>
                                            </div>
                                            <div class="flex flex-col gap-1.5">
                                                <label
                                                    class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Featured
                                                    Badge Text</label>
                                                <div class="view-box opacity-60 italic">
                                                    <?= !empty($plan['badge_text']) ? htmlspecialchars($plan['badge_text']) : 'None' ?>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="flex flex-col gap-1.5">
                                            <label
                                                class="text-[11px] font-black uppercase text-gray-500 tracking-widest ml-1">Features</label>
                                            <div class="view-box view-box-long text-gray-400 font-medium">
                                                <?= !empty($plan['features']) ? htmlspecialchars($plan['features']) : 'No features listed' ?>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- EDIT STATE: Hidden inputs -->
                                    <div class="plan-edit-state space-y-4">
                                        <div class="flex flex-col gap-1.5">
                                            <label
                                                class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Plan
                                                Name</label>
                                            <input type="text" name="plans[<?= $plan['website_plan_id'] ?>][name]"
                                                value="<?= htmlspecialchars($plan['plan_name']) ?>" class="input-field"
                                                oninput="this.closest('.glass-card').querySelector('.plan-title-preview').innerText = this.value">
                                        </div>
                                        <div class="grid grid-cols-2 gap-4">
                                            <div class="flex flex-col gap-1.5">
                                                <label
                                                    class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Price
                                                    (₱)</label>
                                                <input type="number" step="1"
                                                    name="plans[<?= $plan['website_plan_id'] ?>][price]"
                                                    value="<?= htmlspecialchars($plan['price']) ?>" class="input-field">
                                            </div>
                                            <div class="flex flex-col gap-1.5">
                                                <label
                                                    class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Duration
                                                    (Months)</label>
                                                <input type="number" name="plans[<?= $plan['website_plan_id'] ?>][duration]"
                                                    value="<?= htmlspecialchars($plan['duration_months']) ?>"
                                                    class="input-field">
                                            </div>
                                        </div>
                                        <div class="grid grid-cols-2 gap-4">
                                            <div class="flex flex-col gap-1.5">
                                                <label
                                                    class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Billing
                                                    Cycle Text</label>
                                                <input type="text" name="plans[<?= $plan['website_plan_id'] ?>][billing]"
                                                    value="<?= htmlspecialchars($plan['billing_cycle']) ?>" class="input-field">
                                            </div>
                                            <div class="flex flex-col gap-1.5">
                                                <label
                                                    class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Featured
                                                    Badge Text</label>
                                                <input type="text" name="plans[<?= $plan['website_plan_id'] ?>][badge_text]"
                                                    value="<?= htmlspecialchars($plan['badge_text'] ?? '') ?>"
                                                    class="input-field" placeholder="e.g. Most Popular">
                                            </div>
                                        </div>
                                        <div class="flex flex-col gap-1.5">
                                            <label
                                                class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Features
                                                (Comma-separated)</label>
                                            <textarea name="plans[<?= $plan['website_plan_id'] ?>][features]" rows="4"
                                                class="input-field no-scrollbar resize-none"><?= htmlspecialchars($plan['features']) ?></textarea>
                                        </div>
                                        <div class="pt-4 border-t border-white/5">
                                            <p class="text-[8px] font-black uppercase tracking-widest text-primary opacity-60">
                                                Manual save required via 'Save Changes'</p>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Archived Plans Container -->
                    <div id="archivedPlansContainer" class="hidden overflow-hidden glass-card">
                        <!-- Filter Bar Guide (Inspired by system_reports.php) -->
                        <div
                            class="px-8 py-6 border-b border-white/5 flex flex-col md:flex-row items-center gap-4 bg-white/[0.01]">
                            <div class="relative flex-1">
                                <span
                                    class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-gray-500 text-sm">search</span>
                                <input type="text" id="archivedPlanSearch" placeholder="Search by plan name..."
                                    oninput="filterArchivedPlans()" class="input-field w-full pl-12 !min-h-[44px]">
                            </div>
                            <div class="w-full lg:w-fit flex items-center gap-2">
                                <select id="archivedIDSort" onchange="filterArchivedPlans('id')"
                                    class="input-field w-full lg:w-72 !min-h-[44px] cursor-pointer">
                                    <option value="newest">Newest to Oldest</option>
                                    <option value="oldest">Oldest to Newest</option>
                                </select>

                                <select id="archivedPriceSort" onchange="filterArchivedPlans('price')"
                                    class="input-field w-full lg:w-72 !min-h-[44px] cursor-pointer">
                                    <option value="none">Sort by Price</option>
                                    <option value="price_low">Price (Lowest to Highest)</option>
                                    <option value="price_high">Price (Highest to Lowest)</option>
                                </select>

                                <button type="button" onclick="resetArchivedFilters()"
                                    class="size-[44px] rounded-xl bg-white/5 border border-white/5 flex items-center justify-center text-gray-500 hover:text-white transition-all group active:scale-95"
                                    title="Reset Filters">
                                    <span
                                        class="material-symbols-outlined text-sm group-hover:rotate-180 transition-transform duration-500">restart_alt</span>
                                </button>
                            </div>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse">
                                <thead>
                                    <tr class="border-b border-white/5 bg-white/[0.02]">
                                        <th
                                            class="px-8 py-4 text-[10px] font-black uppercase tracking-widest text-gray-500/80">
                                            Plan Name</th>
                                        <th
                                            class="px-8 py-4 text-[10px] font-black uppercase tracking-widest text-gray-500/80 text-center">
                                            Price (₱)</th>
                                        <th
                                            class="px-8 py-4 text-[10px] font-black uppercase tracking-widest text-gray-500/80 text-center">
                                            Duration</th>
                                        <th
                                            class="px-8 py-4 text-[10px] font-black uppercase tracking-widest text-gray-500/80 text-center">
                                            Billing Cycle</th>
                                        <th
                                            class="px-8 py-4 text-[10px] font-black uppercase tracking-widest text-gray-500/80 text-center">
                                            Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-white/5" id="archivedPlansTableBody">
                                    <tr
                                        class="no-results-row <?= empty($archived_website_plans) ? '' : 'hidden' ?> animate-in fade-in duration-500">
                                        <td colspan="5" class="px-8 py-10 text-center opacity-30">
                                            <span
                                                class="text-[10px] font-black uppercase tracking-[0.2em] no-results-text">
                                                <?= empty($archived_website_plans) ? 'No archived plans found' : 'No matching archived plans found' ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php if (!empty($archived_website_plans)): ?>
                                        <?php foreach ($archived_website_plans as $plan): ?>
                                            <tr class="hover:bg-white/[0.02] transition-colors group"
                                                data-id="<?= $plan['website_plan_id'] ?>" data-price="<?= $plan['price'] ?>"
                                                data-name="<?= htmlspecialchars(strtolower($plan['plan_name'])) ?>">
                                                <td class="px-8 py-4">
                                                    <div class="flex flex-col">
                                                        <span
                                                            class="text-[12px] font-black italic uppercase text-white plan-name-text"><?= htmlspecialchars($plan['plan_name']) ?></span>
                                                    </div>
                                                </td>
                                                <td class="px-8 py-4 text-center">
                                                    <span
                                                        class="text-[12px] font-black text-white">₱<?= number_format($plan['price']) ?></span>
                                                </td>
                                                <td class="px-8 py-4 text-center">
                                                    <span
                                                        class="text-[11px] font-black text-gray-400 uppercase"><?= $plan['duration_months'] ?>
                                                        Months</span>
                                                </td>
                                                <td class="px-8 py-4 text-center">
                                                    <span
                                                        class="text-[10px] font-black text-gray-500 uppercase tracking-widest plan-billing-text"
                                                        data-billing="<?= htmlspecialchars($plan['billing_cycle']) ?>"><?= htmlspecialchars($plan['billing_cycle']) ?></span>
                                                </td>
                                                <td class="px-8 py-4 text-center">
                                                    <div class="flex items-center justify-center gap-2">
                                                        <button type="button"
                                                            onclick="viewPlanDetails(<?= htmlspecialchars(json_encode($plan)) ?>)"
                                                            class="size-8 rounded-lg bg-white/5 text-[--highlight] border border-white/5 hover:bg-white/10 transition-all flex items-center justify-center active:scale-95"
                                                            title="View Details">
                                                            <span class="material-symbols-outlined text-sm">visibility</span>
                                                        </button>
                                                        <button type="button"
                                                            onclick="unarchivePlan(<?= $plan['website_plan_id'] ?>)"
                                                            class="size-8 rounded-lg bg-emerald-500/10 text-emerald-500 border border-emerald-500/10 hover:bg-emerald-500 hover:text-white transition-all flex items-center justify-center active:scale-95"
                                                            title="Restore Plan">
                                                            <span
                                                                class="material-symbols-outlined text-sm">settings_backup_restore</span>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
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
                        <span class="material-symbols-outlined text-[--highlight] text-2xl">person_add</span>
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
                                    placeholder="superadmin_dev" autocomplete="off">
                            </div>
                            <div class="flex flex-col gap-1">
                                <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Email
                                    Address</label>
                                <input type="email" name="new_email" id="new_email" required class="input-field"
                                    placeholder="example@gmail.com" autocomplete="off">
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
                                <span class="material-symbols-outlined text-base text-[--highlight]">security</span>
                                Security Protocol
                            </h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div class="flex flex-col gap-1">
                                    <label
                                        class="text-[9px] font-black uppercase text-[--text-main] opacity-70 tracking-widest ml-1">Password</label>
                                    <div class="relative group/pass">
                                        <input type="password" name="new_password" id="new_password" required
                                            class="input-field w-full pr-12" placeholder="Min. 8 characters"
                                            oninput="checkStrength(this.value)" autocomplete="new-password">
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
                                            required class="input-field w-full pr-12" placeholder="Repeat password"
                                            autocomplete="new-password">
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
                <span id="confirmIcon" class="material-symbols-outlined text-[--highlight] text-3xl">warning</span>
            </div>
            <h3 id="confirmTitle" class="text-xl font-black italic uppercase tracking-tighter text-white mb-2">Confirm
                Changes</h3>
            <p id="confirmMessage"
                class="text-[10px] text-[--secondary] opacity-70 font-bold uppercase tracking-widest mb-10 leading-relaxed">
                Save these
                configurations to the system? This will update the appearance for all users immediately.</p>
            <div class="flex justify-center gap-4">
                <button type="button" onclick="toggleActionModal(false)"
                    class="flex-1 h-[50px] rounded-xl bg-white/5 text-[--secondary] text-[12px] font-black uppercase tracking-widest hover:bg-white/10 transition-all">Cancel</button>
                <button type="button" id="confirmExecuteBtn"
                    class="flex-1 h-[50px] rounded-xl bg-primary text-black text-[12px] font-black uppercase tracking-widest hover:bg-primary/90 transition-all">Confirm
                    Action</button>
            </div>
        </div>
    </div>

    <!-- Plan Details View Modal -->
    <div id="planViewModal" class="modal-overlay" onclick="if(event.target === this) togglePlanViewModal(false)">
        <div class="glass-card w-full max-w-2xl p-10 relative animate-in fade-in zoom-in duration-300">
            <button type="button" onclick="togglePlanViewModal(false)"
                class="absolute top-6 right-6 size-10 rounded-xl bg-white/5 border border-white/10 flex items-center justify-center text-white hover:bg-rose-500 hover:text-white transition-all z-10 group">
                <span class="material-symbols-outlined text-lg group-hover:rotate-90 transition-transform">close</span>
            </button>

            <div class="flex items-center gap-4 mb-8">
                <div class="size-12 rounded-2xl bg-primary/10 flex items-center justify-center">
                    <span class="material-symbols-outlined text-primary text-2xl">visibility</span>
                </div>
                <div>
                    <h3 class="text-xl font-black italic uppercase tracking-tighter text-white">Archived <span
                            class="text-primary italic">PLAN DETAILS</span></h3>
                    <p class="text-[11px] font-black uppercase tracking-widest text-gray-500 mt-1">Reviewing details for
                        archived plan</p>
                </div>
            </div>

            <div class="space-y-6">
                <!-- Row 1: Plan Name -->
                <div class="flex flex-col gap-1.5">
                    <label class="text-[11px] font-black uppercase text-gray-500 tracking-widest ml-1">Plan Name</label>
                    <div id="view_plan_name" class="view-box text-white font-bold italic uppercase">--</div>
                </div>

                <!-- Row 2: Price & Duration -->
                <div class="grid grid-cols-2 gap-6">
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[11px] font-black uppercase text-gray-500 tracking-widest ml-1">Price
                            (₱)</label>
                        <div id="view_plan_price" class="view-box font-black text-primary">--</div>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[11px] font-black uppercase text-gray-500 tracking-widest ml-1">Duration
                            (Months)</label>
                        <div id="view_plan_duration" class="view-box text-white">--</div>
                    </div>
                </div>

                <!-- Row 3: Billing & Badge -->
                <div class="grid grid-cols-2 gap-6">
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[11px] font-black uppercase text-gray-500 tracking-widest ml-1">Billing Cycle
                            Text</label>
                        <div id="view_plan_billing" class="view-box text-gray-400 capitalize">--</div>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[11px] font-black uppercase text-gray-500 tracking-widest ml-1">Featured
                            Badge Text</label>
                        <div id="view_plan_badge" class="view-box text-[--highlight] font-bold">--</div>
                    </div>
                </div>

                <!-- Row 4: Features -->
                <div class="flex flex-col gap-1.5">
                    <label class="text-[11px] font-black uppercase text-gray-500 tracking-widest ml-1">Features</label>
                    <div id="view_plan_features"
                        class="view-box view-box-long text-gray-400 font-medium leading-relaxed">
                        --
                    </div>
                </div>
            </div>

            <div class="mt-10 pt-8 border-t border-white/5 flex gap-4">
                <button type="button" onclick="togglePlanViewModal(false)"
                    class="flex-1 h-[50px] rounded-xl border border-white/10 text-[12px] font-black uppercase tracking-widest hover:bg-white/5 transition-all">Close</button>
            </div>
        </div>
    </div>
    <!-- END OF MODALS -->
    <script>
        function switchTab(tabId) {
            // Update UI
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));

            const activeBtn = document.getElementById(`tab-${tabId}`);
            const activeContent = document.getElementById(`content-${tabId}`);

            if (activeBtn) activeBtn.classList.add('active');
            if (activeContent) activeContent.classList.add('active');

            // Update Hidden Input for Persistence
            const tabInput = document.getElementById('activeTabInput');
            if (tabInput) tabInput.value = tabId;
        }

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

            if (username.toLowerCase() === email.toLowerCase()) {
                showError("Username and Email address cannot be the same.");
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

        function toggleSuperadminModal(show = true) {
            const modal = document.getElementById('superadminModal');
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



        /**
         * Horizon Elite Pagination Engine
         */
        class ElitePaginator {
            constructor(containerId, itemsPerPage, itemSelector) {
                this.container = document.getElementById(containerId);
                if (!this.container) return;

                this.pageSize = itemsPerPage;
                this.itemSelector = itemSelector;
                this.currentPage = 1;
                this.paginationContainerId = `${containerId}-pagination`;

                this.init();
            }

            init() {
                this.update();
            }

            update() {
                // First, completely hide items that are manually filtered out
                const allItems = Array.from(this.container.querySelectorAll(this.itemSelector));
                allItems.forEach(item => {
                    if (item.classList.contains('filtered-out')) {
                        item.classList.add('hidden');
                    }
                });

                // Only paginate items that match the filters
                const items = Array.from(this.container.querySelectorAll(`${this.itemSelector}:not(.filtered-out):not(.no-results-row)`));
                const totalItems = items.length;
                const totalPages = Math.ceil(totalItems / this.pageSize);

                // If fewer items than page size, hide existing pagination and show all valid items
                if (totalItems <= this.pageSize) {
                    items.forEach(item => item.classList.remove('hidden'));
                    const existing = document.getElementById(this.paginationContainerId);
                    if (existing) existing.remove();
                    return;
                }

                // Show only current page items
                const start = (this.currentPage - 1) * this.pageSize;
                const end = start + this.pageSize;

                items.forEach((item, index) => {
                    if (index >= start && index < end) {
                        item.classList.remove('hidden');
                        // Add transition support
                        item.classList.add('animate-in', 'fade-in', 'duration-500');
                    } else {
                        item.classList.add('hidden');
                    }
                });

                this.renderControls(totalItems, totalPages, start, end);
            }

            renderControls(totalItems, totalPages, start, endReached) {
                let controls = document.getElementById(this.paginationContainerId);
                if (!controls) {
                    controls = document.createElement('div');
                    controls.id = this.paginationContainerId;
                    controls.className = 'flex flex-col md:flex-row items-center justify-between gap-6 mt-12 p-6 glass-card border border-white/5 backdrop-blur-3xl';
                    this.container.after(controls);
                }

                const end = Math.min(endReached, totalItems);

                controls.innerHTML = `
                    <div class="flex flex-col gap-1">
                        <span class="text-[11px] font-black uppercase text-gray-500 tracking-widest">Navigation Status</span>
                        <p class="text-[12px] font-black uppercase tracking-tight text-white">
                            Showing <span class="text-primary">${start + 1}</span> to <span class="text-primary">${end}</span> of <span class="opacity-50">${totalItems} entries</span>
                        </p>
                    </div>
                    
                    <div class="flex items-center gap-2">
                        <button type="button" onclick="horizonPaginators['${this.container.id}'].setPage(${this.currentPage - 1})" 
                            ${this.currentPage === 1 ? 'disabled' : ''}
                            class="size-10 rounded-xl bg-white/5 border border-white/10 flex items-center justify-center text-white disabled:opacity-20 disabled:cursor-not-allowed hover:bg-primary hover:text-black transition-all group">
                            <span class="material-symbols-outlined text-base group-hover:scale-110 transition-transform">chevron_left</span>
                        </button>
                        
                        <div class="flex items-center gap-1.5 px-3 py-1.5 bg-white/5 border border-white/5 rounded-2xl">
                            ${this.renderPageNumbers(totalPages)}
                        </div>
                        
                        <button type="button" onclick="horizonPaginators['${this.container.id}'].setPage(${this.currentPage + 1})" 
                            ${this.currentPage === totalPages ? 'disabled' : ''}
                            class="size-10 rounded-xl bg-white/5 border border-white/10 flex items-center justify-center text-white disabled:opacity-20 disabled:cursor-not-allowed hover:bg-primary hover:text-black transition-all group">
                            <span class="material-symbols-outlined text-base group-hover:scale-110 transition-transform">chevron_right</span>
                        </button>
                    </div>
                `;
            }

            renderPageNumbers(totalPages) {
                let html = '';
                for (let i = 1; i <= totalPages; i++) {
                    const isActive = i === this.currentPage;
                    html += `
                        <button type="button" onclick="horizonPaginators['${this.container.id}'].setPage(${i})"
                            class="size-8 rounded-lg text-[12px] font-black uppercase tracking-widest transition-all ${isActive ? 'bg-primary text-[--tab-active-text]' : 'text-gray-500 hover:text-white'}">
                            ${i}
                        </button>
                    `;
                }
                return html;
            }

            setPage(page) {
                this.currentPage = page;
                this.update();
                // Smooth scroll to container
                this.container.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        window.horizonPaginators = {};

        function initPagination() {
            // Paginate Active Plans Grid (6 per page)
            const activeGrid = document.getElementById('activePlansContainer');
            if (activeGrid) {
                horizonPaginators['activePlansContainer'] = new ElitePaginator('activePlansContainer', 6, '.glass-card');
            }

            // Paginate Archived Table (10 per page)
            const archivedTable = document.getElementById('archivedPlansTableBody');
            if (archivedTable) {
                horizonPaginators['archivedPlansTableBody'] = new ElitePaginator('archivedPlansTableBody', 10, 'tr');
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            initPagination();
        });

        function switchPlanView(view) {
            const activeContainer = document.getElementById('activePlansContainer');
            const archivedContainer = document.getElementById('archivedPlansContainer');
            const toggleActive = document.getElementById('planViewToggleActive');
            const toggleArchived = document.getElementById('planViewToggleArchived');
            const addBtn = document.getElementById('addNewPlanBtn');

            if (view === 'active') {
                activeContainer.classList.remove('hidden');
                archivedContainer.classList.add('hidden');
                if (addBtn) addBtn.classList.remove('hidden');
                toggleActive.className = 'px-5 py-2 rounded-lg bg-primary text-[--tab-active-text] text-[11px] font-black uppercase tracking-widest transition-all';
                toggleArchived.className = 'px-5 py-2 rounded-lg text-[--text-main] hover:text-white text-[11px] font-black uppercase tracking-widest transition-all opacity-70';
            } else {
                activeContainer.classList.add('hidden');
                archivedContainer.classList.remove('hidden');
                if (addBtn) addBtn.classList.add('hidden');
                toggleActive.className = 'px-5 py-2 rounded-lg text-[--text-main] hover:text-white text-[11px] font-black uppercase tracking-widest transition-all opacity-70';
                toggleArchived.className = 'px-5 py-2 rounded-lg bg-primary text-[--tab-active-text] text-[11px] font-black uppercase tracking-widest transition-all';
            }
        }

        function confirmSaveConfigurations() {
            showActionModal(
                'Save Changes',
                'Update system configurations? This will apply new branding and rules across all platforms immediately.',
                'save',
                () => {
                    const form = document.getElementById('mainSettingsForm');
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

                confirmBtn.textContent = isError && !onConfirm ? "Okay, I'll fix it" : "Confirm Action";
                confirmBtn.onclick = onConfirm || (() => toggleActionModal(false));
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
            const themeInput = document.getElementById('theme_color_input');
            const secondaryInput = document.getElementById('secondary_color_input');
            const textColorInput = document.getElementById('text_color_input');
            const bgInput = document.getElementById('bg_color_input');
            const nameInput = document.getElementById('system_name_input');
            const isAutoCardInput = document.getElementById('auto_card_theme_input');
            const cardColorInput = document.getElementById('card_color_input');

            if (!themeInput || !secondaryInput || !textColorInput || !bgInput) return;

            root.style.setProperty('--primary', themeInput.value);
            root.style.setProperty('--highlight', secondaryInput.value);
            root.style.setProperty('--text-main', textColorInput.value);
            root.style.setProperty('--background', bgInput.value);

            const sidebarName = document.getElementById('sidebarSystemName');
            if (sidebarName && nameInput) sidebarName.textContent = nameInput.value;

            const headerClock = document.getElementById('headerClock');
            if (headerClock) headerClock.style.color = textColorInput.value;

            const rgb = hexToRgb(themeInput.value);
            if (rgb) {
                const rgbVal = `${rgb.r}, ${rgb.g}, ${rgb.b}`;
                root.style.setProperty('--primary-rgb', rgbVal);

                const isAutoCard = isAutoCardInput ? isAutoCardInput.checked : true;
                if (isAutoCard) {
                    root.style.setProperty('--card-bg', `rgba(${rgbVal}, 0.05)`);
                    if (cardColorInput) {
                        cardColorInput.value = themeInput.value;
                        const cardHex = document.getElementById('card_hex_display');
                        if (cardHex) cardHex.innerText = themeInput.value.toUpperCase();
                        if (cardColorInput.parentElement && cardColorInput.parentElement.parentElement) {
                            cardColorInput.parentElement.parentElement.style.opacity = '0.4';
                            cardColorInput.parentElement.parentElement.style.pointerEvents = 'none';
                        }
                    }
                } else if (cardColorInput) {
                    root.style.setProperty('--card-bg', cardColorInput.value);
                    const cardHex = document.getElementById('card_hex_display');
                    if (cardHex) cardHex.innerText = cardColorInput.value.toUpperCase();
                    if (cardColorInput.parentElement && cardColorInput.parentElement.parentElement) {
                        cardColorInput.parentElement.parentElement.style.opacity = '1';
                        cardColorInput.parentElement.parentElement.style.pointerEvents = 'auto';
                    }
                }
                root.style.setProperty('--tab-active-text', textColorInput.value);
            }

            const themeHex = document.getElementById('theme_hex_display');
            const secondaryHex = document.getElementById('secondary_hex_display');
            const textHex = document.getElementById('text_hex_display');
            const bgHex = document.getElementById('bg_hex_display');

            if (themeHex) themeHex.innerText = themeInput.value.toUpperCase();
            if (secondaryHex) secondaryHex.innerText = secondaryInput.value.toUpperCase();
            if (textHex) textHex.innerText = textColorInput.value.toUpperCase();
            if (bgHex) bgHex.innerText = bgInput.value.toUpperCase();
        }
        // --- Initialization & Lifecycle ---
        document.addEventListener('DOMContentLoaded', () => {
            initPagination();
            updateLiveBranding();

            const successAlert = document.getElementById('successAlert');
            if (successAlert) {
                setTimeout(() => {
                    successAlert.style.opacity = '0';
                    successAlert.style.transform = 'translateY(-10px)';
                    setTimeout(() => successAlert.remove(), 500);
                }, 15000);
            }

            // Restore Active Tab (Check GET then POST then default)
            const urlParams = new URLSearchParams(window.location.search);
            const initialTab = urlParams.get('tab') || "<?= htmlspecialchars($_POST['active_tab'] ?? 'look') ?>";
            switchTab(initialTab);

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
                    document.getElementById('tab_active_text_input').value = '#ffffff';
                    document.getElementById('bg_color_input').value = '#0a090d';

                    const isAutoInput = document.getElementById('auto_card_theme_input');
                    if (isAutoInput) isAutoInput.checked = true;

                    const isAutoTabInput = document.getElementById('auto_tab_sync_input');
                    if (isAutoTabInput) isAutoTabInput.checked = true;

                    updateLiveBranding();
                    toggleActionModal(false);
                }
            );
        }

        function toggleEditPlan(planId) {
            const card = document.getElementById(`plan-card-${planId}`);
            if (card) {
                card.classList.toggle('is-editing');
            }
        }

        let newPlanCount = 0;
        function addNewPlanCard() {
            const container = document.getElementById('activePlansContainer');
            const newId = `new_plan_${newPlanCount++}`;

            const card = document.createElement('div');
            // Starts with .is-editing by default
            card.className = "glass-card p-8 flex flex-col gap-6 relative group/plan animate-in fade-in slide-in-from-bottom-4 duration-500 shadow-2xl is-editing";
            card.innerHTML = `
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <div class="size-10 rounded-xl bg-emerald-500/10 flex items-center justify-center">
                            <span class="material-symbols-outlined text-[--highlight]">add_reaction</span>
                        </div>
                        <h3 class="text-sm font-black italic uppercase tracking-widest text-emerald-500 plan-title-preview">Draft Plan</h3>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" onclick="this.closest('.glass-card').classList.toggle('is-editing')" class="size-8 rounded-lg bg-primary/10 text-primary flex items-center justify-center hover:bg-primary hover:text-white transition-all active:scale-95" title="Toggle Edit Mode">
                            <span class="material-symbols-outlined text-base">edit</span>
                        </button>
                        <button type="button" onclick="this.closest('.glass-card').remove(); horizonPaginators['activePlansContainer'].update();" class="size-8 rounded-lg bg-rose-500/10 text-rose-500 flex items-center justify-center hover:bg-rose-500 hover:text-white transition-all">
                            <span class="material-symbols-outlined text-base">close</span>
                        </button>
                    </div>
                </div>

                <!-- PREVIEW STATE: Structured View mirroring Edit Mode -->
                <div class="plan-preview-state space-y-4">
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Plan Name</label>
                        <div class="view-box text-white opacity-60 italic">Drafting...</div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Price (₱)</label>
                            <div class="view-box opacity-40">--</div>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Duration (Months)</label>
                            <div class="view-box opacity-40">--</div>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Billing Cycle Text</label>
                            <div class="view-box opacity-40">--</div>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Featured Badge Text</label>
                            <div class="view-box opacity-40">--</div>
                        </div>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="text-[11px] font-black uppercase text-gray-500 tracking-widest ml-1">Features</label>
                        <div class="view-box view-box-long text-gray-500 italic opacity-40">
                            Drafting features...
                        </div>
                    </div>
                </div>

                <div class="plan-edit-state space-y-4">
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Plan Name</label>
                        <input type="text" name="new_plans[${newId}][name]" placeholder="e.g. Starter Pack" class="input-field" oninput="this.closest('.glass-card').querySelector('.plan-title-preview').innerText = this.value || 'Draft Plan'" required>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Price (₱)</label>
                            <input type="number" step="1" name="new_plans[${newId}][price]" class="input-field" required>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Duration (Months)</label>
                            <input type="number" name="new_plans[${newId}][duration]" class="input-field" required>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Billing Cycle Text</label>
                            <input type="text" name="new_plans[${newId}][billing]" placeholder="e.g. Yearly" class="input-field" required>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Featured Badge Text</label>
                            <input type="text" name="new_plans[${newId}][badge_text]" placeholder="e.g. Most Popular" class="input-field">
                        </div>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Features (Comma-separated)</label>
                        <textarea name="new_plans[${newId}][features]" rows="4" class="input-field no-scrollbar resize-none" placeholder="Feature 1, Feature 2..."></textarea>
                    </div>
                    
                    <div class="pt-4 border-t border-white/5">
                        <p class="text-[8px] font-black uppercase tracking-widest text-primary opacity-60">Manual save required via 'Save Changes'</p>
                    </div>
                </div>
            `;
            container.prepend(card);

            // Sync with pagination engine
            if (horizonPaginators['activePlansContainer']) {
                horizonPaginators['activePlansContainer'].currentPage = 1; // Back to first page to see the new card
                horizonPaginators['activePlansContainer'].update();
            }

            card.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        function triggerImmediatePlanUpdate(planId, action) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '';
            form.style.display = 'none';

            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'immediate_plan_action';
            actionInput.value = action;

            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'target_plan_id';
            idInput.value = planId;

            form.appendChild(actionInput);
            form.appendChild(idInput);
            document.body.appendChild(form);
            form.submit();
        }

        function markPlanForArchival(planId) {
            showActionModal(
                'Archive Plan Immediately',
                'Are you sure? This will instantly archive the plan and update the system. No manual save required.',
                'archive',
                () => {
                    triggerImmediatePlanUpdate(planId, 'archive');
                    toggleActionModal(false);
                }
            );
        }

        function unarchivePlan(planId) {
            showActionModal(
                'Restore Plan Immediately',
                'Are you sure? This will instantly restore the plan and make it active. No manual save required.',
                'unarchive',
                () => {
                    triggerImmediatePlanUpdate(planId, 'restore');
                    toggleActionModal(false);
                }
            );
        }

        function togglePlanViewModal(show) {
            const modal = document.getElementById('planViewModal');
            if (modal) {
                modal.classList.toggle('active', show);
                document.body.style.overflow = show ? 'hidden' : 'auto';
            }
        }

        function viewPlanDetails(plan) {
            document.getElementById('view_plan_name').innerText = plan.plan_name;
            document.getElementById('view_plan_price').innerText = '₱' + parseInt(plan.price).toLocaleString();
            document.getElementById('view_plan_duration').innerText = plan.duration_months + (plan.duration_months > 1 ? ' Months' : ' Month');
            document.getElementById('view_plan_billing').innerText = plan.billing_cycle || 'N/A';
            document.getElementById('view_plan_badge').innerText = plan.badge_text || 'None';
            document.getElementById('view_plan_features').innerText = plan.features || 'No features listed';

            togglePlanViewModal(true);
        }

        function resetArchivedFilters() {
            document.getElementById('archivedPlanSearch').value = '';
            document.getElementById('archivedIDSort').value = 'newest';
            document.getElementById('archivedPriceSort').value = 'none';
            filterArchivedPlans();
        }

        function filterArchivedPlans(source = 'all') {
            const query = document.getElementById('archivedPlanSearch').value.toLowerCase();
            const idSort = document.getElementById('archivedIDSort');
            const priceSort = document.getElementById('archivedPriceSort');

            // Sync logic: If one is used, the other resets to its neutral state to avoid conflict
            if (source === 'id') {
                priceSort.value = 'none';
            } else if (source === 'price' && priceSort.value !== 'none') {
                // No specific neutral state for ID, but we know price takes priority now
            }

            let sortValue = priceSort.value !== 'none' ? priceSort.value : idSort.value;

            const tbody = document.getElementById('archivedPlansTableBody');
            const rows = Array.from(tbody.querySelectorAll('tr:not(.no-results-row)'));

            // 1. Sort Rows
            rows.sort((a, b) => {
                const idA = parseInt(a.getAttribute('data-id'));
                const idB = parseInt(b.getAttribute('data-id'));
                const priceA = parseFloat(a.getAttribute('data-price'));
                const priceB = parseFloat(b.getAttribute('data-price'));

                switch (sortValue) {
                    case 'newest': return idB - idA;
                    case 'oldest': return idA - idB;
                    case 'price_low': return priceA - priceB;
                    case 'price_high': return priceB - priceA;
                    default: return idB - idA;
                }
            });

            // 2. Re-append Sorted Rows
            rows.forEach(row => tbody.appendChild(row));

            // 3. Apply Filters and Update Visibility
            let visibleCount = 0;
            rows.forEach(row => {
                const nameEl = row.querySelector('.plan-name-text');
                if (!nameEl) return;

                const planName = nameEl.innerText.toLowerCase();
                const matchesSearch = planName.includes(query);

                if (matchesSearch) {
                    row.classList.remove('filtered-out');
                    visibleCount++;
                } else {
                    row.classList.add('filtered-out');
                }
            });

            // Handle "No Results" row
            const noResultsRow = document.querySelector('.no-results-row');
            const noResultsText = noResultsRow.querySelector('.no-results-text');

            if (visibleCount === 0) {
                noResultsRow.classList.remove('hidden');
                noResultsText.innerText = (query === '')
                    ? 'No archived plans found'
                    : 'No matching archived plans found';
            } else {
                noResultsRow.classList.add('hidden');
            }

            // Sync with pagination engine
            if (horizonPaginators['archivedPlansTableBody']) {
                horizonPaginators['archivedPlansTableBody'].currentPage = 1;
                horizonPaginators['archivedPlansTableBody'].update();
            }
        }
    </script>
</body>

</html>