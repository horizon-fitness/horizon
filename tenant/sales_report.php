<?php
session_start();
require_once '../db.php';

// Security Check
$role = strtolower($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || !in_array($role, ['tenant', 'admin'])) {
    header("Location: ../login.php");
    exit;
}

$gym_id = $_SESSION['gym_id'];

// --- FINANCIAL CALCULATIONS ---
// Total Revenue (Lifetime)
$stmtTotal = $pdo->prepare("SELECT SUM(amount) as total FROM payments WHERE gym_id = ?");
$stmtTotal->execute([$gym_id]);
$total_revenue = $stmtTotal->fetchColumn() ?? 0;

// Monthly Sales (Current Month)
$stmtMonthly = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE gym_id = ? AND MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())");
$stmtMonthly->execute([$gym_id]);
$monthly_sales = $stmtMonthly->fetchColumn() ?? 0;

// Daily Sales (Today)
$stmtDaily = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE gym_id = ? AND DATE(created_at) = CURDATE()");
$stmtDaily->execute([$gym_id]);
$daily_sales = $stmtDaily->fetchColumn() ?? 0;

// Transaction History Summary (Latest 50)
$stmtHistory = $pdo->prepare("
    SELECT p.*, u.first_name, u.last_name 
    FROM payments p 
    LEFT JOIN client_subscriptions cs ON p.client_subscription_id = cs.client_subscription_id
    LEFT JOIN users u ON cs.owner_user_id = u.user_id 
    WHERE p.gym_id = ? 
    ORDER BY p.created_at DESC LIMIT 50
");
$stmtHistory->execute([$gym_id]);
$transactions = $stmtHistory->fetchAll();

// Sidebar Info
$stmtGym = $pdo->prepare("SELECT * FROM gyms WHERE gym_id = ?");
$stmtGym->execute([$gym_id]);
$gym = $stmtGym->fetch();

// Check Subscription
$stmtSub = $pdo->prepare("SELECT ws.plan_name FROM client_subscriptions cs JOIN website_plans ws ON cs.website_plan_id = ws.website_plan_id WHERE cs.gym_id = ? AND cs.subscription_status = 'Active' LIMIT 1");
$stmtSub->execute([$gym_id]);
$sub = $stmtSub->fetch();

$active_page = "sales";
?>

<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8">
    <title>Sales Intelligence | Horizon</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;700;900&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "#8c2bee", "bg-dark": "#050505", "card-dark": "#121212" }}}
        }
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; overflow: hidden; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        .nav-link { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; transition: all 0.2s; white-space: nowrap; }
        .active-nav { color: #8c2bee !important; position: relative; }
        .active-nav::after { content: ''; position: absolute; right: -32px; top: 50%; transform: translateY(-50%); width: 4px; height: 20px; background: #8c2bee; border-radius: 99px; }
        
        .sidebar-nav { width: 85px; transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1); overflow: hidden; display: flex; flex-direction: column; }
        .sidebar-nav:hover { width: 300px; }
        .nav-text { opacity: 0; transform: translateX(-15px); transition: all 0.3s ease-in-out; white-space: nowrap; pointer-events: none; }
        .sidebar-nav:hover .nav-text { opacity: 1; transform: translateX(0); pointer-events: auto; }

        .nav-section-header { max-height: 0; opacity: 0; overflow: hidden; transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); margin: 0 !important; pointer-events: none; }
        .sidebar-nav:hover .nav-section-header { max-height: 20px; opacity: 1; margin-bottom: 8px !important; pointer-events: auto; }
        
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        .stat-card { background: linear-gradient(145deg, #121212 0%, #0a0a0a 100%); border: 1px solid rgba(255,255,255,0.05); }

        /* Filter Styles */
        .filter-input { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 10px 14px; font-size: 11px; font-weight: 700; color: white; outline: none; transition: all 0.2s; text-transform: uppercase; }
        .filter-input:focus { border-color: #8c2bee; background: rgba(140, 43, 238, 0.05); }
    </style>
    <script>
        function updateTopClock() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            const clockEl = document.getElementById('topClock');
            if (clockEl) clockEl.textContent = timeString;
        }
        setInterval(updateTopClock, 1000);
        window.addEventListener('DOMContentLoaded', updateTopClock);
    </script>
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
<main class="flex-1 overflow-y-auto no-scrollbar p-10">
    <header class="mb-10 flex justify-between items-end">
        <div>
            <h2 class="text-3xl font-black italic uppercase tracking-tighter text-primary">Sales <span class="text-white">Report</span></h2>
            <p class="text-gray-500 font-bold uppercase tracking-[0.3em] text-[10px] mt-2">Revenue Tracking & Transaction Analytics</p>
        </div>

        <div class="flex flex-col items-end">
            <p id="topClock" class="text-white font-black italic text-2xl leading-none">00:00:00 AM</p>
            <p class="text-[10px] font-black uppercase text-gray-500 tracking-[0.2em] leading-none mt-2"><?= date('l, M d, Y') ?></p>
        </div>
    </header>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-10">
        <div class="stat-card p-8 rounded-[32px]">
            <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Total Revenue</p>
            <h3 class="text-3xl font-black italic tracking-tighter text-white">₱<?= number_format($total_revenue, 2) ?></h3>
        </div>
        <div class="stat-card p-8 rounded-[32px] border-l-4 border-primary">
            <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Monthly Sales</p>
            <h3 class="text-3xl font-black italic tracking-tighter text-primary">₱<?= number_format($monthly_sales, 2) ?></h3>
        </div>
        <div class="stat-card p-8 rounded-[32px]">
            <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Weekly Forecast</p>
            <h3 class="text-3xl font-black italic tracking-tighter text-white">₱<?= number_format($monthly_sales / 4, 2) ?></h3>
        </div>
        <div class="stat-card p-8 rounded-[32px] border-l-4 border-emerald-500">
            <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Daily Revenue</p>
            <h3 class="text-3xl font-black italic tracking-tighter text-emerald-500">₱<?= number_format($daily_sales, 2) ?></h3>
        </div>
    </div>

    <div class="glass-card p-6 mb-6">
        <div class="flex flex-wrap items-center gap-6">
            <div class="flex flex-col gap-2">
                <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Specific Day</label>
                <input type="date" class="filter-input w-44">
            </div>
            <div class="flex flex-col gap-2">
                <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Month</label>
                <select class="filter-input w-40">
                    <option value="">All Months</option>
                    <?php for($m=1; $m<=12; ++$m): ?>
                        <option value="<?= $m ?>"><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="flex flex-col gap-2">
                <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Year</label>
                <select class="filter-input w-32">
                    <option>2026</option>
                    <option>2025</option>
                    <option>2024</option>
                </select>
            </div>
            <button class="mt-5 bg-white/5 hover:bg-white/10 text-white border border-white/10 px-6 py-2.5 rounded-xl font-black italic uppercase tracking-tighter text-[10px] transition-all">
                Filter Results
            </button>
        </div>
    </div>

    <div class="bg-card-dark rounded-[32px] border border-white/5 overflow-hidden shadow-2xl">
        <div class="px-8 py-5 border-b border-white/5 flex flex-col md:flex-row justify-between items-center bg-white/[0.02] gap-4">
            <h4 class="font-black italic uppercase text-xs tracking-widest flex items-center gap-2">
                <span class="material-symbols-outlined text-primary">receipt_long</span> Transaction History
            </h4>
            
            <div class="flex items-center gap-3">
                <button class="px-5 py-2.5 rounded-xl bg-white/5 border border-white/10 font-black italic uppercase text-[9px] tracking-widest hover:bg-white/10 transition-all flex items-center gap-2">
                    <span class="material-symbols-outlined text-sm">picture_as_pdf</span> Download PDF
                </button>
                <button class="px-5 py-2.5 rounded-xl bg-primary text-white font-black italic uppercase text-[9px] tracking-widest shadow-lg shadow-primary/20 flex items-center gap-2">
                    <span class="material-symbols-outlined text-sm">analytics</span> Generate Audit
                </button>
            </div>
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
                                    <?= $t['first_name'] ? htmlspecialchars($t['first_name'].' '.$t['last_name']) : 'Guest Transaction' ?>
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
