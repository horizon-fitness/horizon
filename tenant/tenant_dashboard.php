<?php
session_start();
require_once '../db.php';

// Security Check: Only Tenants/Admins
$role = strtolower($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || ($role !== 'tenant' && $role !== 'admin')) {
    header("Location: ../login.php");
    exit;
}

$gym_id = $_SESSION['gym_id'];
$user_id = $_SESSION['user_id'];

// Check Subscription
$stmtSub = $pdo->prepare("SELECT ws.plan_name FROM client_subscriptions cs JOIN website_plans ws ON cs.website_plan_id = ws.website_plan_id WHERE cs.gym_id = ? AND cs.subscription_status = 'Active' LIMIT 1");
$stmtSub->execute([$gym_id]);
$sub = $stmtSub->fetch();

if (!$sub) {
    header("Location: subscription_plan.php");
    exit;
}

// Fetch Gym Details
$stmtGym = $pdo->prepare("SELECT * FROM gyms WHERE gym_id = ?");
$stmtGym->execute([$gym_id]);
$gym = $stmtGym->fetch();

// Fetch Simple Stats
$staff_count = $pdo->prepare("SELECT COUNT(*) FROM staff WHERE gym_id = ?");
$staff_count->execute([$gym_id]);
$total_staff = $staff_count->fetchColumn();

$member_count = $pdo->prepare("SELECT COUNT(*) FROM members WHERE gym_id = ?");
$member_count->execute([$gym_id]);
$total_members = $member_count->fetchColumn();

// Active CMS Page
$stmtPage = $pdo->prepare("SELECT * FROM tenant_pages WHERE gym_id = ? LIMIT 1");
$stmtPage->execute([$gym_id]);
$page = $stmtPage->fetch();

$page_title = "Owner Dashboard";
$active_page = "dashboard";
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title><?= $page_title ?> | Horizon Partners</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "#8c2bee", "background-dark": "#0a090d", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)"}}}
        }
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        .nav-link { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; transition: all 0.2s; white-space: nowrap; }
        .active-nav { color: #8c2bee !important; position: relative; }
        .active-nav::after { content: ''; position: absolute; right: -32px; top: 0; width: 4px; height: 100%; background: #8c2bee; border-radius: 2px; }
    </style>
</head>
<body class="antialiased flex flex-row min-h-screen">

<nav class="flex flex-col w-64 bg-[#0a090d] border-r border-white/5 sticky top-0 h-screen p-8 z-50 shrink-0">
    <div class="mb-12">
        <div class="flex items-center gap-4 mb-6">
            <div class="size-10 rounded-xl bg-primary flex items-center justify-center shadow-lg shrink-0">
                <span class="material-symbols-outlined text-white text-2xl">bolt</span>
            </div>
            <h1 class="text-xl font-black italic uppercase tracking-tighter text-white"><?= htmlspecialchars($gym['gym_name']) ?></h1>
        </div>
        <div class="p-4 rounded-2xl bg-white/5 border border-white/5">
            <p class="text-[9px] font-black uppercase tracking-[0.2em] text-gray-500 mb-1">Active Plan</p>
            <p class="text-primary text-xs font-black uppercase tracking-widest italic"><?= htmlspecialchars($sub['plan_name']) ?></p>
        </div>
    </div>
    
    <div class="flex flex-col gap-5 flex-1 overflow-y-auto pr-2">
        <a href="tenant_dashboard.php" class="nav-link flex items-center gap-3 <?= ($active_page == 'dashboard') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl">dashboard</span> Dashboard
        </a>
        <a href="tenant_settings.php" class="nav-link flex items-center gap-3 <?= ($active_page == 'settings') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl">palette</span> CMS Customization
        </a>
        <a href="#" class="nav-link flex items-center gap-3 text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl">group</span> Staff Management
        </a>
        <a href="#" class="nav-link flex items-center gap-3 text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl">person_search</span> Member Directory
        </a>
        <a href="#" class="nav-link flex items-center gap-3 text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl">payments</span> Billing & Revenue
        </a>
        
        <div class="mt-auto pt-8 border-t border-white/10">
            <a href="../logout.php" class="text-gray-400 hover:text-red-500 transition-colors flex items-center gap-3 group">
                <span class="material-symbols-outlined group-hover:translate-x-1 transition-transform">logout</span>
                <span class="nav-link">Sign Out</span>
            </a>
        </div>
    </div>
</nav>

<div class="flex-1 flex flex-col min-w-0 overflow-y-auto">
    <main class="flex-1 p-10 max-w-[1400px] w-full mx-auto">
        <header class="mb-10">
            <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white">Owner <span class="text-primary">Dashboard</span></h2>
            <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-1">Management Overview for <?= htmlspecialchars($gym['gym_name']) ?></p>
        </header>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-10">
            <div class="glass-card p-6 flex items-center gap-4">
                <div class="size-12 rounded-full bg-primary/10 flex items-center justify-center text-primary">
                    <span class="material-symbols-outlined text-2xl">badge</span>
                </div>
                <div>
                    <p class="text-[10px] font-black uppercase text-gray-400 tracking-widest">Total Staff</p>
                    <h3 class="text-2xl font-black italic uppercase"><?= $total_staff ?></h3>
                </div>
            </div>
            <div class="glass-card p-6 flex items-center gap-4">
                <div class="size-12 rounded-full bg-emerald-500/10 flex items-center justify-center text-emerald-500">
                    <span class="material-symbols-outlined text-2xl">group</span>
                </div>
                <div>
                    <p class="text-[10px] font-black uppercase text-gray-400 tracking-widest">Active Members</p>
                    <h3 class="text-2xl font-black italic uppercase"><?= $total_members ?></h3>
                </div>
            </div>
            <div class="glass-card p-6 flex items-center gap-4">
                <div class="size-12 rounded-full bg-amber-500/10 flex items-center justify-center text-amber-500">
                    <span class="material-symbols-outlined text-2xl">visibility</span>
                </div>
                <div>
                    <p class="text-[10px] font-black uppercase text-gray-400 tracking-widest">Page Views</p>
                    <h3 class="text-2xl font-black italic uppercase">1.2K</h3>
                </div>
            </div>
            <div class="glass-card p-6 flex items-center gap-4">
                <div class="size-12 rounded-full bg-blue-500/10 flex items-center justify-center text-blue-500">
                    <span class="material-symbols-outlined text-2xl">payments</span>
                </div>
                <div>
                    <p class="text-[10px] font-black uppercase text-gray-400 tracking-widest">Monthly Rev</p>
                    <h3 class="text-2xl font-black italic uppercase">₱0</h3>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
            <div class="glass-card p-8">
                <h4 class="font-black italic uppercase text-sm tracking-tighter mb-6">Digital Presence (CMS)</h4>
                <div class="p-6 rounded-2xl bg-background-dark border border-white/5 mb-6">
                    <div class="flex items-center gap-4 mb-4">
                        <div class="size-12 rounded-lg bg-surface-dark border border-white/10 flex items-center justify-center overflow-hidden">
                            <?php if($page && $page['logo_path']): ?>
                                <img src="../<?= htmlspecialchars($page['logo_path']) ?>" class="w-full h-full object-contain">
                            <?php else: ?>
                                <span class="material-symbols-outlined text-gray-700">image</span>
                            <?php endif; ?>
                        </div>
                        <div>
                            <p class="text-xs font-bold italic"><?= htmlspecialchars($page['page_title'] ?? $gym['gym_name']) ?></p>
                            <p class="text-[9px] font-black uppercase tracking-widest text-primary">Slug: p/<?= htmlspecialchars($page['page_slug']) ?></p>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <div class="size-4 rounded-full border border-white/10" style="background-color: <?= $page['theme_color'] ?? '#7f13ec' ?>"></div>
                        <span class="text-[10px] font-bold text-gray-500 uppercase tracking-tighter italic">Primary Theme Color</span>
                    </div>
                </div>
                <div class="flex gap-4">
                    <a href="tenant_settings.php" class="flex-1 h-12 rounded-xl bg-white/5 border border-white/10 hover:bg-white/10 transition-all flex items-center justify-center text-xs font-black uppercase tracking-widest gap-2">
                        <span class="material-symbols-outlined text-sm">edit</span> Customize Page
                    </a>
                    <a target="_blank" href="../portal.php?gym=<?= htmlspecialchars($page['page_slug']) ?>" class="flex-1 h-12 rounded-xl bg-primary hover:bg-primary-hover transition-all flex items-center justify-center text-xs font-black uppercase tracking-widest gap-2">
                        <span class="material-symbols-outlined text-sm">open_in_new</span> View Portal
                    </a>
                </div>
            </div>

            <div class="glass-card p-8 flex flex-col items-center justify-center text-center">
                <div class="size-20 rounded-full bg-white/5 flex items-center justify-center text-gray-700 mb-6 border border-white/5 border-dashed">
                    <span class="material-symbols-outlined text-4xl">inventory_2</span>
                </div>
                <h4 class="font-black italic uppercase text-sm tracking-tighter mb-2">Inventory Multi-Level</h4>
                <p class="text-xs text-gray-500 max-w-xs mb-8">Set up your gym services and membership plans to start accepting registrations.</p>
                <div class="flex gap-3 w-full max-w-sm">
                   <button class="flex-1 h-12 rounded-xl border border-white/10 hover:bg-white/5 transition-all text-[10px] font-black uppercase tracking-widest">Add Service</button>
                   <button class="flex-1 h-12 rounded-xl bg-white/5 border border-white/10 hover:bg-white/10 transition-all text-[10px] font-black uppercase tracking-widest">Plan Setup</button>
                </div>
            </div>
        </div>
    </main>
</div>

</body>
</html>
