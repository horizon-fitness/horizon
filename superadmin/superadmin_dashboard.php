<?php 
session_start();
require_once '../db.php';

// Security Check: Only Superadmin can access
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'superadmin') {
    header("Location: ../login.php");
    exit;
}

$page_title = "Admin (Developer) Dashboard";
$active_page = "dashboard";
$header_title = 'Admin <span class="text-primary">(Developer)</span> Dashboard';
$header_subtitle = 'Enterprise System Control Center';

// Application messages handled via session
$success_msg = $_SESSION['success_msg'] ?? '';
$error_msg = $_SESSION['error_msg'] ?? '';
unset($_SESSION['success_msg'], $_SESSION['error_msg']);

// Fetch Real Dynamic Data for Dashboard
$total_revenue = 0.00; // Place holder for billing later

$stmtTenants = $pdo->query("SELECT COUNT(*) FROM gyms WHERE status = 'Active'");
$active_tenants = $stmtTenants->fetchColumn();

$stmtInactiveTenants = $pdo->query("SELECT COUNT(*) FROM gyms WHERE status != 'Active' AND status != 'Deleted'");
$inactive_tenants = $stmtInactiveTenants->fetchColumn();

$stmtActiveUsers = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'Active'");
$active_users = $stmtActiveUsers->fetchColumn();

$stmtInactiveUsers = $pdo->query("SELECT COUNT(*) FROM users WHERE status != 'Active'");
$inactive_users = $stmtInactiveUsers->fetchColumn();

$stmtTotalUsers = $pdo->query("SELECT COUNT(*) FROM users");
$total_users = $stmtTotalUsers->fetchColumn();

$stmtPending = $pdo->query("SELECT COUNT(*) FROM gym_owner_applications WHERE application_status = 'Pending'");
$pending_apps_count = $stmtPending->fetchColumn();

// Daily/Monthly activity (Logins in the last 30 days)
$stmtActivity = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM audit_logs WHERE action_type = 'Login' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
$monthly_activity = $stmtActivity->fetchColumn();

$stmtDailyActivity = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM audit_logs WHERE action_type = 'Login' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)");
$daily_activity = $stmtDailyActivity->fetchColumn();

// Fetch Data for Charts
// 1. User Growth (Last 6 Months)
$stmtUserGrowth = $pdo->query("SELECT DATE_FORMAT(created_at, '%b') as month, COUNT(*) as count FROM users GROUP BY month ORDER BY created_at ASC LIMIT 6");
$user_growth_data = $stmtUserGrowth->fetchAll(PDO::FETCH_ASSOC);

// 2. Sales Trends (Last 7 Days)
$stmtSalesTrend = $pdo->query("SELECT DATE_FORMAT(created_at, '%m-%d') as day, SUM(amount) as total FROM client_subscriptions WHERE payment_status = 'Paid' GROUP BY day ORDER BY created_at ASC LIMIT 7");
$sales_trend_data = $stmtSalesTrend->fetchAll(PDO::FETCH_ASSOC);

// Fetch Recent Applications
$stmtList = $pdo->query("
    SELECT a.*, u.first_name, u.last_name, tp.logo_path
    FROM gym_owner_applications a 
    JOIN users u ON a.user_id = u.user_id 
    LEFT JOIN gyms g ON a.application_id = g.application_id
    LEFT JOIN tenant_pages tp ON g.gym_id = tp.gym_id
    ORDER BY 
        CASE WHEN a.application_status = 'Pending' THEN 1 ELSE 2 END,
        a.submitted_at DESC 
    LIMIT 10
");
$recent_applications = $stmtList->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title><?php echo $page_title ?? 'Admin (Developer) Dashboard'; ?> | Horizon System</title>
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
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        
        /* Sidebar Hover Logic - ADJUSTED WIDTHS */
        .sidebar-nav {
            width: 110px; /* Increased slightly from 100px */
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
        }
        .sidebar-nav:hover {
            width: 300px; /* Increased from 280px for better text fit */
        }
        .nav-text {
            opacity: 0;
            transform: translateX(-15px);
            transition: all 0.3s ease;
            white-space: nowrap;
            pointer-events: none;
        }
        .sidebar-nav:hover .nav-text {
            opacity: 1;
            transform: translateX(0);
            pointer-events: auto;
        }
        /* End Sidebar Hover Logic */

        .nav-link { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; transition: all 0.2s; white-space: nowrap; }
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
        
        @media (max-width: 1023px) {
            .active-nav::after { display: none; }
        }
        .alert-pulse { animation: alert-pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite; }
        @keyframes alert-pulse { 0%, 100% { opacity: 1; } 50% { opacity: .5; } }
        
        .status-card-green { border: 1px solid #10b981; background: linear-gradient(135deg, rgba(16,185,129,0.05) 0%, rgba(20,18,26,1) 100%); }
        .status-card-yellow { border: 1px solid #f59e0b; background: linear-gradient(135deg, rgba(245,158,11,0.05) 0%, rgba(20,18,26,1) 100%); }
        .status-card-red { border: 1px solid #ef4444; background: linear-gradient(135deg, rgba(239,68,68,0.05) 0%, rgba(20,18,26,1) 100%); }
        .dashed-container { border: 2px dashed rgba(255,255,255,0.1); border-radius: 24px; }
        
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
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
    </script>
</head>
<body class="antialiased flex flex-row min-h-screen">

<?php include '../includes/superadmin_sidebar.php'; ?>

<div class="flex-1 flex flex-col min-w-0 overflow-y-auto">
    <main class="flex-1 p-6 md:p-10 max-w-[1400px] w-full mx-auto">
        <?php include '../includes/superadmin_header.php'; ?>

        <?php if ($success_msg): ?>
        <div class="mb-8 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-sm font-semibold flex items-center gap-3">
            <span class="material-symbols-outlined">check_circle</span>
            <?= htmlspecialchars($success_msg) ?>
        </div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
        <div class="mb-8 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-sm font-semibold flex items-center gap-3">
            <span class="material-symbols-outlined">error</span>
            <?= htmlspecialchars($error_msg) ?>
        </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-10">
            <div class="glass-card p-8 status-card-green relative overflow-hidden group">
                <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform">payments</span>
                <p class="text-[10px] font-black uppercase text-gray-400 mb-2 tracking-widest">Global Revenue</p>
                <h3 class="text-2xl font-black italic uppercase">₱<?= number_format($total_revenue, 2) ?></h3>
                <p class="text-emerald-500 text-[10px] font-black uppercase mt-2">Across All Tenants</p>
            </div>
            <div class="glass-card p-8 status-card-yellow relative overflow-hidden group">
                <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform">business</span>
                <p class="text-[10px] font-black uppercase text-gray-400 mb-2 tracking-widest">Active Tenants</p>
                <h3 class="text-2xl font-black italic uppercase"><?= $active_tenants ?> Gyms</h3>
                <p class="text-amber-500 text-[10px] font-black uppercase mt-2">Live Subscriptions</p>
            </div>
            <div class="glass-card p-6 relative overflow-hidden group border border-white/5 bg-white/5">
                <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform">groups</span>
                <p class="text-[10px] font-black uppercase text-gray-400 mb-2 tracking-widest">Active Users</p>
                <h3 class="text-2xl font-black italic uppercase text-emerald-400"><?= number_format($active_users) ?></h3>
                <p class="text-gray-500 text-[10px] font-black uppercase mt-2"><?= number_format($inactive_users) ?> Inactive</p>
            </div>
            <div class="glass-card p-8 relative overflow-hidden group border border-amber-500/20 bg-amber-500/5">
                <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-amber-500">pending_actions</span>
                <p class="text-[10px] font-black uppercase text-amber-500/70 mb-2 tracking-widest">Pending Apps</p>
                <h3 class="text-2xl font-black italic uppercase text-amber-400"><?= $pending_apps_count ?></h3>
                <p class="text-amber-500 text-[10px] font-black uppercase mt-2">Action Required</p>
            </div>
            <div class="glass-card p-8 relative overflow-hidden group border border-white/5 bg-white/5">
                <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-red-500">notifications_active</span>
                <p class="text-[10px] font-black uppercase text-gray-400 mb-2 tracking-widest">System Alerts</p>
                <h3 class="text-2xl font-black italic uppercase">Active</h3>
                <p class="text-red-500 text-[10px] font-black uppercase mt-2">Urgent Notifications</p>
            </div>
            <div class="glass-card p-6 relative overflow-hidden group border border-white/5 bg-white/5">
                <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-primary">analytics</span>
                <p class="text-[10px] font-black uppercase text-gray-400 mb-2 tracking-widest">System Activity</p>
                <h3 class="text-2xl font-black italic uppercase"><?= number_format($monthly_activity) ?></h3>
                <p class="text-primary text-[10px] font-black uppercase mt-2"><?= $daily_activity ?> Logins Today</p>
            </div>
            <div class="glass-card p-8 relative overflow-hidden group border border-white/5 bg-white/5">
                <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-emerald-500">history_edu</span>
                <p class="text-[10px] font-black uppercase text-gray-400 mb-2 tracking-widest">Subscriptions</p>
                <h3 class="text-2xl font-black italic uppercase">Active Logs</h3>
                <p class="text-emerald-500 text-[10px] font-black uppercase mt-2">Monitoring Health</p>
            </div>
            <div class="glass-card p-8 relative overflow-hidden group border border-white/5 bg-white/5">
                <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-amber-500">security</span>
                <p class="text-[10px] font-black uppercase text-gray-400 mb-2 tracking-widest">Access Control</p>
                <h3 class="text-2xl font-black italic uppercase">RBAC Active</h3>
                <p class="text-amber-500 text-[10px] font-black uppercase mt-2">Security Managed</p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-10">
            <div class="glass-card p-6">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-[10px] font-black italic uppercase tracking-widest">Sales Trend (Weekly)</h3>
                </div>
                <div class="h-[250px]">
                    <canvas id="salesTrendsChart"></canvas>
                </div>
            </div>
            <div class="glass-card p-6">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-[10px] font-black italic uppercase tracking-widest">User Growth (Monthly)</h3>
                </div>
                <div class="h-[250px]">
                    <canvas id="userGrowthChart"></canvas>
                </div>
            </div>
        </div>

        <div class="glass-card overflow-hidden mb-10">
            <div class="px-8 py-6 border-b border-white/5 bg-white/5 flex justify-between items-center">
                <h4 class="font-black italic uppercase text-sm tracking-tighter">Gym Applications <span class="text-primary">&</span> Tenant Activity</h4>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-background-dark/50 text-gray-500 text-[10px] font-black uppercase tracking-widest">
                            <th class="px-8 py-4">Gym Name</th>
                            <th class="px-8 py-4">Applicant</th>
                            <th class="px-8 py-4">Applied Date</th>
                            <th class="px-8 py-4">Status</th>
                            <th class="px-8 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <?php if (empty($recent_applications)): ?>
                            <tr>
                                <td colspan="5" class="px-8 py-8 text-center text-xs font-bold text-gray-500 italic uppercase">No recent applications found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recent_applications as $app): ?>
                                <tr class="hover:bg-white/5 transition-all">
                                    <td class="px-8 py-5">
                                        <div class="flex items-center gap-3">
                                            <div class="size-10 rounded-lg bg-white/5 flex items-center justify-center overflow-hidden border border-white/5 shadow-inner shrink-0">
                                                <?php if (!empty($app['logo_path']) && $app['logo_path'] !== 'pending'): 
                                                    $logo_src = (strpos($app['logo_path'], 'data:image') === 0) ? $app['logo_path'] : '../' . $app['logo_path'];
                                                ?>
                                                    <img src="<?= $logo_src ?>" class="size-full object-contain">
                                                <?php else: ?>
                                                    <span class="text-primary font-black text-xs"><?= strtoupper(substr($app['gym_name'], 0, 2)) ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <p class="text-sm font-bold italic"><?= htmlspecialchars($app['gym_name']) ?></p>
                                                <p class="text-[10px] text-gray-500 uppercase tracking-wider font-bold"><?= htmlspecialchars($app['business_type']) ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-8 py-5">
                                        <p class="text-xs font-medium text-white"><?= htmlspecialchars($app['first_name'] . ' ' . $app['last_name']) ?></p>
                                        <p class="text-[10px] text-gray-500"><?= htmlspecialchars($app['email']) ?></p>
                                    </td>
                                    <td class="px-8 py-5 text-xs font-medium text-gray-400">
                                        <?= date('M d, Y h:i A', strtotime($app['submitted_at'])) ?>
                                    </td>
                                    <td class="px-8 py-5">
                                        <?php if ($app['application_status'] === 'Pending'): ?>
                                            <span class="px-3 py-1 rounded-full bg-amber-500/10 border border-amber-500/20 text-[9px] text-amber-500 font-black uppercase italic">Pending</span>
                                        <?php elseif ($app['application_status'] === 'Approved'): ?>
                                            <span class="px-3 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-[9px] text-emerald-500 font-black uppercase italic">Approved</span>
                                        <?php else: ?>
                                            <span class="px-3 py-1 rounded-full bg-red-500/10 border border-red-500/20 text-[9px] text-red-500 font-black uppercase italic">Rejected</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-8 py-5 text-right">
                                        <?php if ($app['application_status'] === 'Pending'): ?>
                                            <div class="inline-flex gap-2">
                                                <a href="view_application.php?id=<?= $app['application_id'] ?>" class="px-4 py-1.5 rounded-lg bg-white/5 hover:bg-white/10 border border-white/5 text-gray-400 text-[10px] font-black uppercase tracking-widest transition-colors flex items-center gap-1">
                                                    <span class="material-symbols-outlined text-sm">visibility</span> View
                                                </a>
                                                <form method="POST" action="../action/process_application.php" class="inline-flex gap-2">
                                                    <input type="hidden" name="application_id" value="<?= $app['application_id'] ?>">
                                                    <button type="submit" name="action" value="approve" class="px-4 py-1.5 rounded-lg bg-emerald-500 hover:bg-emerald-600 text-white text-[10px] font-black uppercase tracking-widest transition-colors">
                                                        Approve
                                                    </button>
                                                    <button type="submit" name="action" value="reject" class="px-4 py-1.5 rounded-lg bg-red-500/10 hover:bg-red-500/20 border border-red-500/20 text-red-400 text-[10px] font-black uppercase tracking-widest transition-colors">
                                                        Reject
                                                    </button>
                                                </form>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-[10px] font-black text-gray-500 uppercase italic">Reviewed</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>
    <?php include '../includes/image_viewer.php'; ?>
    
    <script>
        // Sales Trends Chart
        const salesTrendsCtx = document.getElementById('salesTrendsChart').getContext('2d');
        new Chart(salesTrendsCtx, {
            type: 'line',
            data: {
                labels: [<?php foreach($sales_trend_data as $d) echo "'" . $d['day'] . "',"; ?>],
                datasets: [{
                    label: 'Revenue',
                    data: [<?php foreach($sales_trend_data as $d) echo $d['total'] . ","; ?>],
                    borderColor: '#8c2bee',
                    backgroundColor: 'rgba(140, 43, 238, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { ticks: { color: '#666', font: { size: 9 } }, grid: { display: false } },
                    y: { ticks: { color: '#666', font: { size: 9 } }, grid: { color: 'rgba(255,255,255,0.05)' } }
                }
            }
        });

        // User Growth Chart
        const userGrowthCtx = document.getElementById('userGrowthChart').getContext('2d');
        new Chart(userGrowthCtx, {
            type: 'bar',
            data: {
                labels: [<?php foreach($user_growth_data as $d) echo "'" . $d['month'] . "',"; ?>],
                datasets: [{
                    label: 'Signups',
                    data: [<?php foreach($user_growth_data as $d) echo $d['count'] . ","; ?>],
                    backgroundColor: '#7f13ec',
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { ticks: { color: '#666', font: { size: 9 } }, grid: { display: false } },
                    y: { ticks: { color: '#666', font: { size: 9 } }, grid: { color: 'rgba(255,255,255,0.05)' } }
                }
            }
        });
    </script>
</body>
</html>