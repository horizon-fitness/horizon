<?php
session_start();
require_once '../db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['reset_user_id'] ?? '';
    $verification_id = $_SESSION['reset_token_id'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $gym_slug = $_POST['gym'] ?? '';
    $gym_param = !empty($gym_slug) ? "?gym=" . urlencode($gym_slug) : "";

    if (empty($user_id) || empty($password) || empty($confirm_password)) {
        $_SESSION['reset_error'] = "All fields are required.";
        header("Location: ../reset_password.php" . $gym_param);
        exit;
    }

    if ($password !== $confirm_password) {
        $_SESSION['reset_error'] = "Passwords do not match.";
        header("Location: ../reset_password.php" . $gym_param);
        exit;
    }

    // Verify user still exists and token is valid (optional but good)
    $stmt = $pdo->prepare("SELECT * FROM user_verifications WHERE verification_id = ? AND user_id = ? AND status = 'pending' LIMIT 1");
    $stmt->execute([$verification_id, $user_id]);
    $verification = $stmt->fetch();

    if ($verification) {
        try {
            $pdo->beginTransaction();

            // Update password
            $password_hash = password_hash($password, PASSWORD_BCRYPT);
            $updateUser = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE user_id = ?");
            $updateUser->execute([$password_hash, $user_id]);

            // Mark token as verified
            $updateToken = $pdo->prepare("UPDATE user_verifications SET status = 'verified', verified_at = NOW() WHERE verification_id = ?");
            $updateToken->execute([$verification_id]);

            $pdo->commit();

            // Clear session
            unset($_SESSION['reset_user_id']);
            unset($_SESSION['reset_token_id']);

            $_SESSION['reset_success'] = "Password updated successfully! You can now login with your new credentials.";
            header("Location: ../login.php" . $gym_param);
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['reset_error'] = "A database error occurred. Please try again.";
            header("Location: ../reset_password.php" . $gym_param);
            exit;
        }
    } else {
        $_SESSION['reset_error'] = "Invalid session or session expired.";
        header("Location: ../forgot_password.php" . $gym_param);
        exit;
    }
} else {
    header("Location: ../login.php");
    exit;
}
