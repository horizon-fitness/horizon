<?php
session_start();
require_once '../db.php';
require_once '../includes/audit_logger.php';
// Include PHPMailer classes
require '../PHPMailer/Exception.php';
require '../PHPMailer/PHPMailer.php';
require '../PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Security Check: Only Superadmin can access
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'superadmin') {
    $_SESSION['error_msg'] = "Unauthorized access.";
    header("Location: ../login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['application_id'])) {
    $app_id = (int)$_POST['application_id'];
    $action = $_POST['action'];
    $admin_id = $_SESSION['user_id'];
    $now = date('Y-m-d H:i:s');

    if ($action === 'approve') {
        try {
            $pdo->beginTransaction();
            
            // 1. Update application status
            $stmtUpdate = $pdo->prepare("UPDATE gym_owner_applications SET application_status = 'Approved', reviewed_by = ?, reviewed_at = ? WHERE application_id = ?");
            $stmtUpdate->execute([$admin_id, $now, $app_id]);

            // 2. Fetch the application details
            $stmtApp = $pdo->prepare("SELECT * FROM gym_owner_applications WHERE application_id = ?");
            $stmtApp->execute([$app_id]);
            $app = $stmtApp->fetch(PDO::FETCH_ASSOC);

            if (!$app) {
                throw new Exception("Application record not found.");
            }

            // 3. Fetch gym logo if it exists in application_documents
            $stmtLogo = $pdo->prepare("SELECT file_path FROM application_documents WHERE application_id = ? AND document_type = 'Gym Logo' LIMIT 1");
            $stmtLogo->execute([$app_id]);
            $logoRow = $stmtLogo->fetch(PDO::FETCH_ASSOC);
            $gymLogo = $logoRow ? $logoRow['file_path'] : null;

            // 4. Insert into gyms table
            $tenant_code = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $app['gym_name']), 0, 3)) . '-' . rand(1000, 9999);
            $stmtGym = $pdo->prepare("INSERT INTO gyms (owner_user_id, application_id, gym_name, business_name, address_id, contact_number, email, profile_picture, tenant_code, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, ?)");
            $stmtGym->execute([
                $app['user_id'], $app['application_id'], $app['gym_name'], $app['business_name'], $app['address_id'], $app['contact_number'], $app['email'], $gymLogo, $tenant_code, $now, $now
            ]);
            $gym_id = $pdo->lastInsertId();

            // 4. Ensure 'Tenant' role exists and assign it
            $roleCheck = $pdo->prepare("SELECT role_id FROM roles WHERE role_name = 'Tenant' LIMIT 1");
            $roleCheck->execute();
            $role = $roleCheck->fetch(PDO::FETCH_ASSOC);
            if (!$role) {
                $pdo->query("INSERT INTO roles (role_name) VALUES ('Tenant')");
                $roleId = $pdo->lastInsertId();
            } else {
                $roleId = $role['role_id'];
            }

            $stmtRole = $pdo->prepare("INSERT INTO user_roles (user_id, role_id, gym_id, role_status, assigned_at) VALUES (?, ?, ?, 'Active', ?)");
            $stmtRole->execute([$app['user_id'], $roleId, $gym_id, $now]);

            // 5. Generate a Tenant Page for Page Customize
            $stmtPage = $pdo->prepare("INSERT INTO tenant_pages (gym_id, page_slug, page_title, logo_path, theme_color, updated_at) VALUES (?, ?, ?, ?, '#7f13ec', ?)");
            $page_slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $app['gym_name']));
            $stmtPage->execute([$gym_id, $page_slug, $app['gym_name'], $gymLogo, $now]);
            
            // 6. Generate System Alert for Approval
            $alertMsg = "New Gym Onboarded: " . $app['gym_name'] . " (Code: " . $tenant_code . ")";
            $stmtAlert = $pdo->prepare("INSERT INTO system_alerts (type, source, message, priority, status, created_at) VALUES ('New Tenant', 'System', ?, 'Medium', 'Unread', ?)");
            $stmtAlert->execute([$alertMsg, $now]);

            // Fetch username for the approval email
            $stmtUser = $pdo->prepare("SELECT username FROM users WHERE user_id = ?");
            $stmtUser->execute([$app['user_id']]);
            $userData = $stmtUser->fetch(PDO::FETCH_ASSOC);
            $username = $userData ? $userData['username'] : 'N/A';

            // 7. Log Audit Event
            log_audit_event($pdo, $admin_id, $gym_id, 'Create', 'gym_owner_applications', $app_id, ['status' => 'Pending'], ['status' => 'Approved', 'gym_id' => $gym_id, 'tenant_code' => $tenant_code]);

            $pdo->commit();

            // 6. Send Approval Email via PHPMailer
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com'; 
                $mail->SMTPAuth   = true;
                $mail->Username   = 'horizonfitnesscorp@gmail.com';
                $mail->Password   = 'haog wnjy zhwe qnmn';
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = 587;

                $mail->setFrom('no-reply@horizonsystems.com', 'Horizon Systems');
                // The request specified to send to the gym email, not the personal one
                $mail->addAddress($app['email'], $app['gym_name']);

                $mail->isHTML(true);
                $mail->Subject = 'Official Application Approval - Horizon Systems';
                
                $mail->Body = "
                <div style='background-color:#f8fafc; padding: 50px 0; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif;'>
                    <div style='max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 24px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.05); border: 1px solid #e2e8f0;'>
                        <div style='background: #0f172a; padding: 40px; text-align: center;'>
                            <h1 style='color: #ffffff; margin: 0; font-size: 22px; font-weight: 900; letter-spacing: 2px; text-transform: uppercase;'>Horizon Systems</h1>
                            <p style='color: #64748b; margin: 8px 0 0; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 3px;'>Corporate Administration</p>
                        </div>
                        <div style='padding: 50px; color: #1e293b;'>
                            <h2 style='font-size: 20px; font-weight: 800; margin-bottom: 25px; color: #0f172a;'>Formal Application Approval</h2>
                            <div style='font-size: 14px; line-height: 1.7; color: #475569;'>
                                <p>We are pleased to formally notify you that the application for <strong>{$app['gym_name']}</strong> has been <strong>Approved</strong> by the Horizon Systems Administrative Office.</p>
                                <p>Your organization has been successfully onboarded, and your dedicated tenant portal is now active. Please find your official access credentials below:</p>
                                
                                <div style='background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 16px; padding: 25px; margin: 30px 0;'>
                                    <table style='width: 100%; font-size: 13px;'>
                                        <tr>
                                            <td style='color: #64748b; padding-bottom: 8px;'>Username:</td>
                                            <td style='color: #0f172a; font-weight: 700; padding-bottom: 8px;'>{$username}</td>
                                        </tr>
                                        <tr>
                                            <td style='color: #64748b;'>Tenant Code:</td>
                                            <td style='color: #7f13ec; font-weight: 800; font-size: 16px; letter-spacing: 1px;'>{$tenant_code}</td>
                                        </tr>
                                    </table>
                                </div>

                                <p>For security purposes, we recommend that you utilize your registered password for initial authentication and ensure that administrative privileges are managed strictly within your organization.</p>
                            </div>
                            <div style='margin-top: 50px; padding-top: 30px; border-top: 1px solid #f1f5f9; font-size: 11px; color: #94a3b8;'>
                                <p style='margin: 0; line-height: 1.5;'>This is an official communication from <strong>Horizon Systems Administration</strong>. Confidential information and credentials should be handled with absolute care.</p>
                            </div>
                        </div>
                    </div>
                    <div style='text-align: center; margin-top: 30px; font-size: 11px; color: #94a3b8;'>
                        <p>&copy; 2026 Horizon Systems Corp. Corporate Blvd, HQ. All rights reserved.</p>
                    </div>
                </div>";

                $mail->send();
            } catch (Exception $e) {
                error_log("Approval email could not be sent. Mailer Error: {$mail->ErrorInfo}");
            }

            $_SESSION['success_msg'] = "Application for {$app['gym_name']} approved! Tenant portal is ready and approval email sent.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error_msg'] = "Failed to approve: " . $e->getMessage();
        }
    } elseif ($action === 'reject') {
        try {
            $pdo->beginTransaction();

            // 1. Fetch application details (user_id and gym_name)
            $stmtApp = $pdo->prepare("SELECT user_id, gym_name, email FROM gym_owner_applications WHERE application_id = ?");
            $stmtApp->execute([$app_id]);
            $app = $stmtApp->fetch(PDO::FETCH_ASSOC);

            if (!$app) {
                throw new Exception("Application record not found.");
            }

            // 2. Update gym_owner_applications status and "free up" Gym Email
            $newGymEmail = $app['email'] . "_rej_" . $app_id;
            $stmtUpdateApp = $pdo->prepare("UPDATE gym_owner_applications SET application_status = 'Rejected', email = ?, reviewed_by = ?, reviewed_at = ? WHERE application_id = ?");
            $stmtUpdateApp->execute([$newGymEmail, $admin_id, $now, $app_id]);

            // 3. "Free up" Owner Username and Email in the users table
            $stmtUserOrig = $pdo->prepare("SELECT username, email FROM users WHERE user_id = ?");
            $stmtUserOrig->execute([$app['user_id']]);
            $userOrig = $stmtUserOrig->fetch(PDO::FETCH_ASSOC);

            if ($userOrig) {
                $newUsername = $userOrig['username'] . "_rej_" . $app_id;
                $newOwnerEmail = $userOrig['email'] . "_rej_" . $app_id;
                
                $stmtUpdateUser = $pdo->prepare("UPDATE users SET username = ?, email = ? WHERE user_id = ?");
                $stmtUpdateUser->execute([$newUsername, $newOwnerEmail, $app['user_id']]);
            }

            // 4. Generate System Alert for Rejection
            $alertMsg = "Gym Application Rejected: " . ($app['gym_name'] ?: 'Unknown');
            $stmtAlert = $pdo->prepare("INSERT INTO system_alerts (type, source, message, priority, status, created_at) VALUES ('Application Rejected', 'System', ?, 'High', 'Unread', ?)");
            $stmtAlert->execute([$alertMsg, $now]);

            // 5. Log Audit Event
            log_audit_event($pdo, $admin_id, null, 'Reject', 'gym_owner_applications', $app_id, ['status' => 'Pending'], ['status' => 'Rejected']);

            $pdo->commit();
            $_SESSION['success_msg'] = "Application rejected successfully. Credentials are now available for reuse.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['error_msg'] = "Failed to reject: " . $e->getMessage();
        }
    }
} else {
    $_SESSION['error_msg'] = "Invalid request method.";
}

// Redirect back to referring page or default to dashboard
$redirect = $_SERVER['HTTP_REFERER'] ?? '../superadmin/superadmin_dashboard.php';
header("Location: $redirect");
exit;
?>
