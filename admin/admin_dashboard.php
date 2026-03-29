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
    // 1. Active Members (Gym Specific)
    $stmtMembers = $pdo->prepare("SELECT COUNT(*) as total FROM members WHERE gym_id = ? AND member_status = 'Active'");
    $stmtMembers->execute([$gym_id]);
    $total_members = (int)($stmtMembers->fetch()['total'] ?? 0);

    // 2. Pending Transactions (Gym Specific)
    $stmtPendingPayments = $pdo->prepare("SELECT COUNT(*) as total FROM payments WHERE gym_id = ? AND payment_status = 'Pending'");
    $stmtPendingPayments->execute([$gym_id]);
    $pending_payments = (int)($stmtPendingPayments->fetch()['total'] ?? 0);

    // 3. Pending Bookings (Gym Specific)
    $stmtPendingAppts = $pdo->prepare("SELECT COUNT(*) as total FROM bookings WHERE gym_id = ? AND booking_status = 'Pending'");
    $stmtPendingAppts->execute([$gym_id]);
    $pending_appts = (int)($stmtPendingAppts->fetch()['total'] ?? 0);

    // Recent Transactions (Last 5 for this gym)
    $stmtRecentPayments = $pdo->prepare("
        SELECT p.*, u.first_name, u.last_name, u.username 
        FROM payments p 
        JOIN members m ON p.member_id = m.member_id
        JOIN users u ON m.user_id = u.user_id 
        WHERE p.gym_id = ?
        ORDER BY p.created_at DESC 
        LIMIT 5
    ");
    $stmtRecentPayments->execute([$gym_id]);
    $recent_payments = $stmtRecentPayments->fetchAll();

    // Recent Bookings (Last 5 for this gym)
    $stmtRecentBookings = $pdo->prepare("
        SELECT b.*, u.first_name, u.last_name, u.username 
        FROM bookings b 
        JOIN members m ON b.member_id = m.member_id
        JOIN users u ON m.user_id = u.user_id 
        WHERE b.gym_id = ?
        ORDER BY b.created_at DESC 
        LIMIT 5
    ");
    $stmtRecentBookings->execute([$gym_id]);
    $recent_bookings = $stmtRecentBookings->fetchAll();

} catch (Exception $e) {
    error_log("Dashboard Fetch Error: " . $e->getMessage());
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
            theme: { extend: { colors: { "primary": "<?= $page['theme_color'] ?? '#8c2bee' ?>", "background-dark": "#0a090d", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)" }}}
        }
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; overflow: hidden; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        
        .side-nav { width: 110px; transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1); overflow: hidden; display: flex; flex-direction: column; position: fixed; left: 0; top: 0; height: 100vh; z-index: 50; }
        .side-nav:hover { width: 300px; }
        .main-content { margin-left: 110px; flex: 1; min-width: 0; transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1); }
        .side-nav:hover ~ .main-content { margin-left: 300px; }

        .nav-label { opacity: 0; transform: translateX(-15px); transition: all 0.3s ease-in-out; white-space: nowrap; pointer-events: none; }
        .side-nav:hover .nav-label { opacity: 1; transform: translateX(0); pointer-events: auto; }
        .nav-section-label { max-height: 0; opacity: 0; overflow: hidden; transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); margin: 0 !important; pointer-events: none; }
        .side-nav:hover .nav-section-label { max-height: 20px; opacity: 1; margin-bottom: 8px !important; pointer-events: auto; }
        
        .nav-item { display: flex; align-items: center; gap: 16px; padding: 10px 38px; transition: all 0.2s ease; text-decoration: none; white-space: nowrap; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; }
        .nav-item:hover { background: rgba(255,255,255,0.05); color: white; }
        .nav-item.active { color: <?= $page['theme_color'] ?? '#8c2bee' ?> !important; position: relative; }
        .nav-item.active::after { content: ''; position: absolute; right: 0px; top: 50%; transform: translateY(-50%); width: 4px; height: 24px; background: <?= $page['theme_color'] ?? '#8c2bee' ?>; border-radius: 4px 0 0 4px; }
        
        .alert-pulse { animation: alert-pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite; }
        @keyframes alert-pulse { 0%, 100% { opacity: 1; } 50% { opacity: .5; } }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
    <script>
        function updateHeaderClock() {
            const now = new Date();
            const clockEl = document.getElementById('topClock');
            if (clockEl) {
                clockEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            }
        }
        setInterval(updateHeaderClock, 1000);
        window.addEventListener('DOMContentLoaded', updateHeaderClock);
    </script>
</head>
<body class="antialiased flex h-screen overflow-hidden">

<nav class="side-nav flex flex-col fixed left-0 top-0 h-screen bg-background-dark border-r border-white/5 z-50">
    <div class="px-7 py-8 mb-4 shrink-0">
        <div class="flex items-center gap-4">
            <div class="size-10 rounded-xl bg-primary flex items-center justify-center shadow-lg shrink-0 overflow-hidden">
                <?php if (!empty($page['logo_path'])): 
                    $logo_src = (strpos($page['logo_path'], 'data:image') === 0) ? $page['logo_path'] : '../' . $page['logo_path'];
                ?>
                    <img src="<?= $logo_src ?>" class="size-full object-contain">
                <?php else: ?>
                    <span class="material-symbols-outlined text-white text-2xl">bolt</span>
                <?php endif; ?>
            </div>
            <h1 class="nav-label text-lg font-black italic uppercase tracking-tighter text-white leading-tight">
                Staff Portal
            </h1>
        </div>
    </div>
    
    <div class="flex-1 overflow-y-auto no-scrollbar space-y-1">
        <div class="nav-section-label px-[38px] mb-2">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Overview</span>
        </div>
        
        <a href="admin_dashboard.php" class="nav-item active">
            <span class="material-symbols-outlined text-xl shrink-0">grid_view</span>
            <span class="nav-label">Dashboard</span>
        </a>

        <a href="register_member.php" class="nav-item">
            <span class="material-symbols-outlined text-xl shrink-0">person_add</span>
            <span class="nav-label">Walk-in Member</span>
        </a>

        <div class="nav-section-label px-[38px] mb-2 mt-6">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Management</span>
        </div>
        
        <a href="admin_users.php" class="nav-item">
            <span class="material-symbols-outlined text-xl shrink-0">group</span>
            <span class="nav-label">My Users</span>
        </a>

        <a href="admin_transaction.php" class="nav-item">
            <span class="material-symbols-outlined text-xl shrink-0">receipt_long</span>
            <span class="nav-label">Transactions</span>
        </a>

        <a href="admin_appointment.php" class="nav-item">
            <span class="material-symbols-outlined text-xl shrink-0">event_note</span>
            <span class="nav-label">Bookings</span>
        </a>

        <a href="admin_attendance.php" class="nav-item">
            <span class="material-symbols-outlined text-xl shrink-0">history</span>
            <span class="nav-label">Attendance</span>
        </a>
    </div>

    <div class="mt-auto pt-4 border-t border-white/10 shrink-0 pb-6">
        <div class="nav-section-label px-[38px] mb-2">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Account</span>
        </div>
        <a href="admin_profile.php" class="nav-item">
            <span class="material-symbols-outlined text-xl shrink-0">account_circle</span>
            <span class="nav-label">Profile</span>
        </a>
        <a href="../logout.php" class="nav-item hover:text-rose-500">
            <span class="material-symbols-outlined text-xl shrink-0">logout</span>
            <span class="nav-label text-sm">Sign Out</span>
        </a>
    </div>
</nav>

<div class="main-content flex-1 overflow-y-auto no-scrollbar">
    <main class="p-10 max-w-[1400px] mx-auto">
        <header class="mb-12 flex justify-between items-end">
            <div>
                <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white leading-none">
                    Staff <span class="text-primary">Dashboard</span>
                </h2>
                <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-2 px-1">
                    Operational Overview • <?= htmlspecialchars($admin_name) ?>
                </p>
            </div>
            <div class="flex items-end gap-8">
                <div class="flex flex-col items-end">
                    <p id="topClock" class="text-white font-black italic text-2xl leading-none tracking-tight">00:00:00 AM</p>
                    <p class="text-[10px] font-black uppercase text-gray-500 tracking-[0.2em] leading-none mt-2"><?= date('l, M d, Y') ?></p>
                </div>
            </div>
        </header>

        <!-- Stat Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-12">
            <div class="glass-card p-6 flex items-center gap-5 group hover:bg-white/[0.02] transition-colors border border-white/5">
                <div class="size-14 rounded-2xl bg-primary/10 flex items-center justify-center text-primary group-hover:scale-105 transition-transform">
                    <span class="material-symbols-outlined text-3xl">groups</span>
                </div>
                <div>
                    <p class="text-[10px] font-black uppercase text-gray-500 tracking-[0.1em]">Active Members</p>
                    <h3 class="text-2xl font-black italic uppercase leading-tight"><?= $total_members ?></h3>
                </div>
            </div>

            <div class="glass-card p-6 flex items-center gap-5 group hover:bg-white/[0.02] transition-colors border border-red-500/10 cursor-pointer" onclick="location.href='admin_transaction.php'">
                <div class="size-14 rounded-2xl bg-red-500/10 flex items-center justify-center text-red-500 group-hover:scale-105 transition-transform">
                    <span class="material-symbols-outlined text-3xl">payments</span>
                </div>
                <div>
                    <p class="text-[10px] font-black uppercase text-gray-500 tracking-[0.1em]">Pending Payments</p>
                    <h3 class="text-2xl font-black italic uppercase leading-tight"><?= $pending_payments ?> <span class="text-red-500 text-sm <?= $pending_payments > 0 ? 'alert-pulse' : '' ?>">!</span></h3>
                </div>
            </div>

            <div class="glass-card p-6 flex items-center gap-5 group hover:bg-white/[0.02] transition-colors border border-amber-500/10 cursor-pointer" onclick="location.href='admin_appointment.php'">
                <div class="size-14 rounded-2xl bg-amber-500/10 flex items-center justify-center text-amber-500 group-hover:scale-105 transition-transform">
                    <span class="material-symbols-outlined text-3xl">event_note</span>
                </div>
                <div>
                    <p class="text-[10px] font-black uppercase text-gray-500 tracking-[0.1em]">Pending Bookings</p>
                    <h3 class="text-2xl font-black italic uppercase leading-tight"><?= $pending_appts ?></h3>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-10">
            <!-- Recent Transactions Table -->
            <div class="glass-card flex flex-col overflow-hidden">
                <div class="px-8 py-6 border-b border-white/5 flex justify-between items-center bg-white/[0.01]">
                    <h4 class="text-sm font-black italic uppercase tracking-tighter flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary text-lg">receipt_long</span> Recent Transactions
                    </h4>
                    <a href="admin_transaction.php" class="text-[9px] font-black uppercase tracking-widest text-gray-500 hover:text-primary transition-colors">View All</a>
                </div>
                <div class="p-2">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="text-[9px] font-black uppercase tracking-widest text-gray-600 border-b border-white/5">
                                <th class="px-6 py-4">Member</th>
                                <th class="px-6 py-4 text-center">Amount</th>
                                <th class="px-6 py-4 text-right">Date</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <?php if (empty($recent_payments)): ?>
                                <tr>
                                    <td colspan="3" class="px-6 py-10 text-center text-[10px] font-black uppercase tracking-widest text-gray-700 italic">No recent transactions</td>
                                </tr>
                            <?php else: foreach ($recent_payments as $pay): ?>
                                <tr class="hover:bg-white/[0.01] group transition-colors">
                                    <td class="px-6 py-4">
                                        <p class="text-[11px] font-bold text-white uppercase group-hover:text-primary transition-colors"><?= htmlspecialchars($pay['first_name'] . ' ' . $pay['last_name']) ?></p>
                                        <p class="text-[8px] text-gray-600 font-black tracking-widest">#<?= htmlspecialchars($pay['reference_number'] ?? 'REF-'.$pay['payment_id']) ?></p>
                                    </td>
                                    <td class="px-6 py-4 text-center">
                                        <span class="text-[10px] font-black italic">₱<?= number_format($pay['amount'], 2) ?></span>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <p class="text-[9px] font-black uppercase italic text-gray-500 tracking-tighter"><?= date('M d, Y', strtotime($pay['created_at'])) ?></p>
                                        <p class="text-[8px] font-bold text-gray-700 uppercase tracking-widest mt-0.5"><?= date('h:i A', strtotime($pay['created_at'])) ?></p>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Recent Bookings Table -->
            <div class="glass-card flex flex-col overflow-hidden">
                <div class="px-8 py-6 border-b border-white/5 flex justify-between items-center bg-white/[0.01]">
                    <h4 class="text-sm font-black italic uppercase tracking-tighter flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary text-lg">event_available</span> Recent Bookings
                    </h4>
                    <a href="admin_appointment.php" class="text-[9px] font-black uppercase tracking-widest text-gray-500 hover:text-primary transition-colors">View All</a>
                </div>
                <div class="p-2">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="text-[9px] font-black uppercase tracking-widest text-gray-600 border-b border-white/5">
                                <th class="px-6 py-4">Member</th>
                                <th class="px-6 py-4 text-center">Schedule</th>
                                <th class="px-6 py-4 text-right">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <?php if (empty($recent_bookings)): ?>
                                <tr>
                                    <td colspan="3" class="px-6 py-10 text-center text-[10px] font-black uppercase tracking-widest text-gray-700 italic">No recent bookings</td>
                                </tr>
                            <?php else: foreach ($recent_bookings as $book): ?>
                                <tr class="hover:bg-white/[0.01] group transition-colors">
                                    <td class="px-6 py-4">
                                        <p class="text-[11px] font-bold text-white uppercase group-hover:text-primary transition-colors"><?= htmlspecialchars($book['first_name'] . ' ' . $book['last_name']) ?></p>
                                        <p class="text-[8px] text-gray-600 font-black tracking-widest">@<?= htmlspecialchars($book['username']) ?></p>
                                    </td>
                                    <td class="px-6 py-4 text-center">
                                        <p class="text-[10px] font-black italic text-gray-300"><?= date('h:i A', strtotime($book['start_time'])) ?></p>
                                        <p class="text-[8px] font-bold text-gray-600 uppercase tracking-widest"><?= date('M d', strtotime($book['booking_date'])) ?></p>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <span class="text-[8px] font-black px-3 py-1 rounded-lg uppercase tracking-widest border border-white/5 bg-white/5 text-gray-400 group-hover:bg-primary/10 group-hover:text-primary transition-all"><?= htmlspecialchars($book['booking_status']) ?></span>
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