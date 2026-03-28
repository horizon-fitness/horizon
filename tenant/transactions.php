<?php
session_start();
// Database connection commented out for UI preview
// require_once '../db.php';

// Mocked session data
$_SESSION['user_id'] = 1;
$_SESSION['gym_id'] = 1;
$_SESSION['role'] = 'tenant';

$gym_id = $_SESSION['gym_id'];
$active_page = 'transactions';

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

// Mock Financial Calculations
$total_revenue = 125450.00;
$monthly_sales = 45200.00;
$daily_sales = 2850.00;

// Mock Transactions
$transactions = [
    ['payment_id' => 1001, 'first_name' => 'John', 'last_name' => 'Doe', 'amount' => 1500.00, 'payment_method' => 'GCash', 'created_at' => date('Y-m-d H:i:s', strtotime('-1 hour'))],
    ['payment_id' => 1002, 'first_name' => 'Jane', 'last_name' => 'Smith', 'amount' => 2000.00, 'payment_method' => 'Bank Transfer', 'created_at' => date('Y-m-d H:i:s', strtotime('-3 hours'))],
    ['payment_id' => 1003, 'first_name' => 'Michael', 'last_name' => 'Brown', 'amount' => 1500.00, 'payment_method' => 'Cash', 'created_at' => date('Y-m-d H:i:s', strtotime('-5 hours'))],
    ['payment_id' => 1004, 'first_name' => 'Emily', 'last_name' => 'Davis', 'amount' => 3000.00, 'payment_method' => 'GCash', 'created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))],
    ['payment_id' => 1005, 'first_name' => 'Chris', 'last_name' => 'Wilson', 'amount' => 1500.00, 'payment_method' => 'Cash', 'created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))],
    ['payment_id' => 1006, 'first_name' => 'Sarah', 'last_name' => 'Connor', 'amount' => 2500.00, 'payment_method' => 'Bank Transfer', 'created_at' => date('Y-m-d H:i:s', strtotime('-2 days'))]
];

?>

<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8">
    <title>Sales Intelligence | Horizon</title>
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

        /* Filter Specific Styling */
        .filter-input {
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 12px;
            padding: 8px 12px;
            font-size: 11px;
            font-weight: 600;
            color: white;
            outline: none;
            transition: all 0.2s;
        }
        .filter-input:focus {
            border-color: #8c2bee;
            background: rgba(140, 43, 238, 0.05);
        }
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

<main class="flex-1 overflow-y-auto no-scrollbar p-10">
    <header class="mb-10 flex flex-col md:flex-row md:items-end justify-between gap-6">
        <div class="flex flex-col md:flex-row md:items-end justify-between w-full">
            <div>
                <h2 class="text-3xl font-black italic uppercase tracking-tighter">Transaction <span class="text-primary">History</span></h2>
                <p class="text-gray-500 font-bold uppercase tracking-[0.3em] text-[10px] mt-2">Monitor financial activities</p>
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

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-10">
        <div class="glass-card p-8">
            <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Total Revenue</p>
            <h3 class="text-3xl font-black italic tracking-tighter text-white">₱<?= number_format($total_revenue, 2) ?></h3>
        </div>
        <div class="glass-card p-8 border-l-4 border-primary">
            <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Monthly Sales</p>
            <h3 class="text-3xl font-black italic tracking-tighter text-primary">₱<?= number_format($monthly_sales, 2) ?></h3>
        </div>
        <div class="glass-card p-8">
            <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Weekly Forecast</p>
            <h3 class="text-3xl font-black italic tracking-tighter text-white">₱<?= number_format($monthly_sales / 4, 2) ?></h3>
        </div>
        <div class="glass-card p-8 border-l-4 border-emerald-500">
            <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Daily Revenue</p>
            <h3 class="text-3xl font-black italic tracking-tighter text-emerald-500">₱<?= number_format($daily_sales, 2) ?></h3>
        </div>
    </div>

    <div class="glass-card p-6 mb-8">
        <form class="flex flex-wrap items-center gap-4">
            <div class="flex flex-col gap-1.5">
                <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Date</label>
                <input type="date" class="filter-input w-44">
            </div>
            <div class="flex flex-col gap-1.5">
                <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Month</label>
                <select class="filter-input w-40">
                    <option value="">All Months</option>
                    <option value="01">January</option>
                    <option value="02">February</option>
                    <option value="03">March</option>
                    <option value="04">April</option>
                    <option value="05">May</option>
                    <option value="06">June</option>
                    <option value="07">July</option>
                    <option value="08">August</option>
                    <option value="09">September</option>
                    <option value="10">October</option>
                    <option value="11">November</option>
                    <option value="12">December</option>
                </select>
            </div>
            <div class="flex flex-col gap-1.5">
                <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Year</label>
                <select class="filter-input w-28">
                    <option value="2026">2026</option>
                    <option value="2025">2025</option>
                    <option value="2024">2024</option>
                </select>
            </div>
            <button type="submit" class="mt-5 bg-primary/10 text-primary border border-primary/20 px-6 py-2 rounded-xl font-black italic uppercase tracking-tighter text-[10px] hover:bg-primary hover:text-white transition-all">
                Filter Results
            </button>
        </form>
    </div>

    <div class="glass-card overflow-hidden shadow-2xl">
        <div class="px-8 py-6 border-b border-white/5 flex justify-between items-center bg-white/[0.02]">
            <h4 class="font-black italic uppercase text-xs tracking-widest flex items-center gap-2">
                <span class="material-symbols-outlined text-primary">receipt_long</span> Transaction History
            </h4>
            <div class="text-[10px] font-bold text-gray-500 italic uppercase">Showing last 50 entries</div>
        </div>
        
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="text-[10px] font-black uppercase tracking-widest text-gray-500 border-b border-white/5">
                        <th class="px-8 py-5">Transaction ID</th>
                        <th class="px-8 py-5">Customer / Member</th>
                        <th class="px-8 py-5">Amount</th>
                        <th class="px-8 py-5">Method</th>
                        <th class="px-8 py-5">Timestamp</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php if(empty($transactions)): ?>
                        <tr><td colspan="5" class="px-8 py-20 text-center text-gray-600 font-black italic uppercase">No financial data recorded yet.</td></tr>
                    <?php else: ?>
                        <?php foreach($transactions as $t): ?>
                        <tr class="hover:bg-white/[0.02] transition-all group">
                            <td class="px-8 py-6 text-xs font-mono text-gray-400">#TRX-<?= str_pad($t['payment_id'], 5, '0', STR_PAD_LEFT) ?></td>
                            <td class="px-8 py-6">
                                <p class="font-black italic uppercase tracking-tighter text-sm">
                                    <?= isset($t['first_name']) ? htmlspecialchars($t['first_name'].' '.$t['last_name']) : 'Guest Transaction' ?>
                                </p>
                            </td>
                            <td class="px-8 py-6">
                                <span class="text-sm font-black italic text-emerald-400">₱<?= number_format($t['amount'], 2) ?></span>
                            </td>
                            <td class="px-8 py-6">
                                <span class="text-[9px] font-black uppercase px-3 py-1 rounded bg-white/5 border border-white/10 text-gray-400">
                                    <?= $t['payment_method'] ?>
                                </span>
                            </td>
                            <td class="px-8 py-6 text-[10px] font-bold text-gray-500 uppercase">
                                <?= date('M d, Y | h:i A', strtotime($t['created_at'])) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

</body>
</html>
