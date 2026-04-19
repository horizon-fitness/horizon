<?php
header('Content-Type: application/json');
require_once '../db_connect.php';

$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$gym_id = isset($_GET['gym_id']) ? (int)$_GET['gym_id'] : null;

if ($user_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid User ID']);
    exit;
}

try {
    // Fetch notifications, ordered by newest first
    $query = "SELECT * FROM notifications WHERE user_id = ? ";
    $params = [$user_id];
    
    if ($gym_id !== null) {
        $query .= "AND (gym_id = ? OR gym_id IS NULL) ";
        $params[] = $gym_id;
    }
    
    $query .= "ORDER BY created_at DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format time for each notification
    foreach ($notifications as &$notif) {
        $notif['time_ago'] = time_elapsed_string($notif['created_at']);
        $notif['is_read'] = (bool)$notif['is_read'];
    }

    echo json_encode([
        'success' => true,
        'notifications' => $notifications
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}

function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = array(
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    );
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}
?>
