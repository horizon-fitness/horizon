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

// Active CMS Page
$stmtPage = $pdo->prepare("SELECT * FROM tenant_pages WHERE gym_id = ? LIMIT 1");
$stmtPage->execute([$gym_id]);
$page = $stmtPage->fetch();

// Fetch Coach ID
$stmtCoach = $pdo->prepare("SELECT coach_id FROM coaches WHERE user_id = ? AND gym_id = ? LIMIT 1");
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
    $workout_desc = trim($_POST['workout_description']);
    $scheduled_date = $_POST['scheduled_date'];
    
    try {
        $stmtAdd = $pdo->prepare("INSERT INTO member_workouts (member_id, coach_id, gym_id, workout_name, workout_description, workout_status, scheduled_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'Assigned', ?, NOW(), NOW())");
        $stmtAdd->execute([$m_id, $coach_id, $gym_id, $workout_name, $workout_desc, $scheduled_date]);
        $_SESSION['success_msg'] = "Workout assigned successfully!";
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
    exit;
}

// Seed Sample Data for Testing
if (isset($_GET['seed_demo']) && $member_id > 0) {
    try {
        $sample_workouts = [
            ['Push Day (Hypertrophy)', 'Bench Press: 3x10, DB Incline: 3x12, Lateral Raises: 4x15, Tricep Pushdowns: 3x15. Focus on mind-muscle connection.', 'Assigned', date('Y-m-d', strtotime('+1 day'))],
            ['Pull Day (Strength)', 'Deadlifts: 5x5, Pull-ups: 3xMax, Barbell Rows: 4x8, Face Pulls: 3x20. Maintain neutral spine throughout.', 'Assigned', date('Y-m-d', strtotime('+3 days'))],
            ['Leg Day (Power)', 'Back Squats: 5x5, Romanian Deadlifts: 3x10, Bulgarian Split Squats: 3x12 each, Goblet Squats: 3x15.', 'Assigned', date('Y-m-d', strtotime('+5 days'))],
            ['Metabolic Conditioning', 'Circuit: Kettlebell Swings (20), Burpees (10), Box Jumps (15), Battle Ropes (30s). 5 rounds, 60s rest.', 'Completed', date('Y-m-d', strtotime('-2 days'))],
            ['Active Recovery & Mobility', '20min walking, Cat-Cow (10), Bird-Dog (10 each), Hip Openers, Foam Rolling (Full body).', 'Completed', date('Y-m-d', strtotime('-4 days'))]
        ];

        
        $stmtSeed = $pdo->prepare("INSERT INTO member_workouts (member_id, coach_id, gym_id, workout_name, workout_description, workout_status, scheduled_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
        foreach($sample_workouts as $sw) {
            $stmtSeed->execute([$member_id, $coach_id, $gym_id, $sw[0], $sw[1], $sw[2], $sw[3]]);
        }
        $_SESSION['success_msg'] = "Sample data seeded successfully!";
        header("Location: coach_workouts.php?member_id=" . $member_id);
        exit;
    } catch (Exception $e) { $_SESSION['error_msg'] = "Seed Error: " . $e->getMessage(); }
}


// Fetch Member Details if member_id is set
$selected_member = null;
if ($member_id > 0) {
    $stmtMem = $pdo->prepare("SELECT m.*, u.first_name, u.last_name, u.email FROM members m JOIN users u ON m.user_id = u.user_id WHERE m.member_id = ? AND m.gym_id = ?");
    $stmtMem->execute([$member_id, $gym_id]);
    $selected_member = $stmtMem->fetch();
}

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

// --- RECENTLY ASSIGNED (TOP 5) ---
$recent_workouts = [];
if ($coach_id > 0) {
    $stmtRecent = $pdo->prepare("
        SELECT w.*, u.first_name, u.last_name 
        FROM member_workouts w
        JOIN members m ON w.member_id = m.member_id
        JOIN users u ON m.user_id = u.user_id
        WHERE w.coach_id = ? AND w.gym_id = ? AND w.workout_status = 'Assigned'
        ORDER BY w.created_at DESC
        LIMIT 5
    ");
    $stmtRecent->execute([$coach_id, $gym_id]);
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
        
        :root { --nav-width: 110px; }
        body:has(.sidebar-nav:hover) { --nav-width: 300px; }
        .sidebar-nav { width: var(--nav-width); transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1); overflow: hidden; display: flex; flex-direction: column; position: fixed; left: 0; top: 0; height: 100vh; z-index: 110; background: <?= $page['bg_color'] ?? '#0a090d' ?>; border-right: 1px solid rgba(255,255,255,0.05); }
        .main-content { margin-left: var(--nav-width); flex: 1; min-width: 0; transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1); height: 100vh; overflow-y: auto; }

        .nav-text { opacity: 0; transform: translateX(-15px); transition: all 0.3s ease-in-out; white-space: nowrap; pointer-events: none; }
        .sidebar-nav:hover .nav-text { opacity: 1; transform: translateX(0); pointer-events: auto; }
        .nav-section-header { max-height: 0; opacity: 0; overflow: hidden; transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); margin: 0 !important; pointer-events: none; }
        .sidebar-nav:hover .nav-section-header { max-height: 20px; opacity: 1; margin-bottom: 8px !important; pointer-events: auto; }

        .nav-link { display: flex; align-items: center; gap: 16px; padding: 12px 38px; transition: all 0.2s ease; text-decoration: none; white-space: nowrap; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; }
        .nav-link:hover { background: rgba(255,255,255,0.05); color: white; }
        .active-nav { color: <?= $page['theme_color'] ?? '#8c2bee' ?> !important; position: relative; }
        .active-nav::after { content: ''; position: absolute; right: 0px; top: 50%; transform: translateY(-50%); width: 4px; height: 20px; background: <?= $page['theme_color'] ?? '#8c2bee' ?>; border-radius: 99px; }
        
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

<nav class="sidebar-nav flex flex-col fixed left-0 top-0 h-screen z-50">
    <div class="px-7 py-8 mb-4">
        <div class="flex items-center gap-4">
            <div class="size-10 rounded-xl bg-primary flex items-center justify-center shadow-lg shadow-primary/20 shrink-0 overflow-hidden">
                <?php if (!empty($page['logo_path'])): 
                    $logo_src = (strpos($page['logo_path'], 'data:image') === 0) ? $page['logo_path'] : '../' . $page['logo_path'];
                ?>
                    <img src="<?= $logo_src ?>" class="size-full object-contain">
                <?php else: ?><span class="material-symbols-outlined text-white text-2xl" style="padding-left: 0;">bolt</span><?php endif; ?>
            </div>
            <span class="nav-text text-white font-black italic uppercase tracking-tighter text-lg leading-none">Coach Portal</span>
        </div>
    </div>
    
    <div class="flex flex-col flex-1 overflow-y-auto no-scrollbar space-y-1">
        <span class="nav-section-header px-[38px] text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Main Menu</span>
        <a href="coach_dashboard.php" class="nav-link text-gray-400 hover:text-white transition-all"><span class="material-symbols-outlined text-xl shrink-0">grid_view</span><span class="nav-text">Dashboard</span><?php if($pending_count > 0): ?><span class="size-1.5 rounded-full bg-primary animate-pulse ml-auto"></span><?php endif; ?></a>
        <a href="coach_schedule.php" class="nav-link text-gray-400 hover:text-white transition-all"><span class="material-symbols-outlined text-xl shrink-0">edit_calendar</span><span class="nav-text">Schedule</span></a>
        <a href="coach_members.php" class="nav-link text-gray-400 hover:text-white transition-all"><span class="material-symbols-outlined text-xl shrink-0">groups</span><span class="nav-text">My Members</span></a>
        <div class="nav-section-header px-[38px] mt-6 text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Training</div>
        <a href="coach_workouts.php" class="nav-link active-nav"><span class="material-symbols-outlined text-xl shrink-0">fitness_center</span><span class="nav-text">Workouts</span></a>
    </div>

    <div class="mt-auto pt-6 border-t border-white/5 flex flex-col gap-1 shrink-0 pb-8 uppercase">
        <a href="coach_profile.php" class="nav-link text-gray-400 hover:text-white transition-all"><span class="material-symbols-outlined text-xl shrink-0">account_circle</span><span class="nav-text">Profile</span></a>
        <a href="../logout.php" class="nav-link text-gray-400 hover:text-rose-500 transition-colors group"><span class="material-symbols-outlined text-xl shrink-0 group-hover:translate-x-1 transition-transform">logout</span><span class="nav-text">Sign Out</span></a>
    </div>
</nav>

<div class="main-content flex flex-col no-scrollbar">
    <main class="flex-1 p-6 md:p-12 max-w-[1400px] w-full mx-auto pb-40">
        <header class="mb-10 flex flex-col md:flex-row justify-between items-end gap-6 animate-fade-in">
            <div>
                <h2 class="text-3xl lg:text-4xl font-black italic uppercase tracking-tighter text-white leading-none">
                    <?= $selected_member ? 'Member <span class="text-primary">Programs</span>' : 'Training <span class="text-primary">Registry</span>' ?>
                </h2>
                <p class="text-gray-500 text-[10px] font-bold uppercase tracking-[0.2em] mt-2 ml-0.5">
                    <?= $selected_member ? 'Managing routines for ' . htmlspecialchars($selected_member['first_name'] . ' ' . $selected_member['last_name']) : 'Tracking all active routines for your members' ?>
                </p>
            </div>
            <div class="text-right shrink-0">
                <p id="headerClock" class="text-white font-black italic text-2xl leading-none transition-colors hover:text-primary">00:00:00 AM</p>
                <p class="text-primary text-[9px] font-black uppercase tracking-[0.2em] mt-2"><?= date('l, M d') ?></p>
            </div>
        </header>

        <!-- Member Identity Card -->
        <?php if($selected_member): ?>
        <section class="mb-10 animate-slide-up">
            <div class="glass-card p-8 border-l-4 border-primary shadow-2xl relative overflow-hidden group">
                <div class="absolute top-0 right-0 p-12 opacity-5 group-hover:opacity-10 transition-opacity">
                    <span class="material-symbols-outlined text-9xl">fitness_center</span>
                </div>
                <div class="flex items-center justify-between relative z-10">
                    <div class="flex items-center gap-8">
                        <div class="size-24 rounded-3xl bg-white/5 flex items-center justify-center text-primary font-black text-5xl italic border border-white/5 shadow-inner">
                            <?= strtoupper(substr($selected_member['first_name'], 0, 1)) ?>
                        </div>
                        <div>
                            <div class="flex items-center gap-3 mb-1">
                                <h3 class="text-3xl font-black italic uppercase text-white tracking-tighter shrink-0"><?= htmlspecialchars($selected_member['first_name'] . ' ' . $selected_member['last_name']) ?></h3>
                                <span class="px-3 py-1 rounded-lg bg-emerald-500/10 text-emerald-500 text-[8px] font-black uppercase tracking-widest border border-emerald-500/10">Active Member</span>
                            </div>
                            <p class="text-[10px] text-gray-500 font-bold uppercase tracking-[0.3em] mb-4">Member ID: #<?= str_pad($selected_member['member_id'], 5, '0', STR_PAD_LEFT) ?></p>
                            <div class="flex items-center gap-6">
                                <div class="flex flex-col"><span class="text-[8px] font-black uppercase text-gray-600 tracking-widest">Email Address</span><span class="text-xs text-gray-300 font-bold"><?= htmlspecialchars($selected_member['email']) ?></span></div>
                                <div class="w-px h-6 bg-white/5"></div>
                                <div class="flex flex-col"><span class="text-[8px] font-black uppercase text-gray-600 tracking-widest">Enrolled Since</span><span class="text-xs text-gray-300 font-bold"><?= date('M Y', strtotime($selected_member['created_at'])) ?></span></div>
                            </div>
                        </div>
                    </div>
                    <button onclick="document.getElementById('assignModal').style.display = 'flex'" class="group h-16 px-8 rounded-2xl bg-primary text-white text-[10px] font-black uppercase tracking-[0.2em] shadow-xl shadow-primary/20 hover:shadow-primary/40 hover:-translate-y-1 transition-all flex items-center gap-3 active:scale-95">
                        <span class="material-symbols-outlined text-xl">add_circle</span> Assign New Program
                    </button>
                </div>

            </div>
        </section>
        <?php endif; ?>

        <!-- Message Alerts -->

        <?php if($success_msg): ?><div class="mb-6 px-6 py-4 rounded-xl bg-emerald-500/10 border border-emerald-500/10 text-emerald-500 text-[10px] font-black uppercase tracking-widest animate-pulse"><?= $success_msg ?></div><?php endif; ?>
        <?php if($error_msg): ?><div class="mb-6 px-6 py-4 rounded-xl bg-rose-500/10 border border-rose-500/10 text-rose-500 text-[10px] font-black uppercase tracking-widest animate-pulse"><?= $error_msg ?></div><?php endif; ?>

        <!-- Tabs Navigation -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-6 mb-10">
            <div class="tabs-container">
                <button onclick="toggleTab('recentTab')" data-tab="recentTab" class="tab-btn active">Recently Assigned</button>
                <button onclick="toggleTab('historyTab')" data-tab="historyTab" class="tab-btn">Program History</button>
            </div>
            
            <?php if($member_id > 0 && count($workouts) == 0): ?>
                <button onclick="window.location.href='?member_id=<?= $member_id ?>&seed_demo=1'" class="h-11 px-6 rounded-2xl bg-white/5 border border-white/5 text-primary text-[9px] font-black uppercase tracking-widest hover:bg-primary/10 transition-all flex items-center gap-3 active:scale-95 shadow-lg">
                    <span class="material-symbols-outlined text-lg">database</span> Add Sample Testing Data
                </button>
            <?php endif; ?>
        </div>


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
                                <th>Program Details</th>
                                <th>Target Date</th>
                                <th>Status</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <?php if(count($workouts) > 0) { 
                                foreach($workouts as $w) { ?>
                            <tr>
                                <td>
                                    <p class="text-primary text-[9px] font-black uppercase tracking-widest mb-1 opacity-80"><?= htmlspecialchars($w['first_name'] . ' ' . $w['last_name']) ?></p>
                                    <p class="text-white font-black italic uppercase text-sm leading-tight mb-1 truncate max-w-[250px]"><?= htmlspecialchars($w['workout_name']) ?></p>
                                    <p class="text-[10px] text-gray-500 font-medium italic truncate max-w-sm"><?= htmlspecialchars($w['workout_description'] ?: 'No instructions.') ?></p>
                                </td>
                                <td><p class="text-gray-300 font-bold uppercase italic text-xs"><?= $w['scheduled_date'] ? date('M d, Y', strtotime($w['scheduled_date'])) : 'Anytime' ?></p></td>
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
    <div class="glass-card w-full max-w-xl p-10 animate-slide-up border border-white/10 shadow-[0_0_100px_rgba(0,0,0,0.5)]">
        <header class="flex items-center justify-between mb-8"><h3 class="text-xl font-black italic uppercase tracking-tighter">Assign <span class="text-primary">Program</span></h3><button onclick="document.getElementById('assignModal').style.display = 'none'" class="size-10 rounded-xl bg-white/5 hover:bg-white/10 flex items-center justify-center text-gray-500 hover:text-white transition-all"><span class="material-symbols-outlined text-xl">close</span></button></header>
        <form action="" method="POST" class="space-y-8">
            <input type="hidden" name="m_id" value="<?= $member_id ?>">
            <div class="space-y-3"><label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Routine Identity</label><input type="text" name="workout_name" placeholder="e.g. Strength Training Phase 1" class="modal-input w-full" required></div>
            <div class="space-y-3"><label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Program Details</label><textarea name="workout_description" rows="4" placeholder="Exercises, sets, reps..." class="modal-input w-full resize-none no-scrollbar"></textarea></div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8 focus-within:z-10"><div class="space-y-3"><label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Target Start Date</label><input type="date" name="scheduled_date" class="modal-input w-full" value="<?= date('Y-m-d') ?>" required></div></div>
            <div class="pt-4"><button type="submit" name="assign_workout" class="w-full h-16 bg-primary hover:bg-primary/90 text-white rounded-2xl text-xs font-black uppercase italic tracking-[0.2em] shadow-xl shadow-primary/20 transition-all active:scale-95">Confirm Assignment</button></div>
        </form>
    </div>
</div>
</body>
</html>
