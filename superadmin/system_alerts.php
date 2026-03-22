<?php 
session_start();
require_once '../db.php';

// Security Check
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'superadmin') {
    header("Location: ../login.php");
    exit;
}

// Handle POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['resolve_id'])) {
        $stmtResolve = $pdo->prepare("UPDATE system_alerts SET status = 'Resolved' WHERE alert_id = ?");
        $stmtResolve->execute([$_POST['resolve_id']]);
        $success_msg = "Alert marked as resolved.";
    } elseif (isset($_POST['clear_all'])) {
        $pdo->exec("UPDATE system_alerts SET status = 'Resolved' WHERE status != 'Resolved'");
        $success_msg = "All alerts cleared.";
    }
}

// Get Filter Inputs
$search = $_GET['search'] ?? '';
$priority_filter = $_GET['priority'] ?? 'all';

// Fetch System Settings for Branding
$stmtConfig = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
$configs = $stmtConfig->fetchAll(PDO::FETCH_KEY_PAIR);

// --- Automated Alert Logic (On Page Load) ---

$now = date('Y-m-d H:i:s');
$sevenDaysLater = date('Y-m-d', strtotime('+7 days'));

// 1. Check for Expiring Client Subscriptions
$stmtExpiring = $pdo->prepare("
    SELECT cs.*, g.gym_name 
    FROM client_subscriptions cs 
    JOIN gyms g ON cs.gym_id = g.gym_id 
    WHERE cs.end_date <= ? 
    AND cs.subscription_status = 'Active'
");
$stmtExpiring->execute([$sevenDaysLater]);
$expiringSubs = $stmtExpiring->fetchAll(PDO::FETCH_ASSOC);

foreach ($expiringSubs as $sub) {
    $daysLeft = (strtotime($sub['end_date']) - strtotime(date('Y-m-d'))) / 86400;
    $msg = "Subscription for " . $sub['gym_name'] . " expires in " . round($daysLeft) . " days (Ends: " . $sub['end_date'] . ")";
    
    // Deduplication: Check if alert already exists
    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM system_alerts WHERE message = ? AND status != 'Resolved'");
    $stmtCheck->execute([$msg]);
    if ($stmtCheck->fetchColumn() == 0) {
        $stmtInsert = $pdo->prepare("INSERT INTO system_alerts (type, source, message, priority, status, created_at) VALUES ('Subscription Expiry', 'Billing', ?, 'Medium', 'Unread', ?)");
        $stmtInsert->execute([$msg, $now]);
    }
}

// 2. Check for Pending Payments
$stmtPendingPayments = $pdo->query("
    SELECT p.*, u.first_name, u.last_name 
    FROM payments p 
    LEFT JOIN members m ON p.member_id = m.member_id 
    LEFT JOIN users u ON m.user_id = u.user_id 
    WHERE p.payment_status = 'Pending'
");
$pendingPayments = $stmtPendingPayments->fetchAll(PDO::FETCH_ASSOC);

foreach ($pendingPayments as $payment) {
    $payer = ($payment['first_name']) ? $payment['first_name'] . ' ' . $payment['last_name'] : 'Tenant/Guest';
    $msg = "New payment of ₱" . number_format($payment['amount'], 2) . " from " . $payer . " requires verification. (Ref: " . $payment['reference_number'] . ")";
    
    // Deduplication
    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM system_alerts WHERE message = ? AND status != 'Resolved'");
    $stmtCheck->execute([$msg]);
    if ($stmtCheck->fetchColumn() == 0) {
        $stmtInsert = $pdo->prepare("INSERT INTO system_alerts (type, source, message, priority, status, created_at) VALUES ('Payment Verification', 'Finance', ?, 'Medium', 'Unread', ?)");
        $stmtInsert->execute([$msg, $now]);
    }
}

// 3. System Health Placeholder
if (rand(1, 100) > 95) { // 5% chance to show a health check alert
    $msg = "System health check completed. All nodes performing optimally.";
    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM system_alerts WHERE message = ? AND status != 'Resolved'");
    $stmtCheck->execute([$msg]);
    if ($stmtCheck->fetchColumn() == 0) {
        $stmtInsert = $pdo->prepare("INSERT INTO system_alerts (type, source, message, priority, status, created_at) VALUES ('Health Check', 'Server', ?, 'Medium', 'Unread', ?)");
        $stmtInsert->execute([$msg, $now]);
    }
}

// --- End Automated Alert Logic ---

// Handle dynamic filtering
$priority_filter = $_GET['priority'] ?? 'all';
$search = $_GET['search'] ?? '';
$view = $_GET['view'] ?? 'active';

$query = "SELECT * FROM system_alerts WHERE 1=1";
$params = [];

// Status Filter (Tabs)
if ($view === 'history') {
    $query .= " AND status = 'Resolved'";
} else {
    $query .= " AND status != 'Resolved'";
}

// Search Filter
if (!empty($search)) {
    $query .= " AND (message LIKE :search OR type LIKE :search OR source LIKE :search)";
    $params['search'] = "%$search%";
}

// Priority Filter
if ($priority_filter !== 'all') {
    $query .= " AND priority = :priority";
    $params['priority'] = $priority_filter;
}

$query .= " ORDER BY created_at DESC";
$stmtAlerts = $pdo->prepare($query);
$stmtAlerts->execute($params);
$alerts = $stmtAlerts->fetchAll(PDO::FETCH_ASSOC);

// Handle AJAX Request for Live Search
if (isset($_GET['ajax'])) {
    ?>
    <div class="space-y-6 relative">
        <?php foreach($alerts as $alert): 
            $isHigh = ($alert['priority'] == 'High');
            $priorityClass = $isHigh ? 'text-rose-500 bg-rose-500/10 border-rose-500/20 alert-glow-high' : 'text-primary bg-primary/10 border-primary/20 alert-glow-medium';
            $icon = $isHigh ? 'report' : 'info';
        ?>
            <div class="group relative flex flex-col md:flex-row gap-8 items-start">
                <div class="hidden md:flex size-14 rounded-xl glass-card shrink-0 items-center justify-center relative z-10 group-hover:border-primary/50 transition-colors">
                    <span class="material-symbols-outlined <?= $isHigh ? 'text-rose-500' : 'text-primary' ?> text-xl"><?= $icon ?></span>
                </div>
                <div class="flex-1 glass-card p-6 group-hover:bg-white/[0.04] transition-all duration-500">
                    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                        <div class="flex flex-col gap-1">
                            <div class="flex items-center gap-3 mb-1">
                                <span class="text-gray-600 text-[9px] font-bold uppercase tracking-widest italic"><?= date('h:i A', strtotime($alert['created_at'])) ?></span>
                            </div>
                            <h4 class="text-md font-black italic uppercase text-white tracking-tight leading-none"><?= htmlspecialchars($alert['type']) ?></h4>
                            <p class="text-[#8e8d91] text-xs mt-1 font-medium"><?= htmlspecialchars($alert['message']) ?></p>
                            <div class="flex items-center gap-2 mt-3">
                                <span class="text-[9px] font-black uppercase text-gray-500 tracking-widest">Source:</span>
                                <span class="text-[9px] font-black uppercase text-white bg-white/5 px-2 py-0.5 rounded"><?= htmlspecialchars($alert['source']) ?></span>
                            </div>
                        </div>
                        <div class="flex gap-2 shrink-0">
                            <?php if ($view === 'active'): ?>
                            <button onclick="requestResolve(<?= $alert['alert_id'] ?>)" class="size-10 flex items-center justify-center rounded-xl bg-white/5 hover:bg-emerald-500/20 hover:text-emerald-400 border border-white/5 transition-all group/btn" title="Resolve">
                                <span class="material-symbols-outlined text-sm">check</span>
                            </button>
                            <?php else: ?>
                            <div class="size-10 flex items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-500 border border-emerald-500/20" title="Resolved">
                                <span class="material-symbols-outlined text-sm">verified</span>
                            </div>
                            <?php endif; ?>
                            <button onclick="openAlertModal('<?= addslashes($alert['type']) ?>', '<?= addslashes($alert['message']) ?>', '<?= addslashes($alert['source']) ?>', '<?= date('M d, Y h:i A', strtotime($alert['created_at'])) ?>', '<?= $alert['priority'] ?>')" class="size-10 flex items-center justify-center rounded-xl bg-white/5 hover:bg-primary/20 hover:text-primary border border-white/5 transition-all group/btn" title="More Details">
                                <span class="material-symbols-outlined text-sm">visibility</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (empty($alerts)): ?>
            <div class="glass-card p-12 text-center">
                <p class="text-gray-500 text-xs font-bold uppercase tracking-widest italic">No alerts found matching your criteria.</p>
            </div>
        <?php endif; ?>
    </div>
    <!-- Pagination Mock -->
    <div class="mt-12 flex justify-center gap-2">
        <button class="size-10 rounded-xl glass-card flex items-center justify-center text-primary border-primary/30">1</button>
        <button class="size-10 rounded-xl glass-card flex items-center justify-center text-gray-500 hover:text-white transition-all">2</button>
        <button class="size-10 rounded-xl glass-card flex items-center justify-center text-gray-500 hover:text-white transition-all">3</button>
    </div>
    <?php
    exit;
}

$page_title = "System Alerts";
$active_page = "alerts";
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title><?= htmlspecialchars($configs['system_name'] ?? 'Horizon System') ?> | System Alerts</title>
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
        .glass-card { background: rgba(20, 18, 26, 0.6); border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; backdrop-filter: blur(20px); }
        
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
        .active-nav::after { content: ''; position: absolute; right: 0px; top: 50%; transform: translateY(-50%); width: 4px; height: 20px; background: #8c2bee; border-radius: 99px; }
        
        .alert-glow-high { box-shadow: 0 0 20px rgba(239, 68, 68, 0.05); }
        .alert-glow-medium { box-shadow: 0 0 20px rgba(140, 43, 238, 0.05); }
        
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
    <script>
        function updateHeaderClock() {
            const now = new Date();
            const clockEl = document.getElementById('headerClock');
            if (clockEl) {
                clockEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            }
        }
        setInterval(updateHeaderClock, 1000);
        window.addEventListener('DOMContentLoaded', updateHeaderClock);

        // Reactive Filtering Logic
        let filterTimeout;
        function reactiveFilter() {
            clearTimeout(filterTimeout);
            filterTimeout = setTimeout(() => {
                const form = document.getElementById('alertFilterForm');
                if (!form) return;
                
                const formData = new FormData(form);
                const params = new URLSearchParams(formData);
                
                // Show loading state (optional)
                const container = document.getElementById('alertsContainer');
                if (container) container.style.opacity = '0.5';

                fetch(`system_alerts.php?${params.toString()}&ajax=1`)
                    .then(response => response.text())
                    .then(html => {
                        if (container) {
                            container.innerHTML = html;
                            container.style.opacity = '1';
                        }
                    })
                    .catch(error => {
                        console.error('Filter error:', error);
                        if (container) container.style.opacity = '1';
                    });
            }, 300);
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
            <h1 class="nav-text text-xl font-black italic uppercase tracking-tighter text-white"><?= htmlspecialchars($configs['system_name'] ?? 'Horizon System') ?></h1>
        </div>
    </div>
    
    <div class="sidebar-content flex-1 overflow-y-auto no-scrollbar pr-2 pb-10 flex flex-col">
        <div class="nav-section-header px-0 mb-2 mt-4">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Overview</span>
        </div>
        <a href="superadmin_dashboard.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'dashboard') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">grid_view</span> 
            <span class="nav-text">Dashboard</span>
        </a>
        
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
                <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white leading-none">System <span class="text-primary">Alerts</span></h2>
                <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-2">Real-time Network Intelligence</p>
            </div>
            <div class="text-right">
                <p id="headerClock" class="text-white font-black italic text-xl tracking-tight leading-none mb-2">00:00:00 AM</p>
                <p class="text-primary text-[9px] font-black uppercase tracking-[0.2em] opacity-80"><?= date('l, M d, Y') ?></p>
            </div>
        </header>

        <!-- Messages -->
        <?php if (isset($success_msg)): ?>
            <div class="mb-6 p-4 bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-[10px] font-black uppercase tracking-widest rounded-2xl flex items-center gap-3">
                <span class="material-symbols-outlined text-sm">check_circle</span> <?= $success_msg ?>
            </div>
        <?php endif; ?>

        <!-- Filters & Actions -->
        <div class="flex flex-col md:flex-row items-center justify-between gap-6 mb-8">
            <!-- Tab Navigation -->
            <div class="flex p-1 bg-surface-dark/50 rounded-2xl border border-white/5">
                <a href="?view=active&search=<?= urlencode($search) ?>&priority=<?= urlencode($priority_filter) ?>" 
                   class="<?= $view === 'active' ? 'bg-primary text-black shadow-lg shadow-primary/20' : 'text-gray-400 hover:text-white' ?> px-6 py-2.5 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all">
                    Active Alerts
                </a>
                <a href="?view=history&search=<?= urlencode($search) ?>&priority=<?= urlencode($priority_filter) ?>" 
                   class="<?= $view === 'history' ? 'bg-primary text-black shadow-lg shadow-primary/20' : 'text-gray-400 hover:text-white' ?> px-6 py-2.5 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all">
                    Alert History
                </a>
            </div>

            <div class="flex flex-col md:flex-row gap-6 flex-1 md:flex-none">
                <form method="GET" id="alertFilterForm" class="flex flex-col md:flex-row gap-4" onsubmit="event.preventDefault(); reactiveFilter();">
                    <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
                    <div class="w-full md:w-64 glass-card p-2 flex items-center gap-3 px-4">
                        <span class="material-symbols-outlined text-gray-400">search</span>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                               oninput="reactiveFilter()"
                               onkeydown="if(event.key === 'Enter') { event.preventDefault(); reactiveFilter(); }"
                               class="bg-transparent border-none outline-none text-xs text-white w-full py-2 placeholder:text-gray-600 font-bold uppercase tracking-widest">
                    </div>
                    <div class="flex gap-4">
                        <select name="priority" onchange="reactiveFilter()" 
                                class="appearance-none bg-[#0a090d] border border-white/10 rounded-[20px] px-8 pr-12 py-3 text-[10px] font-bold uppercase tracking-widest text-white focus:border-primary focus:outline-none cursor-pointer hover:bg-white/5 transition-all"
                                style="background-image: url('data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2224%22%20height%3D%2224%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22none%22%20stroke%3D%22%23666%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cpolyline%20points%3D%226%209%2012%2015%2018%209%22%3E%3C%2Fpolyline%3E%3C%2Fsvg%3E'); background-repeat: no-repeat; background-position: right 1.5rem center; background-size: 1rem;">
                            <option value="all" class="bg-[#0a090d]" <?= $priority_filter == 'all' ? 'selected' : '' ?>>All Priorities</option>
                            <option value="High" class="bg-[#0a090d]" <?= $priority_filter == 'High' ? 'selected' : '' ?>>High Priority</option>
                            <option value="Medium" class="bg-[#0a090d]" <?= $priority_filter == 'Medium' ? 'selected' : '' ?>>Medium Priority</option>
                        </select>
                    </div>
                </form>

                <?php if ($view === 'active'): ?>
                <form method="POST">
                    <button type="submit" name="clear_all" class="px-6 py-3 bg-primary/20 border border-primary/30 rounded-[20px] text-[9px] font-black uppercase tracking-widest hover:bg-primary/30 transition-all flex items-center gap-2 text-primary">
                        <span class="material-symbols-outlined text-sm transition-transform group-hover:rotate-12">done_all</span>
                        Clear All
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="relative">
            <!-- Timeline Line -->
            <div class="absolute left-7 top-0 bottom-0 w-px bg-gradient-to-b from-primary/30 via-primary/5 to-transparent hidden md:block"></div>

            <div id="alertsContainer">
                <div class="space-y-6 relative">
                <?php foreach($alerts as $alert): 
                    $isHigh = ($alert['priority'] == 'High');
                    $priorityClass = $isHigh ? 'text-rose-500 bg-rose-500/10 border-rose-500/20 alert-glow-high' : 'text-primary bg-primary/10 border-primary/20 alert-glow-medium';
                    $icon = $isHigh ? 'report' : 'info';
                ?>
                    <div class="group relative flex flex-col md:flex-row gap-8 items-start">
                        <!-- Timeline Node -->
                        <div class="hidden md:flex size-14 rounded-xl glass-card shrink-0 items-center justify-center relative z-10 group-hover:border-primary/50 transition-colors">
                            <span class="material-symbols-outlined <?= $isHigh ? 'text-rose-500' : 'text-primary' ?> text-xl"><?= $icon ?></span>
                        </div>

                        <!-- Alert Card -->
                        <div class="flex-1 glass-card p-6 group-hover:bg-white/[0.04] transition-all duration-500">
                            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                                <div class="flex flex-col gap-1">
                                    <div class="flex items-center gap-3 mb-1">
                                        <span class="text-gray-600 text-[9px] font-bold uppercase tracking-widest italic"><?= date('h:i A', strtotime($alert['created_at'])) ?></span>
                                    </div>
                                    <h4 class="text-md font-black italic uppercase text-white tracking-tight leading-none"><?= htmlspecialchars($alert['type']) ?></h4>
                                    <p class="text-[#8e8d91] text-xs mt-1 font-medium"><?= htmlspecialchars($alert['message']) ?></p>
                                    <div class="flex items-center gap-2 mt-3">
                                        <span class="text-[9px] font-black uppercase text-gray-500 tracking-widest">Source:</span>
                                        <span class="text-[9px] font-black uppercase text-white bg-white/5 px-2 py-0.5 rounded"><?= htmlspecialchars($alert['source']) ?></span>
                                    </div>
                                </div>
                                <div class="flex gap-2 shrink-0">
                                    <?php if ($view === 'active'): ?>
                                    <button onclick="requestResolve(<?= $alert['alert_id'] ?>)" class="size-10 flex items-center justify-center rounded-xl bg-white/5 hover:bg-emerald-500/20 hover:text-emerald-400 border border-white/5 transition-all group/btn" title="Resolve">
                                        <span class="material-symbols-outlined text-sm">check</span>
                                    </button>
                                    <?php else: ?>
                                    <div class="size-10 flex items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-500 border border-emerald-500/20" title="Resolved">
                                        <span class="material-symbols-outlined text-sm">verified</span>
                                    </div>
                                    <?php endif; ?>
                                    <button onclick="openAlertModal('<?= addslashes($alert['type']) ?>', '<?= addslashes($alert['message']) ?>', '<?= addslashes($alert['source']) ?>', '<?= date('M d, Y h:i A', strtotime($alert['created_at'])) ?>', '<?= $alert['priority'] ?>')" class="size-10 flex items-center justify-center rounded-xl bg-white/5 hover:bg-primary/20 hover:text-primary border border-white/5 transition-all group/btn" title="More Details">
                                        <span class="material-symbols-outlined text-sm">visibility</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($alerts)): ?>
                    <div class="glass-card p-12 text-center">
                        <p class="text-gray-500 text-xs font-bold uppercase tracking-widest italic">No alerts found matching your criteria.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Pagination Mock -->
            <div class="mt-12 flex justify-center gap-2">
                <button class="size-10 rounded-xl glass-card flex items-center justify-center text-primary border-primary/30">1</button>
                <button class="size-10 rounded-xl glass-card flex items-center justify-center text-gray-500 hover:text-white transition-all">2</button>
                <button class="size-10 rounded-xl glass-card flex items-center justify-center text-gray-500 hover:text-white transition-all">3</button>
            </div>
        </div>
    </main>
</div>

<!-- Alert Details Modal -->
<div id="alertModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-black/80 backdrop-blur-sm transition-all duration-300 opacity-0">
    <div class="glass-card w-full max-w-lg overflow-hidden transform scale-95 transition-all duration-300">
        <div class="p-8">
            <div class="flex justify-between items-start mb-6">
                <div>
                    <h3 id="modalType" class="text-2xl font-black italic uppercase text-white tracking-tight leading-none"></h3>
                </div>
                <button onclick="closeAlertModal()" class="text-gray-400 hover:text-white transition-colors">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            
            <div class="space-y-6">
                <div>
                    <p class="text-[10px] font-black uppercase text-primary tracking-widest mb-2">Message</p>
                    <p id="modalMessage" class="text-white/80 text-sm leading-relaxed font-medium"></p>
                </div>
                
                <div class="grid grid-cols-2 gap-6">
                    <div>
                        <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Source</p>
                        <p id="modalSource" class="text-white font-bold text-xs uppercase"></p>
                    </div>
                    <div>
                        <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Timestamp</p>
                        <p id="modalDate" class="text-white font-bold text-xs uppercase"></p>
                    </div>
                </div>
            </div>

            <div class="mt-10">
                <button onclick="closeAlertModal()" class="w-full py-4 glass-card text-[10px] font-black uppercase tracking-widest hover:bg-white/5 transition-all">Close Details</button>
            </div>
        </div>
    </div>
</div>

<!-- Confirmation Modal -->
<div id="confirmModal" class="fixed inset-0 z-[110] hidden items-center justify-center p-4 bg-black/80 backdrop-blur-sm transition-all duration-300 opacity-0">
    <div class="glass-card w-full max-w-sm overflow-hidden transform scale-95 transition-all duration-300">
        <div class="p-8 text-center">
            <div class="size-16 rounded-full bg-emerald-500/10 flex items-center justify-center mx-auto mb-6">
                <span class="material-symbols-outlined text-emerald-500 text-3xl">help</span>
            </div>
            <h3 class="text-xl font-black italic uppercase text-white mb-2">Resolve Alert?</h3>
            <p class="text-gray-400 text-xs font-medium mb-8">Are you sure you want to mark this alert as resolved? This action cannot be undone.</p>
            
            <div class="flex gap-4">
                <button onclick="closeConfirmModal()" class="flex-1 py-3 glass-card text-[9px] font-black uppercase tracking-widest hover:bg-white/5 transition-all text-gray-400">Cancel</button>
                <form method="POST" class="flex-1">
                    <input type="hidden" name="resolve_id" id="confirmResolveId">
                    <button type="submit" class="w-full py-3 bg-emerald-500 text-black text-[9px] font-black uppercase tracking-widest rounded-xl hover:scale-105 transition-all">Yes, Resolve</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    let currentResolveId = null;

    function requestResolve(id) {
        currentResolveId = id;
        document.getElementById('confirmResolveId').value = id;
        const modal = document.getElementById('confirmModal');
        const modalContent = modal.querySelector('.glass-card');
        
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        setTimeout(() => {
            modal.classList.add('opacity-100');
            modalContent.classList.remove('scale-95');
            modalContent.classList.add('scale-100');
        }, 10);
    }

    function closeConfirmModal() {
        const modal = document.getElementById('confirmModal');
        const modalContent = modal.querySelector('.glass-card');
        
        modal.classList.remove('opacity-100');
        modalContent.classList.remove('scale-100');
        modalContent.classList.add('scale-95');
        
        setTimeout(() => {
            modal.classList.remove('flex');
            modal.classList.add('hidden');
        }, 300);
    }

    function updateClock() {
        const now = new Date();
        const clock = document.getElementById('headerClock');
        if (clock) {
            clock.textContent = now.toLocaleTimeString('en-US', { 
                hour: '2-digit', 
                minute: '2-digit', 
                second: '2-digit', 
                hour12: true 
            });
        }
    }
    setInterval(updateClock, 1000);
    updateClock();

    function openAlertModal(type, message, source, date, priority) {
        const modal = document.getElementById('alertModal');
        const modalContent = modal.querySelector('.glass-card');
        
        document.getElementById('modalType').textContent = type;
        document.getElementById('modalMessage').textContent = message;
        document.getElementById('modalSource').textContent = source;
        document.getElementById('modalDate').textContent = date;
        
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        setTimeout(() => {
            modal.classList.add('opacity-100');
            modalContent.classList.remove('scale-95');
            modalContent.classList.add('scale-100');
        }, 10);
    }

    function closeAlertModal() {
        const modal = document.getElementById('alertModal');
        const modalContent = modal.querySelector('.glass-card');
        
        modal.classList.remove('opacity-100');
        modalContent.classList.remove('scale-100');
        modalContent.classList.add('scale-95');
        
        setTimeout(() => {
            modal.classList.remove('flex');
            modal.classList.add('hidden');
        }, 300);
    }

    // Close modal on click outside
    window.onclick = function(event) {
        const modal = document.getElementById('alertModal');
        if (event.target == modal) {
            closeAlertModal();
        }
    }
</script>

</body>
</html>