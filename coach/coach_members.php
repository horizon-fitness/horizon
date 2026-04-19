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
$active_page = "members";

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

$page_branding = [
    'logo_path'   => $configs['system_logo'] ?? '',
    'theme_color' => $theme_color,
    'bg_color'    => $bg_color,
    'system_name' => $configs['system_name'] ?? $gym_name,
];
// ─────────────────────────────────────────────────────────────────────────────

// Fetch Coach ID (from coaches table)
$stmtCoach = $pdo->prepare("SELECT coach_id FROM coaches WHERE user_id = ? AND gym_id = ? LIMIT 1");
$stmtCoach->execute([$user_id, $gym_id]);
$coach_info = $stmtCoach->fetch();
$coach_id = $coach_info ? $coach_info['coach_id'] : 0;

// --- DASHBOARD DATA (MEMBERSHIP INSIGHTS) ---
$total_clients = 0;
$active_clients_count = 0;
$pending_sessions_count = 0;
$lifetime_sessions = 0;

if ($coach_id > 0) {
    // Total Assigned Members (Approved or Confirmed bookings only)
    $stmtTotal = $pdo->prepare("SELECT COUNT(DISTINCT member_id) FROM bookings WHERE coach_id = ? AND booking_status IN ('Approved', 'Confirmed')");
    $stmtTotal->execute([$coach_id]);
    $total_clients = $stmtTotal->fetchColumn();

    // Active Assigned Members (with Active member_status)
    $stmtActive = $pdo->prepare("
        SELECT COUNT(DISTINCT m.member_id) 
        FROM members m
        JOIN bookings b ON m.member_id = b.member_id
        WHERE b.coach_id = ? AND m.member_status = 'Active' AND b.booking_status IN ('Approved', 'Confirmed')
    ");
    $stmtActive->execute([$coach_id]);
    $active_clients_count = $stmtActive->fetchColumn();

    // Pending Sessions (awaiting approval)
    $stmtPend = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE coach_id = ? AND booking_status = 'Pending'");
    $stmtPend->execute([$coach_id]);
    $pending_sessions_count = $stmtPend->fetchColumn();

    // Lifetime Sessions (all-time completed/approved/confirmed)
    $stmtLife = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE coach_id = ? AND booking_status IN ('Approved', 'Confirmed', 'Completed')");
    $stmtLife->execute([$coach_id]);
    $lifetime_sessions = $stmtLife->fetchColumn();
}

// --- AJAX PROFILE FETCH ---
if (isset($_GET['ajax_user_id'])) {
    $target_uid = (int)$_GET['ajax_user_id'];
    $stmt = $pdo->prepare("
        SELECT u.*, m.*, a.address_line, a.barangay, a.city, a.province, a.region
        FROM users u 
        JOIN members m ON u.user_id = m.user_id 
        LEFT JOIN addresses a ON m.address_id = a.address_id
        JOIN bookings b ON m.member_id = b.member_id
        WHERE u.user_id = ? AND m.gym_id = ? AND b.coach_id = ? AND b.booking_status IN ('Approved', 'Pending', 'Confirmed', 'Completed')
        LIMIT 1
    ");
    $stmt->execute([$target_uid, $gym_id, $coach_id]);
    $u = $stmt->fetch();

    if ($u): ?>
        <div class="space-y-8 animate-in fade-in slide-in-from-bottom-6 duration-500 pb-2">
            <header class="flex justify-between items-center border-b border-white/5 pb-6">
                <div class="flex items-center gap-6">
                    <div class="size-16 rounded-2xl flex items-center justify-center font-black italic text-2xl uppercase border" 
                        style="background:rgba(var(--primary-rgb), 0.1); border-color:rgba(var(--primary-rgb), 0.2); color:var(--primary)">
                        <?= substr($u['first_name'] ?? 'M', 0, 1) ?>
                    </div>
                    <div>
                        <h2 class="text-2xl font-black italic uppercase tracking-tighter text-white leading-none mb-1"><?= htmlspecialchars(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? 'Member')) ?></h2>
                        <span class="px-3 py-1 rounded-lg bg-emerald-500/10 text-emerald-500 text-[9px] font-black uppercase italic tracking-widest border border-emerald-500/10"><?= htmlspecialchars($u['member_status'] ?? 'Active') ?></span>
                    </div>
                </div>
                <button onclick="closeUserModal()" class="size-10 rounded-xl bg-white/5 hover:bg-white/10 flex items-center justify-center text-gray-400 transition-all">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </header>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <div class="space-y-8">
                    <section class="space-y-4">
                        <h4 class="text-[10px] font-black uppercase tracking-[0.2em] border-l-2 pl-3" style="color:var(--primary); border-color:var(--primary)">Member Info</h4>
                        <div class="space-y-3">
                            <div class="bg-white/[0.02] p-4 rounded-2xl border border-white/5">
                                <p class="text-[8px] font-black uppercase tracking-widest mb-1" style="color:var(--text-main); opacity: 0.5">Full Name</p>
                                <p class="text-sm font-bold text-white italic"><?= htmlspecialchars(($u['first_name'] ?? '') . ' ' . (($u['middle_name'] ?? '') ? $u['middle_name'] . ' ' : '') . ($u['last_name'] ?? '')) ?></p>
                            </div>
                            <div class="bg-white/[0.02] p-4 rounded-2xl border border-white/5">
                                <p class="text-[8px] font-black uppercase tracking-widest mb-1" style="color:var(--text-main); opacity: 0.5">Home Address</p>
                                <p class="text-xs font-medium text-gray-300 leading-relaxed"><?= htmlspecialchars(($u['address_line'] ?? '') ?: 'No address listed') ?></p>
                            </div>
                        </div>
                    </section>
                </div>
                <div class="space-y-8">
                    <section class="space-y-4">
                        <h4 class="text-[10px] font-black uppercase tracking-[0.2em] border-l-2 pl-3" style="color:var(--primary); border-color:var(--primary)">Contact Details</h4>
                        <div class="space-y-3">
                            <div class="bg-white/[0.02] p-4 rounded-2xl border border-white/5 flex items-center justify-between">
                                <div>
                                    <p class="text-[8px] font-black uppercase tracking-widest mb-1" style="color:var(--text-main); opacity: 0.5">Mobile</p>
                                    <p class="text-sm font-bold text-white tracking-widest"><?= htmlspecialchars(($u['contact_number'] ?? '') ?: 'N/A') ?></p>
                                </div>
                                <span class="material-symbols-outlined op-40" style="color:var(--highlight)">call</span>
                            </div>
                            <div class="bg-white/[0.02] p-4 rounded-2xl border border-white/5 flex items-center justify-between">
                                <div>
                                    <p class="text-[8px] font-black uppercase tracking-widest mb-1" style="color:var(--text-main); opacity: 0.5">Email</p>
                                    <p class="text-sm font-bold text-white truncate max-w-[180px]"><?= htmlspecialchars($u['email'] ?? 'N/A') ?></p>
                                </div>
                                <span class="material-symbols-outlined op-40" style="color:var(--highlight)">mail</span>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    <?php endif;
    exit;
}

// --- FILTERING & SORTING LOGIC ---
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$sort_by = $_GET['sort'] ?? 'recent';

$where_clauses = ["b.coach_id = ?", "m.gym_id = ?"];
$filter_params = [$coach_id, $gym_id];

if (!empty($search)) {
    $where_clauses[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR m.member_code LIKE ?)";
    $filter_params[] = "%$search%";
    $filter_params[] = "%$search%";
    $filter_params[] = "%$search%";
}

if (!empty($status_filter)) {
    $where_clauses[] = "m.member_status = ?";
    $filter_params[] = $status_filter;
}

$order_sql = "ORDER BY last_visit DESC";
if ($sort_by === 'name_asc') $order_sql = "ORDER BY u.first_name ASC";
if ($sort_by === 'name_desc') $order_sql = "ORDER BY u.first_name DESC";
if ($sort_by === 'oldest') $order_sql = "ORDER BY last_visit ASC";

$sql = "
    SELECT DISTINCT m.member_id, u.user_id, u.first_name, u.last_name, u.email, u.contact_number, m.member_code, m.member_status,
    (SELECT COUNT(*) FROM bookings WHERE member_id = m.member_id AND coach_id = ? AND booking_status IN ('Approved', 'Confirmed')) as session_count,
    (SELECT MAX(attendance_date) FROM attendance WHERE member_id = m.member_id) as last_visit,
    (SELECT workout_name FROM member_workouts WHERE member_id = m.member_id AND coach_id = ? ORDER BY created_at DESC LIMIT 1) as workout_plan,
    (SELECT workout_status FROM member_workouts WHERE member_id = m.member_id AND coach_id = ? ORDER BY created_at DESC LIMIT 1) as workout_status
    FROM members m
    JOIN users u ON m.user_id = u.user_id
    JOIN bookings b ON m.member_id = b.member_id
    WHERE b.booking_status IN ('Approved', 'Confirmed') AND " . implode(" AND ", $where_clauses) . "
    $order_sql
";

// coach_id appears 3 times in subqueries + once per $filter_params
$final_params = array_merge([$coach_id, $coach_id, $coach_id], $filter_params);

$members = [];
if ($coach_id > 0) {
    $stmtMembers = $pdo->prepare($sql);
    $stmtMembers->execute($final_params);
    $members = $stmtMembers->fetchAll();
}
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>Coach Portfolio | Horizon Systems</title>
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

        .nav-label { opacity: 0; transform: translateX(-15px); transition: all 0.3s ease-in-out; white-space: nowrap; pointer-events: none; color: var(--text-main); }
        .side-nav:hover .nav-label { opacity: 1; transform: translateX(0); pointer-events: auto; }

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

        .status-card-primary {
            border: 1px solid var(--primary);
            background: linear-gradient(135deg, rgba(var(--primary-rgb), 0.1) 0%, rgba(20, 18, 26, 1) 100%);
        }

        .status-card-green {
            border: 1px solid #10b981;
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.05) 0%, rgba(20, 18, 26, 1) 100%);
        }

        .status-card-yellow {
            border: 1px solid #f59e0b;
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.05) 0%, rgba(20, 18, 26, 1) 100%);
        }

        .status-card-red {
            border: 1px solid #ef4444;
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.05) 0%, rgba(20, 18, 26, 1) 100%);
        }

        .nav-section-label {
            max-height: 0; opacity: 0; overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            margin: 0 !important; pointer-events: none;
        }
        .side-nav:hover .nav-section-label {
            max-height: 20px; opacity: 1;
            margin-bottom: 8px !important; pointer-events: auto;
        }

        .no-scrollbar::-webkit-scrollbar { display: none !important; }
        * { -ms-overflow-style: none !important; scrollbar-width: none !important; }

        .label-muted { color: var(--text-main); opacity: 0.5; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.15em; }

        @keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .animate-slide-up { animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards; opacity: 0; }

        .glass-input {
            background: rgba(255,255,255,0.02);
            border: 1px solid rgba(255,255,255,0.05);
            border-radius: 12px;
            color: white;
            padding: 12px 16px;
            font-size: 11px;
            font-weight: 600;
            outline: none;
            transition: all 0.3s ease;
        }
        .glass-input:focus { border-color: var(--primary); background: rgba(var(--primary-rgb), 0.05); }
        .glass-input option { background: #1a1220; color: white; }

        input[type="date"]::-webkit-calendar-picker-indicator {
            filter: invert(1) hue-rotate(180deg);
            cursor: pointer;
            opacity: 0.5;
            transition: all 0.3s ease;
        }

        .glass-table { width: 100%; border-collapse: separate; border-spacing: 0; }
        .glass-table tr { transition: all 0.3s; }
        .glass-table tr:hover { background: rgba(255, 255, 255, 0.02); }
        .glass-table th { padding: 20px 32px; font-size: 10px; font-weight: 900; text-transform: uppercase; letter-spacing: 0.15em; color: var(--text-main); opacity: 0.5; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.05); background: rgba(0,0,0,0.2); }
        .glass-table td { padding: 24px 32px; font-size: 11px; color: var(--text-main); border-bottom: 1px solid rgba(255,255,255,0.03); }
        .glass-table tr:last-child td { border-bottom: none; }

        .view-btn { 
            padding: 10px; border-radius: 12px; 
            background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05); 
            color: var(--highlight); transition: all 0.3s; cursor: pointer;
        }
        .view-btn.active { background: var(--primary); color: white; box-shadow: 0 4px 20px rgba(var(--primary-rgb), 0.3); border-color: transparent; }

        #userModal { 
            position: fixed; inset: 0; z-index: 100; display: none; 
            align-items: center; justify-content: center; padding: 24px;
        }
        .modal-backdrop {
            position: absolute; inset: 0; background: rgba(0,0,0,0.8);
            backdrop-filter: blur(12px);
        }
        
        .stat-card { background: var(--card-bg); border: 1px solid rgba(255,255,255,0.05); border-radius: 20px; transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
        .stat-card:hover { transform: translateY(-4px); border-color: rgba(var(--primary-rgb), 0.2); }
    </style>
    <script>
        function updateHeaderClock() {
            const now = new Date();
            const clockEl = document.getElementById('headerClock');
            if (clockEl) clockEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }
        setInterval(updateHeaderClock, 1000);
        window.addEventListener('DOMContentLoaded', updateHeaderClock);

        function triggerFilter() { document.getElementById('filterForm').submit(); }

        async function viewUserProfile(userId) {
            const modal = document.getElementById('userModal');
            const content = document.getElementById('modalContent');
            modal.style.display = 'flex';
            content.innerHTML = '<div class="flex items-center justify-center p-20"><div class="size-10 border-4 border-primary/20 border-t-primary rounded-full animate-spin"></div></div>';
            try {
                const response = await fetch(`?ajax_user_id=${userId}`);
                const html = await response.text();
                content.innerHTML = html;
            } catch (error) { content.innerHTML = '<p class="text-rose-500 font-bold text-center">FAILED TO FETCH PROFILE</p>'; }
        }

        function closeUserModal() { document.getElementById('userModal').style.display = 'none'; }

        function toggleView(view) {
            const grid = document.getElementById('memberGridContainer');
            const table = document.getElementById('memberTableContainer');
            const gridBtn = document.getElementById('gridViewBtn');
            const tableBtn = document.getElementById('tableViewBtn');

            if (view === 'grid') {
                grid.classList.remove('hidden'); table.classList.add('hidden');
                gridBtn.classList.add('active'); tableBtn.classList.remove('active');
            } else {
                grid.classList.add('hidden'); table.classList.remove('hidden');
                gridBtn.classList.remove('active'); tableBtn.classList.add('active');
            }
        }
    </script>
</head>
<body class="antialiased flex h-screen overflow-hidden">

    <?php include '../includes/coach_sidebar.php'; ?>

    <div class="main-content flex-1 overflow-y-auto no-scrollbar">
        <div class="p-10">
            <header class="mb-10 flex flex-col md:flex-row justify-between items-start md:items-end gap-6">
                <div>
                    <h2 class="text-3xl font-black italic uppercase tracking-tighter" style="color:var(--text-main)">
                        Port<span style="color:var(--primary)" class="italic">folio</span> Dashboard
                    </h2>
                    <p class="label-muted mt-1 italic">Member Intelligence • Membership Dashboard</p>
                </div>
                <div class="flex flex-col items-end">
                    <p id="headerClock" class="font-black italic text-2xl leading-none tracking-tighter" style="color:var(--text-main)">00:00:00 AM</p>
                    <p class="text-[10px] font-black uppercase tracking-[0.2em] leading-none mt-2" style="color:var(--primary)">
                        <?= date('l, M d, Y') ?>
                    </p>
                </div>
            </header>

            <!-- Membership Stat Dashboard -->
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6 mb-10 animate-slide-up" style="animation-delay: 0s;">
                <div class="glass-card p-8 status-card-primary relative overflow-hidden group hover:scale-[1.02] transition-all">
                    <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform" style="color:var(--primary)">groups</span>
                    <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">Total Clients</p>
                    <h3 class="text-2xl font-black italic uppercase text-white"><?= $total_clients ?></h3>
                    <p class="text-primary text-[10px] font-black uppercase mt-2">Active Assigned</p>
                </div>

                <div class="glass-card p-8 status-card-green relative overflow-hidden group hover:scale-[1.02] transition-all">
                    <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-emerald-500">verified</span>
                    <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">Currently Training</p>
                    <h3 class="text-2xl font-black italic uppercase text-white"><?= $active_clients_count ?></h3>
                    <p class="text-emerald-500 text-[10px] font-black uppercase mt-2">Member Ready</p>
                </div>

                <div class="glass-card p-8 status-card-yellow relative overflow-hidden group hover:scale-[1.02] transition-all">
                    <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-amber-500">timer</span>
                    <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">Pending Sessions</p>
                    <h3 class="text-2xl font-black italic uppercase text-white"><?= $pending_sessions_count ?></h3>
                    <p class="text-amber-500 text-[10px] font-black uppercase mt-2">Awaiting Action</p>
                </div>

                <div class="glass-card p-8 status-card-red relative overflow-hidden group hover:scale-[1.02] transition-all">
                    <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-rose-500">history</span>
                    <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">Lifetime Total</p>
                    <h3 class="text-2xl font-black italic uppercase text-white"><?= $lifetime_sessions ?></h3>
                    <p class="text-rose-500 text-[10px] font-black uppercase mt-2">Success Metrics</p>
                </div>
            </div>

            <section class="glass-card mb-10 overflow-hidden animate-slide-up" style="animation-delay: 0.1s;">
                <div class="px-8 py-4 bg-white/[0.02] border-b border-white/5">
                    <form id="filterForm" method="GET" class="flex flex-wrap items-center gap-4">
                        <div class="flex-1 min-w-[300px] relative group">
                            <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-sm text-primary/50 transition-transform group-hover:scale-110">search</span>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search member name or code..." class="w-full glass-input pl-12">
                        </div>
                        
                        <div class="w-[180px] relative group">
                            <select name="status" class="w-full glass-input pr-10 appearance-none cursor-pointer" onchange="triggerFilter()">
                                <option value="">All Membership Status</option>
                                <option value="Active" <?= $status_filter === 'Active' ? 'selected' : '' ?>>Active</option>
                                <option value="Pending" <?= $status_filter === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                <option value="Expired" <?= $status_filter === 'Expired' ? 'selected' : '' ?>>Expired</option>
                            </select>
                            <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-sm text-[--text-main] opacity-40 pointer-events-none transition-transform group-hover:translate-y-[-40%]">expand_more</span>
                        </div>

                        <div class="w-[180px] relative group">
                            <select name="sort" class="w-full glass-input pr-10 appearance-none cursor-pointer" onchange="triggerFilter()">
                                <option value="recent" <?= $sort_by === 'recent' ? 'selected' : '' ?>>Recently Visited</option>
                                <option value="oldest" <?= $sort_by === 'oldest' ? 'selected' : '' ?>>Oldest Record</option>
                                <option value="name_asc" <?= $sort_by === 'name_asc' ? 'selected' : '' ?>>Name (A-Z)</option>
                                <option value="name_desc" <?= $sort_by === 'name_desc' ? 'selected' : '' ?>>Name (Z-A)</option>
                            </select>
                            <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-sm text-[--text-main] opacity-40 pointer-events-none transition-transform group-hover:translate-y-[-40%]">expand_more</span>
                        </div>

                        <div class="flex gap-2">
                            <button type="button" id="gridViewBtn" onclick="toggleView('grid')" class="view-btn size-[45px] active flex items-center justify-center p-0">
                                <span class="material-symbols-outlined text-lg">grid_view</span>
                            </button>
                            <button type="button" id="tableViewBtn" onclick="toggleView('list')" class="view-btn size-[45px] flex items-center justify-center p-0">
                                <span class="material-symbols-outlined text-lg">list</span>
                            </button>
                        </div>

                        <a href="coach_members.php" class="size-[45px] rounded-xl bg-white/5 border border-white/10 flex items-center justify-center text-rose-500/50 hover:text-rose-500 hover:bg-rose-500/10 transition-all" title="Reset Filters">
                            <span class="material-symbols-outlined text-xl">restart_alt</span>
                        </a>
                    </form>
                </div>
            </section>

            <!-- Member Content -->
            <div id="memberGridContainer" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-8">
                <?php if(count($members) > 0): foreach($members as $index => $m): ?>
                <div class="glass-card p-8 flex flex-col gap-6 animate-slide-up" style="animation-delay: <?= (0.2 + $index * 0.05) ?>s;">
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-5">
                            <div class="size-16 rounded-2xl bg-white/5 border border-white/5 flex items-center justify-center font-black italic text-xl shadow-inner" style="color:var(--primary)">
                                <?= strtoupper(substr($m['first_name'] ?? 'M',0,1)) ?>
                            </div>
                            <div>
                                <h3 class="text-white font-black uppercase italic tracking-tight text-lg leading-tight"><?= htmlspecialchars(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')) ?></h3>
                                <div class="flex items-center gap-2 mt-1">
                                    <p class="text-[9px] text-gray-600 font-black uppercase tracking-[0.2em]"><?= htmlspecialchars($m['member_code'] ?? 'N/A') ?></p>
                                    <span class="size-1 bg-white/10 rounded-full"></span>
                                    <p class="text-[9px] font-black uppercase tracking-[0.2em]" style="color:var(--primary)"><?= $m['session_count'] ?> Sessions</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-4 py-6 border-y border-white/5">
                        <div class="flex justify-between items-center">
                            <p class="label-muted" style="font-size: 8px;">Active Plan</p>
                            <p class="text-xs font-bold italic" style="color:var(--primary)"><?= htmlspecialchars($m['workout_plan'] ?: 'Maintenance') ?></p>
                        </div>
                        <div class="flex justify-between items-center">
                            <p class="label-muted" style="font-size: 8px;">Last Activity</p>
                            <p class="text-xs font-bold text-gray-300"><?= $m['last_visit'] ? date('M d, Y', strtotime($m['last_visit'])) : 'Inaugural' ?></p>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <a href="coach_workouts.php?member_id=<?= $m['member_id'] ?>" class="flex-1 py-4 rounded-2xl bg-white/5 border border-white/5 hover:bg-primary hover:text-white transition-all text-center text-[10px] font-black uppercase tracking-widest">Manage Plans</a>
                        <button onclick="viewUserProfile(<?= $m['user_id'] ?>)" class="size-14 rounded-2xl bg-white/5 border border-white/5 flex items-center justify-center hover:bg-primary/20 hover:border-primary/40 transition-all group">
                            <span class="material-symbols-outlined text-gray-500 group-hover:text-primary transition-colors">visibility</span>
                        </button>
                    </div>
                </div>
                <?php endforeach; else: ?>
                <div class="col-span-full py-20 text-center animate-slide-up">
                    <div class="size-20 rounded-full bg-white/5 flex items-center justify-center mx-auto mb-6 text-gray-700 border border-white/5">
                        <span class="material-symbols-outlined text-4xl">person_off</span>
                    </div>
                    <h4 class="text-white font-black italic uppercase tracking-tighter text-xl mb-4">No Members Identified</h4>
                    <p class="label-muted max-w-md mx-auto italic text-sm">Members assigned to you or booked sessions will manifest here. Ensure your profile is correctly linked and bookings are approved.</p>
                </div>
                <?php endif; ?>
            </div>

            <div id="memberTableContainer" class="hidden glass-card overflow-hidden animate-slide-up" style="animation-delay: 0.2s;">
                <div class="overflow-x-auto no-scrollbar">
                    <table class="glass-table">
                        <thead>
                            <tr>
                                <th class="px-8 py-4">Participant Identity</th>
                                <th class="px-8 py-4">Active Plan</th>
                                <th class="px-8 py-4">Deployment</th>
                                <th class="px-8 py-4">Last Activity</th>
                                <th class="px-8 py-4 text-right">Operation</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($members as $m): ?>
                            <tr class="hover:bg-white/[0.02] transition-colors">
                                <td class="px-8 py-6">
                                    <div class="flex items-center gap-5">
                                        <div class="size-12 rounded-2xl flex items-center justify-center font-bold text-sm shadow-inner border transition-transform hover:scale-105" 
                                            style="background:rgba(var(--primary-rgb), 0.1); border-color:rgba(var(--primary-rgb), 0.2); color:var(--primary)">
                                            <?= substr($m['first_name'] ?? 'M',0,1) ?>
                                        </div>
                                        <div class="flex flex-col">
                                            <span class="font-black italic uppercase tracking-tight text-white"><?= htmlspecialchars(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')) ?></span>
                                            <span class="text-[9px] font-black uppercase tracking-widest mt-1 opacity-40"><?= $m['session_count'] ?> Total Conducted Sessions</span>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-8 py-6 text-gray-300 font-bold italic"><?= htmlspecialchars($m['workout_plan'] ?: 'General Alignment') ?></td>
                                <td class="px-8 py-6">
                                    <?php 
                                        $ws = $m['workout_status'] ?: 'Inactive';
                                        $w_class = ($ws === 'In Progress') ? 'text-primary bg-primary/10 border-primary/20' : (($ws === 'Completed') ? 'text-emerald-500 bg-emerald-500/10 border-emerald-500/20' : 'text-gray-500 bg-white/5 border-white/5');
                                    ?>
                                    <span class="px-3 py-1.5 rounded-xl text-[8px] font-black uppercase tracking-widest border <?= $w_class ?>"><?= $ws ?></span>
                                </td>
                                <td class="px-8 py-6 text-gray-400 font-medium"><?= $m['last_visit'] ? date('M d, Y', strtotime($m['last_visit'])) : 'Initial Contact' ?></td>
                                <td class="px-8 py-6">
                                    <div class="flex justify-end gap-3">
                                        <button onclick="viewUserProfile(<?= $m['user_id'] ?>)" class="size-12 rounded-xl bg-white/5 border border-white/10 flex items-center justify-center text-gray-500 hover:text-primary transition-all active:scale-95 group" title="Intelligence View">
                                            <span class="material-symbols-outlined text-lg group-hover:scale-110 transition-transform">visibility</span>
                                        </button>
                                        <a href="coach_workouts.php?member_id=<?= $m['member_id'] ?>" class="h-12 px-6 rounded-xl bg-primary text-white text-[9px] font-black uppercase italic tracking-widest flex items-center transition-all hover:opacity-90 shadow-lg shadow-primary/20 active:scale-95">Analyze Plan</a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Member Profile Modal -->
    <div id="userModal">
        <div class="modal-backdrop" onclick="closeUserModal()"></div>
        <div class="relative w-full max-w-2xl glass-card border-white/10 shadow-[0_0_80px_rgba(0,0,0,0.4)] p-10 animate-slide-up">
            <div id="modalContent"></div>
        </div>
    </div>

</body>
</html>