<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Herdoza Fitness - Register</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com"/>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&display=swap" rel="stylesheet"/>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />
    
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script id="tailwind-config">
    tailwind.config = {
        darkMode: "class",
        theme: {
            extend: {
                colors: {
                    "primary": "#7f0df2",
                    "primary-hover": "#6b0bcc",
                    "background-light": "#f7f5f8",
                    "background-dark": "#191022",
                    "surface-dark": "#211b27",
                    "surface-border": "#473b54",
                    "text-secondary": "#ab9cba",
                },
                fontFamily: { "display": ["Manrope", "sans-serif"] },
            },
        },
    }
    </script>
</head>

<body class="bg-background-dark font-display min-h-screen flex flex-col antialiased relative">

<div class="fixed inset-0 z-0">
    <img class="w-full h-full object-cover" src="https://images.unsplash.com/photo-1534438327276-14e5300c3a48?q=80&w=2070&auto=format&fit=crop" alt="Gym Background"/>
    <div class="absolute inset-0 bg-[#0a1a1a]/90 mix-blend-multiply"></div>
    <div class="absolute inset-0 bg-gradient-to-b from-transparent to-background-dark/80"></div>
</div>

<header class="flex items-center justify-between border-b border-surface-border bg-background-dark/95 px-6 lg:px-10 py-3 sticky top-0 z-50">
    <div class="flex items-center gap-4 text-white">
        <div class="size-8 text-primary">
            <svg fill="currentColor" viewBox="0 0 48 48"><path d="M24 4H6V30.6667H24V44H42V17.3333H24V4Z"></path></svg>
        </div>
        <h2 class="text-white text-xl font-bold">Herdoza Fitness</h2>
    </div>
    <div class="flex items-center gap-4">
        <span class="text-text-secondary text-sm hidden sm:inline">Already have an account?</span>
        <button onclick="window.location='login.php'" class="rounded-lg h-10 px-4 bg-primary hover:bg-primary-hover text-white text-sm font-bold">Login</button>
    </div>
</header>

<main class="flex-1 flex flex-col items-center justify-start p-6 lg:p-12 z-10">
    <div class="w-full max-w-[700px] bg-surface-dark/95 backdrop-blur-md rounded-2xl border border-surface-border p-8 lg:p-12 shadow-2xl my-10">
        
        <div class="text-center mb-10">
            <h2 class="text-white text-3xl font-black">Create Account</h2>
            <p class="text-text-secondary text-base mt-2">Join the family today. Please fill in your details below.</p>
        </div>

        <?php if (!empty($errors)): ?>
        <div class="bg-red-600/70 border border-red-500 text-white text-sm p-3 rounded-lg mb-6">
            <?php foreach ($errors as $e) echo "• $e<br>"; ?>
        </div>
        <?php endif; ?>

        <form class="flex flex-col gap-8" method="POST">
            
            <div class="space-y-4">
                <div class="flex items-center gap-2 text-primary">
                    <span class="material-symbols-outlined text-sm">manage_accounts</span>
                    <p class="text-xs font-bold uppercase tracking-wider">Account Details</p>
                </div>
                
                <div>
                    <label class="text-white text-sm font-semibold">Username <span class="text-red-500">*</span></label>
                    <input name="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" class="w-full h-12 rounded-lg bg-background-dark/50 border border-surface-border text-white pl-4 text-sm focus:border-primary focus:ring-1 focus:ring-primary outline-none transition-all" placeholder="Create username" required>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div class="relative">
                        <label class="text-white text-sm font-semibold">Password <span class="text-red-500">*</span></label>
                        <div class="relative">
                            <input id="password" name="password" type="password" class="w-full h-12 rounded-lg bg-background-dark/50 border border-surface-border text-white pl-4 pr-10 text-sm focus:border-primary focus:ring-1 focus:ring-primary outline-none transition-all" required>
                            <button type="button" onclick="togglePassword('password', 'eye-icon-1')" class="absolute right-3 top-1/2 -translate-y-1/2 text-text-secondary hover:text-white">
                                <span id="eye-icon-1" class="material-symbols-outlined text-xl">visibility</span>
                            </button>
                        </div>
                        <div class="mt-2 grid grid-cols-3 gap-1">
                            <div id="req-len" class="text-[10px] font-bold text-text-secondary uppercase flex items-center gap-1 transition-colors"><span class="material-symbols-outlined text-[12px]">circle</span> 8+ Chars</div>
                            <div id="req-cap" class="text-[10px] font-bold text-text-secondary uppercase flex items-center gap-1 transition-colors"><span class="material-symbols-outlined text-[12px]">circle</span> 1 Cap</div>
                            <div id="req-num" class="text-[10px] font-bold text-text-secondary uppercase flex items-center gap-1 transition-colors"><span class="material-symbols-outlined text-[12px]">circle</span> 1 Num</div>
                        </div>
                    </div>

                    <div class="relative">
                        <label class="text-white text-sm font-semibold">Confirm <span class="text-red-500">*</span></label>
                        <div class="relative">
                            <input id="confirm_password" name="confirm_password" type="password" class="w-full h-12 rounded-lg bg-background-dark/50 border border-surface-border text-white pl-4 pr-10 text-sm focus:border-primary focus:ring-1 focus:ring-primary outline-none transition-all" required>
                            <button type="button" onclick="togglePassword('confirm_password', 'eye-icon-2')" class="absolute right-3 top-1/2 -translate-y-1/2 text-text-secondary hover:text-white">
                                <span id="eye-icon-2" class="material-symbols-outlined text-xl">visibility</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <hr class="border-surface-border opacity-20">

            <div class="space-y-4">
                <div class="flex items-center gap-2 text-primary">
                    <span class="material-symbols-outlined text-sm">person</span>
                    <p class="text-xs font-bold uppercase tracking-wider">Personal Information</p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div>
                        <label class="text-white text-sm font-semibold">First Name <span class="text-red-500">*</span></label>
                        <input name="firstname" class="w-full h-12 rounded-lg bg-background-dark/50 border border-surface-border text-white px-4 text-sm focus:border-primary outline-none" placeholder="e.g. Jane" required>
                    </div>
                    <div>
                        <label class="text-white text-sm font-semibold">Last Name <span class="text-red-500">*</span></label>
                        <input name="lastname" class="w-full h-12 rounded-lg bg-background-dark/50 border border-surface-border text-white px-4 text-sm focus:border-primary outline-none" placeholder="e.g. Doe" required>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div>
                        <label class="text-white text-sm font-semibold">Date of Birth <span class="text-red-500">*</span></label>
                        <input type="date" name="dob" class="w-full h-12 rounded-lg bg-background-dark/50 border border-surface-border text-white px-4 text-sm focus:border-primary outline-none appearance-none" required>
                        <p class="text-[10px] text-text-secondary mt-1">Must be at least 12 years old.</p>
                    </div>
                    <div>
                        <label class="text-white text-sm font-semibold">Sex <span class="text-red-500">*</span></label>
                        <select name="sex" class="w-full h-12 rounded-lg bg-background-dark/50 border border-surface-border text-white px-4 text-sm focus:border-primary outline-none">
                            <option value="" disabled selected>Select</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                        </select>
                    </div>
                </div>
            </div>

            <hr class="border-surface-border opacity-20">

            <div class="space-y-4">
                <div class="flex items-center gap-2 text-primary">
                    <span class="material-symbols-outlined text-sm">contact_mail</span>
                    <p class="text-xs font-bold uppercase tracking-wider">Contact & Work Details</p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div>
                        <label class="text-white text-sm font-semibold">Email Address</label>
                        <input name="email" type="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" class="w-full h-12 rounded-lg bg-background-dark/50 border border-surface-border text-white px-4 text-sm focus:border-primary outline-none" placeholder="name@example.com" required>
                    </div>
                    <div>
                        <label class="text-white text-sm font-semibold">Phone Number</label>
                        <input name="phone" type="tel" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" class="w-full h-12 rounded-lg bg-background-dark/50 border border-surface-border text-white px-4 text-sm focus:border-primary outline-none" placeholder="09123456789" required>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div>
                        <label class="text-white text-sm font-semibold">Occupation</label>
                        <select name="occupation" class="w-full h-12 rounded-lg bg-background-dark/50 border border-surface-border text-white px-4 text-sm focus:border-primary outline-none">
                            <option value="student">Student</option>
                            <option value="employed">Employed (Full-time)</option>
                            <option value="freelance">Freelance / Type of work</option>
                            <option value="unemployed">Unemployed</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-white text-sm font-semibold">Referral Source</label>
                        <select name="referral" class="w-full h-12 rounded-lg bg-background-dark/50 border border-surface-border text-white px-4 text-sm focus:border-primary outline-none">
                            <option value="walk-in">Walk-in</option>
                            <option value="social-media">Social Media</option>
                            <option value="friend">Friend / Family</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="text-white text-sm font-semibold">Address</label>
                    <input name="address" class="w-full h-12 rounded-lg bg-background-dark/50 border border-surface-border text-white px-4 text-sm focus:border-primary outline-none" placeholder="St., Brgy., City" required>
                </div>
            </div>

            <hr class="border-surface-border opacity-20">

            <div class="space-y-4">
                <div class="flex items-center gap-2 text-primary">
                    <span class="material-symbols-outlined text-sm">health_and_safety</span>
                    <p class="text-xs font-bold uppercase tracking-wider">Health Information</p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                    <div>
                        <label class="text-white text-sm font-semibold">Medical History</label>
                        <select id="health-category" onchange="updateSubCategory()" class="w-full h-12 rounded-lg bg-background-dark/50 border border-surface-border text-white px-4 text-sm focus:border-primary outline-none">
                            <option value="none">N/A</option>
                            <option value="allergy">Allergy</option>
                            <option value="fracture">Fracture</option>
                            <option value="condition">Existing Condition</option>
                            <option value="others">Others</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-white text-sm font-semibold">General Detail</label>
                        <select id="health-details" onchange="updateSpecifics()" class="w-full h-12 rounded-lg bg-background-dark/50 border border-surface-border text-white px-4 text-sm focus:border-primary outline-none">
                            <option value="none">N/A</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-white text-sm font-semibold">Specific Detail</label>
                        <select id="health-specifics" onchange="checkOthers()" name="health_detail_final" class="w-full h-12 rounded-lg bg-background-dark/50 border border-surface-border text-white px-4 text-sm focus:border-primary outline-none">
                            <option value="none">N/A</option>
                        </select>
                    </div>
                </div>

                <div id="others-input-container" class="hidden">
                    <label class="text-white text-sm font-semibold">Please specify medical detail</label>
                    <input type="text" id="medical-others" name="medical_others" class="w-full h-12 rounded-lg bg-background-dark/50 border border-surface-border text-white px-4 text-sm focus:border-primary outline-none" placeholder="Type detail here...">
                </div>
            </div>

            <button type="submit" class="mt-4 flex w-full justify-center rounded-lg bg-primary py-4 text-sm font-bold text-white hover:bg-primary-hover transition-all shadow-xl shadow-primary/20 active:scale-[0.98]">
                Create Account
            </button>
        </form>

        <div class="flex justify-center gap-2 text-sm mt-8">
            <span class="text-text-secondary">Already a member?</span>
            <a class="font-bold text-primary hover:underline" href="login.php">Log in here</a>
        </div>
    </div>
</main>

<script>
function togglePassword(inputId, iconId) {
    const passwordInput = document.getElementById(inputId);
    const eyeIcon = document.getElementById(iconId);
    if (passwordInput.type === "password") {
        passwordInput.type = "text";
        eyeIcon.textContent = "visibility_off";
    } else {
        passwordInput.type = "password";
        eyeIcon.textContent = "visibility";
    }
}

const healthData = {
    'allergy': {
        'food_allergy': ['Peanuts', 'Seafood', 'Dairy', 'Eggs', 'Wheat'],
        'medicine_allergy': ['Penicillin', 'Aspirin', 'Sulfa Drugs'],
        'skin_allergy': ['Latex', 'Fragrance', 'Nickel'],
        'dust/pollen': []
    },
    'fracture': {
        'arm_fracture': ['Upper Arm', 'Forearm', 'Wrist'],
        'leg_fracture': ['Thigh', 'Shin', 'Ankle'],
        'wrist_fracture': [],
        'spinal_injury': []
    },
    'condition': {
        'asthma': [],
        'hypertension': [],
        'diabetes': ['Type 1', 'Type 2'],
        'heart_condition': []
    }
};

function updateSubCategory() {
    const category = document.getElementById('health-category').value;
    const details = document.getElementById('health-details');
    const specifics = document.getElementById('health-specifics');
    
    details.innerHTML = '<option value="none">N/A</option>';
    specifics.innerHTML = '<option value="none">N/A</option>';

    if (category === 'others') {
        details.innerHTML = '<option value="others">Others</option>';
        specifics.innerHTML = '<option value="others">Others</option>';
    } else if (category && healthData[category]) {
        Object.keys(healthData[category]).forEach(key => {
            let opt = document.createElement('option');
            opt.value = key;
            opt.innerHTML = key.replace(/_/g, " ").replace(/\b\w/g, l => l.toUpperCase());
            details.appendChild(opt);
        });
        details.innerHTML += '<option value="others">Others</option>';
    }
    checkOthers();
}

function updateSpecifics() {
    const category = document.getElementById('health-category').value;
    const detail = document.getElementById('health-details').value;
    const specifics = document.getElementById('health-specifics');
    
    specifics.innerHTML = '<option value="none">N/A</option>';

    if (detail === 'others') {
        specifics.innerHTML = '<option value="others">Others</option>';
    } else if (category && detail && healthData[category][detail] && healthData[category][detail].length > 0) {
        healthData[category][detail].forEach(item => {
            let opt = document.createElement('option');
            opt.value = item.toLowerCase().replace(/ /g, "_");
            opt.innerHTML = item;
            specifics.appendChild(opt);
        });
        specifics.innerHTML += '<option value="others">Others</option>';
    } else if (category && detail) {
        specifics.innerHTML = '<option value="none">Not Applicable</option><option value="others">Others</option>';
    }
    checkOthers();
}

function checkOthers() {
    const category = document.getElementById('health-category').value;
    const detail = document.getElementById('health-details').value;
    const specific = document.getElementById('health-specifics').value;
    const container = document.getElementById('others-input-container');
    const input = document.getElementById('medical-others');

    if (category === 'others' || detail === 'others' || specific === 'others') {
        container.classList.remove('hidden');
        input.setAttribute('required', 'required');
    } else {
        container.classList.add('hidden');
        input.removeAttribute('required');
    }
}

const passInput = document.getElementById('password');
const reqLen = document.getElementById('req-len');
const reqCap = document.getElementById('req-cap');
const reqNum = document.getElementById('req-num');

passInput.addEventListener('input', function() {
    const val = passInput.value;
    val.length >= 8 ? setValid(reqLen) : setInvalid(reqLen);
    /[A-Z]/.test(val) ? setValid(reqCap) : setInvalid(reqCap);
    /[0-9]/.test(val) ? setValid(reqNum) : setInvalid(reqNum);
});

function setValid(el) {
    el.classList.remove('text-text-secondary');
    el.classList.add('text-emerald-400');
    el.querySelector('span').textContent = 'check_circle';
}
function setInvalid(el) {
    el.classList.remove('text-emerald-400');
    el.classList.add('text-text-secondary');
    el.querySelector('span').textContent = 'circle';
}
</script>

</body>
</html>