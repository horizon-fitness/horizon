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

// Multi-Tenant Logic: Check if they have an active subscription
$stmtSub = $pdo->prepare("SELECT * FROM client_subscriptions WHERE gym_id = ? AND subscription_status = 'Active' LIMIT 1");
$stmtSub->execute([$gym_id]);
$active_sub = $stmtSub->fetch();

if (!$active_sub) {
    // If no subscription, must choose a plan first
    header("Location: subscription_plan.php");
    exit;
} else {
    // Already has a plan, proceed to dashboard
    header("Location: tenant_dashboard.php");
    exit;
}
?>
