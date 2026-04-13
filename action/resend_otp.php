<?php
session_start();
require_once '../db.php';
require_once '../includes/mailer.php';

if (!isset($_SESSION['verify_user_id']) && !isset($_SESSION['staged_registration'])) {
    header("Location: ../login.php");
    exit;
}

$is_staged = isset($_SESSION['staged_registration']);
$gym_slug = $_GET['gym'] ?? '';
$gym_param = !empty($gym_slug) ? "?gym=" . urlencode($gym_slug) : "";

// Define recipient email
if ($is_staged) {
    $email = $_SESSION['staged_registration']['application']['email'];
} else {
    $email = $_SESSION['verify_email'];
}

// Generate new OTP
$verification_code = sprintf("%06d", mt_rand(1, 999999)); 
$expires_at_timestamp = time() + (15 * 60); // 15 minutes for session
$expires_at_db = date('Y-m-d H:i:s', $expires_at_timestamp);

try {
    if ($is_staged) {
        // Handle staged registration (Session only)
        $_SESSION['staged_otp'] = [
            'code' => $verification_code,
            'expires_at' => $expires_at_timestamp
        ];
        $otp_success = true;
    } else {
        // Handle existing registration (Database)
        $user_id = $_SESSION['verify_user_id'];
        $pdo->beginTransaction();

        // Deactivate old email OTPs for this user
        $stmtUpdate = $pdo->prepare("UPDATE user_verifications SET status = 'expired' WHERE user_id = ? AND verification_type = 'email' AND status = 'pending'");
        $stmtUpdate->execute([$user_id]);

        // Insert new OTP
        $stmtInsert = $pdo->prepare("INSERT INTO user_verifications (user_id, verification_type, code, status, expires_at, created_at) VALUES (?, 'email', ?, 'pending', ?, NOW())");
        $stmtInsert->execute([$user_id, $verification_code, $expires_at_db]);

        $pdo->commit();
        $otp_success = true;
    }

    if (!empty($otp_success)) {
        // Send Email
        $subject = "Your Horizon Verification Code";
        $email_content = "
            <div style='background-color:#f8fafc; padding: 40px; font-family: sans-serif; color: #1e293b;'>
                <p>To verify your email address, please use the following 6-digit code:</p>
                <div style='background: #f1f5f9; padding: 24px; border-radius: 12px; margin: 30px 0; text-align: center;'>
                    <span style='font-size: 32px; font-weight: 800; letter-spacing: 8px; color: #7f13ec;'>$verification_code</span>
                </div>
                <p>This code will expire in 15 minutes.</p>
                <p>If you didn't request this, please ignore this email.</p>
            </div>
        ";
        
        $body = getEmailTemplate("Verify your Identity", $email_content);
        
        if (sendSystemEmail($email, $subject, $body)) {
            $_SESSION['verify_success'] = "Verification code resent successfully!";
        } else {
            $_SESSION['verify_error'] = "Failed to send email. Please try again.";
        }
    }

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['verify_error'] = "An error occurred while resending the code: " . $e->getMessage();
}

header("Location: ../tenant/verify_email.php" . $gym_param);
exit;
?>
