<?php 
session_start();
require_once '../db.php';

// Security Check: Only Superadmin can access
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'superadmin') {
    header("Location: ../login.php");
    exit;
}

$page_title = "Tenant Management";
$active_page = "tenants"; // This highlights the correct nav link

// Fetch Gyms/Tenants with their owner details and latest subscription status
$stmtTenants = $pdo->query("
    SELECT g.*, 
           u.first_name, u.last_name, u.email as owner_email,
           (SELECT subscription_status FROM client_subscriptions cs WHERE cs.gym_id = g.gym_id ORDER BY created_at DESC LIMIT 1) as sub_status
    FROM gyms g
    JOIN users u ON g.owner_user_id = u.user_id
    WHERE g.status != 'Deleted'
    ORDER BY g.created_at DESC
");
$tenants = $stmtTenants->fetchAll(PDO::FETCH_ASSOC);

// Fetch Pending Applications
$stmtPending = $pdo->query("
    SELECT a.*, u.first_name, u.last_name, u.email 
    FROM gym_owner_applications a 
    JOIN users u ON a.user_id = u.user_id 
    WHERE a.application_status = 'Pending'
    ORDER BY a.submitted_at DESC
");
$pending_apps = $stmtPending->fetchAll(PDO::FETCH_ASSOC);

// Counters for metrics
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
        .nav-link { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; transition: all 0.2s; white-space: nowrap; }
        .active-nav { color: #8c2bee !important; position: relative; }
        .active-nav::after { 
            content: ''; 
            position: absolute; 
            right: -32px; 
            top: 50%;
            transform: translateY(-50%);
            width: 4px; 
            height: 20px; 
            background: #8c2bee; 
            border-radius: 99px; 
        }
        
        @media (max-width: 1023px) { .active-nav::after { display: none; } }
        
        /* Custom Scrollbar for Main Page */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: #0a090d; }
        ::-webkit-scrollbar-thumb { background: #14121a; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #1a1824; }
        
        /* Hide scrollbar for Sidebar specifically */
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
    <script>
        function updateSidebarClock() {
            const now = new Date();
            const clockEl = document.getElementById('sidebarClock');
            if (clockEl) {
                clockEl.textContent = now.toLocaleTimeString('en-US', { 
                    hour: '2-digit', 
                    minute: '2-digit', 
                    second: '2-digit' 
                });
            }
        }
        setInterval(updateSidebarClock, 1000);
        window.addEventListener('DOMContentLoaded', updateSidebarClock);
    </script>
</head>
<body class="antialiased flex flex-row min-h-screen">

<nav class="flex flex-col w-64 lg:w-72 bg-[#0a090d] border-r border-white/5 sticky top-0 h-screen p-8 z-50 shrink-0">
    <div class="mb-12">
        <div class="flex items-center gap-4 mb-6">
            <div class="size-10 rounded-xl bg-[#7f13ec] flex items-center justify-center shadow-lg shrink-0">
                <span class="material-symbols-outlined text-white text-2xl">bolt</span>
            </div>
            <h1 class="text-2xl font-black italic uppercase tracking-tighter text-white">Horizon System</h1>
        </div>
        <div class="p-4 rounded-2xl bg-white/5 border border-white/5">
            <p id="sidebarClock" class="text-white font-black italic text-xl leading-none mb-1">00:00:00 AM</p>
            <p class="text-primary text-[9px] font-black uppercase tracking-[0.2em]"><?= date('l, M d') ?></p>
        </div>
    </div>
    
    <div class="flex flex-col gap-5 flex-1 overflow-y-auto no-scrollbar pr-2">
        <a href="superadmin_dashboard.php" class="nav-link flex items-center gap-3 <?= ($active_page == 'dashboard') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl">grid_view</span> Dashboard
        </a>
        <a href="tenant_management.php" class="nav-link flex items-center gap-3 <?= ($active_page == 'tenants') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl">business</span> Tenant Management
        </a>
    </div>

    <div class="mt-auto pt-8 border-t border-white/10 flex flex-col gap-5">
        <a href="#" class="text-gray-400 hover:text-white transition-colors flex items-center gap-3 group">
            <span class="material-symbols-outlined transition-transform group-hover:text-primary">person</span>
            <span class="nav-link">Profile</span>
        </a>
        <a href="../logout.php" class="text-gray-400 hover:text-red-500 transition-colors flex items-center gap-3 group">
            <span class="material-symbols-outlined group-hover:translate-x-1 transition-transform">logout</span>
            <span class="nav-link">Sign Out</span>
        </a>
    </div>
</nav>

<div class="flex-1 flex flex-col min-w-0 overflow-y-auto">
    <main class="flex-1 p-6 md:p-10 max-w-[1400px] w-full mx-auto">

        <header class="mb-10 flex flex-col md:flex-row justify-between items-start md:items-end gap-6">
            <div>
                <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white">Tenant <span class="text-primary">Management</span></h2>
                <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-1">Manage Gym Accounts & Subscriptions</p>
            </div>
            <div class="flex flex-wrap items-center gap-3 bg-surface-dark p-3 rounded-2xl border border-white/5">
                <div class="relative">
                    <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 text-sm">search</span>
                    <input type="text" placeholder="Search tenants..." class="bg-background-dark border-none rounded-lg text-[10px] font-bold py-2 pl-9 pr-4 focus:ring-1 focus:ring-primary text-white w-48">
                </div>
                <button class="bg-primary text-white px-4 py-2 rounded-lg text-[9px] font-black uppercase hover:bg-primary/90 transition-all flex items-center gap-2">
                    <span class="material-symbols-outlined text-sm">add_business</span> Add Tenant
                </button>
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

        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-10">
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
        
        <!-- Tab Navigation -->
        <div class="flex items-center gap-8 mb-8 border-b border-white/5 px-2">
            <button onclick="switchTab('registered')" id="tabBtn-registered" class="pb-4 text-xs font-black uppercase tracking-widest transition-all relative group text-primary">
                Registered Gyms
                <div id="tabIndicator-registered" class="absolute bottom-0 left-0 right-0 h-0.5 bg-primary rounded-full transition-all"></div>
            </button>
            <button onclick="switchTab('pending')" id="tabBtn-pending" class="pb-4 text-xs font-black uppercase tracking-widest transition-all relative group text-gray-500 hover:text-white">
                Pending Applications
                <div id="tabIndicator-pending" class="absolute bottom-0 left-0 right-0 h-0.5 bg-primary rounded-full transition-all opacity-0"></div>
                <?php if ($pending_count > 0): ?>
                    <span class="absolute -top-1 -right-4 size-4 bg-amber-500 text-[8px] font-black text-white flex items-center justify-center rounded-full shadow-lg shadow-amber-500/20"><?= $pending_count ?></span>
                <?php endif; ?>
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
                                        <form method="POST" action="../action/process_application.php" class="inline-flex gap-2">
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
                            <th class="px-8 py-4">Sub Status</th>
                            <th class="px-8 py-4">Account Status</th>
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
                                            <div class="size-10 rounded-lg bg-primary/10 flex items-center justify-center font-black text-primary text-sm shadow-inner shadow-primary/20 border border-primary/20">
                                                <?= strtoupper(substr($t['gym_name'], 0, 2)) ?>
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
                                        <?php 
                                            $sub = $t['sub_status'] ?? 'None';
                                            if ($sub === 'Active'):
                                        ?>
                                            <span class="px-3 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-[9px] text-emerald-500 font-black uppercase italic">Active Plan</span>
                                        <?php elseif ($sub === 'Expired' || $sub === 'Overdue'): ?>
                                            <span class="px-3 py-1 rounded-full bg-red-500/10 border border-red-500/20 text-[9px] text-red-500 font-black uppercase italic">Payment Issue</span>
                                        <?php else: ?>
                                            <span class="px-3 py-1 rounded-full bg-gray-500/10 border border-gray-500/20 text-[9px] text-gray-400 font-black uppercase italic">No Plan</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-8 py-5">
                                        <?php if ($t['status'] === 'Active'): ?>
                                            <div class="flex items-center gap-2 text-emerald-400">
                                                <span class="relative flex size-2"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span><span class="relative inline-flex rounded-full size-2 bg-emerald-500"></span></span>
                                                <span class="text-xs font-black uppercase italic tracking-wider">Active</span>
                                            </div>
                                        <?php elseif ($t['status'] === 'Suspended'): ?>
                                            <div class="flex items-center gap-2 text-amber-400">
                                                <span class="relative inline-flex rounded-full size-2 bg-amber-500"></span>
                                                <span class="text-xs font-black uppercase italic tracking-wider">Suspended</span>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-xs font-black uppercase italic text-gray-500 tracking-wider"><?= htmlspecialchars($t['status']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-8 py-5 text-right">
                                        <div class="inline-flex gap-2">
                                            <?php if ($t['application_id']): ?>
                                                <button onclick="openApplicationModal(<?= $t['application_id'] ?>)" title="View Application Details" class="size-8 rounded-lg bg-white/5 hover:bg-white/10 border border-white/5 text-gray-400 flex items-center justify-center transition-colors">
                                                    <span class="material-symbols-outlined text-[18px]">visibility</span>
                                                </button>
                                            <?php endif; ?>

                                            <form method="POST" action="../action/process_tenant.php" class="inline-flex gap-2" onsubmit="return confirm('Are you sure you want to proceed with this action?');">
                                                <input type="hidden" name="gym_id" value="<?= $t['gym_id'] ?>">
                                                
                                                <?php if ($t['status'] !== 'Active'): ?>
                                                    <button type="submit" name="action" value="activate" title="Activate Account" class="size-8 rounded-lg bg-emerald-500/10 hover:bg-emerald-500/20 border border-emerald-500/20 text-emerald-400 flex items-center justify-center transition-colors">
                                                        <span class="material-symbols-outlined text-[18px]">play_circle</span>
                                                    </button>
                                                <?php endif; ?>

                                                <?php if ($t['status'] !== 'Suspended'): ?>
                                                    <button type="submit" name="action" value="suspend" title="Suspend Account (e.g. Unpaid Subscription)" class="size-8 rounded-lg bg-amber-500/10 hover:bg-amber-500/20 border border-amber-500/20 text-amber-400 flex items-center justify-center transition-colors">
                                                        <span class="material-symbols-outlined text-[18px]">pause_circle</span>
                                                    </button>
                                                <?php endif; ?>

                                                <button type="submit" name="action" value="delete" title="Permanently Remove" class="size-8 rounded-lg bg-red-500/10 hover:bg-red-500/20 border border-red-500/20 text-red-400 flex items-center justify-center transition-colors">
                                                    <span class="material-symbols-outlined text-[18px]">delete</span>
                                                </button>
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

    </main>
</div>

<script>
    function switchTab(tabId) {
        const sections = ['pending', 'registered'];
        
        sections.forEach(s => {
            const section = document.getElementById(`section-${s}`);
            const btn = document.getElementById(`tabBtn-${s}`);
            const indicator = document.getElementById(`tabIndicator-${s}`);
            
            if (s === tabId) {
                section.classList.remove('hidden');
                btn.classList.replace('text-gray-500', 'text-primary');
                btn.classList.add('text-primary');
                indicator.classList.replace('opacity-0', 'opacity-100');
            } else {
                section.classList.add('hidden');
                btn.classList.replace('text-primary', 'text-gray-500');
                btn.classList.remove('text-primary');
                indicator.classList.replace('opacity-100', 'opacity-0');
            }
        });
    }
</script>

<!-- Application Viewer Modal -->
<div id="applicationModal" class="fixed inset-y-0 left-64 lg:left-72 right-0 z-[100] hidden items-center justify-center p-4 md:p-10 overflow-hidden pointer-events-none">
    <!-- Backdrop: Only blurs the right side (main content area) -->
    <div class="fixed inset-y-0 left-64 lg:left-72 right-0 bg-background-dark/20 backdrop-blur-xl transition-opacity duration-500 opacity-0 pointer-events-auto" id="modalBackdrop"></div>
    
    <!-- Modal Content Container: Centers automatically within its parent -->
    <div class="relative w-full max-w-4xl bg-surface-dark/60 backdrop-blur-2xl border border-white/10 shadow-2xl rounded-[32px] overflow-hidden flex flex-col max-h-[85vh] transition-all duration-500 scale-95 opacity-0 pointer-events-auto" id="modalContainer">
        <!-- Loading State -->
        <div id="modalLoading" class="absolute inset-0 flex flex-col items-center justify-center bg-surface-dark/80 backdrop-blur-md z-10 transition-opacity duration-300">
            <div class="size-12 border-4 border-primary/20 border-t-primary rounded-full animate-spin mb-4"></div>
            <p class="text-[10px] font-black uppercase text-gray-500 tracking-[0.2em] italic">Loading Details...</p>
        </div>

        <div id="modalContent" class="flex-1 p-8 md:p-10 opacity-0 transition-opacity duration-500 overflow-y-auto no-scrollbar">
            <!-- Content injected via AJAX -->
        </div>
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

        // Show Modal & Start Animations
        modal.classList.replace('hidden', 'flex');
        
        // Trigger visual entry
        setTimeout(() => {
            backdrop.classList.replace('opacity-0', 'opacity-100');
            container.classList.replace('scale-95', 'scale-100');
            container.classList.replace('opacity-0', 'opacity-100');
        }, 10);

        loading.classList.replace('opacity-0', 'opacity-100');
        loading.classList.remove('hidden');
        content.classList.replace('opacity-100', 'opacity-0');

        // Fetch Content
        fetch(`view_application.php?id=${appId}&ajax=1`)
            .then(response => response.text())
            .then(html => {
                content.innerHTML = html;
                
                // Hide loading then show content
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
            content.innerHTML = ''; // Clear content
        }, 500);
    }

    document.getElementById('modalBackdrop').addEventListener('click', closeApplicationModal);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeApplicationModal(); });
</script>

    <?php include '../includes/image_viewer.php'; ?>
</body>
</html>