<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once '../db.php';
require_once '../includes/mailer.php';

if (!isset($_SESSION['staged_coach_app'])) {
    header("Location: ../coach/coach_application.php");
    exit;
}

$staged = $_SESSION['staged_coach_app'];
$gym_slug = $_GET['gym'] ?? '';

// Generate new OTP
$otp = sprintf("%06d", mt_rand(1, 999999));
$_SESSION['coach_otp'] = [
    'code' => $otp,
    'expires_at' => time() + (15 * 60)
];

$email = $staged['user']['email'];
$first_name = $staged['user']['first_name'];
$gym_id = $staged['application']['gym_id'];

// Get gym info for email
$stmtGym = $pdo->prepare("SELECT gym_name FROM gyms WHERE gym_id = ? LIMIT 1");
$stmtGym->execute([$gym_id]);
$gym_name = $stmtGym->fetchColumn() ?: "Horizon System";

// Send Email
$subject = "Verify Your Coach Application - {$gym_name}";
$content = "
    <p>Hello {$first_name},</p>
    <p>We received a request to resend your verification code for your coach application at <strong>{$gym_name}</strong>. Please use the verification code below:</p>
    <div style='background: #f1f5f9; padding: 24px; border-radius: 12px; margin: 30px 0; text-align: center;'>
        <span style='font-size: 32px; font-weight: 800; letter-spacing: 8px; color: #8c2bee;'>$otp</span>
    </div>
    <p>This code will expire in 15 minutes.</p>";

$body = getFormalEmailTemplate("Verify Your Identity", $content, $gym_name);

if (sendSystemEmail($email, $subject, $body)) {
    $_SESSION['verify_success'] = "Verification code resent successfully!";
} else {
    $_SESSION['verify_error'] = "Failed to send verification email.";
}

header("Location: ../coach/verify_coach.php?gym=" . urlencode($gym_slug));
exit;
?>
