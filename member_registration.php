<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Appointments | Herdoza Fitness</title>
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
            const timeString = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            if(document.getElementById('headerClock')) document.getElementById('headerClock').textContent = timeString;
        }
        window.onload = function() { setInterval(updateHeaderClock, 1000); updateHeaderClock(); };
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; padding-bottom: 100px; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        .sidebar-link { display: flex; align-items: center; gap: 12px; padding: 12px 16px; border-radius: 12px; color: #9ca3af; transition: all 0.2s; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; }
        .sidebar-link:hover { background: rgba(140, 43, 238, 0.1); color: white; }
        .sidebar-active { background: #8c2bee; color: white !important; box-shadow: 0 10px 20px -5px rgba(140, 43, 238, 0.3); }
        .mobile-taskbar { background: rgba(20, 18, 26, 0.9); backdrop-filter: blur(20px); border-top: 1px solid rgba(255,255,255,0.05); }
        textarea, input { background: #0a090d !important; border: 1px solid rgba(255,255,255,0.1) !important; color: white !important; }
        ::-webkit-scrollbar { width: 5px; }
        ::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 10px; }
    </style>
</head>
<body class="antialiased">

    <div class="flex max-w-[1600px] mx-auto min-h-screen">
        <aside class="hidden md:flex flex-col w-72 sticky top-0 h-screen border-r border-white/5 p-6 overflow-y-auto">
            <div class="mb-10 p-4 rounded-2xl bg-white/5 border border-white/5">
                <div class="flex items-center gap-3 mb-4">
                    <div class="size-8 rounded-lg bg-primary flex items-center justify-center shadow-lg shadow-primary/20">
                        <span class="material-symbols-outlined text-white text-lg">bolt</span>
                    </div>
                    <h1 class="text-xl font-black italic uppercase tracking-tighter">Herdoza</h1>
                </div>
                
                <div class="pt-4 border-t border-white/10">
                    <p id="headerClock" class="text-white font-black italic text-xl leading-none">00:00:00 AM</p>
                    <p class="text-primary text-[9px] font-black uppercase mt-1 tracking-[0.2em]"><?= date('l, M d') ?></p>
                </div>
            </div>

            <nav class="flex-1 space-y-2">
                <p class="text-[10px] font-black uppercase text-gray-500 tracking-[0.2em] mb-4 px-4">Main Menu</p>
                <a href="member_dashboard.php" class="sidebar-link">
                    <span class="material-symbols-outlined text-xl">dashboard</span> Dashboard
                </a>
                <a href="member_booking.php" class="sidebar-link">
                    <span class="material-symbols-outlined text-xl">calendar_month</span> Book Session
                </a>
                <a href="member_payment.php" class="sidebar-link">
                    <span class="material-symbols-outlined text-xl">payments</span> Payment
                </a>
                <a href="member_membership.php" class="sidebar-link">
                    <span class="material-symbols-outlined text-xl">card_membership</span> Membership
                </a>
                <a href="member_appointment.php" class="sidebar-link sidebar-active">
                    <span class="material-symbols-outlined text-xl">event_available</span> Appointment
                </a>
            </nav>

            <div class="pt-6 border-t border-white/5 space-y-2">
                <p class="text-[10px] font-black uppercase text-gray-500 tracking-[0.2em] mb-4 px-4">User</p>
                <a href="edit_profile.php" class="sidebar-link">
                    <span class="material-symbols-outlined text-xl">person</span> Profile
                </a>
                <a href="logout.php" class="sidebar-link hover:text-red-500">
                    <span class="material-symbols-outlined text-xl">logout</span> Logout
                </a>
            </div>
        </aside>

        <main class="flex-1 p-6 md:p-12">
            <header class="mb-12 flex flex-col md:flex-row md:items-end justify-between gap-6">
                <div>
                    <h2 class="text-4xl font-black italic uppercase tracking-tighter leading-none">Talk to <span class="text-primary">Admin</span></h2>
                    <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-3">Schedule a face-to-face consultation or inquiry</p>
                </div>
            </header>

            <?php if($success_msg): ?><div class="bg-emerald-500/10 border border-emerald-500/20 p-4 rounded-xl text-emerald-500 text-[10px] font-black uppercase italic mb-8"><?= $success_msg ?></div><?php endif; ?>
            <?php if($error_msg): ?><div class="bg-red-500/10 border border-red-500/20 p-4 rounded-xl text-red-500 text-[10px] font-black uppercase italic mb-8"><?= $error_msg ?></div><?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-10">
                <div class="lg:col-span-1">
                    <div class="glass-card p-8 shadow-xl sticky top-6">
                        <form method="POST" class="space-y-6">
                            <div>
                                <label class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-3 block">Reason for Meeting</label>
                                <input type="text" name="subject" placeholder="e.g. Refund Request" required class="w-full p-4 rounded-xl text-sm outline-none focus:border-primary transition-all">
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-3 block">Date</label>
                                    <input type="date" name="booking_date" min="<?= date('Y-m-d') ?>" required class="w-full p-4 rounded-xl text-sm outline-none">
                                </div>
                                <div>
                                    <label class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-3 block">Time</label>
                                    <input type="time" name="booking_time" required class="w-full p-4 rounded-xl text-sm outline-none">
                                </div>
                            </div>

                            <div>
                                <label class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-3 block">Additional Notes</label>
                                <textarea name="notes" placeholder="Any specific details?" class="w-full p-4 h-28 rounded-xl text-sm outline-none focus:border-primary transition-all resize-none"></textarea>
                            </div>

                            <button type="submit" name="action_book" class="w-full py-4 bg-primary text-white rounded-2xl font-black uppercase tracking-widest shadow-xl hover:scale-[1.02] transition-all mt-4">
                                Request Appointment
                            </button>
                        </form>
                    </div>
                </div>

                <div class="lg:col-span-2">
                    <div class="glass-card overflow-hidden shadow-2xl">
                        <div class="p-8 border-b border-white/5 bg-white/5 flex justify-between items-center">
                            <h3 class="text-lg font-black italic uppercase">Meeting Requests</h3>
                            <span class="size-2 rounded-full bg-primary alert-dot"></span>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left">
                                <thead class="bg-white/5 text-[10px] font-black uppercase text-gray-500 tracking-widest">
                                    <tr>
                                        <th class="px-8 py-4">Purpose</th>
                                        <th class="px-8 py-4">Schedule</th>
                                        <th class="px-8 py-4 text-right">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-white/5">
                                    <?php if(mysqli_num_rows($recent_bookings) > 0): ?>
                                        <?php while($row = mysqli_fetch_assoc($recent_bookings)): ?>
                                        <tr class="text-sm hover:bg-white/[0.02] transition-colors">
                                            <td class="px-8 py-5">
                                                <p class="text-white font-bold italic uppercase"><?= htmlspecialchars($row['subject'] ?: 'Consultation') ?></p>
                                                <p class="text-[9px] text-gray-500 mt-1 italic"><?= htmlspecialchars($row['message']) ?></p>
                                            </td>
                                            <td class="px-8 py-5 text-gray-400">
                                                <?= date('M d, Y', strtotime($row['appt_date'])) ?><br>
                                                <span class="text-white font-bold"><?= date('h:i A', strtotime($row['appt_time'])) ?></span>
                                            </td>
                                            <td class="px-8 py-5 text-right">
                                                <div class="flex flex-col items-end gap-2">
                                                    <span class="px-3 py-1 rounded-full text-[9px] font-black uppercase tracking-tighter <?= $row['status'] == 'Approved' ? 'bg-emerald-500/10 text-emerald-500 border border-emerald-500/20' : 'bg-amber-500/10 text-amber-500 border border-amber-500/20' ?>">
                                                        <?= $row['status'] ?>
                                                    </span>
                                                    <?php if($row['status'] === 'Pending'): ?>
                                                    <form method="POST" onsubmit="return confirm('Cancel request?');">
                                                        <input type="hidden" name="appt_id" value="<?= $row['appt_id'] ?>">
                                                        <button type="submit" name="action_cancel" class="text-[9px] font-black text-red-500 uppercase hover:underline">Cancel</button>
                                                    </form>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="3" class="px-8 py-10 text-center text-gray-600 uppercase text-xs font-black italic tracking-widest">No meeting requests found</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
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
        <a href="member_payment.php" class="flex flex-col items-center gap-1 text-gray-500">
            <span class="material-symbols-outlined">payments</span>
            <span class="text-[8px] font-black uppercase">Pay</span>
        </a>
        <a href="member_membership.php" class="flex flex-col items-center gap-1 text-gray-500">
            <span class="material-symbols-outlined">card_membership</span>
            <span class="text-[8px] font-black uppercase">Plans</span>
        </a>
        <a href="member_appointment.php" class="flex flex-col items-center gap-1 text-primary">
            <span class="material-symbols-outlined">event_available</span>
            <span class="text-[8px] font-black uppercase">Appt</span>
        </a>
    </div>

</body>
</html>