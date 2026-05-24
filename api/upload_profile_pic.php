<?php
ob_start();
header('Content-Type: application/json; charset=UTF-8');

// Increase limits for large base64 strings
ini_set('memory_limit', '256M');

try {
    require_once '../db.php';

    // Accept JSON body or regular POST
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    $user_id      = isset($input['user_id']) ? (int)$input['user_id'] : 0;
    $base64_image = isset($input['image'])   ? $input['image']         : '';
    $member_id    = isset($input['member_id']) ? (int)$input['member_id'] : 0;
    $gym_id       = isset($input['gym_id']) ? (int)$input['gym_id'] : 0;

    if ($user_id <= 0) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Invalid User ID: ' . $user_id]);
        exit;
    }

    // Resolve member_id if not passed but user_id and gym_id are present
    if ($member_id <= 0 && $user_id > 0 && $gym_id > 0) {
        $stmtMem = $pdo->prepare("SELECT member_id FROM members WHERE user_id = ? AND gym_id = ? LIMIT 1");
        $stmtMem->execute([$user_id, $gym_id]);
        $memRow = $stmtMem->fetch();
        if ($memRow) {
            $member_id = (int)$memRow['member_id'];
        }
    }

    $is_removal = (empty($base64_image) || $base64_image === 'remove' || $base64_image === 'delete');
    $dataUrl = null;

    if (!$is_removal) {
        // --- Normalize ---
        // Strip ALL whitespace & newlines that Android's Base64 might insert
        $base64_image = preg_replace('/\s+/', '', $base64_image);

        // If already a full data URL, extract the raw base64 part for validation
        if (strpos($base64_image, 'data:image') === 0) {
            $commaPos = strpos($base64_image, ',');
            $raw      = ($commaPos !== false) ? substr($base64_image, $commaPos + 1) : $base64_image;
            $dataUrl  = $base64_image; // already complete
        } else {
            // Raw base64 (sent from Android) — detect type from magic bytes then build data URL
            $raw     = $base64_image;
            $decoded = base64_decode($raw, false); 
            if ($decoded === false || strlen($decoded) < 4) {
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'Invalid base64 encoding or image too small.']);
                exit;
            }
            // Detect MIME type from magic bytes
            $mime = 'image/png';
            $magic = substr($decoded, 0, 4);
            if (substr($magic, 0, 3) === "\xFF\xD8\xFF")  $mime = 'image/jpeg';
            elseif ($magic === "\x89PNG")                  $mime = 'image/png';
            elseif ($magic === "GIF8")                     $mime = 'image/gif';
            elseif ($magic === "RIFF")                     $mime = 'image/webp';

            $dataUrl = 'data:' . $mime . ';base64,' . $raw;
        }
    }

    // Save the complete data URL (or NULL if removal) directly to the database
    if ($member_id > 0) {
        $stmt = $pdo->prepare("UPDATE members SET profile_picture = ?, updated_at = NOW() WHERE member_id = ?");
        $result = $stmt->execute([$dataUrl, $member_id]);
    } else {
        $stmt = $pdo->prepare("UPDATE users SET profile_picture = ?, updated_at = NOW() WHERE user_id = ?");
        $result = $stmt->execute([$dataUrl, $user_id]);
    }

    if (!$result) {
        $errorInfo = $stmt->errorInfo();
        throw new Exception("Database update failed: " . ($errorInfo[2] ?? 'Unknown error'));
    }

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'message' => $is_removal ? 'Profile picture removed.' : 'Profile picture saved.',
        'path'    => $dataUrl ?? ''
    ]);
    exit;

} catch (Exception $e) {
    ob_end_clean();
    // Return the actual error message to help debugging
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
