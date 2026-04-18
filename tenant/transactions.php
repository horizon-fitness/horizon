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

$active_page = 'transactions';

// Fetch Gym Details
$stmtGym = $pdo->prepare("SELECT gym_name, profile_picture as logo_path FROM gyms WHERE gym_id = ?");
$stmtGym->execute([$gym_id]);
$gym = $stmtGym->fetch();

// ── 4-Color Elite Branding System ─────────────────────────────────────────────
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

// 1. Hard defaults
$configs = [
    'system_name'     => $gym['gym_name'] ?? 'Horizon Gym',
    'system_logo'     => '',
    'theme_color'     => '#8c2bee',
    'secondary_color' => '#a1a1aa',
    'text_color'      => '#d1d5db',
    'bg_color'        => '#0a090d',
    'card_color'      => '#141216',
    'auto_card_theme' => '1',
    'font_family'     => 'Lexend',
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
    'system_name' => $configs['system_name'] ?? ($gym['gym_name'] ?? 'Horizon Gym'),
];

// --- CALCULATION LOGIC ---
// Total Revenue (Verified only)
$stmtTotal = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE gym_id = ? AND payment_status IN ('Verified', 'Completed', 'Paid') AND client_subscription_id IS NULL");
$stmtTotal->execute([$gym_id]);
$total_revenue = (float)($stmtTotal->fetchColumn() ?: 0);

// Monthly Sales
$stmtMonthly = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE gym_id = ? AND payment_status IN ('Verified', 'Completed', 'Paid') AND client_subscription_id IS NULL AND MONTH(created_at) = MONTH(CURRENT_DATE) AND YEAR(created_at) = YEAR(CURRENT_DATE)");
$stmtMonthly->execute([$gym_id]);
$monthly_sales = (float)($stmtMonthly->fetchColumn() ?: 0);

// Daily Revenue
$stmtDaily = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE gym_id = ? AND payment_status IN ('Verified', 'Completed', 'Paid') AND client_subscription_id IS NULL AND DATE(created_at) = CURRENT_DATE");
$stmtDaily->execute([$gym_id]);
$daily_sales = (float)($stmtDaily->fetchColumn() ?: 0);

// --- FILTERING & FETCH TRANSACTIONS ---
$f_date = $_GET['f_date'] ?? '';
$f_month = $_GET['f_month'] ?? '';
$f_year = $_GET['f_year'] ?? ''; // Default to empty to show all years initially

$where = ["p.gym_id = :gym_id", "p.client_subscription_id IS NULL"];
$params = [':gym_id' => $gym_id];

if (!empty($f_date)) {
    $where[] = "DATE(p.created_at) = :f_date";
    $params[':f_date'] = $f_date;
}

if (!empty($f_month)) {
    $where[] = "MONTH(p.created_at) = :f_month";
    $params[':f_month'] = $f_month;
}

if (!empty($f_year)) {
    $where[] = "YEAR(p.created_at) = :f_year";
    $params[':f_year'] = $f_year;
}

$where_sql = implode(" AND ", $where);
$stmtItems = $pdo->prepare("
    SELECT p.*, 
           COALESCE(u_member.first_name, u_owner.first_name) as first_name, 
           COALESCE(u_member.last_name, u_owner.last_name) as last_name 
    FROM payments p
    LEFT JOIN members m ON p.member_id = m.member_id
    LEFT JOIN users u_member ON m.user_id = u_member.user_id
    LEFT JOIN client_subscriptions cs ON p.client_subscription_id = cs.client_subscription_id
    LEFT JOIN users u_owner ON cs.owner_user_id = u_owner.user_id
    WHERE $where_sql
    ORDER BY p.created_at DESC
    LIMIT 100
");
$stmtItems->execute($params);
$transactions = $stmtItems->fetchAll();

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
    <title>Transactions | Horizon</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { 
                "primary": "var(--primary)",
                "background-dark": "var(--background)",
                "surface-dark": "var(--card-bg)",
                "border-subtle": "rgba(255,255,255,0.05)"
            }}}
        }
    </script>
    <style>
        /* ── Elite 4-Color CSS Variable System ── */
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
            border: 1px solid rgba(255,255,255,0.05); 
            border-radius: 24px; 
            backdrop-filter: blur(var(--card-blur));
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); 
        }
        .hover-lift:hover { 
            transform: translateY(-10px); 
            border-color: rgba(var(--primary-rgb), 0.25); 
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
            opacity: 0;
            transform: translateX(-15px);
            transition: all 0.3s ease-in-out;
            white-space: nowrap;
            pointer-events: none;
            color: var(--text-main);
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
        .nav-item .material-symbols-outlined {
            color: var(--highlight);
            transition: transform 0.2s ease;
        }
        .nav-item:hover .material-symbols-outlined { transform: scale(1.12); }
        .nav-item.active { color: var(--primary) !important; position: relative; }
        .nav-item.active .material-symbols-outlined { color: var(--primary); }
        .nav-item.active::after {
            content: ''; position: absolute;
            right: 0; top: 50%; transform: translateY(-50%);
            width: 4px; height: 24px;
            background: var(--primary); border-radius: 4px 0 0 4px;
        }

        /* Muted label utility */
        .label-muted {
            color: var(--text-main); 
            opacity: 0.6;
            font-size: 10px; 
            font-weight: 800;
            text-transform: uppercase; 
            letter-spacing: 0.15em;
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

        /* Invisible Scroll System */
        *::-webkit-scrollbar { display: none !important; }
        * { -ms-overflow-style: none !important; scrollbar-width: none !important; }

        .filter-input {
            background: rgba(255,255,255,0.03); 
            border: 1px solid rgba(255,255,255,0.08); 
            border-radius: 14px; 
            padding: 12px 18px; 
            color: var(--text-main); 
            font-size: 11px; 
            font-weight: 700; 
            outline: none; 
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
            appearance: none;
        }
        .filter-input:focus { border-color: var(--primary); background: rgba(var(--primary-rgb), 0.08); box-shadow: 0 0 0 4px rgba(var(--primary-rgb), 0.1); }

        select.filter-input {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%23<?= str_replace('#', '', $theme_color) ?>'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='3' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 1em;
            padding-right: 2.5rem;
        }

        .table-header-alt {
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.3em;
            color: var(--text-main);
            opacity: 0.35;
        }
        .filter-input option { background-color: #1a1821; color: white; }
        .filter-input:focus { border-color: var(--primary); background: rgba(var(--primary-rgb), 0.05); }

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
            background: rgba(0, 0, 0, 0.82); 
            backdrop-filter: blur(12px); 
            transition: left 0.4s cubic-bezier(0.4, 0, 0.2, 1); 
        }
        #subModal.active { display: flex !important; }
        .side-nav:hover ~ #subModal { left: 300px; }
    </style>
    <script>
        function updateTopClock() {
            const now = new Date();
            const clock = document.getElementById('topClock');
            if(clock) clock.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }
        setInterval(updateTopClock, 1000);
        window.addEventListener('DOMContentLoaded', updateTopClock);

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

<?php 
$active_page = 'transactions';
include '../includes/tenant_sidebar.php'; 
?>

<main class="main-content flex-1 p-10 overflow-y-auto no-scrollbar pb-10 <?= $is_restricted ? 'blur-overlay' : '' ?>">
    <div class="<?= $is_restricted ? 'blur-overlay-content' : '' ?>">

    <header class="mb-10 flex justify-between items-end">
        <div>
            <h2 class="text-3xl font-black uppercase tracking-tighter italic" style="color:var(--text-main)">
                Transactions
            </h2>
            <p class="label-muted mt-1 italic"><?= htmlspecialchars($gym['gym_name'] ?? 'Horizon Gym') ?> Finance Hub</p>
        </div>

        <div class="text-right">
            <p id="topClock" class="font-black italic text-2xl leading-none tracking-tighter" style="color:var(--text-main)">00:00:00 AM</p>
            <p class="text-[10px] font-bold uppercase tracking-widest mt-2 px-1 opacity-80" style="color:var(--primary)"><?= date('l, M d, Y') ?></p>
        </div>
    </header>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-10">
        <!-- Total Revenue -->
        <div class="glass-card p-8 status-card-green relative overflow-hidden group hover:scale-[1.02] transition-all">
            <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-emerald-500">payments</span>
            <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">Global Inflow</p>
            <h3 class="text-2xl font-black italic uppercase" style="color:var(--text-main)">₱<?= number_format($total_revenue, 2) ?></h3>
            <p class="text-emerald-500 text-[10px] font-black uppercase mt-2 italic">Total Revenue</p>
        </div>

        <!-- Monthly Sales -->
        <div class="glass-card p-8 status-card-primary relative overflow-hidden group hover:scale-[1.02] transition-all">
            <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform" style="color:var(--primary)">trending_up</span>
            <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">Monthly Velocity</p>
            <h3 class="text-2xl font-black italic uppercase" style="color:var(--primary)">₱<?= number_format($monthly_sales, 2) ?></h3>
            <p class="text-[10px] font-black uppercase mt-2 italic" style="color:var(--primary)">Active Sales</p>
        </div>

        <!-- Weekly Forecast -->
        <div class="glass-card p-8 status-card-primary relative overflow-hidden group hover:scale-[1.02] transition-all">
            <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform" style="color:var(--primary)">analytics</span>
            <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">Growth Projection</p>
            <h3 class="text-2xl font-black italic uppercase" style="color:var(--text-main)">₱<?= number_format($monthly_sales / 4, 2) ?></h3>
            <p class="text-[10px] font-black uppercase mt-2 italic" style="color:var(--primary)">Weekly Forecast</p>
        </div>

        <!-- Daily Revenue -->
        <div class="glass-card p-8 status-card-green relative overflow-hidden group hover:scale-[1.02] transition-all">
            <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-emerald-500">event_available</span>
            <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">Daily Pulse</p>
            <h3 class="text-2xl font-black italic uppercase text-emerald-500">₱<?= number_format($daily_sales, 2) ?></h3>
            <p class="text-emerald-500 text-[10px] font-black uppercase mt-2 italic">Daily Revenue</p>
        </div>
    </div>

    <div class="glass-card p-8 mb-10 border border-white/5 bg-white/[0.01]">
        <form method="GET" class="flex flex-wrap items-end gap-8">
            <div class="flex-1 min-w-[200px] flex flex-col gap-2.5">
                <label class="label-muted ml-1">Specific Date</label>
                <div class="relative group">
                    <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-sm transition-transform group-hover:scale-110" style="color:var(--primary)">calendar_today</span>
                    <input type="date" name="f_date" value="<?= htmlspecialchars($f_date) ?>" class="filter-input w-full pl-12">
                </div>
            </div>
            <div class="w-48 flex flex-col gap-2.5">
                <label class="label-muted ml-1">Month Filter</label>
                <select name="f_month" class="filter-input w-full">
                    <option value="">All Months</option>
                    <?php 
                    $months = ["01"=>"January", "02"=>"February", "03"=>"March", "04"=>"April", "05"=>"May", "06"=>"June", "07"=>"July", "08"=>"August", "09"=>"September", "10"=>"October", "11"=>"November", "12"=>"December"];
                    foreach($months as $num => $name) {
                        $sel = ($f_month === $num) ? 'selected' : '';
                        echo "<option value='$num' $sel>$name</option>";
                    }
                    ?>
                </select>
            </div>
            <div class="w-36 flex flex-col gap-2.5">
                <label class="label-muted ml-1">Year</label>
                <select name="f_year" class="filter-input w-full">
                    <option value="">All Years</option>
                    <option value="2026" <?= $f_year === '2026' ? 'selected' : '' ?>>2026</option>
                    <option value="2025" <?= $f_year === '2025' ? 'selected' : '' ?>>2025</option>
                    <option value="2024" <?= $f_year === '2024' ? 'selected' : '' ?>>2024</option>
                </select>
            </div>
            <div class="flex gap-2.5">
                <button type="submit" class="h-12 flex items-center justify-center gap-3 px-8 rounded-2xl text-white text-[10px] font-black uppercase italic tracking-widest transition-all hover:scale-[1.03] active:scale-95 shadow-xl group" style="background:var(--primary); shadow-color:rgba(var(--primary-rgb),0.2)">
                    <span class="material-symbols-outlined text-lg group-hover:rotate-12 transition-transform">filter_list</span>
                    Apply Filter
                </button>
                <a href="transactions.php" class="h-12 w-12 flex items-center justify-center rounded-2xl bg-white/5 border border-white/10 text-gray-500 hover:text-white hover:bg-white/10 transition-all shadow-lg" title="Reset View">
                    <span class="material-symbols-outlined text-xl">restart_alt</span>
                </a>
            </div>
        </form>
    </div>

    <div class="glass-card overflow-hidden shadow-2xl">
        <div class="px-8 py-6 border-b border-white/5 flex justify-between items-center bg-white/[0.02]">
            <h4 class="font-black italic uppercase text-xs tracking-widest flex items-center gap-2" style="color:var(--text-main)">
                <span class="material-symbols-outlined" style="color:var(--primary)">receipt_long</span> Transaction History
            </h4>
            <div class="label-muted italic uppercase">Recent Activity</div>
        </div>
        
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="border-b border-white/5 bg-white/[0.01]">
                        <th class="px-8 py-6 table-header-alt">Transaction ID</th>
                        <th class="px-8 py-6 table-header-alt">Customer / Member</th>
                        <th class="px-8 py-6 table-header-alt">Finance Trace</th>
                        <th class="px-8 py-6 table-header-alt">Process Method</th>
                        <th class="px-8 py-6 table-header-alt">Timestamp</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php 
                    $table_total = 0;
                    if(empty($transactions)): ?>
                        <tr><td colspan="5" class="px-8 py-24 text-center text-[11px] font-black italic uppercase tracking-[0.3em] text-[--text-main] opacity-20">No financial data detected.</td></tr>
                    <?php else: ?>
                        <?php foreach($transactions as $t): 
                            $table_total += $t['amount'];
                        ?>
                        <tr class="hover:bg-white/[0.04] transition-all group">
                            <td class="px-8 py-6">
                                <span class="text-[10px] font-black italic uppercase tracking-tight transition-colors group-hover:text-primary" style="color:color-mix(in srgb, var(--text-main) 60%, transparent)">
                                    <?= !empty($t['reference_number']) ? htmlspecialchars($t['reference_number']) : '#TRX-'.str_pad($t['payment_id'], 5, '0', STR_PAD_LEFT) ?>
                                </span>
                            </td>
                            <td class="px-8 py-6">
                                <p class="font-black italic uppercase tracking-tighter text-[13px] text-white">
                                    <?= (!empty($t['first_name'])) ? htmlspecialchars($t['first_name'].' '.$t['last_name']) : 'Manual Entry' ?>
                                </p>
                                <p class="text-[9px] font-black uppercase tracking-widest text-[--text-main] opacity-40 mt-1">Legitimer Member</p>
                            </td>
                            <td class="px-8 py-6">
                                <span class="text-base font-black italic text-emerald-500 tracking-tighter shadow-emerald-500/20 drop-shadow-lg">₱<?= number_format($t['amount'], 2) ?></span>
                            </td>
                            <td class="px-8 py-6">
                                <span class="text-[9px] font-black uppercase px-3 py-1.5 rounded-xl bg-white/5 border border-white/10 text-[--text-main] opacity-60 italic tracking-[0.1em] group-hover:opacity-100 transition-opacity">
                                    <?= $t['payment_method'] ?>
                                </span>
                            </td>
                            <td class="px-8 py-6">
                                <div class="flex flex-col">
                                    <span class="text-[11px] font-black italic text-white uppercase tracking-tighter text-white"><?= date('M d, Y', strtotime($t['created_at'])) ?></span>
                                    <span class="text-[9px] font-black uppercase tracking-widest text-[--text-main] opacity-30 mt-0.5"><?= date('h:i A', strtotime($t['created_at'])) ?></span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot class="bg-white/[0.01]">
                    <tr class="font-black italic uppercase tracking-tighter border-t border-white/5">
                        <td colspan="3" class="px-8 py-8">
                            <div class="flex items-center gap-3">
                                <div class="size-2 rounded-full bg-emerald-500 animate-pulse"></div>
                                <span class="text-xs tracking-[0.2em] text-[--text-main] opacity-40">Verified Transactions Summary</span>
                            </div>
                        </td>
                        <td colspan="2" class="px-8 py-8 text-right">
                            <span class="text-[10px] mr-4 text-[--text-main] opacity-30">CUMULATIVE LOAD</span>
                            <span class="text-2xl font-black italic text-emerald-500 drop-shadow-[0_0_10px_rgba(16,185,129,0.3)]">
                                ₱<?= number_format($table_total, 2) ?>
                            </span>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</main>

    <!-- Restriction Modal (Sidebar-Aware) -->
    <div id="subModal">
        <div class="glass-card max-w-md w-full p-10 text-center animate-in zoom-in duration-300 relative shadow-[0_0_100px_rgba(var(--primary-rgb),0.15)]" style="border-color:rgba(var(--primary-rgb),0.2)">
            <div class="size-20 rounded-3xl flex items-center justify-center mx-auto mb-8 border" style="background:rgba(var(--primary-rgb),0.1); border-color:rgba(var(--primary-rgb),0.2)">
                <span class="material-symbols-outlined text-4xl" style="color:var(--primary)">lock</span>
            </div>
            <h3 class="text-2xl font-black italic uppercase tracking-tighter mb-3" style="color:var(--text-main)">Subscription Required</h3>
            <p class="label-muted mb-10 leading-relaxed italic px-4">
                Access to financial transactions and ledger history is restricted. Your status is <span class="italic animate-pulse" style="color:var(--primary)"><?= $sub_status ?></span>. Please activate a growth plan to unlock.
            </p>
            <div class="flex flex-col gap-4">
                <?php if (strpos($sub_status, 'Pending') !== false): ?>
                    <a href="tenant_dashboard.php" class="h-14 rounded-2xl text-white text-[11px] font-black uppercase italic tracking-[0.2em] flex items-center justify-center gap-3 hover:scale-[1.02] active:scale-95 transition-all shadow-xl group" style="background:var(--primary); shadow-color:rgba(var(--primary-rgb),0.2)">
                        <span class="material-symbols-outlined text-xl group-hover:scale-110 transition-transform">grid_view</span>
                        Back to Dashboard
                    </a>
                <?php else: ?>
                    <a href="subscription_plan.php" class="h-14 rounded-2xl text-white text-[11px] font-black uppercase italic tracking-[0.2em] flex items-center justify-center gap-3 hover:scale-[1.02] active:scale-95 transition-all shadow-xl group" style="background:var(--primary); shadow-color:rgba(var(--primary-rgb),0.2)">
                        <span class="material-symbols-outlined text-xl group-hover:scale-110 transition-transform">payments</span>
                        Select Growth Plan
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

</body>
</html>
