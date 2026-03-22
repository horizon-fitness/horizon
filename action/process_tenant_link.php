<?php
session_start();
require_once '../db.php';

// Security Check: Only Superadmin can access
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'superadmin') {
    header("Location: ../login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['primary_tenant']) && isset($_POST['secondary_tenant'])) {
    $primary_id = $_POST['primary_tenant'] ?? '';
    $secondary_id = $_POST['secondary_tenant'] ?? '';

    if (empty($primary_id) || empty($secondary_id)) {
        $_SESSION['error_msg'] = "Please select both tenants to link.";
    } elseif ($primary_id === $secondary_id) {
        $_SESSION['error_msg'] = "You cannot link a tenant to itself.";
    } else {
        try {
            // Check if gym_links table exists
            $pdo->query("DESCRIBE gym_links");
            
            // Check if link already exists
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM gym_links WHERE (primary_id = ? AND secondary_id = ?) OR (primary_id = ? AND secondary_id = ?)");
            $checkStmt->execute([$primary_id, $secondary_id, $secondary_id, $primary_id]);
            
            if ($checkStmt->fetchColumn() > 0) {
                $_SESSION['error_msg'] = "These tenants are already linked.";
            } else {
                $insertStmt = $pdo->prepare("INSERT INTO gym_links (primary_id, secondary_id, created_at) VALUES (?, ?, NOW())");
                if ($insertStmt->execute([$primary_id, $secondary_id])) {
                    $_SESSION['success_msg'] = "Tenants linked successfully!";
                } else {
                    $_SESSION['error_msg'] = "Failed to create tenant link.";
                }
            }
        } catch (PDOException $e) {
            // Table doesn't exist or other DB error
            $_SESSION['error_msg'] = "Database Error: Please ensure the 'gym_links' table exists.";
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_link_id'])) {
    $link_id = (int)$_POST['delete_link_id'];
    try {
        $stmt = $pdo->prepare("DELETE FROM gym_links WHERE link_id = ?");
        if ($stmt->execute([$link_id])) {
            $_SESSION['success_msg'] = "Link successfully removed.";
        } else {
            $_SESSION['error_msg'] = "Failed to remove link.";
        }
    } catch (PDOException $e) {
        $_SESSION['error_msg'] = "Database error: " . $e->getMessage();
    }
}

header("Location: ../superadmin/tenant_management.php?tab=linking");
exit;
