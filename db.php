<?php
date_default_timezone_set('Asia/Manila');
$host = 'sql202.infinityfree.com';
$db = 'if0_41368854_horizon_db';
$user = 'if0_41368854';
$pass = 'T2TqrnlHhRKilEn';
$charset = 'utf8mb4';
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];
try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'DB Connection Failed']);
    exit;
}

// Migration: Ensure website_plans has badge_text column for custom labeling
try {
    $resPlan = $pdo->query("SHOW COLUMNS FROM website_plans LIKE 'badge_text'");
    if (!$resPlan->fetch()) {
        $pdo->exec("ALTER TABLE website_plans ADD COLUMN badge_text VARCHAR(50) DEFAULT NULL AFTER billing_cycle");
    }
    // Migration: Ensure website_plans has sort_order column for manual sequencing
    $resSort = $pdo->query("SHOW COLUMNS FROM website_plans LIKE 'sort_order'");
    if (!$resSort->fetch()) {
        $pdo->exec("ALTER TABLE website_plans ADD COLUMN sort_order INT DEFAULT 0 AFTER website_plan_id");
    }
} catch (Exception $e) {
    // Fail silently to avoid breaking the app if user lacks ALTER permissions
}
