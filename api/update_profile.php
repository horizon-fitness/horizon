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
    $userFields = [];
    $userParams = [];

    if (array_key_exists('username', $input) && !empty(trim($input['username']))) {
        $userFields[] = "username = ?";
        $userParams[] = trim($input['username']);
    }
    if (array_key_exists('email', $input) && !empty(trim($input['email']))) {
        $userFields[] = "email = ?";
        $userParams[] = trim($input['email']);
    }
    if (array_key_exists('first_name', $input)) {
        $userFields[] = "first_name = ?";
        $userParams[] = trim($input['first_name']);
    }
    if (array_key_exists('last_name', $input)) {
        $userFields[] = "last_name = ?";
        $userParams[] = trim($input['last_name']);
    }
    if (array_key_exists('middle_name', $input)) {
        $userFields[] = "middle_name = ?";
        $userParams[] = trim($input['middle_name']);
    }
    if (array_key_exists('contact_number', $input)) {
        $userFields[] = "contact_number = ?";
        $userParams[] = trim($input['contact_number']);
    }
    if (array_key_exists('birth_date', $input)) {
        $userFields[] = "birth_date = ?";
        $userParams[] = !empty($input['birth_date']) ? $input['birth_date'] : null;
    }
    if (array_key_exists('sex', $input)) {
        $userFields[] = "sex = ?";
        $userParams[] = trim($input['sex']);
    }

    if (!empty($userFields)) {
        $userFields[] = "updated_at = NOW()";
        $sqlUser = "UPDATE users SET " . implode(', ', $userFields) . " WHERE user_id = ?";
        $userParams[] = $user_id;
        $stmtUser = $pdo->prepare($sqlUser);
        $stmtUser->execute($userParams);
    }

    // 2. Handle Address if gym_id is provided
    $gym_id = isset($input['gym_id']) ? (int)$input['gym_id'] : 0;
    if ($gym_id > 0) {
        // Find existing member record to get address_id
        $stmtMember = $pdo->prepare("SELECT address_id FROM members WHERE user_id = ? AND gym_id = ? LIMIT 1");
        $stmtMember->execute([$user_id, $gym_id]);
        $member = $stmtMember->fetch();

        $address_id = $member ? $member['address_id'] : null;

        if ($address_id) {
            $addrFields = [];
            $addrParams = [];
            if (array_key_exists('address_line', $input) || array_key_exists('address', $input)) {
                $addrFields[] = "address_line = ?";
                $addrParams[] = $input['address_line'] ?? ($input['address'] ?? '');
            }
            if (array_key_exists('barangay', $input)) {
                $addrFields[] = "barangay = ?";
                $addrParams[] = $input['barangay'];
            }
            if (array_key_exists('city', $input)) {
                $addrFields[] = "city = ?";
                $addrParams[] = $input['city'];
            }
            if (array_key_exists('province', $input)) {
                $addrFields[] = "province = ?";
                $addrParams[] = $input['province'];
            }
            if (array_key_exists('region', $input)) {
                $addrFields[] = "region = ?";
                $addrParams[] = $input['region'];
            }

            if (!empty($addrFields)) {
                $addrFields[] = "updated_at = NOW()";
                $sqlAddr = "UPDATE addresses SET " . implode(', ', $addrFields) . " WHERE address_id = ?";
                $addrParams[] = $address_id;
                $stmtAddr = $pdo->prepare($sqlAddr);
                $stmtAddr->execute($addrParams);
            }
        } else {
            // Create new address if address fields are provided
            $hasAddressInput = array_key_exists('address_line', $input) || array_key_exists('address', $input) ||
                               array_key_exists('barangay', $input) || array_key_exists('city', $input) ||
                               array_key_exists('province', $input) || array_key_exists('region', $input);

            if ($hasAddressInput) {
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
        }

        // 3. Update MEMBERS table
        $memFields = [];
        $memParams = [];
        if ($address_id) {
            $memFields[] = "address_id = ?";
            $memParams[] = $address_id;
        }
        if (array_key_exists('occupation', $input)) {
            $memFields[] = "occupation = ?";
            $memParams[] = $input['occupation'];
        // Removed medical_history handling
        if (array_key_exists('emergency_contact_name', $input)) {
            $memFields[] = "emergency_contact_name = ?";
            $memParams[] = $input['emergency_contact_name'];
        }
        if (array_key_exists('emergency_contact_number', $input)) {
            $memFields[] = "emergency_contact_number = ?";
            $memParams[] = $input['emergency_contact_number'];
        }
        if (array_key_exists('parent_name', $input)) {
            $memFields[] = "parent_name = ?";
            $memParams[] = $input['parent_name'];
        }
        if (array_key_exists('parent_contact_number', $input) || array_key_exists('parent_contact', $input)) {
            $memFields[] = "parent_contact = ?";
            $memParams[] = $input['parent_contact_number'] ?? ($input['parent_contact'] ?? '');
        }

        if (!empty($memFields)) {
            $memFields[] = "updated_at = NOW()";
            $sqlMem = "UPDATE members SET " . implode(', ', $memFields) . " WHERE user_id = ? AND gym_id = ?";
            $memParams[] = $user_id;
            $memParams[] = $gym_id;
            $stmtMem = $pdo->prepare($sqlMem);
            $stmtMem->execute($memParams);
        }

        // 4. Log health metrics (Height, Weight) if provided
        $height = isset($input['height_cm']) ? (float)$input['height_cm'] : 0.0;
        $weight = isset($input['weight_kg']) ? (float)$input['weight_kg'] : 0.0;
        if ($height > 0 && $weight > 0) {
            // Get member_id
            $stmtGetMember = $pdo->prepare("SELECT member_id FROM members WHERE user_id = ? AND gym_id = ? LIMIT 1");
            $stmtGetMember->execute([$user_id, $gym_id]);
            $member_id = $stmtGetMember->fetchColumn();
            
            if ($member_id) {
                // Check if the latest metric record is identical to avoid duplicate inserts on simple profile updates
                $stmtLatest = $pdo->prepare("SELECT height_cm, weight_kg FROM member_health_metrics WHERE member_id = ? ORDER BY recorded_at DESC LIMIT 1");
                $stmtLatest->execute([$member_id]);
                $latest = $stmtLatest->fetch();
                
                if (!$latest || (float)$latest['height_cm'] !== $height || (float)$latest['weight_kg'] !== $weight) {
                    $stmtInsMetric = $pdo->prepare("INSERT INTO member_health_metrics (member_id, weight_kg, height_cm, recorded_at) VALUES (?, ?, ?, NOW())");
                    $stmtInsMetric->execute([$member_id, $weight, $height]);
                }
            }
        }
    }

    ob_end_clean();
    echo json_encode(['success' => true, 'message' => 'Profile updated successfully.']);
    exit;

} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    exit;
}
