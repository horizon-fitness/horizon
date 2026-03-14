<?php
session_start();
require_once '../db.php';

// Security Check: Only Tenants/Admins
$role = strtolower($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || ($role !== 'tenant' && $role !== 'admin')) {
    header("Location: ../login.php");
    exit;
}

$gym_id = $_SESSION['gym_id'];
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $staff_role = $_POST['staff_role'] ?? 'Staff';
    $employment_type = $_POST['employment_type'] ?? 'Full-time';
    $now = date('Y-m-d H:i:s');

    if (empty($first_name) || empty($last_name) || empty($email) || empty($username) || empty($password)) {
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

            // 1. Create User
            $password_hash = password_hash($password, PASSWORD_BCRYPT);
            $stmtUser = $pdo->prepare("INSERT INTO users (username, email, password_hash, first_name, last_name, contact_number, is_verified, created_at, updated_at) VALUES (?, ?, ?, ?, ?, '', 1, ?, ?)");
            $stmtUser->execute([$username, $email, $password_hash, $first_name, $last_name, $now, $now]);
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

            $pdo->commit();
            $success = "Staff member $first_name added successfully!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}
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
            theme: { extend: { colors: { "primary": "#8c2bee", "background-dark": "#0a090d", "surface-dark": "#121017", "border-subtle": "rgba(255,255,255,0.05)"}}}
        }
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; }
        .glass-card { background: #121017; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        .input-field { background: #1a1721; border: 1px solid #2d2838; border-radius: 12px; color: white; padding: 12px 16px; width: 100%; transition: all 0.2s; }
        .input-field:focus { border-color: #8c2bee; outline: none; box-shadow: 0 0 0 2px rgba(140, 43, 238, 0.2); }
    </style>
</head>
<body class="antialiased min-h-screen p-6 md:p-12 flex items-center justify-center">

    <div class="glass-card w-full max-w-2xl p-8 md:p-12 shadow-2xl relative overflow-hidden">
        <div class="absolute top-0 right-0 p-8 opacity-5">
            <span class="material-symbols-outlined text-[100px]">person_add</span>
        </div>

        <header class="mb-10 text-center relative z-10">
            <h1 class="text-3xl font-black italic uppercase tracking-tighter mb-2">Register <span class="text-primary">Staff</span></h1>
            <p class="text-gray-500 text-sm font-medium">Add a new coach or manager to your facility.</p>
        </header>

        <?php if($success): ?>
            <div class="mb-8 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-500 text-sm font-bold flex items-center gap-3">
                <span class="material-symbols-outlined">check_circle</span> <?= $success ?>
                <a href="tenant_dashboard.php" class="ml-auto underline">Return to Dashboard</a>
            </div>
        <?php endif; ?>

        <?php if($error): ?>
            <div class="mb-8 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-sm font-bold flex items-center gap-3">
                <span class="material-symbols-outlined">error</span> <?= $error ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-6 relative z-10">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="space-y-2">
                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">First Name</label>
                    <input type="text" name="first_name" class="input-field" placeholder="John" required>
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">Last Name</label>
                    <input type="text" name="last_name" class="input-field" placeholder="Doe" required>
                </div>
            </div>

            <div class="space-y-2">
                <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">Email Address</label>
                <input type="email" name="email" class="input-field" placeholder="staff@example.com" required>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="space-y-2">
                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">Username</label>
                    <input type="text" name="username" class="input-field" placeholder="johndoe_coach" required>
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">Password</label>
                    <input type="password" name="password" class="input-field" placeholder="••••••••" required>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="space-y-2">
                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">Assigned Role</label>
                    <select name="staff_role" class="input-field appearance-none">
                        <option value="Coach">Coach / Instructor</option>
                        <option value="Staff">General Staff / Receptionist</option>
                        <option value="Manager">Manager</option>
                    </select>
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">Employment Type</label>
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
                <a href="tenant_dashboard.php" class="h-14 px-8 rounded-2xl bg-white/5 border border-white/10 flex items-center justify-center text-[10px] font-black uppercase tracking-widest hover:bg-white/10 transition-all">
                    Cancel
                </a>
            </div>
        </form>
    </div>

</body>
</html>
