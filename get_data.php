<?php
// get_data.php
require_once 'db.php'; // Include your robust db connection

header('Content-Type: application/json');

try {
    // Write your SQL query (change 'users' to your actual table)
    $stmt = $pdo->prepare("SELECT * FROM users");
    $stmt->execute();
    
    // Fetch results and encode to JSON
    $results = $stmt->fetchAll();
    echo json_encode($results);

} catch (PDOException $e) {
    echo json_encode(["error" => "Database error: " . $e->getMessage()]);
}
?>
