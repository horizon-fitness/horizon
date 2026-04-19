<?php
header('Content-Type: application/json');
ob_start(); 
require_once '../db.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? 'request_otp'; // Default to request_otp for the new flow



    if ($action === 'request_otp') {
        $gym_id = $input['gym_id'] ?? $input['tenant_code'] ?? '';
        $email = trim($input['email'] ?? '');
        $username = trim($input['username'] ?? '');
        
        // 1. Validate Gym
        $stmtGym = $pdo->prepare("SELECT gym_id FROM gyms WHERE LOWER(tenant_code) = LOWER(?) LIMIT 1");
        $stmtGym->execute([$gym_id]);
        $gData = $stmtGym->fetch();
        $target_gym_id = $gData['gym_id'] ?? 1;

        // 2. Separate Uniqueness Checks
        // Check Username
        $stmtU = $pdo->prepare("SELECT u.user_id FROM users u 
                                JOIN user_roles ur ON u.user_id = ur.user_id 
                                WHERE u.username = ? AND ur.gym_id = ? LIMIT 1");
        $stmtU->execute([$username, $target_gym_id]);
        if ($stmtU->fetch()) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => "The Username '$username' is already taken in this gym."]);
            exit;
        }

        // Check Email
        $stmtE = $pdo->prepare("SELECT u.user_id FROM users u 
                                JOIN user_roles ur ON u.user_id = ur.user_id 
                                WHERE u.email = ? AND ur.gym_id = ? LIMIT 1");
        $stmtE->execute([$email, $target_gym_id]);
        if ($stmtE->fetch()) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => "The Email '$email' is already registered in this gym."]);
            exit;
        }

        // 3. Handle Pending User Account
        $now = date('Y-m-d H:i:s');
        $stmtCheck = $pdo->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
        $stmtCheck->execute([$email]);
        $existing = $stmtCheck->fetch();

        if ($existing) {
            $target_user_id = $existing['user_id'];
        } else {
            // Create a placeholder user for verification
            // We use the email/username provided to reserve them
            $stmtPlaceholder = $pdo->prepare("INSERT INTO users (username, email, password_hash, first_name, last_name, contact_number, is_verified, created_at, updated_at) VALUES (?, ?, 'PENDING_REGISTRATION', 'Guest', 'User', '000', 0, ?, ?)");
            $stmtPlaceholder->execute([$username, $email, $now, $now]);
            $target_user_id = $pdo->lastInsertId();
        }

        // 4. Generate and Store OTP in user_verifications
        $otp_code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

        // Delete old pending verifications for this user
        $pdo->prepare("DELETE FROM user_verifications WHERE user_id = ? AND verification_type = 'registration'")->execute([$target_user_id]);
        
        $stmtV = $pdo->prepare("INSERT INTO user_verifications (user_id, verification_type, code, status, expires_at, created_at) VALUES (?, 'registration', ?, 'pending', ?, ?)");
        $stmtV->execute([$target_user_id, $otp_code, $expires, $now]);

        // 4. Send Email
        if (file_exists('../includes/mailer.php')) {
            require_once '../includes/mailer.php';
            $stmtG = $pdo->prepare("SELECT gym_name FROM gyms WHERE gym_id = ? LIMIT 1");
            $stmtG->execute([$target_gym_id]);
            $gymInfo = $stmtG->fetch();
            $gName = $gymInfo['gym_name'] ?? 'Horizon System';
            $gLogo = ''; // Force system text logo ('HORIZON') instead of broken gym logos

            $subject = "$gName - Your Verification PIN";
            $emailBody = getFormalEmailTemplate("Verify Your Account", "
                <p>Welcome to <strong>$gName</strong>!</p>
                <p>Thank you for initiating your membership registration. To verify your email address, please use the following One-Time Password (OTP):</p>
                <div style='background: #f1f5f9; padding: 20px; text-align: center; border-radius: 8px; margin: 25px 0;'>
                    <span style='font-size: 32px; font-weight: 800; letter-spacing: 5px; color: #1a202c;'>$otp_code</span>
                </div>
                <p style='font-size: 14px; color: #718096;'>This code is valid for 1 hour. If you did not request this registration, please ignore this email.</p>
            ", $gName, $gLogo);
            $errorString = '';
            $mailSent = sendSystemEmail($email, $subject, $emailBody, $errorString);
            
            if (!$mailSent) {
                // Delete the verification we just created since the email failed
                $pdo->prepare("DELETE FROM user_verifications WHERE user_id = ? AND code = ?")->execute([$target_user_id, $otp_code]);
                
                ob_end_clean();
                echo json_encode([
                    'success' => false, 
                    'message' => 'Failed to send Verification PIN. ' . ($errorString ?: 'SMTP Error. Ensure provided email is valid.')
                ]);
                exit;
            }
        }

        ob_end_clean();
        echo json_encode([
            'success' => true, 
            'message' => 'Verification PIN sent to your email.'
        ]);

    } elseif ($action === 'register') {
        $pin = $input['pin'] ?? '';
        $email = trim($input['email'] ?? '');
        
        // 1. Verify PIN First
        $stmtPin = $pdo->prepare("
            SELECT uv.*, u.user_id 
            FROM user_verifications uv 
            JOIN users u ON uv.user_id = u.user_id 
            WHERE u.email = ? AND uv.code = ? AND uv.verification_type = 'registration' 
            AND uv.status = 'pending' AND uv.expires_at > NOW() 
            LIMIT 1
        ");
        $stmtPin->execute([$email, $pin]);
        $verData = $stmtPin->fetch();

        if (!$verData) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Invalid or expired PIN. Please request a new one.']);
            exit;
        }

        $target_user_id = $verData['user_id'];

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

        // Handle User Record (Update the PENDING placeholder)
        $password_hash = password_hash($password, PASSWORD_BCRYPT);
        
        $stmtUpdateUser = $pdo->prepare("UPDATE users SET username = ?, email = ?, password_hash = ?, first_name = ?, middle_name = ?, last_name = ?, contact_number = ?, birth_date = ?, sex = ?, is_verified = 1, updated_at = ? WHERE user_id = ?");
        $stmtUpdateUser->execute([$username, $email, $password_hash, $first_name, $middle_name, $last_name, $phone, $birth_date, $sex, $now, $target_user_id]);
        $new_user_id = $target_user_id;

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
        $stmtMember = $pdo->prepare("INSERT INTO members (user_id, gym_id, member_code, address_id, occupation, medical_history, emergency_contact_name, emergency_contact_number, parent_name, parent_contact, registration_source, member_status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, ?)");
        $stmtMember->execute([$new_user_id, $gym_id, $member_code, $address_id, $occupation, $medical_history, $emergency_name, $emergency_phone, $parent_name, $parent_phone, $reg_source, $now, $now]);

        // Cleanup Verification
        $pdo->prepare("UPDATE user_verifications SET status = 'verified', verified_at = ? WHERE verification_id = ?")
            ->execute([$now, $verData['verification_id']]);

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
