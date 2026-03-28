<?php
session_start();
// Database connection commented out for UI preview
// require_once '../db.php';

// Mocked session data
$_SESSION['user_id'] = 1;
$_SESSION['gym_id'] = 1;
$_SESSION['role'] = 'tenant';

$gym_id = $_SESSION['gym_id'];
$user_id = $_SESSION['user_id'];
$active_page = "dashboard";

// Mock Gym Details
$gym = [
    'gym_name' => 'HERDOZA FITNESS'
];

// Mock Subscription
$sub = [
    'plan_name' => 'Legacy Plan'
];

// Mock CMS Page
$page = [
    'logo_path'   => '',
    'page_slug'   => 'herdozafitness',
    'page_title'  => 'HERDOZA FITNESS',
    'theme_color' => '#8c2bee'
];

// Mock Stats
$total_staff   = 12;
$total_members = 148;

$page_title = "Owner Dashboard";
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

    </style>

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

<div class="flex-1 p-10 overflow-y-auto no-scrollbar">

    <header class="mb-10 flex justify-between items-end">

        <div>

            <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white">Owner <span class="text-primary">Dashboard</span></h2>

            <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-1">Industrial Brand Management</p>

        </div>

        <div class="flex items-end gap-8">
            <div class="flex flex-col items-end">
                <p id="topClock" class="text-white font-black italic text-2xl leading-none">00:00:00 AM</p>
                <p class="text-[10px] font-black uppercase text-gray-500 tracking-[0.2em] leading-none mt-2"><?= date('l, M d, Y') ?></p>
                <div class="flex items-center gap-2 mt-2 px-3 py-1 rounded-lg bg-white/5 border border-white/5">
                    <p class="text-[9px] font-black uppercase text-gray-400 tracking-widest">Plan:</p>
                    <p class="text-[10px] font-black uppercase text-white italic tracking-tighter"><?= htmlspecialchars($sub['plan_name'] ?? 'Standard Plan') ?></p>
                    <span class="px-1.5 py-0.5 rounded-md bg-primary/20 text-primary text-[7px] font-black uppercase tracking-widest">Active</span>
                </div>
            </div>

            <a target="_blank" href="../portal.php?gym=<?= htmlspecialchars($page['page_slug'] ?? '') ?>" class="px-5 h-12 rounded-xl bg-white/5 border border-white/10 flex items-center gap-2 text-[10px] font-black uppercase tracking-widest hover:bg-white/10 transition-all">

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

                <a href="staff.php" class="group p-4 rounded-2xl bg-white/5 border border-white/5 hover:border-primary/50 transition-all flex items-center justify-between">

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