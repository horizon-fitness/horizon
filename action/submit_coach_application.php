<?php
session_start();
require_once '../db.php';
require_once '../includes/mailer.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request method.");
    }

    $gym_slug = $_GET['gym'] ?? '';
    if (empty($gym_slug)) {
        throw new Exception("Facility context is missing.");
    }

    // Fetch Gym Info
    $stmtSlug = $pdo->prepare("SELECT user_id FROM system_settings WHERE setting_key = 'page_slug' AND setting_value = ?");
    $stmtSlug->execute([$gym_slug]);
    $gym_owner_id = $stmtSlug->fetchColumn();

    if (!$gym_owner_id) {
        throw new Exception("Gym settings not found.");
    }

    $stmtGym = $pdo->prepare("SELECT gym_id, gym_name FROM gyms WHERE owner_user_id = ? LIMIT 1");
    $stmtGym->execute([$gym_owner_id]);
    $gym = $stmtGym->fetch();

    if (!$gym) {
        throw new Exception("Gym details not found.");
    }

    // Capture Inputs
    $data = [
        'first_name' => trim($_POST['first_name'] ?? ''),
        'middle_name' => trim($_POST['middle_name'] ?? ''),
        'last_name' => trim($_POST['last_name'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'username' => trim($_POST['username'] ?? ''),
        'contact_number' => str_replace('-', '', $_POST['contact_number'] ?? ''),
        'birth_date' => $_POST['birth_date'] ?? '',
        'sex' => $_POST['sex'] ?? '',
        'password' => $_POST['password'] ?? '',
        'coach_type' => $_POST['coach_type'] ?? '',
        'specialization' => trim($_POST['specialization'] ?? ''),
        'license_number' => trim($_POST['license_number'] ?? ''),
        'bank_name' => $_POST['bank_name'] ?? '',
        'account_name' => trim($_POST['account_name'] ?? ''),
        'account_number' => str_replace('-', '', $_POST['account_number'] ?? ''),
    ];

    // Validation
    foreach (['first_name', 'last_name', 'email', 'username', 'password', 'coach_type', 'specialization', 'bank_name', 'account_name', 'account_number'] as $field) {
        if (empty($data[$field])) {
            throw new Exception("Required field '$field' is missing.");
        }
    }

    // Age Validation
    $dob = new DateTime($data['birth_date']);
    $age = (new DateTime())->diff($dob)->y;
    if ($age < 18) {
        throw new Exception("You must be at least 18 years old to apply.");
    }

    // Check Availability
    $stmtCheck = $pdo->prepare("SELECT user_id FROM users WHERE email = ? OR username = ? LIMIT 1");
    $stmtCheck->execute([$data['email'], $data['username']]);
    if ($stmtCheck->fetch()) {
        throw new Exception("Email or Username is already registered.");
    }

    // Handle File Upload
    $cert_base64 = '';
    if (isset($_FILES['certification_file']) && $_FILES['certification_file']['error'] === 0) {
        $tmp = $_FILES['certification_file']['tmp_name'];
        $type = $_FILES['certification_file']['type'];
        $cert_base64 = 'data:' . $type . ';base64,' . base64_encode(file_get_contents($tmp));
    } else {
        throw new Exception("Certification file is required.");
    }

    // Prepare for Session Staging
    $payout_remarks = "PAYOUT PREF:\nBank: {$data['bank_name']} | Acct Name: {$data['account_name']} | Acct No: {$data['account_number']}";
    
    $_SESSION['staged_coach_app'] = [
        'user' => [
            'username' => $data['username'],
            'email' => $data['email'],
            'password_hash' => password_hash($data['password'], PASSWORD_BCRYPT),
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'],
            'last_name' => $data['last_name'],
            'contact_number' => $data['contact_number'],
            'birth_date' => $data['birth_date'],
            'sex' => $data['sex']
        ],
        'application' => [
            'gym_id' => $gym['gym_id'],
            'coach_type' => $data['coach_type'],
            'specialization' => $data['specialization'],
            'license_number' => $data['license_number'],
            'certification_file' => $cert_base64,
            'remarks' => $payout_remarks
        ]
    ];

    // OTP Generation
    $otp = sprintf("%06d", mt_rand(1, 999999));
    $_SESSION['coach_otp'] = [
        'code' => $otp,
        'expires_at' => time() + (15 * 60)
    ];

    // Send Email
    $subject = "Verify Your Coach Application - {$gym['gym_name']}";
    $content = "
        <p>Hello {$data['first_name']},</p>
        <p>Thank you for applying as a coach at <strong>{$gym['gym_name']}</strong>. To complete your application, please use the verification code below:</p>
        <div style='background: #f1f5f9; padding: 24px; border-radius: 12px; margin: 30px 0; text-align: center;'>
            <span style='font-size: 32px; font-weight: 800; letter-spacing: 8px; color: #8c2bee;'>$otp</span>
        </div>
        <p>This code will expire in 15 minutes.</p>";
    
    $body = getFormalEmailTemplate("Verify Your Identity", $content, $gym['gym_name']);
    
    if (!sendSystemEmail($data['email'], $subject, $body)) {
        // Log error but proceed if needed, or throw exception
        // throw new Exception("Failed to send verification email.");
    }

    header("Location: ../coach/verify_coach.php?gym=$gym_slug");
    exit;

} catch (Exception $e) {
    $_SESSION['coach_app_error'] = $e->getMessage();
    $gym_slug = $_GET['gym'] ?? '';
    header("Location: ../coach/coach_application.php" . ($gym_slug ? "?gym=$gym_slug" : ""));
    exit;
}
