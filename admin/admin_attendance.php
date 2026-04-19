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

// --- FILTERING LOGIC ---
$view = $_GET['view'] ?? 'history';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$search_query = $_GET['search'] ?? '';

// Base Query
$query = "
    SELECT a.*, u.username, CONCAT(u.first_name, ' ', u.last_name) as fullname
    FROM attendance a 
    JOIN members m ON a.member_id = m.member_id 
    JOIN users u ON m.user_id = u.user_id 
    WHERE a.gym_id = ?
";
$params = [$gym_id];

if ($view === 'live') {
    $query .= " AND a.check_out_time IS NULL";
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
    $query .= " AND (u.username LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
    $sterm = "%$search_query%";
    $params[] = $sterm;
    $params[] = $sterm;
    $params[] = $sterm;
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

$stmtMetricsActive = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE gym_id = ? AND check_out_time IS NULL");
$stmtMetricsActive->execute([$gym_id]);
$active_now = $stmtMetricsActive->fetchColumn();
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
            theme: { extend: { colors: { 
                "primary": "var(--primary)", 
                "background": "var(--background)", 
                "card-bg": "var(--card-bg)", 
                "text-main": "var(--text-main)",
                "highlight": "var(--highlight)"
            }}}
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
            min-h-screen: 100vh; 
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

        .status-card-green {
            border: 1px solid #10b981;
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.05) 0%, rgba(20, 18, 26, 1) 100%);
        }

        .status-card-yellow {
            border: 1px solid #f59e0b;
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.05) 0%, rgba(20, 18, 26, 1) 100%);
        }

        .status-card-red {
            border: 1px solid #ef4444;
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.05) 0%, rgba(20, 18, 26, 1) 100%);
        }

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
        .nav-section-label { max-height: 0; opacity: 0; overflow: hidden; transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); margin: 0 !important; pointer-events: none; }
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
        
        .alert-pulse { animation: alert-pulse 2s cubic-bezier(0.4, 0, 0.2, 1) infinite; }
        @keyframes alert-pulse { 0%, 100% { opacity: 1; } 50% { opacity: .4; } }
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

        function clearAttendanceFilters() {
            window.location.href = 'admin_attendance.php?view=<?= $view ?>';
        }

        function printQR() {
            const img = document.getElementById('qrCodeImg');
            if (!img || !img.src) return;
            const w = window.open('', '_blank');
            w.document.write(`<html><body style="margin:0;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#fff">
                <div style="text-align:center;font-family:sans-serif">
                    <p style="font-size:11px;font-weight:900;letter-spacing:2px;text-transform:uppercase;margin-bottom:12px;color:#555">Daily Check-In QR Code &bull; <?= date('M d, Y') ?></p>
                    <img src="${img.src}" style="width:280px;height:280px;display:block;margin:0 auto" />
                    <p style="font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase;margin-top:12px;color:#999">Scan using the Horizon App</p>
                </div>
            </body></html>`);
            w.document.close();
            w.onload = () => { w.print(); };
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

        window.addEventListener('DOMContentLoaded', () => {
            updateHeaderClock();
            if ('<?= $view ?>' === 'scan') generateQR();
            switchTab('<?= $view ?>');
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
                <h2 class="text-3xl font-black italic uppercase tracking-tighter leading-none tracking-tight text-white"><span class="opacity-40">Attendance</span> <span class="text-primary">Registry</span></h2>
                <p class="text-[--text-main]/60 text-[10px] font-bold uppercase tracking-widest mt-2 px-1 opacity-60">Gym Attendance Logs • Check-In &amp; Check-Out Records</p>
            </div>
            <div class="flex items-end gap-8 text-right shrink-0">
                <div class="flex flex-col items-end">
                    <p id="headerClock" class="text-[--text-main] font-black italic text-2xl leading-none tracking-tighter uppercase">00:00:00 AM</p>
                    <p class="text-primary text-[10px] font-black uppercase tracking-[0.2em] leading-none mt-2"><?= date('l, M d, Y') ?></p>
                </div>
            </div>
        </header>

        <!-- Dynamic Stat Overview Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-12">
            <!-- Total Daily Logs Card -->
            <div class="glass-card p-8 status-card-green relative overflow-hidden group hover:scale-[1.02] block transition-all shadow-lg hover:shadow-primary/10">
                <span class="material-symbols-rounded absolute right-8 top-1/2 -translate-y-1/2 text-7xl opacity-5 group-hover:scale-110 transition-transform text-emerald-500">history</span>
                <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">Total Daily Logs</p>
                <h3 class="text-2xl font-black italic uppercase text-emerald-400"><?= number_format($total_today) ?></h3>
                <p class="text-emerald-500 text-[10px] font-black uppercase mt-2 tracking-tight">Entries Today</p>
            </div>

            <!-- Active Sessions Card -->
            <a href="?view=live" class="glass-card p-8 status-card-yellow relative overflow-hidden group hover:scale-[1.02] block transition-all shadow-lg hover:shadow-amber-500/10">
                <span class="material-symbols-rounded absolute right-8 top-1/2 -translate-y-1/2 text-7xl opacity-5 group-hover:scale-110 transition-transform text-amber-500">sensors</span>
                <p class="text-[10px] font-black uppercase text-amber-500/70 mb-2 tracking-widest">Active Now</p>
                <h3 class="text-2xl font-black italic uppercase text-amber-400"><?= $active_now ?></h3>
                <p class="text-amber-500 text-[10px] font-black uppercase mt-2 tracking-tight">Currently Training</p>
            </a>
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
                <div class="glass-card p-10 flex flex-col items-center justify-center gap-6 text-center">
                    <div class="mb-2">
                        <p class="text-[10px] font-black uppercase tracking-widest text-[--text-main]/60 mb-1">Daily Check-In Code</p>
                        <h3 class="text-xl font-black italic uppercase text-white">Scan to Mark Attendance</h3>
                        <p class="text-[10px] text-[--text-main]/40 mt-1 font-bold uppercase tracking-widest"><?= date('l, M d, Y') ?></p>
                    </div>
                    <!-- QR Code Display -->
                    <div class="flex items-center justify-center w-[258px] h-[258px]">
                        <!-- Skeleton -->
                        <div id="qrSkeleton" class="w-full h-full rounded-2xl bg-white/5 border border-white/10 animate-pulse flex items-center justify-center">
                            <span class="material-symbols-rounded text-5xl text-[--text-main]/20">qr_code_2</span>
                        </div>
                        <!-- QR Image -->
                        <div id="qrWrapper" class="hidden w-full h-full bg-white rounded-2xl shadow-xl p-2">
                            <img id="qrCodeImg" src="" alt="Check-In QR" class="w-full h-full object-contain block rounded-xl" />
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <button onclick="generateQR()" class="flex items-center gap-2 px-5 py-2.5 bg-white/5 border border-white/10 rounded-xl text-[10px] font-black uppercase tracking-widest text-[--text-main]/60 hover:bg-white/10 hover:text-primary transition-all active:scale-95">
                            <span class="material-symbols-rounded text-sm">refresh</span> Refresh
                        </button>
                        <button onclick="printQR()" class="flex items-center gap-2 px-5 py-2.5 bg-primary/10 border border-primary/30 rounded-xl text-[10px] font-black uppercase tracking-widest text-primary hover:bg-primary/20 transition-all active:scale-95">
                            <span class="material-symbols-rounded text-sm">print</span> Print
                        </button>
                    </div>
                    <p class="text-[9px] text-[--text-main]/30 font-bold uppercase tracking-widest">Members scan this using the Horizon app to check in</p>
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
                            <p class="text-[9px] text-amber-500/80 font-black uppercase tracking-widest">Allow camera access when prompted. This works on HTTPS or localhost.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php else: ?>

        <!-- Functional Filter Form (shown for history + live tabs) -->
        <div class="mb-10">
            <form method="GET" class="glass-card p-8 border border-white/5 relative overflow-hidden bg-white/[0.01]">
                <input type="hidden" name="view" value="<?= $view ?>">
                <div class="grid grid-cols-1 md:grid-cols-2 <?= ($view === 'history') ? 'lg:grid-cols-5' : 'lg:grid-cols-3' ?> gap-6 items-end">
                    <div class="space-y-2 lg:col-span-1">
                        <p class="text-[10px] font-black uppercase tracking-widest text-[--text-main]/60 ml-1">Member Search</p>
                        <div class="relative group">
                            <span class="material-symbols-rounded absolute left-4 top-1/2 -translate-y-1/2 text-[--text-main]/40 text-lg group-focus-within:text-primary transition-colors">search</span>
                            <input type="text" name="search" value="<?= htmlspecialchars($search_query) ?>" placeholder="Search ID or name..." class="input-box pl-12 w-full">
                        </div>
                    </div>

                    <?php if ($view === 'history'): ?>
                    <div class="space-y-2">
                        <p class="text-[10px] font-black uppercase tracking-widest text-[--text-main]/60 ml-1">Period (From)</p>
                        <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" class="input-box w-full [color-scheme:dark]">
                    </div>

                    <div class="space-y-2">
                        <p class="text-[10px] font-black uppercase tracking-widest text-[--text-main]/60 ml-1">Period (To)</p>
                        <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" class="input-box w-full [color-scheme:dark]">
                    </div>
                    <?php endif; ?>

                    <div class="flex gap-3">
                        <button type="submit" class="flex-1 bg-primary hover:bg-primary/90 text-white h-[46px] rounded-xl text-[10px] font-black uppercase tracking-widest transition-all shadow-lg shadow-primary/20 active:scale-95">Apply Log</button>
                        <button type="button" onclick="clearAttendanceFilters()" class="size-[46px] rounded-xl bg-white/5 border border-white/5 flex items-center justify-center text-[--text-main]/40 hover:bg-rose-500/10 hover:text-rose-500 transition-all group active:scale-95">
                            <span class="material-symbols-rounded text-xl group-hover:rotate-180 transition-transform">restart_alt</span>
                        </button>
                    </div>

                    <div class="lg:col-span-1">
                        <button type="button" onclick="alert('CSV Export Protocol Initialized')" class="w-full bg-white/5 border border-white/10 hover:bg-white/10 text-primary h-[46px] rounded-xl text-[10px] font-black uppercase tracking-widest transition-all flex items-center justify-center gap-2 group active:scale-95">
                            <span class="material-symbols-rounded text-lg group-hover:-translate-y-0.5 transition-transform">download</span>
                            Export CSV
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <div class="flex justify-between items-center mb-6 px-2">
            <p class="text-[10px] font-black uppercase tracking-widest text-[--text-main]/40 italic">Attendance Records — <span class="text-[--text-main]"><?= $view === 'live' ? 'Members Currently In Gym' : 'Full Log History' ?></span></p>
            <div class="flex items-center gap-4">
                <span class="px-4 py-1.5 rounded-full bg-white/5 border border-white/5 text-[9px] font-black uppercase tracking-widest text-[--text-main]/40">Records: <?= count($attendance_list) ?></span>
            </div>
        </div>

        <!-- Data Log Table -->
        <div class="glass-card flex flex-col overflow-hidden">
            <div class="overflow-x-auto no-scrollbar">
                <table class="w-full text-left order-collapse">
                    <thead>
                        <tr class="bg-white/[0.01] text-[9px] font-black uppercase tracking-widest text-[--text-main]/40 border-b border-white/5">
                            <th class="px-8 py-5">Member</th>
                            <th class="px-8 py-5 text-center">Record No.</th>
                            <th class="px-8 py-5">Time In / Time Out</th>
                            <th class="px-8 py-5 text-right">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <?php if (empty($attendance_list)): ?>
                        <tr>
                            <td colspan="4" class="px-8 py-24 text-center">
                                <span class="material-symbols-rounded text-4xl text-[--text-main]/20 mb-4 block">event_busy</span>
                                <p class="text-[10px] font-black uppercase tracking-widest text-[--text-main]/20">No attendance records found.</p>
                            </td>
                        </tr>
                        <?php else: foreach ($attendance_list as $row): 
                            $isTraining = (empty($row['check_out_time'])); 
                            $check_in_ts = strtotime($row['attendance_date'] . ' ' . $row['check_in_time']);
                            $check_out_ts = $row['check_out_time'] ? strtotime($row['attendance_date'] . ' ' . $row['check_out_time']) : null;
                        ?>
                        <tr class="hover:bg-white/[0.01] group transition-colors">
                            <td class="px-8 py-6 border-l-2 border-transparent hover:border-primary transition-all">
                                <div class="flex items-center gap-4">
                                    <div class="size-10 rounded-2xl bg-primary/10 border border-white/5 flex items-center justify-center text-primary font-black italic text-base">
                                        <?= substr($row['fullname'] ?: $row['username'], 0, 1) ?>
                                    </div>
                                    <div>
                                        <p class="text-[13px] font-black italic uppercase text-[--text-main] group-hover:text-primary transition-colors"><?= htmlspecialchars($row['fullname'] ?: $row['username']) ?></p>
                                        <p class="text-[10px] font-bold text-[--text-main]/30 tracking-tight lowercase">@<?= htmlspecialchars($row['username']) ?></p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-8 py-6 text-center">
                                <span class="px-3 py-1.5 rounded-lg bg-white/5 border border-white/5 text-[9px] font-black text-[--text-main]/40 uppercase tracking-widest">#<?= str_pad($row['attendance_id'], 5, '0', STR_PAD_LEFT) ?></span>
                            </td>
                            <td class="px-8 py-6">
                                <div class="space-y-0.5 text-left font-mono">
                                    <div class="text-[11px] font-black italic text-[--text-main] uppercase flex items-center gap-2">
                                        <?= date('h:i A', $check_in_ts) ?>
                                        <span class="text-[--text-main]/20">→</span>
                                        <?php if ($isTraining): ?>
                                            <span class="text-emerald-500 text-[10px] font-extrabold animate-pulse">ACTIVE NOW</span>
                                        <?php else: ?>
                                            <span class="text-[--text-main]/40"><?= date('h:i A', $check_out_ts) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-[9px] font-bold text-[--text-main]/40 uppercase tracking-widest italic"><?= date('M d, Y', $check_in_ts) ?></p>
                                </div>
                            </td>
                            <td class="px-8 py-6 text-right">
                                <?php if ($isTraining): ?>
                                    <span class="px-4 py-1.5 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-[9px] text-emerald-500 font-extrabold uppercase italic tracking-widest">Present</span>
                                <?php else: ?>
                                    <span class="px-4 py-1.5 rounded-full bg-white/5 border border-white/5 text-[9px] text-[--text-main]/30 font-extrabold uppercase italic tracking-widest">Completed</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </main>
</div>
</body>
</html>