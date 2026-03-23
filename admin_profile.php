<?php
// --- MOCK DATA FOR UI PREVIEW ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL & ~E_NOTICE);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Mock session and basic variables
$_SESSION["user_id"] = 1;
$_SESSION["role"] = "admin";
$_SESSION["fullname"] = "Admin Trae";
$adminName = $_SESSION["fullname"];
$pending_pay_count = 3;
$pending_appts = 2;

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
            if(document.getElementById('sidebarClock')) document.getElementById('sidebarClock').textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }
        window.onload = function() { setInterval(updateHeaderClock, 1000); updateHeaderClock(); };
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        .nav-link { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; transition: all 0.2s; white-space: nowrap; }
        .active-nav { color: #8c2bee !important; position: relative; }
        .active-nav::after { content: ''; position: absolute; right: -32px; top: 0; width: 4px; height: 100%; background: #8c2bee; border-radius: 2px; }
        .alert-pulse { animation: alert-pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite; }
        @keyframes alert-pulse { 0%, 100% { opacity: 1; } 50% { opacity: .5; } }
        input[type="date"]::-webkit-calendar-picker-indicator { filter: invert(1); opacity: 0.5; cursor: pointer; }
    </style>
</head>
<body class="antialiased flex flex-row min-h-screen">

<nav class="flex flex-col w-64 lg:w-72 bg-[#0a090d] border-r border-white/5 sticky top-0 h-screen p-8 z-50 shrink-0">
    <div class="mb-12">
        <div class="flex items-center gap-4 mb-4">
            <div class="size-10 rounded-xl bg-[#7f13ec] flex items-center justify-center shadow-lg shrink-0">
                <span class="material-symbols-outlined text-white text-2xl">bolt</span>
            </div>
            <h1 class="text-2xl font-black italic uppercase tracking-tighter text-white">Herdoza</h1>
        </div>
        <div class="p-4 rounded-2xl bg-white/5 border border-white/5">
            <p id="sidebarClock" class="text-white font-black italic text-xl leading-none mb-1">00:00:00 AM</p>
            <p class="text-primary text-[9px] font-black uppercase tracking-[0.2em]"><?= date('l, M d') ?></p>
        </div>
    </div>
    
    <div class="flex flex-col gap-7 flex-1">
        <a href="admin_dashboard.php" class="nav-link flex items-center gap-3 text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl">grid_view</span> Dashboard
        </a>
        <a href="admin_users.php" class="nav-link text-gray-400 hover:text-white flex items-center gap-3">
            <span class="material-symbols-outlined text-xl">group</span> My Users
        </a>
        <a href="admin_transaction.php" class="nav-link text-gray-400 hover:text-white flex items-center gap-3">
            <span class="material-symbols-outlined text-xl">receipt_long</span> Transactions
        </a>
        <a href="admin_transaction.php" class="nav-link text-gray-400 hover:text-white flex items-center gap-3">
            <span class="material-symbols-outlined text-xl">event_note</span> Bookings
        </a>
        <a href="admin_attendance.php" class="nav-link active-nav flex items-center gap-3">
            <span class="material-symbols-outlined text-xl">history</span> Attendance
        </a>
        <a href="admin_appointment.php" class="nav-link text-gray-400 hover:text-white flex items-center gap-3">
            <span class="material-symbols-outlined text-xl">event_available</span> Appointment
        </a>
        <a href="admin_report.php" class="nav-link text-gray-400 hover:text-white flex items-center gap-3">
            <span class="material-symbols-outlined text-xl">description</span> Reports
        </a>
        
        <div class="mt-auto pt-8 border-t border-white/10">
            <a href="admin_profile.php" class="nav-link text-gray-400 hover:text-white flex items-center gap-3 mb-6">
                <span class="material-symbols-outlined text-xl">person</span> Profile
            </a>
            <a href="logout.php" class="text-gray-400 hover:text-red-500 transition-colors flex items-center gap-3 group">
                <span class="material-symbols-outlined group-hover:translate-x-1 transition-transform">logout</span>
                <span class="nav-link">Sign Out</span>
            </a>
        </div>
    </div>
</nav>

<div class="flex-1 flex flex-col min-w-0">
    <main class="flex-1 p-6 md:p-10 max-w-[1400px] w-full mx-auto">
        <header class="mb-10 flex flex-col md:flex-row justify-between items-end gap-4">
            <div>
                <h1 class="text-3xl font-black text-white italic uppercase tracking-tighter mb-1">Attendance <span class="text-primary">History</span></h1>
                <p class="text-primary text-xs font-bold uppercase tracking-widest">Facility Log • Active Session Records</p>
            </div>
        </header>
            
            <div class="flex flex-wrap items-center gap-3 bg-surface-dark p-3 rounded-2xl border border-white/5">
                <div class="flex bg-background-dark p-1 rounded-xl border border-white/5 mr-2">
                    <a href="?view=history" class="px-4 py-1.5 rounded-lg text-[9px] font-black uppercase tracking-widest transition-all bg-primary text-white">History</a>
                </div>

                <form method="GET" class="flex items-center gap-3">
                    <input type="hidden" name="view" value="<?= $view ?>">
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 text-sm">search</span>
                        <input type="text" name="search" placeholder="Search member..." value="<?= htmlspecialchars($search_query) ?>" 
                               class="bg-background-dark border-none rounded-lg text-[10px] font-bold py-2 pl-9 pr-4 focus:ring-1 focus:ring-primary text-white w-40">
                    </div>

                    <?php if($view === 'history'): ?>
                    <div class="flex items-center gap-2 border-l border-white/10 pl-3">
                        <input type="date" name="start_date" value="<?= $start_date ?>" class="bg-background-dark border-none rounded-lg text-[10px] font-bold py-2 text-white">
                        <span class="text-[8px] text-gray-600 font-black">TO</span>
                        <input type="date" name="end_date" value="<?= $end_date ?>" class="bg-background-dark border-none rounded-lg text-[10px] font-bold py-2 text-white">
                    </div>
                    <?php endif; ?>

                    <button type="submit" class="bg-primary/20 text-primary border border-primary/30 px-4 py-2 rounded-lg text-[9px] font-black uppercase hover:bg-primary hover:text-white transition-all">Apply</button>
                </form>

                <div class="h-6 w-px bg-white/10 mx-2"></div>
                <a href="?export=csv&view=<?= $view ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&search=<?= urlencode($search_query) ?>" class="flex items-center gap-2 px-4 py-2 bg-white/5 border border-white/10 text-white rounded-lg text-[9px] font-black uppercase hover:bg-white/10 transition-all italic">
                    <span class="material-symbols-outlined text-sm">download</span> CSV
                </a>
            </div>
        </header>



        <div class="glass-card overflow-hidden shadow-2xl mt-10">
            <div class="px-8 py-6 border-b border-white/5 bg-white/5 flex justify-between items-center">
                <h4 class="font-black italic uppercase text-sm tracking-tighter"><?= $view === 'live' ? 'Live Training Sessions' : 'Attendance History Log' ?></h4>
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
                                        <div class="size-10 rounded-full bg-primary/10 flex items-center justify-center font-black text-primary uppercase"><?= substr($row['fullname'] ?: $row['username'], 0, 1) ?></div>
                                        <div><p class="text-sm font-bold italic"><?= htmlspecialchars($row['fullname'] ?: $row['username']) ?></p><p class="text-[10px] text-gray-500 font-bold uppercase tracking-tight">@<?= htmlspecialchars($row['username']) ?></p></div>
                                    </div>
                                </td>
                                <td class="px-8 py-5 text-center">
                                    <span class="text-[10px] font-black bg-white/5 px-3 py-1 rounded-lg text-gray-400 italic"><?= $booked_duration ?> Hour(s)</span>
                                </td>
                                <td class="px-8 py-5 text-xs font-black italic">
                                    <?= date('h:i A', $check_in_ts) ?> 
                                    <span class="text-gray-600 mx-1">→</span> 
                                    <?php 
                                        if ($isTraining) {
                                            echo '<span class="text-primary font-bold">Expected: ' . $expected_end_str . '</span>';
                                        } else {
                                            echo date('h:i A', strtotime($row['check_out']));
                                        }
                                    ?>
                                    <p class="text-[10px] text-gray-600 font-bold mt-1 uppercase"><?= date('M d, Y', $check_in_ts) ?></p>
                                </td>
                                <td class="px-8 py-5 text-right">
                                    <?php if ($isTraining): ?>
                                        <span class="px-3 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-[9px] text-emerald-500 font-black uppercase italic">Active</span>
                                    <?php else: ?>
                                        <span class="text-[9px] text-gray-600 font-black uppercase italic">Completed</span>
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