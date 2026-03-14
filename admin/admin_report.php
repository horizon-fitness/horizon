<?php
/*
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require "db.php"; 

if (!isset($_SESSION["user_id"]) || strtolower($_SESSION["role"] ?? '') !== "admin") {
    header("Location: login.php");
    exit();
}

$admin_name = $_SESSION["fullname"] ?? "Administrator";

// --- 1. SYNC NAV ALERTS ---
$nav_alert_q = mysqli_query($conn, "SELECT COUNT(*) as total FROM payments WHERE status = 'Pending'");
$pending_payments = ($nav_alert_q) ? (mysqli_fetch_assoc($nav_alert_q)['total'] ?? 0) : 0;

$appt_alert_q = mysqli_query($conn, "SELECT COUNT(*) as total FROM bookings WHERE trainer_id = '1' AND status = 'Pending'");
$pending_appts = ($appt_alert_q) ? (mysqli_fetch_assoc($appt_alert_q)['total'] ?? 0) : 0;

// --- 2. REPORT VIEW LOGIC ---
$tab = $_GET['tab'] ?? 'financials'; 
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$plan_filter = $_GET['plan_filter'] ?? 'all'; 
$search = $_GET['search'] ?? ''; // NEW: Search Input

// --- 3. CALCULATE GRAND TOTAL REVENUE ---
$revenue_query = "SELECT SUM(amount) as grand_total FROM payments WHERE status = 'Approved' AND payment_date BETWEEN '$start_date 00:00:00' AND '$end_date 23:59:59'";
$revenue_result = mysqli_query($conn, $revenue_query);
$revenue_data = mysqli_fetch_assoc($revenue_result);
$grand_total = $revenue_data['grand_total'] ?? 0;

// --- 4. PREPARE SEARCH SQL (NEW) ---
$search_sql = "";
if (!empty($search)) {
    $safe_search = mysqli_real_escape_string($conn, $search);
    $search_sql = " AND (u.fullname LIKE '%$safe_search%' OR u.username LIKE '%$safe_search%')";
}

// --- 5. DYNAMIC QUERY SWITCHER ---
switch ($tab) {
    case 'financials':
        $query = "SELECT u.fullname, u.username, 
                         SUM(p.amount) as total_amount, 
                         COUNT(p.payment_id) as transaction_count,
                         MAX(p.payment_date) as last_payment
                  FROM payments p 
                  JOIN users u ON p.user_id = u.id 
                  WHERE p.status = 'Approved' 
                  AND p.payment_date BETWEEN '$start_date 00:00:00' AND '$end_date 23:59:59'
                  $search_sql  -- Search Added Here
                  GROUP BY u.id
                  ORDER BY total_amount DESC";
        $col_1 = "Payment Summary";
        $col_2 = "Member";
        $col_3 = "Total Paid";
        break;

    case 'attendance':
        $query = "SELECT a.*, u.fullname 
                  FROM attendance a 
                  JOIN users u ON a.username = u.username 
                  WHERE a.check_in BETWEEN '$start_date 00:00:00' AND '$end_date 23:59:59'
                  $search_sql  -- Search Added Here
                  ORDER BY a.check_in DESC";
        $col_1 = "Date & Time";
        $col_2 = "Member";
        $col_3 = "Status";
        break;

    case 'membership':
        $query = "SELECT u.fullname, u.username, u.email,
                         (SELECT s.session_name FROM bookings b 
                          JOIN sessions s ON b.session_id = s.session_id 
                          WHERE b.user_id = u.id 
                          AND b.status = 'Confirmed' 
                          AND b.booking_date BETWEEN '$start_date' AND '$end_date'
                          ORDER BY b.booking_date DESC LIMIT 1) as plan_name,
                         (SELECT b.booking_date FROM bookings b 
                          WHERE b.user_id = u.id 
                          AND b.status = 'Confirmed'
                          AND b.booking_date BETWEEN '$start_date' AND '$end_date'
                          ORDER BY b.booking_date DESC LIMIT 1) as plan_date
                  FROM users u
                  WHERE u.role = 'member'
                  $search_sql"; // Search Added Here
        
        if ($plan_filter === 'active') {
            $query .= " HAVING plan_name IS NOT NULL AND plan_name != ''";
        } elseif ($plan_filter === 'standard') {
            $query .= " HAVING plan_name IS NULL OR plan_name = ''";
        }
        $query .= " ORDER BY (plan_name IS NOT NULL) DESC, u.fullname ASC";

        $col_1 = "Membership Type";
        $col_2 = "Member Identity";
        $col_3 = "Date Acquired";
        break;
}

$result = mysqli_query($conn, $query);
*/
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
            if(document.getElementById('sidebarClock')) document.getElementById('sidebarClock').textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }
        window.onload = function() { setInterval(updateHeaderClock, 1000); updateHeaderClock(); };
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; padding-bottom: 90px; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        .nav-link { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; transition: all 0.2s; white-space: nowrap; }
        .active-nav { color: #8c2bee !important; position: relative; }
        .active-nav::after { content: ''; position: absolute; right: -32px; top: 0; width: 4px; height: 100%; background: #8c2bee; border-radius: 2px; }
        .report-tabs { display: inline-flex; background: rgba(255, 255, 255, 0.03); border: 1px solid rgba(255, 255, 255, 0.05); padding: 6px; border-radius: 16px; }
        .tab-item { padding: 10px 24px; border-radius: 12px; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; transition: all 0.3s ease; color: #666; }
        .tab-item:hover { color: white; }
        .tab-item.active { background: #8c2bee; color: white; box-shadow: 0 4px 15px rgba(140, 43, 238, 0.3); }
        .alert-dot { animation: pulse 2s infinite; }
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.4; } 100% { opacity: 1; } }
        .mobile-taskbar { background: rgba(20, 18, 26, 0.9); backdrop-filter: blur(20px); border-top: 1px solid rgba(255,255,255,0.05); }
        
        /* --- OPTIMIZED PRINT STYLES --- */
        @media print {
            @page { margin: 0.5cm; size: auto; }
            body { background-color: white !important; color: black !important; padding: 0 !important; }
            nav, .no-print, .report-tabs, .mobile-taskbar, button { display: none !important; }
            .glass-card { 
                background: white !important; 
                border: none !important; 
                box-shadow: none !important; 
                border-radius: 0 !important; 
                overflow: visible !important;
            }
            .text-white, .text-gray-300, .text-gray-400, .text-gray-500, .text-gray-600 { color: black !important; }
            .text-primary, .text-emerald-400 { color: black !important; font-weight: bold !important; }
            .bg-gradient-to-r { background: none !important; border: 1px solid black !important; }
            
            /* Table Print Styling */
            table { width: 100% !important; border-collapse: collapse !important; border: 1px solid black !important; }
            th, td { 
                border: 1px solid black !important; 
                padding: 8px !important; 
                text-align: left !important;
                color: black !important;
            }
            th { background-color: #f0f0f0 !important; font-weight: bold !important; }
            
            /* Headers */
            h1, h2, h3, p { color: black !important; text-shadow: none !important; }
            
            /* Layout */
            .max-w-7xl { max-width: 100% !important; padding: 0 !important; margin: 0 !important; }
            header { margin-bottom: 20px !important; border-bottom: 2px solid black !important; padding-bottom: 10px !important; }
        }
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
        <a href="admin_attendance.php" class="nav-link text-gray-400 hover:text-white flex items-center gap-3">
            <span class="material-symbols-outlined text-xl">history</span> Attendance
        </a>
        <a href="admin_appointment.php" class="nav-link text-gray-400 hover:text-white flex items-center gap-3">
            <span class="material-symbols-outlined text-xl">event_available</span> Appointment
        </a>
        <a href="admin_report.php" class="nav-link active-nav flex items-center gap-3">
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
                <h1 class="text-3xl font-black text-white italic uppercase tracking-tighter mb-1">Data <span class="text-primary">Reports</span></h1>
                <p class="text-primary text-xs font-bold uppercase tracking-widest">Financial, Attendance, and Membership</p>
            </div>
            <div class="flex flex-wrap gap-3 no-print">
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
                <button type="button" onclick="window.print()" class="bg-primary text-white px-6 py-3 rounded-xl text-[10px] font-black uppercase tracking-widest hover:bg-primary/90 flex items-center gap-2 transition-all">
                    <span class="material-symbols-outlined text-sm">print</span> Print
                </button>
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
                        <?php
                        $tab = $_GET['tab'] ?? 'financials';
                        if ($tab === 'financials'):
                            $financial_data = [
                                ['transaction_count' => 2, 'last_payment' => '2024-01-01', 'fullname' => 'John Doe', 'username' => 'johndoe', 'total_amount' => '2500.00'],
                                ['transaction_count' => 1, 'last_payment' => '2024-01-02', 'fullname' => 'Jane Smith', 'username' => 'janesmith', 'total_amount' => '1500.00']
                            ];
                            foreach($financial_data as $row): ?>
                                <tr class="text-sm hover:bg-white/[0.02]">
                                    <td class="px-8 py-6"><p class="text-white font-bold text-xs"><?= $row['transaction_count'] ?> Trans.</p><p class="text-gray-500 text-[10px]">Last: <?= date('M d', strtotime($row['last_payment'])) ?></p></td>
                                    <td class="px-8 py-6"><p class="text-white font-bold uppercase italic"><?= htmlspecialchars($row['fullname']) ?></p><p class="text-[9px] text-gray-600">@<?= htmlspecialchars($row['username']) ?></p></td>
                                    <td class="px-8 py-6 text-right font-black text-emerald-400 text-lg">₱<?= number_format($row['total_amount'], 2) ?></td>
                                </tr>
                            <?php endforeach;
                        elseif ($tab === 'attendance'):
                            $attendance_data = [
                                ['check_in' => '2024-01-01', 'fullname' => 'John Doe', 'check_out' => '2024-01-01 10:00:00'],
                                ['check_in' => '2024-01-02', 'fullname' => 'Jane Smith', 'check_out' => null]
                            ];
                            foreach($attendance_data as $row): ?>
                                <tr class="text-sm hover:bg-white/[0.02]">
                                    <td class="px-8 py-6"><p class="text-white font-bold text-xs"><?= date('M d, Y', strtotime($row['check_in'])) ?></p></td>
                                    <td class="px-8 py-6"><p class="text-white font-bold uppercase italic"><?= htmlspecialchars($row['fullname']) ?></p></td>
                                    <td class="px-8 py-6 text-right"><?php if(empty($row['check_out'])): ?><span class="text-primary font-black uppercase text-[10px]">Active</span><?php else: ?><span class="text-gray-500 font-bold text-[10px]">Completed</span><?php endif; ?></td>
                                </tr>
                            <?php endforeach;
                        elseif ($tab === 'membership'):
                            $membership_data = [
                                ['plan_name' => 'Premium', 'fullname' => 'John Doe', 'plan_date' => '2024-01-01'],
                                ['plan_name' => 'Basic', 'fullname' => 'Jane Smith', 'plan_date' => '2024-01-02']
                            ];
                            foreach($membership_data as $row): ?>
                                <tr class="text-sm hover:bg-white/[0.02]">
                                    <td class="px-8 py-6"><p class="text-primary font-black uppercase text-xs"><?= htmlspecialchars($row['plan_name'] ?: 'Basic') ?></p></td>
                                    <td class="px-8 py-6"><p class="text-white font-bold uppercase italic"><?= htmlspecialchars($row['fullname']) ?></p></td>
                                    <td class="px-8 py-6 text-right"><p class="text-white font-bold text-xs"><?= $row['plan_date'] ? date('M d, Y', strtotime($row['plan_date'])) : 'N/A' ?></p></td>
                                </tr>
                            <?php endforeach;
                        endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>
</body>
</html>
