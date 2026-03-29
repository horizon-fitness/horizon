<?php
session_start();
require_once '../db.php';

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

$active_page = 'staff';

// --- ADD STAFF LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_staff') {
    $fname = $_POST['first_name'] ?? '';
    $lname = $_POST['last_name'] ?? '';
    $email = $_POST['email'] ?? '';
    $contact = $_POST['contact_number'] ?? '0000000000';
    $role = $_POST['role'] ?? 'Coach';
    $employment = $_POST['employment'] ?? 'FULL-TIME';
    $password = $_POST['password'] ?? '';
    $confirm_pass = $_POST['confirm_password'] ?? '';

    if (empty($password) || $password !== $confirm_pass) {
        $error = "Passwords do not match or are empty!";
    } else {
        $username = strtolower($fname . '.' . $lname . rand(10, 99));
        $pass_hash = password_hash($password, PASSWORD_DEFAULT);

        try {
            $pdo->beginTransaction();

            // 1. Insert into Users
            $stmtUser = $pdo->prepare("INSERT INTO users (username, email, password_hash, first_name, last_name, contact_number, is_verified, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 1, 1, NOW(), NOW())");
            $stmtUser->execute([$username, $email, $pass_hash, $fname, $lname, $contact]);
            $new_user_id = $pdo->lastInsertId();

            // 2. Insert into Staff
            $stmtStaffAdd = $pdo->prepare("INSERT INTO staff (user_id, gym_id, staff_role, employment_type, hire_date, status, created_at, updated_at) VALUES (?, ?, ?, ?, CURRENT_DATE, 'Active', NOW(), NOW())");
            $stmtStaffAdd->execute([$new_user_id, $gym_id, $role, $employment]);

            $pdo->commit();
            header("Location: staff.php?success=1");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error adding staff: " . $e->getMessage();
        }
    }
}

// Fetch Gym Details
$stmtGym = $pdo->prepare("SELECT gym_name, profile_picture as logo_path FROM gyms WHERE gym_id = ?");
$stmtGym->execute([$gym_id]);
$gym = $stmtGym->fetch();

// Fetch Branding Data
$stmtPage = $pdo->prepare("SELECT * FROM tenant_pages WHERE gym_id = ?");
$stmtPage->execute([$gym_id]);
$page = $stmtPage->fetch();

$theme_color = ($page && isset($page['theme_color'])) ? $page['theme_color'] : '#8c2bee';
$bg_color = ($page && isset($page['bg_color'])) ? $page['bg_color'] : '#0a090d';

// Fetch Statistics
$stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM staff WHERE gym_id = ?");
$stmtTotal->execute([$gym_id]);
$total_staff = (int) $stmtTotal->fetchColumn();

$stmtActive = $pdo->prepare("SELECT COUNT(*) FROM staff WHERE gym_id = ? AND status = 'Active'");
$stmtActive->execute([$gym_id]);
$active_personnel = (int) $stmtActive->fetchColumn();

// Fetch Staff List
$search = $_GET['search'] ?? '';
$f_role = $_GET['f_role'] ?? '';
$f_status = $_GET['f_status'] ?? '';

$where = "s.gym_id = :gym_id";
$params = [':gym_id' => $gym_id];

if (!empty($search)) {
    $where .= " AND (u.first_name LIKE :s OR u.last_name LIKE :s OR s.staff_role LIKE :s)";
    $params[':s'] = "%$search%";
}

if (!empty($f_role)) {
    $where .= " AND s.staff_role = :role";
    $params[':role'] = $f_role;
}

if (!empty($f_status)) {
    $where .= " AND s.status = :status";
    $params[':status'] = $f_status;
}

$stmtStaff = $pdo->prepare("
    SELECT s.*, u.first_name, u.last_name, u.email, u.profile_picture
    FROM staff s
    JOIN users u ON s.user_id = u.user_id
    WHERE $where
    ORDER BY s.created_at DESC
");
$stmtStaff->execute($params);
$staff_list = $stmtStaff->fetchAll();

// Fetch Distinct Roles for Filter
$stmtRoles = $pdo->prepare("SELECT DISTINCT staff_role FROM staff WHERE gym_id = ?");
$stmtRoles->execute([$gym_id]);
$roles = $stmtRoles->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html class="dark" lang="en">

<head>
    <meta charset="utf-8">
    <title>Team Management | Horizon</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap"
        rel="stylesheet" />
    <link
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200"
        rel="stylesheet" />
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "<?= $theme_color ?>", "background-dark": "<?= $bg_color ?>", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)" } } }
        }
    </script>
    <style>
        body {
            font-family: 'Lexend', sans-serif;
            background-color:
                <?= $bg_color ?>
            ;
            color: white;
            overflow: hidden;
            color-scheme: dark;
        }

        .glass-card {
            background: #14121a;
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 24px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .hover-lift:hover {
            transform: translateY(-10px);
            border-color:
                <?= $theme_color ?>
                40;
            box-shadow: 0 20px 40px -20px
                <?= $theme_color ?>
                30;
        }

        /* Sidebar Styling */
        .side-nav {
            width: 110px;
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            position: fixed;
            left: 0;
            top: 0;
            height: 100vh;
            z-index: 150;
            background-color:
                <?= $bg_color ?>
            ;
            border-right: 1px solid rgba(255, 255, 255, 0.05);
        }

        .side-nav:hover {
            width: 300px;
        }

        .main-content {
            margin-left: 110px;
            flex: 1;
            min-width: 0;
            transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .side-nav:hover~.main-content {
            margin-left: 300px;
        }

        .nav-label {
            opacity: 0;
            transform: translateX(-15px);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
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
            transform: translateX(-15px);
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            margin: 0 !important;
            pointer-events: none;
        }

        .side-nav:hover .nav-section-label {
            max-height: 20px;
            opacity: 1;
            transform: translateX(0);
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
                <?= $theme_color ?>
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
                <?= $theme_color ?>
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

        /* Modal Overlay Shift with Sidebar */
        .modal-overlay {
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(8px);
            display: none;
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            left: 110px;
            z-index: 100;
            align-items: center;
            justify-content: center;
            padding: 20px;
            transition: left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .side-nav:hover~.modal-overlay {
            left: 300px;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-content {
            background: #14121a;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 24px;
            width: 100%;
            max-width: 500px;
            transform: scale(0.95);
            transition: all 0.3s ease-in-out;
        }

        .modal-overlay.active .modal-content {
            transform: scale(1);
        }

        /* Filter Inputs */
        .filter-input {
            background: rgba(255, 255, 255, 0.03) !important;
            border: 1px solid rgba(255, 255, 255, 0.08) !important;
            border-radius: 12px;
            padding: 12px 18px;
            color: white !important;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            outline: none;
            transition: all 0.3s ease;
            font-style: italic;
        }

        .filter-input option {
            background-color: #1a1625;
            color: white;
        }

        .filter-input:focus {
            border-color:
                <?= $theme_color ?>
                !important;
            background: rgba(255, 255, 255, 0.05) !important;
            box-shadow: 0 0 20px
                <?= $theme_color ?>
                20;
        }

        select.filter-input {
            appearance: none;
            cursor: pointer;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='white'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7' /%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 12px;
            padding-right: 40px;
        }

        /* Password Strength & Visibility */
        .strength-bar {
            height: 4px;
            border-radius: 2px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            width: 0;
        }

        .strength-weak {
            background: #f43f5e;
            width: 33%;
        }

        .strength-medium {
            background: #fbbf24;
            width: 66%;
        }

        .strength-strong {
            background: #10b981;
            width: 100%;
        }

        .pass-container {
            position: relative;
        }

        .view-toggle {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #64748b;
            font-size: 18px !important;
            transition: all 0.3s ease;
        }

        .view-toggle:hover {
            color: white;
        }
    </style>
</head>

<body class="flex h-screen overflow-hidden">

    <nav class="side-nav">
        <div class="px-7 py-8 mb-4 shrink-0">
            <div class="flex items-center gap-4">
                <div
                    class="size-10 rounded-xl shrink-0 overflow-hidden flex items-center justify-center <?= empty($gym['logo_path']) ? 'bg-primary shadow-lg shadow-primary/20' : '' ?>">
                    <?php if (!empty($gym['logo_path'])): ?>
                        <img src="<?= htmlspecialchars($gym['logo_path']) ?>" class="size-full object-cover">
                    <?php else: ?>
                        <span class="material-symbols-outlined text-white text-2xl">bolt</span>
                    <?php endif; ?>
                </div>
                <h1 class="nav-label text-lg font-black italic uppercase tracking-tighter text-white">Owner Portal</h1>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto no-scrollbar space-y-1">
            <div class="nav-section-label px-[38px] mb-2"><span
                    class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Main Menu</span></div>
            <a href="tenant_dashboard.php" class="nav-item">
                <span class="material-symbols-outlined text-xl shrink-0">grid_view</span>
                <span class="nav-label">Dashboard</span>
            </a>

            <a href="my_users.php" class="nav-item">
                <span class="material-symbols-outlined text-xl shrink-0">group</span>
                <span class="nav-label">Users</span>
            </a>

            <a href="transactions.php" class="nav-item">
                <span class="material-symbols-outlined text-xl shrink-0">receipt_long</span>
                <span class="nav-label">Transactions</span>
            </a>

            <a href="attendance.php" class="nav-item">
                <span class="material-symbols-outlined text-xl shrink-0">history</span>
                <span class="nav-label">Attendance</span>
            </a>

            <div class="nav-section-label px-[38px] mb-2 mt-6"><span
                    class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Management</span></div>

            <a href="staff.php" class="nav-item active">
                <span class="material-symbols-outlined text-xl shrink-0">badge</span>
                <span class="nav-label">Staff</span>
            </a>

            <a href="reports.php" class="nav-item">
                <span class="material-symbols-outlined text-xl shrink-0">analytics</span>
                <span class="nav-label">Reports</span>
            </a>

            <a href="sales_report.php" class="nav-item">
                <span class="material-symbols-outlined text-xl shrink-0">payments</span>
                <span class="nav-label">Sales Reports</span>
            </a>
        </div>

        <div class="mt-auto pt-4 border-t border-white/10 shrink-0 pb-6">
            <div class="nav-section-label px-[38px] mb-2"><span
                    class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Account</span></div>
            <a href="tenant_settings.php" class="nav-item">
                <span class="material-symbols-outlined text-xl shrink-0">settings</span>
                <span class="nav-label">Settings</span>
            </a>
            <a href="profile.php" class="nav-item">
                <span class="material-symbols-outlined text-xl shrink-0">account_circle</span>
                <span class="nav-label">Profile</span>
            </a>
            <a href="../logout.php" class="nav-item text-gray-400 hover:text-rose-500 transition-colors">
                <span class="material-symbols-outlined text-xl shrink-0">logout</span>
                <span class="nav-label">Sign Out</span>
            </a>
        </div>
    </nav>

    <main class="main-content flex-1 p-10 overflow-y-auto no-scrollbar pb-10">
        <header class="mb-10 flex justify-between items-end">
            <div>
                <h2 class="text-3xl font-black uppercase tracking-tighter text-white italic">Staff <span
                        class="text-primary italic">Management</span></h2>
                <p class="text-gray-500 text-[10px] font-black uppercase tracking-widest mt-1 italic">
                    <?= htmlspecialchars($gym['gym_name'] ?? 'Horizon Gym') ?> Personnel Management
                </p>
            </div>

            <div class="text-right">
                <p id="topClock" class="text-white font-black italic text-2xl leading-none tracking-tighter">00:00:00 AM
                </p>
                <p class="text-[10px] font-bold uppercase tracking-widest mt-2 px-1 opacity-80 italic">
                    <span class="text-primary"><?= date('l, M d, Y') ?></span>
                </p>
            </div>
        </header>

        <?php if (isset($_GET['success'])): ?>
            <div
                class="mb-8 p-4 bg-emerald-500/10 border border-emerald-500/20 rounded-2xl flex items-center gap-3 animate-pulse">
                <span class="material-symbols-outlined text-emerald-500">check_circle</span>
                <p class="text-[10px] font-black uppercase tracking-widest text-emerald-400 italic">Personnel registered
                    successfully!</p>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="mb-8 p-4 bg-rose-500/10 border border-rose-500/20 rounded-2xl flex items-center gap-3">
                <span class="material-symbols-outlined text-rose-500">error_outline</span>
                <p class="text-[10px] font-black uppercase tracking-widest text-rose-400 italic">
                    <?= htmlspecialchars($error) ?>
                </p>
            </div>
        <?php endif; ?>

        <div class="flex items-center justify-between gap-6 mb-10">
            <div class="flex items-center gap-6">
                <div class="glass-card hover-lift px-8 py-4 flex items-center gap-4">
                    <div>
                        <p class="text-[11px] font-black uppercase text-gray-500 tracking-widest leading-none">Total
                            Staff</p>
                        <h3 class="text-xl font-black italic tracking-tighter text-white mt-1">
                            <?= number_format($total_staff) ?>
                        </h3>
                    </div>
                    <div class="w-px h-8 bg-white/10 mx-2"></div>
                    <div>
                        <p class="text-[11px] font-black uppercase text-gray-500 tracking-widest leading-none">Active
                            Now
                        </p>
                        <h3 class="text-xl font-black italic tracking-tighter text-emerald-400 mt-1">
                            <?= number_format($active_personnel) ?>
                        </h3>
                    </div>
                </div>
            </div>
        </div>

        <div class="glass-card p-6 mb-8 border border-white/5">
            <form method="GET" class="flex flex-wrap items-center gap-6">
                <div class="flex flex-col gap-1.5 flex-1 min-w-[200px]">
                    <label class="text-[11px] font-black uppercase text-gray-500 tracking-widest ml-1">Search
                        Member</label>
                    <div class="relative group">
                        <span
                            class="absolute left-4 top-1/2 -translate-y-1/2 material-symbols-outlined text-gray-500 text-sm group-focus-within:text-primary transition-colors">search</span>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                            placeholder="Name or Role..." class="filter-input w-full pl-12 italic">
                    </div>
                </div>

                <div class="flex flex-col gap-1.5">
                    <label class="text-[11px] font-black uppercase text-gray-500 tracking-widest ml-1">Filter by
                        Role</label>
                    <select name="f_role" class="filter-input w-48 italic">
                        <option value="">All Roles</option>
                        <?php foreach ($roles as $r): ?>
                            <option value="<?= htmlspecialchars($r) ?>" <?= $f_role === $r ? 'selected' : '' ?>>
                                <?= htmlspecialchars($r) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="flex flex-col gap-1.5">
                    <label class="text-[11px] font-black uppercase text-gray-500 tracking-widest ml-1">Filter by
                        Status</label>
                    <select name="f_status" class="filter-input w-40 italic">
                        <option value="">All Status</option>
                        <option value="Active" <?= $f_status === 'Active' ? 'selected' : '' ?>>Active</option>
                        <option value="Inactive" <?= $f_status === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>

                <div class="flex items-end gap-3 ml-auto mt-4 md:mt-0">
                    <button type="submit"
                        class="bg-primary hover:bg-opacity-90 text-white px-8 py-2.5 rounded-xl flex items-center gap-2 text-[10px] font-black uppercase italic tracking-widest shadow-lg shadow-primary/20 transition-all border border-primary/20">
                        Apply Filters
                    </button>
                    <a href="staff.php"
                        class="px-6 py-2.5 bg-white/5 text-gray-400 rounded-xl font-black italic uppercase tracking-widest text-[10px] hover:bg-white/10 transition-all">Clear</a>
                    <button type="button" onclick="toggleAddModal()"
                        class="bg-white/5 border border-white/10 hover:border-primary/50 text-white px-8 py-2.5 rounded-xl flex items-center gap-2 text-[10px] font-black uppercase italic tracking-widest transition-all">
                        <span class="material-symbols-outlined text-sm">person_add</span> Add New
                    </button>
                </div>
            </form>
        </div>

        <div class="glass-card overflow-hidden shadow-2xl">
            <div class="px-8 py-6 border-b border-white/5 flex justify-between items-center bg-white/[0.02]">
                <h4 class="font-black italic uppercase text-xs tracking-widest flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary">groups</span> Team Roster
                </h4>
                <div class="text-[10px] font-bold text-gray-500 italic uppercase">Showing <?= count($staff_list) ?>
                    Staff Members</div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr
                            class="text-[11px] font-black uppercase tracking-widest text-gray-500 border-b border-white/5">
                            <th class="px-8 py-5">Member Name</th>
                            <th class="px-8 py-5">Role</th>
                            <th class="px-8 py-5">Employment</th>
                            <th class="px-8 py-5 text-center">Status</th>
                            <th class="px-8 py-5 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <?php if (empty($staff_list)): ?>
                            <tr>
                                <td colspan="5"
                                    class="px-8 py-20 text-center text-gray-600 font-black italic uppercase text-xs">No
                                    staff members found matching your search.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($staff_list as $s): ?>
                                <tr class="hover:bg-white/[0.02] transition-all group">
                                    <td class="px-8 py-6 flex items-center gap-4">
                                        <?php
                                        $initials = strtoupper(substr($s['first_name'] ?? '', 0, 1) . substr($s['last_name'] ?? '', 0, 1));
                                        ?>
                                        <div
                                            class="size-14 rounded-2xl bg-white/5 border border-white/10 overflow-hidden flex items-center justify-center shadow-lg shadow-black/20">
                                            <?php if (!empty($s['profile_picture'])): ?>
                                                <img src="<?= htmlspecialchars('../' . $s['profile_picture']) ?>"
                                                    class="size-full object-cover"
                                                    onerror="this.outerHTML='<span class=\'text-gray-500 font-black italic text-sm tracking-tighter\'><?= $initials ?></span>'">
                                            <?php else: ?>
                                                <span
                                                    class="text-gray-500 font-black italic text-sm tracking-tighter"><?= $initials ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <p class="font-black italic uppercase tracking-tighter text-sm text-white">
                                                <?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?>
                                            </p>
                                            <p class="text-[10px] font-bold text-gray-500 uppercase">
                                                <?= htmlspecialchars($s['email']) ?>
                                            </p>
                                        </div>
                                    </td>
                                    <td class="px-8 py-6">
                                        <span
                                            class="px-3 py-1 rounded-lg bg-primary/5 border border-primary/10 text-primary text-[10px] font-black uppercase tracking-widest italic"><?= htmlspecialchars($s['staff_role']) ?></span>
                                    </td>
                                    <td class="px-8 py-6">
                                        <p class="text-[11px] font-black uppercase text-white tracking-tighter italic">
                                            <?= $s['employment_type'] ?>
                                        </p>
                                        <p class="text-[10px] text-gray-600 font-bold uppercase italic">Hired:
                                            <?= date('M d, Y', strtotime($s['hire_date'])) ?>
                                        </p>
                                    </td>
                                    <td class="px-8 py-6 text-center">
                                        <span
                                            class="px-3 py-1 rounded-lg text-[10px] font-black uppercase tracking-widest italic <?= $s['status'] === 'Active' ? 'bg-emerald-500/10 text-emerald-400' : 'bg-red-500/10 text-red-500' ?>">
                                            <?= $s['status'] ?>
                                        </span>
                                    </td>
                                    <td class="px-8 py-6 text-right">
                                        <div class="flex justify-end gap-2 outline-none">
                                            <button onclick="openViewModal(<?= htmlspecialchars(json_encode($s)) ?>)"
                                                class="size-9 rounded-xl bg-white/5 hover:bg-primary hover:text-white transition-all text-gray-500 border border-white/5 flex items-center justify-center shadow-lg group-hover:scale-110 active:scale-95">
                                                <span class="material-symbols-outlined text-sm">visibility</span>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- View Staff Modal -->
    <div id="viewStaffModal" class="modal-overlay">
        <div class="modal-content overflow-hidden max-w-[450px]">
            <div class="px-8 py-6 border-b border-white/5 flex justify-between items-center bg-white/[0.02]">
                <h4 class="font-black italic uppercase text-xs tracking-widest flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary">person</span> Personnel Details
                </h4>
                <button onclick="hideViewModal()" class="text-gray-500 hover:text-white transition-colors">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <div class="p-8 space-y-8">
                <div class="flex items-center gap-6">
                    <div id="view_avatar"
                        class="size-28 rounded-3xl bg-white/5 border-2 border-white/10 flex items-center justify-center overflow-hidden shadow-2xl shadow-primary/10 transition-all">
                        <!-- Avatar will be set by JS -->
                    </div>
                    <div class="space-y-2">
                        <h2 id="view_full_name"
                            class="text-3xl font-black italic uppercase tracking-tighter text-white leading-tight">
                            -</h2>
                        <span id="view_role_badge"
                            class="px-4 py-1.5 rounded-xl bg-primary/5 border border-primary/10 text-primary text-[11px] font-black uppercase tracking-widest italic">-</span>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-8">
                    <div class="space-y-1">
                        <label class="text-[11px] font-black uppercase text-gray-500 tracking-widest">Email
                            Address</label>
                        <p id="view_email" class="text-white font-bold text-xs truncate">-</p>
                    </div>
                    <div class="space-y-1">
                        <label class="text-[11px] font-black uppercase text-gray-500 tracking-widest">Contact
                            Number</label>
                        <p id="view_contact" class="text-white font-bold text-xs">-</p>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-8 pt-4 border-t border-white/5">
                    <div class="space-y-1">
                        <label class="text-[11px] font-black uppercase text-gray-500 tracking-widest">Employment</label>
                        <p id="view_employment" class="text-white font-black italic uppercase text-xs tracking-tighter">
                            -</p>
                    </div>
                    <div class="space-y-1">
                        <label class="text-[11px] font-black uppercase text-gray-500 tracking-widest">Hire Date</label>
                        <p id="view_hire_date" class="text-white font-bold text-xs uppercase italic">-</p>
                    </div>
                </div>

                <div class="flex items-center justify-between p-4 rounded-2xl bg-white/5 border border-white/5">
                    <span class="text-[11px] font-black uppercase text-gray-500 tracking-widest">Account Status</span>
                    <span id="view_status_badge"
                        class="px-3 py-1 rounded-lg text-[10px] font-black uppercase tracking-widest italic">-</span>
                </div>
            </div>
            <div class="px-8 py-6 bg-white/[0.02] border-t border-white/5 flex justify-end">
                <button onclick="hideViewModal()"
                    class="px-8 bg-white/5 text-gray-400 py-3 rounded-xl font-black italic uppercase tracking-widest text-[12px] hover:bg-white/10 transition-all">
                    Done
                </button>
            </div>
        </div>
    </div>

    <!-- Add Staff Modal -->
    <div id="addStaffModal" class="modal-overlay">
        <div class="modal-content overflow-hidden">
            <div class="px-8 py-6 border-b border-white/5 flex justify-between items-center bg-white/[0.02]">
                <h4 class="font-black italic uppercase text-xs tracking-widest flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary">person_add</span> Add New Member
                </h4>
                <button onclick="toggleAddModal()" class="text-gray-500 hover:text-white transition-colors">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <form method="POST" class="p-8 space-y-6">
                <input type="hidden" name="action" value="add_staff">
                <div class="grid grid-cols-2 gap-4">
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[11px] font-black uppercase text-gray-500 tracking-widest ml-1">First
                            Name</label>
                        <input type="text" name="first_name" required placeholder="e.g. John"
                            class="filter-input w-full">
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[11px] font-black uppercase text-gray-500 tracking-widest ml-1">Last
                            Name</label>
                        <input type="text" name="last_name" required placeholder="e.g. Doe" class="filter-input w-full">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[11px] font-black uppercase text-gray-500 tracking-widest ml-1">Email
                            Address</label>
                        <input type="email" name="email" required placeholder="e.g. john@horizon.com"
                            class="filter-input w-full">
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[11px] font-black uppercase text-gray-500 tracking-widest ml-1">Contact
                            Number</label>
                        <input type="text" name="contact_number" required placeholder="0917 123 4567"
                            class="filter-input w-full">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div class="flex flex-col gap-1.5">
                        <label
                            class="text-[11px] font-black uppercase text-gray-500 tracking-widest ml-1">Password</label>
                        <div class="pass-container">
                            <input type="password" id="reg_pass" name="password" required placeholder="••••••••"
                                oninput="checkPassStrength(this.value)" class="filter-input w-full pr-12">
                            <span class="material-symbols-outlined view-toggle"
                                onclick="togglePass('reg_pass', this)">visibility</span>
                        </div>
                        <div class="w-full bg-white/5 h-1 rounded-full mt-2 overflow-hidden">
                            <div id="strength-indicator" class="strength-bar"></div>
                        </div>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[11px] font-black uppercase text-gray-500 tracking-widest ml-1">Confirm
                            Password</label>
                        <div class="pass-container">
                            <input type="password" id="reg_confirm" name="confirm_password" required
                                placeholder="••••••••" class="filter-input w-full pr-12">
                            <span class="material-symbols-outlined view-toggle"
                                onclick="togglePass('reg_confirm', this)">visibility</span>
                        </div>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[11px] font-black uppercase text-gray-500 tracking-widest ml-1">Assigned
                            Role</label>
                        <select name="role" class="filter-input w-full">
                            <option value="Coach">Coach</option>
                            <option value="Admin">Admin</option>
                            <option value="Receptionist">Receptionist</option>
                            <option value="Manager">Manager</option>
                        </select>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label
                            class="text-[11px] font-black uppercase text-gray-500 tracking-widest ml-1">Employment</label>
                        <select name="employment" class="filter-input w-full">
                            <option value="FULL-TIME">Full-Time</option>
                            <option value="PART-TIME">Part-Time</option>
                            <option value="CONTRACT">Contract</option>
                        </select>
                    </div>
                </div>
                <div class="flex items-center gap-3 pt-4">
                    <button type="submit"
                        class="flex-1 bg-primary text-white py-3 rounded-xl font-black italic uppercase tracking-widest text-[12px] hover:bg-opacity-90 shadow-lg shadow-primary/20 transition-all">
                        Register Personnel
                    </button>
                    <button type="button" onclick="toggleAddModal()"
                        class="px-8 bg-white/5 text-gray-400 py-3 rounded-xl font-black italic uppercase tracking-widest text-[12px] hover:bg-white/10 transition-all">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function updateClock() {
            const now = new Date();
            const clock = document.getElementById('topClock');
            if (clock) clock.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }
        setInterval(updateClock, 1000);
        updateClock();

        function toggleAddModal() {
            const modal = document.getElementById('addStaffModal');
            modal.classList.toggle('active');
        }

        function openViewModal(s) {
            const modal = document.getElementById('viewStaffModal');

            // Set Avatar
            const avatarDiv = document.getElementById('view_avatar');
            const initials = (s.first_name[0] + s.last_name[0]).toUpperCase();
            
            if (s.profile_picture) {
                avatarDiv.innerHTML = `<img src="../${s.profile_picture}" class="size-full object-cover shadow-inner group-hover:scale-110 transition-transform duration-500" onerror="this.outerHTML='<span class=\\'text-gray-500 font-black italic text-4xl tracking-tighter\\'>${initials}</span>'">`;
            } else {
                avatarDiv.innerHTML = `<span class="text-gray-500 font-black italic text-4xl tracking-tighter">${initials}</span>`;
            }

            document.getElementById('view_full_name').innerText = s.first_name + ' ' + s.last_name;
            document.getElementById('view_role_badge').innerText = s.staff_role;
            document.getElementById('view_email').innerText = s.email;
            document.getElementById('view_contact').innerText = s.contact_number || 'N/A';
            document.getElementById('view_employment').innerText = s.employment_type;

            // Format Hire Date
            const hireDate = new Date(s.hire_date);
            const options = { year: 'numeric', month: 'short', day: 'numeric' };
            document.getElementById('view_hire_date').innerText = hireDate.toLocaleDateString('en-US', options);

            // Status Badge
            const statusBadge = document.getElementById('view_status_badge');
            statusBadge.innerText = s.status;
            statusBadge.className = 'px-3 py-1 rounded-lg text-[10px] font-black uppercase tracking-widest italic ' +
                (s.status === 'Active' ? 'bg-emerald-500/10 text-emerald-400' : 'bg-red-500/10 text-red-500');

            modal.classList.add('active');
        }

        function hideViewModal() {
            document.getElementById('viewStaffModal').classList.remove('active');
        }

        function togglePass(id, el) {
            const input = document.getElementById(id);
            if (input.type === 'password') {
                input.type = 'text';
                el.textContent = 'visibility_off';
            } else {
                input.type = 'password';
                el.textContent = 'visibility';
            }
        }

        function checkPassStrength(pass) {
            const indicator = document.getElementById('strength-indicator');
            let strength = 0;
            if (pass.length > 5) strength++;
            if (pass.length > 8) strength++;
            if (/[0-9]/.test(pass) && /[a-z]/.test(pass) && /[A-Z]/.test(pass)) strength++;
            if (/[^A-Za-z0-9]/.test(pass)) strength++;

            indicator.className = 'strength-bar';
            if (strength <= 1 && pass.length > 0) indicator.classList.add('strength-weak');
            else if (strength === 2) indicator.classList.add('strength-medium');
            else if (strength >= 3) indicator.classList.add('strength-strong');
        }
    </script>

</body>

</html>