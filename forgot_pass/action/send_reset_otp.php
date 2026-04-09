<?php
session_start();
require_once '../../db.php';
require_once '../../includes/mailer.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');
    $gym_slug = $_POST['gym'] ?? '';
    $gym_param = !empty($gym_slug) ? "?gym=" . urlencode($gym_slug) : "";

    // --- LOOKUP ACCOUNT BY EMAIL (PRIORITIZE BUSINESS EMAIL) ---
    $user = null;
    $targetEmail = $identifier;

    // 1. Search in 'gyms' table (Business Email)
    $stmtGym = $pdo->prepare("SELECT owner_user_id, email FROM gyms WHERE email = ? LIMIT 1");
    $stmtGym->execute([$identifier]);
    $gym = $stmtGym->fetch();

    if ($gym) {
        $stmtUser = $pdo->prepare("SELECT user_id, email, first_name FROM users WHERE user_id = ? LIMIT 1");
        $stmtUser->execute([$gym['owner_user_id']]);
        $user = $stmtUser->fetch();
        $targetEmail = $gym['email'];
    } else {
        // 2. Search in 'gym_owner_applications' table (Pending Business Email)
        $stmtApp = $pdo->prepare("SELECT user_id, email FROM gym_owner_applications WHERE email = ? ORDER BY submitted_at DESC LIMIT 1");
        $stmtApp->execute([$identifier]);
        $app = $stmtApp->fetch();

        if ($app) {
            $stmtUser = $pdo->prepare("SELECT user_id, email, first_name FROM users WHERE user_id = ? LIMIT 1");
            $stmtUser->execute([$app['user_id']]);
            $user = $stmtUser->fetch();
            $targetEmail = $app['email'];
        } else {
            // 3. Fallback: Search in 'users' table (Personal Email)
            $stmtUser = $pdo->prepare("SELECT user_id, email, first_name FROM users WHERE email = ? LIMIT 1");
            $stmtUser->execute([$identifier]);
            $user = $stmtUser->fetch();
            
            if ($user) {
                // If found by personal email, check if there's a business email to redirect to
                $stmtCheckGym = $pdo->prepare("SELECT email FROM gyms WHERE owner_user_id = ? LIMIT 1");
                $stmtCheckGym->execute([$user['user_id']]);
                $cGym = $stmtCheckGym->fetch();
                if ($cGym && !empty($cGym['email'])) {
                    $targetEmail = $cGym['email'];
                }
            }
        }
    }

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
        
        if (sendSystemEmail($targetEmail, $subject, $body)) {
            $_SESSION['reset_user_id'] = $user['user_id'];
            $_SESSION['reset_email'] = $targetEmail;
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
    header("Location: ../../login.php");
    exit;
}
