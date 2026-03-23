<?php
session_start();
require_once '../../db.php';

// Security Check: Only Tenants (Gym Owners)
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'tenant') {
    die("Unauthorized access.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['plan_id'])) {
    $user_id = $_SESSION['user_id'];
    $gym_id = $_SESSION['gym_id'] ?? 0;
    $plan_id = (int)$_POST['plan_id'];
    $now = date('Y-m-d H:i:s');
    $start_date = date('Y-m-d');
    
    // Fetch Plan Duration
    $stmtPlan = $pdo->prepare("SELECT duration_months FROM website_plans WHERE website_plan_id = ?");
    $stmtPlan->execute([$plan_id]);
    $duration = $stmtPlan->fetchColumn() ?: 1;
    $end_date = date('Y-m-d', strtotime("+$duration months"));

    try {
        $pdo->beginTransaction();
        
        // Change: Set status to 'Pending' and 'Unpaid' initially
        $stmtInsert = $pdo->prepare("INSERT INTO client_subscriptions (gym_id, owner_user_id, website_plan_id, start_date, end_date, subscription_status, payment_status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'Pending', 'Unpaid', ?, ?)");
        $stmtInsert->execute([$gym_id, $user_id, $plan_id, $start_date, $end_date, $now, $now]);
        $subscription_id = $pdo->lastInsertId();
        
        $pdo->commit();
        
        // Redirect to same page with subscription_id to show payment modal
        header("Location: ../subscription_plan.php?pay=" . $subscription_id . "&plan=" . $plan_id);
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        die("Failed to process subscription: " . $e->getMessage());
    }
} else {
    header("Location: ../subscription_plan.php");
    exit;
}
