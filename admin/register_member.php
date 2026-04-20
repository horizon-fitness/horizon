<?php
session_start();
require_once '../db.php';
require_once '../includes/mailer.php';

// Security Check: Only Staff
$role = strtolower($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || ($role !== 'staff' && $role !== 'coach')) {
    header("Location: ../login.php");
    exit;
}

$adminName = $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];

require_once '../includes/member_processor.php';

$gym_id = $_SESSION['gym_id'];
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $registration_data = array_merge($_POST, [
            'gym_id' => $gym_id,
            'registration_source' => 'Walk-in',
            'registered_by_user_id' => $_SESSION['user_id']
        ]);

        $result = processMemberRegistration($pdo, $registration_data);
        $success = "Member registered successfully! Credentials have been sent to their email.";
        $_POST = [];
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// ── 4-Color Elite Branding System Implementation ─────────────────────────────
if (!function_exists('hexToRgb')) {
    function hexToRgb($hex)
    {
        if (!$hex)
            return "0, 0, 0";
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
$stmtGymBranding = $pdo->prepare("SELECT owner_user_id, gym_name, profile_picture FROM gyms WHERE gym_id = ?");
$stmtGymBranding->execute([$gym_id]);
$gym_data = $stmtGymBranding->fetch();
$owner_user_id = $gym_data['owner_user_id'] ?? 0;
$gym_name = $gym_data['gym_name'] ?? 'Horizon Gym';

$configs = [
    'system_name' => $gym_name,
    'system_logo' => '',
    'theme_color' => '#8c2bee',
    'secondary_color' => '#a1a1aa',
    'text_color' => '#d1d5db',
    'bg_color' => '#0a090d',
    'card_color' => '#141216',
    'auto_card_theme' => '1',
    'font_family' => 'Lexend',
];

// 1. Merge global settings (user_id = 0)
$stmtGlobal = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = 0");
$stmtGlobal->execute();
foreach (($stmtGlobal->fetchAll(PDO::FETCH_KEY_PAIR) ?: []) as $k => $v) {
    if ($v !== null && $v !== '')
        $configs[$k] = $v;
}

// 2. Merge tenant-specific settings (user_id = owner_user_id)
$stmtTenant = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = ?");
$stmtTenant->execute([$owner_user_id]);
foreach (($stmtTenant->fetchAll(PDO::FETCH_KEY_PAIR) ?: []) as $k => $v) {
    if ($v !== null && $v !== '')
        $configs[$k] = $v;
}

// 3. Resolved branding tokens
$theme_color = $configs['theme_color'];
$highlight_color = $configs['secondary_color'];
$text_color = $configs['text_color'];
$bg_color = $configs['bg_color'];
$font_family = $configs['font_family'] ?? 'Lexend';
$auto_card_theme = $configs['auto_card_theme'] ?? '1';
$card_color = $configs['card_color'];

$primary_rgb = hexToRgb($theme_color);
$highlight_rgb = hexToRgb($highlight_color);
$card_bg_css = ($auto_card_theme === '1') ? "rgba({$primary_rgb}, 0.05)" : $card_color;

$page = [
    'logo_path' => $configs['system_logo'] ?? $gym_data['profile_picture'] ?? '',
    'theme_color' => $theme_color,
    'bg_color' => $bg_color,
    'system_name' => $configs['system_name'] ?? $gym_name,
];

$active_page = "register";
?>
<!DOCTYPE html>
<html class="dark" lang="en">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>Walk-in Registration | Horizon Partners</title>
    <!-- Fonts & Icons -->
    <link
        href="https://fonts.googleapis.com/css2?family=<?= urlencode($font_family) ?>:wght@300;400;500;600;700;800;900&display=swap"
        rel="stylesheet" />
    <link
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200"
        rel="stylesheet" />

    <!-- TailWind CSS Configuration -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "var(--primary)",
                        "background": "var(--background)",
                        "card-bg": "var(--card-bg)",
                        "text-main": "var(--text-main)",
                        "highlight": "var(--highlight)"
                    }
                }
            }
        } 
    </script>
    <style>
        :root {
            --primary:
                <?= $theme_color ?>
            ;
            --primary-rgb:
                <?= $primary_rgb ?>
            ;
            --highlight:
                <?= $highlight_color ?>
            ;
            --highlight-rgb:
                <?= $highlight_rgb ?>
            ;
            --text-main:
                <?= $text_color ?>
            ;
            --background:
                <?= $bg_color ?>
            ;
            --card-bg:
                <?= $card_bg_css ?>
            ;
            --card-blur: 20px;
        }

        body {
            font-family: '<?= $font_family ?>', sans-serif;
            background-color: var(--background);
            color: var(--text-main);
            display: flex;
            flex-direction: row;
            min-h-screen: 100vh;
            overflow: hidden;
        }

        .glass-card {
            background: var(--card-bg);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 24px;
            backdrop-filter: blur(var(--card-blur));
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        .input-field {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            color: white;
            padding: 14px 18px;
            width: 100%;
            outline: none;
            transition: all 0.2s;
            font-size: 13px;
            font-weight: 500;
            color-scheme: dark;
        }

        .input-field:focus {
            border-color: var(--primary);
            background: rgba(255, 255, 255, 0.08);
        }

        .input-field option {
            background-color: #1a1821;
            color: white;
        }

        ::-webkit-calendar-picker-indicator {
            filter: invert(1) brightness(0.8);
            opacity: 0.6;
            cursor: pointer;
        }

        .strength-bar {
            height: 4px;
            border-radius: 2px;
            transition: all 0.3s ease;
            width: 0;
        }

        .strength-weak {
            background-color: #ef4444;
            width: 33.33%;
        }

        .strength-medium {
            background-color: #f59e0b;
            width: 66.66%;
        }

        .strength-strong {
            background-color: #10b981;
            width: 100%;
        }

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
            background-color: var(--background);
            border-right: 1px solid rgba(255, 255, 255, 0.05);
        }

        .side-nav:hover {
            width: 300px;
        }

        .main-content {
            margin-left: 110px;
            flex: 1;
            min-width: 0;
            transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .side-nav:hover~.main-content {
            margin-left: 300px;
        }

        .nav-label {
            opacity: 0;
            transform: translateX(-15px);
            transition: all 0.3s ease-in-out;
            white-space: nowrap;
            pointer-events: none;
            color: var(--text-main);
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
            margin-bottom: 8px !important;
            pointer-events: auto;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 10px 38px;
            transition: opacity 0.2s ease, color 0.2s ease;
            text-decoration: none;
            white-space: nowrap;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: color-mix(in srgb, var(--text-main) 45%, transparent);
            position: relative;
        }

        .nav-item:hover {
            color: var(--text-main);
        }

        .nav-item .material-symbols-rounded {
            color: var(--highlight);
            transition: transform 0.2s ease;
        }

        .nav-item:hover .material-symbols-rounded {
            transform: scale(1.12);
        }

        .nav-item.active {
            color: var(--primary) !important;
            position: relative;
        }

        .nav-item.active .material-symbols-rounded {
            color: var(--primary);
        }

        .nav-item.active::after {
            content: '';
            position: absolute;
            right: 0px;
            top: 50%;
            transform: translateY(-50%);
            width: 4px;
            height: 24px;
            background: var(--primary);
            border-radius: 4px 0 0 4px;
        }

        .no-scrollbar::-webkit-scrollbar {
            display: none;
        }

        .no-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
    </style>
    </style>
    <script>
        function updateHeaderClock() {
            const now = new Date();
            const clockEl = document.getElementById('topClock');
            if (clockEl) {
                clockEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            }
        }
        setInterval(updateHeaderClock, 1000);
        window.addEventListener('DOMContentLoaded', updateHeaderClock);

        function formatPhoneNumber(input) {
            let value = input.value.replace(/\D/g, '');

            if (value.length > 11) value = value.slice(0, 11);

            let formatted = '';
            if (value.length > 0) {
                formatted = value.substring(0, 4);
                if (value.length > 4) formatted += '-' + value.substring(4, 7);
                if (value.length > 7) formatted += '-' + value.substring(7, 11);
            }
            input.value = formatted;
        }

        function validateForm(event) {
            return true;
        }
    </script>
</head>

<body class="antialiased flex h-screen overflow-hidden">

    <!-- Dynamic Admin Sidebar -->
    <?php include '../includes/admin_sidebar.php'; ?>

    <div class="main-content flex-1 overflow-y-auto no-scrollbar">
        <main class="p-10 max-w-[1400px] mx-auto">
            <header class="mb-12 flex flex-row justify-between items-end gap-6">
                <div>
                    <h2
                        class="text-3xl font-black italic uppercase tracking-tighter text-white leading-none tracking-tight">
                        Walk-in <span class="text-primary">Registration</span></h2>
                    <p
                        class="text-[--text-main]/60 text-[10px] font-bold uppercase tracking-widest mt-2 px-1 opacity-60">
                        Staff Portal • New Member Entry</p>
                </div>
                <div class="flex items-end gap-8 text-right shrink-0">
                    <div class="flex flex-col items-end">
                        <p id="topClock"
                            class="text-[--text-main] font-black italic text-2xl leading-none tracking-tighter uppercase">
                            00:00:00 AM</p>
                        <p class="text-primary text-[10px] font-black uppercase tracking-[0.2em] leading-none mt-2">
                            <?= date('l, M d, Y') ?></p>
                    </div>
                </div>
            </header>

            <div class="max-w-4xl mx-auto">
                <?php if ($success): ?>
                    <div
                        class="mb-8 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-500 text-xs font-bold flex items-center gap-3 shadow-lg">
                        <span class="material-symbols-rounded text-lg">check_circle</span> <?= $success ?>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div
                        class="mb-8 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-xs font-bold flex items-center gap-3 shadow-lg">
                        <span class="material-symbols-rounded text-lg">error</span> <?= $error ?>
                    </div>
                <?php endif; ?>

                <form method="POST" onsubmit="return validateForm(event)" class="space-y-8 pb-20">
                    <div class="glass-card p-8 border-l-4 border-l-primary">
                        <h4
                            class="text-base font-black italic uppercase tracking-tighter mb-3 flex items-center gap-3 text-primary">
                            <span class="material-symbols-rounded bg-primary/10 p-2 rounded-xl text-xl">key</span>
                            Credentials
                        </h4>
                        <p class="text-[12px] font-medium text-[--text-main]/70 leading-relaxed max-w-2xl">
                            For security, the account <span class="text-white font-bold italic">username</span> and
                            <span class="text-white font-bold italic">password</span> will be automatically generated
                            and securely delivered to the recipient's email address upon confirmation.
                        </p>
                    </div>

                    <div class="glass-card p-8">
                        <h4
                            class="text-base font-black italic uppercase tracking-tighter mb-8 flex items-center gap-3 text-primary">
                            <span class="material-symbols-rounded bg-primary/10 p-2 rounded-xl text-xl">person</span>
                            Personal Information
                        </h4>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-x-8 gap-y-6">
                            <div class="space-y-2">
                                <label
                                    class="text-[11px] font-black uppercase text-[--text-main]/60 tracking-widest ml-1">First
                                    Name <span class="text-red-500">*</span></label>
                                <input type="text" name="first_name" required
                                    value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>" class="input-field"
                                    placeholder="First Name">
                            </div>
                            <div class="space-y-2">
                                <label
                                    class="text-[11px] font-black uppercase text-[--text-main]/60 tracking-widest ml-1">Middle
                                    Name</label>
                                <input type="text" name="middle_name"
                                    value="<?= htmlspecialchars($_POST['middle_name'] ?? '') ?>" class="input-field"
                                    placeholder="Middle Name">
                            </div>
                            <div class="space-y-2">
                                <label
                                    class="text-[11px] font-black uppercase text-[--text-main]/60 tracking-widest ml-1">Last
                                    Name <span class="text-red-500">*</span></label>
                                <input type="text" name="last_name" required
                                    value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>" class="input-field"
                                    placeholder="Last Name">
                            </div>
                            <div class="space-y-2 md:col-span-1">
                                <label
                                    class="text-[11px] font-black uppercase text-[--text-main]/60 tracking-widest ml-1">Sex
                                    <span class="text-red-500">*</span></label>
                                <select name="sex" required class="input-field appearance-none cursor-pointer">
                                    <option value="" disabled <?= !isset($_POST['sex']) ? 'selected' : '' ?>>Select Sex
                                    </option>
                                    <option value="Male" <?= ($_POST['sex'] ?? '') === 'Male' ? 'selected' : '' ?>>Male
                                    </option>
                                    <option value="Female" <?= ($_POST['sex'] ?? '') === 'Female' ? 'selected' : '' ?>>
                                        Female</option>
                                </select>
                            </div>
                            <div class="space-y-2 md:col-span-2">
                                <label
                                    class="text-[11px] font-black uppercase text-[--text-main]/60 tracking-widest ml-1">Birth
                                    Date <span class="text-red-500">*</span></label>
                                <input type="date" name="birth_date" required
                                    value="<?= htmlspecialchars($_POST['birth_date'] ?? '') ?>" class="input-field">
                            </div>
                        </div>
                    </div>

                    <div class="glass-card p-8">
                        <h4
                            class="text-base font-black italic uppercase tracking-tighter mb-8 flex items-center gap-3 text-primary">
                            <span
                                class="material-symbols-rounded bg-primary/10 p-2 rounded-xl text-xl">alternate_email</span>
                            Contact Information
                        </h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                            <div class="space-y-2">
                                <label
                                    class="text-[11px] font-black uppercase text-[--text-main]/60 tracking-widest ml-1">Email
                                    Address <span class="text-red-500">*</span></label>
                                <input type="email" name="email" required
                                    value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" class="input-field"
                                    placeholder="email@address.com">
                            </div>
                            <div class="space-y-2">
                                <label
                                    class="text-[11px] font-black uppercase text-[--text-main]/60 tracking-widest ml-1">Contact
                                    Number <span class="text-red-500">*</span></label>
                                <input type="tel" name="phone" required oninput="formatPhoneNumber(this)"
                                    value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" class="input-field"
                                    placeholder="09XX-XXX-XXXX">
                            </div>
                            <div class="space-y-2 md:col-span-2">
                                <label
                                    class="text-[11px] font-black uppercase text-[--text-main]/60 tracking-widest ml-1">Home
                                    Address <span class="text-red-500">*</span></label>
                                <input type="text" name="address" required
                                    value="<?= htmlspecialchars($_POST['address'] ?? '') ?>" class="input-field"
                                    placeholder="Street, Barangay, City">
                            </div>
                        </div>
                    </div>

                    <div class="glass-card p-8">
                        <h4
                            class="text-base font-black italic uppercase tracking-tighter mb-8 flex items-center gap-3 text-primary">
                            <span
                                class="material-symbols-rounded bg-primary/10 p-2 rounded-xl text-xl">medical_information</span>
                            Health & Profile
                        </h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                            <div class="space-y-2 md:col-span-2">
                                <label
                                    class="text-[11px] font-black uppercase text-[--text-main]/60 tracking-widest ml-1">Occupation</label>
                                <input type="text" name="occupation"
                                    value="<?= htmlspecialchars($_POST['occupation'] ?? '') ?>" class="input-field"
                                    placeholder="e.g. Software Engineer">
                            </div>
                            <div class="space-y-2">
                                <label
                                    class="text-[11px] font-black uppercase text-[--text-main]/60 tracking-widest ml-1">Emergency
                                    Name <span class="text-red-500">*</span></label>
                                <input type="text" name="emergency_name" required
                                    value="<?= htmlspecialchars($_POST['emergency_name'] ?? '') ?>" class="input-field"
                                    placeholder="Full Name">
                            </div>
                            <div class="space-y-2">
                                <label
                                    class="text-[11px] font-black uppercase text-[--text-main]/60 tracking-widest ml-1">Emergency
                                    Contact <span class="text-red-500">*</span></label>
                                <input type="tel" name="emergency_phone" required oninput="formatPhoneNumber(this)"
                                    value="<?= htmlspecialchars($_POST['emergency_phone'] ?? '') ?>" class="input-field"
                                    placeholder="09XX-XXX-XXXX">
                            </div>
                            <div class="space-y-2 md:col-span-2">
                                <label
                                    class="text-[11px] font-black uppercase text-[--text-main]/60 tracking-widest ml-1">Medical
                                    History</label>
                                <textarea name="medical_history" class="input-field min-h-[100px] py-4"
                                    placeholder="List any existing conditions or allergies..."><?= htmlspecialchars($_POST['medical_history'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end pt-4">
                        <button type="submit"
                            class="group px-10 h-16 rounded-2xl bg-primary text-white text-xs font-black uppercase tracking-[0.2em] shadow-lg shadow-primary/20 hover:shadow-primary/40 hover:-translate-y-1 transition-all flex items-center gap-4">Register
                            Member <span
                                class="material-symbols-rounded group-hover:translate-x-1 transition-transform text-xl">arrow_forward</span></button>
                    </div>
                </form>
            </div>
        </main>
    </div>
</body>

</html>