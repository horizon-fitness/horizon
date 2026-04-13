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

// --- BRANDING LOGIC ---
$stmtGlobal = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE user_id = 0");
$global_configs = $stmtGlobal->fetchAll(PDO::FETCH_KEY_PAIR);
$stmtUser = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = ?");
$stmtUser->execute([$user_id]);
$user_configs = $stmtUser->fetchAll(PDO::FETCH_KEY_PAIR);
$configs = array_merge($global_configs, $user_configs);

// Check if already subscribed or pending
$stmtSub = $pdo->prepare("SELECT * FROM client_subscriptions WHERE gym_id = ? AND subscription_status IN ('Active', 'Pending Approval') LIMIT 1");
$stmtSub->execute([$gym_id]);
$active_sub = $stmtSub->fetch();

if ($active_sub) {
    header("Location: tenant_dashboard.php");
    exit;
}

// Fetch Plans
$stmtPlans = $pdo->prepare("SELECT * FROM website_plans WHERE is_active = 1 ORDER BY price ASC");
$stmtPlans->execute();
$plans = $stmtPlans->fetchAll();

foreach ($plans as &$p) {
    $stmtF = $pdo->prepare("SELECT feature_name FROM website_plan_features WHERE website_plan_id = ?");
    $stmtF->execute([$p['website_plan_id']]);
    $p['features'] = $stmtF->fetchAll(PDO::FETCH_COLUMN);
}
unset($p); // Break the reference to the last element

// Check for recent rejection to show notice
$stmtRecent = $pdo->prepare("SELECT * FROM client_subscriptions WHERE gym_id = ? ORDER BY created_at DESC LIMIT 1");
$stmtRecent->execute([$gym_id]);
$recent_sub = $stmtRecent->fetch();
$show_rejection_notice = ($recent_sub && $recent_sub['payment_status'] === 'Rejected');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['plan_id'])) {
    $plan_id = (int)$_POST['plan_id'];
    $payment_term = $_POST['payment_term'] ?? 'Full';
    $payment_method = $_POST['payment_method'] ?? 'Online';
    
    $selected_plan = null;
    foreach($plans as $p) if($p['website_plan_id'] == $plan_id) $selected_plan = $p;
    
    if (!$selected_plan) {
        $error = "Invalid plan selected.";
    } else {
        $amount_to_pay = $selected_plan['price'];
        if ($payment_term === 'Monthly' && $selected_plan['duration_months'] > 1) {
            $amount_to_pay = round($selected_plan['price'] / $selected_plan['duration_months'], 2);
        }

        if ($payment_method === 'Cash') {
            // Manual OTC Process
            $start_date = date('Y-m-d');
            $end_date = date('Y-m-d', strtotime("+{$selected_plan['duration_months']} months"));
            $next_billing_date = null;
            if ($payment_term === 'Monthly') {
                $next_billing_date = date('Y-m-d', strtotime("+1 month"));
            }
            
            // Check if existing pending
            $pdo->prepare("DELETE FROM client_subscriptions WHERE gym_id = ? AND subscription_status = 'Pending Approval'")->execute([$gym_id]);

            $stmtIns = $pdo->prepare("
                INSERT INTO client_subscriptions (gym_id, owner_user_id, website_plan_id, start_date, end_date, next_billing_date, payment_term, subscription_status, payment_status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending Approval', 'Pending', NOW(), NOW())
            ");
            $stmtIns->execute([$gym_id, $user_id, $plan_id, $start_date, $end_date, $next_billing_date, $payment_term]);
            $subscription_id = $pdo->lastInsertId();

            $stmtPay = $pdo->prepare("INSERT INTO payments (gym_id, client_subscription_id, amount, payment_method, payment_type, reference_number, payment_status, created_at) VALUES (?, ?, ?, 'Cash', ?, 'OTC-MANUAL', 'Pending', NOW())");
            $stmtPay->execute([$gym_id, $subscription_id, $amount_to_pay, $payment_term === 'Full' ? 'Subscription' : 'Subscription Installment']);

            header("Location: tenant_dashboard.php");
            exit;

        } else {
            // PayMongo Online Process
            require_once '../includes/paymongo-helper.php';
            
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
            $host = $_SERVER['HTTP_HOST'];
            $current_dir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
            $base_url = rtrim($protocol . $host . $current_dir, '/');

            $salt = "FitPlatform_Secure_2026!";
            $signature = hash('sha256', $gym_id . $user_id . $plan_id . $salt);

            $success_url = $base_url . "/payment_success.php?plan_id=" . $plan_id . "&user_id=" . $user_id . "&gym_id=" . $gym_id . "&sig=" . $signature . "&payment_term=" . $payment_term . "&session_id={CHECKOUT_SESSION_ID}";
            $cancel_url = $base_url . "/payment_cancel.php";

            $stmtDetails = $pdo->prepare("
                SELECT u.first_name, u.last_name, u.email, u.contact_number, g.gym_name 
                FROM users u 
                JOIN gyms g ON g.owner_user_id = u.user_id 
                WHERE u.user_id = ? AND g.gym_id = ?
            ");
            $stmtDetails->execute([$user_id, $gym_id]);
            $details = $stmtDetails->fetch();

            $billing = [
                'name'  => ($details['first_name'] ?? 'User') . ' ' . ($details['last_name'] ?? ''),
                'email' => $details['email'] ?? '',
                'phone' => $details['contact_number'] ?? ''
            ];

            $metadata = [
                'gym_id'       => $gym_id,
                'gym_name'     => $details['gym_name'] ?? 'Unknown Gym',
                'plan_name'    => $selected_plan['plan_name'],
                'user_id'      => $user_id,
                'payment_term' => $payment_term
            ];
            
            $product_name = "Horizon Subscription: " . $selected_plan['plan_name'] . ($payment_term === 'Monthly' ? ' (Monthly Installment)' : '');

            $response = create_checkout_session($amount_to_pay, $product_name, $success_url, $cancel_url, $billing, $metadata);

            if ($response['status'] >= 200 && $response['status'] < 300) {
                $checkout_url = $response['body']['data']['attributes']['checkout_url'];
                header("Location: " . $checkout_url);
                exit;
            } else {
                $error = "PayMongo Error: " . ($response['body']['errors'][0]['detail'] ?? 'Failed to initiate payment.');
            }
        }
    }
}
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Select Plan | Horizon Systems</title>

    <link rel="preconnect" href="https://fonts.googleapis.com"/>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin=""/>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@300;400;500;600&family=Lexend:wght@300;400;500;700;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>

    <script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "<?= $configs['theme_color'] ?? '#7f13ec' ?>",
                        "primary-dark": "<?= $configs['theme_color'] ?? '#5e0eb3' ?>",
                        "background-dark": "<?= $configs['bg_color'] ?? '#050505' ?>", 
                        "surface-dark": "rgba(21, 21, 24, 0.4)",
                        "text-secondary": "#ab9db9"
                    },
                    fontFamily: { 
                        "display": ["<?= $configs['font_family'] ?? 'Lexend' ?>", "sans-serif"],
                        "sans": ["Plus Jakarta Sans", "Inter", "sans-serif"]
                    },
                    borderRadius: { 'custom': '12px' }
                },
            },
        }
    </script>
    <style>
        html, body { 
            background-color: #050505 !important; 
            color: #f3f4f6;
            margin: 0;
            padding: 0;
            min-height: 100vh;
        }
        
        .hero-glow {
            background-image: radial-gradient(circle at 50% -10%, rgba(127, 19, 236, 0.18), transparent 70%);
        }

        .dashboard-window {
            background: #08080a;
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 40px 100px -20px rgba(0, 0, 0, 1);
        }

        .plan-card {
            background: #0d0d10;
            border: 1px solid rgba(255, 255, 255, 0.05);
            transition: all 0.3s ease;
        }
        .plan-card:hover {
            border-color: #7f13ec;
            transform: translateY(-5px);
        }

        /* Minimalist "Hinde Ganong Kakita" Scrollbar */
        #plansSlider::-webkit-scrollbar {
            height: 4px;
            display: block !important;
        }
        #plansSlider::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.02);
            border-radius: 10px;
        }
        #plansSlider::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            transition: all 0.3s;
        }
        #plansSlider::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        /* IE/Firefox Hide (keeping only custom webkit one) */
        * { -ms-overflow-style: none; scrollbar-width: none; }
        #plansSlider { scrollbar-width: thin !important; scrollbar-color: rgba(255, 255, 255, 0.1) transparent !important; }

        #plansSlider { cursor: grab; user-select: none; }
        #plansSlider:active { cursor: grabbing; }

        .terms-box {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid rgba(255, 255, 255, 0.05);
            scrollbar-width: thin;
            scrollbar-color: rgba(127, 19, 236, 0.3) transparent;
        }
        .terms-box::-webkit-scrollbar {
            width: 4px;
        }
        .terms-box::-webkit-scrollbar-track {
            background: transparent;
        }
        .terms-box::-webkit-scrollbar-thumb {
            background: rgba(127, 19, 236, 0.3);
            border-radius: 10px;
        }
    </style>
</head>

<body class="font-sans antialiased min-h-screen flex flex-col hero-glow">

<header class="relative z-20 w-full px-8 py-6 flex justify-between items-center bg-transparent">
    <a href="../index.php" class="flex items-center gap-3 hover:opacity-80 transition-opacity">
        <div class="size-8 bg-primary/20 rounded-lg flex items-center justify-center border border-primary/30 overflow-hidden">
            <img src="../assests/horizon logo.png" alt="Horizon Logo" class="size-full object-contain">
        </div>
        <h2 class="text-lg font-display font-bold text-white uppercase italic tracking-tighter">Horizon <span class="text-primary">System</span></h2>
    </a>
    <div class="flex items-center gap-8">
        <a href="tenant_dashboard.php" class="text-[10px] font-display font-bold uppercase tracking-widest text-gray-500 hover:text-white transition-all flex items-center gap-2 italic">
            Go to Dashboard
            <span class="material-symbols-outlined text-sm">grid_view</span>
        </a>
        <a href="../logout.php" class="text-[10px] font-display font-bold uppercase tracking-widest text-gray-500 hover:text-rose-500 transition-all flex items-center gap-2 italic">
            <span class="material-symbols-outlined text-sm">logout</span>
            Back to Login
        </a>
    </div>
</header>

<main class="flex-1 flex flex-col items-center py-12 px-4 relative z-10 w-full overflow-x-hidden">
    <div class="w-full max-w-6xl">
        <div class="text-center mb-16">
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/5 border border-white/10 text-primary text-[9px] font-black uppercase tracking-[0.2em] mb-4">
                Partner Subscription
            </div>
            <h1 class="text-4xl md:text-5xl font-display font-black text-white uppercase italic tracking-tighter mb-4">Select your <span class="text-primary">Growth Plan</span></h1>
            <p class="text-xs text-gray-500 font-medium uppercase tracking-widest">Unlock your gym's full administrative potential</p>
            
            <?php if ($show_rejection_notice): ?>
                <div class="mt-8 max-w-2xl mx-auto p-6 rounded-2xl bg-red-500/10 border border-red-500/20 text-left relative overflow-hidden group">
                    <div class="absolute top-0 right-0 p-4 opacity-10 group-hover:scale-110 transition-transform">
                        <span class="material-symbols-outlined text-6xl text-red-500">cancel</span>
                    </div>
                    <div class="flex items-start gap-4">
                        <div class="size-10 rounded-xl bg-red-500/20 flex items-center justify-center shrink-0 border border-red-500/30">
                            <span class="material-symbols-outlined text-red-500">error</span>
                        </div>
                        <div>
                            <h4 class="text-sm font-black uppercase text-red-500 mb-1 tracking-tighter">Previous Payment Rejected</h4>
                            <p class="text-[10px] text-gray-400 font-bold leading-relaxed uppercase tracking-widest">Your last subscription attempt was not verified by the administrator. Please re-submit your payment or contact <span class="text-white">horizonfitnesscorp@gmail.com</span> for details.</p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="mt-6 max-w-md mx-auto p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-500 text-sm font-bold flex items-center justify-center gap-3">
                    <span class="material-symbols-outlined">error</span> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Scrollable Wrapper -->
        <div id="plansSlider" class="w-full overflow-x-auto snap-x snap-mandatory py-24 scroll-smooth no-scrollbar">
            <!-- Nested Flex Container for Safe Centering & Complete Visibility -->
            <div class="flex items-center min-w-max mx-auto px-12 gap-10">
                <?php foreach($plans as $plan): 
                    $isFeatured = !empty($plan['badge_text']);
                ?>
                <div class="plan-card rounded-2xl p-10 flex flex-col text-left shrink-0 w-[calc(100vw-4rem)] md:w-[400px] snap-start hover:border-primary/50 transition-all group relative overflow-hidden <?= $isFeatured ? 'border-primary/50 bg-primary/5 scale-105 shadow-2xl shadow-primary/20' : 'dashboard-window' ?>">
                    <?php if($isFeatured): ?>
                        <div class="absolute top-0 right-0 bg-primary text-white text-[8px] font-black uppercase px-4 py-2 rounded-bl-xl tracking-widest"><?= htmlspecialchars($plan['badge_text']) ?></div>
                    <?php endif; ?>

                    <div class="mb-8">
                        <h3 class="text-xl font-display font-black text-white uppercase italic mb-1"><?= htmlspecialchars($plan['plan_name']) ?></h3>
                        <p class="text-[9px] <?= $isFeatured ? 'text-primary' : 'text-gray-600' ?> font-bold uppercase tracking-[0.2em] italic"><?= htmlspecialchars($plan['billing_cycle']) ?> Billing</p>
                    </div>

                    <div class="mb-10 flex items-baseline gap-1">
                        <span class="text-4xl font-display font-black text-white italic">₱<?= number_format($plan['price']) ?></span>
                        <span class="text-[10px] text-gray-500 font-black uppercase tracking-widest">/ Term</span>
                    </div>

                    <ul class="space-y-4 mb-12 flex-grow">
                        <?php foreach($plan['features'] as $feature): ?>
                        <li class="flex items-center gap-3">
                            <span class="material-symbols-outlined text-primary text-lg font-black">check_circle</span>
                            <span class="text-xs text-gray-400 font-medium leading-tight italic"><?= htmlspecialchars($feature) ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>

                    <button type="button" onclick="openCheckoutModal(<?= $plan['website_plan_id'] ?>, '<?= htmlspecialchars(addslashes($plan['plan_name'])) ?>', <?= $plan['price'] ?>, <?= $plan['duration_months'] ?>)" class="w-full h-14 bg-white/5 border border-white/10 rounded-xl text-[10px] font-black uppercase tracking-widest text-white hover:bg-primary hover:border-primary hover:scale-[1.02] active:scale-95 transition-all group flex items-center justify-center gap-3 italic">
                        <span class="material-symbols-outlined text-lg group-hover:scale-110 transition-transform">payments</span>
                        Select Plan
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</main>

<footer class="relative z-20 w-full py-12 text-center">
    <p class="text-[9px] font-display font-bold text-gray-700 uppercase tracking-[0.4em]">
        © 2026 HORIZON SYSTEM. SECURE ENVIRONMENT. PROUDLY PARTNERED.
    </p>
</footer>

<!-- Checkout Modal -->
<div id="checkoutModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/80 backdrop-blur-md p-4">
    <div class="dashboard-window max-w-lg w-full p-8 rounded-3xl relative">
        <button type="button" onclick="closeCheckoutModal()" class="absolute top-6 right-6 text-gray-400 hover:text-white transition-all size-10 rounded-xl hover:bg-white/5 flex items-center justify-center">
            <span class="material-symbols-outlined">close</span>
        </button>
        
        <h3 class="text-2xl font-display font-black text-white uppercase italic tracking-tighter mb-2">Checkout Details</h3>
        <p class="text-[10px] text-gray-500 font-bold uppercase tracking-[0.2em] mb-8" id="modalPlanName">Selected Plan</p>
        
        <form method="POST">
            <input type="hidden" name="plan_id" id="modalPlanId" value="">
            
            <div class="mb-6">
                <p class="text-[9px] font-black uppercase text-primary tracking-[0.2em] mb-3">1. Payment Term</p>
                <div class="grid grid-cols-2 gap-3">
                    <label class="cursor-pointer">
                        <input type="radio" name="payment_term" value="Full" class="peer sr-only" checked onchange="updateCheckoutTotal()">
                        <div class="p-4 rounded-xl border border-white/10 bg-white/5 peer-checked:border-primary peer-checked:bg-primary/10 transition-all flex flex-col justify-center items-center h-full">
                            <span class="text-xs font-bold text-white uppercase tracking-normal mb-1">Full Payment</span>
                            <span class="text-[10px] text-primary font-black uppercase italic tracking-widest" id="lblFullPrice">₱0</span>
                        </div>
                    </label>
                    <label class="cursor-pointer" id="monthlyOptionContainer">
                        <input type="radio" name="payment_term" value="Monthly" class="peer sr-only" onchange="updateCheckoutTotal()">
                        <div class="p-4 rounded-xl border border-white/10 bg-white/5 peer-checked:border-primary peer-checked:bg-primary/10 transition-all flex flex-col justify-center items-center h-full text-center">
                            <span class="text-xs font-bold text-white uppercase tracking-normal mb-1">Monthly</span>
                            <span class="text-[10px] text-primary font-black uppercase italic tracking-widest" id="lblMonthlyPrice">₱0 / mo</span>
                        </div>
                    </label>
                </div>
            </div>

            <div class="mb-6 p-5 rounded-2xl terms-box">
                <p class="text-[9px] font-black uppercase text-primary tracking-[0.2em] mb-3">2. Payment Terms & Conditions</p>
                <div class="max-h-40 overflow-y-auto pr-2 custom-scrollbar">
                    <p class="text-[10px] text-gray-400 font-bold uppercase tracking-widest mb-4">Please review the summary below before proceeding.</p>
                    
                    <div class="space-y-4">
                        <div>
                            <p class="text-[10px] font-black text-white uppercase italic mb-1">1. Payment Authorization</p>
                            <p class="text-[10px] text-gray-500 font-medium leading-relaxed uppercase tracking-widest">By linking your payment method or clicking "Pay," you authorize us to charge the total amount displayed. For subscription plans, you authorize recurring charges (monthly or annually) until you manually cancel.</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-black text-white uppercase italic mb-1">2. Billing Cycle & Automatic Renewal</p>
                            <ul class="text-[10px] text-gray-500 font-medium leading-relaxed uppercase tracking-widest list-disc pl-4 space-y-1">
                                <li>Renewal: Your account will be charged automatically at the start of every billing period.</li>
                                <li>Notice: Any price changes will be sent to your registered email at least 30 days before the next charge.</li>
                            </ul>
                        </div>
                        <div>
                            <p class="text-[10px] font-black text-white uppercase italic mb-1">3. Cancellation & Refund Policy</p>
                            <ul class="text-[10px] text-gray-500 font-medium leading-relaxed uppercase tracking-widest list-disc pl-4 space-y-1">
                                <li>Cancellation: You can cancel anytime via your Dashboard/Settings. You will keep access until your current paid period ends.</li>
                                <li>Refunds: All sales are final. We do not offer pro-rated refunds for unused portions of a billing cycle unless there was a technical error on our part.</li>
                            </ul>
                        </div>
                        <div>
                            <p class="text-[10px] font-black text-white uppercase italic mb-1">4. Security & Data Privacy</p>
                            <p class="text-[10px] text-gray-500 font-medium leading-relaxed uppercase tracking-widest">We do not store your full credit card or e-wallet credentials on our servers. All transactions are processed through secure, encrypted third-party payment gateways.</p>
                        </div>
                    </div>
                </div>

                <div class="mt-6 pt-4 border-t border-white/5">
                    <label class="flex gap-3 cursor-pointer group">
                        <div class="relative flex items-center">
                            <input type="checkbox" id="termsAgreement" class="peer sr-only" onchange="togglePayButton()">
                            <div class="size-5 rounded-md border border-white/10 bg-white/5 peer-checked:bg-primary peer-checked:border-primary transition-all flex items-center justify-center">
                                <span class="material-symbols-outlined text-white text-sm scale-0 peer-checked:scale-100 transition-transform">check</span>
                            </div>
                        </div>
                        <span class="text-[9px] text-gray-500 font-bold uppercase tracking-widest group-hover:text-gray-300 transition-colors">
                            I have read and understood the informative summary above. I agree to the Terms of Service and Payment Policy, and I authorize this transaction and future recurring charges under these terms.
                        </span>
                    </label>
                </div>
            </div>

            <button type="submit" id="btnProceed" disabled class="w-full h-14 rounded-2xl bg-primary text-white text-[11px] font-black uppercase italic tracking-[0.2em] flex items-center justify-center gap-3 hover:scale-[1.02] active:scale-95 transition-all shadow-xl shadow-primary/20 group disabled:opacity-30 disabled:grayscale disabled:cursor-not-allowed disabled:hover:scale-100">
                <span class="material-symbols-outlined text-xl group-hover:scale-110 transition-transform" id="btnIcon">lock</span>
                <span id="btnText">Proceed to Payment</span>
            </button>
        </form>
    </div>
</div>

<script>
    function updateCheckoutTotal() {
        const term = document.querySelector('input[name="payment_term"]:checked').value;
        const btnText = document.getElementById('btnText');
        const btnIcon = document.getElementById('btnIcon');
        
        if (term === 'Monthly') {
            btnText.textContent = 'Pay Monthly Installment';
            btnIcon.textContent = 'calendar_month';
        } else {
            btnText.textContent = 'Proceed to Full Payment';
            btnIcon.textContent = 'lock';
        }
    }

    function openCheckoutModal(id, name, price, duration) {
        document.getElementById('modalPlanId').value = id;
        document.getElementById('modalPlanName').textContent = name;
        
        let monthly = duration > 0 ? (price / duration) : price;
        
        document.getElementById('lblFullPrice').textContent = '₱' + price.toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 0});
        document.getElementById('lblMonthlyPrice').textContent = '₱' + monthly.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' / mo';
        
        // Disable monthly option if it is only a 1-month plan
        const monthlyContainer = document.getElementById('monthlyOptionContainer');
        const monthlyRadio = document.querySelector('input[name="payment_term"][value="Monthly"]');
        
        if (duration <= 1) {
            monthlyContainer.classList.add('opacity-30', 'grayscale', 'cursor-not-allowed');
            monthlyRadio.disabled = true;
            document.querySelector('input[name="payment_term"][value="Full"]').checked = true;
        } else {
            monthlyContainer.classList.remove('opacity-30', 'grayscale', 'cursor-not-allowed');
            monthlyRadio.disabled = false;
        }

        // Reset Terms Agreement
        const agreement = document.getElementById('termsAgreement');
        agreement.checked = false;
        togglePayButton();

        updateCheckoutTotal(); // Sync UI state
        
        const modal = document.getElementById('checkoutModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function togglePayButton() {
        const checkbox = document.getElementById('termsAgreement');
        const btn = document.getElementById('btnProceed');
        btn.disabled = !checkbox.checked;
    }

    function closeCheckoutModal() {
        const modal = document.getElementById('checkoutModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    // --- Drag-to-Scroll Engine ---
    const slider = document.getElementById('plansSlider');
    let isDown = false;
    let startX;
    let scrollLeft;

    slider.addEventListener('mousedown', (e) => {
        isDown = true;
        slider.style.scrollSnapType = 'none'; // Temporarily disable snapping for smooth dragging
        slider.style.scrollBehavior = 'auto'; 
        startX = e.pageX - slider.offsetLeft;
        scrollLeft = slider.scrollLeft;
    });
    slider.addEventListener('mouseleave', () => {
        isDown = false;
        slider.style.scrollSnapType = 'x mandatory';
        slider.style.scrollBehavior = 'smooth';
    });
    slider.addEventListener('mouseup', () => {
        isDown = false;
        slider.style.scrollSnapType = 'x mandatory';
        slider.style.scrollBehavior = 'smooth';
    });
    slider.addEventListener('mousemove', (e) => {
        if (!isDown) return;
        e.preventDefault();
        const x = e.pageX - slider.offsetLeft;
        const walk = (x - startX) * 2; 
        slider.scrollLeft = scrollLeft - walk;
    });
</script>

</body>
</html>
