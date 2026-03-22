<?php 
$page_title = "Access Control (RBAC)";
$active_page = "rbac";

// Mock Data for Roles and Permissions
$roles = ['Super Admin', 'Admin (Gym Owner)', 'Staff', 'Trainer', 'Member'];
$modules = [
    'User Management' => ['view', 'create', 'edit', 'delete'],
    'Financial Reports' => ['view', 'export'],
    'Tenant Settings' => ['view', 'edit'],
    'Inventory Control' => ['view', 'create', 'edit'],
    'Attendance Logs' => ['view', 'export'],
];
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
        
        /* Sidebar Hover Logic */
        .sidebar-nav {
            width: 100px;
            transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
        }
        .sidebar-nav:hover {
            width: 280px;
        }
        .nav-text {
            opacity: 0;
            transform: translateX(-10px);
            transition: all 0.2s ease;
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
            const headerClockEl = document.getElementById('headerClock');
            
            const timeString = now.toLocaleTimeString('en-US', { 
                hour: '2-digit', 
                minute: '2-digit', 
                second: '2-digit' 
            });

            if (headerClockEl) headerClockEl.textContent = timeString;
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
        <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white leading-none">Access <span class="text-primary">Control</span></h2>
        <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-2">Role-Based Permission Management</p>
    </div>
    <div class="text-right flex flex-col items-end">
        <p id="headerClock" class="text-white font-black italic text-xl tracking-tight leading-none mb-2">00:00:00 AM</p>
        <p class="text-primary text-[9px] font-black uppercase tracking-[0.2em] opacity-80"><?= date('l, M d, Y') ?></p>
    </div>
</header>

<div class="flex gap-4 mb-8 overflow-x-auto pb-4 no-print pr-2">
    <?php foreach($roles as $index => $role): ?>
    <button class="px-6 py-3 rounded-2xl border <?= ($index == 0) ? 'border-primary bg-primary/10 text-primary' : 'border-white/5 bg-white/5 text-gray-500 hover:text-white' ?> transition-all whitespace-nowrap">
        <p class="text-[10px] font-black uppercase tracking-widest"><?= $role ?></p>
    </button>
    <?php endforeach; ?>
</div>

<div class="glass-card p-8">
    <div class="flex justify-between items-center mb-8">
        <div>
            <h3 class="text-sm font-black italic uppercase tracking-widest">Module Permissions</h3>
            <p class="text-[10px] text-gray-500 font-bold uppercase mt-1">Configuring permissions for: <span class="text-primary italic">Super Admin</span></p>
        </div>
        <button class="bg-primary text-white px-6 py-2 rounded-lg text-[10px] font-black uppercase tracking-widest hover:scale-105 transition-transform">Save Changes</button>
    </div>

    <div class="space-y-6">
        <?php foreach($modules as $module => $actions): ?>
        <div class="p-6 bg-white/[0.02] border border-white/5 rounded-2xl flex flex-col md:flex-row md:items-center justify-between gap-6 hover:bg-white/[0.04] transition-all">
            <div class="flex items-center gap-4">
                <div class="size-10 rounded-xl bg-primary/10 flex items-center justify-center">
                    <span class="material-symbols-outlined text-primary text-xl">extension</span>
                </div>
                <div>
                    <h4 class="text-sm font-bold text-white"><?= $module ?></h4>
                    <p class="text-[9px] text-gray-500 font-black uppercase tracking-widest">Visibility & Action Control</p>
                </div>
            </div>
            
            <div class="flex flex-wrap gap-4">
                <?php foreach($actions as $action): ?>
                <label class="flex items-center gap-3 cursor-pointer group">
                    <div class="relative">
                        <input type="checkbox" class="sr-only peer" checked>
                        <div class="w-10 h-5 bg-white/10 rounded-full peer-checked:bg-primary transition-all after:content-[''] after:absolute after:top-1 after:left-1 after:bg-gray-400 after:rounded-full after:h-3 after:w-3 after:transition-all peer-checked:after:translate-x-5 peer-checked:after:bg-white"></div>
                    </div>
                    <span class="text-[9px] font-black uppercase text-gray-500 group-hover:text-white transition-colors"><?= $action ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

    </main>
</div>
</body>
</html>