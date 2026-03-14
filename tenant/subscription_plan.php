<?php
session_start();
require_once '../db.php';

// Security Check: Only Tenants (Gym Owners)
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'tenant') {
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$gym_id = $_SESSION['gym_id'];

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
    $now = date('Y-m-d H:i:s');
    $plans = [
        ['Basic Horizon', 1999.00, 'Monthly', 1, 'Single Location, Basic Analytics, Secure Tenant ID'],
        ['Business Prime', 4999.00, 'Monthly', 1, 'Multi-Tenant Management, Advanced Revenue Reports, Priority Uptime'],
        ['Enterprise', 15000.00, 'Yearly', 12, 'API Access, Custom Security, Dedicated Support']
    ];
    $stmtSeed = $pdo->prepare("INSERT INTO website_plans (plan_name, price, billing_cycle, duration_months, features, created_at) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($plans as $p) {
        $stmtSeed->execute([$p[0], $p[1], $p[2], $p[3], $p[4], $now]);
    }
}

// Fetch Plans
$plans = $pdo->query("SELECT * FROM website_plans WHERE is_active = 1")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['plan_id'])) {
    $plan_id = (int)$_POST['plan_id'];
    $now = date('Y-m-d H:i:s');
    $start_date = date('Y-m-d');
    
    // Default duration (if not found in plan)
    $duration = 1; 
    foreach($plans as $p) if($p['website_plan_id'] == $plan_id) $duration = $p['duration_months'];
    $end_date = date('Y-m-d', strtotime("+$duration months"));

    try {
        $pdo->beginTransaction();
        
        $stmtInsert = $pdo->prepare("INSERT INTO client_subscriptions (gym_id, owner_user_id, website_plan_id, start_date, end_date, subscription_status, payment_status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'Active', 'Paid', ?, ?)");
        $stmtInsert->execute([$gym_id, $user_id, $plan_id, $start_date, $end_date, $now, $now]);
        
        $pdo->commit();
        header("Location: tenant_dashboard.php");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Failed to process subscription: " . $e->getMessage();
    }
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

            <form method="POST">
                <input type="hidden" name="plan_id" value="<?= $plan['website_plan_id'] ?>">
                <button type="submit" class="w-full h-12 rounded-xl border border-white/10 hover:bg-primary hover:text-white hover:border-primary transition-all text-[10px] font-black uppercase tracking-widest">
                    Select Plan
                </button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>

</body>
</html>
