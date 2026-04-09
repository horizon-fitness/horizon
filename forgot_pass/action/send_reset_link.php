<?php
session_start();
require_once '../../db.php';
require_once '../../includes/mailer.php';

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
        // Generate Token
        $token = bin2hex(random_bytes(32));
        $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        // Deactivate old reset tokens for this user
        $stmtUpdate = $pdo->prepare("UPDATE user_verifications SET status = 'expired' WHERE user_id = ? AND verification_type = 'password_reset' AND status = 'pending'");
        $stmtUpdate->execute([$user['user_id']]);

        // Insert new token
        $stmtInsert = $pdo->prepare("INSERT INTO user_verifications (user_id, verification_type, code, status, expires_at, created_at) VALUES (?, 'password_reset', ?, 'pending', ?, NOW())");
        $stmtInsert->execute([$user['user_id'], $token, $expires_at]);

        // Send Email
        $reset_link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . dirname($_SERVER['PHP_SELF'], 2) . "/reset_password.php?token=$token" . ($gym_slug ? "&gym=$gym_slug" : "");
        
        $subject = "Reset your Horizon password";
        $email_content = "
            <p>Hi " . htmlspecialchars($user['first_name']) . ",</p>
            <p>We received a request to reset your password for your Horizon account.</p>
            <p>Click the button below to set a new password. This link will expire in 1 hour.</p>
            <div style='text-align: center; margin: 30px 0;'>
                <a href='$reset_link' style='background-color: #8c2bee; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; font-weight: bold; display: inline-block;'>Reset Password</a>
            </div>
            <p>If you didn't request this, you can safely ignore this email.</p>
            <p style='font-size: 12px; color: #777;'>Or copy and paste this link into your browser:<br>$reset_link</p>
        ";
        
        $body = getEmailTemplate("Password Recovery", $email_content);
        
        if (sendSystemEmail($user['email'], $subject, $body)) {
            $_SESSION['reset_success'] = "Recovery link sent! Please check your email inbox.";
        } else {
            $_SESSION['reset_error'] = "Failed to send email. Please try again later.";
        }
    } else {
        // For security, don't reveal if user exists, but here we might want to be helpful or follow a standard
        $_SESSION['reset_success'] = "If an account with that email/username exists, a recovery link has been sent.";
    }

    header("Location: ../forgot_password.php" . $gym_param);
    exit;
} else {
    header("Location: ../../login.php");
    exit;
}
