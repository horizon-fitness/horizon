<?php
header('Content-Type: application/json');
include '../db.php';

// Bypass security check (same style as other APIs)
$bypass = isset($_GET['i']) ? (int)$_GET['i'] : 0;
// We allow access if i=1

$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : -1;

if ($userId === -1) {
    echo json_encode(['success' => false, 'message' => 'User ID is required']);
    exit;
}

try {
    // 1. Fetch live notifications from DB
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();

    $notifications = [];
    foreach ($rows as $row) {
        $notifications[] = [
            'id' => (string)$row['notification_id'],
            'title' => $row['title'],
            'message' => $row['message'],
            'time' => formatTimeAgo($row['created_at']),
            'type' => $row['notification_type'],
            'isRead' => (bool)$row['is_read']
        ];
    }

    // 2. If no real notifications yet, add a friendly "Welcome" one to ensure the UI isn't empty for new users
    if (empty($notifications)) {
        $notifications[] = [
            'id' => 'welcome_001',
            'title' => 'Welcome to Horizon Systems!',
            'message' => 'Start your fitness journey today. Check your bookings and membership details here.',
            'time' => 'Just now',
            'type' => 'system',
            'isRead' => false
        ];
    }

    echo json_encode([
        'success' => true,
        'notifications' => $notifications
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

/**
 * Helper to convert timestamp to "2 hours ago" etc.
 */
function formatTimeAgo($timestamp) {
    $time = strtotime($timestamp);
    $diff = time() - $time;
    
    if ($diff < 60) return "Just now";
    if ($diff < 3600) return floor($diff / 60) . "m ago";
    if ($diff < 86400) return floor($diff / 3600) . "h ago";
    if ($diff < 604800) return floor($diff / 86400) . "d ago";
    
    return date("M j, Y", $time);
}
?>
