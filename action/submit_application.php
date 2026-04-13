<?php
session_start();
// Include the database connection file.
require_once '../db.php'; 

try {
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

        // Sanitize formatted inputs (Strip dashes)
        $bir_number = str_replace('-', '', $bir_number);
        $account_number = str_replace('-', '', $account_number);
        $owner_contact = str_replace('-', '', $contact_number);
        $gym_contact = str_replace('-', '', $gym_contact);

        // Data Integrity Checks
        if (strlen($bir_number) !== 12) {
            throw new Exception("Security Error: BIR/TIN number must be exactly 12 digits before formatting.");
        }

        if (empty($bank_name) || empty($account_name) || empty($account_number)) {
            throw new Exception("Security Error: Complete payout information (Bank/E-wallet, Account Name, Account Number) is required.");
        }

        if (strlen($account_number) > 20) {
            throw new Exception("Security Error: Bank Account Number cannot exceed 20 digits.");
        }

        // Capture facility payload (If present)
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
        $facility_remarks = "PAYOUT PREF:\nBank: $bank_name | Acct Name: $account_name | Acct No: $account_number\n\nFACILITY:\nOpening: $opening_time | Closing: $closing_time | Max Cap: $max_capacity | Lockers: $has_lockers | Shower: $has_shower | Parking: $has_parking | Wifi: $has_wifi \nAbout: $about_text \nRules: $rules_text";

        // 1. Final Validations (Verify non-existence of unique identifiers)
        $stmtCheckUsername = $pdo->prepare("SELECT user_id FROM users WHERE username = ? LIMIT 1");
        $stmtCheckUsername->execute([$username]);
        if ($stmtCheckUsername->fetch()) {
            throw new Exception("The Username '$username' is already registered. Please choose another.");
        }

        $stmtCheckGymEmail = $pdo->prepare("SELECT application_id FROM gym_owner_applications WHERE email = ? LIMIT 1");
        $stmtCheckGymEmail->execute([$gym_email]);
        if ($stmtCheckGymEmail->fetch()) {
            throw new Exception("The Gym Email '$gym_email' is already registered to another application. Please use another.");
        }

        // 2. Prepare Documents (Convert to Base64)
        $filesToUpload = [
            'owner_valid_id_file' => 'Valid ID',
            'bir_document' => 'BIR Document',
            'business_permit' => 'Business Permit',
            'profile_picture' => 'Gym Logo'
        ];

        $documents = [];
        foreach ($filesToUpload as $inputName => $docType) {
            $base64Data = convertFileToBase64($inputName);
            if ($base64Data) {
                $documents[] = [
                    'type' => $docType,
                    'path' => $base64Data
                ];
            }
        }

        // 3. Stage Data in Session (STRICTLY NO DATABASE INSERT YET)
        $_SESSION['staged_registration'] = [
            'user' => [
                'username' => $username,
                'email' => $email,
                'password_hash' => $password_hash,
                'first_name' => $first_name,
                'middle_name' => $middle_name,
                'last_name' => $last_name,
                'contact_number' => $owner_contact,
                'birth_date' => $owner_dob,
                'sex' => $owner_sex
            ],
            'address' => [
                'address_line' => $address_line,
                'barangay' => $barangay,
                'city' => $city,
                'province' => $province,
                'region' => $region
            ],
            'application' => [
                'gym_name' => $gym_name,
                'business_name' => $business_name,
                'business_type' => $business_type,
                'owner_valid_id_type' => $owner_valid_id_type,
                'bir_number' => $bir_number,
                'business_permit_no' => $business_permit_no,
                'contact_number' => $gym_contact,
                'email' => $gym_email,
                'remarks' => $facility_remarks
            ],
            'documents' => $documents,
            'timestamp' => time()
        ];

        // 4. Generate Session-Based OTP
        $verification_code = sprintf("%06d", mt_rand(1, 999999));
        $_SESSION['staged_otp'] = [
            'code' => $verification_code,
            'expires_at' => time() + (15 * 60) // 15 minutes
        ];

        // 5. Send Verification Email
        require_once '../includes/mailer.php';
        $subject = "Verify Your Gym Application - Horizon Systems";
        $email_content = "
            <div style='background-color:#f8fafc; padding: 40px; font-family: sans-serif; color: #1e293b;'>
                <h2 style='color: #0f172a;'>Email Verification</h2>
                <p>To complete your gym application, please use the following 6-digit verification code:</p>
                <div style='background: #f1f5f9; padding: 24px; border-radius: 12px; margin: 30px 0; text-align: center;'>
                    <span style='font-size: 32px; font-weight: 800; letter-spacing: 8px; color: #7f13ec;'>$verification_code</span>
                </div>
                <p>This code will expire in 15 minutes.</p>
                <p>If you didn't request this, please ignore this email.</p>
            </div>";
        
        $body = getEmailTemplate("Verify your Identity", $email_content);
        
        if (!sendSystemEmail($gym_email, $subject, $body)) {
            error_log("Staged Registration: Failed to send OTP to $gym_email");
        }

        // 6. Navigation
        $_SESSION['verify_email'] = $gym_email;
        $gym_param = isset($_GET['gym']) ? "?gym=" . urlencode($_GET['gym']) : "";
        header("Location: ../tenant/verify_email.php" . $gym_param);
        exit;

    } else {
        header("Location: ../tenant/tenant_application.php");
        exit;
    }
} catch (Exception $e) {
    $_SESSION['application_error'] = $e->getMessage();
    $_SESSION['application_data'] = $_POST;
    $gym_param = isset($_GET['gym']) ? "?gym=" . urlencode($_GET['gym']) : "";
    header("Location: ../tenant/tenant_application.php" . $gym_param);
    exit;
}
?>