<?php
/**
 * api/get_gym_services.php
 * Provides a list of available services for a specific gym to the mobile app.
 */
header('Content-Type: application/json; charset=UTF-8');
require_once '../db.php';

$gym_id = isset($_GET['gym_id']) ? (int)$_GET['gym_id'] : 0;

if ($gym_id <= 0) {
    echo json_encode([]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            gs.gym_service_id, 
            COALESCE(gs.custom_service_name, sc.service_name) as custom_service_name, 
            gs.price, 
            gs.duration_minutes
        FROM gym_services gs
        JOIN service_catalog sc ON gs.catalog_service_id = sc.catalog_service_id
        WHERE gs.gym_id = ? AND gs.is_active = 1
    ");
    $stmt->execute([$gym_id]);
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($services);

} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
