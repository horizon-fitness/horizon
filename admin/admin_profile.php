<?php
session_start();
require_once '../db.php';

// Security Check
$role = strtolower($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || ($role !== 'staff' && $role !== 'coach')) {
    header("Location: ../login.php");
    exit;
}

$gym_id = $_SESSION['gym_id'];
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$admin_name = $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];
$email = $_SESSION['email'] ?? 'staff@horizon.com';
$phone = $_SESSION['phone_number'] ?? 'N/A';

// Mock data for UI
$success_msg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $success_msg = "Profile updated successfully! (Mock Mode)";
}
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Staff Profile | Staff Dashboard</title>
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
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; display: flex; flex-direction: row; min-h-screen: 100vh; overflow-x: hidden; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        
        /* Sidebar Hover Logic - MATCHING SUPER ADMIN */
        .side-nav {
            width: 110px; 
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            position: fixed;
            left: 0;
            top: 0;
            height: 100vh;
            z-index: 50;
            background: #0a090d;
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
        
        .form-input { background: #0a090d; border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; padding: 12px 16px; color: white; font-size: 14px; width: 100%; outline: none; transition: border-color 0.2s; }
        .form-input:focus { border-color: #8c2bee; }
        .strength-bar { height: 4px; border-radius: 2px; transition: all 0.3s ease; width: 0; }
        .strength-weak { background-color: #ef4444; width: 33.33%; }
        .strength-medium { background-color: #f59e0b; width: 66.66%; }
        .strength-strong { background-color: #10b981; width: 100%; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        /* Content Adjustment */
        .main-content {
            margin-left: 110px; /* Base margin */
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .side-nav:hover ~ .main-content {
            margin-left: 300px; /* Expand margin when sidebar is hovered */
        }
    </style>
    <script>
        function updateHeaderClock() {
            const now = new Date();
            const clockEl = document.getElementById('headerClock');
            if (clockEl) {
                clockEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
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
            
            if (password.length >= 8) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[a-z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^A-Za-z0-9]/.test(password)) strength++;

            indicator.className = 'strength-bar';
            
            if (password.length === 0) {
                text.textContent = '';
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

        function validateProfileForm(event) {
            const password = document.getElementById('new_password').value;
            const confirm = document.getElementById('confirm_password').value;
            const strengthText = document.getElementById('strength-text').textContent;

            if (password || confirm) {
                if (password !== confirm) {
                    alert('Passwords do not match!');
                    event.preventDefault();
                    return false;
                }
                if (strengthText === 'Weak') {
                    alert('New password is too weak. Please use a stronger password.');
                    event.preventDefault();
                    return false;
                }
            }
            return true;
        }
    </script>
</head>
<body class="antialiased flex flex-row min-h-screen">

<nav class="side-nav bg-[#0a090d] border-r border-white/5">
    <div class="px-4 py-6">
        <div class="flex items-center gap-3">
            <div class="size-10 rounded-xl bg-primary flex items-center justify-center shadow-lg shrink-0 overflow-hidden">
                <span class="material-symbols-outlined text-white text-2xl">bolt</span>
            </div>
            <span class="nav-label text-white font-black italic uppercase tracking-tighter text-base leading-none">Herdoza</span>
        </div>
    </div>
    <div class="flex flex-col flex-1 overflow-y-auto no-scrollbar px-3 gap-0.5">
        <span class="nav-section-label text-[9px] font-black text-gray-500 uppercase tracking-widest px-3 mt-0">Main Menu</span>
        <a href="admin_dashboard.php" class="nav-item text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl shrink-0">grid_view</span>
            <span class="nav-label font-black text-[10px] uppercase tracking-wider">Dashboard</span>
        </a>
        <a href="register_member.php" class="nav-item text-gray-400 hover:text-white">
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
        <a href="admin_profile.php" class="nav-item active text-primary">
            <span class="material-symbols-outlined text-xl shrink-0">person</span>
            <span class="nav-label font-black text-[10px] uppercase tracking-wider">Profile</span>
        </a>
        <a href="../logout.php" class="nav-item text-gray-400 hover:text-red-500 group">
            <span class="material-symbols-outlined text-xl shrink-0 group-hover:translate-x-1 transition-transform">logout</span>
            <span class="nav-label font-black text-[10px] uppercase tracking-wider">Sign Out</span>
        </a>
    </div>
</nav>

<div class="main-content flex flex-col min-w-0">
    <main class="flex-1 p-6 md:p-10 max-w-[1400px] w-full mx-auto">
        <header class="mb-10 w-full max-w-5xl flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
            <div>
                <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white">Staff <span class="text-primary">Profile</span></h2>
                <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-1">Manage your staff account identity</p>
            </div>
            <div class="flex flex-row items-center gap-4">
                <div class="px-6 py-3.5 rounded-2xl bg-white/5 border border-white/5 text-right flex flex-col items-end group shadow-sm hover:shadow-primary/10 transition-shadow">
                    <p id="headerClock" class="text-white font-black italic text-xl leading-none mb-1 group-hover:text-primary transition-colors">00:00:00 AM</p>
                    <p class="text-gray-500 text-[9px] font-black uppercase tracking-[0.2em] group-hover:text-white transition-colors"><?= date('l, M d') ?></p>
                </div>
            </div>
        </header>

        <div class="w-full max-w-5xl">
            <?php if ($success_msg): ?>
            <div class="mb-8 p-4 bg-emerald-500/10 border border-emerald-500/20 rounded-2xl text-emerald-500 text-xs font-bold uppercase italic flex items-center gap-2">
                <span class="material-symbols-outlined text-sm">check_circle</span> <?= $success_msg ?>
            </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <div class="lg:col-span-2">
                <div class="glass-card p-8">
                    <form method="POST" onsubmit="return validateProfileForm(event)" class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="space-y-2">
                                <label class="text-[10px] font-black uppercase text-gray-500 tracking-widest ml-1">Full Name</label>
                                <input type="text" name="full_name" required class="form-input" value="<?= htmlspecialchars($admin_name) ?>">
                            </div>
                            <div class="space-y-2">
                                <label class="text-[10px] font-black uppercase text-gray-500 tracking-widest ml-1">Username</label>
                                <input type="text" name="username" required class="form-input" value="<?= htmlspecialchars($username) ?>">
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="space-y-2">
                                <label class="text-[10px] font-black uppercase text-gray-500 tracking-widest ml-1">Email Address</label>
                                <input type="email" name="email" required class="form-input" value="<?= htmlspecialchars($email) ?>">
                            </div>
                            <div class="space-y-2">
                                <label class="text-[10px] font-black uppercase text-gray-400 tracking-widest ml-1">Phone Number</label>
                                <input type="tel" name="phone" required class="form-input" value="<?= htmlspecialchars($phone) ?>">
                            </div>
                        </div>

                        <div class="pt-6 border-t border-white/5 space-y-6">
                            <h4 class="text-[10px] font-black uppercase text-primary tracking-[0.2em]">Security Update</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div class="space-y-2">
                                    <label class="text-[10px] font-black uppercase text-gray-500 tracking-widest ml-1">New Password</label>
                                    <div class="relative">
                                        <input type="password" name="new_password" id="new_password" onkeyup="checkPasswordStrength(this.value)" class="form-input pr-12" placeholder="Leave blank to keep current">
                                        <button type="button" onclick="togglePassword('new_password', this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-white transition-all">
                                            <span class="material-symbols-outlined text-xl">visibility</span>
                                        </button>
                                    </div>
                                    <div class="w-full bg-white/5 h-1 rounded-full mt-2 overflow-hidden">
                                        <div id="strength-indicator" class="strength-bar"></div>
                                    </div>
                                    <p id="strength-text" class="text-[10px] font-black uppercase tracking-widest mt-1"></p>
                                </div>
                                <div class="space-y-2">
                                    <label class="text-[10px] font-black uppercase text-gray-500 tracking-widest ml-1">Confirm New Password</label>
                                    <div class="relative">
                                        <input type="password" name="confirm_password" id="confirm_password" class="form-input pr-12" placeholder="Confirm new password">
                                        <button type="button" onclick="togglePassword('confirm_password', this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-white transition-all">
                                            <span class="material-symbols-outlined text-xl">visibility</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="pt-4">
                            <button type="submit" name="update_profile" class="w-full bg-primary hover:bg-primary/90 text-white font-black uppercase italic py-4 rounded-2xl shadow-lg shadow-primary/20 transition-all transform hover:scale-[1.02]">
                                Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="lg:col-span-1">
                <div class="glass-card p-8 space-y-6">
                    <div class="flex flex-col items-center text-center">
                        <div class="size-24 rounded-3xl bg-primary/10 flex items-center justify-center mb-4 border border-primary/20">
                            <span class="material-symbols-outlined text-primary text-5xl">person</span>
                        </div>
                        <h3 class="text-xl font-black italic uppercase"><?= htmlspecialchars($admin_name) ?></h3>
                        <p class="text-primary text-[10px] font-black uppercase tracking-widest">Gym Staff</p>
                    </div>
                    <div class="pt-6 border-t border-white/5 space-y-4">
                        <div class="flex justify-between items-center">
                            <span class="text-[10px] font-black uppercase text-gray-500 tracking-widest">Gym ID</span>
                            <span class="text-xs font-mono text-white">#<?= $gym_id ?></span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-[10px] font-black uppercase text-gray-500 tracking-widest">Account Status</span>
                            <span class="px-2 py-0.5 rounded-full bg-emerald-500/10 text-emerald-500 text-[8px] font-black uppercase italic border border-emerald-500/20">Active</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
</body>
</html>