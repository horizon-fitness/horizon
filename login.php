<?php
session_start();
// Include the database connection file. Adjust path if necessary.
require_once 'db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = "Please enter both username and password.";
    } else {
        // Fetch user from the database
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Verify user exists and password is correct
        if ($user && password_verify($password, $user['password_hash'])) {
            
            // 1. Check if the account is active
            if ($user['is_active'] == 0) {
                $error = "Your account has been deactivated. Please contact support.";
            } 
            // 2. Check if the email is verified via OTP
            elseif ($user['is_verified'] == 0) {
                // Not verified, redirect them to the verification page
                $_SESSION['verify_user_id'] = $user['user_id'];
                $_SESSION['verify_email'] = $user['email'];
                header("Location: tenant/verify_email.php");
                exit;
            } 
            else {
                // 3. Fetch the user's role from the database
                $stmtRole = $pdo->prepare("
                    SELECT r.role_name, ur.gym_id, ur.role_status 
                    FROM user_roles ur 
                    JOIN roles r ON ur.role_id = r.role_id 
                    WHERE ur.user_id = ? AND ur.role_status = 'Active' 
                    LIMIT 1
                ");
                $stmtRole->execute([$user['user_id']]);
                $roleData = $stmtRole->fetch(PDO::FETCH_ASSOC);

                if ($roleData) {
                    // Set session variables for successful login
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['first_name'] = $user['first_name'];
                    $_SESSION['last_name'] = $user['last_name'];
                    $_SESSION['role'] = $roleData['role_name'];
                    $_SESSION['gym_id'] = $roleData['gym_id'];

                    // Redirect based on user role
                    switch (strtolower($roleData['role_name'])) {
                        case 'superadmin':
                            header("Location: superadmin/superadmin_dashboard.php");
                            exit;
                        case 'admin':
                        case 'tenant': // Assuming gym owners get the 'admin' or 'tenant' role
                            header("Location: admin/admin_dashboard.php");
                            exit;
                        case 'coach':
                            header("Location: coach/coach_dashboard.php");
                            exit;
                        case 'member':
                            header("Location: member/member_dashboard.php");
                            exit;
                        default:
                            $error = "Invalid role configuration. Please contact support.";
                            break;
                    }
                } else {
                    // No active role found. Check if they are a Pending Gym Owner
                    $stmtApp = $pdo->prepare("SELECT application_status FROM gym_owner_applications WHERE user_id = ? ORDER BY submitted_at DESC LIMIT 1");
                    $stmtApp->execute([$user['user_id']]);
                    $app = $stmtApp->fetch(PDO::FETCH_ASSOC);

                    if ($app) {
                        if ($app['application_status'] === 'Pending') {
                            $error = "Your gym application is currently under review. We will notify you once approved.";
                        } elseif ($app['application_status'] === 'Rejected') {
                            $error = "Your gym application was rejected. Please contact our support team.";
                        } else {
                            $error = "Your account is approved but setup is incomplete. Contact support.";
                        }
                    } else {
                        $error = "You do not have an active role in the system. Contact support.";
                    }
                }
            }
        } else {
            $error = "Invalid username or password.";
        }
    }
}
?>

<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Member Portal | Herdoza Fitness</title>

    <link href="https://fonts.googleapis.com" rel="preconnect"/>
    <link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>

    <script id="tailwind-config">
    tailwind.config = {
        darkMode: "class",
        theme: {
            extend: {
                colors: {
                    "primary": "#7f13ec",
                    "primary-hover": "#6a11c9",
                    "background-dark": "#0a090d",
                    "card-dark": "#121017",
                    "input-dark": "#1a1721",
                    "input-border": "#2d2838",
                },
                fontFamily: { "display": ["Lexend", "sans-serif"] },
            },
        },
    }
    </script>
</head>

<body class="bg-background-dark text-white font-display min-h-screen flex flex-col antialiased relative overflow-hidden">

<div class="fixed inset-0 z-0">
    <img class="w-full h-full object-cover" src="https://images.unsplash.com/photo-1534438327276-14e5300c3a48?q=80&w=2070&auto=format&fit=crop" alt="Gym Background"/>
    <div class="absolute inset-0 bg-[#0a090d]/85 backdrop-blur-sm"></div>
</div>

<header class="relative z-20 w-full px-8 py-6 flex justify-between items-center">
    <div class="flex items-center gap-3">
         <span class="text-xl font-black tracking-tight uppercase italic">Horizon <span class="text-primary">Systems</span></span>
    </div>
    <div class="flex items-center gap-4">
        <span class="text-sm text-gray-400 hidden sm:block">New to the family?</span>
        <a href="tenant/tenant_application.php" class="px-6 py-2 rounded-full border border-primary/30 hover:bg-primary/10 transition-all text-sm font-bold text-primary">Register Gym</a>
    </div>
</header>

<main class="flex-1 flex items-center justify-center p-4 relative z-10">
    <div class="w-full max-w-[480px] rounded-[32px] overflow-hidden border border-white/5 bg-[#121017]/80 backdrop-blur-xl shadow-[0_0_50px_rgba(127,19,236,0.15)] p-10 sm:p-14">
        
        <div class="text-center space-y-3 mb-12">
            <h2 class="text-4xl font-black tracking-tight uppercase italic">Welcome Back</h2>
            <p class="text-gray-400 font-medium">Enter your credentials to access your account.</p>
        </div>

        <?php if (!empty($error)): ?>
        <div class="mb-6 p-4 rounded-2xl bg-red-500/10 border border-red-500/20 text-red-400 text-sm flex items-center gap-3 font-semibold">
            <span class="material-symbols-outlined">security</span>
            <?= $error ?>
        </div>
        <?php endif; ?>

        <form method="POST" class="space-y-8">
            <div class="space-y-2">
                <label class="text-xs font-black uppercase tracking-widest text-gray-500 ml-1">Account Identity</label>
                <div class="relative group">
                    <span class="absolute left-4 top-1/2 -translate-y-1/2 material-symbols-outlined text-gray-600 group-focus-within:text-primary transition-colors">person</span>
                    <input
                        class="flex h-14 w-full rounded-xl border border-input-border bg-black/40 pl-12 pr-4 text-base text-white placeholder:text-gray-700 focus:outline-none focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all"
                        name="username"
                        placeholder="Username"
                        required
                        type="text"
                    />
                </div>
            </div>

            <div class="space-y-2">
                <div class="flex justify-between items-center px-1">
                    <label class="text-xs font-black uppercase tracking-widest text-gray-500">Security Key</label>
                    <a href="#" class="text-[10px] uppercase font-black text-primary hover:underline tracking-tighter">Forgot key?</a>
                </div>
                <div class="relative group">
                    <span class="absolute left-4 top-1/2 -translate-y-1/2 material-symbols-outlined text-gray-600 group-focus-within:text-primary transition-colors">lock</span>
                    <input
                        id="login-password"
                        class="flex h-14 w-full rounded-xl border border-input-border bg-black/40 pl-12 pr-14 text-base text-white placeholder:text-gray-700 focus:outline-none focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all"
                        name="password"
                        placeholder="••••••••"
                        required
                        type="password"
                    />
                    <button type="button" onclick="toggleLoginPassword()" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-600 hover:text-primary transition-colors">
                        <span id="eye-icon" class="material-symbols-outlined text-[20px]">visibility</span>
                    </button>
                </div>
            </div>

            <button
                class="w-full h-14 mt-4 rounded-xl bg-primary hover:bg-primary-hover text-white font-black uppercase tracking-widest text-sm transition-all shadow-lg shadow-primary/25 flex items-center justify-center gap-3 hover:scale-[1.01] active:scale-[0.99]"
                type="submit">
                Authorize Entry
                <span class="material-symbols-outlined text-xl">arrow_forward</span>
            </button>
        </form>

        <div class="text-center mt-10">
            <p class="text-xs text-gray-500 font-medium">
                Not registered? 
                <a class="text-primary font-black uppercase tracking-tighter hover:underline ml-1" href="tenant/tenant_application.php">Create Account</a>
            </p>
        </div>
    </div>
</main>

<footer class="relative z-20 w-full py-6 text-center opacity-30">
    <span class="text-[9px] font-black uppercase tracking-[0.4em] text-gray-500">Secure Environment Access</span>
</footer>

<script>
function toggleLoginPassword() {
    const passwordInput = document.getElementById('login-password');
    const eyeIcon = document.getElementById('eye-icon');
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        eyeIcon.textContent = 'visibility_off';
    } else {
        passwordInput.type = 'password';
        eyeIcon.textContent = 'visibility';
    }
}
</script>

</body>
</html>