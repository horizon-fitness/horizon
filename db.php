<?php
date_default_timezone_set('Asia/Manila');
$host    = 'sql202.infinityfree.com';
$db      = 'if0_41368854_horizon_db';
$user    = 'if0_41368854';
$pass    = 'T2TqrnlHhRKilEn';
$charset = 'utf8mb4';
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];
try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'DB Connection Failed']);
    exit;
}
