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

// 3. Tenant Activity Reports (Logins/Activity per Gym)
// Note: Assuming you have a 'logs' or 'activity' table. If not, this acts as a placeholder logic.
$activity_sql = "SELECT g.gym_name, COUNT(u.user_id) as activity_count, g.tenant_code
                 FROM gyms g
                 LEFT JOIN users u ON g.gym_id = u.gym_id
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
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; }
        
        /* Sidebar Hover Logic */
        .sidebar-nav { width: 110px; transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1); overflow: hidden; }
        .sidebar-nav:hover { width: 300px; }
        .nav-text { opacity: 0; transform: translateX(-15px); transition: all 0.3s ease; white-space: nowrap; pointer-events: none; }
        .sidebar-nav:hover .nav-text { opacity: 1; transform: translateX(0); pointer-events: auto; }
        .nav-link { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; transition: all 0.2s; white-space: nowrap; }
        .active-nav { color: #8c2bee !important; position: relative; }
        .active-nav::after { content: ''; position: absolute; right: 0px; top: 50%; transform: translateY(-50%); width: 4px; height: 20px; background: #8c2bee; border-radius: 99px; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>
<body class="antialiased flex min-h-screen">

<nav class="sidebar-nav flex flex-col bg-[#0a090d] border-r border-white/5 sticky top-0 h-screen px-7 py-8 z-50 shrink-0">
    <div class="mb-12">
        <div class="flex items-center gap-4 mb-6">
            <div class="size-10 rounded-xl bg-[#7f13ec] flex items-center justify-center shadow-lg shrink-0">
                <span class="material-symbols-outlined text-white text-2xl">bolt</span>
            </div>
            <h1 class="nav-text text-xl font-black italic uppercase tracking-tighter text-white">Horizon System</h1>
        </div>
    </div>
    
    <div class="flex flex-col gap-6 flex-1 overflow-y-auto no-scrollbar pr-2 pb-10">
        <a href="superadmin_dashboard.php" class="nav-link flex items-center gap-4 <?= ($active_page == 'dashboard') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-2xl shrink-0">grid_view</span> 
            <span class="nav-text">Dashboard</span>
        </a>
        <a href="tenant_management.php" class="nav-link flex items-center gap-4 <?= ($active_page == 'tenants') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-2xl shrink-0">business</span> 
            <span class="nav-text">Tenant Management</span>
        </a>
        <a href="subscription_logs.php" class="nav-link flex items-center gap-4 <?= ($active_page == 'subscriptions') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-2xl shrink-0">history_edu</span> 
            <span class="nav-text">Subscription Logs</span>
        </a>
        <a href="rbac_management.php" class="nav-link flex items-center gap-4 <?= ($active_page == 'rbac') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-2xl shrink-0">security</span> 
            <span class="nav-text">Access Control</span>
        </a>
        <a href="real_time_occupancy.php" class="nav-link flex items-center gap-4 <?= ($active_page == 'occupancy') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-2xl shrink-0">group</span> 
            <span class="nav-text">Real-Time Occupancy</span>
        </a>
        <a href="recent_transaction.php" class="nav-link flex items-center gap-4 <?= ($active_page == 'transactions') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-2xl shrink-0">receipt_long</span> 
            <span class="nav-text">Recent Transactions</span>
        </a>
        <a href="system_alerts.php" class="nav-link flex items-center gap-4 <?= ($active_page == 'alerts') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-2xl shrink-0">notifications_active</span> 
            <span class="nav-text">System Alerts</span>
        </a>
        <a href="system_reports.php" class="nav-link flex items-center gap-4 <?= ($active_page == 'reports') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-2xl shrink-0">analytics</span> 
            <span class="nav-text">Reports</span>
        </a>
        <a href="sales_report.php" class="nav-link flex items-center gap-4 <?= ($active_page == 'sales_report') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-2xl shrink-0">monitoring</span> 
            <span class="nav-text">Sales Reports</span>
        </a>
        <a href="audit_logs.php" class="nav-link flex items-center gap-4 <?= ($active_page == 'audit_logs') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-2xl shrink-0">assignment</span> 
            <span class="nav-text">Audit Logs</span>
        </a>
        <a href="settings.php" class="nav-link flex items-center gap-4 <?= ($active_page == 'settings') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-2xl shrink-0">settings</span> 
            <span class="nav-text">Settings</span>
        </a>
    </div>

    <div class="mt-auto pt-10 border-t border-white/10 flex flex-col gap-8">
        <a href="#" class="text-gray-400 hover:text-white transition-colors flex items-center gap-4 group">
            <span class="material-symbols-outlined transition-transform group-hover:text-primary text-2xl shrink-0">person</span>
            <span class="nav-link nav-text">Profile</span>
        </a>
        <a href="../logout.php" class="text-gray-400 hover:text-red-500 transition-colors flex items-center gap-4 group">
            <span class="material-symbols-outlined group-hover:translate-x-1 transition-transform text-2xl shrink-0">logout</span>
            <span class="nav-link nav-text">Sign Out</span>
        </a>
    </div>
</nav>
    
    <main class="flex-1 p-8">
        <?php include '../includes/superadmin_header.php'; ?>

        <div class="mt-8 p-6 bg-white/5 border border-white/5 rounded-[24px]">
            <form method="GET" class="flex flex-wrap items-end gap-6">
                <div class="space-y-2">
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">Date From</p>
                    <input type="date" name="date_from" value="<?= $date_from ?>" class="bg-[#0c0c0c] border border-white/10 rounded-xl px-4 py-2 text-xs text-white focus:outline-none focus:border-primary">
                </div>
                <div class="space-y-2">
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">Date To</p>
                    <input type="date" name="date_to" value="<?= $date_to ?>" class="bg-[#0c0c0c] border border-white/10 rounded-xl px-4 py-2 text-xs text-white focus:outline-none focus:border-primary">
                </div>
                <div class="space-y-2">
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">Select Tenant</p>
                    <select name="tenant_id" class="bg-[#0c0c0c] border border-white/10 rounded-xl px-4 py-2 text-xs text-white focus:outline-none focus:border-primary">
                        <option value="all">All Tenants</option>
                        <?php foreach($tenants_list as $gt): ?>
                            <option value="<?= $gt['gym_id'] ?>" <?= $tenant_filter == $gt['gym_id'] ? 'selected' : '' ?>><?= htmlspecialchars($gt['gym_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="h-10 px-6 rounded-xl bg-primary text-black text-[10px] font-black uppercase italic tracking-widest hover:scale-105 transition-transform active:scale-95">Generate Report</button>
            </form>
        </div>

        <div class="mt-8 grid grid-cols-1 lg:grid-cols-3 gap-8">
            <div class="lg:col-span-2 bg-white/5 border border-white/5 rounded-3xl p-8">
                <div class="flex justify-between items-center mb-8">
                    <div>
                        <h3 class="text-lg font-black italic uppercase text-white tracking-tighter">User Registration Growth</h3>
                        <p class="text-[10px] text-gray-500 font-bold uppercase tracking-widest">Growth Trends over time</p>
                    </div>
                </div>
                <div class="h-[300px] w-full">
                    <canvas id="registrationChart"></canvas>
                </div>
            </div>

            <div class="bg-white/5 border border-white/5 rounded-3xl p-8">
                <h3 class="text-lg font-black italic uppercase text-white tracking-tighter mb-6">Usage Statistics</h3>
                <div class="space-y-6">
                    <div class="p-4 rounded-2xl bg-white/[0.02] border border-white/5">
                        <p class="text-[10px] font-black uppercase text-gray-500 mb-1">Total System Users</p>
                        <h2 class="text-2xl font-black text-white italic">1,248</h2>
                    </div>
                    <div class="p-4 rounded-2xl bg-white/[0.02] border border-white/5">
                        <p class="text-[10px] font-black uppercase text-gray-500 mb-1">Avg. Daily Logins</p>
                        <h2 class="text-2xl font-black text-emerald-500 italic">452</h2>
                    </div>
                    <div class="p-4 rounded-2xl bg-white/[0.02] border border-white/5">
                        <p class="text-[10px] font-black uppercase text-gray-500 mb-1">Peak Usage Hour</p>
                        <h2 class="text-2xl font-black text-primary italic">06:00 PM</h2>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-8 bg-white/5 border border-white/5 rounded-3xl overflow-hidden">
            <div class="px-8 py-6 border-b border-white/5 flex justify-between items-center bg-white/[0.02]">
                <div>
                    <h3 class="text-lg font-black italic uppercase text-white tracking-tighter">Tenant Activity Report</h3>
                    <p class="text-[10px] text-gray-500 font-bold uppercase tracking-widest">Member Interaction per Gym</p>
                </div>
                <button class="px-4 py-2 rounded-xl border border-white/10 text-[9px] font-black uppercase tracking-widest text-gray-400 hover:text-white transition-colors">Export CSV</button>
            </div>
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-white/[0.02]">
                        <th class="px-8 py-4 text-[10px] font-black uppercase tracking-widest text-gray-500">Tenant Name</th>
                        <th class="px-8 py-4 text-[10px] font-black uppercase tracking-widest text-gray-500">Tenant Code</th>
                        <th class="px-8 py-4 text-[10px] font-black uppercase tracking-widest text-gray-500">Total Activities</th>
                        <th class="px-8 py-4 text-[10px] font-black uppercase tracking-widest text-gray-500">Health Score</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php foreach ($tenant_activity as $act): ?>
                    <tr class="hover:bg-white/[0.02] transition-colors">
                        <td class="px-8 py-5 text-sm font-bold text-white"><?= htmlspecialchars($act['gym_name']) ?></td>
                        <td class="px-8 py-5 text-xs font-black text-primary italic uppercase tracking-widest"><?= htmlspecialchars($act['tenant_code']) ?></td>
                        <td class="px-8 py-5 text-sm font-bold text-gray-300"><?= number_format($act['activity_count']) ?> logs</td>
                        <td class="px-8 py-5">
                            <div class="w-full max-w-[100px] h-1.5 bg-white/5 rounded-full overflow-hidden">
                                <div class="h-full bg-emerald-500" style="width: 75%"></div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
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
                borderColor: '#c6ff00',
                backgroundColor: 'rgba(198, 255, 0, 0.05)',
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointRadius: 4,
                pointBackgroundColor: '#c6ff00'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#666', font: { size: 10 } } },
                x: { grid: { display: false }, ticks: { color: '#666', font: { size: 10 } } }
            }
        }
    });
</script>
</body>
</html>