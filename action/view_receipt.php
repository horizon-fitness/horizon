<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require "db.php";

if (!isset($_SESSION["user_id"]) || strtolower($_SESSION["role"] ?? '') !== "admin") {
    header("Location: login.php");
    exit();
}

$payment_id = $_GET['id'];
$result = mysqli_query($conn, "SELECT proof_image FROM payments WHERE id = $payment_id");
$row = mysqli_fetch_assoc($result);

if ($row && $row['proof_image']) {
    header("Content-Type: image/jpeg"); // Adjust content type based on your image type
    echo $row['proof_image'];
} else {
    echo "Image not found.";
}
?>