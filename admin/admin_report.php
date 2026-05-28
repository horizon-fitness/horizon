<?php
session_start();
require_once '../db.php';

// Security Check: Only Staff and Coach
$role = strtolower($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || ($role !== 'staff' && $role !== 'coach')) {
    header("Location: ../login.php");
    exit;
}

$gym_id = $_SESSION['gym_id'];
$admin_name = $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];

// ── 4-Color Elite Branding System Implementation ─────────────────────────────
if (!function_exists('hexToRgb')) {
    function hexToRgb($hex) {
        if (!$hex) return "0, 0, 0";
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
}

// Fetch Gym & Owner Details for Branding
$gym = [];
$gym_address = [];
$owner_user_id = 0;
$gym_name = 'Horizon Gym';

try {
    $stmtGymBranding = $pdo->prepare("SELECT owner_user_id, gym_name, address_id FROM gyms WHERE gym_id = ?");
    $stmtGymBranding->execute([$gym_id]);
    $gym = $stmtGymBranding->fetch();
    if ($gym) {
        $owner_user_id = $gym['owner_user_id'] ?? 0;
        $gym_name = $gym['gym_name'] ?? 'Horizon Gym';
    }

    if (!empty($gym['address_id'])) {
        $stmtAddress = $pdo->prepare("SELECT * FROM addresses WHERE address_id = ?");
        $stmtAddress->execute([$gym['address_id']]);
        $gym_address = $stmtAddress->fetch() ?: [];
    }
} catch (Exception $e) {
    // Silent fail for branding
}

$configs = [
    'system_name'     => $gym_name,
    'system_logo'     => '',
    'theme_color'     => '#8c2bee',
    'secondary_color' => '#a1a1aa',
    'text_color'      => '#d1d5db',
    'bg_color'        => '#0a090d',
    'card_color'      => '#141216',
    'auto_card_theme' => '1',
    'font_family'     => 'Lexend',
];

try {
    // 1. Merge global settings (user_id = 0)
    $stmtGlobal = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = 0");
    $stmtGlobal->execute();
    foreach (($stmtGlobal->fetchAll(PDO::FETCH_KEY_PAIR) ?: []) as $k => $v) {
        if ($v !== null && $v !== '') $configs[$k] = $v;
    }

    // 2. Merge tenant-specific settings (user_id = owner_user_id)
    if ($owner_user_id > 0) {
        $stmtTenant = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = ?");
        $stmtTenant->execute([$owner_user_id]);
        foreach (($stmtTenant->fetchAll(PDO::FETCH_KEY_PAIR) ?: []) as $k => $v) {
            if ($v !== null && $v !== '') $configs[$k] = $v;
        }
    }
} catch (Exception $e) {}

// 3. Resolved branding tokens
$theme_color     = $configs['theme_color'];
$highlight_color = $configs['secondary_color'];
$text_color      = $configs['text_color'];
$bg_color        = $configs['bg_color'];
$font_family     = $configs['font_family'] ?? 'Lexend';
$auto_card_theme = $configs['auto_card_theme'] ?? '1';
$card_color      = $configs['card_color'];

$primary_rgb   = hexToRgb($theme_color);
$highlight_rgb = hexToRgb($highlight_color);
$card_bg_css   = ($auto_card_theme === '1') ? "rgba({$primary_rgb}, 0.05)" : $card_color;

$system_logo = $configs['system_logo'] ?? '';
// ─────────────────────────────────────────────────────────────────────────────

// --- REPORT FILTER LOGIC ---
$tab = $_GET['tab'] ?? 'financials';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$search = $_GET['search'] ?? '';
$member_filter = $_GET['member_id'] ?? 'all';

$today = date('Y-m-d');
if ($start_date > $today) $start_date = $today;
if ($end_date > $today) $end_date = $today;
if ($end_date < $start_date) $end_date = $start_date;

$members_list = [];
try {
    $stmtMembers = $pdo->prepare("SELECT m.member_id, u.first_name, u.last_name FROM members m JOIN users u ON m.user_id = u.user_id WHERE m.gym_id = ? AND u.is_active = 1 ORDER BY u.first_name ASC, u.last_name ASC");
    $stmtMembers->execute([$gym_id]);
    $members_list = $stmtMembers->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$member_id_filter = ($member_filter !== 'all') ? (int)$member_filter : 0;

// --- CALCULATE REVENUE BREAKDOWNS (Lifetime) ---
$lifetime_total = 0;
$membership_lifetime = 0;
$booking_lifetime = 0;
try {
    $base_sql = "WHERE (payment_status IN ('Approved', 'Paid', 'Verified', 'Completed') 
                   OR (payment_type = 'Booking' AND booking_id IN (SELECT booking_id FROM bookings WHERE booking_status = 'Confirmed')))
                AND (gym_id = ? OR member_id IN (SELECT member_id FROM members WHERE gym_id = ?)) 
                AND client_subscription_id IS NULL";
                
    $stmtLifetime = $pdo->prepare("SELECT SUM(amount) as total FROM payments $base_sql");
    $stmtLifetime->execute([$gym_id, $gym_id]);
    $lifetime_total = $stmtLifetime->fetch()['total'] ?? 0;
    
    $stmtM = $pdo->prepare("SELECT SUM(amount) as total FROM payments $base_sql AND payment_type = 'Membership'");
    $stmtM->execute([$gym_id, $gym_id]);
    $membership_lifetime = $stmtM->fetch()['total'] ?? 0;
    
    $stmtB = $pdo->prepare("SELECT SUM(amount) as total FROM payments $base_sql AND payment_type = 'Booking'");
    $stmtB->execute([$gym_id, $gym_id]);
    $booking_lifetime = $stmtB->fetch()['total'] ?? 0;
} catch (Exception $e) {}

// --- CALCULATE REVENUE BREAKDOWNS (Filtered Period) ---
$filtered_total = 0;
$membership_filtered = 0;
$booking_filtered = 0;
try {
    $filter_sql = "WHERE (payment_status IN ('Approved', 'Paid', 'Verified', 'Completed') 
                   OR (payment_type = 'Booking' AND booking_id IN (SELECT booking_id FROM bookings WHERE booking_status = 'Confirmed')))
                   AND (gym_id = ? OR member_id IN (SELECT member_id FROM members WHERE gym_id = ?)) 
                   AND client_subscription_id IS NULL 
                   AND created_at BETWEEN ? AND ?";
                   
    $stmtFiltered = $pdo->prepare("SELECT SUM(amount) as total FROM payments $filter_sql");
    $stmtFiltered->execute([$gym_id, $gym_id, $start_date . ' 00:00:00', $end_date . ' 23:59:59']);
    $filtered_total = $stmtFiltered->fetch()['total'] ?? 0;
    
    $stmtMF = $pdo->prepare("SELECT SUM(amount) as total FROM payments $filter_sql AND payment_type = 'Membership'");
    $stmtMF->execute([$gym_id, $gym_id, $start_date . ' 00:00:00', $end_date . ' 23:59:59']);
    $membership_filtered = $stmtMF->fetch()['total'] ?? 0;
    
    $stmtBF = $pdo->prepare("SELECT SUM(amount) as total FROM payments $filter_sql AND payment_type = 'Booking'");
    $stmtBF->execute([$gym_id, $gym_id, $start_date . ' 00:00:00', $end_date . ' 23:59:59']);
    $booking_filtered = $stmtBF->fetch()['total'] ?? 0;
} catch (Exception $e) {}

// --- DYNAMIC DATA FETCHING ---
$query_data = [];
$report_title = "Report";

try {
    switch ($tab) {
        case 'financials':
            $sql = "SELECT p.*, CONCAT(u.first_name, ' ', u.last_name) as fullname, u.username,
                           CASE 
                             WHEN p.subscription_id IS NOT NULL THEN 'Subscription'
                             WHEN p.booking_id IS NOT NULL THEN 'Booking'
                             ELSE 'Other' 
                           END as transaction_type
                    FROM payments p 
                    LEFT JOIN members m ON p.member_id = m.member_id
                    LEFT JOIN users u ON m.user_id = u.user_id 
                    WHERE (p.gym_id = ? OR p.member_id IN (SELECT member_id FROM members WHERE gym_id = ?))
                    AND p.client_subscription_id IS NULL
                    AND (p.payment_status IN ('Approved', 'Paid', 'Verified', 'Completed') 
                         OR (p.payment_type = 'Booking' AND p.booking_id IN (SELECT booking_id FROM bookings WHERE booking_status = 'Confirmed')))
                    AND p.created_at BETWEEN ? AND ?
                    " . ($member_id_filter > 0 ? " AND p.member_id = ? " : "") . "
                    AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ? OR p.reference_number LIKE ?)
                    ORDER BY p.created_at DESC";
            $stmt = $pdo->prepare($sql);
            $sterm = "%$search%";
            $params = [$gym_id, $gym_id, $start_date . ' 00:00:00', $end_date . ' 23:59:59'];
            if ($member_id_filter > 0) $params[] = $member_id_filter;
            $params = array_merge($params, [$sterm, $sterm, $sterm, $sterm]);
            $stmt->execute($params);
            $query_data = $stmt->fetchAll();
            $report_title = "Financial Report";
            break;

        case 'attendance':
            $sql = "SELECT a.*, CONCAT(u.first_name, ' ', u.last_name) as fullname, u.username,
                           b.booking_reference, b.booking_status
                    FROM attendance a 
                    JOIN members m ON a.member_id = m.member_id
                    JOIN users u ON m.user_id = u.user_id 
                    LEFT JOIN bookings b ON a.booking_id = b.booking_id
                    WHERE a.gym_id = ?
                    AND a.attendance_date BETWEEN ? AND ?
                    " . ($member_id_filter > 0 ? " AND a.member_id = ? " : "") . "
                    AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ? OR b.booking_reference LIKE ?)
                    ORDER BY a.attendance_date DESC, a.check_in_time DESC";
            $stmt = $pdo->prepare($sql);
            $sterm = "%$search%";
            $params = [$gym_id, $start_date, $end_date];
            if ($member_id_filter > 0) $params[] = $member_id_filter;
            $params = array_merge($params, [$sterm, $sterm, $sterm, $sterm]);
            $stmt->execute($params);
            $query_data = $stmt->fetchAll();
            $report_title = "Attendance Report";
            break;

        case 'membership':
            $sql = "SELECT ms.*, mp.plan_name, CONCAT(u.first_name, ' ', u.last_name) as fullname, u.username, u.email
                    FROM member_subscriptions ms
                    JOIN membership_plans mp ON ms.membership_plan_id = mp.membership_plan_id
                    JOIN members m ON ms.member_id = m.member_id
                    JOIN users u ON m.user_id = u.user_id
                    WHERE m.gym_id = ?
                    AND (ms.start_date BETWEEN ? AND ? OR ms.end_date BETWEEN ? AND ?)
                    " . ($member_id_filter > 0 ? " AND ms.member_id = ? " : "") . "
                    AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ?)
                    ORDER BY ms.end_date ASC";

            $stmt = $pdo->prepare($sql);
            $sterm = "%$search%";
            $params = [$gym_id, $start_date, $end_date, $start_date, $end_date];
            if ($member_id_filter > 0) $params[] = $member_id_filter;
            $params = array_merge($params, [$sterm, $sterm, $sterm]);
            $stmt->execute($params);
            $query_data = $stmt->fetchAll();
            $report_title = "Subscription Report";
            break;
    }
} catch (Exception $e) {
    $error_msg = $e->getMessage();
}

$active_page = "reports";
$page = [
    'logo_path' => $system_logo,
    'system_name' => $configs['system_name'] ?? 'Horizon Staff'
];
?>
<!DOCTYPE html>
<html class="dark" lang="en">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>System Reports | <?= htmlspecialchars($configs['system_name']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=<?= urlencode($font_family) ?>:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet" />
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <?php
    $members_js = array_map(function ($m) {
        return ['id' => $m['member_id'], 'name' => trim($m['first_name'] . ' ' . $m['last_name'])];
    }, $members_list);
    $member_name_map = array_column($members_js, 'name', 'id');
    ?>
    <script>
        const availableMembers = <?= json_encode($members_js) ?>;
        const currentMemberFilter = "<?= htmlspecialchars($member_filter) ?>";
    </script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "var(--primary)",
                        "background": "var(--background)",
                        "card-bg": "var(--card-bg)",
                        "text-main": "var(--text-main)",
                        "highlight": "var(--highlight)"
                    }
                }
            }
        } 
    </script>
    <style>
        :root {
            --primary:       <?= $theme_color ?>;
            --primary-rgb:   <?= $primary_rgb ?>;
            --highlight:     <?= $highlight_color ?>;
            --highlight-rgb: <?= $highlight_rgb ?>;
            --text-main:     <?= $text_color ?>;
            --background:    <?= $bg_color ?>;
            --card-bg:       <?= $card_bg_css ?>;
            --card-blur:     20px;
        }

        body {
            font-family: '<?= $font_family ?>', sans-serif;
            background-color: var(--background);
            color: var(--text-main);
            display: flex;
            flex-direction: row;
            min-height: 100vh;
            overflow: hidden;
        }

        .glass-card {
            background: var(--card-bg);
            border: 1px solid rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(var(--card-blur));
            border-radius: 24px;
            transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }
        /* Sidebar Width Logic */
        :root { --nav-width: 110px; }
        body:has(.side-nav:hover) { --nav-width: 300px; }

        .side-nav {
            width: var(--nav-width);
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            position: fixed;
            left: 0;
            top: 0;
            height: 100vh;
            z-index: 110;
        }

        .main-content {
            margin-left: var(--nav-width);
            flex: 1;
            min-width: 0;
            transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow-y: auto;
            overflow-x: hidden;
        }

        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        .input-box {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            color: var(--text-main);
            height: 52px;
            padding: 0 18px;
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            outline: none;
            transition: all 0.2s;
            color-scheme: dark;
        }

        .input-box:focus {
            border-color: var(--primary);
            background: rgba(var(--primary-rgb), 0.08);
            box-shadow: 0 0 0 4px rgba(var(--primary-rgb), 0.08);
        }

        .input-box::placeholder { color: rgba(255, 255, 255, 0.2); }

        .tab-btn {
            padding: 0 0 18px;
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.25em;
            color: rgba(255, 255, 255, 0.3);
            border-bottom: 2px solid transparent;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .tab-btn.active {
            color: var(--primary);
            border-color: var(--primary);
        }

        .tab-btn:hover:not(.active) {
            color: var(--text-main);
        }

        .status-card-primary {
            border: 1px solid rgba(var(--primary-rgb), 0.3);
            background: linear-gradient(135deg, rgba(var(--primary-rgb), 0.05) 0%, rgba(var(--primary-rgb), 0.01) 100%);
        }

        .status-card-green {
            border: 1px solid rgba(16, 185, 129, 0.3);
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.05) 0%, rgba(16, 185, 129, 0.01) 100%);
        }

        .status-card-amber {
            border: 1px solid rgba(245, 158, 11, 0.3);
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.05) 0%, rgba(245, 158, 11, 0.01) 100%);
        }

        .table-header-alt {
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.25em;
            color: var(--text-main);
            opacity: 0.5;
        }

        .report-row-text {
            color: color-mix(in srgb, var(--text-main) 82%, transparent);
        }

        .icon-btn {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .icon-btn:hover {
            transform: translateY(-1px);
        }

        .searchable-dropdown-overlay {
            background: var(--background);
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 10px 40px -10px rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(40px);
            z-index: 100;
        }

        .tenant-option {
            transition: background 0.2s;
            cursor: pointer;
            border: 1px solid transparent;
        }

        .tenant-option:hover,
        .tenant-option.selected {
            background: var(--primary);
            color: white;
        }

        .report-filter-form {
            display: grid;
            grid-template-columns: 170px 170px 260px 1px minmax(280px, 1fr) 52px auto auto;
            gap: 14px;
            align-items: center;
        }

        @media (max-width: 1280px) {
            .report-filter-form {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        *::-webkit-scrollbar { display: none !important; }
        * { -ms-overflow-style: none !important; scrollbar-width: none !important; }

        /* Sidebar Item & Label Logic */
        .nav-label {
            opacity: 0;
            transform: translateX(-15px);
            transition: all 0.3s ease-in-out;
            white-space: nowrap;
            pointer-events: none;
        }

        .side-nav:hover .nav-label {
            opacity: 1;
            transform: translateX(0);
            pointer-events: auto;
        }

        .nav-section-label {
            max-height: 0;
            opacity: 0;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            margin: 0 !important;
            pointer-events: none;
        }

        .side-nav:hover .nav-section-label {
            max-height: 20px;
            opacity: 1;
            margin-bottom: 8px !important;
            pointer-events: auto;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 10px 38px;
            transition: opacity 0.2s ease, color 0.2s ease;
            text-decoration: none;
            white-space: nowrap;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: color-mix(in srgb, var(--text-main) 45%, transparent);
            position: relative;
        }

        .nav-item:hover { color: var(--text-main); }
        .nav-item .material-symbols-rounded { 
            color: var(--highlight); 
            transition: transform 0.2s ease; 
        }
        .nav-item:hover .material-symbols-rounded { transform: scale(1.12); }

        .nav-item.active {
            color: var(--primary) !important;
            position: relative;
        }

        .nav-item.active::after {
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

        .tab-btn.active {
            color: var(--primary);
            border-color: var(--primary);
        }

        .tab-btn:hover:not(.active) {
            color: var(--text-main);
        }

        @media print {
            .side-nav, .no-print, .tab-btn, form { display: none !important; }
            .main-content { margin-left: 0 !important; padding: 0 !important; overflow: visible !important; }
            body { background: white !important; color: black !important; overflow: visible !important; }
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

        function clearFilters() {
            const urlParams = new URLSearchParams(window.location.search);
            const tab = urlParams.get('tab') || 'financials';
            window.location.href = 'admin_report.php?tab=' + tab;
        }

        function syncReportDateLimits(changed) {
            const from = document.querySelector('input[name="start_date"]');
            const to = document.querySelector('input[name="end_date"]');
            if (!from || !to) return;
            const today = new Date().toISOString().split('T')[0];
            from.max = to.value || today;
            to.max = today;
            to.min = from.value || '';
            if (from.value > today) from.value = today;
            if (to.value > today) to.value = today;
            if (from.value && to.value && to.value < from.value) {
                if (changed === 'start') to.value = from.value;
                else from.value = to.value;
            }
        }

        window.addEventListener('DOMContentLoaded', () => {
            const container = document.getElementById('memberSearchContainer');
            const input = document.getElementById('memberSearchInput');
            const dropdown = document.getElementById('memberDropdown');
            const list = document.getElementById('memberOptionsList');
            const hidden = document.getElementById('hidden_member_id');
            if (!container || !input || !dropdown || !list || !hidden) return;

            const renderOptions = (filter = '') => {
                const q = filter === 'All Members' ? '' : filter.toLowerCase().trim();
                const filtered = availableMembers.filter(m => m.name.toLowerCase().includes(q));
                list.innerHTML = filtered.map(m => `
                    <div class="tenant-option px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider ${String(m.id) === String(currentMemberFilter) ? 'selected' : 'text-white/60'}"
                        data-id="${m.id}" data-name="${m.name}">
                        ${m.name}
                    </div>
                `).join('') || `<div class="px-4 py-4 text-[10px] font-black uppercase tracking-wider text-white/30">No members found</div>`;
            };

            renderOptions();
            input.addEventListener('focus', () => { dropdown.classList.remove('hidden'); renderOptions(input.value); });
            input.addEventListener('input', () => { dropdown.classList.remove('hidden'); renderOptions(input.value); });
            dropdown.addEventListener('mousedown', (event) => {
                const option = event.target.closest('.tenant-option');
                if (!option) return;
                hidden.value = option.dataset.id;
                input.value = option.dataset.name;
                dropdown.classList.add('hidden');
                input.form.submit();
            });
            document.addEventListener('mousedown', (event) => {
                if (!container.contains(event.target)) dropdown.classList.add('hidden');
            });
        });

        function exportReportToPDF(preview = false) {
            const tableElement = document.getElementById('reportTableContainer');
            const reportTypeLabel = "<?= $report_title ?>";
            const gymName = "<?= htmlspecialchars($gym_name) ?>";
            const gymAddress = "<?= htmlspecialchars(($gym_address['address_line'] ?? '') . ', ' . ($gym_address['city'] ?? '')) ?>";
            const generatedAt = "<?= date('M d, Y') ?>";

            // Create formal wrapper
            const wrapper = document.createElement('div');
            wrapper.style.padding = '40px';
            wrapper.style.color = '#000';
            wrapper.style.backgroundColor = '#fff';
            wrapper.style.fontFamily = "'Lexend', sans-serif";

            const header = `
                <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 5px;">
                    <div style="text-align: left;">
                        <h1 style="font-size: 24px; font-weight: 800; color: #000; margin: 0; text-transform: uppercase;">${gymName}</h1>
                    </div>
                    <div style="text-align: right;">
                        <h2 style="font-size: 14px; font-weight: 700; color: #000; margin: 0; text-transform: uppercase;">${reportTypeLabel}</h2>
                    </div>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 15px; font-size: 9px; line-height: 1.5;">
                    <div style="text-align: left; color: #333;">
                        <p style="margin: 0;">${gymAddress}</p>
                    </div>
                    <div style="text-align: right; color: #333;">
                        <p style="margin: 0;">Date: ${generatedAt}</p>
                        <p style="margin: 0; font-weight: bold;">OFFICIAL SYSTEM REPORT</p>
                    </div>
                </div>
                <div style="border-bottom: 1.5px solid #000; margin-bottom: 30px;"></div>
            `;

            const contentClone = tableElement.cloneNode(true);
            contentClone.querySelectorAll('button, .no-print, span.material-symbols-outlined, span.material-symbols-rounded').forEach(el => el.remove());

            [contentClone, ...contentClone.querySelectorAll('*')].forEach(el => {
                el.removeAttribute('class');
                el.style.setProperty('color', '#000000', 'important');
                el.style.setProperty('background-color', 'transparent', 'important');
                el.style.setProperty('border-radius', '0', 'important');
                el.style.setProperty('box-shadow', 'none', 'important');
                el.style.setProperty('opacity', '1', 'important');
                el.style.setProperty('font-family', "'Lexend', sans-serif", 'important');
            });

            const table = contentClone.querySelector('table');
            if (table) {
                table.style.setProperty('width', '100%', 'important');
                table.style.setProperty('border-collapse', 'collapse', 'important');
                table.style.setProperty('font-size', '10px', 'important');
                table.style.setProperty('border', '1px solid #000', 'important');

                table.querySelectorAll('th').forEach(th => {
                    th.style.setProperty('background-color', '#f5f5f5', 'important');
                    th.style.setProperty('border', '1px solid #000', 'important');
                    th.style.setProperty('padding', '8px', 'important');
                    th.style.setProperty('text-align', 'left', 'important');
                    th.style.setProperty('font-weight', '700', 'important');
                    th.style.setProperty('text-transform', 'uppercase', 'important');
                });

                table.querySelectorAll('td').forEach(td => {
                    td.style.setProperty('border', '1px solid #eee', 'important');
                    td.style.setProperty('padding', '6px 8px', 'important');
                });

                table.querySelectorAll('tfoot td').forEach(td => {
                    td.style.setProperty('font-weight', 'bold', 'important');
                    td.style.setProperty('background-color', '#fafafa', 'important');
                    td.style.setProperty('border-top', '2px solid #000', 'important');
                });
            }

            const footer = document.createElement('div');
            footer.style.marginTop = '40px';
            footer.style.textAlign = 'center';
            footer.style.fontSize = '8px';
            footer.style.color = '#777';
            footer.style.borderTop = '1px solid #eee';
            footer.style.paddingTop = '10px';
            footer.innerHTML = `<p style="margin: 0; font-weight: bold;">INTERNAL USE ONLY</p>`;

            wrapper.innerHTML = header;
            wrapper.appendChild(contentClone);
            wrapper.appendChild(footer);

            const opt = {
                margin: [0.4, 0.4],
                filename: `Report_${reportTypeLabel.replace(/\s+/g, '_')}_${new Date().toISOString().split('T')[0]}.pdf`,
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

<body class="antialiased flex h-screen overflow-hidden">

    <?php include '../includes/admin_sidebar.php'; ?>

    <div class="main-content flex-1 overflow-y-auto no-scrollbar">
        <main class="p-10 max-w-[1650px] mx-auto pb-20">
            <header class="mb-10 flex flex-row justify-between items-end gap-6">
                <div>
                    <h2 class="text-3xl font-black italic uppercase tracking-tighter text-[--text-main] leading-none">System <span class="text-primary">Reports</span></h2>
                    <p class="text-[--text-main]/50 text-[10px] font-black uppercase tracking-widest mt-2 px-1"><?= htmlspecialchars($gym_name) ?> Activity And Metrics</p>
                </div>
                <div class="flex items-end gap-8 text-right shrink-0 no-print">
                    <div class="flex flex-col items-end">
                        <p id="headerClock" class="text-[--text-main] font-black italic text-2xl leading-none tracking-tighter">00:00:00 AM</p>
                        <p class="text-primary text-[10px] font-black uppercase tracking-[0.2em] leading-none mt-2"><?= date('l, M d, Y') ?></p>
                    </div>
                </div>
            </header>

            <!-- Revenue Summary Strip -->
            <div class="hidden grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                <div class="glass-card p-8 status-card-primary relative overflow-hidden group hover:scale-[1.02] transition-all">
                    <span class="material-symbols-rounded absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-primary">payments</span>
                    <p class="text-[10px] font-black uppercase tracking-[0.2em] text-[--text-main]/40 mb-2">Total Combined Revenue</p>
                    <h3 class="text-2xl font-black italic uppercase text-[--text-main]">
                        <span class="text-primary opacity-50 text-xl">₱</span>
                        <?= number_format($lifetime_total, 2) ?>
                    </h3>
                    <p class="text-[9px] font-bold text-[--text-main]/20 uppercase tracking-widest mt-2 px-1">Total Lifetime Settled</p>
                </div>
                <div class="glass-card p-8 status-card-green relative overflow-hidden group hover:scale-[1.02] transition-all">
                    <span class="material-symbols-rounded absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-emerald-500">workspace_premium</span>
                    <p class="text-[10px] font-black uppercase tracking-[0.2em] text-emerald-500/60 mb-2">Membership Income</p>
                    <h3 class="text-2xl font-black italic uppercase text-[--text-main]">
                        <span class="text-emerald-500 opacity-50 text-xl">₱</span>
                        <?= number_format($membership_lifetime, 2) ?>
                    </h3>
                    <p class="text-[9px] font-bold text-[--text-main]/20 uppercase tracking-widest mt-2 px-1">Active Subscriptions</p>
                </div>
                <div class="glass-card p-8 status-card-amber relative overflow-hidden group hover:scale-[1.02] transition-all">
                    <span class="material-symbols-rounded absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-amber-500">event_available</span>
                    <div class="mb-2">
                        <p class="text-[10px] font-black uppercase tracking-[0.2em] text-amber-500/60 mb-1">Booking Revenue</p>
                        <h3 class="text-2xl font-black italic uppercase text-[--text-main]">
                            <span class="text-amber-500 opacity-50 text-base">₱</span>
                            <?= number_format($booking_lifetime, 2) ?>
                        </h3>
                    </div>
                    <p class="text-[9px] font-bold text-[--text-main]/20 uppercase tracking-widest mt-2 px-1">Confirmed Sessions</p>
                </div>
            </div>

            <!-- tab Switcher -->
            <div
                class="flex items-center gap-16 mb-14 border-b border-white/5 no-print uppercase font-black text-[11px] tracking-widest">
                <a href="?tab=financials&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>"
                    class="tab-btn <?= ($tab === 'financials') ? 'active' : '' ?>">Financial</a>
                <a href="?tab=attendance&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>"
                    class="tab-btn <?= ($tab === 'attendance') ? 'active' : '' ?>">Attendance</a>
                <a href="?tab=membership&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>"
                    class="tab-btn <?= ($tab === 'membership') ? 'active' : '' ?>">Memberships</a>
            </div>

            <div class="glass-card overflow-hidden shadow-2xl border border-white/5">
                <div class="p-8 border-b border-white/5 relative z-[60] bg-white/[0.01] no-print">
                <form method="GET" class="report-filter-form">
                    <input type="hidden" name="tab" value="<?= $tab ?>">
                    <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>"
                        max="<?= htmlspecialchars($end_date ?: $today) ?>" oninput="syncReportDateLimits('start')" onchange="this.form.submit()"
                        class="input-box w-full">
                    <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>"
                        min="<?= htmlspecialchars($start_date) ?>" max="<?= htmlspecialchars($today) ?>" oninput="syncReportDateLimits('end')" onchange="this.form.submit()"
                        class="input-box w-full">
                    <div class="relative group" id="memberSearchContainer">
                        <input type="hidden" name="member_id" id="hidden_member_id" value="<?= htmlspecialchars($member_filter) ?>">
                        <div class="relative">
                            <span class="material-symbols-rounded absolute left-4 top-1/2 -translate-y-1/2 text-primary/50 text-sm pointer-events-none">group</span>
                            <input type="text" id="memberSearchInput" placeholder="Search Member..." value="<?= $member_filter === 'all' ? 'All Members' : htmlspecialchars($member_name_map[$member_filter] ?? 'All Members') ?>" autocomplete="off"
                                class="w-full h-[52px] bg-white/5 border border-white/10 rounded-2xl pl-11 pr-10 text-[10px] font-black uppercase tracking-widest outline-none text-[--text-main] hover:border-white/20 transition-all focus:border-primary/50 cursor-pointer">
                            <span class="material-symbols-rounded absolute right-4 top-1/2 -translate-y-1/2 text-primary/60 text-base pointer-events-none">expand_more</span>
                        </div>
                        <div id="memberDropdown" class="absolute left-0 right-0 top-full z-[100] rounded-b-xl searchable-dropdown-overlay max-h-64 overflow-y-auto hidden">
                            <div class="p-1.5 space-y-0.5">
                                <div class="tenant-option px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider <?= $member_filter === 'all' ? 'selected' : 'text-white/60' ?>" data-id="all" data-name="All Members">All Members</div>
                                <div id="memberOptionsList"></div>
                            </div>
                        </div>
                    </div>
                    <div class="h-8 w-px bg-white/5"></div>
                    <div class="relative group">
                        <span class="material-symbols-rounded absolute left-4 top-1/2 -translate-y-1/2 text-base text-primary/50">search</span>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search records..."
                            class="w-full h-[52px] bg-white/5 border border-white/10 rounded-2xl pl-12 pr-4 text-[10px] font-black uppercase tracking-widest text-[--text-main] outline-none focus:border-primary/50 transition-all">
                    </div>
                    <button type="button" onclick="clearFilters()" class="h-[52px] w-[52px] rounded-2xl bg-white/[0.02] border border-white/5 flex items-center justify-center text-primary hover:bg-white/5 transition-all shadow-lg active:scale-95 group" title="Clear Filters">
                        <span class="material-symbols-rounded text-xl transition-transform group-hover:rotate-180 duration-500">refresh</span>
                    </button>
                    <div>
                        <button type="button" onclick="exportReportToPDF(true)"
                            class="h-[52px] w-full px-5 rounded-2xl bg-white/5 border border-white/5 text-[10px] font-black uppercase tracking-widest text-[--text-main]/60 hover:text-[--text-main] hover:bg-white/10 transition-all flex items-center justify-center gap-2">
                            <span class="material-symbols-rounded text-sm">visibility</span>
                            Preview
                        </button>
                    </div>
                    <div>
                        <button type="button" onclick="exportReportToPDF(false)"
                            class="h-[52px] w-full px-5 rounded-2xl border border-primary/20 text-[10px] font-black uppercase tracking-widest text-primary hover:bg-primary/10 transition-all flex items-center justify-center gap-2.5 active:scale-95 shadow-lg shadow-primary/20">
                            <span class="material-symbols-rounded text-sm">picture_as_pdf</span>
                            Export PDF
                        </button>
                    </div>
                </form>
                </div>

            <!-- Document Matrix -->
            <div id="reportTableContainer">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="bg-white/5 border-b border-white/5">
                                    <?php if ($tab === 'financials'): ?>
                                        <th class="px-8 py-5 table-header-alt">Member ID</th>
                                        <th class="px-8 py-5 table-header-alt">Name</th>
                                        <th class="px-8 py-5 table-header-alt text-center">Type</th>
                                        <th class="px-8 py-5 table-header-alt text-center">Date of Payment</th>
                                        <th class="px-8 py-5 table-header-alt text-center">Ref Number</th>
                                        <th class="px-8 py-5 table-header-alt text-right">Amount</th>
                                    <?php elseif ($tab === 'membership'): ?>
                                        <th class="px-8 py-5 table-header-alt">Member ID</th>
                                        <th class="px-8 py-5 table-header-alt">Name</th>
                                        <th class="px-8 py-5 table-header-alt text-center">Tier Type</th>
                                        <th class="px-8 py-5 table-header-alt text-center">Payment Date</th>
                                        <th class="px-8 py-5 table-header-alt text-center">Renewal Date</th>
                                        <th class="px-8 py-5 table-header-alt text-center">Status</th>
                                    <?php elseif ($tab === 'attendance'): ?>
                                        <th class="px-8 py-5 table-header-alt">Member ID</th>
                                        <th class="px-8 py-5 table-header-alt">Name</th>
                                        <th class="px-8 py-5 table-header-alt text-center">Session Date</th>
                                        <th class="px-8 py-5 table-header-alt text-center">Time In / Out</th>
                                        <th class="px-8 py-5 table-header-alt text-center">Status</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/5">
                                <?php if (empty($query_data)): ?>
                                    <tr>
                                        <td colspan="7" class="px-8 py-24 text-center">
                                            <p
                                                class="text-[11px] font-black uppercase tracking-[0.3em] text-[--text-main]/25">
                                                No records found.</p>
                                        </td>
                                    </tr>
                                <?php else:
                                    foreach ($query_data as $row): ?>
                                        <tr class="hover:bg-white/[0.04] group transition-colors">
                                            <?php if ($tab === 'financials'): ?>
                                                <td class="px-8 py-7">
                                                    <p class="text-[11px] font-black text-[--text-main]/60 tracking-widest">
                                                        <?= !empty($row['member_id']) ? 'ID-' . str_pad($row['member_id'], 5, '0', STR_PAD_LEFT) : '---' ?></p>
                                                </td>
                                                <td class="px-8 py-7">
                                                    <p
                                                        class="text-sm font-bold report-row-text group-hover:text-primary transition-colors">
                                                        <?= htmlspecialchars($row['fullname'] ?: $row['username']) ?></p>
                                                </td>
                                                <td class="px-8 py-7 text-center">
                                                    <p class="text-[11px] font-black report-row-text text-center"><?= htmlspecialchars($row['transaction_type']) ?></p>
                                                </td>
                                                <td class="px-8 py-7 text-center">
                                                    <p class="text-[11px] text-[--text-main]/45 font-bold"><?= date('M d, Y', strtotime($row['created_at'])) ?></p>
                                                </td>
                                                <td class="px-8 py-7 text-center">
                                                    <span
                                                        class="text-[11px] text-[--text-main]/60 font-black tracking-wider"><?= htmlspecialchars($row['reference_number'] ?: '#' . str_pad($row['payment_id'] ?? 0, 5, '0', STR_PAD_LEFT)) ?></span>
                                                </td>
                                                <td class="px-8 py-7 text-right">
                                                    <span class="text-primary font-black text-sm">₱<?= number_format($row['amount'], 2) ?></span>
                                                </td>
                                            <?php elseif ($tab === 'membership'): ?>
                                                <td class="px-8 py-7">
                                                    <p class="text-[11px] font-black text-[--text-main]/60 tracking-widest">
                                                        ID-<?= str_pad($row['member_id'], 5, '0', STR_PAD_LEFT) ?></p>
                                                </td>
                                                <td class="px-8 py-7">
                                                    <p class="text-sm font-bold report-row-text"><?= htmlspecialchars($row['fullname']) ?></p>
                                                </td>
                                                <td class="px-8 py-7 text-center">
                                                    <p class="text-[11px] font-black report-row-text"><?= htmlspecialchars($row['plan_name']) ?> Membership</p>
                                                </td>
                                                <td class="px-8 py-7 text-center">
                                                    <p class="text-[11px] text-[--text-main]/45 font-bold"><?= date('M d, Y', strtotime($row['created_at'] ?? $row['start_date'])) ?></p>
                                                </td>
                                                <td class="px-8 py-7 text-center">
                                                    <p class="text-[11px] text-[--text-main]/45 font-bold"><?= date('M d, Y', strtotime($row['end_date'])) ?></p>
                                                </td>
                                                <td class="px-8 py-7 text-center">
                                                    <?php
                                                    $s = $row['subscription_status'] ?? 'Draft';
                                                    $sc = ($s == 'Active') ? 'emerald' : (($s == 'Expiring') ? 'amber' : 'rose');
                                                    ?>
                                                    <span
                                                        class="px-3 py-1.5 rounded-xl bg-<?= $sc ?>-500/10 border border-<?= $sc ?>-500/20 text-[9px] text-<?= $sc ?>-500 font-black uppercase tracking-widest"><?= $s ?></span>
                                                </td>
                                            <?php elseif ($tab === 'attendance'): ?>
                                                <td class="px-8 py-7">
                                                    <p class="text-[11px] font-black text-[--text-main]/60 tracking-widest">
                                                        ID-<?= str_pad($row['member_id'], 5, '0', STR_PAD_LEFT) ?></p>
                                                </td>
                                                <td class="px-8 py-7">
                                                    <p class="text-sm font-bold report-row-text">
                                                        <?= htmlspecialchars($row['fullname']) ?></p>
                                                </td>
                                                <td class="px-8 py-7 text-center">
                                                    <p class="text-[11px] font-black report-row-text text-center">
                                                        <?= date('M d, Y', strtotime($row['attendance_date'])) ?></p>
                                                </td>
                                                <td class="px-8 py-7 text-center">
                                                    <p class="text-[11px] font-black text-emerald-400">
                                                        <?= date('h:i A', strtotime($row['attendance_date'] . ' ' . $row['check_in_time'])) ?>
                                                        <span class="mx-2 text-[--text-main]/40">-</span>
                                                        <span class="text-[--text-main]/45"><?= !empty($row['check_out_time']) ? date('h:i A', strtotime($row['attendance_date'] . ' ' . $row['check_out_time'])) : '---' ?></span>
                                                    </p>
                                                </td>
                                                <td class="px-8 py-7 text-center">
                                                    <?php $attStatus = strtolower($row['attendance_status'] ?? ''); ?>
                                                    <?php if ($attStatus === 'did not checked out' || $attStatus === 'no time out' || $attStatus === 'no timeout'): ?>
                                                        <span class="px-3 py-1.5 rounded-xl bg-red-500/10 border border-red-500/20 text-[9px] text-red-500 font-black uppercase tracking-widest">No Time Out</span>
                                                    <?php elseif (empty($row['check_out_time'])): ?>
                                                        <span
                                                            class="px-3 py-1.5 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-[9px] text-emerald-500 font-black uppercase tracking-widest inline-flex items-center gap-2 justify-center"><span class="size-1.5 rounded-full bg-emerald-500"></span>In Gym</span>
                                                    <?php else: ?>
                                                        <span
                                                            class="px-3 py-1.5 rounded-xl bg-white/5 border border-white/10 text-[9px] text-[--text-main]/45 font-black uppercase tracking-widest">Timed Out</span>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; endif; ?>
                            </tbody>
                            <?php if ($tab === 'financials'): ?>
                                <tfoot class="border-t border-white/5 bg-primary/5">
                                    <tr class="font-black uppercase tracking-widest">
                                        <td colspan="5" class="px-8 py-6 text-primary text-xs tracking-[0.15em]">Filtered Period Total:</td>
                                        <td class="px-8 py-6 text-right text-primary text-xl">
                                            ₱<?= number_format($filtered_total, 2) ?></td>
                                    </tr>
                                </tfoot>
                            <?php elseif ($tab === 'membership'): ?>
                                <tfoot class="border-t border-white/5 bg-primary/5 shadow-inner">
                                    <tr class="font-black uppercase tracking-widest">
                                        <td colspan="5" class="px-8 py-6 text-primary text-xs tracking-[0.15em]">Membership Revenue Total:</td>
                                        <td class="px-8 py-6 text-right text-primary text-xl">
                                            ₱<?= number_format($membership_filtered, 2) ?></td>
                                    </tr>
                                </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>
                    <div class="px-8 py-5 border-t border-white/5 bg-white/[0.01] flex justify-between items-center">
                        <p class="text-[10px] font-black uppercase tracking-[0.15em] text-[--text-main]/45">
                            Showing <?= count($query_data) ? '1' : '0' ?> to <?= count($query_data) ?> of <?= count($query_data) ?> records
                        </p>
                        <div class="flex items-center gap-2">
                            <button type="button" disabled class="px-5 py-3 rounded-xl bg-white/[0.02] text-[10px] font-black uppercase tracking-widest text-[--text-main]/20">Prev</button>
                            <button type="button" class="px-5 py-3 rounded-xl bg-primary text-white text-[10px] font-black uppercase tracking-widest">1</button>
                            <button type="button" disabled class="px-5 py-3 rounded-xl bg-white/[0.02] text-[10px] font-black uppercase tracking-widest text-[--text-main]/20">Next</button>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>

</html>
