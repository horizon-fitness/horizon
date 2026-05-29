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
    SELECT m.member_id, m.user_id, u.first_name, u.last_name,
           ufp.fitness_goal, ufp.experience_level, ufp.injuries_limitations,
           GROUP_CONCAT(tm.muscle_name SEPARATOR ', ') as target_muscles
    FROM members m 
    JOIN users u ON m.user_id = u.user_id 
    LEFT JOIN user_fitness_profiles ufp ON u.user_id = ufp.user_id
    LEFT JOIN user_target_muscles utm ON u.user_id = utm.user_id
    LEFT JOIN target_muscles tm ON utm.target_muscle_id = tm.target_muscle_id
    WHERE m.gym_id = ? 
    GROUP BY m.member_id, m.user_id, u.first_name, u.last_name, ufp.fitness_goal, ufp.experience_level, ufp.injuries_limitations
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
    $student_recent_where = ["w.coach_id = ?", "w.gym_id = ?", "w.member_id = ?"];
    $student_recent_params = [$coach_id, $gym_id, $student_id];
    
    $stmtStudentRecent = $pdo->prepare("
        SELECT w.*, u.first_name, u.last_name 
        FROM member_workouts w
        JOIN members m ON w.member_id = m.member_id
        JOIN users u ON m.user_id = u.user_id
        WHERE " . implode(" AND ", $student_recent_where) . "
        ORDER BY w.created_at DESC
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

        function renderPaginationControls(containerId, current, totalPages, fnName) {
            const container = document.getElementById(containerId);
            if (!container) return;
            
            const prevEnabled = current > 1;
            const nextEnabled = current < totalPages;

            const prevClass = prevEnabled 
                ? "px-4 py-2 rounded-[10px] flex items-center justify-center text-[10px] font-extrabold uppercase tracking-[0.1em] transition-all bg-white/[0.03] border border-white/5 text-white hover:bg-primary hover:border-primary hover:-translate-y-[1px] hover:shadow-lg cursor-pointer"
                : "px-4 py-2 rounded-[10px] flex items-center justify-center text-[10px] font-extrabold uppercase tracking-[0.1em] transition-all bg-white/[0.02] border border-white/5 text-white/30 opacity-50 cursor-not-allowed";

            const nextClass = nextEnabled 
                ? "px-4 py-2 rounded-[10px] flex items-center justify-center text-[10px] font-extrabold uppercase tracking-[0.1em] transition-all bg-white/[0.03] border border-white/5 text-white hover:bg-primary hover:border-primary hover:-translate-y-[1px] hover:shadow-lg cursor-pointer"
                : "px-4 py-2 rounded-[10px] flex items-center justify-center text-[10px] font-extrabold uppercase tracking-[0.1em] transition-all bg-white/[0.02] border border-white/5 text-white/30 opacity-50 cursor-not-allowed";

            const makeBtn = (page, isActive) => {
                const activeClass = "px-4 py-2 rounded-[10px] bg-primary border border-primary text-white text-[10px] font-extrabold uppercase tracking-[0.1em] flex items-center justify-center cursor-default";
                const inactiveClass = "px-4 py-2 rounded-[10px] bg-white/[0.03] border border-white/5 text-white/30 text-[10px] font-extrabold uppercase tracking-[0.1em] hover:bg-primary hover:text-white hover:border-primary hover:-translate-y-[1px] hover:shadow-lg transition-all flex items-center justify-center cursor-pointer";
                return `<button type="button" onclick="${fnName}(${page - current}, true, ${page})" class="${isActive ? activeClass : inactiveClass}">${page}</button>`;
            };
            
            const makeEllipsis = () => `<span class="text-white/30 text-[10px] font-black mx-1 tracking-[0.2em]">...</span>`;

            let pagesHtml = '';
            if (totalPages <= 5) {
                for (let i = 1; i <= totalPages; i++) pagesHtml += makeBtn(i, i === current);
            } else {
                if (current <= 3) {
                    pagesHtml += makeBtn(1, current === 1);
                    pagesHtml += makeBtn(2, current === 2);
                    pagesHtml += makeBtn(3, current === 3);
                    pagesHtml += makeEllipsis();
                    pagesHtml += makeBtn(totalPages, current === totalPages);
                } else if (current >= totalPages - 2) {
                    pagesHtml += makeBtn(1, current === 1);
                    pagesHtml += makeEllipsis();
                    pagesHtml += makeBtn(totalPages - 2, current === totalPages - 2);
                    pagesHtml += makeBtn(totalPages - 1, current === totalPages - 1);
                    pagesHtml += makeBtn(totalPages, current === totalPages);
                } else {
                    pagesHtml += makeBtn(1, current === 1);
                    pagesHtml += makeEllipsis();
                    pagesHtml += makeBtn(current - 1, false);
                    pagesHtml += makeBtn(current, true);
                    pagesHtml += makeBtn(current + 1, false);
                    pagesHtml += makeEllipsis();
                    pagesHtml += makeBtn(totalPages, current === totalPages);
                }
            }

            container.innerHTML = `
                <button type="button" onclick="${fnName}(-1)" ${!prevEnabled ? 'disabled' : ''} class="${prevClass}">PREV</button>
                <div class="flex items-center gap-1">${pagesHtml}</div>
                <button type="button" onclick="${fnName}(1)" ${!nextEnabled ? 'disabled' : ''} class="${nextClass}">NEXT</button>
            `;
        }

        let dbt;
        function debounce(f, d) { clearTimeout(dbt); dbt = setTimeout(f, d); }
        function triggerFilter() { document.getElementById('filterForm').submit(); }

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
                
                if (hiddenInput && hiddenInput.id === 'assign_m_id') {
                    if (option.dataset.cat) {
                        const catHidden = document.getElementById('assign_w_category');
                        const catDisplay = document.getElementById('assign_w_category_display');
                        const catContainer = catHidden.closest('.custom-select-container');
                        
                        catHidden.value = option.dataset.cat;
                        const targetCatOpt = Array.from(catContainer.querySelectorAll('.custom-option')).find(o => o.dataset.value === option.dataset.cat);
                        if (targetCatOpt) {
                            catDisplay.value = targetCatOpt.textContent.trim();
                            catContainer.querySelectorAll('.custom-option').forEach(opt => {
                                opt.classList.remove('bg-primary', 'text-white');
                                opt.classList.add('text-white/60');
                            });
                            targetCatOpt.classList.add('bg-primary', 'text-white');
                            targetCatOpt.classList.remove('text-white/60');
                        }
                    }
                    if (option.dataset.diff) {
                        const diffHidden = document.getElementById('assign_difficulty');
                        const diffDisplay = document.getElementById('assign_difficulty_display');
                        const diffContainer = diffHidden.closest('.custom-select-container');
                        
                        diffHidden.value = option.dataset.diff;
                        const targetDiffOpt = Array.from(diffContainer.querySelectorAll('.custom-option')).find(o => o.dataset.value === option.dataset.diff);
                        if (targetDiffOpt) {
                            diffDisplay.value = targetDiffOpt.textContent.trim();
                            diffContainer.querySelectorAll('.custom-option').forEach(opt => {
                                opt.classList.remove('bg-primary', 'text-white');
                                opt.classList.add('text-white/60');
                            });
                            targetDiffOpt.classList.add('bg-primary', 'text-white');
                            targetDiffOpt.classList.remove('text-white/60');
                        }
                    }
                    if (option.dataset.muscles) {
                        const targetMusclesInput = document.getElementById('assign_target_muscles');
                        if (targetMusclesInput) {
                            targetMusclesInput.value = option.dataset.muscles;
                        }
                    }
                }
                
                if (option.closest('#filterForm')) {
                    triggerFilter();
                }
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

        <?php $active_tab = isset($_GET['tab']) ? $_GET['tab'] : (isset($_GET['student_id']) ? 'progressTab' : (($member_id > 0 || !empty($search) || !empty($status_filter)) ? 'historyTab' : 'recentTab')); ?>

        <!-- Navigation & Search Section -->
        <div class="flex flex-col md:flex-row md:items-end justify-between gap-6 mb-8 border-b border-white/5">
            <div class="flex gap-8 w-full md:w-auto overflow-visible">
                <button onclick="toggleTab('recentTab')" data-tab="recentTab" class="tab-btn <?= $active_tab === 'recentTab' ? 'active' : '' ?> pb-4 -mb-[1px] text-xs font-black uppercase tracking-widest transition-all whitespace-nowrap outline-none" style="color: <?= $active_tab === 'recentTab' ? 'var(--primary)' : 'color-mix(in srgb, var(--text-main) 45%, transparent)' ?>; border-bottom: 2px solid <?= $active_tab === 'recentTab' ? 'var(--primary)' : 'transparent' ?>;">Recently Assigned</button>
                <button onclick="toggleTab('historyTab')" data-tab="historyTab" class="tab-btn <?= $active_tab === 'historyTab' ? 'active' : '' ?> pb-4 -mb-[1px] text-xs font-black uppercase tracking-widest transition-all whitespace-nowrap outline-none" style="color: <?= $active_tab === 'historyTab' ? 'var(--primary)' : 'color-mix(in srgb, var(--text-main) 45%, transparent)' ?>; border-bottom: 2px solid <?= $active_tab === 'historyTab' ? 'var(--primary)' : 'transparent' ?>;">Program History</button>
                <button onclick="toggleTab('progressTab')" data-tab="progressTab" class="tab-btn <?= $active_tab === 'progressTab' ? 'active' : '' ?> pb-4 -mb-[1px] text-xs font-black uppercase tracking-widest transition-all whitespace-nowrap outline-none" style="color: <?= $active_tab === 'progressTab' ? 'var(--primary)' : 'color-mix(in srgb, var(--text-main) 45%, transparent)' ?>; border-bottom: 2px solid <?= $active_tab === 'progressTab' ? 'var(--primary)' : 'transparent' ?>;">Student Progress</button>
            </div>
            
            <div class="flex items-center gap-4 pb-4">
                <button onclick="document.getElementById('assignModal').style.display = 'flex'" class="bg-primary hover:opacity-90 text-[white] px-6 py-2.5 rounded-xl font-black uppercase text-[10px] tracking-widest transition-all active:scale-[0.98] flex items-center justify-center gap-2 whitespace-nowrap">
                    <span class="material-symbols-outlined text-base">add_circle</span> Assign New Workout
                </button>
            </div>
        </div>


        <div id="progressTab" class="tab-content <?= $active_tab === 'progressTab' ? 'active' : '' ?>">
            <section class="mb-12 animate-slide-up">
                <!-- Unified Filter Bar -->
                <div class="glass-card mb-8 relative z-[60]">
                    <div class="px-8 py-6 flex flex-col md:flex-row items-center gap-4">
                        
                        <!-- Search Input -->
                        <div class="relative flex-1 group min-w-[150px]">
                            <div class="relative bg-white/5 border border-white/10 rounded-2xl overflow-hidden flex items-center h-[52px] hover:border-white/20 transition-all focus-within:border-primary/50">
                                <span class="material-symbols-outlined absolute left-4 text-primary/60 text-base pointer-events-none transition-transform group-focus-within:scale-110">search</span>
                                <input type="text" id="progressSearch" placeholder="Search by plan name..." class="w-full bg-transparent border-none text-white text-[10px] font-black uppercase tracking-widest placeholder:text-white/40 pl-11 pr-4 focus:outline-none focus:ring-0 h-full outline-none shadow-none" oninput="filterProgressTable()" autocomplete="off">
                            </div>
                        </div>

                        <!-- Student Dropdown -->
                        <div class="flex-1 relative group shrink-0 custom-select-container min-w-[150px]">
                            <div class="relative bg-white/5 border border-white/10 rounded-2xl overflow-hidden flex items-center h-[52px] hover:border-white/20 transition-all cursor-pointer custom-select-trigger" onclick="toggleCustomDropdown(this, event)">
                                <span class="material-symbols-outlined absolute left-4 text-primary/60 text-base pointer-events-none transition-transform group-focus-within:scale-110">person_search</span>
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
                                <input type="text" placeholder="Search Name..." value="<?= htmlspecialchars($selected_student_name) ?>" oninput="filterCustomSelect(this)" onclick="event.stopPropagation(); const d = this.closest('.custom-select-container').querySelector('.custom-select-dropdown'); document.querySelectorAll('.custom-select-dropdown').forEach(x => { if(x !== d) x.classList.add('hidden'); }); d.classList.remove('hidden');" class="w-full bg-transparent border-none text-white text-[10px] font-black uppercase tracking-widest cursor-text pl-11 pr-10 focus:outline-none focus:ring-0 h-full outline-none shadow-none">
                                <span class="material-symbols-outlined absolute right-4 text-white/40 text-base pointer-events-none transition-transform group-hover:scale-110">expand_more</span>
                            </div>
                            <div class="absolute left-0 right-0 top-full mt-2 z-[100] rounded-xl bg-[#141216] shadow-2xl border border-white/10 p-1.5 space-y-0.5 custom-select-dropdown hidden max-h-64 overflow-y-auto no-scrollbar searchable-dropdown-overlay">
                                <div class="px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-colors custom-option <?= $student_id == 0 ? 'bg-primary text-white' : 'text-white/60' ?>" data-value="0" onclick="window.location.href='?tab=progressTab'">All Students</div>
                                <?php foreach($all_members as $sm): ?>
                                    <div class="px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-colors custom-option <?= $student_id == $sm['member_id'] ? 'bg-primary text-white' : 'text-white/60' ?>" data-value="<?= $sm['member_id'] ?>" onclick="window.location.href='?student_id=<?= $sm['member_id'] ?>&tab=progressTab'">
                                        <?= htmlspecialchars(trim($sm['first_name'] . ' ' . $sm['last_name'])) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Status Filter -->
                        <div class="w-[190px] relative group shrink-0 custom-select-container">
                            <div class="relative bg-white/5 border border-white/10 rounded-2xl overflow-hidden flex items-center h-[52px] hover:border-white/20 transition-all cursor-pointer custom-select-trigger" onclick="toggleCustomDropdown(this, event)">
                                <span class="material-symbols-outlined absolute left-4 text-primary/60 text-base pointer-events-none transition-transform group-focus-within:scale-110">toggle_on</span>
                                <input type="text" id="progressStatusDisplay" readonly value="All Status" class="w-full bg-transparent border-none text-white text-[10px] font-black uppercase tracking-widest cursor-pointer pl-11 pr-10 focus:outline-none focus:ring-0 h-full outline-none shadow-none pointer-events-none">
                                <span class="material-symbols-outlined absolute right-4 text-white/40 text-base pointer-events-none transition-transform group-hover:scale-110">expand_more</span>
                            </div>
                            <div class="absolute left-0 right-0 top-full mt-2 z-[100] rounded-xl bg-[#141216] shadow-2xl border border-white/10 p-1.5 space-y-0.5 custom-select-dropdown hidden">
                                <div class="px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-colors custom-status-option bg-primary text-white" onclick="setProgressStatus('', 'All Status', this)">All Status</div>
                                <div class="px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-colors custom-status-option text-white/60" onclick="setProgressStatus('Assigned', 'Assigned', this)">Assigned</div>
                                <div class="px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-colors custom-status-option text-white/60" onclick="setProgressStatus('Completed', 'Completed', this)">Completed</div>
                            </div>
                        </div>

                        <!-- Sort Filter -->
                        <div class="w-[180px] relative group shrink-0 custom-select-container">
                            <div class="relative bg-white/5 border border-white/10 rounded-2xl overflow-hidden flex items-center h-[52px] hover:border-white/20 transition-all cursor-pointer custom-select-trigger" onclick="toggleCustomDropdown(this, event)">
                                <span class="material-symbols-outlined absolute left-4 text-primary/60 text-base pointer-events-none transition-transform group-focus-within:scale-110">sort</span>
                                <input type="text" id="progressSortDisplay" readonly value="Newest" class="w-full bg-transparent border-none text-white text-[10px] font-black uppercase tracking-widest cursor-pointer pl-11 pr-10 focus:outline-none focus:ring-0 h-full outline-none shadow-none pointer-events-none">
                                <span class="material-symbols-outlined absolute right-4 text-white/40 text-base pointer-events-none transition-transform group-hover:scale-110">expand_more</span>
                            </div>
                            <div class="absolute left-0 right-0 top-full mt-2 z-[100] rounded-xl bg-[#141216] shadow-2xl border border-white/10 p-1.5 space-y-0.5 custom-select-dropdown hidden">
                                <div class="px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-colors custom-sort-option bg-primary text-white" onclick="setProgressSort('newest', 'Newest', this)">Newest</div>
                                <div class="px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-colors custom-sort-option text-white/60" onclick="setProgressSort('oldest', 'Oldest', this)">Oldest</div>
                                <div class="px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-colors custom-sort-option text-white/60" onclick="setProgressSort('name_asc', 'Name A-Z', this)">Name A-Z</div>
                            </div>
                        </div>

                        <!-- Reset -->
                        <a href="coach_workouts.php?tab=progressTab" class="size-[52px] rounded-2xl bg-white/5 border border-white/5 flex items-center justify-center text-gray-500 hover:text-white hover:bg-white/10 transition-all group active:scale-95 shrink-0" title="Reset Filters">
                            <span class="material-symbols-outlined text-xl group-hover:rotate-180 transition-transform duration-500">restart_alt</span>
                        </a>
                    </div>
                </div>

                <!-- Member Identity Card & Their Progress -->
                <?php if($selected_member): ?>
                <div class="glass-card p-6 shadow-xl relative overflow-hidden group mb-12" style="border-left: 2px solid var(--primary); background: linear-gradient(to right, color-mix(in srgb, var(--primary) 8%, var(--card-bg)), var(--card-bg));">
                    <div class="absolute top-0 right-0 p-8 opacity-[0.03] group-hover:opacity-[0.07] transition-opacity pointer-events-none" style="color:var(--primary)">
                        <span class="material-symbols-outlined text-8xl">fitness_center</span>
                    </div>
                    <div class="flex items-center justify-between relative z-10">
                        <div class="flex items-center gap-6">
                            <div class="size-16 rounded-2xl flex items-center justify-center font-black text-2xl shadow-inner" style="background:color-mix(in srgb, var(--primary) 12%, transparent); color:var(--primary); border: 1px solid color-mix(in srgb, var(--primary) 20%, transparent);">
                                <?= strtoupper(substr($selected_member['first_name'], 0, 1)) ?>
                            </div>
                            <div>
                                <div class="flex items-center gap-3 mb-1">
                                    <h3 class="text-xl font-bold text-white tracking-tight"><?= htmlspecialchars($selected_member['first_name'] . ' ' . $selected_member['last_name']) ?></h3>
                                    <span class="px-3 py-1 rounded-md text-[10px] font-black uppercase tracking-widest border" style="color:var(--primary); background:color-mix(in srgb, var(--primary) 10%, transparent); border-color:color-mix(in srgb, var(--primary) 20%, transparent)">Active</span>
                                </div>
                                <div class="flex items-center gap-4">
                                    <p class="text-xs text-gray-500 font-semibold tracking-wide">ID: #<?= str_pad($selected_member['member_id'], 5, '0', STR_PAD_LEFT) ?></p>
                                    <span class="w-1 h-1 rounded-full bg-white/10"></span>
                                    <p class="text-xs text-gray-500 font-semibold tracking-wide"><?= htmlspecialchars($selected_member['email']) ?></p>
                                    <span class="w-1 h-1 rounded-full bg-white/10"></span>
                                    <p class="text-xs text-gray-500 font-semibold tracking-wide">Since <?= date('M Y', strtotime($selected_member['created_at'] ?? 'now')) ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="glass-card overflow-hidden animate-slide-up flex flex-col relative z-[50]">


                    <!-- Table -->
                    <div class="overflow-x-auto no-scrollbar">
                        <table class="w-full text-left border-collapse" id="progressTable">
                            <thead>
                            <tr class="bg-white/5 border-b border-white/5 text-[10px] font-black uppercase tracking-[0.25em]">
                                <th class="px-8 py-5 opacity-40">Target Member</th>
                                <th class="px-8 py-5 opacity-40">Program</th>
                                <th class="px-8 py-5 opacity-40">Assigned Date</th>
                                <th class="px-8 py-5 opacity-40 text-center">Status</th>
                                <th class="px-8 py-5 opacity-40 text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5" id="progressTbody">
                            <?php foreach($student_recent_workouts as $rw):
                                $rw_status = $rw['workout_status'] ?? 'Assigned';
                            ?>
                            <tr class="hover:bg-white/[0.03] transition-all progress-row" data-name="<?= htmlspecialchars(strtolower($rw['workout_name'])) ?>" data-status="<?= htmlspecialchars($rw_status) ?>" data-date="<?= strtotime($rw['created_at']) ?>">
                                <td class="px-8 py-5">
                                    <div class="flex items-center gap-4">
                                        <?php $initials = strtoupper(substr($rw['first_name'] ?? '', 0, 1) . substr($rw['last_name'] ?? '', 0, 1)); ?>
                                        <div class="size-11 rounded-full border border-white/10 flex items-center justify-center font-black italic text-primary text-[11px] shrink-0 overflow-hidden shadow-inner relative" style="background:rgba(var(--primary-rgb), 0.1)">
                                            <?php if (!empty($rw['profile_picture'])): ?>
                                                <img src="<?= htmlspecialchars('../' . $rw['profile_picture']) ?>" class="size-full object-cover" onerror="this.outerHTML='<span class=\'text-primary font-black italic text-[11px]\'><?= $initials ?></span>'">
                                            <?php else: ?>
                                                <span class="text-primary font-black italic text-[11px]"><?= $initials ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-[--text-main] opacity-80 font-black tracking-widest text-[13px]"><?= htmlspecialchars($rw['first_name'] . ' ' . $rw['last_name']) ?></p>
                                    </div>
                                </td>
                                <td class="px-8 py-5">
                                    <p class="text-[--text-main] opacity-80 font-black text-[13px] leading-tight truncate max-w-[250px]"><?= htmlspecialchars($rw['workout_name']) ?></p>
                                </td>
                                <td class="px-8 py-5">
                                    <p class="text-[--text-main] opacity-40 font-black tracking-widest text-[12px]"><?= date('M d, Y', strtotime($rw['created_at'])) ?></p>
                                </td>
                                <td class="px-8 py-5 text-center">
                                    <?php
                                        if ($rw_status === 'Completed') {
                                            $status_classes = 'text-[#10B981] bg-[#10B981]/10 border-[#10B981]/20';
                                        } elseif ($rw_status === 'Missed') {
                                            $status_classes = 'text-[#EF4444] bg-[#EF4444]/10 border-[#EF4444]/20';
                                        } else {
                                            $status_classes = 'text-[#F59E0B] bg-[#F59E0B]/10 border-[#F59E0B]/20';
                                        }
                                    ?>
                                    <span class="px-3 py-1.5 rounded-lg text-[9px] font-black tracking-widest border <?= $status_classes ?>"><?= $rw_status ?></span>
                                </td>
                                <td class="px-8 py-5 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <button type="button" class="size-10 rounded-xl flex items-center justify-center transition-all active:scale-90 group border border-white/5 text-white/40 hover:text-white hover:border-white/20 hover:bg-white/5" title="View Details" onclick="openDetailsModal(this)" data-name="<?= htmlspecialchars($rw['workout_name']) ?>" data-member="<?= htmlspecialchars($rw['first_name'] . ' ' . $rw['last_name']) ?>" data-category="<?= htmlspecialchars($rw['workout_category'] ?? 'General') ?>" data-difficulty="<?= htmlspecialchars($rw['difficulty_level'] ?? 'Intermediate') ?>" data-duration="<?= htmlspecialchars($rw['duration_weeks'] ?? 4) ?>" data-time="<?= htmlspecialchars($rw['estimated_minutes'] ?? 60) ?>" data-desc="<?= htmlspecialchars($rw['workout_description'] ?? 'No description provided.') ?>"><span class="material-symbols-outlined text-lg group-hover:scale-110 transition-transform">visibility</span></button>
                                        <?php if($rw_status != 'Completed'): ?>
                                        <a href="javascript:void(0)" onclick="confirmAction('?student_id=<?= $student_id ?>&action=update_status&workout_id=<?= $rw['workout_id'] ?>&status=Completed&tab=progressTab', 'Are you sure you want to complete this program?')" class="size-10 rounded-xl flex items-center justify-center transition-all active:scale-90 group border border-green-500/20 text-green-500/70 hover:text-green-400 hover:border-green-500/40 hover:bg-green-500/10" title="Complete Program"><span class="material-symbols-outlined text-lg group-hover:scale-110 transition-transform">check_circle</span></a>
                                        <?php else: ?>
                                        <div class="size-10 rounded-xl flex items-center justify-center border border-white/5 text-white/10 cursor-not-allowed" title="Already Completed"><span class="material-symbols-outlined text-lg">check_circle</span></div>
                                        <?php endif; ?>
                                        <?php if($rw_status != 'Assigned'): ?>
                                        <a href="javascript:void(0)" onclick="confirmAction('?student_id=<?= $student_id ?>&action=update_status&workout_id=<?= $rw['workout_id'] ?>&status=Assigned&tab=progressTab', 'Are you sure you want to re-assign this program?')" class="size-10 rounded-xl flex items-center justify-center transition-all active:scale-90 group border border-yellow-500/20 text-yellow-500/70 hover:text-yellow-400 hover:border-yellow-500/40 hover:bg-yellow-500/10" title="Re-assign"><span class="material-symbols-outlined text-lg group-hover:scale-110 transition-transform">refresh</span></a>
                                        <?php else: ?>
                                        <div class="size-10 rounded-xl flex items-center justify-center border border-white/5 text-white/10 cursor-not-allowed" title="Already Assigned"><span class="material-symbols-outlined text-lg">refresh</span></div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        </table>
                        <div id="progressNoResults" class="hidden p-24 text-center opacity-30 text-[11px] font-black tracking-[0.3em]">No matching programs found.</div>
                    </div>

                    <!-- Pagination Footer -->
                    <div class="px-8 py-6 border-t border-white/5 flex items-center justify-between bg-white/[0.01]">
                        <p class="text-[9px] font-black uppercase tracking-[0.2em] text-white/30">SHOWING <span id="progressStart" class="text-inherit">0</span> TO <span id="progressEnd" class="text-inherit">0</span> OF <span id="progressTotal" class="text-inherit">0</span> ENTRIES</p>
                        <div id="progressPaginationControls" class="flex items-center gap-2"></div>
                    </div>
                </div>

                <script>
                    let progressStatusFilter = '';
                    let progressSortMode = 'newest';

                    let progressCurrentPage = 1;
                    const progressItemsPerPage = 10;

                    function filterProgressTable() {
                        const search = document.getElementById('progressSearch').value.toLowerCase();
                        const rows = document.querySelectorAll('.progress-row');
                        let visibleRows = [];
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
                        arr.forEach(row => {
                            const nameMatch = row.dataset.name.includes(search);
                            const statusMatch = !progressStatusFilter || row.dataset.status === progressStatusFilter;
                            const show = nameMatch && statusMatch;
                            if (show) {
                                visibleRows.push(row);
                            } else {
                                row.classList.add('hidden');
                            }
                        });

                        const total = visibleRows.length;
                        const totalPages = Math.max(1, Math.ceil(total / progressItemsPerPage));
                        if (progressCurrentPage > totalPages) progressCurrentPage = totalPages;

                        const startIdx = (progressCurrentPage - 1) * progressItemsPerPage;
                        const endIdx = startIdx + progressItemsPerPage;

                        visibleRows.forEach((row, idx) => {
                            if (idx >= startIdx && idx < endIdx) {
                                row.classList.remove('hidden');
                            } else {
                                row.classList.add('hidden');
                            }
                        });

                        const noResults = document.getElementById('progressNoResults');
                        if (noResults) noResults.classList.toggle('hidden', total > 0);

                        let actualStart = total > 0 ? startIdx + 1 : 0;
                        document.getElementById('progressStart').textContent = actualStart;
                        document.getElementById('progressEnd').textContent = Math.min(endIdx, total);
                        document.getElementById('progressTotal').textContent = total;
                        
                        renderPaginationControls('progressPaginationControls', progressCurrentPage, totalPages, 'changeProgressPage');
                    }

                    function changeProgressPage(dir, isAbsolute = false, absolutePage = 1) {
                        if (isAbsolute) {
                            progressCurrentPage = absolutePage;
                        } else {
                            progressCurrentPage += dir;
                        }
                        filterProgressTable();
                    }

                    function setProgressStatus(val, label, el) {
                        progressStatusFilter = val;
                        document.getElementById('progressStatusDisplay').value = label;
                        el.closest('.custom-select-container').querySelectorAll('.px-4').forEach(o => {
                            o.classList.remove('bg-primary', 'text-white');
                            o.classList.add('text-white/60');
                        });
                        el.classList.remove('text-white/60');
                        el.classList.add('bg-primary', 'text-white');
                        filterProgressTable();
                    }

                    function setProgressSort(val, label, el) {
                        progressSortMode = val;
                        document.getElementById('progressSortDisplay').value = label;
                        el.closest('.custom-select-container').querySelectorAll('.px-4').forEach(o => {
                            o.classList.remove('bg-primary', 'text-white');
                            o.classList.add('text-white/60');
                        });
                        el.classList.remove('text-white/60');
                        el.classList.add('bg-primary', 'text-white');
                        filterProgressTable();
                    }

                    function resetProgressFilters() {
                        progressStatusFilter = '';
                        progressSortMode = 'newest';
                        document.getElementById('progressSearch').value = '';
                        document.getElementById('progressStatusDisplay').value = 'All Status';
                        document.getElementById('progressSortDisplay').value = 'Newest';
                        document.querySelectorAll('.custom-status-option, .custom-sort-option').forEach(o => {
                            o.classList.remove('bg-primary', 'text-white');
                            o.classList.add('text-white/60');
                        });
                        document.querySelector('.custom-status-option:first-child').classList.add('bg-primary', 'text-white');
                        document.querySelector('.custom-status-option:first-child').classList.remove('text-white/60');
                        document.querySelector('.custom-sort-option:first-child').classList.add('bg-primary', 'text-white');
                        document.querySelector('.custom-sort-option:first-child').classList.remove('text-white/60');
                        filterProgressTable();
                    }

                    document.addEventListener('DOMContentLoaded', filterProgressTable);
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
                            <span class="material-symbols-outlined absolute left-4 text-primary/60 text-base pointer-events-none transition-transform group-focus-within:scale-110">sort</span>
                            <input type="text" id="recentSortDisplay" readonly value="Newest" class="w-full bg-transparent border-none text-white text-[10px] font-black uppercase tracking-widest cursor-pointer pl-11 pr-10 focus:outline-none focus:ring-0 h-full outline-none shadow-none pointer-events-none">
                            <span class="material-symbols-outlined absolute right-4 text-white/40 text-base pointer-events-none">expand_more</span>
                        </div>
                        <div class="absolute left-0 right-0 top-full mt-2 z-[100] rounded-xl bg-[#141216] shadow-2xl border border-white/10 p-1.5 space-y-0.5 custom-select-dropdown hidden">
                            <div class="px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-colors bg-primary text-white" onclick="setRecentSort('newest', 'Newest', this)">Newest</div>
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
                            <tr class="bg-white/5 border-b border-white/5 text-[11px] font-black uppercase tracking-[0.2em]">
                                <th class="px-8 py-5 opacity-40">Target Member</th>
                                <th class="px-8 py-5 opacity-40">Program</th>
                                <th class="px-8 py-5 opacity-40">Assigned Date</th>
                                <th class="px-8 py-5 opacity-40 text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5" id="recentTbody">
                            <?php foreach($recent_workouts as $rw): ?>
                            <tr class="hover:bg-white/[0.03] transition-all recent-row group/row animate-fade-in" data-name="<?= htmlspecialchars(strtolower($rw['workout_name'] . ' ' . $rw['first_name'] . ' ' . $rw['last_name'])) ?>" data-date="<?= strtotime($rw['created_at']) ?>">
                                <td class="px-8 py-5">
                                    <div class="flex items-center gap-4">
                                        <?php $initials = strtoupper(substr($rw['first_name'] ?? '', 0, 1) . substr($rw['last_name'] ?? '', 0, 1)); ?>
                                        <div class="size-11 rounded-full border border-white/10 flex items-center justify-center font-black italic text-primary text-[11px] shrink-0 overflow-hidden shadow-inner relative" style="background:rgba(var(--primary-rgb), 0.1)">
                                            <?php if (!empty($rw['profile_picture'])): ?>
                                                <img src="<?= htmlspecialchars('../' . $rw['profile_picture']) ?>" class="size-full object-cover" onerror="this.outerHTML='<span class=\'text-primary font-black italic text-[11px]\'><?= $initials ?></span>'">
                                            <?php else: ?>
                                                <span class="text-primary font-black italic text-[11px]"><?= $initials ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-[--text-main] opacity-80 font-black tracking-widest text-[13px]"><?= htmlspecialchars($rw['first_name'] . ' ' . $rw['last_name']) ?></p>
                                    </div>
                                </td>
                                <td class="px-8 py-5">
                                    <p class="text-[--text-main] opacity-80 font-black text-[13px] leading-tight truncate max-w-[250px]"><?= htmlspecialchars($rw['workout_name']) ?></p>
                                </td>
                                <td class="px-8 py-5">
                                    <p class="text-[--text-main] opacity-40 font-black tracking-widest text-[12px]"><?= date('M d, Y', strtotime($rw['created_at'])) ?></p>
                                </td>
                                <td class="px-8 py-5 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <button type="button" class="size-10 rounded-xl flex items-center justify-center transition-all active:scale-90 group border border-white/5 text-white/40 hover:text-white hover:border-white/20 hover:bg-white/5" title="View Details" onclick="openDetailsModal(this)" data-name="<?= htmlspecialchars($rw['workout_name']) ?>" data-member="<?= htmlspecialchars($rw['first_name'] . ' ' . $rw['last_name']) ?>" data-category="<?= htmlspecialchars($rw['workout_category'] ?? 'General') ?>" data-difficulty="<?= htmlspecialchars($rw['difficulty_level'] ?? 'Intermediate') ?>" data-duration="<?= htmlspecialchars($rw['duration_weeks'] ?? 4) ?>" data-time="<?= htmlspecialchars($rw['estimated_minutes'] ?? 60) ?>" data-desc="<?= htmlspecialchars($rw['workout_description'] ?? 'No description provided.') ?>"><span class="material-symbols-outlined text-lg group-hover:scale-110 transition-transform">visibility</span></button>
                                        <?php if(($rw['workout_status'] ?? 'Assigned') != 'Completed'): ?>
                                        <a href="javascript:void(0)" onclick="confirmAction('?member_id=<?= $member_id ?>&action=update_status&workout_id=<?= $rw['workout_id'] ?>&status=Completed', 'Are you sure you want to mark this as completed?')" class="size-10 rounded-xl flex items-center justify-center transition-all active:scale-90 group border border-green-500/20 text-green-500/70 hover:text-green-400 hover:border-green-500/40 hover:bg-green-500/10" title="Mark as Completed"><span class="material-symbols-outlined text-lg group-hover:scale-110 transition-transform">check_circle</span></a>
                                        <?php else: ?>
                                        <div class="size-10 rounded-xl flex items-center justify-center border border-white/5 text-white/10 cursor-not-allowed" title="Already Completed"><span class="material-symbols-outlined text-lg">check_circle</span></div>
                                        <?php endif; ?>
                                        <?php if(($rw['workout_status'] ?? 'Assigned') != 'Assigned'): ?>
                                        <a href="javascript:void(0)" onclick="confirmAction('?member_id=<?= $member_id ?>&action=update_status&workout_id=<?= $rw['workout_id'] ?>&status=Assigned', 'Are you sure you want to re-assign this program?')" class="size-10 rounded-xl flex items-center justify-center transition-all active:scale-90 group border border-yellow-500/20 text-yellow-500/70 hover:text-yellow-400 hover:border-yellow-500/40 hover:bg-yellow-500/10" title="Re-assign Program"><span class="material-symbols-outlined text-lg group-hover:scale-110 transition-transform">refresh</span></a>
                                        <?php else: ?>
                                        <div class="size-10 rounded-xl flex items-center justify-center border border-white/5 text-white/10 cursor-not-allowed" title="Already Assigned"><span class="material-symbols-outlined text-lg">refresh</span></div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div id="recentNoResults" class="hidden p-24 text-center opacity-30 text-[11px] font-black tracking-[0.3em]">No matching programs found.</div>
                </div>
                <!-- Pagination Footer -->
                <div class="px-8 py-6 border-t border-white/5 flex items-center justify-between bg-white/[0.01]">
                    <p class="text-[9px] font-black uppercase tracking-[0.2em] text-white/30">SHOWING <span id="recentStart" class="text-inherit">0</span> TO <span id="recentEnd" class="text-inherit">0</span> OF <span id="recentTotal" class="text-inherit">0</span> ENTRIES</p>
                    <div id="recentPaginationControls" class="flex items-center gap-2"></div>
                </div>
                <script>
                    let recentSortMode = 'newest';
                    let recentCurrentPage = 1;
                    const recentItemsPerPage = 10;

                    function filterRecentTable() {
                        const search = document.getElementById('recentSearch').value.toLowerCase();
                        const rows = document.querySelectorAll('.recent-row');
                        let visibleRows = [];
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
                        arr.forEach(row => {
                            const nameMatch = row.dataset.name.includes(search);
                            if (nameMatch) {
                                visibleRows.push(row);
                            } else {
                                row.classList.add('hidden');
                            }
                        });

                        const total = visibleRows.length;
                        const totalPages = Math.max(1, Math.ceil(total / recentItemsPerPage));
                        if (recentCurrentPage > totalPages) recentCurrentPage = totalPages;

                        const startIdx = (recentCurrentPage - 1) * recentItemsPerPage;
                        const endIdx = startIdx + recentItemsPerPage;

                        visibleRows.forEach((row, idx) => {
                            if (idx >= startIdx && idx < endIdx) {
                                row.classList.remove('hidden');
                            } else {
                                row.classList.add('hidden');
                            }
                        });

                        const noResults = document.getElementById('recentNoResults');
                        if(noResults) noResults.classList.toggle('hidden', total > 0);
                        
                        let actualStart = total > 0 ? startIdx + 1 : 0;
                        document.getElementById('recentStart').textContent = actualStart;
                        document.getElementById('recentEnd').textContent = Math.min(endIdx, total);
                        document.getElementById('recentTotal').textContent = total;
                        
                        renderPaginationControls('recentPaginationControls', recentCurrentPage, totalPages, 'changeRecentPage');
                    }

                    function changeRecentPage(dir, isAbsolute = false, absolutePage = 1) {
                        if (isAbsolute) {
                            recentCurrentPage = absolutePage;
                        } else {
                            recentCurrentPage += dir;
                        }
                        filterRecentTable();
                    }

                    window.onload = filterRecentTable;

                    function setRecentSort(val, label, el) {
                        recentSortMode = val;
                        document.getElementById('recentSortDisplay').value = label;
                        document.querySelectorAll('#recentTab .custom-select-dropdown .px-4').forEach(o => o.classList.remove('bg-primary', 'text-white'));
                        el.classList.add('bg-primary', 'text-white');
                        
                        const trigger = document.getElementById('recentSortDisplay').closest('.custom-select-trigger');
                        const icon = trigger.querySelector('.material-symbols-outlined.left-4');
                        const input = document.getElementById('recentSortDisplay');
                        const arrow = trigger.querySelector('.material-symbols-outlined.right-4');
                        
                        if (val !== 'newest') {
                            trigger.classList.replace('bg-white/5', 'bg-primary/5');
                            trigger.classList.replace('border-white/10', 'border-primary/30');
                            trigger.classList.replace('hover:border-white/20', 'hover:border-primary/50');
                            icon.classList.replace('text-primary/60', 'text-primary');
                            input.classList.replace('text-white', 'text-primary');
                            arrow.classList.replace('text-white/40', 'text-primary/60');
                        } else {
                            trigger.classList.replace('bg-primary/5', 'bg-white/5');
                            trigger.classList.replace('border-primary/30', 'border-white/10');
                            trigger.classList.replace('hover:border-primary/50', 'hover:border-white/20');
                            icon.classList.replace('text-primary', 'text-primary/60');
                            input.classList.replace('text-primary', 'text-white');
                            arrow.classList.replace('text-primary/60', 'text-white/40');
                        }
                        
                        filterRecentTable();
                    }

                    function resetRecentFilters() {
                        recentSortMode = 'newest';
                        document.getElementById('recentSearch').value = '';
                        document.getElementById('recentSortDisplay').value = 'Newest';
                        document.querySelectorAll('#recentTab .custom-select-dropdown .px-4').forEach(o => o.classList.remove('bg-primary', 'text-white'));
                        document.querySelector('#recentTab .custom-select-dropdown .px-4:first-child').classList.add('bg-primary', 'text-white');
                        
                        const trigger = document.getElementById('recentSortDisplay').closest('.custom-select-trigger');
                        const icon = trigger.querySelector('.material-symbols-outlined.left-4');
                        const input = document.getElementById('recentSortDisplay');
                        const arrow = trigger.querySelector('.material-symbols-outlined.right-4');
                        
                        trigger.classList.replace('bg-primary/5', 'bg-white/5');
                        trigger.classList.replace('border-primary/30', 'border-white/10');
                        trigger.classList.replace('hover:border-primary/50', 'hover:border-white/20');
                        icon.classList.replace('text-primary', 'text-primary/60');
                        input.classList.replace('text-primary', 'text-white');
                        if (arrow.classList.contains('text-primary/60')) {
                            arrow.classList.replace('text-primary/60', 'text-white/40');
                        }
                        
                        filterRecentTable();
                    }
                </script>
            </section>
            <?php else: ?><div class="py-20 text-center opacity-40 text-[10px] tracking-widest">No recent assignments found.</div><?php endif; ?>
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
                            <div class="relative <?= $member_active ? 'bg-primary/5 border-primary/30' : 'bg-white/5 border-white/10' ?> border rounded-2xl overflow-hidden flex items-center h-[52px] <?= $member_active ? 'hover:border-primary/50' : 'hover:border-white/20' ?> transition-all cursor-pointer custom-select-trigger" onclick="toggleCustomDropdown(this, event)">
                                <span class="material-symbols-outlined absolute left-4 <?= $member_active ? 'text-primary' : 'text-primary/60' ?> text-base pointer-events-none transition-transform group-focus-within:scale-110">person_search</span>
                                <?php 
                                    $selected_user_name = "All Users";
                                    $member_active = ($member_id > 0);
                                    if($member_active) {
                                        foreach($all_members as $m) {
                                            if($m['member_id'] == $member_id) {
                                                $selected_user_name = $m['first_name'] . ' ' . $m['last_name'];
                                            }
                                        }
                                    }
                                ?>
                                <input type="text" placeholder="Search Name..." value="<?= htmlspecialchars($selected_user_name) ?>" oninput="filterCustomSelect(this)" onclick="event.stopPropagation(); const d = this.closest('.custom-select-container').querySelector('.custom-select-dropdown'); document.querySelectorAll('.custom-select-dropdown').forEach(x => { if(x !== d) x.classList.add('hidden'); }); d.classList.remove('hidden');" class="w-full bg-transparent border-none <?= $member_active ? 'text-primary' : 'text-white' ?> text-[10px] font-black uppercase tracking-widest cursor-text pl-11 pr-10 focus:outline-none focus:ring-0 h-full outline-none shadow-none">
                                <span class="material-symbols-outlined absolute right-4 <?= $member_active ? 'text-primary/60' : 'text-white/40' ?> text-base pointer-events-none transition-transform group-hover:scale-110">expand_more</span>
                            </div>
                            <div class="absolute left-0 right-0 top-full mt-2 z-[100] rounded-xl bg-[#141216] shadow-2xl border border-white/10 p-1.5 space-y-0.5 custom-select-dropdown hidden max-h-64 overflow-y-auto no-scrollbar searchable-dropdown-overlay">
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
                            <?php $status_active = ($status_filter != ''); ?>
                            <div class="relative <?= $status_active ? 'bg-primary/5 border-primary/30' : 'bg-white/5 border-white/10' ?> border rounded-2xl overflow-hidden flex items-center h-[52px] <?= $status_active ? 'hover:border-primary/50' : 'hover:border-white/20' ?> transition-all cursor-pointer custom-select-trigger" onclick="toggleCustomDropdown(this, event)">
                                <span class="material-symbols-outlined absolute left-4 <?= $status_active ? 'text-primary' : 'text-primary/60' ?> text-base pointer-events-none transition-transform group-focus-within:scale-110">toggle_on</span>
                                <input type="text" readonly value="<?= $status_filter ?: 'All Status' ?>" class="w-full bg-transparent border-none <?= $status_active ? 'text-primary' : 'text-white' ?> text-[10px] font-black uppercase tracking-widest cursor-pointer pl-11 pr-10 focus:outline-none focus:ring-0 h-full outline-none shadow-none pointer-events-none">
                                <span class="material-symbols-outlined absolute right-4 <?= $status_active ? 'text-primary/60' : 'text-white/40' ?> text-base pointer-events-none transition-transform group-hover:scale-110">expand_more</span>
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
                            <?php 
                                $sort_active = ($sort_by != 'recent'); 
                                $sort_labels = ['recent' => 'Newest', 'oldest' => 'Oldest', 'name_asc' => 'Member A-Z'];
                                $current_sort_label = $sort_labels[$sort_by] ?? 'Newest';
                            ?>
                            <div class="relative <?= $sort_active ? 'bg-primary/5 border-primary/30' : 'bg-white/5 border-white/10' ?> border rounded-2xl overflow-hidden flex items-center h-[52px] <?= $sort_active ? 'hover:border-primary/50' : 'hover:border-white/20' ?> transition-all cursor-pointer custom-select-trigger" onclick="toggleCustomDropdown(this, event)">
                                <span class="material-symbols-outlined absolute left-4 <?= $sort_active ? 'text-primary' : 'text-primary/60' ?> text-base pointer-events-none transition-transform group-focus-within:scale-110">sort</span>
                                <input type="text" readonly value="<?= $current_sort_label ?>" class="w-full bg-transparent border-none <?= $sort_active ? 'text-primary' : 'text-white' ?> text-[10px] font-black uppercase tracking-widest cursor-pointer pl-11 pr-10 focus:outline-none focus:ring-0 h-full outline-none shadow-none pointer-events-none">
                                <span class="material-symbols-outlined absolute right-4 <?= $sort_active ? 'text-primary/60' : 'text-white/40' ?> text-base pointer-events-none transition-transform group-hover:scale-110">expand_more</span>
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
                            <tr class="bg-white/5 border-b border-white/5 text-[11px] font-black uppercase tracking-[0.2em]">
                                <th class="px-8 py-5 opacity-40">Target Member</th>
                                <th class="px-8 py-5 opacity-40">Program</th>
                                <th class="px-8 py-5 opacity-40">Details</th>
                                <th class="px-8 py-5 opacity-40 text-center">Status</th>
                                <th class="px-8 py-5 opacity-40 text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5" id="historyTbody">
                            <?php if(count($workouts) > 0) { 
                                foreach($workouts as $w) { ?>
                            <tr class="hover:bg-white/[0.03] transition-all group/row animate-fade-in history-row">
                                <td class="px-8 py-5">
                                    <div class="flex items-center gap-4">
                                        <?php $initials = strtoupper(substr($w['first_name'] ?? '', 0, 1) . substr($w['last_name'] ?? '', 0, 1)); ?>
                                        <div class="size-11 rounded-full border border-white/10 flex items-center justify-center font-black italic text-primary text-[11px] shrink-0 overflow-hidden shadow-inner relative" style="background:rgba(var(--primary-rgb), 0.1)">
                                            <?php if (!empty($w['profile_picture'])): ?>
                                                <img src="<?= htmlspecialchars('../' . $w['profile_picture']) ?>" class="size-full object-cover" onerror="this.outerHTML='<span class=\'text-primary font-black italic text-[11px]\'><?= $initials ?></span>'">
                                            <?php else: ?>
                                                <span class="text-primary font-black italic text-[11px]"><?= $initials ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-[--text-main] opacity-70 font-bold tracking-widest text-[13px]"><?= htmlspecialchars($w['first_name'] . ' ' . $w['last_name']) ?></p>
                                    </div>
                                </td>
                                <td class="px-8 py-5">
                                    <p class="text-[--text-main] opacity-70 font-black text-[13px] leading-tight mb-1 truncate max-w-[250px]"><?= htmlspecialchars($w['workout_name']) ?></p>
                                    <p class="text-[10px] font-black tracking-[0.2em]" style="color:var(--primary)"><?= htmlspecialchars($w['workout_category'] ?? 'General') ?></p>
                                </td>
                                <td class="px-8 py-5">
                                    <div class="flex flex-col">
                                        <p class="text-[--text-main] opacity-70 font-bold text-[13px]"><?= htmlspecialchars($w['difficulty_level'] ?? 'Intermediate') ?></p>
                                        <p class="text-[11px] text-[--text-main] opacity-40 font-black tracking-widest"><?= $w['estimated_minutes'] ?? 60 ?> Mins / <?= $w['duration_weeks'] ?? 4 ?> Wks</p>
                                    </div>
                                </td>
                                <td class="px-8 py-5 text-center">
                                    <?php 
                                        $ws = $w['workout_status']; 
                                        if ($ws === 'Completed') {
                                            $badge_class = 'text-[#10B981] bg-[#10B981]/10 border-[#10B981]/20';
                                        } elseif ($ws === 'Missed') {
                                            $badge_class = 'text-[#EF4444] bg-[#EF4444]/10 border-[#EF4444]/20';
                                        } else {
                                            $badge_class = 'text-[#F59E0B] bg-[#F59E0B]/10 border-[#F59E0B]/20';
                                        }
                                    ?>
                                    <span class="px-3 py-1.5 rounded-lg text-[9px] font-black tracking-widest border <?= $badge_class ?>"><?= $ws ?></span>
                                </td>
                                <td class="px-8 py-5 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <button type="button" class="size-10 rounded-xl flex items-center justify-center transition-all active:scale-90 group border border-white/5 text-white/40 hover:text-white hover:border-white/20 hover:bg-white/5" title="View Details" onclick="openDetailsModal(this)" data-name="<?= htmlspecialchars($w['workout_name']) ?>" data-member="<?= htmlspecialchars($w['first_name'] . ' ' . $w['last_name']) ?>" data-category="<?= htmlspecialchars($w['workout_category'] ?? 'General') ?>" data-difficulty="<?= htmlspecialchars($w['difficulty_level'] ?? 'Intermediate') ?>" data-duration="<?= htmlspecialchars($w['duration_weeks'] ?? 4) ?>" data-time="<?= htmlspecialchars($w['estimated_minutes'] ?? 60) ?>" data-desc="<?= htmlspecialchars($w['workout_description'] ?? 'No description provided.') ?>"><span class="material-symbols-outlined text-lg group-hover:scale-110 transition-transform">visibility</span></button>
                                        <?php if($w['workout_status'] != 'Completed'): ?>
                                        <a href="javascript:void(0)" onclick="confirmAction('?member_id=<?= $member_id ?>&action=update_status&workout_id=<?= $w['workout_id'] ?>&status=Completed', 'Are you sure you want to complete this program?')" class="size-10 rounded-xl flex items-center justify-center transition-all active:scale-90 group border border-green-500/20 text-green-500/70 hover:text-green-400 hover:border-green-500/40 hover:bg-green-500/10" title="Complete Program"><span class="material-symbols-outlined text-lg group-hover:scale-110 transition-transform">check_circle</span></a>
                                        <?php else: ?>
                                        <div class="size-10 rounded-xl flex items-center justify-center border border-white/5 text-white/10 cursor-not-allowed" title="Already Completed"><span class="material-symbols-outlined text-lg">check_circle</span></div>
                                        <?php endif; ?>
                                        <?php if($w['workout_status'] != 'Assigned'): ?>
                                        <a href="javascript:void(0)" onclick="confirmAction('?member_id=<?= $member_id ?>&action=update_status&workout_id=<?= $w['workout_id'] ?>&status=Assigned', 'Are you sure you want to re-assign this program?')" class="size-10 rounded-xl flex items-center justify-center transition-all active:scale-90 group border border-yellow-500/20 text-yellow-500/70 hover:text-yellow-400 hover:border-yellow-500/40 hover:bg-yellow-500/10" title="Re-assign Program"><span class="material-symbols-outlined text-lg group-hover:scale-110 transition-transform">refresh</span></a>
                                        <?php else: ?>
                                        <div class="size-10 rounded-xl flex items-center justify-center border border-white/5 text-white/10 cursor-not-allowed" title="Already Assigned"><span class="material-symbols-outlined text-lg">refresh</span></div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                                <?php } 
                            } else { ?>
                            <tr><td colspan="5" class="p-24 text-center opacity-30 text-[11px] font-black tracking-[0.3em] text-[--text-main]">No matching programs found.</td></tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Footer -->
                <div class="px-8 py-6 border-t border-white/5 flex items-center justify-between bg-white/[0.01]">
                    <p class="text-[9px] font-black uppercase tracking-[0.2em] text-white/30">SHOWING <span id="historyStart" class="text-inherit">0</span> TO <span id="historyEnd" class="text-inherit">0</span> OF <span id="historyTotal" class="text-inherit">0</span> ENTRIES</p>
                    <div id="historyPaginationControls" class="flex items-center gap-2"></div>
                </div>
                <script>
                    let historyCurrentPage = 1;
                    const historyItemsPerPage = 10;
                    
                    function paginateHistoryTable() {
                        const rows = Array.from(document.querySelectorAll('.history-row'));
                        const total = rows.length;
                        if (total === 0) return;
                        
                        const totalPages = Math.ceil(total / historyItemsPerPage);
                        if (historyCurrentPage > totalPages) historyCurrentPage = totalPages;
                        if (historyCurrentPage < 1) historyCurrentPage = 1;
                        
                        const startIdx = (historyCurrentPage - 1) * historyItemsPerPage;
                        const endIdx = startIdx + historyItemsPerPage;
                        
                        rows.forEach((row, idx) => {
                            if (idx >= startIdx && idx < endIdx) {
                                row.classList.remove('hidden');
                            } else {
                                row.classList.add('hidden');
                            }
                        });
                        
                        let actualStart = total > 0 ? startIdx + 1 : 0;
                        document.getElementById('historyStart').textContent = actualStart;
                        document.getElementById('historyEnd').textContent = Math.min(endIdx, total);
                        document.getElementById('historyTotal').textContent = total;
                        
                        renderPaginationControls('historyPaginationControls', historyCurrentPage, totalPages, 'changeHistoryPage');
                    }
                    
                    function changeHistoryPage(dir, isAbsolute = false, absolutePage = 1) {
                        if (isAbsolute) {
                            historyCurrentPage = absolutePage;
                        } else {
                            historyCurrentPage += dir;
                        }
                        paginateHistoryTable();
                    }

                    document.addEventListener('DOMContentLoaded', () => {
                        paginateHistoryTable();
                    });
                </script>
            </div>
        </div>
    </div>



    </main>
</div>

<!-- Assign Modal -->
<div id="assignModal" class="bg-[--background]/40 backdrop-blur-xl hidden fixed top-0 right-0 bottom-0 left-[110px] z-[100] items-center justify-center p-4 transition-all duration-300" style="display: none;">
    <div class="relative z-10 bg-[--card-bg] backdrop-blur-[--card-blur] w-full max-w-[540px] rounded-[32px] shadow-2xl border border-white/5 overflow-hidden transform transition-all duration-300 pointer-events-auto animate-slide-up flex flex-col max-h-[90vh]">
        
        <div class="p-8 overflow-y-auto no-scrollbar flex-1 relative">
            <button type="button" onclick="document.getElementById('assignModal').style.display = 'none'" class="absolute top-6 right-6 size-8 flex items-center justify-center rounded-lg bg-white/5 hover:bg-white/10 text-white/50 hover:text-white transition-colors border border-white/5">
                <span class="material-symbols-outlined text-[14px]">close</span>
            </button>

            <h3 class="text-xl font-black italic tracking-widest flex items-center gap-2 mb-8" style="color:var(--primary);">
                <span class="material-symbols-outlined">assignment_add</span>
                NEW WORKOUT
            </h3>
            
            <form action="" method="POST" class="flex flex-col">
                <!-- ASSIGNMENT DETAILS -->
                <div class="flex items-center gap-4 mb-6 mt-2">
                    <span class="material-symbols-outlined text-gray-400 text-lg">person</span>
                    <p class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">ASSIGNMENT DETAILS</p>
                    <div class="h-px bg-white/10 flex-1"></div>
                </div>

                <div class="flex flex-col gap-6 mb-8">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400 mb-2">TARGET MEMBER <span class="text-red-500">*</span></p>
                        <div class="relative group shrink-0 custom-select-container">
                            <div class="relative bg-transparent border border-white/10 rounded-xl overflow-hidden flex items-center h-12 hover:border-white/20 transition-all cursor-pointer custom-select-trigger" onclick="toggleCustomDropdown(this, event)">
                                <input type="hidden" name="m_id" id="assign_m_id" required>
                                <input type="text" id="assign_m_name" placeholder="Select Member..." value="" onkeydown="if(event.key === 'Enter') { event.preventDefault(); return false; }" oninput="filterCustomSelect(this)" onclick="event.stopPropagation(); const d = this.closest('.custom-select-container').querySelector('.custom-select-dropdown'); document.querySelectorAll('.custom-select-dropdown').forEach(x => { if(x !== d) x.classList.add('hidden'); }); d.classList.remove('hidden');" class="w-full bg-transparent border-none text-white text-[12px] font-bold cursor-text px-4 focus:outline-none focus:ring-0 outline-none shadow-none">
                                <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-white/40 text-base pointer-events-none transition-transform group-hover:scale-110">expand_more</span>
                            </div>
                            <div class="absolute left-0 right-0 top-full mt-2 z-[100] rounded-xl bg-[#141216] shadow-2xl border border-white/10 p-1.5 space-y-0.5 custom-select-dropdown hidden max-h-[200px] overflow-y-auto no-scrollbar searchable-dropdown-overlay">
                                <?php 
                                foreach($all_members as $m): 
                                    $fitness_goal = $m['fitness_goal'] ?? 'General Fitness';
                                    $exp_level = $m['experience_level'] ?? 'Intermediate';
                                    $injuries = $m['injuries_limitations'] ?? 'None';
                                    $target_muscles = $m['target_muscles'] ?: 'Full Body';
                                    
                                    $m_diff = ucfirst(strtolower($exp_level));
                                    if(!in_array($m_diff, ['Beginner', 'Intermediate', 'Advanced'])) {
                                        $m_diff = 'Intermediate';
                                    }
                                    
                                    $cat = 'Strength';
                                    if(stripos($fitness_goal, 'weight') !== false || stripos($fitness_goal, 'cardio') !== false || stripos($fitness_goal, 'lose') !== false) {
                                        $cat = 'Cardio';
                                    } elseif(stripos($fitness_goal, 'muscle') !== false || stripos($fitness_goal, 'bulk') !== false || stripos($fitness_goal, 'build') !== false) {
                                        $cat = 'Hypertrophy';
                                    } elseif(stripos($fitness_goal, 'endurance') !== false) {
                                        $cat = 'Endurance';
                                    }
                                ?>
                                    <div class="px-4 py-3 rounded-lg text-[12px] font-bold cursor-pointer hover:bg-white/5 transition-colors custom-option text-white/60" data-value="<?= $m['member_id'] ?>" data-cat="<?= $cat ?>" data-diff="<?= $m_diff ?>" data-goal="<?= htmlspecialchars($fitness_goal) ?>" data-injuries="<?= htmlspecialchars($injuries) ?>" data-muscles="<?= htmlspecialchars($target_muscles) ?>">
                                        <?= htmlspecialchars(trim($m['first_name'] . ' ' . $m['last_name'])) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400 mb-2">CATEGORY <span class="text-red-500">*</span></p>
                        <div class="relative group custom-select-container">
                            <div class="relative bg-transparent border border-white/10 rounded-xl overflow-hidden flex items-center h-12 hover:border-white/20 transition-all cursor-pointer custom-select-trigger" onclick="toggleCustomDropdown(this, event)">
                                <input type="hidden" name="workout_category" id="assign_w_category" value="Strength" required>
                                <input type="text" id="assign_w_category_display" readonly value="Strength Training" class="w-full bg-transparent border-none text-white text-[12px] font-bold cursor-pointer px-4 focus:outline-none focus:ring-0 outline-none shadow-none pointer-events-none">
                                <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-white/40 text-base pointer-events-none transition-transform group-hover:scale-110">expand_more</span>
                            </div>
                            <div class="absolute left-0 right-0 top-full mt-2 z-[100] rounded-xl bg-[#141216] shadow-2xl border border-white/10 p-1.5 space-y-0.5 custom-select-dropdown hidden">
                                <div class="px-4 py-3 rounded-lg text-[12px] font-bold cursor-pointer hover:bg-white/5 transition-colors custom-option bg-primary text-white" data-value="Strength">Strength Training</div>
                                <div class="px-4 py-3 rounded-lg text-[12px] font-bold cursor-pointer hover:bg-white/5 transition-colors custom-option text-white/60" data-value="Cardio">Cardio &amp; HIIT</div>
                                <div class="px-4 py-3 rounded-lg text-[12px] font-bold cursor-pointer hover:bg-white/5 transition-colors custom-option text-white/60" data-value="Hypertrophy">Hypertrophy</div>
                                <div class="px-4 py-3 rounded-lg text-[12px] font-bold cursor-pointer hover:bg-white/5 transition-colors custom-option text-white/60" data-value="Flexibility">Flexibility / Yoga</div>
                                <div class="px-4 py-3 rounded-lg text-[12px] font-bold cursor-pointer hover:bg-white/5 transition-colors custom-option text-white/60" data-value="Endurance">Endurance</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- PROGRAM CONFIGURATION -->
                <div class="flex items-center gap-4 mb-6 mt-4">
                    <span class="material-symbols-outlined text-gray-400 text-lg">settings</span>
                    <p class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">PROGRAM CONFIGURATION</p>
                    <div class="h-px bg-white/10 flex-1"></div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                    <div class="col-span-1 md:col-span-2">
                        <p class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400 mb-2">PROGRAM IDENTITY <span class="text-red-500">*</span></p>
                        <input type="text" name="workout_name" placeholder="Ex. Advanced Push-Pull Protocol" class="w-full h-12 bg-transparent border border-white/10 rounded-xl text-white text-[12px] font-bold px-4 focus:outline-none focus:border-primary/50 hover:border-white/20 transition-all" required autocomplete="off">
                    </div>

                    <div class="col-span-1 md:col-span-2">
                        <p class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400 mb-2">TARGET MUSCLES <span class="text-red-500">*</span></p>
                        <input type="text" name="target_muscles" id="assign_target_muscles" placeholder="Ex. Front Chest, Back Trapezius" class="w-full h-12 bg-transparent border border-white/10 rounded-xl text-white text-[12px] font-bold px-4 focus:outline-none focus:border-primary/50 hover:border-white/20 transition-all" required autocomplete="off">
                        <p class="text-[9px] font-bold text-primary/60 italic mt-1">* Automatically populated from member's profile but can be changed</p>
                    </div>

                    <div>
                        <p class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400 mb-2">INTENSITY LEVEL <span class="text-red-500">*</span></p>
                        <div class="relative group custom-select-container mb-1">
                            <div class="relative bg-transparent border border-white/10 rounded-xl overflow-hidden flex items-center h-12 hover:border-white/20 transition-all cursor-pointer custom-select-trigger" onclick="toggleCustomDropdown(this, event)">
                                <input type="hidden" name="difficulty_level" id="assign_difficulty" value="Intermediate" required>
                                <input type="text" id="assign_difficulty_display" readonly value="Intermediate" class="w-full bg-transparent border-none text-white text-[12px] font-bold cursor-pointer px-4 focus:outline-none focus:ring-0 outline-none shadow-none pointer-events-none">
                                <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-white/40 text-base pointer-events-none transition-transform group-hover:scale-110">expand_more</span>
                            </div>
                            <div class="absolute left-0 right-0 top-full mt-2 z-[100] rounded-xl bg-[#141216] shadow-2xl border border-white/10 p-1.5 space-y-0.5 custom-select-dropdown hidden">
                                <div class="px-4 py-3 rounded-lg text-[12px] font-bold cursor-pointer hover:bg-white/5 transition-colors custom-option text-white/60" data-value="Beginner">Beginner</div>
                                <div class="px-4 py-3 rounded-lg text-[12px] font-bold cursor-pointer hover:bg-white/5 transition-colors custom-option bg-primary text-white" data-value="Intermediate">Intermediate</div>
                                <div class="px-4 py-3 rounded-lg text-[12px] font-bold cursor-pointer hover:bg-white/5 transition-colors custom-option text-white/60" data-value="Advanced">Advanced</div>
                            </div>
                        </div>
                        <p class="text-[9px] font-bold text-primary/60 italic">* Automatically selected based on member's personalization</p>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400 mb-2 whitespace-nowrap">WEEKS <span class="text-red-500">*</span></p>
                            <input type="number" name="duration_weeks" value="4" min="1" class="w-full h-12 bg-transparent border border-white/10 rounded-xl text-white text-[12px] font-bold px-4 focus:outline-none focus:border-primary/50 hover:border-white/20 transition-all" required>
                        </div>
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400 mb-2 whitespace-nowrap">MINUTES <span class="text-red-500">*</span></p>
                            <input type="number" name="estimated_minutes" value="60" min="15" class="w-full h-12 bg-transparent border border-white/10 rounded-xl text-white text-[12px] font-bold px-4 focus:outline-none focus:border-primary/50 hover:border-white/20 transition-all" required>
                        </div>
                    </div>
                </div>

                <!-- WORKOUT INSTRUCTIONS -->
                <div class="flex items-center gap-4 mb-6 mt-4">
                    <span class="material-symbols-outlined text-gray-400 text-lg">edit_note</span>
                    <p class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">WORKOUT INSTRUCTIONS</p>
                    <div class="h-px bg-white/10 flex-1"></div>
                </div>

                <div class="mb-8">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">INSTRUCTIONS & DESCRIPTION <span class="text-red-500">*</span></p>
                        <button type="button" onclick="autoGenerateRoutine()" class="text-[9px] font-black uppercase tracking-[0.2em] text-primary hover:text-white transition-colors bg-primary/10 hover:bg-primary/30 px-3 py-1.5 rounded-lg flex items-center gap-1 active:scale-95">
                            <span class="material-symbols-outlined text-[14px]">magic_button</span> AUTO-GEN
                        </button>
                    </div>
                    <textarea id="workout_desc_input" name="workout_description" rows="5" placeholder="Ex. Warm-up, reps, sets..." class="w-full bg-transparent border border-white/10 rounded-xl text-white text-[13px] font-bold placeholder:text-white/30 p-4 focus:outline-none focus:border-primary/50 hover:border-white/20 transition-all resize-none no-scrollbar" required></textarea>
                </div>

                <input type="hidden" name="scheduled_date" value="<?= date('Y-m-d') ?>">
                
                <div class="pt-2 flex justify-end">
                    <button type="submit" name="assign_workout" class="px-8 h-12 rounded-xl bg-primary text-white text-[10px] font-black uppercase tracking-[0.2em] hover:bg-primary/90 transition-all active:scale-95 shadow-none hover:shadow-[0_0_20px_rgba(var(--primary-rgb),0.3)]">Create Workout</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Details Modal -->
<div id="viewDetailsModal" class="bg-[--background]/40 backdrop-blur-xl hidden fixed top-0 right-0 bottom-0 left-[110px] z-[100] items-center justify-center p-4 transition-all duration-300">
    <div class="relative z-10 bg-[--card-bg] backdrop-blur-[--card-blur] w-full max-w-[540px] rounded-[32px] shadow-2xl border border-white/5 overflow-hidden transform transition-all duration-300 pointer-events-auto animate-slide-up flex flex-col max-h-[90vh]">
        
        <div class="p-8 overflow-y-auto no-scrollbar flex-1">
            <header class="mb-7 relative">
                <div class="flex items-start justify-between mb-1">
                    <h3 class="text-[22px] font-black uppercase tracking-tighter leading-none flex gap-2">
                        <span class="text-white">Program</span><span class="text-primary" style="color:var(--primary)">Details</span>
                    </h3>
                    <button onclick="document.getElementById('viewDetailsModal').style.display = 'none'; document.getElementById('viewDetailsModal').classList.add('hidden');" class="size-8 flex items-center justify-center rounded-lg bg-white/5 hover:bg-white/10 text-white/50 hover:text-white transition-colors border border-white/5">
                        <span class="material-symbols-outlined text-[14px]">close</span>
                    </button>
                </div>
                <p class="text-[10px] font-black uppercase tracking-[0.2em] text-white/30 mb-6">VIEW ASSIGNMENT INFORMATION</p>
            </header>
            
            <div class="flex flex-col gap-3">
                <div class="bg-white/[0.03] border border-white/5 rounded-[20px] p-6">
                    <div class="space-y-4">
                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-[0.1em] text-gray-500 mb-1.5">Target Member</p>
                            <p id="vd-member" class="text-white text-[14px] font-bold tracking-wide pl-1">...</p>
                        </div>
                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-[0.1em] text-gray-500 mb-1.5">Category</p>
                            <p id="vd-category" class="text-white text-[14px] font-bold tracking-wide pl-1" style="color:var(--primary)">...</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white/[0.03] border border-white/5 rounded-[20px] p-6">
                    <p class="text-[11px] font-bold uppercase tracking-[0.1em] text-gray-500 mb-1.5">Program Identity</p>
                    <p id="vd-name" class="text-white text-[15px] font-bold tracking-wide pl-1">...</p>
                </div>

                <div class="bg-white/[0.03] border border-white/5 rounded-[20px] p-6">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-[0.1em] text-gray-500 mb-1.5">Intensity Level</p>
                            <p id="vd-difficulty" class="text-white text-[14px] font-bold tracking-wide pl-1">...</p>
                        </div>
                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-[0.1em] text-gray-500 mb-1.5">Duration (Wks)</p>
                            <p id="vd-duration" class="text-white text-[14px] font-bold tracking-wide pl-1">...</p>
                        </div>
                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-[0.1em] text-gray-500 mb-1.5">Time (Mins)</p>
                            <p id="vd-time" class="text-white text-[14px] font-bold tracking-wide pl-1">...</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white/[0.03] border border-white/5 rounded-[20px] p-6">
                    <p class="text-[11px] font-bold uppercase tracking-[0.1em] text-gray-500 mb-1.5">Instructions & Description</p>
                    <div id="vd-desc" class="text-white/90 text-[13px] font-bold leading-relaxed whitespace-pre-line pl-1 no-scrollbar overflow-y-auto max-h-[250px]">...</div>
                </div>
                
                <div class="pt-6 flex gap-4">
                    <button type="button" onclick="document.getElementById('viewDetailsModal').style.display = 'none'; document.getElementById('viewDetailsModal').classList.add('hidden');" class="w-full h-14 rounded-2xl bg-white/5 text-gray-500 text-[10px] font-black uppercase tracking-[0.2em] hover:bg-white/10 hover:text-white transition-all">Close</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Confirmation Modal -->
<div id="confirmModal" class="bg-[--background]/40 backdrop-blur-xl hidden fixed top-0 right-0 bottom-0 left-[110px] z-[300] items-center justify-center p-4 transition-all duration-300">
    <div class="relative z-10 bg-[--card-bg] backdrop-blur-[--card-blur] w-full max-w-[400px] rounded-[32px] shadow-2xl border border-white/5 overflow-hidden transform transition-all duration-300 animate-slide-up flex flex-col">
        <div class="p-8 text-center">
            <div class="size-16 rounded-full bg-white/5 border border-white/10 text-white/50 mx-auto flex items-center justify-center mb-6">
                <span class="material-symbols-outlined text-3xl">help</span>
            </div>
            <h3 class="text-xl font-black uppercase tracking-widest text-white mb-2">Confirm Action</h3>
            <p id="confirmMessage" class="text-xs text-white/50 font-semibold mb-8">Are you sure you want to proceed?</p>
            <div class="flex gap-4">
                <button type="button" onclick="document.getElementById('confirmModal').style.display = 'none'; document.getElementById('confirmModal').classList.add('hidden');" class="flex-1 h-12 rounded-2xl bg-white/5 text-gray-500 text-[10px] font-black uppercase tracking-[0.2em] hover:bg-white/10 hover:text-white transition-all">Cancel</button>
                <button type="button" id="confirmBtn" class="flex-1 h-12 rounded-2xl bg-primary text-white text-[10px] font-black uppercase tracking-[0.2em] hover:bg-primary/90 transition-all active:scale-95 shadow-none">Yes, Proceed</button>
            </div>
        </div>
    </div>
</div>

<!-- Alert Modal -->
<div id="alertModal" class="bg-[--background]/40 backdrop-blur-xl hidden fixed top-0 right-0 bottom-0 left-[110px] z-[400] items-center justify-center p-4 transition-all duration-300">
    <div class="relative z-10 bg-[--card-bg] backdrop-blur-[--card-blur] w-full max-w-[400px] rounded-[32px] shadow-2xl border border-white/5 overflow-hidden transform transition-all duration-300 animate-slide-up flex flex-col">
        <div class="p-8 text-center">
            <div class="size-16 rounded-full bg-red-500/10 border border-red-500/20 text-red-500 mx-auto flex items-center justify-center mb-6">
                <span class="material-symbols-outlined text-3xl">error</span>
            </div>
            <h3 class="text-xl font-black uppercase tracking-widest text-white mb-2">Notice</h3>
            <p id="alertMessage" class="text-xs text-white/50 font-semibold mb-8">...</p>
            <button type="button" onclick="document.getElementById('alertModal').style.display = 'none'; document.getElementById('alertModal').classList.add('hidden');" class="w-full h-12 rounded-2xl bg-white/5 text-white text-[10px] font-black uppercase tracking-[0.2em] hover:bg-white/10 transition-all active:scale-95">Okay</button>
        </div>
    </div>
</div>

<script>
function customAlert(message) {
    document.getElementById('alertMessage').innerText = message;
    const alertModal = document.getElementById('alertModal');
    alertModal.classList.remove('hidden');
    alertModal.style.display = 'flex';
}
function confirmAction(url, message) {
    document.getElementById('confirmMessage').innerText = message;
    const confirmBtn = document.getElementById('confirmBtn');
    confirmBtn.onclick = function() {
        window.location.href = url;
    };
    const confirmModal = document.getElementById('confirmModal');
    confirmModal.classList.remove('hidden');
    confirmModal.style.display = 'flex';
}
function openDetailsModal(btn) {
    document.getElementById('vd-member').textContent = btn.dataset.member || 'N/A';
    document.getElementById('vd-category').textContent = btn.dataset.category || 'N/A';
    document.getElementById('vd-name').textContent = btn.dataset.name || 'N/A';
    document.getElementById('vd-difficulty').textContent = btn.dataset.difficulty || 'N/A';
    document.getElementById('vd-duration').textContent = btn.dataset.duration || 'N/A';
    document.getElementById('vd-time').textContent = btn.dataset.time || 'N/A';
    document.getElementById('vd-desc').textContent = btn.dataset.desc || 'N/A';
    
    const modal = document.getElementById('viewDetailsModal');
    modal.classList.remove('hidden');
    modal.style.display = 'flex';
}

function autoGenerateRoutine() {
    const category = document.getElementById('assign_w_category').value || 'Strength';
    const memberIdInput = document.getElementById('assign_m_id');
    
    if (!memberIdInput.value) {
        customAlert("Please choose a Target Member first to generate a personalized routine.");
        return;
    }
    
    const memberName = document.getElementById('assign_m_name').value.trim();
    
    const option = document.querySelector(`.custom-option[data-value="${memberIdInput.value}"]`);
    const goal = option ? option.dataset.goal : 'General Fitness';
    const injuries = option ? option.dataset.injuries : 'None';
    const musclesInput = document.getElementById('assign_target_muscles');
    const muscles = musclesInput && musclesInput.value ? musclesInput.value : (option ? option.dataset.muscles : 'Full Body');
    const diff = option ? option.dataset.diff : 'Intermediate';
    
    const exercises = {
        'Strength': ['Barbell Squats: 4 sets x 8 reps (Rest 90s)', 'Deadlifts: 3 sets x 5 reps (Rest 120s)', 'Bench Press: 4 sets x 8 reps (Rest 90s)', 'Pull-ups: 3 sets x 8-10 reps (Rest 60s)'],
        'Cardio': ['Treadmill Sprint Intervals: 30s sprint / 30s walk (10 rounds)', 'Rowing Machine: 2000m steady state', 'Jump Rope: 5 sets of 3 mins (Rest 1 min)'],
        'Hypertrophy': ['Incline Dumbbell Press: 4 sets x 12 reps', 'Leg Press: 4 sets x 15 reps', 'Lat Pulldown: 3 sets x 12 reps', 'Bicep Curls: 3 sets x 15 reps'],
        'Flexibility': ['Dynamic Stretching Routine (10 mins)', 'Yoga Flow: Sun Salutations (5 rounds)', 'Static Stretching: Hold each pose 45s'],
        'Endurance': ['Long Distance Run: 5k at moderate pace', 'Cycling: 45 mins steady zone 2', 'Burpees: 100 reps for time']
    };
    
    let list = exercises[category] || exercises['Strength'];
    
    let generated = "";
    list.forEach((ex) => {
        generated += ex + "\n";
    });
    generated = generated.trim();
    
    const textarea = document.getElementById('workout_desc_input');
    textarea.value = generated;
    
    textarea.classList.add('bg-primary/20');
    setTimeout(() => textarea.classList.remove('bg-primary/20'), 300);
}
</script>

</body>
</html>
