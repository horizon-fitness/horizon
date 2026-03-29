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
$stmtCoach->execute([$user_id, $gym_id]);
$coach = $stmtCoach->fetch();
$coach_id = $coach ? $coach['coach_id'] : 0;

// Stats
$today = date('Y-m-d');
$today_count = 0;
$pending_count = 0;
$total_members_coached = 0;
$upcoming_sessions = 0;

if ($coach_id > 0) {
    // Confirmed bookings for today
    $stmtToday = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE coach_id = ? AND booking_date = ? AND booking_status = 'Confirmed'");
    $stmtToday->execute([$coach_id, $today]);
    $today_count = $stmtToday->fetchColumn();

    // Total pending bookings
    $stmtPending = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE coach_id = ? AND booking_status = 'Pending'");
    $stmtPending->execute([$coach_id]);
    $pending_count = $stmtPending->fetchColumn();

    // Total distinct members coached
    $stmtMembers = $pdo->prepare("SELECT COUNT(DISTINCT member_id) FROM bookings WHERE coach_id = ?");
    $stmtMembers->execute([$coach_id]);
    $total_members_coached = $stmtMembers->fetchColumn();

    // Upcoming sessions (confirmed, from tomorrow onwards)
    $stmtUpcoming = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE coach_id = ? AND booking_date > ? AND booking_status = 'Confirmed'");
    $stmtUpcoming->execute([$coach_id, $today]);
    $upcoming_sessions = $stmtUpcoming->fetchColumn();
}

// Fetch Today's Schedule (Confirmed Only)
$schedule_result = [];
if ($coach_id > 0) {
    $stmtSched = $pdo->prepare("
        SELECT b.*, u.first_name, u.last_name, u.username, gs.custom_service_name as session_name 
        FROM bookings b
        JOIN members m ON b.member_id = m.member_id
        JOIN users u ON m.user_id = u.user_id
        LEFT JOIN gym_services gs ON b.gym_service_id = gs.gym_service_id
        WHERE b.coach_id = ? AND b.booking_date = ? AND b.booking_status = 'Confirmed'
        ORDER BY b.start_time ASC
    ");
    $stmtSched->execute([$coach_id, $today]);
    $schedule_result = $stmtSched->fetchAll();
}

// Pagination Logic
$limit = 10;
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($current_page < 1) $current_page = 1;
$offset = ($current_page - 1) * $limit;

// Fetch Total Count for Pagination
$total_confirmed = 0;
if ($coach_id > 0) {
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE coach_id = ? AND booking_date = ? AND booking_status = 'Confirmed'");
    $stmtCount->execute([$coach_id, $today]);
    $total_confirmed = $stmtCount->fetchColumn();
}
$total_pages = ceil($total_confirmed / $limit);

// Re-fetch Paginated Schedule
if ($coach_id > 0) {
    $stmtSched = $pdo->prepare("
        SELECT b.*, u.first_name, u.last_name, u.username, gs.custom_service_name as session_name 
        FROM bookings b
        JOIN members m ON b.member_id = m.member_id
        JOIN users u ON m.user_id = u.user_id
        LEFT JOIN gym_services gs ON b.gym_service_id = gs.gym_service_id
        WHERE b.coach_id = ? AND b.booking_date = ? AND b.booking_status = 'Confirmed'
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
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Coach Portal | Horizon Systems</title>
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
        body { font-family: 'Lexend', sans-serif; background-color: <?= $page['bg_color'] ?? '#0a090d' ?>; color: white; display: flex; flex-direction: row; min-height: 100vh; overflow: hidden; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        
        /* Unified Sidebar Navigation Styles - MATCHING ADMIN DASHBOARD */
        .side-nav { width: 110px; transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1); overflow: hidden; display: flex; flex-direction: column; position: fixed; left: 0; top: 0; height: 100vh; z-index: 50; background: <?= $page['bg_color'] ?? '#0a090d' ?>; border-right: 1px solid rgba(255,255,255,0.05); }
        .side-nav:hover { width: 300px; }
        .main-content { margin-left: 110px; flex: 1; min-width: 0; transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1); }
        .side-nav:hover ~ .main-content { margin-left: 300px; }

        .nav-label { opacity: 0; transform: translateX(-15px); transition: all 0.3s ease-in-out; white-space: nowrap; pointer-events: none; }
        .side-nav:hover .nav-label { opacity: 1; transform: translateX(0); pointer-events: auto; }
        .nav-section-label { max-height: 0; opacity: 0; overflow: hidden; transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); margin: 0 !important; pointer-events: none; }
        .side-nav:hover .nav-section-label { max-height: 20px; opacity: 1; margin-bottom: 8px !important; pointer-events: auto; }
        
        .nav-item { display: flex; align-items: center; gap: 16px; padding: 10px 38px; transition: all 0.2s ease; text-decoration: none; white-space: nowrap; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; }
        .nav-item:hover { background: rgba(255,255,255,0.05); color: white; }
        .nav-item.active { color: <?= $page['theme_color'] ?? '#8c2bee' ?> !important; position: relative; }
        .nav-item.active::after { content: ''; position: absolute; right: 0px; top: 50%; transform: translateY(-50%); width: 4px; height: 24px; background: <?= $page['theme_color'] ?? '#8c2bee' ?>; border-radius: 4px 0 0 4px; }

        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

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
        window.addEventListener('DOMContentLoaded', updateHeaderClock);
    </script>
</head>
<body class="antialiased flex h-screen overflow-hidden">

<nav class="side-nav flex flex-col fixed left-0 top-0 h-screen bg-background-dark border-r border-white/5 z-50">
    <div class="px-7 py-8 mb-4 shrink-0">
        <div class="flex items-center gap-4">
            <div class="size-10 rounded-xl bg-primary flex items-center justify-center shadow-lg shrink-0 overflow-hidden">
                <?php if (!empty($page['logo_path'])): 
                    $logo_src = (strpos($page['logo_path'], 'data:image') === 0) ? $page['logo_path'] : '../' . $page['logo_path'];
                ?>
                    <img src="<?= $logo_src ?>" class="size-full object-contain">
                <?php else: ?>
                    <span class="material-symbols-outlined text-white text-2xl">bolt</span>
                <?php endif; ?>
            </div>
            <h1 class="nav-label text-lg font-black italic uppercase tracking-tighter text-white">Coach Portal</h1>
        </div>
    </div>
    
    <div class="flex-1 overflow-y-auto no-scrollbar space-y-1">
        <div class="nav-section-label px-[38px] mb-2">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Main Menu</span>
        </div>
        
        <a href="coach_dashboard.php" class="nav-item active">
            <span class="material-symbols-outlined text-xl shrink-0">grid_view</span> 
            <span class="nav-label">Dashboard</span>
            <?php if($pending_count > 0): ?><span class="size-1.5 rounded-full bg-primary alert-dot ml-auto"></span><?php endif; ?>
        </a>
        
        <a href="coach_schedule.php" class="nav-item text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl shrink-0">edit_calendar</span> 
            <span class="nav-label">My Availability</span>
        </a>

        <a href="coach_members.php" class="nav-item text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl shrink-0">groups</span> 
            <span class="nav-label">My Members</span>
        </a>

        <div class="nav-section-label px-[38px] mb-2 mt-6">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Training</span>
        </div>

        <a href="coach_workouts.php" class="nav-item text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl shrink-0">fitness_center</span> 
            <span class="nav-label">Workouts</span>
        </a>
    </div>

    <div class="mt-auto pt-4 border-t border-white/10 flex flex-col gap-1 shrink-0 pb-6">
        <div class="nav-section-label px-[38px] mb-2">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Account</span>
        </div>

        <a href="coach_profile.php" class="nav-item text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl shrink-0">account_circle</span> 
            <span class="nav-label">Profile</span>
        </a>

        <a href="../logout.php" class="nav-item text-gray-400 hover:text-rose-500 transition-colors group">
            <span class="material-symbols-outlined text-xl shrink-0 group-hover:translate-x-1 transition-transform">logout</span>
            <span class="nav-label">Sign Out</span>
        </a>
    </div>
</nav>

<div class="main-content flex-1 overflow-y-auto no-scrollbar">
    <div class="p-10">
    <header class="mb-10 flex justify-between items-end">
        <div>
            <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white">Coach <span class="text-primary">Dashboard</span></h2>
            <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-1">Operational Overview</p>
        </div>
        <div class="flex flex-col items-end">
            <p id="headerClock" class="text-white font-black italic text-2xl leading-none">00:00:00 AM</p>
            <p class="text-[10px] font-black uppercase text-primary tracking-[0.2em] leading-none mt-2"><?= date('l, M d, Y') ?></p>
        </div>
    </header>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-10">
        <div class="glass-card p-6 flex items-center gap-4 animate-slide-up">
            <div class="size-12 rounded-full bg-emerald-500/10 flex items-center justify-center text-emerald-500">
                <span class="material-symbols-outlined text-2xl">event_available</span>
            </div>
            <div>
                <p class="text-[10px] font-black uppercase text-gray-400 tracking-widest">Confirmed Today</p>
                <h3 class="text-2xl font-black italic uppercase"><?= $today_count ?></h3>
            </div>
        </div>
        
        <div class="glass-card p-6 flex items-center gap-4 animate-slide-up" style="animation-delay: 0.1s;">
            <div class="size-12 rounded-full bg-primary/10 flex items-center justify-center text-primary">
                <span class="material-symbols-outlined text-2xl">pending_actions</span>
            </div>
            <div>
                <p class="text-[10px] font-black uppercase text-gray-400 tracking-widest">Pending Sessions</p>
                <div class="flex items-center gap-2">
                    <h3 class="text-2xl font-black italic uppercase"><?= $pending_count ?></h3>
                    <?php if($pending_count > 0): ?>
                        <span class="px-1.5 py-0.5 rounded-md bg-primary/20 text-primary text-[7px] font-black uppercase tracking-widest alert-dot">Action</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="glass-card p-6 flex items-center gap-4 animate-slide-up" style="animation-delay: 0.2s;">
            <div class="size-12 rounded-full bg-amber-500/10 flex items-center justify-center text-amber-500">
                <span class="material-symbols-outlined text-2xl">groups</span>
            </div>
            <div>
                <p class="text-[10px] font-black uppercase text-gray-400 tracking-widest">Total Members</p>
                <h3 class="text-2xl font-black italic uppercase"><?= $total_members_coached ?></h3>
            </div>
        </div>

        <div class="glass-card p-6 flex items-center gap-4 animate-slide-up" style="animation-delay: 0.3s;">
            <div class="size-12 rounded-full bg-blue-500/10 flex items-center justify-center text-blue-500">
                <span class="material-symbols-outlined text-2xl">schedule</span>
            </div>
            <div>
                <p class="text-[10px] font-black uppercase text-gray-400 tracking-widest">Upcoming</p>
                <h3 class="text-2xl font-black italic uppercase"><?= $upcoming_sessions ?></h3>
            </div>
        </div>
    </div>

    <div class="glass-card overflow-hidden shadow-2xl animate-slide-up" style="animation-delay: 0.4s;">
        <div class="px-8 py-6 border-b border-white/5 bg-white/5 flex justify-between items-center">
            <h4 class="font-black italic uppercase text-sm tracking-tighter">Today's Training <span class="text-primary">Schedule</span></h4>
            <span class="px-3 py-1 bg-primary/10 text-primary text-[8px] font-black uppercase rounded-full border border-primary/20 tracking-widest">Confirmed Only</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="bg-background-dark/50 text-gray-500 text-[10px] font-black uppercase tracking-widest">
                        <th class="px-8 py-4">Member Name</th>
                        <th class="px-8 py-4">Training Type</th>
                        <th class="px-8 py-4">Time Slot</th>
                        <th class="px-8 py-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php if(count($schedule_result) > 0): foreach($schedule_result as $row): ?>
                    <tr class="hover:bg-white/[0.02] transition-all group">
                        <td class="px-8 py-5">
                            <div class="flex items-center gap-3">
                                <div class="size-10 rounded-full bg-white/5 flex items-center justify-center font-black text-primary border border-white/5 group-hover:border-primary/50 text-sm transition-colors"><?= strtoupper(substr($row['first_name'], 0, 1)) ?></div>
                                <div>
                                    <p class="text-sm font-bold italic text-white"><?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?></p>
                                    <p class="text-[9px] text-gray-500 font-bold uppercase tracking-wider">@<?= htmlspecialchars($row['username']) ?></p>
                                </div>
                            </div>
                        </td>
                        <td class="px-8 py-5">
                            <p class="text-gray-300 text-xs font-bold uppercase italic tracking-wide"><?= htmlspecialchars($row['session_name'] ?? 'Personal Training') ?></p>
                        </td>
                        <td class="px-8 py-5">
                            <p class="text-white font-black text-sm"><?= date('h:i A', strtotime($row['start_time'])) ?></p>
                            <p class="text-[9px] text-gray-500 font-bold uppercase tracking-tighter"><?= date('l, M d', strtotime($row['booking_date'])) ?></p>
                        </td>
                        <td class="px-8 py-5 text-right">
                            <a href="coach_workouts.php?member_id=<?= $row['member_id'] ?>" class="px-5 py-2 rounded-xl bg-white/5 border border-white/10 text-[9px] font-black uppercase tracking-widest hover:bg-primary hover:text-white hover:border-primary transition-all shadow-xl inline-block hover:-translate-y-0.5">Manage Workouts</a>
                        </td>
                    </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="4" class="p-24 text-center text-gray-600 uppercase font-black italic text-[10px] tracking-[0.2em] opacity-50">No coaching sessions scheduled today</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination Footer -->
        <?php if($total_pages > 1): ?>
        <div class="px-8 py-5 border-t border-white/5 bg-white/[0.01] flex items-center justify-between gap-6">
            <p class="text-[10px] font-black uppercase tracking-widest text-gray-500">
                Paging Control <span class="text-white ml-2"><?= $current_page ?> / <?= $total_pages ?></span>
            </p>
            <div class="flex items-center gap-1.5">
                <?php if($current_page > 1): ?>
                    <a href="?page=<?= $current_page - 1 ?>" class="size-9 rounded-xl border border-white/5 bg-white/5 flex items-center justify-center text-gray-400 hover:text-white hover:bg-primary/20 hover:border-primary/20 transition-all">
                        <span class="material-symbols-outlined text-xl">chevron_left</span>
                    </a>
                <?php endif; ?>

                <?php 
                $start_p = max(1, $current_page - 2);
                $end_p = min($total_pages, $start_p + 4);
                if ($end_p - $start_p < 4) $start_p = max(1, $end_p - 4);

                for($i = $start_p; $i <= $end_p; $i++): 
                ?>
                    <a href="?page=<?= $i ?>" class="size-9 rounded-xl border <?= ($i == $current_page) ? 'border-primary bg-primary text-white shadow-lg shadow-primary/20' : 'border-white/5 bg-white/5 text-gray-400' ?> flex items-center justify-center text-[10px] font-black transition-all hover:border-primary/50">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>

                <?php if($current_page < $total_pages): ?>
                    <a href="?page=<?= $current_page + 1 ?>" class="size-9 rounded-xl border border-white/5 bg-white/5 flex items-center justify-center text-gray-400 hover:text-white hover:bg-primary/20 hover:border-primary/20 transition-all">
                        <span class="material-symbols-outlined text-xl">chevron_right</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    </div>
</div>
</body>
</html>