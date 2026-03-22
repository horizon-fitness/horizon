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

        

        .no-scrollbar::-webkit-scrollbar { display: none; }

        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

    </style>

    <script>

        function updateSidebarClock() {

            const now = new Date();

            const timeString = now.toLocaleTimeString('en-US', { 

                hour: '2-digit', 

                minute: '2-digit', 

                second: '2-digit' 

            });

            const clockEl = document.getElementById('sidebarClock');

            if (clockEl) clockEl.textContent = timeString;

        }

        setInterval(updateSidebarClock, 1000);

        window.addEventListener('DOMContentLoaded', updateSidebarClock);

    </script>

</head>

<body class="antialiased flex h-screen overflow-hidden">



<nav class="flex flex-col w-64 lg:w-72 bg-[#0a090d] border-r border-white/5 sticky top-0 h-screen p-8 z-50 shrink-0">

    <div class="mb-12">

        <div class="flex items-center gap-3 mb-6">

            <div class="size-10 rounded-xl bg-primary flex items-center justify-center shadow-lg shrink-0 overflow-hidden">

                <?php if (!empty($page['logo_path'])): ?>

                    <img src="<?= $page['logo_path'] ?>" class="size-full object-contain">

                <?php else: ?>

                    <span class="material-symbols-outlined text-white text-2xl">bolt</span>

                <?php endif; ?>

            </div>

            <h1 class="text-lg font-black italic uppercase tracking-tighter text-white leading-none break-words line-clamp-2"><?= htmlspecialchars($gym['gym_name']) ?></h1>

        </div>

        <div class="p-4 rounded-2xl bg-white/5 border border-white/5">

            <div class="mb-2">

                <p id="sidebarClock" class="text-white font-black italic text-base leading-none">00:00:00 AM</p>

            </div>

            <p class="text-[9px] font-black uppercase text-gray-500 tracking-[0.2em] leading-none mb-1"><?= date('l, M d') ?></p>

            <div class="pt-2 border-t border-white/5 mt-2">

                <p class="text-[8px] font-black uppercase text-gray-600 tracking-widest mb-1">Current Plan</p>

                <div class="flex items-center justify-between">

                    <p class="text-[10px] font-black uppercase text-white italic tracking-tighter"><?= htmlspecialchars($sub['plan_name'] ?? 'Standard Plan') ?></p>

                    <span class="px-2 py-0.5 rounded-md bg-primary/20 text-primary text-[8px] font-black uppercase tracking-widest">Active</span>

                </div>

            </div>

        </div>

    </div>

    

    <div class="flex flex-col gap-5 flex-1 overflow-y-auto no-scrollbar pr-2">

        <a href="tenant_dashboard.php" class="nav-link flex items-center gap-3 <?= ($active_page == 'dashboard') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">

            <span class="material-symbols-outlined text-xl">grid_view</span> Dashboard

        </a>

        <a href="tenant_settings.php" class="nav-link flex items-center gap-3 <?= ($active_page == 'settings') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">

            <span class="material-symbols-outlined text-xl">palette</span> Page Customize

        </a>

        <a href="staff_management.php" class="nav-link flex items-center gap-3 text-gray-400 hover:text-white">

            <span class="material-symbols-outlined text-xl">group</span> Staff Management

        </a>



        <div class="pt-4 mt-2 border-t border-white/5 flex flex-col gap-5">

            <p class="text-[8px] font-black uppercase text-gray-600 tracking-[0.2em]">Data & Reports</p>

            <a href="my_users.php" class="nav-link flex items-center gap-3 text-gray-400 hover:text-white">

                <span class="material-symbols-outlined text-xl">person_search</span> My Users

            </a>

            <a href="reports.php" class="nav-link flex items-center gap-3 text-gray-400 hover:text-white">

                <span class="material-symbols-outlined text-xl">analytics</span> Reports

            </a>

            <a href="sales_reports.php" class="nav-link flex items-center gap-3 text-gray-400 hover:text-white">

                <span class="material-symbols-outlined text-xl">payments</span> Sales Reports

            </a>

        </div>

    </div>



    <div class="mt-auto pt-8 border-t border-white/10 flex flex-col gap-5">

        <a href="#" class="text-gray-400 hover:text-white transition-colors flex items-center gap-3 group">

            <span class="material-symbols-outlined transition-transform group-hover:text-primary">person</span>

            <span class="nav-link">Profile</span>

        </a>

        <a href="../logout.php" class="text-gray-400 hover:text-red-500 transition-colors flex items-center gap-3 group">

            <span class="material-symbols-outlined group-hover:translate-x-1 transition-transform">logout</span>

            <span class="nav-link">Sign Out</span>

        </a>

    </div>

</nav>



<div class="flex-1 p-10 max-w-[1400px] w-full mx-auto overflow-y-auto no-scrollbar">

    <header class="mb-10 flex justify-between items-end">

        <div>

            <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white">Owner <span class="text-primary">Dashboard</span></h2>

            <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-1">Industrial Brand Management</p>

        </div>

        <div class="flex gap-2">

            <a target="_blank" href="../portal.php?gym=<?= htmlspecialchars($page['page_slug'] ?? '') ?>" class="px-5 h-10 rounded-xl bg-white/5 border border-white/10 flex items-center gap-2 text-[10px] font-black uppercase tracking-widest hover:bg-white/10 transition-all">

                <span class="material-symbols-outlined text-sm">open_in_new</span> Full Web Portal

            </a>

        </div>

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

                <p class="text-[10px] font-black uppercase text-gray-400 tracking-widest">-</p>

                <h3 class="text-2xl font-black italic uppercase">-</h3>

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



    <div class="grid grid-cols-1 md:grid-cols-2 gap-8 pb-10">

        <div class="glass-card p-8">

            <h4 class="text-sm font-black italic uppercase tracking-tighter mb-6 flex items-center gap-2">

                <span class="material-symbols-outlined text-primary">palette</span> Page Customize

            </h4>

            <div class="p-6 rounded-2xl bg-background-dark border border-white/5 mb-6">

                <div class="flex items-center gap-4 mb-4">

                    <div class="size-12 rounded-lg bg-surface-dark border border-white/10 flex items-center justify-center overflow-hidden">

                        <?php 

                        $logo_src = $page['logo_path'] ?? '';

                        if ($logo_src) {

                            if (strpos($logo_src, 'data:') === 0) { } 

                            elseif (strpos($logo_src, 'uploads/') === 0) { $logo_src = '../' . $logo_src; }

                        }

                        ?>

                        <?php if($logo_src): ?>

                            <img src="<?= htmlspecialchars($logo_src) ?>" class="w-full h-full object-contain">

                        <?php else: ?>

                            <span class="material-symbols-outlined text-gray-700">image</span>

                        <?php endif; ?>

                    </div>

                    <div>

                        <p class="text-xs font-bold italic"><?= htmlspecialchars($page['page_title'] ?? $gym['gym_name']) ?></p>

                        <p class="text-[9px] font-black uppercase tracking-widest text-primary">Slug: p/<?= htmlspecialchars($page['page_slug'] ?? '') ?></p>

                    </div>

                </div>

                <div class="flex gap-2">

                    <div class="size-4 rounded-full border border-white/10" style="background-color: <?= $page['theme_color'] ?? '#7f13ec' ?>"></div>

                    <span class="text-[10px] font-bold text-gray-500 uppercase tracking-tighter italic">Primary Theme Color</span>

                </div>

            </div>

            <div class="flex gap-4">

                <a href="tenant_settings.php" class="flex-1 h-12 rounded-xl bg-white/5 border border-white/10 hover:bg-white/10 transition-all flex items-center justify-center text-[10px] font-black uppercase tracking-widest gap-2">

                    <span class="material-symbols-outlined text-sm">edit</span> Customize Page

                </a>

                <a target="_blank" href="../portal.php?gym=<?= htmlspecialchars($page['page_slug'] ?? '') ?>" class="flex-1 h-12 rounded-xl bg-primary hover:bg-primary-hover shadow-lg shadow-primary/20 transition-all flex items-center justify-center text-[10px] font-black uppercase tracking-widest gap-2">

                    <span class="material-symbols-outlined text-sm">open_in_new</span> View Portal

                </a>

            </div>

        </div>

        <div class="glass-card p-8">

            <h4 class="text-sm font-black italic uppercase tracking-tighter mb-6 flex items-center gap-2">

                <span class="material-symbols-outlined text-primary">bolt</span> Quick Actions

            </h4>

            <div class="grid grid-cols-1 gap-4">

                <a href="staff_management.php" class="group p-4 rounded-2xl bg-white/5 border border-white/5 hover:border-primary/50 transition-all flex items-center justify-between">

                    <div class="flex items-center gap-4">

                        <div class="size-12 rounded-xl bg-blue-500/10 flex items-center justify-center text-blue-500 group-hover:scale-110 transition-transform">

                            <span class="material-symbols-outlined">badge</span>

                        </div>

                        <div>

                            <p class="text-xs font-black uppercase italic">Add Staff</p>

                            <p class="text-[9px] text-gray-500 uppercase font-black">Manage your team</p>

                        </div>

                    </div>

                    <span class="material-symbols-outlined text-gray-600 group-hover:translate-x-1 transition-transform">arrow_forward_ios</span>

                </a>

            </div>

        </div>

    </div>



    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 pb-10">

        <div class="glass-card p-8">

            <div class="flex justify-between items-center mb-8">

                <h4 class="text-sm font-black italic uppercase tracking-tighter flex items-center gap-2">

                    <span class="material-symbols-outlined text-primary">analytics</span> Revenue Analytics

                </h4>

                <span class="text-[9px] font-black uppercase text-gray-500 tracking-widest italic">Last 30 Days</span>

            </div>

            <div class="h-48 rounded-2xl bg-background-dark border border-dashed border-white/5 flex items-center justify-center">

                <p class="text-[9px] font-black uppercase text-gray-700 italic tracking-widest">Revenue Data Visualization</p>

            </div>

        </div>



        <div class="glass-card p-8">

            <div class="flex justify-between items-center mb-8">

                <h4 class="text-sm font-black italic uppercase tracking-tighter flex items-center gap-2">

                    <span class="material-symbols-outlined text-primary">monitoring</span> Member Growth

                </h4>

                <span class="text-[9px] font-black uppercase text-gray-500 tracking-widest italic">Monthly Onboarding</span>

            </div>

            <div class="h-48 rounded-2xl bg-background-dark border border-dashed border-white/5 flex items-center justify-center">

                <p class="text-[9px] font-black uppercase text-gray-700 italic tracking-widest">Growth Data Visualization</p>

            </div>

        </div>

    </div>

</div>



</body>

</html>