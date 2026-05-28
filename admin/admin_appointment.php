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
$stmtGymBranding = $pdo->prepare("SELECT owner_user_id, gym_name FROM gyms WHERE gym_id = ?");
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

// 1. Merge global settings (user_id = 0)
$stmtGlobal = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = 0");
$stmtGlobal->execute();
foreach (($stmtGlobal->fetchAll(PDO::FETCH_KEY_PAIR) ?: []) as $k => $v) {
    if ($v !== null && $v !== '') $configs[$k] = $v;
}

// 2. Merge tenant-specific settings (user_id = owner_user_id)
$stmtTenant = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = ?");
$stmtTenant->execute([$owner_user_id]);
foreach (($stmtTenant->fetchAll(PDO::FETCH_KEY_PAIR) ?: []) as $k => $v) {
    if ($v !== null && $v !== '') $configs[$k] = $v;
}

// 3. Resolved branding tokens
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

$page = [
    'logo_path'   => $configs['system_logo'] ?? '',
    'theme_color' => $theme_color,
    'bg_color'    => $bg_color,
    'system_name' => $configs['system_name'] ?? $gym_name,
];
// ─────────────────────────────────────────────────────────────────────────────

// --- FILTERING LOGIC ---
$search = $_GET['search'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$status = $_GET['status'] ?? '';
$user_filter = $_GET['user_id'] ?? 'all';
$limit = 10;
$current_page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($current_page - 1) * $limit;
if ($date_from !== '' && $date_to !== '' && $date_from > $date_to) $date_from = $date_to;

// Base Query Structure
$sql_parts = ["m.gym_id = :gym_id"];
$sql_params = [':gym_id' => $gym_id];

if (!empty($search)) {
    $sql_parts[] = "(u.first_name LIKE :s1 OR u.last_name LIKE :s2 OR u.username LIKE :s3)";
    $sql_params[':s1'] = "%$search%";
    $sql_params[':s2'] = "%$search%";
    $sql_params[':s3'] = "%$search%";
}

if (!empty($date_from)) {
    $sql_parts[] = "b.booking_date >= :d1";
    $sql_params[':d1'] = $date_from;
}

if (!empty($date_to)) {
    $sql_parts[] = "b.booking_date <= :d2";
    $sql_params[':d2'] = $date_to;
}

if (!empty($status)) {
    $sql_parts[] = "b.booking_status = :status";
    $sql_params[':status'] = $status;
}

if ($user_filter !== '' && $user_filter !== 'all') {
    $sql_parts[] = "u.user_id = :user_id";
    $sql_params[':user_id'] = (int) $user_filter;
}

$where_clause = "WHERE " . implode(' AND ', $sql_parts);

$count_sql = "
    SELECT COUNT(*)
    FROM bookings b
    JOIN members m ON b.member_id = m.member_id
    JOIN users u ON m.user_id = u.user_id
    LEFT JOIN service_catalog sc ON b.catalog_service_id = sc.catalog_service_id
    $where_clause
";
$stmtCount = $pdo->prepare($count_sql);
foreach ($sql_params as $key => $value) {
    $stmtCount->bindValue($key, $value, in_array($key, [':gym_id', ':user_id'], true) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmtCount->execute();
$total_records = (int) $stmtCount->fetchColumn();
$total_pages = max(1, (int) ceil($total_records / $limit));
if ($current_page > $total_pages) {
    $current_page = $total_pages;
    $offset = ($current_page - 1) * $limit;
}

$sql = "
    SELECT 
        b.*, 
        u.user_id, u.first_name, u.last_name, u.username, u.email, u.contact_number, COALESCE(m.profile_picture, u.profile_picture) as profile_picture,
        COALESCE(sc.service_name, 'Unlimited Gym Use') as resolved_service,
        sc.price as service_price,
        CASE 
            WHEN b.coach_id IS NULL THEN 'Self-Training'
            ELSE CONCAT(tu.first_name, ' ', tu.last_name)
        END as resolved_trainer
    FROM bookings b 
    JOIN members m ON b.member_id = m.member_id 
    JOIN users u ON m.user_id = u.user_id 
    LEFT JOIN service_catalog sc ON b.catalog_service_id = sc.catalog_service_id
    LEFT JOIN coaches c ON b.coach_id = c.coach_id
    LEFT JOIN users tu ON c.user_id = tu.user_id
    $where_clause 
    ORDER BY b.booking_date DESC, b.start_time DESC
    LIMIT :limit OFFSET :offset
";

$stmtBookings = $pdo->prepare($sql);
foreach ($sql_params as $key => $value) {
    $stmtBookings->bindValue($key, $value, in_array($key, [':gym_id', ':user_id'], true) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmtBookings->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmtBookings->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmtBookings->execute();
$bookings_list = $stmtBookings->fetchAll();

$stmtStats = $pdo->prepare("
    SELECT
        COUNT(*) AS total_count,
        SUM(CASE WHEN b.booking_status = 'Pending' THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN b.booking_status = 'Confirmed' THEN 1 ELSE 0 END) AS confirmed_count,
        SUM(CASE WHEN b.booking_status IN ('Rejected', 'Cancelled') THEN 1 ELSE 0 END) AS cancelled_count
    FROM bookings b
    JOIN members m ON b.member_id = m.member_id
    WHERE m.gym_id = ?
");
$stmtStats->execute([$gym_id]);
$booking_stats = $stmtStats->fetch() ?: [];
$total_bookings = (int) ($booking_stats['total_count'] ?? 0);
$pending_bookings = (int) ($booking_stats['pending_count'] ?? 0);
$confirmed_bookings = (int) ($booking_stats['confirmed_count'] ?? 0);
$cancelled_bookings = (int) ($booking_stats['cancelled_count'] ?? 0);

$stmtUsers = $pdo->prepare("
    SELECT DISTINCT u.user_id, CONCAT(u.first_name, ' ', u.last_name) AS full_name
    FROM bookings b
    JOIN members m ON b.member_id = m.member_id
    JOIN users u ON m.user_id = u.user_id
    WHERE m.gym_id = ?
    ORDER BY full_name ASC
");
$stmtUsers->execute([$gym_id]);
$all_users_list = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);
$users_js = array_map(fn($user) => ['id' => (string) $user['user_id'], 'name' => trim($user['full_name'])], $all_users_list);
$current_user_name = 'All Users';
foreach ($all_users_list as $available_user) {
    if ((string) $available_user['user_id'] === (string) $user_filter) {
        $current_user_name = trim($available_user['full_name']);
        break;
    }
}

$active_page = "bookings";

// --- ACTION HANDLER: APPROVE / REJECT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../includes/mailer.php';
    require_once '../includes/audit_logger.php';
    $now = date('Y-m-d H:i:s');
    
    if (isset($_POST['approve_id'])) {
        $booking_id = (int)$_POST['approve_id'];
        
        // 1. Get Context for Email
        $stmtCtx = $pdo->prepare("
            SELECT 
                u.email, u.first_name, u.user_id, b.booking_date, b.start_time, g.gym_name, g.gym_id,
                COALESCE(sc.service_name, 'Personal Training') as resolved_service
            FROM bookings b
            JOIN members m ON b.member_id = m.member_id
            JOIN users u ON m.user_id = u.user_id
            JOIN gyms g ON m.gym_id = g.gym_id
            LEFT JOIN service_catalog sc ON b.catalog_service_id = sc.catalog_service_id
            WHERE b.booking_id = ?
            LIMIT 1
        ");
        $stmtCtx->execute([$booking_id]);
        $ctx = $stmtCtx->fetch();
        
        $pdo->beginTransaction();
        try {
            // 2. Update Booking Status to 'Confirmed'
            $stmtUB = $pdo->prepare("UPDATE bookings SET booking_status = 'Confirmed', approved_by = ?, approved_at = ?, updated_at = ? WHERE booking_id = ?");
            $stmtUB->execute([$_SESSION['user_id'], $now, $now, $booking_id]);

            // 2b. Sync Payment Status to 'Approved'
            $stmtUP = $pdo->prepare("UPDATE payments SET payment_status = 'Approved', verified_by = ?, verified_at = ? WHERE booking_id = ? AND payment_type = 'Booking'");
            $stmtUP->execute([$_SESSION['user_id'], $now, $booking_id]);
            
            // 3. Send Email
            if ($ctx && !empty($ctx['email'])) {
                $subject = "Booking Confirmed - See you at " . htmlspecialchars($ctx['gym_name']) . "!";
                $srv = $ctx['resolved_service'] ?? 'Session';
                $content = "
                    <p>Hello " . htmlspecialchars($ctx['first_name']) . ",</p>
                    <p>Your session for <strong>" . htmlspecialchars($srv) . "</strong> on <strong>" . date('M d, Y', strtotime($ctx['booking_date'])) . "</strong> at <strong>" . htmlspecialchars($ctx['start_time']) . "</strong> has been <strong>APPROVED</strong>.</p>
                    <p>We look forward to seeing you at " . htmlspecialchars($ctx['gym_name']) . "!</p>
                    <p>Thank you for choosing Horizon!</p>
                ";
                sendSystemEmail($ctx['email'], $subject, getEmailTemplate("Appointment Confirmed", $content));
            }

            // 3b. In-App Notification
            $notif_title = "Booking Approved";
            $notif_msg = "Your booking for " . ($ctx['resolved_service'] ?? 'Session') . " on " . date('M d, Y', strtotime($ctx['booking_date'])) . " has been approved.";
            $stmtNotif = $pdo->prepare("INSERT INTO notifications (user_id, gym_id, title, message, notification_type, created_at) VALUES (?, ?, ?, ?, 'booking_approved', ?)");
            $stmtNotif->execute([$ctx['user_id'], $ctx['gym_id'], $notif_title, $notif_msg, $now]);

            // 4. Log Audit
            log_audit_event($pdo, $_SESSION['user_id'], $_SESSION['gym_id'], 'Approve', 'bookings', $booking_id, ['old_status' => 'Pending'], ['new_status' => 'Confirmed']);

            $pdo->commit();
            $_SESSION['success_msg'] = "Booking for " . htmlspecialchars($ctx['first_name']) . " has been approved.";
            header("Location: admin_appointment.php");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error_msg'] = $e->getMessage();
            header("Location: admin_appointment.php");
            exit;
        }
    }

    if (isset($_POST['reject_id'])) {
        $booking_id = (int)$_POST['reject_id'];
        
        $stmtCtx = $pdo->prepare("
            SELECT 
                u.email, u.first_name, u.user_id, g.gym_name, g.gym_id, b.booking_date,
                COALESCE(sc.service_name, 'Personal Training') as resolved_service
            FROM bookings b
            JOIN members m ON b.member_id = m.member_id
            JOIN users u ON m.user_id = u.user_id
            JOIN gyms g ON m.gym_id = g.gym_id
            LEFT JOIN service_catalog sc ON b.catalog_service_id = sc.catalog_service_id
            WHERE b.booking_id = ?
            LIMIT 1
        ");
        $stmtCtx->execute([$booking_id]);
        $ctx = $stmtCtx->fetch();

        $pdo->beginTransaction();
        try {
            // 1. Update Booking Status to 'Rejected'
            $stmtUB = $pdo->prepare("UPDATE bookings SET booking_status = 'Rejected', cancellation_reason = 'Rejected by Staff', updated_at = ? WHERE booking_id = ?");
            $stmtUB->execute([$now, $booking_id]);
            
            // 2. Send Email
            if ($ctx && !empty($ctx['email'])) {
                $subject = "Booking Update - " . htmlspecialchars($ctx['gym_name']);
                $srv = $ctx['resolved_service'] ?? 'Session';
                $content = "
                    <p>Hello " . htmlspecialchars($ctx['first_name']) . ",</p>
                    <p>We regret to inform you that your booking for <strong>" . htmlspecialchars($srv) . "</strong> at " . htmlspecialchars($ctx['gym_name']) . " has been <strong>DECLINED</strong> by the staff.</p>
                    <p>Please contact the gym or book another slot if this was in error.</p>
                    <p>Thank you for your understanding.</p>
                ";
                sendSystemEmail($ctx['email'], $subject, getEmailTemplate("Appointment Declined", $content));
            }

            // 2b. In-App Notification
            if ($ctx) {
                $notif_title = "Booking Rejected";
                $notif_msg = "Your booking for " . ($ctx['resolved_service'] ?? 'Session') . " on " . date('M d, Y', strtotime($ctx['booking_date'])) . " has been declined by the staff.";
                $stmtNotif = $pdo->prepare("INSERT INTO notifications (user_id, gym_id, title, message, notification_type, created_at) VALUES (?, ?, ?, ?, 'booking_rejected', ?)");
                $stmtNotif->execute([$ctx['user_id'], $ctx['gym_id'], $notif_title, $notif_msg, $now]);
            }

            // 3. Log Audit
            log_audit_event($pdo, $_SESSION['user_id'], $_SESSION['gym_id'], 'Reject', 'bookings', $booking_id, ['old_status' => 'Pending'], ['new_status' => 'Rejected', 'reason' => 'Rejected by Staff']);

            $pdo->commit();
            $_SESSION['success_msg'] = "Booking for " . htmlspecialchars($ctx['first_name']) . " has been rejected.";
            header("Location: admin_appointment.php");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error_msg'] = $e->getMessage();
            header("Location: admin_appointment.php");
            exit;
        }
    }

    if (isset($_POST['staff_cancel_id'])) {
        $booking_id = (int)$_POST['staff_cancel_id'];
        $create_refund = isset($_POST['create_refund']) && $_POST['create_refund'] === '1';
        $allowed_cancel_reasons = ['Schedule Conflict', 'Health Issues', 'Emergency', 'Change of Mind', 'Others'];
        $cancel_reason = trim($_POST['cancel_reason'] ?? '');
        if (!in_array($cancel_reason, $allowed_cancel_reasons, true)) {
            $cancel_reason = 'Others';
        }
        $stored_cancel_reason = 'Staff Cancelled: ' . $cancel_reason;
        
        $stmtCtx = $pdo->prepare("
            SELECT 
                b.member_id, u.email, u.first_name, u.user_id, g.gym_name, g.gym_id,
                COALESCE(sc.service_name, 'Personal Training') as resolved_service
            FROM bookings b
            JOIN members m ON b.member_id = m.member_id
            JOIN users u ON m.user_id = u.user_id
            JOIN gyms g ON m.gym_id = g.gym_id
            LEFT JOIN service_catalog sc ON b.catalog_service_id = sc.catalog_service_id
            WHERE b.booking_id = ?
            LIMIT 1
        ");
        $stmtCtx->execute([$booking_id]);
        $ctx = $stmtCtx->fetch();

        if ($ctx) {
            $pdo->beginTransaction();
            try {
                // 1. Update Booking Status to 'Cancelled'
                $stmtUB = $pdo->prepare("UPDATE bookings SET booking_status = 'Cancelled', cancellation_reason = ?, updated_at = ? WHERE booking_id = ?");
                $stmtUB->execute([$stored_cancel_reason, $now, $booking_id]);
                
                // 2. Create Refund if flagged
                if ($create_refund) {
                    $refundStmt = $pdo->prepare("
                        INSERT INTO refund_requests (user_id, gym_id, booking_id, reason, status, created_at)
                        VALUES (?, ?, ?, ?, 'Pending', NOW())
                    ");
                    $refundStmt->execute([$ctx['user_id'], $ctx['gym_id'], $booking_id, $stored_cancel_reason]);
                }
                
                // 3. Send Email
                if (!empty($ctx['email'])) {
                    $subject = "Booking Cancelled - " . htmlspecialchars($ctx['gym_name']);
                    $srv = $ctx['resolved_service'] ?? 'Session';
                    
                    $content = "
                        <p>Hello " . htmlspecialchars($ctx['first_name']) . ",</p>
                        <p>Your booking for <strong>" . htmlspecialchars($srv) . "</strong> at " . htmlspecialchars($ctx['gym_name']) . " has been <strong>CANCELLED</strong> by the staff.</p>
                        <p><strong>Reason:</strong> " . htmlspecialchars($cancel_reason) . "</p>
                    ";
                    if ($create_refund) {
                        $content .= "<p>A refund request has been automatically created on your behalf.</p>";
                    } else {
                        $content .= "<p>No refund is applicable due to policy violations.</p>";
                    }
                    $content .= "<p>Please contact the gym for more details.</p>";

                    sendSystemEmail($ctx['email'], $subject, getEmailTemplate("Appointment Cancelled", $content));
                }

                // 3b. In-App Notification
                $notif_title = "Booking Cancelled";
                $notif_msg = "Your booking for " . ($ctx['resolved_service'] ?? 'Session') . " has been cancelled by the staff. Reason: " . $cancel_reason . ". " . ($create_refund ? "A refund request was created." : "");
                $stmtNotif = $pdo->prepare("INSERT INTO notifications (user_id, gym_id, title, message, notification_type, created_at) VALUES (?, ?, ?, ?, 'booking_cancelled', ?)");
                $stmtNotif->execute([$ctx['user_id'], $ctx['gym_id'], $notif_title, $notif_msg, $now]);

                $pdo->commit();
                $_SESSION['success_msg'] = "Booking for " . htmlspecialchars($ctx['first_name']) . " has been cancelled.";
                header("Location: admin_appointment.php");
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $_SESSION['error_msg'] = $e->getMessage();
                header("Location: admin_appointment.php");
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>Booking Appointments | Horizon Partners</title>
    <link href="https://fonts.googleapis.com/css2?family=<?= urlencode($font_family) ?>:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
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
                    },
                    fontFamily: {
                        lexend: ["Lexend", "sans-serif"],
                    },
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

        /* Sidebar-Aware Layout Logic */
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

        .nav-item:hover { color: var(--text-main); }
        .nav-item .material-symbols-rounded { 
            color: var(--highlight); 
            transition: transform 0.2s ease; 
        }
        .nav-item:hover .material-symbols-rounded { transform: scale(1.12); }

        .nav-item.active {
            color: var(--primary) !important;
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
            background: var(--primary);
            border-radius: 4px 0 0 4px;
        }

        .main-content {
            margin-left: var(--nav-width);
            flex: 1;
            min-width: 0;
            transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow-y: auto;
            overflow-x: hidden;
        }

        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        .input-box {
            background: rgba(255, 255, 255, 0.03);
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
        }

        .input-box::placeholder { color: color-mix(in srgb, var(--text-main) 20%, transparent); }
        
        .input-box option {
            background-color: var(--background);
            color: var(--text-main);
        }
        
        select.input-box {
            cursor: pointer;
            color-scheme: dark;
            padding-right: 2.5rem !important;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='white'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 1em;
        }

        .status-badge {
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            padding: 4px 12px;
            border-radius: 99px;
        }

        .table-header-alt {
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .28em;
            color: color-mix(in srgb, var(--text-main) 46%, transparent);
        }
        .pagination-btn {
            min-width: 42px; height: 36px; padding: 0 14px; border-radius: 12px;
            display: inline-flex; align-items: center; justify-content: center;
            background: rgba(255,255,255,.03); border: 1px solid rgba(255,255,255,.03);
            color: color-mix(in srgb, var(--text-main) 45%, transparent);
            font-size: 10px; font-weight: 900; text-transform: uppercase;
            transition: all .2s ease;
        }
        .pagination-btn:hover:not(.disabled), .pagination-btn.active { background: var(--primary); color: #fff; border-color: var(--primary); }
        .pagination-btn.disabled { opacity: .2; pointer-events: none; }
        .pagination-status { font-size: 10px; font-weight: 900; text-transform: uppercase; letter-spacing: .22em; color: color-mix(in srgb, var(--text-main) 45%, transparent); }
        .custom-select-dropdown, .searchable-dropdown-overlay {
            background: rgba(15, 13, 18, .96); border: 1px solid rgba(255,255,255,.05);
            box-shadow: 0 18px 45px rgba(0,0,0,.45); scrollbar-width: none;
        }
        .custom-select-dropdown::-webkit-scrollbar, .searchable-dropdown-overlay::-webkit-scrollbar { display: none; }
        .tenant-option { border: 1px solid transparent; cursor: pointer; transition: all .2s ease; }
        .tenant-option:hover { background: rgba(var(--primary-rgb), .08); border-color: rgba(var(--primary-rgb), .12); color: var(--primary); }
        .tenant-option.selected { background: var(--primary); color: #fff; }
        .cancel-reason-option {
            border: 1px solid rgba(255,255,255,.06);
            background: rgba(255,255,255,.02);
            transition: all .2s ease;
        }
        .cancel-reason-option:has(input:checked) {
            border-color: var(--primary);
            background: rgba(var(--primary-rgb), .10);
            color: var(--primary);
        }
        .cancel-reason-option input {
            appearance: none;
            width: 16px;
            height: 16px;
            border-radius: 999px;
            border: 2px solid color-mix(in srgb, var(--text-main) 45%, transparent);
            display: inline-grid;
            place-items: center;
            flex: 0 0 auto;
        }
        .cancel-reason-option input:checked {
            border-color: var(--primary);
        }
        .cancel-reason-option input:checked::before {
            content: "";
            width: 6px;
            height: 6px;
            border-radius: 999px;
            background: var(--primary);
        }
        input[type="date"] { color-scheme: dark; }
        input[type="date"]::-webkit-calendar-picker-indicator { filter: invert(1) brightness(1.35); opacity: .75; cursor: pointer; }

        /* Modal Elite Positioning - Sidebar-Aware */
        #confirmationModal, #detailModal {
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            left: 110px;
            z-index: 2000;
            transition: left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .side-nav:hover~#confirmationModal,
        .side-nav:hover~#detailModal { left: 300px; }
        .cancel-modal-container {
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
        .side-nav:hover~.cancel-modal-container { left: 300px; }

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
            width: 90%;
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

        #confirmationModal.active .modal-container, #detailModal.active .modal-container {
            opacity: 1;
            visibility: visible;
            transform: scale(1);
        }
        .cancel-modal-container.flex-important .modal-container {
            opacity: 1;
            visibility: visible;
            transform: scale(1);
        }
        .cancel-modal-container.flex-important .modal-backdrop {
            opacity: 1;
            visibility: visible;
        }

        .flex-important {
            display: flex !important;
        }

        /* Alert System */
        .alert-banner {
            position: fixed;
            top: 24px;
            right: 24px;
            z-index: 2000;
            animation: slideIn 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55) forwards;
        }

        @keyframes slideIn {
            from { transform: translateX(120%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        @keyframes fadeOut {
            from { opacity: 1; transform: scale(1); }
            to { opacity: 0; transform: scale(0.9); }
        }
    </style>
    <script>
        const availableUsers = <?= json_encode($users_js, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const currentUserFilter = "<?= htmlspecialchars((string) $user_filter, ENT_QUOTES) ?>";
        let filterTimeout;
        function autoSubmitAppointmentFilters(delay = 250) {
            clearTimeout(filterTimeout);
            filterTimeout = setTimeout(() => document.getElementById('appointmentFilterForm')?.submit(), delay);
        }
        function syncAppointmentDateLimits() {
            const fromInput = document.getElementById('date_from');
            const toInput = document.getElementById('date_to');
            if (!fromInput || !toInput) return;
            fromInput.max = toInput.value || '';
            toInput.min = fromInput.value || '';
            if (fromInput.value && toInput.value && fromInput.value > toInput.value) fromInput.value = toInput.value;
        }
        function toggleCustomDropdown(trigger, event) {
            event.stopPropagation();
            const container = trigger.closest('.custom-select-container');
            const dropdown = container.querySelector('.custom-select-dropdown');
            document.querySelectorAll('.custom-select-dropdown').forEach((item) => { if (item !== dropdown) item.classList.add('hidden'); });
            document.querySelectorAll('.custom-select-container').forEach((item) => { if (item !== container) item.classList.remove('is-open'); });
            dropdown.classList.toggle('hidden');
            container.classList.toggle('is-open', !dropdown.classList.contains('hidden'));
        }
        function initSearchableDropdown() {
            const container = document.getElementById('userSearchContainer');
            if (!container) return;
            const input = document.getElementById('userSearchInput');
            const dropdown = document.getElementById('userDropdown');
            const list = document.getElementById('userOptionsList');
            const hidden = document.getElementById('hidden_user_id');
            const render = (term = '') => {
                const q = term.toLowerCase();
                const rows = [{ id: 'all', name: 'All Users' }, ...availableUsers].filter((user) => user.name.toLowerCase().includes(q));
                list.innerHTML = rows.map((user) => `<button type="button" class="tenant-option w-full text-left px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-widest ${String(user.id) === String(hidden.value) ? 'selected' : 'text-[--text-main]/65'}" data-id="${user.id}" data-name="${user.name}">${user.name}</button>`).join('') || '<div class="px-4 py-3 text-[10px] font-black uppercase tracking-widest text-[--text-main]/35">No users found</div>';
            };
            input.addEventListener('focus', () => { document.querySelectorAll('.custom-select-dropdown').forEach((item) => item.classList.add('hidden')); dropdown.classList.remove('hidden'); render(input.value); });
            input.addEventListener('input', () => render(input.value));
            list.addEventListener('click', (event) => {
                const option = event.target.closest('.tenant-option');
                if (!option) return;
                hidden.value = option.dataset.id || 'all';
                input.value = option.dataset.name || 'All Users';
                dropdown.classList.add('hidden');
                autoSubmitAppointmentFilters(0);
            });
        }
        function updateHeaderClock() {
            const now = new Date();
            const clockEl = document.getElementById('headerClock');
            if (clockEl) {
                clockEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            }
        }
        setInterval(updateHeaderClock, 1000);
        window.addEventListener('DOMContentLoaded', () => {
            updateHeaderClock();
            syncAppointmentDateLimits();
            initSearchableDropdown();
        });

        function clearAppointmentFilters() {
            window.location.href = 'admin_appointment.php';
        }

        let pendingAction = { id: null, type: null };

        function confirmAction(id, type) {
            pendingAction = { id, type };
            const modal = document.getElementById('confirmationModal');
            const backdrop = document.getElementById('modalBackdrop');
            const title = document.getElementById('modalTitle');
            const message = document.getElementById('modalMessage');
            const confirmBtn = document.getElementById('confirmActionBtn');

            if (type === 'approve') {
                title.innerText = 'Approve Booking?';
                message.innerText = 'Confirming this appointment will notify the member via email.';
                confirmBtn.className = 'flex-1 bg-emerald-500 hover:bg-emerald-600 text-white py-4 rounded-2xl text-xs font-black uppercase tracking-widest transition-all';
            } else {
                title.innerText = 'Reject Booking?';
                message.innerText = 'This will cancel the session and inform the member.';
                confirmBtn.className = 'flex-1 bg-rose-500 hover:bg-rose-600 text-white py-4 rounded-2xl text-xs font-black uppercase tracking-widest transition-all';
            }

            modal.classList.add('active', 'flex-important');
            backdrop.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal() {
            document.getElementById('confirmationModal').classList.remove('active', 'flex-important');
            document.getElementById('modalBackdrop').classList.remove('active');
            document.body.style.overflow = '';
        }

        function submitAction() {
            const form = document.createElement('form');
            form.method = 'POST';
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = pendingAction.type === 'approve' ? 'approve_id' : 'reject_id';
            input.value = pendingAction.id;
            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
        }

        let cancelData = { id: null, isLate: false };
        function initiateStaffCancel(id, isLate) {
            cancelData = { id, isLate };
            document.querySelectorAll('input[name="cancel_reason_choice"]').forEach((input) => input.checked = false);
            if (isLate) {
                document.getElementById('cancelWarningModal').classList.add('active', 'flex-important');
            } else {
                document.getElementById('cancelRefundModal').classList.add('active', 'flex-important');
            }
        }
        
        function closeCancelModal() {
            document.querySelectorAll('.cancel-modal-container').forEach(m => m.classList.remove('active', 'flex-important'));
        }

        function proceedToRefundChoice() {
            document.getElementById('cancelWarningModal').classList.remove('active', 'flex-important');
            document.getElementById('cancelRefundModal').classList.add('active', 'flex-important');
        }

        function submitStaffCancel(createRefund) {
            const reasonInput = document.querySelector('input[name="cancel_reason_choice"]:checked');
            if (!reasonInput) {
                alert('Please select a cancellation reason first.');
                return;
            }
            const form = document.createElement('form');
            form.method = 'POST';
            
            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'staff_cancel_id';
            idInput.value = cancelData.id;
            form.appendChild(idInput);
            
            const refInput = document.createElement('input');
            refInput.type = 'hidden';
            refInput.name = 'create_refund';
            refInput.value = createRefund ? '1' : '0';
            form.appendChild(refInput);

            const reasonHidden = document.createElement('input');
            reasonHidden.type = 'hidden';
            reasonHidden.name = 'cancel_reason';
            reasonHidden.value = reasonInput.value;
            form.appendChild(reasonHidden);
            
            document.body.appendChild(form);
            form.submit();
        }

        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert-banner');
            alerts.forEach(a => {
                a.style.animation = 'fadeOut 0.5s ease-in forwards';
                setTimeout(() => a.remove(), 500);
            });
        }, 5000);

        function openDetailModal(data) {
            document.getElementById('dt_ref').textContent = data.ref || ('BK-' + data.id);
            document.getElementById('dt_booking_id').textContent = data.id || 'N/A';
            document.getElementById('dt_name').textContent = data.name;
            document.getElementById('dt_email').textContent = data.email || 'N/A';
            document.getElementById('dt_contact').textContent = data.contact || 'N/A';
            
            const avatarEl = document.getElementById('dt_avatar');
            if (data.avatar && data.avatar !== '') {
                avatarEl.innerHTML = `<img src="${data.avatar}" class="size-full object-cover" alt="">`;
                avatarEl.classList.remove('bg-primary/10', 'text-primary');
                avatarEl.classList.add('bg-white/[0.02]');
            } else {
                avatarEl.textContent = data.initials || data.name.charAt(0);
                avatarEl.classList.add('bg-primary/10', 'text-primary');
                avatarEl.classList.remove('bg-white/[0.02]');
            }

            document.getElementById('dt_service').textContent = data.service;
            document.getElementById('dt_trainer').textContent = data.trainer;
            document.getElementById('dt_date').textContent = data.date;
            document.getElementById('dt_time').textContent = data.time;
            document.getElementById('dt_amount').textContent = data.amount || 'N/A';
            
            const statusEl = document.getElementById('dt_status');
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
                closeModal();
                closeDetailModal();
            }
        });
        document.addEventListener('click', (event) => {
            const option = event.target.closest('.custom-option');
            if (option) {
                const container = option.closest('.custom-select-container');
                container.querySelector('input[type="hidden"]').value = option.dataset.value;
                container.querySelector('.custom-select-label').textContent = option.textContent.trim();
                container.querySelector('.custom-select-dropdown').classList.add('hidden');
                autoSubmitAppointmentFilters(0);
                return;
            }
            if (!event.target.closest('.custom-select-container')) document.querySelectorAll('.custom-select-dropdown').forEach((item) => item.classList.add('hidden'));
            if (!event.target.closest('#userSearchContainer')) document.getElementById('userDropdown')?.classList.add('hidden');
        });
    </script>
</head>
<body class="antialiased flex h-screen overflow-hidden">

<!-- Dynamic Admin Sidebar -->
<?php include '../includes/admin_sidebar.php'; ?>

<!-- Modal System -->
<div id="confirmationModal">
    <div id="modalBackdrop" class="modal-backdrop" onclick="closeModal()"></div>
    <div class="modal-container overflow-hidden">
        <div class="p-8 border-b border-white/5 flex justify-between items-center bg-white/[0.02]">
            <div class="flex items-center gap-5 min-w-0">
                <div class="size-20 rounded-2xl bg-white/5 border border-white/10 overflow-hidden flex items-center justify-center shadow-lg relative shrink-0">
                    <span class="material-symbols-rounded text-primary text-4xl">verified_user</span>
                </div>
                <div class="min-w-0 text-left">
                    <h3 id="modalTitle" class="text-xl font-black uppercase tracking-tight text-[--text-main] leading-tight truncate">Confirm Action</h3>
                    <p class="text-[10px] font-bold text-primary uppercase tracking-widest mt-1">Booking Approval</p>
                </div>
            </div>
            <button onclick="closeModal()" class="size-10 rounded-xl bg-white/5 hover:bg-rose-500/20 hover:text-rose-500 transition-all flex items-center justify-center border border-white/5 text-[--text-main]/60 shrink-0">
                <span class="material-symbols-rounded text-xl">close</span>
            </button>
        </div>
        <div class="p-8 space-y-6 text-left">
            <section class="bg-white/[0.02] p-6 rounded-2xl border border-white/5">
                <p class="text-[10px] font-bold uppercase tracking-widest text-[--text-main]/40 mb-2">Notice</p>
                <p id="modalMessage" class="text-sm font-medium leading-relaxed text-[--text-main]/70"></p>
            </section>
            <div class="flex w-full gap-4">
                <button onclick="closeModal()" class="flex-1 py-4 rounded-2xl bg-white/5 hover:bg-white/10 border border-white/5 text-[10px] font-black uppercase tracking-widest transition-all text-[--text-main]/40 hover:text-white">Discard</button>
                <button id="confirmActionBtn" onclick="submitAction()" class="flex-1 py-4 rounded-2xl bg-primary hover:bg-primary/90 text-white text-[10px] font-black uppercase tracking-widest shadow-lg shadow-primary/20 transition-all active:scale-[0.98]">Proceed</button>
            </div>
        </div>
    </div>
</div>

<!-- Booking Detail Modal -->
<div id="detailModal">
    <div id="detailBackdrop" class="modal-backdrop" onclick="closeDetailModal()"></div>
    <div class="modal-container overflow-hidden">
        <div class="p-8 border-b border-white/5 flex justify-between items-center bg-white/[0.02]">
            <div class="flex items-center gap-5 min-w-0">
                <div class="size-20 rounded-2xl bg-white/5 border border-white/10 overflow-hidden flex items-center justify-center shadow-lg relative shrink-0 text-primary font-black text-2xl" id="dt_avatar">J</div>
                <div class="min-w-0">
                    <h3 class="text-xl font-black uppercase tracking-tight text-[--text-main] leading-tight truncate" id="dt_name">Member Name</h3>
                    <p class="text-[10px] font-bold text-[--text-main]/40 uppercase tracking-widest mt-1 truncate" id="dt_email">email@example.com</p>
                    <p class="text-[10px] font-bold text-primary uppercase tracking-widest mt-1" id="dt_ref">BK-000000</p>
                </div>
            </div>
            <button onclick="closeDetailModal()" class="size-10 rounded-xl bg-white/5 hover:bg-rose-500/20 hover:text-rose-500 transition-all flex items-center justify-center border border-white/5 text-[--text-main]/60 shrink-0">
                <span class="material-symbols-rounded text-xl">close</span>
            </button>
        </div>
        <div class="p-8 space-y-6 text-left max-h-[70vh] overflow-y-auto no-scrollbar">
            <section class="grid grid-cols-1 sm:grid-cols-2 gap-6 bg-white/[0.02] p-6 rounded-2xl border border-white/5">
                <div class="space-y-1">
                    <p class="text-[10px] font-bold uppercase tracking-widest text-[--text-main]/40">Service</p>
                    <p id="dt_service" class="text-sm font-bold text-primary uppercase tracking-wider">Service</p>
                </div>
                <div class="space-y-1">
                    <p class="text-[10px] font-bold uppercase tracking-widest text-[--text-main]/40">Status</p>
                    <span id="dt_status" class="inline-flex px-3 py-1 rounded-full border text-[8px] font-black uppercase tracking-widest">Pending</span>
                </div>
                <div class="space-y-1">
                    <p class="text-[10px] font-bold uppercase tracking-widest text-[--text-main]/40">Amount</p>
                    <p id="dt_amount" class="text-sm font-black text-[--text-main]">N/A</p>
                </div>
                <div class="space-y-1">
                    <p class="text-[10px] font-bold uppercase tracking-widest text-[--text-main]/40">Contact</p>
                    <p id="dt_contact" class="text-sm font-medium text-[--text-main]/70">N/A</p>
                </div>
            </section>
            <section class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                <div class="bg-white/[0.02] p-5 rounded-2xl border border-white/5 space-y-1">
                    <p class="text-[9px] font-bold uppercase tracking-widest text-[--text-main]/40">Coach</p>
                    <p id="dt_trainer" class="text-xs font-bold text-[--text-main]">Coach Name</p>
                </div>
                <div class="bg-white/[0.02] p-5 rounded-2xl border border-white/5 space-y-1">
                    <p class="text-[9px] font-bold uppercase tracking-widest text-[--text-main]/40">Date</p>
                    <p id="dt_date" class="text-xs font-bold text-[--text-main]">Jan 01, 2026</p>
                </div>
                <div class="bg-white/[0.02] p-5 rounded-2xl border border-white/5 space-y-1">
                    <p class="text-[9px] font-bold uppercase tracking-widest text-[--text-main]/40">Time</p>
                    <p id="dt_time" class="text-xs font-bold text-[--text-main]">12:00 PM</p>
                </div>
                <div class="bg-white/[0.02] p-5 rounded-2xl border border-white/5 space-y-1">
                    <p class="text-[9px] font-bold uppercase tracking-widest text-[--text-main]/40">Booking ID</p>
                    <p id="dt_booking_id" class="text-xs font-bold text-[--text-main]">Shown in ref no.</p>
                </div>
            </section>
        </div>
    </div>
</div>

<!-- Cancel Warning Modal -->
<div id="cancelWarningModal" class="cancel-modal-container">
    <div class="modal-backdrop active" onclick="closeCancelModal()"></div>
    <div class="modal-container overflow-hidden active">
        <div class="p-8 border-b border-white/5 flex justify-between items-center bg-white/[0.02]">
            <div class="flex items-center gap-5 min-w-0">
                <div class="size-20 rounded-2xl bg-white/5 border border-white/10 overflow-hidden flex items-center justify-center shadow-lg relative shrink-0">
                    <span class="material-symbols-rounded text-rose-500 text-4xl">warning</span>
                </div>
                <div class="min-w-0 text-left">
                    <h3 class="text-xl font-black uppercase tracking-tight text-[--text-main] leading-tight truncate">Policy Violation</h3>
                    <p class="text-[10px] font-bold text-rose-500 uppercase tracking-widest mt-1">Late Cancellation</p>
                </div>
            </div>
            <button onclick="closeCancelModal()" class="size-10 rounded-xl bg-white/5 hover:bg-rose-500/20 hover:text-rose-500 transition-all flex items-center justify-center border border-white/5 text-[--text-main]/60 shrink-0">
                <span class="material-symbols-rounded text-xl">close</span>
            </button>
        </div>
        <div class="p-8 space-y-6 text-left">
            <section class="bg-white/[0.02] p-6 rounded-2xl border border-white/5">
                <p class="text-[10px] font-bold uppercase tracking-widest text-[--text-main]/40 mb-2">Notice</p>
                <p class="text-sm font-medium leading-relaxed text-[--text-main]/70">This violates the 1-hour cancellation policy. Are you sure you want to cancel?</p>
            </section>
            <div class="flex w-full gap-4">
                <button onclick="closeCancelModal()" class="flex-1 py-4 rounded-2xl bg-white/5 hover:bg-white/10 border border-white/5 text-[10px] font-black uppercase tracking-widest transition-all text-[--text-main]/40 hover:text-white">Abort</button>
                <button onclick="proceedToRefundChoice()" class="flex-1 py-4 rounded-2xl bg-rose-500 hover:bg-rose-600 text-white text-[10px] font-black uppercase tracking-widest shadow-lg shadow-rose-500/20 transition-all active:scale-[0.98]">Proceed</button>
            </div>
        </div>
    </div>
</div>

<!-- Cancel Refund Choice Modal -->
<div id="cancelRefundModal" class="cancel-modal-container">
    <div class="modal-backdrop active" onclick="closeCancelModal()"></div>
    <div class="modal-container overflow-hidden active">
        <div class="p-8 border-b border-white/5 flex justify-between items-center bg-white/[0.02]">
            <div class="flex items-center gap-5 min-w-0">
                <div class="size-20 rounded-2xl bg-white/5 border border-white/10 overflow-hidden flex items-center justify-center shadow-lg relative shrink-0">
                    <span class="material-symbols-rounded text-amber-500 text-4xl">payments</span>
                </div>
                <div class="min-w-0 text-left">
                    <h3 class="text-xl font-black uppercase tracking-tight text-[--text-main] leading-tight truncate">Refund Request</h3>
                    <p class="text-[10px] font-bold text-amber-500 uppercase tracking-widest mt-1">Cancellation Decision</p>
                </div>
            </div>
            <button onclick="closeCancelModal()" class="size-10 rounded-xl bg-white/5 hover:bg-rose-500/20 hover:text-rose-500 transition-all flex items-center justify-center border border-white/5 text-[--text-main]/60 shrink-0">
                <span class="material-symbols-rounded text-xl">close</span>
            </button>
        </div>
        <div class="p-8 space-y-6 text-left">
            <section class="bg-white/[0.02] p-6 rounded-2xl border border-white/5">
                <p class="text-[10px] font-bold uppercase tracking-widest text-[--text-main]/40 mb-2">Notice</p>
                <p class="text-sm font-medium leading-relaxed text-[--text-main]/70">Do you want to create a refund request for this member?</p>
            </section>
            <section class="bg-white/[0.02] p-5 rounded-2xl border border-white/5 space-y-3">
                <p class="text-[10px] font-bold uppercase tracking-widest text-[--text-main]/40">Cancellation Reason</p>
                <?php foreach (['Schedule Conflict', 'Health Issues', 'Emergency', 'Change of Mind', 'Others'] as $reason): ?>
                    <label class="cancel-reason-option flex items-center gap-3 rounded-xl px-4 py-3 cursor-pointer text-[12px] font-bold text-[--text-main]/70">
                        <input type="radio" name="cancel_reason_choice" value="<?= htmlspecialchars($reason) ?>">
                        <span><?= htmlspecialchars($reason) ?></span>
                    </label>
                <?php endforeach; ?>
            </section>
            <div class="flex w-full gap-4 flex-col">
                <button onclick="submitStaffCancel(true)" class="w-full py-4 rounded-2xl bg-emerald-500 hover:bg-emerald-600 text-white text-[10px] font-black uppercase tracking-widest shadow-lg shadow-emerald-500/20 transition-all active:scale-[0.98]">Yes, Create Refund</button>
                <button onclick="submitStaffCancel(false)" class="w-full py-4 rounded-2xl bg-rose-500/20 border border-rose-500/30 hover:bg-rose-500/40 text-rose-500 hover:text-white text-[10px] font-black uppercase tracking-widest transition-all active:scale-[0.98]">No (Late Penalty)</button>
                <button onclick="closeCancelModal()" class="w-full py-2 text-[10px] font-bold uppercase tracking-widest text-[--text-main]/40 hover:text-white transition-colors mt-2">Cancel Action</button>
            </div>
        </div>
    </div>
</div>

<div class="main-content flex-1 overflow-y-auto no-scrollbar">
    <!-- Alert System -->
    <?php if (isset($_SESSION['success_msg'])): ?>
        <div class="alert-banner px-6 py-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 backdrop-blur-xl flex items-center gap-4 shadow-2xl shadow-emerald-500/10">
            <span class="material-symbols-rounded text-emerald-500">check_circle</span>
            <p class="text-xs font-bold text-emerald-500"><?= $_SESSION['success_msg']; unset($_SESSION['success_msg']); ?></p>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_msg'])): ?>
        <div class="alert-banner px-6 py-4 rounded-2xl bg-rose-500/10 border border-rose-500/20 backdrop-blur-xl flex items-center gap-4 shadow-2xl shadow-rose-500/10">
            <span class="material-symbols-outlined text-rose-500">error</span>
            <p class="text-xs font-bold text-rose-500"><?= $_SESSION['error_msg']; unset($_SESSION['error_msg']); ?></p>
        </div>
    <?php endif; ?>


    <main class="p-10 max-w-[1400px] mx-auto pb-20">
        <header class="mb-12 flex flex-row justify-between items-end gap-6">
            <div>
                <h2 class="text-3xl font-black italic uppercase tracking-tighter leading-none text-white transition-all">
                    <span class="opacity-40">Booking</span> <span class="text-primary">Appointments</span>
                </h2>
                <p class="text-[--text-main]/60 text-[10px] font-bold uppercase tracking-widest mt-2 px-1 opacity-60">Manage Member Sessions & Booking Approvals</p>
            </div>
            <div class="flex items-end gap-8 text-right shrink-0">
                <div class="flex flex-col items-end">
                    <p id="headerClock" class="text-[--text-main] font-black italic text-2xl leading-none tracking-tighter uppercase">00:00:00 AM</p>
                    <p class="text-primary text-[10px] font-black uppercase tracking-[0.2em] leading-none mt-2"><?= date('l, M d, Y') ?></p>
                </div>
            </div>
        </header>

        <section class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6 mb-10">
            <div class="glass-card p-8 status-card-blue relative overflow-hidden group hover:scale-[1.02] transition-all">
                <span class="material-symbols-rounded absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-primary">event_available</span>
                <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">All Bookings</p>
                <h3 class="text-3xl font-black italic uppercase text-[--text-main]"><?= $total_bookings ?></h3>
                <p class="text-[10px] font-black uppercase mt-2 italic text-primary">Session Requests</p>
            </div>
            <div class="glass-card p-8 status-card-yellow relative overflow-hidden group hover:scale-[1.02] transition-all">
                <span class="material-symbols-rounded absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-amber-500">pending_actions</span>
                <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">Pending</p>
                <h3 class="text-3xl font-black italic uppercase text-[--text-main]"><?= $pending_bookings ?></h3>
                <p class="text-amber-500 text-[10px] font-black uppercase mt-2 italic">Needs Review</p>
            </div>
            <div class="glass-card p-8 status-card-green relative overflow-hidden group hover:scale-[1.02] transition-all">
                <span class="material-symbols-rounded absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-emerald-500">verified</span>
                <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">Confirmed</p>
                <h3 class="text-3xl font-black italic uppercase text-[--text-main]"><?= $confirmed_bookings ?></h3>
                <p class="text-emerald-500 text-[10px] font-black uppercase mt-2 italic">Approved Sessions</p>
            </div>
            <div class="glass-card p-8 status-card-red relative overflow-hidden group hover:scale-[1.02] transition-all">
                <span class="material-symbols-rounded absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-rose-500">block</span>
                <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">Closed</p>
                <h3 class="text-3xl font-black italic uppercase text-[--text-main]"><?= $cancelled_bookings ?></h3>
                <p class="text-rose-500 text-[10px] font-black uppercase mt-2 italic">Rejected Or Cancelled</p>
            </div>
        </section>

        <div class="glass-card shadow-2xl overflow-hidden border border-white/5">
            <div class="p-8 border-b border-white/5 bg-white/[0.01]">
                <form id="appointmentFilterForm" method="GET" class="flex flex-nowrap items-center gap-5 relative">
                    <input type="hidden" name="user_id" id="hidden_user_id" value="<?= htmlspecialchars((string) $user_filter) ?>">
                    <div class="flex-1 min-w-[220px] relative group">
                        <span class="material-symbols-rounded absolute left-4 top-1/2 -translate-y-1/2 text-base text-primary/50 transition-transform group-hover:scale-110">search</span>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search records..." oninput="autoSubmitAppointmentFilters(450)"
                            class="w-full h-[52px] bg-white/5 border border-white/10 rounded-2xl pl-12 pr-4 text-[10px] font-black uppercase tracking-widest outline-none text-[--text-main] hover:border-white/20 transition-all focus:border-primary">
                    </div>
                    <div id="userSearchContainer" class="flex-1 min-w-[220px] relative group">
                        <span class="material-symbols-rounded absolute left-4 top-1/2 -translate-y-1/2 text-base text-primary/50 transition-transform group-hover:scale-110 z-10">person_search</span>
                        <input id="userSearchInput" type="text" value="<?= htmlspecialchars($current_user_name) ?>" autocomplete="off"
                            class="w-full h-[52px] bg-white/5 border border-white/10 rounded-2xl pl-12 pr-11 text-[10px] font-black uppercase tracking-widest outline-none text-[--text-main] hover:border-white/20 transition-all focus:border-primary">
                        <span class="material-symbols-rounded absolute right-4 top-1/2 -translate-y-1/2 text-primary/70 text-base pointer-events-none transition-transform group-hover:scale-110">expand_more</span>
                        <div id="userDropdown" class="absolute left-0 right-0 top-full mt-2 z-[100] rounded-xl p-1.5 searchable-dropdown-overlay hidden max-h-72 overflow-y-auto">
                            <div id="userOptionsList" class="space-y-1"></div>
                        </div>
                    </div>
                    <div class="w-[180px] relative group shrink-0 custom-select-container">
                        <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
                        <div class="relative custom-select-trigger cursor-pointer" onclick="toggleCustomDropdown(this, event)">
                            <div class="h-[52px] bg-white/5 border border-white/10 rounded-2xl px-5 pr-11 flex items-center text-[10px] font-black uppercase tracking-widest text-[--text-main] hover:border-white/20 transition-all">
                                <span class="custom-select-label"><?= htmlspecialchars($status ?: 'All Status') ?></span>
                            </div>
                            <span class="material-symbols-rounded absolute right-4 top-1/2 -translate-y-1/2 text-primary/70 text-base pointer-events-none transition-transform group-hover:scale-110">expand_more</span>
                        </div>
                        <div class="absolute left-0 right-0 top-full mt-2 z-[100] rounded-xl p-1.5 space-y-0.5 custom-select-dropdown hidden max-h-64 overflow-y-auto">
                            <?php foreach (['' => 'All Status', 'Pending' => 'Pending', 'Confirmed' => 'Confirmed', 'Rejected' => 'Rejected', 'Cancelled' => 'Cancelled'] as $value => $label): ?>
                                <button type="button" class="custom-option tenant-option w-full text-left px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-widest <?= ($status === $value) ? 'selected' : 'text-[--text-main]/65' ?>" data-value="<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($label) ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <input type="date" name="date_from" id="date_from" value="<?= htmlspecialchars($date_from) ?>" max="<?= htmlspecialchars($date_to) ?>" onchange="syncAppointmentDateLimits(); autoSubmitAppointmentFilters(0)"
                        class="h-[52px] w-[170px] bg-white/5 border border-white/10 rounded-2xl px-5 text-[10px] font-black uppercase tracking-widest outline-none text-[--text-main] hover:border-white/20 transition-all focus:border-primary shrink-0">
                    <input type="date" name="date_to" id="date_to" value="<?= htmlspecialchars($date_to) ?>" min="<?= htmlspecialchars($date_from) ?>" onchange="syncAppointmentDateLimits(); autoSubmitAppointmentFilters(0)"
                        class="h-[52px] w-[170px] bg-white/5 border border-white/10 rounded-2xl px-5 text-[10px] font-black uppercase tracking-widest outline-none text-[--text-main] hover:border-white/20 transition-all focus:border-primary shrink-0">
                    <button type="button" onclick="clearAppointmentFilters()" class="h-[52px] w-[52px] rounded-2xl bg-white/5 border border-white/5 flex items-center justify-center text-white/30 hover:text-white hover:bg-white/10 transition-all" title="Reset filters">
                        <span class="material-symbols-rounded text-lg">refresh</span>
                    </button>
                </form>
            </div>
            <div class="overflow-x-auto no-scrollbar">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-white/5 border-b border-white/5">
                            <th class="px-8 py-5 table-header-alt">Name</th>
                            <th class="px-8 py-5 table-header-alt">Service</th>
                            <th class="px-8 py-5 table-header-alt">Coach</th>
                            <th class="px-8 py-5 table-header-alt text-center">Date</th>
                            <th class="px-8 py-5 table-header-alt text-center">Time</th>
                            <th class="px-8 py-5 table-header-alt text-center">Status</th>
                            <th class="px-8 py-5 table-header-alt text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5 text-sm font-medium">
                        <?php if (empty($bookings_list)): ?>
                        <tr>
                            <td colspan="7" class="px-8 py-24 text-center text-[11px] font-black uppercase tracking-[0.3em] text-[--text-main] opacity-20">
                                No appointments found.
                            </td>
                        </tr>
                        <?php else: foreach ($bookings_list as $appt): ?>
                        <?php
                            $fullName = trim(($appt['first_name'] ?? '') . ' ' . ($appt['last_name'] ?? ''));
                            $initials = strtoupper(substr($appt['first_name'] ?? 'U', 0, 1) . substr($appt['last_name'] ?? '', 0, 1));
                            $avatarSrc = '';
                            if (!empty($appt['profile_picture'])) {
                                $avatarSrc = (strpos($appt['profile_picture'], 'data:image') === 0) ? $appt['profile_picture'] : '../' . $appt['profile_picture'];
                            }
                            $srv_label = $appt['resolved_service'] ?? 'Gym Session';
                            if ($appt['coach_id'] && (stripos($srv_label, 'Gym Use') !== false || empty($srv_label))) $srv_label = 'Personal Training';
                            $st = $appt['booking_status'] ?? 'Pending';
                            $status_class = "text-[--text-main]/45 bg-white/5 border-white/5";
                            if ($st === 'Confirmed') $status_class = "text-emerald-500 bg-emerald-500/10 border-emerald-500/20";
                            elseif ($st === 'Rejected' || $st === 'Cancelled') $status_class = "text-rose-500 bg-rose-500/10 border-rose-500/20";
                            elseif ($st === 'Pending') $status_class = "text-amber-500 bg-amber-500/10 border-amber-500/20";
                            $displayAmount = 'PHP ' . number_format((float)($appt['service_price'] ?? 0), 2);
                        ?>
                        <tr class="hover:bg-white/[0.02] group transition-colors">
                            <td class="px-8 py-6 align-middle">
                                <div class="flex items-center gap-4">
                                    <div class="size-11 rounded-full flex items-center justify-center font-black text-[11px] border border-white/10 shrink-0 overflow-hidden shadow-inner relative" style="background:rgba(var(--primary-rgb), 0.1); color:var(--primary)">
                                        <?php if ($avatarSrc !== ''): ?>
                                            <img src="<?= htmlspecialchars($avatarSrc) ?>" class="size-full object-cover" alt="">
                                        <?php else: ?>
                                            <?= htmlspecialchars($initials) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-[13px] font-bold tracking-wide text-[--text-main] truncate"><?= htmlspecialchars($fullName) ?></p>
                                        <p class="text-[11px] font-semibold text-[--text-main]/50 truncate"><?= htmlspecialchars($appt['email'] ?? '') ?></p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-8 py-6 align-middle">
                                <p class="text-[12px] font-bold text-[--text-main]/70 leading-snug"><?= htmlspecialchars($srv_label) ?></p>
                            </td>
                            <td class="px-8 py-6 align-middle">
                                <p class="text-[12px] font-bold text-[--text-main]/65 leading-snug"><?= htmlspecialchars($appt['resolved_trainer'] ?? 'Self-Training') ?></p>
                            </td>
                            <td class="px-8 py-6 text-center align-middle">
                                <p class="text-[12px] font-bold whitespace-nowrap" style="color:var(--primary)"><?= date('M d, Y', strtotime($appt['booking_date'] ?? 'today')) ?></p>
                            </td>
                            <td class="px-8 py-6 text-center align-middle">
                                <p class="text-[12px] font-bold text-[--text-main]/70 whitespace-nowrap"><?= date('h:i A', strtotime($appt['start_time'] ?? '00:00')) ?></p>
                            </td>
                            <td class="px-8 py-6 text-center align-middle">
                                <span class="px-4 py-1.5 rounded-full border text-[8px] font-black uppercase tracking-widest <?= $status_class ?>"><?= htmlspecialchars($st) ?></span>
                            </td>
                            <td class="px-8 py-6 text-center align-middle">
                                <div class="flex items-center justify-center gap-2">
                                    <button type="button" 
                                        onclick='openDetailModal({
                                            id: "<?= $appt["booking_id"] ?>",
                                            ref: "<?= htmlspecialchars($appt["booking_reference"] ?? ('BK-' . $appt["booking_id"]), ENT_QUOTES) ?>",
                                            name: "<?= htmlspecialchars($fullName, ENT_QUOTES) ?>",
                                            email: "<?= htmlspecialchars($appt["email"] ?? "", ENT_QUOTES) ?>",
                                            contact: "<?= htmlspecialchars($appt["contact_number"] ?? "N/A", ENT_QUOTES) ?>",
                                            initials: "<?= htmlspecialchars($initials, ENT_QUOTES) ?>",
                                            service: "<?= htmlspecialchars($srv_label, ENT_QUOTES) ?>",
                                            trainer: "<?= htmlspecialchars($appt["resolved_trainer"] ?? "Personal Trainer", ENT_QUOTES) ?>",
                                            date: "<?= date("M d, Y", strtotime($appt["booking_date"] ?? "today")) ?>",
                                            time: "<?= date("h:i A", strtotime($appt["start_time"] ?? "00:00")) ?>",
                                            amount: "<?= htmlspecialchars($displayAmount, ENT_QUOTES) ?>",
                                            avatar: "<?= htmlspecialchars($avatarSrc, ENT_QUOTES) ?>",
                                            status: "<?= $st ?>",
                                            statusClass: "<?= $status_class ?>"
                                        })'
                                        class="size-8 rounded-lg bg-white/5 border border-white/10 text-[--text-main]/40 flex items-center justify-center hover:bg-primary hover:text-white transition-all" title="View Details">
                                        <span class="material-symbols-rounded text-base">visibility</span>
                                    </button>
                                    <?php if ($st === 'Pending'): ?>
                                        <button onclick="confirmAction(<?= $appt['booking_id'] ?>, 'approve')" class="size-8 rounded-lg bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-500 hover:bg-emerald-500 hover:text-white transition-all active:scale-95" title="Approve">
                                            <span class="material-symbols-rounded text-[18px]">check_circle</span>
                                        </button>
                                        <button onclick="confirmAction(<?= $appt['booking_id'] ?>, 'reject')" class="size-8 rounded-lg bg-rose-500/10 border border-rose-500/20 flex items-center justify-center text-rose-500 hover:bg-rose-500 hover:text-white transition-all active:scale-95" title="Reject">
                                            <span class="material-symbols-rounded text-[18px]">cancel</span>
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($st === 'Confirmed'): 
                                        $bDt = new DateTime($appt['booking_date'] . ' ' . $appt['start_time']);
                                        $nDt = new DateTime();
                                        $diffHrs = ($bDt->getTimestamp() - $nDt->getTimestamp()) / 3600;
                                        $is_late = ($diffHrs < 1) ? 'true' : 'false';
                                    ?>
                                        <button onclick="initiateStaffCancel(<?= $appt['booking_id'] ?>, <?= $is_late ?>)" class="size-8 rounded-lg bg-rose-500/10 border border-rose-500/20 flex items-center justify-center text-rose-500 hover:bg-rose-500 hover:text-white transition-all active:scale-95" title="Cancel Booking">
                                            <span class="material-symbols-rounded text-[18px]">cancel</span>
                                        </button>
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
                $showing_end = min($offset + $limit, $total_records);
                $page_params = $_GET;
                unset($page_params['page']);
                $page_base = 'admin_appointment.php?' . http_build_query($page_params);
                $page_joiner = empty($page_params) ? 'page=' : '&page=';
            ?>
            <div class="px-8 py-5 border-t border-white/5 bg-white/[0.01] flex justify-between items-center">
                <p class="pagination-status">Showing <?= $showing_start ?> to <?= $showing_end ?> of <?= $total_records ?> appointments</p>
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
</body>
</html>
