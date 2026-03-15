<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Transaction Management | Herdoza Admin</title>
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

        function switchTab(tab) {
            document.getElementById('billing-tab').style.display = 'none';
            document.getElementById('bookings-tab').style.display = 'none';
            document.getElementById(tab + '-tab').style.display = 'block';

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
        .tab-btn { position: relative; }
        .tab-btn.active { color: #8c2bee !important; border-bottom-color: #8c2bee !important; }
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
    
    <div class="flex flex-col gap-7 flex-1">
        <a href="admin_dashboard.php" class="nav-link flex items-center gap-3 text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl">grid_view</span> Dashboard
        </a>
        <a href="admin_users.php" class="nav-link text-gray-400 hover:text-white flex items-center gap-3">
            <span class="material-symbols-outlined text-xl">group</span> My Users
        </a>
        <a href="admin_transaction.php" class="nav-link active-nav flex items-center gap-3">
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
                <h1 class="text-3xl font-black text-white italic uppercase tracking-tighter mb-1">Transaction <span class="text-primary">Management</span></h1>
                <p class="text-primary text-xs font-bold uppercase tracking-widest">Billing and Pending Bookings</p>
            </div>
        </header>

        <div class="flex gap-8 mb-6 border-b border-white/5">
            <button onclick="switchTab('billing')" class="tab-btn pb-4 text-[10px] font-black uppercase tracking-widest border-b-2 border-primary text-primary transition-all">Billing</button>
            <button onclick="switchTab('bookings')" class="tab-btn pb-4 text-[10px] font-black uppercase tracking-widest border-b-2 border-transparent text-gray-500 hover:text-white transition-all">Pending Bookings</button>
        </div>

        <div id="billing-tab">
            <div class="glass-card overflow-hidden shadow-2xl">
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="text-[10px] font-black uppercase text-gray-500 tracking-widest bg-white/5 border-b border-white/5">
                                <th class="px-8 py-5">User</th>
                                <th class="px-8 py-5">Amount</th>
                                <th class="px-8 py-5">Payment Method</th>
                                <th class="px-8 py-5">Date</th>
                                <th class="px-8 py-5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <tr>
                                <td class="px-8 py-6">John Doe</td>
                                <td class="px-8 py-6">₱1000</td>
                                <td class="px-8 py-6">Gcash</td>
                                <td class="px-8 py-6">Jan 01, 2024</td>
                                <td class="px-8 py-6 text-right">
                                    <div class="flex justify-end gap-2">
                                        <a href="#" class="size-8 rounded-lg bg-white/5 flex items-center justify-center hover:bg-primary transition-all group">
                                            <span class="material-symbols-outlined text-sm text-gray-500 group-hover:text-white">visibility</span>
                                        </a>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="payment_id" value="1">
                                            <button type="submit" name="approve_payment" class="size-8 rounded-lg bg-white/5 flex items-center justify-center hover:bg-green-500/20 transition-all group">
                                                <span class="material-symbols-outlined text-sm text-gray-500 group-hover:text-green-500">check</span>
                                            </button>
                                        </form>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="payment_id" value="1">
                                            <button type="submit" name="reject_payment" class="size-8 rounded-lg bg-white/5 flex items-center justify-center hover:bg-red-500/20 transition-all group">
                                                <span class="material-symbols-outlined text-sm text-gray-500 group-hover:text-red-500">close</span>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td class="px-8 py-6">Jane Smith</td>
                                <td class="px-8 py-6">₱1500</td>
                                <td class="px-8 py-6">Gcash</td>
                                <td class="px-8 py-6">Jan 02, 2024</td>
                                <td class="px-8 py-6 text-right">
                                    <div class="flex justify-end gap-2">
                                        <a href="#" class="size-8 rounded-lg bg-white/5 flex items-center justify-center hover:bg-primary transition-all group">
                                            <span class="material-symbols-outlined text-sm text-gray-500 group-hover:text-white">visibility</span>
                                        </a>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="payment_id" value="2">
                                            <button type="submit" name="approve_payment" class="size-8 rounded-lg bg-white/5 flex items-center justify-center hover:bg-green-500/20 transition-all group">
                                                <span class="material-symbols-outlined text-sm text-gray-500 group-hover:text-green-500">check</span>
                                            </button>
                                        </form>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="payment_id" value="2">
                                            <button type="submit" name="reject_payment" class="size-8 rounded-lg bg-white/5 flex items-center justify-center hover:bg-red-500/20 transition-all group">
                                                <span class="material-symbols-outlined text-sm text-gray-500 group-hover:text-red-500">close</span>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="bookings-tab" style="display: none;">
            <div class="glass-card overflow-hidden shadow-2xl">
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="text-[10px] font-black uppercase text-gray-500 tracking-widest bg-white/5 border-b border-white/5">
                                <th class="px-8 py-5">User</th>
                                <th class="px-8 py-5">Date</th>
                                <th class="px-8 py-5">Time</th>
                                <th class="px-8 py-5">Duration</th>
                                <th class="px-8 py-5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <tr>
                                <td class="px-8 py-6">Mike Johnson</td>
                                <td class="px-8 py-6">Jan 05, 2024</td>
                                <td class="px-8 py-6">10:00 AM</td>
                                <td class="px-8 py-6">2 hour(s)</td>
                                <td class="px-8 py-6 text-right">
                                    <div class="flex justify-end gap-2">
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="booking_id" value="1">
                                            <button type="submit" name="approve_booking" class="size-8 rounded-lg bg-white/5 flex items-center justify-center hover:bg-green-500/20 transition-all group">
                                                <span class="material-symbols-outlined text-sm text-gray-500 group-hover:text-green-500">check</span>
                                            </button>
                                        </form>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="booking_id" value="1">
                                            <button type="submit" name="reject_booking" class="size-8 rounded-lg bg-white/5 flex items-center justify-center hover:bg-red-500/20 transition-all group">
                                                <span class="material-symbols-outlined text-sm text-gray-500 group-hover:text-red-500">close</span>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td class="px-8 py-6">Sarah Williams</td>
                                <td class="px-8 py-6">Jan 06, 2024</td>
                                <td class="px-8 py-6">2:00 PM</td>
                                <td class="px-8 py-6">1 hour(s)</td>
                                <td class="px-8 py-6 text-right">
                                    <div class="flex justify-end gap-2">
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="booking_id" value="2">
                                            <button type="submit" name="approve_booking" class="size-8 rounded-lg bg-white/5 flex items-center justify-center hover:bg-green-500/20 transition-all group">
                                                <span class="material-symbols-outlined text-sm text-gray-500 group-hover:text-green-500">check</span>
                                            </button>
                                        </form>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="booking_id" value="2">
                                            <button type="submit" name="reject_booking" class="size-8 rounded-lg bg-white/5 flex items-center justify-center hover:bg-red-500/20 transition-all group">
                                                <span class="material-symbols-outlined text-sm text-gray-500 group-hover:text-red-500">close</span>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</div>
</body>
</html>