<?php
session_start();
require_once '../db.php';
require_once '../includes/audit_logger.php';

// Security Check: Only Tenants can access
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'tenant') {
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$gym_id = $_SESSION['gym_id'] ?? null; 
$success_msg = $_SESSION['success_msg'] ?? '';
$error_msg = $_SESSION['error_msg'] ?? '';
unset($_SESSION['success_msg'], $_SESSION['error_msg']);

// Helper function for Base64 conversion
function convertFileToBase64($fileInputName)
{
    if (isset($_FILES[$fileInputName]) && $_FILES[$fileInputName]['error'] == 0) {
        $tmpPath = $_FILES[$fileInputName]['tmp_name'];
        $fileType = $_FILES[$fileInputName]['type'];
        $fileData = file_get_contents($tmpPath);
        return 'data:' . $fileType . ';base64,' . base64_encode($fileData);
    }
    return null;
}

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

// Handle Profile & Password Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $current_password = $_POST['current_password'] ?? '';
    $username = trim($_POST['username']);
    $first_name = trim($_POST['first_name']);
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $contact_number = trim($_POST['contact_number']);

    // New Fields
    $birth_date = trim($_POST['birth_date'] ?? '');
    $sex = trim($_POST['sex'] ?? '');
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    $now = date('Y-m-d H:i:s');
    $today = date('Y-m-d');

    try {
        // --- Server-Side Validation ---
        if (preg_match('/[0-9]/', $first_name)) throw new Exception("First name cannot contain numbers.");
        if (preg_match('/[0-9]/', $middle_name)) throw new Exception("Middle name cannot contain numbers.");
        if (preg_match('/[0-9]/', $last_name)) throw new Exception("Last name cannot contain numbers.");

        $raw_contact = str_replace('-', '', $contact_number);
        if (!ctype_digit($raw_contact) || strlen($raw_contact) !== 11) throw new Exception("Contact number must be exactly 11 digits.");

        if ($birth_date > $today) throw new Exception("Birth date cannot be a future date.");
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !str_ends_with(strtolower($email), '@gmail.com')) throw new Exception("Email must be a valid @gmail.com address.");

        // Verify Current Password
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $userData = $stmt->fetch();

        if (empty($current_password) || !password_verify($current_password, $userData['password_hash'])) throw new Exception("Incorrect current password.");

        $pdo->beginTransaction();

        $stmtOld = $pdo->prepare("SELECT username, first_name, middle_name, last_name, email, contact_number, birth_date, sex, profile_picture FROM users WHERE user_id = ?");
        $stmtOld->execute([$user_id]);
        $old_values = $stmtOld->fetch(PDO::FETCH_ASSOC);

        $remove_profile = isset($_POST['remove_profile_picture']) && $_POST['remove_profile_picture'] === '1';
        $profile_picture = convertFileToBase64('profile_picture');

        $updates = ["username = ?", "first_name = ?", "middle_name = ?", "last_name = ?", "email = ?", "contact_number = ?", "birth_date = ?", "sex = ?", "updated_at = ?"];
        $params = [$username, $first_name, $middle_name, $last_name, $email, $contact_number, $birth_date, $sex, $now];
        $new_values = ['username' => $username, 'first_name' => $first_name, 'middle_name' => $middle_name, 'last_name' => $last_name, 'email' => $email, 'contact_number' => $contact_number, 'birth_date' => $birth_date, 'sex' => $sex];

        if ($remove_profile) { $updates[] = "profile_picture = NULL"; $new_values['profile_picture'] = 'REMOVED'; }
        if ($profile_picture) { $updates[] = "profile_picture = ?"; $params[] = $profile_picture; $new_values['profile_picture'] = '[IMAGE DATA]'; }

        if (!empty($new_password)) {
            if ($new_password !== $confirm_password) throw new Exception("New passwords do not match.");
            if (strlen($new_password) < 8) throw new Exception("New password must be at least 8 characters long.");
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
        header("Location: profile.php?status=success");
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error_msg'] = $e->getMessage();
        header("Location: profile.php?status=error");
        exit;
    }
}

// 1. Fetch User Info
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// 2. Fetch Gym & Application Info
$stmtGym = $pdo->prepare("SELECT g.*, a.address_line, a.barangay, a.city, a.province, a.region 
                          FROM gyms g 
                          LEFT JOIN addresses a ON g.address_id = a.address_id 
                          WHERE g.owner_user_id = ? LIMIT 1");
$stmtGym->execute([$user_id]);
$gym = $stmtGym->fetch(PDO::FETCH_ASSOC);

$app_data = null;
$payout_info = ['bank' => 'N/A', 'acc_name' => 'N/A', 'acc_no' => 'N/A'];

if ($gym) {
    if (!$gym_id) $_SESSION['gym_id'] = $gym['gym_id'];
    
    if ($gym['application_id']) {
        $stmtApp = $pdo->prepare("SELECT * FROM gym_owner_applications WHERE application_id = ? LIMIT 1");
        $stmtApp->execute([$gym['application_id']]);
        $app_data = $stmtApp->fetch(PDO::FETCH_ASSOC);
        
        if (!empty($app_data['remarks'])) {
            if (preg_match('/Bank: (.*?) \| Acct Name: (.*?) \| Acct No: (.*?)(?:\n|$)/', $app_data['remarks'], $matches)) {
                $payout_info = ['bank' => trim($matches[1]), 'acc_name' => trim($matches[2]), 'acc_no' => trim($matches[3])];
            }
        }
    }
}

$page_title = "My Profile";
$active_page = "profile";

// ── 4-Color Elite Branding System ─────────────────────────────────────────────
// Hard defaults
$configs = [
    'system_name'     => $gym['gym_name'] ?? 'Horizon Gym',
    'system_logo'     => '',
    'theme_color'     => '#8c2bee',
    'secondary_color' => '#a1a1aa',
    'text_color'      => '#d1d5db',
    'bg_color'        => '#0a090d',
    'card_color'      => '#141216',
    'auto_card_theme' => '1',
    'font_family'     => 'Lexend',
    'page_slug'       => '',
];

// Merge global settings (user_id = 0)
$stmtGlobal = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = 0");
$stmtGlobal->execute();
foreach (($stmtGlobal->fetchAll(PDO::FETCH_KEY_PAIR) ?: []) as $k => $v) {
    if ($v !== null && $v !== '') $configs[$k] = $v;
}

// Merge tenant-specific settings (user_id = ?)
$stmtTenant = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = ?");
$stmtTenant->execute([$user_id]);
foreach (($stmtTenant->fetchAll(PDO::FETCH_KEY_PAIR) ?: []) as $k => $v) {
    if ($v !== null && $v !== '') $configs[$k] = $v;
}

// Map common keys for convenience
$page = [
    'logo_path'   => $configs['system_logo'] ?? '',
    'theme_color' => $configs['theme_color'],
    'bg_color'    => $configs['bg_color'],
    'page_slug'   => $configs['page_slug'] ?? '',
    'system_name' => $configs['system_name'] ?? ($gym['gym_name'] ?? 'Owner Portal'),
];

$joined = isset($user['created_at']) ? date("F Y", strtotime($user['created_at'])) : 'N/A';
$status = "Active";
$role = "Gym Owner"; 
$sex = htmlspecialchars($user['sex'] ?? '');
$birthDate = htmlspecialchars($user['birth_date'] ?? '');
?>
<!DOCTYPE html>
<html class="dark" lang="en">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title><?= $page_title ?> | Horizon System</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet" />
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "var(--primary)",
                        "background-dark": "var(--background)",
                        "surface-dark": "var(--card-bg)",
                        "text-main": "var(--text-main)",
                        "highlight": "var(--highlight)",
                    }
                }
            }
        }
    </script>
    <style>
        :root {
            --primary: <?= $configs['theme_color'] ?? '#8c2bee' ?>;
            --primary-rgb: <?= hexToRgb($configs['theme_color'] ?? '#8c2bee') ?>;
            --highlight: <?= $configs['secondary_color'] ?? '#a1a1aa' ?>;
            --text-main: <?= $configs['text_color'] ?? '#d1d5db' ?>;
            --background: <?= $configs['bg_color'] ?? '#0a090d' ?>;
            --card-blur: <?= $configs['card_blur'] ?? '20px' ?>;
            --card-bg: <?= ($configs['auto_card_theme'] ?? '1') === '1' ? 'rgba(' . hexToRgb($configs['theme_color'] ?? '#8c2bee') . ', 0.05)' : ($configs['card_color'] ?? '#141216') ?>;
        }

        body {
            font-family: '<?= $configs['font_family'] ?? 'Lexend' ?>', sans-serif;
            background-color: var(--background);
            color: var(--text-main);
        }

        .glass-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border, rgba(255, 255, 255, 0.05));
            backdrop-filter: blur(var(--card-blur));
            box-shadow: var(--card-shadow, 0 10px 30px rgba(0, 0, 0, 0.2)), var(--card-glow, 0 0 0 transparent);
            transition: all 0.3s ease;
        }

        /* Sidebar Layout Synchronization */
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
        }
        .side-nav:hover ~ .main-content { margin-left: 300px; }

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
            transition: all 0.4s cubic-bezier(0.4,0,0.2,1);
            margin: 0 !important; pointer-events: none;
        }
        .side-nav:hover .nav-section-label {
            max-height: 20px; opacity: 1;
            margin-bottom: 8px !important; pointer-events: auto;
        }

        /* Premium Nav items (Synced with Dashboard) */
        .nav-item {
            display: flex; align-items: center; gap: 16px;
            padding: 10px 38px;
            transition: opacity 0.2s ease, color 0.2s ease;
            text-decoration: none; white-space: nowrap;
            font-size: 11px; font-weight: 800;
            text-transform: uppercase; letter-spacing: 0.05em;
            color: color-mix(in srgb, var(--text-main) 45%, transparent);
        }
        .nav-item:hover { color: var(--text-main); }
        .nav-item .material-symbols-outlined {
            color: var(--highlight);
            transition: transform 0.2s ease;
        }
        .nav-item:hover .material-symbols-outlined { transform: scale(1.12); }
        .nav-item.active { color: var(--primary) !important; position: relative; }
        .nav-item.active .material-symbols-outlined { color: var(--primary); }
        .nav-item.active::after {
            content: ''; position: absolute;
            right: 0; top: 50%; transform: translateY(-50%);
            width: 4px; height: 24px;
            background: var(--primary); border-radius: 4px 0 0 4px;
        }


        /* Invisible Scroll System */
        *::-webkit-scrollbar {
            display: none !important;
        }

        * {
            -ms-overflow-style: none !important;
            scrollbar-width: none !important;
        }

        .profile-input { background-color: rgba(255, 255, 255, 0.03); border: 1px solid rgba(255, 255, 255, 0.1); color: var(--text-main); transition: all 0.3s ease; }
        .profile-input:disabled { background-color: transparent !important; border-color: transparent !important; color: #9ca3af !important; cursor: default !important; }
        .profile-input.has-icon { padding-left: 3.5rem !important; }
        .tab-content:not(.edit-mode) .profile-input.has-icon { padding-left: 0 !important; }
        .profile-input:not(:disabled):focus { background-color: rgba(255, 255, 255, 0.05); border-color: var(--primary); outline: none; box-shadow: 0 0 0 1px rgba(var(--primary-rgb, 140, 43, 238), 0.1); }
        
        .material-symbols-outlined { font-family: 'Material Symbols Outlined' !important; font-display: block; }
        
        .input-icon-container { position: absolute; top: 50%; left: 0; padding-left: 1.25rem; transform: translateY(-50%); display: flex; align-items: center; color: rgba(255, 255, 255, 0.4); pointer-events: none; transition: all 0.3s ease; }
        .input-icon-container span { font-size: 1.1rem; margin-top: 2px; }
        
        /* Hide icons strictly and align text flush (nakasagad) in view mode */
        .tab-content:not(.edit-mode) .input-icon-container { display: none !important; }
        .tab-content:not(.edit-mode) .profile-input.has-icon { padding-left: 0 !important; }
        .group:focus-within .input-icon-container { color: var(--primary); transform: scale(1.1); }

        .pill-save-bar { background: rgba(20, 18, 22, 0.6); border: 1px solid rgba(255, 255, 255, 0.1); backdrop-filter: blur(20px); border-radius: 999px; padding: 12px 12px 12px 24px; display: flex; align-items: center; justify-content: space-between; gap: 24px; box-shadow: 0 20px 40px rgba(0,0,0,0.4); }
        .save-bar-icon-circle { width: 56px; height: 56px; border-radius: 50%; border: 1px solid rgba(255, 255, 255, 0.15); display: flex; align-items: center; justify-content: center; color: var(--primary); flex-shrink: 0; }
        .pill-save-input { background: rgba(255, 255, 255, 0.03); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 12px 48px 12px 20px; font-size: 11px; font-weight: 900; font-style: italic; text-transform: uppercase; letter-spacing: 0.1em; color: white; width: 180px; transition: all 0.3s ease; }
        .pill-save-input:focus { border-color: var(--primary); outline: none; background: rgba(255, 255, 255, 0.05); }
        .pill-save-btn { color: var(--primary); font-size: 11px; font-weight: 900; font-style: italic; text-transform: uppercase; letter-spacing: 0.2em; padding: 0 16px; transition: all 0.3s ease; display: flex; align-items: center; gap: 8px; }
        .pill-save-btn:hover { opacity: 0.8; transform: translateX(4px); }

        .profile-section-title { font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: var(--primary); margin-bottom: 1.5rem; padding-bottom: 0.5rem; border-bottom: 1px solid rgba(255, 255, 255, 0.05); }
        .edit-reveal { max-height: 0; opacity: 0; overflow: hidden; transition: all 0.6s cubic-bezier(0.4, 0, 0.2, 1); }
        .tab-content.edit-mode .edit-reveal { max-height: 1000px; opacity: 1; margin-top: 1.5rem; }
        
        #main-wrapper { max-width: 1000px; transition: max-width 0.5s cubic-bezier(0.4, 0, 0.2, 1); }
        body.has-editing-tab #main-wrapper { max-width: 1200px; }

        .tab-btn { position: relative; padding: 12px 24px; font-size: 10px; font-weight: 900; text-transform: uppercase; letter-spacing: 0.2em; color: #6b7280; transition: all 0.3s ease; }
        .tab-btn.active { color: var(--primary); }
        .tab-btn.active::after { content: ''; position: absolute; bottom: 0; left: 24px; right: 24px; height: 2px; background: var(--primary); border-radius: 2px; }
        .tab-content { display: none; animation: fadeIn 0.4s ease-out; }
        .tab-content.active { display: block; }

        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        #custom-modal { position: fixed; top: 0; right: 0; bottom: 0; left: 110px; z-index: 200; display: none; align-items: center; justify-content: center; background: rgba(10, 9, 13, 0.8); backdrop-filter: blur(8px); padding: 20px; transition: left 0.4s cubic-bezier(0.4, 0, 0.2, 1); }
        .side-nav:hover~#custom-modal { left: 300px; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
    </style>
</head>

<body class="antialiased flex h-screen overflow-hidden no-scrollbar">
    <?php include '../includes/tenant_sidebar.php'; ?>

    <div class="main-content flex-1 flex flex-col min-w-0 overflow-y-auto no-scrollbar">
        <main id="main-wrapper" class="flex-1 p-6 md:p-8 lg:p-10 w-full mx-auto pb-32 animate-fade-in">
            <header class="mb-8 flex flex-row justify-between items-end gap-6">
                <div>
                    <h2 class="text-3xl font-black italic uppercase tracking-tighter leading-none"><span class="text-[--text-main]">Profile</span> <span class="text-primary">Center</span></h2>
                    <p class="text-gray-500 text-[10px] font-bold uppercase tracking-widest mt-2 px-1">Security & Identity Management</p>
                </div>
                <div class="flex flex-col items-end justify-center">
                    <p id="headerClock" class="text-white font-black italic text-2xl leading-none hover:text-primary uppercase tracking-tighter">00:00:00 AM</p>
                    <p class="text-primary text-[10px] font-black uppercase tracking-[0.2em] leading-none mt-2"><?= date('l, M d, Y') ?></p>
                </div>
            </header>

            <div class="flex items-center gap-4 mb-10 border-b border-white/5 no-scrollbar overflow-x-auto">
                <button onclick="switchTab('personal')" id="btn-personal" class="tab-btn active">My Profile</button>
                <button onclick="switchTab('business')" id="btn-business" class="tab-btn">Business Information</button>
            </div>

            <?php if ($success_msg): ?><div id="statusAlert" class="bg-emerald-500/10 border border-emerald-500/20 p-4 rounded-xl text-emerald-500 text-[11px] font-black uppercase mb-8 flex items-center justify-between"><div class="flex items-center gap-3"><span class="material-symbols-outlined text-base">check_circle</span><span>Profile updated!</span></div><button onclick="document.getElementById('statusAlert').style.display='none'" class="material-symbols-outlined text-sm">close</button></div><?php endif; ?>
            <?php if ($error_msg): ?><div id="statusAlert" class="bg-rose-500/10 border border-rose-500/20 p-4 rounded-xl text-rose-500 text-[11px] font-black uppercase mb-8 flex items-center justify-between"><div class="flex items-center gap-3"><span class="material-symbols-outlined text-base">warning</span><span><?= $error_msg ?></span></div><button onclick="document.getElementById('statusAlert').style.display='none'" class="material-symbols-outlined text-sm">close</button></div><?php endif; ?>

            <div class="flex flex-col xl:flex-row gap-8 items-start justify-center">
                <!-- Left Panel -->
                <div class="w-full xl:w-72 shrink-0 flex flex-col gap-6">
                    <div class="relative overflow-hidden rounded-3xl bg-[--card-bg] backdrop-blur-[--card-blur] border border-white/5 shadow-2xl p-8 text-center group">
                        <div class="absolute top-0 right-0 w-40 h-40 bg-primary/10 rounded-full blur-3xl -translate-y-1/2 translate-x-1/2 pointer-events-none group-hover:scale-110"></div>
                        
                        <!-- User Profile Content -->
                        <div id="sidebar-user-content" class="relative z-10 transition-all duration-300">
                            <div class="w-32 h-32 mx-auto rounded-[32px] p-1 bg-gradient-to-br from-white/10 to-white/5 border border-white/10 mb-6 shadow-2xl overflow-hidden group">
                                <div id="profile-container" class="w-full h-full rounded-[30px] bg-black/20 flex items-center justify-center overflow-hidden relative">
                                    <?php if (!empty($user['profile_picture'])): ?><img id="profilePreviewImg" src="<?= $user['profile_picture'] ?>" class="size-full aspect-square object-cover object-center transition-transform duration-700 group-hover:scale-110"><?php else: ?><div id="profilePlaceholder" class="size-full flex items-center justify-center bg-gradient-to-br from-primary/20 to-transparent text-primary text-4xl font-black italic"><?= strtoupper($user['first_name'][0] . ($user['last_name'][0] ?? '')) ?></div><?php endif; ?>
                                    <label id="profile-label" class="absolute inset-0 bg-black/80 opacity-0 group-hover:opacity-100 transition-all duration-300 flex flex-col items-center justify-center gap-3 backdrop-blur-md cursor-pointer border-4 border-dashed border-white/10 rounded-[30px] hidden"><span class="material-symbols-outlined text-2xl">add_a_photo</span><input type="file" name="profile_picture" form="profile-form" class="hidden" id="profile-input-file" accept="image/*" onchange="previewProfileImage(this)"></label>
                                    <button type="button" id="remove-photo-btn" onclick="removeProfilePhoto()" class="absolute top-2.5 right-2.5 size-7 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-500 flex items-center justify-center opacity-0 hover:bg-rose-500 hover:text-white z-20 hidden backdrop-blur-md"><span class="material-symbols-outlined text-base">delete</span></button>
                                </div>
                            </div>
                            <h2 class="text-2xl font-black italic uppercase tracking-tighter text-white mb-1"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></h2>
                            <p class="text-[10px] text-primary mb-5 font-bold uppercase tracking-widest"><?= $role ?></p>
                            <div class="flex justify-center gap-2 mb-8"><span class="px-4 py-1.5 rounded-full text-[9px] font-black uppercase tracking-widest border border-emerald-500/20 bg-emerald-500/10 text-emerald-400"><?= $status ?></span></div>
                            <div class="pt-6 border-t border-white/5"><p class="text-[9px] uppercase tracking-[0.2em] text-gray-600 font-black mb-1">Joined Since</p><p class="text-sm font-bold text-gray-300 italic"><?= $joined ?></p></div>
                        </div>

                        <!-- Gym Profile Content -->
                        <div id="sidebar-gym-content" class="hidden relative z-10 transition-all duration-300">
                            <div class="w-32 h-32 mx-auto rounded-[32px] p-1 bg-gradient-to-br from-white/10 to-white/5 border border-white/10 mb-6 shadow-2xl overflow-hidden">
                                <div class="w-full h-full rounded-[30px] bg-black/20 flex items-center justify-center overflow-hidden relative">
                                    <img src="<?= htmlspecialchars($configs['system_logo'] ?? '../assests/horizon logo.png') ?>" class="size-full object-contain transition-transform duration-700 hover:scale-110">
                                </div>
                            </div>
                            <h2 class="text-2xl font-black italic uppercase tracking-tighter text-white mb-1"><?= htmlspecialchars($gym['gym_name'] ?? 'Horizon System') ?></h2>
                            <p class="text-[10px] text-primary mb-5 font-bold uppercase tracking-widest">Gym Establishment</p>
                            <div class="flex justify-center gap-2 mb-8"><span class="px-4 py-1.5 rounded-full text-[9px] font-black uppercase tracking-widest border border-emerald-500/20 bg-emerald-500/10 text-emerald-400">Verified Facility</span></div>
                            <div class="pt-6 border-t border-white/5"><p class="text-[9px] uppercase tracking-[0.2em] text-gray-600 font-black mb-1">Business Status</p><p class="text-sm font-bold text-gray-300 italic uppercase">Operational</p></div>
                        </div>
                    </div>
                    <button id="edit-btn" onclick="toggleEdit()" class="w-full py-4 bg-white/5 border border-white/10 hover:bg-white/10 hover:border-primary/30 text-[--text-main] text-[10px] font-black italic uppercase tracking-[0.2em] rounded-2xl flex items-center justify-center gap-3 group transition-all"><span class="material-symbols-outlined group-hover:text-primary transition-colors">edit_square</span><span>Edit Account</span></button>
                    <button id="discard-btn" onclick="cancelEdit()" class="hidden w-full py-4 bg-rose-500/10 border border-rose-500/20 text-rose-500 text-[10px] font-black italic uppercase tracking-[0.2em] rounded-2xl flex items-center justify-center gap-3 transition-all"><span class="material-symbols-outlined">close</span><span>Discard</span></button>
                </div>

                <div class="flex-1 glass-card rounded-[40px] p-8 no-scrollbar relative overflow-hidden group">
                    <!-- Tab Personal Info -->
                    <div id="tab-personal" class="tab-content active">
                        <div class="flex items-center justify-between mb-10"><h1 class="text-xl font-black italic uppercase tracking-tighter text-white">Security & Account</h1><div id="edit-indicator" class="hidden px-4 py-1.5 rounded-lg bg-primary/20 text-primary text-[9px] font-black italic uppercase animate-pulse">Editing Account</div></div>
                        <form id="profile-form" action="" method="POST" enctype="multipart/form-data" class="flex flex-col gap-10" onsubmit="validateAndSubmit(event)">
                            <input type="hidden" name="action" value="update_profile">
                            <div class="space-y-6">
                                <h3 class="profile-section-title">Account Information</h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-6">
                                    <div class="space-y-2">
                                        <label class="text-[9px] uppercase font-black text-[--text-main]/60 tracking-widest ml-1">Username</label>
                                        <div class="relative group">
                                            <span class="input-icon-container">
                                                <span class="material-symbols-outlined text-lg">alternate_email</span>
                                            </span>
                                            <input type="text" name="username" value="<?= htmlspecialchars($user['username'] ?? '') ?>" disabled required class="w-full profile-input has-icon rounded-2xl px-4 py-3.5 text-sm font-bold">
                                        </div>
                                    </div>
                                    <div class="space-y-2">
                                        <label class="text-[9px] uppercase font-black text-[--text-main]/60 tracking-widest ml-1">Full Name</label>
                                        <div class="relative group">
                                            <span class="input-icon-container">
                                                <span class="material-symbols-outlined text-lg">badge</span>
                                            </span>
                                            <input type="text" value="<?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?>" disabled class="w-full profile-input has-icon rounded-2xl px-4 py-3.5 text-sm font-bold">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="space-y-6">
                                <h3 class="profile-section-title">Personal Details</h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-6">
                                    <div class="space-y-2">
                                        <label class="text-[9px] uppercase font-black text-[--text-main]/60 tracking-widest ml-1">Birth Date</label>
                                        <div class="relative group">
                                            <span class="input-icon-container">
                                                <span class="material-symbols-outlined text-lg">cake</span>
                                            </span>
                                            <input type="date" name="birth_date" value="<?= $birthDate ?>" disabled required class="w-full profile-input has-icon rounded-2xl px-4 py-3.5 text-sm font-bold">
                                        </div>
                                    </div>
                                    <div class="space-y-2">
                                        <label class="text-[9px] uppercase font-black text-[--text-main]/60 tracking-widest ml-1">Sex</label>
                                        <div class="relative group">
                                            <span class="input-icon-container">
                                                <span class="material-symbols-outlined text-lg">wc</span>
                                            </span>
                                            <select name="sex" disabled required class="w-full profile-input has-icon rounded-2xl px-4 py-3.5 text-sm font-bold appearance-none">
                                                <option value="Male" <?= $sex === 'Male' ? 'selected' : '' ?>>Male</option>
                                                <option value="Female" <?= $sex === 'Female' ? 'selected' : '' ?>>Female</option>
                                                <option value="Other" <?= $sex === 'Other' ? 'selected' : '' ?>>Other</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="space-y-6">
                                <h3 class="profile-section-title">Contact Information</h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-6">
                                    <div class="space-y-2">
                                        <label class="text-[9px] uppercase font-black text-[--text-main]/60 tracking-widest ml-1">Contact No.</label>
                                        <div class="relative group">
                                            <span class="input-icon-container">
                                                <span class="material-symbols-outlined text-lg">smartphone</span>
                                            </span>
                                            <input type="text" name="contact_number" value="<?= htmlspecialchars($user['contact_number'] ?? '') ?>" disabled required oninput="formatContactNumber(this)" class="w-full profile-input has-icon rounded-2xl px-4 py-3.5 text-sm font-bold">
                                        </div>
                                    </div>
                                    <div class="space-y-2">
                                        <label class="text-[9px] uppercase font-black text-[--text-main]/60 tracking-widest ml-1">Email Address</label>
                                        <div class="relative group">
                                            <span class="input-icon-container">
                                                <span class="material-symbols-outlined text-lg">mail</span>
                                            </span>
                                            <input type="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" disabled required class="w-full profile-input has-icon rounded-2xl px-4 py-3.5 text-sm font-bold">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="edit-reveal">
                                <div class="p-8 rounded-3xl bg-primary/[0.03] border border-primary/10">
                                    <h4 class="text-[10px] font-black italic text-white uppercase tracking-[0.2em] mb-6 flex items-center gap-2">
                                        <span class="material-symbols-outlined text-primary text-xl">lock_reset</span>Update Password
                                    </h4>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                                        <div class="space-y-2">
                                            <div class="flex items-center justify-between ml-1">
                                                <label class="text-[9px] uppercase font-bold text-[--text-main]/60 tracking-widest">New Password</label>
                                                <p id="strength-text" class="text-[9px] font-black uppercase tracking-widest min-h-[15px]"></p>
                                            </div>
                                            <div class="relative">
                                                <input type="password" name="new_password" id="new_pass" onkeyup="checkStrength(this.value)" placeholder="Leave blank to keep current" class="w-full bg-[--background] border border-white/10 rounded-2xl px-4 py-3.5 text-sm text-[--text-main] focus:border-primary placeholder:text-[--text-main]/30" disabled>
                                                <button type="button" onclick="togglePassword('new_pass', 'icon_new')" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-600 hover:text-white"><span class="material-symbols-outlined text-lg" id="icon_new">visibility_off</span></button>
                                            </div>
                                            <div class="h-1.5 w-full bg-white/5 rounded-full mt-3 overflow-hidden"><div id="strength-bar" class="h-full w-0 transition-all duration-500 bg-rose-500"></div></div>
                                        </div>
                                        <div class="space-y-2">
                                            <label class="text-[9px] uppercase font-bold text-[--text-main]/60 tracking-widest ml-1">Confirm New Password</label>
                                            <div class="relative">
                                                <input type="password" name="confirm_password" id="confirm_pass" placeholder="Re-enter new password" class="w-full bg-[--background] border border-white/10 rounded-2xl px-4 py-3.5 text-sm text-[--text-main] focus:border-primary placeholder:text-[--text-main]/30" disabled>
                                                <button type="button" onclick="togglePassword('confirm_pass', 'icon_confirm')" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-600 hover:text-white"><span class="material-symbols-outlined text-lg" id="icon_confirm">visibility_off</span></button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Bottom Save Section Redesigned -->
                            <div id="save-section" class="hidden border-t border-white/5 pt-12 mt-8 animate-fade-in">
                                <div class="pill-save-bar">
                                    <div class="flex items-center gap-6">
                                        <div class="save-bar-icon-circle">
                                            <span class="material-symbols-outlined text-2xl">shield_locked</span>
                                        </div>
                                        <div>
                                            <h4 class="text-xs font-black italic uppercase tracking-widest text-white">Confirm Changes</h4>
                                            <p class="text-[8px] font-bold text-gray-500 uppercase tracking-widest mt-0.5">Enter current password to save changes.</p>
                                        </div>
                                    </div>

                                    <div class="flex items-center gap-4">
                                        <div class="relative group/input">
                                            <input type="password" name="current_password" id="current_pass" required placeholder="Password" disabled class="pill-save-input">
                                            <button type="button" onclick="togglePassword('current_pass', 'icon_curr')" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-700 hover:text-white transition-colors flex items-center justify-center">
                                                <span class="material-symbols-outlined text-lg" id="icon_curr">visibility_off</span>
                                            </button>
                                        </div>
                                        <button type="submit" class="pill-save-btn">
                                            SAVE CHANGES
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <input type="hidden" name="remove_profile_picture" id="remove-profile-input" value="0">
                        </form>
                    </div>

                    <!-- Tab Business Info -->
                    <div id="tab-business" class="tab-content">
                        <?php if ($gym): ?>
                            <div class="flex items-center justify-between mb-10">
                                <h1 class="text-xl font-black italic uppercase tracking-tighter text-white">Business Information</h1>
                                <div class="px-4 py-1.5 rounded-lg bg-emerald-500/10 text-emerald-400 text-[9px] font-black italic uppercase tracking-[0.2em] border border-emerald-500/20">Verified Partner</div>
                            </div>
                            
                            <div class="space-y-10">
                                <div class="space-y-8">
                                    <div class="space-y-6">
                                        <h3 class="profile-section-title">Brand Identity</h3>
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-6">
                                            <div class="space-y-2">
                                                <label class="text-[9px] uppercase font-black text-[--text-main]/60 tracking-widest ml-1">Official Business Name</label>
                                                <div class="relative group">
                                                    <span class="input-icon-container">
                                                        <span class="material-symbols-outlined text-lg">corporate_fare</span>
                                                    </span>
                                                    <input type="text" value="<?= htmlspecialchars($gym['business_name']) ?>" disabled class="w-full profile-input has-icon rounded-2xl px-4 py-3.5 text-sm font-bold">
                                                </div>
                                            </div>
                                            <div class="space-y-2">
                                                <label class="text-[9px] uppercase font-black text-[--text-main]/60 tracking-widest ml-1">System Code</label>
                                                <div class="relative group">
                                                    <span class="input-icon-container">
                                                        <span class="material-symbols-outlined text-lg">fingerprint</span>
                                                    </span>
                                                    <input type="text" value="<?= htmlspecialchars($gym['tenant_code']) ?>" disabled class="w-full profile-input has-icon rounded-2xl px-4 py-3.5 text-sm font-bold uppercase italic tracking-widest text-primary">
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="space-y-6">
                                        <h3 class="profile-section-title">Legal & Location</h3>
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-6">
                                            <div class="space-y-2">
                                                <label class="text-[9px] uppercase font-black text-[--text-main]/60 tracking-widest ml-1">BIR / TIN Number</label>
                                                <div class="relative group">
                                                    <span class="input-icon-container">
                                                        <span class="material-symbols-outlined text-lg">description</span>
                                                    </span>
                                                    <input type="text" value="<?= $app_data['bir_number'] ?? 'N/A' ?>" disabled class="w-full profile-input has-icon rounded-2xl px-4 py-3.5 text-sm font-bold">
                                                </div>
                                            </div>
                                            <div class="space-y-2">
                                                <label class="text-[9px] uppercase font-black text-[--text-main]/60 tracking-widest ml-1">Business Permit No.</label>
                                                <div class="relative group">
                                                    <span class="input-icon-container">
                                                        <span class="material-symbols-outlined text-lg">verified</span>
                                                    </span>
                                                    <input type="text" value="<?= $app_data['business_permit_no'] ?? 'N/A' ?>" disabled class="w-full profile-input has-icon rounded-2xl px-4 py-3.5 text-sm font-bold">
                                                </div>
                                            </div>
                                            <div class="space-y-2 md:col-span-2">
                                                <label class="text-[9px] uppercase font-black text-[--text-main]/60 tracking-widest ml-1">Gym Address</label>
                                                <div class="relative group">
                                                    <span class="input-icon-container">
                                                        <span class="material-symbols-outlined text-lg">location_on</span>
                                                    </span>
                                                    <input type="text" value="<?= htmlspecialchars($gym['address_line'] . ', ' . $gym['barangay'] . ', ' . $gym['city'] . ', ' . $gym['province'] . ', ' . $gym['region']) ?>" disabled class="w-full profile-input has-icon rounded-2xl px-4 py-3.5 text-sm font-bold">
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="space-y-6">
                                        <h3 class="profile-section-title">Financial Payout</h3>
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-6">
                                            <div class="space-y-2 md:col-span-2">
                                                <label class="text-[9px] uppercase font-black text-[--text-main]/60 tracking-widest ml-1">Bank / E-Wallet</label>
                                                <div class="relative group">
                                                    <span class="input-icon-container">
                                                        <span class="material-symbols-outlined text-lg">account_balance</span>
                                                    </span>
                                                    <input type="text" value="<?= $payout_info['bank'] ?>" disabled class="w-full profile-input has-icon rounded-2xl px-4 py-3.5 text-sm font-bold">
                                                </div>
                                            </div>
                                            <div class="space-y-2">
                                                <label class="text-[9px] uppercase font-black text-[--text-main]/60 tracking-widest ml-1">Account Holder</label>
                                                <div class="relative group">
                                                    <span class="input-icon-container">
                                                        <span class="material-symbols-outlined text-lg">person</span>
                                                    </span>
                                                    <input type="text" value="<?= $payout_info['acc_name'] ?>" disabled class="w-full profile-input has-icon rounded-2xl px-4 py-3.5 text-sm font-bold">
                                                </div>
                                            </div>
                                            <div class="space-y-2">
                                                <label class="text-[9px] uppercase font-black text-[--text-main]/60 tracking-widest ml-1">Account Number</label>
                                                <div class="relative group">
                                                    <span class="input-icon-container">
                                                        <span class="material-symbols-outlined text-lg">lock</span>
                                                    </span>
                                                    <input type="text" value="<?= $payout_info['acc_no'] ?>" disabled class="w-full profile-input has-icon rounded-2xl px-4 py-3.5 text-sm font-black italic tracking-[0.15em] text-primary">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            </div>
                        <?php else: ?>
                            <div class="flex flex-col items-center justify-center py-20 text-center">
                                <div class="size-20 rounded-3xl bg-white/5 flex items-center justify-center border border-white/10 mb-6"><span class="material-symbols-outlined text-4xl text-gray-600">business_center</span></div>
                                <h3 class="text-lg font-black uppercase italic tracking-tighter text-white">No Gym Connected</h3>
                                <p class="text-[10px] text-gray-500 font-bold uppercase tracking-widest mt-2">Finish your application to enable business info.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Custom Modal -->
    <div id="custom-modal" class="hidden">
        <div class="absolute inset-0 bg-[#0a090d]/80 transition-opacity" id="modal-backdrop" onclick="closeModal()"></div>
        <div class="relative z-10 bg-[--background] w-full max-w-sm rounded-[32px] border border-white/10 overflow-hidden transform transition-all duration-300 scale-90 opacity-0 px-4 py-8 text-center" id="modal-content">
            <div class="w-20 h-20 rounded-[24px] bg-white/5 flex items-center justify-center mx-auto mb-6 border border-white/10" id="modal-icon-bg"><span class="material-symbols-outlined text-4xl text-primary" id="modal-icon">info</span></div>
            <h3 class="text-xl font-black italic text-white uppercase tracking-tighter mb-3" id="modal-title">Confirm Update</h3>
            <p class="text-gray-400 text-[11px] font-bold tracking-wider mb-8" id="modal-message"></p>
            <div class="flex gap-3 justify-center" id="modal-actions"></div>
        </div>
    </div>

    <script>
        function switchTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.getElementById('tab-' + tabId).classList.add('active');
            document.getElementById('btn-' + tabId).classList.add('active');

            // Sidebar Swapping Logic
            const sidebarUser = document.getElementById('sidebar-user-content');
            const sidebarGym = document.getElementById('sidebar-gym-content');
            const editBtn = document.getElementById('edit-btn');

            if (tabId === 'business') {
                sidebarUser.classList.add('hidden');
                sidebarGym.classList.remove('hidden');
                editBtn.classList.add('hidden');
            } else {
                sidebarUser.classList.remove('hidden');
                sidebarGym.classList.add('hidden');
                // Only show edit btn if not currently in edit mode
                if (!document.querySelector('.tab-content.edit-mode')) {
                    editBtn.classList.remove('hidden');
                }
            }
        }

        function toggleEdit() {
            const activeTab = document.querySelector('.tab-content.active');
            if (activeTab.id === 'tab-business') {
                // Since Business Info is read-only, we don't do anything here
                // or we could show a message. For now, following "individual worlds" logic
                return; 
            }
            
            activeTab.classList.add('edit-mode');
            document.body.classList.add('has-editing-tab');
            
            // Only enable inputs within the active tab
            activeTab.querySelectorAll('input, select').forEach(i => i.disabled = false);
            
            // Special handling for the save section if it's inside the active tab's form
            const saveSection = activeTab.querySelector('#save-section');
            if (saveSection) {
                saveSection.classList.remove('hidden');
                saveSection.querySelectorAll('input').forEach(i => i.disabled = false);
            }

            document.getElementById('edit-btn').classList.add('hidden');
            document.getElementById('discard-btn').classList.remove('hidden');
            
            const editIndicator = activeTab.querySelector('[id$="-edit-indicator"]');
            if (editIndicator) editIndicator.classList.remove('hidden');
            
            document.getElementById('profile-label').classList.remove('hidden');
            if (document.getElementById('profilePreviewImg')) document.getElementById('remove-photo-btn').classList.remove('hidden');
        }

        function cancelEdit() { window.location.reload(); }

        function showModal(title, message, type, callback = null) {
            const modal = document.getElementById('custom-modal');
            const backdrop = document.getElementById('modal-backdrop');
            const content = document.getElementById('modal-content');
            document.getElementById('modal-title').innerText = title;
            document.getElementById('modal-message').innerText = message;
            const actionsDiv = document.getElementById('modal-actions');
            actionsDiv.innerHTML = '';

            if (type === 'confirm') {
                const cB = document.createElement('button'); cB.className = "px-6 py-3.5 rounded-2xl bg-white/5 text-gray-300 text-[10px] font-black italic uppercase tracking-widest";
                cB.innerText = "Cancel"; cB.onclick = closeModal;
                const fB = document.createElement('button'); fB.className = "px-8 py-3.5 rounded-2xl bg-primary text-white text-[10px] font-black italic uppercase tracking-widest flex items-center gap-2";
                fB.innerHTML = '<span class="material-symbols-outlined text-base">check</span> Confirm';
                fB.onclick = () => { if (callback) callback(); closeModal(); };
                actionsDiv.append(cB, fB);
            } else {
                const oB = document.createElement('button'); oB.className = "w-full py-4 rounded-2xl bg-white/10 text-white text-[10px] font-black italic uppercase tracking-widest";
                oB.innerText = "Got it"; oB.onclick = closeModal;
                actionsDiv.appendChild(oB);
            }

            modal.classList.add('flex'); modal.classList.remove('hidden');
            setTimeout(() => { backdrop.classList.remove('opacity-0'); content.classList.remove('scale-90', 'opacity-0'); content.classList.add('scale-100', 'opacity-100'); }, 10);
        }

        function closeModal() {
            const modal = document.getElementById('custom-modal');
            const content = document.getElementById('modal-content');
            content.classList.add('scale-90', 'opacity-0');
            setTimeout(() => { modal.classList.remove('flex'); modal.classList.add('hidden'); }, 300);
        }

        function previewProfileImage(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    let img = document.getElementById('profilePreviewImg');
                    if (!img) {
                        img = document.createElement('img'); img.id = 'profilePreviewImg';
                        img.className = 'size-full aspect-square object-cover object-center';
                        document.getElementById('profile-container').insertBefore(img, document.getElementById('profile-container').firstChild);
                        if (document.getElementById('profilePlaceholder')) document.getElementById('profilePlaceholder').classList.add('hidden');
                    }
                    img.src = e.target.result;
                    document.getElementById('remove-photo-btn').classList.remove('hidden');
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        function removeProfilePhoto() {
            showModal("Remove Photo", "Revert to default placeholder?", "confirm", () => {
                const img = document.getElementById('profilePreviewImg');
                const placeholder = document.getElementById('profilePlaceholder');
                if (img) img.classList.add('hidden');
                if (placeholder) placeholder.classList.remove('hidden');
                document.getElementById('remove-photo-btn').classList.add('hidden');
                document.getElementById('remove-profile-input').value = "1";
            });
        }

        function checkStrength(p) {
            const bar = document.getElementById('strength-bar');
            const text = document.getElementById('strength-text');
            let s = 0;
            if (p.length >= 8) s += 25; if (/[A-Z]/.test(p)) s += 25; if (/[0-9]/.test(p)) s += 25; if (/[^A-Za-z0-9]/.test(p)) s += 25;
            bar.style.width = s + '%';
            if (s <= 25) { bar.className = 'h-full bg-rose-500'; text.innerText = 'WEAK'; text.className = 'text-[9px] font-black text-rose-500'; }
            else if (s <= 75) { bar.className = 'h-full bg-amber-500'; text.innerText = 'GOOD'; text.className = 'text-[9px] font-black text-amber-500'; }
            else { bar.className = 'h-full bg-emerald-400'; text.innerText = 'STRONG'; text.className = 'text-[9px] font-black text-emerald-400'; }
        }

        function formatContactNumber(i) {
            let v = i.value.replace(/\D/g, ''); if (v.length > 11) v = v.substring(0, 11);
            let f = ''; if (v.length > 0) { f += v.substring(0, 4); if (v.length > 4) f += '-' + v.substring(4, 7); if (v.length > 7) f += '-' + v.substring(7, 11); }
            i.value = f;
        }

        function togglePassword(id, i) {
            const e = document.getElementById(id); const ix = document.getElementById(i);
            e.type = e.type === "password" ? "text" : "password";
            ix.innerText = e.type === "password" ? "visibility_off" : "visibility";
        }

        function validateAndSubmit(e) {
            e.preventDefault();
            const cp = document.getElementById('current_pass');
            if (cp.value.trim() === "") { cp.focus(); return false; }
            showModal("Confirm Update", "Are you sure you want to save these profile changes?", "confirm", () => { document.getElementById('profile-form').submit(); });
            return false;
        }

        setInterval(() => { const c = document.getElementById('headerClock'); if (c) c.innerText = new Date().toLocaleTimeString('en-US'); }, 1000);
    </script>
</body>

</html>
