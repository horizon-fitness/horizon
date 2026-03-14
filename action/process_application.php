<?php
session_start();
require_once '../db.php';

// Security Check: Only Superadmin can access
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'superadmin') {
    $_SESSION['error_msg'] = "Unauthorized access.";
    header("Location: ../login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['application_id'])) {
    $app_id = (int)$_POST['application_id'];
    $action = $_POST['action'];
    $admin_id = $_SESSION['user_id'];
    $now = date('Y-m-d H:i:s');

    if ($action === 'approve') {
        try {
            $pdo->beginTransaction();
            
            // 1. Update application status
            $stmtUpdate = $pdo->prepare("UPDATE gym_owner_applications SET application_status = 'Approved', reviewed_by = ?, reviewed_at = ? WHERE application_id = ?");
            $stmtUpdate->execute([$admin_id, $now, $app_id]);

            // 2. Fetch the application details
            $stmtApp = $pdo->prepare("SELECT * FROM gym_owner_applications WHERE application_id = ?");
            $stmtApp->execute([$app_id]);
            $app = $stmtApp->fetch(PDO::FETCH_ASSOC);

            if (!$app) {
                throw new Exception("Application record not found.");
            }

            // 3. Insert into gyms table
            $tenant_code = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $app['gym_name']), 0, 3)) . '-' . rand(1000, 9999);
            $stmtGym = $pdo->prepare("INSERT INTO gyms (owner_user_id, application_id, gym_name, business_name, address_id, contact_number, email, tenant_code, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, ?)");
            $stmtGym->execute([
                $app['user_id'], $app['application_id'], $app['gym_name'], $app['business_name'], $app['address_id'], $app['contact_number'], $app['email'], $tenant_code, $now, $now
            ]);
            $gym_id = $pdo->lastInsertId();

            // 4. Ensure 'Tenant' role exists and assign it
            $roleCheck = $pdo->prepare("SELECT role_id FROM roles WHERE role_name = 'Tenant' LIMIT 1");
            $roleCheck->execute();
            $role = $roleCheck->fetch(PDO::FETCH_ASSOC);
            if (!$role) {
                $pdo->query("INSERT INTO roles (role_name) VALUES ('Tenant')");
                $roleId = $pdo->lastInsertId();
            } else {
                $roleId = $role['role_id'];
            }

            $stmtRole = $pdo->prepare("INSERT INTO user_roles (user_id, role_id, gym_id, role_status, assigned_at) VALUES (?, ?, ?, 'Active', ?)");
            $stmtRole->execute([$app['user_id'], $roleId, $gym_id, $now]);

            // 5. Generate a Tenant Page for CMS Customization
            $stmtPage = $pdo->prepare("INSERT INTO tenant_pages (gym_id, page_slug, page_title, theme_color, updated_at) VALUES (?, ?, ?, '#7f13ec', ?)");
            $page_slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $app['gym_name']));
            $stmtPage->execute([$gym_id, $page_slug, $app['gym_name'], $now]);

            $pdo->commit();
            $_SESSION['success_msg'] = "Application for {$app['gym_name']} approved! Tenant portal is ready.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error_msg'] = "Failed to approve: " . $e->getMessage();
        }
    } elseif ($action === 'reject') {
        try {
            $stmtUpdate = $pdo->prepare("UPDATE gym_owner_applications SET application_status = 'Rejected', reviewed_by = ?, reviewed_at = ? WHERE application_id = ?");
            $stmtUpdate->execute([$admin_id, $now, $app_id]);
            $_SESSION['success_msg'] = "Application rejected successfully.";
        } catch (Exception $e) {
            $_SESSION['error_msg'] = "Failed to reject: " . $e->getMessage();
        }
    }
} else {
    $_SESSION['error_msg'] = "Invalid request method.";
}

// Redirect back to referring page or default to dashboard
$redirect = $_SERVER['HTTP_REFERER'] ?? '../superadmin/superadmin_dashboard.php';
header("Location: $redirect");
exit;
?>
