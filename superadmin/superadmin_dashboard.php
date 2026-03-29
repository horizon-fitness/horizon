<?php
session_start();
require_once '../db.php';

// Security Check: Only Superadmin can access
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'superadmin') {
    header("Location: ../login.php");
    exit;
}

$page_title = "Super Admin Dashboard";
$active_page = "dashboard";

// Application messages handled via session
$success_msg = $_SESSION['success_msg'] ?? '';
$error_msg = $_SESSION['error_msg'] ?? '';
unset($_SESSION['success_msg'], $_SESSION['error_msg']);

// 0. Revenue Analytics
$stmtRev = $pdo->query("SELECT SUM(amount) FROM payments WHERE payment_status = 'Completed'");
$total_revenue = $stmtRev->fetchColumn() ?: 0.00;

// 1. Gym Analytics
$stmtGyms = $pdo->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN status = 'Suspended' THEN 1 ELSE 0 END) as suspended,
        SUM(CASE WHEN status = 'Deactivated' THEN 1 ELSE 0 END) as deactivated
    FROM gyms");
$gym_stats = $stmtGyms->fetch(PDO::FETCH_ASSOC);

// 2. User Analytics
$stmtUsers = $pdo->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_users,
        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_users
    FROM users");
$user_stats = $stmtUsers->fetch(PDO::FETCH_ASSOC);

// 3. Application Analytics
$stmtPending = $pdo->query("SELECT COUNT(*) FROM gym_owner_applications WHERE application_status = 'Pending'");
$pending_apps_count = $stmtPending->fetchColumn();

// 4. Activity Analytics (System-wide)
// Daily Activity (Last 7 Days - Pre-filled for consistent bar charts)
$daily_activity = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $daily_activity[$d] = ['log_date' => $d, 'count' => 0];
}

$stmtDaily = $pdo->query("
        SELECT DATE(created_at) as log_date, COUNT(*) as count 
        FROM audit_logs 
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) 
        GROUP BY DATE(created_at) 
        ORDER BY log_date ASC
    ");
while ($row = $stmtDaily->fetch(PDO::FETCH_ASSOC)) {
    if (isset($daily_activity[$row['log_date']])) {
        $daily_activity[$row['log_date']]['count'] = (int) $row['count'];
    }
}
$daily_activity = array_values($daily_activity);

// Monthly Activity (Last 6 Months - Pre-filled for consistent line charts)
$monthly_activity = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $monthly_activity[$m] = ['log_month' => $m, 'count' => 0];
}

$stmtMonthly = $pdo->query("
        SELECT DATE_FORMAT(created_at, '%Y-%m') as log_month, COUNT(*) as count 
        FROM audit_logs 
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) 
        GROUP BY log_month 
        ORDER BY log_month ASC
    ");
while ($row = $stmtMonthly->fetch(PDO::FETCH_ASSOC)) {
    if (isset($monthly_activity[$row['log_month']])) {
        $monthly_activity[$row['log_month']]['count'] = (int) $row['count'];
    }
}
$monthly_activity = array_values($monthly_activity);

// 5. System Alerts Analytics (Automated Checks on Dashboard Load)
$nowStr = date('Y-m-d H:i:s');
$sevenDaysLater = date('Y-m-d', strtotime('+7 days'));

// Check for Expiring Client Subscriptions
$stmtExpiring = $pdo->prepare("SELECT cs.*, g.gym_name FROM client_subscriptions cs JOIN gyms g ON cs.gym_id = g.gym_id WHERE cs.end_date <= ? AND cs.subscription_status = 'Active'");
$stmtExpiring->execute([$sevenDaysLater]);
$expiringSubs = $stmtExpiring->fetchAll(PDO::FETCH_ASSOC);
foreach ($expiringSubs as $sub) {
    $daysLeft = (strtotime($sub['end_date']) - strtotime(date('Y-m-d'))) / 86400;
    $msg = "Subscription for " . $sub['gym_name'] . " expires in " . round($daysLeft) . " days (Ends: " . $sub['end_date'] . ")";
    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM system_alerts WHERE message = ? AND status != 'Resolved'");
    $stmtCheck->execute([$msg]);
    if ($stmtCheck->fetchColumn() == 0) {
        $stmtInsert = $pdo->prepare("INSERT INTO system_alerts (type, source, message, priority, status, created_at) VALUES ('Subscription Expiry', 'Billing', ?, 'Medium', 'Unread', ?)");
        $stmtInsert->execute([$msg, $nowStr]);
    }
}

// Check for Pending Payments
$stmtPendingPayments = $pdo->query("SELECT p.*, u.first_name, u.last_name FROM payments p LEFT JOIN members m ON p.member_id = m.member_id LEFT JOIN users u ON m.user_id = u.user_id WHERE p.payment_status = 'Pending'");
$pendingPayments = $stmtPendingPayments->fetchAll(PDO::FETCH_ASSOC);
foreach ($pendingPayments as $payment) {
    $payer = ($payment['first_name']) ? $payment['first_name'] . ' ' . $payment['last_name'] : 'Tenant/Guest';
    $msg = "New payment of ₱" . number_format($payment['amount'], 2) . " from " . $payer . " requires verification. (Ref: " . $payment['reference_number'] . ")";
    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM system_alerts WHERE message = ? AND status != 'Resolved'");
    $stmtCheck->execute([$msg]);
    if ($stmtCheck->fetchColumn() == 0) {
        $stmtInsert = $pdo->prepare("INSERT INTO system_alerts (type, source, message, priority, status, created_at) VALUES ('Payment Verification', 'Finance', ?, 'Medium', 'Unread', ?)");
        $stmtInsert->execute([$msg, $nowStr]);
    }
}

$stmtActiveAlerts = $pdo->query("SELECT COUNT(*) FROM system_alerts WHERE status != 'Resolved'");
$active_alerts_count = $stmtActiveAlerts->fetchColumn();

// Fetch Recent Applications
$stmtList = $pdo->query("
        SELECT a.*, u.first_name, u.last_name, tp.logo_path
        FROM gym_owner_applications a 
        JOIN users u ON a.user_id = u.user_id 
        LEFT JOIN gyms g ON a.application_id = g.application_id
        LEFT JOIN tenant_pages tp ON g.gym_id = tp.gym_id
        ORDER BY 
            CASE WHEN a.application_status = 'Pending' THEN 1 ELSE 2 END,
            a.submitted_at DESC 
        LIMIT 10
    ");
$recent_applications = $stmtList->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html class="dark" lang="en">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title><?php echo $page_title ?? 'Super Admin Dashboard'; ?> | Horizon System</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap"
        rel="stylesheet" />
    <link
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200"
        rel="stylesheet" />
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "#8c2bee", "background-dark": "#0a090d", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)" } } }
        }
    </script>
    <style>
        body {
            font-family: 'Lexend', sans-serif;
            background-color: #0a090d;
            color: white;
        }

        .glass-card {
            background: #14121a;
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 24px;
        }

        /* Sidebar Hover Logic - ADJUSTED WIDTHS */
        .sidebar-nav {
            width: 110px;
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .sidebar-nav:hover {
            width: 300px;
        }

        /* Added: Scrollable container for links */
        .sidebar-scroll-container {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            padding-right: 4px;
        }

        /* Custom Scrollbar for the sidebar */
        .sidebar-scroll-container::-webkit-scrollbar {
            width: 4px;
        }

        .sidebar-scroll-container::-webkit-scrollbar-track {
            background: transparent;
        }

        .sidebar-scroll-container::-webkit-scrollbar-thumb {
            background: rgba(140, 43, 238, 0.1);
            border-radius: 10px;
        }

        .sidebar-nav:hover .sidebar-scroll-container::-webkit-scrollbar-thumb {
            background: rgba(140, 43, 238, 0.4);
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
            margin-bottom: 0px !important;
            pointer-events: auto;
        }

        .sidebar-nav:hover .nav-section-header.mt-4 {
            margin-top: 0px !important;
        }

        .sidebar-nav:hover .nav-section-header.mt-6 {
            margin-top: 0px !important;
        }

        .sidebar-content {
            gap: 2px;
            transition: all 0.3s ease-in-out;
        }

        .sidebar-nav:hover .sidebar-content {
            gap: 4px;
        }

        .nav-link {
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.05em;
            transition: all 0.2s;
            white-space: nowrap;
        }

        .active-nav {
            color: #8c2bee !important;
            position: relative;
        }

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

        @media (max-width: 1023px) {
            .active-nav::after {
                display: none;
            }
        }

        .alert-pulse {
            animation: alert-pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }

        @keyframes alert-pulse {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: .5;
            }
        }

        .status-card-green {
            border: 1px solid #10b981;
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.05) 0%, rgba(20, 18, 26, 1) 100%);
        }

        .status-card-yellow {
            border: 1px solid #f59e0b;
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.05) 0%, rgba(20, 18, 26, 1) 100%);
        }

        .status-card-red {
            border: 1px solid #ef4444;
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.05) 0%, rgba(20, 18, 26, 1) 100%);
        }

        .dashed-container {
            border: 2px dashed rgba(255, 255, 255, 0.1);
            border-radius: 24px;
        }

        .no-scrollbar::-webkit-scrollbar {
            display: none;
        }

        .no-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
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

    <nav
        class="sidebar-nav bg-[#0a090d] border-r border-white/5 sticky top-0 h-screen px-7 py-8 z-50 shrink-0 flex flex-col">
        <div class="mb-4 shrink-0">
            <div class="flex items-center gap-4 mb-4">
                <div class="size-10 rounded-xl bg-[#7f13ec] flex items-center justify-center shadow-lg shrink-0">
                    <span class="material-symbols-outlined text-white text-2xl">bolt</span>
                </div>
                <h1 class="nav-text text-xl font-black italic uppercase tracking-tighter text-white">Horizon System</h1>
            </div>
        </div>

        <div class="sidebar-scroll-container">
            <div class="nav-section-header px-0 mb-2">
                <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Overview</span>
            </div>
            <a href="superadmin_dashboard.php"
                class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'dashboard') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">grid_view</span>
                <span class="nav-text">Dashboard</span>
            </a>

            <div class="nav-section-header px-0 mb-2 mt-4">
                <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Management</span>
            </div>
            <a href="tenant_management.php"
                class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'tenants') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">business</span>
                <span class="nav-text">Tenant Management</span>
            </a>

            <a href="subscription_logs.php"
                class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'subscriptions') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">history_edu</span>
                <span class="nav-text">Subscription Logs</span>
            </a>

            <a href="real_time_occupancy.php"
                class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'occupancy') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">group</span>
                <span class="nav-text">Real-Time Occupancy</span>
            </a>

            <a href="recent_transaction.php"
                class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'transactions') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">receipt_long</span>
                <span class="nav-text">Recent Transactions</span>
            </a>

            <div class="nav-section-header px-0 mb-2 mt-4">
                <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">System</span>
            </div>
            <a href="system_alerts.php"
                class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'alerts') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">notifications_active</span>
                <span class="nav-text">System Alerts</span>
            </a>

            <a href="system_reports.php"
                class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'reports') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">analytics</span>
                <span class="nav-text">Reports</span>
            </a>

            <a href="sales_report.php"
                class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'sales_report') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">monitoring</span>
                <span class="nav-text">Sales Reports</span>
            </a>

            <a href="audit_logs.php"
                class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'audit_logs') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">assignment</span>
                <span class="nav-text">Audit Logs</span>
            </a>

            <a href="backup.php"
                class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'backup') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">backup</span>
                <span class="nav-text">Backup</span>
            </a>
        </div>

        <div class="mt-auto pt-4 border-t border-white/10 flex flex-col gap-2 shrink-0">
            <div class="nav-section-header px-0 mb-0">
                <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Account</span>
            </div>
            <a href="settings.php"
                class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'settings') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">settings</span>
                <span class="nav-text">Settings</span>
            </a>
            <a href="profile.php"
                class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'profile') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">person</span>
                <span class="nav-text">Profile</span>
            </a>
            <a href="../logout.php"
                class="text-gray-400 hover:text-rose-500 transition-colors flex items-center gap-4 group py-2">
                <span
                    class="material-symbols-outlined group-hover:translate-x-1 transition-transform text-xl shrink-0">logout</span>
                <span class="nav-link nav-text">Sign Out</span>
            </a>
        </div>
    </nav>

    <div class="flex-1 flex flex-col min-w-0 overflow-y-auto">
        <main class="flex-1 p-6 md:p-10 max-w-[1400px] w-full mx-auto">
            <header class="mb-10 flex flex-row justify-between items-end gap-6">
                <div>
                    <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white leading-none">System
                        <span class="text-primary">Overview</span></h2>
                    <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-2">Super Admin Control Center
                    </p>
                </div>
                <div class="text-right">
                    <p id="headerClock" class="text-white font-black italic text-xl tracking-tight leading-none mb-2">
                        00:00:00 AM</p>
                    <p class="text-primary text-[9px] font-black uppercase tracking-[0.2em] opacity-80">
                        <?= date('l, M d, Y') ?></p>
                </div>
            </header>

            <?php if ($success_msg): ?>
                <div
                    class="mb-8 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-sm font-semibold flex items-center gap-3">
                    <span class="material-symbols-outlined">check_circle</span>
                    <?= htmlspecialchars($success_msg) ?>
                </div>
            <?php endif; ?>

            <?php if ($error_msg): ?>
                <div
                    class="mb-8 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-sm font-semibold flex items-center gap-3">
                    <span class="material-symbols-outlined">error</span>
                    <?= htmlspecialchars($error_msg) ?>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-10">
                <div class="glass-card p-8 status-card-green relative overflow-hidden group">
                    <span
                        class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform">payments</span>
                    <p class="text-[10px] font-black uppercase text-gray-400 mb-2 tracking-widest">Global Revenue</p>
                    <h3 class="text-2xl font-black italic uppercase">₱<?= number_format($total_revenue, 2) ?></h3>
                    <p class="text-emerald-500 text-[10px] font-black uppercase mt-2">Across All Tenants</p>
                </div>

                <div class="glass-card p-8 status-card-yellow relative overflow-hidden group">
                    <span
                        class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform">business</span>
                    <p class="text-[10px] font-black uppercase text-gray-400 mb-2 tracking-widest">Total Tenants</p>
                    <h3 class="text-2xl font-black italic uppercase"><?= $gym_stats['total'] ?> Gyms</h3>
                    <div class="flex gap-3 mt-2">
                        <p class="text-emerald-500 text-[9px] font-black uppercase tracking-tighter">
                            <?= $gym_stats['active'] ?> Active</p>
                        <p class="text-amber-500 text-[9px] font-black uppercase tracking-tighter">
                            <?= $gym_stats['suspended'] ?> Suspended</p>
                    </div>
                </div>

                <div class="glass-card p-8 relative overflow-hidden group border border-white/5 bg-white/5">
                    <span
                        class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform">groups</span>
                    <p class="text-[10px] font-black uppercase text-gray-400 mb-2 tracking-widest">User Directory</p>
                    <h3 class="text-2xl font-black italic uppercase"><?= number_format($user_stats['total']) ?></h3>
                    <div class="flex gap-3 mt-2">
                        <p class="text-primary text-[9px] font-black uppercase tracking-tighter">
                            <?= number_format($user_stats['active_users']) ?> Active</p>
                        <p class="text-gray-500 text-[9px] font-black uppercase tracking-tighter">
                            <?= number_format($user_stats['inactive_users']) ?> Inactive</p>
                    </div>
                </div>

                <div class="glass-card p-8 relative overflow-hidden group border border-amber-500/20 bg-amber-500/5">
                    <span
                        class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-amber-500">pending_actions</span>
                    <p class="text-[10px] font-black uppercase text-amber-500/70 mb-2 tracking-widest">Pending Apps</p>
                    <h3 class="text-2xl font-black italic uppercase text-amber-400"><?= $pending_apps_count ?></h3>
                    <p class="text-amber-500 text-[10px] font-black uppercase mt-2">Action Required</p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-10">
                <div class="glass-card p-8">
                    <div class="flex justify-between items-center mb-6">
                        <div>
                            <h3 class="text-sm font-black italic uppercase tracking-widest">Daily System Activity</h3>
                            <p class="text-[9px] text-gray-500 font-bold uppercase mt-1 tracking-wider">Events across
                                last 7 days</p>
                        </div>
                    </div>
                    <div class="h-[200px] w-full">
                        <canvas id="dailyActivityChart"></canvas>
                    </div>
                </div>
                <div class="glass-card p-8">
                    <div class="flex justify-between items-center mb-6">
                        <div>
                            <h3 class="text-sm font-black italic uppercase tracking-widest">Monthly Growth Trend</h3>
                            <p class="text-[9px] text-gray-500 font-bold uppercase mt-1 tracking-wider">Event volume
                                over 6 months</p>
                        </div>
                    </div>
                    <div class="h-[200px] w-full">
                        <canvas id="monthlyGrowthChart"></canvas>
                    </div>
                </div>
            </div>

            <script>
                // 1. Daily Activity Chart
                const dailyCtx = document.getElementById('dailyActivityChart').getContext('2d');
                new Chart(dailyCtx, {
                    type: 'bar',
                    data: {
                        labels: <?= json_encode(array_map(function ($d) {
                            return date('D', strtotime($d['log_date'])); }, $daily_activity)) ?>,
                        datasets: [{
                            label: 'Activity Count',
                            data: <?= json_encode(array_map(function ($d) {
                                return (int) $d['count']; }, $daily_activity)) ?>,
                            backgroundColor: 'rgba(140, 43, 238, 0.2)',
                            borderColor: '#8c2bee',
                            borderWidth: 2,
                            borderRadius: 8,
                            hoverBackgroundColor: '#8c2bee'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#666', font: { size: 9, weight: '800' } } },
                            x: { grid: { display: false }, ticks: { color: '#666', font: { size: 9, weight: '800' } } }
                        }
                    }
                });

                // 2. Monthly Growth Chart
                const monthlyCtx = document.getElementById('monthlyGrowthChart').getContext('2d');
                new Chart(monthlyCtx, {
                    type: 'line',
                    data: {
                        labels: <?= json_encode(array_map(function ($d) {
                            return date('M', strtotime($d['log_month'] . '-01')); }, $monthly_activity)) ?>,
                        datasets: [{
                            label: 'System Growth',
                            data: <?= json_encode(array_map(function ($d) {
                                return (int) $d['count']; }, $monthly_activity)) ?>,
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            fill: true,
                            tension: 0.4,
                            pointRadius: 4,
                            pointBackgroundColor: '#10b981'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#666', font: { size: 9, weight: '800' } } },
                            x: { grid: { display: false }, ticks: { color: '#666', font: { size: 9, weight: '800' } } }
                        }
                    }
                });
            </script>

            <div class="glass-card overflow-hidden mb-10">
                <div class="px-8 py-6 border-b border-white/5 bg-white/5 flex justify-between items-center">
                    <h4 class="font-black italic uppercase text-sm tracking-tighter">Gym Applications <span
                            class="text-primary">&</span> Tenant Activity</h4>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr
                                class="bg-background-dark/50 text-gray-500 text-[10px] font-black uppercase tracking-widest">
                                <th class="px-8 py-4">Gym Name</th>
                                <th class="px-8 py-4">Applicant</th>
                                <th class="px-8 py-4">Applied Date</th>
                                <th class="px-8 py-4">Status</th>
                                <th class="px-8 py-4 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <?php if (empty($recent_applications)): ?>
                                <tr>
                                    <td colspan="5"
                                        class="px-8 py-8 text-center text-xs font-bold text-gray-500 italic uppercase">No
                                        recent applications found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recent_applications as $app): ?>
                                    <tr class="hover:bg-white/5 transition-all">
                                        <td class="px-8 py-5">
                                            <div class="flex items-center gap-3">
                                                <div
                                                    class="size-10 rounded-lg bg-white/5 flex items-center justify-center overflow-hidden border border-white/5 shadow-inner shrink-0">
                                                    <?php if (!empty($app['logo_path']) && $app['logo_path'] !== 'pending'):
                                                        $logo_src = (strpos($app['logo_path'], 'data:image') === 0) ? $app['logo_path'] : '../' . $app['logo_path'];
                                                        ?>
                                                        <img src="<?= $logo_src ?>" class="size-full object-contain">
                                                    <?php else: ?>
                                                        <span
                                                            class="text-primary font-black text-xs"><?= strtoupper(substr($app['gym_name'], 0, 2)) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <p class="text-sm font-bold italic">
                                                        <?= htmlspecialchars($app['gym_name']) ?></p>
                                                    <p class="text-[10px] text-gray-500 uppercase tracking-wider font-bold">
                                                        <?= htmlspecialchars($app['business_type']) ?></p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-8 py-5">
                                            <p class="text-xs font-medium text-white">
                                                <?= htmlspecialchars($app['first_name'] . ' ' . $app['last_name']) ?></p>
                                            <p class="text-[10px] text-gray-500"><?= htmlspecialchars($app['email']) ?></p>
                                        </td>
                                        <td class="px-8 py-5 text-xs font-medium text-gray-400">
                                            <?= date('M d, Y h:i A', strtotime($app['submitted_at'])) ?>
                                        </td>
                                        <td class="px-8 py-5">
                                            <?php if ($app['application_status'] === 'Pending'): ?>
                                                <span
                                                    class="px-3 py-1 rounded-full bg-amber-500/10 border border-amber-500/20 text-[9px] text-amber-500 font-black uppercase italic">Pending</span>
                                            <?php elseif ($app['application_status'] === 'Approved'): ?>
                                                <span
                                                    class="px-3 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-[9px] text-emerald-500 font-black uppercase italic">Approved</span>
                                            <?php else: ?>
                                                <span
                                                    class="px-3 py-1 rounded-full bg-red-500/10 border border-red-500/20 text-[9px] text-red-500 font-black uppercase italic">Rejected</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-8 py-5 text-right">
                                            <?php if ($app['application_status'] === 'Pending'): ?>
                                                <div class="inline-flex gap-2">
                                                    <a href="view_application.php?id=<?= $app['application_id'] ?>"
                                                        class="px-4 py-1.5 rounded-lg bg-white/5 hover:bg-white/10 border border-white/5 text-gray-400 text-[10px] font-black uppercase tracking-widest transition-colors flex items-center gap-1">
                                                        <span class="material-symbols-outlined text-sm">visibility</span> View
                                                    </a>
                                                    <form method="POST" action="../action/process_application.php"
                                                        class="inline-flex gap-2">
                                                        <input type="hidden" name="application_id"
                                                            value="<?= $app['application_id'] ?>">
                                                        <button type="submit" name="action" value="approve"
                                                            class="px-4 py-1.5 rounded-lg bg-emerald-500 hover:bg-emerald-600 text-white text-[10px] font-black uppercase tracking-widest transition-colors">
                                                            Approve
                                                        </button>
                                                        <button type="submit" name="action" value="reject"
                                                            class="px-4 py-1.5 rounded-lg bg-red-500/10 hover:bg-red-500/20 border border-red-500/20 text-red-400 text-[10px] font-black uppercase tracking-widest transition-colors">
                                                            Reject
                                                        </button>
                                                    </form>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-[10px] font-black text-gray-500 uppercase italic">Reviewed</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
    <?php include '../includes/image_viewer.php'; ?>
</body>

</html>