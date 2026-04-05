<?php
/**
 * api/get_user_bookings.php
 * Fetches the booking history for a specific user across all gyms they are a member of.
 */
header('Content-Type: application/json; charset=UTF-8');
require_once '../db.php';

$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

if ($user_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'User ID is required.', 'bookings' => []]);
    exit;
}

try {
    // 1. Get all member_ids for this user
    $stmtM = $pdo->prepare("SELECT member_id FROM members WHERE user_id = ?");
    $stmtM->execute([$user_id]);
    $member_ids = $stmtM->fetchAll(PDO::FETCH_COLUMN);

    if (empty($member_ids)) {
        echo json_encode(['success' => true, 'bookings' => []]);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($member_ids), '?'));

    // 2. Fetch bookings matching TrainingLog model fields
    $stmt = $pdo->prepare("
        SELECT 
            b.booking_id, 
            b.booking_reference, 
            b.booking_date as date, 
            b.start_time as time, 
            '60 mins' as duration,
            CASE 
                WHEN b.coach_id IS NOT NULL AND (COALESCE(gs.custom_service_name, sc.service_name, '') = '' OR COALESCE(gs.custom_service_name, sc.service_name, '') LIKE '%Gym Use%') THEN 'Personal Training'
                ELSE COALESCE(gs.custom_service_name, sc.service_name, 'Unlimited Gym Use')
            END as service, 
            CASE 
                WHEN b.coach_id IS NULL THEN 'Self'
                ELSE CONCAT(u.first_name, ' ', u.last_name)
            END as trainer,
            b.booking_status as status,
            g.gym_name
        FROM bookings b
        LEFT JOIN gym_services gs ON b.gym_service_id = gs.gym_service_id
        LEFT JOIN service_catalog sc ON gs.catalog_service_id = sc.catalog_service_id
        LEFT JOIN gyms g ON b.gym_id = g.gym_id
        LEFT JOIN staff s ON b.coach_id = s.staff_id
        LEFT JOIN users u ON s.user_id = u.user_id
        WHERE b.member_id IN ($placeholders)
        ORDER BY b.booking_date DESC, b.start_time DESC
    ");
    $stmt->execute($member_ids);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'bookings' => $bookings
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage(), 'bookings' => []]);
}
