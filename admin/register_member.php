<?php
session_start();
require_once '../db.php';
require_once '../includes/mailer.php';

// Security Check: Only Staff/Coach
$role = strtolower($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || ($role !== 'staff' && $role !== 'coach')) {
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
    $phone = trim($_POST['phone_number'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $birth_date = $_POST['birth_date'] ?? '2000-01-01';
    $sex = $_POST['sex'] ?? 'Not Specified';
    $occupation = trim($_POST['occupation'] ?? '');
    $medical_history = trim($_POST['medical_history'] ?? '');
    $emergency_name = trim($_POST['emergency_contact_name'] ?? '');
    $emergency_phone = trim($_POST['emergency_contact_number'] ?? '');
    $now = date('Y-m-d H:i:s');

    if (empty($first_name) || empty($last_name) || empty($email)) {
        $error = "Name and Email are required.";
    } else {
        try {
            $pdo->beginTransaction();

            // Check if email exists
            $stmtCheck = $pdo->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
            $stmtCheck->execute([$email]);
            if ($stmtCheck->fetch()) {
                throw new Exception("A user with this email already exists.");
            }

            // Auto-generate credentials for walk-in
            $username = strtolower($first_name . $last_name . rand(100, 999));
            $plain_password = bin2hex(random_bytes(4));

            // 1. Create User Account
            $password_hash = password_hash($plain_password, PASSWORD_BCRYPT);
            $stmtUser = $pdo->prepare("INSERT INTO users (username, email, password_hash, first_name, middle_name, last_name, contact_number, is_verified, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)");
            $stmtUser->execute([$username, $email, $password_hash, $first_name, $middle_name, $last_name, $phone, $now, $now]);
            $new_user_id = $pdo->lastInsertId();

            // 2. Assign 'Member' Role
            $role_name = 'Member';
            $stmtRoleCheck = $pdo->prepare("SELECT role_id FROM roles WHERE role_name = ? LIMIT 1");
            $stmtRoleCheck->execute([$role_name]);
            $role_id = $stmtRoleCheck->fetchColumn();

            if (!$role_id) {
                $pdo->prepare("INSERT INTO roles (role_name) VALUES (?)")->execute([$role_name]);
                $role_id = $pdo->lastInsertId();
            }

            $stmtUR = $pdo->prepare("INSERT INTO user_roles (user_id, role_id, gym_id, role_status, assigned_at) VALUES (?, ?, ?, 'Active', ?)");
            $stmtUR->execute([$new_user_id, $role_id, $gym_id, $now]);

            // 3. Create Member Record
            $member_code = "WALK-" . str_pad($new_user_id, 4, '0', STR_PAD_LEFT);
            $stmtMember = $pdo->prepare("INSERT INTO members (user_id, gym_id, member_code, birth_date, sex, occupation, address, medical_history, emergency_contact_name, emergency_contact_number, member_status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, ?)");
            $stmtMember->execute([$new_user_id, $gym_id, $member_code, $birth_date, $sex, $occupation, $address, $medical_history, $emergency_name, $emergency_phone, $now, $now]);

            // 4. Record as Member Registration (For logs)
            $stmtReg = $pdo->prepare("INSERT INTO member_registrations (gym_id, user_id, email, registration_source, registered_by_user_id, registration_status, completed_at, created_at) VALUES (?, ?, ?, 'Walk-in', ?, 'Completed', ?, ?)");
            $stmtReg->execute([$gym_id, $new_user_id, $email, $_SESSION['user_id'], $now, $now]);

            // --- SEND EMAIL CREDENTIALS ---
            $stmtGym = $pdo->prepare("SELECT gym_name FROM gyms WHERE gym_id = ?");
            $stmtGym->execute([$gym_id]);
            $gym = $stmtGym->fetch();
            $gymName = $gym['gym_name'] ?? 'Horizon Gym';

            $subject = "Your New Membership Account - $gymName";
            $emailBody = getEmailTemplate(
                "Welcome to $gymName",
                "<p>Hello $first_name,</p>
                <p>Your membership has been registered as a walk-in at <strong>$gymName</strong>.</p>
                <p>You can now access your member profile on our web portal or mobile app using these credentials:</p>
                <div style='background: #f4f4f4; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                    <strong>Username:</strong> $username<br>
                    <strong>Password:</strong> $plain_password
                </div>
                <p>Welcome to the gym!</p>"
            );
            
            sendSystemEmail($email, $subject, $emailBody);

            $pdo->commit();
            $success = "Member registered successfully! Credentials have been sent to their email.";
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

// Fetch branding for sidebar
$stmtPage = $pdo->prepare("SELECT * FROM tenant_pages WHERE gym_id = ? LIMIT 1");
$stmtPage->execute([$gym_id]);
$page = $stmtPage->fetch();

$stmtSub = $pdo->prepare("SELECT ws.plan_name FROM client_subscriptions cs JOIN website_plans ws ON cs.website_plan_id = ws.website_plan_id WHERE cs.gym_id = ? AND cs.subscription_status = 'Active' LIMIT 1");
$stmtSub->execute([$gym_id]);
$sub = $stmtSub->fetch();

$active_page = "register_member";
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Walk-in Registration | Horizon Partners</title>
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
        .input-field:focus { border-color: #8c2bee; }
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
            <h1 class="text-lg font-black italic uppercase tracking-tighter text-white leading-none break-words line-clamp-2"><?= htmlspecialchars($gym['gym_name']) ?></h1>
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
        <a href="admin_dashboard.php" class="nav-link flex items-center gap-3 <?= ($active_page == 'dashboard') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl">grid_view</span> Dashboard
        </a>
        <a href="register_member.php" class="nav-link flex items-center gap-3 <?= ($active_page == 'register_member') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl">person_add</span> Walk-in Member
        </a>
        <a href="admin_users.php" class="nav-link flex items-center gap-3 text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl">group</span> My Users
        </a>
        <a href="admin_transaction.php" class="nav-link text-gray-400 hover:text-white flex items-center gap-3">
            <span class="material-symbols-outlined text-xl">receipt_long</span> Transactions
        </a>
        <a href="admin_attendance.php" class="nav-link text-gray-400 hover:text-white flex items-center gap-3">
            <span class="material-symbols-outlined text-xl">history</span> Attendance
        </a>
        <a href="admin_appointment.php" class="nav-link text-gray-400 hover:text-white flex items-center gap-3">
            <span class="material-symbols-outlined text-xl">event_available</span> Appointment
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
            <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white">Walk-in <span class="text-primary">Registration</span></h2>
            <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-1">Operational Member Onboarding</p>
        </div>
    </header>

    <div class="glass-card p-8 md:p-12 max-w-4xl shadow-2xl relative overflow-hidden">
        <h4 class="text-sm font-black italic uppercase tracking-tighter mb-8 flex items-center gap-2">
            <span class="material-symbols-outlined text-primary">person_add</span> Member Information
        </h4>

        <?php if($success): ?>
            <div class="mb-8 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-500 text-sm font-bold flex items-center gap-3">
                <span class="material-symbols-outlined">check_circle</span> <?= $success ?>
            </div>
        <?php endif; ?>

        <?php if($error): ?>
            <div class="mb-8 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-sm font-bold flex items-center gap-3">
                <span class="material-symbols-outlined">error</span> <?= $error ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-6">
            <div class="grid grid-cols-3 gap-6">
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

            <div class="space-y-2">
                <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">EMAIL ADDRESS</label>
                <input type="email" name="email" class="input-field" placeholder="member@example.com" required>
            </div>

            <div class="space-y-2">
                <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">HOME ADDRESS</label>
                <input type="text" name="address" class="input-field" placeholder="123 Street, Brgy, City" required>
            </div>

            <div class="grid grid-cols-2 gap-6">
                <div class="space-y-2">
                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">BIRTH DATE</label>
                    <input type="date" name="birth_date" class="input-field">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">SEX</label>
                    <select name="sex" class="input-field appearance-none">
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
            </div>

            <div class="space-y-2">
                <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">OCCUPATION</label>
                <input type="text" name="occupation" class="input-field" placeholder="Software Engineer">
            </div>

            <div class="space-y-2">
                <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">MEDICAL HISTORY / ALLERGIES</label>
                <textarea name="medical_history" class="input-field h-24" placeholder="Mention any medical conditions or allergies..."></textarea>
            </div>

            <div class="grid grid-cols-2 gap-6 pt-6 border-t border-white/5">
                <div class="space-y-2">
                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">EMERGENCY NAME</label>
                    <input type="text" name="emergency_contact_name" class="input-field" placeholder="Ice Contact">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">EMERGENCY PHONE</label>
                    <input type="text" name="emergency_contact_number" class="input-field" placeholder="09XX XXX XXXX">
                </div>
            </div>

            <button type="submit" class="w-full h-14 rounded-2xl bg-primary text-white text-xs font-black uppercase tracking-widest hover:opacity-90 transition-all shadow-lg shadow-primary/20">
                Register & Send Credentials
            </button>
        </form>
    </div>
</div>
</body>
</html>
