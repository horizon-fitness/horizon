<?php 
session_start();
require_once '../db.php';

// Security Check: Only Superadmin can access
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'superadmin') {
    header("Location: ../login.php");
    exit;
}

$page_title = "Tenant Management";
$active_page = "tenants"; 

$stmtTenants = $pdo->query("
    SELECT g.*, 
           u.first_name, u.last_name, u.email as owner_email,
           tp.logo_path,
           cs.subscription_status as sub_status,
           wp.plan_name,
           IFNULL(m.member_count, 0) as member_count
    FROM gyms g
    JOIN users u ON g.owner_user_id = u.user_id
    LEFT JOIN tenant_pages tp ON g.gym_id = tp.gym_id
    LEFT JOIN (
        SELECT cs1.gym_id, cs1.subscription_status, cs1.website_plan_id
        FROM client_subscriptions cs1
        INNER JOIN (
            SELECT gym_id, MAX(created_at) as max_created
            FROM client_subscriptions
            GROUP BY gym_id
        ) cs2 ON cs1.gym_id = cs2.gym_id AND cs1.created_at = cs2.max_created
    ) cs ON g.gym_id = cs.gym_id
    LEFT JOIN website_plans wp ON cs.website_plan_id = wp.website_plan_id
    LEFT JOIN (
        SELECT gym_id, COUNT(*) as member_count 
        FROM members 
        GROUP BY gym_id
    ) m ON g.gym_id = m.gym_id
    WHERE g.status != 'Deleted'
    ORDER BY g.created_at DESC
");
$tenants = $stmtTenants->fetchAll(PDO::FETCH_ASSOC);

// Fetch Total Members across all tenants
$stmtTotalMembers = $pdo->query("SELECT COUNT(*) FROM members");
$total_members_count = $stmtTotalMembers->fetchColumn();

// Fetch Existing Tenant Links for the Linking Tab
$tenant_links = [];
try {
    $stmtLinks = $pdo->query("
        SELECT gl.link_id, gl.created_at,
               g1.gym_name as primary_name, g1.tenant_code as primary_code,
               g2.gym_name as secondary_name, g2.tenant_code as secondary_code
        FROM gym_links gl
        JOIN gyms g1 ON gl.primary_id = g1.gym_id
        JOIN gyms g2 ON gl.secondary_id = g2.gym_id
        ORDER BY gl.created_at DESC
    ");
    if ($stmtLinks) {
        $tenant_links = $stmtLinks->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    // Table might not exist yet
    $tenant_links = [];
}

// Fetch All Active Gyms for the Linking Dropdowns
$stmtActiveGyms = $pdo->query("SELECT gym_id, gym_name, tenant_code FROM gyms WHERE status = 'Active' ORDER BY gym_name ASC");
$active_gyms = $stmtActiveGyms->fetchAll(PDO::FETCH_ASSOC);

$stmtPending = $pdo->query("
    SELECT a.*, u.first_name, u.last_name, u.email 
    FROM gym_owner_applications a 
    JOIN users u ON a.user_id = u.user_id 
    WHERE a.application_status = 'Pending'
    ORDER BY a.submitted_at DESC
");
$pending_apps = $stmtPending->fetchAll(PDO::FETCH_ASSOC);

$total_tenants = count($tenants);
$active_count = 0;
$suspended_count = 0;
$pending_count = count($pending_apps);

foreach ($tenants as $t) {
    if ($t['status'] === 'Active') $active_count++;
    if ($t['status'] === 'Suspended') $suspended_count++;
}
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title><?= $page_title ?> | Horizon System</title>
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
        :root { --sidebar-width: 110px; }
        .sidebar-nav {
            width: var(--sidebar-width);
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
        }
        .sidebar-nav:hover {
            --sidebar-width: 300px;
        }
        #applicationModal, #modalBackdrop {
            left: var(--sidebar-width) !important;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        #applicationModal {
            padding-left: 5rem; /* Significant breathing room (80px) from the nav */
            padding-right: 5rem;
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

<nav class="sidebar-nav flex flex-col bg-[#0a090d] border-r border-white/5 sticky top-0 h-screen px-7 py-8 z-[110] shrink-0">
    <div class="mb-12">
        <div class="flex items-center gap-4 mb-6">
            <div class="size-10 rounded-xl bg-[#7f13ec] flex items-center justify-center shadow-lg shrink-0">
                <span class="material-symbols-outlined text-white text-2xl">bolt</span>
            </div>
            <h1 class="nav-text text-xl font-black italic uppercase tracking-tighter text-white">Horizon System</h1>
        </div>
    </div>
    
    <div class="flex flex-col gap-6 flex-1 overflow-y-auto no-scrollbar pr-2 pb-10">
        <a href="superadmin_dashboard.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'dashboard') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-2xl shrink-0">grid_view</span> 
            <span class="nav-text">Dashboard</span>
        </a>
        
        <a href="tenant_management.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'tenants') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-2xl shrink-0">business</span> 
            <span class="nav-text">Tenant Management</span>
        </a>

        <a href="subscription_logs.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'subscriptions') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-2xl shrink-0">history_edu</span> 
            <span class="nav-text">Subscription Logs</span>
        </a>

        <a href="rbac_management.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'rbac') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-2xl shrink-0">security</span> 
            <span class="nav-text">Access Control</span>
        </a>

        <a href="real_time_occupancy.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'occupancy') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-2xl shrink-0">group</span> 
            <span class="nav-text">Real-Time Occupancy</span>
        </a>

        <a href="recent_transaction.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'transactions') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-2xl shrink-0">receipt_long</span> 
            <span class="nav-text">Recent Transactions</span>
        </a>

        <a href="system_alerts.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'alerts') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-2xl shrink-0">notifications_active</span> 
            <span class="nav-text">System Alerts</span>
        </a>

        <a href="system_reports.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'reports') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-2xl shrink-0">analytics</span> 
            <span class="nav-text">Reports</span>
        </a>

        <a href="sales_report.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'sales_report') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-2xl shrink-0">monitoring</span> 
            <span class="nav-text">Sales Reports</span>
        </a>

        <a href="audit_logs.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'audit_logs') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-2xl shrink-0">assignment</span> 
            <span class="nav-text">Audit Logs</span>
        </a>

        <a href="settings.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'settings') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
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

<div class="flex-1 flex flex-col min-w-0 overflow-y-auto">
    <main class="flex-1 p-6 md:p-10 max-w-[1400px] w-full mx-auto">

        <header class="mb-10 flex flex-row justify-between items-end gap-6">
            <div>
                <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white">Tenant <span class="text-primary">Management</span></h2>
                <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-1">Manage Gym Accounts & Subscriptions</p>
            </div>
            
            <div class="text-right">
                <p id="headerClock" class="text-white font-black italic text-xl tracking-tight leading-none mb-2">00:00:00 AM</p>
                <p class="text-primary text-[9px] font-black uppercase tracking-[0.2em] opacity-80"><?= date('l, M d, Y') ?></p>
            </div>
        </header>

        <?php if (isset($_SESSION['success_msg'])): ?>
            <div class="mb-8 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-sm font-semibold flex items-center gap-3">
                <span class="material-symbols-outlined">check_circle</span>
                <?= htmlspecialchars($_SESSION['success_msg']) ?>
            </div>
            <?php unset($_SESSION['success_msg']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_msg'])): ?>
            <div class="mb-8 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-sm font-semibold flex items-center gap-3">
                <span class="material-symbols-outlined">error</span>
                <?= htmlspecialchars($_SESSION['error_msg']) ?>
            </div>
            <?php unset($_SESSION['error_msg']); ?>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-5 gap-6 mb-10">
            <div class="glass-card p-6 border border-white/5 bg-white/5 flex items-center gap-4">
                <div class="size-12 rounded-full bg-white/10 flex items-center justify-center text-white">
                    <span class="material-symbols-outlined text-2xl">domain</span>
                </div>
                <div>
                    <p class="text-[10px] font-black uppercase text-gray-400 tracking-widest">Total Tenants</p>
                    <h3 class="text-2xl font-black italic uppercase"><?= $total_tenants ?></h3>
                </div>
            </div>
            <div class="glass-card p-6 border border-emerald-500/20 bg-emerald-500/5 flex items-center gap-4">
                <div class="size-12 rounded-full bg-emerald-500/20 flex items-center justify-center text-emerald-500">
                    <span class="material-symbols-outlined text-2xl">check_circle</span>
                </div>
                <div>
                    <p class="text-[10px] font-black uppercase text-emerald-500/70 tracking-widest">Active Gyms</p>
                    <h3 class="text-2xl font-black italic uppercase text-emerald-400"><?= $active_count ?></h3>
                </div>
            </div>
            <div class="glass-card p-6 border border-primary/20 bg-primary/5 flex items-center gap-4">
                <div class="size-12 rounded-full bg-primary/20 flex items-center justify-center text-primary">
                    <span class="material-symbols-outlined text-2xl">groups</span>
                </div>
                <div>
                    <p class="text-[10px] font-black uppercase text-primary/70 tracking-widest">Total Members</p>
                    <h3 class="text-2xl font-black italic uppercase text-primary"><?= number_format($total_members_count) ?></h3>
                </div>
            </div>
            <div class="glass-card p-6 border border-amber-500/20 bg-amber-500/5 flex items-center gap-4">
                <div class="size-12 rounded-full bg-amber-500/20 flex items-center justify-center text-amber-500">
                    <span class="material-symbols-outlined text-2xl">pending_actions</span>
                </div>
                <div>
                    <p class="text-[10px] font-black uppercase text-amber-500/70 tracking-widest">Pending Apps</p>
                    <h3 class="text-2xl font-black italic uppercase text-amber-400"><?= $pending_count ?></h3>
                </div>
            </div>
            <div class="glass-card p-6 border border-red-500/20 bg-red-500/5 flex items-center gap-4">
                <div class="size-12 rounded-full bg-red-500/20 flex items-center justify-center text-red-500">
                    <span class="material-symbols-outlined text-2xl">pause_circle</span>
                </div>
                <div>
                    <p class="text-[10px] font-black uppercase text-red-500/70 tracking-widest">Suspended</p>
                    <h3 class="text-2xl font-black italic uppercase text-red-400"><?= $suspended_count ?></h3>
                </div>
            </div>
        </div>
        
        <div class="flex items-center gap-8 mb-8 border-b border-white/5 px-2">
            <button onclick="switchTab('registered')" id="tabBtn-registered" class="pb-4 text-xs font-black uppercase tracking-widest transition-all relative group text-primary">
                Registered Gyms
                <div id="tabIndicator-registered" class="absolute bottom-0 left-0 right-0 h-0.5 bg-primary rounded-full transition-all opacity-100"></div>
            </button>
            <button onclick="switchTab('pending')" id="tabBtn-pending" class="pb-4 text-xs font-black uppercase tracking-widest transition-all relative group text-gray-500 hover:text-white">
                Pending Applications
                <div id="tabIndicator-pending" class="absolute bottom-0 left-0 right-0 h-0.5 bg-primary rounded-full transition-all opacity-0"></div>
                <?php if ($pending_count > 0): ?>
                    <span class="absolute -top-1 -right-4 size-4 bg-amber-500 text-[8px] font-black text-white flex items-center justify-center rounded-full shadow-lg shadow-amber-500/20"><?= $pending_count ?></span>
                <?php endif; ?>
            </button>
            <button onclick="switchTab('linking')" id="tabBtn-linking" class="pb-4 text-xs font-black uppercase tracking-widest transition-all relative group text-gray-500 hover:text-white">
                Tenant Linking
                <div id="tabIndicator-linking" class="absolute bottom-0 left-0 right-0 h-0.5 bg-primary rounded-full transition-all opacity-0"></div>
            </button>
        </div>

        <div id="section-pending" class="hidden">
        <?php if (!empty($pending_apps)): ?>
        <div class="glass-card overflow-hidden mb-10 border border-amber-500/10">
            <div class="px-8 py-6 border-b border-white/5 bg-amber-500/5 flex justify-between items-center">
                <h4 class="font-black italic uppercase text-sm tracking-tighter text-amber-400 flex items-center gap-2">
                    <span class="material-symbols-outlined">pending_actions</span>
                    Pending Gym Applications
                </h4>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-background-dark/50 text-gray-500 text-[10px] font-black uppercase tracking-widest">
                            <th class="px-8 py-4">Gym Name</th>
                            <th class="px-8 py-4">Applicant</th>
                            <th class="px-8 py-4">Applied Date</th>
                            <th class="px-8 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <?php foreach ($pending_apps as $app): ?>
                            <tr class="hover:bg-white/5 transition-all">
                                <td class="px-8 py-5">
                                    <div class="flex items-center gap-3">
                                        <div class="size-10 rounded-lg bg-amber-500/10 flex items-center justify-center font-black text-amber-500 text-sm border border-amber-500/20">
                                            <?= strtoupper(substr($app['gym_name'], 0, 2)) ?>
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
                                <td class="px-8 py-5 text-right">
                                    <div class="inline-flex gap-2">
                                        <button onclick="openApplicationModal(<?= $app['application_id'] ?>)" class="px-4 py-1.5 rounded-lg bg-white/5 hover:bg-white/10 border border-white/5 text-gray-400 text-[10px] font-black uppercase tracking-widest transition-colors flex items-center gap-1">
                                            <span class="material-symbols-outlined text-sm">visibility</span> View
                                        </button>
                                        <form method="POST" action="action/process_application.php" class="inline-flex gap-2">
                                            <input type="hidden" name="application_id" value="<?= $app['application_id'] ?>">
                                            <button type="submit" name="action" value="approve" class="px-4 py-1.5 rounded-lg bg-emerald-500 hover:bg-emerald-600 text-white text-[10px] font-black uppercase tracking-widest transition-colors flex items-center gap-1">
                                                <span class="material-symbols-outlined text-sm">check_circle</span> Approve
                                            </button>
                                            <button type="submit" name="action" value="reject" class="px-4 py-1.5 rounded-lg bg-red-500/10 hover:bg-red-500/20 border border-red-500/20 text-red-400 text-[10px] font-black uppercase tracking-widest transition-colors flex items-center gap-1">
                                                <span class="material-symbols-outlined text-sm">cancel</span> Reject
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php else: ?>
            <div class="glass-card p-12 text-center border border-white/5 bg-white/5 rounded-[32px]">
                <span class="material-symbols-outlined text-4xl text-gray-600 mb-4 uppercase">check_circle</span>
                <p class="text-xs font-black uppercase text-gray-500 tracking-widest">All caught up! No pending applications.</p>
            </div>
        <?php endif; ?>
        </div>

        <div id="section-registered">
            <div class="glass-card overflow-hidden">
                <div class="px-8 py-6 border-b border-white/5 bg-white/5 flex justify-between items-center">
                    <h4 class="font-black italic uppercase text-sm tracking-tighter">Registered Gyms</h4>
                </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-background-dark/50 text-gray-500 text-[10px] font-black uppercase tracking-widest">
                            <th class="px-8 py-4">Gym Identity</th>
                            <th class="px-8 py-4">Owner Contact</th>
                            <th class="px-8 py-4">Plan & Status</th>
                            <th class="px-8 py-4">Members</th>
                            <th class="px-8 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <?php if (empty($tenants)): ?>
                            <tr>
                                <td colspan="5" class="px-8 py-8 text-center text-xs font-bold text-gray-500 italic uppercase">No active tenants found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($tenants as $t): ?>
                                <tr class="hover:bg-white/5 transition-all">
                                    <td class="px-8 py-5">
                                        <div class="flex items-center gap-3">
                                            <div class="size-16 rounded-xl bg-white/5 flex items-center justify-center overflow-hidden border border-white/5 shadow-inner shrink-0">
                                                <?php if (!empty($t['logo_path'])): 
                                                    $logo_src = (strpos($t['logo_path'], 'data:image') === 0) ? $t['logo_path'] : '../' . $t['logo_path'];
                                                ?>
                                                    <img src="<?= $logo_src ?>" class="size-full object-contain transition-transform hover:scale-110">
                                                <?php else: ?>
                                                    <span class="text-primary font-black text-sm"><?= strtoupper(substr($t['gym_name'], 0, 2)) ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <p class="text-sm font-bold italic"><?= htmlspecialchars($t['gym_name']) ?></p>
                                                <p class="text-[10px] text-primary uppercase tracking-wider font-bold">Code: <?= htmlspecialchars($t['tenant_code']) ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-8 py-5">
                                        <p class="text-xs font-medium text-white"><?= htmlspecialchars($t['first_name'] . ' ' . $t['last_name']) ?></p>
                                        <p class="text-[10px] text-gray-500"><?= htmlspecialchars($t['owner_email']) ?></p>
                                    </td>
                                    <td class="px-8 py-5">
                                        <div class="flex flex-col gap-1.5">
                                            <p class="text-[10px] font-black uppercase text-gray-400 tracking-widest"><?= htmlspecialchars($t['plan_name'] ?? 'No Plan') ?></p>
                                            <?php 
                                                $sub = $t['sub_status'] ?? 'None';
                                                if ($sub === 'Active'):
                                            ?>
                                                <span class="w-fit px-3 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-[9px] text-emerald-500 font-black uppercase italic">Active Plan</span>
                                            <?php elseif ($sub === 'Expired' || $sub === 'Overdue'): ?>
                                                <span class="w-fit px-3 py-1 rounded-full bg-red-500/10 border border-red-500/20 text-[9px] text-red-500 font-black uppercase italic">Payment Issue</span>
                                            <?php else: ?>
                                                <span class="w-fit px-3 py-1 rounded-full bg-gray-500/10 border border-gray-500/20 text-[9px] text-gray-400 font-black uppercase italic">No Active Subscription</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-8 py-5">
                                        <div class="flex flex-col gap-2">
                                            <div class="flex items-center gap-2">
                                                <span class="material-symbols-outlined text-sm text-primary">groups</span>
                                                <p class="text-xs font-black italic"><?= number_format($t['member_count']) ?></p>
                                            </div>
                                            <?php if ($t['status'] === 'Active'): ?>
                                                <div class="flex items-center gap-2 text-emerald-400">
                                                    <span class="relative flex size-2"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span><span class="relative inline-flex rounded-full size-2 bg-emerald-500"></span></span>
                                                    <span class="text-[9px] font-black uppercase italic tracking-wider">Active</span>
                                                </div>
                                            <?php elseif ($t['status'] === 'Suspended'): ?>
                                                <div class="flex items-center gap-2 text-amber-400">
                                                    <span class="relative inline-flex rounded-full size-2 bg-amber-500"></span>
                                                    <span class="text-[9px] font-black uppercase italic tracking-wider">Suspended</span>
                                                </div>
                                            <?php elseif ($t['status'] === 'Deactivated'): ?>
                                                <div class="flex items-center gap-2 text-red-400">
                                                    <span class="relative inline-flex rounded-full size-2 bg-red-500"></span>
                                                    <span class="text-[9px] font-black uppercase italic tracking-wider">Deactivated</span>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-[9px] font-black uppercase italic text-gray-500 tracking-wider"><?= htmlspecialchars($t['status']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-8 py-5 text-right">
                                        <div class="inline-flex gap-2">
                                            <?php if ($t['application_id']): ?>
                                                <button onclick="openApplicationModal(<?= $t['application_id'] ?>)" title="View Application Details" class="size-8 rounded-lg bg-white/5 hover:bg-white/10 border border-white/5 text-gray-400 flex items-center justify-center transition-colors">
                                                    <span class="material-symbols-outlined text-[18px]">visibility</span>
                                                </button>
                                            <?php endif; ?>

                                            <form method="POST" action="action/process_tenant.php" class="inline-flex gap-2" onsubmit="return confirm('Are you sure you want to proceed with this action?');">
                                                <input type="hidden" name="gym_id" value="<?= $t['gym_id'] ?>">
                                                
                                                <?php if ($t['status'] !== 'Active'): ?>
                                                    <button type="submit" name="action" value="activate" title="Activate Account" class="size-8 rounded-lg bg-emerald-500/10 hover:bg-emerald-500/20 border border-emerald-500/20 text-emerald-400 flex items-center justify-center transition-colors">
                                                        <span class="material-symbols-outlined text-[18px]">play_circle</span>
                                                    </button>
                                                <?php endif; ?>

                                                <?php if ($t['status'] === 'Active'): ?>
                                                    <button type="submit" name="action" value="suspend" title="Suspend Account (e.g. Unpaid Subscription)" class="size-8 rounded-lg bg-amber-500/10 hover:bg-amber-500/20 border border-amber-500/20 text-amber-400 flex items-center justify-center transition-colors">
                                                        <span class="material-symbols-outlined text-[18px]">pause_circle</span>
                                                    </button>
                                                <?php endif; ?>

                                                <?php if ($t['status'] !== 'Deactivated'): ?>
                                                    <button type="submit" name="action" value="deactivate" title="Deactivate Account" class="size-8 rounded-lg bg-red-500/10 hover:bg-red-500/20 border border-red-500/20 text-red-400 flex items-center justify-center transition-colors">
                                                        <span class="material-symbols-outlined text-[18px]">cancel</span>
                                                    </button>
                                                <?php endif; ?>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="px-8 py-4 border-t border-white/5 bg-white/[0.02] flex justify-between items-center">
                <p class="text-[10px] font-black uppercase text-gray-600 tracking-widest">Showing <?= count($tenants) ?> of <?= $total_tenants ?> gyms</p>
                <div class="flex gap-2">
                    <button class="size-8 rounded-lg bg-white/5 flex items-center justify-center text-gray-500 hover:text-white transition-all disabled:opacity-20" disabled>
                        <span class="material-symbols-outlined text-sm">chevron_left</span>
                    </button>
                    <button class="size-8 rounded-lg bg-primary flex items-center justify-center text-white transition-all font-black text-[10px]">1</button>
                    <button class="size-8 rounded-lg bg-white/5 flex items-center justify-center text-gray-500 hover:text-white transition-all font-black text-[10px]">2</button>
                    <button class="size-8 rounded-lg bg-white/5 flex items-center justify-center text-gray-500 hover:text-white transition-all">
                        <span class="material-symbols-outlined text-sm">chevron_right</span>
                    </button>
                </div>
            </div>
          </div>
        </div>

        <div id="section-linking" class="hidden">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Linking Form -->
                <div class="lg:col-span-1">
                    <div class="glass-card p-8 border border-white/5 bg-white/5 h-fit">
                        <div class="flex items-center gap-3 mb-6">
                            <span class="material-symbols-outlined text-primary">link</span>
                            <h4 class="font-black italic uppercase text-sm tracking-tighter">Link New Tenants</h4>
                        </div>
                        <form method="POST" action="process_tenant_link.php" class="flex flex-col gap-6">
                            <div>
                                <label class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-2 block">Primary Tenant</label>
                                <select name="primary_tenant" required class="w-full bg-background-dark border border-white/10 rounded-xl px-4 py-3 text-sm focus:border-primary focus:ring-1 focus:ring-primary outline-none transition-all cursor-pointer">
                                    <option value="">Select Primary...</option>
                                    <?php foreach ($active_gyms as $gym): ?>
                                        <option value="<?= $gym['gym_id'] ?>"><?= htmlspecialchars($gym['gym_name']) ?> (<?= $gym['tenant_code'] ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="flex justify-center -my-2 relative z-10">
                                <div class="size-8 rounded-full bg-primary/20 border border-primary/30 flex items-center justify-center text-primary">
                                    <span class="material-symbols-outlined text-sm">sync_alt</span>
                                </div>
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-2 block">Secondary Tenant</label>
                                <select name="secondary_tenant" required class="w-full bg-background-dark border border-white/10 rounded-xl px-4 py-3 text-sm focus:border-primary focus:ring-1 focus:ring-primary outline-none transition-all cursor-pointer">
                                    <option value="">Select Secondary...</option>
                                    <?php foreach ($active_gyms as $gym): ?>
                                        <option value="<?= $gym['gym_id'] ?>"><?= htmlspecialchars($gym['gym_name']) ?> (<?= $gym['tenant_code'] ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="w-full py-4 bg-primary hover:bg-primary/90 text-white text-[10px] font-black uppercase italic tracking-[0.2em] rounded-xl shadow-lg shadow-primary/20 transition-all active:scale-[0.98]">
                                Establish Link
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Existing Links Table -->
                <div class="lg:col-span-2">
                    <div class="glass-card overflow-hidden">
                        <div class="px-8 py-6 border-b border-white/5 bg-white/5">
                            <h4 class="font-black italic uppercase text-sm tracking-tighter">Established Connections</h4>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left">
                                <thead>
                                    <tr class="bg-background-dark/50 text-gray-500 text-[10px] font-black uppercase tracking-widest">
                                        <th class="px-8 py-4">Tied Accounts</th>
                                        <th class="px-8 py-4">Linked Date</th>
                                        <th class="px-8 py-4 text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-white/5">
                                    <?php if (empty($tenant_links)): ?>
                                        <tr>
                                            <td colspan="3" class="px-8 py-12 text-center text-xs font-bold text-gray-500 italic uppercase">No linked tenants found.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($tenant_links as $link): ?>
                                            <tr class="hover:bg-white/5 transition-all group">
                                                <td class="px-8 py-5">
                                                    <div class="flex items-center gap-4">
                                                        <div class="text-xs font-bold italic">
                                                            <span class="text-white"><?= htmlspecialchars($link['primary_name']) ?></span>
                                                            <span class="mx-2 text-primary opacity-50 font-black">↔</span>
                                                            <span class="text-white"><?= htmlspecialchars($link['secondary_name']) ?></span>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-8 py-5 text-xs text-gray-500 font-medium tracking-tight">
                                                    <?= date('M d, Y', strtotime($link['created_at'])) ?>
                                                </td>
                                                <td class="px-8 py-5 text-right">
                                                    <form method="POST" action="process_tenant_link.php" onsubmit="return confirm('Disconnect these tenants?');">
                                                        <input type="hidden" name="delete_link_id" value="<?= $link['link_id'] ?>">
                                                        <button type="submit" class="size-8 rounded-lg bg-red-500/10 hover:bg-red-500/20 border border-red-500/20 text-red-500 flex items-center justify-center transition-all opacity-0 group-hover:opacity-100">
                                                            <span class="material-symbols-outlined text-[18px]">link_off</span>
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        </div>
    </main>
</div>

<script>
    function switchTab(tabId) {
        const sections = ['pending', 'registered', 'linking'];
        
        sections.forEach(s => {
            const section = document.getElementById(`section-${s}`);
            const btn = document.getElementById(`tabBtn-${s}`);
            const indicator = document.getElementById(`tabIndicator-${s}`);
            
            if (s === tabId) {
                section.classList.remove('hidden');
                btn.classList.add('text-primary');
                btn.classList.remove('text-gray-500');
                indicator.classList.remove('opacity-0');
                indicator.classList.add('opacity-100');
            } else {
                section.classList.add('hidden');
                btn.classList.add('text-gray-500');
                btn.classList.remove('text-primary');
                indicator.classList.remove('opacity-100');
                indicator.classList.add('opacity-0');
            }
        });
    }

    // Auto-switch tab based on URL parameter
    window.addEventListener('DOMContentLoaded', () => {
        const urlParams = new URLSearchParams(window.location.search);
        const tab = urlParams.get('tab');
        if (tab && ['pending', 'registered', 'linking'].includes(tab)) {
            switchTab(tab);
        }
    });
</script>

<div id="applicationModal" class="fixed inset-y-0 right-0 z-[100] hidden items-center justify-center p-4 md:p-10 overflow-hidden pointer-events-none">
    <div class="fixed inset-y-0 right-0 bg-background-dark/40 backdrop-blur-xl transition-opacity duration-500 opacity-0 pointer-events-auto" id="modalBackdrop"></div>
    <div class="relative w-full max-w-4xl bg-surface-dark/80 backdrop-blur-3xl border border-white/10 shadow-2xl rounded-[32px] overflow-hidden flex flex-col max-h-[90vh] transition-all duration-500 scale-95 opacity-0 pointer-events-auto" id="modalContainer">
        <div id="modalLoading" class="absolute inset-0 flex flex-col items-center justify-center bg-surface-dark/80 backdrop-blur-md z-10 transition-opacity duration-300">
            <div class="size-12 border-4 border-primary/20 border-t-primary rounded-full animate-spin mb-4"></div>
            <p class="text-[10px] font-black uppercase text-gray-500 tracking-[0.2em] italic">Loading Details...</p>
        </div>
        <div id="modalContent" class="flex-1 p-8 md:p-10 opacity-0 transition-opacity duration-500 overflow-y-auto no-scrollbar"></div>
    </div>
</div>

<script>
    function openApplicationModal(appId) {
        if (!appId) return;
        const modal = document.getElementById('applicationModal');
        const backdrop = document.getElementById('modalBackdrop');
        const container = document.getElementById('modalContainer');
        const content = document.getElementById('modalContent');
        const loading = document.getElementById('modalLoading');

        modal.classList.replace('hidden', 'flex');
        setTimeout(() => {
            backdrop.classList.replace('opacity-0', 'opacity-100');
            container.classList.replace('scale-95', 'scale-100');
            container.classList.replace('opacity-0', 'opacity-100');
        }, 10);

        loading.classList.replace('opacity-0', 'opacity-100');
        loading.classList.remove('hidden');
        content.classList.replace('opacity-100', 'opacity-0');

        fetch(`view_application.php?id=${appId}&ajax=1`)
            .then(response => response.text())
            .then(html => {
                content.innerHTML = html;
                setTimeout(() => {
                    loading.classList.replace('opacity-100', 'opacity-0');
                    setTimeout(() => {
                        loading.classList.add('hidden');
                        content.classList.replace('opacity-0', 'opacity-100');
                    }, 300);
                }, 500);
            })
            .catch(error => {
                closeApplicationModal();
                alert('Connection error. Please try again.');
            });
    }

    function closeApplicationModal() {
        const modal = document.getElementById('applicationModal');
        const backdrop = document.getElementById('modalBackdrop');
        const container = document.getElementById('modalContainer');
        const content = document.getElementById('modalContent');

        backdrop.classList.replace('opacity-100', 'opacity-0');
        container.classList.replace('scale-100', 'scale-95');
        container.classList.replace('opacity-100', 'opacity-0');

        setTimeout(() => {
            modal.classList.replace('flex', 'hidden');
            content.classList.replace('opacity-100', 'opacity-0');
            content.innerHTML = ''; 
        }, 500);
    }

    document.getElementById('modalBackdrop').addEventListener('click', closeApplicationModal);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeApplicationModal(); });
</script>

<?php include '../includes/image_viewer.php'; ?>
</body>
</html>