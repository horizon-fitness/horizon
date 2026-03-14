<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Coach Profile | Herdoza Fitness</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "#8c2bee", "background-dark": "#0a090d", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)" } } }
        }
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; padding-bottom: 100px; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        .nav-link { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; transition: all 0.2s; white-space: nowrap; }
        .active-nav { color: #8c2bee !important; position: relative; }
        .active-nav::after { content: ''; position: absolute; right: -32px; top: 0; width: 4px; height: 100%; background: #8c2bee; border-radius: 2px; }
        @media (max-width: 1024px) { .active-nav::after { width: 100%; height: 3px; bottom: -8px; left: 0; top: auto; right: auto; } }
        .modal-input { background-color: #0a090d !important; border: 1px solid rgba(255,255,255,0.1) !important; color: #ffffff !important; }
        .modal-input::placeholder { color: #666 !important; }
        .mobile-taskbar { background: rgba(20, 18, 26, 0.9); backdrop-filter: blur(20px); border-top: 1px solid rgba(255,255,255,0.05); }
        .alert-dot { animation: pulse 2s infinite; }
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.4; } 100% { opacity: 1; } }
    </style>
    <script>
        function updateHeaderClock() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            if(document.getElementById('headerClock')) document.getElementById('headerClock').textContent = timeString;
            if(document.getElementById('sidebarClock')) document.getElementById('sidebarClock').textContent = timeString;
        }
        window.onload = function() {
            updateHeaderClock();
            setInterval(updateHeaderClock, 1000);
        };
    </script>
</head>
<body class="antialiased flex flex-col lg:flex-row min-h-screen">

    <nav class="hidden lg:flex flex-col w-72 bg-[#0a090d] border-r border-white/5 sticky top-0 h-screen p-8 z-50 shrink-0">
        <div class="flex items-center gap-4 mb-12">
            <div class="size-10 rounded-xl bg-primary flex items-center justify-center shadow-lg shrink-0">
                <span class="material-symbols-outlined text-white text-2xl">bolt</span>
            </div>
            <h1 class="text-xl font-black italic uppercase tracking-tighter text-white">Herdoza <span class="text-primary">Coach</span></h1>
        </div>
        
        <div class="flex flex-col gap-8 flex-1">
            <a href="coach_dashboard.php" class="nav-link text-gray-400 hover:text-white flex items-center gap-3">
                <span class="material-symbols-outlined text-xl">grid_view</span>
                Work Schedule
                <?php if($pending_count > 0): ?><span class="size-1.5 rounded-full bg-primary alert-dot"></span><?php endif; ?>
            </a>
            <a href="coach_schedule.php" class="nav-link text-gray-400 hover:text-white flex items-center gap-3">
                <span class="material-symbols-outlined text-xl">edit_calendar</span>
                Availability
            </a>
            <a href="coach_members.php" class="nav-link text-gray-400 hover:text-white flex items-center gap-3">
                <span class="material-symbols-outlined text-xl">groups</span>
                Members
            </a>
            <a href="coach_workouts.php" class="nav-link text-gray-400 hover:text-white flex items-center gap-3">
                <span class="material-symbols-outlined text-xl">fitness_center</span>
                Workouts
            </a>
            <a href="coach_profile.php" class="nav-link active-nav flex items-center gap-3">
                <span class="material-symbols-outlined text-xl">person</span>
                Profile
            </a>
            
            <div class="mt-auto pt-8 border-t border-white/10">
                <div class="flex items-center gap-3 mb-8">
                    <div class="size-10 rounded-full bg-white/5 flex items-center justify-center border border-primary transition-all group">
                        <span class="material-symbols-outlined text-primary text-xl">person</span>
                    </div>
                    <div class="overflow-hidden">
                        <p id="sidebarClock" class="text-white font-black italic text-[11px] leading-none mb-1">00:00:00 AM</p>
                        <p class="text-[9px] font-black uppercase text-primary leading-none truncate">@<?= htmlspecialchars($_SESSION['username'] ?? 'coach') ?></p>
                    </div>
                </div>

                <a href="logout.php" class="text-gray-400 hover:text-red-500 transition-colors flex items-center gap-3 group">
                    <span class="material-symbols-outlined group-hover:translate-x-1 transition-transform">logout</span>
                    <span class="nav-link">Sign Out</span>
                </a>
            </div>
        </div>
    </nav>

    <div class="fixed bottom-0 left-0 right-0 h-20 mobile-taskbar z-[100] lg:hidden flex items-center justify-around px-4">
        <a href="dashboard_coach.php" class="flex flex-col items-center gap-1 text-gray-500"><span class="material-symbols-outlined">calendar_month</span><span class="text-[8px] font-black uppercase">Schedule</span></a>
        <a href="coach_schedule.php" class="flex flex-col items-center gap-1 text-gray-500"><span class="material-symbols-outlined">edit_calendar</span><span class="text-[8px] font-black uppercase">Avail</span></a>
        <a href="coach_members.php" class="flex flex-col items-center gap-1 text-gray-500"><span class="material-symbols-outlined">groups</span><span class="text-[8px] font-black uppercase">Members</span></a>
        <a href="coach_workouts.php" class="flex flex-col items-center gap-1 text-gray-500"><span class="material-symbols-outlined">fitness_center</span><span class="text-[8px] font-black uppercase">Workouts</span></a>
        <a href="coach_profile.php" class="flex flex-col items-center gap-1 text-primary"><span class="material-symbols-outlined">person</span><span class="text-[8px] font-black uppercase">Profile</span></a>
    </div>

    <main class="flex-1 max-w-[1600px] p-8 lg:p-12 overflow-x-hidden">
        <header class="flex flex-col md:flex-row md:items-end justify-between gap-6 mb-12">
            <div>
                <h2 class="text-4xl font-black italic uppercase tracking-tighter">Coach <span class="text-primary">Profile</span></h2>
                <p class="text-gray-500 font-bold uppercase tracking-[0.2em] text-xs">Manage your trainer identity</p>
            </div>
            <div class="flex items-center gap-4 bg-surface-dark border border-border-subtle p-2 rounded-2xl">
                <div class="size-12 rounded-xl bg-gradient-to-br from-primary to-purple-600 flex items-center justify-center text-xl font-black italic">
                    <?= substr($user['fullname'] ?? 'C', 0, 1) ?>
                </div>
                <div class="pr-4">
                    <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest">Role</p>
                    <p class="text-xs font-bold uppercase text-primary"><?= htmlspecialchars($role) ?></p>
                </div>
            </div>
        </header>

        <?php if(isset($success_msg)): ?><div class="bg-emerald-500/10 border border-emerald-500/20 p-4 rounded-xl text-emerald-500 text-[10px] font-black uppercase italic mb-8 flex items-center gap-2"><span class="material-symbols-outlined text-sm">check_circle</span> <?= $success_msg ?></div><?php endif; ?>
        <?php if(isset($error_msg)): ?><div class="bg-red-500/10 border border-red-500/20 p-4 rounded-xl text-red-500 text-[10px] font-black uppercase italic mb-8 flex items-center gap-2"><span class="material-symbols-outlined text-sm">error</span> <?= $error_msg ?></div><?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <div class="lg:col-span-1 space-y-6">
                <div class="bg-surface-dark border border-border-subtle rounded-[32px] p-8 shadow-xl">
                    <h3 class="text-sm font-black uppercase tracking-widest mb-8 flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary text-lg">badge</span> Coach Info
                    </h3>
                    <div class="space-y-6">
                        <div>
                            <label class="text-[9px] font-black uppercase text-gray-500">Full Name</label>
                            <p class="text-lg font-bold italic"><?= htmlspecialchars($user['fullname']) ?></p>
                        </div>
                        <div>
                            <label class="text-[9px] font-black uppercase text-gray-500">Username</label>
                            <p class="text-sm font-medium text-primary">@<?= htmlspecialchars($user['username']) ?></p>
                        </div>
                        <div>
                            <label class="text-[9px] font-black uppercase text-gray-500">Email</label>
                            <p class="text-sm font-medium text-gray-300"><?= htmlspecialchars($user['email']) ?></p>
                        </div>
                        <div>
                            <label class="text-[9px] font-black uppercase text-gray-500">Phone Number</label>
                            <p class="text-sm font-medium text-gray-300"><?= htmlspecialchars($user['phone_number']) ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="lg:col-span-2 space-y-6">
                <div class="bg-surface-dark border border-border-subtle rounded-[32px] p-8 shadow-xl">
                    <h3 class="text-sm font-black uppercase tracking-widest mb-8">Security & Password</h3>
                    <div class="flex items-center justify-between p-6 bg-background-dark rounded-2xl border border-white/5">
                        <div class="flex items-center gap-4">
                            <span class="material-symbols-outlined text-gray-500">lock</span>
                            <div>
                                <p class="text-xs font-bold italic tracking-tight uppercase">Change Password</p>
                                <p class="text-[9px] text-gray-600 font-bold uppercase tracking-widest">Update your login credentials</p>
                            </div>
                        </div>
                        <button onclick="toggleModal('passwordModal')" class="bg-primary hover:bg-primary/90 text-white px-6 py-2 rounded-xl text-[9px] font-black uppercase tracking-widest transition-all">Update</button>
                    </div>
                </div>
                <a href="logout.php" class="flex items-center justify-center gap-2 w-full py-5 bg-red-500/5 hover:bg-red-500/10 border border-red-500/10 text-red-500 rounded-[24px] text-[10px] font-black uppercase tracking-widest transition-all">
                    <span class="material-symbols-outlined text-sm">logout</span> Sign out of Session
                </a>
            </div>
        </div>
    </main>

    <div id="passwordModal" class="fixed inset-0 z-[120] hidden items-center justify-center bg-black/80 backdrop-blur-sm p-4">
        <div class="bg-surface-dark w-full max-w-md rounded-[32px] p-8 border border-white/10 shadow-2xl">
            <h3 class="text-xl font-black italic uppercase mb-6">Update <span class="text-primary">Password</span></h3>
            <form method="POST" class="space-y-4">
                <div class="relative">
                    <input type="password" name="new_password" id="new_password" placeholder="New Password" required class="modal-input w-full p-4 rounded-xl outline-none pr-12">
                    <button type="button" onclick="togglePassword('new_password', this)" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-500 hover:text-white transition-colors">
                        <span class="material-symbols-outlined text-xl">visibility</span>
                    </button>
                </div>
                <div class="relative">
                    <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm New Password" required class="modal-input w-full p-4 rounded-xl outline-none pr-12">
                    <button type="button" onclick="togglePassword('confirm_password', this)" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-500 hover:text-white transition-colors">
                        <span class="material-symbols-outlined text-xl">visibility</span>
                    </button>
                </div>
                <button type="submit" name="update_password" class="w-full py-4 bg-primary rounded-2xl font-black uppercase italic tracking-widest text-white shadow-lg hover:scale-105 transition-all">Update Password</button>
                <button type="button" onclick="toggleModal('passwordModal')" class="w-full text-[9px] font-black text-gray-500 uppercase mt-2 hover:text-white transition-colors">Cancel</button>
            </form>
        </div>
    </div>

    <script>
        function toggleModal(id) {
            const modal = document.getElementById(id);
            modal.classList.toggle('hidden');
            modal.classList.toggle('flex');
        }

        function togglePassword(inputId, btn) {
            const input = document.getElementById(inputId);
            const icon = btn.querySelector('.material-symbols-outlined');
            if (input.type === "password") {
                input.type = "text";
                icon.textContent = "visibility_off";
            } else {
                input.type = "password";
                icon.textContent = "visibility";
            }
        }
    </script>
</body>
</html>