<?php 
$page_title = "Support Tickets";
$active_page = "tickets";

// Mock Data for Tickets
$tickets = [
    ['id' => 'TCK-1024', 'tenant' => 'Power Fitness', 'subject' => 'Unable to process GCash payments', 'priority' => 'Critical', 'status' => 'Open', 'date' => '2024-03-14'],
    ['id' => 'TCK-1023', 'tenant' => 'Herdoza Gym', 'subject' => 'Request for custom module access', 'priority' => 'Medium', 'status' => 'In Progress', 'date' => '2024-03-13'],
    ['id' => 'TCK-1022', 'tenant' => 'Iron Works', 'subject' => 'User data export error', 'priority' => 'High', 'status' => 'Open', 'date' => '2024-03-13'],
    ['id' => 'TCK-1021', 'tenant' => 'Elite Athletics', 'subject' => 'New trainer onboarding help', 'priority' => 'Low', 'status' => 'Resolved', 'date' => '2024-03-12'],
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
        <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white">Support <span class="text-primary">Tickets</span></h2>
        <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-1">Tenant assistance and issue tracking</p>
    </div>
    <div class="flex gap-3">
        <div class="flex bg-surface-dark p-1 rounded-xl border border-white/5">
            <button class="px-4 py-1.5 rounded-lg text-[9px] font-black uppercase tracking-widest bg-primary text-white">All Tickets</button>
            <button class="px-4 py-1.5 rounded-lg text-[9px] font-black uppercase tracking-widest text-gray-500 hover:text-white transition-all">My Assigned</button>
        </div>
    </div>
</header>

<div class="glass-card overflow-hidden shadow-2xl">
    <div class="overflow-x-auto">
        <table class="w-full text-left">
            <thead>
                <tr class="bg-background-dark/50 text-gray-500 text-[10px] font-black uppercase tracking-widest">
                    <th class="px-8 py-4">Ticket ID</th>
                    <th class="px-8 py-4">Tenant / Subject</th>
                    <th class="px-8 py-4">Priority</th>
                    <th class="px-8 py-4">Status</th>
                    <th class="px-8 py-4 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
                <?php foreach($tickets as $ticket): ?>
                <tr class="hover:bg-white/5 transition-all">
                    <td class="px-8 py-5">
                        <span class="text-[10px] font-black text-primary italic"><?= $ticket['id'] ?></span>
                    </td>
                    <td class="px-8 py-5">
                        <div>
                            <p class="text-sm font-bold italic text-white"><?= htmlspecialchars($ticket['subject']) ?></p>
                            <p class="text-[10px] text-gray-500 font-bold uppercase tracking-tight"><?= htmlspecialchars($ticket['tenant']) ?></p>
                        </div>
                    </td>
                    <td class="px-8 py-5">
                        <?php 
                            $prioColor = ($ticket['priority'] == 'Critical' || $ticket['priority'] == 'High') ? 'text-red-500' : (($ticket['priority'] == 'Medium') ? 'text-amber-500' : 'text-blue-500');
                        ?>
                        <div class="flex items-center gap-2 <?= $prioColor ?>">
                            <span class="size-1.5 rounded-full currentColor bg-current"></span>
                            <span class="text-[9px] font-black uppercase italic"><?= $ticket['priority'] ?></span>
                        </div>
                    </td>
                    <td class="px-8 py-5">
                        <?php 
                            $statusClass = ($ticket['status'] == 'Open') ? 'bg-red-500/10 text-red-500' : (($ticket['status'] == 'In Progress') ? 'bg-amber-500/10 text-amber-500' : 'bg-emerald-500/10 text-emerald-500');
                        ?>
                        <span class="px-3 py-1 rounded-full text-[9px] font-black uppercase italic <?= $statusClass ?>">
                            <?= $ticket['status'] ?>
                        </span>
                    </td>
                    <td class="px-8 py-5 text-right">
                        <div class="flex justify-end gap-2">
                            <button class="px-4 py-2 bg-white/5 hover:bg-primary transition-all text-[9px] font-black uppercase italic rounded-lg text-gray-400 hover:text-white border border-white/5">
                                Resolve
                            </button>
                            <button class="size-9 rounded-lg bg-white/5 flex items-center justify-center hover:bg-white/10 transition-all">
                                <span class="material-symbols-outlined text-sm text-gray-500">more_vert</span>
                            </button>
                        </div>
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