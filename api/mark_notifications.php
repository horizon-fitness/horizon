<?php
header('Content-Type: application/json');
include '../db.php';

$bypass = isset($_POST['i']) ? (int)$_POST['i'] : 0;
// We allow access if i=1

$userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : -1;
$action = isset($_POST['action']) ? trim($_POST['action']) : '';
$notificationId = isset($_POST['notification_id']) ? trim($_POST['notification_id']) : '';

if ($userId === -1 || empty($action)) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
}

try {
    if ($action === 'read_all') {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        $stmt->execute([$userId]);
        echo json_encode(['success' => true, 'message' => 'All notifications marked as read']);
        exit;
    } 
    else if ($action === 'read' || $action === 'unread') {
        if (empty($notificationId)) {
            echo json_encode(['success' => false, 'message' => 'Notification ID required for this action']);
            exit;
        }
        $isRead = ($action === 'read') ? 1 : 0;
        
        // Don't error out if it's a mock notification (starts with welcome_), just return success
        if (strpos($notificationId, 'welcome_') === 0) {
            echo json_encode(['success' => true, 'message' => 'Mock notification toggled']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE notifications SET is_read = ? WHERE notification_id = ? AND user_id = ?");
        $stmt->execute([$isRead, $notificationId, $userId]);
        echo json_encode(['success' => true, 'message' => "Notification marked as $action"]);
        exit;
    } 
    else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit;
    }

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
