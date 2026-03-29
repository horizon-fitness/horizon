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

// --- FETCH BRANDING & CONFIG ---
$stmtGym = $pdo->prepare("SELECT * FROM gyms WHERE gym_id = ?");
$stmtGym->execute([$gym_id]);
$gym = $stmtGym->fetch();

$stmtPage = $pdo->prepare("SELECT * FROM tenant_pages WHERE gym_id = ? LIMIT 1");
$stmtPage->execute([$gym_id]);
$tenant_config = $stmtPage->fetch();

// Also fetch address for report headers
$stmtAddress = $pdo->prepare("SELECT * FROM gym_addresses WHERE address_id = ?");
$stmtAddress->execute([$gym['address_id'] ?? 0]);
$gym_address = $stmtAddress->fetch();

// --- REPORT FILTER LOGIC ---
$tab = $_GET['tab'] ?? 'financials';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$search = $_GET['search'] ?? '';

// --- CALCULATE LIFETIME REVENUE (No date filter) ---
$lifetime_total = 0;
try {
    $stmtLifetime = $pdo->prepare("SELECT SUM(amount) as total FROM payments WHERE payment_status = 'Approved' AND gym_id = ?");
    $stmtLifetime->execute([$gym_id]);
    $lifetime_total = $stmtLifetime->fetch()['total'] ?? 0;
} catch (Exception $e) {
}

// --- CALCULATE FILTERED REVENUE (For table total) ---
$filtered_total = 0;
try {
    $stmtFiltered = $pdo->prepare("SELECT SUM(amount) as total FROM payments WHERE payment_status = 'Approved' AND gym_id = ? AND created_at BETWEEN ? AND ?");
    $stmtFiltered->execute([$gym_id, $start_date . ' 00:00:00', $end_date . ' 23:59:59']);
    $filtered_total = $stmtFiltered->fetch()['total'] ?? 0;
} catch (Exception $e) {
}

// --- DYNAMIC DATA FETCHING ---
$query_data = [];
$report_title = "Report";

try {
    switch ($tab) {
        case 'financials':
            $sql = "SELECT p.*, CONCAT(u.first_name, ' ', u.last_name) as fullname, u.username,
                           CASE 
                             WHEN p.subscription_id IS NOT NULL THEN 'Subscription'
                             WHEN p.booking_id IS NOT NULL THEN 'Booking'
                             WHEN p.client_subscription_id IS NOT NULL THEN 'Plan Payment'
                             ELSE 'Other' 
                           END as transaction_type
                    FROM payments p 
                    LEFT JOIN members m ON p.member_id = m.member_id
                    LEFT JOIN users u ON m.user_id = u.user_id 
                    WHERE (p.gym_id = ? OR p.member_id IN (SELECT member_id FROM members WHERE gym_id = ?))
                    AND p.created_at BETWEEN ? AND ?
                    AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ? OR p.reference_number LIKE ?)
                    ORDER BY p.created_at DESC";
            $stmt = $pdo->prepare($sql);
            $sterm = "%$search%";
            $stmt->execute([$gym_id, $gym_id, $start_date . ' 00:00:00', $end_date . ' 23:59:59', $sterm, $sterm, $sterm, $sterm]);
            $query_data = $stmt->fetchAll();
            $report_title = "Financial Report";
            break;

        case 'attendance':
            $sql = "SELECT a.*, CONCAT(u.first_name, ' ', u.last_name) as fullname, u.username,
                           b.booking_reference, b.booking_status
                    FROM attendance a 
                    JOIN members m ON a.member_id = m.member_id
                    JOIN users u ON m.user_id = u.user_id 
                    LEFT JOIN bookings b ON a.booking_id = b.booking_id
                    WHERE a.gym_id = ?
                    AND a.attendance_date BETWEEN ? AND ?
                    AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ? OR b.booking_reference LIKE ?)
                    ORDER BY a.attendance_date DESC, a.check_in_time DESC";
            $stmt = $pdo->prepare($sql);
            $sterm = "%$search%";
            $stmt->execute([$gym_id, $start_date, $end_date, $sterm, $sterm, $sterm, $sterm]);
            $query_data = $stmt->fetchAll();
            $report_title = "Attendance Report";
            break;

        case 'membership':
            $sql = "SELECT ms.*, mp.plan_name, CONCAT(u.first_name, ' ', u.last_name) as fullname, u.username, u.email
                    FROM member_subscriptions ms
                    JOIN membership_plans mp ON ms.membership_plan_id = mp.membership_plan_id
                    JOIN members m ON ms.member_id = m.member_id
                    JOIN users u ON m.user_id = u.user_id
                    WHERE m.gym_id = ?
                    AND (ms.start_date BETWEEN ? AND ? OR ms.end_date BETWEEN ? AND ?)
                    AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ?)
                    ORDER BY ms.end_date ASC";

            $stmt = $pdo->prepare($sql);
            $sterm = "%$search%";
            $stmt->execute([$gym_id, $start_date, $end_date, $start_date, $end_date, $sterm, $sterm, $sterm]);
            $query_data = $stmt->fetchAll();
            $report_title = "Subscription Report";
            break;
    }
} catch (Exception $e) {
    $error_msg = $e->getMessage();
}

$active_page = "admin_report";
?>
<!DOCTYPE html>
<html class="dark" lang="en">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>System Reports | Horizon</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap"
        rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;700&display=swap" rel="stylesheet" />
    <link
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200"
        rel="stylesheet" />
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "<?= $tenant_config['theme_color'] ?? '#8c2bee' ?>",
                        "background-dark": "<?= $tenant_config['bg_color'] ?? '#0a090d' ?>",
                        "surface-dark": "#14121a",
                        "border-subtle": "rgba(255,255,255,0.05)"
                    }
                }
            }
        } 
    </script>
    <style>
        body {
            font-family: 'Lexend', sans-serif;
            background-color:
                <?= $tenant_config['bg_color'] ?? '#0a090d' ?>
            ;
            color: white;
            display: flex;
            flex-direction: row;
            min-height: 100vh;
            overflow: hidden;
        }

        .glass-card {
            background: #14121a;
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 24px;
        }

        /* Sidebar Hover Logic */
        :root {
            --nav-width: 110px;
        }

        body:has(.side-nav:hover) {
            --nav-width: 300px;
        }

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
            color:
                <?= $tenant_config['theme_color'] ?? '#8c2bee' ?>
                !important;
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
            background:
                <?= $tenant_config['theme_color'] ?? '#8c2bee' ?>
            ;
            border-radius: 4px 0 0 4px;
        }

        .no-scrollbar::-webkit-scrollbar {
            display: none;
        }

        .no-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        .input-box {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            color: white;
            padding: 12px 16px;
            font-size: 13px;
            font-weight: 500;
            outline: none;
            transition: all 0.2s;
        }

        .input-box:focus {
            border-color:
                <?= $tenant_config['theme_color'] ?? '#8c2bee' ?>
            ;
            background: rgba(255, 255, 255, 0.08);
        }

        .input-box::placeholder {
            color: #4b5563;
        }

        .tab-btn {
            padding: 12px 24px;
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            color: #4b5563;
            border-bottom: 2px solid transparent;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .tab-btn.active {
            color:
                <?= $tenant_config['theme_color'] ?? '#8c2bee' ?>
            ;
            border-color:
                <?= $tenant_config['theme_color'] ?? '#8c2bee' ?>
            ;
        }

        .tab-btn:hover:not(.active) {
            color: white;
        }

        @media print {

            .side-nav,
            .no-print,
            header .no-print,
            .tab-btn,
            form {
                display: none !important;
            }

            .main-content {
                margin-left: 0 !important;
                padding: 0 !important;
                overflow: visible !important;
            }

            body {
                background: white !important;
                color: black !important;
                overflow: visible !important;
            }
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

        function clearFilters() {
            const urlParams = new URLSearchParams(window.location.search);
            const tab = urlParams.get('tab') || 'financials';
            window.location.href = 'admin_report.php?tab=' + tab;
        }

        function exportReportToPDF(preview = false) {
            const tableElement = document.getElementById('reportTableContainer');
            const reportTypeLabel = "<?= $report_title ?>";
            const gymName = "<?= htmlspecialchars($gym['gym_name']) ?>";
            const gymAddress = "<?= htmlspecialchars(($gym_address['address_line'] ?? '') . ', ' . ($gym_address['city'] ?? '')) ?>";
            const gymContact = "<?= htmlspecialchars(($gym['contact_number'] ?? '') . ' | ' . ($gym['gym_email'] ?? '')) ?>";
            const generatedAt = "<?= date('M d, Y') ?>";

            // Create formal wrapper
            const wrapper = document.createElement('div');
            wrapper.style.padding = '40px';
            wrapper.style.color = '#000';
            wrapper.style.backgroundColor = '#fff';
            wrapper.style.fontFamily = "'Lexend', sans-serif";

            const header = `
                <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 5px;">
                    <div style="text-align: left;">
                        <h1 style="font-size: 24px; font-weight: 800; color: #000; margin: 0; text-transform: uppercase;">${gymName}</h1>
                    </div>
                    <div style="text-align: right;">
                        <h2 style="font-size: 14px; font-weight: 700; color: #000; margin: 0; text-transform: uppercase;">${reportTypeLabel}</h2>
                    </div>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 15px; font-size: 9px; line-height: 1.5;">
                    <div style="text-align: left; color: #333;">
                        <p style="margin: 0;">${gymAddress}</p>
                        <p style="margin: 0;">${gymContact}</p>
                    </div>
                    <div style="text-align: right; color: #333;">
                        <p style="margin: 0;">Date: ${generatedAt}</p>
                        <p style="margin: 0; font-weight: bold;">OFFICIAL SYSTEM REPORT</p>
                    </div>
                </div>
                <div style="border-bottom: 1.5px solid #000; margin-bottom: 30px;"></div>
            `;

            const contentClone = tableElement.cloneNode(true);
            contentClone.querySelectorAll('button, .no-print, span.material-symbols-outlined').forEach(el => el.remove());

            [contentClone, ...contentClone.querySelectorAll('*')].forEach(el => {
                el.removeAttribute('class');
                el.style.setProperty('color', '#000000', 'important');
                el.style.setProperty('background-color', 'transparent', 'important');
                el.style.setProperty('border-radius', '0', 'important');
                el.style.setProperty('box-shadow', 'none', 'important');
                el.style.setProperty('opacity', '1', 'important');
                el.style.setProperty('font-family', "'Lexend', sans-serif", 'important');
            });

            const table = contentClone.querySelector('table');
            if (table) {
                table.style.setProperty('width', '100%', 'important');
                table.style.setProperty('border-collapse', 'collapse', 'important');
                table.style.setProperty('font-size', '10px', 'important');
                table.style.setProperty('border', '1px solid #000', 'important');

                table.querySelectorAll('th').forEach(th => {
                    th.style.setProperty('background-color', '#f5f5f5', 'important');
                    th.style.setProperty('border', '1px solid #000', 'important');
                    th.style.setProperty('padding', '8px', 'important');
                    th.style.setProperty('text-align', 'left', 'important');
                    th.style.setProperty('font-weight', '700', 'important');
                    th.style.setProperty('text-transform', 'uppercase', 'important');
                });

                table.querySelectorAll('td').forEach(td => {
                    td.style.setProperty('border', '1px solid #eee', 'important');
                    td.style.setProperty('padding', '6px 8px', 'important');
                });

                table.querySelectorAll('tfoot td').forEach(td => {
                    td.style.setProperty('font-weight', 'bold', 'important');
                    td.style.setProperty('background-color', '#fafafa', 'important');
                    td.style.setProperty('border-top', '2px solid #000', 'important');
                });
            }

            const footer = document.createElement('div');
            footer.style.marginTop = '40px';
            footer.style.textAlign = 'center';
            footer.style.fontSize = '8px';
            footer.style.color = '#777';
            footer.style.borderTop = '1px solid #eee';
            footer.style.paddingTop = '10px';
            footer.innerHTML = `<p style="margin: 0; font-weight: bold;">INTERNAL USE ONLY</p>`;

            wrapper.innerHTML = header;
            wrapper.appendChild(contentClone);
            wrapper.appendChild(footer);

            const opt = {
                margin: [0.4, 0.4],
                filename: `Report_${reportTypeLabel.replace(/\s+/g, '_')}_${new Date().toISOString().split('T')[0]}.pdf`,
                image: { type: 'jpeg', quality: 1.0 },
                html2canvas: { scale: 3, backgroundColor: '#ffffff', useCORS: true },
                jsPDF: { unit: 'in', format: 'a4', orientation: 'portrait' }
            };

            if (preview) {
                html2pdf().set(opt).from(wrapper).toPdf().get('pdf').then(function (pdf) {
                    window.open(pdf.output('bloburl'), '_blank');
                });
            } else {
                html2pdf().set(opt).from(wrapper).save();
            }
        }
    </script>
</head>

<body class="antialiased flex h-screen overflow-hidden">

    <nav class="side-nav flex flex-col fixed left-0 top-0 h-screen bg-background-dark border-r border-white/5 no-print">
        <div class="px-7 py-8 mb-4 shrink-0">
            <div class="flex items-center gap-4">
                <div
                    class="size-10 rounded-xl bg-primary flex items-center justify-center shadow-lg shrink-0 overflow-hidden">
                    <?php if (!empty($tenant_config['logo_path'])):
                        $logo_src = (strpos($tenant_config['logo_path'], 'data:image') === 0) ? $tenant_config['logo_path'] : '../' . $tenant_config['logo_path'];
                        ?>
                        <img src="<?= $logo_src ?>" class="size-full object-contain">
                    <?php else: ?>
                        <span class="material-symbols-outlined text-white text-2xl">bolt</span>
                    <?php endif; ?>
                </div>
                <h1 class="nav-label text-lg font-black italic uppercase tracking-tighter text-white">Staff Portal</h1>
            </div>
        </div>
        <div class="flex-1 overflow-y-auto no-scrollbar space-y-1">
            <div class="nav-section-label px-[38px] mb-2"><span
                    class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Overview</span></div>
            <a href="admin_dashboard.php" class="nav-item"><span
                    class="material-symbols-outlined text-xl shrink-0">grid_view</span><span
                    class="nav-label">Dashboard</span></a>
            <a href="register_member.php" class="nav-item"><span
                    class="material-symbols-outlined text-xl shrink-0">person_add</span><span class="nav-label">Walk-in
                    Member</span></a>
            <div class="nav-section-label px-[38px] mb-2 mt-6"><span
                    class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Management</span></div>
            <a href="admin_users.php" class="nav-item"><span
                    class="material-symbols-outlined text-xl shrink-0">group</span><span class="nav-label">My
                    Users</span></a>
            <a href="admin_transaction.php" class="nav-item"><span
                    class="material-symbols-outlined text-xl shrink-0">receipt_long</span><span
                    class="nav-label">Transactions</span></a>
            <a href="admin_appointment.php" class="nav-item"><span
                    class="material-symbols-outlined text-xl shrink-0">event_note</span><span
                    class="nav-label">Bookings</span></a>
            <a href="admin_attendance.php" class="nav-item"><span
                    class="material-symbols-outlined text-xl shrink-0">history</span><span
                    class="nav-label">Attendance</span></a>
            <a href="admin_report.php" class="nav-item active"><span
                    class="material-symbols-outlined text-xl shrink-0">description</span><span
                    class="nav-label">System Reports</span></a>
        </div>
        <div class="mt-auto pt-4 border-t border-white/10 shrink-0 pb-6">
            <div class="nav-section-label px-[38px] mb-2"><span
                    class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Account</span></div>
            <a href="admin_profile.php" class="nav-item text-gray-400 hover:text-white"><span
                    class="material-symbols-outlined text-xl shrink-0">account_circle</span><span
                    class="nav-label">Profile</span></a>
            <a href="../logout.php" class="nav-item text-gray-400 hover:text-rose-500 transition-colors group">
                <span
                    class="material-symbols-outlined text-xl shrink-0 group-hover:translate-x-1 transition-transform">logout</span>
                <span class="nav-label whitespace-nowrap">Sign Out</span>
            </a>
        </div>
    </nav>

    <div class="main-content flex-1 overflow-y-auto no-scrollbar">
        <main class="p-10 max-w-[1500px] mx-auto pb-20">
            <header class="mb-12 flex flex-row justify-between items-end gap-6">
                <div>
                    <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white leading-none">System <span class="text-primary">Reports</span>
                    </h2>
                    <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-2 px-1 opacity-60">Analytics
                        & Insights</p>
                </div>
                <div class="flex items-end gap-8 text-right shrink-0 no-print">
                    <div class="flex flex-col items-end">
                        <p id="headerClock" class="text-white font-black italic text-2xl leading-none tracking-tighter">
                            00:00:00 AM</p>
                        <p class="text-primary text-[10px] font-black uppercase tracking-[0.2em] leading-none mt-2">
                            <?= date('l, M d, Y') ?></p>
                    </div>
                </div>
            </header>

            <!-- Revenue Summary Strip -->
            <div
                class="mb-10 p-8 rounded-[32px] bg-gradient-to-br from-primary/10 via-primary/5 to-transparent border border-primary/20 flex flex-col md:flex-row items-center justify-between gap-8 relative overflow-hidden group">
                <div class="relative z-10 text-center md:text-left">
                    <p class="text-[10px] font-black uppercase tracking-[0.2em] text-primary/60 mb-2">Total Revenue (All
                        Time)</p>
                    <h3 class="text-4xl font-black italic text-white flex items-center gap-4 py-1">
                        <span class="text-primary opacity-50 text-2xl">₱</span>
                        <?= number_format($lifetime_total, 2) ?>
                    </h3>
                    <p class="text-[9px] font-bold text-gray-500 uppercase tracking-widest mt-2">Overall Income Summary
                    </p>
                </div>
                <div class="flex items-center gap-3 no-print relative z-10">
                    <div
                        class="size-14 rounded-2xl bg-primary/10 border border-primary/20 flex items-center justify-center text-primary group-hover:scale-110 transition-transform">
                        <span class="material-symbols-outlined text-3xl">analytics</span>
                    </div>
                </div>
            </div>

            <!-- tab Switcher -->
            <div
                class="flex items-center gap-2 mb-10 border-b border-white/5 no-print uppercase font-black text-[11px] tracking-widest">
                <a href="?tab=financials&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>"
                    class="tab-btn <?= ($tab === 'financials') ? 'active' : '' ?>">Financial Report</a>
                <a href="?tab=membership&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>"
                    class="tab-btn <?= ($tab === 'membership') ? 'active' : '' ?>">Subscription Report</a>
                <a href="?tab=attendance&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>"
                    class="tab-btn <?= ($tab === 'attendance') ? 'active' : '' ?>">Attendance Report</a>
            </div>

            <!-- Filter Matrix -->
            <div class="mb-10 no-print">
                <form method="GET" class="glass-card p-8 border border-white/5">
                    <input type="hidden" name="tab" value="<?= $tab ?>">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-6 items-end">
                        <div class="space-y-2 lg:col-span-2">
                            <p class="text-[10px] font-black uppercase tracking-widest text-primary ml-1">SEARCH</p>
                            <div class="relative">
                                <span
                                    class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-gray-500 text-lg">search</span>
                                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                                    placeholder="Search name or ID..." class="input-box pl-12 w-full">
                            </div>
                        </div>
                        <div class="space-y-2 lg:col-span-1">
                            <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 ml-1">From</p>
                            <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>"
                                class="input-box w-full">
                        </div>
                        <div class="space-y-2 lg:col-span-1">
                            <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 ml-1">To</p>
                            <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>"
                                class="input-box w-full">
                        </div>
                        <!-- Print & Preview Buttons -->
                        <div class="lg:col-span-2 flex gap-3 h-[46px]">
                            <button type="submit"
                                class="flex-1 bg-white/5 border border-white/10 hover:bg-white/10 text-white rounded-xl text-[10px] font-black uppercase tracking-widest transition-all">Filter</button>
                            <button type="button" onclick="clearFilters()"
                                class="flex-1 bg-rose-500/10 border border-rose-500/20 text-rose-500 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all hover:bg-rose-500/20">Clear</button>
                            <button type="button" onclick="exportReportToPDF(true)"
                                class="flex-1 bg-white/10 border border-primary/20 text-primary rounded-xl text-[10px] font-black uppercase tracking-widest transition-all hover:bg-primary/20 flex items-center justify-center gap-2">
                                <span class="material-symbols-outlined text-[18px]">visibility</span> Preview
                            </button>
                            <button type="button" onclick="exportReportToPDF(false)"
                                class="flex-1 bg-primary text-white rounded-xl text-[10px] font-black uppercase tracking-widest transition-all hover:bg-primary/90 shadow-lg shadow-primary/20 flex items-center justify-center gap-2">
                                <span class="material-symbols-outlined text-[18px]">picture_as_pdf</span> Export
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Document Matrix -->
            <div id="reportTableContainer">
                <div class="flex justify-between items-center mb-6">
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-500 italic">Report Data</p>
                    <div class="flex items-center gap-4 no-print">
                        <span
                            class="px-4 py-1.5 rounded-full bg-white/5 border border-white/5 text-[9px] font-black uppercase tracking-widest text-gray-400">Total
                            Records: <?= count($query_data) ?></span>
                    </div>
                </div>

                <div class="glass-card shadow-2xl overflow-hidden border border-white/5">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead>
                                <tr
                                    class="text-[10px] font-black uppercase tracking-[0.15em] text-gray-600 border-b border-white/5 bg-white/[0.01]">
                                    <?php if ($tab === 'financials'): ?>
                                        <th class="px-6 py-5">Reference #</th>
                                        <th class="px-6 py-5">Member Name</th>
                                        <th class="px-6 py-5">Method</th>
                                        <th class="px-6 py-5">Note</th>
                                        <th class="px-6 py-5 text-right">Amount</th>
                                        <th class="px-6 py-5 text-right">Status</th>
                                    <?php elseif ($tab === 'membership'): ?>
                                        <th class="px-6 py-5">Member Name</th>
                                        <th class="px-6 py-5">Plan</th>
                                        <th class="px-6 py-5">Duration</th>
                                        <th class="px-6 py-5">Usage</th>
                                        <th class="px-6 py-5 text-right">Payment</th>
                                        <th class="px-6 py-5 text-right">Status</th>
                                    <?php elseif ($tab === 'attendance'): ?>
                                        <th class="px-6 py-5">Date</th>
                                        <th class="px-6 py-5">Member Name</th>
                                        <th class="px-6 py-5">Booking Ref</th>
                                        <th class="px-6 py-5">Times</th>
                                        <th class="px-6 py-5 text-right">Status</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/5">
                                <?php if (empty($query_data)): ?>
                                    <tr>
                                        <td colspan="7" class="px-8 py-24 text-center">
                                            <p
                                                class="text-[10px] font-black uppercase tracking-widest text-gray-500 italic">
                                                No records found.</p>
                                        </td>
                                    </tr>
                                <?php else:
                                    foreach ($query_data as $row): ?>
                                        <tr class="hover:bg-white/[0.02] group transition-colors">
                                            <?php if ($tab === 'financials'): ?>
                                                <td class="px-6 py-5">
                                                    <p class="text-[11px] font-black text-white uppercase italic tracking-tighter">
                                                        <?= htmlspecialchars($row['reference_number']) ?></p>
                                                    <p class="text-[9px] font-bold text-gray-600 uppercase mt-1">
                                                        <?= date('M d, Y', strtotime($row['created_at'])) ?></p>
                                                </td>
                                                <td class="px-6 py-5">
                                                    <p
                                                        class="text-[11px] font-black text-white uppercase group-hover:text-primary transition-colors">
                                                        <?= htmlspecialchars($row['fullname'] ?: $row['username']) ?></p>
                                                </td>
                                                <td class="px-6 py-5">
                                                    <span
                                                        class="text-[10px] font-bold text-gray-500 uppercase tracking-widest"><?= htmlspecialchars($row['payment_method']) ?></span>
                                                </td>
                                                <td class="px-6 py-5">
                                                    <span
                                                        class="px-3 py-1 rounded-lg bg-white/5 border border-white/10 text-[9px] font-black uppercase text-gray-400 italic"><?= $row['transaction_type'] ?></span>
                                                </td>
                                                <td class="px-6 py-5 text-right">
                                                    <span
                                                        class="text-emerald-400 font-black italic text-[13px]">₱<?= number_format($row['amount'], 2) ?></span>
                                                </td>
                                                <td class="px-6 py-5 text-right">
                                                    <?php
                                                    $ps = $row['payment_status'];
                                                    $ps_color = ($ps == 'Approved' || $ps == 'Paid') ? 'emerald' : (($ps == 'Pending') ? 'amber' : 'rose');
                                                    ?>
                                                    <span
                                                        class="px-3 py-1 rounded-full bg-<?= $ps_color ?>-500/10 border border-<?= $ps_color ?>-500/20 text-[9px] text-<?= $ps_color ?>-500 font-extrabold uppercase italic"><?= $ps ?></span>
                                                </td>
                                            <?php elseif ($tab === 'membership'): ?>
                                                <td class="px-6 py-5">
                                                    <p class="text-[11px] font-black text-white uppercase">
                                                        <?= htmlspecialchars($row['fullname']) ?></p>
                                                    <p class="text-[9px] font-bold text-gray-500 lowercase opacity-60 italic">
                                                        <?= htmlspecialchars($row['email']) ?></p>
                                                </td>
                                                <td class="px-6 py-5">
                                                    <span
                                                        class="text-primary font-black italic uppercase text-[11px] tracking-tight"><?= htmlspecialchars($row['plan_name']) ?></span>
                                                </td>
                                                <td class="px-6 py-5">
                                                    <div class="text-[10px] font-bold text-gray-500 uppercase">
                                                        <?= date('M d', strtotime($row['start_date'])) ?> -
                                                        <?= date('M d, Y', strtotime($row['end_date'])) ?>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-5">
                                                    <div class="flex items-center gap-2">
                                                        <span
                                                            class="text-[9px] font-black text-gray-500"><?= $row['sessions_used'] ?>/<?= $row['sessions_total'] ?>
                                                            Sessions</span>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-5 text-right">
                                                    <span
                                                        class="text-[10px] font-black italic uppercase <?= ($row['payment_status'] == 'Approved' || $row['payment_status'] == 'Paid') ? 'text-emerald-500' : 'text-amber-500' ?>"><?= $row['payment_status'] ?></span>
                                                </td>
                                                <td class="px-6 py-5 text-right">
                                                    <?php
                                                    $s = $row['subscription_status'];
                                                    $sc = ($s == 'Active') ? 'emerald' : (($s == 'Expiring') ? 'amber' : 'rose');
                                                    ?>
                                                    <span
                                                        class="px-3 py-1 rounded-full bg-<?= $sc ?>-500/10 border border-<?= $sc ?>-500/20 text-[9px] text-<?= $sc ?>-500 font-extrabold uppercase italic"><?= $s ?></span>
                                                </td>
                                            <?php elseif ($tab === 'attendance'): ?>
                                                <td class="px-6 py-5">
                                                    <p class="text-[11px] font-black text-white uppercase italic">
                                                        <?= date('M d, Y', strtotime($row['attendance_date'])) ?></p>
                                                </td>
                                                <td class="px-6 py-5">
                                                    <p class="text-[11px] font-black text-white uppercase tracking-tight">
                                                        <?= htmlspecialchars($row['fullname']) ?></p>
                                                    <p class="text-[10px] font-bold text-gray-600 tracking-tight lowercase">
                                                        @<?= htmlspecialchars($row['username']) ?></p>
                                                </td>
                                                <td class="px-6 py-5">
                                                    <span
                                                        class="px-3 py-1 rounded-lg bg-primary/5 border border-primary/10 text-[9px] font-black uppercase text-primary italic"><?= $row['booking_reference'] ?: 'Walk-in' ?></span>
                                                </td>
                                                <td class="px-6 py-5">
                                                    <div class="space-y-1">
                                                        <p
                                                            class="text-[10px] font-black text-white uppercase italic tracking-tighter">
                                                            In:
                                                            <?= date('h:i A', strtotime($row['attendance_date'] . ' ' . $row['check_in_time'])) ?>
                                                        </p>
                                                        <p class="text-[9px] font-bold text-gray-600 uppercase tracking-widest">Out:
                                                            <?= !empty($row['check_out_time']) ? date('h:i A', strtotime($row['attendance_date'] . ' ' . $row['check_out_time'])) : '---' ?>
                                                        </p>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-5 text-right">
                                                    <?php if (empty($row['check_out_time'])): ?>
                                                        <span
                                                            class="px-3 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-[9px] text-emerald-500 font-extrabold uppercase italic">In-Gym</span>
                                                    <?php else: ?>
                                                        <span
                                                            class="px-3 py-1 rounded-full bg-white/5 border border-white/10 text-[9px] text-gray-500 font-extrabold uppercase italic">Completed</span>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; endif; ?>
                            </tbody>
                            <?php if ($tab === 'financials'): ?>
                                <tfoot class="border-t-2 border-primary/30 bg-primary/5 shadow-inner">
                                    <tr class="font-black italic uppercase italic tracking-tighter">
                                        <td colspan="5" class="px-8 py-6 text-primary text-xs tracking-[0.2em]">Total:</td>
                                        <td class="px-8 py-6 text-right text-primary text-xl">
                                            ₱<?= number_format($filtered_total, 2) ?></td>
                                    </tr>
                                </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>

</html>