<?php
require_once 'db.php';

$gym_id = $_GET['gym'] ?? '';

if (empty($gym_id)) {
    header("Location: index.php");
    exit;
}

// Fetch Gym Details for context
$stmtGym = $pdo->prepare("SELECT gym_name FROM gyms WHERE gym_id = ? LIMIT 1");
$stmtGym->execute([$gym_id]);
$gym = $stmtGym->fetch();

if (!$gym) {
    die("Gym not found.");
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
    $birth_date = $_POST['birth_date'] ?? '2000-01-01';
    $sex = $_POST['sex'] ?? 'Not Specified';
    $occupation = trim($_POST['occupation'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $medical_history = trim($_POST['medical_history'] ?? '');
    $emergency_name = trim($_POST['emergency_contact_name'] ?? '');
    $emergency_phone = trim($_POST['emergency_contact_number'] ?? '');
    $now = date('Y-m-d H:i:s');

    if (empty($first_name) || empty($last_name) || empty($email) || empty($username) || empty($password) || empty($emergency_name) || empty($emergency_phone)) {
        $error = "All fields including Emergency Contact are required.";
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
            $member_code = "MBR-" . str_pad($new_user_id, 4, '0', STR_PAD_LEFT);
            $stmtMember = $pdo->prepare("INSERT INTO members (user_id, gym_id, member_code, birth_date, sex, occupation, address, medical_history, emergency_contact_name, emergency_contact_number, member_status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, ?)");
            $stmtMember->execute([$new_user_id, $gym_id, $member_code, $birth_date, $sex, $occupation, $address, $medical_history, $emergency_name, $emergency_phone, $now, $now]);

            $pdo->commit();

            // --- SEND EMAIL CREDENTIALS ---
            require_once 'includes/mailer.php';
            $gymName = $gym['gym_name'] ?? 'Horizon Gym';
            $subject = "Welcome to $gymName - Your Account Details";
            $emailBody = getEmailTemplate(
                "Welcome to the Community!",
                "<p>Hello $first_name,</p>
                <p>You have successfully registered at <strong>$gymName</strong>.</p>
                <p>Your account is ready for use on our mobile application and web portal.</p>
                <div style='background: #f4f4f4; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                    <strong>Username:</strong> $username<br>
                    <strong>Password:</strong> $password
                </div>
                <p>You can download our mobile app from the website to start tracking your progress!</p>"
            );
            
            sendSystemEmail($email, $subject, $emailBody);

            $success = "Registration successful! Your credentials have been sent to your email. You can now log in via our mobile application.";
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
    <title>Member Registration | <?= htmlspecialchars($gym['gym_name']) ?></title>
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
            <div class="size-16 rounded-[24px] bg-primary/10 text-primary flex items-center justify-center mx-auto mb-6 shadow-inner">
                <span class="material-symbols-outlined text-4xl">fitness_center</span>
            </div>
            <h1 class="text-3xl font-black italic uppercase tracking-tighter text-white mb-2">Join <span class="text-primary"><?= htmlspecialchars($gym['gym_name']) ?></span></h1>
            <p class="text-gray-500 text-sm font-medium">Create your profile to start training.</p>
        </div>

        <?php if($success): ?>
            <div class="mb-8 p-6 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-500 text-sm font-bold text-center leading-relaxed">
                <span class="material-symbols-outlined block text-4xl mb-4">task_alt</span>
                <?= $success ?>
                <div class="mt-6 flex flex-col gap-3">
                    <a href="login.php" class="h-12 rounded-xl bg-emerald-500 text-white flex items-center justify-center font-black uppercase tracking-widest text-[10px]">Login to Web Portal</a>
                    <span class="text-[9px] text-gray-600 uppercase tracking-widest">Or experience our mobile app</span>
                </div>
            </div>
        <?php else: ?>

            <?php if($error): ?>
                <div class="mb-8 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-xs font-bold flex items-center gap-3">
                    <span class="material-symbols-outlined">report</span> <?= $error ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-6">
                <div class="grid grid-cols-3 gap-4">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">First Name</label>
                        <input type="text" name="first_name" class="input-field" placeholder="John" autocomplete="given-name" required>
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">Middle Name</label>
                        <input type="text" name="middle_name" class="input-field" placeholder="Quincy">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">Last Name</label>
                        <input type="text" name="last_name" class="input-field" placeholder="Doe" autocomplete="family-name" required>
                    </div>
                </div>

                <div class="space-y-4">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">Current Address</label>
                        <input type="text" name="address" class="input-field" placeholder="123 Street, Brgy, City" required>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-2">
                            <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">Birth Date</label>
                            <input type="date" name="birth_date" class="input-field" required>
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">Sex</label>
                            <select name="sex" class="input-field">
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">Occupation</label>
                        <input type="text" name="occupation" class="input-field" placeholder="Software Engineer">
                    </div>

                    <div class="space-y-2">
                        <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">Medical History / Allergies</label>
                        <textarea name="medical_history" class="input-field h-24" placeholder="Mention any medical conditions or allergies..."></textarea>
                    </div>
                </div>
                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">Phone Number</label>
                    <input type="text" name="phone_number" class="input-field" placeholder="0912 345 6789" autocomplete="tel">
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">Birth Date</label>
                        <input type="date" name="birth_date" class="input-field" autocomplete="bday" required>
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">Sex</label>
                        <select name="sex" class="input-field appearance-none" autocomplete="sex">
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">Emergency Name</label>
                        <input type="text" name="emergency_contact_name" class="input-field" placeholder="Ice Contact Name" required>
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">Emergency Phone</label>
                        <input type="text" name="emergency_contact_number" class="input-field" placeholder="09XX XXX XXXX" autocomplete="tel" required>
                    </div>
                </div>

                <div class="space-y-2 pb-6">
                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">Initial Password</label>
                    <input type="password" name="password" class="input-field" placeholder="••••••••" autocomplete="new-password" required>
                </div>

                <button type="submit" class="w-full h-16 rounded-2xl bg-primary text-white text-sm font-black uppercase tracking-[0.2em] hover:scale-[1.02] transition-all shadow-lg shadow-primary/20">
                    Register as Member
                </button>
                
                <p class="text-center text-[10px] text-gray-600 font-bold uppercase tracking-widest pt-4">
                    Already a member? <a href="login.php" class="text-primary hover:underline ml-1">Login here</a>
                </p>
            </form>
        <?php endif; ?>
    </div>

</body>
</html>
