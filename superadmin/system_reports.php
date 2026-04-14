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

// 1. Fetch Tenants for Filter Dropdown
$tenants_list = $pdo->query("SELECT gym_id, gym_name FROM gyms WHERE status = 'Active' ORDER BY gym_name ASC")->fetchAll();

// 2. Report Period Parameters (Detailed Reports)
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$tenant_filter = $_GET['tenant_id'] ?? 'all';
$date_params = ['start' => $date_from . ' 00:00:00', 'end' => $date_to . ' 23:59:59'];

// 3. Analytics Overview Parameters (Isolated - Rolling 7-Day Default)
$ov_date_from = $_GET['ov_date_from'] ?? date('Y-m-d', strtotime('-6 days'));
$ov_date_to = $_GET['ov_date_to'] ?? date('Y-m-d');
$ov_tenant_id = $_GET['ov_tenant_id'] ?? 'all';
$ov_params_fixed = ['start' => $ov_date_from . ' 00:00:00', 'end' => $ov_date_to . ' 23:59:59'];

// 4. User Registration Statistics (Analytics Overview Data)
$user_reg_query = "SELECT DATE(u.created_at) as reg_date, COUNT(*) as count FROM users u";
if ($ov_tenant_id !== 'all') {
    $user_reg_query .= " JOIN user_roles ur ON u.user_id = ur.user_id WHERE ur.gym_id = :tid AND u.created_at BETWEEN :start AND :end";
    $reg_params = array_merge($ov_params_fixed, ['tid' => $ov_tenant_id]);
} else {
    $user_reg_query .= " WHERE u.created_at BETWEEN :start AND :end";
    $reg_params = $ov_params_fixed;
}
$user_reg_query .= " GROUP BY DATE(u.created_at) ORDER BY reg_date ASC";
$stmtReg = $pdo->prepare($user_reg_query);
$stmtReg->execute($reg_params);
$real_data = $stmtReg->fetchAll(PDO::FETCH_ASSOC);

// Fill gaps in registration data to show a continuous line
$registration_data = [];
$start_ts = strtotime($ov_date_from);
$end_ts = strtotime($ov_date_to);
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

// 5. Usage Statistics (Analytics Overview Data)
// Total Users
$total_users_query = "SELECT COUNT(DISTINCT u.user_id) FROM users u 
                      JOIN user_roles ur ON u.user_id = ur.user_id 
                      JOIN gyms g ON ur.gym_id = g.gym_id 
                      WHERE g.status = 'Active'";
if ($ov_tenant_id !== 'all') {
    $total_users_query .= " AND g.gym_id = :tid";
}
$stmtTotal = $pdo->prepare($total_users_query);
$stmtTotal->execute($ov_tenant_id !== 'all' ? ['tid' => $ov_tenant_id] : []);
$total_users = number_format($stmtTotal->fetchColumn());

// Avg Daily Logins
$login_query = "SELECT COUNT(*) FROM audit_logs WHERE action_type = 'Login' AND created_at BETWEEN :start AND :end";
if ($ov_tenant_id !== 'all') { $login_query .= " AND gym_id = :tid"; }
$stmtLogins = $pdo->prepare($login_query);
$ov_params = $ov_params_fixed;
if ($ov_tenant_id !== 'all') { $ov_params['tid'] = $ov_tenant_id; }
$stmtLogins->execute($ov_params);
$total_logins = $stmtLogins->fetchColumn();
$days_diff = (strtotime($ov_date_to) - strtotime($ov_date_from)) / 86400;
$days = max(1, round($days_diff) + 1);
$avg_daily_logins = number_format($total_logins / $days, 1);

// Peak Usage Hour
$peak_query = "SELECT HOUR(created_at) as hr, COUNT(*) as cnt FROM audit_logs WHERE created_at BETWEEN :start AND :end";
if ($ov_tenant_id !== 'all') { $peak_query .= " AND gym_id = :tid"; }
$peak_query .= " GROUP BY hr ORDER BY cnt DESC LIMIT 1";
$stmtPeak = $pdo->prepare($peak_query);
$stmtPeak->execute($ov_params);
$peak_row = $stmtPeak->fetch(PDO::FETCH_ASSOC);
$peak_hour = $peak_row ? date('h:00 A', strtotime($peak_row['hr'] . ':00')) : 'N/A';

// Extra Inputs for Detailed Tab
$active_tab = $_GET['active_tab'] ?? 'detailedTab';
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? 'all';
$pay_status_filter = $_GET['pay_status'] ?? 'all';

// 4. Multi-Report Selection Logic
$report_type = $_GET['report_type'] ?? 'tenant_activity';
$report_data = [];

$report_titles = [
    'tenant_activity' => ['title' => 'Tenant Activity Report', 'desc' => 'Site-wide engagement and member volume'],
    'gym_apps' => ['title' => 'Gym Applications Report', 'desc' => 'Pending and processed ownership requests'],
    'client_subs' => ['title' => 'Client Subscriptions Report', 'desc' => 'Detailed billing and validity tracking'],
    'revenue_perf' => ['title' => 'Financial Performance', 'desc' => 'Revenue analytics and plan popularity'],
    'system_audit' => ['title' => 'Security & Audit Logs', 'desc' => 'Tracking administrative actions across tenants']
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
            $sql .= " AND (a.gym_name LIKE :s1 OR a.business_name LIKE :s2 OR u.first_name LIKE :s3 OR u.last_name LIKE :s4)";
            $params['s1'] = "%$search%";
            $params['s2'] = "%$search%";
            $params['s3'] = "%$search%";
            $params['s4'] = "%$search%";
        }
        $sql .= " ORDER BY a.submitted_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'client_subs':
        $sql = "SELECT g.gym_name, u.first_name, u.last_name, p.plan_name, p.price, cs.* 
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
            $sql .= " AND (g.gym_name LIKE :s1 OR u.first_name LIKE :s2 OR u.last_name LIKE :s3 OR p.plan_name LIKE :s4)";
            $params['s1'] = "%$search%";
            $params['s2'] = "%$search%";
            $params['s3'] = "%$search%";
            $params['s4'] = "%$search%";
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'revenue_perf':
        $sql = "SELECT p.plan_name, 
                       COUNT(cs.client_subscription_id) as total_subs,
                       SUM(CASE WHEN cs.subscription_status = 'Active' THEN 1 ELSE 0 END) as active_subs,
                       SUM(CASE WHEN cs.payment_status = 'Paid' THEN p.price ELSE 0 END) as total_revenue
                FROM website_plans p
                LEFT JOIN client_subscriptions cs ON p.website_plan_id = cs.website_plan_id 
                AND cs.created_at BETWEEN :start AND :end
                GROUP BY p.website_plan_id";
        $params = $date_params;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'system_audit':
        $sql = "SELECT al.*, u.first_name, u.last_name, g.gym_name 
                FROM audit_logs al
                JOIN users u ON al.user_id = u.user_id
                LEFT JOIN gyms g ON al.gym_id = g.gym_id
                WHERE al.created_at BETWEEN :start AND :end";
        $params = $date_params;
        if ($tenant_filter !== 'all') {
            $sql .= " AND al.gym_id = :tid";
            $params['tid'] = $tenant_filter;
        }
        $sql .= " ORDER BY al.created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;

    default: // tenant_activity
        $where = ["g.created_at BETWEEN :g_start AND :g_end"];
        $params = $date_params;
        $params['g_start'] = $date_params['start'];
        $params['g_end'] = $date_params['end'];

        if ($tenant_filter !== 'all') {
            $where[] = "g.gym_id = :tid";
            $params['tid'] = $tenant_filter;
        }
        if ($status_filter !== 'all') {
            $where[] = "g.status = :status";
            $params['status'] = $status_filter;
        }
        if (!empty($search)) {
            $where[] = "(g.gym_name LIKE :s1 OR g.tenant_code LIKE :s2)";
            $params['s1'] = "%$search%";
            $params['s2'] = "%$search%";
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
<?php
// Prepare Tenants for Searchable Dropdown
$tenants_js = array_map(function($t) {
    return ['id' => $t['gym_id'], 'name' => $t['gym_name']];
}, $tenants_list);
?>
<script>
    const availableTenants = <?= json_encode($tenants_js) ?>;
    const currentTenantFilter = "<?= $tenant_filter ?>";
    const currentOvTenantFilter = "<?= $ov_tenant_id ?>";
</script>
<style>
    .searchable-dropdown-overlay {
        background: var(--background);
        border: 1px solid rgba(255, 255, 255, 0.08);
        box-shadow: 0 10px 40px -10px rgba(0,0,0,0.8);
        backdrop-filter: blur(40px);
        z-index: 100;
        scrollbar-width: none;
        margin-top: 0;
    }
    .searchable-dropdown-overlay::-webkit-scrollbar { display: none; }
    
    .tenant-option {
        transition: background 0.2s;
        cursor: pointer;
        border: 1px solid transparent;
    }
    .tenant-option:hover {
        background: rgba(var(--primary-rgb), 0.08);
        border-color: rgba(var(--primary-rgb), 0.1);
        color: var(--primary);
    }
    .tenant-option.selected {
        background: var(--primary);
        color: white;
    }
</style>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        function initSearchableDropdown(containerId, inputId, dropdownId, listId, hiddenInputId, currentFilter) {
            const container = document.getElementById(containerId);
            const input = document.getElementById(inputId);
            const dropdown = document.getElementById(dropdownId);
            const list = document.getElementById(listId);
            const hiddenInput = document.getElementById(hiddenInputId);

            if (!container || !input || !dropdown || !list || !hiddenInput) return;

            function renderOptions(filter = "") {
                const filtered = availableTenants.filter(t => 
                    t.name.toLowerCase().includes(filter.toLowerCase())
                );
                
                list.innerHTML = filtered.map(t => `
                    <div class="tenant-option px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider ${currentFilter == t.id ? 'selected' : 'text-white/60'}" 
                         data-id="${t.id}" data-name="${t.name}">
                        ${t.name}
                    </div>
                `).join('') || `<div class="px-4 py-3 text-[9px] text-white/20 italic uppercase font-black">No tenant found...</div>`;
            }

            input.addEventListener('focus', () => {
                dropdown.classList.remove('hidden');
                renderOptions(input.value === 'All Tenants' || input.value === 'All System Tenants' ? '' : input.value);
            });

            input.addEventListener('input', (e) => {
                dropdown.classList.remove('hidden');
                renderOptions(e.target.value);
            });

            document.addEventListener('click', (e) => {
                if (!container.contains(e.target)) {
                    dropdown.classList.add('hidden');
                }
            });

            container.addEventListener('click', (e) => {
                const option = e.target.closest('.tenant-option');
                if (option) {
                    const id = option.dataset.id;
                    const name = option.dataset.name || "All Tenants";
                    
                    hiddenInput.value = id;
                    input.value = name;
                    dropdown.classList.add('hidden');
                    
                    container.closest('form').submit();
                }
            });
        }

        // Initialize both dropdowns
        initSearchableDropdown('tenantSearchContainer', 'tenantSearchInput', 'tenantDropdown', 'tenantOptionsList', 'hidden_tenant_id', currentTenantFilter);
        initSearchableDropdown('ovTenantSearchContainer', 'ovTenantSearchInput', 'ovTenantDropdown', 'ovTenantOptionsList', 'hidden_ov_tenant_id', currentOvTenantFilter);
    });
</script>

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

        /* Match DataTables Info and Pagination text to the dashboard theme */
        .dataTables_wrapper .dataTables_info {
            font-family: 'Lexend', sans-serif !important;
            font-size: 10px !important;
            font-weight: 900 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.1em !important;
            color: var(--text-main) !important;
            opacity: 0.4 !important;
            padding-top: 1.5rem !important;
            padding-bottom: 0.5rem !important;
            text-align: left !important;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button {
            font-family: 'Lexend', sans-serif !important;
            font-size: 10px !important;
            font-weight: 900 !important;
            text-transform: uppercase !important;
            color: var(--text-main) !important;
            opacity: 0.6 !important;
            padding: 5px 10px !important;
            border: none !important;
            background: transparent !important;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            opacity: 1 !important;
            background: rgba(255,255,255,0.05) !important;
            border-radius: 8px !important;
            color: var(--primary) !important;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
            background: rgba(255,255,255,0.1) !important;
            color: #fff !important;
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
            padding: 12px 18px;
            min-height: 48px;
            color: white;
            font-size: 13px;
            line-height: 1.5;
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
                <div class="glass-card relative z-[80]">
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
                                        class="input-field appearance-none rounded-xl px-6 h-[48px] text-[10px] font-black uppercase tracking-[0.1em] text-white focus:outline-none cursor-pointer pr-10 min-w-[240px]">
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
                                class="h-[48px] w-[48px] rounded-xl bg-white/[0.02] border border-white/5 flex items-center justify-center text-primary hover:bg-white/5 transition-all shadow-lg group" title="Reset All Filters">
                                <span class="material-symbols-outlined text-xl transition-transform group-hover:rotate-180 duration-500">refresh</span>
                             </a>
                            <button onclick="exportReportToPDF(true)"
                                class="h-[48px] px-6 rounded-xl bg-white/5 border border-white/5 text-[10px] font-black uppercase tracking-widest text-[--text-main]/60 hover:text-white hover:bg-white/10 transition-all flex items-center gap-2">
                                <span class="material-symbols-outlined text-sm">visibility</span>
                                Preview
                            </button>
                            <button onclick="exportReportToPDF(false)"
                                class="h-[48px] px-6 rounded-xl bg-primary text-white text-[10px] font-black uppercase tracking-widest hover:scale-105 transition-transform active:scale-95 shadow-lg shadow-primary/20 flex items-center gap-2">
                                <span class="material-symbols-outlined text-sm">picture_as_pdf</span>
                                Export PDF
                            </button>
                        </div>
                    </div>

                    <div class="px-8 py-4 border-b border-white/5 relative z-[60]">
                        <form method="GET" class="flex flex-wrap items-center gap-4">
                            <!-- Consolidated Filters -->
                            <input type="hidden" name="active_tab" value="detailedTab">
                            <input type="hidden" name="report_type" value="<?= $report_type ?>">

                            <!-- Date Range -->
                            <div class="flex gap-2 shrink-0">
                                <input type="date" name="date_from" value="<?= $date_from ?>" max="<?= min($date_to, date('Y-m-d')) ?>" onchange="this.form.submit()" title="From Date" class="bg-white/5 border border-white/10 rounded-xl py-3.5 px-4 text-xs font-black outline-none text-[--text-main] hover:border-white/20 transition-all [color-scheme:dark]">
                                <input type="date" name="date_to" value="<?= $date_to ?>" min="<?= $date_from ?>" max="<?= date('Y-m-d') ?>" onchange="this.form.submit()" title="To Date" class="bg-white/5 border border-white/10 rounded-xl py-3.5 px-4 text-xs font-black outline-none text-[--text-main] hover:border-white/20 transition-all [color-scheme:dark]">
                            </div>

                            <!-- Searchable Tenant Selector -->
                            <div class="w-[240px] relative group shrink-0" id="tenantSearchContainer">
                                <input type="hidden" name="tenant_id" id="hidden_tenant_id" value="<?= htmlspecialchars($tenant_filter) ?>">
                                
                                <div class="relative">
                                    <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-primary/50 text-sm pointer-events-none transition-transform group-focus-within:scale-110">business</span>
                                    <input type="text" id="tenantSearchInput" 
                                           placeholder="Search Tenant..." 
                                           value="<?= $tenant_filter === 'all' ? 'All Tenants' : htmlspecialchars(array_column($tenants_list, 'gym_name', 'gym_id')[$tenant_filter] ?? 'All Tenants') ?>"
                                           autocomplete="off"
                                           class="w-full h-[48px] bg-white/5 border border-white/10 rounded-xl pl-11 pr-10 text-xs font-black outline-none text-[--text-main] hover:border-white/20 transition-all focus:border-primary/50">
                                    <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-primary/60 text-base pointer-events-none transition-transform group-hover:scale-110">expand_more</span>
                                </div>

                                <!-- Dropdown Overlay (Stuck to bottom) -->
                                <div id="tenantDropdown" class="absolute left-0 right-0 top-full z-[100] rounded-b-xl searchable-dropdown-overlay max-h-64 overflow-y-auto hidden">
                                    <div class="p-1.5 space-y-0.5">
                                        <div class="tenant-option px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider <?= $tenant_filter === 'all' ? 'selected' : 'text-white/60' ?>" data-id="all" data-name="All Tenants">
                                            All Tenants
                                        </div>
                                        <div id="tenantOptionsList">
                                            <!-- Filtered tenants injected here -->
                                        </div>
                                    </div>
                                </div>
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
                                            <th class="px-8 py-4 text-center">Pay Status</th>
                                            <th class="px-8 py-4 text-right">Amount</th>
                                            <?php break;
                                        case 'revenue_perf': ?>
                                            <th class="px-8 py-4">Subscription Plan</th>
                                            <th class="px-8 py-4 text-center">Volume</th>
                                            <th class="px-8 py-4 text-center">Active Subs</th>
                                            <th class="px-8 py-4 text-right">Yield (Revenue)</th>
                                            <?php break;
                                        case 'system_audit': ?>
                                            <th class="px-8 py-4">Event Trigger</th>
                                            <th class="px-8 py-4">Origin / Identity</th>
                                            <th class="px-8 py-4 text-center">Reference Gym</th>
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
                                                        if (strtolower($s) == 'approved') $sc = 'emerald-500';
                                                        elseif (strtolower($s) == 'pending') $sc = 'amber-500';
                                                        elseif (strtolower($s) == 'rejected') $sc = 'rose-500';
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
                                                    <td class="px-8 py-5 text-center">
                                                        <?php
                                                        $ps = $row['payment_status'];
                                                        $psc = 'gray-500';
                                                        if (strtolower($ps) == 'paid') $psc = 'emerald-500';
                                                        elseif (strtolower($ps) == 'pending') $psc = 'amber-500';
                                                        elseif (strtolower($ps) == 'rejected') $psc = 'rose-500';
                                                        ?>
                                                        <span class="px-2.5 py-1 rounded-lg bg-<?= $psc ?>/10 border border-<?= $psc ?>/20 text-[9px] text-<?= $psc ?> font-black uppercase tracking-wider italic">
                                                            <?= htmlspecialchars($ps) ?>
                                                        </span>
                                                    </td>
                                                    <td class="px-8 py-5 text-right font-black italic text-white text-sm">
                                                        <?= ($configs['currency_symbol'] ?? '₱') . number_format($row['price'], 2) ?>
                                                    </td>
                                                    <?php break;

                                                case 'revenue_perf': ?>
                                                     <td class="px-8 py-5">
                                                         <p class="text-sm font-bold italic text-white leading-none mb-1">
                                                             <?= htmlspecialchars($row['plan_name']) ?></p>
                                                         <p class="text-[10px] opacity-40 uppercase tracking-widest font-black">
                                                             Plan Tier</p>
                                                     </td>
                                                     <td class="px-8 py-5 text-center">
                                                         <p class="text-xs font-black text-white italic">
                                                             <?= number_format($row['total_subs']) ?></p>
                                                         <p class="text-[9px] opacity-40 uppercase tracking-widest font-black">
                                                             Sign-ups</p>
                                                     </td>
                                                     <td class="px-8 py-5 text-center">
                                                         <p class="text-xs font-black text-white italic">
                                                             <?= number_format($row['active_subs']) ?></p>
                                                         <p class="text-[9px] opacity-40 uppercase tracking-widest font-black">
                                                             Active Subs</p>
                                                     </td>
                                                     <td class="px-8 py-5 text-right">
                                                         <p class="text-sm font-black text-primary italic leading-none mb-1">
                                                             <?= ($configs['currency_symbol'] ?? '₱') . number_format($row['total_revenue'], 2) ?>
                                                         </p>
                                                         <p class="text-[9px] opacity-40 uppercase tracking-widest font-black italic">
                                                             Total Yield</p>
                                                     </td>
                                                     <?php break;

                                                 case 'system_audit': ?>
                                                     <td class="px-8 py-5">
                                                         <p class="text-sm font-bold italic text-white leading-none mb-1">
                                                             <?= htmlspecialchars($row['action_type']) ?></p>
                                                         <p class="text-[10px] opacity-40 uppercase tracking-widest font-black italic">
                                                             <?= htmlspecialchars(substr($row['details'], 0, 45)) . (strlen($row['details']) > 45 ? '...' : '') ?>
                                                         </p>
                                                     </td>
                                                     <td class="px-8 py-5">
                                                         <p class="text-xs font-bold text-white mb-1">
                                                             <?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?></p>
                                                         <p class="text-[9px] opacity-40 font-black uppercase italic tracking-tighter">
                                                             IP: <?= htmlspecialchars($row['ip_address'] ?? '0.0.0.0') ?></p>
                                                     </td>
                                                     <td class="px-8 py-5 text-center">
                                                         <span class="text-[10px] font-black uppercase italic text-primary/80">
                                                             <?= htmlspecialchars($row['gym_name'] ?? 'SYSTEM') ?>
                                                         </span>
                                                     </td>
                                                     <td class="px-8 py-5 text-right">
                                                         <p class="text-xs text-white font-bold mb-1">
                                                             <?= date('M d, Y', strtotime($row['created_at'])) ?></p>
                                                         <p class="text-[9px] opacity-40 font-black uppercase italic text-white/40">
                                                             <?= date('h:i A', strtotime($row['created_at'])) ?></p>
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
                <!-- Modernized Overview Filter Bar -->
                <div class="glass-card mb-8 relative z-[80]">
                    <div class="px-8 py-4 border-b border-white/5 flex items-center justify-between">
                        <div class="flex items-center gap-4">
                            <span class="material-symbols-outlined text-primary text-xl">analytics</span>
                            <div>
                                <h4 class="text-[10px] font-black uppercase tracking-[0.2em] text-white">Analytics Scoping</h4>
                                <p class="text-[9px] opacity-40 font-black uppercase tracking-widest mt-0.5">Isolated Environment Control</p>
                            </div>
                        </div>
                    </div>

                    <div class="px-8 py-4 relative z-[60]">
                        <form method="GET" class="flex flex-wrap items-center gap-4">
                            <input type="hidden" name="active_tab" value="overviewTab">
                            <input type="hidden" name="report_type" value="<?= $report_type ?>">

                            <!-- Date Range -->
                            <div class="flex gap-3">
                                <input type="date" name="ov_date_from" value="<?= $ov_date_from ?>" max="<?= min($ov_date_to, date('Y-m-d')) ?>" onchange="this.form.submit()" title="From Date" class="bg-white/5 border border-white/10 rounded-xl py-3.5 px-4 text-xs font-black outline-none text-[--text-main] hover:border-white/20 transition-all [color-scheme:dark]">
                                <input type="date" name="ov_date_to" value="<?= $ov_date_to ?>" min="<?= $ov_date_from ?>" max="<?= date('Y-m-d') ?>" onchange="this.form.submit()" title="To Date" class="bg-white/5 border border-white/10 rounded-xl py-3.5 px-4 text-xs font-black outline-none text-[--text-main] hover:border-white/20 transition-all [color-scheme:dark]">
                            </div>

                            <!-- Searchable Tenant Selector (Overview) -->
                             <div class="w-[240px] relative group shrink-0" id="ovTenantSearchContainer">
                                 <input type="hidden" name="ov_tenant_id" id="hidden_ov_tenant_id" value="<?= htmlspecialchars($ov_tenant_id) ?>">
                                 
                                 <div class="relative">
                                     <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-primary/50 text-sm pointer-events-none transition-transform group-focus-within:scale-110">business</span>
                                     <input type="text" id="ovTenantSearchInput" 
                                            placeholder="Search Tenant..." 
                                            value="<?= $ov_tenant_id === 'all' ? 'All System Tenants' : htmlspecialchars(array_column($tenants_list, 'gym_name', 'gym_id')[$ov_tenant_id] ?? 'All System Tenants') ?>"
                                            autocomplete="off"
                                            class="w-full h-[48px] bg-white/5 border border-white/10 rounded-xl pl-11 pr-10 text-xs font-black outline-none text-[--text-main] hover:border-white/20 transition-all focus:border-primary/50">
                                     <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-primary/60 text-base pointer-events-none transition-transform group-hover:scale-110">expand_more</span>
                                 </div>

                                 <!-- Dropdown Overlay (Stuck to bottom) -->
                                 <div id="ovTenantDropdown" class="absolute left-0 right-0 top-full z-[100] rounded-b-xl searchable-dropdown-overlay max-h-64 overflow-y-auto hidden">
                                     <div class="p-1.5 space-y-0.5">
                                         <div class="tenant-option px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider <?= $ov_tenant_id === 'all' ? 'selected' : 'text-white/60' ?>" data-id="all" data-name="All System Tenants">
                                             All System Tenants
                                         </div>
                                         <div id="ovTenantOptionsList">
                                             <!-- Filtered tenants injected here -->
                                         </div>
                                     </div>
                                 </div>
                             </div>

                             <div class="h-8 w-px bg-white/5 mx-2 shrink-0"></div>

                             <!-- Reset & Search Group -->
                             <div class="flex-1 min-w-[200px] flex items-center gap-3">
                                 <div class="flex-1 relative group">
                                     <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-base text-primary/50 transition-transform group-hover:scale-110">search</span>
                                     <input type="text" name="ov_search" placeholder="Search Metrics..." 
                                            onchange="this.form.submit()" 
                                            class="w-full bg-white/5 border border-white/10 rounded-xl py-3.5 pl-12 pr-4 text-xs font-black transition-all focus:border-primary outline-none text-[--text-main]">
                                 </div>
                                 <a href="system_reports.php?active_tab=overviewTab" 
                                    class="h-[48px] w-[48px] shrink-0 rounded-xl bg-white/[0.02] border border-white/5 flex items-center justify-center text-primary hover:bg-white/5 transition-all shadow-lg group" title="Reset Analytics Filters">
                                    <span class="material-symbols-outlined text-xl transition-transform group-hover:rotate-180 duration-500">refresh</span>
                                 </a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-10">
                    <!-- Primary Chart (2/3 Column) -->
                    <div class="lg:col-span-2 glass-card p-8">
                        <div class="flex justify-between items-center mb-8">
                            <div>
                                <h3 class="text-sm font-black italic uppercase tracking-widest text-white">User Registration Growth</h3>
                                <p class="text-[9px] text-[--text-main] opacity-50 font-bold uppercase mt-1 tracking-wider">Growth Trends Over Last 7 Days</p>
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
            wrapper.style.color = '#333';
            wrapper.style.backgroundColor = '#fff';
            wrapper.style.fontFamily = "'Inter', 'Helvetica Neue', Arial, sans-serif";

            // Formal Header (Business Styling)
            const header = `
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 30px;">
                <!-- Left: Branding & Contact -->
                <div style="text-align: left;">
                    <h1 style="font-size: 28px; font-weight: 800; color: #111; margin: 0 0 8px 0; text-transform: uppercase; letter-spacing: -0.5px; line-height: 1;">${tenantName}</h1>
                    <p style="margin: 0 0 3px 0; font-size: 10px; color: #666;">Baliwag, Bulacan, Philippines, 3006</p>
                    <p style="margin: 0; font-size: 10px; color: #666;">Phone: 0976-241-1986 | Email: horizonfitnesscorp@gmail.com</p>
                </div>
                <!-- Right: Report Context -->
                <div style="text-align: right;">
                    <h2 style="font-size: 18px; font-weight: 800; color: #111; margin: 0 0 6px 0; text-transform: uppercase; letter-spacing: -0.5px; line-height: 1;">${reportTitle}</h2>
                    <p style="margin: 0 0 4px 0; font-size: 10px; color: #666;">Generated on: ${generatedAt}</p>
                    <p style="margin: 0; font-size: 9px; font-weight: 800; color: #888; text-transform: uppercase; letter-spacing: 1px;">OFFICIAL SYSTEM REPORT</p>
                </div>
            </div>
            <div style="border-bottom: 2px solid #111; margin-bottom: 30px;"></div>
        `;

            // Clone and SURGICALLY clean the content
            const contentClone = element.cloneNode(true);

            // CUSTOM PAGINATION BYPASS (Elite Pagination un-hider)
            // The custom pagination script hides rows using inline styles.
            // We will dynamically force all <tr> elements inside the clone to display.

            // 1. REMOVE UI ELEMENTS FIRST (while classes still exist)
            contentClone.querySelectorAll('button, form, span.material-symbols-outlined, header, div.border-b, .dataTables_info, .dataTables_paginate, .dataTables_length, .dataTables_filter, [id$="_info"], [id$="_paginate"]').forEach(el => el.remove());

            // 1.5 AGGRESSIVE FALLBACK: Nuke the "Showing X of Y entries" text directly if it evades class selection
            Array.from(contentClone.querySelectorAll('*')).forEach(el => {
                if (el.childNodes.length === 1 && el.childNodes[0].nodeType === 3) {
                    if (el.textContent.includes('Showing ') && el.textContent.includes(' entries')) {
                        el.remove();
                    }
                }
            });

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

            // 3. TRANSFORM TABLE INTO BUSINESS FORMAL
            const table = contentClone.querySelector('table');
            if (table) {
                table.style.setProperty('width', '100%', 'important');
                table.style.setProperty('border-collapse', 'collapse', 'important');
                table.style.setProperty('font-size', '10px', 'important');
                table.style.setProperty('color', '#333333', 'important');
                table.style.setProperty('border', 'none', 'important'); // remove outer thick box
                table.style.setProperty('font-family', "'Inter', 'Helvetica Neue', Arial, sans-serif", 'important');
                table.style.setProperty('margin-top', '0', 'important');

                table.querySelectorAll('th').forEach(th => {
                    th.style.setProperty('background-color', '#f8f9fa', 'important'); // Light clean gray
                    th.style.setProperty('color', '#111111', 'important');
                    th.style.setProperty('border', 'none', 'important');
                    th.style.setProperty('border-bottom', '2px solid #222222', 'important');
                    th.style.setProperty('border-top', '1px solid #dddddd', 'important');
                    th.style.setProperty('padding', '12px 14px', 'important');
                    th.style.setProperty('text-transform', 'uppercase', 'important');
                    th.style.setProperty('letter-spacing', '0.5px', 'important');
                    th.style.setProperty('font-weight', '700', 'important');
                    th.style.setProperty('text-align', 'left', 'important');
                });

                table.querySelectorAll('tr').forEach(tr => {
                    tr.style.setProperty('page-break-inside', 'avoid', 'important');
                    tr.style.setProperty('break-inside', 'avoid', 'important');
                    tr.style.setProperty('display', 'table-row', 'important'); // UN-HIDE EVERY ROW
                });

                table.querySelectorAll('td').forEach(td => {
                    td.style.setProperty('border', 'none', 'important');
                    td.style.setProperty('border-bottom', '1px solid #eeeeee', 'important');
                    td.style.setProperty('padding', '12px 14px', 'important');
                    td.style.setProperty('color', '#444444', 'important');
                    td.style.setProperty('background-color', '#ffffff', 'important');
                    td.style.setProperty('vertical-align', 'middle', 'important');

                    // Ensure all internal elements (p, span, div) look clean
                    td.querySelectorAll('*').forEach(ch => {
                        ch.style.setProperty('color', 'inherit', 'important');
                        ch.style.setProperty('font-size', '10px', 'important');
                        ch.style.setProperty('margin', '0', 'important');
                        ch.style.setProperty('font-weight', '500', 'important');
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