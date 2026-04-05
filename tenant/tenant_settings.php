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

// --- Database Refresh (Ensure secondary_color and rules_text exist) ---
try {
    $pdo->exec("ALTER TABLE tenant_pages ADD COLUMN secondary_color VARCHAR(100) DEFAULT '#a1a1aa' AFTER theme_color");
} catch (Exception $e) { /* Column already exists */ }

try {
    $pdo->exec("ALTER TABLE gym_details ADD COLUMN rules_text TEXT AFTER about_text");
} catch (Exception $e) { /* Column already exists */ }

// Fetch Gym & Detail
$stmtGym = $pdo->prepare("
    SELECT g.*, gd.opening_time, gd.closing_time, gd.max_capacity, gd.has_lockers, gd.has_shower, gd.has_parking, gd.has_wifi, gd.rules_text
    FROM gyms g
    LEFT JOIN gym_details gd ON g.gym_id = gd.gym_id
    WHERE g.gym_id = ?
");
$stmtGym->execute([$gym_id]);
$gym = $stmtGym->fetch();

if (!$gym) {
    die("Gym profile not found. Please contact support.");
}

// Fetch Branding Data
$stmtPage = $pdo->prepare("SELECT * FROM tenant_pages WHERE gym_id = ? LIMIT 1");
$stmtPage->execute([$gym_id]);
$page = $stmtPage->fetch();

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

// --- SUBSCRIPTION CHECK FOR RESTRICTION ---
$stmtSubStatus = $pdo->prepare("SELECT subscription_status FROM client_subscriptions WHERE gym_id = ? ORDER BY created_at DESC LIMIT 1");
$stmtSubStatus->execute([$gym_id]);
$sub_status = $stmtSubStatus->fetchColumn() ?: 'None';
$is_sub_active = (strtolower($sub_status) === 'active');

$success = null;
$error = null;

// Default Branding Values
$bg_color = $page['bg_color'] ?? '#0a090d';
$theme_color = $page['theme_color'] ?? '#8c2bee';
$secondary_color = $page['secondary_color'] ?? '#a1a1aa';

// --- UNIFIED POST HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$is_sub_active) {
        $error = "Action restricted. Your subscription is currently $sub_status.";
    } else {
        // Branding Data
        $page_title = $_POST['page_title'] ?? $gym['gym_name'];
    $theme_color = $_POST['theme_color'] ?? '#8c2bee';
    $secondary_color = $_POST['secondary_color'] ?? '#a1a1aa';
    $bg_color = $_POST['bg_color'] ?? '#0a090d';
    $font_family = $_POST['font_family'] ?? 'Lexend';
    
    // Facility Data (Required for saving)
    $opening_time = !empty($_POST['opening_time']) ? $_POST['opening_time'] : null;
    $closing_time = !empty($_POST['closing_time']) ? $_POST['closing_time'] : null;
    $max_capacity = !empty($_POST['max_capacity']) ? (int)$_POST['max_capacity'] : null;
    
    $has_lockers = isset($_POST['has_lockers']) ? 1 : 0;
    $has_shower = isset($_POST['has_shower']) ? 1 : 0;
    $has_parking = isset($_POST['has_parking']) ? 1 : 0;
    $has_wifi = isset($_POST['has_wifi']) ? 1 : 0;
    $gym_description = $_POST['gym_description'] ?? '';
    $rules_text = $_POST['rules_text'] ?? '';

    $now = date('Y-m-d H:i:s');

    try {
        // Server-side validation
        if (!$opening_time || !$closing_time || !$max_capacity) {
            throw new Exception("Opening Time, Closing Time, and Max Capacity are required.");
        }

        $pdo->beginTransaction();

        // 1. Update/Create Branding (tenant_pages)
        $logo_path = $page['logo_path'] ?? '';
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] == 0) {
            $type = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
            $data = file_get_contents($_FILES['logo']['tmp_name']);
            $logo_path = 'data:image/' . $type . ';base64,' . base64_encode($data);
        }

        if ($page) {
            $stmtUpdatePage = $pdo->prepare("UPDATE tenant_pages SET page_title = ?, logo_path = ?, theme_color = ?, secondary_color = ?, bg_color = ?, font_family = ?, updated_at = ? WHERE gym_id = ?");
            $stmtUpdatePage->execute([$page_title, $logo_path, $theme_color, $secondary_color, $bg_color, $font_family, $now, $gym_id]);
        } else {
            $page_slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $gym['gym_name']));
            $stmtInsertPage = $pdo->prepare("INSERT INTO tenant_pages (gym_id, page_slug, page_title, logo_path, theme_color, secondary_color, bg_color, font_family, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtInsertPage->execute([$gym_id, $page_slug, $page_title, $logo_path, $theme_color, $secondary_color, $bg_color, $font_family, $now]);
        }

        // 2. Update Gym Description
        $stmtUpdateGymDesc = $pdo->prepare("UPDATE gyms SET description = ?, updated_at = ? WHERE gym_id = ?");
        $stmtUpdateGymDesc->execute([$gym_description, $now, $gym_id]);

        // 3. Update/Create Gym Details
        $stmtCheckDetails = $pdo->prepare("SELECT 1 FROM gym_details WHERE gym_id = ?");
        $stmtCheckDetails->execute([$gym_id]);
        
        if ($stmtCheckDetails->fetch()) {
            $stmtUpdateDetails = $pdo->prepare("UPDATE gym_details SET opening_time = ?, closing_time = ?, max_capacity = ?, has_lockers = ?, has_shower = ?, has_parking = ?, has_wifi = ?, rules_text = ?, updated_at = ? WHERE gym_id = ?");
            $stmtUpdateDetails->execute([$opening_time, $closing_time, $max_capacity, $has_lockers, $has_shower, $has_parking, $has_wifi, $rules_text, $now, $gym_id]);
        } else {
            $stmtInsertDetails = $pdo->prepare("INSERT INTO gym_details (gym_id, opening_time, closing_time, max_capacity, has_lockers, has_shower, has_parking, has_wifi, rules_text, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtInsertDetails->execute([$gym_id, $opening_time, $closing_time, $max_capacity, $has_lockers, $has_shower, $has_parking, $has_wifi, $rules_text, $now]);
        }

        $pdo->commit();
        $success = "All configurations saved and synchronized successfully!";
        
        // Refresh local data
        $stmtGym->execute([$gym_id]); $gym = $stmtGym->fetch();
        $stmtPage->execute([$gym_id]); $page = $stmtPage->fetch();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = "Update Error: " . $e->getMessage();
    }
    }
}
?>

<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Gym Settings | Horizon</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "var(--primary)", "background-dark": "var(--background)", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)"}}}
        }
    </script>
    <style>
        :root {
            --primary: <?= $page['theme_color'] ?? '#8c2bee' ?>;
            --background: <?= $bg_color ?? '#0a090d' ?>;
        }
        body { font-family: 'Lexend', sans-serif; background-color: var(--background); color: white; overflow: hidden; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); }
        
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
            color: var(--primary) !important;
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
            background: var(--primary);
            border-radius: 4px 0 0 4px;
        }

        .input-dark { background: #0a090d; border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; color: white; padding: 12px 16px; font-size: 12px; width: 100%; outline: none; transition: all 0.2s; }
        .input-dark:focus { border-color: var(--primary); box-shadow: 0 0 0 4px rgba(140, 43, 238, 0.1); }
        /* Invisible Scroll System */
        *::-webkit-scrollbar { display: none !important; }
        * { -ms-overflow-style: none !important; scrollbar-width: none !important; }
        
        #portalFrame {
            width: 1600px;
            height: 2000px;
            border: none;
            position: absolute;
            top: 0;
            left: 0;
            transform-origin: top left;
        }

        /* RESTRICTION BLUR */
        .blur-overlay { position: relative; }
        .blur-overlay-content { filter: blur(12px); pointer-events: none; user-select: none; }

        /* Sidebar-Aware Sub Modal */
        #subModal { 
            position: fixed; 
            top: 0; 
            right: 0; 
            bottom: 0; 
            left: 110px; 
            z-index: 200; 
            display: none !important; 
            align-items: center; 
            justify-content: center; 
            padding: 24px; 
            background: rgba(0, 0, 0, 0.8); 
            backdrop-filter: blur(12px); 
            transition: left 0.4s cubic-bezier(0.4, 0, 0.2, 1); 
        }
        #subModal.active { display: flex !important; }
        .side-nav:hover ~ #subModal { left: 300px; }
    </style>
    <script>
        function showSubWarning() { document.getElementById('subModal').classList.add('active'); }
        function closeSubModal() { document.getElementById('subModal').classList.remove('active'); }

        window.addEventListener('DOMContentLoaded', () => {
            <?php if (!$is_sub_active): ?>
            showSubWarning();
            <?php endif; ?>
        });
    </script>
</head>
<body class="flex h-screen overflow-hidden">

<nav class="side-nav bg-background-dark border-r border-white/5 z-50">
    <div class="px-7 py-8 mb-4 shrink-0">
        <div class="flex items-center gap-4">
            <div class="size-10 rounded-xl shrink-0 overflow-hidden flex items-center justify-center <?= empty($page['logo_path']) ? 'bg-primary shadow-lg shadow-primary/20' : '' ?>">
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
        <div class="nav-section-label px-[38px] mb-2"><span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Main Menu</span></div>
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

        <div class="nav-section-label px-[38px] mb-2 mt-6"><span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Management</span></div>

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
        <div class="nav-section-label px-[38px] mb-2"><span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Account</span></div>
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
    <!-- Header synchronized with my_users.php -->
    <header class="mb-10 flex justify-between items-end px-2">
        <div>
            <h2 class="text-3xl font-black uppercase tracking-tighter text-white italic">Tenant <span class="text-primary italic">Settings</span></h2>
            <p class="text-gray-500 text-[10px] font-black uppercase tracking-widest mt-1 italic">
                <?= htmlspecialchars($gym['gym_name']) ?> Branding and Operations Configuration
            </p>
        </div>

        <div class="flex items-center gap-8">

            <div class="text-right">
                <p id="topClock" class="text-white font-black italic text-2xl leading-none tracking-tighter">00:00:00 AM</p>
                <p id="topDate" class="text-primary text-[10px] font-bold uppercase tracking-widest mt-2 px-1 opacity-80 italic">
                    <?= date('l, M d, Y') ?>
                </p>
            </div>
        </div>
    </header>

    <?php if ($error): ?>
    <div class="mb-10 p-5 rounded-2xl bg-rose-500/10 border border-rose-500/20 text-rose-400 text-[11px] font-black uppercase italic tracking-widest flex items-center gap-3 animate-in fade-in slide-in-from-top-4 duration-500">
        <span class="material-symbols-outlined text-base">error</span> <?= $error ?>
    </div>
    <?php endif; ?>

    <div class="<?= !$is_sub_active ? 'blur-overlay' : '' ?>">
        <?php if (!$is_sub_active): ?>
            <!-- Premium Modal shown via JS on load -->
        <?php endif; ?>

        <form id="unifiedSettingsForm" method="POST" enctype="multipart/form-data" class="space-y-12 pb-20 max-w-[1700px] mx-auto <?= !$is_sub_active ? 'blur-overlay-content' : '' ?>">
        
        <!-- TOP: LIVE PREVIEW TERMINAL -->
        <div class="space-y-6">
            <div class="flex items-center justify-between px-4">
                <div class="flex items-center gap-3">
                    <div class="size-8 rounded-lg bg-primary/20 flex items-center justify-center">
                        <span class="material-symbols-outlined text-primary text-lg font-bold">visibility</span>
                    </div>
                    <h4 class="text-[12px] font-black italic uppercase tracking-widest text-white">Live Gym Portal Preview</h4>
                </div>
            </div>
            
            <div class="glass-card p-4 overflow-hidden shadow-2xl relative">
                <div class="absolute top-8 left-8 flex items-center gap-1.5 z-10 p-2 rounded-full bg-black/40 backdrop-blur-md border border-white/10">
                    <div class="size-2.5 rounded-full bg-red-400"></div>
                    <div class="size-2.5 rounded-full bg-amber-400"></div>
                    <div class="size-2.5 rounded-full bg-green-400"></div>
                </div>
                
                <div id="portalContainer" class="w-full relative shadow-3xl border border-white/5 rounded-3xl overflow-y-auto bg-black shadow-inner origin-top no-scrollbar">
                    <!-- High-Fidelity Desktop Mockup -->
                    <!-- High-Fidelity Desktop Mockup (Always Use portal.php?preview=1) -->
                    <iframe id="portalFrame" src="../portal.php?gym=<?= $page['page_slug'] ?? '' ?>&preview=1" class="absolute top-0 left-0 w-[1600px] h-[2000px] border-none origin-top-left"></iframe>
                </div>
            </div>
        </div>

        <!-- BOTTOM GRID -->
        <div class="grid grid-cols-1 xl:grid-cols-2 gap-10 items-start">
            
            <!-- Branding Panel (Reduced Size/Scale) -->
            <div class="glass-card p-8">
                <h4 class="text-[12px] font-black italic uppercase tracking-widest text-primary mb-10 flex items-center gap-4">
                    <span class="material-symbols-outlined text-xl">brush</span> 1. Global Branding
                </h4>
                <div class="space-y-10">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Gym Display Name</label>
                        <input type="text" name="page_title" oninput="updateMockup()" value="<?= htmlspecialchars($page['page_title'] ?? $gym['gym_name']) ?>" class="input-dark font-bold italic uppercase tracking-tight" placeholder="Name on Portal">
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                        <div class="space-y-2">
                            <label class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Primary Theme Color</label>
                            <div class="flex items-center gap-4 bg-black p-3 rounded-2xl border border-white/5">
                                <input type="color" name="theme_color" oninput="updateMockup()" value="<?= htmlspecialchars($page['theme_color'] ?? '#8c2bee') ?>" class="size-10 rounded-xl cursor-pointer bg-transparent border-none">
                                <span id="colorHex" class="text-[11px] font-black italic uppercase text-gray-400 tracking-widest"><?= $page['theme_color'] ?? '#8c2bee' ?></span>
                            </div>
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Secondary Accent Color</label>
                            <div class="flex items-center gap-4 bg-black p-3 rounded-2xl border border-white/5">
                                <input type="color" name="secondary_color" oninput="updateMockup()" value="<?= htmlspecialchars($page['secondary_color'] ?? '#a1a1aa') ?>" class="size-10 rounded-xl cursor-pointer bg-transparent border-none">
                                <span id="secondaryHex" class="text-[11px] font-black italic uppercase text-gray-400 tracking-widest"><?= $page['secondary_color'] ?? '#a1a1aa' ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                        <div class="space-y-2">
                            <label class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Page Background Color</label>
                            <div class="flex items-center gap-4 bg-black p-3 rounded-2xl border border-white/5">
                                <input type="color" name="bg_color" oninput="updateMockup()" value="<?= htmlspecialchars($page['bg_color'] ?? '#0a090d') ?>" class="size-10 rounded-xl cursor-pointer bg-transparent border-none">
                                <span id="bgHex" class="text-[11px] font-black italic uppercase text-gray-400 tracking-widest"><?= $page['bg_color'] ?? '#0a090d' ?></span>
                            </div>
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Typography Identity</label>
                            <select name="font_family" onchange="updateMockup()" class="input-dark">
                                <option value="Lexend" <?= ($page['font_family'] ?? 'Lexend') == 'Lexend' ? 'selected' : '' ?>>Lexend (Default)</option>
                                <option value="Inter" <?= ($page['font_family'] ?? '') == 'Inter' ? 'selected' : '' ?>>Inter</option>
                                <option value="Outfit" <?= ($page['font_family'] ?? '') == 'Outfit' ? 'selected' : '' ?>>Outfit</option>
                                <option value="Plus Jakarta Sans" <?= ($page['font_family'] ?? '') == 'Plus Jakarta Sans' ? 'selected' : '' ?>>Plus Jakarta Sans</option>
                            </select>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <label class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Portal Description</label>
                        <textarea name="gym_description" rows="3" oninput="updateMockup()" class="input-dark" placeholder="About your facility..."><?= htmlspecialchars($gym['description'] ?? '') ?></textarea>
                    </div>

                    <div class="pt-8 border-t border-white/5">
                        <div class="space-y-2">
                            <label class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Brand Logo</label>
                            <div class="flex items-center gap-6">
                                <div class="size-24 rounded-2xl bg-black border border-white/5 flex items-center justify-center overflow-hidden shrink-0 shadow-lg">
                                    <img id="logoPreview" src="<?= $page['logo_path'] ?? '' ?>" class="size-full object-contain p-4 <?= empty($page['logo_path']) ? 'hidden' : '' ?>">
                                    <span id="logoPlaceholder" class="material-symbols-outlined text-gray-800 text-2xl <?= !empty($page['logo_path']) ? 'hidden' : '' ?>">photo_library</span>
                                </div>
                                <input type="file" name="logo" onchange="previewImg(this, 'logoPreview', 'logoPlaceholder')" class="text-[10px] text-gray-500 file:bg-primary/10 file:text-primary file:border-none file:px-5 file:py-2 file:rounded-lg file:font-black file:uppercase file:mr-4 file:cursor-pointer hover:file:bg-primary/20 transition-all">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Operations Panel -->
            <div class="glass-card p-8">
                <h4 class="text-[12px] font-black italic uppercase tracking-widest text-primary mb-10 flex items-center gap-4">
                    <span class="material-symbols-outlined text-xl">schedule</span> 2. Operational Rules
                </h4>
                <div class="space-y-8">
                    <div class="grid grid-cols-2 gap-8">
                        <div class="space-y-2">
                            <label class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Opening Time</label>
                            <input type="time" name="opening_time" oninput="updateMockup()" value="<?= htmlspecialchars($gym['opening_time'] ?? '') ?>" class="input-dark" required>
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Closing Time</label>
                            <input type="time" name="closing_time" oninput="updateMockup()" value="<?= htmlspecialchars($gym['closing_time'] ?? '') ?>" class="input-dark" required>
                        </div>
                    </div>
                    
                    <div class="space-y-2">
                        <label class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Max Member Capacity</label>
                        <input type="number" name="max_capacity" oninput="updateMockup()" value="<?= htmlspecialchars($gym['max_capacity'] ?? '') ?>" class="input-dark" placeholder="Enter capacity (e.g. 50)" required>
                    </div>

                    <div class="pt-8 border-t border-white/5">
                        <label class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 mb-6 block italic">Amenities & Services</label>
                        <div class="grid grid-cols-2 gap-4">
                            <label class="flex items-center gap-4 p-4 rounded-2xl bg-black border border-white/5 cursor-pointer hover:border-primary/40 transition-all group">
                                <input type="checkbox" name="has_lockers" onchange="updateMockup()" <?= ($gym['has_lockers'] ?? 0) ? 'checked' : '' ?> class="size-5 rounded-md border-white/10 bg-white/5 text-primary focus:ring-primary focus:ring-offset-0 transition-all">
                                <span class="text-[11px] font-black uppercase tracking-widest text-gray-400 group-hover:text-white">Lockers</span>
                            </label>
                            <label class="flex items-center gap-4 p-4 rounded-2xl bg-black border border-white/5 cursor-pointer hover:border-primary/40 transition-all group">
                                <input type="checkbox" name="has_shower" onchange="updateMockup()" <?= ($gym['has_shower'] ?? 0) ? 'checked' : '' ?> class="size-5 rounded-md border-white/10 bg-white/5 text-primary focus:ring-primary focus:ring-offset-0 transition-all">
                                <span class="text-[11px] font-black uppercase tracking-widest text-gray-400 group-hover:text-white">Showers</span>
                            </label>
                            <label class="flex items-center gap-4 p-4 rounded-2xl bg-black border border-white/5 cursor-pointer hover:border-primary/40 transition-all group">
                                <input type="checkbox" name="has_parking" onchange="updateMockup()" <?= ($gym['has_parking'] ?? 0) ? 'checked' : '' ?> class="size-5 rounded-md border-white/10 bg-white/5 text-primary focus:ring-primary focus:ring-offset-0 transition-all">
                                <span class="text-[11px] font-black uppercase tracking-widest text-gray-400 group-hover:text-white">Parking</span>
                            </label>
                            <label class="flex items-center gap-4 p-4 rounded-2xl bg-black border border-white/5 cursor-pointer hover:border-primary/40 transition-all group">
                                <input type="checkbox" name="has_wifi" onchange="updateMockup()" <?= ($gym['has_wifi'] ?? 0) ? 'checked' : '' ?> class="size-5 rounded-md border-white/10 bg-white/5 text-primary focus:ring-primary focus:ring-offset-0 transition-all">
                                <span class="text-[11px] font-black uppercase tracking-widest text-gray-400 group-hover:text-white">Wi-Fi</span>
                            </label>
                        </div>
                    </div>

                    <div class="pt-8 border-t border-white/5 space-y-2">
                        <label class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Gym House Rules / TOS</label>
                        <textarea name="rules_text" rows="5" class="input-dark" placeholder="Enter terms of service..."><?= htmlspecialchars($gym['rules_text'] ?? '') ?></textarea>
                    </div>


                </div>
            </div>
        </div>

        <!-- SAVE COMMAND FOOTER (Reduced Size) -->
        <div class="flex items-center justify-end pt-8 border-t border-white/5">
            <button type="submit" class="h-16 px-12 rounded-2xl bg-primary hover:bg-opacity-90 shadow-2xl shadow-primary/40 transition-all text-[12px] font-black italic uppercase tracking-[0.3em] flex items-center justify-center gap-4 group hover:scale-[1.02] active:scale-95">
                <span class="material-symbols-outlined text-2xl group-hover:rotate-12 transition-transform">verified</span> 
                Update Global Settings
            </button>
        </div>
        </div>
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
            reader.onload = function(e) {
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

        // REAL-TIME DASHBOARD SYNC
        document.documentElement.style.setProperty('--primary', colorInput.value);
        if (bgInput) {
            document.documentElement.style.setProperty('--background', bgInput.value);
            document.body.style.backgroundColor = bgInput.value;
        }

        const data = {
            page_title: titleInput ? titleInput.value : '',
            theme_color: colorInput ? colorInput.value : '#8c2bee',
            secondary_color: secondaryInput ? secondaryInput.value : '#a1a1aa',
            bg_color: bgInput ? bgInput.value : '#0a090d',
            font_family: fontInput ? fontInput.value : 'Lexend',
            about_text: aboutInput ? aboutInput.value : '',
            logo_url: (logoImg && !logoImg.classList.contains('hidden')) ? logoImg.src : null,
            // Operational Data Sync
            opening_time: document.querySelector('input[name="opening_time"]')?.value || '',
            closing_time: document.querySelector('input[name="closing_time"]')?.value || '',
            max_capacity: document.querySelector('input[name="max_capacity"]')?.value || '',
            has_lockers: document.querySelector('input[name="has_lockers"]')?.checked ? 1 : 0,
            has_shower: document.querySelector('input[name="has_shower"]')?.checked ? 1 : 0,
            has_parking: document.querySelector('input[name="has_parking"]')?.checked ? 1 : 0,
            has_wifi: document.querySelector('input[name="has_wifi"]')?.checked ? 1 : 0
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
            container.style.height = '600px'; 
        }
    }
    window.onload = handleResize;
    window.addEventListener('resize', handleResize);
</script>

    <!-- Restriction Modal (Sidebar-Aware) -->
    <div id="subModal">
        <div class="glass-card max-w-md w-full p-10 text-center animate-in zoom-in duration-300 relative shadow-[0_0_100px_rgba(140,43,238,0.15)] border-primary/20">
            <div class="size-20 rounded-3xl bg-primary/10 border border-primary/20 flex items-center justify-center mx-auto mb-8">
                <span class="material-symbols-outlined text-4xl text-primary">lock</span>
            </div>
            <h3 class="text-2xl font-black italic uppercase tracking-tighter text-white mb-3">Subscription Required</h3>
            <p class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 mb-10 leading-relaxed italic px-4">
                Access to branding and facility configuration is restricted. Your status is <span class="text-primary italic animate-pulse"><?= $sub_status ?></span>. Please activate a growth plan to unlock.
            </p>
            <div class="flex flex-col gap-4">
                <a href="subscription_plan.php" class="h-14 rounded-2xl bg-primary text-white text-[11px] font-black uppercase italic tracking-[0.2em] flex items-center justify-center gap-3 hover:scale-[1.02] active:scale-95 transition-all shadow-xl shadow-primary/20 group">
                    <span class="material-symbols-outlined text-xl group-hover:scale-110 transition-transform">payments</span>
                    Select Growth Plan
                </a>
            </div>
        </div>
    </div>

</body>
</html>