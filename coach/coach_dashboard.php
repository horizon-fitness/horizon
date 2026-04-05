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

// Stats
$today = date('Y-m-d');
$today_count = 0;
$pending_count = 0;
$total_members_coached = 0;
$upcoming_sessions = 0;

if ($coach_id > 0) {
    // 0. Handle Booking Actions (Approve/Reject)
    if (isset($_GET['action']) && isset($_GET['booking_id'])) {
        $target_id = (int) $_GET['booking_id'];
        $status_map = ['approve' => 'Approved', 'reject' => 'Rejected'];
        if (isset($status_map[$_GET['action']])) {
            $updateStmt = $pdo->prepare("UPDATE bookings SET booking_status = ? WHERE booking_id = ? AND coach_id = ?");
            $updateStmt->execute([$status_map[$_GET['action']], $target_id, $coach_id]);
            header("Location: coach_dashboard.php?status=success");
            exit;
        }
    }

    // 1. Approved bookings for today
    $stmtToday = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE coach_id = ? AND booking_date = ? AND booking_status = 'Approved'");
    $stmtToday->execute([$coach_id, $today]);
    $today_count = $stmtToday->fetchColumn();

    // 2. Total pending bookings
    $stmtPending = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE coach_id = ? AND booking_status = 'Pending'");
    $stmtPending->execute([$coach_id]);
    $pending_count = $stmtPending->fetchColumn();

    // 3. Fetch Pending Booking List for UI
    $stmtPendingList = $pdo->prepare("
        SELECT b.*, u.first_name, u.last_name, gs.custom_service_name as service_name 
        FROM bookings b 
        JOIN members m ON b.member_id = m.member_id 
        JOIN users u ON m.user_id = u.user_id 
        JOIN gym_services gs ON b.gym_service_id = gs.gym_service_id 
        WHERE b.coach_id = ? AND b.booking_status = 'Pending' 
        ORDER BY b.booking_date ASC, b.start_time ASC
        LIMIT 5
    ");
    $stmtPendingList->execute([$coach_id]);
    $pending_bookings = $stmtPendingList->fetchAll();

    // 4. Total distinct members coached
    $stmtMembers = $pdo->prepare("SELECT COUNT(DISTINCT member_id) FROM bookings WHERE coach_id = ?");
    $stmtMembers->execute([$coach_id]);
    $total_members_coached = $stmtMembers->fetchColumn();

    // 5. Upcoming sessions (approved, from tomorrow onwards)
    $stmtUpcoming = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE coach_id = ? AND booking_date > ? AND booking_status = 'Approved'");
    $stmtUpcoming->execute([$coach_id, $today]);
    $upcoming_sessions = $stmtUpcoming->fetchColumn();
}
$pending_bookings = $pending_bookings ?? [];

// Fetch Today's Schedule (Approved Only)
$schedule_result = [];
if ($coach_id > 0) {
    $stmtSched = $pdo->prepare("
        SELECT b.*, u.first_name, u.last_name, u.username, gs.custom_service_name as service_name 
        FROM bookings b
        JOIN members m ON b.member_id = m.member_id
        JOIN users u ON m.user_id = u.user_id
        LEFT JOIN gym_services gs ON b.gym_service_id = gs.gym_service_id
        WHERE b.coach_id = ? AND b.booking_date = ? AND b.booking_status = 'Approved'
        ORDER BY b.start_time ASC
    ");
    $stmtSched->execute([$coach_id, $today]);
    $schedule_result = $stmtSched->fetchAll();
}

// Pagination Logic
$limit = 10;
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int) $_GET['page'] : 1;
if ($current_page < 1)
    $current_page = 1;
$offset = ($current_page - 1) * $limit;

// Fetch Total Count for Pagination
$total_approved = 0;
if ($coach_id > 0) {
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE coach_id = ? AND booking_date = ? AND booking_status = 'Approved'");
    $stmtCount->execute([$coach_id, $today]);
    $total_approved = $stmtCount->fetchColumn();
}
$total_pages = ceil($total_approved / $limit);

// Re-fetch Paginated Schedule
if ($coach_id > 0) {
    $stmtSched = $pdo->prepare("
        SELECT b.*, u.first_name, u.last_name, u.username, gs.custom_service_name as service_name 
        FROM bookings b
        JOIN members m ON b.member_id = m.member_id
        JOIN users u ON m.user_id = u.user_id
        LEFT JOIN gym_services gs ON b.gym_service_id = gs.gym_service_id
        WHERE b.coach_id = ? AND b.booking_date = ? AND b.booking_status = 'Approved'
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
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>Coach Portal | Horizon Systems</title>
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
                <?php if ($pending_count > 0): ?><span
                        class="size-1.5 rounded-full bg-primary alert-dot ml-auto"></span><?php endif; ?>
            </a>

            <a href="coach_schedule.php" class="nav-item text-gray-400 hover:text-white">
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
            <header
                class="mb-10 flex flex-col md:flex-row justify-between items-start md:items-end gap-6 animate-fade-in">
                <div>
                    <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white">Welcome Back, <span
                            class="text-primary"><?= htmlspecialchars($coach_name ?: 'Coach') ?></span></h2>
                    <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-1">Operational Overview •
                        Your Daily Performance</p>
                </div>
                <div class="flex flex-col items-end">
                    <p id="headerClock"
                        class="text-white font-black italic text-2xl leading-none transition-colors hover:text-primary font-black italic uppercase tracking-tighter text-white">
                        00:00:00 AM</p>
                    <p class="text-primary text-[10px] font-black uppercase tracking-[0.2em] leading-none mt-2">
                        <?= date('l, M d, Y') ?>
                    </p>
                </div>
            </header>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-10">
                <div class="glass-card p-6 flex items-center gap-4 animate-slide-up">
                    <div
                        class="size-12 rounded-full bg-emerald-500/10 flex items-center justify-center text-emerald-500">
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
                            <?php if ($pending_count > 0): ?>
                                <span
                                    class="px-1.5 py-0.5 rounded-md bg-primary/20 text-primary text-[7px] font-black uppercase tracking-widest alert-dot">Action</span>
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

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 pb-10">
                <!-- PENDING BOOKING REQUESTS -->
                <div class="glass-card flex flex-col overflow-hidden animate-slide-up shadow-2xl shadow-primary/5"
                    style="animation-delay: 0.4s;">
                    <div class="px-6 py-5 border-b border-white/5 flex justify-between items-center bg-white/[0.01]">
                        <h4
                            class="text-xs font-black italic uppercase tracking-tighter flex items-center gap-2 text-white">
                            <span class="material-symbols-outlined text-primary text-lg">pending_actions</span> Booking
                            Requests
                        </h4>
                        <div class="flex items-center gap-2">
                            <span class="size-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                            <p class="text-[8px] font-black uppercase tracking-widest text-amber-500">Waitlist</p>
                        </div>
                    </div>
                    <div class="p-2 flex-1 overflow-x-auto no-scrollbar">
                        <table class="w-full text-left">
                            <thead>
                                <tr
                                    class="text-[8px] font-black uppercase tracking-widest text-gray-600 border-b border-white/5">
                                    <th class="px-5 py-4">Requester</th>
                                    <th class="px-5 py-4 text-center">Schedule</th>
                                    <th class="px-5 py-4 text-right">Decision</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/5">
                                <?php if (empty($pending_bookings)): ?>
                                    <tr>
                                        <td colspan="3"
                                            class="px-6 py-12 text-center text-[10px] font-black uppercase tracking-widest text-gray-700 italic">
                                            No pending requests</td>
                                    </tr>
                                <?php else:
                                    foreach ($pending_bookings as $pb): ?>
                                        <tr class="hover:bg-white/[0.01] group transition-colors">
                                            <td class="px-5 py-4">
                                                <p
                                                    class="text-[11px] font-bold text-white uppercase group-hover:text-primary transition-colors italic">
                                                    <?= htmlspecialchars($pb['first_name'] . ' ' . $pb['last_name']) ?>
                                                </p>
                                                <p class="text-[8px] text-gray-600 font-black tracking-widest mt-0.5">
                                                    <?= strtoupper($pb['service_name']) ?>
                                                </p>
                                            </td>
                                            <td class="px-5 py-4 text-center">
                                                <p class="text-[10px] font-black italic text-gray-300">
                                                    <?= date('M d', strtotime($pb['booking_date'])) ?>
                                                </p>
                                                <p class="text-[8px] font-bold text-primary uppercase tracking-widest mt-0.5">
                                                    <?= date('h:i A', strtotime($pb['start_time'])) ?>
                                                </p>
                                            </td>
                                            <td class="px-5 py-4 text-right">
                                                <div class="flex justify-end gap-2">
                                                    <a href="?action=approve&booking_id=<?= $pb['booking_id'] ?>"
                                                        class="size-8 rounded-lg bg-emerald-500/10 text-emerald-500 flex items-center justify-center hover:bg-emerald-500 hover:text-white transition-all shadow-lg shadow-emerald-500/10">
                                                        <span class="material-symbols-outlined text-sm">check</span>
                                                    </a>
                                                    <a href="?action=reject&booking_id=<?= $pb['booking_id'] ?>"
                                                        onclick="return confirm('Reject this request?')"
                                                        class="size-8 rounded-lg bg-rose-500/10 text-rose-500 flex items-center justify-center hover:bg-rose-500 hover:text-white transition-all shadow-lg shadow-rose-500/10">
                                                        <span class="material-symbols-outlined text-sm">close</span>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- TODAY'S TRAINING TIMELINE -->
                <div class="glass-card flex flex-col overflow-hidden animate-slide-up shadow-2xl"
                    style="animation-delay: 0.5s;">
                    <div class="px-6 py-5 border-b border-white/5 flex justify-between items-center bg-white/[0.01]">
                        <h4
                            class="text-xs font-black italic uppercase tracking-tighter flex items-center gap-2 text-white">
                            <span class="material-symbols-outlined text-primary text-lg">history_toggle_off</span>
                            Today's Schedule
                        </h4>
                        <div class="flex items-center gap-2">
                            <span class="size-1.5 rounded-full bg-primary animate-pulse"></span>
                            <p class="text-[8px] font-black uppercase tracking-widest text-primary">Live Queue</p>
                        </div>
                    </div>
                    <div class="p-2 flex-1 overflow-x-auto no-scrollbar">
                        <table class="w-full text-left">
                            <thead>
                                <tr
                                    class="text-[8px] font-black uppercase tracking-widest text-gray-600 border-b border-white/5">
                                    <th class="px-5 py-4">Account</th>
                                    <th class="px-5 py-4 text-center">Type</th>
                                    <th class="px-5 py-4 text-right">Time</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/5">
                                <?php if (empty($schedule_result)): ?>
                                    <tr>
                                        <td colspan="3"
                                            class="px-6 py-12 text-center text-[10px] font-black uppercase tracking-widest text-gray-700 italic">
                                            No sessions today</td>
                                    </tr>
                                <?php else:
                                    foreach ($schedule_result as $row): ?>
                                        <tr class="hover:bg-white/[0.01] group transition-colors cursor-pointer"
                                            onclick="location.href='coach_workouts.php?member_id=<?= $row['member_id'] ?>'">
                                            <td class="px-5 py-4">
                                                <p
                                                    class="text-[11px] font-bold text-white uppercase group-hover:text-primary transition-colors italic">
                                                    <?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?>
                                                </p>
                                                <p class="text-[8px] text-gray-600 font-black tracking-widest mt-0.5">
                                                    @<?= htmlspecialchars($row['username']) ?></p>
                                            </td>
                                            <td class="px-5 py-4 text-center">
                                                <p class="text-[10px] font-black italic text-gray-300">
                                                    <?= htmlspecialchars($row['service_name'] ?: 'PT Session') ?>
                                                </p>
                                                <p class="text-[8px] font-bold text-gray-600 uppercase tracking-widest mt-0.5">
                                                    MEMBER</p>
                                            </td>
                                            <td class="px-5 py-4 text-right">
                                                <span
                                                    class="text-[9px] font-black text-white group-hover:text-primary transition-colors italic"><?= date('h:i A', strtotime($row['start_time'])) ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="px-6 py-4 border-t border-white/5 flex justify-center gap-4 bg-white/[0.01]">
                            <?php if ($current_page > 1): ?>
                                <a href="?page=<?= $current_page - 1 ?>"
                                    class="size-9 rounded-xl border border-white/5 bg-white/5 flex items-center justify-center text-gray-400 hover:text-white hover:bg-primary/20 hover:border-primary/20 transition-all">
                                    <span class="material-symbols-outlined text-sm">chevron_left</span>
                                </a>
                            <?php endif; ?>
                            <?php if ($current_page < $total_pages): ?>
                                <a href="?page=<?= $current_page + 1 ?>"
                                    class="size-9 rounded-xl border border-white/5 bg-white/5 flex items-center justify-center text-gray-400 hover:text-white hover:bg-primary/20 hover:border-primary/20 transition-all">
                                    <span class="material-symbols-outlined text-sm">chevron_right</span>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</body>

</html>