<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
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
        'license_number' => trim($_POST['license_number'] ?? ''),
        'session_rate' => $_POST['session_rate'] ?? 0.00,
    ];

    // Validation
    foreach (['first_name', 'last_name', 'email', 'username', 'password', 'coach_type', 'sex'] as $field) {
        if (empty($data[$field])) {
            throw new Exception("Required field '$field' is missing.");
        }
    }

    if (!isset($_POST['session_rate']) || $_POST['session_rate'] === '') {
        throw new Exception("Required field 'session_rate' is missing.");
    }

    // Pre-initialize variables
    $cert_base64 = '';

    // Ensure certification file was uploaded
    if (!isset($_FILES['certification_file']) || $_FILES['certification_file']['error'] !== 0) {
        throw new Exception("Please upload your professional certification.");
    }

    // Age Validation
    $dob = new DateTime($data['birth_date']);
    $age = (new DateTime())->diff($dob)->y;
    if ($age < 18) {
        throw new Exception("You must be at least 18 years old to apply.");
    }

    // Check Availability (Smart Check: Allow Re-application if Rejected)
    $stmtCheck = $pdo->prepare("
        SELECT u.user_id, ca.application_status 
        FROM users u 
        LEFT JOIN coach_applications ca ON u.user_id = ca.user_id AND ca.gym_id = ?
        WHERE u.email = ? OR u.username = ?
        ORDER BY ca.submitted_at DESC LIMIT 1
    ");
    $stmtCheck->execute([$gym['gym_id'], $data['email'], $data['username']]);
    $existing = $stmtCheck->fetch();

    if ($existing) {
        if ($existing['application_status'] === 'Pending') {
            throw new Exception("Application In Progress: You already have a pending application for this facility.");
        }
        if ($existing['application_status'] === 'Approved') {
            throw new Exception("Profile Match Found: You are already part of this facility's roster.");
        }
    }

    // Handle File Upload
    $cert_base64 = '';
    if (isset($_FILES['certification_file']) && $_FILES['certification_file']['error'] === 0) {
        $tmp = $_FILES['certification_file']['tmp_name'];
        $type = $_FILES['certification_file']['type'];
        $size = $_FILES['certification_file']['size'];

        // Maximum 1.5MB file size limit to prevent MySQL "Server has gone away" connection crashes
        if ($size > 1572864) {
            throw new Exception("Your certification file is too large. Please upload a file smaller than 1.5MB.");
        }

        $cert_base64 = 'data:' . $type . ';base64,' . base64_encode(file_get_contents($tmp));
    }

    // Prepare for Session Staging
    $payout_remarks = "EXPECTED RATE: ₱" . number_format($data['session_rate'], 2);
    
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
            'license_number' => $data['license_number'],
            'certification_file' => $cert_base64,
            'session_rate' => $data['session_rate'],
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
