<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
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
$stmtPage = $pdo->prepare("SELECT tp.*, g.gym_name, g.gym_id, g.email as gym_email, g.contact_number as gym_contact 
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $registration_data = array_merge($_POST, [
            'gym_id' => $gym_id,
            'registration_source' => 'Self'
        ]);

        $result = processMemberRegistration($pdo, $registration_data);
        $success = "Registration successful! You can now log in using your credentials on our mobile app.";
        $_POST = [];
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
                        "background-dark": "<?= $bg_color ?>",
                        "surface-dark": "rgba(255, 255, 255, 0.02)",
                    },
                    fontFamily: { 
                        "display": ["<?= $font_family ?>", "Lexend", "sans-serif"],
                        "sans": ["Plus Jakarta Sans", "Inter", "sans-serif"]
                    },
                    borderRadius: { 'custom': '24px' }
                },
            },
        }
    </script>
    <style>
        *::-webkit-scrollbar { display: none; }
        * { -ms-overflow-style: none; scrollbar-width: none; }
        
        body { 
            background-color: <?= $bg_color ?> !important; 
            color: #f3f4f6;
            margin: 0;
            padding: 0;
            min-height: 100vh;
            font-family: ["<?= $font_family ?>", "Lexend", "sans-serif"];
        }
        
        .hero-glow {
            background-image: radial-gradient(circle at 50% -10%, rgba(<?= $primary_rgb ?>, 0.15), transparent 70%);
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.02);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.05);
            box-shadow: 0 40px 100px -20px rgba(0, 0, 0, 0.5);
        }

        .input-field {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            color: white;
            padding: 14px 20px;
            width: 100%;
            outline: none;
            transition: all 0.3s;
            font-size: 14px;
        }

        .input-field:focus {
            border-color: <?= $primary_color ?>;
            background: rgba(255, 255, 255, 0.06);
            box-shadow: 0 0 0 1px rgba(<?= $primary_rgb ?>, 0.3);
        }

        .step-hidden { display: none; }
        
        select option {
            background-color: #0c0b10;
            color: white;
        }
        
        input[type="date"] { color-scheme: dark; }

        .strength-bar { height: 4px; border-radius: 2px; transition: all 0.3s ease; width: 0; }
        .strength-weak { background-color: #ef4444; width: 33.33%; }
        .strength-medium { background-color: #f59e0b; width: 66.66%; }
        .strength-strong { background-color: #10b981; width: 100%; }
    </style>
</head>

<body class="font-sans antialiased min-h-screen flex flex-col hero-glow">

<header class="relative z-20 w-full px-8 py-6 flex justify-between items-center bg-transparent">
    <a href="../portal.php?gym=<?= $gym_slug ?>" class="flex items-center gap-3 hover:opacity-80 transition-opacity">
        <div class="size-9 rounded-xl bg-primary flex items-center justify-center shadow-lg shadow-primary/20 overflow-hidden">
            <?php if (!empty($page['logo_path'])): ?>
                <img src="../<?= htmlspecialchars($page['logo_path']) ?>" class="size-full object-cover">
            <?php else: ?>
                <span class="material-symbols-outlined text-white text-xl font-bold">bolt</span>
            <?php endif; ?>
        </div>
        <h2 class="text-lg font-display font-bold text-white uppercase italic tracking-tighter"><?= htmlspecialchars($gym_name) ?></h2>
    </a>
    <div class="flex items-center gap-6">
        <a href="../portal.php?gym=<?= $gym_slug ?>" class="text-[10px] font-display font-bold uppercase tracking-widest text-gray-500 hover:text-white transition-all flex items-center gap-2">
            <span class="material-symbols-outlined text-sm">arrow_back</span>
            Back to Portal
        </a>
    </div>
</header>

<main class="flex-1 flex flex-col items-center py-8 px-4 relative z-10 w-full overflow-x-hidden">
    <div class="w-full max-w-2xl">
        <div class="text-center mb-8">
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/5 border border-white/10 text-primary text-[9px] font-black uppercase tracking-[0.2em] mb-4">
                Facility Membership
            </div>
            <h1 class="text-4xl font-display font-black text-white uppercase italic tracking-tighter mb-2">Join <span class="text-primary"><?= htmlspecialchars($gym_name) ?></span></h1>
            <p class="text-[10px] text-gray-500 font-bold uppercase tracking-widest">Step <span id="step-number">1</span> of 2: Account Set-up</p>
            
            <div class="w-full bg-white/5 h-1.5 mt-8 rounded-full overflow-hidden">
                <div id="progress-bar" class="bg-primary h-full transition-all duration-500" style="width: 50%"></div>
            </div>
        </div>

        <?php if($success): ?>
            <div class="mb-8 p-8 rounded-[32px] bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-sm font-bold flex flex-col items-center gap-6 text-center glass-card">
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
                <div class="mb-8 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-xs font-bold flex items-center gap-3 glass-card">
                    <span class="material-symbols-outlined text-lg">error</span> <?= $error ?>
                </div>
            <?php endif; ?>

            <form id="reg-form" method="POST" class="space-y-6 pb-20">
                
                <!-- STEP 1: Account Credentials -->
                <div class="step-container" data-step="1">
                    <div class="glass-card rounded-[32px] p-8 md:p-10 relative overflow-hidden">
                        <div class="absolute -top-24 -right-24 w-48 h-48 bg-primary/10 blur-[60px] rounded-full pointer-events-none"></div>
                        
                        <div class="relative z-10">
                            <div class="flex items-center gap-4 mb-8">
                                <span class="size-8 rounded-lg bg-primary/20 text-primary flex items-center justify-center font-bold text-sm border border-primary/30">1</span>
                                <h3 class="text-lg font-display font-black text-white uppercase italic tracking-tight">Security Credentials</h3>
                            </div>

                            <div class="space-y-6">
                                <div class="space-y-2">
                                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">Username</label>
                                    <input type="text" name="username" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" class="input-field" placeholder="Create a unique username">
                                </div>

                                <div class="space-y-2">
                                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">Email Address</label>
                                    <input type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" class="input-field" placeholder="your@email.com">
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div class="space-y-2">
                                        <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">Password</label>
                                        <div class="relative">
                                            <input type="password" name="password" id="password" required onkeyup="checkPasswordStrength(this.value)" class="input-field" placeholder="••••••••">
                                            <button type="button" onclick="togglePassword('password', this)" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-500 hover:text-white transition-all"><span class="material-symbols-outlined text-sm">visibility</span></button>
                                        </div>
                                        <div class="w-full bg-white/5 h-1 rounded-full mt-2 overflow-hidden"><div id="strength-indicator" class="strength-bar"></div></div>
                                        <p id="strength-text" class="text-[9px] font-black uppercase tracking-widest ml-1 mt-1"></p>
                                    </div>
                                    <div class="space-y-2">
                                        <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">Confirm Password</label>
                                        <div class="relative">
                                            <input type="password" name="confirm_password" id="confirm_password" required class="input-field" placeholder="••••••••">
                                            <button type="button" onclick="togglePassword('confirm_password', this)" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-500 hover:text-white transition-all"><span class="material-symbols-outlined text-sm">visibility</span></button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- STEP 2: Personal Information -->
                <div class="step-container step-hidden" data-step="2">
                    <div class="glass-card rounded-[32px] p-8 md:p-10 relative overflow-hidden">
                        <div class="absolute -top-24 -right-24 w-48 h-48 bg-primary/10 blur-[60px] rounded-full pointer-events-none"></div>
                        
                        <div class="relative z-10">
                            <div class="flex items-center gap-4 mb-8">
                                <span class="size-8 rounded-lg bg-primary/20 text-primary flex items-center justify-center font-bold text-sm border border-primary/30">2</span>
                                <h3 class="text-lg font-display font-black text-white uppercase italic tracking-tight">Personal Details</h3>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                                <div class="space-y-2">
                                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">First Name</label>
                                    <input type="text" name="first_name" required value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>" class="input-field" placeholder="Juan">
                                </div>
                                <div class="space-y-2">
                                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">Last Name</label>
                                    <input type="text" name="last_name" required value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>" class="input-field" placeholder="Dela Cruz">
                                </div>
                                <div class="space-y-2">
                                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">Sex</label>
                                    <select name="sex" required class="input-field appearance-none cursor-pointer">
                                        <option value="" disabled <?= !isset($_POST['sex']) ? 'selected' : '' ?>>Select Sex</option>
                                        <option value="Male" <?= ($_POST['sex'] ?? '') === 'Male' ? 'selected' : '' ?>>Male</option>
                                        <option value="Female" <?= ($_POST['sex'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
                                    </select>
                                </div>
                                <div class="space-y-2">
                                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">Birth Date</label>
                                    <input type="date" name="birth_date" required value="<?= htmlspecialchars($_POST['birth_date'] ?? '') ?>" class="input-field">
                                </div>
                                <div class="space-y-2">
                                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">Contact Number</label>
                                    <input type="tel" name="phone" required oninput="formatPhoneNumber(this)" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" class="input-field" placeholder="09XX-XXX-XXXX">
                                </div>
                                <div class="space-y-2">
                                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">Emergency Contact</label>
                                    <input type="tel" name="emergency_phone" required oninput="formatPhoneNumber(this)" value="<?= htmlspecialchars($_POST['emergency_phone'] ?? '') ?>" class="input-field" placeholder="Emergency Number">
                                </div>
                                <div class="space-y-2 md:col-span-2">
                                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">Home Address</label>
                                    <input type="text" name="address" required value="<?= htmlspecialchars($_POST['address'] ?? '') ?>" class="input-field" placeholder="Street, Barangay, City">
                                </div>
                                <div class="space-y-2 md:col-span-2">
                                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">Medical Conditions (Optional)</label>
                                    <textarea name="medical_history" class="input-field min-h-[100px] py-4" placeholder="List any medical conditions we should be aware of..."><?= htmlspecialchars($_POST['medical_history'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex gap-4 pt-4">
                    <button type="button" id="prev-btn" class="hidden flex-1 h-14 rounded-2xl bg-white/5 border border-white/10 text-white text-[11px] font-black uppercase tracking-widest hover:bg-white/10 transition-all">Previous</button>
                    <button type="button" id="next-btn" class="flex-1 h-14 rounded-2xl bg-primary text-white text-[11px] font-black uppercase tracking-widest shadow-lg shadow-primary/20 hover:shadow-primary/40 transition-all flex items-center justify-center gap-2">Next Step <span class="material-symbols-outlined text-lg">arrow_forward</span></button>
                    <button type="submit" id="submit-btn" class="hidden flex-1 h-14 rounded-2xl bg-primary text-white text-[11px] font-black uppercase tracking-widest shadow-lg shadow-primary/20 hover:shadow-primary/40 transition-all flex items-center justify-center gap-2">Register <span class="material-symbols-outlined text-lg">rocket_launch</span></button>
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
    const progressBar = document.getElementById('progress-bar');
    const stepNumberLabel = document.getElementById('step-number');
    let currentStep = 1;

    function updateUI() {
        steps.forEach(step => {
            if(parseInt(step.dataset.step) === currentStep) {
                step.classList.remove('step-hidden');
            } else {
                step.classList.add('step-hidden');
            }
        });

        const progress = (currentStep / steps.length) * 100;
        progressBar.style.width = `${progress}%`;
        stepNumberLabel.innerText = currentStep;

        prevBtn.classList.toggle('hidden', currentStep === 1);
        nextBtn.classList.toggle('hidden', currentStep === steps.length);
        submitBtn.classList.toggle('hidden', currentStep !== steps.length);
        
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
        let strength = 0;
        const indicator = document.getElementById('strength-indicator');
        const text = document.getElementById('strength-text');
        if (password.length >= 8) strength++;
        if (/[A-Z]/.test(password)) strength++;
        if (/[0-9]/.test(password)) strength++;
        if (/[^A-Za-z0-9]/.test(password)) strength++;
        
        indicator.className = 'strength-bar';
        if (password.length === 0) text.textContent = '';
        else if (strength <= 2) { indicator.classList.add('strength-weak'); text.textContent = 'Weak'; text.className = 'text-[9px] font-black uppercase text-red-500 mt-1'; }
        else if (strength === 3) { indicator.classList.add('strength-medium'); text.textContent = 'Good'; text.className = 'text-[9px] font-black uppercase text-amber-500 mt-1'; }
        else { indicator.classList.add('strength-strong'); text.textContent = 'Strong'; text.className = 'text-[9px] font-black uppercase text-emerald-500 mt-1'; }
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