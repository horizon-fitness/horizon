<?php
ob_start(); // Buffer all output to prevent stray whitespace/warnings from corrupting JSON
header('Content-Type: application/json; charset=UTF-8');

try {
    require_once '../db.php';

    $input = json_decode(file_get_contents('php://input'), true);
    $username = isset($input['username']) ? trim((string)$input['username']) : '';
    $password = isset($input['password']) ? (string)$input['password'] : '';
    $tenant_code = isset($input['tenant_code']) ? trim((string)$input['tenant_code']) : (isset($input['tenant_id']) ? trim((string)$input['tenant_id']) : '');

    if (empty($username) || empty($password)) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Credentials required.']);
        exit;
    }

    // 1. Find User First (Case-Insensitive)
    // We fetch all potential gyms/roles for this user to decide the best error message
    $sqlUser = "SELECT * FROM users WHERE LOWER(username) = LOWER(?) OR LOWER(email) = LOWER(?) LIMIT 1";
    $stmtUser = $pdo->prepare($sqlUser);
    $stmtUser->execute([$username, $username]);
    $user = $stmtUser->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Invalid credentials.']);
        exit;
    }

    // 2. User exists and Password is correct. Now check Gym Access.
    // If tenant_code is empty or '000', we treat it as Horizon Global
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
    
    // If specific tenant provided, prioritize that connection
    if (!$isGlobalRequest) {
        $sqlRole .= " AND (LOWER(ur.tenant_code) = LOWER(:t1) OR LOWER(g.tenant_code) = LOWER(:t2) OR r.role_name = 'Super Admin')";
    } else {
        // If global request, just pick the first available role (usually the one they registered with)
        // or prioritize Super Admin
        $sqlRole .= " ORDER BY (CASE WHEN r.role_name = 'Super Admin' THEN 1 ELSE 2 END) ASC";
    }
    
    $sqlRole .= " LIMIT 1";

    $stmtRole = $pdo->prepare($sqlRole);
    $params = ['uid' => $user['user_id']];
    if (!$isGlobalRequest) {
        $params['t1'] = $tenant_code;
        $params['t2'] = $tenant_code;
    }
    $stmtRole->execute($params);
    $roleData = $stmtRole->fetch();

    if (!$roleData) {
        // User exists, password matches, but they are NOT in THIS gym.
        // Let's find out which gym they ARE in to help them.
        $stmtWhere = $pdo->prepare("SELECT g.gym_name, ur.tenant_code FROM user_roles ur JOIN gyms g ON ur.gym_id = g.gym_id WHERE ur.user_id = ? LIMIT 1");
        $stmtWhere->execute([$user['user_id']]);
        $where = $stmtWhere->fetch();
        
        $gymMsg = $where ? "Your account is registered under '{$where['gym_name']}'. Please switch to that gym first." : "Account found, but it is not linked to any gym.";
        
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => $gymMsg]);
        exit;
    }

    // 3. Validate Role for Mobile
    $roleName = (string)($roleData['role_name'] ?? 'Member');
    if (!in_array(strtolower($roleName), ['member', 'customer', 'super admin'])) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Only members are allowed on Mobile. Staff must use the web portal.']);
        exit;
    }

    // 4. Build Detailed Response
    $branding = null;
    if (!empty($roleData['gym_id'])) {
        $stmtBranding = $pdo->prepare("SELECT * FROM tenant_pages WHERE gym_id = ? LIMIT 1");
        $stmtBranding->execute([$roleData['gym_id']]);
        $branding = $stmtBranding->fetch(PDO::FETCH_ASSOC);
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
            'role' => $roleName,
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
        'branding' => $branding ? [
            'gym_id' => (int)$branding['gym_id'],
            'tenant_code' => (string)($roleData['tenant_code'] ?? ($roleData['g_tenant_code'] ?? '000')),
            'page_title' => (string)$branding['page_title'],
            'logo_path' => $branding['logo_path'] ? (string)$branding['logo_path'] : null,
            'theme_color' => (string)$branding['theme_color'],
            'bg_color' => (string)($branding['bg_color'] ?? '#050505')
        ] : [
            'gym_id' => (int)($roleData['gym_id'] ?? 0),
            'tenant_code' => (string)($roleData['tenant_code'] ?? ($roleData['g_tenant_code'] ?? '000')),
            'page_title' => (string)($roleData['gym_name'] ?? 'Horizon'),
            'theme_color' => '#7f13ec',
            'bg_color' => '#050505'
        ]
    ];

    ob_end_clean();
    echo json_encode($response);
    exit;

} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    exit;
}
