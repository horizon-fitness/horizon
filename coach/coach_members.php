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

    // In Gym
    $stmtInGym = $pdo->prepare("
        SELECT COUNT(DISTINCT m.member_id) 
        FROM members m
        JOIN bookings b ON m.member_id = b.member_id
        JOIN attendance a ON m.member_id = a.member_id
        WHERE b.coach_id = ? AND b.booking_status != 'Rejected'
        AND a.attendance_date = CURDATE() AND a.check_in_time IS NOT NULL AND a.check_out_time IS NULL
    ");
    $stmtInGym->execute([$coach_id]);
    $in_gym_count = $stmtInGym->fetchColumn();

    // Upcoming Sessions
    $stmtUpcoming = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE coach_id = ? AND booking_status IN ('Approved', 'Confirmed') AND booking_date >= CURDATE()");
    $stmtUpcoming->execute([$coach_id]);
    $upcoming_sessions_count = $stmtUpcoming->fetchColumn();
}

// --- AJAX PROFILE FETCH ---
if (isset($_GET['ajax_user_id'])) {
    $target_uid = (int)$_GET['ajax_user_id'];
    $hasWorkoutsTable = false;
    try {
        $pdo->query("SELECT 1 FROM member_workouts LIMIT 1");
        $hasWorkoutsTable = true;
    } catch (PDOException $e) { }

    $workout_plan_sql = "(SELECT fitness_goal FROM user_fitness_profiles WHERE user_id = u.user_id LIMIT 1)";

    $stmtAjax = $pdo->prepare("
        SELECT u.*, m.*, a.address_line, a.barangay, a.city, a.province, a.region,
        COALESCE(m.profile_picture, u.profile_picture) as profile_picture,
        (SELECT COUNT(*) FROM bookings WHERE member_id = m.member_id AND coach_id = ? AND booking_status IN ('Approved', 'Confirmed')) as session_count,
        (SELECT MAX(attendance_date) FROM attendance WHERE member_id = m.member_id) as last_visit,
        (SELECT COUNT(*) FROM member_subscriptions WHERE member_id = m.member_id AND end_date < CURDATE()) as is_expired,
        $workout_plan_sql as workout_plan
        FROM users u 
        JOIN members m ON u.user_id = m.user_id 
        LEFT JOIN addresses a ON m.address_id = a.address_id
        WHERE u.user_id = ? AND m.gym_id = ? AND EXISTS (SELECT 1 FROM bookings WHERE member_id = m.member_id AND coach_id = ? AND booking_status != 'Rejected')
        LIMIT 1
    ");
    
    $ajax_params = [$coach_id];
    $ajax_params[] = $target_uid;
    $ajax_params[] = $gym_id;
    $ajax_params[] = $coach_id;

    $stmtAjax->execute($ajax_params);
    $u = $stmtAjax->fetch();

    if ($u): ?>
        <div class="mb-7 relative">
            <div class="flex items-start justify-between mb-1">
                <h3 class="text-[22px] font-black uppercase tracking-tighter leading-none flex gap-2">
                    <span class="text-white">Member</span><span class="text-primary" style="color:var(--primary)">Details</span>
                </h3>
                <button onclick="closeUserModal()" class="size-8 flex items-center justify-center rounded-lg bg-white/5 hover:bg-white/10 text-white/50 hover:text-white transition-colors border border-white/5">
                    <span class="material-symbols-outlined text-[14px]">close</span>
                </button>
            </div>
            <?php
                if (($u['is_expired'] ?? 0) > 0 || ($u['member_status'] ?? '') === 'Expired') {
                    $m_status = 'Expired';
                    $m_color = 'text-red-500/80';
                } else {
                    $m_status = 'Active';
                    $m_color = 'text-white/30';
                }
            ?>
            <p class="text-[10px] font-black uppercase tracking-[0.2em] <?= $m_color ?> mb-6"><?= $m_status ?> MEMBERSHIP</p>
            
            <div class="flex items-center gap-4">
                <div class="size-[56px] rounded-full flex items-center justify-center shrink-0 border border-white/10 overflow-hidden shadow-inner relative" style="background:rgba(var(--primary-rgb), 0.1); color:var(--primary)">
                    <?php if(!empty($u['profile_picture'])): 
                        $pfp = $u['profile_picture'];
                        $display_pic = (strpos($pfp, 'data:') === 0 || strpos($pfp, 'http') === 0 || strpos($pfp, '../') === 0) ? $pfp : '../' . $pfp;
                    ?>
                        <img src="<?= htmlspecialchars($display_pic) ?>" alt="DP" class="w-full h-full object-cover">
                    <?php else: ?>
                        <span class="font-black text-xl uppercase"><?= substr($u['first_name'] ?? 'M', 0, 1) ?></span>
                    <?php endif; ?>
                </div>
                <div>
                    <h4 class="text-[18px] font-bold text-white leading-none mb-1 capitalize"><?= htmlspecialchars(($u['first_name'] ?? '') . ' ' . (($u['middle_name'] ?? '') ? $u['middle_name'] . ' ' : '') . ($u['last_name'] ?? '')) ?></h4>
                    <p class="text-[11px] font-medium text-gray-400 lowercase"><?= htmlspecialchars($u['email'] ?? 'N/A') ?></p>
                </div>
            </div>
        </div>

        <div class="flex flex-col gap-3">
            <div class="bg-white/[0.03] border border-white/5 rounded-[20px] p-6">
                <div class="grid grid-cols-2 gap-y-6 gap-x-4">
                    <div>
                        <p class="text-[9px] font-bold uppercase tracking-[0.1em] text-gray-500 mb-1.5">Contact Number</p>
                        <p class="text-[13px] font-bold text-white"><?= htmlspecialchars(($u['contact_number'] ?? '') ?: 'N/A') ?></p>
                    </div>
                    <div>
                        <p class="text-[9px] font-bold uppercase tracking-[0.1em] text-gray-500 mb-1.5">Fitness Goal</p>
                        <p class="text-[13px] font-bold capitalize" style="color:var(--primary)"><?= htmlspecialchars(($u['workout_plan'] ?: 'General Fitness')) ?></p>
                    </div>
                </div>
            </div>
            <div class="bg-white/[0.03] border border-white/5 rounded-[20px] p-6">
                <p class="text-[9px] font-bold uppercase tracking-[0.1em] text-gray-500 mb-1.5">Residential Address</p>
                <p class="text-[13px] font-bold text-white leading-relaxed"><?= htmlspecialchars(($u['address_line'] ?? '') ?: 'No address listed') ?></p>
            </div>
            <div class="bg-white/[0.03] border border-white/5 rounded-[20px] p-6">
                <div class="grid grid-cols-2 gap-y-6 gap-x-4">
                    <div>
                        <p class="text-[9px] font-bold uppercase tracking-[0.1em] text-gray-500 mb-1.5">Last Visit</p>
                        <p class="text-[13px] font-bold text-white"><?= $u['last_visit'] ? date('M d, Y', strtotime($u['last_visit'])) : 'No visits yet' ?></p>
                    </div>
                    <div>
                        <p class="text-[9px] font-bold uppercase tracking-[0.1em] text-gray-500 mb-1.5">Total Sessions</p>
                        <p class="text-[13px] font-bold text-white"><?= $u['session_count'] ?? 0 ?></p>
                    </div>
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
$sql = "
    SELECT DISTINCT m.member_id, u.user_id, u.first_name, u.last_name, u.email, u.contact_number, m.member_code, m.member_status, 
    COALESCE(m.profile_picture, u.profile_picture) as profile_picture,
    (SELECT COUNT(*) FROM bookings WHERE member_id = m.member_id AND coach_id = ? AND booking_status IN ('Approved', 'Confirmed')) as session_count,
    (SELECT MAX(attendance_date) FROM attendance WHERE member_id = m.member_id) as last_visit,
    (SELECT fitness_goal FROM user_fitness_profiles WHERE user_id = u.user_id LIMIT 1) as workout_plan,
    (SELECT COUNT(*) FROM attendance WHERE member_id = m.member_id AND attendance_date = CURDATE() AND check_in_time IS NOT NULL AND check_out_time IS NULL) as is_in_gym,
    (SELECT COUNT(*) FROM member_subscriptions WHERE member_id = m.member_id AND end_date < CURDATE()) as is_expired
    FROM members m
    JOIN users u ON m.user_id = u.user_id
    JOIN bookings b ON m.member_id = b.member_id
    WHERE b.booking_status != 'Rejected' AND " . implode(" AND ", $where_clauses) . "
    $order_sql
";

$members = [];
if ($coach_id > 0) {
    try {
        $final_params = array_merge([$coach_id], $filter_params);
        $stmtMembers = $pdo->prepare($sql);
        $stmtMembers->execute($final_params);
        $members = $stmtMembers->fetchAll();
        
        // --- INJECT TEST SAMPLE DATA ---
        $mock_plans = ['Strength Training', 'Cardio & HIIT', 'Hypertrophy', 'General Fitness', 'Flexibility / Yoga'];
        for ($i = 1; $i <= 35; $i++) {
            $is_exp = ($i % 5 === 0) ? 1 : 0;
            $members[] = [
                'member_id' => 9000 + $i,
                'user_id' => 9000 + $i,
                'first_name' => 'Sample',
                'last_name' => 'Client ' . $i,
                'email' => "sampleclient{$i}@example.com",
                'contact_number' => '0912345678' . ($i % 10),
                'member_code' => 'MEM-900' . str_pad($i, 2, '0', STR_PAD_LEFT),
                'member_status' => $is_exp ? 'Expired' : 'Active',
                'profile_picture' => null,
                'session_count' => rand(2, 45),
                'last_visit' => date('Y-m-d H:i:s', strtotime('-' . rand(0, 20) . ' days')),
                'workout_plan' => $mock_plans[array_rand($mock_plans)],
                'is_in_gym' => ($i % 4 === 0 && !$is_exp) ? 1 : 0,
                'is_expired' => $is_exp
            ];
        }
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
    <title>My Members | Horizon Systems</title>
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
        .status-card-blue    { border: 1px solid rgba(59, 130, 246, 0.3); background: linear-gradient(135deg, rgba(59, 130, 246, 0.05) 0%, transparent 100%); }

        #userModal {
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            left: 110px;
            z-index: 200;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            transition: left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            pointer-events: none;
        }

        .side-nav:hover ~ #userModal,
        .side-nav:hover ~ .main-content ~ #userModal {
            left: 300px;
        }
        #userModal.flex { display: flex !important; }
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
            const backdrop = document.getElementById('modalBackdrop');
            const inner = document.getElementById('modalInner');
            const content = document.getElementById('modalContent');
            
            modal.classList.add('flex');
            modal.classList.remove('hidden');

            setTimeout(() => {
                backdrop.classList.remove('opacity-0');
                inner.classList.remove('scale-90', 'opacity-0');
                inner.classList.add('scale-100', 'opacity-100');
            }, 10);
            
            content.innerHTML = '<div class="flex items-center justify-center p-20"><div class="size-10 border-4 border-primary/20 border-t-primary rounded-full animate-spin"></div></div>';
            try {
                const response = await fetch(`?ajax_user_id=${userId}`);
                const html = await response.text();
                content.innerHTML = html;
            } catch (error) { content.innerHTML = '<div class="text-rose-500 font-bold text-center p-10 uppercase italic tracking-widest text-[12px]">FAILED TO FETCH PROFILE</div>'; }
        }

        function closeUserModal() {
            const modal = document.getElementById('userModal');
            const backdrop = document.getElementById('modalBackdrop');
            const inner = document.getElementById('modalInner');
            
            backdrop.classList.add('opacity-0');
            inner.classList.remove('scale-100', 'opacity-100');
            inner.classList.add('scale-90', 'opacity-0');

            setTimeout(() => {
                modal.classList.remove('flex');
                modal.classList.add('hidden');
            }, 300);
        }

        function toggleView(view) {
            const grid = document.getElementById('memberGridContainer');
            const table = document.getElementById('memberTableContainer');
            const gridPagination = document.getElementById('memberGridPagination');
            const gridBtn = document.getElementById('gridViewBtn');
            const tableBtn = document.getElementById('tableViewBtn');
            
            const filterForm = document.getElementById('filterForm');
            const gridFilterSlot = document.getElementById('gridFilterBar');
            const tableFilterSlot = document.getElementById('tableFilterSlot');

            const activeClass = "flex-1 lg:size-9 rounded-lg bg-primary text-white flex items-center justify-center transition-all h-full shadow-none";
            const inactiveClass = "flex-1 lg:size-9 rounded-lg text-white/40 hover:bg-white/5 hover:text-white flex items-center justify-center transition-all h-full shadow-none";

            if(view === 'grid') {
                grid.classList.remove('hidden');
                table.classList.add('hidden');
                if(gridPagination) gridPagination.classList.remove('hidden');
                if(gridBtn) gridBtn.className = activeClass;
                if(tableBtn) tableBtn.className = inactiveClass;
                
                // Move filter to Grid position
                if(gridFilterSlot && filterForm) gridFilterSlot.appendChild(filterForm);
                if(gridFilterSlot) gridFilterSlot.classList.remove('hidden');
            } else {
                grid.classList.add('hidden');
                table.classList.remove('hidden');
                if(gridPagination) gridPagination.classList.add('hidden');
                if(tableBtn) tableBtn.className = activeClass;
                if(gridBtn) gridBtn.className = inactiveClass;
                
                // Move filter to Table position
                if(tableFilterSlot && filterForm) tableFilterSlot.appendChild(filterForm);
                if(gridFilterSlot) gridFilterSlot.classList.add('hidden');
            }
        }
        class ElitePaginator {
            constructor(containerId, itemsPerPage, itemSelector, paginationContainerId = null) {
                this.container = document.getElementById(containerId);
                if (!this.container) return;

                this.pageSize = itemsPerPage;
                this.itemSelector = itemSelector;
                this.currentPage = 1;
                this.paginationContainerId = paginationContainerId || `${containerId}-pagination`;

                this.init();
            }

            init() {
                this.update();
            }

            update() {
                const allItems = Array.from(this.container.querySelectorAll(this.itemSelector));
                allItems.forEach(item => {
                    if (item.classList.contains('filtered-out')) {
                        item.classList.add('hidden');
                    }
                });

                const items = Array.from(this.container.querySelectorAll(`${this.itemSelector}:not(.filtered-out):not(.no-results-row)`));
                const totalItems = items.length;
                const totalPages = Math.max(1, Math.ceil(totalItems / this.pageSize));

                const start = (this.currentPage - 1) * this.pageSize;
                const end = start + this.pageSize;

                items.forEach((item, index) => {
                    if (index >= start && index < end) {
                        item.classList.remove('hidden');
                        item.classList.add('animate-in', 'fade-in', 'duration-500');
                    } else {
                        item.classList.add('hidden');
                    }
                });

                this.renderControls(totalItems, totalPages, start, end);
            }

            renderControls(totalItems, totalPages, start, endReached) {
                let controls = document.getElementById(this.paginationContainerId);
                if (!controls) return;
                
                let isHidden = controls.classList.contains('hidden');
                
                if (this.container.id === 'memberGridContainer') {
                    controls.className = 'px-8 py-5 flex justify-between items-center w-full glass-card mt-8 rounded-2xl';
                } else {
                    controls.className = 'px-8 py-6 border-t border-white/5 bg-white/[0.01] flex justify-between items-center w-full';
                }
                
                if (isHidden) controls.classList.add('hidden');
                
                const end = Math.min(endReached, totalItems);

                const prevEnabled = this.currentPage > 1;
                const nextEnabled = this.currentPage < totalPages;

                const prevClass = prevEnabled 
                    ? "px-4 py-2 rounded-[10px] flex items-center justify-center text-[10px] font-extrabold uppercase tracking-[0.1em] transition-all bg-white/[0.03] border border-white/5 text-white hover:bg-primary hover:border-primary hover:-translate-y-[1px] hover:shadow-lg cursor-pointer"
                    : "px-4 py-2 rounded-[10px] flex items-center justify-center text-[10px] font-extrabold uppercase tracking-[0.1em] transition-all bg-white/[0.02] border border-white/5 text-white/30 opacity-50 cursor-not-allowed";

                const nextClass = nextEnabled 
                    ? "px-4 py-2 rounded-[10px] flex items-center justify-center text-[10px] font-extrabold uppercase tracking-[0.1em] transition-all bg-white/[0.03] border border-white/5 text-white hover:bg-primary hover:border-primary hover:-translate-y-[1px] hover:shadow-lg cursor-pointer"
                    : "px-4 py-2 rounded-[10px] flex items-center justify-center text-[10px] font-extrabold uppercase tracking-[0.1em] transition-all bg-white/[0.02] border border-white/5 text-white/30 opacity-50 cursor-not-allowed";

                controls.innerHTML = `
                    <p class="text-[9px] font-black uppercase tracking-[0.2em] text-white/30">
                        SHOWING ${totalItems === 0 ? 0 : start + 1} TO ${end} OF ${totalItems} ENTRIES
                    </p>
                    
                    <div class="flex items-center gap-2">
                        <button type="button" onclick="horizonPaginators['${this.container.id}'].setPage(${this.currentPage - 1})" 
                            ${!prevEnabled ? 'disabled' : ''}
                            class="${prevClass}">
                            PREV
                        </button>
                        
                        <div class="flex items-center gap-1">
                            ${this.renderPageNumbers(totalPages)}
                        </div>
                        
                        <button type="button" onclick="horizonPaginators['${this.container.id}'].setPage(${this.currentPage + 1})" 
                            ${!nextEnabled ? 'disabled' : ''}
                            class="${nextClass}">
                            NEXT
                        </button>
                    </div>
                `;
            }

            renderPageNumbers(totalPages) {
                let html = '';
                const current = this.currentPage;
                const containerId = this.container.id;

                const makeBtn = (page, isActive) => {
                    const activeClass = "px-4 py-2 rounded-[10px] bg-primary border border-primary text-white text-[10px] font-extrabold uppercase tracking-[0.1em] flex items-center justify-center cursor-default";
                    const inactiveClass = "px-4 py-2 rounded-[10px] bg-white/[0.03] border border-white/5 text-white/30 text-[10px] font-extrabold uppercase tracking-[0.1em] hover:bg-primary hover:text-white hover:border-primary hover:-translate-y-[1px] hover:shadow-lg transition-all flex items-center justify-center cursor-pointer";
                    return `<button type="button" onclick="horizonPaginators['${containerId}'].setPage(${page})" class="${isActive ? activeClass : inactiveClass}">${page}</button>`;
                };
                
                const makeEllipsis = () => `<span class="text-white/30 text-[10px] font-black mx-1 tracking-[0.2em]">...</span>`;

                if (totalPages <= 5) {
                    for (let i = 1; i <= totalPages; i++) html += makeBtn(i, i === current);
                } else {
                    if (current <= 3) {
                        html += makeBtn(1, current === 1);
                        html += makeBtn(2, current === 2);
                        html += makeBtn(3, current === 3);
                        html += makeEllipsis();
                        html += makeBtn(totalPages, current === totalPages);
                    } else if (current >= totalPages - 2) {
                        html += makeBtn(1, current === 1);
                        html += makeEllipsis();
                        html += makeBtn(totalPages - 2, current === totalPages - 2);
                        html += makeBtn(totalPages - 1, current === totalPages - 1);
                        html += makeBtn(totalPages, current === totalPages);
                    } else {
                        html += makeBtn(1, current === 1);
                        html += makeEllipsis();
                        html += makeBtn(current - 1, false);
                        html += makeBtn(current, true);
                        html += makeBtn(current + 1, false);
                        html += makeEllipsis();
                        html += makeBtn(totalPages, current === totalPages);
                    }
                }
                return html;
            }

            setPage(page) {
                this.currentPage = page;
                this.update();
                // Smooth scroll to container depending on view
                if(this.container.id === 'memberGridContainer') {
                    document.querySelector('.main-content').scrollIntoView({ behavior: 'smooth', block: 'start' });
                } else {
                    document.getElementById('memberTableContainer').scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }
        }

        window.horizonPaginators = {};
        document.addEventListener('DOMContentLoaded', () => {
            horizonPaginators['memberGridContainer'] = new ElitePaginator('memberGridContainer', 6, '.member-grid-card', 'memberGridPagination');
            horizonPaginators['memberTableContainer'] = new ElitePaginator('memberTableContainer', 10, '.member-table-row', 'memberTablePagination');
            
            // Initial filter run if there are predefined PHP values
            if (document.getElementById('gridSearchInput').value || document.getElementById('gridStatusFilter').value !== 'all') {
                filterGrid();
                filterTable();
            }
        });

        function toggleCustomDropdown(trigger, event) {
            event.stopPropagation();
            const dropdown = trigger.nextElementSibling;
            
            document.querySelectorAll('.custom-select-dropdown').forEach(d => {
                if (d !== dropdown) d.classList.add('hidden');
            });
            
            dropdown.classList.toggle('hidden');
        }

        function filterCustomSelect(input) {
            const filter = input.value.toUpperCase();
            const container = input.closest('.custom-select-container');
            const options = container.querySelectorAll('.custom-option');
            options.forEach(opt => {
                if (opt.textContent.toUpperCase().indexOf(filter) > -1) {
                    opt.style.display = "";
                } else {
                    opt.style.display = "none";
                }
            });
            const dropdown = container.querySelector('.custom-select-dropdown');
            if (dropdown && dropdown.classList.contains('hidden')) {
                document.querySelectorAll('.custom-select-dropdown').forEach(d => d.classList.add('hidden'));
                dropdown.classList.remove('hidden');
            }
        }

        document.addEventListener('click', (e) => {
            if (!e.target.closest('.custom-select-container')) {
                document.querySelectorAll('.custom-select-dropdown').forEach(d => d.classList.add('hidden'));
            }

            const option = e.target.closest('.custom-option');
            if (option) {
                e.stopPropagation();
                const container = option.closest('.custom-select-container');
                const hiddenInput = container.querySelector('input[type="hidden"]');
                const displayInput = container.querySelector('input[type="text"]');
                const dropdown = container.querySelector('.custom-select-dropdown');
                
                hiddenInput.value = option.dataset.value;
                displayInput.value = option.textContent.trim();
                
                container.querySelectorAll('.custom-option').forEach(opt => {
                    opt.classList.remove('bg-primary', 'text-white');
                    opt.classList.add('text-white/60');
                });
                option.classList.add('bg-primary', 'text-white');
                option.classList.remove('text-white/60');
                
                dropdown.classList.add('hidden');
                filterUnified();
            }
        });

        function resetUnifiedFilters() {
            document.getElementById('globalSearchInput').value = '';
            
            const userContainer = document.getElementById('userSearchContainer');
            if(userContainer) {
                userContainer.querySelector('input[type="hidden"]').value = 'all';
                userContainer.querySelector('input[type="text"]').value = 'All Users';
                userContainer.querySelectorAll('.custom-option').forEach(opt => {
                    if(opt.dataset.value === 'all') { opt.classList.add('bg-primary', 'text-white'); opt.classList.remove('text-white/60'); }
                    else { opt.classList.remove('bg-primary', 'text-white'); opt.classList.add('text-white/60'); }
                });
            }

            const statusContainer = document.getElementById('globalStatusFilter').closest('.custom-select-container');
            statusContainer.querySelector('input[type="hidden"]').value = 'all';
            statusContainer.querySelector('input[type="text"]').value = 'All Status';
            statusContainer.querySelectorAll('.custom-option').forEach(opt => {
                if(opt.dataset.value === 'all') { opt.classList.add('bg-primary', 'text-white'); opt.classList.remove('text-white/60'); }
                else { opt.classList.remove('bg-primary', 'text-white'); opt.classList.add('text-white/60'); }
            });

            const sortContainer = document.getElementById('globalSortFilter').closest('.custom-select-container');
            sortContainer.querySelector('input[type="hidden"]').value = 'recent';
            sortContainer.querySelector('input[type="text"]').value = 'Newest';
            sortContainer.querySelectorAll('.custom-option').forEach(opt => {
                if(opt.dataset.value === 'recent') { opt.classList.add('bg-primary', 'text-white'); opt.classList.remove('text-white/60'); }
                else { opt.classList.remove('bg-primary', 'text-white'); opt.classList.add('text-white/60'); }
            });

            filterUnified();
        }

        function filterUnified() {
            const query = (document.getElementById('globalSearchInput').value || '').toLowerCase();
            const userQuery = (document.getElementById('hidden_user_id').value || 'all');
            const statusFilter = document.getElementById('globalStatusFilter').value;
            
            const gridContainer = document.getElementById('memberGridContainer');
            const gridCards = Array.from(gridContainer.querySelectorAll('.member-grid-card'));
            
            let visibleGridCount = 0;
            gridCards.forEach(card => {
                let name = card.querySelector('.member-name').innerText.toLowerCase();
                let exactName = card.querySelector('.member-name').innerText.trim();
                let status = card.querySelector('.member-status-badge').innerText;
                
                let matchesSearch = name.includes(query);
                let matchesUser = (userQuery === 'all') || (exactName === userQuery);
                let matchesStatus = (statusFilter === 'all') || (status === statusFilter);
                
                if (matchesSearch && matchesUser && matchesStatus) {
                    card.classList.remove('filtered-out');
                    visibleGridCount++;
                } else {
                    card.classList.add('filtered-out');
                }
            });
            
            const gridNoResults = gridContainer.querySelector('.no-results-card');
            if (gridNoResults) {
                if (visibleGridCount === 0) {
                    gridNoResults.classList.remove('hidden');
                } else {
                    gridNoResults.classList.add('hidden');
                }
            }
            
            if (window.horizonPaginators && window.horizonPaginators['memberGridContainer']) {
                window.horizonPaginators['memberGridContainer'].currentPage = 1;
                window.horizonPaginators['memberGridContainer'].update();
            }

            const tbody = document.querySelector('#memberTableContainer tbody');
            if (tbody) {
                const tableRows = Array.from(tbody.querySelectorAll('.member-table-row'));
                let visibleTableCount = 0;
                tableRows.forEach(row => {
                    let name = row.querySelector('.member-name').innerText.toLowerCase();
                    let exactName = row.querySelector('.member-name').innerText.trim();
                    let status = row.querySelector('.member-status-badge').innerText;
                    
                    let matchesSearch = name.includes(query);
                    let matchesUser = (userQuery === 'all') || (exactName === userQuery);
                    let matchesStatus = (statusFilter === 'all') || (status === statusFilter);
                    
                    if (matchesSearch && matchesUser && matchesStatus) {
                        row.classList.remove('filtered-out');
                        visibleTableCount++;
                    } else {
                        row.classList.add('filtered-out');
                    }
                });
                
                const tableNoResults = tbody.querySelector('.no-results-row');
                if (tableNoResults) {
                    if (visibleTableCount === 0) {
                        tableNoResults.classList.remove('hidden');
                    } else {
                        tableNoResults.classList.add('hidden');
                    }
                }
                
                if (window.horizonPaginators && window.horizonPaginators['memberTableContainer']) {
                    window.horizonPaginators['memberTableContainer'].currentPage = 1;
                    window.horizonPaginators['memberTableContainer'].update();
                }
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
                    <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-amber-500">fitness_center</span>
                    <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">In Gym</p>
                    <h3 class="text-2xl font-black uppercase" style="color:var(--text-main)"><?= $in_gym_count ?></h3>
                    <p class="text-amber-500 text-[10px] font-black uppercase mt-2">Currently In Gym</p>
                </div>

                <div class="glass-card p-8 status-card-blue relative overflow-hidden group block hover:scale-[1.02] transition-all">
                    <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-blue-500">event</span>
                    <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">Upcoming Session</p>
                    <h3 class="text-2xl font-black uppercase" style="color:var(--text-main)"><?= $upcoming_sessions_count ?></h3>
                    <p class="text-blue-500 text-[10px] font-black uppercase mt-2">Next Sessions</p>
                </div>
            </div>

            <!-- Filter Hub (Grid Mode) -->
            <div id="gridFilterBar" class="px-8 py-6 flex flex-col md:flex-row items-center gap-4 bg-white/[0.01] mb-8 rounded-3xl border border-white/10 glass-card relative z-[60]">
                <form id="filterForm" class="w-full flex flex-col md:flex-row items-center gap-4" onsubmit="event.preventDefault(); filterUnified();">
                    
                    <!-- Search Input -->
                    <div class="relative flex-1 group min-w-[200px]">
                        <div class="relative bg-white/5 border border-white/10 rounded-2xl overflow-hidden flex items-center h-[52px] hover:border-white/20 transition-all focus-within:border-primary/50">
                            <span class="material-symbols-outlined absolute left-4 text-primary/60 text-base pointer-events-none transition-transform group-focus-within:scale-110">search</span>
                            <input type="text" id="globalSearchInput" placeholder="Search by name or code..." class="w-full bg-transparent border-none text-white text-[10px] font-black uppercase tracking-widest placeholder:text-white/40 pl-11 pr-4 focus:outline-none focus:ring-0 h-full outline-none shadow-none" oninput="filterUnified();" autocomplete="off">
                        </div>
                    </div>

                    <!-- Searchable User Selector -->
                    <div class="w-[240px] relative group shrink-0 custom-select-container" id="userSearchContainer">
                        <input type="hidden" id="hidden_user_id" value="all">
                        <div class="relative bg-white/5 border border-white/10 rounded-2xl overflow-hidden flex items-center h-[52px] hover:border-white/20 transition-all cursor-pointer custom-select-trigger" onclick="toggleCustomDropdown(this, event)">
                            <span class="material-symbols-outlined absolute left-4 text-primary/60 text-base pointer-events-none transition-transform group-focus-within:scale-110">person_search</span>
                            <input type="text" id="userSearchDisplay" placeholder="Search Name..." value="All Users" oninput="filterCustomSelect(this)" onclick="event.stopPropagation(); const d = this.closest('.custom-select-container').querySelector('.custom-select-dropdown'); document.querySelectorAll('.custom-select-dropdown').forEach(x => { if(x !== d) x.classList.add('hidden'); }); d.classList.remove('hidden');" class="w-full bg-transparent border-none text-white text-[10px] font-black uppercase tracking-widest cursor-text pl-11 pr-10 focus:outline-none focus:ring-0 h-full outline-none shadow-none">
                            <span class="material-symbols-outlined absolute right-4 text-white/40 text-base pointer-events-none transition-transform group-hover:scale-110">expand_more</span>
                        </div>
                        <div id="userDropdown" class="absolute left-0 right-0 top-full mt-2 z-[100] rounded-xl bg-[#141216] shadow-2xl border border-white/10 p-1.5 space-y-0.5 custom-select-dropdown hidden max-h-[220px] overflow-y-auto no-scrollbar searchable-dropdown-overlay">
                            <div class="px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-colors custom-option bg-primary text-white" data-value="all">All Users</div>
                            <?php foreach($members as $m): ?>
                                <div class="px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-colors custom-option text-white/60" data-value="<?= htmlspecialchars(trim($m['first_name'] . ' ' . $m['last_name'])) ?>">
                                    <?= htmlspecialchars(trim($m['first_name'] . ' ' . $m['last_name'])) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Status Filter -->
                    <div class="w-[190px] relative group shrink-0 custom-select-container">
                        <input type="hidden" id="globalStatusFilter" value="all">
                        <div class="relative bg-white/5 border border-white/10 rounded-2xl overflow-hidden flex items-center h-[52px] hover:border-white/20 transition-all cursor-pointer custom-select-trigger" onclick="toggleCustomDropdown(this, event)">
                            <span class="material-symbols-outlined absolute left-4 text-primary/60 text-base pointer-events-none transition-transform group-focus-within:scale-110">toggle_on</span>
                            <input type="text" id="statusDisplay" readonly value="All Status" class="w-full bg-transparent border-none text-white text-[10px] font-black uppercase tracking-widest cursor-pointer pl-11 pr-10 focus:outline-none focus:ring-0 h-full outline-none shadow-none pointer-events-none">
                            <span class="material-symbols-outlined absolute right-4 text-white/40 text-base pointer-events-none transition-transform group-hover:scale-110">expand_more</span>
                        </div>
                        <div class="absolute left-0 right-0 top-full mt-2 z-[100] rounded-xl bg-[#141216] shadow-2xl border border-white/10 p-1.5 space-y-0.5 custom-select-dropdown hidden max-h-64 overflow-y-auto no-scrollbar">
                            <div class="px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-colors custom-option bg-primary text-white" data-value="all">All Status</div>
                            <div class="px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-colors custom-option text-white/60" data-value="In Gym">In Gym</div>
                            <div class="px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-colors custom-option text-white/60" data-value="Not In Gym">Not In Gym</div>
                            <div class="px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-colors custom-option text-white/60" data-value="Expired">Expired</div>
                        </div>
                    </div>

                    <!-- Sort Filter -->
                    <div class="w-[180px] relative group shrink-0 custom-select-container">
                        <input type="hidden" id="globalSortFilter" value="recent">
                        <div class="relative bg-white/5 border border-white/10 rounded-2xl overflow-hidden flex items-center h-[52px] hover:border-white/20 transition-all cursor-pointer custom-select-trigger" onclick="toggleCustomDropdown(this, event)">
                            <span class="material-symbols-outlined absolute left-4 text-primary/60 text-base pointer-events-none transition-transform group-focus-within:scale-110">sort</span>
                            <input type="text" id="sortDisplay" readonly value="Newest" class="w-full bg-transparent border-none text-white text-[10px] font-black uppercase tracking-widest cursor-pointer pl-11 pr-10 focus:outline-none focus:ring-0 h-full outline-none shadow-none pointer-events-none">
                            <span class="material-symbols-outlined absolute right-4 text-white/40 text-base pointer-events-none transition-transform group-hover:scale-110">expand_more</span>
                        </div>
                        <div class="absolute left-0 right-0 top-full mt-2 z-[100] rounded-xl bg-[#141216] shadow-2xl border border-white/10 p-1.5 space-y-0.5 custom-select-dropdown hidden max-h-64 overflow-y-auto no-scrollbar">
                            <div class="px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-colors custom-option bg-primary text-white" data-value="recent">Newest</div>
                            <div class="px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-colors custom-option text-white/60" data-value="oldest">Oldest</div>
                            <div class="px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-colors custom-option text-white/60" data-value="name_asc">Name A-Z</div>
                            <div class="px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-colors custom-option text-white/60" data-value="name_desc">Name Z-A</div>
                        </div>
                    </div>

                    <!-- Reset Button -->
                    <button type="button" onclick="resetUnifiedFilters();" class="size-[52px] rounded-2xl bg-white/5 border border-white/5 flex items-center justify-center text-gray-500 hover:text-white hover:bg-white/10 transition-all group active:scale-95 shrink-0" title="Reset Filters">
                        <span class="material-symbols-outlined text-lg group-hover:rotate-180 transition-transform duration-500">restart_alt</span>
                    </button>

                    <div class="h-8 w-px bg-white/10 mx-1 hidden lg:block"></div>
                    <div class="flex bg-[#141216] border border-white/10 p-1 rounded-xl h-[44px] shrink-0 items-center gap-1 w-full lg:w-auto">
                        <button type="button" id="gridViewBtn" onclick="toggleView('grid')" class="flex-1 lg:size-9 rounded-lg bg-primary text-white flex items-center justify-center transition-all h-full shadow-none" title="Grid View">
                            <span class="material-symbols-outlined text-sm">grid_view</span>
                        </button>
                        <button type="button" id="tableViewBtn" onclick="toggleView('list')" class="flex-1 lg:size-9 rounded-lg text-white/40 hover:bg-white/5 hover:text-white flex items-center justify-center transition-all h-full shadow-none" title="Table View">
                            <span class="material-symbols-outlined text-sm">table_rows</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Member Grid Container -->
            <div id="memberGridContainer" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-8">
                <?php if(count($members) > 0): foreach($members as $index => $m): ?>
                <div class="member-grid-card glass-card p-8 flex flex-col gap-6 relative group transition-all duration-300">
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-6">
                            <div class="size-16 rounded-full flex items-center justify-center font-black text-2xl border border-white/10 shrink-0 overflow-hidden shadow-inner relative" style="background:rgba(var(--primary-rgb),0.1); color:var(--primary);">
                                <?php if(!empty($m['profile_picture'])): 
                                    $pfp = $m['profile_picture'];
                                    $display_pic = (strpos($pfp, 'data:') === 0 || strpos($pfp, 'http') === 0 || strpos($pfp, '../') === 0) ? $pfp : '../' . $pfp;
                                ?>
                                    <img src="<?= htmlspecialchars($display_pic) ?>" alt="DP" class="w-full h-full object-cover">
                                <?php else: ?>
                                    <?= strtoupper(substr($m['first_name'] ?? 'M',0,1)) ?>
                                <?php endif; ?>
                            </div>
                            <div>
                                <h3 class="member-name font-black capitalize tracking-tight text-xl leading-tight group-hover:text-[--primary] transition-colors" style="color:var(--text-main)"><?= htmlspecialchars(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')) ?></h3>
                                <div class="flex items-center gap-2 mt-1">
                                    <p class="text-[11px] font-black uppercase tracking-[0.2em]" style="color:var(--highlight)"><?= $m['session_count'] ?> Sessions</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-5 py-6 border-y border-white/5">
                        <div class="flex justify-between items-center">
                            <p class="text-[11px] font-black uppercase tracking-widest text-[--text-main] opacity-40">Status</p>
                            <?php 
                                if ($m['is_in_gym'] > 0) {
                                    $ws = 'In Gym';
                                    $w_color_class = 'text-green-500';
                                } elseif ($m['is_expired'] > 0 || $m['member_status'] === 'Expired') {
                                    $ws = 'Expired';
                                    $w_color_class = 'text-red-500';
                                } else {
                                    $ws = 'Not In Gym';
                                    $w_color_class = 'text-gray-400';
                                }
                            ?>
                            <p class="member-status-badge text-[12px] font-bold capitalize <?= $w_color_class ?>"><?= $ws ?></p>
                        </div>
                        <div class="flex justify-between items-center">
                            <p class="text-[11px] font-black uppercase tracking-widest text-[--text-main] opacity-40">Fitness Goal</p>
                            <p class="text-[12px] font-bold truncate ml-4 capitalize" style="color:var(--primary)"><?= htmlspecialchars(($m['workout_plan'] ?: 'General Fitness')) ?></p>
                        </div>
                        <div class="flex justify-between items-center">
                            <p class="text-[11px] font-black uppercase tracking-widest text-[--text-main] opacity-40">Last Visit</p>
                            <p class="text-[12px] font-bold text-[--text-main] opacity-70 capitalize"><?= $m['last_visit'] ? date('M d, Y', strtotime($m['last_visit'])) : 'No visits yet' ?></p>
                        </div>
                    </div>

                    <div class="flex items-center gap-4 mt-auto pt-2">
                        <button onclick="viewUserProfile(<?= $m['user_id'] ?>)" class="w-full h-14 rounded-2xl bg-white/5 border border-transparent flex items-center justify-center hover:bg-white/10 transition-all group/btn shadow-none">
                            <span class="material-symbols-rounded text-[--text-main] opacity-50 group-hover/btn:text-primary group-hover/btn:scale-110 transition-all mr-2">visibility</span>
                            <span class="text-[11px] font-black uppercase tracking-widest text-[--text-main] opacity-80 group-hover/btn:text-primary">View Profile</span>
                        </button>
                    </div>
                </div>
                <?php endforeach; endif; ?>
                <div class="no-results-card col-span-full py-12 text-center text-[11px] font-black uppercase tracking-[0.3em] text-[--text-main] opacity-20 no-results-text <?= empty($members) ? '' : 'hidden' ?>">
                    No matching members found.
                </div>
            </div>
            
            <div id="memberGridPagination"></div>

            <!-- Member Table Container -->
            <div id="memberTableContainer" class="hidden glass-card overflow-hidden flex flex-col">
                <div id="tableFilterSlot" class="p-8 border-b border-white/5 bg-white/[0.01]"></div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-white/5 border-b border-white/5 text-[10px] font-black uppercase tracking-[0.25em]">
                                <th class="px-8 py-5 opacity-40">Name</th>
                                <th class="px-8 py-5 opacity-40">Fitness Goal</th>
                                <th class="px-8 py-5 opacity-40 text-center">Total Sessions</th>
                                <th class="px-8 py-5 opacity-40 text-center">Last Visit</th>
                                <th class="px-8 py-5 opacity-40 text-center">Status</th>
                                <th class="px-8 py-5 opacity-40 text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <?php if(!empty($members)): ?>
                            <?php foreach($members as $m): ?>
                            <tr class="member-table-row hover:bg-white/[0.03] transition-all group/row animate-fade-in">
                                <td class="px-8 py-5">
                                    <div class="flex items-center gap-4">
                                        <div class="size-11 rounded-full flex items-center justify-center font-black text-lg border border-white/10 shrink-0 overflow-hidden shadow-inner relative" style="background:rgba(var(--primary-rgb),0.1); color:var(--primary);">
                                            <?php if(!empty($m['profile_picture'])): 
                                                $pfp = $m['profile_picture'];
                                                $display_pic = (strpos($pfp, 'data:') === 0 || strpos($pfp, 'http') === 0 || strpos($pfp, '../') === 0) ? $pfp : '../' . $pfp;
                                            ?>
                                                <img src="<?= htmlspecialchars($display_pic) ?>" alt="DP" class="w-full h-full object-cover">
                                            <?php else: ?>
                                                <?= strtoupper(substr($m['first_name'] ?? 'M',0,1)) ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="flex flex-col">
                                            <span class="member-name text-[12px] font-black capitalize text-[--text-main] opacity-70"><?= htmlspecialchars(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')) ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-8 py-5">
                                    <span class="text-[12px] font-black capitalize" style="color:var(--primary)"><?= htmlspecialchars(($m['workout_plan'] ?: 'General Fitness')) ?></span>
                                </td>
                                <td class="px-8 py-5 text-center">
                                    <span class="text-[12px] font-black text-white/70"><?= $m['session_count'] ?></span>
                                </td>
                                <td class="px-8 py-5 text-center">
                                    <span class="text-[11px] font-black text-[--text-main] opacity-70 capitalize"><?= $m['last_visit'] ? date('M d, Y', strtotime($m['last_visit'])) : 'No visits' ?></span>
                                </td>
                                <td class="px-8 py-5 text-center">
                                    <?php
                                        if ($m['is_in_gym'] > 0) {
                                            $ws = 'In Gym';
                                            $badge_class = 'text-green-500 bg-green-500/10';
                                        } elseif ($m['is_expired'] > 0 || $m['member_status'] === 'Expired') {
                                            $ws = 'Expired';
                                            $badge_class = 'text-red-500 bg-red-500/10';
                                        } else {
                                            $ws = 'Not In Gym';
                                            $badge_class = 'text-gray-400 bg-white/5';
                                        }
                                    ?>
                                    <span class="member-status-badge px-3 py-1.5 rounded-lg text-[10px] font-black tracking-widest capitalize <?= $badge_class ?>"><?= $ws ?></span>
                                </td>
                                <td class="px-8 py-5">
                                    <div class="flex items-center justify-center gap-2">
                                        <button onclick="viewUserProfile(<?= $m['user_id'] ?>)" class="size-9 rounded-xl bg-white/5 border border-white/10 text-white hover:bg-primary hover:border-transparent transition-all active:scale-95 flex items-center justify-center group/btn" title="View Profile">
                                            <span class="material-symbols-outlined text-lg group-hover/btn:scale-110 transition-transform">visibility</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                            <tr class="no-results-row <?= empty($members) ? '' : 'hidden' ?> animate-in fade-in duration-500">
                                <td colspan="6" class="px-8 py-24 text-center text-[11px] font-black uppercase tracking-[0.3em] text-white/20 no-results-text">No matching members found.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <!-- Table Pagination -->
                <div id="memberTablePagination"></div>
            </div>
        </div>
    </div>

    <!-- Universal Profile Modal -->
    <div id="userModal" class="hidden">
        <div id="modalBackdrop" class="absolute inset-0 transition-opacity duration-300 opacity-0 bg-[--background]/40 backdrop-blur-xl pointer-events-auto" onclick="closeUserModal()"></div>
        <div id="modalInner" class="relative z-10 bg-[--card-bg] backdrop-blur-[--card-blur] w-full max-w-[540px] rounded-[32px] shadow-2xl border border-white/5 overflow-hidden transform transition-all duration-300 scale-90 opacity-0 pointer-events-auto">
            <div id="modalContent" class="no-scrollbar max-h-[80vh] overflow-y-auto p-8"></div>
        </div>
    </div>

</body>
</html>