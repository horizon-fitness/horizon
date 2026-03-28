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
$theme_color = "#8c2bee";     

// Sample Data
$sample_appointments = [
    [
        'id' => 1,
        'fullname' => 'Mike Johnson',
        'username' => 'mike.j',
        'service' => 'Powerlifting Class',
        'trainer' => 'Coach Dave',
        'date' => date('Y-m-d', strtotime('+1 day')),
        'time' => '10:00 AM',
        'status' => 'Confirmed'
    ],
    [
        'id' => 2,
        'fullname' => 'Sarah Wilson',
        'username' => 'sarah.w',
        'service' => 'Yoga Essentials',
        'trainer' => 'Coach Elena',
        'date' => date('Y-m-d', strtotime('+1 day')),
        'time' => '02:00 PM',
        'status' => 'Pending'
    ],
    [
        'id' => 3,
        'fullname' => 'Robert Chen',
        'username' => 'rob.c',
        'service' => 'HIIT Session',
        'trainer' => 'Coach Dave',
        'date' => date('Y-m-d', strtotime('+2 days')),
        'time' => '08:30 AM',
        'status' => 'Confirmed'
    ],
    [
        'id' => 4,
        'fullname' => 'Emily Davis',
        'username' => 'emily.d',
        'service' => 'Personal Training',
        'trainer' => 'Coach Elena',
        'date' => date('Y-m-d', strtotime('+2 days')),
        'time' => '04:00 PM',
        'status' => 'Cancelled'
    ]
];
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Appointments | Horizon Partners</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "<?= $theme_color ?>", "background-dark": "#0a090d", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)"}}}
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
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; display: flex; flex-direction: row; min-h-screen: 100vh; }
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

        .nav-item { display: flex; align-items: center; gap: 12px; padding: 12px 16px; border-radius: 10px; transition: all 0.2s ease; text-decoration: none; white-space: nowrap; }
        .nav-item:hover { background: rgba(255,255,255,0.05); color: white; }
        .nav-item.active { color: <?= $theme_color ?> !important; background: rgba(140,43,238,0.1); border: 1px solid rgba(140,43,238,0.15); }
    </style>
</head>
<body class="antialiased min-h-screen flex">

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
        <a href="admin_appointment.php" class="nav-item active text-primary">
            <span class="material-symbols-outlined text-xl shrink-0">event_note</span>
            <span class="nav-label font-black text-[10px] uppercase tracking-wider">Bookings</span>
        </a>
        <a href="admin_attendance.php" class="nav-item text-gray-400 hover:text-white">
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
                <h1 class="text-3xl font-black text-white italic uppercase tracking-tighter mb-1">Member <span class="text-primary">Appointments</span></h1>
                <p class="text-primary text-xs font-bold uppercase tracking-widest">Operational Schedule • Booking Management</p>
            </div>
            <div class="flex flex-row items-center gap-4">
                <div class="px-6 py-3.5 rounded-2xl bg-white/5 border border-white/5 text-right flex flex-col items-end group shadow-sm hover:shadow-primary/10 transition-shadow">
                    <p id="headerClock" class="text-white font-black italic text-xl leading-none mb-1 group-hover:text-primary transition-colors">00:00:00 AM</p>
                    <p class="text-gray-500 text-[9px] font-black uppercase tracking-[0.2em] group-hover:text-white transition-colors"><?= date('l, M d') ?></p>
                </div>
            </div>
        </header>

        <div class="glass-card overflow-hidden shadow-2xl">
            <div class="px-8 py-6 border-b border-white/5 bg-white/5 flex justify-between items-center">
                <h4 class="font-black italic uppercase text-sm tracking-tighter text-gray-400">Scheduled Appointments</h4>
                <div class="flex gap-2">
                    <button class="bg-white/5 border border-white/10 px-4 py-2 rounded-xl text-[10px] font-black uppercase hover:bg-white/10 transition-all flex items-center gap-2">
                        <span class="material-symbols-outlined text-xs">filter_list</span> Filter
                    </button>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-background-dark/50 text-gray-500 text-[10px] font-black uppercase tracking-widest">
                            <th class="px-8 py-5">Member</th>
                            <th class="px-8 py-5">Service / Trainer</th>
                            <th class="px-8 py-5">Schedule</th>
                            <th class="px-8 py-5 text-center">Status</th>
                            <th class="px-8 py-5 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <?php foreach($sample_appointments as $appt): ?>
                            <tr class="hover:bg-white/[0.02] transition-colors">
                                <td class="px-8 py-6">
                                    <div class="flex items-center gap-3">
                                        <div class="size-10 rounded-full bg-primary/10 flex items-center justify-center font-black text-primary uppercase shadow-lg border border-primary/20">
                                            <?= substr($appt['fullname'], 0, 1) ?>
                                        </div>
                                        <div>
                                            <p class="text-white font-black uppercase italic text-sm tracking-tight"><?= htmlspecialchars($appt['fullname']) ?></p>
                                            <p class="text-[10px] text-gray-500 font-bold uppercase tracking-widest mt-0.5">@<?= htmlspecialchars($appt['username']) ?></p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-8 py-6">
                                    <p class="text-white font-bold text-xs"><?= htmlspecialchars($appt['service']) ?></p>
                                    <p class="text-primary text-[10px] font-black uppercase tracking-widest mt-1"><?= htmlspecialchars($appt['trainer']) ?></p>
                                </td>
                                <td class="px-8 py-6">
                                    <div class="flex flex-col">
                                        <div class="text-xs font-black italic text-gray-300">
                                            <?= $appt['time'] ?>
                                        </div>
                                        <p class="text-[10px] text-gray-600 font-bold mt-1 uppercase tracking-widest">
                                            <?= date('M d, Y', strtotime($appt['date'])) ?>
                                        </p>
                                    </div>
                                </td>
                                <td class="px-8 py-6 text-center">
                                    <?php 
                                        $statusClass = '';
                                        switch($appt['status']) {
                                            case 'Confirmed': $statusClass = 'bg-emerald-500/10 border-emerald-500/20 text-emerald-500'; break;
                                            case 'Pending': $statusClass = 'bg-amber-500/10 border-amber-500/20 text-amber-500'; break;
                                            case 'Cancelled': $statusClass = 'bg-red-500/10 border-red-500/20 text-red-500'; break;
                                        }
                                    ?>
                                    <span class="px-4 py-1.5 rounded-full border text-[9px] font-black uppercase tracking-widest <?= $statusClass ?>">
                                        <?= $appt['status'] ?>
                                    </span>
                                </td>
                                <td class="px-8 py-6 text-right">
                                    <div class="flex justify-end gap-2">
                                        <button class="size-8 rounded-lg bg-white/5 border border-white/10 flex items-center justify-center hover:bg-primary/20 hover:text-primary transition-all text-gray-400" title="Edit">
                                            <span class="material-symbols-outlined text-[16px]">edit</span>
                                        </button>
                                        <button class="size-8 rounded-lg bg-white/5 border border-white/10 flex items-center justify-center hover:bg-red-500/20 hover:text-red-500 transition-all text-gray-400" title="Cancel">
                                            <span class="material-symbols-outlined text-[16px]">close</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

</body>
</html>