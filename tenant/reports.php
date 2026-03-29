<?php
session_start();
require_once '../db.php';

// Security Check
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'tenant') {
    header("Location: ../login.php");
    exit;
}

$gym_id = $_SESSION['gym_id'] ?? null;
$user_id = $_SESSION['user_id'] ?? null;

if (!$user_id || !$gym_id) {
    header("Location: ../login.php");
    exit;
}

// Fetch Filter Inputs
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$date_params = ['gid' => $gym_id, 'start' => $date_from . ' 00:00:00', 'end' => $date_to . ' 23:59:59'];

// Fetch Gym & Owner Details
$stmtGym = $pdo->prepare("
    SELECT g.gym_name, g.email as gym_email, g.contact_number as gym_contact, u.first_name, u.last_name, g.owner_user_id
    FROM gyms g 
    JOIN users u ON g.owner_user_id = u.user_id 
    WHERE g.gym_id = ?
");
$stmtGym->execute([$gym_id]);
$gym_data = $stmtGym->fetch();

$gym_name = $gym_data['gym_name'] ?? 'Horizon Gym';
$first_name = $gym_data['first_name'] ?? 'Owner';
$active_page = "reports";

// Fetch Branding Data from tenant_pages
$stmtPage = $pdo->prepare("SELECT * FROM tenant_pages WHERE gym_id = ?");
$stmtPage->execute([$gym_id]);
$page = $stmtPage->fetch();

$theme_color = ($page && isset($page['theme_color'])) ? $page['theme_color'] : '#8c2bee';
$bg_color = ($page && isset($page['bg_color'])) ? $page['bg_color'] : '#0a090d';

// Fetch Active Subscription / Plan for the Gym
$stmtSub = $pdo->prepare("
    SELECT wp.plan_name 
    FROM client_subscriptions cs 
    JOIN website_plans wp ON cs.website_plan_id = wp.website_plan_id 
    WHERE cs.gym_id = ? 
    ORDER BY cs.created_at DESC LIMIT 1
");
$stmtSub->execute([$gym_id]);
$plan_name = $stmtSub->fetchColumn() ?: 'Standard Plan';

// Fetch Financial Transactions (Money Reports)
$stmtFinancials = $pdo->prepare("
    SELECT payment_id, amount, payment_method, created_at, reference_number, payment_status
    FROM payments 
    WHERE gym_id = :gid AND payment_status = 'Verified' AND created_at BETWEEN :start AND :end
    ORDER BY created_at DESC
");
$stmtFinancials->execute($date_params);
$financials = $stmtFinancials->fetchAll();

// Fetch Attendance (Entry Logs)
$stmtAttendance = $pdo->prepare("
    SELECT u.first_name, u.last_name, a.check_in_time, a.check_out_time, a.attendance_status, a.recorded_by
    FROM attendance a
    JOIN members m ON a.member_id = m.member_id
    JOIN users u ON m.user_id = u.user_id
    WHERE a.gym_id = :gid AND a.created_at BETWEEN :start AND :end
    ORDER BY a.created_at DESC
");
$stmtAttendance->execute($date_params);
$attendance_logs = $stmtAttendance->fetchAll();

// Fetch Member Subscriptions (Memberships)
$stmtSubscriptions = $pdo->prepare("
    SELECT u.first_name, u.last_name, mp.plan_name, ms.start_date, ms.end_date, ms.subscription_status
    FROM member_subscriptions ms
    JOIN members m ON ms.member_id = m.member_id
    JOIN users u ON m.user_id = u.user_id
    JOIN membership_plans mp ON ms.membership_plan_id = mp.membership_plan_id
    WHERE m.gym_id = :gid AND ms.created_at BETWEEN :start AND :end
    ORDER BY ms.created_at DESC
");
$stmtSubscriptions->execute($date_params);
$subscriptions = $stmtSubscriptions->fetchAll();

// Summary Counters for Selected Period
$total_money = array_reduce($financials, fn($sum, $f) => $sum + (float)$f['amount'], 0);
$total_entries = count($attendance_logs);
$total_active_subs = count(array_filter($subscriptions, fn($s) => $s['subscription_status'] === 'Active'));
?>


<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8">
    <title>Reports & Analytics | Horizon</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;700&display=swap" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { 
                extend: { 
                    colors: { 
                        "primary": "<?= $theme_color ?>", 
                        "background-dark": "<?= $bg_color ?>", 
                        "surface-dark": "#14121a", 
                        "border-subtle": "rgba(255,255,255,0.05)"
                    }
                }
            }
        }
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: <?= $bg_color ?>; color: white; overflow: hidden; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); }
        .hover-lift:hover { transform: translateY(-10px); border-color: <?= $theme_color ?>40; box-shadow: 0 20px 40px -20px <?= $theme_color ?>30; }

        /* Unified Sidebar Navigation Styles */
        .side-nav { width: 110px; transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1); overflow: hidden; display: flex; flex-direction: column; position: fixed; left: 0; top: 0; height: 100vh; z-index: 50; }
        .side-nav:hover { width: 300px; }
        .main-content { margin-left: 110px; flex: 1; min-width: 0; transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1); }
        .side-nav:hover ~ .main-content { margin-left: 300px; }

        .nav-label { opacity: 0; transform: translateX(-15px); transition: all 0.3s ease-in-out; white-space: nowrap; pointer-events: none; }
        .side-nav:hover .nav-label { opacity: 1; transform: translateX(0); pointer-events: auto; }
        
        .nav-section-label { max-height: 0; opacity: 0; overflow: hidden; transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); margin: 0 !important; pointer-events: none; }
        .side-nav:hover .nav-section-label { max-height: 20px; opacity: 1; margin-bottom: 8px !important; pointer-events: auto; }
        
        .nav-item { display: flex; align-items: center; gap: 16px; padding: 10px 38px; transition: all 0.2s ease; text-decoration: none; white-space: nowrap; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; }
        .nav-item:hover { background: rgba(255,255,255,0.05); color: white; }
        .nav-item.active { color: <?= $theme_color ?> !important; position: relative; }
        .nav-item.active::after { content: ''; position: absolute; right: 0px; top: 50%; transform: translateY(-50%); width: 4px; height: 24px; background: <?= $theme_color ?>; border-radius: 4px 0 0 4px; }
        
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        .report-tab-active { color: <?= $theme_color ?> !important; border-bottom: 2px solid <?= $theme_color ?> !important; }
        .report-tab-inactive { color: #555555 !important; border-bottom: 2px solid transparent !important; }
        .report-tab-inactive:hover { color: #ffffff !important; }
        
        /* Modal Styles */
        .modal-backdrop { background: rgba(0,0,0,0.8); backdrop-filter: blur(8px); }
        .modal-content { background: #14121a; border: 1px solid rgba(255,255,255,0.1); box-shadow: 0 0 50px rgba(0,0,0,0.5); }

        /* Inputs */
        .input-box { background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 12px; color: white; padding: 10px 16px; font-size: 11px; font-weight: 500; outline: none; transition: all 0.2s; }
        .input-box:focus { border-color: <?= $theme_color ?>; background: rgba(255, 255, 255, 0.08); }
        .input-box option { background: #14121a; color: white; }

        .line-tab { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; padding: 16px 0; margin-right: 32px; transition: all 0.2s; border-bottom: 2px solid transparent; }
    </style>
</head>
<body class="flex h-screen overflow-hidden">

<nav class="side-nav bg-background-dark border-r border-white/5 z-50">
    <div class="px-7 py-8 mb-4 shrink-0">
        <div class="flex items-center gap-4">
            <div class="size-10 rounded-xl shrink-0 overflow-hidden flex items-center justify-center <?= empty($page['logo_path']) ? 'bg-primary shadow-lg shadow-primary/20' : '' ?>">
                <?php if (!empty($page['logo_path'])): ?>
                    <img src="<?= htmlspecialchars($page['logo_path']) ?>" class="size-full object-cover">
                <?php else: ?>
                    <span class="material-symbols-outlined text-white text-2xl">bolt</span>
                <?php endif; ?>
            </div>
            <h1 class="nav-label text-lg font-black italic uppercase tracking-tighter text-white">Owner Portal</h1>
        </div>
    </div>
    
    <div class="flex-1 overflow-y-auto no-scrollbar space-y-1">
        <div class="nav-section-label px-[38px] mb-2"><span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Main Menu</span></div>
        <a href="tenant_dashboard.php" class="nav-item <?= ($active_page == 'dashboard') ? 'active' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">grid_view</span> 
            <span class="nav-label">Dashboard</span>
        </a>
        
        <a href="my_users.php" class="nav-item <?= ($active_page == 'users') ? 'active' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">group</span> 
            <span class="nav-label">Users</span>
        </a>

        <a href="transactions.php" class="nav-item <?= ($active_page == 'transactions') ? 'active' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">receipt_long</span> 
            <span class="nav-label">Transactions</span>
        </a>

        <a href="attendance.php" class="nav-item <?= ($active_page == 'attendance') ? 'active' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">history</span> 
            <span class="nav-label">Attendance</span>
        </a>

        <div class="nav-section-label px-[38px] mb-2 mt-6"><span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Management</span></div>

        <a href="staff.php" class="nav-item <?= ($active_page == 'staff') ? 'active' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">badge</span> 
            <span class="nav-label">Staff</span>
        </a>

        <a href="reports.php" class="nav-item <?= ($active_page == 'reports') ? 'active' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">analytics</span> 
            <span class="nav-label">Reports</span>
        </a>

        <a href="sales_report.php" class="nav-item <?= ($active_page == 'sales') ? 'active' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">payments</span> 
            <span class="nav-label">Sales Reports</span>
        </a>
    </div>

    <div class="mt-auto pt-4 border-t border-white/10 shrink-0 pb-6">
        <div class="nav-section-label px-[38px] mb-2"><span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Account</span></div>
        <a href="tenant_settings.php" class="nav-item <?= ($active_page == 'settings') ? 'active' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">settings</span> 
            <span class="nav-label">Settings</span>
        </a>
        <a href="profile.php" class="nav-item <?= ($active_page == 'profile') ? 'active' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">account_circle</span> 
            <span class="nav-label">Profile</span>
        </a>
        <a href="../logout.php" class="nav-item text-gray-400 hover:text-rose-500 transition-colors">
            <span class="material-symbols-outlined text-xl shrink-0">logout</span> 
            <span class="nav-label">Sign Out</span>
        </a>
    </div>
</nav>

<script>
    function updateTopClock() {
        const now = new Date();
        const timeString = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        const dateString = now.toLocaleDateString('en-US', { weekday: 'long', month: 'short', day: '2-digit', year: 'numeric' });
        
        const clockEl = document.getElementById('topClock');
        const dateEl = document.getElementById('topDate');
        
        if (clockEl) clockEl.textContent = timeString;
        if (dateEl) dateEl.textContent = dateString;
    }
    setInterval(updateTopClock, 1000);
    window.addEventListener('DOMContentLoaded', updateTopClock);
</script>

<main class="main-content flex-1 p-10 overflow-y-auto no-scrollbar">
    <header class="mb-10 flex justify-between items-end">
        <div>
            <h2 class="text-3xl font-black uppercase tracking-tighter text-white italic leading-none">
                GYM <span class="text-primary italic">REPORTS</span>
            </h2>
            <p class="text-gray-500 text-[10px] font-black uppercase tracking-widest mt-1 italic leading-none">
                <?= htmlspecialchars($gym_name) ?> ACTIVITY AND METRICS
            </p>
        </div>

        <div class="text-right flex flex-col items-end">
            <p id="topClock" class="text-white font-black italic text-2xl leading-none tracking-tighter">00:00:00 AM</p>
            <p id="topDate" class="text-primary font-bold uppercase tracking-widest text-[10px] mt-2 px-1 opacity-80 italic">
                <?= date('l, M d, Y') ?>
            </p>
        </div>
    </header>

    <!-- Summary Statistics -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        <div class="glass-card p-6 flex items-center gap-6 group hover:translate-y-[-5px] transition-all">
            <div class="size-14 rounded-2xl bg-primary/10 flex items-center justify-center text-primary group-hover:scale-110 transition-transform">
                <span class="material-symbols-outlined text-3xl">payments</span>
            </div>
            <div>
                <p class="text-[10px] font-black uppercase tracking-widest text-gray-500 mb-1">Total Revenue</p>
                <h3 class="text-2xl font-black italic text-white tracking-tighter">₱<?= number_format($total_money, 2) ?></h3>
            </div>
        </div>
        <div class="glass-card p-6 flex items-center gap-6 group hover:translate-y-[-5px] transition-all">
            <div class="size-14 rounded-2xl bg-emerald-500/10 flex items-center justify-center text-emerald-500 group-hover:scale-110 transition-transform">
                <span class="material-symbols-outlined text-3xl">login</span>
            </div>
            <div>
                <p class="text-[10px] font-black uppercase tracking-widest text-gray-500 mb-1">Total Entries</p>
                <h3 class="text-2xl font-black italic text-white tracking-tighter"><?= number_format($total_entries) ?></h3>
            </div>
        </div>
        <div class="glass-card p-6 flex items-center gap-6 group hover:translate-y-[-5px] transition-all">
            <div class="size-14 rounded-2xl bg-amber-500/10 flex items-center justify-center text-amber-500 group-hover:scale-110 transition-transform">
                <span class="material-symbols-outlined text-3xl">stars</span>
            </div>
            <div>
                <p class="text-[10px] font-black uppercase tracking-widest text-gray-500 mb-1">Active Subs</p>
                <h3 class="text-2xl font-black italic text-white tracking-tighter"><?= number_format($total_active_subs) ?></h3>
            </div>
        </div>
    </div>

    <!-- Polished Filter Bar -->
    <div class="mb-10">
        <form method="GET" class="glass-card p-8 border border-white/5 relative overflow-hidden">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 items-end">
                <div class="space-y-2 md:col-span-1">
                    <p class="text-[9px] font-black uppercase tracking-widest text-primary ml-1 flex items-center gap-2">
                        <span class="material-symbols-outlined text-xs">calendar_today</span> Start Date
                    </p>
                    <input type="date" name="date_from" value="<?= $date_from ?>" class="input-box w-full">
                </div>
                <div class="space-y-2 md:col-span-1">
                    <p class="text-[9px] font-black uppercase tracking-widest text-primary ml-1 flex items-center gap-2">
                        <span class="material-symbols-outlined text-xs">event</span> End Date
                    </p>
                    <input type="date" name="date_to" value="<?= $date_to ?>" class="input-box w-full">
                </div>
                <div class="md:col-span-2 flex items-center justify-end gap-3">
                    <a href="reports.php" class="h-11 flex items-center px-6 rounded-xl bg-white/5 border border-white/10 text-[10px] font-black uppercase tracking-widest text-gray-400 hover:text-white transition-all">Clear</a>
                    <button type="submit" class="h-11 px-10 rounded-xl bg-primary text-black text-[10px] font-black uppercase italic tracking-widest hover:scale-105 transition-all shadow-lg shadow-primary/20">Apply Activity Filter</button>
                </div>
            </div>
        </form>
    </div>

    <div class="glass-card overflow-hidden shadow-2xl min-h-[500px] border border-white/5">
        
        <div class="px-10 border-b border-white/10 flex bg-white/[0.01]">
            <div class="flex items-center">
                <button onclick="switchReport('financial')" id="btn-financial" class="line-tab report-tab-active">Money</button>
                <button onclick="switchReport('attendance')" id="btn-attendance" class="line-tab report-tab-inactive">Entry Log</button>
                <button onclick="switchReport('membership')" id="btn-membership" class="line-tab report-tab-inactive">Memberships</button>
            </div>
        </div>

        <div id="section-financial" class="report-section">
            <div class="px-8 py-6 border-b border-white/5 bg-white/[0.02] flex justify-between items-center">
                <h3 class="font-black italic uppercase text-xs tracking-widest text-[#8c2bee]">Money Reports</h3>
                <div class="flex gap-2">
                    <button onclick="exportReportToPDF('section-financial', 'Money Report', true)" class="text-[10px] font-black uppercase text-gray-500 border border-white/5 px-4 py-2 rounded-lg hover:text-white transition-all flex items-center gap-2">
                        <span class="material-symbols-outlined text-sm">visibility</span>
                        View Report
                    </button>
                    <button onclick="exportReportToPDF('section-financial', 'Money Report', false)" class="text-[10px] font-black uppercase text-primary border border-primary/20 px-4 py-2 rounded-lg hover:bg-primary/10 transition-all flex items-center gap-2">
                        <span class="material-symbols-outlined text-sm">picture_as_pdf</span>
                        Get PDF
                    </button>
                </div>
            </div>
            <table class="w-full text-left">
                <thead class="text-[10px] font-black uppercase text-gray-500 tracking-widest border-b border-white/5">
                    <tr>
                        <th class="px-8 py-5">Ref ID</th>
                        <th class="px-8 py-5">Amount</th>
                        <th class="px-8 py-5">Method</th>
                        <th class="px-8 py-5 text-right">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5 text-sm font-medium">
                    <?php foreach($financials as $f): ?>
                    <tr class="hover:bg-white/[0.01]">
                        <td class="px-8 py-6 text-gray-400 font-mono">#<?= $f['payment_id'] ?></td>
                        <td class="px-8 py-6 font-black text-white italic">₱<?= number_format($f['amount'], 2) ?></td>
                        <td class="px-8 py-6 uppercase text-[10px] font-bold"><?= $f['payment_method'] ?></td>
                        <td class="px-8 py-6 text-right text-gray-500"><?= date('M d, Y', strtotime($f['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div id="section-attendance" class="report-section hidden">
            <div class="px-8 py-6 border-b border-white/5 bg-white/[0.02] flex justify-between items-center">
                <h3 class="font-black italic uppercase text-xs tracking-widest text-emerald-500">Entry Log</h3>
                <div class="flex gap-2">
                    <button onclick="exportReportToPDF('section-attendance', 'Entry Report', true)" class="text-[10px] font-black uppercase text-gray-500 border border-white/5 px-4 py-2 rounded-lg hover:text-white transition-all flex items-center gap-2">
                        <span class="material-symbols-outlined text-sm">visibility</span>
                        View Report
                    </button>
                    <button onclick="exportReportToPDF('section-attendance', 'Entry Report', false)" class="text-[10px] font-black uppercase text-emerald-500 border border-emerald-500/20 px-4 py-2 rounded-lg hover:bg-emerald-500/10 transition-all flex items-center gap-2">
                        <span class="material-symbols-outlined text-sm">picture_as_pdf</span>
                        Get PDF
                    </button>
                </div>
            </div>
            <table class="w-full text-left">
                <thead class="text-[10px] font-black uppercase text-gray-500 tracking-widest border-b border-white/5">
                    <tr>
                        <th class="px-8 py-5">Member</th>
                        <th class="px-8 py-5">Check In</th>
                        <th class="px-8 py-5">Check Out</th>
                        <th class="px-8 py-5 text-right">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5 text-sm">
                    <?php foreach($attendance_logs as $a): ?>
                    <tr class="hover:bg-white/[0.01]">
                        <td class="px-8 py-6 font-black italic uppercase tracking-tighter"><?= htmlspecialchars($a['first_name'] . ' ' . $a['last_name']) ?></td>
                        <td class="px-8 py-6 text-emerald-400 font-bold"><?= date('h:i A', strtotime($a['check_in_time'])) ?></td>
                        <td class="px-8 py-6 text-gray-500"><?= $a['check_out_time'] ? date('h:i A', strtotime($a['check_out_time'])) : '---' ?></td>
                        <td class="px-8 py-6 text-right">
                            <span class="px-2 py-0.5 rounded bg-emerald-500/10 text-emerald-500 text-[9px] font-black uppercase">
                                <?= htmlspecialchars($a['attendance_status']) ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div id="section-membership" class="report-section hidden">
            <div class="px-8 py-6 border-b border-white/5 bg-white/[0.02] flex justify-between items-center">
                <h3 class="font-black italic uppercase text-xs tracking-widest text-amber-500">Memberships</h3>
                <div class="flex gap-2">
                    <button onclick="exportReportToPDF('section-membership', 'Membership Report', true)" class="text-[10px] font-black uppercase text-gray-500 border border-white/5 px-4 py-2 rounded-lg hover:text-white transition-all flex items-center gap-2">
                        <span class="material-symbols-outlined text-sm">visibility</span>
                        View Report
                    </button>
                    <button onclick="exportReportToPDF('section-membership', 'Membership Report', false)" class="text-[10px] font-black uppercase text-amber-500 border border-amber-500/20 px-4 py-2 rounded-lg hover:bg-amber-500/10 transition-all flex items-center gap-2">
                        <span class="material-symbols-outlined text-sm">picture_as_pdf</span>
                        Get PDF
                    </button>
                </div>
            </div>
            <table class="w-full text-left">
                <thead class="text-[10px] font-black uppercase text-gray-500 tracking-widest border-b border-white/5">
                    <tr>
                        <th class="px-8 py-5">Member</th>
                        <th class="px-8 py-5">Tier</th>
                        <th class="px-8 py-5">Renewal Date</th>
                        <th class="px-8 py-5 text-right">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5 text-sm">
                    <?php foreach($subscriptions as $s): ?>
                    <tr class="hover:bg-white/[0.01]">
                        <td class="px-8 py-6 font-black italic uppercase tracking-tighter"><?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?></td>
                        <td class="px-8 py-6 text-[10px] font-black uppercase text-gray-400 italic"><?= htmlspecialchars($s['plan_name']) ?></td>
                        <td class="px-8 py-6 text-gray-500 font-bold"><?= date('M d, Y', strtotime($s['end_date'])) ?></td>
                        <td class="px-8 py-6 text-right">
                            <span class="px-3 py-1 rounded-full text-[8px] font-black uppercase border border-white/5 bg-white/5">
                                <?= htmlspecialchars($s['subscription_status']) ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    </div>
</main>

<script>
    function switchReport(type) {
        const sections = document.querySelectorAll('.report-section');
        sections.forEach(s => s.classList.add('hidden'));
        document.getElementById('section-' + type).classList.remove('hidden');

        const tabs = ['financial', 'attendance', 'membership'];
        tabs.forEach(t => {
            const btn = document.getElementById('btn-' + t);
            if (t === type) {
                btn.classList.add('report-tab-active');
                btn.classList.remove('report-tab-inactive');
            } else {
                btn.classList.remove('report-tab-active');
                btn.classList.add('report-tab-inactive');
            }
        });
    }

    function exportReportToPDF(sectionId, reportTitle, preview = false) {
        const element = document.getElementById(sectionId);
        const gymName = "<?= htmlspecialchars($gym_name) ?>";
        const generatedAt = "<?= date('M d, Y h:i A') ?>";
        const dateRange = "<?= date('M d, Y', strtotime($date_from)) ?> to <?= date('M d, Y', strtotime($date_to)) ?>";

        // 1. DYNAMIC HEADER (SUPERADMIN STYLE)
        const header = `
            <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 5px;">
                <div style="text-align: left;">
                    <h1 style="font-size: 28px; font-weight: 800; color: #000; margin: 0; text-transform: uppercase; font-family: 'Roboto Mono', monospace;">${gymName}</h1>
                </div>
                <div style="text-align: right;">
                    <h2 style="font-size: 16px; font-weight: 700; color: #000; margin: 0; text-transform: uppercase;">${reportTitle}</h2>
                </div>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 25px; font-size: 10px; line-height: 1.6; font-family: 'Roboto Mono', monospace;">
                <div style="text-align: left; color: #000;">
                    <p style="margin: 0;"><?= htmlspecialchars($gym_data['gym_email'] ?? '') ?></p>
                    <p style="margin: 0;">Phone: <?= htmlspecialchars($gym_data['gym_contact'] ?? '') ?></p>
                </div>
                <div style="text-align: right; color: #000;">
                    <p style="margin: 0;">Period: ${dateRange}</p>
                    <p style="margin: 0;">Generated on: ${generatedAt}</p>
                    <p style="margin: 0; font-weight: bold; border-top: 1px solid #000; padding-top: 5px; margin-top: 5px;">OFFICIAL ACTIVITY TRANSCRIPT</p>
                </div>
            </div>
            <div style="border-bottom: 3px double #000; margin-bottom: 30px;"></div>
        `;

        const wrapper = document.createElement('div');
        wrapper.style.padding = '50px';
        wrapper.style.color = '#000';
        wrapper.style.backgroundColor = '#fff';
        wrapper.style.fontFamily = "'Roboto Mono', monospace";

        // 2. SURGICAL CLONING & CLASS STRIPPING
        const contentClone = element.cloneNode(true);
        [contentClone, ...contentClone.querySelectorAll('*')].forEach(el => {
            el.removeAttribute('class');
            el.style.setProperty('color', '#000000', 'important');
            el.style.setProperty('background-color', 'transparent', 'important');
            el.style.setProperty('border-radius', '0', 'important');
            el.style.setProperty('box-shadow', 'none', 'important');
            el.style.setProperty('text-shadow', 'none', 'important');
            el.style.setProperty('filter', 'none', 'important');
            el.style.setProperty('opacity', '1', 'important');
            el.style.setProperty('visibility', 'visible', 'important');
        });

        // Hide UI elements
        contentClone.querySelectorAll('button, .material-symbols-outlined, h3, .flex:has(button)').forEach(el => el.remove());
        
        // 3. TRANSFORM TABLE INTO FORMAL GRID (SUPERADMIN STYLE)
        const table = contentClone.querySelector('table');
        if (table) {
            table.style.setProperty('width', '100%', 'important');
            table.style.setProperty('border-collapse', 'collapse', 'important');
            table.style.setProperty('font-size', '10px', 'important');
            table.style.setProperty('color', '#000', 'important');
            table.style.setProperty('border', '2px solid #000', 'important');
            table.style.setProperty('font-family', "'Roboto Mono', monospace", 'important');

            table.querySelectorAll('th').forEach(th => {
                th.style.setProperty('background-color', '#eee', 'important'); 
                th.style.setProperty('color', '#000', 'important');
                th.style.setProperty('border', '1px solid #000', 'important');
                th.style.setProperty('padding', '12px 10px', 'important');
                th.style.setProperty('text-transform', 'uppercase', 'important');
                th.style.setProperty('font-weight', '900', 'important');
                th.style.setProperty('text-align', 'left', 'important');
            });

            table.querySelectorAll('td').forEach(td => {
                td.style.setProperty('border', '1px solid #000', 'important');
                td.style.setProperty('padding', '10px 10px', 'important');
                td.style.setProperty('color', '#000', 'important');
                td.style.setProperty('background-color', '#fff', 'important');
                td.style.setProperty('vertical-align', 'middle', 'important');

                td.querySelectorAll('*').forEach(ch => {
                    ch.style.setProperty('color', '#000', 'important');
                    ch.style.setProperty('font-size', '10px', 'important');
                    ch.style.setProperty('margin', '0', 'important');
                    ch.style.setProperty('font-weight', '700', 'important');
                    ch.style.setProperty('text-decoration', 'none', 'important');
                    ch.style.setProperty('opacity', '1', 'important');
                });
            });
        }

        const footer = document.createElement('div');
        footer.style.marginTop = '60px';
        footer.style.textAlign = 'center';
        footer.style.fontSize = '9px';
        footer.style.color = '#000';
        footer.style.borderTop = '1px solid #000';
        footer.style.paddingTop = '15px';
        footer.style.fontFamily = "'Roboto Mono', monospace";
        footer.innerHTML = `
            <p style="margin: 0; font-weight: bold;">CONFIDENTIAL DOCUMENT - FOR INTERNAL USE ONLY</p>
            <p style="margin: 0;">&copy; ${new Date().getFullYear()} Horizon System. All Rights Reserved.</p>
        `;

        wrapper.innerHTML = header;
        wrapper.appendChild(contentClone);
        wrapper.appendChild(footer);

        const opt = {
            margin: [0.3, 0.3],
            filename: `${reportTitle.replace(/\s+/g, '_')}_${new Date().getTime()}.pdf`,
            image: { type: 'jpeg', quality: 1.0 },
            html2canvas: { scale: 3, backgroundColor: '#ffffff', useCORS: true },
            jsPDF: { unit: 'in', format: 'a4', orientation: 'portrait' }
        };

        if (preview) {
            html2pdf().set(opt).from(wrapper).toPdf().get('pdf').then(function (pdf) {
                window.open(pdf.output('bloburl'), '_blank');
            });
        } else {
            html2pdf().set(opt).from(wrapper).save();
        }
    }
</script>


</body>
</html>
