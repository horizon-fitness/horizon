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

// Fetch Coach ID (from staff table)
$stmtCoach = $pdo->prepare("SELECT staff_id as coach_id FROM staff WHERE user_id = ? AND gym_id = ? AND staff_role = 'Coach' LIMIT 1");
$stmtCoach->execute([$user_id, $gym_id]);
$coach = $stmtCoach->fetch();
$coach_id = $coach ? $coach['coach_id'] : 0;

$msg = '';
$week_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

// Handle Save Availability
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_availability'])) {
    if ($coach_id <= 0) {
        $msg = "Error: Coach profile not found. Please contact support.";
    } else {
        try {
            $pdo->beginTransaction();

            $stmtDelete = $pdo->prepare("DELETE FROM coach_schedules WHERE coach_id = ?");
            $stmtDelete->execute([$coach_id]);

            foreach ($week_days as $day) {
                $is_off = isset($_POST["off_$day"]) ? 1 : 0;
                $start = $_POST["start_$day"] ?? '08:00';
                $end = $_POST["end_$day"] ?? '12:00';
                $start2 = $_POST["start2_$day"] ?? '13:00';
                $end2 = $_POST["end2_$day"] ?? '17:00';

                $stmtInsert = $pdo->prepare("INSERT INTO coach_schedules (coach_id, day_of_week, start_time, end_time, start_time_2, end_time_2, availability_status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                $status = $is_off ? 'Off' : 'Available';
                $stmtInsert->execute([$coach_id, $day, $start, $end, $start2, $end2, $status]);
            }

            $pdo->commit();
            $msg = "Schedule updated successfully.";
        } catch (Exception $e) {
            if ($pdo->inTransaction())
                $pdo->rollBack();
            $msg = "Error updating schedule: " . $e->getMessage();
        }
    }
}

// Fetch current availability with normalized defaults
$avail_map = [];
foreach ($week_days as $day) {
    $avail_map[$day] = [
        'start_time' => '08:00',
        'end_time' => '12:00',
        'start_time_2' => '13:00',
        'end_time_2' => '17:00',
        'is_off_day' => 0
    ];
}

$stmtAvail = $pdo->prepare("SELECT * FROM coach_schedules WHERE coach_id = ?");
$stmtAvail->execute([$coach_id]);
$rows = $stmtAvail->fetchAll();
foreach ($rows as $r) {
    $d = $r['day_of_week'];
    if (isset($avail_map[$d])) {
        $avail_map[$d] = [
            'start_time' => !empty($r['start_time']) ? date('H:i', strtotime($r['start_time'])) : '08:00',
            'end_time' => !empty($r['end_time']) ? date('H:i', strtotime($r['end_time'])) : '12:00',
            'start_time_2' => !empty($r['start_time_2']) ? date('H:i', strtotime($r['start_time_2'])) : '13:00',
            'end_time_2' => !empty($r['end_time_2']) ? date('H:i', strtotime($r['end_time_2'])) : '17:00',
            'is_off_day' => (trim($r['availability_status']) === 'Off') ? 1 : 0
        ];
    }
}

$pending_count = 0;
if ($coach_id > 0) {
    $stmtPending = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE coach_id = ? AND booking_status = 'Pending'");
    $stmtPending->execute([$coach_id]);
    $pending_count = $stmtPending->fetchColumn();
}

// Fetch Approved Bookings for Daily View
$all_bookings = [];
if ($coach_id > 0) {
    $stmtBookings = $pdo->prepare("
        SELECT b.*, u.username, CONCAT(u.first_name, ' ', u.last_name) as fullname
        FROM bookings b
        JOIN members m ON b.member_id = m.member_id
        JOIN users u ON m.user_id = u.user_id
        WHERE b.coach_id = ? AND b.booking_status IN ('Approved', 'Pending')
    ");
    $stmtBookings->execute([$coach_id]);
    $fetched_bookings = $stmtBookings->fetchAll();

    foreach ($fetched_bookings as $fb) {
        $all_bookings[] = [
            'ts_start' => strtotime($fb['booking_date'] . ' ' . $fb['start_time']),
            'ts_end' => strtotime($fb['booking_date'] . ' ' . $fb['end_time']),
            'fullname' => $fb['fullname'],
            'username' => $fb['username'],
            'status' => $fb['booking_status']
        ];
    }
}
?>
<!DOCTYPE html>
<html class="dark" lang="en">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>My Schedule | Horizon Systems</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap"
        rel="stylesheet" />
    <link
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200"
        rel="stylesheet" />
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "<?= $page['theme_color'] ?? '#8c2bee' ?>", "background-dark": "<?= $page['bg_color'] ?? '#0a090d' ?>", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)" } } }
        }
    </script>
    <style>
        body {
            font-family: 'Lexend', sans-serif;
            background-color:
                <?= $page['bg_color'] ?? '#0a090d' ?>
            ;
            color: white;
            display: flex;
            flex-direction: row;
            min-height: 100vh;
            overflow: hidden;
        }

        .glass-card {
            background: #14121a;
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 24px;
        }

        /* Unified Sidebar Navigation Styles - MATCHING ADMIN DASHBOARD */
        .side-nav {
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
            background:
                <?= $page['bg_color'] ?? '#0a090d' ?>
            ;
            border-right: 1px solid rgba(255, 255, 255, 0.05);
        }

        .side-nav:hover {
            width: 300px;
        }

        .main-content {
            margin-left: 110px;
            flex: 1;
            min-width: 0;
            transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .side-nav:hover~.main-content {
            margin-left: 300px;
        }

        .nav-label {
            opacity: 0;
            transform: translateX(-15px);
            transition: all 0.3s ease-in-out;
            white-space: nowrap;
            pointer-events: none;
        }

        .side-nav:hover .nav-label {
            opacity: 1;
            transform: translateX(0);
            pointer-events: auto;
        }

        .nav-section-label {
            max-height: 0;
            opacity: 0;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            margin: 0 !important;
            pointer-events: none;
        }

        .side-nav:hover .nav-section-label {
            max-height: 20px;
            opacity: 1;
            margin-bottom: 8px !important;
            pointer-events: auto;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 10px 38px;
            transition: all 0.2s ease;
            text-decoration: none;
            white-space: nowrap;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #94a3b8;
        }

        .nav-item:hover {
            background: rgba(255, 255, 255, 0.05);
            color: white;
        }

        .nav-item.active {
            color:
                <?= $page['theme_color'] ?? '#8c2bee' ?>
                !important;
            position: relative;
        }

        .nav-item.active::after {
            content: '';
            position: absolute;
            right: 0px;
            top: 50%;
            transform: translateY(-50%);
            width: 4px;
            height: 24px;
            background:
                <?= $page['theme_color'] ?? '#8c2bee' ?>
            ;
            border-radius: 4px 0 0 4px;
        }

        .no-scrollbar::-webkit-scrollbar {
            display: none;
        }

        .no-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        @keyframes slideUp {
            from {
                transform: translateY(20px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .animate-slide-up {
            animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .alert-dot {
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% {
                opacity: 1;
            }

            50% {
                opacity: 0.4;
            }

            100% {
                opacity: 1;
            }
        }

        input[type="time"]::-webkit-calendar-picker-indicator {
            filter: invert(1);
            cursor: pointer;
        }

        .custom-scroll::-webkit-scrollbar {
            width: 6px;
        }

        .custom-scroll::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.02);
        }

        .custom-scroll::-webkit-scrollbar-thumb {
            background: #333;
            border-radius: 3px;
        }

        .tab-content {
            display: none;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
            animation: fadeIn 0.4s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        .day-card {
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        .day-card:hover {
            background: rgba(255, 255, 255, 0.05);
            border-color: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
        }

        .day-card.is-off {
            background: rgba(244, 63, 94, 0.02); /* rose-500 at 2% */
            border-color: rgba(244, 63, 94, 0.1);
        }

        .day-card.is-off .shift-inputs {
            opacity: 0.2;
            pointer-events: none;
            filter: grayscale(1);
        }

        /* Daily View Day Off Sync Style */
        .is-day-off-view .available-slot-box,
        .is-day-off-view .booked-slot-box {
            display: none !important;
        }

        .is-day-off-view .blank-slot-row {
            display: flex !important;
            opacity: 0.5 !important;
        }

        /* Modern Toggle Switch - Rose/Pink theme */
        .toggle-switch {
            position: relative;
            display: inline-flex;
            width: 52px;
            height: 28px;
            background-color: #1a1a1a;
            border-radius: 100px;
            padding: 4px;
            transition: all 0.3s ease;
            cursor: pointer;
            border: 1px solid rgba(255,255,255,0.05);
        }

        .toggle-switch .dot {
            width: 18px;
            height: 18px;
            background-color: white;
            border-radius: 50%;
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        .toggle-input:checked + .toggle-switch {
            background-color: #f43f5e; /* Rose-500 */
            border-color: #fb7185; /* Rose-400 */
            box-shadow: 0 0 15px rgba(244, 63, 94, 0.3);
        }

        .toggle-input:checked + .toggle-switch .dot {
            transform: translateX(24px);
        }
    </style>
    <script>
        function toggleDayOff(checkbox, dayName) {
            const card = document.getElementById('card-' + dayName);
            const statusLabel = document.getElementById('status-' + dayName);
            const miniLabel = document.getElementById('label-' + dayName);
            const timeline = document.getElementById('timeline-' + dayName);
            
            if (checkbox.checked) {
                card.classList.add('is-off');
                if (timeline) timeline.classList.add('is-day-off-view');
                if (statusLabel) {
                    statusLabel.textContent = 'DAY OFF';
                    statusLabel.className = 'px-5 py-2 bg-rose-500/10 text-rose-500 rounded-xl text-[8px] font-black uppercase tracking-widest border border-rose-500/10 shadow-lg shadow-rose-500/5 transition-all animate-pulse';
                }
                if (miniLabel) {
                    miniLabel.textContent = 'DAY OFF';
                    miniLabel.className = 'text-[9px] font-black uppercase tracking-widest text-rose-500 transition-colors';
                }
            } else {
                card.classList.remove('is-off');
                if (timeline) timeline.classList.remove('is-day-off-view');
                if (statusLabel) {
                    statusLabel.textContent = 'WORKING DAY';
                    statusLabel.className = 'px-5 py-2 bg-emerald-500/10 text-emerald-500 rounded-xl text-[8px] font-black uppercase tracking-widest border border-emerald-500/10 shadow-lg shadow-emerald-500/5';
                }
                if (miniLabel) {
                    miniLabel.textContent = 'WORKING';
                    miniLabel.className = 'text-[9px] font-black uppercase tracking-widest text-gray-500 transition-colors';
                }
            }
        }
        function updateHeaderClock() {
            const now = new Date();
            const clockEl = document.getElementById('headerClock');
            if (clockEl) clockEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }
        function openTab(dayName) {
            document.querySelectorAll('.tab-content').forEach(tc => tc.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(tb => {
                tb.classList.remove('active');
                tb.classList.remove('bg-primary', 'text-white', 'shadow-lg', 'shadow-primary/20');
                tb.classList.add('bg-white/5', 'text-gray-400');
            });
            document.getElementById(dayName).classList.add('active');
            const targetBtn = document.getElementById('btn-' + dayName);
            targetBtn.classList.remove('bg-white/5', 'text-gray-400');
            targetBtn.classList.add('active', 'bg-primary', 'text-white', 'shadow-lg', 'shadow-primary/20');
            localStorage.setItem('last_active_day', dayName);
        }
        setInterval(updateHeaderClock, 1000);
        window.addEventListener('DOMContentLoaded', function () {
            updateHeaderClock();
            const lastActiveDay = localStorage.getItem('last_active_day');
            if (lastActiveDay && document.getElementById(lastActiveDay)) {
                openTab(lastActiveDay);
            } else {
                const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                const today = days[new Date().getDay()];
                if (today !== 'Sunday' && document.getElementById(today)) { 
                    openTab(today); 
                } else { 
                    openTab('Monday'); 
                }
            }

            // Auto-dismiss alert after 5s
            const alert = document.getElementById('statusAlert');
            if (alert) {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    alert.style.transition = 'opacity 0.8s ease-out, transform 0.8s ease-out';
                    alert.style.transform = 'translateY(-10px)';
                    setTimeout(() => alert.style.display = 'none', 800);
                }, 5000);
            }
        });
    </script>
</head>

<body class="antialiased flex h-screen overflow-hidden">

    <nav class="side-nav flex flex-col fixed left-0 top-0 h-screen bg-background-dark border-r border-white/5 z-50">
        <div class="px-7 py-8 mb-4 shrink-0">
            <div class="flex items-center gap-4">
                <div
                    class="size-10 rounded-xl bg-primary flex items-center justify-center shadow-lg shrink-0 overflow-hidden">
                    <?php if (!empty($page['logo_path'])):
                        $logo_src = (strpos($page['logo_path'], 'data:image') === 0) ? $page['logo_path'] : '../' . $page['logo_path'];
                        ?>
                        <img src="<?= $logo_src ?>" class="size-full object-contain">
                    <?php else: ?>
                        <span class="material-symbols-outlined text-white text-2xl">bolt</span>
                    <?php endif; ?>
                </div>
                <h1
                    class="nav-label text-lg font-black italic uppercase tracking-tighter text-white font-black italic uppercase tracking-tighter text-white">
                    Coach Portal</h1>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto no-scrollbar space-y-1">
            <div class="nav-section-label px-[38px] mb-2">
                <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Main Menu</span>
            </div>

            <a href="coach_dashboard.php" class="nav-item">
                <span class="material-symbols-outlined text-xl shrink-0">grid_view</span>
                <span class="nav-label">Dashboard</span>
                <?php if ($pending_count > 0): ?><span
                        class="size-1.5 rounded-full bg-primary alert-dot ml-auto"></span><?php endif; ?>
            </a>

            <a href="coach_schedule.php" class="nav-item active">
                <span class="material-symbols-outlined text-xl shrink-0">edit_calendar</span>
                <span class="nav-label">My Availability</span>
            </a>

            <a href="coach_members.php" class="nav-item text-gray-400 hover:text-white">
                <span class="material-symbols-outlined text-xl shrink-0">groups</span>
                <span class="nav-label">My Members</span>
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
                <span
                    class="material-symbols-outlined text-xl shrink-0 group-hover:translate-x-1 transition-transform">logout</span>
                <span class="nav-label">Sign Out</span>
            </a>
        </div>
    </nav>

    <div class="main-content flex-1 overflow-y-auto no-scrollbar">
        <div class="p-10">
            <header class="mb-10 flex justify-between items-end">
                <div>
                    <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white leading-none">My <span
                            class="text-primary">Schedule</span></h2>
                    <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-1">Capacity & Slot Management
                    </p>
                </div>
                <div class="flex flex-col items-end text-right">
                    <p id="headerClock" class="text-white font-black italic text-2xl leading-none transition-colors hover:text-primary">00:00:00 AM</p>
                    <p class="text-primary text-[10px] font-black uppercase tracking-[0.2em] leading-none mt-2"><?= date('l, M d, Y') ?></p>
                </div>
            </header>

            <?php if ($msg): ?>
                <div id="statusAlert"
                    class="bg-emerald-500/10 border border-emerald-500/20 p-4 rounded-xl text-emerald-500 text-[11px] font-black uppercase italic mb-8 flex items-center justify-between group animate-fade-in">
                    <div class="flex items-center gap-3">
                        <span class="material-symbols-outlined text-base">check_circle</span>
                        <span><?= $msg ?></span>
                    </div>
                    <button onclick="document.getElementById('statusAlert').style.display='none'" 
                        class="size-6 flex items-center justify-center rounded-lg hover:bg-emerald-500/20 transition-all text-emerald-500/50 hover:text-emerald-500">
                        <span class="material-symbols-outlined text-sm">close</span>
                    </button>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 xl:grid-cols-12 gap-8 animate-slide-up">
                <div class="xl:col-span-5">
                    <div class="glass-card p-10 shadow-2xl h-fit overflow-hidden relative">
                        <div class="flex items-center gap-3 mb-10">
                            <div class="size-10 rounded-xl bg-primary/10 flex items-center justify-center text-primary">
                                <span class="material-symbols-outlined">settings_suggest</span>
                            </div>
                            <div>
                                <h3
                                    class="text-sm font-black uppercase italic tracking-widest text-white leading-tight">
                                    Availability Settings</h3>
                                <p class="text-[9px] font-bold text-gray-500 uppercase tracking-widest">Shift Parameters
                                </p>
                            </div>
                        </div>

                        <form method="POST" class="space-y-4">
                            <?php foreach ($week_days as $day):
                                $s1 = $avail_map[$day]['start_time'] ?? '08:00';
                                $e1 = $avail_map[$day]['end_time'] ?? '12:00';
                                $s2 = $avail_map[$day]['start_time_2'] ?? '13:00';
                                $e2 = $avail_map[$day]['end_time_2'] ?? '17:00';
                                $off = ($avail_map[$day]['is_off_day'] ?? 0) == 1;
                                ?>
                                <div id="card-<?= $day ?>"
                                    class="day-card p-6 rounded-[24px] transition-all <?= $off ? 'is-off' : '' ?>">
                                    <div class="flex justify-between items-center mb-6">
                                        <div class="flex flex-col">
                                            <span class="font-black italic uppercase text-xs text-white tracking-[0.1em]"><?= $day ?></span>
                                            <p class="text-[11px] font-bold text-gray-500 uppercase tracking-widest mt-1">Availability Status</p>
                                        </div>
                                        
                                        <div class="flex items-center gap-3">
                                            <span class="text-[11px] font-black uppercase tracking-widest <?= $off ? 'text-rose-500' : 'text-gray-500' ?> transition-colors" id="label-<?= $day ?>">
                                                <?= $off ? 'DAY OFF' : 'WORKING' ?>
                                            </span>
                                            <label class="relative inline-flex items-center cursor-pointer">
                                                <input type="checkbox" name="off_<?= $day ?>" value="1" 
                                                    <?= $off ? 'checked' : '' ?> 
                                                    class="sr-only toggle-input" 
                                                    onchange="toggleDayOff(this, '<?= $day ?>')">
                                                <div class="toggle-switch">
                                                    <div class="dot"></div>
                                                </div>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="shift-inputs space-y-6 transition-all duration-300">
                                        <div>
                                            <p class="text-[11px] text-primary uppercase font-black mb-4 tracking-[0.2em] italic flex items-center gap-2">
                                                <span class="size-1 bg-primary rounded-full"></span> Shift 1
                                            </p>
                                            <div class="grid grid-cols-2 gap-4">
                                                <div>
                                                    <p class="text-xs text-gray-600 uppercase font-bold mb-3 tracking-widest">Start Time</p>
                                                    <input type="time" name="start_<?= $day ?>" value="<?= substr($s1, 0, 5) ?>"
                                                        class="w-full bg-black/40 border border-white/5 rounded-xl p-3 text-sm text-white outline-none focus:border-primary transition-all font-medium">
                                                </div>
                                                <div>
                                                    <p class="text-xs text-gray-600 uppercase font-bold mb-3 tracking-widest">End Time</p>
                                                    <input type="time" name="end_<?= $day ?>" value="<?= substr($e1, 0, 5) ?>"
                                                        class="w-full bg-black/40 border border-white/5 rounded-xl p-3 text-sm text-white outline-none focus:border-primary transition-all font-medium">
                                                </div>
                                            </div>
                                        </div>

                                        <div>
                                            <p class="text-[11px] text-primary/60 uppercase font-black mb-4 tracking-[0.2em] italic flex items-center gap-2">
                                                <span class="size-1 bg-primary/60 rounded-full"></span> Shift 2
                                            </p>
                                            <div class="grid grid-cols-2 gap-4">
                                                <div>
                                                    <p class="text-xs text-gray-600 uppercase font-bold mb-3 tracking-widest">Start Time</p>
                                                    <input type="time" name="start2_<?= $day ?>" value="<?= substr($s2, 0, 5) ?>"
                                                        class="w-full bg-black/40 border border-white/5 rounded-xl p-3 text-sm text-white outline-none focus:border-primary transition-all font-medium">
                                                </div>
                                                <div>
                                                    <p class="text-xs text-gray-600 uppercase font-bold mb-3 tracking-widest">End Time</p>
                                                    <input type="time" name="end2_<?= $day ?>" value="<?= substr($e2, 0, 5) ?>"
                                                        class="w-full bg-black/40 border border-white/5 rounded-xl p-3 text-sm text-white outline-none focus:border-primary transition-all font-medium">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <div class="pt-6">
                                <button type="submit" name="save_availability"
                                    class="w-full bg-primary hover:bg-primary/90 text-white py-5 rounded-[24px] font-black uppercase text-[11px] tracking-[0.2em] shadow-2xl shadow-primary/30 transition-all active:scale-[0.97] flex items-center justify-center gap-3">
                                    <span class="material-symbols-outlined text-lg">save_as</span> Update Availability
                                </button>
                            </div>
                        </form>
                        <div class="absolute -left-10 -bottom-10 size-40 bg-primary/5 rounded-full blur-3xl"></div>
                    </div>
                </div>

                <div class="xl:col-span-7">
                    <div class="glass-card p-10 flex flex-col h-full min-h-[850px] shadow-2xl relative overflow-hidden">
                        <div class="flex items-center gap-3 mb-10">
                            <div class="size-10 rounded-xl bg-primary/10 flex items-center justify-center text-primary">
                                <span class="material-symbols-outlined">calendar_month</span>
                            </div>
                            <div>
                                <h3
                                    class="text-sm font-black uppercase italic tracking-widest text-white leading-tight">
                                    Daily Schedule View</h3>
                                <p class="text-[9px] font-bold text-gray-500 uppercase tracking-widest">Coaching
                                    Timeline</p>
                            </div>
                        </div>

                        <div class="flex gap-2 mb-10 overflow-x-auto no-scrollbar pb-2 border-b border-white/5">
                            <?php foreach ($week_days as $day): ?>
                                <button id="btn-<?= $day ?>" onclick="openTab('<?= $day ?>')"
                                    class="tab-btn px-6 py-2.5 rounded-xl text-[9px] font-black uppercase tracking-widest transition-all">
                                    <?= substr($day, 0, 3) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>

                        <div class="flex-1 custom-scroll overflow-y-auto pr-2">
                                <?php
                                foreach ($week_days as $index => $day_name):
                                    $loop_date = date('Y-m-d', strtotime("monday this week +$index days"));
                                    $day_data = $avail_map[$day_name];
                                    $is_off = (int)($day_data['is_off_day'] ?? 0) === 1;
                                    
                                    // Shift 1 Bound
                                    $s1_ts = strtotime($loop_date . ' ' . ($day_data['start_time'] ?? '08:00'));
                                    $e1_ts = strtotime($loop_date . ' ' . ($day_data['end_time'] ?? '12:00'));
                                    
                                    // Shift 2 Bound
                                    $s2_ts = strtotime($loop_date . ' ' . ($day_data['start_time_2'] ?? '13:00'));
                                    $e2_ts = strtotime($loop_date . ' ' . ($day_data['end_time_2'] ?? '17:00'));
                                    ?>
                                <div id="<?= $day_name ?>" class="tab-content transition-all">
                                    <div class="flex justify-between items-center mb-10 pb-6 border-b border-white/5">
                                        <div>
                                            <h4 class="text-2xl font-black italic uppercase text-white leading-none">
                                                <?= $day_name ?></h4>
                                            <p
                                                class="text-[10px] font-black text-gray-500 uppercase tracking-widest mt-2 px-1 opacity-60">
                                                <?= date('F d, Y', strtotime($loop_date)) ?></p>
                                        </div>
                                        <?php if ($is_off): ?>
                                            <span id="status-<?= $day_name ?>"
                                                class="px-5 py-2 bg-rose-500/10 text-rose-500 rounded-xl text-[8px] font-black uppercase tracking-widest border border-rose-500/10 shadow-lg shadow-rose-500/5 transition-all animate-pulse">DAY OFF</span>
                                        <?php else: ?>
                                            <span id="status-<?= $day_name ?>"
                                                class="px-5 py-2 bg-emerald-500/10 text-emerald-500 rounded-xl text-[8px] font-black uppercase tracking-widest border border-emerald-500/10 shadow-lg shadow-emerald-500/5">WORKING DAY</span>
                                        <?php endif; ?>
                                    </div>

                                    <div id="timeline-<?= $day_name ?>" class="space-y-4 <?= $is_off ? 'is-day-off-view' : '' ?>">
                                        <?php
                                        $start_day = strtotime($loop_date . ' 07:00');
                                        $end_day = strtotime($loop_date . ' 22:00');

                                        while ($start_day < $end_day):
                                            $slot_end = strtotime('+30 minutes', $start_day);
                                            $pretty_range = date('h:i A', $start_day) . ' - ' . date('h:i A', $slot_end);

                                            $found_booking = null;
                                            foreach ($all_bookings as $b) {
                                                if ($b['ts_start'] < $slot_end && $b['ts_end'] > $start_day) {
                                                    $found_booking = $b;
                                                    break;
                                                }
                                            }

                                            $is_working_hour = (
                                                ($start_day >= $s1_ts && $start_day < $e1_ts) || 
                                                ($start_day >= $s2_ts && $start_day < $e2_ts)
                                            ) && !$is_off;
                                            ?>
                                            <?php if ($found_booking): 
                                                $is_pending = ($found_booking['status'] === 'Pending');
                                                $accent_color = $is_pending ? 'amber-500' : 'emerald-500';
                                                $bg_color = $is_pending ? 'bg-amber-500/10' : 'bg-emerald-500/10';
                                                $border_color = $is_pending ? 'border-amber-500/30' : 'border-emerald-500/30';
                                                $text_color = $is_pending ? 'text-amber-500' : 'text-emerald-500';
                                                $icon = $is_pending ? 'timer' : 'verified';
                                                $status_label = $is_pending ? 'Pending Request' : 'Approved Training';
                                            ?>
                                                <div
                                                    class="booked-slot-box flex items-center <?= $bg_color ?> border <?= $border_color ?> p-6 rounded-[24px] shadow-2xl animate-slide-up group relative overflow-hidden">
                                                    <div class="w-44 text-[11px] font-black <?= $text_color ?> leading-none shrink-0 border-r-2 <?= $border_color ?> pr-6 mr-6 flex items-center">
                                                        <?= date('h:i A', $start_day) ?> - <?= date('h:i A', $slot_end) ?>
                                                    </div>
                                                    <div class="flex-1">
                                                        <p class="text-[10px] font-black <?= $text_color ?> uppercase tracking-[0.2em] mb-1">
                                                            BOOKED BY:</p>
                                                        <p
                                                            class="text-base font-black text-white uppercase italic group-hover:text-<?= $accent_color ?> transition-colors">
                                                            <?= htmlspecialchars($found_booking['fullname']) ?></p>
                                                        <p
                                                            class="text-[10px] text-gray-400 font-bold uppercase tracking-widest mt-1">
                                                            <?= $status_label ?></p>
                                                    </div>
                                                    <span
                                                        class="material-symbols-outlined <?= $text_color ?> text-2xl group-hover:scale-110 transition-transform"><?= $icon ?></span>
                                                    <div
                                                        class="absolute -right-4 -bottom-4 size-24 bg-<?= $accent_color ?>/5 rounded-full blur-2xl">
                                                     </div>
                                                </div>
                                            <?php elseif ($is_working_hour): ?>
                                                <div
                                                    class="available-slot-box flex items-center bg-white/[0.02] border border-white/5 p-5 rounded-[20px] hover:bg-white/[0.04] hover:border-emerald-500/30 transition-all group">
                                                    <div
                                                        class="w-44 text-[11px] font-black text-gray-500 group-hover:text-emerald-500 transition-colors leading-none shrink-0 border-r-2 border-white/10 pr-6 mr-6 flex items-center">
                                                        <?= date('h:i A', $start_day) ?> - <?= date('h:i A', $slot_end) ?>
                                                    </div>
                                                    <div class="flex-1">
                                                        <p
                                                            class="text-[11px] font-black text-emerald-500 uppercase tracking-[0.2em] group-hover:text-emerald-400 transition-colors">
                                                            AVAILABLE SLOT</p>
                                                    </div>
                                                    <span
                                                        class="material-symbols-outlined text-emerald-500/20 group-hover:text-emerald-500 transition-colors text-lg italic">add_task</span>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <!-- Standard Blank Row (Always exists but selectively visible via CSS) -->
                                            <div class="blank-slot-row <?= ($is_off || (!$found_booking && !$is_working_hour)) ? 'flex' : 'hidden' ?> items-center py-8 px-5 hover:opacity-50 transition-all cursor-default">
                                                <div class="w-44 text-[11px] font-black text-gray-500 leading-normal uppercase opacity-70 shrink-0 border-r border-white/10 pr-6 mr-6 flex items-center">
                                                    <?= date('h:i A', $start_day) ?> - <?= date('h:i A', $slot_end) ?>
                                                </div>
                                                <div class="h-px flex-1 bg-white/10"></div>
                                            </div>
                                            <?php $start_day = $slot_end; ?>
                                        <?php endwhile; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>