<?php
session_start();
require_once '../db.php';

// Security Check
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'tenant') {
    header("Location: ../login.php");
    exit;
}

$gym_id = $_SESSION['gym_id'] ?? null;
$user_id = $_SESSION['user_id'] ?? null;

if (!$user_id || !$gym_id) {
    header("Location: ../login.php");
    exit;
}

// Fetch Gym & Owner Details (gym_name and owner's name)
$stmtGym = $pdo->prepare("
    SELECT g.gym_name, u.first_name, u.last_name, g.owner_user_id
    FROM gyms g 
    JOIN users u ON g.owner_user_id = u.user_id 
    WHERE g.gym_id = ?
");
$stmtGym->execute([$gym_id]);
$gym_data = $stmtGym->fetch();

$gym_name = $gym_data['gym_name'] ?? 'Horizon Gym';
$first_name = $gym_data['first_name'] ?? 'Owner';
$owner_user_id = $gym_data['owner_user_id'] ?? 0;
$active_page = "users";

// Fetch Branding Data from tenant_pages
$stmtPage = $pdo->prepare("SELECT * FROM tenant_pages WHERE gym_id = ?");
$stmtPage->execute([$gym_id]);
$page = $stmtPage->fetch();

// Set default fallback values
$theme_color = ($page && isset($page['theme_color'])) ? $page['theme_color'] : '#8c2bee';
$bg_color = ($page && isset($page['bg_color'])) ? $page['bg_color'] : '#0a090d';

// Fetch Active Subscription / Plan
$stmtSub = $pdo->prepare("
    SELECT cs.subscription_status, wp.plan_name 
    FROM client_subscriptions cs 
    JOIN website_plans wp ON cs.website_plan_id = wp.website_plan_id 
    WHERE cs.gym_id = ? 
    ORDER BY cs.created_at DESC LIMIT 1
");
$stmtSub->execute([$gym_id]);
$subscription = $stmtSub->fetch();

$plan_name = $subscription['plan_name'] ?? 'No Plan';
$sub_status = $subscription['subscription_status'] ?? 'None';

// --- AJAX USER PROFILE FETCH (View Details) ---
if (isset($_GET['ajax_user_id'])) {
    $uid = (int) $_GET['ajax_user_id'];

    // Determine role to join correct detail tables
    $stmtRoleCheck = $pdo->prepare("SELECT r.role_name FROM user_roles ur JOIN roles r ON ur.role_id = r.role_id WHERE ur.user_id = ? AND ur.gym_id = ? LIMIT 1");
    $stmtRoleCheck->execute([$uid, $gym_id]);
    $role_name = strtolower($stmtRoleCheck->fetchColumn() ?: '');

    $sql = "SELECT u.*, r.role_name as role, ur.role_status ";
    if ($role_name === 'member') {
        $sql .= ", m.member_code, m.birth_date, m.sex, m.occupation, m.address, m.medical_history, m.emergency_contact_name, m.emergency_contact_number ";
        $sql .= " FROM users u JOIN user_roles ur ON u.user_id = ur.user_id JOIN roles r ON ur.role_id = r.role_id LEFT JOIN members m ON u.user_id = m.user_id ";
    } elseif ($role_name === 'staff') {
        $sql .= ", s.staff_role, s.employment_type, s.hire_date, s.status as staff_status ";
        $sql .= " FROM users u JOIN user_roles ur ON u.user_id = ur.user_id JOIN roles r ON ur.role_id = r.role_id LEFT JOIN staff s ON u.user_id = s.user_id AND s.gym_id = ur.gym_id ";
    } elseif ($role_name === 'coach') {
        $sql .= ", c.coach_type as employment_type, c.specialization as staff_role, c.hire_date, c.status as staff_status ";
        $sql .= " FROM users u JOIN user_roles ur ON u.user_id = ur.user_id JOIN roles r ON ur.role_id = r.role_id LEFT JOIN coaches c ON u.user_id = c.user_id AND c.gym_id = ur.gym_id ";
    } else {
        $sql .= " FROM users u JOIN user_roles ur ON u.user_id = ur.user_id JOIN roles r ON ur.role_id = r.role_id ";
    }
    $sql .= " WHERE u.user_id = ? AND ur.gym_id = ? LIMIT 1";

    $stmtUser = $pdo->prepare($sql);
    $stmtUser->execute([$uid, $gym_id]);
    $u = $stmtUser->fetch();

    if ($u): ?>
        <div class="space-y-10 animate-in fade-in slide-in-from-bottom-6 duration-500 pb-10">
            <header class="flex justify-between items-start border-b border-white/5 pb-10">
                <div class="flex items-center gap-8">
                    <div
                        class="size-24 rounded-[32px] bg-primary/10 border-2 border-primary/20 flex items-center justify-center text-primary font-black italic text-4xl uppercase">
                        <?= substr($u['first_name'], 0, 1) ?>
                    </div>
                    <div>
                        <div class="flex items-center gap-4 mb-2">
                            <h2 class="text-4xl font-black italic uppercase tracking-tighter text-white leading-none">
                                <?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?>
                            </h2>
                        </div>
                        <div class="flex items-center gap-4">
                            <span class="px-4 py-1.5 rounded-full bg-primary/10 border border-primary/20 text-[10px] text-primary font-black uppercase italic tracking-[0.1em]"><?= $u['role'] ?></span>
                        </div>
                    </div>
                </div>
                <button onclick="closeUserModal()"
                    class="size-12 rounded-2xl bg-white/5 hover:bg-white/10 flex items-center justify-center text-gray-400 group transition-all">
                    <span class="material-symbols-outlined text-2xl group-hover:rotate-90 transition-transform">close</span>
                </button>
            </header>

            <div class="grid grid-cols-1 xl:grid-cols-3 gap-10">
                <div class="xl:col-span-2 space-y-10">
                    <!-- Section 1: Personal Information -->
                    <section class="glass-card p-8 border border-white/5">
                        <h4 class="text-base font-black italic uppercase tracking-tighter mb-8 flex items-center gap-3 text-primary">
                            <span class="material-symbols-outlined bg-primary/10 p-2 rounded-xl text-xl">person</span> Personal Information
                        </h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            <div class="space-y-1">
                                <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest ml-1">Username Alias</p>
                                <p class="text-sm font-black text-white italic">@<?= htmlspecialchars($u['username']) ?></p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest ml-1">First Name</p>
                                <p class="text-sm font-black text-white italic uppercase"><?= htmlspecialchars($u['first_name']) ?></p>
                            </div>
                            <?php if ($role_name === 'member'): ?>
                            <div class="space-y-1">
                                <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest ml-1">Middle Name</p>
                                <p class="text-sm font-black text-white italic uppercase"><?= htmlspecialchars($u['middle_name'] ?: 'N/A') ?></p>
                            </div>
                            <?php endif; ?>
                            <div class="space-y-1">
                                <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest ml-1">Last Name</p>
                                <p class="text-sm font-black text-white italic uppercase"><?= htmlspecialchars($u['last_name']) ?></p>
                            </div>
                            <?php if ($role_name === 'member'): ?>
                            <div class="space-y-1">
                                <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest ml-1">Sex</p>
                                <p class="text-sm font-black text-white italic uppercase"><?= $u['sex'] ?: 'N/A' ?></p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest ml-1">Birth Date</p>
                                <p class="text-sm font-black text-white italic"><?= $u['birth_date'] ? date('M d, Y', strtotime($u['birth_date'])) : 'N/A' ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </section>

                    <!-- Section 2: Contact Information -->
                    <section class="glass-card p-8 border border-white/5">
                        <h4 class="text-base font-black italic uppercase tracking-tighter mb-8 flex items-center gap-3 text-primary">
                            <span class="material-symbols-outlined bg-primary/10 p-2 rounded-xl text-xl">alternate_email</span> Contact Information
                        </h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="space-y-1">
                                <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest ml-1">Email Address</p>
                                <p class="text-sm font-black text-white italic truncate"><?= htmlspecialchars($u['email']) ?></p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest ml-1">Contact Number</p>
                                <p class="text-sm font-black text-white italic tracking-widest"><?= htmlspecialchars($u['contact_number'] ?: 'UNKNOWN') ?></p>
                            </div>
                            <?php if ($role_name === 'member' && !empty($u['address'])): ?>
                            <div class="space-y-1 md:col-span-2">
                                <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest ml-1">Home Address</p>
                                <p class="text-sm font-black text-white italic"><?= htmlspecialchars($u['address']) ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </section>

                    <?php if ($role_name === 'member'): ?>
                    <!-- Section 3: Health & Profile -->
                    <section class="glass-card p-8 border border-white/5">
                        <h4 class="text-base font-black italic uppercase tracking-tighter mb-8 flex items-center gap-3 text-primary">
                            <span class="material-symbols-outlined bg-primary/10 p-2 rounded-xl text-xl">medical_information</span> Health & Profile
                        </h4>
                        <div class="space-y-6">
                            <div class="space-y-1">
                                <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest ml-1">Occupation</p>
                                <p class="text-sm font-black text-white italic uppercase"><?= htmlspecialchars($u['occupation'] ?: 'N/A') ?></p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest ml-1">Medical History</p>
                                <div class="text-sm font-medium text-gray-400 italic leading-relaxed bg-black/20 p-6 rounded-2xl border border-white/5">
                                    <?= nl2br(htmlspecialchars($u['medical_history'] ?: 'No physical medical history recorded.')) ?>
                                </div>
                            </div>
                        </div>
                    </section>
                    <?php endif; ?>

                    <?php if ($role_name === 'staff' || $role_name === 'coach'): ?>
                    <!-- Section 3: Professional Registry -->
                    <section class="glass-card p-8 border border-white/5">
                        <h4 class="text-base font-black italic uppercase tracking-tighter mb-8 flex items-center gap-3 text-primary">
                            <span class="material-symbols-outlined bg-primary/10 p-2 rounded-xl text-xl">badge</span> Professional Profile
                        </h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            <div class="space-y-1">
                                <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest ml-1">System Role</p>
                                <p class="text-sm font-black text-white italic uppercase"><?= $u['staff_role'] ?: $u['role'] ?></p>
                            </div>
                             <div class="space-y-1">
                                <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest ml-1">Class</p>
                                <p class="text-sm font-black text-white italic uppercase"><?= $u['employment_type'] ?: 'N/A' ?></p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest ml-1">Registry Date</p>
                                <p class="text-sm font-black text-white italic"><?= $u['hire_date'] ? date('M d, Y', strtotime($u['hire_date'])) : 'N/A' ?></p>
                            </div>
                        </div>
                    </section>
                    <?php endif; ?>
                </div>

                <div class="space-y-10">
                    <!-- Sidebar section: Auth Status -->
                    <section class="glass-card p-8 border border-white/5">
                        <h4 class="text-[10px] font-black uppercase text-primary tracking-[0.3em] border-l-4 border-primary pl-4 mb-6">Security Node</h4>
                        <div class="p-8 rounded-[32px] bg-gradient-to-br from-white/5 to-transparent border border-white/10">
                            <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-3">Auth Protocol</p>
                            <div class="flex items-center gap-4">
                                <span class="size-3 rounded-full <?= $u['is_active'] ? 'bg-emerald-500 animate-pulse' : 'bg-red-500' ?>"></span>
                                <p class="text-sm font-black uppercase italic tracking-widest <?= $u['is_active'] ? 'text-emerald-500' : 'text-red-500' ?>">
                                    <?= $u['is_active'] ? 'Authorized' : 'Restricted' ?>
                                </p>
                            </div>
                        </div>
                    </section>

                    <?php if ($role_name === 'member' || !empty($u['emergency_contact_name'])): ?>
                    <!-- Sidebar section: Emergency Support -->
                    <section class="glass-card p-8 border border-white/5">
                        <h4 class="text-[10px] font-black uppercase text-amber-500 tracking-[0.3em] border-l-4 border-amber-500 pl-4 mb-6">Emergency Protocol</h4>
                        <div class="bg-amber-500/[0.03] p-8 rounded-[32px] border border-amber-500/10">
                            <div class="space-y-6">
                                <div>
                                    <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-2">Primary Contact</p>
                                    <p class="text-base font-black text-white italic uppercase"><?= htmlspecialchars($u['emergency_contact_name'] ?: 'NOT LISTED') ?></p>
                                </div>
                                <div>
                                    <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-2">Contact Signal</p>
                                    <p class="text-base font-bold text-amber-500 tracking-widest"><?= htmlspecialchars($u['emergency_contact_number'] ?: 'OFFLINE') ?></p>
                                </div>
                            </div>
                        </div>
                    </section>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif;
    exit;
}

// --- FILTERING LOGIC ---
$search = $_GET['search'] ?? '';
$filter_role = $_GET['role'] ?? ''; // From Tabs or Switcher
$filter_status = $_GET['status'] ?? '';
$sort_by = $_GET['sort'] ?? 'newest';

// Base Query parts
$where = ["ur.gym_id = :gym_id"];
$params = [':gym_id' => $gym_id];

if (!empty($search)) {
    $where[] = "(u.first_name LIKE :s1 OR u.last_name LIKE :s2 OR u.email LIKE :s3)";
    $params[':s1'] = "%$search%";
    $params[':s2'] = "%$search%";
    $params[':s3'] = "%$search%";
}

if (!empty($filter_role)) {
    $where[] = "r.role_name = :role";
    $params[':role'] = $filter_role;
} else {
    // Default to Members, Coaches, and Staff
    $where[] = "r.role_name IN ('Member', 'Coach', 'Staff')";
}

if ($filter_status !== '') {
    $where[] = "u.is_active = :status";
    $params[':status'] = (int) $filter_status;
}

$order = "ORDER BY u.created_at DESC";
if ($sort_by === 'oldest')
    $order = "ORDER BY u.created_at ASC";
if ($sort_by === 'name_asc')
    $order = "ORDER BY u.first_name ASC";

$where_sql = "WHERE " . implode(" AND ", $where);

// Fetch Filtered Users
$stmtUsers = $pdo->prepare("
    SELECT u.user_id, u.first_name, u.last_name, u.email, r.role_name as role, u.is_active, u.created_at,
           CASE WHEN r.role_name = 'Member' THEN m.member_status ELSE c.status END as active_status,
           CASE WHEN r.role_name = 'Member' THEN IFNULL(mp.plan_name, 'No Plan') ELSE c.specialization END as detail_info
    FROM users u
    JOIN user_roles ur ON u.user_id = ur.user_id
    JOIN roles r ON ur.role_id = r.role_id
    LEFT JOIN members m ON u.user_id = m.user_id
    LEFT JOIN coaches c ON u.user_id = c.user_id
    LEFT JOIN member_subscriptions ms ON m.member_id = ms.member_id AND ms.subscription_status = 'Active'
    LEFT JOIN membership_plans mp ON ms.membership_plan_id = mp.membership_plan_id
    $where_sql
    $order
");
$stmtUsers->execute($params);
$users_list = $stmtUsers->fetchAll();

// Statistics (Unfiltered)
$total_members = $pdo->query("SELECT COUNT(*) FROM members WHERE gym_id = $gym_id")->fetchColumn();
$total_coaches = $pdo->query("SELECT COUNT(*) FROM coaches WHERE gym_id = $gym_id")->fetchColumn();

$page_title = "User Database";
?>

<!DOCTYPE html>
<html class="dark" lang="en">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title><?= $page_title ?> | <?= $gym_name ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap"
        rel="stylesheet" />
    <link
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200"
        rel="stylesheet" />
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "var(--primary)",
                        "background-dark": "var(--background)",
                        "surface-dark": "#14121a",
                        "border-subtle": "rgba(255,255,255,0.05)"
                    }
                }
            }
        }
    </script>

    <style>
        :root { 
            --nav-width: 110px;
            --primary: <?= $theme_color ?>;
            --background: <?= $bg_color ?>;
        }
        body:has(.side-nav:hover) { --nav-width: 300px; }

        body { font-family: 'Lexend', sans-serif; background-color: var(--background); color: white; overflow: hidden; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); }
        .hover-lift:hover { transform: translateY(-5px); border-color: var(--primary); }

        /* Sidebar: Dynamic Logic */
        .side-nav { width: var(--nav-width); transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1); overflow: hidden; display: flex; flex-direction: column; position: fixed; left: 0; top: 0; height: 100vh; z-index: 250; background: var(--background); border-right: 1px solid rgba(255,255,255,0.05); }
        .main-content { margin-left: var(--nav-width); flex: 1; min-width: 0; transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1); }

        .nav-label { opacity: 0; transform: translateX(-15px); transition: all 0.3s ease-in-out; white-space: nowrap; pointer-events: none; }
        .side-nav:hover .nav-label { opacity: 1; transform: translateX(0); pointer-events: auto; }
        
        .nav-section-label { max-height: 0; opacity: 0; overflow: hidden; transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); margin: 0 !important; pointer-events: none; }
        .side-nav:hover .nav-section-label { max-height: 20px; opacity: 1; margin-bottom: 8px !important; pointer-events: auto; }
        
        .nav-item { display: flex; align-items: center; gap: 16px; padding: 10px 38px; transition: all 0.2s ease; text-decoration: none; white-space: nowrap; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; }
        .nav-item:hover { background: rgba(255, 255, 255, 0.05); color: white; }
        .nav-item.active { color: var(--primary) !important; position: relative; }
        .nav-item.active::after { content: ''; position: absolute; right: 0px; top: 50%; transform: translateY(-50%); width: 4px; height: 24px; background: var(--primary); border-radius: 4px 0 0 4px; }
        
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        /* Inputs */
        .input-box { background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 12px; color: white; padding: 10px 16px; font-size: 11px; font-weight: 500; outline: none; transition: all 0.2s; }
        .input-box:focus { border-color: var(--primary); background: rgba(255, 255, 255, 0.08); }
        .input-box option { background: #14121a; color: white; }
        select.input-box { cursor: pointer; color-scheme: dark; appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='white'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 0.75rem center; background-size: 0.85em; padding-right: 2.5rem; }

        /* Profile Modal: Exactly like Coach Portal */
        #profileModal { position: fixed; inset: 0; z-index: 200; display: none; align-items: center; justify-content: center; padding: 24px; padding-left: calc(var(--nav-width) + 24px); transition: padding-left 0.4s cubic-bezier(0.4, 0, 0.2, 1); }
        .modal-backdrop { position: absolute; inset: 0; left: var(--nav-width); background: rgba(0, 0, 0, 0.8); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); transition: left 0.4s cubic-bezier(0.4, 0, 0.2, 1); }
        .modal-container { position: relative; width: 100%; max-width: 1000px; max-height: 90vh; overflow-y: auto; z-index: 10; scrollbar-width: none; }
        .modal-container::-webkit-scrollbar { display: none; }
    </style>

    <script>
        function updateTopClock() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('en-US', {
                hour: '2-digit', minute: '2-digit', second: '2-digit'
            });
            const clockEl = document.getElementById('topClock');
            if (clockEl) clockEl.textContent = timeString;
        }
        setInterval(updateTopClock, 1000);
        window.addEventListener('DOMContentLoaded', updateTopClock);

        let filterTimeout;
        function reactiveFilter() {
            clearTimeout(filterTimeout);
            filterTimeout = setTimeout(() => {
                const form = document.getElementById('filterForm');
                const formData = new FormData(form);
                const params = new URLSearchParams(formData);
                const newUrl = `${window.location.pathname}?${params.toString()}`;
                window.history.replaceState({}, '', newUrl);

                fetch(newUrl)
                    .then(res => res.text())
                    .then(html => {
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(html, 'text/html');
                        document.getElementById('tableContainer').innerHTML = doc.getElementById('tableContainer').innerHTML;
                    });
            }, 300);
        }

        async function viewUserProfile(id) {
            const modal = document.getElementById('profileModal');
            const content = document.getElementById('modalContent');
            document.body.classList.add('modal-open');
            modal.style.display = 'flex';
            content.innerHTML = '<div class="flex items-center justify-center p-20"><div class="size-12 border-4 border-primary/20 border-t-primary rounded-full animate-spin"></div></div>';

            try {
                const res = await fetch(`?ajax_user_id=${id}`);
                content.innerHTML = await res.text();
            } catch (e) { content.innerHTML = '<p class="p-10 text-rose-500 font-bold">Failed to load profile.</p>'; }
        }

        function closeUserModal() {
            const modal = document.getElementById('profileModal');
            document.body.classList.remove('modal-open');
            modal.style.display = 'none';
        }
    </script>
</head>

<body class="antialiased flex h-screen overflow-hidden">

    <nav class="side-nav bg-background-dark border-r border-white/5 z-50">
        <div class="px-7 py-8 mb-4 shrink-0">
            <div class="flex items-center gap-4">
                <div class="size-10 rounded-xl shrink-0 overflow-hidden flex items-center justify-center <?= empty($page['logo_path']) ? 'bg-primary shadow-lg shadow-primary/20' : '' ?>">
                    <?php if (!empty($page['logo_path'])): ?>
                        <img src="<?= htmlspecialchars($page['logo_path']) ?>" class="size-full object-cover">
                    <?php else: ?>
                        <span class="material-symbols-outlined text-white text-2xl">bolt</span>
                    <?php endif; ?>
                </div>
                <h1 class="nav-label text-lg font-black italic uppercase tracking-tighter text-white">Owner Portal</h1>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto no-scrollbar space-y-1">
            <div class="nav-section-label px-[38px] mb-2"><span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Main Menu</span></div>
            <a href="tenant_dashboard.php" class="nav-item <?= ($active_page == 'dashboard') ? 'active' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">grid_view</span>
                <span class="nav-label">Dashboard</span>
            </a>
            
            <a href="my_users.php" class="nav-item <?= ($active_page == 'users' || $active_page == 'my_users') ? 'active' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">group</span>
                <span class="nav-label">Users</span>
            </a>

            <a href="transactions.php" class="nav-item <?= ($active_page == 'transactions') ? 'active' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">receipt_long</span>
                <span class="nav-label">Transactions</span>
            </a>

            <a href="attendance.php" class="nav-item <?= ($active_page == 'attendance') ? 'active' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">history</span>
                <span class="nav-label">Attendance</span>
            </a>

            <div class="nav-section-label px-[38px] mb-2 mt-6"><span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Management</span></div>

            <a href="staff.php" class="nav-item <?= ($active_page == 'staff') ? 'active' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">badge</span>
                <span class="nav-label">Staff</span>
            </a>

            <a href="reports.php" class="nav-item <?= ($active_page == 'reports') ? 'active' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">analytics</span>
                <span class="nav-label">Reports</span>
            </a>

            <a href="sales_report.php" class="nav-item <?= ($active_page == 'sales') ? 'active' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">payments</span>
                <span class="nav-label">Sales Reports</span>
            </a>
        </div>

        <div class="mt-auto pt-4 border-t border-white/10 shrink-0 pb-6">
            <div class="nav-section-label px-[38px] mb-2"><span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Account</span></div>
            <a href="tenant_settings.php" class="nav-item <?= ($active_page == 'settings') ? 'active' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">settings</span>
                <span class="nav-label">Settings</span>
            </a>
            <a href="profile.php" class="nav-item <?= ($active_page == 'profile') ? 'active' : '' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">account_circle</span>
                <span class="nav-label">Profile</span>
            </a>
            <a href="../logout.php" class="nav-item text-gray-400 hover:text-rose-500 transition-colors">
                <span class="material-symbols-outlined text-xl shrink-0">logout</span>
                <span class="nav-label">Sign Out</span>
            </a>
        </div>
    </nav>

    <main class="main-content flex-1 p-10 overflow-y-auto no-scrollbar">

        <header class="mb-10 flex justify-between items-end">
            <div>
                <h2 class="text-3xl font-black uppercase tracking-tighter text-white italic">User <span
                        class="text-primary italic">Database</span></h2>
                <p class="text-gray-500 text-[10px] font-black uppercase tracking-widest mt-1 italic">
                    <?= htmlspecialchars($gym_name) ?> Community Roster</p>
            </div>

            <div class="flex items-center gap-8">
                <a href="profile.php" class="hidden md:flex items-center gap-2.5 px-6 py-3 rounded-2xl bg-primary/10 border border-primary/20 text-primary text-[10px] font-black uppercase italic tracking-widest hover:bg-primary hover:text-white transition-all active:scale-95 group">
                    <span class="material-symbols-outlined text-lg group-hover:scale-110 transition-transform">account_circle</span>
                    My Profile
                </a>
                <div class="text-right">
                    <p id="topClock" class="text-white font-black italic text-2xl leading-none tracking-tighter">00:00:00 AM</p>
                    <p class="text-primary text-[10px] font-bold uppercase tracking-widest mt-2 px-1 opacity-80"><?= date('l, M d, Y') ?></p>
                </div>
            </div>
        </header>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">
            <div class="glass-card p-6 flex items-center gap-5 relative overflow-hidden group">
                <div
                    class="size-12 rounded-2xl bg-primary/10 flex items-center justify-center text-primary shrink-0 transition-transform group-hover:scale-110">
                    <span class="material-symbols-outlined text-2xl">group</span>
                </div>
                <div>
                    <p class="text-[10px] font-bold uppercase text-gray-500 tracking-widest">Total Community</p>
                    <h3 class="text-2xl font-extrabold mt-1 tracking-tight italic uppercase">
                        <?= $total_members + $total_coaches ?></h3>
                </div>
                <div class="absolute -right-4 -bottom-4 size-24 bg-primary/5 rounded-full blur-2xl"></div>
            </div>

            <div class="glass-card p-6 flex items-center gap-5 relative overflow-hidden group">
                <div
                    class="size-12 rounded-2xl bg-emerald-500/10 flex items-center justify-center text-emerald-500 shrink-0 transition-transform group-hover:scale-110">
                    <span class="material-symbols-outlined text-2xl">how_to_reg</span>
                </div>
                <div>
                    <p class="text-[10px] font-bold uppercase text-gray-500 tracking-widest">Active Members</p>
                    <h3 class="text-2xl font-extrabold mt-1 tracking-tight italic uppercase"><?= $total_members ?></h3>
                </div>
                <div class="absolute -right-4 -bottom-4 size-24 bg-emerald-500/5 rounded-full blur-2xl"></div>
            </div>

            <div class="glass-card p-6 flex items-center gap-5 relative overflow-hidden group">
                <div
                    class="size-12 rounded-2xl bg-amber-500/10 flex items-center justify-center text-amber-500 shrink-0 transition-transform group-hover:scale-110">
                    <span class="material-symbols-outlined text-2xl">workspace_premium</span>
                </div>
                <div>
                    <p class="text-[10px] font-bold uppercase text-gray-500 tracking-widest">Expert Coaches</p>
                    <h3 class="text-2xl font-extrabold mt-1 tracking-tight italic uppercase"><?= $total_coaches ?></h3>
                </div>
                <div class="absolute -right-4 -bottom-4 size-24 bg-amber-500/5 rounded-full blur-2xl"></div>
            </div>
        </div>

        <!-- ADVANCED FILTERS -->
        <div class="mb-10">
            <form id="filterForm" onsubmit="event.preventDefault(); reactiveFilter();"
                class="glass-card p-8 border border-white/5 relative overflow-hidden">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6">
                    <div class="space-y-2 lg:col-span-2">
                        <p class="text-[9px] font-black uppercase tracking-widest text-primary ml-1">Search</p>
                        <div class="relative">
                            <span
                                class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-lg">search</span>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                                placeholder="Filter user registry..." oninput="reactiveFilter()"
                                class="input-box pl-12 w-full">
                        </div>
                    </div>
                    <div class="space-y-2">
                        <p class="text-[9px] font-black uppercase tracking-widest text-gray-400 ml-1">Authority Filter
                        </p>
                        <select name="role" onchange="reactiveFilter()" class="input-box w-full">
                            <option value="">All Roles</option>
                            <option value="Member" <?= ($filter_role == 'Member') ? 'selected' : '' ?>>Members</option>
                            <option value="Staff" <?= ($filter_role == 'Staff') ? 'selected' : '' ?>>Staff</option>
                            <option value="Coach" <?= ($filter_role == 'Coach') ? 'selected' : '' ?>>Coach</option>
                        </select>
                    </div>
                    <div class="space-y-2">
                        <p class="text-[9px] font-black uppercase tracking-widest text-gray-400 ml-1">Account Protocol
                        </p>
                        <select name="status" onchange="reactiveFilter()" class="input-box w-full">
                            <option value="">All Status</option>
                            <option value="1" <?= ($filter_status == '1') ? 'selected' : '' ?>>Active</option>
                            <option value="0" <?= ($filter_status == '0') ? 'selected' : '' ?>>Restricted</option>
                        </select>
                    </div>
                    <div class="space-y-2">
                        <p class="text-[9px] font-black uppercase tracking-widest text-gray-400 ml-1">Sort Registry</p>
                        <select name="sort" onchange="reactiveFilter()" class="input-box w-full">
                            <option value="newest" <?= ($sort_by == 'newest') ? 'selected' : '' ?>>Newest</option>
                            <option value="oldest" <?= ($sort_by == 'oldest') ? 'selected' : '' ?>>Oldest</option>
                            <option value="name_asc" <?= ($sort_by == 'name_asc') ? 'selected' : '' ?>>Name A-Z</option>
                        </select>
                    </div>
                </div>
            </form>
        </div>

        <div id="tableContainer" class="glass-card overflow-hidden">
            <table class="w-full text-left">
                <thead>
                    <tr
                        class="bg-black/20 text-[10px] font-black uppercase tracking-widest text-gray-500 border-b border-white/5">
                        <th class="px-8 py-5">Individual</th>
                        <th class="px-8 py-5">Role / Protocol</th>
                        <th class="px-8 py-5">Detail Info</th>
                        <th class="px-8 py-5 text-right">System Access</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php if (empty($users_list)): ?>
                        <tr>
                            <td colspan="4"
                                class="px-8 py-10 text-center text-gray-500 text-[10px] font-bold uppercase tracking-widest italic">
                                No matching users found in registry</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users_list as $u): ?>
                            <tr class="group hover:bg-white/[0.02] transition-colors">
                                <td class="px-8 py-6 flex items-center gap-4">
                                    <div
                                        class="size-10 rounded-full bg-primary/10 border border-primary/20 flex items-center justify-center text-primary font-black italic text-xs">
                                        <?= strtoupper(substr($u['first_name'] ?? 'U', 0, 1)) ?>
                                    </div>
                                    <div>
                                        <p class="text-sm font-extrabold italic uppercase tracking-tighter">
                                            <?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?></p>
                                        <p class="text-[10px] font-bold text-gray-500"><?= htmlspecialchars($u['email']) ?></p>
                                    </div>
                                </td>
                                <td class="px-8 py-6">
                                    <div class="flex flex-col gap-1">
                                        <span
                                            class="text-[10px] font-black uppercase italic tracking-widest text-primary"><?= $u['role'] ?></span>
                                        <span
                                            class="text-[8px] font-bold uppercase tracking-widest <?= ($u['is_active']) ? 'text-emerald-500' : 'text-rose-500' ?>">
                                            <?= ($u['is_active']) ? 'Authorized' : 'Restricted' ?>
                                        </span>
                                    </div>
                                </td>
                                <td class="px-8 py-6">
                                    <p
                                        class="text-[10px] font-black uppercase text-gray-400 italic tracking-widest group-hover:text-white transition-colors truncate max-w-[200px]">
                                        <?= htmlspecialchars($u['detail_info'] ?: 'None') ?></p>
                                </td>
                                <td class="px-8 py-6 text-right">
                                    <button onclick="viewUserProfile(<?= $u['user_id'] ?>)"
                                        class="size-9 rounded-xl bg-white/5 hover:bg-primary transition-all flex items-center justify-center text-gray-500 hover:text-white inline-flex border border-white/5 shadow-sm group-hover:shadow-primary/20">
                                        <span class="material-symbols-outlined text-xl">visibility</span>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </main>

    <!-- USER PROFILE MODAL -->
    <div id="profileModal" class="overflow-hidden">
        <div class="modal-backdrop" onclick="closeUserModal()"></div>
        <div
            class="modal-container glass-card shadow-[0_0_100px_rgba(0,0,0,0.5)] backdrop-blur-3xl animate-in fade-in zoom-in duration-300">
            <div id="modalContent" class="p-10">
                <!-- Content loaded via AJAX -->
            </div>
        </div>
    </div>

</body>

</html>