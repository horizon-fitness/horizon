<?php
header('Content-Type: application/json');
require_once '../db.php';

$user_id = $_GET['user_id'] ?? null;
$gym_id = $_GET['gym_id'] ?? null;

if (!$user_id || !$gym_id) {
    echo json_encode(['success' => false, 'message' => 'User ID and Gym ID are required.']);
    exit;
}

try {
    // 1. Get Member ID
    $stmtMember = $pdo->prepare("SELECT member_id FROM members WHERE user_id = ? AND gym_id = ? LIMIT 1");
    $stmtMember->execute([$user_id, $gym_id]);
    $member = $stmtMember->fetch();

    if (!$member) {
        // Not a member, no logs
        echo json_encode(['success' => true, 'logs' => []]);
        exit;
    }

    // 2. Get Logs
    $stmtLogs = $pdo->prepare("
        SELECT a.attendance_date, a.check_in_time, a.check_out_time, a.attendance_status, g.gym_name 
        FROM attendance a
        JOIN gyms g ON a.gym_id = g.gym_id
        WHERE a.member_id = ? AND a.gym_id = ? 
        ORDER BY a.attendance_date DESC, a.check_in_time DESC
        LIMIT 50
    ");
    $stmtLogs->execute([$member['member_id'], $gym_id]);
    $logs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'logs' => $logs]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
