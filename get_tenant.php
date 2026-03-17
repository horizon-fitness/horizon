// save this as get_tenant.php on your server
<?php
require_once 'db.php';
$slug = $_GET['gym'] ?? '';

$stmt = $pdo->prepare("SELECT tp.*, g.gym_name, g.tenant_code FROM tenant_pages tp JOIN gyms g ON tp.gym_id = g.gym_id WHERE tp.page_slug = ? LIMIT 1");
$stmt->execute([$slug]);
$result = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$result) {
    // Return Default "Horizon Systems" Branding if DB is empty or no slug matches
    $result = [
        'gym_id' => 1,
        'page_slug' => 'horizon',
        'page_title' => 'Horizon Systems',
        'gym_name' => 'Horizon Systems',
        'tenant_code' => '000',
        'logo_path' => 'assets/default_logo.png',
        'theme_color' => '#1a73e8',
        'bg_color' => '#ffffff',
        'font_family' => 'Inter',
        'about_text' => 'Welcome to the Horizon Systems official app. Your fitness journey starts here.',
        'app_download_link' => 'https://horizonfitnesscorp.gt.tc/download.php'
    ];
}

header('Content-Type: application/json');
echo json_encode($result);
?>
