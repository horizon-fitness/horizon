// save this as get_tenant.php on your server
<?php
require_once 'db.php';
$slug = $_GET['gym'] ?? '';
$stmt = $pdo->prepare("SELECT tp.*, g.gym_name, g.tenant_code FROM tenant_pages tp JOIN gyms g ON tp.gym_id = g.gym_id WHERE tp.page_slug = ? LIMIT 1");
$stmt->execute([$slug]);
echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
?>