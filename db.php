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

// -------------------------------------------------------------------------
// MOBILE API MIGRATIONS (Auto-Healing Schema)
// Sinisiguro nito na laging nandiyan ang tables na kailangan ng mobile app.
// -------------------------------------------------------------------------
try {
    // Roles system
    $pdo->exec("CREATE TABLE IF NOT EXISTS roles (role_id INT AUTO_INCREMENT PRIMARY KEY, role_name VARCHAR(50) NOT NULL UNIQUE)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_roles (user_role_id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, role_id INT NOT NULL, gym_id INT DEFAULT NULL, tenant_code VARCHAR(50) DEFAULT NULL, role_status VARCHAR(20) DEFAULT 'Active', assigned_at DATETIME NOT NULL, INDEX(user_id), INDEX(gym_id))");

    // Gyms & Branding
    $pdo->exec("CREATE TABLE IF NOT EXISTS gyms (gym_id INT AUTO_INCREMENT PRIMARY KEY, tenant_code VARCHAR(50) NOT NULL UNIQUE, gym_name VARCHAR(255) NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");


    // Member Profile & Addresses
    $pdo->exec("CREATE TABLE IF NOT EXISTS addresses (address_id INT AUTO_INCREMENT PRIMARY KEY, address_line TEXT, barangay VARCHAR(100), city VARCHAR(100), province VARCHAR(100), region VARCHAR(100), created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS members (member_id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, gym_id INT NOT NULL, member_code VARCHAR(50), address_id INT, occupation VARCHAR(100), member_status VARCHAR(20) DEFAULT 'Active', created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX(user_id), INDEX(gym_id))");

    // Verification Tables
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_verifications (verification_id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, verification_type VARCHAR(50) NOT NULL, code VARCHAR(10) NOT NULL, status ENUM('pending', 'verified', 'expired') DEFAULT 'pending', expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL, verified_at DATETIME NULL)");

    // Original Website Plans Migrations (Safe-mode)
    $resPlan = $pdo->query("SHOW TABLES LIKE 'website_plans'");
    if ($resPlan->fetch()) {
        $resBadge = $pdo->query("SHOW COLUMNS FROM website_plans LIKE 'badge_text'");
        if (!$resBadge->fetch()) {
            $pdo->exec("ALTER TABLE website_plans ADD COLUMN badge_text VARCHAR(50) DEFAULT NULL AFTER billing_cycle");
        }
        $resSort = $pdo->query("SHOW COLUMNS FROM website_plans LIKE 'sort_order'");
        if (!$resSort->fetch()) {
            $pdo->exec("ALTER TABLE website_plans ADD COLUMN sort_order INT DEFAULT 0 AFTER website_plan_id");
        }
    }
    // Coach Recruitment Schema Enhancements
    $resCoachApp = $pdo->query("SHOW TABLES LIKE 'coach_applications'");
    if ($resCoachApp->fetch()) {
        $resRate = $pdo->query("SHOW COLUMNS FROM coach_applications LIKE 'session_rate'");
        if (!$resRate->fetch()) {
            $pdo->exec("ALTER TABLE coach_applications ADD COLUMN session_rate DECIMAL(10,2) AFTER license_number");
        }
    }
} catch (Exception $e) { /* Silently fail to avoid blocking connection */ }
