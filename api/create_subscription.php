<?php
ob_start();
header('Content-Type: application/json; charset=UTF-8');

try {
    require_once '../db.php';
    require_once '../includes/audit_logger.php';

    $input = json_decode(file_get_contents('php://input'), true);
    
    // member_id in request can be user_id or actual member_id from Android app
    $member_id_input = isset($input['member_id']) ? (int)$input['member_id'] : 0;
    $plan_id = isset($input['membership_plan_id']) ? (int)$input['membership_plan_id'] : 1;
    $start_date = isset($input['start_date']) ? $input['start_date'] : date('Y-m-d');
    $end_date = isset($input['end_date']) ? $input['end_date'] : null;
    $sessions_total = isset($input['sessions_total']) ? (int)$input['sessions_total'] : -1;
    $status = isset($input['subscription_status']) ? $input['subscription_status'] : 'Active';
    $payment_status = isset($input['payment_status']) ? $input['payment_status'] : 'Paid';
    $now = date('Y-m-d H:i:s');

    if ($member_id_input <= 0) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Member ID is required.']);
        exit;
    }

    // 1. Resolve Member and Gym
    // Check if input is actual member_id
    $stmt = $pdo->prepare("SELECT member_id, user_id, gym_id FROM members WHERE member_id = ? LIMIT 1");
    $stmt->execute([$member_id_input]);
    $member = $stmt->fetch();

    if (!$member) {
        // Check if input was user_id (fall back)
        $stmt = $pdo->prepare("SELECT member_id, user_id, gym_id FROM members WHERE user_id = ? LIMIT 1");
        $stmt->execute([$member_id_input]);
        $member = $stmt->fetch();
    }

    if (!$member) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => "Member record not found for ID: $member_id_input"]);
        exit;
    }

    $real_member_id = (int)$member['member_id'];
    $user_id = (int)$member['user_id'];
    $gym_id = (int)$member['gym_id'];

    // 2. Resolve Plan Details & Amount (Optional validation)
    $amount = 0.0;
    $stmtPlan = $pdo->prepare("SELECT * FROM membership_plans WHERE membership_plan_id = ? AND gym_id = ? LIMIT 1");
    $stmtPlan->execute([$plan_id, $gym_id]);
    $plan = $stmtPlan->fetch();

    if ($plan) {
        $amount = (float)$plan['price'];
        if ($sessions_total === -1 && $plan['session_limit'] > 0) {
            $sessions_total = (int)$plan['session_limit'];
        }
    } else {
        // Fallback default prices based on app selection logic if plan not in DB
        if ($plan_id == 1) $amount = 1500.00;
        elseif ($plan_id == 2) $amount = 4000.00;
        elseif ($plan_id == 3) $amount = 14000.00;
        
        if ($sessions_total === -1 && $plan_id == 1) $sessions_total = 30;
    }

    $pdo->beginTransaction();

    // 3. Create Subscription Record
    $stmtSub = $pdo->prepare("INSERT INTO member_subscriptions 
        (member_id, membership_plan_id, start_date, end_date, sessions_total, sessions_used, subscription_status, payment_status, created_at, updated_at) 
        VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?)");
    $stmtSub->execute([$real_member_id, $plan_id, $start_date, $end_date, $sessions_total, $status, $payment_status, $now, $now]);
    $subscription_id = $pdo->lastInsertId();

    // 4. Create Payment Transaction Record
    $reference_number = 'PM-' . strtoupper(substr(md5(time() . $real_member_id), 0, 8));
    
    $stmtPay = $pdo->prepare("INSERT INTO payments 
        (member_id, gym_id, subscription_id, amount, payment_method, payment_type, reference_number, payment_status, payment_date, created_at) 
        VALUES (?, ?, ?, ?, 'PayMongo', 'Subscription', ?, 'Completed', ?, ?)");
    $stmtPay->execute([$real_member_id, $gym_id, $subscription_id, $amount, $reference_number, date('Y-m-d'), $now]);
    $payment_id = $pdo->lastInsertId();

    // 5. Update Member Status if needed
    $stmtUpdateMember = $pdo->prepare("UPDATE members SET member_status = 'Active' WHERE member_id = ?");
    $stmtUpdateMember->execute([$real_member_id]);

    // 6. Audit Log
    log_audit_event($pdo, $user_id, $gym_id, 'Create', 'member_subscriptions', $subscription_id, [], [
        'plan_id' => $plan_id, 
        'status' => $status,
        'payment' => 'PayMongo'
    ]);

    $pdo->commit();

    ob_end_clean();
    echo json_encode([
        'success' => true, 
        'message' => 'Subscription and Payment recorded successfully',
        'subscription_id' => (int)$subscription_id,
        'payment_id' => (int)$payment_id
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
