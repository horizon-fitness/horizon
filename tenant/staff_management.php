<?php
session_start();
require_once '../db.php';

// Security Check: Only Tenants (Gym Owners)
$role = strtolower($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || ($role !== 'tenant' && $role !== 'admin')) {
    header("Location: ../login.php");
    exit;
}

$gym_id = $_SESSION['gym_id'];
$user_id = $_SESSION['user_id'];
$active_page = "staff";

// Fetch Gym Details
$stmtGym = $pdo->prepare("SELECT * FROM gyms WHERE gym_id = ?");
$stmtGym->execute([$gym_id]);
$gym = $stmtGym->fetch();

// Check Subscription (for sidebar)
$stmtSub = $pdo->prepare("SELECT ws.plan_name FROM client_subscriptions cs JOIN website_plans ws ON cs.website_plan_id = ws.website_plan_id WHERE cs.gym_id = ? AND cs.subscription_status = 'Active' LIMIT 1");
$stmtSub->execute([$gym_id]);
$sub = $stmtSub->fetch();

// Fetch CMS Page (for sidebar branding)
$stmtPage = $pdo->prepare("SELECT * FROM tenant_pages WHERE gym_id = ? LIMIT 1");
$stmtPage->execute([$gym_id]);
$page = $stmtPage->fetch();

// Fetch Staff Members
$stmtStaff = $pdo->prepare("
    SELECT s.*, u.first_name, u.last_name, u.email
    FROM staff s 
    JOIN users u ON s.user_id = u.user_id 
    WHERE s.gym_id = ? 
    ORDER BY s.created_at DESC
");
$stmtStaff->execute([$gym_id]);
$staff_members = $stmtStaff->fetchAll(PDO::FETCH_ASSOC);

$total_staff = count($staff_members);
$active_staff = 0;
foreach($staff_members as $s) {
    if($s['status'] === 'Active') $active_staff++;
}
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Staff Management | Horizon Tenant</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "#8c2bee", "background-dark": "#0a090d", "surface-dark": "#121017", "border-subtle": "rgba(255,255,255,0.05)"}}}
        }
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; overflow: hidden; }
        .glass-card { background: #121017; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
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
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
    <script>
        function updateSidebarClock() {
            const now = new Date();
            const clockEl = document.getElementById('sidebarClock');
            if (clockEl) {
                clockEl.textContent = now.toLocaleTimeString('en-US', { 
                    hour: '2-digit', minute: '2-digit', second: '2-digit' 
                });
            }
        }
        setInterval(updateSidebarClock, 1000);
        window.addEventListener('DOMContentLoaded', updateSidebarClock);
    </script>
</head>
<body class="antialiased flex min-h-screen">

<nav class="flex flex-col w-64 bg-[#0a090d] border-r border-white/5 sticky top-0 h-screen p-8 z-50 shrink-0">
    <div class="mb-12">
        <div class="flex items-center gap-3 mb-6">
            <div class="size-10 rounded-xl bg-primary flex items-center justify-center shadow-lg shrink-0 overflow-hidden">
                <?php if (!empty($page['logo_path'])): ?>
                    <img src="<?= $page['logo_path'] ?>" class="size-full object-contain">
                <?php else: ?>
                    <span class="material-symbols-outlined text-white text-2xl">bolt</span>
                <?php endif; ?>
            </div>
            <h1 class="text-lg font-black italic uppercase tracking-tighter text-white leading-none break-words line-clamp-2"><?= htmlspecialchars($page['page_title'] ?? $gym['gym_name']) ?></h1>
        </div>
        <div class="p-4 rounded-2xl bg-white/5 border border-white/5">
            <div class="mb-2">
                <p id="sidebarClock" class="text-white font-black italic text-base leading-none">00:00:00 AM</p>
            </div>
            <p class="text-[9px] font-black uppercase text-gray-500 tracking-[0.2em] leading-none mb-1"><?= date('l, M d') ?></p>
            <div class="pt-2 border-t border-white/5 mt-2">
                <p class="text-[8px] font-black uppercase text-gray-600 tracking-widest mb-1">Current Plan</p>
                <div class="flex items-center justify-between">
                    <p class="text-[10px] font-black uppercase text-white italic tracking-tighter"><?= htmlspecialchars($sub['plan_name'] ?? 'Standard Plan') ?></p>
                    <span class="px-2 py-0.5 rounded-md bg-primary/20 text-primary text-[8px] font-black uppercase tracking-widest">Active</span>
                </div>
            </div>
        </div>
    </div>
    
    <div class="flex flex-col gap-5 flex-1 overflow-y-auto no-scrollbar pr-2">
        <a href="tenant_dashboard.php" class="nav-link flex items-center gap-3 <?= ($active_page == 'dashboard') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl">dashboard</span> Dashboard
        </a>
        <a href="tenant_settings.php" class="nav-link flex items-center gap-3 <?= ($active_page == 'settings') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl">palette</span> Page Customize
        </a>
        <a href="staff_management.php" class="nav-link flex items-center gap-3 <?= ($active_page == 'staff') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl">group</span> Staff Management
        </a>
        <a href="#" class="nav-link flex items-center gap-3 text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl">person_search</span> Member Directory
        </a>
        <a href="#" class="nav-link flex items-center gap-3 text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl">payments</span> Billing & Revenue
        </a>
    </div>

    <div class="mt-auto pt-8 border-t border-white/10">
        <a href="../logout.php" class="text-gray-400 hover:text-red-500 transition-colors flex items-center gap-3 group">
            <span class="material-symbols-outlined group-hover:translate-x-1 transition-transform">logout</span>
            <span class="nav-link">Sign Out</span>
        </a>
    </div>
</nav>

<div class="flex-1 flex flex-col min-w-0 overflow-y-auto w-full max-w-[1400px] mx-auto p-6 md:p-10 no-scrollbar">

    <header class="mb-10 flex flex-col md:flex-row justify-between items-start md:items-end gap-6">
        <div>
            <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white">Staff <span class="text-primary">Management</span></h2>
            <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-1">Manage Gym Personnel & Accounts</p>
        </div>
        <div class="flex flex-wrap items-center gap-3 bg-white/5 p-3 rounded-2xl border border-white/5">
            <div class="relative">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 text-sm">search</span>
                <input type="text" placeholder="Search staff..." class="bg-[#0a090d] border border-white/5 rounded-lg text-[10px] font-bold py-2 pl-9 pr-4 focus:outline-none focus:border-primary text-white w-48 transition-colors">
            </div>
            <button onclick="location.href='add_staff.php'" class="bg-primary text-white px-4 py-2 rounded-lg text-[9px] font-black uppercase tracking-widest hover:opacity-90 transition-all flex items-center gap-2 shadow-lg shadow-primary/20">
                <span class="material-symbols-outlined text-sm">person_add</span> Add Staff
            </button>
        </div>
    </header>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-10">
        <div class="glass-card p-6 border-l-4 border-primary/50 flex items-center gap-4 group hover:border-primary transition-colors cursor-pointer">
            <div class="size-12 rounded-full bg-primary/10 flex items-center justify-center text-primary group-hover:scale-110 transition-transform">
                <span class="material-symbols-outlined text-2xl">shield_person</span>
            </div>
            <div>
                <p class="text-[10px] font-black uppercase text-gray-400 tracking-widest">Total Staff</p>
                <h3 class="text-2xl font-black italic uppercase"><?= $total_staff ?></h3>
            </div>
        </div>
        <div class="glass-card p-6 border-l-4 border-emerald-500/50 flex items-center gap-4 group hover:border-emerald-500 transition-colors cursor-pointer">
            <div class="size-12 rounded-full bg-emerald-500/10 flex items-center justify-center text-emerald-500 group-hover:scale-110 transition-transform">
                <span class="material-symbols-outlined text-2xl">how_to_reg</span>
            </div>
            <div>
                <p class="text-[10px] font-black uppercase text-emerald-500/70 tracking-widest">Active Personnel</p>
                <h3 class="text-2xl font-black italic uppercase text-emerald-400"><?= $active_staff ?></h3>
            </div>
        </div>
    </div>

    <!-- Data Table -->
    <div class="glass-card overflow-hidden">
        <div class="px-8 py-6 border-b border-white/5 bg-white/5 flex justify-between items-center">
            <h4 class="font-black italic uppercase text-sm tracking-tighter flex items-center gap-2">
                <span class="material-symbols-outlined text-primary">groups_2</span> Personnel Roster
            </h4>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="bg-black/20 text-gray-500 text-[10px] font-black uppercase tracking-widest border-b border-white/5">
                        <th class="px-8 py-4 whitespace-nowrap">Staff Member</th>
                        <th class="px-8 py-4 whitespace-nowrap">Assigned Role</th>
                        <th class="px-8 py-4 whitespace-nowrap">Employment Type</th>
                        <th class="px-8 py-4 whitespace-nowrap">Status</th>
                        <th class="px-8 py-4 text-right whitespace-nowrap">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php if (empty($staff_members)): ?>
                        <tr>
                            <td colspan="5" class="px-8 py-12 text-center">
                                <span class="material-symbols-outlined text-gray-700 text-5xl mb-4">group_off</span>
                                <p class="text-xs font-bold text-gray-500 uppercase tracking-widest">No staff members found.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($staff_members as $s): ?>
                            <tr class="hover:bg-white/5 transition-all">
                                <td class="px-8 py-5">
                                    <div class="flex items-center gap-3">
                                        <div class="size-10 rounded-xl bg-primary/10 flex items-center justify-center font-black text-primary text-sm shadow-inner overflow-hidden border border-primary/20 shrink-0">
                                            <?= strtoupper(substr($s['first_name'], 0, 1) . substr($s['last_name'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <p class="text-sm font-bold text-white"><?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?></p>
                                            <p class="text-[10px] text-gray-500">ID: STAFF-<?= str_pad($s['staff_id'], 4, '0', STR_PAD_LEFT) ?></p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-8 py-5">
                                    <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-white/5 border border-white/5">
                                        <?php if(strtolower($s['staff_role']) === 'coach'): ?>
                                            <span class="material-symbols-outlined text-[14px] text-amber-500">sports</span>
                                        <?php elseif(strtolower($s['staff_role']) === 'manager'): ?>
                                            <span class="material-symbols-outlined text-[14px] text-primary">admin_panel_settings</span>
                                        <?php else: ?>
                                            <span class="material-symbols-outlined text-[14px] text-gray-400">badge</span>
                                        <?php endif; ?>
                                        <span class="text-[10px] font-black uppercase tracking-widest text-gray-300"><?= htmlspecialchars($s['staff_role']) ?></span>
                                    </div>
                                </td>
                                <td class="px-8 py-5">
                                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest"><?= htmlspecialchars($s['employment_type']) ?></p>
                                    <p class="text-[9px] text-gray-600 mt-0.5">Hired: <?= date('M d, Y', strtotime($s['hire_date'])) ?></p>
                                </td>
                                <td class="px-8 py-5">
                                    <?php if ($s['status'] === 'Active'): ?>
                                        <div class="flex items-center gap-2 text-emerald-400">
                                            <span class="relative flex size-2"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span><span class="relative inline-flex rounded-full size-2 bg-emerald-500"></span></span>
                                            <span class="text-[10px] font-black uppercase tracking-widest">Active</span>
                                        </div>
                                    <?php else: ?>
                                        <div class="flex items-center gap-2 text-gray-500">
                                            <span class="relative inline-flex rounded-full size-2 bg-gray-500"></span>
                                            <span class="text-[10px] font-black uppercase tracking-widest"><?= htmlspecialchars($s['status']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-8 py-5 text-right">
                                    <div class="inline-flex gap-2">
                                        <button title="Edit Staff Member" class="size-8 rounded-lg bg-white/5 hover:bg-white/10 border border-white/5 text-gray-400 flex items-center justify-center transition-colors">
                                            <span class="material-symbols-outlined text-[16px]">edit</span>
                                        </button>
                                        <form method="POST" action="../action/process_staff.php" class="inline-block" onsubmit="return confirm('Suspend this staff member?');">
                                            <input type="hidden" name="staff_id" value="<?= $s['staff_id'] ?>">
                                            <button type="submit" name="action" value="suspend" title="Suspend Staff" class="size-8 rounded-lg bg-amber-500/10 hover:bg-amber-500/20 border border-amber-500/20 text-amber-500 flex items-center justify-center transition-colors">
                                                <span class="material-symbols-outlined text-[16px]">pause_circle</span>
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
    </div>
</div>

</body>
</html>
