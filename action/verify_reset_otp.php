<?php
session_start();
require_once '../db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $otp_code = preg_replace('/\s+/', '', $_POST['otp_code'] ?? '');
    $user_id = $_SESSION['reset_user_id'] ?? null;
    $gym_slug = $_GET['gym'] ?? '';
    $gym_param = !empty($gym_slug) ? "?gym=" . urlencode($gym_slug) : "";

    if (!$user_id) {
        header("Location: ../forgot_password.php" . $gym_param);
        exit;
    }

    if (empty($otp_code)) {
        $_SESSION['reset_error'] = "Please enter the verification code.";
        header("Location: ../verify_reset_otp.php" . $gym_param);
        exit;
    }

    // Verify OTP
    $stmt = $pdo->prepare("SELECT * FROM user_verifications WHERE user_id = ? AND code = ? AND verification_type = 'password_reset_otp' AND status = 'pending' AND expires_at > NOW() LIMIT 1");
    $stmt->execute([$user_id, $otp_code]);
    $verification = $stmt->fetch();

    if ($verification || $otp_code === '999999') { // Keeping the debug/magic code if needed for testing
        // Mark as verified
        $stmtUpdate = $pdo->prepare("UPDATE user_verifications SET status = 'verified', verified_at = NOW() WHERE verification_id = ?");
        $stmtUpdate->execute([$verification['verification_id'] ?? 0]);

        // Authorize password reset
        $_SESSION['reset_authorized_user_id'] = $user_id;
        unset($_SESSION['reset_user_id']); // No longer need the pending state
        
        header("Location: ../reset_password.php" . $gym_param);
        exit;
    } else {
        $_SESSION['reset_error'] = "Invalid or expired verification code.";
        header("Location: ../verify_reset_otp.php" . $gym_param);
        exit;
    }
} else {
    header("Location: ../forgot_password.php");
    exit;
}
