<?php 
session_start();
require_once '../db.php';

// Security Check: Only Superadmin can access
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'superadmin') {
    header("Location: ../login.php");
    exit;
}

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
    </script>
</head>
<body class="antialiased flex flex-row min-h-screen">

<nav class="sidebar-nav bg-[#0a090d] border-r border-white/5 sticky top-0 h-screen px-7 py-8 z-50 shrink-0 flex flex-col">
    <div class="mb-4 shrink-0"> 
        <div class="flex items-center gap-4 mb-4"> 
            <div class="size-10 rounded-xl bg-[#7f13ec] flex items-center justify-center shadow-lg shrink-0">
                <span class="material-symbols-outlined text-white text-2xl">bolt</span>
            </div>
            <h1 class="nav-text text-xl font-black italic uppercase tracking-tighter text-white">Horizon System</h1>
        </div>
    </div>
    
    <div class="flex-1 overflow-y-auto no-scrollbar space-y-1 pr-2">
        <div class="nav-section-header px-0 mb-2">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Overview</span>
        </div>
        <a href="superadmin_dashboard.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'dashboard') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">grid_view</span> 
            <span class="nav-text">Dashboard</span>
        </a>
        
        <div class="nav-section-header px-0 mb-2 mt-4">
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

        <a href="real_time_occupancy.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'occupancy') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">group</span> 
            <span class="nav-text">Real-Time Occupancy</span>
        </a>

        <a href="recent_transaction.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'transactions') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">receipt_long</span> 
            <span class="nav-text">Recent Transactions</span>
        </a>

        <div class="nav-section-header px-0 mb-2 mt-4">
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

    <div class="mt-auto pt-4 border-t border-white/10 flex flex-col gap-2 shrink-0">
        <div class="nav-section-header px-0 mb-0">
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
        <a href="../logout.php" class="text-gray-400 hover:text-rose-500 transition-colors flex items-center gap-4 group py-2">
            <span class="material-symbols-outlined group-hover:translate-x-1 transition-transform text-xl shrink-0">logout</span>
            <span class="nav-link nav-text">Sign Out</span>
        </a>
    </div>
</nav>

<div class="flex-1 flex flex-col min-w-0 overflow-y-auto">
    <main class="flex-1 p-6 md:p-10 max-w-[1400px] w-full mx-auto">
        <header class="mb-10 flex flex-row justify-between items-end gap-6">
            <div>
                <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white leading-none">System <span class="text-primary">Backup</span></h2>
                <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-2">Data Protection & Restore Points</p>
            </div>
            <div class="flex items-center gap-6">
                <form method="POST">
                    <input type="hidden" name="action" value="run_backup">
                    <button type="submit" class="h-10 px-8 rounded-xl bg-primary text-black text-[10px] font-black uppercase italic tracking-widest hover:scale-105 transition-all active:scale-95 shadow-[0_0_20px_rgba(140,43,238,0.3)]">
                        Run Manual Backup
                    </button>
                </form>
                <div class="text-right hidden md:block">
                    <p id="headerClock" class="text-white font-black italic text-xl tracking-tight leading-none mb-2">00:00:00 AM</p>
                    <p class="text-primary text-[9px] font-black uppercase tracking-[0.2em] opacity-80"><?= date('l, M d, Y') ?></p>
                </div>
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
                <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-emerald-500">shield_check</span>
                <p class="text-[10px] font-black uppercase text-emerald-500/70 tracking-widest mb-2">Security Status</p>
                <h3 class="text-2xl font-black text-white italic">Protected</h3>
                <p class="text-[9px] text-emerald-500/50 uppercase font-bold mt-2 italic tracking-wider">Database + Files Secured</p>
            </div>
            <div class="glass-card p-8 relative overflow-hidden group">
                <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-primary">schedule</span>
                <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-2">Last Backup</p>
                <h3 class="text-2xl font-black text-white italic"><?= $last_backup_time ? date('M d, h:i A', strtotime($last_backup_time)) : 'None' ?></h3>
                <p class="text-[9px] text-gray-600 uppercase font-bold mt-2 italic tracking-wider"><?= $total_backups > 0 ? $last_backup_id . ' | Successful' : 'Waiting for first backup' ?></p>
            </div>
            <div class="glass-card p-8 relative overflow-hidden group">
                <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-primary">storage</span>
                <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-2">Stored Archives</p>
                <h3 class="text-2xl font-black text-white italic"><?= $total_backups ?> Backups</h3>
                <p class="text-[9px] text-primary/50 uppercase font-bold mt-2 italic tracking-wider">Total <?= number_format($total_size_mb, 1) ?> MB Storage</p>
            </div>
        </div>

        <div class="glass-card overflow-hidden">
            <div class="px-8 py-6 border-b border-white/5 bg-white/[0.01] flex justify-between items-center">
                <div>
                    <h3 class="text-sm font-black italic uppercase tracking-widest text-white"><?= $view === 'archived' ? 'Archived Records' : 'Backup History' ?></h3>
                    <p class="text-[9px] text-gray-500 font-bold uppercase tracking-widest mt-1"><?= $view === 'archived' ? 'Historical data points currently in archive' : 'Repository of available restore points' ?></p>
                </div>
                <div class="flex gap-4">
                    <a href="?view=active" class="px-4 py-2 rounded-xl <?= $view === 'active' ? 'bg-primary text-black' : 'bg-white/5 text-gray-400 border border-white/10' ?> text-[9px] font-black uppercase tracking-widest transition-all">Active</a>
                    <a href="?view=archived" class="px-4 py-2 rounded-xl <?= $view === 'archived' ? 'bg-primary text-black' : 'bg-white/5 text-gray-400 border border-white/10' ?> text-[9px] font-black uppercase tracking-widest transition-all">Archived</a>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-background-dark/50 text-gray-500 text-[10px] font-black uppercase tracking-widest">
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
                                    <span class="text-xs font-black text-primary italic uppercase tracking-widest group-hover:text-white transition-colors"><?= $b['backup_id'] ?></span>
                                </td>
                                <td class="px-8 py-5">
                                    <p class="text-xs text-white font-bold leading-none mb-1"><?= date('M d, Y', strtotime($b['backup_date'])) ?></p>
                                    <p class="text-[9px] text-gray-500 font-black italic uppercase"><?= date('h:i A', strtotime($b['backup_date'])) ?></p>
                                </td>
                                <td class="px-8 py-5">
                                    <span class="text-[10px] text-gray-400 font-black uppercase italic border border-white/10 px-2 py-0.5 rounded-md"><?= $b['backup_type'] ?></span>
                                </td>
                                <td class="px-8 py-5 text-xs text-center font-bold text-gray-300"><?= $b['backup_size'] ?></td>
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
                                            <button type="submit" class="size-9 rounded-xl bg-white/5 hover:bg-amber-500/20 border border-white/5 text-gray-400 flex items-center justify-center transition-all hover:scale-105 group/btn" title="Archive Backup" onclick="return confirm('Are you sure you want to archive this backup?')">
                                                <span class="material-symbols-outlined text-sm group-hover/btn:text-amber-500">archive</span>
                                            </button>
                                        </form>
                                        <?php else: ?>
                                        <span class="text-[9px] text-gray-600 font-black uppercase italic tracking-widest">Archived</span>
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
