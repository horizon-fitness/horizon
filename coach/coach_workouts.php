<?php
session_start();
require_once '../db.php';

// Security Check
$role = strtolower($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || $role !== 'coach') {
    header("Location: ../login.php");
    exit;
}

$coach_user_id = $_SESSION['user_id'];
$gym_id = $_SESSION['gym_id'];
$username = $_SESSION['username'];
$coach_name = ($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '');
$member_id = $_GET['member_id'] ?? 0;
$student_id = $_GET['student_id'] ?? 0;

// Fetch Gym Details
$stmtGym = $pdo->prepare("SELECT * FROM gyms WHERE gym_id = ?");
$stmtGym->execute([$gym_id]);
$gym = $stmtGym->fetch();

// Fetch Gym & Owner Details for Branding
$stmtGymBranding = $pdo->prepare("SELECT owner_user_id, gym_name FROM gyms WHERE gym_id = ?");
$stmtGymBranding->execute([$gym_id]);
$gym_data = $stmtGymBranding->fetch();
$owner_user_id = $gym_data['owner_user_id'] ?? 0;

// ── Full 4-Color Elite Branding System ───────────────────────────────────────
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

$configs = [
    'system_name'     => 'Horizon Systems',
    'system_logo'     => '',
    'theme_color'     => '#8c2bee',
    'secondary_color' => '#a1a1aa',
    'text_color'      => '#ffffff',
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

// 2. Merge tenant-specific settings (owner)
$stmtTenant = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = ?");
$stmtTenant->execute([$owner_user_id]);
foreach (($stmtTenant->fetchAll(PDO::FETCH_KEY_PAIR) ?: []) as $k => $v) {
    if ($v !== null && $v !== '') $configs[$k] = $v;
}

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
    'system_name' => $configs['system_name'] ?? 'Horizon Systems',
];
// ─────────────────────────────────────────────────────────────────────────────

// Fetch Coach ID (from staff table)
$stmtCoach = $pdo->prepare("SELECT staff_id as coach_id FROM staff WHERE user_id = ? AND gym_id = ? AND staff_role = 'Coach' LIMIT 1");
$stmtCoach->execute([$coach_user_id, $gym_id]);
$coach = $stmtCoach->fetch();
$coach_id = $coach ? $coach['coach_id'] : 0;

$pending_count = 0;
if ($coach_id > 0) {
    $stmtPending = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE coach_id = ? AND booking_status = 'Pending'");
    $stmtPending->execute([$coach_id]);
    $pending_count = $stmtPending->fetchColumn();
}

// Handle Workout Assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_workout'])) {
    $m_id = $_POST['m_id'];
    $workout_name = trim($_POST['workout_name']);
    $category = $_POST['workout_category'] ?? 'General';
    $difficulty = $_POST['difficulty_level'] ?? 'Intermediate';
    $duration = (int)($_POST['duration_weeks'] ?? 1);
    $est_time = (int)($_POST['estimated_minutes'] ?? 60);
    $workout_desc = trim($_POST['workout_description']);
    $scheduled_date = $_POST['scheduled_date'];
    
    try {
        $stmtAdd = $pdo->prepare("INSERT INTO member_workouts (member_id, coach_id, gym_id, workout_name, workout_category, difficulty_level, duration_weeks, estimated_minutes, workout_description, workout_status, scheduled_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Assigned', ?, NOW(), NOW())");
        $stmtAdd->execute([$m_id, $coach_id, $gym_id, $workout_name, $category, $difficulty, $duration, $est_time, $workout_desc, $scheduled_date]);
        $_SESSION['success_msg'] = "Workout program assigned successfully!";
        header("Location: coach_workouts.php?member_id=" . $m_id);
        exit;
    } catch (Exception $e) { $_SESSION['error_msg'] = "Error: " . $e->getMessage(); }
}

// Handle Status Update
if (isset($_GET['action']) && $_GET['action'] == 'update_status' && isset($_GET['workout_id'])) {
    $w_id = $_GET['workout_id'];
    $new_status = $_GET['status'];
    $stmtUpdate = $pdo->prepare("UPDATE member_workouts SET workout_status = ?, updated_at = NOW() WHERE workout_id = ? AND coach_id = ?");
    $stmtUpdate->execute([$new_status, $w_id, $coach_id]);
    header("Location: coach_workouts.php?member_id=" . $member_id);
    exit;
}



// Fetch Member Details if student_id is set
$selected_member = null;
if ($student_id > 0) {
    $stmtMem = $pdo->prepare("SELECT m.*, u.first_name, u.last_name, u.email FROM members m JOIN users u ON m.user_id = u.user_id WHERE m.member_id = ? AND m.gym_id = ?");
    $stmtMem->execute([$student_id, $gym_id]);
    $selected_member = $stmtMem->fetch();
}

// Fetch All Members in Gym for Selection
$stmtAllMembers = $pdo->prepare("
    SELECT m.member_id, u.first_name, u.last_name 
    FROM members m 
    JOIN users u ON m.user_id = u.user_id 
    WHERE m.gym_id = ? 
    ORDER BY u.first_name ASC
");
$stmtAllMembers->execute([$gym_id]);
$all_members = $stmtAllMembers->fetchAll();

// --- FILTERING LOGIC ---
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$sort_by = $_GET['sort'] ?? 'recent';

$where_clauses = ["w.coach_id = ?", "w.gym_id = ?"];
$params = [$coach_id, $gym_id];

if ($member_id > 0) {
    $where_clauses[] = "w.member_id = ?";
    $params[] = $member_id;
}

if (!empty($search)) {
    $where_clauses[] = "(w.workout_name LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}

if (!empty($status_filter)) {
    $where_clauses[] = "w.workout_status = ?";
    $params[] = $status_filter;
}

$order_sql = "ORDER BY w.scheduled_date DESC, w.created_at DESC";
if ($sort_by === 'oldest') $order_sql = "ORDER BY w.scheduled_date ASC, w.created_at ASC";
if ($sort_by === 'name_asc') $order_sql = "ORDER BY u.first_name ASC";
if ($sort_by === 'name_desc') $order_sql = "ORDER BY u.first_name DESC";

$sqlWorkouts = "
    SELECT w.*, u.first_name, u.last_name 
    FROM member_workouts w
    JOIN members m ON w.member_id = m.member_id
    JOIN users u ON m.user_id = u.user_id
    WHERE " . implode(" AND ", $where_clauses) . "
    $order_sql
";

$workouts = [];
if ($coach_id > 0) {
    $stmtW = $pdo->prepare($sqlWorkouts);
    $stmtW->execute($params);
    $workouts = $stmtW->fetchAll();
}

$recent_workouts = [];
if ($coach_id > 0) {
    $recent_where = ["w.coach_id = ?", "w.gym_id = ?", "w.workout_status = 'Assigned'"];
    $recent_params = [$coach_id, $gym_id];
    
    $stmtRecent = $pdo->prepare("
        SELECT w.*, u.first_name, u.last_name 
        FROM member_workouts w
        JOIN members m ON w.member_id = m.member_id
        JOIN users u ON m.user_id = u.user_id
        WHERE " . implode(" AND ", $recent_where) . "
        ORDER BY w.created_at DESC
        LIMIT 5
    ");
    $stmtRecent->execute($recent_params);
    $recent_workouts = $stmtRecent->fetchAll();
}

$student_recent_workouts = [];
if ($coach_id > 0 && $student_id > 0) {
    $student_recent_where = ["w.coach_id = ?", "w.gym_id = ?", "w.workout_status = 'Assigned'", "w.member_id = ?"];
    $student_recent_params = [$coach_id, $gym_id, $student_id];
    
    $stmtStudentRecent = $pdo->prepare("
        SELECT w.*, u.first_name, u.last_name 
        FROM member_workouts w
        JOIN members m ON w.member_id = m.member_id
        JOIN users u ON m.user_id = u.user_id
        WHERE " . implode(" AND ", $student_recent_where) . "
        ORDER BY w.created_at DESC
        LIMIT 5
    ");
    $stmtStudentRecent->execute($student_recent_params);
    $student_recent_workouts = $stmtStudentRecent->fetchAll();
}

$success_msg = $_SESSION['success_msg'] ?? '';
$error_msg = $_SESSION['error_msg'] ?? '';
unset($_SESSION['success_msg'], $_SESSION['error_msg']);

$active_page = "workouts";
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Workouts Management | Horizon Systems</title>
    <link href="https://fonts.googleapis.com/css2?family=<?= urlencode($font_family) ?>:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
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

        body { font-family: '<?= $font_family ?>', sans-serif; background-color: var(--background); color: var(--text-main); display: flex; flex-direction: row; min-h-screen: 100vh; overflow: hidden; }
        .glass-card { background: var(--card-bg); border: 1px solid rgba(255,255,255,0.07); border-radius: 24px; }
        
        .side-nav { width: 110px; transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1); overflow: hidden; display: flex; flex-direction: column; position: fixed; left: 0; top: 0; height: 100vh; z-index: 110; background: var(--background); border-right: 1px solid rgba(255,255,255,0.05); }
        .side-nav:hover { width: 300px; }
        .main-content { margin-left: 110px; flex: 1; min-width: 0; transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1); height: 100vh; overflow-y: auto; }
        .side-nav:hover ~ .main-content { margin-left: 300px; }

        .nav-label { opacity: 0; transform: translateX(-15px); transition: all 0.3s ease-in-out; white-space: nowrap; pointer-events: none; }
        .side-nav:hover .nav-label { opacity: 1; transform: translateX(0); pointer-events: auto; }
        .nav-section-label { max-height: 0; opacity: 0; overflow: hidden; transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); margin: 0 !important; pointer-events: none; }
        .side-nav:hover .nav-section-label { max-height: 20px; opacity: 1; margin-bottom: 8px !important; pointer-events: auto; }
        
        .nav-item { display: flex; align-items: center; gap: 16px; padding: 12px 38px; transition: all 0.2s ease; text-decoration: none; white-space: nowrap; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; }
        .nav-item:hover { background: rgba(255,255,255,0.05); color: white; }
        
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        .filter-container { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 20px; padding: 20px; margin-bottom: 2rem; position: sticky; top: 0; z-index: 20; }
        .input-box { background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.05); border-radius: 12px; color: white; padding: 10px 16px; font-size: 11px; font-weight: 600; outline: none; transition: all 0.3s; width: 100%; color-scheme: dark; }
        .input-box:focus { border-color: <?= $page['theme_color'] ?? '#8c2bee' ?>; background: rgba(140,43,238,0.05); }
        .input-box option { background: #1a1821 !important; color: white !important; font-size: 12px; padding: 10px; }




        .modal-input { background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.05); border-radius: 12px; padding: 12px 16px; color: white; font-size: 12px; font-weight: 500; transition: all 0.2s; color-scheme: dark; }
        .modal-input:focus { border-color: <?= $page['theme_color'] ?? '#8c2bee' ?>; outline: none; background: rgba(140,43,238,0.05); }

        @keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .animate-slide-up { animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
        


        .view-btn { padding: 10px; border-radius: 10px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.05); color: #475569; transition: all 0.2s; }
        .view-btn.active { background: <?= $page['theme_color'] ?? '#8c2bee' ?>; color: white; box-shadow: 0 4px 20px <?= $page['theme_color'] ?? '#8c2bee' ?>44; border-color: transparent; }

        ::-webkit-calendar-picker-indicator { filter: invert(1) brightness(0.8); opacity: 0.6; cursor: pointer; }

        .tab-btn { border-bottom: 2px solid transparent; }
        .tab-btn.active { border-bottom-color: var(--primary) !important; color: var(--primary) !important; }
        
        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeIn 0.4s ease-out; }

        @keyframes fadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }

        #assignModal {
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            left: 110px;
            z-index: 250;
            align-items: center;
            justify-content: center;
            padding: 20px;
            transition: left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .side-nav:hover ~ .main-content ~ #assignModal,
        .side-nav:hover ~ #assignModal { left: 300px; }
    </style>

    <script>
        function toggleTab(tabId) {
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
                btn.style.color = 'color-mix(in srgb, var(--text-main) 45%, transparent)';
                btn.style.borderBottom = '2px solid transparent';
            });
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            
            const targetBtn = document.querySelector(`[data-tab="${tabId}"]`);
            targetBtn.classList.add('active');
            targetBtn.style.color = 'var(--primary)';
            targetBtn.style.borderBottom = '2px solid var(--primary)';
            
            document.getElementById(tabId).classList.add('active');
        }

        function updateHeaderClock() {
            const now = new Date();
            const clockEl = document.getElementById('headerClock');
            if (clockEl) clockEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }
        setInterval(updateHeaderClock, 1000);
        window.addEventListener('DOMContentLoaded', updateHeaderClock);

        let dbt;
        function debounce(f, d) { clearTimeout(dbt); dbt = setTimeout(f, d); }
        function triggerFilter() { document.getElementById('filterForm').submit(); }

        function toggleCustomDropdown(trigger, event) {
            event.stopPropagation();
            const dropdown = trigger.nextElementSibling;
            document.querySelectorAll('.custom-select-dropdown').forEach(d => {
                if (d !== dropdown) d.classList.add('hidden');
            });
            dropdown.classList.toggle('hidden');
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
                triggerFilter();
            }
        });
    </script>

</head>
<body class="antialiased flex min-h-screen">

<?php include '../includes/coach_sidebar.php'; ?>

<div class="main-content flex flex-col no-scrollbar">
    <main class="flex-1 p-6 md:p-12 max-w-[1400px] w-full mx-auto pb-40">
        <header class="mb-10 flex justify-between items-end">
            <div>
                <h2 class="text-3xl font-black uppercase tracking-tighter italic" style="color:var(--text-main)">
                    Workout <span style="color:var(--primary)" class="italic">Tracker</span>
                </h2>
                <p class="text-[10px] font-bold uppercase tracking-widest mt-1 opacity-50 italic" style="color:var(--text-main)">Workout Programs & Assignments</p>
            </div>
            <div class="text-right">
                <p id="headerClock" class="font-black italic text-2xl leading-none tracking-tighter pr-2" style="color:var(--text-main)">00:00:00 AM</p>
                <p class="text-[10px] font-bold uppercase tracking-widest mt-2 pr-2 opacity-80" style="color:var(--primary)">
                    <?= date('l, M d, Y') ?>
                </p>
            </div>
        </header>


        <!-- Message Alerts -->

        <?php if($success_msg): ?><div class="mb-6 px-6 py-4 rounded-xl bg-emerald-500/10 border border-emerald-500/10 text-emerald-500 text-[10px] font-black uppercase tracking-widest animate-pulse"><?= $success_msg ?></div><?php endif; ?>
        <?php if($error_msg): ?><div class="mb-6 px-6 py-4 rounded-xl bg-rose-500/10 border border-rose-500/10 text-rose-500 text-[10px] font-black uppercase tracking-widest animate-pulse"><?= $error_msg ?></div><?php endif; ?>

        <?php $active_tab = isset($_GET['tab']) ? $_GET['tab'] : (($student_id > 0) ? 'progressTab' : (($member_id > 0 || !empty($search) || !empty($status_filter)) ? 'historyTab' : 'recentTab')); ?>

        <!-- Navigation & Search Section -->
        <div class="flex flex-col md:flex-row md:items-end justify-between gap-6 mb-8 border-b border-white/5">
            <div class="flex gap-8 w-full md:w-auto overflow-visible">
                <button onclick="toggleTab('recentTab')" data-tab="recentTab" class="tab-btn <?= $active_tab === 'recentTab' ? 'active' : '' ?> pb-4 -mb-[1px] text-xs font-black uppercase tracking-widest transition-all whitespace-nowrap outline-none" style="color: <?= $active_tab === 'recentTab' ? 'var(--primary)' : 'color-mix(in srgb, var(--text-main) 45%, transparent)' ?>; border-bottom: 2px solid <?= $active_tab === 'recentTab' ? 'var(--primary)' : 'transparent' ?>;">Recently Assigned</button>
                <button onclick="toggleTab('historyTab')" data-tab="historyTab" class="tab-btn <?= $active_tab === 'historyTab' ? 'active' : '' ?> pb-4 -mb-[1px] text-xs font-black uppercase tracking-widest transition-all whitespace-nowrap outline-none" style="color: <?= $active_tab === 'historyTab' ? 'var(--primary)' : 'color-mix(in srgb, var(--text-main) 45%, transparent)' ?>; border-bottom: 2px solid <?= $active_tab === 'historyTab' ? 'var(--primary)' : 'transparent' ?>;">Program History</button>
                <button onclick="toggleTab('progressTab')" data-tab="progressTab" class="tab-btn <?= $active_tab === 'progressTab' ? 'active' : '' ?> pb-4 -mb-[1px] text-xs font-black uppercase tracking-widest transition-all whitespace-nowrap outline-none" style="color: <?= $active_tab === 'progressTab' ? 'var(--primary)' : 'color-mix(in srgb, var(--text-main) 45%, transparent)' ?>; border-bottom: 2px solid <?= $active_tab === 'progressTab' ? 'var(--primary)' : 'transparent' ?>;">Student Progress</button>
            </div>
            
            <div class="flex items-center gap-4 pb-4">
                <button onclick="document.getElementById('assignModal').style.display = 'flex'" class="bg-primary hover:opacity-90 text-[white] px-6 py-2.5 rounded-xl font-black uppercase text-[10px] tracking-widest transition-all active:scale-[0.98] flex items-center justify-center gap-2 whitespace-nowrap">
                    <span class="material-symbols-outlined text-base">add_circle</span> Assign New Program
                </button>
            </div>
        </div>


        <div id="progressTab" class="tab-content <?= $active_tab === 'progressTab' ? 'active' : '' ?>">
            <section class="mb-12 animate-slide-up">
                <!-- Student Search Filter Bar -->
                <div class="glass-card mb-8 relative z-[60]">
                    <div class="px-8 py-6 flex flex-col md:flex-row items-center gap-4">
                        <!-- Search Input -->
                        <div class="relative flex-1 group min-w-[200px]">
                            <div class="relative bg-white/5 border border-white/10 rounded-2xl overflow-hidden flex items-center h-[52px] hover:border-white/20 transition-all focus-within:border-primary/50">
                                <span class="material-symbols-outlined absolute left-4 text-primary/60 text-base pointer-events-none">person_search</span>
                                <input type="text" id="memberSearchInput" placeholder="Search student name..." class="w-full bg-transparent border-none text-white text-[10px] font-black uppercase tracking-widest placeholder:text-white/40 pl-11 pr-4 focus:outline-none focus:ring-0 h-full outline-none shadow-none" autocomplete="off" oninput="filterStudentMembers(this.value)">
                            </div>
                        </div>
                        <!-- Student Dropdown -->
                        <div class="flex-1 relative group shrink-0 custom-select-container min-w-[200px]">
                            <div class="relative bg-white/5 border border-white/10 rounded-2xl overflow-hidden flex items-center h-[52px] hover:border-white/20 transition-all cursor-pointer custom-select-trigger" onclick="toggleCustomDropdown(this, event)">
                                <span class="material-symbols-outlined absolute left-4 text-primary/60 text-base pointer-events-none">people</span>
                                <?php
                                    $selected_student_name = 'All Students';
                                    if($student_id > 0) {
                                        foreach($all_members as $sm) {
                                            if($sm['member_id'] == $student_id) {
                                                $selected_student_name = $sm['first_name'] . ' ' . $sm['last_name'];
                                            }
                                        }
                                    }
                                ?>
                                <input type="text" readonly value="<?= htmlspecialchars($selected_student_name) ?>" class="w-full bg-transparent border-none text-white text-[10px] font-black uppercase tracking-widest cursor-pointer pl-11 pr-10 focus:outline-none focus:ring-0 h-full outline-none shadow-none pointer-events-none">
                                <span class="material-symbols-outlined absolute right-4 text-white/40 text-base pointer-events-none">expand_more</span>
                            </div>
                            <div id="studentSelectDropdown" class="absolute left-0 right-0 top-full mt-2 z-[100] rounded-xl bg-[#141216] shadow-2xl border border-white/10 p-1.5 space-y-0.5 custom-select-dropdown hidden max-h-64 overflow-y-auto no-scrollbar">
                                <div class="px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-colors custom-student-option <?= $student_id == 0 ? 'bg-primary text-white' : 'text-white/60' ?>" data-value="0" onclick="window.location.href='?tab=progressTab'">All Students</div>
                                <?php foreach($all_members as $sm): ?>
                                    <div class="member-item px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-colors custom-student-option <?= $student_id == $sm['member_id'] ? 'bg-primary text-white' : 'text-white/60' ?>" data-value="<?= $sm['member_id'] ?>" onclick="window.location.href='?student_id=<?= $sm['member_id'] ?>&tab=progressTab'">
                                        <?= htmlspecialchars(trim($sm['first_name'] . ' ' . $sm['last_name'])) ?>
                                    </div>
                                <?php endforeach; ?>
                                <div id="noResults" class="hidden px-6 py-8 text-center text-[10px] text-gray-600 italic font-bold uppercase tracking-widest">No students found...</div>
                            </div>
                        </div>
                        <?php if($student_id > 0): ?>
                        <a href="coach_workouts.php?tab=progressTab" class="size-[52px] rounded-2xl bg-white/5 border border-white/5 flex items-center justify-center text-gray-500 hover:text-white hover:bg-white/10 transition-all group active:scale-95 shrink-0" title="Clear Selection">
                            <span class="material-symbols-outlined text-xl group-hover:rotate-180 transition-transform duration-500">restart_alt</span>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <script>
                    function filterStudentMembers(val) {
                        const items = document.querySelectorAll('.member-item');
                        let found = 0;
                        items.forEach(item => {
                            const text = item.textContent.toLowerCase();
                            if (text.includes(val.toLowerCase())) {
                                item.classList.remove('hidden');
                                found++;
                            } else {
                                item.classList.add('hidden');
                            }
                        });
                        document.getElementById('noResults').classList.toggle('hidden', found > 0);
                    }
                </script>

                <!-- Member Identity Card & Their Progress -->
                <?php if($selected_member): ?>
                <div class="glass-card p-6 shadow-xl relative overflow-hidden group mb-12" style="border-left: 2px solid var(--primary); background: linear-gradient(to right, color-mix(in srgb, var(--primary) 8%, var(--card-bg)), var(--card-bg));">
                    <div class="absolute top-0 right-0 p-8 opacity-[0.03] group-hover:opacity-[0.07] transition-opacity pointer-events-none" style="color:var(--primary)">
                        <span class="material-symbols-outlined text-8xl">fitness_center</span>
                    </div>
                    <div class="flex items-center justify-between relative z-10">
                        <div class="flex items-center gap-6">
                            <div class="size-16 rounded-2xl flex items-center justify-center font-black text-2xl italic shadow-inner" style="background:color-mix(in srgb, var(--primary) 12%, transparent); color:var(--primary); border: 1px solid color-mix(in srgb, var(--primary) 20%, transparent);">
                                <?= strtoupper(substr($selected_member['first_name'], 0, 1)) ?>
                            </div>
                            <div>
                                <div class="flex items-center gap-3 mb-0.5">
                                    <h3 class="text-xl font-black italic uppercase text-white tracking-tighter"><?= htmlspecialchars($selected_member['first_name'] . ' ' . $selected_member['last_name']) ?></h3>
                                    <span class="px-2 py-0.5 rounded-md text-[7px] font-black uppercase tracking-widest border" style="color:var(--primary); background:color-mix(in srgb, var(--primary) 10%, transparent); border-color:color-mix(in srgb, var(--primary) 20%, transparent)">Active</span>
                                </div>
                                <div class="flex items-center gap-4">
                                    <p class="text-[9px] text-gray-500 font-bold uppercase tracking-widest">ID: #<?= str_pad($selected_member['member_id'], 5, '0', STR_PAD_LEFT) ?></p>
                                    <span class="w-1 h-1 rounded-full bg-white/10"></span>
                                    <p class="text-[9px] text-gray-500 font-bold uppercase tracking-widest"><?= htmlspecialchars($selected_member['email']) ?></p>
                                    <span class="w-1 h-1 rounded-full bg-white/10"></span>
                                    <p class="text-[9px] text-gray-500 font-bold uppercase tracking-widest">Since <?= date('M Y', strtotime($selected_member['created_at'] ?? 'now')) ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="glass-card overflow-hidden animate-slide-up flex flex-col relative z-[50]">
                    <!-- Filter Hub Inside Table -->
                    <div class="px-8 py-6 flex flex-col md:flex-row items-center gap-4 bg-white/[0.01] border-b border-white/5">
                        <!-- Search Input -->
                        <div class="relative flex-1 group min-w-[150px]">
                            <div class="relative bg-white/5 border border-white/10 rounded-2xl overflow-hidden flex items-center h-[52px] hover:border-white/20 transition-all focus-within:border-primary/50" style="--tw-border-opacity:1">
                                <span class="material-symbols-outlined absolute left-4 text-base pointer-events-none" style="color:color-mix(in srgb, var(--primary) 60%, transparent)">search</span>
                                <input type="text" id="progressSearch" placeholder="Search by plan name..." class="w-full bg-transparent border-none text-white text-[10px] font-black uppercase tracking-widest placeholder:text-white/40 pl-11 pr-4 focus:outline-none focus:ring-0 h-full outline-none shadow-none" oninput="filterProgressTable()" autocomplete="off">
                            </div>
                        </div>

                        <!-- Status Filter -->
                        <div class="w-[190px] relative group shrink-0 custom-select-container">
                            <div class="relative bg-white/5 border border-white/10 rounded-2xl overflow-hidden flex items-center h-[52px] hover:border-white/20 transition-all cursor-pointer custom-select-trigger" onclick="toggleCustomDropdown(this, event)">
                                <span class="material-symbols-outlined absolute left-4 text-base pointer-events-none" style="color:color-mix(in srgb, var(--primary) 60%, transparent)">toggle_on</span>
                                <input type="text" id="progressStatusDisplay" readonly value="All Status" class="w-full bg-transparent border-none text-white text-[10px] font-black uppercase tracking-widest cursor-pointer pl-11 pr-10 focus:outline-none focus:ring-0 h-full outline-none shadow-none pointer-events-none">
                                <span class="material-symbols-outlined absolute right-4 text-white/40 text-base pointer-events-none">expand_more</span>
                            </div>
                            <div class="absolute left-0 right-0 top-full mt-2 z-[100] rounded-xl bg-[#141216] shadow-2xl border border-white/10 p-1.5 space-y-0.5 custom-select-dropdown hidden">
                                <div class="px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-colors text-white/60" onclick="setProgressStatus('', 'All Status', this)">All Status</div>
                                <div class="px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-colors text-white/60" onclick="setProgressStatus('Assigned', 'Assigned', this)">Assigned</div>
                                <div class="px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-colors text-white/60" onclick="setProgressStatus('Completed', 'Completed', this)">Completed</div>
                            </div>
                        </div>

                        <!-- Sort Filter -->
                        <div class="w-[180px] relative group shrink-0 custom-select-container">
                            <div class="relative bg-white/5 border border-white/10 rounded-2xl overflow-hidden flex items-center h-[52px] hover:border-white/20 transition-all cursor-pointer custom-select-trigger" onclick="toggleCustomDropdown(this, event)">
                                <span class="material-symbols-outlined absolute left-4 text-base pointer-events-none" style="color:color-mix(in srgb, var(--primary) 60%, transparent)">sort</span>
                                <input type="text" id="progressSortDisplay" readonly value="Newest" class="w-full bg-transparent border-none text-white text-[10px] font-black uppercase tracking-widest cursor-pointer pl-11 pr-10 focus:outline-none focus:ring-0 h-full outline-none shadow-none pointer-events-none">
                                <span class="material-symbols-outlined absolute right-4 text-white/40 text-base pointer-events-none">expand_more</span>
                            </div>
                            <div class="absolute left-0 right-0 top-full mt-2 z-[100] rounded-xl bg-[#141216] shadow-2xl border border-white/10 p-1.5 space-y-0.5 custom-select-dropdown hidden">
                                <div class="px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-colors text-white/60" onclick="setProgressSort('newest', 'Newest', this)">Newest</div>
                                <div class="px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-colors text-white/60" onclick="setProgressSort('oldest', 'Oldest', this)">Oldest</div>
                                <div class="px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-colors text-white/60" onclick="setProgressSort('name_asc', 'Name A-Z', this)">Name A-Z</div>
                            </div>
                        </div>

                        <!-- Reset -->
                        <button onclick="resetProgressFilters()" class="size-[52px] rounded-2xl bg-white/5 border border-white/5 flex items-center justify-center text-gray-500 hover:text-white hover:bg-white/10 transition-all group active:scale-95 shrink-0" title="Reset Filters">
                            <span class="material-symbols-outlined text-xl group-hover:rotate-180 transition-transform duration-500">restart_alt</span>
                        </button>
                    </div>

                    <!-- Table -->
                    <div class="overflow-x-auto no-scrollbar">
                        <table class="w-full text-left border-collapse" id="progressTable">
                            <thead>
                            <tr class="bg-white/5 border-b border-white/5 text-[10px] font-black uppercase tracking-[0.25em]">
                                <th class="px-8 py-5 opacity-40">Assignment</th>
                                <th class="px-8 py-5 opacity-40">Member</th>
                                <th class="px-8 py-5 opacity-40">Assigned Date</th>
                                <th class="px-8 py-5 opacity-40 text-center">Status</th>
                                <th class="px-8 py-5 opacity-40 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5" id="progressTbody">
                            <?php foreach($student_recent_workouts as $rw):
                                $rw_status = $rw['workout_status'] ?? 'Assigned';
                            ?>
                            <tr class="hover:bg-white/[0.03] transition-all progress-row" data-name="<?= htmlspecialchars(strtolower($rw['workout_name'])) ?>" data-status="<?= htmlspecialchars($rw_status) ?>" data-date="<?= strtotime($rw['created_at']) ?>">
                                <td class="px-8 py-5">
                                    <p class="text-white font-black italic uppercase text-sm leading-tight truncate max-w-[250px]"><?= htmlspecialchars($rw['workout_name']) ?></p>
                                </td>
                                <td class="px-8 py-5">
                                    <p class="text-[--text-main] opacity-80 font-black uppercase tracking-widest text-[11px] italic"><?= htmlspecialchars($rw['first_name'] . ' ' . $rw['last_name']) ?></p>
                                </td>
                                <td class="px-8 py-5">
                                    <p class="text-[--text-main] opacity-40 font-black uppercase tracking-widest text-[10px] italic"><?= date('M d, Y', strtotime($rw['created_at'])) ?></p>
                                </td>
                                <td class="px-8 py-5 text-center">
                                    <span class="px-3 py-1.5 rounded-lg text-[9px] font-black uppercase tracking-widest border" style="color:var(--primary); background:color-mix(in srgb, var(--primary) <?= $rw_status === 'Completed' ? '6' : '10' ?>%, transparent); border-color:color-mix(in srgb, var(--primary) 25%, transparent); <?= $rw_status === 'Completed' ? 'opacity:0.7;' : '' ?>"><?= $rw_status ?></span>
                                </td>
                                <td class="px-8 py-5 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <?php if($rw_status != 'Completed'): ?>
                                        <a href="?student_id=<?= $student_id ?>&action=update_status&workout_id=<?= $rw['workout_id'] ?>&status=Completed&tab=progressTab" class="size-10 rounded-xl flex items-center justify-center transition-all active:scale-90 group border" style="color:var(--primary); background:color-mix(in srgb, var(--primary) 10%, transparent); border-color:color-mix(in srgb, var(--primary) 20%, transparent);" title="Complete Program" onmouseover="this.style.background='var(--primary)'; this.style.color='white';" onmouseout="this.style.background='color-mix(in srgb, var(--primary) 10%, transparent)'; this.style.color='var(--primary)';"><span class="material-symbols-outlined text-lg group-hover:scale-110 transition-transform">check_circle</span></a>
                                        <?php endif; ?>
                                        <?php if($rw_status != 'Assigned'): ?>
                                        <a href="?student_id=<?= $student_id ?>&action=update_status&workout_id=<?= $rw['workout_id'] ?>&status=Assigned&tab=progressTab" class="size-10 rounded-xl flex items-center justify-center transition-all active:scale-90 group border" style="color:var(--primary); background:color-mix(in srgb, var(--primary) 8%, transparent); border-color:color-mix(in srgb, var(--primary) 20%, transparent);" title="Re-assign" onmouseover="this.style.background='var(--primary)'; this.style.color='white';" onmouseout="this.style.background='color-mix(in srgb, var(--primary) 8%, transparent)'; this.style.color='var(--primary)';"><span class="material-symbols-outlined text-lg group-hover:scale-110 transition-transform">refresh</span></a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        </table>
                        <div id="progressNoResults" class="hidden p-24 text-center opacity-30 italic text-[11px] font-black uppercase tracking-[0.3em]">No matching programs found.</div>
                    </div>

                    <!-- Pagination Footer -->
                    <div class="px-8 py-6 border-t border-white/5 flex items-center justify-between bg-white/[0.01]">
                        <p id="progressCount" class="text-[9px] font-black uppercase tracking-[0.2em] text-white/30">SHOWING <?= count($student_recent_workouts) ?> ENTRIES</p>
                        <div class="flex items-center gap-4">
                            <button class="text-[9px] font-black uppercase tracking-[0.2em] text-white/30 hover:text-white transition-colors">PREV</button>
                            <button class="size-7 rounded-lg text-white text-[10px] font-black flex items-center justify-center" style="background:var(--primary)">1</button>
                            <button class="text-[9px] font-black uppercase tracking-[0.2em] text-white/30 hover:text-white transition-colors">NEXT</button>
                        </div>
                    </div>
                </div>

                <script>
                    let progressStatusFilter = '';
                    let progressSortMode = 'newest';

                    function filterProgressTable() {
                        const search = document.getElementById('progressSearch').value.toLowerCase();
                        const rows = document.querySelectorAll('.progress-row');
                        let visible = 0;
                        const arr = Array.from(rows);

                        // Sort
                        const tbody = document.getElementById('progressTbody');
                        arr.sort((a, b) => {
                            if (progressSortMode === 'oldest') return parseInt(a.dataset.date) - parseInt(b.dataset.date);
                            if (progressSortMode === 'name_asc') return a.dataset.name.localeCompare(b.dataset.name);
                            return parseInt(b.dataset.date) - parseInt(a.dataset.date); // newest
                        });
                        arr.forEach(r => tbody.appendChild(r));

                        // Filter
                        rows.forEach(row => {
                            const nameMatch = row.dataset.name.includes(search);
                            const statusMatch = !progressStatusFilter || row.dataset.status === progressStatusFilter;
                            const show = nameMatch && statusMatch;
                            row.classList.toggle('hidden', !show);
                            if (show) visible++;
                        });

                        document.getElementById('progressNoResults').classList.toggle('hidden', visible > 0);
                        document.getElementById('progressCount').textContent = 'SHOWING ' + visible + ' OF <?= count($student_recent_workouts) ?> ENTRIES';
                    }

                    function setProgressStatus(val, label, el) {
                        progressStatusFilter = val;
                        document.getElementById('progressStatusDisplay').value = label;
                        document.querySelectorAll('.custom-select-dropdown .px-4').forEach(o => o.classList.remove('bg-primary', 'text-white'));
                        el.classList.add('bg-primary', 'text-white');
                        filterProgressTable();
                    }

                    function setProgressSort(val, label, el) {
                        progressSortMode = val;
                        document.getElementById('progressSortDisplay').value = label;
                        document.querySelectorAll('.custom-select-dropdown .px-4').forEach(o => o.classList.remove('bg-primary', 'text-white'));
                        el.classList.add('bg-primary', 'text-white');
                        filterProgressTable();
                    }

                    function resetProgressFilters() {
                        progressStatusFilter = '';
                        progressSortMode = 'newest';
                        document.getElementById('progressSearch').value = '';
                        document.getElementById('progressStatusDisplay').value = 'All Status';
                        document.getElementById('progressSortDisplay').value = 'Newest';
                        filterProgressTable();
                    }
                </script>

                <?php endif; ?>
            </section>
        </div>



        <div id="recentTab" class="tab-content <?= $active_tab === 'recentTab' ? 'active' : '' ?>">
            <!-- Recently Assigned Section -->
            <?php if(!empty($recent_workouts)): ?>
            <section class="mb-12">

            <div class="glass-card overflow-hidden animate-slide-up flex flex-col relative z-[50]">
                <!-- Filter Hub Inside Table -->
                <div class="px-8 py-6 flex flex-col md:flex-row items-center gap-4 bg-white/[0.01] border-b border-white/5">
                    <!-- Search Input -->
                    <div class="relative flex-1 group min-w-[150px]">
                        <div class="relative bg-white/5 border border-white/10 rounded-2xl overflow-hidden flex items-center h-[52px] hover:border-white/20 transition-all focus-within:border-primary/50" style="--tw-border-opacity:1">
                            <span class="material-symbols-outlined absolute left-4 text-base pointer-events-none" style="color:color-mix(in srgb, var(--primary) 60%, transparent)">search</span>
                            <input type="text" id="recentSearch" placeholder="Search by plan or member name..." class="w-full bg-transparent border-none text-white text-[10px] font-black uppercase tracking-widest placeholder:text-white/40 pl-11 pr-4 focus:outline-none focus:ring-0 h-full outline-none shadow-none" oninput="filterRecentTable()" autocomplete="off">
                        </div>
                    </div>

                    <!-- Sort Filter -->
                    <div class="w-[180px] relative group shrink-0 custom-select-container">
                        <div class="relative bg-white/5 border border-white/10 rounded-2xl overflow-hidden flex items-center h-[52px] hover:border-white/20 transition-all cursor-pointer custom-select-trigger" onclick="toggleCustomDropdown(this, event)">
                            <span class="material-symbols-outlined absolute left-4 text-base pointer-events-none" style="color:color-mix(in srgb, var(--primary) 60%, transparent)">sort</span>
                            <input type="text" id="recentSortDisplay" readonly value="Newest" class="w-full bg-transparent border-none text-white text-[10px] font-black uppercase tracking-widest cursor-pointer pl-11 pr-10 focus:outline-none focus:ring-0 h-full outline-none shadow-none pointer-events-none">
                            <span class="material-symbols-outlined absolute right-4 text-white/40 text-base pointer-events-none">expand_more</span>
                        </div>
                        <div class="absolute left-0 right-0 top-full mt-2 z-[100] rounded-xl bg-[#141216] shadow-2xl border border-white/10 p-1.5 space-y-0.5 custom-select-dropdown hidden">
                            <div class="px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-colors text-white/60" onclick="setRecentSort('newest', 'Newest', this)">Newest</div>
                            <div class="px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-colors text-white/60" onclick="setRecentSort('oldest', 'Oldest', this)">Oldest</div>
                            <div class="px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-colors text-white/60" onclick="setRecentSort('name_asc', 'Name A-Z', this)">Name A-Z</div>
                        </div>
                    </div>

                    <!-- Reset -->
                    <button onclick="resetRecentFilters()" class="size-[52px] rounded-2xl bg-white/5 border border-white/5 flex items-center justify-center text-gray-500 hover:text-white hover:bg-white/10 transition-all group active:scale-95 shrink-0" title="Reset Filters">
                        <span class="material-symbols-outlined text-xl group-hover:rotate-180 transition-transform duration-500">restart_alt</span>
                    </button>
                </div>
                <div class="overflow-x-auto no-scrollbar">
                    <table class="w-full text-left border-collapse" id="recentTable">
                        <thead>
                            <tr class="bg-white/5 border-b border-white/5 text-[10px] font-black uppercase tracking-[0.25em]">
                                <th class="px-8 py-5 opacity-40">Assignment</th>
                                <th class="px-8 py-5 opacity-40">Member</th>
                                <th class="px-8 py-5 opacity-40">Assigned Date</th>
                                <th class="px-8 py-5 opacity-40 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5" id="recentTbody">
                            <?php foreach($recent_workouts as $rw): ?>
                            <tr class="hover:bg-white/[0.03] transition-all recent-row group/row animate-fade-in" data-name="<?= htmlspecialchars(strtolower($rw['workout_name'] . ' ' . $rw['first_name'] . ' ' . $rw['last_name'])) ?>" data-date="<?= strtotime($rw['created_at']) ?>">
                                <td class="px-8 py-5">
                                    <p class="text-white font-black italic uppercase text-sm leading-tight truncate max-w-[250px]"><?= htmlspecialchars($rw['workout_name']) ?></p>
                                </td>
                                <td class="px-8 py-5">
                                    <p class="text-[--text-main] opacity-80 font-black uppercase tracking-widest text-[11px] italic"><?= htmlspecialchars($rw['first_name'] . ' ' . $rw['last_name']) ?></p>
                                </td>
                                <td class="px-8 py-5">
                                    <p class="text-[--text-main] opacity-40 font-black uppercase tracking-widest text-[10px] italic"><?= date('M d, Y', strtotime($rw['created_at'])) ?></p>
                                </td>
                                <td class="px-8 py-5 text-right">
                                    <div class="flex items-center justify-end">
                                        <a href="?member_id=<?= $member_id ?>&action=update_status&workout_id=<?= $rw['workout_id'] ?>&status=Completed" class="size-10 rounded-xl flex items-center justify-center transition-all active:scale-90 group border" style="color:var(--primary); background:color-mix(in srgb, var(--primary) 10%, transparent); border-color:color-mix(in srgb, var(--primary) 20%, transparent);" title="Mark as Completed" onmouseover="this.style.background='var(--primary)'; this.style.color='white';" onmouseout="this.style.background='color-mix(in srgb, var(--primary) 10%, transparent)'; this.style.color='var(--primary)';">
                                            <span class="material-symbols-outlined text-lg group-hover:scale-110 transition-transform">check_circle</span>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div id="recentNoResults" class="hidden p-24 text-center opacity-30 italic text-[11px] font-black uppercase tracking-[0.3em]">No matching programs found.</div>
                </div>
                <!-- Pagination Footer -->
                <div class="px-8 py-6 border-t border-white/5 flex items-center justify-between bg-white/[0.01]">
                    <p id="recentCount" class="text-[9px] font-black uppercase tracking-[0.2em] text-white/30">SHOWING <?= count($recent_workouts) ?> ENTRIES</p>
                    <div class="flex items-center gap-4">
                        <button class="text-[9px] font-black uppercase tracking-[0.2em] text-white/30 hover:text-white transition-colors">PREV</button>
                        <button class="size-7 rounded-lg bg-primary text-white text-[10px] font-black flex items-center justify-center">1</button>
                        <button class="text-[9px] font-black uppercase tracking-[0.2em] text-white/30 hover:text-white transition-colors">NEXT</button>
                    </div>
                </div>
                <script>
                    let recentSortMode = 'newest';

                    function filterRecentTable() {
                        const search = document.getElementById('recentSearch').value.toLowerCase();
                        const rows = document.querySelectorAll('.recent-row');
                        let visible = 0;
                        const arr = Array.from(rows);

                        // Sort
                        const tbody = document.getElementById('recentTbody');
                        arr.sort((a, b) => {
                            if (recentSortMode === 'oldest') return parseInt(a.dataset.date) - parseInt(b.dataset.date);
                            if (recentSortMode === 'name_asc') return a.dataset.name.localeCompare(b.dataset.name);
                            return parseInt(b.dataset.date) - parseInt(a.dataset.date); // newest
                        });
                        arr.forEach(r => tbody.appendChild(r));

                        // Filter
                        rows.forEach(row => {
                            const nameMatch = row.dataset.name.includes(search);
                            row.classList.toggle('hidden', !nameMatch);
                            if (nameMatch) visible++;
                        });

                        const noResults = document.getElementById('recentNoResults');
                        if(noResults) noResults.classList.toggle('hidden', visible > 0);
                        
                        const countEl = document.getElementById('recentCount');
                        if(countEl) countEl.textContent = 'SHOWING ' + visible + ' ENTRIES';
                    }

                    function setRecentSort(val, label, el) {
                        recentSortMode = val;
                        document.getElementById('recentSortDisplay').value = label;
                        document.querySelectorAll('#recentTab .custom-select-dropdown .px-4').forEach(o => o.classList.remove('bg-primary', 'text-white'));
                        el.classList.add('bg-primary', 'text-white');
                        filterRecentTable();
                    }

                    function resetRecentFilters() {
                        recentSortMode = 'newest';
                        document.getElementById('recentSearch').value = '';
                        document.getElementById('recentSortDisplay').value = 'Newest';
                        document.querySelectorAll('#recentTab .custom-select-dropdown .px-4').forEach(o => o.classList.remove('bg-primary', 'text-white'));
                        document.querySelector('#recentTab .custom-select-dropdown .px-4:first-child').classList.add('bg-primary', 'text-white');
                        filterRecentTable();
                    }
                </script>
            </section>
            <?php else: ?><div class="py-20 text-center opacity-40 italic text-[10px] uppercase tracking-widest">No recent assignments found.</div><?php endif; ?>
        </div>

        <div id="historyTab" class="tab-content <?= $active_tab === 'historyTab' ? 'active' : '' ?>">
            <div class="glass-card overflow-hidden animate-slide-up flex flex-col mb-12 relative z-[60]">
                <!-- Filter Hub Inside Table -->
                <div class="px-8 py-6 flex flex-col md:flex-row items-center gap-4 bg-white/[0.01] border-b border-white/5">
                    <form id="filterForm" method="GET" class="w-full flex flex-col md:flex-row items-center gap-4">
                        <input type="hidden" name="tab" value="historyTab">
                        
                        <!-- Search Input -->
                        <div class="relative flex-1 group min-w-[150px]">
                            <div class="relative bg-white/5 border border-white/10 rounded-2xl overflow-hidden flex items-center h-[52px] hover:border-white/20 transition-all focus-within:border-primary/50">
                                <span class="material-symbols-outlined absolute left-4 text-primary/60 text-base pointer-events-none transition-transform group-focus-within:scale-110">search</span>
                                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search by member or plan name..." class="w-full bg-transparent border-none text-white text-[10px] font-black uppercase tracking-widest placeholder:text-white/40 pl-11 pr-4 focus:outline-none focus:ring-0 h-full outline-none shadow-none" oninput="debounce(triggerFilter, 500)" autocomplete="off">
                            </div>
                        </div>

                        <!-- Searchable User Selector -->
                        <div class="flex-1 relative group shrink-0 custom-select-container min-w-[150px]">
                            <input type="hidden" name="member_id" value="<?= $member_id ?>">
                            <div class="relative bg-white/5 border border-white/10 rounded-2xl overflow-hidden flex items-center h-[52px] hover:border-white/20 transition-all cursor-pointer custom-select-trigger" onclick="toggleCustomDropdown(this, event)">
                                <span class="material-symbols-outlined absolute left-4 text-primary/60 text-base pointer-events-none transition-transform group-focus-within:scale-110">person_search</span>
                                <?php 
                                    $selected_user_name = "All Users";
                                    if($member_id > 0) {
                                        foreach($all_members as $m) {
                                            if($m['member_id'] == $member_id) {
                                                $selected_user_name = $m['first_name'] . ' ' . $m['last_name'];
                                            }
                                        }
                                    }
                                ?>
                                <input type="text" readonly placeholder="Search Name..." value="<?= htmlspecialchars($selected_user_name) ?>" class="w-full bg-transparent border-none text-white text-[10px] font-black uppercase tracking-widest cursor-pointer pl-11 pr-10 focus:outline-none focus:ring-0 h-full outline-none shadow-none pointer-events-none">
                                <span class="material-symbols-outlined absolute right-4 text-white/40 text-base pointer-events-none transition-transform group-hover:scale-110">expand_more</span>
                            </div>
                            <div class="absolute left-0 right-0 top-full mt-2 z-[100] rounded-xl bg-[#141216] shadow-2xl border border-white/10 p-1.5 space-y-0.5 custom-select-dropdown hidden max-h-64 overflow-y-auto searchable-dropdown-overlay">
                                <div class="px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-colors custom-option <?= $member_id == 0 ? 'bg-primary text-white' : 'text-white/60' ?>" data-value="0">All Users</div>
                                <?php foreach($all_members as $m): ?>
                                    <div class="px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-colors custom-option <?= $member_id == $m['member_id'] ? 'bg-primary text-white' : 'text-white/60' ?>" data-value="<?= $m['member_id'] ?>">
                                        <?= htmlspecialchars(trim($m['first_name'] . ' ' . $m['last_name'])) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Status Filter -->
                        <div class="w-[190px] relative group shrink-0 custom-select-container">
                            <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>">
                            <div class="relative bg-white/5 border border-white/10 rounded-2xl overflow-hidden flex items-center h-[52px] hover:border-white/20 transition-all cursor-pointer custom-select-trigger" onclick="toggleCustomDropdown(this, event)">
                                <span class="material-symbols-outlined absolute left-4 text-primary/60 text-base pointer-events-none transition-transform group-focus-within:scale-110">toggle_on</span>
                                <input type="text" readonly value="<?= $status_filter ?: 'All Status' ?>" class="w-full bg-transparent border-none text-white text-[10px] font-black uppercase tracking-widest cursor-pointer pl-11 pr-10 focus:outline-none focus:ring-0 h-full outline-none shadow-none pointer-events-none">
                                <span class="material-symbols-outlined absolute right-4 text-white/40 text-base pointer-events-none transition-transform group-hover:scale-110">expand_more</span>
                            </div>
                            <div class="absolute left-0 right-0 top-full mt-2 z-[100] rounded-xl bg-[#141216] shadow-2xl border border-white/10 p-1.5 space-y-0.5 custom-select-dropdown hidden max-h-64 overflow-y-auto">
                                <div class="px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-colors custom-option <?= $status_filter == '' ? 'bg-primary text-white' : 'text-white/60' ?>" data-value="">All Status</div>
                                <div class="px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-colors custom-option <?= $status_filter == 'Assigned' ? 'bg-primary text-white' : 'text-white/60' ?>" data-value="Assigned">Assigned</div>
                                <div class="px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-colors custom-option <?= $status_filter == 'Completed' ? 'bg-primary text-white' : 'text-white/60' ?>" data-value="Completed">Completed</div>
                            </div>
                        </div>

                        <!-- Sort Filter -->
                        <div class="w-[180px] relative group shrink-0 custom-select-container">
                            <input type="hidden" name="sort" value="<?= htmlspecialchars($sort_by) ?>">
                            <div class="relative bg-white/5 border border-white/10 rounded-2xl overflow-hidden flex items-center h-[52px] hover:border-white/20 transition-all cursor-pointer custom-select-trigger" onclick="toggleCustomDropdown(this, event)">
                                <span class="material-symbols-outlined absolute left-4 text-primary/60 text-base pointer-events-none transition-transform group-focus-within:scale-110">sort</span>
                                <?php 
                                    $sort_labels = ['recent' => 'Newest', 'oldest' => 'Oldest', 'name_asc' => 'Member A-Z'];
                                    $current_sort_label = $sort_labels[$sort_by] ?? 'Newest';
                                ?>
                                <input type="text" readonly value="<?= $current_sort_label ?>" class="w-full bg-transparent border-none text-white text-[10px] font-black uppercase tracking-widest cursor-pointer pl-11 pr-10 focus:outline-none focus:ring-0 h-full outline-none shadow-none pointer-events-none">
                                <span class="material-symbols-outlined absolute right-4 text-white/40 text-base pointer-events-none transition-transform group-hover:scale-110">expand_more</span>
                            </div>
                            <div class="absolute left-0 right-0 top-full mt-2 z-[100] rounded-xl bg-[#141216] shadow-2xl border border-white/10 p-1.5 space-y-0.5 custom-select-dropdown hidden max-h-64 overflow-y-auto">
                                <div class="px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-colors custom-option <?= $sort_by == 'recent' ? 'bg-primary text-white' : 'text-white/60' ?>" data-value="recent">Newest</div>
                                <div class="px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-colors custom-option <?= $sort_by == 'oldest' ? 'bg-primary text-white' : 'text-white/60' ?>" data-value="oldest">Oldest</div>
                                <div class="px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-colors custom-option <?= $sort_by == 'name_asc' ? 'bg-primary text-white' : 'text-white/60' ?>" data-value="name_asc">Member A-Z</div>
                            </div>
                        </div>

                        <!-- Reset Button -->
                        <a href="coach_workouts.php?tab=historyTab" class="size-[52px] rounded-2xl bg-white/5 border border-white/5 flex items-center justify-center text-gray-500 hover:text-white hover:bg-white/10 transition-all group active:scale-95 shrink-0" title="Reset Filters">
                            <span class="material-symbols-outlined text-xl group-hover:rotate-180 transition-transform duration-500">restart_alt</span>
                        </a>
                    </form>
                </div>

                <!-- History Table Section -->
                <div class="overflow-x-auto no-scrollbar">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-white/5 border-b border-white/5 text-[10px] font-black uppercase tracking-[0.25em]">
                                <th class="px-8 py-5 opacity-40">Identity & Category</th>
                                <th class="px-8 py-5 opacity-40">Intensity & Time</th>
                                <th class="px-8 py-5 opacity-40 text-center">Status</th>
                                <th class="px-8 py-5 opacity-40 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <?php if(count($workouts) > 0) { 
                                foreach($workouts as $w) { ?>
                            <tr class="hover:bg-white/[0.03] transition-all group/row animate-fade-in">
                                <td class="px-8 py-5">
                                    <p class="text-[--text-main] opacity-40 text-[9px] font-black uppercase tracking-widest mb-1 italic"><?= htmlspecialchars($w['first_name'] . ' ' . $w['last_name']) ?></p>
                                    <p class="text-white font-black italic uppercase text-sm leading-tight mb-1 truncate max-w-[250px]"><?= htmlspecialchars($w['workout_name']) ?></p>
                                    <p class="text-[9px] font-black uppercase tracking-[0.2em]" style="color:var(--primary)"><?= htmlspecialchars($w['workout_category'] ?? 'General') ?></p>
                                </td>
                                <td class="px-8 py-5">
                                    <div class="flex flex-col">
                                        <p class="text-[--text-main] opacity-70 font-bold uppercase italic text-[11px]"><?= htmlspecialchars($w['difficulty_level'] ?? 'Intermediate') ?></p>
                                        <p class="text-[10px] text-[--text-main] opacity-40 font-black uppercase tracking-widest"><?= $w['estimated_minutes'] ?? 60 ?> Mins / <?= $w['duration_weeks'] ?? 4 ?> Wks</p>
                                    </div>
                                </td>
                                <td class="px-8 py-5 text-center">
                                    <?php 
                                        $ws = $w['workout_status']; 
                                        if($ws == 'Completed') {
                                            $badge_class = '';
                                            $badge_style = 'color: var(--primary); background: color-mix(in srgb, var(--primary) 10%, transparent); border-color: color-mix(in srgb, var(--primary) 25%, transparent); opacity: 0.7;';
                                        } else {
                                            $badge_class = '';
                                            $badge_style = 'color: var(--primary); background: color-mix(in srgb, var(--primary) 10%, transparent); border-color: color-mix(in srgb, var(--primary) 25%, transparent);';
                                        }
                                    ?>
                                    <span class="px-3 py-1.5 rounded-lg text-[9px] font-black uppercase tracking-widest border <?= $badge_class ?>" style="<?= $badge_style ?>"><?= $ws ?></span>
                                </td>
                                <td class="px-8 py-5 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <?php if($w['workout_status'] != 'Completed'): ?><a href="?member_id=<?= $member_id ?>&action=update_status&workout_id=<?= $w['workout_id'] ?>&status=Completed" class="size-10 rounded-xl flex items-center justify-center transition-all active:scale-90 group border" style="color:var(--primary); background:color-mix(in srgb, var(--primary) 10%, transparent); border-color:color-mix(in srgb, var(--primary) 20%, transparent);" title="Complete Program" onmouseover="this.style.background='var(--primary)'; this.style.color='white';" onmouseout="this.style.background='color-mix(in srgb, var(--primary) 10%, transparent)'; this.style.color='var(--primary)';"><span class="material-symbols-outlined text-lg group-hover:scale-110 transition-transform">check_circle</span></a><?php endif; ?>
                                        <?php if($w['workout_status'] != 'Assigned'): ?><a href="?member_id=<?= $member_id ?>&action=update_status&workout_id=<?= $w['workout_id'] ?>&status=Assigned" class="size-10 rounded-xl border flex items-center justify-center transition-all active:scale-90 group" style="color:var(--primary); background:color-mix(in srgb, var(--primary) 8%, transparent); border-color:color-mix(in srgb, var(--primary) 20%, transparent);" title="Re-assign Program" onmouseover="this.style.background='var(--primary)'; this.style.color='white';" onmouseout="this.style.background='color-mix(in srgb, var(--primary) 8%, transparent)'; this.style.color='var(--primary)';"><span class="material-symbols-outlined text-lg group-hover:scale-110 transition-transform">refresh</span></a><?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                                <?php } 
                            } else { ?>
                            <tr><td colspan="4" class="p-24 text-center opacity-30 italic text-[11px] font-black uppercase tracking-[0.3em] text-[--text-main]">No matching programs found.</td></tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Footer -->
                <div class="px-8 py-6 border-t border-white/5 flex items-center justify-between bg-white/[0.01]">
                    <p class="text-[9px] font-black uppercase tracking-[0.2em] text-white/30">SHOWING 1 TO <?= count($workouts) ?> OF <?= count($workouts) ?> ENTRIES</p>
                    <div class="flex items-center gap-4">
                        <button class="text-[9px] font-black uppercase tracking-[0.2em] text-white/30 hover:text-white transition-colors">PREV</button>
                        <button class="size-7 rounded-lg bg-primary text-white text-[10px] font-black flex items-center justify-center">1</button>
                        <button class="text-[9px] font-black uppercase tracking-[0.2em] text-white/30 hover:text-white transition-colors">NEXT</button>
                    </div>
                </div>
            </div>
        </div>
    </div>



    </main>
</div>

<!-- Assign Modal -->
<div id="assignModal" class="bg-[--background]/40 backdrop-blur-xl hidden" style="display: none;">
    <div class="relative z-10 bg-[--card-bg] backdrop-blur-[--card-blur] w-full max-w-[540px] rounded-[32px] shadow-2xl border border-white/5 overflow-hidden transform transition-all duration-300 pointer-events-auto animate-slide-up flex flex-col max-h-[90vh]">
        
        <div class="p-8 overflow-y-auto no-scrollbar flex-1">
            <header class="mb-7 relative">
                <div class="flex items-start justify-between mb-1">
                    <h3 class="text-[22px] font-black uppercase tracking-tighter leading-none flex gap-2">
                        <span class="text-white">New</span><span class="text-primary" style="color:var(--primary)">Program</span>
                    </h3>
                    <button onclick="document.getElementById('assignModal').style.display = 'none'" class="size-8 flex items-center justify-center rounded-lg bg-white/5 hover:bg-white/10 text-white/50 hover:text-white transition-colors border border-white/5">
                        <span class="material-symbols-outlined text-[14px]">close</span>
                    </button>
                </div>
                <p class="text-[10px] font-black uppercase tracking-[0.2em] text-white/30 mb-6">ASSIGN PROFESSIONAL TRAINING ROUTINE</p>
            </header>
            
            <form action="" method="POST" class="flex flex-col gap-3">
                <div class="bg-white/[0.03] border border-white/5 rounded-[20px] p-6">
                    <div class="space-y-4">
                        <div>
                            <p class="text-[9px] font-bold uppercase tracking-[0.1em] text-gray-500 mb-1.5">Target Member</p>
                            <div class="relative group">
                                <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-primary/60 text-base pointer-events-none transition-transform group-focus-within:scale-110 z-10">person</span>
                                <select name="m_id" class="w-full bg-white/5 border border-white/10 rounded-2xl text-white text-[10px] font-black uppercase tracking-widest cursor-pointer pl-11 pr-10 py-3.5 focus:outline-none focus:border-primary/50 hover:border-white/20 transition-all appearance-none" required>
                                    <option value="" class="bg-[#1a1821] text-white">--- Choose Member ---</option>
                                    <?php foreach($all_members as $m): ?>
                                        <option value="<?= $m['member_id'] ?>" <?= $member_id == $m['member_id'] ? 'selected' : '' ?> class="bg-[#1a1821] text-white">
                                            <?= htmlspecialchars($m['first_name'] . ' ' . $m['last_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-white/40 text-base pointer-events-none transition-transform group-hover:scale-110">expand_more</span>
                            </div>
                        </div>

                        <div>
                            <p class="text-[9px] font-bold uppercase tracking-[0.1em] text-gray-500 mb-1.5">Category</p>
                            <div class="relative group">
                                <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-primary/60 text-base pointer-events-none transition-transform group-focus-within:scale-110 z-10">category</span>
                                <select name="workout_category" class="w-full bg-white/5 border border-white/10 rounded-2xl text-white text-[10px] font-black uppercase tracking-widest cursor-pointer pl-11 pr-10 py-3.5 focus:outline-none focus:border-primary/50 hover:border-white/20 transition-all appearance-none">
                                    <option value="Strength" class="bg-[#1a1821] text-white">Strength Training</option>
                                    <option value="Cardio" class="bg-[#1a1821] text-white">Cardio & HIIT</option>
                                    <option value="Hypertrophy" class="bg-[#1a1821] text-white">Hypertrophy</option>
                                    <option value="Flexibility" class="bg-[#1a1821] text-white">Flexibility / Yoga</option>
                                    <option value="Endurance" class="bg-[#1a1821] text-white">Endurance</option>
                                </select>
                                <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-white/40 text-base pointer-events-none transition-transform group-hover:scale-110">expand_more</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white/[0.03] border border-white/5 rounded-[20px] p-6">
                    <p class="text-[9px] font-bold uppercase tracking-[0.1em] text-gray-500 mb-1.5">Program Identity</p>
                    <div class="relative group">
                        <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-primary/60 text-base pointer-events-none transition-transform group-focus-within:scale-110 z-10">edit_note</span>
                        <input type="text" name="workout_name" placeholder="e.g. Advanced Push-Pull Protocol" class="w-full bg-white/5 border border-white/10 rounded-2xl text-white text-[10px] font-black uppercase tracking-widest placeholder:text-white/30 pl-11 pr-4 py-3.5 focus:outline-none focus:border-primary/50 hover:border-white/20 transition-all shadow-none outline-none" required autocomplete="off">
                    </div>
                </div>

                <div class="bg-white/[0.03] border border-white/5 rounded-[20px] p-6">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div>
                            <p class="text-[9px] font-bold uppercase tracking-[0.1em] text-gray-500 mb-1.5">Intensity Level</p>
                            <div class="relative group">
                                <select name="difficulty_level" class="w-full bg-white/5 border border-white/10 rounded-2xl text-white text-[10px] font-black uppercase tracking-widest cursor-pointer px-4 py-3.5 focus:outline-none focus:border-primary/50 hover:border-white/20 transition-all appearance-none">
                                    <option value="Beginner" class="bg-[#1a1821] text-white">Beginner</option>
                                    <option value="Intermediate" selected class="bg-[#1a1821] text-white">Intermediate</option>
                                    <option value="Advanced" class="bg-[#1a1821] text-white">Advanced</option>
                                </select>
                                <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-white/40 text-base pointer-events-none transition-transform group-hover:scale-110">expand_more</span>
                            </div>
                        </div>
                        <div>
                            <p class="text-[9px] font-bold uppercase tracking-[0.1em] text-gray-500 mb-1.5">Duration (Wks)</p>
                            <input type="number" name="duration_weeks" value="4" min="1" class="w-full bg-white/5 border border-white/10 rounded-2xl text-white text-[10px] font-black uppercase tracking-widest pl-4 pr-4 py-3.5 focus:outline-none focus:border-primary/50 hover:border-white/20 transition-all shadow-none outline-none">
                        </div>
                        <div>
                            <p class="text-[9px] font-bold uppercase tracking-[0.1em] text-gray-500 mb-1.5">Time (Mins)</p>
                            <input type="number" name="estimated_minutes" value="60" min="15" class="w-full bg-white/5 border border-white/10 rounded-2xl text-white text-[10px] font-black uppercase tracking-widest pl-4 pr-4 py-3.5 focus:outline-none focus:border-primary/50 hover:border-white/20 transition-all shadow-none outline-none">
                        </div>
                    </div>
                </div>

                <div class="bg-white/[0.03] border border-white/5 rounded-[20px] p-6">
                    <p class="text-[9px] font-bold uppercase tracking-[0.1em] text-gray-500 mb-1.5">Instructions & Description</p>
                    <textarea name="workout_description" rows="3" placeholder="Rest times, reps, sets..." class="w-full bg-white/5 border border-white/10 rounded-2xl text-white text-[10px] font-bold placeholder:text-white/30 p-4 focus:outline-none focus:border-primary/50 hover:border-white/20 transition-all shadow-none outline-none resize-none no-scrollbar"></textarea>
                </div>

                <input type="hidden" name="scheduled_date" value="<?= date('Y-m-d') ?>">
                
                <div class="pt-6 flex gap-4">
                    <button type="button" onclick="document.getElementById('assignModal').style.display = 'none'" class="flex-1 h-14 rounded-2xl bg-white/5 text-gray-500 text-[10px] font-black uppercase tracking-[0.2em] hover:bg-white/10 hover:text-white transition-all">Cancel</button>
                    <button type="submit" name="assign_workout" class="flex-[2] h-14 rounded-2xl bg-primary text-white text-[10px] font-black uppercase tracking-[0.2em] hover:bg-primary/90 transition-all active:scale-95 shadow-none hover:shadow-[0_0_20px_rgba(var(--primary-rgb),0.3)]">Complete Assignment</button>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>
