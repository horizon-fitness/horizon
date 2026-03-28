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

// --- ADDED: Fetch Gym Details to fix the 500 Error ---
$stmtGym = $pdo->prepare("SELECT * FROM gyms WHERE gym_id = ? LIMIT 1");
$stmtGym->execute([$gym_id]);
$gym = $stmtGym->fetch();
// ----------------------------------------------------

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
        $pdo->rollBack();
        $msg = "Error updating schedule: " . $e->getMessage();
    }
}

// Fetch current availability
$avail_map = [];
$stmtAvail = $pdo->prepare("SELECT * FROM coach_schedules WHERE coach_id = ?");
$stmtAvail->execute([$coach_id]);
$rows = $stmtAvail->fetchAll();
foreach ($rows as $r) {
    $avail_map[$r['day_of_week']] = [
        'start_time' => $r['start_time'],
        'end_time' => $r['end_time'],
        'is_off_day' => ($r['availability_status'] === 'Off') ? 1 : 0
    ];
}

$pending_count = 0;
if ($coach_id > 0) {
    $stmtPending = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE coach_id = ? AND booking_status = 'Pending'");
    $stmtPending->execute([$coach_id]);
    $pending_count = $stmtPending->fetchColumn();
}

// Fetch Bookings for Daily View
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
    <title>My Schedule | Herdoza Coach</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "<?= $_SESSION['theme_color'] ?? '#8c2bee' ?>", "surface-dark": "#14121a", "background-dark": "#0a090d", "border-subtle": "rgba(255,255,255,0.05)" } } }
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
        function openTab(dayName) {
            var i, tabcontent, tablinks;
            tabcontent = document.getElementsByClassName("tab-content");
            for (i = 0; i < tabcontent.length; i++) {
                tabcontent[i].style.display = "none";
            }
            tablinks = document.getElementsByClassName("tab-btn");
            for (i = 0; i < tablinks.length; i++) {
                tablinks[i].classList.remove("bg-primary", "text-white");
                tablinks[i].classList.add("bg-white/5", "text-gray-400");
            }
            document.getElementById(dayName).style.display = "block";
            document.getElementById("btn-" + dayName).classList.remove("bg-white/5", "text-gray-400");
            document.getElementById("btn-" + dayName).classList.add("bg-primary", "text-white");
        }
        window.onload = function() { 
            setInterval(updateHeaderClock, 1000); 
            updateHeaderClock();
            const days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
            const today = days[new Date().getDay()];
            if(today !== 'Sunday' && document.getElementById(today)) { openTab(today); } else { openTab('Monday'); }
        };
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; padding-bottom: 100px; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        
        /* Sidebar Hover Logic - ADJUSTED WIDTHS */
        .sidebar-nav {
            width: 110px; 
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .sidebar-nav:hover {
            width: 300px; 
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

        .nav-link { font-size: 11px; font-weight: 800; letter-spacing: 0.05em; transition: all 0.2s; white-space: nowrap; }
        .active-nav { color: #8c2bee !important; position: relative; }
        .active-nav::after { 
            content: ''; 
            position: absolute; 
            right: 0px; 
            top: 50%;
            transform: translateY(-50%);
            width: 4px; 
            height: 20px; 
            background: #8c2bee; 
            border-radius: 99px; 
        }
        @media (max-width: 1024px) { 
            .sidebar-nav { width: 100%; height: auto; position: relative; }
            .sidebar-nav:hover { width: 100%; }
            .nav-text { opacity: 1; transform: translateX(0); pointer-events: auto; }
            .active-nav::after { width: 100%; height: 3px; bottom: -8px; left: 0; top: auto; right: auto; transform: none; } 
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

        input[type="time"]::-webkit-calendar-picker-indicator { filter: invert(1); cursor: pointer; }
        .custom-scroll::-webkit-scrollbar { width: 6px; }
        .custom-scroll::-webkit-scrollbar-track { background: rgba(255,255,255,0.02); }
        .custom-scroll::-webkit-scrollbar-thumb { background: #333; border-radius: 3px; }
    </style>
</head>
<body class="antialiased flex flex-col lg:flex-row min-h-screen">

    <nav class="sidebar-nav hidden lg:flex flex-col bg-[#0a090d] border-r border-white/5 sticky top-0 h-screen px-7 py-8 z-50 shrink-0">
    <div class="mb-10 shrink-0"> 
        <div class="flex items-center gap-4 mb-4 min-w-[240px]"> 
            <div class="size-11 rounded-xl bg-primary flex items-center justify-center shadow-lg shrink-0 overflow-hidden">
                <?php if (!empty($gym['logo_path'])): ?>
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
    
    <div class="flex-1 overflow-y-auto no-scrollbar space-y-1 pr-2">
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
                <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white leading-none">My <span class="text-primary">Schedule</span></h2>
                <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-2">Gym Hours: 8:00 AM - 9:00 PM (Mon-Sat)</p>
            </div>
            <div class="text-right">
                <p id="headerClock" class="text-white font-black italic text-xl tracking-tight leading-none mb-2">00:00:00 AM</p>
                <p class="text-primary text-[9px] font-black uppercase tracking-[0.2em]"><?= date('l, M d') ?></p>
            </div>
        </header>

        <?php if($msg): ?>
            <div class="bg-emerald-500/10 border border-emerald-500/20 p-4 rounded-xl text-emerald-500 text-[10px] font-black uppercase italic mb-8 flex items-center gap-2 animate-fade-in"><span class="material-symbols-outlined text-sm">check_circle</span> <?= $msg ?></div>
        <?php endif; ?>

        <div class="grid grid-cols-1 xl:grid-cols-12 gap-8 lg:h-[calc(100vh-250px)] animate-slide-up"> 
            
            <div class="xl:col-span-5 h-full">
                <div class="glass-card p-6 shadow-xl h-full flex flex-col">
                    <div class="flex items-center gap-2 mb-6 text-primary shrink-0">
                        <span class="material-symbols-outlined">settings_clock</span>
                        <h3 class="text-sm font-black uppercase italic">Availability Settings</h3>
                    </div>
                    <form method="POST" class="space-y-4 overflow-y-auto custom-scroll pr-2 flex-1">
                        <?php foreach($week_days as $day): 
                            $s1 = $avail_map[$day]['start_time'] ?? '07:00';
                            $e1 = $avail_map[$day]['end_time'] ?? '12:00';
                            $s2 = $avail_map[$day]['start_time_2'] ?? '';
                            $e2 = $avail_map[$day]['end_time_2'] ?? '';
                            $off = ($avail_map[$day]['is_off_day'] ?? 0) == 1;
                        ?>
                        <div class="bg-white/5 p-4 rounded-xl border border-white/5">
                            <div class="flex justify-between items-center mb-3">
                                <span class="font-bold text-xs uppercase text-white"><?= $day ?></span>
                                <label class="inline-flex items-center cursor-pointer">
                                    <input type="checkbox" name="off_<?= $day ?>" value="1" <?= $off ? 'checked' : '' ?> class="sr-only peer">
                                    <div class="relative w-9 h-5 bg-gray-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:bg-red-500 after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all"></div>
                                    <span class="ms-2 text-[9px] font-black uppercase text-gray-500 peer-checked:text-red-500">Day Off</span>
                                </label>
                            </div>
                            <div class="grid grid-cols-2 gap-2 mb-2">
                                <div>
                                    <p class="text-[8px] text-gray-500 uppercase font-bold mb-1">Working Hours</p>
                                    <div class="flex gap-1">
                                        <input type="time" name="start_<?= $day ?>" value="<?= substr($s1, 0, 5) ?>" min="07:00" max="21:00" class="w-full bg-black/30 border border-white/10 rounded-lg p-2 text-xs text-white outline-none focus:border-primary">
                                        <input type="time" name="end_<?= $day ?>" value="<?= substr($e1, 0, 5) ?>" min="07:00" max="21:00" class="w-full bg-black/30 border border-white/10 rounded-lg p-2 text-xs text-white outline-none focus:border-primary">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <button type="submit" name="save_availability" class="w-full bg-primary text-white py-3 rounded-xl font-black uppercase text-xs tracking-widest hover:scale-[1.02] transition-transform sticky bottom-0">Save Settings</button>
                    </form>
                </div>
            </div>

            <div class="xl:col-span-7 h-full">
                <div class="glass-card p-6 shadow-xl h-full flex flex-col">
                    <div class="flex justify-between items-center mb-6 shrink-0">
                        <div class="flex items-center gap-2 text-white">
                            <span class="material-symbols-outlined text-emerald-500">calendar_month</span>
                            <h3 class="text-sm font-black uppercase italic">Daily View</h3>
                        </div>
                    </div>

                    <div class="flex gap-2 overflow-x-auto pb-2 mb-4 shrink-0 custom-scroll">
                        <?php foreach($week_days as $day): ?>
                            <button id="btn-<?= $day ?>" onclick="openTab('<?= $day ?>')" class="tab-btn px-4 py-2 rounded-lg text-[10px] font-black uppercase bg-white/5 text-gray-400 hover:bg-white/10 transition-colors whitespace-nowrap">
                                <?= substr($day, 0, 3) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <div class="overflow-y-auto custom-scroll flex-1 pr-2">
                        <?php 
                        foreach($week_days as $index => $day_name): 
                            $loop_date = date('Y-m-d', strtotime("monday this week +$index days"));
                            $is_off = ($avail_map[$day_name]['is_off_day'] ?? 0) == 1;
                            
                            $s1_ts = strtotime($loop_date . ' ' . ($avail_map[$day_name]['start_time'] ?? '07:00'));
                            $e1_ts = strtotime($loop_date . ' ' . ($avail_map[$day_name]['end_time'] ?? '12:00'));
                            $s2_ts = ($avail_map[$day_name]['start_time_2'] ?? '') ? strtotime($loop_date . ' ' . $avail_map[$day_name]['start_time_2']) : 0;
                            $e2_ts = ($avail_map[$day_name]['end_time_2'] ?? '') ? strtotime($loop_date . ' ' . $avail_map[$day_name]['end_time_2']) : 0;
                        ?>
                        <div id="<?= $day_name ?>" class="tab-content" style="display: none;">
                            <div class="flex justify-between items-center mb-4 border-b border-white/10 pb-2">
                                <h4 class="text-xl font-black italic text-white"><?= $day_name ?> <span class="text-sm font-bold text-gray-500 not-italic ml-2"><?= date('M d', strtotime($loop_date)) ?></span></h4>
                                <?php if($is_off): ?>
                                    <span class="px-3 py-1 bg-red-500/10 text-red-500 rounded-full text-[10px] font-black uppercase">Day Off</span>
                                <?php else: ?>
                                    <span class="px-3 py-1 bg-emerald-500/10 text-emerald-500 rounded-full text-[10px] font-black uppercase">Working Day</span>
                                <?php endif; ?>
                            </div>

                            <div class="space-y-2">
                                <?php 
                                $start_day = strtotime($loop_date . ' 07:00');
                                $end_day   = strtotime($loop_date . ' 21:00');
                                
                                while($start_day < $end_day): 
                                    $slot_end = strtotime('+30 minutes', $start_day);
                                    $pretty_time = date('h:i A', $start_day) . ' - ' . date('h:i A', $slot_end);
                                    
                                    $found_booking = null;
                                    foreach($all_bookings as $b) {
                                        if ($b['ts_start'] < $slot_end && $b['ts_end'] > $start_day) {
                                            $found_booking = $b;
                                            break;
                                        }
                                    }
                                    
                                    $in_shift_1 = ($start_day >= $s1_ts && $start_day < $e1_ts);
                                    $in_shift_2 = ($start_day >= $s2_ts && $start_day < $e2_ts);
                                    $is_working_hour = ($in_shift_1 || $in_shift_2) && !$is_off;
                                ?>
                                    <?php if($found_booking): ?>
                                        <div class="flex items-center bg-primary/20 border-l-4 border-primary p-3 rounded-lg">
                                            <div class="w-24 text-[10px] font-black text-white"><?= $pretty_time ?></div>
                                            <div class="flex-1 ml-4">
                                                <p class="text-sm font-black text-primary uppercase italic"><?= htmlspecialchars($found_booking['fullname']) ?></p>
                                                <p class="text-[10px] text-gray-400">@<?= htmlspecialchars($found_booking['username']) ?></p>
                                            </div>
                                            <div class="text-[9px] font-bold text-white bg-primary px-2 py-1 rounded">TAKEN</div>
                                        </div>
                                    <?php elseif($is_working_hour): ?>
                                        <div class="flex items-center bg-white/5 border-l-4 border-emerald-500/50 p-3 rounded-lg hover:bg-white/10 transition-colors">
                                            <div class="w-24 text-[10px] font-bold text-gray-400"><?= $pretty_time ?></div>
                                            <div class="flex-1 ml-4 text-[10px] font-bold text-emerald-500 uppercase tracking-widest">Available Slot</div>
                                        </div>
                                    <?php else: ?>
                                        <div class="flex items-center opacity-30 p-2">
                                            <div class="w-24 text-[10px] font-bold text-gray-600"><?= $pretty_time ?></div>
                                            <div class="flex-1 ml-4 text-[10px] font-bold text-gray-700 uppercase">-- Off --</div>
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
    
    <div class="fixed bottom-0 left-0 right-0 h-20 mobile-taskbar z-[100] lg:hidden flex items-center justify-around px-4">
        <a href="coach_dashboard.php" class="flex flex-col items-center gap-1 text-gray-500 relative">
            <span class="material-symbols-outlined">grid_view</span>
            <span class="text-[8px] font-black uppercase">Dashboard</span>
            <?php if($pending_count > 0): ?><span class="absolute top-0 right-0 size-2 bg-primary rounded-full alert-dot"></span><?php endif; ?>
        </a>
        <a href="coach_schedule.php" class="flex flex-col items-center gap-1 text-primary">
            <span class="material-symbols-outlined">edit_calendar</span>
            <span class="text-[8px] font-black uppercase">Avail</span>
        </a>
        <a href="coach_members.php" class="flex flex-col items-center gap-1 text-gray-500">
            <span class="material-symbols-outlined">groups</span>
            <span class="text-[8px] font-black uppercase">Members</span>
        </a>
        <a href="coach_profile.php" class="flex flex-col items-center gap-1 text-gray-500">
            <span class="material-symbols-outlined">person</span>
            <span class="text-[8px] font-black uppercase">Profile</span>
        </a>
    </div>

</body>
</html>