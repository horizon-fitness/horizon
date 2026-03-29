<?php 
session_start();
require_once '../db.php';

// Security Check: Only Superadmin can access
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'superadmin') {
    header("Location: ../login.php");
    exit;
}

$page_title = "Recent Transactions";
$active_page = "transactions";

// 1. Get Filter Inputs
$tenant_filter = $_GET['tenant_id'] ?? 'all';

// Fetch Tenants for Filter Dropdown
$tenants_list = $pdo->query("SELECT gym_id, gym_name FROM gyms WHERE status = 'Active' ORDER BY gym_name ASC")->fetchAll();

// 2. Pagination Settings
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// 3. Logic for fetching transactions
$count_query = "SELECT COUNT(*) FROM payments p";
$query = "SELECT p.*, g.gym_name,
                 COALESCE(u_member.first_name, u_owner.first_name, 'System') as f_name,
                 COALESCE(u_member.last_name, u_owner.last_name, '') as l_name
          FROM payments p
          LEFT JOIN gyms g ON p.gym_id = g.gym_id
          LEFT JOIN members m ON p.member_id = m.member_id
          LEFT JOIN users u_member ON m.user_id = u_member.user_id
          LEFT JOIN client_subscriptions cs ON p.client_subscription_id = cs.client_subscription_id
          LEFT JOIN users u_owner ON cs.owner_user_id = u_owner.user_id";

$params = [];
if ($tenant_filter !== 'all') {
    $count_query .= " WHERE p.gym_id = :tid";
    $query .= " WHERE p.gym_id = :tid";
    $params[':tid'] = $tenant_filter;
}

// Get total records for pagination
$count_stmt = $pdo->prepare($count_query);
$count_stmt->execute($params);
$total_records = $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $limit);

// Finalize query with ordering and pagination
$query .= " ORDER BY p.created_at DESC LIMIT :limit OFFSET :offset";
$stmtTrx = $pdo->prepare($query);

// Bind filter parameter if active
if ($tenant_filter !== 'all') {
    $stmtTrx->bindValue(':tid', $params[':tid']);
}

// Bind pagination parameters as Integers (Crucial for SQL syntax)
$stmtTrx->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
$stmtTrx->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
$stmtTrx->execute();
$transactions = $stmtTrx->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title><?= $page_title ?> | Horizon System</title>
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
        
        /* Sidebar Hover Logic - ADJUSTED WIDTHS */
        .sidebar-nav {
            width: 110px; 
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .sidebar-nav:hover {
            width: 300px; 
        }

        /* Added: Scrollable container for links */
        .sidebar-scroll-container {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            padding-right: 4px;
        }

        /* Custom Scrollbar for the sidebar */
        .sidebar-scroll-container::-webkit-scrollbar {
            width: 4px;
        }
        .sidebar-scroll-container::-webkit-scrollbar-track {
            background: transparent;
        }
        .sidebar-scroll-container::-webkit-scrollbar-thumb {
            background: rgba(140, 43, 238, 0.1);
            border-radius: 10px;
        }
        .sidebar-nav:hover .sidebar-scroll-container::-webkit-scrollbar-thumb {
            background: rgba(140, 43, 238, 0.4);
        }

        .nav-text {
            opacity: 0;
            transform: translateX(-15px);
            transition: all 0.3s ease-in-out;
            white-space: nowrap;
            pointer-events: none;
        }
        .sidebar-nav:hover .nav-text {
            opacity: 1;
            transform: translateX(0);
            pointer-events: auto;
        }

        .nav-section-header {
            max-height: 0;
            opacity: 0;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            margin: 0 !important;
            pointer-events: none;
        }
        .sidebar-nav:hover .nav-section-header {
            max-height: 20px;
            opacity: 1;
            margin-bottom: 0px !important; 
            pointer-events: auto;
        }
        .sidebar-nav:hover .nav-section-header.mt-4 { margin-top: 0px !important; } 
        .sidebar-nav:hover .nav-section-header.mt-6 { margin-top: 0px !important; } 

        .sidebar-content {
            gap: 2px; 
            transition: all 0.3s ease-in-out;
        }
        .sidebar-nav:hover .sidebar-content {
            gap: 4px; 
        }

        .nav-link { font-size: 11px; font-weight: 800; letter-spacing: 0.05em; transition: all 0.2s; white-space: nowrap; }
        .active-nav { color: #8c2bee !important; position: relative; }
        .active-nav::after { 
            content: ''; 
            position: absolute; 
            right: 0px; 
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
<body class="antialiased flex flex-row min-h-screen">

<nav class="sidebar-nav bg-[#0a090d] border-r border-white/5 sticky top-0 h-screen px-7 py-8 z-50 shrink-0 flex flex-col">
    <div class="mb-4 shrink-0"> 
        <div class="flex items-center gap-4 mb-4"> 
            <div class="size-10 rounded-xl bg-[#7f13ec] flex items-center justify-center shadow-lg shrink-0">
                <span class="material-symbols-outlined text-white text-2xl">bolt</span>
            </div>
            <h1 class="nav-text text-xl font-black italic uppercase tracking-tighter text-white">Horizon System</h1>
        </div>
    </div>
    
    <div class="flex-1 overflow-y-auto no-scrollbar space-y-1 pr-2">
        <div class="nav-section-header px-0 mb-2">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Overview</span>
        </div>
        <a href="superadmin_dashboard.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'dashboard') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">grid_view</span> 
            <span class="nav-text">Dashboard</span>
        </a>
        
        <div class="nav-section-header px-0 mb-2 mt-4">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Management</span>
        </div>
        <a href="tenant_management.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'tenants') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">business</span> 
            <span class="nav-text">Tenant Management</span>
        </a>

        <a href="subscription_logs.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'subscriptions') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">history_edu</span> 
            <span class="nav-text">Subscription Logs</span>
        </a>

        <a href="real_time_occupancy.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'occupancy') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">group</span> 
            <span class="nav-text">Real-Time Occupancy</span>
        </a>

        <a href="recent_transaction.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'transactions') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">receipt_long</span> 
            <span class="nav-text">Recent Transactions</span>
        </a>

        <div class="nav-section-header px-0 mb-2 mt-4">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">System</span>
        </div>
        <a href="system_alerts.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'alerts') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">notifications_active</span> 
            <span class="nav-text">System Alerts</span>
        </a>

        <a href="system_reports.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'reports') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">analytics</span> 
            <span class="nav-text">Reports</span>
        </a>

        <a href="sales_report.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'sales_report') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">monitoring</span> 
            <span class="nav-text">Sales Reports</span>
        </a>

        <a href="audit_logs.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'audit_logs') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">assignment</span> 
            <span class="nav-text">Audit Logs</span>
        </a>

        <a href="backup.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'backup') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">backup</span> 
            <span class="nav-text">Backup</span>
        </a>
    </div>

    <div class="mt-auto pt-4 border-t border-white/10 flex flex-col gap-2 shrink-0">
        <div class="nav-section-header px-0 mb-0">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Account</span>
        </div>
        <a href="settings.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'settings') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">settings</span> 
            <span class="nav-text">Settings</span>
        </a>
        <a href="profile.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'profile') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">person</span> 
            <span class="nav-text">Profile</span>
        </a>
        <a href="../logout.php" class="text-gray-400 hover:text-rose-500 transition-colors flex items-center gap-4 group py-2">
            <span class="material-symbols-outlined group-hover:translate-x-1 transition-transform text-xl shrink-0">logout</span>
            <span class="nav-link nav-text">Sign Out</span>
        </a>
    </div>
</nav>

<div class="flex-1 flex flex-col min-w-0 overflow-y-auto">
    <main class="flex-1 p-6 md:p-10 max-w-[1400px] w-full mx-auto">

        <header class="mb-10 flex flex-row justify-between items-end gap-6">
            <div>
                <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white leading-none">Global <span class="text-primary">Transactions</span></h2>
                <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-2">Cross-tenant payment history</p>
            </div>
            <div class="text-right">
                <p id="headerClock" class="text-white font-black italic text-xl tracking-tight leading-none mb-2">00:00:00 AM</p>
                <p class="text-primary text-[9px] font-black uppercase tracking-[0.2em] opacity-80"><?= date('l, M d, Y') ?></p>
            </div>
        </header>

        <div class="glass-card mb-8 p-8">
            <form method="GET" class="flex flex-wrap items-end gap-6" id="trxFilterForm">
                <div class="space-y-2">
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 ml-1">Select Tenant</p>
                    <select name="tenant_id" onchange="this.form.submit()" class="bg-[#0a090d] border border-white/10 rounded-xl px-4 py-2.5 text-xs text-white focus:border-primary focus:outline-none transition-all min-w-[280px]">
                        <option value="all">All Tenants / System Logs</option>
                        <?php foreach($tenants_list as $t): ?>
                            <option value="<?= $t['gym_id'] ?>" <?= $tenant_filter == $t['gym_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($t['gym_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex items-center gap-3">
                    <button type="submit" class="h-10 px-6 rounded-xl bg-primary text-black text-[10px] font-black uppercase italic tracking-widest hover:scale-105 transition-all shadow-lg shadow-primary/20">Apply</button>
                    <a href="recent_transaction.php" class="text-[9px] font-black uppercase tracking-widest text-gray-600 hover:text-white transition-colors">Reset Filter</a>
                </div>
            </form>
        </div>

        <div class="glass-card overflow-hidden shadow-2xl">
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-background-dark/50 text-gray-500 text-[10px] font-black uppercase tracking-widest">
                            <th class="px-8 py-4">Transaction ID</th>
                            <th class="px-8 py-4">Member / Branch</th>
                            <th class="px-8 py-4">Category</th>
                            <th class="px-8 py-4">Amount</th>
                            <th class="px-8 py-4">Timestamp</th>
                            <th class="px-8 py-4 text-right">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <?php if (empty($transactions)): ?>
                            <tr><td colspan="6" class="px-8 py-12 text-center text-xs font-bold text-gray-500 italic uppercase">No transaction records found.</td></tr>
                        <?php else: ?>
                            <?php foreach($transactions as $trx): ?>
                            <tr class="hover:bg-white/[0.02] transition-all">
                                <td class="px-8 py-5">
                                    <span class="text-[10px] font-black italic text-primary uppercase">TRX-<?= htmlspecialchars($trx['reference_number'] ?: $trx['payment_id']) ?></span>
                                </td>
                                <td class="px-8 py-5">
                                    <div>
                                        <p class="text-sm font-bold text-white"><?= htmlspecialchars($trx['f_name'] . ' ' . $trx['l_name']) ?></p>
                                        <p class="text-[9px] text-gray-500 font-black uppercase tracking-widest italic"><?= $trx['gym_name'] ? htmlspecialchars($trx['gym_name']) : 'System Managed' ?></p>
                                    </div>
                                </td>
                                <td class="px-8 py-5">
                                    <span class="text-[9px] font-black uppercase tracking-tighter text-gray-400"><?= htmlspecialchars($trx['payment_type']) ?></span>
                                </td>
                                <td class="px-8 py-5">
                                    <p class="text-sm font-black italic text-white">₱<?= number_format($trx['amount'], 2) ?></p>
                                </td>
                                <td class="px-8 py-5 text-[10px] font-bold text-gray-500">
                                    <?= date('M d, Y', strtotime($trx['created_at'])) ?>
                                    <p class="text-[8px] opacity-50 uppercase"><?= date('h:i A', strtotime($trx['created_at'])) ?></p>
                                </td>
                                <td class="px-8 py-5 text-right">
                                    <?php 
                                        $status = strtolower($trx['payment_status']);
                                        $is_success = ($status == 'paid' || $status == 'success');
                                    ?>
                                    <span class="px-3 py-1 rounded-full <?= $is_success ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-500' : 'bg-red-500/10 border-red-500/20 text-red-500' ?> border text-[9px] font-black uppercase italic">
                                        <?= htmlspecialchars($trx['payment_status']) ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
            <div class="px-8 py-6 border-t border-white/5 bg-white/[0.01] flex items-center justify-between">
                <p class="text-[10px] font-bold text-gray-500 uppercase tracking-widest">
                    Showing <span class="text-white"><?= $offset + 1 ?></span> to <span class="text-white"><?= min($offset + $limit, $total_records) ?></span> of <span class="text-white"><?= $total_records ?></span>
                </p>
                <div class="flex items-center gap-1">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?tenant_id=<?= $tenant_filter ?>&page=<?= $i ?>" 
                           class="size-8 rounded-lg flex items-center justify-center text-[10px] font-black transition-all <?= ($i == $page) ? 'bg-primary text-black' : 'bg-white/5 text-gray-400 hover:text-white hover:bg-white/10' ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>
</body>
</html>