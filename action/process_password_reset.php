<?php
session_start();
require_once '../db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $gym_slug = $_POST['gym'] ?? '';
    $gym_param = !empty($gym_slug) ? "?gym=" . urlencode($gym_slug) : "";
    $user_id = $_SESSION['reset_authorized_user_id'] ?? null;

    if (!$user_id) {
        $_SESSION['reset_error'] = "Unauthorized access. Please restart the process.";
        header("Location: ../forgot_password.php" . $gym_param);
        exit;
    }

    if (empty($password) || empty($confirm_password)) {
        $_SESSION['reset_error'] = "All fields are required.";
        header("Location: ../reset_password.php" . $gym_param);
        exit;
    }

    if ($password !== $confirm_password) {
        $_SESSION['reset_error'] = "Passwords do not match.";
        header("Location: ../reset_password.php" . $gym_param);
        exit;
    }

    // Check if new password is same as old password
    $stmtCheckOld = $pdo->prepare("SELECT password_hash FROM users WHERE user_id = ? LIMIT 1");
    $stmtCheckOld->execute([$user_id]);
    $current_hash = $stmtCheckOld->fetchColumn();

    if ($current_hash && password_verify($password, $current_hash)) {
        $_SESSION['reset_error'] = "Your new password cannot be the same as your old one.";
        header("Location: ../reset_password.php" . $gym_param);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Update password
        $password_hash = password_hash($password, PASSWORD_BCRYPT);
        $updateUser = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE user_id = ?");
        $updateUser->execute([$password_hash, $user_id]);

        // Deactivate all password reset tokens for this user
        $updateToken = $pdo->prepare("UPDATE user_verifications SET status = 'verified', verified_at = NOW() WHERE user_id = ? AND verification_type = 'password_reset_otp' AND status = 'pending'");
        $updateToken->execute([$user_id]);

        $pdo->commit();

        // Clear reset session
        unset($_SESSION['reset_authorized_user_id']);
        unset($_SESSION['reset_user_id']);
        unset($_SESSION['reset_email']);

        $_SESSION['reset_success'] = "Password updated successfully! Redirecting to login page...";
        header("Location: ../reset_password.php" . $gym_param);
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['reset_error'] = "A database error occurred. Please try again.";
        header("Location: ../reset_password.php" . $gym_param);
        exit;
    }
} else {
    header("Location: ../login.php");
    exit;
}
