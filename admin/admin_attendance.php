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
$user_id = $_SESSION['user_id'];
$admin_name = $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];
$active_page = "attendance";

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

// Auto-timeout pending checkouts past gym closing time
$currentDate = date('Y-m-d');
$currentTime = date('H:i:s');
$stmtAutoTimeout = $pdo->prepare("
    UPDATE attendance a
    JOIN gyms g ON a.gym_id = g.gym_id
    SET a.attendance_status = 'Did Not Checked Out'
    WHERE a.check_out_time IS NULL 
      AND a.attendance_status = 'Active'
      AND (
          a.attendance_date < ? 
          OR (a.attendance_date = ? AND ? > g.closing_time)
      )
");
$stmtAutoTimeout->execute([$currentDate, $currentDate, $currentTime]);

// --- FILTERING LOGIC ---
$view = $_GET['view'] ?? 'history';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$search_query = $_GET['search'] ?? '';
$user_filter = $_GET['user_id'] ?? 'all';
$status_filter = $_GET['status'] ?? '';
$today_filter_date = date('Y-m-d');

if ($start_date !== '' && $start_date > $today_filter_date) $start_date = $today_filter_date;
if ($end_date !== '' && $end_date > $today_filter_date) $end_date = $today_filter_date;
if ($start_date !== '' && $end_date !== '' && $start_date > $end_date) $start_date = $end_date;

// Base Query
$query = "
    SELECT a.*, u.username, COALESCE(m.profile_picture, u.profile_picture) as profile_picture, CONCAT(u.first_name, ' ', u.last_name) as fullname
    FROM attendance a 
    JOIN members m ON a.member_id = m.member_id 
    JOIN users u ON m.user_id = u.user_id 
    WHERE a.gym_id = ?
";
$params = [$gym_id];

if ($view === 'live') {
    $query .= " AND a.check_out_time IS NULL AND a.attendance_status = 'Active'";
}

if ($start_date) {
    $query .= " AND a.attendance_date >= ?";
    $params[] = $start_date;
}
if ($end_date) {
    $query .= " AND a.attendance_date <= ?";
    $params[] = $end_date;
}
if ($search_query) {
    $query .= " AND (u.username LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?)";
    $sterm = "%$search_query%";
    $params[] = $sterm;
    $params[] = $sterm;
    $params[] = $sterm;
    $params[] = $sterm;
}
if ($user_filter !== '' && $user_filter !== 'all') {
    $query .= " AND u.user_id = ?";
    $params[] = (int) $user_filter;
}
if ($status_filter !== '') {
    if ($status_filter === 'Completed') {
        $query .= " AND a.check_out_time IS NOT NULL AND a.attendance_status <> 'Did Not Checked Out'";
    } elseif ($status_filter === 'Active') {
        $query .= " AND a.check_out_time IS NULL AND a.attendance_status = 'Active'";
    } elseif ($status_filter === 'No Checkout') {
        $query .= " AND a.attendance_status = 'Did Not Checked Out'";
    }
}

$query .= " ORDER BY a.attendance_date DESC, a.check_in_time DESC";

$stmtLogs = $pdo->prepare($query);
$stmtLogs->execute($params);
$attendance_list = $stmtLogs->fetchAll();

// --- FETCH METRICS ---
$today = date('Y-m-d');
$stmtMetricsToday = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE gym_id = ? AND attendance_date = ?");
$stmtMetricsToday->execute([$gym_id, $today]);
$total_today = $stmtMetricsToday->fetchColumn();

$stmtMetricsActive = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE gym_id = ? AND check_out_time IS NULL AND attendance_status = 'Active'");
$stmtMetricsActive->execute([$gym_id]);
$active_now = $stmtMetricsActive->fetchColumn();

$stmtAllLogs = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE gym_id = ?");
$stmtAllLogs->execute([$gym_id]);
$total_logs = (int) $stmtAllLogs->fetchColumn();

// Average Stay Today (Minutes)
$stmtAvg = $pdo->prepare("SELECT AVG(TIMESTAMPDIFF(MINUTE, check_in_time, check_out_time)) FROM attendance WHERE gym_id = ? AND attendance_date = ? AND check_out_time IS NOT NULL");
$stmtAvg->execute([$gym_id, $today]);
$avg_stay = round($stmtAvg->fetchColumn() ?: 0);

$stmtUsers = $pdo->prepare("
    SELECT DISTINCT u.user_id, CONCAT(u.first_name, ' ', u.last_name) AS full_name
    FROM attendance a
    JOIN members m ON a.member_id = m.member_id
    JOIN users u ON m.user_id = u.user_id
    WHERE a.gym_id = ?
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
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Attendance Registry | Horizon Partners</title>
    
    <!-- Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css2?family=<?= urlencode($font_family) ?>:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    
    <!-- TailWind CSS Configuration -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
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
            border: 1px solid rgba(255,255,255,0.05); 
            border-radius: 24px; 
            backdrop-filter: blur(var(--card-blur));
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        .status-card-blue { border: 1px solid rgba(var(--primary-rgb), 0.18); background: linear-gradient(135deg, rgba(var(--primary-rgb), 0.05) 0%, rgba(var(--primary-rgb), 0.01) 100%); }
        .status-card-green { border: 1px solid rgba(16, 185, 129, 0.25); background: linear-gradient(135deg, rgba(16, 185, 129, 0.05) 0%, rgba(16, 185, 129, 0.01) 100%); }
        .status-card-yellow { border: 1px solid rgba(245, 158, 11, 0.25); background: linear-gradient(135deg, rgba(245, 158, 11, 0.05) 0%, rgba(245, 158, 11, 0.01) 100%); }
        .status-card-red { border: 1px solid rgba(244, 63, 94, 0.25); background: linear-gradient(135deg, rgba(244, 63, 94, 0.05) 0%, rgba(244, 63, 94, 0.01) 100%); }

        .search-container {
            background: var(--card-bg);
            border: 1px solid rgba(255,255,255,0.05);
            border-radius: 20px;
            padding: 4px;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }
        .search-container:focus-within {
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(var(--primary-rgb), 0.1);
        }
        
        /* Unified Sidebar Navigation Styles */
        .side-nav { width: 110px; transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1); overflow: hidden; display: flex; flex-direction: column; position: fixed; left: 0; top: 0; height: 100vh; z-index: 50; background-color: var(--background); border-right: 1px solid rgba(255,255,255,0.05); }
        .side-nav:hover { width: 300px; }
        .main-content { margin-left: 110px; flex: 1; min-width: 0; transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1); }
        .side-nav:hover ~ .main-content { margin-left: 300px; }

        .nav-label { opacity: 0; transform: translateX(-15px); transition: all 0.3s ease-in-out; white-space: nowrap; pointer-events: none; color: var(--text-main); }
        .side-nav:hover .nav-label { opacity: 1; transform: translateX(0); pointer-events: auto; }
        .nav-section-label { max-height: 0; opacity: 0; overflow: hidden; transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); margin: 0 !important; pointer-events: none; color: color-mix(in srgb, var(--text-main) 40%, transparent); }
        .side-nav:hover .nav-section-label { max-height: 20px; opacity: 1; margin-bottom: 8px !important; pointer-events: auto; }
        
        .nav-item { 
            display: flex; align-items: center; gap: 16px; padding: 10px 38px; 
            transition: opacity 0.2s ease, color 0.2s ease; 
            text-decoration: none; white-space: nowrap; font-size: 11px; font-weight: 800; 
            text-transform: uppercase; letter-spacing: 0.05em; 
            color: color-mix(in srgb, var(--text-main) 45%, transparent); 
            position: relative;
        }
        .nav-item:hover { color: var(--text-main); }
        .nav-item .material-symbols-rounded { color: var(--highlight); transition: transform 0.2s ease; }
        .nav-item:hover .material-symbols-rounded { transform: scale(1.12); }
        .nav-item.active { color: var(--primary) !important; position: relative; }
        .nav-item.active .material-symbols-rounded { color: var(--primary); }
        .nav-item.active::after { content: ''; position: absolute; right: 0px; top: 50%; transform: translateY(-50%); width: 4px; height: 24px; background: var(--primary); border-radius: 4px 0 0 4px; }
        
        /* Invisible Scroll System */
        *::-webkit-scrollbar { display: none !important; }
        * { -ms-overflow-style: none !important; scrollbar-width: none !important; }
        
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

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
        }

        .input-box::placeholder { color: color-mix(in srgb, var(--text-main) 30%, transparent); }
        
        .tab-btn {
            padding: 12px 24px;
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            color: color-mix(in srgb, var(--text-main) 40%, transparent);
            border-bottom: 2px solid transparent;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .tab-btn.active {
            color: var(--primary);
            border-color: var(--primary);
        }

        .tab-btn:hover:not(.active) { color: var(--text-main); }
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
            background: rgba(15, 13, 18, .96);
            border: 1px solid rgba(255,255,255,.05);
            box-shadow: 0 18px 45px rgba(0,0,0,.45);
            scrollbar-width: none;
        }
        .custom-select-dropdown::-webkit-scrollbar, .searchable-dropdown-overlay::-webkit-scrollbar { display: none; }
        .tenant-option { border: 1px solid transparent; cursor: pointer; transition: all .2s ease; }
        .tenant-option:hover { background: rgba(var(--primary-rgb), .08); border-color: rgba(var(--primary-rgb), .12); color: var(--primary); }
        .tenant-option.selected { background: var(--primary); color: #fff; }
        input[type="date"] { color-scheme: dark; }
        input[type="date"]::-webkit-calendar-picker-indicator { filter: invert(1) brightness(1.35); opacity: .75; cursor: pointer; }
    </style>
    
    <script>
        const availableUsers = <?= json_encode($users_js, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const currentUserFilter = "<?= htmlspecialchars((string) $user_filter, ENT_QUOTES) ?>";
        function updateHeaderClock() {
            const now = new Date();
            const clockEl = document.getElementById('headerClock');
            if (clockEl) {
                clockEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            }
        }
        setInterval(updateHeaderClock, 1000);
        window.addEventListener('DOMContentLoaded', updateHeaderClock);

        function clearAttendanceFilters() {
            window.location.href = 'admin_attendance.php?view=<?= $view ?>';
        }
        let attendanceFilterTimeout;
        function autoSubmitAttendanceFilters(delay = 350) {
            clearTimeout(attendanceFilterTimeout);
            attendanceFilterTimeout = setTimeout(() => document.getElementById('attendanceFilterForm')?.submit(), delay);
        }
        function syncAttendanceDateLimits() {
            const fromInput = document.getElementById('start_date');
            const toInput = document.getElementById('end_date');
            if (!fromInput || !toInput) return;
            const today = new Date().toISOString().slice(0, 10);
            fromInput.max = toInput.value || today;
            toInput.min = fromInput.value || '';
            toInput.max = today;
            if (fromInput.value && fromInput.value > today) fromInput.value = today;
            if (toInput.value && toInput.value > today) toInput.value = today;
            if (fromInput.value && toInput.value && fromInput.value > toInput.value) fromInput.value = toInput.value;
        }
        function toggleCustomDropdown(trigger, event) {
            event.stopPropagation();
            const dropdown = trigger.closest('.custom-select-container').querySelector('.custom-select-dropdown');
            document.getElementById('userDropdown')?.classList.add('hidden');
            document.querySelectorAll('.custom-select-dropdown').forEach((item) => { if (item !== dropdown) item.classList.add('hidden'); });
            dropdown.classList.toggle('hidden');
        }
        function initAttendanceUserDropdown() {
            const input = document.getElementById('userSearchInput');
            const dropdown = document.getElementById('userDropdown');
            const list = document.getElementById('userOptionsList');
            if (!input || !dropdown || !list) return;
            const escapeHtml = (value) => String(value).replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
            const render = (filter = '') => {
                const searchFilter = filter === 'All Users' ? '' : filter.toLowerCase().trim();
                const rows = [{ id: 'all', name: 'All Users' }, ...availableUsers].filter((user) => user.name.toLowerCase().includes(searchFilter));
                list.innerHTML = rows.map((user) => `<button type="button" class="tenant-option w-full text-left px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-widest ${String(user.id) === String(document.getElementById('hidden_user_id').value) ? 'selected' : 'text-[--text-main]/65'}" data-id="${escapeHtml(user.id)}" data-name="${escapeHtml(user.name)}">${escapeHtml(user.name)}</button>`).join('') || '<div class="px-4 py-3 text-[10px] font-black uppercase tracking-widest text-[--text-main]/35">No users found</div>';
            };
            input.addEventListener('focus', () => { document.querySelectorAll('.custom-select-dropdown').forEach((item) => item.classList.add('hidden')); dropdown.classList.remove('hidden'); render(input.value); });
            input.addEventListener('input', () => { dropdown.classList.remove('hidden'); render(input.value); });
            list.addEventListener('click', (event) => {
                const option = event.target.closest('.tenant-option');
                if (!option) return;
                document.getElementById('hidden_user_id').value = option.dataset.id || 'all';
                input.value = option.dataset.name || 'All Users';
                dropdown.classList.add('hidden');
                autoSubmitAttendanceFilters(0);
            });
        }


        function switchTab(tab) {
            document.querySelectorAll('.tab-panel').forEach(p => p.classList.add('hidden'));
            const panel = document.getElementById('panel-' + tab);
            if (panel) panel.classList.remove('hidden');
        }

        // QR Code generation using QRServer API — Black & White standard
        function generateQR() {
            const gymId = '<?= $gym_id ?>';
            const today = new Date().toISOString().split('T')[0];
            const payload = encodeURIComponent(JSON.stringify({ gym_id: gymId, date: today, action: 'checkin' }));
            const qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=${payload}&color=000000&bgcolor=ffffff&margin=4&qzone=1`;
            const img = document.getElementById('qrCodeImg');
            const skeleton = document.getElementById('qrSkeleton');
            const wrapper = document.getElementById('qrWrapper');
            if (!img || !skeleton || !wrapper) return;
            skeleton.style.display = 'flex';
            wrapper.classList.add('hidden');
            img.src = '';
            img.onload = () => {
                skeleton.style.display = 'none';
                wrapper.classList.remove('hidden');
            };
            img.onerror = () => {
                skeleton.style.display = 'flex';
                wrapper.classList.add('hidden');
            };
            img.src = qrUrl;
        }

        // Admin Camera Scanner
        let html5QrCode = null;
        let scannerRunning = false;

        function startAdminScanner() {
            const scanBtn = document.getElementById('startScanBtn');
            const stopBtn = document.getElementById('stopScanBtn');
            const resultBox = document.getElementById('scanResult');
            resultBox.innerHTML = '';
            resultBox.classList.add('hidden');

            if (scannerRunning) return;

            html5QrCode = new Html5Qrcode('adminScannerView');
            html5QrCode.start(
                { facingMode: 'environment' },
                { fps: 10, qrbox: { width: 220, height: 220 } },
                (decodedText) => {
                    stopAdminScanner();
                    resultBox.classList.remove('hidden');
                    try {
                        const data = JSON.parse(decodeURIComponent(decodedText));
                        
                        // Hit the Attendance API
                        fetch('../api/attendance.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ 
                                user_id: data.user_id, 
                                gym_id: '<?= $gym_id ?>', 
                                action: data.action || 'check_in' 
                            })
                        })
                        .then(r => r.json())
                        .then(res => {
                            if (res.success) {
                                resultBox.innerHTML = `
                                    <div class="flex items-center gap-3">
                                        <span class="material-symbols-rounded text-emerald-500 text-2xl">check_circle</span>
                                        <div>
                                            <p class="text-[11px] font-black uppercase text-emerald-400">Success: ${res.member_name}</p>
                                            <p class="text-[9px] text-[--text-main]/40 font-bold uppercase tracking-widest mt-0.5">${res.message}</p>
                                        </div>
                                    </div>`;
                            } else {
                                resultBox.innerHTML = `
                                    <div class="flex items-center gap-3">
                                        <span class="material-symbols-rounded text-rose-500 text-2xl">error</span>
                                        <div>
                                            <p class="text-[11px] font-black uppercase text-rose-400">Scan Failed</p>
                                            <p class="text-[9px] text-[--text-main]/40 font-bold uppercase tracking-widest mt-0.5">${res.message}</p>
                                        </div>
                                    </div>`;
                            }
                        })
                        .catch(err => {
                            resultBox.innerHTML = `<p class="text-[10px] font-black uppercase text-rose-400">Network Error</p>`;
                        });

                    } catch(e) {
                        resultBox.innerHTML = `
                            <div class="flex items-center gap-3">
                                <span class="material-symbols-rounded text-amber-500 text-2xl">qr_code_scanner</span>
                                <p class="text-[11px] font-black uppercase text-[--text-main]/60">Scanned: ${decodedText.substring(0, 60)}...</p>
                            </div>`;
                    }
                },
                () => {}
            ).then(() => {
                scannerRunning = true;
                scanBtn.classList.add('hidden');
                stopBtn.classList.remove('hidden');
                const placeholder = document.getElementById('scannerPlaceholder');
                if (placeholder) placeholder.classList.add('hidden');
            }).catch(err => {
                resultBox.classList.remove('hidden');
                resultBox.innerHTML = `<p class="text-[10px] font-black uppercase text-rose-400">Could not access camera. Please allow camera permission.</p>`;
            });
        }

        function stopAdminScanner() {
            if (html5QrCode && scannerRunning) {
                html5QrCode.stop().then(() => {
                    html5QrCode = null;
                    scannerRunning = false;
                    document.getElementById('startScanBtn').classList.remove('hidden');
                    document.getElementById('stopScanBtn').classList.add('hidden');
                });
            }
        }

        // Elite Pagination Engine
        let tablePagination = {
            currentPage: 1,
            rowsPerPage: 10,
            tableId: 'attendanceTable'
        };

        function initTablePagination() {
            const table = document.getElementById(tablePagination.tableId);
            if (!table) return;
            
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr:not(.no-records)'));
            const totalRows = rows.length;
            
            if (totalRows <= tablePagination.rowsPerPage) {
                document.getElementById('paginationWrap').classList.add('hidden');
                rows.forEach(r => r.style.display = '');
                return;
            }

            document.getElementById('paginationWrap').classList.remove('hidden');
            renderPagination(1);
        }

        function renderPagination(page) {
            tablePagination.currentPage = page;
            const table = document.getElementById(tablePagination.tableId);
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr:not(.no-records)'));
            const totalRows = rows.length;
            const totalPages = Math.ceil(totalRows / tablePagination.rowsPerPage);
            
            const start = (page - 1) * tablePagination.rowsPerPage;
            const end = start + tablePagination.rowsPerPage;

            rows.forEach((row, index) => {
                row.style.display = (index >= start && index < end) ? '' : 'none';
            });

            // Update Status Label
            const actualEnd = Math.min(end, totalRows);
            document.getElementById('paginationStatus').textContent = `Showing ${start + 1} to ${actualEnd} of ${totalRows} entries`;

            // Render Buttons
            const controls = document.getElementById('paginationControls');
            controls.innerHTML = '';

            // Prev Button
            const prevBtn = document.createElement('button');
            prevBtn.className = `pagination-btn ${page === 1 ? 'disabled' : ''}`;
            prevBtn.textContent = 'Prev';
            if (page > 1) prevBtn.onclick = () => renderPagination(page - 1);
            controls.appendChild(prevBtn);

            // Index Buttons (Simplified)
            for (let i = 1; i <= totalPages; i++) {
                if (i === 1 || i === totalPages || (i >= page - 1 && i <= page + 1)) {
                    const btn = document.createElement('button');
                    btn.className = `pagination-btn ${i === page ? 'active' : ''}`;
                    btn.textContent = i;
                    btn.onclick = () => renderPagination(i);
                    controls.appendChild(btn);
                } else if (i === page - 2 || i === page + 2) {
                    const dot = document.createElement('span');
                    dot.className = 'text-[--text-main]/20 px-1';
                    dot.textContent = '...';
                    controls.appendChild(dot);
                }
            }

            // Next Button
            const nextBtn = document.createElement('button');
            nextBtn.className = `pagination-btn ${page === totalPages ? 'disabled' : ''}`;
            nextBtn.textContent = 'Next';
            if (page < totalPages) nextBtn.onclick = () => renderPagination(page + 1);
            controls.appendChild(nextBtn);
        }

        window.addEventListener('DOMContentLoaded', () => {
            updateHeaderClock();
            syncAttendanceDateLimits();
            initAttendanceUserDropdown();
            if ('<?= $view ?>' === 'scan') generateQR();
            switchTab('<?= $view ?>');
            
            // Wait slightly for DOM to settle
            setTimeout(initTablePagination, 100);
        });
        document.addEventListener('click', (event) => {
            const customOption = event.target.closest('.custom-option');
            if (customOption) {
                const container = customOption.closest('.custom-select-container');
                container.querySelector('input[type="hidden"]').value = customOption.dataset.value;
                container.querySelector('.custom-select-label').textContent = customOption.textContent.trim();
                container.querySelector('.custom-select-dropdown').classList.add('hidden');
                autoSubmitAttendanceFilters(0);
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

<!-- Main Page Content Area -->
<div class="main-content flex-1 overflow-y-auto no-scrollbar">
    <main class="p-10 max-w-[1400px] mx-auto pb-20">
        
        <!-- Welcome Header -->
        <header class="mb-12 flex flex-row justify-between items-end gap-6">
            <div>
                <h2 class="text-3xl font-black italic uppercase tracking-tighter leading-none text-white transition-all"><span class="opacity-40">Attendance</span> <span class="text-primary">Registry</span></h2>
                <p class="text-[--text-main]/60 text-[10px] font-bold uppercase tracking-widest mt-2 px-1 opacity-60">Gym Attendance Logs • Check-In &amp; Check-Out Records</p>
            </div>
            <div class="flex items-end gap-8 text-right shrink-0">
                <div class="flex flex-col items-end">
                    <p id="headerClock" class="text-[--text-main] font-black italic text-2xl leading-none tracking-tighter uppercase">00:00:00 AM</p>
                    <p class="text-primary text-[10px] font-black uppercase tracking-[0.2em] leading-none mt-2"><?= date('l, M d, Y') ?></p>
                </div>
            </div>
        </header>

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6 mb-10">
            <div class="glass-card p-8 status-card-blue relative overflow-hidden group hover:scale-[1.02] transition-all">
                <span class="material-symbols-rounded absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-primary">fact_check</span>
                <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">All Logs</p>
                <h3 class="text-3xl font-black italic uppercase text-[--text-main]"><?= number_format($total_logs) ?></h3>
                <p class="text-[10px] font-black uppercase mt-2 italic text-primary">Attendance Records</p>
            </div>
            <div class="glass-card p-8 status-card-green relative overflow-hidden group hover:scale-[1.02] transition-all">
                <span class="material-symbols-rounded absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-emerald-500">today</span>
                <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">Today</p>
                <h3 class="text-3xl font-black italic uppercase text-[--text-main]"><?= number_format($total_today) ?></h3>
                <p class="text-emerald-500 text-[10px] font-black uppercase mt-2 italic">Daily Entries</p>
            </div>
            <a href="?view=live" class="glass-card p-8 status-card-yellow relative overflow-hidden group hover:scale-[1.02] transition-all">
                <span class="material-symbols-rounded absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-amber-500">sensors</span>
                <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">Active Now</p>
                <h3 class="text-3xl font-black italic uppercase text-[--text-main]"><?= number_format($active_now) ?></h3>
                <p class="text-amber-500 text-[10px] font-black uppercase mt-2 italic">Currently In Gym</p>
            </a>
            <div class="glass-card p-8 status-card-red relative overflow-hidden group hover:scale-[1.02] transition-all">
                <span class="material-symbols-rounded absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-rose-500">update</span>
                <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">Average Stay</p>
                <h3 class="text-3xl font-black italic uppercase text-[--text-main]"><?= $avg_stay ?></h3>
                <p class="text-rose-500 text-[10px] font-black uppercase mt-2 italic">Minutes Today</p>
            </div>
        </div>

        <!-- Tab Switcher -->
        <div class="flex items-center gap-2 mb-10 border-b border-white/5">
            <a href="?view=history" class="tab-btn <?= ($view === 'history') ? 'active' : '' ?>">Attendance Logs</a>
            <a href="?view=live" class="tab-btn <?= ($view === 'live') ? 'active' : '' ?>">In Gym Now</a>
            <a href="?view=scan" class="tab-btn <?= ($view === 'scan') ? 'active' : '' ?>">Scan to Check In</a>
        </div>

        <?php if ($view === 'scan'): ?>
        <!-- QR Code Scan Panel -->
        <div id="panel-scan" class="tab-panel">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-10">
                <!-- QR Code Card -->
                <div class="glass-card p-10 flex flex-col items-center justify-center gap-8 text-center relative overflow-hidden">
                    <div class="absolute inset-0 bg-gradient-to-b from-primary/5 to-transparent pointer-events-none"></div>
                    <div class="relative z-10">
                        <p class="text-[--text-main]/40 text-[10px] font-black uppercase tracking-[0.2em] mb-2">Daily Authentication Matrix</p>
                        <h3 class="text-2xl font-black italic uppercase text-white tracking-tighter">Scan to Check In</h3>
                        <p class="text-primary text-[10px] mt-2 font-black uppercase tracking-[0.15em] italic"><?= date('l, M d, Y') ?></p>
                    </div>
                    <!-- QR Code Display -->
                    <div class="relative z-10 flex items-center justify-center w-[280px] h-[280px] p-6 glass-card border-white/10 bg-white shadow-2xl">
                        <!-- Skeleton -->
                        <div id="qrSkeleton" class="absolute inset-6 rounded-xl bg-black/5 animate-pulse flex items-center justify-center">
                            <span class="material-symbols-rounded text-6xl text-black/10">qr_code_2</span>
                        </div>
                        <!-- QR Image -->
                        <div id="qrWrapper" class="hidden w-full h-full p-2">
                            <img id="qrCodeImg" src="" alt="Check-In QR" class="w-full h-full object-contain block" />
                        </div>
                    </div>
                    <div class="relative z-10 flex items-center gap-3">
                        <button onclick="generateQR()" class="flex items-center gap-3 px-6 py-3 bg-white/5 border border-white/10 rounded-2xl text-[10px] font-black uppercase tracking-widest text-[--text-main]/60 hover:bg-white/10 hover:text-primary transition-all active:scale-95 group">
                            <span class="material-symbols-rounded text-base group-hover:rotate-180 transition-transform">refresh</span> 
                            Refresh Vector
                        </button>
                    </div>
                    <p class="relative z-10 text-[9px] text-[--text-main]/20 font-bold uppercase tracking-[0.15em] italic mt-4 px-10">Members scan this using the Horizon app to synchronize their attendance registry</p>
                </div>

                <!-- Admin Camera Scanner Card -->
                <div class="glass-card p-8 flex flex-col gap-6">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-widest text-[--text-main]/60 mb-1">Admin Mode</p>
                        <h3 class="text-xl font-black italic uppercase text-white">Scan Member QR</h3>
                        <p class="text-[10px] text-[--text-main]/40 font-bold mt-1">Use your camera to scan a member's QR code and mark them present.</p>
                    </div>

                    <!-- Camera Viewer -->
                    <div class="relative rounded-2xl overflow-hidden bg-black/40 border border-white/5" style="min-height: 250px;">
                        <div id="adminScannerView" class="w-full"></div>
                        <div id="scannerPlaceholder" class="absolute inset-0 flex flex-col items-center justify-center gap-3">
                            <span class="material-symbols-rounded text-5xl text-[--text-main]/20">photo_camera</span>
                            <p class="text-[10px] font-black uppercase tracking-widest text-[--text-main]/20">Camera not started</p>
                        </div>
                    </div>

                    <!-- Scan Result -->
                    <div id="scanResult" class="hidden p-4 rounded-2xl bg-emerald-500/5 border border-emerald-500/20"></div>

                    <!-- Controls -->
                    <div class="flex gap-3">
                        <button id="startScanBtn" onclick="startAdminScanner()" class="flex-1 flex items-center justify-center gap-2 h-[46px] bg-primary hover:bg-primary/90 text-white rounded-xl text-[10px] font-black uppercase tracking-widest transition-all shadow-lg shadow-primary/20 active:scale-95">
                            <span class="material-symbols-rounded text-base">qr_code_scanner</span>
                            Start Scanning
                        </button>
                        <button id="stopScanBtn" onclick="stopAdminScanner()" class="hidden flex-1 flex items-center justify-center gap-2 h-[46px] bg-rose-500/10 border border-rose-500/20 text-rose-500 hover:bg-rose-500/20 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all active:scale-95">
                            <span class="material-symbols-rounded text-base">stop_circle</span>
                            Stop Camera
                        </button>
                    </div>

                    <div class="p-4 rounded-2xl bg-amber-500/5 border border-amber-500/10">
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-rounded text-amber-500 text-base">info</span>
                            <p class="text-[9px] text-amber-500/80 font-black uppercase tracking-widest">Allow camera access when scanning the members qr</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php else: ?>

        <!-- Dynamic Filter Matrix -->
        <div class="glass-card shadow-2xl overflow-hidden border border-white/5">
            <div class="p-8 border-b border-white/5 bg-white/[0.01]">
            <form id="attendanceFilterForm" method="GET" class="flex flex-nowrap items-center gap-5 relative">
                <input type="hidden" name="view" value="<?= $view ?>">
                <input type="hidden" name="user_id" id="hidden_user_id" value="<?= htmlspecialchars((string) $user_filter) ?>">
                    <div class="flex-1 min-w-[260px] relative group">
                        <span class="material-symbols-rounded absolute left-4 top-1/2 -translate-y-1/2 text-base text-primary/50 transition-transform group-hover:scale-110">search</span>
                        <input type="text" name="search" value="<?= htmlspecialchars($search_query) ?>" placeholder="Search records..." autocomplete="off" oninput="autoSubmitAttendanceFilters()"
                            class="w-full h-[52px] bg-white/5 border border-white/10 rounded-2xl pl-12 pr-4 text-[10px] font-black uppercase tracking-widest outline-none text-[--text-main] hover:border-white/20 transition-all focus:border-primary">
                    </div>
                    <div id="userSearchContainer" class="flex-1 min-w-[240px] relative group">
                        <span class="material-symbols-rounded absolute left-4 top-1/2 -translate-y-1/2 text-base text-primary/50 transition-transform group-hover:scale-110 z-10">person_search</span>
                        <input id="userSearchInput" type="text" value="<?= htmlspecialchars($current_user_name) ?>" autocomplete="off"
                            class="w-full h-[52px] bg-white/5 border border-white/10 rounded-2xl pl-12 pr-11 text-[10px] font-black uppercase tracking-widest outline-none text-[--text-main] hover:border-white/20 transition-all focus:border-primary">
                        <span class="material-symbols-rounded absolute right-4 top-1/2 -translate-y-1/2 text-primary/70 text-base pointer-events-none transition-transform group-hover:scale-110">expand_more</span>
                        <div id="userDropdown" class="absolute left-0 right-0 top-full mt-2 z-[100] rounded-xl p-1.5 searchable-dropdown-overlay hidden max-h-72 overflow-y-auto">
                            <div id="userOptionsList" class="space-y-1"></div>
                        </div>
                    </div>
                    <div class="w-[180px] relative group shrink-0 custom-select-container">
                        <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>">
                        <div class="relative custom-select-trigger cursor-pointer" onclick="toggleCustomDropdown(this, event)">
                            <div class="h-[52px] bg-white/5 border border-white/10 rounded-2xl px-5 pr-11 flex items-center text-[10px] font-black uppercase tracking-widest text-[--text-main] hover:border-white/20 transition-all">
                                <span class="custom-select-label"><?= htmlspecialchars($status_filter ?: 'All Status') ?></span>
                            </div>
                            <span class="material-symbols-rounded absolute right-4 top-1/2 -translate-y-1/2 text-primary/70 text-base pointer-events-none transition-transform group-hover:scale-110">expand_more</span>
                        </div>
                        <div class="absolute left-0 right-0 top-full mt-2 z-[100] rounded-xl p-1.5 space-y-0.5 custom-select-dropdown hidden max-h-64 overflow-y-auto">
                            <?php foreach (['' => 'All Status', 'Active' => 'Active', 'Completed' => 'Completed', 'No Checkout' => 'No Checkout'] as $value => $label): ?>
                                <button type="button" class="custom-option tenant-option w-full text-left px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-widest <?= ($status_filter === $value) ? 'selected' : 'text-[--text-main]/65' ?>" data-value="<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($label) ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <?php if ($view === 'history'): ?>
                    <input type="date" name="start_date" id="start_date" value="<?= htmlspecialchars($start_date) ?>" max="<?= htmlspecialchars($end_date ?: date('Y-m-d')) ?>" onchange="syncAttendanceDateLimits(); autoSubmitAttendanceFilters(0)"
                        class="w-[170px] h-[52px] bg-white/5 border border-white/10 rounded-2xl px-5 text-[10px] font-black uppercase tracking-widest outline-none text-[--text-main] hover:border-white/20 transition-all focus:border-primary shrink-0">
                    <input type="date" name="end_date" id="end_date" value="<?= htmlspecialchars($end_date) ?>" min="<?= htmlspecialchars($start_date) ?>" max="<?= date('Y-m-d') ?>" onchange="syncAttendanceDateLimits(); autoSubmitAttendanceFilters(0)"
                        class="w-[170px] h-[52px] bg-white/5 border border-white/10 rounded-2xl px-5 text-[10px] font-black uppercase tracking-widest outline-none text-[--text-main] hover:border-white/20 transition-all focus:border-primary shrink-0">
                    <?php endif; ?>

                    <button type="button" onclick="clearAttendanceFilters()" class="h-[52px] w-[52px] rounded-2xl bg-white/5 border border-white/5 flex items-center justify-center text-white/30 hover:text-white hover:bg-white/10 transition-all" title="Reset filters">
                        <span class="material-symbols-rounded text-lg">refresh</span>
                    </button>
                    <button type="button" onclick="alert('CSV Export Protocol Initialized')" class="h-[52px] w-[52px] rounded-2xl bg-white/5 border border-white/5 flex items-center justify-center text-primary/70 hover:text-white hover:bg-white/10 transition-all" title="Export">
                        <span class="material-symbols-rounded text-lg">download</span>
                    </button>
            </form>
            </div>

        <div class="hidden">
            <p class="text-[10px] font-black uppercase tracking-widest text-[--text-main]/40 italic">Attendance Records — <span class="text-[--text-main]"><?= $view === 'live' ? 'Members Currently In Gym' : 'Full Log History' ?></span></p>
            <div class="flex items-center gap-4">
                <span class="px-4 py-1.5 rounded-full bg-white/5 border border-white/5 text-[9px] font-black uppercase tracking-widest text-[--text-main]/40">Records: <?= count($attendance_list) ?></span>
            </div>
        </div>

        <!-- Data Log Table -->
            <div class="overflow-x-auto no-scrollbar">
                <table id="attendanceTable" class="w-full text-left order-collapse border-separate border-spacing-0">
                    <thead>
                        <tr class="bg-white/5 border-b border-white/5">
                            <th class="px-8 py-5 table-header-alt">Name</th>
                            <th class="px-8 py-5 table-header-alt">Date</th>
                            <th class="px-8 py-5 table-header-alt text-center">Check In</th>
                            <th class="px-8 py-5 table-header-alt text-center">Check Out</th>
                            <th class="px-8 py-5 table-header-alt text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5 text-sm font-medium">
                        <?php if (empty($attendance_list)): ?>
                        <tr>
                            <td colspan="5" class="px-8 py-24 text-center text-[11px] font-black uppercase tracking-[0.3em] text-[--text-main] opacity-20">
                                No attendance records found.
                            </td>
                        </tr>
                        <?php else: foreach ($attendance_list as $row): 
                            $isTraining = (empty($row['check_out_time'])); 
                            $check_in_ts = strtotime($row['attendance_date'] . ' ' . $row['check_in_time']);
                            $check_out_ts = $row['check_out_time'] ? strtotime($row['attendance_date'] . ' ' . $row['check_out_time']) : null;
                        ?>
                        <tr class="hover:bg-white/[0.02] group transition-colors">
                            <td class="px-8 py-5">
                                <div class="flex items-center gap-4">
                                    <div class="size-11 rounded-full flex items-center justify-center font-black text-[11px] border border-white/10 shrink-0 overflow-hidden shadow-inner relative" style="background:rgba(var(--primary-rgb), 0.1); color:var(--primary)">
                                        <?php if (!empty($row['profile_picture'])): 
                                            $pfp_src = (strpos($row['profile_picture'], 'data:image') === 0) ? $row['profile_picture'] : '../' . $row['profile_picture'];
                                        ?>
                                            <img src="<?= htmlspecialchars($pfp_src) ?>" class="size-full object-cover" alt="">
                                        <?php else: ?>
                                            <?= htmlspecialchars(strtoupper(substr($row['fullname'] ?: $row['username'], 0, 1))) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <p class="text-[13px] font-bold tracking-wide text-[--text-main] truncate"><?= htmlspecialchars($row['fullname'] ?: $row['username']) ?></p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-8 py-5">
                                <p class="text-[12px] font-bold whitespace-nowrap" style="color:var(--primary)"><?= date('M d, Y', $check_in_ts) ?></p>
                                <p class="text-[11px] font-semibold text-[--text-main]/50"><?= date('l', $check_in_ts) ?></p>
                            </td>
                            <td class="px-8 py-5 text-center">
                                <div class="inline-block px-3 py-1.5 rounded-xl bg-emerald-500/5 border border-emerald-500/10">
                                    <p class="text-[12px] font-bold text-emerald-500 uppercase"><?= date('h:i A', $check_in_ts) ?></p>
                                </div>
                            </td>
                            <td class="px-8 py-5 text-center">
                                <?php if ($isTraining): ?>
                                    <?php if ($row['attendance_status'] === 'Did Not Checked Out'): ?>
                                        <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-xl bg-rose-500/5 border border-rose-500/10">
                                            <span class="text-rose-500 text-[9px] font-black uppercase tracking-widest">MISSING</span>
                                        </div>
                                    <?php else: ?>
                                        <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-xl bg-amber-500/5 border border-amber-500/10">
                                            <span class="size-1 rounded-full bg-amber-500 animate-ping"></span>
                                            <span class="text-amber-500 text-[9px] font-black uppercase tracking-widest">ACTIVE</span>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="inline-block px-3 py-1.5 rounded-xl bg-white/5 border border-white/5">
                                        <p class="text-[12px] font-bold text-[--text-main]/60 uppercase"><?= date('h:i A', $check_out_ts) ?></p>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="px-8 py-5 text-center">
                                <?php if ($isTraining): ?>
                                    <?php if ($row['attendance_status'] === 'Did Not Checked Out'): ?>
                                        <span class="px-4 py-1.5 rounded-full bg-rose-500/10 border border-rose-500/20 text-[8px] text-rose-500 font-black uppercase tracking-widest">NO CHECKOUT</span>
                                    <?php else: ?>
                                        <span class="px-4 py-1.5 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-[8px] text-emerald-500 font-black uppercase tracking-widest">PRESENT</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="px-4 py-1.5 rounded-full bg-white/5 border border-white/5 text-[8px] text-[--text-main]/45 font-black uppercase tracking-widest">COMPLETED</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Elite Pagination Container -->
            <div id="paginationWrap" class="px-8 py-6 border-t border-white/5 flex flex-col md:flex-row justify-between items-center gap-4 bg-white/[0.01] hidden">
                <p id="paginationStatus" class="pagination-status">Showing 10 of 45 entries</p>
                <div id="paginationControls" class="flex items-center gap-2">
                    <!-- Dynamic Buttons -->
                </div>
            </div>
        </div>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
