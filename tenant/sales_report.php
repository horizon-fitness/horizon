<?php
session_start();
require_once '../db.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Security Check
$role = strtolower($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || !in_array($role, ['tenant', 'admin'])) {
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'] ?? null;
$gym_id = $_SESSION['gym_id'] ?? null;

if (!$user_id || !$gym_id) {
    header("Location: ../login.php");
    exit;
}

// Fetch Gym Branding Info
$stmtGym = $pdo->prepare("
    SELECT g.*, u.first_name, u.last_name 
    FROM gyms g 
    JOIN users u ON g.owner_user_id = u.user_id 
    WHERE g.gym_id = ?
");
$stmtGym->execute([$gym_id]);
$gym_data = $stmtGym->fetch();
$gym_name = $gym_data['gym_name'] ?? 'Horizon Gym';

// --- DATE FILTERING LOGIC ---
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // Default to start of month
$date_to = $_GET['date_to'] ?? date('Y-m-d');

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

// --- FINANCIAL CALCULATIONS (Scoped by Date Range) ---
// Total Revenue (Verified only)
// Total revenue will be calculated from filtered transactions below

$stmtLifetime = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE gym_id = ? AND client_subscription_id IS NULL");
$stmtLifetime->execute([$gym_id]);
$lifetime_revenue = $stmtLifetime->fetchColumn() ?? 0;

$stmtDaily = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE gym_id = ? AND client_subscription_id IS NULL AND DATE(created_at) = CURDATE()");
$stmtDaily->execute([$gym_id]);
$daily_sales = $stmtDaily->fetchColumn() ?? 0;

// Fetch Members for Filter Dropdown
$stmtMembers = $pdo->prepare("SELECT m.member_id, u.first_name, u.last_name FROM members m JOIN users u ON m.user_id = u.user_id WHERE m.gym_id = ? AND u.is_active = 1 ORDER BY u.first_name ASC");
$stmtMembers->execute([$gym_id]);
$members_list = $stmtMembers->fetchAll(PDO::FETCH_ASSOC);

$member_filter = $_GET['member_id'] ?? 'all';

// --- TRANSACTION HISTORY (Filtered) ---
$history_sql = "
    SELECT p.*, 
           u_member.first_name, 
           u_member.last_name,
           m.member_id,
           mp.plan_name 
    FROM payments p 
    LEFT JOIN member_subscriptions ms ON p.subscription_id = ms.subscription_id
    LEFT JOIN membership_plans mp ON ms.membership_plan_id = mp.membership_plan_id
    LEFT JOIN members m ON p.member_id = m.member_id
    LEFT JOIN users u_member ON m.user_id = u_member.user_id
    WHERE p.gym_id = ? AND p.client_subscription_id IS NULL AND DATE(p.created_at) BETWEEN ? AND ?";
$history_params = [$gym_id, $date_from, $date_to];

if ($member_filter !== 'all') {
    $history_sql .= " AND p.member_id = ?";
    $history_params[] = $member_filter;
}
$history_sql .= " ORDER BY p.created_at DESC";

$stmtHistory = $pdo->prepare($history_sql);
$stmtHistory->execute($history_params);
$transactions = $stmtHistory->fetchAll();

$total_revenue = array_reduce($transactions, fn($sum, $t) => $sum + (float)$t['amount'], 0);

// --- SUBSCRIPTION CHECK FOR RESTRICTION ---
$stmtSubStatus = $pdo->prepare("SELECT subscription_status FROM client_subscriptions WHERE gym_id = ? ORDER BY created_at DESC LIMIT 1");
$stmtSubStatus->execute([$gym_id]);
$sub_status = $stmtSubStatus->fetchColumn() ?: 'None';
$is_sub_active = (strtolower($sub_status) === 'active');
$is_restricted = (!$is_sub_active);

$active_page = "sales";
?>

<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8">
    <title>Sales Intelligence | Horizon</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
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

        /* Sidebar & Layout Engine */
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

        /* Invisible Scroll System (Global) */
        *::-webkit-scrollbar { display: none !important; }
        * { -ms-overflow-style: none !important; scrollbar-width: none !important; }

        /* Removed table-header-alt style rule */

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

        /* Muted label utility */
        .label-muted {
            color: var(--text-main); opacity: 0.6;
            font-size: 10px; font-weight: 800;
            text-transform: uppercase; letter-spacing: 0.15em;
        }

        /* RESTRICTION BLUR */
        .blur-overlay { position: relative; }
        .blur-overlay-content { filter: blur(12px); pointer-events: none; user-select: none; }

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
        #subModal.active { display: flex !important; }
        .side-nav:hover ~ #subModal { left: 300px; }
    </style>
    <script>
        function showSubWarning() { document.getElementById('subModal').classList.add('active'); }
        function closeSubModal() { document.getElementById('subModal').classList.remove('active'); }

        window.addEventListener('DOMContentLoaded', () => {
            <?php if ($is_restricted): ?>
            showSubWarning();
            <?php endif; ?>
            updateTopClock();
        });

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
    </script>
</head>
<body class="flex h-screen overflow-hidden">

    <?php include '../includes/tenant_sidebar.php'; ?>

<main class="main-content flex-1 p-10 overflow-y-auto no-scrollbar <?= $is_restricted ? 'blur-overlay' : '' ?>">
    <div class="<?= $is_restricted ? 'blur-overlay-content' : '' ?>">


    <header class="mb-10 flex justify-between items-end">
        <div>
            <h2 class="text-3xl font-black uppercase tracking-tighter italic leading-none" style="color: var(--text-main)">
                SALES <span class="text-primary italic">REPORTS</span>
            </h2>
            <p class="label-muted mt-2 italic leading-none opacity-60">
                <?= htmlspecialchars($gym_name) ?> FINANCIAL INTELLIGENCE
            </p>
        </div>

        <div class="flex items-center gap-8">
            <div class="text-right flex flex-col items-end">
                <p id="topClock" class="font-black italic text-2xl leading-none tracking-tighter" style="color: var(--text-main)">00:00:00 AM</p>
                <p id="topDate" class="text-primary font-bold uppercase tracking-widest text-[10px] mt-2 px-1 opacity-80 italic">
                    <?= date('l, M d, Y') ?>
                </p>
            </div>
        </div>
    </header>

    <!-- Summary Intelligence Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">
        <!-- Filtered Revenue -->
        <div class="glass-card p-8 status-card-primary relative overflow-hidden group hover:scale-[1.02] transition-all">
            <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform" style="color:var(--primary)">payments</span>
            <p class="label-muted mb-2 tracking-widest text-[10px]">Filtered Revenue</p>
            <h3 class="text-2xl font-black italic uppercase" style="color:var(--text-main)">₱<?= number_format($total_revenue, 2) ?></h3>
            <p class="text-primary text-[10px] font-black uppercase mt-2 italic shadow-sm">Sales Inflow</p>
        </div>

        <!-- Lifetime Sales -->
        <div class="glass-card p-8 status-card-green relative overflow-hidden group hover:scale-[1.02] transition-all">
            <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-emerald-500">database</span>
            <p class="label-muted mb-2 tracking-widest text-[10px]">Lifetime Sales</p>
            <h3 class="text-2xl font-black italic uppercase text-emerald-400">₱<?= number_format($lifetime_revenue, 2) ?></h3>
            <p class="text-emerald-500/60 text-[10px] font-black uppercase mt-2 italic">All-time Recorded</p>
        </div>

        <!-- Today's Performance -->
        <div class="glass-card p-8 status-card-amber relative overflow-hidden group hover:scale-[1.02] transition-all">
            <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-amber-500">trending_up</span>
            <p class="label-muted mb-2 tracking-widest text-[10px]">Today's Sales</p>
            <h3 class="text-2xl font-black italic uppercase text-amber-500">₱<?= number_format($daily_sales, 2) ?></h3>
            <p class="text-amber-500/60 text-[10px] font-black uppercase mt-2 italic">Daily Performance</p>
        </div>
    </div>

    <!-- Export Anchor: This wraps only the table and its specific header for the PDF -->
    <div id="table-export-anchor" class="glass-card overflow-hidden shadow-2xl border border-white/5 flex flex-col justify-between">
        <div class="px-8 py-6 border-b border-white/5 bg-white/[0.01] flex justify-between items-center">
            <div>
                <h3 class="text-sm font-black italic uppercase tracking-widest text-primary">Transaction History</h3>
                <p class="text-[9px] text-[--text-main]/40 font-bold uppercase tracking-widest mt-1">Gym Sales and Payment Records</p>
            </div>
            <div class="flex items-center gap-3">
                <button type="button" onclick="exportReportToPDF('table-export-anchor', 'Sales Intelligence', true)" class="px-5 py-2.5 rounded-xl bg-white/5 border border-white/5 text-[10px] font-black uppercase tracking-widest text-[--text-main]/60 hover:text-white hover:bg-white/10 transition-all flex items-center gap-2">
                    <span class="material-symbols-outlined text-sm">visibility</span> Preview
                </button>
                <button type="button" onclick="exportReportToPDF('table-export-anchor', 'Sales Intelligence', false)" class="px-5 py-2.5 rounded-xl bg-transparent border border-primary text-primary outline-none focus:outline-none focus:ring-0 text-[10px] font-black uppercase tracking-widest hover:scale-105 transition-transform active:scale-95 shadow-lg shadow-primary/20 flex items-center gap-2">
                    <span class="material-symbols-outlined text-sm">picture_as_pdf</span> Export PDF
                </button>
            </div>
        </div>

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
                    <input type="text" id="salesSearchInput" onkeyup="filterSalesRows()" placeholder="Search records..."
                        class="w-full h-[52px] bg-white/5 border border-white/10 rounded-2xl pl-12 pr-4 text-[10px] font-black uppercase tracking-widest text-white outline-none focus:border-primary/50 transition-all">
                </div>

                <!-- Clear Filter Button -->
                <a href="sales_report.php" class="h-[52px] w-[52px] flex items-center justify-center rounded-2xl bg-white/[0.02] border border-white/5 text-primary hover:bg-white/5 transition-all shadow-lg active:scale-95 group" title="Clear Filters">
                    <span class="material-symbols-outlined text-xl transition-transform group-hover:rotate-180 duration-500">refresh</span>
                </a>
            </form>
        </div>
        
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
                <tbody id="salesTableBody" class="divide-y divide-white/5 text-sm font-medium">
                    <?php if (empty($transactions)): ?>
                        <tr class="no-pagination">
                            <td colspan="6" class="px-8 py-24 text-center text-[11px] font-black italic uppercase tracking-[0.3em] text-[--text-main] opacity-20">
                                No recent transactions found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($transactions as $t): ?>
                            <tr class="hover:bg-white/[0.05] transition-all duration-300 group">
                                <td class="px-8 py-7 text-[11px] font-black text-[--text-main]/60 tracking-widest">
                                    <?= $t['member_id'] ? 'ID-' . str_pad($t['member_id'], 5, '0', STR_PAD_LEFT) : '---' ?>
                                </td>
                                <td class="px-8 py-7">
                                    <p class="text-sm font-bold text-[--text-main] group-hover:text-white transition-colors">
                                        <?php if ($t['first_name']): ?>
                                            <?= htmlspecialchars($t['first_name'] . ' ' . $t['last_name']) ?>
                                        <?php else: ?>
                                            Walk-in Guest
                                        <?php endif; ?>
                                    </p>
                                </td>
                                <td class="px-8 py-7 text-center text-[11px] font-black text-[--text-main]">
                                    <?php 
                                        $type = !empty($t['plan_name']) ? htmlspecialchars($t['plan_name']) . ' Membership' : 'N/A';
                                        if (strpos($t['reference_number'] ?? '', 'PAYB') === 0) {
                                            $type = 'BOOKING';
                                        }
                                        echo $type;
                                    ?>
                                </td>
                                <td class="px-8 py-7 text-center text-[11px] text-[--text-main]/40 font-bold">
                                    <?= date('M d, Y', strtotime($t['created_at'])) ?>
                                </td>
                                <td class="px-8 py-7 text-center text-[11px] text-[--text-main]/60 font-black tracking-wider">
                                    <?= !empty($t['reference_number']) ? htmlspecialchars($t['reference_number']) : '#' . str_pad($t['payment_id'], 5, '0', STR_PAD_LEFT) ?>
                                </td>
                                <td class="px-8 py-7 text-right text-sm font-black text-primary" data-amount="<?= $t['amount'] ?>">
                                    ₱<?= number_format($t['amount'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr class="bg-white/[0.02] border-t border-white/5 font-black uppercase tracking-widest">
                        <td colspan="5" class="px-8 py-6 text-left text-[--text-main]/40 text-sm">Total amount</td>
                        <td class="px-8 py-6 text-right text-primary text-sm font-black">₱<?= number_format($total_revenue, 2) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <!-- Pagination Container -->
        <div id="pagination-sales" class="px-10 py-5 border-t border-white/5 bg-white/[0.01] flex justify-between items-center hidden">
            <p class="pagination-status status-text"></p>
            <div class="flex items-center gap-2 controls-container"></div>
        </div>
    </div>
</main>

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

    let refreshSalesPagination = null;

    function initTablePagination(tbodyId, paginationId, rowsPerPage = 10) {
        const tbody = document.getElementById(tbodyId);
        const footer = document.getElementById(paginationId);
        if (!tbody || !footer) return;

        let currentPage = 1;
        const status = footer.querySelector('.status-text');
        const controls = footer.querySelector('.controls-container');

        function refresh() {
            const rows = Array.from(tbody.querySelectorAll('tr:not(.no-pagination):not(.hidden-search)'));
            const totalRows = rows.length;

            // Make sure the footer is always visible
            footer.classList.remove('hidden');

            const totalPages = Math.max(1, Math.ceil(totalRows / rowsPerPage));
            if (currentPage > totalPages) currentPage = totalPages;
            if (currentPage < 1) currentPage = 1;

            const start = (currentPage - 1) * rowsPerPage;
            const end = start + rowsPerPage;

            tbody.querySelectorAll('tr:not(.no-pagination)').forEach(row => {
                row.classList.add('hidden');
            });

            rows.forEach((row, i) => {
                if (i >= start && i < end) {
                    row.classList.remove('hidden');
                    row.classList.add('animate-in', 'fade-in', 'duration-300');
                }
            });

            controls.innerHTML = '';

            const prev = document.createElement('button');
            prev.type = 'button';
            prev.className = `pagination-btn ${currentPage === 1 ? 'disabled' : ''}`;
            prev.disabled = currentPage === 1;
            prev.textContent = 'Prev';
            prev.onclick = () => { if (currentPage > 1) { currentPage--; refresh(); } };
            controls.appendChild(prev);

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

        if (tbodyId === 'salesTableBody') {
            refreshSalesPagination = refresh;
        }

        refresh();
    }

    function filterSalesRows() {
        const query = document.getElementById('salesSearchInput').value.toLowerCase().trim();
        const tbody = document.getElementById('salesTableBody');
        if (!tbody) return;

        const rows = Array.from(tbody.querySelectorAll('tr:not(.no-pagination):not([id^="search-empty-state"])'));
        let hasVisibleRow = false;
        
        rows.forEach(row => {
            const cells = row.querySelectorAll('td');
            let match = false;
            // Name (0), Plan Type (1), Date of Payment (2), Ref Number (3), Amount (4)
            const searchCols = [0, 1, 2, 3, 4];
            searchCols.forEach(colIdx => {
                if (cells[colIdx]) {
                    const text = cells[colIdx].textContent.toLowerCase();
                    if (text.includes(query)) {
                        match = true;
                    }
                }
            });

            if (query === '' || match) {
                row.classList.remove('hidden-search');
                hasVisibleRow = true;
            } else {
                row.classList.add('hidden-search');
            }
        });

        let emptyStateRow = tbody.querySelector('#search-empty-state-sales');
        if (!hasVisibleRow && rows.length > 0) {
            if (!emptyStateRow) {
                emptyStateRow = document.createElement('tr');
                emptyStateRow.id = 'search-empty-state-sales';
                emptyStateRow.className = 'no-pagination';
                const colCount = tbody.closest('table').querySelectorAll('thead th').length;
                emptyStateRow.innerHTML = `<td colspan="${colCount}" class="px-8 py-24 text-center text-[11px] font-black italic uppercase tracking-[0.3em] text-[--text-main] opacity-20">No records found matching your search.</td>`;
                tbody.appendChild(emptyStateRow);
            }
            emptyStateRow.style.display = '';
        } else if (emptyStateRow) {
            emptyStateRow.style.display = 'none';
        }

        if (typeof refreshSalesPagination === 'function') {
            refreshSalesPagination();
        }
    }

    function syncDateBounds(source) {
        const fromInput = document.querySelector('input[name="date_from"]');
        const toInput = document.querySelector('input[name="date_to"]');
        const today = new Date().toISOString().split('T')[0];

        if (!fromInput || !toInput) return;

        if (source === 'from') {
            if (fromInput.value) {
                toInput.min = fromInput.value;
                if (toInput.value && fromInput.value > toInput.value) {
                    toInput.value = fromInput.value;
                }
            } else {
                toInput.removeAttribute('min');
            }
        } else if (source === 'to') {
            if (toInput.value) {
                fromInput.max = toInput.value;
                if (fromInput.value && toInput.value < fromInput.value) {
                    fromInput.value = toInput.value;
                }
            } else {
                fromInput.max = today;
            }
        }
    }

    window.addEventListener('DOMContentLoaded', () => {
        initTablePagination('salesTableBody', 'pagination-sales', 10);
        syncDateBounds('from');
        syncDateBounds('to');
    });

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
        contentClone.querySelectorAll('button, form, span.material-symbols-outlined, header, [id^="pagination-"], .pagination-status, .controls-container, .flex-wrap, h4, .screen-only-total, .filter-bar-wrapper').forEach(el => el.remove());
        
        // Remove the dashboard-style header container from the PDF
        const dashboardHeader = contentClone.querySelector('div.px-10.py-8');
        if (dashboardHeader) dashboardHeader.remove();

        // Remove the inner "Transaction History" table header from the PDF
        const tableHeaderToRemove = contentClone.querySelector('div.px-8.py-6');
        if (tableHeaderToRemove) tableHeaderToRemove.remove();

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
            <p style="margin: 0;">&copy; ${new Date().getFullYear()} Horizon System • Secured Sales Data</p>
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
</script>
    <!-- Restriction Modal (Sidebar-Aware) -->
    <div id="subModal">
        <div class="glass-card max-w-md w-full p-10 text-center animate-in zoom-in duration-300 relative shadow-[0_0_100px_rgba(var(--primary-rgb),0.15)] border-primary/20">
            <div class="size-20 rounded-3xl bg-primary/10 border border-primary/20 flex items-center justify-center mx-auto mb-8">
                <span class="material-symbols-outlined text-4xl text-primary">payments</span>
            </div>
            <h3 class="text-2xl font-black italic uppercase tracking-tighter mb-3" style="color: var(--text-main)">Subscription Required</h3>
            <p class="text-[10px] font-black uppercase tracking-[0.2em] opacity-60 mb-10 leading-relaxed italic px-4" style="color: var(--text-main)">
                Access to financial intelligence, revenue forecasting, and sales analytics is restricted. Your status is <span class="text-primary italic animate-pulse"><?= $sub_status ?></span>. Please activate a growth plan to unlock.
            </p>
            <div class="flex flex-col gap-4">
                <?php if (strpos($sub_status, 'Pending') !== false): ?>
                    <a href="tenant_dashboard.php" class="h-14 rounded-2xl bg-primary text-white text-[11px] font-black uppercase italic tracking-[0.2em] flex items-center justify-center gap-3 hover:scale-[1.02] active:scale-95 transition-all shadow-xl group" style="box-shadow: 0 10px 30px -10px rgba(var(--primary-rgb), 0.4)">
                        <span class="material-symbols-outlined text-xl group-hover:scale-110 transition-transform">grid_view</span>
                        Back to Dashboard
                    </a>
                <?php else: ?>
                    <a href="subscription_plan.php" class="h-14 rounded-2xl bg-primary text-white text-[11px] font-black uppercase italic tracking-[0.2em] flex items-center justify-center gap-3 hover:scale-[1.02] active:scale-95 transition-all shadow-xl group" style="box-shadow: 0 10px 30px -10px rgba(var(--primary-rgb), 0.4)">
                        <span class="material-symbols-outlined text-xl group-hover:scale-110 transition-transform">payments</span>
                        Select Growth Plan
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

</body>
</html>
