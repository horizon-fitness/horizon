<?php
session_start();
require_once '../db.php';

// Security Check: Only Tenants/Admins/Staff
$role = strtolower($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || ($role !== 'tenant' && $role !== 'admin' && $role !== 'staff' && $role !== 'coach')) {
    header("Location: ../login.php");
    exit;
}

$gym_id = $_SESSION['gym_id'];
$user_id = $_SESSION['user_id'];
$active_page = "facility";

// Fetch Gym Details
$stmtGym = $pdo->prepare("SELECT * FROM gyms WHERE gym_id = ?");
$stmtGym->execute([$gym_id]);
$gym = $stmtGym->fetch();

// Fetch Subscription for header
$stmtSub = $pdo->prepare("SELECT ws.plan_name FROM client_subscriptions cs JOIN website_plans ws ON cs.website_plan_id = ws.website_plan_id WHERE cs.gym_id = ? AND cs.subscription_status = 'Active' LIMIT 1");
$stmtSub->execute([$gym_id]);
$sub = $stmtSub->fetch();

// Fetch CMS Page for sidebar logo
$stmtPage = $pdo->prepare("SELECT * FROM tenant_pages WHERE gym_id = ? LIMIT 1");
$stmtPage->execute([$gym_id]);
$page = $stmtPage->fetch();

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $opening_time = $_POST['opening_time'];
    $closing_time = $_POST['closing_time'];
    $max_capacity = (int)$_POST['max_capacity'];
    $has_lockers = isset($_POST['has_lockers']) ? 1 : 0;
    $has_shower = isset($_POST['has_shower']) ? 1 : 0;
    $has_parking = isset($_POST['has_parking']) ? 1 : 0;
    $has_wifi = isset($_POST['has_wifi']) ? 1 : 0;
    $rules_text = $_POST['rules_text'] ?? '';

    try {
        $stmtUpdate = $pdo->prepare("UPDATE gyms SET opening_time = ?, closing_time = ?, max_capacity = ?, has_lockers = ?, has_shower = ?, has_parking = ?, has_wifi = ?, rules_text = ? WHERE gym_id = ?");
        $stmtUpdate->execute([$opening_time, $closing_time, $max_capacity, $has_lockers, $has_shower, $has_parking, $has_wifi, $rules_text, $gym_id]);
        
        $success = "Facility configuration updated successfully!";
        // Refresh gym data
        $stmtGym->execute([$gym_id]);
        $gym = $stmtGym->fetch();
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$page_title_meta = "Facility Setup";
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title><?= $page_title_meta ?> | Horizon Partners</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&family=Inter:wght@400;700&family=Outfit:wght@400;700&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "#8c2bee", "background-dark": "#0a090d", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)"}}}
        }
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; overflow: hidden; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        .nav-link { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; transition: all 0.2s; white-space: nowrap; }

        .active-nav { color: #8c2bee !important; position: relative; }

        .active-nav::after { 

            content: ''; 

            position: absolute; 

            right: -32px; 

            top: 50%;

            transform: translateY(-50%);

            width: 4px; 

            height: 20px; 

            background: #8c2bee; 

            border-radius: 99px; 

        }

        /* Sidebar Hover Logic */
        .sidebar-nav {
            width: 85px; 
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .sidebar-nav:hover {
            width: 300px; 
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
            margin-bottom: 8px !important; 
            pointer-events: auto;
        }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        
        .input-dark { background: #0a090d; border: 1px solid rgba(255,255,255,0.05); border-radius: 12px; color: white; padding: 0.75rem 1rem; font-size: 0.75rem; width: 100%; outline: none; transition: border-color 0.2s; }
        .input-dark:focus { border-color: #8c2bee; }
    </style>
</head>
<body class="antialiased flex h-screen overflow-hidden">

<nav class="sidebar-nav bg-[#0a090d] border-r border-white/5 sticky top-0 h-screen px-7 py-8 z-50 shrink-0 flex flex-col">
    <div class="mb-10 shrink-0"> 
        <div class="flex items-center gap-4 mb-4"> 
            <div class="size-11 rounded-xl bg-primary flex items-center justify-center shadow-lg shrink-0 overflow-hidden">
                <?php if (!empty($gym['logo_path'])): ?>
                    <img src="<?= htmlspecialchars($gym['logo_path']) ?>" class="size-full object-contain">
                <?php else: ?>
                    <span class="material-symbols-outlined text-white text-2xl">bolt</span>
                <?php endif; ?>
            </div>
            <h1 class="nav-text text-lg font-black italic uppercase tracking-tighter text-white leading-tight break-words line-clamp-2">
                <?= htmlspecialchars($gym['gym_name'] ?? 'CORSANO FITNESS') ?>
            </h1>
        </div>
    </div>
    
    <div class="flex-1 overflow-y-auto no-scrollbar space-y-1 pr-2">
        <div class="nav-section-header px-0 mb-2">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Main Menu</span>
        </div>
        
        <a href="tenant_dashboard.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'dashboard') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">grid_view</span> 
            <span class="nav-text">Dashboard</span>
        </a>
        
        <a href="my_users.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'users') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">group</span> 
            <span class="nav-text">My Users</span>
        </a>

        <a href="transactions.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'transactions') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">receipt_long</span> 
            <span class="nav-text">Transactions</span>
            <span class="size-1.5 rounded-full bg-red-500 ml-auto"></span>
        </a>

        <a href="attendance.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'attendance') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">history</span> 
            <span class="nav-text">Attendance</span>
        </a>

        <div class="nav-section-header px-0 mb-2 mt-6">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Management</span>
        </div>

        <a href="staff.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'staff') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">badge</span> 
            <span class="nav-text">Staff Management</span>
        </a>

        <a href="reports.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'reports') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">analytics</span> 
            <span class="nav-text">System Reports</span>
        </a>

        <a href="sales_report.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'sales') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">payments</span> 
            <span class="nav-text">Sales Reports</span>
        </a>
    </div>

    <div class="mt-auto pt-4 border-t border-white/10 flex flex-col gap-1 shrink-0">
        <div class="nav-section-header px-0 mb-2">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Account</span>
        </div>
        
        <a href="facility_setup.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'facility') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">settings</span> 
            <span class="nav-text">Settings</span>
        </a>

        <a href="tenant_settings.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'profile') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">person</span> 
            <span class="nav-text">Profile</span>
        </a>

        <a href="../logout.php" class="text-gray-400 hover:text-rose-500 transition-colors flex items-center gap-4 group py-2">
            <span class="material-symbols-outlined group-hover:translate-x-1 transition-transform text-xl shrink-0">logout</span>
            <span class="nav-link nav-text text-sm">Sign Out</span>
        </a>
    </div>
</nav>

<script>
    function updateTopClock() {
        const now = new Date();
        const timeString = now.toLocaleTimeString('en-US', { 
            hour: '2-digit', 
            minute: '2-digit', 
            second: '2-digit' 
        });
        const clockEl = document.getElementById('topClock');
        if (clockEl) clockEl.textContent = timeString;
    }
    setInterval(updateTopClock, 1000);
    window.addEventListener('DOMContentLoaded', updateTopClock);
</script>

<main class="flex-1 overflow-y-auto no-scrollbar p-10 master-content">
    <div class="max-w-4xl mx-auto w-full">
        <header class="mb-10 flex justify-between items-end">
            <div>
                <h2 class="text-3xl font-black italic uppercase tracking-tighter text-primary">Facility <span class="text-white">Setup</span></h2>
                <p class="text-gray-500 font-bold uppercase tracking-[0.3em] text-[10px] mt-2">Configure gym operations & amenities</p>
            </div>
            <div class="flex flex-col items-end">
                <p id="topClock" class="text-white font-black italic text-2xl leading-none">00:00:00 AM</p>
                <p class="text-[10px] font-black uppercase text-gray-500 tracking-[0.2em] leading-none mt-2"><?= date('l, M d, Y') ?></p>
                <div class="flex items-center gap-2 mt-2 px-3 py-1 rounded-lg bg-white/5 border border-white/5">
                    <p class="text-[9px] font-black uppercase text-gray-400 tracking-widest">Plan:</p>
                    <p class="text-[10px] font-black uppercase text-white italic tracking-tighter"><?= htmlspecialchars($sub['plan_name'] ?? 'Standard Plan') ?></p>
                    <span class="px-1.5 py-0.5 rounded-md bg-primary/20 text-primary text-[7px] font-black uppercase tracking-widest">Active</span>
                </div>
            </div>
        </header>

        <?php if ($error): ?>
        <div class="mb-8 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-xs font-bold flex items-center gap-3">
            <span class="material-symbols-outlined">report</span> <?= $error ?>
        </div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="mb-8 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-xs font-bold flex items-center gap-3">
            <span class="material-symbols-outlined">check_circle</span> <?= $success ?>
        </div>
        <?php endif; ?>

        <form method="POST" class="space-y-8">
        <div class="glass-card p-8">
            <h4 class="text-sm font-black italic uppercase tracking-tighter mb-6 flex items-center gap-2">
                <span class="material-symbols-outlined text-primary">schedule</span> Operational Hours
            </h4>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="space-y-1.5">
                    <label class="text-[9px] font-black uppercase tracking-widest text-gray-500 ml-1">OPENING TIME</label>
                    <input type="time" name="opening_time" value="<?= htmlspecialchars($gym['opening_time'] ?? '') ?>" class="input-dark">
                </div>
                <div class="space-y-1.5">
                    <label class="text-[9px] font-black uppercase tracking-widest text-gray-500 ml-1">CLOSING TIME</label>
                    <input type="time" name="closing_time" value="<?= htmlspecialchars($gym['closing_time'] ?? '') ?>" class="input-dark">
                </div>
                <div class="space-y-1.5">
                    <label class="text-[9px] font-black uppercase tracking-widest text-gray-500 ml-1">MAX CAPACITY</label>
                    <input type="number" name="max_capacity" value="<?= htmlspecialchars($gym['max_capacity'] ?? '') ?>" class="input-dark">
                </div>
            </div>
        </div>

        <div class="glass-card p-8">
            <h4 class="text-sm font-black italic uppercase tracking-tighter mb-6 flex items-center gap-2">
                <span class="material-symbols-outlined text-primary">featured_play_list</span> Amenities & Features
            </h4>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <label class="flex items-center gap-3 p-4 rounded-xl bg-background-dark border border-white/5 cursor-pointer hover:border-primary/50 transition-all">
                    <input type="checkbox" name="has_lockers" <?= ($gym['has_lockers'] ?? 0) ? 'checked' : '' ?> class="size-4 rounded border-white/10 bg-white/5 text-primary focus:ring-primary focus:ring-offset-0">
                    <span class="text-[10px] font-black uppercase tracking-widest">Lockers</span>
                </label>
                <label class="flex items-center gap-3 p-4 rounded-xl bg-background-dark border border-white/5 cursor-pointer hover:border-primary/50 transition-all">
                    <input type="checkbox" name="has_shower" <?= ($gym['has_shower'] ?? 0) ? 'checked' : '' ?> class="size-4 rounded border-white/10 bg-white/5 text-primary focus:ring-primary focus:ring-offset-0">
                    <span class="text-[10px] font-black uppercase tracking-widest">Showers</span>
                </label>
                <label class="flex items-center gap-3 p-4 rounded-xl bg-background-dark border border-white/5 cursor-pointer hover:border-primary/50 transition-all">
                    <input type="checkbox" name="has_parking" <?= ($gym['has_parking'] ?? 0) ? 'checked' : '' ?> class="size-4 rounded border-white/10 bg-white/5 text-primary focus:ring-primary focus:ring-offset-0">
                    <span class="text-[10px] font-black uppercase tracking-widest">Parking</span>
                </label>
                <label class="flex items-center gap-3 p-4 rounded-xl bg-background-dark border border-white/5 cursor-pointer hover:border-primary/50 transition-all">
                    <input type="checkbox" name="has_wifi" <?= ($gym['has_wifi'] ?? 0) ? 'checked' : '' ?> class="size-4 rounded border-white/10 bg-white/5 text-primary focus:ring-primary focus:ring-offset-0">
                    <span class="text-[10px] font-black uppercase tracking-widest">Wi-Fi</span>
                </label>
            </div>
        </div>

        <div class="glass-card p-8">
            <h4 class="text-sm font-black italic uppercase tracking-tighter mb-6 flex items-center gap-2">
                <span class="material-symbols-outlined text-primary">gavel</span> House Rules
            </h4>
            <div class="space-y-1.5">
                <label class="text-[9px] font-black uppercase tracking-widest text-gray-500 ml-1">GYM POLICIES</label>
                <textarea name="rules_text" rows="6" class="input-dark" placeholder="Enter gym rules and policies..."><?= htmlspecialchars($gym['rules_text'] ?? '') ?></textarea>
            </div>
        </div>

        <button type="submit" class="w-full h-16 rounded-2xl bg-primary hover:bg-opacity-90 shadow-lg shadow-primary/20 transition-all text-xs font-black uppercase tracking-[0.2em] flex items-center justify-center gap-3">
            <span class="material-symbols-outlined">save</span> Save Configuration
        </button>
    </form>
</div>
</main>

</body>
</html>