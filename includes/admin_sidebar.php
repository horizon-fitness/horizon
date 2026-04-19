<?php
// Centralized Admin Sidebar
// Required variables: 
// - $active_page: To highlight current page
// - $page: Mapping of logo_path and system_name
?>
<style>
    .nav-item:hover {
        background: transparent !important;
        color: var(--text-main) !important;
    }
    .nav-item .material-symbols-rounded {
        color: var(--highlight);
        transition: transform 0.2s ease;
    }
    .nav-item:hover .material-symbols-rounded {
        transform: scale(1.1);
        color: inherit;
    }
    .nav-item.active .material-symbols-rounded {
        color: var(--primary);
    }
</style>

<nav class="side-nav bg-background border-r border-white/5 z-50">
    <div class="px-7 py-8 mb-4 shrink-0">
        <div class="flex items-center gap-4">
            <?php 
                $logo = !empty($page['logo_path']) ? $page['logo_path'] : '';
                // Ensure logo path is correct relative to the admin directory
                if (!empty($logo) && strpos($logo, 'data:image') !== 0 && strpos($logo, '../') !== 0) {
                    $logo = '../' . $logo;
                }
                $name = "STAFF PORTAL";
            ?>
            <div id="sidebarLogoContainer" class="size-10 rounded-xl shrink-0 overflow-hidden flex items-center justify-center <?= empty($logo) ? 'bg-primary shadow-lg shadow-primary/20' : '' ?>">
                <?php if (!empty($logo)): ?>
                    <img id="sidebarLogoImg" src="<?= htmlspecialchars($logo) ?>" class="size-full object-contain">
                <?php else: ?>
                    <span id="sidebarBoltIcon" class="material-symbols-rounded text-white text-2xl">bolt</span>
                <?php endif; ?>
            </div>
            <h1 id="sidebarSystemName" class="nav-label text-lg font-black italic uppercase tracking-tighter text-white"><?= htmlspecialchars($name) ?></h1>
        </div>
    </div>
    
    <div class="flex-1 overflow-y-auto no-scrollbar space-y-1">
        <div class="nav-section-label px-[38px] mb-2"><span class="text-[10px] font-black uppercase tracking-[0.2em] text-[--text-main]/40">Main Menu</span></div>
        <a href="admin_dashboard.php" class="nav-item <?= ($active_page == 'dashboard') ? 'active' : '' ?>">
            <span class="material-symbols-rounded text-xl shrink-0">grid_view</span> 
            <span class="nav-label">Dashboard</span>
        </a>
        
        <a href="register_member.php" class="nav-item <?= ($active_page == 'register') ? 'active' : '' ?>">
            <span class="material-symbols-rounded text-xl shrink-0">person_add</span> 
            <span class="nav-label">Walk-in Member</span>
        </a>

        <div class="nav-section-label px-[38px] mb-2 mt-6"><span class="text-[10px] font-black uppercase tracking-[0.2em] text-[--text-main]/40">Management</span></div>

        <a href="admin_users.php" class="nav-item <?= ($active_page == 'users') ? 'active' : '' ?>">
            <span class="material-symbols-rounded text-xl shrink-0">group</span> 
            <span class="nav-label">My Users</span>
        </a>

        <a href="admin_transaction.php" class="nav-item <?= ($active_page == 'transactions') ? 'active' : '' ?>">
            <span class="material-symbols-rounded text-xl shrink-0">receipt_long</span> 
            <span class="nav-label">Transactions</span>
        </a>

        <a href="admin_appointment.php" class="nav-item <?= ($active_page == 'bookings') ? 'active' : '' ?>">
            <span class="material-symbols-rounded text-xl shrink-0">event_note</span> 
            <span class="nav-label">Bookings</span>
        </a>

        <a href="admin_attendance.php" class="nav-item <?= ($active_page == 'attendance') ? 'active' : '' ?>">
            <span class="material-symbols-rounded text-xl shrink-0">history</span> 
            <span class="nav-label">Attendance</span>
        </a>

        <a href="admin_report.php" class="nav-item <?= ($active_page == 'reports') ? 'active' : '' ?>">
            <span class="material-symbols-rounded text-xl shrink-0">description</span> 
            <span class="nav-label">Reports</span>
        </a>
    </div>

    <div class="mt-auto pt-4 border-t border-white/10 shrink-0 pb-6">
        <div class="nav-section-label px-[38px] mb-2"><span class="text-[10px] font-black uppercase tracking-[0.2em] text-[--text-main]/40">Account</span></div>
        <a href="admin_profile.php" class="nav-item <?= ($active_page == 'profile') ? 'active' : '' ?>">
            <span class="material-symbols-rounded text-xl shrink-0">account_circle</span> 
            <span class="nav-label">Profile</span>
        </a>
        <a href="../logout.php" class="nav-item group text-[--text-main]/45 hover:text-rose-500 transition-colors">
            <span class="material-symbols-rounded text-xl shrink-0 group-hover:text-rose-500">logout</span> 
            <span class="nav-label">Sign Out</span>
        </a>
    </div>
</nav>
