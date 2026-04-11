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

// Fetch Gym Details
$stmtGym = $pdo->prepare("SELECT * FROM gyms WHERE gym_id = ?");
$stmtGym->execute([$gym_id]);
$gym = $stmtGym->fetch();

// Active CMS Page (for logo & theme)
$stmtPage = $pdo->prepare("SELECT * FROM tenant_pages WHERE gym_id = ? LIMIT 1");
$stmtPage->execute([$gym_id]);
$page = $stmtPage->fetch();

// Fetch Coach ID (from staff table)
$stmtCoach = $pdo->prepare("SELECT staff_id as coach_id FROM staff WHERE user_id = ? AND gym_id = ? AND staff_role = 'Coach' LIMIT 1");
$stmtCoach->execute([$user_id, $gym_id]);
$coach = $stmtCoach->fetch();
$coach_id = $coach ? $coach['coach_id'] : 0;

$pending_count = 0;
if ($coach_id > 0) {
    $stmtPending = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE coach_id = ? AND booking_status = 'Pending'");
    $stmtPending->execute([$coach_id]);
    $pending_count = $stmtPending->fetchColumn();
}

// --- AJAX PROFILE FETCH ---
if (isset($_GET['ajax_user_id'])) {
    $target_uid = (int) $_GET['ajax_user_id'];
    
    // Fetch unique member data
    // Check if this member has booked with this coach
    $stmt = $pdo->prepare("
        SELECT u.*, m.*, a.address_line, a.barangay, a.city, a.province, a.region
        FROM users u 
        JOIN members m ON u.user_id = m.user_id 
        LEFT JOIN addresses a ON m.address_id = a.address_id
        JOIN bookings b ON m.member_id = b.member_id
        WHERE u.user_id = ? AND m.gym_id = ? AND b.coach_id = ? AND b.booking_status IN ('Approved', 'Pending', 'Confirmed', 'Completed')
        LIMIT 1
    ");
    $stmt->execute([$target_uid, $gym_id, $coach_id]);
    $u = $stmt->fetch();

    if (!$u && $target_uid >= 101) { // Mock support
        // This is a simple mock return for demo purposes
        $u = [
            'username' => 'sample_user', 'first_name' => 'Sample', 'middle_name' => 'Q.', 'last_name' => 'Member', 'email' => 'sample@example.com',
            'contact_number' => '09XXXXXXXXX', 'member_code' => 'M-XXXX', 'member_status' => 'Active',
            'birth_date' => '1990-01-01', 'sex' => 'N/A', 'occupation' => 'Professional', 'address' => 'Sample Address',
            'medical_history' => 'No medical history recorded in system.', 'emergency_contact_name' => 'Relative', 'emergency_contact_number' => '09XXXXXXXXX'
        ];
    }

    if ($u): ?>
        <div class="space-y-8 animate-in fade-in slide-in-from-bottom-6 duration-500 pb-2">
            <header class="flex justify-between items-center border-b border-white/5 pb-6">
                <div class="flex items-center gap-6">
                    <div class="size-16 rounded-2xl bg-primary/10 border border-primary/20 flex items-center justify-center text-primary font-black italic text-2xl uppercase">
                        <?= substr($u['first_name'], 0, 1) ?>
                    </div>
                    <div>
                        <h2 class="text-2xl font-black italic uppercase tracking-tighter text-white leading-none mb-1"><?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?></h2>
                        <span class="px-3 py-1 rounded-lg bg-emerald-500/10 text-emerald-500 text-[9px] font-black uppercase italic tracking-widest border border-emerald-500/10"><?= htmlspecialchars($u['member_status']) ?></span>
                    </div>
                </div>
                <button onclick="closeUserModal()" class="size-10 rounded-xl bg-white/5 hover:bg-white/10 flex items-center justify-center text-gray-400 transition-all">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </header>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <div class="space-y-8">
                    <section class="space-y-4">
                        <h4 class="text-[10px] font-black uppercase text-primary tracking-[0.2em] border-l-2 border-primary pl-3">Member Info</h4>
                        <div class="space-y-3">
                            <div class="bg-white/[0.02] p-4 rounded-2xl border border-white/5">
                                <p class="text-[8px] font-black uppercase text-gray-500 tracking-widest mb-1">Full Name</p>
                                <p class="text-sm font-bold text-white italic"><?= htmlspecialchars($u['first_name'] . ' ' . ($u['middle_name'] ? $u['middle_name'] . ' ' : '') . $u['last_name']) ?></p>
                            </div>
                            <div class="bg-white/[0.02] p-4 rounded-2xl border border-white/5">
                                <p class="text-[8px] font-black uppercase text-gray-500 tracking-widest mb-1">Home Address</p>
                                <p class="text-xs font-medium text-gray-300 leading-relaxed"><?= htmlspecialchars($u['address_line'] ?: 'No address listed') ?></p>
                            </div>
                        </div>
                    </section>

                    <section class="space-y-4">
                        <h4 class="text-[10px] font-black uppercase text-rose-500 tracking-[0.2em] border-l-2 border-rose-500 pl-3">Personal Details</h4>
                        <div class="grid grid-cols-2 gap-3">
                            <div class="bg-white/[0.02] p-4 rounded-2xl border border-white/5">
                                <p class="text-[8px] font-black uppercase text-gray-500 tracking-widest mb-1">Sex / Gender</p>
                                <p class="text-xs font-bold text-white uppercase italic"><?= $u['sex'] ?: 'N/A' ?></p>
                            </div>
                            <div class="bg-white/[0.02] p-4 rounded-2xl border border-white/5">
                                <p class="text-[8px] font-black uppercase text-gray-500 tracking-widest mb-1">Occupation</p>
                                <p class="text-xs font-bold text-white truncate"><?= $u['occupation'] ?: 'N/A' ?></p>
                            </div>
                            <div class="bg-white/[0.02] p-4 rounded-2xl border border-white/5 col-span-2">
                                <p class="text-[8px] font-black uppercase text-gray-500 tracking-widest mb-1">Birth Date</p>
                                <p class="text-xs font-bold text-white italic"><?= $u['birth_date'] ? date('M d, Y', strtotime($u['birth_date'])) : 'N/A' ?></p>
                            </div>
                        </div>
                    </section>
                </div>

                <div class="space-y-8">
                    <section class="space-y-4">
                        <h4 class="text-[10px] font-black uppercase text-primary tracking-[0.2em] border-l-2 border-primary pl-3">Contact Nodes</h4>
                        <div class="space-y-3">
                            <div class="bg-white/[0.02] p-4 rounded-2xl border border-white/5 flex items-center justify-between">
                                <div>
                                    <p class="text-[8px] font-black uppercase text-gray-500 tracking-widest mb-1">Mobile</p>
                                    <p class="text-sm font-bold text-white tracking-widest"><?= htmlspecialchars($u['contact_number'] ?: 'N/A') ?></p>
                                </div>
                                <span class="material-symbols-outlined text-gray-700">call</span>
                            </div>
                            <div class="bg-white/[0.02] p-4 rounded-2xl border border-white/5 flex items-center justify-between">
                                <div>
                                    <p class="text-[8px] font-black uppercase text-gray-500 tracking-widest mb-1">Email</p>
                                    <p class="text-sm font-bold text-white truncate max-w-[180px]"><?= htmlspecialchars($u['email']) ?></p>
                                </div>
                                <span class="material-symbols-outlined text-gray-700">mail</span>
                            </div>
                        </div>
                    </section>

                    <section class="space-y-4">
                        <h4 class="text-[10px] font-black uppercase text-amber-500 tracking-[0.2em] border-l-2 border-amber-500 pl-3">Emergency Contact</h4>
                        <div class="bg-amber-500/[0.02] p-5 rounded-2xl border border-amber-500/10 space-y-4">
                            <div>
                                <p class="text-[8px] font-black uppercase text-gray-500 tracking-widest mb-1">Person</p>
                                <p class="text-sm font-black text-white italic uppercase"><?= htmlspecialchars($u['emergency_contact_name'] ?: 'Not Listed') ?></p>
                            </div>
                            <div>
                                <p class="text-[8px] font-black uppercase text-gray-500 tracking-widest mb-1">Number</p>
                                <p class="text-sm font-bold text-amber-500 tracking-widest"><?= htmlspecialchars($u['emergency_contact_number'] ?: 'N/A') ?></p>
                            </div>
                        </div>
                    </section>

                    <section class="space-y-4">
                        <h4 class="text-[10px] font-black uppercase text-rose-500 tracking-[0.2em] border-l-2 border-rose-500 pl-3">Medical History</h4>
                        <div class="bg-rose-500/[0.02] p-5 rounded-2xl border border-rose-500/10 min-h-[80px]">
                            <p class="text-xs font-medium text-gray-400 italic leading-relaxed">
                                <?= nl2br(htmlspecialchars($u['medical_history'] ?: 'No notes listed.')) ?>
                            </p>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    <?php endif;
    exit;
}

// --- FILTERING & SORTING LOGIC ---
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$sort_by = $_GET['sort'] ?? 'recent';

$where_clauses = ["b.coach_id = ?", "m.gym_id = ?"];
$params = [$coach_id, $gym_id];

if (!empty($search)) {
    $where_clauses[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR m.member_code LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($status_filter)) {
    $where_clauses[] = "m.member_status = ?";
    $params[] = $status_filter;
}

$order_sql = "ORDER BY last_visit DESC";
if ($sort_by === 'name_asc') $order_sql = "ORDER BY u.first_name ASC";
if ($sort_by === 'name_desc') $order_sql = "ORDER BY u.first_name DESC";
if ($sort_by === 'oldest') $order_sql = "ORDER BY last_visit ASC";

$sql = "
    SELECT DISTINCT m.member_id, u.user_id, u.first_name, u.last_name, u.email, u.contact_number, m.member_code, m.member_status,
    (SELECT COUNT(*) FROM bookings WHERE member_id = m.member_id AND coach_id = ?) as session_count,
    (SELECT MAX(attendance_date) FROM attendance WHERE member_id = m.member_id) as last_visit,
    (SELECT workout_name FROM member_workouts WHERE member_id = m.member_id ORDER BY created_at DESC LIMIT 1) as workout_plan,
    (SELECT workout_status FROM member_workouts WHERE member_id = m.member_id ORDER BY created_at DESC LIMIT 1) as workout_status
    FROM members m
    JOIN users u ON m.user_id = u.user_id
    JOIN bookings b ON m.member_id = b.member_id
    WHERE b.booking_status IN ('Approved', 'Pending', 'Confirmed', 'Completed') AND " . implode(" AND ", $where_clauses) . "
    $order_sql
";

$params = array_merge([$coach_id], $params); // Add for subquery

$members = [];
if ($coach_id > 0) {
    $stmtMembers = $pdo->prepare($sql);
    $stmtMembers->execute($params);
    $members = $stmtMembers->fetchAll();
}

$active_page = "members";
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>My Members | Horizon Systems</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "<?= $page['theme_color'] ?? '#8c2bee' ?>", "background-dark": "<?= $page['bg_color'] ?? '#0a090d' ?>", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)" }}}
        }
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: <?= $page['bg_color'] ?? '#0a090d' ?>; color: white; display: flex; flex-direction: row; min-h-screen: 100vh; overflow: hidden; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        
        /* Sidebar Hover Logic */
        :root { --nav-width: 110px; }
        body:has(.sidebar-nav:hover) { --nav-width: 300px; }

        .sidebar-nav { 
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
            background: <?= $page['bg_color'] ?? '#0a090d' ?>; 
            border-right: 1px solid rgba(255,255,255,0.05); 
        }

        /* Main Content Adjustment */
        .main-content { 
            margin-left: var(--nav-width); 
            flex: 1; 
            min-width: 0; 
            transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1); 
            height: 100vh; 
            overflow-y: auto; 
        }

        .nav-text { opacity: 0; transform: translateX(-15px); transition: all 0.3s ease-in-out; white-space: nowrap; pointer-events: none; }
        .sidebar-nav:hover .nav-text { opacity: 1; transform: translateX(0); pointer-events: auto; }
        .nav-section-header { max-height: 0; opacity: 0; overflow: hidden; transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); margin: 0 !important; pointer-events: none; }
        .sidebar-nav:hover .nav-section-header { max-height: 20px; opacity: 1; margin-bottom: 8px !important; pointer-events: auto; }

        .nav-link { display: flex; align-items: center; gap: 16px; padding: 12px 38px; transition: all 0.2s ease; text-decoration: none; white-space: nowrap; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; }
        .nav-link:hover { background: rgba(255,255,255,0.05); color: white; }
        .active-nav { color: <?= $page['theme_color'] ?? '#8c2bee' ?> !important; position: relative; }
        .active-nav::after { content: ''; position: absolute; right: 0px; top: 50%; transform: translateY(-50%); width: 4px; height: 20px; background: <?= $page['theme_color'] ?? '#8c2bee' ?>; border-radius: 99px; }

        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        .filter-container { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 20px; padding: 20px; margin-bottom: 2rem; position: sticky; top: 0; z-index: 20; }
        .input-box { background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.05); border-radius: 12px; color: white; padding: 10px 16px; font-size: 12px; font-weight: 500; outline: none; transition: all 0.3s; width: 100%; }
        .input-box:focus { border-color: <?= $page['theme_color'] ?? '#8c2bee' ?>; background: rgba(140,43,238,0.05); }
        .input-box option { background: #14121a; color: white; }

        @keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .animate-slide-up { animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
        
        .member-card { transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1); }
        .member-card:hover { transform: translateY(-4px); border-color: <?= $page['theme_color'] ?? '#8c2bee' ?>33; background: rgba(255,255,255,0.03); }
        
        /* Table View Styles */
        .glass-table { width: 100%; border-collapse: collapse; }
        .glass-table tr { transition: all 0.3s; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .glass-table tr:hover { background: rgba(255,255,255,0.02); }
        .glass-table th { padding: 16px 32px; font-size: 10px; font-weight: 900; text-transform: uppercase; letter-spacing: 0.15em; color: #475569; text-align: left; background: rgba(255,255,255,0.01); }
        .glass-table td { padding: 16px 32px; font-size: 11px; }
        /* Flush Action Controls to the right */
        .glass-table th:last-child, .glass-table td:last-child { padding-right: 32px; text-align: right; width: 1%; white-space: nowrap; }

        .view-btn { padding: 10px; border-radius: 10px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.05); color: #475569; transition: all 0.2s; }
        .view-btn.active { background: <?= $page['theme_color'] ?? '#8c2bee' ?>; color: white; box-shadow: 0 4px 20px <?= $page['theme_color'] ?? '#8c2bee' ?>44; border-color: transparent; }
        
        #userModal { 
            position: fixed;
            inset: 0;
            z-index: 100;
            display: none; /* Controlled by JS */
            align-items: center;
            justify-content: center;
            padding: 24px;
            padding-left: calc(var(--nav-width) + 24px); /* Account for sidebar */
            transition: padding-left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .modal-backdrop {
            position: absolute;
            inset: 0;
            left: var(--nav-width); /* Blur starts after sidebar */
            background: rgba(0,0,0,0.7);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            transition: left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
    </style>
    <script>
        function updateHeaderClock() {
            const now = new Date();
            const clockEl = document.getElementById('headerClock');
            if (clockEl) clockEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }
        setInterval(updateHeaderClock, 1000);
        window.addEventListener('DOMContentLoaded', updateHeaderClock);

        function triggerFilter() { document.getElementById('filterForm').submit(); }

        async function viewUserProfile(userId) {
            const modal = document.getElementById('userModal');
            const content = document.getElementById('modalContent');
            modal.style.display = 'flex';
            content.innerHTML = '<div class="flex items-center justify-center p-20"><div class="size-12 border-4 border-primary/20 border-t-primary rounded-full animate-spin"></div></div>';
            try {
                const response = await fetch(`?ajax_user_id=${userId}`);
                const html = await response.text();
                content.innerHTML = html;
            } catch (error) { content.innerHTML = '<p class="text-red-500 font-bold text-center p-10">ERROR: FAILED TO FETCH PROFILE</p>'; }
        }

        function closeUserModal() {
            const modal = document.getElementById('userModal');
            modal.style.display = 'none';
        }

        function toggleView(view) {
            const grid = document.getElementById('memberGridContainer');
            const table = document.getElementById('memberTableContainer');
            const gridBtn = document.getElementById('gridViewBtn');
            const tableBtn = document.getElementById('tableViewBtn');

            if (view === 'grid') {
                grid.classList.remove('hidden');
                table.classList.add('hidden');
                gridBtn.classList.add('active');
                tableBtn.classList.remove('active');
            } else {
                grid.classList.add('hidden');
                table.classList.remove('hidden');
                gridBtn.classList.remove('active');
                tableBtn.classList.add('active');
            }
        }
    </script>
</head>
<body class="antialiased flex flex-row min-h-screen">

<nav class="sidebar-nav flex flex-col fixed left-0 top-0 h-screen z-50">
    <div class="px-7 py-8 mb-4">
        <div class="flex items-center gap-4">
            <div class="size-10 rounded-xl bg-primary flex items-center justify-center shadow-lg shadow-primary/20 shrink-0 overflow-hidden">
                <?php if (!empty($page['logo_path'])): 
                    $logo_src = (strpos($page['logo_path'], 'data:image') === 0) ? $page['logo_path'] : '../' . $page['logo_path'];
                ?>
                    <img src="<?= $logo_src ?>" class="size-full object-contain">
                <?php else: ?><span class="material-symbols-outlined text-white text-2xl" style="padding-left: 0;">bolt</span><?php endif; ?>
            </div>
            <span class="nav-text text-white font-black italic uppercase tracking-tighter text-lg leading-none">Coach Portal</span>
        </div>
    </div>
    
    <nav class="flex flex-col flex-1 overflow-y-auto no-scrollbar space-y-1">
        <div class="nav-section-header px-[38px]"><span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Main Menu</span></div>
        <a href="coach_dashboard.php" class="nav-link <?= ($active_page == 'dashboard') ? 'active-nav' : 'text-gray-400 hover:text-white' ?>"><span class="material-symbols-outlined text-xl shrink-0">grid_view</span><span class="nav-text">Dashboard</span></a>
        <a href="coach_schedule.php" class="nav-link <?= ($active_page == 'schedule') ? 'active-nav' : 'text-gray-400 hover:text-white' ?>"><span class="material-symbols-outlined text-xl shrink-0">edit_calendar</span><span class="nav-text">Schedule</span></a>
        <a href="coach_members.php" class="nav-link <?= ($active_page == 'members') ? 'active-nav' : 'text-gray-400 hover:text-white' ?>"><span class="material-symbols-outlined text-xl shrink-0">groups</span><span class="nav-text">My Members</span></a>
    </nav>

    <div class="mt-auto pt-6 border-t border-white/5 flex flex-col gap-1 shrink-0 pb-8 uppercase">
        <a href="coach_profile.php" class="nav-link <?= ($active_page == 'profile') ? 'active-nav' : 'text-gray-400 hover:text-white' ?>"><span class="material-symbols-outlined text-xl shrink-0">person</span><span class="nav-text">Profile</span></a>
        <a href="../logout.php" class="nav-link text-gray-400 hover:text-rose-500 group"><span class="material-symbols-outlined text-xl shrink-0 group-hover:translate-x-1 transition-transform">logout</span><span class="nav-text">Sign Out</span></a>
    </div>
</nav>

<div class="main-content flex flex-col no-scrollbar">
    <main class="flex-1 p-6 md:p-12 max-w-[1400px] w-full mx-auto">
        <header class="mb-10 flex flex-col md:flex-row justify-between items-start md:items-end gap-6">
            <div>
                <div class="flex items-center gap-3 mb-3">
                    <h1 class="text-3xl font-black italic uppercase tracking-tighter text-white leading-none">My <span class="text-primary">Members</span></h1>
                </div>
                <p class="text-gray-500 text-[10px] font-black uppercase tracking-[0.2em]">Manage your assigned members and training plans</p>
            </div>
            <div class="flex flex-row items-center gap-8 text-right">
                <div>
                    <p id="headerClock" class="text-white font-black italic text-2xl leading-none transition-colors hover:text-primary">00:00:00 AM</p>
                    <p class="text-primary text-[10px] font-black uppercase tracking-[0.2em] leading-none mt-2"><?= date('l, M d, Y') ?></p>
                </div>
            </div>
        </header>

        <section class="filter-container animate-slide-up">
            <form id="filterForm" method="GET" class="grid grid-cols-1 md:grid-cols-12 gap-6 items-end">
                <div class="md:col-span-5 space-y-2">
                    <label class="text-[9px] font-black uppercase tracking-[0.1em] text-gray-500 ml-1">Search Member</label>
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-gray-600 text-lg">search</span>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Type name or code..." class="input-box pl-12" oninput="debounce(triggerFilter, 500)">
                    </div>
                </div>
                
                <div class="md:col-span-2 space-y-2">
                    <label class="text-[9px] font-black uppercase tracking-[0.1em] text-gray-500 ml-1">Filter Status</label>
                    <select name="status" class="input-box pr-10" onchange="triggerFilter()">
                        <option value="">All Status</option>
                        <option value="Active" <?= $status_filter === 'Active' ? 'selected' : '' ?>>Active</option>
                        <option value="Pending" <?= $status_filter === 'Pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="Expired" <?= $status_filter === 'Expired' ? 'selected' : '' ?>>Expired</option>
                    </select>
                </div>

                <div class="md:col-span-3 space-y-2">
                    <label class="text-[9px] font-black uppercase tracking-[0.1em] text-gray-500 ml-1">Sort By</label>
                    <div class="flex gap-2">
                        <select name="sort" class="input-box pr-10 flex-1" onchange="triggerFilter()">
                            <option value="recent" <?= $sort_by === 'recent' ? 'selected' : '' ?>>Recent Visit</option>
                            <option value="oldest" <?= $sort_by === 'oldest' ? 'selected' : '' ?>>Oldest Record</option>
                            <option value="name_asc" <?= $sort_by === 'name_asc' ? 'selected' : '' ?>>Name A-Z</option>
                            <option value="name_desc" <?= $sort_by === 'name_desc' ? 'selected' : '' ?>>Name Z-A</option>
                        </select>
                        <a href="coach_members.php" class="size-[42px] rounded-xl bg-white/5 border border-white/5 flex items-center justify-center text-gray-500 hover:text-rose-500 transition-colors hover:bg-rose-500/10" title="Reset Filters"><span class="material-symbols-outlined text-xl">restart_alt</span></a>
                    </div>
                </div>

                <div class="md:col-span-2 space-y-2">
                    <label class="text-[9px] font-black uppercase tracking-[0.1em] text-gray-500 ml-1">View Layout</label>
                    <div class="flex gap-2">
                        <button type="button" id="gridViewBtn" onclick="toggleView('grid')" class="view-btn flex-1 active"><span class="material-symbols-outlined text-lg">grid_view</span></button>
                        <button type="button" id="tableViewBtn" onclick="toggleView('list')" class="view-btn flex-1"><span class="material-symbols-outlined text-lg">list</span></button>
                    </div>
                </div>
            </form>
        </section>

        <!-- Grid View Container -->
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6" id="memberGridContainer">
            <?php if(count($members) > 0): foreach($members as $m): ?>
            <div class="member-card glass-card p-7 flex flex-col gap-6 animate-slide-up">
                <!-- Top Section -->
                <div class="flex items-start justify-between">
                    <div class="flex items-center gap-5">
                        <div class="size-14 rounded-2xl bg-white/5 border border-white/5 flex items-center justify-center font-black text-primary text-xl shadow-inner"><?= strtoupper(substr($m['first_name'],0,1)) ?></div>
                        <div>
                            <h3 class="text-white font-black uppercase italic tracking-tight text-base"><?= htmlspecialchars($m['first_name'] . ' ' . $m['last_name']) ?></h3>
                            <div class="flex items-center gap-2 mt-1">
                                <p class="text-[9px] text-gray-600 font-black uppercase tracking-[0.2em]">Code: <?= htmlspecialchars($m['member_code']) ?></p>
                                <span class="size-1 bg-gray-700 rounded-full"></span>
                                <p class="text-[9px] text-primary font-black uppercase tracking-[0.2em]"><?= $m['session_count'] ?> Sessions</p>
                            </div>
                        </div>
                    </div>
                    <?php $sc = $m['member_status'] === 'Active' ? 'text-emerald-500 bg-emerald-500/10' : ($m['member_status'] === 'Pending' ? 'text-amber-500 bg-amber-500/10' : 'text-rose-500 bg-rose-500/10'); ?>
                    <span class="px-3 py-1.5 rounded-lg text-[9px] font-black uppercase tracking-widest border border-white/5 <?= $sc ?>"><?= $m['member_status'] ?></span>
                </div>

                <!-- Middle Section -->
                <div class="space-y-4 py-5 border-y border-white/5">
                    <div class="flex justify-between items-center">
                        <p class="text-[9px] font-black uppercase text-gray-600 tracking-widest">Workout Plan</p>
                        <p class="text-xs font-bold text-primary italic"><?= htmlspecialchars($m['workout_plan'] ?: 'No active plan') ?></p>
                    </div>
                    <div class="flex justify-between items-center">
                        <p class="text-[9px] font-black uppercase text-gray-600 tracking-widest">Last Visit</p>
                        <p class="text-xs font-bold text-gray-300"><?= $m['last_visit'] ? date('M d, Y', strtotime($m['last_visit'])) : 'No record' ?></p>
                    </div>
                    <div class="flex justify-between items-center">
                        <p class="text-[9px] font-black uppercase text-gray-600 tracking-widest">Contact Number</p>
                        <p class="text-xs font-bold text-gray-300"><?= htmlspecialchars($m['contact_number'] ?: 'N/A') ?></p>
                    </div>
                </div>

                <!-- Bottom Section -->
                <div class="flex items-center gap-3">
                    <a href="coach_workouts.php?member_id=<?= $m['member_id'] ?>" class="flex-1 py-3.5 rounded-xl bg-white/5 border border-white/5 hover:bg-primary text-white text-[10px] font-black uppercase tracking-widest transition-all text-center">Manage Workouts</a>
                    <button onclick="viewUserProfile(<?= $m['user_id'] ?>)" class="size-12 rounded-xl bg-white/5 border border-white/5 flex items-center justify-center hover:bg-primary/20 hover:border-primary/40 transition-all text-gray-400 hover:text-primary" title="View Profile">
                        <span class="material-symbols-outlined text-lg">visibility</span>
                    </button>
                </div>
            </div>
            <?php endforeach; else: ?><div class="col-span-full py-20 text-center opacity-40 italic text-sm">No members found.</div><?php endif; ?>
        </div>

        <!-- Table View Container -->
        <div class="hidden glass-card border border-white/5 overflow-hidden animate-slide-up" id="memberTableContainer">
            <div class="overflow-x-auto">
                <table class="glass-table text-left">
                    <thead>
                        <tr class="border-b border-white/5">
                            <th>Member Name</th>
                            <th>Workout Plan</th>
                            <th>Workout Status</th>
                            <th>Last Visit</th>
                            <th class="text-right">Action Controls</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <?php if(count($members) > 0): foreach($members as $m): ?>
                        <tr>
                            <td>
                                <div class="flex items-center gap-4">
                                    <div class="size-10 rounded-xl bg-primary/10 border border-primary/20 flex items-center justify-center text-primary font-bold text-sm shadow-inner"><?= substr($m['first_name'],0,1) ?></div>
                                    <div class="flex flex-col">
                                        <span class="font-bold text-white italic"><?= htmlspecialchars($m['first_name'] . ' ' . $m['last_name']) ?></span>
                                        <span class="text-[8px] text-primary font-black uppercase tracking-widest mt-0.5"><?= $m['session_count'] ?> Sessions with You</span>
                                    </div>
                                </div>
                            </td>
                            <td class="text-gray-300 text-xs font-bold"><?= htmlspecialchars($m['workout_plan'] ?: 'None') ?></td>
                            <td>
                                <?php 
                                    $ws = $m['workout_status'] ?: 'None';
                                    $w_class = ($ws === 'In Progress') ? 'text-primary bg-primary/10' : (($ws === 'Completed') ? 'text-emerald-500 bg-emerald-500/10' : 'text-gray-500 bg-white/5');
                                ?>
                                <span class="px-3 py-1.5 rounded-lg text-[8px] font-black uppercase tracking-widest border border-white/5 <?= $w_class ?>"><?= $ws ?></span>
                            </td>
                            <td class="text-gray-400 text-xs font-medium"><?= $m['last_visit'] ? date('M d, Y', strtotime($m['last_visit'])) : 'No record' ?></td>
                            <td>
                                <div class="flex justify-end gap-3">
                                    <button onclick="viewUserProfile(<?= $m['user_id'] ?>)" class="size-10 rounded-xl bg-white/5 border border-white/5 flex items-center justify-center text-gray-400 hover:text-primary transition-all active:scale-90" title="View Profile"><span class="material-symbols-outlined text-lg">visibility</span></button>
                                    <a href="coach_workouts.php?member_id=<?= $m['member_id'] ?>" class="h-10 px-6 rounded-xl bg-primary text-white text-[10px] font-black uppercase italic tracking-widest flex items-center transition-all hover:bg-primary/90 shadow-lg shadow-primary/20 active:scale-95">Manage</a>
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

<!-- Clean & Compact Profile Modal -->
<div id="userModal">
    <div class="modal-backdrop" onclick="closeUserModal()"></div>
    <div class="relative w-full max-w-2xl max-h-[90vh] overflow-y-auto no-scrollbar glass-card border-white/10 shadow-[0_0_100px_rgba(0,0,0,0.5)] backdrop-blur-3xl p-8 animate-in fade-in zoom-in duration-300">
        <div id="modalContent"></div>
    </div>
</div>

<script>
    let dbt;
    function debounce(f, d) { clearTimeout(dbt); dbt = setTimeout(f, d); }
</script>
</body>
</html>