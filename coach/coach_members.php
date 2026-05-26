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

// --- DASHBOARD DATA ---
$total_clients = 0;
$active_clients_count = 0;
$pending_sessions_count = 0;
$lifetime_sessions = 0;

if ($coach_id > 0) {
    // Total Assigned Members
    $stmtTotal = $pdo->prepare("SELECT COUNT(DISTINCT member_id) FROM bookings WHERE coach_id = ? AND booking_status != 'Rejected'");
    $stmtTotal->execute([$coach_id]);
    $total_clients = $stmtTotal->fetchColumn();

    // Active Assigned Members
    $stmtActive = $pdo->prepare("
        SELECT COUNT(DISTINCT m.member_id) 
        FROM members m
        JOIN bookings b ON m.member_id = b.member_id
        WHERE b.coach_id = ? AND m.member_status = 'Active' AND b.booking_status != 'Rejected'
    ");
    $stmtActive->execute([$coach_id]);
    $active_clients_count = $stmtActive->fetchColumn();

    // Pending Sessions
    $stmtPend = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE coach_id = ? AND booking_status = 'Pending'");
    $stmtPend->execute([$coach_id]);
    $pending_sessions_count = $stmtPend->fetchColumn();

    // Completed Sessions
    $stmtLife = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE coach_id = ? AND booking_status != 'Rejected'");
    $stmtLife->execute([$coach_id]);
    $lifetime_sessions = $stmtLife->fetchColumn();
}

// --- AJAX PROFILE FETCH ---
if (isset($_GET['ajax_user_id'])) {
    $target_uid = (int)$_GET['ajax_user_id'];
    $stmtAjax = $pdo->prepare("
        SELECT u.*, m.*, a.address_line, a.barangay, a.city, a.province, a.region
        FROM users u 
        JOIN members m ON u.user_id = m.user_id 
        LEFT JOIN addresses a ON m.address_id = a.address_id
        JOIN bookings b ON m.member_id = b.member_id
        WHERE u.user_id = ? AND m.gym_id = ? AND b.coach_id = ? AND b.booking_status IN ('Approved', 'Pending', 'Confirmed', 'Completed')
        LIMIT 1
    ");
    $stmtAjax->execute([$target_uid, $gym_id, $coach_id]);
    $u = $stmtAjax->fetch();

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
                        <span class="px-3 py-1 rounded-lg bg-emerald-500/10 text-emerald-500 text-[11px] font-black uppercase italic tracking-widest border border-emerald-500/10"><?= htmlspecialchars($u['member_status'] ?? 'Active') ?></span>
                    </div>
                </div>
                <button onclick="closeUserModal()" class="size-10 rounded-xl bg-white/5 hover:bg-white/10 flex items-center justify-center text-[--text-main]/40 transition-all border border-white/5 group">
                    <span class="material-symbols-rounded text-xl group-hover:rotate-90 transition-transform">close</span>
                </button>
            </header>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <div class="space-y-8">
                    <section class="space-y-4">
                        <h4 class="text-[11px] font-black uppercase tracking-[0.2em] border-l-2 pl-3" style="color:var(--primary); border-color:var(--primary)">Member Information</h4>
                        <div class="space-y-3">
                            <div class="bg-white/[0.02] p-5 rounded-2xl border border-white/5 shadow-inner">
                                <p class="text-[11px] font-black uppercase tracking-widest mb-1 opacity-40">Full Name</p>
                                <p class="text-sm font-bold text-white italic"><?= htmlspecialchars(($u['first_name'] ?? '') . ' ' . (($u['middle_name'] ?? '') ? $u['middle_name'] . ' ' : '') . ($u['last_name'] ?? '')) ?></p>
                            </div>
                            <div class="bg-white/[0.02] p-5 rounded-2xl border border-white/5 shadow-inner">
                                <p class="text-[11px] font-black uppercase tracking-widest mb-1 opacity-40">Residential Address</p>
                                <p class="text-xs font-medium text-[--text-main]/70 leading-relaxed"><?= htmlspecialchars(($u['address_line'] ?? '') ?: 'No address listed') ?></p>
                            </div>
                        </div>
                    </section>
                </div>
                <div class="space-y-8">
                    <section class="space-y-4">
                        <h4 class="text-[11px] font-black uppercase tracking-[0.2em] border-l-2 pl-3" style="color:var(--primary); border-color:var(--primary)">Contact Details</h4>
                        <div class="space-y-3">
                            <div class="bg-white/[0.02] p-5 rounded-2xl border border-white/5 flex items-center justify-between shadow-inner group">
                                <div>
                                    <p class="text-[11px] font-black uppercase tracking-widest mb-1 opacity-40">Mobile Number</p>
                                    <p class="text-sm font-bold text-white tracking-widest"><?= htmlspecialchars(($u['contact_number'] ?? '') ?: 'N/A') ?></p>
                                </div>
                                <span class="material-symbols-rounded text-2xl opacity-20 group-hover:opacity-100 group-hover:text-primary transition-all">call</span>
                            </div>
                            <div class="bg-white/[0.02] p-5 rounded-2xl border border-white/5 flex items-center justify-between shadow-inner group">
                                <div>
                                    <p class="text-[11px] font-black uppercase tracking-widest mb-1 opacity-40">Email Address</p>
                                    <p class="text-sm font-bold text-white truncate max-w-[180px]"><?= htmlspecialchars($u['email'] ?? 'N/A') ?></p>
                                </div>
                                <span class="material-symbols-rounded text-2xl opacity-20 group-hover:opacity-100 group-hover:text-primary transition-all">mail</span>
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
    $sterm = "%$search%";
    $filter_params[] = $sterm;
    $filter_params[] = $sterm;
    $filter_params[] = $sterm;
}

if (!empty($status_filter)) {
    $where_clauses[] = "m.member_status = ?";
    $filter_params[] = $status_filter;
}

$order_sql = "ORDER BY last_visit DESC";
if ($sort_by === 'name_asc') $order_sql = "ORDER BY u.first_name ASC";
if ($sort_by === 'name_desc') $order_sql = "ORDER BY u.first_name DESC";
if ($sort_by === 'oldest') $order_sql = "ORDER BY last_visit ASC";

// ── SAFE SCHEMA ADAPTIVE SEARCH ──
$hasWorkoutsTable = false;
try {
    $resTest = $pdo->query("SELECT 1 FROM member_workouts LIMIT 1");
    $hasWorkoutsTable = true;
} catch (PDOException $e) { $hasWorkoutsTable = false; }

$workout_subqueries = $hasWorkoutsTable 
    ? " (SELECT workout_name FROM member_workouts WHERE member_id = m.member_id AND coach_id = ? ORDER BY created_at DESC LIMIT 1) as workout_plan,
        (SELECT workout_status FROM member_workouts WHERE member_id = m.member_id AND coach_id = ? ORDER BY created_at DESC LIMIT 1) as workout_status "
    : " '' as workout_plan, 'Unknown' as workout_status ";

$sql = "
    SELECT DISTINCT m.member_id, u.user_id, u.first_name, u.last_name, u.email, u.contact_number, m.member_code, m.member_status,
    (SELECT COUNT(*) FROM bookings WHERE member_id = m.member_id AND coach_id = ? AND booking_status IN ('Approved', 'Confirmed')) as session_count,
    (SELECT MAX(attendance_date) FROM attendance WHERE member_id = m.member_id) as last_visit,
    $workout_subqueries
    FROM members m
    JOIN users u ON m.user_id = u.user_id
    JOIN bookings b ON m.member_id = b.member_id
    WHERE b.booking_status != 'Rejected' AND " . implode(" AND ", $where_clauses) . "
    $order_sql
";

$members = [];
if ($coach_id > 0) {
    try {
        $subquery_params = $hasWorkoutsTable ? [$coach_id, $coach_id, $coach_id] : [$coach_id];
        $final_params = array_merge($subquery_params, $filter_params);
        $stmtMembers = $pdo->prepare($sql);
        $stmtMembers->execute($final_params);
        $members = $stmtMembers->fetchAll();
    } catch (PDOException $e) {
        $members = [];
        $error_msg = "Database integrity check failed.";
    }
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
            border: 1px solid rgba(255,255,255,0.07);
            border-radius: 24px;
            transition: box-shadow 0.4s ease, border-color 0.4s ease;
            position: relative;
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

        .no-scrollbar::-webkit-scrollbar { display: none !important; }
        * { -ms-overflow-style: none !important; scrollbar-width: none !important; }

        .glass-input {
            background: rgba(255,255,255,0.02);
            border: 1px solid rgba(255,255,255,0.05);
            border-radius: 16px;
            color: var(--text-main);
            padding: 14px 20px;
            font-size: 13px;
            font-weight: 600;
            outline: none;
            transition: all 0.3s ease;
        }
        .glass-input:focus { border-color: var(--primary); background: rgba(var(--primary-rgb), 0.05); box-shadow: 0 0 0 4px rgba(var(--primary-rgb), 0.1); }
        .glass-input option { background: #1a1220; color: white; }

        .view-box {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 0.75rem;
            padding: 0.85rem 1.25rem;
            color: white;
            font-size: 0.875rem;
            font-weight: 600;
            min-height: 48px;
            display: flex;
            align-items: center;
        }
        
        .view-btn { 
            size: 48px; border-radius: 16px; 
            background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.05); 
            color: var(--text-main); opacity: 0.3; transition: all 0.3s; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
        }
        .view-btn.active { background: rgba(var(--primary-rgb), 0.1); color: var(--primary); border-color: rgba(var(--primary-rgb), 0.2); opacity: 1; }

        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fade-in { animation: fadeIn 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards; }

        .status-card-primary { border: 1px solid rgba(var(--primary-rgb), 0.3); background: linear-gradient(135deg, rgba(var(--primary-rgb), 0.05) 0%, transparent 100%); }
        .status-card-green   { border: 1px solid rgba(16, 185, 129, 0.3); background: linear-gradient(135deg, rgba(16, 185, 129, 0.05) 0%, transparent 100%); }
        .status-card-yellow  { border: 1px solid rgba(245, 158, 11, 0.3); background: linear-gradient(135deg, rgba(245, 158, 11, 0.05) 0%, transparent 100%); }
        .status-card-rose    { border: 1px solid rgba(244, 63, 94, 0.3); background: linear-gradient(135deg, rgba(244, 63, 94, 0.05) 0%, transparent 100%); }
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
            const inner = document.getElementById('modalInner');
            modal.style.display = 'flex';
            inner.classList.add('scale-100', 'opacity-100');
            inner.classList.remove('scale-95', 'opacity-0');
            content.innerHTML = '<div class="flex items-center justify-center p-20"><div class="size-10 border-4 border-primary/20 border-t-primary rounded-full animate-spin"></div></div>';
            try {
                const response = await fetch(`?ajax_user_id=${userId}`);
                const html = await response.text();
                content.innerHTML = html;
            } catch (error) { content.innerHTML = '<div class="text-rose-500 font-bold text-center p-10 uppercase italic tracking-widest text-[12px]">FAILED TO FETCH PROFILE</div>'; }
        }

        function closeUserModal() {
            const modal = document.getElementById('userModal');
            const inner = document.getElementById('modalInner');
            inner.classList.add('scale-95', 'opacity-0');
            inner.classList.remove('scale-100', 'opacity-100');
            setTimeout(() => { modal.style.display = 'none'; }, 200);
        }

        function toggleView(view) {
            const grid = document.getElementById('memberGridContainer');
            const table = document.getElementById('memberTableContainer');
            const gridBtn = document.getElementById('gridViewBtn');
            const tableBtn = document.getElementById('tableViewBtn');

            const activeClass = "flex-1 lg:size-9 rounded-lg bg-primary text-white flex items-center justify-center transition-all h-full active-view-btn";
            const inactiveClass = "flex-1 lg:size-9 rounded-lg text-white/40 hover:bg-white/5 hover:text-white flex items-center justify-center transition-all h-full";

            if(view === 'grid') {
                grid.classList.remove('hidden');
                table.classList.add('hidden');
                gridBtn.className = activeClass;
                tableBtn.className = inactiveClass;
            } else {
                grid.classList.add('hidden');
                table.classList.remove('hidden');
                tableBtn.className = activeClass;
                gridBtn.className = inactiveClass;
            }
        }
    </script>
</head>
<body class="antialiased flex h-screen overflow-hidden">

    <?php include '../includes/coach_sidebar.php'; ?>

    <div class="main-content flex-1 overflow-y-auto no-scrollbar">
        <div class="p-10 max-w-[1500px] mx-auto animate-fade-in">
            <header class="mb-10 flex justify-between items-end">
                <div>
                    <h2 class="text-3xl font-black uppercase tracking-tighter italic" style="color:var(--text-main)">
                        MEMBER <span style="color:var(--primary)" class="italic">MANAGEMENT</span>
                    </h2>
                    <p class="text-[10px] font-bold uppercase tracking-widest mt-1 opacity-50 italic" style="color:var(--text-main)">Track and manage your clients</p>
                </div>
                <div class="text-right">
                    <p id="headerClock" class="font-black italic text-2xl leading-none tracking-tighter pr-2" style="color:var(--text-main)">00:00:00 AM</p>
                    <p class="text-[10px] font-bold uppercase tracking-widest mt-2 pr-2 opacity-80" style="color:var(--primary)">
                        <?= date('l, M d, Y') ?>
                    </p>
                </div>
            </header>

            <!-- Membership Stat Dashboard -->
            <!-- Membership Stat Dashboard -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-10">
                <div class="glass-card p-8 status-card-primary relative overflow-hidden group block hover:scale-[1.02] transition-all">
                    <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-primary">groups</span>
                    <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">Total Clients</p>
                    <h3 class="text-2xl font-black uppercase" style="color:var(--text-main)"><?= $total_clients ?></h3>
                    <p class="text-[10px] font-black uppercase mt-2" style="color:var(--primary)">All Assigned</p>
                </div>

                <div class="glass-card p-8 status-card-green relative overflow-hidden group block hover:scale-[1.02] transition-all">
                    <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-emerald-500">verified</span>
                    <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">Active Users</p>
                    <h3 class="text-2xl font-black uppercase" style="color:var(--text-main)"><?= $active_clients_count ?></h3>
                    <p class="text-emerald-500 text-[10px] font-black uppercase mt-2">Currently Active</p>
                </div>

                <div class="glass-card p-8 status-card-yellow relative overflow-hidden group block hover:scale-[1.02] transition-all">
                    <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-amber-500">timer</span>
                    <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">Pending Requests</p>
                    <h3 class="text-2xl font-black uppercase" style="color:var(--text-main)"><?= $pending_sessions_count ?></h3>
                    <p class="text-amber-500 text-[10px] font-black uppercase mt-2">Awaiting Action</p>
                </div>

                <div class="glass-card p-8 status-card-rose relative overflow-hidden group block hover:scale-[1.02] transition-all">
                    <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-rose-500">monitoring</span>
                    <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">Completed Sessions</p>
                    <h3 class="text-2xl font-black uppercase" style="color:var(--text-main)"><?= $lifetime_sessions ?></h3>
                    <p class="text-rose-500 text-[10px] font-black uppercase mt-2">Lifetime Total</p>
                </div>
            </div>

            <!-- Filter Hub -->
            <div class="px-8 py-6 border-b border-white/5 flex flex-col md:flex-row items-center gap-4 bg-white/[0.01] mb-8 rounded-t-3xl rounded-b-xl border border-white/10 glass-card relative z-[60]">
                <form id="filterForm" method="GET" class="w-full flex flex-col md:flex-row items-center gap-4">
                    <div class="relative flex-1 group">
                        <div class="relative bg-white/5 border border-white/10 rounded-2xl overflow-hidden flex items-center h-[52px] hover:border-white/20 transition-all focus-within:border-primary/50">
                            <span class="material-symbols-outlined absolute left-4 text-primary/60 text-base pointer-events-none transition-transform group-focus-within:scale-110">search</span>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search by name or member code..." class="w-full bg-transparent border-none text-white text-[10px] font-black uppercase tracking-widest placeholder:text-white/40 pl-11 pr-4 focus:outline-none focus:ring-0 h-full outline-none shadow-none">
                        </div>
                    </div>
                    
                    <div class="w-full lg:w-fit flex items-center gap-2">
                        <div class="w-full lg:w-48 relative">
                            <div class="relative bg-white/5 border border-white/10 rounded-2xl overflow-hidden flex items-center h-[52px] hover:border-white/20 transition-all">
                                <span class="material-symbols-outlined absolute left-4 text-primary/60 text-base pointer-events-none">filter_list</span>
                                <select name="status" class="w-full bg-transparent border-none text-white text-[10px] font-black uppercase tracking-widest cursor-pointer pl-11 pr-10 focus:outline-none focus:ring-0 h-full appearance-none outline-none shadow-none" onchange="document.getElementById('filterForm').submit()">
                                    <option value="" class="bg-[#141216]">Status filter</option>
                                    <option value="Active" class="bg-[#141216]" <?= $status_filter === 'Active' ? 'selected' : '' ?>>Active</option>
                                    <option value="Pending" class="bg-[#141216]" <?= $status_filter === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                    <option value="Expired" class="bg-[#141216]" <?= $status_filter === 'Expired' ? 'selected' : '' ?>>Expired</option>
                                </select>
                                <span class="material-symbols-outlined absolute right-4 text-white/40 text-base pointer-events-none">expand_more</span>
                            </div>
                        </div>

                        <div class="w-full lg:w-56 relative">
                            <div class="relative bg-white/5 border border-white/10 rounded-2xl overflow-hidden flex items-center h-[52px] hover:border-white/20 transition-all">
                                <span class="material-symbols-outlined absolute left-4 text-primary/60 text-base pointer-events-none">sort</span>
                                <select name="sort" class="w-full bg-transparent border-none text-white text-[10px] font-black uppercase tracking-widest cursor-pointer pl-11 pr-10 focus:outline-none focus:ring-0 h-full appearance-none outline-none shadow-none" onchange="document.getElementById('filterForm').submit()">
                                    <option value="recent" class="bg-[#141216]" <?= $sort_by === 'recent' ? 'selected' : '' ?>>Sort: Activity</option>
                                    <option value="oldest" class="bg-[#141216]" <?= $sort_by === 'oldest' ? 'selected' : '' ?>>Sort: Oldest</option>
                                    <option value="name_asc" class="bg-[#141216]" <?= $sort_by === 'name_asc' ? 'selected' : '' ?>>Sort: Name (A-Z)</option>
                                    <option value="name_desc" class="bg-[#141216]" <?= $sort_by === 'name_desc' ? 'selected' : '' ?>>Sort: Name (Z-A)</option>
                                </select>
                                <span class="material-symbols-outlined absolute right-4 text-white/40 text-base pointer-events-none">expand_more</span>
                            </div>
                        </div>

                        <a href="coach_members.php" class="size-[52px] rounded-2xl bg-white/5 border border-white/5 flex items-center justify-center text-gray-500 hover:text-white hover:bg-white/10 transition-all group active:scale-95 shrink-0" title="Reset Filters">
                            <span class="material-symbols-outlined text-lg group-hover:rotate-180 transition-transform duration-500">restart_alt</span>
                        </a>

                        <div class="h-8 w-px bg-white/10 mx-1 hidden lg:block"></div>

                        <div class="flex bg-[#141216] border border-white/10 p-1 rounded-xl h-[44px] shrink-0 items-center gap-1 w-full lg:w-auto">
                            <button type="button" id="gridViewBtn" onclick="toggleView('grid')" class="flex-1 lg:size-9 rounded-lg bg-primary text-white flex items-center justify-center transition-all h-full active-view-btn shadow-none" title="Grid View">
                                <span class="material-symbols-outlined text-sm">grid_view</span>
                            </button>
                            <button type="button" id="tableViewBtn" onclick="toggleView('list')" class="flex-1 lg:size-9 rounded-lg text-white/40 hover:bg-white/5 hover:text-white flex items-center justify-center transition-all h-full shadow-none" title="Table View">
                                <span class="material-symbols-outlined text-sm">table_rows</span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Member Grid Container -->
            <div id="memberGridContainer" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-8">
                <?php if(count($members) > 0): foreach($members as $index => $m): ?>
                <div class="glass-card p-8 flex flex-col gap-6 relative group transition-all duration-300">
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-6">
                            <div class="size-16 rounded-2xl bg-[--background] flex items-center justify-center font-black italic text-2xl border border-transparent shadow-none" style="color:var(--primary)">
                                <?= strtoupper(substr($m['first_name'] ?? 'M',0,1)) ?>
                            </div>
                            <div>
                                <h3 class="font-black uppercase italic tracking-tight text-xl leading-tight group-hover:text-[--primary] transition-colors" style="color:var(--text-main)"><?= htmlspecialchars(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')) ?></h3>
                                <div class="flex items-center gap-2 mt-1">
                                    <p class="text-[11px] font-black uppercase tracking-[0.2em]" style="color:var(--highlight)"><?= $m['session_count'] ?> SESSIONS</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- PREVIEW STATE: Structured View -->
                    <div class="space-y-5 py-6 border-y border-white/5">
                        <div class="flex justify-between items-center">
                            <p class="text-[11px] font-black uppercase tracking-widest text-[--text-main] opacity-40 italic">Status</p>
                            <?php 
                                $ws = $m['member_status'] ?? 'Inactive';
                                $w_color_style = ($ws === 'Active') ? 'color:var(--primary);' : '';
                                $w_color_class = ($ws === 'Active') ? '' : (($ws === 'Pending') ? 'text-amber-500' : 'text-[--text-main] opacity-50');
                            ?>
                            <p class="text-[12px] font-bold italic <?= $w_color_class ?>" style="<?= $w_color_style ?>"><?= $ws ?></p>
                        </div>
                        <div class="flex justify-between items-center">
                            <p class="text-[11px] font-black uppercase tracking-widest text-[--text-main] opacity-40 italic">Workout Plan</p>
                            <p class="text-[12px] font-bold italic truncate ml-4" style="color:var(--primary)"><?= htmlspecialchars(($m['workout_plan'] ?: 'General Plan')) ?></p>
                        </div>
                        <div class="flex justify-between items-center">
                            <p class="text-[11px] font-black uppercase tracking-widest text-[--text-main] opacity-40 italic">Last Visit</p>
                            <p class="text-[12px] font-bold text-[--text-main] opacity-70 italic"><?= $m['last_visit'] ? date('M d, Y', strtotime($m['last_visit'])) : 'No visits yet' ?></p>
                        </div>
                    </div>

                    <div class="flex items-center gap-4 mt-auto pt-2">
                        <button onclick="viewUserProfile(<?= $m['user_id'] ?>)" class="w-full h-14 rounded-2xl bg-white/5 border border-transparent flex items-center justify-center hover:bg-white/10 transition-all group/btn shadow-none">
                            <span class="material-symbols-rounded text-[--text-main] opacity-50 group-hover/btn:text-primary group-hover/btn:scale-110 transition-all mr-2">visibility</span>
                            <span class="text-[11px] font-black uppercase italic tracking-widest text-[--text-main] opacity-80 group-hover/btn:text-primary">View Profile</span>
                        </button>
                    </div>
                </div>
                <?php endforeach; else: ?>
                <div class="col-span-full py-24 text-center glass-card border-dashed">
                    <div class="size-20 rounded-full bg-white/5 flex items-center justify-center mx-auto mb-8 border border-white/5">
                        <span class="material-symbols-rounded text-4xl text-[--text-main]/10">person_search</span>
                    </div>
                    <h4 class="text-white font-black italic uppercase tracking-tighter text-2xl mb-4">No active members found</h4>
                    <p class="text-[--text-main]/30 max-w-md mx-auto italic text-sm font-bold uppercase tracking-widest leading-loose px-10">Members assigned to you will appear here once they are approved.</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Member Table Container -->
            <div id="memberTableContainer" class="hidden overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-white/5 border-b border-white/5 text-[10px] font-black uppercase tracking-[0.25em]">
                                <th class="px-8 py-5 opacity-40">Member Info</th>
                                <th class="px-8 py-5 opacity-40">Workout Plan</th>
                                <th class="px-8 py-5 opacity-40 text-center">Status</th>
                                <th class="px-8 py-5 opacity-40 text-center">Last Visit</th>
                                <th class="px-8 py-5 opacity-40 text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <?php foreach($members as $m): ?>
                            <tr class="hover:bg-white/5 transition-all group/row">
                                <td class="px-8 py-5">
                                    <div class="flex items-center gap-4">
                                        <div class="size-10 rounded-xl flex items-center justify-center font-black italic text-lg bg-primary/10 text-primary">
                                            <?= strtoupper(substr($m['first_name'] ?? 'M',0,1)) ?>
                                        </div>
                                        <div class="flex flex-col">
                                            <span class="text-[12px] font-black italic uppercase text-white"><?= htmlspecialchars(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')) ?></span>
                                            <span class="text-[10px] font-black uppercase tracking-widest text-gray-500 mt-0.5"><?= $m['session_count'] ?> Total Sessions</span>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-8 py-5">
                                    <span class="text-[11px] font-black italic uppercase text-[--text-main] opacity-70"><?= htmlspecialchars(($m['workout_plan'] ?: 'General Plan')) ?></span>
                                </td>
                                <td class="px-8 py-5 text-center">
                                    <?php 
                                        $ws = $m['member_status'] ?? 'Inactive';
                                        $w_class = ($ws === 'Active') ? 'text-emerald-500 bg-emerald-500/10' : (($ws === 'Pending') ? 'text-amber-500 bg-amber-500/10' : 'text-gray-400 bg-white/5');
                                    ?>
                                    <span class="px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest <?= $w_class ?>"><?= $ws ?></span>
                                </td>
                                <td class="px-8 py-5 text-center">
                                    <span class="text-[11px] font-black text-gray-400 uppercase"><?= $m['last_visit'] ? date('M d, Y', strtotime($m['last_visit'])) : 'No visits' ?></span>
                                </td>
                                <td class="px-8 py-5">
                                    <div class="flex items-center justify-center">
                                        <button onclick="viewUserProfile(<?= $m['user_id'] ?>)" class="size-9 rounded-xl bg-white/5 border border-white/10 text-white hover:bg-primary transition-all active:scale-95 shadow-lg flex items-center justify-center group/btn" title="View Profile">
                                            <span class="material-symbols-outlined text-lg group-hover/btn:scale-110 transition-transform">visibility</span>
                                        </button>
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

    <!-- Universal Profile Modal -->
    <div id="userModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-8">
        <div class="absolute inset-0 bg-black/80 backdrop-blur-2xl" onclick="closeUserModal()"></div>
        <div id="modalInner" class="relative w-full max-w-4xl glass-card border-white/10 shadow-[0_0_100px_rgba(0,0,0,0.5)] p-12 transition-all duration-300 scale-95 opacity-0">
            <div id="modalContent" class="no-scrollbar max-h-[80vh] overflow-y-auto"></div>
        </div>
    </div>

</body>
</html>