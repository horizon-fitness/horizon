<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/mailer.php';

/**
 * Handles the registration logic for new gym members.
 * Supports both public self-registration and staff-led walk-ins.
 */
function processMemberRegistration($pdo, $data) {
    $first_name = trim($data['first_name'] ?? '');
    $middle_name = trim($data['middle_name'] ?? '');
    $last_name = trim($data['last_name'] ?? '');
    $email = trim($data['email'] ?? '');
    $phone = trim($data['phone'] ?? $data['phone_number'] ?? '');
    $address = trim($data['address'] ?? '');
    $birth_date = $data['birth_date'] ?? '2000-01-01';
    $sex = $data['sex'] ?? 'Not Specified';
    $occupation = trim($data['occupation'] ?? '');
    $medical_history = trim($data['medical_history'] ?? '');
    $emergency_name = trim($data['emergency_name'] ?? $data['emergency_contact_name'] ?? '');
    $emergency_phone = trim($data['emergency_phone'] ?? $data['emergency_contact_number'] ?? '');
    $gym_id = $data['gym_id'];
    $source = $data['registration_source'] ?? 'Self'; // 'Self' or 'Walk-in'
    $registered_by = $data['registered_by_user_id'] ?? null;
    $now = date('Y-m-d H:i:s');

    if (empty($first_name) || empty($last_name) || empty($email)) {
        throw new Exception("First Name, Last Name, and Email are required.");
    }

    // Check if email already exists
    $stmtCheck = $pdo->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
    $stmtCheck->execute([$email]);
    if ($stmtCheck->fetch()) {
        throw new Exception("A user with this email address is already registered.");
    }

    // Handle Credentials
    $username = trim($data['username'] ?? '');
    $plain_password = $data['password'] ?? '';

    if (empty($username) || empty($plain_password)) {
        if ($source === 'Walk-in') {
            // Auto-generate if not provided for staff-led walk-in
            if (empty($username)) $username = strtolower($first_name . $last_name . rand(100, 999));
            if (empty($plain_password)) $plain_password = bin2hex(random_bytes(4));
        } else {
            // User-provided required for self-registration
            if (empty($plain_password)) throw new Exception("Password is required for registration.");
        }
    }

    // Double check username uniqueness
    $stmtUCheck = $pdo->prepare("SELECT user_id FROM users WHERE username = ? LIMIT 1");
    $stmtUCheck->execute([$username]);
    if ($stmtUCheck->fetch()) {
        throw new Exception("Username '$username' is already taken.");
    }

    try {
        $pdo->beginTransaction();

        // 1. Create User
        $password_hash = password_hash($plain_password, PASSWORD_BCRYPT);
        $stmtUser = $pdo->prepare("INSERT INTO users (username, email, password_hash, first_name, middle_name, last_name, contact_number, is_verified, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)");
        $stmtUser->execute([$username, $email, $password_hash, $first_name, $middle_name, $last_name, $phone, $now, $now]);
        $new_user_id = $pdo->lastInsertId();

        // 2. Assign 'Member' Role
        $role_name = 'Member';
        $stmtRoleCheck = $pdo->prepare("SELECT role_id FROM roles WHERE role_name = ? LIMIT 1");
        $stmtRoleCheck->execute([$role_name]);
        $role_id = $stmtRoleCheck->fetchColumn();

        if (!$role_id) {
            $pdo->prepare("INSERT INTO roles (role_name) VALUES (?)")->execute([$role_name]);
            $role_id = $pdo->lastInsertId();
        }

        // Fetch tenant_code for synergy
        $stmtT = $pdo->prepare("SELECT tenant_code FROM gyms WHERE gym_id = ? LIMIT 1");
        $stmtT->execute([$gym_id]);
        $tenant_code = $stmtT->fetchColumn() ?: '000';

        $stmtUR = $pdo->prepare("INSERT INTO user_roles (user_id, role_id, gym_id, tenant_code, role_status, assigned_at) VALUES (?, ?, ?, ?, 'Active', ?)");
        $stmtUR->execute([$new_user_id, $role_id, $gym_id, $tenant_code, $now]);

        // 3. Create Member Record
        $prefix = ($source === 'Walk-in') ? 'WALK-' : 'MBR-';
        $member_code = $prefix . str_pad($new_user_id, 4, '0', STR_PAD_LEFT);
        
        $stmtMember = $pdo->prepare("INSERT INTO members (user_id, gym_id, member_code, birth_date, sex, occupation, address, medical_history, emergency_contact_name, emergency_contact_number, member_status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, ?)");
        $stmtMember->execute([$new_user_id, $gym_id, $member_code, $birth_date, $sex, $occupation, $address, $medical_history, $emergency_name, $emergency_phone, $now, $now]);

        // 4. Log Registration
        $stmtReg = $pdo->prepare("INSERT INTO member_registrations (gym_id, user_id, email, registration_source, registered_by_user_id, registration_status, completed_at, created_at) VALUES (?, ?, ?, ?, ?, 'Completed', ?, ?)");
        $stmtReg->execute([$gym_id, $new_user_id, $email, $source, $registered_by, $now, $now]);

        // 5. Send Email
        $stmtGym = $pdo->prepare("SELECT gym_name FROM gyms WHERE gym_id = ?");
        $stmtGym->execute([$gym_id]);
        $gym = $stmtGym->fetch();
        $gymName = $gym['gym_name'] ?? 'Horizon Gym';

        $subject = ($source === 'Walk-in') ? "Your New Membership Account - $gymName" : "Welcome to $gymName - Your Account Details";
        $welcomeMsg = ($source === 'Walk-in') 
            ? "Your membership has been registered as a walk-in at <strong>$gymName</strong>."
            : "You have successfully registered at <strong>$gymName</strong>.";

        $emailBody = getEmailTemplate(
            "Welcome to the Community!",
            "<p>Hello $first_name,</p>
            <p>$welcomeMsg</p>
            <p>Your account is ready for use on our mobile application and web portal.</p>
            <div style='background: #f4f4f4; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                <strong>Username:</strong> $username<br>
                <strong>Password:</strong> $plain_password
            </div>
            <p>You can download our mobile app from the website to start tracking your progress!</p>"
        );
        
        sendSystemEmail($email, $subject, $emailBody);

        $pdo->commit();
        return [
            'success' => true,
            'user_id' => $new_user_id,
            'username' => $username,
            'plain_password' => $plain_password
        ];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
