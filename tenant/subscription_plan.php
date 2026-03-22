<?php
session_start();
require_once '../db.php';

// Security Check: Only Tenants (Gym Owners)
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'tenant') {
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$gym_id = $_SESSION['gym_id'] ?? 0;

// Check if already subscribed
$stmtSub = $pdo->prepare("SELECT * FROM client_subscriptions WHERE gym_id = ? AND subscription_status = 'Active' LIMIT 1");
$stmtSub->execute([$gym_id]);
$active_sub = $stmtSub->fetch();

if ($active_sub) {
    header("Location: tenant_dashboard.php");
    exit;
}

// --- SEED PLANS IF EMPTY ---
$plansCheck = $pdo->query("SELECT COUNT(*) FROM website_plans")->fetchColumn();
if ($plansCheck == 0) {
    $plans = [
        ['6-Month Kickstart', 5999.00, '6 Months', 6, 'Multi-Tenant Management, Core Analytics, Gym Page Customizer'],
        ['1-Year Momentum', 10999.00, '1 Year', 12, 'Revenue Reports, Priority Support, Gym Page Customizer'],
        ['3-Year Legacy', 27999.00, '3 Years', 36, 'White-Label Access, API Integration, Unlimited Team Accounts']
    ];
    $stmtSeed = $pdo->prepare("INSERT INTO website_plans (plan_name, price, billing_cycle, duration_months, features) VALUES (?, ?, ?, ?, ?)");
    foreach ($plans as $p) {
        $stmtSeed->execute([$p[0], $p[1], $p[2], $p[3], $p[4]]);
    }
}

// Fetch Plans
$plans = $pdo->query("SELECT * FROM website_plans WHERE is_active = 1")->fetchAll();

// Fetch Plan Details if paying
$pay_sub_id = isset($_GET['pay']) ? (int)$_GET['pay'] : 0;
$pay_plan_id = isset($_GET['plan']) ? (int)$_GET['plan'] : 0;
$selected_plan = null;
if ($pay_plan_id) {
    foreach($plans as $p) if($p['website_plan_id'] == $pay_plan_id) $selected_plan = $p;
}
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Select Plan | Horizon Partners</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "#8c2bee", "background-dark": "#0a090d", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)"}}}
        }
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        .payment-modal { background: rgba(10, 9, 13, 0.8); backdrop-filter: blur(12px); }
    </style>
</head>
<body class="antialiased min-h-screen flex flex-col items-center justify-center p-6">

    <div class="max-w-5xl w-full text-center mb-12">
        <div class="inline-flex items-center justify-center size-16 rounded-2xl bg-primary/10 text-primary mb-6">
            <span class="material-symbols-outlined text-4xl">workspace_premium</span>
        </div>
        <h2 class="text-4xl font-black italic uppercase tracking-tighter text-white mb-4">Choose Your <span class="text-primary">Growth Plan</span></h2>
        <p class="text-gray-500 text-sm font-bold uppercase tracking-widest">Select a plan to activate your gym's digital infrastructure</p>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="max-w-md w-full mb-8 p-6 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-center relative overflow-hidden">
            <div class="absolute top-0 right-0 p-2 opacity-20"><span class="material-symbols-outlined text-4xl">check_circle</span></div>
            <p class="text-[10px] font-black uppercase tracking-[0.2em] mb-1">Success</p>
            <h4 class="text-sm font-black italic uppercase italic">Payment Submitted Successfully</h4>
            <p class="text-xs font-medium text-gray-400 mt-2">Your subscription is now pending verification. We will notify you once activated.</p>
        </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="max-w-md w-full mb-8 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-sm text-center font-bold">
            <?= $error ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-8 max-w-6xl w-full">
        <?php foreach($plans as $plan): ?>
        <div class="glass-card p-10 flex flex-col hover:border-primary/50 transition-all group relative overflow-hidden">
            <?php if($plan['plan_name'] == 'Business Prime'): ?>
                <div class="absolute top-0 right-0 bg-primary text-white text-[9px] font-black uppercase px-4 py-2 rounded-bl-xl tracking-widest">Most Popular</div>
            <?php endif; ?>
            
            <h3 class="text-xl font-black uppercase italic mb-2"><?= htmlspecialchars($plan['plan_name']) ?></h3>
            <p class="text-gray-500 text-[10px] font-bold uppercase tracking-widest mb-6 border-b border-white/5 pb-6"><?= htmlspecialchars($plan['billing_cycle']) ?> Billing</p>
            
            <div class="mb-8">
                <span class="text-4xl font-black italic">₱<?= number_format($plan['price'], 0) ?></span>
                <span class="text-gray-600 text-[10px] font-black uppercase ml-1">/ <?= $plan['billing_cycle'] == 'Monthly' ? 'mo' : 'yr' ?></span>
            </div>

            <ul class="space-y-4 mb-10 flex-1">
                <?php foreach(explode(',', $plan['features']) as $feature): ?>
                <li class="flex items-start gap-3">
                    <span class="material-symbols-outlined text-primary text-lg">check_circle</span>
                    <span class="text-xs text-gray-400 font-medium leading-tight"><?= trim(htmlspecialchars($feature)) ?></span>
                </li>
                <?php endforeach; ?>
            </ul>

            <form action="action/select_subscription_plan.php" method="POST" class="h-full">
                <input type="hidden" name="plan_id" value="<?= $plan['website_plan_id'] ?>">
                <button type="submit" class="w-full h-12 rounded-xl border border-white/10 hover:bg-primary hover:text-white hover:border-primary transition-all text-[10px] font-black uppercase tracking-widest">
                    Select Plan
                </button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($pay_sub_id && $selected_plan): ?>
    <!-- Payment Modal -->
    <div id="paymentModal" class="fixed inset-0 z-50 flex items-center justify-center p-6 payment-modal">
        <div class="glass-card max-w-lg w-full p-8 shadow-2xl border-primary/20 relative overflow-hidden">
            <div class="absolute -top-24 -right-24 w-48 h-48 bg-primary/10 blur-[60px] rounded-full pointer-events-none"></div>
            
            <div class="flex justify-between items-center mb-8 relative z-10">
                <div>
                    <h3 class="text-2xl font-black italic uppercase tracking-tighter text-white">Complete <span class="text-primary">Payment</span></h3>
                    <p class="text-[10px] text-gray-500 font-bold uppercase tracking-widest mt-1">Subscription Activation Required</p>
                </div>
                <a href="subscription_plan.php" class="size-10 rounded-full bg-white/5 flex items-center justify-center text-gray-500 hover:text-white transition-all">
                    <span class="material-symbols-outlined">close</span>
                </a>
            </div>

            <div class="space-y-6 relative z-10">
                <div class="p-6 rounded-2xl bg-white/5 border border-white/5 flex justify-between items-center">
                    <div>
                        <p class="text-[10px] text-gray-500 font-bold uppercase tracking-widest mb-1">Plan Selected</p>
                        <p class="text-sm font-black italic uppercase text-white"><?= htmlspecialchars($selected_plan['plan_name']) ?></p>
                    </div>
                    <div class="text-right">
                        <p class="text-[10px] text-gray-500 font-bold uppercase tracking-widest mb-1">Amount Due</p>
                        <p class="text-xl font-black text-primary italic uppercase">₱<?= number_format($selected_plan['price'], 0) ?></p>
                    </div>
                </div>

                <div class="space-y-4">
                    <!-- Payment Selector -->
                    <div class="space-y-4 mb-4">
                        <div class="space-y-2">
                            <label class="text-[10px] text-gray-500 font-bold uppercase tracking-widest ml-1">Select Payment Method</label>
                            <select id="payment_method" onchange="togglePaymentDetails()" class="w-full h-14 rounded-xl bg-[#14121a] border border-white/10 px-6 text-sm text-white focus:border-primary focus:outline-none transition-all cursor-pointer">
                                <option value="GCash" class="bg-[#14121a] text-white">GCash</option>
                                <option value="Maya" class="bg-[#14121a] text-white">Maya</option>
                                <option value="Bank Transfer" class="bg-[#14121a] text-white">Bank Transfer (BDO)</option>
                            </select>
                        </div>

                        <!-- GCash Details -->
                        <div id="gcash_details" class="payment-detail-card p-6 rounded-2xl bg-gradient-to-br from-blue-600/20 to-blue-400/5 border border-blue-500/20 text-center">
                            <p class="text-[10px] text-blue-400 font-black uppercase tracking-[0.2em] mb-2">GCash Account</p>
                            <h4 class="text-2xl font-black text-white tracking-widest mb-1">0976-241-1986</h4>
                            <p class="text-xs text-gray-400 font-medium uppercase tracking-[0.1em]">Horizon Fitness Corp.</p>
                        </div>

                        <!-- Maya Details -->
                        <div id="maya_details" class="payment-detail-card p-6 rounded-2xl bg-gradient-to-br from-emerald-600/20 to-emerald-400/5 border border-emerald-500/20 text-center hidden">
                            <p class="text-[10px] text-emerald-400 font-black uppercase tracking-[0.2em] mb-2">Maya Business</p>
                            <h4 class="text-2xl font-black text-white tracking-widest mb-1">0917-888-2024</h4>
                            <p class="text-xs text-gray-400 font-medium uppercase tracking-[0.1em]">Horizon Systems Inc.</p>
                        </div>

                        <!-- Bank Details -->
                        <div id="bank_details" class="payment-detail-card p-6 rounded-2xl bg-gradient-to-br from-red-600/20 to-red-400/5 border border-red-500/20 text-center hidden">
                            <p class="text-[10px] text-red-500 font-black uppercase tracking-[0.2em] mb-2">BDO Unibank</p>
                            <h4 class="text-xl font-black text-white tracking-widest mb-1">0045-8023-1192</h4>
                            <p class="text-xs text-gray-400 font-medium uppercase tracking-[0.1em]">Horizon Fitness Solutions</p>
                        </div>
                    </div>

                    <form action="action/submit_subscription_payment.php" method="POST" class="space-y-4">
                        <input type="hidden" name="subscription_id" value="<?= $pay_sub_id ?>">
                        <input type="hidden" name="amount" value="<?= $selected_plan['price'] ?>">
                        <input type="hidden" name="payment_method" id="selected_method" value="GCash">
                        
                        <div class="space-y-2">
                            <label class="text-[10px] text-gray-500 font-bold uppercase tracking-widest ml-1">Reference Number</label>
                            <input type="text" name="reference_number" required placeholder="Enter Payment Reference No." 
                                   class="w-full h-14 rounded-xl bg-white/5 border border-white/5 px-6 text-sm text-white placeholder:text-gray-700 focus:border-primary focus:outline-none transition-all font-mono tracking-widest">
                        </div>

                        <button type="submit" class="w-full h-14 rounded-xl bg-primary hover:bg-primary-dark text-white font-black italic uppercase tracking-widest text-xs shadow-xl shadow-primary/20 transition-all hover:scale-[1.02] active:scale-[0.98]">
                            Submit Payment Verification
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <script>
        function togglePaymentDetails() {
            const method = document.getElementById('payment_method').value;
            document.getElementById('selected_method').value = method;
            
            // Hide all
            document.getElementById('gcash_details').classList.add('hidden');
            document.getElementById('maya_details').classList.add('hidden');
            document.getElementById('bank_details').classList.add('hidden');
            
            // Show selected
            if(method === 'GCash') document.getElementById('gcash_details').classList.remove('hidden');
            if(method === 'Maya') document.getElementById('maya_details').classList.remove('hidden');
            if(method === 'Bank Transfer') document.getElementById('bank_details').classList.remove('hidden');
        }
        </script>
    <?php endif; ?>
</body>
</html>
