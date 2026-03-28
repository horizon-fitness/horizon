    <?php
    session_start();
    require_once '../db.php';

    // Security Check
    $role = strtolower($_SESSION['role'] ?? '');
    if (!isset($_SESSION['user_id']) || $role !== 'coach') {
        header("Location: ../login.php");
        exit;
    }

    $user_id = $_SESSION['user_id'];
    $gym_id = $_SESSION['gym_id'];

    // Fetch User Details
    $stmtUser = $pdo->prepare("SELECT *, CONCAT(first_name, ' ', last_name) as fullname FROM users WHERE user_id = ? LIMIT 1");
    $stmtUser->execute([$user_id]);
    $user = $stmtUser->fetch();

    // Fetch Coach Specific Info
    $stmtCoach = $pdo->prepare("SELECT * FROM coaches WHERE user_id = ? AND gym_id = ? LIMIT 1");
    $stmtCoach->execute([$user_id, $gym_id]);
    $coach = $stmtCoach->fetch();
    $coach_id = $coach ? $coach['coach_id'] : 0;

    $pending_count = 0;
    if ($coach_id > 0) {
        $stmtPending = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE coach_id = ? AND booking_status = 'Pending'");
        $stmtPending->execute([$coach_id]);
        $pending_count = $stmtPending->fetchColumn();
    }

    // Handle Password Update
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_password'])) {
        $new_pass = $_POST['new_password'];
        $conf_pass = $_POST['confirm_password'];

        if ($new_pass !== $conf_pass) {
            $error_msg = "Passwords do not match.";
        } elseif (strlen($new_pass) < 6) {
            $error_msg = "Password must be at least 6 characters.";
        } else {
            $hash = password_hash($new_pass, PASSWORD_DEFAULT);
            $stmtUpdate = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE user_id = ?");
            if ($stmtUpdate->execute([$hash, $user_id])) {
                $success_msg = "Password updated successfully.";
            } else {
                $error_msg = "Failed to update password.";
            }
        }
    }
    ?>
    <!DOCTYPE html>
    <html class="dark" lang="en">
    <head>
        <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
        <title>Coach Profile | Herdoza Fitness</title>
        <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
        <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
        <script src="https://cdn.tailwindcss.com"></script>
        <script>
            tailwind.config = {
                darkMode: "class",
                theme: { extend: { colors: { "primary": "<?= $_SESSION['theme_color'] ?? '#8c2bee' ?>", "background-dark": "#0a090d", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)" } } }
            }
        </script>
        <style>
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; padding-bottom: 100px; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        
        /* Sidebar Hover Logic - ADJUSTED WIDTHS */
        .sidebar-nav {
            width: 110px; 
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .sidebar-nav:hover {
            width: 300px; 
        }

        .nav-text {
            opacity: 0;
            transform: translateX(-15px);
            transition: all 0.3s ease-in-out;
            white-space: nowrap;
            pointer-events: none;
        }
        .sidebar-nav:hover .nav-text {
            opacity: 1;
            transform: translateX(0);
            pointer-events: auto;
        }

        .nav-link { font-size: 11px; font-weight: 800; letter-spacing: 0.05em; transition: all 0.2s; white-space: nowrap; }
        .active-nav { color: #8c2bee !important; position: relative; }
        .active-nav::after { 
            content: ''; 
            position: absolute; 
            right: 0px; 
            top: 50%;
            transform: translateY(-50%);
            width: 4px; 
            height: 20px; 
            background: #8c2bee; 
            border-radius: 99px; 
        }
        @media (max-width: 1024px) { 
            .sidebar-nav { width: 100%; height: auto; position: relative; }
            .sidebar-nav:hover { width: 100%; }
            .nav-text { opacity: 1; transform: translateX(0); pointer-events: auto; }
            .active-nav::after { width: 100%; height: 3px; bottom: -8px; left: 0; top: auto; right: auto; transform: none; } 
        }
        .mobile-taskbar { background: rgba(20, 18, 26, 0.9); backdrop-filter: blur(20px); border-top: 1px solid rgba(255,255,255,0.05); }
        .alert-dot { animation: pulse 2s infinite; }
            @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.4; } 100% { opacity: 1; } }

            /* Animations */
            @keyframes slideUp { 
                from { transform: translateY(20px); opacity: 0; }
                to { transform: translateY(0); opacity: 1; }
            }
            @keyframes fadeIn {
                from { opacity: 0; }
                to { opacity: 1; }
            }
            .animate-fade-in { animation: fadeIn 0.8s ease-out; }
            .animate-slide-up { animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1); }
        </style>
        <script>
            function updateHeaderClock() {
                const now = new Date();
                const timeString = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
                if(document.getElementById('headerClock')) document.getElementById('headerClock').textContent = timeString;
                if(document.getElementById('sidebarClock')) document.getElementById('sidebarClock').textContent = timeString;
            }
            window.onload = function() {
                updateHeaderClock();
                setInterval(updateHeaderClock, 1000);
            };
        </script>
    </head>
    <body class="antialiased flex flex-col lg:flex-row min-h-screen">

        <nav class="sidebar-nav hidden lg:flex flex-col bg-[#0a090d] border-r border-white/5 sticky top-0 h-screen px-7 py-8 z-50 shrink-0">
    <div class="mb-10 shrink-0"> 
        <div class="flex items-center gap-4 mb-4 min-w-[240px]"> 
            <div class="size-11 rounded-xl bg-primary flex items-center justify-center shadow-lg shrink-0 overflow-hidden">
                <?php if (!empty($gym['logo_path'])): ?>
                    <img src="<?= htmlspecialchars($gym['logo_path']) ?>" class="size-full object-contain">
                <?php else: ?>
                    <span class="material-symbols-outlined text-white text-2xl">bolt</span>
                <?php endif; ?>
            </div>
            <h1 class="nav-text text-lg font-black italic uppercase tracking-tighter text-white leading-tight whitespace-nowrap">
                <?= htmlspecialchars($gym['gym_name'] ?? 'HORIZON COACH') ?>
            </h1>
        </div>
    </div>
    
    <div class="flex-1 overflow-y-auto no-scrollbar space-y-1 pr-2">
        <div class="nav-section-header px-0 mb-2">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Main Menu</span>
        </div>
        
        <a href="coach_dashboard.php" class="nav-link flex items-center gap-4 py-2 <?= (basename($_SERVER['PHP_SELF']) == 'coach_dashboard.php') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">grid_view</span> 
            <span class="nav-text">Dashboard</span>
            <?php if($pending_count > 0): ?><span class="size-1.5 rounded-full bg-primary alert-dot ml-auto"></span><?php endif; ?>
        </a>
        
        <a href="coach_schedule.php" class="nav-link flex items-center gap-4 py-2 <?= (basename($_SERVER['PHP_SELF']) == 'coach_schedule.php') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">edit_calendar</span> 
            <span class="nav-text">My Availability</span>
        </a>

        <a href="coach_members.php" class="nav-link flex items-center gap-4 py-2 <?= (basename($_SERVER['PHP_SELF']) == 'coach_members.php') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">groups</span> 
            <span class="nav-text">My Members</span>
        </a>

        <div class="nav-section-header px-0 mb-2 mt-6">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Training</span>
        </div>

        <a href="coach_workouts.php" class="nav-link flex items-center gap-4 py-2 <?= (basename($_SERVER['PHP_SELF']) == 'coach_workouts.php') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">fitness_center</span> 
            <span class="nav-text">Workouts</span>
        </a>
    </div>

    <div class="mt-auto pt-4 border-t border-white/10 flex flex-col gap-1 shrink-0">
        <div class="nav-section-header px-0 mb-2">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Account</span>
        </div>

        <a href="coach_profile.php" class="nav-link flex items-center gap-4 py-2 <?= (basename($_SERVER['PHP_SELF']) == 'coach_profile.php') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl shrink-0">account_circle</span> 
            <span class="nav-text">Profile</span>
        </a>

        <a href="../logout.php" class="text-gray-400 hover:text-rose-500 transition-colors flex items-center gap-4 group py-2">
            <span class="material-symbols-outlined group-hover:translate-x-1 transition-transform text-xl shrink-0">logout</span>
            <span class="nav-link nav-text text-sm">Sign Out</span>
        </a>
    </div>
</nav>

        <div class="fixed bottom-0 left-0 right-0 h-20 mobile-taskbar z-[100] lg:hidden flex items-center justify-around px-4">
            <a href="dashboard_coach.php" class="flex flex-col items-center gap-1 text-gray-500"><span class="material-symbols-outlined">calendar_month</span><span class="text-[8px] font-black uppercase">Schedule</span></a>
            <a href="coach_schedule.php" class="flex flex-col items-center gap-1 text-gray-500"><span class="material-symbols-outlined">edit_calendar</span><span class="text-[8px] font-black uppercase">Avail</span></a>
            <a href="coach_members.php" class="flex flex-col items-center gap-1 text-gray-500"><span class="material-symbols-outlined">groups</span><span class="text-[8px] font-black uppercase">Members</span></a>
            <a href="coach_workouts.php" class="flex flex-col items-center gap-1 text-gray-500"><span class="material-symbols-outlined">fitness_center</span><span class="text-[8px] font-black uppercase">Workouts</span></a>
            <a href="coach_profile.php" class="flex flex-col items-center gap-1 text-primary"><span class="material-symbols-outlined">person</span><span class="text-[8px] font-black uppercase">Profile</span></a>
        </div>

        <main class="flex-1 max-w-[1600px] p-6 lg:p-12 overflow-x-hidden">
            <header class="mb-12 flex flex-col md:flex-row justify-between items-end gap-4 animate-fade-in">
                <div>
                    <h2 class="text-4xl font-black italic uppercase tracking-tighter text-white leading-none">Coach <span class="text-primary">Profile</span></h2>
                    <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-2">Manage your trainer identity</p>
                </div>
                <div class="text-right">
                    <p id="headerClock" class="text-white font-black italic text-xl tracking-tight leading-none mb-2">00:00:00 AM</p>
                    <p class="text-primary text-[9px] font-black uppercase tracking-[0.2em]"><?= date('l, M d') ?></p>
                </div>
            </header>

            <?php if(isset($success_msg)): ?><div class="bg-emerald-500/10 border border-emerald-500/20 p-4 rounded-xl text-emerald-500 text-[10px] font-black uppercase italic mb-8 flex items-center gap-2"><span class="material-symbols-outlined text-sm">check_circle</span> <?= $success_msg ?></div><?php endif; ?>
            <?php if(isset($error_msg)): ?><div class="bg-red-500/10 border border-red-500/20 p-4 rounded-xl text-red-500 text-[10px] font-black uppercase italic mb-8 flex items-center gap-2"><span class="material-symbols-outlined text-sm">error</span> <?= $error_msg ?></div><?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 animate-slide-up">
                <div class="lg:col-span-1 space-y-6">
                    <div class="bg-surface-dark border border-border-subtle rounded-[32px] p-8 shadow-xl">
                        <h3 class="text-sm font-black uppercase tracking-widest mb-8 flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary text-lg">badge</span> Coach Info
                        </h3>
                        <div class="space-y-6">
                            <div>
                                <label class="text-[9px] font-black uppercase text-gray-500">Full Name</label>
                                <p class="text-lg font-bold italic"><?= htmlspecialchars($user['fullname']) ?></p>
                            </div>
                            <div>
                                <label class="text-[9px] font-black uppercase text-gray-500">Username</label>
                                <p class="text-sm font-medium text-primary">@<?= htmlspecialchars($user['username']) ?></p>
                            </div>
                            <div>
                                <label class="text-[9px] font-black uppercase text-gray-500">Email</label>
                                <p class="text-sm font-medium text-gray-300"><?= htmlspecialchars($user['email']) ?></p>
                            </div>
                            <div>
                                <label class="text-[9px] font-black uppercase text-gray-500">Phone Number</label>
                                <p class="text-sm font-medium text-gray-300"><?= htmlspecialchars($user['contact_number'] ?? 'Not set') ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-2 space-y-6">
                    <div class="bg-surface-dark border border-border-subtle rounded-[32px] p-8 shadow-xl">
                        <h3 class="text-sm font-black uppercase tracking-widest mb-8">Security & Password</h3>
                        <div class="flex items-center justify-between p-6 bg-background-dark rounded-2xl border border-white/5">
                            <div class="flex items-center gap-4">
                                <span class="material-symbols-outlined text-gray-500">lock</span>
                                <div>
                                    <p class="text-xs font-bold italic tracking-tight uppercase">Change Password</p>
                                    <p class="text-[9px] text-gray-600 font-bold uppercase tracking-widest">Update your login credentials</p>
                                </div>
                            </div>
                            <button onclick="toggleModal('passwordModal')" class="bg-primary hover:bg-primary/90 text-white px-6 py-2 rounded-xl text-[9px] font-black uppercase tracking-widest transition-all">Update</button>
                        </div>
                    </div>
                    <a href="logout.php" class="flex items-center justify-center gap-2 w-full py-5 bg-red-500/5 hover:bg-red-500/10 border border-red-500/10 text-red-500 rounded-[24px] text-[10px] font-black uppercase tracking-widest transition-all">
                        <span class="material-symbols-outlined text-sm">logout</span> Sign out of Session
                    </a>
                </div>
            </div>
        </main>

        <div id="passwordModal" class="fixed inset-0 z-[120] hidden items-center justify-center bg-black/80 backdrop-blur-sm p-4">
            <div class="bg-surface-dark w-full max-w-md rounded-[32px] p-8 border border-white/10 shadow-2xl">
                <h3 class="text-xl font-black italic uppercase mb-6">Update <span class="text-primary">Password</span></h3>
                <form method="POST" class="space-y-4">
                    <div class="relative">
                        <input type="password" name="new_password" id="new_password" placeholder="New Password" required class="modal-input w-full p-4 rounded-xl outline-none pr-12">
                        <button type="button" onclick="togglePassword('new_password', this)" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-500 hover:text-white transition-colors">
                            <span class="material-symbols-outlined text-xl">visibility</span>
                        </button>
                    </div>
                    <div class="relative">
                        <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm New Password" required class="modal-input w-full p-4 rounded-xl outline-none pr-12">
                        <button type="button" onclick="togglePassword('confirm_password', this)" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-500 hover:text-white transition-colors">
                            <span class="material-symbols-outlined text-xl">visibility</span>
                        </button>
                    </div>
                    <button type="submit" name="update_password" class="w-full py-4 bg-primary rounded-2xl font-black uppercase italic tracking-widest text-white shadow-lg hover:scale-105 transition-all">Update Password</button>
                    <button type="button" onclick="toggleModal('passwordModal')" class="w-full text-[9px] font-black text-gray-500 uppercase mt-2 hover:text-white transition-colors">Cancel</button>
                </form>
            </div>
        </div>

        <script>
            function toggleModal(id) {
                const modal = document.getElementById(id);
                modal.classList.toggle('hidden');
                modal.classList.toggle('flex');
            }

            function togglePassword(inputId, btn) {
                const input = document.getElementById(inputId);
                const icon = btn.querySelector('.material-symbols-outlined');
                if (input.type === "password") {
                    input.type = "text";
                    icon.textContent = "visibility_off";
                } else {
                    input.type = "password";
                    icon.textContent = "visibility";
                }
            }
        </script>
    </body>
    </html>