<?php
session_start();
require_once '../db.php';
require_once '../includes/mailer.php';

// Security Check: Only Staff and Admin/Coach can access (Modify based on gym roles)
$role = strtolower($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || ($role !== 'staff' && $role !== 'coach' && $role !== 'admin' && $role !== 'superadmin' && $role !== 'tenant')) {
    header("Location: ../login.php");
    exit;
}

$gym_id = $_SESSION['gym_id'];
$admin_name = $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];
$admin_id = $_SESSION['user_id'];

// --- PROCESS REFUND STATUS UPDATE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['refund_id'])) {
    $refund_id = (int)$_POST['refund_id'];
    $action = $_POST['action']; // 'approve' or 'reject'
    $new_status = ($action === 'approve') ? 'Approved' : 'Rejected';
    
    // Fetch details for email
    $stmtRef = $pdo->prepare("
        SELECT rr.*, u.email, u.first_name, u.last_name, b.booking_date, b.start_time, sc.service_name, g.gym_name
        FROM refund_requests rr
        JOIN users u ON rr.user_id = u.user_id
        JOIN bookings b ON rr.booking_id = b.booking_id
        JOIN gyms g ON rr.gym_id = g.gym_id
        JOIN service_catalog sc ON b.catalog_service_id = sc.catalog_service_id
        WHERE rr.refund_request_id = ? AND rr.gym_id = ?
    ");
    $stmtRef->execute([$refund_id, $gym_id]);
    $refundData = $stmtRef->fetch(PDO::FETCH_ASSOC);

    if ($refundData && $refundData['status'] === 'Pending') {
        $updateStmt = $pdo->prepare("UPDATE refund_requests SET status = ?, processed_by = ?, processed_at = NOW() WHERE refund_request_id = ?");
        $updateStmt->execute([$new_status, $admin_id, $refund_id]);

        // Send Email Notification to User
        $memberName = $refundData['first_name'] . ' ' . $refundData['last_name'];
        $subject = "Refund Request " . strtoupper($new_status);
        $gymName = $refundData['gym_name'];
        
        $color = $new_status === 'Approved' ? '#10B981' : '#EF4444';
        
        $emailContent = "
            <p>Hello $memberName,</p>
            <p>Your refund and cancellation request has been processed.</p>
            <div style='background: #f8f9fa; padding: 20px; border-radius: 10px; margin: 20px 0;'>
                <p style='margin: 5px 0;'><strong>Service:</strong> {$refundData['service_name']}</p>
                <p style='margin: 5px 0;'><strong>Schedule:</strong> " . date('M d, Y', strtotime($refundData['booking_date'])) . " at " . date('h:i A', strtotime($refundData['start_time'])) . "</p>
                <p style='margin: 5px 0;'><strong>Reason Provided:</strong> " . htmlspecialchars($refundData['reason']) . "</p>
                <p style='margin: 5px 0;'><strong>Final Status:</strong> <span style='color: $color; font-weight: bold;'>$new_status</span></p>
            </div>
            <p>If approved, please coordinate with the gym staff for the release of funds. If rejected, it means your request did not meet the gym's policy.</p>
        ";
        $fullBody = getFormalEmailTemplate($subject, $emailContent, $gymName);
        sendSystemEmail($refundData['email'], $subject, $fullBody);
        
        header("Location: admin_refunds.php?msg=success");
        exit;
    }
}

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

function getRefundAvatarPath($path) {
    if (empty($path)) return '';
    if (strpos($path, 'data:') === 0 || strpos($path, 'http') === 0) return $path;
    $cleanPath = ltrim($path, './');
    if (strpos($cleanPath, 'uploads/') === 0) return '../' . $cleanPath;
    return '../uploads/profile_pics/' . $cleanPath;
}

// Fetch Gym & Owner Details for Branding
$stmtGymBranding = $pdo->prepare("SELECT owner_user_id, gym_name, profile_picture FROM gyms WHERE gym_id = ?");
$stmtGymBranding->execute([$gym_id]);
$gym_data = $stmtGymBranding->fetch();
$owner_user_id = $gym_data['owner_user_id'] ?? 0;
$gym_name = $gym_data['gym_name'] ?? 'Horizon Gym';

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

$stmtGlobal = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = 0");
$stmtGlobal->execute();
foreach (($stmtGlobal->fetchAll(PDO::FETCH_KEY_PAIR) ?: []) as $k => $v) {
    if ($v !== null && $v !== '') $configs[$k] = $v;
}

$stmtTenant = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = ?");
$stmtTenant->execute([$owner_user_id]);
foreach (($stmtTenant->fetchAll(PDO::FETCH_KEY_PAIR) ?: []) as $k => $v) {
    if ($v !== null && $v !== '') $configs[$k] = $v;
}

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
$system_logo = $configs['system_logo'] ?: ($gym_data['profile_picture'] ?? '');

$limit = 10;
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int) $_GET['page'] : 1;
if ($current_page < 1) $current_page = 1;
$offset = ($current_page - 1) * $limit;

$search = $_GET['search'] ?? '';
$filter_status = $_GET['status'] ?? '';
$user_filter = $_GET['user_id'] ?? 'all';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$today_filter_date = date('Y-m-d');

if (!in_array($filter_status, ['', 'Pending', 'Approved', 'Rejected'], true)) $filter_status = '';
if ($user_filter !== 'all' && !ctype_digit((string) $user_filter)) $user_filter = 'all';
if ($date_from !== '' && $date_from > $today_filter_date) $date_from = $today_filter_date;
if ($date_to !== '' && $date_to > $today_filter_date) $date_to = $today_filter_date;
if ($date_from !== '' && $date_to !== '' && $date_from > $date_to) $date_from = $date_to;

$where_parts = ["rr.gym_id = :gym_id"];
$sql_params = [':gym_id' => $gym_id];

if ($search !== '') {
    $where_parts[] = "(u.first_name LIKE :s1 OR u.last_name LIKE :s2 OR u.email LIKE :s3 OR sc.service_name LIKE :s4 OR rr.reason LIKE :s5)";
    $sql_params[':s1'] = "%$search%";
    $sql_params[':s2'] = "%$search%";
    $sql_params[':s3'] = "%$search%";
    $sql_params[':s4'] = "%$search%";
    $sql_params[':s5'] = "%$search%";
}
if ($filter_status !== '') {
    $where_parts[] = "rr.status = :status";
    $sql_params[':status'] = $filter_status;
}
if ($user_filter !== 'all') {
    $where_parts[] = "u.user_id = :user_id";
    $sql_params[':user_id'] = (int) $user_filter;
}
if ($date_from !== '') {
    $where_parts[] = "DATE(rr.created_at) >= :date_from";
    $sql_params[':date_from'] = $date_from;
}
if ($date_to !== '') {
    $where_parts[] = "DATE(rr.created_at) <= :date_to";
    $sql_params[':date_to'] = $date_to;
}

$where_clause = "WHERE " . implode(' AND ', $where_parts);

$stmtCount = $pdo->prepare("
    SELECT COUNT(*)
    FROM refund_requests rr
    JOIN users u ON rr.user_id = u.user_id
    JOIN bookings b ON rr.booking_id = b.booking_id
    JOIN service_catalog sc ON b.catalog_service_id = sc.catalog_service_id
    $where_clause
");
$stmtCount->execute($sql_params);
$total_records = (int) $stmtCount->fetchColumn();
$total_pages = max(1, (int) ceil($total_records / $limit));
if ($current_page > $total_pages) {
    $current_page = $total_pages;
    $offset = ($current_page - 1) * $limit;
}

$stmtRefunds = $pdo->prepare("
    SELECT rr.*, u.user_id, u.first_name, u.last_name, u.email, u.contact_number, COALESCE(m.profile_picture, u.profile_picture) as profile_picture, b.booking_date, b.start_time, sc.service_name
    FROM refund_requests rr
    JOIN users u ON rr.user_id = u.user_id
    LEFT JOIN members m ON m.user_id = u.user_id AND m.gym_id = rr.gym_id
    JOIN bookings b ON rr.booking_id = b.booking_id
    JOIN service_catalog sc ON b.catalog_service_id = sc.catalog_service_id
    $where_clause
    ORDER BY rr.created_at DESC
    LIMIT :limit OFFSET :offset
");
foreach ($sql_params as $key => $val) {
    $isInt = in_array($key, [':gym_id', ':user_id'], true);
    $stmtRefunds->bindValue($key, $isInt ? (int) $val : $val, $isInt ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmtRefunds->bindValue(':limit', (int) $limit, PDO::PARAM_INT);
$stmtRefunds->bindValue(':offset', (int) $offset, PDO::PARAM_INT);
$stmtRefunds->execute();
$refunds = $stmtRefunds->fetchAll(PDO::FETCH_ASSOC);
$is_sample_refunds = false;
if (empty($refunds)) {
    $is_sample_refunds = true;
    $refunds = [
        [
            'refund_request_id' => 0,
            'first_name' => 'Julienne',
            'last_name' => 'Flores',
            'email' => 'sample.member@email.com',
            'contact_number' => '0912-345-6789',
            'profile_picture' => '',
            'service_name' => 'Personal Training',
            'booking_date' => date('Y-m-d'),
            'start_time' => '18:30:00',
            'reason' => 'Sample reason: member requested cancellation due to schedule conflict.',
            'status' => 'Pending',
            'created_at' => date('Y-m-d H:i:s'),
            'processed_at' => null,
            'refund_amount' => 500,
        ],
        [
            'refund_request_id' => 0,
            'first_name' => 'Andrei',
            'last_name' => 'Mangalus',
            'email' => 'sample.client@email.com',
            'contact_number' => '0923-456-7890',
            'profile_picture' => '',
            'service_name' => 'Strength Coaching',
            'booking_date' => date('Y-m-d', strtotime('+1 day')),
            'start_time' => '10:00:00',
            'reason' => 'Sample reason: refund review already approved for display testing.',
            'status' => 'Approved',
            'created_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
            'processed_at' => date('Y-m-d H:i:s'),
            'refund_amount' => 750,
        ],
        [
            'refund_request_id' => 0,
            'first_name' => 'Alex',
            'last_name' => 'Estanislao',
            'email' => 'sample.user@email.com',
            'contact_number' => '0934-567-8901',
            'profile_picture' => '',
            'service_name' => 'Boxing Session',
            'booking_date' => date('Y-m-d', strtotime('+2 days')),
            'start_time' => '15:00:00',
            'reason' => 'Sample reason: request rejected after policy review.',
            'status' => 'Rejected',
            'created_at' => date('Y-m-d H:i:s', strtotime('-2 days')),
            'processed_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
            'refund_amount' => 350,
        ],
    ];
}

$stmtStats = $pdo->prepare("
    SELECT COUNT(*) AS total_count,
           SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pending_count,
           SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) AS approved_count,
           SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) AS rejected_count
    FROM refund_requests
    WHERE gym_id = ?
");
$stmtStats->execute([$gym_id]);
$refund_stats = $stmtStats->fetch() ?: [];
$total_refunds = (int) ($refund_stats['total_count'] ?? 0);
$pending_refunds = (int) ($refund_stats['pending_count'] ?? 0);
$approved_refunds = (int) ($refund_stats['approved_count'] ?? 0);
$rejected_refunds = (int) ($refund_stats['rejected_count'] ?? 0);

$stmtAllUsers = $pdo->prepare("
    SELECT DISTINCT u.user_id, CONCAT(u.first_name, ' ', u.last_name) AS full_name
    FROM refund_requests rr
    JOIN users u ON rr.user_id = u.user_id
    WHERE rr.gym_id = ?
    ORDER BY u.first_name ASC, u.last_name ASC
");
$stmtAllUsers->execute([$gym_id]);
$all_users_list = $stmtAllUsers->fetchAll(PDO::FETCH_ASSOC);
$users_js = array_map(fn($user) => ['id' => (string) $user['user_id'], 'name' => trim($user['full_name'])], $all_users_list);
$user_name_map = array_column($users_js, 'name', 'id');

$active_page = "refunds";
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
    <title>Refund Requests | Horizon Partners</title>
    <link href="https://fonts.googleapis.com/css2?family=<?= urlencode($font_family) ?>:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet" />
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        const availableUsers = <?= json_encode($users_js, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const currentUserFilter = <?= json_encode((string) $user_filter) ?>;

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
            --background-rgb: <?= hexToRgb($bg_color) ?>;
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
        }

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
            transition: all 0.2s ease;
            text-decoration: none;
            white-space: nowrap;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #94a3b8;
        }

        .nav-item:hover {
            background: rgba(255, 255, 255, 0.05);
            color: white;
        }

        .nav-item.active {
            color: <?= $theme_color ?> !important;
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
            background: <?= $theme_color ?>;
            border-radius: 4px 0 0 4px;
        }
        
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        .table-header-alt {
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.25em;
            color: var(--text-main);
            opacity: 0.5;
        }

        .status-card-blue { border: 1px solid rgba(var(--primary-rgb), 0.18); background: linear-gradient(135deg, rgba(var(--primary-rgb), 0.05), rgba(var(--primary-rgb), 0.01)); }
        .status-card-yellow { border: 1px solid rgba(245, 158, 11, 0.25); background: linear-gradient(135deg, rgba(245, 158, 11, 0.05), rgba(245, 158, 11, 0.01)); }
        .status-card-green { border: 1px solid rgba(16, 185, 129, 0.25); background: linear-gradient(135deg, rgba(16, 185, 129, 0.05), rgba(16, 185, 129, 0.01)); }
        .status-card-red { border: 1px solid rgba(244, 63, 94, 0.25); background: linear-gradient(135deg, rgba(244, 63, 94, 0.05), rgba(244, 63, 94, 0.01)); }

        .pagination-btn {
            padding: 8px 16px; border-radius: 10px; background: rgba(255,255,255,.03);
            border: 1px solid rgba(255,255,255,.05); color: var(--text-main);
            font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .1em;
            transition: all .2s ease;
        }
        .pagination-btn:hover:not(.disabled), .pagination-btn.active { background: var(--primary); color: #fff; border-color: var(--primary); }
        .pagination-btn.disabled { opacity: .2; pointer-events: none; }
        .pagination-status { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .15em; color: var(--text-main); opacity: .5; }

        .selected-option { background-color: var(--primary) !important; color: #fff !important; }
        .custom-select-dropdown, .searchable-dropdown-overlay {
            background: #141216; border: 1px solid rgba(255,255,255,.10);
            box-shadow: 0 18px 45px rgba(0,0,0,.45); scrollbar-width: none;
        }
        .custom-select-dropdown::-webkit-scrollbar, .searchable-dropdown-overlay::-webkit-scrollbar { display: none; }
        .tenant-option { border: 1px solid transparent; cursor: pointer; transition: all .2s ease; }
        .tenant-option:hover { background: rgba(var(--primary-rgb), .08); border-color: rgba(var(--primary-rgb), .12); color: var(--primary); }
        .tenant-option.selected { background: var(--primary); color: #fff; }

        input[type="date"] { color-scheme: dark; }
        input[type="date"]::-webkit-calendar-picker-indicator { filter: invert(1) brightness(1.35); opacity: .75; cursor: pointer; }

        #refundDetailModal,
        #refundActionModal {
            position: fixed; top: 0; right: 0; bottom: 0; left: 110px;
            z-index: 2000; display: none; align-items: center; justify-content: center; padding: 24px;
            background: rgba(var(--background-rgb), .4); backdrop-filter: blur(20px) saturate(180%);
            transition: left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .side-nav:hover~#refundDetailModal,
        .side-nav:hover~#refundActionModal { left: 300px; }
        #refundDetailModal.active,
        #refundActionModal.active { display: flex; }
        .refund-modal-panel {
            width: 100%; max-width: 600px; background: var(--card-bg);
            border: 1px solid rgba(255,255,255,.05); border-radius: 28px;
            overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,.5);
        }
    </style>
    <script>
        let filterTimeout;
        function autoSubmitFilters(delay = 350) {
            clearTimeout(filterTimeout);
            filterTimeout = setTimeout(() => document.getElementById('refundFilterForm')?.submit(), delay);
        }
        function clearRefundFilters() { window.location.href = 'admin_refunds.php'; }
        function syncRefundDateLimits() {
            const fromInput = document.getElementById('date_from');
            const toInput = document.getElementById('date_to');
            if (!fromInput || !toInput) return;
            const today = new Date().toISOString().split('T')[0];
            fromInput.max = toInput.value || today;
            toInput.max = today;
            toInput.min = fromInput.value || '';
            if (fromInput.value && fromInput.value > today) fromInput.value = today;
            if (toInput.value && toInput.value > today) toInput.value = today;
            if (fromInput.value && toInput.value && fromInput.value > toInput.value) fromInput.value = toInput.value;
        }
        function toggleCustomDropdown(trigger, event) {
            event.stopPropagation();
            const dropdown = trigger.nextElementSibling;
            const container = trigger.closest('.custom-select-container');
            document.getElementById('userDropdown')?.classList.add('hidden');
            document.querySelectorAll('.custom-select-dropdown').forEach((item) => { if (item !== dropdown) item.classList.add('hidden'); });
            document.querySelectorAll('.custom-select-container').forEach((item) => { if (item !== container) item.classList.remove('is-open'); });
            dropdown.classList.toggle('hidden');
            container.classList.toggle('is-open', !dropdown.classList.contains('hidden'));
        }
        function initSearchableDropdown() {
            const input = document.getElementById('userSearchInput');
            const dropdown = document.getElementById('userDropdown');
            const list = document.getElementById('userOptionsList');
            if (!input || !dropdown || !list) return;
            const escapeHtml = (value) => String(value).replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
            const renderOptions = (filter = '') => {
                const searchFilter = filter === 'All Users' ? '' : filter.toLowerCase().trim();
                const filtered = availableUsers.filter((user) => user.name.toLowerCase().includes(searchFilter));
                list.innerHTML = filtered.map((user) => `<div class="tenant-option px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider ${String(currentUserFilter) === String(user.id) ? 'selected' : 'text-white/60'}" data-id="${escapeHtml(user.id)}" data-name="${escapeHtml(user.name)}">${escapeHtml(user.name)}</div>`).join('') || '<div class="px-4 py-3 text-[9px] text-white/20 uppercase font-black">No user found...</div>';
            };
            input.addEventListener('focus', () => { document.querySelectorAll('.custom-select-dropdown').forEach((item) => item.classList.add('hidden')); dropdown.classList.remove('hidden'); renderOptions(input.value); });
            input.addEventListener('input', (event) => { dropdown.classList.remove('hidden'); renderOptions(event.target.value); });
            renderOptions('');
        }
        document.addEventListener('click', (event) => {
            const tenantOption = event.target.closest('.tenant-option');
            if (tenantOption) {
                const container = tenantOption.closest('#userSearchContainer');
                container.querySelector('#hidden_user_id').value = tenantOption.dataset.id || 'all';
                container.querySelector('#userSearchInput').value = tenantOption.dataset.name || 'All Users';
                container.querySelector('#userDropdown').classList.add('hidden');
                container.closest('form')?.submit();
                return;
            }
            const customOption = event.target.closest('.custom-option');
            if (customOption) {
                const container = customOption.closest('.custom-select-container');
                container.querySelector('input[type="hidden"]').value = customOption.dataset.value;
                container.querySelector('input[type="text"]').value = customOption.textContent.trim();
                container.querySelector('.custom-select-dropdown').classList.add('hidden');
                container.closest('form')?.submit();
                return;
            }
            if (!event.target.closest('.custom-select-container')) document.querySelectorAll('.custom-select-dropdown').forEach((item) => item.classList.add('hidden'));
            if (!event.target.closest('#userSearchContainer')) document.getElementById('userDropdown')?.classList.add('hidden');
        });
        window.addEventListener('DOMContentLoaded', () => { syncRefundDateLimits(); initSearchableDropdown(); });
        function openRefundDetailModal(data) {
            document.getElementById('refund_detail_name').textContent = data.name;
            document.getElementById('refund_detail_email').textContent = data.email || 'N/A';
            document.getElementById('refund_detail_initials').textContent = data.initials || 'U';
            const avatarEl = document.getElementById('refund_detail_avatar_img');
            if (data.avatar) {
                avatarEl.src = data.avatar;
                avatarEl.classList.remove('hidden');
            } else {
                avatarEl.removeAttribute('src');
                avatarEl.classList.add('hidden');
            }
            document.getElementById('refund_detail_contact').textContent = data.contact || 'N/A';
            document.getElementById('refund_detail_ref').textContent = data.ref || 'REFUND-00000';
            document.getElementById('refund_detail_service').textContent = data.service || 'N/A';
            document.getElementById('refund_detail_date').textContent = data.date || 'N/A';
            document.getElementById('refund_detail_time').textContent = data.time || 'N/A';
            document.getElementById('refund_detail_requested').textContent = data.requested || 'N/A';
            document.getElementById('refund_detail_processed').textContent = data.processed || 'Not processed yet';
            document.getElementById('refund_detail_amount').textContent = data.amount || 'N/A';
            document.getElementById('refund_detail_reason').textContent = data.reason || 'N/A';
            const statusEl = document.getElementById('refund_detail_status');
            statusEl.textContent = data.status || 'Pending';
            statusEl.className = 'inline-flex px-3 py-1 rounded-full border text-[8px] font-black uppercase tracking-widest ';
            if (data.status === 'Approved') statusEl.className += 'text-emerald-500 bg-emerald-500/10 border-emerald-500/20';
            else if (data.status === 'Rejected') statusEl.className += 'text-rose-500 bg-rose-500/10 border-rose-500/20';
            else statusEl.className += 'text-amber-500 bg-amber-500/10 border-amber-500/20';
            document.getElementById('refundDetailModal').classList.add('active');
        }
        function closeRefundDetailModal() {
            document.getElementById('refundDetailModal')?.classList.remove('active');
        }
        let pendingRefundForm = null;
        function confirmRefundAction(form, action) {
            pendingRefundForm = form;
            const isApprove = action === 'approve';
            document.getElementById('refund_action_icon').textContent = isApprove ? 'check_circle' : 'cancel';
            document.getElementById('refund_action_icon').className = 'material-symbols-rounded text-4xl ' + (isApprove ? 'text-emerald-500' : 'text-rose-500');
            document.getElementById('refund_action_title').textContent = isApprove ? 'Approve Refund' : 'Reject Refund';
            document.getElementById('refund_action_label').textContent = isApprove ? 'Release Request' : 'Decline Request';
            document.getElementById('refund_action_label').className = 'text-[10px] font-bold uppercase tracking-widest mt-1 ' + (isApprove ? 'text-emerald-500' : 'text-rose-500');
            document.getElementById('refund_action_message').textContent = isApprove
                ? 'Approve this refund request? An email will be sent to the member.'
                : 'Reject this refund request? An email will be sent to the member.';
            const submitBtn = document.getElementById('refund_action_submit');
            submitBtn.textContent = isApprove ? 'Approve' : 'Reject';
            submitBtn.className = 'flex-1 py-4 rounded-2xl text-white text-[10px] font-black uppercase tracking-widest shadow-lg transition-all active:scale-[0.98] ' + (isApprove ? 'bg-emerald-500 hover:bg-emerald-600 shadow-emerald-500/20' : 'bg-rose-500 hover:bg-rose-600 shadow-rose-500/20');
            document.getElementById('refundActionModal').classList.add('active');
        }
        function closeRefundActionModal() {
            document.getElementById('refundActionModal')?.classList.remove('active');
            pendingRefundForm = null;
        }
        function submitRefundAction() {
            if (pendingRefundForm) {
                pendingRefundForm.submit();
                return;
            }
            closeRefundActionModal();
        }
        function showSampleAction(action) {
            pendingRefundForm = null;
            confirmRefundAction(null, String(action).toLowerCase());
            document.getElementById('refund_action_message').textContent = 'Sample only: ' + action + ' button preview. No database changes will be made.';
        }
        document.addEventListener('keydown', (event) => { if (event.key === 'Escape') { closeRefundDetailModal(); closeRefundActionModal(); } });
        function updateHeaderClock() {
            const now = new Date();
            const clockEl = document.getElementById('headerClock');
            if (clockEl) {
                clockEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            }
        }
        setInterval(updateHeaderClock, 1000);
        window.addEventListener('DOMContentLoaded', updateHeaderClock);
    </script>
</head>
<body class="antialiased flex h-screen overflow-hidden">
    <?php include '../includes/admin_sidebar.php'; ?>

    <div class="main-content flex-1 overflow-y-auto no-scrollbar">
        <main class="p-10 max-w-[1400px] mx-auto pb-20">
            <header class="mb-10 flex justify-between items-end gap-6">
                <div>
                    <h2 class="text-3xl font-black uppercase tracking-tighter italic" style="color:var(--text-main)">
                        Refund <span style="color:var(--primary)" class="italic">Requests</span>
                    </h2>
                    <p class="text-[10px] font-black uppercase tracking-widest mt-1 opacity-50" style="color:var(--text-main)">Manage Cancellations & Refunds</p>
                </div>
                <div class="flex flex-col items-end text-right shrink-0">
                    <p id="headerClock" class="font-black text-2xl leading-none tracking-tighter uppercase" style="color:var(--text-main)">00:00:00 AM</p>
                    <p class="text-primary text-[10px] font-black uppercase tracking-[0.2em] leading-none mt-2"><?= date('l, M d, Y') ?></p>
                </div>
            </header>

            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6 mb-10">
                <div class="glass-card p-8 status-card-blue relative overflow-hidden">
                    <span class="material-symbols-rounded absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 text-primary">payments</span>
                    <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">All Refunds</p>
                    <h3 class="text-3xl font-black uppercase text-[--text-main]"><?= $total_refunds ?></h3>
                    <p class="text-[10px] font-black uppercase mt-2 text-primary">Cancellation Requests</p>
                </div>
                <div class="glass-card p-8 status-card-yellow relative overflow-hidden">
                    <span class="material-symbols-rounded absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 text-amber-500">pending_actions</span>
                    <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">Pending</p>
                    <h3 class="text-3xl font-black uppercase text-[--text-main]"><?= $pending_refunds ?></h3>
                    <p class="text-amber-500 text-[10px] font-black uppercase mt-2">Needs Review</p>
                </div>
                <div class="glass-card p-8 status-card-green relative overflow-hidden">
                    <span class="material-symbols-rounded absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 text-emerald-500">verified</span>
                    <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">Approved</p>
                    <h3 class="text-3xl font-black uppercase text-[--text-main]"><?= $approved_refunds ?></h3>
                    <p class="text-emerald-500 text-[10px] font-black uppercase mt-2">Released</p>
                </div>
                <div class="glass-card p-8 status-card-red relative overflow-hidden">
                    <span class="material-symbols-rounded absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 text-rose-500">block</span>
                    <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">Rejected</p>
                    <h3 class="text-3xl font-black uppercase text-[--text-main]"><?= $rejected_refunds ?></h3>
                    <p class="text-rose-500 text-[10px] font-black uppercase mt-2">Declined</p>
                </div>
            </div>

            <div class="glass-card overflow-hidden">
                <div class="p-8 border-b border-white/5 bg-white/[0.01]">
                    <form id="refundFilterForm" method="GET" class="flex flex-wrap items-center gap-5 relative">
                        <div class="flex-1 min-w-[260px] relative group">
                            <span class="material-symbols-rounded absolute left-4 top-1/2 -translate-y-1/2 text-base text-primary/50">search</span>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search records..." autocomplete="off" oninput="autoSubmitFilters()"
                                class="w-full h-[52px] bg-white/5 border border-white/10 rounded-2xl pl-12 pr-4 text-[10px] font-black uppercase tracking-widest outline-none text-[--text-main] hover:border-white/20 transition-all focus:border-primary">
                        </div>

                        <div class="flex-1 min-w-[260px] relative group" id="userSearchContainer">
                            <?php $selectedUserName = ($user_filter === 'all') ? 'All Users' : ($user_name_map[(string) $user_filter] ?? 'All Users'); ?>
                            <input type="hidden" name="user_id" id="hidden_user_id" value="<?= htmlspecialchars((string) $user_filter) ?>">
                            <div class="relative">
                                <span class="material-symbols-rounded absolute left-4 top-1/2 -translate-y-1/2 text-base text-primary/50">person_search</span>
                                <input type="text" id="userSearchInput" value="<?= htmlspecialchars($selectedUserName) ?>" placeholder="Search name..." autocomplete="off"
                                    class="w-full h-[52px] bg-white/5 border border-white/10 rounded-2xl pl-12 pr-11 text-[10px] font-black uppercase tracking-widest outline-none text-[--text-main] hover:border-white/20 transition-all focus:border-primary">
                                <span class="material-symbols-rounded absolute right-4 top-1/2 -translate-y-1/2 text-primary/70 text-base pointer-events-none">expand_more</span>
                            </div>
                            <div id="userDropdown" class="absolute left-0 right-0 top-full mt-2 z-[100] rounded-xl searchable-dropdown-overlay max-h-64 overflow-y-auto hidden">
                                <div class="p-1.5 space-y-0.5">
                                    <div class="tenant-option px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider <?= $user_filter === 'all' ? 'selected' : 'text-white/60' ?>" data-id="all" data-name="All Users">All Users</div>
                                    <div id="userOptionsList"></div>
                                </div>
                            </div>
                        </div>

                        <?php $statusDisplay = $filter_status ?: 'All Status'; ?>
                        <div class="w-[180px] relative group shrink-0 custom-select-container">
                            <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>">
                            <div class="relative cursor-pointer" onclick="toggleCustomDropdown(this, event)">
                                <input type="text" readonly value="<?= htmlspecialchars($statusDisplay) ?>" class="w-full h-[52px] bg-white/5 border border-white/10 rounded-2xl pl-5 pr-11 text-[10px] font-black uppercase tracking-widest outline-none text-[--text-main] cursor-pointer pointer-events-none">
                                <span class="material-symbols-rounded absolute right-4 top-1/2 -translate-y-1/2 text-primary/70 text-base pointer-events-none">expand_more</span>
                            </div>
                            <div class="absolute left-0 right-0 top-full mt-2 z-[100] rounded-xl p-1.5 space-y-0.5 custom-select-dropdown hidden max-h-64 overflow-y-auto">
                                <div class="custom-option px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 <?= $filter_status === '' ? 'selected-option' : 'text-white/60' ?>" data-value="">All Status</div>
                                <div class="custom-option px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 <?= $filter_status === 'Pending' ? 'selected-option' : 'text-white/60' ?>" data-value="Pending">Pending</div>
                                <div class="custom-option px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 <?= $filter_status === 'Approved' ? 'selected-option' : 'text-white/60' ?>" data-value="Approved">Approved</div>
                                <div class="custom-option px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 <?= $filter_status === 'Rejected' ? 'selected-option' : 'text-white/60' ?>" data-value="Rejected">Rejected</div>
                            </div>
                        </div>

                        <input type="date" name="date_from" id="date_from" value="<?= htmlspecialchars($date_from) ?>" max="<?= htmlspecialchars($date_to ?: date('Y-m-d')) ?>" onchange="syncRefundDateLimits(); autoSubmitFilters(0)"
                            class="w-[170px] h-[52px] bg-white/5 border border-white/10 rounded-2xl px-5 text-[10px] font-black uppercase tracking-widest outline-none text-[--text-main]">
                        <input type="date" name="date_to" id="date_to" value="<?= htmlspecialchars($date_to) ?>" min="<?= htmlspecialchars($date_from) ?>" max="<?= date('Y-m-d') ?>" onchange="syncRefundDateLimits(); autoSubmitFilters(0)"
                            class="w-[170px] h-[52px] bg-white/5 border border-white/10 rounded-2xl px-5 text-[10px] font-black uppercase tracking-widest outline-none text-[--text-main]">
                        <button type="button" onclick="clearRefundFilters()" class="h-[52px] w-[52px] rounded-2xl bg-white/5 border border-white/5 flex items-center justify-center text-white/30 hover:text-white hover:bg-white/10 transition-all" title="Reset filters">
                            <span class="material-symbols-rounded text-lg">refresh</span>
                        </button>
                    </form>
                </div>
                <div class="overflow-x-auto no-scrollbar">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="bg-white/5 border-b border-white/5">
                                <th class="px-8 py-5 table-header-alt">Name</th>
                                <th class="px-8 py-5 table-header-alt">Ref No.</th>
                                <th class="px-8 py-5 table-header-alt">Service</th>
                                <th class="px-8 py-5 table-header-alt text-center">Date</th>
                                <th class="px-8 py-5 table-header-alt">Amount</th>
                                <th class="px-8 py-5 table-header-alt text-center">Status</th>
                                <th class="px-8 py-5 table-header-alt text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5 text-sm font-medium">
                            <?php if (empty($refunds)): ?>
                                <tr>
                                    <td colspan="7" class="px-8 py-24 text-center text-[11px] font-black uppercase tracking-[0.3em] text-[--text-main] opacity-20">
                                        No refund requests found.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($refunds as $refundIndex => $r): ?>
                                    <?php
                                        $refundRefId = (int) ($r['refund_request_id'] ?? 0);
                                        $displayRefundRef = 'REFUND-' . str_pad((string) ($refundRefId > 0 ? $refundRefId : ($refundIndex + 1)), 5, '0', STR_PAD_LEFT);
                                        $displayRefundAmount = isset($r['refund_amount']) && $r['refund_amount'] !== null ? 'PHP ' . number_format((float) $r['refund_amount'], 2) : 'N/A';
                                    ?>
                                    <tr class="group hover:bg-white/[0.02] transition-colors">
                                        <td class="px-8 py-6 align-middle">
                                            <div class="flex items-center gap-4">
                                                <?php
                                                $initials = strtoupper(substr($r['first_name'] ?? 'U', 0, 1) . substr($r['last_name'] ?? '', 0, 1));
                                                $avatarPath = getRefundAvatarPath($r['profile_picture'] ?? '');
                                                ?>
                                                <div class="size-11 rounded-full flex items-center justify-center font-black text-[11px] border border-white/10 shrink-0 overflow-hidden shadow-inner relative"
                                                    style="background:rgba(var(--primary-rgb), 0.1); color:var(--primary)">
                                                    <?php if (!empty($avatarPath)): ?>
                                                        <img src="<?= htmlspecialchars($avatarPath) ?>" class="size-full object-cover" alt="">
                                                    <?php else: ?>
                                                        <?= htmlspecialchars($initials) ?>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="min-w-0">
                                                    <p class="text-[13px] font-bold tracking-wide text-[--text-main] truncate">
                                                        <?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?>
                                                    </p>
                                                    <p class="text-[11px] font-semibold text-[--text-main]/50 truncate"><?= htmlspecialchars($r['email']) ?></p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-8 py-6 align-middle">
                                            <p class="text-[11px] font-black uppercase tracking-widest text-[--text-main]/60 whitespace-nowrap">
                                                <?= htmlspecialchars($displayRefundRef) ?>
                                            </p>
                                        </td>
                                        <td class="px-8 py-6 align-middle">
                                            <p class="text-[12px] font-bold text-[--text-main]/70 leading-snug"><?= htmlspecialchars($r['service_name']) ?></p>
                                        </td>
                                        <td class="px-8 py-6 text-center align-middle">
                                            <p class="text-[12px] font-bold whitespace-nowrap" style="color:var(--primary)">
                                                <?= date('M d, Y', strtotime($r['booking_date'])) ?>
                                            </p>
                                            <p class="text-[11px] font-semibold text-[--text-main]/50"><?= date('h:i A', strtotime($r['start_time'])) ?></p>
                                        </td>
                                        <td class="px-8 py-6 align-middle">
                                            <p class="text-[12px] font-black text-[--text-main]/75 whitespace-nowrap">
                                                <?= htmlspecialchars($displayRefundAmount) ?>
                                            </p>
                                        </td>
                                        <td class="px-8 py-6 text-center align-middle">
                                            <?php 
                                            $c = 'text-amber-500 bg-amber-500/10 border-amber-500/20';
                                            if ($r['status'] === 'Approved') $c = 'text-emerald-500 bg-emerald-500/10 border-emerald-500/20';
                                            if ($r['status'] === 'Rejected') $c = 'text-rose-500 bg-rose-500/10 border-rose-500/20';
                                            ?>
                                            <span class="px-4 py-1.5 rounded-full border text-[8px] font-black uppercase tracking-widest <?= $c ?>">
                                                <?= $r['status'] ?>
                                            </span>
                                        </td>
                                        <td class="px-8 py-6 text-center align-middle">
                                            <div class="flex items-center justify-center gap-2">
                                                <button type="button"
                                                    onclick='openRefundDetailModal({
                                                        name: "<?= htmlspecialchars($r["first_name"] . " " . $r["last_name"], ENT_QUOTES) ?>",
                                                        email: "<?= htmlspecialchars($r["email"], ENT_QUOTES) ?>",
                                                        initials: "<?= htmlspecialchars($initials, ENT_QUOTES) ?>",
                                                        avatar: "<?= htmlspecialchars($avatarPath, ENT_QUOTES) ?>",
                                                        contact: "<?= htmlspecialchars($r["contact_number"] ?? "N/A", ENT_QUOTES) ?>",
                                                        ref: "<?= htmlspecialchars($displayRefundRef, ENT_QUOTES) ?>",
                                                        service: "<?= htmlspecialchars($r["service_name"], ENT_QUOTES) ?>",
                                                        date: "<?= date("M d, Y", strtotime($r["booking_date"])) ?>",
                                                        time: "<?= date("h:i A", strtotime($r["start_time"])) ?>",
                                                        requested: "<?= !empty($r["created_at"]) ? date("M d, Y h:i A", strtotime($r["created_at"])) : "N/A" ?>",
                                                        processed: "<?= !empty($r["processed_at"]) ? date("M d, Y h:i A", strtotime($r["processed_at"])) : "Not processed yet" ?>",
                                                        amount: "<?= htmlspecialchars($displayRefundAmount, ENT_QUOTES) ?>",
                                                        reason: "<?= htmlspecialchars($r["reason"], ENT_QUOTES) ?>",
                                                        status: "<?= htmlspecialchars($r["status"], ENT_QUOTES) ?>"
                                                    })'
                                                    class="size-8 rounded-lg bg-white/5 border border-white/10 text-[--text-main]/40 flex items-center justify-center hover:bg-primary hover:text-white transition-all"
                                                    title="View Details">
                                                    <span class="material-symbols-rounded text-base">visibility</span>
                                                </button>
                                                <?php if (!$is_sample_refunds && $r['status'] === 'Pending'): ?>
                                                    <form method="POST">
                                                        <input type="hidden" name="refund_id" value="<?= $r['refund_request_id'] ?>">
                                                        <input type="hidden" name="action" value="approve">
                                                        <button type="button" onclick="confirmRefundAction(this.form, 'approve')" class="size-8 rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-emerald-500 flex items-center justify-center hover:bg-emerald-500 hover:text-white transition-all" title="Approve">
                                                            <span class="material-symbols-rounded text-base">check</span>
                                                        </button>
                                                    </form>
                                                    <form method="POST">
                                                        <input type="hidden" name="refund_id" value="<?= $r['refund_request_id'] ?>">
                                                        <input type="hidden" name="action" value="reject">
                                                        <button type="button" onclick="confirmRefundAction(this.form, 'reject')" class="size-8 rounded-lg bg-rose-500/10 border border-rose-500/20 text-rose-500 flex items-center justify-center hover:bg-rose-500 hover:text-white transition-all" title="Reject">
                                                            <span class="material-symbols-rounded text-base">close</span>
                                                        </button>
                                                    </form>
                                                <?php elseif ($is_sample_refunds): ?>
                                                    <button type="button" onclick="showSampleAction('Approve')" class="size-8 rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-emerald-500 flex items-center justify-center hover:bg-emerald-500 hover:text-white transition-all" title="Sample approve preview">
                                                        <span class="material-symbols-rounded text-base">check</span>
                                                    </button>
                                                    <button type="button" onclick="showSampleAction('Reject')" class="size-8 rounded-lg bg-rose-500/10 border border-rose-500/20 text-rose-500 flex items-center justify-center hover:bg-rose-500 hover:text-white transition-all" title="Sample reject preview">
                                                        <span class="material-symbols-rounded text-base">close</span>
                                                    </button>
                                                <?php else: ?>
                                                    <span class="px-3 py-1 rounded-lg bg-white/5 border border-white/5 text-[9px] font-black uppercase tracking-widest text-[--text-main]/35">Processed</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php
                $showing_start = $total_records > 0 ? $offset + 1 : 0;
                $showing_end = $total_records > 0 ? min($offset + $limit, $total_records) : 0;
                $page_params = $_GET;
                unset($page_params['page']);
                $page_base = 'admin_refunds.php?' . http_build_query($page_params);
                $page_joiner = empty($page_params) ? 'page=' : '&page=';
                ?>
                <div class="px-8 py-5 border-t border-white/5 bg-white/[0.01] flex justify-between items-center">
                    <p class="pagination-status">
                        Showing <?= $showing_start ?> to <?= $showing_end ?> of <?= $total_records ?> refunds
                    </p>
                    <div class="flex items-center gap-2">
                        <a href="<?= htmlspecialchars($page_base . $page_joiner . max(1, $current_page - 1)) ?>" class="pagination-btn <?= ($current_page <= 1) ? 'disabled' : '' ?>">Prev</a>
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <?php if ($i === 1 || $i === $total_pages || ($i >= $current_page - 2 && $i <= $current_page + 2)): ?>
                                <a href="<?= htmlspecialchars($page_base . $page_joiner . $i) ?>" class="pagination-btn <?= ($i === $current_page) ? 'active' : '' ?>"><?= $i ?></a>
                            <?php elseif ($i === $current_page - 3 || $i === $current_page + 3): ?>
                                <span class="text-[--text-main]/20 text-[10px] font-black mx-1">...</span>
                            <?php endif; ?>
                        <?php endfor; ?>
                        <a href="<?= htmlspecialchars($page_base . $page_joiner . min($total_pages, $current_page + 1)) ?>" class="pagination-btn <?= ($current_page >= $total_pages) ? 'disabled' : '' ?>">Next</a>
                    </div>
                </div>
            </div>
        </main>
    </div>
    <div id="refundDetailModal" onclick="if(event.target === this) closeRefundDetailModal()">
        <div class="refund-modal-panel">
            <div class="p-8 border-b border-white/5 flex justify-between items-center gap-4 bg-white/[0.02]">
                <div class="flex items-center gap-4 min-w-0">
                    <div class="size-14 rounded-full flex items-center justify-center font-black text-[12px] border border-white/10 shrink-0 overflow-hidden shadow-inner relative"
                        style="background:rgba(var(--primary-rgb), 0.1); color:var(--primary)">
                        <img id="refund_detail_avatar_img" class="hidden size-full object-cover absolute inset-0" alt="">
                        <span id="refund_detail_initials">U</span>
                    </div>
                    <div class="min-w-0">
                        <h4 id="refund_detail_name" class="text-xl font-black tracking-tight text-[--text-main] leading-tight truncate">Member Name</h4>
                        <p id="refund_detail_email" class="text-[12px] font-bold text-[--text-main]/55 tracking-wide mt-1 truncate">email@example.com</p>
                        <p id="refund_detail_ref" class="text-[10px] font-bold text-primary tracking-wide mt-1">REFUND-00000</p>
                    </div>
                </div>
                <button onclick="closeRefundDetailModal()" class="size-10 rounded-xl bg-white/5 hover:bg-rose-500/20 hover:text-rose-500 transition-all flex items-center justify-center border border-white/5 text-[--text-main]/60 shrink-0">
                    <span class="material-symbols-rounded text-xl">close</span>
                </button>
            </div>
            <div class="p-8 space-y-6">
                <section class="grid grid-cols-1 sm:grid-cols-2 gap-6 bg-white/[0.02] p-6 rounded-2xl border border-white/5">
                    <div class="space-y-1">
                        <p class="text-[10px] font-bold uppercase tracking-widest text-[--text-main]/40">Service</p>
                        <p id="refund_detail_service" class="text-sm font-bold text-[--text-main]">Service</p>
                    </div>
                    <div class="space-y-1">
                        <p class="text-[10px] font-bold uppercase tracking-widest text-[--text-main]/40">Status</p>
                        <span id="refund_detail_status" class="inline-flex px-3 py-1 rounded-full border text-[8px] font-black uppercase tracking-widest">Pending</span>
                    </div>
                    <div class="space-y-1">
                        <p class="text-[10px] font-bold uppercase tracking-widest text-[--text-main]/40">Refund Amount</p>
                        <p id="refund_detail_amount" class="text-sm font-bold text-[--text-main]">N/A</p>
                    </div>
                    <div class="space-y-1">
                        <p class="text-[10px] font-bold uppercase tracking-widest text-[--text-main]/40">Contact</p>
                        <p id="refund_detail_contact" class="text-sm font-medium text-[--text-main]/70">N/A</p>
                    </div>
                </section>
                <section class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                    <div class="bg-white/[0.02] p-5 rounded-2xl border border-white/5 space-y-1">
                        <p class="text-[9px] font-bold uppercase tracking-widest text-[--text-main]/40">Date</p>
                        <p id="refund_detail_date" class="text-xs font-bold text-[--text-main]">Jan 01, 2026</p>
                    </div>
                    <div class="bg-white/[0.02] p-5 rounded-2xl border border-white/5 space-y-1">
                        <p class="text-[9px] font-bold uppercase tracking-widest text-[--text-main]/40">Time</p>
                        <p id="refund_detail_time" class="text-xs font-bold text-[--text-main]">12:00 PM</p>
                    </div>
                    <div class="bg-white/[0.02] p-5 rounded-2xl border border-white/5 space-y-1">
                        <p class="text-[9px] font-bold uppercase tracking-widest text-[--text-main]/40">Requested</p>
                        <p id="refund_detail_requested" class="text-xs font-bold text-[--text-main]">N/A</p>
                    </div>
                    <div class="bg-white/[0.02] p-5 rounded-2xl border border-white/5 space-y-1">
                        <p class="text-[9px] font-bold uppercase tracking-widest text-[--text-main]/40">Processed</p>
                        <p id="refund_detail_processed" class="text-xs font-bold text-[--text-main]">Not processed yet</p>
                    </div>
                </section>
                <section class="bg-white/[0.02] p-6 rounded-2xl border border-white/5 space-y-2">
                    <p class="text-[10px] font-bold uppercase tracking-widest text-[--text-main]/40">Reason</p>
                    <p id="refund_detail_reason" class="text-sm leading-relaxed text-[--text-main]/70">Reason details</p>
                </section>
            </div>
        </div>
    </div>
    <div id="refundActionModal" onclick="if(event.target === this) closeRefundActionModal()">
        <div class="refund-modal-panel max-w-[450px]">
            <div class="p-8 border-b border-white/5 flex justify-between items-center bg-white/[0.02]">
                <div class="flex items-center gap-5 min-w-0">
                    <div class="size-20 rounded-2xl bg-white/5 border border-white/10 overflow-hidden flex items-center justify-center shadow-lg relative shrink-0">
                        <span id="refund_action_icon" class="material-symbols-rounded text-primary text-4xl">check_circle</span>
                    </div>
                    <div class="min-w-0 text-left">
                        <h3 id="refund_action_title" class="text-xl font-black uppercase tracking-tight text-[--text-main] leading-tight truncate">Confirm Refund</h3>
                        <p id="refund_action_label" class="text-[10px] font-bold text-primary uppercase tracking-widest mt-1">Refund Request</p>
                    </div>
                </div>
                <button onclick="closeRefundActionModal()" class="size-10 rounded-xl bg-white/5 hover:bg-rose-500/20 hover:text-rose-500 transition-all flex items-center justify-center border border-white/5 text-[--text-main]/60 shrink-0">
                    <span class="material-symbols-rounded text-xl">close</span>
                </button>
            </div>
            <div class="p-8 space-y-6 text-left">
                <section class="bg-white/[0.02] p-6 rounded-2xl border border-white/5">
                    <p class="text-[10px] font-bold uppercase tracking-widest text-[--text-main]/40 mb-2">Notice</p>
                    <p id="refund_action_message" class="text-sm font-medium leading-relaxed text-[--text-main]/70">Confirm this refund action?</p>
                </section>
                <div class="flex w-full gap-4">
                    <button onclick="closeRefundActionModal()" class="flex-1 py-4 rounded-2xl bg-white/5 hover:bg-white/10 border border-white/5 text-[10px] font-black uppercase tracking-widest transition-all text-[--text-main]/40 hover:text-white">Cancel</button>
                    <button id="refund_action_submit" onclick="submitRefundAction()" class="flex-1 py-4 rounded-2xl bg-primary hover:bg-primary/90 text-white text-[10px] font-black uppercase tracking-widest shadow-lg shadow-primary/20 transition-all active:scale-[0.98]">Proceed</button>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
