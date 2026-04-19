<?php
header('Content-Type: application/json');
require_once '../db_connect.php';

$user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
$notification_id = isset($_POST['notification_id']) ? (int)$_POST['notification_id'] : 0;
$clear_all = isset($_POST['clear_all']) ? (bool)$_POST['clear_all'] : false;

if ($user_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid User ID']);
    exit;
}

try {
    if ($clear_all) {
        $stmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $message = "All notifications cleared.";
    } else if ($notification_id > 0) {
        $stmt = $pdo->prepare("DELETE FROM notifications WHERE notification_id = ? AND user_id = ?");
        $stmt->execute([$notification_id, $user_id]);
        if ($stmt->rowCount() > 0) {
            $message = "Notification cleared.";
        } else {
            echo json_encode(['success' => false, 'message' => 'Notification not found or already cleared.']);
            exit;
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Missing notification ID or clear_all flag.']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => $message
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
