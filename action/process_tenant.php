<?php
session_start();
// Include the database connection (action folder is one level deep, so ../ is correct)
require_once '../db.php';

// Security Check: Only logged-in Superadmins can perform these actions
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'superadmin') {
    $_SESSION['error_msg'] = "Unauthorized access.";
    header("Location: ../login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['gym_id'])) {
    
    $gym_id = (int)$_POST['gym_id'];
    $action = $_POST['action'];
    $now = date('Y-m-d H:i:s');

    try {
        $pdo->beginTransaction();

        // Check if gym exists
        $stmtCheck = $pdo->prepare("SELECT gym_name, owner_user_id FROM gyms WHERE gym_id = ? LIMIT 1");
        $stmtCheck->execute([$gym_id]);
        $gym = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$gym) {
            throw new Exception("Gym record not found.");
        }

        if ($action === 'activate') {
            // Activate the Gym and the Tenant Role
            $stmtUpdate = $pdo->prepare("UPDATE gyms SET status = 'Active', updated_at = ? WHERE gym_id = ?");
            $stmtUpdate->execute([$now, $gym_id]);

            $stmtRole = $pdo->prepare("UPDATE user_roles SET role_status = 'Active' WHERE gym_id = ? AND user_id = ?");
            $stmtRole->execute([$gym_id, $gym['owner_user_id']]);

            $_SESSION['success_msg'] = "Account for {$gym['gym_name']} has been reactivated successfully.";

        } elseif ($action === 'suspend') {
            // Suspend the Gym (e.g., unpaid subscription)
            $stmtUpdate = $pdo->prepare("UPDATE gyms SET status = 'Suspended', updated_at = ? WHERE gym_id = ?");
            $stmtUpdate->execute([$now, $gym_id]);

            $stmtRole = $pdo->prepare("UPDATE user_roles SET role_status = 'Suspended' WHERE gym_id = ? AND user_id = ?");
            $stmtRole->execute([$gym_id, $gym['owner_user_id']]);

            $_SESSION['success_msg'] = "Account for {$gym['gym_name']} has been suspended due to policy/billing issues.";

        } elseif ($action === 'delete') {
            // Soft Delete the Gym
            $stmtUpdate = $pdo->prepare("UPDATE gyms SET status = 'Deleted', updated_at = ? WHERE gym_id = ?");
            $stmtUpdate->execute([$now, $gym_id]);

            // Revoke the log-in access of the tenant
            $stmtRole = $pdo->prepare("UPDATE user_roles SET role_status = 'Revoked' WHERE gym_id = ? AND user_id = ?");
            $stmtRole->execute([$gym_id, $gym['owner_user_id']]);

            $_SESSION['success_msg'] = "Account for {$gym['gym_name']} has been successfully deleted/removed from the system.";

        } else {
            throw new Exception("Invalid action requested.");
        }

        $pdo->commit();

    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error_msg'] = "Action failed: " . $e->getMessage();
    }

} else {
    $_SESSION['error_msg'] = "Invalid request method.";
}

// Redirect back to the Tenant Management UI in the superadmin folder
header("Location: ../superadmin/tenant_management.php");
exit;
?>