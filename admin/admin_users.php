<?php
session_start();
require_once '../db.php';

// Security Check
$role = strtolower($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || ($role !== 'staff' && $role !== 'coach')) {
    header("Location: ../login.php");
    exit;
}

$gym_id = $_SESSION['gym_id'];
$user_id = $_SESSION['user_id'];

// Fetch Gym Details
$stmtGym = $pdo->prepare("SELECT * FROM gyms WHERE gym_id = ?");
$stmtGym->execute([$gym_id]);
$gym = $stmtGym->fetch();

// Active CMS Page (for logo)
$stmtPage = $pdo->prepare("SELECT * FROM tenant_pages WHERE gym_id = ? LIMIT 1");
$stmtPage->execute([$gym_id]);
$page = $stmtPage->fetch();

$stmtUsers = $pdo->prepare("SELECT u.user_id as id, CONCAT(u.first_name, ' ', u.last_name) as fullname, u.username, u.email, u.contact_number, r.role_name as role, u.created_at FROM users u JOIN user_roles ur ON u.user_id = ur.user_id JOIN roles r ON ur.role_id = r.role_id ORDER BY u.created_at DESC");
$stmtUsers->execute();
$users_list = $stmtUsers->fetchAll();
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>User Management | Horizon Partners</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "<?= $page['theme_color'] ?? '#8c2bee' ?>", "background-dark": "<?= $page['bg_color'] ?? '#0a090d' ?>", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)" }}}
        }
        function updateHeaderClock() {
            const now = new Date();
            const clockEl = document.getElementById('headerClock');
            if (clockEl) {
                clockEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            }
        }
        window.onload = function() { setInterval(updateHeaderClock, 1000); updateHeaderClock(); };
            
            function switchTab(role) {
            document.querySelectorAll('.user-row').forEach(row => {
                if (role === 'all' || row.getAttribute('data-role') === role) {
                    row.style.display = 'table-row';
                } else {
                    row.style.display = 'none';
                }
            });
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('text-primary', 'border-primary');
                btn.classList.add('text-gray-500', 'border-transparent');
            });
            event.currentTarget.classList.add('text-primary', 'border-primary');
            event.currentTarget.classList.remove('text-gray-500', 'border-transparent');
        }
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: <?= $page['bg_color'] ?? '#0a090d' ?>; color: white; display: flex; flex-direction: row; min-h-screen: 100vh; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        
        /* Sidebar Hover Logic - MATCHING CORE DASHBOARD */
        .side-nav {
            width: 110px; 
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            position: fixed;
            left: 0;
            top: 0;
            height: 100vh;
            z-index: 50;
        }
        .side-nav:hover {
            width: 300px; 
        }

        /* Main Content Adjustment */
        .main-content {
            margin-left: 110px; 
            flex: 1;
            min-width: 0;
            transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .side-nav:hover ~ .main-content {
            margin-left: 300px; 
        }

        .nav-label {
            opacity: 0;
            transform: translateX(-15px);
            transition: all 0.3s ease-in-out;
            white-space: nowrap;
            pointer-events: none;
        }
        .side-nav:hover .nav-label {
            opacity: 1;
            transform: translateX(0);
            pointer-events: auto;
        }

        .nav-section-label {
            max-height: 0;
            opacity: 0;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            margin: 0 !important;
            pointer-events: none;
        }
        .side-nav:hover .nav-section-label {
            max-height: 20px;
            opacity: 1;
            margin-bottom: 0px !important; 
            pointer-events: auto;
        }
        .side-nav:hover .mt-0 { margin-top: 0px !important; } 

        .nav-item { 
            display: flex; 
            align-items: center; 
            gap: 16px; 
            padding: 10px 38px; 
            transition: all 0.2s ease; 
            text-decoration: none; 
            white-space: nowrap; 
            font-size: 13px; 
            font-weight: 700; 
            letter-spacing: 0.02em; 
            color: #94a3b8;
        }
        .nav-item:hover { background: rgba(255,255,255,0.05); color: white; }
        .nav-item.active { color: <?= $page['theme_color'] ?? '#8c2bee' ?> !important; position: relative; }
        .nav-item.active::after { 
            content: ''; 
            position: absolute; 
            right: 0px; 
            top: 50%;
            transform: translateY(-50%);
            width: 4px; 
            height: 24px; 
            background: <?= $page['theme_color'] ?? '#8c2bee' ?>; 
            border-radius: 4px 0 0 4px; 
        }
        
        .alert-dot { animation: pulse 2s infinite; }
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.4; } 100% { opacity: 1; } }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>
<body class="antialiased min-h-screen">

<nav class="side-nav flex flex-col fixed left-0 top-0 h-screen bg-background-dark border-r border-white/5 z-50">
    <div class="px-7 py-8">
        <div class="flex items-center gap-[6px]">
            <div class="size-10 rounded-xl bg-primary flex items-center justify-center shadow-lg shrink-0 overflow-hidden">
                <?php if (!empty($page['logo_path'])): 
                    $logo_src = (strpos($page['logo_path'], 'data:image') === 0) ? $page['logo_path'] : '../' . $page['logo_path'];
                ?>
                    <img src="<?= $logo_src ?>" class="size-full object-contain">
                <?php else: ?>
                    <span class="material-symbols-outlined text-white text-2xl">bolt</span>
                <?php endif; ?>
            </div>
            <span class="nav-label text-white font-black italic uppercase tracking-tighter text-base leading-none">Staff Portal</span>
        </div>
    </div>
    
    <div class="flex flex-col flex-1 overflow-y-auto no-scrollbar gap-0.5">
        <span class="nav-section-label text-[10px] font-black text-gray-500 uppercase tracking-widest px-[38px] mt-0">Main Menu</span>
        
        <a href="admin_dashboard.php" class="nav-item <?= (basename($_SERVER['PHP_SELF']) == 'admin_dashboard.php') ? 'active' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">grid_view</span>
            <span class="nav-label">Dashboard</span>
        </a>

        <a href="register_member.php" class="nav-item <?= (basename($_SERVER['PHP_SELF']) == 'register_member.php') ? 'active' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">person_add</span>
            <span class="nav-label">Walk-in Member</span>
        </a>

        <span class="nav-section-label text-[10px] font-black text-gray-500 uppercase tracking-widest px-[38px] mt-4 mb-2">Management</span>
        
        <a href="admin_users.php" class="nav-item <?= (basename($_SERVER['PHP_SELF']) == 'admin_users.php') ? 'active' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">group</span>
            <span class="nav-label">My Users</span>
        </a>
        
        <a href="admin_transaction.php" class="nav-item <?= (basename($_SERVER['PHP_SELF']) == 'admin_transaction.php') ? 'active' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">receipt_long</span>
            <span class="nav-label">Transactions</span>
        </a>

        <a href="admin_appointment.php" class="nav-item <?= (basename($_SERVER['PHP_SELF']) == 'admin_appointment.php') ? 'active' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">event_note</span>
            <span class="nav-label">Bookings</span>
        </a>

        <a href="admin_attendance.php" class="nav-item <?= (basename($_SERVER['PHP_SELF']) == 'admin_attendance.php') ? 'active' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">history</span>
            <span class="nav-label">Attendance</span>
        </a>

        <a href="admin_report.php" class="nav-item <?= (basename($_SERVER['PHP_SELF']) == 'admin_report.php') ? 'active' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">description</span>
            <span class="nav-label">Reports</span>
        </a>
    </div>

    <div class="mt-auto pt-4 border-t border-white/10 flex flex-col gap-1 shrink-0 pb-6">
        <span class="nav-section-label text-[10px] font-black text-gray-500 uppercase tracking-widest px-[38px] mt-0 mb-2">Account</span>

        <a href="admin_profile.php" class="nav-item <?= (basename($_SERVER['PHP_SELF']) == 'admin_profile.php') ? 'active' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">account_circle</span>
            <span class="nav-label">Profile</span>
        </a>

        <a href="../logout.php" class="nav-item text-gray-400 hover:text-rose-500 transition-colors group">
            <span class="material-symbols-outlined text-xl shrink-0 group-hover:translate-x-1 transition-transform">logout</span>
            <span class="nav-label">Sign Out</span>
        </a>
    </div>
</nav>

<div class="main-content flex flex-col min-w-0">
    <main class="flex-1 p-6 md:p-10 max-w-[1400px] w-full mx-auto">
        <header class="mb-10 flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
            <div>
                <h1 class="text-3xl font-black text-white italic uppercase tracking-tighter leading-none mb-2">User <span class="text-primary">Management</span></h1>
                <p class="text-gray-500 text-xs font-bold uppercase tracking-widest">Master Database • Account Authority</p>
            </div>
            <div class="flex flex-row items-center gap-4">
                 <a href="add_user.php" class="bg-primary hover:bg-primary/90 text-white px-6 py-4 rounded-2xl text-[10px] font-black uppercase tracking-widest transition-all shadow-lg active:scale-95 flex items-center gap-2">
                    <span class="material-symbols-outlined text-sm">person_add</span> Add New User
                </a>
                <div class="px-6 py-3.5 rounded-2xl bg-white/5 border border-white/5 text-right flex flex-col items-end group shadow-sm hover:shadow-primary/10 transition-shadow">
                    <p id="headerClock" class="text-white font-black italic text-xl leading-none mb-1 group-hover:text-primary transition-colors">00:00:00 AM</p>
                    <p class="text-gray-500 text-[9px] font-black uppercase tracking-[0.2em] group-hover:text-white transition-colors"><?= date('l, M d') ?></p>
                </div>
            </div>
        </header>

        <div class="flex gap-8 mb-6 border-b border-white/5">
            <button onclick="switchTab('all')" class="tab-btn pb-4 text-[10px] font-black uppercase tracking-widest border-b-2 border-primary text-primary transition-all">All Users</button>
            <button onclick="switchTab('coach')" class="tab-btn pb-4 text-[10px] font-black uppercase tracking-widest border-b-2 border-transparent text-gray-500 hover:text-white transition-all">Coaches</button>
            <button onclick="switchTab('member')" class="tab-btn pb-4 text-[10px] font-black uppercase tracking-widest border-b-2 border-transparent text-gray-500 hover:text-white transition-all">Members</button>
        </div>

        <div class="glass-card overflow-hidden shadow-2xl">
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="text-[10px] font-black uppercase text-gray-500 tracking-widest bg-white/5 border-b border-white/5">
                            <th class="px-8 py-5">System ID</th>
                            <th class="px-8 py-5">Identity Info</th>
                            <th class="px-8 py-5">Contact Details</th>
                            <th class="px-8 py-5">Status / Role</th>
                            <th class="px-8 py-5 text-right">Control</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <?php if($users_list): foreach($users_list as $row): 
                            $roleClean = strtolower($row['role'] ?? 'member');
                            $roleClass = "role-badge-" . $roleClean;
                        ?>
                        <tr class="user-row hover:bg-white/[0.02] transition-colors" data-role="<?= $roleClean ?>">
                            <td class="px-8 py-6 text-gray-500 font-bold text-xs font-mono">#<?= str_pad($row['id'], 3, "0", STR_PAD_LEFT) ?></td>
                            <td class="px-8 py-6">
                                <p class="text-white font-black uppercase italic text-sm"><?= htmlspecialchars($row['fullname']) ?></p>
                                <p class="text-primary text-[10px] font-black">@<?= htmlspecialchars($row['username']) ?></p>
                            </td>
                            <td class="px-8 py-6">
                                <p class="text-gray-300 text-xs font-medium"><?= htmlspecialchars($row['email']) ?></p>
                                <p class="text-gray-600 text-[10px] font-bold mt-0.5"><?= htmlspecialchars($row['contact_number'] ?? 'NO PHONE') ?></p>
                            </td>
                            <td class="px-8 py-6">
                                <span class="px-3 py-1 rounded-lg text-[9px] font-black uppercase italic <?= $roleClass ?>">
                                    <?= $row['role'] ?>
                                </span>
                            </td>
                            <td class="px-8 py-6 text-right">
                                <div class="flex justify-end gap-2">
                                    <button class="size-8 rounded-lg bg-white/5 flex items-center justify-center hover:bg-primary transition-all group">
                                        <span class="material-symbols-outlined text-sm text-gray-500 group-hover:text-white">edit</span>
                                    </button>
                                    <form method="POST" onsubmit="return confirm('Are you sure?');">
                                        <input type="hidden" name="user_id" value="<?= $row['id'] ?>">
                                        <button type="submit" name="delete_user" class="size-8 rounded-lg bg-white/5 flex items-center justify-center hover:bg-red-500/20 transition-all group">
                                            <span class="material-symbols-outlined text-sm text-gray-500 group-hover:text-red-500">delete</span>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>
</body>
</html>