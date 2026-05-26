<?php
session_start();
require_once '../db.php';

// Security Check
$role = strtolower($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || $role !== 'coach') {
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$gym_id = $_SESSION['gym_id'];
$username = $_SESSION['username'];
$coach_name = ($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '');
$active_page = "schedule";

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
$stmtGym = $pdo->prepare("SELECT owner_user_id, gym_name FROM gyms WHERE gym_id = ?");
$stmtGym->execute([$gym_id]);
$gym_data = $stmtGym->fetch();
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
    'page_slug'       => '',
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
    'page_slug'   => $configs['page_slug']   ?? '',
    'system_name' => $configs['system_name'] ?? $gym_name,
];
// ─────────────────────────────────────────────────────────────────────────────

// Fetch Coach ID (from coaches table)
$stmtCoach = $pdo->prepare("SELECT coach_id FROM coaches WHERE user_id = ? AND gym_id = ? LIMIT 1");
$stmtCoach->execute([$user_id, $gym_id]);
$coach_info = $stmtCoach->fetch();
$coach_id = $coach_info ? $coach_info['coach_id'] : 0;

$msg = '';
$week_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

// Handle Save Availability
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_availability'])) {
    if ($coach_id <= 0) {
        $msg = "Error: Coach profile not found.";
    } else {
        try {
            $pdo->beginTransaction();
            
            foreach ($week_days as $day) {
                $is_off = isset($_POST["off_$day"]) ? 1 : 0;
                $start = $_POST["start_$day"] ?? '08:00';
                $end = $_POST["end_$day"] ?? '12:00';
                $start2 = $_POST["start2_$day"] ?? '13:00';
                $end2 = $_POST["end2_$day"] ?? '17:00';
                $status = $is_off ? 'Off' : 'Available';

                // Check if record for this day already exists for the coach
                $stmtCheck = $pdo->prepare("SELECT coach_schedule_id FROM coach_schedules WHERE coach_id = ? AND day_of_week = ?");
                $stmtCheck->execute([$coach_id, $day]);
                $existing_id = $stmtCheck->fetchColumn();

                if ($existing_id) {
                    // Update existing record
                    $stmtUpdate = $pdo->prepare("UPDATE coach_schedules SET morning_start = ?, morning_end = ?, afternoon_start = ?, afternoon_end = ?, availability_status = ?, updated_at = NOW() WHERE coach_schedule_id = ?");
                    $stmtUpdate->execute([$start, $end, $start2, $end2, $status, $existing_id]);
                } else {
                    // Insert new record
                    $stmtInsert = $pdo->prepare("INSERT INTO coach_schedules (coach_id, day_of_week, morning_start, morning_end, afternoon_start, afternoon_end, availability_status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                    $stmtInsert->execute([$coach_id, $day, $start, $end, $start2, $end2, $status]);
                }
            }
            $pdo->commit();
            $msg = "Schedule updated successfully.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $msg = "Error updating schedule: " . $e->getMessage();
        }
    }
}

// Fetch current availability
$avail_map = [];
foreach ($week_days as $day) {
    $avail_map[$day] = ['start_time' => '08:00', 'end_time' => '12:00', 'start_time_2' => '13:00', 'end_time_2' => '17:00', 'is_off_day' => 0];
}

$stmtAvail = $pdo->prepare("SELECT * FROM coach_schedules WHERE coach_id = ?");
$stmtAvail->execute([$coach_id]);
$rows = $stmtAvail->fetchAll();
foreach ($rows as $r) {
    $d = $r['day_of_week'];
    if (isset($avail_map[$d])) {
        $avail_map[$d] = [
            'start_time' => !empty($r['morning_start']) ? date('H:i', strtotime($r['morning_start'])) : '08:00',
            'end_time' => !empty($r['morning_end']) ? date('H:i', strtotime($r['morning_end'])) : '12:00',
            'start_time_2' => !empty($r['afternoon_start']) ? date('H:i', strtotime($r['afternoon_start'])) : '13:00',
            'end_time_2' => !empty($r['afternoon_end']) ? date('H:i', strtotime($r['afternoon_end'])) : '17:00',
            'is_off_day' => (trim($r['availability_status']) === 'Off') ? 1 : 0
        ];
    }
}

// Fetch Bookings for Daily View
$all_bookings = [];
if ($coach_id > 0) {
    $stmtBookings = $pdo->prepare("
        SELECT b.*, u.username, CONCAT(u.first_name, ' ', u.last_name) as fullname, sc.service_name as service_name
        FROM bookings b
        JOIN members m ON b.member_id = m.member_id
        JOIN users u ON m.user_id = u.user_id
        LEFT JOIN service_catalog sc ON b.catalog_service_id = sc.catalog_service_id
        WHERE b.coach_id = ? AND b.booking_status IN ('Approved', 'Pending', 'Confirmed', 'Completed')
    ");
    $stmtBookings->execute([$coach_id]);
    $fetched_bookings = $stmtBookings->fetchAll();

    foreach ($fetched_bookings as $fb) {
        $all_bookings[] = [
            'booking_id' => $fb['booking_id'] ?? 0,
            'ts_start' => strtotime($fb['booking_date'] . ' ' . $fb['start_time']),
            'ts_end'   => strtotime($fb['booking_date'] . ' ' . $fb['end_time']),
            'fullname' => $fb['fullname'],
            'status'   => $fb['booking_status'],
            'service'  => $fb['service_name'] ?: 'PT Session',
            'date_str' => date('F d, Y', strtotime($fb['booking_date'])),
            'time_str' => date('h:i A', strtotime($fb['start_time'])) . ' - ' . date('h:i A', strtotime($fb['end_time']))
        ];
    }
}

?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>My Schedule | Horizon Systems</title>
    <link href="https://fonts.googleapis.com/css2?family=<?= urlencode($font_family) ?>:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet" />
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { 
                "primary": "var(--primary)", 
                "background-dark": "var(--background)", 
                "surface-dark": "var(--card-bg)", 
                "border-subtle": "rgba(255,255,255,0.05)" 
            } } }
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
            --background-rgb: 10, 9, 13;
            --card-bg:       <?= $card_bg_css ?>;
            --card-blur:     20px;
        }

        body {
            font-family: '<?= $font_family ?>', sans-serif;
            background-color: var(--background);
            color: var(--text-main);
            overflow: hidden;
        }

        ::selection {
            background: var(--primary);
            color: white;
        }
        ::-moz-selection {
            background: var(--primary);
            color: white;
        }

        .glass-card {
            border: 1px solid rgba(255,255,255,0.05);
            border-radius: 24px;
            transition: all 0.4s cubic-bezier(0.4,0,0.2,1);
            overflow: hidden;
            isolation: isolate;
            position: relative;
        }
        .glass-card::before {
            content: '';
            position: absolute;
            inset: 0;
            background: var(--card-bg);
            backdrop-filter: blur(var(--card-blur));
            -webkit-backdrop-filter: blur(var(--card-blur));
            z-index: -1;
            border-radius: inherit;
            pointer-events: none;
        }

        .side-nav {
            width: 110px;
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            position: fixed;
            left: 0; top: 0;
            height: 100vh;
            z-index: 50;
            background: var(--background);
            border-right: 1px solid rgba(255, 255, 255, 0.05);
        }
        .side-nav:hover { width: 300px; }

        .main-content {
            margin-left: 110px;
            flex: 1;
            min-width: 0;
            transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .side-nav:hover~.main-content { margin-left: 300px; }

        .nav-label { opacity: 0; transform: translateX(-15px); transition: all 0.3s ease-in-out; white-space: nowrap; pointer-events: none; color: var(--text-main); }
        .side-nav:hover .nav-label { opacity: 1; transform: translateX(0); pointer-events: auto; }

        .nav-item {
            display: flex; align-items: center; gap: 16px;
            padding: 10px 38px;
            transition: opacity 0.2s ease, color 0.2s ease;
            text-decoration: none; white-space: nowrap;
            font-size: 11px; font-weight: 800;
            text-transform: uppercase; letter-spacing: 0.05em;
            color: color-mix(in srgb, var(--text-main) 45%, transparent);
        }
        .nav-item:hover { color: var(--text-main); }
        .nav-item .material-symbols-outlined { color: var(--highlight); transition: transform 0.2s ease; }
        .nav-item:hover .material-symbols-outlined { transform: scale(1.1); }
        .nav-item.active { color: var(--primary) !important; position: relative; }
        .nav-item.active .material-symbols-outlined { color: var(--primary); }
        .nav-item.active::after {
            content: ''; position: absolute;
            right: 0px; top: 50%; transform: translateY(-50%);
            width: 4px; height: 24px;
            background: var(--primary); border-radius: 4px 0 0 4px;
        }

        .nav-section-label {
            max-height: 0; opacity: 0; overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            margin: 0 !important; pointer-events: none;
        }
        .side-nav:hover .nav-section-label {
            max-height: 20px; opacity: 1;
            margin-bottom: 8px !important; pointer-events: auto;
        }

        .no-scrollbar::-webkit-scrollbar { display: none !important; }
        * { -ms-overflow-style: none !important; scrollbar-width: none !important; }

        .label-muted { color: var(--text-main); opacity: 0.5; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.15em; }

        @keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .animate-slide-up { animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards; opacity: 0; }

        input[type="time"]::-webkit-calendar-picker-indicator { filter: invert(1); cursor: pointer; }
        
        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeIn 0.4s ease-out; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

        .day-card { transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); background: rgba(255, 255, 255, 0.03); border: 1px solid rgba(255, 255, 255, 0.05); }
        .day-card:hover { background: rgba(255, 255, 255, 0.05); border-color: rgba(255, 255, 255, 0.1); transform: translateY(-2px); }
        .day-card.is-off { background: rgba(244, 63, 94, 0.02); border-color: rgba(244, 63, 94, 0.1); }
        .day-card.is-off .shift-inputs { opacity: 0.2; pointer-events: none; filter: grayscale(1); }

        .toggle-switch {
            position: relative; display: inline-flex; width: 52px; height: 28px;
            background-color: rgba(255,255,255,0.05); border-radius: 100px; padding: 4px;
            transition: all 0.3s ease; cursor: pointer; border: 1px solid rgba(255,255,255,0.05);
        }
        .toggle-switch .dot { width: 18px; height: 18px; background-color: white; border-radius: 50%; transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
        .toggle-input:checked + .toggle-switch { background-color: #f43f5e; border-color: #fb7185; }
        .toggle-input:checked + .toggle-switch .dot { transform: translateX(24px); }

        .is-day-off-view .booked-slot-box, .is-day-off-view .available-slot-box { display: none !important; }
        .is-day-off-view .blank-slot-row { display: flex !important; opacity: 0.5 !important; }
        
        #custom-modal {
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            left: 110px;
            z-index: 250;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            transition: left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .side-nav:hover ~ #custom-modal { left: 300px; }
        #custom-modal.flex { display: flex !important; }

        #dayBookingsModal {
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            left: 110px;
            z-index: 190;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            transition: left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .side-nav:hover ~ #dayBookingsModal { left: 300px; }
        #dayBookingsModal.flex { display: flex !important; }

        #bookingModal {
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            left: 110px;
            z-index: 200;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            transition: left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            pointer-events: none;
        }

        .side-nav:hover ~ #bookingModal,
        .side-nav:hover ~ .main-content ~ #bookingModal {
            left: 300px;
        }

        #bookingModal.flex {
            display: flex !important;
        }
    </style>
    <script>
        function toggleDayOff(checkbox, dayName) {
            const card = document.getElementById('card-' + dayName);
            const statusLabel = document.getElementById('status-' + dayName);
            const miniLabel = document.getElementById('label-' + dayName);
            const timeline = document.getElementById('timeline-' + dayName);
            
            if (checkbox.checked) {
                card.classList.add('is-off');
                if (timeline) timeline.classList.add('is-day-off-view');
                if (statusLabel) {
                    statusLabel.textContent = 'DAY OFF';
                    statusLabel.className = 'px-5 py-2 bg-rose-500/10 text-rose-500 rounded-xl text-[12px] font-black uppercase tracking-widest border border-rose-500/10 animate-pulse';
                }
                if (miniLabel) {
                    miniLabel.textContent = 'DAY OFF';
                    miniLabel.className = 'text-[11px] font-black uppercase tracking-widest text-rose-500';
                }
            } else {
                card.classList.remove('is-off');
                if (timeline) timeline.classList.remove('is-day-off-view');
                if (statusLabel) {
                    statusLabel.textContent = 'WORKING DAY';
                    statusLabel.className = 'px-5 py-2 bg-emerald-500/10 text-emerald-500 rounded-xl text-[12px] font-black uppercase tracking-widest border border-emerald-500/10';
                }
                if (miniLabel) {
                    miniLabel.textContent = 'WORKING';
                    miniLabel.className = 'text-[11px] font-black uppercase tracking-widest text-gray-500';
                }
            }
        }
        function openTab(dayName) {
            document.querySelectorAll('.tab-content').forEach(tc => tc.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(tb => {
                tb.style.backgroundColor = 'rgba(255,255,255,0.03)';
                tb.style.color = 'rgba(255,255,255,0.4)';
                tb.classList.remove('border-primary');
            });
            document.getElementById(dayName).classList.add('active');
            const targetBtn = document.getElementById('btn-' + dayName);
            targetBtn.style.backgroundColor = 'var(--primary)';
            targetBtn.style.color = 'white';
            localStorage.setItem('last_active_day', dayName);
        }
        function updateHeaderClock() {
            const now = new Date();
            const clockEl = document.getElementById('headerClock');
            if (clockEl) clockEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }
        setInterval(updateHeaderClock, 1000);
        window.addEventListener('DOMContentLoaded', () => {
            updateHeaderClock();
            const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            const today = days[new Date().getDay()];
            openTab(today !== 'Sunday' ? today : 'Monday');
        });
    </script>
</head>

<body class="antialiased flex h-screen overflow-hidden">

    <?php include '../includes/coach_sidebar.php'; ?>

    <div class="main-content flex-1 overflow-y-auto no-scrollbar">
        <div class="p-10">
            <header class="mb-10 flex justify-between items-end">
                <div>
                    <h2 class="text-3xl font-black uppercase tracking-tighter italic" style="color:var(--text-main)">
                        My <span style="color:var(--primary)" class="italic">Schedule</span>
                    </h2>
                    <p class="label-muted mt-1 italic">Capacity & Slot Management • Live Updates</p>
                </div>
                <div class="text-right">
                    <p id="headerClock" class="font-black italic text-2xl leading-none tracking-tighter pr-2" style="color:var(--text-main)">00:00:00 AM</p>
                    <p class="text-[10px] font-bold uppercase tracking-widest mt-2 pr-2 opacity-80" style="color:var(--primary)">
                        <?= date('l, M d, Y') ?>
                    </p>
                </div>
            </header>

            <?php if ($msg): ?>
                <div id="statusAlert"
                    class="bg-emerald-500/10 border border-emerald-500/20 p-4 rounded-xl text-emerald-500 text-[11px] font-black uppercase italic mb-8 flex items-center justify-between group animate-slide-up">
                    <div class="flex items-center gap-3">
                        <span class="material-symbols-outlined text-base">check_circle</span>
                        <span><?= $msg ?></span>
                    </div>
                    <button type="button" onclick="document.getElementById('statusAlert').style.display='none'"
                        class="size-6 flex items-center justify-center rounded-lg hover:bg-emerald-500/20 transition-all text-emerald-500/50 hover:text-emerald-500">
                        <span class="material-symbols-outlined text-sm">close</span>
                    </button>
                </div>
            <?php endif; ?>

            <form id="scheduleForm" action="coach_schedule.php" method="POST" class="flex flex-col animate-slide-up min-h-[800px]">
                <input type="hidden" id="active_tab" name="active_tab" value="<?= isset($_POST['active_tab']) ? htmlspecialchars($_POST['active_tab']) : 'settings' ?>">
                <input type="hidden" name="save_availability" value="1">
                
                <!-- TAB NAVIGATION -->
                <div class="flex flex-col md:flex-row justify-between items-end mb-8 border-b border-white/5 gap-6">
                    <div class="flex gap-8 overflow-x-auto no-scrollbar w-full md:w-auto">
                        <button type="button" id="main-btn-settings" onclick="openMainTab('settings')" class="main-tab-btn pb-4 -mb-[1px] text-xs font-black uppercase tracking-widest transition-all whitespace-nowrap" style="color: var(--primary); border-bottom: 2px solid var(--primary);">
                            Availability
                        </button>
                        <button type="button" id="main-btn-daily" onclick="openMainTab('daily')" class="main-tab-btn pb-4 -mb-[1px] text-xs font-black uppercase tracking-widest transition-all whitespace-nowrap" style="color: color-mix(in srgb, var(--text-main) 45%, transparent); border-bottom: 2px solid transparent;">
                            Daily / Weekly View
                        </button>
                        <button type="button" id="main-btn-monthly" onclick="openMainTab('monthly')" class="main-tab-btn pb-4 -mb-[1px] text-xs font-black uppercase tracking-widest transition-all whitespace-nowrap" style="color: color-mix(in srgb, var(--text-main) 45%, transparent); border-bottom: 2px solid transparent;">
                            Monthly View
                        </button>
                    </div>
                    <div class="pb-4 flex items-center gap-3">
                        <button type="button" onclick="confirmSave()" class="bg-primary hover:opacity-90 text-[white] px-6 py-2.5 rounded-xl font-black uppercase text-[10px] tracking-widest transition-all active:scale-[0.98] flex items-center justify-center gap-2 whitespace-nowrap">
                            <span class="material-symbols-outlined text-base">save_as</span> Save Changes
                        </button>
                    </div>
                </div>

                <!-- TAB 1: SETTINGS -->
                <div id="main-tab-settings" class="main-tab-content">
                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6">
                        <?php foreach ($week_days as $day):
                            $off = ($avail_map[$day]['is_off_day'] ?? 0) == 1;
                            ?>
                            <div id="card-<?= $day ?>" class="day-card p-5 rounded-2xl <?= $off ? 'is-off' : '' ?>">
                                <div class="flex justify-between items-center mb-5">
                                    <div class="flex flex-col">
                                        <span class="font-black italic uppercase text-sm tracking-widest text-primary transition-colors"><?= $day ?></span>
                                        <p class="label-muted mt-0.5 text-[10px]" id="label-<?= $day ?>">
                                            <?= $off ? 'DAY OFF' : 'WORKING' ?>
                                        </p>
                                    </div>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" name="off_<?= $day ?>" value="1" <?= $off ? 'checked' : '' ?> class="sr-only toggle-input" onchange="toggleDayOff(this, '<?= $day ?>')">
                                        <div class="toggle-switch"><div class="dot"></div></div>
                                    </label>
                                </div>

                                <div class="shift-inputs space-y-4">
                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <p class="label-muted mb-2 text-[10px]">Shift 1 Start</p>
                                            <input type="time" name="start_<?= $day ?>" value="<?= $avail_map[$day]['start_time'] ?>"
                                                class="w-full bg-white/[0.03] border border-white/5 rounded-xl p-2.5 text-xs text-white outline-none focus:border-primary transition-all">
                                        </div>
                                        <div>
                                            <p class="label-muted mb-2 text-[10px]">Shift 1 End</p>
                                            <input type="time" name="end_<?= $day ?>" value="<?= $avail_map[$day]['end_time'] ?>"
                                                class="w-full bg-white/[0.03] border border-white/5 rounded-xl p-2.5 text-xs text-white outline-none focus:border-primary transition-all">
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <p class="label-muted mb-2 text-[10px]">Shift 2 Start</p>
                                            <input type="time" name="start2_<?= $day ?>" value="<?= $avail_map[$day]['start_time_2'] ?>"
                                                class="w-full bg-white/[0.03] border border-white/5 rounded-xl p-2.5 text-xs text-white outline-none focus:border-primary transition-all">
                                        </div>
                                        <div>
                                            <p class="label-muted mb-2 text-[10px]">Shift 2 End</p>
                                            <input type="time" name="end2_<?= $day ?>" value="<?= $avail_map[$day]['end_time_2'] ?>"
                                                class="w-full bg-white/[0.03] border border-white/5 rounded-xl p-2.5 text-xs text-white outline-none focus:border-primary transition-all">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- TAB 2: DAILY VIEW -->
                <div id="main-tab-daily" class="main-tab-content" style="display: none;">
                    <div class="flex gap-2 mb-10 overflow-x-auto no-scrollbar pb-2">
                        <?php foreach ($week_days as $day): ?>
                            <button type="button" id="btn-<?= $day ?>" onclick="openTab('<?= $day ?>')"
                                class="tab-btn px-8 py-3.5 rounded-2xl text-[11px] font-black uppercase tracking-widest transition-all glass-card border-none">
                                <?= $day ?>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <div class="flex-1 overflow-y-auto no-scrollbar">
                        <?php foreach ($week_days as $index => $day_name):
                            $loop_date = date('Y-m-d', strtotime("monday this week +$index days"));
                            $day_data = $avail_map[$day_name];
                            $is_off = (int)($day_data['is_off_day'] ?? 0) === 1;
                            $s1_ts = strtotime($loop_date . ' ' . ($day_data['start_time'] ?? '08:00'));
                            $e1_ts = strtotime($loop_date . ' ' . ($day_data['end_time'] ?? '12:00'));
                            $s2_ts = strtotime($loop_date . ' ' . ($day_data['start_time_2'] ?? '13:00'));
                            $e2_ts = strtotime($loop_date . ' ' . ($day_data['end_time_2'] ?? '17:00'));
                            ?>
                            <div id="<?= $day_name ?>" class="tab-content transition-all">
                                <div class="flex justify-between items-center mb-6">
                                    <div class="flex items-center">
                                        <h4 class="text-sm font-black uppercase tracking-widest" style="color:var(--text-main)">
                                            <?= $day_name ?> <span class="opacity-30 mx-2">|</span> <span class="opacity-50"><?= date('F d, Y', strtotime($loop_date)) ?></span>
                                        </h4>
                                    </div>
                                    <span id="status-<?= $day_name ?>" class="<?= $is_off ? 'bg-rose-500/10 text-rose-500 border-rose-500/10 animate-pulse' : 'bg-emerald-500/10 text-emerald-500 border-emerald-500/10' ?> px-5 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest border">
                                        <?= $is_off ? 'DAY OFF' : 'WORKING DAY' ?>
                                    </span>
                                </div>

                                <div id="timeline-<?= $day_name ?>" class="<?= $is_off ? 'is-day-off-view' : '' ?>">
                                    <div class="grid grid-cols-1 xl:grid-cols-2 gap-10">
                                        <!-- Morning Column -->
                                        <div class="space-y-4">
                                            <h5 class="text-[10px] font-black uppercase tracking-widest text-primary mb-2">Morning Shift</h5>
                                            <?php
                                            $start_st = strtotime($loop_date . ' 05:00');
                                            $end_st = strtotime($loop_date . ' 12:00');
                                            while ($start_st < $end_st):
                                                $slot_end = strtotime('+30 minutes', $start_st);
                                                $found_booking = null;
                                                foreach ($all_bookings as $b) {
                                                    if ($b['ts_start'] < $slot_end && $b['ts_end'] > $start_st) {
                                                        $found_booking = $b; break;
                                                    }
                                                }
                                                $is_working = (($start_st >= $s1_ts && $start_st < $e1_ts) || ($start_st >= $s2_ts && $start_st < $e2_ts)) && !$is_off;
                                                ?>
                                                <?php if ($found_booking): 
                                                    $is_pending = ($found_booking['status'] === 'Pending');
                                                    $cls = $is_pending ? 'amber' : 'emerald';
                                                ?>
                                                    <div onclick="openBookingModal(this)" data-booking="<?= htmlspecialchars(json_encode($found_booking)) ?>" class="booked-slot-box flex items-center bg-<?= $cls ?>-500/10 border border-<?= $cls ?>-500/20 p-5 rounded-3xl group animate-slide-up cursor-pointer hover:bg-<?= $cls ?>-500/20 transition-all hover:scale-[1.01]">
                                                        <div class="w-32 text-xs font-black italic text-<?= $cls ?>-500 border-r border-<?= $cls ?>-500/20 pr-4 mr-4 shrink-0 flex flex-col items-start justify-center text-left">
                                                            <span><?= date('h:i A', $start_st) ?> -</span>
                                                            <span><?= date('h:i A', $slot_end) ?></span>
                                                        </div>
                                                        <div class="flex-1 overflow-hidden">
                                                            <div class="relative h-[18px] mb-0.5 overflow-hidden">
                                                                <p class="text-[11px] font-black text-<?= $cls ?>-500/80 uppercase tracking-widest absolute inset-0 transition-transform duration-300 group-hover:-translate-y-full"><?= strtoupper($found_booking['status']) ?> • <?= strtoupper($found_booking['service']) ?></p>
                                                                <p class="text-[11px] font-black uppercase tracking-[0.2em] text-<?= $cls ?>-500 absolute inset-0 translate-y-full transition-transform duration-300 group-hover:translate-y-0">View Details &rarr;</p>
                                                            </div>
                                                            <h5 class="text-sm font-black italic tracking-tight text-zinc-200 truncate"><?= htmlspecialchars($found_booking['fullname']) ?></h5>
                                                        </div>
                                                        <span class="material-symbols-outlined text-<?= $cls ?>-500 group-hover:scale-110 transition-transform"><?= $is_pending ? 'timer' : 'verified' ?></span>
                                                    </div>
                                                <?php elseif ($is_working): ?>
                                                    <div class="available-slot-box flex items-center bg-white/[0.02] border border-white/5 p-5 rounded-3xl hover:bg-emerald-500/5 hover:border-emerald-500/20 transition-all group animate-slide-up">
                                                        <div class="w-32 text-xs font-black italic text-gray-400 group-hover:text-emerald-500 group-hover:border-emerald-500/20 border-r border-white/10 pr-4 mr-4 shrink-0 transition-colors flex flex-col items-start justify-center text-left">
                                                            <span><?= date('h:i A', $start_st) ?> -</span>
                                                            <span><?= date('h:i A', $slot_end) ?></span>
                                                        </div>
                                                        <div class="flex-1">
                                                            <p class="text-[9px] font-black uppercase tracking-[0.2em] text-emerald-500/40 group-hover:text-emerald-500 transition-colors">AVAILABLE SLOT</p>
                                                        </div>
                                                        <span class="material-symbols-outlined text-emerald-500/20 group-hover:text-emerald-500 transition-colors">add_task</span>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="blank-slot-row <?= ($is_off || (!$found_booking && !$is_working)) ? 'flex' : 'hidden' ?> items-center py-6 px-4 opacity-40 hover:opacity-60 transition-all group">
                                                    <div class="w-32 text-xs font-black italic text-gray-400 border-r border-white/10 pr-4 mr-4 shrink-0 flex flex-col items-start justify-center text-left">
                                                        <span><?= date('h:i A', $start_st) ?> -</span>
                                                        <span><?= date('h:i A', $slot_end) ?></span>
                                                    </div>
                                                    <div class="h-px flex-1 bg-white/10"></div>
                                                </div>
                                                <?php $start_st = $slot_end; ?>
                                            <?php endwhile; ?>
                                        </div>
                                        
                                        <!-- Afternoon Column -->
                                        <div class="space-y-4">
                                            <h5 class="text-[10px] font-black uppercase tracking-widest text-primary mb-2">Afternoon & Evening</h5>
                                            <?php
                                            $start_st = strtotime($loop_date . ' 12:00');
                                            $end_st = strtotime($loop_date . ' 22:00');
                                            while ($start_st < $end_st):
                                                $slot_end = strtotime('+30 minutes', $start_st);
                                                $found_booking = null;
                                                foreach ($all_bookings as $b) {
                                                    if ($b['ts_start'] < $slot_end && $b['ts_end'] > $start_st) {
                                                        $found_booking = $b; break;
                                                    }
                                                }
                                                $is_working = (($start_st >= $s1_ts && $start_st < $e1_ts) || ($start_st >= $s2_ts && $start_st < $e2_ts)) && !$is_off;
                                                ?>
                                                <?php if ($found_booking): 
                                                    $is_pending = ($found_booking['status'] === 'Pending');
                                                    $cls = $is_pending ? 'amber' : 'emerald';
                                                ?>
                                                    <div onclick="openBookingModal(this)" data-booking="<?= htmlspecialchars(json_encode($found_booking)) ?>" class="booked-slot-box flex items-center bg-<?= $cls ?>-500/10 border border-<?= $cls ?>-500/20 p-5 rounded-3xl group animate-slide-up cursor-pointer hover:bg-<?= $cls ?>-500/20 transition-all hover:scale-[1.01]">
                                                        <div class="w-32 text-xs font-black italic text-<?= $cls ?>-500 border-r border-<?= $cls ?>-500/20 pr-4 mr-4 shrink-0 flex flex-col items-start justify-center text-left">
                                                            <span><?= date('h:i A', $start_st) ?> -</span>
                                                            <span><?= date('h:i A', $slot_end) ?></span>
                                                        </div>
                                                        <div class="flex-1 overflow-hidden">
                                                            <div class="relative h-[18px] mb-0.5 overflow-hidden">
                                                                <p class="text-[11px] font-black text-<?= $cls ?>-500/80 uppercase tracking-widest absolute inset-0 transition-transform duration-300 group-hover:-translate-y-full"><?= strtoupper($found_booking['status']) ?> • <?= strtoupper($found_booking['service']) ?></p>
                                                                <p class="text-[11px] font-black uppercase tracking-[0.2em] text-<?= $cls ?>-500 absolute inset-0 translate-y-full transition-transform duration-300 group-hover:translate-y-0">View Details &rarr;</p>
                                                            </div>
                                                            <h5 class="text-sm font-black italic tracking-tight text-zinc-200 truncate"><?= htmlspecialchars($found_booking['fullname']) ?></h5>
                                                        </div>
                                                        <span class="material-symbols-outlined text-<?= $cls ?>-500 group-hover:scale-110 transition-transform"><?= $is_pending ? 'timer' : 'verified' ?></span>
                                                    </div>
                                                <?php elseif ($is_working): ?>
                                                    <div class="available-slot-box flex items-center bg-white/[0.02] border border-white/5 p-5 rounded-3xl hover:bg-emerald-500/5 hover:border-emerald-500/20 transition-all group animate-slide-up">
                                                        <div class="w-32 text-xs font-black italic text-gray-400 group-hover:text-emerald-500 group-hover:border-emerald-500/20 border-r border-white/10 pr-4 mr-4 shrink-0 transition-colors flex flex-col items-start justify-center text-left">
                                                            <span><?= date('h:i A', $start_st) ?> -</span>
                                                            <span><?= date('h:i A', $slot_end) ?></span>
                                                        </div>
                                                        <div class="flex-1">
                                                            <p class="text-[9px] font-black uppercase tracking-[0.2em] text-emerald-500/40 group-hover:text-emerald-500 transition-colors">AVAILABLE SLOT</p>
                                                        </div>
                                                        <span class="material-symbols-outlined text-emerald-500/20 group-hover:text-emerald-500 transition-colors">add_task</span>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="blank-slot-row <?= ($is_off || (!$found_booking && !$is_working)) ? 'flex' : 'hidden' ?> items-center py-6 px-4 opacity-40 hover:opacity-60 transition-all group">
                                                    <div class="w-32 text-xs font-black italic text-gray-400 border-r border-white/10 pr-4 mr-4 shrink-0 flex flex-col items-start justify-center text-left">
                                                        <span><?= date('h:i A', $start_st) ?> -</span>
                                                        <span><?= date('h:i A', $slot_end) ?></span>
                                                    </div>
                                                    <div class="h-px flex-1 bg-white/10"></div>
                                                </div>
                                                <?php $start_st = $slot_end; ?>
                                            <?php endwhile; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div id="main-tab-monthly" class="main-tab-content w-full flex-1 animate-slide-up" style="display: none;">
                    <div class="flex flex-col xl:flex-row gap-10 items-start h-full w-full max-w-[1300px]">
                        <!-- Left Side: Title and Controls -->
                        <div class="w-full xl:w-48 shrink-0 flex flex-col justify-center xl:sticky top-10 xl:mt-32">
                            <h2 id="monthly-title" class="text-[32px] font-black uppercase tracking-tighter leading-tight mb-6">
                                <!-- Rendered via JS -->
                            </h2>
                            <div class="flex items-center gap-3">
                                <button type="button" onclick="changeMonth(-1)" class="size-9 rounded-[10px] bg-white/5 hover:bg-white/10 flex items-center justify-center text-white transition-all border border-white/10">
                                    <span class="material-symbols-outlined text-sm">chevron_left</span>
                                </button>
                                <button type="button" onclick="goToToday()" class="px-5 h-9 rounded-[10px] bg-white/5 hover:bg-white/10 flex items-center justify-center text-white font-black text-[10px] uppercase tracking-[0.2em] transition-all border border-white/10">
                                    Today
                                </button>
                                <button type="button" onclick="changeMonth(1)" class="size-9 rounded-[10px] bg-white/5 hover:bg-white/10 flex items-center justify-center text-white transition-all border border-white/10">
                                    <span class="material-symbols-outlined text-sm">chevron_right</span>
                                </button>
                            </div>
                        </div>
                        
                        <!-- Right Side: Calendar Grid -->
                        <div class="flex-1 w-full border border-white/10 rounded-3xl overflow-hidden bg-[--card-bg] backdrop-blur-[--card-blur] shadow-2xl">
                            <!-- Days Header -->
                            <div class="grid grid-cols-7 border-b border-white/10 bg-black/20">
                                <?php foreach (['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'] as $dow): ?>
                                    <div class="py-5 text-center text-[9px] font-black uppercase tracking-[0.2em] text-white/50 border-r border-white/10 last:border-0">
                                        <?= $dow ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <!-- Grid Body -->
                            <div id="monthly-grid" class="grid grid-cols-7 bg-white/[0.02]">
                                <!-- Rendered via JS -->
                            </div>
                        </div>
                    </div>
                </div>

                <script>
                    let currentCalYear = new Date().getFullYear();
                    let currentCalMonth = new Date().getMonth(); // 0-11
                    const allBookingsJson = <?= json_encode($all_bookings) ?>;

                    // Mock bookings generator removed to only show actual bookings

                    function changeMonth(offset) {
                        currentCalMonth += offset;
                        if (currentCalMonth > 11) {
                            currentCalMonth = 0;
                            currentCalYear++;
                        } else if (currentCalMonth < 0) {
                            currentCalMonth = 11;
                            currentCalYear--;
                        }
                        renderMonthlyCalendar(currentCalYear, currentCalMonth);
                    }

                    function goToToday() {
                        currentCalYear = new Date().getFullYear();
                        currentCalMonth = new Date().getMonth();
                        renderMonthlyCalendar(currentCalYear, currentCalMonth);
                    }

                    function formatTimeAMPM(timestamp) {
                        const d = new Date(timestamp * 1000);
                        let hours = d.getHours();
                        let minutes = d.getMinutes();
                        const ampm = hours >= 12 ? 'pm' : 'am';
                        hours = hours % 12;
                        hours = hours ? hours : 12;
                        minutes = minutes < 10 ? '0' + minutes : minutes;
                        return hours + ':' + minutes + ampm;
                    }

                    function escapeHtml(unsafe) {
                        return (unsafe||"").toString()
                             .replace(/&/g, "&amp;")
                             .replace(/</g, "&lt;")
                             .replace(/>/g, "&gt;")
                             .replace(/"/g, "&quot;")
                             .replace(/'/g, "&#039;");
                    }

                    function renderMonthlyCalendar(year, month) {
                        const monthNames = ["JANUARY", "FEBRUARY", "MARCH", "APRIL", "MAY", "JUNE", "JULY", "AUGUST", "SEPTEMBER", "OCTOBER", "NOVEMBER", "DECEMBER"];
                        document.getElementById('monthly-title').innerHTML = `<span class="text-primary">${monthNames[month]}</span><br><span style="color: white;">${year}</span>`;
                        
                        const grid = document.getElementById('monthly-grid');
                        grid.innerHTML = '';
                        
                        const firstDay = new Date(year, month, 1);
                        const lastDay = new Date(year, month + 1, 0);
                        const numDays = lastDay.getDate();
                        let startingDayOfWeek = firstDay.getDay();
                        
                        for (let i = 0; i < startingDayOfWeek; i++) {
                            grid.innerHTML += `<div class='min-h-[140px] p-3 border-r border-b border-white/5 bg-black/20'></div>`;
                        }
                        
                        const combinedBookings = allBookingsJson;
                        
                        const today = new Date();
                        const isCurrentMonthYear = today.getFullYear() === year && today.getMonth() === month;
                        const currentDayNum = today.getDate();
                        
                        for (let d = 1; d <= numDays; d++) {
                            const isToday = isCurrentMonthYear && currentDayNum === d;
                            const bgClass = isToday ? 'bg-primary/5' : '';
                            const numClass = isToday ? 'bg-primary text-white shadow-[0_0_15px_rgba(var(--primary-rgb),0.5)]' : 'text-white font-bold';
                            
                            const dayStartTs = new Date(year, month, d, 0, 0, 0).getTime() / 1000;
                            const dayEndTs = new Date(year, month, d, 23, 59, 59).getTime() / 1000;
                            
                            const dayBookings = combinedBookings.filter(b => b.ts_start >= dayStartTs && b.ts_start <= dayEndTs);
                            
                            let bookingsHtml = '';
                            if (dayBookings.length > 0) {
                                bookingsHtml += '<div class="flex flex-col gap-1.5 overflow-hidden flex-1 px-1 pb-1 pointer-events-none">';
                                const previewBookings = dayBookings.slice(0, 5);
                                previewBookings.forEach(b => {
                                    const cls = (b.status && b.status.toLowerCase() === 'pending') ? 'amber' : 'emerald';
                                    const b_json = escapeHtml(JSON.stringify(b));
                                    bookingsHtml += `
                                        <div onclick='openBookingModal(this, event)' data-booking='${b_json}' class='pointer-events-auto cursor-pointer flex items-center gap-1.5 px-2 py-1.5 rounded-md bg-${cls}-500/10 hover:bg-${cls}-500/20 transition-all w-full overflow-hidden shrink-0 border-l-2 border-${cls}-500'>
                                            <p class='text-[9px] font-bold text-${cls}-500 truncate'>${formatTimeAMPM(b.ts_start)} ${escapeHtml(b.fullname)}</p>
                                        </div>`;
                                });
                                if (dayBookings.length > 5) {
                                    bookingsHtml += `<div class="text-[9px] text-gray-500 font-bold uppercase tracking-widest text-center mt-1">+${dayBookings.length - 5} more</div>`;
                                }
                                bookingsHtml += '</div>';
                            }
                            
                            const dayBookingsJson = escapeHtml(JSON.stringify(dayBookings));
                            const dayDateStr = new Date(year, month, d).toLocaleDateString('en-US', {month: 'long', day: 'numeric', year: 'numeric'});

                            grid.innerHTML += `
                                <div class='min-h-[140px] p-2 border-r border-b border-white/5 flex flex-col gap-2 transition-all hover:bg-white/[0.04] ${bgClass} relative group cursor-pointer overflow-hidden min-w-0' onclick='openDayBookingsModal("${dayDateStr}", ${dayBookingsJson})'>
                                    <div class='flex justify-end p-2 pointer-events-none'>
                                        <span class='size-8 rounded-full flex items-center justify-center text-[11px] transition-all cursor-default ${numClass}'>${d}</span>
                                    </div>
                                    ${bookingsHtml}
                                </div>`;
                        }
                        
                        const totalCells = startingDayOfWeek + numDays;
                        const remaining = (7 - (totalCells % 7)) % 7;
                        for (let i = 0; i < remaining; i++) {
                            grid.innerHTML += `<div class='min-h-[140px] p-3 border-r border-b border-white/5 bg-black/20'></div>`;
                        }
                    }

                    // Initialize the calendar on load
                    goToToday();
                </script>

            </form>

            <script>
                function openMainTab(tabId) {
                    document.querySelectorAll('.main-tab-content').forEach(tc => {
                        tc.style.display = 'none';
                    });
                    document.querySelectorAll('.main-tab-btn').forEach(tb => {
                        tb.style.color = 'color-mix(in srgb, var(--text-main) 45%, transparent)';
                        tb.style.borderBottom = '2px solid transparent';
                    });
                    
                    const targetContent = document.getElementById('main-tab-' + tabId);
                    if (targetContent) {
                        if (tabId === 'monthly') {
                            targetContent.style.display = 'flex';
                        } else {
                            targetContent.style.display = 'block';
                        }
                    }
                    
                    const targetBtn = document.getElementById('main-btn-' + tabId);
                    if (targetBtn) {
                        targetBtn.style.color = 'var(--primary)';
                        targetBtn.style.borderBottom = '2px solid var(--primary)';
                    }

                    const activeTabInput = document.getElementById('active_tab');
                    if (activeTabInput) {
                        activeTabInput.value = tabId;
                    }
                }

                document.addEventListener('DOMContentLoaded', () => {
                    const alert = document.getElementById('statusAlert');
                    if (alert) {
                        setTimeout(() => {
                            alert.style.opacity = '0';
                            alert.style.transition = 'opacity 0.8s ease-out, transform 0.8s ease-out';
                            alert.style.transform = 'translateY(-10px)';
                            setTimeout(() => alert.style.display = 'none', 800);
                        }, 15000);
                    }

                    const urlParams = new URLSearchParams(window.location.search);
                    const tabParam = urlParams.get('tab');
                    const activeTabInput = document.getElementById('active_tab');
                    
                    if (tabParam) {
                        openMainTab(tabParam);
                    } else if (activeTabInput && activeTabInput.value) {
                        openMainTab(activeTabInput.value);
                    } else {
                        openMainTab('settings');
                    }
                });
            </script>

        </div>
    </div>

    <!-- Booking Details Modal -->
    <div id="bookingModal" class="hidden pointer-events-none">
        <div class="absolute inset-0 transition-opacity duration-300 opacity-0 bg-background/40 backdrop-blur-xl pointer-events-auto" id="booking-modal-backdrop" onclick="closeBookingModal()">
        </div>
        <div class="relative z-10 bg-[--card-bg] backdrop-blur-[--card-blur] w-full max-w-[540px] rounded-[32px] shadow-2xl border border-white/5 overflow-hidden transform transition-all duration-300 scale-90 opacity-0 pointer-events-auto" id="booking-modal-content">
            <div class="p-8">
                <div class="mb-7 relative">
                    <div class="flex items-start justify-between mb-1">
                        <h3 class="text-[22px] font-black italic uppercase tracking-tighter leading-none flex gap-2">
                            <span class="text-white">Booking</span><span class="text-primary">Details</span>
                        </h3>
                        <button onclick="closeBookingModal()" class="size-8 flex items-center justify-center rounded-lg bg-white/5 hover:bg-white/10 text-white/50 hover:text-white transition-colors border border-white/5">
                            <span class="material-symbols-outlined text-[14px]">close</span>
                        </button>
                    </div>
                    <p id="modal-booking-id" class="text-[10px] font-black uppercase tracking-[0.2em] text-white/30 mb-6"></p>
                    <div class="flex items-center gap-4">
                        <div class="size-[56px] rounded-2xl bg-white/5 border border-white/5 flex items-center justify-center shrink-0 overflow-hidden">
                            <img id="modal-dp" src="" class="hidden size-full object-cover">
                            <span id="modal-dp-icon" class="material-symbols-outlined text-white/80 text-[28px]">person</span>
                        </div>
                        <div>
                            <h4 id="modal-fullname" class="text-[18px] font-bold text-white leading-none mb-1 capitalize"></h4>
                            <p id="modal-email" class="text-[11px] font-medium text-gray-400 lowercase"></p>
                        </div>
                    </div>
                </div>
                <div class="flex flex-col gap-3">
                    <div class="bg-white/[0.03] border border-white/5 rounded-[20px] p-6">
                        <div class="grid grid-cols-2 gap-y-6 gap-x-4">
                            <div>
                                <p class="text-[9px] font-bold uppercase tracking-[0.1em] text-gray-500 mb-1.5">Contact Number</p>
                                <p id="modal-number" class="text-[13px] font-bold text-white"></p>
                            </div>
                            <div>
                                <p class="text-[9px] font-bold uppercase tracking-[0.1em] text-gray-500 mb-1.5">Booking Status</p>
                                <p id="modal-status" class="text-[13px] font-bold capitalize"></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white/[0.03] border border-white/5 rounded-[20px] p-6">
                        <p class="text-[9px] font-bold uppercase tracking-[0.1em] text-gray-500 mb-1.5">Service Item</p>
                        <p id="modal-service" class="text-[13px] font-bold text-white"></p>
                    </div>
                    <div class="bg-white/[0.03] border border-white/5 rounded-[20px] p-6">
                        <div class="grid grid-cols-2 gap-y-6 gap-x-4">
                            <div>
                                <p class="text-[9px] font-bold uppercase tracking-[0.1em] text-gray-500 mb-1.5">Schedule Date</p>
                                <p id="modal-date-only" class="text-[13px] font-bold text-white"></p>
                            </div>
                            <div>
                                <p class="text-[9px] font-bold uppercase tracking-[0.1em] text-gray-500 mb-1.5">Schedule Time</p>
                                <p id="modal-time-only" class="text-[13px] font-bold text-white"></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Day Bookings Modal -->
    <div id="dayBookingsModal" class="hidden pointer-events-none">
        <div class="absolute inset-0 transition-opacity duration-300 opacity-0 bg-background/40 backdrop-blur-xl pointer-events-auto" id="day-modal-backdrop" onclick="closeDayBookingsModal()">
        </div>
        <div class="relative z-10 bg-[--card-bg] backdrop-blur-[--card-blur] w-full max-w-[540px] max-h-[80vh] flex flex-col rounded-[32px] shadow-2xl border border-white/5 overflow-hidden transform transition-all duration-300 scale-90 opacity-0 pointer-events-auto" id="day-modal-content">
            <div class="p-8 pb-4 shrink-0 flex items-center justify-between border-b border-white/5">
                <h3 class="text-[22px] font-black italic uppercase tracking-tighter leading-none flex gap-2">
                    <span class="text-white" id="day-modal-date">May 14, 2026</span>
                </h3>
                <button onclick="closeDayBookingsModal()" class="size-8 flex items-center justify-center rounded-full bg-white/5 hover:bg-white/10 text-white transition-all active:scale-95">
                    <span class="material-symbols-outlined text-[14px]">close</span>
                </button>
            </div>
            <div class="p-8 overflow-y-auto no-scrollbar flex-1 flex flex-col gap-3" id="day-modal-list">
                <!-- Bookings populate here -->
            </div>
            <div id="day-modal-pagination" class="p-8 pt-4 border-t border-white/5 shrink-0 hidden">
                <!-- Pagination renders here -->
            </div>
        </div>
    </div>

    <!-- Custom Modal -->
    <div id="custom-modal" class="hidden pointer-events-none">
        <div class="absolute inset-0 transition-opacity duration-300 opacity-0 bg-background/40 backdrop-blur-xl pointer-events-auto" id="modal-backdrop" onclick="closeCustomModal()">
        </div>

        <div class="relative z-10 bg-[--card-bg] backdrop-blur-[--card-blur] w-full max-w-sm rounded-[32px] shadow-2xl border border-white/10 overflow-hidden transform transition-all duration-300 scale-90 opacity-0 pointer-events-auto" id="custom-modal-content">
            <div class="p-8 text-center">
                <div class="w-20 h-20 rounded-[24px] bg-white/5 flex items-center justify-center mx-auto mb-6 border border-white/10" id="custom-modal-icon-bg">
                    <span class="material-symbols-rounded text-4xl text-primary" id="custom-modal-icon">info</span>
                </div>

                <h3 class="text-xl font-black italic text-white uppercase tracking-tighter mb-3" id="custom-modal-title">Notification</h3>
                <p class="text-gray-400 text-[11px] font-bold tracking-wider mb-8 leading-relaxed px-2" id="custom-modal-message">Message goes here...</p>

                <div class="flex gap-3 justify-center" id="custom-modal-actions">
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentDayBookings = [];
        let currentDayPage = 1;
        const BOOKINGS_PER_PAGE = 5;

        function renderDayBookingsPage() {
            const listDiv = document.getElementById('day-modal-list');
            listDiv.innerHTML = '';
            
            if (currentDayBookings.length === 0) {
                listDiv.innerHTML = `<div class='text-center text-gray-500 text-xs font-bold uppercase tracking-widest py-10'>No bookings on this day</div>`;
                return;
            }

            const startIdx = (currentDayPage - 1) * BOOKINGS_PER_PAGE;
            const endIdx = startIdx + BOOKINGS_PER_PAGE;
            const pageBookings = currentDayBookings.slice(startIdx, endIdx);

            let html = '';
            pageBookings.forEach(b => {
                const cls = (b.status && b.status.toLowerCase() === 'pending') ? 'amber' : 'emerald';
                const b_json = escapeHtml(JSON.stringify(b));
                html += `
                    <div onclick='openBookingModal(this)' data-booking='${b_json}' class='cursor-pointer flex flex-col gap-2 p-4 rounded-[20px] bg-${cls}-500/5 hover:bg-${cls}-500/10 transition-all border border-${cls}-500/20'>
                        <div class='flex justify-between items-center'>
                            <p class='text-[13px] font-bold text-white'>${escapeHtml(b.fullname)}</p>
                            <span class='bg-${cls}-500/20 text-${cls}-500 px-3 py-1 rounded-lg text-[9px] font-black uppercase tracking-widest'>${b.status}</span>
                        </div>
                        <div class='flex items-center gap-4 mt-2'>
                            <div class='flex items-center gap-1.5 text-gray-400'>
                                <span class='material-symbols-outlined text-[16px]'>schedule</span>
                                <span class='text-xs font-bold tracking-wider'>${formatTimeAMPM(b.ts_start)} - ${formatTimeAMPM(b.ts_end)}</span>
                            </div>
                            <div class='flex items-center gap-1.5 text-gray-400'>
                                <span class='material-symbols-outlined text-[16px]'>fitness_center</span>
                                <span class='text-xs font-bold tracking-wider truncate max-w-[120px]'>${escapeHtml(b.service)}</span>
                            </div>
                        </div>
                    </div>
                `;
            });

            listDiv.innerHTML = html;

            const totalPages = Math.ceil(currentDayBookings.length / BOOKINGS_PER_PAGE);
            const pagDiv = document.getElementById('day-modal-pagination');
            if (totalPages > 1) {
                pagDiv.classList.remove('hidden');
                pagDiv.innerHTML = `
                    <div class="flex justify-between items-center">
                        <button onclick="changeDayPage(-1)" class="px-4 py-2 rounded-xl bg-white/5 hover:bg-white/10 text-white text-[10px] font-black uppercase tracking-widest transition-all ${currentDayPage === 1 ? 'opacity-30 pointer-events-none' : ''}">Prev</button>
                        <span class="text-[10px] text-gray-400 font-bold tracking-widest">PAGE ${currentDayPage} OF ${totalPages}</span>
                        <button onclick="changeDayPage(1)" class="px-4 py-2 rounded-xl bg-white/5 hover:bg-white/10 text-white text-[10px] font-black uppercase tracking-widest transition-all ${currentDayPage === totalPages ? 'opacity-30 pointer-events-none' : ''}">Next</button>
                    </div>
                `;
            } else {
                pagDiv.classList.add('hidden');
                pagDiv.innerHTML = '';
            }
        }

        function changeDayPage(offset) {
            currentDayPage += offset;
            renderDayBookingsPage();
        }

        function openDayBookingsModal(dateStr, bookings) {
            document.getElementById('day-modal-date').innerText = dateStr;
            currentDayBookings = bookings;
            currentDayPage = 1;
            renderDayBookingsPage();

            const modal = document.getElementById('dayBookingsModal');
            const backdrop = document.getElementById('day-modal-backdrop');
            const content = document.getElementById('day-modal-content');

            modal.classList.add('flex');
            modal.classList.remove('hidden');

            setTimeout(() => {
                backdrop.classList.remove('opacity-0');
                content.classList.remove('scale-90', 'opacity-0');
                content.classList.add('scale-100', 'opacity-100');
            }, 10);
        }

        function closeDayBookingsModal() {
            const modal = document.getElementById('dayBookingsModal');
            const backdrop = document.getElementById('day-modal-backdrop');
            const content = document.getElementById('day-modal-content');

            backdrop.classList.add('opacity-0');
            content.classList.remove('scale-100', 'opacity-100');
            content.classList.add('scale-90', 'opacity-0');

            setTimeout(() => {
                modal.classList.remove('flex');
                modal.classList.add('hidden');
            }, 300);
        }

        function confirmSave() {
            showCustomModal(
                "Confirm Changes",
                "Are you sure you want to save these changes to your availability?",
                "confirm",
                () => {
                    document.getElementById('scheduleForm').submit();
                }
            );
        }

        function showCustomModal(title, message, type, callback = null) {
            const modal = document.getElementById('custom-modal');
            const backdrop = document.getElementById('modal-backdrop');
            const content = document.getElementById('custom-modal-content');

            document.getElementById('custom-modal-title').innerText = title;
            document.getElementById('custom-modal-message').innerText = message;

            const actionsDiv = document.getElementById('custom-modal-actions');
            actionsDiv.className = 'flex gap-3 justify-center w-full';
            actionsDiv.innerHTML = '';

            if (type === 'confirm') {
                const cancelBtn = document.createElement('button');
                cancelBtn.className = "flex-1 px-6 py-3.5 rounded-2xl bg-white/5 hover:bg-white/10 text-gray-300 text-[10px] font-black uppercase tracking-[0.2em] transition-colors";
                cancelBtn.innerText = "Cancel";
                cancelBtn.onclick = closeCustomModal;
                actionsDiv.appendChild(cancelBtn);

                const confirmBtn = document.createElement('button');
                confirmBtn.className = "flex-1 px-6 py-3.5 rounded-2xl bg-primary hover:opacity-90 text-[white] text-[10px] font-black italic uppercase tracking-[0.2em] shadow-lg shadow-primary/20 transition-all flex items-center justify-center gap-2";
                confirmBtn.innerHTML = '<span class="material-symbols-rounded text-base not-italic normal-case tracking-normal">check</span> Confirm';
                confirmBtn.onclick = function () {
                    if (callback) callback();
                    closeCustomModal();
                };
                actionsDiv.appendChild(confirmBtn);

                document.getElementById('custom-modal-icon').innerText = 'security';
                document.getElementById('custom-modal-icon').className = 'material-symbols-rounded text-4xl text-primary';
                document.getElementById('custom-modal-icon-bg').className = 'w-20 h-20 rounded-[24px] bg-primary/10 flex items-center justify-center mx-auto mb-6 border border-primary/20';
            }

            modal.classList.add('flex');
            modal.classList.remove('hidden');

            setTimeout(() => {
                backdrop.classList.remove('opacity-0');
                content.classList.remove('scale-90', 'opacity-0');
                content.classList.add('scale-100', 'opacity-100');
            }, 10);
        }

        function closeCustomModal() {
            const modal = document.getElementById('custom-modal');
            const backdrop = document.getElementById('modal-backdrop');
            const content = document.getElementById('custom-modal-content');

            backdrop.classList.add('opacity-0');
            content.classList.remove('scale-100', 'opacity-100');
            content.classList.add('scale-90', 'opacity-0');

            setTimeout(() => {
                modal.classList.remove('flex');
                modal.classList.add('hidden');
            }, 300);
        }

        function openBookingModal(element, event = null) {
            if (event) {
                event.stopPropagation();
            }
            const bookingData = JSON.parse(element.getAttribute('data-booking'));
            
            document.getElementById('modal-booking-id').innerText = bookingData.booking_id ? 'BK-' + String(bookingData.booking_id).padStart(8, '0') : '';
            document.getElementById('modal-fullname').innerText = bookingData.fullname || 'Unknown';
            document.getElementById('modal-email').innerText = bookingData.email || 'N/A';
            document.getElementById('modal-number').innerText = bookingData.number || 'N/A';
            document.getElementById('modal-service').innerText = bookingData.service || 'N/A';
            document.getElementById('modal-date-only').innerText = bookingData.date_str || 'N/A';
            document.getElementById('modal-time-only').innerText = bookingData.time_str || 'N/A';

            if (bookingData.dp) {
                document.getElementById('modal-dp').src = bookingData.dp;
                document.getElementById('modal-dp').classList.remove('hidden');
                document.getElementById('modal-dp-icon').classList.add('hidden');
            } else {
                document.getElementById('modal-dp').classList.add('hidden');
                document.getElementById('modal-dp-icon').classList.remove('hidden');
            }

            const statusBadge = document.getElementById('modal-status');
            statusBadge.innerText = bookingData.status || 'UNKNOWN';
            
            const lowerStatus = (bookingData.status || '').toLowerCase();
            if (lowerStatus === 'pending') {
                statusBadge.className = 'text-[13px] font-bold capitalize text-amber-500';
            } else if (lowerStatus === 'approved' || lowerStatus === 'confirmed' || lowerStatus === 'completed') {
                statusBadge.className = 'text-[13px] font-bold capitalize text-emerald-500';
            } else {
                statusBadge.className = 'text-[13px] font-bold capitalize text-gray-500';
            }

            const modal    = document.getElementById('bookingModal');
            const backdrop = document.getElementById('booking-modal-backdrop');
            const content  = document.getElementById('booking-modal-content');

            modal.classList.add('flex');
            modal.classList.remove('hidden');

            setTimeout(() => {
                backdrop.classList.remove('opacity-0');
                content.classList.remove('scale-90', 'opacity-0');
                content.classList.add('scale-100', 'opacity-100');
            }, 10);
        }

        function closeBookingModal() {
            const modal    = document.getElementById('bookingModal');
            const backdrop = document.getElementById('booking-modal-backdrop');
            const content  = document.getElementById('booking-modal-content');

            backdrop.classList.add('opacity-0');
            content.classList.remove('scale-100', 'opacity-100');
            content.classList.add('scale-90', 'opacity-0');

            setTimeout(() => {
                modal.classList.remove('flex');
                modal.classList.add('hidden');
            }, 300);
        }
    </script>
</body>
</html>