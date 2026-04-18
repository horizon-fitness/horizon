<?php
header('Content-Type: application/json');
require_once '../db.php';
require_once '../includes/mailer.php';

// Enable error reporting for debugging but catch output
error_reporting(E_ALL);
ini_set('display_errors', 0);

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

ob_start();

try {
    // Ensure user_verifications table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_verifications (
        verification_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        verification_type VARCHAR(50) NOT NULL,
        code VARCHAR(10) NOT NULL,
        status ENUM('pending', 'verified', 'expired') DEFAULT 'pending',
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        verified_at DATETIME NULL,
        INDEX (user_id),
        INDEX (code)
    )");

    if ($action === 'request_otp') {
        $email = trim($input['email'] ?? '');
        $tenant_code = trim($input['tenant_code'] ?? '');

        if (empty($email) || empty($tenant_code)) {
            throw new Exception("Email and Tenant Code are required.");
        }

        // 1. Find the Gym
        $stmtGym = $pdo->prepare("SELECT gym_id, gym_name FROM gyms WHERE tenant_code = ? LIMIT 1");
        $stmtGym->execute([$tenant_code]);
        $gym = $stmtGym->fetch();

        if (!$gym) {
            throw new Exception("Gym not found. Please check your connection.");
        }

        $gym_id = $gym['gym_id'];
        $gym_name = $gym['gym_name'];

        // 2. Find the User within THIS gym by joining with user_roles
        $stmtUser = $pdo->prepare("
            SELECT u.user_id, u.first_name 
            FROM users u
            JOIN user_roles ur ON u.user_id = ur.user_id
            WHERE u.email = ? AND ur.gym_id = ? AND u.is_active = 1 
            LIMIT 1
        ");
        $stmtUser->execute([$email, $gym_id]);
        $user = $stmtUser->fetch();

        if (!$user) {
            // Requirement: Block if no record or wrong details
            throw new Exception("Invalid credentials or no record found in $gym_name.");
        }

        $user_id = $user['user_id'];
        $first_name = $user['first_name'];

        // 3. Generate 6-digit OTP
        $otp = sprintf("%06d", mt_rand(1, 999999));
        $expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));

        // Deactivate old reset codes
        $pdo->prepare("UPDATE user_verifications SET status = 'expired' WHERE user_id = ? AND verification_type = 'mobile_password_reset' AND status = 'pending'")
            ->execute([$user_id]);

        // Insert new OTP
        $pdo->prepare("INSERT INTO user_verifications (user_id, verification_type, code, status, expires_at, created_at) VALUES (?, 'mobile_password_reset', ?, 'pending', ?, NOW())")
            ->execute([$user_id, $otp, $expires_at]);

        // 4. Send Branded Email
        $subject = "Password Reset Code for $gym_name";
        $email_content = "
            <p>Hi $first_name,</p>
            <p>We received a request to reset your password for your <strong>$gym_name</strong> account.</p>
            <p>Please use the following 6-digit code to proceed with your password reset:</p>
            <h2 style='color:#050505; letter-spacing: 5px; text-align: center; font-size: 32px; background:#f3f4f6; padding: 20px; border-radius: 12px;'>$otp</h2>
            <p>This code will expire in 15 minutes.</p>
            <p>If you didn't request this, you can safely ignore this email.</p>
            <br>
            <p>Securely yours,<br>The $gym_name Team</p>
        ";
        
        $body = getEmailTemplate("Password Recovery", $email_content);
        
        if (!sendSystemEmail($email, $subject, $body)) {
            throw new Exception("Failed to send email. Please try again later.");
        }

        ob_end_clean();
        echo json_encode([
            'success' => true,
            'message' => 'OTP sent to your email.',
            'user_id' => (int)$user_id
        ]);

    } elseif ($action === 'verify_otp') {
        $user_id = $input['user_id'] ?? '';
        $otp = $input['otp'] ?? '';

        $stmt = $pdo->prepare("SELECT * FROM user_verifications WHERE user_id = ? AND code = ? AND status = 'pending' AND expires_at > NOW() LIMIT 1");
        $stmt->execute([$user_id, $otp]);
        $ver = $stmt->fetch();

        if ($ver) {
            ob_end_clean();
            echo json_encode(['success' => true, 'message' => 'OTP verified.']);
        } else {
            throw new Exception("Invalid or expired code.");
        }

    } elseif ($action === 'update_password') {
        $user_id = $input['user_id'] ?? '';
        $otp = $input['otp'] ?? ''; // Re-verify for safety
        $new_password = $input['password'] ?? '';

        if (empty($new_password)) throw new Exception("New password required.");

        $stmt = $pdo->prepare("SELECT * FROM user_verifications WHERE user_id = ? AND code = ? AND status = 'pending' AND expires_at > NOW() LIMIT 1");
        $stmt->execute([$user_id, $otp]);
        $ver = $stmt->fetch();

        if ($ver) {
            $pdo->beginTransaction();
            
            // 1. Check if new password is same as old
            $stmtCurrent = $pdo->prepare("SELECT password_hash FROM users WHERE user_id = ?");
            $stmtCurrent->execute([$user_id]);
            $currentHash = $stmtCurrent->fetchColumn();

            if ($currentHash && password_verify($new_password, $currentHash)) {
                throw new Exception("New password cannot be the same as your old password. Please choose a different one.");
            }

            // 2. Update Password
            $hash = password_hash($new_password, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?")->execute([$hash, $user_id]);
            
            // 2. Mark OTP as used
            $pdo->prepare("UPDATE user_verifications SET status = 'verified', verified_at = NOW() WHERE verification_id = ?")->execute([$ver['verification_id']]);
            
            $pdo->commit();

            ob_end_clean();
            echo json_encode(['success' => true, 'message' => 'Password updated successfully.']);
        } else {
            throw new Exception("Session expired. Please restart the process.");
        }

    } else {
        throw new Exception("Invalid action.");
    }
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
