<?php 
session_start();
require_once '../db.php';

// Security Check: Only Superadmin can access
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'superadmin') {
    header("Location: ../login.php");
    exit;
}

$page_title = "Real-Time Occupancy";
$active_page = "occupancy";

// --- FILTER INPUTS ---
$search = $_GET['search'] ?? '';
$occupancy_status = $_GET['occupancy_status'] ?? 'all';

// 4-Color Elite Branding System: Fetching & Merging Settings
if (!function_exists('hexToRgb')) {
    function hexToRgb($hex) {
        $hex = str_replace("#", "", $hex);
        if (strlen($hex) == 3) {
            $r = hexdec(substr($hex, 0, 1) . substr($hex, 0, 1));
            $g = hexdec(substr($hex, 1, 1) . substr($hex, 1, 1));
            $b = hexdec(substr($hex, 2, 1) . substr($hex, 2, 1));
        } else {
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
        }
        return "$r, $g, $b";
    }
}

// 1. Fetch Global Settings (user_id = 0)
$stmtGlobal = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE user_id = 0");
$global_configs = $stmtGlobal->fetchAll(PDO::FETCH_KEY_PAIR);

// 2. Fetch User-Specific Settings (Personal Branding)
$stmtUser = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = ?");
$stmtUser->execute([$_SESSION['user_id']]);
$user_configs = $stmtUser->fetchAll(PDO::FETCH_KEY_PAIR);

// 3. Merge (User settings take precedence)
$brand = array_merge($global_configs, $user_configs);

// Fetch Real Data for Occupancy
try {
    $today = date('Y-m-d');
    $query = "SELECT 
        g.gym_id,
        g.gym_name as name, 
        COALESCE(
            NULLIF(gd.max_capacity, 0), 
            IF(goa.remarks LIKE '%Max Cap: %', 
               CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(goa.remarks, 'Max Cap: ', -1), ' |', 1) AS UNSIGNED),
               100),
            100
        ) as capacity,
        (SELECT COUNT(*) 
         FROM attendance a 
         WHERE a.gym_id = g.gym_id 
           AND (a.check_out_time IS NULL OR a.check_out_time = '00:00:00' OR a.check_out_time = '')
           AND (a.attendance_date = ? OR LOWER(a.attendance_status) = 'active' OR LOWER(a.attendance_status) = 'present' OR LOWER(a.attendance_status) = 'checked in')) as count
    FROM gyms g
    LEFT JOIN gym_details gd ON g.gym_id = gd.gym_id
    LEFT JOIN gym_owner_applications goa ON g.application_id = goa.application_id
    WHERE LOWER(g.status) = 'active'";

    $params = [$today];
    if (!empty($search)) {
        $query .= " AND (g.gym_name LIKE ? OR g.tenant_code LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $query .= " ORDER BY g.gym_name ASC";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    
    $gyms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // --- Process Gym Status & Final Filter ---
    $filtered_gyms = [];
    foreach ($gyms as $gym) {
        $rate = ($gym['capacity'] > 0) ? ($gym['count'] / $gym['capacity']) * 100 : 0;
        $status = ($rate >= 80) ? 'Full' : (($rate >= 40) ? 'Moderate' : 'Low');
        $gym['status'] = $status;
        
        if ($occupancy_status === 'all' || $status === $occupancy_status) {
            $filtered_gyms[] = $gym;
        }
    }
    $gyms = $filtered_gyms;

} catch (PDOException $e) {
    $gyms = [];
}
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title><?php echo $page_title ?? 'Super Admin Dashboard'; ?> | Horizon System</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { 
                extend: { 
                    colors: { 
                        "primary": "var(--primary)", 
                        "background": "var(--background)", 
                        "highlight": "var(--highlight)",
                        "text-main": "var(--text-main)",
                        "surface-dark": "#14121a", 
                        "border-subtle": "rgba(255,255,255,0.05)"
                    }
                }
            }
        }
    </script>
    <style>
        :root {
            --primary: <?= $brand['theme_color'] ?? '#8c2bee' ?>;
            --primary-rgb: <?= hexToRgb($brand['theme_color'] ?? '#8c2bee') ?>;
            --highlight: <?= $brand['secondary_color'] ?? '#a1a1aa' ?>;
            --text-main: <?= $brand['text_color'] ?? '#d1d5db' ?>;
            --background: <?= $brand['bg_color'] ?? '#0a090d' ?>;

            /* Glassmorphism Engine */
            --card-blur: 20px;
            --card-bg: <?= ($brand['auto_card_theme'] ?? '1') === '1' ? 'rgba(' . hexToRgb($brand['theme_color'] ?? '#8c2bee') . ', 0.05)' : ($brand['card_color'] ?? '#141216') ?>;
        }

        *::-webkit-scrollbar { display: none !important; }
        * { scrollbar-width: none !important; -ms-overflow-style: none !important; }

        body {
            font-family: '<?= $brand['font_family'] ?? 'Lexend' ?>', sans-serif;
            background-color: var(--background);
            color: var(--text-main);
        }

        .glass-card {
            background: var(--card-bg);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 24px;
            backdrop-filter: blur(20px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            transition: all 0.3s ease;
        }

        /* --- Dropdown Styling Fix --- */
        select {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%23<?= str_replace('#', '', $brand['theme_color'] ?? '8c2bee') ?>'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 1em;
        }

        select option {
            background-color: #1a1820 !important;
            color: var(--text-main);
            padding: 12px;
            font-weight: 600;
        }

        select option:hover, 
        select option:focus, 
        select option:active {
            background-color: var(--primary) !important;
            color: white !important;
        }
        /* --- Elite Table Components (Sync with Tenant Management) --- */
        .elite-table thead th {
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            color: var(--text-main);
            opacity: 0.5;
            padding: 18px 32px;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            text-align: left;
            background: rgba(var(--primary-rgb), 0.02);
        }

        .elite-table tbody tr {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border-bottom: 1px solid rgba(255,255,255,0.02);
        }

        .elite-table tbody tr:hover {
            background: rgba(255,255,255,0.04) !important;
        }

        .elite-table td {
            padding: 20px 32px;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-main);
        }

        .action-btn-ghost {
            padding: 8px 16px;
            border-radius: 12px;
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.05);
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--text-main);
            opacity: 0.5;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .action-btn-ghost:hover {
            background: rgba(var(--primary-rgb), 0.1);
            border-color: rgba(var(--primary-rgb), 0.3);
            color: var(--primary);
            opacity: 1;
        }

        /* --- Sidebar-Aware Modal Logic --- */
        #facilityModal {
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            left: 110px; 
            z-index: 200;
            transition: left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .sidebar-nav:hover ~ .main-content #facilityModal {
            left: 300px;
        }

        .modal-overlay {
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(10px);
        }

        /* --- Pagination Elite Styling --- */
        .pagination-btn {
            padding: 8px 16px;
            border-radius: 12px;
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.05);
            font-size: 11px;
            font-weight: 800;
            color: var(--text-main);
            transition: all 0.2s;
        }

        .pagination-btn:hover:not(:disabled) {
            background: rgba(var(--primary-rgb), 0.1);
            border-color: var(--primary);
            color: var(--primary);
        }

        .pagination-btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
            box-shadow: 0 4px 12px rgba(var(--primary-rgb), 0.3);
        }

        .pagination-btn:disabled {
            opacity: 0.3;
            cursor: not-allowed;
        }

        .sidebar-nav {
            width: 110px;
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            position: fixed;
            left: 0;
            top: 0;
            bottom: 0;
            z-index: 50;
            background: var(--background);
            border-right: 1px solid rgba(255, 255, 255, 0.05);
        }

        .sidebar-nav:hover {
            width: 300px;
        }

        .main-content {
            margin-left: 110px;
            flex: 1;
            min-width: 0;
            transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .sidebar-nav:hover ~ .main-content {
            margin-left: 300px;
        }

        .sidebar-scroll-container {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
        }

        /* Custom Scrollbar for the sidebar */
        .sidebar-scroll-container::-webkit-scrollbar {
            width: 4px;
        }

        .sidebar-scroll-container::-webkit-scrollbar-track {
            background: transparent;
        }

        .sidebar-scroll-container::-webkit-scrollbar-thumb {
            background: rgba(var(--primary-rgb), 0.1);
            border-radius: 10px;
        }

        .sidebar-nav:hover .sidebar-scroll-container::-webkit-scrollbar-thumb {
            background: rgba(var(--primary-rgb), 0.4);
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
            padding: 0 38px;
            pointer-events: none;
        }

        .sidebar-nav:hover .nav-section-header {
            max-height: 20px;
            opacity: 1;
            margin-bottom: 8px !important;
            pointer-events: auto;
        }

        .sidebar-nav:hover .nav-section-header.mt-4 {
            margin-top: 12px !important;
        }

        .sidebar-nav:hover .nav-section-header.mt-6 {
            margin-top: 16px !important;
        }

        .sidebar-content {
            display: flex;
            flex-direction: column;
            gap: 2px;
            transition: all 0.3s ease-in-out;
        }

        .sidebar-nav:hover .sidebar-content {
            gap: 4px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 10px 38px;
            transition: all 0.2s;
            white-space: nowrap;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.05em;
            color: var(--text-main);
            text-decoration: none;
        }

        .nav-link span.material-symbols-outlined {
            color: var(--highlight);
            opacity: 0.7;
            transition: all 0.3s ease;
        }

        .nav-link:hover {
            opacity: 0.8;
            transform: scale(1.02);
        }

        .active-nav {
            color: var(--primary) !important;
            position: relative;
        }

        .active-nav span.material-symbols-outlined {
            color: var(--primary) !important;
            opacity: 1 !important;
        }

        .active-nav::after {
            content: '';
            position: absolute;
            right: 0px;
            top: 50%;
            transform: translateY(-50%);
            width: 4px;
            height: 24px;
            background: var(--primary);
            border-radius: 4px 0 0 4px;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .sidebar-nav:hover .active-nav::after {
            opacity: 1;
        }
        
        @media (max-width: 1023px) {
            .active-nav::after { display: none; }
        }
        
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
    <script>
        // --- View & Pagination Engine ---
        let currentLayout = 'grid';
        let currentPage = 1;
        let rowsPerPage = 9; // Default for Grid (3x3)
        const allGymData = <?= json_encode($gyms) ?>;
        let gymData = [...allGymData];
        let totalGyms = gymData.length;

        function handleLiveFilter() {
            const searchQuery = document.getElementById('searchInput').value.toLowerCase();
            const statusFilter = document.getElementById('statusFilter').value;
            
            gymData = allGymData.filter(gym => {
                const matchesSearch = gym.name.toLowerCase().includes(searchQuery);
                const matchesStatus = statusFilter === 'all' || gym.status === statusFilter;
                return matchesSearch && matchesStatus;
            });
            
            totalGyms = gymData.length;
            currentPage = 1;
            renderPagination();
            updateVisibility();
            
            // Show/Hide Empty State
            const emptyState = document.querySelector('.dashed-container');
            if (totalGyms === 0) {
                emptyState?.classList.remove('hidden');
                document.getElementById('layoutGrid').classList.add('hidden');
                document.getElementById('layoutTable').classList.add('hidden');
                document.getElementById('paginationContainer').classList.add('hidden');
            } else {
                emptyState?.classList.add('hidden');
                switchLayout(currentLayout); // Re-trigger current layout visibility
            }
        }

        function openFacilityModal(gymId) {
            const gym = gymData.find(g => g.gym_id == gymId);
            if (!gym) return;

            document.getElementById('modalGymName').textContent = gym.name;
            document.getElementById('modalCurrentCount').textContent = gym.count;
            document.getElementById('modalMaxCapacity').textContent = gym.capacity;
            
            const rate = gym.capacity > 0 ? Math.round((gym.count / gym.capacity) * 100) : 0;
            document.getElementById('modalRate').textContent = rate + '%';
            
            const pBar = document.getElementById('modalProgressBar');
            pBar.style.width = rate + '%';
            
            if (gym.status === 'Full') {
                pBar.className = 'h-full rounded-full bg-red-500 transition-all duration-1000 shadow-lg shadow-red-500/20';
            } else if (gym.status === 'Moderate') {
                pBar.className = 'h-full rounded-full bg-amber-500 transition-all duration-1000 shadow-lg shadow-amber-500/20';
            } else {
                pBar.className = 'h-full rounded-full bg-primary transition-all duration-1000 shadow-lg shadow-primary/20';
            }

            const modal = document.getElementById('facilityModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.body.classList.add('overflow-hidden');
            toggleLogs(false); // Default to overview
        }

        function toggleLogs(showLogs) {
            const overview = document.getElementById('modalOverviewSection');
            const logs = document.getElementById('modalLogsSection');
            const logsContainer = document.getElementById('logsContainer');

            if (showLogs) {
                overview.classList.add('hidden');
                logs.classList.remove('hidden');
                logs.classList.add('flex');
                
                // Populate Mock Logs
                const events = ['User Registered Access', 'Standard Member Entry', 'Staff Override Scan', 'Trial Pass Admission', 'Guest Check-out'];
                const types = ['entry', 'entry', 'entry', 'entry', 'exit'];
                let html = '';
                
                for(let i=0; i<6; i++) {
                    const isEntry = Math.random() > 0.3;
                    const time = new Date(Date.now() - (i * 180000)).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                    const color = isEntry ? 'emerald' : 'rose';
                    const icon = isEntry ? 'login' : 'logout';
                    
                    html += `
                        <div class="p-3 rounded-xl bg-white/[0.02] border border-white/5 flex items-center justify-between group hover:bg-white/[0.04] transition-all">
                            <div class="flex items-center gap-3">
                                <div class="size-8 rounded-lg bg-${color}-500/10 flex items-center justify-center text-${color}-500 border border-${color}-500/20 shadow-lg shadow-${color}-500/10">
                                    <span class="material-symbols-outlined text-sm">${icon}</span>
                                </div>
                                <div>
                                    <p class="text-[10px] font-black uppercase text-[--text-main] opacity-80 leading-none">${isEntry ? 'FACILITY ENTRY' : 'FACILITY EXIT'}</p>
                                    <p class="text-[8px] text-[--text-main] opacity-30 font-bold uppercase mt-1">ID: HSZ-00${Math.floor(Math.random()*900)+100} • ${events[Math.floor(Math.random()*events.length)]}</p>
                                </div>
                            </div>
                            <span class="text-[9px] font-black text-primary italic opacity-60">${time}</span>
                        </div>
                    `;
                }
                logsContainer.innerHTML = html;
            } else {
                overview.classList.remove('hidden');
                logs.classList.add('hidden');
                logs.classList.remove('flex');
            }
        }

        function closeFacilityModal() {
            const modal = document.getElementById('facilityModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            document.body.classList.remove('overflow-hidden');
        }

        function switchLayout(layout) {
            currentLayout = layout;
            currentPage = 1; // Reset to page 1
            rowsPerPage = (layout === 'grid') ? 9 : 10;
            
            const grid = document.getElementById('layoutGrid');
            const table = document.getElementById('layoutTable');
            const btnGrid = document.getElementById('btnLayoutGrid');
            const btnTable = document.getElementById('btnLayoutTable');

            if (layout === 'grid') {
                grid.classList.remove('hidden');
                grid.classList.add('grid');
                table.classList.add('hidden');
                btnGrid.classList.add('bg-primary', 'shadow-lg', 'shadow-primary/20');
                btnGrid.classList.remove('text-white/40');
                btnTable.classList.remove('bg-primary', 'shadow-lg', 'shadow-primary/20');
                btnTable.classList.add('text-white/40');
            } else {
                grid.classList.add('hidden');
                grid.classList.remove('grid');
                table.classList.remove('hidden');
                btnTable.classList.add('bg-primary', 'shadow-lg', 'shadow-primary/20');
                btnTable.classList.remove('text-white/40');
                btnGrid.classList.remove('bg-primary', 'shadow-lg', 'shadow-primary/20');
                btnGrid.classList.add('text-white/40');
            }
            renderPagination();
        }

        function renderPagination() {
            const container = document.getElementById('paginationControls');
            const totalPages = Math.ceil(totalGyms / rowsPerPage);
            if (totalPages <= 1) {
                document.getElementById('paginationContainer').classList.add('hidden');
                return;
            }

            document.getElementById('paginationContainer').classList.remove('hidden');
            let html = '';

            // Prev Button
            html += `<button onclick="changePage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''} class="pagination-btn flex items-center justify-center">
                <span class="material-symbols-outlined text-sm">chevron_left</span>
            </button>`;

            // Page Numbers
            for (let i = 1; i <= totalPages; i++) {
                html += `<button onclick="changePage(${i})" class="pagination-btn ${currentPage === i ? 'active' : ''}">${i}</button>`;
            }

            // Next Button
            html += `<button onclick="changePage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''} class="pagination-btn flex items-center justify-center">
                <span class="material-symbols-outlined text-sm">chevron_right</span>
            </button>`;

            container.innerHTML = html;
            updateVisibility();
        }

        function changePage(page) {
            currentPage = page;
            renderPagination();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function updateVisibility() {
            const start = (currentPage - 1) * rowsPerPage;
            const end = start + rowsPerPage;

            // Update Label
            document.getElementById('startIndex').textContent = totalGyms > 0 ? start + 1 : 0;
            document.getElementById('endIndex').textContent = Math.min(end, totalGyms);
            document.getElementById('totalEntries').textContent = totalGyms;

            // Hide/Show items based on global index across both layouts
            const gridItems = document.querySelectorAll('#layoutGrid .gym-item');
            const tableItems = document.querySelectorAll('#layoutTable .gym-item');

            // We need to map the filtered gymData to the DOM elements
            // This assumes the DOM order matches the initial PHP order
            const visibleIds = gymData.slice(start, end).map(g => g.gym_id.toString());

            gridItems.forEach((item) => {
                if (visibleIds.includes(item.dataset.id)) item.classList.remove('hidden');
                else item.classList.add('hidden');
            });

            tableItems.forEach((item) => {
                if (visibleIds.includes(item.dataset.id)) item.classList.remove('hidden');
                else item.classList.add('hidden');
            });
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', () => {
            renderPagination();
        });

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
<body class="antialiased flex h-screen overflow-hidden">

<nav class="sidebar-nav z-50 flex flex-col no-scrollbar">
    <div class="px-7 py-5 mb-2 shrink-0"> 
        <div class="flex items-center gap-4"> 
            <div class="size-10 rounded-xl flex items-center justify-center shadow-lg shrink-0 overflow-hidden">
                <?php if (!empty($brand['system_logo'])): ?>
                    <img src="<?= htmlspecialchars($brand['system_logo']) ?>" id="sidebarLogoPreview" class="size-full object-contain rounded-xl">
                <?php else: ?>
                    <img src="../assests/horizon logo.png" id="sidebarLogoPreview" class="size-full object-contain rounded-xl transition-transform duration-500 hover:scale-110" alt="Horizon Logo">
                <?php endif; ?>
            </div>
            <h1 class="nav-text text-lg font-black italic uppercase tracking-tighter text-white">
                <?= htmlspecialchars($brand['system_name'] ?? 'Horizon System') ?>
            </h1>
        </div>
    </div>
    
    <div class="sidebar-scroll-container no-scrollbar space-y-1 pb-4">
        <div class="nav-section-header mb-2">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Overview</span>
        </div>
        <a href="superadmin_dashboard.php" class="nav-link <?= ($active_page == 'dashboard') ? 'active-nav' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">grid_view</span> 
            <span class="nav-text">Dashboard</span>
        </a>
        
        <div class="nav-section-header mb-2 mt-4">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Management</span>
        </div>
        <a href="tenant_management.php" class="nav-link <?= ($active_page == 'tenants') ? 'active-nav' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">business</span> 
            <span class="nav-text">Tenant Management</span>
        </a>

        <a href="subscription_logs.php" class="nav-link <?= ($active_page == 'subscriptions') ? 'active-nav' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">history_edu</span> 
            <span class="nav-text">Subscription Logs</span>
        </a>

        <a href="real_time_occupancy.php" class="nav-link <?= ($active_page == 'occupancy') ? 'active-nav' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">group</span> 
            <span class="nav-text">Real-Time Occupancy</span>
        </a>

        <a href="recent_transaction.php" class="nav-link <?= ($active_page == 'transactions') ? 'active-nav' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">receipt_long</span> 
            <span class="nav-text">Recent Transactions</span>
        </a>

        <div class="nav-section-header mb-2 mt-4">
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
        <div class="nav-section-header mb-2">
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
        <a href="../logout.php" class="nav-link !text-gray-400 hover:!text-rose-500 transition-colors group">
            <span class="material-symbols-outlined text-xl shrink-0 group-hover:!text-rose-500">logout</span>
            <span class="nav-text group-hover:!text-rose-500">Sign Out</span>
        </a>
    </div>
</nav>

<div class="main-content flex-1 flex flex-col min-w-0 overflow-y-auto no-scrollbar">
    <main class="flex-1 p-6 md:p-10 max-w-[1400px] w-full mx-auto">

<header class="mb-10 flex flex-row justify-between items-end gap-6">
    <div>
        <h2 class="text-3xl font-black italic uppercase tracking-tighter text-[--text-main] leading-none">Live <span class="text-primary">Occupancy</span></h2>
        <p class="text-[--text-main] opacity-40 text-xs font-bold uppercase tracking-widest mt-2">Real-time facility usage monitoring</p>
    </div>
    <div class="text-right">
        <p id="headerClock" class="text-[--text-main] font-black italic text-2xl tracking-tighter leading-none mb-2 transition-colors hover:text-primary">00:00:00 AM</p>
        <p class="text-primary text-[10px] font-black uppercase tracking-[0.2em] opacity-80 leading-none"><?= date('l, M d, Y') ?></p>
    </div>
</header>

<div class="glass-card p-6 mb-8 border-white/5 shadow-2xl relative overflow-hidden">
    <form method="GET" class="flex flex-wrap items-end gap-6 relative z-10">
        <div class="flex-1 min-w-[280px]">
            <label class="text-[10px] font-black uppercase text-[--text-main] opacity-40 mb-3 block tracking-[0.2em] px-1">Search Gym</label>
            <div class="relative group">
                <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-sm text-primary transition-transform group-hover:scale-110">search</span>
                <input type="text" id="searchInput" name="search" value="<?= htmlspecialchars($search) ?>" 
                       oninput="handleLiveFilter()"
                       placeholder="Gym Name or Tenant Code..." 
                       class="w-full bg-white/5 border border-white/10 rounded-xl py-3 pl-12 pr-4 text-xs font-bold transition-all focus:border-primary focus:bg-white/[0.08] outline-none placeholder:text-white/20">
            </div>
        </div>

        <div class="w-[180px]">
            <label class="text-[10px] font-black uppercase text-[--text-main] opacity-40 mb-3 block tracking-[0.2em] px-1">Occupancy Status</label>
            <select id="statusFilter" name="occupancy_status" 
                    onchange="handleLiveFilter()"
                    class="w-full bg-white/5 border border-white/10 rounded-xl py-3 px-4 pr-10 text-xs font-bold transition-all focus:border-primary outline-none text-primary">
                <option value="all" <?= $occupancy_status === 'all' ? 'selected' : '' ?> style="background-color: #1a1820; color: #fff;">All Status</option>
                <option value="Low" <?= $occupancy_status === 'Low' ? 'selected' : '' ?> style="background-color: #1a1820; color: #10b981;">Low (< 40%)</option>
                <option value="Moderate" <?= $occupancy_status === 'Moderate' ? 'selected' : '' ?> style="background-color: #1a1820; color: #f59e0b;">Moderate (40-79%)</option>
                <option value="Full" <?= $occupancy_status === 'Full' ? 'selected' : '' ?> style="background-color: #1a1820; color: #ef4444;">Full (80%+)</option>
            </select>
        </div>

        <div class="flex gap-2">
            <!-- View Switcher -->
            <div class="flex bg-white/5 border border-white/10 rounded-xl p-1 shrink-0 mr-2">
                <button type="button" onclick="switchLayout('grid')" id="btnLayoutGrid" class="p-2 rounded-lg transition-all flex items-center justify-center bg-primary text-white shadow-lg shadow-primary/20">
                    <span class="material-symbols-outlined text-sm">grid_view</span>
                </button>
                <button type="button" onclick="switchLayout('table')" id="btnLayoutTable" class="p-2 rounded-lg transition-all flex items-center justify-center text-white/40 hover:text-white">
                    <span class="material-symbols-outlined text-sm">table_rows</span>
                </button>
            </div>

            <button type="submit" class="p-3 rounded-xl bg-primary text-white shadow-lg shadow-primary/20 hover:scale-[1.05] active:scale-95 transition-all flex items-center justify-center">
                <span class="material-symbols-outlined text-sm">filter_alt</span>
            </button>
            <a href="real_time_occupancy.php" class="p-3 rounded-xl bg-white/5 border border-white/10 text-white/50 hover:bg-white/10 hover:text-white transition-all flex items-center justify-center">
                <span class="material-symbols-outlined text-sm">refresh</span>
            </a>
        </div>
    </form>
</div>

<!-- --- CONTENT LAYOUTS --- -->
<div id="layoutGrid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-10">
    <?php foreach($gyms as $gym): ?>
    <div class="gym-item glass-card p-8 group hover:border-primary/30 transition-all flex flex-col h-full overflow-hidden" data-id="<?= $gym['gym_id'] ?>">
        <div class="flex justify-between items-start mb-6">
            <div>
                <h3 class="text-lg font-black italic uppercase text-[--text-main]"><?= htmlspecialchars($gym['name']) ?></h3>
                <p class="text-[9px] text-[--text-main] opacity-40 font-black uppercase tracking-widest px-1 mt-1">Facility Capacity: <?= $gym['capacity'] ?></p>
            </div>
            <?php 
                $statusColor = ($gym['status'] == 'Full') ? 'text-red-500 bg-red-500/10' : (($gym['status'] == 'Moderate') ? 'text-amber-500 bg-amber-500/10' : 'text-emerald-500 bg-emerald-500/10');
            ?>
            <span class="px-3 py-1 rounded-full text-[10px] font-black uppercase border border-current <?= $statusColor ?>"><?= $gym['status'] ?></span>
        </div>
        
        <div class="relative pt-1 mt-auto">
            <div class="flex mb-3 items-center justify-between">
                <div>
                    <span class="text-2xl font-black italic text-[--text-main]"><?= $gym['count'] ?></span>
                    <span class="text-[10px] font-bold text-[--text-main] opacity-40 uppercase ml-1">People In</span>
                </div>
                <div class="text-right">
                    <span class="text-xs font-black italic text-primary">
                        <?= ($gym['capacity'] > 0) ? round(($gym['count'] / $gym['capacity']) * 100) : 0 ?>%
                    </span>
                </div>
            </div>
            <div class="overflow-hidden h-2 mb-2 text-xs flex rounded-full bg-white/5 border border-white/5">
                <div style="width:<?= ($gym['capacity'] > 0) ? ($gym['count'] / $gym['capacity']) * 100 : 0 ?>%" class="shadow-none flex flex-col text-center whitespace-nowrap text-white justify-center <?= ($gym['status'] == 'Full') ? 'bg-red-500' : (($gym['status'] == 'Moderate') ? 'bg-amber-500' : 'bg-primary') ?> transition-all duration-1000"></div>
            </div>
        </div>
        
        <div class="flex justify-between items-center mt-4 pt-4 border-t border-white/5">
            <p class="text-[9px] font-black uppercase text-[--text-main] opacity-40 tracking-widest italic">Last Scan: Just Now</p>
            <button onclick="openFacilityModal(<?= $gym['gym_id'] ?>)" class="material-symbols-outlined text-[--text-main] opacity-20 group-hover:text-primary transition-colors cursor-pointer">analytics</button>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div id="layoutTable" class="hidden mb-10 overflow-hidden rounded-[24px] border border-white/10">
    <div class="overflow-x-auto no-scrollbar">
        <table class="w-full elite-table border-collapse text-left">
            <thead>
                <tr class="bg-background/50">
                    <th class="px-8 py-4">Facility Name</th>
                    <th class="px-8 py-4">Capacity</th>
                    <th class="px-8 py-4">People In</th>
                    <th class="px-8 py-4">Occupancy Rate</th>
                    <th class="px-8 py-4">Current Status</th>
                    <th class="px-8 py-4 text-right">Actions</th>
                </tr>
            </thead>
            <tbody id="occupancyTableBody" class="divide-y divide-white/5">
                <?php foreach($gyms as $gym): ?>
                <tr class="gym-item hover:bg-white/5 transition-all" data-id="<?= $gym['gym_id'] ?>">
                    <td class="px-8 py-5">
                        <div class="flex items-center gap-3">
                            <div class="size-10 rounded-lg bg-white/5 flex items-center justify-center overflow-hidden border border-white/5 shadow-inner shrink-0 grayscale opacity-40 font-black italic text-xs">
                                <?= strtoupper(substr($gym['name'], 0, 2)) ?>
                            </div>
                            <div>
                                <p class="text-sm font-bold italic opacity-80"><?= htmlspecialchars($gym['name']) ?></p>
                                <p class="text-[10px] text-[--text-main] opacity-40 uppercase tracking-wider font-bold italic">Horizon Tenant Facility</p>
                            </div>
                        </div>
                    </td>
                    <td class="px-8 py-5">
                        <p class="text-xs font-medium text-[--text-main] opacity-40 italic"><?= $gym['capacity'] ?> Max Slots</p>
                    </td>
                    <td class="px-8 py-5 font-bold italic text-sm text-[--text-main]"><?= $gym['count'] ?></td>
                    <td class="px-8 py-5">
                        <div class="flex items-center gap-3">
                            <div class="w-24 h-1.5 rounded-full bg-white/5 overflow-hidden border border-white/5">
                                <div style="width:<?= ($gym['capacity'] > 0) ? ($gym['count'] / $gym['capacity']) * 100 : 0 ?>%" 
                                     class="h-full <?= ($gym['status'] == 'Full') ? 'bg-red-500' : (($gym['status'] == 'Moderate') ? 'bg-amber-500' : 'bg-primary') ?>"></div>
                            </div>
                            <span class="text-primary italic font-black text-xs"><?= ($gym['capacity'] > 0) ? round(($gym['count'] / $gym['capacity']) * 100) : 0 ?>%</span>
                        </div>
                    </td>
                    <td class="px-8 py-5">
                        <?php 
                            $statusColor = ($gym['status'] == 'Full') ? 'text-red-500 bg-red-500/10' : (($gym['status'] == 'Moderate') ? 'text-amber-500 bg-amber-500/10' : 'text-emerald-500 bg-emerald-500/10');
                        ?>
                        <span class="px-3 py-1 rounded-full text-[9px] font-black uppercase border border-current <?= $statusColor ?>"><?= $gym['status'] ?></span>
                    </td>
                    <td class="px-8 py-5 text-right">
                        <button onclick="openFacilityModal(<?= $gym['gym_id'] ?>)" class="action-btn-ghost ml-auto">
                            <span class="material-symbols-outlined text-sm">visibility</span>
                            Details
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (count($gyms) == 0): ?>
<div class="dashed-container border-2 border-dashed border-white/10 rounded-[24px] p-12 flex flex-col items-center justify-center text-center">
    <span class="material-symbols-outlined text-[--text-main] opacity-20 text-5xl mb-4">sensors</span>
    <h4 class="text-[--text-main] opacity-40 text-xs font-black uppercase tracking-widest italic">No facilities match your search...</h4>
    <p class="text-[--text-main] opacity-20 text-[10px] mt-2 font-bold uppercase tracking-tighter italic">Scanning across active tenant gyms</p>
</div>
<?php endif; ?>

<!-- --- ELITE PAGINATION ENGINE --- -->
<div id="paginationContainer" class="glass-card p-4 flex flex-col sm:flex-row items-center justify-between gap-4 border-white/5 no-scrollbar <?= count($gyms) <= 10 ? 'hidden' : '' ?>">
    <div class="text-[10px] font-black uppercase tracking-[0.2em] text-[--text-main] opacity-40 px-2 italic">
        Showing <span id="startIndex" class="text-primary italic">1</span> to <span id="endIndex" class="text-primary italic">10</span> of <span id="totalEntries" class="text-primary italic"><?= count($gyms) ?></span> Entries
    </div>
    <div class="flex items-center gap-1" id="paginationControls">
        <!-- JS Managed Pagination -->
    </div>
</div>

    </main>

    <!-- --- FACILITY DETAIL MODAL (SIDEBAR-AWARE) --- -->
    <div id="facilityModal" class="hidden items-center justify-center">
        <div class="modal-overlay absolute inset-0 cursor-pointer" onclick="closeFacilityModal()"></div>
        <div class="glass-card w-full max-w-[500px] border border-white/10 shadow-2xl relative overflow-hidden flex flex-col p-8 m-10">
            <div class="flex justify-between items-start mb-8">
                <div>
                    <h4 id="modalGymName" class="text-2xl font-black italic uppercase text-primary leading-none">Facility Detail</h4>
                    <p id="modalGymSub" class="text-[10px] text-[--text-main] opacity-40 font-black uppercase tracking-[0.2em] mt-3 italic">Live Monitoring Session</p>
                </div>
                <button onclick="closeFacilityModal()" class="size-10 rounded-xl bg-white/5 hover:bg-rose-500/20 text-white/40 hover:text-rose-500 transition-all flex items-center justify-center">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </div>

            <div id="modalOverviewSection">
                <div class="grid grid-cols-1 gap-6 mb-8">
                    <div class="p-6 rounded-2xl bg-white/[0.03] border border-white/5 flex items-center">
                        <div>
                            <p class="text-[10px] font-black uppercase text-primary tracking-widest mb-1 opacity-60 italic">Live Capacity</p>
                            <h5 class="text-3xl font-black italic text-[--text-main]"><span id="modalCurrentCount">0</span> <span class="text-xs text-[--text-main] opacity-30">/ <span id="modalMaxCapacity">0</span></span></h5>
                        </div>
                    </div>

                    <div class="relative pt-1 px-1">
                        <div class="flex mb-3 items-center justify-between">
                            <div>
                                <span class="text-[10px] font-black uppercase text-[--text-main] opacity-40 italic tracking-widest">Occupancy Progress</span>
                            </div>
                            <div class="text-right">
                                <span id="modalRate" class="text-sm font-black italic text-primary">0%</span>
                            </div>
                        </div>
                        <div class="overflow-hidden h-3 mb-2 text-xs flex rounded-full bg-white/5 border border-white/5 p-0.5">
                            <div id="modalProgressBar" style="width:0%" class="h-full rounded-full transition-all duration-1000"></div>
                        </div>
                    </div>
                </div>

                <div class="flex gap-4">
                    <button onclick="closeFacilityModal()" class="flex-1 py-4 px-6 rounded-xl bg-white/5 border border-white/10 text-[10px] font-black uppercase text-[--text-main] opacity-60 hover:bg-white/10 hover:opacity-100 transition-all tracking-widest">Close Dashboard</button>
                    <button onclick="toggleLogs(true)" class="flex-1 py-4 px-6 rounded-xl bg-primary text-white shadow-lg shadow-primary/20 hover:scale-[1.05] active:scale-95 transition-all text-[10px] font-black uppercase tracking-widest flex items-center justify-center gap-2">
                        <span class="material-symbols-outlined text-sm">assignment</span>
                        Detailed Logs
                    </button>
                </div>
            </div>

            <!-- --- DETAILED LOGS VIEW (HIDDEN) --- -->
            <div id="modalLogsSection" class="hidden flex-col h-full">
                <div class="flex items-center justify-between mb-4 px-1">
                    <p class="text-[10px] font-black uppercase text-primary tracking-widest italic opacity-60">Facility Live Stream</p>
                    <span class="text-[8px] font-bold text-[--text-main] opacity-20 uppercase tracking-tighter">Updating Chronologically</span>
                </div>
                
                <div class="flex-1 space-y-3 mb-8 max-h-[280px] overflow-y-auto pr-2 no-scrollbar" id="logsContainer">
                    <!-- Logs populated via JS -->
                </div>

                <div class="flex gap-4 mt-auto">
                    <button onclick="toggleLogs(false)" class="flex-1 py-4 px-6 rounded-xl bg-white/5 border border-white/10 text-[10px] font-black uppercase text-[--text-main] opacity-60 hover:bg-white/10 hover:opacity-100 transition-all tracking-widest flex items-center justify-center gap-2">
                        <span class="material-symbols-outlined text-sm">arrow_back</span>
                        Back to Summary
                    </button>
                    <button onclick="closeFacilityModal()" class="flex-1 py-4 px-6 rounded-xl bg-white/5 border border-white/10 text-[10px] font-black uppercase text-rose-500 hover:bg-rose-500/10 transition-all tracking-widest">Exit Monitoring</button>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>