<?php
session_start();
require_once '../../db.php';

// Security Check: Only Superadmin can access
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'superadmin') {
    header("Location: ../../login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gym_id']) && isset($_POST['action'])) {
    $gym_id = (int)$_POST['gym_id'];
    $action = $_POST['action'];
    $new_status = '';

    switch ($action) {
        case 'activate':
            $new_status = 'Active';
            break;
        case 'suspend':
            $new_status = 'Suspended';
            break;
        case 'deactivate':
            $new_status = 'Deactivated';
            break;
        default:
            $_SESSION['error_msg'] = "Invalid action.";
            header("Location: ../tenant_management.php");
            exit;
    }

    try {
        $stmt = $pdo->prepare("UPDATE gyms SET status = ? WHERE gym_id = ?");
        if ($stmt->execute([$new_status, $gym_id])) {
            $_SESSION['success_msg'] = "Gym account successfully " . strtolower($new_status) . ".";
        } else {
            $_SESSION['error_msg'] = "Failed to update gym status.";
        }
    } catch (PDOException $e) {
        $_SESSION['error_msg'] = "Database error: " . $e->getMessage();
    }
}

header("Location: ../tenant_management.php");
exit;
