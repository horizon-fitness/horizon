<?php
ob_start();
header('Content-Type: application/json; charset=UTF-8');

try {
    require_once '../db.php';

    $user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : (isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0);
    $gym_id = isset($_GET['gym_id']) ? (int)$_GET['gym_id'] : (isset($_POST['gym_id']) ? (int)$_POST['gym_id'] : 0);

    if ($user_id <= 0) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Valid User ID required.']);
        exit;
    }

    // Get member_id from user_id and gym_id
    $stmtMem = $pdo->prepare("SELECT member_id FROM members WHERE user_id = ? AND gym_id = ? LIMIT 1");
    $stmtMem->execute([$user_id, $gym_id]);
    $member = $stmtMem->fetch();

    if (!$member) {
        ob_end_clean();
        echo json_encode(['success' => true, 'workouts' => []]);
        exit;
    }

    // Get workouts assigned to this member
    $stmtWorkouts = $pdo->prepare("
        SELECT mw.workout_id, mw.workout_name, mw.workout_category, mw.difficulty_level, mw.workout_description, mw.workout_status, mw.scheduled_date, mw.completed_items, mw.total_items, mw.created_at,
               CONCAT(u.first_name, ' ', u.last_name) AS coach_name
        FROM member_workouts mw
        LEFT JOIN staff s ON mw.coach_id = s.staff_id
        LEFT JOIN users u ON s.user_id = u.user_id
        WHERE mw.member_id = ? AND mw.gym_id = ?
        ORDER BY mw.created_at DESC
    ");
    $stmtWorkouts->execute([$member['member_id'], $gym_id]);
    $workouts = $stmtWorkouts->fetchAll(PDO::FETCH_ASSOC);

    ob_end_clean();
    echo json_encode(['success' => true, 'workouts' => $workouts]);
    exit;

} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    exit;
}
