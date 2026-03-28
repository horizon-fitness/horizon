<?php
session_start();
// Database connection completely removed for UI preview
// require_once '../db.php';

// Mocked session data for UI preview
$_SESSION['user_id'] = 1;
$_SESSION['gym_id'] = 1;
$_SESSION['role'] = 'tenant';

$gym_id = $_SESSION['gym_id'];
$active_page = 'users';

// Mock Gym Details
$gym = [
    'gym_name' => 'CORSANO FITNESS'
];

// Mock Subscription
$sub = [
    'plan_name' => 'Legacy Plan'
];

// Mock CMS Page
$page = [
    'logo_path' => ''
];

$active_tab = $_GET['tab'] ?? 'members';

// Mock Coaches
$coaches = [
    ['user_id' => 101, 'first_name' => 'Alex', 'last_name' => 'Rivera', 'email' => 'alex@example.com', 'staff_role' => 'Head Coach', 'staff_status' => 'Active'],
    ['user_id' => 102, 'first_name' => 'Samantha', 'last_name' => 'Lee', 'email' => 'sam@example.com', 'staff_role' => 'Yoga Instructor', 'staff_status' => 'Active'],
    ['user_id' => 103, 'first_name' => 'Marcus', 'last_name' => 'Chen', 'email' => 'marcus@example.com', 'staff_role' => 'Strength Coach', 'staff_status' => 'Active'],
    ['user_id' => 104, 'first_name' => 'Elena', 'last_name' => 'Rodriguez', 'email' => 'elena@example.com', 'staff_role' => 'Pilates Expert', 'staff_status' => 'Active'],
    ['user_id' => 105, 'first_name' => 'David', 'last_name' => 'Kim', 'email' => 'david@example.com', 'staff_role' => 'Nutritionist', 'staff_status' => 'Active'],
    ['user_id' => 106, 'first_name' => 'Sophia', 'last_name' => 'Wang', 'email' => 'sophia@example.com', 'staff_role' => 'Zumba Coach', 'staff_status' => 'Active'],
    ['user_id' => 107, 'first_name' => 'James', 'last_name' => 'Wilson', 'email' => 'james@example.com', 'staff_role' => 'Boxing Trainer', 'staff_status' => 'Active']
];

// Mock Members
$members = [
    ['user_id' => 201, 'first_name' => 'John', 'last_name' => 'Doe', 'email' => 'john@example.com', 'membership_type' => 'Premium', 'member_status' => 'Active'],
    ['user_id' => 202, 'first_name' => 'Jane', 'last_name' => 'Smith', 'email' => 'jane@example.com', 'membership_type' => 'Standard', 'member_status' => 'Active'],
    ['user_id' => 203, 'first_name' => 'Michael', 'last_name' => 'Brown', 'email' => 'mike@example.com', 'membership_type' => 'Elite', 'member_status' => 'Inactive'],
    ['user_id' => 204, 'first_name' => 'Emily', 'last_name' => 'Davis', 'email' => 'emily@example.com', 'membership_type' => 'Standard', 'member_status' => 'Active'],
    ['user_id' => 205, 'first_name' => 'Chris', 'last_name' => 'Wilson', 'email' => 'chris@example.com', 'membership_type' => 'Premium', 'member_status' => 'Active'],
    ['user_id' => 206, 'first_name' => 'Sarah', 'last_name' => 'Miller', 'email' => 'sarah@example.com', 'membership_type' => 'Standard', 'member_status' => 'Active'],
    ['user_id' => 207, 'first_name' => 'Robert', 'last_name' => 'Taylor', 'email' => 'robert@example.com', 'membership_type' => 'Elite', 'member_status' => 'Active'],
    ['user_id' => 208, 'first_name' => 'Jessica', 'last_name' => 'Anderson', 'email' => 'jess@example.com', 'membership_type' => 'Premium', 'member_status' => 'Active'],
    ['user_id' => 209, 'first_name' => 'William', 'last_name' => 'Thomas', 'email' => 'will@example.com', 'membership_type' => 'Standard', 'member_status' => 'Inactive'],
    ['user_id' => 210, 'first_name' => 'Linda', 'last_name' => 'Jackson', 'email' => 'linda@example.com', 'membership_type' => 'Standard', 'member_status' => 'Active'],
    ['user_id' => 211, 'first_name' => 'Daniel', 'last_name' => 'White', 'email' => 'daniel@example.com', 'membership_type' => 'Premium', 'member_status' => 'Active'],
    ['user_id' => 212, 'first_name' => 'Ashley', 'last_name' => 'Harris', 'email' => 'ashley@example.com', 'membership_type' => 'Standard', 'member_status' => 'Active'],
    ['user_id' => 213, 'first_name' => 'Joseph', 'last_name' => 'Martin', 'email' => 'joseph@example.com', 'membership_type' => 'Elite', 'member_status' => 'Active']
];
?>

<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8">
    <title>User Management | Horizon</title>
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
                <h2 class="text-3xl font-black italic uppercase tracking-tighter">My <span class="text-primary">Users</span></h2>
                <p class="text-gray-500 font-bold uppercase tracking-[0.3em] text-[10px] mt-2">Manage your community roster</p>
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

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">
        <div class="glass-card p-8 relative overflow-hidden group">
            <div class="absolute -right-4 -top-4 size-24 bg-primary/10 rounded-full blur-3xl group-hover:bg-primary/20 transition-all"></div>
            <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Total Community</p>
            <h3 class="text-4xl font-black italic tracking-tighter"><?= count($members) + count($coaches) ?></h3>
        </div>
        <div class="glass-card p-8">
            <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Active Members</p>
            <h3 class="text-4xl font-black italic tracking-tighter text-emerald-500"><?= count($members) ?></h3>
        </div>
        <div class="glass-card p-8">
            <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Expert Coaches</p>
            <h3 class="text-4xl font-black italic tracking-tighter text-primary"><?= count($coaches) ?></h3>
        </div>
    </div>

    <div class="glass-card overflow-hidden shadow-2xl">
        
        <div class="px-8 py-6 border-b border-white/5 flex items-center justify-between">
             <div class="flex bg-black/40 rounded-xl p-1 border border-white/5">
                <button onclick="switchTab('members')" id="tab-btn-members" class="px-6 py-2 rounded-lg font-black italic uppercase tracking-tighter text-[10px] transition-all <?= $active_tab == 'members' ? 'bg-primary text-white shadow-lg' : 'text-gray-500 hover:text-white' ?>">Members</button>
                <button onclick="switchTab('coaches')" id="tab-btn-coaches" class="px-6 py-2 rounded-lg font-black italic uppercase tracking-tighter text-[10px] transition-all <?= $active_tab == 'coaches' ? 'bg-primary text-white shadow-lg' : 'text-gray-400 hover:text-white' ?>">Coaches</button>
            </div>
            <p class="text-[10px] font-black uppercase text-gray-500 tracking-[0.2em] italic">Community Roster</p>
        </div>

        <div id="tab-members" class="<?= $active_tab == 'members' ? '' : 'hidden' ?>">
            <table class="w-full text-left">
                <thead class="bg-black/20 border-b border-white/5">
                    <tr class="text-[10px] font-black uppercase tracking-widest text-gray-500">
                        <th class="px-8 py-5">Member Name</th>
                        <th class="px-8 py-5">Plan</th>
                        <th class="px-8 py-5">Status</th>
                        <th class="px-8 py-5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php foreach($members as $m): ?>
                    <tr class="hover:bg-white/[0.02] transition-all group">
                        <td class="px-8 py-6 flex items-center gap-4">
                            <div class="size-10 rounded-full bg-primary/20 border border-primary/30 flex items-center justify-center text-primary font-black italic text-xs">
                                <?= strtoupper(substr($m['first_name'] ?? 'M', 0, 1)) ?>
                            </div>
                            <div>
                                <p class="font-black italic uppercase tracking-tighter text-sm"><?= htmlspecialchars(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')) ?></p>
                                <p class="text-[10px] text-gray-500 font-bold"><?= htmlspecialchars($m['email'] ?? '') ?></p>
                            </div>
                        </td>
                        <td class="px-8 py-6">
                            <span class="text-[10px] font-black uppercase italic tracking-tighter text-gray-400"><?= $m['membership_type'] ?? 'Standard' ?></span>
                        </td>
                        <td class="px-8 py-6">
                            <span class="px-3 py-1 rounded-full text-[8px] font-black uppercase tracking-widest border <?= ($m['member_status'] ?? 'Active') == 'Active' ? 'bg-emerald-500/10 text-emerald-500 border-emerald-500/20' : 'bg-red-500/10 text-red-500 border-red-500/20' ?>">
                                <?= $m['member_status'] ?? 'Active' ?>
                            </span>
                        </td>
                        <td class="px-8 py-6 text-right">
                            <button class="size-8 rounded-lg bg-white/5 hover:bg-primary transition-all text-gray-500 hover:text-white">
                                <span class="material-symbols-outlined text-sm">visibility</span>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div id="tab-coaches" class="<?= $active_tab == 'coaches' ? '' : 'hidden' ?>">
            <table class="w-full text-left">
                <thead class="bg-black/20 border-b border-white/5">
                    <tr class="text-[10px] font-black uppercase tracking-widest text-gray-500">
                        <th class="px-8 py-5">Coach Name</th>
                        <th class="px-8 py-5">Specialization</th>
                        <th class="px-8 py-5">System Role</th>
                        <th class="px-8 py-5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php foreach($coaches as $c): ?>
                    <tr class="hover:bg-white/[0.02] transition-all group">
                        <td class="px-8 py-6 flex items-center gap-4">
                            <div class="size-10 rounded-full bg-amber-500/20 border border-amber-500/30 flex items-center justify-center text-amber-500 font-black italic text-xs">
                                <?= strtoupper(substr($c['first_name'] ?? 'C', 0, 1)) ?>
                            </div>
                            <div>
                                <p class="font-black italic uppercase tracking-tighter text-sm"><?= htmlspecialchars(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')) ?></p>
                                <p class="text-[10px] text-gray-500 font-bold italic tracking-widest">VERIFIED COACH</p>
                            </div>
                        </td>
                        <td class="px-8 py-6">
                            <span class="text-[10px] font-black uppercase italic tracking-tighter text-gray-400"><?= $c['staff_role'] ?></span>
                        </td>
                        <td class="px-8 py-6">
                            <span class="px-3 py-1 rounded-full text-[8px] font-black uppercase tracking-widest border bg-primary/10 text-primary border-primary/20">
                                Staff Member
                            </span>
                        </td>
                        <td class="px-8 py-6 text-right">
                            <button class="size-8 rounded-lg bg-white/5 hover:bg-primary transition-all text-gray-500 hover:text-white">
                                <span class="material-symbols-outlined text-sm">edit</span>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    </div>
</main>

<script>
    function switchTab(tabName) {
        const memberTab = document.getElementById('tab-members');
        const coachTab = document.getElementById('tab-coaches');
        const memberBtn = document.getElementById('tab-btn-members');
        const coachBtn = document.getElementById('tab-btn-coaches');

        if (tabName === 'members') {
            memberTab.classList.remove('hidden');
            coachTab.classList.add('hidden');
            memberBtn.classList.add('bg-primary', 'text-white', 'shadow-lg');
            memberBtn.classList.remove('text-gray-500');
            coachBtn.classList.remove('bg-primary', 'text-white', 'shadow-lg');
            coachBtn.classList.add('text-gray-500');
        } else {
            coachTab.classList.remove('hidden');
            memberTab.classList.add('hidden');
            coachBtn.classList.add('bg-primary', 'text-white', 'shadow-lg');
            coachBtn.classList.remove('text-gray-500');
            memberBtn.classList.remove('bg-primary', 'text-white', 'shadow-lg');
            memberBtn.classList.add('text-gray-500');
        }
    }
</script>

</body>
</html>
