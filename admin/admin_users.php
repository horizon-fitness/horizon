<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>User Management | Herdoza Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "#8c2bee", "background-dark": "#0a090d", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)"}}}
        }
        function updateHeaderClock() {
            const now = new Date();
            if(document.getElementById('sidebarClock')) document.getElementById('sidebarClock').textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
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
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        .nav-link { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; transition: all 0.2s; white-space: nowrap; }
        .active-nav { color: #8c2bee !important; position: relative; }
        .active-nav::after { content: ''; position: absolute; right: -32px; top: 0; width: 4px; height: 100%; background: #8c2bee; border-radius: 2px; }
        .nav-link:hover:not(.active-nav) { color: white; }
        
        .alert-dot { animation: pulse 2s infinite; }
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.4; } 100% { opacity: 1; } }
        .role-badge-admin { background: rgba(140, 43, 238, 0.1); color: #8c2bee; border: 1px solid rgba(140, 43, 238, 0.2); }
        .role-badge-coach { background: rgba(16, 185, 129, 0.1); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.2); }
        .role-badge-member { background: rgba(107, 114, 128, 0.1); color: #9ca3af; border: 1px solid rgba(107, 114, 128, 0.2); }
    </style>
</head>
<body class="antialiased flex flex-row min-h-screen">

<nav class="flex flex-col w-64 lg:w-72 bg-[#0a090d] border-r border-white/5 sticky top-0 h-screen p-8 z-50 shrink-0">
    <div class="mb-12">
        <div class="flex items-center gap-4 mb-4">
            <div class="size-10 rounded-xl bg-[#7f13ec] flex items-center justify-center shadow-lg shrink-0">
                <span class="material-symbols-outlined text-white text-2xl">bolt</span>
            </div>
            <h1 class="text-2xl font-black italic uppercase tracking-tighter text-white">Herdoza</h1>
        </div>
        <div class="p-4 rounded-2xl bg-white/5 border border-white/5">
            <p id="sidebarClock" class="text-white font-black italic text-xl leading-none mb-1">00:00:00 AM</p>
            <p class="text-primary text-[9px] font-black uppercase tracking-[0.2em]"><?= date('l, M d') ?></p>
        </div>
    </div>
    
    <div class="flex flex-col gap-5 flex-1 overflow-y-auto pr-2">
        <a href="admin_dashboard.php" class="nav-link flex items-center gap-3 text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl">grid_view</span> Dashboard
        </a>
        <a href="admin_users.php" class="nav-link active-nav text-primary flex items-center gap-3">
            <span class="material-symbols-outlined text-xl">group</span> My Users
        </a>
        <a href="admin_transaction.php" class="nav-link text-gray-400 hover:text-white flex items-center gap-3">
            <span class="material-symbols-outlined text-xl">receipt_long</span> Transactions
        </a>
        <a href="admin_transaction.php" class="nav-link text-gray-400 hover:text-white flex items-center gap-3">
            <span class="material-symbols-outlined text-xl">event_note</span> Bookings
        </a>
        <a href="admin_attendance.php" class="nav-link text-gray-400 hover:text-white flex items-center gap-3">
            <span class="material-symbols-outlined text-xl">history</span> Attendance
        </a>
        <a href="admin_appointment.php" class="nav-link text-gray-400 hover:text-white flex items-center gap-3">
            <span class="material-symbols-outlined text-xl">event_available</span> Appointment
        </a>
        <a href="admin_report.php" class="nav-link text-gray-400 hover:text-white flex items-center gap-3">
            <span class="material-symbols-outlined text-xl">description</span> Reports
        </a>
        
        <div class="mt-auto pt-8 border-t border-white/10">
            <a href="admin_profile.php" class="nav-link text-gray-400 hover:text-white flex items-center gap-3 mb-6">
                <span class="material-symbols-outlined text-xl">person</span> Profile
            </a>
            <a href="logout.php" class="text-gray-400 hover:text-red-500 transition-colors flex items-center gap-3 group">
                <span class="material-symbols-outlined group-hover:translate-x-1 transition-transform">logout</span>
                <span class="nav-link">Sign Out</span>
            </a>
        </div>
    </div>
</nav>

<div class="flex-1 flex flex-col min-w-0">
    <main class="flex-1 p-6 md:p-10 max-w-[1400px] w-full mx-auto">
        <header class="mb-10 flex flex-col md:flex-row justify-between items-end gap-4">
            <div>
                <h1 class="text-3xl font-black text-white italic uppercase tracking-tighter mb-1">User <span class="text-primary">Management</span></h1>
                <p class="text-primary text-xs font-bold uppercase tracking-widest">Master Database • Account Authority</p>
            </div>
            <div class="flex gap-3">
                 <a href="add_user.php" class="bg-white/5 border border-white/10 px-6 py-3 rounded-xl text-[10px] font-black uppercase hover:bg-white/10 transition-all flex items-center gap-2">
                    <span class="material-symbols-outlined text-sm">person_add</span> Add New User
                </a>
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
                        <?php if($users_q): while($row = mysqli_fetch_assoc($users_q)): 
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
                                <p class="text-gray-600 text-[10px] font-bold mt-0.5"><?= htmlspecialchars($row['phone_number'] ?? 'NO PHONE') ?></p>
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
                        <?php endwhile; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>
</body>
</html>