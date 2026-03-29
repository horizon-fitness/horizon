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

$pending_count = 0;
if ($coach_id > 0) {
    $stmtPending = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE coach_id = ? AND booking_status = 'Pending'");
    $stmtPending->execute([$coach_id]);
    $pending_count = $stmtPending->fetchColumn();
}

// Fetch Members assigned to this coach (via bookings)
$members = [];
if ($coach_id > 0) {
    // We get distinct members who have booked this coach
    $stmtMembers = $pdo->prepare("
        SELECT DISTINCT m.member_id, u.first_name, u.last_name, u.email, u.contact_number, m.member_code, m.member_status,
        (SELECT MAX(attendance_date) FROM attendance WHERE member_id = m.member_id) as last_visit
        FROM members m
        JOIN users u ON m.user_id = u.user_id
        JOIN bookings b ON m.member_id = b.member_id
        WHERE b.coach_id = ? AND m.gym_id = ?
        ORDER BY last_visit DESC
    ");
    $stmtMembers->execute([$coach_id, $gym_id]);
    $members = $stmtMembers->fetchAll();
}
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>My Members | Horizon Systems</title>
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

        .search-input { background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.05); border-radius: 16px; padding: 12px 20px 12px 48px; color: white; transition: all 0.3s; width: 100%; }
        .search-input:focus { border-color: #8c2bee; outline: none; background: rgba(140,43,238,0.05); }

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
    <div class="mb-12 shrink-0"> 
        <div class="flex items-center gap-4"> 
            <div class="size-14 rounded-2xl bg-white/5 flex items-center justify-center shadow-2xl shrink-0 overflow-hidden border border-white/10">
                <?php if ($gym && !empty($gym['logo_path'])): ?>
                    <img src="<?= htmlspecialchars($gym['logo_path']) ?>" class="size-full object-cover">
                <?php else: ?>
                    <span class="material-symbols-outlined text-primary text-3xl">bolt</span>
                <?php endif; ?>
            </div>
            <div class="nav-text">
                <h1 class="text-xs font-black italic uppercase tracking-[0.2em] text-primary leading-none mb-1">Horizon</h1>
                <h2 class="text-lg font-black italic uppercase tracking-tighter text-white leading-none">Systems</h2>
            </div>
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
        
        <a href="coach_schedule.php" class="nav-link flex items-center gap-4 py-2 <?= (basename($_SERVER['PHP_SELF']) == 'coach_schedule.php') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">edit_calendar</span> 
            <span class="nav-text">My Availability</span>
        </a>

        <a href="coach_members.php" class="nav-link flex items-center gap-4 py-2 <?= (basename($_SERVER['PHP_SELF']) == 'coach_members.php') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">groups</span> 
            <span class="nav-text">My Members</span>
        </a>

        <div class="nav-section-header px-0 mb-2 mt-6">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Training</span>
        </div>

        <a href="coach_workouts.php" class="nav-link flex items-center gap-4 py-2 <?= (basename($_SERVER['PHP_SELF']) == 'coach_workouts.php') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">fitness_center</span> 
            <span class="nav-text">Workouts</span>
        </a>
    </div>

    <div class="mt-auto pt-4 border-t border-white/10 flex flex-col gap-1 shrink-0">
        <div class="nav-section-header px-0 mb-2">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Account</span>
        </div>

        <a href="coach_profile.php" class="nav-link flex items-center gap-4 py-2 <?= (basename($_SERVER['PHP_SELF']) == 'coach_profile.php') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
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
    <header class="mb-12 flex flex-col md:flex-row justify-between items-start md:items-center gap-6">
        <div class="flex items-center gap-6 animate-slide-up">
            <div class="size-20 rounded-[2rem] bg-white/5 flex items-center justify-center shadow-2xl shrink-0 overflow-hidden border border-white/10 p-2">
                <?php if ($gym && !empty($gym['logo_path'])): ?>
                    <img src="<?= htmlspecialchars($gym['logo_path']) ?>" class="size-full object-contain">
                <?php else: ?>
                    <span class="material-symbols-outlined text-primary text-4xl">bolt</span>
                <?php endif; ?>
            </div>
            <div>
                <h2 class="text-4xl lg:text-5xl font-black italic uppercase tracking-tighter text-white leading-none">My <span class="text-primary">Members</span></h2>
                <p class="text-gray-500 text-[10px] font-black uppercase tracking-[0.3em] mt-3 ml-1 opacity-60">Client Directory</p>
            </div>
        </div>
        <div class="text-left md:text-right animate-slide-up" style="animation-delay: 0.1s;">
            <p id="headerClock" class="text-white font-black italic text-2xl tracking-tight leading-none mb-2">00:00:00 AM</p>
            <p class="text-primary text-[10px] font-black uppercase tracking-[0.2em]"><?= date('l, M d, Y') ?></p>
        </div>
    </header>

    <div class="mb-8">
        <div class="relative w-full">
            <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-gray-500">search</span>
            <input type="text" id="memberSearch" placeholder="Search members..." class="search-input">
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6 animate-slide-up" id="memberContainer">
        <?php if(count($members) > 0): foreach($members as $m): ?>
        <div class="member-card glass-card p-6 flex flex-col gap-6 hover:border-primary/30 transition-all group" data-name="<?= strtolower($m['first_name'] . ' ' . $m['last_name']) ?>">
            <div class="flex items-start justify-between">
                <div class="flex items-center gap-4">
                    <div class="size-14 rounded-2xl bg-white/5 border border-white/5 flex items-center justify-center font-black text-primary text-xl group-hover:bg-primary/10 group-hover:border-primary/20 transition-all">
                        <?= strtoupper(substr($m['first_name'],0,1)) ?>
                    </div>
                    <div>
                        <h3 class="text-white font-black uppercase italic tracking-tight group-hover:text-primary transition-colors text-name"><?= htmlspecialchars($m['first_name'] . ' ' . $m['last_name']) ?></h3>
                        <p class="text-[9px] text-gray-500 font-bold uppercase tracking-widest">#<?= htmlspecialchars($m['member_code']) ?></p>
                    </div>
                </div>
                <span class="px-3 py-1 rounded-full bg-emerald-500/10 text-emerald-500 text-[8px] font-black uppercase tracking-widest border border-emerald-500/20"><?= htmlspecialchars($m['member_status']) ?></span>
            </div>

            <div class="grid grid-cols-2 gap-4 py-4 border-y border-white/5">
                <div>
                    <p class="text-[8px] font-black uppercase text-gray-600 tracking-widest mb-1">Last Visit</p>
                    <p class="text-xs font-bold italic text-gray-300"><?= $m['last_visit'] ? date('M d, Y', strtotime($m['last_visit'])) : 'No record' ?></p>
                </div>
                <div>
                    <p class="text-[8px] font-black uppercase text-gray-600 tracking-widest mb-1">Contact</p>
                    <p class="text-xs font-bold italic text-gray-300 truncate"><?= htmlspecialchars($m['contact_number'] ?: $m['email']) ?></p>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <a href="coach_workouts.php?member_id=<?= $m['member_id'] ?>" class="flex-1 py-3 rounded-xl bg-white/5 border border-white/10 hover:bg-primary hover:border-primary text-white text-[9px] font-black uppercase tracking-widest transition-all text-center">Manage Workouts</a>
                <a href="mailto:<?= htmlspecialchars($m['email']) ?>" class="size-11 rounded-xl bg-white/5 border border-white/10 flex items-center justify-center hover:bg-primary/10 hover:border-primary transition-all">
                    <span class="material-symbols-outlined text-sm text-gray-400 group-hover:text-primary">mail</span>
                </a>
            </div>
        </div>
        <?php endforeach; else: ?>
            <div class="col-span-full py-32 glass-card text-center flex flex-col items-center gap-4 border-dashed border-white/10 opacity-50">
                <span class="material-symbols-outlined text-5xl text-gray-700">group_off</span>
                <p class="text-xs font-black uppercase text-gray-600 tracking-widest italic">No members assigned to your schedule yet</p>
            </div>
        <?php endif; ?>
    </div>
</main>

<script>
    document.getElementById('memberSearch').addEventListener('input', function(e) {
        const term = e.target.value.toLowerCase();
        document.querySelectorAll('.member-card').forEach(card => {
            const name = card.querySelector('.text-name').innerText.toLowerCase();
            card.style.display = name.includes(term) ? 'flex' : 'none';
        });
    });
</script>
</body>
</html>