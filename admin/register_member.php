<?php 
session_start(); 
require_once '../db.php'; 
require_once '../includes/mailer.php'; 

// Security Check: Only Staff
$role = strtolower($_SESSION['role'] ?? ''); 
if (!isset($_SESSION['user_id']) || ($role !== 'staff' && $role !== 'coach')) { 
    header("Location: ../login.php"); 
    exit; 
} 

$adminName = $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];

require_once '../includes/member_processor.php'; 

$gym_id = $_SESSION['gym_id']; 
$success = ''; 
$error = ''; 

if ($_SERVER['REQUEST_METHOD'] === 'POST') { 
    try { 
        $registration_data = array_merge($_POST, [ 
            'gym_id' => $gym_id, 
            'registration_source' => 'Walk-in', 
            'registered_by_user_id' => $_SESSION['user_id'] 
        ]); 

        $result = processMemberRegistration($pdo, $registration_data); 
        $success = "Member registered successfully! Credentials have been sent to their email."; 
        $_POST = []; 
    } catch (Exception $e) { 
        $error = $e->getMessage(); 
    } 
} 

// Fetch Gym Details 
$stmtGym = $pdo->prepare("SELECT * FROM gyms WHERE gym_id = ?"); 
$stmtGym->execute([$gym_id]); 
$gym = $stmtGym->fetch(); 

// Fetch branding for sidebar 
$stmtPage = $pdo->prepare("SELECT * FROM tenant_pages WHERE gym_id = ? LIMIT 1"); 
$stmtPage->execute([$gym_id]); 
$page = $stmtPage->fetch(); 

$stmtSub = $pdo->prepare("SELECT ws.plan_name FROM client_subscriptions cs JOIN website_plans ws ON cs.website_plan_id = ws.website_plan_id WHERE cs.gym_id = ? AND cs.subscription_status = 'Active' LIMIT 1"); 
$stmtSub->execute([$gym_id]); 
$sub = $stmtSub->fetch(); 

$active_page = "register_member"; 
?> 
<!DOCTYPE html> 
<html class="dark" lang="en"> 
<head> 
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/> 
    <title>Walk-in Registration | Horizon Partners</title> 
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/> 
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/> 
    <script src="https://cdn.tailwindcss.com"></script> 
    <script> 
        tailwind.config = { 
            darkMode: "class", 
            theme: { extend: { colors: { "primary": "<?= $page['theme_color'] ?? '#8c2bee' ?>", "background-dark": "<?= $page['bg_color'] ?? '#0a090d' ?>", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)"}}} 
        } 
    </script> 
    <style> 
        body { font-family: 'Lexend', sans-serif; background-color: <?= $page['bg_color'] ?? '#0a090d' ?>; color: white; overflow: hidden; } 
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; } 
        .input-field { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; color: white; padding: 14px 18px; width: 100%; outline: none; transition: all 0.2s; font-size: 13px; font-weight: 500; color-scheme: dark; } 
        .input-field:focus { border-color: <?= $page['theme_color'] ?? '#8c2bee' ?>; background: rgba(255,255,255,0.08); } 
        .input-field option { background-color: #1a1821; color: white; }
        ::-webkit-calendar-picker-indicator { filter: invert(1) brightness(0.8); opacity: 0.6; cursor: pointer; }
        .strength-bar { height: 4px; border-radius: 2px; transition: all 0.3s ease; width: 0; }
        .strength-weak { background-color: #ef4444; width: 33.33%; }
        .strength-medium { background-color: #f59e0b; width: 66.66%; }
        .strength-strong { background-color: #10b981; width: 100%; }
        
        .side-nav { width: 110px; transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1); overflow: hidden; display: flex; flex-direction: column; position: fixed; left: 0; top: 0; height: 100vh; z-index: 50; }
        .side-nav:hover { width: 300px; }
        .main-content { margin-left: 110px; flex: 1; min-width: 0; transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1); }
        .side-nav:hover ~ .main-content { margin-left: 300px; }

        .nav-label { opacity: 0; transform: translateX(-15px); transition: all 0.3s ease-in-out; white-space: nowrap; pointer-events: none; }
        .side-nav:hover .nav-label { opacity: 1; transform: translateX(0); pointer-events: auto; }
        .nav-section-label { max-height: 0; opacity: 0; overflow: hidden; transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); margin: 0 !important; pointer-events: none; }
        .side-nav:hover .nav-section-label { max-height: 20px; opacity: 1; margin-bottom: 8px !important; pointer-events: auto; }
        
        .nav-item { display: flex; align-items: center; gap: 16px; padding: 10px 38px; transition: all 0.2s ease; text-decoration: none; white-space: nowrap; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; }
        .nav-item:hover { background: rgba(255,255,255,0.05); color: white; }
        .nav-item.active { color: <?= $page['theme_color'] ?? '#8c2bee' ?> !important; position: relative; }
        .nav-item.active::after { content: ''; position: absolute; right: 0px; top: 50%; transform: translateY(-50%); width: 4px; height: 24px; background: <?= $page['theme_color'] ?? '#8c2bee' ?>; border-radius: 4px 0 0 4px; }
        
        .no-scrollbar::-webkit-scrollbar { display: none; } 
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; } 
    </style> 
    <script> 
        function updateHeaderClock() { 
            const now = new Date(); 
            const clockEl = document.getElementById('topClock'); 
            if (clockEl) { 
                clockEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' }); 
            } 
        } 
        setInterval(updateHeaderClock, 1000); 
        window.addEventListener('DOMContentLoaded', updateHeaderClock); 

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
            if (/[a-z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^A-Za-z0-9]/.test(password)) strength++;
            indicator.className = 'strength-bar';
            if (password.length === 0) text.textContent = '';
            else if (strength <= 2) { indicator.classList.add('strength-weak'); text.textContent = 'Weak'; text.className = 'text-[9px] font-black uppercase text-red-500 mt-1'; }
            else if (strength <= 4) { indicator.classList.add('strength-medium'); text.textContent = 'Medium'; text.className = 'text-[9px] font-black uppercase text-amber-500 mt-1'; }
            else { indicator.classList.add('strength-strong'); text.textContent = 'Strong'; text.className = 'text-[9px] font-black uppercase text-emerald-500 mt-1'; }
        }

        function validateForm(event) {
            const password = document.getElementById('password').value;
            const confirm = document.getElementById('confirm_password').value;
            if (password !== confirm) {
                alert('Passwords do not match!');
                event.preventDefault();
                return false;
            }
            return true;
        }
    </script> 
</head> 
<body class="antialiased flex h-screen overflow-hidden"> 

<nav class="side-nav flex flex-col fixed left-0 top-0 h-screen bg-background-dark border-r border-white/5 z-50">
    <div class="px-7 py-8 mb-4 shrink-0">
        <div class="flex items-center gap-4">
            <div class="size-10 rounded-xl bg-primary flex items-center justify-center shadow-lg shrink-0 overflow-hidden">
                <?php if (!empty($page['logo_path'])): 
                    $logo_src = (strpos($page['logo_path'], 'data:image') === 0) ? $page['logo_path'] : '../' . $page['logo_path'];
                ?>
                    <img src="<?= $logo_src ?>" class="size-full object-contain">
                <?php else: ?>
                    <span class="material-symbols-outlined text-white text-2xl">bolt</span>
                <?php endif; ?>
            </div>
            <h1 class="nav-label text-lg font-black italic uppercase tracking-tighter text-white leading-tight">Staff Portal</h1>
        </div>
    </div>
    <div class="flex-1 overflow-y-auto no-scrollbar space-y-1">
        <div class="nav-section-label px-[38px] mb-2"><span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Overview</span></div>
        <a href="admin_dashboard.php" class="nav-item"><span class="material-symbols-outlined text-xl shrink-0">grid_view</span><span class="nav-label">Dashboard</span></a>
        <a href="register_member.php" class="nav-item active"><span class="material-symbols-outlined text-xl shrink-0">person_add</span><span class="nav-label">Walk-in Member</span></a>
        <div class="nav-section-label px-[38px] mb-2 mt-6"><span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Management</span></div>
        <a href="admin_users.php" class="nav-item text-gray-400 hover:text-white"><span class="material-symbols-outlined text-xl shrink-0">group</span><span class="nav-label">My Users</span></a>
        <a href="admin_transaction.php" class="nav-item text-gray-400 hover:text-white"><span class="material-symbols-outlined text-xl shrink-0">receipt_long</span><span class="nav-label">Transactions</span></a>
        <a href="admin_appointment.php" class="nav-item text-gray-400 hover:text-white"><span class="material-symbols-outlined text-xl shrink-0">event_note</span><span class="nav-label">Bookings</span></a>
        <a href="admin_attendance.php" class="nav-item text-gray-400 hover:text-white"><span class="material-symbols-outlined text-xl shrink-0">history</span><span class="nav-label">Attendance</span></a>
    </div>
    <div class="mt-auto pt-4 border-t border-white/10 shrink-0 pb-6">
        <div class="nav-section-label px-[38px] mb-2"><span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Account</span></div>
        <a href="admin_profile.php" class="nav-item text-gray-400 hover:text-white"><span class="material-symbols-outlined text-xl shrink-0">account_circle</span><span class="nav-label">Profile</span></a>
        <a href="../logout.php" class="nav-item text-gray-400 hover:text-rose-500 transition-colors">
            <span class="material-symbols-outlined text-xl shrink-0">logout</span>
            <span class="nav-label whitespace-nowrap">Sign Out</span>
        </a>
    </div>
</nav>

<div class="main-content flex-1 overflow-y-auto no-scrollbar">
    <main class="p-10 max-w-[1400px] mx-auto">
        <header class="mb-12 flex flex-row justify-between items-end gap-6">
            <div>
                <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white leading-none tracking-tight">Walk-in <span class="text-primary">Registration</span></h2>
                <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-2 px-1 opacity-60">Staff Portal • New Member Entry</p>
            </div>
            <div class="flex items-end gap-8 text-right shrink-0">
                <div class="flex flex-col items-end">
                    <p id="topClock" class="text-white font-black italic text-2xl leading-none tracking-tighter">00:00:00 AM</p>
                    <p class="text-primary text-[10px] font-black uppercase tracking-[0.2em] leading-none mt-2"><?= date('l, M d, Y') ?></p>
                </div>
            </div>
        </header>

        <div class="max-w-4xl mx-auto">
            <?php if($success): ?> 
                <div class="mb-8 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-500 text-xs font-bold flex items-center gap-3 shadow-lg"> 
                    <span class="material-symbols-outlined text-lg">check_circle</span> <?= $success ?> 
                </div> 
            <?php endif; ?> 

            <?php if($error): ?> 
                <div class="mb-8 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-xs font-bold flex items-center gap-3 shadow-lg"> 
                    <span class="material-symbols-outlined text-lg">error</span> <?= $error ?> 
                </div> 
            <?php endif; ?> 

            <form method="POST" onsubmit="return validateForm(event)" class="space-y-8 pb-20"> 
                <div class="glass-card p-8 border border-white/5">
                    <h4 class="text-base font-black italic uppercase tracking-tighter mb-8 flex items-center gap-3 text-primary">
                        <span class="material-symbols-outlined bg-primary/10 p-2 rounded-xl text-xl">person</span> Personal Information
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-x-8 gap-y-6">
                        <div class="space-y-2 lg:col-span-1">
                            <label class="text-[11px] font-black uppercase text-gray-500 tracking-widest ml-1">Username <span class="text-red-500">*</span></label>
                            <input type="text" name="username" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" class="input-field" placeholder="Username">
                        </div>
                        <div class="space-y-2 lg:col-span-1">
                            <label class="text-[11px] font-black uppercase text-gray-500 tracking-widest ml-1">First Name <span class="text-red-500">*</span></label>
                            <input type="text" name="first_name" required value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>" class="input-field" placeholder="First Name">
                        </div>
                        <div class="space-y-2 lg:col-span-1">
                            <label class="text-[11px] font-black uppercase text-gray-500 tracking-widest ml-1">Middle Name</label>
                            <input type="text" name="middle_name" value="<?= htmlspecialchars($_POST['middle_name'] ?? '') ?>" class="input-field" placeholder="Middle Name">
                        </div>
                        <div class="space-y-2 lg:col-span-1">
                            <label class="text-[11px] font-black uppercase text-gray-500 tracking-widest ml-1">Last Name <span class="text-red-500">*</span></label>
                            <input type="text" name="last_name" required value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>" class="input-field" placeholder="Last Name">
                        </div>
                        <div class="space-y-2 md:col-span-1">
                            <label class="text-[11px] font-black uppercase text-gray-500 tracking-widest ml-1">Sex <span class="text-red-500">*</span></label>
                            <select name="sex" required class="input-field appearance-none cursor-pointer">
                                <option value="" disabled <?= !isset($_POST['sex']) ? 'selected' : '' ?>>Select Sex</option>
                                <option value="Male" <?= ($_POST['sex'] ?? '') === 'Male' ? 'selected' : '' ?>>Male</option>
                                <option value="Female" <?= ($_POST['sex'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
                            </select>
                        </div>
                        <div class="space-y-2 md:col-span-1">
                            <label class="text-[11px] font-black uppercase text-gray-500 tracking-widest ml-1">Birth Date <span class="text-red-500">*</span></label>
                            <input type="date" name="birth_date" required value="<?= htmlspecialchars($_POST['birth_date'] ?? '') ?>" class="input-field">
                        </div>
                        <div class="space-y-2 md:col-span-2">
                            <label class="text-[11px] font-black uppercase text-gray-500 tracking-widest ml-1">Password <span class="text-red-500">*</span></label>
                            <div class="relative">
                                <input type="password" name="password" id="password" required onkeyup="checkPasswordStrength(this.value)" class="input-field" placeholder="Security Password">
                                <button type="button" onclick="togglePassword('password', this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-white transition-all"><span class="material-symbols-outlined text-sm">visibility</span></button>
                            </div>
                            <div class="w-full bg-white/5 h-1 rounded-full mt-2 overflow-hidden"><div id="strength-indicator" class="strength-bar"></div></div>
                            <p id="strength-text" class="text-[9px] font-black ml-1"></p>
                        </div>
                        <div class="space-y-2 md:col-span-2 md:col-start-3">
                            <label class="text-[11px] font-black uppercase text-gray-500 tracking-widest ml-1">Confirm Password <span class="text-red-500">*</span></label>
                            <div class="relative">
                                <input type="password" name="confirm_password" id="confirm_password" required class="input-field" placeholder="Re-type Password">
                                <button type="button" onclick="togglePassword('confirm_password', this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-white transition-all"><span class="material-symbols-outlined text-sm">visibility</span></button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="glass-card p-8 border border-white/5">
                    <h4 class="text-base font-black italic uppercase tracking-tighter mb-8 flex items-center gap-3 text-primary">
                        <span class="material-symbols-outlined bg-primary/10 p-2 rounded-xl text-xl">alternate_email</span> Contact Information
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                        <div class="space-y-2">
                            <label class="text-[11px] font-black uppercase text-gray-500 tracking-widest ml-1">Email Address <span class="text-red-500">*</span></label>
                            <input type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" class="input-field" placeholder="email@address.com">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[11px] font-black uppercase text-gray-500 tracking-widest ml-1">Contact Number <span class="text-red-500">*</span></label>
                            <input type="tel" name="phone" required oninput="formatPhoneNumber(this)" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" class="input-field" placeholder="09XX-XXX-XXXX">
                        </div>
                        <div class="space-y-2 md:col-span-2">
                            <label class="text-[11px] font-black uppercase text-gray-500 tracking-widest ml-1">Home Address <span class="text-red-500">*</span></label>
                            <input type="text" name="address" required value="<?= htmlspecialchars($_POST['address'] ?? '') ?>" class="input-field" placeholder="Street, Barangay, City">
                        </div>
                    </div>
                </div>

                <div class="glass-card p-8 border border-white/5">
                    <h4 class="text-base font-black italic uppercase tracking-tighter mb-8 flex items-center gap-3 text-primary">
                        <span class="material-symbols-outlined bg-primary/10 p-2 rounded-xl text-xl">medical_information</span> Health & Profile
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                        <div class="space-y-2 md:col-span-2">
                            <label class="text-[11px] font-black uppercase text-gray-500 tracking-widest ml-1">Occupation</label>
                            <input type="text" name="occupation" value="<?= htmlspecialchars($_POST['occupation'] ?? '') ?>" class="input-field" placeholder="e.g. Software Engineer">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[11px] font-black uppercase text-gray-500 tracking-widest ml-1">Emergency Name <span class="text-red-500">*</span></label>
                            <input type="text" name="emergency_name" required value="<?= htmlspecialchars($_POST['emergency_name'] ?? '') ?>" class="input-field" placeholder="Full Name">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[11px] font-black uppercase text-gray-500 tracking-widest ml-1">Emergency Contact <span class="text-red-500">*</span></label>
                            <input type="tel" name="emergency_phone" required oninput="formatPhoneNumber(this)" value="<?= htmlspecialchars($_POST['emergency_phone'] ?? '') ?>" class="input-field" placeholder="09XX-XXX-XXXX">
                        </div>
                        <div class="space-y-2 md:col-span-2">
                            <label class="text-[11px] font-black uppercase text-gray-500 tracking-widest ml-1">Medical History</label>
                            <textarea name="medical_history" class="input-field min-h-[100px] py-4" placeholder="List any existing conditions or allergies..."><?= htmlspecialchars($_POST['medical_history'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end pt-4">
                    <button type="submit" class="group px-10 h-16 rounded-2xl bg-primary text-white text-xs font-black uppercase tracking-[0.2em] shadow-lg shadow-primary/20 hover:shadow-primary/40 hover:-translate-y-1 transition-all flex items-center gap-4">Register Member <span class="material-symbols-outlined group-hover:translate-x-1 transition-transform text-xl">arrow_forward</span></button>
                </div>
            </form>
        </div>
    </main>
</div>
</body>
</html>