<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../PHPMailer/Exception.php';
require_once __DIR__ . '/../PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer/SMTP.php';

/**
 * Sends a system email using PHPMailer
 * 
 * @param string $to Recipient email
 * @param string $subject Email subject
 * @param string $body HTML body of the email
 * @return bool True on success, false on failure
 */
function sendSystemEmail($to, $subject, $body) {
    $mail = new PHPMailer(true);

    try {
        // --- SMTP CONFIGURATION ---
        // NOTE: For infinityfree, standard SMTP might be restricted. 
        // Using local mail() or a secondary SMTP service is standard.
        // For development/demo, we will use a generic structure.
        
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com'; // Adjust to actual SMTP provider
        $mail->SMTPAuth   = true;
        $mail->Username   = 'horizon.systems.dev@gmail.com'; // Replace with REAL credentials if available
        $mail->Password   = 'your_app_password';             // Replace with REAL credentials if available
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Recipients
        $mail->setFrom('no-reply@horizonsystems.com', 'Horizon Systems');
        $mail->addAddress($to);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = strip_tags($body);

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}

/**
 * Generates a consistent HTML template for Horizon emails
 */
function getEmailTemplate($title, $content) {
    return "
    <div style='font-family: sans-serif; max-width: 600px; margin: auto; border: 1px solid #eee; padding: 40px; border-radius: 10px;'>
        <div style='text-align: center; margin-bottom: 30px;'>
            <h1 style='color: #8c2bee; margin: 0;'>HORIZON</h1>
            <p style='color: #777; font-size: 12px; text-transform: uppercase; letter-spacing: 2px;'>Digital Infrastructure</p>
        </div>
        <h2 style='color: #333;'>$title</h2>
        <div style='color: #555; line-height: 1.6;'>
            $content
        </div>
        <div style='margin-top: 40px; padding-top: 20px; border-top: 1px solid #eee; font-size: 12px; color: #aaa; text-align: center;'>
            &copy; " . date('Y') . " Horizon Systems. All rights reserved.
        </div>
    </div>";
}
