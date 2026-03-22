<?php 
session_start();
require_once '../db.php';

// Security Check: Only Superadmin can access
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'superadmin') {
    header("Location: ../login.php");
    exit;
}

$page_title = "System Reports";
$active_page = "reports"; 

// Get Filter Inputs
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$tenant_filter = $_GET['tenant_id'] ?? 'all';
$active_tab = $_GET['active_tab'] ?? 'detailedTab';

// 1. Fetch Tenants for Filter Dropdown
$tenants_list = $pdo->query("SELECT gym_id, gym_name FROM gyms WHERE status = 'Active' ORDER BY gym_name ASC")->fetchAll();

// 2. User Registration Statistics (Grouped by Date)
$date_params = ['start' => $date_from . ' 00:00:00', 'end' => $date_to . ' 23:59:59'];
$user_reg_query = "SELECT DATE(u.created_at) as reg_date, COUNT(*) as count FROM users u";
if ($tenant_filter !== 'all') {
    $user_reg_query .= " JOIN user_roles ur ON u.user_id = ur.user_id WHERE ur.gym_id = :tid AND u.created_at BETWEEN :start AND :end";
    $reg_params = array_merge($date_params, ['tid' => $tenant_filter]);
} else {
    $user_reg_query .= " WHERE u.created_at BETWEEN :start AND :end";
    $reg_params = $date_params;
}
$user_reg_query .= " GROUP BY DATE(u.created_at) ORDER BY reg_date ASC";
$stmtReg = $pdo->prepare($user_reg_query);
$stmtReg->execute($reg_params);
$registration_data = $stmtReg->fetchAll(PDO::FETCH_ASSOC);

// 3. Usage Statistics
// Total Users
$total_users_query = "SELECT COUNT(*) FROM users u";
if ($tenant_filter !== 'all') {
    $total_users_query .= " JOIN user_roles ur ON u.user_id = ur.user_id WHERE ur.gym_id = :tid";
}
$stmtTotal = $pdo->prepare($total_users_query);
$stmtTotal->execute($tenant_filter !== 'all' ? ['tid' => $tenant_filter] : []);
$total_users = number_format($stmtTotal->fetchColumn());

// Avg Daily Logins
$login_query = "SELECT COUNT(*) FROM audit_logs WHERE action_type = 'Login' AND created_at BETWEEN :start AND :end";
if ($tenant_filter !== 'all') { $login_query .= " AND gym_id = :tid"; }
$stmtLogins = $pdo->prepare($login_query);
$login_params = $date_params;
if ($tenant_filter !== 'all') { $login_params['tid'] = $tenant_filter; }
$stmtLogins->execute($login_params);
$total_logins = $stmtLogins->fetchColumn();
$days_diff = (strtotime($date_to) - strtotime($date_from)) / 86400;
$days = max(1, round($days_diff) + 1);
$avg_daily_logins = number_format($total_logins / $days, 1);

// Peak Usage Hour
$peak_query = "SELECT HOUR(created_at) as hr, COUNT(*) as cnt FROM audit_logs WHERE created_at BETWEEN :start AND :end";
if ($tenant_filter !== 'all') { $peak_query .= " AND gym_id = :tid"; }
$peak_query .= " GROUP BY hr ORDER BY cnt DESC LIMIT 1";
$stmtPeak = $pdo->prepare($peak_query);
$stmtPeak->execute($login_params);
$peak_row = $stmtPeak->fetch(PDO::FETCH_ASSOC);
$peak_hour = $peak_row ? date('h:00 A', strtotime($peak_row['hr'] . ':00')) : 'N/A';

// 4. Multi-Report Selection Logic
$report_type = $_GET['report_type'] ?? 'tenant_activity';
$report_data = [];

switch ($report_type) {
    case 'gym_apps':
        $sql = "SELECT a.*, u.first_name, u.last_name, r.first_name as rev_f, r.last_name as rev_l 
                FROM gym_owner_applications a 
                JOIN users u ON a.user_id = u.user_id 
                LEFT JOIN users r ON a.reviewed_by = r.user_id
                WHERE a.submitted_at BETWEEN :start AND :end ORDER BY a.submitted_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($date_params);
        $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'active_gyms':
        $sql = "SELECT g.*, u.first_name, u.last_name, ad.address_line, ad.city 
                FROM gyms g 
                JOIN users u ON g.owner_user_id = u.user_id 
                JOIN gym_addresses ad ON g.address_id = ad.address_id
                WHERE g.created_at BETWEEN :start AND :end";
        if ($tenant_filter !== 'all') { $sql .= " AND g.gym_id = :tid"; }
        $sql .= " ORDER BY g.created_at DESC";
        $stmt = $pdo->prepare($sql);
        $params = $date_params;
        if ($tenant_filter !== 'all') { $params['tid'] = $tenant_filter; }
        $stmt->execute($params);
        $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'client_subs':
        $sql = "SELECT g.gym_name, u.first_name, u.last_name, p.plan_name, cs.* 
                FROM client_subscriptions cs 
                JOIN gyms g ON cs.gym_id = g.gym_id 
                JOIN users u ON cs.owner_user_id = u.user_id 
                JOIN website_plans p ON cs.website_plan_id = p.website_plan_id
                WHERE cs.created_at BETWEEN :start AND :end";
        if ($tenant_filter !== 'all') { $sql .= " AND cs.gym_id = :tid"; }
        $stmt = $pdo->prepare($sql);
        $params = $date_params;
        if ($tenant_filter !== 'all') { $params['tid'] = $tenant_filter; }
        $stmt->execute($params);
        $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'system_alerts':
        $sql = "SELECT * FROM system_alerts WHERE created_at BETWEEN :start AND :end ORDER BY created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($date_params);
        $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'audit_report':
        $sql = "SELECT al.*, u.first_name, u.last_name 
                FROM audit_logs al 
                LEFT JOIN users u ON al.user_id = u.user_id
                WHERE al.created_at BETWEEN :start AND :end";
        if ($tenant_filter !== 'all') { $sql .= " AND al.gym_id = :tid"; }
        $sql .= " ORDER BY al.created_at DESC";
        $stmt = $pdo->prepare($sql);
        $params = $date_params;
        if ($tenant_filter !== 'all') { $params['tid'] = $tenant_filter; }
        $stmt->execute($params);
        $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;

    default: // tenant_activity
        $sql = "SELECT g.gym_name, g.tenant_code, g.status, g.created_at as joined_date,
                 COUNT(DISTINCT m.member_id) as member_count,
                 COUNT(DISTINCT al.audit_log_id) as activity_count
                 FROM gyms g
                 LEFT JOIN members m ON g.gym_id = m.gym_id
                 LEFT JOIN audit_logs al ON g.gym_id = al.gym_id AND al.created_at BETWEEN :start AND :end
                 WHERE 1=1";
        if ($tenant_filter !== 'all') { $sql .= " AND g.gym_id = :tid"; }
        $sql .= " GROUP BY g.gym_id ORDER BY activity_count DESC";
        $stmt = $pdo->prepare($sql);
        $params = $date_params;
        if ($tenant_filter !== 'all') { $params['tid'] = $tenant_filter; }
        $stmt->execute($params);
        $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;
}

?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title><?= $page_title ?> | Horizon System</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;700&display=swap" rel="stylesheet"/>
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
        body { font-family: 'Lexend', sans-serif; background-color: #0b0a0f; color: #e2e8f0; } /* Softer background */
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

        .tab-btn { position: relative; transition: all 0.3s ease; color: #6b7280; cursor: pointer; } /* text-gray-400 equivalent */
        .tab-btn.active { color: #8c2bee; } /* text-primary */
        .tab-indicator { position: absolute; bottom: 0; left: 0; right: 0; height: 2px; background-color: #8c2bee; border-radius: 9999px; transition: all 0.3s ease; opacity: 0; pointer-events: none; }
        .tab-btn.active .tab-indicator { opacity: 1; transform: translateY(-2px); }

        .premium-select-container { position: relative; }
        .premium-select { 
            background: #1a1824;
            border: 2px solid rgba(140, 43, 238, 0.4);
            transition: border-color 0.3s ease;
        }
        .premium-select:hover, .premium-select:focus {
            border-color: #8c2bee;
        }

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
            // Remove active classes
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
            
            // Add active to targeted content
            const target = document.getElementById(tabId);
            if (target) {
                target.classList.add('active');
                
                // Find and activate the matching button
                const btn = document.querySelector(`button[onclick*="${tabId}"]`);
                if (btn) btn.classList.add('active');
                
                // Update all hidden active_tab inputs in forms
                document.querySelectorAll('.active-tab-input').forEach(input => {
                    input.value = tabId;
                });
            }
        }

        // Initialize Tab on Load
        window.addEventListener('DOMContentLoaded', () => {
            const initialTab = "<?= $active_tab ?>";
            switchTab(initialTab);
        });
    </script>
</head>
<body class="antialiased flex flex-row min-h-screen">

<nav class="sidebar-nav flex flex-col bg-[#0b0a0f] border-r border-white/5 sticky top-0 h-screen px-7 py-8 z-50 shrink-0">
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
        <a href="#" class="text-gray-400 hover:text-white transition-colors flex items-center gap-4 group">
            <span class="material-symbols-outlined transition-transform group-hover:text-primary text-xl shrink-0">person</span>
            <span class="nav-link nav-text">Profile</span>
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
                <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white leading-none">System <span class="text-primary">Reports</span></h2>
                <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-2">Analytical Insights & Performance</p>
            </div>
            <div class="text-right">
                <p id="headerClock" class="text-white font-black italic text-xl tracking-tight leading-none mb-2">00:00:00 AM</p>
                <p class="text-primary text-[9px] font-black uppercase tracking-[0.2em] opacity-80"><?= date('l, M d, Y') ?></p>
            </div>
        </header>

        <div class="glass-card mb-8 p-8">
            <form method="GET" class="flex flex-wrap items-end gap-6 report-form">
                <input type="hidden" name="active_tab" class="active-tab-input" value="<?= $active_tab ?>">
                <input type="hidden" name="report_type" value="<?= $report_type ?>">
                <div class="space-y-2">
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 ml-1">Date From</p>
                    <input type="date" name="date_from" value="<?= $date_from ?>" class="bg-[#1a1824] border border-white/10 rounded-xl px-4 py-2 text-xs text-white focus:outline-none focus:border-primary">
                </div>
                <div class="space-y-2">
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 ml-1">Date To</p>
                    <input type="date" name="date_to" value="<?= $date_to ?>" class="bg-[#1a1824] border border-white/10 rounded-xl px-4 py-2 text-xs text-white focus:outline-none focus:border-primary">
                </div>
                <div class="space-y-2">
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 ml-1">Select Tenant</p>
                    <select name="tenant_id" class="bg-[#1a1824] border border-white/10 rounded-xl px-4 py-2 text-xs text-white focus:outline-none focus:border-primary">
                        <option value="all">All Tenants</option>
                        <?php foreach($tenants_list as $gt): ?>
                            <option value="<?= $gt['gym_id'] ?>" <?= $tenant_filter == $gt['gym_id'] ? 'selected' : '' ?>><?= htmlspecialchars($gt['gym_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex items-center gap-4">
                    <button type="submit" class="h-10 px-8 rounded-xl bg-primary text-black text-[10px] font-black uppercase italic tracking-widest hover:scale-105 transition-transform active:scale-95 shadow-lg shadow-primary/20">Generate Report</button>
                    <a href="system_reports.php" class="text-[10px] font-black uppercase tracking-widest text-gray-500 hover:text-white transition-colors">Clear Filters</a>
                </div>
            </form>
        </div>

        <!-- Tab Navigation -->
        <div class="flex gap-8 border-b border-white/5 mb-8 px-2">
            <button onclick="switchTab('detailedTab')" class="tab-btn pb-4 text-xs font-black uppercase tracking-widest transition-all relative group <?= ($active_tab == 'detailedTab') ? 'active' : '' ?>">
                Detailed Reports
                <div class="tab-indicator"></div>
            </button>
            <button onclick="switchTab('overviewTab')" class="tab-btn pb-4 text-xs font-black uppercase tracking-widest transition-all relative group <?= ($active_tab == 'overviewTab') ? 'active' : '' ?>">
                Analytics Overview
                <div class="tab-indicator"></div>
            </button>
        </div>

        <!-- Tab Content -->
        <!-- Tab 1: Detailed Reports -->
        <div id="detailedTab" class="tab-content <?= ($active_tab == 'detailedTab') ? 'active' : '' ?>">
            <?php 
                $report_titles = [
                    'tenant_activity' => ['title' => 'Tenant Activity Report', 'desc' => 'Member Interaction Per Gym'],
                    'gym_apps' => ['title' => 'Gym Owner Applications', 'desc' => 'Review new gym registration requests'],
                    'active_gyms' => ['title' => 'Active Gyms Report', 'desc' => 'Overview of all registered gyms in the system'],
                    'client_subs' => ['title' => 'Client Subscription Report', 'desc' => 'Tracking gym owner website plans and payments'],
                    'system_alerts' => ['title' => 'System Alerts Report', 'desc' => 'Monitoring unresolved and high priority alerts'],
                    'audit_report' => ['title' => 'System Audit Report', 'desc' => 'Detailed tracking of system record changes']
                ];
                $curr_report = $report_titles[$report_type] ?? $report_titles['tenant_activity'];
            ?>
            <div class="glass-card overflow-hidden">
                <div class="px-8 py-6 border-b border-white/5 flex flex-wrap justify-between items-center gap-4 bg-white/[0.01]">
                    <div>
                        <h3 class="text-sm font-black italic uppercase tracking-widest text-white leading-none"><?= $curr_report['title'] ?></h3>
                        <p class="text-[9px] text-gray-500 font-bold uppercase tracking-widest mt-1"><?= $curr_report['desc'] ?></p>
                    </div>
                    <div class="flex items-center gap-3">
                        <form method="GET" id="reportTypeForm" class="flex items-center report-form">
                            <!-- Preserve other filters -->
                            <input type="hidden" name="date_from" value="<?= $date_from ?>">
                            <input type="hidden" name="date_to" value="<?= $date_to ?>">
                            <input type="hidden" name="tenant_id" value="<?= $tenant_filter ?>">
                            <input type="hidden" name="active_tab" class="active-tab-input" value="<?= $active_tab ?>">
                            
                            <div class="relative group premium-select-container">
                                <select name="report_type" onchange="this.form.submit()" 
                                        class="premium-select appearance-none rounded-full px-8 py-3 text-[11px] font-black uppercase tracking-[0.15em] text-white focus:outline-none cursor-pointer pr-12 min-w-[280px]">
                                    <?php foreach ($report_titles as $key => $data): ?>
                                        <option value="<?= $key ?>" <?= $report_type == $key ? 'selected' : '' ?>><?= $data['title'] ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="material-symbols-outlined absolute right-5 top-1/2 -translate-y-1/2 text-primary text-lg pointer-events-none group-hover:scale-110 transition-transform">expand_more</span>
                            </div>
                        </form>
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
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-black/20 text-gray-500 text-[10px] font-black uppercase tracking-widest">
                                <?php switch($report_type): 
                                    case 'gym_apps': ?>
                                        <th class="px-8 py-4">Applicant</th>
                                        <th class="px-8 py-4">Gym / Business</th>
                                        <th class="px-8 py-4">Type</th>
                                        <th class="px-8 py-4">Submitted</th>
                                        <th class="px-8 py-4 text-center">Status</th>
                                        <?php break;
                                    case 'active_gyms': ?>
                                        <th class="px-8 py-4">Gym Info</th>
                                        <th class="px-8 py-4">Owner</th>
                                        <th class="px-8 py-4">Contact</th>
                                        <th class="px-8 py-4">Address</th>
                                        <th class="px-8 py-4 text-center">Status</th>
                                        <?php break;
                                    case 'client_subs': ?>
                                        <th class="px-8 py-4">Gym / Owner</th>
                                        <th class="px-8 py-4">Plan</th>
                                        <th class="px-8 py-4">Duration</th>
                                        <th class="px-8 py-4 text-center">Sub Status</th>
                                        <th class="px-8 py-4 text-right">Payment</th>
                                        <?php break;
                                    case 'system_alerts': ?>
                                        <th class="px-8 py-4">Type / Source</th>
                                        <th class="px-8 py-4 w-1/3">Message</th>
                                        <th class="px-8 py-4 text-center">Priority</th>
                                        <th class="px-8 py-4 text-center">Status</th>
                                        <th class="px-8 py-4 text-right">Timestamp</th>
                                        <?php break;
                                    case 'audit_report': ?>
                                        <th class="px-8 py-4">Performer</th>
                                        <th class="px-8 py-4">Table / ID</th>
                                        <th class="px-8 py-4">Changes</th>
                                        <th class="px-8 py-4 text-right">Timestamp</th>
                                        <?php break;
                                    default: // tenant_activity ?>
                                        <th class="px-8 py-4">Tenant Info</th>
                                        <th class="px-8 py-4 text-center">Members</th>
                                        <th class="px-8 py-4 text-center">Activities</th>
                                        <th class="px-8 py-4 text-center">Status</th>
                                        <th class="px-8 py-4 text-right">Joined Date</th>
                                <?php endswitch; ?>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <?php if (empty($report_data)): ?>
                                <tr><td colspan="6" class="px-8 py-12 text-center text-xs text-gray-600 italic uppercase font-bold tracking-widest">No reports found for the selected period</td></tr>
                            <?php else: ?>
                                <?php foreach ($report_data as $row): ?>
                                <tr class="hover:bg-white/[0.01] transition-colors group">
                                    <?php switch($report_type):
                                        case 'gym_apps': ?>
                                            <td class="px-8 py-5">
                                                <p class="text-sm font-bold text-white mb-1"><?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?></p>
                                                <p class="text-[9px] text-gray-500 font-black italic">Applicant ID: #<?= $row['user_id'] ?></p>
                                            </td>
                                            <td class="px-8 py-5">
                                                <p class="text-sm font-bold text-white mb-1"><?= htmlspecialchars($row['gym_name']) ?></p>
                                                <p class="text-[9px] text-primary font-black uppercase tracking-tighter"><?= htmlspecialchars($row['business_name']) ?></p>
                                            </td>
                                            <td class="px-8 py-5 text-xs text-gray-400 font-bold uppercase tracking-widest italic"><?= htmlspecialchars($row['business_type']) ?></td>
                                            <td class="px-8 py-5">
                                                <p class="text-xs font-bold text-white"><?= date('M d, Y', strtotime($row['submitted_at'])) ?></p>
                                                <p class="text-[9px] text-gray-500 font-black italic"><?= date('h:i A', strtotime($row['submitted_at'])) ?></p>
                                            </td>
                                            <td class="px-8 py-5 text-center">
                                                <div class="mb-1">
                                                    <span class="px-3 py-1 rounded-md bg-white/5 border border-white/10 text-[9px] text-gray-400 font-black uppercase italic">
                                                        <?= htmlspecialchars($row['application_status']) ?>
                                                    </span>
                                                </div>
                                                <?php if($row['rev_f']): ?>
                                                    <p class="text-[8px] text-gray-600 font-bold uppercase tracking-tighter">By: <?= htmlspecialchars($row['rev_f'] . ' ' . $row['rev_l']) ?></p>
                                                <?php endif; ?>
                                                <?php if($row['remarks']): ?>
                                                    <p class="text-[8px] text-primary/50 italic mt-1 truncate max-w-[100px] mx-auto" title="<?= htmlspecialchars($row['remarks']) ?>"><?= htmlspecialchars($row['remarks']) ?></p>
                                                <?php endif; ?>
                                            </td>
                                            <?php break;
                                        case 'active_gyms': ?>
                                            <td class="px-8 py-5">
                                                <p class="text-sm font-bold text-white mb-1"><?= htmlspecialchars($row['gym_name']) ?></p>
                                                <p class="text-[9px] text-primary font-black uppercase tracking-tighter italic"><?= htmlspecialchars($row['tenant_code']) ?></p>
                                            </td>
                                            <td class="px-8 py-5 text-sm font-bold text-white"><?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?></td>
                                            <td class="px-8 py-5">
                                                <p class="text-xs font-bold text-white mb-1"><?= htmlspecialchars($row['contact_number']) ?></p>
                                                <p class="text-[9px] text-gray-500 font-black uppercase italic"><?= htmlspecialchars($row['email']) ?></p>
                                            </td>
                                            <td class="px-8 py-5 overflow-hidden max-w-[200px]">
                                                <p class="text-[10px] text-gray-400 font-bold uppercase leading-tight truncate"><?= htmlspecialchars($row['address_line'] . ', ' . $row['city']) ?></p>
                                            </td>
                                            <td class="px-8 py-5 text-center">
                                                <?php $c = $row['status'] == 'Active' ? 'emerald-500' : 'rose-500'; ?>
                                                <span class="px-3 py-1 rounded-md bg-<?= $c ?>/10 border border-<?= $c ?>/20 text-[9px] text-<?= $c ?> font-black uppercase italic">
                                                    <?= htmlspecialchars($row['status']) ?>
                                                </span>
                                            </td>
                                            <?php break;
                                        case 'client_subs': ?>
                                            <td class="px-8 py-5">
                                                <p class="text-sm font-bold text-white mb-1"><?= htmlspecialchars($row['gym_name']) ?></p>
                                                <p class="text-[9px] text-gray-500 font-bold italic"><?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?></p>
                                            </td>
                                            <td class="px-8 py-5">
                                                <p class="text-xs font-black text-primary uppercase tracking-widest italic"><?= htmlspecialchars($row['plan_name']) ?></p>
                                            </td>
                                            <td class="px-8 py-5">
                                                <p class="text-[10px] text-white font-bold leading-none mb-1"><?= date('M d', strtotime($row['start_date'])) ?> - <?= date('M d, Y', strtotime($row['end_date'])) ?></p>
                                                <p class="text-[9px] text-gray-500 font-black uppercase tracking-tighter italic">Billing Period</p>
                                            </td>
                                            <td class="px-8 py-5 text-center">
                                                <?php $sc = $row['subscription_status'] == 'Active' ? 'emerald-500' : 'amber-500'; ?>
                                                <span class="px-3 py-1 rounded-md bg-<?= $sc ?>/10 border border-<?= $sc ?>/20 text-[9px] text-<?= $sc ?> font-black uppercase italic"><?= htmlspecialchars($row['subscription_status']) ?></span>
                                            </td>
                                            <td class="px-8 py-5 text-right">
                                                <p class="text-xs font-black text-emerald-500 italic uppercase"><?= htmlspecialchars($row['payment_status']) ?></p>
                                            </td>
                                            <?php break;
                                        case 'system_alerts': ?>
                                            <td class="px-8 py-5">
                                                <p class="text-xs font-bold text-white mb-1"><?= htmlspecialchars($row['type']) ?></p>
                                                <p class="text-[9px] text-primary font-black uppercase italic">SRC: <?= htmlspecialchars($row['source']) ?></p>
                                            </td>
                                            <td class="px-8 py-5">
                                                <p class="text-[10px] text-gray-400 font-medium leading-relaxed italic line-clamp-2"><?= htmlspecialchars($row['message']) ?></p>
                                            </td>
                                            <td class="px-8 py-5 text-center">
                                                <?php $pc = $row['priority'] == 'High' ? 'rose-500' : ($row['priority'] == 'Medium' ? 'amber-500' : 'emerald-500'); ?>
                                                <span class="px-3 py-1 rounded-md bg-<?= $pc ?>/10 border border-<?= $pc ?>/20 text-[9px] text-<?= $pc ?> font-black uppercase italic"><?= htmlspecialchars($row['priority']) ?></span>
                                            </td>
                                            <td class="px-8 py-5 text-center text-[9px] font-black uppercase tracking-tighter text-gray-500 italic"><?= htmlspecialchars($row['status']) ?></td>
                                            <td class="px-8 py-5 text-right">
                                                <p class="text-[10px] font-bold text-white"><?= date('M d, Y', strtotime($row['created_at'])) ?></p>
                                            </td>
                                            <?php break;
                                        case 'audit_report': ?>
                                            <td class="px-8 py-5">
                                                <p class="text-sm font-bold text-white mb-1"><?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?></p>
                                                <p class="text-[9px] text-primary font-black uppercase italic">Action: <?= htmlspecialchars($row['action_type']) ?></p>
                                            </td>
                                            <td class="px-8 py-5">
                                                <p class="text-xs font-black text-white italic uppercase tracking-widest"><?= htmlspecialchars($row['table_name']) ?></p>
                                                <p class="text-[9px] text-gray-500 font-bold italic">Record #<?= $row['record_id'] ?></p>
                                            </td>
                                            <td class="px-8 py-5 overflow-hidden max-w-[300px]">
                                                <p class="text-[9px] text-gray-500 italic truncate"><span class="text-rose-500/50">OLD:</span> <?= htmlspecialchars($row['old_values']) ?></p>
                                                <p class="text-[9px] text-emerald-500/80 italic truncate"><span class="text-emerald-500">NEW:</span> <?= htmlspecialchars($row['new_values']) ?></p>
                                            </td>
                                            <td class="px-8 py-5 text-right">
                                                <p class="text-[10px] font-bold text-white"><?= date('M d, H:i:s', strtotime($row['created_at'])) ?></p>
                                            </td>
                                            <?php break;
                                        default: // tenant_activity ?>
                                            <td class="px-8 py-5">
                                                <p class="text-sm font-bold text-white leading-none mb-1"><?= htmlspecialchars($row['gym_name']) ?></p>
                                                <p class="text-[9px] text-primary font-black uppercase tracking-tighter italic"><?= htmlspecialchars($row['tenant_code']) ?></p>
                                            </td>
                                            <td class="px-8 py-5 text-center">
                                                <p class="text-sm font-bold text-white leading-none mb-1"><?= number_format($row['member_count']) ?></p>
                                            </td>
                                            <td class="px-8 py-5 text-center">
                                                <p class="text-sm font-bold text-white leading-none mb-1"><?= number_format($row['activity_count']) ?></p>
                                            </td>
                                            <td class="px-8 py-5 text-center">
                                                <?php $tc = $row['status'] == 'Active' ? 'emerald-500' : 'rose-500'; ?>
                                                <span class="px-3 py-1 rounded-md bg-<?= $tc ?>/10 border border-<?= $tc ?>/20 text-[9px] text-<?= $tc ?> font-black uppercase italic"><?= htmlspecialchars($row['status']) ?></span>
                                            </td>
                                            <td class="px-8 py-5 text-right">
                                                <p class="text-xs font-bold text-white uppercase leading-none mb-1"><?= date('M d, Y', strtotime($row['joined_date'])) ?></p>
                                            </td>
                                    <?php endswitch; ?>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Tab 2: Analytics Overview -->
        <div id="overviewTab" class="tab-content <?= ($active_tab == 'overviewTab') ? 'active' : '' ?>">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-10">
                <div class="lg:col-span-2 glass-card p-8">
                    <div class="flex justify-between items-center mb-8">
                        <div>
                            <h3 class="text-sm font-black italic uppercase tracking-widest text-white">User Registration Growth</h3>
                            <p class="text-[9px] text-gray-500 font-bold uppercase mt-1 tracking-wider">Growth Trends Over Time</p>
                        </div>
                    </div>
                    <div class="h-[300px] w-full">
                        <canvas id="registrationChart"></canvas>
                    </div>
                </div>

                <div class="glass-card p-8 flex flex-col justify-between">
                    <h3 class="text-sm font-black italic uppercase tracking-widest text-white mb-6">Usage Statistics</h3>
                    <div class="space-y-6">
                        <div class="p-5 rounded-2xl bg-white/[0.01] border border-white/5 relative overflow-hidden group hover:border-primary/20 transition-colors">
                            <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-4xl opacity-5 group-hover:scale-110 transition-transform">groups</span>
                            <p class="text-[10px] font-black uppercase text-gray-500 mb-1 tracking-widest">Total System Users</p>
                            <h2 class="text-2xl font-black text-white italic"><?= $total_users ?></h2>
                        </div>
                        <div class="p-5 rounded-2xl bg-white/[0.01] border border-white/5 relative overflow-hidden group hover:border-emerald-500/20 transition-colors">
                            <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-4xl opacity-5 group-hover:scale-110 transition-transform text-emerald-500">login</span>
                            <p class="text-[10px] font-black uppercase text-gray-500 mb-1 tracking-widest">Avg. Daily Logins</p>
                            <h2 class="text-2xl font-black text-emerald-500 italic"><?= $avg_daily_logins ?></h2>
                        </div>
                        <div class="p-5 rounded-2xl bg-white/[0.01] border border-white/5 relative overflow-hidden group hover:border-primary/20 transition-colors">
                            <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-4xl opacity-5 group-hover:scale-110 transition-transform text-primary">schedule</span>
                            <p class="text-[10px] font-black uppercase text-gray-500 mb-1 tracking-widest">Peak Usage Hour</p>
                            <h2 class="text-2xl font-black text-primary italic"><?= $peak_hour ?></h2>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
    const ctx = document.getElementById('registrationChart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: [<?php foreach($registration_data as $d) echo "'" . date('M d', strtotime($d['reg_date'])) . "',"; ?>],
            datasets: [{
                label: 'New Registrations',
                data: [<?php foreach($registration_data as $d) echo $d['count'] . ","; ?>],
                borderColor: '#8c2bee',
                backgroundColor: 'rgba(140, 43, 238, 0.05)',
                borderWidth: 3,
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
                y: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#666', font: { size: 10, weight: '800' } } },
                x: { grid: { display: false }, ticks: { color: '#666', font: { size: 10, weight: '800' } } }
            }
        }
    });

    function exportReportToPDF(preview = false) {
        const element = document.getElementById('detailedTab');
        const reportTitle = "<?= $curr_report['title'] ?>";
        const dateFrom = "<?= date('M d, Y', strtotime($date_from)) ?>";
        const dateTo = "<?= date('M d, Y', strtotime($date_to)) ?>";
        const tenantName = "Horizon System";
        const generatedAt = "<?= date('M d, Y h:i A') ?>";

        // Create a wrapper for formal PDF styling
        const wrapper = document.createElement('div');
        wrapper.style.padding = '40px';
        wrapper.style.color = '#000';
        wrapper.style.backgroundColor = '#fff';
        wrapper.style.fontFamily = "'Roboto Mono', monospace";

        // Formal Header (Matching Sample)
        const header = `
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;">
                <div style="text-align: left;">
                    <h1 style="font-family: 'Lexend', sans-serif; font-size: 28px; font-weight: 800; color: #000; margin: 0; text-transform: uppercase; letter-spacing: -0.5px;">${tenantName}</h1>
                    <div style="font-size: 9px; color: #333; margin-top: 8px; line-height: 1.6;">
                        <p style="margin: 0;">Baliwag, Bulacan, Philippines, 3006</p>
                        <p style="margin: 0;">Phone: 0976-241-1986 | Email: horizonfitnesscorp@gmail.com</p>
                    </div>
                </div>
                <div style="text-align: right;">
                    <h2 style="font-family: 'Lexend', sans-serif; font-size: 18px; font-weight: 700; color: #000; margin: 0; text-transform: uppercase;">${reportTitle}</h2>
                    <div style="font-size: 9px; color: #333; margin-top: 8px; line-height: 1.6;">
                        <p style="margin: 0;">Generated on: ${generatedAt}</p>
                    </div>
                </div>
            </div>
            <div style="border-bottom: 2px solid #000; margin-bottom: 30px;"></div>
        `;

        // Clone the content and clean it up
        const contentClone = element.cloneNode(true);
        
        // Remove interactive elements from clone
        const actionBar = contentClone.querySelector('.px-8.py-6.border-b');
        if (actionBar) actionBar.remove();

        // 1. Clean data: Remove secondary info (clutter) for business report
        const secondaryTexts = contentClone.querySelectorAll('p.text-\\[9px\\], p.text-\\[8px\\], span.text-\\[9px\\]');
        secondaryTexts.forEach(el => {
            if (!el.classList.contains('px-3')) { 
                el.remove();
            }
        });
        
        // Adjust table for PDF visibility (Excel Style)
        const table = contentClone.querySelector('table');
        let recordCount = 0;
        if (table) {
            table.style.width = '100%';
            table.style.borderCollapse = 'collapse';
            table.style.fontSize = '10px';
            table.style.color = '#000';
            table.style.border = '1px solid #000';
            table.style.marginTop = '10px';

            // style headers
            const ths = table.querySelectorAll('th');
            ths.forEach(th => {
                th.style.backgroundColor = '#f3f4f6'; 
                th.style.color = '#000';
                th.style.border = '1px solid #000';
                th.style.padding = '12px 10px';
                th.style.textTransform = 'uppercase';
                th.style.fontWeight = '800';
                th.style.textAlign = 'center';
            });

            // style cells
            const rows = table.querySelectorAll('tbody tr');
            recordCount = rows.length;
            const tds = table.querySelectorAll('td');
            tds.forEach(td => {
                td.style.border = '1px solid #000';
                td.style.padding = '12px 10px';
                td.style.color = '#000';
                td.style.verticalAlign = 'middle';
                const texts = td.querySelectorAll('p, span');
                texts.forEach(t => t.style.color = '#000');
            });

            if (table.querySelector('td[colspan]')) recordCount = 0;
        }

        // Add Summary Section
        const summary = `
            <div style="font-size: 10px; font-weight: 700; color: #000; margin-bottom: 15px; display: flex; justify-content: space-between;">
                <span>TRANSCRIPT OF RECORDS</span>
                <span>TOTAL RECORDS FOUND: ${recordCount}</span>
            </div>
        `;

        wrapper.innerHTML = header + summary;
        wrapper.appendChild(contentClone);

        // Add Footer
        const footer = document.createElement('div');
        footer.style.marginTop = '40px';
        footer.style.textAlign = 'center';
        footer.style.fontSize = '8px';
        footer.style.color = '#666';
        footer.style.lineHeight = '1.8';
        footer.innerHTML = `
            <p style="margin: 0;">This document is strictly confidential and generated for internal use only by Horizon System.</p>
            <p style="margin: 0;">&copy; ${new Date().getFullYear()} Horizon System. All Rights Reserved.</p>
        `;
        wrapper.appendChild(footer);

        const opt = {
            margin:       [0.5, 0.5],
            filename:     `${reportTitle.replace(/\s+/g, '_')}_${new Date().getTime()}.pdf`,
            image:        { type: 'jpeg', quality: 0.98 },
            html2canvas:  { 
                scale: 2, 
                backgroundColor: '#ffffff',
                useCORS: true,
                letterRendering: true
            },
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
</body>
</html>