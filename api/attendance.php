<?php
header('Content-Type: application/json');
require_once '../db.php';

$input = json_decode(file_get_contents('php://input'), true);
$user_id = $input['user_id'] ?? null;
$gym_id = $input['gym_id'] ?? null;
$action = $input['action'] ?? 'status'; // 'check_in', 'check_out', or 'status'

if (!$user_id || !$gym_id) {
    echo json_encode(['success' => false, 'message' => 'User ID and Gym ID are required.']);
    exit;
}

try {
    // 1. Get Member ID
    $stmtMember = $pdo->prepare("SELECT m.member_id, u.first_name, u.last_name FROM members m JOIN users u ON m.user_id = u.user_id WHERE m.user_id = ? AND m.gym_id = ? LIMIT 1");
    $stmtMember->execute([$user_id, $gym_id]);
    $member = $stmtMember->fetch();

    if (!$member) {
        echo json_encode(['success' => false, 'message' => 'Member record not found for this gym.']);
        exit;
    }

    $member_id = $member['member_id'];
    $member_name = trim($member['first_name'] . ' ' . $member['last_name']);
    $today = date('Y-m-d');
    $now = date('H:i:s');

    if ($action === 'check_in' || $action === 'check_out' || $action === 'toggle' || $action === 'checkin') {
        
        // Find active session
        $stmtActive = $pdo->prepare("SELECT attendance_id FROM attendance WHERE member_id = ? AND attendance_date = ? AND check_out_time IS NULL ORDER BY check_in_time DESC LIMIT 1");
        $stmtActive->execute([$member_id, $today]);
        $session = $stmtActive->fetch();

        if ($session) {
            // User is already checked in, so we CHECK OUT
            $stmtOut = $pdo->prepare("UPDATE attendance SET check_out_time = ?, attendance_status = 'Completed' WHERE attendance_id = ?");
            $stmtOut->execute([$now, $session['attendance_id']]);
            echo json_encode(['success' => true, 'member_name' => $member_name, 'message' => 'Checked out successfully!']);
        } else {
            // User is not checked in, so we CHECK IN
            $stmtIn = $pdo->prepare("INSERT INTO attendance (member_id, gym_id, attendance_date, check_in_time, attendance_status, created_at) VALUES (?, ?, ?, ?, 'Active', NOW())");
            $stmtIn->execute([$member_id, $gym_id, $today, $now]);
            echo json_encode(['success' => true, 'member_name' => $member_name, 'message' => 'Checked in successfully!']);
        }
        exit;
    }
    else {
        // Default: Get Status
        $stmtStatus = $pdo->prepare("SELECT check_in_time, check_out_time, attendance_status FROM attendance WHERE member_id = ? AND attendance_date = ? ORDER BY created_at DESC LIMIT 1");
        $stmtStatus->execute([$member_id, $today]);
        $status = $stmtStatus->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'is_checked_in' => ($status && $status['check_out_time'] === null),
            'last_session' => $status
        ]);
    }

}
catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
