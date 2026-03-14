<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Gym Onboarding | Horizon Systems</title>

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
                },
                fontFamily: { "display": ["Lexend", "sans-serif"] },
            },
        },
    }
    </script>
    <style>
        .step-hidden { display: none; }
        
        /* Custom styling for dropdowns to keep them dark */
        select option {
            background-color: #121017;
            color: white;
            padding: 10px;
        }

        /* Forces the time picker popup and clock icon to respect dark mode */
        input[type="time"] {
            color-scheme: dark;
        }
        
        /* Smooths out the clock icon hover effect */
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

<body class="bg-background-dark text-white font-display min-h-screen antialiased flex flex-col">

<div class="fixed inset-0 z-0">
    <div class="absolute inset-0 bg-gradient-to-br from-primary/5 via-background-dark to-background-dark"></div>
</div>

<header class="relative z-20 w-full px-8 py-6 flex justify-between items-center border-b border-white/5 bg-background-dark/50 backdrop-blur-md">
    <div class="flex items-center gap-3">
         <span class="material-symbols-outlined text-primary text-2xl">wb_twilight</span>
         <span class="text-xl font-black tracking-tight uppercase italic text-white">Horizon <span class="text-primary">Partners</span></span>
    </div>
    <div class="flex items-center gap-6">
        <img src="https://images.unsplash.com/photo-1534438327276-14e5300c3a48?auto=format&fit=crop&q=80&w=100" alt="Gym" class="size-10 rounded-full object-cover border border-primary/50 shadow-lg shadow-primary/20">
        <a href="../login.php" class="text-xs font-bold uppercase tracking-widest text-gray-400 hover:text-white transition-colors">Back to Login</a>
    </div>
</header>

<main class="relative z-10 flex-1 flex flex-col items-center py-10 px-4">
    <div class="w-full max-w-4xl">
        <div class="mb-8">
            <h1 class="text-3xl font-black tracking-tight uppercase italic">Apply for <span class="text-primary">Partnership</span></h1>
            <p class="text-gray-400 mt-2 text-sm">Step <span id="step-number">1</span> of 3: Fill in your details below.</p>
            
            <div class="w-full bg-white/5 h-1.5 mt-5 rounded-full overflow-hidden">
                <div id="progress-bar" class="bg-primary h-full transition-all duration-500" style="width: 33.33%"></div>
            </div>
        </div>

        <form id="multi-step-form" action="process_application.php" method="POST" enctype="multipart/form-data" class="space-y-6">
            
            <div class="step-container" data-step="1">
                <div class="bg-card-dark/40 border border-white/10 rounded-3xl p-8 shadow-2xl backdrop-blur-2xl">
                    <div class="flex items-center gap-4 mb-6">
                        <span class="size-8 rounded-full bg-primary/20 text-primary flex items-center justify-center font-bold text-sm">1</span>
                        <h3 class="text-lg font-bold uppercase italic tracking-tight">Business Profile & Location</h3>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-6">
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Gym Name</label>
                            <input type="text" name="gym_name" required class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none">
                        </div>
                        <div class="space-y-1.5">
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

            <div class="step-container step-hidden" data-step="2">
                <div class="bg-card-dark/40 border border-white/10 rounded-3xl p-8 shadow-2xl backdrop-blur-2xl">
                    <div class="flex items-center gap-4 mb-6">
                        <span class="size-8 rounded-full bg-primary/20 text-primary flex items-center justify-center font-bold text-sm">2</span>
                        <h3 class="text-lg font-bold uppercase italic tracking-tight">Legal & Financial Verification</h3>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-6">
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Valid ID Type</label>
                            <select name="owner_valid_id_type" class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none cursor-pointer appearance-none">
                                <option value="">Select ID Type</option>
                                <option value="passport">Passport</option>
                                <option value="drivers_license">Driver's License</option>
                                <option value="national_id">National ID</option>
                                <option value="tin_id">TIN ID</option>
                            </select>
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Upload Valid ID</label>
                            <input type="file" name="owner_valid_id_file" class="w-full text-sm text-gray-400 file:mr-4 file:py-2.5 file:px-5 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-white/10 file:text-white hover:file:bg-white/20 file:transition-all file:cursor-pointer">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">BIR Number (TIN)</label>
                            <input type="text" name="bir_number" required class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">BIR Document (0605 / COR)</label>
                            <input type="file" name="bir_document" class="w-full text-sm text-gray-400 file:mr-4 file:py-2.5 file:px-5 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-white/10 file:text-white hover:file:bg-white/20 file:transition-all file:cursor-pointer">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Business Permit No.</label>
                            <input type="text" name="business_permit_no" required placeholder="Enter permit ref" class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Mayor's Permit (File)</label>
                            <input type="file" name="business_permit" required class="w-full text-sm text-gray-400 file:mr-4 file:py-2.5 file:px-5 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-white/10 file:text-white hover:file:bg-white/20 file:transition-all file:cursor-pointer">
                        </div>
                    </div>

                    <hr class="border-white/5 my-6">

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-5">
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest ml-1">Bank Name</label>
                            <input type="text" name="bank_name" class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 transition-all outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest ml-1">Account Name</label>
                            <input type="text" name="account_name" class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 transition-all outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest ml-1">Account Number</label>
                            <input type="text" name="account_number" class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 transition-all outline-none">
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

            <div class="step-container step-hidden" data-step="3">
                <div class="bg-card-dark/40 border border-white/10 rounded-3xl p-8 shadow-2xl backdrop-blur-2xl">
                    <div class="flex items-center gap-4 mb-6">
                        <span class="size-8 rounded-full bg-primary/20 text-primary flex items-center justify-center font-bold text-sm">3</span>
                        <h3 class="text-lg font-bold uppercase italic tracking-tight">Facility Setup</h3>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-6">
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Opening Time</label>
                            <input type="time" name="opening_time" class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Closing Time</label>
                            <input type="time" name="closing_time" class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">Max Capacity</label>
                            <input type="number" name="max_capacity" min="1" oninput="if(this.value < 0) this.value = Math.abs(this.value);" placeholder="e.g. 50" class="w-full h-12 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md px-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                        <label class="flex items-center gap-3 p-3.5 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md cursor-pointer hover:bg-white/10 hover:border-primary/50 transition-all">
                            <input type="checkbox" name="has_lockers" value="1" class="rounded text-primary bg-transparent border-white/20 focus:ring-primary focus:ring-offset-0">
                            <span class="text-xs font-bold uppercase tracking-wide">Lockers</span>
                        </label>
                        <label class="flex items-center gap-3 p-3.5 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md cursor-pointer hover:bg-white/10 hover:border-primary/50 transition-all">
                            <input type="checkbox" name="has_shower" value="1" class="rounded text-primary bg-transparent border-white/20 focus:ring-primary focus:ring-offset-0">
                            <span class="text-xs font-bold uppercase tracking-wide">Showers</span>
                        </label>
                        <label class="flex items-center gap-3 p-3.5 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md cursor-pointer hover:bg-white/10 hover:border-primary/50 transition-all">
                            <input type="checkbox" name="has_parking" value="1" class="rounded text-primary bg-transparent border-white/20 focus:ring-primary focus:ring-offset-0">
                            <span class="text-xs font-bold uppercase tracking-wide">Parking</span>
                        </label>
                        <label class="flex items-center gap-3 p-3.5 rounded-xl border border-white/10 bg-white/5 backdrop-blur-md cursor-pointer hover:bg-white/10 hover:border-primary/50 transition-all">
                            <input type="checkbox" name="has_wifi" value="1" class="rounded text-primary bg-transparent border-white/20 focus:ring-primary focus:ring-offset-0">
                            <span class="text-xs font-bold uppercase tracking-wide">Wi-Fi</span>
                        </label>
                    </div>

                    <div class="space-y-5">
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">About the Gym</label>
                            <textarea name="about_text" rows="3" placeholder="Describe your gym's philosophy..." class="w-full rounded-xl border border-white/10 bg-white/5 backdrop-blur-md p-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none"></textarea>
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-400 ml-1">House Rules</label>
                            <textarea name="rules_text" rows="3" placeholder="e.g. Proper gym attire required..." class="w-full rounded-xl border border-white/10 bg-white/5 backdrop-blur-md p-4 text-sm text-white focus:bg-white/10 focus:border-primary/50 focus:ring-1 focus:ring-primary/50 transition-all outline-none"></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex gap-4 pt-2">
                <button type="button" id="prev-btn" class="hidden flex-1 h-12 rounded-xl bg-white/5 border border-white/10 backdrop-blur-md text-white font-semibold uppercase tracking-wider text-sm hover:bg-white/10 transition-all">
                    Previous
                </button>
                <button type="button" id="next-btn" class="flex-1 h-12 rounded-xl bg-primary hover:bg-primary-hover text-white font-bold uppercase tracking-wider text-sm transition-all shadow-lg shadow-primary/30 flex items-center justify-center gap-2">
                    Next Step
                    <span class="material-symbols-outlined text-lg">arrow_forward</span>
                </button>
                <button type="submit" id="submit-btn" class="hidden flex-1 h-12 rounded-xl bg-primary hover:bg-primary-hover text-white font-bold uppercase tracking-wider text-sm transition-all shadow-lg shadow-primary/30 flex items-center justify-center gap-2">
                    Submit Application
                    <span class="material-symbols-outlined text-lg">send</span>
                </button>
            </div>

        </form>
    </div>
</main>

<footer class="relative z-10 w-full py-8 text-center opacity-40 border-t border-white/5 bg-background-dark mt-auto">
    <span class="text-[10px] font-bold uppercase tracking-[0.3em] text-gray-500">Horizon Multi-Tenant Verification System © 2026</span>
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

    function updateUI() {
        steps.forEach(step => {
            if(parseInt(step.dataset.step) === currentStep) {
                step.classList.remove('step-hidden');
            } else {
                step.classList.add('step-hidden');
            }
        });

        // Update Progress Bar
        const progress = (currentStep / totalSteps) * 100;
        progressBar.style.width = `${progress}%`;
        stepNumberLabel.innerText = currentStep;

        // Button Visibility
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

    nextBtn.addEventListener('click', () => {
        if (currentStep < totalSteps) {
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
</script>

</body>
</html>