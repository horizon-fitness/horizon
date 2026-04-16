<?php
ob_start();
header('Content-Type: application/json; charset=UTF-8');

// --- DEBUG LOGGING UTILITY ---
function payment_debug_log($message) {
    $log_file = __DIR__ . '/payment_debug.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
}

try {
    require_once '../db.php';
    require_once '../includes/audit_logger.php';

    $input_raw = file_get_contents('php://input');
    $input = json_decode($input_raw, true);
    
    payment_debug_log("RECEIVED DATA: " . $input_raw);
    
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
        payment_debug_log("ERROR: Member ID is required.");
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
        // Check if input was user_id
        $stmt = $pdo->prepare("SELECT member_id, user_id, gym_id FROM members WHERE user_id = ? LIMIT 1");
        $stmt->execute([$member_id_input]);
        $member = $stmt->fetch();
    }

    // EMERGENCY AUTO-FIX: If member record doesn't exist for this user, create it now!
    if (!$member) {
        payment_debug_log("MEMBER NOT FOUND: Attempting auto-fix for user_id $member_id_input");
        $stmtUser = $pdo->prepare("SELECT * FROM users WHERE user_id = ? LIMIT 1");
        $stmtUser->execute([$member_id_input]);
        $userData = $stmtUser->fetch();

        if ($userData) {
            // Find a gym_id (default to 1 or the first one found)
            $gym_id = $pdo->query("SELECT gym_id FROM gyms LIMIT 1")->fetchColumn() ?: 1;
            $member_code = 'MBR-' . str_pad($member_id_input, 4, '0', STR_PAD_LEFT);
            
            // FIX: Added missing NOT NULL fields (Emergency Contact Info)
            $stmtInsertMember = $pdo->prepare("INSERT INTO members 
                (user_id, gym_id, member_code, birth_date, sex, emergency_contact_name, emergency_contact_number, member_status, created_at, updated_at) 
                VALUES (?, ?, ?, '2000-01-01', 'Not Specified', 'Not Provided', 'Not Provided', 'Active', ?, ?)");
            $stmtInsertMember->execute([$member_id_input, $gym_id, $member_code, $now, $now]);
            
            $real_member_id = $pdo->lastInsertId();
            $user_id = (int)$member_id_input;
            payment_debug_log("AUTO-FIX SUCCESS: Created member_id $real_member_id for gym $gym_id");
        } else {
            payment_debug_log("ERROR: User ID $member_id_input not found in users table.");
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => "User ID: $member_id_input not found in system."]);
            exit;
        }
    } else {
        $real_member_id = (int)$member['member_id'];
        $user_id = (int)$member['user_id'];
        $gym_id = (int)$member['gym_id'];
    }

    // 2. Resolve Plan Details & Amount
    $amount = 0.0;
    // Check for gym-specific plans only (Global plans removed)
    $stmtPlan = $pdo->prepare("SELECT * FROM membership_plans WHERE membership_plan_id = ? AND gym_id = ? LIMIT 1");
    $stmtPlan->execute([$plan_id, $gym_id]);
    $plan = $stmtPlan->fetch();

    if ($plan) {
        $amount = (float)$plan['price'];
        if ($sessions_total === -1 && $plan['session_limit'] > 0) {
            $sessions_total = (int)$plan['session_limit'];
        }
    } else {
        payment_debug_log("PLAN NOT FOUND: Plan ID $plan_id does not belong to gym $gym_id or is invalid.");
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => "Invalid membership plan selected for this gym."]);
        exit;
    }

    $pdo->beginTransaction();
    payment_debug_log("TRANSACTION STARTED: Inserting records for member $real_member_id");

    // 3. Create Subscription Record
    $stmtSub = $pdo->prepare("INSERT INTO member_subscriptions 
        (member_id, membership_plan_id, start_date, end_date, sessions_total, sessions_used, subscription_status, payment_status, created_at, updated_at) 
        VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?)");
    $stmtSub->execute([$real_member_id, $plan_id, $start_date, $end_date, $sessions_total, $status, $payment_status, $now, $now]);
    $subscription_id = $pdo->lastInsertId();

    // 4. Create Payment Transaction Record
    $reference_number = 'PM-' . strtoupper(substr(md5(time() . $real_member_id), 0, 8));
    
    $stmtPay = $pdo->prepare("INSERT INTO payments 
        (member_id, gym_id, subscription_id, amount, payment_method, payment_type, reference_number, payment_status, created_at) 
        VALUES (?, ?, ?, ?, 'PayMongo', 'Subscription', ?, 'Completed', ?)");
    $stmtPay->execute([$real_member_id, $gym_id, $subscription_id, $amount, $reference_number, $now]);
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
    payment_debug_log("TRANSACTION SUCCESS: subscription_id $subscription_id, payment_id $payment_id");

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
    payment_debug_log("SERVER ERROR: " . $e->getMessage());
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
