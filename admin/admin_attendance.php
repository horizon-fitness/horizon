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

// --- VIEW & FILTER LOGIC (from GET params) ---
$view = $_GET['view'] ?? 'history';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$search_query = $_GET['search'] ?? '';

// --- MOCK STATS ---
$checkins_today = 12;
$peak_hour = "06:00 PM";

// --- MOCK ATTENDANCE LOGS ---
// To prevent errors with mysqli functions, we create a mock result object.
// This allows the view to render correctly without a database connection.
class MockMysqliResult {
    private $data;
    private $pointer = 0;
    public $num_rows;

    public function __construct($data) {
        $this->data = $data;
        $this->num_rows = count($data);
    }

    public function fetch_assoc() {
        if ($this->pointer < $this->num_rows) {
            return $this->data[$this->pointer++];
        }
        return null;
    }
}

$mock_data = [];
if ($view === 'live') {
    $mock_data[] = ['username' => 'live.user', 'fullname' => 'Live User', 'check_in' => date('Y-m-d H:i:s', time() - 1800), 'check_out' => '1970-01-01 00:00:01', 'scheduled_duration' => 2];
} else {
    $mock_data[] = ['username' => 'j.doe', 'fullname' => 'John Doe', 'check_in' => date('Y-m-d H:i:s', time() - 86400), 'check_out' => date('Y-m-d H:i:s', time() - 82800), 'scheduled_duration' => 1];
    $mock_data[] = ['username' => 'j.smith', 'fullname' => 'Jane Smith', 'check_in' => date('Y-m-d H:i:s', time() - 90000), 'check_out' => date('Y-m-d H:i:s', time() - 86400), 'scheduled_duration' => 1];
}

$log_result = new MockMysqliResult($mock_data);

?>

<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Attendance History | Herdoza Fitness</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "#8c2bee", "background-dark": "#0a090d", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)"}}}
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
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; display: flex; flex-row: row; min-h-screen: 100vh; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        
        /* Sidebar Hover Logic - MATCHING SUPER ADMIN */
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
            margin-left: 110px; /* Base margin */
            flex: 1;
            min-width: 0;
            transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .side-nav:hover ~ .main-content {
            margin-left: 300px; /* Expand margin when sidebar is hovered */
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

        .nav-item { display: flex; align-items: center; gap: 12px; padding: 12px 16px; border-radius: 10px; transition: all 0.2s ease; text-decoration: none; white-space: nowrap; }
        .nav-item:hover { background: rgba(255,255,255,0.05); }
        .nav-item.active { color: #8c2bee !important; background: rgba(140,43,238,0.1); border: 1px solid rgba(140,43,238,0.15); }
        
        .alert-pulse { animation: alert-pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite; }
        @keyframes alert-pulse { 0%, 100% { opacity: 1; } 50% { opacity: .5; } }
        input[type="date"]::-webkit-calendar-picker-indicator { filter: invert(1); opacity: 0.5; cursor: pointer; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>
<body class="antialiased min-h-screen">

<nav class="side-nav flex flex-col fixed left-0 top-0 h-screen bg-[#0a090d] border-r border-white/5 z-50">
    <div class="px-4 py-6">
        <div class="flex items-center gap-3">
            <div class="size-10 rounded-xl bg-primary flex items-center justify-center shadow-lg shrink-0">
                <span class="material-symbols-outlined text-white text-2xl">bolt</span>
            </div>
            <span class="nav-label text-white font-black italic uppercase tracking-tighter text-base leading-none">Herdoza</span>
        </div>
    </div>
    <div class="flex flex-col flex-1 overflow-y-auto no-scrollbar px-3 gap-0.5">
        <span class="nav-section-label text-[9px] font-black text-gray-500 uppercase tracking-widest px-3 mt-0">Main Menu</span>
        <a href="admin_dashboard.php" class="nav-item text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl shrink-0">grid_view</span>
            <span class="nav-label font-black text-[10px] uppercase tracking-wider">Dashboard</span>
        </a>
        <a href="register_member.php" class="nav-item text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl shrink-0">person_add</span>
            <span class="nav-label font-black text-[10px] uppercase tracking-wider">Walk-in Member</span>
        </a>
        <a href="admin_users.php" class="nav-item text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl shrink-0">group</span>
            <span class="nav-label font-black text-[10px] uppercase tracking-wider">My Users</span>
        </a>

        <span class="nav-section-label text-[9px] font-black text-gray-500 uppercase tracking-widest px-3">Management</span>
        <a href="admin_transaction.php" class="nav-item text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl shrink-0">receipt_long</span>
            <span class="nav-label font-black text-[10px] uppercase tracking-wider">Transactions</span>
        </a>
        <a href="admin_appointment.php" class="nav-item text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl shrink-0">event_note</span>
            <span class="nav-label font-black text-[10px] uppercase tracking-wider">Bookings</span>
        </a>
        <a href="admin_attendance.php" class="nav-item active text-primary">
            <span class="material-symbols-outlined text-xl shrink-0">history</span>
            <span class="nav-label font-black text-[10px] uppercase tracking-wider">Attendance</span>
        </a>
        <a href="admin_report.php" class="nav-item text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl shrink-0">description</span>
            <span class="nav-label font-black text-[10px] uppercase tracking-wider">Reports</span>
        </a>
    </div>
    <div class="px-3 pt-4 pb-4 border-t border-white/10 flex flex-col gap-0.5">
        <span class="nav-section-label text-[9px] font-black text-gray-500 uppercase tracking-widest px-3 mt-0 mb-2">Account</span>
        <a href="admin_profile.php" class="nav-item text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl shrink-0">person</span>
            <span class="nav-label font-black text-[10px] uppercase tracking-wider">Profile</span>
        </a>
        <a href="../logout.php" class="nav-item text-gray-400 hover:text-red-500 group">
            <span class="material-symbols-outlined text-xl shrink-0 group-hover:translate-x-1 transition-transform">logout</span>
            <span class="nav-label font-black text-[10px] uppercase tracking-wider">Sign Out</span>
        </a>
    </div>
</nav>

<div class="main-content flex flex-col min-w-0">
    <main class="flex-1 p-6 md:p-10 max-w-[1400px] w-full mx-auto">
        <header class="mb-10 flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
            <div>
                <h1 class="text-3xl font-black text-white italic uppercase tracking-tighter mb-1">Attendance <span class="text-primary">History</span></h1>
                <p class="text-primary text-xs font-bold uppercase tracking-widest">Facility Log • Active Session Records</p>
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
                <a href="?view=history" class="pb-4 text-[10px] font-black uppercase tracking-widest border-b-2 border-primary text-primary transition-all">History</a>
                <a href="?view=live" class="pb-4 text-[10px] font-black uppercase tracking-widest border-b-2 border-transparent text-gray-500 hover:text-white transition-all">Active Session</a>
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
                <a href="?export=csv&view=<?= $view ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&search=<?= urlencode($search_query) ?>" class="flex items-center gap-2 px-4 py-3 bg-white/5 border border-white/10 text-primary rounded-xl text-[10px] font-black uppercase hover:bg-white/10 transition-all">
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
                            <th class="px-8 py-4 text-center">Session Length</th>
                            <th class="px-8 py-4">Check-in / Out</th>
                            <th class="px-8 py-4 text-right">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <?php if ($log_result->num_rows > 0): ?>
                            <?php while($row = $log_result->fetch_assoc()): 
                                $isTraining = ($row['check_out'] == '1970-01-01 00:00:01' || empty($row['check_out'])); 
                                
                                // Auto-calculate expected end time
                                $booked_duration = $row['scheduled_duration'] ?: 1; // Default to 1 hour if not found
                                $check_in_ts = strtotime($row['check_in']);
                                $expected_end_ts = $check_in_ts + ($booked_duration * 3600);
                                $expected_end_str = date('h:i A', $expected_end_ts);
                            ?>
                            <tr class="hover:bg-white/5 transition-all">
                                <td class="px-8 py-5">
                                    <div class="flex items-center gap-3">
                                        <div class="size-10 rounded-full bg-primary/10 flex items-center justify-center font-black text-primary uppercase shadow-lg border border-primary/20"><?= substr($row['fullname'] ?: $row['username'], 0, 1) ?></div>
                                        <div><p class="text-white font-black uppercase italic text-sm"><?= htmlspecialchars($row['fullname'] ?: $row['username']) ?></p><p class="text-[10px] text-gray-500 font-bold uppercase tracking-tight mt-0.5">@<?= htmlspecialchars($row['username']) ?></p></div>
                                    </div>
                                </td>
                                <td class="px-8 py-5 text-center">
                                    <span class="text-[10px] font-black bg-white/5 border border-white/10 px-3 py-1.5 rounded-lg text-gray-400 uppercase tracking-widest"><?= $booked_duration ?> Hour(s)</span>
                                </td>
                                <td class="px-8 py-5">
                                    <div class="flex flex-col">
                                        <div class="text-xs font-black italic text-gray-300">
                                            <?= date('h:i A', $check_in_ts) ?> 
                                            <span class="text-gray-600 mx-1">→</span> 
                                            <?php 
                                                if ($isTraining) {
                                                    echo '<span class="text-emerald-400 font-bold">End: ' . $expected_end_str . '</span>';
                                                } else {
                                                    echo '<span class="text-gray-400 font-bold">' . date('h:i A', strtotime($row['check_out'])) . '</span>';
                                                }
                                            ?>
                                        </div>
                                        <p class="text-[10px] text-gray-600 font-bold mt-1 uppercase tracking-widest"><?= date('M d, Y', $check_in_ts) ?></p>
                                    </div>
                                </td>
                                <td class="px-8 py-5 text-right">
                                    <?php if ($isTraining): ?>
                                        <span class="px-4 py-1.5 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-[9px] text-emerald-500 font-black uppercase tracking-widest">Active</span>
                                    <?php else: ?>
                                        <span class="px-4 py-1.5 rounded-full bg-gray-500/10 border border-gray-500/20 text-[9px] text-gray-400 font-black uppercase tracking-widest">Completed</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
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