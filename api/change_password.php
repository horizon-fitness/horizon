<?php
ob_start();
header('Content-Type: application/json; charset=UTF-8');

try {
    require_once '../db.php';

    $input = json_decode(file_get_contents('php://input'), true);
    $user_id = isset($input['user_id']) ? (int)$input['user_id'] : 0;
    $current_password = isset($input['current_password']) ? (string)$input['current_password'] : '';
    $new_password = isset($input['new_password']) ? (string)$input['new_password'] : '';

    if ($user_id <= 0 || empty($current_password) || empty($new_password)) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
        exit;
    }

    // 1. Verify user and current password
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE user_id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($current_password, $user['password_hash'])) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Current password incorrect.']);
        exit;
    }

    // 2. Hash new password and update
    $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
    $stmtUpdate = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE user_id = ?");
    $stmtUpdate->execute([$new_hash, $user_id]);

    ob_end_clean();
    echo json_encode(['success' => true, 'message' => 'Password changed successfully.']);
    exit;

} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    exit;
}
