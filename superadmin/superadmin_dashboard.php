<?php 
$page_title = "Super Admin Dashboard";
$active_page = "dashboard";

// Mock Data
$total_revenue = 1250000.00;
$active_tenants = 42;
$total_users = 15420;
$system_health = "99.9%";
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
        
        @media (max-width: 1023px) {
            .active-nav::after { display: none; }
        }
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

<nav class="flex flex-col w-64 lg:w-72 bg-[#0a090d] border-r border-white/5 sticky top-0 h-screen p-8 z-50 shrink-0">
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
    
    <div class="flex flex-col gap-5 flex-1 overflow-y-auto pr-2">
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
            <a href="logout.php" class="text-gray-400 hover:text-red-500 transition-colors flex items-center gap-3 group">
                <span class="material-symbols-outlined group-hover:translate-x-1 transition-transform">logout</span>
                <span class="nav-link">Sign Out</span>
            </a>
        </div>
    </div>
</nav>

<div class="flex-1 flex flex-col min-w-0 overflow-y-auto">
    <main class="flex-1 p-6 md:p-10 max-w-[1400px] w-full mx-auto">

<header class="mb-10 flex flex-col md:flex-row justify-between items-start md:items-end gap-6">
    <div>
        <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white">System <span class="text-primary">Overview</span></h2>
        <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-1">Super Admin Control Center</p>
    </div>
</header>

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
        <p class="text-amber-500 text-[10px] font-black uppercase mt-2">Active Subscriptions</p>
    </div>
    <div class="glass-card p-8 relative overflow-hidden group border border-white/5 bg-white/5">
        <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform">groups</span>
        <p class="text-[10px] font-black uppercase text-gray-400 mb-2 tracking-widest">Total Users</p>
        <h3 class="text-2xl font-black italic uppercase"><?= number_format($total_users) ?></h3>
        <p class="text-primary text-[10px] font-black uppercase mt-2">Network Growth</p>
    </div>
    <div class="glass-card p-8 relative overflow-hidden group border border-white/5 bg-white/5">
        <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform">bolt</span>
        <p class="text-[10px] font-black uppercase text-gray-400 mb-2 tracking-widest">Uptime</p>
        <h3 class="text-2xl font-black italic uppercase"><?= $system_health ?></h3>
        <p class="text-primary text-[10px] font-black uppercase mt-2">System Stability</p>
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

<div class="glass-card overflow-hidden">
    <div class="px-8 py-6 border-b border-white/5 bg-white/5 flex justify-between items-center">
        <h4 class="font-black italic uppercase text-sm tracking-tighter">Recent Tenant Activity</h4>
        <a href="tenant_management.php" class="text-[10px] font-black uppercase text-primary hover:text-white transition-colors">View All</a>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-left">
            <thead>
                <tr class="bg-background-dark/50 text-gray-500 text-[10px] font-black uppercase tracking-widest">
                    <th class="px-8 py-4">Gym Name</th>
                    <th class="px-8 py-4">Owner</th>
                    <th class="px-8 py-4">Status</th>
                    <th class="px-8 py-4 text-right">Joined Date</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
                <tr class="hover:bg-white/5 transition-all">
                    <td class="px-8 py-5">
                        <div class="flex items-center gap-3">
                            <div class="size-8 rounded-lg bg-primary/10 flex items-center justify-center font-black text-primary text-xs">PF</div>
                            <p class="text-sm font-bold italic">Power Fitness</p>
                        </div>
                    </td>
                    <td class="px-8 py-5 text-xs font-medium text-gray-400">Mark Johnson</td>
                    <td class="px-8 py-5">
                        <span class="px-3 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-[9px] text-emerald-500 font-black uppercase italic">Active</span>
                    </td>
                    <td class="px-8 py-5 text-right text-xs font-black italic text-gray-500">Mar 12, 2024</td>
                </tr>
                <tr class="hover:bg-white/5 transition-all">
                    <td class="px-8 py-5">
                        <div class="flex items-center gap-3">
                            <div class="size-8 rounded-lg bg-primary/10 flex items-center justify-center font-black text-primary text-xs">HG</div>
                            <p class="text-sm font-bold italic">Herdoza Gym</p>
                        </div>
                    </td>
                    <td class="px-8 py-5 text-xs font-medium text-gray-400">Louis Herdoza</td>
                    <td class="px-8 py-5">
                        <span class="px-3 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-[9px] text-emerald-500 font-black uppercase italic">Active</span>
                    </td>
                    <td class="px-8 py-5 text-right text-xs font-black italic text-gray-500">Mar 10, 2024</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

    </main>
</div>
</body>
</html>