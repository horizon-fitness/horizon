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
 * @param string &$errorString By-reference parameter to hold error message
 * @return bool True on success, false on failure
 */
function sendSystemEmail($to, $subject, $body, &$errorString = null) {
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com'; 
        $mail->SMTPAuth   = true;
        $mail->Username   = 'horizonfitnesscorp@gmail.com'; 
        $mail->Password   = 'haog wnjy zhwe qnmn';             
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Settings to ignore SSL errors on local setups
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );

        $mail->setFrom('no-reply@horizonsystems.com', 'Horizon Systems');
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = strip_tags($body);

        $mail->send();
        return true;
    } catch (Exception $e) {
        $errorString = $mail->ErrorInfo;
        error_log("Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}

/**
 * Original Template for Web/Dashboard compatibility
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

/**
 * New Formal Template specifically for Mobile/Registration Branding
 */
function getFormalEmailTemplate($title, $content, $gymName = "Horizon System", $logoUrl = "") {
    $currentYear = date('Y');
    $accentColor = "#8c2bee";
    $headerLogo = !empty($logoUrl) ? "<img src='$logoUrl' alt='$gymName Logo' style='max-height: 60px; margin-bottom: 20px;'>" : "<h1 style='color: $accentColor; margin: 0; font-size: 28px; letter-spacing: 2px;'>HORIZON</h1>";
    
    return "
    <!DOCTYPE html>
    <html>
    <head><meta charset='UTF-8'></head>
    <body style='margin: 0; padding: 0; background-color: #f6f9fc; font-family: sans-serif;'>
        <table width='100%' border='0' cellspacing='0' cellpadding='0' style='background-color: #f6f9fc; padding: 40px 0;'>
            <tr>
                <td align='center'>
                    <table width='600' border='0' cellspacing='0' cellpadding='0' style='background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.05);'>
                        <tr>
                            <td align='center' style='padding: 40px 40px 0 40px;'>
                                $headerLogo
                                <div style='height: 2px; width: 40px; background-color: $accentColor; margin: 20px 0;'></div>
                            </td>
                        </tr>
                        <tr>
                            <td style='padding: 20px 40px 40px 40px;'>
                                <h1 style='color: #1a1a1a; font-size: 24px; margin: 0 0 20px 0; text-align: center;'>$title</h1>
                                <div style='color: #4a5568; font-size: 16px; line-height: 1.6; margin-bottom: 30px;'>
                                    $content
                                </div>
                                <div style='background-color: #f8fafc; padding: 25px; border-radius: 8px; border-left: 4px solid $accentColor;'>
                                    <p style='margin: 0; font-size: 14px; color: #718096; font-style: italic;'>
                                        Sent via <strong>$gymName</strong> Registration Service
                                    </p>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <td style='background-color: #1a202c; padding: 30px 40px; text-align: center;'>
                                <p style='color: #a0aec0; font-size: 12px; margin: 0;'>&copy; $currentYear $gymName. All rights reserved.</p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>";
}

/**
 * Professional Receipt Template for Payment Approvals
 */
function getReceiptTemplate($data) {
    $currentYear = date('Y');
    $accentColor = "#8c2bee";
    $refNo = $data['reference_number'] ?? 'N/A';
    $gymName = $data['gym_name'] ?? 'Horizon System';
    $planName = $data['plan_name'] ?? 'Membership Plan';
    $amount = number_format($data['amount'], 2);
    $date = date('M d, Y');
    $customerName = $data['customer_name'] ?? 'Valued Member';
    
    return "
    <!DOCTYPE html>
    <html>
    <head><meta charset='UTF-8'></head>
    <body style='margin: 0; padding: 0; background-color: #f4f7f6; font-family: sans-serif; color: #333;'>
        <table width='100%' border='0' cellspacing='0' cellpadding='0' style='padding: 40px 0;'>
            <tr>
                <td align='center'>
                    <table width='600' border='0' cellspacing='0' cellpadding='0' style='background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.05);'>
                        <!-- Header -->
                        <tr>
                            <td style='background: linear-gradient(135deg, #8c2bee 0%, #6d22ba 100%); padding: 40px; text-align: center;'>
                                <h1 style='color: #ffffff; margin: 0; font-size: 24px; letter-spacing: 2px; text-transform: uppercase;'>Official Receipt</h1>
                                <p style='color: rgba(255,255,255,0.8); font-size: 11px; margin-top: 5px; text-transform: uppercase; letter-spacing: 1px;'>$gymName</p>
                            </td>
                        </tr>
                        <!-- Content -->
                        <tr>
                            <td style='padding: 40px;'>
                                <p style='margin: 0; font-size: 16px; color: #555;'>Hello <strong>$customerName</strong>,</p>
                                <p style='margin: 10px 0 30px 0; font-size: 14px; color: #777; line-height: 1.5;'>Thank you for your payment. Your subscription is now active. Please find your receipt details below for your records.</p>
                                
                                <table width='100%' border='0' cellspacing='0' cellpadding='0' style='border: 1px solid #eee; border-radius: 12px; overflow: hidden;'>
                                    <tr style='background-color: #fcfcfc;'>
                                        <td style='padding: 15px 20px; font-size: 12px; color: #999; text-transform: uppercase; border-bottom: 1px solid #eee;'>Transaction Details</td>
                                        <td style='padding: 15px 20px; text-align: right; font-size: 12px; color: #999; text-transform: uppercase; border-bottom: 1px solid #eee;'>#$refNo</td>
                                    </tr>
                                    <tr>
                                        <td style='padding: 20px; font-size: 14px; border-bottom: 1px solid #eee;'><strong>Item/Plan</strong></td>
                                        <td style='padding: 20px; text-align: right; font-size: 14px; border-bottom: 1px solid #eee;'>$planName</td>
                                    </tr>
                                    <tr>
                                        <td style='padding: 20px; font-size: 14px; border-bottom: 1px solid #eee;'><strong>Date Paid</strong></td>
                                        <td style='padding: 20px; text-align: right; font-size: 14px; border-bottom: 1px solid #eee;'>$date</td>
                                    </tr>
                                    <tr style='background-color: #fcfcfc;'>
                                        <td style='padding: 25px 20px; font-size: 18px; color: $accentColor;'><strong>Total Amount</strong></td>
                                        <td style='padding: 25px 20px; text-align: right; font-size: 22px; color: #1a1a1a;'><strong>₱$amount</strong></td>
                                    </tr>
                                </table>
                                
                                <div style='margin-top: 30px; padding: 20px; background-color: #f0f7f4; border-radius: 10px; border-left: 4px solid #22c55e;'>
                                    <p style='margin: 0; font-size: 13px; color: #166534;'>
                                        <strong>Status:</strong> Verified & Approved
                                    </p>
                                </div>
                                
                                <div style='text-align: center; margin-top: 40px;'>
                                    <p style='font-size: 12px; color: #aaa;'>If you have any questions regarding this receipt, please contact our support team.</p>
                                </div>
                            </td>
                        </tr>
                        <!-- Footer -->
                        <tr>
                            <td style='background-color: #fafafa; padding: 30px; text-align: center; border-top: 1px solid #eee;'>
                                <p style='color: #999; font-size: 11px; margin: 0;'>&copy; $currentYear $gymName. All rights reserved.</p>
                                <p style='color: #ccc; font-size: 10px; margin-top: 5px;'>This is an automated receipt generated by Horizon Systems.</p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>";
}

