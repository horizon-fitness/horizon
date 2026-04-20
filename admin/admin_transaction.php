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
$search = $_GET['search'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

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

$where_clause = "WHERE " . implode(' AND ', $sql_parts);

$sql = "
        SELECT p.*, u.first_name, u.last_name, u.username 
        FROM payments p 
        JOIN members m ON p.member_id = m.member_id 
        JOIN users u ON m.user_id = u.user_id 
        $where_clause 
        ORDER BY p.created_at DESC
    ";

$stmtPayments = $pdo->prepare($sql);
$stmtPayments->execute($sql_params);
$payments_list = $stmtPayments->fetchAll();

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

        .badge-surface {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        #confirmModal,
        #detailModal {
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            left: 0;
            z-index: 200;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, 0.85);
            backdrop-filter: blur(12px);
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
            background: var(--background);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 32px;
            transform: scale(0.95);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            opacity: 0;
            visibility: hidden;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            position: relative;
            overflow: hidden;
        }

        #detailModal .modal-container {
            max-width: 512px;
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

            <!-- Dynamic Filter Matrix -->
            <div class="mb-10">
                <form method="GET" class="glass-card p-8 relative overflow-hidden">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 items-end">
                        <div class="space-y-2 lg:col-span-1">
                            <p class="text-[--text-main]/60 text-[10px] font-black uppercase tracking-widest ml-1">
                                Identity Search</p>
                            <div class="relative">
                                <span
                                    class="material-symbols-rounded absolute left-4 top-1/2 -translate-y-1/2 text-[--text-main]/40 text-lg">search</span>
                                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                                    placeholder="Search member name..." class="input-box pl-12 w-full">
                            </div>
                        </div>

                        <div class="space-y-2">
                            <p class="text-[--text-main]/60 text-[10px] font-black uppercase tracking-widest ml-1">Date
                                Offset (From)</p>
                            <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>"
                                class="input-box w-full">
                        </div>

                        <div class="space-y-2">
                            <p class="text-[--text-main]/60 text-[10px] font-black uppercase tracking-widest ml-1">Date
                                Limit (To)</p>
                            <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>"
                                class="input-box w-full">
                        </div>

                        <div class="flex gap-3">
                            <button type="submit"
                                class="flex-1 bg-primary hover:bg-primary/90 text-white h-[46px] rounded-xl text-[10px] font-black uppercase tracking-widest transition-all shadow-lg shadow-primary/20 active:scale-95">Apply
                                Filter</button>
                            <button type="button" onclick="clearTransactionFilters()"
                                class="size-[46px] rounded-xl bg-white/5 border border-white/5 flex items-center justify-center text-[--text-main]/40 hover:bg-rose-500/10 hover:text-rose-500 transition-all group active:scale-95">
                                <span
                                    class="material-symbols-rounded text-xl group-hover:rotate-180 transition-transform">restart_alt</span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <div class="flex justify-between items-center mb-6">
                <p class="text-[10px] font-black uppercase tracking-widest text-[--text-main]/40 italic">TRANSACTION
                    LOGS — <span class="text-[--text-main]">LIVE FEED</span></p>
                <div class="flex items-center gap-4">
                    <span
                        class="px-4 py-1.5 rounded-full bg-white/5 border border-white/5 text-[9px] font-black uppercase tracking-widest text-[--text-main]/60">Total
                        Entries: <?= count($payments_list) ?></span>
                </div>
            </div>

            <div class="glass-card shadow-2xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr
                                class="text-[10px] font-black uppercase tracking-[0.15em] text-[--text-main]/40 border-b border-white/5 bg-white/[0.01]">
                                <th class="px-8 py-5">Member Profile</th>
                                <th class="px-8 py-5 text-center">Amount</th>
                                <th class="px-8 py-5 text-center">Transaction Type</th>
                                <th class="px-8 py-5">Date &amp; Time</th>
                                <th class="px-8 py-5 text-center">Status</th>
                                <th class="px-8 py-5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <?php if (empty($payments_list)): ?>
                                <tr>
                                    <td colspan="6" class="px-8 py-24 text-center">
                                        <span
                                            class="material-symbols-rounded text-4xl text-[--text-main]/20 mb-4 block">receipt_long</span>
                                        <p
                                            class="text-[10px] font-black uppercase tracking-widest text-[--text-main]/40 italic">
                                            No transactions found.</p>
                                    </td>
                                </tr>
                            <?php else:
                                foreach ($payments_list as $pay): ?>
                                    <tr class="hover:bg-white/[0.02] group transition-colors">
                                        <td class="px-8 py-6">
                                            <div class="flex items-center gap-4">
                                                <div
                                                    class="size-10 rounded-2xl bg-primary/10 border border-primary/20 flex items-center justify-center text-primary font-black italic text-base">
                                                    <?= substr($pay['first_name'] ?? 'U', 0, 1) ?>
                                                </div>
                                                <div>
                                                    <p
                                                        class="text-[13px] font-black italic uppercase text-white group-hover:text-primary transition-colors">
                                                        <?= htmlspecialchars(($pay['first_name'] ?? '') . ' ' . ($pay['last_name'] ?? '')) ?>
                                                    </p>
                                                    <p
                                                        class="text-[10px] font-bold text-[--text-main]/40 tracking-tight lowercase">
                                                        @<?= htmlspecialchars($pay['username'] ?? 'unknown') ?></p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-8 py-6 text-center">
                                            <p class="text-sm font-black italic text-white tracking-tight">
                                                ₱<?= number_format($pay['amount'], 2) ?></p>
                                        </td>
                                        <td class="px-8 py-6 text-center">
                                            <span
                                                class="px-4 py-1.5 rounded-xl badge-surface text-[9px] font-black uppercase tracking-[0.1em] text-[--text-main]/60 italic"><?= htmlspecialchars($pay['payment_type'] ?? 'OFFLINE') ?></span>
                                        </td>
                                        <td class="px-8 py-6">
                                            <div class="space-y-0.5 text-left">
                                                <p class="text-[11px] font-black italic text-white uppercase">
                                                    <?= date('h:i A', strtotime($pay['created_at'])) ?></p>
                                                <p
                                                    class="text-[9px] font-bold text-[--text-main]/20 uppercase tracking-widest italic">
                                                    <?= date('M d, Y', strtotime($pay['created_at'])) ?></p>
                                            </div>
                                        </td>
                                        <td class="px-8 py-6 text-center">
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
                                                class="px-4 py-1.5 rounded-full border text-[9px] font-extrabold uppercase italic tracking-widest <?= $status_class ?>"><?= $status ?></span>
                                        </td>
                                        <td class="px-8 py-6 text-right">
                                            <div class="flex justify-end gap-2">
                                                <button type="button" onclick='openDetailModal({
                                                id: "<?= $pay["payment_id"] ?>",
                                                name: "<?= htmlspecialchars($pay["first_name"] . " " . $pay["last_name"]) ?>",
                                                username: "<?= htmlspecialchars($pay["username"] ?? "unknown") ?>",
                                                amount: "<?= $pay["amount"] ?>",
                                                type: "<?= htmlspecialchars($pay["payment_type"] ?? "OFFLINE") ?>",
                                                date: "<?= date("M d, Y h:i A", strtotime($pay["created_at"])) ?>",
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
        <div class="modal-container p-10 flex flex-col items-center">
            <div class="w-full flex justify-between items-start mb-8">
                <div>
                    <h3 class="text-2xl font-black italic uppercase tracking-tighter text-white leading-none">
                        Transaction <span class="text-primary">Details</span></h3>
                    <p class="text-[10px] font-bold text-[--text-main]/40 uppercase tracking-widest mt-2" id="dt_ref">
                        REF-000000</p>
                </div>
                <button onclick="closeDetailModal()"
                    class="size-10 rounded-xl bg-white/5 flex items-center justify-center hover:bg-rose-500/20 hover:text-rose-500 transition-all">
                    <span class="material-symbols-rounded text-xl">close</span>
                </button>
            </div>

            <div class="w-full space-y-6">
                <div class="glass-card p-6 border-white/5 bg-white/[0.02]">
                    <p class="text-[10px] font-black uppercase text-primary mb-4 tracking-widest">Member Information</p>
                    <div class="flex items-center gap-4">
                        <div class="size-12 rounded-2xl bg-primary/10 border border-primary/20 flex items-center justify-center text-primary font-black italic text-xl"
                            id="dt_avatar">J</div>
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

            <button onclick="closeDetailModal()"
                class="w-full mt-8 py-4 rounded-2xl bg-white/5 hover:bg-white/10 border border-white/5 text-[11px] font-black uppercase tracking-[0.2em] transition-all text-white active:scale-[0.98]">
                Dismiss Record
            </button>
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
            document.getElementById('dt_ref').textContent = 'TRX-' + (data.ref || data.id);
            document.getElementById('dt_name').textContent = data.name;
            document.getElementById('dt_username').textContent = '@' + data.username;
            document.getElementById('dt_avatar').textContent = data.name.charAt(0);
            document.getElementById('dt_amount').textContent = '₱' + parseFloat(data.amount).toLocaleString(undefined, { minimumFractionDigits: 2 });
            document.getElementById('dt_type').textContent = data.type;
            document.getElementById('dt_date').textContent = data.date;

            const statusEl = document.getElementById('dt_status');
            statusEl.textContent = data.status;
            statusEl.className = 'px-3 py-1 rounded-full border text-[8px] font-black uppercase italic tracking-widest ' + data.statusClass;

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