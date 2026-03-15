<?php
header('Content-Type: application/json');
require_once '../db.php';

$input = json_decode(file_get_contents('php://input'), true);
$username = $input['username'] ?? '';
$password = $input['password'] ?? '';

if (empty($username) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Username and password are required.']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT u.*, ur.role_id, r.role_name, ur.gym_id 
                           FROM users u 
                           JOIN user_roles ur ON u.user_id = ur.user_id 
                           JOIN roles r ON ur.role_id = r.role_id 
                           WHERE u.username = ? OR u.email = ? LIMIT 1");
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
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
                'gym_id' => $user['gym_id']
            ],
            'branding' => $branding
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid credentials.']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
