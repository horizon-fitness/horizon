<?php
session_start();
require_once '../db.php';

// Enable Error Reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Security Check
$role = strtolower($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || ($role !== 'staff' && $role !== 'coach')) {
    header("Location: ../login.php");
    exit;
}

$gym_id = $_SESSION['gym_id'];
$adminName = $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];

// --- 1. SYNC NAV ALERTS ---
$pending_payments = 0;
$pending_appts = 0;

try {
    $stmtPendingPayments = $pdo->prepare("SELECT COUNT(*) as total FROM payments WHERE status = 'Pending'");
    $stmtPendingPayments->execute();
    $pending_payments = $stmtPendingPayments->fetch()['total'] ?? 0;
} catch (Exception $e) { /* Table might not exist yet */ }

try {
    $stmtPendingAppts = $pdo->prepare("SELECT COUNT(*) as total FROM bookings WHERE status = 'Pending'");
    $stmtPendingAppts->execute();
    $pending_appts = $stmtPendingAppts->fetch()['total'] ?? 0;
} catch (Exception $e) { /* Table might not exist yet */ }

// --- 2. REPORT VIEW LOGIC ---
$tab = $_GET['tab'] ?? 'financials'; 
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$plan_filter = $_GET['plan_filter'] ?? 'all'; 
$search = $_GET['search'] ?? ''; 

// --- 3. CALCULATE GRAND TOTAL REVENUE ---
$grand_total = 0;
try {
    $stmtRevenue = $pdo->prepare("SELECT SUM(amount) as grand_total FROM payments WHERE status = 'Approved' AND payment_date BETWEEN ? AND ?");
    $stmtRevenue->execute([$start_date . ' 00:00:00', $end_date . ' 23:59:59']);
    $grand_total = $stmtRevenue->fetch()['grand_total'] ?? 0;
} catch (Exception $e) { }

// --- 4. DYNAMIC QUERY SWITCHER ---
$query_data = [];
$col_1 = "Column 1"; $col_2 = "Column 2"; $col_3 = "Column 3";

try {
    switch ($tab) {
        case 'financials':
            $sql = "SELECT u.fullname, u.username, 
                           SUM(p.amount) as total_amount, 
                           COUNT(p.payment_id) as transaction_count,
                           MAX(p.payment_date) as last_payment
                    FROM payments p 
                    JOIN users u ON p.user_id = u.id 
                    WHERE p.status = 'Approved' 
                    AND p.payment_date BETWEEN ? AND ?
                    AND (u.fullname LIKE ? OR u.username LIKE ?)
                    GROUP BY u.id
                    ORDER BY total_amount DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$start_date . ' 00:00:00', $end_date . ' 23:59:59', "%$search%", "%$search%"]);
            $query_data = $stmt->fetchAll();
            $col_1 = "Payment Summary";
            $col_2 = "Member";
            $col_3 = "Total Paid";
            break;

        case 'attendance':
            $sql = "SELECT a.*, u.fullname 
                    FROM attendance a 
                    JOIN users u ON a.username = u.username 
                    WHERE a.check_in BETWEEN ? AND ?
                    AND (u.fullname LIKE ? OR u.username LIKE ?)
                    ORDER BY a.check_in DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$start_date . ' 00:00:00', $end_date . ' 23:59:59', "%$search%", "%$search%"]);
            $query_data = $stmt->fetchAll();
            $col_1 = "Date & Time";
            $col_2 = "Member";
            $col_3 = "Status";
            break;

        case 'membership':
            // Using confirmed table names from register_member.php: client_subscriptions and website_plans
            $sql = "SELECT u.fullname, u.username, u.email,
                           (SELECT ws.plan_name FROM client_subscriptions cs 
                            JOIN website_plans ws ON cs.website_plan_id = ws.website_plan_id 
                            WHERE cs.user_id = u.id 
                            AND cs.subscription_status = 'Active' 
                            AND cs.created_at BETWEEN ? AND ?
                            ORDER BY cs.created_at DESC LIMIT 1) as plan_name,
                           (SELECT cs.created_at FROM client_subscriptions cs 
                            WHERE cs.user_id = u.id 
                            AND cs.subscription_status = 'Active'
                            AND cs.created_at BETWEEN ? AND ?
                            ORDER BY cs.created_at DESC LIMIT 1) as plan_date
                    FROM users u
                    WHERE u.role = 'member'
                    AND (u.fullname LIKE ? OR u.username LIKE ?)";
            
            if ($plan_filter === 'active') {
                $sql .= " HAVING plan_name IS NOT NULL AND plan_name != ''";
            } elseif ($plan_filter === 'standard') {
                $sql .= " HAVING plan_name IS NULL OR plan_name = ''";
            }
            $sql .= " ORDER BY (plan_name IS NOT NULL) DESC, u.fullname ASC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$start_date . ' 00:00:00', $end_date . ' 23:59:59', $start_date . ' 00:00:00', $end_date . ' 23:59:59', "%$search%", "%$search%"]);
            $query_data = $stmt->fetchAll();

            $col_1 = "Membership Type";
            $col_2 = "Member Identity";
            $col_3 = "Date Acquired";
            break;
    }
} catch (Exception $e) {
    // If query fails, we'll just have empty $query_data
    $error_msg = $e->getMessage();
}
?>

<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><title>Admin Reports | Herdoza Fitness</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "#8c2bee", "background-dark": "#0a090d", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)" } } }
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
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; padding-bottom: 90px; display: flex; flex-direction: row; min-h-screen: 100vh; }
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
        
        .report-tabs { display: inline-flex; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05); padding: 6px; border-radius: 16px; }
        .tab-item { padding: 10px 24px; border-radius: 12px; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; transition: all 0.3s ease; color: #666; }
        .tab-item:hover { color: white; }
        .tab-item.active { background: #8c2bee; color: white; box-shadow: 0 4px 15px rgba(140,43,238,0.3); }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        @media print {
            @page { margin: 0.5cm; size: auto; }
            body { background-color: white !important; color: black !important; padding: 0 !important; }
            nav, .no-print, .report-tabs, button { display: none !important; }
            .glass-card { background: white !important; border: none !important; box-shadow: none !important; border-radius: 0 !important; overflow: visible !important; }
            table { width: 100% !important; border-collapse: collapse !important; border: 1px solid black !important; }
            th, td { border: 1px solid black !important; padding: 8px !important; text-align: left !important; color: black !important; }
            th { background-color: #f0f0f0 !important; font-weight: bold !important; }
        }
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
        <a href="admin_attendance.php" class="nav-item text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl shrink-0">history</span>
            <span class="nav-label font-black text-[10px] uppercase tracking-wider">Attendance</span>
        </a>
        <a href="admin_report.php" class="nav-item active text-primary">
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
                <h1 class="text-3xl font-black text-white italic uppercase tracking-tighter mb-1">Data <span class="text-primary">Reports</span></h1>
                <p class="text-primary text-xs font-bold uppercase tracking-widest">Financial, Attendance, and Membership</p>
            </div>
            <div class="flex flex-wrap items-center gap-4 no-print">
                <form method="GET" class="flex gap-2">
                    <input type="hidden" name="tab" value="<?= $_GET['tab'] ?? 'financials' ?>">
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 text-xs">search</span>
                        <input type="text" name="search" placeholder="Search..." class="bg-white/5 border border-white/10 rounded-xl text-[10px] font-bold py-3 pl-9 pr-4 text-white focus:outline-none focus:border-primary w-40">
                    </div>
                    <button type="submit" class="bg-white/5 border border-white/10 px-4 py-3 rounded-xl text-[10px] font-black uppercase hover:bg-white/10 transition-all">
                        Filter
                    </button>
                </form>
                <div class="flex items-center gap-3">
                    <button type="button" onclick="window.print()" class="bg-primary text-white px-6 py-3 rounded-xl text-[10px] font-black uppercase tracking-widest hover:bg-primary/90 flex items-center gap-2 transition-all">
                        <span class="material-symbols-outlined text-sm">print</span> Print
                    </button>
                </div>
                <!-- Clock -->
                <div class="px-6 py-3.5 rounded-2xl bg-white/5 border border-white/5 text-right flex flex-col items-end group shadow-sm hover:shadow-primary/10 transition-shadow">
                    <p id="headerClock" class="text-white font-black italic text-xl leading-none mb-1 group-hover:text-primary transition-colors">00:00:00 AM</p>
                    <p class="text-gray-500 text-[9px] font-black uppercase tracking-[0.2em] group-hover:text-white transition-colors"><?= date('l, M d') ?></p>
                </div>
            </div>
        </header>

        <div class="mb-8 p-6 rounded-3xl bg-gradient-to-r from-primary/10 to-transparent border border-primary/20 flex items-center justify-between print:border-black print:border-2">
            <div>
                <p class="text-primary text-xs font-black uppercase tracking-widest mb-1">Total Revenue (Selected Period)</p>
                <h2 class="text-4xl font-black text-white italic">₱<?= number_format($grand_total, 2) ?></h2>
            </div>
            <div class="size-12 rounded-full bg-primary/20 flex items-center justify-center text-primary no-print">
                <span class="material-symbols-outlined text-2xl">account_balance_wallet</span>
            </div>
        </div>

        <div class="mb-8 no-print flex justify-between items-center">
            <div class="report-tabs">
                <a href="?tab=financials" class="tab-item <?= $tab === 'financials' ? 'active' : '' ?>">Financials</a>
                <a href="?tab=membership" class="tab-item <?= $tab === 'membership' ? 'active' : '' ?>">Subscriptions</a>
                <a href="?tab=attendance" class="tab-item <?= $tab === 'attendance' ? 'active' : '' ?>">Attendance</a>
            </div>
            <button onclick="window.print()" class="text-gray-500 hover:text-white flex items-center gap-2 text-[10px] font-black uppercase tracking-widest transition-all group">
                <span class="material-symbols-outlined text-lg group-hover:text-primary">print</span>
                Print Current View
            </button>
        </div>

        <div class="glass-card overflow-hidden shadow-2xl">
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="text-[10px] font-black uppercase text-gray-500 tracking-widest bg-white/5 border-b border-white/5">
                            <th class="px-8 py-6"><?= $col_1 ?></th>
                            <th class="px-8 py-6"><?= $col_2 ?></th>
                            <th class="px-8 py-6 text-right"><?= $col_3 ?></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5 print:divide-black">
                        <?php if (!empty($query_data)): ?>
                            <?php foreach($query_data as $row): ?>
                                <?php if ($tab === 'financials'): ?>
                                    <tr class="text-sm hover:bg-white/[0.02]">
                                        <td class="px-8 py-6">
                                            <p class="text-white font-bold text-xs"><?= $row['transaction_count'] ?> Trans.</p>
                                            <p class="text-gray-500 text-[10px]">Last: <?= date('M d', strtotime($row['last_payment'])) ?></p>
                                        </td>
                                        <td class="px-8 py-6">
                                            <p class="text-white font-bold uppercase italic"><?= htmlspecialchars($row['fullname']) ?></p>
                                            <p class="text-[9px] text-gray-600">@<?= htmlspecialchars($row['username']) ?></p>
                                        </td>
                                        <td class="px-8 py-6 text-right font-black text-emerald-400 text-lg">₱<?= number_format($row['total_amount'], 2) ?></td>
                                    </tr>
                                <?php elseif ($tab === 'attendance'): ?>
                                    <tr class="text-sm hover:bg-white/[0.02]">
                                        <td class="px-8 py-6">
                                            <p class="text-white font-bold text-xs"><?= date('M d, Y h:i A', strtotime($row['check_in'])) ?></p>
                                        </td>
                                        <td class="px-8 py-6">
                                            <p class="text-white font-bold uppercase italic"><?= htmlspecialchars($row['fullname']) ?></p>
                                        </td>
                                        <td class="px-8 py-6 text-right">
                                            <?php if(empty($row['check_out']) || $row['check_out'] == '0000-00-00 00:00:00'): ?>
                                                <span class="text-primary font-black uppercase text-[10px]">Active</span>
                                            <?php else: ?>
                                                <span class="text-gray-500 font-bold text-[10px]">Completed</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php elseif ($tab === 'membership'): ?>
                                    <tr class="text-sm hover:bg-white/[0.02]">
                                        <td class="px-8 py-6">
                                            <p class="text-primary font-black uppercase text-xs"><?= htmlspecialchars($row['plan_name'] ?: 'No Active Plan') ?></p>
                                        </td>
                                        <td class="px-8 py-6">
                                            <p class="text-white font-bold uppercase italic"><?= htmlspecialchars($row['fullname']) ?></p>
                                            <p class="text-[9px] text-gray-600">@<?= htmlspecialchars($row['username']) ?></p>
                                        </td>
                                        <td class="px-8 py-6 text-right">
                                            <p class="text-white font-bold text-xs"><?= !empty($row['plan_date']) ? date('M d, Y', strtotime($row['plan_date'])) : 'N/A' ?></p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="3" class="px-8 py-20 text-center text-gray-600 uppercase font-black text-xs italic tracking-widest">
                                    No records found for the selected filters
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>
</body>
</html>
