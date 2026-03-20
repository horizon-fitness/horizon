<?php
// API endpoint for fetching tenant branding/customization
require_once '../db.php';
$slug = $_GET['gym'] ?? 'horizon';

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
        'banner_image' => 'https://images.unsplash.com/photo-1534438327276-14e5300c3a48?q=80&w=2070&auto=format&fit=crop',
        'theme_color' => '#8c2bee',
        'bg_color' => '#0a090d',
        'font_family' => 'Inter',
        'about_text' => 'Welcome to the Horizon Systems official app. Your fitness journey starts here.',
        'app_download_link' => 'https://horizonfitnesscorp.gt.tc/download.php'
    ];
}

header('Content-Type: application/json');
echo json_encode($result);
?>
