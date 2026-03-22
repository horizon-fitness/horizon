<?php 
$page_title = "Real-Time Occupancy";
$active_page = "occupancy";

// Mock Data for Occupancy
$gyms = [
    ['name' => 'Power Fitness', 'count' => 45, 'capacity' => 100, 'status' => 'Moderate'],
    ['name' => 'Herdoza Gym', 'count' => 12, 'capacity' => 50, 'status' => 'Low'],
    ['name' => 'Iron Works', 'count' => 78, 'capacity' => 80, 'status' => 'Full'],
    ['name' => 'Elite Athletics', 'count' => 30, 'capacity' => 150, 'status' => 'Low'],
    ['name' => 'City Gym', 'count' => 55, 'capacity' => 60, 'status' => 'Full'],
    ['name' => 'Prime Studio', 'count' => 22, 'capacity' => 100, 'status' => 'Moderate'],
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
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            margin-top: 0 !important;
            margin-bottom: 0 !important;
        }
        .sidebar-nav:hover .nav-section-header {
            max-height: 25px;
        }
        .sidebar-nav:hover .nav-section-header.mt-4 { margin-top: 1rem !important; }
        .sidebar-nav:hover .nav-section-header.mt-6 { margin-top: 1.5rem !important; }
        .sidebar-nav:hover .nav-section-header.mb-2 { margin-bottom: 0.5rem !important; }

        .sidebar-content {
            gap: 0.5rem;
            transition: all 0.3s ease-in-out;
        }
        .sidebar-nav:hover .sidebar-content {
            gap: 1rem;
        }
        /* End Sidebar Hover Logic */

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
            <span class="nav-text text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Overview</span>
        </div>
        <a href="superadmin_dashboard.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'dashboard') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">grid_view</span> 
            <span class="nav-text">Dashboard</span>
        </a>
        
        <!-- Management Section -->
        <div class="nav-section-header px-0 mb-2 mt-6">
            <span class="nav-text text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Management</span>
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
            <span class="nav-text text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">System</span>
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
            <span class="nav-text text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Account</span>
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
</nav>

<div class="flex-1 flex flex-col min-w-0 overflow-y-auto">
    <main class="flex-1 p-6 md:p-10 max-w-[1400px] w-full mx-auto">

<header class="mb-10 flex flex-row justify-between items-end gap-6">
    <div>
        <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white leading-none">Live <span class="text-primary">Occupancy</span></h2>
        <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-2">Real-time facility usage monitoring</p>
    </div>
    <div class="text-right">
        <p id="headerClock" class="text-white font-black italic text-xl tracking-tight leading-none mb-2">00:00:00 AM</p>
        <p class="text-primary text-[9px] font-black uppercase tracking-[0.2em] opacity-80"><?= date('l, M d, Y') ?></p>
    </div>
</header>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-10">
    <?php foreach($gyms as $gym): ?>
    <div class="glass-card p-8 group hover:border-primary/30 transition-all">
        <div class="flex justify-between items-start mb-6">
            <div>
                <h3 class="text-lg font-black italic uppercase text-white"><?= htmlspecialchars($gym['name']) ?></h3>
                <p class="text-[9px] text-gray-500 font-black uppercase tracking-widest">Facility Capacity: <?= $gym['capacity'] ?></p>
            </div>
            <?php 
                $statusColor = ($gym['status'] == 'Full') ? 'text-red-500 bg-red-500/10' : (($gym['status'] == 'Moderate') ? 'text-amber-500 bg-amber-500/10' : 'text-emerald-500 bg-emerald-500/10');
            ?>
            <span class="px-2 py-1 rounded text-[8px] font-black uppercase tracking-tighter <?= $statusColor ?>">
                <?= $gym['status'] ?>
            </span>
        </div>

        <div class="relative pt-1">
            <div class="flex mb-2 items-center justify-between">
                <div>
                    <span class="text-2xl font-black italic text-white"><?= $gym['count'] ?></span>
                    <span class="text-[10px] font-bold text-gray-500 uppercase ml-1">People In</span>
                </div>
                <div class="text-right">
                    <span class="text-xs font-black italic text-primary">
                        <?= round(($gym['count'] / $gym['capacity']) * 100) ?>%
                    </span>
                </div>
            </div>
            <div class="overflow-hidden h-2 mb-4 text-xs flex rounded-full bg-white/5">
                <?php 
                    $barColor = ($gym['status'] == 'Full') ? 'bg-red-500' : (($gym['status'] == 'Moderate') ? 'bg-amber-500' : 'bg-emerald-500');
                    $width = min(100, ($gym['count'] / $gym['capacity']) * 100);
                ?>
                <div style="width:<?= $width ?>%" class="shadow-none flex flex-col text-center whitespace-nowrap text-white justify-center <?= $barColor ?> transition-all duration-500"></div>
            </div>
        </div>
        
        <div class="flex justify-between items-center mt-4 pt-4 border-t border-white/5">
            <p class="text-[9px] font-black uppercase text-gray-600 tracking-widest italic">Last Scan: Just Now</p>
            <span class="material-symbols-outlined text-gray-700 group-hover:text-primary transition-colors">analytics</span>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="dashed-container border-2 border-dashed border-white/10 rounded-[24px] p-12 flex flex-col items-center justify-center text-center">
    <span class="material-symbols-outlined text-gray-700 text-5xl mb-4">sensors</span>
    <h4 class="text-gray-500 text-xs font-black uppercase tracking-widest italic">Waiting for more data points...</h4>
    <p class="text-gray-700 text-[10px] mt-2 font-bold uppercase tracking-tighter italic">Scanning across 42 active tenant gyms</p>
</div>

    </main>
</div>
</body>
</html>