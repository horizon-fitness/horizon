<?php
/**
 * api/cancel_booking.php
 * Handles the cancellation of approved/confirmed bookings by a member.
 * Applies penalty rules automatically depending on the time of cancellation.
 */
header('Content-Type: application/json; charset=UTF-8');
require_once '../db.php';
require_once '../includes/mailer.php';

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

    // 2. Retrieve the booking details with extended info for email notification
    $stmt = $pdo->prepare("
        SELECT 
            b.booking_date, b.start_time, b.booking_status, b.booking_reference,
            u.first_name, u.last_name, u.email as member_email,
            g.gym_name, g.email as gym_email,
            sc.service_name
        FROM bookings b
        JOIN members m ON b.member_id = m.member_id
        JOIN users u ON m.user_id = u.user_id
        JOIN gyms g ON b.gym_id = g.gym_id
        JOIN service_catalog sc ON b.catalog_service_id = sc.catalog_service_id
        WHERE b.booking_id = ? AND b.member_id = ? AND b.gym_id = ?
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
    if (in_array($current_status, ['CANCELLED', 'COMPLETED', 'REJECTED'])) {
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
    } elseif ($hours_diff < 1) {
        // Less than 1 hour until session -> Rejected
        $new_status = 'REJECTED';
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
        "Your session was cancelled less than 1 hour in advance and has been marked as REJECTED according to Gym terms." : 
        "Your session cancellation request has been received and an automated notification has been sent to the gym admin. Please wait for the follow-up email confirming approval.";

    // --- AUTOMATED EMAIL NOTIFICATION ---
    if ($new_status === 'CANCELLED' || $new_status === 'REJECTED') {
        $memberName = ($booking['first_name'] ?? 'Member') . ' ' . ($booking['last_name'] ?? '');
        $gymEmail = $booking['gym_email'] ?? 'horizonfitnesscorp@gmail.com';
        $gymName = $booking['gym_name'] ?? 'Horizon System';
        $serviceName = $booking['service_name'] ?? 'Gym Session';
        $bookingDate = date('M d, Y', strtotime($booking['booking_date']));
        $bookingTime = date('h:i A', strtotime($booking['start_time']));
        $refNo = $booking['booking_reference'] ?? 'N/A';

        $subject = "Cancellation Request: $serviceName - $memberName";
        $emailContent = "
            <p>A session cancellation has been initiated through the mobile application.</p>
            <div style='background: #f8f9fa; padding: 20px; border-radius: 10px; margin: 20px 0;'>
                <p style='margin: 5px 0;'><strong>Member:</strong> $memberName</p>
                <p style='margin: 5px 0;'><strong>Service:</strong> $serviceName</p>
                <p style='margin: 5px 0;'><strong>Schedule:</strong> $bookingDate at $bookingTime</p>
                <p style='margin: 5px 0;'><strong>Reference No:</strong> $refNo</p>
                <p style='margin: 5px 0;'><strong>Reason:</strong> " . htmlspecialchars($cancellation_reason) . "</p>
                <p style='margin: 5px 0;'><strong>Status Assigned:</strong> <span style='color: " . ($penalty_applied ? '#ef4444' : '#f59e0b') . ";'>$new_status</span></p>
            </div>
            <p>Please review this request and send the final approval/confirmation email to the member at <strong>{$booking['member_email']}</strong> as per the Updated Terms & Conditions.</p>
        ";

        $fullBody = getFormalEmailTemplate($subject, $emailContent, $gymName);
        sendSystemEmail($gymEmail, $subject, $fullBody);
    }

    echo json_encode([
        'success' => true,
        'message' => $message,
        'new_status' => $new_status,
        'penalty_applied' => $penalty_applied
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
