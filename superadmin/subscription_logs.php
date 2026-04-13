<?php
session_start();
require_once '../db.php';
require_once '../includes/mailer.php';

// Security Check: Only Superadmin can access
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'superadmin') {
    header("Location: ../login.php");
    exit;
}

// --- HANDLE AJAX PAYMONGO REFERENCE FETCH ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'fetch_paymongo_ref') {
    header('Content-Type: application/json');
    require_once '../includes/paymongo-helper.php';
    
    $payment_id = $_POST['payment_id'] ?? 0;
    try {
        $stmtP = $pdo->prepare("SELECT payment_id, remarks, reference_number FROM payments WHERE payment_id = ?");
        $stmtP->execute([$payment_id]);
        $payment = $stmtP->fetch();

        if (!$payment) throw new Exception("Payment record not found.");

        // Extract Session ID from remarks (Expected: "... Session: cs_...")
        if (preg_match('/Session:\s*(cs_[a-zA-Z0-9]+)/', $payment['remarks'], $matches)) {
            $session_id = $matches[1];
            $response = retrieve_checkout_session($session_id);
            
            if ($response['status'] === 200) {
                $attributes = $response['body']['data']['attributes'];
                if (isset($attributes['payments'][0]['id'])) {
                    $real_ref = $attributes['payments'][0]['id'];
                    
                    // Update DB to persist the real reference
                    $stmtUpdate = $pdo->prepare("UPDATE payments SET reference_number = ? WHERE payment_id = ?");
                    $stmtUpdate->execute([$real_ref, $payment_id]);
                    
                    echo json_encode(['success' => true, 'ref_no' => $real_ref]);
                    exit;
                } else {
                    throw new Exception("Payment ID not found in checkout session attributes.");
                }
            } else {
                throw new Exception("PayMongo API Error: " . ($response['body']['errors'][0]['detail'] ?? 'Unknown error'));
            }
        } else {
            throw new Exception("No PayMongo Session ID found in payment remarks.");
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// --- HANDLE POST ACTIONS (Approve/Reject) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['subscription_id'])) {
    $sub_id = $_POST['subscription_id'];
    $action = $_POST['action'];
    $admin_id = $_SESSION['user_id'];

    try {
        // Fetch Subscriber Data for Email
        $stmtData = $pdo->prepare("
            SELECT cs.*, g.gym_name, g.email as gym_email, u.email as owner_email, wp.plan_name 
            FROM client_subscriptions cs
            JOIN gyms g ON cs.gym_id = g.gym_id
            JOIN users u ON g.owner_user_id = u.user_id
            JOIN website_plans wp ON cs.website_plan_id = wp.website_plan_id
            WHERE cs.client_subscription_id = ?
        ");
        $stmtData->execute([$sub_id]);
        $subData = $stmtData->fetch();

        if (!$subData) throw new Exception("Subscription records not found for ID: $sub_id");

        $pdo->beginTransaction();

        $email_sent = false;
        if ($action === 'approve_payment') {
            // Update subscription to Paid and Active
            $stmt = $pdo->prepare("UPDATE client_subscriptions SET payment_status = 'Paid', subscription_status = 'Active' WHERE client_subscription_id = ?");
            $stmt->execute([$sub_id]);

            // Sync with payments table (Handle both Full and Installment types)
            $stmtPay = $pdo->prepare("UPDATE payments SET payment_status = 'Paid', verified_by = ?, verified_at = NOW() WHERE client_subscription_id = ? AND payment_type IN ('Subscription', 'Subscription Installment')");
            $stmtPay->execute([$admin_id, $sub_id]);

            $msg = "Approved payment for Subscription #$sub_id (" . $subData['gym_name'] . ")";

            // Prepare Email
            $subject = "Payment Confirmed - Your " . $subData['plan_name'] . " is now Active!";
            $content = "
                <p>Hello,</p>
                <p>We are pleased to inform you that your payment for <strong>" . htmlspecialchars($subData['gym_name']) . "</strong> has been approved.</p>
                <p>Your <strong>" . htmlspecialchars($subData['plan_name']) . "</strong> subscription is now active. You and your members can now enjoy all the premium features and management tools provided by the Horizon System.</p>
                <div style='margin: 30px 0; padding: 20px; background: #f8f9fa; border-radius: 8px;'>
                    <p style='margin: 0; font-size: 14px;'><strong>Plan:</strong> " . htmlspecialchars($subData['plan_name']) . "</p>
                    <p style='margin: 5px 0 0; font-size: 14px;'><strong>Status:</strong> Active</p>
                </div>
                <p>Thank you for choosing Horizon!</p>
            ";
            $email_sent = sendSystemEmail($subData['gym_email'], $subject, getEmailTemplate("Payment Approved", $content));

        } elseif ($action === 'reject_payment') {
            // Update subscription to Rejected and Inactive
            $stmt = $pdo->prepare("UPDATE client_subscriptions SET payment_status = 'Rejected', subscription_status = 'Inactive' WHERE client_subscription_id = ?");
            $stmt->execute([$sub_id]);

            // Sync with payments table (Handle both Full and Installment types)
            $stmtPay = $pdo->prepare("UPDATE payments SET payment_status = 'Rejected', verified_by = ?, verified_at = NOW() WHERE client_subscription_id = ? AND payment_type IN ('Subscription', 'Subscription Installment')");
            $stmtPay->execute([$admin_id, $sub_id]);

            $msg = "Rejected subscription payment for #" . $sub_id . " (" . $subData['gym_name'] . ")";

            // Prepare High-Impact Rejection Email
            $subject = "Subscription Payment Rejected - Action Required for " . $subData['gym_name'];
            $content = "
                <p>Hello,</p>
                <p>We are writing to professionally inform you that your recent payment for the <strong>" . htmlspecialchars($subData['plan_name']) . "</strong> plan for <strong>" . htmlspecialchars($subData['gym_name']) . "</strong> was not verified and has been <strong>Rejected</strong>.</p>
                
                <div style='margin: 30px 0; padding: 25px; background: #fff5f5; border: 1px solid #feb2b2; border-radius: 12px; border-left: 5px solid #f56565;'>
                    <h3 style='margin: 0 0 10px; color: #c53030; font-size: 16px;'>Why was this rejected?</h3>
                    <p style='margin: 0; font-size: 14px; color: #742a2a;'>Common reasons include invalid proof of payment, transaction mismatch, or an expired checkout session. Your current subscription has been set to <strong>Inactive</strong>.</p>
                </div>

                <h4 style='margin: 0 0 10px; color: #333;'>Next Steps:</h4>
                <ol style='color: #555; padding-left: 20px;'>
                    <li>Log in to your Horizon Partner Portal.</li>
                    <li>Navigate to the 'Subscription Plan' section.</li>
                    <li>Select your desired plan again and ensure payment completion via PayMongo.</li>
                    <li>If you believe this was an error, please contact support immediately with your reference number.</li>
                </ol>

                <p style='margin-top: 30px;'>Thank you for your cooperation.</p>
            ";
            $email_sent = sendSystemEmail($subData['gym_email'], $subject, getEmailTemplate("Payment Verification Failed", $content));
        }

        // Log the action with Email status
        $audit_msg = $msg . ($email_sent ? " (Email Sent Successfully)" : " (Email Failed to Send)");
        $stmtAudit = $pdo->prepare("INSERT INTO audit_logs (user_id, gym_id, action_type, table_name, record_id, old_values, new_values, created_at) VALUES (?, ?, 'Update', 'client_subscriptions', ?, '', ?, NOW())");
        $stmtAudit->execute([$admin_id, $subData['gym_id'], $sub_id, $audit_msg]);

        $pdo->commit();
        $_SESSION['success_msg'] = "Action successful: $msg" . (!$email_sent ? " (Email failed, but status updated)" : "");
        header("Location: subscription_logs.php?tab=pending");
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error_msg'] = "Error: " . $e->getMessage();
        header("Location: subscription_logs.php?tab=pending");
        exit;
    }
}

$page_title = "Subscription Logs";
$active_page = "subscriptions";

// --- FILTER INPUTS ---
$search = $_GET['search'] ?? '';
$sub_status = $_GET['sub_status'] ?? 'all';
$pay_status = $_GET['pay_status'] ?? 'all';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$active_tab = $_GET['tab'] ?? 'recent'; // Track active tab for persistence

// 4-Color Elite Branding System: Fetching & Merging Settings
if (!function_exists('hexToRgb')) {
// ... existing hexToRgb ...
    function hexToRgb($hex)
    {
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

// Helper for robust logo pathing
if (!function_exists('getLogoPath')) {
    function getLogoPath($path)
    {
        if (empty($path))
            return '';
        if (strpos($path, 'data:') === 0 || strpos($path, 'http') === 0)
            return $path;
        if (strpos($path, 'uploads/') === 0)
            return '../' . $path;
        if (strpos($path, '../') === 0)
            return $path;
        return '../uploads/applications/' . $path;
    }
}

// 1. Fetch Global Settings (user_id = 0)
$stmtGlobal = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE user_id = 0");
$global_configs = $stmtGlobal->fetchAll(PDO::FETCH_KEY_PAIR);

// 2. Fetch User-Specific Settings (Personal Branding)
$stmtUser = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = ?");
$stmtUser->execute([$_SESSION['user_id']]);
$user_configs = $stmtUser->fetchAll(PDO::FETCH_KEY_PAIR);

// 3. Merge (User settings take precedence)
$brand = array_merge($global_configs, $user_configs);

// --- FETCH LOGS WITH FILTERS ---
$query = "
    SELECT cs.*, 
           g.gym_name, g.tenant_code, g.owner_user_id,
           wp.plan_name, wp.price as plan_price, wp.billing_cycle,
            (SELECT setting_value FROM system_settings WHERE user_id = g.owner_user_id AND setting_key = 'system_logo') as gym_logo,
            p.reference_number as ref_no,
            p.payment_id,
            p.remarks as payment_remarks
    FROM client_subscriptions cs
    JOIN gyms g ON cs.gym_id = g.gym_id
    JOIN website_plans wp ON cs.website_plan_id = wp.website_plan_id
    LEFT JOIN payments p ON cs.client_subscription_id = p.client_subscription_id AND p.payment_type IN ('Subscription', 'Subscription Installment')
    WHERE cs.created_at BETWEEN :start AND :end
";

$params = [
    'start' => $date_from . ' 00:00:00',
    'end' => $date_to . ' 23:59:59'
];

if ($sub_status !== 'all') {
    $query .= " AND cs.subscription_status = :sub_status";
    $params['sub_status'] = $sub_status;
}

if ($pay_status !== 'all') {
    $query .= " AND cs.payment_status = :pay_status";
    $params['pay_status'] = $pay_status;
}

if (!empty($search)) {
    $query .= " AND (g.gym_name LIKE :s1 OR g.tenant_code LIKE :s2)";
    $params['s1'] = "%$search%";
    $params['s2'] = "%$search%";
}

$query .= " ORDER BY cs.created_at DESC";
$stmtLogs = $pdo->prepare($query);
$stmtLogs->execute($params);
$logs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);

// Separate Categories for Tabs
$pending_logs = array_filter($logs, fn($l) => $l['payment_status'] === 'Pending');
$recent_date = date('Y-m-d', strtotime('-7 days'));
$recent_logs = array_filter($logs, fn($l) => $l['start_date'] >= $recent_date && $l['payment_status'] !== 'Pending');
$history_logs = array_filter($logs, fn($l) => $l['payment_status'] !== 'Pending');

// Metrics
$total_subs = count($logs);
$active_subs = 0;
$expired_subs = 0;
$pending_payment = count($pending_logs);
foreach ($logs as $log) {
    if ($log['subscription_status'] === 'Active') $active_subs++;
    if ($log['subscription_status'] === 'Expired') $expired_subs++;
}
?>
<!DOCTYPE html>
<html class="dark no-scrollbar" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title><?= $page_title ?> | Horizon System</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { 
                extend: { 
                    colors: { 
                        "primary": "var(--primary)", 
                        "background": "var(--background)", 
                        "highlight": "var(--highlight)",
                        "text-main": "var(--text-main)",
                        "surface-dark": "#14121a", 
                        "border-subtle": "rgba(255,255,255,0.05)"
                    }
                }
            }
        }
    </script>
    <style>
        :root {
            --primary: <?= $brand['theme_color'] ?? '#8c2bee' ?>;
            --primary-rgb: <?= hexToRgb($brand['theme_color'] ?? '#8c2bee') ?>;
            --highlight: <?= $brand['secondary_color'] ?? '#a1a1aa' ?>;
            --text-main: <?= $brand['text_color'] ?? '#d1d5db' ?>;
            --background: <?= $brand['bg_color'] ?? '#0a090d' ?>;

            /* Glassmorphism Engine */
            --card-blur: 20px;
            --card-bg: <?= ($brand['auto_card_theme'] ?? '1') === '1' ? 'rgba(' . hexToRgb($brand['theme_color'] ?? '#8c2bee') . ', 0.05)' : ($brand['card_color'] ?? '#141216') ?>;
        }

        body { 
            font-family: '<?= $brand['font_family'] ?? 'Lexend' ?>', sans-serif; 
            background-color: var(--background); 
            color: var(--text-main); 
        }

        .glass-card { 
            background: var(--card-bg); 
            border: 1px solid rgba(255,255,255,0.05); 
            border-radius: 24px; 
            backdrop-filter: blur(var(--card-blur));
        }

        input[type="date"], select {
            color-scheme: dark;
        }

        select option {
            background-color: var(--background);
            color: var(--text-main);
        }
        
        /* Unified Sidebar Width Variable Scoping */
        :root {
            --sidebar-width: 110px;
        }

        .sidebar-nav {
            width: var(--sidebar-width);
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            background: var(--background);
            border-right: 1px solid rgba(255,255,255,0.05);
            z-index: 250;
        }
        .sidebar-nav:hover {
            --sidebar-width: 300px;
        }
        .nav-text {
            opacity: 0 !important;
            visibility: hidden !important;
            transform: translateX(-15px);
            transition: all 0.3s ease-in-out;
            white-space: nowrap;
            pointer-events: none;
        }
        .sidebar-nav:hover .nav-text {
            opacity: 1 !important;
            visibility: visible !important;
            transform: translateX(0);
            pointer-events: auto;
        }

        .nav-section-header {
            max-height: 0;
            opacity: 0 !important;
            visibility: hidden !important;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            margin: 0 !important;
            pointer-events: none;
        }
        .sidebar-nav:hover .nav-section-header {
            max-height: 20px;
            opacity: 1 !important;
            visibility: visible !important;
            margin-bottom: 0.5rem !important;
            pointer-events: auto;
        }
        /* Adjusted for zero-gap between sections on hover */
        .sidebar-nav:hover .nav-section-header.mt-4 { margin-top: 0.25rem !important; }
        .sidebar-nav:hover .nav-section-header.mt-6 { margin-top: 0.5rem !important; }

        .sidebar-content {
            gap: 2px;
            transition: all 0.3s ease-in-out;
            padding-bottom: 8rem;
        }
        .sidebar-nav:hover .sidebar-content {
            gap: 4px;
        }

        .nav-link { 
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 10px 38px;
            transition: all 0.2s; 
            white-space: nowrap; 
            font-size: 11px; 
            font-weight: 800; 
            letter-spacing: 0.05em; 
            color: var(--text-main);
            text-decoration: none;
        }
        .nav-link span.material-symbols-outlined {
            color: var(--highlight);
            opacity: 0.7;
            transition: all 0.3s ease;
        }
        .nav-link:hover {
            opacity: 0.8;
            transform: scale(1.02);
        }
        .active-nav { color: var(--primary) !important; position: relative; }
        .active-nav span.material-symbols-outlined { color: var(--primary) !important; opacity: 1 !important; }
        .active-nav::after { 
            content: ''; 
            position: absolute; 
            right: 0px; 
            top: 50%;
            transform: translateY(-50%);
            width: 4px; 
            height: 24px; 
            background: var(--primary); 
            border-radius: 4px 0 0 4px; 
            opacity: 1;
            transition: opacity 0.3s ease;
        }
        
        @media (max-width: 1023px) {
            .active-nav::after { display: none; }
        }
        .alert-pulse { animation: alert-pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite; }
        @keyframes alert-pulse { 0%, 100% { opacity: 1; } 50% { opacity: .5; } }
        
        .status-card-green { border: 1px solid #10b981; background: linear-gradient(135deg, rgba(16,185,129,0.05) 0%, rgba(20,18,26,1) 100%); }
        .status-card-yellow { border: 1px solid #f59e0b; background: linear-gradient(135deg, rgba(245,158,11,0.05) 0%, rgba(20,18,26,1) 100%); }
        .status-card-red { border: 1px solid #ef4444; background: linear-gradient(135deg, rgba(239,68,68,0.05) 0%, rgba(20,18,26,1) 100%); }
        .dashed-container { border: 2px dashed rgba(255,255,255,0.1); border-radius: 24px; }
        
        /* 1. Global Invisible Scroll System (CSS Reset) */
        *::-webkit-scrollbar { display: none !important; }
        * { -ms-overflow-style: none !important; scrollbar-width: none !important; }
        
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        /* 2. Sidebar-Aware Responsive Modal Architecture */
        .sidebar-aware-modal {
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            left: 110px; /* Collapsed Sidebar Width */
            z-index: 200;
            transition: left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            background: rgba(10, 9, 13, 0.8);
            backdrop-filter: blur(20px);
            display: none;
        }

        .sidebar-aware-modal.flex-important {
            display: flex !important;
            align-items: center;
            justify-content: center;
        }

        /* Sync Shift with Sidebar expansion */
        .sidebar-nav:hover ~ .sidebar-aware-modal {
            left: 300px; /* Expanded Sidebar Width */
        }

        /* Responsive Mobile Breakpoint */
        @media (max-width: 1023px) {
            .sidebar-aware-modal {
                left: 0 !important;
            }
        }
    </style>
    <script>
        function updateHeaderClock() {
            const now = new Date();
            const time = now.toLocaleTimeString('en-US', { hour12: true, hour: '2-digit', minute: '2-digit', second: '2-digit' });
            document.getElementById('headerClock').textContent = time;
        }
        setInterval(updateHeaderClock, 1000);
        window.addEventListener('DOMContentLoaded', () => {
            updateHeaderClock();
            
            // --- 2. Initialize Pagination for all Tables ---
            initPagination('recentTableBody', 'pagination-recent', 10);
            initPagination('pendingTableBody', 'pagination-pending', 10);
            initPagination('historyTableBody', 'pagination-history', 10);

            // --- 3. Handle Tab Persistence from URL ---
            const urlParams = new URLSearchParams(window.location.search);
            const activeTab = urlParams.get('tab');
            if (activeTab) {
                switchTab(activeTab);
            }
        });

        function switchTab(tabId) {
            // Sections
            const sections = ['section-recent', 'section-pending', 'section-history'];
            sections.forEach(s => {
                const el = document.getElementById(s);
                if (el) el.classList.add('hidden');
            });
            const activeSection = document.getElementById('section-' + tabId);
            if (activeSection) activeSection.classList.remove('hidden');

            // Buttons & Indicators
            const tabs = ['recent', 'pending', 'history'];
            tabs.forEach(t => {
                const btn = document.getElementById('tabBtn-' + t);
                const indicator = document.getElementById('tabIndicator-' + t);
                
                if (btn) {
                    btn.classList.remove('text-primary');
                    btn.classList.add('text-[--text-main]', 'opacity-50');
                }
                if (indicator) indicator.classList.replace('opacity-100', 'opacity-0');
            });

            const activeBtn = document.getElementById('tabBtn-' + tabId);
            const activeIndicator = document.getElementById('tabIndicator-' + tabId);
            if (activeBtn) {
                activeBtn.classList.add('text-primary');
                activeBtn.classList.remove('text-[--text-main]', 'opacity-50');
            }
            if (activeIndicator) activeIndicator.classList.replace('opacity-0', 'opacity-100');

            // Sync with hidden filter input
            const tabInput = document.getElementById('activeTabInput');
            if (tabInput) tabInput.value = tabId;

            // Context-Aware Filter Visibility
            const subFilter = document.getElementById('filterGroupSubStatus');
            const payFilter = document.getElementById('filterGroupPayStatus');
            if (tabId === 'pending') {
                if (subFilter) subFilter.classList.add('hidden');
                if (payFilter) payFilter.classList.add('hidden');
            } else {
                if (subFilter) subFilter.classList.remove('hidden');
                if (payFilter) payFilter.classList.remove('hidden');
            }
        }

        function confirmAdminAction(form, title, message) {
            const modal = document.getElementById('adminActionModal');
            if(!modal) return;
            
            modal.querySelector('#modalTitle').textContent = title;
            modal.querySelector('#modalMessage').textContent = message;
            
            modal.classList.add('flex-important');
            
            const confirmBtn = modal.querySelector('#confirm-btn');
            confirmBtn.onclick = () => {
                form.submit();
                modal.classList.remove('flex-important');
            };
        }
        
        function closeModal() {
            document.getElementById('adminActionModal').classList.remove('flex-important');
        }
    </script>
</head>
<body class="antialiased flex flex-row min-h-screen">

<?php include '../includes/superadmin_sidebar.php'; ?>

<div class="flex-1 flex flex-col min-w-0 overflow-y-auto no-scrollbar">
    <main class="flex-1 p-6 md:p-10 max-w-[1400px] w-full mx-auto">
        <header class="mb-10 flex flex-row justify-between items-end gap-6">
            <div>
                <h2 class="text-3xl font-black italic uppercase tracking-tighter leading-none">
                    <span class="text-[--text-main] opacity-80">Subscription</span>
                    <span class="text-primary">Logs</span>
                </h2>
                <p class="text-[--text-main] opacity-60 text-xs font-bold uppercase tracking-widest mt-2 px-1">Monitor Tenant Subscription Histories</p>
            </div>
            <div class="text-right">
                <p id="headerClock" class="text-[--text-main] font-black italic text-2xl leading-none transition-colors hover:text-primary uppercase tracking-tighter mb-2">00:00:00 AM</p>
                <p class="text-primary text-[10px] font-black uppercase tracking-[0.2em] leading-none"><?= date('l, M d, Y') ?></p>
            </div>
        </header>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="glass-card p-8 relative overflow-hidden group">
                <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform">receipt_long</span>
                <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">Total Logs</p>
                <h3 class="text-2xl font-black italic uppercase"><?= $total_subs ?></h3>
                <p class="text-[--text-main] opacity-40 text-[9px] font-black uppercase mt-2 tracking-tighter italic">History Archive</p>
            </div>
            <div class="glass-card p-8 status-card-green relative overflow-hidden group">
                <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 text-emerald-500 group-hover:scale-110 transition-transform">check_circle</span>
                <p class="text-[10px] font-black uppercase text-emerald-500/70 mb-2 tracking-widest">Active Plans</p>
                <h3 class="text-2xl font-black italic uppercase text-emerald-400"><?= $active_subs ?></h3>
                <p class="text-emerald-500/50 text-[9px] font-black uppercase mt-2 tracking-tighter italic">Current Active</p>
            </div>
            <div class="glass-card p-8 status-card-yellow relative overflow-hidden group">
                <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 text-amber-500 group-hover:scale-110 transition-transform">pending_actions</span>
                <p class="text-[10px] font-black uppercase text-amber-500/70 mb-2 tracking-widest">Pending Payment</p>
                <h3 class="text-2xl font-black italic uppercase text-amber-400"><?= $pending_payment ?></h3>
                <p class="text-amber-500/50 text-[9px] font-black uppercase mt-2 tracking-tighter italic">Awaiting Action</p>
            </div>
            <div class="glass-card p-8 status-card-red relative overflow-hidden group">
                <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 text-red-500 group-hover:scale-110 transition-transform">event_busy</span>
                <p class="text-[10px] font-black uppercase text-red-500/70 mb-2 tracking-widest">Expired</p>
                <h3 class="text-2xl font-black italic uppercase text-red-400"><?= $expired_subs ?></h3>
                <p class="text-red-500/50 text-[9px] font-black uppercase mt-2 tracking-tighter italic">Lapsed Plans</p>
            </div>
        </div>

        <?php if (isset($_SESSION['success_msg'])): ?>
            <div id="successAlert" class="mb-8 p-4 bg-emerald-500/10 border border-emerald-500/20 rounded-2xl flex items-center gap-4 animate-fadeIn transition-all duration-500">
                <span class="material-symbols-outlined text-emerald-500">check_circle</span>
                <p class="text-xs font-bold text-emerald-400"><?= $_SESSION['success_msg'] ?></p>
                <button onclick="document.getElementById('successAlert').style.opacity='0'; setTimeout(()=>document.getElementById('successAlert').remove(), 500)" class="ml-auto opacity-50 hover:opacity-100 transition-opacity">
                    <span class="material-symbols-outlined text-sm">close</span>
                </button>
                <?php unset($_SESSION['success_msg']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_msg'])): ?>
            <div id="errorAlert" class="mb-8 p-4 bg-rose-500/10 border border-rose-500/20 rounded-2xl flex items-center gap-4 animate-fadeIn transition-all duration-500">
                <span class="material-symbols-outlined text-rose-500">error</span>
                <p class="text-xs font-bold text-rose-400"><?= $_SESSION['error_msg'] ?></p>
                <button onclick="document.getElementById('errorAlert').style.opacity='0'; setTimeout(()=>document.getElementById('errorAlert').remove(), 500)" class="ml-auto opacity-50 hover:opacity-100 transition-opacity">
                    <span class="material-symbols-outlined text-sm">close</span>
                </button>
                <?php unset($_SESSION['error_msg']); ?>
            </div>
        <?php endif; ?>
        
        <!-- DYNAMIC FILTERS (Restored & Corrected Position) -->
        <div class="glass-card p-6 mb-8 border-white/5 shadow-2xl relative overflow-hidden">
            <form method="GET" class="flex flex-wrap items-end gap-6 relative z-10" id="filterForm">
                <input type="hidden" name="tab" id="activeTabInput" value="<?= htmlspecialchars($active_tab) ?>">
                <div class="w-[320px]">
                    <label class="text-[10px] font-black uppercase text-[--text-main] opacity-40 mb-3 block tracking-[0.2em] px-1">Search Identifier</label>
                    <div class="relative group">
                        <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-sm text-primary transition-transform group-hover:scale-110">search</span>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                               placeholder="Gym Name or Tenant Code..." 
                               class="w-full bg-white/5 border border-white/10 rounded-xl py-3 pl-12 pr-4 text-xs font-bold transition-all focus:border-primary focus:bg-white/[0.08] outline-none placeholder:text-white/20">
                    </div>
                </div>

                <div id="filterGroupSubStatus" class="w-[180px] <?= $active_tab === 'pending' ? 'hidden' : '' ?>">
                    <label class="text-[10px] font-black uppercase text-[--text-main] opacity-40 mb-3 block tracking-[0.2em] px-1">Sub Status</label>
                    <select name="sub_status" onchange="this.form.submit()" class="w-full bg-white/5 border border-white/10 rounded-xl py-3 px-4 text-xs font-bold transition-all focus:border-primary outline-none text-[--text-main]">
                        <option value="all" <?= $sub_status === 'all' ? 'selected' : '' ?>>All Status</option>
                        <option value="Active" <?= $sub_status === 'Active' ? 'selected' : '' ?>>Active Only</option>
                        <option value="Expired" <?= $sub_status === 'Expired' ? 'selected' : '' ?>>Expired Only</option>
                    </select>
                </div>

                <div id="filterGroupPayStatus" class="w-[180px] <?= $active_tab === 'pending' ? 'hidden' : '' ?>">
                    <label class="text-[10px] font-black uppercase text-[--text-main] opacity-40 mb-3 block tracking-[0.2em] px-1">Payment Status</label>
                    <select name="pay_status" onchange="this.form.submit()" class="w-full bg-white/5 border border-white/10 rounded-xl py-3 px-4 text-xs font-bold transition-all focus:border-primary outline-none text-[--text-main]">
                        <option value="all" <?= $pay_status === 'all' ? 'selected' : '' ?> class="text-white">All Payments</option>
                        <option value="Paid" <?= $pay_status === 'Paid' ? 'selected' : '' ?> class="text-emerald-400">Paid</option>
                        <option value="Pending" <?= $pay_status === 'Pending' ? 'selected' : '' ?> class="text-amber-400">Pending</option>
                        <option value="Rejected" <?= $pay_status === 'Rejected' ? 'selected' : '' ?> class="text-rose-400">Rejected</option>
                    </select>
                </div>

                <div class="flex gap-4">
                    <div class="w-[150px]">
                        <label class="text-[10px] font-black uppercase text-[--text-main] opacity-40 mb-3 block tracking-[0.2em] px-1">From</label>
                        <input type="date" name="date_from" value="<?= $date_from ?>" onchange="this.form.submit()" 
                               class="w-full bg-white/5 border border-white/10 rounded-xl py-3 px-4 text-xs font-bold transition-all focus:border-primary outline-none">
                    </div>
                    <div class="w-[150px]">
                        <label class="text-[10px] font-black uppercase text-[--text-main] opacity-40 mb-3 block tracking-[0.2em] px-1">To</label>
                        <input type="date" name="date_to" value="<?= $date_to ?>" onchange="this.form.submit()" 
                               class="w-full bg-white/5 border border-white/10 rounded-xl py-3 px-4 text-xs font-bold transition-all focus:border-primary outline-none">
                    </div>
                </div>

                <div class="ml-auto">
                    <a href="subscription_logs.php" title="Reset Filters" class="size-11 rounded-xl bg-white/5 border border-white/10 text-white/50 hover:bg-white/10 hover:text-white transition-all flex items-center justify-center">
                        <span class="material-symbols-outlined text-sm">refresh</span>
                    </a>
                </div>
            </form>
        </div>

        <!-- Layout Tabs (Tenant Style) -->
        <div class="flex items-center gap-8 mb-8 border-b border-white/5 px-2">
            <button onclick="switchTab('recent')" id="tabBtn-recent"
                class="pb-4 text-xs font-black uppercase tracking-widest transition-all relative group text-primary">
                Recent Logs
                <div id="tabIndicator-recent"
                    class="absolute bottom-0 left-0 right-0 h-0.5 bg-primary rounded-full transition-all opacity-100">
                </div>
            </button>
            <button onclick="switchTab('pending')" id="tabBtn-pending"
                class="pb-4 text-xs font-black uppercase tracking-widest transition-all relative group text-[--text-main] opacity-50 hover:opacity-100 <?= ($pending_payment > 0) ? 'mr-4' : '' ?>">
                Pending Payments
                <div id="tabIndicator-pending"
                    class="absolute bottom-0 left-0 right-0 h-0.5 bg-primary rounded-full transition-all opacity-0">
                </div>
                <?php if ($pending_payment > 0): ?>
                    <span
                        class="absolute -top-1 -right-6 size-4 bg-amber-500 text-[8px] font-black text-white flex items-center justify-center rounded-full shadow-lg shadow-amber-500/20 animate-bounce"><?= $pending_payment ?></span>
                <?php endif; ?>
            </button>
            <button onclick="switchTab('history')" id="tabBtn-history"
                class="pb-4 text-xs font-black uppercase tracking-widest transition-all relative group text-[--text-main] opacity-50 hover:opacity-100">
                All History
                <div id="tabIndicator-history"
                    class="absolute bottom-0 left-0 right-0 h-0.5 bg-primary rounded-full transition-all opacity-0">
                </div>
            </button>
        </div>

        <!-- RECENT ACTIVTY -->
        <div id="section-recent">
            <div class="glass-card overflow-hidden">
                <div class="px-8 py-6 border-b border-white/5 bg-white/5 flex justify-between items-center">
                    <h4 class="font-black italic uppercase text-sm tracking-tighter">Recent Logs (Last 7 Days)</h4>
                </div>
                <div class="overflow-x-auto no-scrollbar">
                    <table class="w-full text-left border-separate border-spacing-0">
                        <thead>
                            <tr class="bg-background/50 text-[--text-main] opacity-50 text-[10px] font-black uppercase tracking-[0.2em]">
                                <th class="px-8 py-4 border-b border-white/5">Gym & Plan</th>
                                <th class="px-8 py-4 border-b border-white/5">Start Date</th>
                                <th class="px-8 py-4 border-b border-white/5">Subscription Status</th>
                                <th class="px-8 py-4 border-b border-white/5">Payment Status</th>
                                <th class="px-8 py-4 border-b border-white/5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="recentTableBody" class="divide-y divide-white/5">
                            <?php if (empty($recent_logs)): ?>
                                <tr class="no-pagination">
                                    <td colspan="4" class="px-8 py-20 text-center">
                                        <div class="opacity-20 mb-4 flex justify-center">
                                            <span class="material-symbols-outlined text-6xl">history</span>
                                        </div>
                                        <p class="text-xs font-bold uppercase tracking-widest opacity-40">No recent activity found</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recent_logs as $log): ?>
                                    <tr class="hover:bg-white/5 transition-all">
                                        <td class="px-8 py-5">
                                            <div class="flex items-center gap-4">
                                                <div class="size-10 rounded-xl bg-primary/10 flex items-center justify-center shrink-0 border border-primary/20 overflow-hidden">
                                                    <?php if (!empty($log['gym_logo'])): ?>
                                                        <img src="<?= getLogoPath($log['gym_logo']) ?>" class="size-full object-contain">
                                                    <?php else: ?>
                                                        <span class="material-symbols-outlined text-primary text-xl">fitness_center</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <p class="text-sm font-black italic uppercase leading-none mb-1"><?= htmlspecialchars($log['gym_name']) ?></p>
                                                    <p class="text-[--text-main] opacity-40 text-[10px] uppercase font-black italic"><?= htmlspecialchars($log['plan_name']) ?> (₱<?= number_format($log['plan_price'], 0) ?>)</p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-8 py-5 text-xs font-black uppercase italic opacity-60">
                                            <?= date('M d, Y', strtotime($log['start_date'])) ?>
                                        </td>
                                        <td class="px-8 py-5">
                                            <?php 
                                            $subClass = match($log['subscription_status']) {
                                                'Active' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20',
                                                'Expired' => 'bg-rose-500/10 text-rose-400 border-rose-500/20',
                                                default => 'bg-white/5 text-gray-400 border-white/10'
                                            };
                                            ?>
                                            <span class="px-3 py-1 rounded-full text-[9px] font-black uppercase tracking-widest border <?= $subClass ?>">
                                                <?= $log['subscription_status'] ?>
                                            </span>
                                        </td>
                                        <td class="px-8 py-5">
                                            <?php 
                                            $payClass = match($log['payment_status']) {
                                                'Paid' => 'bg-emerald-500 text-emerald-100',
                                                'Pending' => 'bg-amber-500 text-amber-100',
                                                'Rejected' => 'bg-rose-500 text-rose-100',
                                                default => 'bg-gray-500 text-gray-100'
                                            };
                                            ?>
                                            <span class="px-3 py-1 rounded-full text-[9px] font-black uppercase tracking-widest <?= $payClass ?>">
                                                <?= $log['payment_status'] ?>
                                            </span>
                                        </td>
                                        <td class="px-8 py-5 text-right">
                                            <button onclick="viewSubscriptionDetails(<?= htmlspecialchars(json_encode($log)) ?>)" 
                                                    class="px-4 py-1.5 rounded-lg bg-white/5 hover:bg-white/10 border border-white/5 text-[--text-main] opacity-40 hover:opacity-100 text-[10px] font-black uppercase tracking-widest transition-all">
                                                View Details
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <!-- Pagination Container for Recent -->
                <div id="pagination-recent" class="px-8 py-4 border-t border-white/5 bg-white/[0.02] flex justify-between items-center hidden transition-all duration-300">
                    <p class="text-[10px] font-black uppercase text-[--text-main] opacity-40 tracking-widest status-text"></p>
                    <div class="flex gap-2 controls-container"></div>
                </div>
            </div>
        </div>

        <!-- PENDING PAYMENTS SECTION -->
        <div id="section-pending" class="hidden">
            <div class="glass-card overflow-hidden border border-amber-500/10 shadow-lg shadow-amber-500/5">
                <div class="px-8 py-6 border-b border-amber-500/10 bg-amber-500/5 flex justify-between items-center">
                    <h4 class="font-black italic uppercase text-sm tracking-tighter text-amber-400 flex items-center gap-2">
                        <span class="material-symbols-outlined text-xl">pending_actions</span>
                        Awaiting Payment Approval
                    </h4>
                </div>
                <div class="overflow-x-auto no-scrollbar">
                    <table class="w-full text-left border-separate border-spacing-0">
                        <thead>
                            <tr class="bg-background/50 text-[--text-main] opacity-50 text-[10px] font-black uppercase tracking-[0.2em]">
                                <th class="px-8 py-4 border-b border-white/5 font-black uppercase">Gym & Plan</th>
                                <th class="px-8 py-4 border-b border-white/5 font-black uppercase">Start Date</th>
                                <th class="px-8 py-4 border-b border-white/5 font-black uppercase text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="pendingTableBody" class="divide-y divide-white/5">
                            <?php if (empty($pending_logs)): ?>
                                <tr class="no-pagination">
                                    <td colspan="3" class="px-8 py-20 text-center">
                                        <div class="opacity-20 mb-4 text-emerald-400 flex justify-center">
                                            <span class="material-symbols-outlined text-6xl">verified</span>
                                        </div>
                                        <p class="text-xs font-bold uppercase tracking-widest opacity-40">All payments processed!</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($pending_logs as $log): ?>
                                    <tr class="hover:bg-white/5 transition-all">
                                        <td class="px-8 py-5">
                                            <div class="flex items-center gap-4">
                                                <div class="size-10 rounded-xl bg-amber-500/10 flex items-center justify-center shrink-0 border border-amber-500/20 overflow-hidden">
                                                    <?php if (!empty($log['gym_logo'])): ?>
                                                        <img src="<?= getLogoPath($log['gym_logo']) ?>" class="size-full object-contain">
                                                    <?php else: ?>
                                                        <span class="material-symbols-outlined text-amber-500 text-xl">payments</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <p class="text-sm font-black italic uppercase leading-none mb-1 text-white"><?= htmlspecialchars($log['gym_name']) ?></p>
                                                    <p class="text-[--text-main] opacity-40 text-[10px] uppercase font-black italic"><?= htmlspecialchars($log['plan_name']) ?> (₱<?= number_format($log['plan_price'], 0) ?>)</p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-8 py-5 text-xs font-black uppercase italic opacity-60">
                                            <?= date('M d, Y', strtotime($log['start_date'])) ?>
                                        </td>
                                        <td class="px-8 py-5 text-right">
                                            <div class="inline-flex gap-2">
                                                <button onclick="viewSubscriptionDetails(<?= htmlspecialchars(json_encode($log)) ?>)" 
                                                        class="px-4 py-1.5 rounded-lg bg-white/5 hover:bg-white/10 border border-white/5 text-[--text-main] opacity-40 hover:opacity-100 text-[10px] font-black uppercase tracking-widest transition-all mr-2">
                                                    Details
                                                </button>
                                                <form method="POST" class="confirm-form">
                                                    <input type="hidden" name="subscription_id" value="<?= $log['client_subscription_id'] ?>">
                                                    <input type="hidden" name="action" value="approve_payment">
                                                    <button type="button" 
                                                            onclick="confirmAdminAction(this.form, 'Approve Payment', 'Are you sure you want to approve this payment? This will activate the gym\'s premium subscription.')"
                                                            class="size-8 rounded-lg bg-emerald-500/10 hover:bg-emerald-500 border border-emerald-500/20 hover:border-emerald-500 text-emerald-500 hover:text-white transition-all flex items-center justify-center shadow-lg shadow-emerald-500/5 group relative">
                                                        <span class="material-symbols-outlined text-sm">check</span>
                                                        <span class="absolute -top-10 left-1/2 -translate-x-1/2 px-3 py-1 bg-[#141216] text-[8px] font-black uppercase tracking-widest text-emerald-400 rounded-lg border border-emerald-500/20 opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none whitespace-nowrap">Approve</span>
                                                    </button>
                                                </form>
                                                <form method="POST" class="confirm-form">
                                                    <input type="hidden" name="subscription_id" value="<?= $log['client_subscription_id'] ?>">
                                                    <input type="hidden" name="action" value="reject_payment">
                                                    <button type="button" 
                                                            onclick="confirmAdminAction(this.form, 'Reject Payment', 'Are you sure you want to reject this payment? This will set the subscription to Inactive.')"
                                                            class="size-8 rounded-lg bg-rose-500/10 hover:bg-rose-500 border border-rose-500/20 hover:border-rose-500 text-rose-500 hover:text-white transition-all flex items-center justify-center shadow-lg shadow-rose-500/5 group relative">
                                                        <span class="material-symbols-outlined text-sm">close</span>
                                                        <span class="absolute -top-10 left-1/2 -translate-x-1/2 px-3 py-1 bg-[#141216] text-[8px] font-black uppercase tracking-widest text-rose-400 rounded-lg border border-rose-500/20 opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none whitespace-nowrap">Reject</span>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <!-- Pagination Container for Pending -->
                <div id="pagination-pending" class="px-8 py-4 border-t border-white/5 bg-white/[0.02] flex justify-between items-center hidden transition-all duration-300">
                    <p class="text-[10px] font-black uppercase text-[--text-main] opacity-40 tracking-widest status-text"></p>
                    <div class="flex gap-2 controls-container"></div>
                </div>
            </div>
        </div>

        <!-- FULL HISTORY SECTION -->
        <div id="section-history" class="hidden">
            <div class="glass-card overflow-hidden">
                <div class="px-8 py-6 border-b border-white/5 bg-white/5 flex justify-between items-center">
                    <h4 class="font-black italic uppercase text-sm tracking-tighter">Enterprise Audit History</h4>
                </div>
                <div class="overflow-x-auto no-scrollbar">
                    <table class="w-full text-left border-separate border-spacing-0">
                        <thead>
                            <tr class="bg-background/50 text-[--text-main] opacity-50 text-[10px] font-black uppercase tracking-[0.2em]">
                                <th class="px-8 py-4 border-b border-white/5">Gym & Plan</th>
                                <th class="px-8 py-4 border-b border-white/5">Transaction Date</th>
                                <th class="px-8 py-4 border-b border-white/5">Status</th>
                                <th class="px-8 py-4 border-b border-white/5">Payment</th>
                                <th class="px-8 py-4 border-b border-white/5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="historyTableBody" class="divide-y divide-white/5">
                            <?php if (empty($history_logs)): ?>
                                <tr class="no-pagination">
                                    <td colspan="4" class="px-8 py-20 text-center">
                                        <div class="opacity-20 mb-4 flex justify-center text-primary">
                                            <span class="material-symbols-outlined text-6xl italic">manage_search</span>
                                        </div>
                                        <p class="text-xs font-black italic uppercase tracking-widest opacity-40">No subscription history found</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($history_logs as $log): ?>
                                    <tr class="hover:bg-white/5 transition-all">
                                        <td class="px-8 py-5">
                                            <div class="flex items-center gap-4">
                                                <div class="size-10 rounded-xl bg-white/5 flex items-center justify-center shrink-0 border border-white/10 overflow-hidden">
                                                    <?php if (!empty($log['gym_logo'])): ?>
                                                        <img src="<?= getLogoPath($log['gym_logo']) ?>" class="size-full object-contain">
                                                    <?php else: ?>
                                                        <span class="material-symbols-outlined text-md">receipt</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <p class="text-xs font-bold italic uppercase leading-none mb-1 text-white"><?= htmlspecialchars($log['gym_name']) ?></p>
                                                    <p class="text-[--text-main] opacity-40 text-[9px] uppercase font-black italic"><?= htmlspecialchars($log['plan_name']) ?></p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-8 py-5 text-[10px] font-black uppercase italic opacity-40">
                                            <?= date('M d, Y', strtotime($log['start_date'])) ?>
                                        </td>
                                        <td class="px-8 py-5 text-[10px] font-black uppercase italic">
                                            <?php 
                                            $subClass = match($log['subscription_status']) {
                                                'Active' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20',
                                                'Expired' => 'bg-rose-500/10 text-rose-400 border-rose-500/20',
                                                default => 'bg-white/5 text-gray-400 border-white/10'
                                            };
                                            ?>
                                            <span class="px-2 py-0.5 rounded-full text-[8px] font-black uppercase tracking-widest border <?= $subClass ?>">
                                                <?= $log['subscription_status'] ?>
                                            </span>
                                        </td>
                                        <td class="px-8 py-5">
                                            <span class="text-[10px] font-black uppercase tracking-tighter <?= $log['payment_status'] === 'Paid' ? 'text-emerald-400' : ($log['payment_status'] === 'Rejected' ? 'text-rose-400' : 'text-amber-400') ?>">
                                                <?= $log['payment_status'] ?>
                                            </span>
                                        </td>
                                        <td class="px-8 py-5 text-right">
                                            <button onclick="viewSubscriptionDetails(<?= htmlspecialchars(json_encode($log)) ?>)" 
                                                    class="px-4 py-1.5 rounded-lg bg-white/5 hover:bg-white/10 border border-white/5 text-[--text-main] opacity-40 hover:opacity-100 text-[10px] font-black uppercase tracking-widest transition-all">
                                                Details
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <!-- Pagination Container for History -->
                <div id="pagination-history" class="px-8 py-4 border-t border-white/5 bg-white/[0.02] flex justify-between items-center hidden transition-all duration-300">
                    <p class="text-[10px] font-black uppercase text-[--text-main] opacity-40 tracking-widest status-text"></p>
                    <div class="flex gap-2 controls-container"></div>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
    // Auto-dismiss alerts after 10 seconds
    setTimeout(() => {
        const successAlert = document.getElementById('successAlert');
        const errorAlert = document.getElementById('errorAlert');
        if (successAlert) {
            successAlert.style.opacity = '0';
            setTimeout(() => successAlert.remove(), 500);
        }
        if (errorAlert) {
            errorAlert.style.opacity = '0';
            setTimeout(() => errorAlert.remove(), 500);
        }
    }, 10000);

    function confirmAdminAction(form, title, message) {
        const modal = document.getElementById('adminActionModal');
        const modalTitle = document.getElementById('modalTitle');
        const modalMessage = document.getElementById('modalMessage');
        const confirmBtn = document.getElementById('confirm-btn');

        modalTitle.textContent = title;
        modalMessage.textContent = message;
        
        // Show Modal
        modal.classList.add('flex-important');

        // Setup Confirm Button with ONE-TIME click
        confirmBtn.onclick = function() {
            form.submit();
            closeModal();
        };
    }

    function closeModal() {
        document.getElementById('adminActionModal').classList.remove('flex-important');
    }

    function initPagination(tableBodyId, paginationId, rowsPerPage) {
        const tableBody = document.getElementById(tableBodyId);
        const paginationContainer = document.getElementById(paginationId);
        if (!tableBody || !paginationContainer) return;

        // Skip rows that should not be paginated (e.g., empty state messages)
        const allRows = Array.from(tableBody.querySelectorAll('tr'));
        const rows = allRows.filter(tr => !tr.classList.contains('no-pagination'));
        const totalRows = rows.length;

        if (totalRows <= rowsPerPage) {
            paginationContainer.classList.add('hidden');
            allRows.forEach(row => row.classList.remove('hidden'));
            return;
        }

        paginationContainer.classList.remove('hidden');
        const totalPages = Math.ceil(totalRows / rowsPerPage);
        let currentPage = 1;

        const statusText = paginationContainer.querySelector('.status-text');
        const controlsContainer = paginationContainer.querySelector('.controls-container');

        function render() {
            const start = (currentPage - 1) * rowsPerPage;
            const end = start + rowsPerPage;

            rows.forEach((row, index) => {
                row.classList.toggle('hidden', index < start || index >= end);
            });

            const entriesCount = Math.min(end, totalRows);
            statusText.textContent = `Showing ${start + 1} to ${entriesCount} of ${totalRows} entries`;
            controlsContainer.innerHTML = '';
            
            // Prev Button
            const prevBtn = document.createElement('button');
            prevBtn.className = `size-8 rounded-lg bg-white/5 flex items-center justify-center text-[--text-main] transition-all ${currentPage === 1 ? 'opacity-20 pointer-events-none' : 'opacity-40 hover:opacity-100 hover:bg-white/10 active:scale-90'}`;
            prevBtn.innerHTML = '<span class="material-symbols-outlined text-sm">chevron_left</span>';
            prevBtn.onclick = () => { if (currentPage > 1) { currentPage--; render(); } };
            controlsContainer.appendChild(prevBtn);

            // Page Numbers (Smart Pagination)
            let startPage = Math.max(1, currentPage - 1);
            let endPage = Math.min(totalPages, startPage + 2);
            if (endPage === totalPages) startPage = Math.max(1, endPage - 2);

            for (let i = startPage; i <= endPage; i++) {
                const pageBtn = document.createElement('button');
                pageBtn.className = `size-8 rounded-lg font-black text-[10px] transition-all ${i === currentPage ? 'bg-primary text-white shadow-lg shadow-primary/20' : 'bg-white/5 text-[--text-main] opacity-40 hover:opacity-100 hover:bg-white/10 active:scale-95'}`;
                pageBtn.textContent = i;
                pageBtn.onclick = () => { currentPage = i; render(); };
                controlsContainer.appendChild(pageBtn);
            }

            // Next Button
            const nextBtn = document.createElement('button');
            nextBtn.className = `size-8 rounded-lg bg-white/5 flex items-center justify-center text-[--text-main] transition-all ${currentPage === totalPages ? 'opacity-20 pointer-events-none' : 'opacity-40 hover:opacity-100 hover:bg-white/10 active:scale-90'}`;
            nextBtn.innerHTML = '<span class="material-symbols-outlined text-sm">chevron_right</span>';
            nextBtn.onclick = () => { if (currentPage < totalPages) { currentPage++; render(); } };
            controlsContainer.appendChild(nextBtn);
        }

        render();
    }
</script>

<!-- 4. Sidebar-Aware Modal UI Skeleton (Targeting Requirement) -->
<div id="adminActionModal" class="sidebar-aware-modal p-4 overflow-y-auto">
    <div class="glass-card max-w-md w-full p-8 border-primary/20 shadow-2xl shadow-primary/10 mx-auto">
        <h3 id="modalTitle" class="text-xl font-black italic uppercase italic text-white mb-2 leading-none">Confirm Action</h3>
        <p id="modalMessage" class="text-[10px] text-[--text-main] opacity-60 font-bold uppercase tracking-widest leading-relaxed mb-10">Are you sure you want to proceed with this administrative task?</p>
        
        <div class="flex gap-4">
            <button onclick="closeModal()" 
                    class="flex-1 py-3 rounded-xl bg-white/5 hover:bg-white/10 text-[10px] font-black uppercase tracking-widest transition-all border border-white/5">
                Cancel
            </button>
            <button id="confirm-btn" 
                    class="flex-1 py-3 rounded-xl bg-primary text-white text-[10px] font-black uppercase tracking-widest shadow-lg shadow-primary/20 hover:scale-[1.02] active:scale-95 transition-all">
                Confirm Proceed
            </button>
        </div>
    </div>
</div>

<!-- Subscription Details Modal -->
<div id="subscriptionDetailModal" class="sidebar-aware-modal p-4 overflow-y-auto">
    <div class="glass-card max-w-2xl w-full flex flex-col max-h-[90vh] overflow-hidden border-white/10 shadow-2xl">
        <div class="px-8 py-6 border-b border-white/5 flex justify-between items-center bg-white/5">
            <h3 class="text-xl font-black italic uppercase text-white flex items-center gap-3">
                <span class="material-symbols-outlined text-primary">info</span>
                Subscription Details
            </h3>
            <button onclick="closeDetailModal()" class="size-8 rounded-lg bg-white/5 hover:bg-white/10 flex items-center justify-center transition-all">
                <span class="material-symbols-outlined text-sm">close</span>
            </button>
        </div>
        
        <div class="p-8 overflow-y-auto no-scrollbar space-y-8">
            <!-- Gym Overview -->
            <div class="flex items-center gap-6 p-6 rounded-2xl bg-white/5 border border-white/5">
                <div class="size-20 rounded-2xl bg-primary/10 flex items-center justify-center border border-primary/20 overflow-hidden shrink-0">
                    <img id="detailLogo" src="" class="size-full object-contain hidden">
                    <span id="detailIcon" class="material-symbols-outlined text-primary text-4xl">fitness_center</span>
                </div>
                <div>
                    <h4 id="detailGymName" class="text-2xl font-black italic uppercase text-white leading-none mb-2">Gym Name</h4>
                    <p id="detailTenantCode" class="text-[10px] font-black uppercase text-primary tracking-[0.2em]">TENANT_CODE</p>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-6">
                <!-- Plan Information -->
                <div class="space-y-4">
                    <p class="text-[10px] font-black uppercase text-[--text-main] opacity-40 tracking-widest pl-1">Plan Information</p>
                    <div class="p-5 rounded-2xl bg-white/[0.03] border border-white/5 space-y-3">
                        <div class="flex justify-between">
                            <span class="text-[10px] font-bold text-[--text-main] opacity-50 uppercase">Plan Name</span>
                            <span id="detailPlanName" class="text-[10px] font-black uppercase text-white">---</span>
                        </div>
                        <div class="flex justify-between border-t border-white/5 pt-3">
                            <span class="text-[10px] font-bold text-[--text-main] opacity-50 uppercase">Price</span>
                            <span id="detailPrice" class="text-[10px] font-black uppercase text-emerald-400">---</span>
                        </div>
                        <div class="flex justify-between border-t border-white/5 pt-3">
                            <span class="text-[10px] font-bold text-[--text-main] opacity-50 uppercase">Billing Cycle</span>
                            <span id="detailBilling" class="text-[10px] font-black uppercase text-primary">---</span>
                        </div>
                    </div>
                </div>

                <!-- Status & Timing -->
                <div class="space-y-4">
                    <p class="text-[10px] font-black uppercase text-[--text-main] opacity-40 tracking-widest pl-1">Subscription Validity</p>
                    <div class="p-5 rounded-2xl bg-white/[0.03] border border-white/5 space-y-3">
                        <div class="flex justify-between">
                            <span class="text-[10px] font-bold text-[--text-main] opacity-50 uppercase">Status</span>
                            <span id="detailSubStatus" class="px-2 py-0.5 rounded-full text-[8px] font-black uppercase tracking-widest border border-white/10">---</span>
                        </div>
                        <div class="flex justify-between border-t border-white/5 pt-3">
                            <span class="text-[10px] font-bold text-[--text-main] opacity-50 uppercase">Start Date</span>
                            <span id="detailStart" class="text-[10px] font-black uppercase text-white">---</span>
                        </div>
                        <div class="flex justify-between border-t border-white/5 pt-3">
                            <span class="text-[10px] font-bold text-[--text-main] opacity-50 uppercase">Expiry Date</span>
                            <span id="detailEnd" class="text-[10px] font-black uppercase text-rose-400">---</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Payment Details -->
            <div class="space-y-4">
                <p class="text-[10px] font-black uppercase text-[--text-main] opacity-40 tracking-widest pl-1">Payment Verification</p>
                <div class="p-6 rounded-2xl bg-amber-500/[0.03] border border-amber-500/10 flex items-center justify-between">
                    <div>
                        <p class="text-[9px] font-black text-amber-500/50 uppercase tracking-widest mb-1">Payment Status</p>
                        <p id="detailPayStatus" class="text-sm font-black italic uppercase text-amber-400">---</p>
                    </div>
                    <div class="text-right">
                        <p class="text-[9px] font-black text-white/20 uppercase tracking-widest mb-1">Reference Number</p>
                        <p id="detailRefNo" class="text-sm font-black italic uppercase text-white">---</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="px-8 py-6 border-t border-white/5 bg-white/5">
            <button onclick="closeDetailModal()" class="w-full py-4 rounded-xl bg-primary text-white text-[11px] font-black uppercase tracking-[0.2em] shadow-lg shadow-primary/20 hover:scale-[1.01] active:scale-95 transition-all">
                Dismiss Details
            </button>
        </div>
    </div>
</div>

<script>
    function viewSubscriptionDetails(data) {
        const modal = document.getElementById('subscriptionDetailModal');
        
        // Populating basic text data
        document.getElementById('detailGymName').textContent = data.gym_name;
        document.getElementById('detailTenantCode').textContent = data.tenant_code;
        document.getElementById('detailPlanName').textContent = data.plan_name;
        document.getElementById('detailPrice').textContent = '₱' + Number(data.plan_price).toLocaleString();
        document.getElementById('detailBilling').textContent = data.billing_cycle;
        document.getElementById('detailStart').textContent = data.start_date ? new Date(data.start_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '---';
        document.getElementById('detailEnd').textContent = data.end_date ? new Date(data.end_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '---';
        document.getElementById('detailPayStatus').textContent = data.payment_status;
        
        const refEl = document.getElementById('detailRefNo');
        refEl.textContent = data.ref_no || 'NOT_FOUND';
        
        // Auto-fetch from PayMongo if Reference is missing or is just a Session ID
        if ((!data.ref_no || data.ref_no === 'NOT_FOUND' || data.ref_no.startsWith('cs_')) && data.payment_id) {
            refEl.innerHTML = '<span class="flex items-center gap-2 text-primary opacity-60"><span class="material-symbols-outlined text-xs animate-spin">refresh</span> Fetching Ref...</span>';
            
            const formData = new FormData();
            formData.append('action', 'fetch_paymongo_ref');
            formData.append('payment_id', data.payment_id);
            
            fetch('subscription_logs.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(res => {
                if (res.success) {
                    refEl.textContent = res.ref_no;
                    // Update the local object so it doesn't refetch if closed and reopened
                    data.ref_no = res.ref_no;
                } else {
                    refEl.textContent = data.ref_no || 'NOT_FOUND';
                    console.error('PayMongo Fetch Error:', res.message);
                }
            })
            .catch(err => {
                refEl.textContent = data.ref_no || 'NOT_FOUND';
                console.error('AJAX Error:', err);
            });
        }
        
        // Handle Sub Status badge
        const subStatus = document.getElementById('detailSubStatus');
        subStatus.textContent = data.subscription_status;
        subStatus.className = 'px-2 py-0.5 rounded-full text-[8px] font-black uppercase tracking-widest border ' + 
            (data.subscription_status === 'Active' ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' : 'bg-rose-500/10 text-rose-400 border-rose-500/20');

        // Handle Logo
        const logoImg = document.getElementById('detailLogo');
        const iconPlaceholder = document.getElementById('detailIcon');
        
        if (data.gym_logo) {
            logoImg.src = getLogoPathJS(data.gym_logo);
            logoImg.classList.remove('hidden');
            iconPlaceholder.classList.add('hidden');
        } else {
            logoImg.classList.add('hidden');
            iconPlaceholder.classList.remove('hidden');
        }

        modal.classList.add('flex-important');
    }

    function getLogoPathJS(path) {
        if (!path) return '';
        if (path.startsWith('data:') || path.startsWith('http')) return path;
        if (path.startsWith('uploads/')) return '../' + path;
        if (path.startsWith('../')) return path;
        return '../uploads/applications/' + path;
    }

    function closeDetailModal() {
        document.getElementById('subscriptionDetailModal').classList.remove('flex-important');
    }
</script>
</body>
</html>