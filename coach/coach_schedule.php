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

// Fetch Coach ID (from staff table)
$stmtCoach = $pdo->prepare("SELECT staff_id as coach_id FROM staff WHERE user_id = ? AND gym_id = ? AND staff_role = 'Coach' LIMIT 1");
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
                    $stmtUpdate = $pdo->prepare("UPDATE coach_schedules SET start_time = ?, end_time = ?, start_time_2 = ?, end_time_2 = ?, availability_status = ?, updated_at = NOW() WHERE coach_schedule_id = ?");
                    $stmtUpdate->execute([$start, $end, $start2, $end2, $status, $existing_id]);
                } else {
                    // Insert new record
                    $stmtInsert = $pdo->prepare("INSERT INTO coach_schedules (coach_id, day_of_week, start_time, end_time, start_time_2, end_time_2, availability_status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
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
            'start_time' => !empty($r['start_time']) ? date('H:i', strtotime($r['start_time'])) : '08:00',
            'end_time' => !empty($r['end_time']) ? date('H:i', strtotime($r['end_time'])) : '12:00',
            'start_time_2' => !empty($r['start_time_2']) ? date('H:i', strtotime($r['start_time_2'])) : '13:00',
            'end_time_2' => !empty($r['end_time_2']) ? date('H:i', strtotime($r['end_time_2'])) : '17:00',
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
            'ts_start' => strtotime($fb['booking_date'] . ' ' . $fb['start_time']),
            'ts_end'   => strtotime($fb['booking_date'] . ' ' . $fb['end_time']),
            'fullname' => $fb['fullname'],
            'status'   => $fb['booking_status'],
            'service'  => $fb['service_name'] ?: 'PT Session'
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
            --card-bg:       <?= $card_bg_css ?>;
            --card-blur:     20px;
        }

        body {
            font-family: '<?= $font_family ?>', sans-serif;
            background-color: var(--background);
            color: var(--text-main);
            overflow: hidden;
        }

        .glass-card {
            background: var(--card-bg);
            border: 1px solid rgba(255,255,255,0.05);
            border-radius: 24px;
            backdrop-filter: blur(var(--card-blur));
            transition: all 0.4s cubic-bezier(0.4,0,0.2,1);
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
                    statusLabel.className = 'px-5 py-2 bg-rose-500/10 text-rose-500 rounded-xl text-[8px] font-black uppercase tracking-widest border border-rose-500/10 animate-pulse';
                }
                if (miniLabel) {
                    miniLabel.textContent = 'DAY OFF';
                    miniLabel.className = 'text-[9px] font-black uppercase tracking-widest text-rose-500';
                }
            } else {
                card.classList.remove('is-off');
                if (timeline) timeline.classList.remove('is-day-off-view');
                if (statusLabel) {
                    statusLabel.textContent = 'WORKING DAY';
                    statusLabel.className = 'px-5 py-2 bg-emerald-500/10 text-emerald-500 rounded-xl text-[8px] font-black uppercase tracking-widest border border-emerald-500/10';
                }
                if (miniLabel) {
                    miniLabel.textContent = 'WORKING';
                    miniLabel.className = 'text-[9px] font-black uppercase tracking-widest text-gray-500';
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
            <header class="mb-10 flex flex-col md:flex-row justify-between items-start md:items-end gap-6">
                <div>
                    <h2 class="text-3xl font-black italic uppercase tracking-tighter" style="color:var(--text-main)">
                        My <span style="color:var(--primary)" class="italic">Schedule</span>
                    </h2>
                    <p class="label-muted mt-1 italic">Capacity & Slot Management • Live Updates</p>
                </div>
                <div class="flex flex-col items-end">
                    <p id="headerClock" class="font-black italic text-2xl leading-none tracking-tighter" style="color:var(--text-main)">00:00:00 AM</p>
                    <p class="text-[10px] font-black uppercase tracking-[0.2em] leading-none mt-2" style="color:var(--primary)">
                        <?= date('l, M d, Y') ?>
                    </p>
                </div>
            </header>

            <?php if ($msg): ?>
                <div id="statusAlert" class="glass-card mb-8 px-6 py-4 flex items-center justify-between border-emerald-500/20 bg-emerald-500/5 animate-slide-up" style="animation-delay: 0s;">
                    <div class="flex items-center gap-3">
                        <span class="material-symbols-outlined text-emerald-500">verified</span>
                        <p class="text-[10px] font-black uppercase tracking-widest text-emerald-500"><?= $msg ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 xl:grid-cols-12 gap-8 animate-slide-up">
                <!-- SETTINGS PANEL -->
                <div class="xl:col-span-4">
                    <div class="glass-card p-8 shadow-2xl">
                        <div class="flex items-center gap-3 mb-8">
                            <div class="size-10 rounded-xl bg-primary/10 flex items-center justify-center text-primary">
                                <span class="material-symbols-outlined text-xl">settings_suggest</span>
                            </div>
                            <div>
                                <h3 class="text-xs font-black italic uppercase tracking-widest" style="color:var(--text-main)">Availability Settings</h3>
                                <p class="label-muted" style="font-size: 8px;">Shift Parameters</p>
                            </div>
                        </div>

                        <form method="POST" class="space-y-4">
                            <?php foreach ($week_days as $day):
                                $off = ($avail_map[$day]['is_off_day'] ?? 0) == 1;
                                ?>
                                <div id="card-<?= $day ?>" class="day-card p-5 rounded-2xl <?= $off ? 'is-off' : '' ?>">
                                    <div class="flex justify-between items-center mb-5">
                                        <div class="flex flex-col">
                                            <span class="font-black italic uppercase text-[11px] tracking-widest" style="color:var(--text-main)"><?= $day ?></span>
                                            <p class="label-muted mt-0.5" style="font-size: 7px;" id="label-<?= $day ?>">
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
                                                <p class="label-muted mb-2" style="font-size: 8px;">Shift 1 Start</p>
                                                <input type="time" name="start_<?= $day ?>" value="<?= $avail_map[$day]['start_time'] ?>"
                                                    class="w-full bg-white/[0.03] border border-white/5 rounded-xl p-2.5 text-xs text-white outline-none focus:border-primary transition-all">
                                            </div>
                                            <div>
                                                <p class="label-muted mb-2" style="font-size: 8px;">Shift 1 End</p>
                                                <input type="time" name="end_<?= $day ?>" value="<?= $avail_map[$day]['end_time'] ?>"
                                                    class="w-full bg-white/[0.03] border border-white/5 rounded-xl p-2.5 text-xs text-white outline-none focus:border-primary transition-all">
                                            </div>
                                        </div>
                                        <div class="grid grid-cols-2 gap-3">
                                            <div>
                                                <p class="label-muted mb-2" style="font-size: 8px;">Shift 2 Start</p>
                                                <input type="time" name="start2_<?= $day ?>" value="<?= $avail_map[$day]['start_time_2'] ?>"
                                                    class="w-full bg-white/[0.03] border border-white/5 rounded-xl p-2.5 text-xs text-white outline-none focus:border-primary transition-all">
                                            </div>
                                            <div>
                                                <p class="label-muted mb-2" style="font-size: 8px;">Shift 2 End</p>
                                                <input type="time" name="end2_<?= $day ?>" value="<?= $avail_map[$day]['end_time_2'] ?>"
                                                    class="w-full bg-white/[0.03] border border-white/5 rounded-xl p-2.5 text-xs text-white outline-none focus:border-primary transition-all">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <div class="pt-4">
                                <button type="submit" name="save_availability"
                                    class="w-full bg-primary hover:opacity-90 text-[white] py-4 rounded-2xl font-black uppercase text-[10px] tracking-widest shadow-2xl shadow-primary/20 transition-all active:scale-[0.98] flex items-center justify-center gap-3">
                                    <span class="material-symbols-outlined text-lg">save_as</span> Update Availability
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- DAILY TIMELINE -->
                <div class="xl:col-span-8">
                    <div class="glass-card p-10 flex flex-col h-full min-h-[800px] shadow-2xl relative overflow-hidden">
                        <div class="flex items-center gap-3 mb-8 border-b border-white/5 pb-8">
                            <div class="size-10 rounded-xl bg-primary/10 flex items-center justify-center text-primary">
                                <span class="material-symbols-outlined text-xl">calendar_month</span>
                            </div>
                            <div>
                                <h3 class="text-xs font-black italic uppercase tracking-widest" style="color:var(--text-main)">Daily Schedule View</h3>
                                <p class="label-muted" style="font-size: 8px;">Coaching Timeline • Booked & Available Slots</p>
                            </div>
                        </div>

                        <div class="flex gap-2 mb-10 overflow-x-auto no-scrollbar pb-2">
                            <?php foreach ($week_days as $day): ?>
                                <button id="btn-<?= $day ?>" onclick="openTab('<?= $day ?>')"
                                    class="tab-btn px-6 py-3 rounded-xl text-[9px] font-black uppercase tracking-widest transition-all glass-card border-none">
                                    <?= substr($day, 0, 3) ?>
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
                                    <div class="flex justify-between items-center mb-8 pb-4">
                                        <div>
                                            <h4 class="text-xl font-black italic uppercase italic tracking-tighter" style="color:var(--text-main)"><?= $day_name ?></h4>
                                            <p class="label-muted mt-1" style="font-size: 8px;"><?= date('F d, Y', strtotime($loop_date)) ?></p>
                                        </div>
                                        <span id="status-<?= $day_name ?>" class="<?= $is_off ? 'bg-rose-500/10 text-rose-500 border-rose-500/10 animate-pulse' : 'bg-emerald-500/10 text-emerald-500 border-emerald-500/10' ?> px-5 py-2 rounded-xl text-[8px] font-black uppercase tracking-widest border">
                                            <?= $is_off ? 'DAY OFF' : 'WORKING DAY' ?>
                                        </span>
                                    </div>

                                    <div id="timeline-<?= $day_name ?>" class="space-y-4 <?= $is_off ? 'is-day-off-view' : '' ?>">
                                        <?php
                                        $start_st = strtotime($loop_date . ' 07:00');
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
                                                <div class="booked-slot-box flex items-center bg-<?= $cls ?>-500/10 border border-<?= $cls ?>-500/20 p-6 rounded-3xl group animate-slide-up">
                                                    <div class="w-40 text-[10px] font-black italic text-<?= $cls ?>-500 border-r border-<?= $cls ?>-500/20 pr-6 mr-6 shrink-0">
                                                        <?= date('h:i A', $start_st) ?> - <?= date('h:i A', $slot_end) ?>
                                                    </div>
                                                    <div class="flex-1">
                                                        <p class="text-[8px] font-black text-<?= $cls ?>-500/60 uppercase tracking-widest mb-1"><?= strtoupper($found_booking['status']) ?> • <?= strtoupper($found_booking['service']) ?></p>
                                                        <h5 class="text-sm font-black italic uppercase tracking-tight text-white"><?= htmlspecialchars($found_booking['fullname']) ?></h5>
                                                    </div>
                                                    <span class="material-symbols-outlined text-<?= $cls ?>-500"><?= $is_pending ? 'timer' : 'verified' ?></span>
                                                </div>
                                            <?php elseif ($is_working): ?>
                                                <div class="available-slot-box flex items-center bg-white/[0.02] border border-white/5 p-5 rounded-3xl hover:bg-emerald-500/5 hover:border-emerald-500/20 transition-all group animate-slide-up">
                                                    <div class="w-40 text-[10px] font-black italic text-gray-500 group-hover:text-emerald-500 border-r border-white/10 pr-6 mr-6 shrink-0 transition-colors">
                                                        <?= date('h:i A', $start_st) ?> - <?= date('h:i A', $slot_end) ?>
                                                    </div>
                                                    <div class="flex-1">
                                                        <p class="text-[9px] font-black uppercase tracking-[0.2em] text-emerald-500/40 group-hover:text-emerald-500 transition-colors">AVAILABLE SLOT</p>
                                                    </div>
                                                    <span class="material-symbols-outlined text-emerald-500/20 group-hover:text-emerald-500 transition-colors">add_task</span>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <!-- Blank Slot (Visible when off or non-working) -->
                                            <div class="blank-slot-row <?= ($is_off || (!$found_booking && !$is_working)) ? 'flex' : 'hidden' ?> items-center py-6 px-6 opacity-40 hover:opacity-60 transition-all group">
                                                <div class="w-40 text-[10px] font-black italic text-gray-500 border-r border-white/10 pr-6 mr-6 shrink-0">
                                                    <?= date('h:i A', $start_st) ?> - <?= date('h:i A', $slot_end) ?>
                                                </div>
                                                <div class="h-px flex-1 bg-white/10"></div>
                                            </div>
                                            <?php $start_st = $slot_end; ?>
                                        <?php endwhile; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</body>
</html>