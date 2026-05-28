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
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    $limitClause = $limit > 0 ? "LIMIT $limit" : "";

    // 1. Fetch live notifications from DB
    $stmt = $pdo->prepare("
        SELECT *, TIMESTAMPDIFF(SECOND, created_at, NOW()) as seconds_ago 
        FROM notifications 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        $limitClause
    ");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();

    $notifications = [];
    foreach ($rows as $row) {
        $notifications[] = [
            'id' => (string)$row['notification_id'],
            'title' => $row['title'],
            'message' => $row['message'],
            'time' => formatTimeAgo((int)$row['seconds_ago'], $row['created_at']),
            'rawTime' => $row['created_at'],
            'type' => $row['notification_type'],
            'isRead' => (bool)$row['is_read']
        ];
    }

    // 2. Always append friendly "Welcome" notifications to the bottom of the history
    $stmtUser = $pdo->prepare("SELECT created_at, TIMESTAMPDIFF(SECOND, created_at, NOW()) as seconds_ago FROM users WHERE user_id = ?");
    $stmtUser->execute([$userId]);
    $userRow = $stmtUser->fetch();
    
    $baseSeconds = $userRow ? (int)$userRow['seconds_ago'] : 0;
    $baseTime = $userRow ? $userRow['created_at'] : date('Y-m-d H:i:s');

    $mockNotifications = [
        [
            'id' => 'welcome_001',
            'title' => 'Welcome to Horizon Systems!',
            'message' => 'Start your fitness journey today. Check your bookings and membership details here.',
            'time' => formatTimeAgo($baseSeconds, $baseTime),
            'rawTime' => $baseTime,
            'type' => 'system',
            'isRead' => false
        ],
        [
            'id' => 'welcome_002',
            'title' => 'Avail a Membership',
            'message' => 'Unlock premium features and unlimited gym access by subscribing to a membership plan.',
            'time' => formatTimeAgo(max(0, $baseSeconds - 120), $baseTime),
            'rawTime' => $baseTime,
            'type' => 'membership',
            'isRead' => false
        ],
        [
            'id' => 'welcome_003',
            'title' => 'Book a Session',
            'message' => 'Ready to workout? Book your next gym session or class through the app easily.',
            'time' => formatTimeAgo(max(0, $baseSeconds - 300), $baseTime),
            'rawTime' => $baseTime,
            'type' => 'booking',
            'isRead' => false
        ],
        [
            'id' => 'welcome_004',
            'title' => 'Check Out Our Coaches',
            'message' => 'Need guidance? Browse our list of professional coaches to help you reach your goals.',
            'time' => formatTimeAgo(max(0, $baseSeconds - 600), $baseTime),
            'rawTime' => $baseTime,
            'type' => 'system',
            'isRead' => false
        ],
        [
            'id' => 'welcome_005',
            'title' => 'Scan Your QR Code',
            'message' => 'Use your mobile QR code at the reception to seamlessly log your gym attendance.',
            'time' => formatTimeAgo(max(0, $baseSeconds - 900), $baseTime),
            'rawTime' => $baseTime,
            'type' => 'system',
            'isRead' => false
        ],
        [
            'id' => 'welcome_006',
            'title' => 'Track Your Progress',
            'message' => 'Check out the BMI calculator and workout tracker to stay on top of your fitness.',
            'time' => formatTimeAgo(max(0, $baseSeconds - 1200), $baseTime),
            'rawTime' => $baseTime,
            'type' => 'system',
            'isRead' => false
        ],
        [
            'id' => 'welcome_007',
            'title' => 'Review House Rules',
            'message' => 'Please take a moment to review the gym policies to ensure a safe environment for everyone.',
            'time' => formatTimeAgo(max(0, $baseSeconds - 1800), $baseTime),
            'rawTime' => $baseTime,
            'type' => 'system',
            'isRead' => false
        ]
    ];

    $notifications = array_merge($notifications, $mockNotifications);

    // 3. Enforce limit on combined array
    if ($limit > 0) {
        $notifications = array_slice($notifications, 0, $limit);
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
function formatTimeAgo($diff, $timestamp) {
    if ($diff < 0) $diff = 0;
    if ($diff < 60) return "Just now";
    if ($diff < 3600) return floor($diff / 60) . "m ago";
    if ($diff < 86400) return floor($diff / 3600) . "h ago";
    if ($diff < 604800) return floor($diff / 86400) . "d ago";
    
    return date("M j, Y", strtotime($timestamp));
}
?>
