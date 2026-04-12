<?php
session_start();
require_once '../db.php';

// Enable error reporting for debugging 500 error
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Security Check
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'tenant') {
    header("Location: ../login.php");
    exit;
}

$gym_id = $_SESSION['gym_id'] ?? null;
$user_id = $_SESSION['user_id'] ?? null;

if (!$user_id || !$gym_id) {
    header("Location: ../login.php");
    exit;
}

// Fetch Gym & Owner Details (gym_name and owner's name)
$stmtGym = $pdo->prepare("
    SELECT g.gym_name, u.first_name, u.last_name, g.owner_user_id
    FROM gyms g 
    JOIN users u ON g.owner_user_id = u.user_id 
    WHERE g.gym_id = ?
");
$stmtGym->execute([$gym_id]);
$gym_data = $stmtGym->fetch();

$gym_name = $gym_data['gym_name'] ?? 'Horizon Gym';
$first_name = $gym_data['first_name'] ?? 'Owner';
$owner_user_id = $gym_data['owner_user_id'] ?? 0;
$active_page = "dashboard";


// Fetch Branding Data from tenant_pages (logo_path, colors are here)
$stmtPage = $pdo->prepare("SELECT * FROM tenant_pages WHERE gym_id = ?");
$stmtPage->execute([$gym_id]);
$page = $stmtPage->fetch();

// Set default fallback values
$theme_color = ($page && isset($page['theme_color'])) ? $page['theme_color'] : '#8c2bee';
$bg_color = ($page && isset($page['bg_color'])) ? $page['bg_color'] : '#0a090d';
// gym_name is already set above from gyms table

// Fetch Global Statistics (Staff + Coaches, excluding the Tenant/Owner)
$stmtStaff = $pdo->prepare("
    SELECT (
        (SELECT COUNT(*) FROM staff WHERE gym_id = ? AND status = 'Active' AND user_id != ?) +
        (SELECT COUNT(*) FROM coaches WHERE gym_id = ? AND status = 'Active' AND user_id != ?)
    ) as total
");
$stmtStaff->execute([$gym_id, $owner_user_id, $gym_id, $owner_user_id]);
$total_staff = $stmtStaff->fetchColumn() ?: 0;

$stmtMembers = $pdo->prepare("SELECT COUNT(*) FROM members WHERE gym_id = ? AND member_status = 'Active'");
$stmtMembers->execute([$gym_id]);
$total_members = $stmtMembers->fetchColumn() ?: 0;

// Fetch Monthly Revenue
$stmtRev = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE gym_id = ? AND payment_status IN ('Verified', 'Completed', 'Paid') AND client_subscription_id IS NULL AND MONTH(created_at) = MONTH(CURRENT_DATE) AND YEAR(created_at) = YEAR(CURRENT_DATE)");
$stmtRev->execute([$gym_id]);
$monthly_rev = $stmtRev->fetchColumn() ?: 0;

// Fetch Active Subscription / Plan
$stmtSub = $pdo->prepare("
    SELECT cs.subscription_status, cs.payment_term, cs.next_billing_date, cs.end_date, wp.plan_name 
    FROM client_subscriptions cs 
    JOIN website_plans wp ON cs.website_plan_id = wp.website_plan_id 
    WHERE cs.gym_id = ? 
    ORDER BY cs.created_at DESC LIMIT 1
");
$stmtSub->execute([$gym_id]);
$subscription = $stmtSub->fetch();

$plan_name = $subscription['plan_name'] ?? 'No Plan';
$sub_status = $subscription['subscription_status'] ?? 'None';
$payment_term = $subscription['payment_term'] ?? 'Full';
$next_billing_date = $subscription['next_billing_date'] ?? null;
$plan_end_date = $subscription['end_date'] ?? null;
$is_sub_active = (strtolower($sub_status) === 'active');

$billing_status_color = 'text-gray-500';
$billing_label = '';
$is_suspended = false;

if ($is_sub_active && $payment_term === 'Monthly' && $next_billing_date) {
    // Determine start of current day and due day
    $now_time = strtotime('today');
    $due_time = strtotime($next_billing_date);
    $diff_days = ($due_time - $now_time) / (60 * 60 * 24);
    
    if ($diff_days > 0 && $diff_days <= 7) {
        $billing_status_color = 'text-yellow-500'; // Yellow when near due
        $billing_label = "Due in " . $diff_days . " days";
    } elseif ($diff_days === 0) {
        $billing_status_color = 'text-yellow-500';
        $billing_label = "Due Today";
    } elseif ($diff_days < 0) {
        $billing_status_color = 'text-red-500'; // Red when overdue
        $abs_days = abs($diff_days);
        $billing_label = "Past Due (" . $abs_days . " days)";
        
        // 3 Days Extension Logic
        if ($abs_days > 3) {
            $is_suspended = true;
            $sub_status = 'Suspended (Overdue)';
            $is_sub_active = false;
        }
    } else {
        $billing_label = "Due " . date('M d', $due_time);
    }
} elseif ($is_sub_active && $payment_term === 'Full' && $plan_end_date) {
    $billing_label = "Active till " . date('M d', strtotime($plan_end_date));
}

$page_title = "Owner Dashboard";

// --- START OF CHART DATA QUERIES ---
// Fetch Last 6 Months Revenue Trends
$revenue_trends = [];
for ($i = 5; $i >= 0; $i--) {
    $month = date('m', strtotime("-$i months"));
    $year = date('Y', strtotime("-$i months"));
    $month_name = date('M', strtotime("-$i months"));
    
    $stmt = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE gym_id = ? AND payment_status IN ('Verified', 'Completed', 'Paid') AND client_subscription_id IS NULL AND MONTH(created_at) = ? AND YEAR(created_at) = ?");
    $stmt->execute([$gym_id, $month, $year]);
    $revenue_trends[] = [
        'month' => $month_name,
        'amount' => (float)($stmt->fetchColumn() ?: 0)
    ];
}

// Fetch Last 6 Months Member Growth
$member_growth = [];
for ($i = 5; $i >= 0; $i--) {
    $month = date('m', strtotime("-$i months"));
    $year = date('Y', strtotime("-$i months"));
    $month_name = date('M', strtotime("-$i months"));
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM members WHERE gym_id = ? AND MONTH(created_at) = ? AND YEAR(created_at) = ?");
    $stmt->execute([$gym_id, $month, $year]);
    $member_growth[] = [
        'month' => $month_name,
        'count' => (int)($stmt->fetchColumn() ?: 0)
    ];
}
// --- END OF CHART DATA QUERIES ---
?>


<!DOCTYPE html>

<html class="dark" lang="en">

<head>

    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>

    <title><?= $page_title ?> | Horizon Partners</title>

    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>

    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>

    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <script>

        tailwind.config = {

            darkMode: "class",

            theme: { 
                extend: { 
                    colors: { 
                        "primary": "<?= $theme_color ?>", 
                        "background-dark": "<?= $bg_color ?>", 
                        "surface-dark": "#14121a", 
                        "border-subtle": "rgba(255,255,255,0.05)"
                    }
                }
            }

        }

    </script>

    <style>

        body { font-family: 'Lexend', sans-serif; background-color: <?= $bg_color ?>; color: white; overflow: hidden; }

        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); }
        .hover-lift:hover { transform: translateY(-10px); border-color: <?= $theme_color ?>40; box-shadow: 0 20px 40px -20px <?= $theme_color ?>30; }

        .nav-link { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; transition: all 0.2s; white-space: nowrap; color: #94a3b8; }

        .active-nav { color: <?= $theme_color ?> !important; position: relative; }

        .active-nav::after { 

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

        /* Unified Sidebar Navigation Styles from Admin Portal */
        .side-nav { width: 110px; transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1); overflow: hidden; display: flex; flex-direction: column; position: fixed; left: 0; top: 0; height: 100vh; z-index: 50; }
        .side-nav:hover { width: 300px; }
        .main-content { margin-left: 110px; flex: 1; min-width: 0; transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1); }
        .side-nav:hover ~ .main-content { margin-left: 300px; }

        .nav-label { opacity: 0; transform: translateX(-15px); transition: all 0.3s ease-in-out; white-space: nowrap; pointer-events: none; }
        .side-nav:hover .nav-label { opacity: 1; transform: translateX(0); pointer-events: auto; }
        
        .nav-section-label { max-height: 0; opacity: 0; overflow: hidden; transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); margin: 0 !important; pointer-events: none; }
        .side-nav:hover .nav-section-label { max-height: 20px; opacity: 1; margin-bottom: 8px !important; pointer-events: auto; }
        
        .nav-item { display: flex; align-items: center; gap: 16px; padding: 10px 38px; transition: all 0.2s ease; text-decoration: none; white-space: nowrap; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; }
        .nav-item:hover { background: rgba(255,255,255,0.05); color: white; }
        .nav-item.active { color: <?= $theme_color ?> !important; position: relative; }
        .nav-item.active::after { content: ''; position: absolute; right: 0px; top: 50%; transform: translateY(-50%); width: 4px; height: 24px; background: <?= $theme_color ?>; border-radius: 4px 0 0 4px; }
        
        /* Invisible Scroll System */
        *::-webkit-scrollbar { display: none !important; }
        * { -ms-overflow-style: none !important; scrollbar-width: none !important; }

        /* Sidebar-Aware Sub Modal */
        #subModal { 
            position: fixed; 
            top: 0; 
            right: 0; 
            bottom: 0; 
            left: 110px; 
            z-index: 200; 
            display: none !important; 
            align-items: center; 
            justify-content: center; 
            padding: 24px; 
            background: rgba(0, 0, 0, 0.8); 
            backdrop-filter: blur(12px); 
            transition: left 0.4s cubic-bezier(0.4, 0, 0.2, 1); 
        }
        #subModal.active { display: flex !important; }
        .side-nav:hover ~ #subModal { left: 300px; }
        
        /* Hard Suspension Override */
        #subModal.hard-lock {
            left: 0 !important;
            z-index: 9999 !important;
            background: rgba(10, 9, 13, 0.95);
        }
    </style>

    <script>
        function showSubWarning() { document.getElementById('subModal').classList.add('active'); }
        function closeSubModal() { 
            if(!document.getElementById('subModal').classList.contains('hard-lock')) {
                document.getElementById('subModal').classList.remove('active'); 
            }
        }
    </script>

    <script>

        function updateTopClock() {

            const now = new Date();

            const timeString = now.toLocaleTimeString('en-US', { 

                hour: '2-digit', 

                minute: '2-digit', 

                second: '2-digit' 

            });

            const clockEl = document.getElementById('topClock');

            if (clockEl) clockEl.textContent = timeString;

        }

        setInterval(updateTopClock, 1000);

        window.addEventListener('DOMContentLoaded', updateTopClock);

    </script>

</head>

<body class="antialiased flex h-screen overflow-hidden">

<nav class="side-nav bg-background-dark border-r border-white/5 z-50">
    <div class="px-7 py-8 mb-4 shrink-0">
        <div class="flex items-center gap-4">
            <div class="size-10 rounded-xl shrink-0 overflow-hidden flex items-center justify-center <?= empty($page['logo_path']) ? 'bg-primary shadow-lg shadow-primary/20' : '' ?>">
                <?php if (!empty($page['logo_path'])): ?>
                    <img src="<?= htmlspecialchars($page['logo_path']) ?>" class="size-full object-cover">
                <?php else: ?>
                    <span class="material-symbols-outlined text-white text-2xl">bolt</span>
                <?php endif; ?>
            </div>
            <h1 class="nav-label text-lg font-black italic uppercase tracking-tighter text-white">Owner Portal</h1>
        </div>
    </div>
    
    <div class="flex-1 overflow-y-auto no-scrollbar space-y-1">
        <div class="nav-section-label px-[38px] mb-2"><span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Main Menu</span></div>
        <a href="tenant_dashboard.php" class="nav-item <?= ($active_page == 'dashboard') ? 'active' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">grid_view</span> 
            <span class="nav-label">Dashboard</span>
        </a>
        
        <a href="my_users.php" class="nav-item <?= ($active_page == 'users') ? 'active' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">group</span> 
            <span class="nav-label">Users</span>
        </a>

        <a href="transactions.php" class="nav-item <?= ($active_page == 'transactions') ? 'active' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">receipt_long</span> 
            <span class="nav-label">Transactions</span>
        </a>

        <a href="attendance.php" class="nav-item <?= ($active_page == 'attendance') ? 'active' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">history</span> 
            <span class="nav-label">Attendance</span>
        </a>

        <div class="nav-section-label px-[38px] mb-2 mt-6"><span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Management</span></div>

        <a href="staff.php" class="nav-item <?= ($active_page == 'staff') ? 'active' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">badge</span> 
            <span class="nav-label">Staff</span>
        </a>

        <a href="reports.php" class="nav-item <?= ($active_page == 'reports') ? 'active' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">analytics</span> 
            <span class="nav-label">Reports</span>
        </a>

        <a href="sales_report.php" class="nav-item <?= ($active_page == 'sales') ? 'active' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">payments</span> 
            <span class="nav-label">Sales Reports</span>
        </a>
    </div>

    <div class="mt-auto pt-4 border-t border-white/10 shrink-0 pb-6">
        <div class="nav-section-label px-[38px] mb-2"><span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Account</span></div>
        <a href="tenant_settings.php" class="nav-item <?= ($active_page == 'settings') ? 'active' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">settings</span> 
            <span class="nav-label">Settings</span>
        </a>
        <a href="profile.php" class="nav-item <?= ($active_page == 'profile') ? 'active' : '' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">account_circle</span> 
            <span class="nav-label">Profile</span>
        </a>
        <a href="../logout.php" class="nav-item text-gray-400 hover:text-rose-500 transition-colors">
            <span class="material-symbols-outlined text-xl shrink-0">logout</span> 
            <span class="nav-label">Sign Out</span>
        </a>
    </div>
</nav>

<main class="main-content flex-1 p-10 overflow-y-auto no-scrollbar">

    <?php if (strpos($sub_status, 'Pending Approval') !== false): ?>
        <div class="glass-card p-6 border-amber-500/30 bg-amber-500/5 mb-8 flex items-center gap-6">
            <div class="size-12 rounded-2xl bg-amber-500/20 flex items-center justify-center text-amber-500 shrink-0">
                <span class="material-symbols-outlined text-2xl">hourglass_empty</span>
            </div>
            <div class="flex-1">
                <h4 class="text-sm font-black uppercase italic tracking-tight text-amber-400">Subscription Pending Approval</h4>
                <p class="text-[10px] font-bold text-amber-500/70 uppercase tracking-widest mt-1">Your payment is being verified by our team. Access to some features might be restricted until your plan is activated.</p>
            </div>
            <div class="text-right">
                <span class="px-3 py-1 rounded-full bg-amber-500/20 text-amber-400 text-[9px] font-black uppercase tracking-widest border border-amber-500/30">Verification In Progress</span>
            </div>
        </div>
    <?php elseif ($sub_status === 'None' || $sub_status === 'Expired' || $sub_status === 'Inactive' || strpos($sub_status, 'Suspended') !== false): ?>
        <div class="glass-card p-6 border-rose-500/30 bg-rose-500/5 mb-8 flex items-center gap-6">
            <div class="size-12 rounded-2xl bg-rose-500/20 flex items-center justify-center text-rose-500 shrink-0">
                <span class="material-symbols-outlined text-2xl">priority_high</span>
            </div>
            <div class="flex-1">
                <h4 class="text-sm font-black uppercase italic tracking-tight text-rose-400"><?= $is_suspended ? 'System Access Suspended' : 'No Active Subscription' ?></h4>
                <p class="text-[10px] font-bold text-rose-500/70 uppercase tracking-widest mt-1">Activate a growth plan to unlock the full potential of your gym's digital infrastructure.</p>
            </div>
            <a href="subscription_plan.php" class="h-10 px-6 rounded-xl bg-rose-500 text-white text-[10px] font-black uppercase tracking-widest flex items-center justify-center hover:opacity-90 transition-all">
                <?= $is_suspended ? 'Pay Overdue Balance' : 'Select Plan' ?>
            </a>
        </div>
    <?php endif; ?>

    <header class="mb-10 flex justify-between items-end">
        <div>
            <h2 class="text-3xl font-black uppercase tracking-tighter text-white italic">Welcome Back, <span class="text-primary italic"><?= htmlspecialchars($first_name) ?></span></h2>
            <p class="text-gray-500 text-[10px] font-black uppercase tracking-widest mt-1 italic"><?= htmlspecialchars($gym_name) ?> Management System</p>
        </div>

        <div class="text-right">
            <p id="topClock" class="text-white font-black italic text-2xl leading-none tracking-tighter">00:00:00 AM</p>
            <p class="text-primary text-[10px] font-bold uppercase tracking-widest mt-2 px-1 opacity-80"><?= date('l, M d, Y') ?></p>
        </div>
    </header>



    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-10">
        <div class="glass-card p-6 flex items-center gap-5 relative overflow-hidden group">
            <div class="size-12 rounded-2xl bg-primary/10 flex items-center justify-center text-primary shrink-0 group-hover:scale-110 transition-transform">
                <span class="material-symbols-outlined text-2xl">badge</span>
            </div>
            <div>
                <p class="text-[10px] font-bold uppercase text-gray-500 tracking-widest">Total Staff</p>
                <h3 class="text-2xl font-extrabold mt-1 tracking-tight italic uppercase"><?= $total_staff ?></h3>
            </div>
            <div class="absolute -right-4 -bottom-4 size-24 bg-primary/5 rounded-full blur-2xl"></div>
        </div>

        <div class="glass-card p-6 flex items-center gap-5 relative overflow-hidden group">
            <div class="size-12 rounded-2xl bg-emerald-500/10 flex items-center justify-center text-emerald-500 shrink-0 group-hover:scale-110 transition-transform">
                <span class="material-symbols-outlined text-2xl">group</span>
            </div>
            <div>
                <p class="text-[10px] font-bold uppercase text-gray-500 tracking-widest">Active Members</p>
                <h3 class="text-2xl font-extrabold mt-1 tracking-tight italic uppercase"><?= $total_members ?></h3>
            </div>
            <div class="absolute -right-4 -bottom-4 size-24 bg-emerald-500/5 rounded-full blur-2xl"></div>
        </div>

        <div class="glass-card p-6 flex items-center gap-5 relative overflow-hidden group">
            <div class="size-12 rounded-2xl bg-amber-500/10 flex items-center justify-center text-amber-500 shrink-0 group-hover:scale-110 transition-transform">
                <span class="material-symbols-outlined text-2xl">payments</span>
            </div>
            <div>
                <p class="text-[10px] font-bold uppercase text-gray-500 tracking-widest">Revenue</p>
                <h3 class="text-2xl font-extrabold mt-1 tracking-tight italic uppercase">₱<?= number_format($monthly_rev, 0) ?></h3>
            </div>
            <div class="absolute -right-4 -bottom-4 size-24 bg-amber-500/5 rounded-full blur-2xl"></div>
        </div>

        <div class="glass-card p-6 flex items-center gap-5 relative overflow-hidden group">
            <div class="size-12 rounded-2xl bg-blue-500/10 flex items-center justify-center text-blue-500 shrink-0 group-hover:scale-110 transition-transform">
                <span class="material-symbols-outlined text-2xl">card_membership</span>
            </div>
            <div>
                <p class="text-[10px] font-bold uppercase text-gray-500 tracking-widest flex items-center gap-1">
                    <?= htmlspecialchars($plan_name) ?> 
                    <?php if($payment_term === 'Monthly'): ?><span class="px-1.5 py-0.5 rounded-sm bg-blue-500/20 text-blue-400 text-[7px] font-black">1/Mo</span><?php endif; ?>
                </p>
                <div class="flex items-center gap-2 mt-1">
                    <h3 class="text-xl font-extrabold tracking-tight uppercase <?= ($is_sub_active && strtolower($sub_status) === 'active') ? 'text-emerald-500' : 'text-rose-500' ?> italic leading-none"><?= htmlspecialchars($sub_status) ?></h3>
                </div>
                <?php if ($billing_label): ?>
                <p class="text-[9px] font-black uppercase tracking-widest mt-1 <?= $billing_status_color ?>"><?= $billing_label ?></p>
                <?php endif; ?>
            </div>
            <div class="absolute -right-4 -bottom-4 size-24 bg-blue-500/5 rounded-full blur-2xl"></div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-10">
        <div class="glass-card p-6">
            <div class="flex items-center justify-between mb-6">
                <h4 class="text-base font-bold uppercase tracking-tight flex items-center gap-3">
                    <span class="material-symbols-outlined text-primary">palette</span> Page Design
                </h4>
                <span class="text-[9px] font-bold uppercase text-gray-500 tracking-widest">Current Style</span>
            </div>

            <div class="p-6 rounded-3xl bg-background-dark/50 border border-white/5 mb-6">
                <div class="flex items-center gap-4">
                    <div class="size-12 rounded-xl bg-[#1a1821] flex items-center justify-center overflow-hidden shadow-inner">
                        <?php if (!empty($page['logo_path'])): ?>
                            <img src="<?= htmlspecialchars($page['logo_path']) ?>" class="w-full h-full object-cover">
                        <?php else: ?>
                            <span class="material-symbols-outlined text-gray-600 text-xl">image</span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h5 class="text-sm font-bold italic uppercase tracking-tight"><?= htmlspecialchars($gym_name) ?></h5>
                        <div class="flex items-center gap-2 mt-1.5">
                            <div class="size-3.5 rounded-full border border-white/20" style="background-color: <?= $theme_color ?>"></div>
                            <p class="text-[9px] font-bold text-gray-400 uppercase tracking-widest">Theme Accent Color</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex gap-4">
                <a href="tenant_settings.php" class="flex-1 h-12 rounded-xl bg-white/5 border border-white/10 hover:bg-white/10 transition-all flex items-center justify-center text-[10px] font-bold uppercase tracking-widest gap-2">
                    <span class="material-symbols-outlined text-lg">edit_note</span> Edit Page
                </a>
                <a <?= $is_sub_active ? 'target="_blank" href="../portal.php?gym=' . htmlspecialchars($page['page_slug'] ?? '') . '"' : 'onclick="showSubWarning()"' ?> class="flex-1 h-12 rounded-xl <?= $is_sub_active ? 'bg-primary shadow-primary/20 hover:opacity-90' : 'bg-white/5 border border-white/10 text-gray-500 hover:text-white' ?> transition-all flex items-center justify-center text-[10px] font-bold uppercase tracking-widest gap-2 text-white shadow-lg cursor-pointer">
                    <span class="material-symbols-outlined text-lg"><?= $is_sub_active ? 'visibility' : 'lock' ?></span> 
                    <?= $is_sub_active ? 'View Site' : 'Site Locked' ?>
                </a>
            </div>
        </div>

        <div class="glass-card p-6">
            <div class="flex items-center justify-between mb-6">
                <h4 class="text-base font-bold uppercase tracking-tight flex items-center gap-3">
                    <span class="material-symbols-outlined text-primary">bolt</span> Actions
                </h4>
                <span class="text-[9px] font-bold uppercase text-gray-500 tracking-widest">Quick Access</span>
            </div>

            <div class="grid grid-cols-1 gap-3">
                <a href="profile.php" class="group p-5 rounded-3xl bg-white/5 border border-white/5 hover:border-indigo-500/50 transition-all flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <div class="size-12 rounded-2xl bg-indigo-500/10 flex items-center justify-center text-indigo-500 group-hover:scale-110 transition-transform">
                            <span class="material-symbols-outlined text-xl">account_circle</span>
                        </div>
                        <div>
                            <p class="text-xs font-bold uppercase italic">My Profile</p>
                            <p class="text-[9px] text-gray-600 uppercase font-bold mt-0.5">Edit personal and account details</p>
                        </div>
                    </div>
                    <span class="material-symbols-outlined text-gray-600 group-hover:translate-x-1 transition-transform">chevron_right</span>
                </a>

                <a href="staff.php" class="group p-5 rounded-3xl bg-white/5 border border-white/5 hover:border-primary/50 transition-all flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <div class="size-12 rounded-2xl bg-blue-500/10 flex items-center justify-center text-blue-500 group-hover:scale-110 transition-transform">
                            <span class="material-symbols-outlined text-xl">person_add</span>
                        </div>
                        <div>
                            <p class="text-xs font-bold uppercase italic">Add Team Staff</p>
                            <p class="text-[9px] text-gray-600 uppercase font-bold mt-0.5">Manage employees and roles</p>
                        </div>
                    </div>
                    <span class="material-symbols-outlined text-gray-600 group-hover:translate-x-1 transition-transform">chevron_right</span>
                </a>

                <a href="my_users.php" class="group p-5 rounded-3xl bg-white/5 border border-white/5 hover:border-emerald-500/50 transition-all flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <div class="size-12 rounded-2xl bg-emerald-500/10 flex items-center justify-center text-emerald-500 group-hover:scale-110 transition-transform">
                            <span class="material-symbols-outlined text-xl">group_add</span>
                        </div>
                        <div>
                            <p class="text-xs font-bold uppercase italic">Member Directory</p>
                            <p class="text-[9px] text-gray-600 uppercase font-bold mt-0.5">Manage active memberships</p>
                        </div>
                    </div>
                    <span class="material-symbols-outlined text-gray-600 group-hover:translate-x-1 transition-transform">chevron_right</span>
                </a>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 pb-10">
        <div class="glass-card p-8 hover-lift">
            <div class="flex justify-between items-center mb-8">
                <h4 class="text-base font-bold uppercase tracking-tight flex items-center gap-3">
                    <span class="material-symbols-outlined text-primary">bar_chart</span> Revenue Trends
                </h4>
                <div class="flex items-center gap-2">
                    <div class="size-2 rounded-full bg-primary/20"></div>
                    <span class="text-[10px] font-bold text-gray-500 uppercase tracking-widest">Last 6 Months</span>
                </div>
            </div>
            <div class="h-64 w-full">
                <canvas id="revenueChart"></canvas>
            </div>
        </div>

        <div class="glass-card p-8 hover-lift">
            <div class="flex justify-between items-center mb-8">
                <h4 class="text-base font-bold uppercase tracking-tight flex items-center gap-3">
                    <span class="material-symbols-outlined text-primary">monitoring</span> Member Growth
                </h4>
                <div class="flex items-center gap-2">
                    <div class="size-2 rounded-full bg-emerald-500/20"></div>
                    <span class="text-[10px] font-bold text-gray-500 uppercase tracking-widest">New Signups</span>
                </div>
            </div>
            <div class="h-64 w-full">
                <canvas id="growthChart"></canvas>
            </div>
        </div>
    </div>

</main>



    <script>
        // Chart Initialization with Dynamic Data
        const chartOptions = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#14121a',
                    titleColor: '<?= $theme_color ?>',
                    borderColor: 'rgba(255,255,255,0.1)',
                    borderWidth: 1,
                    padding: 12,
                    displayColors: false,
                    titleFont: { family: 'Lexend', size: 10, weight: '800' },
                    bodyFont: { family: 'Lexend', size: 13, weight: '700' }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(255,255,255,0.03)', drawBorder: false },
                    ticks: { color: '#64748b', font: { family: 'Lexend', size: 10, weight: '600' } }
                },
                x: {
                    grid: { display: false },
                    ticks: { color: '#64748b', font: { family: 'Lexend', size: 10, weight: '600' } }
                }
            }
        };

        // Revenue Trends Chart (Line)
        new Chart(document.getElementById('revenueChart'), {
            type: 'line',
            data: {
                labels: [<?php foreach($revenue_trends as $r) echo "'".$r['month']."',"; ?>],
                datasets: [{
                    label: 'Revenue',
                    data: [<?php foreach($revenue_trends as $r) echo $r['amount'].","; ?>],
                    borderColor: '<?= $theme_color ?>',
                    borderWidth: 3,
                    tension: 0.4,
                    fill: true,
                    backgroundColor: (ctx) => {
                        const gradient = ctx.chart.ctx.createLinearGradient(0, 0, 0, 400);
                        gradient.addColorStop(0, '<?= $theme_color ?>20');
                        gradient.addColorStop(1, '<?= $theme_color ?>00');
                        return gradient;
                    },
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    pointBackgroundColor: '<?= $theme_color ?>'
                }]
            },
            options: chartOptions
        });

        // Member Growth Chart (Bar)
        new Chart(document.getElementById('growthChart'), {
            type: 'bar',
            data: {
                labels: [<?php foreach($member_growth as $m) echo "'".$m['month']."',"; ?>],
                datasets: [{
                    label: 'New Members',
                    data: [<?php foreach($member_growth as $m) echo $m['count'].","; ?>],
                    backgroundColor: '<?= $theme_color ?>80',
                    hoverBackgroundColor: '<?= $theme_color ?>',
                    borderRadius: 6,
                    barThickness: 24
                }]
            },
            options: chartOptions
        });
    </script>

    <!-- Restriction Modal (Sidebar-Aware & Capable of Hard Locking) -->
    <div id="subModal" class="<?= $is_suspended ? 'active hard-lock' : '' ?>">
        <div class="glass-card max-w-md w-full p-10 text-center animate-in zoom-in duration-300 relative shadow-[0_0_100px_rgba(140,43,238,0.15)] border-primary/20">
            <?php if (!$is_suspended): ?>
            <button onclick="closeSubModal()" class="absolute top-6 right-6 text-gray-400 hover:text-white transition-all size-10 rounded-xl hover:bg-white/5 flex items-center justify-center">
                <span class="material-symbols-outlined">close</span>
            </button>
            <?php endif; ?>
            
            <div class="size-20 rounded-3xl <?= $is_suspended ? 'bg-red-500/10 border-red-500/20' : 'bg-primary/10 border-primary/20' ?> border flex items-center justify-center mx-auto mb-8">
                <span class="material-symbols-outlined text-4xl <?= $is_suspended ? 'text-red-500' : 'text-primary' ?>">lock</span>
            </div>
            <h3 class="text-2xl font-black italic uppercase tracking-tighter text-white mb-3"><?= $is_suspended ? 'System Suspended' : 'Subscription Required' ?></h3>
            <p class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 mb-10 leading-relaxed italic px-4">
                <?= $is_suspended ? 'Your subscription is critically overdue and your 3-day extension has lapsed. Access to the gym system and your public portal has been restricted.' : 'Your public gym portal is currently offline or restricted. Please activate a growth plan to go live.' ?>
                <br><br>Status: <span class="<?= $is_suspended ? 'text-red-500' : 'text-primary' ?> italic animate-pulse"><?= $sub_status ?></span>
            </p>
            <div class="flex flex-col gap-4">
                <a href="subscription_plan.php" class="h-14 rounded-2xl <?= $is_suspended ? 'bg-red-600 shadow-red-500/20' : 'bg-primary shadow-primary/20' ?> text-white text-[11px] font-black uppercase italic tracking-[0.2em] flex items-center justify-center gap-3 hover:scale-[1.02] active:scale-95 transition-all shadow-xl group">
                    <span class="material-symbols-outlined text-xl group-hover:scale-110 transition-transform">payments</span>
                    <?= $is_suspended ? 'Settle Overdue Balance' : 'Select Growth Plan' ?>
                </a>
                <?php if (!$is_suspended): ?>
                <button onclick="closeSubModal()" class="h-14 rounded-2xl bg-white/5 border border-white/10 text-gray-400 text-[11px] font-black uppercase italic tracking-[0.2em] flex items-center justify-center hover:bg-white/10 transition-all">
                    Dismiss
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

</body>

</html>