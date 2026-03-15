<?php
header('Content-Type: application/json');
require_once '../db.php';

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? 'register';

if ($action === 'register') {
    $gym_id = $input['gym_id'] ?? '';
    $first_name = trim($input['first_name'] ?? '');
    $middle_name = trim($input['middle_name'] ?? '');
    $last_name = trim($input['last_name'] ?? '');
    $email = trim($input['email'] ?? '');
    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';
    $phone = trim($input['phone'] ?? $input['phone_number'] ?? '');
    
    // Member specific fields
    $birth_date = $input['birth_date'] ?? '2000-01-01';
    $sex = $input['sex'] ?? 'Not Specified';
    $occupation = trim($input['occupation'] ?? '');
    $address = trim($input['address'] ?? '');
    $medical_history = trim($input['medical_history'] ?? '');
    $emergency_name = trim($input['emergency_name'] ?? $input['emergency_contact_name'] ?? '');
    $emergency_phone = trim($input['emergency_phone'] ?? $input['emergency_contact_number'] ?? '');

    $now = date('Y-m-d H:i:s');

    if (empty($gym_id) || empty($first_name) || empty($last_name) || empty($email) || empty($username) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
        exit;
    }

    $role_name = 'Member';
    $invitation_id = null;
    $tenant_code = null;

    // Resolve tenant_code or staff token if alphanumeric
    if (!is_numeric($gym_id)) {
        // 1. Check if it's a Staff Invitation Token
        $stmtInv = $pdo->prepare("SELECT invitation_id, gym_id, staff_role FROM staff_invitations WHERE token = ? AND invitation_status = 'Pending' LIMIT 1");
        $stmtInv->execute([$gym_id]);
        $inv = $stmtInv->fetch();

        if ($inv) {
            $gym_id = $inv['gym_id'];
            $role_name = $inv['staff_role']; // e.g., 'Admin', 'Coach'
            $invitation_id = $inv['invitation_id'];
            
            // Fetch tenant_code for this gym
            $stmtT = $pdo->prepare("SELECT tenant_code FROM gyms WHERE gym_id = ? LIMIT 1");
            $stmtT->execute([$gym_id]);
            $tenant_code = $stmtT->fetchColumn();
        } else {
            // 2. Check if it's a Gym Tenant Code (Walk-in Member)
            $stmtLookup = $pdo->prepare("SELECT gym_id FROM gyms WHERE tenant_code = ? LIMIT 1");
            $stmtLookup->execute([$gym_id]);
            $found_id = $stmtLookup->fetchColumn();
            if ($found_id) {
                $tenant_code = $gym_id; // Current gym_id is the code
                $gym_id = $found_id;
                $role_name = 'Member';
            } else {
                // Fallback Synergy: Default to Gym 1 (Horizon Systems) if code is unknown
                // This ensures every registration creates a role/membership context.
                $gym_id = 1; 
                $role_name = 'Member';
                $stmtT = $pdo->prepare("SELECT tenant_code FROM gyms WHERE gym_id = 1 LIMIT 1");
                $stmtT->execute();
                $tenant_code = $stmtT->fetchColumn() ?: '000';
            }
        }
    } else {
        // gym_id is numeric, fetch its tenant_code
        $stmtT = $pdo->prepare("SELECT tenant_code FROM gyms WHERE gym_id = ? LIMIT 1");
        $stmtT->execute([$gym_id]);
        $tenant_code = $stmtT->fetchColumn();
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
        $stmtUser = $pdo->prepare("INSERT INTO users (username, email, password_hash, first_name, middle_name, last_name, contact_number, is_verified, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?)");
        $stmtUser->execute([$username, $email, $password_hash, $first_name, $middle_name, $last_name, $phone, $now, $now]);
        $new_user_id = $pdo->lastInsertId();

        // 2. Assign Role (Robust Synergy with Web)
        $stmtRoleCheck = $pdo->prepare("SELECT role_id FROM roles WHERE role_name = ? LIMIT 1");
        $stmtRoleCheck->execute([$role_name]);
        $role_id = $stmtRoleCheck->fetchColumn();

        if (!$role_id) {
            $pdo->prepare("INSERT INTO roles (role_name) VALUES (?)")->execute([$role_name]);
            $role_id = $pdo->lastInsertId();
        }

        // Populate tenant_code in user_roles
        $stmtUR = $pdo->prepare("INSERT INTO user_roles (user_id, role_id, gym_id, tenant_code, role_status, assigned_at) VALUES (?, ?, ?, ?, 'Pending', ?)");
        $stmtUR->execute([$new_user_id, $role_id, $gym_id, $tenant_code, $now]);

        if ($role_name === 'Member') {
            // 3. Create Member Record (Full Profile Synergy)
            $member_code = 'MBR-' . str_pad($new_user_id, 4, '0', STR_PAD_LEFT);
            $stmtMember = $pdo->prepare("INSERT INTO members (user_id, gym_id, member_code, birth_date, sex, occupation, address, medical_history, emergency_contact_name, emergency_contact_number, member_status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, ?)");
            $stmtMember->execute([$new_user_id, $gym_id, $member_code, $birth_date, $sex, $occupation, $address, $medical_history, $emergency_name, $emergency_phone, $now, $now]);
        } else {
            // 3. Create Staff Record
            $stmtStaff = $pdo->prepare("INSERT INTO staff (user_id, gym_id, staff_role, employment_type, hire_date, status, created_at, updated_at) VALUES (?, ?, ?, 'Full-Time', ?, 'Active', ?, ?)");
            $stmtStaff->execute([$new_user_id, $gym_id, $role_name, date('Y-m-d'), $now, $now]);

            if ($invitation_id) {
                $stmtInvUpdate = $pdo->prepare("UPDATE staff_invitations SET invitation_status = 'Accepted', accepted_at = ? WHERE invitation_id = ?");
                $stmtInvUpdate->execute([$now, $invitation_id]);
            }
        }

        // 4. Create Verification PIN (Synergy Flow)
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
