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

    // Find user
    $stmt = $pdo->prepare("SELECT user_id, email, first_name FROM users WHERE email = ? OR username = ? LIMIT 1");
    $stmt->execute([$identifier, $identifier]);
    $user = $stmt->fetch();

    if ($user) {
        // Generate 6-digit OTP
        $otp = sprintf("%06d", mt_rand(1, 999999));
        $expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        
        // Deactivate old reset codes for this user
        $stmtUpdate = $pdo->prepare("UPDATE user_verifications SET status = 'expired' WHERE user_id = ? AND verification_type = 'password_reset_otp' AND status = 'pending'");
        $stmtUpdate->execute([$user['user_id']]);

        // Insert new OTP
        $stmtInsert = $pdo->prepare("INSERT INTO user_verifications (user_id, verification_type, code, status, expires_at, created_at) VALUES (?, 'password_reset_otp', ?, 'pending', ?, NOW())");
        $stmtInsert->execute([$user['user_id'], $otp, $expires_at]);

        // Send Email
        $subject = "Your Horizon Password Reset Code";
        $email_content = "
            <p>Hi " . htmlspecialchars($user['first_name']) . ",</p>
            <p>We received a request to reset your password for your Horizon account.</p>
            <p>Please use the following 6-digit code to proceed with your password reset:</p>
            <h2 style='color:#8c2bee; letter-spacing: 5px; text-align: center; font-size: 32px;'>$otp</h2>
            <p>This code will expire in 15 minutes.</p>
            <p>If you didn't request this, you can safely ignore this email.</p>
        ";
        
        $body = getEmailTemplate("Password Recovery", $email_content);
        
        if (sendSystemEmail($user['email'], $subject, $body)) {
            $_SESSION['reset_user_id'] = $user['user_id'];
            $_SESSION['reset_email'] = $user['email'];
            header("Location: ../verify_reset_otp.php" . $gym_param);
            exit;
        } else {
            $_SESSION['reset_error'] = "Failed to send email. Please try again later.";
        }
    } else {
        // Standard non-revealing message
        $_SESSION['reset_error'] = "Account not found. Please check your credentials.";
    }

    header("Location: ../forgot_password.php" . $gym_param);
    exit;
} else {
    header("Location: ../login.php");
    exit;
}
