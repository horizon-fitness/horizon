<?php 
session_start(); 
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
    </style>
</head>

<body class="font-sans antialiased min-h-screen flex flex-col hero-glow">

<header class="relative z-20 w-full px-8 py-6 flex justify-between items-center bg-transparent">
    <a href="../index.php" class="flex items-center gap-3 hover:opacity-80 transition-opacity">
        <div class="size-8 bg-primary/20 rounded-lg flex items-center justify-center border border-primary/30">
            <span class="material-symbols-outlined text-primary text-xl">blur_on</span>
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
            <p class="text-xs text-gray-500 font-medium uppercase tracking-widest">Step <span id="step-number">1</span> of 3: Provider Details</p>
            
            <?php if (isset($_SESSION['application_error'])): ?>
                <div class="mt-4 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-500 text-sm font-bold flex items-center gap-3">
                    <span class="material-symbols-outlined">error</span> <?= htmlspecialchars($_SESSION['application_error']) ?>
                </div>
                <?php unset($_SESSION['application_error']); ?>
            <?php endif; ?>

            <div class="w-full bg-white/5 h-1.5 mt-8 rounded-full overflow-hidden">
                <div id="progress-bar" class="bg-primary h-full transition-all duration-500" style="width: 33.33%"></div>
            </div>
        </div>

        <form id="multi-step-form" action="../action/submit_application.php" method="POST" enctype="multipart/form-data" class="space-y-6">
            
            <div class="step-container" data-step="1">
                <div class="dashboard-window rounded-2xl p-8 md:p-10 relative overflow-hidden">
                    <div class="absolute -top-24 -right-24 w-48 h-48 bg-primary/10 blur-[60px] rounded-full pointer-events-none"></div>
                    <div class="relative z-10">
                        <div class="flex items-center gap-4 mb-8">
                            <span class="size-8 rounded-lg bg-primary/20 text-primary flex items-center justify-center font-bold text-sm border border-primary/30">1</span>
                            <h3 class="text-lg font-display font-black text-white uppercase italic tracking-tight">Owner Account Details</h3>
                        </div>
                        
                        <p class="text-[10px] text-gray-500 font-bold uppercase tracking-widest mb-8">Primary Administrative Credentials</p>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-5">
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">First Name</label>
                            <input type="text" name="first_name" required placeholder="e.g. Juan" autocomplete="given-name" class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Middle Name (Optional)</label>
                            <input type="text" name="middle_name" placeholder="e.g. Santos" autocomplete="additional-name" class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Last Name</label>
                            <input type="text" name="last_name" required placeholder="e.g. Dela Cruz" autocomplete="family-name" class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-5">
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Personal Email Address</label>
                            <input type="email" name="owner_email" required placeholder="e.g. name@example.com" autocomplete="email" class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Personal Contact Number</label>
                            <input type="text" name="owner_contact" id="owner_contact" required placeholder="09123456789" maxlength="11" pattern="09\d{9}" inputmode="numeric" autocomplete="tel" class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none numeric-only">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-5">
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Date of Birth</label>
                            <input type="date" name="owner_dob" required max="<?php echo date('Y-m-d'); ?>" class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Sex</label>
                            <select name="owner_sex" required class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none cursor-pointer appearance-none">
                                <option value="">Select Sex</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                    </div>

                    <div class="space-y-1.5 mb-5">
                        <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Username (For Login)</label>
                        <input type="text" name="username" required placeholder="e.g. juan_owner2026" autocomplete="username" class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none">
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Password</label>
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
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Confirm Password</label>
                            <div class="relative group">
                                <input type="password" id="reg-confirm-password" name="confirm_password" required autocomplete="new-password" class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md pl-4 pr-12 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none">
                                <button type="button" onclick="togglePasswordVisibility('reg-confirm-password', 'eye-icon-confirm')" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-primary transition-colors">
                                    <span id="eye-icon-confirm" class="material-symbols-outlined text-[20px]">visibility</span>
                                </button>
                            </div>
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
                <div class="dashboard-window rounded-2xl p-8 md:p-10 relative overflow-hidden">
                    <div class="absolute -top-24 -right-24 w-48 h-48 bg-primary/10 blur-[60px] rounded-full pointer-events-none"></div>
                    <div class="relative z-10">
                        <div class="flex items-center gap-4 mb-8">
                            <span class="size-8 rounded-lg bg-primary/20 text-primary flex items-center justify-center font-bold text-sm border border-primary/30">2</span>
                            <h3 class="text-lg font-display font-black text-white uppercase italic tracking-tight">Business Profile & Location</h3>
                        </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-6">
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Gym / Brand Name</label>
                            <input type="text" name="gym_name" required placeholder="e.g. Iron Forge Gym" class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Registered Business Name</label>
                            <input type="text" name="business_name" required placeholder="e.g. Iron Forge Fitness Inc." class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Gym Official Email</label>
                            <input type="email" name="gym_email" required placeholder="gym@example.com" class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Gym Contact Number</label>
                            <input type="text" name="gym_contact" id="gym_contact" required placeholder="09123456789" maxlength="11" pattern="09\d{9}" inputmode="numeric" class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none numeric-only">
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
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Street Address</label>
                            <input type="text" name="gym_address_line" placeholder="Unit No., Street Name" required class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest ml-1">Region</label>
                            <input type="text" name="region" placeholder="e.g. Central Luzon" required class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest ml-1">Province</label>
                            <input type="text" name="province" placeholder="e.g. Bulacan" required class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest ml-1">City / Municipality</label>
                            <input type="text" name="city" placeholder="e.g. Baliwag" required class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest ml-1">Barangay</label>
                            <input type="text" name="barangay" placeholder="e.g. Bagong Nayon" required class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none">
                        </div>
                    </div>
                    </div>
                </div>
            </div>

            <div class="step-container step-hidden" data-step="3">
                <div class="dashboard-window rounded-2xl p-8 md:p-10 relative overflow-hidden">
                    <div class="absolute -top-24 -right-24 w-48 h-48 bg-primary/10 blur-[60px] rounded-full pointer-events-none"></div>
                    <div class="relative z-10">
                        <div class="flex items-center gap-4 mb-8">
                            <span class="size-8 rounded-lg bg-primary/20 text-primary flex items-center justify-center font-bold text-sm border border-primary/30">3</span>
                            <h3 class="text-lg font-display font-black text-white uppercase italic tracking-tight">Legal & Financial Verification</h3>
                        </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-6">
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Valid ID Type</label>
                            <select name="owner_valid_id_type" required class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none cursor-pointer appearance-none">
                                <option value="">Select ID Type</option>
                                <option value="passport">Passport</option>
                                <option value="drivers_license">Driver's License</option>
                                <option value="national_id">National ID</option>
                                <option value="tin_id">TIN ID</option>
                            </select>
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Upload Valid ID</label>
                            <input type="file" name="owner_valid_id_file" required class="w-full text-sm text-gray-400 file:mr-4 file:py-2.5 file:px-5 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-white/10 file:text-white hover:file:bg-white/20 file:transition-all file:cursor-pointer">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">BIR Number (TIN)</label>
                            <input type="text" name="bir_number" id="bir_number" required placeholder="e.g. 123456789000" maxlength="12" pattern="\d{9,12}" inputmode="numeric" class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none numeric-only">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">BIR Document (0605 / COR)</label>
                            <input type="file" name="bir_document" required class="w-full text-sm text-gray-400 file:mr-4 file:py-2.5 file:px-5 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-white/10 file:text-white hover:file:bg-white/20 file:transition-all file:cursor-pointer">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Business Permit No.</label>
                            <input type="text" name="business_permit_no" id="business_permit_no" required placeholder="e.g. B-2026-12345" maxlength="50" class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Mayor's Permit (File)</label>
                            <input type="file" name="business_permit" required class="w-full text-sm text-gray-400 file:mr-4 file:py-2.5 file:px-5 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-white/10 file:text-white hover:file:bg-white/20 file:transition-all file:cursor-pointer">
                        </div>
                    </div>

                    <hr class="border-white/5 my-6">

                    <h4 class="text-xs font-black uppercase text-primary tracking-wider mb-4">Payout Information</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-5">
                        <div class="space-y-1.5 md:col-span-2">
                            <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest ml-1">Bank Name</label>
                            <input type="text" name="bank_name" placeholder="e.g. BDO, BPI, GCash" class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 transition-all outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest ml-1">Account Name</label>
                            <input type="text" name="account_name" placeholder="Juan Dela Cruz" class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 transition-all outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest ml-1">Account Number</label>
                            <input type="text" name="account_number" id="account_number" placeholder="e.g. 1234567890" maxlength="20" class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 transition-all outline-none numeric-only">
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Platform Fee Preference</label>
                        <div class="flex flex-col sm:flex-row gap-4">
                            <label class="flex-1 border border-white/10 bg-white/5 backdrop-blur-md p-3.5 rounded-xl cursor-pointer hover:bg-white/10 hover:border-primary/50 transition-all flex items-center">
                                <input type="radio" name="platform_fee_preference" value="deduct_from_payout" checked class="text-primary bg-transparent border-white/20 focus:ring-primary focus:ring-offset-0">
                                <span class="ml-3 text-sm font-semibold">Deduct from Payout</span>
                            </label>
                            <label class="flex-1 border border-white/10 bg-white/5 backdrop-blur-md p-3.5 rounded-xl cursor-pointer hover:bg-white/10 hover:border-primary/50 transition-all flex items-center">
                                <input type="radio" name="platform_fee_preference" value="bill_separately" class="text-primary bg-transparent border-white/20 focus:ring-primary focus:ring-offset-0">
                                <span class="ml-3 text-sm font-semibold">Bill Separately</span>
                            </label>
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

    function updateUI() {
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

    // --- NEW VALIDATION CODE START ---
    // Enforce numeric-only input for specific fields
    const numericInputs = document.querySelectorAll('.numeric-only');
    numericInputs.forEach(input => {
        input.addEventListener('input', (e) => {
            e.target.value = e.target.value.replace(/[^0-9]/g, '');
        });
    });

    // Real-time password match feedback
    const passwordInput = document.getElementById('reg-password');
    const confirmPasswordInput = document.getElementById('reg-confirm-password');
    const errorText = document.getElementById('password-error');

    const validatePasswords = () => {
        if (confirmPasswordInput.value && passwordInput.value !== confirmPasswordInput.value) {
            errorText.classList.remove('hidden');
        } else {
            errorText.classList.add('hidden');
        }
    };

    passwordInput.addEventListener('input', validatePasswords);
    confirmPasswordInput.addEventListener('input', validatePasswords);

    // Password Strength Indicator Logic
    passwordInput.addEventListener('input', function() {
        const password = this.value;
        const strengthLabel = document.getElementById('strength-label');
        const bars = [
            document.getElementById('strength-bar-1'),
            document.getElementById('strength-bar-2'),
            document.getElementById('strength-bar-3'),
            document.getElementById('strength-bar-4')
        ];

        let strength = 0;
        if (password.length >= 8) strength++;
        if (/[A-Z]/.test(password)) strength++;
        if (/[0-9]/.test(password)) strength++;
        if (/[^A-Za-z0-9]/.test(password)) strength++;

        // Reset bars
        bars.forEach(bar => {
            bar.className = 'flex-1 rounded-full bg-white/5 transition-colors';
        });

        const colors = ['bg-red-500', 'bg-orange-500', 'bg-yellow-500', 'bg-green-500'];
        const labels = ['Weak', 'Fair', 'Good', 'Strong'];

        if (password.length > 0) {
            for (let i = 0; i < strength; i++) {
                bars[i].className = 'flex-1 rounded-full ' + colors[strength - 1] + ' transition-colors';
            }
            strengthLabel.textContent = labels[strength - 1];
            strengthLabel.className = colors[strength - 1].replace('bg-', 'text-');
        } else {
            strengthLabel.textContent = 'None';
            strengthLabel.className = 'text-gray-500';
        }
    });

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
            if (passwordInput.value !== confirmPasswordInput.value) {
                errorText.classList.remove('hidden');
                confirmPasswordInput.focus();
                return;
            }
            
            const contact = document.getElementById('owner_contact').value;
            if (contact.length !== 11 || !contact.startsWith('09')) {
                alert('Please enter a valid Philippine mobile number (e.g., 09123456789)');
                document.getElementById('owner_contact').focus();
                return;
            }
        }

        // Additional validation for Step 2
        if (currentStep === 2) {
            const contact = document.getElementById('gym_contact').value;
            if (contact.length !== 11 || !contact.startsWith('09')) {
                alert('Please enter a valid Philippine mobile number for the gym (e.g., 09123456789)');
                document.getElementById('gym_contact').focus();
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
            
            if (key === 'platform_fee_preference') {
                const radio = document.querySelector(`input[name="${key}"][value="${formData[key]}"]`);
                if (radio) radio.checked = true;
                return;
            }

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

    form.addEventListener('submit', () => {
        submitBtn.disabled = true;
        submitBtn.innerHTML = `Processing... <span class="material-symbols-outlined text-lg animate-spin">refresh</span>`;
    });
</script>

</body>
</html>