<?php
session_start();
// Include the database connection file.
require_once 'db.php';

// --- START AUTO-SEED DEFAULT SUPERADMIN ---
try {
    $adminCheck = $pdo->prepare("SELECT user_id FROM users WHERE username = 'superadmin' LIMIT 1");
    $adminCheck->execute();
    
    // If the superadmin account does not exist, create it automatically
    if ($adminCheck->rowCount() == 0) {
        $defaultPassword = 'superadmin123';
        $hash = password_hash($defaultPassword, PASSWORD_DEFAULT);
        $date = date('Y-m-d H:i:s');
        
        // 1. Insert default Super Admin user
        $stmtUser = $pdo->prepare("INSERT INTO users (username, email, password_hash, first_name, last_name, contact_number, is_verified, is_active, created_at, updated_at) VALUES ('superadmin', 'admin@horizon.com', ?, 'Super', 'Admin', '00000000000', 1, 1, ?, ?)");
        $stmtUser->execute([$hash, $date, $date]);
        $superAdminId = $pdo->lastInsertId();

        // 2. Ensure 'Superadmin' role exists
        $roleCheck = $pdo->prepare("SELECT role_id FROM roles WHERE role_name = 'Superadmin' LIMIT 1");
        $roleCheck->execute();
        $role = $roleCheck->fetch(PDO::FETCH_ASSOC);
        
        if (!$role) {
            $pdo->query("INSERT INTO roles (role_name) VALUES ('Superadmin')");
            $roleId = $pdo->lastInsertId();
        } else {
            $roleId = $role['role_id'];
        }

        // 3. Assign Superadmin role to the user
        $stmtUserRole = $pdo->prepare("INSERT INTO user_roles (user_id, role_id, role_status, assigned_at) VALUES (?, ?, 'Active', ?)");
        $stmtUserRole->execute([$superAdminId, $roleId, $date]);
    }
} catch (PDOException $e) {
    // Silently log errors so it doesn't break the login page if tables are missing
    error_log("Superadmin seed error: " . $e->getMessage());
}
// --- END AUTO-SEED DEFAULT SUPERADMIN ---

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
                // Fetch gym email from application if it exists, otherwise use personal email
                $stmtApp = $pdo->prepare("SELECT email FROM gym_owner_applications WHERE user_id = ? ORDER BY submitted_at DESC LIMIT 1");
                $stmtApp->execute([$user['user_id']]);
                $app = $stmtApp->fetch(PDO::FETCH_ASSOC);
                $displayEmail = ($app && !empty($app['email'])) ? $app['email'] : $user['email'];

                // Not verified, redirect them to the verification page
                $_SESSION['verify_user_id'] = $user['user_id'];
                $_SESSION['verify_email'] = $displayEmail;
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

                    // Redirect based on user role (Following Monday Activity Rules)
                    switch (strtolower($roleData['role_name'])) {
                        case 'superadmin':
                            header("Location: superadmin/superadmin_dashboard.php");
                            exit;
                        case 'admin':
                        case 'tenant': 
                            // Directed to the tenant's primary entry-point
                            header("Location: tenant/tenant_gateway.php");
                            exit;
                        case 'coach':
                        case 'staff':
                            header("Location: admin/admin_dashboard.php");
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
                            $error = "Your account is approved but setup is incomplete. Wait for the Admin to assign your page.";
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
    <meta content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" name="viewport"/>
    <title>Horizon | Secure Login</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com"/>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin=""/>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@300;400;500;600&family=Lexend:wght@300;400;500;700;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "#7f13ec",
                        "primary-dark": "#5e0eb3",
                        "background-dark": "#050505", 
                        "surface-dark": "rgba(21, 21, 24, 0.4)",
                        "text-secondary": "#ab9db9"
                    },
                    fontFamily: { 
                        "display": ["Lexend", "sans-serif"],
                        "sans": ["Plus Jakarta Sans", "Inter", "sans-serif"]
                    },
                    borderRadius: { 'custom': '12px' }
                },
            },
        }
    </script>
    <style>
        html, body { 
            background-color: #050505 !important; 
            color: #f3f4f6;
            margin: 0;
            padding: 0;
            min-height: 100vh;
        }
        
        .hero-glow {
            background-image: radial-gradient(circle at 50% -10%, rgba(127, 19, 236, 0.18), transparent 70%);
        }

        .dashboard-window {
            background: #08080a;
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 40px 100px -20px rgba(0, 0, 0, 1);
        }

        .input-gradient-focus:focus-within {
            border-color: #7f13ec;
            box-shadow: 0 0 0 1px rgba(127, 19, 236, 0.3);
        }
    </style>
</head>

<body class="font-sans antialiased min-h-screen flex flex-col hero-glow">

    <nav class="w-full px-8 py-6 flex justify-between items-center relative z-20">
        <div class="flex items-center gap-3">
            <div class="size-8 bg-primary/20 rounded-lg flex items-center justify-center border border-primary/30">
                <span class="material-symbols-outlined text-primary text-xl">blur_on</span>
            </div>
            <h2 class="text-lg font-display font-bold text-white uppercase italic tracking-tighter">Horizon <span class="text-primary">System</span></h2>
        </div>
        
        <div class="flex items-center gap-4">
            <?php if(isset($_GET['gym'])): ?>
                <a href="portal.php?gym=<?= htmlspecialchars($_GET['gym']) ?>" class="text-[10px] font-display font-bold uppercase tracking-widest text-gray-500 hover:text-white transition-all mr-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-sm">arrow_back</span>
                    Back to Website
                </a>
            <?php endif; ?>
            <a href="tenant/tenant_application.php" class="font-display bg-white/5 hover:bg-white/10 text-white border border-white/10 px-5 py-2.5 rounded-custom text-[10px] font-bold uppercase tracking-widest transition-all">
                Register Gym
            </a>
        </div>
    </nav>

    <main class="flex-1 flex items-center justify-center p-6 relative z-10">
        <div class="dashboard-window w-full max-w-[440px] rounded-2xl p-10 md:p-12 relative overflow-hidden">
            <!-- Subtle accent glow inside the card -->
            <div class="absolute -top-24 -right-24 w-48 h-48 bg-primary/10 blur-[60px] rounded-full pointer-events-none"></div>
            
            <div class="relative z-10">
                <div class="text-center mb-10">
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/5 border border-white/10 text-primary text-[9px] font-black uppercase tracking-[0.2em] mb-4">
                        Secure Access
                    </div>
                    <h1 class="text-4xl font-display font-black text-white uppercase italic tracking-tighter mb-2">Welcome <span class="text-primary">Back</span></h1>
                    <p class="text-xs text-gray-500 font-medium uppercase tracking-widest">Authorized Personnel Only</p>
                </div>

                <?php if (!empty($error)): ?>
                <div class="mb-8 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-[11px] flex items-center gap-3 font-bold uppercase tracking-wider">
                    <span class="material-symbols-outlined text-lg">security</span>
                    <?= $error ?>
                </div>
                <?php endif; ?>

                <form method="POST" class="space-y-6">
                    <div class="space-y-2">
                        <label class="text-[10px] font-display font-bold uppercase tracking-widest text-gray-500 ml-1">Identity</label>
                        <div class="relative group input-gradient-focus rounded-xl transition-all">
                            <span class="absolute left-4 top-1/2 -translate-y-1/2 material-symbols-outlined text-gray-600 group-focus-within:text-primary transition-colors text-xl">person</span>
                            <input
                                class="flex h-14 w-full rounded-xl border border-white/5 bg-white/[0.02] pl-12 pr-4 text-sm text-white placeholder:text-gray-700 focus:outline-none transition-all"
                                name="username"
                                placeholder="Username"
                                autocomplete="username"
                                required
                                type="text"
                            />
                        </div>
                    </div>

                    <div class="space-y-2">
                        <div class="flex justify-between items-center px-1">
                            <label class="text-[10px] font-display font-bold uppercase tracking-widest text-gray-500">Security Key</label>
                            <a href="#" class="text-[9px] font-display font-bold text-primary hover:text-white transition-colors uppercase tracking-widest">Forgot?</a>
                        </div>
                        <div class="relative group input-gradient-focus rounded-xl transition-all">
                            <span class="absolute left-4 top-1/2 -translate-y-1/2 material-symbols-outlined text-gray-600 group-focus-within:text-primary transition-colors text-xl">lock</span>
                            <input
                                id="login-password"
                                class="flex h-14 w-full rounded-xl border border-white/5 bg-white/[0.02] pl-12 pr-14 text-sm text-white placeholder:text-gray-700 focus:outline-none transition-all"
                                name="password"
                                placeholder="••••••••"
                                autocomplete="current-password"
                                required
                                type="password"
                            />
                            <button type="button" onclick="toggleLoginPassword()" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-600 hover:text-primary transition-colors">
                                <span id="eye-icon" class="material-symbols-outlined text-[20px]">visibility</span>
                            </button>
                        </div>
                    </div>

                    <button
                        class="w-full h-14 mt-6 rounded-xl bg-primary hover:bg-primary-dark text-white font-display font-bold uppercase tracking-widest text-[11px] transition-all shadow-lg shadow-primary/20 flex items-center justify-center gap-3 hover:scale-[1.02] active:scale-[0.98]"
                        type="submit">
                        Authorize Entry
                        <span class="material-symbols-outlined text-lg">arrow_forward</span>
                    </button>
                </form>

                <div class="text-center mt-10 pt-8 border-t border-white/5">
                    <p class="text-[10px] text-gray-600 font-bold uppercase tracking-widest">
                        New to the family? 
                        <a class="text-primary hover:text-white transition-colors ml-1" href="tenant/tenant_application.php">Create Account</a>
                    </p>
                </div>
            </div>
        </div>
    </main>

    <footer class="relative z-20 w-full py-10 text-center">
        <p class="text-[9px] font-display font-bold text-gray-700 uppercase tracking-[0.4em]">
            © 2026 HORIZON SYSTEM. SECURE ENVIRONMENT.
        </p>
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
<?php
// Note: The PHP logic remains at the top of the file.
?>