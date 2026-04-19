<?php
header('Content-Type: application/json; charset=UTF-8');

try {
    require_once '../db.php';

    $user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
    $gym_id = isset($_GET['gym_id']) ? (int)$_GET['gym_id'] : 0;
    $show_all = isset($_GET['show_all']) ? (int)$_GET['show_all'] : 0;

    if ($user_id <= 0 || $gym_id <= 0) {
        echo json_encode([]);
        exit;
    }

    // 1. Resolve Member ID for specific Gym
    $stmtMember = $pdo->prepare("SELECT member_id FROM members WHERE user_id = ? AND gym_id = ? LIMIT 1");
    $stmtMember->execute([$user_id, $gym_id]);
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

    // 2. Fetch Payments joined with Subscriptions, Plans, and Bookings
    $stmt = $pdo->prepare("
        SELECT 
            p.payment_id, p.amount, p.reference_number, p.payment_method, p.payment_status, p.created_at, p.booking_id,
            ms.subscription_status,
            mp.plan_name,
            b.booking_status
        FROM payments p
        JOIN members m ON p.member_id = m.member_id
        LEFT JOIN member_subscriptions ms ON p.subscription_id = ms.subscription_id
        LEFT JOIN membership_plans mp ON ms.membership_plan_id = mp.membership_plan_id
        LEFT JOIN bookings b ON p.booking_id = b.booking_id
        WHERE m.user_id = ? AND m.gym_id = ? " . ($show_all ? "" : " AND p.booking_id IS NULL") . "
        ORDER BY p.created_at DESC
    ");
    $stmt->execute([$user_id, $gym_id]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $history = [];
    foreach ($results as $row) {
        $datetime = new DateTime($row['created_at']);
        
        // Final Status Resolver: Priority Booking Status > Subscription Status > Payment Status
        $status = 'Pending';
        if ($row['booking_id'] != null) {
            $rawStatus = $row['booking_status'] ?? 'Pending';
            $status = ($rawStatus === 'Confirmed') ? 'Approved' : (($rawStatus === 'Cancelled') ? 'Rejected' : $rawStatus);
        } else {
            if ($row['subscription_status'] === 'Pending Approval') {
                $status = 'Pending';
            } else {
                $status = (in_array($row['payment_status'], ['Completed', 'Verified', 'Paid', 'Active'])) ? 'Approved' : $row['payment_status'];
            }
        }
        
        $history[] = [
            'date' => $datetime->format('M d, Y • h:i A'), 
            'time' => $datetime->format('h:i A'),
            'service' => ($row['booking_id'] != null) ? 'Gym Booking' : ($row['plan_name'] ?? 'Membership Subscription'),
            'reference' => $row['reference_number'],
            'amount' => number_format((float)$row['amount'], 2),
            'status' => $status
        ];
    }

    echo json_encode($history);

} catch (Exception $e) {
    // Return empty array on error to prevent app crash
    echo json_encode([]);
}
