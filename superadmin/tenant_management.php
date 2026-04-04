<?php
session_start();
require_once '../db.php';

// Security Check: Only Superadmin can access
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'superadmin') {
    header("Location: ../login.php");
    exit;
}

$page_title = "Tenant Management";
$active_page = "tenants";

// 4-Color Elite Branding System: Fetching & Merging Settings
// Hex to RGB helper for dynamic transparency
if (!function_exists('hexToRgb')) {
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

// 1. Fetch Global Settings (user_id = 0)
$stmtGlobal = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE user_id = 0");
$global_configs = $stmtGlobal->fetchAll(PDO::FETCH_KEY_PAIR);

// 2. Fetch User-Specific Settings (Personal Branding)
$stmtUser = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = ?");
$stmtUser->execute([$_SESSION['user_id']]);
$user_configs = $stmtUser->fetchAll(PDO::FETCH_KEY_PAIR);

// 3. Merge (User settings take precedence for overlapping keys)
$brand = array_merge($global_configs, $user_configs);

$stmtTenants = $pdo->query("
    SELECT g.*, 
           u.first_name, u.last_name, u.email as owner_email,
           tp.logo_path,
           cs.subscription_status as sub_status,
           wp.plan_name,
           IFNULL(m.member_count, 0) as member_count
    FROM gyms g
    JOIN users u ON g.owner_user_id = u.user_id
    LEFT JOIN tenant_pages tp ON g.gym_id = tp.gym_id
    LEFT JOIN (
        SELECT cs1.gym_id, cs1.subscription_status, cs1.website_plan_id
        FROM client_subscriptions cs1
        INNER JOIN (
            SELECT gym_id, MAX(created_at) as max_created
            FROM client_subscriptions
            GROUP BY gym_id
        ) cs2 ON cs1.gym_id = cs2.gym_id AND cs1.created_at = cs2.max_created
    ) cs ON g.gym_id = cs.gym_id
    LEFT JOIN website_plans wp ON cs.website_plan_id = wp.website_plan_id
    LEFT JOIN (
        SELECT gym_id, COUNT(*) as member_count 
        FROM members 
        GROUP BY gym_id
    ) m ON g.gym_id = m.gym_id
    WHERE g.status IN ('Active', 'Suspended', 'Deleted', 'Deactivated')
    ORDER BY g.created_at DESC
");
$tenants = $stmtTenants->fetchAll(PDO::FETCH_ASSOC);

// Fetch Total Members across all tenants
$stmtTotalMembers = $pdo->query("SELECT COUNT(*) FROM members");
$total_members_count = $stmtTotalMembers->fetchColumn();

// Fetch Pending Applications
$stmtPending = $pdo->query("
    SELECT a.*, u.first_name, u.last_name, u.email,
           ad.file_path as gym_logo
    FROM gym_owner_applications a 
    JOIN users u ON a.user_id = u.user_id 
    LEFT JOIN application_documents ad ON a.application_id = ad.application_id AND ad.document_type = 'Gym Logo'
    WHERE a.application_status = 'Pending'
    ORDER BY a.submitted_at DESC
");
$pending_apps = $stmtPending->fetchAll(PDO::FETCH_ASSOC);

$rejected_stmt = $pdo->query("
    SELECT a.*, u.first_name, u.last_name, u.email,
           ad.file_path as gym_logo
    FROM gym_owner_applications a 
    JOIN users u ON a.user_id = u.user_id 
    LEFT JOIN application_documents ad ON a.application_id = ad.application_id AND ad.document_type = 'Gym Logo'
    WHERE a.application_status = 'Rejected'
    ORDER BY a.reviewed_at DESC
");
$rejected_apps = $rejected_stmt->fetchAll(PDO::FETCH_ASSOC);

$total_tenants = count($tenants);
$active_count = 0;
$suspended_count = 0;
$pending_count = count($pending_apps);
$rejected_count = count($rejected_apps);
$active_tenants = [];
$deactivated_tenants = [];
foreach ($tenants as $t) {
    if ($t['status'] === 'Active') {
        $active_count++;
        $active_tenants[] = $t;
    } elseif ($t['status'] === 'Suspended') {
        $suspended_count++;
        $active_tenants[] = $t;
    } elseif ($t['status'] === 'Deactivated' || $t['status'] === 'Deleted') {
        $deactivated_tenants[] = $t;
    }
}
$deactivated_count = count($deactivated_tenants);
?>
<!DOCTYPE html>
<html class="dark no-scrollbar" lang="en">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title><?= $page_title ?> | Horizon System</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap"
        rel="stylesheet" />
    <link
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200"
        rel="stylesheet" />
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
            --primary:
                <?= $brand['theme_color'] ?? '#8c2bee' ?>
            ;
            --primary-rgb:
                <?= hexToRgb($brand['theme_color'] ?? '#8c2bee') ?>
            ;
            --highlight:
                <?= $brand['secondary_color'] ?? '#a1a1aa' ?>
            ;
            --text-main:
                <?= $brand['text_color'] ?? '#d1d5db' ?>
            ;
            --background:
                <?= $brand['bg_color'] ?? '#0a090d' ?>
            ;

            /* Glassmorphism Engine */
            --card-blur: 20px;
            --card-bg:
                <?= ($brand['auto_card_theme'] ?? '1') === '1' ? 'rgba(' . hexToRgb($brand['theme_color'] ?? '#8c2bee') . ', 0.05)' : ($brand['card_color'] ?? '#141216') ?>
            ;
        }

        body {
            font-family: '<?= $brand['font_family'] ?? 'Lexend' ?>', sans-serif;
            background-color: var(--background);
            color: var(--text-main);
        }

        .glass-card {
            background: var(--card-bg);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 24px;
            backdrop-filter: blur(20px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            transition: all 0.3s ease;
        }

        .sidebar-nav {
            width: var(--sidebar-width);
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            background: var(--background);
            border-right: 1px solid rgba(255, 255, 255, 0.05);
            z-index: 250;
        }

        /* Unified Sidebar/Modal Width Variable Scoping */
        :root {
            --sidebar-width: 110px;
        }

        .sidebar-nav:hover {
            --sidebar-width: 300px;
        }

        .sidebar-nav:hover~#applicationModal {
            --sidebar-width: 300px;
        }

        #applicationModal {
            left: var(--sidebar-width) !important;
            transition: left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            /* Prevent blur bleed */
        }

        #modalBackdrop {
            position: absolute;
            inset: 0;
            z-index: -1;
        }

        #applicationModal {
            padding-left: 5rem;
            /* Significant breathing room (80px) from the nav */
            padding-right: 5rem;
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
        .sidebar-nav:hover .nav-section-header.mt-4 {
            margin-top: 0.25rem !important;
        }

        .sidebar-nav:hover .nav-section-header.mt-6 {
            margin-top: 0.5rem !important;
        }

        .sidebar-content {
            gap: 2px;
            /* Much search tighter base gap */
            transition: all 0.3s ease-in-out;
            padding-bottom: 8rem;
            /* Increased to allow easier scrolling */
        }

        .sidebar-nav:hover .sidebar-content {
            gap: 4px;
            /* Slightly more space on hover for readability */
        }

        /* End Sidebar Hover Logic */

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

        .active-nav {
            color: var(--primary) !important;
            position: relative;
        }

        .active-nav span.material-symbols-outlined {
            color: var(--primary) !important;
            opacity: 1 !important;
        }

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
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .sidebar-nav:hover .active-nav::after {
            opacity: 1;
        }

        @media (max-width: 1023px) {
            .active-nav::after {
                display: none;
            }
        }

        .alert-pulse {
            animation: alert-pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }

        @keyframes alert-pulse {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: .5;
            }
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

        .dashed-container {
            border: 2px dashed rgba(255, 255, 255, 0.1);
            border-radius: 24px;
        }

        .no-scrollbar::-webkit-scrollbar,
        *::-webkit-scrollbar {
            display: none;
        }

        .no-scrollbar,
        * {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        /* Sidebar-Aware Modal Logic */
        #global-image-viewer.active {
        display: flex;
        opacity: 1;
        pointer-events: auto !important;
    }
        #applicationModal {
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            left: 110px;
            z-index: 200;
            transition: left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        #applicationModal.flex-important {
            display: flex !important;
        }

        .sidebar-nav:hover~#applicationModal {
            left: 300px;
        }

        .sidebar-nav:hover~#global-image-viewer {
            --sidebar-width: 300px;
        }

        @media (max-width: 1023px) {
            #applicationModal {
                left: 0 !important;
            }
        }
    </style>
    <script>
        function updateHeaderClock() {
            const now = new Date();
            const clockEl = document.getElementById('headerClock');
            if (clockEl) {
                clockEl.textContent = now.toLocaleTimeString('en-US', {
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit'
                });
            }
        }
        setInterval(updateHeaderClock, 1000);
        window.addEventListener('DOMContentLoaded', updateHeaderClock);
    </script>
</head>

<body class="antialiased flex flex-row min-h-screen no-scrollbar">

    <nav class="sidebar-nav h-screen sticky top-0 z-50 shrink-0 flex flex-col no-scrollbar">
        <div class="px-7 py-5 mb-2 shrink-0">
            <div class="flex items-center gap-4">
                <div class="size-10 rounded-xl flex items-center justify-center shadow-lg shrink-0 overflow-hidden">
                    <?php if (!empty($brand['system_logo'])): ?>
                        <img src="<?= htmlspecialchars($brand['system_logo']) ?>" id="sidebarLogoPreview"
                            class="size-full object-contain rounded-xl">
                    <?php else: ?>
                        <img src="../assests/horizon logo.png" id="sidebarLogoPreview"
                            class="size-full object-contain rounded-xl transition-transform duration-500 hover:scale-110"
                            alt="Horizon Logo">
                    <?php endif; ?>
                </div>
                <h1 class="nav-text text-lg font-black italic uppercase tracking-tighter text-white">
                    <?= htmlspecialchars($brand['system_name'] ?? 'Horizon System') ?>
                </h1>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto sidebar-scroll-container no-scrollbar space-y-1 pb-4">
            <div class="nav-section-header px-7 mb-2">
                <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Overview</span>
            </div>
            <a href="superadmin_dashboard.php"
                class="nav-link <?= ($active_page == 'dashboard') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">grid_view</span>
                <span class="nav-text">Dashboard</span>
            </a>

            <div class="nav-section-header px-7 mb-2 mt-4">
                <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Management</span>
            </div>
            <a href="tenant_management.php" class="nav-link <?= ($active_page == 'tenants') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">business</span>
                <span class="nav-text">Tenant Management</span>
            </a>

            <a href="subscription_logs.php"
                class="nav-link <?= ($active_page == 'subscriptions') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">history_edu</span>
                <span class="nav-text">Subscription Logs</span>
            </a>

            <a href="real_time_occupancy.php" class="nav-link <?= ($active_page == 'occupancy') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">group</span>
                <span class="nav-text">Real-Time Occupancy</span>
            </a>

            <a href="recent_transaction.php"
                class="nav-link <?= ($active_page == 'transactions') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">receipt_long</span>
                <span class="nav-text">Recent Transactions</span>
            </a>

            <div class="nav-section-header px-7 mb-2 mt-4">
                <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">System</span>
            </div>
            <a href="system_alerts.php" class="nav-link <?= ($active_page == 'alerts') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">notifications_active</span>
                <span class="nav-text">System Alerts</span>
            </a>

            <a href="system_reports.php" class="nav-link <?= ($active_page == 'reports') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">analytics</span>
                <span class="nav-text">Reports</span>
            </a>

            <a href="sales_report.php" class="nav-link <?= ($active_page == 'sales_report') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">monitoring</span>
                <span class="nav-text">Sales Reports</span>
            </a>

            <a href="audit_logs.php" class="nav-link <?= ($active_page == 'audit_logs') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">assignment</span>
                <span class="nav-text">Audit Logs</span>
            </a>

            <a href="backup.php" class="nav-link <?= ($active_page == 'backup') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">backup</span>
                <span class="nav-text">Backup</span>
            </a>
        </div>

        <div class="mt-auto pt-4 border-t border-white/10 flex flex-col gap-1 shrink-0 pb-6">
            <div class="nav-section-header px-7 mb-0">
                <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Account</span>
            </div>
            <a href="settings.php" class="nav-link <?= ($active_page == 'settings') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">settings</span>
                <span class="nav-text">Settings</span>
            </a>
            <a href="profile.php" class="nav-link <?= ($active_page == 'profile') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">person</span>
                <span class="nav-text">Profile</span>
            </a>
            <a href="../logout.php" class="nav-link !text-gray-400 hover:!text-rose-500 transition-colors group">
                <span
                    class="material-symbols-outlined group-hover:translate-x-1 transition-transform text-xl shrink-0 group-hover:!text-rose-500">logout</span>
                <span class="nav-text group-hover:!text-rose-500">Sign Out</span>
            </a>
        </div>
    </nav>

    <div class="flex-1 flex flex-col min-w-0 overflow-y-auto no-scrollbar">
        <main class="flex-1 p-6 md:p-10 max-w-[1400px] w-full mx-auto">

            <header class="mb-10 flex flex-row justify-between items-end gap-6">
                <div>
                    <h2 class="text-3xl font-black italic uppercase tracking-tighter leading-none">
                        <span class="text-[--text-main] opacity-80">Tenant</span>
                        <span class="text-primary">Management</span>
                    </h2>
                    <p class="text-[--text-main] opacity-60 text-xs font-bold uppercase tracking-widest mt-2 px-1">
                        Manage Gym Accounts & Subscriptions
                    </p>
                </div>

                <div class="text-right">
                    <p id="headerClock"
                        class="text-[--text-main] font-black italic text-2xl leading-none transition-colors hover:text-primary uppercase tracking-tighter mb-2">
                        00:00:00 AM</p>
                    <p class="text-primary text-[10px] font-black uppercase tracking-[0.2em] leading-none">
                        <?= date('l, M d, Y') ?></p>
                </div>
            </header>

            <?php if (isset($_SESSION['success_msg'])): ?>
                <div
                    class="mb-8 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-sm font-semibold flex items-center gap-3">
                    <span class="material-symbols-outlined">check_circle</span>
                    <?= htmlspecialchars($_SESSION['success_msg']) ?>
                </div>
                <?php unset($_SESSION['success_msg']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_msg'])): ?>
                <div
                    class="mb-8 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-sm font-semibold flex items-center gap-3">
                    <span class="material-symbols-outlined">error</span>
                    <?= htmlspecialchars($_SESSION['error_msg']) ?>
                </div>
                <?php unset($_SESSION['error_msg']); ?>
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 mb-10">
                <div class="glass-card p-8 relative overflow-hidden group border border-white/5 bg-white/5">
                    <span
                        class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform">domain</span>
                    <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">Total
                        Tenants</p>
                    <h3 class="text-2xl font-black italic uppercase"><?= $total_tenants ?></h3>
                    <p class="text-[--text-main] opacity-40 text-[9px] font-black uppercase mt-2 tracking-tighter">Gym
                        Directory</p>
                </div>

                <div class="glass-card p-8 status-card-green relative overflow-hidden group">
                    <span
                        class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 text-emerald-500 group-hover:scale-110 transition-transform">check_circle</span>
                    <p class="text-[10px] font-black uppercase text-emerald-500/70 mb-2 tracking-widest">Active Gyms</p>
                    <h3 class="text-2xl font-black italic uppercase text-emerald-400"><?= $active_count ?></h3>
                    <p class="text-emerald-500/50 text-[9px] font-black uppercase mt-2 tracking-tighter italic">Live
                        Accounts</p>
                </div>

                <div class="glass-card p-8 relative overflow-hidden group border-primary/20 bg-primary/5">
                    <span
                        class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 text-primary group-hover:scale-110 transition-transform">groups</span>
                    <p class="text-[10px] font-black uppercase text-primary/70 mb-2 tracking-widest">Total Members</p>
                    <h3 class="text-2xl font-black italic uppercase text-primary">
                        <?= number_format($total_members_count) ?></h3>
                    <p class="text-primary/50 text-[9px] font-black uppercase mt-2 tracking-tighter italic">Platform
                        Global</p>
                </div>

                <div class="glass-card p-8 status-card-yellow relative overflow-hidden group">
                    <span
                        class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 text-amber-500 group-hover:scale-110 transition-transform">pending_actions</span>
                    <p class="text-[10px] font-black uppercase text-amber-500/70 mb-2 tracking-widest">Pending Apps</p>
                    <h3 class="text-2xl font-black italic uppercase text-amber-400"><?= $pending_count ?></h3>
                    <p class="text-amber-500/50 text-[9px] font-black uppercase mt-2 tracking-tighter italic">Review
                        Required</p>
                </div>

                <div class="glass-card p-8 status-card-red relative overflow-hidden group">
                    <span
                        class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 text-red-500 group-hover:scale-110 transition-transform">pause_circle</span>
                    <p class="text-[10px] font-black uppercase text-red-500/70 mb-2 tracking-widest">Suspended</p>
                    <h3 class="text-2xl font-black italic uppercase text-red-400"><?= $suspended_count ?></h3>
                    <p class="text-red-500/50 text-[9px] font-black uppercase mt-2 tracking-tighter italic">On-Hold
                        Status</p>
                </div>
            </div>

            <div class="flex items-center gap-8 mb-8 border-b border-white/5 px-2">
                <button onclick="switchTab('registered')" id="tabBtn-registered"
                    class="pb-4 text-xs font-black uppercase tracking-widest transition-all relative group text-primary">
                    Active & Suspended
                    <div id="tabIndicator-registered"
                        class="absolute bottom-0 left-0 right-0 h-0.5 bg-primary rounded-full transition-all opacity-100">
                    </div>
                </button>
                <button onclick="switchTab('pending')" id="tabBtn-pending"
                    class="pb-4 text-xs font-black uppercase tracking-widest transition-all relative group text-[--text-main] opacity-50 hover:opacity-100 <?= ($pending_count > 0) ? 'mr-4' : '' ?>">
                    Pending Apps
                    <div id="tabIndicator-pending"
                        class="absolute bottom-0 left-0 right-0 h-0.5 bg-primary rounded-full transition-all opacity-0">
                    </div>
                    <?php if ($pending_count > 0): ?>
                        <span
                            class="absolute -top-1 -right-6 size-4 bg-amber-500 text-[8px] font-black text-white flex items-center justify-center rounded-full shadow-lg shadow-amber-500/20"><?= $pending_count ?></span>
                    <?php endif; ?>
                </button>
                <button onclick="switchTab('deactivated')" id="tabBtn-deactivated"
                    class="pb-4 text-xs font-black uppercase tracking-widest transition-all relative group text-[--text-main] opacity-50 hover:opacity-100 <?= ($deactivated_count > 0) ? 'mr-4' : '' ?>">
                    Deactivated
                    <div id="tabIndicator-deactivated"
                        class="absolute bottom-0 left-0 right-0 h-0.5 bg-primary rounded-full transition-all opacity-0">
                    </div>
                    <?php if ($deactivated_count > 0): ?>
                        <span
                            class="absolute -top-1 -right-6 size-4 bg-red-500 text-[8px] font-black text-white flex items-center justify-center rounded-full shadow-lg shadow-red-500/20"><?= $deactivated_count ?></span>
                    <?php endif; ?>
                </button>
                <button onclick="switchTab('rejected')" id="tabBtn-rejected"
                    class="pb-4 text-xs font-black uppercase tracking-widest transition-all relative group text-[--text-main] opacity-50 hover:opacity-100 <?= ($rejected_count > 0) ? 'mr-4' : '' ?>">
                    Rejected History
                    <div id="tabIndicator-rejected"
                        class="absolute bottom-0 left-0 right-0 h-0.5 bg-primary rounded-full transition-all opacity-0">
                    </div>
                    <?php if ($rejected_count > 0): ?>
                        <span
                            class="absolute -top-1 -right-6 size-4 bg-gray-500 text-[8px] font-black text-white flex items-center justify-center rounded-full shadow-lg shadow-gray-500/20"><?= $rejected_count ?></span>
                    <?php endif; ?>
                </button>
            </div>

            <div id="section-pending" class="hidden">
                <?php if (!empty($pending_apps)): ?>
                    <div class="glass-card overflow-hidden mb-10 border border-amber-500/10">
                        <div class="px-8 py-6 border-b border-white/5 bg-amber-500/5 flex justify-between items-center">
                            <h4
                                class="font-black italic uppercase text-sm tracking-tighter text-amber-400 flex items-center gap-2">
                                <span class="material-symbols-outlined">pending_actions</span>
                                Pending Gym Applications
                            </h4>
                        </div>
                        <div class="overflow-x-auto no-scrollbar">
                            <table class="w-full text-left">
                                <thead>
                                    <tr
                                        class="bg-background/50 text-[--text-main] opacity-50 text-[10px] font-black uppercase tracking-widest">
                                        <th class="px-8 py-4">Gym Name</th>
                                        <th class="px-8 py-4">Applicant</th>
                                        <th class="px-8 py-4">Applied Date</th>
                                        <th class="px-8 py-4 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="pendingTableBody" class="divide-y divide-white/5">
                                    <?php foreach ($pending_apps as $app): ?>
                                        <tr class="hover:bg-white/5 transition-all">
                                            <td class="px-8 py-5">
                                                <div class="flex items-center gap-3">
                                                    <div
                                                        class="size-10 rounded-lg bg-amber-500/10 flex items-center justify-center overflow-hidden border border-amber-500/20 shadow-inner shrink-0">
                                                        <?php if (!empty($app['gym_logo'])): ?>
                                                            <img src="<?= $app['gym_logo'] ?>"
                                                                class="size-full object-contain transition-transform hover:scale-110">
                                                        <?php else: ?>
                                                            <span
                                                                class="text-amber-500 font-black text-sm"><?= strtoupper(substr($app['gym_name'], 0, 2)) ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div>
                                                        <p class="text-sm font-bold italic">
                                                            <?= htmlspecialchars($app['gym_name']) ?></p>
                                                        <p
                                                            class="text-[10px] text-[--text-main] opacity-50 uppercase tracking-wider font-bold">
                                                            <?= htmlspecialchars(str_replace('_', ' ', $app['business_type'] ?? '')) ?>
                                                        </p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-8 py-5">
                                                <p class="text-xs font-medium text-white">
                                                    <?= htmlspecialchars($app['first_name'] . ' ' . $app['last_name']) ?></p>
                                                <p class="text-[10px] text-[--text-main] opacity-50">
                                                    <?= htmlspecialchars($app['email']) ?></p>
                                            </td>
                                            <td class="px-8 py-5 text-xs font-medium text-gray-400">
                                                <?= date('M d, Y h:i A', strtotime($app['submitted_at'])) ?>
                                            </td>
                                            <td class="px-8 py-5 text-right">
                                                <div class="inline-flex gap-2">
                                                    <button onclick="openApplicationModal(<?= $app['application_id'] ?>)"
                                                        class="px-4 py-1.5 rounded-lg bg-white/5 hover:bg-white/10 border border-white/5 text-[--text-main] opacity-40 hover:opacity-100 text-[10px] font-black uppercase tracking-widest transition-colors flex items-center gap-1">
                                                        <span class="material-symbols-outlined text-sm">visibility</span> View
                                                    </button>
                                                    <form method="POST" action="../action/process_application.php"
                                                        class="inline-flex gap-2">
                                                        <input type="hidden" name="application_id"
                                                            value="<?= $app['application_id'] ?>">
                                                        <input type="hidden" name="action" value="">
                                                        <button type="button"
                                                            onclick="confirmAction(this.form, 'approve', 'Approve Application', 'Are you sure you want to approve the application for <?= addslashes($app['gym_name']) ?>? This will create a new tenant account.')"
                                                            class="px-4 py-1.5 rounded-lg bg-emerald-500 hover:bg-emerald-600 text-white text-[10px] font-black uppercase tracking-widest transition-colors flex items-center gap-1 shadow-lg shadow-emerald-500/10">
                                                            <span class="material-symbols-outlined text-sm">check_circle</span>
                                                            Approve
                                                        </button>
                                                        <button type="button"
                                                            onclick="confirmAction(this.form, 'reject', 'Reject Application', 'Are you sure you want to reject the application for <?= addslashes($app['gym_name']) ?>? This action cannot be undone.')"
                                                            class="px-4 py-1.5 rounded-lg bg-red-500/10 hover:bg-red-500/20 border border-red-500/20 text-red-400 text-[10px] font-black uppercase tracking-widest transition-colors flex items-center gap-1">
                                                            <span class="material-symbols-outlined text-sm">cancel</span> Reject
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <!-- Pagination Container for Pending -->
                    <div id="pagination-pending" class="px-8 py-4 border-t border-white/5 bg-white/[0.02] flex justify-between items-center hidden">
                        <p class="text-[10px] font-black uppercase text-[--text-main] opacity-40 tracking-widest status-text"></p>
                        <div class="flex gap-2 controls-container"></div>
                    </div>
                <?php else: ?>
                    <div class="glass-card p-12 text-center border border-white/5 bg-white/5 rounded-[32px]">
                        <span
                            class="material-symbols-outlined text-4xl text-[--text-main] opacity-60 mb-4 uppercase">check_circle</span>
                        <p class="text-xs font-black uppercase text-[--text-main] opacity-50 tracking-widest">All caught up!
                            No pending applications.</p>
                    </div>
                <?php endif; ?>
            </div>

            <div id="section-registered">
                <div class="glass-card overflow-hidden">
                    <div class="px-8 py-6 border-b border-white/5 bg-white/5 flex justify-between items-center">
                        <h4 class="font-black italic uppercase text-sm tracking-tighter">Registered Gyms</h4>
                    </div>
                    <div class="overflow-x-auto no-scrollbar">
                        <table class="w-full text-left">
                            <thead>
                                <tr
                                    class="bg-background/50 text-[--text-main] opacity-50 text-[10px] font-black uppercase tracking-widest">
                                    <th class="px-8 py-4">Gym Identity</th>
                                    <th class="px-8 py-4">Owner Contact</th>
                                    <th class="px-8 py-4">Plan & Status</th>
                                    <th class="px-8 py-4">Members</th>
                                    <th class="px-8 py-4 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="registeredTableBody" class="divide-y divide-white/5">
                                <?php if (empty($active_tenants)): ?>
                                    <tr>
                                        <td colspan="5"
                                            class="px-8 py-8 text-center text-xs font-bold text-[--text-main] opacity-50 italic uppercase">
                                            No active/suspended tenants found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($active_tenants as $t): ?>
                                        <tr class="hover:bg-white/5 transition-all">
                                            <td class="px-8 py-5">
                                                <div class="flex items-center gap-3">
                                                        <?php 
                                                        $logo_src = !empty($t['logo_path']) ? ((strpos($t['logo_path'], 'data:image') === 0) ? $t['logo_path'] : '../' . $t['logo_path']) : '';
                                                        ?>
                                                        <div
                                                        class="size-16 rounded-xl bg-white/5 flex items-center justify-center overflow-hidden border border-white/5 shadow-inner shrink-0 cursor-zoom-in modal-img-preview"
                                                        data-src="<?= $logo_src ?>" data-title="<?= htmlspecialchars($t['gym_name']) ?>">
                                                        <?php if (!empty($t['logo_path'])):
                                                            ?>
                                                            <img src="<?= $logo_src ?>"
                                                                class="size-full object-contain transition-transform hover:scale-110">
                                                        <?php else: ?>
                                                            <span
                                                                class="text-primary font-black text-sm"><?= strtoupper(substr($t['gym_name'], 0, 2)) ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div>
                                                        <p class="text-sm font-bold italic">
                                                            <?= htmlspecialchars($t['gym_name']) ?></p>
                                                        <p class="text-[10px] text-primary uppercase tracking-wider font-bold">
                                                            Code: <?= htmlspecialchars($t['tenant_code']) ?></p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-8 py-5">
                                                <p class="text-xs font-medium text-white">
                                                    <?= htmlspecialchars($t['first_name'] . ' ' . $t['last_name']) ?></p>
                                                <p class="text-[10px] text-[--text-main] opacity-50">
                                                    <?= htmlspecialchars($t['owner_email']) ?></p>
                                            </td>
                                            <td class="px-8 py-5">
                                                <div class="flex flex-col gap-1.5">
                                                    <p
                                                        class="text-[10px] font-black uppercase text-[--text-main] opacity-40 tracking-widest">
                                                        <?= htmlspecialchars($t['plan_name'] ?? 'No Plan') ?></p>
                                                    <?php
                                                    $sub = $t['sub_status'] ?? 'None';
                                                    if ($sub === 'Active'):
                                                        ?>
                                                        <span
                                                            class="w-fit px-3 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-[9px] text-emerald-500 font-black uppercase italic">Active
                                                            Plan</span>
                                                    <?php elseif ($sub === 'Expired' || $sub === 'Overdue'): ?>
                                                        <span
                                                            class="w-fit px-3 py-1 rounded-full bg-red-500/10 border border-red-500/20 text-[9px] text-red-500 font-black uppercase italic">Payment
                                                            Issue</span>
                                                    <?php else: ?>
                                                        <span
                                                            class="w-fit px-3 py-1 rounded-full bg-gray-500/10 border border-gray-500/20 text-[9px] text-gray-400 font-black uppercase italic">No
                                                            Active Subscription</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="px-8 py-5">
                                                <div class="flex flex-col gap-2">
                                                    <div class="flex items-center gap-2">
                                                        <span
                                                            class="material-symbols-outlined text-sm text-primary">groups</span>
                                                        <p class="text-xs font-black italic">
                                                            <?= number_format($t['member_count']) ?></p>
                                                    </div>
                                                    <?php if ($t['status'] === 'Active'): ?>
                                                        <div class="flex items-center gap-2 text-emerald-400">
                                                            <span class="relative flex size-2"><span
                                                                    class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span><span
                                                                    class="relative inline-flex rounded-full size-2 bg-emerald-500"></span></span>
                                                            <span
                                                                class="text-[9px] font-black uppercase italic tracking-wider">Active</span>
                                                        </div>
                                                    <?php elseif ($t['status'] === 'Suspended'): ?>
                                                        <div class="flex items-center gap-2 text-amber-400">
                                                            <span
                                                                class="relative inline-flex rounded-full size-2 bg-amber-500"></span>
                                                            <span
                                                                class="text-[9px] font-black uppercase italic tracking-wider">Suspended</span>
                                                        </div>
                                                    <?php elseif ($t['status'] === 'Deactivated'): ?>
                                                        <div class="flex items-center gap-2 text-red-400">
                                                            <span
                                                                class="relative inline-flex rounded-full size-2 bg-red-500"></span>
                                                            <span
                                                                class="text-[9px] font-black uppercase italic tracking-wider">Deactivated</span>
                                                        </div>
                                                    <?php else: ?>
                                                        <span
                                                            class="text-[9px] font-black uppercase italic text-gray-500 tracking-wider"><?= htmlspecialchars($t['status']) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="px-8 py-5 text-right">
                                                <div class="inline-flex gap-2">
                                                    <?php if ($t['application_id']): ?>
                                                        <button onclick="openApplicationModal(<?= $t['application_id'] ?>)"
                                                            title="View Application Details"
                                                            class="size-8 rounded-lg bg-white/5 hover:bg-white/10 border border-white/5 text-[--text-main] opacity-40 hover:opacity-100 flex items-center justify-center transition-colors">
                                                            <span class="material-symbols-outlined text-[18px]">visibility</span>
                                                        </button>
                                                    <?php endif; ?>

                                                    <form method="POST" action="../action/process_tenant.php"
                                                        class="inline-flex gap-2">
                                                        <input type="hidden" name="gym_id" value="<?= $t['gym_id'] ?>">
                                                        <input type="hidden" name="action" value="">

                                                        <?php if ($t['status'] !== 'Active'): ?>
                                                            <button type="button"
                                                                onclick="confirmAction(this.form, 'activate', 'Activate Gym', 'Are you sure you want to reactivate <?= addslashes($t['gym_name']) ?>?')"
                                                                title="Activate Account"
                                                                class="size-8 rounded-lg bg-emerald-500/10 hover:bg-emerald-500/20 border border-emerald-500/20 text-emerald-400 flex items-center justify-center transition-colors">
                                                                <span
                                                                    class="material-symbols-outlined text-[18px]">play_circle</span>
                                                            </button>
                                                        <?php endif; ?>

                                                        <?php if ($t['status'] === 'Active'): ?>
                                                            <button type="button"
                                                                onclick="confirmAction(this.form, 'suspend', 'Suspend Gym', 'Are you sure you want to suspend <?= addslashes($t['gym_name']) ?>?')"
                                                                title="Suspend Account"
                                                                class="size-8 rounded-lg bg-amber-500/10 hover:bg-amber-500/20 border border-amber-500/20 text-amber-400 flex items-center justify-center transition-colors">
                                                                <span
                                                                    class="material-symbols-outlined text-[18px]">pause_circle</span>
                                                            </button>
                                                        <?php endif; ?>

                                                        <?php if ($t['status'] !== 'Deactivated'): ?>
                                                            <button type="button"
                                                                onclick="confirmAction(this.form, 'deactivate', 'Deactivate Gym', 'Are you sure you want to deactivate <?= addslashes($t['gym_name']) ?>? This will revoke all access.')"
                                                                title="Deactivate Account"
                                                                class="size-8 rounded-lg bg-red-500/10 hover:bg-red-500/20 border border-red-500/20 text-red-400 flex items-center justify-center transition-colors">
                                                                <span class="material-symbols-outlined text-[18px]">cancel</span>
                                                            </button>
                                                        <?php endif; ?>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <!-- Pagination Container for Registered -->
                    <div id="pagination-registered" class="px-8 py-4 border-t border-white/5 bg-white/[0.02] flex justify-between items-center hidden">
                        <p class="text-[10px] font-black uppercase text-[--text-main] opacity-40 tracking-widest status-text"></p>
                        <div class="flex gap-2 controls-container"></div>
                    </div>
                </div>
            </div>

            <div id="section-deactivated" class="hidden">
                <div class="glass-card overflow-hidden">
                    <div class="px-8 py-6 border-b border-white/5 bg-red-500/5 flex justify-between items-center">
                        <h4
                            class="font-black italic uppercase text-sm tracking-tighter text-red-400 flex items-center gap-2">
                            <span class="material-symbols-outlined">cancel</span>
                            Deactivated Gym Accounts
                        </h4>
                    </div>
                    <div class="overflow-x-auto no-scrollbar">
                        <table class="w-full text-left">
                            <thead>
                                <tr
                                    class="bg-background/50 text-[--text-main] opacity-50 text-[10px] font-black uppercase tracking-widest">
                                    <th class="px-8 py-4">Gym Identity</th>
                                    <th class="px-8 py-4">Owner Contact</th>
                                    <th class="px-8 py-4">Status Info</th>
                                    <th class="px-8 py-4 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="deactivatedTableBody" class="divide-y divide-white/5">
                                <?php if (empty($deactivated_tenants)): ?>
                                    <tr>
                                        <td colspan="4"
                                            class="px-8 py-12 text-center text-xs font-bold text-[--text-main] opacity-50 italic uppercase">
                                            No deactivated gyms found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($deactivated_tenants as $t): ?>
                                        <tr class="hover:bg-white/5 transition-all">
                                            <td class="px-8 py-5">
                                                <div class="flex items-center gap-3">
                                                        <?php 
                                                        $logo_src = !empty($t['logo_path']) ? ((strpos($t['logo_path'], 'data:image') === 0) ? $t['logo_path'] : '../' . $t['logo_path']) : '';
                                                        ?>
                                                        <div
                                                        class="size-12 rounded-lg bg-white/5 flex items-center justify-center overflow-hidden border border-white/5 grayscale cursor-zoom-in modal-img-preview"
                                                        data-src="<?= $logo_src ?>" data-title="<?= htmlspecialchars($t['gym_name']) ?>">
                                                        <?php if (!empty($t['logo_path'])):
                                                            ?>
                                                            <img src="<?= $logo_src ?>" class="size-full object-contain opacity-50 transition-transform hover:scale-110">
                                                        <?php else: ?>
                                                            <span
                                                                class="text-[--text-main] opacity-50 font-black text-xs"><?= strtoupper(substr($t['gym_name'], 0, 2)) ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div>
                                                        <p class="text-sm font-bold italic text-[--text-main] opacity-40">
                                                            <?= htmlspecialchars($t['gym_name']) ?></p>
                                                        <p
                                                            class="text-[10px] text-[--text-main] opacity-60 uppercase tracking-wider font-bold">
                                                            <?= htmlspecialchars($t['tenant_code']) ?></p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-8 py-5">
                                                <p class="text-xs font-medium text-[--text-main] opacity-40">
                                                    <?= htmlspecialchars($t['first_name'] . ' ' . $t['last_name']) ?></p>
                                                <p class="text-[10px] text-[--text-main] opacity-60">
                                                    <?= htmlspecialchars($t['owner_email']) ?></p>
                                            </td>
                                            <td class="px-8 py-5">
                                                <div class="flex items-center gap-2 text-red-500/50">
                                                    <span class="material-symbols-outlined text-sm">block</span>
                                                    <span
                                                        class="text-[9px] font-black uppercase italic tracking-wider">Deactivated
                                                        Account</span>
                                                </div>
                                                <p class="text-[9px] text-[--text-main] opacity-60 mt-1 italic leading-tight">
                                                    All login access for this tenant and its staff is revoked.</p>
                                            </td>
                                            <td class="px-8 py-5 text-right">
                                                <form method="POST" action="../action/process_tenant.php">
                                                    <input type="hidden" name="gym_id" value="<?= $t['gym_id'] ?>">
                                                    <input type="hidden" name="action" value="activate">
                                                    <button type="button"
                                                        onclick="confirmAction(this.form, 'activate', 'Restore Gym Account', 'Are you sure you want to restore access for <?= addslashes($t['gym_name']) ?>?')"
                                                        class="px-4 py-2 rounded-lg bg-emerald-500 hover:bg-emerald-600 text-white text-[10px] font-black uppercase tracking-widest transition-all flex items-center justify-center gap-2 ml-auto shadow-lg shadow-emerald-500/10">
                                                        <span class="material-symbols-outlined text-sm">play_circle</span>
                                                        Restore Access
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <!-- Pagination Container for Deactivated -->
                    <div id="pagination-deactivated" class="px-8 py-4 border-t border-white/5 bg-white/[0.02] flex justify-between items-center hidden">
                        <p class="text-[10px] font-black uppercase text-[--text-main] opacity-40 tracking-widest status-text"></p>
                        <div class="flex gap-2 controls-container"></div>
                    </div>
                </div>
            </div>

            <div id="section-rejected" class="hidden">
                <?php if (!empty($rejected_apps)): ?>
                    <div class="glass-card overflow-hidden mb-10 border border-white/5">
                        <div class="px-8 py-6 border-b border-white/5 bg-white/5 flex justify-between items-center">
                            <h4 class="font-black italic uppercase text-sm tracking-tighter opacity-50 flex items-center gap-2">
                                <span class="material-symbols-outlined">history</span>
                                Rejected Applications
                            </h4>
                        </div>
                        <div class="overflow-x-auto no-scrollbar">
                            <table class="w-full text-left">
                                <thead>
                                    <tr class="bg-background/50 text-[--text-main] opacity-50 text-[10px] font-black uppercase tracking-widest">
                                        <th class="px-8 py-4">Gym Name</th>
                                        <th class="px-8 py-4">Applicant</th>
                                        <th class="px-8 py-4">Rejected Date</th>
                                        <th class="px-8 py-4 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="rejectedTableBody" class="divide-y divide-white/5">
                                    <?php foreach ($rejected_apps as $app): ?>
                                        <tr class="hover:bg-white/5 transition-all">
                                            <td class="px-8 py-5">
                                                <div class="flex items-center gap-3">
                                                    <div class="size-10 rounded-lg bg-white/5 flex items-center justify-center overflow-hidden border border-white/5 shadow-inner shrink-0 grayscale opacity-40">
                                                        <?php if (!empty($app['gym_logo'])): ?>
                                                            <img src="<?= $app['gym_logo'] ?>" class="size-full object-contain">
                                                        <?php else: ?>
                                                            <span class="text-gray-500 font-black text-sm"><?= strtoupper(substr($app['gym_name'], 0, 2)) ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div>
                                                        <p class="text-sm font-bold italic text-gray-500"><?= htmlspecialchars($app['gym_name']) ?></p>
                                                        <p class="text-[10px] text-[--text-main] opacity-40 uppercase tracking-wider font-bold"><?= htmlspecialchars(str_replace('_', ' ', $app['business_type'] ?? '')) ?></p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-8 py-5">
                                                <p class="text-xs font-medium text-gray-500"><?= htmlspecialchars($app['first_name'] . ' ' . $app['last_name']) ?></p>
                                                <p class="text-[10px] text-[--text-main] opacity-40"><?= htmlspecialchars($app['email']) ?></p>
                                            </td>
                                            <td class="px-8 py-5 text-xs font-medium text-gray-600 italic">
                                                <?= date('M d, Y', strtotime($app['reviewed_at'])) ?>
                                            </td>
                                            <td class="px-8 py-5 text-right">
                                                <button onclick="openApplicationModal(<?= $app['application_id'] ?>)"
                                                    class="px-4 py-1.5 rounded-lg bg-white/5 hover:bg-white/10 border border-white/5 text-[--text-main] opacity-40 hover:opacity-100 text-[10px] font-black uppercase tracking-widest transition-colors flex items-center gap-1">
                                                    <span class="material-symbols-outlined text-sm">visibility</span> Details
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <!-- Pagination Container for Rejected -->
                    <div id="pagination-rejected" class="px-8 py-4 border-t border-white/5 bg-white/[0.02] flex justify-between items-center hidden">
                        <p class="text-[10px] font-black uppercase text-[--text-main] opacity-40 tracking-widest status-text"></p>
                        <div class="flex gap-2 controls-container"></div>
                    </div>
                <?php else: ?>
                    <div class="glass-card p-12 text-center border border-white/5 bg-white/5 rounded-[32px]">
                        <span class="material-symbols-outlined text-4xl text-[--text-main] opacity-40 mb-4 uppercase">history_toggle_off</span>
                        <p class="text-xs font-black uppercase text-[--text-main] opacity-40 tracking-widest italic">No rejected applications in history.</p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        function switchTab(tabId) {
            const sections = ['pending', 'registered', 'deactivated', 'rejected'];

            sections.forEach(s => {
                const section = document.getElementById(`section-${s}`);
                const btn = document.getElementById(`tabBtn-${s}`);
                const indicator = document.getElementById(`tabIndicator-${s}`);

                if (s === tabId) {
                    section.classList.remove('hidden');
                    btn.classList.add('text-primary');
                    btn.classList.remove('opacity-50');
                    indicator.classList.remove('opacity-0');
                    indicator.classList.add('opacity-100');
                } else {
                    section.classList.add('hidden');
                    btn.classList.add('opacity-50');
                    btn.classList.remove('text-primary');
                    indicator.classList.remove('opacity-100');
                    indicator.classList.add('opacity-0');
                }
            });
        }

        // Auto-switch tab based on URL parameter
        window.addEventListener('DOMContentLoaded', () => {
            const urlParams = new URLSearchParams(window.location.search);
            const tab = urlParams.get('tab');
            if (tab && ['pending', 'registered', 'deactivated', 'rejected'].includes(tab)) {
                switchTab(tab);
            }

            // Initialize Pagination for all tables
            initTablePagination('pendingTableBody', 'pagination-pending', 10);
            initTablePagination('registeredTableBody', 'pagination-registered', 10);
            initTablePagination('deactivatedTableBody', 'pagination-deactivated', 10);
            initTablePagination('rejectedTableBody', 'pagination-rejected', 10);
        });

        /**
         * Horizon Table Pagination Engine
         * Implements a clean 10-row limit per page with smooth transitions
         */
        function initTablePagination(tbodyId, paginationId, rowsPerPage) {
            const tbody = document.getElementById(tbodyId);
            const paginationContainer = document.getElementById(paginationId);
            if (!tbody || !paginationContainer) return;

            const rows = Array.from(tbody.querySelectorAll('tr:not(.no-pagination)'));
            const totalRows = rows.length;
            if (totalRows <= rowsPerPage) {
                // Not enough rows for pagination, remain hidden
                return;
            }

            // Show pagination container
            paginationContainer.classList.remove('hidden');
            const totalPages = Math.ceil(totalRows / rowsPerPage);
            let currentPage = 1;

            const statusText = paginationContainer.querySelector('.status-text');
            const controlsContainer = paginationContainer.querySelector('.controls-container');

            function render() {
                // Show/Hide rows
                const start = (currentPage - 1) * rowsPerPage;
                const end = start + rowsPerPage;

                rows.forEach((row, index) => {
                    if (index >= start && index < end) {
                        row.classList.remove('hidden');
                    } else {
                        row.classList.add('hidden');
                    }
                });

                // Update Status Text
                statusText.textContent = `Showing ${Math.min(end, totalRows)} of ${totalRows} entries`;

                // Render Controls
                controlsContainer.innerHTML = '';
                
                // Prev Button
                const prevBtn = document.createElement('button');
                prevBtn.className = `size-8 rounded-lg bg-white/5 flex items-center justify-center text-[--text-main] transition-all ${currentPage === 1 ? 'opacity-20 pointer-events-none' : 'opacity-50 hover:opacity-100 hover:bg-white/10'}`;
                prevBtn.innerHTML = '<span class="material-symbols-outlined text-sm">chevron_left</span>';
                prevBtn.onclick = () => { if (currentPage > 1) { currentPage--; render(); } };
                controlsContainer.appendChild(prevBtn);

                // Page Numbers (Simplistic approach, show all if small, or current window)
                for (let i = 1; i <= totalPages; i++) {
                    const pageBtn = document.createElement('button');
                    pageBtn.className = `size-8 rounded-lg font-black text-[10px] transition-all ${i === currentPage ? 'bg-primary text-white shadow-lg shadow-primary/20' : 'bg-white/5 text-[--text-main] opacity-50 hover:opacity-100 hover:bg-white/10'}`;
                    pageBtn.textContent = i;
                    pageBtn.onclick = () => { currentPage = i; render(); };
                    controlsContainer.appendChild(pageBtn);
                }

                // Next Button
                const nextBtn = document.createElement('button');
                nextBtn.className = `size-8 rounded-lg bg-white/5 flex items-center justify-center text-[--text-main] transition-all ${currentPage === totalPages ? 'opacity-20 pointer-events-none' : 'opacity-50 hover:opacity-100 hover:bg-white/10'}`;
                nextBtn.innerHTML = '<span class="material-symbols-outlined text-sm">chevron_right</span>';
                nextBtn.onclick = () => { if (currentPage < totalPages) { currentPage++; render(); } };
                controlsContainer.appendChild(nextBtn);
            }

            render();
        }
    </script>

    <div id="confirmModal" class="fixed inset-0 z-[150] hidden items-center justify-center p-4 overflow-hidden">
        <div id="confirmBackdrop" onclick="closeConfirmModal()"
            class="fixed inset-0 bg-background/60 backdrop-blur-sm transition-opacity duration-300 opacity-0"></div>
        <div id="confirmContainer"
            class="relative w-full max-w-md bg-transparent backdrop-blur-2xl border border-white/10 shadow-2xl rounded-[32px] overflow-hidden transition-all duration-300 scale-95 opacity-0">
            <div class="p-8 text-center text-[--text-main]">
                <div
                    class="size-16 rounded-2xl bg-primary/10 border border-primary/20 flex items-center justify-center mx-auto mb-6">
                    <span class="material-symbols-outlined text-3xl text-primary">contact_support</span>
                </div>
                <h3 id="confirmTitle" class="text-xl font-black italic uppercase tracking-tighter mb-2 italic">Confirm
                    Action</h3>
                <p id="confirmMessage" class="text-[--text-main] opacity-50 text-xs font-medium leading-relaxed mb-8">
                </p>

                <div class="flex gap-3">
                    <button onclick="closeConfirmModal()"
                        class="flex-1 py-3 px-6 rounded-xl bg-white/5 hover:bg-white/10 border border-white/5 text-[10px] font-black uppercase tracking-widest transition-all text-[--text-main] opacity-40 hover:opacity-100">
                        Cancel
                    </button>
                    <button onclick="executeConfirmedAction()"
                        class="flex-1 py-3 px-6 rounded-xl bg-primary hover:bg-primary/90 text-white text-[10px] font-black uppercase italic tracking-widest shadow-lg shadow-primary/20 transition-all active:scale-[0.98]">
                        Confirm
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div id="applicationModal"
        class="hidden items-center justify-center p-4 md:p-10 overflow-hidden pointer-events-none transition-all duration-500">
        <div class="bg-background/40 backdrop-blur-xl transition-opacity duration-500 opacity-0 pointer-events-auto"
            id="modalBackdrop"></div>
        <div class="relative w-full max-w-5xl bg-transparent backdrop-blur-3xl border border-white/10 shadow-2xl rounded-[40px] overflow-hidden flex flex-col min-h-[500px] max-h-[90vh] transition-all duration-500 scale-95 opacity-0 pointer-events-auto no-scrollbar"
            id="modalContainer">
            <div id="modalLoading"
                class="absolute inset-0 flex flex-col items-center justify-center bg-[#0d0c12]/90 backdrop-blur-2xl z-10 transition-opacity duration-300">
                <div class="size-16 relative flex items-center justify-center mb-8">
                    <div class="absolute inset-0 border-[1px] border-primary/10 rounded-full"></div>
                    <div class="absolute inset-0 border-[1px] border-t-primary rounded-full animate-spin"></div>
                    <span class="material-symbols-outlined text-primary/30 text-2xl">database</span>
                </div>
                <p class="text-[10px] font-black uppercase text-gray-500 tracking-[0.3em] italic animate-pulse">Synchronizing Data...</p>
            </div>
            <div id="modalContent"
                class="flex-1 p-8 md:p-10 opacity-0 transition-opacity duration-500 overflow-y-auto no-scrollbar"></div>
        </div>
    </div>

    <script>
        let pendingForm = null;

        function confirmAction(form, actionValue, title, message) {
            pendingForm = form;
            // Find or create hidden action input
            let actionInput = form.querySelector('input[name="action"]');
            if (actionInput) {
                actionInput.value = actionValue;
            }

            document.getElementById('confirmTitle').textContent = title;
            document.getElementById('confirmMessage').textContent = message;

            const modal = document.getElementById('confirmModal');
            modal.classList.replace('hidden', 'flex-important');
            setTimeout(() => {
                document.getElementById('confirmBackdrop').classList.replace('opacity-0', 'opacity-100');
                document.getElementById('confirmContainer').classList.replace('scale-95', 'scale-100');
                document.getElementById('confirmContainer').classList.replace('opacity-0', 'opacity-100');
            }, 10);
        }

        function closeConfirmModal() {
            const modal = document.getElementById('confirmModal');
            const backdrop = document.getElementById('confirmBackdrop');
            const container = document.getElementById('confirmContainer');

            backdrop.classList.replace('opacity-100', 'opacity-0');
            container.classList.replace('scale-100', 'scale-95');
            container.classList.replace('opacity-100', 'opacity-0');

            setTimeout(() => {
                modal.classList.replace('flex-important', 'hidden');
                pendingForm = null;
            }, 300);
        }

        function executeConfirmedAction() {
            if (pendingForm) {
                // If the action was passed via hidden input in the button, we need to ensure it's set
                // Wait, the buttons I updated have <input type="hidden" name="action" value="...">
                // This is safer than relying on button name/value which doesn't work with form.submit()
                pendingForm.submit();
            }
        }

        function openApplicationModal(appId) {
            if (!appId) return;
            const modal = document.getElementById('applicationModal');
            const backdrop = document.getElementById('modalBackdrop');
            const container = document.getElementById('modalContainer');
            const content = document.getElementById('modalContent');
            const loading = document.getElementById('modalLoading');

            modal.classList.replace('hidden', 'flex-important');
            setTimeout(() => {
                backdrop.classList.replace('opacity-0', 'opacity-100');
                container.classList.replace('scale-95', 'scale-100');
                container.classList.replace('opacity-0', 'opacity-100');
            }, 10);

            loading.classList.replace('opacity-0', 'opacity-100');
            loading.classList.remove('hidden');
            content.classList.replace('opacity-100', 'opacity-0');

            fetch(`view_application.php?id=${appId}&ajax=1`)
                .then(response => response.text())
                .then(html => {
                    content.innerHTML = html;
                    setTimeout(() => {
                        loading.classList.replace('opacity-100', 'opacity-0');
                        setTimeout(() => {
                            loading.classList.add('hidden');
                            content.classList.replace('opacity-0', 'opacity-100');
                        }, 200);
                    }, 100);
                })
                .catch(error => {
                    closeApplicationModal();
                    alert('Connection error. Please try again.');
                });
        }

        function closeApplicationModal() {
            const modal = document.getElementById('applicationModal');
            const backdrop = document.getElementById('modalBackdrop');
            const container = document.getElementById('modalContainer');
            const content = document.getElementById('modalContent');

            backdrop.classList.replace('opacity-100', 'opacity-0');
            container.classList.replace('scale-100', 'scale-95');
            container.classList.replace('opacity-100', 'opacity-0');

            setTimeout(() => {
                modal.classList.replace('flex-important', 'hidden');
                content.classList.replace('opacity-100', 'opacity-0');
                content.innerHTML = '';
            }, 500);
        }

        document.getElementById('modalBackdrop').addEventListener('click', closeApplicationModal);
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeApplicationModal(); });
    </script>

    <?php include '../includes/image_viewer.php'; ?>
</body>

</html>