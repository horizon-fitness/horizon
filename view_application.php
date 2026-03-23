<?php 
session_start();
require_once '../db.php';

// Security Check
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'superadmin') {
    header("Location: ../login.php");
    exit;
}

// Initialize RBAC Permissions Table if it doesn't exist
$pdo->exec("
    CREATE TABLE IF NOT EXISTS rbac_permissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        role_id INT NOT NULL,
        module_name VARCHAR(100) NOT NULL,
        permission_key VARCHAR(50) NOT NULL,
        is_allowed TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY (role_id, module_name, permission_key)
    )
");

// Fetch all settings
$stmtConfigs = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
$configs = $stmtConfigs->fetchAll(PDO::FETCH_KEY_PAIR);

// Fetch all Roles from the database
$stmtRoles = $pdo->query("SELECT role_id, role_name FROM roles ORDER BY role_id ASC");
$roles = $stmtRoles->fetchAll();

// Get Selected Role (Default to Superadmin - ID 1)
$selected_role_id = isset($_GET['role_id']) ? (int)$_GET['role_id'] : 1;
if ($selected_role_id === 0 && !empty($roles)) {
    $selected_role_id = $roles[0]['role_id'];
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_permissions'])) {
    $role_id = (int)$_POST['role_id'];
    
    // Start Transaction
    $pdo->beginTransaction();
    try {
        // Clear existing permissions for this role to sync correctly
        $stmtDelete = $pdo->prepare("DELETE FROM rbac_permissions WHERE role_id = ?");
        $stmtDelete->execute([$role_id]);
        
        $stmtInsert = $pdo->prepare("INSERT INTO rbac_permissions (role_id, module_name, permission_key, is_allowed) VALUES (?, ?, ?, 1)");
        
        if (isset($_POST['permissions']) && is_array($_POST['permissions'])) {
            foreach ($_POST['permissions'] as $module => $actions) {
                foreach ($actions as $action => $value) {
                    $stmtInsert->execute([$role_id, $module, $action]);
                }
            }
        }
        
        $pdo->commit();
        $success_msg = "Permissions updated successfully for selected role!";
    } catch (Exception $e) {
        $pdo->rollBack();
        $error_msg = "Error updating permissions: " . $e->getMessage();
    }
}

// Fetch existing permissions for the selected role
$stmtPerms = $pdo->prepare("SELECT module_name, permission_key FROM rbac_permissions WHERE role_id = ? AND is_allowed = 1");
$stmtPerms->execute([$selected_role_id]);
$active_permissions = [];
while ($row = $stmtPerms->fetch()) {
    $active_permissions[$row['module_name']][$row['permission_key']] = true;
}

$page_title = "Access Control (RBAC)";
$active_page = "rbac";

// Definition of Modules and their available actions
$modules = [
    'User Management' => ['view', 'create', 'edit', 'delete'],
    'Financial Reports' => ['view', 'export'],
    'Tenant Settings' => ['view', 'edit'],
    'Inventory Control' => ['view', 'create', 'edit'],
    'Attendance Logs' => ['view', 'export'],
    'System Configuration' => ['view', 'edit'],
];
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title><?= htmlspecialchars($configs['system_name'] ?? 'Horizon System') ?> | <?= $page_title ?></title>
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
            gap: 2px; /* Much searhc tighter base gap */
            transition: all 0.3s ease-in-out;
        }
        .sidebar-nav:hover .sidebar-content {
            gap: 4px; /* Slightly more space on hover for readability */
        }
        /* End Sidebar Hover Logic */

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
        .alert-pulse { animation: alert-pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite; }
        @keyframes alert-pulse { 0%, 100% { opacity: 1; } 50% { opacity: .5; } }
        
        .status-card-green { border: 1px solid #10b981; background: linear-gradient(135deg, rgba(16,185,129,0.05) 0%, rgba(20,18,26,1) 100%); }
        .status-card-yellow { border: 1px solid #f59e0b; background: linear-gradient(135deg, rgba(245,158,11,0.05) 0%, rgba(20,18,26,1) 100%); }
        .status-card-red { border: 1px solid #ef4444; background: linear-gradient(135deg, rgba(239,68,68,0.05) 0%, rgba(20,18,26,1) 100%); }
        .dashed-container { border: 2px dashed rgba(255,255,255,0.1); border-radius: 24px; }
        
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
    <script>
        function updateHeaderClock() {
            const now = new Date();
            const headerClockEl = document.getElementById('headerClock');
            
            const timeString = now.toLocaleTimeString('en-US', { 
                hour: '2-digit', 
                minute: '2-digit', 
                second: '2-digit' 
            });

            if (headerClockEl) headerClockEl.textContent = timeString;
        }
        setInterval(updateHeaderClock, 1000);
        window.addEventListener('DOMContentLoaded', updateHeaderClock);
    </script>
</head>
<body class="antialiased flex flex-row min-h-screen">

<nav class="sidebar-nav flex flex-col bg-[#0a090d] border-r border-white/5 sticky top-0 h-screen px-7 py-8 z-50 shrink-0">
    <div class="mb-8">
        <div class="flex items-center gap-4 mb-6">
            <div class="size-10 rounded-xl bg-[#7f13ec] flex items-center justify-center shadow-lg shrink-0">
                <span class="material-symbols-outlined text-white text-2xl">bolt</span>
            </div>
            <h1 class="nav-text text-xl font-black italic uppercase tracking-tighter text-white"><?= htmlspecialchars($configs['system_name'] ?? 'Horizon System') ?></h1>
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
        <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white leading-none">Access <span class="text-primary">Control</span></h2>
        <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-2">Role-Based Permission Management</p>
    </div>
    <div class="text-right flex flex-col items-end">
        <p id="headerClock" class="text-white font-black italic text-xl tracking-tight leading-none mb-2">00:00:00 AM</p>
        <p class="text-primary text-[9px] font-black uppercase tracking-[0.2em] opacity-80"><?= date('l, M d, Y') ?></p>
    </div>
</header>

<!-- Status Messages -->
<?php if (isset($success_msg)): ?>
    <div class="mb-6 p-4 bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-[10px] font-black uppercase tracking-widest rounded-2xl flex items-center gap-3">
        <span class="material-symbols-outlined text-sm">check_circle</span> <?= $success_msg ?>
    </div>
<?php endif; ?>
<?php if (isset($error_msg)): ?>
    <div class="mb-6 p-4 bg-red-500/10 border border-red-500/20 text-red-400 text-[10px] font-black uppercase tracking-widest rounded-2xl flex items-center gap-3">
        <span class="material-symbols-outlined text-sm">error</span> <?= $error_msg ?>
    </div>
<?php endif; ?>

<div class="flex gap-4 mb-4 overflow-x-auto py-4 no-print pr-2 no-scrollbar scroll-smooth">
    <?php foreach($roles as $role): ?>
    <a href="?role_id=<?= $role['role_id'] ?>" 
       class="px-6 py-3 rounded-2xl border transition-all duration-300 whitespace-nowrap 
              <?= ($selected_role_id == $role['role_id']) ? 'border-primary bg-primary/10 text-primary scale-105 shadow-xl shadow-primary/10 z-10' : 'border-white/5 bg-white/5 text-gray-500 hover:text-white hover:bg-white/10' ?>">
        <p class="text-[10px] font-black uppercase tracking-widest"><?= htmlspecialchars($role['role_name']) ?></p>
    </a>
    <?php endforeach; ?>
</div>

<form action="?role_id=<?= $selected_role_id ?>" method="POST" class="glass-card p-8">
    <input type="hidden" name="role_id" value="<?= $selected_role_id ?>">
    <div class="flex justify-between items-center mb-8">
        <div>
            <h3 class="text-sm font-black italic uppercase tracking-widest">Module Permissions</h3>
            <p class="text-[10px] text-gray-500 font-bold uppercase mt-1">Configuring permissions for: <span class="text-primary italic">
                <?php 
                    foreach($roles as $r) {
                        if($r['role_id'] == $selected_role_id) {
                            echo htmlspecialchars($r['role_name']);
                            break;
                        }
                    }
                ?>
            </span></p>
        </div>
        <button type="submit" name="save_permissions" class="bg-primary text-white px-8 py-3 rounded-xl text-[10px] font-black uppercase tracking-widest hover:scale-105 transition-all shadow-lg shadow-primary/20">Save Changes</button>
    </div>

    <div class="space-y-6">
        <?php foreach($modules as $module => $actions): ?>
        <div class="p-6 bg-white/[0.02] border border-white/5 rounded-2xl flex flex-col md:flex-row md:items-center justify-between gap-6 hover:bg-white/[0.04] transition-all group">
            <div class="flex items-center gap-4">
                <div class="size-10 rounded-xl bg-primary/10 flex items-center justify-center group-hover:scale-110 transition-transform">
                    <span class="material-symbols-outlined text-primary text-xl">
                        <?php 
                            if (strpos($module, 'User') !== false) echo 'person_search';
                            elseif (strpos($module, 'Financial') !== false) echo 'payments';
                            elseif (strpos($module, 'Tenant') !== false) echo 'business_center';
                            elseif (strpos($module, 'Inventory') !== false) echo 'inventory_2';
                            elseif (strpos($module, 'System') !== false) echo 'settings';
                            else echo 'extension';
                        ?>
                    </span>
                </div>
                <div>
                    <h4 class="text-sm font-bold text-white"><?= $module ?></h4>
                    <p class="text-[9px] text-gray-500 font-black uppercase tracking-widest italic">Visibility & Action Control</p>
                </div>
            </div>
            
            <div class="flex flex-wrap gap-4">
                <?php foreach($actions as $action): ?>
                <label class="flex items-center gap-3 cursor-pointer group/label">
                    <div class="relative">
                        <input type="checkbox" name="permissions[<?= $module ?>][<?= $action ?>]" value="1" class="sr-only peer" <?= isset($active_permissions[$module][$action]) ? 'checked' : '' ?>>
                        <div class="w-10 h-5 bg-white/10 rounded-full peer-checked:bg-primary transition-all after:content-[''] after:absolute after:top-1 after:left-1 after:bg-gray-400 after:rounded-full after:h-3 after:w-3 after:transition-all peer-checked:after:translate-x-5 peer-checked:after:bg-white"></div>
                    </div>
                    <span class="text-[9px] font-black uppercase text-gray-500 group-hover/label:text-white transition-colors"><?= $action ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</form>

    </main>
</div>
</body>
</html>