<?php
header('Content-Type: application/json');
require_once '../db.php';

$gym_id = isset($_GET['gym_id']) ? (int)$_GET['gym_id'] : 0;

if ($gym_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid Gym ID']);
    exit;
}

try {
    // Get active employees with the role 'Coach' from the staff table
    $stmt = $pdo->prepare("
        SELECT s.staff_id as coach_id, u.first_name, u.last_name, 'Fitness Specialist' as specialization
        FROM staff s
        JOIN users u ON s.user_id = u.user_id
        WHERE s.gym_id = ? AND s.staff_role = 'Coach' AND s.status = 'Active'
    ");
    $stmt->execute([$gym_id]);
    $coaches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'coaches' => $coaches
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
}
?>
