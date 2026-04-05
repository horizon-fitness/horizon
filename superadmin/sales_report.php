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

// Fetch Branding Settings
$stmtGlobal = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE user_id = 0");
$global_configs = $stmtGlobal->fetchAll(PDO::FETCH_KEY_PAIR);

$stmtUser = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = ?");
$stmtUser->execute([$_SESSION['user_id']]);
$user_configs = $stmtUser->fetchAll(PDO::FETCH_KEY_PAIR);

$configs = array_merge($global_configs, $user_configs);

$page_title = "Sales Report";
$active_page = "sales_report";

// Get Filter Inputs (Default to current month)
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$active_tab = $_GET['active_tab'] ?? 'historyTab';

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

// 4. TRANSACTION HISTORY SUMMARY
$stmtHistory = $pdo->prepare("
    SELECT cs.*, g.gym_name, wp.plan_name, wp.price 
    FROM client_subscriptions cs
    JOIN gyms g ON cs.gym_id = g.gym_id
    JOIN website_plans wp ON cs.website_plan_id = wp.website_plan_id
    WHERE cs.payment_status = 'Paid' AND cs.created_at BETWEEN :start AND :end
    ORDER BY cs.created_at DESC
");
$stmtHistory->execute(['start' => $date_from . ' 00:00:00', 'end' => $date_to . ' 23:59:59']);
$transactions = $stmtHistory->fetchAll(PDO::FETCH_ASSOC);

// --- REAL TRANSACTION DATA SORTING ---
usort($transactions, function ($a, $b) {
    return strtotime($b['created_at']) <=> strtotime($a['created_at']);
});

// Recalculate totals for analytics using database data
$total_revenue = 0;
$temp_tenant_sales = [];
$temp_daily_sales = [];
$filtered_transactions = []; // New array for strictly filtered display

foreach ($transactions as $t) {
    if ($t['created_at'] >= $date_from . ' 00:00:00' && $t['created_at'] <= $date_to . ' 23:59:59') {
        $total_revenue += $t['price'];
        $filtered_transactions[] = $t; // Add to display list
        
        if (!isset($temp_tenant_sales[$t['gym_name']])) {
            $temp_tenant_sales[$t['gym_name']] = ['gym_name' => $t['gym_name'], 'total_revenue' => 0, 'transaction_count' => 0];
        }
        $temp_tenant_sales[$t['gym_name']]['total_revenue'] += $t['price'];
        $temp_tenant_sales[$t['gym_name']]['transaction_count']++;
        
        $d = date('Y-m-d', strtotime($t['created_at']));
        if (!isset($temp_daily_sales[$d])) {
            $temp_daily_sales[$d] = ['sale_date' => $d, 'daily_amount' => 0];
        }
        $temp_daily_sales[$d]['daily_amount'] += $t['price'];
    }
}

// --- ELITE CONTINUOUS GRAPH DATA ENGINE ---
$data_map = [];
foreach ($temp_daily_sales as $ds) {
    $data_map[$ds['sale_date']] = $ds['daily_amount'];
}

$start_ts = strtotime($date_from);
$end_ts = strtotime($date_to);
$current_ts = $start_ts;

$daily_sales = [];
while ($current_ts <= $end_ts) {
    $date_str = date('Y-m-d', $current_ts);
    $amount = $data_map[$date_str] ?? 0;
    
    $daily_sales[] = [
        'sale_date' => $date_str,
        'daily_amount' => $amount
    ];
    $current_ts = strtotime("+1 day", $current_ts);
}

// Update the main transactions array for the table display
$transactions = $filtered_transactions;

$tenant_sales = array_values($temp_tenant_sales);
usort($tenant_sales, function ($a, $b) {
    return $b['total_revenue'] <=> $a['total_revenue'];
});
// Daily sales already sorted and filled by the continuous engine above
?>
<!DOCTYPE html>
<html class="dark" lang="en">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title><?= $page_title ?> | Horizon System</title>
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
            theme: { extend: { colors: { "primary": "var(--primary)", "background": "var(--background)", "secondary": "var(--secondary)", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)" } } }
        }
    </script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        :root {
            color-scheme: dark;
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
            --secondary-rgb: 161, 161, 170;
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
            border: 1px solid var(--card-border, rgba(255, 255, 255, 0.05));
            border-radius: 24px;
            backdrop-filter: blur(var(--card-blur));
            box-shadow: var(--card-shadow, 0 10px 30px rgba(0, 0, 0, 0.2)), var(--card-glow, 0 0 0 transparent);
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

        /* Global Hidden Scrollbar - Sleek UI */
        *::-webkit-scrollbar {
            display: none !important;
        }

        * {
            -ms-overflow-style: none !important;
            /* IE and Edge */
            scrollbar-width: none !important;
            /* Firefox */
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
            /* Subtle feedback instead of color change */
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

        .tab-btn {
            position: relative;
            transition: all 0.3s ease;
            color: var(--text-main);
            opacity: 0.6;
            cursor: pointer;
            border: none;
            outline: none;
            background: none;
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

        /* Pagination Styles */
        .pagination-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 36px;
            min-width: 36px;
            padding: 0 12px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            color: var(--text-main);
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            transition: all 0.2s;
            cursor: pointer;
        }

        .pagination-btn:hover:not(:disabled) {
            background: rgba(255, 255, 255, 0.1);
            border-color: rgba(255, 255, 255, 0.1);
            transform: translateY(-1px);
        }

        .pagination-btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
            box-shadow: 0 4px 12px rgba(var(--primary-rgb), 0.3);
        }

        .pagination-btn:disabled {
            opacity: 0.3;
            cursor: not-allowed;
        }

        .input-field {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 12px 16px;
            color: white;
            font-size: 13px;
            transition: all 0.2s;
            backdrop-filter: blur(10px);
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
            wrapper.style.padding = '0';
            wrapper.style.color = '#000';
            wrapper.style.backgroundColor = '#fff';
            wrapper.style.fontFamily = "'Roboto Mono', monospace";
            wrapper.style.width = '650px'; // Expanded width for 0.75in margins

            const header = `
                <div style="padding: 0;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 10px;">
                        <div style="text-align: left;">
                            <h1 style="font-size: 24px; font-weight: 800; color: #000; margin: 0; text-transform: uppercase;">${tenantName}</h1>
                        </div>
                        <div style="text-align: right;">
                            <h2 style="font-size: 14px; font-weight: 700; color: #000; margin: 0; text-transform: uppercase;">${reportTitle}</h2>
                        </div>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; font-size: 9px; line-height: 1.4;">
                        <div style="text-align: left; color: #000;">
                            <p style="margin: 0;">Baliwag, Bulacan, Philippines, 3006</p>
                            <p style="margin: 0;">Phone: 0976-241-1986 | Email: horizonfitnesscorp@gmail.com</p>
                        </div>
                        <div style="text-align: right; color: #000;">
                            <p style="margin: 0;">Generated on: ${generatedAt}</p>
                            <p style="margin: 0; font-weight: bold;">OFFICIAL SYSTEM TRANSCRIPT</p>
                        </div>
                    </div>
                    <div style="border-bottom: 2px solid #000; margin-bottom: 25px;"></div>
                </div>
            `;

            const contentClone = element.cloneNode(true);
            contentClone.querySelectorAll('button, form, span.material-symbols-outlined, header, .border-b, .px-8.py-6, .flex-wrap, #historyPaginationUI').forEach(el => el.remove());

            // FORCE VISIBILITY & STYLING FOR PDF
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

                // CRITICAL: Ensure paginated rows appear
                if (el.tagName === 'TR') {
                    el.style.setProperty('display', 'table-row', 'important');
                }
            });

            const table = contentClone.querySelector('table');
            if (table) {
                table.style.setProperty('width', '100%', 'important');
                table.style.setProperty('border-collapse', 'collapse', 'important');
                table.style.setProperty('font-size', '10px', 'important');
                table.style.setProperty('color', '#000000', 'important');
                table.style.setProperty('border', '2px solid #000000', 'important');
                table.style.setProperty('font-family', "'Roboto Mono', monospace", 'important');

                table.querySelectorAll('th').forEach(th => {
                    th.style.setProperty('background-color', '#f3f4f6', 'important');
                    th.style.setProperty('border', '1px solid #000000', 'important');
                    th.style.setProperty('padding', '10px 8px', 'important');
                    th.style.setProperty('font-weight', 'bold', 'important');
                });

                table.querySelectorAll('td').forEach(td => {
                    td.style.setProperty('border', '1px solid #000000', 'important');
                    td.style.setProperty('padding', '8px 8px', 'important');
                    td.querySelectorAll('*').forEach(ch => {
                        ch.style.setProperty('font-size', '10px', 'important');
                    });
                });
            }

            const footer = document.createElement('div');
            footer.style.marginTop = '30px';
            footer.style.textAlign = 'center';
            footer.style.fontSize = '8px';
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
                margin: 0.75, // 0.75 inch margins as requested
                filename: `Sales_Report_${new Date().toISOString().split('T')[0]}.pdf`,
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
                    class="nav-text text-lg font-black italic uppercase tracking-tighter text-white">
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
                        <span class="text-[--text-main] opacity-80">SALES</span>
                        <span class="text-primary">REPORTS</span>
                    </h2>
                    <p class="text-[--text-main] text-[10px] font-bold uppercase tracking-widest mt-2 px-1 opacity-80">
                        Revenue & Transaction Tracking</p>
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

            <div class="glass-card mb-8 p-8">
                <form method="GET" class="flex flex-wrap items-end gap-6">
                    <input type="hidden" name="active_tab" class="active-tab-input" value="<?= $active_tab ?>">
                    <div class="space-y-2 flex-1 min-w-[240px]">
                        <p class="text-[10px] font-black uppercase tracking-widest text-[--text-main]/60 ml-1">Search
                            Transaction</p>
                        <div class="relative group">
                            <span
                                class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-sm text-[--text-main]/40 group-focus-within:text-primary transition-colors">search</span>
                            <input type="text" id="tableSearch" placeholder="Tenant name or ID..."
                                class="input-field w-full pl-11">
                        </div>
                    </div>
                    <div class="space-y-2">
                        <p class="text-[10px] font-black uppercase tracking-widest text-[--text-main]/60 ml-1">Start
                            Date</p>
                        <input type="date" name="date_from" value="<?= $date_from ?>" class="input-field">
                    </div>
                    <div class="space-y-2">
                        <p class="text-[10px] font-black uppercase tracking-widest text-[--text-main]/60 ml-1">End Date
                        </p>
                        <input type="date" name="date_to" value="<?= $date_to ?>" class="input-field">
                    </div>
                    <div class="flex items-center gap-4">
                        <button type="submit"
                            class="h-[46px] px-8 rounded-xl bg-primary text-white text-[10px] font-black uppercase tracking-widest hover:scale-105 transition-transform active:scale-95 shadow-lg shadow-primary/20">Update
                            Dates</button>
                        <a href="sales_report.php"
                            class="text-[10px] font-black uppercase tracking-widest text-[--text-main]/60 hover:text-white transition-colors">Clear</a>
                    </div>
                </form>
            </div>

            <!-- Tab Navigation -->
            <div class="flex gap-8 border-b border-white/5 mb-8 px-2">
                <button onclick="switchTab('historyTab')"
                    class="tab-btn pb-4 text-xs font-black uppercase tracking-widest transition-all relative group <?= ($active_tab == 'historyTab') ? 'active' : '' ?>">
                    Detailed History
                    <div class="tab-indicator"></div>
                </button>
                <button onclick="switchTab('overviewTab')"
                    class="tab-btn pb-4 text-xs font-black uppercase tracking-widest transition-all relative group <?= ($active_tab == 'overviewTab') ? 'active' : '' ?>">
                    Analytics Overview
                    <div class="tab-indicator"></div>
                </button>
            </div>

            <!-- Tab Content -->
            <div id="historyTab" class="tab-content <?= ($active_tab == 'historyTab') ? 'active' : '' ?>">
                <div class="glass-card overflow-hidden">
                    <div class="px-8 py-6 border-b border-white/5 bg-white/[0.01] flex justify-between items-center">
                        <div>
                            <h3 class="text-sm font-black italic uppercase tracking-widest text-primary">Transaction
                                History Summary</h3>
                            <p class="text-[9px] text-[--text-main]/40 font-bold uppercase tracking-widest mt-1">
                                Detailed logs of all successful payments</p>
                        </div>
                        <div class="flex items-center gap-3">
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
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead>
                                <tr
                                    class="bg-background/50 text-[--text-main]/60 text-[10px] font-black uppercase tracking-widest">
                                    <th class="px-8 py-4">Tenant / ID</th>
                                    <th class="px-8 py-4">Plan Type</th>
                                    <th class="px-8 py-4">Amount</th>
                                    <th class="px-8 py-4">Date</th>
                                    <th class="px-8 py-4 text-right">Status</th>
                                </tr>
                            </thead>
                            <tbody id="historyTableBody" class="divide-y divide-white/5">
                                <?php if (empty($transactions)): ?>
                                    <tr>
                                        <td colspan="5"
                                            class="px-8 py-8 text-center text-xs font-bold text-[--text-main]/40 italic uppercase">
                                            No recent transactions found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($transactions as $trx): ?>
                                        <tr class="hover:bg-white/[0.05] transition-all duration-300 group">
                                            <td class="px-8 py-5">
                                                <p
                                                    class="text-sm font-bold text-[--text-main] group-hover:text-white transition-colors">
                                                    <?= htmlspecialchars($trx['gym_name']) ?>
                                                </p>
                                                <p
                                                    class="text-[9px] text-[--text-main]/40 font-black uppercase italic tracking-widest">
                                                    ID: <?= htmlspecialchars($trx['client_subscription_id']) ?></p>
                                            </td>
                                            <td class="px-8 py-5 text-[10px] font-black text-[--text-main] uppercase italic">
                                                <?= htmlspecialchars($trx['plan_name']) ?>
                                            </td>
                                            <td class="px-8 py-5 text-sm font-black text-primary">
                                                ₱<?= number_format($trx['price'], 2) ?></td>
                                            <td class="px-8 py-5 text-[10px] text-[--text-main]/40 font-bold uppercase">
                                                <?= date('M d, Y', strtotime($trx['created_at'])) ?>
                                            </td>
                                            <td class="px-8 py-5 text-right">
                                                <span
                                                    class="px-3 py-1 rounded-full text-[9px] font-black uppercase italic bg-emerald-500/10 text-emerald-500 border border-emerald-500/20">Paid</span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Elite Pagination Footer -->
                    <div id="historyPaginationUI"
                        class="px-8 py-5 border-t border-white/5 flex items-center justify-between gap-4 hidden">
                        <p id="paginationStatus"
                            class="text-[9px] font-black uppercase tracking-widest text-[--text-main]/40">
                            Showing 1 to 10 of 45 entries
                        </p>
                        <div id="paginationButtons" class="flex items-center gap-2">
                            <!-- Buttons injected by JS -->
                        </div>
                    </div>
                </div>
            </div>

            <div id="overviewTab" class="tab-content <?= ($active_tab == 'overviewTab') ? 'active' : '' ?>">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">
                    <div class="glass-card p-8 relative overflow-hidden group">
                        <span
                            class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform">payments</span>
                        <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 tracking-widest mb-2">
                            Total Revenue</p>
                        <h2 class="text-3xl font-black text-[--text-main] italic mt-2">
                            ₱<?= number_format($total_revenue, 2) ?></h2>
                        <p class="text-emerald-500 text-[10px] font-black uppercase mt-2 italic">Captured Payments</p>
                    </div>
                    <div class="glass-card p-8 relative overflow-hidden group border-primary/20 bg-primary/5">
                        <span
                            class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-primary">receipt_long</span>
                        <p class="text-[10px] font-black uppercase text-primary/70 tracking-widest mb-2">Transactions
                        </p>
                        <h2 class="text-3xl font-black text-[--text-main] italic mt-2"><?= count($transactions) ?></h2>
                        <p class="text-primary text-[10px] font-black uppercase mt-2 italic">Success Rate 100%</p>
                    </div>
                    <div class="glass-card p-8 relative overflow-hidden group">
                        <span
                            class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform">star</span>
                        <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 tracking-widest mb-2">
                            Top Tenant</p>
                        <h2 class="text-2xl font-black text-[--text-main] italic mt-2">
                            <?= $tenant_sales[0]['gym_name'] ?? 'N/A' ?>
                        </h2>
                        <p class="text-amber-500 text-[10px] font-black uppercase mt-2 italic">Highest Performance</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-10">
                    <div class="lg:col-span-2 glass-card p-8">
                        <h3 class="text-sm font-black italic uppercase tracking-widest text-primary mb-8">Sales Performance Trend</h3>
                        <div class="h-[450px]">
                            <canvas id="salesTrendChart"></canvas>
                        </div>
                    </div>

                    <div class="glass-card p-8 flex flex-col">
                        <h3 class="text-sm font-black italic uppercase tracking-widest text-primary mb-6">Sales Per Tenant</h3>
                        <div class="space-y-4 overflow-y-auto no-scrollbar max-h-[500px]">
                            <?php if (empty($tenant_sales)): ?>
                                <p class="text-xs text-[--text-main]/40 italic font-bold text-center mt-10 uppercase">No
                                    sales recorded</p>
                            <?php else: ?>
                                <?php foreach ($tenant_sales as $ts): ?>
                                    <div
                                        class="p-4 rounded-2xl bg-white/[0.02] border border-white/5 flex justify-between items-center hover:bg-white/[0.05] transition-colors">
                                        <div>
                                            <p class="text-sm font-bold text-[--text-main]">
                                                <?= htmlspecialchars($ts['gym_name']) ?>
                                            </p>
                                            <p
                                                class="text-[9px] text-[--text-main]/40 font-black uppercase tracking-widest italic">
                                                <?= $ts['transaction_count'] ?> sales
                                            </p>
                                        </div>
                                        <p class="text-sm font-black text-primary italic">
                                            ₱<?= number_format($ts['total_revenue'], 0) ?></p>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>    const ctx = document.getElementById('salesTrendChart').getContext('2d');
    let salesChart;

    function initChart() {
        if (salesChart) salesChart.destroy();
        
        salesChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: [<?php foreach($daily_sales as $ds) echo "'" . date('M d', strtotime($ds['sale_date'])) . "',"; ?>],
                datasets: [{
                    label: 'Daily Revenue',
                    data: [<?php foreach($daily_sales as $ds) echo $ds['daily_amount'] . ","; ?>],
                    borderColor: '<?= $configs['theme_color'] ?? '#8c2bee' ?>',
                    backgroundColor: 'rgba(<?= hexToRgb($configs['theme_color'] ?? '#8c2bee') ?>, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                    pointBackgroundColor: '<?= $configs['theme_color'] ?? '#8c2bee' ?>',
                    pointBorderColor: 'rgba(255,255,255,0.2)',
                    pointBorderWidth: 2,
                    pointHoverRadius: 7,
                    pointHoverBackgroundColor: '<?= $configs['theme_color'] ?? '#8c2bee' ?>',
                    pointHoverBorderColor: '#fff',
                    pointHoverBorderWidth: 2
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
    }

    // Tab Switcher with Chart Update
    function switchTab(tabId) {
        document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        
        document.getElementById(tabId).classList.add('active');
        const activeBtn = Array.from(document.querySelectorAll('.tab-btn')).find(b => b.getAttribute('onclick')?.includes(tabId));
        if (activeBtn) activeBtn.classList.add('active');
        
        document.querySelector('.active-tab-input').value = tabId;

        if (tabId === 'overviewTab') {
            setTimeout(() => {
                initChart();
                if (salesChart) salesChart.resize();
            }, 100);
        }
    }

        // --- Elite Client-Side Pagination Engine with Live Search ---
        function initElitePagination(tbodyId, paginationUiId, rowsPerPage = 10, searchInputId = null) {
            const tbody = document.getElementById(tbodyId);
            const ui = document.getElementById(paginationUiId);
            const status = document.getElementById('paginationStatus');
            const container = document.getElementById('paginationButtons');
            const searchInput = searchInputId ? document.getElementById(searchInputId) : null;

            if (!tbody || !ui) return;

            const allRows = Array.from(tbody.querySelectorAll('tr:not(.no-results-row)'));
            let filteredRows = [...allRows];
            let currentPage = 1;

            function applyFilter() {
                if (searchInput) {
                    const query = searchInput.value.toLowerCase().trim();
                    filteredRows = allRows.filter(row => {
                        const text = row.textContent.toLowerCase();
                        return text.includes(query);
                    });
                } else {
                    filteredRows = [...allRows];
                }
                currentPage = 1;
                updateUI();
            }

            if (searchInput) {
                searchInput.addEventListener('input', applyFilter);
            }

            function updateUI() {
                const totalRows = filteredRows.length;

                // Hide all rows first
                allRows.forEach(r => r.style.display = 'none');

                // Handle "No Results" state
                let noResultsRow = tbody.querySelector('.no-results-row');
                if (totalRows === 0) {
                    ui.classList.add('hidden');
                    if (!noResultsRow) {
                        noResultsRow = document.createElement('tr');
                        noResultsRow.className = 'no-results-row';
                        noResultsRow.innerHTML = `<td colspan="5" class="px-8 py-12 text-center text-[10px] font-black uppercase tracking-[0.2em] text-[--text-main]/40 italic">No matching transactions found for "${searchInput?.value}"</td>`;
                        tbody.appendChild(noResultsRow);
                    } else {
                        noResultsRow.querySelector('td').textContent = `No matching transactions found for "${searchInput?.value}"`;
                        noResultsRow.style.display = '';
                    }
                    return;
                } else if (noResultsRow) {
                    noResultsRow.style.display = 'none';
                }

                if (totalRows <= rowsPerPage) {
                    ui.classList.add('hidden');
                    filteredRows.forEach(r => r.style.display = '');
                    status.textContent = `Showing 1 to ${totalRows} of ${totalRows} entries`;
                    return;
                }

                ui.classList.remove('hidden');
                const totalPages = Math.ceil(totalRows / rowsPerPage);

                // Bounds check for current page
                if (currentPage > totalPages) currentPage = totalPages;
                if (currentPage < 1) currentPage = 1;

                const start = (currentPage - 1) * rowsPerPage;
                const end = Math.min(start + rowsPerPage, totalRows);

                filteredRows.forEach((row, index) => {
                    row.style.display = (index >= start && index < end) ? '' : 'none';
                });

                status.textContent = `Showing ${start + 1} to ${end} of ${totalRows} entries`;

                container.innerHTML = '';

                // Prev Button
                const prevBtn = document.createElement('button');
                prevBtn.className = 'pagination-btn';
                prevBtn.innerHTML = '<span class="material-symbols-outlined text-sm">chevron_left</span>';
                prevBtn.disabled = currentPage === 1;
                prevBtn.onclick = () => { if (currentPage > 1) { currentPage--; updateUI(); } };
                container.appendChild(prevBtn);

                // Index Buttons (Simplified for Elite look)
                for (let i = 1; i <= totalPages; i++) {
                    const btn = document.createElement('button');
                    btn.className = `pagination-btn ${i === currentPage ? 'active' : ''}`;
                    btn.textContent = i;
                    btn.onclick = () => { currentPage = i; updateUI(); };
                    container.appendChild(btn);
                }

                // Next Button
                const nextBtn = document.createElement('button');
                nextBtn.className = 'pagination-btn';
                nextBtn.innerHTML = '<span class="material-symbols-outlined text-sm">chevron_right</span>';
                nextBtn.disabled = currentPage === totalPages;
                nextBtn.onclick = () => { if (currentPage < totalPages) { currentPage++; updateUI(); } };
                container.appendChild(nextBtn);
            }

            updateUI();
        }

        // Initialize after DOM loads
    window.addEventListener('DOMContentLoaded', () => {
        initElitePagination('historyTableBody', 'historyPaginationUI', 10, 'tableSearch');
        initChart();
    });
    </script>
</body>

</html>