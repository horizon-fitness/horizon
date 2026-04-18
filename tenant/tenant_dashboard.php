<?php
session_start();
require_once '../db.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Security Check
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'tenant') {
    header("Location: ../login.php");
    exit;
}

$gym_id  = $_SESSION['gym_id']  ?? null;
$user_id = $_SESSION['user_id'] ?? null;

if (!$user_id || !$gym_id) {
    header("Location: ../login.php");
    exit;
}

// Fetch Gym & Owner Details
$stmtGym = $pdo->prepare("
    SELECT g.gym_name, u.first_name, u.last_name, g.owner_user_id
    FROM gyms g
    JOIN users u ON g.owner_user_id = u.user_id
    WHERE g.gym_id = ?
");
$stmtGym->execute([$gym_id]);
$gym_data = $stmtGym->fetch();

$gym_name      = $gym_data['gym_name']      ?? 'Horizon Gym';
$first_name    = $gym_data['first_name']    ?? 'Owner';
$owner_user_id = $gym_data['owner_user_id'] ?? 0;
$active_page   = "dashboard";

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

// ── Statistics ────────────────────────────────────────────────────────────────
$stmtStaff = $pdo->prepare("
    SELECT (
        (SELECT COUNT(*) FROM staff   WHERE gym_id = ? AND status = 'Active' AND user_id != ?) +
        (SELECT COUNT(*) FROM coaches WHERE gym_id = ? AND status = 'Active' AND user_id != ?)
    ) as total
");
$stmtStaff->execute([$gym_id, $owner_user_id, $gym_id, $owner_user_id]);
$total_staff = $stmtStaff->fetchColumn() ?: 0;

$stmtMembers = $pdo->prepare("SELECT COUNT(*) FROM members WHERE gym_id = ? AND member_status = 'Active'");
$stmtMembers->execute([$gym_id]);
$total_members = $stmtMembers->fetchColumn() ?: 0;

$stmtRev = $pdo->prepare("
    SELECT SUM(amount) FROM payments
    WHERE gym_id = ? AND payment_status IN ('Verified','Completed','Paid')
    AND client_subscription_id IS NULL
    AND MONTH(created_at) = MONTH(CURRENT_DATE) AND YEAR(created_at) = YEAR(CURRENT_DATE)
");
$stmtRev->execute([$gym_id]);
$monthly_rev = $stmtRev->fetchColumn() ?: 0;

// ── Subscription ──────────────────────────────────────────────────────────────
$stmtSub = $pdo->prepare("
    SELECT cs.subscription_status, cs.payment_term, cs.next_billing_date, cs.end_date, wp.plan_name
    FROM client_subscriptions cs
    JOIN website_plans wp ON cs.website_plan_id = wp.website_plan_id
    WHERE cs.gym_id = ?
    ORDER BY cs.created_at DESC LIMIT 1
");
$stmtSub->execute([$gym_id]);
$subscription = $stmtSub->fetch();

$plan_name         = $subscription['plan_name']           ?? 'No Plan';
$sub_status        = $subscription['subscription_status'] ?? 'None';
$payment_term      = $subscription['payment_term']        ?? 'Full';
$next_billing_date = $subscription['next_billing_date']   ?? null;
$plan_end_date     = $subscription['end_date']            ?? null;
$is_sub_active     = (strtolower($sub_status) === 'active');
$billing_label     = '';
$billing_color_cls = '';
$is_suspended      = false;

if ($is_sub_active && $payment_term === 'Monthly' && $next_billing_date) {
    $now_time  = strtotime('today');
    $due_time  = strtotime($next_billing_date);
    $diff_days = floor(($due_time - $now_time) / (60*60*24));
    if ($diff_days >= 0 && $diff_days <= 7) {
        $billing_color_cls = 'text-yellow-400';
        $billing_label = $diff_days === 0 ? "Due Today" : "Due in $diff_days days";
    } elseif ($diff_days < 0) {
        $billing_color_cls = 'text-rose-500';
        $abs = abs($diff_days);
        $billing_label = "Past Due ($abs days)";
        if ($abs > 3) {
            $is_suspended  = true;
            $sub_status    = 'Suspended';
            $is_sub_active = false;
            $pdo->prepare("UPDATE client_subscriptions SET subscription_status='Suspended', updated_at=NOW() WHERE gym_id=? AND subscription_status='Active'")->execute([$gym_id]);
        }
    } else {
        $billing_label = "Next Payment: " . date('M d', $due_time);
    }
} elseif ($is_sub_active && $payment_term === 'Full' && $plan_end_date) {
    $billing_label = "Active till " . date('M d, Y', strtotime($plan_end_date));
}

$page_title = "Owner Dashboard";

// ── Chart Data ────────────────────────────────────────────────────────────────
$revenue_trends = [];
$member_growth  = [];
for ($i = 5; $i >= 0; $i--) {
    $month = date('m', strtotime("-$i months"));
    $year  = date('Y', strtotime("-$i months"));
    $mname = date('M', strtotime("-$i months"));

    $s = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE gym_id=? AND payment_status IN ('Verified','Completed','Paid') AND client_subscription_id IS NULL AND MONTH(created_at)=? AND YEAR(created_at)=?");
    $s->execute([$gym_id, $month, $year]);
    $revenue_trends[] = ['month' => $mname, 'amount' => (float)($s->fetchColumn() ?: 0)];

    $s = $pdo->prepare("SELECT COUNT(*) FROM members WHERE gym_id=? AND MONTH(created_at)=? AND YEAR(created_at)=?");
    $s->execute([$gym_id, $month, $year]);
    $member_growth[] = ['month' => $mname, 'count' => (int)($s->fetchColumn() ?: 0)];
}
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title><?= htmlspecialchars($page_title) ?> | Horizon Partners</title>

    <link href="https://fonts.googleapis.com/css2?family=<?= urlencode($font_family) ?>:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: {
                "primary":       "var(--primary)",
                "background-dark":"var(--background)",
                "surface-dark":  "var(--card-bg)",
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

        /* Glass Card */
        .glass-card {
            background: var(--card-bg);
            border: 1px solid rgba(255,255,255,0.05);
            border-radius: 24px;
            backdrop-filter: blur(var(--card-blur));
            transition: all 0.4s cubic-bezier(0.4,0,0.2,1);
        }
        .hover-lift:hover {
            transform: translateY(-6px);
            border-color: rgba(var(--primary-rgb),0.25);
            box-shadow: 0 20px 40px -20px rgba(var(--primary-rgb),0.3);
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
            transition: margin-left 0.4s cubic-bezier(0.4,0,0.2,1);
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

        /* Invisible scroll */
        *::-webkit-scrollbar { display: none !important; }
        * { -ms-overflow-style: none !important; scrollbar-width: none !important; }

        /* Muted label utility */
        .label-muted {
            color: var(--text-main); opacity: 0.5;
            font-size: 10px; font-weight: 800;
            text-transform: uppercase; letter-spacing: 0.15em;
        }

        /* Primary icon helper */
        .icon-primary {
            background: rgba(var(--primary-rgb), 0.1);
            color: var(--primary);
        }

        /* Subscription modal */
        #subModal {
            position: fixed; top: 0; right: 0; bottom: 0; left: 110px;
            z-index: 200; display: none !important;
            align-items: center; justify-content: center;
            padding: 24px;
            background: rgba(0,0,0,0.8);
            backdrop-filter: blur(12px);
            transition: left 0.4s cubic-bezier(0.4,0,0.2,1);
        }
        #subModal.active { display: flex !important; }
        .side-nav:hover ~ #subModal { left: 300px; }
        #subModal.hard-lock { left: 0 !important; z-index: 9999 !important; background: rgba(10,9,13,0.95); }
    </style>

    <script>
        function showSubWarning() { document.getElementById('subModal').classList.add('active'); }
        function closeSubModal() {
            if (!document.getElementById('subModal').classList.contains('hard-lock')) {
                document.getElementById('subModal').classList.remove('active');
            }
        }
        function updateTopClock() {
            const el = document.getElementById('topClock');
            if (el) el.textContent = new Date().toLocaleTimeString('en-US', {hour:'2-digit',minute:'2-digit',second:'2-digit'});
        }
        setInterval(updateTopClock, 1000);
        window.addEventListener('DOMContentLoaded', updateTopClock);
    </script>
</head>

<body class="antialiased flex h-screen overflow-hidden">

    <?php include '../includes/tenant_sidebar.php'; ?>

    <main class="main-content flex-1 p-10 overflow-y-auto">

        <!-- Header -->
        <header class="mb-10 flex justify-between items-end">
            <div>
                <h2 class="text-3xl font-black uppercase tracking-tighter italic" style="color:var(--text-main)">
                    Welcome Back, <span style="color:var(--primary)" class="italic"><?= htmlspecialchars($first_name) ?></span>
                </h2>
                <p class="label-muted mt-1 italic"><?= htmlspecialchars($gym_name) ?> Management System</p>
            </div>
            <div class="text-right">
                <p id="topClock" class="font-black italic text-2xl leading-none tracking-tighter" style="color:var(--text-main)">00:00:00 AM</p>
                <p class="text-[10px] font-bold uppercase tracking-widest mt-2 px-1 opacity-80" style="color:var(--primary)"><?= date('l, M d, Y') ?></p>
            </div>
        </header>

        <!-- Subscription Alerts -->
        <?php if (strpos($sub_status, 'Pending Approval') !== false): ?>
            <div class="glass-card p-6 border-amber-500/30 bg-amber-500/5 mb-8 flex items-center gap-6">
                <div class="size-12 rounded-2xl bg-amber-500/20 flex items-center justify-center text-amber-500 shrink-0">
                    <span class="material-symbols-outlined text-2xl">hourglass_empty</span>
                </div>
                <div class="flex-1">
                    <h4 class="text-sm font-black uppercase italic tracking-tight text-amber-400">Subscription Pending Approval</h4>
                    <p class="text-[10px] font-bold text-amber-500/70 uppercase tracking-widest mt-1">Your payment is being verified. Access may be restricted until your plan is activated.</p>
                </div>
                <span class="px-3 py-1 rounded-full bg-amber-500/20 text-amber-400 text-[9px] font-black uppercase tracking-widest border border-amber-500/30">Verification In Progress</span>
            </div>
        <?php elseif ($sub_status === 'None' || $sub_status === 'Expired' || $sub_status === 'Inactive' || strpos($sub_status, 'Suspended') !== false): ?>
            <div class="glass-card p-6 border-rose-500/30 bg-rose-500/5 mb-8 flex items-center gap-6">
                <div class="size-12 rounded-2xl bg-rose-500/20 flex items-center justify-center text-rose-500 shrink-0">
                    <span class="material-symbols-outlined text-2xl">priority_high</span>
                </div>
                <div class="flex-1">
                    <h4 class="text-sm font-black uppercase italic tracking-tight text-rose-400"><?= $is_suspended ? 'System Access Suspended' : 'No Active Subscription' ?></h4>
                    <p class="text-[10px] font-bold text-rose-500/70 uppercase tracking-widest mt-1">Activate a growth plan to unlock your gym's digital infrastructure.</p>
                </div>
                <a href="subscription_plan.php" class="h-10 px-6 rounded-xl bg-rose-500 text-white text-[10px] font-black uppercase tracking-widest flex items-center justify-center hover:opacity-90 transition-all">
                    <?= $is_suspended ? 'Pay Overdue Balance' : 'Select Plan' ?>
                </a>
            </div>
        <?php endif; ?>

        <!-- Stat Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-10">

            <!-- Total Staff -->
            <a href="staff.php" class="glass-card p-8 relative overflow-hidden group block hover:scale-[1.02] transition-all"
                style="border-color:rgba(var(--primary-rgb),0.3);background:linear-gradient(135deg,rgba(var(--primary-rgb),0.05) 0%,var(--card-bg) 100%)">
                <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform" style="color:var(--primary)">badge</span>
                <p class="text-[10px] font-black uppercase tracking-widest mb-2 label-muted">Total Staff</p>
                <h3 class="text-2xl font-black italic uppercase" style="color:var(--text-main)"><?= $total_staff ?></h3>
                <p class="text-[10px] font-black uppercase mt-2" style="color:var(--primary)">Active Personnel</p>
            </a>

            <!-- Active Members -->
            <a href="my_users.php" class="glass-card p-8 relative overflow-hidden group block hover:scale-[1.02] transition-all"
                style="border-color:rgba(16,185,129,0.3);background:linear-gradient(135deg,rgba(16,185,129,0.05) 0%,var(--card-bg) 100%)">
                <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-emerald-500">group</span>
                <p class="text-[10px] font-black uppercase tracking-widest mb-2 label-muted">Active Members</p>
                <h3 class="text-2xl font-black italic uppercase" style="color:var(--text-main)"><?= $total_members ?></h3>
                <p class="text-emerald-500 text-[10px] font-black uppercase mt-2">Currently Enrolled</p>
            </a>

            <!-- Monthly Revenue -->
            <a href="transactions.php" class="glass-card p-8 relative overflow-hidden group block hover:scale-[1.02] transition-all"
                style="border-color:rgba(245,158,11,0.3);background:linear-gradient(135deg,rgba(245,158,11,0.05) 0%,var(--card-bg) 100%)">
                <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-amber-500">payments</span>
                <p class="text-[10px] font-black uppercase tracking-widest mb-2 label-muted">Revenue</p>
                <h3 class="text-2xl font-black italic uppercase" style="color:var(--text-main)">₱<?= number_format($monthly_rev, 0) ?></h3>
                <p class="text-amber-500 text-[10px] font-black uppercase mt-2">This Month</p>
            </a>

            <!-- Subscription -->
            <a href="subscription_plan.php" class="glass-card p-8 relative overflow-hidden group block hover:scale-[1.02] transition-all"
                style="<?= $is_sub_active ? 'border-color:rgba(59,130,246,0.3);background:linear-gradient(135deg,rgba(59,130,246,0.05) 0%,var(--card-bg) 100%)' : 'border-color:rgba(239,68,68,0.3);background:linear-gradient(135deg,rgba(239,68,68,0.05) 0%,var(--card-bg) 100%)' ?>">
                <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform <?= $is_sub_active ? 'text-blue-400' : 'text-rose-500' ?>">card_membership</span>
                <p class="text-[10px] font-black uppercase tracking-widest mb-2 label-muted">
                    <?= htmlspecialchars($plan_name) ?>
                </p>
                <h3 class="text-2xl font-black italic uppercase <?= ($is_sub_active && strtolower($sub_status) === 'active') ? 'text-emerald-500' : 'text-rose-500' ?>">
                    <?= htmlspecialchars($sub_status) ?>
                </h3>
                <?php if ($billing_label): ?>
                    <p class="text-[10px] font-black uppercase mt-2 <?= $billing_color_cls ?>"><?= $billing_label ?></p>
                <?php else: ?>
                    <p class="text-[10px] font-black uppercase mt-2 <?= $is_sub_active ? 'text-blue-400' : 'text-rose-500' ?>"><?= $is_sub_active ? 'Subscription Active' : 'Action Required' ?></p>
                <?php endif; ?>
            </a>
        </div>


        <!-- Charts -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 pb-10">
            <div class="glass-card p-8 hover-lift">
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h3 class="text-xs font-black italic uppercase tracking-widest text-white leading-none">Revenue Trends</h3>
                        <p class="text-[9px] text-[--text-main] opacity-50 font-bold uppercase mt-2 tracking-wider">Financial performance last 6 months</p>
                    </div>
                </div>
                <div class="h-[300px] w-full"><canvas id="revenueChart"></canvas></div>
            </div>

            <div class="glass-card p-8 hover-lift">
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h3 class="text-xs font-black italic uppercase tracking-widest text-white leading-none">Member Growth</h3>
                        <p class="text-[9px] text-[--text-main] opacity-50 font-bold uppercase mt-2 tracking-wider">New signups across time</p>
                    </div>
                </div>
                <div class="h-[300px] w-full"><canvas id="growthChart"></canvas></div>
            </div>
        </div>

    </main>

    <script>
        const primaryColor  = getComputedStyle(document.documentElement).getPropertyValue('--primary').trim();
        const textColor     = getComputedStyle(document.documentElement).getPropertyValue('--text-main').trim();
        const cardBg        = getComputedStyle(document.documentElement).getPropertyValue('--card-bg').trim();

        const chartOptions = {
            responsive: true,
            maintainAspectRatio: false,
            layout: {
                padding: { top: 30, right: 30, left: 10, bottom: 10 }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: cardBg,
                    titleColor: primaryColor,
                    bodyColor: textColor,
                    borderColor: 'rgba(255,255,255,0.1)',
                    borderWidth: 1,
                    padding: 12,
                    displayColors: false,
                    titleFont: { family: '<?= $font_family ?>', size: 10, weight: '800' },
                    bodyFont:  { family: '<?= $font_family ?>', size: 13, weight: '700' }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grace: '15%',
                    grid: { color: 'rgba(255,255,255,0.05)', drawBorder: false },
                    ticks: { color: '#666', font: { family: '<?= $font_family ?>', size: 9, weight: '800' } }
                },
                x: {
                    offset: true,
                    grid: { display: false, drawBorder: false },
                    ticks: { color: '#666', font: { family: '<?= $font_family ?>', size: 9, weight: '800' } }
                }
            }
        };

        new Chart(document.getElementById('revenueChart'), {
            type: 'line',
            data: {
                labels: [<?php foreach($revenue_trends as $r) echo "'".$r['month']."',"; ?>],
                datasets: [{
                    data: [<?php foreach($revenue_trends as $r) echo $r['amount'].","; ?>],
                    borderColor: primaryColor,
                    borderWidth: 3, tension: 0.4, fill: true,
                    backgroundColor: (ctx) => {
                        const g = ctx.chart.ctx.createLinearGradient(0,0,0,280);
                        g.addColorStop(0, primaryColor+'33');
                        g.addColorStop(1, primaryColor+'00');
                        return g;
                    },
                    pointRadius: 4, pointHoverRadius: 6,
                    pointBackgroundColor: primaryColor
                }]
            },
            options: chartOptions
        });

        new Chart(document.getElementById('growthChart'), {
            type: 'bar',
            data: {
                labels: [<?php foreach($member_growth as $m) echo "'".$m['month']."',"; ?>],
                datasets: [{
                    data: [<?php foreach($member_growth as $m) echo $m['count'].","; ?>],
                    backgroundColor: 'rgba(<?= $primary_rgb ?>, 0.15)',
                    hoverBackgroundColor: '<?= $theme_color ?>',
                    borderColor: '<?= $theme_color ?>',
                    borderWidth: 2,
                    borderRadius: { topLeft: 10, topRight: 10, bottomLeft: 0, bottomRight: 0 },
                    barPercentage: 0.6,
                    categoryPercentage: 0.8,
                    borderSkipped: false
                }]
            },
            options: chartOptions
        });
    </script>

    <!-- Subscription Modal -->
    <div id="subModal" class="<?= $is_suspended ? 'active hard-lock' : '' ?>">
        <div class="glass-card max-w-md w-full p-10 text-center relative"
            style="border-color:rgba(var(--primary-rgb),0.2);box-shadow:0 0 100px rgba(var(--primary-rgb),0.1)">
            <?php if (!$is_suspended): ?>
                <button onclick="closeSubModal()"
                    class="absolute top-6 right-6 size-10 rounded-xl hover:bg-white/5 flex items-center justify-center transition-all"
                    style="color:var(--text-main);opacity:0.5">
                    <span class="material-symbols-outlined">close</span>
                </button>
            <?php endif; ?>

            <div class="size-20 rounded-3xl border flex items-center justify-center mx-auto mb-8 <?= $is_suspended ? 'bg-red-500/10 border-red-500/20' : '' ?>"
                style="<?= !$is_suspended ? 'background:rgba(var(--primary-rgb),0.1);border-color:rgba(var(--primary-rgb),0.2)' : '' ?>">
                <span class="material-symbols-outlined text-4xl <?= $is_suspended ? 'text-red-500' : '' ?>"
                    style="<?= !$is_suspended ? 'color:var(--primary)' : '' ?>">lock</span>
            </div>

            <h3 class="text-2xl font-black italic uppercase tracking-tighter mb-3" style="color:var(--text-main)">
                <?= $is_suspended ? 'System Suspended' : 'Subscription Required' ?>
            </h3>
            <p class="label-muted mb-10 leading-relaxed italic px-4">
                <?= $is_suspended
                    ? 'Your subscription is critically overdue. Access to the gym system and your public portal has been restricted.'
                    : 'Your public gym portal is currently offline. Activate a growth plan to go live.' ?>
                <br><br>Status: <span class="italic animate-pulse <?= $is_suspended ? 'text-red-500' : '' ?>"
                    style="<?= !$is_suspended ? 'color:var(--primary)' : '' ?>"><?= $sub_status ?></span>
            </p>

            <div class="flex flex-col gap-4">
                <a href="subscription_plan.php"
                    class="h-14 rounded-2xl text-white text-[11px] font-black uppercase italic tracking-[0.2em] flex items-center justify-center gap-3 hover:scale-[1.02] active:scale-95 transition-all <?= $is_suspended ? 'bg-red-600' : '' ?>"
                    style="<?= !$is_suspended ? 'background:var(--primary);box-shadow:0 8px 30px rgba(var(--primary-rgb),0.3)' : '' ?>">
                    <span class="material-symbols-outlined text-xl">payments</span>
                    <?= $is_suspended ? 'Settle Overdue Balance' : 'Select Growth Plan' ?>
                </a>
                <?php if (!$is_suspended): ?>
                    <button onclick="closeSubModal()"
                        class="h-14 rounded-2xl bg-white/5 border border-white/10 text-[11px] font-black uppercase italic tracking-[0.2em] flex items-center justify-center hover:bg-white/10 transition-all"
                        style="color:var(--text-main)">
                        Dismiss
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

</body>
</html>