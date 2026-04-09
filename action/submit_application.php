<?php
session_start();
// Include the database connection file.
require_once '../db.php'; 

// Include PHPMailer classes
require '../PHPMailer/Exception.php';
require '../PHPMailer/PHPMailer.php';
require '../PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    /**
     * Converts a file upload into a Base64 string for database storage.
     * 
     * @param string $fileInputName The name attribute of the file input
     * @return string|null The Base64 string (data:image/...) or null if upload failed
     */
    function convertFileToBase64($fileInputName) {
        if (isset($_FILES[$fileInputName]) && $_FILES[$fileInputName]['error'] == 0) {
            $tmpPath = $_FILES[$fileInputName]['tmp_name'];
            $fileType = $_FILES[$fileInputName]['type'];
            $fileData = file_get_contents($tmpPath);
            return 'data:' . $fileType . ';base64,' . base64_encode($fileData);
        }
        return null;
    }

    // Capture User Info (Owner Details)
    $first_name = $_POST['first_name'] ?? '';
    $middle_name = $_POST['middle_name'] ?? ''; 
    $last_name = $_POST['last_name'] ?? '';
    $email = $_POST['owner_email'] ?? ''; 
    $contact_number = $_POST['owner_contact'] ?? ''; 
    $username = $_POST['username'] ?? '';
    $owner_sex = $_POST['owner_sex'] ?? '';
    $owner_dob = $_POST['owner_dob'] ?? '';
    
    // Age Validation (18+)
    if (!empty($owner_dob)) {
        $dob = new DateTime($owner_dob);
        $today = new DateTime();
        $age = $today->diff($dob)->y;
        if ($age < 18) {
            throw new Exception("Security Error: You must be at least 18 years old to apply.");
        }
    } else {
        throw new Exception("Security Error: Date of birth is required.");
    }

    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Strict Password Validation
    $hasUppercase = preg_match('@[A-Z]@', $password);
    $hasNumber    = preg_match('@[0-9]@', $password);
    $hasSpecial   = preg_match('@[^\w]@', $password);
    $isLongEnough = strlen($password) >= 8;

    if (!$isLongEnough || !$hasUppercase || !$hasNumber || !$hasSpecial) {
        throw new Exception("Security Error: Your password does not meet the complexity requirements (8+ characters, uppercase, number, and special character).");
    }

    if ($password !== $confirm_password) {
        throw new Exception("Security Error: Passwords do not match.");
    }

    $password_hash = password_hash($password, PASSWORD_BCRYPT);
    $current_date = date('Y-m-d H:i:s');

    // Capture Business Info
    $gym_name = $_POST['gym_name'] ?? '';
    $business_name = $_POST['business_name'] ?? '';
    $business_type = $_POST['business_type'] ?? '';
    $gym_email = $_POST['gym_email'] ?? ''; 
    $gym_contact = $_POST['gym_contact'] ?? ''; 
    
    // Capture Address Info
    $address_line = $_POST['gym_address_line'] ?? '';
    $region = $_POST['region'] ?? '';
    $province = $_POST['province'] ?? '';
    $city = $_POST['city'] ?? '';
    $barangay = $_POST['barangay'] ?? '';

    // Capture Application specifics
    $owner_valid_id_type = $_POST['owner_valid_id_type'] ?? '';
    $bir_number = $_POST['bir_number'] ?? '';
    $business_permit_no = $_POST['business_permit_no'] ?? ''; 
    
    // Capture Banking and Payout Info
    $bank_name = $_POST['bank_name'] ?? '';
    $account_name = $_POST['account_name'] ?? '';
    $account_number = $_POST['account_number'] ?? '';

    // Data Integrity Checks
    if (strlen($bir_number) < 9 || strlen($bir_number) > 12) {
        throw new Exception("Security Error: BIR/TIN number must be between 9 and 12 digits.");
    }

    if (empty($bank_name) || empty($account_name) || empty($account_number)) {
        throw new Exception("Security Error: Complete payout information (Bank, Account Name, Account Number) is required.");
    }
    $platform_fee_preference = $_POST['platform_fee_preference'] ?? '';

    // Capture facility payload 
    $opening_time = $_POST['opening_time'] ?? '';
    $closing_time = $_POST['closing_time'] ?? '';
    $max_capacity = $_POST['max_capacity'] ?? '';
    $has_lockers = isset($_POST['has_lockers']) ? 'Yes' : 'No';
    $has_shower = isset($_POST['has_shower']) ? 'Yes' : 'No';
    $has_parking = isset($_POST['has_parking']) ? 'Yes' : 'No';
    $has_wifi = isset($_POST['has_wifi']) ? 'Yes' : 'No';
    $about_text = $_POST['about_text'] ?? '';
    $rules_text = $_POST['rules_text'] ?? '';
    
    // Combine facility and banking info into the `remarks` column so no data is lost
    $facility_remarks = "PAYOUT PREF:\nBank: $bank_name | Acct Name: $account_name | Acct No: $account_number | Fee Pref: $platform_fee_preference\n\nFACILITY:\nOpening: $opening_time | Closing: $closing_time | Max Cap: $max_capacity | Lockers: $has_lockers | Shower: $has_shower | Parking: $has_parking | Wifi: $has_wifi \nAbout: $about_text \nRules: $rules_text";

    try {
        $pdo->beginTransaction();

        // Check if username already exists
        $stmtCheckUsername = $pdo->prepare("SELECT user_id FROM users WHERE username = ? LIMIT 1");
        $stmtCheckUsername->execute([$username]);
        if ($stmtCheckUsername->fetch()) {
            throw new Exception("The Username '$username' is already registered. Please choose another.");
        }

        // Check if gym email already exists
        $stmtCheckGymEmail = $pdo->prepare("SELECT application_id FROM gym_owner_applications WHERE email = ? LIMIT 1");
        $stmtCheckGymEmail->execute([$gym_email]);
        if ($stmtCheckGymEmail->fetch()) {
            throw new Exception("The Gym Email '$gym_email' is already registered to another application. Please use another.");
        }

        // 1. Insert into `users` table
        $stmtUser = $pdo->prepare("INSERT INTO users (username, email, password_hash, first_name, middle_name, last_name, contact_number, birth_date, sex, is_verified, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1, ?, ?)");
        $stmtUser->execute([$username, $email, $password_hash, $first_name, $middle_name, $last_name, $contact_number, $owner_dob, $owner_sex, $current_date, $current_date]);
        $user_id = $pdo->lastInsertId();

        // 2. Insert into `gym_addresses` table
        $stmtAddr = $pdo->prepare("INSERT INTO gym_addresses (address_line, barangay, city, province, region, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmtAddr->execute([$address_line, $barangay, $city, $province, $region, $current_date, $current_date]);
        $address_id = $pdo->lastInsertId();

        // 3. Insert into `gym_owner_applications` table
        $application_status = 'Pending';
        $stmtApp = $pdo->prepare("INSERT INTO gym_owner_applications (user_id, gym_name, business_name, business_type, address_id, owner_valid_id_type, bir_number, business_permit_no, contact_number, email, application_status, submitted_at, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmtApp->execute([$user_id, $gym_name, $business_name, $business_type, $address_id, $owner_valid_id_type, $bir_number, $business_permit_no, $gym_contact, $gym_email, $application_status, $current_date, $facility_remarks]);
        $application_id = $pdo->lastInsertId();

        // 4. Handle File Uploads (Convert to Base64) and Insert into `application_documents`
        $filesToUpload = [
            'owner_valid_id_file' => 'Valid ID',
            'bir_document' => 'BIR Document',
            'business_permit' => 'Business Permit',
            'profile_picture' => 'Gym Logo'
        ];

        $stmtDoc = $pdo->prepare("INSERT INTO application_documents (application_id, document_type, file_path, uploaded_at) VALUES (?, ?, ?, ?)");

        foreach ($filesToUpload as $inputName => $docType) {
            $base64Data = convertFileToBase64($inputName);
            if ($base64Data) {
                $stmtDoc->execute([$application_id, $docType, $base64Data, $current_date]);
            }
        }

        // 5. Generate Verification OTP and save to `user_verifications` table
        $verification_code = sprintf("%06d", mt_rand(1, 999999)); 
        $expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes')); 
        
        $stmtVerify = $pdo->prepare("INSERT INTO user_verifications (user_id, verification_type, code, status, expires_at, created_at) VALUES (?, 'email', ?, 'pending', ?, ?)");
        $stmtVerify->execute([$user_id, $verification_code, $expires_at, $current_date]);

        $pdo->commit();

        // 6. Send Email via PHPMailer
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com'; 
            $mail->SMTPAuth   = true;
            $mail->Username   = 'horizonfitnesscorp@gmail.com'; // Update this
            $mail->Password   = 'haog wnjy zhwe qnmn';    // Update this
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;

            $mail->setFrom('no-reply@horizonsystems.com', 'Horizon Systems');
            $mail->addAddress($gym_email, $gym_name);

            $mail->isHTML(true);
            $mail->Subject = 'Verify your Horizon Partner Account';
            $mail->Body    = "
                <h3>Welcome to Horizon Systems, $first_name!</h3>
                <p>Your gym application for <strong>$gym_name</strong> has been received.</p>
                <p>To verify your email address, please use the following 6-digit code:</p>
                <h2 style='color:#7f13ec; letter-spacing: 5px;'>$verification_code</h2>
                <p>This code will expire in 15 minutes.</p>
            ";

            $mail->send();
        } catch (Exception $e) {
            error_log("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
        }

        // Redirect to OTP Verification page
        $_SESSION['verify_user_id'] = $user_id;
        $_SESSION['verify_email'] = $gym_email;
        header("Location: ../tenant/verify_email.php");
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['application_error'] = $e->getMessage();
        $_SESSION['application_data'] = $_POST;
        header("Location: ../tenant/tenant_application.php");
        exit;
    }

} else {
    header("Location: ../tenant/tenant_application.php");
    exit;
}
?>