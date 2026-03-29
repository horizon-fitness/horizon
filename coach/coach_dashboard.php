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
$coach_first_name = $_SESSION['first_name'] ?? 'Coach';

// Fetch Gym Details
$gym = null;
if (!empty($gym_id)) {
    $stmtGym = $pdo->prepare("SELECT * FROM gyms WHERE gym_id = ? LIMIT 1");
    $stmtGym->execute([$gym_id]);
    $gym = $stmtGym->fetch();
}

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
            theme: { extend: { colors: { "primary": "#8c2bee", "background-dark": "#0a090d", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)" } } }
        }
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; padding-bottom: 100px; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        
        .sidebar-nav {
            width: 110px; 
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .sidebar-nav:hover {
            width: 280px; 
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

        .nav-link { font-size: 11px; font-weight: 800; letter-spacing: 0.05em; transition: all 0.2s; white-space: nowrap; }
        .active-nav { color: #8c2bee !important; position: relative; }
        .active-nav::after { 
            content: ''; 
            position: absolute; 
            right: 0px; 
            top: 50%;
            transform: translateY(-50%);
            width: 4px; 
            height: 32px; 
            background: #8c2bee; 
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
<body class="antialiased flex flex-col lg:flex-row min-h-screen">

<nav class="sidebar-nav hidden lg:flex flex-col bg-[#0a090d] border-r border-white/5 sticky top-0 h-screen pl-7 pr-0 py-8 z-50 shrink-0">
    <div class="mb-10 shrink-0"> 
        <div class="flex items-center gap-4 mb-4"> 
            <div class="size-11 rounded-xl bg-primary flex items-center justify-center shadow-lg shrink-0 overflow-hidden">
                <?php if ($gym && !empty($gym['logo_path'])): ?>
                    <img src="<?= htmlspecialchars($gym['logo_path']) ?>" class="size-full object-contain">
                <?php else: ?>
                    <span class="material-symbols-outlined text-white text-2xl">bolt</span>
                <?php endif; ?>
            </div>
            <h1 class="nav-text text-lg font-black italic uppercase tracking-tighter text-white leading-tight whitespace-nowrap">
                <?= htmlspecialchars($gym['gym_name'] ?? 'HORIZON COACH') ?>
            </h1>
        </div>
    </div>
    
    <div class="flex-1 overflow-y-auto no-scrollbar space-y-1">
        <div class="nav-section-header px-0 mb-2">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Main Menu</span>
        </div>
        
        <a href="coach_dashboard.php" class="nav-link flex items-center gap-4 py-2 <?= (basename($_SERVER['PHP_SELF']) == 'coach_dashboard.php') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">grid_view</span> 
            <span class="nav-text">Dashboard</span>
            <?php if($pending_count > 0): ?><span class="size-1.5 rounded-full bg-primary alert-dot ml-auto"></span><?php endif; ?>
        </a>
        
        <a href="coach_schedule.php" class="nav-link flex items-center gap-4 py-2 text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl shrink-0">edit_calendar</span> 
            <span class="nav-text">My Availability</span>
        </a>

        <a href="coach_members.php" class="nav-link flex items-center gap-4 py-2 text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl shrink-0">groups</span> 
            <span class="nav-text">My Members</span>
        </a>

        <div class="nav-section-header px-0 mb-2 mt-6">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Training</span>
        </div>

        <a href="coach_workouts.php" class="nav-link flex items-center gap-4 py-2 text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl shrink-0">fitness_center</span> 
            <span class="nav-text">Workouts</span>
        </a>
    </div>

    <div class="mt-auto pt-4 border-t border-white/10 flex flex-col gap-1 shrink-0">
        <div class="nav-section-header px-0 mb-2">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Account</span>
        </div>

        <a href="coach_profile.php" class="nav-link flex items-center gap-4 py-2 text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl shrink-0">account_circle</span> 
            <span class="nav-text">Profile</span>
        </a>

        <a href="../logout.php" class="nav-link flex items-center gap-4 py-2 text-gray-400 hover:text-rose-500 transition-colors group">
            <span class="material-symbols-outlined text-xl shrink-0 group-hover:translate-x-1 transition-transform">logout</span>
            <span class="nav-text">Sign Out</span>
        </a>
    </div>
</nav>

<main class="flex-1 max-w-[1600px] p-6 lg:p-12 overflow-x-hidden">
    <header class="mb-10 flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
        <div>
            <h2 class="text-3xl lg:text-4xl font-black italic uppercase tracking-tighter text-white leading-none">Coach <span class="text-primary">Dashboard</span></h2>
            <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-2">Manage your training sessions and member progress</p>
        </div>
        <div class="text-left md:text-right">
            <p id="headerClock" class="text-white font-black italic text-xl tracking-tight leading-none mb-2">00:00:00 AM</p>
            <p class="text-primary text-[9px] font-black uppercase tracking-[0.2em] mb-4"><?= date('l, M d') ?></p>
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
</body>
</html>