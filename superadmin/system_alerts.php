<?php 
$page_title = "System Alerts";
$active_page = "alerts";

// Mock Data for Alerts
$alerts = [
    ['id' => 1, 'type' => 'Payment Failure', 'source' => 'Iron Works', 'message' => 'Subscription payment failed for TRX-9840.', 'time' => '2 hours ago', 'priority' => 'High'],
    ['id' => 2, 'type' => 'Pending Approval', 'source' => 'System', 'message' => 'New tenant "Gravity Fitness" is waiting for account activation.', 'time' => '5 hours ago', 'priority' => 'Medium'],
    ['id' => 3, 'type' => 'Expired Membership', 'source' => 'Power Fitness', 'message' => 'Tenant subscription expired for Power Fitness (Legacy Plan).', 'time' => '1 day ago', 'priority' => 'High'],
    ['id' => 4, 'type' => 'Warning', 'source' => 'Server', 'message' => 'Database storage reaching 85% capacity.', 'time' => '2 days ago', 'priority' => 'Low'],
];
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title><?php echo $page_title ?? 'Super Admin Dashboard'; ?> | Herdoza Fitness</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "#8c2bee", "background-dark": "#0a090d", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)"}}}
        }
        function updateHeaderClock() {
            const now = new Date();
            const clockEl = document.getElementById('topClock');
            if(clockEl) clockEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }
        window.onload = function() { setInterval(updateHeaderClock, 1000); updateHeaderClock(); };
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        
        /* Sidebar Hover Logic - Fixed Clipping */
        .sidebar-nav {
            width: 88px;
            transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
        }
        .sidebar-nav:hover {
            width: 280px;
        }
        .nav-text {
            opacity: 0;
            transition: opacity 0.2s ease;
            white-space: nowrap;
            display: none;
        }
        .sidebar-nav:hover .nav-text {
            opacity: 1;
            display: block;
        }

        .nav-link { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; transition: all 0.2s; white-space: nowrap; }
        .active-nav { color: #8c2bee !important; position: relative; }
        .active-nav::after { content: ''; position: absolute; right: 0; top: 50%; transform: translateY(-50%); width: 4px; height: 20px; background: #8c2bee; border-radius: 99px; }
        
        @media (max-width: 1023px) {
            .active-nav::after { display: none; }
        }
        
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: #0a090d; }
        ::-webkit-scrollbar-thumb { background: #14121a; border-radius: 10px; }
    </style>
</head>
<body class="antialiased flex flex-row min-h-screen">

<nav class="sidebar-nav flex flex-col bg-[#0a090d] border-r border-white/5 sticky top-0 h-screen p-6 z-50 shrink-0">
    <div class="mb-12">
        <div class="flex items-center gap-4 mb-6">
            <div class="size-10 rounded-xl bg-[#7f13ec] flex items-center justify-center shadow-lg shrink-0">
                <span class="material-symbols-outlined text-white text-2xl">bolt</span>
            </div>
            <h1 class="nav-text text-2xl font-black italic uppercase tracking-tighter text-white">Herdoza</h1>
        </div>
    </div>
    
    <div class="flex flex-col gap-8 flex-1 overflow-y-auto pr-2">
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
        <a href="logout.php" class="text-gray-400 hover:text-red-500 transition-colors flex items-center gap-4 group">
            <span class="material-symbols-outlined group-hover:translate-x-1 transition-transform text-2xl shrink-0">logout</span>
            <span class="nav-link nav-text">Sign Out</span>
        </a>
    </div>
</nav>

<div class="flex-1 flex flex-col min-w-0 overflow-y-auto">
    <main class="flex-1 p-6 md:p-10 max-w-[1400px] w-full mx-auto">

<header class="mb-10 flex flex-row justify-between items-end gap-4">
    <div>
        <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white">System <span class="text-primary">Alerts</span></h2>
        <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-1">Critical notifications and system warnings</p>
    </div>
    
    <div class="text-right">
        <p id="topClock" class="text-white font-black italic text-4xl leading-none mb-1 text-primary">00:00:00 AM</p>
        <p class="text-gray-500 text-[10px] font-black uppercase tracking-[0.2em]"><?= date('l, M d, Y') ?></p>
    </div>
</header>

<div class="flex justify-end mb-4">
    <button class="text-[10px] font-black uppercase text-gray-500 hover:text-white transition-colors">Mark all as read</button>
</div>

<div class="space-y-4">
    <?php foreach($alerts as $alert): ?>
    <div class="glass-card p-6 flex flex-col md:flex-row md:items-center justify-between gap-6 border-l-4 <?= ($alert['priority'] == 'High') ? 'border-red-500' : (($alert['priority'] == 'Medium') ? 'border-amber-500' : 'border-blue-500') ?> hover:bg-white/[0.02] transition-all">
        <div class="flex items-start gap-4">
            <div class="size-10 rounded-xl flex items-center justify-center <?= ($alert['priority'] == 'High') ? 'bg-red-500/10 text-red-500' : (($alert['priority'] == 'Medium') ? 'bg-amber-500/10 text-amber-500' : 'bg-blue-500/10 text-blue-500') ?>">
                <span class="material-symbols-outlined">
                    <?= ($alert['type'] == 'Payment Failure') ? 'credit_card_off' : (($alert['type'] == 'Pending Approval') ? 'person_add' : (($alert['type'] == 'Expired Membership') ? 'event_busy' : 'warning')) ?>
                </span>
            </div>
            <div>
                <div class="flex items-center gap-2 mb-1">
                    <h4 class="text-sm font-black italic uppercase text-white"><?= $alert['type'] ?></h4>
                    <span class="text-[8px] font-black uppercase px-1.5 py-0.5 bg-white/5 text-gray-500 rounded"><?= $alert['source'] ?></span>
                </div>
                <p class="text-xs text-gray-400 font-medium"><?= $alert['message'] ?></p>
                <p class="text-[9px] text-gray-600 font-black uppercase mt-2 italic"><?= $alert['time'] ?></p>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <button class="px-4 py-2 bg-white/5 hover:bg-white/10 text-white rounded-lg text-[9px] font-black uppercase transition-all">View Details</button>
            <button class="size-8 flex items-center justify-center text-gray-600 hover:text-red-500 transition-colors">
                <span class="material-symbols-outlined text-sm">close</span>
            </button>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="mt-10 flex justify-center">
    <button class="px-8 py-3 bg-white/5 border border-white/10 rounded-2xl text-[10px] font-black uppercase tracking-widest text-gray-500 hover:text-white hover:bg-white/10 transition-all italic">Load Older Alerts</button>
</div>

    </main>
</div>
</body>
</html>