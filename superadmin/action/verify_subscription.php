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

        if ($action === 'Approve') {
            // 1. Update Payment Status to 'Paid'
            $stmtPay = $pdo->prepare("UPDATE payments SET payment_status = 'Paid', verified_by = ?, verified_at = ? WHERE payment_id = ?");
            $stmtPay->execute([$user_id, $now, $payment_id]);

            // 2. Update Subscription Status to 'Active' and Payment Status to 'Paid'
            $stmtSub = $pdo->prepare("UPDATE client_subscriptions SET subscription_status = 'Active', payment_status = 'Paid', updated_at = ? WHERE client_subscription_id = ?");
            $stmtSub->execute([$now, $subscription_id]);

            // 3. Log Audit
            log_audit_event($pdo, $user_id, null, 'Verify', 'payments', $payment_id, ['old_status' => 'Pending'], ['new_status' => 'Paid', 'action' => 'Approved']);
        } else {
            // 1. Update Payment Status to 'Rejected'
            $stmtPay = $pdo->prepare("UPDATE payments SET payment_status = 'Rejected', verified_by = ?, verified_at = ? WHERE payment_id = ?");
            $stmtPay->execute([$user_id, $now, $payment_id]);

            // 2. Update Subscription Payment Status back to 'Unpaid' (so they can try again or select another plan)
            $stmtSub = $pdo->prepare("UPDATE client_subscriptions SET payment_status = 'Rejected', updated_at = ? WHERE client_subscription_id = ?");
            $stmtSub->execute([$now, $subscription_id]);

            // 3. Log Audit
            log_audit_event($pdo, $user_id, null, 'Verify', 'payments', $payment_id, ['old_status' => 'Pending'], ['new_status' => 'Rejected', 'action' => 'Rejected']);
        }

        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
}
