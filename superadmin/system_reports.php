<?php
session_start();
require_once '../db.php';

// Security Check: Only Superadmin can access
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'superadmin') {
    header("Location: ../login.php");
    exit;
}

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

$page_title = "System Reports";
$active_page = "reports";

// Get Filter Inputs
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$tenant_filter = $_GET['tenant_id'] ?? 'all';
$active_tab = $_GET['active_tab'] ?? 'detailedTab';

// --- NEW FILTER INPUTS ---
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? 'all';
$pay_status_filter = $_GET['pay_status'] ?? 'all';

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
$real_data = $stmtReg->fetchAll(PDO::FETCH_ASSOC);

// Fill gaps in registration data to show a continuous line
$registration_data = [];
$start_ts = strtotime($date_from);
$end_ts = strtotime($date_to);
$current_ts = $start_ts;

// Create a lookup map
$data_map = [];
foreach ($real_data as $rd) {
    $data_map[$rd['reg_date']] = $rd['count'];
}

while ($current_ts <= $end_ts) {
    $date_str = date('Y-m-d', $current_ts);
    $registration_data[] = [
        'reg_date' => $date_str,
        'count' => $data_map[$date_str] ?? 0
    ];
    $current_ts = strtotime("+1 day", $current_ts);
}

// 3. Usage Statistics
// Total Users (Only from Approved/Active Tenants)
$total_users_query = "SELECT COUNT(DISTINCT u.user_id) FROM users u 
                      JOIN user_roles ur ON u.user_id = ur.user_id 
                      JOIN gyms g ON ur.gym_id = g.gym_id 
                      WHERE g.status = 'Active'";
if ($tenant_filter !== 'all') {
    $total_users_query .= " AND g.gym_id = :tid";
}
$stmtTotal = $pdo->prepare($total_users_query);
$stmtTotal->execute($tenant_filter !== 'all' ? ['tid' => $tenant_filter] : []);
$total_users = number_format($stmtTotal->fetchColumn());

// Avg Daily Logins
$login_query = "SELECT COUNT(*) FROM audit_logs WHERE action_type = 'Login' AND created_at BETWEEN :start AND :end";
if ($tenant_filter !== 'all') {
    $login_query .= " AND gym_id = :tid";
}
$stmtLogins = $pdo->prepare($login_query);
$login_params = $date_params;
if ($tenant_filter !== 'all') {
    $login_params['tid'] = $tenant_filter;
}
$stmtLogins->execute($login_params);
$total_logins = $stmtLogins->fetchColumn();
$days_diff = (strtotime($date_to) - strtotime($date_from)) / 86400;
$days = max(1, round($days_diff) + 1);
$avg_daily_logins = number_format($total_logins / $days, 1);

// Peak Usage Hour
$peak_query = "SELECT HOUR(created_at) as hr, COUNT(*) as cnt FROM audit_logs WHERE created_at BETWEEN :start AND :end";
if ($tenant_filter !== 'all') {
    $peak_query .= " AND gym_id = :tid";
}
$peak_query .= " GROUP BY hr ORDER BY cnt DESC LIMIT 1";
$stmtPeak = $pdo->prepare($peak_query);
$stmtPeak->execute($login_params);
$peak_row = $stmtPeak->fetch(PDO::FETCH_ASSOC);
$peak_hour = $peak_row ? date('h:00 A', strtotime($peak_row['hr'] . ':00')) : 'N/A';

// 4. Multi-Report Selection Logic
$report_type = $_GET['report_type'] ?? 'tenant_activity';
$report_data = [];

$report_titles = [
    'tenant_activity' => ['title' => 'Tenant Activity Report', 'desc' => 'Site-wide engagement and member volume'],
    'gym_apps' => ['title' => 'Gym Applications Report', 'desc' => 'Pending and processed ownership requests'],
    'client_subs' => ['title' => 'Client Subscriptions Report', 'desc' => 'Revenue and validity tracking for gym partners']
];

$curr_report = $report_titles[$report_type] ?? $report_titles['tenant_activity'];

switch ($report_type) {
    case 'gym_apps':
        $sql = "SELECT a.*, u.first_name, u.last_name, r.first_name as rev_f, r.last_name as rev_l 
                FROM gym_owner_applications a 
                JOIN users u ON a.user_id = u.user_id 
                LEFT JOIN users r ON a.reviewed_by = r.user_id
                WHERE a.submitted_at BETWEEN :start AND :end";
        $params = $date_params;
        if ($status_filter !== 'all') {
            $sql .= " AND a.application_status = :status";
            $params['status'] = $status_filter;
        }
        if (!empty($search)) {
            $sql .= " AND (a.gym_name LIKE :search OR a.business_name LIKE :search OR u.first_name LIKE :search OR u.last_name LIKE :search)";
            $params['search'] = "%$search%";
        }
        $sql .= " ORDER BY a.submitted_at DESC";
        $stmt = $pdo->prepare($sql);
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
        $params = $date_params;
        if ($tenant_filter !== 'all') {
            $sql .= " AND cs.gym_id = :tid";
            $params['tid'] = $tenant_filter;
        }
        if ($status_filter !== 'all') {
            $sql .= " AND cs.subscription_status = :status";
            $params['status'] = $status_filter;
        }
        if ($pay_status_filter !== 'all') {
            $sql .= " AND cs.payment_status = :pay_status";
            $params['pay_status'] = $pay_status_filter;
        }
        if (!empty($search)) {
            $sql .= " AND (g.gym_name LIKE :search OR u.first_name LIKE :search OR u.last_name LIKE :search OR p.plan_name LIKE :search)";
            $params['search'] = "%$search%";
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;

    default: // tenant_activity
        $where = ["1=1"];
        $params = $date_params;
        if ($tenant_filter !== 'all') {
            $where[] = "g.gym_id = :tid";
            $params['tid'] = $tenant_filter;
        }
        if ($status_filter !== 'all') {
            $where[] = "g.status = :status";
            $params['status'] = $status_filter;
        }
        if (!empty($search)) {
            $where[] = "(g.gym_name LIKE :search OR g.tenant_code LIKE :search)";
            $params['search'] = "%$search%";
        }

        $sql = "SELECT g.gym_name, g.tenant_code, g.status, g.created_at as joined_date,
                 COUNT(DISTINCT m.member_id) as member_count,
                 COUNT(DISTINCT al.audit_log_id) as activity_count
                 FROM gyms g
                 LEFT JOIN members m ON g.gym_id = m.gym_id
                 LEFT JOIN audit_logs al ON g.gym_id = al.gym_id AND al.created_at BETWEEN :start AND :end
                 WHERE " . implode(" AND ", $where) . "
                 GROUP BY g.gym_id ORDER BY activity_count DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;
}

?>
<!DOCTYPE html>
<html class="dark" lang="en">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title><?= $page_title ?> | Horizon System</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap"
        rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;700&display=swap" rel="stylesheet" />
    <link
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200"
        rel="stylesheet" />
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "var(--primary)",
                        "background": "var(--background)",
                        "highlight": "var(--highlight)",
                        "text-main": "var(--text-main)",
                        "surface-dark": "#14121a",
                        "border-subtle": "rgba(255,255,255,0.05)"
                    }
                }
            }
        }
    </script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
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

            /* Glassmorphism Engine */
            --card-blur: 20px;
            --card-bg:
                <?= ($configs['auto_card_theme'] ?? '1') === '1' ? 'rgba(' . hexToRgb($configs['theme_color'] ?? '#8c2bee') . ', 0.05)' : ($configs['card_color'] ?? '#141216') ?>
            ;
        }

        body {
            font-family: '<?= $configs['font_family'] ?? 'Lexend' ?>', sans-serif;
            background-color: var(--background);
            color: var(--text-main);
            color-scheme: dark;
        }

        .glass-card {
            background: var(--card-bg);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 24px;
            backdrop-filter: blur(var(--card-blur));
            box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.5);
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
            flex: 1;
            min-width: 0;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Invisible Scroll System (Global Reset) */
        *::-webkit-scrollbar {
            display: none !important;
        }

        * {
            -ms-overflow-style: none !important;
            scrollbar-width: none !important;
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
        }

        @media (max-width: 1023px) {
            .active-nav::after {
                display: none;
            }
        }

        .tab-btn {
            position: relative;
            transition: all 0.3s ease;
            color: var(--text-main);
            cursor: pointer;
            opacity: 0.4;
        }

        .tab-btn:hover {
            opacity: 0.7;
        }

        .tab-btn.active {
            color: var(--primary);
            opacity: 1;
        }

        .tab-indicator {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 2px;
            background-color: var(--primary);
            border-radius: 9999px;
            transition: all 0.3s ease;
            opacity: 0;
            pointer-events: none;
        }

        .tab-btn.active .tab-indicator {
            opacity: 1;
            transform: translateY(-2px);
        }

        .premium-select-container {
            position: relative;
        }

        .premium-select {
            background: var(--card-bg);
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .premium-select:hover,
        .premium-select:focus {
            border-color: var(--primary);
            background: rgba(var(--primary-rgb), 0.05);
        }

        .premium-select option, .input-field option, select option {
            background-color: #14121a;
            color: white;
            padding: 10px;
        }

        .input-field, select {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 0 16px;
            height: 42px;
            color: white;
            font-size: 13px;
            transition: all 0.2s;
            backdrop-filter: blur(10px);
            appearance: none;
            color-scheme: dark;
        }

        .input-field:hover {
            border-color: rgba(255, 255, 255, 0.2);
            background: rgba(255, 255, 255, 0.08);
        }

        .input-field:focus {
            border-color: var(--primary);
            outline: none;
            background: rgba(var(--primary-rgb), 0.05);
            box-shadow: 0 0 20px rgba(var(--primary-rgb), 0.1);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
            animation: fadeIn 0.4s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Elite Pagination Component Styling */
        .pagination-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 32px;
            margin-top: 24px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 20px;
            backdrop-filter: blur(20px);
        }

        .pagination-btn {
            padding: 8px 16px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: var(--text-main);
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            transition: all 0.2s ease;
            cursor: pointer;
        }

        .pagination-btn:hover:not(:disabled) {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        .pagination-btn:disabled {
            opacity: 0.3;
            cursor: not-allowed;
        }

        .pagination-btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .pagination-status {
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            color: var(--text-main);
            opacity: 0.5;
        }
    </style>
    <script>
        function initPagination(tableId, paginationContainerId, itemsPerPage = 10) {
            const table = document.querySelector(`#detailedTab table`);
            if (!table) return;

            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr:not(.no-report)'));
            const totalRows = rows.length;
            const container = document.getElementById(paginationContainerId);
            const statusLabel = document.getElementById(`paginationStatus-detailed`);
            const controls = document.getElementById(`paginationControls-detailed`);

            if (!container || !statusLabel || !controls) return;

            // If rows fits in single page, hide pagination
            if (totalRows <= itemsPerPage) {
                container.classList.add('hidden');
                rows.forEach(r => r.style.display = '');
                return;
            }

            container.classList.remove('hidden');
            let currentPage = 1;
            const totalPages = Math.ceil(totalRows / itemsPerPage);

            function showPage(page) {
                if (page < 1 || page > totalPages) return;
                currentPage = page;
                const start = (currentPage - 1) * itemsPerPage;
                const end = start + itemsPerPage;

                rows.forEach((row, index) => {
                    row.style.display = (index >= start && index < end) ? '' : 'none';
                });

                // Update Status
                const showingEnd = Math.min(end, totalRows);
                statusLabel.textContent = `Showing ${start + 1} to ${showingEnd} of ${totalRows} entries`;

                renderControls();
            }

            function renderControls() {
                controls.innerHTML = '';

                // Prev Button
                const prevBtn = document.createElement('button');
                prevBtn.className = `pagination-btn ${currentPage === 1 ? 'disabled' : ''}`;
                prevBtn.disabled = currentPage === 1;
                prevBtn.textContent = 'Prev';
                prevBtn.onclick = () => showPage(currentPage - 1);
                controls.appendChild(prevBtn);

                // Page Numbers
                for (let i = 1; i <= totalPages; i++) {
                    const pageBtn = document.createElement('button');
                    pageBtn.className = `pagination-btn ${currentPage === i ? 'active' : ''}`;
                    pageBtn.textContent = i;
                    pageBtn.onclick = () => showPage(i);
                    controls.appendChild(pageBtn);
                }

                // Next Button
                const nextBtn = document.createElement('button');
                nextBtn.className = `pagination-btn ${currentPage === totalPages ? 'disabled' : ''}`;
                nextBtn.disabled = currentPage === totalPages;
                nextBtn.textContent = 'Next';
                nextBtn.onclick = () => showPage(currentPage + 1);
                controls.appendChild(nextBtn);
            }

            showPage(1);
        }

        document.addEventListener('DOMContentLoaded', () => {
            if (typeof updateHeaderClock === 'function') updateHeaderClock();
            initPagination('detailedTable', 'pagination-detailed', 10);
        });

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

    <?php include '../includes/superadmin_sidebar.php'; ?>

    <div class="main-content flex-1 flex flex-col min-w-0 overflow-y-auto">
        <main class="flex-1 p-6 md:p-10 max-w-[1400px] w-full mx-auto">
            <header class="mb-12 flex flex-row justify-between items-end gap-6">
                <div>
                    <h2 class="text-3xl font-black italic uppercase tracking-tighter leading-none">
                        <span class="text-[--text-main] opacity-80">SYSTEM</span>
                        <span class="text-primary">REPORTS</span>
                    </h2>
                    <p class="text-[--text-main] text-[10px] font-bold uppercase tracking-widest mt-2 px-1 opacity-80">
                        Analytical Insights & Performance</p>
                </div>
                <div class="flex items-end gap-8 text-right shrink-0">
                    <div class="flex flex-col items-end">
                        <p id="headerClock"
                            class="text-[--text-main] font-black italic text-2xl leading-none tracking-tighter uppercase transition-colors cursor-default">
                            00:00:00 AM</p>
                        <p class="text-primary text-[10px] font-black uppercase tracking-[0.2em] leading-none mt-2">
                            <?= date('l, M d, Y') ?></p>
                    </div>
                </div>
            </header>


            <!-- Tab Navigation -->
            <div class="flex gap-8 border-b border-white/5 mb-8 px-2">
                <button onclick="switchTab('detailedTab')"
                    class="tab-btn pb-4 text-xs font-black uppercase tracking-widest transition-all relative group <?= ($active_tab == 'detailedTab') ? 'active' : '' ?>">
                    Detailed Reports
                    <div class="tab-indicator"></div>
                </button>
                <button onclick="switchTab('overviewTab')"
                    class="tab-btn pb-4 text-xs font-black uppercase tracking-widest transition-all relative group <?= ($active_tab == 'overviewTab') ? 'active' : '' ?>">
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
                    'client_subs' => ['title' => 'Client Subscription Report', 'desc' => 'Tracking gym owner website plans and payments']
                ];
                $curr_report = $report_titles[$report_type] ?? $report_titles['tenant_activity'];
                ?>
                <div class="glass-card overflow-hidden">
                    <div
                        class="px-8 py-6 border-b border-white/5 flex flex-wrap justify-between items-center gap-4 bg-white/[0.01]">
                        <div>
                            <h3 class="text-sm font-black italic uppercase tracking-widest text-white leading-none">
                                <?= $curr_report['title'] ?></h3>
                            <p class="text-[9px] text-gray-500 font-bold uppercase tracking-widest mt-1">
                                <?= $curr_report['desc'] ?></p>
                        </div>
                        <div class="flex items-center gap-3">
                            <form method="GET" id="reportTypeForm" class="flex items-center report-form">
                                <!-- Preserve other filters -->
                                <input type="hidden" name="date_from" value="<?= $date_from ?>">
                                <input type="hidden" name="date_to" value="<?= $date_to ?>">
                                <input type="hidden" name="tenant_id" value="<?= $tenant_filter ?>">
                                <input type="hidden" name="active_tab" class="active-tab-input"
                                    value="<?= $active_tab ?>">
                                <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                                <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>">
                                <input type="hidden" name="pay_status" value="<?= htmlspecialchars($pay_status_filter) ?>">

                                <div class="relative group premium-select-container">
                                    <select name="report_type" onchange="this.form.submit()"
                                        class="input-field appearance-none rounded-xl px-6 py-0 text-[10px] font-black uppercase tracking-[0.1em] text-white focus:outline-none cursor-pointer pr-10 min-w-[240px]">
                                        <?php foreach ($report_titles as $key => $data): ?>
                                            <option value="<?= $key ?>" <?= $report_type == $key ? 'selected' : '' ?>>
                                                <?= $data['title'] ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span
                                        class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-primary text-base pointer-events-none group-hover:scale-110 transition-transform">expand_more</span>
                                </div>
                            </form>
                             <a href="system_reports.php?report_type=<?= $report_type ?>&active_tab=detailedTab" 
                                class="size-10 rounded-xl bg-white/[0.02] border border-white/5 flex items-center justify-center text-primary hover:bg-white/5 transition-all shadow-lg group" title="Reset All Filters">
                                <span class="material-symbols-outlined text-xl transition-transform group-hover:rotate-180 duration-500">refresh</span>
                             </a>
                            <button onclick="exportReportToPDF(true)"
                                class="px-5 py-2.5 rounded-xl bg-white/5 border border-white/5 text-[10px] font-black uppercase tracking-widest text-[--text-main]/60 hover:text-white hover:bg-white/10 transition-all flex items-center gap-2">
                                <span class="material-symbols-outlined text-sm">visibility</span>
                                Preview
                            </button>
                            <button onclick="exportReportToPDF(false)"
                                class="px-5 py-2.5 rounded-xl bg-primary text-white text-[10px] font-black uppercase tracking-widest hover:scale-105 transition-transform active:scale-95 shadow-lg shadow-primary/20 flex items-center gap-2">
                                <span class="material-symbols-outlined text-sm">picture_as_pdf</span>
                                Export PDF
                            </button>
                        </div>
                    </div>

                    <div class="px-8 py-4 bg-white/[0.02] border-b border-white/5">
                        <form method="GET" class="flex flex-wrap items-center gap-4">
                            <!-- Consolidated Filters -->
                            <input type="hidden" name="active_tab" value="detailedTab">
                            <input type="hidden" name="report_type" value="<?= $report_type ?>">

                            <!-- Date Range -->
                            <div class="flex gap-2 shrink-0">
                                <input type="date" name="date_from" value="<?= $date_from ?>" onchange="this.form.submit()" title="From Date" class="bg-white/5 border border-white/10 rounded-xl py-3.5 px-4 text-xs font-black outline-none text-[--text-main] hover:border-white/20 transition-all">
                                <input type="date" name="date_to" value="<?= $date_to ?>" onchange="this.form.submit()" title="To Date" class="bg-white/5 border border-white/10 rounded-xl py-3.5 px-4 text-xs font-black outline-none text-[--text-main] hover:border-white/20 transition-all">
                            </div>

                            <!-- Tenant Selector -->
                            <div class="w-[220px] relative group shrink-0">
                                <select name="tenant_id" onchange="this.form.submit()" class="w-full bg-white/5 border border-white/10 rounded-xl py-3.5 pl-4 pr-10 text-xs font-black outline-none text-[--text-main] appearance-none cursor-pointer hover:border-white/20 transition-all">
                                    <option value="all">All Tenants</option>
                                    <?php foreach ($tenants_list as $gt): ?>
                                        <option value="<?= $gt['gym_id'] ?>" <?= $tenant_filter == $gt['gym_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($gt['gym_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-sm text-primary/60 pointer-events-none transition-transform group-hover:scale-110">expand_more</span>
                            </div>

                            <div class="h-8 w-px bg-white/5 mx-2 shrink-0"></div>

                            <!-- Search -->
                            <div class="flex-1 min-w-[200px] relative group">
                                <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-base text-primary/50 transition-transform group-hover:scale-110">search</span>
                                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search Table Body..." 
                                       onchange="this.form.submit()" 
                                       class="w-full bg-white/5 border border-white/10 rounded-xl py-3.5 pl-12 pr-4 text-xs font-black transition-all focus:border-primary outline-none text-[--text-main]">
                            </div>

                            <!-- Contextual Status -->
                            <?php if ($report_type === 'gym_apps'): ?>
                                <div class="w-[200px] relative group shrink-0">
                                    <select name="status" onchange="this.form.submit()" class="w-full bg-white/5 border border-white/10 rounded-xl py-3.5 pl-4 pr-10 text-xs font-black outline-none text-[--text-main] appearance-none cursor-pointer hover:border-white/20 transition-all">
                                        <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Apps Status</option>
                                        <option value="Pending" <?= $status_filter === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                        <option value="Approved" <?= $status_filter === 'Approved' ? 'selected' : '' ?>>Approved</option>
                                        <option value="Rejected" <?= $status_filter === 'Rejected' ? 'selected' : '' ?>>Rejected</option>
                                    </select>
                                    <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-sm text-[--text-main] opacity-40 pointer-events-none">expand_more</span>
                                </div>
                            <?php elseif ($report_type === 'client_subs'): ?>
                                <div class="w-[180px] relative group shrink-0">
                                    <select name="status" onchange="this.form.submit()" class="w-full bg-white/5 border border-white/10 rounded-xl py-3.5 pl-4 pr-10 text-xs font-black outline-none text-[--text-main] appearance-none cursor-pointer hover:border-white/20 transition-all">
                                        <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Sub Status</option>
                                        <option value="Active" <?= $status_filter === 'Active' ? 'selected' : '' ?>>Active</option>
                                        <option value="Inactive" <?= $status_filter === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                                        <option value="Expired" <?= $status_filter === 'Expired' ? 'selected' : '' ?>>Expired</option>
                                    </select>
                                    <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-sm text-[--text-main] opacity-40 pointer-events-none">expand_more</span>
                                </div>
                                <div class="w-[180px] relative group shrink-0">
                                    <select name="pay_status" onchange="this.form.submit()" class="w-full bg-white/5 border border-white/10 rounded-xl py-3.5 pl-4 pr-10 text-xs font-black outline-none text-[--text-main] appearance-none cursor-pointer hover:border-white/20 transition-all">
                                        <option value="all" <?= $pay_status_filter === 'all' ? 'selected' : '' ?>>All Pay Status</option>
                                        <option value="Paid" <?= $pay_status_filter === 'Paid' ? 'selected' : '' ?>>Paid</option>
                                        <option value="Pending" <?= $pay_status_filter === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                        <option value="Rejected" <?= $pay_status_filter === 'Rejected' ? 'selected' : '' ?>>Rejected</option>
                                    </select>
                                    <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-sm text-[--text-main] opacity-40 pointer-events-none">expand_more</span>
                                </div>
                            <?php else: // tenant_activity ?>
                                <div class="w-[200px] relative group shrink-0">
                                    <select name="status" onchange="this.form.submit()" class="w-full bg-white/5 border border-white/10 rounded-xl py-3.5 pl-4 pr-10 text-xs font-black outline-none text-[--text-main] appearance-none cursor-pointer hover:border-white/20 transition-all">
                                        <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Gym Status</option>
                                        <option value="Active" <?= $status_filter === 'Active' ? 'selected' : '' ?>>Active</option>
                                        <option value="Inactive" <?= $status_filter === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                                    </select>
                                    <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-sm text-[--text-main] opacity-40 pointer-events-none">expand_more</span>
                                </div>
                            <?php endif; ?>
                        </form>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-white/5 text-[--text-main] opacity-60 text-[10px] font-black uppercase tracking-[0.25em] border-b border-white/5">
                                    <?php switch ($report_type):
                                        case 'gym_apps': ?>
                                            <th class="px-8 py-4">Gym Identity</th>
                                            <th class="px-8 py-4">Business Type</th>
                                            <th class="px-8 py-4">Submission Date</th>
                                            <th class="px-8 py-4 text-center">Status</th>
                                            <?php break;
                                        case 'client_subs': ?>
                                            <th class="px-8 py-4">Tenant Identity</th>
                                            <th class="px-8 py-4">Plan</th>
                                            <th class="px-8 py-4">Validity</th>
                                            <th class="px-8 py-4 text-center">Status</th>
                                            <th class="px-8 py-4 text-right">Payment</th>
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
                                    <tr>
                                        <td colspan="6"
                                            class="px-8 py-12 text-center text-xs text-gray-600 italic uppercase font-bold tracking-widest">
                                            No reports found for the selected period</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($report_data as $row): ?>
                                        <tr class="hover:bg-white/5 transition-all text-[--text-main]">
                                            <?php switch ($report_type):
                                                case 'gym_apps': ?>
                                                    <td class="px-8 py-5">
                                                        <p class="text-sm font-bold italic text-white leading-none mb-1">
                                                            <?= htmlspecialchars($row['gym_name']) ?></p>
                                                        <p class="text-[10px] opacity-40 uppercase tracking-widest font-black">
                                                            <?= htmlspecialchars($row['business_name']) ?></p>
                                                    </td>
                                                    <td class="px-8 py-5">
                                                        <p class="text-xs font-black uppercase tracking-widest opacity-60">
                                                            <?= htmlspecialchars(str_replace('_', ' ', $row['business_type'] ?? 'unknown')) ?></p>
                                                    </td>
                                                    <td class="px-8 py-5">
                                                        <p class="text-xs font-bold text-white mb-1">
                                                            <?= date('M d, Y', strtotime($row['submitted_at'])) ?></p>
                                                        <p class="text-[9px] opacity-40 font-black uppercase italic">
                                                            <?= date('h:i A', strtotime($row['submitted_at'])) ?></p>
                                                    </td>
                                                    <td class="px-8 py-5 text-center">
                                                        <?php
                                                        $s = $row['application_status'];
                                                        $sc = 'gray-500';
                                                        if (strtolower($s) == 'approved')
                                                            $sc = 'emerald-500';
                                                        elseif (strtolower($s) == 'pending')
                                                            $sc = 'amber-500';
                                                        elseif (strtolower($s) == 'rejected')
                                                            $sc = 'rose-500';
                                                        ?>
                                                        <span class="px-2.5 py-1 rounded-lg bg-<?= $sc ?>/10 border border-<?= $sc ?>/20 text-[9px] text-<?= $sc ?> font-black uppercase tracking-wider italic">
                                                            <?= htmlspecialchars(str_replace('_', ' ', $s)) ?>
                                                        </span>
                                                    </td>
                                                    <?php break;
                                                case 'client_subs': ?>
                                                    <td class="px-8 py-5">
                                                        <p class="text-sm font-bold italic text-white leading-none mb-1">
                                                            <?= htmlspecialchars($row['gym_name']) ?></p>
                                                        <p class="text-[10px] opacity-40 uppercase tracking-widest font-black">
                                                            <?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?></p>
                                                    </td>
                                                    <td class="px-8 py-5">
                                                        <p class="text-xs font-black text-primary uppercase tracking-widest">
                                                            <?= htmlspecialchars($row['plan_name']) ?></p>
                                                    </td>
                                                    <td class="px-8 py-5">
                                                        <p class="text-xs text-white font-bold mb-1">
                                                            <?= date('M d', strtotime($row['start_date'])) ?> -
                                                            <?= date('M d, Y', strtotime($row['end_date'])) ?></p>
                                                        <p class="text-[9px] opacity-40 font-black uppercase italic tracking-tighter">
                                                            Billing Cycle</p>
                                                    </td>
                                                    <td class="px-8 py-5 text-center">
                                                        <?php $sc = $row['subscription_status'] == 'Active' ? 'emerald-500' : 'amber-500'; ?>
                                                        <span class="px-2.5 py-1 rounded-lg bg-<?= $sc ?>/10 border border-<?= $sc ?>/20 text-[9px] text-<?= $sc ?> font-black uppercase tracking-wider italic"><?= htmlspecialchars(str_replace('_', ' ', $row['subscription_status'])) ?></span>
                                                    </td>
                                                    <td class="px-8 py-5 text-right">
                                                        <p class="text-xs font-black text-emerald-500 uppercase">
                                                            <?= htmlspecialchars($row['payment_status']) ?></p>
                                                    </td>
                                                    <?php break;
                                                default: // tenant_activity ?>
                                                    <td class="px-8 py-5">
                                                        <p class="text-sm font-bold italic text-white leading-none mb-1">
                                                            <?= htmlspecialchars($row['gym_name']) ?></p>
                                                        <p class="text-[10px] opacity-40 uppercase tracking-widest font-black">
                                                            <?= htmlspecialchars($row['tenant_code']) ?></p>
                                                    </td>
                                                    <td class="px-8 py-5 text-center">
                                                        <p class="text-sm font-black text-white">
                                                            <?= number_format($row['member_count']) ?></p>
                                                    </td>
                                                    <td class="px-8 py-5 text-center">
                                                        <p class="text-sm font-black text-white">
                                                            <?= number_format($row['activity_count']) ?></p>
                                                    </td>
                                                    <td class="px-8 py-5 text-center">
                                                        <?php $tc = $row['status'] == 'Active' ? 'emerald-500' : 'rose-500'; ?>
                                                        <span class="px-2.5 py-1 rounded-lg bg-<?= $tc ?>/10 border border-<?= $tc ?>/20 text-[9px] text-<?= $tc ?> font-black uppercase tracking-wider italic"><?= htmlspecialchars(str_replace('_', ' ', $row['status'])) ?></span>
                                                    </td>
                                                    <td class="px-8 py-5 text-right">
                                                        <p class="text-xs font-bold text-white uppercase opacity-60">
                                                            <?= date('M d, Y', strtotime($row['joined_date'])) ?></p>
                                                    </td>
                                            <?php endswitch; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Elite Pagination Container -->
                    <div id="pagination-detailed" class="pagination-container hidden">
                        <div class="pagination-status" id="paginationStatus-detailed">Showing 0 of 0 entries</div>
                        <div class="flex gap-2" id="paginationControls-detailed">
                            <!-- JS injected buttons -->
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab 2: Analytics Overview -->
            <div id="overviewTab" class="tab-content <?= ($active_tab == 'overviewTab') ? 'active' : '' ?>">
                <!-- Content Filter Bar -->
                <div class="glass-card mb-8 p-4 px-8 flex justify-between items-center bg-white/[0.01]">
                    <div class="flex items-center gap-4">
                        <span class="material-symbols-outlined text-primary/50 text-xl">tune</span>
                        <h4 class="text-[10px] font-black uppercase tracking-[0.2em] text-[--text-main]/60">Analytics Scoping</h4>
                    </div>
                    <form method="GET" class="flex items-center gap-4">
                        <input type="hidden" name="active_tab" value="overviewTab">
                        <input type="hidden" name="report_type" value="<?= $report_type ?>">
                        
                        <div class="flex gap-2">
                            <input type="date" name="date_from" value="<?= $date_from ?>" onchange="this.form.submit()" class="bg-white/5 border border-white/10 rounded-xl py-3 px-6 text-xs font-black outline-none text-[--text-main] hover:border-white/20 transition-all">
                            <input type="date" name="date_to" value="<?= $date_to ?>" onchange="this.form.submit()" class="bg-white/5 border border-white/10 rounded-xl py-3 px-6 text-xs font-black outline-none text-[--text-main] hover:border-white/20 transition-all">
                        </div>

                        <div class="w-[240px] relative group">
                            <select name="tenant_id" onchange="this.form.submit()" class="w-full bg-white/5 border border-white/10 rounded-xl py-3 pl-6 pr-10 text-xs font-black outline-none text-[--text-main] appearance-none cursor-pointer hover:border-white/20 transition-all">
                                <option value="all">All System Tenants</option>
                                <?php foreach ($tenants_list as $gt): ?>
                                    <option value="<?= $gt['gym_id'] ?>" <?= $tenant_filter == $gt['gym_id'] ? 'selected' : '' ?>><?= htmlspecialchars($gt['gym_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-base text-primary opacity-60 pointer-events-none transition-transform group-hover:scale-110">expand_more</span>
                        </div>
                    </form>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-10">
                    <!-- Primary Chart (2/3 Column) -->
                    <div class="lg:col-span-2 glass-card p-8">
                        <div class="flex justify-between items-center mb-8">
                            <div>
                                <h3 class="text-sm font-black italic uppercase tracking-widest text-white">User Registration Growth</h3>
                                <p class="text-[9px] text-[--text-main] opacity-50 font-bold uppercase mt-1 tracking-wider">Growth Trends Over Selected Period</p>
                            </div>
                        </div>
                        <div class="h-[300px] w-full">
                            <canvas id="registrationChart"></canvas>
                        </div>
                    </div>

                    <!-- Usage Statistics (1/3 Column) -->
                    <div class="glass-card p-8 flex flex-col">
                        <h3 class="text-sm font-black italic uppercase tracking-widest text-white mb-6">Usage Statistics</h3>
                        <div class="flex-1 space-y-6 flex flex-col justify-center">
                            <div class="p-6 rounded-2xl bg-white/[0.01] border border-white/5 relative overflow-hidden group hover:border-primary/20 hover:bg-white/[0.03] transition-all cursor-default">
                                <span class="material-symbols-outlined absolute right-6 top-1/2 -translate-y-1/2 text-5xl opacity-10 group-hover:scale-110 transition-transform text-white">groups</span>
                                <p class="text-[10px] font-black uppercase text-[--text-main] opacity-40 mb-1 tracking-widest">Total System Users</p>
                                <h2 class="text-xl font-black text-white italic tracking-tighter"><?= $total_users ?></h2>
                            </div>
                            <div class="p-6 rounded-2xl bg-white/[0.01] border border-white/5 relative overflow-hidden group hover:border-emerald-500/20 hover:bg-white/[0.03] transition-all cursor-default">
                                <span class="material-symbols-outlined absolute right-6 top-1/2 -translate-y-1/2 text-5xl opacity-10 group-hover:scale-110 transition-transform text-emerald-500">login</span>
                                <p class="text-[10px] font-black uppercase text-[--text-main] opacity-40 mb-1 tracking-widest">Avg. Daily Logins</p>
                                <h2 class="text-xl font-black text-emerald-500 italic tracking-tighter"><?= $avg_daily_logins ?></h2>
                            </div>
                            <div class="p-6 rounded-2xl bg-white/[0.01] border border-white/5 relative overflow-hidden group hover:border-primary/20 hover:bg-white/[0.03] transition-all cursor-default">
                                <span class="material-symbols-outlined absolute right-6 top-1/2 -translate-y-1/2 text-5xl opacity-10 group-hover:scale-110 transition-transform text-primary">schedule</span>
                                <p class="text-[10px] font-black uppercase text-[--text-main] opacity-40 mb-1 tracking-widest">Peak Usage Hour</p>
                                <h2 class="text-xl font-black text-primary italic tracking-tighter"><?= $peak_hour ?></h2>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // 1. User Registration Growth (Line-Area) - Elite Styling
        const regCtx = document.getElementById('registrationChart').getContext('2d');
        new Chart(regCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode(array_map(function ($d) { return date('M d', strtotime($d['reg_date'])); }, $registration_data)) ?>,
                datasets: [{
                    label: 'Registrations',
                    data: <?= json_encode(array_map(function ($d) { return (int) $d['count']; }, $registration_data)) ?>,
                    borderColor: '<?= $configs['theme_color'] ?? '#8c2bee' ?>',
                    backgroundColor: 'rgba(<?= hexToRgb($configs['theme_color'] ?? '#8c2bee') ?>, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                    pointBackgroundColor: '<?= $configs['theme_color'] ?? '#8c2bee' ?>',
                    pointBorderColor: 'rgba(255,255,255,0.2)',
                    pointHitRadius: 10
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(255,255,255,0.03)', drawBorder: false },
                        border: { display: false },
                        ticks: { color: 'rgba(255,255,255,0.4)', font: { family: 'Lexend', size: 9, weight: '800' } }
                    },
                    x: {
                        grid: { display: false },
                        border: { display: false },
                        ticks: { color: 'rgba(255,255,255,0.4)', font: { family: 'Lexend', size: 9, weight: '800' } }
                    }
                }
            }
        });

        function exportReportToPDF(preview = false) {
            const element = document.getElementById('detailedTab');
            const reportTitle = "<?= $curr_report['title'] ?>";
            const tenantName = "Horizon System";
            const generatedAt = "<?= date('M d, Y h:i A') ?>";

            // Create a wrapper for formal PDF styling
            const wrapper = document.createElement('div');
            wrapper.style.padding = '50px';
            wrapper.style.color = '#000';
            wrapper.style.backgroundColor = '#fff';
            wrapper.style.fontFamily = "'Roboto Mono', monospace";

            // Formal Header (Strictly B&W)
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

            // Clone and SURGICALLY clean the content
            const contentClone = element.cloneNode(true);

            // 1. REMOVE UI ELEMENTS FIRST (while classes still exist)
            contentClone.querySelectorAll('button, form, span.material-symbols-outlined, header, .border-b, .px-8.py-6, .flex-wrap').forEach(el => el.remove());

            // 2. STRIP ALL CLASSES AND INLINE STYLES + FORCE 100% OPACITY BLACK TEXT
            // ALSO CLEAR THE ROOT CLONE ELEMENT STYLES
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
                el.style.setProperty('-webkit-font-smoothing', 'antialiased', 'important');
                el.style.setProperty('-moz-osx-font-smoothing', 'grayscale', 'important');
            });

            // 3. TRANSFORM TABLE INTO FORMAL GRID (Aggressive Black & White)
            const table = contentClone.querySelector('table');
            if (table) {
                table.style.setProperty('width', '100%', 'important');
                table.style.setProperty('border-collapse', 'collapse', 'important');
                table.style.setProperty('font-size', '11px', 'important');
                table.style.setProperty('color', '#000000ff', 'important');
                table.style.setProperty('border', '2px solid #000000ff', 'important');
                table.style.setProperty('font-family', "'Roboto Mono', monospace", 'important');
                table.style.setProperty('margin-top', '0', 'important');

                table.querySelectorAll('th').forEach(th => {
                    th.style.setProperty('background-color', '#858282ff', 'important');
                    th.style.setProperty('color', '#000000ff', 'important');
                    th.style.setProperty('border', '1px solid #000000ff', 'important');
                    th.style.setProperty('padding', '12px 10px', 'important');
                    th.style.setProperty('text-transform', 'uppercase', 'important');
                    th.style.setProperty('font-weight', 'bold', 'important');
                    th.style.setProperty('text-align', 'left', 'important');
                });

                table.querySelectorAll('td').forEach(td => {
                    td.style.setProperty('border', '1px solid #000000ff', 'important');
                    td.style.setProperty('padding', '10px 10px', 'important');
                    td.style.setProperty('color', '#000000ff', 'important');
                    td.style.setProperty('background-color', '#ffffffff', 'important'); // Strictly white cells
                    td.style.setProperty('vertical-align', 'top', 'important');

                    // Ensure all internal elements (p, span, div) are strictly black and bold
                    td.querySelectorAll('*').forEach(ch => {
                        ch.style.setProperty('color', '#000000ff', 'important');
                        ch.style.setProperty('font-size', '11px', 'important');
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
            footer.innerHTML = `
            <p style="margin: 0; font-weight: bold;">CONFIDENTIAL DOCUMENT - FOR INTERNAL USE ONLY</p>
            <p style="margin: 0;">&copy; ${new Date().getFullYear()} Horizon System. All Rights Reserved.</p>
        `;

            wrapper.innerHTML = header;
            wrapper.appendChild(contentClone);
            wrapper.appendChild(footer);

            const opt = {
                margin: [0.3, 0.3],
                filename: `${reportTitle.replace(/\s+/g, '_')}_${new Date().toISOString().split('T')[0]}.pdf`,
                image: { type: 'jpeg', quality: 1.0 },
                html2canvas: { scale: 3, backgroundColor: '#ffffff', useCORS: true, letterRendering: true },
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