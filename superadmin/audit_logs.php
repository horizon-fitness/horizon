<?php 
session_start();
require_once '../db.php';

// Security Check: Only Superadmin can access
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'superadmin') {
    header("Location: ../login.php");
    exit;
}

$page_title = "Audit Logs";
$active_page = "audit_logs"; 

// Get Filter Inputs
$search = $_GET['search'] ?? '';
$action_filter = $_GET['action_type'] ?? 'all';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$tenant_filter = $_GET['tenant_id'] ?? 'all';

// Fetch Tenants for Filter Dropdown
$tenants_list = $pdo->query("SELECT gym_id, gym_name FROM gyms WHERE status = 'Active' ORDER BY gym_name ASC")->fetchAll();

// Logic for fetching logs
$query = "SELECT al.*, u.first_name, u.last_name, r.role_name as role, g.gym_name
          FROM audit_logs al 
          JOIN users u ON al.user_id = u.user_id 
          LEFT JOIN user_roles ur ON u.user_id = ur.user_id AND ur.role_status = 'Active'
          LEFT JOIN roles r ON ur.role_id = r.role_id
          LEFT JOIN gyms g ON al.gym_id = g.gym_id
          WHERE al.created_at BETWEEN :start AND :end";

$params = [
    'start' => $date_from . ' 00:00:00',
    'end' => $date_to . ' 23:59:59'
];

if ($action_filter !== 'all') {
    $query .= " AND al.action_type = :type";
    $params['type'] = $action_filter;
}

if ($tenant_filter !== 'all') {
    $query .= " AND al.gym_id = :tid";
    $params['tid'] = $tenant_filter;
}

if (!empty($search)) {
    $query .= " AND (al.table_name LIKE :s1 OR al.action_type LIKE :s2 OR u.first_name LIKE :s3 OR u.last_name LIKE :s4)";
    $params['s1'] = "%$search%";
    $params['s2'] = "%$search%";
    $params['s3'] = "%$search%";
    $params['s4'] = "%$search%";
}

$query .= " ORDER BY al.created_at DESC LIMIT 100";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title><?= $page_title ?> | Horizon System</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "#8c2bee", "background-dark": "#0a090d", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)"}}}
        }
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        
        /* Sidebar Hover Logic */
        .sidebar-nav {
            width: 110px;
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
        }
        .sidebar-nav:hover {
            width: 300px;
        }
        .nav-text {
            opacity: 0;
            visibility: hidden;
            transform: translateX(-15px);
            transition: all 0.3s ease-in-out;
            white-space: nowrap;
            pointer-events: none;
        }
        .sidebar-nav:hover .nav-text {
            opacity: 1;
            visibility: visible;
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
            margin-bottom: 0.5rem !important;
            pointer-events: auto;
        }
        /* Override for Overview which is the first section */
        .sidebar-nav:hover .nav-section-header.mt-4 { margin-top: 0.75rem !important; }
        .sidebar-nav:hover .nav-section-header.mt-6 { margin-top: 1.25rem !important; }

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
        // Reactive Filtering
        let filterTimeout;
        function reactiveFilter() {
            clearTimeout(filterTimeout);
            filterTimeout = setTimeout(() => {
                const form = document.getElementById('auditFilterForm');
                const formData = new FormData(form);
                const params = new URLSearchParams(formData);
                
                // Fetch the updated table content
                fetch(`audit_logs.php?${params.toString()}&ajax=1`)
                    .then(response => response.text())
                    .then(html => {
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(html, 'text/html');
                        const newTable = doc.getElementById('auditTableContainer');
                        if (newTable) {
                            document.getElementById('auditTableContainer').innerHTML = newTable.innerHTML;
                        }
                    });
            }, 300); // 300ms debounce
        }

        setInterval(updateHeaderClock, 1000);
        window.addEventListener('DOMContentLoaded', updateHeaderClock);


        function exportAuditTrail(preview = false) {
            const element = document.getElementById('auditTableContainer');
            const reportTitle = "System Audit Trail Transcript";
            const generatedAt = "<?= date('M d, Y h:i A') ?>";

            const wrapper = document.createElement('div');
            wrapper.style.padding = '50px';
            wrapper.style.color = '#000';
            wrapper.style.backgroundColor = '#fff';
            wrapper.style.fontFamily = "'Roboto Mono', monospace";

            const header = `
                <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 20px; border-bottom: 2px solid #000; padding-bottom: 15px;">
                    <div style="text-align: left;">
                        <h1 style="font-size: 24px; font-weight: 800; color: #000; margin: 0; text-transform: uppercase;">HORIZON SYSTEM</h1>
                        <p style="margin: 0; font-size: 9px; font-weight: bold; color: #666;">OFFICIAL AUDIT REPORT</p>
                    </div>
                    <div style="text-align: right;">
                        <h2 style="font-size: 14px; font-weight: 700; color: #000; margin: 0; text-transform: uppercase;">${reportTitle}</h2>
                        <p style="margin: 0; font-size: 9px; color: #666;">Date: ${generatedAt}</p>
                    </div>
                </div>
            `;

            const contentClone = element.cloneNode(true);
            contentClone.querySelectorAll('button, .material-symbols-outlined, .flex-wrap').forEach(el => el.remove());

            [contentClone, ...contentClone.querySelectorAll('*')].forEach(el => {
                el.removeAttribute('class');
                el.style.setProperty('color', '#000000', 'important');
                el.style.setProperty('background-color', 'transparent', 'important');
                el.style.setProperty('border', 'none', 'important');
                el.style.setProperty('box-shadow', 'none', 'important');
            });

            const table = contentClone.querySelector('table');
            if (table) {
                table.style.setProperty('width', '100%', 'important');
                table.style.setProperty('border-collapse', 'collapse', 'important');
                table.style.setProperty('font-size', '11px', 'important'); // Larger font for portrait
                table.style.setProperty('border', '1px solid #000', 'important');

                table.querySelectorAll('th').forEach(th => {
                    th.style.setProperty('background-color', '#f3f4f6', 'important');
                    th.style.setProperty('border', '1px solid #000', 'important');
                    th.style.setProperty('padding', '12px 10px', 'important');
                    th.style.setProperty('text-align', 'left', 'important');
                    th.style.setProperty('text-transform', 'uppercase', 'important');
                    th.style.setProperty('font-weight', 'bold', 'important');
                });

                table.querySelectorAll('td').forEach(td => {
                    td.style.setProperty('border', '1px solid #000', 'important');
                    td.style.setProperty('padding', '10px 10px', 'important');
                    td.style.setProperty('word-break', 'break-word', 'important');
                    td.querySelectorAll('*').forEach(child => {
                        child.style.setProperty('font-size', '11px', 'important');
                    });
                });
            }

            wrapper.innerHTML = header;
            wrapper.appendChild(contentClone);

            const opt = {
                margin: [0.5, 0.5],
                filename: `Audit_Trail_${new Date().toISOString().split('T')[0]}.pdf`,
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 2, backgroundColor: '#ffffff' },
                jsPDF: { unit: 'in', format: 'letter', orientation: 'portrait' }
            };

            if (preview) {
                html2pdf().set(opt).from(wrapper).toPdf().get('pdf').then(function (pdf) {
                    window.open(pdf.output('bloburl'), '_blank');
                });
            } else {
                html2pdf().from(wrapper).set(opt).save();
            }
        }
    </script>
</head>
<body class="antialiased flex flex-row min-h-screen">

<nav class="sidebar-nav flex flex-col bg-[#0a090d] border-r border-white/5 sticky top-0 h-screen px-7 py-8 z-50 shrink-0">
    <div class="mb-8">
        <div class="flex items-center gap-4 mb-6">
            <div class="size-10 rounded-xl bg-[#7f13ec] flex items-center justify-center shadow-lg shrink-0">
                <span class="material-symbols-outlined text-white text-2xl">bolt</span>
            </div>
            <h1 class="nav-text text-xl font-black italic uppercase tracking-tighter text-white">Horizon System</h1>
        </div>
    </div>
    <div class="sidebar-content flex-1 overflow-y-auto no-scrollbar pr-2 pb-10 flex flex-col">
        <!-- Overview Section -->
        <div class="nav-section-header px-0 mb-2 mt-4">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Overview</span>
        </div>
        <a href="superadmin_dashboard.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'dashboard') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">grid_view</span> 
            <span class="nav-link nav-text">Dashboard</span>
        </a>
        
        <!-- Management Section -->
        <div class="nav-section-header px-0 mb-2 mt-6">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Management</span>
        </div>
        <a href="tenant_management.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'tenants') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">business</span> 
            <span class="nav-link nav-text">Tenant Management</span>
        </a>

        <a href="subscription_logs.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'subscriptions') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">history_edu</span> 
            <span class="nav-link nav-text">Subscription Logs</span>
        </a>

        <a href="rbac_management.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'rbac') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">security</span> 
            <span class="nav-link nav-text">Access Control</span>
        </a>

        <a href="real_time_occupancy.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'occupancy') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">group</span> 
            <span class="nav-link nav-text">Real-Time Occupancy</span>
        </a>

        <a href="recent_transaction.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'transactions') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">receipt_long</span> 
            <span class="nav-link nav-text">Recent Transactions</span>
        </a>

        <!-- System Section -->
        <div class="nav-section-header px-0 mb-2 mt-6">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">System</span>
        </div>
        <a href="system_alerts.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'alerts') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">notifications_active</span> 
            <span class="nav-link nav-text">System Alerts</span>
        </a>

        <a href="system_reports.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'reports') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">analytics</span> 
            <span class="nav-link nav-text">Reports</span>
        </a>

        <a href="sales_report.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'sales_report') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">monitoring</span> 
            <span class="nav-link nav-text">Sales Reports</span>
        </a>

        <a href="audit_logs.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'audit_logs') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">assignment</span> 
            <span class="nav-link nav-text">Audit Logs</span>
        </a>

        <a href="backup.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'backup') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">backup</span> 
            <span class="nav-link nav-text">Backup</span>
        </a>
    </div>

    <div class="mt-auto pt-10 border-t border-white/10 flex flex-col gap-4">
        <div class="nav-section-header px-0 mb-2">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Account</span>
        </div>
        <a href="settings.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'settings') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">settings</span> 
            <span class="nav-link nav-text">Settings</span>
        </a>
        <a href="profile.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'profile') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">person</span> 
            <span class="nav-text">Profile</span>
        </a>
        <a href="../logout.php" class="text-gray-400 hover:text-rose-500 transition-colors flex items-center gap-4 group">
            <span class="material-symbols-outlined group-hover:translate-x-1 transition-transform text-xl shrink-0">logout</span>
            <span class="nav-link nav-text">Sign Out</span>
        </a>
    </div>
</nav>

<div class="flex-1 flex flex-col min-w-0 overflow-y-auto">
    <main class="flex-1 p-6 md:p-10 max-w-[1400px] w-full mx-auto">
        <header class="mb-10 flex flex-row justify-between items-end gap-6">
            <div>
                <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white leading-none">Audit <span class="text-primary">Logs</span></h2>
                <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-2">Administrative & Security Monitoring</p>
            </div>
            <div class="text-right">
                <p id="headerClock" class="text-white font-black italic text-xl tracking-tight leading-none mb-2">00:00:00 AM</p>
                <p class="text-primary text-[9px] font-black uppercase tracking-[0.2em] opacity-80"><?= date('l, M d, Y') ?></p>
            </div>
        </header>

        <div class="glass-card mb-8 p-8">
            <form method="GET" class="flex flex-wrap items-end gap-6" id="auditFilterForm">
                <div class="space-y-2">
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 ml-1">Search Term</p>
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 text-sm">search</span>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Filter logs..." 
                               oninput="reactiveFilter()"
                               class="bg-[#0a090d] border border-white/10 rounded-xl pl-10 pr-4 py-2 text-xs text-white focus:border-primary focus:outline-none w-48 transition-all">
                    </div>
                </div>
                <div class="space-y-2">
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 ml-1">Action Type</p>
                    <select name="action_type" onchange="reactiveFilter()" class="bg-[#0a090d] border border-white/10 rounded-xl px-4 py-2 text-xs text-white focus:border-primary focus:outline-none transition-all w-40">
                        <option value="all">All Activities</option>
                        <option value="Login" <?= $action_filter == 'Login' ? 'selected' : '' ?>>Login/Logout</option>
                        <option value="Create" <?= $action_filter == 'Create' ? 'selected' : '' ?>>Create Actions</option>
                        <option value="Update" <?= $action_filter == 'Update' ? 'selected' : '' ?>>Update Actions</option>
                        <option value="Delete" <?= $action_filter == 'Delete' ? 'selected' : '' ?>>Delete Actions</option>
                        <option value="Tenant" <?= $action_filter == 'Tenant' ? 'selected' : '' ?>>Tenant Changes</option>
                    </select>
                </div>
                <div class="space-y-2">
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 ml-1">Tenant</p>
                    <select name="tenant_id" onchange="reactiveFilter()" class="bg-[#0a090d] border border-white/10 rounded-xl px-4 py-2 text-xs text-white focus:border-primary focus:outline-none transition-all w-40">
                        <option value="all">All Tenants</option>
                        <?php foreach($tenants_list as $t): ?>
                            <option value="<?= $t['gym_id'] ?>" <?= $tenant_filter == $t['gym_id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['gym_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="space-y-2">
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 ml-1">From</p>
                    <input type="date" name="date_from" value="<?= $date_from ?>" onchange="reactiveFilter()" class="bg-[#0a090d] border border-white/10 rounded-xl px-4 py-2 text-xs text-white focus:border-primary focus:outline-none">
                </div>
                <div class="space-y-2">
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 ml-1">To</p>
                    <input type="date" name="date_to" value="<?= $date_to ?>" onchange="reactiveFilter()" class="bg-[#0a090d] border border-white/10 rounded-xl px-4 py-2 text-xs text-white focus:border-primary focus:outline-none">
                </div>
                <div class="flex items-center gap-3">
                    <button type="submit" class="h-10 px-6 rounded-xl bg-primary text-black text-[10px] font-black uppercase italic tracking-widest hover:scale-105 transition-all active:scale-95 shadow-lg shadow-primary/20">Apply</button>
                    <a href="audit_logs.php" class="text-[9px] font-black uppercase tracking-widest text-gray-600 hover:text-white transition-colors">Reset Filters</a>
                </div>
            </form>
        </div>

        <div class="glass-card overflow-hidden">
            <div class="px-8 py-6 border-b border-white/5 bg-white/[0.01] flex flex-wrap justify-between items-center gap-4">
                <div>
                    <h3 class="text-sm font-black italic uppercase tracking-widest text-white">System Audit Trail</h3>
                    <p class="text-[9px] text-gray-500 font-bold uppercase tracking-widest mt-1">Tracking all administrative and security events</p>
                </div>
                <div class="flex items-center gap-3">
                    <button type="button" onclick="exportAuditTrail(true)" class="h-10 px-6 rounded-xl bg-white/5 hover:bg-white/10 border border-white/5 text-[9px] font-black uppercase tracking-widest text-gray-400 hover:text-white transition-all flex items-center gap-2">
                        <span class="material-symbols-outlined text-sm">visibility</span> Preview
                    </button>
                    <button type="button" onclick="exportAuditTrail(false)" class="h-10 px-6 rounded-xl bg-primary text-black text-[10px] font-black uppercase tracking-widest hover:scale-105 transition-all active:scale-95 shadow-lg shadow-primary/20 flex items-center gap-2">
                        <span class="material-symbols-outlined text-sm">picture_as_pdf</span> Export PDF
                    </button>
                </div>
            </div>
            <div class="overflow-x-auto" id="auditTableContainer">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-background-dark/50 text-gray-500 text-[10px] font-black uppercase tracking-widest">
                            <th class="px-8 py-4 text-left">Timestamp</th>
                            <th class="px-8 py-4 text-left">User</th>
                            <th class="px-8 py-4 text-left">Role</th>
                            <th class="px-8 py-4 text-left">Event Action</th>
                            <th class="px-8 py-4 text-right">Record ID</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <?php if (empty($logs)): ?>
                            <tr><td colspan="5" class="px-8 py-12 text-center text-xs text-gray-600 italic uppercase font-bold tracking-widest">No audit records found</td></tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                            <tr class="hover:bg-white/[0.01] transition-colors">
                                <td class="px-8 py-5">
                                    <p class="text-[11px] text-white font-medium uppercase leading-none mb-1"><?= date('M d, Y', strtotime($log['created_at'])) ?></p>
                                    <p class="text-[9px] text-gray-500 font-black italic tracking-tighter"><?= date('h:i:s A', strtotime($log['created_at'])) ?></p>
                                </td>
                                <td class="px-8 py-5">
                                    <div class="flex items-center gap-3">
                                        <div class="size-7 rounded-full bg-primary/10 flex items-center justify-center border border-primary/20 shrink-0">
                                            <span class="material-symbols-outlined text-primary text-[14px]">person</span>
                                        </div>
                                        <p class="text-xs font-bold text-white"><?= htmlspecialchars($log['first_name'] . ' ' . $log['last_name']) ?></p>
                                    </div>
                                </td>
                                <td class="px-8 py-5">
                                    <span class="px-2.5 py-1 rounded-lg bg-primary/10 border border-primary/20 text-[9px] text-primary font-black uppercase tracking-widest">
                                        <?= htmlspecialchars($log['role'] ?? 'User') ?>
                                    </span>
                                </td>
                                <td class="px-8 py-5">
                                    <?php 
                                        $color = 'gray-500';
                                        if(in_array($log['action_type'], ['Create', 'Login'])) $color = 'emerald-500';
                                        if($log['action_type'] === 'Delete') $color = 'rose-500';
                                        if($log['action_type'] === 'Update') $color = 'amber-500';
                                    ?>
                                    <span class="px-3 py-1 rounded-md bg-<?= $color ?>/10 border border-<?= $color ?>/20 text-[9px] text-<?= $color ?> font-black uppercase italic">
                                        <?= htmlspecialchars($log['action_type']) ?>
                                    </span>
                                </td>
                                <td class="px-8 py-5 text-right">
                                    <p class="text-[10px] font-black text-gray-600 tracking-widest">#<?= htmlspecialchars($log['record_id']) ?></p>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

</body>
</html>