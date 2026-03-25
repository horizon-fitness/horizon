<?php
header('Content-Type: application/json');
require_once '../db.php';
ob_start();

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $gym_id = trim($input['gym_id'] ?? '');

    if (empty($gym_id)) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Tenant code is required.']);
        exit;
    }

    // 1. Check if it's a tenant_code
    $stmtLookup = $pdo->prepare("SELECT gym_id, gym_name, tenant_code FROM gyms WHERE LOWER(tenant_code) = LOWER(?) LIMIT 1");
    $stmtLookup->execute([$gym_id]);
    $gym_data = $stmtLookup->fetch();

    if ($gym_data) {
        ob_end_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Valid Tenant: ' . $gym_data['gym_name'],
            'gym_id' => $gym_data['gym_id'],
            'gym_name' => $gym_data['gym_name']
        ]);
        exit;
    }

    // 2. Check if it's an invitation token
    $stmtInv = $pdo->prepare("SELECT invitation_id, gym_id FROM staff_invitations WHERE token = ? AND invitation_status = 'Pending' LIMIT 1");
    $stmtInv->execute([$gym_id]);
    $inv = $stmtInv->fetch();

    if ($inv) {
        $stmtT = $pdo->prepare("SELECT gym_name FROM gyms WHERE gym_id = ? LIMIT 1");
        $stmtT->execute([$inv['gym_id']]);
        $gym_name = $stmtT->fetchColumn();

        ob_end_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Valid Invitation for: ' . $gym_name,
            'gym_id' => $inv['gym_id'],
            'gym_name' => $gym_name
        ]);
        exit;
    }

    // 3. Check if it's a numeric gym_id (backward compatibility if needed)
    if (is_numeric($gym_id)) {
        $stmtT = $pdo->prepare("SELECT gym_name FROM gyms WHERE gym_id = ? LIMIT 1");
        $stmtT->execute([(int)$gym_id]);
        $gym_name = $stmtT->fetchColumn();
        if ($gym_name) {
            ob_end_clean();
            echo json_encode([
                'success' => true,
                'message' => 'Valid Gym: ' . $gym_name,
                'gym_id' => (int)$gym_id,
                'gym_name' => $gym_name
            ]);
            exit;
        }
    }

    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid Tenant Code or Invitation Token.']);

} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
