<?php
session_start();
require_once '../db.php';

// Security Check: Only Tenants/Admins (Gym Owners)
$role = strtolower($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || ($role !== 'tenant' && $role !== 'admin')) {
    header("Location: ../login.php");
    exit;
}

$gym_id = $_SESSION['gym_id'];

// Multi-Tenant Logic: Check if they have an active subscription or pending payment
$stmtSub = $pdo->prepare("SELECT * FROM client_subscriptions WHERE gym_id = ? AND subscription_status != 'Expired' ORDER BY created_at DESC LIMIT 1");
$stmtSub->execute([$gym_id]);
$sub = $stmtSub->fetch();

if (!$sub) {
    // If no subscription at all, must choose a plan first
    header("Location: subscription_plan.php");
    exit;
} elseif ($sub['subscription_status'] === 'Active') {
    // Already has a plan, proceed to dashboard
    header("Location: tenant_dashboard.php");
    exit;
} elseif ($sub['payment_status'] === 'Pending Verification') {
    // Payment is pending verification, redirect back to login with notification
    header("Location: ../login.php?payment_success=1");
    exit;
} else {
    // Default: no active plan, go to selection
    header("Location: subscription_plan.php");
    exit;
}
?>
