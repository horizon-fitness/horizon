<?php
session_start();
require_once '../db.php';

// Security Check
$role = strtolower($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || ($role !== 'staff' && $role !== 'coach')) {
    header("Location: ../login.php");
    exit;
}

$gym_id = $_SESSION['gym_id'];
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

// Fetch Gym Details for branding and configuration
$stmtGym = $pdo->prepare("SELECT * FROM gyms WHERE gym_id = ?");
$stmtGym->execute([$gym_id]);
$gym = $stmtGym->fetch();



$admin_name = $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];
$active_page = "dashboard";

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

// --- FETCH DYNAMIC DASHBOARD DATA ---
// We fetch counts and lists directly from the database to ensure real-time accuracy.
$total_members = 0;
$pending_payments = 0;
$pending_appts = 0;
$recent_payments = [];
$recent_bookings = [];

try {
    // 1. Total Active Members for this specific Gym
    $stmtMembers = $pdo->prepare("SELECT COUNT(*) as total FROM members WHERE gym_id = ? AND member_status = 'Active'");
    $stmtMembers->execute([$gym_id]);
    $total_members = (int)($stmtMembers->fetch()['total'] ?? 0);

    // 2. Pending Financial Transactions requiring verification
    $stmtPendingPayments = $pdo->prepare("SELECT COUNT(*) as total FROM payments WHERE gym_id = ? AND payment_status = 'Pending'");
    $stmtPendingPayments->execute([$gym_id]);
    $pending_payments = (int)($stmtPendingPayments->fetch()['total'] ?? 0);

    // 3. Pending Session/Class Bookings for this gym
    $stmtPendingAppts = $pdo->prepare("SELECT COUNT(*) as total FROM bookings WHERE gym_id = ? AND booking_status = 'Pending'");
    $stmtPendingAppts->execute([$gym_id]);
    $pending_appts = (int)($stmtPendingAppts->fetch()['total'] ?? 0);

    // 4. Fetch the 5 most recent payments joined with user identity
    $stmtRecentPayments = $pdo->prepare("
        SELECT p.*, u.first_name, u.last_name, u.username 
        FROM payments p 
        JOIN members m ON p.member_id = m.member_id
        JOIN users u ON m.user_id = u.user_id 
        WHERE p.gym_id = ?
        ORDER BY p.created_at DESC 
        LIMIT 5
    ");
    $stmtRecentPayments->execute([$gym_id]);
    $recent_payments = $stmtRecentPayments->fetchAll();

    // 5. Fetch the 5 most recent bookings joined with user identity
    $stmtRecentBookings = $pdo->prepare("
        SELECT b.*, u.first_name, u.last_name, u.username 
        FROM bookings b 
        JOIN members m ON b.member_id = m.member_id
        JOIN users u ON m.user_id = u.user_id 
        WHERE b.gym_id = ?
        ORDER BY b.created_at DESC 
        LIMIT 5
    ");
    $stmtRecentBookings->execute([$gym_id]);
    $recent_bookings = $stmtRecentBookings->fetchAll();

} catch (Exception $e) {
    error_log("Dashboard Data Retrieval Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Staff Dashboard | Horizon Partners</title>
    
    <!-- Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css2?family=<?= urlencode($font_family) ?>:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    
    <!-- TailWind CSS Configuration -->
    <script src="https://cdn.tailwindcss.com"></script>
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
    </style>
    
    <script>
        /**
         * Real-time Clock Implementation
         * Updates the header clock element every second to match current system time.
         */
        function updateHeaderClock() {
            const now = new Date();
            const clockEl = document.getElementById('headerClock');
            if (clockEl) {
                clockEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            }
        }
        setInterval(updateHeaderClock, 1000);
        window.addEventListener('DOMContentLoaded', updateHeaderClock);
    </script>
</head>
<body class="antialiased flex h-screen overflow-hidden">

<!-- Dynamic Admin Sidebar -->
<?php include '../includes/admin_sidebar.php'; ?>

<!-- Main Page Content Area -->
<div class="main-content flex-1 overflow-y-auto no-scrollbar">
    <main class="p-10 max-w-[1400px] mx-auto">
        
        <!-- Welcome Header with Dynamic Date Color Fix -->
        <header class="mb-12 flex flex-row justify-between items-end gap-6">
            <div>
                <h2 class="text-3xl font-black italic uppercase tracking-tighter leading-none tracking-tight text-white"><span class="opacity-40">Staff</span> <span class="text-primary">Dashboard</span></h2>
                <p class="text-[--text-main]/60 text-[10px] font-bold uppercase tracking-widest mt-2 px-1 opacity-60">Welcome Back, <?= htmlspecialchars($admin_name ?? 'Admin') ?> • Site Control Center</p>
            </div>
            <div class="flex items-end gap-8 text-right shrink-0">
                <div class="flex flex-col items-end">
                    <p id="headerClock" class="text-[--text-main] font-black italic text-2xl leading-none tracking-tighter uppercase">00:00:00 AM</p>
                    <p class="text-primary text-[10px] font-black uppercase tracking-[0.2em] leading-none mt-2"><?= date('l, M d, Y') ?></p>
                </div>
            </div>
        </header>

        <!-- Dynamic Stat Overview Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-12">
            <!-- Active Members Card -->
            <a href="admin_users.php" class="glass-card p-8 status-card-green relative overflow-hidden group hover:scale-[1.02] block transition-all shadow-lg hover:shadow-primary/10">
                <span class="material-symbols-rounded absolute right-8 top-1/2 -translate-y-1/2 text-7xl opacity-5 group-hover:scale-110 transition-transform text-emerald-500">groups</span>
                <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">Active Members</p>
                <h3 class="text-2xl font-black italic uppercase italic text-emerald-400"><?= number_format($total_members) ?></h3>
                <p class="text-emerald-500 text-[10px] font-black uppercase mt-2 tracking-tight">Active Client List</p>
            </a>

            <!-- Pending Payments Card -->
            <a href="admin_transaction.php" class="glass-card p-8 status-card-red relative overflow-hidden group hover:scale-[1.02] block transition-all shadow-lg hover:shadow-red-500/10">
                <span class="material-symbols-rounded absolute right-8 top-1/2 -translate-y-1/2 text-7xl opacity-5 group-hover:scale-110 transition-transform text-red-500">payments</span>
                <p class="text-[10px] font-black uppercase text-red-500/70 mb-2 tracking-widest">Pending Payments</p>
                <h3 class="text-2xl font-black italic uppercase text-red-400"><?= $pending_payments ?> <span class="text-red-500 text-sm ml-1 <?= $pending_payments > 0 ? 'alert-pulse' : '' ?>">!</span></h3>
                <p class="text-red-500 text-[10px] font-black uppercase mt-2 tracking-tight">Action Required</p>
            </a>

            <!-- Pending Bookings Card -->
            <a href="admin_appointment.php" class="glass-card p-8 status-card-yellow relative overflow-hidden group hover:scale-[1.02] block transition-all shadow-lg hover:shadow-amber-500/10">
                <span class="material-symbols-rounded absolute right-8 top-1/2 -translate-y-1/2 text-7xl opacity-5 group-hover:scale-110 transition-transform text-amber-500">event_note</span>
                <p class="text-[10px] font-black uppercase text-amber-500/70 mb-2 tracking-widest">Pending Sessions</p>
                <h3 class="text-2xl font-black italic uppercase text-amber-400"><?= $pending_appts ?></h3>
                <p class="text-amber-500 text-[10px] font-black uppercase mt-2 tracking-tight">Scheduled Classes</p>
            </a>
        </div>

        <!-- Dashboard Table Filter Bar UI (Superadmin Look) -->
        <div class="mb-8 flex flex-wrap items-center gap-4 bg-white/[0.02] border border-white/5 p-4 rounded-2xl">
            <div class="flex-1 min-w-[200px] relative group">
                <span class="material-symbols-rounded absolute left-4 top-1/2 -translate-y-1/2 text-sm text-[--text-main]/40 group-focus-within:text-primary transition-colors">search</span>
                <input type="text" placeholder="Quick Search Staff Table Data..." class="w-full bg-white/5 border border-white/10 rounded-xl py-3 pl-12 pr-4 text-[10px] font-bold uppercase tracking-widest outline-none focus:border-primary/50 transition-all text-[--text-main]">
            </div>
            <div class="flex gap-2">
                <button class="size-10 rounded-xl bg-white/5 border border-white/10 flex items-center justify-center text-[--text-main]/40 hover:text-primary hover:bg-white/10 transition-all shadow-lg" title="Reset Filters">
                    <span class="material-symbols-rounded text-sm">refresh</span>
                </button>
            </div>
        </div>

        <!-- Recent Data Activity Section -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-10 pb-10">
            <!-- Recent Transactions Data Grid -->
            <div class="glass-card flex flex-col overflow-hidden">
                <div class="px-8 py-6 border-b border-white/5 flex justify-between items-center bg-white/[0.01]">
                    <h4 class="text-sm font-black italic uppercase tracking-tighter flex items-center gap-2 text-[--text-main]">
                        <span class="material-symbols-rounded text-[--highlight] text-lg">payments</span> Financial Records
                    </h4>
                    <a href="admin_transaction.php" class="text-[9px] font-black uppercase tracking-widest text-[--text-main]/60 hover:text-primary transition-colors">View All Archive</a>
                </div>
                <div class="p-0">
                    <table class="w-full text-left order-collapse">
                        <thead>
                            <tr class="bg-white/[0.01] text-[9px] font-black uppercase tracking-widest text-[--text-main]/40 border-b border-white/5">
                                <th class="px-8 py-5">Full Member Name</th>
                                <th class="px-8 py-5 text-center">Amount</th>
                                <th class="px-8 py-5 text-right">Processed At</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <?php if (empty($recent_payments)): ?>
                                <tr><td colspan="3" class="px-8 py-14 text-center text-[10px] font-black uppercase tracking-widest text-[--text-main]/20 italic">No recent financial logs found</td></tr>
                            <?php else: foreach ($recent_payments as $pay): ?>
                                <tr class="hover:bg-white/[0.01] group transition-colors">
                                    <td class="px-8 py-5 border-l-2 border-transparent hover:border-primary transition-all">
                                        <p class="text-[11px] font-bold text-[--text-main] uppercase group-hover:text-primary transition-colors italic"><?= htmlspecialchars($pay['first_name'] . ' ' . $pay['last_name']) ?></p>
                                        <p class="text-[8px] text-[--text-main]/40 font-black tracking-widest mt-0.5">@<?= htmlspecialchars($pay['username']) ?></p>
                                    </td>
                                    <td class="px-8 py-5 text-center">
                                        <span class="text-[10px] font-black italic text-[--text-main]">₱<?= number_format($pay['amount'], 2) ?></span>
                                    </td>
                                    <td class="px-8 py-5 text-right font-mono">
                                        <p class="text-[9px] font-black uppercase italic text-[--text-main]/60 tracking-tighter leading-none"><?= date('M d, Y', strtotime($pay['created_at'])) ?></p>
                                        <p class="text-[8px] font-bold text-[--text-main]/20 uppercase tracking-widest mt-1"><?= date('h:i A', strtotime($pay['created_at'])) ?></p>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Recent Bookings Data Grid -->
            <div class="glass-card flex flex-col overflow-hidden shadow-2xl">
                <div class="px-8 py-6 border-b border-white/5 flex justify-between items-center bg-white/[0.01]">
                    <h4 class="text-sm font-black italic uppercase tracking-tighter flex items-center gap-2 text-[--text-main]">
                        <span class="material-symbols-rounded text-[--highlight] text-lg">event_available</span> Session Schedule
                    </h4>
                    <a href="admin_appointment.php" class="text-[9px] font-black uppercase tracking-widest text-[--text-main]/60 hover:text-primary transition-colors">Manage Full List</a>
                </div>
                <div class="p-0">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="bg-white/[0.01] text-[9px] font-black uppercase tracking-widest text-[--text-main]/40 border-b border-white/5">
                                <th class="px-8 py-5">Member Account</th>
                                <th class="px-8 py-5 text-center">Reference</th>
                                <th class="px-8 py-5 text-right">Booking Time</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <?php if (empty($recent_bookings)): ?>
                                <tr><td colspan="3" class="px-8 py-14 text-center text-[10px] font-black uppercase tracking-widest text-[--text-main]/20 italic">No recent bookings registered</td></tr>
                            <?php else: foreach ($recent_bookings as $book): ?>
                                <tr class="hover:bg-white/[0.01] group transition-colors">
                                    <td class="px-8 py-5 border-l-2 border-transparent hover:border-primary transition-all">
                                        <p class="text-[11px] font-bold text-[--text-main] uppercase group-hover:text-primary transition-colors italic"><?= htmlspecialchars($book['first_name'] . ' ' . $book['last_name']) ?></p>
                                        <p class="text-[8px] text-[--text-main]/40 font-black tracking-widest mt-0.5">#<?= htmlspecialchars($book['booking_id']) ?></p>
                                    </td>
                                    <td class="px-8 py-5 text-center">
                                        <p class="text-[10px] font-black italic text-[--text-main]/80 uppercase"><?= htmlspecialchars($book['booking_status']) ?></p>
                                        <p class="text-[8px] font-bold text-[--text-main]/40 uppercase tracking-widest"><?= date('M d', strtotime($book['booking_date'])) ?></p>
                                    </td>
                                    <td class="px-8 py-5 text-right font-mono">
                                        <span class="text-[9px] font-black text-[--text-main] group-hover:text-primary transition-colors italic underline decoration-primary/20"><?= date('h:i A', strtotime($book['start_time'])) ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</div>
</body>
</html>