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

// --- FETCH DASHBOARD STATS ---
$total_members = 0;
$pending_payments = 0;
$pending_appts = 0;
$recent_payments = [];
$recent_bookings = [];

try {
    // Stats
    $stmtMembers = $pdo->prepare("SELECT COUNT(*) as total FROM users WHERE role = 'member'");
    $stmtMembers->execute();
    $total_members = $stmtMembers->fetch()['total'] ?? 0;

    $stmtPendingPayments = $pdo->prepare("SELECT COUNT(*) as total FROM payments WHERE status = 'Pending'");
    $stmtPendingPayments->execute();
    $pending_payments = $stmtPendingPayments->fetch()['total'] ?? 0;

    $stmtPendingAppts = $pdo->prepare("SELECT COUNT(*) as total FROM bookings WHERE status = 'Pending'");
    $stmtPendingAppts->execute();
    $pending_appts = $stmtPendingAppts->fetch()['total'] ?? 0;

    // Recent Transactions (Last 5)
    $stmtRecentPayments = $pdo->prepare("
        SELECT p.*, u.first_name, u.last_name, u.username 
        FROM payments p 
        JOIN members m ON p.member_id = m.member_id
        JOIN users u ON m.user_id = u.user_id 
        ORDER BY p.created_at DESC 
        LIMIT 5
    ");
    $stmtRecentPayments->execute();
    $recent_payments = $stmtRecentPayments->fetchAll();

    // Recent Bookings (Last 5)
    $stmtRecentBookings = $pdo->prepare("
        SELECT b.*, u.first_name, u.last_name, u.username 
        FROM bookings b 
        JOIN members m ON b.member_id = m.member_id
        JOIN users u ON m.user_id = u.user_id 
        ORDER BY b.created_at DESC 
        LIMIT 5
    ");
    $stmtRecentBookings->execute();
    $recent_bookings = $stmtRecentBookings->fetchAll();

} catch (Exception $e) {
    // Silently fail if tables don't exist
}
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
            theme: { extend: { colors: { "primary": "<?= $page['theme_color'] ?? '#8c2bee' ?>", "background-dark": "<?= $page['bg_color'] ?? '#0a090d' ?>", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)" }}}
        }
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
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
    <script>
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
        setInterval(updateHeaderClock, 1000);
        window.addEventListener('DOMContentLoaded', updateHeaderClock);
    </script>
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
        <header class="mb-10 flex flex-row justify-between items-end gap-6">
            <div>
                <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white leading-none">Welcome Back, <span class="text-primary"><?= htmlspecialchars($admin_name ?? '') ?></span></h2>
                <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-2">Staff Operational Overview</p>
            </div>
            <div class="text-right">
                <p id="headerClock" class="text-white font-black italic text-xl tracking-tight leading-none mb-2">00:00:00 AM</p>
                <p class="text-primary text-[9px] font-black uppercase tracking-[0.2em] opacity-80"><?= date('l, M d, Y') ?></p>
            </div>
        </header>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">
            <div class="glass-card p-8 border-l-4 border-primary relative overflow-hidden group shadow-xl">
                <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform">groups</span>
                <p class="text-[10px] font-black uppercase text-gray-400 mb-2 tracking-widest">Active Members</p>
                <h3 class="text-2xl font-black italic uppercase italic"><?= $total_members ?? 0 ?></h3>
                <p class="text-primary text-[10px] font-black uppercase mt-2 italic">Current Member Base</p>
            </div>
            <div class="glass-card p-8 border-l-4 border-red-500 relative overflow-hidden group shadow-xl cursor-pointer hover:bg-red-500/[0.02]" onclick="location.href='admin_transaction.php'">
                <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform">payments</span>
                <p class="text-[10px] font-black uppercase text-gray-400 mb-2 tracking-widest">Pending Transactions</p>
                <h3 class="text-2xl font-black italic italic"><?= $pending_payments ?? 0 ?> <span class="text-red-500 alert-pulse text-xs">!</span></h3>
                <p class="text-red-500 text-[10px] font-black uppercase mt-2 italic">Requires Review</p>
            </div>
            <div class="glass-card p-8 border-l-4 border-amber-500 relative overflow-hidden group shadow-xl cursor-pointer hover:bg-amber-500/[0.02]" onclick="location.href='admin_transaction.php'">
                <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform">event_note</span>
                <p class="text-[10px] font-black uppercase text-gray-400 mb-2 tracking-widest">Pending Bookings</p>
                <h3 class="text-2xl font-black italic italic text-amber-500"><?= $pending_appts ?? 0 ?></h3>
                <p class="text-amber-500 text-[10px] font-black uppercase mt-2 italic">Action Required</p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Recent Transactions -->
            <div class="glass-card overflow-hidden flex flex-col">
                <div class="px-8 py-6 border-b border-white/5 bg-white/[0.02] flex justify-between items-center">
                    <div>
                        <h4 class="font-black italic uppercase text-sm tracking-tighter text-white">Recent Transactions</h4>
                        <p class="text-[9px] text-gray-500 font-bold uppercase tracking-widest mt-1">Latest Financial Records</p>
                    </div>
                    <a href="admin_transaction.php" class="text-primary text-[10px] font-black uppercase tracking-widest hover:underline transition-all">View All</a>
                </div>
                <div class="overflow-x-auto flex-1">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="text-gray-500 text-[9px] font-black uppercase tracking-[0.15em] border-b border-white/5">
                                <th class="px-8 py-4">Member</th>
                                <th class="px-8 py-4 text-center">Amount</th>
                                <th class="px-8 py-4 text-center">Status</th>
                                <th class="px-8 py-4 text-right">Date</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <?php if (empty($recent_payments)): ?>
                                <tr>
                                    <td colspan="4" class="px-8 py-10 text-center text-gray-500 text-[10px] font-black uppercase tracking-widest">No recent transactions</td>
                                </tr>
                            <?php else: foreach ($recent_payments as $pay): ?>
                                <tr class="hover:bg-white/[0.02] transition-colors">
                                    <td class="px-8 py-4">
                                        <p class="text-white font-black uppercase italic text-xs tracking-tight"><?= htmlspecialchars($pay['first_name'] . ' ' . $pay['last_name']) ?></p>
                                        <p class="text-[9px] text-gray-500 font-bold uppercase tracking-widest mt-0.5">@<?= htmlspecialchars($pay['username']) ?></p>
                                    </td>
                                    <td class="px-8 py-4 text-center text-white italic font-black text-[10px]">
                                        ₱<?= number_format($pay['amount'], 2) ?>
                                    </td>
                                    <td class="px-8 py-4 text-center">
                                        <?php 
                                            $statClass = 'bg-gray-500/10 text-gray-500 border-gray-500/20';
                                            $status = $pay['status'] ?? 'Pending';
                                            if ($status == 'Paid' || $status == 'Approved') $statClass = 'bg-emerald-500/10 text-emerald-500 border-emerald-500/20';
                                            if ($status == 'Pending') $statClass = 'bg-amber-500/10 text-amber-500 border-amber-500/20';
                                        ?>
                                        <span class="px-3 py-1 rounded-full border text-[8px] font-black uppercase tracking-widest <?= $statClass ?>">
                                            <?= $status ?>
                                        </span>
                                    </td>
                                    <td class="px-8 py-4 text-right">
                                        <p class="text-gray-400 text-[10px] font-bold"><?= date('M d, Y', strtotime($pay['created_at'])) ?></p>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Recent Bookings -->
            <div class="glass-card overflow-hidden flex flex-col">
                <div class="px-8 py-6 border-b border-white/5 bg-white/[0.02] flex justify-between items-center">
                    <div>
                        <h4 class="font-black italic uppercase text-sm tracking-tighter text-white">Recent Bookings</h4>
                        <p class="text-[9px] text-gray-500 font-bold uppercase tracking-widest mt-1">Latest Appointments</p>
                    </div>
                    <a href="admin_appointment.php" class="text-primary text-[10px] font-black uppercase tracking-widest hover:underline transition-all">View All</a>
                </div>
                <div class="overflow-x-auto flex-1">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="text-gray-500 text-[9px] font-black uppercase tracking-[0.15em] border-b border-white/5">
                                <th class="px-8 py-4">Member</th>
                                <th class="px-8 py-4">Service</th>
                                <th class="px-8 py-4 text-center">Status</th>
                                <th class="px-8 py-4 text-right">Schedule</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <?php if (empty($recent_bookings)): ?>
                                <tr>
                                    <td colspan="4" class="px-8 py-10 text-center text-gray-500 text-[10px] font-black uppercase tracking-widest">No recent bookings</td>
                                </tr>
                            <?php else: foreach ($recent_bookings as $book): ?>
                                <tr class="hover:bg-white/[0.02] transition-colors">
                                    <td class="px-8 py-4">
                                        <p class="text-white font-black uppercase italic text-xs tracking-tight"><?= htmlspecialchars($book['first_name'] . ' ' . $book['last_name']) ?></p>
                                        <p class="text-[9px] text-gray-500 font-bold uppercase tracking-widest mt-0.5">@<?= htmlspecialchars($book['username']) ?></p>
                                    </td>
                                    <td class="px-8 py-4">
                                        <p class="text-white font-bold text-[10px]"><?= htmlspecialchars($book['service']) ?></p>
                                        <p class="text-primary text-[8px] font-black uppercase tracking-tighter"><?= htmlspecialchars($book['trainer'] ?? 'Generic') ?></p>
                                    </td>
                                    <td class="px-8 py-4 text-center">
                                        <?php 
                                            $statClass = 'bg-gray-500/10 text-gray-500 border-gray-500/20';
                                            $bStatus = $book['status'] ?? 'Pending';
                                            if ($bStatus == 'Confirmed' || $bStatus == 'Approved') $statClass = 'bg-emerald-500/10 text-emerald-500 border-emerald-500/20';
                                            if ($bStatus == 'Pending') $statClass = 'bg-amber-500/10 text-amber-500 border-amber-500/20';
                                            if ($bStatus == 'Cancelled') $statClass = 'bg-red-500/10 text-red-500 border-red-500/20';
                                        ?>
                                        <span class="px-3 py-1 rounded-full border text-[8px] font-black uppercase tracking-widest <?= $statClass ?>">
                                            <?= $bStatus ?>
                                        </span>
                                    </td>
                                    <td class="px-8 py-4 text-right">
                                        <p class="text-white font-black italic text-[10px]"><?= $book['time'] ?></p>
                                        <p class="text-gray-500 text-[8px] font-bold uppercase tracking-tighter"><?= date('M d', strtotime($book['date'])) ?></p>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</div>
</body>
</html>