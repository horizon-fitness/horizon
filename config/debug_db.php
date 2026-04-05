<?php
require_once '../db.php';
try {
    echo "<pre>";
    echo "--- PAYMENTS ---\n";
    $stmt = $pdo->query("DESCRIBE payments");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
    
    echo "\n--- CLIENT SUBSCRIPTIONS ---\n";
    $stmt = $pdo->query("DESCRIBE client_subscriptions");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
    echo "</pre>";
} catch (Exception $e) {
    echo $e->getMessage();
}
