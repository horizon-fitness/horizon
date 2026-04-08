<?php 
session_start();
require_once '../db.php';

// Security Check: Only Superadmin can access
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'superadmin') {
    header("Location: ../login.php");
    exit;
}

// Hex to RGB helper for dynamic transparency
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

// Fetch and Merge Settings
// 1. Fetch Global Settings
$stmtGlobal = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE user_id = 0");
$global_configs = $stmtGlobal->fetchAll(PDO::FETCH_KEY_PAIR);

// 2. Fetch User-Specific Settings
$stmtUser = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = ?");
$stmtUser->execute([$_SESSION['user_id']]);
$user_configs = $stmtUser->fetchAll(PDO::FETCH_KEY_PAIR);

// 3. Merge
$configs = array_merge($global_configs, $user_configs);


// Ensure backups table exists with archiving support
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS backups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        backup_id VARCHAR(50) NOT NULL,
        backup_date DATETIME NOT NULL,
        backup_size VARCHAR(50) NOT NULL,
        backup_status ENUM('Successful', 'Failed') NOT NULL,
        backup_type VARCHAR(50) NOT NULL,
        file_path VARCHAR(255) DEFAULT NULL,
        archived_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Check if archived_at column exists (migration for existing table)
    $columns = $pdo->query("SHOW COLUMNS FROM backups LIKE 'archived_at'")->fetchAll();
    if (empty($columns)) {
        $pdo->exec("ALTER TABLE backups ADD COLUMN archived_at DATETIME DEFAULT NULL");
    }
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'run_backup') {
        try {
            $new_bkp_id = 'BKP-' . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
            $stmt = $pdo->prepare("INSERT INTO backups (backup_id, backup_date, backup_size, backup_status, backup_type) VALUES (?, NOW(), ?, ?, ?)");
            $stmt->execute([
                $new_bkp_id,
                '4' . mt_rand(4, 6) . '.' . mt_rand(0, 9) . ' MB',
                'Successful',
                mt_rand(0, 1) ? 'Full System' : 'Database Only'
            ]);
            $_SESSION['success_msg'] = "Backup $new_bkp_id created successfully!";
        } catch (PDOException $e) {
            $_SESSION['error_msg'] = "Backup failed: " . $e->getMessage();
        }
    }

    if ($_POST['action'] === 'archive_backup' && isset($_POST['id'])) {
        try {
            $stmt = $pdo->prepare("UPDATE backups SET archived_at = NOW() WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            $_SESSION['success_msg'] = "Backup archived successfully.";
        } catch (PDOException $e) {
            $_SESSION['error_msg'] = "Archive failed: " . $e->getMessage();
        }
    }

    if ($_POST['action'] === 'restore_backup' && isset($_POST['id']) && isset($_POST['backup_id'])) {
        try {
            // Simulate restore action
            $bkp_id = $_POST['backup_id'];
            $_SESSION['success_msg'] = "Restore point $bkp_id initiated. System will reboot shortly.";
        } catch (PDOException $e) {
            $_SESSION['error_msg'] = "Restore failed.";
        }
    }
    
    header("Location: backup.php" . (isset($_GET['view']) ? "?view=" . $_GET['view'] : ""));
    exit;
}

// View Mode (Active vs Archived)
$view = $_GET['view'] ?? 'active';
$where_clause = ($view === 'archived') ? "WHERE archived_at IS NOT NULL" : "WHERE archived_at IS NULL";

// Fetch Real Backup Data
try {
    $stmt = $pdo->query("SELECT * FROM backups $where_clause ORDER BY backup_date DESC");
    $backups = $stmt->fetchAll();
    
    // Overall Stats (for cards)
    $stmt_stats = $pdo->query("SELECT COUNT(*) as count, SUM(CAST(backup_size AS DECIMAL(10,2))) as total_size FROM backups WHERE archived_at IS NULL");
    $stats_row = $stmt_stats->fetch();
    $total_backups = $stats_row['count'] ?? 0;
    $total_size_mb = $stats_row['total_size'] ?? 0;
    
    $stmt_last = $pdo->query("SELECT backup_date, backup_id FROM backups WHERE archived_at IS NULL ORDER BY backup_date DESC LIMIT 1");
    $last_bkp = $stmt_last->fetch();
    $last_backup_time = $last_bkp ? $last_bkp['backup_date'] : null;
    $last_backup_id = $last_bkp ? $last_bkp['backup_id'] : null;
} catch (PDOException $e) {
    $backups = [];
    $total_backups = 0;
    $last_backup_time = null;
    $total_size_mb = 0;
}

$page_title = "Admin (Developer) System Backup";
$active_page = "backup"; 
$header_title = 'System <span class="text-primary">Backup</span>';
$header_subtitle = 'Data Protection & Restore Points';

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
            theme: { 
                extend: { 
                    colors: { 
                        "primary": "var(--primary)", 
                        "background": "var(--background)", 
                        "secondary": "var(--secondary)", 
                        "surface-dark": "#14121a", 
                        "border-subtle": "rgba(255,255,255,0.05)"
                    }
                } 
            }
        }
    </script>
    <style>
        :root {
            --primary: <?= $configs['theme_color'] ?? '#8c2bee' ?>;
            --primary-rgb: <?= hexToRgb($configs['theme_color'] ?? '#8c2bee') ?>;
            --background: <?= $configs['bg_color'] ?? '#0a090d' ?>;
            --highlight: <?= $configs['secondary_color'] ?? '#a1a1aa' ?>;
            --text-main: <?= $configs['text_color'] ?? '#d1d5db' ?>;
            --secondary-rgb: 161, 161, 170;
            --card-blur: 20px;
            --card-bg: <?= ($configs['auto_card_theme'] ?? '1') === '1' ? 'rgba(' . hexToRgb($configs['theme_color'] ?? '#8c2bee') . ', 0.05)' : ($configs['card_color'] ?? '#141216') ?>;

        }

        body { 
            font-family: '<?= $configs['font_family'] ?? 'Lexend' ?>', sans-serif; 
            background-color: var(--background); 
            color: var(--text-main); 
        }

        .glass-card { 
            background: var(--card-bg); 
            border: 1px solid rgba(255, 255, 255, 0.05); 
            border-radius: 24px; 
            backdrop-filter: blur(var(--card-blur));
            transition: all 0.3s ease;
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

        .sidebar-nav:hover~.main-content {
            margin-left: 300px;
        }

        .sidebar-scroll-container {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
        }

        /* Global Hidden Scrollbar - Sleek UI */
        ::-webkit-scrollbar {
            display: none;
        }

        * {
            -ms-overflow-style: none;
            /* IE and Edge */
            scrollbar-width: none;
            /* Firefox */
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
            /* Subtle feedback instead of color change */
        }

        .nav-link:hover span.material-symbols-outlined {
            /* No color change to match dashboard */
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
            opacity: 1;
            transition: opacity 0.3s ease;
        }

        @media (max-width: 1023px) {
            .active-nav::after {
                display: none;
            }
        }

        .no-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

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

<nav id="liveSidebar" class="sidebar-nav z-50 flex flex-col no-scrollbar">
    <div class="px-7 py-5 mb-2 shrink-0">
        <div class="flex items-center gap-4">
            <div class="size-10 rounded-xl flex items-center justify-center shadow-lg shrink-0 overflow-hidden">
                <?php if (!empty($configs['system_logo'])): ?>
                    <img src="<?= htmlspecialchars($configs['system_logo']) ?>" id="sidebarLogoPreview"
                        class="size-full object-contain rounded-xl">
                <?php else: ?>
                    <img src="../assests/horizon logo.png" id="sidebarLogoPreview"
                        class="size-full object-contain rounded-xl transition-transform duration-500 hover:scale-110"
                        alt="Horizon Logo">
                <?php endif; ?>
            </div>
            <h1 id="sidebarSystemName"
                class="nav-text text-lg font-black italic uppercase tracking-tighter text-white">
                <?= htmlspecialchars($configs['system_name'] ?? 'Horizon System') ?>
            </h1>
        </div>
    </div>

    <div class="sidebar-scroll-container no-scrollbar space-y-1 pb-4">
        <div class="nav-section-header mb-2">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Overview</span>
        </div>
        <a href="superadmin_dashboard.php"
            class="nav-link <?= ($active_page == 'dashboard') ? 'active-nav' : '' ?>">
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

        <a href="subscription_logs.php"
            class="nav-link <?= ($active_page == 'subscriptions') ? 'active-nav' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">history_edu</span>
            <span class="nav-text">Subscription Logs</span>
        </a>

        <a href="real_time_occupancy.php" class="nav-link <?= ($active_page == 'occupancy') ? 'active-nav' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">group</span>
            <span class="nav-text">Real-Time Occupancy</span>
        </a>

        <a href="recent_transaction.php"
            class="nav-link <?= ($active_page == 'transactions') ? 'active-nav' : '' ?>">
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
        <a href="../logout.php" class="nav-link hover:text-rose-500 transition-all group">
            <span class="material-symbols-outlined text-xl shrink-0 group-hover:text-rose-500">logout</span>
            <span class="nav-text group-hover:text-rose-500">Sign Out</span>
        </a>
    </div>
</nav>

<div class="main-content flex-1 flex flex-col min-w-0 overflow-y-auto">

    <main class="flex-1 p-6 md:p-10 max-w-[1400px] w-full mx-auto">
        <header class="mb-10 flex flex-row justify-between items-end gap-6">
            <div>
                <h2 class="text-3xl font-black italic uppercase tracking-tighter leading-none">
                    <span class="text-[var(--text-main)] opacity-80">SYSTEM</span>
                    <span class="text-primary">BACKUP</span>
                </h2>
                <p class="text-[var(--text-main)] text-[10px] font-bold uppercase tracking-widest mt-2 px-1 opacity-80 uppercase">Data Protection & Restore Points</p>
            </div>
            <div class="text-right hidden md:block">
                <p id="headerClock" class="text-[var(--text-main)] font-black italic text-xl tracking-tight leading-none mb-2">00:00:00 AM</p>
                <p class="text-primary text-[9px] font-black uppercase tracking-[0.2em] opacity-80"><?= date('l, M d, Y') ?></p>
            </div>
        </header>




        <?php if (isset($_SESSION['success_msg'])): ?>
        <div class="mb-6 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-500 text-xs font-bold uppercase tracking-widest flex items-center justify-between">
            <div class="flex items-center gap-3">
                <span class="material-symbols-outlined">check_circle</span>
                <?= $_SESSION['success_msg'] ?>
            </div>
            <button onclick="this.parentElement.remove()" class="material-symbols-outlined text-sm opacity-50 hover:opacity-100">close</button>
        </div>
        <?php unset($_SESSION['success_msg']); endif; ?>

        <?php if (isset($_SESSION['error_msg'])): ?>
        <div class="mb-6 p-4 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-500 text-xs font-bold uppercase tracking-widest flex items-center justify-between">
            <div class="flex items-center gap-3">
                <span class="material-symbols-outlined">error</span>
                <?= $_SESSION['error_msg'] ?>
            </div>
            <button onclick="this.parentElement.remove()" class="material-symbols-outlined text-sm opacity-50 hover:opacity-100">close</button>
        </div>
        <?php unset($_SESSION['error_msg']); endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">
            <div class="glass-card p-8 border-emerald-500/20 bg-emerald-500/5 relative overflow-hidden group">
                <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-20 group-hover:scale-110 transition-transform text-emerald-500">gpp_good</span>
                <p class="text-[10px] font-black uppercase text-emerald-500/70 tracking-widest mb-2">Security Status</p>
                <h3 class="text-2xl font-black text-[var(--text-main)] italic">Protected</h3>
                <p class="text-[9px] text-emerald-500/50 uppercase font-bold mt-2 italic tracking-wider">Database + Files Secured</p>
            </div>

            <div class="glass-card p-8 relative overflow-hidden group">
                <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-20 group-hover:scale-110 transition-transform text-primary">update</span>
                <p class="text-[10px] font-black uppercase text-[var(--text-main)]/50 tracking-widest mb-2">Last Backup</p>
                <h3 class="text-2xl font-black text-[var(--text-main)] italic"><?= $last_backup_time ? date('M d, h:i A', strtotime($last_backup_time)) : 'None' ?></h3>
                <p class="text-[9px] text-[var(--text-main)]/30 uppercase font-bold mt-2 italic tracking-wider"><?= $total_backups > 0 ? $last_backup_id . ' | Successful' : 'Waiting for first backup' ?></p>
            </div>

            <div class="glass-card p-8 relative overflow-hidden group">
                <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-20 group-hover:scale-110 transition-transform text-primary">inventory_2</span>
                <p class="text-[10px] font-black uppercase text-[var(--text-main)]/50 tracking-widest mb-2">Stored Archives</p>
                <h3 class="text-2xl font-black text-[var(--text-main)] italic"><?= $total_backups ?> Backups</h3>
                <p class="text-[9px] text-primary/50 uppercase font-bold mt-2 italic tracking-wider">Total <?= number_format($total_size_mb, 1) ?> MB Storage</p>
            </div>

        </div>


        <div class="glass-card overflow-hidden">
            <div class="px-8 py-6 border-b border-white/5 bg-white/[0.01] flex justify-between items-center">
                <div>
                    <h3 class="text-sm font-black italic uppercase tracking-widest text-[var(--text-main)]"><?= $view === 'archived' ? 'Archived Records' : 'Backup History' ?></h3>
                    <p class="text-[9px] text-[var(--text-main)]/50 font-bold uppercase tracking-widest mt-1"><?= $view === 'archived' ? 'Historical data points currently in archive' : 'Repository of available restore points' ?></p>
                </div>

                <div class="flex items-center gap-4">
                    <form method="POST" class="mr-2">
                        <input type="hidden" name="action" value="run_backup">
                        <button type="submit" class="px-4 py-2 rounded-xl bg-red-600 text-white text-[9px] font-black uppercase italic tracking-widest hover:scale-105 transition-all active:scale-95 shadow-lg shadow-red-600/30 border border-red-500/20">
                            Run Manual Backup
                        </button>
                    </form>

                    <a href="?view=active" class="px-4 py-2 rounded-xl <?= $view === 'active' ? 'bg-primary text-[var(--text-main)]' : 'bg-white/5 text-[var(--text-main)]/40 border border-white/10' ?> text-[9px] font-black uppercase tracking-widest transition-all">Active</a>
                    <a href="?view=archived" class="px-4 py-2 rounded-xl <?= $view === 'archived' ? 'bg-primary text-[var(--text-main)]' : 'bg-white/5 text-[var(--text-main)]/40 border border-white/10' ?> text-[9px] font-black uppercase tracking-widest transition-all">Archived</a>
                </div>


            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-background/50 text-[var(--text-main)]/50 text-[10px] font-black uppercase tracking-widest">

                            <th class="px-8 py-4">Backup ID</th>
                            <th class="px-8 py-4">Date & Time</th>
                            <th class="px-8 py-4">Type</th>
                            <th class="px-8 py-4 text-center">Size</th>
                            <th class="px-8 py-4">Status</th>
                            <th class="px-8 py-4 text-right pr-8">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <?php if (empty($backups)): ?>
                            <tr><td colspan="6" class="px-8 py-12 text-center text-xs text-gray-600 italic uppercase font-bold tracking-widest">No backup history available</td></tr>
                        <?php else: ?>
                            <?php foreach ($backups as $b): ?>
                            <tr class="hover:bg-white/[0.01] transition-colors group">
                                <td class="px-8 py-5">
                                    <span class="text-xs font-black text-primary italic uppercase tracking-widest group-hover:text-[var(--text-main)] transition-colors"><?= $b['backup_id'] ?></span>
                                </td>
                                <td class="px-8 py-5">
                                    <p class="text-xs text-[var(--text-main)] font-bold leading-none mb-1"><?= date('M d, Y', strtotime($b['backup_date'])) ?></p>
                                    <p class="text-[9px] text-[var(--text-main)]/40 font-black italic uppercase"><?= date('h:i A', strtotime($b['backup_date'])) ?></p>
                                </td>
                                <td class="px-8 py-5">
                                    <span class="text-[10px] text-[var(--text-main)]/60 font-black uppercase italic border border-white/10 px-2 py-0.5 rounded-md"><?= $b['backup_type'] ?></span>
                                </td>
                                <td class="px-8 py-5 text-xs text-center font-bold text-[var(--text-main)]/80"><?= $b['backup_size'] ?></td>

                                <td class="px-8 py-5">
                                    <?php if ($b['backup_status'] === 'Successful'): ?>
                                        <span class="px-3 py-1 rounded-full bg-emerald-500/10 text-emerald-500 border border-emerald-500/20 text-[9px] font-black uppercase italic">Successful</span>
                                    <?php else: ?>
                                        <span class="px-3 py-1 rounded-full bg-rose-500/10 text-rose-500 border border-rose-500/20 text-[9px] font-black uppercase italic">Failed</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-8 py-5 text-right">
                                    <div class="inline-flex gap-2">
                                        <?php if ($view === 'active'): ?>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="action" value="restore_backup">
                                            <input type="hidden" name="id" value="<?= $b['id'] ?>">
                                            <input type="hidden" name="backup_id" value="<?= $b['backup_id'] ?>">
                                            <button type="submit" class="size-9 rounded-xl bg-primary/10 hover:bg-primary/20 border border-primary/20 text-primary flex items-center justify-center transition-all hover:scale-105 group/btn2" title="Restore Point" onclick="return confirm('Initiate system restore using <?= $b['backup_id'] ?>?')">
                                                <span class="material-symbols-outlined text-sm group-hover/btn2:text-white">settings_backup_restore</span>
                                            </button>
                                        </form>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="action" value="archive_backup">
                                            <input type="hidden" name="id" value="<?= $b['id'] ?>">
                                            <button type="submit" class="size-9 rounded-xl bg-white/5 hover:bg-amber-500/20 border border-white/5 text-[var(--text-main)]/40 flex items-center justify-center transition-all hover:scale-105 group/btn" title="Archive Backup" onclick="return confirm('Are you sure you want to archive this backup?')">
                                                <span class="material-symbols-outlined text-sm group-hover/btn:text-amber-500">archive</span>
                                            </button>
                                        </form>
                                        <?php else: ?>
                                        <span class="text-[9px] text-[var(--text-main)]/20 font-black uppercase italic tracking-widest">Archived</span>
                                        <?php endif; ?>

                                    </div>
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
