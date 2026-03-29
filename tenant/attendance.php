<?php
session_start();
require_once '../db.php';

// Security Check
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'tenant') {
    header("Location: ../login.php");
    exit;
}

$gym_id = $_SESSION['gym_id'] ?? null;
$user_id = $_SESSION['user_id'] ?? null;

if (!$user_id || !$gym_id) {
    header("Location: ../login.php");
    exit;
}

$active_page = 'attendance';

// Fetch Gym Details
$stmtGym = $pdo->prepare("SELECT gym_name, profile_picture as logo_path FROM gyms WHERE gym_id = ?");
$stmtGym->execute([$gym_id]);
$gym = $stmtGym->fetch();

// Fetch Branding Data
$stmtPage = $pdo->prepare("SELECT * FROM tenant_pages WHERE gym_id = ?");
$stmtPage->execute([$gym_id]);
$page = $stmtPage->fetch();

$theme_color = ($page && isset($page['theme_color'])) ? $page['theme_color'] : '#8c2bee';
$bg_color = ($page && isset($page['bg_color'])) ? $page['bg_color'] : '#0a090d';

// --- CALCULATION LOGIC ---
// Total Check-ins Today
$stmtToday = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE gym_id = ? AND attendance_date = CURRENT_DATE");
$stmtToday->execute([$gym_id]);
$total_today = (int)$stmtToday->fetchColumn();

// Currently Active Members (Checked in but not checked out)
$stmtActive = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE gym_id = ? AND attendance_date = CURRENT_DATE AND check_out_time IS NULL");
$stmtActive->execute([$gym_id]);
$active_now = (int)$stmtActive->fetchColumn();

// Monthly Average
$stmtMonthlyAvg = $pdo->prepare("SELECT COUNT(*)/20.0 FROM attendance WHERE gym_id = ? AND MONTH(attendance_date) = MONTH(CURRENT_DATE)");
$stmtMonthlyAvg->execute([$gym_id]);
$monthly_avg = (float)$stmtMonthlyAvg->fetchColumn();

// --- FILTERING & FETCH ATTENDANCE ---
$f_date = $_GET['f_date'] ?? '';
$f_month = $_GET['f_month'] ?? '';
$f_year = $_GET['f_year'] ?? '';

$where = ["a.gym_id = :gym_id"];
$params = [':gym_id' => $gym_id];

if (!empty($f_date)) {
    $where[] = "a.attendance_date = :f_date";
    $params[':f_date'] = $f_date;
}
if (!empty($f_month)) {
    $where[] = "MONTH(a.attendance_date) = :f_month";
    $params[':f_month'] = $f_month;
}
if (!empty($f_year)) {
    $where[] = "YEAR(a.attendance_date) = :f_year";
    $params[':f_year'] = $f_year;
}

$where_sql = implode(" AND ", $where);
$stmtAttendance = $pdo->prepare("
    SELECT a.*, u.first_name, u.last_name, u.profile_picture
    FROM attendance a
    LEFT JOIN members m ON a.member_id = m.member_id
    LEFT JOIN users u ON m.user_id = u.user_id
    WHERE $where_sql
    ORDER BY a.attendance_date DESC, a.check_in_time DESC
    LIMIT 100
");
$stmtAttendance->execute($params);
$attendance = $stmtAttendance->fetchAll();
?>

<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8">
    <title>Attendance Tracking | Horizon</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "#8c2bee", "background-dark": "#0a090d", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)"}}}
        }
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: <?= $bg_color ?>; color: white; overflow: hidden; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); }
        .hover-lift:hover { transform: translateY(-10px); border-color: <?= $theme_color ?>40; box-shadow: 0 20px 40px -20px <?= $theme_color ?>30; }

        /* Sidebar Styling */
        .side-nav { width: 110px; transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1); overflow: hidden; display: flex; flex-direction: column; position: fixed; left: 0; top: 0; height: 100vh; z-index: 50; background-color: <?= $bg_color ?>; border-right: 1px solid rgba(255,255,255,0.05); }
        .side-nav:hover { width: 300px; }
        .main-content { margin-left: 110px; flex: 1; min-width: 0; transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1); }
        .side-nav:hover ~ .main-content { margin-left: 300px; }

        .nav-label { opacity: 0; transform: translateX(-15px); transition: all 0.3s ease-in-out; white-space: nowrap; pointer-events: none; }
        .side-nav:hover .nav-label { opacity: 1; transform: translateX(0); pointer-events: auto; }
        
        .nav-section-label { max-height: 0; opacity: 0; overflow: hidden; transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); margin: 0 !important; pointer-events: none; }
        .side-nav:hover .nav-section-label { max-height: 20px; opacity: 1; margin-bottom: 8px !important; pointer-events: auto; }
        
        .nav-item { display: flex; align-items: center; gap: 16px; padding: 10px 38px; transition: all 0.2s ease; text-decoration: none; white-space: nowrap; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; }
        .nav-item:hover { background: rgba(255,255,255,0.05); color: white; }
        .nav-item.active { color: <?= $theme_color ?> !important; position: relative; }
        .nav-item.active::after { content: ''; position: absolute; right: 0px; top: 50%; transform: translateY(-50%); width: 4px; height: 24px; background: <?= $theme_color ?>; border-radius: 4px 0 0 4px; }

        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        .filter-input { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 8px 12px; font-size: 11px; font-weight: 600; color: white; outline: none; transition: all 0.2s; color-scheme: dark; }
        .filter-input option { background-color: #1a1821; color: white; }
        .filter-input:focus { border-color: <?= $theme_color ?>; background: rgba(140, 43, 238, 0.05); }
    </style>
</head>
<body class="flex h-screen overflow-hidden">

<nav class="side-nav">
    <div class="px-7 py-8 mb-4 shrink-0">
        <div class="flex items-center gap-4">
            <div class="size-10 rounded-xl shrink-0 overflow-hidden flex items-center justify-center <?= empty($gym['logo_path']) ? 'bg-primary shadow-lg shadow-primary/20' : '' ?>">
                <?php if (!empty($gym['logo_path'])): ?>
                    <img src="<?= htmlspecialchars($gym['logo_path']) ?>" class="size-full object-cover">
                <?php else: ?>
                    <span class="material-symbols-outlined text-white text-2xl">bolt</span>
                <?php endif; ?>
            </div>
            <h1 class="nav-label text-lg font-black italic uppercase tracking-tighter text-white">Owner Portal</h1>
        </div>
    </div>
    
    <div class="flex-1 overflow-y-auto no-scrollbar space-y-1">
        <div class="nav-section-label px-[38px] mb-2"><span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Main Menu</span></div>
        <a href="tenant_dashboard.php" class="nav-item">
            <span class="material-symbols-outlined text-xl shrink-0">grid_view</span> 
            <span class="nav-label">Dashboard</span>
        </a>
        
        <a href="my_users.php" class="nav-item">
            <span class="material-symbols-outlined text-xl shrink-0">group</span> 
            <span class="nav-label">Users</span>
        </a>

        <a href="transactions.php" class="nav-item">
            <span class="material-symbols-outlined text-xl shrink-0">receipt_long</span> 
            <span class="nav-label">Transactions</span>
        </a>

        <a href="attendance.php" class="nav-item active">
            <span class="material-symbols-outlined text-xl shrink-0">history</span> 
            <span class="nav-label">Attendance</span>
        </a>

        <div class="nav-section-label px-[38px] mb-2 mt-6"><span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Management</span></div>

        <a href="staff.php" class="nav-item">
            <span class="material-symbols-outlined text-xl shrink-0">badge</span> 
            <span class="nav-label">Staff</span>
        </a>

        <a href="reports.php" class="nav-item">
            <span class="material-symbols-outlined text-xl shrink-0">analytics</span> 
            <span class="nav-label">Reports</span>
        </a>

        <a href="sales_report.php" class="nav-item">
            <span class="material-symbols-outlined text-xl shrink-0">payments</span> 
            <span class="nav-label">Sales Reports</span>
        </a>
    </div>

    <div class="mt-auto pt-4 border-t border-white/10 shrink-0 pb-6">
        <div class="nav-section-label px-[38px] mb-2"><span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Account</span></div>
        <a href="tenant_settings.php" class="nav-item">
            <span class="material-symbols-outlined text-xl shrink-0">settings</span> 
            <span class="nav-label">Settings</span>
        </a>
        <a href="profile.php" class="nav-item">
            <span class="material-symbols-outlined text-xl shrink-0">account_circle</span> 
            <span class="nav-label">Profile</span>
        </a>
        <a href="../logout.php" class="nav-item text-gray-400 hover:text-rose-500 transition-colors">
            <span class="material-symbols-outlined text-xl shrink-0">logout</span> 
            <span class="nav-label">Sign Out</span>
        </a>
    </div>
</nav>

<main class="main-content flex-1 p-10 overflow-y-auto no-scrollbar pb-10">
    <header class="mb-10 flex justify-between items-end">
        <div>
            <h2 class="text-3xl font-black uppercase tracking-tighter text-white italic">Attendance <span class="text-primary italic">Tracker</span></h2>
            <p class="text-gray-500 text-[10px] font-black uppercase tracking-widest mt-1 italic"><?= htmlspecialchars($gym['gym_name'] ?? 'Horizon Gym') ?> Member Presence</p>
        </div>

        <div class="text-right">
            <p id="topClock" class="text-white font-black italic text-2xl leading-none tracking-tighter">00:00:00 AM</p>
            <p class="text-[10px] font-bold uppercase tracking-widest mt-2 px-1 opacity-80 italic">
                <span class="text-primary"><?= date('l, M d, Y') ?></span>
            </p>
        </div>
    </header>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">
        <div class="glass-card hover-lift p-8">
            <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Total Today</p>
            <h3 class="text-3xl font-black italic tracking-tighter text-white"><?= number_format($total_today) ?> <span class="text-xs text-gray-500 uppercase font-black tracking-normal">Check-ins</span></h3>
        </div>
        <div class="glass-card hover-lift p-8 border-l-4 border-primary">
            <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Active Now</p>
            <h3 class="text-3xl font-black italic tracking-tighter text-primary"><?= number_format($active_now) ?> <span class="text-xs opacity-50 uppercase font-black tracking-normal">In Gym</span></h3>
        </div>
        <div class="glass-card hover-lift p-8">
            <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Daily Average</p>
            <h3 class="text-3xl font-black italic tracking-tighter text-white"><?= number_format($monthly_avg, 1) ?> <span class="text-xs text-gray-500 uppercase font-black tracking-normal">Per Day</span></h3>
        </div>
    </div>

    <div class="glass-card p-6 mb-8 border border-white/5">
        <form method="GET" class="flex flex-wrap items-center gap-6">
            <div class="flex flex-col gap-1.5">
                <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Specific Date</label>
                <input type="date" name="f_date" value="<?= htmlspecialchars($f_date) ?>" class="filter-input w-44">
            </div>
            <div class="flex flex-col gap-1.5">
                <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Filter by Month</label>
                <select name="f_month" class="filter-input w-40">
                    <option value="">All Months</option>
                    <?php 
                    $months = ["01"=>"January", "02"=>"February", "03"=>"March", "04"=>"April", "05"=>"May", "06"=>"June", "07"=>"July", "08"=>"August", "09"=>"September", "10"=>"October", "11"=>"November", "12"=>"December"];
                    foreach($months as $num => $name) {
                        $sel = ($f_month === $num) ? 'selected' : '';
                        echo "<option value='$num' $sel>$name</option>";
                    }
                    ?>
                </select>
            </div>
            <div class="flex flex-col gap-1.5">
                <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Year</label>
                <select name="f_year" class="filter-input w-28">
                    <option value="">All Years</option>
                    <option value="2026" <?= $f_year === '2026' ? 'selected' : '' ?>>2026</option>
                    <option value="2025" <?= $f_year === '2025' ? 'selected' : '' ?>>2025</option>
                    <option value="2024" <?= $f_year === '2024' ? 'selected' : '' ?>>2024</option>
                </select>
            </div>
            <div class="flex items-end gap-2 mt-3">
                <button type="submit" class="bg-primary/10 text-primary border border-primary/20 px-8 py-2.5 rounded-xl font-black italic uppercase tracking-tighter text-[10px] hover:bg-primary hover:text-white transition-all">
                    Filter Records
                </button>
                <a href="attendance.php" class="bg-white/5 text-gray-400 px-6 py-2.5 rounded-xl font-black italic uppercase tracking-tighter text-[10px] hover:bg-white/10 transition-all">
                    Reset
                </a>
            </div>
        </form>
    </div>

    <div class="glass-card overflow-hidden shadow-2xl">
        <div class="px-8 py-6 border-b border-white/5 flex justify-between items-center bg-white/[0.02]">
            <h4 class="font-black italic uppercase text-xs tracking-widest flex items-center gap-2">
                <span class="material-symbols-outlined text-emerald-500">history</span> Attendance History
            </h4>
            <div class="text-[10px] font-bold text-gray-500 italic uppercase">Recent Entries</div>
        </div>
        
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="text-[10px] font-black uppercase tracking-widest text-gray-500 border-b border-white/5">
                        <th class="px-8 py-5">Member Name</th>
                        <th class="px-8 py-5">Date</th>
                        <th class="px-8 py-5">Check In</th>
                        <th class="px-8 py-5">Check Out</th>
                        <th class="px-8 py-5">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php if(empty($attendance)): ?>
                        <tr><td colspan="5" class="px-8 py-20 text-center text-gray-600 font-black italic uppercase">No attendance history found.</td></tr>
                    <?php else: ?>
                        <?php foreach($attendance as $a): ?>
                        <tr class="hover:bg-white/[0.02] transition-all group">
                            <td class="px-8 py-6 flex items-center gap-4">
                                <div class="size-10 rounded-xl bg-white/5 border border-white/5 overflow-hidden flex items-center justify-center">
                                    <?php if(!empty($a['profile_picture'])): ?>
                                        <img src="<?= htmlspecialchars('../'.$a['profile_picture']) ?>" class="size-full object-cover">
                                    <?php else: ?>
                                        <span class="material-symbols-outlined text-gray-600 text-xl">person</span>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <p class="font-black italic uppercase tracking-tighter text-sm text-white"><?= htmlspecialchars(($a['first_name'] ?? 'Unknown User') . ' ' . ($a['last_name'] ?? '')) ?></p>
                                    <p class="text-[9px] font-bold text-gray-500 uppercase">M-ID: <?= str_pad($a['member_id'], 4, '0', STR_PAD_LEFT) ?></p>
                                </div>
                            </td>
                            <td class="px-8 py-6">
                                <span class="text-[11px] font-black italic uppercase text-gray-400"><?= date('M d, Y', strtotime($a['attendance_date'])) ?></span>
                            </td>
                            <td class="px-8 py-6">
                                <span class="text-sm font-black italic text-emerald-400 tracking-tight"><?= date('h:i A', strtotime($a['check_in_time'])) ?></span>
                            </td>
                            <td class="px-8 py-6">
                                <span class="text-sm font-black italic <?= $a['check_out_time'] ? 'text-gray-400' : 'text-gray-600' ?> tracking-tight">
                                    <?= $a['check_out_time'] ? date('h:i A', strtotime($a['check_out_time'])) : '---' ?>
                                </span>
                            </td>
                            <td class="px-8 py-6">
                                <?php if($a['check_out_time']): ?>
                                    <span class="px-3 py-1 rounded-lg bg-white/5 border border-white/10 text-gray-500 text-[9px] font-black uppercase tracking-widest italic">Completed</span>
                                <?php else: ?>
                                    <span class="px-3 py-1 rounded-lg bg-primary/10 border border-primary/20 text-primary text-[9px] font-black uppercase tracking-widest italic flex items-center gap-1 w-fit">
                                        <span class="size-1.5 rounded-full bg-primary animate-pulse"></span>
                                        Present
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

</body>
</html>
