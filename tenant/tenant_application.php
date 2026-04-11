<?php 
session_start(); 
require_once '../db.php';

// Handle AJAX Field Checks
if (isset($_GET['check_field']) && isset($_GET['value'])) {
    header('Content-Type: application/json');
    $field = $_GET['check_field'];
    $value = trim($_GET['value']);
    $exists = false;

    if ($field === 'username') {
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$value]);
        $exists = (bool)$stmt->fetch();
    } elseif (in_array($field, ['email', 'gym_name', 'business_name', 'bir_number', 'business_permit_no'])) {
        // Sanitize for DB check if it's bir_number
        if ($field === 'bir_number') $value = str_replace('-', '', $value);
        
        $stmt = $pdo->prepare("SELECT application_id FROM gym_owner_applications WHERE `$field` = ? LIMIT 1");
        $stmt->execute([$value]);
        $exists = (bool)$stmt->fetch();
    }
    
    echo json_encode(['exists' => $exists]);
    exit;
}

$form_data = $_SESSION['application_data'] ?? [];
unset($_SESSION['application_data']);
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Gym Onboarding | Horizon Systems</title>

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

        .step-hidden { display: none; }
        
        select option {
            background-color: #08080a;
            color: white;
            padding: 10px;
        }

        input[type="time"] {
            color-scheme: dark;
        }
        
        input[type="time"]::-webkit-calendar-picker-indicator {
            cursor: pointer;
            opacity: 0.7;
            transition: opacity 0.2s ease;
        }
        input[type="time"]::-webkit-calendar-picker-indicator:hover {
            opacity: 1;
        }

        /* Scrollable Portal Specifically for Dropdowns */
        .scrollbar-visible::-webkit-scrollbar { display: block !important; width: 4px; }
        .scrollbar-visible::-webkit-scrollbar-thumb { background: rgba(127, 19, 236, 0.4); border-radius: 10px; }
        .scrollbar-visible::-webkit-scrollbar-track { background: rgba(255, 255, 255, 0.02); }

        /* Invisible Scroll System */
        *::-webkit-scrollbar { display: none !important; }
        * { -ms-overflow-style: none !important; scrollbar-width: none !important; }
    </style>
</head>

<body class="font-sans antialiased min-h-screen flex flex-col hero-glow">

<header class="relative z-20 w-full px-8 py-6 flex justify-between items-center bg-transparent">
    <a href="../index.php" class="flex items-center gap-3 hover:opacity-80 transition-opacity">
        <div class="size-8 bg-primary/20 rounded-lg flex items-center justify-center border border-primary/30 overflow-hidden">
            <img src="../assests/horizon logo.png" alt="Horizon Logo" class="size-full object-contain rounded-lg">
        </div>
        <h2 class="text-lg font-display font-bold text-white uppercase italic tracking-tighter">Horizon <span class="text-primary">System</span></h2>
    </a>
    <div class="flex items-center gap-6">
        <a href="../login.php" class="text-[10px] font-display font-bold uppercase tracking-widest text-gray-500 hover:text-white transition-all flex items-center gap-2">
            <span class="material-symbols-outlined text-sm">arrow_back</span>
            Back to Login
        </a>
    </div>
</header>

<main class="flex-1 flex flex-col items-center py-12 px-4 relative z-10 w-full overflow-x-hidden">
    <div class="w-full max-w-2xl">
        <div class="text-center mb-10">
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/5 border border-white/10 text-primary text-[9px] font-black uppercase tracking-[0.2em] mb-4">
                Partner Registration
            </div>
            <h1 class="text-4xl font-display font-black text-white uppercase italic tracking-tighter mb-2">Apply for <span class="text-primary">Partnership</span></h1>
            <p class="text-xs text-gray-500 font-medium uppercase tracking-widest mb-12">Step <span id="step-number">1</span> of 3: Provider Details</p>
            
            <div class="w-full bg-white/5 h-1.5 mt-8 rounded-full overflow-hidden">
                <div id="progress-bar" class="bg-primary h-full transition-all duration-500" style="width: 33.33%"></div>
            </div>
        </div>

        <div id="dynamic-alert-container" class="w-full pointer-events-none sticky top-4 z-[9999] px-2 mb-2"></div>

        <form id="multi-step-form" action="../action/submit_application.php" method="POST" enctype="multipart/form-data" class="space-y-6">
            
            <?php if (isset($_SESSION['application_error'])): ?>
                <script>window.addEventListener('load', () => showAlert("<?= addslashes(htmlspecialchars($_SESSION['application_error'])) ?>", 'error'));</script>
                <?php unset($_SESSION['application_error']); ?>
            <?php endif; ?>
            
            <div class="step-container" data-step="1">
                <div class="dashboard-window rounded-2xl p-8 md:p-10 relative">
                    <div class="absolute inset-0 rounded-2xl overflow-hidden pointer-events-none">
                        <div class="absolute -top-24 -right-24 w-48 h-48 bg-primary/10 blur-[60px] rounded-full pointer-events-none"></div>
                    </div>
                    <div class="relative z-10">
                        <div class="flex items-center gap-4 mb-8">
                            <span class="size-8 rounded-lg bg-primary/20 text-primary flex items-center justify-center font-bold text-sm border border-primary/30">1</span>
                            <h3 class="text-lg font-display font-black text-white uppercase italic tracking-tight">Owner Account Details</h3>
                        </div>
                        
                        <p class="text-[10px] text-gray-500 font-bold uppercase tracking-widest mb-8">Primary Administrative Credentials</p>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-5">
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">First Name <span class="text-red-500">*</span></label>
                            <input type="text" name="first_name" required placeholder="e.g. Juan" autocomplete="given-name" class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Middle Name (Optional)</label>
                            <input type="text" name="middle_name" placeholder="e.g. Santos" autocomplete="additional-name" class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Last Name <span class="text-red-500">*</span></label>
                            <input type="text" name="last_name" required placeholder="e.g. Dela Cruz" autocomplete="family-name" class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-5">
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Personal Email Address <span class="text-red-500">*</span></label>
                            <input type="email" name="owner_email" required placeholder="e.g. name@example.com" autocomplete="email" class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Personal Contact Number <span class="text-red-500">*</span></label>
                            <input type="text" name="owner_contact" id="owner_contact" required placeholder="0912-345-6789" maxlength="13" autocomplete="tel" class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-5">
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Date of Birth <span class="text-red-500">*</span></label>
                            <input type="date" name="owner_dob" id="owner_dob" required max="<?php echo date('Y-m-d', strtotime('-18 years')); ?>" class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none">
                            <p id="age-error" class="text-[9px] font-bold text-red-400 mt-1 ml-1 hidden flex items-center gap-1">
                                <span class="material-symbols-outlined text-[10px]">error</span>
                                You must be at least 18 years old.
                            </p>
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Sex <span class="text-red-500">*</span></label>
                            <select name="owner_sex" required class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none cursor-pointer appearance-none">
                                <option value="">Select Sex</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                    </div>

                    <div class="space-y-1.5 mb-5">
                        <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Username (For Login) <span class="text-red-500">*</span></label>
                        <input type="text" name="username" required placeholder="e.g. juan_owner2026" autocomplete="username" class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none">
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Password <span class="text-red-500">*</span></label>
                            <div class="relative group">
                                <input type="password" id="reg-password" name="password" required placeholder="••••••••" autocomplete="new-password" class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md pl-4 pr-12 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none">
                                <button type="button" onclick="togglePasswordVisibility('reg-password', 'eye-icon-pass')" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-primary transition-colors">
                                    <span id="eye-icon-pass" class="material-symbols-outlined text-[20px]">visibility</span>
                                </button>
                            </div>
                            <div class="mt-2 flex gap-1 h-1.5 px-1">
                                <div id="strength-bar-1" class="flex-1 rounded-full bg-white/5 transition-colors"></div>
                                <div id="strength-bar-2" class="flex-1 rounded-full bg-white/5 transition-colors"></div>
                                <div id="strength-bar-3" class="flex-1 rounded-full bg-white/5 transition-colors"></div>
                                <div id="strength-bar-4" class="flex-1 rounded-full bg-white/5 transition-colors"></div>
                            </div>
                            <p id="strength-text" class="text-[9px] font-bold uppercase tracking-widest text-gray-500 mt-1 ml-1">Strength: <span id="strength-label">None</span></p>

                            <!-- Password Requirements Checklist -->
                            <div class="grid grid-cols-2 gap-y-3 mt-5 px-1">
                                <div id="req-length" class="flex items-center gap-2 text-gray-500 transition-colors">
                                    <span class="material-symbols-outlined text-sm transition-all">radio_button_unchecked</span>
                                    <span class="text-[9px] font-bold uppercase tracking-widest">8+ Characters</span>
                                </div>
                                <div id="req-upper" class="flex items-center gap-2 text-gray-500 transition-colors">
                                    <span class="material-symbols-outlined text-sm transition-all">radio_button_unchecked</span>
                                    <span class="text-[9px] font-bold uppercase tracking-widest">Uppercase</span>
                                </div>
                                <div id="req-number" class="flex items-center gap-2 text-gray-500 transition-colors">
                                    <span class="material-symbols-outlined text-sm transition-all">radio_button_unchecked</span>
                                    <span class="text-[9px] font-bold uppercase tracking-widest">Number</span>
                                </div>
                                <div id="req-special" class="flex items-center gap-2 text-gray-500 transition-colors">
                                    <span class="material-symbols-outlined text-sm transition-all">radio_button_unchecked</span>
                                    <span class="text-[9px] font-bold uppercase tracking-widest">Special Char</span>
                                </div>
                            </div>
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Confirm Password <span class="text-red-500">*</span></label>
                            <div class="relative group">
                                <input type="password" id="reg-confirm-password" name="confirm_password" placeholder="••••••••" required autocomplete="new-password" class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md pl-4 pr-12 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none">
                                <button type="button" onclick="togglePasswordVisibility('reg-confirm-password', 'eye-icon-confirm')" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-primary transition-colors">
                                    <span id="eye-icon-confirm" class="material-symbols-outlined text-[20px]">visibility</span>
                                </button>
                            </div>
                            <p id="match-text" class="text-[9px] font-bold uppercase tracking-widest text-gray-500 mt-2 ml-1 opacity-0 transition-opacity">Status: <span id="match-label">Not Matching</span></p>
                        </div>
                    </div>
                    
                    <p id="password-error" class="text-xs font-semibold text-red-400 mt-3 hidden flex items-center gap-1">
                        <span class="material-symbols-outlined text-[14px]">error</span>
                        Passwords do not match!
                    </p>

                    </div>
                </div>
            </div>

            <div class="step-container step-hidden" data-step="2">
                <div class="dashboard-window rounded-2xl p-8 md:p-10 relative">
                    <div class="absolute inset-0 rounded-2xl overflow-hidden pointer-events-none">
                        <div class="absolute -top-24 -right-24 w-48 h-48 bg-primary/10 blur-[60px] rounded-full pointer-events-none"></div>
                    </div>
                    <div class="relative z-10">
                        <div class="flex items-center gap-4 mb-8">
                            <span class="size-8 rounded-lg bg-primary/20 text-primary flex items-center justify-center font-bold text-sm border border-primary/30">2</span>
                            <h3 class="text-lg font-display font-black text-white uppercase italic tracking-tight">Business Profile & Location</h3>
                        </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-6">
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Gym / Brand Name <span class="text-red-500">*</span></label>
                            <input type="text" name="gym_name" required placeholder="e.g. Iron Forge Gym" class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Registered Business Name <span class="text-red-500">*</span></label>
                            <input type="text" name="business_name" required placeholder="e.g. Iron Forge Fitness Inc." class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Gym Official Email <span class="text-red-500">*</span></label>
                            <input type="email" name="gym_email" required placeholder="gym@example.com" class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Gym Contact Number <span class="text-red-500">*</span></label>
                            <input type="text" name="gym_contact" id="gym_contact" required placeholder="0912-345-6789" maxlength="13" class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none">
                        </div>

                        <div class="space-y-1.5 md:col-span-2">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Business Type</label>
                            <select name="business_type" class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none cursor-pointer appearance-none">
                                <option value="sole_proprietorship">Sole Proprietorship</option>
                                <option value="partnership">Partnership</option>
                                <option value="corporation">Corporation</option>
                            </select>
                        </div>
                        <div class="space-y-1.5 md:col-span-2">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Profile Picture (Gym Logo)</label>
                            <input type="file" name="profile_picture" accept="image/*" class="w-full text-sm text-gray-400 file:mr-4 file:py-2.5 file:px-5 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-white/10 file:text-white hover:file:bg-white/20 file:transition-all file:cursor-pointer">
                        </div>
                    </div>

                    <hr class="border-white/5 my-6">

                    <h4 class="text-xs font-black uppercase text-primary tracking-wider mb-4">Gym Address</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div class="md:col-span-2 space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Street Address <span class="text-red-500">*</span></label>
                            <input type="text" name="gym_address_line" placeholder="Unit No., Street Name" required class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest ml-1">Region <span class="text-red-500">*</span></label>
                            <input type="text" name="region" placeholder="e.g. Central Luzon" required class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest ml-1">Province <span class="text-red-500">*</span></label>
                            <input type="text" name="province" placeholder="e.g. Bulacan" required class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest ml-1">City / Municipality <span class="text-red-500">*</span></label>
                            <input type="text" name="city" placeholder="e.g. Baliwag" required class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest ml-1">Barangay <span class="text-red-500">*</span></label>
                            <input type="text" name="barangay" placeholder="e.g. Bagong Nayon" required class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none">
                        </div>
                    </div>
                    </div>
                </div>
            </div>

            <div class="step-container step-hidden" data-step="3">
                <div class="dashboard-window rounded-2xl p-8 md:p-10 relative">
                    <div class="absolute inset-0 rounded-2xl overflow-hidden pointer-events-none">
                        <div class="absolute -top-24 -right-24 w-48 h-48 bg-primary/10 blur-[60px] rounded-full pointer-events-none"></div>
                    </div>
                    <div class="relative z-10">
                        <div class="flex items-center gap-4 mb-8">
                            <span class="size-8 rounded-lg bg-primary/20 text-primary flex items-center justify-center font-bold text-sm border border-primary/30">3</span>
                            <h3 class="text-lg font-display font-black text-white uppercase italic tracking-tight">Legal & Financial Verification</h3>
                        </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-6">
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Valid ID Type <span class="text-red-500">*</span></label>
                            <select name="owner_valid_id_type" required class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none cursor-pointer appearance-none">
                                <option value="">Select ID Type</option>
                                <option value="passport">Passport</option>
                                <option value="drivers_license">Driver's License</option>
                                <option value="national_id">National ID</option>
                                <option value="tin_id">TIN ID</option>
                            </select>
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Upload Valid ID <span class="text-red-500">*</span></label>
                            <input type="file" name="owner_valid_id_file" required accept=".pdf,image/*" class="w-full text-sm text-gray-400 file:mr-4 file:py-2.5 file:px-5 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-white/10 file:text-white hover:file:bg-white/20 file:transition-all file:cursor-pointer">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">BIR Number (TIN) <span class="text-red-500">*</span></label>
                            <input type="text" name="bir_number" id="bir_number" required placeholder="XXX-XXX-XXX-XXX" maxlength="15" class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">BIR Document (0605 / COR) <span class="text-red-500">*</span></label>
                            <input type="file" name="bir_document" required accept=".pdf,image/*" class="w-full text-sm text-gray-400 file:mr-4 file:py-2.5 file:px-5 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-white/10 file:text-white hover:file:bg-white/20 file:transition-all file:cursor-pointer">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Business Permit No. <span class="text-red-500">*</span></label>
                            <input type="text" name="business_permit_no" id="business_permit_no" required placeholder="BIN-XXX-XX-YYYY-123456" maxlength="22" class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Mayor's Permit (File) <span class="text-red-500">*</span></label>
                            <input type="file" name="business_permit" required accept=".pdf,image/*" class="w-full text-sm text-gray-400 file:mr-4 file:py-2.5 file:px-5 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-white/10 file:text-white hover:file:bg-white/20 file:transition-all file:cursor-pointer">
                        </div>
                    </div>

                    <hr class="border-white/5 my-6">

                    <h4 class="text-xs font-black uppercase text-primary tracking-wider mb-4">Payout Information</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-5">
                        <div class="space-y-1.5 md:col-span-2">
                            <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest ml-1">Bank or E-Wallet Name <span class="text-red-500">*</span></label>
                            <div class="relative" id="bank-dropdown-container">
                                <input type="text" id="bank-search" placeholder="Search Bank or E-Wallet..." class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 transition-all outline-none" autocomplete="off">
                                <input type="hidden" name="bank_name" id="bank_name_hidden" required>
                                
                                <div id="bank-options" class="absolute z-50 w-full mt-2 bg-[#08080a] border border-white/10 rounded-xl max-h-60 overflow-y-auto hidden shadow-2xl backdrop-blur-xl scrollbar-visible">
                                    <div class="p-2 space-y-1" id="bank-list">
                                        <!-- Banks will be injected here -->
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest ml-1">Account Name <span class="text-red-500">*</span></label>
                            <input type="text" name="account_name" required placeholder="Juan Dela Cruz" class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 transition-all outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label id="account-number-label" class="text-[10px] font-bold text-gray-400 uppercase tracking-widest ml-1">Account Number <span class="text-red-500">*</span></label>
                            <input type="text" name="account_number" id="account_number" required placeholder="e.g. 1234567890" maxlength="12" class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 transition-all outline-none">
                        </div>
                    </div>


                    </div>
                </div>
            </div>

            <div class="flex gap-4 pt-2">
                <button type="button" id="prev-btn" class="hidden flex-1 h-12 rounded-xl bg-white/5 border border-white/10 backdrop-blur-md text-white font-display font-bold uppercase tracking-widest text-[10px] hover:bg-white/10 transition-all">
                    Previous
                </button>
                <button type="button" id="next-btn" class="flex-1 h-12 rounded-xl bg-primary hover:bg-primary-dark text-white font-display font-bold uppercase tracking-widest text-[10px] transition-all shadow-lg shadow-primary/20 flex items-center justify-center gap-2">
                    Next Step
                    <span class="material-symbols-outlined text-lg">arrow_forward</span>
                </button>
                <button type="submit" id="submit-btn" class="hidden flex-1 h-12 rounded-xl bg-primary hover:bg-primary-dark text-white font-display font-bold uppercase tracking-widest text-[10px] transition-all shadow-lg shadow-primary/20 flex items-center justify-center gap-2">
                    Submit Application
                    <span class="material-symbols-outlined text-lg">send</span>
                </button>
            </div>

        </form>
    </div>
</main>

<footer class="relative z-20 w-full py-6 text-center -mt-10">
    <p class="text-[9px] font-display font-bold text-gray-700 uppercase tracking-[0.4em]">
        © 2026 HORIZON SYSTEM. SECURE ENVIRONMENT.
    </p>
</footer>

<script>
    const form = document.getElementById('multi-step-form');
    const steps = document.querySelectorAll('.step-container');
    const nextBtn = document.getElementById('next-btn');
    const prevBtn = document.getElementById('prev-btn');
    const submitBtn = document.getElementById('submit-btn');
    const progressBar = document.getElementById('progress-bar');
    const stepNumberLabel = document.getElementById('step-number');
    
    let currentStep = 1;
    const totalSteps = steps.length; 

    // Toggles password visibility
    function togglePasswordVisibility(inputId, iconId) {
        const input = document.getElementById(inputId);
        const icon = document.getElementById(iconId);
        if (input.type === 'password') {
            input.type = 'text';
            icon.textContent = 'visibility_off';
        } else {
            input.type = 'password';
            icon.textContent = 'visibility';
        }
    }

    // --- NEW NOTIFICATION SYSTEM START ---
    function dismissAlert(id) {
        const alert = document.getElementById(id);
        if (alert) {
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-10px)';
            alert.style.transition = 'all 0.5s cubic-bezier(0.4, 0, 0.2, 1)';
            setTimeout(() => alert.remove(), 500);
        }
    }

    function showAlert(message, type = 'error') {
        const container = document.getElementById('dynamic-alert-container');
        
        // Anti-spam: limit to 1 visible alert
        if (container.children.length >= 1) {
            const oldest = container.lastElementChild;
            if (oldest) dismissAlert(oldest.id);
        }

        const id = 'alert-' + Math.random().toString(36).substr(2, 9);
        const icon = type === 'success' ? 'check_circle' : 'error';
        const colorClass = type === 'success' ? 'bg-green-500/10 border-green-500/20 text-green-400' : 'bg-red-500/10 border-red-500/20 text-red-500';

        const alertHtml = `
            <div id="${id}" class="mb-4 p-3 rounded-xl ${colorClass} border text-[10px] font-bold uppercase tracking-wider flex items-center gap-3 animate-fade-in pointer-events-auto shadow-xl backdrop-blur-xl transition-all duration-500">
                <div class="size-6 rounded-md ${type === 'success' ? 'bg-green-500/20' : 'bg-red-500/20'} flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-base">${icon}</span>
                </div>
                <span class="flex-1 leading-normal">${message}</span>
                <button onclick="dismissAlert('${id}')" class="size-6 flex items-center justify-center rounded-md hover:bg-white/5 opacity-30 hover:opacity-100 transition-all">
                    <span class="material-symbols-outlined text-xs">close</span>
                </button>
            </div>
        `;
        container.insertAdjacentHTML('afterbegin', alertHtml);
        
        // Auto-scroll to top so user sees the notification
        window.scrollTo({ top: 0, behavior: 'smooth' });
        
        setTimeout(() => dismissAlert(id), 15000); 
    }

    // Auto-dismiss initial alerts
    window.addEventListener('load', () => {
        const errorAlert = document.getElementById('error-alert');
        if (errorAlert) setTimeout(() => dismissAlert('error-alert'), 15000);
    });
    // --- NEW NOTIFICATION SYSTEM END ---

    function updateUI() {
        // Clear all active alerts when moving between steps to prevent stale notifications
        const alertContainer = document.getElementById('dynamic-alert-container');
        if (alertContainer) alertContainer.innerHTML = '';

        steps.forEach(step => {
            if(parseInt(step.dataset.step) === currentStep) {
                step.classList.remove('step-hidden');
            } else {
                step.classList.add('step-hidden');
            }
        });

        const progress = (currentStep / totalSteps) * 100;
        progressBar.style.width = `${progress}%`;
        stepNumberLabel.innerText = currentStep;

        prevBtn.classList.toggle('hidden', currentStep === 1);
        
        if (currentStep === totalSteps) {
            nextBtn.classList.add('hidden');
            submitBtn.classList.remove('hidden');
        } else {
            nextBtn.classList.remove('hidden');
            submitBtn.classList.add('hidden');
        }
        
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // --- NEW FORMATTING & MASKING CODE START ---
    const banks = [
        "BDO Unibank", "BPI", "Land Bank of the Philippines",
        "Metrobank", "Security Bank", "Union Bank",
        "PNB", "China Bank", "RCBC", "EastWest Bank",
        "GCash", "Maya", "GrabPay", "ShopeePay", "Coins.ph", "SeaBank"
    ];

    const bankSearch = document.getElementById('bank-search');
    const bankOptions = document.getElementById('bank-options');
    const bankList = document.getElementById('bank-list');
    const bankHidden = document.getElementById('bank_name_hidden');
    const accNumberInput = document.getElementById('account_number');
    const accNumberLabel = document.getElementById('account-number-label');

    // Populate bank list
    function renderBanks(filter = "") {
        const filtered = banks.filter(b => b.toLowerCase().includes(filter.toLowerCase()));
        bankList.innerHTML = filtered.map(b => `
            <div class="bank-item px-4 py-3 hover:bg-white/5 cursor-pointer rounded-lg text-sm text-gray-300 hover:text-white transition-colors flex items-center gap-3" data-name="${b}">
                <span class="material-symbols-outlined text-sm text-primary opacity-50">account_balance</span>
                ${b}
            </div>
        `).join('') || `<div class="p-4 text-xs text-gray-500 italic">No bank found. You can keep typing...</div>`;
    }

    renderBanks();

    bankSearch.addEventListener('focus', () => {
        bankOptions.classList.remove('hidden');
        renderBanks(bankSearch.value);
    });

    bankSearch.addEventListener('input', (e) => {
        renderBanks(e.target.value);
        bankHidden.value = e.target.value; // Allow custom typing too
    });

    document.addEventListener('click', (e) => {
        if (!document.getElementById('bank-dropdown-container').contains(e.target)) {
            bankOptions.classList.add('hidden');
        }
    });

    bankList.addEventListener('click', (e) => {
        const item = e.target.closest('.bank-item');
        if (item) {
            const name = item.dataset.name;
            bankSearch.value = name;
            bankHidden.value = name;
            bankOptions.classList.add('hidden');
            handleBankChange(name);
        }
    });

    function handleBankChange(name) {
        if (name === "GCash" || name === "Maya") {
            accNumberLabel.innerHTML = `${name} Mobile Number <span class="text-red-500">*</span>`;
            accNumberInput.placeholder = "0912-345-6789";
            accNumberInput.classList.add('phone-mask');
            accNumberInput.maxLength = 13;
        } else {
            accNumberLabel.innerHTML = `Account Number <span class="text-red-500">*</span>`;
            accNumberInput.placeholder = "e.g. 1234567890";
            accNumberInput.classList.remove('phone-mask');
            accNumberInput.maxLength = 12;
            accNumberInput.value = accNumberInput.value.replace(/[^0-9]/g, '');
        }
    }

    // Generic Masking Function
    function applyMask(input, mask) {
        let value = input.value.replace(/\D/g, ''); // Remove non-digits
        
        // Specific logic for Business Permit No (BIN- prefix)
        if (input.id === 'business_permit_no') {
            applyBusinessPermitMask(input);
            return;
        }

        let formatted = "";
        let valIdx = 0;
        
        for (let i = 0; i < mask.length && valIdx < value.length; i++) {
            if (mask[i] === 'X') {
                formatted += value[valIdx++];
            } else {
                formatted += mask[i];
                if (value[valIdx] === mask[i]) valIdx++; // Skip if user typed the separator
            }
        }
        input.value = formatted;
    }

    function applyBusinessPermitMask(input) {
        let digits = input.value.replace(/\D/g, '');
        let formatted = "BIN-";
        
        // Target format: BIN-XXX-XX-YYYY-XXXXXX
        if (digits.length > 0) formatted += digits.substring(0, 3);
        if (digits.length > 3) formatted += "-" + digits.substring(3, 5);
        if (digits.length > 5) formatted += "-" + digits.substring(5, 9);
        if (digits.length > 9) formatted += "-" + digits.substring(9, 15);
        
        input.value = formatted;
    }

    // Attach masks
    const phoneFields = ['owner_contact', 'gym_contact', 'account_number'];
    phoneFields.forEach(id => {
        const el = document.getElementById(id);
        el.addEventListener('input', () => {
            if (id === 'account_number' && !el.classList.contains('phone-mask')) {
                el.value = el.value.replace(/[^0-9]/g, '');
                return;
            }
            applyMask(el, "09XX-XXX-XXXX");
        });
    });

    document.getElementById('bir_number').addEventListener('input', (e) => {
        applyMask(e.target, "XXX-XXX-XXX-XXX");
    });

    document.getElementById('business_permit_no').addEventListener('input', (e) => {
        applyBusinessPermitMask(e.target);
    });

    // --- NEW FORMATTING & MASKING CODE END ---

    // --- REAL-TIME AVAILABILITY CHECKS START ---
    const fieldStates = {
        username: { exists: false, checking: false, timeout: null, label: 'Username', dbField: 'username' },
        gym_name: { exists: false, checking: false, timeout: null, label: 'Gym Name', dbField: 'gym_name' },
        business_name: { exists: false, checking: false, timeout: null, label: 'Registered Business Name', dbField: 'business_name' },
        gym_email: { exists: false, checking: false, timeout: null, label: 'Gym Email', dbField: 'email' },
        bir_number: { exists: false, checking: false, timeout: null, label: 'BIR Number (TIN)', dbField: 'bir_number' },
        business_permit_no: { exists: false, checking: false, timeout: null, label: 'Business Permit No.', dbField: 'business_permit_no' }
    };

    function setupRealTimeValidation(fieldName, selector) {
        const input = document.querySelector(selector);
        if (!input) return;

        input.addEventListener('input', () => {
            const state = fieldStates[fieldName];
            clearTimeout(state.timeout);
            const val = input.value.trim();

            // Reset state immediately on new input to prevent stale 'exists' flags
            state.exists = false;
            input.classList.remove('border-red-500/50', 'bg-red-500/5');

            if (val.length < 3) return;

            state.timeout = setTimeout(async () => {
                state.checking = true;
                try {
                    const response = await fetch(`?check_field=${state.dbField}&value=${encodeURIComponent(val)}`);
                    const data = await response.json();
                    state.exists = data.exists;

                    if (state.exists) {
                        showAlert(`The ${state.label} "${val}" is already registered. Please use another.`, 'error');
                        input.classList.add('border-red-500/50', 'bg-red-500/5');
                    }
                } catch (err) {
                    console.error(`${state.label} check failed`, err);
                } finally {
                    state.checking = false;
                }
            }, 700);
        });
    }

    // Initialize checks
    setupRealTimeValidation('username', 'input[name="username"]');
    setupRealTimeValidation('gym_name', 'input[name="gym_name"]');
    setupRealTimeValidation('business_name', 'input[name="business_name"]');
    setupRealTimeValidation('gym_email', 'input[name="gym_email"]');
    setupRealTimeValidation('bir_number', '#bir_number');
    setupRealTimeValidation('business_permit_no', '#business_permit_no');
    // --- REAL-TIME AVAILABILITY CHECKS END ---

    // Real-time password match feedback
    const passwordInput = document.getElementById('reg-password');
    const confirmPasswordInput = document.getElementById('reg-confirm-password');
    const errorText = document.getElementById('password-error');

    // Real-time password verification (Match & Strength)
    const validatePasswords = () => {
        const p1 = passwordInput.value;
        const p2 = confirmPasswordInput.value;
        const matchText = document.getElementById('match-text');
        const matchLabel = document.getElementById('match-label');
        
        if (p2.length > 0) {
            matchText.classList.remove('opacity-0');
            if (p1 === p2) {
                matchLabel.textContent = 'Passwords Match';
                matchLabel.className = 'text-green-500';
            } else {
                matchLabel.textContent = 'Passwords Do Not Match';
                matchLabel.className = 'text-red-400';
            }
        } else {
            matchText.classList.add('opacity-0');
        }

        if (confirmPasswordInput.value && passwordInput.value !== confirmPasswordInput.value) {
            errorText.classList.remove('hidden');
        } else {
            errorText.classList.add('hidden');
        }
    };

    function checkPasswordStrength(password) {
        const strengthLabel = document.getElementById('strength-label');
        const bars = [
            document.getElementById('strength-bar-1'),
            document.getElementById('strength-bar-2'),
            document.getElementById('strength-bar-3'),
            document.getElementById('strength-bar-4')
        ];

        // Requirements Checklist Indicators
        const reqs = {
            length: { el: document.getElementById('req-length'), check: password.length >= 8 },
            upper: { el: document.getElementById('req-upper'), check: /[A-Z]/.test(password) },
            number: { el: document.getElementById('req-number'), check: /[0-9]/.test(password) },
            special: { el: document.getElementById('req-special'), check: /[^A-Za-z0-9]/.test(password) }
        };

        let strength = 0;
        // Update UI for each requirement
        Object.keys(reqs).forEach(key => {
            const item = reqs[key];
            const icon = item.el.querySelector('.material-symbols-outlined');
            if (item.check) {
                item.el.classList.remove('text-gray-500');
                item.el.classList.add('text-primary');
                icon.textContent = 'check_circle';
                strength++;
            } else {
                item.el.classList.add('text-gray-500');
                item.el.classList.remove('text-primary');
                icon.textContent = 'radio_button_unchecked';
            }
        });

        // Reset bars
        bars.forEach(bar => {
            bar.className = 'flex-1 rounded-full bg-white/5 transition-colors';
        });

        const colors = ['bg-red-500', 'bg-orange-500', 'bg-yellow-500', 'bg-green-500'];
        const labels = ['Weak', 'Fair', 'Good', 'Strong'];

        if (password.length > 0) {
            const displayStrength = Math.max(1, strength);
            for (let i = 0; i < displayStrength; i++) {
                bars[i].className = 'flex-1 rounded-full ' + colors[displayStrength - 1] + ' transition-colors';
            }
            strengthLabel.textContent = labels[displayStrength - 1];
            strengthLabel.className = colors[displayStrength - 1].replace('bg-', 'text-');
        } else {
            strengthLabel.textContent = 'None';
            strengthLabel.className = 'text-gray-500';
        }
        return strength;
    }

    passwordInput.addEventListener('input', () => {
        checkPasswordStrength(passwordInput.value);
        validatePasswords();
    });
    
    confirmPasswordInput.addEventListener('input', validatePasswords);

    nextBtn.addEventListener('click', () => {
        // Validate all required fields on the current step before proceeding
        const currentStepEl = document.querySelector(`.step-container[data-step="${currentStep}"]`);
        const inputs = currentStepEl.querySelectorAll('input, select, textarea');
        
        let allValid = true;
        for (let input of inputs) {
            if (!input.checkValidity()) {
                input.reportValidity(); // This forces the browser to show the required tooltip
                allValid = false;
                break; // Stop at the first invalid input
            }
        }

        if (!allValid) return; // Prevent moving to next step if there's an error

        // Additional validation for Step 1
        if (currentStep === 1) {
            if (fieldStates.username.checking) {
                showAlert('Verifying username availability...', 'success');
                return;
            }
            if (fieldStates.username.exists) {
                showAlert('The chosen username is already taken. Please choose another.', 'error');
                document.querySelector('input[name="username"]').focus();
                return;
            }

            const strength = checkPasswordStrength(passwordInput.value);
            if (strength < 4) {
                showAlert('Please fulfill all password requirements before proceeding.');
                passwordInput.focus();
                return;
            }

            if (passwordInput.value !== confirmPasswordInput.value) {
                errorText.classList.remove('hidden');
                confirmPasswordInput.focus();
                return;
            }
            
            const dob = new Date(document.getElementById('owner_dob').value);
            const today = new Date();
            let age = today.getFullYear() - dob.getFullYear();
            const m = today.getMonth() - dob.getMonth();
            if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) age--;
            
            const ageError = document.getElementById('age-error');
            if (age < 18) {
                ageError.classList.remove('hidden');
                document.getElementById('owner_dob').focus();
                return;
            } else {
                ageError.classList.add('hidden');
            }
            
            const contactField = document.getElementById('owner_contact');
            const contact = contactField.value;
            const rawContact = contact.replace(/\D/g, '');
            if (rawContact.length !== 11 || !rawContact.startsWith('09')) {
                showAlert('Please enter a valid Philippine mobile number (e.g., 0912-345-6789)');
                contactField.focus();
                return;
            }
        }

        // Additional validation for Step 2
        if (currentStep === 2) {
            // Check availability flags
            if (fieldStates.gym_name.exists || fieldStates.business_name.exists || fieldStates.gym_email.exists) {
                const dupField = fieldStates.gym_name.exists ? 'gym_name' : (fieldStates.business_name.exists ? 'business_name' : 'gym_email');
                showAlert(`Please resolve the duplicate ${fieldStates[dupField].label} before proceeding.`, 'error');
                document.querySelector(`input[name="${dupField}"]`).focus();
                return;
            }

            const gymContactField = document.getElementById('gym_contact');
            const contact = gymContactField.value;
            const rawContact = contact.replace(/\D/g, '');
            if (rawContact.length !== 11 || !rawContact.startsWith('09')) {
                showAlert('Please enter a valid Philippine mobile number for the gym (e.g., 0912-345-6789)');
                gymContactField.focus();
                return;
            }
        }

        if (currentStep < totalSteps) {
            currentStep++;
            updateUI();
        }
    });
    // --- NEW VALIDATION CODE END ---

    // --- RESTORE FORM DATA ---
    const formData = <?= json_encode($form_data) ?>;
    if (formData && Object.keys(formData).length > 0) {
        Object.keys(formData).forEach(key => {
            if (['password', 'confirm_password', 'username', 'gym_email'].includes(key)) return;
            


            const checkbox = document.querySelector(`input[type="checkbox"][name="${key}"]`);
            if (checkbox) {
                if (formData[key] === checkbox.value) checkbox.checked = true;
                return;
            }

            const input = document.querySelector(`[name="${key}"]`);
            if (input && input.type !== 'file') {
                input.value = formData[key];
            }
        });
    }

    prevBtn.addEventListener('click', () => {
        if (currentStep > 1) {
            currentStep--;
            updateUI();
        }
    });

    form.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') {
            e.preventDefault();
            if (currentStep < totalSteps) {
                nextBtn.click();
            } else {
                submitBtn.click();
            }
        }
    });

    form.addEventListener('submit', (e) => {
        // Final validation for Step 3
        const currentStepEl = document.querySelector(`.step-container[data-step="3"]`);
        const inputs = currentStepEl.querySelectorAll('input, select');
        
        let allValid = true;
        for (let input of inputs) {
            if (!input.checkValidity()) {
                input.reportValidity();
                allValid = false;
                break;
            }
        }

        if (!allValid) {
            e.preventDefault();
            return;
        }

        // Check Step 3 availability flags
        if (fieldStates.bir_number.exists || fieldStates.business_permit_no.exists) {
            e.preventDefault();
            const dupField = fieldStates.bir_number.exists ? 'bir_number' : 'business_permit_no';
            showAlert(`Please resolve the duplicate ${fieldStates[dupField].label} before submitting.`, 'error');
            document.getElementById(dupField).focus();
            return;
        }

        const tin = document.getElementById('bir_number').value.replace(/\D/g, '');
        if (tin.length !== 12) {
            e.preventDefault();
            showAlert('Please enter a valid 12-digit TIN number (XXX-XXX-XXX-XXX)');
            document.getElementById('bir_number').focus();
            return;
        }

        submitBtn.disabled = true;
        submitBtn.innerHTML = `Processing... <span class="material-symbols-outlined text-lg animate-spin">refresh</span>`;
    });
</script>

</body>
</html>