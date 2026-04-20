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

$where_clause = "WHERE " . implode(' AND ', $sql_parts);

$sql = "
    SELECT 
        b.*, 
        u.first_name, u.last_name, u.username, u.profile_picture,
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
";

$stmtBookings = $pdo->prepare($sql);
$stmtBookings->execute($sql_params);
$bookings_list = $stmtBookings->fetchAll();

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
                u.email, u.first_name, b.booking_date, b.start_time, g.gym_name,
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
                u.email, u.first_name, g.gym_name,
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
}
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>Appointment Masterlist | Horizon Partners</title>
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
            font-family: 'Lexend', sans-serif;
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
            font-style: italic;
            padding: 4px 12px;
            border-radius: 99px;
        }

        /* Modal Elite Positioning - Sidebar-Aware */
        #confirmationModal, #detailModal {
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            left: var(--nav-width);
            z-index: 200;
            transition: left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            display: none;
            align-items: center;
            justify-content: center;
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
            width: 90%;
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

        #confirmationModal.active .modal-container, #detailModal.active .modal-container {
            opacity: 1;
            visibility: visible;
            transform: scale(1);
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
        function updateHeaderClock() {
            const now = new Date();
            const clockEl = document.getElementById('headerClock');
            if (clockEl) {
                clockEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            }
        }
        setInterval(updateHeaderClock, 1000);
        window.addEventListener('DOMContentLoaded', updateHeaderClock);

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

        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert-banner');
            alerts.forEach(a => {
                a.style.animation = 'fadeOut 0.5s ease-in forwards';
                setTimeout(() => a.remove(), 500);
            });
        }, 5000);

        function openDetailModal(data) {
            document.getElementById('dt_ref').textContent = data.ref || ('BK-' + data.id);
            document.getElementById('dt_name').textContent = data.name;
            
            const avatarEl = document.getElementById('dt_avatar');
            if (data.avatar && data.avatar !== '') {
                avatarEl.innerHTML = `<img src="${data.avatar}" class="size-full object-cover rounded-2xl" alt="">`;
                avatarEl.classList.remove('bg-primary/10', 'text-primary');
                avatarEl.classList.add('bg-white/[0.02]');
            } else {
                avatarEl.textContent = data.name.charAt(0);
                avatarEl.classList.add('bg-primary/10', 'text-primary');
                avatarEl.classList.remove('bg-white/[0.02]');
            }

            document.getElementById('dt_service').textContent = data.service;
            document.getElementById('dt_trainer').textContent = data.trainer;
            document.getElementById('dt_schedule').textContent = data.schedule;
            document.getElementById('dt_amount').textContent = '₱' + parseFloat(data.amount).toLocaleString(undefined, {minimumFractionDigits: 2});
            
            const statusEl = document.getElementById('dt_status');
            statusEl.textContent = data.status;
            statusEl.className = 'status-badge ' + data.statusClass;

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
    </script>
</head>
<body class="antialiased flex h-screen overflow-hidden">

<!-- Dynamic Admin Sidebar -->
<?php include '../includes/admin_sidebar.php'; ?>

<!-- Modal System -->
<div id="confirmationModal">
    <div id="modalBackdrop" class="modal-backdrop" onclick="closeModal()"></div>
    <div class="modal-container p-10 flex flex-col items-center text-center">
        <div class="size-20 rounded-3xl bg-primary/10 border border-primary/20 flex items-center justify-center mb-6">
            <span class="material-symbols-rounded text-primary text-4xl">verified_user</span>
        </div>
        <h3 id="modalTitle" class="text-2xl font-black italic uppercase tracking-tighter text-white mb-2">Confirm Action</h3>
        <p id="modalMessage" class="text-[--text-main]/60 text-sm font-medium leading-relaxed mb-10 px-4"></p>
        <div class="flex w-full gap-4">
            <button onclick="closeModal()" class="flex-1 py-4 rounded-2xl bg-white/5 hover:bg-white/10 border border-white/5 text-[10px] font-black uppercase tracking-widest transition-all text-[--text-main]/40 hover:text-white">Discard</button>
            <button id="confirmActionBtn" onclick="submitAction()" class="flex-1 py-4 rounded-2xl bg-primary hover:bg-primary/90 text-white text-[10px] font-black uppercase italic tracking-widest shadow-lg shadow-primary/20 transition-all active:scale-[0.98]">Proceed</button>
        </div>
    </div>
</div>

<!-- Booking Detail Modal -->
<div id="detailModal">
    <div id="detailBackdrop" class="modal-backdrop" onclick="closeDetailModal()"></div>
    <div class="modal-container p-10 flex flex-col items-center">
        <div class="w-full flex justify-between items-start mb-8">
            <div>
                <h3 class="text-2xl font-black italic uppercase tracking-tighter text-white leading-none">Booking <span class="text-primary">Details</span></h3>
                <p class="text-[10px] font-bold text-[--text-main]/40 uppercase tracking-widest mt-2" id="dt_ref">REF-000000</p>
            </div>
            <button onclick="closeDetailModal()" class="size-10 rounded-xl bg-white/5 flex items-center justify-center hover:bg-rose-500/20 hover:text-rose-500 transition-all group">
                <span class="material-symbols-rounded text-xl group-hover:rotate-90 transition-transform">close</span>
            </button>
        </div>

        <div class="w-full space-y-6">
            <div class="glass-card p-6 border-white/5 bg-white/[0.02]">
                <p class="text-[10px] font-black uppercase text-primary mb-4 tracking-widest">Member Information</p>
                <div class="flex items-center gap-4">
                    <div class="size-12 rounded-2xl bg-white/[0.02] flex items-center justify-center text-primary font-black italic text-xl overflow-hidden" id="dt_avatar">J</div>
                    <div>
                        <p class="text-base font-black italic uppercase text-white" id="dt_name">John Doe</p>
                    </div>
                </div>
            </div>

            <div class="w-full grid grid-cols-2 gap-4">
                <div class="glass-card p-5 border-white/5 bg-white/[0.02]">
                    <p class="text-[9px] font-black uppercase text-[--text-main]/40 mb-1 tracking-widest">Service Item</p>
                    <p class="text-xs font-black italic text-white uppercase" id="dt_service">PT Session</p>
                </div>
                <div class="glass-card p-5 border-white/5 bg-white/[0.02]">
                    <p class="text-[9px] font-black uppercase text-[--text-main]/40 mb-1 tracking-widest">Fee/Amount</p>
                    <p class="text-xs font-black italic text-white uppercase" id="dt_amount">₱0.00</p>
                </div>
            </div>

            <div class="w-full glass-card p-5 border-white/5 bg-white/[0.02] flex justify-between items-center">
                <div>
                    <p class="text-[9px] font-black uppercase text-[--text-main]/40 mb-1 tracking-widest">Assigned Trainer</p>
                    <p class="text-xs font-bold text-white italic" id="dt_trainer">Coach Name</p>
                </div>
                <div class="text-right">
                    <p class="text-[9px] font-black uppercase text-[--text-main]/40 mb-1 tracking-widest">Schedule</p>
                    <p class="text-xs font-bold text-primary italic" id="dt_schedule">Jan 01, 10:00 AM</p>
                </div>
            </div>

            <div class="w-full glass-card p-5 border-white/5 bg-white/[0.02] flex justify-between items-center">
                <p class="text-[9px] font-black uppercase text-[--text-main]/40 tracking-widest">Current Status Indices</p>
                <span class="status-badge italic" id="dt_status">PENDING</span>
            </div>
        </div>

        <button onclick="closeDetailModal()" class="w-full mt-8 py-4 rounded-2xl bg-white/5 hover:bg-white/10 border border-white/5 text-[11px] font-black uppercase tracking-[0.2em] transition-all text-white active:scale-[0.98]">
            Dismiss Record
        </button>
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
                <h2 class="text-3xl font-black italic uppercase tracking-tighter leading-none text-white transition-all"><span class="opacity-40">Appointment</span> <span class="text-primary">Masterlist</span></h2>
                <p class="text-[--text-main]/60 text-[10px] font-bold uppercase tracking-widest mt-2 px-1 opacity-60">Operations Scheduler • Session Registry</p>
            </div>
            <div class="flex items-end gap-8 text-right shrink-0">
                <div class="flex flex-col items-end">
                    <p id="headerClock" class="text-[--text-main] font-black italic text-2xl leading-none tracking-tighter uppercase">00:00:00 AM</p>
                    <p class="text-primary text-[10px] font-black uppercase tracking-[0.2em] leading-none mt-2"><?= date('l, M d, Y') ?></p>
                </div>
            </div>
        </header>

        <!-- Dynamic Filter Matrix -->
        <div class="mb-10">
            <form method="GET" class="glass-card p-8 relative overflow-hidden">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 items-end">
                    <div class="space-y-2 lg:col-span-1">
                        <p class="text-[--text-main]/60 text-[10px] font-black uppercase tracking-widest ml-1">Identity Filter</p>
                        <div class="relative">
                            <span class="material-symbols-rounded absolute left-4 top-1/2 -translate-y-1/2 text-[--text-main]/40 text-lg">search</span>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search member..." class="input-box pl-12 w-full">
                        </div>
                    </div>

                    <div class="space-y-2">
                        <p class="text-[--text-main]/60 text-[10px] font-black uppercase tracking-widest ml-1">Date Registry (Start)</p>
                        <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" class="input-box w-full">
                    </div>

                    <div class="space-y-2">
                        <p class="text-[--text-main]/60 text-[10px] font-black uppercase tracking-widest ml-1">Date Registry (End)</p>
                        <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" class="input-box w-full">
                    </div>

                    <div class="space-y-2">
                        <p class="text-[--text-main]/60 text-[10px] font-black uppercase tracking-widest ml-1">Status Offset</p>
                        <select name="status" class="input-box w-full">
                            <option value="">All Matrix Status</option>
                            <option value="Confirmed" <?= ($status === 'Confirmed') ? 'selected' : '' ?>>Confirmed</option>
                            <option value="Pending" <?= ($status === 'Pending') ? 'selected' : '' ?>>Pending</option>
                            <option value="Rejected" <?= ($status === 'Rejected') ? 'selected' : '' ?>>Rejected</option>
                        </select>
                    </div>

                    <div class="flex gap-3">
                        <button type="submit" class="flex-1 bg-primary hover:bg-primary/90 text-white h-[46px] rounded-xl text-[10px] font-black uppercase tracking-widest transition-all shadow-lg shadow-primary/20 active:scale-95">Execute Apply</button>
                        <button type="button" onclick="clearAppointmentFilters()" class="size-[46px] rounded-xl bg-white/5 border border-white/5 flex items-center justify-center text-[--text-main]/40 hover:bg-rose-500/10 hover:text-rose-500 transition-all group active:scale-95">
                            <span class="material-symbols-rounded text-xl group-hover:rotate-180 transition-transform">restart_alt</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <div class="flex justify-between items-center mb-6">
            <p class="text-[10px] font-black uppercase tracking-widest text-gray-500 italic">LIVE SCHEDULER MATRIX — <span class="text-white">ACTIVE FEED</span></p>
            <div class="flex items-center gap-4">
                <span class="px-4 py-1.5 rounded-full bg-white/5 border border-white/5 text-[9px] font-black uppercase tracking-widest text-gray-400">Total Bookings: <?= count($bookings_list) ?></span>
            </div>
        </div>

        <div class="glass-card shadow-2xl overflow-hidden border border-white/5">
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="text-[10px] font-black uppercase tracking-[0.15em] text-[--text-main]/40 border-b border-white/5 bg-white/[0.01]">
                            <th class="px-8 py-5">Member</th>
                            <th class="px-8 py-5 text-center">Service / Coach</th>
                            <th class="px-8 py-5 text-center">Schedule</th>
                            <th class="px-8 py-5 text-center">Status Index</th>
                            <th class="px-8 py-5 text-right">Actions Matrix</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <?php if (empty($bookings_list)): ?>
                        <tr>
                            <td colspan="5" class="px-8 py-24 text-center">
                                <span class="material-symbols-rounded text-4xl text-[--text-main]/20 mb-4 block">event_busy</span>
                                <p class="text-[10px] font-black uppercase tracking-widest text-[--text-main]/40 italic">No scheduled appointments detected in current matrix.</p>
                            </td>
                        </tr>
                        <?php else: foreach ($bookings_list as $appt): ?>
                        <tr class="hover:bg-white/[0.02] group transition-colors">
                            <td class="px-8 py-6">
                                <div class="flex items-center gap-4">
                                    <div class="size-10 rounded-2xl bg-white/[0.03] flex items-center justify-center overflow-hidden">
                                        <?php if (!empty($appt['profile_picture'])): 
                                            $pfp_src = (strpos($appt['profile_picture'], 'data:image') === 0) ? $appt['profile_picture'] : '../' . $appt['profile_picture'];
                                        ?>
                                            <img src="<?= htmlspecialchars($pfp_src) ?>" class="size-full object-cover" alt="">
                                        <?php else: ?>
                                            <div class="size-full bg-primary/10 flex items-center justify-center text-primary font-black italic text-base">
                                                <?= substr($appt['first_name'] ?? 'U', 0, 1) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <p class="text-[13px] font-black italic uppercase text-white group-hover:text-primary transition-colors"><?= htmlspecialchars(($appt['first_name'] ?? '') . ' ' . ($appt['last_name'] ?? '')) ?></p>
                                    </div>
                                </div>
                            </td>
                             <td class="px-8 py-6">
                                <?php 
                                    $srv_label = $appt['resolved_service'] ?? 'Gym Session';
                                    if ($appt['coach_id'] && (stripos($srv_label, 'Gym Use') !== false || empty($srv_label))) {
                                        $srv_label = "Personal Training";
                                    }
                                ?>
                                <p class="text-xs font-black italic text-white uppercase"><?= htmlspecialchars($srv_label) ?></p>
                                <p class="text-[10px] font-black text-primary tracking-widest uppercase mt-0.5"><?= htmlspecialchars($appt['resolved_trainer'] ?? 'Personal Trainer') ?></p>
                            </td>
                            <td class="px-8 py-6 text-center">
                                <div class="space-y-0.5 inline-block text-center">
                                    <p class="text-[11px] font-black italic text-white uppercase"><?= htmlspecialchars($appt['start_time'] ?? '00:00') ?></p>
                                    <p class="text-[9px] font-bold text-[--text-main]/20 uppercase tracking-widest italic"><?= date('M d, Y', strtotime($appt['booking_date'] ?? 'today')) ?></p>
                                </div>
                            </td>
                            <td class="px-8 py-6 text-center">
                                <?php 
                                    $st = $appt['booking_status'] ?? 'Pending';
                                    $status_class = "text-[--text-main]/40 bg-white/5 border-white/5";
                                    if ($st === 'Confirmed') {
                                        $status_class = "text-emerald-500 bg-emerald-500/10 border-emerald-500/20";
                                    } elseif ($st === 'Rejected' || $st === 'Cancelled') {
                                        $status_class = "text-rose-500 bg-rose-500/10 border-rose-500/20";
                                    } elseif ($st === 'Pending') {
                                        $status_class = "text-amber-500 bg-amber-500/10 border-amber-500/20";
                                    }
                                ?>
                                <span class="status-badge <?= $status_class ?> border"><?= $st ?></span>
                            </td>
                            <td class="px-8 py-6 text-right">
                                <div class="flex justify-end gap-2">
                                    <button type="button" 
                                        onclick='openDetailModal({
                                            id: "<?= $appt["booking_id"] ?>",
                                            ref: "<?= $appt["booking_reference"] ?>",
                                            name: "<?= htmlspecialchars(($appt["first_name"] ?? "") . " " . ($appt["last_name"] ?? ""), ENT_QUOTES) ?>",
                                            service: "<?= htmlspecialchars($appt["resolved_service"] ?? "Gym Session", ENT_QUOTES) ?>",
                                            trainer: "<?= htmlspecialchars($appt["resolved_trainer"] ?? "Personal Trainer", ENT_QUOTES) ?>",
                                            schedule: "<?= date("M d, Y h:i A", strtotime(($appt["booking_date"] ?? "today") . " " . ($appt["start_time"] ?? "00:00"))) ?>",
                                            amount: "<?= $appt["service_price"] ?? 0 ?>",
                                            avatar: "<?= !empty($appt['profile_picture']) ? ((strpos($appt['profile_picture'], 'data:image') === 0) ? $appt['profile_picture'] : '../' . $appt['profile_picture']) : '' ?>",
                                            status: "<?= $st ?>",
                                            statusClass: "<?= $status_class ?>"
                                        })'
                                        class="size-8 rounded-lg bg-white/5 border border-white/10 text-[--text-main]/40 flex items-center justify-center hover:bg-primary hover:text-white transition-all shadow-lg active:scale-90" title="View Details">
                                        <span class="material-symbols-rounded text-base">search</span>
                                    </button>
                                    <?php if ($st === 'Pending'): ?>
                                        <button onclick="confirmAction(<?= $appt['booking_id'] ?>, 'approve')" class="size-8 rounded-lg bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-500 hover:bg-emerald-500 hover:text-white transition-all active:scale-95" title="Approve">
                                            <span class="material-symbols-rounded text-[18px]">check_circle</span>
                                        </button>
                                        <button onclick="confirmAction(<?= $appt['booking_id'] ?>, 'reject')" class="size-8 rounded-lg bg-rose-500/10 border border-rose-500/20 flex items-center justify-center text-rose-500 hover:bg-rose-500 hover:text-white transition-all active:scale-95" title="Reject">
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
        </div>
    </main>
</div>
</body>
</html>