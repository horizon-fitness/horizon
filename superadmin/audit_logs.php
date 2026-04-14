<?php 
session_start();
require_once '../db.php';

// Security Check: Only Superadmin can access
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'superadmin') {
    header("Location: ../login.php");
    exit;
}

$page_title = "Audit Logs";
$active_page = "audit_logs"; 

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

// Logic: Categorize Audit Activities
function getAuditCategory($tableName, $actionType) {
    if (in_array($tableName, ['users', 'user_roles', 'login_logs'])) return 'Access & Security';
    if (in_array($tableName, ['gyms', 'gym_owner_applications'])) return 'Tenant Management';
    if (in_array($tableName, ['clients', 'members', 'attendance'])) return 'Member Management';
    if (in_array($tableName, ['payments', 'client_subscriptions', 'membership_plans'])) return 'Financial & Plans';
    if (in_array($tableName, ['classes', 'schedules', 'trainers'])) return 'Operations';
    if (in_array($tableName, ['system_settings', 'configurations'])) return 'System Config';
    return 'General';
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

// Get Filter Inputs
$search = $_GET['search'] ?? '';
$action_filter = $_GET['action_type'] ?? 'all';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$tenant_filter = $_GET['tenant_id'] ?? 'all';

// Fetch Tenants for Filter Dropdown
$tenants_list = $pdo->query("SELECT gym_id, gym_name FROM gyms WHERE status = 'Active' ORDER BY gym_name ASC")->fetchAll();

// Prepare Tenants for Searchable Dropdown
$tenants_js = array_map(function($t) {
    return ['id' => $t['gym_id'], 'name' => $t['gym_name']];
}, $tenants_list);

// Pagination Settings - Increased to 200 for the Elite Client-Side engine
$limit = 200;
$page = 1;
$offset = 0;

// Logic for fetching logs
$count_query = "SELECT COUNT(*) 
                FROM audit_logs al 
                JOIN users u ON al.user_id = u.user_id 
                WHERE al.created_at BETWEEN :start AND :end";

$query = "SELECT al.*, u.first_name, u.last_name, r.role_name as role, g.gym_name
          FROM audit_logs al 
          JOIN users u ON al.user_id = u.user_id 
          LEFT JOIN user_roles ur ON u.user_id = ur.user_id AND ur.role_status = 'Active'
          LEFT JOIN roles r ON ur.role_id = r.role_id
          LEFT JOIN gyms g ON al.gym_id = g.gym_id
          WHERE al.created_at BETWEEN :start AND :end";

$params = [
    'start' => $date_from . ' 00:00:00',
    'end' => $date_to . ' 23:59:59'
];

if ($action_filter !== 'all') {
    if ($action_filter === 'Applicant') {
        $count_query .= " AND al.table_name = 'gym_owner_applications'";
        $query .= " AND al.table_name = 'gym_owner_applications'";
    } elseif ($action_filter === 'Transaction') {
        $count_query .= " AND al.table_name IN ('payments', 'client_subscriptions')";
        $query .= " AND al.table_name IN ('payments', 'client_subscriptions')";
    } else {
        $count_query .= " AND al.action_type = :type";
        $query .= " AND al.action_type = :type";
        $params['type'] = $action_filter;
    }
}

if ($tenant_filter !== 'all') {
    $count_query .= " AND al.gym_id = :tid";
    $query .= " AND al.gym_id = :tid";
    $params['tid'] = $tenant_filter;
}

if (!empty($search)) {
    $search_condition = " AND (al.table_name LIKE :s1 OR al.action_type LIKE :s2 OR u.first_name LIKE :s3 OR u.last_name LIKE :s4)";
    $count_query .= $search_condition;
    $query .= $search_condition;
    $params['s1'] = "%$search%";
    $params['s2'] = "%$search%";
    $params['s3'] = "%$search%";
    $params['s4'] = "%$search%";
}

// Get total records for pagination
$count_stmt = $pdo->prepare($count_query);
$count_stmt->execute($params);
$total_records = $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $limit);

$query .= " ORDER BY al.created_at DESC LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($query);
$stmt->bindParam(':start', $params['start']);
$stmt->bindParam(':end', $params['end']);
if ($action_filter !== 'all' && !in_array($action_filter, ['Applicant', 'Transaction'])) $stmt->bindParam(':type', $params['type']);
if ($tenant_filter !== 'all') $stmt->bindParam(':tid', $params['tid']);
if (!empty($search)) {
    $stmt->bindParam(':s1', $params['s1']);
    $stmt->bindParam(':s2', $params['s2']);
    $stmt->bindParam(':s3', $params['s3']);
    $stmt->bindParam(':s4', $params['s4']);
}
$stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title><?= $page_title ?> | Horizon System</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script>
        const availableTenants = <?= json_encode($tenants_js) ?>;
        const currentTenantFilter = "<?= $tenant_filter ?>";

        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "var(--primary)", "background": "var(--background)", "secondary": "var(--secondary)", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)" } } }
        }
    </script>
    <style>
        *::-webkit-scrollbar { display: none !important; }
        * { -ms-overflow-style: none !important; scrollbar-width: none !important; }

        :root {
            --primary: <?= $configs['theme_color'] ?? '#8c2bee' ?>;
            --primary-rgb: <?= hexToRgb($configs['theme_color'] ?? '#8c2bee') ?>;
            --highlight: <?= $configs['secondary_color'] ?? '#a1a1aa' ?>;
            --text-main: <?= $configs['text_color'] ?? '#d1d5db' ?>;
            --background: <?= $configs['bg_color'] ?? '#0a090d' ?>;
            --secondary: <?= $configs['secondary_color'] ?? '#a1a1aa' ?>;

            /* Glassmorphism Engine */
            --card-blur: 20px;
            --card-bg: <?= ($configs['auto_card_theme'] ?? '1') === '1' ? 'rgba(' . hexToRgb($configs['theme_color'] ?? '#8c2bee') . ', 0.05)' : ($configs['card_color'] ?? '#141216') ?>;
        }

        body {
            font-family: '<?= $configs['font_family'] ?? 'Lexend' ?>', sans-serif;
            background-color: var(--background);
            color: var(--text-main);
            overflow-x: hidden;
            color-scheme: dark;
        }

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

        .glass-card {
            background: var(--card-bg);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 24px;
            backdrop-filter: blur(20px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
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

        .sidebar-scroll-container {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
        }

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

        .input-field {
            background: rgba(0,0,0,0.2) !important;
            border: 1px solid rgba(255,255,255,0.05) !important;
            border-radius: 12px !important;
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

        .premium-select-container {
            position: relative;
        }

        select option {
            background-color: #14121a;
            color: white;
            padding: 10px;
        }

        input[type="date"] {
            color-scheme: dark;
        }

        .filter-label {
            opacity: 0.4;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 700;
        }

        .text-primary { color: var(--primary) !important; }
        .bg-primary { background-color: var(--primary) !important; }

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
        // Reactive Filtering
        let filterTimeout;
        function reactiveFilter() {
            clearTimeout(filterTimeout);
            filterTimeout = setTimeout(() => {
                changePage(1); // Reset to page 1 on filter change
            }, 300); // 300ms debounce
        }

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
                renderOptions(input.value === 'All Tenants' ? '' : input.value);
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
                    
                    reactiveFilter(); // Trigger AJAX update
                }
            });
        }

        document.addEventListener('DOMContentLoaded', () => {
            initSearchableDropdown('tenantSearchContainer', 'tenantSearchInput', 'tenantDropdown', 'tenantOptionsList', 'hidden_tenant_id', currentTenantFilter);
        });

        function changePage(page) {
            const form = document.getElementById('auditFilterForm');
            const formData = new FormData(form);
            const params = new URLSearchParams(formData);
            params.set('page', page);
            
            // Update the URL without reloading
            const newUrl = `${window.location.pathname}?${params.toString()}`;
            window.history.pushState({path: newUrl}, '', newUrl);

            // Fetch the updated table content
            fetch(`${window.location.pathname}?${params.toString()}&ajax=1`)
                .then(response => response.text())
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    const newTable = doc.getElementById('auditTableContainer');
                    if (newTable) {
                        document.getElementById('auditTableContainer').innerHTML = newTable.innerHTML;
                        // Re-initialize Elite Pagination after AJAX update
                        initElitePagination('auditLogTable', 10);
                    }
                });
        }

        setInterval(updateHeaderClock, 1000);
        window.addEventListener('DOMContentLoaded', updateHeaderClock);


        function exportAuditTrail(preview = false) {
            const element = document.getElementById('auditLogTable'); // Target the table directly
            const reportTitle = "SYSTEM AUDIT";
            const tenantName = "Horizon System";
            const generatedAt = "<?= date('M d, Y h:i A') ?>";

            // Create a wrapper for formal PDF styling
            const wrapper = document.createElement('div');
            wrapper.style.padding = '50px';
            wrapper.style.color = '#333';
            wrapper.style.backgroundColor = '#fff';
            wrapper.style.fontFamily = "'Inter', 'Helvetica Neue', Arial, sans-serif";

            // Formal Header (Standardized Branding)
            const header = `
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 30px;">
                <div style="text-align: left;">
                    <h1 style="font-size: 28px; font-weight: 800; color: #111; margin: 0 0 8px 0; text-transform: uppercase; letter-spacing: -0.5px; line-height: 1;">${tenantName}</h1>
                    <p style="margin: 0 0 3px 0; font-size: 10px; color: #666;">Baliwag, Bulacan, Philippines, 3006</p>
                    <p style="margin: 0; font-size: 10px; color: #666;">Phone: 0976-241-1986 | Email: horizonfitnesscorp@gmail.com</p>
                </div>
                <div style="text-align: right; flex: 1;">
                    <h2 style="font-size: 18px; font-weight: 800; color: #111; margin: 0 0 6px 0; text-transform: uppercase; letter-spacing: -0.5px; line-height: 1; text-align: right;">${reportTitle}</h2>
                    <p style="margin: 0 0 4px 0; font-size: 10px; color: #666; text-align: right;">Generated on: ${generatedAt}</p>
                    <p style="margin: 0; font-size: 9px; font-weight: 800; color: #888; text-transform: uppercase; letter-spacing: 1px; text-align: right;">OFFICIAL SYSTEM REPORT</p>
                </div>
            </div>
            <div style="border-bottom: 2px solid #111; margin-bottom: 30px;"></div>
            `;

            // Clone and clean the content
            const contentClone = element.cloneNode(true);

            // 1. Surgical UI component removal
            contentClone.querySelectorAll('button, form, span.material-symbols-outlined, header, .pagination-container').forEach(el => el.remove());

            // 2. Strip all classes and force professional layout
            [contentClone, ...contentClone.querySelectorAll('*')].forEach(el => {
                el.removeAttribute('class');
                el.style.setProperty('color', '#000000', 'important');
                el.style.setProperty('background-color', 'transparent', 'important');
                el.style.setProperty('border-radius', '0', 'important');
                el.style.setProperty('box-shadow', 'none', 'important');
                el.style.setProperty('opacity', '1', 'important');
                el.style.setProperty('visibility', 'visible', 'important');
            });

            // 3. Table Styling (Business Formal)
            const table = contentClone.tagName === 'TABLE' ? contentClone : contentClone.querySelector('table');
            if (table) {
                table.style.setProperty('width', '100%', 'important');
                table.style.setProperty('border-collapse', 'collapse', 'important');
                table.style.setProperty('font-size', '10px', 'important');
                table.style.setProperty('font-family', "'Inter', 'Helvetica Neue', Arial, sans-serif", 'important');

                table.querySelectorAll('th').forEach(th => {
                    th.style.setProperty('background-color', '#f8f9fa', 'important');
                    th.style.setProperty('color', '#111111', 'important');
                    th.style.setProperty('border-bottom', '2px solid #222222', 'important');
                    th.style.setProperty('border-top', '1px solid #dddddd', 'important');
                    th.style.setProperty('padding', '12px 14px', 'important');
                    th.style.setProperty('text-transform', 'uppercase', 'important');
                    th.style.setProperty('font-weight', '700', 'important');
                    th.style.setProperty('text-align', 'left', 'important');
                });

                table.querySelectorAll('tr').forEach(tr => {
                    tr.style.setProperty('display', 'table-row', 'important'); // PAGINATION BYPASS
                    tr.style.setProperty('page-break-inside', 'avoid', 'important');
                });

                table.querySelectorAll('td').forEach(td => {
                    td.style.setProperty('border-bottom', '1px solid #eeeeee', 'important');
                    td.style.setProperty('padding', '12px 14px', 'important');
                    td.style.setProperty('color', '#444444', 'important');
                    td.style.setProperty('vertical-align', 'middle', 'important');

                    td.querySelectorAll('*').forEach(ch => {
                        ch.style.setProperty('color', 'inherit', 'important');
                        ch.style.setProperty('font-size', '10px', 'important');
                        ch.style.setProperty('margin', '0', 'important');
                        ch.style.setProperty('font-weight', '500', 'important');
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
                filename: `Audit_Log_Transcript_${new Date().toISOString().split('T')[0]}.pdf`,
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
</head>
<body class="antialiased flex flex-row min-h-screen">

    <?php include '../includes/superadmin_sidebar.php'; ?>

    <div class="main-content flex-1 flex flex-col min-w-0 overflow-y-auto">
    <main class="flex-1 p-6 md:p-10 max-w-[1400px] w-full mx-auto">
        <header class="mb-12 flex flex-row justify-between items-end gap-6">
            <div>
                <h2 class="text-3xl font-black italic uppercase tracking-tighter leading-none">
                    <span class="text-[--text-main] opacity-80">AUDIT</span>
                    <span class="text-primary">LOGS</span>
                </h2>
                <p class="text-[--text-main] text-[10px] font-bold uppercase tracking-widest mt-2 px-1 opacity-80">
                    Administrative & Security Monitoring
                </p>
            </div>
            <div class="flex items-end gap-8 text-right shrink-0">
                <div class="flex flex-col items-end">
                    <p id="headerClock" class="text-[--text-main] font-black italic text-2xl leading-none tracking-tighter uppercase transition-colors cursor-default">
                        00:00:00 AM
                    </p>
                    <p class="text-primary text-[10px] font-black uppercase tracking-[0.2em] leading-none mt-2">
                        <?= date('l, M d, Y') ?>
                    </p>
                </div>
            </div>
        </header>

        <div class="glass-card overflow-hidden" id="auditTableContainer">
            <form method="GET" id="auditFilterForm" onsubmit="event.preventDefault(); reactiveFilter();">
                <div class="px-8 py-6 border-b border-white/5 bg-white/[0.01] flex flex-wrap justify-between items-center gap-4">
                    <div>
                        <h3 class="text-sm font-black italic uppercase tracking-widest text-primary leading-none">System Audit Trail</h3>
                        <p class="text-[9px] text-[--text-main]/60 font-bold uppercase tracking-widest mt-1">Administrative & Security monitoring transcript</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <button type="button" onclick="exportAuditTrail(true)" class="h-[48px] px-6 rounded-xl bg-white/5 border border-white/5 text-[10px] font-black uppercase tracking-widest text-[--text-main]/60 hover:text-white hover:bg-white/10 transition-all flex items-center gap-2">
                            <span class="material-symbols-outlined text-sm">visibility</span> Preview
                        </button>
                        <button type="button" onclick="exportAuditTrail(false)" class="h-[48px] px-6 rounded-xl bg-primary text-white text-[10px] font-black uppercase tracking-widest hover:scale-105 transition-transform active:scale-95 shadow-lg shadow-primary/20 flex items-center gap-2 group">
                            <span class="material-symbols-outlined text-sm group-hover:rotate-12 transition-transform text-white">picture_as_pdf</span> Export PDF
                        </button>
                    </div>
                </div>

                <div class="px-8 py-4 border-b border-white/5 relative z-[60]">
                    <div class="flex flex-wrap items-center gap-4">
                        <!-- Date Range -->
                        <div class="flex gap-2 shrink-0" id="dateRangeContainer">
                            <input type="date" name="date_from" id="date_from" value="<?= $date_from ?>" max="<?= min($date_to, date('Y-m-d')) ?>" oninput="syncDateBounds('from'); reactiveFilter();" title="From Date" class="bg-white/5 border border-white/10 rounded-xl py-3.5 px-4 text-xs font-black outline-none text-[--text-main] hover:border-white/20 transition-all [color-scheme:dark]">
                            <input type="date" name="date_to" id="date_to" value="<?= $date_to ?>" min="<?= $date_from ?>" max="<?= date('Y-m-d') ?>" oninput="syncDateBounds('to'); reactiveFilter();" title="To Date" class="bg-white/5 border border-white/10 rounded-xl py-3.5 px-4 text-xs font-black outline-none text-[--text-main] hover:border-white/20 transition-all [color-scheme:dark]">
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

                            <!-- Dropdown Overlay -->
                            <div id="tenantDropdown" class="absolute left-0 right-0 top-full z-[100] rounded-b-xl searchable-dropdown-overlay max-h-64 overflow-y-auto hidden">
                                <div class="p-1.5 space-y-0.5" id="tenantOptionsList">
                                    <div class="tenant-option px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider <?= $tenant_filter === 'all' ? 'selected' : 'text-white/60' ?>" data-id="all" data-name="All Tenants">
                                        All Tenants
                                    </div>
                                    <!-- Filtered tenants injected here -->
                                </div>
                            </div>
                        </div>

                        <!-- Action Type selector -->
                        <div class="w-[200px] relative group shrink-0">
                            <select name="action_type" onchange="reactiveFilter()" class="w-full h-[48px] bg-white/5 border border-white/10 rounded-xl py-3.5 pl-4 pr-10 text-xs font-black outline-none text-[--text-main] appearance-none cursor-pointer hover:border-white/20 transition-all">
                                <option value="all" <?= $action_filter == 'all' ? 'selected' : '' ?>>All Activities</option>
                                <option value="Login" <?= $action_filter == 'Login' ? 'selected' : '' ?>>Login / Logout</option>
                                <option value="Applicant" <?= $action_filter == 'Applicant' ? 'selected' : '' ?>>Applicants & Tenants</option>
                                <option value="Transaction" <?= $action_filter == 'Transaction' ? 'selected' : '' ?>>New Transactions</option>
                                <option value="Create" <?= $action_filter == 'Create' ? 'selected' : '' ?>>Create Actions</option>
                                <option value="Update" <?= $action_filter == 'Update' ? 'selected' : '' ?>>Update Actions</option>
                            </select>
                            <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-sm text-[--text-main] opacity-40 pointer-events-none">expand_more</span>
                        </div>

                        <!-- Search -->
                        <div class="flex-1 min-w-[200px] relative group">
                            <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-base text-primary/50 transition-transform group-hover:scale-110">search</span>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search Table Body..." 
                                   oninput="reactiveFilter()" 
                                   class="w-full h-[48px] bg-white/5 border border-white/10 rounded-xl py-3.5 pl-12 pr-4 text-xs font-black transition-all focus:border-primary outline-none text-[--text-main]">
                        </div>

                        <!-- Reset -->
                        <a href="audit_logs.php" class="h-[48px] w-[48px] rounded-xl bg-white/[0.02] border border-white/5 flex items-center justify-center text-primary hover:bg-white/5 transition-all shadow-lg group" title="Reset All Filters">
                            <span class="material-symbols-outlined text-xl transition-transform group-hover:rotate-180 duration-500">refresh</span>
                        </a>
                    </div>
                </div>
            </form>
            <div class="overflow-x-auto">
                <table id="auditLogTable" class="w-full text-left">
                    <thead>
                        <tr class="bg-white/[0.02] border-b border-white/5">
                            <th class="px-6 py-5 text-left text-[9px] font-black uppercase tracking-[0.2em] text-[--text-main]/40">TIMESTAMP</th>
                            <th class="px-6 py-5 text-left text-[9px] font-black uppercase tracking-[0.2em] text-[--text-main]/40">TENANT scope</th>
                            <th class="px-6 py-5 text-left text-[9px] font-black uppercase tracking-[0.2em] text-[--text-main]/40">ADMIN / ROLE</th>
                            <th class="px-6 py-5 text-left text-[9px] font-black uppercase tracking-[0.2em] text-[--text-main]/40">ACTIVITY TYPE</th>
                        </tr>
</thead>
                    <tbody id="auditTableBody" class="divide-y divide-white/5 font-lexend">
                        <?php if (empty($logs)): ?>
                            <tr><td colspan="4" class="px-8 py-12 text-center text-xs text-[--text-main]/40 italic uppercase font-bold tracking-widest">No audit records found</td></tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                            <?php 
                                $category = getAuditCategory($log['table_name'], $log['action_type']);
                                
                                // Color mapping for categories
                                $catColor = 'text-[--text-main]/40';
                                if ($category === 'Access & Security') $catColor = 'text-blue-400';
                                if ($category === 'Tenant Management') $catColor = 'text-primary';
                                if ($category === 'Member Management') $catColor = 'text-emerald-400';
                                if ($category === 'Financial & Plans') $catColor = 'text-amber-400';
                                if ($category === 'Operations') $catColor = 'text-purple-400';
                                if ($category === 'System Config') $catColor = 'text-rose-400';
                            ?>
                            <tr class="transition-all duration-300 group cursor-default border-b border-white/5 hover:bg-white/[0.02]">
                                <td class="px-6 py-6 vertical-top">
                                    <p class="text-xs text-[--text-main] font-bold uppercase leading-none mb-1"><?= date('M d, Y', strtotime($log['created_at'])) ?></p>
                                    <p class="text-[9px] text-[--text-main]/40 font-black italic tracking-tighter uppercase"><?= date('h:i A', strtotime($log['created_at'])) ?></p>
                                </td>
                                <td class="px-6 py-6">
                                    <p class="text-[10px] font-black uppercase tracking-widest <?= $log['gym_name'] ? 'text-white' : 'text-white/20 italic' ?>">
                                        <?= htmlspecialchars($log['gym_name'] ?: 'Global/System') ?>
                                    </p>
                                </td>
                                <td class="px-6 py-6">
                                    <p class="text-xs font-bold text-[--text-main]"><?= htmlspecialchars($log['first_name'] . ' ' . $log['last_name']) ?></p>
                                    <p class="text-[9px] text-primary font-black uppercase tracking-[0.1em] mt-1"><?= htmlspecialchars($log['role'] ?? 'User') ?></p>
                                </td>
                                <td class="px-6 py-6">
                                    <p class="text-[9px] font-black uppercase tracking-[0.15em] mb-1 <?= $catColor ?>"><?= $category ?></p>
                                    <span class="text-[10px] text-white font-bold px-2 py-1 rounded bg-white/5 border border-white/5 inline-block"><?= htmlspecialchars($log['action_type']) ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Elite Pagination Engine UI -->
            <div id="paginationFooter" class="pagination-container hidden">
                <p id="paginationStatus" class="pagination-status">
                    Initializing Pagination...
                </p>
                <div id="paginationWrapper" class="flex items-center gap-2">
                    <!-- Dynamic Buttons -->
                </div>
            </div>
        </div>

        <script>
            function initElitePagination(containerId, rowsPerPage = 10) {
                const tbody = document.getElementById('auditTableBody');
                const footer = document.getElementById('paginationFooter');
                const controls = document.getElementById('paginationWrapper');
                const status = document.getElementById('paginationStatus');
                
                if (!tbody || !footer) return;
                
                const rows = Array.from(tbody.querySelectorAll('tr:not(.empty-row)'));
                const totalRows = rows.length;
                
                if (totalRows <= rowsPerPage) {
                    footer.classList.add('hidden');
                    rows.forEach(r => r.classList.remove('hidden'));
                    return;
                }

                footer.classList.remove('hidden');
                const totalPages = Math.ceil(totalRows / rowsPerPage);
                let currentPage = 1;

                function showPage(p) {
                    currentPage = p;
                    const start = (p - 1) * rowsPerPage;
                    const end = start + rowsPerPage;

                    rows.forEach((row, i) => {
                        if (i >= start && i < end) {
                            row.classList.remove('hidden');
                            row.classList.add('animate-in', 'fade-in', 'duration-500');
                        } else {
                            row.classList.add('hidden');
                        }
                    });

                    renderControls();
                    status.innerHTML = `Showing ${start + 1} to ${Math.min(end, totalRows)} of ${totalRows} security events`;
                }

                function renderControls() {
                    controls.innerHTML = '';
                    
                    // Prev Button
                    const prev = document.createElement('button');
                    prev.className = `pagination-btn ${currentPage === 1 ? 'disabled' : ''}`;
                    prev.disabled = currentPage === 1;
                    prev.textContent = 'Prev';
                    prev.onclick = () => currentPage > 1 && showPage(currentPage - 1);
                    controls.appendChild(prev);

                    // Indices
                    for (let i = 1; i <= totalPages; i++) {
                        if (i === 1 || i === totalPages || (i >= currentPage - 2 && i <= currentPage + 2)) {
                            const btn = document.createElement('button');
                            btn.className = `pagination-btn ${i === currentPage ? 'active' : ''}`;
                            btn.innerText = i;
                            btn.onclick = () => showPage(i);
                            controls.appendChild(btn);
                        } else if (i === currentPage - 3 || i === currentPage + 3) {
                            const dot = document.createElement('span');
                            dot.className = 'text-[--text-main]/20 text-[10px] font-black mx-1';
                            dot.innerText = '...';
                            controls.appendChild(dot);
                        }
                    }

                    // Next Button
                    const next = document.createElement('button');
                    next.className = `pagination-btn ${currentPage === totalPages ? 'disabled' : ''}`;
                    next.disabled = currentPage === totalPages;
                    next.textContent = 'Next';
                    next.onclick = () => currentPage < totalPages && showPage(currentPage + 1);
                    controls.appendChild(next);
                }

                showPage(1);
            }

            // Date Range Synchronization (Conflict Prevention)
            function syncDateBounds(triggerSource = null) {
                const fromInput = document.getElementById('date_from');
                const toInput = document.getElementById('date_to');
                const today = new Date().toISOString().split('T')[0];

                if (fromInput.value) {
                    toInput.min = fromInput.value;
                    // Auto-correction: if From > To, push To forward
                    if (toInput.value && fromInput.value > toInput.value && triggerSource === 'from') {
                        toInput.value = fromInput.value;
                    }
                }
                if (toInput.value) {
                    fromInput.max = Math.min(toInput.value, today);
                    // Auto-correction: if To < From, pull From back
                    if (fromInput.value && toInput.value < fromInput.value && triggerSource === 'to') {
                        fromInput.value = toInput.value;
                    }
                    if (toInput.value > today) toInput.value = today;
                }
            }

            // Initialize on load
            window.addEventListener('DOMContentLoaded', () => {
                syncDateBounds(); // Run once to set initial bounds
                initElitePagination('auditLogTable', 10);
            });
        </script>
    </main>
</div>
</body>
</html>