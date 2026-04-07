<?php
session_start();

// Use absolute paths for includes to avoid issues in subdirectories
$root = dirname(__DIR__);
require_once $root . '/db.php';
require_once $root . '/includes/member_processor.php';
require_once $root . '/includes/mailer.php';

$gym_slug = $_GET['gym'] ?? '';

if (empty($gym_slug)) {
    die("Direct access not allowed. Please register through the facility portal.");
}

// Exactly match portal.php logic for tenant data
$stmtPage = $pdo->prepare("SELECT tp.*, g.gym_name, g.gym_id, g.profile_picture as gym_logo, g.email as gym_email, g.contact_number as gym_contact 
                           FROM tenant_pages tp 
                           JOIN gyms g ON tp.gym_id = g.gym_id 
                           WHERE tp.page_slug = ? AND tp.is_active = 1 LIMIT 1");
$stmtPage->execute([$gym_slug]);
$page = $stmtPage->fetch();

if (!$page) {
    die("Gym page not found or is currently inactive.");
}

$gym_id = $page['gym_id'];
$gym_name = $page['gym_name'];

// Branding Configuration (Matches portal.php)
$primary_color = $page['theme_color'] ?? '#8c2bee';
$secondary_color = $page['secondary_color'] ?? '#a1a1aa';
$bg_color = $page['bg_color'] ?? '#0a090d';
$font_family = $page['font_family'] ?? 'Lexend';

function hexToRgb($hex) {
    $hex = str_replace("#", "", $hex);
    if (strlen($hex) == 3) {
        $r = hexdec(substr($hex, 0, 1) . substr($hex, 0, 1));
        $g = hexdec(substr($hex, 1, 1) . substr($hex, 1, 1));
        $b = hexdec(substr($hex, 2, 1) . substr($hex, 2, 1));
    } else {
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
    }
    return "$r, $g, $b";
}
$primary_rgb = hexToRgb($primary_color);

$success = '';
$error = '';
$otp_sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['otp_code'])) {
            // PHASE 2: Verify OTP
            if (!isset($_SESSION['pending_reg_otp']) || $_POST['otp_code'] !== $_SESSION['pending_reg_otp']) {
                throw new Exception("Invalid verification code. Please try again.");
            }
            if (time() > ($_SESSION['pending_reg_expiry'] ?? 0)) {
                throw new Exception("Verification code has expired. Please restart the process.");
            }
            
            $result = processMemberRegistration($pdo, $_SESSION['pending_reg_data']);
            $success = "Registration successful! You can now log in using your credentials on our mobile app.";
            unset($_SESSION['pending_reg_otp'], $_SESSION['pending_reg_data'], $_SESSION['pending_reg_expiry']);
            $_POST = [];
        } else {
            // PHASE 1: Send OTP
            $email = trim($_POST['email'] ?? '');
            $username = trim($_POST['username'] ?? '');
            
            // Preliminary checks
            $stmtCheck = $pdo->prepare("SELECT user_id FROM users WHERE email = ? OR username = ? LIMIT 1");
            $stmtCheck->execute([$email, $username]);
            if ($stmtCheck->fetch()) {
                throw new Exception("Email or Username is already registered.");
            }

            $otp = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
            $_SESSION['pending_reg_otp'] = $otp;
            $_SESSION['pending_reg_data'] = array_merge($_POST, ['gym_id' => $gym_id, 'registration_source' => 'Self']);
            $_SESSION['pending_reg_expiry'] = time() + (10 * 60); // 10 mins

            $subject = "Verification Code - $gym_name";
            $emailBody = getEmailTemplate(
                "Verify Your Registration",
                "<p>Hello,</p>
                <p>Thank you for choosing <strong>$gym_name</strong>. Use the verification code below to complete your registration:</p>
                <div style='background: #111; padding: 30px; border-radius: 12px; margin: 20px 0; text-align: center; font-size: 32px; font-weight: bold; letter-spacing: 12px; color: " . $primary_color . "; border: 1px solid rgba(255,255,255,0.1);'>
                    $otp
                </div>
                <p>This code will expire in 10 minutes.</p>"
            );
            
            if (!sendSystemEmail($email, $subject, $emailBody)) {
                throw new Exception("Failed to send verification email. Please check your email address.");
            }
            
            $otp_sent = true;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Member Registration | <?= htmlspecialchars($gym_name) ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com"/>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin=""/>
    <link href="https://fonts.googleapis.com/css2?family=<?= urlencode($font_family) ?>:wght@300;400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@300;400;500;600&family=Lexend:wght@300;400;500;700;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>

    <script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "<?= $primary_color ?>",
                        "primary-dark": "<?= $primary_color ?>dd",
                        "background-dark": "<?= $bg_color ?>",
                        "surface-dark": "rgba(255, 255, 255, 0.02)",
                    },
                    fontFamily: { 
                        "display": ["<?= $font_family ?>", "Lexend", "sans-serif"],
                        "sans": ["Plus Jakarta Sans", "Inter", "sans-serif"]
                    },
                    borderRadius: { 'custom': '12px' }
                },
            },
        }
    </script>
    <style>
        *::-webkit-scrollbar { display: none !important; }
        * { -ms-overflow-style: none !important; scrollbar-width: none !important; }
        
        body { 
            background-color: <?= $bg_color ?> !important; 
            color: #f3f4f6;
            margin: 0;
            padding: 0;
            min-height: 100vh;
        }
        
        .hero-glow {
            background-image: radial-gradient(circle at 50% -10%, rgba(<?= $primary_rgb ?>, 0.15), transparent 70%);
        }

        .dashboard-window {
            background: rgba(255, 255, 255, 0.01);
            backdrop-filter: blur(24px);
            border: 1px solid rgba(255, 255, 255, 0.05);
            box-shadow: 0 40px 100px -20px rgba(0, 0, 0, 0.8);
        }

        .input-field {
            background: rgba(255, 255, 255, 0.02) !important;
            border: 1px solid rgba(255, 255, 255, 0.08) !important;
            border-radius: 12px !important;
            color: #ffffff !important;
            padding: 12px 18px !important;
            width: 100%;
            outline: none;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            font-size: 13px;
        }

        /* Prevent browser autofill from changing background color */
        .input-field:-webkit-autofill,
        .input-field:-webkit-autofill:hover, 
        .input-field:-webkit-autofill:focus, 
        .input-field:-webkit-autofill:active {
            -webkit-box-shadow: 0 0 0 1000px <?= $bg_color ?> inset !important;
            -webkit-text-fill-color: #ffffff !important;
            transition: background-color 5000s ease-in-out 0s;
        }

        .input-field:focus {
            border-color: <?= $primary_color ?> !important;
            background: rgba(255, 255, 255, 0.05) !important;
            box-shadow: 0 0 0 1px rgba(<?= $primary_rgb ?>, 0.3) !important;
        }

        .input-field::placeholder {
            color: #6b7280 !important;
            opacity: 0.5;
        }

        .step-hidden { display: none; }
        
        select option {
            background-color: #111111;
            color: #ffffff;
        }
        
        input[type="date"] { color-scheme: dark; }

        .strength-bar { height: 3px; border-radius: 2px; transition: all 0.3s ease; width: 0; }
        .strength-weak { background-color: #ef4444; width: 33.33%; }
        .strength-medium { background-color: #f59e0b; width: 66.66%; }
        .strength-strong { background-color: #10b981; width: 100%; }

        .section-label {
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: <?= $secondary_color ?>;
            opacity: 0.7;
            margin-bottom: 24px;
            display: block;
        }
    </style>
</head>

<body class="font-sans antialiased min-h-screen flex flex-col hero-glow">

<header class="relative z-20 w-full px-8 py-6 flex justify-between items-center bg-transparent">
    <a href="../portal.php?gym=<?= $gym_slug ?>" class="flex items-center gap-3 hover:opacity-80 transition-opacity">
            <?php 
                // Primary: Custom Landing Page Logo | Secondary: Gym's Profile Picture
                $logo_src = !empty($page['logo_path']) ? $page['logo_path'] : ($page['gym_logo'] ?? '');
                
                if (!empty($logo_src)) {
                    // Check if it's a relative path and normalize it for the 'member/' directory
                    if (!filter_var($logo_src, FILTER_VALIDATE_URL) && $logo_src[0] !== '/' && strpos($logo_src, 'data:image') === false) {
                        // If it doesn't start with '../', prepend it to go up to root
                        if (substr($logo_src, 0, 3) !== '../') {
                            $logo_src = '../' . $logo_src;
                        }
                    }
                }
            ?>
            <div class="size-12 rounded-2xl bg-white/5 border border-white/10 flex items-center justify-center overflow-hidden shadow-2xl">
                <?php if (!empty($logo_src)): ?>
                    <img src="<?= htmlspecialchars($logo_src) ?>" class="size-full object-cover" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                    <span class="material-symbols-outlined text-primary text-2xl font-bold" style="display:none;">bolt</span>
                <?php else: ?>
                    <span class="material-symbols-outlined text-primary text-2xl font-bold">bolt</span>
                <?php endif; ?>
            </div>
            <div class="flex flex-col">
                <h2 class="text-xl font-display font-black text-white uppercase italic tracking-tighter leading-none"><?= htmlspecialchars($gym_name) ?></h2>
                <span class="text-[8px] font-black text-primary/60 uppercase tracking-[0.2em] mt-1 ml-1">Elite Partner Portal</span>
            </div>
    </a>
    <div class="flex items-center gap-6">
        <a href="../portal.php?gym=<?= $gym_slug ?>" class="text-[10px] font-display font-bold uppercase tracking-widest text-gray-500 hover:text-white transition-all flex items-center gap-2">
            <span class="material-symbols-outlined text-sm">arrow_back</span>
            Back to Portal
        </a>
    </div>
</header>

<main class="flex-1 flex flex-col items-center py-12 px-4 relative z-10 w-full overflow-x-hidden">
    <div class="w-full max-w-2xl">
        <div class="text-center mb-10">
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/5 border border-white/10 text-primary text-[9px] font-black uppercase tracking-[0.2em] mb-4">
                Facility Membership
            </div>
            <h1 class="text-4xl font-display font-black text-white uppercase italic tracking-tighter mb-2">JOIN <span class="text-primary"><?= htmlspecialchars($gym_name) ?></span></h1>
            <p class="text-xs text-gray-500 font-medium uppercase tracking-widest leading-relaxed">Step <span id="step-count">1</span> of 4: <span id="step-label" class="text-white">Personal Information</span></p>
            
            <div class="w-full bg-white/5 h-1.5 mt-8 rounded-full overflow-hidden">
                <div id="progress-bar" class="bg-primary h-full transition-all duration-700 ease-out shadow-[0_0_15px_rgba(<?= $primary_rgb ?>,0.5)]" style="width: 25%"></div>
            </div>
        </div>

        <?php if($success): ?>
            <div class="mb-8 p-8 rounded-[32px] bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-sm font-bold flex flex-col items-center gap-6 text-center dashboard-window">
                <div class="size-20 rounded-full bg-emerald-500/20 flex items-center justify-center">
                    <span class="material-symbols-outlined text-5xl">check_circle</span>
                </div>
                <div>
                    <h3 class="text-2xl font-display font-black uppercase italic mb-2">Welcome Aboard!</h3>
                    <p class="text-xs text-gray-400 font-medium leading-relaxed"><?= $success ?></p>
                    <a href="../portal.php?gym=<?= $gym_slug ?>" class="inline-flex mt-8 px-8 py-3 bg-primary text-white text-[10px] font-black uppercase tracking-widest rounded-xl hover:scale-105 transition-all">Return to Portal</a>
                </div>
            </div>
        <?php else: ?>

            <?php if($error): ?>
                <div class="mb-8 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-xs font-bold flex items-center gap-3 dashboard-window">
                    <span class="material-symbols-outlined text-lg">error</span> <?= $error ?>
                </div>
            <?php endif; ?>

            <form id="reg-form" method="POST" class="space-y-6 pb-20">
                
                <!-- SECTION 1: PERSONAL INFORMATION -->
                <div class="step-container" data-step="1">
                    <div class="dashboard-window rounded-2xl p-8 md:p-10 relative overflow-hidden">
                        <div class="absolute -top-24 -right-24 w-48 h-48 bg-primary/10 blur-[60px] rounded-full pointer-events-none"></div>
                        
                        <div class="relative z-10">
                            <div class="flex items-center gap-4 mb-8">
                                <div class="size-8 rounded-lg bg-primary/20 text-primary flex items-center justify-center font-bold text-sm border border-primary/30">1</div>
                                <h3 class="text-lg font-display font-black text-white uppercase italic tracking-tight">Personal Details</h3>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-5">
                                <div class="space-y-1.5">
                                    <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">First Name <small class="text-primary">*</small></label>
                                    <input type="text" name="first_name" required value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>" class="input-field" placeholder="e.g. Juan">
                                </div>
                                <div class="space-y-1.5">
                                    <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Middle Name (Optional)</label>
                                    <input type="text" name="middle_name" value="<?= htmlspecialchars($_POST['middle_name'] ?? '') ?>" class="input-field" placeholder="e.g. Santos">
                                </div>
                                <div class="space-y-1.5">
                                    <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Last Name <small class="text-primary">*</small></label>
                                    <input type="text" name="last_name" required value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>" class="input-field" placeholder="e.g. Dela Cruz">
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-5">
                                <div class="space-y-1.5">
                                    <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Date of Birth <small class="text-primary">*</small></label>
                                    <input type="date" name="birth_date" required value="<?= htmlspecialchars($_POST['birth_date'] ?? '') ?>" class="input-field">
                                </div>
                                <div class="space-y-1.5">
                                    <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Sex <small class="text-primary">*</small></label>
                                    <div class="relative">
                                        <select name="sex" required class="input-field appearance-none cursor-pointer">
                                            <option value="" disabled <?= !isset($_POST['sex']) ? 'selected' : '' ?>>Select Sex</option>
                                            <option value="Male" <?= ($_POST['sex'] ?? '') === 'Male' ? 'selected' : '' ?>>Male</option>
                                            <option value="Female" <?= ($_POST['sex'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
                                        </select>
                                        <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none text-sm">expand_more</span>
                                    </div>
                                </div>
                            </div>

                            <div class="space-y-1.5 mb-5 md:max-w-[50%]">
                                <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Username (For Login) <small class="text-primary">*</small></label>
                                <input type="text" name="username" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" class="input-field" placeholder="e.g. juan_delacruz">
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                <div class="space-y-1.5">
                                    <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Security Password <small class="text-primary">*</small></label>
                                    <div class="relative group">
                                        <input type="password" name="password" id="password" required onkeyup="checkPasswordStrength(this.value)" class="input-field" placeholder="••••••••">
                                        <button type="button" onclick="togglePassword('password', this)" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-primary transition-all"><span class="material-symbols-outlined text-[20px]">visibility</span></button>
                                    </div>
                                    <div class="mt-2 flex gap-1 h-1 px-1">
                                        <div id="strength-bar-1" class="flex-1 rounded-full bg-white/5 transition-colors"></div>
                                        <div id="strength-bar-2" class="flex-1 rounded-full bg-white/5 transition-colors"></div>
                                        <div id="strength-bar-3" class="flex-1 rounded-full bg-white/5 transition-colors"></div>
                                    </div>
                                    <p id="strength-text" class="text-[9px] font-bold uppercase tracking-widest text-gray-500 mt-2 ml-1">Strength: <span id="strength-label">None</span></p>
                                </div>

                                <div class="space-y-1.5">
                                    <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Confirm Password <small class="text-primary">*</small></label>
                                    <div class="relative group">
                                        <input type="password" name="confirm_password" id="confirm_password" required class="input-field" placeholder="••••••••">
                                        <button type="button" onclick="togglePassword('confirm_password', this)" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-primary transition-all"><span class="material-symbols-outlined text-[20px]">visibility</span></button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SECTION 2: CONTACT INFORMATION -->
                <div class="step-container step-hidden" data-step="2">
                    <div class="dashboard-window rounded-2xl p-8 md:p-10 relative overflow-hidden">
                        <div class="absolute -top-24 -right-24 w-48 h-48 bg-primary/10 blur-[60px] rounded-full pointer-events-none"></div>
                        
                        <div class="relative z-10">
                            <div class="flex items-center gap-4 mb-8">
                                <div class="size-8 rounded-lg bg-primary/20 text-primary flex items-center justify-center font-bold text-sm border border-primary/30">2</div>
                                <h3 class="text-lg font-display font-black text-white uppercase italic tracking-tight">Contact Information</h3>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-5">
                                <div class="space-y-1.5">
                                    <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Email address <small class="text-primary">*</small></label>
                                    <input type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" class="input-field" placeholder="e.g. name@example.com">
                                </div>
                                <div class="space-y-1.5">
                                    <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Contact Number <small class="text-primary">*</small></label>
                                    <input type="tel" name="phone" required oninput="formatPhoneNumber(this)" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" class="input-field" placeholder="09123456789">
                                </div>
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Full Home Address <small class="text-primary">*</small></label>
                                <textarea name="address" required class="input-field min-h-[80px] py-3" placeholder="Street, Barangay, City, Province"><?= htmlspecialchars($_POST['address'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SECTION 3: HEALTH & PROFILE -->
                <div class="step-container step-hidden" data-step="3">
                    <div class="dashboard-window rounded-2xl p-8 md:p-10 relative overflow-hidden">
                        <div class="absolute -top-24 -right-24 w-48 h-48 bg-primary/10 blur-[60px] rounded-full pointer-events-none"></div>
                        
                        <div class="relative z-10">
                            <div class="flex items-center gap-4 mb-8">
                                <div class="size-8 rounded-lg bg-primary/20 text-primary flex items-center justify-center font-bold text-sm border border-primary/30">3</div>
                                <h3 class="text-lg font-display font-black text-white uppercase italic tracking-tight">Health Records</h3>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-5">
                                <div class="space-y-1.5 md:col-span-2">
                                    <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Current Occupation</label>
                                    <input type="text" name="occupation" value="<?= htmlspecialchars($_POST['occupation'] ?? '') ?>" class="input-field" placeholder="e.g. Software Engineer">
                                </div>
                                <div class="space-y-1.5">
                                    <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Emergency Representative <small class="text-primary">*</small></label>
                                    <input type="text" name="emergency_name" required value="<?= htmlspecialchars($_POST['emergency_name'] ?? '') ?>" class="input-field" placeholder="Full name">
                                </div>
                                <div class="space-y-1.5">
                                    <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Emergency Hotline <small class="text-primary">*</small></label>
                                    <input type="tel" name="emergency_phone" required oninput="formatPhoneNumber(this)" value="<?= htmlspecialchars($_POST['emergency_phone'] ?? '') ?>" class="input-field" placeholder="09123456789">
                                </div>
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Medical history</label>
                                <textarea name="medical_history" class="input-field min-h-[100px] py-3" placeholder="List any existing conditions, allergies, or medications..."><?= htmlspecialchars($_POST['medical_history'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SECTION 4: SECURITY VERIFICATION -->
                <div class="step-container step-hidden" data-step="4">
                    <div class="dashboard-window rounded-2xl p-8 md:p-10 relative overflow-hidden">
                        <div class="absolute -top-24 -right-24 w-48 h-48 bg-primary/10 blur-[60px] rounded-full pointer-events-none"></div>
                        
                        <div class="relative z-10">
                            <div class="flex items-center gap-4 mb-8">
                                <div class="size-8 rounded-lg bg-primary/20 text-primary flex items-center justify-center font-bold text-sm border border-primary/30">4</div>
                                <h3 class="text-lg font-display font-black text-white uppercase italic tracking-tight">Email Verification</h3>
                            </div>
                            
                            <p class="text-xs text-gray-400 font-medium mb-8 leading-relaxed">
                                We've sent a 6-digit security code to your email. Please enter it below to finalize your membership.
                            </p>

                            <div class="space-y-2">
                                <label class="text-[10px] font-bold uppercase tracking-widest text-gray-500 ml-1">6-Digit Code</label>
                                <input type="text" name="otp_code" maxlength="6" class="input-field text-center text-4xl font-black tracking-[0.5em] h-24 bg-white/5 border-white/10" placeholder="000000">
                            </div>

                            <p class="mt-8 text-[10px] text-gray-500 font-bold uppercase text-center">Didn't receive it? Check your spam folder or wait a minute.</p>
                        </div>
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row items-stretch gap-4 pt-10 w-full px-0">
                    <button type="button" id="prev-btn" class="hidden h-14 w-full sm:flex-1 rounded-xl bg-white/5 border border-white/10 text-white text-[11px] font-bold uppercase tracking-widest hover:bg-white/10 transition-all flex items-center justify-center">
                        Previous
                    </button>
                    <button type="button" id="next-btn" class="h-14 w-full sm:flex-1 rounded-xl bg-primary text-white text-[11px] font-bold uppercase tracking-widest shadow-lg shadow-primary/20 hover:scale-[1.02] active:scale-[0.98] transition-all flex items-center justify-center gap-3">
                        Next Step <span class="material-symbols-outlined text-lg">arrow_forward</span>
                    </button>
                    <button type="submit" id="submit-btn" class="hidden h-14 w-full sm:flex-1 rounded-xl bg-primary text-white text-[11px] font-bold uppercase tracking-widest shadow-lg shadow-primary/20 hover:scale-[1.02] active:scale-[0.98] transition-all flex items-center justify-center gap-3">
                        Join Membership
                    </button>
                    <button type="submit" id="verify-btn" class="hidden h-14 w-full sm:flex-1 rounded-xl bg-emerald-600 text-white text-[11px] font-bold uppercase tracking-widest shadow-lg shadow-emerald-900/20 hover:scale-[1.02] active:scale-[0.98] transition-all flex items-center justify-center gap-3">
                        Verify & Complete <span class="material-symbols-outlined text-lg">verified_user</span>
                    </button>
                </div>

            </form>
        <?php endif; ?>
    </div>
</main>

<footer class="relative z-20 w-full py-8 text-center mt-auto">
    <p class="text-[9px] font-display font-bold text-gray-700 uppercase tracking-[0.4em]">
        © 2026 HORIZON SYSTEM. SECURE ENVIRONMENT.
    </p>
</footer>

<script>
    const steps = document.querySelectorAll('.step-container');
    const nextBtn = document.getElementById('next-btn');
    const prevBtn = document.getElementById('prev-btn');
    const submitBtn = document.getElementById('submit-btn');
    const verifyBtn = document.getElementById('verify-btn');
    let currentStep = <?= $otp_sent ? '4' : '1' ?>;

    function updateUI() {
        steps.forEach(step => {
            if(parseInt(step.dataset.step) === currentStep) {
                step.classList.remove('step-hidden');
            } else {
                step.classList.add('step-hidden');
            }
        });

        // Update Progress Bar & Step Labels
        const progressBar = document.getElementById('progress-bar');
        const stepCount = document.getElementById('step-count');
        const stepLabel = document.getElementById('step-label');
        
        const labels = ["Personal Information", "Contact Details", "Health Records", "Security OTP"];
        const progress = (currentStep / steps.length) * 100;
        
        progressBar.style.width = `${progress}%`;
        stepCount.innerText = currentStep;
        stepLabel.innerText = labels[currentStep - 1];

        // Hide navigation if in OTP step (PHASE 1 to PHASE 2 transition handled by PHP reload)
        if (currentStep === 4) {
            prevBtn.classList.add('hidden');
            nextBtn.classList.add('hidden');
            submitBtn.classList.add('hidden');
            verifyBtn.classList.remove('hidden');
        } else {
            prevBtn.classList.toggle('hidden', currentStep === 1);
            nextBtn.classList.toggle('hidden', currentStep === 3);
            submitBtn.classList.toggle('hidden', currentStep !== 3);
            verifyBtn.classList.add('hidden');
        }
        
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    nextBtn.addEventListener('click', () => {
        const activeStep = document.querySelector(`.step-container[data-step="${currentStep}"]`);
        const inputs = activeStep.querySelectorAll('input[required], select[required]');
        let valid = true;
        
        inputs.forEach(input => {
            if(!input.value) {
                input.reportValidity();
                valid = false;
            }
        });

        if(currentStep === 1 && valid) {
            const pass = document.getElementById('password').value;
            const confirm = document.getElementById('confirm_password').value;
            if(pass !== confirm) {
                alert('Passwords do not match!');
                valid = false;
            }
        }

        if(valid && currentStep < steps.length) {
            currentStep++;
            updateUI();
        }
    });

    prevBtn.addEventListener('click', () => {
        if(currentStep > 1) {
            currentStep--;
            updateUI();
        }
    });

    function togglePassword(id, btn) {
        const input = document.getElementById(id);
        const icon = btn.querySelector('.material-symbols-outlined');
        if (input.type === 'password') {
            input.type = 'text';
            icon.textContent = 'visibility_off';
        } else {
            input.type = 'password';
            icon.textContent = 'visibility';
        }
    }

    function checkPasswordStrength(password) {
        const bars = [
            document.getElementById('strength-bar-1'),
            document.getElementById('strength-bar-2'),
            document.getElementById('strength-bar-3')
        ];
        const label = document.getElementById('strength-label');
        
        let strength = 0;
        if (password.length >= 8) strength++;
        if (/[A-Z]/.test(password)) strength++;
        if (/[0-9]/.test(password)) strength++;
        if (/[^A-Za-z0-9]/.test(password)) strength++;
        
        bars.forEach(bar => bar.className = 'flex-1 rounded-full bg-white/5 transition-colors');
        
        if (password.length === 0) {
            label.textContent = 'None';
            label.className = 'text-gray-500';
        } else {
            const colors = ['bg-red-500', 'bg-amber-500', 'bg-emerald-500'];
            const labels = ['Weak', 'Good', 'Strong'];
            const activeStrength = Math.min(strength, 3);
            
            for (let i = 0; i < activeStrength; i++) {
                bars[i].className = 'flex-1 rounded-full ' + colors[activeStrength - 1];
            }
            label.textContent = labels[activeStrength - 1];
            label.className = colors[activeStrength - 1].replace('bg-', 'text-');
        }
    }

    function formatPhoneNumber(input) {
        let value = input.value.replace(/\D/g, ''); 
        if (value.length > 11) value = value.slice(0, 11);
        let formatted = '';
        if (value.length > 0) {
            formatted = value.substring(0, 4);
            if (value.length > 4) formatted += '-' + value.substring(4, 7);
            if (value.length > 7) formatted += '-' + value.substring(7, 11);
        }
        input.value = formatted;
    }
</script>

</body>
</html>