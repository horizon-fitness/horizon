<?php
header('Content-Type: application/json');
require_once '../db.php';

$input = json_decode(file_get_contents('php://input'), true);
$username = trim($input['username'] ?? '');
$password = $input['password'] ?? '';

if (empty($username) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Username and password required.']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT u.*, ur.gym_id, r.role_name, g.tenant_code, g.gym_name 
                            FROM users u 
                            JOIN user_roles ur ON u.user_id = ur.user_id 
                            JOIN roles r ON ur.role_id = r.role_id 
                            LEFT JOIN gyms g ON ur.gym_id = g.gym_id
                            WHERE u.username = ? OR u.email = ? LIMIT 1");
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        if (!$user['is_verified']) {
            echo json_encode(['success' => false, 'message' => 'Unverified', 'unverified' => true, 'user_id' => $user['user_id']]);
            exit;
        }

        $branding = null;
        if ($user['gym_id']) {
            $stmtP = $pdo->prepare("SELECT * FROM tenant_pages WHERE gym_id = ? LIMIT 1");
            $stmtP->execute([$user['gym_id']]);
            $branding = $stmtP->fetch(PDO::FETCH_ASSOC);
        }

        echo json_encode([
            'success' => true,
            'user' => [
                'user_id' => (int)$user['user_id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'password_hash' => $user['password_hash'],
                'first_name' => $user['first_name'],
                'middle_name' => $user['middle_name'] ?? '',
                'last_name' => $user['last_name'],
                'contact_number' => $user['contact_number'] ?? '',
                'profile_picture' => $user['profile_picture'] ?? null,
                'is_verified' => 1,
                'is_active' => 1,
                'created_at' => $user['created_at'] ?? date('Y-m-d H:i:s'),
                'updated_at' => $user['updated_at'] ?? date('Y-m-d H:i:s'),
                'tenant_id' => $user['tenant_code'] ?? '000',
                'gym_name' => $user['gym_name'] ?? 'Horizon',
                'gym_id' => (int)($user['gym_id'] ?? 0)
            ],
            'branding' => $branding ?: [
                'page_id' => 0,
                'gym_id' => (int)($user['gym_id'] ?? 0),
                'tenant_code' => $user['tenant_code'] ?? '000',
                'page_slug' => 'default',
                'page_title' => $user['gym_name'] ?? 'Horizon',
                'theme_color' => '#7f13ec',
                'bg_color' => '#050505',
                'logo_path' => null
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid credentials.']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
