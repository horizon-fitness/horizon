<?php
session_start();
require_once '../../db.php';

// Security Check: Only Superadmin can access
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'superadmin') {
    header("Location: ../../login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['application_id']) && isset($_POST['action'])) {
    $app_id = (int)$_POST['application_id'];
    $action = $_POST['action'];
    $reviewer_id = $_SESSION['user_id'];

    try {
        if ($action === 'approve') {
            // Check if already approved
            $checkStmt = $pdo->prepare("SELECT application_status, user_id, gym_name FROM gym_owner_applications WHERE application_id = ?");
            $checkStmt->execute([$app_id]);
            $app = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$app) {
                $_SESSION['error_msg'] = "Application not found.";
                header("Location: ../tenant_management.php");
                exit;
            }

            if ($app['application_status'] === 'Approved') {
                $_SESSION['error_msg'] = "Application is already approved.";
                header("Location: ../tenant_management.php");
                exit;
            }

            // Generate unique tenant code
            $tenant_code = 'GYM-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6));

            // Start transaction
            $pdo->beginTransaction();

            // Create Gym entry
            $stmtGym = $pdo->prepare("
                INSERT INTO gyms (gym_name, owner_user_id, status, tenant_code, application_id, created_at)
                VALUES (?, ?, 'Active', ?, ?, NOW())
            ");
            $stmtGym->execute([$app['gym_name'], $app['user_id'], $tenant_code, $app_id]);

            // Update application status
            $stmtUpdate = $pdo->prepare("
                UPDATE gym_owner_applications 
                SET application_status = 'Approved', reviewed_by = ?, reviewed_at = NOW() 
                WHERE application_id = ?
            ");
            $stmtUpdate->execute([$reviewer_id, $app_id]);

            // Update user role to GymOwner
            $stmtUser = $pdo->prepare("UPDATE users SET role = 'GymOwner' WHERE user_id = ?");
            $stmtUser->execute([$app['user_id']]);

            $pdo->commit();
            $_SESSION['success_msg'] = "Application approved. Gym account created with code: $tenant_code";
        } elseif ($action === 'reject') {
            // Update application status
            $stmtUpdate = $pdo->prepare("
                UPDATE gym_owner_applications 
                SET application_status = 'Rejected', reviewed_by = ?, reviewed_at = NOW() 
                WHERE application_id = ?
            ");
            $stmtUpdate->execute([$reviewer_id, $app_id]);

            $_SESSION['success_msg'] = "Application has been rejected.";
        } else {
            $_SESSION['error_msg'] = "Invalid action.";
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error_msg'] = "Database error: " . $e->getMessage();
    }
}

header("Location: ../tenant_management.php");
exit;
