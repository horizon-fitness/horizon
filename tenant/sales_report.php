<?php
session_start();
require_once '../db.php';

// Security Check
$role = strtolower($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || !in_array($role, ['tenant', 'admin'])) {
    header("Location: ../login.php");
    exit;
}

$gym_id = $_SESSION['gym_id'];

// --- DATE FILTERING LOGIC ---
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // Default to start of month
$date_to = $_GET['date_to'] ?? date('Y-m-d');

// --- FINANCIAL CALCULATIONS (Scoped by Date Range) ---
// Total Revenue (within range)
$stmtTotal = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE gym_id = ? AND DATE(created_at) BETWEEN ? AND ?");
$stmtTotal->execute([$gym_id, $date_from, $date_to]);
$total_revenue = $stmtTotal->fetchColumn() ?? 0;

// Lifetime Revenue (For Context)
$stmtLifetime = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE gym_id = ?");
$stmtLifetime->execute([$gym_id]);
$lifetime_revenue = $stmtLifetime->fetchColumn() ?? 0;

// Today's Sales
$stmtDaily = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE gym_id = ? AND DATE(created_at) = CURDATE()");
$stmtDaily->execute([$gym_id]);
$daily_sales = $stmtDaily->fetchColumn() ?? 0;

// --- TRANSACTION HISTORY (Filtered) ---
$stmtHistory = $pdo->prepare("
    SELECT p.*, 
           COALESCE(u_member.first_name, u_owner.first_name) as first_name, 
           COALESCE(u_member.last_name, u_owner.last_name) as last_name 
    FROM payments p 
    LEFT JOIN member_subscriptions ms ON p.subscription_id = ms.subscription_id
    LEFT JOIN members m ON p.member_id = m.member_id
    LEFT JOIN users u_member ON m.user_id = u_member.user_id
    LEFT JOIN client_subscriptions cs ON p.client_subscription_id = cs.client_subscription_id
    LEFT JOIN users u_owner ON cs.owner_user_id = u_owner.user_id 
    WHERE p.gym_id = ? AND DATE(p.created_at) BETWEEN ? AND ?
    ORDER BY p.created_at DESC
");
$stmtHistory->execute([$gym_id, $date_from, $date_to]);
$transactions = $stmtHistory->fetchAll();

// Fetch Gym Branding Info
$stmtGym = $pdo->prepare("SELECT * FROM gyms WHERE gym_id = ?");
$stmtGym->execute([$gym_id]);
$gym_data = $stmtGym->fetch();
$gym_name = $gym_data['gym_name'] ?? 'Horizon Gym';

// Fetch Custom Branding from tenant_pages
$stmtPage = $pdo->prepare("SELECT * FROM tenant_pages WHERE gym_id = ?");
$stmtPage->execute([$gym_id]);
$page = $stmtPage->fetch();

$theme_color = $page['theme_color'] ?? '#8c2bee';
$bg_color = $page['bg_color'] ?? '#0a090d';

// Check Subscription
$stmtSub = $pdo->prepare("SELECT ws.plan_name FROM client_subscriptions cs JOIN website_plans ws ON cs.website_plan_id = ws.website_plan_id WHERE cs.gym_id = ? AND cs.subscription_status = 'Active' LIMIT 1");
$stmtSub->execute([$gym_id]);
$sub = $stmtSub->fetch();
$plan_name = $sub['plan_name'] ?? 'Free Trial';

$active_page = "sales";
?>

<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8">
    <title>Sales Intelligence | Horizon</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;700;900&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: { colors: { "primary": "var(--primary)", "surface-dark": "#14121a", "background-dark": "var(--background)" }}}
        }
    </script>
    <style>
        :root {
            --primary: <?= $theme_color ?>;
            --background: <?= $bg_color ?>;
        }
        body { font-family: 'Lexend', sans-serif; background-color: var(--background); color: white; overflow: hidden; }

        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); }
        .hover-lift:hover { transform: translateY(-10px); border-color: var(--primary); box-shadow: 0 20px 40px -20px var(--primary); }

        /* Unified Expanding Sidebar Navigation */
        .side-nav { width: 110px; transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1); overflow: hidden; display: flex; flex-direction: column; position: fixed; left: 0; top: 0; height: 100vh; z-index: 50; background-color: var(--background); }
        .side-nav:hover { width: 300px; }
        .main-content { margin-left: 110px; flex: 1; min-width: 0; transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1); }
        .side-nav:hover ~ .main-content { margin-left: 300px; }

        .nav-label { opacity: 0; transform: translateX(-15px); transition: all 0.3s ease-in-out; white-space: nowrap; pointer-events: none; }
        .side-nav:hover .nav-label { opacity: 1; transform: translateX(0); pointer-events: auto; }
        
        .nav-section-label { max-height: 0; opacity: 0; overflow: hidden; transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); margin: 0 !important; pointer-events: none; }
        .side-nav:hover .nav-section-label { max-height: 20px; opacity: 1; margin-bottom: 8px !important; pointer-events: auto; }
        
        .nav-item { display: flex; align-items: center; gap: 16px; padding: 10px 38px; transition: all 0.2s ease; text-decoration: none; white-space: nowrap; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; }
        .nav-item:hover { background: rgba(255,255,255,0.05); color: white; }
        .nav-item.active { color: var(--primary) !important; position: relative; }
        .nav-item.active::after { content: ''; position: absolute; right: 0px; top: 50%; transform: translateY(-50%); width: 4px; height: 24px; background: var(--primary); border-radius: 4px 0 0 4px; }
        
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        /* Inputs */
        .input-box { background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 12px; color: white; padding: 10px 16px; font-size: 11px; font-weight: 500; outline: none; transition: all 0.2s; }
        .input-box:focus { border-color: var(--primary); background: rgba(255, 255, 255, 0.08); }
        .input-box option { background: #14121a; color: white; }
    </style>
    <script>
        function updateTopClock() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            const clockEl = document.getElementById('topClock');
            if (clockEl) clockEl.textContent = timeString;
        }
        setInterval(updateTopClock, 1000);
        window.addEventListener('DOMContentLoaded', updateTopClock);
    </script>
</head>
<body class="flex h-screen overflow-hidden">

<body class="antialiased flex h-screen overflow-hidden">

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
        <a href="tenant_dashboard.php" class="nav-item">
            <span class="material-symbols-outlined text-xl shrink-0">grid_view</span> 
            <span class="nav-label">Dashboard</span>
        </a>
        
        <a href="my_users.php" class="nav-item">
            <span class="material-symbols-outlined text-xl shrink-0">group</span> 
            <span class="nav-label">Users</span>
        </a>

        <a href="transactions.php" class="nav-item">
            <span class="material-symbols-outlined text-xl shrink-0">receipt_long</span> 
            <span class="nav-label">Transactions</span>
        </a>

        <a href="attendance.php" class="nav-item">
            <span class="material-symbols-outlined text-xl shrink-0">history</span> 
            <span class="nav-label">Attendance</span>
        </a>

        <div class="nav-section-label px-[38px] mb-2 mt-6"><span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Management</span></div>

        <a href="staff.php" class="nav-item">
            <span class="material-symbols-outlined text-xl shrink-0">badge</span> 
            <span class="nav-label">Staff</span>
        </a>

        <a href="reports.php" class="nav-item">
            <span class="material-symbols-outlined text-xl shrink-0">analytics</span> 
            <span class="nav-label">Reports</span>
        </a>

        <a href="sales_report.php" class="nav-item active">
            <span class="material-symbols-outlined text-xl shrink-0">payments</span> 
            <span class="nav-label">Sales Reports</span>
        </a>
    </div>

    <div class="mt-auto pt-4 border-t border-white/10 shrink-0 pb-6">
        <div class="nav-section-label px-[38px] mb-2"><span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Account</span></div>
        <a href="tenant_settings.php" class="nav-item">
            <span class="material-symbols-outlined text-xl shrink-0">settings</span> 
            <span class="nav-label">Settings</span>
        </a>
        <a href="profile.php" class="nav-item">
            <span class="material-symbols-outlined text-xl shrink-0">account_circle</span> 
            <span class="nav-label">Profile</span>
        </a>
        <a href="../logout.php" class="nav-item text-gray-400 hover:text-rose-500 transition-colors">
            <span class="material-symbols-outlined text-xl shrink-0">logout</span> 
            <span class="nav-label">Sign Out</span>
        </a>
    </div>
</nav>

<main class="main-content flex-1 p-10 overflow-y-auto no-scrollbar">

    <header class="mb-10 flex justify-between items-end">
        <div>
            <h2 class="text-3xl font-black uppercase tracking-tighter text-white italic leading-none">
                SALES <span class="text-primary italic">REPORTS</span>
            </h2>
            <p class="text-gray-500 text-[10px] font-black uppercase tracking-widest mt-1 italic leading-none">
                <?= htmlspecialchars($gym_name) ?> FINANCIAL INTELLIGENCE
            </p>
        </div>

        <div class="flex items-center gap-8">
            <a href="profile.php" class="hidden md:flex items-center gap-2.5 px-6 py-3 rounded-2xl bg-primary/10 border border-primary/20 text-primary text-[10px] font-black uppercase italic tracking-widest hover:bg-primary hover:text-white transition-all active:scale-95 group">
                <span class="material-symbols-outlined text-lg group-hover:scale-110 transition-transform">account_circle</span>
                My Profile
            </a>
            <div class="text-right flex flex-col items-end">
                <p id="topClock" class="text-white font-black italic text-2xl leading-none tracking-tighter">00:00:00 AM</p>
                <p id="topDate" class="text-primary font-bold uppercase tracking-widest text-[10px] mt-2 px-1 opacity-80 italic">
                    <?= date('l, M d, Y') ?>
                </p>
            </div>
        </div>
    </header>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-10">
        <div class="glass-card p-8 hover-lift">
            <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1 italic">Total Revenue</p>
            <h3 class="text-3xl font-black italic tracking-tighter text-white uppercase">₱<?= number_format($total_revenue, 2) ?></h3>
            <div class="mt-4 flex items-center gap-2">
                <span class="size-2 rounded-full bg-primary animate-pulse"></span>
                <span class="text-[9px] font-black text-gray-500 uppercase tracking-widest italic leading-none pt-0.5">Filtered Results</span>
            </div>
        </div>

        <div class="glass-card p-8 hover-lift border-l-4 border-primary/40">
            <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1 italic">Lifetime Sales</p>
            <h3 class="text-3xl font-black italic tracking-tighter text-primary uppercase">₱<?= number_format($lifetime_revenue, 2) ?></h3>
            <p class="text-[9px] font-black text-gray-500 uppercase tracking-widest mt-4 italic leading-none">All-time Recorded</p>
        </div>

        <div class="glass-card p-8 hover-lift">
            <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1 italic">Today's Sales</p>
            <h3 class="text-3xl font-black italic tracking-tighter text-white uppercase">₱<?= number_format($daily_sales, 2) ?></h3>
            <div class="mt-4 flex items-center gap-1.5">
                <span class="text-[9px] font-black text-emerald-500 uppercase tracking-widest italic">Live Tracking</span>
            </div>
        </div>

        <div class="glass-card p-8 hover-lift border-l-4 border-emerald-500/40">
            <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1 italic">Forecast</p>
            <h3 class="text-3xl font-black italic tracking-tighter text-emerald-500 uppercase">₱<?= number_format($daily_sales * 30, 2) ?></h3>
            <p class="text-[9px] font-black text-gray-500 uppercase tracking-widest mt-4 italic leading-none">Estimated Monthly</p>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="glass-card p-6 mb-10 overflow-hidden relative group">
        <form method="GET" class="flex flex-wrap items-end gap-6 relative z-10">
            <div class="flex flex-col gap-2">
                <label class="text-[9px] font-black uppercase text-gray-500 tracking-[0.2em] ml-1 italic">Date From</label>
                <input type="date" name="date_from" value="<?= $date_from ?>" class="input-box w-48 py-3">
            </div>
            <div class="flex flex-col gap-2">
                <label class="text-[9px] font-black uppercase text-gray-500 tracking-[0.2em] ml-1 italic">Date To</label>
                <input type="date" name="date_to" value="<?= $date_to ?>" class="input-box w-48 py-3">
            </div>
            <div class="flex gap-2">
                <button type="submit" class="bg-primary text-white h-[46px] px-8 rounded-xl font-black italic uppercase tracking-tighter text-[11px] shadow-lg shadow-primary/20 hover:scale-105 transition-all active:scale-95 flex items-center gap-2">
                    <span class="material-symbols-outlined text-sm">filter_list</span>
                    Apply Filters
                </button>
                <a href="sales_report.php" class="bg-white/5 text-gray-400 h-[46px] px-6 rounded-xl font-black italic uppercase tracking-tighter text-[11px] hover:text-white hover:bg-white/10 transition-all flex items-center justify-center">
                    Reset
                </a>
            </div>

            <div class="ml-auto flex gap-3 h-[46px]">
                <button type="button" onclick="exportReportToPDF('sales-table-container', 'Sales Report', true)" class="px-6 rounded-xl bg-white/5 border border-white/5 font-black italic uppercase text-[10px] tracking-widest text-gray-500 hover:text-white transition-all flex items-center gap-2">
                    <span class="material-symbols-outlined text-sm">visibility</span> View Report
                </button>
                <button type="button" onclick="exportReportToPDF('sales-table-container', 'Sales Report', false)" class="px-6 rounded-xl bg-primary/10 border border-primary/20 font-black italic uppercase text-[10px] tracking-widest text-primary hover:bg-primary/20 transition-all flex items-center gap-2">
                    <span class="material-symbols-outlined text-sm">picture_as_pdf</span> Get PDF
                </button>
            </div>
        </form>
    </div>

    <div id="sales-table-container" class="bg-surface-dark rounded-[32px] border border-white/5 overflow-hidden shadow-2xl">
        <div class="px-8 py-6 border-b border-white/5 flex justify-between items-center bg-white/[0.02]">
            <h4 class="font-black italic uppercase text-xs tracking-widest text-primary flex items-center gap-2">
                <span class="material-symbols-outlined text-sm">payments</span>
                Transaction History
            </h4>
            <span class="text-[10px] font-black uppercase text-gray-500 tracking-widest">
                Showing <?= count($transactions) ?> Records
            </span>
        </div>
        
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="text-[10px] font-black uppercase tracking-widest text-gray-500 border-b border-white/5">
                        <th class="px-8 py-5">Ref ID</th>
                        <th class="px-8 py-5">Payer / Member</th>
                        <th class="px-8 py-5">Amount</th>
                        <th class="px-8 py-5 text-right">Date & Time</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5 text-sm">
                    <?php if(empty($transactions)): ?>
                        <tr>
                            <td colspan="4" class="px-8 py-20 text-center">
                                <p class="text-[10px] font-black uppercase text-gray-600 tracking-[0.2em] italic">No sales recorded for this period</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach($transactions as $t): ?>
                        <tr class="hover:bg-white/[0.01] transition-all group">
                            <td class="px-8 py-6 font-mono text-xs text-gray-500">#<?= str_pad($t['payment_id'], 6, '0', STR_PAD_LEFT) ?></td>
                            <td class="px-8 py-6">
                                <p class="font-black italic uppercase tracking-tighter text-white">
                                    <?= $t['first_name'] ? htmlspecialchars($t['first_name'].' '.$t['last_name']) : 'Walk-in Guest' ?>
                                </p>
                                <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mt-0.5">
                                    <?= htmlspecialchars($t['payment_method']) ?> Verified
                                </p>
                            </td>
                            <td class="px-8 py-6">
                                <div class="flex flex-col">
                                    <span class="font-black italic text-white">₱<?= number_format($t['amount'], 2) ?></span>
                                    <span class="text-[8px] font-black text-primary uppercase tracking-widest">Paid In Full</span>
                                </div>
                            </td>
                            <td class="px-8 py-6 text-right">
                                <div class="flex flex-col items-end leading-tight">
                                    <span class="font-black uppercase text-primary tracking-tighter italic text-xs">
                                        <?= date('M d, Y', strtotime($t['created_at'])) ?>
                                    </span>
                                    <span class="text-[10px] font-bold text-gray-500 uppercase tracking-widest mt-1">
                                        <?= date('h:i A', strtotime($t['created_at'])) ?>
                                    </span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

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

    function exportReportToPDF(sectionId, reportTitle, preview = false) {
        const element = document.getElementById(sectionId);
        const gymName = "<?= htmlspecialchars($gym_name) ?>";
        const generatedAt = "<?= date('M d, Y h:i A') ?>";
        const dateRange = "<?= date('M d, Y', strtotime($date_from)) ?> to <?= date('M d, Y', strtotime($date_to)) ?>";

        // Dynamic Header (Surgical Style)
        const header = `
            <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 5px; font-family: 'Roboto Mono', monospace;">
                <div style="text-align: left;">
                    <h1 style="font-size: 28px; font-weight: 800; color: #000; margin: 0; text-transform: uppercase;">${gymName}</h1>
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
                    <p style="margin: 0; font-weight: bold; border-top: 1px solid #000; padding-top: 5px; margin-top: 5px;">OFFICIAL FINANCIAL TRANSCRIPT</p>
                </div>
            </div>
            <div style="border-bottom: 3px double #000; margin-bottom: 30px;"></div>
        `;

        const wrapper = document.createElement('div');
        wrapper.style.padding = '50px';
        wrapper.style.color = '#000';
        wrapper.style.backgroundColor = '#fff';
        wrapper.style.fontFamily = "'Roboto Mono', monospace";

        // Surgical Cloning
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

        contentClone.querySelectorAll('button, .material-symbols-outlined, h3, h4').forEach(el => el.remove());
        
        const table = contentClone.querySelector('table');
        if (table) {
            table.style.setProperty('width', '100%', 'important');
            table.style.setProperty('border-collapse', 'collapse', 'important');
            table.style.setProperty('font-size', '10px', 'important');
            table.style.setProperty('border', '2px solid #000', 'important');

            table.querySelectorAll('th').forEach(th => {
                th.style.setProperty('background-color', '#eee', 'important'); 
                th.style.setProperty('border', '1px solid #000', 'important');
                th.style.setProperty('padding', '12px 10px', 'important');
                th.style.setProperty('text-transform', 'uppercase', 'important');
                th.style.setProperty('font-weight', '900', 'important');
                th.style.setProperty('text-align', 'left', 'important');
            });

            table.querySelectorAll('td').forEach(td => {
                td.style.setProperty('border', '1px solid #000', 'important');
                td.style.setProperty('padding', '10px 10px', 'important');
                td.style.setProperty('background-color', '#fff', 'important');

                td.querySelectorAll('*').forEach(ch => {
                    ch.style.setProperty('color', '#000', 'important');
                    ch.style.setProperty('font-size', '10px', 'important');
                    ch.style.setProperty('font-weight', '700', 'important');
                });
            });
        }

        const footer = document.createElement('div');
        footer.style.marginTop = '60px';
        footer.style.textAlign = 'center';
        footer.style.fontSize = '9px';
        footer.style.borderTop = '1px solid #000';
        footer.style.paddingTop = '15px';
        footer.innerHTML = `<p style="margin: 0; font-weight: bold;">FINANCIAL DATA PRIVACY NOTICE - INTERNAL ONLY</p>`;

        wrapper.innerHTML = header;
        wrapper.appendChild(contentClone);
        wrapper.appendChild(footer);

        const opt = {
            margin: [0.3, 0.3],
            filename: `Sales_Report_${new Date().getTime()}.pdf`,
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
