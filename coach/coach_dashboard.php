<?php
session_start();
require_once '../db.php';

// Security Check: Restricted to Coach role only
$role = strtolower($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || $role !== 'coach') {
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$gym_id = $_SESSION['gym_id'];
$username = $_SESSION['username'] ?? 'Coach';
$coach_name = ($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '');
$active_page = "dashboard";

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
$stmtGymBranding = $pdo->prepare("SELECT owner_user_id, gym_name FROM gyms WHERE gym_id = ?");
$stmtGymBranding->execute([$gym_id]);
$gym_branding_data = $stmtGymBranding->fetch();
$owner_user_id = $gym_branding_data['owner_user_id'] ?? 0;
$gym_name = $gym_branding_data['gym_name'] ?? 'Horizon Gym';

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

// 1. Merge global settings (user_id = 0)
$stmtGlobal = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = 0");
$stmtGlobal->execute();
foreach (($stmtGlobal->fetchAll(PDO::FETCH_KEY_PAIR) ?: []) as $k => $v) {
    if ($v !== null && $v !== '') $configs[$k] = $v;
}

// 2. Merge tenant-specific settings (user_id = owner_user_id)
$stmtTenant = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = ?");
$stmtTenant->execute([$owner_user_id]);
foreach (($stmtTenant->fetchAll(PDO::FETCH_KEY_PAIR) ?: []) as $k => $v) {
    if ($v !== null && $v !== '') $configs[$k] = $v;
}

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

$page = [
    'logo_path'   => $configs['system_logo'] ?? '',
    'theme_color' => $theme_color,
    'bg_color'    => $bg_color,
    'system_name' => $configs['system_name'] ?? $gym_name,
];
// ─────────────────────────────────────────────────────────────────────────────

// Fetch Coach ID
$stmtCoach = $pdo->prepare("SELECT coach_id FROM coaches WHERE user_id = ? AND gym_id = ? LIMIT 1");
$stmtCoach->execute([$user_id, $gym_id]);
$coach_info = $stmtCoach->fetch();
$coach_id = $coach_info ? $coach_info['coach_id'] : 0;

// Stats
$today = date('Y-m-d');
$total_members_coached = 0;
$upcoming_sessions = 0;
$done_count = 0;
$history_bookings = [];
$schedule_result = [];

if ($coach_id > 0) {
    // 1. Total Clients (Unique members with past or current approved bookings)
    $stmtMembers = $pdo->prepare("SELECT COUNT(DISTINCT member_id) FROM bookings WHERE coach_id = ? AND booking_status != 'Rejected'");
    $stmtMembers->execute([$coach_id]);
    $total_members_coached = $stmtMembers->fetchColumn();

    // 2. Upcoming Sessions (Future bookings or Today's sessions not yet started)
    $stmtUpcoming = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE coach_id = ? AND (booking_date > ? OR (booking_date = ? AND booking_status = 'Pending')) AND booking_status != 'Rejected'");
    $stmtUpcoming->execute([$coach_id, $today, $today]);
    $upcoming_sessions = $stmtUpcoming->fetchColumn();

    // 3. Done Sessions (Completed or Past sessions)
    $stmtDone = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE coach_id = ? AND (booking_status = 'Completed' OR (booking_date < ? AND booking_status != 'Rejected'))");
    $stmtDone->execute([$coach_id, $today]);
    $done_count = $stmtDone->fetchColumn();

    // 4. Fetch Session History (Past or Completed) - Ensure multiple bookings for same client show up
    $stmtHistory = $pdo->prepare("
        SELECT b.*, u.first_name, u.last_name, sc.service_name as service_name 
        FROM bookings b 
        JOIN members m ON b.member_id = m.member_id 
        JOIN users u ON m.user_id = u.user_id 
        LEFT JOIN service_catalog sc ON b.catalog_service_id = sc.catalog_service_id 
        WHERE b.coach_id = ? AND (b.booking_date < ? OR b.booking_status = 'Completed')
        ORDER BY b.booking_date DESC, b.start_time DESC
        LIMIT 10
    ");
    $stmtHistory->execute([$coach_id, $today]);
    $history_bookings = $stmtHistory->fetchAll();

    // 5. Today's Schedule (Show ALL for today including Pending to fix visibility issue)
    $stmtSched = $pdo->prepare("
        SELECT b.*, u.first_name, u.last_name, u.username, sc.service_name as service_name 
        FROM bookings b
        JOIN members m ON b.member_id = m.member_id
        JOIN users u ON m.user_id = u.user_id
        LEFT JOIN service_catalog sc ON b.catalog_service_id = sc.catalog_service_id
        WHERE b.coach_id = ? AND b.booking_date = ? AND b.booking_status != 'Rejected'
        ORDER BY b.start_time ASC
    ");
    $stmtSched->execute([$coach_id, $today]);
    $schedule_result = $stmtSched->fetchAll();
}
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>Coach Dashboard | Horizon Systems</title>
    <link href="https://fonts.googleapis.com/css2?family=<?= urlencode($font_family) ?>:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet" />
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { 
                "primary": "var(--primary)", 
                "background": "var(--background)", 
                "card-bg": "var(--card-bg)", 
                "text-main": "var(--text-main)",
                "highlight": "var(--highlight)"
            } } }
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

        body { font-family: '<?= $font_family ?>', sans-serif; background-color: var(--background); color: var(--text-main); overflow: hidden; }

        .glass-card {
            background: var(--card-bg);
            border: 1px solid rgba(255,255,255,0.05);
            border-radius: 32px;
            backdrop-filter: blur(var(--card-blur));
            transition: all 0.4s cubic-bezier(0.4,0,0.2,1);
        }

        .side-nav {
            width: 110px;
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            position: fixed;
            left: 0; top: 0;
            height: 100vh;
            z-index: 50;
            background: var(--background);
            border-right: 1px solid rgba(255, 255, 255, 0.05);
        }
        .side-nav:hover { width: 300px; }

        .main-content {
            margin-left: 110px;
            flex: 1;
            min-width: 0;
            transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow-y: auto;
        }
        .side-nav:hover~.main-content { margin-left: 300px; }

        .nav-label { opacity: 0; transform: translateX(-15px); transition: all 0.3s ease-in-out; white-space: nowrap; pointer-events: none; color: var(--text-main); }
        .side-nav:hover .nav-label { opacity: 1; transform: translateX(0); pointer-events: auto; }

        .nav-section-label {
            max-height: 0; opacity: 0; overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            margin: 0 !important; pointer-events: none;
        }
        .side-nav:hover .nav-section-label {
            max-height: 20px; opacity: 1;
            margin-bottom: 8px !important; pointer-events: auto;
        }

        .nav-item {
            display: flex; align-items: center; gap: 20px;
            padding: 12px 43px;
            transition: all 0.2s ease;
            text-decoration: none; white-space: nowrap;
            font-size: 11px; font-weight: 800;
            text-transform: uppercase; letter-spacing: 0.1em;
            color: color-mix(in srgb, var(--text-main) 40%, transparent);
            position: relative;
        }
        
        .nav-item .material-symbols-rounded,
        .nav-item .material-symbols-outlined { color: var(--highlight); transition: transform 0.2s ease, color 0.2s ease; }
        
        .nav-item:hover { color: var(--text-main); background: rgba(255,255,255,0.02); }
        .nav-item:hover .material-symbols-rounded,
        .nav-item:hover .material-symbols-outlined { color: var(--text-main); transform: scale(1.15); }
        
        .nav-item.active { color: var(--primary) !important; position: relative; background: transparent !important; }
        .nav-item.active .material-symbols-rounded,
        .nav-item.active .material-symbols-outlined { color: var(--primary); }

        .material-symbols-outlined, .material-symbols-rounded {
            font-family: 'Material Symbols Rounded' !important;
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
            display: inline-block; line-height: 1;
        }

        .status-card-primary { border: 1px solid rgba(var(--primary-rgb), 0.2); background: linear-gradient(135deg, rgba(var(--primary-rgb), 0.1) 0%, rgba(20, 18, 26, 0) 100%); }
        .status-card-green   { border: 1px solid rgba(16, 185, 129, 0.2); background: linear-gradient(135deg, rgba(16, 185, 129, 0.05) 0%, rgba(20, 18, 26, 0) 100%); }
        .status-card-yellow  { border: 1px solid rgba(245, 158, 11, 0.2);  background: linear-gradient(135deg, rgba(245, 158, 11, 0.05)  0%, rgba(20, 18, 26, 0) 100%); }
        .status-card-blue    { border: 1px solid rgba(59, 130, 246, 0.2);  background: linear-gradient(135deg, rgba(59, 130, 246, 0.05)  0%, rgba(20, 18, 26, 0) 100%); }

        .no-scrollbar::-webkit-scrollbar { display: none !important; }
        * { -ms-overflow-style: none !important; scrollbar-width: none !important; }

        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fade-in { animation: fadeIn 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
    </style>
    <script>
        function updateHeaderClock() {
            const now = new Date();
            const clockEl = document.getElementById('headerClock');
            if (clockEl) clockEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }
        setInterval(updateHeaderClock, 1000);
        window.addEventListener('DOMContentLoaded', updateHeaderClock);
    </script>
</head>
<body class="antialiased flex h-screen overflow-hidden">

    <?php include '../includes/coach_sidebar.php'; ?>

    <div class="main-content flex-1 overflow-y-auto no-scrollbar">
        <div class="p-10 max-w-[1500px] mx-auto animate-fade-in">
            <header class="mb-12 flex flex-col md:flex-row justify-between items-start md:items-end gap-6">
                <div>
                    <h2 class="text-4xl font-black italic uppercase tracking-tighter leading-none" style="color:var(--text-main)">
                        Welcome Back, <span style="color:var(--primary)"><?= htmlspecialchars($coach_name ?: 'Coach') ?></span>
                    </h2>
                    <p class="text-[--text-main]/30 text-[11px] font-black uppercase tracking-[0.2em] mt-2 px-1 italic">Coach Overview & Performance Tracker</p>
                </div>
                <div class="flex flex-col items-end">
                    <p id="headerClock" class="font-black italic text-2xl leading-none tracking-tighter" style="color:var(--text-main)">00:00:00 AM</p>
                    <p class="text-primary text-[11px] font-black uppercase tracking-[0.2em] leading-none mt-2">
                        <?= date('l, M d, Y') ?>
                    </p>
                </div>
            </header>

            <!-- 3 Stat Cards Only -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-12">
                <div class="glass-card p-8 status-card-green relative overflow-hidden group hover:scale-[1.02]">
                    <span class="material-symbols-rounded absolute right-6 top-1/2 -translate-y-1/2 text-7xl opacity-5 group-hover:scale-110 transition-transform text-emerald-500">groups</span>
                    <p class="text-[11px] font-black uppercase text-[--text-main]/40 mb-2 tracking-[0.15em]">Total Clients</p>
                    <h3 class="text-3xl font-black italic uppercase text-white leading-none tracking-tighter"><?= $total_members_coached ?></h3>
                </div>

                <div class="glass-card p-8 status-card-blue relative overflow-hidden group hover:scale-[1.02]">
                    <span class="material-symbols-rounded absolute right-6 top-1/2 -translate-y-1/2 text-7xl opacity-5 group-hover:scale-110 transition-transform text-blue-500">schedule</span>
                    <p class="text-[11px] font-black uppercase text-[--text-main]/40 mb-2 tracking-[0.15em]">Upcoming sessions</p>
                    <h3 class="text-3xl font-black italic uppercase text-white leading-none tracking-tighter"><?= $upcoming_sessions ?></h3>
                </div>

                <div class="glass-card p-8 status-card-primary relative overflow-hidden group hover:scale-[1.02]">
                    <span class="material-symbols-rounded absolute right-6 top-1/2 -translate-y-1/2 text-7xl opacity-5 group-hover:scale-110 transition-transform text-primary">verified</span>
                    <p class="text-[11px] font-black uppercase text-[--text-main]/40 mb-2 tracking-[0.15em]">Done sessions</p>
                    <h3 class="text-3xl font-black italic uppercase text-white leading-none tracking-tighter"><?= $done_count ?></h3>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-10">
                <!-- Session History -->
                <div class="glass-card flex flex-col overflow-hidden shadow-2xl shadow-primary/5">
                    <div class="px-8 py-6 border-b border-white/5 flex justify-between items-center bg-white/[0.01]">
                        <h4 class="text-sm font-black italic uppercase tracking-tighter flex items-center gap-2" style="color:var(--text-main)">
                            <span class="material-symbols-rounded text-primary text-xl">history</span> Recent History
                        </h4>
                        <div class="flex items-center gap-2">
                            <span class="size-2 rounded-full bg-[--highlight] opacity-40"></span>
                            <p class="text-[10px] font-black uppercase tracking-widest text-[--text-main]/40">Past Logs</p>
                        </div>
                    </div>
                    <div class="overflow-x-auto no-scrollbar">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="text-[10px] font-black uppercase tracking-widest opacity-40 border-b border-white/5">
                                    <th class="px-8 py-5">Member</th>
                                    <th class="px-8 py-5 text-center">Date</th>
                                    <th class="px-8 py-5 text-right">Result</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/5">
                                <?php if (empty($history_bookings)): ?>
                                    <tr>
                                        <td colspan="3" class="px-8 py-20 text-center text-xs font-bold uppercase tracking-widest opacity-20 italic">No past sessions found</td>
                                    </tr>
                                <?php else: foreach ($history_bookings as $hb): ?>
                                    <tr class="hover:bg-white/[0.01] group transition-colors">
                                        <td class="px-8 py-6">
                                            <p class="text-sm font-bold uppercase group-hover:text-primary transition-colors italic text-white leading-none">
                                                <?= htmlspecialchars($hb['first_name'] . ' ' . $hb['last_name']) ?>
                                            </p>
                                            <p class="text-[10px] font-black uppercase tracking-widest opacity-30 mt-2">
                                                <?= strtoupper($hb['service_name'] ?: 'PT Session') ?>
                                            </p>
                                        </td>
                                        <td class="px-8 py-6 text-center">
                                            <p class="text-xs font-black italic opacity-60">
                                                <?= date('M d, Y', strtotime($hb['booking_date'])) ?>
                                            </p>
                                        </td>
                                        <td class="px-8 py-6 text-right">
                                            <?php 
                                            $st = $hb['booking_status'];
                                            $color = ($st == 'Completed') ? 'text-emerald-500' : 'text-[--text-main]/40';
                                            ?>
                                            <span class="text-[10px] font-black uppercase italic tracking-widest <?= $color ?>"><?= $st ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Today's Schedule -->
                <div class="glass-card flex flex-col overflow-hidden shadow-2xl">
                    <div class="px-8 py-6 border-b border-white/5 flex justify-between items-center bg-white/[0.01]">
                        <h4 class="text-sm font-black italic uppercase tracking-tighter flex items-center gap-2" style="color:var(--text-main)">
                            <span class="material-symbols-rounded text-primary text-xl">history_toggle_off</span> Today's Schedule
                        </h4>
                        <div class="flex items-center gap-2">
                            <span class="size-2 rounded-full bg-primary animate-pulse"></span>
                            <p class="text-[10px] font-black uppercase tracking-widest text-primary">Live</p>
                        </div>
                    </div>
                    <div class="overflow-x-auto no-scrollbar">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="text-[10px] font-black uppercase tracking-widest opacity-40 border-b border-white/5">
                                    <th class="px-8 py-5">Member</th>
                                    <th class="px-8 py-5 text-center">Type / Status</th>
                                    <th class="px-8 py-5 text-right">Time</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/5">
                                <?php if (empty($schedule_result)): ?>
                                    <tr>
                                        <td colspan="3" class="px-8 py-20 text-center text-xs font-bold uppercase tracking-widest opacity-20 italic">No sessions today</td>
                                    </tr>
                                <?php else: foreach ($schedule_result as $row): ?>
                                    <tr class="hover:bg-white/[0.01] group transition-colors cursor-pointer" onclick="location.href='coach_members.php?search=<?= urlencode($row['first_name'] . ' ' . $row['last_name']) ?>'">
                                        <td class="px-8 py-6">
                                            <p class="text-sm font-bold uppercase group-hover:text-primary transition-colors italic text-white leading-none">
                                                <?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?>
                                            </p>
                                        </td>
                                        <td class="px-8 py-6 text-center">
                                            <p class="text-[11px] font-black italic text-white uppercase tracking-wide">
                                                <?= htmlspecialchars($row['service_name'] ?: 'PT Session') ?>
                                            </p>
                                            <p class="text-[9px] font-black uppercase tracking-[0.15em] mt-1 <?= $row['booking_status'] == 'Pending' ? 'text-amber-500' : 'text-emerald-500' ?>">
                                                <?= $row['booking_status'] ?>
                                            </p>
                                        </td>
                                        <td class="px-8 py-6 text-right">
                                            <span class="text-xs font-black group-hover:text-primary transition-colors italic text-white"><?= date('h:i A', strtotime($row['start_time'])) ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>
</body>
</html>