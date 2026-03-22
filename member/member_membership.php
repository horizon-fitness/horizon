<?php
session_start();
require_once '../db.php';

// Security Check: Only Members
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'member') {
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$gym_id = $_SESSION['gym_id'] ?? 0;

// Fetch member_id for this user
$stmtMember = $pdo->prepare("SELECT member_id FROM members WHERE user_id = ? AND gym_id = ? LIMIT 1");
$stmtMember->execute([$user_id, $gym_id]);
$member = $stmtMember->fetch();
$member_id = $member['member_id'] ?? 0;

// Fetch Transaction History for the current member
// We'll mock history for now or join with payments if available
$history_result = $pdo->prepare("SELECT payment_date, reference_number, amount, payment_status as status FROM payments WHERE member_id = ? ORDER BY created_at DESC");
$history_result->execute([$member_id]);
$history = $history_result->fetchAll(PDO::FETCH_ASSOC);

// Dummy fallback if $history_result is used with mysqli_fetch_assoc in the original code
// We'll update the loop to use foreach instead for consistency with PDO
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Membership Plans | Herdoza Fitness</title>
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

        function openPaymentModal(name, price) {
            document.getElementById('paymentModal').classList.remove('hidden');
            document.getElementById('paymentModal').classList.add('flex');
            document.getElementById('modal_plan_name').value = name;
            document.getElementById('modal_amount').value = price;
            document.getElementById('display_plan_name').innerText = name;
            document.getElementById('display_amount').innerText = "₱" + parseInt(price).toLocaleString();
        }

        function closePaymentModal() {
            document.getElementById('paymentModal').classList.add('hidden');
            document.getElementById('paymentModal').classList.remove('flex');
        }

        function toggleReferenceInput() {
            const method = document.getElementById('payment_method').value;
            const gcashDiv = document.getElementById('gcash_details_div');
            const refInput = document.getElementById('reference_input');
            const fileInput = document.getElementById('proof_input');
            
            if (method === 'GCash') {
                gcashDiv.classList.remove('hidden');
                refInput.required = true;
                fileInput.required = true;
            } else {
                gcashDiv.classList.add('hidden');
                refInput.required = false;
                fileInput.required = false;
                refInput.value = ''; 
                fileInput.value = '';
            }
        }
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; padding-bottom: 90px; }
        .plan-card { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        .plan-card:hover { transform: translateY(-8px); border-color: #8c2bee; box-shadow: 0 20px 40px -20px rgba(140, 43, 238, 0.3); }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        .nav-link { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; transition: all 0.2s; white-space: nowrap; }
        .active-nav { color: #8c2bee !important; position: relative; }
        .sidebar-link { display: flex; align-items: center; gap: 12px; padding: 12px 16px; border-radius: 12px; color: #9ca3af; transition: all 0.2s; font-size: 12px; font-weight: 700; text-transform: uppercase; }
        .sidebar-link:hover { background: rgba(140, 43, 238, 0.1); color: white; }
        .sidebar-active { background: #8c2bee; color: white !important; }
        .mobile-taskbar { background: rgba(20, 18, 26, 0.9); backdrop-filter: blur(20px); border-top: 1px solid rgba(255,255,255,0.05); }
        .alert-dot { animation: pulse 2s infinite; }
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.4; } 100% { opacity: 1; } }
    </style>
</head>
<body class="text-white antialiased font-display">

    <nav class="w-full bg-[#0a090d] border-b border-white/5 sticky top-0 z-50 h-20">
        <div class="max-w-[1500px] mx-auto px-6 flex items-center justify-between h-full">
            <div class="flex items-center gap-4">
                <div class="size-10 rounded-xl bg-[#7f13ec] flex items-center justify-center shadow-lg"><span class="material-symbols-outlined text-white text-2xl">bolt</span></div>
                <h1 class="text-2xl font-black italic uppercase tracking-tighter">Herdoza</h1>
            </div>
            
            <div class="hidden xl:flex items-center gap-8">
                <a href="dashboard.php" class="nav-link text-gray-400 hover:text-white">Dashboard</a>
                <a href="schedule_user.php" class="nav-link text-gray-400 hover:text-white">Book Session</a>
                <a href="dashboard_payment.php" class="nav-link text-gray-400 hover:text-white">Payment</a>
                <a href="member_membership.php" class="nav-link active-nav">Membership</a>
            </div>

            <div class="flex items-center gap-6">
                <div class="hidden md:block text-right border-r border-white/10 pr-6">
                    <p id="headerClock" class="text-white font-black italic text-sm leading-none">00:00:00 AM</p>
                    <p class="text-primary text-[8px] font-black uppercase mt-1 tracking-widest"><?= date('l, M d') ?></p>
                </div>
                <a href="member_profile.php" class="size-9 rounded-full bg-white/5 border border-white/10 flex items-center justify-center hover:border-primary transition-all">
                    <span class="material-symbols-outlined text-gray-400 text-xl">person</span>
                </a>
                <a href="../logout.php" class="text-gray-400 hover:text-red-500 transition-colors"><span class="material-symbols-outlined">logout</span></a>
            </div>
        </div>
    </nav>

    <div class="flex max-w-[1500px] mx-auto">
        <aside class="hidden lg:block w-64 sticky top-20 h-[calc(100vh-80px)] border-r border-white/5 p-6 space-y-2">
            <p class="text-[10px] font-black uppercase text-gray-500 tracking-[0.2em] mb-4 px-4">Menu</p>
            <a href="member_dashboard.php" class="sidebar-link">
                <span class="material-symbols-outlined text-xl">dashboard</span> Dashboard
            </a>
            <a href="member_booking.php" class="sidebar-link">
                <span class="material-symbols-outlined text-xl">calendar_month</span> Book Session
            </a>
            <a href="member_payment.php" class="sidebar-link">
                <span class="material-symbols-outlined text-xl">payments</span> Payment
            </a>
            <a href="member_membership.php" class="sidebar-link sidebar-active">
                <span class="material-symbols-outlined text-xl">card_membership</span> Membership
            </a>
            <a href="appointment.php" class="sidebar-link">
                <span class="material-symbols-outlined text-xl">event_available</span> Appointment
            </a>
            <div class="pt-8">
                <p class="text-[10px] font-black uppercase text-gray-500 tracking-[0.2em] mb-4 px-4">Account</p>
                <a href="edit_profile.php" class="sidebar-link">
                    <span class="material-symbols-outlined text-xl">settings</span> Settings
                </a>
                <a href="logout.php" class="sidebar-link hover:text-red-500">
                    <span class="material-symbols-outlined text-xl">logout</span> Logout
                </a>
            </div>
        </aside>

        <main class="flex-1 p-8">
            <?php if(isset($_GET['success'])): ?>
            <div class="mb-8 bg-emerald-500/10 border border-emerald-500/20 text-emerald-500 p-4 rounded-xl flex items-center gap-3">
                <span class="material-symbols-outlined">check_circle</span>
                <span class="text-sm font-bold">Payment submitted successfully! Please wait for approval.</span>
            </div>
            <?php endif; ?>

            <header class="mb-12">
                <h2 class="text-4xl font-black italic uppercase tracking-tighter">Choose Your <span class="text-primary">Strength</span></h2>
                <p class="text-gray-500 font-bold uppercase tracking-[0.2em] text-xs mt-2">Select a membership plan to unlock full gym access</p>
            </header>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-2 xl:grid-cols-3 gap-8 mb-16">
                <div class="plan-card bg-surface-dark border border-border-subtle p-8 rounded-[32px] flex flex-col">
                    <div class="mb-6"><span class="bg-primary/20 text-primary text-[10px] font-black px-3 py-1 rounded-full uppercase">Basic Access</span></div>
                    <h3 class="text-2xl font-black mb-2 uppercase italic">Daily Pass</h3>
                    <div class="flex items-baseline gap-1 mb-6">
                        <span class="text-4xl font-black text-white">₱150</span>
                        <span class="text-gray-500 text-sm italic">/day</span>
                    </div>
                    <ul class="space-y-4 mb-8 flex-1 text-sm text-gray-400">
                        <li class="flex items-center gap-2"><span class="material-symbols-outlined text-primary text-sm">check_circle</span> Single day access</li>
                        <li class="flex items-center gap-2"><span class="material-symbols-outlined text-primary text-sm">check_circle</span> All equipment use</li>
                    </ul>
                    <button onclick="openPaymentModal('Daily Pass', '150')" class="w-full py-4 bg-white text-black font-black rounded-2xl uppercase text-[10px] tracking-widest hover:bg-primary hover:text-white transition-all shadow-xl">Avail Plan</button>
                </div>

                <div class="plan-card bg-surface-dark border-2 border-primary p-8 rounded-[32px] flex flex-col relative overflow-hidden shadow-2xl shadow-primary/10">
                    <div class="absolute top-0 right-0 bg-primary text-white text-[8px] font-black px-4 py-1 uppercase rotate-45 translate-x-4 translate-y-2">Best Value</div>
                    <div class="mb-6"><span class="bg-primary/20 text-primary text-[10px] font-black px-3 py-1 rounded-full uppercase">Full Access</span></div>
                    <h3 class="text-2xl font-black mb-2 uppercase italic">Monthly Basic</h3>
                    <div class="flex items-baseline gap-1 mb-6">
                        <span class="text-4xl font-black text-white">₱1200</span>
                        <span class="text-gray-500 text-sm italic">/month</span>
                    </div>
                    <ul class="space-y-4 mb-8 flex-1 text-sm text-gray-400">
                        <li class="flex items-center gap-2"><span class="material-symbols-outlined text-primary text-sm">check_circle</span> Unlimited Gym Use</li>
                        <li class="flex items-center gap-2"><span class="material-symbols-outlined text-primary text-sm">check_circle</span> Locker Access Included</li>
                    </ul>
                    <button onclick="openPaymentModal('Monthly Basic', '1200')" class="w-full py-4 bg-primary text-white font-black rounded-2xl uppercase text-[10px] tracking-widest hover:opacity-90 transition-all shadow-lg shadow-primary/20">Avail Plan</button>
                </div>

                <div class="plan-card bg-surface-dark border border-border-subtle p-8 rounded-[32px] flex flex-col">
                    <div class="mb-6"><span class="bg-red-500/20 text-red-500 text-[10px] font-black px-3 py-1 rounded-full uppercase">Pro Training</span></div>
                    <h3 class="text-2xl font-black mb-2 uppercase italic">Personal Training</h3>
                    <div class="flex items-baseline gap-1 mb-6">
                        <span class="text-4xl font-black text-white">₱3500</span>
                        <span class="text-gray-500 text-sm italic">/month</span>
                    </div>
                    <ul class="space-y-4 mb-8 flex-1 text-sm text-gray-400">
                        <li class="flex items-center gap-2"><span class="material-symbols-outlined text-primary text-sm">check_circle</span> 10 Individual Sessions</li>
                        <li class="flex items-center gap-2"><span class="material-symbols-outlined text-primary text-sm">check_circle</span> Gym Use Included</li>
                    </ul>
                    <button onclick="openPaymentModal('Personal Training', '3500')" class="w-full py-4 bg-white text-black font-black rounded-2xl uppercase text-[10px] tracking-widest hover:bg-primary hover:text-white transition-all shadow-xl">Avail Plan</button>
                </div>
            </div>

            <div class="glass-card p-8 shadow-xl">
                <h4 class="text-xl font-black italic uppercase tracking-tighter mb-6">Payment <span class="text-primary">History</span></h4>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="text-[10px] font-black uppercase text-gray-500 tracking-widest border-b border-white/5">
                                <th class="pb-4">Date</th>
                                <th class="pb-4">Reference</th>
                                <th class="pb-4">Amount</th>
                                <th class="pb-4 text-right">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <?php if (empty($history)): ?>
                                <tr><td colspan="4" class="py-8 text-center text-xs font-bold text-gray-500 italic uppercase">No payment history found.</td></tr>
                            <?php else: ?>
                                <?php foreach($history as $row): ?>
                                <tr class="text-sm hover:bg-white/[0.02] transition-colors">
                                    <td class="py-5 text-gray-400"><?= date('M d, Y', strtotime($row['payment_date'])) ?></td>
                                    <td class="py-5 font-mono text-[10px] text-gray-300"><?= htmlspecialchars($row['reference_number']) ?></td>
                                    <td class="py-5 font-bold text-white">₱<?= number_format($row['amount'], 2) ?></td>
                                    <td class="py-5 text-right">
                                        <span class="px-3 py-1 rounded-full text-[9px] font-black uppercase tracking-tighter 
                                            <?= $row['status'] == 'Approved' ? 'bg-emerald-500/10 text-emerald-500 border border-emerald-500/20' : 'bg-amber-500/10 text-amber-500 border border-amber-500/20' ?>">
                                            <?= htmlspecialchars($row['status']) ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <div class="fixed bottom-0 left-0 right-0 h-20 mobile-taskbar z-[100] lg:hidden flex items-center justify-around px-4">
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
        <a href="member_membership.php" class="flex flex-col items-center gap-1 text-primary">
            <span class="material-symbols-outlined">card_membership</span>
            <span class="text-[8px] font-black uppercase">Plans</span>
        </a>
        <a href="member_appointment.php" class="flex flex-col items-center gap-1 text-gray-500">
            <span class="material-symbols-outlined">event_available</span>
            <span class="text-[8px] font-black uppercase">Appt</span>
        </a>
    </div>

    <!-- Payment Modal -->
<div id="paymentModal" class="fixed inset-0 z-[100] flex items-center justify-center p-6 bg-black/80 backdrop-blur-sm hidden">
    <div class="glass-card max-w-lg w-full p-8 shadow-2xl relative overflow-hidden">
        <div class="absolute -top-24 -right-24 w-48 h-48 bg-primary/10 blur-[60px] rounded-full"></div>
        
        <div class="flex justify-between items-center mb-8 relative z-10">
            <div>
                <h3 class="text-2xl font-black italic uppercase tracking-tighter text-white">Enroll in <span class="text-primary">Plan</span></h3>
                <p class="text-[10px] text-gray-500 font-bold uppercase tracking-widest mt-1">Select Payment Method</p>
            </div>
            <button onclick="closePaymentModal()" class="size-10 rounded-full bg-white/5 flex items-center justify-center text-gray-500 hover:text-white transition-all">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>

        <form action="action/submit_membership_payment.php" method="POST" enctype="multipart/form-data" class="space-y-6 relative z-10">
            <input type="hidden" name="plan_name" id="modal_plan_name">
            <input type="hidden" name="amount" id="modal_amount">

            <div class="p-4 rounded-xl bg-white/5 border border-white/5 flex justify-between items-center">
                <div>
                    <p class="text-[9px] text-gray-500 font-bold uppercase tracking-widest">Plan</p>
                    <p id="display_plan_name" class="text-sm font-black italic uppercase text-white">-</p>
                </div>
                <div class="text-right">
                    <p class="text-[9px] text-gray-500 font-bold uppercase tracking-widest">Amount</p>
                    <p id="display_amount" class="text-lg font-black text-primary italic uppercase">-</p>
                </div>
            </div>

            <div class="space-y-2">
                <label class="text-[10px] text-gray-500 font-bold uppercase tracking-widest ml-1 text-[10px] text-gray-500">Payment Method</label>
                <select name="payment_method" id="payment_method" onchange="toggleReferenceInput()" required class="w-full h-14 rounded-xl bg-white/5 border border-white/5 px-6 text-sm text-white focus:border-primary focus:outline-none transition-all">
                    <option value="" disabled selected>Select method...</option>
                    <option value="GCash">GCash</option>
                    <option value="Cash">Over-the-Counter (Cash)</option>
                </select>
            </div>

            <div id="gcash_details_div" class="hidden space-y-4">
                <div class="p-4 rounded-xl bg-blue-500/10 border border-blue-500/20">
                    <p class="text-[9px] text-blue-400 font-black uppercase tracking-widest mb-1 italic">GCash Account</p>
                    <p class="text-lg font-black text-white italic tracking-widest uppercase">0912-345-6789</p>
                    <p class="text-[9px] text-gray-500 uppercase">Herdoza Fitness Center</p>
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] text-gray-500 font-bold uppercase tracking-widest ml-1">Reference Number</label>
                    <input type="text" name="reference_number" id="reference_input" placeholder="Enter Reference No." class="w-full h-14 rounded-xl bg-white/5 border border-white/5 px-6 text-sm text-white font-mono tracking-widest focus:border-primary focus:outline-none transition-all">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] text-gray-500 font-bold uppercase tracking-widest ml-1">Proof of Payment</label>
                    <input type="file" name="proof_of_payment" id="proof_input" class="w-full text-xs text-gray-500 file:mr-4 file:py-3 file:px-6 file:rounded-xl file:border-0 file:text-[10px] file:font-black file:uppercase file:bg-primary/20 file:text-primary hover:file:bg-primary/30 file:transition-all cursor-pointer">
                </div>
            </div>

            <button type="submit" class="w-full h-14 rounded-xl bg-primary hover:bg-primary-dark text-white font-black italic uppercase tracking-widest text-xs shadow-xl shadow-primary/20 transition-all hover:scale-[1.02] active:scale-[0.98]">
                Confirm Enrollment
            </button>
        </form>
    </div>
</div>

</body>
</html>