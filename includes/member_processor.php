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
    $address_line = trim($data['address_line'] ?? $data['address'] ?? '');
    $barangay = trim($data['barangay'] ?? '');
    $city = trim($data['city'] ?? '');
    $province = trim($data['province'] ?? '');
    $region = trim($data['region'] ?? '');
    $birth_date = $data['birth_date'] ?? '2000-01-01';
    $sex = $data['sex'] ?? 'Not Specified';
    $occupation = trim($data['occupation'] ?? '');

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
        $stmtUser = $pdo->prepare("INSERT INTO users (username, email, password_hash, first_name, middle_name, last_name, contact_number, birth_date, sex, is_verified, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)");
        $stmtUser->execute([$username, $email, $password_hash, $first_name, $middle_name, $last_name, $phone, $birth_date, $sex, $now, $now]);
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

        $stmtUR = $pdo->prepare("INSERT INTO user_roles (user_id, role_id, gym_id, role_status, assigned_at) VALUES (?, ?, ?, 'Active', ?)");
        $stmtUR->execute([$new_user_id, $role_id, $gym_id, $now]);

        // 3. Create Member Record
        $prefix = ($source === 'Walk-in') ? 'WALK-' : 'MBR-';
        $member_code = $prefix . str_pad($new_user_id, 4, '0', STR_PAD_LEFT);

        // 3NF: Insert address into dedicated table
        $stmtAddr = $pdo->prepare("INSERT INTO addresses (address_line, barangay, city, province, region, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmtAddr->execute([$address_line, $barangay, $city, $province, $region, $now, $now]);
        $address_id = $pdo->lastInsertId();
        
        $stmtMember = $pdo->prepare("INSERT INTO members (user_id, gym_id, member_code, address_id, occupation, emergency_contact_name, emergency_contact_number, registration_source, registered_by_user_id, member_status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, ?)");
        $stmtMember->execute([$new_user_id, $gym_id, $member_code, $address_id, $occupation, $emergency_name, $emergency_phone, $source, $registered_by, $now, $now]);

        // 5. Send Email
        $stmtGym = $pdo->prepare("SELECT gym_name, profile_picture FROM gyms WHERE gym_id = ?");
        $stmtGym->execute([$gym_id]);
        $gym = $stmtGym->fetch();
        $gymName = $gym['gym_name'] ?? 'Horizon Gym';
        $gymLogo = $gym['profile_picture'] ?? '';

        $safeFirstName = htmlspecialchars($first_name, ENT_QUOTES, 'UTF-8');
        $safeGymName = htmlspecialchars($gymName, ENT_QUOTES, 'UTF-8');
        $safeUsername = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
        $safePassword = htmlspecialchars($plain_password, ENT_QUOTES, 'UTF-8');
        $safeMemberCode = htmlspecialchars($member_code, ENT_QUOTES, 'UTF-8');

        $subject = ($source === 'Walk-in') ? "Your New Membership Account - $gymName" : "Welcome to $gymName - Your Account Details";
        $welcomeMsg = ($source === 'Walk-in') 
            ? "Your membership has been registered as a walk-in at <strong>$safeGymName</strong>."
            : "You have successfully registered at <strong>$safeGymName</strong>.";

        $emailBody = getFormalEmailTemplate(
            "Membership Account Activated",
            "<p style='margin: 0 0 14px 0;'>Hello <strong>$safeFirstName</strong>,</p>
            <p style='margin: 0 0 14px 0;'>$welcomeMsg</p>
            <p style='margin: 0 0 24px 0;'>Your account is ready for the member portal. Please keep these credentials private.</p>

            <div style='background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 14px; padding: 22px; margin: 24px 0;'>
                <p style='margin: 0 0 14px 0; font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; font-weight: 700;'>Account Details</p>
                <table width='100%' border='0' cellspacing='0' cellpadding='0'>
                    <tr>
                        <td style='padding: 10px 0; color: #64748b; font-size: 14px;'>Member Code</td>
                        <td align='right' style='padding: 10px 0; color: #111827; font-size: 14px; font-weight: 700;'>$safeMemberCode</td>
                    </tr>
                    <tr>
                        <td style='padding: 10px 0; color: #64748b; font-size: 14px; border-top: 1px solid #e5e7eb;'>Username</td>
                        <td align='right' style='padding: 10px 0; color: #111827; font-size: 14px; font-weight: 700; border-top: 1px solid #e5e7eb;'>$safeUsername</td>
                    </tr>
                    <tr>
                        <td style='padding: 10px 0 0 0; color: #64748b; font-size: 14px; border-top: 1px solid #e5e7eb;'>Temporary Password</td>
                        <td align='right' style='padding: 10px 0 0 0; color: #7f13ec; font-size: 14px; font-weight: 800; border-top: 1px solid #e5e7eb;'>$safePassword</td>
                    </tr>
                </table>
            </div>

            <p style='margin: 0;'>For your security, change your password after your first login.</p>",
            $safeGymName,
            $gymLogo
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
