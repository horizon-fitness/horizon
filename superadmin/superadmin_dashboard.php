<?php 
session_start();
require_once '../db.php';

// Security Check: Only Superadmin can access
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'superadmin') {
    header("Location: ../login.php");
    exit;
}

$page_title = "Super Admin Dashboard";
$active_page = "dashboard";

$success_msg = '';
$error_msg = '';

// Handle Application Actions (Approve / Reject)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['application_id'])) {
    $app_id = (int)$_POST['application_id'];
    $action = $_POST['action'];
    $admin_id = $_SESSION['user_id'];
    $now = date('Y-m-d H:i:s');

    if ($action === 'approve') {
        try {
            $pdo->beginTransaction();
            
            // 1. Update application status
            $stmtUpdate = $pdo->prepare("UPDATE gym_owner_applications SET application_status = 'Approved', reviewed_by = ?, reviewed_at = ? WHERE application_id = ?");
            $stmtUpdate->execute([$admin_id, $now, $app_id]);

            // 2. Fetch the application details
            $stmtApp = $pdo->prepare("SELECT * FROM gym_owner_applications WHERE application_id = ?");
            $stmtApp->execute([$app_id]);
            $app = $stmtApp->fetch(PDO::FETCH_ASSOC);

            // 3. Insert into gyms table
            $tenant_code = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $app['gym_name']), 0, 3)) . '-' . rand(1000, 9999);
            $stmtGym = $pdo->prepare("INSERT INTO gyms (owner_user_id, application_id, gym_name, business_name, address_id, contact_number, email, tenant_code, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, ?)");
            $stmtGym->execute([
                $app['user_id'], $app['application_id'], $app['gym_name'], $app['business_name'], $app['address_id'], $app['contact_number'], $app['email'], $tenant_code, $now, $now
            ]);
            $gym_id = $pdo->lastInsertId();

            // 4. Ensure 'Tenant' role exists and assign it
            $roleCheck = $pdo->prepare("SELECT role_id FROM roles WHERE role_name = 'Tenant' LIMIT 1");
            $roleCheck->execute();
            $role = $roleCheck->fetch(PDO::FETCH_ASSOC);
            if (!$role) {
                $pdo->query("INSERT INTO roles (role_name) VALUES ('Tenant')");
                $roleId = $pdo->lastInsertId();
            } else {
                $roleId = $role['role_id'];
            }

            $stmtRole = $pdo->prepare("INSERT INTO user_roles (user_id, role_id, gym_id, role_status, assigned_at) VALUES (?, ?, ?, 'Active', ?)");
            $stmtRole->execute([$app['user_id'], $roleId, $gym_id, $now]);

            // 5. Generate a Tenant Page for CMS Customization (Monday Activity Requirement)
            $stmtPage = $pdo->prepare("INSERT INTO tenant_pages (gym_id, page_slug, page_title, theme_color, updated_at) VALUES (?, ?, ?, '#7f13ec', ?)");
            $page_slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $app['gym_name']));
            $stmtPage->execute([$gym_id, $page_slug, $app['gym_name'], $now]);

            $pdo->commit();
            $success_msg = "Application for {$app['gym_name']} approved! Tenant portal is ready.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_msg = "Failed to approve: " . $e->getMessage();
        }
    } elseif ($action === 'reject') {
        $stmtUpdate = $pdo->prepare("UPDATE gym_owner_applications SET application_status = 'Rejected', reviewed_by = ?, reviewed_at = ? WHERE application_id = ?");
        $stmtUpdate->execute([$admin_id, $now, $app_id]);
        $success_msg = "Application rejected successfully.";
    }
}

// Fetch Real Dynamic Data for Dashboard
$total_revenue = 0.00; // Place holder for billing later

$stmtTenants = $pdo->query("SELECT COUNT(*) FROM gyms WHERE status = 'Active'");
$active_tenants = $stmtTenants->fetchColumn();

$stmtUsers = $pdo->query("SELECT COUNT(*) FROM users");
$total_users = $stmtUsers->fetchColumn();

$stmtPending = $pdo->query("SELECT COUNT(*) FROM gym_owner_applications WHERE application_status = 'Pending'");
$pending_apps_count = $stmtPending->fetchColumn();

// Fetch Recent Applications
$stmtList = $pdo->query("
    SELECT a.*, u.first_name, u.last_name 
    FROM gym_owner_applications a 
    JOIN users u ON a.user_id = u.user_id 
    ORDER BY 
        CASE WHEN a.application_status = 'Pending' THEN 1 ELSE 2 END,
        a.submitted_at DESC 
    LIMIT 10
");
$recent_applications = $stmtList->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title><?php echo $page_title ?? 'Super Admin Dashboard'; ?> | Herdoza Fitness</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "#8c2bee", "background-dark": "#0a090d", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)"}}}
        }
        function updateHeaderClock() {
            const now = new Date();
            if(document.getElementById('sidebarClock')) document.getElementById('sidebarClock').textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }
        window.onload = function() { setInterval(updateHeaderClock, 1000); updateHeaderClock(); };
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        .nav-link { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; transition: all 0.2s; white-space: nowrap; }
        .active-nav { color: #8c2bee !important; position: relative; }
        .active-nav::after { content: ''; position: absolute; right: -32px; top: 0; width: 4px; height: 100%; background: #8c2bee; border-radius: 2px; }
        
        @media (max-width: 1023px) {
            .active-nav::after { display: none; }
        }
        .alert-pulse { animation: alert-pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite; }
        @keyframes alert-pulse { 0%, 100% { opacity: 1; } 50% { opacity: .5; } }
        
        .status-card-green { border: 1px solid #10b981; background: linear-gradient(135deg, rgba(16,185,129,0.05) 0%, rgba(20,18,26,1) 100%); }
        .status-card-yellow { border: 1px solid #f59e0b; background: linear-gradient(135deg, rgba(245,158,11,0.05) 0%, rgba(20,18,26,1) 100%); }
        .status-card-red { border: 1px solid #ef4444; background: linear-gradient(135deg, rgba(239,68,68,0.05) 0%, rgba(20,18,26,1) 100%); }
        .dashed-container { border: 2px dashed rgba(255,255,255,0.1); border-radius: 24px; }
        
        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: #0a090d; }
        ::-webkit-scrollbar-thumb { background: #14121a; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #1a1824; }
    </style>
</head>
<body class="antialiased flex flex-row min-h-screen">

<nav class="flex flex-col w-64 lg:w-72 bg-[#0a090d] border-r border-white/5 sticky top-0 h-screen p-8 z-50 shrink-0">
    <div class="mb-12">
        <div class="flex items-center gap-4 mb-6">
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
    
    <div class="flex flex-col gap-5 flex-1 overflow-y-auto pr-2">
        <a href="superadmin_dashboard.php" class="nav-link flex items-center gap-3 <?= ($active_page == 'dashboard') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl">grid_view</span> Dashboard
        </a>
        <a href="tenant_management.php" class="nav-link flex items-center gap-3 <?= ($active_page == 'tenants') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl">business</span> Tenant Management
        </a>
        <a href="subscription_logs.php" class="nav-link flex items-center gap-3 <?= ($active_page == 'subscriptions') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl">history_edu</span> Subscription Logs
        </a>
        <a href="rbac_management.php" class="nav-link flex items-center gap-3 <?= ($active_page == 'rbac') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl">security</span> Access Control
        </a>
        <a href="real_time_occupancy.php" class="nav-link flex items-center gap-3 <?= ($active_page == 'occupancy') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl">group</span> Real-Time Occupancy
        </a>
        <a href="recent_transactions.php" class="nav-link flex items-center gap-3 <?= ($active_page == 'transactions') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl">receipt_long</span> Recent Transactions
        </a>
        <a href="system_alerts.php" class="nav-link flex items-center gap-3 <?= ($active_page == 'alerts') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl">notifications_active</span> System Alerts
        </a>
        <a href="system_status.php" class="nav-link flex items-center gap-3 <?= ($active_page == 'status') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl">monitor_heart</span> System Status
        </a>
        <a href="support_tickets.php" class="nav-link flex items-center gap-3 <?= ($active_page == 'tickets') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl">confirmation_number</span> Support Tickets
        </a>
        
        <div class="mt-auto pt-8 border-t border-white/10">
            <a href="../logout.php" class="text-gray-400 hover:text-red-500 transition-colors flex items-center gap-3 group">
                <span class="material-symbols-outlined group-hover:translate-x-1 transition-transform">logout</span>
                <span class="nav-link">Sign Out</span>
            </a>
        </div>
    </div>
</nav>

<div class="flex-1 flex flex-col min-w-0 overflow-y-auto">
    <main class="flex-1 p-6 md:p-10 max-w-[1400px] w-full mx-auto">

<header class="mb-10 flex flex-col md:flex-row justify-between items-start md:items-end gap-6">
    <div>
        <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white">System <span class="text-primary">Overview</span></h2>
        <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-1">Super Admin Control Center</p>
    </div>
</header>

<?php if ($success_msg): ?>
<div class="mb-8 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-sm font-semibold flex items-center gap-3">
    <span class="material-symbols-outlined">check_circle</span>
    <?= htmlspecialchars($success_msg) ?>
</div>
<?php endif; ?>

<?php if ($error_msg): ?>
<div class="mb-8 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-sm font-semibold flex items-center gap-3">
    <span class="material-symbols-outlined">error</span>
    <?= htmlspecialchars($error_msg) ?>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-10">
    <div class="glass-card p-8 status-card-green relative overflow-hidden group">
        <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform">payments</span>
        <p class="text-[10px] font-black uppercase text-gray-400 mb-2 tracking-widest">Global Revenue</p>
        <h3 class="text-2xl font-black italic uppercase">₱<?= number_format($total_revenue, 2) ?></h3>
        <p class="text-emerald-500 text-[10px] font-black uppercase mt-2">Across All Tenants</p>
    </div>
    <div class="glass-card p-8 status-card-yellow relative overflow-hidden group">
        <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform">business</span>
        <p class="text-[10px] font-black uppercase text-gray-400 mb-2 tracking-widest">Active Tenants</p>
        <h3 class="text-2xl font-black italic uppercase"><?= $active_tenants ?> Gyms</h3>
        <p class="text-amber-500 text-[10px] font-black uppercase mt-2">Live Subscriptions</p>
    </div>
    <div class="glass-card p-8 relative overflow-hidden group border border-white/5 bg-white/5">
        <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform">groups</span>
        <p class="text-[10px] font-black uppercase text-gray-400 mb-2 tracking-widest">Total Users</p>
        <h3 class="text-2xl font-black italic uppercase"><?= number_format($total_users) ?></h3>
        <p class="text-primary text-[10px] font-black uppercase mt-2">Network Growth</p>
    </div>
    <div class="glass-card p-8 relative overflow-hidden group border border-amber-500/20 bg-amber-500/5">
        <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-amber-500">pending_actions</span>
        <p class="text-[10px] font-black uppercase text-amber-500/70 mb-2 tracking-widest">Pending Apps</p>
        <h3 class="text-2xl font-black italic uppercase text-amber-400"><?= $pending_apps_count ?></h3>
        <p class="text-amber-500 text-[10px] font-black uppercase mt-2">Action Required</p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-10">
    <div class="glass-card p-8">
        <div class="flex justify-between items-center mb-6">
            <h3 class="text-sm font-black italic uppercase tracking-widest">Revenue Analytics</h3>
            <span class="text-[10px] font-black text-gray-500 uppercase tracking-widest">Last 30 Days</span>
        </div>
        <div class="h-[300px] flex items-center justify-center border border-white/5 rounded-2xl bg-white/[0.02] relative overflow-hidden">
            <p class="text-gray-600 text-[10px] font-black uppercase italic tracking-widest z-10">Revenue Chart Visualization</p>
            <div class="absolute bottom-0 left-0 w-full h-1/2 bg-gradient-to-t from-primary/5 to-transparent"></div>
        </div>
    </div>
    <div class="glass-card p-8">
        <div class="flex justify-between items-center mb-6">
            <h3 class="text-sm font-black italic uppercase tracking-widest">Tenant Growth</h3>
            <span class="text-[10px] font-black text-gray-500 uppercase tracking-widest">Monthly Onboarding</span>
        </div>
        <div class="h-[300px] flex items-center justify-center border border-white/5 rounded-2xl bg-white/[0.02] relative overflow-hidden">
            <p class="text-gray-600 text-[10px] font-black uppercase italic tracking-widest z-10">Growth Chart Visualization</p>
            <div class="absolute bottom-0 left-0 w-full h-1/2 bg-gradient-to-t from-primary/5 to-transparent"></div>
        </div>
    </div>
</div>

<div class="glass-card overflow-hidden mb-10">
    <div class="px-8 py-6 border-b border-white/5 bg-white/5 flex justify-between items-center">
        <h4 class="font-black italic uppercase text-sm tracking-tighter">Gym Applications <span class="text-primary">&</span> Tenant Activity</h4>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-left">
            <thead>
                <tr class="bg-background-dark/50 text-gray-500 text-[10px] font-black uppercase tracking-widest">
                    <th class="px-8 py-4">Gym Name</th>
                    <th class="px-8 py-4">Applicant</th>
                    <th class="px-8 py-4">Applied Date</th>
                    <th class="px-8 py-4">Status</th>
                    <th class="px-8 py-4 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
                <?php if (empty($recent_applications)): ?>
                    <tr>
                        <td colspan="5" class="px-8 py-8 text-center text-xs font-bold text-gray-500 italic uppercase">No recent applications found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recent_applications as $app): ?>
                        <tr class="hover:bg-white/5 transition-all">
                            <td class="px-8 py-5">
                                <div class="flex items-center gap-3">
                                    <div class="size-8 rounded-lg bg-primary/10 flex items-center justify-center font-black text-primary text-xs">
                                        <?= strtoupper(substr($app['gym_name'], 0, 2)) ?>
                                    </div>
                                    <div>
                                        <p class="text-sm font-bold italic"><?= htmlspecialchars($app['gym_name']) ?></p>
                                        <p class="text-[10px] text-gray-500 uppercase tracking-wider font-bold"><?= htmlspecialchars($app['business_type']) ?></p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-8 py-5">
                                <p class="text-xs font-medium text-white"><?= htmlspecialchars($app['first_name'] . ' ' . $app['last_name']) ?></p>
                                <p class="text-[10px] text-gray-500"><?= htmlspecialchars($app['email']) ?></p>
                            </td>
                            <td class="px-8 py-5 text-xs font-medium text-gray-400">
                                <?= date('M d, Y h:i A', strtotime($app['submitted_at'])) ?>
                            </td>
                            <td class="px-8 py-5">
                                <?php if ($app['application_status'] === 'Pending'): ?>
                                    <span class="px-3 py-1 rounded-full bg-amber-500/10 border border-amber-500/20 text-[9px] text-amber-500 font-black uppercase italic">Pending</span>
                                <?php elseif ($app['application_status'] === 'Approved'): ?>
                                    <span class="px-3 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-[9px] text-emerald-500 font-black uppercase italic">Approved</span>
                                <?php else: ?>
                                    <span class="px-3 py-1 rounded-full bg-red-500/10 border border-red-500/20 text-[9px] text-red-500 font-black uppercase italic">Rejected</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-8 py-5 text-right">
                                <?php if ($app['application_status'] === 'Pending'): ?>
                                    <form method="POST" class="inline-flex gap-2">
                                        <input type="hidden" name="application_id" value="<?= $app['application_id'] ?>">
                                        <button type="submit" name="action" value="approve" class="px-4 py-1.5 rounded-lg bg-emerald-500 hover:bg-emerald-600 text-white text-[10px] font-black uppercase tracking-widest transition-colors">
                                            Approve
                                        </button>
                                        <button type="submit" name="action" value="reject" class="px-4 py-1.5 rounded-lg bg-red-500/10 hover:bg-red-500/20 border border-red-500/20 text-red-400 text-[10px] font-black uppercase tracking-widest transition-colors">
                                            Reject
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-[10px] font-black text-gray-500 uppercase italic">Reviewed</span>
                                <?php endif; ?>
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