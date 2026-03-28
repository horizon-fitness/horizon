<?php
session_start();
// Database connection commented out for UI preview
// require_once '../db.php';

// Mocked session data
$_SESSION['user_id'] = 1;
$_SESSION['gym_id'] = 1;
$_SESSION['role'] = 'tenant';

$gym_id = $_SESSION['gym_id'];
$active_page = 'reports';

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

// Mock Financial Transactions
$financials = [
    ['payment_id' => 1001, 'amount' => 1500.00, 'payment_method' => 'GCash', 'created_at' => date('Y-m-d', strtotime('-1 day'))],
    ['payment_id' => 1002, 'amount' => 2000.00, 'payment_method' => 'Bank Transfer', 'created_at' => date('Y-m-d', strtotime('-2 days'))],
    ['payment_id' => 1003, 'amount' => 1500.00, 'payment_method' => 'Cash', 'created_at' => date('Y-m-d', strtotime('-3 days'))]
];

// Mock Attendance Logs
$attendance = [
    ['first_name' => 'John', 'last_name' => 'Doe', 'check_in' => date('Y-m-d H:i:s', strtotime('-1 hour')), 'check_out' => null],
    ['first_name' => 'Jane', 'last_name' => 'Smith', 'check_in' => date('Y-m-d H:i:s', strtotime('-3 hours')), 'check_out' => date('Y-m-d H:i:s', strtotime('-2 hours'))],
    ['first_name' => 'Michael', 'last_name' => 'Brown', 'check_in' => date('Y-m-d H:i:s', strtotime('-5 hours')), 'check_out' => date('Y-m-d H:i:s', strtotime('-4 hours'))]
];

// Mock Membership Subscriptions
$subscriptions = [
    ['first_name' => 'Emily', 'last_name' => 'Davis', 'membership_type' => 'Premium', 'end_date' => date('Y-m-d', strtotime('+15 days')), 'member_status' => 'Active'],
    ['first_name' => 'Chris', 'last_name' => 'Wilson', 'membership_type' => 'Standard', 'end_date' => date('Y-m-d', strtotime('+20 days')), 'member_status' => 'Active'],
    ['first_name' => 'Sarah', 'last_name' => 'Connor', 'membership_type' => 'Elite', 'end_date' => date('Y-m-d', strtotime('+5 days')), 'member_status' => 'Active']
];

?>

<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8">
    <title>Reports & Analytics | Horizon</title>
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
        .report-tab-active { background: #8c2bee; color: white; box-shadow: 0 10px 20px -5px rgba(140, 43, 238, 0.4); }
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
                <h2 class="text-3xl font-black italic uppercase tracking-tighter">Analytics & <span class="text-primary">Reports</span></h2>
                <p class="text-gray-500 font-bold uppercase tracking-[0.3em] text-[10px] mt-2">Operational Insights & Engagement Analytics</p>
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

    <div class="glass-card overflow-hidden shadow-2xl min-h-[500px]">
        
        <div class="px-8 py-4 border-b border-white/5 flex bg-surface-dark/50">
            <div class="flex bg-surface-dark rounded-2xl p-1.5 border border-white/5">
                <button onclick="switchReport('financial')" id="btn-financial" class="report-tab-active px-6 py-2.5 rounded-xl font-black italic uppercase tracking-tighter text-[11px] transition-all">Financials</button>
                <button onclick="switchReport('attendance')" id="btn-attendance" class="text-gray-500 hover:text-white px-6 py-2.5 rounded-xl font-black italic uppercase tracking-tighter text-[11px] transition-all">Attendance</button>
                <button onclick="switchReport('membership')" id="btn-membership" class="text-gray-500 hover:text-white px-6 py-2.5 rounded-xl font-black italic uppercase tracking-tighter text-[11px] transition-all">Subscriptions</button>
            </div>
        </div>

        <div id="section-financial" class="report-section">
            <div class="px-8 py-6 border-b border-white/5 bg-white/[0.02] flex justify-between items-center">
                <h3 class="font-black italic uppercase text-xs tracking-widest">Recent Transactions</h3>
                <button class="text-[10px] font-black uppercase text-primary border border-primary/20 px-4 py-2 rounded-lg hover:bg-primary/10 transition-all">Export CSV</button>
            </div>
            <table class="w-full text-left">
                <thead class="text-[10px] font-black uppercase text-gray-500 tracking-widest border-b border-white/5">
                    <tr>
                        <th class="px-8 py-5">Ref ID</th>
                        <th class="px-8 py-5">Amount</th>
                        <th class="px-8 py-5">Method</th>
                        <th class="px-8 py-5">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5 text-sm font-medium">
                    <?php foreach($financials as $f): ?>
                    <tr class="hover:bg-white/[0.01]">
                        <td class="px-8 py-6 text-gray-400 font-mono">#<?= $f['payment_id'] ?></td>
                        <td class="px-8 py-6 font-black text-white italic">₱<?= number_format($f['amount'], 2) ?></td>
                        <td class="px-8 py-6 uppercase text-[10px] font-bold"><?= $f['payment_method'] ?></td>
                        <td class="px-8 py-6 text-gray-500"><?= date('M d, Y', strtotime($f['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div id="section-attendance" class="report-section hidden">
            <div class="px-8 py-6 border-b border-white/5 bg-white/[0.02] flex justify-between items-center">
                <h3 class="font-black italic uppercase text-xs tracking-widest text-emerald-500">Member Check-ins</h3>
            </div>
            <table class="w-full text-left">
                <thead class="text-[10px] font-black uppercase text-gray-500 tracking-widest border-b border-white/5">
                    <tr>
                        <th class="px-8 py-5">Member</th>
                        <th class="px-8 py-5">Check In</th>
                        <th class="px-8 py-5">Check Out</th>
                        <th class="px-8 py-5">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5 text-sm">
                    <?php foreach($attendance as $a): ?>
                    <tr class="hover:bg-white/[0.01]">
                        <td class="px-8 py-6 font-black italic uppercase tracking-tighter"><?= $a['first_name'] ?> <?= $a['last_name'] ?></td>
                        <td class="px-8 py-6 text-emerald-400 font-bold"><?= date('h:i A', strtotime($a['check_in'])) ?></td>
                        <td class="px-8 py-6 text-gray-500"><?= $a['check_out'] ? date('h:i A', strtotime($a['check_out'])) : '---' ?></td>
                        <td class="px-8 py-6"><span class="px-2 py-0.5 rounded bg-emerald-500/10 text-emerald-500 text-[9px] font-black uppercase">Present</span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div id="section-membership" class="report-section hidden">
            <div class="px-8 py-6 border-b border-white/5 bg-white/[0.02] flex justify-between items-center">
                <h3 class="font-black italic uppercase text-xs tracking-widest text-amber-500">Subscription Flow</h3>
            </div>
            <table class="w-full text-left">
                <thead class="text-[10px] font-black uppercase text-gray-500 tracking-widest border-b border-white/5">
                    <tr>
                        <th class="px-8 py-5">Member</th>
                        <th class="px-8 py-5">Tier</th>
                        <th class="px-8 py-5">Renewal Date</th>
                        <th class="px-8 py-5">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5 text-sm">
                    <?php foreach($subscriptions as $s): ?>
                    <tr class="hover:bg-white/[0.01]">
                        <td class="px-8 py-6 font-black italic uppercase tracking-tighter"><?= $s['first_name'] ?> <?= $s['last_name'] ?></td>
                        <td class="px-8 py-6 text-[10px] font-black uppercase text-gray-400 italic"><?= $s['membership_type'] ?></td>
                        <td class="px-8 py-6 text-gray-500 font-bold"><?= date('M d, Y', strtotime($s['end_date'] ?? '+1 month')) ?></td>
                        <td class="px-8 py-6">
                            <span class="px-3 py-1 rounded-full text-[8px] font-black uppercase border border-white/5 bg-white/5">
                                <?= $s['member_status'] ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    </div>
</main>

<script>
    function switchReport(type) {
        const sections = document.querySelectorAll('.report-section');
        sections.forEach(s => s.classList.add('hidden'));
        document.getElementById('section-' + type).classList.remove('hidden');

        const buttons = [document.getElementById('btn-financial'), document.getElementById('btn-attendance'), document.getElementById('btn-membership')];
        buttons.forEach(btn => {
            btn.classList.remove('report-tab-active', 'text-white');
            btn.classList.add('text-gray-500');
        });

        const activeBtn = document.getElementById('btn-' + type);
        activeBtn.classList.add('report-tab-active', 'text-white');
        activeBtn.classList.remove('text-gray-500');
    }
</script>

</body>
</html>
