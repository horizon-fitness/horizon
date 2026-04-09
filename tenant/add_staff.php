<?php
session_start();
require_once '../db.php';
require_once '../includes/mailer.php';

// Security Check
$role = strtolower($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || ($role !== 'tenant' && $role !== 'admin')) {
    header("Location: ../login.php");
    exit;
}

$gym_id = $_SESSION['gym_id'];
$active_page = 'staff';
$success = '';
$error = '';

// Fetch Gym Details
$stmtGym = $pdo->prepare("SELECT * FROM gyms WHERE gym_id = ?");
$stmtGym->execute([$gym_id]);
$gym = $stmtGym->fetch();

// Fetch CMS Page
$stmtPage = $pdo->prepare("SELECT * FROM tenant_pages WHERE gym_id = ? LIMIT 1");
$stmtPage->execute([$gym_id]);
$page = $stmtPage->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $password = $_POST['password'] ?? '';
    $staff_role = $_POST['staff_role'] ?? 'Staff';
    $employment_type = $_POST['employment_type'] ?? 'Full-time';
    $now = date('Y-m-d H:i:s');

    if (empty($first_name) || empty($last_name) || empty($email) || empty($username)) {
        $error = "Essential fields are required.";
    } else {
        try {
            // 0. Check Staff Limit (Global Rule)
            $stmtMax = $pdo->prepare("SELECT setting_value FROM system_settings WHERE user_id = 0 AND setting_key = 'max_staff' LIMIT 1");
            $stmtMax->execute();
            $max_staff = (int)($stmtMax->fetchColumn() ?: 0);

            if ($max_staff > 0) {
                $stmtCountStaff = $pdo->prepare("SELECT COUNT(*) FROM staff WHERE gym_id = ? AND status = 'Active'");
                $stmtCountStaff->execute([$gym_id]);
                $current_staff = (int)$stmtCountStaff->fetchColumn();

                $stmtCountCoaches = $pdo->prepare("SELECT COUNT(*) FROM coaches WHERE gym_id = ? AND status = 'Active'");
                $stmtCountCoaches->execute([$gym_id]);
                $current_coaches = (int)$stmtCountCoaches->fetchColumn();

                if (($current_staff + $current_coaches) >= $max_staff) {
                    throw new Exception("Security Protocol: Staff limit reached. Your current configuration allows only $max_staff active staff members. Please contact system administrators for expansion.");
                }
            }

            // 1. Check if Username Already Exists
            $stmtCheckU = $pdo->prepare("SELECT user_id FROM users WHERE username = ? LIMIT 1");
            $stmtCheckU->execute([$username]);
            if ($stmtCheckU->fetch()) {
                $error = "The username '$username' is already taken. Please choose another.";
            } else {
                // 2. Check if Email Already Exists
                $stmtCheckE = $pdo->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
                $stmtCheckE->execute([$email]);
                if ($stmtCheckE->fetch()) {
                    $error = "The email '$email' is already registered with another account.";
                } else {
                    $pdo->beginTransaction();

                // Hash password (auto-gen if empty)
                if (empty($password)) {
                    $password = strtolower($first_name) . "123";
                }
                $password_hash = password_hash($password, PASSWORD_DEFAULT);

                // 1. Insert into users
                $stmtUser = $pdo->prepare("INSERT INTO users (username, email, password_hash, first_name, last_name, contact_number, is_verified, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 1, 1, ?, ?)");
                $stmtUser->execute([$username, $email, $password_hash, $first_name, $last_name, $contact_number, $now, $now]);
                $new_user_id = $pdo->lastInsertId();

                // 2. Fetch or Create Role (ensure it matches login.php expectations)
                $role_name = (strtolower($staff_role) === 'coach') ? 'Coach' : 'Staff';
                $stmtRole = $pdo->prepare("SELECT role_id FROM roles WHERE role_name = ? LIMIT 1");
                $stmtRole->execute([$role_name]);
                $role_row = $stmtRole->fetch();
                
                if (!$role_row) {
                    $stmtAddRole = $pdo->prepare("INSERT INTO roles (role_name) VALUES (?)");
                    $stmtAddRole->execute([$role_name]);
                    $role_id = $pdo->lastInsertId();
                } else {
                    $role_id = $role_row['role_id'];
                }

                // 3. Insert into user_roles
                $stmtUserRole = $pdo->prepare("INSERT INTO user_roles (user_id, role_id, gym_id, role_status, assigned_at) VALUES (?, ?, ?, 'Active', ?)");
                $stmtUserRole->execute([$new_user_id, $role_id, $gym_id, $now]);

                // 4. Conditional insertion into staff or coaches table
                if (strtolower($staff_role) === 'coach') {
                    $stmtCoach = $pdo->prepare("INSERT INTO coaches (user_id, gym_id, coach_type, specialization, hire_date, status, created_at, updated_at) VALUES (?, ?, ?, 'General Trainer', CURDATE(), 'Active', ?, ?)");
                    $stmtCoach->execute([$new_user_id, $gym_id, $employment_type, $now, $now]);
                } else {
                    $stmtStaff = $pdo->prepare("INSERT INTO staff (user_id, gym_id, staff_role, employment_type, hire_date, status, created_at, updated_at) VALUES (?, ?, ?, ?, CURDATE(), 'Active', ?, ?)");
                    $stmtStaff->execute([$new_user_id, $gym_id, $staff_role, $employment_type, $now, $now]);
                }

                    $pdo->commit();

                    // Send Welcome Email
                    $subject = "Welcome to " . ($gym['gym_name'] ?? 'Horizon') . "!";
                    $login_url = "https://" . $_SERVER['HTTP_HOST'] . "/login.php"; 
                    $content = "
                        <p>Hello <strong>" . htmlspecialchars($first_name) . "</strong>,</p>
                        <p>Welcome to the <strong>" . htmlspecialchars($gym['gym_name'] ?? 'Horizon') . "</strong> team! Your staff account has been successfully created.</p>
                        <div style='background: #f8f8f8; padding: 20px; border-radius: 10px; margin: 20px 0;'>
                            <p style='margin: 0;'><strong>Username:</strong> " . htmlspecialchars($username) . "</p>
                            <p style='margin: 5px 0 0 0;'><strong>Password:</strong> " . htmlspecialchars($password) . "</p>
                        </div>
                        <p>You can access the portal here: <a href='$login_url'>$login_url</a></p>
                        <p>Please change your password after your first login for security.</p>
                    ";
                    sendSystemEmail($email, $subject, getEmailTemplate("Welcome to the Team!", $content));

                    $success = "Staff member $first_name added successfully! Access username: $username";
                }
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Transaction failed: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Add New Staff | Horizon Tenant</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "#8c2bee", "background-dark": "#0a090d", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)"}}}
        }
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; overflow: hidden; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        .nav-link { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; transition: all 0.2s; white-space: nowrap; }

        .active-nav { color: #8c2bee !important; position: relative; }

        .active-nav::after { 

            content: ''; 

            position: absolute; 

            right: -32px; 

            top: 50%;

            transform: translateY(-50%);

            width: 4px; 

            height: 20px; 

            background: #8c2bee; 

            border-radius: 99px; 

        }

        /* Sidebar Hover Logic */
        .sidebar-nav {
            width: 85px; 
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .sidebar-nav:hover {
            width: 300px; 
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
            pointer-events: none;
        }
        .sidebar-nav:hover .nav-section-header {
            max-height: 20px;
            opacity: 1;
            margin-bottom: 8px !important; 
            pointer-events: auto;
        }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        .input-field { background: #1a1721; border: 1px solid #2d2838; border-radius: 12px; color: white; padding: 12px 16px; width: 100%; transition: all 0.2s; font-size: 13px; font-weight: 500; }
        .input-field:focus { border-color: #8c2bee; outline: none; box-shadow: 0 0 0 2px rgba(140, 43, 238, 0.2); }

        /* Power User Features */
        .pass-container { position: relative; }
        .view-toggle { position: absolute; right: 14px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #64748b; font-size: 18px !important; transition: all 0.3s ease; }
        .view-toggle:hover { color: white; }
        .strength-bar { height: 4px; border-radius: 2px; transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); width: 0; margin-top: 8px; }
        .strength-weak { background: #f43f5e; width: 33%; }
        .strength-medium { background: #fbbf24; width: 66%; }
        .strength-strong { background: #10b981; width: 100%; }
    </style>
</head>
<body class="flex h-screen overflow-hidden">

<nav class="sidebar-nav bg-[#0a090d] border-r border-white/5 sticky top-0 h-screen px-7 py-8 z-50 shrink-0 flex flex-col">
    <div class="mb-10 shrink-0"> 
        <div class="flex items-center gap-4 mb-4"> 
            <div class="size-11 rounded-xl bg-primary flex items-center justify-center shadow-lg shrink-0 overflow-hidden">
                <?php if (!empty($gym['logo_path'])): ?>
                    <img src="<?= htmlspecialchars($gym['logo_path']) ?>" class="size-full object-contain">
                <?php else: ?>
                    <span class="material-symbols-outlined text-white text-2xl">bolt</span>
                <?php endif; ?>
            </div>
            <h1 class="nav-text text-lg font-black italic uppercase tracking-tighter text-white leading-tight break-words line-clamp-2">
                <?= htmlspecialchars($gym['gym_name'] ?? 'CORSANO FITNESS') ?>
            </h1>
        </div>
    </div>
    
    <div class="flex-1 overflow-y-auto no-scrollbar space-y-1 pr-2">
        <div class="nav-section-header px-0 mb-2">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Main Menu</span>
        </div>
        
        <a href="tenant_dashboard.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'dashboard') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">grid_view</span> 
            <span class="nav-text">Dashboard</span>
        </a>
        
        <a href="my_users.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'users') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">group</span> 
            <span class="nav-text">My Users</span>
        </a>

        <a href="transactions.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'transactions') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">receipt_long</span> 
            <span class="nav-text">Transactions</span>
            <span class="size-1.5 rounded-full bg-red-500 ml-auto"></span>
        </a>

        <a href="attendance.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'attendance') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">history</span> 
            <span class="nav-text">Attendance</span>
        </a>

        <div class="nav-section-header px-0 mb-2 mt-6">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Management</span>
        </div>

        <a href="staff.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'staff') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">badge</span> 
            <span class="nav-text">Staff Management</span>
        </a>

        <a href="reports.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'reports') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">analytics</span> 
            <span class="nav-text">System Reports</span>
        </a>

        <a href="sales_report.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'sales') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">payments</span> 
            <span class="nav-text">Sales Reports</span>
        </a>
    </div>

    <div class="mt-auto pt-4 border-t border-white/10 flex flex-col gap-1 shrink-0">
        <div class="nav-section-header px-0 mb-2">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Account</span>
        </div>
        
        <a href="tenant_settings.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'settings') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">settings</span> 
            <span class="nav-text">Settings</span>
        </a>

        <a href="tenant_settings.php" class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'profile') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">person</span> 
            <span class="nav-text">Profile</span>
        </a>

        <a href="../logout.php" class="text-gray-400 hover:text-rose-500 transition-colors flex items-center gap-4 group py-2">
            <span class="material-symbols-outlined group-hover:translate-x-1 transition-transform text-xl shrink-0">logout</span>
            <span class="nav-link nav-text text-sm">Sign Out</span>
        </a>
    </div>
</nav>

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
            if (!indicator) return;
            let strength = 0;
            if (pass.length > 5) strength++;
            if (pass.length > 8) strength++;
            if (/[0-9]/.test(pass) && /[a-z]/.test(pass) && /[A-Z]/.test(pass)) strength++;
            if (/[^A-Za-z0-9]/.test(pass)) strength++;

            indicator.className = 'strength-bar';
            if (pass.length === 0) return;
            if (strength <= 1) indicator.classList.add('strength-weak');
            else if (strength === 2) indicator.classList.add('strength-medium');
            else if (strength >= 3) indicator.classList.add('strength-strong');
        }

        window.addEventListener('DOMContentLoaded', updateTopClock);

    </script>

<main class="flex-1 overflow-y-auto no-scrollbar p-10">
    <header class="mb-10 flex flex-col md:flex-row md:items-end justify-between gap-6">
        <div class="flex flex-col md:flex-row md:items-end justify-between w-full">
            <div>
                <h2 class="text-3xl font-black italic uppercase tracking-tighter text-primary">Onboard <span class="text-white">Staff</span></h2>
                <p class="text-gray-500 font-bold uppercase tracking-[0.3em] text-[10px] mt-2">Create new system access credentials</p>
            </div>
            <div class="flex flex-col items-end mt-4 md:mt-0">
                <p id="topClock" class="text-white font-black italic text-2xl leading-none">00:00:00 AM</p>
                <p class="text-[10px] font-black uppercase text-gray-500 tracking-[0.2em] leading-none mt-2"><?= date('l, M d, Y') ?></p>
                <div class="flex items-center gap-2 mt-2 px-3 py-1 rounded-lg bg-white/5 border border-white/5">
                    <p class="text-[9px] font-black uppercase text-gray-400 tracking-widest">Plan:</p>
                    <p class="text-[10px] font-black uppercase text-white italic tracking-tighter"><?= htmlspecialchars($sub['plan_name'] ?? 'Standard Plan') ?></p>
                    <span class="px-1.5 py-0.5 rounded-md bg-primary/20 text-primary text-[7px] font-black uppercase tracking-widest">Active</span>
                </div>
            </div>
        </div>
    </header>

    <header class="mb-10 flex justify-between items-center">
        <a href="staff.php" class="text-gray-500 hover:text-white flex items-center gap-2 font-black italic uppercase text-[10px] tracking-widest transition-all ml-auto">
            <span class="material-symbols-outlined text-sm text-primary">arrow_back</span> Back to Roster
        </a>
    </header>

    <div class="glass-card p-10 max-w-5xl">
        <?php if($success): ?>
            <div class="mb-8 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-500 text-xs font-bold flex items-center gap-3">
                <span class="material-symbols-outlined">check_circle</span> <?= $success ?>
            </div>
        <?php endif; ?>
        <?php if($error): ?>
            <div class="mb-8 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-500 text-xs font-bold flex items-center gap-3">
                <span class="material-symbols-outlined">error</span> <?= $error ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-8">
            <div class="space-y-6">
                <h4 class="text-[10px] font-black uppercase text-primary tracking-widest border-b border-white/5 pb-2 flex items-center gap-2 italic">
                    <span class="material-symbols-outlined text-[14px]">assignment_ind</span> Identity Information
                </h4>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-2 block">First Name</label>
                        <input type="text" name="first_name" required class="input-field">
                    </div>
                    <div>
                        <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-2 block">Last Name</label>
                        <input type="text" name="last_name" required class="input-field">
                    </div>
                </div>
                <div>
                    <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-2 block">Email Address</label>
                    <input type="email" name="email" required class="input-field">
                </div>
                <div>
                    <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-2 block">Contact Number</label>
                    <input type="text" name="contact_number" class="input-field">
                </div>
            </div>

            <div class="space-y-6">
                <h4 class="text-[10px] font-black uppercase text-primary tracking-widest border-b border-white/5 pb-2 flex items-center gap-2 italic">
                    <span class="material-symbols-outlined text-[14px]">admin_panel_settings</span> Account Authority
                </h4>
                <div>
                    <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-2 block">System Username</label>
                    <input type="text" name="username" required class="input-field">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-2 block">Assigned Role</label>
                        <select name="staff_role" class="input-field uppercase font-bold italic tracking-wider">
                            <option value="Staff">Staff Member</option>
                            <option value="Coach">Coach / Trainer</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-2 block">Employment</label>
                        <select name="employment_type" class="input-field">
                            <option value="Full-time">Full-time</option>
                            <option value="Part-time">Part-time</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-2 block">Set Master Password (Optional - defaults to [first_name]123)</label>
                    <div class="pass-container">
                        <input type="password" id="staff_pass" name="password" oninput="checkPassStrength(this.value)" class="input-field pr-12" placeholder="Leave empty for auto-gen">
                        <span class="material-symbols-outlined view-toggle" onclick="togglePass('staff_pass', this)">visibility</span>
                    </div>
                    <div class="w-full bg-white/5 h-1 rounded-full mt-2 overflow-hidden">
                        <div id="strength-indicator" class="strength-bar"></div>
                    </div>
                </div>
            </div>

            <div class="md:col-span-2 pt-6">
                <button type="submit" class="w-full bg-primary text-white py-4 rounded-xl font-black italic uppercase tracking-widest text-xs shadow-lg shadow-primary/20 hover:scale-[1.02] transition-all">Onboard Staff Member</button>
            </div>
        </form>
    </div>
</main>

</body>
</html>
