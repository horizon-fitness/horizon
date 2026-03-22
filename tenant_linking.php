<?php 
session_start();
require_once '../db.php';

// Security Check: Only Superadmin can access
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'superadmin') {
    header("Location: ../login.php");
    exit;
}

$page_title = "Tenant Linking";
$active_page = "tenant_linking"; 

// Application messages handled via session
$success_msg = $_SESSION['success_msg'] ?? '';
$error_msg = $_SESSION['error_msg'] ?? '';
unset($_SESSION['success_msg'], $_SESSION['error_msg']);

// Fetch tenants for linking
$stmtTenants = $pdo->query("
    SELECT g.*, 
           u.first_name, u.last_name, u.email as owner_email,
           tp.logo_path
    FROM gyms g
    JOIN users u ON g.owner_user_id = u.user_id
    LEFT JOIN tenant_pages tp ON g.gym_id = tp.gym_id
    WHERE g.status != 'Deleted'
    ORDER BY g.created_at DESC
");
$tenants = $stmtTenants->fetchAll(PDO::FETCH_ASSOC);

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
        
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
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
    
    <div class="flex flex-col gap-6 flex-1 overflow-y-auto no-scrollbar pr-2">
        <a href="superadmin_dashboard.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'dashboard') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-2xl shrink-0">grid_view</span> 
            <span class="nav-text">Dashboard</span>
        </a>
        
        <a href="tenant_management.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'tenants') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-2xl shrink-0">business</span> 
            <span class="nav-text">Tenant Management</span>
        </a>

        <a href="tenant_linking.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'tenant_linking') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-2xl shrink-0">link</span> 
            <span class="nav-text">Tenant Linking</span>
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

    <div class="mt-auto pt-8 border-t border-white/10 flex flex-col gap-8">
        <a href="../logout.php" class="nav-link flex items-center gap-4 py-2 text-red-400/70 hover:text-red-400">
            <span class="material-symbols-outlined text-2xl shrink-0">logout</span> 
            <span class="nav-text">Logout</span>
        </a>
    </div>
</nav>

<main class="flex-1 p-8 lg:p-12">
    <header class="flex flex-col md:flex-row md:items-center justify-between gap-6 mb-12">
        <div>
            <h1 class="text-4xl font-black tracking-tight text-white mb-2"><?= $page_title ?></h1>
            <p class="text-gray-400 font-medium">Link tenants together for shared resources or multi-location management.</p>
        </div>
    </header>

    <?php if ($success_msg): ?>
        <div class="mb-8 p-4 bg-emerald-500/10 border border-emerald-500/20 rounded-2xl flex items-center gap-4 text-emerald-400 animate-in fade-in slide-in-from-top-4">
            <span class="material-symbols-outlined">check_circle</span>
            <p class="text-sm font-bold uppercase tracking-wider"><?= $success_msg ?></p>
        </div>
    <?php endif; ?>

    <?php if ($error_msg): ?>
        <div class="mb-8 p-4 bg-red-500/10 border border-red-500/20 rounded-2xl flex items-center gap-4 text-red-400 animate-in fade-in slide-in-from-top-4">
            <span class="material-symbols-outlined">error</span>
            <p class="text-sm font-bold uppercase tracking-wider"><?= $error_msg ?></p>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        <div class="glass-card p-8">
            <h2 class="text-xl font-bold mb-6 flex items-center gap-2">
                <span class="material-symbols-outlined text-primary">link</span>
                Link New Tenants
            </h2>
            <form action="process_tenant_link.php" method="POST" class="space-y-6">
                <div>
                    <label class="block text-sm font-bold text-gray-400 uppercase mb-2">Primary Tenant</label>
                    <select name="primary_tenant" class="w-full bg-[#0a090d] border border-white/10 rounded-xl px-4 py-3 text-white focus:outline-none focus:border-primary">
                        <option value="">Select a tenant</option>
                        <?php foreach ($tenants as $tenant): ?>
                            <option value="<?= $tenant['gym_id'] ?>"><?= htmlspecialchars($tenant['gym_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-400 uppercase mb-2">Secondary Tenant</label>
                    <select name="secondary_tenant" class="w-full bg-[#0a090d] border border-white/10 rounded-xl px-4 py-3 text-white focus:outline-none focus:border-primary">
                        <option value="">Select a tenant</option>
                        <?php foreach ($tenants as $tenant): ?>
                            <option value="<?= $tenant['gym_id'] ?>"><?= htmlspecialchars($tenant['gym_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="w-full bg-primary hover:bg-primary/90 text-white font-bold py-4 rounded-xl transition-all shadow-lg shadow-primary/20">
                    Create Link
                </button>
            </form>
        </div>

        <div class="glass-card p-8">
            <h2 class="text-xl font-bold mb-6 flex items-center gap-2">
                <span class="material-symbols-outlined text-primary">list_alt</span>
                Existing Links
            </h2>
            <div class="space-y-4 overflow-y-auto max-h-[400px] pr-2 no-scrollbar">
                <?php 
                $links = [];
                try {
                    // Try to fetch links if table exists
                    $stmtLinks = $pdo->query("
                        SELECT l.*, 
                               g1.gym_name as primary_name, 
                               g2.gym_name as secondary_name 
                        FROM gym_links l 
                        JOIN gyms g1 ON l.primary_id = g1.gym_id 
                        JOIN gyms g2 ON l.secondary_id = g2.gym_id
                        ORDER BY l.created_at DESC
                    ");
                    $links = $stmtLinks->fetchAll(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    // Table doesn't exist, show empty list
                    $links = [];
                }
                
                if (empty($links)): ?>
                    <div class="flex flex-col items-center justify-center h-48 border-2 border-dashed border-white/5 rounded-2xl">
                        <span class="material-symbols-outlined text-gray-600 text-4xl mb-2">link_off</span>
                        <p class="text-gray-500 font-medium text-sm">No active links found</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($links as $link): ?>
                        <div class="bg-[#0a090d] border border-white/5 p-4 rounded-xl flex items-center justify-between group hover:border-primary/50 transition-all">
                            <div class="flex items-center gap-4">
                                <div class="size-10 rounded-lg bg-primary/10 flex items-center justify-center">
                                    <span class="material-symbols-outlined text-primary text-xl">link</span>
                                </div>
                                <div>
                                    <p class="text-white font-bold text-sm"><?= htmlspecialchars($link['primary_name']) ?></p>
                                    <div class="flex items-center gap-2">
                                        <span class="material-symbols-outlined text-gray-500 text-xs">sync</span>
                                        <p class="text-gray-400 text-[10px] font-bold uppercase tracking-wider"><?= htmlspecialchars($link['secondary_name']) ?></p>
                                    </div>
                                </div>
                            </div>
                            <form action="process_tenant_link.php" method="POST" onsubmit="return confirm('Are you sure you want to remove this link?');">
                                <input type="hidden" name="delete_link_id" value="<?= $link['link_id'] ?>">
                                <button type="submit" class="size-8 rounded-lg bg-red-500/10 text-red-500 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-all">
                                    <span class="material-symbols-outlined text-lg">delete</span>
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

</body>
</html>