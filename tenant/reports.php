<?php
session_start();
require_once '../db.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Security Check
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'tenant') {
    header("Location: ../login.php");
    exit;
}

$gym_id = $_SESSION['gym_id'] ?? null;
$user_id = $_SESSION['user_id'] ?? null;

if (!$user_id || !$gym_id) {
    header("Location: ../login.php");
    exit;
}

// Fetch Filter Inputs
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$date_params = ['gid' => $gym_id, 'start' => $date_from . ' 00:00:00', 'end' => $date_to . ' 23:59:59'];

// Fetch Gym & Owner Details
$stmtGym = $pdo->prepare("
    SELECT g.gym_name, g.email as gym_email, g.contact_number as gym_contact, u.first_name, u.last_name, g.owner_user_id
    FROM gyms g 
    JOIN users u ON g.owner_user_id = u.user_id 
    WHERE g.gym_id = ?
");
$stmtGym->execute([$gym_id]);
$gym_data = $stmtGym->fetch();

$gym_name = $gym_data['gym_name'] ?? 'Horizon Gym';
$first_name = $gym_data['first_name'] ?? 'Owner';
$active_page = "reports";

// ── 4-Color Elite Branding System ─────────────────────────────────────────────
function hexToRgb($hex) {
    if (!$hex) return "0, 0, 0";
    $hex = str_replace("#", "", $hex);
    if (strlen($hex) == 3) {
        $r = hexdec(substr($hex,0,1).substr($hex,0,1));
        $g = hexdec(substr($hex,1,1).substr($hex,1,1));
        $b = hexdec(substr($hex,2,1).substr($hex,2,1));
    } else {
        $r = hexdec(substr($hex,0,2));
        $g = hexdec(substr($hex,2,2));
        $b = hexdec(substr($hex,4,2));
    }
    return "$r, $g, $b";
}

// 1. Hard defaults
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
    'page_slug'       => '',
];

// 2. Merge global settings (user_id = 0)
$stmtGlobal = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = 0");
$stmtGlobal->execute();
foreach (($stmtGlobal->fetchAll(PDO::FETCH_KEY_PAIR) ?: []) as $k => $v) {
    if ($v !== null && $v !== '') $configs[$k] = $v;
}

// 3. Merge tenant-specific settings (user_id = ?)
$stmtTenant = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = ?");
$stmtTenant->execute([$user_id]);
foreach (($stmtTenant->fetchAll(PDO::FETCH_KEY_PAIR) ?: []) as $k => $v) {
    if ($v !== null && $v !== '') $configs[$k] = $v;
}

// 4. Resolved branding tokens
$theme_color     = $configs['theme_color'];
$highlight_color = $configs['secondary_color'];
$text_color      = $configs['text_color'];
$bg_color        = $configs['bg_color'];
$font_family     = $configs['font_family'] ?? 'Lexend';
$auto_card_theme = $configs['auto_card_theme'] ?? '1';
$card_color      = $configs['card_color'];

$primary_rgb   = hexToRgb($theme_color);
$highlight_rgb = hexToRgb($highlight_color);
$card_bg_css   = ($auto_card_theme === '1')
    ? "rgba({$primary_rgb}, 0.05)"
    : $card_color;

// 5. $page convenience array for sidebar
$page = [
    'logo_path'   => $configs['system_logo'] ?? '',
    'theme_color' => $theme_color,
    'bg_color'    => $bg_color,
    'page_slug'   => $configs['page_slug']   ?? '',
    'system_name' => $configs['system_name'] ?? $gym_name,
];
$configs['system_logo'] = $page['logo_path'];

// Fetch Active Subscription / Plan for the Gym
$stmtSub = $pdo->prepare("
    SELECT wp.plan_name 
    FROM client_subscriptions cs 
    JOIN website_plans wp ON cs.website_plan_id = wp.website_plan_id 
    WHERE cs.gym_id = ? 
    ORDER BY cs.created_at DESC LIMIT 1
");
$stmtSub->execute([$gym_id]);
$plan_name = $stmtSub->fetchColumn() ?: 'Standard Plan';

// Fetch Members for Filter Dropdown
$stmtMembers = $pdo->prepare("SELECT m.member_id, u.first_name, u.last_name FROM members m JOIN users u ON m.user_id = u.user_id WHERE m.gym_id = ? AND u.is_active = 1 ORDER BY u.first_name ASC");
$stmtMembers->execute([$gym_id]);
$members_list = $stmtMembers->fetchAll(PDO::FETCH_ASSOC);

$member_filter = $_GET['member_id'] ?? 'all';
$member_condition_p = ($member_filter !== 'all') ? " AND p.member_id = " . (int)$member_filter : "";
$member_condition_a = ($member_filter !== 'all') ? " AND a.member_id = " . (int)$member_filter : "";
$member_condition_ms = ($member_filter !== 'all') ? " AND ms.member_id = " . (int)$member_filter : "";

// Fetch Financial Transactions (Money Reports)
$stmtFinancials = $pdo->prepare("
    SELECT p.payment_id, p.amount, p.payment_method, p.created_at, p.reference_number, p.payment_status,
           COALESCE(u_member.first_name, u_owner.first_name) as first_name, 
           COALESCE(u_member.last_name, u_owner.last_name) as last_name,
           m.member_id,
           mp.plan_name 
    FROM payments p
    LEFT JOIN member_subscriptions ms ON p.subscription_id = ms.subscription_id
    LEFT JOIN membership_plans mp ON ms.membership_plan_id = mp.membership_plan_id
    LEFT JOIN members m ON p.member_id = m.member_id
    LEFT JOIN users u_member ON m.user_id = u_member.user_id
    LEFT JOIN client_subscriptions cs ON p.client_subscription_id = cs.client_subscription_id
    LEFT JOIN users u_owner ON cs.owner_user_id = u_owner.user_id
    WHERE p.gym_id = :gid AND p.client_subscription_id IS NULL AND p.created_at BETWEEN :start AND :end $member_condition_p
    ORDER BY p.created_at DESC
");
$stmtFinancials->execute($date_params);
$financials = $stmtFinancials->fetchAll();

// Auto-timeout pending checkouts past gym closing time
$currentDate = date('Y-m-d');
$currentTime = date('H:i:s');
$stmtAutoTimeout = $pdo->prepare("
    UPDATE attendance a
    JOIN gyms g ON a.gym_id = g.gym_id
    SET a.attendance_status = 'Did Not Checked Out'
    WHERE a.check_out_time IS NULL 
      AND a.attendance_status = 'Active'
      AND (
          a.attendance_date < ? 
          OR (a.attendance_date = ? AND ? > g.closing_time)
      )
");
$stmtAutoTimeout->execute([$currentDate, $currentDate, $currentTime]);

// Fetch Attendance (Entry Logs)
$stmtAttendance = $pdo->prepare("
    SELECT u.first_name, u.last_name, a.check_in_time, a.check_out_time, a.attendance_status, a.recorded_by, m.member_id
    FROM attendance a
    JOIN members m ON a.member_id = m.member_id
    JOIN users u ON m.user_id = u.user_id
    WHERE a.gym_id = :gid AND a.created_at BETWEEN :start AND :end $member_condition_a
    ORDER BY a.created_at DESC
");
$stmtAttendance->execute($date_params);
$attendance_logs = $stmtAttendance->fetchAll();

// Fetch Member Subscriptions (Memberships)
$stmtSubscriptions = $pdo->prepare("
    SELECT u.first_name, u.last_name, mp.plan_name, ms.created_at as payment_date, ms.start_date, ms.end_date, ms.subscription_status, m.member_id
    FROM member_subscriptions ms
    JOIN members m ON ms.member_id = m.member_id
    JOIN users u ON m.user_id = u.user_id
    JOIN membership_plans mp ON ms.membership_plan_id = mp.membership_plan_id
    WHERE m.gym_id = :gid AND ms.created_at BETWEEN :start AND :end $member_condition_ms
    ORDER BY ms.created_at DESC
");
$stmtSubscriptions->execute($date_params);
$subscriptions = $stmtSubscriptions->fetchAll();

$total_money = array_reduce($financials, fn($sum, $f) => $sum + (float) $f['amount'], 0);
$total_entries = count($attendance_logs);
$total_active_subs = count(array_filter($subscriptions, fn($s) => $s['subscription_status'] === 'Active'));

// --- SUBSCRIPTION CHECK FOR RESTRICTION ---
$stmtSubStatus = $pdo->prepare("SELECT subscription_status FROM client_subscriptions WHERE gym_id = ? ORDER BY created_at DESC LIMIT 1");
$stmtSubStatus->execute([$gym_id]);
$sub_status = $stmtSubStatus->fetchColumn() ?: 'None';
$is_sub_active = (strtolower($sub_status) === 'active');
$is_restricted = (!$is_sub_active);
?>


<!DOCTYPE html>
<html class="dark" lang="en">

<head>
    <meta charset="utf-8">
    <title>Reports & Analytics | Horizon</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap"
        rel="stylesheet" />
    <link
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200"
        rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;700&display=swap" rel="stylesheet" />
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "var(--primary)",
                        "background-dark": "var(--background)",
                        "surface-dark": "var(--card-bg)",
                        "border-subtle": "rgba(255,255,255,0.05)"
                    }
                }
            }
        }
    </script>
    <?php
    $members_js = array_map(function ($m) {
        return ['id' => $m['member_id'], 'name' => trim($m['first_name'] . ' ' . $m['last_name'])];
    }, $members_list);
    ?>
    <script>
        const availableMembers = <?= json_encode($members_js) ?>;
        const currentMemberFilter = "<?= $member_filter ?>";
    </script>
    <style>
        .searchable-dropdown-overlay {
            background: var(--background);
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 10px 40px -10px rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(40px);
            z-index: 100;
            scrollbar-width: none;
            margin-top: 0;
        }

        .searchable-dropdown-overlay::-webkit-scrollbar {
            display: none;
        }

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
                    const isAllLabel = filter === "All Members";
                    const searchFilter = isAllLabel ? "" : filter.toLowerCase().trim();

                    const filtered = availableMembers.filter(m =>
                        m.name.toLowerCase().includes(searchFilter)
                    );

                    list.innerHTML = filtered.map(m => `
                        <div class="tenant-option px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider ${currentFilter == m.id ? 'selected' : 'text-white/60'}" 
                             data-id="${m.id}" data-name="${m.name}">
                            ${m.name}
                        </div>
                    `).join('') || `<div class="px-4 py-3 text-[9px] text-white/20 italic uppercase font-black">No member found...</div>`;
                }

                input.addEventListener('focus', () => {
                    dropdown.classList.remove('hidden');
                    const isAllLabel = input.value === "All Members";
                    renderOptions(isAllLabel ? "" : input.value);
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
                        const name = option.dataset.name || "All Members";

                        hiddenInput.value = id;
                        input.value = name;
                        dropdown.classList.add('hidden');

                        container.closest('form').submit();
                    }
                });
            }

            initSearchableDropdown('memberSearchContainer', 'memberSearchInput', 'memberDropdown', 'memberOptionsList', 'hidden_member_id', currentMemberFilter);
        });
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
            overflow: hidden;
        }

        .glass-card {
            background: var(--card-bg);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 24px;
            backdrop-filter: blur(var(--card-blur));
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .hover-lift:hover {
            transform: translateY(-10px);
            border-color: rgba(var(--primary-rgb), 0.4);
            box-shadow: 0 20px 40px -20px rgba(var(--primary-rgb), 0.3);
        }


        /* Sidebar */
        .side-nav {
            width: 110px;
            transition: width 0.4s cubic-bezier(0.4,0,0.2,1);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            position: fixed;
            left: 0; top: 0;
            height: 100vh;
            z-index: 50;
            background-color: var(--background);
            border-right: 1px solid rgba(255,255,255,0.05);
        }
        .side-nav:hover { width: 300px; }

        .main-content {
            margin-left: 110px;
            flex: 1;
            min-width: 0;
            transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .side-nav:hover ~ .main-content { margin-left: 300px; }

        .nav-label {
            opacity: 0; transform: translateX(-15px);
            transition: all 0.3s ease-in-out; white-space: nowrap;
            pointer-events: none; color: var(--text-main);
        }
        .side-nav:hover .nav-label { opacity: 1; transform: translateX(0); pointer-events: auto; }

        .nav-section-label {
            max-height: 0; opacity: 0; overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4,0,0.2,1);
            margin: 0 !important; pointer-events: none;
        }
        .side-nav:hover .nav-section-label {
            max-height: 20px; opacity: 1;
            margin-bottom: 8px !important; pointer-events: auto;
        }

        /* Nav items — no background flash, subtle opacity/scale only */
        .nav-item {
            display: flex; align-items: center; gap: 16px;
            padding: 10px 38px;
            transition: opacity 0.2s ease, color 0.2s ease;
            text-decoration: none; white-space: nowrap;
            font-size: 11px; font-weight: 800;
            text-transform: uppercase; letter-spacing: 0.05em;
            color: color-mix(in srgb, var(--text-main) 45%, transparent);
        }
        .nav-item:hover { color: var(--text-main); }
        .nav-item .material-symbols-outlined { color: var(--highlight); transition: transform 0.2s ease; }
        .nav-item:hover .material-symbols-outlined { transform: scale(1.12); }
        .nav-item.active { color: var(--primary) !important; position: relative; }
        .nav-item.active .material-symbols-outlined { color: var(--primary); }
        .nav-item.active::after {
            content: ''; position: absolute; right: 0; top: 50%; transform: translateY(-50%);
            width: 4px; height: 24px; background: var(--primary); border-radius: 4px 0 0 4px;
        }

        /* Status Cards (Superadmin Sync) */
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

        /* Muted label utility */
        .label-muted {
            color: var(--text-main); opacity: 0.5;
            font-size: 10px; font-weight: 800;
            text-transform: uppercase; letter-spacing: 0.15em;
        }

        .filter-input {
            background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); 
            border-radius: 14px; padding: 12px 18px; color: var(--text-main); 
            font-size: 11px; font-weight: 700; outline: none; 
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); appearance: none;
        }
        .filter-input:focus { border-color: var(--primary); background: rgba(var(--primary-rgb), 0.08); box-shadow: 0 0 0 4px rgba(var(--primary-rgb), 0.1); }

        .table-header-alt {
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.25em;
            color: var(--text-main);
            opacity: 0.5;
        }

        /* Elite Pagination Component Styling */
        .pagination-btn {
            padding: 8px 16px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.05);
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
            opacity: 0.2;
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


        /* Invisible Scroll System */
        *::-webkit-scrollbar {
            display: none !important;
        }

        * {
            -ms-overflow-style: none !important;
            scrollbar-width: none !important;
        }

        .report-tab-active {
            color: var(--primary) !important;
            border-bottom: 2px solid var(--primary) !important;
        }

        .report-tab-inactive {
            color: color-mix(in srgb, var(--text-main) 30%, transparent) !important;
            border-bottom: 2px solid transparent !important;
        }

        .report-tab-inactive:hover {
            color: var(--text-main) !important;
        }

        /* Modal Styles */
        .modal-backdrop {
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(8px);
        }

        .modal-content {
            background: var(--card-bg);
            border: 1px solid rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(var(--card-blur));
            box-shadow: 0 0 50px rgba(0, 0, 0, 0.5);
        }

        /* Inputs */
        .input-box {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            color: white;
            padding: 10px 16px;
            font-size: 11px;
            font-weight: 500;
            outline: none;
            transition: all 0.2s;
        }

        .input-box:focus {
            border-color: var(--primary);
            background: rgba(255, 255, 255, 0.08);
        }

        .input-box option {
            background: var(--background);
            color: var(--text-main);
        }

        .line-tab {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            padding: 16px 0;
            margin-right: 32px;
            transition: all 0.2s;
            border-bottom: 2px solid transparent;
        }

        /* RESTRICTION BLUR */
        .blur-overlay {
            position: relative;
        }

        .blur-overlay-content {
            filter: blur(12px);
            pointer-events: none;
            user-select: none;
        }

        /* Sidebar-Aware Sub Modal */
        #subModal {
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            left: 110px;
            z-index: 200;
            display: none !important;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(12px);
            transition: left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        #subModal.active {
            display: flex !important;
        }

        .side-nav:hover~#subModal {
            left: 300px;
        }
    </style>
    <script>
        function showSubWarning() { document.getElementById('subModal').classList.add('active'); }
        function closeSubModal() { document.getElementById('subModal').classList.remove('active'); }

        window.addEventListener('DOMContentLoaded', () => {
            <?php if ($is_restricted): ?>
                showSubWarning();
            <?php endif; ?>
        });
    </script>
</head>

<body class="flex h-screen overflow-hidden">

    <?php include '../includes/tenant_sidebar.php'; ?>

    <script>
        function updateTopClock() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            const dateString = now.toLocaleDateString('en-US', { weekday: 'long', month: 'short', day: '2-digit', year: 'numeric' });

            const clockEl = document.getElementById('topClock');
            const dateEl = document.getElementById('topDate');

            if (clockEl) clockEl.textContent = timeString;
            if (dateEl) dateEl.textContent = dateString;
        }
        setInterval(updateTopClock, 1000);
        window.addEventListener('DOMContentLoaded', updateTopClock);
    </script>

    <main class="main-content flex-1 p-10 overflow-y-auto no-scrollbar <?= $is_restricted ? 'blur-overlay' : '' ?>">
        <div class="<?= $is_restricted ? 'blur-overlay-content' : '' ?>">

            <header class="mb-10 flex justify-between items-end">
                <div>
                    <h2 class="text-3xl font-black uppercase tracking-tighter italic leading-none" style="color: var(--text-main)">
                        GYM <span class="text-primary italic">REPORTS</span>
                    </h2>
                    <p class="text-[10px] font-black uppercase tracking-widest mt-2 italic leading-none opacity-60" style="color: var(--text-main)">
                        <?= htmlspecialchars($gym_name) ?> ACTIVITY AND METRICS
                    </p>
                </div>

                <div class="flex items-center gap-8">

                    <div class="text-right flex flex-col items-end">
                        <p id="topClock" class="font-black italic text-2xl leading-none tracking-tighter" style="color: var(--text-main)">
                            00:00:00 AM</p>
                        <p id="topDate"
                            class="text-primary font-bold uppercase tracking-widest text-[10px] mt-2 px-1 opacity-80 italic">
                            <?= date('l, M d, Y') ?>
                        </p>
                    </div>
                </div>
            </header>

            <!-- Summary Statistics -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                <!-- Total Revenue -->
                <div class="glass-card p-8 status-card-primary relative overflow-hidden group hover:scale-[1.02] transition-all">
                    <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform" style="color:var(--primary)">payments</span>
                    <p class="label-muted mb-2 tracking-widest">Total Revenue</p>
                    <h3 class="text-2xl font-black italic uppercase" style="color:var(--text-main)">₱<?= number_format($total_money, 2) ?></h3>
                    <p class="text-primary text-[10px] font-black uppercase mt-2 italic shadow-sm">Financial Inflow</p>
                </div>

                <!-- Total Entries -->
                <div class="glass-card p-8 status-card-green relative overflow-hidden group hover:scale-[1.02] transition-all">
                    <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-emerald-500">login</span>
                    <p class="label-muted mb-2 tracking-widest">Total Entries</p>
                    <h3 class="text-2xl font-black italic uppercase text-emerald-400"><?= number_format($total_entries) ?> <span class="text-xs opacity-40">Units</span></h3>
                    <p class="text-emerald-500/60 text-[10px] font-black uppercase mt-2 italic">Presence Count</p>
                </div>

                <!-- Active Memberships -->
                <div class="glass-card p-8 status-card-amber relative overflow-hidden group hover:scale-[1.02] transition-all">
                    <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-amber-500">stars</span>
                    <p class="label-muted mb-2 tracking-widest">Active Subs</p>
                    <h3 class="text-2xl font-black italic uppercase text-amber-500"><?= number_format($total_active_subs) ?> <span class="text-xs opacity-40">Live</span></h3>
                    <p class="text-amber-500/60 text-[10px] font-black uppercase mt-2 italic">Active Memberships</p>
                </div>
            </div>

            <!-- Superadmin Style Underline Tabs (Outside the Table Card) -->
            <div class="flex items-center gap-12 mb-10 border-b border-white/5 px-2">
                <button onclick="switchReport('financial')" id="btn-financial" class="pb-5 relative transition-all duration-300 group outline-none">
                    <span id="tab-label-financial" class="text-xs font-black uppercase tracking-widest text-primary">
                        Financial
                    </span>
                    <div id="line-financial" class="absolute bottom-0 left-0 w-full h-[2px] bg-primary shadow-[0_0_10px_rgba(var(--primary-rgb),0.3)]"></div>
                </button>
                <button onclick="switchReport('attendance')" id="btn-attendance" class="pb-5 relative transition-all duration-300 group outline-none">
                    <span id="tab-label-attendance" class="text-xs font-black uppercase tracking-widest text-white/30 group-hover:text-white/50">
                        Attendance
                    </span>
                    <div id="line-attendance" class="hidden absolute bottom-0 left-0 w-full h-[2px] bg-emerald-500 shadow-[0_0_10px_rgba(16,185,129,0.3)]"></div>
                </button>
                <button onclick="switchReport('membership')" id="btn-membership" class="pb-5 relative transition-all duration-300 group outline-none">
                    <span id="tab-label-membership" class="text-xs font-black uppercase tracking-widest text-white/30 group-hover:text-white/50">
                        Memberships
                    <div id="line-membership" class="hidden absolute bottom-0 left-0 w-full h-[2px] bg-amber-500 shadow-[0_0_10px_rgba(245,158,11,0.3)]"></div>
                </button>
            </div>

            <div class="glass-card overflow-hidden shadow-2xl border border-white/5 flex flex-col">

                <!-- Elite Filter Bar (Inside the Card) -->
                <div class="p-8 border-b border-white/5 relative z-[60] bg-white/[0.01]">
                    <form method="GET" class="flex flex-wrap items-center gap-4">
                        <!-- Date Range -->
                        <div class="flex gap-2 shrink-0">
                            <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>"
                                max="<?= !empty($date_to) ? htmlspecialchars($date_to) : date('Y-m-d') ?>"
                                oninput="syncDateBounds('from')"
                                onchange="this.form.submit()"
                                class="w-full h-[52px] bg-white/5 border border-white/10 rounded-2xl px-5 text-[10px] font-black uppercase tracking-widest text-white outline-none hover:border-white/20 transition-all [color-scheme:dark]">
                            <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>"
                                min="<?= !empty($date_from) ? htmlspecialchars($date_from) : '' ?>"
                                max="<?= date('Y-m-d') ?>"
                                oninput="syncDateBounds('to')"
                                onchange="this.form.submit()"
                                class="w-full h-[52px] bg-white/5 border border-white/10 rounded-2xl px-5 text-[10px] font-black uppercase tracking-widest text-white outline-none hover:border-white/20 transition-all [color-scheme:dark]">
                        </div>

                        <!-- Searchable Member Selector -->
                        <div class="w-[240px] relative group shrink-0" id="memberSearchContainer">
                            <input type="hidden" name="member_id" id="hidden_member_id" value="<?= htmlspecialchars($member_filter) ?>">
                            <div class="relative">
                                <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-primary/50 text-sm pointer-events-none transition-transform group-focus-within:scale-110">group</span>
                                <input type="text" id="memberSearchInput" placeholder="Search Member..." value="<?= $member_filter === 'all' ? 'All Members' : htmlspecialchars(array_column($members_list, 'name', 'id')[$member_filter] ?? 'All Members') ?>" autocomplete="off"
                                    class="w-full h-[52px] bg-white/5 border border-white/10 rounded-2xl pl-11 pr-10 text-[10px] font-black uppercase tracking-widest outline-none text-white hover:border-white/20 transition-all focus:border-primary/50 cursor-pointer">
                                <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-primary/60 text-base pointer-events-none transition-transform group-hover:scale-110">expand_more</span>
                            </div>

                            <!-- Dropdown Overlay -->
                            <div id="memberDropdown" class="absolute left-0 right-0 top-full z-[100] rounded-b-xl searchable-dropdown-overlay max-h-64 overflow-y-auto hidden">
                                <div class="p-1.5 space-y-0.5">
                                    <div class="tenant-option px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider <?= $member_filter === 'all' ? 'selected' : 'text-white/60' ?>"
                                        data-id="all" data-name="All Members">
                                        All Members
                                    </div>
                                    <div id="memberOptionsList">
                                        <!-- Filtered members injected here -->
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="h-8 w-px bg-white/5 mx-2 shrink-0"></div>

                        <!-- Search -->
                        <div class="flex-1 min-w-[200px] relative group">
                            <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-base text-primary/50 transition-transform group-hover:scale-110">search</span>
                            <input type="text" id="reportSearchInput" onkeyup="filterTableRows()" placeholder="Search records..."
                                class="w-full h-[52px] bg-white/5 border border-white/10 rounded-2xl pl-12 pr-4 text-[10px] font-black uppercase tracking-widest text-white outline-none focus:border-primary/50 transition-all">
                        </div>

                        <!-- Clear Filter Button -->
                        <a href="reports.php" class="h-[52px] w-[52px] rounded-2xl bg-white/[0.02] border border-white/5 flex items-center justify-center text-primary hover:bg-white/5 transition-all shadow-lg active:scale-95 group" title="Clear Filters">
                            <span class="material-symbols-outlined text-xl transition-transform group-hover:rotate-180 duration-500">refresh</span>
                        </a>

                        <!-- Action Buttons (Right-aligned) -->
                        <div class="flex items-center gap-2 ml-auto shrink-0">
                            <button type="button" onclick="exportActiveReport(true)"
                                class="h-[52px] px-6 rounded-2xl bg-white/5 border border-white/5 text-[10px] font-black uppercase tracking-widest text-[--text-main]/60 hover:text-white hover:bg-white/10 transition-all flex items-center gap-2">
                                <span class="material-symbols-outlined text-sm">visibility</span>
                                Preview
                            </button>
                            <button type="button" id="pdfExportBtn" onclick="exportActiveReport(false)"
                                class="h-[52px] px-6 rounded-2xl border border-primary/20 text-[10px] font-black uppercase tracking-widest text-primary hover:bg-primary/10 transition-all flex items-center gap-2.5 active:scale-95 shadow-lg shadow-primary/20">
                                <span class="material-symbols-outlined text-sm">picture_as_pdf</span>
                                Export PDF
                            </button>
                        </div>
                    </form>
                </div>

                <div id="section-financial" class="report-section flex-1 flex flex-col justify-between">
                    <div class="overflow-x-auto flex-1">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="bg-white/5 border-b border-white/5">
                                    <th class="px-8 py-5 table-header-alt">Member ID</th>
                                    <th class="px-8 py-5 table-header-alt">Name</th>
                                    <th class="px-8 py-5 table-header-alt text-center">Type</th>
                                    <th class="px-8 py-5 table-header-alt text-center">Date of Payment</th>
                                    <th class="px-8 py-5 table-header-alt text-center">Ref Number</th>
                                    <th class="px-8 py-5 table-header-alt text-right">Amount</th>
                                </tr>
                            </thead>
                            <tbody id="financialTableBody" class="divide-y divide-white/5 text-sm font-medium">
                                <?php if (empty($financials)): ?>
                                    <tr class="no-pagination">
                                        <td colspan="6" class="px-8 py-24 text-center text-[11px] font-black italic uppercase tracking-[0.3em] text-[--text-main] opacity-20">
                                            No financial records found for this period.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($financials as $f): ?>
                                        <tr class="hover:bg-white/[0.05] transition-all duration-300 group">
                                            <td class="px-8 py-7 text-[11px] font-black text-[--text-main]/60 tracking-widest">
                                                <?= $f['member_id'] ? 'ID-' . str_pad($f['member_id'], 5, '0', STR_PAD_LEFT) : '---' ?>
                                            </td>
                                            <td class="px-8 py-7">
                                                <p class="text-sm font-bold text-[--text-main] group-hover:text-white transition-colors">
                                                    <?php if ($f['first_name']): ?>
                                                        <?= htmlspecialchars($f['first_name'] . ' ' . $f['last_name']) ?>
                                                    <?php else: ?>
                                                        Walk-in Guest
                                                    <?php endif; ?>
                                                </p>
                                            </td>
                                            <td class="px-8 py-7 text-center text-[11px] font-black text-[--text-main]">
                                                <?php 
                                                    $type = !empty($f['plan_name']) ? htmlspecialchars($f['plan_name']) . ' Membership' : 'N/A';
                                                    if (strpos($f['reference_number'] ?? '', 'PAYB') === 0) {
                                                        $type = 'BOOKING';
                                                    }
                                                    echo $type;
                                                ?>
                                            </td>
                                            <td class="px-8 py-7 text-center text-[11px] text-[--text-main]/40 font-bold">
                                                <?= date('M d, Y', strtotime($f['created_at'])) ?>
                                            </td>
                                            <td class="px-8 py-7 text-center text-[11px] text-[--text-main]/60 font-black tracking-wider">
                                                <?= !empty($f['reference_number']) ? htmlspecialchars($f['reference_number']) : '#' . str_pad($f['payment_id'], 5, '0', STR_PAD_LEFT) ?>
                                            </td>
                                            <td class="px-8 py-7 text-right text-sm font-black text-primary" data-amount="<?= $f['amount'] ?>">
                                                ₱<?= number_format($f['amount'], 2) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <tfoot>
                                <tr class="bg-white/[0.02] border-t border-white/5 font-black uppercase tracking-widest">
                                    <td colspan="5" class="px-8 py-6 text-left text-[--text-main]/40 text-sm">Total amount</td>
                                    <td class="px-8 py-6 text-right text-primary text-sm font-black">₱<?= number_format($total_money, 2) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <!-- Pagination Container -->
                    <div id="pagination-financial"
                        class="px-8 py-5 border-t border-white/5 bg-white/[0.01] flex justify-between items-center hidden">
                        <p class="pagination-status status-text"></p>
                        <div class="flex items-center gap-2 controls-container"></div>
                    </div>
                </div>

                <div id="section-attendance" class="report-section flex-1 flex flex-col justify-between hidden">
                    <div class="overflow-x-auto flex-1">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="bg-white/5 border-b border-white/5">
                                    <th class="px-8 py-5 table-header-alt">Member ID</th>
                                    <th class="px-8 py-5 table-header-alt">Name</th>
                                    <th class="px-8 py-5 table-header-alt text-center">Session Date</th>
                                    <th class="px-8 py-5 table-header-alt text-center">Time In / Out</th>
                                    <th class="px-8 py-5 table-header-alt text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody id="attendanceTableBody" class="divide-y divide-white/5 text-sm font-medium">
                                <?php if (empty($attendance_logs)): ?>
                                    <tr class="no-pagination">
                                        <td colspan="5" class="px-8 py-24 text-center text-[11px] font-black italic uppercase tracking-[0.3em] text-[--text-main] opacity-20">
                                            No attendance entries found for this period.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($attendance_logs as $a): ?>
                                        <tr class="hover:bg-white/[0.04] transition-all duration-300 group">
                                            <td class="px-8 py-7 text-[11px] font-black text-[--text-main]/60 tracking-widest">
                                                ID-<?= str_pad($a['member_id'], 5, '0', STR_PAD_LEFT) ?>
                                            </td>
                                            <td class="px-8 py-7">
                                                <p class="text-sm font-bold text-[--text-main] group-hover:text-white transition-colors">
                                                    <?= htmlspecialchars($a['first_name'] . ' ' . $a['last_name']) ?>
                                                </p>
                                            </td>
                                            <td class="px-8 py-7 text-center text-[11px] font-black text-[--text-main]">
                                                <?= date('M d, Y', strtotime($a['check_in_time'])) ?>
                                            </td>
                                            <?php 
                                                $statusRaw = strtolower($a['attendance_status'] ?? '');
                                                $isNoTimeOut = ($statusRaw === 'no time out' || $statusRaw === 'no timeout' || $statusRaw === 'did not checked out');
                                                $isTimedOut = ($statusRaw === 'timed out' || $statusRaw === 'timeout' || !empty($a['check_out_time']));
                                                $displayStatus = $isNoTimeOut ? 'No Time Out' : ($isTimedOut ? 'Timed Out' : 'In Gym');
                                            ?>
                                            <td class="px-8 py-7 text-center text-[11px] font-black text-emerald-400">
                                                <?= date('h:i A', strtotime($a['check_in_time'])) ?>
                                                <span class="mx-1 text-[--text-main]/40">-</span>
                                                <span style="color:<?= $a['check_out_time'] ? 'var(--text-main)' : 'rgba(var(--primary-rgb), 0.3)' ?>; opacity:<?= $a['check_out_time'] ? '0.6' : '1' ?>">
                                                    <?php 
                                                        if ($displayStatus === 'No Time Out' || !$a['check_out_time']) {
                                                            echo '---';
                                                        } else {
                                                            echo date('h:i A', strtotime($a['check_out_time']));
                                                        }
                                                    ?>
                                                </span>
                                            </td>
                                            <td class="px-8 py-7 text-center">
                                                <?php if($displayStatus === 'Timed Out'): ?>
                                                    <span class="px-3 py-1.5 rounded-xl bg-white/5 border border-white/10 text-[--text-main] opacity-40 text-[9px] font-black uppercase tracking-widest flex items-center gap-2 justify-center mx-auto w-fit">Timed Out</span>
                                                <?php elseif($displayStatus === 'No Time Out'): ?>
                                                    <span class="px-3 py-1.5 rounded-xl bg-red-500/10 border border-red-500/20 text-red-500 text-[9px] font-black uppercase tracking-widest flex items-center gap-2 justify-center mx-auto w-fit">No Time Out</span>
                                                <?php else: ?>
                                                    <span class="px-3 py-1.5 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-500 text-[9px] font-black uppercase tracking-[0.1em] flex items-center gap-2 justify-center mx-auto w-fit shadow-lg shadow-emerald-500/5">
                                                        <span class="size-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                                                        In Gym
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <!-- Pagination Container -->
                    <div id="pagination-attendance"
                        class="px-8 py-5 border-t border-white/5 bg-white/[0.01] flex justify-between items-center hidden">
                        <p class="pagination-status status-text"></p>
                        <div class="flex items-center gap-2 controls-container"></div>
                    </div>
                </div>

                <div id="section-membership" class="report-section flex-1 flex flex-col justify-between hidden">
                    <div class="overflow-x-auto flex-1">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="bg-white/5 border-b border-white/5">
                                    <th class="px-8 py-5 table-header-alt">Member ID</th>
                                    <th class="px-8 py-5 table-header-alt">Name</th>
                                    <th class="px-8 py-5 table-header-alt text-center">Tier Type</th>
                                    <th class="px-8 py-5 table-header-alt text-center">Payment Date</th>
                                    <th class="px-8 py-5 table-header-alt text-center">Renewal Date</th>
                                    <th class="px-8 py-5 table-header-alt text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody id="membershipTableBody" class="divide-y divide-white/5 text-sm font-medium">
                                <?php if (empty($subscriptions)): ?>
                                    <tr class="no-pagination">
                                        <td colspan="6" class="px-8 py-24 text-center text-[11px] font-black italic uppercase tracking-[0.3em] text-[--text-main] opacity-20">
                                            No active membership records found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($subscriptions as $s): ?>
                                        <tr class="hover:bg-white/[0.05] transition-all duration-300 group">
                                            <td class="px-8 py-7 text-[11px] font-black text-[--text-main]/60 tracking-widest">
                                                ID-<?= str_pad($s['member_id'], 5, '0', STR_PAD_LEFT) ?>
                                            </td>
                                            <td class="px-8 py-7">
                                                <p class="text-sm font-bold text-[--text-main] group-hover:text-white transition-colors">
                                                    <?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?>
                                                </p>
                                            </td>
                                            <td class="px-8 py-7 text-center text-[11px] font-black text-[--text-main]">
                                                <?= htmlspecialchars($s['plan_name']) ?> Membership
                                            </td>
                                            <td class="px-8 py-7 text-center text-[11px] text-[--text-main]/40 font-bold">
                                                <?= date('M d, Y', strtotime($s['payment_date'])) ?>
                                            </td>
                                            <td class="px-8 py-7 text-center text-[11px] text-[--text-main]/40 font-bold">
                                                <?= date('M d, Y', strtotime($s['end_date'])) ?>
                                            </td>
                                            <td class="px-8 py-7 text-center">
                                                <?php $sub_color = strtolower($s['subscription_status']) === 'active' ? 'emerald-500' : 'amber-500'; ?>
                                                <span class="px-3 py-1.5 rounded-xl bg-<?= $sub_color ?>/10 border border-<?= $sub_color ?>/20 text-[9px] text-<?= $sub_color ?> font-black uppercase tracking-wider mx-auto inline-block">
                                                    <?= htmlspecialchars($s['subscription_status']) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <!-- Pagination Container -->
                    <div id="pagination-membership"
                        class="px-8 py-5 border-t border-white/5 bg-white/[0.01] flex justify-between items-center hidden">
                        <p class="pagination-status status-text"></p>
                        <div class="flex items-center gap-2 controls-container"></div>
                    </div>
                </div>

            </div>
    </main>

    <script>
        const paginationRegistry = {};

        function switchReport(type) {
            // Save tab to local storage to persist on filter/reload
            localStorage.setItem('reports_active_tab', type);

            // Clean search filter when switching tabs so user gets fresh results
            const searchInput = document.getElementById('reportSearchInput');
            if (searchInput) searchInput.value = '';
            
            // Clear hidden search classes on all rows
            document.querySelectorAll('tr.hidden-search').forEach(tr => tr.classList.remove('hidden-search'));

            const sections = document.querySelectorAll('.report-section');
            sections.forEach(s => s.classList.add('hidden'));
            const activeSection = document.getElementById('section-' + type);
            if (activeSection) {
                activeSection.classList.remove('hidden');
            }

            const tabs = ['financial', 'attendance', 'membership'];
            tabs.forEach(t => {
                const label = document.getElementById('tab-label-' + t);
                const line = document.getElementById('line-' + t);
                
                if (label && line) {
                    if (t === type) {
                        if (t === 'financial') {
                            label.className = "text-[10px] font-black uppercase tracking-[0.3em] text-primary";
                            line.className = "absolute bottom-0 left-0 w-full h-[2px] bg-primary shadow-[0_0_10px_rgba(var(--primary-rgb),0.3)]";
                            line.classList.remove('hidden');
                        } else if (t === 'attendance') {
                            label.className = "text-[10px] font-black uppercase tracking-[0.3em] text-emerald-500";
                            line.className = "absolute bottom-0 left-0 w-full h-[2px] bg-emerald-500 shadow-[0_0_10px_rgba(16,185,129,0.3)]";
                            line.classList.remove('hidden');
                        } else {
                            label.className = "text-[10px] font-black uppercase tracking-[0.3em] text-amber-500";
                            line.className = "absolute bottom-0 left-0 w-full h-[2px] bg-amber-500 shadow-[0_0_10px_rgba(245,158,11,0.3)]";
                            line.classList.remove('hidden');
                        }
                    } else {
                        label.className = "text-[10px] font-black uppercase tracking-[0.3em] text-white/30 group-hover:text-white/50";
                        line.className = "hidden absolute bottom-0 left-0 w-full h-[2px]";
                        line.classList.add('hidden');
                    }
                }
            });

            // Dynamically change PDF download button's theme to match the active tab
            const pdfBtn = document.getElementById('pdfExportBtn');
            if (pdfBtn) {
                if (type === 'financial') {
                    pdfBtn.className = "h-[52px] px-6 rounded-2xl border border-primary/20 text-[10px] font-black uppercase tracking-widest text-primary hover:bg-primary/10 transition-all flex items-center gap-2.5 active:scale-95";
                } else if (type === 'attendance') {
                    pdfBtn.className = "h-[52px] px-6 rounded-2xl border border-emerald-500/20 text-[10px] font-black uppercase tracking-widest text-emerald-500 hover:bg-emerald-500/10 transition-all flex items-center gap-2.5 active:scale-95";
                } else if (type === 'membership') {
                    pdfBtn.className = "h-[52px] px-6 rounded-2xl border border-amber-500/20 text-[10px] font-black uppercase tracking-widest text-amber-500 hover:bg-amber-500/10 transition-all flex items-center gap-2.5 active:scale-95";
                }
            }

            // Re-run pagination since search is reset
            const tbodyId = type === 'financial' ? 'financialTableBody' : (type === 'attendance' ? 'attendanceTableBody' : 'membershipTableBody');
            if (paginationRegistry[tbodyId]) {
                paginationRegistry[tbodyId]();
            }
        }

        function filterTableRows() {
            const query = document.getElementById('reportSearchInput').value.toLowerCase().trim();
            const activeTab = localStorage.getItem('reports_active_tab') || 'financial';
            
            let tbodyId, searchCols;
            if (activeTab === 'financial') {
                tbodyId = 'financialTableBody';
                searchCols = [1, 3]; // Payer, Method
            } else if (activeTab === 'attendance') {
                tbodyId = 'attendanceTableBody';
                searchCols = [0, 3]; // Member, Status
            } else if (activeTab === 'membership') {
                tbodyId = 'membershipTableBody';
                searchCols = [0, 1, 3]; // Member, Tier Plan, Status
            }

            const tbody = document.getElementById(tbodyId);
            if (!tbody) return;

            const rows = Array.from(tbody.querySelectorAll('tr:not(.no-pagination):not([id^="search-empty-state"])'));
            let hasVisibleRow = false;
            
            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                let match = false;
                searchCols.forEach(colIdx => {
                    if (cells[colIdx] && cells[colIdx].textContent.toLowerCase().includes(query)) {
                        match = true;
                    }
                });

                if (query === '' || match) {
                    row.classList.remove('hidden-search');
                    hasVisibleRow = true;
                } else {
                    row.classList.add('hidden-search');
                }
            });

            let emptyStateRow = tbody.querySelector('#search-empty-state-' + tbodyId);
            if (!hasVisibleRow && rows.length > 0) {
                if (!emptyStateRow) {
                    emptyStateRow = document.createElement('tr');
                    emptyStateRow.id = 'search-empty-state-' + tbodyId;
                    emptyStateRow.className = 'no-pagination';
                    const colCount = tbody.closest('table').querySelectorAll('thead th').length;
                    emptyStateRow.innerHTML = `<td colspan="${colCount}" class="px-8 py-24 text-center text-[11px] font-black italic uppercase tracking-[0.3em] text-[--text-main] opacity-20">No records found matching your search.</td>`;
                    tbody.appendChild(emptyStateRow);
                }
                emptyStateRow.style.display = '';
            } else if (emptyStateRow) {
                emptyStateRow.style.display = 'none';
            }

            // Refresh pagination to account for hidden search results
            if (paginationRegistry[tbodyId]) {
                paginationRegistry[tbodyId]();
            }
        }

        function exportActiveReport(preview) {
            const activeTab = localStorage.getItem('reports_active_tab') || 'financial';
            if (activeTab === 'financial') {
                exportReportToPDF('section-financial', 'Financial Report', preview);
            } else if (activeTab === 'attendance') {
                exportReportToPDF('section-attendance', 'Attendance Report', preview);
            } else if (activeTab === 'membership') {
                exportReportToPDF('section-membership', 'Membership Report', preview);
            }
        }

        function syncDateBounds(source) {
            const fromInput = document.querySelector('input[name="date_from"]');
            const toInput = document.querySelector('input[name="date_to"]');
            const today = new Date().toISOString().split('T')[0];

            if (!fromInput || !toInput) return;

            if (source === 'from') {
                if (fromInput.value) {
                    // Lock the To field's min boundary to this value
                    toInput.min = fromInput.value;
                    
                    // Logical correction: Push To forward if From is later than To
                    if (toInput.value && fromInput.value > toInput.value) {
                        toInput.value = fromInput.value;
                    }
                } else {
                    toInput.removeAttribute('min');
                }
            } else if (source === 'to') {
                if (toInput.value) {
                    // Lock the From field's max boundary to this value
                    fromInput.max = toInput.value;
                    
                    // Logical correction: Pull From back if To is earlier than From
                    if (fromInput.value && toInput.value < fromInput.value) {
                        fromInput.value = toInput.value;
                    }
                } else {
                    fromInput.max = today;
                }
            }
        }

        function exportReportToPDF(sectionId, reportTitle, preview = false) {
            const element = document.getElementById(sectionId);
            const gymName = "<?= htmlspecialchars($gym_name) ?>";
            const generatedAt = "<?= date('M d, Y h:i A') ?>";
            const dateRange = "Period: <?= date('M d, Y', strtotime($date_from)) ?> - <?= date('M d, Y', strtotime($date_to)) ?>";

            const wrapper = document.createElement('div');
            wrapper.style.padding = '50px';
            wrapper.style.color = '#333';
            wrapper.style.backgroundColor = '#fff';
            wrapper.style.fontFamily = "'Inter', 'Helvetica Neue', Arial, sans-serif";

            // 1. ELITE BUSINESS HEADER
            const header = `
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 30px;">
                <div style="text-align: left;">
                    <h1 style="font-size: 28px; font-weight: 800; color: #111; margin: 0 0 8px 0; text-transform: uppercase; letter-spacing: -0.5px; line-height: 1;">${gymName}</h1>
                    <p style="margin: 0 0 3px 0; font-size: 10px; color: #666;"><?= htmlspecialchars($gym_data['gym_email'] ?? 'Internal Records') ?></p>
                    <p style="margin: 0; font-size: 10px; color: #666;">Phone: <?= htmlspecialchars($gym_data['gym_contact'] ?? 'N/A') ?></p>
                </div>
                <div style="text-align: right;">
                    <h2 style="font-size: 18px; font-weight: 800; color: #111; margin: 0 0 6px 0; text-transform: uppercase; letter-spacing: -0.5px; line-height: 1;">${reportTitle}</h2>
                    <p style="margin: 0 0 4px 0; font-size: 10px; color: #666;">${dateRange}</p>
                    <p style="margin: 0 0 4px 0; font-size: 10px; color: #666;">Generated on: ${generatedAt}</p>
                    <p style="margin: 0; font-size: 9px; font-weight: 800; color: #888; text-transform: uppercase; letter-spacing: 1px;">OFFICIAL SECURE TRANSCRIPT</p>
                </div>
            </div>
            <div style="border-bottom: 2px solid #111; margin-bottom: 30px;"></div>
            `;

            // 2. SURGICAL CLEANING
            const contentClone = element.cloneNode(true);
            
            // Remove UI clutter (buttons, forms, icons, headers, pagination, etc.)
            contentClone.querySelectorAll('button, form, span.material-symbols-outlined, header, [id^="pagination-"], .pagination-status, .controls-container, .flex-wrap, .screen-only-total').forEach(el => el.remove());

            [contentClone, ...contentClone.querySelectorAll('*')].forEach(el => {
                const isRightAligned = el.classList.contains('text-right');
                const isCentered = el.classList.contains('text-center');

                el.removeAttribute('class');
                el.removeAttribute('style'); // Clear any conflicting inline styles like style="color: var(--text-main)"
                
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

                if (isRightAligned) {
                    el.style.setProperty('text-align', 'right', 'important');
                } else if (isCentered) {
                    el.style.setProperty('text-align', 'center', 'important');
                } else {
                    el.style.setProperty('text-align', 'left', 'important');
                }
            });

            // 3. TABLE TRANSFORMATION
            const table = contentClone.querySelector('table');
            if (table) {
                table.style.setProperty('width', '100%', 'important');
                table.style.setProperty('border-collapse', 'collapse', 'important');
                table.style.setProperty('font-size', '10px', 'important');
                table.style.setProperty('color', '#333333', 'important');
                table.style.setProperty('border', 'none', 'important');
                table.style.setProperty('font-family', "'Inter', 'Helvetica Neue', Arial, sans-serif", 'important');
                table.style.setProperty('margin-top', '20px', 'important');

                table.querySelectorAll('th').forEach(th => {
                    th.style.setProperty('background-color', '#f8f9fa', 'important');
                    th.style.setProperty('color', '#111111', 'important');
                    th.style.setProperty('border-bottom', '2px solid #222222', 'important');
                    th.style.setProperty('border-top', '1px solid #dddddd', 'important');
                    th.style.setProperty('border-left', 'none', 'important');
                    th.style.setProperty('border-right', 'none', 'important');
                    th.style.setProperty('padding', '12px 14px', 'important');
                    th.style.setProperty('text-transform', 'uppercase', 'important');
                    th.style.setProperty('font-weight', '700', 'important');
                    if (!th.style.textAlign) {
                        th.style.setProperty('text-align', 'left', 'important');
                    }
                });

                table.querySelectorAll('tr').forEach(tr => {
                    tr.style.setProperty('display', 'table-row', 'important');
                });

                table.querySelectorAll('td').forEach(td => {
                    td.style.setProperty('border-bottom', '1px solid #eeeeee', 'important');
                    td.style.setProperty('border-top', 'none', 'important');
                    td.style.setProperty('border-left', 'none', 'important');
                    td.style.setProperty('border-right', 'none', 'important');
                    td.style.setProperty('padding', '12px 14px', 'important');
                    td.style.setProperty('color', '#444444', 'important');
                    td.style.setProperty('background-color', '#ffffff', 'important');
                    td.querySelectorAll('*').forEach(ch => {
                        ch.style.setProperty('font-size', '10px', 'important');
                    });
                });

                const tfoot = table.querySelector('tfoot');
                if (tfoot) {
                    const tfootRow = tfoot.querySelector('tr');
                    if (tfootRow) {
                        tfootRow.style.setProperty('background-color', '#fdfdfd', 'important');
                        tfootRow.style.setProperty('border-top', '2px solid #222222', 'important');
                        tfootRow.querySelectorAll('td').forEach(td => {
                            td.style.setProperty('font-weight', '900', 'important');
                            td.style.setProperty('color', '#000', 'important');
                            td.style.setProperty('font-size', '12px', 'important');
                            td.style.setProperty('border', 'none', 'important');
                            td.style.setProperty('padding', '14px 14px', 'important');
                        });
                    }
                }
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
                <p style="margin: 0;">&copy; ${new Date().getFullYear()} Horizon System • Secured Internal Data</p>
            `;

            wrapper.innerHTML = header;
            wrapper.appendChild(contentClone);
            wrapper.appendChild(footer);

            const opt = {
                margin: [0.3, 0.3],
                filename: `${reportTitle.replace(/\s+/g, '_')}_${new Date().toISOString().split('T')[0]}.pdf`,
                image: { type: 'jpeg', quality: 1.0 },
                html2canvas: { scale: 3, useCORS: true, letterRendering: true, backgroundColor: '#ffffff' },
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

        /**
         * Horizon Table Pagination Engine 2.0 (Search-Aware & Dynamic Registry)
         * Premium Glassmorphism Logic with Range Awareness & Empty Row Padding
         */
        function initTablePagination(tbodyId, paginationId, rowsPerPage = 10) {
            const tbody = document.getElementById(tbodyId);
            const footer = document.getElementById(paginationId);
            if (!tbody || !footer) return;

            let currentPage = 1;

            const status = footer.querySelector('.status-text');
            const controls = footer.querySelector('.controls-container');

            function refresh() {
                // Select only rows that are NOT marked as hidden-search
                const rows = Array.from(tbody.querySelectorAll('tr:not(.no-pagination):not(.hidden-search)'));
                const totalRows = rows.length;

                // Make sure the footer is always visible
                footer.classList.remove('hidden');

                const totalPages = Math.max(1, Math.ceil(totalRows / rowsPerPage));
                if (currentPage > totalPages) currentPage = totalPages;
                if (currentPage < 1) currentPage = 1;

                const start = (currentPage - 1) * rowsPerPage;
                const end = start + rowsPerPage;

                // Hide all rows in this section first
                tbody.querySelectorAll('tr:not(.no-pagination)').forEach(row => {
                    row.classList.add('hidden');
                });

                // Display only matching rows within the paginated bounds
                rows.forEach((row, i) => {
                    if (i >= start && i < end) {
                        row.classList.remove('hidden');
                        row.classList.add('animate-in', 'fade-in', 'duration-300');
                    }
                });

                // Render control buttons
                controls.innerHTML = '';

                // Prev Button
                const prev = document.createElement('button');
                prev.type = 'button';
                prev.className = `pagination-btn ${currentPage === 1 ? 'disabled' : ''}`;
                prev.disabled = currentPage === 1;
                prev.textContent = 'Prev';
                prev.onclick = () => { if (currentPage > 1) { currentPage--; refresh(); } };
                controls.appendChild(prev);

                // Indices
                for (let i = 1; i <= totalPages; i++) {
                    if (i === 1 || i === totalPages || (i >= currentPage - 2 && i <= currentPage + 2)) {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = `pagination-btn ${i === currentPage ? 'active' : ''}`;
                        btn.innerText = i;
                        btn.onclick = () => { currentPage = i; refresh(); };
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
                next.type = 'button';
                next.className = `pagination-btn ${currentPage === totalPages ? 'disabled' : ''}`;
                next.disabled = currentPage === totalPages;
                next.textContent = 'Next';
                next.onclick = () => { if (currentPage < totalPages) { currentPage++; refresh(); } };
                controls.appendChild(next);

                if (totalRows === 0) {
                    status.innerHTML = `Showing 0 to 0 of 0 records`;
                } else {
                    status.innerHTML = `Showing ${start + 1} to ${Math.min(end, totalRows)} of ${totalRows} records`;
                }
            }

            // Register this pagination refresh callback globally
            paginationRegistry[tbodyId] = refresh;

            refresh();
        }

        window.addEventListener('DOMContentLoaded', () => {
            initTablePagination('financialTableBody', 'pagination-financial', 10);
            initTablePagination('attendanceTableBody', 'pagination-attendance', 10);
            initTablePagination('membershipTableBody', 'pagination-membership', 10);

            // Restore active tab from local storage
            const activeTab = localStorage.getItem('reports_active_tab') || 'financial';
            switchReport(activeTab);
            
            // Sync dates max/min boundaries on load
            syncDateBounds('from');
            syncDateBounds('to');
        });
    </script>


    <!-- Restriction Modal (Sidebar-Aware) -->
    <div id="subModal">
        <div
            class="glass-card max-w-md w-full p-10 text-center animate-in zoom-in duration-300 relative shadow-[0_0_100px_rgba(var(--primary-rgb),0.15)] border-primary/20">
            <div
                class="size-20 rounded-3xl bg-primary/10 border border-primary/20 flex items-center justify-center mx-auto mb-8">
                <span class="material-symbols-outlined text-4xl text-primary">analytics</span>
            </div>
            <h3 class="text-2xl font-black italic uppercase tracking-tighter mb-3" style="color: var(--text-main)">Subscription Required</h3>
            <p
                class="text-[10px] font-black uppercase tracking-[0.2em] mb-10 leading-relaxed italic px-4 opacity-60" style="color: var(--text-main)">
                Access to detailed analytics, financial reports, and system metrics is restricted. Your status is <span
                    class="text-primary italic animate-pulse"><?= $sub_status ?></span>. Please activate a growth plan
                to unlock.
            </p>
            <div class="flex flex-col gap-4">
                <?php if (strpos($sub_status, 'Pending') !== false): ?>
                    <a href="tenant_dashboard.php"
                        class="h-14 rounded-2xl bg-primary text-white text-[11px] font-black uppercase italic tracking-[0.2em] flex items-center justify-center gap-3 hover:scale-[1.02] active:scale-95 transition-all shadow-xl shadow-primary/20 group">
                        <span
                            class="material-symbols-outlined text-xl group-hover:scale-110 transition-transform">grid_view</span>
                        Back to Dashboard
                    </a>
                <?php else: ?>
                    <a href="subscription_plan.php"
                        class="h-14 rounded-2xl bg-primary text-white text-[11px] font-black uppercase italic tracking-[0.2em] flex items-center justify-center gap-3 hover:scale-[1.02] active:scale-95 transition-all shadow-xl shadow-primary/20 group">
                        <span
                            class="material-symbols-outlined text-xl group-hover:scale-110 transition-transform">payments</span>
                        Select Growth Plan
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

</body>

</html>