<?php 
session_start();
require_once '../db.php';

// Security Check: Only Superadmin can access
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'superadmin') {
    header("Location: ../login.php");
    exit;
}

$page_title = "System Reports";
$active_page = "reports"; 

// Get Filter Inputs
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$tenant_filter = $_GET['tenant_id'] ?? 'all';

// 1. Fetch Tenants for Filter Dropdown
$tenants_list = $pdo->query("SELECT gym_id, gym_name FROM gyms WHERE status = 'Active' ORDER BY gym_name ASC")->fetchAll();

// 2. User Registration Statistics (Grouped by Date)
$user_reg_query = "SELECT DATE(created_at) as reg_date, COUNT(*) as count 
                   FROM users 
                   WHERE created_at BETWEEN :start AND :end 
                   GROUP BY DATE(created_at) ORDER BY reg_date ASC";
$stmtReg = $pdo->prepare($user_reg_query);
$stmtReg->execute(['start' => $date_from . ' 00:00:00', 'end' => $date_to . ' 23:59:59']);
$registration_data = $stmtReg->fetchAll(PDO::FETCH_ASSOC);

// 3. Tenant Activity Reports (Counts per Gym via user_roles)
$activity_sql = "SELECT g.gym_name, COUNT(ur.user_id) as activity_count, g.tenant_code
                 FROM gyms g
                 LEFT JOIN user_roles ur ON g.gym_id = ur.gym_id
                 WHERE g.status = 'Active' " . ($tenant_filter !== 'all' ? "AND g.gym_id = :tid" : "") . "
                 GROUP BY g.gym_id";
$stmtAct = $pdo->prepare($activity_sql);
$act_params = ($tenant_filter !== 'all') ? ['tid' => $tenant_filter] : [];
$stmtAct->execute($act_params);
$tenant_activity = $stmtAct->fetchAll(PDO::FETCH_ASSOC);

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
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "#8c2bee", "background-dark": "#0a090d", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)"}}}
        }
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: #0b0a0f; color: #e2e8f0; } /* Softer background */
        .glass-card { background: #16141d; border: 1px solid rgba(255,255,255,0.03); border-radius: 24px; box-shadow: 0 10px 30px -10px rgba(0,0,0,0.5); }
        
        /* Sidebar Hover Logic */
        .sidebar-nav {
            width: 110px;
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
            margin-bottom: 0.5rem !important;
            pointer-events: auto;
        }
        /* Override for Overview which is the first section */
        .sidebar-nav:hover .nav-section-header.mt-4 { margin-top: 0.75rem !important; }
        .sidebar-nav:hover .nav-section-header.mt-6 { margin-top: 1.25rem !important; }

        .sidebar-content {
            gap: 2px;
            transition: all 0.3s ease-in-out;
        }
        .sidebar-nav:hover .sidebar-content {
            gap: 4px;
        }
        .nav-link { font-size: 11px; font-weight: 800; letter-spacing: 0.05em; transition: all 0.2s; white-space: nowrap; }
        .active-nav { color: #8c2bee !important; position: relative; }
        .active-nav::after { 
            content: ''; 
            position: absolute; 
            right: 0px; 
            top: 50%;
            transform: translateY(-50%);
            width: 4px; 
            height: 20px; 
            background: #8c2bee; 
            border-radius: 99px; 
        }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        /* Custom Tab Styles matching Tenant Management Exactly */
        .tab-btn { position: relative; transition: all 0.3s ease; color: #6b7280; } /* text-gray-400 equivalent */
        .tab-btn.active { color: #8c2bee; } /* text-primary */
        .tab-indicator { position: absolute; bottom: 0; left: 0; right: 0; height: 2px; background-color: #8c2bee; border-radius: 9999px; transition: all 0.3s ease; opacity: 0; }
        .tab-btn.active .tab-indicator { opacity: 1; }

        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeIn 0.4s ease-out; }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
    <script>
        function updateHeaderClock() {
            const now = new Date();
            const clockEl = document.getElementById('headerClock');
            if (clockEl) {
                clockEl.textContent = now.toLocaleTimeString('en-US', { 
                    hour: '2-digit', 
                    minute: '2-digit', 
                    second: '2-digit' 
                });
            }
        }
        setInterval(updateHeaderClock, 1000);
        window.addEventListener('DOMContentLoaded', updateHeaderClock);

        function switchTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
            
            document.getElementById(tabId).classList.add('active');
            event.currentTarget.classList.add('active');
        }
    </script>
</head>
<body class="antialiased flex flex-row min-h-screen">

<nav class="sidebar-nav flex flex-col bg-[#0b0a0f] border-r border-white/5 sticky top-0 h-screen px-7 py-8 z-50 shrink-0">
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

<div class="flex-1 flex flex-col min-w-0 overflow-y-auto">
    <main class="flex-1 p-6 md:p-10 max-w-[1400px] w-full mx-auto">
        <header class="mb-10 flex flex-row justify-between items-end gap-6">
            <div>
                <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white leading-none">System <span class="text-primary">Reports</span></h2>
                <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-2">Analytical Insights & Performance</p>
            </div>
            <div class="text-right">
                <p id="headerClock" class="text-white font-black italic text-xl tracking-tight leading-none mb-2">00:00:00 AM</p>
                <p class="text-primary text-[9px] font-black uppercase tracking-[0.2em] opacity-80"><?= date('l, M d, Y') ?></p>
            </div>
        </header>

        <div class="glass-card mb-8 p-8">
            <form method="GET" class="flex flex-wrap items-end gap-6">
                <div class="space-y-2">
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 ml-1">Date From</p>
                    <input type="date" name="date_from" value="<?= $date_from ?>" class="bg-[#0a090d] border border-white/5 rounded-xl px-4 py-2 text-xs text-white focus:outline-none focus:border-primary">
                </div>
                <div class="space-y-2">
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 ml-1">Date To</p>
                    <input type="date" name="date_to" value="<?= $date_to ?>" class="bg-[#0a090d] border border-white/5 rounded-xl px-4 py-2 text-xs text-white focus:outline-none focus:border-primary">
                </div>
                <div class="space-y-2">
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 ml-1">Select Tenant</p>
                    <select name="tenant_id" class="bg-[#0a090d] border border-white/5 rounded-xl px-4 py-2 text-xs text-white focus:outline-none focus:border-primary">
                        <option value="all">All Tenants</option>
                        <?php foreach($tenants_list as $gt): ?>
                            <option value="<?= $gt['gym_id'] ?>" <?= $tenant_filter == $gt['gym_id'] ? 'selected' : '' ?>><?= htmlspecialchars($gt['gym_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="h-10 px-8 rounded-xl bg-primary text-black text-[10px] font-black uppercase italic tracking-widest hover:scale-105 transition-transform active:scale-95 shadow-lg shadow-primary/20">Generate Report</button>
            </form>
        </div>

        <!-- Tab Navigation matching Tenant Management Exactly -->
        <div class="flex gap-8 border-b border-white/5 mb-8 px-2">
            <button onclick="switchTab('overviewTab')" class="tab-btn active pb-4 text-xs font-black uppercase tracking-widest transition-all relative group">
                Analytics Overview
                <div class="tab-indicator"></div>
            </button>
            <button onclick="switchTab('detailedTab')" class="tab-btn pb-4 text-xs font-black uppercase tracking-widest transition-all relative group">
                Detailed Reports
                <div class="tab-indicator"></div>
            </button>
        </div>

        <!-- Tab 1: Overview -->
        <div id="overviewTab" class="tab-content active">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-10">
                <div class="lg:col-span-2 glass-card p-8">
                    <div class="flex justify-between items-center mb-8">
                        <div>
                            <h3 class="text-sm font-black italic uppercase tracking-widest text-white">User Registration Growth</h3>
                            <p class="text-[9px] text-gray-500 font-bold uppercase mt-1 tracking-wider">Growth Trends Over Time</p>
                        </div>
                    </div>
                    <div class="h-[300px] w-full">
                        <canvas id="registrationChart"></canvas>
                    </div>
                </div>

                <div class="glass-card p-8 flex flex-col justify-between">
                    <h3 class="text-sm font-black italic uppercase tracking-widest text-white mb-6">Usage Statistics</h3>
                    <div class="space-y-6">
                        <div class="p-5 rounded-2xl bg-white/[0.01] border border-white/5 relative overflow-hidden group hover:border-primary/20 transition-colors">
                            <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-4xl opacity-5 group-hover:scale-110 transition-transform">groups</span>
                            <p class="text-[10px] font-black uppercase text-gray-500 mb-1 tracking-widest">Total System Users</p>
                            <h2 class="text-2xl font-black text-white italic">1,248</h2>
                        </div>
                        <div class="p-5 rounded-2xl bg-white/[0.01] border border-white/5 relative overflow-hidden group hover:border-emerald-500/20 transition-colors">
                            <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-4xl opacity-5 group-hover:scale-110 transition-transform text-emerald-500">login</span>
                            <p class="text-[10px] font-black uppercase text-gray-500 mb-1 tracking-widest">Avg. Daily Logins</p>
                            <h2 class="text-2xl font-black text-emerald-500 italic">452</h2>
                        </div>
                        <div class="p-5 rounded-2xl bg-white/[0.01] border border-white/5 relative overflow-hidden group hover:border-primary/20 transition-colors">
                            <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-4xl opacity-5 group-hover:scale-110 transition-transform text-primary">schedule</span>
                            <p class="text-[10px] font-black uppercase text-gray-500 mb-1 tracking-widest">Peak Usage Hour</p>
                            <h2 class="text-2xl font-black text-primary italic">06:00 PM</h2>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab 2: Detailed Reports -->
        <div id="detailedTab" class="tab-content">
            <div class="glass-card overflow-hidden">
                <div class="px-8 py-6 border-b border-white/5 flex justify-between items-center bg-white/[0.01]">
                    <div>
                        <h3 class="text-sm font-black italic uppercase tracking-widest text-white">Tenant Activity Report</h3>
                        <p class="text-[9px] text-gray-500 font-bold uppercase tracking-widest mt-1">Member Interaction Per Gym</p>
                    </div>
                    <button class="px-4 py-2 rounded-xl border border-white/5 text-[9px] font-black uppercase tracking-widest text-gray-400 hover:text-white hover:bg-white/5 transition-all">Export CSV</button>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-black/20 text-gray-500 text-[10px] font-black uppercase tracking-widest">
                                <th class="px-8 py-4">Tenant Name</th>
                                <th class="px-8 py-4">Tenant Code</th>
                                <th class="px-8 py-4">Total Activities</th>
                                <th class="px-8 py-4">Health Score</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <?php if (empty($tenant_activity)): ?>
                                <tr><td colspan="4" class="px-8 py-8 text-center text-xs font-bold text-gray-500 italic uppercase">No data available</td></tr>
                            <?php else: ?>
                                <?php foreach ($tenant_activity as $act): ?>
                                <tr class="hover:bg-white/[0.01] transition-colors group">
                                    <td class="px-8 py-5 text-sm font-bold text-white"><?= htmlspecialchars($act['gym_name']) ?></td>
                                    <td class="px-8 py-5 text-xs font-black text-primary italic uppercase tracking-widest"><?= htmlspecialchars($act['tenant_code']) ?></td>
                                    <td class="px-8 py-5 text-sm font-bold text-gray-400 uppercase tracking-tighter"><?= number_format($act['activity_count']) ?> <span class="text-[8px] opacity-40 italic">Logs</span></td>
                                    <td class="px-8 py-5">
                                        <div class="w-full max-w-[100px] h-1 bg-white/5 rounded-full overflow-hidden">
                                            <div class="h-full bg-emerald-500 shadow-[0_0_10px_rgba(16,185,129,0.3)] transition-all group-hover:bg-emerald-400" style="width: 75%"></div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</div>
    </main>
</div>

<script>
    const ctx = document.getElementById('registrationChart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: [<?php foreach($registration_data as $d) echo "'" . date('M d', strtotime($d['reg_date'])) . "',"; ?>],
            datasets: [{
                label: 'New Registrations',
                data: [<?php foreach($registration_data as $d) echo $d['count'] . ","; ?>],
                borderColor: '#8c2bee',
                backgroundColor: 'rgba(140, 43, 238, 0.05)',
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointRadius: 4,
                pointBackgroundColor: '#8c2bee',
                pointBorderColor: 'rgba(255,255,255,0.1)',
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { 
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#14121a',
                    titleColor: '#8c2bee',
                    bodyColor: '#fff',
                    borderColor: 'rgba(255,255,255,0.1)',
                    borderWidth: 1,
                    padding: 10,
                    displayColors: false
                }
            },
            scales: {
                y: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#666', font: { size: 10, weight: '800' } } },
                x: { grid: { display: false }, ticks: { color: '#666', font: { size: 10, weight: '800' } } }
            }
        }
    });
</script>
</body>
</html>