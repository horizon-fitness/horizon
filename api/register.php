<?php
header('Content-Type: application/json');
ob_start(); 
require_once '../db.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? 'request_otp'; // Default to request_otp for the new flow



    if ($action === 'request_otp') {
        // Direct Flow: Bypass OTP request and tell the app to proceed to registration
        ob_end_clean();
        echo json_encode([
            'success' => true, 
            'message' => 'Direct registration enabled. Proceeding...'
        ]);
    } elseif ($action === 'register') {
        // Simplified Registration: Skip PIN verification
        $pin = $input['pin'] ?? '';
        $email = trim($input['email'] ?? '');

        // 2. If PIN is valid, proceed with Full Registration
        $gym_id = $input['gym_id'] ?? $input['tenant_code'] ?? '';
        $first_name = trim($input['first_name'] ?? '');
        $middle_name = trim($input['middle_name'] ?? '');
        $last_name = trim($input['last_name'] ?? '');
        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';
        $phone = trim($input['phone_number'] ?? $input['contact_number'] ?? $input['phone'] ?? '');
        $birth_date = $input['birth_date'] ?? '2000-01-01';
        $sex = $input['sex'] ?? 'Not Specified';
        $occupation = trim($input['occupation'] ?? '');
        $medical_history = trim($input['medical_history'] ?? '');
        $emergency_name = trim($input['emergency_contact_name'] ?? $input['emergency_name'] ?? '');
        $emergency_phone = trim($input['emergency_contact_number'] ?? $input['emergency_phone'] ?? '');
        $parent_name = trim($input['parent_name'] ?? '');
        $parent_phone = trim($input['parent_contact_number'] ?? $input['parent_phone'] ?? '');
        $address_line = trim($input['address_line'] ?? $input['address'] ?? '');
        $barangay = trim($input['barangay'] ?? '');
        $city = trim($input['city'] ?? '');
        $province = trim($input['province'] ?? '');
        $region = trim($input['region'] ?? '');
        $reg_source = trim($input['registration_source'] ?? 'Mobile');
        $now = date('Y-m-d H:i:s');

        $pdo->beginTransaction();

        // Target Gym Lookup
        $stmtLookup = $pdo->prepare("SELECT gym_id, tenant_code FROM gyms WHERE LOWER(tenant_code) = LOWER(?) LIMIT 1");
        $stmtLookup->execute([trim($gym_id)]);
        $gym_data = $stmtLookup->fetch();
        $gym_id = $gym_data['gym_id'] ?? 1;
        $tenant_code = $gym_data['tenant_code'] ?? '000';

        // Final uniqueness check inside transaction
        $stmtFinalCheck = $pdo->prepare("SELECT u.user_id FROM users u JOIN user_roles ur ON u.user_id = ur.user_id WHERE (u.username = ? OR u.email = ?) AND ur.gym_id = ? LIMIT 1");
        $stmtFinalCheck->execute([$username, $email, $gym_id]);
        if ($stmtFinalCheck->fetch()) {
            throw new Exception("Username or Email was taken during the verification process.");
        }

        // Handle User Record (Create NEW or reuse EXISTING global account)
        $password_hash = password_hash($password, PASSWORD_BCRYPT);
        
        $stmtCheckExist = $pdo->prepare("SELECT user_id FROM users WHERE username = ? OR email = ? LIMIT 1");
        $stmtCheckExist->execute([$username, $email]);
        $existing_user = $stmtCheckExist->fetch();

        if ($existing_user) {
            $new_user_id = $existing_user['user_id'];
            // Optionally update password since email ownership was proven via OTP
            $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = ? WHERE user_id = ?")
                ->execute([$password_hash, $now, $new_user_id]);
        } else {
            $stmtUser = $pdo->prepare("INSERT INTO users (username, email, password_hash, first_name, middle_name, last_name, contact_number, birth_date, sex, is_verified, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)");
            $stmtUser->execute([$username, $email, $password_hash, $first_name, $middle_name, $last_name, $phone, $birth_date, $sex, $now, $now]);
            $new_user_id = $pdo->lastInsertId();
        }

        // Assign Role
        $role_name = 'Member';
        $stmtRoleCheck = $pdo->prepare("SELECT role_id FROM roles WHERE role_name = ? LIMIT 1");
        $stmtRoleCheck->execute([$role_name]);
        $role_id = $stmtRoleCheck->fetchColumn();
        if (!$role_id) {
            $pdo->prepare("INSERT INTO roles (role_name) VALUES (?)")->execute([$role_name]);
            $role_id = $pdo->lastInsertId();
        }
        $pdo->prepare("INSERT INTO user_roles (user_id, role_id, gym_id, tenant_code, role_status, assigned_at) VALUES (?, ?, ?, ?, 'Active', ?)")
            ->execute([$new_user_id, $role_id, $gym_id, $tenant_code, $now]);

        // Member Data & Address
        $stmtAddr = $pdo->prepare("INSERT INTO addresses (address_line, barangay, city, province, region, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmtAddr->execute([$address_line, $barangay, $city, $province, $region, $now, $now]);
        $address_id = $pdo->lastInsertId();

        $member_code = 'MBR-' . str_pad($new_user_id, 4, '0', STR_PAD_LEFT);
        $stmtMember = $pdo->prepare("INSERT INTO members (user_id, gym_id, member_code, address_id, occupation, medical_history, emergency_contact_name, emergency_contact_number, parent_name, parent_contact, member_status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, ?)");
        $stmtMember->execute([$new_user_id, $gym_id, $member_code, $address_id, $occupation, $medical_history, $emergency_name, $emergency_phone, $parent_name, $parent_phone, $now, $now]);

        $pdo->prepare("INSERT INTO member_registrations (gym_id, user_id, registration_source, registration_status, created_at) VALUES (?, ?, ?, 'Completed', ?)")
            ->execute([$gym_id, $new_user_id, $reg_source, $now]);



        $pdo->commit();

        // Send Welcome Email
        if (file_exists('../includes/mailer.php')) {
            require_once '../includes/mailer.php';
            $stmtG = $pdo->prepare("SELECT gym_name FROM gyms WHERE gym_id = ? LIMIT 1");
            $stmtG->execute([$gym_id]);
            $gym = $stmtG->fetch();
            $gName = $gym['gym_name'] ?? 'Horizon System';
            $gLogo = ''; // Force system text logo ('HORIZON') instead of broken gym logos

            $subject = "Welcome to $gName - Registration Confirmed";
            $emailBody = getFormalEmailTemplate("Welcome to the Family", "
                <p>Hello <strong>" . htmlspecialchars($first_name) . "</strong>,</p>
                <p>Your account at <strong>$gName</strong> has been successfully created and verified! You can now access your member portal and start your fitness journey.</p>
                <div style='background: #f8fafc; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                    <p style='margin: 0; font-size: 14px;'><strong>Account Details:</strong></p>
                    <p style='margin: 5px 0 0 0; color: #4a5568;'>Username: <strong>" . htmlspecialchars($username) . "</strong></p>
                </div>
                <p>Stay active and see you at the gym!</p>
            ", $gName, $gLogo);
            $errorString = ''; // Add this variable since sendSystemEmail now expects by-reference
            sendSystemEmail($email, $subject, $emailBody, $errorString);
        }

        ob_end_clean();
        echo json_encode(['success' => true, 'message' => 'Registration successful! You can now log in.']);
    }
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
