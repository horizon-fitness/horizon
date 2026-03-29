<?php
session_start();
require_once '../db.php';

// Security Check
$role = strtolower($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || ($role !== 'staff' && $role !== 'coach')) {
    header("Location: ../login.php");
    exit;
}

$gym_id = $_SESSION['gym_id'];
$adminName = $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];

// Fetch Gym Details
$stmtGym = $pdo->prepare("SELECT * FROM gyms WHERE gym_id = ?");
$stmtGym->execute([$gym_id]);
$gym = $stmtGym->fetch();

// Active CMS Page (for logo & theme)
$stmtPage = $pdo->prepare("SELECT * FROM tenant_pages WHERE gym_id = ? LIMIT 1");
$stmtPage->execute([$gym_id]);
$page = $stmtPage->fetch();

// --- VIEW & FILTER LOGIC (from GET params) ---
$view = $_GET['view'] ?? 'history';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$search_query = $_GET['search'] ?? '';

// --- FETCH ACTUAL ATTENDANCE LOGS ---
$query = "
    SELECT a.*, u.username, CONCAT(u.first_name, ' ', u.last_name) as fullname, gs.duration_minutes as scheduled_duration
    FROM attendance a 
    JOIN members m ON a.member_id = m.member_id 
    JOIN users u ON m.user_id = u.user_id 
    LEFT JOIN bookings b ON a.booking_id = b.booking_id
    LEFT JOIN gym_services gs ON b.gym_service_id = gs.gym_service_id
    WHERE a.gym_id = ?
";
$params = [$gym_id];

if ($view === 'live') {
    $query .= " AND a.check_out_time IS NULL";
}

if ($start_date) {
    $query .= " AND a.attendance_date >= ?";
    $params[] = $start_date;
}
if ($end_date) {
    $query .= " AND a.attendance_date <= ?";
    $params[] = $end_date;
}
if ($search_query) {
    $query .= " AND (u.username LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
    $sterm = "%$search_query%";
    $params[] = $sterm;
    $params[] = $sterm;
    $params[] = $sterm;
}

$query .= " ORDER BY a.attendance_date DESC, a.check_in_time DESC";

$stmtLogs = $pdo->prepare($query);
$stmtLogs->execute($params);
$attendance_list = $stmtLogs->fetchAll();

$checkins_today = 0;
$stmtToday = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE gym_id = ? AND attendance_date = CURRENT_DATE");
$stmtToday->execute([$gym_id]);
$checkins_today = $stmtToday->fetchColumn();

?>

<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Attendance History | Horizon Partners</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "<?= $page['theme_color'] ?? '#8c2bee' ?>", "background-dark": "<?= $page['bg_color'] ?? '#0a090d' ?>", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)" }}}
        }
        function updateHeaderClock() {
            const now = new Date();
            const clockEl = document.getElementById('headerClock');
            if (clockEl) {
                clockEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            }
        }
        window.onload = function() { setInterval(updateHeaderClock, 1000); updateHeaderClock(); };
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: <?= $page['bg_color'] ?? '#0a090d' ?>; color: white; display: flex; flex-direction: row; min-h-screen: 100vh; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        
        /* Sidebar Hover Logic - MATCHING CORE DASHBOARD */
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
        }
        .side-nav:hover {
            width: 300px; 
        }

        /* Main Content Adjustment */
        .main-content {
            margin-left: 110px; 
            flex: 1;
            min-width: 0;
            transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .side-nav:hover ~ .main-content {
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
            margin-bottom: 0px !important; 
            pointer-events: auto;
        }
        .side-nav:hover .mt-0 { margin-top: 0px !important; } 

        .nav-item { 
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
        .nav-item:hover { background: rgba(255,255,255,0.05); color: white; }
        .nav-item.active { color: <?= $page['theme_color'] ?? '#8c2bee' ?> !important; position: relative; }
        .nav-item.active::after { 
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
        
        .alert-pulse { animation: alert-pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite; }
        @keyframes alert-pulse { 0%, 100% { opacity: 1; } 50% { opacity: .5; } }
        input[type="date"]::-webkit-calendar-picker-indicator { filter: invert(1); opacity: 0.5; cursor: pointer; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>
<body class="antialiased min-h-screen">

<nav class="side-nav flex flex-col fixed left-0 top-0 h-screen bg-background-dark border-r border-white/5 z-50">
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
            <span class="nav-label text-white font-black italic uppercase tracking-tighter text-base leading-none">Staff Portal</span>
        </div>
    </div>
    
    <div class="flex flex-col flex-1 overflow-y-auto no-scrollbar gap-0.5">
        <span class="nav-section-label text-[10px] font-black text-gray-500 uppercase tracking-widest px-[38px] mt-0">Main Menu</span>
        
        <a href="admin_dashboard.php" class="nav-item <?= (basename($_SERVER['PHP_SELF']) == 'admin_dashboard.php') ? 'active' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">grid_view</span>
            <span class="nav-label">Dashboard</span>
        </a>

        <a href="register_member.php" class="nav-item <?= (basename($_SERVER['PHP_SELF']) == 'register_member.php') ? 'active' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">person_add</span>
            <span class="nav-label">Walk-in Member</span>
        </a>

        <span class="nav-section-label text-[10px] font-black text-gray-500 uppercase tracking-widest px-[38px] mt-4 mb-2">Management</span>
        
        <a href="admin_users.php" class="nav-item <?= (basename($_SERVER['PHP_SELF']) == 'admin_users.php') ? 'active' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">group</span>
            <span class="nav-label">My Users</span>
        </a>

        <a href="admin_transaction.php" class="nav-item <?= (basename($_SERVER['PHP_SELF']) == 'admin_transaction.php') ? 'active' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">receipt_long</span>
            <span class="nav-label">Transactions</span>
        </a>

        <a href="admin_appointment.php" class="nav-item <?= (basename($_SERVER['PHP_SELF']) == 'admin_appointment.php') ? 'active' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">event_note</span>
            <span class="nav-label">Bookings</span>
        </a>

        <a href="admin_attendance.php" class="nav-item <?= (basename($_SERVER['PHP_SELF']) == 'admin_attendance.php') ? 'active' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">history</span>
            <span class="nav-label">Attendance</span>
        </a>

        <a href="admin_report.php" class="nav-item <?= (basename($_SERVER['PHP_SELF']) == 'admin_report.php') ? 'active' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">description</span>
            <span class="nav-label">Reports</span>
        </a>
    </div>

    <div class="mt-auto pt-4 border-t border-white/10 flex flex-col gap-1 shrink-0 pb-6">
        <span class="nav-section-label text-[10px] font-black text-gray-500 uppercase tracking-widest px-[38px] mt-0 mb-2">Account</span>

        <a href="admin_profile.php" class="nav-item <?= (basename($_SERVER['PHP_SELF']) == 'admin_profile.php') ? 'active' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">account_circle</span>
            <span class="nav-label">Profile</span>
        </a>

        <a href="../logout.php" class="nav-item text-gray-400 hover:text-rose-500 transition-colors group">
            <span class="material-symbols-outlined text-xl shrink-0 group-hover:translate-x-1 transition-transform">logout</span>
            <span class="nav-label">Sign Out</span>
        </a>
    </div>
</nav>

<div class="main-content flex flex-col min-w-0">
    <main class="flex-1 p-6 md:p-10 max-w-[1400px] w-full mx-auto">
        <header class="mb-10 flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
            <div>
                <h1 class="text-3xl font-black text-white italic uppercase tracking-tighter leading-none mb-2">Attendance <span class="text-primary">History</span></h1>
                <p class="text-gray-500 text-xs font-bold uppercase tracking-widest">Facility Log • Active Session Records</p>
            </div>
            <div class="flex flex-row items-center gap-4">
                <div class="px-6 py-3.5 rounded-2xl bg-white/5 border border-white/5 text-right flex flex-col items-end group shadow-sm hover:shadow-primary/10 transition-shadow">
                    <p id="headerClock" class="text-white font-black italic text-xl leading-none mb-1 group-hover:text-primary transition-colors">00:00:00 AM</p>
                    <p class="text-gray-500 text-[9px] font-black uppercase tracking-[0.2em] group-hover:text-white transition-colors"><?= date('l, M d') ?></p>
                </div>
            </div>
        </header>
            
        <div class="flex gap-8 mb-6 border-b border-white/5 mt-6 w-full items-center justify-between">
            <div class="flex gap-8">
                <a href="?view=history" class="pb-4 text-[10px] font-black uppercase tracking-widest border-b-2 <?= ($view === 'history') ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-white' ?> transition-all">History</a>
                <a href="?view=live" class="pb-4 text-[10px] font-black uppercase tracking-widest border-b-2 <?= ($view === 'live') ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-white' ?> transition-all">Active Session</a>
            </div>
            
            <div class="flex flex-wrap items-center gap-4 mb-3">
                <form method="GET" class="flex gap-2">
                    <input type="hidden" name="view" value="<?= $view ?>">
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 text-sm">search</span>
                        <input type="text" name="search" placeholder="Search member..." value="<?= htmlspecialchars($search_query) ?>" 
                               class="bg-white/5 border border-white/10 rounded-xl text-[10px] font-bold py-3 pl-9 pr-4 focus:ring-1 focus:ring-primary text-white w-40 outline-none transition-all">
                    </div>

                    <?php if($view === 'history'): ?>
                    <div class="flex items-center gap-2">
                        <input type="date" name="start_date" value="<?= $start_date ?>" class="bg-white/5 border border-white/10 rounded-xl text-[10px] font-bold py-3 px-3 text-white outline-none">
                        <span class="text-[8px] text-gray-600 font-black">TO</span>
                        <input type="date" name="end_date" value="<?= $end_date ?>" class="bg-white/5 border border-white/10 rounded-xl text-[10px] font-bold py-3 px-3 text-white outline-none">
                    </div>
                    <?php endif; ?>

                    <button type="submit" class="bg-white/5 border border-white/10 px-4 py-3 rounded-xl text-[10px] font-black uppercase hover:bg-white/10 transition-all text-white">Apply</button>
                </form>

                <div class="h-6 w-px bg-white/10 mx-1"></div>
                <a href="#" class="flex items-center gap-2 px-4 py-3 bg-white/5 border border-white/10 text-primary rounded-xl text-[10px] font-black uppercase hover:bg-white/10 transition-all">
                    <span class="material-symbols-outlined text-sm">download</span> CSV
                </a>
            </div>
        </div>

        <div class="glass-card overflow-hidden shadow-2xl mt-4">
            <div class="px-8 py-6 border-b border-white/5 bg-white/5 flex justify-between items-center">
                <h4 class="font-black italic uppercase text-sm tracking-tighter text-gray-400"><?= $view === 'live' ? 'Live Training Sessions' : 'Attendance History Log' ?></h4>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-background-dark/50 text-gray-500 text-[10px] font-black uppercase tracking-widest">
                            <th class="px-8 py-4">Member Details</th>
                            <th class="px-8 py-4 text-center">Reference</th>
                            <th class="px-8 py-4">Check-in / Out</th>
                            <th class="px-8 py-4 text-right">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <?php if (!empty($attendance_list)): ?>
                            <?php foreach($attendance_list as $row): 
                                $isTraining = (empty($row['check_out_time'])); 
                                $check_in_ts = strtotime($row['attendance_date'] . ' ' . $row['check_in_time']);
                                $check_out_ts = $row['check_out_time'] ? strtotime($row['attendance_date'] . ' ' . $row['check_out_time']) : null;
                            ?>
                            <tr class="hover:bg-white/5 transition-all">
                                <td class="px-8 py-5">
                                    <div class="flex items-center gap-3">
                                        <div class="size-10 rounded-full bg-primary/10 flex items-center justify-center font-black text-primary uppercase shadow-lg border border-primary/20"><?= substr($row['fullname'] ?: $row['username'], 0, 1) ?></div>
                                        <div><p class="text-white font-black uppercase italic text-sm"><?= htmlspecialchars($row['fullname'] ?: $row['username']) ?></p><p class="text-[10px] text-gray-500 font-bold uppercase tracking-tight mt-0.5">@<?= htmlspecialchars($row['username']) ?></p></div>
                                    </div>
                                </td>
                                <td class="px-8 py-5 text-center">
                                    <span class="text-[10px] font-black bg-white/5 border border-white/10 px-3 py-1.5 rounded-lg text-gray-400 uppercase tracking-widest">#<?= str_pad($row['attendance_id'], 5, '0', STR_PAD_LEFT) ?></span>
                                </td>
                                <td class="px-8 py-5">
                                    <div class="flex flex-col">
                                        <div class="text-xs font-black italic text-gray-300">
                                            <?= date('h:i A', $check_in_ts) ?> 
                                            <span class="text-gray-600 mx-1">→</span> 
                                            <?php 
                                                if ($isTraining) {
                                                    echo '<span class="text-emerald-400 font-bold">Active</span>';
                                                } else {
                                                    echo '<span class="text-gray-400 font-bold">' . date('h:i A', $check_out_ts) . '</span>';
                                                }
                                            ?>
                                        </div>
                                        <p class="text-[10px] text-gray-600 font-bold mt-1 uppercase tracking-widest"><?= date('M d, Y', $check_in_ts) ?></p>
                                    </div>
                                </td>
                                <td class="px-8 py-5 text-right">
                                    <?php if ($isTraining): ?>
                                        <span class="px-4 py-1.5 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-[9px] text-emerald-500 font-black uppercase tracking-widest">Present</span>
                                    <?php else: ?>
                                        <span class="px-4 py-1.5 rounded-full bg-gray-500/10 border border-gray-500/20 text-[9px] text-gray-400 font-black uppercase tracking-widest">Completed</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="4" class="px-8 py-20 text-center text-gray-600 uppercase font-black text-xs italic tracking-widest">No matching records found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

</body>
</html>