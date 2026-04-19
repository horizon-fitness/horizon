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

// Operational Hours Validation (7 AM to 10 PM)
$hour = (int)date('H', strtotime($time));
$minute = (int)date('i', strtotime($time));
if ($hour < 7 || $hour > 22 || ($hour == 22 && $minute > 0)) {
    echo json_encode(['success' => true, 'available' => false, 'message' => 'Bookings are only available from 7 AM to 10 PM.']);
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

    // 2. Check for pending or approved bookings at the same time for the MEMBER
    $stmtCheck = $pdo->prepare("
        SELECT COUNT(*) 
        FROM bookings 
        WHERE member_id = ? 
        AND booking_date = ? 
        AND start_time = ? 
        AND booking_status IN ('Pending', 'Approved', 'Confirmed')
    ");
    $stmtCheck->execute([$member_id, $date, $time]);
    $existingCount = $stmtCheck->fetchColumn();

    if ($existingCount > 0) {
        echo json_encode([
            'success' => true, 
            'available' => false, 
            'message' => 'You already have a session booked for this time slot.'
        ]);
        exit;
    }

    // 3. Check for pending or approved bookings at the same time for the COACH
    $coach_id = isset($_GET['coach_id']) ? (int)$_GET['coach_id'] : 0;
    if ($coach_id > 0) {
        // --- Added: Verify Coach Shift Availability ---
        $day_name = date('l', strtotime($date));
        $stmtShift = $pdo->prepare("SELECT * FROM coach_schedules WHERE coach_id = ? AND day_of_week = ? LIMIT 1");
        $stmtShift->execute([$coach_id, $day_name]);
        $shift = $stmtShift->fetch();

        if ($shift) {
            if ($shift['availability_status'] === 'Off') {
                echo json_encode(['success' => true, 'available' => false, 'message' => 'Coach is off on this day.']);
                exit;
            }
            
            $req_time = date('H:i:s', strtotime($time));
            $m_start = !empty($shift['morning_start']) ? date('H:i:s', strtotime($shift['morning_start'])) : null;
            $m_end = !empty($shift['morning_end']) ? date('H:i:s', strtotime($shift['morning_end'])) : null;
            $a_start = !empty($shift['afternoon_start']) ? date('H:i:s', strtotime($shift['afternoon_start'])) : null;
            $a_end = !empty($shift['afternoon_end']) ? date('H:i:s', strtotime($shift['afternoon_end'])) : null;

            $is_available = false;
            if ($m_start && $m_end && $req_time >= $m_start && $req_time < $m_end) {
                $is_available = true;
            }
            if ($a_start && $a_end && $req_time >= $a_start && $req_time < $a_end) {
                $is_available = true;
            }

            if (!$is_available) {
                echo json_encode(['success' => true, 'available' => false, 'message' => 'Requested time is outside the coach\'s working hours.']);
                exit;
            }
        }
        // --- End of Shift Check ---

        $stmtCoachCheck = $pdo->prepare("
            SELECT COUNT(*) 
            FROM bookings 
            WHERE coach_id = ? 
            AND booking_date = ? 
            AND start_time = ? 
            AND booking_status IN ('Pending', 'Approved', 'Confirmed')
        ");
        $stmtCoachCheck->execute([$coach_id, $date, $time]);
        $coachConflictCount = $stmtCoachCheck->fetchColumn();

        if ($coachConflictCount > 0) {
            echo json_encode([
                'success' => true, 
                'available' => false, 
                'message' => 'Coach is busy at this time.'
            ]);
            exit;
        }
    }

    echo json_encode(['success' => true, 'available' => true]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'available' => false, 'message' => $e->getMessage()]);
}
