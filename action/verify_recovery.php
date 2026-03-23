<?php
session_start();
require_once '../db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $otp_code = $_POST['otp_code'] ?? '';
    $user_id = $_SESSION['recovery_user_id'] ?? '';
    $gym_slug = $_POST['gym'] ?? '';
    $gym_param = !empty($gym_slug) ? "?gym=" . urlencode($gym_slug) : "";

    if (empty($otp_code) || empty($user_id)) {
        header("Location: ../forgot_password.php" . $gym_param);
        exit;
    }

    // Verify OTP
    $stmt = $pdo->prepare("SELECT * FROM user_verifications WHERE user_id = ? AND code = ? AND verification_type = 'password_reset' AND status = 'pending' AND expires_at > NOW() LIMIT 1");
    $stmt->execute([$user_id, $otp_code]);
    $verification = $stmt->fetch();

    if ($verification) {
        // Success
        $_SESSION['reset_user_id'] = $user_id;
        $_SESSION['reset_token_id'] = $verification['verification_id']; // For later marking as verified
        
        unset($_SESSION['recovery_user_id']);
        unset($_SESSION['recovery_email']);

        header("Location: ../reset_password.php" . $gym_param);
        exit;
    } else {
        $_SESSION['reset_error'] = "Invalid or expired verification code.";
        header("Location: ../verify_recovery_otp.php" . $gym_param);
        exit;
    }
} else {
    header("Location: ../login.php");
    exit;
}
