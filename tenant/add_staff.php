<?php
session_start();
require_once '../db.php';

// Security Check: Only Tenants/Admins/Staff
$role = strtolower($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || ($role !== 'tenant' && $role !== 'admin' && $role !== 'staff' && $role !== 'coach')) {
    header("Location: ../login.php");
    exit;
}

$gym_id = $_SESSION['gym_id'];
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $password = $_POST['password'] ?? '';
    $staff_role = $_POST['staff_role'] ?? 'Staff';
    $employment_type = $_POST['employment_type'] ?? 'Full-time';
    $now = date('Y-m-d H:i:s');

    if (empty($first_name) || empty($last_name) || empty($email) || empty($username)) {
        $error = "All fields are required.";
    } else {
        try {
            $pdo->beginTransaction();

            // Check if username/email exists
            $stmtCheck = $pdo->prepare("SELECT user_id FROM users WHERE username = ? OR email = ? LIMIT 1");
            $stmtCheck->execute([$username, $email]);
            if ($stmtCheck->fetch()) {
                throw new Exception("Username or Email already exists.");
            }

            // Generate Random Password if empty
            $plain_password = $password;
            if (empty($plain_password)) {
                $plain_password = bin2hex(random_bytes(4)); // 8 character random hex
            }

            // 1. Create User
            $password_hash = password_hash($plain_password, PASSWORD_BCRYPT);
            $stmtUser = $pdo->prepare("INSERT INTO users (username, email, password_hash, first_name, middle_name, last_name, contact_number, is_verified, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)");
            $stmtUser->execute([$username, $email, $password_hash, $first_name, $middle_name, $last_name, $contact_number, $now, $now]);
            $new_user_id = $pdo->lastInsertId();

            // 2. Assign Role
            $role_name = ($staff_role === 'Coach') ? 'Coach' : 'Staff';
            $stmtRoleCheck = $pdo->prepare("SELECT role_id FROM roles WHERE role_name = ? LIMIT 1");
            $stmtRoleCheck->execute([$role_name]);
            $role_id = $stmtRoleCheck->fetchColumn();

            if (!$role_id) {
                $pdo->prepare("INSERT INTO roles (role_name) VALUES (?)")->execute([$role_name]);
                $role_id = $pdo->lastInsertId();
            }

            $stmtUR = $pdo->prepare("INSERT INTO user_roles (user_id, role_id, gym_id, role_status, assigned_at) VALUES (?, ?, ?, 'Active', ?)");
            $stmtUR->execute([$new_user_id, $role_id, $gym_id, $now]);

            // 3. Add to Staff Table
            $stmtStaff = $pdo->prepare("INSERT INTO staff (user_id, gym_id, staff_role, employment_type, status, hire_date, created_at, updated_at) VALUES (?, ?, ?, ?, 'Active', ?, ?, ?)");
            $stmtStaff->execute([$new_user_id, $gym_id, $staff_role, $employment_type, date('Y-m-d'), $now, $now]);

            // --- SEND EMAIL CREDENTIALS ---
            require_once '../includes/mailer.php';
            $gymName = $gym['gym_name'] ?? 'Horizon Portal';
            $subject = "Your Staff Account Credentials - $gymName";
            $emailBody = getEmailTemplate(
                "Welcome to the Team!",
                "<p>Hello $first_name,</p>
                <p>An account has been created for you at <strong>$gymName</strong>.</p>
                <p>You can now log in to the portal using the following credentials:</p>
                <div style='background: #f4f4f4; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                    <strong>Username:</strong> $username<br>
                    <strong>Password:</strong> $plain_password
                </div>
                <p>Please change your password after your first login for security.</p>"
            );
            
            sendSystemEmail($email, $subject, $emailBody);

            $pdo->commit();
            $success = "Staff member $first_name added successfully! Credentials have been sent to their email.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}

// Fetch Gym Details
$stmtGym = $pdo->prepare("SELECT * FROM gyms WHERE gym_id = ?");
$stmtGym->execute([$gym_id]);
$gym = $stmtGym->fetch();

// Check Subscription (for sidebar)
$stmtSub = $pdo->prepare("SELECT ws.plan_name FROM client_subscriptions cs JOIN website_plans ws ON cs.website_plan_id = ws.website_plan_id WHERE cs.gym_id = ? AND cs.subscription_status = 'Active' LIMIT 1");
$stmtSub->execute([$gym_id]);
$sub = $stmtSub->fetch();

// Fetch CMS Page (for sidebar branding)
$stmtPage = $pdo->prepare("SELECT * FROM tenant_pages WHERE gym_id = ? LIMIT 1");
$stmtPage->execute([$gym_id]);
$page = $stmtPage->fetch();

$active_page = "add_staff";
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Add Staff | Horizon Tenant</title>
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
        .input-field { background: #1a1721; border: 1px solid #2d2838; border-radius: 12px; color: white; padding: 12px 16px; width: 100%; outline: none; transition: all 0.2s; }
        .input-field:focus { border-color: #8c2bee; outline: none; box-shadow: 0 0 0 2px rgba(140, 43, 238, 0.2); }
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
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
    <script>
        function updateSidebarClock() {
            const now = new Date();
            const clockEl = document.getElementById('sidebarClock');
            if (clockEl) {
                clockEl.textContent = now.toLocaleTimeString('en-US', { 
                    hour: '2-digit', minute: '2-digit', second: '2-digit' 
                });
            }
        }
        setInterval(updateSidebarClock, 1000);
        window.addEventListener('DOMContentLoaded', updateSidebarClock);
    </script>
</head>
<body class="antialiased flex h-screen overflow-hidden">

<nav class="flex flex-col w-64 lg:w-72 bg-[#0a090d] border-r border-white/5 sticky top-0 h-screen p-8 z-50 shrink-0">
    <div class="mb-12">
        <div class="flex items-center gap-3 mb-6">
            <div class="size-10 rounded-xl bg-primary flex items-center justify-center shadow-lg shrink-0 overflow-hidden">
                <?php if (!empty($page['logo_path'])): ?>
                    <img src="<?= $page['logo_path'] ?>" class="size-full object-contain">
                <?php else: ?>
                    <span class="material-symbols-outlined text-white text-2xl">bolt</span>
                <?php endif; ?>
            </div>
            <h1 class="text-lg font-black italic uppercase tracking-tighter text-white leading-none break-words line-clamp-2"><?= htmlspecialchars($page['page_title'] ?? $gym['gym_name']) ?></h1>
        </div>
        <div class="p-4 rounded-2xl bg-white/5 border border-white/5">
            <div class="mb-2">
                <p id="sidebarClock" class="text-white font-black italic text-base leading-none">00:00:00 AM</p>
            </div>
            <p class="text-[9px] font-black uppercase text-gray-500 tracking-[0.2em] leading-none mb-1"><?= date('l, M d') ?></p>
            <div class="pt-2 border-t border-white/5 mt-2">
                <p class="text-[8px] font-black uppercase text-gray-600 tracking-widest mb-1">Current Plan</p>
                <div class="flex items-center justify-between">
                    <p class="text-[10px] font-black uppercase text-white italic tracking-tighter"><?= htmlspecialchars($sub['plan_name'] ?? 'Standard Plan') ?></p>
                    <span class="px-2 py-0.5 rounded-md bg-primary/20 text-primary text-[8px] font-black uppercase tracking-widest">Active</span>
                </div>
            </div>
        </div>
    </div>
    
    <div class="flex flex-col gap-5 flex-1 overflow-y-auto no-scrollbar pr-2">
        <a href="tenant_dashboard.php" class="nav-link flex items-center gap-3 <?= ($active_page == 'dashboard') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl">grid_view</span> Dashboard
        </a>
        <a href="tenant_settings.php" class="nav-link flex items-center gap-3 <?= ($active_page == 'settings') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl">palette</span> Page Customize
        </a>
        <a href="staff_management.php" class="nav-link flex items-center gap-3 <?= ($active_page == 'staff' || $active_page == 'add_staff') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl">group</span> Staff Management
        </a>
        <a href="#" class="nav-link flex items-center gap-3 text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl">person_search</span> Member Directory
        </a>
        <a href="#" class="nav-link flex items-center gap-3 text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl">payments</span> Billing & Revenue
        </a>
    </div>

    <div class="mt-auto pt-8 border-t border-white/10">
        <a href="../logout.php" class="text-gray-400 hover:text-red-500 transition-colors flex items-center gap-3 group">
            <span class="material-symbols-outlined group-hover:translate-x-1 transition-transform">logout</span>
            <span class="nav-link">Sign Out</span>
        </a>
    </div>
</nav>

<div class="flex-1 p-10 max-w-[1400px] w-full mx-auto overflow-y-auto no-scrollbar">
    <header class="mb-10 flex flex-col md:flex-row justify-between items-start md:items-end gap-6">
        <div>
            <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white">Register <span class="text-primary">Staff</span></h2>
            <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-1">Industrial Team Management</p>
        </div>
    </header>

    <div class="glass-card p-8 md:p-12 max-w-4xl shadow-2xl relative overflow-hidden">
        <div class="absolute top-0 right-0 p-8 opacity-5">
            <span class="material-symbols-outlined text-[100px]">person_add</span>
        </div>

        <?php if($success): ?>
            <div class="mb-8 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-500 text-sm font-bold flex items-center gap-3">
                <span class="material-symbols-outlined">check_circle</span> <?= $success ?>
                <a href="staff_management.php" class="ml-auto underline text-emerald-400 hover:text-emerald-300">Return to Staff List</a>
            </div>
        <?php endif; ?>

        <?php if($error): ?>
            <div class="mb-8 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-sm font-bold flex items-center gap-3">
                <span class="material-symbols-outlined">error</span> <?= $error ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-6 relative z-10">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="space-y-2">
                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">FIRST NAME</label>
                    <input type="text" name="first_name" class="input-field" placeholder="John" required>
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">MIDDLE NAME</label>
                    <input type="text" name="middle_name" class="input-field" placeholder="Quincy">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">LAST NAME</label>
                    <input type="text" name="last_name" class="input-field" placeholder="Doe" required>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="space-y-2">
                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">EMAIL ADDRESS</label>
                    <input type="email" name="email" class="input-field" placeholder="staff@example.com" required>
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">CONTACT NUMBER</label>
                    <input type="text" name="contact_number" class="input-field" placeholder="09XX XXX XXXX" required>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="space-y-2">
                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">USERNAME</label>
                    <input type="text" name="username" class="input-field" placeholder="johndoe_coach" required>
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">PASSWORD</label>
                    <input type="password" name="password" class="input-field" placeholder="••••••••" required>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="space-y-2">
                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">ASSIGNED ROLE</label>
                    <select name="staff_role" class="input-field appearance-none">
                        <option value="Coach">Coach / Instructor</option>
                        <option value="Staff">General Staff / Receptionist</option>
                    </select>
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">EMPLOYMENT TYPE</label>
                    <select name="employment_type" class="input-field appearance-none">
                        <option value="Full-time">Full-time</option>
                        <option value="Part-time">Part-time</option>
                        <option value="Contract">Contract</option>
                    </select>
                </div>
            </div>

            <div class="flex flex-col md:flex-row gap-4 pt-6 border-t border-white/5">
                <button type="submit" class="flex-1 h-14 rounded-2xl bg-primary text-white text-xs font-black uppercase tracking-widest hover:opacity-90 transition-all shadow-lg shadow-primary/20">
                    Confirm & Register
                </button>
                <a href="staff_management.php" class="h-14 px-8 rounded-2xl bg-white/5 border border-white/10 flex items-center justify-center text-[10px] font-black uppercase tracking-widest hover:bg-white/10 transition-all">
                    Cancel
                </a>
            </div>
        </form>
    </div>
</div>

</body>
</html>
