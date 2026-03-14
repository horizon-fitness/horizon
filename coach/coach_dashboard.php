<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Coach Portal | Herdoza Fitness</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "#8c2bee", "background-dark": "#0a090d", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)" } } }
        }
        function updateHeaderClock() {
            const now = new Date();
            if(document.getElementById('headerClock')) document.getElementById('headerClock').textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }
        window.onload = function() { setInterval(updateHeaderClock, 1000); updateHeaderClock(); };
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; padding-bottom: 100px; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        .nav-link { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; transition: all 0.2s; white-space: nowrap; }
        .active-nav { color: #8c2bee !important; position: relative; }
        .active-nav::after { content: ''; position: absolute; right: -24px; top: 0; width: 4px; height: 100%; background: #8c2bee; border-radius: 2px; }
        @media (max-width: 1024px) { .active-nav::after { width: 100%; height: 3px; bottom: -8px; left: 0; top: auto; right: auto; } }
        .mobile-taskbar { background: rgba(20, 18, 26, 0.9); backdrop-filter: blur(20px); border-top: 1px solid rgba(255,255,255,0.05); }
        .alert-dot { animation: pulse 2s infinite; }
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.4; } 100% { opacity: 1; } }
    </style>
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
            <a href="dashboard_coach.php" class="nav-link active-nav flex items-center gap-3">
                <span class="material-symbols-outlined text-xl">grid_view</span>
                Dashboard
                <?php if($pending_count > 0): ?><span class="size-1.5 rounded-full bg-primary alert-dot"></span><?php endif; ?>
            </a>
            <a href="coach_schedule.php" class="nav-link text-gray-400 hover:text-white flex items-center gap-3">
                <span class="material-symbols-outlined text-xl">edit_calendar</span>
                My Availability
            </a>
            <a href="coach_members.php" class="nav-link text-gray-400 hover:text-white flex items-center gap-3">
                <span class="material-symbols-outlined text-xl">groups</span>
                My Members
            </a>
            
            <div class="mt-auto pt-8 border-t border-white/10">
                <div class="flex items-center gap-3 mb-8">
                    <a href="coach_profile.php" class="size-10 rounded-full bg-white/5 flex items-center justify-center border border-white/5 hover:border-primary transition-all group">
                        <span class="material-symbols-outlined text-gray-400 text-xl group-hover:text-primary">psychology</span>
                    </a>
                    <div class="overflow-hidden">
                        <p id="headerClock" class="text-white font-black italic text-[11px] leading-none mb-1">00:00:00 AM</p>
                        <p class="text-[9px] font-black uppercase text-primary leading-none truncate">@<?= htmlspecialchars($_SESSION['username'] ?? 'coach') ?></p>
                    </div>
                </div>

                <a href="../logout.php" class="text-gray-400 hover:text-red-500 transition-colors flex items-center gap-3 group">
                    <span class="material-symbols-outlined group-hover:translate-x-1 transition-transform">logout</span>
                    <span class="nav-link">Sign Out</span>
                </a>
            </div>
        </div>
    </nav>

    <main class="flex-1 max-w-[1600px] p-6 lg:p-12 overflow-x-hidden">
        <header class="mb-10 flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
            <div>
                <h2 class="text-3xl lg:text-4xl font-black italic uppercase tracking-tighter">Coach <span class="text-primary">Dashboard</span></h2>
                <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-1">Manage your training sessions and member progress</p>
            </div>
            <div class="text-left md:text-right">
                <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest leading-none">Logged in as</p>
                <p class="text-xl font-black italic text-white uppercase leading-tight"><?= htmlspecialchars($coach_name) ?></p>
            </div>
        </header>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-10">
            <div class="glass-card p-8 border-l-4 border-emerald-500 shadow-xl">
                <p class="text-[10px] font-black uppercase text-gray-500 mb-2 tracking-widest">Members Today</p>
                <h3 class="text-4xl lg:text-5xl font-black italic"><?= $today_count ?></h3>
            </div>
            
            <div class="glass-card p-8 border-l-4 border-primary shadow-xl">
                <p class="text-[10px] font-black uppercase text-gray-500 mb-2 tracking-widest">Today's Training Schedule</p>
                <div class="flex items-end gap-3">
                    <h3 class="text-4xl lg:text-5xl font-black italic"><?= mysqli_num_rows($schedule_result) ?></h3>
                    <p class="text-[10px] font-black mb-1 text-primary uppercase tracking-widest">Total Sessions</p>
                </div>
            </div>
        </div>

        <div class="glass-card overflow-hidden shadow-2xl">
            <div class="p-8 border-b border-white/5 bg-white/5 flex flex-col sm:row justify-between items-start sm:items-center gap-4">
                <h3 class="text-lg font-black italic uppercase">Today's Training Schedule</h3>
                <span class="px-4 py-1 bg-primary/10 text-primary text-[9px] font-black uppercase rounded-full border border-primary/20">Confirmed Members Only</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="text-[10px] font-black uppercase text-gray-500 tracking-widest bg-black/20">
                            <th class="px-8 py-5">Member Name</th>
                            <th class="px-8 py-5">Training Type</th>
                            <th class="px-8 py-5">Time Slot</th>
                            <th class="px-8 py-5 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <?php if(mysqli_num_rows($schedule_result) > 0): while($row = mysqli_fetch_assoc($schedule_result)): ?>
                        <tr class="hover:bg-white/[0.02] transition-colors group">
                            <td class="px-8 py-6">
                                <div class="flex items-center gap-3">
                                    <div class="size-10 rounded-full bg-white/5 flex items-center justify-center font-black text-primary border border-white/5 group-hover:border-primary/50 text-sm"><?= substr($row['member_name'], 0, 1) ?></div>
                                    <div>
                                        <p class="text-white font-black uppercase italic leading-tight"><?= htmlspecialchars($row['member_name']) ?></p>
                                        <p class="text-[9px] text-gray-500 font-bold uppercase">@<?= htmlspecialchars($row['username']) ?></p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-8 py-6">
                                <p class="text-gray-300 text-sm font-bold uppercase italic"><?= htmlspecialchars($row['session_name'] ?? 'Personal Training') ?></p>
                            </td>
                            <td class="px-8 py-6">
                                <p class="text-white font-black text-sm"><?= date('h:i A', strtotime($row['booking_time'])) ?></p>
                                <p class="text-[9px] text-gray-500 font-bold uppercase tracking-tighter"><?= date('l, M d', strtotime($row['booking_date'])) ?></p>
                            </td>
                            <td class="px-8 py-6 text-right">
                                <a href="coach_workouts.php?user_id=<?= $row['user_id'] ?>" class="bg-white/5 border border-white/10 px-4 py-2 rounded-xl text-[9px] font-black uppercase hover:bg-primary hover:border-primary transition-all shadow-xl inline-block">Assign Workouts</a>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                            <tr><td colspan="4" class="p-20 text-center text-gray-600 uppercase font-black italic text-xs tracking-widest">No members scheduled for training today</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <div class="fixed bottom-0 left-0 right-0 h-20 mobile-taskbar z-[100] lg:hidden flex items-center justify-around px-4">
        <a href="dashboard_coach.php" class="flex flex-col items-center gap-1 text-primary relative">
            <span class="material-symbols-outlined">grid_view</span>
            <span class="text-[8px] font-black uppercase">Dashboard</span>
            <?php if($pending_count > 0): ?><span class="absolute top-0 right-0 size-2 bg-primary rounded-full alert-dot"></span><?php endif; ?>
        </a>
        <a href="coach_schedule.php" class="flex flex-col items-center gap-1 text-gray-500">
            <span class="material-symbols-outlined">edit_calendar</span>
            <span class="text-[8px] font-black uppercase">Avail</span>
        </a>
        <a href="coach_members.php" class="flex flex-col items-center gap-1 text-gray-500">
            <span class="material-symbols-outlined">groups</span>
            <span class="text-[8px] font-black uppercase">Members</span>
        </a>
        <a href="coach_profile.php" class="flex flex-col items-center gap-1 text-gray-500">
            <span class="material-symbols-outlined">person</span>
            <span class="text-[8px] font-black uppercase">Profile</span>
        </a>
    </div>
</body>
</html>