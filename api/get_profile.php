<?php
ob_start();
header('Content-Type: application/json; charset=UTF-8');

try {
    require_once '../db.php';

    $user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : (isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0);
    $tenant_code = isset($_GET['tenant_code']) ? trim((string)$_GET['tenant_code']) : (isset($_POST['tenant_code']) ? trim((string)$_POST['tenant_code']) : '');

    if ($user_id <= 0) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Valid User ID required.']);
        exit;
    }

    // 1. Find User
    $sqlUser = "SELECT * FROM users WHERE user_id = ? LIMIT 1";
    $stmtUser = $pdo->prepare($sqlUser);
    $stmtUser->execute([$user_id]);
    $user = $stmtUser->fetch();

    if (!$user) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'User not found.']);
        exit;
    }

    // 2. Resolve Gym Connection
    $isGlobalRequest = (empty($tenant_code) || $tenant_code === '000' || strtolower($tenant_code) === 'horizon');
    
    $sqlRole = "SELECT ur.*, r.role_name, g.gym_name, g.tenant_code as g_tenant_code,
                m.member_id, m.member_code, m.occupation, 
                addr.address_line AS member_address, addr.address_line, addr.barangay, addr.city, addr.province, addr.region, 
                m.emergency_contact_name, m.emergency_contact_number, m.medical_history, m.member_status,
                m.parent_name, m.parent_contact
                FROM user_roles ur
                JOIN roles r ON ur.role_id = r.role_id
                LEFT JOIN gyms g ON ur.gym_id = g.gym_id
                LEFT JOIN members m ON ur.user_id = m.user_id AND ur.gym_id = m.gym_id
                LEFT JOIN addresses addr ON m.address_id = addr.address_id
                WHERE ur.user_id = :uid";
    
    if (!$isGlobalRequest) {
        $sqlRole .= " AND (LOWER(ur.tenant_code) = LOWER(:t1) OR LOWER(g.tenant_code) = LOWER(:t2) OR r.role_name = 'Super Admin')";
    } else {
        $sqlRole .= " ORDER BY (CASE WHEN r.role_name = 'Super Admin' THEN 1 ELSE 2 END) ASC";
    }
    
    $sqlRole .= " LIMIT 1";

    $stmtRole = $pdo->prepare($sqlRole);
    $params = ['uid' => $user_id];
    if (!$isGlobalRequest) {
        $params['t1'] = $tenant_code;
        $params['t2'] = $tenant_code;
    }
    $stmtRole->execute($params);
    $roleData = $stmtRole->fetch();

    // 3. Branding (Optional but helpful for sync)
    $branding = null;
    if ($roleData && !empty($roleData['gym_id'])) {
        $stmtG = $pdo->prepare("SELECT gym_name, profile_picture as logo_path, owner_user_id FROM gyms WHERE gym_id = ? LIMIT 1");
        $stmtG->execute([$roleData['gym_id']]);
        $gym = $stmtG->fetch(PDO::FETCH_ASSOC);

        if ($gym) {
            $stmtS = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = ? OR user_id = 0 ORDER BY user_id ASC");
            $stmtS->execute([$gym['owner_user_id']]);
            $settings = $stmtS->fetchAll(PDO::FETCH_KEY_PAIR);

            $branding = [
                'gym_id' => (int)$roleData['gym_id'],
                'tenant_code' => (string)($roleData['tenant_code'] ?? ($roleData['g_tenant_code'] ?? '000')),
                'page_title' => (string)$gym['gym_name'],
                'logo_path' => $gym['logo_path'] ? (string)$gym['logo_path'] : null,
                'theme_color' => $settings['theme_color'] ?? '#7f13ec',
                'bg_color' => $settings['bg_color'] ?? '#050505'
            ];
        }
    }

    $response = [
        'success' => true,
        'user' => [
            'user_id' => (int)$user['user_id'],
            'username' => (string)$user['username'],
            'email' => (string)$user['email'],
            'first_name' => (string)$user['first_name'],
            'middle_name' => (string)($user['middle_name'] ?? ''),
            'last_name' => (string)$user['last_name'],
            'contact_number' => (string)($user['contact_number'] ?? ''),
            'profile_picture' => $user['profile_picture'] ? (string)$user['profile_picture'] : null,
            'is_verified' => (int)($user['is_verified'] ?? 1),
            'tenant_id' => (string)($roleData['tenant_code'] ?? ($roleData['g_tenant_code'] ?? '000')),
            'gym_name' => (string)($roleData['gym_name'] ?? 'Horizon'),
            'gym_id' => (int)($roleData['gym_id'] ?? 0),
            'role' => (string)($roleData['role_name'] ?? 'Member'),
            'member_id' => (int)($roleData['member_id'] ?? 0),
            'member_code' => (string)($roleData['member_code'] ?? ''),
            'address' => (string)($roleData['member_address'] ?? ($user['address'] ?? '')),
            'address_line' => (string)($roleData['address_line'] ?? ($user['address'] ?? '')),
            'barangay' => (string)($roleData['barangay'] ?? ''),
            'city' => (string)($roleData['city'] ?? ''),
            'province' => (string)($roleData['province'] ?? ''),
            'region' => (string)($roleData['region'] ?? ''),
            'birth_date' => (string)($user['birth_date'] ?? ''),
            'sex' => (string)($user['sex'] ?? ''),
            'occupation' => (string)($roleData['occupation'] ?? ''),
            'medical_history' => (string)($roleData['medical_history'] ?? ''),
            'emergency_contact_name' => (string)($roleData['emergency_contact_name'] ?? ''),
            'emergency_contact_number' => (string)($roleData['emergency_contact_number'] ?? ''),
            'parent_name' => (string)($roleData['parent_name'] ?? ''),
            'parent_contact_number' => (string)($roleData['parent_contact'] ?? ''),
            'member_status' => (string)($roleData['member_status'] ?? 'Active')
        ],
        'branding' => $branding
    ];

    ob_end_clean();
    echo json_encode($response);
    exit;

} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    exit;
}
