<?php
session_start();
require_once '../db.php';

// Security Check: Only Staff and Coach
$role = strtolower($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || ($role !== 'staff' && $role !== 'coach')) {
    header("Location: ../login.php");
    exit;
}

$gym_id = $_SESSION['gym_id'];
$admin_name = $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];

// --- FETCH BRANDING & CONFIG ---
$stmtGym = $pdo->prepare("SELECT * FROM gyms WHERE gym_id = ?");
$stmtGym->execute([$gym_id]);
$gym = $stmtGym->fetch();

$stmtPage = $pdo->prepare("SELECT * FROM tenant_pages WHERE gym_id = ? LIMIT 1");
$stmtPage->execute([$gym_id]);
$tenant_config = $stmtPage->fetch();

// --- FILTERING LOGIC ---
$view = $_GET['view'] ?? 'history';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$search_query = $_GET['search'] ?? '';

// Base Query
$query = "
    SELECT a.*, u.username, CONCAT(u.first_name, ' ', u.last_name) as fullname
    FROM attendance a 
    JOIN members m ON a.member_id = m.member_id 
    JOIN users u ON m.user_id = u.user_id 
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

$active_page = "admin_attendance";
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>Attendance Registry | Horizon Partners</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet" />
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "<?= $tenant_config['theme_color'] ?? '#8c2bee' ?>",
                        "background-dark": "<?= $tenant_config['bg_color'] ?? '#0a090d' ?>",
                        "surface-dark": "#14121a",
                        "border-subtle": "rgba(255,255,255,0.05)"
                    }
                }
            }
        } 
    </script>
    <style>
        body {
            font-family: 'Lexend', sans-serif;
            background-color: <?= $tenant_config['bg_color'] ?? '#0a090d' ?>;
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

        /* Sidebar Hover Logic */
        :root { --nav-width: 110px; }
        body:has(.side-nav:hover) { --nav-width: 300px; }

        .side-nav {
            width: var(--nav-width);
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            position: fixed;
            left: 0;
            top: 0;
            height: 100vh;
            z-index: 110;
        }

        .main-content {
            margin-left: var(--nav-width);
            flex: 1;
            min-width: 0;
            transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow-y: auto;
            overflow-x: hidden;
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
            color: <?= $tenant_config['theme_color'] ?? '#8c2bee' ?> !important;
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
            background: <?= $tenant_config['theme_color'] ?? '#8c2bee' ?>;
            border-radius: 4px 0 0 4px;
        }

        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        .input-box {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            color: white;
            padding: 12px 16px;
            font-size: 13px;
            font-weight: 500;
            outline: none;
            transition: all 0.2s;
        }

        .input-box:focus {
            border-color: <?= $tenant_config['theme_color'] ?? '#8c2bee' ?>;
            background: rgba(255, 255, 255, 0.08);
        }

        .input-box::placeholder { color: #4b5563; }
        
        .tab-btn {
            padding: 12px 24px;
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            color: #4b5563;
            border-bottom: 2px solid transparent;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .tab-btn.active {
            color: <?= $tenant_config['theme_color'] ?? '#8c2bee' ?>;
            border-color: <?= $tenant_config['theme_color'] ?? '#8c2bee' ?>;
        }

        .tab-btn:hover:not(.active) {
            color: white;
        }
    </style>
    <script>
        function updateHeaderClock() {
            const now = new Date();
            const clockEl = document.getElementById('headerClock');
            if (clockEl) {
                clockEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            }
        }
        setInterval(updateHeaderClock, 1000);
        window.addEventListener('DOMContentLoaded', updateHeaderClock);

        function clearAttendanceFilters() {
            window.location.href = 'admin_attendance.php?view=<?= $view ?>';
        }
    </script>
</head>
<body class="antialiased flex h-screen overflow-hidden">

<nav class="side-nav flex flex-col fixed left-0 top-0 h-screen bg-background-dark border-r border-white/5">
    <div class="px-7 py-8 mb-4 shrink-0">
        <div class="flex items-center gap-4">
            <div class="size-10 rounded-xl bg-primary flex items-center justify-center shadow-lg shrink-0 overflow-hidden">
                <?php if (!empty($tenant_config['logo_path'])):
                    $logo_src = (strpos($tenant_config['logo_path'], 'data:image') === 0) ? $tenant_config['logo_path'] : '../' . $tenant_config['logo_path'];
                ?>
                    <img src="<?= $logo_src ?>" class="size-full object-contain">
                <?php else: ?>
                    <span class="material-symbols-outlined text-white text-2xl">bolt</span>
                <?php endif; ?>
            </div>
            <h1 class="nav-label text-lg font-black italic uppercase tracking-tighter text-white">Staff Portal</h1>
        </div>
    </div>
    <div class="flex-1 overflow-y-auto no-scrollbar space-y-1">
        <div class="nav-section-label px-[38px] mb-2"><span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Overview</span></div>
        <a href="admin_dashboard.php" class="nav-item"><span class="material-symbols-outlined text-xl shrink-0">grid_view</span><span class="nav-label">Dashboard</span></a>
        <a href="register_member.php" class="nav-item"><span class="material-symbols-outlined text-xl shrink-0">person_add</span><span class="nav-label">Walk-in Member</span></a>
        <div class="nav-section-label px-[38px] mb-2 mt-6"><span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Management</span></div>
        <a href="admin_users.php" class="nav-item"><span class="material-symbols-outlined text-xl shrink-0">group</span><span class="nav-label">My Users</span></a>
        <a href="admin_transaction.php" class="nav-item"><span class="material-symbols-outlined text-xl shrink-0">receipt_long</span><span class="nav-label">Transactions</span></a>
        <a href="admin_appointment.php" class="nav-item"><span class="material-symbols-outlined text-xl shrink-0">event_note</span><span class="nav-label">Bookings</span></a>
        <a href="admin_attendance.php" class="nav-item active"><span class="material-symbols-outlined text-xl shrink-0">history</span><span class="nav-label">Attendance</span></a>
    </div>
    <div class="mt-auto pt-4 border-t border-white/10 shrink-0 pb-6">
        <div class="nav-section-label px-[38px] mb-2"><span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Account</span></div>
        <a href="admin_profile.php" class="nav-item text-gray-400 hover:text-white"><span class="material-symbols-outlined text-xl shrink-0">account_circle</span><span class="nav-label">Profile</span></a>
        <a href="../logout.php" class="nav-item text-gray-400 hover:text-rose-500 transition-colors group">
            <span class="material-symbols-outlined text-xl shrink-0 group-hover:translate-x-1 transition-transform">logout</span>
            <span class="nav-label whitespace-nowrap">Sign Out</span>
        </a>
    </div>
</nav>

<div class="main-content flex-1 overflow-y-auto no-scrollbar">
    <main class="p-10 max-w-[1400px] mx-auto pb-20">
        <header class="mb-12 flex flex-row justify-between items-end gap-6">
            <div>
                <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white leading-none">Attendance <span class="text-primary">Registry</span></h2>
                <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-2 px-1 opacity-60">Security Access Logs • Facility Registry</p>
            </div>
            <div class="flex items-end gap-8 text-right shrink-0">
                <div class="flex flex-col items-end">
                    <p id="headerClock" class="text-white font-black italic text-2xl leading-none tracking-tighter">00:00:00 AM</p>
                    <p class="text-primary text-[10px] font-black uppercase tracking-[0.2em] leading-none mt-2"><?= date('l, M d, Y') ?></p>
                </div>
            </div>
        </header>

        <!-- Premium Tab Switcher -->
        <div class="flex items-center gap-2 mb-10 border-b border-white/5">
            <a href="?view=history" class="tab-btn <?= ($view === 'history') ? 'active' : '' ?>">Full History</a>
            <a href="?view=live" class="tab-btn <?= ($view === 'live') ? 'active' : '' ?>">Active Sessions</a>
        </div>

        <!-- Functional Filter Matrix -->
        <div class="mb-10">
            <form method="GET" class="glass-card p-8 border border-white/5 relative overflow-hidden">
                <input type="hidden" name="view" value="<?= $view ?>">
                <div class="grid grid-cols-1 md:grid-cols-2 <?= ($view === 'history') ? 'lg:grid-cols-5' : 'lg:grid-cols-3' ?> gap-6 items-end">
                    <div class="space-y-2 lg:col-span-1">
                        <p class="text-[10px] font-black uppercase tracking-widest text-primary ml-1">Member Search</p>
                        <div class="relative">
                            <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-gray-500 text-lg">search</span>
                            <input type="text" name="search" value="<?= htmlspecialchars($search_query) ?>" placeholder="Search ID or name..." class="input-box pl-12 w-full">
                        </div>
                    </div>

                    <?php if ($view === 'history'): ?>
                    <div class="space-y-2">
                        <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 ml-1">Period (From)</p>
                        <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" class="input-box w-full">
                    </div>

                    <div class="space-y-2">
                        <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 ml-1">Period (To)</p>
                        <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" class="input-box w-full">
                    </div>
                    <?php endif; ?>

                    <div class="flex gap-3">
                        <button type="submit" class="flex-1 bg-primary hover:bg-primary/90 text-white h-[46px] rounded-xl text-[10px] font-black uppercase tracking-widest transition-all shadow-lg shadow-primary/20 active:scale-95">Apply Log</button>
                        <button type="button" onclick="clearAttendanceFilters()" class="size-[46px] rounded-xl bg-white/5 border border-white/5 flex items-center justify-center text-gray-400 hover:bg-rose-500/10 hover:text-rose-500 transition-all group active:scale-95">
                            <span class="material-symbols-outlined text-xl group-hover:rotate-180 transition-transform">restart_alt</span>
                        </button>
                    </div>

                    <div class="lg:col-span-1">
                        <button type="button" onclick="alert('CSV Export Protocol Initialized')" class="w-full bg-white/5 border border-white/10 hover:bg-white/10 text-primary h-[46px] rounded-xl text-[10px] font-black uppercase tracking-widest transition-all flex items-center justify-center gap-2 group active:scale-95">
                            <span class="material-symbols-outlined text-lg group-hover:-translate-y-0.5 transition-transform">download</span>
                            Export CSV
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <div class="flex justify-between items-center mb-6">
            <p class="text-[10px] font-black uppercase tracking-widest text-gray-500 italic">LOGGING DATABASE MATRIX — <span class="text-white">ENCRYPTED FEED</span></p>
            <div class="flex items-center gap-4">
                <span class="px-4 py-1.5 rounded-full bg-white/5 border border-white/5 text-[9px] font-black uppercase tracking-widest text-gray-400">Log Entries: <?= count($attendance_list) ?></span>
            </div>
        </div>

        <div class="glass-card shadow-2xl overflow-hidden border border-white/5">
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="text-[10px] font-black uppercase tracking-[0.15em] text-gray-600 border-b border-white/5 bg-white/[0.01]">
                            <th class="px-8 py-5">NODE IDENTITY</th>
                            <th class="px-8 py-5 text-center">ACCESS PROTOCOL</th>
                            <th class="px-8 py-5">TIMELINE PROTOCOL</th>
                            <th class="px-8 py-5 text-right">SYSTEM STATUS</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <?php if (empty($attendance_list)): ?>
                        <tr>
                            <td colspan="4" class="px-8 py-24 text-center">
                                <span class="material-symbols-outlined text-4xl text-gray-700 mb-4 block">fact_check</span>
                                <p class="text-[10px] font-black uppercase tracking-widest text-gray-500 italic">No access logs detected in target matrix.</p>
                            </td>
                        </tr>
                        <?php else: foreach ($attendance_list as $row): 
                            $isTraining = (empty($row['check_out_time'])); 
                            $check_in_ts = strtotime($row['attendance_date'] . ' ' . $row['check_in_time']);
                            $check_out_ts = $row['check_out_time'] ? strtotime($row['attendance_date'] . ' ' . $row['check_out_time']) : null;
                        ?>
                        <tr class="hover:bg-white/[0.02] group transition-colors">
                            <td class="px-8 py-6">
                                <div class="flex items-center gap-4">
                                    <div class="size-10 rounded-2xl bg-primary/10 border border-primary/20 flex items-center justify-center text-primary font-black italic text-base">
                                        <?= substr($row['fullname'] ?: $row['username'], 0, 1) ?>
                                    </div>
                                    <div>
                                        <p class="text-[13px] font-black italic uppercase text-white group-hover:text-primary transition-colors"><?= htmlspecialchars($row['fullname'] ?: $row['username']) ?></p>
                                        <p class="text-[10px] font-bold text-gray-500 tracking-tight lowercase">@<?= htmlspecialchars($row['username']) ?></p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-8 py-6 text-center">
                                <span class="px-3 py-1.5 rounded-lg bg-white/5 border border-white/5 text-[9px] font-black text-gray-500 uppercase tracking-widest">#<?= str_pad($row['attendance_id'], 5, '0', STR_PAD_LEFT) ?></span>
                            </td>
                            <td class="px-8 py-6">
                                <div class="space-y-0.5 text-left">
                                    <div class="text-[11px] font-black italic text-white uppercase flex items-center gap-2">
                                        <?= date('h:i A', $check_in_ts) ?>
                                        <span class="text-gray-600">→</span>
                                        <?php if ($isTraining): ?>
                                            <span class="text-emerald-500 text-[10px] font-extrabold animate-pulse">ACTIVE NOW</span>
                                        <?php else: ?>
                                            <span class="text-gray-400"><?= date('h:i A', $check_out_ts) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-[9px] font-bold text-gray-600 uppercase tracking-widest italic"><?= date('M d, Y', $check_in_ts) ?></p>
                                </div>
                            </td>
                            <td class="px-8 py-6 text-right">
                                <?php if ($isTraining): ?>
                                    <span class="px-4 py-1.5 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-[9px] text-emerald-500 font-extrabold uppercase italic tracking-widest">Present</span>
                                <?php else: ?>
                                    <span class="px-4 py-1.5 rounded-full bg-gray-500/10 border border-gray-500/20 text-[9px] text-gray-400 font-extrabold uppercase italic tracking-widest">Completed</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>
</body>
</html>