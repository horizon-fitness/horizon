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

if ($coach_id > 0) {
    // Confirmed bookings for today
    $stmtToday = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE coach_id = ? AND booking_date = ? AND booking_status = 'Confirmed'");
    $stmtToday->execute([$coach_id, $today]);
    $today_count = $stmtToday->fetchColumn();

    // Total pending bookings
    $stmtPending = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE coach_id = ? AND booking_status = 'Pending'");
    $stmtPending->execute([$coach_id]);
    $pending_count = $stmtPending->fetchColumn();
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
        body { font-family: 'Lexend', sans-serif; background-color: <?= $page['bg_color'] ?? '#0a090d' ?>; color: white; display: flex; flex-direction: row; min-h-screen: 100vh; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        
        /* Sidebar Hover Logic - MATCHING STAFF DASHBOARD */
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
<nav class="sidebar-nav flex flex-col fixed left-0 top-0 h-screen bg-background-dark border-r border-white/5 z-50">
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
        
        <a href="coach_schedule.php" class="nav-link text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl shrink-0">edit_calendar</span> 
            <span class="nav-text">My Availability</span>
        </a>

        <a href="coach_members.php" class="nav-link text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl shrink-0">groups</span> 
            <span class="nav-text">My Members</span>
        </a>

        <div class="nav-section-header text-[10px] font-black text-gray-500 uppercase tracking-widest px-[38px] mt-4 mb-2">Training</div>

        <a href="coach_workouts.php" class="nav-link text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl shrink-0">fitness_center</span> 
            <span class="nav-text">Workouts</span>
        </a>
    </div>

    <div class="mt-auto pt-4 border-t border-white/10 flex flex-col gap-1 shrink-0 pb-6">
        <span class="nav-section-header text-[10px] font-black text-gray-500 uppercase tracking-widest px-[38px] mt-0 mb-2">Account</span>

        <a href="coach_profile.php" class="nav-link text-gray-400 hover:text-white">
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
                <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white">Welcome Back, <span class="text-primary"><?= htmlspecialchars($coach_name ?? '') ?></span></h2>
                <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-1">Coach Operational Overview</p>
            </div>
            <div class="flex flex-row items-center gap-4">
                <div class="px-6 py-3.5 rounded-2xl bg-white/5 border border-white/5 text-right flex flex-col items-end group shadow-sm hover:shadow-primary/10 transition-shadow">
                    <p id="headerClock" class="text-white font-black italic text-xl leading-none mb-1 group-hover:text-primary transition-colors">00:00:00 AM</p>
                    <p class="text-gray-500 text-[9px] font-black uppercase tracking-[0.2em] group-hover:text-white transition-colors"><?= date('l, M d') ?></p>
                </div>
            </div>
        </header>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-10">
        <div class="glass-card p-8 border-l-4 border-emerald-500 shadow-xl animate-slide-up">
            <p class="text-[10px] font-black uppercase text-gray-500 mb-2 tracking-widest">Confirmed Today</p>
            <h3 class="text-4xl lg:text-5xl font-black italic"><?= $today_count ?></h3>
        </div>
        
        <div class="glass-card p-8 border-l-4 border-primary shadow-xl animate-slide-up" style="animation-delay: 0.1s;">
            <p class="text-[10px] font-black uppercase text-gray-500 mb-2 tracking-widest">Pending Sessions</p>
            <div class="flex items-end gap-3">
                <h3 class="text-4xl lg:text-5xl font-black italic"><?= $pending_count ?></h3>
                <p class="text-[10px] font-black mb-1 text-primary uppercase tracking-widest">Needs Action</p>
            </div>
        </div>
    </div>

    <div class="glass-card overflow-hidden shadow-2xl animate-slide-up" style="animation-delay: 0.2s;">
        <div class="p-8 border-b border-white/5 bg-white/5 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
            <h3 class="text-lg font-black italic uppercase">Today's Training Schedule</h3>
            <span class="px-4 py-1 bg-primary/10 text-primary text-[9px] font-black uppercase rounded-full border border-primary/20">Confirmed Members Only</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="text-[10px] font-black uppercase text-gray-500 tracking-widest bg-black/20">
                        <th class="px-8 py-5">Member Name</th>
                        <th class="px-8 py-5">Training Type</th>
                        <th class="px-8 py-5">Time Slot</th>
                        <th class="px-8 py-5 text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php if(count($schedule_result) > 0): foreach($schedule_result as $row): ?>
                    <tr class="hover:bg-white/[0.02] transition-colors group">
                        <td class="px-8 py-6">
                            <div class="flex items-center gap-3">
                                <div class="size-10 rounded-full bg-white/5 flex items-center justify-center font-black text-primary border border-white/5 group-hover:border-primary/50 text-sm"><?= strtoupper(substr($row['first_name'], 0, 1)) ?></div>
                                <div>
                                    <p class="text-white font-black uppercase italic leading-tight"><?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?></p>
                                    <p class="text-[9px] text-gray-500 font-bold uppercase">@<?= htmlspecialchars($row['username']) ?></p>
                                </div>
                            </div>
                        </td>
                        <td class="px-8 py-6">
                            <p class="text-gray-300 text-sm font-bold uppercase italic"><?= htmlspecialchars($row['session_name'] ?? 'Personal Training') ?></p>
                        </td>
                        <td class="px-8 py-6">
                            <p class="text-white font-black text-sm"><?= date('h:i A', strtotime($row['start_time'])) ?></p>
                            <p class="text-[9px] text-gray-500 font-bold uppercase tracking-tighter"><?= date('l, M d', strtotime($row['booking_date'])) ?></p>
                        </td>
                        <td class="px-8 py-6 text-right">
                            <a href="coach_workouts.php?member_id=<?= $row['member_id'] ?>" class="bg-white/5 border border-white/10 px-4 py-2 rounded-xl text-[9px] font-black uppercase hover:bg-primary hover:border-primary transition-all shadow-xl inline-block">Manage Workouts</a>
                        </td>
                    </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="4" class="p-20 text-center text-gray-600 uppercase font-black italic text-xs tracking-widest">No members scheduled for training today</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    </main>
</div>
</body>
</html>