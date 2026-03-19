<?php 
session_start();
require_once '../db.php';

// Security Check: Only Superadmin can access
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'superadmin') {
    header("Location: ../login.php");
    exit;
}

$page_title = "Super Admin Dashboard";
$active_page = "dashboard";

// Application messages handled via session
$success_msg = $_SESSION['success_msg'] ?? '';
$error_msg = $_SESSION['error_msg'] ?? '';
unset($_SESSION['success_msg'], $_SESSION['error_msg']);

// Fetch Real Dynamic Data for Dashboard
$total_revenue = 0.00; // Place holder for billing later

$stmtTenants = $pdo->query("SELECT COUNT(*) FROM gyms WHERE status = 'Active'");
$active_tenants = $stmtTenants->fetchColumn();

$stmtUsers = $pdo->query("SELECT COUNT(*) FROM users");
$total_users = $stmtUsers->fetchColumn();

$stmtPending = $pdo->query("SELECT COUNT(*) FROM gym_owner_applications WHERE application_status = 'Pending'");
$pending_apps_count = $stmtPending->fetchColumn();

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
    <title><?php echo $page_title ?? 'Super Admin Dashboard'; ?> | Horizon System</title>
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

<nav class="sidebar-nav flex flex-col bg-[#0a090d] border-r border-white/5 sticky top-0 h-screen px-7 py-8 z-50 shrink-0">
    <div class="mb-12">
        <div class="flex items-center gap-4 mb-6">
            <div class="size-10 rounded-xl bg-[#7f13ec] flex items-center justify-center shadow-lg shrink-0">
                <span class="material-symbols-outlined text-white text-2xl">bolt</span>
            </div>
            <h1 class="nav-text text-xl font-black italic uppercase tracking-tighter text-white">Horizon System</h1>
        </div>
    </div>
    
    <div class="flex flex-col gap-8 flex-1 overflow-y-auto no-scrollbar pr-2">
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
    </div>

    <div class="mt-auto pt-8 border-t border-white/10 flex flex-col gap-8">
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

<div class="flex-1 flex flex-col min-w-0 overflow-y-auto">
    <main class="flex-1 p-6 md:p-10 max-w-[1400px] w-full mx-auto">
        <header class="mb-10 flex flex-row justify-between items-end gap-6">
            <div>
                <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white">System <span class="text-primary">Overview</span></h2>
                <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-1">Super Admin Control Center</p>
            </div>
            <div class="text-right">
                <p id="headerClock" class="text-white font-black italic text-3xl leading-none mb-1">00:00:00 AM</p>
                <p class="text-primary text-[10px] font-black uppercase tracking-[0.2em]"><?= date('l, M d, Y') ?></p>
            </div>
        </header>

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
            <div class="glass-card p-8 relative overflow-hidden group border border-white/5 bg-white/5">
                <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform">groups</span>
                <p class="text-[10px] font-black uppercase text-gray-400 mb-2 tracking-widest">Total Users</p>
                <h3 class="text-2xl font-black italic uppercase"><?= number_format($total_users) ?></h3>
                <p class="text-primary text-[10px] font-black uppercase mt-2">Network Growth</p>
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
            <div class="glass-card p-8 relative overflow-hidden group border border-white/5 bg-white/5">
                <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-primary">group</span>
                <p class="text-[10px] font-black uppercase text-gray-400 mb-2 tracking-widest">Total Occupancy</p>
                <h3 class="text-2xl font-black italic uppercase">Live</h3>
                <p class="text-primary text-[10px] font-black uppercase mt-2">Real-time Monitoring</p>
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
            <div class="glass-card p-8">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-sm font-black italic uppercase tracking-widest">Revenue Analytics</h3>
                    <span class="text-[10px] font-black text-gray-500 uppercase tracking-widest">Last 30 Days</span>
                </div>
                <div class="h-[300px] flex items-center justify-center border border-white/5 rounded-2xl bg-white/[0.02] relative overflow-hidden">
                    <p class="text-gray-600 text-[10px] font-black uppercase italic tracking-widest z-10">Revenue Chart Visualization</p>
                    <div class="absolute bottom-0 left-0 w-full h-1/2 bg-gradient-to-t from-primary/5 to-transparent"></div>
                </div>
            </div>
            <div class="glass-card p-8">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-sm font-black italic uppercase tracking-widest">Tenant Growth</h3>
                    <span class="text-[10px] font-black text-gray-500 uppercase tracking-widest">Monthly Onboarding</span>
                </div>
                <div class="h-[300px] flex items-center justify-center border border-white/5 rounded-2xl bg-white/[0.02] relative overflow-hidden">
                    <p class="text-gray-600 text-[10px] font-black uppercase italic tracking-widest z-10">Growth Chart Visualization</p>
                    <div class="absolute bottom-0 left-0 w-full h-1/2 bg-gradient-to-t from-primary/5 to-transparent"></div>
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
</body>
</html>