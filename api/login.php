<?php
header('Content-Type: application/json');
require_once '../db.php';

$input = json_decode(file_get_contents('php://input'), true);
$username = trim($input['username'] ?? '');
$password = $input['password'] ?? '';
$tenant_id = trim($input['tenant_id'] ?? '');

if (empty($username) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Username/Email and password are required.']);
    exit;
}

try {
    // 1. Build Query with Tenant Context Synergy
    $sql = "SELECT u.*, ur.role_id, r.role_name, ur.gym_id, g.tenant_code, g.gym_name 
            FROM users u 
            JOIN user_roles ur ON u.user_id = ur.user_id 
            JOIN roles r ON ur.role_id = r.role_id 
            LEFT JOIN gyms g ON ur.gym_id = g.gym_id
            WHERE (u.username = :u1 OR u.email = :u2)";
    
    $params = [':u1' => $username, ':u2' => $username];
    
    if (!empty($tenant_id)) {
        // Allow Global Access for Management Roles, otherwise enforce tenant context
        $sql .= " AND (g.tenant_code = :t1 OR g.gym_id = :t2 OR r.role_name IN ('Superadmin', 'Admin', 'Coach', 'Staff', 'Tenant'))";
        $params[':t1'] = $tenant_id;
        $params[':t2'] = $tenant_id;
    }
    
    $sql .= " LIMIT 1";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $user = $stmt->fetch();

    if (!$user) {
        // Debug: Check if user exists in users table at least
        $stmtCheck = $pdo->prepare("SELECT user_id FROM users WHERE username = ? OR email = ? LIMIT 1");
        $stmtCheck->execute([$username, $username]);
        $exists = $stmtCheck->fetch();
        
        if ($exists) {
            echo json_encode(['success' => false, 'message' => 'User found but has no assigned role or tenant access.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Account does not exist.']);
        }
        exit;
    }

    if (password_verify($password, $user['password_hash'])) {
        if (!$user['is_verified']) {
            echo json_encode(['success' => false, 'message' => 'Please verify your account first.', 'unverified' => true, 'user_id' => $user['user_id']]);
            exit;
        }

        if (!$user['is_active']) {
            echo json_encode(['success' => false, 'message' => 'Account is suspended.']);
            exit;
        }

        // Fetch Tenant Branding for Mobile App Synergy
        $branding = null;
        if ($user['gym_id']) {
            $stmtPage = $pdo->prepare("SELECT page_title, logo_path, theme_color, bg_color, font_family FROM tenant_pages WHERE gym_id = ? LIMIT 1");
            $stmtPage->execute([$user['gym_id']]);
            $branding = $stmtPage->fetch(PDO::FETCH_ASSOC);
        }

        echo json_encode([
            'success' => true,
            'user' => [
                'user_id' => $user['user_id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'first_name' => $user['first_name'],
                'last_name' => $user['last_name'],
                'role' => $user['role_name'],
                'gym_id' => $user['gym_id'],
                'tenant_id' => $user['tenant_code'],
                'gym_name' => $user['gym_name']
            ],
            'branding' => $branding
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid credentials.']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
