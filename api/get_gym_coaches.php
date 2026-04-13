<?php
header('Content-Type: application/json');
require_once '../db.php';

$gym_id = isset($_GET['gym_id']) ? (int)$_GET['gym_id'] : 0;

if ($gym_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid Gym ID']);
    exit;
}

try {
    // Get active coaches by joining coaches with users and their applications for specialization
    $stmt = $pdo->prepare("
        SELECT 
            c.coach_id, 
            u.first_name, 
            u.last_name, 
            ca.specialization
        FROM coaches c
        JOIN users u ON c.user_id = u.user_id
        JOIN coach_applications ca ON c.coach_application_id = ca.coach_application_id
        WHERE c.gym_id = ? AND c.status = 'Active'
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
