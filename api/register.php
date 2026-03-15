<?php
header('Content-Type: application/json');
require_once '../db.php';

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? 'register';

if ($action === 'register') {
    $gym_id = $input['gym_id'] ?? '';
    $first_name = trim($input['first_name'] ?? '');
    $last_name = trim($input['last_name'] ?? '');
    $email = trim($input['email'] ?? '');
    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';
    $phone = trim($input['phone_number'] ?? '');
    $now = date('Y-m-d H:i:s');

    if (empty($gym_id) || empty($first_name) || empty($last_name) || empty($email) || empty($username) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
        exit;
    }

    // Resolve tenant_code if alphanumeric
    if (!is_numeric($gym_id)) {
        $stmtLookup = $pdo->prepare("SELECT gym_id FROM gyms WHERE tenant_code = ? LIMIT 1");
        $stmtLookup->execute([$gym_id]);
        $found_id = $stmtLookup->fetchColumn();
        if ($found_id) {
            $gym_id = $found_id;
        } else {
            echo json_encode(['success' => false, 'message' => "Tenant Code '$gym_id' not found."]);
            exit;
        }
    }

    try {
        $pdo->beginTransaction();

        // Check if username/email exists
        $stmtCheck = $pdo->prepare("SELECT user_id FROM users WHERE username = ? OR email = ? LIMIT 1");
        $stmtCheck->execute([$username, $email]);
        if ($stmtCheck->fetch()) {
            throw new Exception("Username or Email already exists.");
        }

        // 1. Create User Account
        $password_hash = password_hash($password, PASSWORD_BCRYPT);
        $stmtUser = $pdo->prepare("INSERT INTO users (username, email, password_hash, first_name, last_name, contact_number, is_verified, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)");
        $stmtUser->execute([$username, $email, $password_hash, $first_name, $last_name, $phone, $now, $now]);
        $new_user_id = $pdo->lastInsertId();

        // 2. Assign 'Member' Role
        $stmtRoleCheck = $pdo->prepare("SELECT role_id FROM roles WHERE role_name = 'Member' LIMIT 1");
        $stmtRoleCheck->execute();
        $role_id = $stmtRoleCheck->fetchColumn();

        $stmtUR = $pdo->prepare("INSERT INTO user_roles (user_id, role_id, gym_id, role_status, assigned_at) VALUES (?, ?, ?, 'Pending', ?)");
        $stmtUR->execute([$new_user_id, $role_id, $gym_id, $now]);

        // 3. Create Verification PIN (Synergy Flow)
        $otp_code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        $stmtV = $pdo->prepare("INSERT INTO user_verifications (user_id, gym_id, verification_type, code, status, expires_at, created_at) VALUES (?, ?, 'email', ?, 'pending', ?, ?)");
        $stmtV->execute([$new_user_id, $gym_id, $otp_code, $expires, $now]);

        $pdo->commit();

        // Send Email (Placeholder check for mailer)
        if (file_exists('../includes/mailer.php')) {
            require_once '../includes/mailer.php';
            $subject = "Your Verification PIN";
            $emailBody = getEmailTemplate("Verify Your Account", "<p>Your pin is: <strong>$otp_code</strong></p>");
            sendSystemEmail($email, $subject, $emailBody);
        }

        echo json_encode([
            'success' => true, 
            'message' => 'Registration initiated. Please verify with the PIN sent to your email.',
            'user_id' => $new_user_id
        ]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} elseif ($action === 'verify_pin') {
    $user_id = $input['user_id'] ?? '';
    $pin = $input['pin'] ?? '';

    if (empty($user_id) || empty($pin)) {
        echo json_encode(['success' => false, 'message' => 'User ID and PIN are required.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM user_verifications WHERE user_id = ? AND code = ? AND status = 'pending' AND expires_at > NOW() LIMIT 1");
    $stmt->execute([$user_id, $pin]);
    $ver = $stmt->fetch();

    if ($ver) {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE user_verifications SET status = 'verified', verified_at = NOW() WHERE verification_id = ?")->execute([$ver['verification_id']]);
        $pdo->prepare("UPDATE users SET is_verified = 1 WHERE user_id = ?")->execute([$user_id]);
        $pdo->prepare("UPDATE user_roles SET role_status = 'Active' WHERE user_id = ?")->execute([$user_id]);
        $pdo->commit();

        echo json_encode(['success' => true, 'message' => 'Account verified successfully.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid or expired PIN.']);
    }
}
