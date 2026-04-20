<?php
/**
 * api/cancel_booking.php
 * Handles the cancellation of approved/confirmed bookings by a member.
 * Applies penalty rules automatically depending on the time of cancellation.
 */
header('Content-Type: application/json; charset=UTF-8');
require_once '../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
$gym_id = isset($_POST['gym_id']) ? (int)$_POST['gym_id'] : 0;
// We might receive 'log_id' instead of booking_id from mobile, let's cater to both if needed.
$booking_id = isset($_POST['booking_id']) ? (int)$_POST['booking_id'] : 0;
$cancellation_reason = isset($_POST['cancellation_reason']) ? trim($_POST['cancellation_reason']) : '';

if ($user_id <= 0 || $gym_id <= 0 || $booking_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters.']);
    exit;
}

if (empty($cancellation_reason)) {
    echo json_encode(['success' => false, 'message' => 'Cancellation reason is required.']);
    exit;
}

try {
    // 1. Get member_id for this specific gym
    $stmtM = $pdo->prepare("SELECT member_id FROM members WHERE user_id = ? AND gym_id = ? LIMIT 1");
    $stmtM->execute([$user_id, $gym_id]);
    $member_id = $stmtM->fetchColumn();

    if (!$member_id) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized or gym membership not found.']);
        exit;
    }

    // 2. Retrieve the booking details
    $stmt = $pdo->prepare("
        SELECT booking_date, start_time, booking_status 
        FROM bookings 
        WHERE booking_id = ? AND member_id = ? AND gym_id = ?
        LIMIT 1
    ");
    $stmt->execute([$booking_id, $member_id, $gym_id]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        echo json_encode(['success' => false, 'message' => 'Booking not found.']);
        exit;
    }

    $current_status = strtoupper($booking['booking_status']);
    
    // Check if it's already cancelled or completed
    if (in_array($current_status, ['CANCELLED', 'FORFEITED', 'COMPLETED', 'REJECTED'])) {
        echo json_encode(['success' => false, 'message' => "Cannot cancel a booking that is currently $current_status."]);
        exit;
    }

    // 3. Compute times to check the 24-hour rule
    // Date format example: 2024-01-01 14:00:00
    $booking_datetime_str = $booking['booking_date'] . ' ' . $booking['start_time'];
    $booking_timestamp = strtotime($booking_datetime_str);
    $current_timestamp = time();

    // Calculate hours difference
    $hours_diff = ($booking_timestamp - $current_timestamp) / 3600;

    // Automated Policy Evaluation
    $new_status = 'CANCELLED';
    $penalty_applied = false;

    if ($booking_timestamp < $current_timestamp) {
        // Session already started or passed
        echo json_encode(['success' => false, 'message' => 'Cannot cancel a session that has already passed.']);
        exit;
    } elseif ($hours_diff < 24) {
        // Less than 24 hours until session -> Forfeited
        $new_status = 'FORFEITED';
        $penalty_applied = true;
    }

    // 4. Update the Booking
    $updateStmt = $pdo->prepare("
        UPDATE bookings 
        SET booking_status = ?, 
            cancellation_reason = ?, 
            updated_at = NOW() 
        WHERE booking_id = ?
    ");
    $updateStmt->execute([$new_status, $cancellation_reason, $booking_id]);

    $message = $penalty_applied ? 
        "Your session was cancelled less than 24 hours in advance and has been marked as FORFEITED according to Gym terms." : 
        "Your session has been successfully CANCELLED.";

    echo json_encode([
        'success' => true,
        'message' => $message,
        'new_status' => $new_status,
        'penalty_applied' => $penalty_applied
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
