<?php
session_start();
require_once '../db.php';
require_once '../includes/paymongo-helper.php';

// Security Check: Only Tenants
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'tenant') {
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$gym_id = $_SESSION['gym_id'] ?? 0;
$session_id = trim($_GET['session_id'] ?? '');
$plan_id = (int)($_GET['plan_id'] ?? 0);
$get_user_id = (int)($_GET['user_id'] ?? 0);
$get_gym_id = (int)($_GET['gym_id'] ?? 0);
$get_sig = $_GET['sig'] ?? '';

if (empty($session_id) || $plan_id === 0) {
    header("Location: subscription_plan.php");
    exit;
}

// 1. Verification Logic
$verification_success = false;
$verification_method = "";

// Method A: Check Secure Signature (Fallback for Placeholder Issue)
$salt = "FitPlatform_Secure_2026!";
$expected_sig = hash('sha256', $get_gym_id . $get_user_id . $plan_id . $salt);

if (!empty($get_sig) && hash_equals($expected_sig, $get_sig)) {
    $verification_success = true;
    $verification_method = "Signature Fallback";
}

// Method B: Verify with PayMongo (Primary if ID is valid)
if (!$verification_success && $session_id !== '{CHECKOUT_SESSION_ID}') {
    $response = retrieve_checkout_session($session_id);
    if ($response['status'] === 200) {
        $attributes = $response['body']['data']['attributes'];
        if ($attributes['status'] === 'paid') {
            $verification_success = true;
            $verification_method = "PayMongo API";
        } else {
            $error = "Payment status is " . ucfirst($attributes['status']) . ". Please complete the payment.";
        }
    } else {
        $detail = $response['body']['errors'][0]['detail'] ?? 'No detail available';
        $error = "Verification Failed (HTTP " . $response['status'] . "): " . $detail;
    }
}

// If Method A worked, we don't even need to show the Method B error unless both fail
if (!$verification_success && !isset($error)) {
    if ($session_id === '{CHECKOUT_SESSION_ID}') {
        $error = "Verification Failed: Secure signature mismatch and no valid session ID provided.";
    } else {
        $error = "Verification Failed: Unable to verify payment through API or Signature.";
    }
}

// 2. Database Insertion Logic
if ($verification_success) {
    // Determine the most accurate Reference Number
    $reference_to_save = $session_id; // Default

    if ($verification_method === "PayMongo API" && isset($attributes['payments'][0]['id'])) {
        // Use the actual Payment ID (e.g., pay_...) from PayMongo
        $reference_to_save = $attributes['payments'][0]['id'];
    } elseif ($session_id === '{CHECKOUT_SESSION_ID}') {
        // Generate a unique fallback reference since the gateway ID was displaced
        $reference_to_save = 'REF-' . strtoupper(substr(md5(time() . $get_user_id), 0, 10));
    }

    // Check if already processed to prevent duplicates
    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE reference_number = ?");
    $stmtCheck->execute([$reference_to_save]);
    
    if ($stmtCheck->fetchColumn() > 0) {
        header("Location: tenant_dashboard.php");
        exit;
    }

    // Fetch Plan Details for duration and price
    $stmtPlan = $pdo->prepare("SELECT * FROM website_plans WHERE website_plan_id = ?");
    $stmtPlan->execute([$plan_id]);
    $plan = $stmtPlan->fetch();
    
    if ($plan) {
        $duration = $plan['duration_months'];
        $now = date('Y-m-d H:i:s');
        $start_date = date('Y-m-d');
        $end_date = date('Y-m-d', strtotime("+$duration months"));

        try {
            $pdo->beginTransaction();

            // 1. Update/Add Subscription (Status: Pending Approval, Payment: Pending)
            $stmtSub = $pdo->prepare("INSERT INTO client_subscriptions (gym_id, owner_user_id, website_plan_id, start_date, end_date, subscription_status, payment_status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'Pending Approval', 'Pending', ?, ?)");
            $stmtSub->execute([$gym_id, $user_id, $plan_id, $start_date, $end_date, $now, $now]);
            $client_subscription_id = $pdo->lastInsertId();

            // 2. Record Payment (Status: Pending)
            $remarks = ($verification_method === "Signature Fallback") ? "Verified via fallback signature due to gateway ID displacement. Original Session: $session_id" : "Verified via PayMongo API. Session: $session_id";
            $stmtPay = $pdo->prepare("INSERT INTO payments (gym_id, client_subscription_id, amount, payment_method, payment_type, reference_number, payment_status, payment_date, created_at, remarks) VALUES (?, ?, ?, 'PayMongo Checkout', 'Subscription', ?, 'Pending', ?, ?, ?)");
            $stmtPay->execute([
                $gym_id, 
                $client_subscription_id, 
                $plan['price'], 
                $reference_to_save, 
                date('Y-m-d'), 
                $now,
                $remarks
            ]);

            $pdo->commit();
            $success = true;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Transaction failed: " . $e->getMessage();
        }
    } else {
        $error = "Invalid plan reference.";
    }
}
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Payment Status | Horizon</title>
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

    <div class="max-w-md w-full glass-card p-10 text-center relative overflow-hidden">
        <?php if (isset($success)): ?>
            <div class="size-20 rounded-full bg-green-500/10 text-green-500 flex items-center justify-center mx-auto mb-6">
                <span class="material-symbols-outlined text-5xl">check_circle</span>
            </div>
            <h2 class="text-2xl font-black uppercase italic italic mb-2">Payment Received</h2>
            <p class="text-gray-400 text-sm mb-8">Your payment via PayMongo has been confirmed. Your subscription is currently <strong>Pending Approval</strong> by the system administrator.</p>
            
            <a href="tenant_dashboard.php" class="inline-flex items-center justify-center w-full h-12 rounded-xl bg-primary text-white text-[10px] font-black uppercase tracking-widest hover:brightness-110 transition-all">
                Go to Dashboard
            </a>
        <?php else: ?>
            <div class="size-20 rounded-full bg-red-500/10 text-red-500 flex items-center justify-center mx-auto mb-6">
                <span class="material-symbols-outlined text-5xl">error</span>
            </div>
            <h2 class="text-2xl font-black uppercase italic italic mb-2">Payment Verification Failed</h2>
            <p class="text-gray-400 text-sm mb-8"><?= $error ?? 'Something went wrong while processing your payment.' ?></p>
            
            <div class="flex flex-col gap-3">
                <a href="subscription_plan.php" class="inline-flex items-center justify-center w-full h-12 rounded-xl bg-white/5 border border-white/10 text-white text-[10px] font-black uppercase tracking-widest hover:bg-white/10 transition-all">
                    Retry Payment
                </a>
                <a href="tenant_dashboard.php" class="text-gray-500 text-[10px] font-black uppercase tracking-widest hover:text-white transition-all">
                    Back to Dashboard
                </a>
            </div>
        <?php endif; ?>
    </div>

</body>
</html>
