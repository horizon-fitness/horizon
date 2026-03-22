<?php 
session_start();
require_once '../db.php';

// Security Check: Only Superadmin can access
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'superadmin') {
    header("Location: ../login.php");
    exit;
}

$page_title = "Admin (Developer) System Backup";
$active_page = "backup"; 
$header_title = 'System <span class="text-primary">Backup</span>';
$header_subtitle = 'Data Protection & Restore Points';
$header_action = '<button class="h-10 px-8 rounded-xl bg-primary text-black text-[10px] font-black uppercase italic tracking-widest hover:scale-105 transition-transform">Run Manual Backup</button>';

// Mock Backup Data
$backups = [
    ['id' => 'BKP-001', 'date' => '2026-03-20 02:00:00', 'size' => '45.2 MB', 'status' => 'Successful', 'type' => 'Full System'],
    ['id' => 'BKP-002', 'date' => '2026-03-19 02:00:00', 'size' => '44.8 MB', 'status' => 'Successful', 'type' => 'Database Only'],
    ['id' => 'BKP-003', 'date' => '2026-03-18 02:00:00', 'size' => '44.5 MB', 'status' => 'Failed', 'type' => 'Full System'],
];

?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title><?= $page_title ?> | Horizon System</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        .sidebar-nav { width: 110px; transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1); overflow: hidden; }
        .sidebar-nav:hover { width: 300px; }
        .nav-text { opacity: 0; transform: translateX(-15px); transition: all 0.3s ease; white-space: nowrap; pointer-events: none; }
        .sidebar-nav:hover .nav-text { opacity: 1; transform: translateX(0); pointer-events: auto; }
        .nav-link { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; transition: all 0.2s; white-space: nowrap; }
        .active-nav { color: #8c2bee !important; position: relative; }
        .active-nav::after { content: ''; position: absolute; right: 0px; top: 50%; transform: translateY(-50%); width: 4px; height: 20px; background: #8c2bee; border-radius: 99px; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
    </style>
</head>
<body class="antialiased flex min-h-screen">

<?php include '../includes/superadmin_sidebar.php'; ?>
    
<div class="flex-1 flex flex-col min-w-0 overflow-y-auto">
    <main class="flex-1 p-8">
        <?php include '../includes/superadmin_header.php'; ?>
        <?php /* Hardcoded header replaced by include */ ?>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">
        <div class="glass-card p-6 border border-emerald-500/20 bg-emerald-500/5">
            <p class="text-[10px] font-black uppercase text-emerald-500/70 tracking-widest mb-1">Status</p>
            <h3 class="text-xl font-black text-white italic">Protected</h3>
            <p class="text-[9px] text-emerald-500/50 uppercase font-bold mt-2">Database + Files Secured</p>
        </div>
        <div class="glass-card p-6">
            <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Last Backup</p>
            <h3 class="text-xl font-black text-white italic">2 Hours Ago</h3>
            <p class="text-[9px] text-gray-600 uppercase font-bold mt-2">BKP-001</p>
        </div>
        <div class="glass-card p-6">
            <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Stored Files</p>
            <h3 class="text-xl font-black text-white italic">14 Backups</h3>
            <p class="text-[9px] text-gray-600 uppercase font-bold mt-2">Total 1.4 GB</p>
        </div>
    </div>

    <div class="glass-card overflow-hidden">
        <div class="px-8 py-6 border-b border-white/5 bg-white/[0.01]">
            <h3 class="text-lg font-black italic uppercase text-white tracking-tighter">Backup History</h3>
        </div>
        <table class="w-full text-left">
            <thead>
                <tr class="bg-white/[0.02]">
                    <th class="px-8 py-4 text-[10px] font-black uppercase tracking-widest text-gray-500">Backup ID</th>
                    <th class="px-8 py-4 text-[10px] font-black uppercase tracking-widest text-gray-500">Date & Time</th>
                    <th class="px-8 py-4 text-[10px] font-black uppercase tracking-widest text-gray-500">Type</th>
                    <th class="px-8 py-4 text-[10px] font-black uppercase tracking-widest text-gray-500 text-center">Size</th>
                    <th class="px-8 py-4 text-[10px] font-black uppercase tracking-widest text-gray-500">Status</th>
                    <th class="px-8 py-4 text-right pr-8 uppercase text-[10px] text-gray-500 font-black">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
                <?php foreach ($backups as $b): ?>
                <tr class="hover:bg-white/[0.01] transition-colors">
                    <td class="px-8 py-5 text-xs font-bold text-primary italic uppercase tracking-widest"><?= $b['id'] ?></td>
                    <td class="px-8 py-5 text-xs text-white font-medium"><?= date('M d, Y | h:i A', strtotime($b['date'])) ?></td>
                    <td class="px-8 py-5 text-[10px] text-gray-400 font-black uppercase italic"><?= $b['type'] ?></td>
                    <td class="px-8 py-5 text-xs text-center font-bold text-gray-300"><?= $b['size'] ?></td>
                    <td class="px-8 py-5">
                        <?php if ($b['status'] === 'Successful'): ?>
                            <span class="px-3 py-1 rounded-md bg-emerald-500/10 text-emerald-500 border border-emerald-500/20 text-[9px] font-black uppercase italic">Successful</span>
                        <?php else: ?>
                            <span class="px-3 py-1 rounded-md bg-red-500/10 text-red-500 border border-red-500/20 text-[9px] font-black uppercase italic">Failed</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-8 py-5 text-right">
                        <div class="inline-flex gap-2">
                            <button class="size-8 rounded-lg bg-white/5 hover:bg-white/10 border border-white/5 text-gray-400 flex items-center justify-center transition-all" title="Download Backup">
                                <span class="material-symbols-outlined text-sm">download</span>
                            </button>
                            <button class="size-8 rounded-lg bg-primary/10 hover:bg-primary/20 border border-primary/20 text-primary flex items-center justify-center transition-all" title="Restore Point">
                                <span class="material-symbols-outlined text-sm">settings_backup_restore</span>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</main>
    </main>
</div>
</body>
</html>
