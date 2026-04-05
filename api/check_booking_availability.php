<?php
/**
 * api/check_booking_availability.php
 * Checks if a user already has an existing booking for a specific date and time slot.
 */
header('Content-Type: application/json; charset=UTF-8');
require_once '../db.php';

$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$gym_id = isset($_GET['gym_id']) ? (int)$_GET['gym_id'] : 0;
$date = isset($_GET['date']) ? $_GET['date'] : '';
$time = isset($_GET['time']) ? $_GET['time'] : '';

if ($user_id <= 0 || $gym_id <= 0 || empty($date) || empty($time)) {
    echo json_encode(['success' => false, 'available' => false, 'message' => 'Missing required parameters.']);
    exit;
}

try {
    // 1. Resolve member_id
    $stmtM = $pdo->prepare("SELECT member_id FROM members WHERE user_id = ? AND gym_id = ? LIMIT 1");
    $stmtM->execute([$user_id, $gym_id]);
    $member_id = $stmtM->fetchColumn();

    if (!$member_id) {
        // No membership means no existing booking for this gym
        echo json_encode(['success' => true, 'available' => true]);
        exit;
    }

    // 2. Check for pending or approved bookings at the same time
    $stmtCheck = $pdo->prepare("
        SELECT COUNT(*) 
        FROM bookings 
        WHERE member_id = ? 
        AND booking_date = ? 
        AND start_time = ? 
        AND booking_status IN ('Pending', 'Approved')
    ");
    $stmtCheck->execute([$member_id, $date, $time]);
    $existingCount = $stmtCheck->fetchColumn();

    if ($existingCount > 0) {
        echo json_encode([
            'success' => true, 
            'available' => false, 
            'message' => 'You already have a session booked for this time slot.'
        ]);
    } else {
        echo json_encode(['success' => true, 'available' => true]);
    }

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'available' => false, 'message' => $e->getMessage()]);
}
