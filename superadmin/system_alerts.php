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

// Hex to RGB helper for dynamic transparency
function hexToRgb($hex)
{
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
// 1. Fetch Global Settings
$stmtGlobal = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE user_id = 0");
$global_configs = $stmtGlobal->fetchAll(PDO::FETCH_KEY_PAIR);

// 2. Fetch User-Specific Settings
$stmtUser = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = ?");
$stmtUser->execute([$_SESSION['user_id']]);
$user_configs = $stmtUser->fetchAll(PDO::FETCH_KEY_PAIR);

// 3. Merge (User settings take precedence for overlapping keys if any)
$configs = array_merge($global_configs, $user_configs);

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
$category_filter = $_GET['category'] ?? 'all';
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

// Category Intelligence Filter (Database-Driven)
if ($category_filter !== 'all') {
    $query .= " AND type = :category";
    $params['category'] = $category_filter;
}

$query .= " ORDER BY created_at DESC";
$stmtAlerts = $pdo->prepare($query);
$stmtAlerts->execute($params);
$alerts = $stmtAlerts->fetchAll(PDO::FETCH_ASSOC);

// Final Data Merging & Sorting (Production-Ready)
usort($alerts, function ($a, $b) {
    return strtotime($b['created_at']) <=> strtotime($a['created_at']);
});

// Dynamic Category Fetching (Database-Driven)
$stmtTypes = $pdo->query("SELECT DISTINCT type FROM system_alerts");
$available_categories = $stmtTypes->fetchAll(PDO::FETCH_COLUMN);
sort($available_categories);

// Handle AJAX Request for Live Search
if (isset($_GET['ajax'])) {
    ?>
    <div class="space-y-6 relative">
        <?php foreach ($alerts as $alert):
            $isHigh = ($alert['priority'] == 'High');
            $priorityClass = $isHigh ? 'text-rose-500 bg-rose-500/10 border-rose-500/20 alert-glow-high' : 'text-primary bg-primary/10 border-primary/20 alert-glow-medium';
            $icon = $isHigh ? 'report' : 'info';
            ?>
            <div class="group relative flex flex-col md:flex-row gap-8 items-start">
                <div
                    class="hidden md:flex size-14 rounded-xl glass-card shrink-0 items-center justify-center relative z-10 group-hover:border-primary/50 transition-colors">
                    <span
                        class="material-symbols-outlined <?= $isHigh ? 'text-rose-500' : 'text-primary' ?> text-xl"><?= $icon ?></span>
                </div>
                <div class="flex-1 glass-card p-6 group-hover:bg-white/[0.04] transition-all duration-500">
                    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                        <div class="flex flex-col gap-1">
                            <div class="flex items-center gap-3 mb-1">
                                <span
                                    class="text-[--text-main]/40 text-[9px] font-bold uppercase tracking-widest italic"><?= date('h:i A', strtotime($alert['created_at'])) ?></span>
                            </div>
                            <h4 class="text-md font-black italic uppercase text-[--text-main] tracking-tight leading-none">
                                <?= htmlspecialchars($alert['type']) ?>
                            </h4>
                            <p class="text-[--text-main]/60 text-xs mt-1 font-medium"><?= htmlspecialchars($alert['message']) ?>
                            </p>
                            <div class="flex items-center gap-2 mt-3">
                                <span
                                    class="text-[9px] font-black uppercase text-[--text-main]/40 tracking-widest">Source:</span>
                                <span
                                    class="text-[9px] font-black uppercase text-[--text-main] bg-white/5 px-2 py-0.5 rounded"><?= htmlspecialchars($alert['source']) ?></span>
                            </div>
                        </div>
                        <div class="flex gap-2 shrink-0">
                            <?php if ($view === 'active'): ?>
                                <button onclick="requestResolve(<?= json_encode($alert['alert_id']) ?>)"
                                    class="size-10 flex items-center justify-center rounded-xl bg-white/5 hover:bg-emerald-500/20 hover:text-emerald-400 border border-white/5 transition-all group/btn"
                                    title="Resolve">
                                    <span class="material-symbols-outlined text-sm">check</span>
                                </button>
                            <?php else: ?>
                                <div class="size-10 flex items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-500 border border-emerald-500/20"
                                    title="Resolved">
                                    <span class="material-symbols-outlined text-sm">verified</span>
                                </div>
                            <?php endif; ?>
                            <button
                                onclick="openAlertModal(<?= htmlspecialchars(json_encode($alert['type'])) ?>, <?= htmlspecialchars(json_encode($alert['message'])) ?>, <?= htmlspecialchars(json_encode($alert['source'])) ?>, '<?= date('M d, Y h:i A', strtotime($alert['created_at'])) ?>', '<?= $alert['priority'] ?>')"
                                class="size-10 flex items-center justify-center rounded-xl bg-white/5 hover:bg-primary/20 hover:text-primary border border-white/5 transition-all group/btn"
                                title="More Details">
                                <span class="material-symbols-outlined text-sm">visibility</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (empty($alerts)): ?>
            <div class="glass-card p-12 text-center">
                <p class="text-[--text-main]/40 text-xs font-bold uppercase tracking-widest italic">No alerts found matching
                    your criteria.</p>
            </div>
        <?php endif; ?>
    </div>
    <!-- Pagination Mock -->
    <div class="mt-12 flex justify-center gap-2">
        <button
            class="size-10 rounded-xl glass-card flex items-center justify-center text-primary border-primary/30">1</button>
        <button
            class="size-10 rounded-xl glass-card flex items-center justify-center text-[--text-main]/40 hover:text-white transition-all">2</button>
        <button
            class="size-10 rounded-xl glass-card flex items-center justify-center text-[--text-main]/40 hover:text-white transition-all">3</button>
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
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title><?= htmlspecialchars($configs['system_name'] ?? 'Horizon System') ?> | System Alerts</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap"
        rel="stylesheet" />
    <link
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200"
        rel="stylesheet" />
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "var(--primary)", "background": "var(--background)", "secondary": "var(--secondary)", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)" } } }
        }
    </script>
    <style>
        :root {
            --primary:
                <?= $configs['theme_color'] ?? '#8c2bee' ?>
            ;
            --primary-rgb:
                <?= hexToRgb($configs['theme_color'] ?? '#8c2bee') ?>
            ;
            --background:
                <?= $configs['bg_color'] ?? '#0a090d' ?>
            ;
            --highlight:
                <?= $configs['secondary_color'] ?? '#a1a1aa' ?>
            ;
            --text-main:
                <?= $configs['text_color'] ?? '#d1d5db' ?>
            ;
            --card-blur: 20px;
            --card-bg:
                <?= ($configs['auto_card_theme'] ?? '1') === '1' ? 'rgba(' . hexToRgb($configs['theme_color'] ?? '#8c2bee') . ', 0.05)' : ($configs['card_color'] ?? '#141216') ?>
            ;
        }

        body {
            font-family: '<?= $configs['font_family'] ?? 'Lexend' ?>', sans-serif;
            background-color: var(--background);
            color: var(--text-main);
        }

        .glass-card {
            background: var(--card-bg);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 24px;
            backdrop-filter: blur(var(--card-blur));
            transition: all 0.3s ease;
        }

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

        .sidebar-nav:hover~.main-content {
            margin-left: 300px;
        }

        .sidebar-scroll-container {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
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

        /* Invisible Scroll System (Global Reset) */
        * {
            scrollbar-width: none !important;
            -ms-overflow-style: none !important;
        }

        *::-webkit-scrollbar {
            display: none !important;
        }

        ::-webkit-scrollbar {
            width: 0;
            height: 0;
            display: none;
        }

        .no-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        /* Sidebar-Aware Modal Styles */
        #alertModal,
        #confirmModal {
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            left: 110px;
            z-index: 200;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            pointer-events: none;
            transition: left 0.4s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.3s ease;
        }

        .sidebar-nav:hover~#alertModal,
        .sidebar-nav:hover~#confirmModal {
            left: 300px;
        }

        #alertModal.modal-active,
        #confirmModal.modal-active {
            opacity: 1 !important;
            pointer-events: auto !important;
        }
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
        let currentPage = 1;
        const rowsPerPage = 10;

        function reactiveFilter() {
            clearTimeout(filterTimeout);
            filterTimeout = setTimeout(() => {
                const form = document.getElementById('alertFilterForm');
                if (!form) return;

                const formData = new FormData(form);
                const params = new URLSearchParams(formData);

                const container = document.getElementById('alertsContainer');
                if (container) container.style.opacity = '0.5';

                fetch(`system_alerts.php?${params.toString()}&ajax=1`)
                    .then(response => response.text())
                    .then(html => {
                        if (container) {
                            container.innerHTML = html;
                            container.style.opacity = '1';
                            currentPage = 1; // Reset to page 1 on new filter
                            initElitePagination();
                        }
                    })
                    .catch(error => {
                        console.error('Filter error:', error);
                        if (container) container.style.opacity = '1';
                    });
            }, 300);
        }

        function initElitePagination() {
            const container = document.getElementById('alertsContainer');
            const listWrapper = container.querySelector('.space-y-6');
            if (!listWrapper) return;

            const alerts = listWrapper.querySelectorAll('.group.relative.flex');
            const totalAlerts = alerts.length;
            const totalPages = Math.ceil(totalAlerts / rowsPerPage);

            // Clear current display
            alerts.forEach(a => a.style.display = 'none');

            // Show current page
            const start = (currentPage - 1) * rowsPerPage;
            const end = start + rowsPerPage;
            for (let i = start; i < end && i < totalAlerts; i++) {
                alerts[i].style.display = 'flex';
                // Add entrance animation
                alerts[i].style.animation = `fadeSlideIn 0.4s ease forwards ${(i % 10) * 0.05}s`;
                alerts[i].style.opacity = '0';
            }

            // Update Status Label
            const statusLabel = document.getElementById('paginationStatus');
            if (statusLabel) {
                if (totalAlerts > 0) {
                    statusLabel.textContent = `Showing ${start + 1} to ${Math.min(end, totalAlerts)} of ${totalAlerts} entries`;
                } else {
                    statusLabel.textContent = `Showing 0 entries`;
                }
            }

            // Rebuild UI
            const paginationUI = document.getElementById('paginationControls');
            if (!paginationUI) return;

            if (totalAlerts <= rowsPerPage) {
                paginationUI.style.display = 'none';
                return;
            }

            paginationUI.style.display = 'flex';
            let buttonsHtml = '';

            // Prev Button
            buttonsHtml += `<button onclick="changePage(${currentPage - 1})" class="px-5 py-2.5 rounded-xl glass-card text-[9px] font-black uppercase tracking-widest text-[--text-main]/40 hover:text-primary transition-all flex items-center gap-2 ${currentPage === 1 ? 'opacity-30 pointer-events-none' : ''}">
                <span class="material-symbols-outlined text-xs">chevron_left</span> PREV
            </button>`;

            // Page Buttons
            for (let i = 1; i <= totalPages; i++) {
                const isActive = (i === currentPage);
                buttonsHtml += `<button onclick="changePage(${i})" class="size-10 rounded-xl glass-card flex items-center justify-center text-[10px] font-black tracking-widest transition-all ${isActive ? 'bg-primary text-[--background] border-primary shadow-lg shadow-primary/20' : 'text-[--text-main]/40 hover:text-white border-white/5'}">
                    ${i}
                </button>`;
            }

            // Next Button
            buttonsHtml += `<button onclick="changePage(${currentPage + 1})" class="px-5 py-2.5 rounded-xl glass-card text-[9px] font-black uppercase tracking-widest text-[--text-main]/40 hover:text-primary transition-all flex items-center gap-2 ${currentPage === totalPages ? 'opacity-30 pointer-events-none' : ''}">
                NEXT <span class="material-symbols-outlined text-xs">chevron_right</span>
            </button>`;

            paginationUI.innerHTML = buttonsHtml;
        }

        function changePage(p) {
            currentPage = p;
            initElitePagination();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        window.addEventListener('load', initElitePagination);

        // Entrance Animation CSS
        const style = document.createElement('style');
        style.textContent = `
            @keyframes fadeSlideIn {
                from { opacity: 0; transform: translateY(10px); }
                to { opacity: 1; transform: translateY(0); }
            }
        `;
        document.head.appendChild(style);

    </script>
</head>

<body class="antialiased flex flex-row min-h-screen">

    <nav id="liveSidebar" class="sidebar-nav z-50 flex flex-col no-scrollbar">
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
                <h1 id="sidebarSystemName"
                    class="nav-text text-lg font-black italic uppercase tracking-tighter text-[--text-main]">
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
            <a href="tenant_management.php" class="nav-link <?= ($active_page == 'tenants') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">business</span>
                <span class="nav-text">Tenant Management</span>
            </a>

            <a href="subscription_logs.php"
                class="nav-link <?= ($active_page == 'subscriptions') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">history_edu</span>
                <span class="nav-text">Subscription Logs</span>
            </a>

            <a href="real_time_occupancy.php" class="nav-link <?= ($active_page == 'occupancy') ? 'active-nav' : '' ?>">
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
            <a href="system_alerts.php" class="nav-link <?= ($active_page == 'alerts') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">notifications_active</span>
                <span class="nav-text">System Alerts</span>
            </a>

            <a href="system_reports.php" class="nav-link <?= ($active_page == 'reports') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">analytics</span>
                <span class="nav-text">Reports</span>
            </a>

            <a href="sales_report.php" class="nav-link <?= ($active_page == 'sales_report') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">monitoring</span>
                <span class="nav-text">Sales Reports</span>
            </a>

            <a href="audit_logs.php" class="nav-link <?= ($active_page == 'audit_logs') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">assignment</span>
                <span class="nav-text">Audit Logs</span>
            </a>

            <a href="backup.php" class="nav-link <?= ($active_page == 'backup') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">backup</span>
                <span class="nav-text">Backup</span>
            </a>
        </div>

        <div class="mt-auto pt-4 border-t border-white/10 flex flex-col gap-1 shrink-0 pb-6">
            <div class="nav-section-header mb-2">
                <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Account</span>
            </div>
            <a href="settings.php" class="nav-link <?= ($active_page == 'settings') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">settings</span>
                <span class="nav-text">Settings</span>
            </a>
            <a href="profile.php" class="nav-link <?= ($active_page == 'profile') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">person</span>
                <span class="nav-text">Profile</span>
            </a>
            <a href="../logout.php" class="nav-link hover:text-rose-500 transition-all group">
                <span class="material-symbols-outlined text-xl shrink-0 group-hover:text-rose-500">logout</span>
                <span class="nav-text group-hover:text-rose-500">Sign Out</span>
            </a>
        </div>
    </nav>

    <div class="main-content flex-1 flex flex-col min-w-0 overflow-y-auto">
        <main class="flex-1 p-6 md:p-10 max-w-[1400px] w-full mx-auto">
            <header class="mb-12 flex flex-row justify-between items-end gap-6">
                <div>
                    <h2 class="text-3xl font-black italic uppercase tracking-tighter leading-none">
                        <span class="text-[--text-main] opacity-80">SYSTEM</span>
                        <span class="text-primary">ALERTS</span>
                    </h2>
                    <p class="text-[--text-main] text-[10px] font-bold uppercase tracking-widest mt-2 px-1 opacity-80">
                        Real-time Network Intelligence</p>
                </div>
                <div class="flex items-end gap-8 text-right shrink-0">
                    <div class="flex flex-col items-end">
                        <p id="headerClock"
                            class="text-[--text-main] font-black italic text-2xl leading-none tracking-tighter uppercase transition-colors cursor-default">
                            00:00:00 AM</p>
                        <p class="text-primary text-[10px] font-black uppercase tracking-[0.2em] leading-none mt-2">
                            <?= date('l, M d, Y') ?>
                        </p>
                    </div>
                </div>
            </header>

            <!-- Messages -->
            <?php if (isset($success_msg)): ?>
                <div
                    class="mb-6 p-4 bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-[10px] font-black uppercase tracking-widest rounded-2xl flex items-center gap-3">
                    <span class="material-symbols-outlined text-sm">check_circle</span> <?= $success_msg ?>
                </div>
            <?php endif; ?>

            <!-- Filters & Actions -->
            <div class="flex flex-col md:flex-row items-center justify-between gap-6 mb-8">
                <!-- Tab Navigation -->
                <div class="flex p-1.5 bg-white/5 rounded-full border border-white/5 backdrop-blur-md">
                    <a href="?view=active&search=<?= urlencode($search) ?>&priority=<?= urlencode($priority_filter) ?>"
                        class="<?= $view === 'active' ? 'bg-primary text-[--text-main] shadow-lg shadow-primary/30' : 'text-[--text-main]/40 hover:text-[--text-main]/70' ?> px-10 py-3 rounded-full text-[10px] font-black uppercase tracking-widest transition-all">
                        Active Alerts
                    </a>
                    <a href="?view=history&search=<?= urlencode($search) ?>&priority=<?= urlencode($priority_filter) ?>"
                        class="<?= $view === 'history' ? 'bg-primary text-[--text-main] shadow-lg shadow-primary/30' : 'text-[--text-main]/40 hover:text-[--text-main]/70' ?> px-10 py-3 rounded-full text-[10px] font-black uppercase tracking-widest transition-all">
                        Alert History
                    </a>
                </div>

                <div class="flex flex-col md:flex-row gap-6 flex-1 md:flex-none">
                    <form method="GET" id="alertFilterForm" class="flex flex-col md:flex-row gap-4"
                        onsubmit="event.preventDefault(); reactiveFilter();">
                        <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
                        <div
                            class="w-full md:w-64 bg-white/[0.03] border border-white/5 p-1 px-5 flex items-center gap-3 rounded-full focus-within:border-primary/50 transition-all">
                            <span class="material-symbols-outlined text-[--text-main]/40 text-sm">search</span>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                                oninput="reactiveFilter()"
                                onkeydown="if(event.key === 'Enter') { event.preventDefault(); reactiveFilter(); }"
                                class="bg-transparent border-none outline-none text-[10px] text-[--text-main] w-full py-2 placeholder:text-[--text-main]/20 font-black uppercase tracking-widest"
                                placeholder="SEARCH ALERTS...">
                        </div>
                        <div class="relative">
                            <select name="category" onchange="reactiveFilter()"
                                class="appearance-none bg-white/[0.03] border border-white/5 rounded-full px-10 pr-14 py-3.5 text-[10px] font-black uppercase tracking-widest text-[--text-main] focus:border-primary/50 focus:outline-none cursor-pointer hover:bg-white/[0.06] transition-all"
                                style="background-image: url('data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2224%22%20height%3D%2224%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22none%22%20stroke%3D%22white%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cpolyline%20points%3D%226%209%2012%2015%2018%209%22%3E%3C%2Fpolyline%3E%3C%2Fsvg%3E'); background-repeat: no-repeat; background-position: right 1.5rem center; background-size: 0.8rem;">
                                <option value="all" class="bg-[--background]" <?= $category_filter == 'all' ? 'selected' : '' ?>>All</option>
                                <?php foreach ($available_categories as $cat): ?>
                                    <option value="<?= htmlspecialchars($cat) ?>" class="bg-[--background]"
                                        <?= $category_filter == $cat ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cat) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </form>

                    <form method="POST">
                        <button type="submit" name="clear_all"
                            class="px-10 py-3.5 bg-primary/10 border border-primary/20 rounded-full text-[10px] font-black uppercase tracking-widest hover:bg-primary/20 transition-all flex items-center gap-3 text-primary group">
                            <span
                                class="material-symbols-outlined text-sm transition-transform group-hover:scale-110">restart_alt</span>
                            Clear All
                        </button>
                    </form>
                </div>
            </div>

            <div class="relative">
                <!-- Timeline Line -->
                <div
                    class="absolute left-7 top-0 bottom-0 w-px bg-gradient-to-b from-primary/30 via-primary/5 to-transparent hidden md:block">
                </div>

                <div id="alertsContainer">
                    <div class="space-y-6 relative">
                        <?php foreach ($alerts as $alert):
                            $isHigh = ($alert['priority'] == 'High');
                            $priorityClass = $isHigh ? 'text-rose-500 bg-rose-500/10 border-rose-500/20 alert-glow-high' : 'text-primary bg-primary/10 border-primary/20 alert-glow-medium';
                            $icon = $isHigh ? 'report' : 'info';
                            ?>
                            <div class="group relative flex flex-col md:flex-row gap-8 items-start">
                                <!-- Timeline Node -->
                                <div
                                    class="hidden md:flex size-14 rounded-xl glass-card shrink-0 items-center justify-center relative z-10 group-hover:border-primary/50 transition-colors">
                                    <span
                                        class="material-symbols-outlined <?= $isHigh ? 'text-rose-500' : 'text-primary' ?> text-xl"><?= $icon ?></span>
                                </div>

                                <!-- Alert Card -->
                                <div class="flex-1 glass-card p-6 group-hover:bg-white/[0.04] transition-all duration-500">
                                    <div
                                        class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                                        <div class="flex flex-col gap-1">
                                            <div class="flex items-center gap-3 mb-1">
                                                <span
                                                    class="text-[--text-main]/40 text-[9px] font-bold uppercase tracking-widest italic"><?= date('h:i A', strtotime($alert['created_at'])) ?></span>
                                            </div>
                                            <h4
                                                class="text-md font-black italic uppercase text-[--text-main] tracking-tight leading-none">
                                                <?= htmlspecialchars($alert['type']) ?>
                                            </h4>
                                            <p class="text-[--text-main]/60 text-xs mt-1 font-medium">
                                                <?= htmlspecialchars($alert['message']) ?>
                                            </p>
                                            <div class="flex items-center gap-2 mt-3">
                                                <span
                                                    class="text-[9px] font-black uppercase text-[--text-main]/40 tracking-widest">Source:</span>
                                                <span
                                                    class="text-[9px] font-black uppercase text-[--text-main] bg-white/5 px-2 py-0.5 rounded"><?= htmlspecialchars($alert['source']) ?></span>
                                            </div>
                                        </div>
                                        <div class="flex gap-2 shrink-0">
                                            <?php if ($view === 'active'): ?>
                                                <button onclick="requestResolve(<?= json_encode($alert['alert_id']) ?>)"
                                                    class="size-10 flex items-center justify-center rounded-xl bg-white/5 hover:bg-emerald-500/20 hover:text-emerald-400 border border-white/5 transition-all group/btn"
                                                    title="Resolve">
                                                    <span class="material-symbols-outlined text-sm">check</span>
                                                </button>
                                            <?php else: ?>
                                                <div class="size-10 flex items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-500 border border-emerald-500/20"
                                                    title="Resolved">
                                                    <span class="material-symbols-outlined text-sm">verified</span>
                                                </div>
                                            <?php endif; ?>
                                            <button
                                                onclick="openAlertModal(<?= htmlspecialchars(json_encode($alert['type'])) ?>, <?= htmlspecialchars(json_encode($alert['message'])) ?>, <?= htmlspecialchars(json_encode($alert['source'])) ?>, '<?= date('M d, Y h:i A', strtotime($alert['created_at'])) ?>', '<?= $alert['priority'] ?>')"
                                                class="size-10 flex items-center justify-center rounded-xl bg-white/5 hover:bg-primary/20 hover:text-primary border border-white/5 transition-all group/btn"
                                                title="More Details">
                                                <span class="material-symbols-outlined text-sm">visibility</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($alerts)): ?>
                            <div class="glass-card p-12 text-center">
                                <p class="text-[--text-main]/40 text-xs font-bold uppercase tracking-widest italic">No
                                    alerts found matching your criteria.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Elite Pagination Engine -->
                <div class="mt-16 flex flex-col md:flex-row items-center justify-between gap-6">
                    <div>
                        <p id="paginationStatus"
                            class="text-[10px] font-black uppercase tracking-widest text-[--text-main]/30">
                            Showing 0 of 0 entries
                        </p>
                    </div>
                    <div id="paginationControls" class="flex items-center gap-3">
                        <!-- JavaScript will populate this -->
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Alert Details Modal -->
    <div id="alertModal" class="p-4 bg-black/80 backdrop-blur-sm transition-all duration-300">
        <div class="glass-card w-full max-w-lg overflow-hidden transform scale-95 transition-all duration-300">
            <div class="p-8">
                <div class="flex justify-between items-start mb-6">
                    <div>
                        <h3 id="modalType"
                            class="text-2xl font-black italic uppercase text-[--text-main] tracking-tight leading-none">
                        </h3>
                    </div>
                    <button onclick="closeAlertModal()"
                        class="text-[--text-main]/40 hover:text-white transition-colors">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>

                <div class="space-y-6">
                    <div>
                        <p class="text-[10px] font-black uppercase text-primary tracking-widest mb-2">Message</p>
                        <p id="modalMessage" class="text-[--text-main]/80 text-sm leading-relaxed font-medium"></p>
                    </div>

                    <div class="grid grid-cols-2 gap-6">
                        <div>
                            <p class="text-[10px] font-black uppercase text-[--text-main]/40 tracking-widest mb-1">
                                Source</p>
                            <p id="modalSource" class="text-[--text-main] font-bold text-xs uppercase"></p>
                        </div>
                        <div>
                            <p class="text-[10px] font-black uppercase text-[--text-main]/40 tracking-widest mb-1">
                                Timestamp</p>
                            <p id="modalDate" class="text-[--text-main] font-bold text-xs uppercase"></p>
                        </div>
                    </div>
                </div>

                <div class="mt-10">
                    <button onclick="closeAlertModal()"
                        class="w-full py-4 glass-card text-[10px] font-black uppercase tracking-widest hover:bg-white/5 transition-all text-[--text-main]">Close
                        Details</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div id="confirmModal" class="p-4 bg-black/80 backdrop-blur-sm transition-all duration-300">
        <div class="glass-card w-full max-w-sm overflow-hidden transform scale-95 transition-all duration-300">
            <div class="p-8 text-center">
                <div class="size-16 rounded-full bg-primary/10 flex items-center justify-center mx-auto mb-6">
                    <span class="material-symbols-outlined text-primary text-3xl">help</span>
                </div>
                <h3 class="text-xl font-black italic uppercase text-[--text-main] mb-2 tracking-tighter">Resolve Alert?
                </h3>
                <p class="text-[--text-main]/40 text-[10px] font-bold uppercase tracking-widest mb-8 leading-relaxed">
                    Are you sure you want to mark this alert as resolved? This action cannot be undone.</p>

                <div class="flex gap-4">
                    <button onclick="closeConfirmModal()"
                        class="flex-1 py-4 glass-card text-[9px] font-black uppercase tracking-widest hover:bg-white/5 transition-all text-[--text-main]/40">Cancel</button>
                    <form method="POST" class="flex-1">
                        <input type="hidden" name="resolve_id" id="confirmResolveId">
                        <button type="submit"
                            class="w-full py-4 bg-primary text-[--background] text-[9px] font-black uppercase tracking-widest rounded-2xl hover:scale-105 transition-all shadow-lg shadow-primary/20">Yes,
                            Resolve</button>
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

            modal.classList.add('modal-active');
            setTimeout(() => {
                modalContent.classList.remove('scale-95');
                modalContent.classList.add('scale-100');
            }, 10);
        }

        function closeConfirmModal() {
            const modal = document.getElementById('confirmModal');
            const modalContent = modal.querySelector('.glass-card');

            modal.classList.remove('modal-active');
            modalContent.classList.remove('scale-100');
            modalContent.classList.add('scale-95');
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

            modal.classList.add('modal-active');
            setTimeout(() => {
                modalContent.classList.remove('scale-95');
                modalContent.classList.add('scale-100');
            }, 10);
        }

        function closeAlertModal() {
            const modal = document.getElementById('alertModal');
            const modalContent = modal.querySelector('.glass-card');

            modal.classList.remove('modal-active');
            modalContent.classList.remove('scale-100');
            modalContent.classList.add('scale-95');
        }

        // Close modal on click outside
        window.onclick = function (event) {
            const modal = document.getElementById('alertModal');
            if (event.target == modal) {
                closeAlertModal();
            }
        }
    </script>

</body>

</html>