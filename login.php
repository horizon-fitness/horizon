<?php
session_start();
// Include the database connection file.
require_once 'db.php';

// --- START AUTO-SEED DEFAULT SUPERADMIN ---
try {
    $adminCheck = $pdo->prepare("SELECT user_id FROM users WHERE username = 'superadmin' LIMIT 1");
    $adminCheck->execute();
    
    if ($adminCheck->rowCount() == 0) {
        $defaultPassword = 'superadmin123';
        $hash = password_hash($defaultPassword, PASSWORD_DEFAULT);
        $date = date('Y-m-d H:i:s');
        
        $stmtUser = $pdo->prepare("INSERT INTO users (username, email, password_hash, first_name, last_name, contact_number, is_verified, is_active, created_at, updated_at) VALUES ('superadmin', 'admin@horizon.com', ?, 'Super', 'Admin', '00000000000', 1, 1, ?, ?)");
        $stmtUser->execute([$hash, $date, $date]);
        $superAdminId = $pdo->lastInsertId();

        $roleCheck = $pdo->prepare("SELECT role_id FROM roles WHERE role_name = 'Superadmin' LIMIT 1");
        $roleCheck->execute();
        $role = $roleCheck->fetch(PDO::FETCH_ASSOC);
        
        if (!$role) {
            $pdo->query("INSERT INTO roles (role_name) VALUES ('Superadmin')");
            $roleId = $pdo->lastInsertId();
        } else {
            $roleId = $role['role_id'];
        }

        $stmtUserRole = $pdo->prepare("INSERT INTO user_roles (user_id, role_id, role_status, assigned_at) VALUES (?, ?, 'Active', ?)");
        $stmtUserRole->execute([$superAdminId, $roleId, $date]);
    }
} catch (PDOException $e) {
    error_log("Superadmin seed error: " . $e->getMessage());
}
// --- END AUTO-SEED DEFAULT SUPERADMIN ---

$error = '';
$branding = null;

if (isset($_GET['gym'])) {
    $slug = $_GET['gym'];
    $stmtBranding = $pdo->prepare("SELECT tp.*, g.gym_name, g.tenant_code FROM tenant_pages tp JOIN gyms g ON tp.gym_id = g.gym_id WHERE tp.page_slug = ? LIMIT 1");
    $stmtBranding->execute([$slug]);
    $branding = $stmtBranding->fetch(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = "Please enter both username and password.";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            if ($user['is_active'] == 0) {
                $error = "Your account has been deactivated. Please contact support.";
            } 
            elseif ($user['is_verified'] == 0) {
                $stmtApp = $pdo->prepare("SELECT email FROM gym_owner_applications WHERE user_id = ? ORDER BY submitted_at DESC LIMIT 1");
                $stmtApp->execute([$user['user_id']]);
                $app = $stmtApp->fetch(PDO::FETCH_ASSOC);
                $displayEmail = ($app && !empty($app['email'])) ? $app['email'] : $user['email'];

                $_SESSION['verify_user_id'] = $user['user_id'];
                $_SESSION['verify_email'] = $displayEmail;
                $gym_param = $branding ? "?gym=" . urlencode($slug) : "";
                header("Location: action/resend_otp.php" . $gym_param);
                exit;
            } 
            else {
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
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['first_name'] = $user['first_name'];
                    $_SESSION['last_name'] = $user['last_name'];
                    $_SESSION['role'] = $roleData['role_name'];
                    $_SESSION['gym_id'] = $roleData['gym_id'];

                    require_once 'includes/audit_logger.php';
                    log_audit_event($pdo, $user['user_id'], $roleData['gym_id'], 'Login', 'users', $user['user_id'], [], ['status' => 'Success']);

                    switch (strtolower($roleData['role_name'])) {
                        case 'superadmin':
                            header("Location: superadmin/superadmin_dashboard.php");
                            exit;
                        case 'admin':
                        case 'tenant': 
                            if ($branding && strtolower($roleData['role_name']) !== 'superadmin') {
                                if ($roleData['gym_id'] != $branding['gym_id']) {
                                    $error = "This account is not authorized to access " . $branding['gym_name'] . "'s portal.";
                                    unset($_SESSION['user_id']);
                                    break;
                                }
                            }
                            header("Location: tenant/tenant_gateway.php");
                            exit;
                        case 'coach':
                            header("Location: coach/coach_dashboard.php");
                            exit;
                        case 'staff':
                            if ($branding) {
                                if ($roleData['gym_id'] != $branding['gym_id']) {
                                    $error = "This account is not authorized to access " . $branding['gym_name'] . "'s portal.";
                                    unset($_SESSION['user_id']);
                                    break;
                                }
                            }
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
                    $stmtApp = $pdo->prepare("SELECT application_status FROM gym_owner_applications WHERE user_id = ? ORDER BY submitted_at DESC LIMIT 1");
                    $stmtApp->execute([$user['user_id']]);
                    $app = $stmtApp->fetch(PDO::FETCH_ASSOC);

                    if ($app) {
                        if ($app['application_status'] === 'Pending') {
                            $error = "Your gym application is currently under review.";
                        } elseif ($app['application_status'] === 'Rejected') {
                            $error = "Your gym application was rejected.";
                        } else {
                            $error = "Account approved but setup is incomplete.";
                        }
                    } else {
                        $error = "No active role found.";
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
                        "primary": "<?= $branding['theme_color'] ?? '#7f13ec' ?>",
                        "primary-dark": "<?= $branding['theme_color'] ?? '#5e0eb3' ?>",
                        "background-dark": "<?= $branding['bg_color'] ?? '#050505' ?>", 
                        "surface-dark": "rgba(21, 21, 24, 0.4)",
                        "text-secondary": "#ab9db9"
                    },
                    fontFamily: { 
                        "display": ["<?= $branding['font_family'] ?? 'Lexend' ?>", "sans-serif"],
                        "sans": ["Plus Jakarta Sans", "Inter", "sans-serif"]
                    },
                    borderRadius: { 'custom': '12px' }
                },
            },
        }
    </script>
    <style>
        /* Invisible Scroll System */
        *::-webkit-scrollbar { display: none; }
        * { -ms-overflow-style: none; scrollbar-width: none; }

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

        /* Gym Photo Integration */
        .login-bg-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(to bottom, rgba(5,5,5,0.8), #050505), url('https://images.unsplash.com/photo-1534438327276-14e5300c3a48?q=80&w=2070&auto=format&fit=crop');
            background-size: cover;
            background-position: center;
            opacity: 0.4;
            z-index: -1;
        }

        .dashboard-window {
            background: rgba(8, 8, 10, 0.8);
            backdrop-filter: blur(12px);
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
    <div class="login-bg-overlay"></div>

    <nav class="w-full px-8 py-6 flex justify-between items-center relative z-20">
        <a href="index.php" class="flex items-center gap-3 hover:opacity-80 transition-opacity">
            <div class="size-8 bg-primary/20 rounded-lg flex items-center justify-center border border-primary/30 overflow-hidden">
                <?php if ($branding && !empty($branding['logo_path'])): ?>
                    <img src="<?= $branding['logo_path'] ?>" class="size-full object-contain">
                <?php else: ?>
                    <img src="assests/horizon logo.png" alt="Horizon Logo" class="size-full object-contain rounded-lg">
                <?php endif; ?>
            </div>
            <h2 class="text-lg font-display font-bold text-white uppercase italic tracking-tighter"><?= $branding['gym_name'] ?? 'Horizon' ?> <span class="text-primary"><?= $branding ? 'Portal' : 'System' ?></span></h2>
        </a>
        
        <div class="flex items-center gap-3">
            <?php if(isset($_GET['gym'])): ?>
                <a href="portal.php?gym=<?= htmlspecialchars($_GET['gym']) ?>" class="text-[10px] font-display font-bold uppercase tracking-widest text-gray-400 hover:text-white transition-all mr-4 flex items-center gap-2">
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
            <div class="absolute -top-24 -right-24 w-48 h-48 bg-primary/10 blur-[60px] rounded-full pointer-events-none"></div>
            
            <div class="relative z-10">
                <div class="text-center mb-10">
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/5 border border-white/10 text-primary text-[9px] font-black uppercase tracking-[0.2em] mb-4">
                        Secure Access
                    </div>
                    <h1 class="text-4xl font-display font-black text-white uppercase italic tracking-tighter mb-2">
                        <?= $branding ? 'Access <span class="text-primary">Portal</span>' : 'Welcome <span class="text-primary">Back</span>' ?>
                    </h1>
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
                        <label class="text-[10px] font-display font-bold uppercase tracking-widest text-gray-500 ml-1">Username</label>
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
                            <label class="text-[10px] font-display font-bold uppercase tracking-widest text-gray-500">Password</label>
                            <a href="forgot_password.php<?= isset($_GET['gym']) ? '?gym='.htmlspecialchars($_GET['gym']) : '' ?>" class="text-[9px] font-display font-bold text-primary hover:text-white transition-colors uppercase tracking-widest">Forgot Password?</a>
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

    <footer class="relative z-20 w-full py-6 text-center -mt-10">
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