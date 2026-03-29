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

$msg = '';
$week_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

// Handle Save Availability
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_availability'])) {
    try {
        $pdo->beginTransaction();
        
        $stmtDelete = $pdo->prepare("DELETE FROM coach_schedules WHERE coach_id = ?");
        $stmtDelete->execute([$coach_id]);

        foreach ($week_days as $day) {
            $is_off = isset($_POST["off_$day"]) ? 1 : 0;
            $start = $_POST["start_$day"] ?? '08:00';
            $end = $_POST["end_$day"] ?? '17:00';
            
            $stmtInsert = $pdo->prepare("INSERT INTO coach_schedules (coach_id, day_of_week, start_time, end_time, availability_status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
            $status = $is_off ? 'Off' : 'Available';
            $stmtInsert->execute([$coach_id, $day, $start, $end, $status]);
        }
        
        $pdo->commit();
        $msg = "Schedule updated successfully.";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = "Error updating schedule: " . $e->getMessage();
    }
}

// Fetch current availability
$avail_map = [];
foreach ($week_days as $day) {
    $avail_map[$day] = ['start_time' => '08:00', 'end_time' => '17:00', 'is_off_day' => 0];
}

$stmtAvail = $pdo->prepare("SELECT * FROM coach_schedules WHERE coach_id = ?");
$stmtAvail->execute([$coach_id]);
$rows = $stmtAvail->fetchAll();
foreach ($rows as $r) {
    if (isset($avail_map[$r['day_of_week']])) {
        $avail_map[$r['day_of_week']] = [
            'start_time' => $r['start_time'],
            'end_time' => $r['end_time'],
            'is_off_day' => ($r['availability_status'] === 'Off') ? 1 : 0
        ];
    }
}

$pending_count = 0;
if ($coach_id > 0) {
    $stmtPending = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE coach_id = ? AND booking_status = 'Pending'");
    $stmtPending->execute([$coach_id]);
    $pending_count = $stmtPending->fetchColumn();
}

// Fetch Confirmed Bookings for Daily View
$all_bookings = [];
if ($coach_id > 0) {
    $stmtBookings = $pdo->prepare("
        SELECT b.*, u.username, CONCAT(u.first_name, ' ', u.last_name) as fullname
        FROM bookings b
        JOIN members m ON b.member_id = m.member_id
        JOIN users u ON m.user_id = u.user_id
        WHERE b.coach_id = ? AND b.booking_status = 'Confirmed'
    ");
    $stmtBookings->execute([$coach_id]);
    $fetched_bookings = $stmtBookings->fetchAll();
    
    foreach ($fetched_bookings as $fb) {
        $all_bookings[] = [
            'ts_start' => strtotime($fb['booking_date'] . ' ' . $fb['start_time']),
            'ts_end' => strtotime($fb['booking_date'] . ' ' . $fb['end_time']),
            'fullname' => $fb['fullname'],
            'username' => $fb['username']
        ];
    }
}
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>My Schedule | Horizon Systems</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "#8c2bee", "surface-dark": "#14121a", "background-dark": "#0a090d", "border-subtle": "rgba(255,255,255,0.05)" } } }
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

        input[type="time"]::-webkit-calendar-picker-indicator { filter: invert(1); cursor: pointer; }
        .custom-scroll::-webkit-scrollbar { width: 6px; }
        .custom-scroll::-webkit-scrollbar-track { background: rgba(255,255,255,0.02); }
        .custom-scroll::-webkit-scrollbar-thumb { background: #333; border-radius: 3px; }

        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeIn 0.4s ease-out; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
    </style>
    <script>
        function updateHeaderClock() {
            const now = new Date();
            const clockEl = document.getElementById('headerClock');
            if (clockEl) clockEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }
        function openTab(dayName) {
            document.querySelectorAll('.tab-content').forEach(tc => tc.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(tb => {
                tb.classList.remove('bg-primary', 'text-white');
                tb.classList.add('bg-white/5', 'text-gray-400');
            });
            document.getElementById(dayName).classList.add('active');
            document.getElementById('btn-' + dayName).classList.remove('bg-white/5', 'text-gray-400');
            document.getElementById('btn-' + dayName).classList.add('bg-primary', 'text-white');
        }
        setInterval(updateHeaderClock, 1000);
        window.onload = function() { 
            updateHeaderClock();
            const days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
            const today = days[new Date().getDay()];
            if(today !== 'Sunday' && document.getElementById(today)) { openTab(today); } else { openTab('Monday'); }
        };
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
                <h2 class="text-4xl lg:text-5xl font-black italic uppercase tracking-tighter text-white leading-none">My <span class="text-primary">Schedule</span></h2>
                <p class="text-gray-500 text-[10px] font-black uppercase tracking-[0.3em] mt-3 ml-1 opacity-60">Availability Console</p>
            </div>
        </div>
        <div class="text-left md:text-right animate-slide-up" style="animation-delay: 0.1s;">
            <p id="headerClock" class="text-white font-black italic text-2xl tracking-tight leading-none mb-2">00:00:00 AM</p>
            <p class="text-primary text-[10px] font-black uppercase tracking-[0.2em]"><?= date('l, M d, Y') ?></p>
        </div>
    </header>

    <?php if($msg): ?>
        <div class="bg-emerald-500/10 border border-emerald-500/20 p-4 rounded-xl text-emerald-500 text-[10px] font-black uppercase italic mb-8 flex items-center gap-2 animate-fade-in">
            <span class="material-symbols-outlined text-sm">check_circle</span> <?= $msg ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 xl:grid-cols-12 gap-8 animate-slide-up"> 
        <!-- Left: Settings -->
        <div class="xl:col-span-5">
            <div class="glass-card p-8 shadow-xl">
                <div class="flex items-center gap-2 mb-8 text-primary shrink-0">
                    <span class="material-symbols-outlined">settings_clock</span>
                    <h3 class="text-sm font-black uppercase italic">Availability Settings</h3>
                </div>
                <form method="POST" class="space-y-4">
                    <?php foreach($week_days as $day): 
                        $s1 = $avail_map[$day]['start_time'] ?? '08:00';
                        $e1 = $avail_map[$day]['end_time'] ?? '17:00';
                        $off = ($avail_map[$day]['is_off_day'] ?? 0) == 1;
                    ?>
                    <div class="bg-white/5 p-5 rounded-2xl border border-white/5 group hover:border-primary/20 transition-all">
                        <div class="flex justify-between items-center mb-4">
                            <span class="font-black italic uppercase text-xs text-white"><?= $day ?></span>
                            <label class="inline-flex items-center cursor-pointer">
                                <input type="checkbox" name="off_<?= $day ?>" value="1" <?= $off ? 'checked' : '' ?> class="sr-only peer">
                                <div class="relative w-10 h-5 bg-gray-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:bg-primary after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all"></div>
                                <span class="ms-2 text-[9px] font-black uppercase text-gray-500 peer-checked:text-primary tracking-widest">OFF</span>
                            </label>
                        </div>
                        <div class="flex gap-4">
                            <div class="flex-1">
                                <p class="text-[8px] text-gray-600 uppercase font-black mb-1 tracking-widest">Start Time</p>
                                <input type="time" name="start_<?= $day ?>" value="<?= substr($s1, 0, 5) ?>" min="07:00" max="21:00" class="w-full bg-black/40 border border-white/10 rounded-xl p-3 text-xs text-white outline-none focus:border-primary transition-all">
                            </div>
                            <div class="flex-1">
                                <p class="text-[8px] text-gray-600 uppercase font-black mb-1 tracking-widest">End Time</p>
                                <input type="time" name="end_<?= $day ?>" value="<?= substr($e1, 0, 5) ?>" min="07:00" max="21:00" class="w-full bg-black/40 border border-white/10 rounded-xl p-3 text-xs text-white outline-none focus:border-primary transition-all">
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <button type="submit" name="save_availability" class="w-full bg-primary hover:bg-primary/90 text-white py-4 rounded-2xl font-black uppercase text-[10px] tracking-[0.2em] italic shadow-lg shadow-primary/20 transition-all active:scale-[0.98] flex items-center justify-center gap-2">
                        <span class="material-symbols-outlined text-sm">save</span> Update Availability
                    </button>
                </form>
            </div>
        </div>

        <!-- Right: Daily View -->
        <div class="xl:col-span-7">
            <div class="glass-card p-8 shadow-xl flex flex-col h-full min-h-[800px]">
                <div class="flex items-center gap-2 text-white mb-8">
                    <span class="material-symbols-outlined text-primary">calendar_month</span>
                    <h3 class="text-sm font-black uppercase italic tracking-widest">Daily Bookings</h3>
                </div>

                <div class="flex gap-2 mb-8 no-scrollbar overflow-x-auto pb-4 border-b border-white/5">
                    <?php foreach($week_days as $day): ?>
                        <button id="btn-<?= $day ?>" onclick="openTab('<?= $day ?>')" class="tab-btn px-6 py-2 rounded-xl text-[9px] font-black uppercase tracking-widest bg-white/5 text-gray-500 hover:bg-white/10 transition-all">
                            <?= substr($day, 0, 3) ?>
                        </button>
                    <?php endforeach; ?>
                </div>

                <div class="flex-1">
                    <?php 
                    foreach($week_days as $index => $day_name): 
                        $loop_date = date('Y-m-d', strtotime("monday this week +$index days"));
                        $is_off = ($avail_map[$day_name]['is_off_day'] ?? 0) == 1;
                        $s1_ts = strtotime($loop_date . ' ' . ($avail_map[$day_name]['start_time'] ?? '08:00'));
                        $e1_ts = strtotime($loop_date . ' ' . ($avail_map[$day_name]['end_time'] ?? '17:00'));
                    ?>
                    <div id="<?= $day_name ?>" class="tab-content">
                        <div class="flex justify-between items-center mb-6">
                            <h4 class="text-2xl font-black italic uppercase text-white"><?= $day_name ?> <span class="text-sm font-bold text-gray-600 not-italic ml-2 tracking-widest"><?= date('M d', strtotime($loop_date)) ?></span></h4>
                            <?php if($is_off): ?>
                                <span class="px-4 py-1.5 bg-red-500/10 text-red-500 rounded-full text-[9px] font-black uppercase tracking-widest border border-red-500/20">Day Off</span>
                            <?php else: ?>
                                <span class="px-4 py-1.5 bg-emerald-500/10 text-emerald-500 rounded-full text-[9px] font-black uppercase tracking-widest border border-emerald-500/20">Active</span>
                            <?php endif; ?>
                        </div>

                        <div class="space-y-3">
                            <?php 
                            $start_day = strtotime($loop_date . ' 07:00');
                            $end_day   = strtotime($loop_date . ' 21:00');
                            
                            while($start_day < $end_day): 
                                $slot_end = strtotime('+30 minutes', $start_day);
                                $pretty_time = date('h:i A', $start_day);
                                
                                $found_booking = null;
                                foreach($all_bookings as $b) {
                                    if ($b['ts_start'] < $slot_end && $b['ts_end'] > $start_day) {
                                        $found_booking = $b;
                                        break;
                                    }
                                }
                                
                                $is_working_hour = ($start_day >= $s1_ts && $start_day < $e1_ts) && !$is_off;
                            ?>
                                <?php if($found_booking): ?>
                                    <div class="flex items-center bg-primary/10 border border-primary/20 p-4 rounded-2xl shadow-xl">
                                        <div class="w-20 text-[10px] font-black text-gray-400"><?= $pretty_time ?></div>
                                        <div class="flex-1 ml-6">
                                            <p class="text-sm font-black text-white uppercase italic"><?= htmlspecialchars($found_booking['fullname']) ?></p>
                                            <p class="text-[9px] text-primary font-bold uppercase tracking-widest">Training Session</p>
                                        </div>
                                        <span class="material-symbols-outlined text-primary text-xl">verified_user</span>
                                    </div>
                                <?php elseif($is_working_hour): ?>
                                    <div class="flex items-center bg-white/5 border border-white/5 p-4 rounded-2xl hover:bg-white/[0.08] transition-all group">
                                        <div class="w-20 text-[10px] font-black text-gray-600 group-hover:text-gray-400 transition-colors"><?= $pretty_time ?></div>
                                        <div class="flex-1 ml-6 text-[9px] font-black text-emerald-500/40 uppercase tracking-[0.2em] group-hover:text-emerald-500 transition-colors">Available Slot</div>
                                    </div>
                                <?php else: ?>
                                    <div class="flex items-center opacity-20 p-3">
                                        <div class="w-20 text-[10px] font-black text-gray-700"><?= $pretty_time ?></div>
                                        <div class="h-px flex-1 bg-white/5 ml-6"></div>
                                    </div>
                                <?php endif; ?>
                                <?php $start_day = $slot_end; ?>
                            <?php endwhile; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</main>

</body>
</html>