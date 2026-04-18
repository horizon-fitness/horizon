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
$stmtTotal = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE gym_id = ? AND payment_status IN ('Verified', 'Completed', 'Paid') AND client_subscription_id IS NULL AND DATE(created_at) BETWEEN ? AND ?");
$stmtTotal->execute([$gym_id, $date_from, $date_to]);
$total_revenue = $stmtTotal->fetchColumn() ?? 0;

// Lifetime Revenue (Verified only)
$stmtLifetime = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE gym_id = ? AND payment_status IN ('Verified', 'Completed', 'Paid') AND client_subscription_id IS NULL");
$stmtLifetime->execute([$gym_id]);
$lifetime_revenue = $stmtLifetime->fetchColumn() ?? 0;

// Today's Sales
$stmtDaily = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE gym_id = ? AND payment_status IN ('Verified', 'Completed', 'Paid') AND client_subscription_id IS NULL AND DATE(created_at) = CURDATE()");
$stmtDaily->execute([$gym_id]);
$daily_sales = $stmtDaily->fetchColumn() ?? 0;

// --- TRANSACTION HISTORY (Filtered) ---
$stmtHistory = $pdo->prepare("
    SELECT p.*, 
           u_member.first_name, 
           u_member.last_name 
    FROM payments p 
    LEFT JOIN member_subscriptions ms ON p.subscription_id = ms.subscription_id
    LEFT JOIN members m ON p.member_id = m.member_id
    LEFT JOIN users u_member ON m.user_id = u_member.user_id
    WHERE p.gym_id = ? AND p.client_subscription_id IS NULL AND DATE(p.created_at) BETWEEN ? AND ?
    ORDER BY p.created_at DESC
");
$stmtHistory->execute([$gym_id, $date_from, $date_to]);
$transactions = $stmtHistory->fetchAll();

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

        .table-header-alt {
            font-size: 10px; font-weight: 900;
            text-transform: uppercase; letter-spacing: 0.3em;
            color: var(--text-main); opacity: 0.35;
        }

        .filter-input {
            background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); 
            border-radius: 14px; padding: 12px 18px; color: var(--text-main); 
            font-size: 11px; font-weight: 700; outline: none; 
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); appearance: none;
        }
        .filter-input:focus { border-color: var(--primary); background: rgba(var(--primary-rgb), 0.08); box-shadow: 0 0 0 4px rgba(var(--primary-rgb), 0.1); }

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

    <!-- Polished Filter Bar -->
    <div class="glass-card p-8 mb-10 border border-white/5 bg-white/[0.01]">
        <form method="GET" class="flex flex-wrap items-end gap-8 relative z-10">
            <div class="flex-1 min-w-[200px] flex flex-col gap-2.5">
                <label class="label-muted ml-1 flex items-center gap-2 text-[10px]">
                    <span class="material-symbols-outlined text-xs text-primary">calendar_today</span> Start Date
                </label>
                <input type="date" name="date_from" value="<?= $date_from ?>" class="filter-input w-full">
            </div>
            <div class="flex-1 min-w-[200px] flex flex-col gap-2.5">
                <label class="label-muted ml-1 flex items-center gap-2 text-[10px]">
                    <span class="material-symbols-outlined text-xs text-primary">event</span> End Date
                </label>
                <input type="date" name="date_to" value="<?= $date_to ?>" class="filter-input w-full">
            </div>
            <div class="flex gap-3">
                <button type="submit" class="h-12 px-10 rounded-2xl text-white text-[10px] font-black uppercase italic tracking-widest transition-all hover:scale-[1.03] active:scale-95 shadow-xl group" style="background:var(--primary); box-shadow: 0 10px 30px -10px rgba(var(--primary-rgb), 0.4)">
                    <span class="material-symbols-outlined text-lg group-hover:rotate-12 transition-transform">filter_list</span>
                    Apply Filter
                </button>
                <a href="sales_report.php" class="h-12 w-12 flex items-center justify-center rounded-2xl bg-white/5 border border-white/10 text-[10px] font-black uppercase tracking-widest text-[--text-main] opacity-40 hover:opacity-100 hover:bg-white/10 transition-all shadow-lg" title="Clear Filters">
                    <span class="material-symbols-outlined text-xl">restart_alt</span>
                </a>
            </div>

            <div class="ml-auto flex gap-3 h-12">
                <button type="button" onclick="exportReportToPDF('table-export-anchor', 'Sales Intelligence', true)" class="px-6 rounded-xl bg-white/5 border border-white/5 font-black italic uppercase text-[10px] tracking-widest opacity-60 hover:opacity-100 transition-all flex items-center gap-2" style="color: var(--text-main)">
                    <span class="material-symbols-outlined text-sm">visibility</span> View Report
                </button>
                <button type="button" onclick="exportReportToPDF('table-export-anchor', 'Sales Intelligence', false)" class="px-6 rounded-xl bg-primary/10 border border-primary/20 font-black italic uppercase text-[10px] tracking-widest text-primary hover:bg-primary/20 transition-all flex items-center gap-2">
                    <span class="material-symbols-outlined text-sm">picture_as_pdf</span> Get PDF
                </button>
            </div>
        </form>
    </div>

    <!-- Export Anchor: This wraps only the table and its specific header for the PDF -->
    <div id="table-export-anchor" class="glass-card overflow-hidden shadow-2xl min-h-[500px] border border-white/5">
        <div class="px-10 py-8 border-b border-white/5 flex justify-between items-center bg-white/[0.02]">
            <h4 class="font-black italic uppercase text-xs tracking-widest text-primary flex items-center gap-3">
                <span class="material-symbols-outlined text-lg">payments</span>
                Transaction History
            </h4>
            <span class="text-[10px] font-black uppercase tracking-widest opacity-40" style="color: var(--text-main)">
                Showing <?= count($transactions) ?> Records
            </span>
        </div>
        
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="border-b border-white/5 bg-white/[0.01]">
                        <th class="px-10 py-6 table-header-alt">Ref ID</th>
                        <th class="px-10 py-6 table-header-alt">Payer / Member</th>
                        <th class="px-10 py-6 table-header-alt">Amount</th>
                        <th class="px-10 py-6 table-header-alt text-right">Date & Time</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5 text-sm font-medium">
                    <?php if(empty($transactions)): ?>
                        <tr>
                            <td colspan="4" class="px-10 py-24 text-center">
                                <p class="text-[10px] font-black uppercase opacity-30 italic tracking-widest" style="color: var(--text-main)">No sales recorded for this period</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach($transactions as $t): ?>
                        <tr class="hover:bg-white/[0.04] transition-all group">
                            <td class="px-10 py-6 font-mono text-[10px] opacity-40 group-hover:opacity-100 transition-opacity" style="color: var(--text-main)">
                                <?= !empty($t['reference_number']) ? htmlspecialchars($t['reference_number']) : '#'.str_pad($t['payment_id'], 5, '0', STR_PAD_LEFT) ?>
                            </td>
                            <td class="px-10 py-6">
                                <div class="flex items-center gap-4">
                                    <div class="size-2 rounded-full bg-primary/20 group-hover:bg-primary transition-colors"></div>
                                    <div>
                                        <p class="font-black italic uppercase tracking-tighter text-xs" style="color: var(--text-main)">
                                            <?= $t['first_name'] ? htmlspecialchars($t['first_name'].' '.$t['last_name']) : 'Walk-in Guest' ?>
                                        </p>
                                        <p class="text-[9px] font-black uppercase tracking-widest mt-1 opacity-40 group-hover:opacity-60 transition-opacity" style="color: var(--text-main)">
                                            <?= htmlspecialchars($t['payment_method']) ?> Verified
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-10 py-6">
                                <div class="flex flex-col">
                                    <span class="font-black italic group-hover:text-primary transition-colors" style="color: var(--text-main)">₱<?= number_format($t['amount'], 2) ?></span>
                                    <span class="text-[8px] font-black text-primary uppercase tracking-widest opacity-60">Paid In Full</span>
                                </div>
                            </td>
                            <td class="px-10 py-6 text-right">
                                <div class="flex flex-col items-end leading-tight">
                                    <span class="font-black uppercase text-primary tracking-tighter italic text-xs">
                                        <?= date('M d, Y', strtotime($t['created_at'])) ?>
                                    </span>
                                    <span class="text-[10px] font-bold uppercase tracking-widest mt-1 opacity-40 group-hover:opacity-100 transition-opacity" style="color: var(--text-main)">
                                        <?= date('h:i A', strtotime($t['created_at'])) ?>
                                    </span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot class="border-t-2 border-white/10 bg-white/[0.02]">
                    <tr>
                        <td colspan="2" class="px-10 py-6 text-xs font-black uppercase italic tracking-widest text-primary">Total Interval Revenue</td>
                        <td colspan="2" class="px-10 py-6 text-right text-xl font-black italic" style="color: var(--text-main)">
                            ₱<?= number_format($total_revenue, 2) ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
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

    function exportReportToPDF(sectionId, reportTitle, preview = false) {
        const element = document.getElementById(sectionId);
        const gymName = "<?= htmlspecialchars($gym_name) ?>";
        const generatedAt = "<?= date('M d, Y h:i A') ?>";
        const dateRange = "Period: <?= date('M d, Y', strtotime($date_from)) ?> - <?= date('M d, Y', strtotime($date_to)) ?>";

        const wrapper = document.createElement('div');
        wrapper.style.padding = '50px';
        wrapper.style.color = '#111';
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
        // Remove UI clutter but protect table hierarchy
        contentClone.querySelectorAll('button, form, span.material-symbols-outlined, header, .flex-wrap, h4').forEach(el => el.remove());
        
        // Remove the dashboard-style header container from the PDF
        const dashboardHeader = contentClone.querySelector('div.px-10.py-8');
        if (dashboardHeader) dashboardHeader.remove();

        [contentClone, ...contentClone.querySelectorAll('*')].forEach(el => {
            // Capture alignment BEFORE stripping classes
            const isRightAligned = el.classList.contains('text-right');
            
            el.removeAttribute('class');
            el.style.setProperty('color', '#000000', 'important');
            el.style.setProperty('background-color', 'transparent', 'important');
            el.style.setProperty('border-radius', '0', 'important');
            el.style.setProperty('box-shadow', 'none', 'important');
            el.style.setProperty('text-shadow', 'none', 'important');
            el.style.setProperty('filter', 'none', 'important');
            el.style.setProperty('opacity', '1', 'important');
            el.style.setProperty('visibility', 'visible', 'important');
            el.style.setProperty('font-family', "'Inter', sans-serif", 'important');
            
            if (isRightAligned) {
                el.style.setProperty('text-align', 'right', 'important');
            }
        });

        // 3. TABLE TRANSFORMATION
        const table = contentClone.querySelector('table');
        if (table) {
            table.style.setProperty('width', '100%', 'important');
            table.style.setProperty('border-collapse', 'collapse', 'important');
            table.style.setProperty('font-size', '10px', 'important');
            table.style.setProperty('margin-top', '20px', 'important');

            table.querySelectorAll('th').forEach(th => {
                th.style.setProperty('background-color', '#f8f9fa', 'important');
                th.style.setProperty('color', '#111', 'important');
                th.style.setProperty('border-bottom', '2px solid #000', 'important');
                th.style.setProperty('padding', '12px 14px', 'important');
                th.style.setProperty('text-transform', 'uppercase', 'important');
                th.style.setProperty('font-weight', '700', 'important');
                th.style.setProperty('text-align', 'left', 'important');
            });

            table.querySelectorAll('tr').forEach(tr => {
                tr.style.setProperty('display', 'table-row', 'important');
            });

            table.querySelectorAll('td').forEach(td => {
                td.style.setProperty('border-bottom', '1px solid #eeeeee', 'important');
                td.style.setProperty('padding', '10px 14px', 'important');
                td.style.setProperty('color', '#333', 'important');
            });

            // Bold Footer styling for professional PDF
            const tfoot = table.querySelector('tfoot');
            if (tfoot) {
                tfoot.querySelectorAll('td').forEach(td => {
                    td.style.setProperty('background-color', '#fafafa', 'important');
                    td.style.setProperty('font-weight', '900', 'important');
                    td.style.setProperty('font-size', '12px', 'important');
                    td.style.setProperty('border-top', '2px solid #000', 'important');
                    td.style.setProperty('border-bottom', 'none', 'important');
                    td.style.setProperty('padding', '14px 14px', 'important');
                });
            }
        }

        const footer = document.createElement('div');
        footer.style.marginTop = '60px';
        footer.style.textAlign = 'center';
        footer.style.fontSize = '9px';
        footer.style.color = '#777';
        footer.style.borderTop = '1px solid #eee';
        footer.style.paddingTop = '15px';
        footer.innerHTML = `<p>&copy; ${new Date().getFullYear()} Horizon System • Secured Sales Data</p>`;

        wrapper.innerHTML = header;
        wrapper.appendChild(contentClone);
        wrapper.appendChild(footer);

        const opt = {
            margin: [0.4, 0.4],
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
