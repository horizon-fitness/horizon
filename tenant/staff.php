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
$active_page = "staff";

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
    'logo_path' => ''
];

// Mock Staff Members
$staff_members = [
    ['staff_id' => 1, 'first_name' => 'Alex', 'last_name' => 'Rivera', 'staff_role' => 'Head Coach', 'employment_type' => 'FULL-TIME', 'status' => 'Active', 'created_at' => '2023-01-15'],
    ['staff_id' => 2, 'first_name' => 'Samantha', 'last_name' => 'Lee', 'staff_role' => 'Yoga Instructor', 'employment_type' => 'PART-TIME', 'status' => 'Active', 'created_at' => '2023-02-20'],
    ['staff_id' => 3, 'first_name' => 'Marcus', 'last_name' => 'Chen', 'staff_role' => 'Strength Coach', 'employment_type' => 'FULL-TIME', 'status' => 'Active', 'created_at' => '2023-03-10'],
    ['staff_id' => 4, 'first_name' => 'Jessica', 'last_name' => 'Taylor', 'staff_role' => 'Front Desk', 'employment_type' => 'FULL-TIME', 'status' => 'Active', 'created_at' => '2023-05-05'],
    ['staff_id' => 5, 'first_name' => 'David', 'last_name' => 'Miller', 'staff_role' => 'Personal Trainer', 'employment_type' => 'PART-TIME', 'status' => 'Inactive', 'created_at' => '2023-06-12']
];

$total_staff = count($staff_members);
$active_staff = 0;
foreach($staff_members as $s) {
    if($s['status'] === 'Active') $active_staff++;
}
?>

<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Staff Management | Horizon Tenant</title>
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
</head>
<body class="flex h-screen overflow-hidden">

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
    <header class="mb-10 flex flex-col md:flex-row md:items-end justify-between gap-6">
        <div class="flex flex-col md:flex-row md:items-end justify-between w-full">
            <div>
                <h2 class="text-3xl font-black italic uppercase tracking-tighter text-primary">Staff <span class="text-white">Management</span></h2>
                <p class="text-gray-500 font-bold uppercase tracking-[0.3em] text-[10px] mt-2">Manage gym personnel & accounts</p>
            </div>
            <div class="flex flex-col items-end mt-4 md:mt-0">
                <p id="topClock" class="text-white font-black italic text-2xl leading-none">00:00:00 AM</p>
                <p class="text-[10px] font-black uppercase text-gray-500 tracking-[0.2em] leading-none mt-2"><?= date('l, M d, Y') ?></p>
                <div class="flex items-center gap-2 mt-2 px-3 py-1 rounded-lg bg-white/5 border border-white/5">
                    <p class="text-[9px] font-black uppercase text-gray-400 tracking-widest">Plan:</p>
                    <p class="text-[10px] font-black uppercase text-white italic tracking-tighter"><?= htmlspecialchars($sub['plan_name'] ?? 'Standard Plan') ?></p>
                    <span class="px-1.5 py-0.5 rounded-md bg-primary/20 text-primary text-[7px] font-black uppercase tracking-widest">Active</span>
                </div>
            </div>
        </div>
    </header>

    <header class="mb-10 flex flex-col md:flex-row md:items-end justify-between gap-6">
        <div class="flex items-center gap-4 ml-auto">
            <div class="relative group">
                <span class="absolute left-4 top-1/2 -translate-y-1/2 material-symbols-outlined text-gray-500 text-sm group-focus-within:text-primary transition-colors">search</span>
                <input type="text" placeholder="Search staff..." class="bg-surface-dark border border-white/5 rounded-xl py-3 pl-12 pr-6 text-xs font-bold w-64 focus:border-primary/50 outline-none transition-all">
            </div>
            <a href="add_staff.php" class="bg-primary hover:bg-opacity-90 text-white px-6 py-3 rounded-xl flex items-center gap-2 text-[10px] font-black uppercase tracking-widest shadow-lg shadow-primary/20 transition-all">
                <span class="material-symbols-outlined text-sm">person_add</span> Add Staff
            </a>
        </div>
    </header>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-10">
        <div class="glass-card p-8 flex items-center gap-6 group hover:border-primary/30 transition-all">
            <div class="size-16 rounded-2xl bg-primary/10 flex items-center justify-center text-primary group-hover:scale-110 transition-transform">
                <span class="material-symbols-outlined text-3xl">shield_person</span>
            </div>
            <div>
                <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Total Staff</p>
                <h3 class="text-4xl font-black italic tracking-tighter"><?= $total_staff ?></h3>
            </div>
        </div>
        <div class="glass-card p-8 flex items-center gap-6 group hover:border-emerald-500/30 transition-all">
            <div class="size-16 rounded-2xl bg-emerald-500/10 flex items-center justify-center text-emerald-500 group-hover:scale-110 transition-transform">
                <span class="material-symbols-outlined text-3xl">person_check</span>
            </div>
            <div>
                <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Active Personnel</p>
                <h3 class="text-4xl font-black italic tracking-tighter text-emerald-500"><?= $active_staff ?></h3>
            </div>
        </div>
    </div>

    <div class="glass-card overflow-hidden shadow-2xl">
        <div class="px-8 py-6 border-b border-white/5 bg-white/[0.02] flex items-center gap-3">
            <span class="material-symbols-outlined text-primary">groups</span>
            <h4 class="font-black italic uppercase text-xs tracking-widest">Personnel Roster</h4>
        </div>
        
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="text-[10px] font-black uppercase tracking-widest text-gray-500 border-b border-white/5">
                        <th class="px-8 py-5">Staff Member</th>
                        <th class="px-8 py-5">Assigned Role</th>
                        <th class="px-8 py-5">Employment Type</th>
                        <th class="px-8 py-5 text-center">Status</th>
                        <th class="px-8 py-5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php foreach($staff_members as $s): ?>
                    <tr class="hover:bg-white/[0.02] transition-all group">
                        <td class="px-8 py-6 flex items-center gap-4">
                            <div class="size-12 rounded-xl bg-surface-dark border border-white/5 flex items-center justify-center text-primary font-black italic text-sm group-hover:border-primary/30 transition-all">
                                <?= strtoupper(substr($s['first_name'], 0, 1)) . strtoupper(substr($s['last_name'], 0, 1)) ?>
                            </div>
                            <div>
                                <p class="font-black italic uppercase tracking-tighter text-sm"><?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?></p>
                                <p class="text-[10px] text-gray-500 font-bold uppercase tracking-widest">ID: <?= str_pad($s['staff_id'], 4, '0', STR_PAD_LEFT) ?></p>
                            </div>
                        </td>
                        <td class="px-8 py-6">
                            <span class="px-3 py-1 rounded bg-surface-dark border border-white/5 text-[9px] font-black uppercase tracking-widest text-gray-400 group-hover:text-primary transition-all"><?= $s['staff_role'] ?></span>
                        </td>
                        <td class="px-8 py-6">
                            <p class="text-[10px] font-black uppercase text-white tracking-tighter italic"><?= $s['employment_type'] ?? 'FULL-TIME' ?></p>
                            <p class="text-[9px] text-gray-600 font-bold uppercase">Hired: <?= date('M d, Y', strtotime($s['created_at'])) ?></p>
                        </td>
                        <td class="px-8 py-6 text-center">
                            <span class="px-3 py-1 rounded text-[9px] font-black uppercase tracking-widest <?= $s['status'] === 'Active' ? 'text-emerald-500 bg-emerald-500/10 border border-emerald-500/20' : 'text-red-500 bg-red-500/10 border border-red-500/20' ?>">
                                <?= $s['status'] ?>
                            </span>
                        </td>
                        <td class="px-8 py-6 text-right">
                            <div class="flex justify-end gap-2">
                                <button class="size-9 rounded-lg bg-surface-dark border border-white/5 text-gray-500 hover:text-white hover:border-primary/50 transition-all flex items-center justify-center">
                                    <span class="material-symbols-outlined text-sm">edit</span>
                                </button>
                                <button class="size-9 rounded-lg bg-surface-dark border border-white/5 text-gray-500 hover:text-amber-500 hover:border-amber-500/50 transition-all flex items-center justify-center">
                                    <span class="material-symbols-outlined text-sm">settings</span>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

</body>
</html>
