<?php
session_start();
require_once '../db.php';

// Security Check
$role = strtolower($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || $role !== 'coach') {
    header("Location: ../login.php");
    exit;
}

$user_id = $_GET['user_id'] ?? 0;
$gym_id = $_SESSION['gym_id'] ?? 0;
$coach_name = ($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '');

// Fetch Gym Details
$gym = null;
if (!empty($gym_id)) {
    $stmtGym = $pdo->prepare("SELECT * FROM gyms WHERE gym_id = ? LIMIT 1");
    $stmtGym->execute([$gym_id]);
    $gym = $stmtGym->fetch();
}

// Mock data if db is missing or for sample
$member_name = "Sample Member";
$workouts = [
    ['id' => 1, 'name' => 'Upper Body Power', 'status' => 'Assigned', 'date' => '2026-03-28'],
    ['id' => 2, 'name' => 'Lower Body Strength', 'status' => 'Pending', 'date' => '2026-03-29'],
    ['id' => 3, 'name' => 'Core & Cardio', 'status' => 'Completed', 'date' => '2026-03-27'],
];

?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Member Workouts | Horizon Coach</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "#8c2bee", "background-dark": "#0a090d", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)" } } }
        }
        function updateHeaderClock() {
            const now = new Date();
            const clockEl = document.getElementById('headerClock');
            if (clockEl) {
                clockEl.textContent = now.toLocaleTimeString('en-US', { 
                    hour: '2-digit', 
                    minute: '2-digit', 
                    second: '2-digit' 
                });
            }
        }
        setInterval(updateHeaderClock, 1000);
        window.addEventListener('DOMContentLoaded', updateHeaderClock);
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; padding-bottom: 100px; }
        .glass-card { 
            background: rgba(20, 18, 26, 0.8); 
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255,255,255,0.05); 
            border-radius: 24px; 
            animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1);
        }
        
        /* Sidebar Hover Logic - ADJUSTED WIDTHS */
        .sidebar-nav {
            width: 110px; 
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            overflow-x: hidden !important;
            display: flex;
            flex-direction: column;
            padding-right: 0 !important;
        }
        .sidebar-nav:hover {
            width: 280px; 
        }

        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; overflow-x: hidden !important; }

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
        .sidebar-nav:hover .nav-section-header.mt-6 { margin-top: 0px !important; } 
Pre-existing navigation link base styles already configured.

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
        @media (max-width: 1024px) { 
            .sidebar-nav { width: 100%; height: auto; position: relative; }
            .sidebar-nav:hover { width: 100%; }
            .nav-text { opacity: 1; transform: translateX(0); pointer-events: auto; }
            .active-nav::after { display: none; } 
            .nav-section-header { max-height: 20px; opacity: 1; margin-bottom: 8px !important; }
        }
        .mobile-taskbar { background: rgba(20, 18, 26, 0.9); backdrop-filter: blur(20px); border-top: 1px solid rgba(255,255,255,0.05); }
        .alert-dot { animation: pulse 2s infinite; }
        
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.4; } 100% { opacity: 1; } }
        @keyframes slideUp { 
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        .animate-fade-in { animation: fadeIn 0.8s ease-out; }
        .animate-slide-up { animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1); }
    </style>
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

        <a href="../logout.php" class="text-gray-400 hover:text-rose-500 transition-colors flex items-center gap-4 group py-2">
            <span class="material-symbols-outlined group-hover:translate-x-1 transition-transform text-xl shrink-0">logout</span>
            <span class="nav-link nav-text text-sm">Sign Out</span>
        </a>
    </div>
</nav>

   <main class="flex-1 max-w-[1600px] p-6 lg:p-12 overflow-x-hidden">
        <header class="mb-10 flex flex-col md:flex-row justify-between items-end gap-4 animate-fade-in">
            <div>
                <h2 class="text-3xl lg:text-4xl font-black italic uppercase tracking-tighter text-white leading-none">Member <span class="text-primary">Workouts</span></h2>
                <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-2">Assign and track workouts for your members</p>
            </div>
            <div class="text-right">
                <p id="headerClock" class="text-white font-black italic text-xl tracking-tight leading-none mb-2">00:00:00 AM</p>
                <p class="text-primary text-[9px] font-black uppercase tracking-[0.2em] mb-4"><?= date('l, M d') ?></p>
                </div>
        </header>

        <div class="grid grid-cols-1 gap-6 mb-10">
            <div class="glass-card p-8 border-l-4 border-primary shadow-xl animate-slide-up">
                <div class="flex items-center gap-6">
                    <div class="size-20 rounded-2xl bg-white/5 flex items-center justify-center text-primary font-black text-4xl italic border border-white/5">
                        <?= strtoupper(substr($member_name, 0, 1)) ?>
                    </div>
                    <div>
                        <h3 class="text-2xl font-black italic uppercase text-white"><?= htmlspecialchars($member_name) ?></h3>
                        <p class="text-[10px] text-gray-500 font-bold uppercase tracking-[0.2em] mt-1">ID: #HF-<?= $user_id ?></p>
                    </div>
                </div>
            </div>
        </div>

        <div class="glass-card overflow-hidden shadow-2xl animate-slide-up" style="animation-delay: 0.1s;">
            <div class="p-8 border-b border-white/5 bg-white/5 flex flex-col sm:row justify-between items-start sm:items-center gap-4">
                <h3 class="text-lg font-black italic uppercase">Workout History</h3>
                <button class="bg-primary hover:bg-primary/90 text-white px-6 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all">Assign New Workout</button>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="text-[10px] font-black uppercase text-gray-500 tracking-widest bg-black/20">
                            <th class="px-8 py-5">Workout Name</th>
                            <th class="px-8 py-5">Status</th>
                            <th class="px-8 py-5">Date</th>
                            <th class="px-8 py-5 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <?php foreach($workouts as $w): ?>
                        <tr class="hover:bg-white/[0.02] transition-colors group">
                            <td class="px-8 py-6">
                                <p class="text-white font-black uppercase italic"><?= htmlspecialchars($w['name']) ?></p>
                            </td>
                            <td class="px-8 py-6">
                                <?php 
                                    $statusColor = "text-yellow-500 bg-yellow-500/10 border-yellow-500/20";
                                    if($w['status'] == 'Completed') $statusColor = "text-emerald-500 bg-emerald-500/10 border-emerald-500/20";
                                    if($w['status'] == 'Assigned') $statusColor = "text-primary bg-primary/10 border-primary/20";
                                ?>
                                <span class="px-3 py-1 rounded-full text-[9px] font-black uppercase border <?= $statusColor ?>"><?= $w['status'] ?></span>
                            </td>
                            <td class="px-8 py-6">
                                <p class="text-gray-400 text-sm font-bold uppercase italic"><?= date('M d, Y', strtotime($w['date'])) ?></p>
                            </td>
                            <td class="px-8 py-6 text-right">
                                <button class="bg-white/5 border border-white/10 px-4 py-2 rounded-xl text-[9px] font-black uppercase hover:bg-primary hover:border-primary transition-all shadow-xl inline-block">Edit</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <div class="fixed bottom-0 left-0 right-0 h-20 mobile-taskbar z-[100] lg:hidden flex items-center justify-around px-4">
        <a href="coach_dashboard.php" class="flex flex-col items-center gap-1 text-gray-500">
            <span class="material-symbols-outlined">grid_view</span>
            <span class="text-[8px] font-black uppercase">Dashboard</span>
        </a>
        <a href="coach_members.php" class="flex flex-col items-center gap-1 text-primary">
            <span class="material-symbols-outlined">groups</span>
            <span class="text-[8px] font-black uppercase">Members</span>
        </a>
    </div>

</body>
</html>
