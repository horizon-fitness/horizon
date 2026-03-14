<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once "../db.php";

// Basic Security Check
if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit();
}

$payment_id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT receipt_image FROM payments WHERE payment_id = ?");
$stmt->execute([$payment_id]);
$row = $stmt->fetch();

if ($row && $row['receipt_image']) {
    $imgData = $row['receipt_image'];
    
    // If it's a base64 string (data:image/...)
    if (str_contains($imgData, 'data:image')) {
        // Extract the mime type and the raw data
        list($type, $data) = explode(';', $imgData);
        list(, $data)      = explode(',', $data);
        header("Content-Type: " . str_replace('data:', '', $type));
        echo base64_decode($data);
    } else {
        // Fallback for legacy raw blob or path
        header("Content-Type: image/jpeg");
        echo $imgData;
    }
} else {
    echo "Receipt image not found.";
}
?>