<?php
session_start();
require_once '../db.php';
require_once '../includes/mailer.php';

$user_id = $_SESSION['reset_user_id'] ?? null;
$email = $_SESSION['reset_email'] ?? null;
$gym_slug = $_GET['gym'] ?? '';
$gym_param = !empty($gym_slug) ? "?gym=" . urlencode($gym_slug) : "";

if (!$user_id || !$email) {
    header("Location: ../forgot_password.php" . $gym_param);
    exit;
}

// Generate new 6-digit OTP
$otp = sprintf("%06d", mt_rand(1, 999999));
$expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes'));

try {
    $pdo->beginTransaction();

    // Deactivate old reset codes for this user
    $stmtUpdate = $pdo->prepare("UPDATE user_verifications SET status = 'expired' WHERE user_id = ? AND verification_type = 'password_reset_otp' AND status = 'pending'");
    $stmtUpdate->execute([$user_id]);

    // Insert new OTP
    $stmtInsert = $pdo->prepare("INSERT INTO user_verifications (user_id, verification_type, code, status, expires_at, created_at) VALUES (?, 'password_reset_otp', ?, 'pending', ?, NOW())");
    $stmtInsert->execute([$user_id, $otp, $expires_at]);

    $pdo->commit();

    // Send Email
    $stmtUser = $pdo->prepare("SELECT first_name FROM users WHERE user_id = ?");
    $stmtUser->execute([$user_id]);
    $user = $stmtUser->fetch();

    $subject = "Your Horizon Password Reset Code (Resent)";
    $email_content = "
        <p>Hi " . htmlspecialchars($user['first_name'] ?? 'there') . ",</p>
        <p>We received a request to resend your password reset code.</p>
        <p>Please use the following 6-digit code to proceed:</p>
        <h2 style='color:#8c2bee; letter-spacing: 5px; text-align: center; font-size: 32px;'>$otp</h2>
        <p>This code will expire in 15 minutes.</p>
    ";
    
    $body = getEmailTemplate("Password Recovery", $email_content);
    
    if (sendSystemEmail($email, $subject, $body)) {
        $_SESSION['reset_success'] = "Verification code resent successfully!";
    } else {
        $_SESSION['reset_error'] = "Failed to send email. Please try again.";
    }

} catch (Exception $e) {
    $pdo->rollBack();
    $_SESSION['reset_error'] = "A database error occurred.";
}

header("Location: ../verify_reset_otp.php" . $gym_param);
exit;
