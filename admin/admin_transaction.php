<?php
session_start();
require_once '../db.php';

// Security Check: Only Staff and Coach
$role = strtolower($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || ($role !== 'staff' && $role !== 'coach')) {
    header("Location: ../login.php");
    exit;
}

$gym_id = $_SESSION['gym_id'];
$admin_name = $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];

// --- 4-Color Elite Branding System Implementation ---
if (!function_exists('hexToRgb')) {
    function hexToRgb($hex)
    {
        if (!$hex)
            return "0, 0, 0";
        $hex = str_replace("#", "", $hex);
        if (strlen($hex) == 3) {
            $r = hexdec(substr($hex, 0, 1) . substr($hex, 0, 1));
            $g = hexdec(substr($hex, 1, 1) . substr($hex, 1, 1));
            $b = hexdec(substr($hex, 2, 1) . substr($hex, 2, 1));
        } else {
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
        }
        return "$r, $g, $b";
    }
}

// Fetch Gym & Owner Context
$stmtGym = $pdo->prepare("SELECT owner_user_id, gym_name, profile_picture FROM gyms WHERE gym_id = ?");
$stmtGym->execute([$gym_id]);
$gym_data = $stmtGym->fetch();
$owner_user_id = $gym_data['owner_user_id'] ?? 0;
$gym_name = $gym_data['gym_name'] ?? 'Horizon Gym';

$configs = [
    'system_name' => $gym_name,
    'system_logo' => $gym_data['profile_picture'] ?? '',
    'theme_color' => '#8c2bee',
    'secondary_color' => '#a1a1aa',
    'text_color' => '#d1d5db',
    'bg_color' => '#0a090d',
    'card_color' => '#141216',
    'auto_card_theme' => '1',
    'font_family' => 'Lexend',
];

// 1. Merge global settings (user_id = 0)
$stmtGlobal = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = 0");
$stmtGlobal->execute();
foreach (($stmtGlobal->fetchAll(PDO::FETCH_KEY_PAIR) ?: []) as $k => $v) {
    if ($v !== null && $v !== '')
        $configs[$k] = $v;
}

// 2. Merge tenant-specific settings (user_id = owner_user_id)
$stmtTenant = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = ?");
$stmtTenant->execute([$owner_user_id]);
foreach (($stmtTenant->fetchAll(PDO::FETCH_KEY_PAIR) ?: []) as $k => $v) {
    if ($v !== null && $v !== '')
        $configs[$k] = $v;
}

// 3. Resolved branding tokens
$theme_color = $configs['theme_color'];
$highlight_color = $configs['secondary_color'];
$text_color = $configs['text_color'];
$bg_color = $configs['bg_color'];
$font_family = $configs['font_family'] ?? 'Lexend';
$auto_card_theme = $configs['auto_card_theme'] ?? '1';
$card_color = $configs['card_color'];

$primary_rgb = hexToRgb($theme_color);
$highlight_rgb = hexToRgb($highlight_color);
$card_bg_css = ($auto_card_theme === '1') ? "rgba({$primary_rgb}, 0.05)" : $card_color;

$page = [
    'logo_path' => $configs['system_logo'] ?? '',
    'theme_color' => $theme_color,
    'bg_color' => $bg_color,
    'system_name' => $configs['system_name'] ?? $gym_name,
];

$active_page = "transactions";

// --- FILTERING LOGIC ---
$limit = 10;
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int) $_GET['page'] : 1;
if ($current_page < 1) {
    $current_page = 1;
}
$offset = ($current_page - 1) * $limit;

$search = $_GET['search'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$filter_status = $_GET['status'] ?? '';
$user_filter = $_GET['user_id'] ?? 'all';
$today_filter_date = date('Y-m-d');

if ($date_from !== '' && $date_from > $today_filter_date) {
    $date_from = $today_filter_date;
}

if ($date_to !== '' && $date_to > $today_filter_date) {
    $date_to = $today_filter_date;
}

if ($date_from !== '' && $date_to !== '' && $date_from > $date_to) {
    $date_from = $date_to;
}

if (!in_array($filter_status, ['', 'Pending', 'Verified', 'Rejected'], true)) {
    $filter_status = '';
}

if ($user_filter !== 'all' && !ctype_digit((string) $user_filter)) {
    $user_filter = 'all';
}

// Base Query
$sql_parts = ["m.gym_id = :gym_id", "p.payment_type = 'Membership'"];
$sql_params = [':gym_id' => $gym_id];

if (!empty($search)) {
    $sql_parts[] = "(u.first_name LIKE :s1 OR u.last_name LIKE :s2 OR u.username LIKE :s3)";
    $sql_params[':s1'] = "%$search%";
    $sql_params[':s2'] = "%$search%";
    $sql_params[':s3'] = "%$search%";
}

if (!empty($date_from)) {
    $sql_parts[] = "p.created_at >= :d1";
    $sql_params[':d1'] = $date_from . ' 00:00:00';
}

if (!empty($date_to)) {
    $sql_parts[] = "p.created_at <= :d2";
    $sql_params[':d2'] = $date_to . ' 23:59:59';
}

if ($filter_status !== '') {
    $sql_parts[] = "p.payment_status = :status";
    $sql_params[':status'] = $filter_status;
}

if ($user_filter !== 'all') {
    $sql_parts[] = "u.user_id = :user_id";
    $sql_params[':user_id'] = (int) $user_filter;
}

$where_clause = "WHERE " . implode(' AND ', $sql_parts);

$stmtCount = $pdo->prepare("
    SELECT COUNT(*)
    FROM payments p
    JOIN members m ON p.member_id = m.member_id
    JOIN users u ON m.user_id = u.user_id
    $where_clause
");
$stmtCount->execute($sql_params);
$total_records = (int) $stmtCount->fetchColumn();
$total_pages = max(1, (int) ceil($total_records / $limit));
if ($current_page > $total_pages) {
    $current_page = $total_pages;
    $offset = ($current_page - 1) * $limit;
}

$sql = "
        SELECT p.*, u.first_name, u.last_name, u.username, u.email, u.contact_number, COALESCE(m.profile_picture, u.profile_picture) as profile_picture,
               mp.plan_name, ms.subscription_status
        FROM payments p 
        JOIN members m ON p.member_id = m.member_id 
        JOIN users u ON m.user_id = u.user_id 
        LEFT JOIN member_subscriptions ms ON p.subscription_id = ms.subscription_id
        LEFT JOIN membership_plans mp ON ms.membership_plan_id = mp.membership_plan_id
        $where_clause 
        ORDER BY p.created_at DESC
        LIMIT :limit OFFSET :offset
    ";

$stmtPayments = $pdo->prepare($sql);
foreach ($sql_params as $key => $val) {
    $isIntParam = in_array($key, [':gym_id', ':user_id'], true);
    $stmtPayments->bindValue($key, $isIntParam ? (int) $val : $val, $isIntParam ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmtPayments->bindValue(':limit', (int) $limit, PDO::PARAM_INT);
$stmtPayments->bindValue(':offset', (int) $offset, PDO::PARAM_INT);
$stmtPayments->execute();
$payments_list = $stmtPayments->fetchAll();

$stmtStats = $pdo->prepare("
    SELECT
        COUNT(*) AS total_count,
        SUM(CASE WHEN p.payment_status = 'Pending' THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN p.payment_status IN ('Verified', 'Completed', 'Paid') THEN 1 ELSE 0 END) AS approved_count,
        SUM(CASE WHEN p.payment_status = 'Rejected' THEN 1 ELSE 0 END) AS rejected_count
    FROM payments p
    JOIN members m ON p.member_id = m.member_id
    WHERE m.gym_id = ? AND p.payment_type = 'Membership'
");
$stmtStats->execute([$gym_id]);
$transaction_stats = $stmtStats->fetch() ?: [];
$total_transactions = (int) ($transaction_stats['total_count'] ?? 0);
$pending_transactions = (int) ($transaction_stats['pending_count'] ?? 0);
$approved_transactions = (int) ($transaction_stats['approved_count'] ?? 0);
$rejected_transactions = (int) ($transaction_stats['rejected_count'] ?? 0);

$stmtAllUsers = $pdo->prepare("
    SELECT DISTINCT u.user_id, CONCAT(u.first_name, ' ', u.last_name) AS full_name
    FROM payments p
    JOIN members m ON p.member_id = m.member_id
    JOIN users u ON m.user_id = u.user_id
    WHERE m.gym_id = ? AND p.payment_type = 'Membership'
    ORDER BY u.first_name ASC, u.last_name ASC
");
$stmtAllUsers->execute([$gym_id]);
$all_users_list = $stmtAllUsers->fetchAll(PDO::FETCH_ASSOC);
$users_js = array_map(function ($user) {
    return [
        'id' => (string) $user['user_id'],
        'name' => trim($user['full_name']),
    ];
}, $all_users_list);
$user_name_map = array_column($users_js, 'name', 'id');

// active_page already set in branding merge


// --- ACTION HANDLER: APPROVE / REJECT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../includes/mailer.php';
    require_once '../includes/audit_logger.php';
    $now = date('Y-m-d H:i:s');

    if (isset($_POST['approve_id'])) {
        $pay_id = (int) $_POST['approve_id'];

        // 1. Get Context for Email (Member Name, Email, Plan Name, Gym Name)
        $stmtCtx = $pdo->prepare("
                SELECT u.email, u.first_name, mp.plan_name, g.gym_name, p.subscription_id, p.amount, p.reference_number
                FROM payments p
                JOIN members m ON p.member_id = m.member_id
                JOIN users u ON m.user_id = u.user_id
                JOIN gyms g ON m.gym_id = g.gym_id
                LEFT JOIN member_subscriptions ms ON p.subscription_id = ms.subscription_id
                LEFT JOIN membership_plans mp ON ms.membership_plan_id = mp.membership_plan_id
                WHERE p.payment_id = ?
                LIMIT 1
            ");
        $stmtCtx->execute([$pay_id]);
        $ctx = $stmtCtx->fetch();
        $sub_id = $ctx['subscription_id'] ?? null;

        $pdo->beginTransaction();
        try {
            // 2. Update Payment Status to 'Verified'
            $stmtUP = $pdo->prepare("UPDATE payments SET payment_status = 'Verified', verified_by = ?, verified_at = ? WHERE payment_id = ?");
            $stmtUP->execute([$_SESSION['user_id'], $now, $pay_id]);

            // 3. Update Subscription Status to 'Active'
            if ($sub_id) {
                $stmtUS = $pdo->prepare("UPDATE member_subscriptions SET subscription_status = 'Active', updated_at = ? WHERE subscription_id = ?");
                $stmtUS->execute([$now, $sub_id]);
            }

            // 4. Send Email (E-Receipt)
            if ($ctx && !empty($ctx['email'])) {
                $receiptData = [
                    'reference_number' => $ctx['reference_number'] ?? 'TRX-' . $pay_id,
                    'gym_name' => $ctx['gym_name'],
                    'plan_name' => $ctx['plan_name'] ?? 'Membership Plan',
                    'amount' => $ctx['amount'] ?? 0,
                    'customer_name' => $ctx['first_name']
                ];
                $subject = "Official Receipt - Payment Approved for " . $ctx['gym_name'];
                sendSystemEmail($ctx['email'], $subject, getReceiptTemplate($receiptData));
            }

            // 5. Log Audit
            log_audit_event($pdo, $_SESSION['user_id'], $_SESSION['gym_id'], 'Verify', 'payments', $pay_id, ['old_status' => 'Pending'], ['new_status' => 'Verified', 'action' => 'Approved']);

            $pdo->commit();
            $_SESSION['success_msg'] = "Transaction for " . htmlspecialchars($ctx['first_name']) . " successfully approved.";
            header("Location: admin_transaction.php");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error_msg'] = $e->getMessage();
            header("Location: admin_transaction.php");
            exit;
        }
    }

    if (isset($_POST['reject_id'])) {
        $pay_id = (int) $_POST['reject_id'];

        $stmtCtx = $pdo->prepare("
                SELECT u.email, u.first_name, mp.plan_name, g.gym_name, p.subscription_id, p.amount, p.reference_number
                FROM payments p
                JOIN members m ON p.member_id = m.member_id
                JOIN users u ON m.user_id = u.user_id
                JOIN gyms g ON m.gym_id = g.gym_id
                LEFT JOIN member_subscriptions ms ON p.subscription_id = ms.subscription_id
                LEFT JOIN membership_plans mp ON ms.membership_plan_id = mp.membership_plan_id
                WHERE p.payment_id = ?
                LIMIT 1
            ");
        $stmtCtx->execute([$pay_id]);
        $ctx = $stmtCtx->fetch();
        $sub_id = $ctx['subscription_id'] ?? null;

        $pdo->beginTransaction();
        try {
            // 2. Update Payment Status to 'Rejected'
            $stmtUP = $pdo->prepare("UPDATE payments SET payment_status = 'Rejected', verified_by = ?, verified_at = ? WHERE payment_id = ?");
            $stmtUP->execute([$_SESSION['user_id'], $now, $pay_id]);

            // 3. Update Subscription Status to 'Rejected'
            if ($sub_id) {
                $stmtUS = $pdo->prepare("UPDATE member_subscriptions SET subscription_status = 'Rejected', updated_at = ? WHERE subscription_id = ?");
                $stmtUS->execute([$now, $sub_id]);
            }

            // 4. Send Email (Rejection with Refund Notice)
            if ($ctx && !empty($ctx['email'])) {
                $subject = "Payment Rejected - Action Required for " . $ctx['gym_name'];
                $content = "
                        <p>Hello " . htmlspecialchars($ctx['first_name']) . ",</p>
                        <p>Your recent payment for the <strong>" . htmlspecialchars($ctx['plan_name'] ?? 'Membership') . "</strong> at <strong>" . htmlspecialchars($ctx['gym_name']) . "</strong> was not verified and has been <strong>Rejected</strong>.</p>
                        
                        <div style='margin: 20px 0; padding: 15px; background: #fff5f5; border: 1px solid #feb2b2; border-radius: 8px;'>
                            <p style='margin: 0; font-size: 13px; color: #c53030;'><strong>Refund Notice:</strong> Since the system is currently in test mode, refunds will be processed manually within <strong>3 to 5 business days</strong>. Thank you for your patience.</p>
                        </div>

                        <p>Please log in to your app to re-submit your payment or visit the front desk for assistance.</p>
                        <p>Thank you!</p>
                    ";
                sendSystemEmail($ctx['email'], $subject, getEmailTemplate("Payment Rejected", $content));
            }

            // 5. Log Audit
            log_audit_event($pdo, $_SESSION['user_id'], $_SESSION['gym_id'], 'Verify', 'payments', $pay_id, ['old_status' => 'Pending'], ['new_status' => 'Rejected', 'action' => 'Rejected']);

            $pdo->commit();
            $_SESSION['success_msg'] = "Transaction for " . htmlspecialchars($ctx['first_name']) . " has been rejected.";
            header("Location: admin_transaction.php");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error_msg'] = $e->getMessage();
            header("Location: admin_transaction.php");
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html class="dark" lang="en">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>Transaction Ledger | Horizon Partners</title>
    <link
        href="https://fonts.googleapis.com/css2?family=<?= urlencode($font_family) ?>:wght@300;400;500;600;700;800;900&display=swap"
        rel="stylesheet" />
    <link
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200"
        rel="stylesheet" />
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        const availableUsers = <?= json_encode($users_js, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const currentUserFilter = <?= json_encode((string) $user_filter) ?>;

        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "var(--primary)",
                        "highlight": "var(--highlight)",
                        "text-main": "var(--text-main)",
                        "background": "var(--background)",
                        "card-bg": "var(--card-bg)"
                    }
                }
            }
        } 
    </script>
    <style>
        :root {
            --primary:
                <?= $theme_color ?>
            ;
            --primary-rgb:
                <?= $primary_rgb ?>
            ;
            --highlight:
                <?= $highlight_color ?>
            ;
            --highlight-rgb:
                <?= $highlight_rgb ?>
            ;
            --text-main:
                <?= $text_color ?>
            ;
            --background:
                <?= $bg_color ?>
            ;
            --background-rgb:
                <?= hexToRgb($bg_color) ?>
            ;
            --card-bg:
                <?= $card_bg_css ?>
            ;
            --card-blur: 20px;
        }

        body {
            font-family: '<?= $font_family ?>', sans-serif;
            background-color: var(--background);
            color: var(--text-main);
            display: flex;
            flex-direction: row;
            min-height: 100vh;
            overflow: hidden;
        }

        .glass-card {
            background: var(--card-bg);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 24px;
            backdrop-filter: blur(var(--card-blur));
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .status-card-blue {
            border: 1px solid rgba(var(--primary-rgb), 0.18);
            background: linear-gradient(135deg, rgba(var(--primary-rgb), 0.05) 0%, rgba(var(--primary-rgb), 0.01) 100%);
        }

        .status-card-green {
            border: 1px solid rgba(16, 185, 129, 0.25);
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.05) 0%, rgba(16, 185, 129, 0.01) 100%);
        }

        .status-card-yellow {
            border: 1px solid rgba(245, 158, 11, 0.25);
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.05) 0%, rgba(245, 158, 11, 0.01) 100%);
        }

        .status-card-red {
            border: 1px solid rgba(244, 63, 94, 0.25);
            background: linear-gradient(135deg, rgba(244, 63, 94, 0.05) 0%, rgba(244, 63, 94, 0.01) 100%);
        }

        /* Unified Sidebar Navigation Styles */
        .side-nav {
            width: 110px;
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            position: fixed;
            left: 0;
            top: 0;
            height: 100vh;
            z-index: 50;
            background-color: var(--background);
            border-right: 1px solid rgba(255, 255, 255, 0.05);
        }

        .side-nav:hover {
            width: 300px;
        }

        .main-content {
            margin-left: 110px;
            flex: 1;
            min-width: 0;
            transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .side-nav:hover~.main-content {
            margin-left: 300px;
        }

        .nav-label {
            opacity: 0;
            transform: translateX(-15px);
            transition: all 0.3s ease-in-out;
            white-space: nowrap;
            pointer-events: none;
            color: var(--text-main);
        }

        .side-nav:hover .nav-label {
            opacity: 1;
            transform: translateX(0);
            pointer-events: auto;
        }

        .nav-section-label {
            max-height: 0;
            opacity: 0;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            margin: 0 !important;
            pointer-events: none;
            color: color-mix(in srgb, var(--text-main) 40%, transparent);
        }

        .side-nav:hover .nav-section-label {
            max-height: 20px;
            opacity: 1;
            margin-bottom: 8px !important;
            pointer-events: auto;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 10px 38px;
            transition: opacity 0.2s ease, color 0.2s ease;
            text-decoration: none;
            white-space: nowrap;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: color-mix(in srgb, var(--text-main) 45%, transparent);
            position: relative;
        }

        .nav-item:hover {
            color: var(--text-main);
        }

        .nav-item .material-symbols-rounded {
            color: var(--highlight);
            transition: transform 0.2s ease;
        }

        .nav-item:hover .material-symbols-rounded {
            transform: scale(1.12);
        }

        .nav-item.active {
            color: var(--primary) !important;
            position: relative;
        }

        .nav-item.active .material-symbols-rounded {
            color: var(--primary);
        }

        .nav-item.active::after {
            content: '';
            position: absolute;
            right: 0px;
            top: 50%;
            transform: translateY(-50%);
            width: 4px;
            height: 24px;
            background: var(--primary);
            border-radius: 4px 0 0 4px;
        }

        .no-scrollbar::-webkit-scrollbar {
            display: none;
        }

        .no-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        .input-box {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            color: var(--text-main);
            padding: 12px 16px;
            font-size: 13px;
            font-weight: 500;
            outline: none;
            transition: all 0.2s;
        }

        .input-box:focus {
            border-color: var(--primary);
            background: rgba(255, 255, 255, 0.08);
            box-shadow: 0 0 0 4px rgba(var(--primary-rgb), 0.1);
        }

        .input-box::placeholder {
            color: color-mix(in srgb, var(--text-main) 20%, transparent);
        }

        input[type="date"] {
            color-scheme: dark;
        }

        input[type="date"]::-webkit-calendar-picker-indicator {
            filter: invert(1) brightness(1.35);
            opacity: 0.75;
            cursor: pointer;
        }

        input[type="date"]::-webkit-calendar-picker-indicator:hover {
            opacity: 1;
        }

        .badge-surface {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        .pagination-btn {
            padding: 8px 16px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.05);
            color: var(--text-main);
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            transition: all 0.2s ease;
            cursor: pointer;
        }

        .pagination-btn:hover:not(.disabled) {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        .pagination-btn.active {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }

        .pagination-btn.disabled {
            opacity: 0.2;
            pointer-events: none;
            cursor: not-allowed;
        }

        .pagination-status {
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            color: var(--text-main);
            opacity: 0.5;
        }

        .selected-option {
            background-color: var(--primary) !important;
            color: #ffffff !important;
        }

        .custom-select-dropdown {
            background-color: #141216;
            border: 1px solid rgba(255, 255, 255, 0.10);
            box-shadow: 0 18px 45px rgba(0, 0, 0, 0.45);
        }

        .searchable-dropdown-overlay {
            background: #141216;
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 10px 40px -10px rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(40px);
            scrollbar-width: none;
        }

        .searchable-dropdown-overlay::-webkit-scrollbar {
            display: none;
        }

        .tenant-option {
            border: 1px solid transparent;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .tenant-option:hover {
            background: rgba(var(--primary-rgb), 0.08);
            border-color: rgba(var(--primary-rgb), 0.12);
            color: var(--primary);
        }

        .tenant-option.selected {
            background: var(--primary);
            color: #ffffff;
        }

        #confirmModal,
        #detailModal {
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            left: 110px;
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 24px;
            transition: left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .side-nav:hover~#confirmModal,
        .side-nav:hover~#detailModal {
            left: 300px;
        }

        .modal-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(var(--background-rgb), 0.4);
            backdrop-filter: blur(20px) saturate(180%);
            opacity: 0;
            visibility: hidden;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: -1;
        }

        .modal-backdrop.active {
            opacity: 1;
            visibility: visible;
        }

        .modal-container {
            width: 100%;
            max-width: 450px;
            background: var(--card-bg);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 28px;
            transform: scale(0.95);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            opacity: 0;
            visibility: hidden;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            position: relative;
            overflow: hidden;
        }

        #detailModal .modal-container {
            max-width: 600px;
        }

        #confirmModal.active .modal-container,
        #detailModal.active .modal-container {
            opacity: 1;
            visibility: visible;
            transform: scale(1);
        }

        .flex-important {
            display: flex !important;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        .animate-in {
            animation: fadeIn 0.5s ease-out;
        }
    </style>
    <script>
        function updateHeaderClock() {
            const now = new Date();
            const clockEl = document.getElementById('headerClock');
            if (clockEl) {
                clockEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            }
        }
        setInterval(updateHeaderClock, 1000);
        window.addEventListener('DOMContentLoaded', updateHeaderClock);

        function clearTransactionFilters() {
            window.location.href = 'admin_transaction.php';
        }

        let filterTimeout;
        function autoSubmitFilters(delay = 350) {
            clearTimeout(filterTimeout);
            filterTimeout = setTimeout(() => {
                const form = document.getElementById('transactionFilterForm');
                if (form) form.submit();
            }, delay);
        }

        function syncTransactionDateLimits() {
            const fromInput = document.getElementById('date_from');
            const toInput = document.getElementById('date_to');
            if (!fromInput || !toInput) return;

            const today = new Date().toISOString().split('T')[0];
            fromInput.max = toInput.value || today;
            toInput.max = today;
            toInput.min = fromInput.value || '';

            if (fromInput.value && fromInput.value > today) fromInput.value = today;
            if (toInput.value && toInput.value > today) toInput.value = today;
            if (fromInput.value && toInput.value && fromInput.value > toInput.value) {
                fromInput.value = toInput.value;
            }
        }

        function toggleCustomDropdown(trigger, event) {
            event.stopPropagation();
            const dropdown = trigger.nextElementSibling;
            const container = trigger.closest('.custom-select-container');

            const userDropdown = document.getElementById('userDropdown');
            if (userDropdown) userDropdown.classList.add('hidden');

            document.querySelectorAll('.custom-select-dropdown').forEach((item) => {
                if (item !== dropdown) item.classList.add('hidden');
            });
            document.querySelectorAll('.custom-select-container').forEach((item) => {
                if (item !== container) item.classList.remove('is-open');
            });

            dropdown.classList.toggle('hidden');
            container.classList.toggle('is-open', !dropdown.classList.contains('hidden'));
        }

        document.addEventListener('click', (event) => {
            const customOption = event.target.closest('.custom-option');

            const tenantOption = event.target.closest('.tenant-option');
            if (tenantOption) {
                event.stopPropagation();
                const container = tenantOption.closest('#userSearchContainer');
                if (container) {
                    const hiddenInput = container.querySelector('#hidden_user_id');
                    const input = container.querySelector('#userSearchInput');
                    const dropdown = container.querySelector('#userDropdown');

                    hiddenInput.value = tenantOption.dataset.id || 'all';
                    input.value = tenantOption.dataset.name || 'All Users';
                    dropdown.classList.add('hidden');
                    container.closest('form')?.submit();
                }
                return;
            }

            if (customOption) {
                event.stopPropagation();
                const container = customOption.closest('.custom-select-container');
                const hiddenInput = container.querySelector('input[type="hidden"]');
                const displayInput = container.querySelector('input[type="text"]');
                const dropdown = container.querySelector('.custom-select-dropdown');

                hiddenInput.value = customOption.dataset.value;
                displayInput.value = customOption.textContent.trim();

                container.querySelectorAll('.custom-option').forEach((option) => {
                    option.classList.remove('selected-option');
                    option.classList.add('text-white/60');
                });

                customOption.classList.add('selected-option');
                customOption.classList.remove('text-white/60');
                dropdown.classList.add('hidden');
                container.classList.remove('is-open');
                container.closest('form')?.submit();
                return;
            }

            if (!event.target.closest('.custom-select-container')) {
                document.querySelectorAll('.custom-select-dropdown').forEach((item) => item.classList.add('hidden'));
                document.querySelectorAll('.custom-select-container').forEach((item) => item.classList.remove('is-open'));
            }

            if (!event.target.closest('#userSearchContainer')) {
                const userDropdown = document.getElementById('userDropdown');
                if (userDropdown) userDropdown.classList.add('hidden');
            }
        });

        function initSearchableDropdown(containerId, inputId, dropdownId, listId, hiddenInputId, currentFilter) {
            const container = document.getElementById(containerId);
            const input = document.getElementById(inputId);
            const dropdown = document.getElementById(dropdownId);
            const list = document.getElementById(listId);
            const hiddenInput = document.getElementById(hiddenInputId);

            if (!container || !input || !dropdown || !list || !hiddenInput) return;

            function escapeHtml(value) {
                return String(value).replace(/[&<>"']/g, (char) => ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                }[char]));
            }

            function renderOptions(filter = '') {
                const searchFilter = filter === 'All Users' ? '' : filter.toLowerCase().trim();
                const filtered = availableUsers.filter((user) => user.name.toLowerCase().includes(searchFilter));

                list.innerHTML = filtered.map((user) => `
                    <div class="tenant-option px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider ${String(currentFilter) === String(user.id) ? 'selected' : 'text-white/60'}"
                         data-id="${escapeHtml(user.id)}" data-name="${escapeHtml(user.name)}">
                        ${escapeHtml(user.name)}
                    </div>
                `).join('') || '<div class="px-4 py-3 text-[9px] text-white/20 uppercase font-black">No user found...</div>';
            }

            const newInput = input.cloneNode(true);
            input.parentNode.replaceChild(newInput, input);

            newInput.addEventListener('focus', () => {
                document.querySelectorAll('.custom-select-dropdown').forEach((item) => item.classList.add('hidden'));
                document.querySelectorAll('.custom-select-container').forEach((item) => item.classList.remove('is-open'));
                dropdown.classList.remove('hidden');
                renderOptions(newInput.value);
            });

            newInput.addEventListener('input', (event) => {
                dropdown.classList.remove('hidden');
                renderOptions(event.target.value);
            });

            renderOptions('');
        }

        window.addEventListener('DOMContentLoaded', () => {
            syncTransactionDateLimits();
            initSearchableDropdown('userSearchContainer', 'userSearchInput', 'userDropdown', 'userOptionsList', 'hidden_user_id', currentUserFilter);
        });
    </script>
</head>

<body class="antialiased flex h-screen overflow-hidden">

    <!-- Dynamic Admin Sidebar -->
    <?php include '../includes/admin_sidebar.php'; ?>

    <div class="main-content flex-1 overflow-y-auto no-scrollbar">
        <main class="p-10 max-w-[1400px] mx-auto pb-20">
            <header class="mb-12 flex flex-row justify-between items-end gap-6">
                <div>
                    <h2
                        class="text-3xl font-black italic uppercase tracking-tighter leading-none text-white transition-all">
                        <span class="opacity-40">Transaction</span> <span class="text-primary">Ledger</span></h2>
                    <p
                        class="text-[--text-main]/60 text-[10px] font-bold uppercase tracking-widest mt-2 px-1 opacity-60">
                        Complete Payment History & Approval Queue</p>
                </div>
                <div class="flex items-end gap-8 text-right shrink-0">
                    <div class="flex flex-col items-end">
                        <p id="headerClock"
                            class="text-[--text-main] font-black italic text-2xl leading-none tracking-tighter uppercase">
                            00:00:00 AM</p>
                        <p class="text-primary text-[10px] font-black uppercase tracking-[0.2em] leading-none mt-2">
                            <?= date('l, M d, Y') ?></p>
                    </div>
                </div>
            </header>

            <?php if (isset($_SESSION['success_msg'])): ?>
                <div
                    class="mb-8 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-sm font-semibold flex items-center gap-3 animate-in fade-in slide-in-from-top-4 duration-500">
                    <span class="material-symbols-rounded">check_circle</span>
                    <?= htmlspecialchars($_SESSION['success_msg']) ?>
                </div>
                <?php unset($_SESSION['success_msg']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_msg'])): ?>
                <div
                    class="mb-8 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-sm font-semibold flex items-center gap-3 animate-in fade-in slide-in-from-top-4 duration-500">
                    <span class="material-symbols-rounded">error</span>
                    <?= htmlspecialchars($_SESSION['error_msg']) ?>
                </div>
                <?php unset($_SESSION['error_msg']); ?>
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6 mb-10">
                <div class="glass-card p-8 status-card-blue relative overflow-hidden group hover:scale-[1.02] transition-all">
                    <span class="material-symbols-rounded absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-primary">receipt_long</span>
                    <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">All Transactions</p>
                    <h3 class="text-3xl font-black italic uppercase text-[--text-main]"><?= $total_transactions ?></h3>
                    <p class="text-[10px] font-black uppercase mt-2 italic text-primary">Membership Payments</p>
                </div>
                <div class="glass-card p-8 status-card-yellow relative overflow-hidden group hover:scale-[1.02] transition-all">
                    <span class="material-symbols-rounded absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-amber-500">pending_actions</span>
                    <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">Pending</p>
                    <h3 class="text-3xl font-black italic uppercase text-[--text-main]"><?= $pending_transactions ?></h3>
                    <p class="text-amber-500 text-[10px] font-black uppercase mt-2 italic">Needs Review</p>
                </div>
                <div class="glass-card p-8 status-card-green relative overflow-hidden group hover:scale-[1.02] transition-all">
                    <span class="material-symbols-rounded absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-emerald-500">verified</span>
                    <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">Approved</p>
                    <h3 class="text-3xl font-black italic uppercase text-[--text-main]"><?= $approved_transactions ?></h3>
                    <p class="text-emerald-500 text-[10px] font-black uppercase mt-2 italic">Verified Payments</p>
                </div>
                <div class="glass-card p-8 status-card-red relative overflow-hidden group hover:scale-[1.02] transition-all">
                    <span class="material-symbols-rounded absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-rose-500">block</span>
                    <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">Rejected</p>
                    <h3 class="text-3xl font-black italic uppercase text-[--text-main]"><?= $rejected_transactions ?></h3>
                    <p class="text-rose-500 text-[10px] font-black uppercase mt-2 italic">Declined Records</p>
                </div>
            </div>

            <div class="hidden !hidden">
                <p class="text-[10px] font-black uppercase tracking-widest text-[--text-main]/40 italic">TRANSACTION
                    LOGS — <span class="text-[--text-main]">LIVE FEED</span></p>
                <div class="flex items-center gap-4">
                    <span
                        class="px-4 py-1.5 rounded-full bg-white/5 border border-white/5 text-[9px] font-black uppercase tracking-widest text-[--text-main]/60">Total
                        Entries: <?= count($payments_list) ?></span>
                </div>
            </div>

            <div class="glass-card shadow-2xl overflow-hidden">
                <div class="p-8 border-b border-white/5 bg-white/[0.01]">
                    <form id="transactionFilterForm" method="GET" class="flex flex-wrap items-center gap-5 relative">
                        <div class="flex-1 min-w-[260px] relative group">
                            <span class="material-symbols-rounded absolute left-4 top-1/2 -translate-y-1/2 text-base text-primary/50 transition-transform group-hover:scale-110">search</span>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                                placeholder="Search records..." autocomplete="off" oninput="autoSubmitFilters()"
                                class="w-full h-[52px] bg-white/5 border border-white/10 rounded-2xl pl-12 pr-4 text-[10px] font-black uppercase tracking-widest outline-none text-[--text-main] hover:border-white/20 transition-all focus:border-primary">
                        </div>

                        <div class="flex-1 min-w-[260px] relative group" id="userSearchContainer">
                            <?php
                            $selectedUserName = ($user_filter === 'all') ? 'All Users' : ($user_name_map[(string) $user_filter] ?? 'All Users');
                            ?>
                            <input type="hidden" name="user_id" id="hidden_user_id" value="<?= htmlspecialchars((string) $user_filter) ?>">
                            <div class="relative">
                                <span class="material-symbols-rounded absolute left-4 top-1/2 -translate-y-1/2 text-base text-primary/50 transition-transform group-hover:scale-110">person_search</span>
                                <input type="text" id="userSearchInput" value="<?= htmlspecialchars($selectedUserName) ?>"
                                    placeholder="Search name..." autocomplete="off"
                                    class="w-full h-[52px] bg-white/5 border border-white/10 rounded-2xl pl-12 pr-11 text-[10px] font-black uppercase tracking-widest outline-none text-[--text-main] hover:border-white/20 transition-all focus:border-primary">
                                <span class="material-symbols-rounded absolute right-4 top-1/2 -translate-y-1/2 text-primary/70 text-base pointer-events-none transition-transform group-hover:scale-110">expand_more</span>
                            </div>
                            <div id="userDropdown" class="absolute left-0 right-0 top-full mt-2 z-[100] rounded-xl searchable-dropdown-overlay max-h-64 overflow-y-auto hidden">
                                <div class="p-1.5 space-y-0.5">
                                    <div class="tenant-option px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider <?= $user_filter === 'all' ? 'selected' : 'text-white/60' ?>"
                                        data-id="all" data-name="All Users">All Users</div>
                                    <div id="userOptionsList"></div>
                                </div>
                            </div>
                        </div>

                        <?php
                        $statusDisplay = 'All Status';
                        if ($filter_status === 'Pending') $statusDisplay = 'Pending';
                        if ($filter_status === 'Verified') $statusDisplay = 'Approved';
                        if ($filter_status === 'Rejected') $statusDisplay = 'Rejected';
                        ?>
                        <div class="w-[180px] relative group shrink-0 custom-select-container">
                            <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>">
                            <div class="relative custom-select-trigger cursor-pointer" onclick="toggleCustomDropdown(this, event)">
                                <input type="text" readonly value="<?= htmlspecialchars($statusDisplay) ?>"
                                    class="w-full h-[52px] bg-white/5 border border-white/10 rounded-2xl pl-5 pr-11 text-[10px] font-black uppercase tracking-widest outline-none text-[--text-main] hover:border-white/20 transition-all cursor-pointer pointer-events-none">
                                <span class="material-symbols-rounded absolute right-4 top-1/2 -translate-y-1/2 text-primary/70 text-base pointer-events-none transition-transform group-hover:scale-110">expand_more</span>
                            </div>
                            <div class="absolute left-0 right-0 top-full mt-2 z-[100] rounded-xl p-1.5 space-y-0.5 custom-select-dropdown hidden max-h-64 overflow-y-auto">
                                <div class="custom-option px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-all <?= $filter_status === '' ? 'selected-option' : 'text-white/60' ?>" data-value="">All Status</div>
                                <div class="custom-option px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-all <?= $filter_status === 'Pending' ? 'selected-option' : 'text-white/60' ?>" data-value="Pending">Pending</div>
                                <div class="custom-option px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-all <?= $filter_status === 'Verified' ? 'selected-option' : 'text-white/60' ?>" data-value="Verified">Approved</div>
                                <div class="custom-option px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-all <?= $filter_status === 'Rejected' ? 'selected-option' : 'text-white/60' ?>" data-value="Rejected">Rejected</div>
                            </div>
                        </div>

                        <input type="date" name="date_from" id="date_from" value="<?= htmlspecialchars($date_from) ?>"
                            max="<?= htmlspecialchars($date_to ?: date('Y-m-d')) ?>"
                            onchange="syncTransactionDateLimits(); autoSubmitFilters(0)"
                            class="w-[170px] h-[52px] bg-white/5 border border-white/10 rounded-2xl px-5 text-[10px] font-black uppercase tracking-widest outline-none text-[--text-main] hover:border-white/20 transition-all focus:border-primary">
                        <input type="date" name="date_to" id="date_to" value="<?= htmlspecialchars($date_to) ?>"
                            min="<?= htmlspecialchars($date_from) ?>"
                            max="<?= date('Y-m-d') ?>"
                            onchange="syncTransactionDateLimits(); autoSubmitFilters(0)"
                            class="w-[170px] h-[52px] bg-white/5 border border-white/10 rounded-2xl px-5 text-[10px] font-black uppercase tracking-widest outline-none text-[--text-main] hover:border-white/20 transition-all focus:border-primary">
                        <button type="button" onclick="clearTransactionFilters()"
                            class="h-[52px] w-[52px] rounded-2xl bg-white/5 border border-white/5 flex items-center justify-center text-white/30 hover:text-white hover:bg-white/10 transition-all"
                            title="Reset filters">
                            <span class="material-symbols-rounded text-lg">refresh</span>
                        </button>
                    </form>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr
                                class="bg-white/5 border-b border-white/5">
                                <th class="px-8 py-5 text-[10px] font-black uppercase tracking-[0.22em] text-[--text-main]/45">Name</th>
                                <th class="px-8 py-5 text-center text-[10px] font-black uppercase tracking-[0.22em] text-[--text-main]/45">Ref No.</th>
                                <th class="px-8 py-5 text-center text-[10px] font-black uppercase tracking-[0.22em] text-[--text-main]/45">Transaction Type</th>
                                <th class="px-8 py-5 text-center text-[10px] font-black uppercase tracking-[0.22em] text-[--text-main]/45">Amount</th>
                                <th class="px-8 py-5 text-center text-[10px] font-black uppercase tracking-[0.22em] text-[--text-main]/45">Date</th>
                                <th class="px-8 py-5 text-center text-[10px] font-black uppercase tracking-[0.22em] text-[--text-main]/45">Status</th>
                                <th class="px-8 py-5 text-center text-[10px] font-black uppercase tracking-[0.22em] text-[--text-main]/45">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <?php if (empty($payments_list)): ?>
                                <tr>
                                    <td colspan="7" class="px-8 py-24 text-center">
                                        <span
                                            class="material-symbols-rounded text-4xl text-[--text-main]/20 mb-4 block">receipt_long</span>
                                        <p
                                            class="text-[10px] font-black uppercase tracking-widest text-[--text-main]/40">
                                            No transactions found.</p>
                                    </td>
                                </tr>
                            <?php else:
                                foreach ($payments_list as $pay): ?>
                                    <tr class="hover:bg-white/[0.02] group transition-colors">
                                        <td class="px-8 py-6 align-middle">
                                            <div class="flex items-center gap-4">
                                                <div class="size-10 rounded-2xl bg-primary/10 flex items-center justify-center overflow-hidden">
                                                    <?php if (!empty($pay['profile_picture'])): 
                                                        $pfp_src = (strpos($pay['profile_picture'], 'data:image') === 0) ? $pay['profile_picture'] : '../' . $pay['profile_picture'];
                                                    ?>
                                                        <img src="<?= htmlspecialchars($pfp_src) ?>" class="size-full object-cover" alt="">
                                                    <?php else: ?>
                                                        <div class="text-primary font-black text-base">
                                                            <?= substr($pay['first_name'] ?? 'U', 0, 1) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="min-w-0">
                                                    <p class="text-[13px] font-bold text-[--text-main] group-hover:text-primary transition-colors tracking-wide truncate">
                                                        <?= htmlspecialchars(($pay['first_name'] ?? '') . ' ' . ($pay['last_name'] ?? '')) ?>
                                                    </p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-8 py-6 text-center align-middle">
                                            <p class="text-[11px] font-black text-[--text-main]/60 tracking-wider">
                                                <?= htmlspecialchars($pay['reference_number'] ?: 'TRX-' . $pay['payment_id']) ?>
                                            </p>
                                        </td>
                                        <td class="px-8 py-6 text-center align-middle">
                                            <span
                                                class="px-3 py-1 rounded-xl badge-surface text-[10px] font-black uppercase tracking-[0.1em] text-[--text-main]/60"><?= htmlspecialchars($pay['payment_type'] ?? 'OFFLINE') ?></span>
                                        </td>
                                        <td class="px-8 py-6 text-center align-middle">
                                            <p class="text-[13px] font-black text-[--text-main] tracking-tight">
                                                &#8369;<?= number_format($pay['amount'], 2) ?></p>
                                        </td>
                                        <td class="px-8 py-6 text-center align-middle">
                                            <p class="text-[12px] font-bold text-primary tracking-wide">
                                                <?= date('M d, Y', strtotime($pay['created_at'])) ?></p>
                                        </td>
                                        <td class="px-8 py-6 text-center align-middle">
                                            <?php
                                            $status = strtoupper($pay['payment_status'] ?? 'PENDING');
                                            $status_class = "text-[--text-main]/40 bg-white/5 border-white/5";
                                            if ($status === 'VERIFIED' || $status === 'COMPLETED' || $status === 'PAID') {
                                                $status = "APPROVED";
                                                $status_class = "text-emerald-500 bg-emerald-500/10 border-emerald-500/20";
                                            } elseif ($status === 'REJECTED') {
                                                $status_class = "text-rose-500 bg-rose-500/10 border-rose-500/20";
                                            } elseif ($status === 'PENDING') {
                                                $status_class = "text-amber-500 bg-amber-500/10 border-amber-500/20";
                                            }
                                            ?>
                                            <span
                                                class="px-4 py-1.5 rounded-full border text-[8px] font-black uppercase tracking-widest <?= $status_class ?>"><?= $status ?></span>
                                        </td>
                                        <td class="px-8 py-6 text-center align-middle">
                                            <div class="flex justify-center gap-2">
                                                <button type="button" onclick='openDetailModal({
                                                id: "<?= $pay["payment_id"] ?>",
                                                name: "<?= htmlspecialchars($pay["first_name"] . " " . $pay["last_name"]) ?>",
                                                username: "<?= htmlspecialchars($pay["username"] ?? "unknown") ?>",
                                                email: "<?= htmlspecialchars($pay["email"] ?? "") ?>",
                                                contact: "<?= htmlspecialchars($pay["contact_number"] ?? "") ?>",
                                                ref: "<?= htmlspecialchars($pay["reference_number"] ?: 'TRX-' . $pay["payment_id"]) ?>",
                                                amount: "<?= $pay["amount"] ?>",
                                                type: "<?= htmlspecialchars($pay["payment_type"] ?? "OFFLINE") ?>",
                                                plan: "<?= htmlspecialchars($pay["plan_name"] ?? "Membership Plan") ?>",
                                                subscriptionStatus: "<?= htmlspecialchars($pay["subscription_status"] ?? "N/A") ?>",
                                                date: "<?= date("M d, Y h:i A", strtotime($pay["created_at"])) ?>",
                                                dateOnly: "<?= date("M d, Y", strtotime($pay["created_at"])) ?>",
                                                timeOnly: "<?= date("h:i A", strtotime($pay["created_at"])) ?>",
                                                status: "<?= $status ?>",
                                                statusClass: "<?= $status_class ?>"
                                            })'
                                                    class="size-8 rounded-lg bg-white/5 border border-white/10 text-[--text-main]/40 flex items-center justify-center hover:bg-primary hover:text-white transition-all shadow-lg active:scale-90"
                                                    title="View Details">
                                                    <span class="material-symbols-rounded text-base">search</span>
                                                </button>

                                                <?php if ($pay['payment_status'] === 'Pending'): ?>
                                                    <form method="POST">
                                                        <input type="hidden" name="approve_id" value="<?= $pay['payment_id'] ?>">
                                                        <button type="button"
                                                            onclick="confirmAction(this.form, 'Approve Transaction', 'Are you sure you want to approve this transaction?')"
                                                            class="size-8 rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-emerald-500 flex items-center justify-center hover:bg-emerald-500 hover:text-white transition-all shadow-lg active:scale-90"
                                                            title="Approve">
                                                            <span class="material-symbols-rounded text-base">check</span>
                                                        </button>
                                                    </form>
                                                    <form method="POST">
                                                        <input type="hidden" name="reject_id" value="<?= $pay['payment_id'] ?>">
                                                        <button type="button"
                                                            onclick="confirmAction(this.form, 'Reject Transaction', 'Are you sure you want to reject this transaction? This action cannot be undone.')"
                                                            class="size-8 rounded-lg bg-rose-500/10 border border-rose-500/20 text-rose-500 flex items-center justify-center hover:bg-rose-500 hover:text-white transition-all shadow-lg active:scale-90"
                                                            title="Reject">
                                                            <span class="material-symbols-rounded text-base">close</span>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php
                $showing_start = $total_records > 0 ? $offset + 1 : 0;
                $showing_end = $total_records > 0 ? min($offset + $limit, $total_records) : 0;
                $page_params = $_GET;
                unset($page_params['page']);
                $page_base = 'admin_transaction.php?' . http_build_query($page_params);
                $page_joiner = empty($page_params) ? 'page=' : '&page=';
                ?>
                <div class="px-8 py-5 border-t border-white/5 bg-white/[0.01] flex justify-between items-center">
                    <p class="pagination-status">
                        Showing <?= $showing_start ?> to <?= $showing_end ?> of <?= $total_records ?> transactions
                    </p>
                    <div class="flex items-center gap-2">
                        <a href="<?= htmlspecialchars($page_base . $page_joiner . max(1, $current_page - 1)) ?>" class="pagination-btn <?= ($current_page <= 1) ? 'disabled' : '' ?>">Prev</a>
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <?php if ($i === 1 || $i === $total_pages || ($i >= $current_page - 2 && $i <= $current_page + 2)): ?>
                                <a href="<?= htmlspecialchars($page_base . $page_joiner . $i) ?>" class="pagination-btn <?= ($i === $current_page) ? 'active' : '' ?>"><?= $i ?></a>
                            <?php elseif ($i === $current_page - 3 || $i === $current_page + 3): ?>
                                <span class="text-[--text-main]/20 text-[10px] font-black mx-1">...</span>
                            <?php endif; ?>
                        <?php endfor; ?>
                        <a href="<?= htmlspecialchars($page_base . $page_joiner . min($total_pages, $current_page + 1)) ?>" class="pagination-btn <?= ($current_page >= $total_pages) ? 'disabled' : '' ?>">Next</a>
                    </div>
                </div>
            </div>
        </main>
    </div>
    <div id="confirmModal">
        <div id="confirmBackdrop" class="modal-backdrop" onclick="closeConfirmModal()"></div>
        <div class="modal-container p-8 text-center">
            <div
                class="size-16 rounded-2xl bg-primary/10 border border-primary/20 flex items-center justify-center mx-auto mb-6">
                <span class="material-symbols-rounded text-3xl text-primary">contact_support</span>
            </div>
            <h3 id="confirmTitle" class="text-xl font-black italic uppercase tracking-tighter mb-2 text-white">Confirm
                Action</h3>
            <p id="confirmMessage" class="text-[--text-main]/60 text-xs font-medium leading-relaxed mb-8"></p>
            <div class="flex gap-3">
                <button onclick="closeConfirmModal()"
                    class="flex-1 py-3 px-6 rounded-xl bg-white/5 hover:bg-white/10 border border-white/5 text-[10px] font-black uppercase tracking-widest transition-all text-[--text-main]/40 hover:text-white">Cancel</button>
                <button onclick="executeConfirmedAction()"
                    class="flex-1 py-3 px-6 rounded-xl bg-primary hover:bg-primary/90 text-white text-[10px] font-black uppercase italic tracking-widest shadow-lg shadow-primary/20 transition-all active:scale-[0.98]">Confirm</button>
            </div>
        </div>
    </div>

    <!-- Transaction Detail Modal -->
    <div id="detailModal">
        <div id="detailBackdrop" class="modal-backdrop" onclick="closeDetailModal()"></div>
        <div class="modal-container overflow-hidden">
            <div class="p-8 border-b border-white/5 flex justify-between items-center bg-white/[0.02]">
                <div class="flex items-center gap-5 min-w-0">
                    <div class="size-20 rounded-2xl bg-white/5 border border-white/10 overflow-hidden flex items-center justify-center shadow-lg relative shrink-0" id="dt_avatar">
                        <span class="text-primary font-black text-2xl">J</span>
                    </div>
                    <div class="min-w-0">
                        <h4 class="text-xl font-black uppercase tracking-tight text-[--text-main] leading-tight truncate" id="dt_name">John Doe</h4>
                        <p class="text-[10px] font-bold text-[--text-main]/40 uppercase tracking-widest mt-1" id="dt_ref">TRX-000000</p>
                    </div>
                </div>
                <button onclick="closeDetailModal()"
                    class="size-10 rounded-xl bg-white/5 hover:bg-rose-500/20 hover:text-rose-500 transition-all flex items-center justify-center border border-white/5 text-[--text-main]/60 shrink-0">
                    <span class="material-symbols-rounded text-xl">close</span>
                </button>
            </div>

            <div class="hidden">
                <div class="glass-card p-6 border-white/5 bg-white/[0.02]">
                    <p class="text-[10px] font-black uppercase text-primary mb-4 tracking-widest">Member Information</p>
                    <div class="flex items-center gap-4">
                        <div class="size-12 rounded-2xl bg-primary/10 flex items-center justify-center overflow-hidden" id="dt_avatar">
                            <span class="text-primary font-black italic text-xl">J</span>
                        </div>
                        <div>
                            <p class="text-base font-black italic uppercase text-white" id="dt_name">John Doe</p>
                            <p class="text-[11px] font-bold text-[--text-main]/40" id="dt_username">@johndoe</p>
                        </div>
                    </div>
                </div>

                <div class="w-full grid grid-cols-2 gap-4">
                    <div class="glass-card p-5 border-white/5 bg-white/[0.02]">
                        <p class="text-[9px] font-black uppercase text-[--text-main]/40 mb-1 tracking-widest">Amount
                            Paid</p>
                        <p class="text-lg font-black italic text-white" id="dt_amount">₱0.00</p>
                    </div>
                    <div class="glass-card p-5 border-white/5 bg-white/[0.02]">
                        <p class="text-[9px] font-black uppercase text-[--text-main]/40 mb-1 tracking-widest">Payment
                            Type</p>
                        <span class="text-[10px] font-black uppercase italic text-primary" id="dt_type">OFFLINE</span>
                    </div>
                </div>

                <div class="w-full glass-card p-5 border-white/5 bg-white/[0.02] flex justify-between items-center">
                    <div>
                        <p class="text-[9px] font-black uppercase text-[--text-main]/40 mb-1 tracking-widest">
                            Transaction Date</p>
                        <p class="text-xs font-bold text-white italic" id="dt_date">Jan 01, 2024</p>
                    </div>
                    <div class="text-right">
                        <p class="text-[9px] font-black uppercase text-[--text-main]/40 mb-1 tracking-widest">Status</p>
                        <span
                            class="px-3 py-1 rounded-full border text-[8px] font-black uppercase italic tracking-widest"
                            id="dt_status">PENDING</span>
                    </div>
                </div>
            </div>

            <div class="p-8 space-y-6 text-left max-h-[70vh] overflow-y-auto no-scrollbar">
                <section class="grid grid-cols-1 sm:grid-cols-2 gap-6 bg-white/[0.02] p-6 rounded-2xl border border-white/5">
                    <div class="space-y-1">
                        <p class="text-[10px] font-bold uppercase tracking-widest text-[--text-main]/40">Transaction Type</p>
                        <p class="text-sm font-bold text-primary uppercase tracking-wider" id="dt_type_clean">Membership</p>
                    </div>
                    <div class="space-y-1">
                        <p class="text-[10px] font-bold uppercase tracking-widest text-[--text-main]/40">Amount</p>
                        <p class="text-sm font-black text-[--text-main]" id="dt_amount_clean">&#8369;0.00</p>
                    </div>
                </section>

                <section class="grid grid-cols-1 sm:grid-cols-3 gap-6">
                    <div class="bg-white/[0.02] p-5 rounded-2xl border border-white/5 space-y-1">
                        <p class="text-[9px] font-bold uppercase tracking-widest text-[--text-main]/40">Date</p>
                        <p class="text-xs font-bold text-[--text-main]" id="dt_date_clean">Jan 01, 2024</p>
                    </div>
                    <div class="bg-white/[0.02] p-5 rounded-2xl border border-white/5 space-y-1">
                        <p class="text-[9px] font-bold uppercase tracking-widest text-[--text-main]/40">Time</p>
                        <p class="text-xs font-bold text-[--text-main]" id="dt_time_clean">12:00 PM</p>
                    </div>
                    <div class="bg-white/[0.02] p-5 rounded-2xl border border-white/5 space-y-1">
                        <p class="text-[9px] font-bold uppercase tracking-widest text-[--text-main]/40">Status</p>
                        <span class="inline-flex px-3 py-1 rounded-full border text-[8px] font-black uppercase tracking-widest" id="dt_status_clean">PENDING</span>
                    </div>
                </section>

                <section class="grid grid-cols-1 sm:grid-cols-2 gap-6 bg-white/[0.02] p-6 rounded-2xl border border-white/5">
                    <div class="space-y-1">
                        <p class="text-[10px] font-bold uppercase tracking-widest text-[--text-main]/40">Plan</p>
                        <p class="text-sm font-bold text-[--text-main]" id="dt_plan_clean">Membership Plan</p>
                    </div>
                    <div class="space-y-1">
                        <p class="text-[10px] font-bold uppercase tracking-widest text-[--text-main]/40">Subscription</p>
                        <p class="text-sm font-bold text-[--text-main]" id="dt_subscription_clean">N/A</p>
                    </div>
                    <div class="space-y-1">
                        <p class="text-[10px] font-bold uppercase tracking-widest text-[--text-main]/40">Email</p>
                        <p class="text-sm font-medium text-[--text-main]/70 truncate" id="dt_email_clean">N/A</p>
                    </div>
                    <div class="space-y-1">
                        <p class="text-[10px] font-bold uppercase tracking-widest text-[--text-main]/40">Contact</p>
                        <p class="text-sm font-medium text-[--text-main]/70" id="dt_contact_clean">N/A</p>
                    </div>
                </section>
            </div>
        </div>
    </div>

    <script>
        let pendingForm = null;

        function confirmAction(form, title, message) {
            pendingForm = form;
            document.getElementById('confirmTitle').textContent = title;
            document.getElementById('confirmMessage').textContent = message;

            const modal = document.getElementById('confirmModal');
            modal.classList.add('active', 'flex-important');
            document.getElementById('confirmBackdrop').classList.add('active');
        }

        function closeConfirmModal() {
            const modal = document.getElementById('confirmModal');
            document.getElementById('confirmBackdrop').classList.remove('active');
            modal.classList.remove('active', 'flex-important');
            pendingForm = null;
        }

        function executeConfirmedAction() {
            if (pendingForm) {
                pendingForm.submit();
            }
        }

        function openDetailModal(data) {
            document.getElementById('dt_ref').textContent = data.ref || ('TRX-' + data.id);
            document.getElementById('dt_name').textContent = data.name;
            const avatar = document.getElementById('dt_avatar');
            avatar.innerHTML = `<span class="text-primary font-black text-2xl">${data.name.charAt(0)}</span>`;
            document.getElementById('dt_amount').textContent = '₱' + parseFloat(data.amount).toLocaleString(undefined, { minimumFractionDigits: 2 });
            document.getElementById('dt_type_clean').textContent = data.type;
            document.getElementById('dt_amount_clean').textContent = 'PHP ' + parseFloat(data.amount).toLocaleString(undefined, { minimumFractionDigits: 2 });
            document.getElementById('dt_date_clean').textContent = data.dateOnly || data.date;
            document.getElementById('dt_time_clean').textContent = data.timeOnly || '';
            document.getElementById('dt_plan_clean').textContent = data.plan || 'Membership Plan';
            document.getElementById('dt_subscription_clean').textContent = data.subscriptionStatus || 'N/A';
            document.getElementById('dt_email_clean').textContent = data.email || 'N/A';
            document.getElementById('dt_contact_clean').textContent = data.contact || 'N/A';

            const statusEl = document.getElementById('dt_status_clean');
            statusEl.textContent = data.status;
            statusEl.className = 'inline-flex px-3 py-1 rounded-full border text-[8px] font-black uppercase tracking-widest ' + data.statusClass;

            const modal = document.getElementById('detailModal');
            modal.classList.add('active', 'flex-important');
            document.getElementById('detailBackdrop').classList.add('active');
        }

        function closeDetailModal() {
            const modal = document.getElementById('detailModal');
            document.getElementById('detailBackdrop').classList.remove('active');
            modal.classList.remove('active', 'flex-important');
        }

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeConfirmModal();
                closeDetailModal();
            }
        });
    </script>
</body>

</html>
