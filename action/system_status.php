<?php 
$page_title = "System Status";
$active_page = "status";

// Mock Data for System Status
$services = [
    ['name' => 'Main Database', 'status' => 'Healthy', 'uptime' => '99.99%', 'latency' => '12ms'],
    ['name' => 'Tenant API Gateway', 'status' => 'Healthy', 'uptime' => '99.95%', 'latency' => '45ms'],
    ['name' => 'Auth Service (SSO)', 'status' => 'Warning', 'uptime' => '98.50%', 'latency' => '250ms'],
    ['name' => 'Payment Webhook Node', 'status' => 'Healthy', 'uptime' => '100%', 'latency' => '8ms'],
    ['name' => 'File Storage (S3)', 'status' => 'Healthy', 'uptime' => '99.99%', 'latency' => '110ms'],
    ['name' => 'Real-time Socket Cluster', 'status' => 'Critical', 'uptime' => '85.20%', 'latency' => 'TIMEOUT'],
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
            if(document.getElementById('sidebarClock')) document.getElementById('sidebarClock').textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }
        window.onload = function() { setInterval(updateHeaderClock, 1000); updateHeaderClock(); };
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        .nav-link { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; transition: all 0.2s; white-space: nowrap; }
        .active-nav { color: #8c2bee !important; position: relative; }
        .active-nav::after { content: ''; position: absolute; right: -32px; top: 0; width: 4px; height: 100%; background: #8c2bee; border-radius: 2px; }
        
        @media (max-width: 1023px) { .active-nav::after { display: none; } }
        
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        .alert-pulse { animation: alert-pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite; }
        @keyframes alert-pulse { 0%, 100% { opacity: 1; } 50% { opacity: .5; } }
        
        .status-card-green { border: 1px solid #10b981; background: linear-gradient(135deg, rgba(16,185,129,0.05) 0%, rgba(20,18,26,1) 100%); }
        .status-card-yellow { border: 1px solid #f59e0b; background: linear-gradient(135deg, rgba(245,158,11,0.05) 0%, rgba(20,18,26,1) 100%); }
        .status-card-red { border: 1px solid #ef4444; background: linear-gradient(135deg, rgba(239,68,68,0.05) 0%, rgba(20,18,26,1) 100%); }
        .dashed-container { border: 2px dashed rgba(255,255,255,0.1); border-radius: 24px; }
        
        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: #0a090d; }
        ::-webkit-scrollbar-thumb { background: #14121a; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #1a1824; }
    </style>
</head>
<body class="antialiased flex flex-row min-h-screen">

<nav class="flex flex-col w-64 lg:w-72 bg-[#0a090d] border-r border-white/5 sticky top-0 h-screen p-8 z-50 shrink-0 overflow-x-hidden">
    <div class="mb-12">
        <div class="flex items-center gap-4 mb-6">
            <div class="size-10 rounded-xl bg-[#7f13ec] flex items-center justify-center shadow-lg shrink-0">
                <span class="material-symbols-outlined text-white text-2xl">bolt</span>
            </div>
            <h1 class="text-2xl font-black italic uppercase tracking-tighter text-white">Herdoza</h1>
        </div>
        <div class="p-4 rounded-2xl bg-white/5 border border-white/5">
            <p id="sidebarClock" class="text-white font-black italic text-xl leading-none mb-1">00:00:00 AM</p>
            <p class="text-primary text-[9px] font-black uppercase tracking-[0.2em]"><?= date('l, M d') ?></p>
        </div>
    </div>
    
    <div class="flex flex-col gap-5 flex-1 overflow-y-auto pr-2 no-scrollbar">
        <a href="superadmin_dashboard.php" class="nav-link flex items-center gap-3 <?= ($active_page == 'dashboard') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl">grid_view</span> Dashboard
        </a>
        <a href="tenant_management.php" class="nav-link flex items-center gap-3 <?= ($active_page == 'tenants') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl">business</span> Tenant Management
        </a>
        <a href="subscription_logs.php" class="nav-link flex items-center gap-3 <?= ($active_page == 'subscriptions') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl">history_edu</span> Subscription Logs
        </a>
        <a href="rbac_management.php" class="nav-link flex items-center gap-3 <?= ($active_page == 'rbac') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl">security</span> Access Control
        </a>
        <a href="real_time_occupancy.php" class="nav-link flex items-center gap-3 <?= ($active_page == 'occupancy') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl">group</span> Real-Time Occupancy
        </a>
        <a href="recent_transactions.php" class="nav-link flex items-center gap-3 <?= ($active_page == 'transactions') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl">receipt_long</span> Recent Transactions
        </a>
        <a href="system_alerts.php" class="nav-link flex items-center gap-3 <?= ($active_page == 'alerts') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl">notifications_active</span> System Alerts
        </a>
        <a href="system_status.php" class="nav-link flex items-center gap-3 <?= ($active_page == 'status') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl">monitor_heart</span> System Status
        </a>
        <a href="support_tickets.php" class="nav-link flex items-center gap-3 <?= ($active_page == 'tickets') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl">confirmation_number</span> Support Tickets
        </a>
        
        <div class="mt-auto pt-8 border-t border-white/10">
            <a href="../logout.php" class="text-gray-400 hover:text-red-500 transition-colors flex items-center gap-3 group">
                <span class="material-symbols-outlined group-hover:translate-x-1 transition-transform">logout</span>
                <span class="nav-link">Sign Out</span>
            </a>
        </div>
    </div>
</nav>

<div class="flex-1 flex flex-col min-w-0 overflow-y-auto">
    <main class="flex-1 p-6 md:p-10 max-w-[1400px] w-full mx-auto">

<header class="mb-10 flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
    <div>
        <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white">System <span class="text-primary">Status</span></h2>
        <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-1">Infrastructure health and service monitoring</p>
    </div>
    <div class="flex items-center gap-3">
        <button class="bg-white/5 text-white px-4 py-2 rounded-lg text-[9px] font-black uppercase hover:bg-white/10 transition-all border border-white/5 italic">Refresh Status</button>
    </div>
</header>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-10">
    <?php foreach($services as $service): ?>
    <div class="glass-card p-8 relative overflow-hidden group">
        <div class="flex justify-between items-start mb-6">
            <h3 class="text-sm font-black italic uppercase italic text-white"><?= $service['name'] ?></h3>
            <?php 
                $statusClass = ($service['status'] == 'Healthy') ? 'status-card-green' : (($service['status'] == 'Warning') ? 'status-card-yellow' : 'status-card-red');
                $dotColor = ($service['status'] == 'Healthy') ? 'bg-emerald-500' : (($service['status'] == 'Warning') ? 'bg-amber-500' : 'bg-red-500');
            ?>
            <div class="flex items-center gap-2">
                <span class="size-2 rounded-full <?= $dotColor ?> alert-pulse"></span>
                <span class="text-[8px] font-black uppercase tracking-tighter <?= str_replace('bg-', 'text-', $dotColor) ?>"><?= $service['status'] ?></span>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4 mt-4">
            <div class="p-4 bg-white/5 rounded-2xl border border-white/5">
                <p class="text-[8px] font-black uppercase text-gray-500 mb-1 tracking-widest">Uptime</p>
                <p class="text-sm font-black italic text-white"><?= $service['uptime'] ?></p>
            </div>
            <div class="p-4 bg-white/5 rounded-2xl border border-white/5">
                <p class="text-[8px] font-black uppercase text-gray-500 mb-1 tracking-widest">Latency</p>
                <p class="text-sm font-black italic text-white"><?= $service['latency'] ?></p>
            </div>
        </div>

        <div class="absolute bottom-0 left-0 w-full h-1 <?= str_replace('status-card-', 'bg-', $statusClass) ?> opacity-20"></div>
    </div>
    <?php endforeach; ?>
</div>

<div class="glass-card p-8">
    <h3 class="text-sm font-black italic uppercase tracking-widest mb-6 flex items-center gap-2">
        <span class="material-symbols-outlined text-primary">analytics</span> Global Traffic Load
    </h3>
    <div class="h-48 w-full bg-white/[0.02] border border-white/5 rounded-2xl flex items-center justify-center relative overflow-hidden">
        <div class="flex items-end gap-1 h-20">
            <?php for($i=0; $i<30; $i++): ?>
                <div class="w-2 bg-primary/20 rounded-t-sm hover:bg-primary transition-all cursor-pointer" style="height: <?= rand(20, 100) ?>%"></div>
            <?php endfor; ?>
        </div>
        <p class="absolute top-4 right-6 text-[8px] font-black uppercase text-gray-600 tracking-[0.3em] italic">Network Throughput: 1.2 GB/s</p>
    </div>
</div>

    </main>
</div>
</body>
</html>