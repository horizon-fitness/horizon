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

// Fetch Gym Details
$stmtGym = $pdo->prepare("SELECT * FROM gyms WHERE gym_id = ?");
$stmtGym->execute([$gym_id]);
$gym = $stmtGym->fetch();

// Fetch Gym & Owner Details for Branding
$stmtGymBranding = $pdo->prepare("SELECT owner_user_id, gym_name FROM gyms WHERE gym_id = ?");
$stmtGymBranding->execute([$gym_id]);
$gym_data = $stmtGymBranding->fetch();
$owner_user_id = $gym_data['owner_user_id'] ?? 0;

$configs = [
    'system_logo'     => '',
    'theme_color'     => '#8c2bee',
    'bg_color'        => '#0a090d',
];

// Fetch tenant-specific settings
$stmtSettings = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = ?");
$stmtSettings->execute([$owner_user_id]);
foreach (($stmtSettings->fetchAll(PDO::FETCH_KEY_PAIR) ?: []) as $k => $v) {
    if ($v !== null && $v !== '') $configs[$k] = $v;
}

$page = [
    'logo_path'   => $configs['system_logo'] ?? '',
    'theme_color' => $configs['theme_color'],
    'bg_color'    => $configs['bg_color'],
];

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



// Fetch Member Details if member_id is set
$selected_member = null;
if ($member_id > 0) {
    $stmtMem = $pdo->prepare("SELECT m.*, u.first_name, u.last_name, u.email FROM members m JOIN users u ON m.user_id = u.user_id WHERE m.member_id = ? AND m.gym_id = ?");
    $stmtMem->execute([$member_id, $gym_id]);
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
    
    if ($member_id > 0) {
        $recent_where[] = "w.member_id = ?";
        $recent_params[] = $member_id;
    }

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
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "<?= $page['theme_color'] ?? '#8c2bee' ?>", "background-dark": "<?= $page['bg_color'] ?? '#0a090d' ?>", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)" }}}
        }
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: <?= $page['bg_color'] ?? '#0a090d' ?>; color: white; display: flex; flex-direction: row; min-h-screen: 100vh; overflow: hidden; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        
        .side-nav { width: 110px; transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1); overflow: hidden; display: flex; flex-direction: column; position: fixed; left: 0; top: 0; height: 100vh; z-index: 110; background: <?= $page['bg_color'] ?? '#0a090d' ?>; border-right: 1px solid rgba(255,255,255,0.05); }
        .side-nav:hover { width: 300px; }
        .main-content { margin-left: 110px; flex: 1; min-width: 0; transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1); height: 100vh; overflow-y: auto; }
        .side-nav:hover ~ .main-content { margin-left: 300px; }

        .nav-label { opacity: 0; transform: translateX(-15px); transition: all 0.3s ease-in-out; white-space: nowrap; pointer-events: none; }
        .side-nav:hover .nav-label { opacity: 1; transform: translateX(0); pointer-events: auto; }
        .nav-section-label { max-height: 0; opacity: 0; overflow: hidden; transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); margin: 0 !important; pointer-events: none; }
        .side-nav:hover .nav-section-label { max-height: 20px; opacity: 1; margin-bottom: 8px !important; pointer-events: auto; }

        .nav-item { display: flex; align-items: center; gap: 16px; padding: 12px 38px; transition: all 0.2s ease; text-decoration: none; white-space: nowrap; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; }
        .nav-item:hover { background: rgba(255,255,255,0.05); color: white; }
        .nav-item.active { color: <?= $page['theme_color'] ?? '#8c2bee' ?> !important; position: relative; }
        .nav-item.active::after { content: ''; position: absolute; right: 0px; top: 50%; transform: translateY(-50%); width: 4px; height: 20px; background: <?= $page['theme_color'] ?? '#8c2bee' ?>; border-radius: 99px; }
        
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
        
        .glass-table { width: 100%; border-collapse: collapse; }
        .glass-table tr { transition: all 0.3s; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .glass-table tr:hover { background: rgba(255,255,255,0.02); }
        .glass-table th { padding: 16px 32px; font-size: 10px; font-weight: 900; text-transform: uppercase; letter-spacing: 0.15em; color: #475569; text-align: left; background: rgba(255,255,255,0.01); }
        .glass-table td { padding: 16px 32px; font-size: 11px; }
        .glass-table th:last-child, .glass-table td:last-child { text-align: right; padding-right: 32px; width: 1%; white-space: nowrap; }

        .view-btn { padding: 10px; border-radius: 10px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.05); color: #475569; transition: all 0.2s; }
        .view-btn.active { background: <?= $page['theme_color'] ?? '#8c2bee' ?>; color: white; box-shadow: 0 4px 20px <?= $page['theme_color'] ?? '#8c2bee' ?>44; border-color: transparent; }

        ::-webkit-calendar-picker-indicator { filter: invert(1) brightness(0.8); opacity: 0.6; cursor: pointer; }

        /* Tabs Styling - Segmented Pill */
        .tabs-container { display: inline-flex; background: rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.05); border-radius: 16px; padding: 6px; gap: 4px; }
        .tab-btn { padding: 10px 24px; font-size: 9px; font-weight: 900; text-transform: uppercase; letter-spacing: 0.15em; color: #4b5563; border-radius: 12px; transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); cursor: pointer; border: none; background: transparent; }
        .tab-btn:hover:not(.active) { color: white; background: rgba(255,255,255,0.03); }
        .tab-btn.active { color: white; background: <?= $page['theme_color'] ?? '#8c2bee' ?>; box-shadow: 0 4px 20px <?= $page['theme_color'] ?? '#8c2bee' ?>44; }
        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeIn 0.4s ease-out; }

        @keyframes fadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }
    </style>

    <script>
        function toggleTab(tabId) {
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            document.querySelector(`[data-tab="${tabId}"]`).classList.add('active');
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
    </script>

</head>
<body class="antialiased flex min-h-screen">

<?php include '../includes/coach_sidebar.php'; ?>

<div class="main-content flex flex-col no-scrollbar">
    <main class="flex-1 p-6 md:p-12 max-w-[1400px] w-full mx-auto pb-40">
        <header class="mb-10 flex flex-col md:flex-row justify-between items-end gap-6 animate-fade-in">
            <div>
                <h2 class="text-3xl lg:text-4xl font-black italic uppercase tracking-tighter text-white leading-none">
                    <?= $selected_member ? 'Member <span class="text-primary">Programs</span>' : 'Training <span class="text-primary">Registry</span>' ?>
                </h2>
                <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-2 px-1">
                    <?= $selected_member ? 'Managing routines for ' . htmlspecialchars($selected_member['first_name'] . ' ' . $selected_member['last_name']) : 'Select a member to manage their routines or assign new programs' ?>
                </p>
            </div>
            <div class="flex items-center gap-4">
                <div class="text-right shrink-0 ml-4">
                    <p id="headerClock" class="text-white font-black italic text-2xl leading-none transition-colors hover:text-primary">00:00:00 AM</p>
                    <p class="text-primary text-[10px] font-black uppercase tracking-[0.2em] leading-none mt-2"><?= date('l, M d, Y') ?></p>
                </div>
            </div>
        </header>


        <!-- Message Alerts -->

        <?php if($success_msg): ?><div class="mb-6 px-6 py-4 rounded-xl bg-emerald-500/10 border border-emerald-500/10 text-emerald-500 text-[10px] font-black uppercase tracking-widest animate-pulse"><?= $success_msg ?></div><?php endif; ?>
        <?php if($error_msg): ?><div class="mb-6 px-6 py-4 rounded-xl bg-rose-500/10 border border-rose-500/10 text-rose-500 text-[10px] font-black uppercase tracking-widest animate-pulse"><?= $error_msg ?></div><?php endif; ?>

        <!-- Navigation & Search Section -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-6 mb-8">
            <div class="bg-[#14121a]/50 p-2 rounded-[22px] border border-white/5 backdrop-blur-md">
                <div class="tabs-container !border-none !bg-transparent">
                    <button onclick="toggleTab('recentTab')" data-tab="recentTab" class="tab-btn active">Recently Assigned</button>
                    <button onclick="toggleTab('historyTab')" data-tab="historyTab" class="tab-btn">Program History</button>
                </div>
            </div>
            
            <div class="flex items-center gap-4 flex-1 max-w-2xl">
                <div class="flex-1 relative group z-[100]">
                    <div class="bg-[#14121a]/50 p-2 rounded-[22px] border border-white/5 backdrop-blur-md flex items-center gap-3 pr-4 focus-within:border-primary/30 transition-all">
                        <span class="material-symbols-outlined ml-3 text-gray-500 text-lg group-focus-within:text-primary transition-colors">person_search</span>
                        <input type="text" id="memberSearchInput" placeholder="Search student name..." class="flex-1 h-10 bg-transparent text-white text-[10px] font-black uppercase tracking-widest outline-none placeholder:text-gray-600" autocomplete="off" onfocus="document.getElementById('memberSearchDropdown').classList.remove('hidden')" oninput="filterMembers(this.value)">
                        <span class="material-symbols-outlined text-gray-600 text-sm">expand_more</span>
                    </div>
                    
                    <!-- Custom Dropdown -->
                    <div id="memberSearchDropdown" class="absolute top-full left-0 right-0 mt-3 hidden bg-[#1a1821] border border-white/10 rounded-2xl shadow-2xl shadow-black/50 overflow-hidden backdrop-blur-xl max-h-[300px] overflow-y-auto no-scrollbar animate-slide-up">
                        <div class="p-2 space-y-1" id="memberList">
                            <div class="px-4 py-3 text-[8px] font-black text-gray-500 uppercase tracking-widest border-b border-white/5 mb-1">Select Student</div>
                            <?php foreach($all_members as $m): ?>
                                <div class="member-item px-4 py-3 rounded-xl hover:bg-primary/10 hover:text-white text-gray-400 text-[10px] font-black uppercase tracking-widest cursor-pointer transition-all flex items-center gap-3 group/item" onclick="window.location.href='?member_id=<?= $m['member_id'] ?>'">
                                    <div class="size-6 rounded-lg bg-white/5 flex items-center justify-center text-[10px] group-hover/item:bg-primary/20 group-hover/item:text-primary transition-colors">
                                        <?= strtoupper(substr($m['first_name'], 0, 1)) ?>
                                    </div>
                                    <?= htmlspecialchars($m['first_name'] . ' ' . $m['last_name']) ?>
                                </div>
                            <?php endforeach; ?>
                            <div id="noResults" class="hidden px-6 py-8 text-center text-[10px] text-gray-600 italic font-bold uppercase tracking-widest">No students found...</div>
                        </div>
                    </div>
                </div>

                <button onclick="document.getElementById('assignModal').style.display = 'flex'" class="h-[58px] px-6 rounded-[22px] bg-primary text-white text-[9px] font-black uppercase tracking-[0.2em] shadow-xl shadow-primary/20 hover:shadow-primary/40 hover:-translate-y-1 transition-all flex items-center gap-2 active:scale-95 shrink-0">
                    <span class="material-symbols-outlined text-lg">add_circle</span> Assign
                </button>
            </div>
        </div>

        <script>
            function filterMembers(val) {
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

            // Close dropdown when clicking outside
            document.addEventListener('click', (e) => {
                if (!e.target.closest('.group')) {
                    document.getElementById('memberSearchDropdown').classList.add('hidden');
                }
            });
        </script>

        <!-- Member Identity Card (Compact & Below Tabs) -->
        <?php if($selected_member): ?>
        <section class="mb-10 animate-slide-up">
            <div class="glass-card p-6 border-l-2 border-primary shadow-xl relative overflow-hidden group bg-gradient-to-r from-[#14121a] to-transparent">
                <div class="absolute top-0 right-0 p-8 opacity-[0.03] group-hover:opacity-[0.07] transition-opacity pointer-events-none">
                    <span class="material-symbols-outlined text-8xl">fitness_center</span>
                </div>
                <div class="flex items-center justify-between relative z-10">
                    <div class="flex items-center gap-6">
                        <div class="size-16 rounded-2xl bg-primary/10 flex items-center justify-center text-primary font-black text-2xl italic border border-primary/10 shadow-inner">
                            <?= strtoupper(substr($selected_member['first_name'], 0, 1)) ?>
                        </div>
                        <div>
                            <div class="flex items-center gap-3 mb-0.5">
                                <h3 class="text-xl font-black italic uppercase text-white tracking-tighter"><?= htmlspecialchars($selected_member['first_name'] . ' ' . $selected_member['last_name']) ?></h3>
                                <span class="px-2 py-0.5 rounded-md bg-emerald-500/10 text-emerald-500 text-[7px] font-black uppercase tracking-widest border border-emerald-500/10">Active</span>
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
        </section>
        <?php endif; ?>


        <div id="recentTab" class="tab-content active">
            <!-- Recently Assigned Section -->
            <?php if(!empty($recent_workouts)): ?>
            <section class="mb-12">

            <h4 class="text-[10px] font-black uppercase text-gray-500 tracking-[0.2em] mb-4 ml-1">Recently Assigned</h4>
            <div class="glass-card overflow-hidden">
                <div class="overflow-x-auto no-scrollbar">
                    <table class="glass-table">
                        <thead>
                            <tr>
                                <th>Assignment</th>
                                <th>Member</th>
                                <th>Assigned Date</th>
                                <th class="text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <?php foreach($recent_workouts as $rw): ?>
                            <tr>
                                <td>
                                    <p class="text-white font-black italic uppercase text-xs truncate max-w-[200px]"><?= htmlspecialchars($rw['workout_name']) ?></p>
                                </td>
                                <td>
                                    <div class="flex items-center gap-2">
                                        <div class="size-6 rounded-lg bg-primary/10 flex items-center justify-center">
                                            <span class="material-symbols-outlined text-[10px] text-primary">person</span>
                                        </div>
                                        <p class="text-[10px] font-bold text-gray-300"><?= htmlspecialchars($rw['first_name'] . ' ' . $rw['last_name']) ?></p>
                                    </div>
                                </td>
                                <td>
                                    <div class="flex items-center gap-2">
                                        <span class="material-symbols-outlined text-[12px] text-gray-600">event</span>
                                        <p class="text-[10px] font-bold text-gray-500 italic"><?= date('M d, Y', strtotime($rw['created_at'])) ?></p>
                                    </div>
                                </td>
                                <td>
                                    <div class="flex justify-end">
                                        <a href="?member_id=<?= $member_id ?>&action=update_status&workout_id=<?= $rw['workout_id'] ?>&status=Completed" class="size-8 rounded-lg bg-emerald-500/10 text-emerald-500 flex items-center justify-center hover:bg-emerald-500 hover:text-white transition-all active:scale-95" title="Mark as Completed">
                                            <span class="material-symbols-outlined text-[14px]">check_circle</span>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <?php else: ?><div class="py-20 text-center opacity-40 italic text-[10px] uppercase tracking-widest">No recent assignments found.</div><?php endif; ?>
        </div>

        <div id="historyTab" class="tab-content">
            <!-- Filters Section -->
            <section class="filter-container animate-slide-up">

            <form id="filterForm" method="GET" class="grid grid-cols-1 md:grid-cols-12 gap-6 items-end">
                <input type="hidden" name="member_id" value="<?= $member_id ?>">
                <div class="md:col-span-6 space-y-2">
                    <label class="text-[9px] font-black uppercase tracking-[0.1em] text-gray-500 ml-1">Search Program</label>
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-gray-600 text-lg">search</span>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search by member or plan name..." class="input-box pl-12" oninput="debounce(triggerFilter, 500)">
                    </div>
                </div>
                <div class="md:col-span-3 space-y-2">
                    <label class="text-[9px] font-black uppercase tracking-[0.1em] text-gray-500 ml-1">Filter Status</label>
                    <select name="status" class="input-box pr-10" onchange="triggerFilter()">
                        <option value="">All Status</option>
                        <option value="Assigned" <?= $status_filter === 'Assigned' ? 'selected' : '' ?>>Assigned</option>
                        <option value="Completed" <?= $status_filter === 'Completed' ? 'selected' : '' ?>>Completed</option>
                    </select>
                </div>
                <div class="md:col-span-3 space-y-2">
                    <label class="text-[9px] font-black uppercase tracking-[0.1em] text-gray-500 ml-1">Sort By</label>
                    <div class="flex gap-2">
                        <select name="sort" class="input-box pr-10 flex-1" onchange="triggerFilter()">
                            <option value="recent" <?= $sort_by === 'recent' ? 'selected' : '' ?>>Newest First</option>
                            <option value="oldest" <?= $sort_by === 'oldest' ? 'selected' : '' ?>>Oldest First</option>
                            <option value="name_asc" <?= $sort_by === 'name_asc' ? 'selected' : '' ?>>Member A-Z</option>
                        </select>
                        <a href="coach_workouts.php?member_id=<?= $member_id ?>" class="size-[42px] rounded-xl bg-white/5 border border-white/5 flex items-center justify-center text-gray-500 hover:text-rose-500 transition-colors hover:bg-rose-500/10" title="Reset Filters"><span class="material-symbols-outlined text-xl">restart_alt</span></a>
                    </div>
                </div>
            </form>
        </section>


        <!-- History Table Section -->
        <div>
            <h4 class="text-[10px] font-black uppercase text-gray-500 tracking-[0.2em] mb-4 ml-1">Training History</h4>
            <div class="glass-card overflow-hidden animate-slide-up">
                <div class="overflow-x-auto no-scrollbar">
                    <table class="glass-table">
                        <thead>
                            <tr>
                                <th>Identity & Category</th>
                                <th>Intensity & Time</th>
                                <th>Status</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <?php if(count($workouts) > 0) { 
                                foreach($workouts as $w) { ?>
                            <tr>
                                <td>
                                    <p class="text-white/[0.4] text-[9px] font-black uppercase tracking-widest mb-1 italic"><?= htmlspecialchars($w['first_name'] . ' ' . $w['last_name']) ?></p>
                                    <p class="text-white font-black italic uppercase text-sm leading-tight mb-1 truncate max-w-[250px]"><?= htmlspecialchars($w['workout_name']) ?></p>
                                    <p class="text-[9px] font-black uppercase tracking-[0.2em] text-primary"><?= htmlspecialchars($w['workout_category'] ?? 'General') ?></p>
                                </td>
                                <td>
                                    <div class="flex flex-col">
                                        <p class="text-gray-300 font-bold uppercase italic text-[11px]"><?= htmlspecialchars($w['difficulty_level'] ?? 'Intermediate') ?></p>
                                        <p class="text-[10px] text-gray-500 font-black uppercase tracking-widest"><?= $w['estimated_minutes'] ?? 60 ?> Mins / <?= $w['duration_weeks'] ?? 4 ?> Wks</p>
                                    </div>
                                </td>
                                <td>
                                    <?php $ws = $w['workout_status']; $sc = "text-primary bg-primary/10 border-primary/10"; if($ws == 'Completed') $sc = "text-emerald-500 bg-emerald-500/10 border-emerald-500/10"; ?>
                                    <span class="px-3 py-1.5 rounded-lg text-[9px] font-black uppercase tracking-widest border border-white/5 <?= $sc ?>"><?= $ws ?></span>
                                </td>
                                <td>
                                    <div class="flex items-center justify-end gap-2">
                                        <?php if($w['workout_status'] != 'Completed'): ?><a href="?member_id=<?= $member_id ?>&action=update_status&workout_id=<?= $w['workout_id'] ?>&status=Completed" class="size-10 rounded-xl bg-emerald-500/10 text-emerald-500 border border-emerald-500/10 flex items-center justify-center hover:bg-emerald-500 hover:text-white transition-all active:scale-90" title="Complete Program"><span class="material-symbols-outlined text-lg">check_circle</span></a><?php endif; ?>
                                        <?php if($w['workout_status'] != 'Assigned'): ?><a href="?member_id=<?= $member_id ?>&action=update_status&workout_id=<?= $w['workout_id'] ?>&status=Assigned" class="size-10 rounded-xl bg-primary/10 text-primary border border-primary/10 flex items-center justify-center hover:bg-primary hover:text-white transition-all active:scale-90" title="Re-assign Program"><span class="material-symbols-outlined text-lg">refresh</span></a><?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                                <?php } 
                            } else { ?>
                            <tr><td colspan="4" class="p-24 text-center opacity-30 italic text-xs tracking-widest">No programs logged</td></tr>
                            <?php } ?>
                        </tbody>

                    </table>
                </div>
            </div>
        </div>
    </div>



    </main>
</div>

<!-- Assign Modal -->
<div id="assignModal" class="fixed inset-0 bg-black/90 backdrop-blur-3xl z-[200] hidden items-center justify-center p-6">
    <div class="glass-card w-full max-w-2xl p-12 animate-slide-up border border-white/10 shadow-[0_0_100px_rgba(0,0,0,0.5)]">
        <header class="flex items-center justify-between mb-10">
            <div>
                <h3 class="text-2xl font-black italic uppercase tracking-tighter">New <span class="text-primary">Program</span></h3>
                <p class="text-[10px] text-gray-500 font-black uppercase tracking-widest mt-1">Assign professional training routine to a member</p>
            </div>
            <button onclick="document.getElementById('assignModal').style.display = 'none'" class="size-10 rounded-xl bg-white/5 hover:bg-white/10 flex items-center justify-center text-gray-500 hover:text-white transition-all"><span class="material-symbols-outlined text-xl">close</span></button>
        </header>
        
        <form action="" method="POST" class="space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="space-y-2">
                    <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Target Member</label>
                    <div class="relative">
                        <select name="m_id" class="modal-input w-full cursor-pointer pl-10" required>
                            <option value="">--- Choose Member ---</option>
                            <?php foreach($all_members as $m): ?>
                                <option value="<?= $m['member_id'] ?>" <?= $member_id == $m['member_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($m['first_name'] . ' ' . $m['last_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-lg text-gray-600">person</span>
                    </div>
                </div>
                <div class="space-y-2">
                    <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Category</label>
                    <div class="relative">
                        <select name="workout_category" class="modal-input w-full cursor-pointer pl-10">
                            <option value="Strength">Strength Training</option>
                            <option value="Cardio">Cardio & HIIT</option>
                            <option value="Hypertrophy">Hypertrophy</option>
                            <option value="Flexibility">Flexibility / Yoga</option>
                            <option value="Endurance">Endurance</option>
                        </select>
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-lg text-gray-600">category</span>
                    </div>
                </div>
            </div>

            <div class="space-y-2">
                <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Program Identity</label>
                <div class="relative">
                    <input type="text" name="workout_name" placeholder="e.g. Advanced Push-Pull Protocol" class="modal-input w-full pl-10" required>
                    <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-lg text-gray-600">edit_note</span>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="space-y-2">
                    <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Intensity Level</label>
                    <select name="difficulty_level" class="modal-input w-full cursor-pointer">
                        <option value="Beginner">Beginner</option>
                        <option value="Intermediate" selected>Intermediate</option>
                        <option value="Advanced">Advanced</option>
                    </select>
                </div>
                <div class="space-y-2">
                    <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Duration (Wks)</label>
                    <input type="number" name="duration_weeks" value="4" min="1" class="modal-input w-full">
                </div>
                <div class="space-y-2">
                    <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Time (Mins)</label>
                    <input type="number" name="estimated_minutes" value="60" min="15" class="modal-input w-full">
                </div>
            </div>

            <div class="space-y-2">
                <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Instructions & Description</label>
                <textarea name="workout_description" rows="3" placeholder="Rest times, reps, sets..." class="modal-input w-full resize-none no-scrollbar"></textarea>
            </div>

            <input type="hidden" name="scheduled_date" value="<?= date('Y-m-d') ?>">
            
            <div class="pt-6 flex gap-4">
                <button type="button" onclick="document.getElementById('assignModal').style.display = 'none'" class="flex-1 h-14 rounded-2xl bg-white/5 text-gray-500 text-[10px] font-black uppercase tracking-[0.2em] hover:bg-white/10 transition-all">Cancel</button>
                <button type="submit" name="assign_workout" class="flex-[2] h-14 rounded-2xl bg-primary text-white text-[10px] font-black uppercase tracking-[0.2em] shadow-xl shadow-primary/20 hover:shadow-primary/40 hover:-translate-y-1 transition-all active:scale-95">Complete Assignment</button>
            </div>
        </form>
    </div>
</div>
</body>
</html>
