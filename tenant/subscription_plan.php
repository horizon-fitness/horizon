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

// Check if already subscribed or pending
$stmtSub = $pdo->prepare("SELECT * FROM client_subscriptions WHERE gym_id = ? AND subscription_status IN ('Active', 'Pending Approval') LIMIT 1");
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
        ['6-Month Kickstart', 24999.00, '6 Months', 6, 'Multi-Tenant Management, Base64 Document Engine, Core Analytics'],
        ['1-Year Momentum', 44999.00, '1 Year', 12, 'Advanced Revenue Reports, Priority Support, Gym Page Customizer'],
        ['3-Year Legacy', 99999.00, '3 Years', 36, 'Full White-Label Access, API Integration, Unlimited Team Accounts']
    ];
    $stmtSeed = $pdo->prepare("INSERT INTO website_plans (plan_name, price, billing_cycle, duration_months, features) VALUES (?, ?, ?, ?, ?)");
    foreach ($plans as $p) {
        $stmtSeed->execute([$p[0], $p[1], $p[2], $p[3], $p[4]]);
    }
}

// Fetch Plans
$plans = $pdo->query("SELECT * FROM website_plans WHERE is_active = 1")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['plan_id'])) {
    require_once '../includes/paymongo-helper.php';
    $plan_id = (int)$_POST['plan_id'];
    
    // Find plan details
    $selected_plan = null;
    foreach($plans as $p) if($p['website_plan_id'] == $plan_id) $selected_plan = $p;
    
    if (!$selected_plan) {
        $error = "Invalid plan selected.";
    } else {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'];
        
        // Since we're in /tenant/, we want the full path to it
        $current_dir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
        $base_url = rtrim($protocol . $host . $current_dir, '/');

        // Security Signature for fallback verification
        $salt = "FitPlatform_Secure_2026!";
        $signature = hash('sha256', $gym_id . $user_id . $plan_id . $salt);

        $success_url = $base_url . "/payment_success.php?plan_id=" . $plan_id . "&user_id=" . $user_id . "&gym_id=" . $gym_id . "&sig=" . $signature . "&session_id={CHECKOUT_SESSION_ID}";
        $cancel_url = $base_url . "/payment_cancel.php";

        // 1. Fetch Billing/Metadata Details for better PayMongo dashboard reflection
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
            'gym_id'    => $gym_id,
            'gym_name'  => $details['gym_name'] ?? 'Unknown Gym',
            'plan_name' => $selected_plan['plan_name'],
            'user_id'   => $user_id
        ];

        $response = create_checkout_session(
            $selected_plan['price'], 
            "Horizon Subscription: " . $selected_plan['plan_name'], 
            $success_url, 
            $cancel_url,
            $billing,
            $metadata
        );

        if ($response['status'] >= 200 && $response['status'] < 300) {
            $checkout_url = $response['body']['data']['attributes']['checkout_url'];
            header("Location: " . $checkout_url);
            exit;
        } else {
            $error = "PayMongo Error: " . ($response['body']['errors'][0]['detail'] ?? 'Failed to initiate payment.');
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
                        "primary": "#7f13ec",
                        "primary-dark": "#5e0eb3",
                        "background-dark": "#050505", 
                        "surface-dark": "rgba(21, 21, 24, 0.4)",
                        "text-secondary": "#ab9db9"
                    },
                    fontFamily: { 
                        "display": ["Lexend", "sans-serif"],
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

        /* Invisible Scroll System */
        *::-webkit-scrollbar { display: none !important; }
        * { -ms-overflow-style: none !important; scrollbar-width: none !important; }
    </style>
</head>

<body class="font-sans antialiased min-h-screen flex flex-col hero-glow">

<header class="relative z-20 w-full px-8 py-6 flex justify-between items-center bg-transparent">
    <a href="../index.php" class="flex items-center gap-3 hover:opacity-80 transition-opacity">
        <div class="size-8 bg-primary/20 rounded-lg flex items-center justify-center border border-primary/30">
            <span class="material-symbols-outlined text-primary text-xl">blur_on</span>
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
            
            <?php if (isset($error)): ?>
                <div class="mt-6 max-w-md mx-auto p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-500 text-sm font-bold flex items-center justify-center gap-3">
                    <span class="material-symbols-outlined">error</span> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <?php foreach($plans as $plan): ?>
            <div class="dashboard-window rounded-2xl p-10 flex flex-col text-left hover:border-primary/50 transition-all group relative overflow-hidden">
                <?php if($plan['plan_name'] == '1-Year Momentum'): ?>
                    <div class="absolute top-0 right-0 bg-primary text-white text-[8px] font-black uppercase px-4 py-2 rounded-bl-xl tracking-widest">Most Popular</div>
                <?php endif; ?>

                <div class="mb-8">
                    <h3 class="text-xl font-display font-black text-white uppercase italic mb-1"><?= htmlspecialchars($plan['plan_name']) ?></h3>
                    <p class="text-[9px] text-gray-600 font-bold uppercase tracking-[0.2em] italic"><?= htmlspecialchars($plan['billing_cycle']) ?> Billing</p>
                </div>

                <div class="mb-10 flex items-baseline gap-1">
                    <span class="text-4xl font-display font-black text-white italic">₱<?= number_format($plan['price'], 0) ?></span>
                    <span class="text-[10px] text-gray-500 font-black uppercase tracking-widest">/ Term</span>
                </div>

                <ul class="space-y-4 mb-12 flex-grow">
                    <?php foreach(explode(',', $plan['features']) as $feature): ?>
                    <li class="flex items-start gap-3">
                        <span class="material-symbols-outlined text-primary text-lg">check_circle</span>
                        <span class="text-xs text-gray-400 font-medium leading-tight italic"><?= trim(htmlspecialchars($feature)) ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>

                <form method="POST">
                    <input type="hidden" name="plan_id" value="<?= $plan['website_plan_id'] ?>">
                    <button type="submit" class="w-full h-14 bg-white/5 border border-white/10 rounded-xl text-[10px] font-black uppercase tracking-widest text-white hover:bg-primary hover:border-primary hover:scale-[1.02] active:scale-95 transition-all group flex items-center justify-center gap-3 italic">
                        <span class="material-symbols-outlined text-lg group-hover:scale-110 transition-transform">payments</span>
                        Select Plan
                    </button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</main>

<footer class="relative z-20 w-full py-12 text-center">
    <p class="text-[9px] font-display font-bold text-gray-700 uppercase tracking-[0.4em]">
        © 2026 HORIZON SYSTEM. SECURE ENVIRONMENT. PROUDLY PARTNERED.
    </p>
</footer>

</body>
</html>
