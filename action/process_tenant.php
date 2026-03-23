<?php
session_start();
// Include the database connection (action folder is one level deep, so ../ is correct)
require_once '../db.php';

// Include PHPMailer classes
require '../PHPMailer/Exception.php';
require '../PHPMailer/PHPMailer.php';
require '../PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Security Check: Only logged-in Superadmins can perform these actions
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'superadmin') {
    $_SESSION['error_msg'] = "Unauthorized access.";
    header("Location: ../login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['gym_id'])) {
    
    $gym_id = (int)$_POST['gym_id'];
    $action = strtolower(trim($_POST['action']));
    $now = date('Y-m-d H:i:s');

    try {
        $pdo->beginTransaction();

        // Check if gym exists
        $stmtCheck = $pdo->prepare("SELECT gym_name, email, owner_user_id FROM gyms WHERE gym_id = ? LIMIT 1");
        $stmtCheck->execute([$gym_id]);
        $gym = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$gym) {
            throw new Exception("Gym record not found.");
        }

        $emailSent = false;
        $subject = "";
        $body = "";

        if ($action === 'activate') {
            // Activate the Gym and the Tenant Role
            $stmtUpdate = $pdo->prepare("UPDATE gyms SET status = 'Active', updated_at = ? WHERE gym_id = ?");
            $stmtUpdate->execute([$now, $gym_id]);

            $stmtRole = $pdo->prepare("UPDATE user_roles SET role_status = 'Active' WHERE gym_id = ? AND user_id = ?");
            $stmtRole->execute([$gym_id, $gym['owner_user_id']]);

            $_SESSION['success_msg'] = "Account for {$gym['gym_name']} has been reactivated successfully.";
            
            $subject = "Official Account Reactivation - Horizon Systems";
            $message_content = "
                <p>We are pleased to inform you that your gym account for <strong>{$gym['gym_name']}</strong> has been formally <strong>Reactivated</strong> by the System Administration.</p>
                <p>Full administrative and operational access has been restored to your portal. You and your designated staff may now resume operations using your existing credentials.</p>
                <div style='background: #f0fff4; padding: 20px; border-radius: 12px; margin-top: 20px;'>
                    <p style='margin: 0; color: #2f855a; font-weight: 700;'>Action: Reactivation Successful</p>
                </div>
            ";

        } elseif ($action === 'suspend') {
            // Suspend the Gym (e.g., unpaid subscription)
            $stmtUpdate = $pdo->prepare("UPDATE gyms SET status = 'Suspended', updated_at = ? WHERE gym_id = ?");
            $stmtUpdate->execute([$now, $gym_id]);

            $stmtRole = $pdo->prepare("UPDATE user_roles SET role_status = 'Suspended' WHERE gym_id = ? AND user_id = ?");
            $stmtRole->execute([$gym_id, $gym['owner_user_id']]);

            $_SESSION['success_msg'] = "Account for {$gym['gym_name']} has been suspended due to policy/billing issues.";

            $subject = "Notice of Account Suspension - Horizon Systems";
            $message_content = "
                <p>This is an official notification that your gym account for <strong>{$gym['gym_name']}</strong> has been <strong>Suspended</strong> effectively immediately.</p>
                <p>This measure has been taken due to pending administrative reviews, billing discrepancies, or policy compliance audits. Access to the Horizon Systems portal for all users associated with this tenant is currently restricted.</p>
                <div style='background: #fffaf0; padding: 20px; border-radius: 12px; margin-top: 20px; border: 1px solid #feebc8;'>
                    <p style='margin: 0; color: #c05621; font-weight: 700;'>Status: Temporarily Restricted</p>
                </div>
                <p style='margin-top: 20px;'>To resolve this status, please contact our <strong>Account Management Office</strong> at your earliest convenience.</p>
            ";

        } elseif ($action === 'delete' || $action === 'deactivate') {
            // Soft Delete the Gym
            $stmtUpdate = $pdo->prepare("UPDATE gyms SET status = 'Deleted', updated_at = ? WHERE gym_id = ?");
            $stmtUpdate->execute([$now, $gym_id]);

            // Revoke the log-in access of the tenant
            $stmtRole = $pdo->prepare("UPDATE user_roles SET role_status = 'Revoked' WHERE gym_id = ? AND user_id = ?");
            $stmtRole->execute([$gym_id, $gym['owner_user_id']]);

            $_SESSION['success_msg'] = "Account for {$gym['gym_name']} has been successfully " . ($action === 'delete' ? 'deleted' : 'deactivated') . ".";

            $subject = "Account Deactivation Notice - Horizon Systems";
            $message_content = "
                <p>This is to formally confirm that your gym account for <strong>{$gym['gym_name']}</strong> has been <strong>Deactivated</strong>.</p>
                <p>Following this administrative action, all data access, system privileges, and staff permissions for your gym have been revoked. This concludes the active service period for this tenant on our platform.</p>
                <div style='background: #fff5f5; padding: 20px; border-radius: 12px; margin-top: 20px; border: 1px solid #fed7d7;'>
                    <p style='margin: 0; color: #c53030; font-weight: 700;'>Action: Account Deactivated</p>
                </div>
                <p style='margin-top: 20px;'>If you believe this action was taken in error or if you wish to discuss data retention, please reach out to our <strong>Compliance Department</strong>.</p>
            ";

        } else {
            throw new Exception("Invalid action requested.");
        }

        $pdo->commit();

        // 7. Send Notification Email via PHPMailer
        if (!empty($gym['email']) && !empty($subject)) {
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
                $mail->addAddress($gym['email'], $gym['gym_name']);

                $mail->isHTML(true);
                $mail->Subject = $subject;
                
                // Construct Formal HTML Template
                $mail->Body = "
                <div style='background-color:#f8fafc; padding: 50px 0; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif;'>
                    <div style='max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 24px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.05); border: 1px solid #e2e8f0;'>
                        <div style='background: #0f172a; padding: 40px; text-align: center;'>
                            <h1 style='color: #ffffff; margin: 0; font-size: 22px; font-weight: 900; letter-spacing: 2px; text-transform: uppercase;'>Horizon Systems</h1>
                            <p style='color: #64748b; margin: 8px 0 0; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 3px;'>Corporate Administration</p>
                        </div>
                        <div style='padding: 50px; color: #1e293b;'>
                            <h2 style='font-size: 20px; font-weight: 800; margin-bottom: 25px; color: #0f172a;'>{$subject}</h2>
                            <div style='font-size: 14px; line-height: 1.7; color: #475569;'>
                                {$message_content}
                            </div>
                            <div style='margin-top: 50px; padding-top: 30px; border-top: 1px solid #f1f5f9; font-size: 11px; color: #94a3b8;'>
                                <p style='margin: 0; line-height: 1.5;'>This is an automated system notification from <strong>Horizon Systems Administration</strong>. To ensure account security, please do not share your portal credentials with unauthorized personnel.</p>
                            </div>
                        </div>
                    </div>
                    <div style='text-align: center; margin-top: 30px; font-size: 11px; color: #94a3b8;'>
                        <p>&copy; 2026 Horizon Systems Corp. Corporate Blvd, HQ. All rights reserved.</p>
                    </div>
                </div>";

                $mail->send();
                $_SESSION['success_msg'] .= " Notification email sent.";
            } catch (Exception $e) {
                error_log("Notification email could not be sent. Mailer Error: {$mail->ErrorInfo}");
            }
        }

    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error_msg'] = "Action failed: " . $e->getMessage();
    }

} else {
    $_SESSION['error_msg'] = "Invalid request method.";
}

// Redirect back to the Tenant Management UI in the superadmin folder
header("Location: ../superadmin/tenant_management.php");
exit;
?>