<?php
session_start();
require_once '../../db.php';
require_once '../../includes/audit_logger.php';

header('Content-Type: application/json');

// Security Check: Only Superadmin
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'superadmin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subscription_id = (int)$_POST['subscription_id'];
    $payment_id = (int)$_POST['payment_id'];
    $action = $_POST['action']; // 'Approve' or 'Reject'
    $user_id = $_SESSION['user_id'];
    $now = date('Y-m-d H:i:s');

    try {
        $pdo->beginTransaction();

        // Fetch Subscriber Data for Email
        $stmtData = $pdo->prepare("
            SELECT cs.*, g.gym_name, u.email as owner_email, wp.plan_name 
            FROM client_subscriptions cs
            JOIN gyms g ON cs.gym_id = g.gym_id
            JOIN users u ON g.owner_user_id = u.user_id
            JOIN website_plans wp ON cs.website_plan_id = wp.website_plan_id
            WHERE cs.subscription_id = ?
        ");
        $stmtData->execute([$subscription_id]);
        $subData = $stmtData->fetch();

        if (!$subData) throw new Exception("Subscription records not found.");

        require_once '../../includes/mailer.php';
        $email_sent = false;

        if ($action === 'Approve') {
            // 1. Update Payment Status to 'Paid'
            $stmtPay = $pdo->prepare("UPDATE payments SET payment_status = 'Paid', verified_by = ?, verified_at = ? WHERE payment_id = ?");
            $stmtPay->execute([$user_id, $now, $payment_id]);

            // 2. Update Subscription Status to 'Active' and Payment Status to 'Paid'
            $stmtSub = $pdo->prepare("UPDATE client_subscriptions SET subscription_status = 'Active', payment_status = 'Paid', updated_at = ? WHERE subscription_id = ?");
            $stmtSub->execute([$now, $subscription_id]);

            // Prepare Approval Email
            $subject = "Payment Confirmed - Your " . $subData['plan_name'] . " is now Active!";
            $content = "
                <p>Hello,</p>
                <p>We are pleased to inform you that your payment for <strong>" . htmlspecialchars($subData['gym_name']) . "</strong> has been approved.</p>
                <p>Your <strong>" . htmlspecialchars($subData['plan_name']) . "</strong> subscription is now active.</p>
                <p>Thank you for choosing Horizon!</p>
            ";
            $email_sent = sendSystemEmail($subData['owner_email'], $subject, getEmailTemplate("Payment Approved", $content));

            // 3. Log Audit
            log_audit_event($pdo, $user_id, null, 'Verify', 'payments', $payment_id, ['old_status' => 'Pending'], ['new_status' => 'Paid', 'action' => 'Approved', 'email' => $email_sent]);
        } else {
            // 1. Update Payment Status to 'Rejected'
            $stmtPay = $pdo->prepare("UPDATE payments SET payment_status = 'Rejected', verified_by = ?, verified_at = ? WHERE payment_id = ?");
            $stmtPay->execute([$user_id, $now, $payment_id]);

            // 2. Update Subscription Payment Status back to 'Rejected'
            $stmtSub = $pdo->prepare("UPDATE client_subscriptions SET payment_status = 'Rejected', subscription_status = 'Inactive', updated_at = ? WHERE subscription_id = ?");
            $stmtSub->execute([$now, $subscription_id]);

            // Prepare Rejection Email
            $subject = "Subscription Payment Rejected - Action Required for " . $subData['gym_name'];
            $content = "
                <p>Hello,</p>
                <p>Your recent payment for the <strong>" . htmlspecialchars($subData['plan_name']) . "</strong> plan for <strong>" . htmlspecialchars($subData['gym_name']) . "</strong> was not verified and has been <strong>Rejected</strong>.</p>
                <p>Please log in to your portal to re-submit your payment or contact support.</p>
            ";
            $email_sent = sendSystemEmail($subData['owner_email'], $subject, getEmailTemplate("Payment Verification Failed", $content));

            // 3. Log Audit
            log_audit_event($pdo, $user_id, null, 'Verify', 'payments', $payment_id, ['old_status' => 'Pending'], ['new_status' => 'Rejected', 'action' => 'Rejected', 'email' => $email_sent]);
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'email_sent' => $email_sent]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
}
