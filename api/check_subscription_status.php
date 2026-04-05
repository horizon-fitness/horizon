<?php
/**
 * api/check_subscription_status.php
 * Checks if a user is eligible to buy a new membership.
 */
header('Content-Type: application/json; charset=UTF-8');

try {
    require_once '../db.php';

    $user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

    if ($user_id <= 0) {
        echo json_encode(['can_buy' => false, 'reason' => 'Invalid user ID']);
        exit;
    }

    // Resolve member_id
    $stmtM = $pdo->prepare("SELECT member_id FROM members WHERE user_id = ? LIMIT 1");
    $stmtM->execute([$user_id]);
    $member_id = $stmtM->fetchColumn();

    if (!$member_id) {
        // No member record means they can definitely buy
        echo json_encode(['can_buy' => true]);
        exit;
    }

    // Check for Active or Pending Approval subscriptions
    $stmt = $pdo->prepare("
        SELECT subscription_status, end_date 
        FROM member_subscriptions 
        WHERE member_id = ? 
          AND subscription_status IN ('Active', 'Pending Approval') 
          AND (end_date IS NULL OR end_date >= CURDATE())
        LIMIT 1
    ");
    $stmt->execute([$member_id]);
    $sub = $stmt->fetch();

    if ($sub) {
        $status = $sub['subscription_status'];
        $msg = ($status === 'Pending Approval') 
            ? "You already have a membership request awaiting approval. Please wait for staff verification."
            : "You already have an active membership. You cannot purchase another one until it expires.";
        
        echo json_encode(['can_buy' => false, 'status' => $status, 'message' => $msg]);
    } else {
        echo json_encode(['can_buy' => true]);
    }

} catch (Exception $e) {
    echo json_encode(['can_buy' => false, 'reason' => 'Server error: ' . $e->getMessage()]);
}
