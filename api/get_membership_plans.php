<?php
require_once '../db.php';
header('Content-Type: application/json');

/**
 * Endpoint to fetch active membership plans for a given gym.
 * Usage: get_membership_plans.php?gym_id=1
 */

$gym_id = $_GET['gym_id'] ?? null;

if (!$gym_id) {
    echo json_encode(['success' => false, 'message' => 'Gym ID is required']);
    exit;
}

try {
    // Fetch active plans, ordered by sort_order and price
    $stmt = $pdo->prepare("SELECT membership_plan_id, plan_name, price, duration_value, billing_cycle_text, featured_badge_text, description, features 
                           FROM membership_plans 
                           WHERE gym_id = ? AND is_active = 1 
                           ORDER BY sort_order ASC, price ASC");
    $stmt->execute([$gym_id]);
    $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Convert price to float for JSON consistency
    foreach ($plans as &$plan) {
        $plan['price'] = (float)$plan['price'];
        $plan['duration_value'] = (int)$plan['duration_value'];
    }
    
    echo json_encode($plans);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
