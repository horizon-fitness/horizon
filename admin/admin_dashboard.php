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
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

// Fetch Gym Details
$stmtGym = $pdo->prepare("SELECT * FROM gyms WHERE gym_id = ?");
$stmtGym->execute([$gym_id]);
$gym = $stmtGym->fetch();

// Active CMS Page (for logo)
$stmtPage = $pdo->prepare("SELECT * FROM tenant_pages WHERE gym_id = ? LIMIT 1");
$stmtPage->execute([$gym_id]);
$page = $stmtPage->fetch();

$admin_name = $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Staff Dashboard | Horizon Partners</title>
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
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        .nav-link { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; transition: all 0.2s; white-space: nowrap; }
        .active-nav { color: #8c2bee !important; position: relative; }
        .active-nav::after { 
            content: ''; 
            position: absolute; 
            right: -32px; 
            top: 50%;
            transform: translateY(-50%);
            width: 4px; 
            height: 20px; 
            background: #8c2bee; 
            border-radius: 99px; 
        }
        
        @media (max-width: 1023px) {
            .active-nav::after { display: none; }
        }
        .alert-pulse { animation: alert-pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite; }
        @keyframes alert-pulse { 0%, 100% { opacity: 1; } 50% { opacity: .5; } }
        
        .status-card-green { border: 1px solid #10b981; background: linear-gradient(135deg, rgba(16,185,129,0.05) 0%, rgba(20,18,26,1) 100%); }
        .status-card-yellow { border: 1px solid #f59e0b; background: linear-gradient(135deg, rgba(245,158,11,0.05) 0%, rgba(20,18,26,1) 100%); }
        .dashed-container { border: 2px dashed rgba(255,255,255,0.1); border-radius: 24px; }

        /* Hide scrollbar for Chrome, Safari and Opera */
        .no-scrollbar::-webkit-scrollbar {
            display: none;
        }

        /* Hide scrollbar for IE, Edge and Firefox */
        .no-scrollbar {
            -ms-overflow-style: none;  /* IE and Edge */
            scrollbar-width: none;  /* Firefox */
        }
    </style>
    <script>
        function updateSidebarClock() {
            const now = new Date();
            const clockEl = document.getElementById('sidebarClock');
            if (clockEl) {
                clockEl.textContent = now.toLocaleTimeString('en-US', { 
                    hour: '2-digit', 
                    minute: '2-digit', 
                    second: '2-digit' 
                });
            }
        }
        setInterval(updateSidebarClock, 1000);
        window.addEventListener('DOMContentLoaded', updateSidebarClock);
    </script>
</head>
<body class="antialiased flex flex-row min-h-screen">

<nav class="flex flex-col w-64 lg:w-72 bg-[#0a090d] border-r border-white/5 sticky top-0 h-screen p-8 z-50 shrink-0">
    <div class="mb-12">
        <div class="flex items-center gap-4 mb-6">
            <div class="size-10 rounded-xl bg-[#7f13ec] flex items-center justify-center shadow-lg shrink-0 overflow-hidden">
                <?php if (!empty($page['logo_path'])): ?>
                    <img src="<?= $page['logo_path'] ?>" class="size-full object-contain">
                <?php else: ?>
                    <span class="material-symbols-outlined text-white text-2xl">bolt</span>
                <?php endif; ?>
            </div>
            <h1 class="text-xl font-black italic uppercase tracking-tighter text-white leading-none"><?= htmlspecialchars($gym['gym_name'] ?? 'Horizon') ?></h1>
        </div>
        <div class="p-4 rounded-2xl bg-white/5 border border-white/5">
            <p id="sidebarClock" class="text-white font-black italic text-xl leading-none mb-1">00:00:00 AM</p>
            <p class="text-primary text-[9px] font-black uppercase tracking-[0.2em]"><?= date('l, M d') ?></p>
        </div>
    </div>
    
    <div class="flex flex-col gap-7 flex-1 overflow-y-auto no-scrollbar pr-2">
        <a href="admin_dashboard.php" class="nav-link active-nav text-primary flex items-center gap-3">
            <span class="material-symbols-outlined text-xl">grid_view</span> Dashboard
        </a>
        <a href="../tenant/register_member.php" class="nav-link text-gray-400 hover:text-white flex items-center gap-3">
            <span class="material-symbols-outlined text-xl">person_add</span> Walk-in Member
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
        <a href="admin_attendance.php" class="nav-link text-gray-400 hover:text-white flex items-center gap-3">
            <span class="material-symbols-outlined text-xl">history</span> Attendance
        </a>
        <a href="admin_appointment.php" class="nav-link text-gray-400 hover:text-white flex items-center gap-3">
            <span class="material-symbols-outlined text-xl">event_available</span> Appointment
        </a>
        <a href="admin_report.php" class="nav-link text-gray-400 hover:text-white flex items-center gap-3">
            <span class="material-symbols-outlined text-xl">description</span> Reports
        </a>
    </div>

    <div class="mt-auto pt-8 border-t border-white/10">
        <a href="admin_profile.php" class="nav-link text-gray-400 hover:text-white flex items-center gap-3 mb-6">
            <span class="material-symbols-outlined text-xl">person</span> Profile
        </a>
        <a href="../logout.php" class="text-gray-400 hover:text-red-500 transition-colors flex items-center gap-3 group">
            <span class="material-symbols-outlined group-hover:translate-x-1 transition-transform">logout</span>
            <span class="nav-link">Sign Out</span>
        </a>
    </div>
</nav>

<div class="flex-1 flex flex-col min-w-0">
    <main class="flex-1 p-6 md:p-10 max-w-[1400px] w-full mx-auto">
        <header class="mb-10 flex flex-col md:flex-row justify-between items-start md:items-end gap-6">
            <div>
                <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white">Welcome Back, <span class="text-primary"><?= htmlspecialchars($admin_name ?? '') ?></span></h2>
                <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-1">Operational Overview for Herdoza Fitness</p>
            </div>
        </header>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-10">
            <div class="glass-card p-8 status-card-green relative overflow-hidden group">
                <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform">payments</span>
                <p class="text-[10px] font-black uppercase text-gray-400 mb-2 tracking-widest">Revenue Streams</p>
                <h3 class="text-2xl font-black italic uppercase italic">₱<?= number_format($total_revenue ?? 0, 2) ?> Total</h3>
                <p class="text-emerald-500 text-[10px] font-black uppercase mt-2">Financial Status: Optimal</p>
            </div>
            <div class="glass-card p-8 status-card-yellow relative overflow-hidden group">
                <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform">groups</span>
                <p class="text-[10px] font-black uppercase text-gray-400 mb-2 tracking-widest">Member Engagement</p>
                <h3 class="text-2xl font-black italic uppercase italic"><?= $total_members ?? 0 ?> Active Members</h3>
                <p class="text-amber-500 text-[10px] font-black uppercase mt-2">Plan: Standard Access Base</p>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">
            <div class="glass-card p-6 border-l-4 border-primary shadow-xl cursor-pointer hover:bg-primary/[0.02]" onclick="location.href='admin_appointment.php'">
                <p class="text-[10px] font-black uppercase text-gray-500 mb-1 tracking-widest">Admin Appt.</p>
                <h3 class="text-2xl font-black italic text-primary"><?= $pending_appts ?? 0 ?></h3>
            </div>
            <div class="glass-card p-6 border-l-4 border-red-500 shadow-xl cursor-pointer hover:bg-red-500/[0.02]" onclick="location.href='admin_transaction.php'">
                <p class="text-[10px] font-black uppercase text-gray-500 mb-1 tracking-widest">Pending Trans.</p>
                <h3 class="text-2xl font-black italic"><?= $pending_payments ?? 0 ?> <span class="text-red-500 alert-pulse text-xs">!</span></h3>
            </div>
            <div class="glass-card p-6 border-l-4 border-amber-500 shadow-xl cursor-pointer hover:bg-amber-500/[0.02]" onclick="location.href='admin_transaction.php'">
                <p class="text-[10px] font-black uppercase text-gray-500 mb-1 tracking-widest">Pending Bookings</p>
                <h3 class="text-2xl font-black italic text-amber-500"><?= $pending_appts ?? 0 ?></h3>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-8">
            <div class="w-full">
                <h3 class="text-sm font-black italic uppercase tracking-widest mb-4 flex items-center gap-2">
                    <span class="size-2 bg-primary rounded-full"></span> Attendance Today
                </h3>
                <div class="dashed-container p-8 flex flex-col items-center justify-center text-center min-h-[300px]">
                    <?php if(!empty($live_query) && mysqli_num_rows($live_query) > 0): ?>
                        <div class="w-full grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            <?php while($row = mysqli_fetch_assoc($live_query)): ?>
                            <div class="flex items-center justify-between p-4 bg-white/5 rounded-xl border border-white/5">
                                <div class="text-left">
                                    <p class="text-xs font-black uppercase italic"><?= htmlspecialchars($row['username']) ?></p>
                                    <p class="text-[9px] text-gray-500"><?= date('h:i A', strtotime($row['check_in'])) ?></p>
                                </div>
                                <span class="text-[8px] font-black px-2 py-1 bg-primary/10 text-primary border border-primary/20 rounded">IN GYM</span>
                            </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <span class="material-symbols-outlined text-gray-700 text-5xl mb-4">person_off</span>
                        <p class="text-gray-600 text-[10px] font-black uppercase italic tracking-widest">No Active Logs Found</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>
</body>
</html>