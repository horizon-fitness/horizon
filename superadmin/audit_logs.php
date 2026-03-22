<?php 
session_start();
require_once '../db.php';

// Security Check: Only Superadmin can access
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'superadmin') {
    header("Location: ../login.php");
    exit;
}

$page_title = "Audit Logs";
$active_page = "audit_logs"; 

// Get Filter Inputs
$search = $_GET['search'] ?? '';
$action_filter = $_GET['action_type'] ?? 'all';

// Logic for fetching logs (Assuming an 'audit_logs' table exists)
// Required Columns: log_id, user_id, action_details, action_type, ip_address, created_at
$query = "SELECT al.*, u.first_name, u.last_name, u.role 
          FROM audit_logs al 
          JOIN users u ON al.user_id = u.user_id 
          WHERE 1=1";

$params = [];

if ($action_filter !== 'all') {
    $query .= " AND al.action_type = :type";
    $params['type'] = $action_filter;
}

if (!empty($search)) {
    $query .= " AND (al.action_details LIKE :search OR u.first_name LIKE :search OR u.last_name LIKE :search)";
    $params['search'] = "%$search%";
}

$query .= " ORDER BY al.created_at DESC LIMIT 50";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title><?= $page_title ?> | Horizon System</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
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
        .bg-card { background-color: rgba(255, 255, 255, 0.03); border: 1px solid rgba(255, 255, 255, 0.05); }
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

        <div class="mt-8 p-6 bg-card rounded-[24px] flex flex-wrap items-center justify-between gap-6">
            <form method="GET" class="flex flex-wrap items-center gap-4 w-full md:w-auto">
                <div class="relative">
                    <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 text-sm">search</span>
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search logs or users..." 
                           class="bg-[#0c0c0c] border border-white/10 rounded-xl pl-10 pr-4 py-2 text-xs text-white focus:border-primary focus:outline-none w-64">
                </div>
                <select name="action_type" class="bg-[#0c0c0c] border border-white/10 rounded-xl px-4 py-2 text-xs text-white focus:border-primary focus:outline-none">
                    <option value="all">All Activities</option>
                    <option value="Login" <?= $action_filter == 'Login' ? 'selected' : '' ?>>Login/Logout</option>
                    <option value="Create" <?= $action_filter == 'Create' ? 'selected' : '' ?>>Create Actions</option>
                    <option value="Update" <?= $action_filter == 'Update' ? 'selected' : '' ?>>Update Actions</option>
                    <option value="Delete" <?= $action_filter == 'Delete' ? 'selected' : '' ?>>Delete Actions</option>
                    <option value="Tenant" <?= $action_filter == 'Tenant' ? 'selected' : '' ?>>Tenant Changes</option>
                </select>
                <button type="submit" class="h-9 px-6 rounded-lg bg-white/5 hover:bg-white/10 border border-white/10 text-[10px] font-black uppercase tracking-widest transition-all">Apply Filter</button>
            </form>
            
            <button class="px-6 py-2 rounded-xl bg-primary/10 border border-primary/20 text-primary text-[10px] font-black uppercase italic tracking-widest hover:bg-primary hover:text-black transition-all">
                Export Audit Trail
            </button>
        </div>

        <div class="mt-8 bg-card rounded-[32px] overflow-hidden">
            <div class="px-8 py-6 border-b border-white/5 bg-white/[0.01]">
                <h3 class="text-lg font-black italic uppercase text-white tracking-tighter">System Audit Trail</h3>
                <p class="text-[10px] text-gray-500 font-bold uppercase tracking-widest">Tracking all administrative and security events</p>
            </div>
            <table class="w-full text-left">
                <thead>
                    <tr class="bg-white/[0.02]">
                        <th class="px-8 py-4 text-[10px] font-black uppercase tracking-widest text-gray-500">Timestamp</th>
                        <th class="px-8 py-4 text-[10px] font-black uppercase tracking-widest text-gray-500">User / Role</th>
                        <th class="px-8 py-4 text-[10px] font-black uppercase tracking-widest text-gray-500">Event Action</th>
                        <th class="px-8 py-4 text-[10px] font-black uppercase tracking-widest text-gray-500">Details</th>
                        <th class="px-8 py-4 text-[10px] font-black uppercase tracking-widest text-gray-500 text-right">IP Address</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php if (empty($logs)): ?>
                        <tr><td colspan="5" class="px-8 py-12 text-center text-xs text-gray-600 italic uppercase font-bold tracking-widest">No audit records found for the selected criteria</td></tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                        <tr class="hover:bg-white/[0.01] transition-colors">
                            <td class="px-8 py-5">
                                <p class="text-[11px] font-bold text-white uppercase"><?= date('M d, Y', strtotime($log['created_at'])) ?></p>
                                <p class="text-[9px] text-gray-500 font-black italic"><?= date('h:i:s A', strtotime($log['created_at'])) ?></p>
                            </td>
                            <td class="px-8 py-5">
                                <p class="text-xs font-bold text-white"><?= htmlspecialchars($log['first_name'] . ' ' . $log['last_name']) ?></p>
                                <p class="text-[9px] text-primary font-black uppercase tracking-tighter italic"><?= htmlspecialchars($log['role']) ?></p>
                            </td>
                            <td class="px-8 py-5">
                                <?php 
                                    $color = 'gray-500';
                                    if(in_array($log['action_type'], ['Create', 'Login'])) $color = 'emerald-500';
                                    if($log['action_type'] === 'Delete') $color = 'red-500';
                                    if($log['action_type'] === 'Update') $color = 'amber-500';
                                ?>
                                <span class="px-2.5 py-1 rounded-md bg-<?= $color ?>/10 border border-<?= $color ?>/20 text-[9px] text-<?= $color ?> font-black uppercase italic">
                                    <?= htmlspecialchars($log['action_type']) ?>
                                </span>
                            </td>
                            <td class="px-8 py-5">
                                <p class="text-xs text-gray-400 max-w-md"><?= htmlspecialchars($log['action_details']) ?></p>
                            </td>
                            <td class="px-8 py-5 text-right">
                                <p class="text-[10px] font-black text-gray-600 tracking-widest"><?= htmlspecialchars($log['ip_address']) ?></p>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>
</body>
</html>