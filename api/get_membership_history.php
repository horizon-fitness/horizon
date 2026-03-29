<?php
header('Content-Type: application/json; charset=UTF-8');

try {
    require_once '../db.php';

    $user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

    if ($user_id <= 0) {
        echo json_encode([]);
        exit;
    }

    // 1. Resolve Member ID from User ID
    $stmtMember = $pdo->prepare("SELECT member_id FROM members WHERE user_id = ? LIMIT 1");
    $stmtMember->execute([$user_id]);
    $member = $stmtMember->fetch();

    if (!$member) {
        // Fallback: Check if they are already using their member_id as user_id
        $stmtMember = $pdo->prepare("SELECT member_id FROM members WHERE member_id = ? LIMIT 1");
        $stmtMember->execute([$user_id]);
        $member = $stmtMember->fetch();
    }

    if (!$member) {
        echo json_encode([]);
        exit;
    }

    $member_id = $member['member_id'];

    // 2. Fetch Payments joined with Subscriptions and Plans
    // Using LEFT JOINs to ensure we don't lose records if a plan was deleted
    $stmt = $pdo->prepare("
        SELECT 
            p.payment_id,
            p.amount,
            p.reference_number,
            p.payment_method,
            p.payment_status,
            p.created_at,
            mp.plan_name
        FROM payments p
        JOIN members m ON p.member_id = m.member_id
        LEFT JOIN member_subscriptions ms ON p.subscription_id = ms.subscription_id
        LEFT JOIN membership_plans mp ON ms.membership_plan_id = mp.membership_plan_id
        WHERE m.user_id = ?
        ORDER BY p.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $history = [];
    foreach ($results as $row) {
        $datetime = new DateTime($row['created_at']);
        
        $history[] = [
            'date' => $datetime->format('M d, Y • h:i A'), // Combined for the app's single date field
            'time' => $datetime->format('h:i A'),
            'service' => $row['plan_name'] ?? 'Membership Subscription',
            'reference' => $row['reference_number'],
            'amount' => number_format($row['amount'], 2), // App adds the symbol
            'status' => (in_array($row['payment_status'], ['Completed', 'Verified', 'Paid', 'Active'])) ? 'Approved' : $row['payment_status']
        ];
    }

    echo json_encode($history);

} catch (Exception $e) {
    // Return empty array on error to prevent app crash
    echo json_encode([]);
}
