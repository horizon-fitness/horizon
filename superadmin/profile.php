<?php
session_start();
require_once '../db.php';
require_once '../includes/audit_logger.php';

// Security Check: Only Superadmin can access
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'superadmin') {
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$success_msg = $_SESSION['success_msg'] ?? '';
$error_msg = $_SESSION['error_msg'] ?? '';
unset($_SESSION['success_msg'], $_SESSION['error_msg']);

// Helper function for Base64 conversion
function convertFileToBase64($fileInputName)
{
    if (isset($_FILES[$fileInputName]) && $_FILES[$fileInputName]['error'] == 0) {
        $tmpPath = $_FILES[$fileInputName]['tmp_name'];
        $fileType = $_FILES[$fileInputName]['type'];
        $fileData = file_get_contents($tmpPath);
        return 'data:' . $fileType . ';base64,' . base64_encode($fileData);
    }
    return null;
}

// Handle Profile & Password Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $current_password = $_POST['current_password'] ?? '';
    $username = trim($_POST['username']);
    $first_name = trim($_POST['first_name']);
    $middle_name = trim($_POST['middle_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $contact_number = trim($_POST['contact_number']);

    // New Fields
    $birth_date = trim($_POST['birth_date'] ?? '');
    $sex = trim($_POST['sex'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $shift_schedule = trim($_POST['shift_schedule'] ?? '');

    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    $now = date('Y-m-d H:i:s');

    try {
        // 1. Verify Current Password First
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $userData = $stmt->fetch();

        if (empty($current_password) || !password_verify($current_password, $userData['password_hash'])) {
            throw new Exception("Incorrect current password. Verification failed.");
        }

        $pdo->beginTransaction();

        // Fetch old values for audit
        $stmtOld = $pdo->prepare("SELECT username, first_name, middle_name, last_name, email, contact_number, birth_date, sex, address, shift_schedule, profile_picture FROM users WHERE user_id = ?");
        $stmtOld->execute([$user_id]);
        $old_values = $stmtOld->fetch(PDO::FETCH_ASSOC);

        $profile_picture = convertFileToBase64('profile_picture');

        // Build Update Query
        $updates = [
            "username = ?",
            "first_name = ?",
            "middle_name = ?",
            "last_name = ?",
            "email = ?",
            "contact_number = ?",
            "birth_date = ?",
            "sex = ?",
            "address = ?",
            "shift_schedule = ?",
            "updated_at = ?"
        ];
        $params = [$username, $first_name, $middle_name, $last_name, $email, $contact_number, $birth_date, $sex, $address, $shift_schedule, $now];
        $new_values = [
            'username' => $username,
            'first_name' => $first_name,
            'middle_name' => $middle_name,
            'last_name' => $last_name,
            'email' => $email,
            'contact_number' => $contact_number,
            'birth_date' => $birth_date,
            'sex' => $sex,
            'address' => $address,
            'shift_schedule' => $shift_schedule
        ];

        if ($profile_picture) {
            $updates[] = "profile_picture = ?";
            $params[] = $profile_picture;
            $new_values['profile_picture'] = '[IMAGE DATA]';
        }

        // Handle Password Change if requested
        if (!empty($new_password)) {
            if ($new_password !== $confirm_password) {
                throw new Exception("New passwords do not match.");
            }
            if (strlen($new_password) < 8) {
                throw new Exception("New password must be at least 8 characters long.");
            }
            $updates[] = "password_hash = ?";
            $params[] = password_hash($new_password, PASSWORD_BCRYPT);
            $new_values['password'] = 'CHANGED';
        }

        $params[] = $user_id; // For WHERE clause
        $stmtUpdate = $pdo->prepare("UPDATE users SET " . implode(", ", $updates) . " WHERE user_id = ?");
        $stmtUpdate->execute($params);

        log_audit_event($pdo, $user_id, null, 'Update', 'users', $user_id, $old_values, $new_values);

        $pdo->commit();
        $_SESSION['success_msg'] = "Profile updated successfully!";
        $_SESSION['first_name'] = $first_name;
        $_SESSION['last_name'] = $last_name;
        header("Location: profile.php?status=success&msg=" . urlencode("Profile updated successfully!"));
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error_msg'] = $e->getMessage();
        header("Location: profile.php?status=error&msg=" . urlencode($e->getMessage()));
        exit;
    }
}

// Fetch current user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$page_title = "My Profile";
$active_page = "profile";

// Data Preparation for the template
$joined = isset($user['created_at']) ? date("F Y", strtotime($user['created_at'])) : 'N/A';
$status = "Active";
$statusColor = 'text-emerald-400 bg-emerald-400/10 border-emerald-400/20';

$sex = htmlspecialchars($user['sex'] ?? '');
$birthDate = htmlspecialchars($user['birth_date'] ?? '');
$address = htmlspecialchars($user['address'] ?? '');
$shift = htmlspecialchars($user['shift_schedule'] ?? '');
$role = "Superadmin"; // Hardcoded role for Superadmin profile

?>
<!DOCTYPE html>
<html class="dark" lang="en">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>
        <?= $page_title ?> | Horizon System
    </title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap"
        rel="stylesheet" />
    <link
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200"
        rel="stylesheet" />
    <link
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200"
        rel="stylesheet" />
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "#8c2bee", "primary-hover": "#7724cc", "background-dark": "#0a090d", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)" } } }
        }
    </script>
    <style>
        body {
            font-family: 'Lexend', sans-serif;
            background-color: #0a090d;
            color: white;
        }

        .sidebar-nav {
            width: 110px;
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            position: fixed;
            left: 0;
            top: 0;
            bottom: 0;
            z-index: 50;
        }

        .sidebar-nav:hover {
            width: 300px;
        }

        .main-content {
            margin-left: 110px;
            flex: 1;
            min-width: 0;
            transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .sidebar-nav:hover~.main-content {
            margin-left: 300px;
        }

        .sidebar-scroll-container {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
        }

        .sidebar-scroll-container::-webkit-scrollbar {
            width: 4px;
        }

        .sidebar-scroll-container::-webkit-scrollbar-track {
            background: transparent;
        }

        .sidebar-scroll-container::-webkit-scrollbar-thumb {
            background: rgba(140, 43, 238, 0.1);
            border-radius: 10px;
        }

        .sidebar-nav:hover .sidebar-scroll-container::-webkit-scrollbar-thumb {
            background: rgba(140, 43, 238, 0.4);
        }

        .nav-text {
            opacity: 0;
            transform: translateX(-15px);
            transition: all 0.3s ease-in-out;
            white-space: nowrap;
            pointer-events: none;
        }

        .sidebar-nav:hover .nav-text {
            opacity: 1;
            transform: translateX(0);
            pointer-events: auto;
        }

        .nav-section-header {
            max-height: 0;
            opacity: 0;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            margin: 0 !important;
            padding: 0 38px;
            pointer-events: none;
        }

        .sidebar-nav:hover .nav-section-header {
            max-height: 20px;
            opacity: 1;
            margin-bottom: 8px !important;
            pointer-events: auto;
        }

        .sidebar-nav:hover .nav-section-header.mt-4 {
            margin-top: 12px !important;
        }

        .sidebar-nav:hover .nav-section-header.mt-6 {
            margin-top: 16px !important;
        }

        .sidebar-content {
            display: flex;
            flex-direction: column;
            gap: 2px;
            transition: all 0.3s ease-in-out;
        }

        .sidebar-nav:hover .sidebar-content {
            gap: 4px;
        }

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
            color: #94a3b8;
            text-decoration: none;
        }

        .active-nav {
            color: #8c2bee !important;
            position: relative;
        }

        .active-nav::after {
            content: '';
            position: absolute;
            right: 0px;
            top: 50%;
            transform: translateY(-50%);
            width: 4px;
            height: 24px;
            background: #8c2bee;
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

        .no-scrollbar::-webkit-scrollbar {
            display: none;
        }

        .no-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        /* Profile Specific Styles (Merged Logic + Superadmin Theme) */
        .profile-input {
            background-color: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #e5e7eb;
            transition: all 0.3s ease;
        }

        .profile-input:disabled {
            background-color: transparent;
            border-color: transparent;
            color: #9ca3af;
            cursor: default;
            padding-left: 0;
        }

        .edit-mode .profile-input.read-only-box {
            background-color: rgba(255, 255, 255, 0.03) !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            color: #6b7280 !important;
            padding-left: 1rem !important;
            cursor: not-allowed;
        }

        .profile-input:not(:disabled):focus {
            background-color: rgba(255, 255, 255, 0.05);
            border-color: #8c2bee;
            outline: none;
            box-shadow: 0 0 0 1px rgba(140, 43, 238, 0.1);
        }

        .profile-input.has-icon:not(:disabled),
        .edit-mode .profile-input.has-icon:disabled {
            padding-left: 2.5rem !important;
        }

        body:not(.edit-mode) .profile-input.has-icon {
            padding-left: 0 !important;
        }

        body:not(.edit-mode) .input-icon,
        body:not(.edit-mode) .input-chevron {
            display: none !important;
        }

        .profile-section-title {
            font-size: 0.75rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #8c2bee;
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }

        .edit-reveal {
            max-height: 0;
            opacity: 0;
            overflow: hidden;
            transition: all 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .edit-mode .edit-reveal {
            max-height: 1000px;
            opacity: 1;
            margin-top: 1.5rem;
        }

        select.profile-input option {
            background-color: #14121a;
            color: white;
        }

        /* Dynamic Main Wrapper Sizing */
        #main-wrapper {
            max-width: 1000px;
            transition: max-width 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }

        body.edit-mode #main-wrapper {
            max-width: 1200px;
        }

        .animate-fade-in {
            animation: fadeIn 0.3s ease-in-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(5px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>

<body class="antialiased flex h-screen overflow-hidden">

    <nav class="sidebar-nav bg-[#0a090d] border-r border-white/5 z-50 flex flex-col no-scrollbar">
        <div class="px-7 py-5 mb-2 shrink-0">
            <div class="flex items-center gap-4">
                <div class="size-10 rounded-xl bg-[#7f13ec] flex items-center justify-center shadow-lg shrink-0">
                    <span class="material-symbols-outlined text-white text-2xl">bolt</span>
                </div>
                <h1 class="nav-text text-lg font-black italic uppercase tracking-tighter text-white">Horizon System</h1>
            </div>
        </div>

        <div class="sidebar-scroll-container no-scrollbar space-y-1 pb-4">
            <div class="nav-section-header mb-2">
                <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Overview</span>
            </div>
            <a href="superadmin_dashboard.php"
                class="nav-link <?= ($active_page == 'dashboard') ? 'active-nav' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">grid_view</span>
                <span class="nav-text">Dashboard</span>
            </a>

            <div class="nav-section-header mb-2 mt-4">
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

            <div class="nav-section-header mb-2 mt-4">
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
            <div class="nav-section-header mb-2">
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
            <a href="../logout.php" class="nav-link text-gray-400 hover:text-rose-500 transition-colors">
                <span class="material-symbols-outlined text-xl shrink-0">logout</span>
                <span class="nav-text">Sign Out</span>
            </a>
        </div>
    </nav>

    <div class="main-content flex-1 flex flex-col min-w-0 overflow-y-auto no-scrollbar">
        <main id="main-wrapper" class="flex-1 p-6 md:p-8 lg:p-10 w-full mx-auto pb-32 animate-fade-in">
            <header class="mb-12 flex flex-row justify-between items-end gap-6">
                <div>
                    <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white leading-none">My
                        <span class="text-primary">Profile</span>
                    </h2>
                    <p class="text-gray-500 text-[10px] font-bold uppercase tracking-widest mt-2 px-1">
                        Identify & Security Settings</p>
                </div>
                <div class="flex flex-col items-end justify-center">
                    <p id="headerClock"
                        class="text-white font-black italic text-2xl leading-none transition-colors hover:text-primary uppercase tracking-tighter">
                        00:00:00 AM</p>
                    <p class="text-primary text-[10px] font-black uppercase tracking-[0.2em] leading-none mt-2">
                        <?= date('l, M d, Y') ?>
                    </p>
                </div>
            </header>

            <div class="flex flex-col xl:flex-row gap-8 items-start justify-center">
                <!-- Left Panel -->
                <div class="w-full xl:w-72 shrink-0 flex flex-col gap-6">
                    <div
                        class="relative overflow-hidden rounded-3xl bg-[#14121a] border border-white/5 shadow-2xl p-8 text-center group">
                        <div
                            class="absolute top-0 right-0 w-40 h-40 bg-primary/10 rounded-full blur-3xl -translate-y-1/2 translate-x-1/2 pointer-events-none transition-transform group-hover:scale-110">
                        </div>
                        <div class="relative z-10">
                            <div
                                class="w-32 h-32 mx-auto rounded-[32px] p-1 bg-gradient-to-br from-white/10 to-white/5 border border-white/10 mb-6 shadow-2xl overflow-hidden group">
                                <div
                                    class="w-full h-full rounded-[30px] bg-[#14121a] flex items-center justify-center overflow-hidden relative">
                                    <?php if (!empty($user['profile_picture'])): ?>
                                        <img id="profilePreviewImg" src="<?= $user['profile_picture'] ?>"
                                            class="size-full object-cover transition-transform duration-700 group-hover:scale-110">
                                    <?php else: ?>
                                        <div id="profilePlaceholder"
                                            class="size-full flex items-center justify-center bg-gradient-to-br from-primary/20 via-primary/10 to-transparent text-primary text-4xl font-black italic">
                                            <?= strtoupper($user['first_name'][0] . ($user['last_name'][0] ?? '')) ?>
                                        </div>
                                    <?php endif; ?>
                                    <label
                                        class="absolute inset-0 bg-black/80 opacity-0 group-hover:opacity-100 transition-all duration-300 flex flex-col items-center justify-center gap-3 backdrop-blur-md cursor-pointer border-4 border-dashed border-white/10 rounded-[30px]">
                                        <div
                                            class="size-12 rounded-full bg-white/5 flex items-center justify-center text-white group-hover:scale-110 transition-transform duration-300">
                                            <span class="material-symbols-rounded text-2xl">add_a_photo</span>
                                        </div>
                                        <span
                                            class="text-[10px] font-black uppercase tracking-[0.2em] text-white italic">Update</span>
                                        <input type="file" name="profile_picture" form="profile-form" class="hidden"
                                            accept="image/*" onchange="previewProfileImage(this)">
                                    </label>
                                </div>
                            </div>
                            <h2 class="text-2xl font-black italic uppercase tracking-tighter text-white mb-1">
                                <?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?>
                            </h2>
                            <p class="text-[10px] text-primary mb-5 font-bold uppercase tracking-widest">
                                <?= $role ?>
                            </p>
                            <div class="flex justify-center gap-2 mb-8">
                                <span
                                    class="px-4 py-1.5 rounded-full text-[9px] font-black uppercase tracking-widest border border-emerald-500/20 bg-emerald-500/10 text-emerald-400">
                                    <?= $status ?>
                                </span>
                            </div>
                            <div class="pt-6 border-t border-white/5">
                                <p class="text-[9px] uppercase tracking-[0.2em] text-gray-600 font-black mb-1">
                                    Administrator Since</p>
                                <p class="text-sm font-bold text-gray-300 italic">
                                    <?= $joined ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <button id="edit-btn" onclick="toggleEdit()"
                        class="w-full py-4 bg-white/5 border border-white/10 hover:bg-white/10 hover:border-primary/30 text-white text-[10px] font-black italic uppercase tracking-[0.2em] rounded-2xl transition-all flex items-center justify-center gap-3 group">
                        <span
                            class="material-symbols-rounded group-hover:text-primary transition-colors">edit_square</span>
                        <span>Edit Profile</span>
                    </button>

                    <button id="discard-btn" onclick="cancelEdit()"
                        class="hidden w-full py-4 bg-rose-500/10 border border-rose-500/20 hover:bg-rose-500/20 hover:border-rose-500/40 text-rose-500 text-[10px] font-black italic uppercase tracking-[0.2em] rounded-2xl transition-all flex items-center justify-center gap-3 group">
                        <span class="material-symbols-rounded group-hover:scale-110 transition-transform">close</span>
                        <span>Discard Changes</span>
                    </button>

                    <div class="mt-4 px-2 opacity-80">
                        <p
                            class="text-[9px] font-bold text-gray-400 uppercase tracking-widest leading-relaxed text-center">
                            Security Notice: Ensure your password is unique and not used elsewhere.
                        </p>
                    </div>
                </div>

                <!-- Right Panel Form -->
                <div
                    class="flex-1 w-full bg-[#14121a]/50 backdrop-blur-md border border-white/5 rounded-3xl p-6 md:p-10 shadow-2xl transition-all">
                    <div class="flex items-center justify-between mb-8">
                        <h1 class="text-xl font-black italic uppercase tracking-tighter text-white">Profile Details</h1>
                        <div id="edit-indicator"
                            class="hidden px-4 py-1.5 rounded-lg bg-primary/20 text-primary text-[9px] font-black italic uppercase tracking-[0.2em] animate-pulse">
                            Editing Mode
                        </div>
                    </div>

                    <form id="profile-form" action="" method="POST" enctype="multipart/form-data"
                        class="flex flex-col gap-10" onsubmit="validateAndSubmit(event)">
                        <input type="hidden" name="action" value="update_profile">

                        <!-- Account Details -->
                        <div class="space-y-6">
                            <h3 class="profile-section-title">Account Details</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-6">
                                <div class="space-y-2">
                                    <label
                                        class="text-[9px] uppercase font-black text-gray-600 tracking-widest ml-1">Username</label>
                                    <div class="relative group">
                                        <span
                                            class="input-icon absolute inset-y-0 left-0 pl-4 flex items-center text-gray-500 pointer-events-none group-focus-within:text-primary transition-colors">
                                            <span class="material-symbols-rounded text-lg">alternate_email</span>
                                        </span>
                                        <input type="text" name="username"
                                            value="<?= htmlspecialchars($user['username'] ?? '') ?>" disabled required
                                            class="w-full profile-input has-icon rounded-2xl px-4 py-3.5 text-sm font-bold">
                                    </div>
                                </div>

                                <div class="space-y-2">
                                    <label
                                        class="text-[9px] uppercase font-black text-gray-600 tracking-widest ml-1">Administrator
                                        Role</label>
                                    <div class="relative group">
                                        <span
                                            class="input-icon absolute inset-y-0 left-0 pl-4 flex items-center text-gray-500 pointer-events-none transition-colors">
                                            <span class="material-symbols-rounded text-lg">badge</span>
                                        </span>
                                        <input type="text" name="staff_role" value="<?= $role ?>" disabled
                                            class="w-full profile-input read-only-box has-icon rounded-2xl px-4 py-3.5 text-sm font-bold">
                                    </div>
                                </div>

                                <div class="edit-reveal md:col-span-2 mt-0">
                                    <div class="p-8 rounded-3xl bg-primary/[0.03] border border-primary/10">
                                        <h4
                                            class="text-[10px] font-black italic text-white uppercase tracking-[0.2em] mb-6 flex items-center gap-2">
                                            <span
                                                class="material-symbols-rounded text-primary text-xl">lock_reset</span>
                                            Update Password
                                        </h4>
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-6">
                                            <div class="space-y-2">
                                                <label
                                                    class="text-[9px] uppercase font-bold text-gray-600 tracking-widest">New
                                                    Password</label>
                                                <div class="relative">
                                                    <input type="password" name="new_password" id="new_pass"
                                                        onkeyup="checkStrength(this.value)"
                                                        placeholder="Leave blank to keep current"
                                                        class="w-full bg-[#0a090d] border border-white/10 rounded-2xl px-4 py-3.5 text-sm text-white focus:border-primary focus:outline-none transition-all placeholder:text-gray-800"
                                                        disabled>
                                                    <button type="button"
                                                        onclick="togglePassword('new_pass', 'icon_new')"
                                                        class="absolute right-4 top-3.5 text-gray-600 hover:text-white">
                                                        <span class="material-symbols-rounded text-lg"
                                                            id="icon_new">visibility_off</span>
                                                    </button>
                                                </div>
                                                <div class="h-1 w-full bg-white/5 rounded-full mt-3 overflow-hidden">
                                                    <div id="strength-bar"
                                                        class="h-full w-0 transition-all duration-300"></div>
                                                </div>
                                                <p id="strength-text"
                                                    class="text-[9px] mt-1.5 text-right font-black uppercase tracking-widest min-h-[15px]">
                                                </p>
                                            </div>

                                            <div class="space-y-2">
                                                <label
                                                    class="text-[9px] uppercase font-bold text-gray-600 tracking-widest">Confirm
                                                    New Password</label>
                                                <div class="relative">
                                                    <input type="password" name="confirm_password" id="confirm_pass"
                                                        placeholder="Re-enter new password"
                                                        class="w-full bg-[#0a090d] border border-white/10 rounded-2xl px-4 py-3.5 text-sm text-white focus:border-primary focus:outline-none transition-all placeholder:text-gray-800"
                                                        disabled>
                                                    <button type="button"
                                                        onclick="togglePassword('confirm_pass', 'icon_confirm')"
                                                        class="absolute right-4 top-3.5 text-gray-600 hover:text-white">
                                                        <span class="material-symbols-rounded text-lg"
                                                            id="icon_confirm">visibility_off</span>
                                                    </button>
                                                </div>
                                                <p id="match-text"
                                                    class="text-[9px] mt-1.5 text-right font-black uppercase tracking-widest text-rose-500 min-h-[15px]">
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Personal Information -->
                        <div class="space-y-6">
                            <h3 class="profile-section-title">Personal Information</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-6">
                                <div class="space-y-2">
                                    <label
                                        class="text-[9px] uppercase font-black text-gray-600 tracking-widest ml-1">First
                                        Name</label>
                                    <div class="relative group">
                                        <span
                                            class="input-icon absolute inset-y-0 left-0 pl-4 flex items-center text-gray-500 pointer-events-none group-focus-within:text-primary transition-colors">
                                            <span class="material-symbols-rounded text-lg">badge</span>
                                        </span>
                                        <input type="text" name="first_name"
                                            value="<?= htmlspecialchars($user['first_name'] ?? '') ?>" disabled required
                                            class="w-full profile-input has-icon rounded-2xl px-4 py-3.5 text-sm font-bold">
                                    </div>
                                </div>

                                <div class="space-y-2">
                                    <label
                                        class="text-[9px] uppercase font-black text-gray-600 tracking-widest ml-1">Middle
                                        Name</label>
                                    <div class="relative group">
                                        <span
                                            class="input-icon absolute inset-y-0 left-0 pl-4 flex items-center text-gray-500 pointer-events-none group-focus-within:text-primary transition-colors">
                                            <span class="material-symbols-rounded text-lg">badge</span>
                                        </span>
                                        <input type="text" name="middle_name"
                                            value="<?= htmlspecialchars($user['middle_name'] ?? '') ?>" disabled
                                            class="w-full profile-input has-icon rounded-2xl px-4 py-3.5 text-sm font-bold">
                                    </div>
                                </div>

                                <div class="space-y-2">
                                    <label
                                        class="text-[9px] uppercase font-black text-gray-600 tracking-widest ml-1">Last
                                        Name</label>
                                    <div class="relative group">
                                        <span
                                            class="input-icon absolute inset-y-0 left-0 pl-4 flex items-center text-gray-500 pointer-events-none group-focus-within:text-primary transition-colors">
                                            <span class="material-symbols-rounded text-lg">badge</span>
                                        </span>
                                        <input type="text" name="last_name"
                                            value="<?= htmlspecialchars($user['last_name'] ?? '') ?>" disabled required
                                            class="w-full profile-input has-icon rounded-2xl px-4 py-3.5 text-sm font-bold">
                                    </div>
                                </div>

                                <div class="space-y-2">
                                    <!-- Grid Spacer -->
                                </div>

                                <div class="space-y-2">
                                    <label
                                        class="text-[9px] uppercase font-black text-gray-600 tracking-widest ml-1">Date
                                        of Birth</label>
                                    <div class="relative group">
                                        <span
                                            class="input-icon absolute inset-y-0 left-0 pl-4 flex items-center text-gray-500 pointer-events-none group-focus-within:text-primary transition-colors">
                                            <span class="material-symbols-rounded text-lg">cake</span>
                                        </span>
                                        <input type="date" name="birth_date" value="<?= $birthDate ?>" disabled required
                                            class="w-full profile-input has-icon rounded-2xl px-4 py-3.5 text-sm font-bold [color-scheme:dark]">
                                    </div>
                                </div>

                                <div class="space-y-2">
                                    <label
                                        class="text-[9px] uppercase font-black text-gray-600 tracking-widest ml-1">Sex</label>
                                    <div class="relative group">
                                        <span
                                            class="input-icon absolute inset-y-0 left-0 pl-4 flex items-center text-gray-500 pointer-events-none group-focus-within:text-primary transition-colors">
                                            <span class="material-symbols-rounded text-lg">wc</span>
                                        </span>
                                        <select name="sex" disabled required
                                            class="w-full profile-input has-icon rounded-2xl px-4 py-3.5 text-sm font-bold appearance-none cursor-pointer">
                                            <option value="" disabled <?= empty($sex) ? 'selected' : '' ?>>Select Sex
                                            </option>
                                            <option value="Male" <?= $sex === 'Male' ? 'selected' : '' ?>>Male</option>
                                            <option value="Female" <?= $sex === 'Female' ? 'selected' : '' ?>>Female
                                            </option>
                                            <option value="Other" <?= $sex === 'Other' ? 'selected' : '' ?>>Other</option>
                                        </select>
                                        <span
                                            class="input-chevron absolute inset-y-0 right-0 pr-4 flex items-center text-gray-500 pointer-events-none">
                                            <span class="material-symbols-rounded text-lg">expand_more</span>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Contact & Details -->
                        <div class="space-y-6">
                            <h3 class="profile-section-title">Contact & Details</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-6">
                                <div class="space-y-2">
                                    <label
                                        class="text-[9px] uppercase font-black text-gray-600 tracking-widest ml-1">Contact
                                        No.</label>
                                    <div class="relative group">
                                        <span
                                            class="input-icon absolute inset-y-0 left-0 pl-4 flex items-center text-gray-500 pointer-events-none group-focus-within:text-primary transition-colors">
                                            <span class="material-symbols-rounded text-lg">smartphone</span>
                                        </span>
                                        <input type="text" name="contact_number"
                                            value="<?= htmlspecialchars($user['contact_number'] ?? '') ?>" disabled
                                            required
                                            class="w-full profile-input has-icon rounded-2xl px-4 py-3.5 text-sm font-bold">
                                    </div>
                                </div>

                                <div class="space-y-2">
                                    <label
                                        class="text-[9px] uppercase font-black text-gray-600 tracking-widest ml-1">Email</label>
                                    <div class="relative group">
                                        <span
                                            class="input-icon absolute inset-y-0 left-0 pl-4 flex items-center text-gray-500 pointer-events-none group-focus-within:text-primary transition-colors">
                                            <span class="material-symbols-rounded text-lg">mail</span>
                                        </span>
                                        <input type="email" name="email"
                                            value="<?= htmlspecialchars($user['email'] ?? '') ?>" disabled required
                                            class="w-full profile-input has-icon rounded-2xl px-4 py-3.5 text-sm font-bold">
                                    </div>
                                </div>






                            </div>
                        </div>

                        <!-- Bottom Save Section -->
                        <div id="save-section" class="hidden border-t border-white/5 pt-10 mt-6 animate-fade-in">
                            <div
                                class="bg-[#1a1824] border border-primary/20 rounded-3xl p-5 flex flex-col md:flex-row items-center justify-between gap-6 shadow-2xl backdrop-blur-xl relative overflow-hidden group/save">
                                <div
                                    class="absolute inset-0 bg-primary/5 opacity-0 group-hover/save:opacity-100 transition-opacity">
                                </div>

                                <div class="flex items-center gap-5 shrink-0 relative z-10">
                                    <div
                                        class="size-14 rounded-full bg-primary/10 flex items-center justify-center text-primary border border-primary/30 shadow-inner group-hover/save:scale-110 transition-transform duration-500">
                                        <span class="material-symbols-rounded text-2xl">shield_locked</span>
                                    </div>
                                    <div>
                                        <h4 class="text-sm font-black italic uppercase tracking-tighter text-white">
                                            Confirm Changes</h4>
                                        <p
                                            class="text-[9px] font-bold text-gray-500 uppercase tracking-widest mt-1 opacity-80">
                                            Enter current password to save changes.</p>
                                    </div>
                                </div>

                                <div
                                    class="flex flex-col sm:flex-row items-center gap-5 w-full md:w-auto relative z-10 shrink-0">
                                    <div class="relative w-full sm:w-44 group/input">
                                        <input type="password" name="current_password" id="current_pass" required
                                            placeholder="Password" disabled
                                            class="w-full bg-black/40 border border-white/10 rounded-xl px-5 py-3 text-xs font-black italic text-white focus:border-primary/50 focus:outline-none transition-all pr-12 placeholder:text-gray-800 tracking-widest">
                                        <button type="button" onclick="togglePassword('current_pass', 'icon_curr')"
                                            class="absolute right-4 top-2.5 text-gray-700 hover:text-white transition-colors">
                                            <span class="material-symbols-rounded text-lg"
                                                id="icon_curr">visibility_off</span>
                                        </button>
                                    </div>

                                    <button type="submit"
                                        class="shrink-0 text-primary hover:text-white text-[11px] font-black italic uppercase tracking-[0.2em] transition-all hover:scale-110 active:scale-95 py-2">
                                        SAVE CHANGES
                                    </button>
                                </div>
                            </div>
                        </div>

                    </form>
                </div>
            </div>
        </main>
    </div>

    <!-- Custom Modal (Themed for Superadmin) -->
    <div id="custom-modal" class="fixed inset-0 z-[100] flex items-center justify-center p-4 hidden xl:pl-[110px]">
        <div class="absolute inset-0 backdrop-blur-sm transition-opacity duration-300 opacity-0 bg-[#0a090d]/80"
            id="modal-backdrop" onclick="closeModal()">
        </div>

        <div class="relative z-10 bg-[#14121a] w-full max-w-sm rounded-[32px] shadow-2xl border border-white/10 overflow-hidden transform transition-all duration-300 scale-90 opacity-0"
            id="modal-content">
            <div class="p-8 text-center">
                <div class="w-20 h-20 rounded-[24px] bg-white/5 flex items-center justify-center mx-auto mb-6 border border-white/10"
                    id="modal-icon-bg">
                    <span class="material-symbols-rounded text-4xl text-primary" id="modal-icon">info</span>
                </div>

                <h3 class="text-xl font-black italic text-white uppercase tracking-tighter mb-3" id="modal-title">
                    Notification</h3>
                <p class="text-gray-400 text-[11px] font-bold tracking-wider mb-8 leading-relaxed px-2"
                    id="modal-message">Message goes here...</p>

                <div class="flex gap-3 justify-center" id="modal-actions">
                </div>
            </div>
        </div>
    </div>

    <script>
        // --- MODAL LOGIC ---
        function showModal(title, message, type, callback = null) {
            const modal = document.getElementById('custom-modal');
            const backdrop = document.getElementById('modal-backdrop');
            const content = document.getElementById('modal-content');

            document.getElementById('modal-title').innerText = title;
            document.getElementById('modal-message').innerText = message;

            const actionsDiv = document.getElementById('modal-actions');
            actionsDiv.innerHTML = '';

            if (type === 'confirm') {
                const cancelBtn = document.createElement('button');
                cancelBtn.className = "px-6 py-3.5 rounded-2xl bg-white/5 hover:bg-white/10 text-gray-300 text-[10px] font-black italic uppercase tracking-[0.2em] transition-colors";
                cancelBtn.innerText = "Cancel";
                cancelBtn.onclick = closeModal;
                actionsDiv.appendChild(cancelBtn);

                const confirmBtn = document.createElement('button');
                confirmBtn.className = "px-8 py-3.5 rounded-2xl bg-primary hover:bg-primary-hover text-white text-[10px] font-black italic uppercase tracking-[0.2em] shadow-lg shadow-primary/20 transition-all flex items-center gap-2";
                confirmBtn.innerHTML = '<span class="material-symbols-rounded text-base">check</span> Confirm';
                confirmBtn.onclick = function () {
                    if (callback) callback();
                    closeModal();
                };
                actionsDiv.appendChild(confirmBtn);

                document.getElementById('modal-icon').innerText = 'security';
                document.getElementById('modal-icon').className = 'material-symbols-rounded text-4xl text-primary';
                document.getElementById('modal-icon-bg').className = 'w-20 h-20 rounded-[24px] bg-primary/10 flex items-center justify-center mx-auto mb-6 border border-primary/20';

            } else {
                const okBtn = document.createElement('button');
                okBtn.className = "w-full py-4 rounded-2xl bg-white/10 hover:bg-white/20 text-white text-[10px] font-black italic uppercase tracking-[0.2em] transition-colors";
                okBtn.innerText = "Okay, Got it";
                okBtn.onclick = closeModal;
                actionsDiv.appendChild(okBtn);

                document.getElementById('modal-icon').innerText = 'warning';
                document.getElementById('modal-icon').className = 'material-symbols-rounded text-4xl text-rose-500';
                document.getElementById('modal-icon-bg').className = 'w-20 h-20 rounded-[24px] bg-rose-500/10 flex items-center justify-center mx-auto mb-6 border border-rose-500/20';

                if (type === 'success') {
                    document.getElementById('modal-icon').innerText = 'check_circle';
                    document.getElementById('modal-icon').className = 'material-symbols-rounded text-4xl text-emerald-400';
                    document.getElementById('modal-icon-bg').className = 'w-20 h-20 rounded-[24px] bg-emerald-400/10 flex items-center justify-center mx-auto mb-6 border border-emerald-400/20';
                }
            }

            modal.classList.remove('hidden');

            setTimeout(() => {
                backdrop.classList.remove('opacity-0');
                content.classList.remove('scale-90', 'opacity-0');
                content.classList.add('scale-100', 'opacity-100');
            }, 10);
        }

        function closeModal() {
            const modal = document.getElementById('custom-modal');
            const backdrop = document.getElementById('modal-backdrop');
            const content = document.getElementById('modal-content');

            backdrop.classList.add('opacity-0');
            content.classList.remove('scale-100', 'opacity-100');
            content.classList.add('scale-90', 'opacity-0');

            setTimeout(() => {
                modal.classList.add('hidden');
            }, 300);
        }

        // --- FORM LOGIC ---
        let initialValues = {};

        function toggleEdit() {
            const form = document.getElementById('profile-form');
            const inputs = form.querySelectorAll('input, select, textarea');
            const saveSection = document.getElementById('save-section');
            const editBtn = document.getElementById('edit-btn');
            const discardBtn = document.getElementById('discard-btn');
            const indicator = document.getElementById('edit-indicator');

            document.body.classList.add('edit-mode');

            inputs.forEach(input => {
                if (input.name !== 'staff_role') {
                    input.disabled = false;
                    initialValues[input.name] = input.value;
                }
            });

            editBtn.classList.add('hidden');
            discardBtn.classList.remove('hidden');
            saveSection.classList.remove('hidden');
            indicator.classList.remove('hidden');

            const firstInput = form.querySelector('input:not([disabled])');
            if (firstInput && firstInput.type !== 'hidden') firstInput.focus();
        }

        function cancelEdit() {
            const form = document.getElementById('profile-form');
            const inputs = form.querySelectorAll('input, select, textarea');
            const saveSection = document.getElementById('save-section');
            const editBtn = document.getElementById('edit-btn');
            const discardBtn = document.getElementById('discard-btn');
            const indicator = document.getElementById('edit-indicator');

            document.body.classList.remove('edit-mode');

            inputs.forEach(input => {
                input.disabled = true;
                if (initialValues[input.name] !== undefined && input.type !== 'file' && input.type !== 'password') {
                    input.value = initialValues[input.name];
                }
            });

            document.getElementById('new_pass').value = '';
            document.getElementById('confirm_pass').value = '';
            document.getElementById('current_pass').value = '';
            document.getElementById('strength-bar').style.width = '0';
            document.getElementById('strength-text').innerText = '';
            document.getElementById('match-text').innerText = '';

            editBtn.classList.remove('hidden');
            discardBtn.classList.add('hidden');
            saveSection.classList.add('hidden');
            indicator.classList.add('hidden');
        }

        function previewProfileImage(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function (e) {
                    let img = document.getElementById('profilePreviewImg');
                    if (!img) {
                        const placeholder = document.getElementById('profilePlaceholder');
                        if (placeholder) {
                            img = document.createElement('img');
                            img.id = 'profilePreviewImg';
                            img.className = 'size-full object-cover transition-transform duration-700 group-hover:scale-110';
                            placeholder.parentNode.insertBefore(img, placeholder);
                            placeholder.remove();
                        }
                    }
                    if (img) img.src = e.target.result;

                    // Auto-trigger Edit Mode if not already in it
                    if (!document.body.classList.contains('edit-mode')) {
                        toggleEdit();
                    }
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        function togglePassword(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            if (input.type === "password") {
                input.type = "text";
                icon.innerText = "visibility";
            } else {
                input.type = "password";
                icon.innerText = "visibility_off";
            }
        }

        function checkStrength(password) {
            const bar = document.getElementById('strength-bar');
            const text = document.getElementById('strength-text');

            if (password.length === 0) {
                bar.style.width = '0%';
                bar.className = 'h-full transition-all duration-300';
                text.innerText = '';
                return;
            }

            let strength = 0;
            if (password.length >= 8) strength += 25;
            if (password.match(/[A-Z]/)) strength += 25;
            if (password.match(/[0-9]/)) strength += 25;
            if (password.match(/[^A-Za-z0-9]/)) strength += 25;

            bar.style.width = strength + '%';
            if (strength <= 25) { bar.className = 'h-full transition-all duration-300 bg-rose-500'; text.innerText = 'WEAK'; text.className = 'text-[9px] mt-1.5 text-right font-black uppercase tracking-widest text-rose-500 min-h-[15px]'; }
            else if (strength <= 50) { bar.className = 'h-full transition-all duration-300 bg-amber-500'; text.innerText = 'FAIR'; text.className = 'text-[9px] mt-1.5 text-right font-black uppercase tracking-widest text-amber-500 min-h-[15px]'; }
            else if (strength <= 75) { bar.className = 'h-full transition-all duration-300 bg-emerald-400'; text.innerText = 'GOOD'; text.className = 'text-[9px] mt-1.5 text-right font-black uppercase tracking-widest text-emerald-400 min-h-[15px]'; }
            else { bar.className = 'h-full transition-all duration-300 bg-primary'; text.innerText = 'STRONG'; text.className = 'text-[9px] mt-1.5 text-right font-black uppercase tracking-widest text-primary min-h-[15px]'; }
        }

        function validateAndSubmit(event) {
            event.preventDefault();

            const newPass = document.getElementById('new_pass').value;
            const confirmPass = document.getElementById('confirm_pass').value;
            const matchText = document.getElementById('match-text');

            if (newPass.length > 0) {
                if (newPass !== confirmPass) {
                    matchText.innerText = "PASSWORDS DO NOT MATCH!";
                    matchText.className = "text-[9px] mt-1.5 text-right font-black uppercase tracking-widest text-rose-500 min-h-[15px]";

                    showModal('Password Mismatch', 'The new passwords you entered do not match. Please try again.', 'error');
                    return false;
                }
            }

            showModal(
                'Save Changes?',
                'Are you sure you want to update your profile details? This action cannot be undone.',
                'confirm',
                function () {
                    document.getElementById('profile-form').submit();
                }
            );
            return false;
        }

        setInterval(() => {
            const now = new Date();
            document.getElementById('headerClock').innerText = now.toLocaleTimeString('en-US');
        }, 1000);
    </script>

    <?php if (isset($_GET['status']) && isset($_GET['msg'])): ?>
        <script>
            window.addEventListener('DOMContentLoaded', () => {
                const status = "<?= htmlspecialchars($_GET['status']) ?>";
                const msg = "<?= htmlspecialchars(urldecode($_GET['msg'])) ?>";
                const title = status === 'success' ? 'Success' : 'Notice';
                const type = status === 'success' ? 'success' : 'error';

                showModal(title, msg, type);

                if (history.replaceState) {
                    var newurl = window.location.protocol + "//" + window.location.host + window.location.pathname + window.location.search.replace(/[\?&](status|msg)=[^&]+/, '').replace(/^&/, '?');
                    window.history.replaceState({ path: newurl }, '', newurl);
                }
            });
        </script>
    <?php endif; ?>

</body>

</html>