<?php
// Centralized Tenant Sidebar
// Required variables: 
// - $active_page: To highlight current page
// - $configs: Array containing branding (system_name, system_logo, etc.)
// - $page: Mapping of logo_path
?>
<style>
    .nav-item:hover {
        background: transparent !important;
        color: white !important;
    }
</style>

<nav class="side-nav bg-background-dark border-r border-white/5 z-50">
    <div class="px-7 py-8 mb-4 shrink-0">
        <div class="flex items-center gap-4">
            <?php 
                // Flexible branding fallbacks
                $logo = !empty($page['logo_path']) ? $page['logo_path'] : ($configs['system_logo'] ?? '');
                $name = !empty($configs['system_name']) ? $configs['system_name'] : ($page['system_name'] ?? 'Owner Portal');
            ?>
            <div id="sidebarLogoContainer" class="size-10 rounded-xl shrink-0 overflow-hidden flex items-center justify-center <?= empty($logo) ? 'bg-primary shadow-lg shadow-primary/20' : '' ?>">
                <?php if (!empty($logo)): ?>
                    <img id="sidebarLogoImg" src="<?= htmlspecialchars($logo) ?>" class="size-full object-cover">
                <?php else: ?>
                    <span id="sidebarBoltIcon" class="material-symbols-outlined text-white text-2xl">bolt</span>
                <?php endif; ?>
            </div>
            <h1 id="sidebarSystemName" class="nav-label text-lg font-black italic uppercase tracking-tighter text-white"><?= htmlspecialchars($name) ?></h1>

        </div>
    </div>
    
    <div class="flex-1 overflow-y-auto no-scrollbar space-y-1">
        <?php
            $pending_apps_count = 0;
            if (isset($pdo) && isset($_SESSION['gym_id'])) {
                try {
                    // Pending Coach Applications
                    $stmtPendA = $pdo->prepare("SELECT COUNT(*) FROM coach_applications WHERE gym_id = ? AND application_status = 'Pending'");
                    $stmtPendA->execute([$_SESSION['gym_id']]);
                    $pending_apps_count = $stmtPendA->fetchColumn();
                } catch (Exception $e) {}
            }
        ?>
        <div class="nav-section-label px-[38px] mb-2"><span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Main Menu</span></div>
        <a href="tenant_dashboard.php" class="nav-item <?= ($active_page == 'dashboard') ? 'active' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">grid_view</span> 
            <span class="nav-label">Dashboard</span>
        </a>
        
        <a href="my_users.php" class="nav-item <?= ($active_page == 'users') ? 'active' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">group</span> 
            <span class="nav-label">Users</span>
        </a>

        <div class="nav-section-label px-[38px] mb-2 mt-6"><span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Management</span></div>

        <a href="staff.php" class="nav-item <?= ($active_page == 'staff') ? 'active' : '' ?>">
            <div class="relative flex items-center justify-center shrink-0">
                <span class="material-symbols-outlined text-xl">badge</span> 
                <?php if ($pending_apps_count > 0): ?>
                    <span class="absolute -top-1.5 -right-1.5 size-4 rounded-full bg-amber-500 text-[8px] font-black flex items-center justify-center text-white shadow-lg shadow-amber-500/20">
                        <?= $pending_apps_count ?>
                    </span>
                <?php endif; ?>
            </div>
            <span class="nav-label">Staff</span>
        </a>

        <a href="reports.php" class="nav-item <?= ($active_page == 'reports') ? 'active' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">analytics</span> 
            <span class="nav-label">Reports</span>
        </a>

        <a href="sales_report.php" class="nav-item <?= ($active_page == 'sales') ? 'active' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">payments</span> 
            <span class="nav-label">Sales Reports</span>
        </a>
    </div>

    <div class="mt-auto pt-4 border-t border-white/10 shrink-0 pb-6">
        <div class="nav-section-label px-[38px] mb-2"><span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Account</span></div>
        <a href="tenant_settings.php" class="nav-item <?= ($active_page == 'settings') ? 'active' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">settings</span> 
            <span class="nav-label">Settings</span>
        </a>
        <a href="profile.php" class="nav-item <?= ($active_page == 'profile') ? 'active' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">account_circle</span> 
            <span class="nav-label">Profile</span>
        </a>
        <a href="../logout.php" class="nav-item text-gray-400 hover:text-rose-500 transition-colors">
            <span class="material-symbols-outlined text-xl shrink-0">logout</span> 
            <span class="nav-label">Sign Out</span>
        </a>
    </div>
</nav>
