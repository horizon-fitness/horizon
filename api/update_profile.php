<?php
header('Content-Type: application/json');
require_once '../db_connect.php';

$user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
$gym_id = isset($_POST['gym_id']) ? (int)$_POST['gym_id'] : null;

if ($user_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid User ID']);
    exit;
}

// Fields to allow updating
$allowed_fields = [
    'first_name', 'last_name', 'middle_name', 'birth_date', 'sex', 
    'contact_number', 'address', 'occupation', 'medical_history',
    'emergency_contact_name', 'emergency_contact_number'
];

$updates = [];
$params = [];

foreach ($allowed_fields as $field) {
    if (isset($_POST[$field])) {
        $updates[] = "$field = ?";
        $params[] = $_POST[$field];
    }
}

if (empty($updates)) {
    echo json_encode(['success' => false, 'message' => 'No fields to update']);
    exit;
}

try {
    $sql = "UPDATE users SET " . implode(", ", $updates) . ", updated_at = NOW() WHERE user_id = ?";
    $params[] = $user_id;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    if ($stmt->rowCount() >= 0) { // >= 0 because if no changes made but query successful, it's still success
        echo json_encode(['success' => true, 'message' => 'Profile updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update profile']);
    }

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
