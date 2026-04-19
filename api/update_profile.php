<?php
ob_start();
header('Content-Type: application/json; charset=UTF-8');

try {
    require_once '../db.php';

    $input = json_decode(file_get_contents('php://input'), true);
    $user_id = isset($input['user_id']) ? (int)$input['user_id'] : 0;

    if ($user_id <= 0) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Valid User ID required.']);
        exit;
    }

    $username = trim($input['username'] ?? '');
    $email = trim($input['email'] ?? '');

    // Check for username uniqueness
    if (!empty($username)) {
        $stmtC = $pdo->prepare("SELECT user_id FROM users WHERE username = ? AND user_id != ?");
        $stmtC->execute([$username, $user_id]);
        if ($stmtC->fetch()) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Username already taken.']);
            exit;
        }
    }

    // Check for email uniqueness
    if (!empty($email)) {
        $stmtC = $pdo->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
        $stmtC->execute([$email, $user_id]);
        if ($stmtC->fetch()) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Email already registered.']);
            exit;
        }
    }

    // 1. Update USERS table
    $sqlUser = "UPDATE users SET 
                username = COALESCE(NULLIF(?, ''), username),
                email = COALESCE(NULLIF(?, ''), email),
                first_name = ?, 
                last_name = ?, 
                middle_name = ?, 
                contact_number = ?, 
                birth_date = ?, 
                sex = ?,
                updated_at = NOW() 
                WHERE user_id = ?";
    
    $stmtUser = $pdo->prepare($sqlUser);
    $stmtUser->execute([
        $username,
        $email,
        $input['first_name'] ?? '',
        $input['last_name'] ?? '',
        $input['middle_name'] ?? '',
        $input['contact_number'] ?? '',
        $input['birth_date'] ?? null,
        $input['sex'] ?? '',
        $user_id
    ]);

    // 2. Handle Address if gym_id is provided
    $gym_id = isset($input['gym_id']) ? (int)$input['gym_id'] : 0;
    if ($gym_id > 0) {
        // Find existing member record to get address_id
        $stmtMember = $pdo->prepare("SELECT address_id FROM members WHERE user_id = ? AND gym_id = ? LIMIT 1");
        $stmtMember->execute([$user_id, $gym_id]);
        $member = $stmtMember->fetch();

        $address_id = $member ? $member['address_id'] : null;

        if ($address_id) {
            // Update existing address
            $sqlAddr = "UPDATE addresses SET 
                        address_line = ?, 
                        barangay = ?, 
                        city = ?, 
                        province = ?, 
                        region = ?, 
                        updated_at = NOW() 
                        WHERE address_id = ?";
            $stmtAddr = $pdo->prepare($sqlAddr);
            $stmtAddr->execute([
                $input['address_line'] ?? ($input['address'] ?? ''),
                $input['barangay'] ?? '',
                $input['city'] ?? '',
                $input['province'] ?? '',
                $input['region'] ?? '',
                $address_id
            ]);
        } else {
            // Create new address
            $sqlAddr = "INSERT INTO addresses (address_line, barangay, city, province, region, created_at, updated_at) 
                        VALUES (?, ?, ?, ?, ?, NOW(), NOW())";
            $stmtAddr = $pdo->prepare($sqlAddr);
            $stmtAddr->execute([
                $input['address_line'] ?? ($input['address'] ?? ''),
                $input['barangay'] ?? '',
                $input['city'] ?? '',
                $input['province'] ?? '',
                $input['region'] ?? ''
            ]);
            $address_id = $pdo->lastInsertId();
        }

        // 3. Update MEMBERS table
        $sqlMem = "UPDATE members SET 
                   address_id = ?, 
                   occupation = ?, 
                   medical_history = ?, 
                   emergency_contact_name = ?, 
                   emergency_contact_number = ?, 
                   parent_name = ?, 
                   parent_contact = ?, 
                   updated_at = NOW() 
                   WHERE user_id = ? AND gym_id = ?";
        $stmtMem = $pdo->prepare($sqlMem);
        $stmtMem->execute([
            $address_id,
            $input['occupation'] ?? '',
            $input['medical_history'] ?? '',
            $input['emergency_contact_name'] ?? '',
            $input['emergency_contact_number'] ?? '',
            $input['parent_name'] ?? '',
            $input['parent_contact_number'] ?? ($input['parent_contact'] ?? ''),
            $user_id,
            $gym_id
        ]);
    }

    ob_end_clean();
    echo json_encode(['success' => true, 'message' => 'Profile updated successfully.']);
    exit;

} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    exit;
}
