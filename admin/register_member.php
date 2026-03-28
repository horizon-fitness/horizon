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
        // Reset POST to clear form on success 
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
    <title>Walk-in Registration | Staff Dashboard</title> 
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/> 
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/> 
    <script src="https://cdn.tailwindcss.com"></script> 
    <script> 
        tailwind.config = { 
            darkMode: "class", 
            theme: { extend: { colors: { "primary": "#8c2bee", "background-dark": "#0a090d", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)"}}} 
        } 
    </script> 
    <style> 
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; display: flex; flex-direction: row; min-h-screen: 100vh; } 
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; } 
        .input-field { background: #0a090d; border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; color: white; padding: 12px 16px; width: 100%; outline: none; transition: all 0.2s; } 
        .input-field:focus { border-color: #8c2bee; } 
        input[type="date"]::-webkit-calendar-picker-indicator { filter: invert(1); opacity: 0.5; cursor: pointer; }
        select.input-field { background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='white'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 1rem center; background-size: 1rem; }
        .strength-bar { height: 4px; border-radius: 2px; transition: all 0.3s ease; width: 0; }
        .strength-weak { background-color: #ef4444; width: 33.33%; }
        .strength-medium { background-color: #f59e0b; width: 66.66%; }
        .strength-strong { background-color: #10b981; width: 100%; }
        
        /* Sidebar Hover Logic - MATCHING SUPER ADMIN */
        .side-nav {
            width: 110px; 
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            position: sticky;
            top: 0;
            height: 100vh;
            z-index: 50;
            shrink: 0;
        }
        .side-nav:hover {
            width: 300px; 
        }

        .nav-label {
            opacity: 0;
            transform: translateX(-15px);
            transition: all 0.3s ease-in-out;
            white-space: nowrap;
            pointer-events: none;
        }
        .side-nav:hover .nav-label {
            opacity: 1;
            transform: translateX(0);
            pointer-events: auto;
        }

        .nav-section-label {
            max-height: 0;
            opacity: 0;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            margin: 0 !important;
            pointer-events: none;
        }
        .side-nav:hover .nav-section-label {
            max-height: 20px;
            opacity: 1;
            margin-bottom: 0px !important; 
            pointer-events: auto;
        }
        .side-nav:hover .mt-0 { margin-top: 0px !important; } 

        .nav-item { display: flex; align-items: center; gap: 12px; padding: 12px 16px; border-radius: 10px; transition: all 0.2s ease; text-decoration: none; white-space: nowrap; }
        .nav-item:hover { background: rgba(255,255,255,0.05); }
        .nav-item.active { color: #8c2bee !important; background: rgba(140,43,238,0.1); border: 1px solid rgba(140,43,238,0.15); }
        .no-scrollbar::-webkit-scrollbar { display: none; } 
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; } 

        /* Content Adjustment */
        .main-content {
            flex: 1;
            min-width: 0;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
    </style> 
    <script> 
        function updateHeaderClock() { 
            const now = new Date(); 
            const clockEl = document.getElementById('headerClock'); 
            if (clockEl) { 
                clockEl.textContent = now.toLocaleTimeString('en-US', {  
                    hour: '2-digit', minute: '2-digit', second: '2-digit'  
                }); 
            } 
        } 
        setInterval(updateHeaderClock, 1000); 
        window.addEventListener('DOMContentLoaded', updateHeaderClock); 

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
            const submitBtn = document.getElementById('submit-btn');
            
            if (password.length >= 8) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[a-z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^A-Za-z0-9]/.test(password)) strength++;

            indicator.className = 'strength-bar';
            
            if (password.length === 0) {
                text.textContent = '';
                submitBtn.disabled = false;
            } else if (strength <= 2) {
                indicator.classList.add('strength-weak');
                text.textContent = 'Weak';
                text.className = 'text-[10px] font-black uppercase tracking-widest text-red-500 mt-1';
            } else if (strength <= 4) {
                indicator.classList.add('strength-medium');
                text.textContent = 'Medium';
                text.className = 'text-[10px] font-black uppercase tracking-widest text-amber-500 mt-1';
            } else {
                indicator.classList.add('strength-strong');
                text.textContent = 'Strong';
                text.className = 'text-[10px] font-black uppercase tracking-widest text-emerald-500 mt-1';
            }
        }

        function validateForm(event) {
            const password = document.getElementById('password').value;
            const confirm = document.getElementById('confirm_password').value;
            const strengthText = document.getElementById('strength-text').textContent;

            if (password !== confirm) {
                alert('Passwords do not match!');
                event.preventDefault();
                return false;
            }

            if (strengthText === 'Weak') {
                alert('Password is too weak. Please use a stronger password.');
                event.preventDefault();
                return false;
            }
            return true;
        }
    </script> 
</head> 
<body class="antialiased flex flex-row min-h-screen overflow-hidden"> 
<nav class="side-nav bg-[#0a090d] border-r border-white/5 shrink-0">
    <div class="px-4 py-6">
        <div class="flex items-center gap-3">
            <div class="size-10 rounded-xl bg-[#7f13ec] flex items-center justify-center shadow-lg shrink-0 overflow-hidden"> 
                <span class="material-symbols-outlined text-white text-2xl">bolt</span> 
            </div> 
            <span class="nav-label text-white font-black italic uppercase tracking-tighter text-base leading-none">Staff Dashboard</span>
        </div>
    </div>
    <div class="flex flex-col flex-1 overflow-y-auto no-scrollbar px-3 gap-0.5">
        <span class="nav-section-label text-[9px] font-black text-gray-500 uppercase tracking-widest px-3 mt-0">Main Menu</span>
        <a href="admin_dashboard.php" class="nav-item text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl shrink-0">grid_view</span>
            <span class="nav-label font-black text-[10px] uppercase tracking-wider">Dashboard</span>
        </a>
        <a href="register_member.php" class="nav-item active text-primary">
            <span class="material-symbols-outlined text-xl shrink-0">person_add</span>
            <span class="nav-label font-black text-[10px] uppercase tracking-wider">Walk-in Member</span>
        </a>
        <a href="admin_users.php" class="nav-item text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl shrink-0">group</span>
            <span class="nav-label font-black text-[10px] uppercase tracking-wider">My Users</span>
        </a>

        <span class="nav-section-label text-[9px] font-black text-gray-500 uppercase tracking-widest px-3">Management</span>
        <a href="admin_transaction.php" class="nav-item text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl shrink-0">receipt_long</span>
            <span class="nav-label font-black text-[10px] uppercase tracking-wider">Transactions</span>
        </a>
        <a href="admin_appointment.php" class="nav-item text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl shrink-0">event_note</span>
            <span class="nav-label font-black text-[10px] uppercase tracking-wider">Bookings</span>
        </a>
        <a href="admin_attendance.php" class="nav-item text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl shrink-0">history</span>
            <span class="nav-label font-black text-[10px] uppercase tracking-wider">Attendance</span>
        </a>
        <a href="admin_report.php" class="nav-item text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl shrink-0">description</span>
            <span class="nav-label font-black text-[10px] uppercase tracking-wider">Reports</span>
        </a>
    </div>
    <div class="px-3 pt-4 pb-4 border-t border-white/10 flex flex-col gap-0.5">
        <span class="nav-section-label text-[9px] font-black text-gray-500 uppercase tracking-widest px-3 mt-0 mb-2">Account</span>
        <a href="admin_profile.php" class="nav-item text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl shrink-0">person</span>
            <span class="nav-label font-black text-[10px] uppercase tracking-wider">Profile</span>
        </a>
        <a href="../logout.php" class="nav-item text-gray-400 hover:text-red-500 group">
            <span class="material-symbols-outlined text-xl shrink-0 group-hover:translate-x-1 transition-transform">logout</span>
            <span class="nav-label font-black text-[10px] uppercase tracking-wider">Sign Out</span>
        </a>
    </div>
</nav>
  
<div class="main-content flex-1 flex flex-col min-w-0 overflow-y-auto">
    <main class="flex-1 p-6 md:p-10 max-w-[1400px] w-full mx-auto flex flex-col items-center">
        <header class="mb-10 w-full max-w-4xl flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
            <div>
                <h1 class="text-3xl font-black text-white italic uppercase tracking-tighter mb-1">Walk-in <span class="text-primary">Registration</span></h1>
                <p class="text-primary text-xs font-bold uppercase tracking-widest">New Member Onboarding • Facility Entry</p>
            </div>
            <div class="flex flex-row items-center gap-4">
                <div class="px-6 py-3.5 rounded-2xl bg-white/5 border border-white/5 text-right flex flex-col items-end group shadow-sm hover:shadow-primary/10 transition-shadow">
                    <p id="headerClock" class="text-white font-black italic text-xl leading-none mb-1 group-hover:text-primary transition-colors">00:00:00 AM</p>
                    <p class="text-gray-500 text-[9px] font-black uppercase tracking-[0.2em] group-hover:text-white transition-colors"><?= date('l, M d') ?></p>
                </div>
            </div>
        </header> 
  
        <div class="w-full max-w-4xl"> 
            <?php if($success): ?> 
                <div class="mb-8 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-500 text-sm font-bold flex items-center gap-3"> 
                    <span class="material-symbols-outlined">check_circle</span> <?= $success ?> 
                </div> 
            <?php endif; ?> 
  
            <?php if($error): ?> 
                <div class="mb-8 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-sm font-bold flex items-center gap-3"> 
                    <span class="material-symbols-outlined">error</span> <?= $error ?> 
                </div> 
            <?php endif; ?> 
  
            <div class="glass-card p-8 md:p-12 shadow-2xl relative overflow-hidden"> 
                <form method="POST" onsubmit="return validateForm(event)" class="space-y-10"> 
                    <!-- Personal Information -->
                    <div class="space-y-6">
                        <h4 class="text-sm font-black italic uppercase tracking-tighter flex items-center gap-2 text-primary"> 
                            <span class="material-symbols-outlined text-lg">person</span> Personal Information
                        </h4> 
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                            <div class="space-y-2">
                                <label class="text-[10px] font-black uppercase text-gray-500 tracking-widest ml-1">Username <span class="text-red-500">*</span></label>
                                <input type="text" name="username" required class="input-field" placeholder="Username">
                            </div>
                            <div class="space-y-2">
                                <label class="text-[10px] font-black uppercase text-gray-500 tracking-widest ml-1">First Name <span class="text-red-500">*</span></label>
                                <input type="text" name="first_name" required class="input-field" placeholder="First Name">
                            </div>
                            <div class="space-y-2">
                                <label class="text-[10px] font-black uppercase text-gray-500 tracking-widest ml-1">Middle Name</label>
                                <input type="text" name="middle_name" class="input-field" placeholder="Middle Name">
                            </div>
                            <div class="space-y-2">
                                <label class="text-[10px] font-black uppercase text-gray-500 tracking-widest ml-1">Last Name <span class="text-red-500">*</span></label>
                                <input type="text" name="last_name" required class="input-field" placeholder="Last Name">
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div class="space-y-2">
                                <label class="text-[10px] font-black uppercase text-gray-500 tracking-widest ml-1">Sex <span class="text-red-500">*</span></label>
                                <select name="sex" required class="input-field appearance-none cursor-pointer">
                                    <option value="" disabled selected>Select Sex</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div class="space-y-2">
                                <label class="text-[10px] font-black uppercase text-gray-500 tracking-widest ml-1">Birth Date <span class="text-red-500">*</span></label>
                                <input type="date" name="birth_date" required class="input-field">
                            </div>
                            <div class="space-y-2">
                                <label class="text-[10px] font-black uppercase text-gray-500 tracking-widest ml-1">Password <span class="text-red-500">*</span></label>
                                <div class="relative">
                                    <input type="password" name="password" id="password" required onkeyup="checkPasswordStrength(this.value)" class="input-field pr-12" placeholder="Password">
                                    <button type="button" onclick="togglePassword('password', this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-white transition-all">
                                        <span class="material-symbols-outlined text-xl">visibility</span>
                                    </button>
                                </div>
                                <div class="w-full bg-white/5 h-1 rounded-full mt-2 overflow-hidden">
                                    <div id="strength-indicator" class="strength-bar"></div>
                                </div>
                                <p id="strength-text" class="text-[10px] font-black uppercase tracking-widest mt-1"></p>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="space-y-2">
                                <label class="text-[10px] font-black uppercase text-gray-500 tracking-widest ml-1">Confirm Password <span class="text-red-500">*</span></label>
                                <div class="relative">
                                    <input type="password" name="confirm_password" id="confirm_password" required class="input-field pr-12" placeholder="Confirm Password">
                                    <button type="button" onclick="togglePassword('confirm_password', this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-white transition-all">
                                        <span class="material-symbols-outlined text-xl">visibility</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Contact Details -->
                    <div class="space-y-6">
                        <h4 class="text-sm font-black italic uppercase tracking-tighter flex items-center gap-2 text-primary"> 
                            <span class="material-symbols-outlined text-lg">contact_mail</span> Contact Details
                        </h4> 
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="space-y-2">
                                <label class="text-[10px] font-black uppercase text-gray-500 tracking-widest ml-1">Email Address <span class="text-red-500">*</span></label>
                                <input type="email" name="email" required class="input-field" placeholder="email@example.com">
                            </div>
                            <div class="space-y-2">
                                <label class="text-[10px] font-black uppercase text-gray-500 tracking-widest ml-1">Contact Number <span class="text-red-500">*</span></label>
                                <input type="tel" name="phone" required class="input-field" placeholder="09XX XXX XXXX">
                            </div>
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black uppercase text-gray-500 tracking-widest ml-1">Home Address <span class="text-red-500">*</span></label>
                            <input type="text" name="address" required class="input-field" placeholder="Complete Home Address">
                        </div>
                    </div>

                    <!-- Health & Professional Background -->
                    <div class="space-y-6">
                        <h4 class="text-sm font-black italic uppercase tracking-tighter flex items-center gap-2 text-primary"> 
                            <span class="material-symbols-outlined text-lg">medical_services</span> Health & Background
                        </h4> 
                        <div class="space-y-6">
                            <div class="space-y-2">
                                <label class="text-[10px] font-black uppercase text-gray-500 tracking-widest ml-1">Occupation</label>
                                <input type="text" name="occupation" class="input-field" placeholder="Your current job">
                            </div>
                            <div class="space-y-2">
                                <label class="text-[10px] font-black uppercase text-gray-500 tracking-widest ml-1">Medical History / Allergies</label>
                                <textarea name="medical_history" class="input-field min-h-[100px] resize-none" placeholder="Please specify any medical conditions or allergies..."></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Emergency Contact -->
                    <div class="space-y-6">
                        <h4 class="text-sm font-black italic uppercase tracking-tighter flex items-center gap-2 text-primary"> 
                            <span class="material-symbols-outlined text-lg">emergency</span> Emergency Contact
                        </h4> 
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="space-y-2">
                                <label class="text-[10px] font-black uppercase text-gray-500 tracking-widest ml-1">Contact Name <span class="text-red-500">*</span></label>
                                <input type="text" name="emergency_name" required class="input-field" placeholder="Name of person to contact">
                            </div>
                            <div class="space-y-2">
                                <label class="text-[10px] font-black uppercase text-gray-500 tracking-widest ml-1">Phone Number <span class="text-red-500">*</span></label>
                                <input type="tel" name="emergency_phone" required class="input-field" placeholder="Emergency contact number">
                            </div>
                        </div>
                    </div>

                    <div class="pt-6">
                        <button type="submit" class="w-full h-16 rounded-2xl bg-primary text-white text-xs font-black uppercase tracking-widest hover:opacity-90 transition-all shadow-lg shadow-primary/20 transform hover:scale-[1.01] flex items-center justify-center gap-2"> 
                            <span class="material-symbols-outlined">how_to_reg</span> Register & Send Credentials 
                        </button> 
                    </div>
                </form> 
            </div> 
        </div> 
    </main> 
</div> 
</body> 
</html>