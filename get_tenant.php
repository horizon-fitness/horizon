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
            'logo_path'    => 'assests/horizon logo.png', // Explicit system logo
            'theme_color'  => '#8c2bee',
            'bg_color'     => '#0a090d',
            'font_family'  => 'Inter',
            'is_default'   => true
        ]);
        exit;
    }

    // Find the gym by tenant_code, gym_name, or page_slug (from system_settings)
    // Joined with latest client_subscription to check for plan payment/standing
    $query = "
        SELECT 
            g.gym_id,
            g.gym_name,
            g.tenant_code,
            g.owner_user_id,
            g.profile_picture,
            g.status as gym_status,
            cs.subscription_status,
            cs.payment_status,
            cs.website_plan_id,
            gd.opening_time,
            gd.closing_time
        FROM gyms g
        LEFT JOIN gym_details gd ON g.gym_id = gd.gym_id
        LEFT JOIN (
            SELECT cs1.gym_id, cs1.subscription_status, cs1.payment_status, cs1.website_plan_id
            FROM client_subscriptions cs1
            INNER JOIN (
                SELECT gym_id, MAX(created_at) as max_created
                FROM client_subscriptions
                GROUP BY gym_id
            ) cs2 ON cs1.gym_id = cs2.gym_id AND cs1.created_at = cs2.max_created
        ) cs ON g.gym_id = cs.gym_id
        WHERE (LOWER(REPLACE(g.tenant_code, '-', '')) = LOWER(?) OR LOWER(g.gym_name) = LOWER(?))
    ";
    
    // Check system_settings if not found by primary info (Handling page_slug fallback)
    $stmt = $pdo->prepare($query);
    $stmt->execute([$cleanSlug, $slug]);
    $gym = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$gym) {
        // Try page_slug from system_settings
        $stmtSlug = $pdo->prepare("
            SELECT g.gym_id 
            FROM system_settings ss
            JOIN gyms g ON ss.user_id = g.owner_user_id
            WHERE ss.setting_key = 'page_slug' AND LOWER(ss.setting_value) = LOWER(?)
            LIMIT 1
        ");
        $stmtSlug->execute([$slug]);
        $slugGymId = $stmtSlug->fetchColumn();

        if ($slugGymId) {
            $stmt = $pdo->prepare($query . " OR g.gym_id = ?");
            $stmt->execute([$cleanSlug, $slug, $slugGymId]);
            $gym = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }

    if (!$gym) {
        // FAIL: If gym not found, return error message
        ob_end_clean();
        echo json_encode([
            'success'      => false,
            'message'      => "This QR or code is invalid."
        ]);
        exit;
    }

    // Bypass restrictions for Horizon Default (Tenant Code '000')
    if (trim($gym['tenant_code']) !== '000') {
        
        // 1. Account Standing Check (Suspended/Deactivated/Pending/Inactive)
        $restrictedStatuses = ['suspended', 'deactivated', 'deleted', 'pending', 'inactive'];
        if (in_array(strtolower($gym['gym_status']), $restrictedStatuses)) {
            $msg = "This gym (" . $gym['gym_name'] . ") is currently " . $gym['gym_status'] . ".";
            if (strtolower($gym['gym_status']) === 'pending' || strtolower($gym['gym_status']) === 'inactive') {
                $msg = "This gym's portal is currently restricted or awaiting activation.";
            }
            
            ob_end_clean();
            echo json_encode([
                'success'      => false,
                'is_suspended' => true,
                'status'       => $gym['gym_status'],
                'message'      => $msg . " Please try again another time."
            ]);
            exit;
        }

        // 2. Plan Standing Check (Unpaid/Expired)
        $subStatus = strtolower($gym['subscription_status'] ?? '');
        $payStatus = strtolower($gym['payment_status'] ?? '');

        // If no subscription record EXISTS or it's not Active + Paid
        if (empty($subStatus) || $subStatus !== 'active' || $payStatus !== 'paid') {
            $msg = "Connection restricted: ";
            
            if (empty($subStatus)) {
                $msg .= "This gym has not yet activated its subscription plan.";
            } elseif ($subStatus === 'pending approval') {
                $msg .= "Payment verification is in progress.";
            } elseif ($subStatus === 'expired') {
                $msg .= "The gym's subscription plan has expired.";
            } else {
                $msg .= "Account standing is " . ($gym['subscription_status'] ?: 'Inactive') . ".";
            }

            ob_end_clean();
            echo json_encode([
                'success'      => false,
                'is_suspended' => true,
                'status'       => 'unpaid',
                'message'      => $msg . " Please try again another time."
            ]);
            exit;
        }
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
        'icon_color'  => $branding['secondary_color'] ?? '#a1a1aa',
        'text_color'  => $branding['text_color'] ?? '#d1d5db',
        'bg_color'    => $branding['bg_color'] ?? '#0a090d',
        'font_family' => $branding['font_family'] ?? 'Inter',
        'card_color'  => $branding['card_color'] ?? '#141216',
        'auto_card_theme' => $branding['auto_card_theme'] ?? '1',
        'opening_time' => $gym['opening_time'] ?? '07:00:00',
        'closing_time' => $gym['closing_time'] ?? '21:00:00'
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
