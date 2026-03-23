<?php
session_start();
require_once '../db.php';
require_once '../includes/mailer.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');
    $gym_slug = $_POST['gym'] ?? '';
    $gym_param = !empty($gym_slug) ? "?gym=" . urlencode($gym_slug) : "";

    if (empty($identifier)) {
        $_SESSION['reset_error'] = "Please enter your email or username.";
        header("Location: ../forgot_password.php" . $gym_param);
        exit;
    }

    // Find user - Explicit check for existence as requested
    $stmt = $pdo->prepare("SELECT user_id, email, first_name FROM users WHERE email = ? OR username = ? LIMIT 1");
    $stmt->execute([$identifier, $identifier]);
    $user = $stmt->fetch();

    if (!$user) {
        $_SESSION['reset_error'] = "Account not found. Please check your email or username.";
        header("Location: ../forgot_password.php" . $gym_param);
        exit;
    }

    // Generate 6-digit OTP
    $otp_code = sprintf("%06d", mt_rand(1, 999999)); 
    $expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes')); 
    
    // Deactivate old reset tokens/OTPs for this user
    $stmtUpdate = $pdo->prepare("UPDATE user_verifications SET status = 'expired' WHERE user_id = ? AND verification_type = 'password_reset' AND status = 'pending'");
    $stmtUpdate->execute([$user['user_id']]);

    // Insert new OTP
    $stmtInsert = $pdo->prepare("INSERT INTO user_verifications (user_id, verification_type, code, status, expires_at, created_at) VALUES (?, 'password_reset', ?, 'pending', ?, NOW())");
    $stmtInsert->execute([$user['user_id'], $otp_code, $expires_at]);

    // Send Email
    $subject = "Your Horizon Recovery Code";
    $email_content = "
        <p>Hi " . htmlspecialchars($user['first_name']) . ",</p>
        <p>We received a request to recover your Horizon account.</p>
        <p>Your 6-digit recovery code is:</p>
        <h2 style='color:#8c2bee; letter-spacing: 5px; text-align: center; font-size: 32px;'>$otp_code</h2>
        <p>This code will expire in 15 minutes.</p>
        <p>If you didn't request this, you can safely ignore this email.</p>
    ";
    
    $body = getEmailTemplate("Account Recovery", $email_content);
    
    if (sendSystemEmail($user['email'], $subject, $body)) {
        $_SESSION['recovery_user_id'] = $user['user_id'];
        $_SESSION['recovery_email'] = $user['email'];
        $_SESSION['reset_success'] = "Recovery code sent! Please check your email.";
        header("Location: ../verify_recovery_otp.php" . $gym_param);
        exit;
    } else {
        $_SESSION['reset_error'] = "Failed to send email. Please try again later.";
        header("Location: ../forgot_password.php" . $gym_param);
        exit;
    }
} else {
    header("Location: ../login.php");
    exit;
}
