<?php 
session_start();
require_once '../db.php';

// Security Check: Only Superadmin can access
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'superadmin') {
    header("Location: ../login.php");
    exit;
}

$page_title = "Recent Transactions";
$active_page = "transactions";

// 1. Get Filter Inputs
$tenant_filter = $_GET['tenant_id'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';
$type_filter = $_GET['type'] ?? 'all';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search_query = $_GET['search'] ?? '';

// (Logic moved below settings fetch to support mock data override)

// (Query logic moved to the mock/real switch below settings)

// Hex to RGB helper for dynamic transparency
function hexToRgb($hex) {
    if (!$hex) return "140, 43, 238"; // Default primary RGB
    $hex = str_replace("#", "", $hex);
    if (strlen($hex) == 3) {
        $r = hexdec(substr($hex, 0, 1) . substr($hex, 0, 1));
        $g = hexdec(substr($hex, 1, 1) . substr($hex, 1, 1));
        $b = hexdec(substr($hex, 2, 1) . substr($hex, 2, 1));
    } else {
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
    }
    return "$r, $g, $b";
}

// Fetch and Merge Settings
$stmtGlobal = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE user_id = 0");
$global_configs = $stmtGlobal->fetchAll(PDO::FETCH_KEY_PAIR);

$stmtUser = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = ?");
$stmtUser->execute([$_SESSION['user_id']]);
$user_configs = $stmtUser->fetchAll(PDO::FETCH_KEY_PAIR);

$configs = array_merge($global_configs, $user_configs);

// 0. Financial Overview Calculations (Global)
$stmtTotal = $pdo->query("SELECT SUM(amount) FROM payments WHERE LOWER(payment_status) IN (CAST('paid' AS CHAR CHARACTER SET latin1), CAST('success' AS CHAR CHARACTER SET latin1), CAST('completed' AS CHAR CHARACTER SET latin1))");
$total_revenue = $stmtTotal->fetchColumn() ?: 0.00;

$stmtPending = $pdo->query("SELECT SUM(amount), COUNT(*) FROM payments WHERE LOWER(payment_status) = CAST('pending' AS CHAR CHARACTER SET latin1)");
$pending_data = $stmtPending->fetch(PDO::FETCH_NUM);
$pending_revenue = $pending_data[0] ?: 0.00;
$pending_count = $pending_data[1] ?: 0;

$stmtToday = $pdo->query("SELECT SUM(amount) FROM payments WHERE DATE(created_at) = CURDATE() AND LOWER(payment_status) IN (CAST('paid' AS CHAR CHARACTER SET latin1), CAST('success' AS CHAR CHARACTER SET latin1), CAST('completed' AS CHAR CHARACTER SET latin1))");
$today_revenue = $stmtToday->fetchColumn() ?: 0.00;

// Count tenants with "Pending" or "Failed" transactions
$stmtUnpaid = $pdo->query("SELECT COUNT(DISTINCT gym_id) FROM payments WHERE LOWER(payment_status) IN (CAST('pending' AS CHAR CHARACTER SET latin1), CAST('failed' AS CHAR CHARACTER SET latin1))");
$unpaid_tenant_count = $stmtUnpaid->fetchColumn();

// Fetch Tenants for Filter Dropdown
$tenants_list = $pdo->query("SELECT gym_id, gym_name FROM gyms WHERE status = 'Active' ORDER BY gym_name ASC")->fetchAll();

// 2. Pagination Settings
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// 3. Dynamic Query Builder
$where_clauses = [];
$params = [];

if ($tenant_filter !== 'all') {
    $where_clauses[] = "p.gym_id = :tid";
    $params[':tid'] = $tenant_filter;
}
if ($status_filter !== 'all') {
    $where_clauses[] = "p.payment_status = :status";
    $params[':status'] = $status_filter;
}
if ($type_filter !== 'all') {
    $where_clauses[] = "p.payment_type = :type";
    $params[':type'] = $type_filter;
}
if (!empty($date_from)) {
    $where_clauses[] = "p.created_at >= :date_from";
    $params[':date_from'] = $date_from . " 00:00:00";
}
if (!empty($date_to)) {
    $where_clauses[] = "p.created_at <= :date_to";
    $params[':date_to'] = $date_to . " 23:59:59";
}
if (!empty($search_query)) {
    $where_clauses[] = "(p.reference_number LIKE :search OR u_member.first_name LIKE :search OR u_member.last_name LIKE :search OR u_owner.first_name LIKE :search)";
    $params[':search'] = "%$search_query%";
}

$where_sql = !empty($where_clauses) ? " WHERE " . implode(" AND ", $where_clauses) : "";

$count_query = "SELECT COUNT(*) 
                FROM payments p
                LEFT JOIN members m ON p.member_id = m.member_id
                LEFT JOIN users u_member ON m.user_id = u_member.user_id
                LEFT JOIN client_subscriptions cs ON p.client_subscription_id = cs.client_subscription_id
                LEFT JOIN users u_owner ON cs.owner_user_id = u_owner.user_id" . $where_sql;

$query = "SELECT p.*, g.gym_name,
                 COALESCE(u_member.first_name, u_owner.first_name, 'System') as f_name,
                 COALESCE(u_member.last_name, u_owner.last_name, '') as l_name
          FROM payments p
          LEFT JOIN gyms g ON p.gym_id = g.gym_id
          LEFT JOIN members m ON p.member_id = m.member_id
          LEFT JOIN users u_member ON m.user_id = u_member.user_id
          LEFT JOIN client_subscriptions cs ON p.client_subscription_id = cs.client_subscription_id
          LEFT JOIN users u_owner ON cs.owner_user_id = u_owner.user_id" . $where_sql;

// Get total records for pagination
$count_stmt = $pdo->prepare($count_query);
$count_stmt->execute($params);
$total_records = $count_stmt->fetchColumn();
$total_pages = max(1, ceil($total_records / $limit));

// Finalize query with ordering and pagination
$query .= " ORDER BY p.created_at DESC LIMIT :limit OFFSET :offset";
$stmtTrx = $pdo->prepare($query);

// Bind all parameters
foreach ($params as $key => $val) {
    if ($key === ':limit' || $key === ':offset') continue; 
    $stmtTrx->bindValue($key, $val);
}

// Bind pagination parameters as Integers
$stmtTrx->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
$stmtTrx->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
$stmtTrx->execute();
$transactions = $stmtTrx->fetchAll(PDO::FETCH_ASSOC);

// Helper for Pagination links persistence
$query_string = $_GET;
unset($query_string['page']);
$base_pagination_url = "recent_transaction.php?" . http_build_query($query_string);
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
            theme: { extend: { colors: { 
                "primary": "var(--primary)", 
                "background": "var(--background)", 
                "secondary": "var(--secondary)",
                "surface-dark": "#14121a", 
                "border-subtle": "rgba(255,255,255,0.05)"
            }}}
        }
    </script>
    <style>
        :root {
            --primary: <?= $configs['theme_color'] ?? '#8c2bee' ?>;
            --primary-rgb: <?= hexToRgb($configs['theme_color'] ?? '#8c2bee') ?>;
            --background: <?= $configs['bg_color'] ?? '#0a090d' ?>;
            --highlight: <?= $configs['secondary_color'] ?? '#a1a1aa' ?>;
            --text-main: <?= $configs['text_color'] ?? '#d1d5db' ?>;
            --card-blur: 20px;
            --card-bg: <?= ($configs['auto_card_theme'] ?? '1') === '1' ? 'rgba(' . hexToRgb($configs['theme_color'] ?? '#8c2bee') . ', 0.05)' : ($configs['card_color'] ?? '#141216') ?>;
        }

        body { 
            font-family: 'Lexend', sans-serif; 
            background-color: var(--background); 
            color: var(--text-main); 
        }

        .glass-card { 
            background: var(--card-bg); 
            border: 1px solid rgba(255,255,255,0.05); 
            border-radius: 24px; 
            backdrop-filter: blur(var(--card-blur));
            transition: all 0.3s ease;
        }

        /* Sidebar Nav Styles */
        .sidebar-nav {
            width: 110px;
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            position: fixed;
            left: 0;
            top: 0;
            bottom: 0;
            z-index: 50;
            background: var(--background);
            border-right: 1px solid rgba(255, 255, 255, 0.05);
        }

        .sidebar-nav:hover {
            width: 300px;
        }

        .main-content {
            margin-left: 110px;
            flex: 1;
            min-width: 0;
            transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .sidebar-nav:hover ~ .main-content {
            margin-left: 300px;
        }

        .sidebar-scroll-container {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
        }

        /* Custom Scrollbar for the sidebar */
        .sidebar-scroll-container::-webkit-scrollbar {
            width: 4px;
        }

        .sidebar-scroll-container::-webkit-scrollbar-track {
            background: transparent;
        }

        .sidebar-scroll-container::-webkit-scrollbar-thumb {
            background: rgba(var(--primary-rgb), 0.1);
            border-radius: 10px;
        }

        .sidebar-nav:hover .sidebar-scroll-container::-webkit-scrollbar-thumb {
            background: rgba(var(--primary-rgb), 0.4);
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
            padding: 0 38px;
            pointer-events: none;
        }

        .sidebar-nav:hover .nav-section-header {
            max-height: 20px;
            opacity: 1;
            margin-bottom: 8px !important;
            pointer-events: auto;
        }

        .sidebar-nav:hover .nav-section-header.mt-4 {
            margin-top: 12px !important;
        }

        .sidebar-nav:hover .nav-section-header.mt-6 {
            margin-top: 16px !important;
        }

        .sidebar-content {
            display: flex;
            flex-direction: column;
            gap: 2px;
            transition: all 0.3s ease-in-out;
        }

        .sidebar-nav:hover .sidebar-content {
            gap: 4px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 10px 38px;
            transition: all 0.2s;
            white-space: nowrap;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.05em;
            color: var(--text-main);
            text-decoration: none;
        }

        .nav-link span.material-symbols-outlined {
            color: var(--highlight);
            opacity: 0.7;
            transition: all 0.3s ease;
        }

        .nav-link:hover {
            opacity: 0.8;
            transform: scale(1.02);
        }

        .active-nav {
            color: var(--primary) !important;
            position: relative;
        }

        .active-nav span.material-symbols-outlined {
            color: var(--primary) !important;
            opacity: 1 !important;
        }

        .active-nav::after {
            content: '';
            position: absolute;
            right: 0px;
            top: 50%;
            transform: translateY(-50%);
            width: 4px;
            height: 24px;
            background: var(--primary);
            border-radius: 4px 0 0 4px;
            opacity: 1;
            transition: opacity 0.3s ease;
        }

        @media (max-width: 1023px) {
            .active-nav::after {
                display: none;
            }
        }

        .no-scrollbar::-webkit-scrollbar {
            display: none;
        }

        .no-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        /* --- Elite Invisible Scroll System --- */
        *::-webkit-scrollbar { display: none !important; }
        * { -ms-overflow-style: none !important; scrollbar-width: none !important; }

        /* --- Dropdown Styling Fix --- */
        select {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%23<?= str_replace('#', '', $configs['theme_color'] ?? '8c2bee') ?>'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 1em;
        }

        select option {
            background-color: #1a1820 !important;
            color: var(--text-main);
            padding: 12px;
            font-weight: 600;
        }

        select option:hover, 
        select option:focus, 
        select option:active {
            background-color: var(--primary) !important;
            color: white !important;
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
    </script>
</head>
<body class="antialiased flex flex-row min-h-screen">

    <nav class="sidebar-nav z-50 flex flex-col no-scrollbar">
        <div class="px-7 py-5 mb-2 shrink-0">
            <div class="flex items-center gap-4">
                <div class="size-10 rounded-xl flex items-center justify-center shadow-lg shrink-0 overflow-hidden">
                    <?php if (!empty($configs['system_logo'])): ?>
                        <img src="<?= htmlspecialchars($configs['system_logo']) ?>" id="sidebarLogoPreview"
                            class="size-full object-contain rounded-xl">
                    <?php else: ?>
                        <img src="../assests/horizon logo.png" id="sidebarLogoPreview"
                            class="size-full object-contain rounded-xl transition-transform duration-500 hover:scale-110"
                            alt="Horizon Logo">
                    <?php endif; ?>
                </div>
                <h1 class="nav-text text-lg font-black italic uppercase tracking-tighter text-white">
                    <?= htmlspecialchars($configs['system_name'] ?? 'Horizon System') ?>
                </h1>
            </div>
        </div>

        <div class="sidebar-scroll-container no-scrollbar space-y-1 pb-4">
            <div class="nav-section-header mb-2">
                <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Overview</span>
            </div>
            <a href="superadmin_dashboard.php"
                class="nav-link <?= ($active_page == 'dashboard') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">grid_view</span>
                <span class="nav-text">Dashboard</span>
            </a>

            <div class="nav-section-header mb-2 mt-4">
                <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Management</span>
            </div>
            <a href="tenant_management.php"
                class="nav-link <?= ($active_page == 'tenants') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">business</span>
                <span class="nav-text">Tenant Management</span>
            </a>

            <a href="subscription_logs.php"
                class="nav-link <?= ($active_page == 'subscriptions') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">history_edu</span>
                <span class="nav-text">Subscription Logs</span>
            </a>

            <a href="real_time_occupancy.php"
                class="nav-link <?= ($active_page == 'occupancy') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">group</span>
                <span class="nav-text">Real-Time Occupancy</span>
            </a>

            <a href="recent_transaction.php"
                class="nav-link <?= ($active_page == 'transactions') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">receipt_long</span>
                <span class="nav-text">Recent Transactions</span>
            </a>

            <div class="nav-section-header mb-2 mt-4">
                <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">System</span>
            </div>
            <a href="system_alerts.php"
                class="nav-link <?= ($active_page == 'alerts') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">notifications_active</span>
                <span class="nav-text">System Alerts</span>
            </a>

            <a href="system_reports.php"
                class="nav-link <?= ($active_page == 'reports') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">analytics</span>
                <span class="nav-text">Reports</span>
            </a>

            <a href="sales_report.php"
                class="nav-link <?= ($active_page == 'sales_report') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">monitoring</span>
                <span class="nav-text">Sales Reports</span>
            </a>

            <a href="audit_logs.php"
                class="nav-link <?= ($active_page == 'audit_logs') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">assignment</span>
                <span class="nav-text">Audit Logs</span>
            </a>

            <a href="backup.php"
                class="nav-link <?= ($active_page == 'backup') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">backup</span>
                <span class="nav-text">Backup</span>
            </a>
        </div>

        <div class="mt-auto pt-4 border-t border-white/10 flex flex-col gap-1 shrink-0 pb-6">
            <div class="nav-section-header mb-2">
                <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Account</span>
            </div>
            <a href="settings.php"
                class="nav-link <?= ($active_page == 'settings') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">settings</span>
                <span class="nav-text">Settings</span>
            </a>
            <a href="profile.php"
                class="nav-link <?= ($active_page == 'profile') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">person</span>
                <span class="nav-text">Profile</span>
            </a>
            <a href="../logout.php"
                class="nav-link !text-gray-400 hover:!text-rose-500 transition-colors group">
                <span class="material-symbols-outlined text-xl shrink-0 group-hover:!text-rose-500">logout</span>
                <span class="nav-text group-hover:!text-rose-500">Sign Out</span>
            </a>
        </div>
    </nav>

    <div class="main-content flex-1 flex flex-col min-w-0 overflow-y-auto no-scrollbar">
    <main class="flex-1 p-6 md:p-10 max-w-[1400px] w-full mx-auto">

        <header class="mb-10 flex flex-row justify-between items-end gap-6">
            <div>
                <h2 class="text-3xl font-black italic uppercase tracking-tighter text-[--text-main] leading-none">Global <span class="text-primary">Transactions</span></h2>
                <p class="text-[--text-main] opacity-60 text-xs font-bold uppercase tracking-widest mt-2">Financial ecosystem monitoring</p>
            </div>
            <div class="text-right shrink-0">
                <p id="headerClock" class="text-[--text-main] font-black italic text-2xl tracking-tighter leading-none mb-2 transition-colors cursor-default">00:00:00 AM</p>
                <p class="text-primary text-[10px] font-black uppercase tracking-[0.2em] leading-none opacity-80"><?= date('l, M d, Y') ?></p>
            </div>
        </header>

        <!-- Financial Summary Dashboard -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-10">
            <div class="glass-card p-8 border-emerald-500/20 bg-emerald-500/[0.03] relative overflow-hidden group">
                <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-5xl opacity-10 group-hover:scale-110 transition-transform text-emerald-500">payments</span>
                <p class="text-[10px] font-black uppercase text-[--text-main] opacity-40 mb-2 tracking-widest">Total Revenue</p>
                <h3 class="text-2xl font-black italic uppercase text-emerald-400">₱<?= number_format($total_revenue, 2) ?></h3>
                <p class="text-emerald-500/60 text-[9px] font-black uppercase mt-2 italic shadow-sm">Verified Collections</p>
            </div>

            <a href="?status=pending" class="glass-card p-8 border-amber-500/20 bg-amber-500/[0.03] relative overflow-hidden group block hover:scale-[1.02] transition-all">
                <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-5xl opacity-10 group-hover:scale-110 transition-transform text-amber-500">pending_actions</span>
                <p class="text-[10px] font-black uppercase text-[--text-main] opacity-40 mb-2 tracking-widest">Pending Collections</p>
                <h3 class="text-2xl font-black italic uppercase text-amber-400">₱<?= number_format($pending_revenue, 2) ?></h3>
                <p class="text-amber-500/60 text-[9px] font-black uppercase mt-2 italic"><?= $pending_count ?> transactions requiring action</p>
            </a>

            <a href="?status=failed" class="glass-card p-8 border-rose-500/20 bg-rose-500/[0.03] relative overflow-hidden group block hover:scale-[1.02] transition-all">
                <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-5xl opacity-10 group-hover:scale-110 transition-transform text-rose-500">warning</span>
                <p class="text-[10px] font-black uppercase text-[--text-main] opacity-40 mb-2 tracking-widest">At-Risk Accounts</p>
                <h3 class="text-2xl font-black italic uppercase text-rose-400"><?= $unpaid_tenant_count ?> Gyms</h3>
                <p class="text-rose-500/60 text-[9px] font-black uppercase mt-2 italic">Outstanding / Failed Logs</p>
            </a>

            <div class="glass-card p-8 border-primary/20 bg-primary/[0.03] relative overflow-hidden group">
                <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-5xl opacity-10 group-hover:scale-110 transition-transform text-primary">trending_up</span>
                <p class="text-[10px] font-black uppercase text-[--text-main] opacity-40 mb-2 tracking-widest">Today's Growth</p>
                <h3 class="text-2xl font-black italic uppercase text-primary">₱<?= number_format($today_revenue, 2) ?></h3>
                <p class="text-primary/60 text-[9px] font-black uppercase mt-2 italic"><?= date('F d') ?> Snapshot</p>
            </div>
        </div>

        <div class="glass-card mb-8 p-6">
            <form method="GET" class="flex flex-wrap items-end gap-6" id="trxFilterForm">
                <div class="flex-1 min-w-[280px]">
                    <p class="text-[10px] font-black uppercase tracking-widest text-[--text-main] opacity-40 mb-3 block px-1">Quick Search</p>
                    <div class="relative group">
                        <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-sm text-primary transition-transform group-hover:scale-110">search</span>
                        <input type="text" name="search" id="trxSearch" value="<?= htmlspecialchars($search_query) ?>" placeholder="Ref ID, Member, or Client Name..." 
                               class="w-full bg-white/5 border border-white/10 rounded-xl py-3 pl-12 pr-4 text-xs font-bold transition-all focus:border-primary focus:bg-white/[0.08] outline-none">
                    </div>
                </div>

                <div class="w-[200px]">
                    <p class="text-[10px] font-black uppercase tracking-widest text-[--text-main] opacity-40 mb-3 block px-1">Gym Context</p>
                    <select name="tenant_id" onchange="this.form.submit()" class="w-full bg-white/5 border border-white/10 rounded-xl py-3 px-4 text-xs font-bold transition-all focus:border-primary focus:bg-white/[0.08] outline-none appearance-none">
                        <option value="all" class="bg-[--background]">All Tenants</option>
                        <?php foreach($tenants_list as $t): ?>
                            <option value="<?= $t['gym_id'] ?>" <?= ($tenant_filter == $t['gym_id']) ? 'selected' : '' ?> class="bg-[--background]"><?= htmlspecialchars($t['gym_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="w-[180px]">
                    <p class="text-[10px] font-black uppercase tracking-widest text-[--text-main] opacity-40 mb-3 block px-1">Status</p>
                    <select name="status" class="w-full bg-white/5 border border-white/10 rounded-xl py-3 px-4 text-xs font-bold transition-all focus:border-primary focus:bg-white/[0.08] outline-none appearance-none">
                        <option value="all" class="bg-[--background]">All Statuses</option>
                        <?php 
                            $statuses = ['Paid', 'Pending', 'Failed', 'Success', 'Refunded'];
                            foreach($statuses as $s): 
                        ?>
                            <option value="<?= strtolower($s) ?>" <?= ($status_filter == strtolower($s)) ? 'selected' : '' ?> class="bg-[--background]"><?= $s ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="flex gap-2">
                    <button type="submit" class="w-11 h-11 flex items-center justify-center rounded-xl bg-primary text-black hover:scale-[1.05] active:scale-[0.95] transition-all shadow-lg shadow-primary/20" title="Update View">
                        <span class="material-symbols-outlined text-xl">filter_alt</span>
                    </button>
                    <a href="recent_transaction.php" class="w-11 h-11 flex items-center justify-center rounded-xl bg-white/5 border border-white/10 text-[--text-main] opacity-50 hover:opacity-100 hover:bg-white/10 transition-all" title="Reset Filters">
                        <span class="material-symbols-outlined text-xl">restart_alt</span>
                    </a>
                </div>
            </form>

            <div class="mt-6 pt-6 border-t border-white/5 flex items-center gap-6">
                <div class="flex items-center gap-3">
                    <div class="flex items-center gap-4 bg-white/5 border border-white/10 rounded-xl px-4 py-2">
                        <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" onchange="this.form.submit()"
                               class="bg-transparent border-none text-xs font-bold text-primary focus:outline-none">
                        <span class="text-[10px] font-black opacity-20 uppercase tracking-widest">TO</span>
                        <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" onchange="this.form.submit()"
                               class="bg-transparent border-none text-xs font-bold text-primary focus:outline-none">
                    </div>
                </div>
                <div class="ml-auto flex items-center gap-4">
                    <span class="text-[11px] font-black uppercase text-[--text-main] opacity-40">Category Filter:</span>
                    <div class="flex gap-2">
                        <?php 
                            $types = ['Membership', 'Walk-in', 'Subscription'];
                            foreach($types as $t): 
                                $is_active = ($type_filter == strtolower($t));
                        ?>
                            <a href="?type=<?= strtolower($t) ?>" class="px-4 py-2 rounded-xl text-[11px] font-black uppercase border transition-all <?= $is_active ? 'bg-primary/20 border-primary text-primary' : 'bg-white/5 border-white/10 text-[--text-main] opacity-50 hover:opacity-100 hover:bg-white/10' ?>">
                                <?= $t ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="glass-card overflow-hidden shadow-2xl">
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-[--background]/50 text-[--text-main] opacity-40 text-[10px] font-black uppercase tracking-[0.25em] border-b border-white/5">
                            <th class="px-8 py-5">Transaction ID</th>
                            <th class="px-8 py-5">Member / Branch</th>
                            <th class="px-8 py-5">Category</th>
                            <th class="px-8 py-5 text-center">Amount</th>
                            <th class="px-8 py-5">Timestamp</th>
                            <th class="px-8 py-5 text-right">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <?php if (empty($transactions)): ?>
                            <tr><td colspan="6" class="px-8 py-12 text-center text-xs font-bold text-[--text-main] opacity-60 italic uppercase tracking-widest">No transaction records found.</td></tr>
                        <?php else: ?>
                            <?php foreach($transactions as $trx): ?>
                            <tr class="hover:bg-white/[0.04] transition-all duration-300 group">
                                <td class="px-8 py-5">
                                    <span class="text-[10px] font-black italic text-primary uppercase group-hover:scale-105 transition-transform block w-fit">TRX-<?= htmlspecialchars($trx['reference_number'] ?: $trx['payment_id']) ?></span>
                                </td>
                                <td class="px-8 py-5">
                                    <div>
                                        <p class="text-sm font-bold text-white leading-tight"><?= htmlspecialchars($trx['f_name'] . ' ' . $trx['l_name']) ?></p>
                                        <p class="text-[10.5px] text-[--text-main] opacity-70 font-black uppercase tracking-wider italic mt-1"><?= $trx['gym_name'] ? htmlspecialchars($trx['gym_name']) : 'System Managed' ?></p>
                                    </div>
                                </td>
                                <td class="px-8 py-5">
                                    <span class="text-[11px] font-black uppercase tracking-tight text-[--text-main] opacity-50 group-hover:opacity-100 transition-opacity"><?= htmlspecialchars($trx['payment_type']) ?></span>
                                </td>
                                <td class="px-8 py-5 text-center">
                                    <p class="text-sm font-black italic text-white group-hover:text-primary transition-colors">₱<?= number_format($trx['amount'], 2) ?></p>
                                </td>
                                <td class="px-8 py-5 text-xs font-bold text-[--text-main] opacity-80">
                                    <?= date('M d, Y', strtotime($trx['created_at'])) ?>
                                    <p class="text-[10px] opacity-60 uppercase tracking-tight"><?= date('h:i A', strtotime($trx['created_at'])) ?></p>
                                </td>
                                <td class="px-8 py-5 text-right">
                                    <?php 
                                        $status = strtolower($trx['payment_status']);
                                        $is_success = ($status == 'paid' || $status == 'success');
                                    ?>
                                    <span class="px-3 py-1 rounded-full <?= $is_success ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-500' : 'bg-red-500/10 border-red-500/20 text-red-500' ?> border text-[9px] font-black uppercase italic">
                                        <?= htmlspecialchars($trx['payment_status']) ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
            <div class="px-8 py-6 border-t border-white/5 bg-white/[0.02] backdrop-blur-xl flex items-center justify-between">
                <p class="text-[10px] font-black text-[--text-main] opacity-40 uppercase tracking-[0.2em]">
                    Showing <span class="text-primary opacity-100"><?= $offset + 1 ?></span> to <span class="text-primary opacity-100"><?= min($offset + $limit, $total_records) ?></span> <span class="mx-1 text-[8px] opacity-20">OF</span> <span class="text-white opacity-100"><?= $total_records ?></span> entries
                </p>
                <div class="flex items-center gap-2">
                    <!-- Prev Button -->
                    <?php if ($page > 1): ?>
                        <a href="<?= $base_pagination_url ?>&page=<?= $page - 1 ?>" 
                           class="h-9 px-4 rounded-xl flex items-center gap-2 bg-white/5 border border-white/10 text-[10px] font-black uppercase tracking-widest text-[--text-main] opacity-60 hover:opacity-100 hover:bg-white/10 transition-all">
                            <span class="material-symbols-outlined text-sm">chevron_left</span> Prev
                        </a>
                    <?php endif; ?>

                    <div class="flex items-center gap-1 bg-black/20 p-1 rounded-xl border border-white/5">
                        <?php 
                        $range = 2; // Show pages around current
                        for ($i = 1; $i <= $total_pages; $i++): 
                            if ($i == 1 || $i == $total_pages || ($i >= $page - $range && $i <= $page + $range)):
                        ?>
                            <a href="<?= $base_pagination_url ?>&page=<?= $i ?>" 
                               class="size-8 rounded-lg flex items-center justify-center text-[10px] font-black transition-all <?= ($i == $page) ? 'bg-primary text-black shadow-lg shadow-primary/20' : 'text-[--text-main] opacity-40 hover:opacity-100 hover:bg-white/5' ?>">
                                <?= $i ?>
                            </a>
                        <?php elseif ($i == $page - $range - 1 || $i == $page + $range + 1): ?>
                            <span class="px-2 text-[10px] opacity-20">...</span>
                        <?php endif; ?>
                        <?php endfor; ?>
                    </div>

                    <!-- Next Button -->
                    <?php if ($page < $total_pages): ?>
                        <a href="<?= $base_pagination_url ?>&page=<?= $page + 1 ?>" 
                           class="h-9 px-4 rounded-xl flex items-center gap-2 bg-white/5 border border-white/10 text-[10px] font-black uppercase tracking-widest text-[--text-main] opacity-60 hover:opacity-100 hover:bg-white/10 transition-all">
                            Next <span class="material-symbols-outlined text-sm">chevron_right</span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>
<?php include '../includes/image_viewer.php'; ?>
</body>
</html>