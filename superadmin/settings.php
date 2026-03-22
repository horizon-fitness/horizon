<?php 
session_start();
require_once '../db.php';

// Security Check
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'superadmin') {
    header("Location: ../login.php");
    exit;
}

$page_title = "Admin (Developer) System Settings";
$active_page = "settings";
$header_title = 'System <span class="text-primary">Settings</span>';
$header_subtitle = 'Global Configuration & Control';

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    // Logic to update your 'system_settings' table would go here
    $success_msg = "System configurations updated successfully!";
}
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
        .sidebar-nav { width: 100px; transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1); overflow: hidden; }
        .sidebar-nav:hover { width: 280px; }
        .nav-text { opacity: 0; transform: translateX(-10px); transition: all 0.2s ease; white-space: nowrap; pointer-events: none; }
        .sidebar-nav:hover .nav-text { opacity: 1; transform: translateX(0); pointer-events: auto; }
        .nav-link { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; transition: all 0.2s; white-space: nowrap; }
        .active-nav { color: #8c2bee !important; position: relative; }
        .active-nav::after { content: ''; position: absolute; right: 0px; top: 50%; transform: translateY(-50%); width: 4px; height: 20px; background: #8c2bee; border-radius: 99px; }
        .input-field { background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.05); border-radius: 12px; padding: 12px 16px; color: white; font-size: 13px; transition: all 0.2s; }
        .input-field:focus { border-color: #8c2bee; outline: none; background: rgba(140,43,238,0.05); }
        .no-scrollbar::-webkit-scrollbar { display: none; }
    </style>
</head>
<body class="antialiased flex flex-row min-h-screen">

<?php include '../includes/superadmin_sidebar.php'; ?>

<div class="flex-1 flex flex-col min-w-0 overflow-y-auto">
    <main class="flex-1 p-6 md:p-10 max-w-[1200px] w-full mx-auto">

        <?php include '../includes/superadmin_header.php'; ?>

        <?php if (isset($success_msg)): ?>
            <div class="mb-6 p-4 bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-[10px] font-black uppercase tracking-widest rounded-2xl flex items-center gap-3">
                <span class="material-symbols-outlined text-sm">check_circle</span> <?= $success_msg ?>
            </div>
        <?php endif; ?>

        <form action="" method="POST" class="space-y-8">
            <div class="glass-card p-8">
                <div class="flex items-center gap-4 mb-8">
                    <div class="size-10 rounded-xl bg-primary/10 flex items-center justify-center">
                        <span class="material-symbols-outlined text-primary">palette</span>
                    </div>
                    <div>
                        <h3 class="text-sm font-black italic uppercase tracking-widest text-white">System Branding</h3>
                        <p class="text-[10px] text-gray-500 font-bold uppercase">Customize names and visuals</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="flex flex-col gap-2">
                        <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Platform Name</label>
                        <input type="text" name="system_name" class="input-field" placeholder="e.g. Horizon System">
                    </div>
                    <div class="flex flex-col gap-2">
                        <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Admin Email Notice</label>
                        <input type="email" name="admin_email" class="input-field" placeholder="admin@horizonsystem.com">
                    </div>
                </div>
            </div>

            <div class="glass-card p-8">
                <div class="flex items-center gap-4 mb-8">
                    <div class="size-10 rounded-xl bg-primary/10 flex items-center justify-center">
                        <span class="material-symbols-outlined text-primary">gavel</span>
                    </div>
                    <div>
                        <h3 class="text-sm font-black italic uppercase tracking-widest text-white">Tenant Limits & Rules</h3>
                        <p class="text-[10px] text-gray-500 font-bold uppercase">Global restrictions for gym owners</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="flex flex-col gap-2">
                        <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Max Staff per Tenant</label>
                        <input type="number" name="max_staff" class="input-field" value="10">
                    </div>
                    <div class="flex flex-col gap-2">
                        <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Grace Period (Days)</label>
                        <input type="number" name="grace_period" class="input-field" value="7">
                    </div>
                    <div class="flex flex-col gap-2">
                        <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">New Tenant Status</label>
                        <select name="default_status" class="input-field appearance-none">
                            <option value="Pending">Pending Approval</option>
                            <option value="Active">Auto-Activate</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="glass-card p-8 border-dashed border-primary/20 bg-transparent">
                <div class="flex flex-col md:flex-row justify-between items-center gap-6">
                    <div class="flex items-center gap-4">
                        <div class="size-10 rounded-xl bg-white/5 flex items-center justify-center">
                            <span class="material-symbols-outlined text-gray-400">admin_panel_settings</span>
                        </div>
                        <div>
                            <h3 class="text-sm font-black italic uppercase tracking-widest text-white">User Roles & Permissions</h3>
                            <p class="text-[10px] text-gray-500 font-bold uppercase">Advanced access control settings</p>
                        </div>
                    </div>
                    <a href="rbac_management.php" class="px-6 py-2 border border-white/10 rounded-xl text-[10px] font-black uppercase tracking-widest hover:bg-white/5 transition-all">Configure Roles</a>
                </div>
            </div>

            <div class="flex justify-end">
                <button type="submit" name="save_settings" class="bg-primary text-black px-10 py-3 rounded-xl text-[10px] font-black uppercase tracking-widest hover:scale-105 transition-transform shadow-[0_0_30px_rgba(140,43,238,0.2)]">
                    Save Configurations
                </button>
            </div>
        </form>
    </main>
</div>

</body>
</html>