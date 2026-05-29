<?php
ob_start();
header('Content-Type: application/json; charset=UTF-8');

try {
    require_once '../db.php';

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    $workout_id = isset($input['workout_id']) ? (int)$input['workout_id'] : 0;
    $completed_items = isset($input['completed_items']) ? (int)$input['completed_items'] : 0;
    $total_items = isset($input['total_items']) ? (int)$input['total_items'] : 0;

    if ($workout_id <= 0) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Valid Workout ID required.']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE member_workouts SET completed_items = ?, total_items = ? WHERE workout_id = ?");
    $stmt->execute([$completed_items, $total_items, $workout_id]);

    ob_end_clean();
    echo json_encode(['success' => true, 'message' => 'Progress saved.']);
    exit;

} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    exit;
}
