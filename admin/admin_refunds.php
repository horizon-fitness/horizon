<?php
session_start();
require_once '../db.php';
require_once '../includes/mailer.php';

// Security Check: Only Staff and Admin/Coach can access (Modify based on gym roles)
$role = strtolower($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || ($role !== 'staff' && $role !== 'coach' && $role !== 'admin' && $role !== 'superadmin' && $role !== 'tenant')) {
    header("Location: ../login.php");
    exit;
}

$gym_id = $_SESSION['gym_id'];
$admin_name = $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];
$admin_id = $_SESSION['user_id'];

// --- PROCESS REFUND STATUS UPDATE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['refund_id'])) {
    $refund_id = (int)$_POST['refund_id'];
    $action = $_POST['action']; // 'approve' or 'reject'
    $new_status = ($action === 'approve') ? 'Approved' : 'Rejected';
    
    // Fetch details for email
    $stmtRef = $pdo->prepare("
        SELECT rr.*, u.email, u.first_name, u.last_name, b.booking_date, b.start_time, sc.service_name, g.gym_name
        FROM refund_requests rr
        JOIN users u ON rr.user_id = u.user_id
        JOIN bookings b ON rr.booking_id = b.booking_id
        JOIN gyms g ON rr.gym_id = g.gym_id
        JOIN service_catalog sc ON b.catalog_service_id = sc.catalog_service_id
        WHERE rr.refund_request_id = ? AND rr.gym_id = ?
    ");
    $stmtRef->execute([$refund_id, $gym_id]);
    $refundData = $stmtRef->fetch(PDO::FETCH_ASSOC);

    if ($refundData && $refundData['status'] === 'Pending') {
        $updateStmt = $pdo->prepare("UPDATE refund_requests SET status = ?, processed_by = ?, processed_at = NOW() WHERE refund_request_id = ?");
        $updateStmt->execute([$new_status, $admin_id, $refund_id]);

        // Send Email Notification to User
        $memberName = $refundData['first_name'] . ' ' . $refundData['last_name'];
        $subject = "Refund Request " . strtoupper($new_status);
        $gymName = $refundData['gym_name'];
        
        $color = $new_status === 'Approved' ? '#10B981' : '#EF4444';
        
        $emailContent = "
            <p>Hello $memberName,</p>
            <p>Your refund and cancellation request has been processed.</p>
            <div style='background: #f8f9fa; padding: 20px; border-radius: 10px; margin: 20px 0;'>
                <p style='margin: 5px 0;'><strong>Service:</strong> {$refundData['service_name']}</p>
                <p style='margin: 5px 0;'><strong>Schedule:</strong> " . date('M d, Y', strtotime($refundData['booking_date'])) . " at " . date('h:i A', strtotime($refundData['start_time'])) . "</p>
                <p style='margin: 5px 0;'><strong>Reason Provided:</strong> " . htmlspecialchars($refundData['reason']) . "</p>
                <p style='margin: 5px 0;'><strong>Final Status:</strong> <span style='color: $color; font-weight: bold;'>$new_status</span></p>
            </div>
            <p>If approved, please coordinate with the gym staff for the release of funds. If rejected, it means your request did not meet the gym's policy.</p>
        ";
        $fullBody = getFormalEmailTemplate($subject, $emailContent, $gymName);
        sendSystemEmail($refundData['email'], $subject, $fullBody);
        
        header("Location: admin_refunds.php?msg=success");
        exit;
    }
}

// ── 4-Color Elite Branding System Implementation ─────────────────────────────
if (!function_exists('hexToRgb')) {
    function hexToRgb($hex) {
        if (!$hex) return "0, 0, 0";
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

// Fetch Gym & Owner Details for Branding
$stmtGymBranding = $pdo->prepare("SELECT owner_user_id, gym_name, profile_picture FROM gyms WHERE gym_id = ?");
$stmtGymBranding->execute([$gym_id]);
$gym_data = $stmtGymBranding->fetch();
$owner_user_id = $gym_data['owner_user_id'] ?? 0;
$gym_name = $gym_data['gym_name'] ?? 'Horizon Gym';

$configs = [
    'system_name'     => $gym_name,
    'system_logo'     => '',
    'theme_color'     => '#8c2bee',
    'secondary_color' => '#a1a1aa',
    'text_color'      => '#d1d5db',
    'bg_color'        => '#0a090d',
    'card_color'      => '#141216',
    'auto_card_theme' => '1',
    'font_family'     => 'Lexend',
];

$stmtGlobal = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = 0");
$stmtGlobal->execute();
foreach (($stmtGlobal->fetchAll(PDO::FETCH_KEY_PAIR) ?: []) as $k => $v) {
    if ($v !== null && $v !== '') $configs[$k] = $v;
}

$stmtTenant = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = ?");
$stmtTenant->execute([$owner_user_id]);
foreach (($stmtTenant->fetchAll(PDO::FETCH_KEY_PAIR) ?: []) as $k => $v) {
    if ($v !== null && $v !== '') $configs[$k] = $v;
}

$theme_color     = $configs['theme_color'];
$highlight_color = $configs['secondary_color'];
$text_color      = $configs['text_color'];
$bg_color        = $configs['bg_color'];
$font_family     = $configs['font_family'] ?? 'Lexend';
$auto_card_theme = $configs['auto_card_theme'] ?? '1';
$card_color      = $configs['card_color'];

$primary_rgb   = hexToRgb($theme_color);
$highlight_rgb = hexToRgb($highlight_color);
$card_bg_css   = ($auto_card_theme === '1') ? "rgba({$primary_rgb}, 0.05)" : $card_color;
$system_logo = $configs['system_logo'] ?: ($gym_data['profile_picture'] ?? '');

// Fetch Refund Requests
$stmtRefunds = $pdo->prepare("
    SELECT rr.*, u.first_name, u.last_name, u.email, b.booking_date, b.start_time, sc.service_name 
    FROM refund_requests rr
    JOIN users u ON rr.user_id = u.user_id
    JOIN bookings b ON rr.booking_id = b.booking_id
    JOIN service_catalog sc ON b.catalog_service_id = sc.catalog_service_id
    WHERE rr.gym_id = ?
    ORDER BY rr.created_at DESC
");
$stmtRefunds->execute([$gym_id]);
$refunds = $stmtRefunds->fetchAll(PDO::FETCH_ASSOC);

$active_page = "refunds";
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>Refund Requests | Horizon Partners</title>
    <link href="https://fonts.googleapis.com/css2?family=<?= urlencode($font_family) ?>:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet" />
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "var(--primary)",
                        "background": "var(--background)",
                        "card-bg": "var(--card-bg)",
                        "text-main": "var(--text-main)",
                        "highlight": "var(--highlight)"
                    }
                }
            }
        } 
    </script>
    <style>
        :root {
            --primary:       <?= $theme_color ?>;
            --primary-rgb:   <?= $primary_rgb ?>;
            --highlight:     <?= $highlight_color ?>;
            --highlight-rgb: <?= $highlight_rgb ?>;
            --text-main:     <?= $text_color ?>;
            --background:    <?= $bg_color ?>;
            --background-rgb: <?= hexToRgb($bg_color) ?>;
            --card-bg:       <?= $card_bg_css ?>;
            --card-blur:     20px;
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
            backdrop-filter: blur(var(--card-blur));
            border-radius: 24px;
        }

        :root { --nav-width: 110px; }
        body:has(.side-nav:hover) { --nav-width: 300px; }

        .side-nav {
            width: var(--nav-width);
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            position: fixed;
            left: 0;
            top: 0;
            height: 100vh;
            z-index: 110;
        }

        .main-content {
            margin-left: var(--nav-width);
            flex: 1;
            min-width: 0;
            transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow-y: auto;
            overflow-x: hidden;
        }

        .nav-label {
            opacity: 0;
            transform: translateX(-15px);
            transition: all 0.3s ease-in-out;
            white-space: nowrap;
            pointer-events: none;
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
            transition: all 0.2s ease;
            text-decoration: none;
            white-space: nowrap;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #94a3b8;
        }

        .nav-item:hover {
            background: rgba(255, 255, 255, 0.05);
            color: white;
        }

        .nav-item.active {
            color: <?= $theme_color ?> !important;
            position: relative;
        }

        .nav-item.active::after {
            content: '';
            position: absolute;
            right: 0px;
            top: 50%;
            transform: translateY(-50%);
            width: 4px;
            height: 24px;
            background: <?= $theme_color ?>;
            border-radius: 4px 0 0 4px;
        }
        
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        .table-header-alt {
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.25em;
            color: var(--text-main);
            opacity: 0.5;
        }
    </style>
</head>
<body class="antialiased flex h-screen overflow-hidden">
    <?php include '../includes/admin_sidebar.php'; ?>

    <div class="main-content flex-1 overflow-y-auto no-scrollbar">
        <main class="p-10 max-w-[1400px] mx-auto pb-20">
            <header class="mb-10 flex justify-between items-end gap-6">
                <div>
                    <h2 class="text-3xl font-black uppercase tracking-tighter italic" style="color:var(--text-main)">
                        Refund <span style="color:var(--primary)" class="italic">Requests</span>
                    </h2>
                    <p class="text-[10px] font-black uppercase tracking-widest mt-1 opacity-50" style="color:var(--text-main)">Manage Cancellations & Refunds</p>
                </div>
            </header>

            <div class="glass-card p-6">
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="bg-white/5 border-b border-white/5">
                                <th class="px-6 py-5 table-header-alt">Member</th>
                                <th class="px-6 py-5 table-header-alt">Service</th>
                                <th class="px-6 py-5 table-header-alt">Schedule</th>
                                <th class="px-6 py-5 table-header-alt">Reason</th>
                                <th class="px-6 py-5 table-header-alt">Status</th>
                                <th class="px-6 py-5 table-header-alt text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5 text-sm font-medium">
                            <?php if (empty($refunds)): ?>
                                <tr>
                                    <td colspan="6" class="px-8 py-24 text-center text-[11px] font-black italic uppercase tracking-[0.3em] text-[--text-main] opacity-20">
                                        No refund requests found.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($refunds as $r): ?>
                                    <tr class="group hover:bg-white/[0.02] transition-colors">
                                        <td class="px-6 py-4 align-middle">
                                            <p class="text-[13px] font-bold" style="color:var(--text-main)">
                                                <?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?>
                                            </p>
                                            <p class="text-[11px] opacity-60"><?= htmlspecialchars($r['email']) ?></p>
                                        </td>
                                        <td class="px-6 py-4 align-middle">
                                            <p class="text-[12px] font-medium opacity-80" style="color:var(--text-main)"><?= htmlspecialchars($r['service_name']) ?></p>
                                        </td>
                                        <td class="px-6 py-4 align-middle">
                                            <p class="text-[12px] font-bold" style="color:var(--primary)">
                                                <?= date('M d, Y', strtotime($r['booking_date'])) ?>
                                            </p>
                                            <p class="text-[11px] opacity-60"><?= date('h:i A', strtotime($r['start_time'])) ?></p>
                                        </td>
                                        <td class="px-6 py-4 align-middle">
                                            <p class="text-[11px] font-medium opacity-80 italic max-w-xs break-words" style="color:var(--text-main)">
                                                "<?= htmlspecialchars($r['reason']) ?>"
                                            </p>
                                        </td>
                                        <td class="px-6 py-4 align-middle">
                                            <?php 
                                            $c = 'text-amber-500';
                                            if ($r['status'] === 'Approved') $c = 'text-emerald-500';
                                            if ($r['status'] === 'Rejected') $c = 'text-rose-500';
                                            ?>
                                            <span class="text-[11px] font-black uppercase tracking-wider <?= $c ?>">
                                                <?= $r['status'] ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 text-center align-middle">
                                            <?php if ($r['status'] === 'Pending'): ?>
                                                <div class="flex items-center justify-center gap-2">
                                                    <form method="POST" onsubmit="return confirm('Approve this refund request? An email will be sent.');">
                                                        <input type="hidden" name="refund_id" value="<?= $r['refund_request_id'] ?>">
                                                        <input type="hidden" name="action" value="approve">
                                                        <button type="submit" class="px-4 py-2 bg-emerald-500/10 text-emerald-500 hover:bg-emerald-500 hover:text-white rounded-lg text-[10px] font-black uppercase tracking-widest transition-all">
                                                            Approve
                                                        </button>
                                                    </form>
                                                    <form method="POST" onsubmit="return confirm('Reject this refund request? An email will be sent.');">
                                                        <input type="hidden" name="refund_id" value="<?= $r['refund_request_id'] ?>">
                                                        <input type="hidden" name="action" value="reject">
                                                        <button type="submit" class="px-4 py-2 bg-rose-500/10 text-rose-500 hover:bg-rose-500 hover:text-white rounded-lg text-[10px] font-black uppercase tracking-widest transition-all">
                                                            Reject
                                                        </button>
                                                    </form>
                                                </div>
                                            <?php else: ?>
                                                <p class="text-[10px] uppercase font-black opacity-30">Processed</p>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
