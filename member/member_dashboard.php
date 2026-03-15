<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Dashboard | Herdoza Fitness</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "#8c2bee", "surface-dark": "#14121a", "background-dark": "#0a090d" } } }
        }
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        .nav-link { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; transition: all 0.2s; white-space: nowrap; }
        .active-nav { color: #8c2bee !important; position: relative; }
        .active-nav::after { content: ''; position: absolute; right: -32px; top: 0; width: 4px; height: 100%; background: #8c2bee; border-radius: 2px; }
        
        @media (max-width: 767px) { 
            .active-nav::after { display: none; } 
            body { padding-bottom: 0; }
        }
        .alert-dot { animation: pulse 2s infinite; }
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.4; } 100% { opacity: 1; } }

        /* Hide scrollbar for Chrome, Safari and Opera */
        .no-scrollbar::-webkit-scrollbar {
            display: none;
        }

        /* Hide scrollbar for IE, Edge and Firefox */
        .no-scrollbar {
            -ms-overflow-style: none;  /* IE and Edge */
            scrollbar-width: none;  /* Firefox */
        }
    </style>
    <script>
        function updateSidebarClock() {
            const now = new Date();
            const clockEl = document.getElementById('sidebarClock');
            if (clockEl) {
                clockEl.textContent = now.toLocaleTimeString('en-US', { 
                    hour: '2-digit', 
                    minute: '2-digit', 
                    second: '2-digit' 
                });
            }
        }
        setInterval(updateSidebarClock, 1000);
        window.addEventListener('DOMContentLoaded', updateSidebarClock);
    </script>
</head>
<body class="antialiased flex flex-row min-h-screen">

    <nav class="hidden md:flex flex-col w-64 lg:w-72 bg-[#0a090d] border-r border-white/5 sticky top-0 h-screen p-8 z-50 shrink-0">
        <div class="mb-12">
            <div class="flex items-center gap-4 mb-6">
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
        
        <div class="flex flex-col gap-8 flex-1 overflow-y-auto no-scrollbar pr-2">
            <a href="member_dashboard.php" class="nav-link active-nav flex items-center gap-3">
                <span class="material-symbols-outlined text-xl">grid_view</span> Dashboard
            </a>
            <a href="member_booking.php" class="nav-link text-gray-400 hover:text-white flex items-center gap-3">
                <span class="material-symbols-outlined text-xl">calendar_month</span> Book Session
            </a>
            <a href="member_payment.php" class="nav-link text-gray-400 hover:text-white flex items-center gap-3">
                <span class="material-symbols-outlined text-xl">payments</span> Payment
            </a>
            <a href="member_membership.php" class="nav-link text-gray-400 hover:text-white flex items-center gap-3">
                <span class="material-symbols-outlined text-xl">card_membership</span> Membership
            </a>
            <a href="member_appointment.php" class="nav-link text-gray-400 hover:text-white flex items-center gap-3">
                <span class="material-symbols-outlined text-xl">event_available</span> Appointment
            </a>
        </div>

        <div class="mt-auto pt-8 border-t border-white/10">
            <div class="flex items-center gap-3 mb-8">
                <a href="member_profile.php" class="size-10 rounded-full bg-white/5 flex items-center justify-center border border-white/10 hover:border-primary transition-all">
                    <span class="material-symbols-outlined text-gray-400 text-xl">person</span>
                </a>
                <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest">Profile Settings</p>
            </div>
            <a href="../logout.php" class="text-gray-400 hover:text-red-500 transition-colors flex items-center gap-3 group">
                <span class="material-symbols-outlined group-hover:translate-x-1 transition-transform">logout</span>
                <span class="nav-link">Sign Out</span>
            </a>
        </div>
    </nav>

    <div class="flex-1 overflow-x-hidden">
        <nav class="md:hidden w-full bg-[#0a090d] border-b border-white/5 h-20 flex items-center px-6 justify-between sticky top-0 z-50">
            <div class="flex items-center gap-4">
                <div class="size-10 rounded-xl bg-[#7f13ec] flex items-center justify-center shadow-lg"><span class="material-symbols-outlined text-white text-2xl">bolt</span></div>
                <h1 class="text-2xl font-black italic uppercase tracking-tighter">Herdoza</h1>
            </div>
            <button onclick="toggleMobileMenu()" class="text-white">
                <span class="material-symbols-outlined text-3xl">menu</span>
            </button>
        </nav>

        <div id="mobileMenu" class="hidden md:hidden bg-[#14121a] border-b border-white/5 p-6 flex flex-col gap-6">
            <a href="member_dashboard.php" class="nav-link active-nav">Dashboard</a>
            <a href="member_booking.php" class="nav-link text-gray-400">Book Session</a>
            <a href="member_payment.php" class="nav-link text-gray-400">Payment</a>
            <a href="member_membership.php" class="nav-link text-gray-400">Membership</a>
            <a href="member_appointment.php" class="nav-link text-gray-400">Appointment</a>
            <hr class="border-white/5">
            <a href="../logout.php" class="nav-link text-red-500">Sign Out</a>
        </div>

        <main class="max-w-7xl mx-auto p-6 md:p-10">
            <header class="mb-10">
                <h2 class="text-3xl font-black italic uppercase tracking-tighter">Welcome Back, <span class="text-primary"><?= htmlspecialchars($full_name ?? '') ?></span></h2>
                <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-1">Let's crush your goals today.</p>
            </header>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-10">
                <div class="glass-card p-8 border-l-4 border-emerald-500 relative overflow-hidden group flex flex-col justify-between">
                    <div class="absolute right-0 top-0 p-3 opacity-10 group-hover:opacity-20 transition-opacity">
                        <span class="material-symbols-outlined text-8xl">calendar_month</span>
                    </div>
                    <p class="text-[10px] font-black uppercase text-gray-500 mb-2 tracking-widest">Today's Status</p>

                    <?php if (!empty($is_checked_in)): ?>
                        <div>
                            <h3 class="text-2xl font-black italic text-emerald-400 uppercase tracking-tighter mb-1">Currently Active</h3>
                            <p class="text-[10px] text-gray-400 mb-4">You are clocked in.</p>
                            <form method="POST">
                                <input type="hidden" name="action_type" value="check_out">
                                <button type="submit" class="w-full bg-red-500 hover:bg-red-600 text-white font-black text-xs uppercase py-3 rounded-xl shadow-lg transition-all flex items-center justify-center gap-2">
                                    <span class="material-symbols-outlined text-sm">logout</span> Check Out
                                </button>
                            </form>
                        </div>
                    <?php elseif (!empty($has_booking_today) && empty($is_completed_today)): ?>
                        <div>
                            <h3 class="text-xl font-black italic text-white uppercase tracking-tighter mb-1">Session Today</h3>
                            <p class="text-[10px] text-emerald-500 font-bold uppercase mb-4">Ready to start?</p>
                            <form method="POST">
                                <input type="hidden" name="action_type" value="check_in">
                                <button type="submit" class="w-full bg-emerald-500 hover:bg-emerald-600 text-white font-black text-xs uppercase py-3 rounded-xl shadow-lg transition-all flex items-center justify-center gap-2">
                                    <span class="material-symbols-outlined text-sm">login</span> Check In
                                </button>
                            </form>
                        </div>
                    <?php elseif (!empty($is_completed_today)): ?>
                        <div>
                            <h3 class="text-xl font-black italic text-gray-400 uppercase tracking-tighter">Session Done</h3>
                            <p class="text-[10px] text-emerald-500 font-bold uppercase mt-1">Great job today!</p>
                        </div>
                    <?php elseif (!empty($next_session)): ?>
                        <div>
                            <h3 class="text-xl font-black italic text-white"><?= date('M d', strtotime($next_session['booking_date'])) ?></h3>
                            <p class="text-xs text-primary font-bold uppercase mt-1">Next: <?= date('h:i A', strtotime($next_session['booking_time'])) ?></p>
                        </div>
                    <?php else: ?>
                        <div>
                            <h3 class="text-lg font-black italic text-gray-600 uppercase">No Bookings</h3>
                            <a href="member_booking.php" class="text-[10px] text-primary font-bold uppercase mt-1 hover:underline">Book Now -></a>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="glass-card p-8 border-l-4 border-amber-500 relative overflow-hidden">
                    <p class="text-[10px] font-black uppercase text-gray-500 mb-2 tracking-widest">Membership Status</p>
                    <h3 class="text-2xl font-black italic text-white uppercase">Active Member</h3>
                    <p class="text-[9px] text-gray-500 font-bold uppercase mt-1">Plan: Standard Access</p>
                </div>
            </div>

            <h3 class="font-black uppercase italic text-sm tracking-widest mb-6">Quick Actions</h3>
            <div class="grid grid-cols-2 lg:grid-cols-3 gap-4">
                <a href="member_booking.php" class="glass-card p-6 flex flex-col items-center justify-center gap-3 hover:bg-white/5 transition-colors group">
                    <div class="size-12 rounded-full bg-primary/20 flex items-center justify-center text-primary group-hover:scale-110 transition-transform">
                        <span class="material-symbols-outlined">add</span>
                    </div>
                    <span class="text-[10px] font-black uppercase tracking-widest">Book Session</span>
                </a>
                <a href="member_payment.php" class="glass-card p-6 flex flex-col items-center justify-center gap-3 hover:bg-white/5 transition-colors group">
                    <div class="size-12 rounded-full bg-amber-500/20 flex items-center justify-center text-amber-500 group-hover:scale-110 transition-transform">
                        <span class="material-symbols-outlined">receipt_long</span>
                    </div>
                    <span class="text-[10px] font-black uppercase tracking-widest">Payment History</span>
                </a>
                <a href="member_membership.php" class="glass-card p-6 flex flex-col items-center justify-center gap-3 hover:bg-white/5 transition-colors group">
                    <div class="size-12 rounded-full bg-purple-500/20 flex items-center justify-center text-purple-500 group-hover:scale-110 transition-transform">
                        <span class="material-symbols-outlined">card_membership</span>
                    </div>
                    <span class="text-[10px] font-black uppercase tracking-widest">Membership</span>
                </a>
            </div>
        </main>
    </div>
</body>
</html>