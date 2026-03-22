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

// --- DATA FETCHING ---
// 1. Financial Transactions
$stmtFin = $pdo->prepare("SELECT * FROM payments WHERE gym_id = ? ORDER BY payment_date DESC LIMIT 20");
$stmtFin->execute([$gym_id]);
$financials = $stmtFin->fetchAll();

// 2. Attendance Logs
$stmtAtt = $pdo->prepare("
    SELECT a.*, u.first_name, u.last_name 
    FROM attendance a 
    JOIN users u ON a.user_id = u.user_id 
    WHERE a.gym_id = ? 
    ORDER BY a.check_in DESC LIMIT 20
");
$stmtAtt->execute([$gym_id]);
$attendance = $stmtAtt->fetchAll();

// 3. Membership Subscriptions
$stmtSub = $pdo->prepare("
    SELECT m.*, u.first_name, u.last_name 
    FROM members m 
    JOIN users u ON m.user_id = u.user_id 
    WHERE m.gym_id = ? 
    ORDER BY m.created_at DESC LIMIT 20
");
$stmtSub->execute([$gym_id]);
$subscriptions = $stmtSub->fetchAll();

// Sidebar Info
$stmtGym = $pdo->prepare("SELECT gym_name FROM gyms WHERE gym_id = ?");
$stmtGym->execute([$gym_id]);
$gym = $stmtGym->fetch();
?>

<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8">
    <title>Reports & Analytics | Horizon</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;700;900&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "#8c2bee", "bg-dark": "#050505", "card-dark": "#121212" }}}
        }
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: #050505; color: white; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .report-tab-active { background: #8c2bee; color: white; box-shadow: 0 10px 20px -5px rgba(140, 43, 238, 0.4); }
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
        <a href="reports.php" class="flex items-center gap-4 px-4 py-3 rounded-xl bg-primary/10 text-primary border border-primary/20">
            <span class="material-symbols-outlined">analytics</span>
            <span class="font-bold uppercase tracking-tighter text-sm italic">Reports</span>
        </a>
        <a href="sales_reports.php" class="flex items-center gap-4 px-4 py-3 rounded-xl text-gray-400 hover:bg-white/5 transition-all group">
            <span class="material-symbols-outlined group-hover:text-primary">payments</span>
            <span class="font-bold uppercase tracking-tighter text-sm italic">Sales Reports</span>
        </a>
    </nav>
</aside>

<main class="flex-1 overflow-y-auto no-scrollbar p-10">
    <header class="mb-10 flex flex-col md:flex-row md:items-end justify-between gap-6">
        <div>
            <h2 class="text-5xl font-black italic uppercase tracking-tighter">System <span class="text-primary">Reports</span></h2>
            <p class="text-gray-500 font-bold uppercase tracking-[0.3em] text-[10px] mt-2">Audit logs and business intelligence</p>
        </div>
        
        <div class="flex bg-card-dark rounded-2xl p-1.5 border border-white/5 self-start">
            <button onclick="switchReport('financial')" id="btn-financial" class="report-tab-active px-6 py-2.5 rounded-xl font-black italic uppercase tracking-tighter text-[11px] transition-all">Financials</button>
            <button onclick="switchReport('attendance')" id="btn-attendance" class="text-gray-500 hover:text-white px-6 py-2.5 rounded-xl font-black italic uppercase tracking-tighter text-[11px] transition-all">Attendance</button>
            <button onclick="switchReport('membership')" id="btn-membership" class="text-gray-500 hover:text-white px-6 py-2.5 rounded-xl font-black italic uppercase tracking-tighter text-[11px] transition-all">Subscriptions</button>
        </div>
    </header>

    <div class="bg-card-dark rounded-[32px] border border-white/5 overflow-hidden shadow-2xl min-h-[500px]">
        
        <div id="section-financial" class="report-section">
            <div class="px-8 py-6 border-b border-white/5 bg-white/[0.02] flex justify-between items-center">
                <h3 class="font-black italic uppercase text-xs tracking-widest">Recent Transactions</h3>
                <button class="text-[10px] font-black uppercase text-primary border border-primary/20 px-4 py-2 rounded-lg hover:bg-primary/10 transition-all">Export CSV</button>
            </div>
            <table class="w-full text-left">
                <thead class="text-[10px] font-black uppercase text-gray-500 tracking-widest border-b border-white/5">
                    <tr>
                        <th class="px-8 py-5">Ref ID</th>
                        <th class="px-8 py-5">Amount</th>
                        <th class="px-8 py-5">Method</th>
                        <th class="px-8 py-5">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5 text-sm font-medium">
                    <?php foreach($financials as $f): ?>
                    <tr class="hover:bg-white/[0.01]">
                        <td class="px-8 py-6 text-gray-400 font-mono">#<?= $f['payment_id'] ?></td>
                        <td class="px-8 py-6 font-black text-white italic">₱<?= number_format($f['amount'], 2) ?></td>
                        <td class="px-8 py-6 uppercase text-[10px] font-bold"><?= $f['payment_method'] ?></td>
                        <td class="px-8 py-6 text-gray-500"><?= date('M d, Y', strtotime($f['payment_date'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div id="section-attendance" class="report-section hidden">
            <div class="px-8 py-6 border-b border-white/5 bg-white/[0.02] flex justify-between items-center">
                <h3 class="font-black italic uppercase text-xs tracking-widest text-emerald-500">Member Check-ins</h3>
            </div>
            <table class="w-full text-left">
                <thead class="text-[10px] font-black uppercase text-gray-500 tracking-widest border-b border-white/5">
                    <tr>
                        <th class="px-8 py-5">Member</th>
                        <th class="px-8 py-5">Check In</th>
                        <th class="px-8 py-5">Check Out</th>
                        <th class="px-8 py-5">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5 text-sm">
                    <?php foreach($attendance as $a): ?>
                    <tr class="hover:bg-white/[0.01]">
                        <td class="px-8 py-6 font-black italic uppercase tracking-tighter"><?= $a['first_name'] ?> <?= $a['last_name'] ?></td>
                        <td class="px-8 py-6 text-emerald-400 font-bold"><?= date('h:i A', strtotime($a['check_in'])) ?></td>
                        <td class="px-8 py-6 text-gray-500"><?= $a['check_out'] ? date('h:i A', strtotime($a['check_out'])) : '---' ?></td>
                        <td class="px-8 py-6"><span class="px-2 py-0.5 rounded bg-emerald-500/10 text-emerald-500 text-[9px] font-black uppercase">Present</span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div id="section-membership" class="report-section hidden">
            <div class="px-8 py-6 border-b border-white/5 bg-white/[0.02] flex justify-between items-center">
                <h3 class="font-black italic uppercase text-xs tracking-widest text-amber-500">Subscription Flow</h3>
            </div>
            <table class="w-full text-left">
                <thead class="text-[10px] font-black uppercase text-gray-500 tracking-widest border-b border-white/5">
                    <tr>
                        <th class="px-8 py-5">Member</th>
                        <th class="px-8 py-5">Tier</th>
                        <th class="px-8 py-5">Renewal Date</th>
                        <th class="px-8 py-5">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5 text-sm">
                    <?php foreach($subscriptions as $s): ?>
                    <tr class="hover:bg-white/[0.01]">
                        <td class="px-8 py-6 font-black italic uppercase tracking-tighter"><?= $s['first_name'] ?> <?= $s['last_name'] ?></td>
                        <td class="px-8 py-6 text-[10px] font-black uppercase text-gray-400 italic"><?= $s['membership_type'] ?></td>
                        <td class="px-8 py-6 text-gray-500 font-bold"><?= date('M d, Y', strtotime($s['end_date'] ?? '+1 month')) ?></td>
                        <td class="px-8 py-6">
                            <span class="px-3 py-1 rounded-full text-[8px] font-black uppercase border border-white/5 bg-white/5">
                                <?= $s['member_status'] ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    </div>
</main>

<script>
    function switchReport(type) {
        // Sections
        const sections = document.querySelectorAll('.report-section');
        sections.forEach(s => s.classList.add('hidden'));
        document.getElementById('section-' + type).classList.remove('hidden');

        // Buttons
        const buttons = [document.getElementById('btn-financial'), document.getElementById('btn-attendance'), document.getElementById('btn-membership')];
        buttons.forEach(btn => {
            btn.classList.remove('report-tab-active', 'text-white');
            btn.classList.add('text-gray-500');
        });

        const activeBtn = document.getElementById('btn-' + type);
        activeBtn.classList.add('report-tab-active', 'text-white');
        activeBtn.classList.remove('text-gray-500');
    }
</script>

</body>
</html>