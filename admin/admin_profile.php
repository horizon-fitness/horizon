<?php
session_start();
require_once '../db.php';
require_once '../includes/audit_logger.php';

// Security Check: Staff and Coach access restricted to their gym
$role_session = strtolower($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || ($role_session !== 'staff' && $role_session !== 'coach')) {
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$gym_id = $_SESSION['gym_id'];
$success_msg = $_SESSION['success_msg'] ?? '';
$error_msg = $_SESSION['error_msg'] ?? '';
unset($_SESSION['success_msg'], $_SESSION['error_msg']);
$active_page = "profile";

// ── 4-Color Elite Branding System Implementation ─────────────────────────────
if (!function_exists('hexToRgb')) {
    function hexToRgb($hex) {
        if (!$hex) return "0, 0, 0";
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
}

// Fetch Gym & Owner Details for Branding
$stmtGymBranding = $pdo->prepare("SELECT owner_user_id, gym_name FROM gyms WHERE gym_id = ?");
$stmtGymBranding->execute([$gym_id]);
$gym_data = $stmtGymBranding->fetch();
$owner_user_id = $gym_data['owner_user_id'] ?? 0;
$gym_name = $gym_data['gym_name'] ?? 'Horizon Gym';

$configs = [
    'system_name'     => $gym_name,
    'system_logo'     => '',
    'theme_color'     => '#8c2bee',
    'secondary_color' => '#a1a1aa',
    'text_color'      => '#d1d5db',
    'bg_color'        => '#0a090d',
    'card_color'      => '#141216',
    'auto_card_theme' => '1',
    'font_family'     => 'Lexend',
];

// 1. Merge global settings (user_id = 0)
$stmtGlobal = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = 0");
$stmtGlobal->execute();
foreach (($stmtGlobal->fetchAll(PDO::FETCH_KEY_PAIR) ?: []) as $k => $v) {
    if ($v !== null && $v !== '') $configs[$k] = $v;
}

// 2. Merge tenant-specific settings (user_id = owner_user_id)
$stmtTenant = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = ?");
$stmtTenant->execute([$owner_user_id]);
foreach (($stmtTenant->fetchAll(PDO::FETCH_KEY_PAIR) ?: []) as $k => $v) {
    if ($v !== null && $v !== '') $configs[$k] = $v;
}

// 3. Resolved branding tokens
$theme_color     = $configs['theme_color'];
$highlight_color = $configs['secondary_color'];
$text_color      = $configs['text_color'];
$bg_color        = $configs['bg_color'];
$font_family     = $configs['font_family'] ?? 'Lexend';
$auto_card_theme = $configs['auto_card_theme'] ?? '1';
$card_color      = $configs['card_color'];

$primary_rgb   = hexToRgb($theme_color);
$highlight_rgb = hexToRgb($highlight_color);
$card_bg_css   = ($auto_card_theme === '1') ? "rgba({$primary_rgb}, 0.05)" : $card_color;

$page = [
    'logo_path'   => $configs['system_logo'] ?? '',
    'theme_color' => $theme_color,
    'bg_color'    => $bg_color,
    'system_name' => $configs['system_name'] ?? $gym_name,
];
// ─────────────────────────────────────────────────────────────────────────────

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

// Unified Profile & Password Update Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $current_password = $_POST['current_password'] ?? '';
    $username = trim($_POST['username']);
    $first_name = trim($_POST['first_name']);
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $contact_number = trim($_POST['contact_number']);
    $birth_date = trim($_POST['birth_date'] ?? '');
    $sex = trim($_POST['sex'] ?? '');
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    $now = date('Y-m-d H:i:s');
    $today = date('Y-m-d');

    try {
        // Verification: Current Password
        $stmtStatus = $pdo->prepare("SELECT password_hash FROM users WHERE user_id = ?");
        $stmtStatus->execute([$user_id]);
        $userData = $stmtStatus->fetch();

        if (empty($current_password) || !password_verify($current_password, $userData['password_hash'])) {
            throw new Exception("Incorrect current password. Verification failed.");
        }

        // Server-Side Validations
        if (preg_match('/[0-9]/', $first_name)) throw new Exception("First name cannot contain numbers.");
        if (preg_match('/[0-9]/', $last_name)) throw new Exception("Last name cannot contain numbers.");
        
        $raw_contact = str_replace('-', '', $contact_number);
        if (!ctype_digit($raw_contact) || strlen($raw_contact) !== 11) {
            throw new Exception("Contact number must be exactly 11 digits.");
        }

        if ($birth_date > $today) throw new Exception("Birth date cannot be a future date.");
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !str_ends_with(strtolower($email), '@gmail.com')) {
            throw new Exception("Email must be a valid @gmail.com address.");
        }

        $pdo->beginTransaction();

        // Fetch old values for audit
        $stmtOld = $pdo->prepare("SELECT username, first_name, middle_name, last_name, email, contact_number, birth_date, sex, profile_picture FROM users WHERE user_id = ?");
        $stmtOld->execute([$user_id]);
        $old_values = $stmtOld->fetch(PDO::FETCH_ASSOC);

        $remove_profile = isset($_POST['remove_profile_picture']) && $_POST['remove_profile_picture'] === '1';
        $profile_picture = convertFileToBase64('profile_picture');

        // Build Update Query
        $updates = [
            "username = ?", "first_name = ?", "middle_name = ?", "last_name = ?", 
            "email = ?", "contact_number = ?", "birth_date = ?", "sex = ?", "updated_at = ?"
        ];
        $params = [$username, $first_name, $middle_name, $last_name, $email, $contact_number, $birth_date, $sex, $now];
        $new_values = [
            'username' => $username, 'first_name' => $first_name, 'middle_name' => $middle_name, 
            'last_name' => $last_name, 'email' => $email, 'contact_number' => $contact_number, 
            'birth_date' => $birth_date, 'sex' => $sex
        ];

        if ($remove_profile) {
            $updates[] = "profile_picture = NULL";
            $new_values['profile_picture'] = 'REMOVED';
        }

        if ($profile_picture) {
            $updates[] = "profile_picture = ?";
            $params[] = $profile_picture;
            $new_values['profile_picture'] = '[IMAGE DATA]';
        }

        // Handle Password Change
        if (!empty($new_password)) {
            if ($new_password !== $confirm_password) throw new Exception("New passwords do not match.");
            if (strlen($new_password) < 8) throw new Exception("New password must be at least 8 characters.");
            $updates[] = "password_hash = ?";
            $params[] = password_hash($new_password, PASSWORD_BCRYPT);
            $new_values['password'] = 'CHANGED';
        }

        $params[] = $user_id;
        $stmtUpdate = $pdo->prepare("UPDATE users SET " . implode(", ", $updates) . " WHERE user_id = ?");
        $stmtUpdate->execute($params);

        log_audit_event($pdo, $user_id, $gym_id, 'Update', 'users', $user_id, $old_values, $new_values);

        $pdo->commit();
        $_SESSION['success_msg'] = "Profile updated successfully!";
        header("Location: admin_profile.php?status=success");
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error_msg'] = $e->getMessage();
        header("Location: admin_profile.php?status=error");
        exit;
    }
}

// FETCH Base User Data First
$stmtUser = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmtUser->execute([$user_id]);
$user = $stmtUser->fetch(PDO::FETCH_ASSOC);

if ($user) {
    // Fetch Staff Details
    $stmtStaff = $pdo->prepare("
        SELECT s.staff_role, s.employment_type, s.status as staff_status, s.hire_date
        FROM staff s
        WHERE s.user_id = ? AND s.gym_id = ?
        LIMIT 1
    ");
    $stmtStaff->execute([$user_id, $gym_id]);
    $st_data = $stmtStaff->fetch(PDO::FETCH_ASSOC);
    if ($st_data) {
        $user = array_merge($user, $st_data);
    }
}

$stmtGym = $pdo->prepare("SELECT * FROM gyms WHERE gym_id = ?");
$stmtGym->execute([$gym_id]);
$gym = $stmtGym->fetch();

$joined = isset($user['created_at']) ? date("F Y", strtotime($user['created_at'])) : 'Unknown';
$status = $user['staff_status'] ?? 'Active';
$staffRole = $user['staff_role'] ?? 'Administrator';
$employmentType = $user['employment_type'] ?? 'Full-Time';
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Admin Profile | Horizon Systems</title>
    <link href="https://fonts.googleapis.com/css2?family=<?= urlencode($font_family) ?>:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { 
                "primary": "var(--primary)", 
                "background": "var(--background)", 
                "card-bg": "var(--card-bg)", 
                "text-main": "var(--text-main)",
                "highlight": "var(--highlight)"
            } } }
        }
    </script>
    <style>
        :root {
            --primary:       <?= $theme_color ?>;
            --primary-rgb:   <?= $primary_rgb ?>;
            --highlight:     <?= $highlight_color ?>;
            --highlight-rgb: <?= $highlight_rgb ?>;
            --text-main:     <?= $text_color ?>;
            --background:    <?= $bg_color ?>;
            --card-bg:       <?= $card_bg_css ?>;
            --card-blur:     20px;
        }

        body {
            font-family: '<?= $font_family ?>', sans-serif;
            background-color: var(--background);
            color: var(--text-main);
            overflow: hidden;
            display: flex;
            min-height: 100vh;
        }

        .side-nav {
            width: 110px;
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            position: fixed;
            left: 0; top: 0;
            height: 100vh;
            z-index: 50;
            background-color: var(--background);
            border-right: 1px solid rgba(255,255,255,0.05);
        }
        .side-nav:hover { width: 300px; }

        .main-content {
            margin-left: 110px;
            flex: 1;
            min-width: 0;
            transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow-y: auto;
        }
        .side-nav:hover ~ .main-content { margin-left: 300px; }

        .glass-card {
            background: var(--card-bg);
            border: 1px solid rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(var(--card-blur));
            border-radius: 32px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .nav-label {
            opacity: 0;
            transform: translateX(-15px);
            transition: all 0.3s ease-in-out;
            white-space: nowrap;
            pointer-events: none;
            color: var(--text-main);
        }
        .side-nav:hover .nav-label { opacity: 1; transform: translateX(0); pointer-events: auto; }

        .nav-section-label {
            max-height: 0; opacity: 0; overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            margin: 0 !important; pointer-events: none;
        }
        .side-nav:hover .nav-section-label {
            max-height: 20px; opacity: 1;
            margin-bottom: 8px !important; pointer-events: auto;
        }

        .nav-item {
            display: flex; align-items: center; gap: 16px;
            padding: 10px 38px;
            transition: opacity 0.2s ease, color 0.2s ease;
            text-decoration: none; white-space: nowrap;
            font-size: 11px; font-weight: 800;
            text-transform: uppercase; letter-spacing: 0.05em;
            color: color-mix(in srgb, var(--text-main) 45%, transparent);
            position: relative;
        }
        .nav-item:hover { color: var(--text-main); }
        .nav-item .material-symbols-rounded {
            color: var(--highlight);
            transition: transform 0.2s ease;
        }
        .nav-item:hover .material-symbols-rounded { transform: scale(1.12); }
        .nav-item.active { color: var(--primary) !important; }
        .nav-item.active .material-symbols-rounded { color: var(--primary); }
        .nav-item.active::after {
            content: ''; position: absolute;
            right: 0px; top: 50%; transform: translateY(-50%);
            width: 4px; height: 24px;
            background: var(--primary); border-radius: 4px 0 0 4px;
            opacity: 1; transition: opacity 0.3s ease;
        }

        .profile-input {
            background-color: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            color: var(--text-main);
            transition: all 0.3s ease;
            font-family: inherit;
        }
        .profile-input.has-icon { padding-left: 3.5rem !important; }
        .profile-input:focus {
            background-color: rgba(255, 255, 255, 0.06);
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 4px rgba(var(--primary-rgb), 0.1);
        }
        .profile-input:disabled {
            background-color: transparent !important;
            border-color: transparent !important;
            color: color-mix(in srgb, var(--text-main) 40%, transparent) !important;
            cursor: default !important;
            padding-left: 0 !important;
        }

        .input-icon-container {
            position: absolute; top: 50%; left: 0; padding-left: 1.25rem;
            transform: translateY(-50%); display: flex; align-items: center;
            color: color-mix(in srgb, var(--text-main) 30%, transparent); pointer-events: none;
            transition: all 0.3s ease;
        }
        body:not(.edit-mode) .input-icon-container { display: none !important; }

        .profile-section-title {
            font-size: 0.75rem; font-weight: 800; text-transform: uppercase;
            letter-spacing: 0.05em; color: var(--primary);
            margin-bottom: 1.5rem; padding-bottom: 0.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }

        .edit-reveal {
            max-height: 0; opacity: 0; overflow: hidden;
            transition: all 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }
        body.edit-mode .edit-reveal { max-height: 1000px; opacity: 1; margin-top: 1.5rem; }

        #main-wrapper {
            max-width: 1000px;
            transition: max-width 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }
        body.edit-mode #main-wrapper { max-width: 1200px; }

        .strength-bar { height: 4px; border-radius: 2px; transition: all 0.3s ease; }
        .no-scrollbar::-webkit-scrollbar { display: none !important; }
        * { -ms-overflow-style: none !important; scrollbar-width: none !important; }

        #custom-modal {
            position: fixed; top: 0; right: 0; bottom: 0; left: var(--nav-width, 110px);
            z-index: 200; display: none; align-items: center; justify-content: center;
            background: rgba(var(--background), 0.85); backdrop-filter: blur(12px);
            padding: 20px; transition: left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        #custom-modal.flex { display: flex !important; }

        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fade-in { animation: fadeIn 0.4s ease-out; }
    </style>
</head>
<body class="antialiased flex h-screen overflow-hidden">

    <!-- Centralized Admin Sidebar Integration -->
    <?php include '../includes/admin_sidebar.php'; ?>

    <div class="main-content flex-1 overflow-y-auto no-scrollbar">
        <main id="main-wrapper" class="flex-1 p-10 w-full mx-auto pb-32 animate-fade-in">
            <header class="mb-12 flex flex-row justify-between items-end gap-6">
                <div>
                    <h2 class="text-3xl font-black italic uppercase tracking-tighter leading-none">
                        <span class="text-[--text-main]">Admin</span>
                        <span class="text-primary">Profile</span>
                    </h2>
                    <p class="text-[--text-main]/40 text-[10px] font-bold uppercase tracking-widest mt-2 px-1">Personal Identity & Security Access</p>
                </div>
                <div class="flex flex-col items-end justify-center no-scrollbar">
                    <p id="headerClock" class="text-[--text-main] font-black italic text-2xl leading-none hover:text-primary tracking-tighter uppercase transition-colors">00:00:00 AM</p>
                    <p class="text-primary text-[10px] font-black uppercase tracking-[0.2em] mt-2 leading-none"><?= date('l, M d, Y') ?></p>
                </div>
            </header>

            <?php if ($success_msg): ?>
                <div id="statusAlert" class="bg-emerald-500/10 border border-emerald-500/20 p-4 rounded-xl text-emerald-500 text-[11px] font-black uppercase mb-8 flex items-center gap-3 animate-fade-in shadow-lg shadow-emerald-500/5">
                    <span class="material-symbols-rounded text-base">check_circle</span>
                    <span><?= $success_msg ?></span>
                </div>
            <?php elseif ($error_msg): ?>
                <div id="statusAlert" class="bg-rose-500/10 border border-rose-500/20 p-4 rounded-xl text-rose-500 text-[11px] font-black uppercase mb-8 flex items-center gap-3 animate-fade-in shadow-lg shadow-rose-500/5">
                    <span class="material-symbols-rounded text-base">warning</span>
                    <span><?= $error_msg ?></span>
                </div>
            <?php endif; ?>

            <div class="flex flex-col xl:flex-row gap-8 items-start justify-center">
                <!-- Left Panel (Identity Card) -->
                <div class="w-full xl:w-72 shrink-0 flex flex-col gap-6">
                    <div class="glass-card relative overflow-hidden p-8 text-center group shadow-2xl">
                        <div class="absolute top-0 right-0 size-40 bg-primary/10 rounded-full blur-3xl -translate-y-1/2 translate-x-1/2 pointer-events-none group-hover:scale-110 transition-transform"></div>
                        <div class="relative z-10">
                            <div class="size-32 mx-auto rounded-[32px] p-1 bg-gradient-to-br from-white/10 to-transparent border border-white/10 mb-6 shadow-2xl overflow-hidden group">
                                <div id="profile-container" class="size-full rounded-[30px] bg-black/20 flex items-center justify-center overflow-hidden relative">
                                    <?php if (!empty($user['profile_picture'])): ?>
                                        <img id="profilePreviewImg" src="<?= $user['profile_picture'] ?>" class="size-full aspect-square object-cover transition-transform duration-700 group-hover:scale-110">
                                    <?php else: ?>
                                        <div id="profilePlaceholder" class="size-full flex items-center justify-center bg-gradient-to-br from-primary/20 via-primary/10 to-transparent text-primary text-5xl font-black italic uppercase">
                                            <?php 
                                            $f = $user['first_name'] ?? $_SESSION['first_name'] ?? 'A';
                                            $l = $user['last_name'] ?? $_SESSION['last_name'] ?? '';
                                            echo strtoupper($f[0] . ($l[0] ?? ''));
                                            ?>
                                        </div>
                                    <?php endif; ?>
                                    <label id="profile-label" class="absolute inset-0 bg-black/80 opacity-0 group-hover:opacity-100 transition-all duration-300 flex flex-col items-center justify-center gap-3 backdrop-blur-md cursor-pointer border-4 border-dashed border-white/10 rounded-[30px] hidden">
                                        <span class="material-symbols-rounded text-2xl text-white">add_a_photo</span>
                                        <input type="file" name="profile_picture" form="profile-form" id="profile-input-file" class="hidden" accept="image/*" onchange="previewProfileImage(this)">
                                    </label>
                                    <button type="button" id="remove-photo-btn" onclick="removeProfilePhoto()" class="absolute top-2.5 right-2.5 size-7 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-500 flex items-center justify-center opacity-0 hover:bg-rose-500 hover:text-white transition-all duration-300 z-20 hidden backdrop-blur-md">
                                        <span class="material-symbols-rounded text-base">delete</span>
                                    </button>
                                </div>
                            </div>
                            <h2 class="text-2xl font-black italic uppercase tracking-tighter text-white mb-1"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></h2>
                            <p class="text-[10px] text-primary mb-5 font-bold uppercase tracking-widest"><?= $staffRole ?></p>
                            <div class="flex justify-center gap-2 mb-8">
                                <span class="px-4 py-1.5 rounded-full text-[9px] font-black uppercase tracking-widest border border-emerald-500/20 bg-emerald-500/10 text-emerald-400"><?= $status ?></span>
                            </div>
                            <div class="pt-6 border-t border-white/5">
                                <p class="text-[9px] uppercase tracking-[0.2em] text-[--text-main]/30 font-black mb-1 italic">Identity Verified Since</p>
                                <p class="text-sm font-bold text-[--text-main]/60 italic"><?= $joined ?></p>
                            </div>
                        </div>
                    </div>

                    <button id="edit-btn" onclick="toggleEdit()" class="w-full py-4 bg-white/5 border border-white/10 hover:bg-white/10 hover:border-primary/30 text-[--text-main] text-[10px] font-black italic uppercase tracking-[0.2em] rounded-2xl transition-all flex items-center justify-center gap-3 group">
                        <span class="material-symbols-rounded group-hover:text-primary transition-colors">edit_square</span>
                        <span>Edit Profile</span>
                    </button>
                    <button id="discard-btn" onclick="cancelEdit()" class="hidden w-full py-4 bg-rose-500/10 border border-rose-500/20 hover:bg-rose-500/20 hover:border-rose-500/40 text-rose-500 text-[10px] font-black italic uppercase tracking-[0.2em] rounded-2xl transition-all flex items-center justify-center gap-3">
                        <span class="material-symbols-rounded">close</span>
                        <span>Discard Changes</span>
                    </button>
                </div>

                <!-- Right Panel (Form Management) -->
                <div class="flex-1 glass-card p-10 no-scrollbar relative overflow-hidden group">
                    <div class="flex items-center justify-between mb-10">
                        <h1 class="text-xl font-black italic uppercase tracking-tighter text-white">Identity Management</h1>
                        <div id="edit-indicator" class="hidden px-4 py-1.5 rounded-lg bg-primary/20 text-primary text-[9px] font-black italic uppercase tracking-[0.2em] animate-pulse">Editing Mode</div>
                    </div>

                    <form id="profile-form" action="" method="POST" enctype="multipart/form-data" class="flex flex-col gap-12" onsubmit="validateAndSubmit(event)">
                        <input type="hidden" name="action" value="update_profile">
                        
                        <div class="space-y-6">
                            <h3 class="profile-section-title">Account Details</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-10 gap-y-8">
                                <div class="space-y-2">
                                    <label class="text-[9px] uppercase font-black text-[--text-main]/60 tracking-widest pl-1">Username</label>
                                    <div class="relative group">
                                        <div class="input-icon-container"><span class="material-symbols-rounded">alternate_email</span></div>
                                        <input type="text" name="username" value="<?= htmlspecialchars($user['username'] ?? '') ?>" disabled required class="w-full profile-input has-icon rounded-2xl px-6 py-4 text-sm font-bold shadow-inner">
                                    </div>
                                </div>
                                <div class="space-y-2">
                                    <label class="text-[9px] uppercase font-black text-[--text-main]/60 tracking-widest pl-1">Assigned Role</label>
                                    <div class="relative group">
                                        <div class="input-icon-container"><span class="material-symbols-rounded">badge</span></div>
                                        <input type="text" value="<?= $staffRole ?>" disabled class="w-full profile-input has-icon rounded-2xl px-6 py-4 text-sm font-bold shadow-inner">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="space-y-6">
                            <h3 class="profile-section-title">Personal Information</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-10 gap-y-8">
                                <div class="space-y-2">
                                    <label class="text-[9px] uppercase font-black text-[--text-main]/60 tracking-widest pl-1">First Name</label>
                                    <div class="relative group">
                                        <div class="input-icon-container"><span class="material-symbols-rounded">person</span></div>
                                        <input type="text" name="first_name" value="<?= htmlspecialchars($user['first_name'] ?? '') ?>" disabled required class="w-full profile-input has-icon rounded-2xl px-6 py-4 text-sm font-bold shadow-inner">
                                    </div>
                                </div>
                                <div class="space-y-2">
                                    <label class="text-[9px] uppercase font-black text-[--text-main]/60 tracking-widest pl-1">Middle Name</label>
                                    <div class="relative group">
                                        <div class="input-icon-container"><span class="material-symbols-rounded">person</span></div>
                                        <input type="text" name="middle_name" value="<?= htmlspecialchars($user['middle_name'] ?? '') ?>" disabled class="w-full profile-input has-icon rounded-2xl px-6 py-4 text-sm font-bold shadow-inner" placeholder="Optional">
                                    </div>
                                </div>
                                <div class="space-y-2">
                                    <label class="text-[9px] uppercase font-black text-[--text-main]/60 tracking-widest pl-1">Last Name</label>
                                    <div class="relative group">
                                        <div class="input-icon-container"><span class="material-symbols-rounded">person</span></div>
                                        <input type="text" name="last_name" value="<?= htmlspecialchars($user['last_name'] ?? '') ?>" disabled required class="w-full profile-input has-icon rounded-2xl px-6 py-4 text-sm font-bold shadow-inner">
                                    </div>
                                </div>
                                <div class="space-y-2">
                                    <label class="text-[9px] uppercase font-black text-[--text-main]/60 tracking-widest pl-1">Birth Date</label>
                                    <div class="relative group">
                                        <div class="input-icon-container"><span class="material-symbols-rounded">cake</span></div>
                                        <input type="date" name="birth_date" value="<?= $user['birth_date'] ?? '' ?>" disabled required class="w-full profile-input has-icon rounded-2xl px-6 py-4 text-sm font-bold shadow-inner appearance-none">
                                    </div>
                                </div>
                                <div class="space-y-2">
                                    <label class="text-[9px] uppercase font-black text-[--text-main]/60 tracking-widest pl-1">Sex</label>
                                    <div class="relative group">
                                        <div class="input-icon-container"><span class="material-symbols-rounded">wc</span></div>
                                        <select name="sex" disabled required class="w-full profile-input has-icon rounded-2xl px-6 py-4 text-sm font-bold shadow-inner appearance-none">
                                            <option value="Male" <?= ($user['sex'] ?? '') === 'Male' ? 'selected' : '' ?>>Male</option>
                                            <option value="Female" <?= ($user['sex'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
                                            <option value="Other" <?= ($user['sex'] ?? '') === 'Other' ? 'selected' : '' ?>>Other</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="space-y-6">
                            <h3 class="profile-section-title">Contact Information</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-10 gap-y-8">
                                <div class="space-y-2">
                                    <label class="text-[9px] uppercase font-black text-[--text-main]/60 tracking-widest pl-1">Email Address</label>
                                    <div class="relative group">
                                        <div class="input-icon-container"><span class="material-symbols-rounded">mail</span></div>
                                        <input type="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" disabled required class="w-full profile-input has-icon rounded-2xl px-6 py-4 text-sm font-bold shadow-inner">
                                    </div>
                                </div>
                                <div class="space-y-2">
                                    <label class="text-[9px] uppercase font-black text-[--text-main]/60 tracking-widest pl-1">Phone number</label>
                                    <div class="relative group">
                                        <div class="input-icon-container"><span class="material-symbols-rounded">smartphone</span></div>
                                        <input type="text" name="contact_number" value="<?= htmlspecialchars($user['contact_number'] ?? '') ?>" disabled required oninput="formatPhone(this)" class="w-full profile-input has-icon rounded-2xl px-6 py-4 text-sm font-bold shadow-inner" placeholder="09XX-XXX-XXXX">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Security Reveal Section -->
                        <div class="edit-reveal w-full">
                            <div class="p-10 rounded-[32px] bg-primary/[0.03] border border-primary/10 shadow-2xl relative overflow-hidden group/sec">
                                <div class="absolute top-0 right-0 size-24 bg-primary/10 rounded-full blur-2xl -translate-y-1/2 translate-x-1/2 pointer-events-none group-hover/sec:scale-125 transition-transform duration-700"></div>
                                <h4 class="text-[10px] font-black italic text-white uppercase tracking-[0.2em] mb-8 flex items-center gap-3">
                                    <span class="material-symbols-rounded text-primary text-2xl">lock_reset</span>
                                    Update Security Key
                                </h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-10 gap-y-8">
                                    <div class="space-y-2">
                                        <div class="flex items-center justify-between ml-1">
                                            <label class="text-[9px] uppercase font-bold text-[--text-main]/60 tracking-widest">New Password</label>
                                            <p id="strength-text" class="text-[9px] font-black uppercase tracking-widest min-h-[15px]"></p>
                                        </div>
                                        <div class="relative">
                                            <input type="password" name="new_password" id="new_pass" onkeyup="checkStrength(this.value)" placeholder="Leave blank to keep current" class="w-full bg-black/40 border border-white/10 rounded-2xl px-6 py-4 text-sm text-[--text-main] focus:border-primary focus:outline-none transition-all placeholder:text-[--text-main]/20" disabled>
                                            <button type="button" onclick="togglePass('new_pass', 'icon_new')" class="absolute right-6 top-1/2 -translate-y-1/2 text-[--text-main]/20 hover:text-white transition-colors">
                                                <span class="material-symbols-rounded text-lg" id="icon_new">visibility_off</span>
                                            </button>
                                        </div>
                                        <div class="h-1.5 w-full bg-white/5 rounded-full mt-4 overflow-hidden">
                                            <div id="strength-bar" class="strength-bar w-0 bg-rose-500 h-full"></div>
                                        </div>
                                        <div class="mt-6 grid grid-cols-2 gap-x-4 gap-y-3">
                                            <div id="req-length" class="flex items-center gap-2 text-[--text-main]/40 transition-all duration-300">
                                                <span class="material-symbols-rounded text-xs shrink-0">radio_button_unchecked</span>
                                                <span class="text-[9px] font-black uppercase tracking-widest">8+ Characters</span>
                                            </div>
                                            <div id="req-upper" class="flex items-center gap-2 text-[--text-main]/40 transition-all duration-300">
                                                <span class="material-symbols-rounded text-xs shrink-0">radio_button_unchecked</span>
                                                <span class="text-[9px] font-black uppercase tracking-widest">Uppercase</span>
                                            </div>
                                            <div id="req-number" class="flex items-center gap-2 text-[--text-main]/40 transition-all duration-300">
                                                <span class="material-symbols-rounded text-xs shrink-0">radio_button_unchecked</span>
                                                <span class="text-[9px] font-black uppercase tracking-widest">Number</span>
                                            </div>
                                            <div id="req-special" class="flex items-center gap-2 text-[--text-main]/40 transition-all duration-300">
                                                <span class="material-symbols-rounded text-xs shrink-0">radio_button_unchecked</span>
                                                <span class="text-[9px] font-black uppercase tracking-widest">Special Char</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="space-y-2">
                                        <div class="flex items-center justify-between ml-1">
                                            <label class="text-[9px] uppercase font-bold text-[--text-main]/60 tracking-widest">Confirm New Password</label>
                                            <p class="text-[9px] min-h-[15px]"></p>
                                        </div>
                                        <div class="relative">
                                            <input type="password" name="confirm_password" id="confirm_pass" placeholder="Re-enter new password" class="w-full bg-black/40 border border-white/10 rounded-2xl px-6 py-4 text-sm text-[--text-main] focus:border-primary focus:outline-none transition-all placeholder:text-[--text-main]/20" disabled>
                                            <button type="button" onclick="togglePass('confirm_pass', 'icon_confirm')" class="absolute right-6 top-1/2 -translate-y-1/2 text-[--text-main]/20 hover:text-white transition-colors">
                                                <span class="material-symbols-rounded text-lg" id="icon_confirm">visibility_off</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Master Submit Section -->
                        <div id="save-section" class="hidden border-t border-white/5 pt-10 mt-6 animate-fade-in">
                            <div class="glass-card border border-primary/20 p-8 flex flex-col md:flex-row items-center justify-between gap-8 shadow-2xl relative overflow-hidden group/save">
                                <div class="absolute inset-0 bg-primary/5 opacity-0 group-hover/save:opacity-100 transition-opacity"></div>
                                <div class="flex items-center gap-6 shrink-0 relative z-10">
                                    <div class="size-16 rounded-full bg-primary/10 flex items-center justify-center text-primary border border-primary/30 shadow-inner group-hover/save:scale-110 transition-transform duration-500">
                                        <span class="material-symbols-rounded text-3xl">shield_locked</span>
                                    </div>
                                    <div>
                                        <h4 class="text-base font-black italic uppercase tracking-tighter text-white">Confirm Changes</h4>
                                        <p class="text-[9px] font-bold text-[--text-main]/40 uppercase tracking-widest mt-1">Enter current password to save changes.</p>
                                    </div>
                                </div>
                                <div class="flex flex-col sm:flex-row items-center gap-6 w-full md:w-auto relative z-10 shrink-0">
                                    <div class="relative w-full sm:w-56 group/input">
                                        <input type="password" name="current_password" id="current_pass" required placeholder="Verify Password" disabled class="w-full bg-black/40 border border-white/10 rounded-2xl px-6 py-4 text-[11px] font-black italic text-[--text-main] focus:border-primary focus:outline-none transition-all pr-14 placeholder:text-[--text-main]/20 tracking-[0.2em] font-sans">
                                        <button type="button" onclick="togglePass('current_pass', 'icon_cur')" class="absolute right-5 top-1/2 -translate-y-1/2 text-[--text-main]/20 hover:text-white transition-colors">
                                            <span class="material-symbols-rounded text-lg" id="icon_cur">visibility_off</span>
                                        </button>
                                    </div>
                                    <button type="submit" class="shrink-0 text-white bg-primary hover:bg-primary/90 px-10 py-4 rounded-2xl text-[11px] font-black italic uppercase tracking-[0.2em] transition-all hover:scale-105 active:scale-95 shadow-xl shadow-primary/20">SAVE CHANGES</button>
                                </div>
                            </div>
                        </div>

                        <input type="hidden" name="remove_profile_picture" id="remove-profile-input" value="0">
                    </form>
                </div>
            </div>
        </main>
    </div>

    <!-- Elite Interaction System (Custom Modal) -->
    <div id="custom-modal">
        <div class="absolute inset-0 bg-black/40 backdrop-blur-md transition-opacity" onclick="closeModal()"></div>
        <div class="relative z-10 glass-card bg-[--background]/95 w-full max-w-sm rounded-[40px] p-10 text-center transform scale-95 opacity-0 transition-all duration-300 shadow-2xl" id="modal-content">
            <div class="size-24 rounded-[30px] bg-primary/10 text-primary flex items-center justify-center mx-auto mb-8 border border-primary/20 shadow-inner"><span class="material-symbols-rounded text-5xl" id="modal-icon">info</span></div>
            <h3 class="text-2xl font-black italic text-white uppercase tracking-tighter mb-4" id="modal-title">Notification</h3>
            <p class="text-[--text-main]/60 text-[11px] font-bold tracking-wider mb-10 px-4 leading-relaxed" id="modal-message"></p>
            <div class="flex gap-4 justify-center" id="modal-actions"></div>
        </div>
    </div>

    <script>
        function updateHeaderClock() {
            const now = new Date();
            const clockEl = document.getElementById('headerClock');
            if (clockEl) clockEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }
        setInterval(updateHeaderClock, 1000);
        window.addEventListener('DOMContentLoaded', updateHeaderClock);

        let initialValues = {};

        function toggleEdit() {
            const form = document.getElementById('profile-form');
            const inputs = form.querySelectorAll('input, select, textarea');
            const saveSection = document.getElementById('save-section');
            const editBtn = document.getElementById('edit-btn');
            const discardBtn = document.getElementById('discard-btn');
            const indicator = document.getElementById('edit-indicator');
            const profileLabel = document.getElementById('profile-label');
            const removeBtn = document.getElementById('remove-photo-btn');
            const hasPhoto = !!document.getElementById('profilePreviewImg');

            document.body.classList.add('edit-mode');
            inputs.forEach(input => {
                if (input.name !== 'staff_role') {
                    input.disabled = false;
                    initialValues[input.name] = input.value;
                }
            });

            editBtn.classList.add('hidden');
            discardBtn.classList.remove('hidden');
            saveSection.classList.remove('hidden');
            indicator.classList.remove('hidden');
            profileLabel.classList.remove('hidden');
            if (hasPhoto) removeBtn.classList.remove('hidden');

            const firstInput = form.querySelector('input:not([disabled])');
            if (firstInput && firstInput.type !== 'hidden') firstInput.focus();
        }

        function cancelEdit() { window.location.reload(); }

        function previewProfileImage(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = e => {
                    const img = document.getElementById('profilePreviewImg');
                    if (img) img.src = e.target.result;
                    else {
                        const container = document.getElementById('profile-container');
                        container.innerHTML = `<img id="profilePreviewImg" src="${e.target.result}" class="size-full aspect-square object-cover transition-transform duration-700 group-hover:scale-110">` + container.innerHTML;
                    }
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        function removeProfilePhoto() {
            showModal('Remove Photo?', 'Are you sure you want to delete your profile photo?', 'confirm', () => {
                document.getElementById('remove-profile-input').value = '1';
                document.getElementById('profile-container').innerHTML = `<div id="profilePlaceholder" class="size-full flex items-center justify-center bg-gradient-to-br from-primary/20 via-primary/10 to-transparent text-primary text-5xl font-black italic uppercase"><?php 
                                            $f = $user['first_name'] ?? $_SESSION['first_name'] ?? 'A';
                                            $l = $user['last_name'] ?? $_SESSION['last_name'] ?? '';
                                            echo strtoupper($f[0] . ($l[0] ?? ''));
                                            ?></div>` + document.getElementById('profile-label').outerHTML;
            });
        }

        function togglePass(inputId, iconId) {
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
                const icon = req.el.querySelector('.material-symbols-rounded');
                if (req.met) {
                    strength += 25;
                    req.el.classList.remove('text-[--text-main]/40');
                    req.el.classList.add('text-emerald-400');
                    icon.innerText = 'check_circle';
                } else {
                    req.el.classList.add('text-[--text-main]/40');
                    req.el.classList.remove('text-emerald-400');
                    icon.innerText = 'radio_button_unchecked';
                }
            });

            bar.style.width = strength + '%';
            if (password.length === 0) { bar.style.width = '0%'; text.innerText = ''; }
            else if (strength <= 25) { bar.className = 'strength-bar h-full transition-all bg-rose-500'; text.innerText = 'WEAK'; text.className = 'text-[9px] font-black uppercase text-rose-500 min-h-[15px]'; }
            else if (strength <= 75) { bar.className = 'strength-bar h-full transition-all bg-amber-500'; text.innerText = (strength <= 50) ? 'FAIR' : 'GOOD'; text.className = 'text-[9px] font-black uppercase text-amber-500 min-h-[15px]'; }
            else { bar.className = 'strength-bar h-full transition-all bg-emerald-400'; text.innerText = 'STRONG'; text.className = 'text-[9px] font-black uppercase text-emerald-400 min-h-[15px]'; }
        }

        function formatPhone(i) {
            let v = i.value.replace(/\D/g, '').substring(0, 11);
            if (v.length > 4) v = v.substring(0, 4) + '-' + v.substring(4);
            if (v.length > 8) v = v.substring(0, 8) + '-' + v.substring(8);
            i.value = v;
        }

        function showModal(title, message, type, callback = null) {
            const m = document.getElementById('custom-modal');
            const c = document.getElementById('modal-content');
            document.getElementById('modal-title').innerText = title;
            document.getElementById('modal-message').innerText = message;
            const actionsDiv = document.getElementById('modal-actions');
            actionsDiv.innerHTML = '';

            if (type === 'confirm') {
                const cn = document.createElement('button');
                cn.className = "px-10 py-4 rounded-2xl bg-white/5 text-[--text-main]/40 text-[10px] font-black uppercase tracking-widest hover:text-white transition-colors";
                cn.innerText = "Cancel"; cn.onclick = closeModal;
                actionsDiv.appendChild(cn);

                const cf = document.createElement('button');
                cf.className = "px-10 py-4 rounded-2xl bg-primary text-white text-[10px] font-black uppercase tracking-widest shadow-xl shadow-primary/20";
                cf.innerText = "Confirm"; cf.onclick = () => { if (callback) callback(); closeModal(); };
                actionsDiv.appendChild(cf);
            } else {
                const ok = document.createElement('button');
                ok.className = "w-full py-5 rounded-2xl bg-white/10 text-white text-[10px] font-black uppercase tracking-widest hover:bg-white/20 transition-all";
                ok.innerText = "Understood"; ok.onclick = closeModal;
                actionsDiv.appendChild(ok);
            }

            m.classList.add('flex'); m.classList.remove('hidden');
            setTimeout(() => { c.classList.remove('scale-95', 'opacity-0'); c.classList.add('scale-100', 'opacity-100'); }, 10);
        }

        function closeModal() {
            const m = document.getElementById('custom-modal');
            const c = document.getElementById('modal-content');
            c.classList.add('scale-95', 'opacity-0');
            setTimeout(() => { m.classList.add('hidden'); m.classList.remove('flex'); }, 300);
        }

        function validateAndSubmit(e) {
            e.preventDefault();
            const form = e.target;
            const phone = form.contact_number.value.replace(/-/g, '');
            const pass = form.current_password.value;

            if (phone.length !== 11) { showModal('Error', 'Contact number must be 11 digits.', 'error'); return; }
            if (!pass) {
                 const pi = document.getElementById('current_pass');
                 pi.focus(); pi.classList.add('border-rose-500/50');
                 setTimeout(() => pi.classList.remove('border-rose-500/50'), 2000);
                 return;
            }
            showModal('Confirm Changes', 'Are you sure you want to update your admin profile?', 'confirm', () => form.submit());
        }
    </script>
</body>
</html>