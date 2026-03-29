<?php
session_start();
require_once '../db.php';

// Security Check: Only Tenants/Admins/Staff/Coaches
$role = strtolower($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || ($role !== 'tenant' && $role !== 'admin' && $role !== 'staff' && $role !== 'coach')) {
    header("Location: ../login.php");
    exit;
}

$gym_id = $_SESSION['gym_id'];
$user_id = $_SESSION['user_id'];
$active_page = "settings";

// --- Database Refresh (Ensure secondary_color exists) ---
try {
    $pdo->exec("ALTER TABLE tenant_pages ADD COLUMN secondary_color VARCHAR(100) DEFAULT '#a1a1aa' AFTER theme_color");
} catch (Exception $e) { /* Column already exists */
}

// Fetch Gym Details
$stmtGym = $pdo->prepare("SELECT * FROM gyms WHERE gym_id = ?");
$stmtGym->execute([$gym_id]);
$gym = $stmtGym->fetch();

if (!$gym) {
    die("Gym profile not found. Please contact support.");
}

// Fetch Branding Data
$stmtPage = $pdo->prepare("SELECT * FROM tenant_pages WHERE gym_id = ? LIMIT 1");
$stmtPage->execute([$gym_id]);
$page = $stmtPage->fetch();

// Fetch Active Subscription for Sidebar/Header
$stmtSub = $pdo->prepare("
        SELECT ws.plan_name 
        FROM client_subscriptions cs 
        JOIN website_plans ws ON cs.website_plan_id = ws.website_plan_id 
        WHERE cs.gym_id = ? AND cs.subscription_status = 'Active' 
        LIMIT 1
    ");
$stmtSub->execute([$gym_id]);
$sub = $stmtSub->fetch();
$plan_name = $sub['plan_name'] ?? 'Standard Plan';

$success = null;
$error = null;

// --- UNIFIED POST HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Branding Data
    $page_title = $_POST['page_title'] ?? $gym['gym_name'];
    $theme_color = $_POST['theme_color'] ?? '#8c2bee';
    $secondary_color = $_POST['secondary_color'] ?? '#a1a1aa';
    $bg_color = $_POST['bg_color'] ?? '#0a090d';
    $font_family = $_POST['font_family'] ?? 'Lexend';
    $app_download_link = $_POST['app_download_link'] ?? '';

    // Facility Data
    $opening_time = $_POST['opening_time'] ?? '08:00:00';
    $closing_time = $_POST['closing_time'] ?? '22:00:00';
    $max_capacity = (int) ($_POST['max_capacity'] ?? 50);
    $has_lockers = isset($_POST['has_lockers']) ? 1 : 0;
    $has_shower = isset($_POST['has_shower']) ? 1 : 0;
    $has_parking = isset($_POST['has_parking']) ? 1 : 0;
    $has_wifi = isset($_POST['has_wifi']) ? 1 : 0;
    $gym_description = $_POST['gym_description'] ?? '';
    $rules_text = $_POST['rules_text'] ?? '';

    $now = date('Y-m-d H:i:s');

    try {
        $pdo->beginTransaction();

        // 1. Update/Create Branding (tenant_pages)
        $logo_path = $page['logo_path'] ?? '';
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] == 0) {
            $type = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
            $data = file_get_contents($_FILES['logo']['tmp_name']);
            $logo_path = 'data:image/' . $type . ';base64,' . base64_encode($data);
        }

        if ($page) {
            $stmtUpdatePage = $pdo->prepare("UPDATE tenant_pages SET page_title = ?, logo_path = ?, theme_color = ?, secondary_color = ?, bg_color = ?, font_family = ?, app_download_link = ?, updated_at = ? WHERE gym_id = ?");
            $stmtUpdatePage->execute([$page_title, $logo_path, $theme_color, $secondary_color, $bg_color, $font_family, $app_download_link, $now, $gym_id]);
        } else {
            $page_slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $gym['gym_name']));
            $stmtInsertPage = $pdo->prepare("INSERT INTO tenant_pages (gym_id, page_slug, page_title, logo_path, theme_color, secondary_color, bg_color, font_family, app_download_link, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtInsertPage->execute([$gym_id, $page_slug, $page_title, $logo_path, $theme_color, $secondary_color, $bg_color, $font_family, $app_download_link, $now]);
        }

        // 2. Update Facility (gyms)
        $stmtUpdateGym = $pdo->prepare("UPDATE gyms SET description = ?, opening_time = ?, closing_time = ?, max_capacity = ?, has_lockers = ?, has_shower = ?, has_parking = ?, has_wifi = ?, rules_text = ?, updated_at = ? WHERE gym_id = ?");
        $stmtUpdateGym->execute([$gym_description, $opening_time, $closing_time, $max_capacity, $has_lockers, $has_shower, $has_parking, $has_wifi, $rules_text, $now, $gym_id]);

        $pdo->commit();
        $success = "All configurations saved and synchronized successfully!";

        // Refresh local data
        $stmtGym->execute([$gym_id]);
        $gym = $stmtGym->fetch();
        $stmtPage->execute([$gym_id]);
        $page = $stmtPage->fetch();
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Update Error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html class="dark" lang="en">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>Gym Settings | Horizon</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap"
        rel="stylesheet" />
    <link
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200"
        rel="stylesheet" />
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "#8c2bee", "background-dark": "#0a090d", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)" } } }
        }
    </script>
    <style>
        body {
            font-family: 'Lexend', sans-serif;
            background-color: #0a090d;
            color: white;
            overflow: hidden;
        }

        .glass-card {
            background: #14121a;
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 24px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
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
            z-index: 150;
            background-color: #0d0c12;
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
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
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
            transform: translateX(-15px);
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            margin: 0 !important;
            pointer-events: none;
        }

        .side-nav:hover .nav-section-label {
            max-height: 20px;
            opacity: 1;
            transform: translateX(0);
            margin-bottom: 8px !important;
            pointer-events: auto;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 10px 38px;
            transition: all 0.2s ease;
            text-decoration: none;
            white-space: nowrap;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #94a3b8;
        }

        .nav-item:hover {
            background: rgba(255, 255, 255, 0.05);
            color: white;
        }

        .nav-item.active {
            color: #8c2bee !important;
            position: relative;
        }

        .nav-item.active::after {
            content: '';
            position: absolute;
            right: 0px;
            top: 50%;
            transform: translateY(-50%);
            width: 4px;
            height: 24px;
            background: #8c2bee;
            border-radius: 4px 0 0 4px;
        }

        .input-dark {
            background: #0a090d;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            color: white;
            padding: 14px 18px;
            font-size: 14px;
            width: 100%;
            outline: none;
            transition: all 0.2s;
        }

        .input-dark:focus {
            border-color: #8c2bee;
            box-shadow: 0 0 0 4px rgba(140, 43, 238, 0.1);
        }

        .no-scrollbar::-webkit-scrollbar {
            display: none;
        }

        #portalFrame {
            width: 1600px;
            height: 1000px;
            border: none;
            position: absolute;
            top: 0;
            left: 0;
            transform-origin: top left;
        }
    </style>
</head>

<body class="flex h-screen overflow-hidden">

    <nav class="side-nav bg-background-dark border-r border-white/5 z-50">
        <div class="px-7 py-8 mb-4 shrink-0">
            <div class="flex items-center gap-4">
                <div
                    class="size-10 rounded-xl shrink-0 overflow-hidden flex items-center justify-center <?= empty($page['logo_path']) ? 'bg-primary shadow-lg shadow-primary/20' : '' ?>">
                    <?php if (!empty($page['logo_path'])): ?>
                        <img src="<?= htmlspecialchars($page['logo_path']) ?>" class="size-full object-cover">
                    <?php else: ?>
                        <span class="material-symbols-outlined text-white text-2xl">bolt</span>
                    <?php endif; ?>
                </div>
                <h1 class="nav-label text-lg font-black italic uppercase tracking-tighter text-white">Owner Portal</h1>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto no-scrollbar space-y-1">
            <div class="nav-section-label px-[38px] mb-2"><span
                    class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Main Menu</span></div>
            <a href="tenant_dashboard.php" class="nav-item">
                <span class="material-symbols-outlined text-xl shrink-0">grid_view</span>
                <span class="nav-label">Dashboard</span>
            </a>

            <a href="my_users.php" class="nav-item">
                <span class="material-symbols-outlined text-xl shrink-0">group</span>
                <span class="nav-label">Users</span>
            </a>

            <a href="transactions.php" class="nav-item">
                <span class="material-symbols-outlined text-xl shrink-0">receipt_long</span>
                <span class="nav-label">Transactions</span>
            </a>

            <a href="attendance.php" class="nav-item">
                <span class="material-symbols-outlined text-xl shrink-0">history</span>
                <span class="nav-label">Attendance</span>
            </a>

            <div class="nav-section-label px-[38px] mb-2 mt-6"><span
                    class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Management</span></div>

            <a href="staff.php" class="nav-item">
                <span class="material-symbols-outlined text-xl shrink-0">badge</span>
                <span class="nav-label">Staff</span>
            </a>

            <a href="reports.php" class="nav-item">
                <span class="material-symbols-outlined text-xl shrink-0">analytics</span>
                <span class="nav-label">Reports</span>
            </a>

            <a href="sales_report.php" class="nav-item">
                <span class="material-symbols-outlined text-xl shrink-0">payments</span>
                <span class="nav-label">Sales Reports</span>
            </a>
        </div>

        <div class="mt-auto pt-4 border-t border-white/10 shrink-0 pb-6">
            <div class="nav-section-label px-[38px] mb-2"><span
                    class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Account</span></div>
            <a href="tenant_settings.php" class="nav-item active">
                <span class="material-symbols-outlined text-xl shrink-0">settings</span>
                <span class="nav-label">Settings</span>
            </a>
            <a href="profile.php" class="nav-item">
                <span class="material-symbols-outlined text-xl shrink-0">account_circle</span>
                <span class="nav-label">Profile</span>
            </a>
            <a href="../logout.php" class="nav-item text-gray-400 hover:text-rose-500 transition-colors">
                <span class="material-symbols-outlined text-xl shrink-0">logout</span>
                <span class="nav-label">Sign Out</span>
            </a>
        </div>
    </nav>

    <main class="main-content flex-1 p-10 overflow-y-auto no-scrollbar">
        <!-- Header -->
        <header class="flex flex-col md:flex-row md:items-center justify-between gap-6 mb-12 px-2">
            <div>
                <div class="flex items-center gap-2 mb-2">
                    <span class="text-[10px] font-black uppercase tracking-[0.4em] text-primary">System Command</span>
                    <span class="size-1 rounded-full bg-gray-700"></span>
                    <span class="text-[10px] font-black uppercase tracking-[0.4em] text-gray-500">Facility Center</span>
                </div>
                <h1 class="text-4xl font-black italic uppercase tracking-tighter text-white leading-none">Tenant <span
                        class="text-primary">Settings</span></h1>
            </div>

            <div class="glass-card px-8 py-5 flex items-center gap-8 shadow-2xl">
                <div class="text-right">
                    <p id="topClock" class="text-2xl font-black tracking-widest text-white leading-none mb-2"></p>
                    <p id="topDate" class="text-[11px] font-bold uppercase tracking-widest text-primary opacity-80"></p>
                </div>
                <div class="h-10 w-px bg-white/10"></div>
                <div class="flex flex-col items-end">
                    <span class="text-[10px] font-black uppercase tracking-widest text-gray-500 mb-1">Status</span>
                    <div class="flex items-center gap-2">
                        <span
                            class="size-2 rounded-full bg-green-500 shadow-lg shadow-green-500/50 animate-pulse"></span>
                        <span class="text-[11px] font-bold uppercase text-white tracking-widest">Active</span>
                    </div>
                </div>
            </div>
        </header>

        <?php if ($success): ?>
            <div
                class="mb-10 p-5 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-[11px] font-black uppercase italic tracking-widest flex items-center gap-3 animate-in fade-in slide-in-from-top-4 duration-500">
                <span class="material-symbols-outlined text-base">check_circle</span> <?= $success ?>
            </div>
        <?php endif; ?>

        <form id="unifiedSettingsForm" method="POST" enctype="multipart/form-data"
            class="space-y-12 pb-20 max-w-[1700px] mx-auto">

            <!-- TOP: LIVE PREVIEW TERMINAL -->
            <div class="space-y-6">
                <div class="flex items-center justify-between px-4">
                    <div class="flex items-center gap-3">
                        <div class="size-8 rounded-lg bg-primary/20 flex items-center justify-center">
                            <span class="material-symbols-outlined text-primary text-lg font-bold">visibility</span>
                        </div>
                        <h4 class="text-[14px] font-black italic uppercase tracking-widest text-white">Live Gym Portal
                            Preview</h4>
                    </div>
                    <div class="flex items-center gap-2 px-3 py-1.5 rounded-full bg-white/5 border border-white/5">
                        <span class="size-1.5 rounded-full bg-green-500 animate-pulse"></span>
                        <span class="text-[9px] font-black uppercase tracking-widest text-gray-400 italic">Desktop
                            Fidelity 1600px Active</span>
                    </div>
                </div>

                <div class="glass-card p-4 overflow-hidden shadow-2xl relative">
                    <div
                        class="absolute top-8 left-8 flex items-center gap-1.5 z-10 p-2 rounded-full bg-black/40 backdrop-blur-md border border-white/10">
                        <div class="size-2.5 rounded-full bg-red-400"></div>
                        <div class="size-2.5 rounded-full bg-amber-400"></div>
                        <div class="size-2.5 rounded-full bg-green-400"></div>
                    </div>

                    <div id="portalContainer"
                        class="w-full relative shadow-3xl border border-white/5 rounded-3xl overflow-hidden bg-black shadow-inner origin-top">
                        <!-- High-Fidelity Desktop Mockup -->
                        <iframe id="portalFrame" src="../portal.php?gym=<?= $page['page_slug'] ?? '' ?>&preview=1"
                            class="absolute top-0 left-0 w-[1600px] h-[1000px] border-none origin-top-left pointer-events-none"></iframe>
                    </div>

                    <div
                        class="absolute bottom-6 right-6 px-4 py-2 rounded-xl bg-black/60 backdrop-blur-md border border-white/10 text-[10px] font-bold uppercase tracking-widest text-gray-400">
                        Instant Synchronization Active
                    </div>
                </div>
            </div>

            <!-- BOTTOM GRID -->
            <div class="grid grid-cols-1 xl:grid-cols-2 gap-10 items-start">

                <!-- Branding Panel -->
                <div class="glass-card p-10">
                    <h4
                        class="text-[14px] font-black italic uppercase tracking-widest text-primary mb-12 flex items-center gap-4">
                        <span class="material-symbols-outlined text-2xl">brush</span> 1. Global Branding
                    </h4>
                    <div class="space-y-12">
                        <div class="space-y-4">
                            <label
                                class="text-[12px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Gym
                                Display Name</label>
                            <input type="text" name="page_title" oninput="updateMockup()"
                                value="<?= htmlspecialchars($page['page_title'] ?? $gym['gym_name']) ?>"
                                class="input-dark text-lg" placeholder="Name on Portal">
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-10">
                            <div class="space-y-4">
                                <label
                                    class="text-[12px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Primary
                                    Theme Color</label>
                                <div class="flex items-center gap-5 bg-black p-4 rounded-3xl border border-white/5">
                                    <input type="color" name="theme_color" oninput="updateMockup()"
                                        value="<?= htmlspecialchars($page['theme_color'] ?? '#8c2bee') ?>"
                                        class="size-12 rounded-xl cursor-pointer bg-transparent border-none">
                                    <span id="colorHex"
                                        class="text-[14px] font-black italic uppercase text-gray-400 tracking-widest"><?= $page['theme_color'] ?? '#8c2bee' ?></span>
                                </div>
                            </div>
                            <div class="space-y-4">
                                <label
                                    class="text-[12px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Secondary
                                    Accent Color</label>
                                <div class="flex items-center gap-5 bg-black p-4 rounded-3xl border border-white/5">
                                    <input type="color" name="secondary_color" oninput="updateMockup()"
                                        value="<?= htmlspecialchars($page['secondary_color'] ?? '#a1a1aa') ?>"
                                        class="size-12 rounded-xl cursor-pointer bg-transparent border-none">
                                    <span id="secondaryHex"
                                        class="text-[14px] font-black italic uppercase text-gray-400 tracking-widest"><?= $page['secondary_color'] ?? '#a1a1aa' ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-10">
                            <div class="space-y-4">
                                <label
                                    class="text-[12px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Page
                                    Background Color</label>
                                <div class="flex items-center gap-5 bg-black p-4 rounded-3xl border border-white/5">
                                    <input type="color" name="bg_color" oninput="updateMockup()"
                                        value="<?= htmlspecialchars($page['bg_color'] ?? '#0a090d') ?>"
                                        class="size-12 rounded-xl cursor-pointer bg-transparent border-none">
                                    <span id="bgHex"
                                        class="text-[14px] font-black italic uppercase text-gray-400 tracking-widest"><?= $page['bg_color'] ?? '#0a090d' ?></span>
                                </div>
                            </div>
                            <div class="space-y-4">
                                <label
                                    class="text-[12px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Typography
                                    Identity</label>
                                <select name="font_family" onchange="updateMockup()" class="input-dark">
                                    <option value="Lexend" <?= ($page['font_family'] ?? 'Lexend') == 'Lexend' ? 'selected' : '' ?>>Lexend (Default)</option>
                                    <option value="Inter" <?= ($page['font_family'] ?? '') == 'Inter' ? 'selected' : '' ?>>
                                        Inter</option>
                                    <option value="Outfit" <?= ($page['font_family'] ?? '') == 'Outfit' ? 'selected' : '' ?>>Outfit</option>
                                    <option value="Plus Jakarta Sans" <?= ($page['font_family'] ?? '') == 'Plus Jakarta Sans' ? 'selected' : '' ?>>Plus Jakarta Sans</option>
                                </select>
                            </div>
                        </div>

                        <div class="space-y-4">
                            <label
                                class="text-[12px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Portal
                                Description</label>
                            <textarea name="gym_description" rows="3" class="input-dark"
                                placeholder="About your facility..."><?= htmlspecialchars($gym['description'] ?? '') ?></textarea>
                        </div>

                        <div class="pt-12 border-t border-white/5">
                            <div class="space-y-4">
                                <label
                                    class="text-[12px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Brand
                                    Logo</label>
                                <div class="flex items-center gap-8">
                                    <div
                                        class="size-32 rounded-[2rem] bg-black border border-white/5 flex items-center justify-center overflow-hidden shrink-0 shadow-2xl">
                                        <img id="logoPreview" src="<?= $page['logo_path'] ?? '' ?>"
                                            class="size-full object-contain p-5 <?= empty($page['logo_path']) ? 'hidden' : '' ?>">
                                        <span id="logoPlaceholder"
                                            class="material-symbols-outlined text-gray-800 text-4xl <?= !empty($page['logo_path']) ? 'hidden' : '' ?>">photo_library</span>
                                    </div>
                                    <input type="file" name="logo"
                                        onchange="previewImg(this, 'logoPreview', 'logoPlaceholder')"
                                        class="text-[11px] text-gray-500 file:bg-primary/10 file:text-primary file:border-none file:px-6 file:py-3 file:rounded-xl file:font-black file:uppercase file:mr-6 file:cursor-pointer hover:file:bg-primary/20 transition-all">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Operations Panel -->
                <div class="glass-card p-10">
                    <h4
                        class="text-[14px] font-black italic uppercase tracking-widest text-primary mb-12 flex items-center gap-4">
                        <span class="material-symbols-outlined text-2xl">schedule</span> 2. Operational Rules
                    </h4>
                    <div class="space-y-10">
                        <div class="grid grid-cols-2 gap-10">
                            <div class="space-y-4">
                                <label
                                    class="text-[12px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Opening
                                    Time</label>
                                <input type="time" name="opening_time"
                                    value="<?= htmlspecialchars($gym['opening_time'] ?? '08:00') ?>" class="input-dark">
                            </div>
                            <div class="space-y-4">
                                <label
                                    class="text-[12px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Closing
                                    Time</label>
                                <input type="time" name="closing_time"
                                    value="<?= htmlspecialchars($gym['closing_time'] ?? '22:00') ?>" class="input-dark">
                            </div>
                        </div>

                        <div class="space-y-4">
                            <label
                                class="text-[12px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Max
                                Member Capacity</label>
                            <input type="number" name="max_capacity"
                                value="<?= htmlspecialchars($gym['max_capacity'] ?? '50') ?>" class="input-dark"
                                placeholder="50">
                        </div>

                        <div class="pt-10 border-t border-white/5">
                            <label
                                class="text-[12px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 mb-8 block italic">Amenities
                                & Services</label>
                            <div class="grid grid-cols-2 gap-5">
                                <label
                                    class="flex items-center gap-5 p-6 rounded-3xl bg-black border border-white/5 cursor-pointer hover:border-primary/40 transition-all group">
                                    <input type="checkbox" name="has_lockers" <?= ($gym['has_lockers'] ?? 0) ? 'checked' : '' ?>
                                        class="size-6 rounded-lg border-white/10 bg-white/5 text-primary focus:ring-primary focus:ring-offset-0 transition-all">
                                    <span
                                        class="text-[13px] font-black uppercase tracking-widest text-gray-400 group-hover:text-white">Lockers</span>
                                </label>
                                <label
                                    class="flex items-center gap-5 p-6 rounded-3xl bg-black border border-white/5 cursor-pointer hover:border-primary/40 transition-all group">
                                    <input type="checkbox" name="has_shower" <?= ($gym['has_shower'] ?? 0) ? 'checked' : '' ?>
                                        class="size-6 rounded-lg border-white/10 bg-white/5 text-primary focus:ring-primary focus:ring-offset-0 transition-all">
                                    <span
                                        class="text-[13px] font-black uppercase tracking-widest text-gray-400 group-hover:text-white">Showers</span>
                                </label>
                                <label
                                    class="flex items-center gap-5 p-6 rounded-3xl bg-black border border-white/5 cursor-pointer hover:border-primary/40 transition-all group">
                                    <input type="checkbox" name="has_parking" <?= ($gym['has_parking'] ?? 0) ? 'checked' : '' ?>
                                        class="size-6 rounded-lg border-white/10 bg-white/5 text-primary focus:ring-primary focus:ring-offset-0 transition-all">
                                    <span
                                        class="text-[13px] font-black uppercase tracking-widest text-gray-400 group-hover:text-white">Parking</span>
                                </label>
                                <label
                                    class="flex items-center gap-5 p-6 rounded-3xl bg-black border border-white/5 cursor-pointer hover:border-primary/40 transition-all group">
                                    <input type="checkbox" name="has_wifi" <?= ($gym['has_wifi'] ?? 0) ? 'checked' : '' ?>
                                        class="size-6 rounded-lg border-white/10 bg-white/5 text-primary focus:ring-primary focus:ring-offset-0 transition-all">
                                    <span
                                        class="text-[13px] font-black uppercase tracking-widest text-gray-400 group-hover:text-white">Wi-Fi</span>
                                </label>
                            </div>
                        </div>

                        <div class="pt-10 border-t border-white/5 space-y-4">
                            <label
                                class="text-[12px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Gym
                                House Rules / TOS</label>
                            <textarea name="rules_text" rows="6" class="input-dark"
                                placeholder="Enter terms of service..."><?= htmlspecialchars($gym['rules_text'] ?? '') ?></textarea>
                        </div>

                        <div class="space-y-4">
                            <label
                                class="text-[12px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Mobile
                                App URL</label>
                            <input type="url" name="app_download_link"
                                value="<?= htmlspecialchars($page['app_download_link'] ?? '') ?>" class="input-dark"
                                placeholder="Play Store Link">
                        </div>
                    </div>
                </div>
            </div>

            <!-- SAVE COMMAND FOOTER -->
            <div class="flex items-center justify-end pt-12 border-t border-white/5">
                <button type="submit"
                    class="h-20 px-20 rounded-3xl bg-primary hover:bg-opacity-90 shadow-3xl shadow-primary/40 transition-all text-[15px] font-black italic uppercase tracking-[0.5em] flex items-center justify-center gap-5 group hover:scale-[1.02] active:scale-95">
                    <span
                        class="material-symbols-outlined text-3xl group-hover:rotate-12 transition-transform">cloud_sync</span>
                    Update Global Settings
                </button>
            </div>
        </form>
    </main>

    <script>
        function updateTopClock() {
            const clockEl = document.getElementById('topClock');
            const dateEl = document.getElementById('topDate');
            if (!clockEl || !dateEl) return;
            const now = new Date();
            clockEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            dateEl.textContent = now.toLocaleDateString('en-US', { weekday: 'long', month: 'short', day: '2-digit', year: 'numeric' });
        }
        setInterval(updateTopClock, 1000);
        window.addEventListener('DOMContentLoaded', updateTopClock);

        function previewImg(input, targetId, placeholderId) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function (e) {
                    const img = document.getElementById(targetId);
                    const placeholder = document.getElementById(placeholderId);
                    img.src = e.target.result;
                    img.classList.remove('hidden');
                    if (placeholder) placeholder.classList.add('hidden');
                    updateMockup();
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        function updateMockup() {
            const titleInput = document.querySelector('input[name="page_title"]');
            const colorInput = document.querySelector('input[name="theme_color"]');
            const secondaryInput = document.querySelector('input[name="secondary_color"]');
            const bgInput = document.querySelector('input[name="bg_color"]');
            const fontInput = document.querySelector('select[name="font_family"]');
            const aboutInput = document.querySelector('textarea[name="gym_description"]');
            const logoImg = document.getElementById('logoPreview');

            if (!titleInput || !colorInput) return;

            const data = {
                page_title: titleInput.value,
                theme_color: colorInput.value,
                secondary_color: secondaryInput ? secondaryInput.value : null,
                bg_color: bgInput ? bgInput.value : null,
                font_family: fontInput ? fontInput.value : null,
                about_text: aboutInput ? aboutInput.value : null,
                logo_url: (logoImg && !logoImg.classList.contains('hidden')) ? logoImg.src : null
            };

            // Update Hex Displays
            const phex = document.getElementById('colorHex');
            if (phex) phex.textContent = data.theme_color.toUpperCase();
            const shex = document.getElementById('secondaryHex');
            if (shex && data.secondary_color) shex.textContent = data.secondary_color.toUpperCase();
            const bhex = document.getElementById('bgHex');
            if (bhex && data.bg_color) bhex.textContent = data.bg_color.toUpperCase();

            const portalFrame = document.getElementById('portalFrame');
            if (portalFrame && portalFrame.contentWindow) {
                portalFrame.contentWindow.postMessage({ type: 'updateStyles', data: data }, '*');
            }
        }

        function handleResize() {
            const container = document.getElementById('portalContainer');
            const frame = document.getElementById('portalFrame');
            if (container && frame) {
                const scale = container.offsetWidth / 1600;
                frame.style.transform = `scale(${scale})`;
                container.style.height = (1000 * scale) + 'px';
            }
        }
        window.onload = handleResize;
        window.addEventListener('resize', handleResize);
    </script>

</body>

</html>