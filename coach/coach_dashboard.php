<?php
session_start();
require_once '../db.php';

// Security Check
$role = strtolower($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || $role !== 'coach') {
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$gym_id = $_SESSION['gym_id'];
$username = $_SESSION['username'];
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
$stmtGym = $pdo->prepare("SELECT owner_user_id, gym_name FROM gyms WHERE gym_id = ?");
$stmtGym->execute([$gym_id]);
$gym_data = $stmtGym->fetch();
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
    'page_slug'       => '',
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
    'page_slug'   => $configs['page_slug']   ?? '',
    'system_name' => $configs['system_name'] ?? $gym_name,
];
// ─────────────────────────────────────────────────────────────────────────────

// Fetch Coach ID (from coaches table)
$stmtCoach = $pdo->prepare("SELECT coach_id FROM coaches WHERE user_id = ? AND gym_id = ? LIMIT 1");
$stmtCoach->execute([$user_id, $gym_id]);
$coach_info = $stmtCoach->fetch();
$coach_id = $coach_info ? $coach_info['coach_id'] : 0;

// Stats
$today = date('Y-m-d');
$today_count = 0;
$pending_count = 0;
$total_members_coached = 0;
$upcoming_sessions = 0;

if ($coach_id > 0) {
    // Handle Booking Actions
    if (isset($_GET['action']) && isset($_GET['booking_id'])) {
        $target_id = (int) $_GET['booking_id'];
        $status_map = ['approve' => 'Approved', 'reject' => 'Rejected'];
        if (isset($status_map[$_GET['action']])) {
            $updateStmt = $pdo->prepare("UPDATE bookings SET booking_status = ? WHERE booking_id = ? AND coach_id = ?");
            $updateStmt->execute([$status_map[$_GET['action']], $target_id, $coach_id]);
            header("Location: coach_dashboard.php?status=success");
            exit;
        }
    }

    // 1. Approved bookings for today
    $stmtToday = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE coach_id = ? AND booking_date = ? AND booking_status IN ('Approved', 'Confirmed', 'Completed')");
    $stmtToday->execute([$coach_id, $today]);
    $today_count = $stmtToday->fetchColumn();

    // 2. Total pending bookings
    $stmtPending = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE coach_id = ? AND booking_status = 'Pending'");
    $stmtPending->execute([$coach_id]);
    $pending_count = $stmtPending->fetchColumn();

    // 3. Fetch Pending Booking List
    $stmtPendingList = $pdo->prepare("
        SELECT b.*, u.first_name, u.last_name, sc.service_name as service_name 
        FROM bookings b 
        JOIN members m ON b.member_id = m.member_id 
        JOIN users u ON m.user_id = u.user_id 
        LEFT JOIN service_catalog sc ON b.catalog_service_id = sc.catalog_service_id 
        WHERE b.coach_id = ? AND b.booking_status = 'Pending' 
        ORDER BY b.booking_date ASC, b.start_time ASC
        LIMIT 5
    ");
    $stmtPendingList->execute([$coach_id]);
    $pending_bookings = $stmtPendingList->fetchAll();

    // 4. Total distinct members coached
    $stmtMembers = $pdo->prepare("SELECT COUNT(DISTINCT member_id) FROM bookings WHERE coach_id = ?");
    $stmtMembers->execute([$coach_id]);
    $total_members_coached = $stmtMembers->fetchColumn();

    // 5. Upcoming sessions
    $stmtUpcoming = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE coach_id = ? AND booking_date > ? AND booking_status IN ('Approved', 'Confirmed')");
    $stmtUpcoming->execute([$coach_id, $today]);
    $upcoming_sessions = $stmtUpcoming->fetchColumn();
}
$pending_bookings = $pending_bookings ?? [];

// Pagination / Today's Schedule
$limit = 10;
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int) $_GET['page'] : 1;
if ($current_page < 1) $current_page = 1;
$offset = ($current_page - 1) * $limit;

$total_approved = 0;
if ($coach_id > 0) {
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE coach_id = ? AND booking_date = ? AND booking_status IN ('Approved', 'Confirmed', 'Completed')");
    $stmtCount->execute([$coach_id, $today]);
    $total_approved = $stmtCount->fetchColumn();
}
$total_pages = ceil($total_approved / $limit);

$schedule_result = [];
if ($coach_id > 0) {
    $stmtSched = $pdo->prepare("
        SELECT b.*, u.first_name, u.last_name, u.username, sc.service_name as service_name 
        FROM bookings b
        JOIN members m ON b.member_id = m.member_id
        JOIN users u ON m.user_id = u.user_id
        LEFT JOIN service_catalog sc ON b.catalog_service_id = sc.catalog_service_id
        WHERE b.coach_id = ? AND b.booking_date = ? AND b.booking_status IN ('Approved', 'Confirmed', 'Completed')
        ORDER BY b.start_time ASC
        LIMIT $limit OFFSET $offset
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
    <title>Coach Portal | Horizon Systems</title>
    <link href="https://fonts.googleapis.com/css2?family=<?= urlencode($font_family) ?>:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet" />
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { 
                "primary": "var(--primary)", 
                "background-dark": "var(--background)", 
                "surface-dark": "var(--card-bg)", 
                "border-subtle": "rgba(255,255,255,0.05)" 
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
        }
        .side-nav:hover~.main-content { margin-left: 300px; }

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
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
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
        .nav-item:hover .material-symbols-outlined { transform: scale(1.1); }
        .nav-item.active { color: var(--primary) !important; position: relative; }
        .nav-item.active .material-symbols-outlined { color: var(--primary); }
        .nav-item.active::after {
            content: ''; position: absolute;
            right: 0px; top: 50%; transform: translateY(-50%);
            width: 4px; height: 24px;
            background: var(--primary); border-radius: 4px 0 0 4px;
        }

        .no-scrollbar::-webkit-scrollbar { display: none !important; }
        * { -ms-overflow-style: none !important; scrollbar-width: none !important; }

        .label-muted {
            color: var(--text-main); opacity: 0.5;
            font-size: 10px; font-weight: 800;
            text-transform: uppercase; letter-spacing: 0.15em;
        }

        @keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .animate-slide-up { animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards; opacity: 0; }

        .alert-dot { animation: pulse 2s infinite; }
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.4; } 100% { opacity: 1; } }
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
        <div class="p-10">
            <header class="mb-10 flex flex-col md:flex-row justify-between items-start md:items-end gap-6">
                <div>
                    <h2 class="text-3xl font-black italic uppercase tracking-tighter" style="color:var(--text-main)">
                        Welcome Back, <span style="color:var(--primary)" class="italic"><?= htmlspecialchars($coach_name ?: 'Coach') ?></span>
                    </h2>
                    <p class="label-muted mt-1 italic">Operational Overview • Performance Intelligence</p>
                </div>
                <div class="flex flex-col items-end">
                    <p id="headerClock" class="font-black italic text-2xl leading-none tracking-tighter" style="color:var(--text-main)">00:00:00 AM</p>
                    <p class="text-[10px] font-black uppercase tracking-[0.2em] leading-none mt-2" style="color:var(--primary)">
                        <?= date('l, M d, Y') ?>
                    </p>
                </div>
            </header>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-10">
                <div class="glass-card p-6 flex items-center gap-4 animate-slide-up" style="animation-delay: 0s;">
                    <div class="size-12 rounded-2xl bg-emerald-500/10 flex items-center justify-center text-emerald-500">
                        <span class="material-symbols-outlined text-2xl">event_available</span>
                    </div>
                    <div>
                        <p class="label-muted">Confirmed Today</p>
                        <h3 class="text-2xl font-black italic uppercase" style="color:var(--text-main)"><?= $today_count ?></h3>
                    </div>
                </div>

                <div class="glass-card p-6 flex items-center gap-4 animate-slide-up" style="animation-delay: 0.1s;">
                    <div class="size-12 rounded-2xl bg-primary/10 flex items-center justify-center text-primary">
                        <span class="material-symbols-outlined text-2xl">pending_actions</span>
                    </div>
                    <div>
                        <p class="label-muted">Pending Sessions</p>
                        <div class="flex items-center gap-2">
                            <h3 class="text-2xl font-black italic uppercase" style="color:var(--text-main)"><?= $pending_count ?></h3>
                            <?php if ($pending_count > 0): ?>
                                <span class="px-1.5 py-0.5 rounded-md bg-primary/20 text-primary text-[7px] font-black uppercase tracking-widest alert-dot">Action</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="glass-card p-6 flex items-center gap-4 animate-slide-up" style="animation-delay: 0.2s;">
                    <div class="size-12 rounded-2xl bg-amber-500/10 flex items-center justify-center text-amber-500">
                        <span class="material-symbols-outlined text-2xl">groups</span>
                    </div>
                    <div>
                        <p class="label-muted">Total Members</p>
                        <h3 class="text-2xl font-black italic uppercase" style="color:var(--text-main)"><?= $total_members_coached ?></h3>
                    </div>
                </div>

                <div class="glass-card p-6 flex items-center gap-4 animate-slide-up" style="animation-delay: 0.3s;">
                    <div class="size-12 rounded-2xl bg-blue-500/10 flex items-center justify-center text-blue-500">
                        <span class="material-symbols-outlined text-2xl">schedule</span>
                    </div>
                    <div>
                        <p class="label-muted">Upcoming</p>
                        <h3 class="text-2xl font-black italic uppercase" style="color:var(--text-main)"><?= $upcoming_sessions ?></h3>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 pb-10">
                <!-- PENDING BOOKING REQUESTS -->
                <div class="glass-card flex flex-col overflow-hidden animate-slide-up shadow-2xl shadow-primary/5" style="animation-delay: 0.4s;">
                    <div class="px-6 py-5 border-b border-white/5 flex justify-between items-center bg-white/[0.01]">
                        <h4 class="text-xs font-black italic uppercase tracking-tighter flex items-center gap-2" style="color:var(--text-main)">
                            <span class="material-symbols-outlined text-primary text-lg">pending_actions</span> Booking Requests
                        </h4>
                        <div class="flex items-center gap-2">
                            <span class="size-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                            <p class="text-[8px] font-black uppercase tracking-widest text-amber-500">Waitlist</p>
                        </div>
                    </div>
                    <div class="p-2 flex-1 overflow-x-auto no-scrollbar">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="text-[8px] font-black uppercase tracking-widest opacity-40 border-b border-white/5">
                                    <th class="px-5 py-4">Requester</th>
                                    <th class="px-5 py-4 text-center">Schedule</th>
                                    <th class="px-5 py-4 text-right">Decision</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/5">
                                <?php if (empty($pending_bookings)): ?>
                                    <tr>
                                        <td colspan="3" class="px-6 py-12 text-center text-[10px] font-black uppercase tracking-widest opacity-20 italic">No pending requests</td>
                                    </tr>
                                <?php else:
                                    foreach ($pending_bookings as $pb): ?>
                                        <tr class="hover:bg-white/[0.01] group transition-colors">
                                            <td class="px-5 py-4">
                                                <p class="text-[11px] font-bold uppercase group-hover:text-primary transition-colors italic" style="color:var(--text-main)">
                                                    <?= htmlspecialchars($pb['first_name'] . ' ' . $pb['last_name']) ?>
                                                </p>
                                                <p class="label-muted mt-0.5" style="font-size: 8px;">
                                                    <?= strtoupper($pb['service_name']) ?>
                                                </p>
                                            </td>
                                            <td class="px-5 py-4 text-center">
                                                <p class="text-[10px] font-black italic opacity-60">
                                                    <?= date('M d', strtotime($pb['booking_date'])) ?>
                                                </p>
                                                <p class="text-[8px] font-bold uppercase tracking-widest mt-0.5" style="color:var(--primary)">
                                                    <?= date('h:i A', strtotime($pb['start_time'])) ?>
                                                </p>
                                            </td>
                                            <td class="px-5 py-4 text-right">
                                                <div class="flex justify-end gap-2">
                                                    <a href="?action=approve&booking_id=<?= $pb['booking_id'] ?>"
                                                        class="size-8 rounded-lg bg-emerald-500/10 text-emerald-500 flex items-center justify-center hover:bg-emerald-500 hover:text-white transition-all">
                                                        <span class="material-symbols-outlined text-sm">check</span>
                                                    </a>
                                                    <a href="?action=reject&booking_id=<?= $pb['booking_id'] ?>"
                                                        onclick="return confirm('Reject this request?')"
                                                        class="size-8 rounded-lg bg-rose-500/10 text-rose-500 flex items-center justify-center hover:bg-rose-500 hover:text-white transition-all">
                                                        <span class="material-symbols-outlined text-sm">close</span>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- TODAY'S TRAINING TIMELINE -->
                <div class="glass-card flex flex-col overflow-hidden animate-slide-up shadow-2xl" style="animation-delay: 0.5s;">
                    <div class="px-6 py-5 border-b border-white/5 flex justify-between items-center bg-white/[0.01]">
                        <h4 class="text-xs font-black italic uppercase tracking-tighter flex items-center gap-2" style="color:var(--text-main)">
                            <span class="material-symbols-outlined text-primary text-lg">history_toggle_off</span> Today's Schedule
                        </h4>
                        <div class="flex items-center gap-2">
                            <span class="size-1.5 rounded-full bg-primary animate-pulse"></span>
                            <p class="text-[8px] font-black uppercase tracking-widest text-primary">Live Queue</p>
                        </div>
                    </div>
                    <div class="p-2 flex-1 overflow-x-auto no-scrollbar">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="text-[8px] font-black uppercase tracking-widest opacity-40 border-b border-white/5">
                                    <th class="px-5 py-4">Account</th>
                                    <th class="px-5 py-4 text-center">Type</th>
                                    <th class="px-5 py-4 text-right">Time</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/5">
                                <?php if (empty($schedule_result)): ?>
                                    <tr>
                                        <td colspan="3" class="px-6 py-12 text-center text-[10px] font-black uppercase tracking-widest opacity-20 italic">No sessions today</td>
                                    </tr>
                                <?php else:
                                    foreach ($schedule_result as $row): ?>
                                        <tr class="hover:bg-white/[0.01] group transition-colors cursor-pointer"
                                            onclick="location.href='coach_workouts.php?member_id=<?= $row['member_id'] ?>'">
                                            <td class="px-5 py-4">
                                                <p class="text-[11px] font-bold uppercase group-hover:text-primary transition-colors italic" style="color:var(--text-main)">
                                                    <?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?>
                                                </p>
                                                <p class="label-muted mt-0.5" style="font-size: 8px;">
                                                    @<?= htmlspecialchars($row['username']) ?></p>
                                            </td>
                                            <td class="px-5 py-4 text-center">
                                                <p class="text-[10px] font-black italic opacity-60">
                                                    <?= htmlspecialchars($row['service_name'] ?: 'PT Session') ?>
                                                </p>
                                                <p class="label-muted mt-0.5" style="font-size: 8px;">MEMBER</p>
                                            </td>
                                            <td class="px-5 py-4 text-right">
                                                <span class="text-[9px] font-black group-hover:text-primary transition-colors italic" style="color:var(--text-main)"><?= date('h:i A', strtotime($row['start_time'])) ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="px-6 py-4 border-t border-white/5 flex justify-center gap-4 bg-white/[0.01]">
                            <?php if ($current_page > 1): ?>
                                <a href="?page=<?= $current_page - 1 ?>"
                                    class="size-9 rounded-xl border border-white/5 bg-white/5 flex items-center justify-center opacity-40 hover:opacity-100 hover:bg-primary/20 hover:border-primary/20 transition-all">
                                    <span class="material-symbols-outlined text-sm">chevron_left</span>
                                </a>
                            <?php endif; ?>
                            <?php if ($current_page < $total_pages): ?>
                                <a href="?page=<?= $current_page + 1 ?>"
                                    class="size-9 rounded-xl border border-white/5 bg-white/5 flex items-center justify-center opacity-40 hover:opacity-100 hover:bg-primary/20 hover:border-primary/20 transition-all">
                                    <span class="material-symbols-outlined text-sm">chevron_right</span>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</body>
</html>