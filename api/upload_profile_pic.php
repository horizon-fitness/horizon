<?php
ob_start();
header('Content-Type: application/json; charset=UTF-8');

try {
    require_once '../db.php';

    // Get input data (expecting JSON or POST)
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    $user_id = isset($input['user_id']) ? (int)$input['user_id'] : 0;
    $base64_image = isset($input['image']) ? $input['image'] : '';

    if ($user_id <= 0 || empty($base64_image)) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Valid User ID and Image data required.']);
        exit;
    }

    // Prepare directory
    $upload_dir = '../uploads/profile_pics/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    // Clean base64 string (remove data:image/png;base64, if present)
    if (preg_match('/^data:image\/(\w+);base64,/', $base64_image, $type)) {
        $base64_image = substr($base64_image, strpos($base64_image, ',') + 1);
        $type = strtolower($type[1]); // jpg, png, etc.
    } else {
        $type = 'png'; // default
    }

    $image_data = base64_decode($base64_image);
    if ($image_data === false) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Invalid image encoding.']);
        exit;
    }

    // Save file
    $file_name = 'user_' . $user_id . '_' . time() . '.' . $type;
    $file_path = $upload_dir . $file_name;
    
    if (file_put_contents($file_path, $image_data)) {
        // Update database
        $public_path = 'uploads/profile_pics/' . $file_name;
        $stmt = $pdo->prepare("UPDATE users SET profile_picture = ?, updated_at = NOW() WHERE user_id = ?");
        $stmt->execute([$public_path, $user_id]);

        ob_end_clean();
        echo json_encode(['success' => true, 'message' => 'Profile picture uploaded.', 'path' => $public_path]);
    } else {
        throw new Exception("Failed to save image file.");
    }

} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
