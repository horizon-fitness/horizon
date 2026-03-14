<?php 
$page_title = "Subscription Logs";
$active_page = "subscriptions";

// Mock Data for Subscriptions
$logs = [
    ['gym' => 'Power Fitness', 'plan' => 'Enterprise', 'start' => '2024-01-15', 'expiry' => '2025-01-15', 'status' => 'Paid', 'health' => 'Healthy'],
    ['gym' => 'Herdoza Gym', 'plan' => 'Pro', 'start' => '2024-02-10', 'expiry' => '2024-03-10', 'status' => 'Pending', 'health' => 'Expiring Soon'],
    ['gym' => 'Iron Works', 'plan' => 'Basic', 'start' => '2023-11-05', 'expiry' => '2023-12-05', 'status' => 'Overdue', 'health' => 'Expired'],
    ['gym' => 'Elite Athletics', 'plan' => 'Enterprise', 'start' => '2024-03-01', 'expiry' => '2025-03-01', 'status' => 'Paid', 'health' => 'Healthy'],
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
        <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white">Subscription <span class="text-primary">Logs</span></h2>
        <p class="text-primary text-xs font-bold uppercase tracking-widest">Monitor tenant billing cycles and plans</p>
    </div>
</header>

<div class="glass-card overflow-hidden shadow-2xl">
    <div class="overflow-x-auto">
        <table class="w-full text-left">
            <thead>
                <tr class="bg-background-dark/50 text-gray-500 text-[10px] font-black uppercase tracking-widest">
                    <th class="px-8 py-4">Tenant Gym</th>
                    <th class="px-8 py-4">Plan Level</th>
                    <th class="px-8 py-4">Billing Cycle</th>
                    <th class="px-8 py-4">Payment</th>
                    <th class="px-8 py-4 text-right">Health Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
                <?php foreach($logs as $log): ?>
                <tr class="hover:bg-white/5 transition-all">
                    <td class="px-8 py-5">
                        <p class="text-sm font-bold italic text-white"><?= htmlspecialchars($log['gym']) ?></p>
                    </td>
                    <td class="px-8 py-5">
                        <span class="text-[10px] font-black uppercase tracking-widest text-gray-400"><?= $log['plan'] ?></span>
                    </td>
                    <td class="px-8 py-5">
                        <div class="flex flex-col gap-1">
                            <p class="text-[10px] font-bold text-white"><?= date('M d, Y', strtotime($log['start'])) ?> — <?= date('M d, Y', strtotime($log['expiry'])) ?></p>
                            <div class="w-32 h-1 bg-white/5 rounded-full overflow-hidden">
                                <?php 
                                    $progress = ($log['health'] == 'Expired') ? 100 : (($log['health'] == 'Expiring Soon') ? 90 : 30);
                                    $color = ($log['health'] == 'Expired') ? 'bg-red-500' : (($log['health'] == 'Expiring Soon') ? 'bg-amber-500' : 'bg-primary');
                                ?>
                                <div class="<?= $color ?> h-full" style="width: <?= $progress ?>%"></div>
                            </div>
                        </div>
                    </td>
                    <td class="px-8 py-5">
                        <?php if($log['status'] == 'Paid'): ?>
                            <span class="text-[9px] font-black uppercase text-emerald-500 bg-emerald-500/10 px-2 py-1 rounded">Paid</span>
                        <?php elseif($log['status'] == 'Pending'): ?>
                            <span class="text-[9px] font-black uppercase text-amber-500 bg-amber-500/10 px-2 py-1 rounded">Pending</span>
                        <?php else: ?>
                            <span class="text-[9px] font-black uppercase text-red-500 bg-red-500/10 px-2 py-1 rounded">Overdue</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-8 py-5 text-right">
                        <?php if($log['health'] == 'Healthy'): ?>
                            <div class="flex items-center justify-end gap-2 text-emerald-500">
                                <span class="text-[9px] font-black uppercase italic">Active</span>
                                <span class="material-symbols-outlined text-sm">check_circle</span>
                            </div>
                        <?php elseif($log['health'] == 'Expiring Soon'): ?>
                            <div class="flex items-center justify-end gap-2 text-amber-500 alert-pulse">
                                <span class="text-[9px] font-black uppercase italic">Expiring</span>
                                <span class="material-symbols-outlined text-sm">warning</span>
                            </div>
                        <?php else: ?>
                            <div class="flex items-center justify-end gap-2 text-red-500">
                                <span class="text-[9px] font-black uppercase italic">Expired</span>
                                <span class="material-symbols-outlined text-sm">error</span>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

    </main>
</div>
</body>
</html>