<?php
header('Content-Type: application/json');
require_once '../db.php';

$gym_id = isset($_GET['gym_id']) ? (int)$_GET['gym_id'] : 0;

if ($gym_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid Gym ID']);
    exit;
}

try {
    // Restore original working query + add session_rate from coaches table
    $stmt = $pdo->prepare("
        SELECT 
            s.staff_id as coach_id, 
            u.first_name, 
            u.last_name, 
            '' as specialization,
            COALESCE(c.session_rate, 0) as session_rates
        FROM staff s
        JOIN users u ON s.user_id = u.user_id
        LEFT JOIN coaches c ON c.user_id = s.user_id AND c.gym_id = s.gym_id
        WHERE s.gym_id = ? AND s.staff_role = 'Coach' AND s.status = 'Active'
    ");
    $stmt->execute([$gym_id]);
    $coaches = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'success' => true,
        'coaches' => $coaches
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
}
?>
