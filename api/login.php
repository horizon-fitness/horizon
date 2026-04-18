<?php
ob_start(); // Buffer all output to prevent stray whitespace/warnings from corrupting JSON
header('Content-Type: application/json; charset=UTF-8');

try {
    require_once '../db.php';

    $input = json_decode(file_get_contents('php://input'), true);
    $username = isset($input['username']) ? trim((string)$input['username']) : '';
    $password = isset($input['password']) ? (string)$input['password'] : '';
    $tenant_code = isset($input['tenant_code']) ? (string)$input['tenant_code'] : (isset($input['tenant_id']) ? (string)$input['tenant_id'] : '');

    if (empty($username) || empty($password)) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Credentials required.']);
        exit;
    }

    // Capture User with Role and Gym synergy - Filtered by tenant_code for strict multi-tenancy
    // Relaxed check: Allow login if tenant is '000', empty, or if user is Super Admin
    $sql = "SELECT u.*, ur.gym_id, r.role_name, ur.tenant_code AS ur_tenant_code, g.tenant_code AS g_tenant_code, g.gym_name,
            m.member_id, m.member_code, m.occupation, addr.address_line AS member_address, m.emergency_contact_name, m.emergency_contact_number, m.medical_history, m.member_status
            FROM users u 
            JOIN user_roles ur ON u.user_id = ur.user_id 
            JOIN roles r ON ur.role_id = r.role_id 
            LEFT JOIN gyms g ON ur.gym_id = g.gym_id
            LEFT JOIN members m ON u.user_id = m.user_id
            LEFT JOIN addresses addr ON m.address_id = addr.address_id
            WHERE (u.username = :user1 OR u.email = :user2) 
            AND (ur.tenant_code = :tenant1 OR :tenant2 = '' OR :tenant3 = '000' OR r.role_name = 'Super Admin') 
            LIMIT 1";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'user1' => $username, 
        'user2' => $username, 
        'tenant1' => $tenant_code, 
        'tenant2' => $tenant_code, 
        'tenant3' => $tenant_code
    ]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        // Strict Role Restriction for Mobile: Only Member/Customer allowed
        $roleName = (string)($user['role_name'] ?? 'Member');
        if (!in_array(strtolower($roleName), ['member', 'customer'])) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Only members are allowed on Mobile. Staff/Admin must use the Web portal.']);
            exit;
        }

        // Verification is now handled strictly during the registration process (OTP-first).
        // Any existing accounts with is_verified = 0 from the old system will be granted access 
        // to prevent them from being locked out of the new workflow.

        $branding = null;
        if (!empty($user['gym_id'])) {
            try {
                $stmtBranding = $pdo->prepare("SELECT * FROM tenant_pages WHERE gym_id = ? LIMIT 1");
                $stmtBranding->execute([$user['gym_id']]);
                $branding = $stmtBranding->fetch(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                // Silently fallback if tenant_pages is missing/corrupted
                $branding = null;
            }
        }

        $response = [
            'success' => true,
            'user' => [
                'user_id' => (int)$user['user_id'],
                'username' => (string)$user['username'],
                'email' => (string)$user['email'],
                'password_hash' => (string)$user['password_hash'],
                'first_name' => (string)$user['first_name'],
                'middle_name' => (string)($user['middle_name'] ?? ''),
                'last_name' => (string)$user['last_name'],
                'contact_number' => (string)($user['contact_number'] ?? ''),
                'profile_picture' => $user['profile_picture'] ? (string)$user['profile_picture'] : null,
                'is_verified' => (int)($user['is_verified'] ?? 1),
                'is_active' => (int)($user['is_active'] ?? 1),
                'created_at' => (string)($user['created_at'] ?? date('Y-m-d H:i:s')),
                'updated_at' => (string)($user['updated_at'] ?? date('Y-m-d H:i:s')),
                'tenant_id' => (string)($user['ur_tenant_code'] ?? ($user['g_tenant_code'] ?? '000')),
                'gym_name' => (string)($user['gym_name'] ?? 'Horizon'),
                'gym_id' => (int)($user['gym_id'] ?? 0),
                'role' => (string)($user['role_name'] ?? 'Member'),
                // Member-specific profile details
                'member_id' => (int)($user['member_id'] ?? 0),
                'member_code' => (string)($user['member_code'] ?? ''),
                'address' => (string)($user['member_address'] ?? ($user['address'] ?? '')),
                'birth_date' => (string)($user['birth_date'] ?? ''),
                'sex' => (string)($user['sex'] ?? ''),
                'occupation' => (string)($user['occupation'] ?? ''),
                'medical_history' => (string)($user['medical_history'] ?? ''),
                'emergency_contact_name' => (string)($user['emergency_contact_name'] ?? ''),
                'emergency_contact_number' => (string)($user['emergency_contact_number'] ?? ''),
                'member_status' => (string)($user['member_status'] ?? 'Active')
            ],
            'branding' => $branding ? [
                'page_id' => (int)$branding['page_id'],
                'gym_id' => (int)$branding['gym_id'],
                'tenant_code' => (string)($user['ur_tenant_code'] ?? ($user['g_tenant_code'] ?? '000')),
                'page_slug' => (string)$branding['page_slug'],
                'page_title' => (string)$branding['page_title'],
                'logo_path' => $branding['logo_path'] ? (string)$branding['logo_path'] : null,
                'theme_color' => (string)$branding['theme_color'],
                'bg_color' => (string)($branding['bg_color'] ?? '#050505'),
                'font_family' => (string)($branding['font_family'] ?? 'Inter')
            ] : [
                'page_id' => 0,
                'gym_id' => (int)($user['gym_id'] ?? 0),
                'tenant_code' => (string)($user['ur_tenant_code'] ?? ($user['g_tenant_code'] ?? '000')),
                'page_slug' => 'default',
                'page_title' => (string)($user['gym_name'] ?? 'Horizon'),
                'theme_color' => '#7f13ec',
                'bg_color' => '#050505',
                'logo_path' => null
            ]
        ];

        ob_end_clean(); // Discard any warnings/whitespace that occurred during execution
        echo json_encode($response);
        exit;
    } else {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Invalid credentials.']);
        exit;
    }
} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    exit;
}
