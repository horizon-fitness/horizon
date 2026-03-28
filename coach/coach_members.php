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

// Fetch Assigned Members (those who have booked with this coach)
$members = [];
if ($coach_id > 0) {
    $stmtMembers = $pdo->prepare("
        SELECT DISTINCT m.member_id as id, u.username, CONCAT(u.first_name, ' ', u.last_name) as fullname, m.member_code
        FROM bookings b
        JOIN members m ON b.member_id = m.member_id
        JOIN users u ON m.user_id = u.user_id
        WHERE b.coach_id = ?
    ");
    $stmtMembers->execute([$coach_id]);
    $members = $stmtMembers->fetchAll();
}

// Sample Data if empty
if (empty($members)) {
    $members = [
        ['id' => 1, 'fullname' => 'John Doe', 'username' => 'johndoe', 'member_code' => 'HF-001'],
        ['id' => 2, 'fullname' => 'Jane Smith', 'username' => 'janesmith', 'member_code' => 'HF-002'],
        ['id' => 3, 'fullname' => 'Alex Brown', 'username' => 'alexb', 'member_code' => 'HF-003'],
    ];
}
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>My Members | Herdoza Coach</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "<?= $_SESSION['theme_color'] ?? '#8c2bee' ?>", "background-dark": "#0a090d", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)" } } }
        }
        function updateHeaderClock() {
            const now = new Date();
            if(document.getElementById('headerClock')) document.getElementById('headerClock').textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }
        window.onload = function() { setInterval(updateHeaderClock, 1000); updateHeaderClock(); };
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; padding-bottom: 100px; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        
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

        /* Animations */
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
        .member-card { animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) both; }
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
        <header class="mb-10 flex flex-col md:flex-row justify-between items-start gap-4 animate-fade-in">
            <div class="flex-1">
                <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white leading-none">My <span class="text-primary">Members</span></h2>
                <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-2">Clients officially assigned to your program</p>
                
                <div class="relative mt-6">
                    <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 text-sm">search</span>
                    <input type="text" id="memberSearch" placeholder="Find member..." class="bg-surface-dark border border-white/10 rounded-xl py-2 pl-10 pr-4 text-xs w-64 focus:border-primary outline-none transition-all text-white shadow-xl">
                </div>
            </div>

            <div class="text-right shrink-0">
                <p id="headerClock" class="text-white font-black italic text-xl tracking-tight leading-none mb-2">00:00:00 AM</p>
                <p class="text-primary text-[9px] font-black uppercase tracking-[0.2em]"><?= date('l, M d') ?></p>
            </div>
        </header>

        <div class="grid grid-cols-1 gap-4" id="memberContainer">
            <?php if (count($members) > 0): ?>
                <?php foreach($members as $index => $row): 
                    $stmtAtt = $pdo->prepare("SELECT MAX(attendance_date) as last_v FROM attendance WHERE member_id = ?");
                    $stmtAtt->execute([$row['id']]);
                    $att_data = $stmtAtt->fetch();
                    $last_visit = ($att_data && $att_data['last_v']) ? date("M d, Y", strtotime($att_data['last_v'])) : "No record";
                ?>
                <div class="member-card glass-card p-6 flex flex-col md:flex-row items-center justify-between hover:border-primary/40 transition-all gap-6" style="animation-delay: <?= ($index * 0.1) ?>s;">
                    <div class="flex items-center gap-5">
                        <div class="size-14 rounded-2xl bg-white/5 flex items-center justify-center text-primary font-black text-2xl italic border border-white/5">
                            <?= strtoupper(substr($row['fullname'], 0, 1)) ?>
                        </div>
                        <div>
                            <h3 class="text-lg font-black italic uppercase text-white member-name"><?= htmlspecialchars($row['fullname']) ?></h3>
                            <p class="text-[10px] text-gray-500 font-bold uppercase tracking-widest">ID: #HF-<?= $row['id'] ?> • @<?= htmlspecialchars($row['username']) ?></p>
                        </div>
                    </div>

                    <div class="flex items-center gap-8 md:gap-16">
                        <div class="text-center">
                            <p class="text-xs font-bold text-gray-300"><?= $last_visit ?></p>
                            <p class="text-[9px] text-gray-600 font-black uppercase tracking-tighter">Last Attendance</p>
                        </div>
                        <a href="coach_workouts.php?user_id=<?= $row['id'] ?>" class="bg-primary/10 border border-primary/20 px-6 py-2 rounded-xl text-[10px] font-black uppercase text-primary hover:bg-primary hover:text-white transition-all">View Profile</a>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="py-20 text-center glass-card opacity-50 border-dashed">
                    <span class="material-symbols-outlined text-5xl mb-4">person_off</span>
                    <p class="text-xs font-black uppercase tracking-[0.2em]">No members assigned to you yet</p>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <div class="fixed bottom-0 left-0 right-0 h-20 mobile-taskbar z-[100] lg:hidden flex items-center justify-around px-4">
        <a href="coach_dashboard.php" class="flex flex-col items-center gap-1 text-gray-500 relative">
            <span class="material-symbols-outlined">grid_view</span>
            <span class="text-[8px] font-black uppercase">Dashboard</span>
            <?php if($pending_count > 0): ?><span class="absolute top-0 right-0 size-2 bg-primary rounded-full alert-dot"></span><?php endif; ?>
        </a>
        <a href="coach_schedule.php" class="flex flex-col items-center gap-1 text-gray-500">
            <span class="material-symbols-outlined">edit_calendar</span>
            <span class="text-[8px] font-black uppercase">Avail</span>
        </a>
        <a href="coach_members.php" class="flex flex-col items-center gap-1 text-primary">
            <span class="material-symbols-outlined">groups</span>
            <span class="text-[8px] font-black uppercase">Members</span>
        </a>
        <a href="coach_profile.php" class="flex flex-col items-center gap-1 text-gray-500">
            <span class="material-symbols-outlined">person</span>
            <span class="text-[8px] font-black uppercase">Profile</span>
        </a>
    </div>

    <script>
        document.getElementById('memberSearch').addEventListener('input', function(e) {
            let val = e.target.value.toLowerCase();
            document.querySelectorAll('.member-card').forEach(card => {
                let name = card.querySelector('.member-name').innerText.toLowerCase();
                card.style.display = name.includes(val) ? 'flex' : 'none';
            });
        });
    </script>
</body>
</html>