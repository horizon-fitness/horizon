<?php

session_start();

require_once '../db.php';



// Security Check: Only Tenants/Admins/Staff

$role = strtolower($_SESSION['role'] ?? '');

if (!isset($_SESSION['user_id']) || ($role !== 'tenant' && $role !== 'admin' && $role !== 'staff' && $role !== 'coach')) {

    header("Location: ../login.php");

    exit;

}



$gym_id = $_SESSION['gym_id'];

$user_id = $_SESSION['user_id'];

$active_page = "staff";



$success_msg = '';

$error_msg = '';



// --- ADD STAFF LOGIC ---

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_staff'])) {

    $first_name = trim($_POST['first_name'] ?? '');

    $middle_name = trim($_POST['middle_name'] ?? '');

    $last_name = trim($_POST['last_name'] ?? '');

    $email = trim($_POST['email'] ?? '');

    $username = trim($_POST['username'] ?? '');

    $contact_number = trim($_POST['contact_number'] ?? '');

    $password = $_POST['password'] ?? '';

    $staff_role = $_POST['staff_role'] ?? 'Staff';

    $employment_type = $_POST['employment_type'] ?? 'Full-time';

    $now = date('Y-m-d H:i:s');



    if (empty($first_name) || empty($last_name) || empty($email) || empty($username)) {

        $error_msg = "All fields are required.";

    } else {

        try {

            $pdo->beginTransaction();



            $stmtCheck = $pdo->prepare("SELECT user_id FROM users WHERE username = ? OR email = ? LIMIT 1");

            $stmtCheck->execute([$username, $email]);

            if ($stmtCheck->fetch()) {

                throw new Exception("Username or Email already exists.");

            }



            $plain_password = $password;

            if (empty($plain_password)) {

                $plain_password = bin2hex(random_bytes(4));

            }



            $password_hash = password_hash($plain_password, PASSWORD_BCRYPT);

            $stmtUser = $pdo->prepare("INSERT INTO users (username, email, password_hash, first_name, middle_name, last_name, contact_number, is_verified, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)");

            $stmtUser->execute([$username, $email, $password_hash, $first_name, $middle_name, $last_name, $contact_number, $now, $now]);

            $new_user_id = $pdo->lastInsertId();



            $role_name = ($staff_role === 'Coach') ? 'Coach' : 'Staff';

            $stmtRoleCheck = $pdo->prepare("SELECT role_id FROM roles WHERE role_name = ? LIMIT 1");

            $stmtRoleCheck->execute([$role_name]);

            $role_id = $stmtRoleCheck->fetchColumn();



            if (!$role_id) {

                $pdo->prepare("INSERT INTO roles (role_name) VALUES (?)")->execute([$role_name]);

                $role_id = $pdo->lastInsertId();

            }



            $stmtUR = $pdo->prepare("INSERT INTO user_roles (user_id, role_id, gym_id, role_status, assigned_at) VALUES (?, ?, ?, 'Active', ?)");

            $stmtUR->execute([$new_user_id, $role_id, $gym_id, $now]);



            $stmtStaff = $pdo->prepare("INSERT INTO staff (user_id, gym_id, staff_role, employment_type, status, hire_date, created_at, updated_at) VALUES (?, ?, ?, ?, 'Active', ?, ?, ?)");

            $stmtStaff->execute([$new_user_id, $gym_id, $staff_role, $employment_type, date('Y-m-d'), $now, $now]);



            require_once '../includes/mailer.php';

            $gymName = $gym['gym_name'] ?? 'Horizon Portal';

            $subject = "Your Staff Account Credentials - $gymName";

            $emailBody = getEmailTemplate(

                "Welcome to the Team!",

                "<p>Hello $first_name,</p>

                <p>An account has been created for you at <strong>$gymName</strong>.</p>

                <div style='background: #f4f4f4; padding: 15px; border-radius: 8px; margin: 20px 0;'>

                    <strong>Username:</strong> $username<br>

                    <strong>Password:</strong> $plain_password

                </div>"

            );

            sendSystemEmail($email, $subject, $emailBody);



            $pdo->commit();

            $success_msg = "Staff member $first_name added successfully!";

        } catch (Exception $e) {

            $pdo->rollBack();

            $error_msg = $e->getMessage();

        }

    }

}



// --- UPDATE STAFF LOGIC ---

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_staff'])) {

    $target_staff_id = $_POST['staff_id'];

    $target_user_id = $_POST['user_id'];

    $f_name = trim($_POST['first_name']);

    $l_name = trim($_POST['last_name']);

    $email = trim($_POST['email']);

    $s_role = $_POST['staff_role'];

    $e_type = $_POST['employment_type'];

    $status = $_POST['status'];



    try {

        $pdo->beginTransaction();

        $stmtU = $pdo->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, updated_at = NOW() WHERE user_id = ?");

        $stmtU->execute([$f_name, $l_name, $email, $target_user_id]);



        $stmtS = $pdo->prepare("UPDATE staff SET staff_role = ?, employment_type = ?, status = ?, updated_at = NOW() WHERE staff_id = ?");

        $stmtS->execute([$s_role, $e_type, $status, $target_staff_id]);

        

        $pdo->commit();

        $success_msg = "Staff records updated successfully.";

    } catch (Exception $e) {

        $pdo->rollBack();

        $error_msg = "Update failed: " . $e->getMessage();

    }

}



// Fetch Gym Details

$stmtGym = $pdo->prepare("SELECT * FROM gyms WHERE gym_id = ?");

$stmtGym->execute([$gym_id]);

$gym = $stmtGym->fetch();



// Check Subscription

$stmtSub = $pdo->prepare("SELECT ws.plan_name FROM client_subscriptions cs JOIN website_plans ws ON cs.website_plan_id = ws.website_plan_id WHERE cs.gym_id = ? AND cs.subscription_status = 'Active' LIMIT 1");

$stmtSub->execute([$gym_id]);

$sub = $stmtSub->fetch();



// Fetch CMS Page

$stmtPage = $pdo->prepare("SELECT * FROM tenant_pages WHERE gym_id = ? LIMIT 1");

$stmtPage->execute([$gym_id]);

$page = $stmtPage->fetch();



// Fetch Staff Members

$stmtStaff = $pdo->prepare("

    SELECT s.*, u.first_name, u.middle_name, u.last_name, u.email, u.contact_number, u.username

    FROM staff s 

    JOIN users u ON s.user_id = u.user_id 

    WHERE s.gym_id = ? 

    ORDER BY s.created_at DESC

");

$stmtStaff->execute([$gym_id]);

$staff_members = $stmtStaff->fetchAll(PDO::FETCH_ASSOC);



$total_staff = count($staff_members);

$active_staff = 0;

foreach($staff_members as $s) {

    if($s['status'] === 'Active') $active_staff++;

}

?>

<!DOCTYPE html>

<html class="dark" lang="en">

<head>

    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>

    <title>Staff Management | Horizon Tenant</title>

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

        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; overflow: hidden; }

        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }

        .input-field { background: #1a1721; border: 1px solid #2d2838; border-radius: 12px; color: white; padding: 12px 16px; width: 100%; transition: all 0.2s; }

        .input-field:focus { border-color: #8c2bee; outline: none; box-shadow: 0 0 0 2px rgba(140, 43, 238, 0.2); }

        .nav-link { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; transition: all 0.2s; white-space: nowrap; }

        .active-nav { color: #8c2bee !important; position: relative; }

        .active-nav::after { content: ''; position: absolute; right: -32px; top: 50%; transform: translateY(-50%); width: 4px; height: 20px; background: #8c2bee; border-radius: 99px; }

        .no-scrollbar::-webkit-scrollbar { display: none; }

        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

    </style>

</head>

<body class="antialiased flex min-h-screen">



<nav class="flex flex-col w-64 lg:w-72 bg-[#0a090d] border-r border-white/5 sticky top-0 h-screen p-8 z-50 shrink-0">

    <div class="mb-12">

        <div class="flex items-center gap-3 mb-6">

            <div class="size-10 rounded-xl bg-primary flex items-center justify-center shadow-lg shrink-0 overflow-hidden">

                <?php if (!empty($page['logo_path'])): ?>

                    <img src="<?= $page['logo_path'] ?>" class="size-full object-contain">

                <?php else: ?>

                    <span class="material-symbols-outlined text-white text-2xl">bolt</span>

                <?php endif; ?>

            </div>

            <h1 class="text-lg font-black italic uppercase tracking-tighter text-white leading-none break-words line-clamp-2"><?= htmlspecialchars($page['page_title'] ?? $gym['gym_name']) ?></h1>

        </div>

        <div class="p-4 rounded-2xl bg-white/5 border border-white/5">

            <div class="mb-2">

                <p id="sidebarClock" class="text-white font-black italic text-base leading-none">00:00:00 AM</p>

            </div>

            <p class="text-[9px] font-black uppercase text-gray-500 tracking-[0.2em] leading-none mb-1"><?= date('l, M d') ?></p>

            <div class="pt-2 border-t border-white/5 mt-2">

                <p class="text-[8px] font-black uppercase text-gray-600 tracking-widest mb-1">Current Plan</p>

                <div class="flex items-center justify-between">

                    <p class="text-[10px] font-black uppercase text-white italic tracking-tighter"><?= htmlspecialchars($sub['plan_name'] ?? 'Standard Plan') ?></p>

                    <span class="px-2 py-0.5 rounded-md bg-primary/20 text-primary text-[8px] font-black uppercase tracking-widest">Active</span>

                </div>

            </div>

        </div>

    </div>

    

    <div class="flex flex-col gap-5 flex-1 overflow-y-auto no-scrollbar pr-2">

        <a href="tenant_dashboard.php" class="nav-link flex items-center gap-3 <?= ($active_page == 'dashboard') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">

            <span class="material-symbols-outlined text-xl">grid_view</span> Dashboard

        </a>

        <a href="tenant_settings.php" class="nav-link flex items-center gap-3 <?= ($active_page == 'settings') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">

            <span class="material-symbols-outlined text-xl">palette</span> Page Customize

        </a>

        <a href="staff_management.php" class="nav-link flex items-center gap-3 <?= ($active_page == 'staff') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">

            <span class="material-symbols-outlined text-xl">group</span> Staff Management

        </a>

    </div>



    <div class="mt-auto pt-8 border-t border-white/10 flex flex-col gap-5">

        <a href="#" class="text-gray-400 hover:text-white transition-colors flex items-center gap-3 group">

            <span class="material-symbols-outlined transition-transform group-hover:text-primary">person</span>

            <span class="nav-link">Profile</span>

        </a>

        <a href="../logout.php" class="text-gray-400 hover:text-red-500 transition-colors flex items-center gap-3 group">

            <span class="material-symbols-outlined group-hover:translate-x-1 transition-transform">logout</span>

            <span class="nav-link">Sign Out</span>

        </a>

    </div>

</nav>



<div class="flex-1 flex flex-col min-w-0 overflow-y-auto w-full max-w-[1400px] mx-auto p-6 md:p-10 no-scrollbar">



    <header class="mb-10 flex flex-col md:flex-row justify-between items-start md:items-end gap-6">

        <div>

            <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white">Staff <span class="text-primary">Management</span></h2>

            <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-1">Manage Gym Personnel & Accounts</p>

        </div>

        <div class="flex flex-wrap items-center gap-3 bg-white/5 p-3 rounded-2xl border border-white/5">

            <div class="relative">

                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 text-sm">search</span>

                <input type="text" id="staffSearch" placeholder="Search staff..." class="bg-[#0a090d] border border-white/5 rounded-lg text-[10px] font-bold py-2 pl-9 pr-4 focus:outline-none focus:border-primary text-white w-48 transition-colors">

            </div>

            <button onclick="openAddStaffModal()" class="bg-primary text-white px-4 py-2 rounded-lg text-[9px] font-black uppercase tracking-widest hover:opacity-90 transition-all flex items-center gap-2 shadow-lg shadow-primary/20">

                <span class="material-symbols-outlined text-sm">person_add</span> Add Staff

            </button>

        </div>

    </header>



    <?php if($success_msg): ?>

        <div class="mb-8 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-500 text-sm font-bold flex items-center gap-3">

            <span class="material-symbols-outlined">check_circle</span> <?= htmlspecialchars($success_msg) ?>

        </div>

    <?php endif; ?>



    <?php if($error_msg): ?>

        <div class="mb-8 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-sm font-bold flex items-center gap-3">

            <span class="material-symbols-outlined">error</span> <?= htmlspecialchars($error_msg) ?>

        </div>

    <?php endif; ?>



    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-10">

        <div class="glass-card p-6 border-l-4 border-primary/50 flex items-center gap-4 group transition-colors">

            <div class="size-12 rounded-full bg-primary/10 flex items-center justify-center text-primary">

                <span class="material-symbols-outlined text-2xl">shield_person</span>

            </div>

            <div>

                <p class="text-[10px] font-black uppercase text-gray-400 tracking-widest">Total Staff</p>

                <h3 class="text-2xl font-black italic uppercase"><?= $total_staff ?></h3>

            </div>

        </div>

        <div class="glass-card p-6 border-l-4 border-emerald-500/50 flex items-center gap-4 group transition-colors">

            <div class="size-12 rounded-full bg-emerald-500/10 flex items-center justify-center text-emerald-500">

                <span class="material-symbols-outlined text-2xl">how_to_reg</span>

            </div>

            <div>

                <p class="text-[10px] font-black uppercase text-emerald-500/70 tracking-widest">Active Personnel</p>

                <h3 class="text-2xl font-black italic uppercase text-emerald-400"><?= $active_staff ?></h3>

            </div>

        </div>

    </div>



    <div class="glass-card overflow-hidden">

        <div class="px-8 py-6 border-b border-white/5 bg-white/5 flex justify-between items-center">

            <h4 class="font-black italic uppercase text-sm tracking-tighter flex items-center gap-2">

                <span class="material-symbols-outlined text-primary">groups_2</span> Personnel Roster

            </h4>

        </div>

        <div class="overflow-x-auto">

            <table class="w-full text-left" id="staffTable">

                <thead>

                    <tr class="bg-black/20 text-gray-500 text-[10px] font-black uppercase tracking-widest border-b border-white/5">

                        <th class="px-8 py-4 whitespace-nowrap">Staff Member</th>

                        <th class="px-8 py-4 whitespace-nowrap">Assigned Role</th>

                        <th class="px-8 py-4 whitespace-nowrap">Employment Type</th>

                        <th class="px-8 py-4 whitespace-nowrap">Status</th>

                        <th class="px-8 py-4 text-right whitespace-nowrap">Actions</th>

                    </tr>

                </thead>

                <tbody class="divide-y divide-white/5">

                    <?php if (empty($staff_members)): ?>

                        <tr><td colspan="5" class="px-8 py-12 text-center text-gray-500 uppercase font-bold text-xs">No staff members found.</td></tr>

                    <?php else: ?>

                        <?php foreach ($staff_members as $s): ?>

                            <tr class="hover:bg-white/5 transition-all">

                                <td class="px-8 py-5">

                                    <div class="flex items-center gap-3">

                                        <div class="size-10 rounded-xl bg-primary/10 flex items-center justify-center font-black text-primary text-sm border border-primary/20">

                                            <?= strtoupper(substr($s['first_name'], 0, 1) . substr($s['last_name'], 0, 1)) ?>

                                        </div>

                                        <div>

                                            <p class="text-sm font-bold text-white"><?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?></p>

                                            <p class="text-[10px] text-gray-500">ID: STAFF-<?= str_pad($s['staff_id'], 4, '0', STR_PAD_LEFT) ?></p>

                                        </div>

                                    </div>

                                </td>

                                <td class="px-8 py-5">

                                    <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-white/5 border border-white/5">

                                        <span class="text-[10px] font-black uppercase tracking-widest text-gray-300"><?= htmlspecialchars($s['staff_role']) ?></span>

                                    </div>

                                </td>

                                <td class="px-8 py-5">

                                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest"><?= htmlspecialchars($s['employment_type']) ?></p>

                                    <p class="text-[9px] text-gray-600 mt-0.5">Hired: <?= date('M d, Y', strtotime($s['hire_date'])) ?></p>

                                </td>

                                <td class="px-8 py-5">

                                    <span class="text-[10px] font-black uppercase tracking-widest <?= $s['status'] === 'Active' ? 'text-emerald-400' : 'text-amber-500' ?>">

                                        <?= htmlspecialchars($s['status']) ?>

                                    </span>

                                </td>

                                <td class="px-8 py-5 text-right">

                                    <div class="inline-flex gap-2">

                                        <button onclick='openEditModal(<?= json_encode($s) ?>)' class="size-8 rounded-lg bg-white/5 hover:bg-white/10 border border-white/5 text-gray-400 flex items-center justify-center transition-colors">

                                            <span class="material-symbols-outlined text-[16px]">edit</span>

                                        </button>

                                        <form method="POST" class="inline-block" onsubmit="return confirm('Change status for this staff member?');">

                                            <input type="hidden" name="staff_id" value="<?= $s['staff_id'] ?>">

                                            <input type="hidden" name="user_id" value="<?= $s['user_id'] ?>">

                                            <input type="hidden" name="first_name" value="<?= $s['first_name'] ?>">

                                            <input type="hidden" name="last_name" value="<?= $s['last_name'] ?>">

                                            <input type="hidden" name="email" value="<?= $s['email'] ?>">

                                            <input type="hidden" name="staff_role" value="<?= $s['staff_role'] ?>">

                                            <input type="hidden" name="employment_type" value="<?= $s['employment_type'] ?>">

                                            <input type="hidden" name="status" value="<?= $s['status'] === 'Active' ? 'Suspended' : 'Active' ?>">

                                            <button type="submit" name="update_staff" class="size-8 rounded-lg <?= $s['status'] === 'Active' ? 'bg-amber-500/10 text-amber-500' : 'bg-emerald-500/10 text-emerald-400' ?> border border-white/5 flex items-center justify-center">

                                                <span class="material-symbols-outlined text-[16px]"><?= $s['status'] === 'Active' ? 'pause_circle' : 'play_circle' ?></span>

                                            </button>

                                        </form>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>



<div id="addStaffModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4">

    <div class="fixed inset-0 bg-black/60 backdrop-blur-md" onclick="closeAddStaffModal()"></div>

    <div class="relative w-full max-w-2xl bg-[#121017] border border-white/10 shadow-2xl rounded-[32px] overflow-hidden p-8 md:p-12">

        <header class="mb-10 text-center">

            <h1 class="text-3xl font-black italic uppercase tracking-tighter mb-2">Register <span class="text-primary">Staff</span></h1>

        </header>

        <form method="POST" class="space-y-6">

            <input type="hidden" name="add_staff" value="1">

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

                <input type="text" name="first_name" class="input-field" placeholder="First Name" required>

                <input type="text" name="middle_name" class="input-field" placeholder="Middle Name">

                <input type="text" name="last_name" class="input-field" placeholder="Last Name" required>

            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                <input type="email" name="email" class="input-field" placeholder="Email Address" required>

                <input type="text" name="contact_number" class="input-field" placeholder="Contact Number">

            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                <input type="text" name="username" class="input-field" placeholder="Username" required>

                <input type="password" name="password" class="input-field" placeholder="Password (Optional)">

            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                <select name="staff_role" class="input-field">

                    <option value="Staff">General Staff</option>

                    <option value="Coach">Coach</option>

                </select>

                <select name="employment_type" class="input-field">

                    <option value="Full-time">Full-time</option>

                    <option value="Part-time">Part-time</option>

                </select>

            </div>

            <div class="flex gap-4 pt-6">

                <button type="submit" class="flex-1 h-14 rounded-2xl bg-primary text-white font-black uppercase text-xs tracking-widest">Confirm Registration</button>

                <button type="button" onclick="closeAddStaffModal()" class="px-8 rounded-2xl bg-white/5 border border-white/10 text-xs font-black uppercase">Cancel</button>

            </div>

        </form>

    </div>

</div>



<div id="editStaffModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4">

    <div class="fixed inset-0 bg-black/60 backdrop-blur-md" onclick="closeEditModal()"></div>

    <div class="relative w-full max-w-2xl bg-[#121017] border border-white/10 shadow-2xl rounded-[32px] overflow-hidden p-8 md:p-12">

        <header class="mb-10 text-center">

            <h1 class="text-3xl font-black italic uppercase tracking-tighter mb-2">Update <span class="text-primary">Staff</span></h1>

        </header>

        <form method="POST" class="space-y-6">

            <input type="hidden" name="update_staff" value="1">

            <input type="hidden" name="staff_id" id="edit_staff_id">

            <input type="hidden" name="user_id" id="edit_user_id">

            

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                <input type="text" name="first_name" id="edit_first_name" class="input-field" placeholder="First Name" required>

                <input type="text" name="last_name" id="edit_last_name" class="input-field" placeholder="Last Name" required>

            </div>

            <input type="email" name="email" id="edit_email" class="input-field" placeholder="Email Address" required>

            

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

                <select name="staff_role" id="edit_staff_role" class="input-field">

                    <option value="Staff">Staff</option>

                    <option value="Coach">Coach</option>

                    <option value="Manager">Manager</option>

                </select>

                <select name="employment_type" id="edit_employment_type" class="input-field">

                    <option value="Full-time">Full-time</option>

                    <option value="Part-time">Part-time</option>

                </select>

                <select name="status" id="edit_status" class="input-field">

                    <option value="Active">Active</option>

                    <option value="Suspended">Suspended</option>

                    <option value="Resigned">Resigned</option>

                </select>

            </div>

            <div class="flex gap-4 pt-6">

                <button type="submit" class="flex-1 h-14 rounded-2xl bg-primary text-white font-black uppercase text-xs tracking-widest">Save Changes</button>

                <button type="button" onclick="closeEditModal()" class="px-8 rounded-2xl bg-white/5 border border-white/10 text-xs font-black uppercase">Cancel</button>

            </div>

        </form>

    </div>

</div>



<script>

    function updateSidebarClock() {

        const now = new Date();

        const clockEl = document.getElementById('sidebarClock');

        if (clockEl) clockEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });

    }

    setInterval(updateSidebarClock, 1000);



    // Search Logic

    document.getElementById('staffSearch').addEventListener('keyup', function() {

        let filter = this.value.toUpperCase();

        let rows = document.querySelector("#staffTable tbody").rows;

        for (let i = 0; i < rows.length; i++) {

            let name = rows[i].cells[0].textContent.toUpperCase();

            rows[i].style.display = name.includes(filter) ? "" : "none";

        }

    });



    function openAddStaffModal() { document.getElementById('addStaffModal').classList.replace('hidden', 'flex'); }

    function closeAddStaffModal() { document.getElementById('addStaffModal').classList.replace('flex', 'hidden'); }



    function openEditModal(staff) {

        document.getElementById('edit_staff_id').value = staff.staff_id;

        document.getElementById('edit_user_id').value = staff.user_id;

        document.getElementById('edit_first_name').value = staff.first_name;

        document.getElementById('edit_last_name').value = staff.last_name;

        document.getElementById('edit_email').value = staff.email;

        document.getElementById('edit_staff_role').value = staff.staff_role;

        document.getElementById('edit_employment_type').value = staff.employment_type;

        document.getElementById('edit_status').value = staff.status;

        document.getElementById('editStaffModal').classList.replace('hidden', 'flex');

    }

    function closeEditModal() { document.getElementById('editStaffModal').classList.replace('flex', 'hidden'); }

</script>

</body>

</html>