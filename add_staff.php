<?php 
session_start();
require_once '../db.php';

// Security Check: Only Superadmin can access
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'superadmin') {
    header("Location: ../login.php");
    exit;
}

$page_title = "Sales Report";
$active_page = "sales_report"; 

// Get Filter Inputs (Default to current month)
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$active_tab = $_GET['active_tab'] ?? 'overviewTab';

// 1. TOTAL SALES / REVENUE
$stmtRev = $pdo->prepare("
    SELECT SUM(wp.price) as total 
    FROM client_subscriptions cs
    JOIN website_plans wp ON cs.website_plan_id = wp.website_plan_id
    WHERE cs.payment_status = 'Paid' AND cs.created_at BETWEEN :start AND :end
");
$stmtRev->execute(['start' => $date_from . ' 00:00:00', 'end' => $date_to . ' 23:59:59']);
$total_revenue = $stmtRev->fetchColumn() ?? 0;

// 2. SALES PER TENANT & TOP PERFORMERS
$stmtTenantSales = $pdo->prepare("
    SELECT g.gym_name, g.tenant_code, SUM(wp.price) as total_revenue, COUNT(cs.client_subscription_id) as transaction_count
    FROM gyms g
    JOIN client_subscriptions cs ON g.gym_id = cs.gym_id
    JOIN website_plans wp ON cs.website_plan_id = wp.website_plan_id
    WHERE cs.payment_status = 'Paid' AND cs.created_at BETWEEN :start AND :end
    GROUP BY g.gym_id
    ORDER BY total_revenue DESC
");
$stmtTenantSales->execute(['start' => $date_from . ' 00:00:00', 'end' => $date_to . ' 23:59:59']);
$tenant_sales = $stmtTenantSales->fetchAll(PDO::FETCH_ASSOC);

// 3. DAILY SALES (For Charting)
$stmtDaily = $pdo->prepare("
    SELECT DATE(cs.created_at) as sale_date, SUM(wp.price) as daily_amount 
    FROM client_subscriptions cs
    JOIN website_plans wp ON cs.website_plan_id = wp.website_plan_id
    WHERE cs.payment_status = 'Paid' AND cs.created_at BETWEEN :start AND :end 
    GROUP BY DATE(cs.created_at) 
    ORDER BY sale_date ASC
");
$stmtDaily->execute(['start' => $date_from . ' 00:00:00', 'end' => $date_to . ' 23:59:59']);
$daily_sales = $stmtDaily->fetchAll(PDO::FETCH_ASSOC);

// 4. TRANSACTION HISTORY SUMMARY (Recent 15)
$stmtHistory = $pdo->prepare("
    SELECT cs.*, g.gym_name, wp.plan_name 
    FROM client_subscriptions cs
    JOIN gyms g ON cs.gym_id = g.gym_id
    JOIN website_plans wp ON cs.website_plan_id = wp.website_plan_id
    WHERE cs.created_at BETWEEN :start AND :end
    ORDER BY cs.created_at DESC LIMIT 15
");
$stmtHistory->execute(['start' => $date_from . ' 00:00:00', 'end' => $date_to . ' 23:59:59']);
$transactions = $stmtHistory->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title><?= $page_title ?> | Horizon System</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "#8c2bee", "background-dark": "#0a090d", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)"}}}
        }
    </script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: #0b0a0f; color: #e2e8f0; } /* Softer background matching system_reports */
        .glass-card { background: #16141d; border: 1px solid rgba(255,255,255,0.03); border-radius: 24px; box-shadow: 0 10px 30px -10px rgba(0,0,0,0.5); }
        
        /* Sidebar Hover Logic */
        .sidebar-nav {
            width: 110px;
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
        }
        .sidebar-nav:hover {
            width: 300px;
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
            opacity: 0;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            margin: 0 !important;
            pointer-events: none;
        }
        .sidebar-nav:hover .nav-section-header {
            max-height: 20px;
            opacity: 1;
            margin-bottom: 0.5rem !important;
            pointer-events: auto;
        }
        /* Override for Overview which is the first section */
        .sidebar-nav:hover .nav-section-header.mt-4 { margin-top: 0.75rem !important; }
        .sidebar-nav:hover .nav-section-header.mt-6 { margin-top: 1.25rem !important; }

        .sidebar-content {
            gap: 2px;
            transition: all 0.3s ease-in-out;
        }
        .sidebar-nav:hover .sidebar-content {
            gap: 4px;
        }
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
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        .tab-btn { position: relative; transition: all 0.3s ease; color: #6b7280; cursor: pointer; border: none; outline: none; background: none; } 
        .tab-btn.active { color: #8c2bee; } 
        .tab-indicator { position: absolute; bottom: 0; left: 0; right: 0; height: 2px; background-color: #8c2bee; border-radius: 9999px; transition: all 0.3s ease; opacity: 0; pointer-events: none; }
        .tab-btn.active .tab-indicator { opacity: 1; transform: translateY(-2px); }

        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeIn 0.4s ease-out; }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
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

        function switchTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
            
            const target = document.getElementById(tabId);
            if (target) {
                target.classList.add('active');
                const btn = document.querySelector(`button[onclick*="${tabId}"]`);
                if (btn) btn.classList.add('active');
                
                document.querySelectorAll('.active-tab-input').forEach(input => {
                    input.value = tabId;
                });
            }
        }

        window.addEventListener('DOMContentLoaded', () => {
            const initialTab = "<?= $active_tab ?>";
            switchTab(initialTab);
        });

        function exportReportToPDF(preview = false) {
            const element = document.getElementById('historyTab');
            const reportTitle = "Sales Transaction Report";
            const tenantName = "Horizon System";
            const generatedAt = "<?= date('M d, Y h:i A') ?>";

            const wrapper = document.createElement('div');
            wrapper.style.padding = '50px';
            wrapper.style.color = '#000';
            wrapper.style.backgroundColor = '#fff';
            wrapper.style.fontFamily = "'Roboto Mono', monospace";

            const header = `
                <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 10px;">
                    <div style="text-align: left;">
                        <h1 style="font-size: 32px; font-weight: 800; color: #000; margin: 0; text-transform: uppercase;">${tenantName}</h1>
                    </div>
                    <div style="text-align: right;">
                        <h2 style="font-size: 18px; font-weight: 700; color: #000; margin: 0; text-transform: uppercase;">${reportTitle}</h2>
                    </div>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; font-size: 10px; line-height: 1.6;">
                    <div style="text-align: left; color: #000;">
                        <p style="margin: 0;">Baliwag, Bulacan, Philippines, 3006</p>
                        <p style="margin: 0;">Phone: 0976-241-1986 | Email: horizonfitnesscorp@gmail.com</p>
                    </div>
                    <div style="text-align: right; color: #000;">
                        <p style="margin: 0;">Generated on: ${generatedAt}</p>
                        <p style="margin: 0; font-weight: bold;">OFFICIAL SYSTEM TRANSCRIPT</p>
                    </div>
                </div>
                <div style="border-bottom: 2px solid #000; margin-bottom: 40px;"></div>
            `;

            const contentClone = element.cloneNode(true);
            contentClone.querySelectorAll('button, form, span.material-symbols-outlined, header, .border-b, .px-8.py-6, .flex-wrap').forEach(el => el.remove());

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

            const table = contentClone.querySelector('table');
            if (table) {
                table.style.setProperty('width', '100%', 'important');
                table.style.setProperty('border-collapse', 'collapse', 'important');
                table.style.setProperty('font-size', '11px', 'important');
                table.style.setProperty('color', '#000000', 'important');
                table.style.setProperty('border', '2px solid #000000', 'important');
                table.style.setProperty('font-family', "'Roboto Mono', monospace", 'important');

                table.querySelectorAll('th').forEach(th => {
                    th.style.setProperty('background-color', '#f3f4f6', 'important'); 
                    th.style.setProperty('color', '#000000', 'important');
                    th.style.setProperty('border', '1px solid #000000', 'important');
                    th.style.setProperty('padding', '12px 10px', 'important');
                    th.style.setProperty('text-transform', 'uppercase', 'important');
                    th.style.setProperty('font-weight', 'bold', 'important');
                });

                table.querySelectorAll('td').forEach(td => {
                    td.style.setProperty('border', '1px solid #000000', 'important');
                    td.style.setProperty('padding', '10px 10px', 'important');
                    td.style.setProperty('color', '#000000', 'important');
                    td.querySelectorAll('*').forEach(ch => {
                        ch.style.setProperty('color', '#000000', 'important');
                        ch.style.setProperty('font-size', '11px', 'important');
                        ch.style.setProperty('font-weight', '700', 'important');
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
            footer.innerHTML = `
                <p style="margin: 0; font-weight: bold;">CONFIDENTIAL SALES REPORT - FOR INTERNAL USE ONLY</p>
                <p style="margin: 0;">&copy; ${new Date().getFullYear()} Horizon System. All Rights Reserved.</p>
            `;

            wrapper.innerHTML = header;
            wrapper.appendChild(contentClone);
            wrapper.appendChild(footer);

            const opt = {
                margin:       [0.3, 0.3],
                filename:     `Sales_Report_${new Date().toISOString().split('T')[0]}.pdf`,
                image:        { type: 'jpeg', quality: 1.0 },
                html2canvas:  { scale: 3, backgroundColor: '#ffffff', useCORS: true },
                jsPDF:        { unit: 'in', format: 'a4', orientation: 'portrait' }
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
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Overview</span>
        </div>
        <a href="superadmin_dashboard.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'dashboard') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">grid_view</span> 
            <span class="nav-text">Dashboard</span>
        </a>
        
        <!-- Management Section -->
        <div class="nav-section-header px-0 mb-2 mt-6">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Management</span>
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
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">System</span>
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
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Account</span>
        </div>
        <a href="settings.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'settings') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">settings</span> 
            <span class="nav-text">Settings</span>
        </a>
        <a href="profile.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'profile') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">person</span> 
            <span class="nav-text">Profile</span>
        </a>
        <a href="../logout.php" class="text-gray-400 hover:text-rose-500 transition-colors flex items-center gap-4 group">
            <span class="material-symbols-outlined group-hover:translate-x-1 transition-transform text-xl shrink-0">logout</span>
            <span class="nav-link nav-text">Sign Out</span>
        </a>
    </div>
</nav>

<div class="flex-1 flex flex-col min-w-0 overflow-y-auto">
    <main class="flex-1 p-6 md:p-10 max-w-[1400px] w-full mx-auto">
        <header class="mb-10 flex flex-row justify-between items-end gap-6">
            <div>
                <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white leading-none">Sales <span class="text-primary">Reports</span></h2>
                <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-2">Revenue & Transaction Tracking</p>
            </div>
            <div class="text-right">
                <p id="headerClock" class="text-white font-black italic text-xl tracking-tight leading-none mb-2">00:00:00 AM</p>
                <p class="text-primary text-[9px] font-black uppercase tracking-[0.2em] opacity-80"><?= date('l, M d, Y') ?></p>
            </div>
        </header>

        <div class="glass-card mb-8 p-8">
            <form method="GET" class="flex flex-wrap items-end gap-6">
                <input type="hidden" name="active_tab" class="active-tab-input" value="<?= $active_tab ?>">
                <div class="space-y-2">
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 ml-1">Start Date</p>
                    <input type="date" name="date_from" value="<?= $date_from ?>" class="bg-[#1a1824] border border-white/10 rounded-xl px-4 py-2 text-xs text-white focus:outline-none focus:border-primary">
                </div>
                <div class="space-y-2">
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 ml-1">End Date</p>
                    <input type="date" name="date_to" value="<?= $date_to ?>" class="bg-[#1a1824] border border-white/10 rounded-xl px-4 py-2 text-xs text-white focus:outline-none focus:border-primary">
                </div>
                <div class="flex items-center gap-4">
                    <button type="submit" class="h-10 px-8 rounded-xl bg-primary text-black text-[10px] font-black uppercase italic tracking-widest hover:scale-105 transition-transform active:scale-95 shadow-lg shadow-primary/20">Update Report</button>
                    <a href="sales_report.php" class="text-[10px] font-black uppercase tracking-widest text-gray-500 hover:text-white transition-colors">Clear Filters</a>
                </div>
            </form>
        </div>

        <!-- Tab Navigation -->
        <div class="flex gap-8 border-b border-white/5 mb-8 px-2">
            <button onclick="switchTab('overviewTab')" class="tab-btn pb-4 text-xs font-black uppercase tracking-widest transition-all relative group <?= ($active_tab == 'overviewTab') ? 'active' : '' ?>">
                Analytics Overview
                <div class="tab-indicator"></div>
            </button>
            <button onclick="switchTab('historyTab')" class="tab-btn pb-4 text-xs font-black uppercase tracking-widest transition-all relative group <?= ($active_tab == 'historyTab') ? 'active' : '' ?>">
                Detailed History
                <div class="tab-indicator"></div>
            </button>
        </div>

        <!-- Tab Content -->
        <div id="overviewTab" class="tab-content <?= ($active_tab == 'overviewTab') ? 'active' : '' ?>">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">
            <div class="glass-card p-8 relative overflow-hidden group">
                <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform">payments</span>
                <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-2">Total Revenue</p>
                <h2 class="text-3xl font-black text-white italic mt-2">₱<?= number_format($total_revenue, 2) ?></h2>
                <p class="text-emerald-500 text-[10px] font-black uppercase mt-2 italic">Captured Payments</p>
            </div>
            <div class="glass-card p-8 relative overflow-hidden group border-primary/20 bg-primary/5">
                <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-primary">receipt_long</span>
                <p class="text-[10px] font-black uppercase text-primary/70 tracking-widest mb-2">Transactions</p>
                <h2 class="text-3xl font-black text-white italic mt-2"><?= count($transactions) ?></h2>
                <p class="text-primary text-[10px] font-black uppercase mt-2 italic">Success Rate 100%</p>
            </div>
            <div class="glass-card p-8 relative overflow-hidden group">
                <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform">star</span>
                <p class="text-[10px] font-black uppercase text-gray-400 tracking-widest mb-2">Top Tenant</p>
                <h2 class="text-2xl font-black text-white italic mt-2"><?= $tenant_sales[0]['gym_name'] ?? 'N/A' ?></h2>
                <p class="text-amber-500 text-[10px] font-black uppercase mt-2 italic">Highest Performance</p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-10">
            <div class="lg:col-span-2 glass-card p-8">
                <h3 class="text-sm font-black italic uppercase tracking-widest text-white mb-8">Sales Performance Trend</h3>
                <div class="h-[350px]">
                    <canvas id="salesTrendChart"></canvas>
                </div>
            </div>

            <div class="glass-card p-8 flex flex-col">
                <h3 class="text-sm font-black italic uppercase tracking-widest text-white mb-6">Sales Per Tenant</h3>
                <div class="space-y-4 overflow-y-auto no-scrollbar max-h-[400px]">
                    <?php if (empty($tenant_sales)): ?>
                        <p class="text-xs text-gray-500 italic font-bold text-center mt-10 uppercase">No sales recorded</p>
                    <?php else: ?>
                        <?php foreach ($tenant_sales as $ts): ?>
                        <div class="p-4 rounded-2xl bg-white/[0.02] border border-white/5 flex justify-between items-center hover:bg-white/[0.05] transition-colors">
                            <div>
                                <p class="text-sm font-bold text-white"><?= htmlspecialchars($ts['gym_name']) ?></p>
                                <p class="text-[9px] text-gray-500 font-black uppercase tracking-widest italic"><?= $ts['transaction_count'] ?> sales</p>
                            </div>
                            <p class="text-sm font-black text-primary italic">₱<?= number_format($ts['total_revenue'], 0) ?></p>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        </div>

        <div id="historyTab" class="tab-content <?= ($active_tab == 'historyTab') ? 'active' : '' ?>">

        <div class="glass-card overflow-hidden">
            <div class="px-8 py-6 border-b border-white/5 bg-white/[0.01] flex justify-between items-center">
                <div>
                    <h3 class="text-sm font-black italic uppercase tracking-widest text-white">Transaction History Summary</h3>
                    <p class="text-[9px] text-gray-500 font-bold uppercase tracking-widest mt-1">Detailed logs of all successful payments</p>
                </div>
                <div class="flex items-center gap-3">
                    <button onclick="exportReportToPDF(true)" class="px-5 py-2.5 rounded-xl bg-white/5 border border-white/5 text-[10px] font-black uppercase tracking-widest text-gray-400 hover:text-white hover:bg-white/10 transition-all flex items-center gap-2">
                        <span class="material-symbols-outlined text-sm">visibility</span>
                        Preview
                    </button>
                    <button onclick="exportReportToPDF(false)" class="px-5 py-2.5 rounded-xl bg-primary text-black text-[10px] font-black uppercase tracking-widest hover:scale-105 transition-transform active:scale-95 shadow-lg shadow-primary/20 flex items-center gap-2">
                        <span class="material-symbols-outlined text-sm">picture_as_pdf</span>
                        Export PDF
                    </button>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-background-dark/50 text-gray-500 text-[10px] font-black uppercase tracking-widest">
                            <th class="px-8 py-4">Tenant / ID</th>
                            <th class="px-8 py-4">Plan Type</th>
                            <th class="px-8 py-4">Amount</th>
                            <th class="px-8 py-4">Date</th>
                            <th class="px-8 py-4 text-right">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <?php if (empty($transactions)): ?>
                            <tr><td colspan="5" class="px-8 py-8 text-center text-xs font-bold text-gray-500 italic uppercase">No recent transactions found</td></tr>
                        <?php else: ?>
                            <?php foreach ($transactions as $trx): ?>
                            <tr class="hover:bg-white/[0.01] transition-colors">
                                <td class="px-8 py-5">
                                    <p class="text-sm font-bold text-white"><?= htmlspecialchars($trx['gym_name']) ?></p>
                                    <p class="text-[9px] text-gray-500 font-black uppercase italic tracking-widest">ID: <?= htmlspecialchars($trx['client_subscription_id']) ?></p>
                                </td>
                                <td class="px-8 py-5 text-[10px] font-black text-white uppercase italic"><?= htmlspecialchars($trx['plan_name']) ?></td>
                                <td class="px-8 py-5 text-sm font-black text-primary">₱<?= number_format($trx['price'], 2) ?></td>
                                <td class="px-8 py-5 text-[10px] text-gray-500 font-bold uppercase"><?= date('M d, Y', strtotime($trx['created_at'])) ?></td>
                                <td class="px-8 py-5 text-right">
                                    <span class="px-3 py-1 rounded-full text-[9px] font-black uppercase italic bg-emerald-500/10 text-emerald-500 border border-emerald-500/20">Paid</span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>
</div>

<script>
    const ctx = document.getElementById('salesTrendChart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: [<?php foreach($daily_sales as $ds) echo "'" . date('M d', strtotime($ds['sale_date'])) . "',"; ?>],
            datasets: [{
                label: 'Daily Revenue',
                data: [<?php foreach($daily_sales as $ds) echo $ds['daily_amount'] . ","; ?>],
                borderColor: '#8c2bee',
                backgroundColor: 'rgba(140, 43, 238, 0.05)',
                borderWidth: 4,
                fill: true,
                tension: 0.4,
                pointRadius: 4,
                pointBackgroundColor: '#8c2bee',
                pointBorderColor: 'rgba(255,255,255,0.1)',
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { 
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#14121a',
                    titleColor: '#8c2bee',
                    bodyColor: '#fff',
                    borderColor: 'rgba(255,255,255,0.1)',
                    borderWidth: 1,
                    padding: 10,
                    displayColors: false
                }
            },
            scales: {
                y: { grid: { color: 'rgba(255,255,255,0.03)' }, ticks: { color: '#666', font: { size: 10, weight: '800' } } },
                x: { grid: { display: false }, ticks: { color: '#666', font: { size: 10, weight: '800' } } }
            }
        }
    });
</script>
</body>
</html>