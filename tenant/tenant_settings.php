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

// --- Database Refresh (Rules_text exist) ---
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

// Fetch Branding Data from system_settings
$stmtPage = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = ?");
$stmtPage->execute([$user_id]);
$page = $stmtPage->fetchAll(PDO::FETCH_KEY_PAIR);
// Map system_settings keys to expected names for UI if necessary
$page['logo_path'] = $page['system_logo'] ?? '';
$page['theme_color'] = $page['theme_color'] ?? '#8c2bee';
$page['bg_color'] = $page['bg_color'] ?? '#0a090d';
$page['page_slug'] = $page['page_slug'] ?? '';
$page['page_title'] = $page['system_name'] ?? '';

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

// Determine if we show the restriction modal (Only for non-active AND non-pending)
$is_restricted = (!$is_sub_active);

// Hex to RGB helper
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

// Fetch Tenant System Settings
$stmtSync = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = ?");
$stmtSync->execute([$user_id]);
$user_configs = $stmtSync->fetchAll(PDO::FETCH_KEY_PAIR);

// --- AUTO-MIGRATION FROM TENANT_PAGES (Legacy Support) ---
if (empty($user_configs)) {
    try {
        $stmtOld = $pdo->prepare("SELECT * FROM tenant_pages WHERE gym_id = ? LIMIT 1");
        $stmtOld->execute([$gym_id]);
        $old = $stmtOld->fetch();
        if ($old) {
            $migration_map = [
                'page_slug' => $old['page_slug'],
                'system_name' => $old['page_title'],
                'system_logo' => $old['logo_path'],
                'theme_color' => $old['theme_color'],
                'secondary_color' => $old['secondary_color'] ?? '#a1a1aa',
                'bg_color' => $old['bg_color'],
                'font_family' => $old['font_family'],
                'is_active' => $old['is_active']
            ];
            $stmtMigrate = $pdo->prepare("INSERT INTO system_settings (user_id, setting_key, setting_value) VALUES (?, ?, ?)");
            foreach ($migration_map as $mk => $mv) {
                $stmtMigrate->execute([$user_id, $mk, $mv]);
            }
            $user_configs = $migration_map; // Use migrated data
        }
    } catch (Exception $e) { /* Table might already be deleted */ }
}

$configs = [
    'system_name' => $gym['gym_name'] ?? 'Horizon System',
    'theme_color' => '#8c2bee',
    'secondary_color' => '#a1a1aa',
    'text_color' => '#d1d5db',
    'bg_color' => '#0a090d',
    'card_color' => '#141216',
    'auto_card_theme' => '1',
    'font_family' => 'Lexend'
];
$configs = array_merge($configs, $user_configs);

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
        // System Settings Data
        $system_keys = [
            'system_name' => $_POST['system_name'] ?? $gym['gym_name'],
            'theme_color' => $_POST['theme_color'] ?? '#8c2bee',
            'secondary_color' => $_POST['secondary_color'] ?? '#a1a1aa',
            'text_color' => $_POST['text_color'] ?? '#d1d5db',
            'bg_color' => $_POST['bg_color'] ?? '#0a090d',
            'font_family' => $_POST['font_family'] ?? 'Lexend',
            'card_color' => $_POST['card_color'] ?? '#141216',
            'auto_card_theme' => $_POST['auto_card_theme'] ?? '0',
            'is_active' => '1', // Default to active
            'page_slug' => $page['page_slug'] ?? strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $gym['gym_name'])),
            'portal_hero_title' => $_POST['portal_hero_title'] ?? '',
            'portal_hero_subtitle' => $_POST['portal_hero_subtitle'] ?? '',
            'portal_features_title' => $_POST['portal_features_title'] ?? '',
            'portal_features_desc' => $_POST['portal_features_desc'] ?? '',
            'portal_philosophy_title' => $_POST['portal_philosophy_title'] ?? '',
            'portal_philosophy_desc' => $_POST['portal_philosophy_desc'] ?? '',
            'portal_hero_label' => $_POST['portal_hero_label'] ?? '',
            'portal_features_label' => $_POST['portal_features_label'] ?? '',
            'portal_philosophy_label' => $_POST['portal_philosophy_label'] ?? '',
            'portal_plans_title' => $_POST['portal_plans_title'] ?? '',
            'portal_plans_subtitle' => $_POST['portal_plans_subtitle'] ?? '',
            'portal_footer_links_title' => $_POST['portal_footer_links_title'] ?? '',
            'portal_footer_contact_title' => $_POST['portal_footer_contact_title'] ?? '',
            'portal_footer_app_title' => $_POST['portal_footer_app_title'] ?? ''
        ];

        // Logo Processing
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] == 0) {
            $type = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
            $data = file_get_contents($_FILES['logo']['tmp_name']);
            $system_keys['system_logo'] = 'data:image/' . $type . ';base64,' . base64_encode($data);
        } else {
            $system_keys['system_logo'] = $page['system_logo'] ?? '';
        }
    
        // Facility Data (Required for saving)
        $opening_time = !empty($_POST['opening_time']) ? $_POST['opening_time'] : null;
        $closing_time = !empty($_POST['closing_time']) ? $_POST['closing_time'] : null;
        $max_capacity = !empty($_POST['max_capacity']) ? (int)$_POST['max_capacity'] : null;
        
        $has_lockers = isset($_POST['has_lockers']) ? 1 : 0;
        $has_shower = isset($_POST['has_shower']) ? 1 : 0;
        $has_parking = isset($_POST['has_parking']) ? 1 : 0;
        $has_wifi = isset($_POST['has_wifi']) ? 1 : 0;
        $rules_text = $_POST['rules_text'] ?? '';
    
        $now = date('Y-m-d H:i:s');
    
        try {
            // Server-side validation
            if (!$opening_time || !$closing_time || !$max_capacity) {
                throw new Exception("Opening Time, Closing Time, and Max Capacity are required.");
            }
    
            $pdo->beginTransaction();
    
            // 1. Update/Create System Settings
            $stmtUpdateSettings = $pdo->prepare("INSERT INTO system_settings (user_id, setting_key, setting_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            foreach ($system_keys as $key => $value) {
                $stmtUpdateSettings->execute([$user_id, $key, $value]);
            }
    
            // Update local configs immediately for the preview/render
            $configs = array_merge($configs, $system_keys);
    
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
            $stmtSync->execute([$user_id]); $configs = $stmtSync->fetchAll(PDO::FETCH_KEY_PAIR);
            $page = $configs; // For UI consistency
            $page['logo_path'] = $page['system_logo'] ?? '';
            $page['page_title'] = $page['system_name'] ?? '';
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
            --primary: <?= $configs['theme_color'] ?? '#8c2bee' ?>;
            --primary-rgb: <?= hexToRgb($configs['theme_color'] ?? '#8c2bee') ?>;
            --background: <?= $configs['bg_color'] ?? '#0a090d' ?>;
            --highlight: <?= $configs['secondary_color'] ?? '#a1a1aa' ?>;
            --text-main: <?= $configs['text_color'] ?? '#d1d5db' ?>;
            --card-blur: 20px;
            --card-bg: <?= ($configs['auto_card_theme'] ?? '1') === '1' ? 'rgba(' . hexToRgb($configs['theme_color'] ?? '#8c2bee') . ', 0.05)' : ($configs['card_color'] ?? '#14121a') ?>;
        }
        body { font-family: '<?= $configs['font_family'] ?? 'Lexend' ?>', sans-serif; background-color: var(--background); color: var(--text-main); overflow: hidden; }
        .glass-card { background: var(--card-bg); border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); backdrop-filter: blur(var(--card-blur)); }
        
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

        .input-dark { background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 12px; color: white; padding: 12px 16px; font-size: 12px; width: 100%; outline: none; transition: all 0.2s; backdrop-filter: blur(10px); }
        .input-dark:focus { border-color: var(--primary); background: rgba(var(--primary-rgb), 0.05); box-shadow: 0 0 20px rgba(var(--primary-rgb), 0.1); }
        .input-dark option { background-color: #0d0c12; color: white; }
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
            <?php if ($is_restricted): ?>
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

<main class="main-content flex-1 p-10 overflow-y-auto no-scrollbar <?= $is_restricted ? 'blur-overlay' : '' ?>">
    <div class="<?= $is_restricted ? 'blur-overlay-content' : '' ?>">
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
            
            <!-- System Appearance Panel (Sync with Superadmin) -->
            <div class="glass-card p-8 h-full">
                <div class="flex items-center justify-between mb-8 text-primary">
                    <div class="flex items-center gap-4">
                        <div class="size-10 rounded-xl bg-primary/10 flex items-center justify-center">
                            <span class="material-symbols-outlined text-primary">brush</span>
                        </div>
                        <div>
                            <h3 class="text-sm font-black italic uppercase tracking-widest text-primary">1. System Appearance</h3>
                            <p class="text-[10px] text-[--text-main] opacity-70 font-bold uppercase tracking-tight line-clamp-1">Brand identity & glassmorphism</p>
                        </div>
                    </div>
                </div>

                <div class="space-y-8">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[9px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">System Name</label>
                            <input type="text" name="system_name" oninput="updateMockup()" value="<?= htmlspecialchars($configs['system_name'] ?? $gym['gym_name']) ?>" class="input-dark">
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[9px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Font Style</label>
                            <select name="font_family" onchange="updateMockup()" class="input-dark cursor-pointer">
                                <option value="Lexend" <?= ($configs['font_family'] ?? '') === 'Lexend' ? 'selected' : '' ?>>Lexend (Default)</option>
                                <option value="Inter" <?= ($configs['font_family'] ?? '') === 'Inter' ? 'selected' : '' ?>>Inter</option>
                                <option value="Outfit" <?= ($configs['font_family'] ?? '') === 'Outfit' ? 'selected' : '' ?>>Outfit</option>
                                <option value="Plus Jakarta Sans" <?= ($configs['font_family'] ?? '') === 'Plus Jakarta Sans' ? 'selected' : '' ?>>Plus Jakarta Sans</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-6">
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[9px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Main Color</label>
                            <div class="flex items-center gap-4 bg-white/5 p-2 rounded-xl border border-white/5">
                                <input type="color" name="theme_color" oninput="updateMockup()" value="<?= htmlspecialchars($configs['theme_color'] ?? '#8c2bee') ?>" class="size-10 rounded-lg cursor-pointer bg-transparent border-none">
                                <span id="colorHex" class="text-[10px] font-black uppercase text-gray-400"><?= $configs['theme_color'] ?? '#8c2bee' ?></span>
                            </div>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[9px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Icon Color</label>
                            <div class="flex items-center gap-4 bg-white/5 p-2 rounded-xl border border-white/5">
                                <input type="color" name="secondary_color" oninput="updateMockup()" value="<?= htmlspecialchars($configs['secondary_color'] ?? '#a1a1aa') ?>" class="size-10 rounded-lg cursor-pointer bg-transparent border-none">
                                <span id="secondaryHex" class="text-[10px] font-black uppercase text-gray-400"><?= $configs['secondary_color'] ?? '#a1a1aa' ?></span>
                            </div>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[9px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Text Color</label>
                            <div class="flex items-center gap-4 bg-white/5 p-2 rounded-xl border border-white/5">
                                <input type="color" name="text_color" oninput="updateMockup()" value="<?= htmlspecialchars($configs['text_color'] ?? '#d1d5db') ?>" class="size-10 rounded-lg cursor-pointer bg-transparent border-none">
                                <span id="textHex" class="text-[10px] font-black uppercase text-gray-400"><?= $configs['text_color'] ?? '#d1d5db' ?></span>
                            </div>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[9px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Background Color</label>
                            <div class="flex items-center gap-4 bg-white/5 p-2 rounded-xl border border-white/5">
                                <input type="color" name="bg_color" oninput="updateMockup()" value="<?= htmlspecialchars($configs['bg_color'] ?? '#0a090d') ?>" class="size-10 rounded-lg cursor-pointer bg-transparent border-none">
                                <span id="bgHex" class="text-[10px] font-black uppercase text-gray-400"><?= $configs['bg_color'] ?? '#0a090d' ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Card Appearance Section -->
                    <div class="mt-6 pt-6 border-t border-white/5 space-y-4">
                        <div class="flex items-center justify-between">
                            <h4 class="text-[9px] font-black uppercase tracking-[0.2em] text-primary">Card Appearance</h4>
                            <label class="flex items-center gap-3 cursor-pointer group">
                                <span class="text-[8px] font-bold uppercase tracking-widest text-[#d1d5db] opacity-70 group-hover:text-primary transition-colors">Sync Theme</span>
                                <div class="relative inline-flex items-center">
                                    <input type="hidden" name="auto_card_theme" value="0">
                                    <input type="checkbox" name="auto_card_theme" value="1" onchange="updateMockup()" <?= ($configs['auto_card_theme'] ?? '1') === '1' ? 'checked' : '' ?> class="sr-only peer">
                                    <div class="w-10 h-5 bg-white/5 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white/20 after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-primary/30 peer-checked:after:bg-primary transition-all border border-white/5"></div>
                                </div>
                            </label>
                        </div>

                        <div class="grid grid-cols-2 gap-6">
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[9px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Surface Color</label>
                                <div class="flex items-center gap-4 bg-white/5 p-2 rounded-xl border border-white/5">
                                    <input type="color" name="card_color" oninput="updateMockup()" value="<?= htmlspecialchars($configs['card_color'] ?? '#141216') ?>" class="size-10 rounded-lg cursor-pointer bg-transparent border-none">
                                    <span id="cardHex" class="text-[10px] font-black uppercase text-gray-400"><?= $configs['card_color'] ?? '#141216' ?></span>
                                </div>
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

        <!-- Section 3: Portal Content Customization -->
        <div class="glass-card p-8 mt-10">
            <h4 class="text-[12px] font-black italic uppercase tracking-widest text-primary mb-10 flex items-center gap-4">
                <span class="material-symbols-outlined text-xl">edit_document</span> 3. Portal Content Customization
            </h4>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-10">
                <!-- Hero Section -->
                <div class="space-y-6">
                    <h5 class="text-[9px] font-black uppercase tracking-[0.2em] text-gray-500 italic border-b border-white/5 pb-2">Hero Section</h5>
                    <div class="space-y-4">
                        <div class="space-y-2">
                            <label class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Hero Label</label>
                            <input type="text" name="portal_hero_label" oninput="updateMockup()" value="<?= htmlspecialchars($configs['portal_hero_label'] ?? '') ?>" class="input-dark" placeholder="Open for Membership">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Hero Title</label>
                            <input type="text" name="portal_hero_title" oninput="updateMockup()" value="<?= htmlspecialchars($configs['portal_hero_title'] ?? '') ?>" class="input-dark" placeholder="Elevate Your Fitness at <?= htmlspecialchars($gym['gym_name']) ?>">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Hero Subtitle</label>
                            <textarea name="portal_hero_subtitle" oninput="updateMockup()" rows="3" class="input-dark" placeholder="Discover a premium workout experience powered by Horizon's elite technology..."><?= htmlspecialchars($configs['portal_hero_subtitle'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Features Section -->
                <div class="space-y-6">
                    <h5 class="text-[9px] font-black uppercase tracking-[0.2em] text-gray-500 italic border-b border-white/5 pb-2">Features Highlight</h5>
                    <div class="space-y-4">
                        <div class="space-y-2">
                            <label class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Features Label</label>
                            <input type="text" name="portal_features_label" oninput="updateMockup()" value="<?= htmlspecialchars($configs['portal_features_label'] ?? '') ?>" class="input-dark" placeholder="Experience the Difference">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Features Title</label>
                            <input type="text" name="portal_features_title" oninput="updateMockup()" value="<?= htmlspecialchars($configs['portal_features_title'] ?? '') ?>" class="input-dark" placeholder="Premium Training. Elite Management.">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Features Description</label>
                            <textarea name="portal_features_desc" oninput="updateMockup()" rows="3" class="input-dark" placeholder="Access our elite workout tracking and world-class management platform..."><?= htmlspecialchars($configs['portal_features_desc'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Philosophy Section -->
                <div class="space-y-6 md:col-span-2">
                    <h5 class="text-[9px] font-black uppercase tracking-[0.2em] text-gray-500 italic border-b border-white/5 pb-2">Facility Philosophy</h5>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div class="space-y-2">
                            <label class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Philosophy Label</label>
                            <input type="text" name="portal_philosophy_label" oninput="updateMockup()" value="<?= htmlspecialchars($configs['portal_philosophy_label'] ?? '') ?>" class="input-dark" placeholder="The Philosophy">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Philosophy Title</label>
                            <input type="text" name="portal_philosophy_title" oninput="updateMockup()" value="<?= htmlspecialchars($configs['portal_philosophy_title'] ?? '') ?>" class="input-dark" placeholder="Modern technology meets unwavering dedication.">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Philosophy Description</label>
                            <textarea name="portal_philosophy_desc" oninput="updateMockup()" rows="3" class="input-dark" placeholder="Experience fitness like never before with our cutting-edge multi-tenant facility."><?= htmlspecialchars($configs['portal_philosophy_desc'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Membership Plans Section -->
                <div class="space-y-6 md:col-span-2">
                    <h5 class="text-[9px] font-black uppercase tracking-[0.2em] text-gray-500 italic border-b border-white/5 pb-2">Membership Plans Content</h5>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="space-y-2">
                            <label class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Plans Section Title</label>
                            <input type="text" name="portal_plans_title" oninput="updateMockup()" value="<?= htmlspecialchars($configs['portal_plans_title'] ?? '') ?>" class="input-dark" placeholder="Elite Membership Plans">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Plans Section Subtitle</label>
                            <input type="text" name="portal_plans_subtitle" oninput="updateMockup()" value="<?= htmlspecialchars($configs['portal_plans_subtitle'] ?? '') ?>" class="input-dark" placeholder="Select a plan to start your journey...">
                        </div>
                    </div>
                </div>

                <!-- Footer Titles Section -->
                <div class="space-y-6 md:col-span-2">
                    <h5 class="text-[9px] font-black uppercase tracking-[0.2em] text-gray-500 italic border-b border-white/5 pb-2">Footer Labels</h5>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div class="space-y-2">
                            <label class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Links Label</label>
                            <input type="text" name="portal_footer_links_title" oninput="updateMockup()" value="<?= htmlspecialchars($configs['portal_footer_links_title'] ?? '') ?>" class="input-dark" placeholder="Quick Links">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Contact Label</label>
                            <input type="text" name="portal_footer_contact_title" oninput="updateMockup()" value="<?= htmlspecialchars($configs['portal_footer_contact_title'] ?? '') ?>" class="input-dark" placeholder="Contact Facility">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">App Label</label>
                            <input type="text" name="portal_footer_app_title" oninput="updateMockup()" value="<?= htmlspecialchars($configs['portal_footer_app_title'] ?? '') ?>" class="input-dark" placeholder="Get the App">
                        </div>
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
        const titleInput = document.querySelector('input[name="system_name"]');
        const colorInput = document.querySelector('input[name="theme_color"]');
        const secondaryInput = document.querySelector('input[name="secondary_color"]');
        const textInput = document.querySelector('input[name="text_color"]');
        const bgInput = document.querySelector('input[name="bg_color"]');
        const cardInput = document.querySelector('input[name="card_color"]');
        const syncInput = document.querySelector('input[name="auto_card_theme"]:checked');
        const fontInput = document.querySelector('select[name="font_family"]');
        
        if (!colorInput) return;

        // Convert Hex to RGB manually for the preview
        const hexToRgbVals = (hex) => {
            let h = hex.replace('#', '');
            if(h.length === 3) h = h.split('').map(x => x+x).join('');
            return `${parseInt(h.substr(0,2),16)}, ${parseInt(h.substr(2,2),16)}, ${parseInt(h.substr(4,2),16)}`;
        };

        // REAL-TIME DASHBOARD SYNC
        document.documentElement.style.setProperty('--primary', colorInput.value);
        document.documentElement.style.setProperty('--primary-rgb', hexToRgbVals(colorInput.value));
        
        if (textInput) {
            document.documentElement.style.setProperty('--text-main', textInput.value);
            document.body.style.color = textInput.value;
        }

        if (bgInput) {
            document.documentElement.style.setProperty('--background', bgInput.value);
            document.body.style.backgroundColor = bgInput.value;
        }
        
        if (cardInput && syncInput) {
            if (syncInput.value === '1') {
                document.documentElement.style.setProperty('--card-bg', `rgba(${hexToRgbVals(colorInput.value)}, 0.05)`);
            } else {
                document.documentElement.style.setProperty('--card-bg', cardInput.value);
            }
        }

        if (fontInput) {
            document.body.style.fontFamily = `"${fontInput.value}", sans-serif`;
        }

        const data = {
            page_title: titleInput ? titleInput.value : '',
            theme_color: colorInput ? colorInput.value : '#8c2bee',
            secondary_color: secondaryInput ? secondaryInput.value : '#a1a1aa',
            text_color: textInput ? textInput.value : '#d1d5db',
            bg_color: bgInput ? bgInput.value : '#0a090d',
            font_family: fontInput ? fontInput.value : 'Lexend',
            card_color: cardInput ? cardInput.value : '#141216',
            auto_card_theme: syncInput ? syncInput.value : '0',
            // Operational Data Sync
            opening_time: document.querySelector('input[name="opening_time"]')?.value || '',
            closing_time: document.querySelector('input[name="closing_time"]')?.value || '',
            max_capacity: document.querySelector('input[name="max_capacity"]')?.value || '',
            has_lockers: document.querySelector('input[name="has_lockers"]')?.checked ? 1 : 0,
            has_shower: document.querySelector('input[name="has_shower"]')?.checked ? 1 : 0,
            has_parking: document.querySelector('input[name="has_parking"]')?.checked ? 1 : 0,
            has_wifi: document.querySelector('input[name="has_wifi"]')?.checked ? 1 : 0,
            // CMS Content Sync
            portal_hero_title: document.querySelector('input[name="portal_hero_title"]')?.value || '',
            portal_hero_subtitle: document.querySelector('textarea[name="portal_hero_subtitle"]')?.value || '',
            portal_features_title: document.querySelector('input[name="portal_features_title"]')?.value || '',
            portal_features_desc: document.querySelector('textarea[name="portal_features_desc"]')?.value || '',
            portal_philosophy_title: document.querySelector('input[name="portal_philosophy_title"]')?.value || '',
            portal_philosophy_desc: document.querySelector('textarea[name="portal_philosophy_desc"]')?.value || '',
            // Expanded CMS Content Sync
            portal_hero_label: document.querySelector('input[name="portal_hero_label"]')?.value || '',
            portal_features_label: document.querySelector('input[name="portal_features_label"]')?.value || '',
            portal_philosophy_label: document.querySelector('input[name="portal_philosophy_label"]')?.value || '',
            portal_plans_title: document.querySelector('input[name="portal_plans_title"]')?.value || '',
            portal_plans_title: document.querySelector('input[name="portal_plans_title"]')?.value || '',
            portal_plans_subtitle: document.querySelector('input[name="portal_plans_subtitle"]')?.value || '',
            portal_footer_links_title: document.querySelector('input[name="portal_footer_links_title"]')?.value || '',
            portal_footer_contact_title: document.querySelector('input[name="portal_footer_contact_title"]')?.value || '',
            portal_footer_app_title: document.querySelector('input[name="portal_footer_app_title"]')?.value || ''
        };
        
        // Update Hex Displays
        const phex = document.getElementById('colorHex');
        if (phex) phex.textContent = data.theme_color.toUpperCase();
        const shex = document.getElementById('secondaryHex');
        if (shex && data.secondary_color) shex.textContent = data.secondary_color.toUpperCase();
        const thex = document.getElementById('textHex');
        if (thex && data.text_color) thex.textContent = data.text_color.toUpperCase();
        const bhex = document.getElementById('bgHex');
        if (bhex && data.bg_color) bhex.textContent = data.bg_color.toUpperCase();
        const chex = document.getElementById('cardHex');
        if (chex && data.card_color) chex.textContent = data.card_color.toUpperCase();

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
    <div id="subModal" class="<?= $is_restricted ? 'active hard-lock' : '' ?>">
        <div class="glass-card max-w-md w-full p-10 text-center animate-in zoom-in duration-300 relative shadow-[0_0_100px_rgba(140,43,238,0.15)] border-primary/20">
            <div class="size-20 rounded-3xl bg-primary/10 border border-primary/20 flex items-center justify-center mx-auto mb-8">
                <span class="material-symbols-outlined text-4xl text-primary">lock</span>
            </div>
            <h3 class="text-2xl font-black italic uppercase tracking-tighter text-white mb-3">Subscription Required</h3>
            <p class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 mb-10 leading-relaxed italic px-4">
                Access to branding and facility configuration is restricted. Your status is <span class="text-primary italic animate-pulse"><?= $sub_status ?></span>. Please activate a growth plan to unlock.
            </p>
            <div class="flex flex-col gap-4">
                <?php if (strpos($sub_status, 'Pending') !== false): ?>
                    <a href="tenant_dashboard.php" class="h-14 rounded-2xl bg-primary text-white text-[11px] font-black uppercase italic tracking-[0.2em] flex items-center justify-center gap-3 hover:scale-[1.02] active:scale-95 transition-all shadow-xl shadow-primary/20 group">
                        <span class="material-symbols-outlined text-xl group-hover:scale-110 transition-transform">grid_view</span>
                        Back to Dashboard
                    </a>
                <?php else: ?>
                    <a href="subscription_plan.php" class="h-14 rounded-2xl bg-primary text-white text-[11px] font-black uppercase italic tracking-[0.2em] flex items-center justify-center gap-3 hover:scale-[1.02] active:scale-95 transition-all shadow-xl shadow-primary/20 group">
                        <span class="material-symbols-outlined text-xl group-hover:scale-110 transition-transform">payments</span>
                        Select Growth Plan
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

</body>
</html>