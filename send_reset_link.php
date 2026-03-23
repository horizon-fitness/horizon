<?php
session_start();
require_once '../db.php';
require_once '../includes/mailer.php';

if (!isset($_SESSION['verify_user_id'])) {
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['verify_user_id'];
$email = $_SESSION['verify_email'];
$gym_slug = $_GET['gym'] ?? '';
$gym_param = !empty($gym_slug) ? "?gym=" . urlencode($gym_slug) : "";

// Generate new OTP
$verification_code = sprintf("%06d", mt_rand(1, 999999)); 
$expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes')); 

try {
    $pdo->beginTransaction();

    // Deactivate old email OTPs for this user
    $stmtUpdate = $pdo->prepare("UPDATE user_verifications SET status = 'expired' WHERE user_id = ? AND verification_type = 'email' AND status = 'pending'");
    $stmtUpdate->execute([$user_id]);

    // Insert new OTP
    $stmtInsert = $pdo->prepare("INSERT INTO user_verifications (user_id, verification_type, code, status, expires_at, created_at) VALUES (?, 'email', ?, 'pending', ?, NOW())");
    $stmtInsert->execute([$user_id, $verification_code, $expires_at]);

    $pdo->commit();

    // Send Email
    $subject = "Your Horizon Verification Code";
    $email_content = "
        <p>To verify your email address, please use the following 6-digit code:</p>
        <h2 style='color:#7f13ec; letter-spacing: 5px; text-align: center;'>$verification_code</h2>
        <p>This code will expire in 15 minutes.</p>
        <p>If you didn't request this, you can safely ignore this email.</p>
    ";
    
    $body = getEmailTemplate("Verify your Identity", $email_content);
    
    if (sendSystemEmail($email, $subject, $body)) {
        $_SESSION['verify_success'] = "Verification code resent successfully!";
    } else {
        $_SESSION['verify_error'] = "Failed to send email. Please try again.";
    }

} catch (Exception $e) {
    $pdo->rollBack();
    $_SESSION['verify_error'] = "A database error occurred.";
}

header("Location: ../tenant/verify_email.php" . $gym_param);
exit;
