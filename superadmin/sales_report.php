<?php 
session_start();
require_once '../db.php';

// Security Check: Only Superadmin can access
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'superadmin') {
    header("Location: ../login.php");
    exit;
}

$page_title = "Sales Report";
$active_page = "sales_report"; 

// Get Filter Inputs (Default to current month)
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');

// 1. TOTAL SALES / REVENUE
$stmtRev = $pdo->prepare("SELECT SUM(amount) as total FROM client_subscriptions WHERE payment_status = 'Paid' AND created_at BETWEEN :start AND :end");
$stmtRev->execute(['start' => $date_from . ' 00:00:00', 'end' => $date_to . ' 23:59:59']);
$total_revenue = $stmtRev->fetchColumn() ?? 0;

// 2. SALES PER TENANT & TOP PERFORMERS
$stmtTenantSales = $pdo->prepare("
    SELECT g.gym_name, g.tenant_code, SUM(cs.amount) as total_revenue, COUNT(cs.subscription_id) as transaction_count
    FROM gyms g
    JOIN client_subscriptions cs ON g.gym_id = cs.gym_id
    WHERE cs.payment_status = 'Paid' AND cs.created_at BETWEEN :start AND :end
    GROUP BY g.gym_id
    ORDER BY total_revenue DESC
");
$stmtTenantSales->execute(['start' => $date_from . ' 00:00:00', 'end' => $date_to . ' 23:59:59']);
$tenant_sales = $stmtTenantSales->fetchAll(PDO::FETCH_ASSOC);

// 3. DAILY SALES (For Charting)
$stmtDaily = $pdo->prepare("
    SELECT DATE(created_at) as sale_date, SUM(amount) as daily_amount 
    FROM client_subscriptions 
    WHERE payment_status = 'Paid' AND created_at BETWEEN :start AND :end 
    GROUP BY DATE(created_at) 
    ORDER BY sale_date ASC
");
$stmtDaily->execute(['start' => $date_from . ' 00:00:00', 'end' => $date_to . ' 23:59:59']);
$daily_sales = $stmtDaily->fetchAll(PDO::FETCH_ASSOC);

// 4. TRANSACTION HISTORY SUMMARY (Recent 15)
$stmtHistory = $pdo->prepare("
    SELECT cs.*, g.gym_name, wp.plan_name 
    FROM client_subscriptions cs
    JOIN gyms g ON cs.gym_id = g.gym_id
    JOIN website_plans wp ON cs.website_plan_id = wp.website_plan_id
    WHERE cs.created_at BETWEEN :start AND :end
    ORDER BY cs.created_at DESC LIMIT 15
");
$stmtHistory->execute(['start' => $date_from . ' 00:00:00', 'end' => $date_to . ' 23:59:59']);
$transactions = $stmtHistory->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title><?= $page_title ?> | Horizon System</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; }
        
        /* Sidebar Hover Logic */
        .sidebar-nav {
            width: 80px;
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
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
            opacity: 0;
            transition: all 0.3s ease-in-out;
            margin-top: 0;
            margin-bottom: 0.5rem;
            pointer-events: none;
        }
        .sidebar-nav:hover .nav-section-header {
            opacity: 1;
            margin-top: 1.25rem; /* Reduced from 1.5rem */
            pointer-events: auto;
        }
        /* Override for Overview which is the first section */
        .sidebar-nav:hover .nav-section-header.mt-4 { margin-top: 0.75rem !important; }
        .sidebar-nav:hover .nav-section-header.mt-6 { margin-top: 1.25rem !important; }

        .sidebar-content {
            gap: 2px; /* Much searhc tighter base gap */
            transition: all 0.3s ease-in-out;
        }
        .sidebar-nav:hover .sidebar-content {
            gap: 4px; /* Slightly more space on hover for readability */
        }
        .nav-link { font-size: 11px; font-weight: 800; letter-spacing: 0.05em; transition: all 0.2s; white-space: nowrap; }
        .active-nav { color: #8c2bee !important; position: relative; }
        .active-nav::after { content: ''; position: absolute; right: 0px; top: 50%; transform: translateY(-50%); width: 4px; height: 20px; background: #8c2bee; border-radius: 99px; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>
<body class="antialiased flex min-h-screen">

<nav class="sidebar-nav flex flex-col bg-[#0a090d] border-r border-white/5 sticky top-0 h-screen px-7 py-8 z-50 shrink-0">
    <div class="mb-8">
        <div class="flex items-center gap-4 mb-6">
            <div class="size-10 rounded-xl bg-[#7f13ec] flex items-center justify-center shadow-lg shrink-0">
                <span class="material-symbols-outlined text-white text-2xl">bolt</span>
            </div>
            <h1 class="nav-text text-xl font-black italic uppercase tracking-tighter text-white">Horizon System</h1>
        </div>
    </div>
    
    <div class="sidebar-content flex-1 overflow-y-auto no-scrollbar pr-2 pb-10 flex flex-col">
        <!-- Overview Section -->
        <div class="nav-section-header px-0 mb-2 mt-4">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Overview</span>
        </div>
        <a href="superadmin_dashboard.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'dashboard') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">grid_view</span> 
            <span class="nav-text">Dashboard</span>
        </a>
        
        <!-- Management Section -->
        <div class="nav-section-header px-0 mb-2 mt-6">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Management</span>
        </div>
        <a href="tenant_management.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'tenants') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">business</span> 
            <span class="nav-text">Tenant Management</span>
        </a>

        <a href="subscription_logs.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'subscriptions') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">history_edu</span> 
            <span class="nav-text">Subscription Logs</span>
        </a>

        <a href="rbac_management.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'rbac') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">security</span> 
            <span class="nav-text">Access Control</span>
        </a>

        <a href="real_time_occupancy.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'occupancy') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">group</span> 
            <span class="nav-text">Real-Time Occupancy</span>
        </a>

        <a href="recent_transaction.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'transactions') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">receipt_long</span> 
            <span class="nav-text">Recent Transactions</span>
        </a>

        <!-- System Section -->
        <div class="nav-section-header px-0 mb-2 mt-6">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">System</span>
        </div>
        <a href="system_alerts.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'alerts') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">notifications_active</span> 
            <span class="nav-text">System Alerts</span>
        </a>

        <a href="system_reports.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'reports') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">analytics</span> 
            <span class="nav-text">Reports</span>
        </a>

        <a href="sales_report.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'sales_report') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">monitoring</span> 
            <span class="nav-text">Sales Reports</span>
        </a>

        <a href="audit_logs.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'audit_logs') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">assignment</span> 
            <span class="nav-text">Audit Logs</span>
        </a>

        <a href="backup.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'backup') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">backup</span> 
            <span class="nav-text">Backup</span>
        </a>
    </div>

    <div class="mt-auto pt-10 border-t border-white/10 flex flex-col gap-4">
        <div class="nav-section-header px-0 mb-2">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Account</span>
        </div>
        <a href="settings.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'settings') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">settings</span> 
            <span class="nav-text">Settings</span>
        </a>
        <a href="#" class="text-gray-400 hover:text-white transition-colors flex items-center gap-4 group">
            <span class="material-symbols-outlined transition-transform group-hover:text-primary text-xl shrink-0">person</span>
            <span class="nav-link nav-text">Profile</span>
        </a>
        <a href="../logout.php" class="text-gray-400 hover:text-rose-500 transition-colors flex items-center gap-4 group">
            <span class="material-symbols-outlined group-hover:translate-x-1 transition-transform text-xl shrink-0">logout</span>
            <span class="nav-link nav-text">Sign Out</span>
        </a>
    </div>
</nav>
    
    <main class="flex-1 p-8">
        <?php include '../includes/superadmin_header.php'; ?>

        <div class="mt-8 p-6 bg-card rounded-[24px]">
            <form method="GET" class="flex flex-wrap items-end gap-6">
                <div class="space-y-2">
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">Start Date</p>
                    <input type="date" name="date_from" value="<?= $date_from ?>" class="bg-[#0c0c0c] border border-white/10 rounded-xl px-4 py-2 text-xs text-white focus:border-primary focus:outline-none">
                </div>
                <div class="space-y-2">
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">End Date</p>
                    <input type="date" name="date_to" value="<?= $date_to ?>" class="bg-[#0c0c0c] border border-white/10 rounded-xl px-4 py-2 text-xs text-white focus:border-primary focus:outline-none">
                </div>
                <button type="submit" class="h-10 px-8 rounded-xl bg-primary text-black text-[10px] font-black uppercase italic tracking-widest hover:scale-105 transition-transform">Update Report</button>
            </form>
        </div>

        <div class="mt-8 grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="p-8 bg-card rounded-[32px] relative overflow-hidden">
                <div class="absolute top-0 right-0 p-4 opacity-10">
                    <span class="material-symbols-outlined text-6xl">payments</span>
                </div>
                <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest">Total Revenue</p>
                <h2 class="text-4xl font-black text-white italic mt-2">₱<?= number_format($total_revenue, 2) ?></h2>
            </div>
            <div class="p-8 bg-card rounded-[32px]">
                <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest">Transactions</p>
                <h2 class="text-4xl font-black text-primary italic mt-2"><?= count($transactions) ?></h2>
            </div>
            <div class="p-8 bg-card rounded-[32px]">
                <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest">Top Tenant</p>
                <h2 class="text-2xl font-black text-white italic mt-2"><?= $tenant_sales[0]['gym_name'] ?? 'N/A' ?></h2>
            </div>
        </div>

        <div class="mt-8 grid grid-cols-1 lg:grid-cols-3 gap-8">
            <div class="lg:col-span-2 bg-card rounded-[32px] p-8">
                <h3 class="text-lg font-black italic uppercase text-white tracking-tighter mb-8">Sales Performance Trend</h3>
                <div class="h-[350px]">
                    <canvas id="salesTrendChart"></canvas>
                </div>
            </div>

            <div class="bg-card rounded-[32px] p-8">
                <h3 class="text-lg font-black italic uppercase text-white tracking-tighter mb-6">Sales Per Tenant</h3>
                <div class="space-y-4">
                    <?php foreach ($tenant_sales as $ts): ?>
                    <div class="p-4 rounded-2xl bg-white/[0.02] border border-white/5 flex justify-between items-center">
                        <div>
                            <p class="text-xs font-bold text-white"><?= htmlspecialchars($ts['gym_name']) ?></p>
                            <p class="text-[9px] text-gray-500 font-black uppercase tracking-widest italic"><?= $ts['transaction_count'] ?> sales</p>
                        </div>
                        <p class="text-sm font-black text-primary italic">₱<?= number_format($ts['total_revenue'], 0) ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="mt-8 bg-card rounded-[32px] overflow-hidden">
            <div class="px-8 py-6 border-b border-white/5 bg-white/[0.01] flex justify-between items-center">
                <h3 class="text-lg font-black italic uppercase text-white tracking-tighter">Transaction History Summary</h3>
                <button class="text-[10px] font-black uppercase text-primary border-b border-primary/20 hover:border-primary transition-all">Export Report</button>
            </div>
            <table class="w-full text-left">
                <thead>
                    <tr class="bg-white/[0.02]">
                        <th class="px-8 py-4 text-[10px] font-black uppercase tracking-widest text-gray-500">Tenant / Code</th>
                        <th class="px-8 py-4 text-[10px] font-black uppercase tracking-widest text-gray-500">Plan Type</th>
                        <th class="px-8 py-4 text-[10px] font-black uppercase tracking-widest text-gray-500">Amount</th>
                        <th class="px-8 py-4 text-[10px] font-black uppercase tracking-widest text-gray-500">Date</th>
                        <th class="px-8 py-4 text-[10px] font-black uppercase tracking-widest text-gray-500 text-right">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php foreach ($transactions as $trx): ?>
                    <tr class="hover:bg-white/[0.01] transition-colors">
                        <td class="px-8 py-5">
                            <p class="text-xs font-bold text-white"><?= htmlspecialchars($trx['gym_name']) ?></p>
                            <p class="text-[9px] text-gray-500 font-black uppercase italic tracking-widest"><?= htmlspecialchars($trx['subscription_id']) ?></p>
                        </td>
                        <td class="px-8 py-5 text-[10px] font-black text-white uppercase italic"><?= htmlspecialchars($trx['plan_name']) ?></td>
                        <td class="px-8 py-5 text-sm font-black text-primary">₱<?= number_format($trx['amount'], 2) ?></td>
                        <td class="px-8 py-5 text-[10px] text-gray-500 font-bold uppercase"><?= date('M d, Y', strtotime($trx['created_at'])) ?></td>
                        <td class="px-8 py-5 text-right">
                            <span class="px-3 py-1 rounded-full text-[9px] font-black uppercase italic bg-emerald-500/10 text-emerald-500 border border-emerald-500/20">Paid</span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>

<script>
    const ctx = document.getElementById('salesTrendChart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: [<?php foreach($daily_sales as $ds) echo "'" . date('M d', strtotime($ds['sale_date'])) . "',"; ?>],
            datasets: [{
                label: 'Daily Revenue',
                data: [<?php foreach($daily_sales as $ds) echo $ds['daily_amount'] . ","; ?>],
                borderColor: '#c6ff00',
                backgroundColor: 'rgba(198, 255, 0, 0.05)',
                borderWidth: 4,
                fill: true,
                tension: 0.4,
                pointRadius: 0,
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { grid: { color: 'rgba(255,255,255,0.03)' }, ticks: { color: '#666', font: { size: 10 } } },
                x: { grid: { display: false }, ticks: { color: '#666', font: { size: 10 } } }
            }
        }
    });
</script>
</body>
</html>