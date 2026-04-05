<?php
session_start();
require_once '../../db.php';
require_once '../../includes/audit_logger.php';

// Security Check: Only Members
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'member') {
    die("Unauthorized access.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['user_id'];
    $gym_id = $_SESSION['gym_id'] ?? 0;
    $plan_name = $_POST['plan_name'];
    $amount = (float)$_POST['amount'];
    $payment_method = $_POST['payment_method'];
    $reference_number = $_POST['reference_number'] ?? null;
    $now = date('Y-m-d H:i:s');

    // 1. Fetch member_id
    $stmtMember = $pdo->prepare("SELECT member_id FROM members WHERE user_id = ? AND gym_id = ? LIMIT 1");
    $stmtMember->execute([$user_id, $gym_id]);
    $member = $stmtMember->fetch();
    $member_id = $member['member_id'] ?? 0;

    // 2. Find membership_plan_id based on name
    $stmtPlan = $pdo->prepare("SELECT membership_plan_id, duration_months, sessions_total FROM membership_plans WHERE plan_name LIKE ? AND gym_id = ? LIMIT 1");
    $stmtPlan->execute(['%' . $plan_name . '%', $gym_id]);
    $plan = $stmtPlan->fetch();

    if (!$plan) {
         // Fallback if not found in DB
         $plan_id = 0;
         $duration = 1;
         $sessions = 0;
    } else {
         $plan_id = $plan['membership_plan_id'];
         $duration = $plan['duration_months'];
         $sessions = $plan['sessions_total'];
    }

    $start_date = date('Y-m-d');
    $end_date = ($duration > 0) ? date('Y-m-d', strtotime("+$duration months")) : null;

    try {
        $pdo->beginTransaction();

        // 3. Create a Pending Subscription
        $stmtSub = $pdo->prepare("INSERT INTO member_subscriptions (member_id, membership_plan_id, start_date, end_date, sessions_total, sessions_used, subscription_status, payment_status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 0, 'Pending', 'Pending Verification', ?, ?)");
        $stmtSub->execute([$member_id, $plan_id, $start_date, $end_date, $sessions, $now, $now]);
        $subscription_id = $pdo->lastInsertId();

        // 4. (DEPRECATED) Handle Proof of Payment (Removed for PayMongo)
        $proof_path = null;

        // 5. Insert Payment Record (Setting type to 'Membership' to distinguish from Tenant 'Subscription')
        $stmtPay = $pdo->prepare("INSERT INTO payments (member_id, gym_id, subscription_id, amount, payment_method, payment_type, reference_number, payment_status, payment_date, created_at) VALUES (?, ?, ?, ?, ?, 'Membership', ?, 'Pending', ?, ?)");
        $stmtPay->execute([$member_id, $gym_id, $subscription_id, $amount, $payment_method, $reference_number, date('Y-m-d'), $now]);
        $payment_id = $pdo->lastInsertId();

        // 6. Audit Log
        log_audit_event($pdo, $user_id, $gym_id, 'Create', 'payments', $payment_id, [], ['amount' => $amount, 'plan' => $plan_name, 'status' => 'Pending']);

        $pdo->commit();
        header("Location: ../member_membership.php?success=1");
    } catch (Exception $e) {
        $pdo->rollBack();
        die("Error processing payment: " . $e->getMessage());
    }
}
