<?php
session_start();
require_once '../db.php';

$gym_slug = $_GET['gym'] ?? '';

if (empty($gym_slug)) {
    die("Direct access not allowed. Please register through the facility portal.");
}

// Fetch Gym/Tenant Info
$stmtSlug = $pdo->prepare("SELECT user_id FROM system_settings WHERE setting_key = 'page_slug' AND setting_value = ?");
$stmtSlug->execute([$gym_slug]);
$user_id = $stmtSlug->fetchColumn();

if (!$user_id) {
    die("Gym page not found or is currently inactive.");
}

$stmtSettings = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = ?");
$stmtSettings->execute([$user_id]);
$configs = $stmtSettings->fetchAll(PDO::FETCH_KEY_PAIR);

$stmtGym = $pdo->prepare("SELECT *, profile_picture as gym_logo, email as gym_email, contact_number as gym_contact FROM gyms WHERE owner_user_id = ? LIMIT 1");
$stmtGym->execute([$user_id]);
$gym_info = $stmtGym->fetch();

if (!$gym_info) {
    die("Gym details not found.");
}

$logo_src = $gym_info['gym_logo'] ?? '';
if (!empty($logo_src)) {
    if (!filter_var($logo_src, FILTER_VALIDATE_URL) && $logo_src[0] !== '/' && strpos($logo_src, 'data:image') === false) {
        if (substr($logo_src, 0, 3) !== '../') {
            $logo_src = '../' . $logo_src;
        }
    }
}

$page = [
    'gym_id' => $gym_info['gym_id'],
    'gym_name' => $gym_info['gym_name'],
    'page_slug' => $gym_slug,
    'theme_color' => $configs['theme_color'] ?? '#8c2bee',
    'bg_color' => $configs['bg_color'] ?? '#0a090d',
    'font_family' => $configs['font_family'] ?? 'Lexend',
    'gym_logo' => $logo_src
];

$primary_color = $page['theme_color'];
$bg_color = $page['bg_color'];

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

// Handle AJAX Field Checks
if (isset($_GET['check_field']) && isset($_GET['value'])) {
    header('Content-Type: application/json');
    $field = $_GET['check_field'];
    $value = trim($_GET['value']);
    $exists = false;

    if ($field === 'username' || $field === 'email') {
        $stmt = $pdo->prepare("
            SELECT u.user_id, ca.application_status 
            FROM users u 
            LEFT JOIN coach_applications ca ON u.user_id = ca.user_id AND ca.gym_id = ?
            WHERE " . ($field === 'username' ? 'u.username' : 'u.email') . " = ?
            ORDER BY ca.submitted_at DESC LIMIT 1
        ");
        $stmt->execute([$page['gym_id'], $value]);
        $res = $stmt->fetch();
        
        if ($res) {
            // Block only if they have a non-rejected application at THIS facility
            if (in_array($res['application_status'], ['Pending', 'Approved'])) {
                $exists = true;
            }
        }
    }
    
    echo json_encode(['exists' => $exists]);
    exit;
}
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Coach Application | <?= htmlspecialchars($page['gym_name']) ?></title>

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
                        "primary": "<?= $primary_color ?>",
                        "primary-dark": "<?= $primary_color ?>dd",
                        "background-dark": "<?= $bg_color ?>",
                        "surface-dark": "rgba(255, 255, 255, 0.02)",
                    },
                    fontFamily: { 
                        "display": ["<?= $page['font_family'] ?>", "sans-serif"],
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

        .glass-card {
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
            transition: all 0.3s ease;
            font-size: 13px;
        }

        .input-field:focus {
            border-color: <?= $primary_color ?> !important;
            background: rgba(255, 255, 255, 0.05) !important;
            box-shadow: 0 0 0 1px rgba(<?= $primary_rgb ?>, 0.3) !important;
        }

        .step-hidden { display: none; }
        
        select option {
            background-color: #111111;
            color: #ffffff;
        }
        
        input[type="date"] { color-scheme: dark; }

        .btn-modern {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .btn-modern:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px -5px rgba(<?= $primary_rgb ?>, 0.4);
        }
    </style>
</head>

<body class="font-sans antialiased min-h-screen flex flex-col hero-glow">

<header class="relative z-20 w-full px-8 py-6 flex justify-between items-center bg-transparent">
    <a href="../portal.php?gym=<?= $gym_slug ?>" class="flex items-center gap-3 hover:opacity-80 transition-opacity">
        <div class="size-12 rounded-2xl bg-white/5 border border-white/10 flex items-center justify-center overflow-hidden shadow-2xl">
            <?php if (!empty($page['gym_logo'])): ?>
                <img src="<?= htmlspecialchars($page['gym_logo']) ?>" class="size-full object-cover">
            <?php else: ?>
                <span class="material-symbols-outlined text-primary text-2xl font-bold">bolt</span>
            <?php endif; ?>
        </div>
        <div class="flex flex-col">
            <h2 class="text-xl font-display font-black text-white uppercase italic tracking-tighter leading-none"><?= htmlspecialchars($page['gym_name']) ?></h2>
            <span class="text-[8px] font-black text-primary/60 uppercase tracking-[0.2em] mt-1 ml-1">Coach Recruitment</span>
        </div>
    </a>
    <div class="flex items-center gap-6">
        <a href="../portal.php?gym=<?= $gym_slug ?>" class="text-[10px] font-display font-bold uppercase tracking-widest text-gray-500 hover:text-white transition-all flex items-center gap-2">
            <span class="material-symbols-outlined text-sm">arrow_back</span>
            Back to Portal
        </a>
    </div>
</header>

<main class="flex-1 flex flex-col items-center py-12 px-4 relative z-10 w-full">
    <div class="w-full max-w-2xl">
        <div class="text-center mb-10">
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/5 border border-white/10 text-primary text-[9px] font-black uppercase tracking-[0.2em] mb-4">
                Professional Application
            </div>
            <h1 class="text-4xl font-display font-black text-white uppercase italic tracking-tighter mb-2">APPLY AS <span class="text-primary text-5xl">COACH</span></h1>
            <p class="text-xs text-gray-400 font-medium uppercase tracking-widest leading-relaxed">Step <span id="step-count">1</span> of 3: <span id="step-label" class="text-white font-bold">Account Registration</span></p>
            
            <div class="w-full bg-white/5 h-1.5 mt-8 rounded-full overflow-hidden">
                <div id="progress-bar" class="bg-primary h-full transition-all duration-700 ease-out" style="width: 33.33%"></div>
            </div>
        </div>

        <div id="dynamic-alert-container" class="w-full sticky top-4 z-[9999] mb-4">
            <?php if (isset($_SESSION['coach_app_error'])): ?>
                <div class="p-4 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-400 text-xs font-bold flex items-center gap-3 mb-4 animate-pulse">
                    <span class="material-symbols-outlined text-lg">error</span> 
                    <?= htmlspecialchars($_SESSION['coach_app_error']) ?>
                    <?php unset($_SESSION['coach_app_error']); ?>
                </div>
            <?php endif; ?>
        </div>

        <form id="coach-form" action="../action/submit_coach_application.php?gym=<?= $gym_slug ?>" method="POST" enctype="multipart/form-data" class="space-y-6 pb-10">
            
            <!-- STEP 1: ACCOUNT & PERSONAL INFO -->
            <div class="step-container" data-step="1">
                <div class="glass-card rounded-2xl p-8 md:p-10 relative overflow-hidden">
                    <div class="absolute -top-24 -right-24 w-48 h-48 bg-primary/10 blur-[60px] rounded-full pointer-events-none"></div>
                    
                    <div class="relative z-10">
                        <div class="flex items-center gap-4 mb-8">
                            <div class="size-8 rounded-lg bg-primary/20 text-primary flex items-center justify-center font-bold text-sm border border-primary/30">1</div>
                            <h3 class="text-lg font-display font-black text-white uppercase italic tracking-tight">Personal & Account Details</h3>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-5">
                            <div class="space-y-1.5">
                                <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">First Name <span class="text-primary">*</span></label>
                                <input type="text" name="first_name" required class="input-field" placeholder="e.g. Juan">
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Middle Name (Optional)</label>
                                <input type="text" name="middle_name" class="input-field" placeholder="e.g. Santos">
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Last Name <span class="text-primary">*</span></label>
                                <input type="text" name="last_name" required class="input-field" placeholder="e.g. Dela Cruz">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-5">
                            <div class="space-y-1.5">
                                <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Email Address <span class="text-primary">*</span></label>
                                <input type="email" name="email" id="coach-email" required class="input-field" placeholder="e.g. name@example.com">
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Contact Number <span class="text-primary">*</span></label>
                                <input type="tel" name="contact_number" required oninput="formatPhoneNumber(this)" class="input-field" placeholder="0912-345-6789">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-5">
                            <div class="space-y-1.5">
                                <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Date of Birth <span class="text-primary">*</span></label>
                                <input type="date" name="birth_date" required max="<?= date('Y-m-d', strtotime('-18 years')) ?>" class="input-field">
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Sex <span class="text-primary">*</span></label>
                                <select name="sex" required class="input-field appearance-none cursor-pointer">
                                    <option value="" disabled selected>Select Sex</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                </select>
                            </div>
                        </div>

                        <div class="space-y-1.5 mb-5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Username (For Login) <span class="text-primary">*</span></label>
                            <input type="text" name="username" required id="coach-username" class="input-field" placeholder="e.g. juan_coach2026" autocomplete="username">
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-3">
                            <div class="space-y-1.5">
                                <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Password <span class="text-primary">*</span></label>
                                <div class="relative">
                                    <input type="password" name="password" id="password" required class="input-field pr-12" placeholder="••••••••" autocomplete="new-password">
                                    <button type="button" onclick="togglePassword('password', this)" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-500 hover:text-primary transition-all">
                                        <span class="material-symbols-outlined text-lg">visibility</span>
                                    </button>
                                </div>
                                <!-- Strength Indicator -->
                                <div class="mt-4 flex gap-1 h-1.5 px-1">
                                    <div id="strength-bar-1" class="flex-1 rounded-full bg-white/10 transition-all duration-300"></div>
                                    <div id="strength-bar-2" class="flex-1 rounded-full bg-white/10 transition-all duration-300"></div>
                                    <div id="strength-bar-3" class="flex-1 rounded-full bg-white/10 transition-all duration-300"></div>
                                    <div id="strength-bar-4" class="flex-1 rounded-full bg-white/10 transition-all duration-300"></div>
                                </div>

                                <!-- Password Checklist -->
                                <div class="grid grid-cols-2 gap-y-2 mt-4 px-1">
                                    <div id="req-length" class="flex items-center gap-2 text-gray-500 transition-colors">
                                        <span class="material-symbols-outlined text-[10px]">radio_button_unchecked</span>
                                        <span class="text-[8px] font-bold uppercase tracking-widest">8+ Characters</span>
                                    </div>
                                    <div id="req-upper" class="flex items-center gap-2 text-gray-500 transition-colors">
                                        <span class="material-symbols-outlined text-[10px]">radio_button_unchecked</span>
                                        <span class="text-[8px] font-bold uppercase tracking-widest">Uppercase</span>
                                    </div>
                                    <div id="req-number" class="flex items-center gap-2 text-gray-500 transition-colors">
                                        <span class="material-symbols-outlined text-[10px]">radio_button_unchecked</span>
                                        <span class="text-[8px] font-bold uppercase tracking-widest">Number</span>
                                    </div>
                                    <div id="req-special" class="flex items-center gap-2 text-gray-500 transition-colors">
                                        <span class="material-symbols-outlined text-[10px]">radio_button_unchecked</span>
                                        <span class="text-[8px] font-bold uppercase tracking-widest">Special Char</span>
                                    </div>
                                </div>
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Confirm Password <span class="text-primary">*</span></label>
                                <div class="relative">
                                    <input type="password" id="confirm_password" name="confirm_password" required class="input-field pr-12" placeholder="••••••••" autocomplete="new-password">
                                    <button type="button" onclick="togglePassword('confirm_password', this)" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-500 hover:text-primary transition-all">
                                        <span class="material-symbols-outlined text-lg">visibility</span>
                                    </button>
                                </div>
                            </div>
                        </div>


                    </div>
                </div>
            </div>

            <!-- STEP 2: PROFESSIONAL INFO -->
            <div class="step-container step-hidden" data-step="2">
                <div class="glass-card rounded-2xl p-8 md:p-10 relative overflow-hidden">
                    <div class="absolute -top-24 -right-24 w-48 h-48 bg-primary/10 blur-[60px] rounded-full pointer-events-none"></div>
                    
                    <div class="relative z-10">
                        <div class="flex items-center gap-4 mb-8">
                            <div class="size-8 rounded-lg bg-primary/20 text-primary flex items-center justify-center font-bold text-sm border border-primary/30">2</div>
                            <h3 class="text-lg font-display font-black text-white uppercase italic tracking-tight">Professional Profile</h3>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-5">
                            <div class="space-y-1.5">
                                <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Coach Type <span class="text-primary">*</span></label>
                                <select name="coach_type" required class="input-field appearance-none cursor-pointer">
                                    <option value="" disabled selected>Select Employment Type</option>
                                    <option value="Full-time">Full-time Professional</option>
                                    <option value="Part-time">Part-time / Freelance</option>
                                </select>
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Expected Rate Per Session (₱) <span class="text-primary">*</span></label>
                                <input type="number" name="session_rate" required step="0.01" min="0" class="input-field" placeholder="0.00">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-5 items-end">
                            <div class="space-y-1.5">
                                <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">License / Certification Number (Optional)</label>
                                <input type="text" name="license_number" class="input-field" placeholder="e.g. CERT-123456">
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Upload Certification (PDF or Image) <span class="text-primary">*</span></label>
                                <input type="file" name="certification_file" required accept=".pdf,image/*" class="w-full text-sm text-gray-400 file:mr-4 file:py-2.5 file:px-5 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-white/10 file:text-white hover:file:bg-white/20 file:transition-all file:cursor-pointer">
                            </div>
                        </div>
                    </div>
                </div>
            </div>



            <div class="flex flex-col sm:flex-row gap-4 pt-6">
                <button type="button" id="prev-btn" class="hidden h-14 flex-1 rounded-xl bg-white/5 border border-white/10 text-white text-[11px] font-black uppercase tracking-widest hover:bg-white/10 transition-all flex items-center justify-center">
                    Previous
                </button>
                <button type="button" id="next-btn" class="h-14 flex-1 rounded-xl bg-primary text-white text-[11px] font-black uppercase tracking-widest shadow-lg shadow-primary/20 btn-modern flex items-center justify-center gap-3">
                    Next Step <span class="material-symbols-outlined text-lg">arrow_forward</span>
                </button>
                <button type="submit" id="submit-btn" class="hidden h-14 flex-1 rounded-xl bg-primary text-white text-[11px] font-black uppercase tracking-widest shadow-lg shadow-primary/20 btn-modern flex items-center justify-center gap-3">
                    Submit Application <span class="material-symbols-outlined text-lg">send</span>
                </button>
            </div>

        </form>
    </div>
</main>

<footer class="relative z-20 w-full py-8 text-center mt-auto">
    <p class="text-[9px] font-display font-bold text-gray-700 uppercase tracking-[0.4em]">
        © 2026 HORIZON SYSTEM. PRE-EMPLOYMENT PORTAL.
    </p>
</footer>

<script>
    const steps = document.querySelectorAll('.step-container');
    const nextBtn = document.getElementById('next-btn');
    const prevBtn = document.getElementById('prev-btn');
    const submitBtn = document.getElementById('submit-btn');
    let currentStep = 1;

    function updateUI() {
        steps.forEach(step => {
            step.classList.toggle('step-hidden', parseInt(step.dataset.step) !== currentStep);
        });

        const labels = ["Account Registration", "Professional Profile"];
        document.getElementById('step-count').innerText = currentStep;
        document.getElementById('step-label').innerText = labels[currentStep - 1];
        document.getElementById('progress-bar').style.width = `${(currentStep / 2) * 100}%`;

        prevBtn.classList.toggle('hidden', currentStep === 1);
        nextBtn.classList.toggle('hidden', currentStep === 2);
        submitBtn.classList.toggle('hidden', currentStep !== 2);
        
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

        if (currentStep === 1 && valid) {
            if (document.getElementById('password').value !== document.getElementById('confirm_password').value) {
                showAlert("Passwords do not match!", "error");
                valid = false;
            }
        }

        if (valid && currentStep < 2) {
            currentStep++;
            updateUI();
        }
    });

    prevBtn.addEventListener('click', () => {
        if (currentStep > 1) {
            currentStep--;
            updateUI();
        }
    });

    // Real-time Availability
    function setupCheck(id, field) {
        const el = document.getElementById(id);
        el.addEventListener('blur', async () => {
            const val = el.value.trim();
            if (val.length < 3) return;
            const res = await fetch(`?check_field=${field}&value=${encodeURIComponent(val)}`);
            const data = await res.json();
            if (data.exists) {
                showAlert(`This ${field} is already taken.`, "error");
                el.classList.add('border-red-500');
            } else {
                el.classList.remove('border-red-500');
            }
        });
    }
    setupCheck('coach-username', 'username');
    setupCheck('coach-email', 'email');

    function showAlert(message, type = 'error') {
        const container = document.getElementById('dynamic-alert-container');
        const color = type === 'error' ? 'red' : 'primary';
        container.innerHTML = `
            <div class="p-4 rounded-xl bg-${color}-500/10 border border-${color}-500/20 text-${color}-400 text-xs font-bold flex items-center gap-3 animate-pulse">
                <span class="material-symbols-outlined text-lg">info</span> ${message}
            </div>
        `;
        setTimeout(() => container.innerHTML = '', 5000);
    }

    // --- BANK MASKING (Removed as not applicable to this form) ---

    // Password strength meter
    function checkPasswordStrength(password) {
        const bars = [
            document.getElementById('strength-bar-1'),
            document.getElementById('strength-bar-2'),
            document.getElementById('strength-bar-3'),
            document.getElementById('strength-bar-4')
        ];
        
        const reqs = {
            length: password.length >= 8,
            upper: /[A-Z]/.test(password),
            number: /[0-9]/.test(password),
            special: /[^A-Za-z0-9]/.test(password)
        };

        let strength = 0;
        if (reqs.length) strength++;
        if (reqs.upper) strength++;
        if (reqs.number) strength++;
        if (reqs.special) strength++;
        
        // Update bars
        const colors = ['bg-rose-500', 'bg-orange-500', 'bg-amber-500', 'bg-emerald-500'];
        bars.forEach((bar, i) => {
            bar.classList.remove('bg-rose-500', 'bg-orange-500', 'bg-amber-500', 'bg-emerald-500', 'bg-white/10');
            if (i < strength) {
                bar.classList.add(colors[strength - 1]);
            } else {
                bar.classList.add('bg-white/10');
            }
        });

        // Update Checklist
        updateReq('req-length', reqs.length);
        updateReq('req-upper', reqs.upper);
        updateReq('req-number', reqs.number);
        updateReq('req-special', reqs.special);
    }

    function updateReq(id, meets) {
        const el = document.getElementById(id);
        const icon = el.querySelector('span');
        if (meets) {
            el.classList.remove('text-gray-500');
            el.classList.add('text-primary');
            icon.innerText = 'check_circle';
        } else {
            el.classList.remove('text-primary');
            el.classList.add('text-gray-500');
            icon.innerText = 'radio_button_unchecked';
        }
    }

    function togglePassword(id, btn) {
        const input = document.getElementById(id);
        const icon = btn.querySelector('span');
        if (input.type === 'password') {
            input.type = 'text';
            icon.innerText = 'visibility_off';
        } else {
            input.type = 'password';
            icon.innerText = 'visibility';
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

    document.getElementById('password').addEventListener('input', (e) => checkPasswordStrength(e.target.value));

    // --- AUTOSAVE FEATURE ---
    const STORAGE_KEY = 'coach_app_draft_' + '<?= $gym_slug ?>';
    
    function saveFormData() {
        const formData = {};
        const inputs = document.querySelectorAll('input:not([type="password"]):not([type="file"]), select, textarea');
        inputs.forEach(input => {
            if (input.name) {
                if (input.type === 'radio' || input.type === 'checkbox') {
                    if (input.checked) formData[input.name] = input.value;
                } else {
                    formData[input.name] = input.value;
                }
            }
        });
        sessionStorage.setItem(STORAGE_KEY, JSON.stringify(formData));
    }

    function restoreFormData() {
        const savedData = sessionStorage.getItem(STORAGE_KEY);
        if (savedData) {
            const formData = JSON.parse(savedData);
            Object.keys(formData).forEach(key => {
                const input = document.querySelector(`[name="${key}"]`);
                if (input) {
                    if (input.type === 'radio' || input.type === 'checkbox') {
                        if (input.value === formData[key]) input.checked = true;
                    } else {
                        input.value = formData[key];
                    }
                }
            });
            // trigger select formatting rules
            if(document.getElementById('bank_name') && document.getElementById('bank_name').value) {
                document.getElementById('bank_name').dispatchEvent(new Event('change'));
            }
        }
    }

    // Attach save listeners
    document.querySelectorAll('input:not([type="password"]):not([type="file"]), select, textarea').forEach(input => {
        input.addEventListener('input', saveFormData);
        input.addEventListener('change', saveFormData);
    });

    // Restore on load
    window.addEventListener('DOMContentLoaded', () => {
        restoreFormData();
        const pass = document.getElementById('password');
        if (pass.value) checkPasswordStrength(pass.value);
    });
    
    // Clear storage on form submit
    document.getElementById('coach-form').addEventListener('submit', function() {
        sessionStorage.removeItem(STORAGE_KEY);
    });
</script>

</body>
</html>
