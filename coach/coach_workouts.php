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

// Active CMS Page (for logo & theme)
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
    } catch (Exception $e) {
        $_SESSION['error_msg'] = "Error: " . $e->getMessage();
    }
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

// Fetch Workout History
$workouts = [];
if ($member_id > 0) {
    $stmtWork = $pdo->prepare("SELECT * FROM member_workouts WHERE member_id = ? AND coach_id = ? ORDER BY scheduled_date DESC, created_at DESC");
    $stmtWork->execute([$member_id, $coach_id]);
    $workouts = $stmtWork->fetchAll();
} else {
    // If no specific member, show all workouts assigned by this coach
    $stmtWork = $pdo->prepare("
        SELECT w.*, u.first_name, u.last_name 
        FROM member_workouts w
        JOIN members m ON w.member_id = m.member_id
        JOIN users u ON m.user_id = u.user_id
        WHERE w.coach_id = ? AND w.gym_id = ?
        ORDER BY w.scheduled_date DESC
    ");
    $stmtWork->execute([$coach_id, $gym_id]);
    $workouts = $stmtWork->fetchAll();
}

$success_msg = $_SESSION['success_msg'] ?? '';
$error_msg = $_SESSION['error_msg'] ?? '';
unset($_SESSION['success_msg'], $_SESSION['error_msg']);
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
            theme: { extend: { colors: { "primary": "<?= $page['theme_color'] ?? '#8c2bee' ?>", "background-dark": "#0a090d", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)" }}}
        }
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; display: flex; flex-direction: row; min-h-screen: 100vh; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        
        /* Sidebar Hover Logic - MATCHING CORE DASHBOARD */
        .sidebar-nav {
            width: 110px; 
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            position: fixed;
            left: 0;
            top: 0;
            height: 100vh;
            z-index: 50;
        }
        .sidebar-nav:hover {
            width: 300px; 
        }

        /* Main Content Adjustment */
        .main-content {
            margin-left: 110px; 
            flex: 1;
            min-width: 0;
            transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .sidebar-nav:hover ~ .main-content {
            margin-left: 300px; 
        }

        .nav-text {
            opacity: 0;
            transform: translateX(-15px);
            transition: all 0.3s ease-in-out;
            white-space: nowrap;
            pointer-events: none;
        }
        .sidebar-nav:hover .nav-text {
            opacity: 1;
            transform: translateX(0);
            pointer-events: auto;
        }

        .nav-section-header {
            max-height: 0;
            opacity: 0;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            margin: 0 !important;
            pointer-events: none;
        }
        .sidebar-nav:hover .nav-section-header {
            max-height: 20px;
            opacity: 1;
            margin-bottom: 0px !important; 
            pointer-events: auto;
        }
        .sidebar-nav:hover .mt-0 { margin-top: 0px !important; }

        .nav-link { 
            display: flex; 
            align-items: center; 
            gap: 16px; 
            padding: 10px 38px; 
            transition: all 0.2s ease; 
            text-decoration: none; 
            white-space: nowrap; 
            font-size: 13px; 
            font-weight: 700; 
            letter-spacing: 0.02em; 
            color: #94a3b8;
        }
        .nav-link:hover { background: rgba(255,255,255,0.05); color: white; }
        .active-nav { color: <?= $page['theme_color'] ?? '#8c2bee' ?> !important; position: relative; }
        .active-nav::after { 
            content: ''; 
            position: absolute; 
            right: 0px; 
            top: 50%;
            transform: translateY(-50%);
            width: 4px; 
            height: 24px; 
            background: <?= $page['theme_color'] ?? '#8c2bee' ?>; 
            border-radius: 4px 0 0 4px; 
        }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        .modal-input { background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.05); border-radius: 12px; padding: 12px 16px; color: white; font-size: 13px; transition: all 0.2s; }
        .modal-input:focus { border-color: #8c2bee; outline: none; background: rgba(140,43,238,0.05); }

        @keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .animate-slide-up { animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1); }
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
    </script>
</head>
<body class="antialiased flex flex-col lg:flex-row min-h-screen">

<nav class="sidebar-nav flex flex-col fixed left-0 top-0 h-screen bg-[#0a090d] border-r border-white/5 z-50">
    <div class="px-7 py-8">
        <div class="flex items-center gap-[6px]">
            <div class="size-10 rounded-xl bg-primary flex items-center justify-center shadow-lg shrink-0 overflow-hidden">
                <?php if (!empty($page['logo_path'])): 
                    $logo_src = (strpos($page['logo_path'], 'data:image') === 0) ? $page['logo_path'] : '../' . $page['logo_path'];
                ?>
                    <img src="<?= $logo_src ?>" class="size-full object-contain">
                <?php else: ?>
                    <span class="material-symbols-outlined text-white text-2xl">bolt</span>
                <?php endif; ?>
            </div>
            <span class="nav-text text-white font-black italic uppercase tracking-tighter text-base leading-none">Coach Dashboard</span>
        </div>
    </div>
    
    <div class="flex flex-col flex-1 overflow-y-auto no-scrollbar gap-0.5">
        <span class="nav-section-header text-[10px] font-black text-gray-500 uppercase tracking-widest px-[38px] mt-0">Main Menu</span>
        
        <a href="coach_dashboard.php" class="nav-link <?= (basename($_SERVER['PHP_SELF']) == 'coach_dashboard.php') ? 'active-nav' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">grid_view</span> 
            <span class="nav-text">Dashboard</span>
            <?php if($pending_count > 0): ?><span class="size-1.5 rounded-full bg-primary alert-dot ml-auto"></span><?php endif; ?>
        </a>
        
        <a href="coach_schedule.php" class="nav-link <?= (basename($_SERVER['PHP_SELF']) == 'coach_schedule.php') ? 'active-nav' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">edit_calendar</span> 
            <span class="nav-text">My Availability</span>
        </a>

        <a href="coach_members.php" class="nav-link <?= (basename($_SERVER['PHP_SELF']) == 'coach_members.php') ? 'active-nav' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">groups</span> 
            <span class="nav-text">My Members</span>
        </a>

        <div class="nav-section-header text-[10px] font-black text-gray-500 uppercase tracking-widest px-[38px] mt-4 mb-2">Training</div>

        <a href="coach_workouts.php" class="nav-link <?= (basename($_SERVER['PHP_SELF']) == 'coach_workouts.php') ? 'active-nav' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">fitness_center</span> 
            <span class="nav-text">Workouts</span>
        </a>
    </div>

    <div class="mt-auto pt-4 border-t border-white/10 flex flex-col gap-1 shrink-0 pb-6">
        <span class="nav-section-header text-[10px] font-black text-gray-500 uppercase tracking-widest px-[38px] mt-0 mb-2">Account</span>

        <a href="coach_profile.php" class="nav-link <?= (basename($_SERVER['PHP_SELF']) == 'coach_profile.php') ? 'active-nav' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">account_circle</span> 
            <span class="nav-text">Profile</span>
        </a>

        <a href="../logout.php" class="nav-link text-gray-400 hover:text-rose-500 transition-colors group">
            <span class="material-symbols-outlined text-xl shrink-0 group-hover:translate-x-1 transition-transform">logout</span>
            <span class="nav-text">Sign Out</span>
        </a>
    </div>
</nav>

<div class="main-content flex flex-col min-w-0">
    <main class="flex-1 p-6 md:p-10 max-w-[1400px] w-full mx-auto">
        <header class="mb-10 flex flex-col md:flex-row justify-between items-start md:items-end gap-6">
            <div>
                <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white leading-none">
                    <?= $selected_member ? 'Member Workouts' : 'All Workouts' ?>
                </h2>
                <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-2">
                    <?= $selected_member ? 'Managing routines for ' . htmlspecialchars($selected_member['first_name'] . ' ' . $selected_member['last_name']) : 'Tracking all your assigned routines' ?>
                </p>
            </div>
            <div class="flex flex-row items-center gap-4">
                <div class="px-6 py-3.5 rounded-2xl bg-white/5 border border-white/5 text-right flex flex-col items-end group shadow-sm hover:shadow-primary/10 transition-shadow">
                    <p id="headerClock" class="text-white font-black italic text-xl leading-none mb-1 group-hover:text-primary transition-colors">00:00:00 AM</p>
                    <p class="text-gray-500 text-[9px] font-black uppercase tracking-[0.2em] group-hover:text-white transition-colors"><?= date('l, M d') ?></p>
                </div>
                <?php if($selected_member): ?>
                    <button onclick="document.getElementById('assignModal').classList.remove('hidden')" class="bg-primary hover:bg-primary/90 text-white px-6 py-3 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all shadow-lg active:scale-95 flex items-center gap-2">
                        <span class="material-symbols-outlined text-sm">add_circle</span> Assign New
                    </button>
                <?php endif; ?>
            </div>
        </header>

    <?php if($success_msg): ?>
        <div class="mb-8 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-[10px] font-black uppercase tracking-widest flex items-center gap-3 animate-fade-in">
            <span class="material-symbols-outlined text-sm">check_circle</span> <?= htmlspecialchars($success_msg) ?>
        </div>
    <?php endif; ?>

    <div class="glass-card overflow-hidden shadow-2xl animate-slide-up">
        <div class="p-8 border-b border-white/5 bg-white/5 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
            <h3 class="text-lg font-black italic uppercase tracking-tight">Workout Records</h3>
            <?php if(!$selected_member): ?>
                <span class="text-[10px] font-black uppercase text-gray-500 tracking-widest">Showing all member workouts</span>
            <?php endif; ?>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="text-[10px] font-black uppercase text-gray-500 tracking-widest bg-black/20">
                        <th class="px-8 py-5"><?= $selected_member ? 'Workout Name' : 'Member / Workout' ?></th>
                        <th class="px-8 py-5">Scheduled Date</th>
                        <th class="px-8 py-5">Status</th>
                        <th class="px-8 py-5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php if(count($workouts) > 0): foreach($workouts as $w): ?>
                    <tr class="hover:bg-white/[0.02] transition-colors group">
                        <td class="px-8 py-6">
                            <?php if(!$selected_member): ?>
                                <p class="text-primary text-[10px] font-black uppercase mb-1"><?= htmlspecialchars($w['first_name'] . ' ' . $w['last_name']) ?></p>
                            <?php endif; ?>
                            <p class="text-white font-black uppercase italic"><?= htmlspecialchars($w['workout_name']) ?></p>
                            <p class="text-[9px] text-gray-600 font-bold uppercase truncate max-w-xs"><?= htmlspecialchars($w['workout_description']) ?></p>
                        </td>
                        <td class="px-8 py-6">
                            <p class="text-gray-300 text-sm font-bold uppercase italic"><?= $w['scheduled_date'] ? date('M d, Y', strtotime($w['scheduled_date'])) : 'Anytime' ?></p>
                        </td>
                        <td class="px-8 py-6">
                            <?php 
                                $statusColor = "text-yellow-500 bg-yellow-500/10 border-yellow-500/20";
                                if($w['workout_status'] == 'Completed') $statusColor = "text-emerald-500 bg-emerald-500/10 border-emerald-500/20";
                                if($w['workout_status'] == 'Assigned') $statusColor = "text-primary bg-primary/10 border-primary/20";
                            ?>
                            <span class="px-3 py-1 rounded-full text-[8px] font-black uppercase border <?= $statusColor ?>"><?= $w['workout_status'] ?></span>
                        </td>
                        <td class="px-8 py-6 text-right">
                            <div class="flex items-center justify-end gap-2">
                                <?php if($w['workout_status'] != 'Completed'): ?>
                                    <a href="?member_id=<?= $member_id ?>&action=update_status&workout_id=<?= $w['workout_id'] ?>&status=Completed" class="size-9 rounded-lg bg-emerald-500/10 text-emerald-500 border border-emerald-500/20 flex items-center justify-center hover:bg-emerald-500 hover:text-white transition-all shadow-lg" title="Mark as Completed">
                                        <span class="material-symbols-outlined text-sm">check</span>
                                    </a>
                                <?php endif; ?>
                                <?php if($w['workout_status'] != 'Assigned'): ?>
                                    <a href="?member_id=<?= $member_id ?>&action=update_status&workout_id=<?= $w['workout_id'] ?>&status=Assigned" class="size-9 rounded-lg bg-primary/10 text-primary border border-primary/20 flex items-center justify-center hover:bg-primary hover:text-white transition-all shadow-lg" title="Re-assign">
                                        <span class="material-symbols-outlined text-sm">refresh</span>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="4" class="p-20 text-center text-gray-600 uppercase font-black italic text-xs tracking-widest">No workout history found</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    </main>
</div>

<!-- Assign Modal -->
<div id="assignModal" class="fixed inset-0 bg-black/80 backdrop-blur-sm z-[200] hidden items-center justify-center p-4">
    <div class="glass-card w-full max-w-lg p-8 animate-slide-up border border-white/10">
        <div class="flex items-center justify-between mb-8">
            <h3 class="text-xl font-black italic uppercase">Assign <span class="text-primary">Workout</span></h3>
            <button onclick="document.getElementById('assignModal').classList.add('hidden')" class="text-gray-500 hover:text-white transition-colors">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        
        <form action="" method="POST" class="space-y-6">
            <input type="hidden" name="m_id" value="<?= $member_id ?>">
            
            <div class="flex flex-col gap-2">
                <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Routine Name</label>
                <input type="text" name="workout_name" placeholder="e.g. Advanced Leg Day" class="modal-input" required>
            </div>
            
            <div class="flex flex-col gap-2">
                <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Exercises & Details</label>
                <textarea name="workout_description" rows="4" placeholder="List exercises, reps, sets..." class="modal-input resize-none"></textarea>
            </div>
            
            <div class="flex flex-col gap-2">
                <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Scheduled Date</label>
                <input type="date" name="scheduled_date" class="modal-input" value="<?= date('Y-m-d') ?>">
            </div>
            
            <button type="submit" name="assign_workout" class="w-full py-4 bg-primary hover:bg-primary/90 text-white rounded-xl text-[11px] font-black uppercase italic tracking-widest transition-all active:scale-95 shadow-xl shadow-primary/20">
                Confirm Assignment
            </button>
        </form>
    </div>
</div>

<script>
    window.onload = function() {
        updateHeaderClock();
        setInterval(updateHeaderClock, 1000);
    };
</script>
</body>
</html>
