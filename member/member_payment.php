<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Payment History | Herdoza Fitness</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "#8c2bee", "surface-dark": "#14121a", "background-dark": "#0a090d" } } }
        }
        function updateHeaderClock() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            if(document.getElementById('sidebarClock')) document.getElementById('sidebarClock').textContent = timeString;
        }
        window.onload = function() { setInterval(updateHeaderClock, 1000); updateHeaderClock(); };
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        .nav-link { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; transition: all 0.2s; white-space: nowrap; }
        .active-nav { color: #8c2bee !important; position: relative; }
        .active-nav::after { content: ''; position: absolute; right: -32px; top: 0; width: 4px; height: 100%; background: #8c2bee; border-radius: 2px; }
        
        @media (max-width: 767px) { 
            .active-nav::after { display: none; } 
            body { padding-bottom: 80px; }
        }
        .mobile-taskbar { background: rgba(20, 18, 26, 0.9); backdrop-filter: blur(20px); border-top: 1px solid rgba(255,255,255,0.05); }
    </style>
</head>
<body class="antialiased flex min-h-screen">

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
        
        <div class="flex flex-col gap-8 flex-1">
            <a href="member_dashboard.php" class="nav-link text-gray-400 hover:text-white flex items-center gap-3">
                <span class="material-symbols-outlined text-xl">grid_view</span> Dashboard
            </a>
            <a href="member_booking.php" class="nav-link text-gray-400 hover:text-white flex items-center gap-3">
                <span class="material-symbols-outlined text-xl">calendar_month</span> Book Session
            </a>
            <a href="member_payment.php" class="nav-link active-nav flex items-center gap-3">
                <span class="material-symbols-outlined text-xl">payments</span> Payment
            </a>
            <a href="member_membership.php" class="nav-link text-gray-400 hover:text-white flex items-center gap-3">
                <span class="material-symbols-outlined text-xl">card_membership</span> Membership
            </a>
            <a href="member_appointment.php" class="nav-link text-gray-400 hover:text-white flex items-center gap-3">
                <span class="material-symbols-outlined text-xl">event_available</span> Appointment
            </a>
            
            <div class="mt-auto pt-8 border-t border-white/10">
                <div class="flex items-center gap-3 mb-8">
                    <a href="edit_profile.php" class="size-10 rounded-full bg-white/5 flex items-center justify-center border border-white/10 hover:border-primary transition-all">
                        <span class="material-symbols-outlined text-gray-400 text-xl">person</span>
                    </a>
                    <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest">Profile Settings</p>
                </div>
                <a href="logout.php" class="text-gray-400 hover:text-red-500 transition-colors flex items-center gap-3 group">
                    <span class="material-symbols-outlined group-hover:translate-x-1 transition-transform">logout</span>
                    <span class="nav-link">Sign Out</span>
                </a>
            </div>
        </div>
    </nav>

    <div class="flex-1 overflow-x-hidden">
        <nav class="md:hidden w-full bg-[#0a090d] border-b border-white/5 h-20 flex items-center px-6 justify-between sticky top-0 z-50">
            <div class="flex items-center gap-4">
                <div class="size-10 rounded-xl bg-[#7f13ec] flex items-center justify-center shadow-lg"><span class="material-symbols-outlined text-white text-2xl">bolt</span></div>
                <h1 class="text-2xl font-black italic uppercase tracking-tighter">Herdoza</h1>
            </div>
            <a href="member_profile.php" class="size-9 rounded-full bg-white/5 border border-white/10 flex items-center justify-center">
                <span class="material-symbols-outlined text-gray-400 text-xl">person</span>
            </a>
        </nav>

        <main class="max-w-5xl mx-auto p-6 md:p-10">
            <header class="mb-10 flex justify-between items-end">
                <div>
                    <h2 class="text-3xl font-black italic uppercase tracking-tighter">Transaction <span class="text-primary">History</span></h2>
                    <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-1">Record of your payments and sessions</p>
                </div>
                <div class="text-right hidden sm:block">
                    <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest leading-none">Total Invested</p>
                    <p class="text-2xl font-black italic text-white uppercase leading-tight">₱<?= number_format($total_spent, 2) ?></p>
                </div>
            </header>

            <div class="glass-card overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="text-[10px] font-black uppercase text-gray-500 tracking-widest bg-black/20 border-b border-white/5">
                                <th class="px-6 py-4">Date</th>
                                <th class="px-6 py-4">Service</th>
                                <th class="px-6 py-4">Status</th>
                                <th class="px-6 py-4 text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <?php if(mysqli_num_rows($history_result) > 0): while($row = mysqli_fetch_assoc($history_result)): ?>
                            <tr class="hover:bg-white/5 transition-colors">
                                <td class="px-6 py-5">
                                    <p class="text-white font-bold text-sm"><?= date('M d, Y', strtotime($row['booking_date'])) ?></p>
                                    <p class="text-[9px] text-gray-500 uppercase font-black"><?= date('h:i A', strtotime($row['booking_time'])) ?></p>
                                </td>
                                <td class="px-6 py-5">
                                    <p class="text-gray-300 font-bold uppercase italic text-xs"><?= htmlspecialchars($row['session_name']) ?></p>
                                    <p class="text-[9px] text-gray-600 uppercase">Ref: <?= htmlspecialchars($row['reference_number'] ?? 'N/A') ?></p>
                                </td>
                                <td class="px-6 py-5">
                                    <span class="px-3 py-1 rounded-full text-[9px] font-black uppercase bg-emerald-500/10 text-emerald-500 border border-emerald-500/20">
                                        Paid
                                    </span>
                                </td>
                                <td class="px-6 py-5 text-right">
                                    <span class="text-white font-black font-mono">₱<?= number_format($row['price'], 2) ?></span>
                                </td>
                            </tr>
                            <?php endwhile; else: ?>
                            <tr>
                                <td colspan="4" class="p-12 text-center text-gray-500 uppercase font-black text-xs tracking-widest">
                                    No payment history found.
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <div class="fixed bottom-0 left-0 right-0 h-20 mobile-taskbar z-[100] md:hidden flex items-center justify-around px-4">
        <a href="member_dashboard.php" class="flex flex-col items-center gap-1 text-gray-500">
            <span class="material-symbols-outlined">dashboard</span>
            <span class="text-[8px] font-black uppercase">Home</span>
        </a>
        <a href="member_booking.php" class="flex flex-col items-center gap-1 text-gray-500">
            <span class="material-symbols-outlined">calendar_month</span>
            <span class="text-[8px] font-black uppercase">Book</span>
        </a>
        <a href="member_payment.php" class="flex flex-col items-center gap-1 text-primary">
            <span class="material-symbols-outlined">payments</span>
            <span class="text-[8px] font-black uppercase">Pay</span>
        </a>
        <a href="member_appointment.php" class="flex flex-col items-center gap-1 text-gray-500">
            <span class="material-symbols-outlined">event_available</span>
            <span class="text-[8px] font-black uppercase">Appt</span>
        </a>
        <a href="member_profile.php" class="flex flex-col items-center gap-1 text-gray-500">
            <span class="material-symbols-outlined">person</span>
            <span class="text-[8px] font-black uppercase">Profile</span>
        </a>
    </div>

</body>
</html>