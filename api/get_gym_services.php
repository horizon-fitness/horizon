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
            catalog_service_id as id, 
            service_name as name, 
            service_category as category,
            price, 
            description
        FROM service_catalog
        WHERE gym_id = ? AND is_active = 1
        ORDER BY service_name ASC
    ");
    $stmt->execute([$gym_id]);
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($services);

} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
