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
    $query .= " AND (al.table_name LIKE :search OR al.action_type LIKE :search OR u.first_name LIKE :search OR u.last_name LIKE :search)";
    $params['search'] = "%$search%";
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
        setInterval(updateHeaderClock, 1000);
        window.addEventListener('DOMContentLoaded', updateHeaderClock);

        function showLogDetails(oldVal, newVal) {
            const modal = document.getElementById('detailsModal');
            const oldContent = document.getElementById('oldValuesContent');
            const newContent = document.getElementById('newValuesContent');
            
            try {
                oldContent.textContent = JSON.stringify(JSON.parse(oldVal), null, 2);
                newContent.textContent = JSON.stringify(JSON.parse(newVal), null, 2);
            } catch(e) {
                oldContent.textContent = oldVal;
                newContent.textContent = newVal;
            }
            
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeDetailsModal() {
            const modal = document.getElementById('detailsModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        function exportAuditTrail() {
            const element = document.getElementById('auditTableContainer');
            const reportTitle = "System Audit Trail Transcript";
            const generatedAt = "<?= date('M d, Y h:i A') ?>";

            const wrapper = document.createElement('div');
            wrapper.style.padding = '50px';
            wrapper.style.color = '#000';
            wrapper.style.backgroundColor = '#fff';
            wrapper.style.fontFamily = "'Roboto Mono', monospace";

            const header = `
                <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 40px; border-bottom: 2px solid #000; padding-bottom: 20px;">
                    <div style="text-align: left;">
                        <h1 style="font-size: 28px; font-weight: 800; color: #000; margin: 0; text-transform: uppercase;">HORIZON SYSTEM</h1>
                        <p style="margin: 0; font-size: 10px; font-weight: bold; color: #666;">OFFICIAL AUDIT REPORT</p>
                    </div>
                    <div style="text-align: right;">
                        <h2 style="font-size: 16px; font-weight: 700; color: #000; margin: 0; text-transform: uppercase;">${reportTitle}</h2>
                        <p style="margin: 0; font-size: 10px; color: #666;">Date: ${generatedAt}</p>
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
                table.style.setProperty('font-size', '9px', 'important');
                table.style.setProperty('border', '1px solid #000', 'important');

                table.querySelectorAll('th').forEach(th => {
                    th.style.setProperty('background-color', '#f0f0f0', 'important');
                    th.style.setProperty('border', '1px solid #000', 'important');
                    th.style.setProperty('padding', '8px', 'important');
                    th.style.setProperty('text-align', 'left', 'important');
                });

                table.querySelectorAll('td').forEach(td => {
                    td.style.setProperty('border', '1px solid #000', 'important');
                    td.style.setProperty('padding', '8px', 'important');
                });
            }

            wrapper.innerHTML = header;
            wrapper.appendChild(contentClone);

            const opt = {
                margin: [0.5, 0.5],
                filename: `Audit_Trail_${new Date().toISOString().split('T')[0]}.pdf`,
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 2, backgroundColor: '#ffffff' },
                jsPDF: { unit: 'in', format: 'letter', orientation: 'landscape' }
            };

            html2pdf().from(wrapper).set(opt).save();
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
            <span class="nav-text">Dashboard</span>
        </a>
        
        <!-- Management Section -->
        <div class="nav-section-header px-0 mb-2 mt-6">
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

        <a href="rbac_management.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'rbac') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">security</span> 
            <span class="nav-text">Access Control</span>
        </a>

        <a href="real_time_occupancy.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'occupancy') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">group</span> 
            <span class="nav-text">Real-Time Occupancy</span>
        </a>

        <a href="recent_transaction.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'transactions') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">receipt_long</span> 
            <span class="nav-text">Recent Transactions</span>
        </a>

        <!-- System Section -->
        <div class="nav-section-header px-0 mb-2 mt-6">
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

    <div class="mt-auto pt-10 border-t border-white/10 flex flex-col gap-4">
        <div class="nav-section-header px-0 mb-2">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Account</span>
        </div>
        <a href="settings.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'settings') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">settings</span> 
            <span class="nav-text">Settings</span>
        </a>
        <a href="#" class="text-gray-400 hover:text-white transition-colors flex items-center gap-4 group">
            <span class="material-symbols-outlined transition-transform group-hover:text-primary text-xl shrink-0">person</span>
            <span class="nav-link nav-text">Profile</span>
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
            <form method="GET" class="flex flex-wrap items-end gap-6">
                <div class="space-y-2">
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 ml-1">Search Term</p>
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 text-sm">search</span>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Filter logs..." 
                               class="bg-[#0a090d] border border-white/10 rounded-xl pl-10 pr-4 py-2 text-xs text-white focus:border-primary focus:outline-none w-48 transition-all">
                    </div>
                </div>
                <div class="space-y-2">
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 ml-1">Action Type</p>
                    <select name="action_type" class="bg-[#0a090d] border border-white/10 rounded-xl px-4 py-2 text-xs text-white focus:border-primary focus:outline-none transition-all w-40">
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
                    <select name="tenant_id" class="bg-[#0a090d] border border-white/10 rounded-xl px-4 py-2 text-xs text-white focus:border-primary focus:outline-none transition-all w-40">
                        <option value="all">All Tenants</option>
                        <?php foreach($tenants_list as $t): ?>
                            <option value="<?= $t['gym_id'] ?>" <?= $tenant_filter == $t['gym_id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['gym_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="space-y-2">
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 ml-1">From</p>
                    <input type="date" name="date_from" value="<?= $date_from ?>" class="bg-[#0a090d] border border-white/10 rounded-xl px-4 py-2 text-xs text-white focus:border-primary focus:outline-none">
                </div>
                <div class="space-y-2">
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 ml-1">To</p>
                    <input type="date" name="date_to" value="<?= $date_to ?>" class="bg-[#0a090d] border border-white/10 rounded-xl px-4 py-2 text-xs text-white focus:border-primary focus:outline-none">
                </div>
                <div class="flex items-center gap-3">
                    <button type="submit" class="h-10 px-6 rounded-xl bg-primary text-black text-[10px] font-black uppercase italic tracking-widest hover:scale-105 transition-all active:scale-95 shadow-lg shadow-primary/20">Apply</button>
                    <button type="button" onclick="exportAuditTrail()" class="h-10 px-6 rounded-xl bg-white/5 hover:bg-white/10 border border-white/5 text-[9px] font-black uppercase tracking-widest text-gray-400 hover:text-white transition-all flex items-center gap-2">
                        <span class="material-symbols-outlined text-sm">download</span> Export
                    </button>
                    <a href="audit_logs.php" class="text-[9px] font-black uppercase tracking-widest text-gray-600 hover:text-white transition-colors">Reset</a>
                </div>
            </form>
        </div>

        <div class="glass-card overflow-hidden">
            <div class="px-8 py-6 border-b border-white/5 bg-white/[0.01]">
                <h3 class="text-sm font-black italic uppercase tracking-widest text-white">System Audit Trail</h3>
                <p class="text-[9px] text-gray-500 font-bold uppercase tracking-widest mt-1">Tracking all administrative and security events</p>
            </div>
            <div class="overflow-x-auto" id="auditTableContainer">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-background-dark/50 text-gray-500 text-[10px] font-black uppercase tracking-widest">
                            <th class="px-8 py-4">Timestamp</th>
                            <th class="px-8 py-4">User / Role</th>
                            <th class="px-8 py-4">Event Action</th>
                            <th class="px-8 py-4">Target Table</th>
                            <th class="px-8 py-4">Record ID</th>
                            <th class="px-8 py-4 text-right">Details</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <?php if (empty($logs)): ?>
                            <tr><td colspan="6" class="px-8 py-12 text-center text-xs text-gray-600 italic uppercase font-bold tracking-widest">No audit records found</td></tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                            <tr class="hover:bg-white/[0.01] transition-colors">
                                <td class="px-8 py-5">
                                    <p class="text-xs font-bold text-white uppercase leading-none mb-1"><?= date('M d, Y', strtotime($log['created_at'])) ?></p>
                                    <p class="text-[9px] text-gray-500 font-black italic"><?= date('h:i:s A', strtotime($log['created_at'])) ?></p>
                                </td>
                                <td class="px-8 py-5">
                                    <p class="text-sm font-bold text-white leading-none mb-1"><?= htmlspecialchars($log['first_name'] . ' ' . $log['last_name']) ?></p>
                                    <p class="text-[9px] text-primary font-black uppercase tracking-tighter italic"><?= htmlspecialchars($log['role'] ?? 'User') ?></p>
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
                                <td class="px-8 py-5">
                                    <p class="text-xs text-gray-400 font-bold italic uppercase tracking-widest"><?= htmlspecialchars($log['table_name']) ?></p>
                                </td>
                                <td class="px-8 py-5">
                                    <p class="text-[10px] font-black text-gray-600 tracking-widest">#<?= htmlspecialchars($log['record_id']) ?></p>
                                </td>
                                <td class="px-8 py-5 text-right">
                                    <button onclick='showLogDetails(<?= json_encode($log['old_values']) ?>, <?= json_encode($log['new_values']) ?>)' 
                                            class="p-2 rounded-lg bg-white/5 hover:bg-primary/20 text-gray-500 hover:text-primary transition-all">
                                        <span class="material-symbols-outlined text-sm">visibility</span>
                                    </button>
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

<!-- Details Modal -->
<div id="detailsModal" class="fixed inset-0 z-[100] hidden items-center justify-center bg-black/80 backdrop-blur-sm p-4">
    <div class="glass-card w-full max-w-4xl max-h-[90vh] flex flex-col overflow-hidden shadow-2xl border-white/10">
        <div class="px-8 py-6 border-b border-white/5 bg-white/[0.02] flex justify-between items-center">
            <div>
                <h3 class="text-lg font-black italic uppercase tracking-widest text-white">Event Details</h3>
                <p class="text-[10px] text-gray-500 font-bold uppercase tracking-widest mt-1">Comparing initial and final states</p>
            </div>
            <button onclick="closeDetailsModal()" class="size-10 rounded-xl bg-white/5 hover:bg-rose-500/20 hover:text-rose-500 flex items-center justify-center transition-all">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="flex-1 overflow-y-auto p-8 grid grid-cols-1 md:grid-cols-2 gap-8">
            <div class="space-y-4">
                <div class="flex items-center gap-2 px-4 py-2 rounded-lg bg-amber-500/10 border border-amber-500/20">
                    <span class="material-symbols-outlined text-amber-500 text-sm">history</span>
                    <span class="text-[10px] font-black uppercase text-amber-500 tracking-widest">Previous State</span>
                </div>
                <pre id="oldValuesContent" class="bg-black/40 rounded-2xl p-6 text-[11px] font-mono text-gray-400 overflow-x-auto border border-white/5 min-h-[200px]"></pre>
            </div>
            <div class="space-y-4">
                <div class="flex items-center gap-2 px-4 py-2 rounded-lg bg-emerald-500/10 border border-emerald-500/20">
                    <span class="material-symbols-outlined text-emerald-500 text-sm">verified</span>
                    <span class="text-[10px] font-black uppercase text-emerald-500 tracking-widest">Modified State</span>
                </div>
                <pre id="newValuesContent" class="bg-black/40 rounded-2xl p-6 text-[11px] font-mono text-emerald-400/80 overflow-x-auto border border-emerald-500/10 min-h-[200px]"></pre>
            </div>
        </div>
        <div class="px-8 py-6 border-t border-white/5 bg-white/[0.02] text-right">
            <button onclick="closeDetailsModal()" class="px-8 py-3 rounded-xl bg-white/5 border border-white/10 text-[10px] font-black uppercase tracking-widest text-white hover:bg-white/10 transition-all">Close Review</button>
        </div>
    </div>
</div>
</body>
</html>