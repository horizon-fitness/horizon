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
            $gym_id = $pdo->lastInsertId();

            // Update application status
            $stmtUpdate = $pdo->prepare("
                UPDATE gym_owner_applications 
                SET application_status = 'Approved', reviewed_by = ?, reviewed_at = NOW() 
                WHERE application_id = ?
            ");
            $stmtUpdate->execute([$reviewer_id, $app_id]);

            // 3. Get 'Tenant' role ID
            $stmtRole = $pdo->prepare("SELECT role_id FROM roles WHERE role_name = 'Tenant' LIMIT 1");
            $stmtRole->execute();
            $role = $stmtRole->fetch(PDO::FETCH_ASSOC);
            $role_id = $role ? $role['role_id'] : 2; // Default to 2 if not found (Tenant)

            // 4. Assign role to user in user_roles table
            $stmtUserRole = $pdo->prepare("
                INSERT INTO user_roles (user_id, role_id, gym_id, tenant_code, role_status, assigned_at)
                VALUES (?, ?, ?, ?, 'Active', NOW())
            ");
            $stmtUserRole->execute([$app['user_id'], $role_id, $gym_id, $tenant_code]);

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
