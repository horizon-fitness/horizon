<?php
session_start();
require_once '../../db.php';
require_once '../../includes/audit_logger.php';

// Security Check: Only Tenants
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'tenant') {
    header("Location: ../../login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subscription_id'], $_POST['reference_number'])) {
    $subscription_id = (int)$_POST['subscription_id'];
    $reference_number = trim($_POST['reference_number']);
    $amount = (float)($_POST['amount'] ?? 0);
    $gym_id = $_SESSION['gym_id'] ?? 0;
    $user_id = $_SESSION['user_id'];
    $now = date('Y-m-d H:i:s');

    try {
        $pdo->beginTransaction();

        // 1. Insert into payments table
        $stmtPayment = $pdo->prepare("INSERT INTO payments (gym_id, client_subscription_id, amount, payment_method, payment_type, reference_number, payment_status, created_at) VALUES (?, ?, ?, 'GCash', 'Subscription', ?, 'Pending', ?)");
        $stmtPayment->execute([$gym_id, $subscription_id, $amount, $reference_number, $now]);
        $payment_id = $pdo->lastInsertId();

        // 2. Update client_subscriptions status
        $stmtUpdateSub = $pdo->prepare("UPDATE client_subscriptions SET payment_status = 'Pending Verification', updated_at = ? WHERE client_subscription_id = ?");
        $stmtUpdateSub->execute([$now, $subscription_id]);

        // 3. Log Audit Event
        log_audit_event($pdo, $user_id, $gym_id, 'Create', 'payments', $payment_id, [], ['reference_number' => $reference_number, 'amount' => $amount]);

        $pdo->commit();
        header("Location: ../subscription_plan.php?success=1");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error_msg'] = "Failed to submit payment: " . $e->getMessage();
        header("Location: ../subscription_plan.php");
        exit;
    }
} else {
    header("Location: ../subscription_plan.php");
    exit;
}
