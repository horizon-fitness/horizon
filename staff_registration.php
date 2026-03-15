<?php
require_once 'db.php';

$gym_id = $_GET['gym'] ?? '';

if (empty($gym_id)) {
    header("Location: index.php");
    exit;
}

// Fetch Gym Details and check if staff self-reg is allowed
$stmtGym = $pdo->prepare("SELECT g.gym_name, tp.allow_staff_self_reg FROM gyms g JOIN tenant_pages tp ON g.gym_id = tp.gym_id WHERE g.gym_id = ? LIMIT 1");
$stmtGym->execute([$gym_id]);
$gym = $stmtGym->fetch();

if (!$gym) {
    die("Gym not found.");
}

if (!$gym['allow_staff_self_reg']) {
    die("Staff self-registration is currently disabled for this gym.");
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $phone = trim($_POST['phone_number'] ?? '');
    $staff_role = $_POST['staff_role'] ?? 'Staff';
    $employment_type = $_POST['employment_type'] ?? 'Full-time';
    $now = date('Y-m-d H:i:s');
    $today = date('Y-m-d');

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

            // 1. Create User Account
            $password_hash = password_hash($password, PASSWORD_BCRYPT);
            $stmtUser = $pdo->prepare("INSERT INTO users (username, email, password_hash, first_name, middle_name, last_name, contact_number, is_verified, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)");
            $stmtUser->execute([$username, $email, $password_hash, $first_name, $middle_name, $last_name, $phone, $now, $now]);
            $new_user_id = $pdo->lastInsertId();

            // 2. Assign 'Admin' or 'Staff' Role (Wait, transcript says 'Admin' if they are managing, but usually we assign 'Admin' role ID for staff)
            // Let's check available roles
            $stmtRole = $pdo->prepare("SELECT role_id FROM roles WHERE role_name = 'Admin' LIMIT 1");
            $stmtRole->execute();
            $role_id = $stmtRole->fetchColumn();

            $stmtUR = $pdo->prepare("INSERT INTO user_roles (user_id, role_id, gym_id, role_status, assigned_at) VALUES (?, ?, ?, 'Pending Approval', ?)");
            $stmtUR->execute([$new_user_id, $role_id, $gym_id, $now]);

            // 3. Create Staff Record
            $stmtStaff = $pdo->prepare("INSERT INTO staff (user_id, gym_id, staff_role, employment_type, hire_date, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'Pending Approval', ?, ?)");
            $stmtStaff->execute([$new_user_id, $gym_id, $staff_role, $employment_type, $today, $now, $now]);

            $pdo->commit();

            // --- SEND EMAIL CREDENTIALS ---
            if (file_exists('includes/mailer.php')) {
                require_once 'includes/mailer.php';
                $gymName = $gym['gym_name'] ?? 'Horizon Gym';
                $subject = "Staff Application Received - $gymName";
                $emailBody = getEmailTemplate(
                    "Welcome to the Team!",
                    "<p>Hello $first_name,</p>
                    <p>Your application to join the staff at <strong>$gymName</strong> has been received.</p>
                    <p>Your account is currently <strong>awaiting approval</strong> from the gym owner.</p>
                    <div style='background: #f4f4f4; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                        <strong>Username:</strong> $username<br>
                        <strong>Password:</strong> $password
                    </div>
                    <p>Once approved, you will be able to log in to the management dashboard.</p>"
                );
                sendSystemEmail($email, $subject, $emailBody);
            }

            $success = "Application submitted! Your credentials have been sent to your email. Please wait for the gym owner to approve your account.";
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
    <title>Staff Registration | <?= htmlspecialchars($gym['gym_name']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "#8c2bee", "background-dark": "#0a090d", "surface-dark": "#121017"}}}
        }
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; }
        .glass-card { background: #121017; border: 1px solid rgba(255,255,255,0.05); border-radius: 32px; backdrop-filter: blur(20px); }
        .input-field { background: #1a1721; border: 1px solid #2d2838; border-radius: 16px; color: white; padding: 16px 20px; width: 100%; transition: all 0.2s; }
        .input-field:focus { border-color: #8c2bee; outline: none; box-shadow: 0 0 0 2px rgba(140, 43, 238, 0.2); }
    </style>
</head>
<body class="antialiased min-h-screen flex items-center justify-center p-6 bg-[radial-gradient(circle_at_top_right,rgba(140,43,238,0.05),transparent)]">

    <div class="glass-card w-full max-w-xl p-8 md:p-12 shadow-[0_0_80px_rgba(0,0,0,0.5)]">
        
        <div class="text-center mb-12">
            <div class="size-16 rounded-[24px] bg-emerald-500/10 text-emerald-400 flex items-center justify-center mx-auto mb-6 shadow-inner">
                <span class="material-symbols-outlined text-4xl">shield_person</span>
            </div>
            <h1 class="text-3xl font-black italic uppercase tracking-tighter text-white mb-2">Join Our <span class="text-emerald-400">Team</span></h1>
            <p class="text-gray-500 text-sm font-medium">Apply at <?= htmlspecialchars($gym['gym_name']) ?> as a staff member.</p>
        </div>

        <?php if($success): ?>
            <div class="mb-8 p-6 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-500 text-sm font-bold text-center leading-relaxed">
                <span class="material-symbols-outlined block text-4xl mb-4">how_to_reg</span>
                <?= $success ?>
                <div class="mt-6">
                    <a href="portal.php?gym=<?= htmlspecialchars($_GET['gym_slug'] ?? '') ?>" class="h-12 rounded-xl bg-emerald-500 text-white flex items-center justify-center font-black uppercase tracking-widest text-[10px]">Back to Portal</a>
                </div>
            </div>
        <?php else: ?>

            <?php if($error): ?>
                <div class="mb-8 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-xs font-bold flex items-center gap-3">
                    <span class="material-symbols-outlined">report</span> <?= $error ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-6">
                <!-- Personal Info -->
                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">First Name</label>
                        <input type="text" name="first_name" class="input-field" placeholder="Jane" required>
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">Last Name</label>
                        <input type="text" name="last_name" class="input-field" placeholder="Smith" required>
                    </div>
                </div>

                <!-- Account Info -->
                <div class="space-y-4">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">Email Address</label>
                        <input type="email" name="email" class="input-field" placeholder="jane@example.com" required>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-2">
                            <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">Username</label>
                            <input type="text" name="username" class="input-field" placeholder="janesmith" required>
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">Password</label>
                            <input type="password" name="password" class="input-field" placeholder="••••••••" required>
                        </div>
                    </div>
                </div>

                <!-- Employment Info -->
                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">Desired Role</label>
                        <select name="staff_role" class="input-field appearance-none">
                            <option value="Admin">Admin / Staff</option>
                            <option value="Coach">Fitness Coach</option>
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

                <div class="space-y-2">
                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">Contact Number</label>
                    <input type="text" name="phone_number" class="input-field" placeholder="09XX XXX XXXX">
                </div>

                <div class="pt-4">
                    <button type="submit" class="w-full h-16 rounded-2xl bg-emerald-500 text-white text-sm font-black uppercase tracking-[0.2em] hover:scale-[1.02] transition-all shadow-lg shadow-emerald-500/20">
                        Submit Application
                    </button>
                </div>
                
                <p class="text-center text-[10px] text-gray-600 font-bold uppercase tracking-widest pt-4">
                    Already have an account? <a href="login.php" class="text-primary hover:underline ml-1">Login here</a>
                </p>
            </form>
        <?php endif; ?>
    </div>

</body>
</html>
