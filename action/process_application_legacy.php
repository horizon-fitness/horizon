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
    
    // Directory setup for file uploads
    $uploadDir = '../uploads/applications/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    function uploadFile($fileInputName, $uploadDir) {
        if (isset($_FILES[$fileInputName]) && $_FILES[$fileInputName]['error'] == 0) {
            $fileName = time() . '_' . basename($_FILES[$fileInputName]['name']);
            $targetPath = $uploadDir . $fileName;
            if (move_uploaded_file($_FILES[$fileInputName]['tmp_name'], $targetPath)) {
                return $targetPath;
            }
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
    $password = $_POST['password'] ?? '';
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

        // 1. Insert into `users` table
        $stmtUser = $pdo->prepare("INSERT INTO users (username, email, password_hash, first_name, middle_name, last_name, contact_number, is_verified, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, 1, ?, ?)");
        $stmtUser->execute([$username, $email, $password_hash, $first_name, $middle_name, $last_name, $contact_number, $current_date, $current_date]);
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

        // 4. Handle File Uploads and Insert into `application_documents`
        $filesToUpload = [
            'owner_valid_id_file' => 'Valid ID',
            'bir_document' => 'BIR Document',
            'business_permit' => 'Business Permit',
            'profile_picture' => 'Gym Logo'
        ];

        $stmtDoc = $pdo->prepare("INSERT INTO application_documents (application_id, document_type, file_path, uploaded_at) VALUES (?, ?, ?, ?)");

        foreach ($filesToUpload as $inputName => $docType) {
            $filePath = uploadFile($inputName, $uploadDir);
            if ($filePath) {
                $stmtDoc->execute([$application_id, $docType, $filePath, $current_date]);
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
            $mail->addAddress($email, $first_name . ' ' . $last_name);

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
        $_SESSION['verify_email'] = $email;
        header("Location: verify_email.php");
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        die("Application Failed. Error: " . $e->getMessage());
    }

} else {
    header("Location: tenant_application.php");
    exit;
}
?>