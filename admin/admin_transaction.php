<?php
session_start();
require_once '../db.php';

// Security Check: Only Staff and Coach
$role = strtolower($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || ($role !== 'staff' && $role !== 'coach')) {
    header("Location: ../login.php");
    exit;
}

$gym_id = $_SESSION['gym_id'];
$admin_name = $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];

// --- FETCH BRANDING & CONFIG ---
$stmtGym = $pdo->prepare("SELECT * FROM gyms WHERE gym_id = ?");
$stmtGym->execute([$gym_id]);
$gym = $stmtGym->fetch();

$stmtPage = $pdo->prepare("SELECT * FROM tenant_pages WHERE gym_id = ? LIMIT 1");
$stmtPage->execute([$gym_id]);
$tenant_config = $stmtPage->fetch();

// --- FILTERING LOGIC ---
$search = $_GET['search'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Base Query
$sql_parts = ["m.gym_id = :gym_id"];
$sql_params = [':gym_id' => $gym_id];

if (!empty($search)) {
    $sql_parts[] = "(u.first_name LIKE :s1 OR u.last_name LIKE :s2 OR u.username LIKE :s3)";
    $sql_params[':s1'] = "%$search%";
    $sql_params[':s2'] = "%$search%";
    $sql_params[':s3'] = "%$search%";
}

if (!empty($date_from)) {
    $sql_parts[] = "p.created_at >= :d1";
    $sql_params[':d1'] = $date_from . ' 00:00:00';
}

if (!empty($date_to)) {
    $sql_parts[] = "p.created_at <= :d2";
    $sql_params[':d2'] = $date_to . ' 23:59:59';
}

$where_clause = "WHERE " . implode(' AND ', $sql_parts);

$sql = "
    SELECT p.*, u.first_name, u.last_name, u.username 
    FROM payments p 
    JOIN members m ON p.member_id = m.member_id 
    JOIN users u ON m.user_id = u.user_id 
    $where_clause 
    ORDER BY p.created_at DESC
";

$stmtPayments = $pdo->prepare($sql);
$stmtPayments->execute($sql_params);
$payments_list = $stmtPayments->fetchAll();

$active_page = "admin_transaction";
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>Transaction Ledger | Horizon Partners</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet" />
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "<?= $tenant_config['theme_color'] ?? '#8c2bee' ?>",
                        "background-dark": "<?= $tenant_config['bg_color'] ?? '#0a090d' ?>",
                        "surface-dark": "#14121a",
                        "border-subtle": "rgba(255,255,255,0.05)"
                    }
                }
            }
        } 
    </script>
    <style>
        body {
            font-family: 'Lexend', sans-serif;
            background-color: <?= $tenant_config['bg_color'] ?? '#0a090d' ?>;
            color: white;
            display: flex;
            flex-direction: row;
            min-height: 100vh;
            overflow: hidden;
        }

        .glass-card {
            background: #14121a;
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 24px;
        }

        /* Sidebar Hover Logic */
        :root { --nav-width: 110px; }
        body:has(.side-nav:hover) { --nav-width: 300px; }

        .side-nav {
            width: var(--nav-width);
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            position: fixed;
            left: 0;
            top: 0;
            height: 100vh;
            z-index: 110;
        }

        .main-content {
            margin-left: var(--nav-width);
            flex: 1;
            min-width: 0;
            transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow-y: auto;
            overflow-x: hidden;
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
            margin-bottom: 8px !important;
            pointer-events: auto;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 10px 38px;
            transition: all 0.2s ease;
            text-decoration: none;
            white-space: nowrap;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #94a3b8;
        }

        .nav-item:hover {
            background: rgba(255, 255, 255, 0.05);
            color: white;
        }

        .nav-item.active {
            color: <?= $tenant_config['theme_color'] ?? '#8c2bee' ?> !important;
            position: relative;
        }

        .nav-item.active::after {
            content: '';
            position: absolute;
            right: 0px;
            top: 50%;
            transform: translateY(-50%);
            width: 4px;
            height: 24px;
            background: <?= $tenant_config['theme_color'] ?? '#8c2bee' ?>;
            border-radius: 4px 0 0 4px;
        }

        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        .input-box {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            color: white;
            padding: 12px 16px;
            font-size: 13px;
            font-weight: 500;
            outline: none;
            transition: all 0.2s;
        }

        .input-box:focus {
            border-color: <?= $tenant_config['theme_color'] ?? '#8c2bee' ?>;
            background: rgba(255, 255, 255, 0.08);
        }

        .input-box::placeholder { color: #4b5563; }
        
        .badge-surface {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
    </style>
    <script>
        function updateHeaderClock() {
            const now = new Date();
            const clockEl = document.getElementById('headerClock');
            if (clockEl) {
                clockEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            }
        }
        setInterval(updateHeaderClock, 1000);
        window.addEventListener('DOMContentLoaded', updateHeaderClock);

        function clearTransactionFilters() {
            window.location.href = 'admin_transaction.php';
        }
    </script>
</head>
<body class="antialiased flex h-screen overflow-hidden">

<nav class="side-nav flex flex-col fixed left-0 top-0 h-screen bg-background-dark border-r border-white/5">
    <div class="px-7 py-8 mb-4 shrink-0">
        <div class="flex items-center gap-4">
            <div class="size-10 rounded-xl bg-primary flex items-center justify-center shadow-lg shrink-0 overflow-hidden">
                <?php if (!empty($tenant_config['logo_path'])):
                    $logo_src = (strpos($tenant_config['logo_path'], 'data:image') === 0) ? $tenant_config['logo_path'] : '../' . $tenant_config['logo_path'];
                ?>
                    <img src="<?= $logo_src ?>" class="size-full object-contain">
                <?php else: ?>
                    <span class="material-symbols-outlined text-white text-2xl">bolt</span>
                <?php endif; ?>
            </div>
            <h1 class="nav-label text-lg font-black italic uppercase tracking-tighter text-white">Staff Portal</h1>
        </div>
    </div>
    <div class="flex-1 overflow-y-auto no-scrollbar space-y-1">
        <div class="nav-section-label px-[38px] mb-2"><span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Overview</span></div>
        <a href="admin_dashboard.php" class="nav-item"><span class="material-symbols-outlined text-xl shrink-0">grid_view</span><span class="nav-label">Dashboard</span></a>
        <a href="register_member.php" class="nav-item"><span class="material-symbols-outlined text-xl shrink-0">person_add</span><span class="nav-label">Walk-in Member</span></a>
        <div class="nav-section-label px-[38px] mb-2 mt-6"><span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Management</span></div>
        <a href="admin_users.php" class="nav-item"><span class="material-symbols-outlined text-xl shrink-0">group</span><span class="nav-label">My Users</span></a>
        <a href="admin_transaction.php" class="nav-item active"><span class="material-symbols-outlined text-xl shrink-0">receipt_long</span><span class="nav-label">Transactions</span></a>
        <a href="admin_appointment.php" class="nav-item"><span class="material-symbols-outlined text-xl shrink-0">event_note</span><span class="nav-label">Bookings</span></a>
        <a href="admin_attendance.php" class="nav-item"><span class="material-symbols-outlined text-xl shrink-0">history</span><span class="nav-label">Attendance</span></a>
        <a href="admin_report.php" class="nav-item"><span class="material-symbols-outlined text-xl shrink-0">description</span><span class="nav-label">Reports</span></a>
    </div>
    <div class="mt-auto pt-4 border-t border-white/10 shrink-0 pb-6">
        <div class="nav-section-label px-[38px] mb-2"><span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Account</span></div>
        <a href="admin_profile.php" class="nav-item text-gray-400 hover:text-white"><span class="material-symbols-outlined text-xl shrink-0">account_circle</span><span class="nav-label">Profile</span></a>
        <a href="../logout.php" class="nav-item text-gray-400 hover:text-rose-500 transition-colors group">
            <span class="material-symbols-outlined text-xl shrink-0 group-hover:translate-x-1 transition-transform">logout</span>
            <span class="nav-label whitespace-nowrap">Sign Out</span>
        </a>
    </div>
</nav>

<div class="main-content flex-1 overflow-y-auto no-scrollbar">
    <main class="p-10 max-w-[1400px] mx-auto pb-20">
        <header class="mb-12 flex flex-row justify-between items-end gap-6">
            <div>
                <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white leading-none">Transaction <span class="text-primary">Ledger</span></h2>
                <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-2 px-1 opacity-60">Verified Billing Logs • System Registry</p>
            </div>
            <div class="flex items-end gap-8 text-right shrink-0">
                <div class="flex flex-col items-end">
                    <p id="headerClock" class="text-white font-black italic text-2xl leading-none tracking-tighter">00:00:00 AM</p>
                    <p class="text-primary text-[10px] font-black uppercase tracking-[0.2em] leading-none mt-2"><?= date('l, M d, Y') ?></p>
                </div>
            </div>
        </header>

        <!-- Dynamic Filter Matrix -->
        <div class="mb-10">
            <form method="GET" class="glass-card p-8 border border-white/5 relative overflow-hidden">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 items-end">
                    <div class="space-y-2 lg:col-span-1">
                        <p class="text-[10px] font-black uppercase tracking-widest text-primary ml-1">Identity Search</p>
                        <div class="relative">
                            <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-lg">search</span>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search member name..." class="input-box pl-12 w-full">
                        </div>
                    </div>

                    <div class="space-y-2">
                        <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 ml-1">Date Offset (From)</p>
                        <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" class="input-box w-full">
                    </div>

                    <div class="space-y-2">
                        <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 ml-1">Date Limit (To)</p>
                        <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" class="input-box w-full">
                    </div>

                    <div class="flex gap-3">
                        <button type="submit" class="flex-1 bg-primary hover:bg-primary/90 text-white h-[46px] rounded-xl text-[10px] font-black uppercase tracking-widest transition-all shadow-lg shadow-primary/20 active:scale-95">Apply Filter</button>
                        <button type="button" onclick="clearTransactionFilters()" class="size-[46px] rounded-xl bg-white/5 border border-white/5 flex items-center justify-center text-gray-400 hover:bg-rose-500/10 hover:text-rose-500 transition-all group active:scale-95">
                            <span class="material-symbols-outlined text-xl group-hover:rotate-180 transition-transform">restart_alt</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <div class="flex justify-between items-center mb-6">
            <p class="text-[10px] font-black uppercase tracking-widest text-gray-500 italic">SECURE GATEWAY FEED — <span class="text-white">ENCRYPTED LOGS</span></p>
            <div class="flex items-center gap-4">
                <span class="px-4 py-1.5 rounded-full bg-white/5 border border-white/5 text-[9px] font-black uppercase tracking-widest text-gray-400">Total Entries: <?= count($payments_list) ?></span>
            </div>
        </div>

        <div class="glass-card shadow-2xl overflow-hidden border border-white/5">
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="text-[10px] font-black uppercase tracking-[0.15em] text-gray-600 border-b border-white/5 bg-white/[0.01]">
                            <th class="px-8 py-5">SENSORY IDENTITY</th>
                            <th class="px-8 py-5 text-center">FINANCIAL AMOUNT</th>
                            <th class="px-8 py-5 text-center">CHANNEL TYPE</th>
                            <th class="px-8 py-5">TIMESTAMP</th>
                            <th class="px-8 py-5 text-right">PROTOCOL STATUS</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <?php if (empty($payments_list)): ?>
                        <tr>
                            <td colspan="5" class="px-8 py-24 text-center">
                                <span class="material-symbols-outlined text-4xl text-gray-700 mb-4 block">receipt_long</span>
                                <p class="text-[10px] font-black uppercase tracking-widest text-gray-500 italic">No transaction records detected in current matrix.</p>
                            </td>
                        </tr>
                        <?php else: foreach ($payments_list as $pay): ?>
                        <tr class="hover:bg-white/[0.02] group transition-colors">
                            <td class="px-8 py-6">
                                <div class="flex items-center gap-4">
                                    <div class="size-10 rounded-2xl bg-primary/10 border border-primary/20 flex items-center justify-center text-primary font-black italic text-base">
                                        <?= substr($pay['first_name'] ?? 'U', 0, 1) ?>
                                    </div>
                                    <div>
                                        <p class="text-[13px] font-black italic uppercase text-white group-hover:text-primary transition-colors"><?= htmlspecialchars(($pay['first_name'] ?? '') . ' ' . ($pay['last_name'] ?? '')) ?></p>
                                        <p class="text-[10px] font-bold text-gray-500 tracking-tight lowercase">@<?= htmlspecialchars($pay['username'] ?? 'unknown') ?></p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-8 py-6 text-center">
                                <p class="text-sm font-black italic text-white tracking-tight">₱<?= number_format($pay['amount'], 2) ?></p>
                            </td>
                            <td class="px-8 py-6 text-center">
                                <span class="px-4 py-1.5 rounded-xl badge-surface text-[9px] font-black uppercase tracking-[0.1em] text-gray-400 italic"><?= htmlspecialchars($pay['payment_type'] ?? 'OFFLINE') ?></span>
                            </td>
                            <td class="px-8 py-6">
                                <div class="space-y-0.5 text-left">
                                    <p class="text-[11px] font-black italic text-white uppercase"><?= date('h:i A', strtotime($pay['created_at'])) ?></p>
                                    <p class="text-[9px] font-bold text-gray-600 uppercase tracking-widest italic"><?= date('M d, Y', strtotime($pay['created_at'])) ?></p>
                                </div>
                            </td>
                            <td class="px-8 py-6 text-right">
                                <span class="px-4 py-1.5 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-[9px] text-emerald-500 font-extrabold uppercase italic tracking-widest"><?= htmlspecialchars($pay['payment_status'] ?? 'COMPLETED') ?></span>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>
</body>
</html>