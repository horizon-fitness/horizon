<?php
// Centralized Coach Sidebar
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
                $name = "Coach Portal"; 
                
                // Handle pathing for coach directory
                if (!empty($logo) && strpos($logo, 'data:image') !== 0 && strpos($logo, 'http') !== 0 && strpos($logo, '../') !== 0) {
                    $logo = '../' . $logo;
                }
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
    
    <div class="flex-1 overflow-y-auto no-scrollbar flex flex-col space-y-1">
        <div class="nav-section-label px-[38px] mb-2"><span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Main Menu</span></div>
        <a href="coach_dashboard.php" class="nav-item <?= ($active_page == 'dashboard') ? 'active' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">grid_view</span> 
            <span class="nav-label">Dashboard</span>
        </a>
        
        <a href="coach_schedule.php" class="nav-item <?= ($active_page == 'schedule') ? 'active' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">edit_calendar</span> 
            <span class="nav-label">My Availability</span>
        </a>

        <a href="coach_members.php" class="nav-item <?= ($active_page == 'members') ? 'active' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">groups</span> 
            <span class="nav-label">My Members</span>
        </a>

    </div>

    <div class="mt-auto pt-4 border-t border-white/10 shrink-0 pb-6 flex flex-col">
        <div class="nav-section-label px-[38px] mb-2"><span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Account</span></div>
        <a href="coach_profile.php" class="nav-item <?= ($active_page == 'profile') ? 'active' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">account_circle</span> 
            <span class="nav-label">Profile</span>
        </a>
        <a href="../logout.php" class="nav-item hover:!text-rose-500 transition-colors group">
            <span class="material-symbols-outlined text-xl shrink-0 group-hover:!text-rose-500 transition-colors">logout</span> 
            <span class="nav-label group-hover:!text-rose-500">Sign Out</span>
        </a>
    </div>
</nav>
