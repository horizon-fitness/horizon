<?php
/**
 * api/payment_success_redirect.php
 * Handles redirection and database updates after successful PayMongo payment.
 */
require_once '../db.php';

// Extraction of URL parameters
$plan_id = isset($_GET['plan_id']) ? (int)$_GET['plan_id'] : 1;
$amount = isset($_GET['amount']) ? $_GET['amount'] : '0.00';
$gym_id = isset($_GET['gym_id']) ? (int)$_GET['gym_id'] : 0;
$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$signature = isset($_GET['sig']) ? $_GET['sig'] : '';

// 1. Security Check: Verify signature to prevent tampering
$my_secret_salt = "FitPlatform_Secure_2026!"; 
$expected_signature = hash('sha256', $gym_id . $user_id . $plan_id . $amount . $my_secret_salt);

$db_status = "Pending";
$error_msg = "";

if ($gym_id > 0 && $user_id > 0) {
    if (hash_equals($expected_signature, $signature)) {
        try {
            $pdo->beginTransaction();

            // Resolve member_id for this user at this gym
            $stmtM = $pdo->prepare("SELECT member_id FROM members WHERE user_id = ? AND gym_id = ? LIMIT 1");
            $stmtM->execute([$user_id, $gym_id]);
            $member_id = $stmtM->fetchColumn();

            if (!$member_id) {
                // Emergency: If member record doesn't exist, create a basic one
                $member_code = 'MBR-' . str_pad($user_id, 4, '0', STR_PAD_LEFT);
                $now = date('Y-m-d H:i:s');
                $stmtInsertM = $pdo->prepare("INSERT INTO members 
                    (user_id, gym_id, member_code, birth_date, sex, emergency_contact_name, emergency_contact_number, member_status, created_at, updated_at) 
                    VALUES (?, ?, ?, '2000-01-01', 'Not Specified', 'Not Provided', 'Not Provided', 'Active', ?, ?)");
                $stmtInsertM->execute([$user_id, $gym_id, $member_code, $now, $now]);
                $member_id = $pdo->lastInsertId();
            }

            // Get Plan details for duration & sessions
            $stmtPlan = $pdo->prepare("SELECT duration_value, session_limit, plan_name FROM membership_plans WHERE membership_plan_id = ?");
            $stmtPlan->execute([$plan_id]);
            $planDetails = $stmtPlan->fetch();
            
            $duration = $planDetails ? (int)$planDetails['duration_value'] : 30;
            $sessions = $planDetails ? (int)$planDetails['session_limit'] : 0;
            $plan_name = $planDetails ? $planDetails['plan_name'] : "Subscription";

            $start_date = date('Y-m-d');
            $end_date = date('Y-m-d', strtotime("+$duration days"));
            $now = date('Y-m-d H:i:s');

            // 2. Create/Update Subscription
            // Check if there's an existing active sub to update, or just create new
            $stmtSub = $pdo->prepare("INSERT INTO member_subscriptions 
                (member_id, membership_plan_id, start_date, end_date, sessions_total, sessions_used, subscription_status, payment_status, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, 0, 'Active', 'Paid', ?, ?)");
            $stmtSub->execute([$member_id, $plan_id, $start_date, $end_date, $sessions, $now, $now]);
            $subscription_id = $pdo->lastInsertId();

            // 3. Record Payment (Using 'Verified' status for dashboard integration)
            $reference_number = 'PAYM-' . strtoupper(substr(md5(time() . $member_id), 0, 8));
            $stmtPay = $pdo->prepare("INSERT INTO payments 
                (member_id, gym_id, subscription_id, amount, payment_method, payment_type, reference_number, payment_status, created_at) 
                VALUES (?, ?, ?, ?, 'PayMongo', 'Membership', ?, 'Verified', ?)");
            $stmtPay->execute([$member_id, $gym_id, $subscription_id, $amount, $reference_number, $now]);

            // 4. Activate Member status
            $stmtUpdateM = $pdo->prepare("UPDATE members SET member_status = 'Active', updated_at = ? WHERE member_id = ?");
            $stmtUpdateM->execute([$now, $member_id]);

            $pdo->commit();
            $db_status = "Success";
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $db_status = "Database Error";
            $error_msg = $e->getMessage();
        }
    } else {
        $db_status = "Security Error: Invalid Signature.";
    }
} else {
    $db_status = "Error: Missing ID details.";
}

// Redirect back to app - Deep Link from AndroidManifest.xml
$deep_link = "https://horizonfitnesscorp.gt.tc/portal.php?payment_status=" . ($db_status === "Success" ? "success" : "failed");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Updating Membership...</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #0d0d0d; color: #fff; display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100vh; margin: 0; text-align: center; }
        .loader { border: 4px solid #1a1a1a; border-top: 4px solid #007BFF; border-radius: 50%; width: 50px; height: 50px; animation: spin 1s linear infinite; margin-bottom: 20px; }
        .success-icon { color: #28a745; font-size: 60px; margin-bottom: 10px; display: none; }
        .error-box { background: rgba(255, 68, 68, 0.1); border: 1px solid #ff4444; color: #ff8888; padding: 20px; border-radius: 12px; max-width: 90%; word-break: break-word; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .btn { padding: 12px 24px; background: #007BFF; color: white; border: none; border-radius: 8px; text-decoration: none; font-weight: bold; margin-top: 20px; display: inline-block; cursor: pointer; }
    </style>
</head>
<body>
    <div class="loader" id="spinner"></div>
    <div class="success-icon" id="check">✓</div>
    <p id="status-text">Finalizing your membership details...</p>
    
    <?php if ($db_status !== "Success"): ?>
        <div class="error-box">
            <strong>Update Failed</strong><br>
            <?php echo $db_status; ?><br>
            <?php if($error_msg) echo "<small>Details: " . $error_msg . "</small>"; ?>
            <br>
            <a href="<?php echo $deep_link; ?>" class="btn">Return to App</a>
        </div>
    <?php endif; ?>

    <script>
        <?php if ($db_status === "Success"): ?>
            document.getElementById('status-text').innerText = 'Membership Activated! Redirecting you back...';
            document.getElementById('spinner').style.display = 'none';
            document.getElementById('check').style.display = 'block';
            setTimeout(() => {
                window.location.href = "<?php echo $deep_link; ?>";
            }, 2000);
        <?php else: ?>
            document.getElementById('spinner').style.display = 'none';
            document.getElementById('status-text').style.color = '#ff4444';
            document.getElementById('status-text').innerText = 'Something went wrong.';
        <?php endif; ?>
    </script>
</body>
</html>
