<?php
session_start();
require_once '../db.php';

// Security Check
$role = strtolower($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || !in_array($role, ['tenant', 'admin'])) {
    header("Location: ../login.php");
    exit;
}

$gym_id = $_SESSION['gym_id'];

// --- FINANCIAL CALCULATIONS ---
// Total Revenue (Lifetime)
$stmtTotal = $pdo->prepare("SELECT SUM(amount) as total FROM payments WHERE gym_id = ?");
$stmtTotal->execute([$gym_id]);
$total_revenue = $stmtTotal->fetchColumn() ?? 0;

// Monthly Sales (Current Month)
$stmtMonthly = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE gym_id = ? AND MONTH(payment_date) = MONTH(CURRENT_DATE()) AND YEAR(payment_date) = YEAR(CURRENT_DATE())");
$stmtMonthly->execute([$gym_id]);
$monthly_sales = $stmtMonthly->fetchColumn() ?? 0;

// Daily Sales (Today)
$stmtDaily = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE gym_id = ? AND DATE(payment_date) = CURDATE()");
$stmtDaily->execute([$gym_id]);
$daily_sales = $stmtDaily->fetchColumn() ?? 0;

// Transaction History Summary (Latest 50)
$stmtHistory = $pdo->prepare("
    SELECT p.*, u.first_name, u.last_name 
    FROM payments p 
    LEFT JOIN users u ON p.user_id = u.user_id 
    WHERE p.gym_id = ? 
    ORDER BY p.payment_date DESC LIMIT 50
");
$stmtHistory->execute([$gym_id]);
$transactions = $stmtHistory->fetchAll();

// Sidebar Info
$stmtGym = $pdo->prepare("SELECT gym_name FROM gyms WHERE gym_id = ?");
$stmtGym->execute([$gym_id]);
$gym = $stmtGym->fetch();
?>

<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8">
    <title>Sales Intelligence | Horizon</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;700;900&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "#8c2bee", "bg-dark": "#050505", "card-dark": "#121212" }}}
        }
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: #050505; color: white; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .stat-card { background: linear-gradient(145deg, #121212 0%, #0a0a0a 100%); border: 1px solid rgba(255,255,255,0.05); }
    </style>
</head>
<body class="flex h-screen overflow-hidden">

<aside class="w-72 bg-bg-dark border-r border-white/5 p-8 flex flex-col shrink-0">
    <div class="flex items-center gap-3 mb-10">
        <div class="size-12 rounded-xl bg-primary/10 flex items-center justify-center border border-primary/20">
            <span class="material-symbols-outlined text-primary font-bold text-3xl">bolt</span>
        </div>
        <h1 class="text-xl font-black italic uppercase tracking-tighter truncate"><?= htmlspecialchars($gym['gym_name']) ?></h1>
    </div>

    <nav class="flex flex-col gap-2 flex-1">
        <a href="tenant_dashboard.php" class="flex items-center gap-4 px-4 py-3 rounded-xl text-gray-400 hover:bg-white/5 transition-all group">
            <span class="material-symbols-outlined group-hover:text-primary">grid_view</span>
            <span class="font-bold uppercase tracking-tighter text-sm italic">Dashboard</span>
        </a>
        <a href="reports.php" class="flex items-center gap-4 px-4 py-3 rounded-xl text-gray-400 hover:bg-white/5 transition-all group">
            <span class="material-symbols-outlined group-hover:text-primary">analytics</span>
            <span class="font-bold uppercase tracking-tighter text-sm italic">Reports</span>
        </a>
        <a href="sales_reports.php" class="flex items-center gap-4 px-4 py-3 rounded-xl bg-primary/10 text-primary border border-primary/20">
            <span class="material-symbols-outlined">payments</span>
            <span class="font-bold uppercase tracking-tighter text-sm italic">Sales Reports</span>
        </a>
    </nav>
</aside>

<main class="flex-1 overflow-y-auto no-scrollbar p-10">
    <header class="mb-10 flex flex-col md:flex-row md:items-end justify-between gap-6">
        <div>
            <h2 class="text-5xl font-black italic uppercase tracking-tighter text-primary">Sales <span class="text-white">Report</span></h2>
            <p class="text-gray-500 font-bold uppercase tracking-[0.3em] text-[10px] mt-2">Revenue Tracking & Transaction Analytics</p>
        </div>
        <div class="flex gap-3">
            <button class="px-6 py-3 rounded-xl bg-white/5 border border-white/10 font-black italic uppercase text-[10px] tracking-widest hover:bg-white/10 transition-all">Download PDF</button>
            <button class="px-6 py-3 rounded-xl bg-primary text-white font-black italic uppercase text-[10px] tracking-widest shadow-lg shadow-primary/20">Generate Audit</button>
        </div>
    </header>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-10">
        <div class="stat-card p-8 rounded-[32px]">
            <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Total Revenue</p>
            <h3 class="text-3xl font-black italic tracking-tighter text-white">₱<?= number_format($total_revenue, 2) ?></h3>
        </div>
        <div class="stat-card p-8 rounded-[32px] border-l-4 border-primary">
            <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Monthly Sales</p>
            <h3 class="text-3xl font-black italic tracking-tighter text-primary">₱<?= number_format($monthly_sales, 2) ?></h3>
        </div>
        <div class="stat-card p-8 rounded-[32px]">
            <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Weekly Forecast</p>
            <h3 class="text-3xl font-black italic tracking-tighter text-white">₱<?= number_format($monthly_sales / 4, 2) ?></h3>
        </div>
        <div class="stat-card p-8 rounded-[32px] border-l-4 border-emerald-500">
            <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Daily Revenue</p>
            <h3 class="text-3xl font-black italic tracking-tighter text-emerald-500">₱<?= number_format($daily_sales, 2) ?></h3>
        </div>
    </div>

    <div class="bg-card-dark rounded-[32px] border border-white/5 overflow-hidden shadow-2xl">
        <div class="px-8 py-6 border-b border-white/5 flex justify-between items-center bg-white/[0.02]">
            <h4 class="font-black italic uppercase text-xs tracking-widest flex items-center gap-2">
                <span class="material-symbols-outlined text-primary">receipt_long</span> Transaction History
            </h4>
            <div class="text-[10px] font-bold text-gray-500 italic uppercase">Showing last 50 entries</div>
        </div>
        
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="text-[10px] font-black uppercase tracking-widest text-gray-500 border-b border-white/5">
                        <th class="px-8 py-5">Transaction ID</th>
                        <th class="px-8 py-5">Customer / Member</th>
                        <th class="px-8 py-5">Amount</th>
                        <th class="px-8 py-5">Method</th>
                        <th class="px-8 py-5">Timestamp</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php if(empty($transactions)): ?>
                        <tr><td colspan="5" class="px-8 py-20 text-center text-gray-600 font-black italic uppercase">No financial data recorded yet.</td></tr>
                    <?php else: ?>
                        <?php foreach($transactions as $t): ?>
                        <tr class="hover:bg-white/[0.02] transition-all group">
                            <td class="px-8 py-6 text-xs font-mono text-gray-400">#TRX-<?= str_pad($t['payment_id'], 5, '0', STR_PAD_LEFT) ?></td>
                            <td class="px-8 py-6">
                                <p class="font-black italic uppercase tracking-tighter text-sm">
                                    <?= $t['first_name'] ? htmlspecialchars($t['first_name'].' '.$t['last_name']) : 'Guest Transaction' ?>
                                </p>
                            </td>
                            <td class="px-8 py-6">
                                <span class="text-sm font-black italic text-emerald-400">₱<?= number_format($t['amount'], 2) ?></span>
                            </td>
                            <td class="px-8 py-6">
                                <span class="text-[9px] font-black uppercase px-3 py-1 rounded bg-white/5 border border-white/10 text-gray-400">
                                    <?= $t['payment_method'] ?>
                                </span>
                            </td>
                            <td class="px-8 py-6 text-[10px] font-bold text-gray-500 uppercase">
                                <?= date('M d, Y | h:i A', strtotime($t['payment_date'])) ?>
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