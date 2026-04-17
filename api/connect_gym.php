<?php
/**
 * api/connect_gym.php
 * Dedicated Mobile API for QR/Code Gym Connection
 * Reads from: gyms + system_settings (NOT tenant_pages)
 */
ob_start();
header('Content-Type: application/json; charset=UTF-8');

try {
    require_once '../db.php';

    // Accept POST body or GET params
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $slug = trim($input['gym'] ?? $_GET['gym'] ?? $_GET['tenant_code'] ?? '');
    $cleanSlug = str_replace('-', '', $slug);

    if (empty($slug) || strtolower($slug) === 'horizon') {
        ob_end_clean();
        echo json_encode([
            'success'      => true,
            'gym_id'       => 1,
            'gym_name'     => 'Horizon Systems',
            'tenant_code'  => '000',
            'page_slug'    => 'horizon',
            'logo_path'    => null,
            'theme_color'  => '#8c2bee',
            'is_default'   => true
        ]);
        exit;
    }

    // Search by tenant_code or gym_name
    $stmt = $pdo->prepare("
        SELECT gym_id, gym_name, tenant_code, owner_user_id
        FROM gyms
        WHERE LOWER(REPLACE(tenant_code, '-', '')) = LOWER(?)
           OR LOWER(gym_name) = LOWER(?)
        LIMIT 1
    ");
    $stmt->execute([$cleanSlug, $slug]);
    $gym = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fall back: search by page_slug in system_settings
    if (!$gym) {
        $stmtSlug = $pdo->prepare("
            SELECT g.gym_id, g.gym_name, g.tenant_code, g.owner_user_id
            FROM system_settings ss
            JOIN gyms g ON ss.user_id = g.owner_user_id
            WHERE ss.setting_key = 'page_slug' AND LOWER(ss.setting_value) = LOWER(?)
            LIMIT 1
        ");
        $stmtSlug->execute([$slug]);
        $gym = $stmtSlug->fetch(PDO::FETCH_ASSOC);
    }

    if (!$gym) {
        http_response_code(404);
        ob_end_clean();
        echo json_encode([
            'success' => false,
            'message' => "Could not find gym: '$slug'. Please check your Tenant Code."
        ]);
        exit;
    }

    // Fetch branding from system_settings
    $stmtB = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = ?");
    $stmtB->execute([$gym['owner_user_id']]);
    $b = $stmtB->fetchAll(PDO::FETCH_KEY_PAIR);

    ob_end_clean();
    echo json_encode([
        'success'     => true,
        'is_default'  => false,
        'gym_id'      => (int) $gym['gym_id'],
        'gym_name'    => $gym['gym_name'],
        'tenant_code' => $gym['tenant_code'],
        'page_slug'   => $b['page_slug'] ?? strtolower(preg_replace('/[^a-z0-9]/i', '', $gym['gym_name'])),
        'logo_path'   => $b['system_logo'] ?? null,
        'theme_color' => $b['theme_color'] ?? '#8c2bee',
        'bg_color'    => $b['bg_color'] ?? '#0a090d',
        'font_family' => $b['font_family'] ?? 'Inter'
    ]);
    exit;

} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    exit;
} catch (Error $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Fatal: ' . $e->getMessage()]);
    exit;
}
?>
