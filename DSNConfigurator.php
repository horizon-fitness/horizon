<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Schedule | Herdoza Fitness</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "#8c2bee", "background-dark": "#0a090d", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)"}}}
        }
        
        function openBookingModal(date = '') {
            document.getElementById('bookingModal').classList.remove('hidden');
            document.getElementById('bookingModal').classList.add('flex');
            if(date) document.getElementById('booking_date_input').value = date;
            calculateTotal(); 
        }
        
        function closeBookingModal() {
            document.getElementById('bookingModal').classList.add('hidden');
            document.getElementById('bookingModal').classList.remove('flex');
        }

        function calculateTotal() {
            const sessionSelect = document.getElementById('session_select');
            const trainerSelect = document.getElementById('trainer_select');
            const durationSelect = document.getElementById('duration_select');
            
            const selectedOption = sessionSelect.options[sessionSelect.selectedIndex];
            let basePrice = selectedOption.getAttribute('data-price') ? parseFloat(selectedOption.getAttribute('data-price')) : 0;
            
            let trainerId = trainerSelect.value;
            let duration = parseInt(durationSelect.value);
            
            let coachFeePerHour = (trainerId !== '0') ? 60 : 0;
            let totalCoachFee = coachFeePerHour * duration;
            let total = (basePrice + coachFeePerHour) * duration;
            
            let feeMessage = (trainerId !== '0') ? `Includes Coach Fee: ₱${totalCoachFee} (₱60 x ${duration} hrs)` : "No Coach Fee";

            document.getElementById('total_display').innerText = "₱" + total.toFixed(2);
            document.getElementById('fee_breakdown').innerText = feeMessage;
        }

        function togglePaymentFields() {
            const method = document.getElementById('payment_method').value;
            const gcashDiv = document.getElementById('gcash_details');
            if (method === 'GCash') {
                gcashDiv.classList.remove('hidden');
                document.getElementById('ref_input').required = true; 
                document.getElementById('proof_input').required = true; 
            } else {
                gcashDiv.classList.add('hidden');
                document.getElementById('ref_input').required = false; 
                document.getElementById('proof_input').required = false; 
            }
        }

        window.onload = function() { 
            setInterval(() => {
                const now = new Date();
                const timeString = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
                if(document.getElementById('sidebarClock')) document.getElementById('sidebarClock').textContent = timeString;
            }, 1000); 
        };
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        .nav-link { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; transition: all 0.2s; white-space: nowrap; }
        .active-nav { color: #8c2bee !important; position: relative; }
        .active-nav::after { content: ''; position: absolute; right: -32px; top: 0; width: 4px; height: 100%; background: #8c2bee; border-radius: 2px; }
        
        @media (max-width: 767px) { 
            .active-nav::after { display: none; } 
            body { padding-bottom: 90px; }
        }

        .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 6px; }
        .mobile-taskbar { background: rgba(20, 18, 26, 0.9); backdrop-filter: blur(20px); border-top: 1px solid rgba(255,255,255,0.05); }
    </style>
</head>
<body class="antialiased text-white flex min-h-screen">

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
            <a href="member_booking.php" class="nav-link active-nav flex items-center gap-3">
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

    <div class="flex-1 flex flex-col overflow-x-hidden">
        <nav class="md:hidden w-full bg-[#0a090d] border-b border-white/5 h-20 flex items-center px-6 justify-between sticky top-0 z-50">
            <div class="flex items-center gap-4">
                <div class="size-10 rounded-xl bg-[#7f13ec] flex items-center justify-center shadow-lg"><span class="material-symbols-outlined text-white text-2xl">bolt</span></div>
                <h1 class="text-2xl font-black italic uppercase tracking-tighter">Herdoza</h1>
            </div>
            <a href="edit_profile.php" class="size-9 rounded-full bg-white/5 border border-white/10 flex items-center justify-center">
                <span class="material-symbols-outlined text-gray-400 text-xl">person</span>
            </a>
        </nav>

        <main class="p-6 md:p-10 max-w-7xl mx-auto w-full">
            <header class="mb-10 flex justify-between items-center">
                <div>
                    <h2 class="text-3xl font-black italic uppercase tracking-tighter">Training <span class="text-primary">Schedule</span></h2>
                    <p class="text-gray-500 text-[10px] font-black uppercase tracking-widest mt-1">Book & Pay for your next session</p>
                </div>
                <button onclick="openBookingModal()" class="bg-primary text-white text-[10px] font-black px-8 py-3 rounded-full uppercase tracking-widest shadow-lg hover:scale-105 transition-all">Quick Book</button>
            </header>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-10">
                <div class="lg:col-span-4">
                    <div class="glass-card p-8">
                        <h3 class="text-white text-xl font-black italic uppercase mb-8"><?= date('F Y') ?></h3>
                        <div class="calendar-grid">
                            <?php 
                            $year = date('Y'); $month = date('m'); $days_in_month = date('t');
                            $first_day = date('w', strtotime("$year-$month-01"));
                            
                            $booked_dates = [];
                            if (!empty($active_sessions)) {
                                foreach ($active_sessions as $session) { $booked_dates[] = $session['booking_date']; }
                            }

                            for ($i = 0; $i < $first_day; $i++) { echo "<div></div>"; }

                            for ($day = 1; $day <= $days_in_month; $day++): 
                                $loop_date = "$year-$month-" . str_pad($day, 2, "0", STR_PAD_LEFT);
                                $is_today = ($loop_date == date('Y-m-d'));
                                $has_booking = in_array($loop_date, $booked_dates);
                            ?>
                                <button onclick="openBookingModal('<?= $loop_date ?>')" class="aspect-square flex items-center justify-center text-xs rounded-xl border <?= $has_booking ? 'bg-primary/20 text-primary border-primary/40 font-black' : ($is_today ? 'bg-white/10 text-white border-white/20 font-black' : 'text-gray-400 border-transparent hover:bg-white/5') ?>">
                                    <?= $day ?>
                                </button>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-8 space-y-6">
                    <h3 class="font-black uppercase italic text-sm tracking-widest">Active Training Logs</h3>
                    <div class="space-y-4">
                        <?php if(!empty($active_sessions)): foreach($active_sessions as $session): 
                            $display_end = $session['end_time'] ? date('h:i A', strtotime($session['end_time'])) : 'TBD';
                        ?>
                            <div class="glass-card p-6 flex items-center justify-between group hover:border-primary/40 transition-all text-white">
                                <div class="flex items-center gap-8">
                                    <div class="text-left min-w-[100px]">
                                        <span class="text-white text-2xl font-black italic"><?= date('h:i', strtotime($session['booking_time'])); ?><span class="text-xs text-gray-500">am/pm</span></span>
                                        <p class="text-gray-500 text-[9px] font-bold uppercase mt-1">Until <?= $display_end ?></p>
                                        <p class="text-primary text-[10px] font-black uppercase tracking-widest mt-1"><?= date('M d', strtotime($session['booking_date'])); ?></p>
                                    </div>
                                    <div>
                                        <h4 class="text-white text-lg font-bold uppercase italic"><?= htmlspecialchars($session['session_name']); ?></h4>
                                        <p class="text-gray-500 text-[10px] font-bold uppercase italic">Coach: <?= htmlspecialchars($session['trainer_name'] ?? 'Self-Train'); ?></p>
                                        <p class="text-gray-500 text-[9px] font-bold uppercase italic mt-1">Duration: <?= $session['duration'] ?> Hour(s)</p>
                                    </div>
                                </div>
                                <span class="px-4 py-1.5 rounded-full text-[9px] font-black uppercase border <?= $session['status'] == 'Pending' ? 'bg-amber-500/10 border-amber-500/20 text-amber-500' : 'bg-emerald-500/10 border-emerald-500/20 text-emerald-500'; ?>"><?= $session['status']; ?></span>
                            </div>
                        <?php endforeach; else: ?>
                            <div class="h-40 border-2 border-dashed border-white/5 rounded-[32px] flex items-center justify-center text-gray-700 font-black uppercase text-[10px] tracking-widest">No upcoming sessions</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <div class="fixed bottom-0 left-0 right-0 h-20 mobile-taskbar z-[100] md:hidden flex items-center justify-around px-4">
        <a href="member_dashboard.php" class="flex flex-col items-center gap-1 text-gray-500"><span class="material-symbols-outlined">dashboard</span><span class="text-[8px] font-black uppercase">Home</span></a>
        <a href="member_booking.php" class="flex flex-col items-center gap-1 text-primary"><span class="material-symbols-outlined">calendar_month</span><span class="text-[8px] font-black uppercase">Book</span></a>
        <a href="member_payment.php" class="flex flex-col items-center gap-1 text-gray-500"><span class="material-symbols-outlined">payments</span><span class="text-[8px] font-black uppercase">Pay</span></a>
        <a href="member_profile.php" class="flex flex-col items-center gap-1 text-gray-500"><span class="material-symbols-outlined">person</span><span class="text-[8px] font-black uppercase">Profile</span></a>
    </div>

    <div id="bookingModal" class="fixed inset-0 z-[110] hidden items-center justify-center bg-black/80 backdrop-blur-md p-4">
        <div class="bg-surface-dark w-full max-w-md rounded-[32px] p-8 border border-border-subtle shadow-2xl h-[90vh] overflow-y-auto">
            <h3 class="text-white text-xl font-black italic uppercase tracking-tighter mb-6">Book & <span class="text-primary">Pay</span></h3>
            <form method="POST" enctype="multipart/form-data" class="space-y-4">
                <input type="date" name="booking_date" id="booking_date_input" min="<?= $today_date ?>" required class="w-full bg-background-dark border-white/5 rounded-xl p-4 text-white outline-none focus:ring-1 focus:ring-primary">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-[9px] font-black uppercase text-gray-500 ml-1">Start</label>
                        <input type="time" name="booking_time" id="time_input" required class="w-full bg-background-dark border-white/5 rounded-xl p-4 text-white outline-none focus:ring-1 focus:ring-primary">
                    </div>
                    <div>
                        <label class="text-[9px] font-black uppercase text-gray-500 ml-1">Duration</label>
                        <select name="duration" id="duration_select" onchange="calculateTotal()" class="w-full bg-background-dark border-white/5 rounded-xl p-4 text-white outline-none focus:ring-1 focus:ring-primary">
                            <option value="1">1 Hour</option>
                            <option value="2">2 Hours</option>
                            <option value="3">3 Hours</option>
                            <option value="4">4 Hours</option>
                        </select>
                    </div>
                </div>
                <label class="text-[9px] font-black uppercase text-gray-500 ml-1">Service</label>
                <select name="session_id" id="session_select" onchange="calculateTotal()" required class="w-full bg-background-dark border-white/5 rounded-xl p-4 text-white outline-none focus:ring-1 focus:ring-primary">
                    <option value="" disabled selected data-price="0">Select Session Type</option>
                    <?php if($sessions_result): mysqli_data_seek($sessions_result, 0); while($s = mysqli_fetch_assoc($sessions_result)): ?>
                        <option value="<?= $s['session_id'] ?>" data-price="<?= $s['price'] ?>"><?= htmlspecialchars($s['session_name']) ?> (₱<?= number_format($s['price'], 2) ?>/hr)</option>
                    <?php endwhile; endif; ?>
                </select>
                <label class="text-[9px] font-black uppercase text-gray-500 ml-1">Trainer (+₱60/hr)</label>
                <select name="trainer_id" id="trainer_select" onchange="calculateTotal()" required class="w-full bg-background-dark border-white/5 rounded-xl p-4 text-white outline-none focus:ring-1 focus:ring-primary">
                    <option value="0">General Workout (Self-Train)</option>
                    <?php if($trainers_result): mysqli_data_seek($trainers_result, 0); while($t = mysqli_fetch_assoc($trainers_result)): ?>
                        <option value="<?= $t['trainer_id'] ?>">Coach <?= htmlspecialchars($t['trainer_name']) ?></option>
                    <?php endwhile; endif; ?>
                </select>
                <div class="bg-white/5 p-4 rounded-2xl border border-white/10 flex justify-between items-center">
                    <div><span class="text-gray-500 text-xs font-black uppercase tracking-widest block">Total to Pay</span></div>
                    <div class="text-right">
                        <h4 id="total_display" class="text-white text-2xl font-black italic">₱0.00</h4>
                        <p id="fee_breakdown" class="text-emerald-500 text-[10px] font-bold uppercase"></p>
                    </div>
                </div>
                <div class="pt-4 border-t border-white/10">
                    <label class="text-[9px] font-black uppercase text-gray-500 ml-1 mb-1 block">Payment Method</label>
                    <select name="payment_method" id="payment_method" onchange="togglePaymentFields()" required class="w-full bg-background-dark border-white/5 rounded-xl p-4 text-white outline-none focus:ring-1 focus:ring-primary font-bold">
                        <option value="Cash">Cash (Pay at Gym)</option>
                        <option value="GCash">GCash (Upload Proof)</option>
                    </select>
                </div>
                <div id="gcash_details" class="hidden space-y-4">
                    <div class="bg-primary/10 border border-primary/20 p-3 rounded-xl text-center">
                        <p class="text-[9px] uppercase font-black text-primary tracking-widest">Send Payment To</p>
                        <p class="text-white font-black text-xl mt-1 tracking-wider">0992 699 7569</p>
                        <p class="text-[9px] text-gray-400 mt-1">HERDOZA FITNESS</p>
                    </div>
                    <input type="text" name="reference_number" id="ref_input" placeholder="GCash Reference No." class="w-full bg-background-dark border-white/5 rounded-xl p-4 text-white text-sm outline-none focus:ring-1 focus:ring-primary">
                    <div>
                        <label class="text-[9px] font-black uppercase text-gray-500 ml-1 mb-1 block">Proof Screenshot</label>
                        <input type="file" name="payment_proof" id="proof_input" accept="image/*" class="w-full bg-background-dark border-white/5 rounded-xl p-2 text-white text-xs">
                    </div>
                </div>
                <button type="submit" name="submit_booking" class="w-full bg-primary py-4 rounded-2xl font-black uppercase italic tracking-widest shadow-lg hover:scale-[1.02] transition-transform text-white">Book & Pay Now</button>
                <button type="button" onclick="closeBookingModal()" class="w-full text-gray-500 uppercase font-black text-[9px] tracking-widest mt-2 hover:text-white transition-colors">Cancel</button>
            </form>
        </div>
    </div>

</body>
</html>