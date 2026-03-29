<?php
/**
 * api/get_active_membership.php
 * Fetches the current active membership details for a given user.
 */
header('Content-Type: application/json; charset=UTF-8');

try {
    require_once '../db.php';

    $user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

    if ($user_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Valid User ID required']);
        exit;
    }

    // 1. Resolve Member ID
    $stmtM = $pdo->prepare("SELECT member_id FROM members WHERE user_id = ? LIMIT 1");
    $stmtM->execute([$user_id]);
    $member_id = $stmtM->fetchColumn();

    if (!$member_id) {
        echo json_encode(['success' => false, 'message' => 'Member record not found']);
        exit;
    }

    // 2. Fetch Active Subscription
    $stmt = $pdo->prepare("
        SELECT 
            ms.subscription_id,
            ms.start_date,
            ms.end_date,
            ms.subscription_status,
            mp.plan_name,
            DATEDIFF(ms.end_date, CURDATE()) as days_remaining
        FROM member_subscriptions ms
        JOIN membership_plans mp ON ms.membership_plan_id = mp.membership_plan_id
        WHERE ms.member_id = ? 
          AND ms.subscription_status = 'Active' 
          AND ms.end_date >= CURDATE()
        ORDER BY ms.end_date DESC
        LIMIT 1
    ");
    $stmt->execute([$member_id]);
    $active_sub = $stmt->fetch();

    if ($active_sub) {
        $active_sub['success'] = true;
        // Format dates for display
        $start = new DateTime($active_sub['start_date']);
        $end = new DateTime($active_sub['end_date']);
        $active_sub['formatted_start'] = $start->format('M d, Y');
        $active_sub['formatted_end'] = $end->format('M d, Y');
        
        echo json_encode($active_sub);
    } else {
        echo json_encode(['success' => false, 'message' => 'No active membership found']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
