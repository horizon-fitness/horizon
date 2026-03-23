<?php
session_start();
require_once '../db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['token'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $gym_slug = $_POST['gym'] ?? '';
    $gym_param = !empty($gym_slug) ? "?gym=" . urlencode($gym_slug) : "";

    if (empty($token) || empty($password) || empty($confirm_password)) {
        $_SESSION['reset_error'] = "All fields are required.";
        header("Location: ../reset_password.php?token=$token" . ($gym_slug ? "&gym=$gym_slug" : ""));
        exit;
    }

    if ($password !== $confirm_password) {
        $_SESSION['reset_error'] = "Passwords do not match.";
        header("Location: ../reset_password.php?token=$token" . ($gym_slug ? "&gym=$gym_slug" : ""));
        exit;
    }

    // Validate token again
    $stmt = $pdo->prepare("SELECT * FROM user_verifications WHERE code = ? AND verification_type = 'password_reset' AND status = 'pending' AND expires_at > NOW() LIMIT 1");
    $stmt->execute([$token]);
    $verification = $stmt->fetch();

    if ($verification) {
        try {
            $pdo->beginTransaction();

            // Update password
            $password_hash = password_hash($password, PASSWORD_BCRYPT);
            $updateUser = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE user_id = ?");
            $updateUser->execute([$password_hash, $verification['user_id']]);

            // Mark token as verified
            $updateToken = $pdo->prepare("UPDATE user_verifications SET status = 'verified', verified_at = NOW() WHERE verification_id = ?");
            $updateToken->execute([$verification['verification_id']]);

            $pdo->commit();

            $_SESSION['reset_success'] = "Password updated successfully! You can now login with your new credentials.";
            header("Location: ../login.php" . $gym_param);
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['reset_error'] = "A database error occurred. Please try again.";
            header("Location: ../reset_password.php?token=$token" . ($gym_slug ? "&gym=$gym_slug" : ""));
            exit;
        }
    } else {
        $_SESSION['reset_error'] = "Invalid or expired token.";
        header("Location: ../forgot_password.php" . $gym_param);
        exit;
    }
} else {
    header("Location: ../login.php");
    exit;
}
