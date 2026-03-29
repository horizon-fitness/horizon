<?php
/**
 * api/booking_success_redirect.php
 * Handles redirection and database updates after successful PayMongo payment for a gym booking.
 */
require_once '../db.php';

// Extraction of URL parameters
$gym_id = isset($_GET['gym_id']) ? (int)$_GET['gym_id'] : 0;
$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$service_id = isset($_GET['service_id']) ? (int)$_GET['service_id'] : 0;
$date = isset($_GET['date']) ? $_GET['date'] : '';
$time = isset($_GET['time']) ? $_GET['time'] : '';
$amount = isset($_GET['amount']) ? $_GET['amount'] : '0.00';
$signature = isset($_GET['sig']) ? $_GET['sig'] : '';

// 1. Security Check: Verify signature
$my_secret_salt = "FitPlatform_Secure_2026!"; 
$sig_input = $gym_id . $user_id . $service_id . $date . $time . $amount . $my_secret_salt;
$expected_signature = hash('sha256', $sig_input);

$db_status = "Pending";
$error_msg = "";

if ($gym_id > 0 && $user_id > 0 && $service_id > 0) {
    if (hash_equals($expected_signature, $signature)) {
        try {
            $pdo->beginTransaction();

            // Resolve member_id
            $stmtM = $pdo->prepare("SELECT member_id FROM members WHERE user_id = ? AND gym_id = ? LIMIT 1");
            $stmtM->execute([$user_id, $gym_id]);
            $member_id = $stmtM->fetchColumn();

            if (!$member_id) {
                $member_code = 'MBR-' . str_pad($user_id, 4, '0', STR_PAD_LEFT);
                $now = date('Y-m-d H:i:s');
                $stmtInsertM = $pdo->prepare("INSERT INTO members 
                    (user_id, gym_id, member_code, birth_date, sex, emergency_contact_name, emergency_contact_number, member_status, created_at, updated_at) 
                    VALUES (?, ?, ?, '2000-01-01', 'Not Specified', 'Not Provided', 'Not Provided', 'Active', ?, ?)");
                $stmtInsertM->execute([$user_id, $gym_id, $member_code, $now, $now]);
                $member_id = $pdo->lastInsertId();
            }

            $now = date('Y-m-d H:i:s');
            $booking_reference = 'BK-' . strtoupper(substr(md5(time() . $member_id), 0, 8));

            // 2. Create Booking Record (Status is Approved since it is paid)
            $stmtBook = $pdo->prepare("INSERT INTO bookings 
                (member_id, gym_id, gym_service_id, booking_reference, booking_date, start_time, end_time, booking_source, booking_status, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'Mobile App', 'Approved', ?, ?)");
            $stmtBook->execute([$member_id, $gym_id, $service_id, $booking_reference, $date, $time, $time, $now, $now]);
            $booking_id = $pdo->lastInsertId();

            // 3. Record Payment
            $payment_reference = 'PAYB-' . strtoupper(substr(md5(time() . $booking_id), 0, 8));
            $stmtPay = $pdo->prepare("INSERT INTO payments 
                (member_id, gym_id, booking_id, amount, payment_method, payment_type, reference_number, payment_status, created_at) 
                VALUES (?, ?, ?, ?, 'PayMongo', 'Booking', ?, 'Verified', ?)");
            $stmtPay->execute([$member_id, $gym_id, $booking_id, $amount, $payment_reference, $now]);

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
    $db_status = "Error: Missing booking details.";
}

// Deep Link Redirect back to app
$deep_link = "https://horizonfitnesscorp.gt.tc/portal.php?payment_status=" . ($db_status === "Success" ? "success" : "failed");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Confirming Booking...</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #0d0d0d; color: #fff; display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100vh; margin: 0; text-align: center; }
        .loader { border: 4px solid #1a1a1a; border-top: 4px solid #A855F7; border-radius: 50%; width: 50px; height: 50px; animation: spin 1s linear infinite; margin-bottom: 20px; }
        .success-icon { color: #A855F7; font-size: 60px; margin-bottom: 10px; display: none; }
        .error-box { background: rgba(255, 68, 68, 0.1); border: 1px solid #ff4444; color: #ff8888; padding: 20px; border-radius: 12px; max-width: 90%; word-break: break-word; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .btn { padding: 12px 24px; background: #A855F7; color: white; border: none; border-radius: 8px; text-decoration: none; font-weight: bold; margin-top: 20px; display: inline-block; cursor: pointer; }
    </style>
</head>
<body>
    <div class="loader" id="spinner"></div>
    <div class="success-icon" id="check">✓</div>
    <p id="status-text">Finalizing your booking details...</p>
    
    <?php if ($db_status !== "Success"): ?>
        <div class="error-box">
            <strong>Booking Failed</strong><br>
            <?php echo $db_status; ?><br>
            <?php if($error_msg) echo "<small>Details: " . $error_msg . "</small>"; ?>
            <br>
            <a href="<?php echo $deep_link; ?>" class="btn">Return to App</a>
        </div>
    <?php endif; ?>

    <script>
        <?php if ($db_status === "Success"): ?>
            document.getElementById('status-text').innerText = 'Booking Confirmed! Redirecting you back...';
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
