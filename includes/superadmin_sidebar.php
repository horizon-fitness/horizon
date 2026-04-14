<?php
// Centralized Superadmin Sidebar with Pending Count Logic
// Required variables: $pdo, $active_page, $brand

// Count Pending Subscription Payments for the Badge
$pending_sub_count = 0;
if (isset($pdo)) {
    try {
        $stmtBadge = $pdo->query("SELECT COUNT(*) FROM client_subscriptions WHERE payment_status = 'Pending'");
        $pending_sub_count = $stmtBadge->fetchColumn();
    } catch (Exception $e) {
        // Silently fail if query fails
    }
}
?>
<nav class="sidebar-nav h-screen sticky top-0 z-50 shrink-0 flex flex-col no-scrollbar">
    <div class="px-7 py-5 mb-2 shrink-0">
        <div class="flex items-center gap-4">
            <div class="size-10 rounded-xl flex items-center justify-center shadow-lg shrink-0 overflow-hidden">
                <?php if (!empty($brand['system_logo'])): ?>
                    <img src="<?= htmlspecialchars($brand['system_logo']) ?>" class="size-full object-contain rounded-xl">
                <?php else: ?>
                    <img src="../assests/horizon logo.png" class="size-full object-contain rounded-xl transition-transform duration-500 hover:scale-110" alt="Horizon Logo">
                <?php endif; ?>
            </div>
            <h1 class="nav-text text-lg font-black italic uppercase tracking-tighter text-white">
                <?= htmlspecialchars($brand['system_name'] ?? 'Horizon System') ?>
            </h1>
        </div>
    </div>
    
    <div class="flex-1 overflow-y-auto no-scrollbar space-y-1 pb-4">
        <div class="nav-section-header px-7 mb-2">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Overview</span>
        </div>
        <a href="superadmin_dashboard.php" class="nav-link <?= ($active_page == 'dashboard') ? 'active-nav' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">grid_view</span> 
            <span class="nav-text">Dashboard</span>
        </a>
        
        <div class="nav-section-header px-7 mb-2 mt-4">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Management</span>
        </div>
        <a href="tenant_management.php" class="nav-link <?= ($active_page == 'tenants') ? 'active-nav' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">business</span> 
            <span class="nav-text">Tenant Management</span>
        </a>

        <a href="subscription_logs.php" class="nav-link <?= ($active_page == 'subscriptions') ? 'active-nav' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">history_edu</span> 
            <span class="nav-text">Subscription Logs</span>
            <?php if ($pending_sub_count > 0): ?>
                <span class="ml-auto size-5 rounded-full bg-rose-500 text-[9px] font-black flex items-center justify-center text-white alert-pulse shadow-lg shadow-rose-500/20">
                    <?= $pending_sub_count ?>
                </span>
            <?php endif; ?>
        </a>


        <div class="nav-section-header px-7 mb-2 mt-4">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">System</span>
        </div>
        <a href="system_alerts.php" class="nav-link <?= ($active_page == 'alerts') ? 'active-nav' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">notifications_active</span> 
            <span class="nav-text">System Alerts</span>
        </a>

        <a href="system_reports.php" class="nav-link <?= ($active_page == 'reports') ? 'active-nav' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">analytics</span> 
            <span class="nav-text">Reports</span>
        </a>

        <a href="sales_report.php" class="nav-link <?= ($active_page == 'sales_report') ? 'active-nav' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">monitoring</span> 
            <span class="nav-text">Sales Reports</span>
        </a>

        <a href="audit_logs.php" class="nav-link <?= ($active_page == 'audit_logs') ? 'active-nav' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">assignment</span> 
            <span class="nav-text">Audit Logs</span>
        </a>

        <a href="backup.php" class="nav-link <?= ($active_page == 'backup') ? 'active-nav' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">backup</span> 
            <span class="nav-text">Backup</span>
        </a>
    </div>

    <div class="mt-auto pt-4 border-t border-white/10 flex flex-col gap-1 shrink-0 pb-6">
        <div class="nav-section-header px-7 mb-0">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Account</span>
        </div>
        <a href="settings.php" class="nav-link <?= ($active_page == 'settings') ? 'active-nav' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">settings</span> 
            <span class="nav-text">Settings</span>
        </a>
        <a href="profile.php" class="nav-link <?= ($active_page == 'profile') ? 'active-nav' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">person</span> 
            <span class="nav-text">Profile</span>
        </a>
        <a href="../logout.php" class="nav-link !text-gray-400 hover:!text-rose-500 transition-all group">
            <span class="material-symbols-outlined group-hover:translate-x-1 transition-transform text-xl shrink-0 group-hover:!text-rose-500">logout</span>
            <span class="nav-text group-hover:!text-rose-500">Sign Out</span>
        </a>
    </div>
</nav>
