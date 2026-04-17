<?php
/**
 * get_tenant.php
 * Mobile Branding & Gym Connection API
 * Reads from: gyms + system_settings (NOT tenant_pages which doesn't exist)
 */
ob_start();
header('Content-Type: application/json; charset=UTF-8');

try {
    require_once 'db.php';

    // Accept input from ?gym= or ?tenant_code=
    $slug = trim($_GET['gym'] ?? $_GET['tenant_code'] ?? '');
    $cleanSlug = str_replace('-', '', $slug);

    // Default "Horizon Systems" branding if no slug provided
    if (empty($slug) || strtolower($slug) === 'horizon') {
        ob_end_clean();
        echo json_encode([
            'success'      => true,
            'gym_id'       => 1,
            'page_slug'    => 'horizon',
            'gym_name'     => 'Horizon Systems',
            'tenant_code'  => '000',
            'logo_path'    => null,
            'theme_color'  => '#8c2bee',
            'bg_color'     => '#0a090d',
            'font_family'  => 'Inter'
        ]);
        exit;
    }

    // Find the gym by tenant_code, gym_name, or page_slug (from system_settings)
    $stmt = $pdo->prepare("
        SELECT 
            g.gym_id,
            g.gym_name,
            g.tenant_code,
            g.owner_user_id,
            g.profile_picture
        FROM gyms g
        WHERE LOWER(REPLACE(g.tenant_code, '-', '')) = LOWER(?)
           OR LOWER(g.gym_name) = LOWER(?)
        LIMIT 1
    ");
    $stmt->execute([$cleanSlug, $slug]);
    $gym = $stmt->fetch(PDO::FETCH_ASSOC);

    // If not found by code/name, try page_slug from system_settings
    if (!$gym) {
        $stmtSlug = $pdo->prepare("
            SELECT g.gym_id, g.gym_name, g.tenant_code, g.owner_user_id, g.profile_picture
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
            'message' => "Gym '$slug' not found. Please verify your Tenant Code or Gym Name."
        ]);
        exit;
    }

    // Fetch branding from system_settings using owner_user_id
    $stmtBranding = $pdo->prepare(
        "SELECT setting_key, setting_value FROM system_settings WHERE user_id = ?"
    );
    $stmtBranding->execute([$gym['owner_user_id']]);
    $branding = $stmtBranding->fetchAll(PDO::FETCH_KEY_PAIR);

    ob_end_clean();
    echo json_encode([
        'success'     => true,
        'gym_id'      => (int) $gym['gym_id'],
        'gym_name'    => $gym['gym_name'],
        'tenant_code' => $gym['tenant_code'],
        'page_slug'   => $branding['page_slug'] ?? strtolower(preg_replace('/[^a-z0-9]/i', '', $gym['gym_name'])),
        'logo_path'   => $branding['system_logo'] ?? $gym['profile_picture'] ?? null,
        'theme_color' => $branding['theme_color'] ?? '#8c2bee',
        'bg_color'    => $branding['bg_color'] ?? '#0a090d',
        'font_family' => $branding['font_family'] ?? 'Inter'
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
