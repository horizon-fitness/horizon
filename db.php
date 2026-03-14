<?php

// 1. SET TIMEZONE TO PHILIPPINES
date_default_timezone_set('Asia/Manila');

// Database Configuration
$host    = 'sql202.infinityfree.com';
$db      = 'if0_41368854_horizon_db';
$user    = 'if0_41368854';
$pass    = 'T2TqrnlHhRKilEn';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

// PDO Options
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO(
        $dsn,
        $user,
        $pass,
        $options
    );
    
    // Optional: Ensure MySQL also uses the correct time for NOW() calls
    // $pdo->exec("SET time_zone = '+08:00';"); 
    
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>
