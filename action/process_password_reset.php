<?php
session_start();
require_once '../db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['reset_authorized_user_id'] ?? null;
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $gym_slug = $_POST['gym'] ?? '';
    $gym_param = !empty($gym_slug) ? "?gym=" . urlencode($gym_slug) : "";

    if (!$user_id || empty($password) || empty($confirm_password)) {
        $_SESSION['reset_error'] = "Authorization failed or fields are missing.";
        header("Location: ../forgot_password.php" . $gym_param);
        exit;
    }

    if ($password !== $confirm_password) {
        $_SESSION['reset_error'] = "Passwords do not match.";
        header("Location: ../reset_password.php" . ($gym_slug ? "?gym=$gym_slug" : ""));
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Update password
        $password_hash = password_hash($password, PASSWORD_BCRYPT);
        $updateUser = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE user_id = ?");
        $updateUser->execute([$password_hash, $user_id]);

        $pdo->commit();

        unset($_SESSION['reset_authorized_user_id']); // Clear authorization
        $_SESSION['reset_success'] = "Password updated successfully! You can now login with your new credentials.";
        header("Location: ../login.php" . $gym_param);
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['reset_error'] = "A database error occurred. Please try again.";
        header("Location: ../reset_password.php" . ($gym_slug ? "?gym=$gym_slug" : ""));
        exit;
    }
} else {
    header("Location: ../login.php");
    exit;
}
