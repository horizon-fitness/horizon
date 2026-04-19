<?php
header('Content-Type: application/json');
require_once '../db.php';

$gym_id = isset($_GET['gym_id']) ? (int)$_GET['gym_id'] : 0;
$date_str = isset($_GET['date']) ? $_GET['date'] : '';
$day_name = !empty($date_str) ? date('l', strtotime($date_str)) : '';

if ($gym_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid Gym ID']);
    exit;
}

try {
    // If a date is provided, filter out coaches who are 'Off' on that day of the week
    $query = "
        SELECT 
            s.staff_id as coach_id, 
            u.first_name, 
            u.last_name, 
            '' as specialization,
            COALESCE(c.session_rate, 0) as session_rates
        FROM staff s
        JOIN users u ON s.user_id = u.user_id
        LEFT JOIN coaches c ON c.user_id = s.user_id AND c.gym_id = s.gym_id
    ";
    
    if (!empty($day_name)) {
        $query .= " LEFT JOIN coach_schedules cs ON s.staff_id = cs.coach_id AND cs.day_of_week = :day_name ";
    }
    
    $query .= " WHERE s.gym_id = :gym_id AND s.staff_role = 'Coach' AND s.status = 'Active' ";
    
    if (!empty($day_name)) {
        $query .= " AND (cs.availability_status IS NULL OR cs.availability_status != 'Off') ";
    }
    
    $stmt = $pdo->prepare($query);
    $params = [':gym_id' => $gym_id];
    if (!empty($day_name)) $params[':day_name'] = $day_name;
    
    $stmt->execute($params);
    $coaches = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'success' => true,
        'coaches' => $coaches
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
}
?>
