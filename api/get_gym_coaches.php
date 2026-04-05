<?php
header('Content-Type: application/json');
require_once '../db.php';

$gym_id = isset($_GET['gym_id']) ? (int)$_GET['gym_id'] : 0;

if ($gym_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid Gym ID']);
    exit;
}

try {
    // Get active coaches Joined with users table
    $stmt = $pdo->prepare("
        SELECT c.coach_id, u.first_name, u.last_name, c.specialization
        FROM coaches c
        JOIN users u ON c.user_id = u.user_id
        WHERE c.gym_id = ? AND c.status = 'Active'
    ");
    $stmt->execute([$gym_id]);
    $coaches = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'coaches' => $coaches
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
}
?>
