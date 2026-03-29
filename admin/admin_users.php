<?php
session_start();
require_once '../db.php';

// Security Check: Only Staff and Coach
$role = strtolower($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || ($role !== 'staff' && $role !== 'coach')) {
    header("Location: ../login.php");
    exit;
}

$gym_id = $_SESSION['gym_id'];
$admin_name = $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];

// --- FETCH BRANDING & CONFIG ---
$stmtGym = $pdo->prepare("SELECT * FROM gyms WHERE gym_id = ?");
$stmtGym->execute([$gym_id]);
$gym = $stmtGym->fetch();

$stmtPage = $pdo->prepare("SELECT * FROM tenant_pages WHERE gym_id = ? LIMIT 1");
$stmtPage->execute([$gym_id]);
$tenant_config = $stmtPage->fetch();

// --- PAGINATION & FILTERING LOGIC ---
$limit = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int) $_GET['page'] : 1;
if ($page < 1)
    $page = 1;
$offset = ($page - 1) * $limit;

$search = $_GET['search'] ?? '';
$filter_role = $_GET['role'] ?? '';
$filter_status = $_GET['status'] ?? '';
$sort_by = $_GET['sort'] ?? 'newest';

// 1. Base Query Structure
$where_parts = ["ur.gym_id = :gym_id", "r.role_name != 'Superadmin'"];
$sql_params = [':gym_id' => $gym_id];

if (!empty($search)) {
    $where_parts[] = "(u.first_name LIKE :s1 OR u.last_name LIKE :s2 OR u.username LIKE :s3 OR u.email LIKE :s4)";
    $sql_params[':s1'] = "%$search%";
    $sql_params[':s2'] = "%$search%";
    $sql_params[':s3'] = "%$search%";
    $sql_params[':s4'] = "%$search%";
}

if (!empty($filter_role)) {
    $where_parts[] = "r.role_name = :role";
    $sql_params[':role'] = $filter_role;
}

if ($filter_status !== '') {
    $where_parts[] = "u.is_active = :status";
    $sql_params[':status'] = (int) $filter_status;
}

$where_clause = "WHERE " . implode(' AND ', $where_parts);

$order_sql = "ORDER BY u.created_at DESC";
if ($sort_by === 'oldest')
    $order_sql = "ORDER BY u.created_at ASC";
if ($sort_by === 'name_asc')
    $order_sql = "ORDER BY u.first_name ASC";
if ($sort_by === 'name_desc')
    $order_sql = "ORDER BY u.first_name DESC";

// 2. Fetch Total Count
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM users u JOIN user_roles ur ON u.user_id = ur.user_id JOIN roles r ON ur.role_id = r.role_id $where_clause");
$stmtCount->execute($sql_params);
$total_records = $stmtCount->fetchColumn();
$total_pages = ceil($total_records / $limit);

// 3. Fetch Paginated List
$users_sql = "
    SELECT u.user_id as id, u.first_name, u.last_name, u.username, u.email, u.contact_number, r.role_name as role, u.created_at, u.is_active 
    FROM users u 
    JOIN user_roles ur ON u.user_id = ur.user_id 
    JOIN roles r ON ur.role_id = r.role_id 
    $where_clause 
    $order_sql 
    LIMIT :limit OFFSET :offset
";

$stmtUsers = $pdo->prepare($users_sql);
foreach ($sql_params as $key => $val) {
    if ($key === ':status' || $key === ':gym_id') {
        $stmtUsers->bindValue($key, (int) $val, PDO::PARAM_INT);
    } else {
        $stmtUsers->bindValue($key, $val, PDO::PARAM_STR);
    }
}
$stmtUsers->bindValue(':limit', (int) $limit, PDO::PARAM_INT);
$stmtUsers->bindValue(':offset', (int) $offset, PDO::PARAM_INT);
$stmtUsers->execute();
$users_list = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

// --- AJAX USER PROFILE FETCH ---
if (isset($_GET['ajax_user_id'])) {
    $uid = (int) $_GET['ajax_user_id'];
    $stmtUser = $pdo->prepare("
        SELECT u.*, r.role_name as role, ur.role_status 
        FROM users u 
        JOIN user_roles ur ON u.user_id = ur.user_id 
        JOIN roles r ON ur.role_id = r.role_id 
        WHERE u.user_id = ? AND ur.gym_id = ? 
        LIMIT 1
    ");
    $stmtUser->execute([$uid, $gym_id]);
    $u = $stmtUser->fetch();

    if ($u): ?>
        <div class="space-y-8 animate-in fade-in slide-in-from-bottom-4 duration-500">
            <header class="flex justify-between items-start border-b border-white/5 pb-8">
                <div class="flex items-center gap-6">
                    <div
                        class="size-20 rounded-[28px] bg-primary/10 border-2 border-primary/20 flex items-center justify-center text-primary font-black italic text-3xl uppercase shadow-2xl">
                        <?= substr($u['first_name'], 0, 1) ?>
                    </div>
                    <div>
                        <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white leading-none">
                            <?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?></h2>
                        <div class="flex items-center gap-3 mt-3">
                            <span
                                class="px-3 py-1 rounded-full bg-primary/10 border border-primary/20 text-[9px] text-primary font-black uppercase italic tracking-widest"><?= $u['role'] ?></span>
                            <span
                                class="px-3 py-1 rounded-full bg-white/5 border border-white/5 text-[9px] text-gray-500 font-bold uppercase tracking-widest italic">Joined:
                                <?= date('M d, Y', strtotime($u['created_at'])) ?></span>
                        </div>
                    </div>
                </div>
                <button onclick="closeUserModal()"
                    class="size-10 rounded-full bg-white/5 hover:bg-white/10 flex items-center justify-center text-gray-400 group transition-all">
                    <span class="material-symbols-outlined text-2xl group-hover:rotate-90 transition-transform">close</span>
                </button>
            </header>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <div class="space-y-6">
                    <h4 class="text-[10px] font-black uppercase text-primary tracking-[0.2em] border-l-2 border-primary pl-3">
                        Member Identity</h4>
                    <div class="space-y-4">
                        <div class="bg-white/[0.03] p-6 rounded-2xl border border-white/5">
                            <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-1.5">Username Handle</p>
                            <p class="text-lg font-black text-white italic tracking-tight">
                                @<?= htmlspecialchars($u['username']) ?></p>
                        </div>
                        <div class="bg-white/[0.03] p-6 rounded-2xl border border-white/5">
                            <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-1.5">Security ID</p>
                            <p class="text-sm font-bold text-gray-300">
                                USER_NODE_<?= str_pad($u['user_id'], 6, '0', STR_PAD_LEFT) ?></p>
                        </div>
                    </div>
                </div>

                <div class="space-y-6">
                    <h4 class="text-[10px] font-black uppercase text-primary tracking-[0.2em] border-l-2 border-primary pl-3">
                        Contact Nodes</h4>
                    <div class="space-y-4">
                        <div class="bg-white/[0.03] p-6 rounded-2xl border border-white/5">
                            <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-1.5">Email Protocol</p>
                            <p class="text-sm font-bold text-white"><?= htmlspecialchars($u['email']) ?></p>
                        </div>
                        <div class="bg-white/[0.03] p-6 rounded-2xl border border-white/5">
                            <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-1.5">Mobile Registry</p>
                            <p class="text-sm font-bold text-white tracking-widest">
                                <?= htmlspecialchars($u['contact_number'] ?: 'NOT REGISTERED') ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-primary/5 border border-primary/10 rounded-3xl p-8 backdrop-blur-md shadow-2xl">
                <div class="flex items-center justify-between">
                    <div>
                        <h4 class="text-[10px] font-black uppercase text-primary tracking-[0.2em] mb-1">System Authority</h4>
                        <p class="text-xs text-gray-500 font-bold uppercase tracking-widest italic">Verified Status Profile</p>
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="text-right">
                            <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-1">Account Protocol</p>
                            <span
                                class="px-4 py-1.5 rounded-full bg-white/5 border border-white/10 text-[10px] font-black uppercase italic tracking-widest <?= $u['is_active'] ? 'text-emerald-500' : 'text-red-500' ?>">
                                <?= $u['is_active'] ? 'Active Access' : 'Access Restricted' ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif;
    exit;
}

$active_page = "admin_users";
?>
<!DOCTYPE html>
<html class="dark" lang="en">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>User Database | Horizon Partners</title>
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
                        "primary": "<?= $tenant_config['theme_color'] ?? '#8c2bee' ?>",
                        "background-dark": "<?= $tenant_config['bg_color'] ?? '#0a090d' ?>",
                        "surface-dark": "#14121a",
                        "border-subtle": "rgba(255,255,255,0.05)"
                    }
                }
            }
        } 
    </script>
    <style>
        body {
            font-family: 'Lexend', sans-serif;
            background-color:
                <?= $tenant_config['bg_color'] ?? '#0a090d' ?>
            ;
            color: white;
            display: flex;
            flex-direction: row;
            min-h-screen: 100vh;
            overflow: hidden;
        }

        .glass-card {
            background: #14121a;
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 24px;
        }

        /* Sidebar Hover Logic */
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
        }

        .side-nav:hover {
            width: 300px;
        }

        .main-content {
            margin-left: 110px;
            flex: 1;
            min-width: 0;
            transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .side-nav:hover~.main-content {
            margin-left: 300px;
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
            margin-bottom: 8px !important;
            pointer-events: auto;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 10px 38px;
            transition: all 0.2s ease;
            text-decoration: none;
            white-space: nowrap;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #94a3b8;
        }

        .nav-item:hover {
            background: rgba(255, 255, 255, 0.05);
            color: white;
        }

        .nav-item.active {
            color:
                <?= $tenant_config['theme_color'] ?? '#8c2bee' ?>
                !important;
            position: relative;
        }

        .nav-item.active::after {
            content: '';
            position: absolute;
            right: 0px;
            top: 50%;
            transform: translateY(-50%);
            width: 4px;
            height: 24px;
            background:
                <?= $tenant_config['theme_color'] ?? '#8c2bee' ?>
            ;
            border-radius: 4px 0 0 4px;
        }

        .no-scrollbar::-webkit-scrollbar {
            display: none;
        }

        .no-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        .input-box {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            color: white;
            padding: 12px 16px;
            font-size: 13px;
            font-weight: 500;
            outline: none;
            transition: all 0.2s;
        }

        .input-box:focus {
            border-color:
                <?= $tenant_config['theme_color'] ?? '#8c2bee' ?>
            ;
            background: rgba(255, 255, 255, 0.08);
        }

        .input-box option {
            background-color: #1a1821;
            color: white;
        }

        select.input-box {
            cursor: pointer;
            color-scheme: dark;
            padding-right: 2.5rem !important;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='white'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 1em;
        }
    </style>
    <script>
        let filterTimeout;
        function reactiveFilter() {
            clearTimeout(filterTimeout);
            filterTimeout = setTimeout(() => {
                changePage(1);
            }, 400);
        }

        function clearFilters() {
            const form = document.getElementById('filterForm');
            form.reset();
            // Clear inputs manually if reset() isn't enough for some browsers
            form.querySelectorAll('input, select').forEach(el => el.value = '');
            changePage(1);
        }

        function changePage(pageNumber) {
            const form = document.getElementById('filterForm');
            const formData = new FormData(form);
            const params = new URLSearchParams(formData);
            params.set('page', pageNumber);

            // Re-construct the URL and update history without reload
            const newUrl = `${window.location.pathname}?${params.toString()}`;
            window.history.pushState({ path: newUrl }, '', newUrl);

            // AJAX Table Switch - Fetch the new content and parse the table container
            fetch(newUrl)
                .then(response => response.text())
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    const newContainer = doc.getElementById('usersTableContainer');
                    if (newContainer) {
                        document.getElementById('usersTableContainer').innerHTML = newContainer.innerHTML;
                    }
                })
                .catch(err => console.error("Filter Fetch Error:", err));
        }

        async function viewUserProfile(userId) {
            const modal = document.getElementById('userModal');
            const content = document.getElementById('modalContent');

            // Show modal & loader
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            content.innerHTML = '<div class="flex items-center justify-center p-20"><div class="size-12 border-4 border-primary/20 border-t-primary rounded-full animate-spin"></div></div>';

            try {
                const response = await fetch(`?ajax_user_id=${userId}`);
                const html = await response.text();
                content.innerHTML = html;
            } catch (error) {
                content.innerHTML = '<p class="text-red-500 font-bold text-center p-10">ERROR: FAILED TO FETCH PROFILE</p>';
            }
        }

        function closeUserModal() {
            const modal = document.getElementById('userModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        function updateHeaderClock() {
            const now = new Date();
            const clockEl = document.getElementById('headerClock');
            if (clockEl) {
                clockEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            }
        }
        setInterval(updateHeaderClock, 1000);
        window.addEventListener('DOMContentLoaded', updateHeaderClock); 
    </script>
</head>

<body class="antialiased flex h-screen overflow-hidden">

    <nav class="side-nav flex flex-col fixed left-0 top-0 h-screen bg-background-dark border-r border-white/5 z-50">
        <div class="px-7 py-8 mb-4 shrink-0">
            <div class="flex items-center gap-4">
                <div
                    class="size-10 rounded-xl bg-primary flex items-center justify-center shadow-lg shrink-0 overflow-hidden">
                    <?php if (!empty($tenant_config['logo_path'])):
                        $logo_src = (strpos($tenant_config['logo_path'], 'data:image') === 0) ? $tenant_config['logo_path'] : '../' . $tenant_config['logo_path'];
                        ?>
                        <img src="<?= $logo_src ?>" class="size-full object-contain">
                    <?php else: ?>
                        <span class="material-symbols-outlined text-white text-2xl">bolt</span>
                    <?php endif; ?>
                </div>
                <h1 class="nav-label text-lg font-black italic uppercase tracking-tighter text-white">Staff Portal</h1>
            </div>
        </div>
        <div class="flex-1 overflow-y-auto no-scrollbar space-y-1">
            <div class="nav-section-label px-[38px] mb-2"><span
                    class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Overview</span></div>
            <a href="admin_dashboard.php" class="nav-item"><span
                    class="material-symbols-outlined text-xl shrink-0">grid_view</span><span
                    class="nav-label">Dashboard</span></a>
            <a href="register_member.php" class="nav-item"><span
                    class="material-symbols-outlined text-xl shrink-0">person_add</span><span class="nav-label">Walk-in
                    Member</span></a>
            <div class="nav-section-label px-[38px] mb-2 mt-6"><span
                    class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Management</span></div>
            <a href="admin_users.php" class="nav-item active"><span
                    class="material-symbols-outlined text-xl shrink-0">group</span><span class="nav-label">My
                    Users</span></a>
            <a href="admin_transaction.php" class="nav-item text-gray-400 hover:text-white"><span
                    class="material-symbols-outlined text-xl shrink-0">receipt_long</span><span
                    class="nav-label">Transactions</span></a>
            <a href="admin_appointment.php" class="nav-item text-gray-400 hover:text-white"><span
                    class="material-symbols-outlined text-xl shrink-0">event_note</span><span
                    class="nav-label">Bookings</span></a>
            <a href="admin_attendance.php" class="nav-item text-gray-400 hover:text-white"><span
                    class="material-symbols-outlined text-xl shrink-0">history</span><span
                    class="nav-label">Attendance</span></a>
        </div>
        <div class="mt-auto pt-4 border-t border-white/10 shrink-0 pb-6">
            <div class="nav-section-label px-[38px] mb-2"><span
                    class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">Account</span></div>
            <a href="admin_profile.php" class="nav-item text-gray-400 hover:text-white"><span
                    class="material-symbols-outlined text-xl shrink-0">account_circle</span><span
                    class="nav-label">Profile</span></a>
            <a href="../logout.php" class="nav-item text-gray-400 hover:text-rose-500 transition-colors group">
                <span
                    class="material-symbols-outlined text-xl shrink-0 group-hover:translate-x-1 transition-transform">logout</span>
                <span class="nav-label whitespace-nowrap">Sign Out</span>
            </a>
        </div>
    </nav>

    <div class="main-content flex-1 overflow-y-auto no-scrollbar">
        <main class="p-10 max-w-[1400px] mx-auto pb-20">
            <header class="mb-12 flex flex-row justify-between items-end gap-6">
                <div>
                    <h2
                        class="text-3xl font-black italic uppercase tracking-tighter text-white leading-none tracking-tight">
                        User <span class="text-primary">Masterlist</span></h2>
                    <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-2 px-1 opacity-60">Verified
                        Access Accounts • <?= number_format($total_records) ?> Total</p>
                </div>
                <div class="flex items-end gap-8 text-right shrink-0">
                    <div class="flex flex-col items-end">
                        <p id="headerClock" class="text-white font-black italic text-2xl leading-none tracking-tighter">
                            00:00:00 AM</p>
                        <p class="text-primary text-[10px] font-black uppercase tracking-[0.2em] leading-none mt-2">
                            <?= date('l, M d, Y') ?></p>
                    </div>
                </div>
            </header>

            <!-- Search & Filter Controls -->
            <div class="mb-8">
                <form id="filterForm" method="GET" class="glass-card p-8 border border-white/5 relative overflow-hidden"
                    onsubmit="event.preventDefault(); reactiveFilter();">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6">
                        <div class="space-y-2 lg:col-span-2">
                            <p class="text-[10px] font-black uppercase tracking-widest text-primary ml-1">Database
                                Search</p>
                            <div class="relative">
                                <span
                                    class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-lg">search</span>
                                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                                    placeholder="Filter user registry..." oninput="reactiveFilter()"
                                    class="input-box pl-12 w-full">
                            </div>
                        </div>

                        <div class="space-y-2">
                            <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 ml-1">Authority
                                Filter</p>
                            <select name="role" onchange="reactiveFilter()" class="input-box w-full pr-10">
                                <option value="">All Roles</option>
                                <option value="Member" <?= ($filter_role === 'Member') ? 'selected' : '' ?>>Member</option>
                                <option value="Staff" <?= ($filter_role === 'Staff') ? 'selected' : '' ?>>Staff</option>
                                <option value="Coach" <?= ($filter_role === 'Coach') ? 'selected' : '' ?>>Coach</option>
                            </select>
                        </div>

                        <div class="space-y-2">
                            <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 ml-1">Connection
                                Protocol</p>
                            <select name="status" onchange="reactiveFilter()" class="input-box w-full pr-10">
                                <option value="">All Status</option>
                                <option value="1" <?= ($filter_status === '1') ? 'selected' : '' ?>>Active</option>
                                <option value="0" <?= ($filter_status === '0') ? 'selected' : '' ?>>Restricted</option>
                            </select>
                        </div>

                        <div class="space-y-2">
                            <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 ml-1">Registry Sort
                            </p>
                            <div class="flex items-center gap-3">
                                <select name="sort" onchange="reactiveFilter()" class="input-box w-full pr-10">
                                    <option value="newest" <?= ($sort_by === 'newest') ? 'selected' : '' ?>>Newest</option>
                                    <option value="oldest" <?= ($sort_by === 'oldest') ? 'selected' : '' ?>>Oldest</option>
                                    <option value="name_asc" <?= ($sort_by === 'name_asc') ? 'selected' : '' ?>>A-Z
                                    </option>
                                </select>
                                <button type="button" onclick="clearFilters()"
                                    class="size-[46px] rounded-xl bg-white/5 border border-white/5 flex items-center justify-center text-gray-400 hover:bg-rose-500/10 hover:text-rose-500 transition-all active:scale-95 group"
                                    title="Clear All Filters">
                                    <span
                                        class="material-symbols-outlined text-xl group-hover:rotate-180 transition-transform">restart_alt</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <div class="flex justify-between items-center mb-6">
                <p class="text-[10px] font-black uppercase tracking-widest text-gray-500">Live Connection Database —
                    <span class="text-white">Active Feed</span></p>
                <a href="register_member.php"
                    class="bg-primary hover:bg-primary/90 text-white px-8 h-[46px] rounded-xl text-[10px] font-black uppercase tracking-widest transition-all shadow-lg shadow-primary/20 flex items-center gap-3 active:scale-95">
                    <span class="material-symbols-outlined text-lg">person_add</span> Enlist Walk-in Member
                </a>
            </div>

            <div id="usersTableContainer" class="glass-card shadow-2xl overflow-hidden border border-white/5">
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr
                                class="text-[10px] font-black uppercase tracking-widest text-gray-600 border-b border-white/5 bg-white/[0.01]">
                                <th class="px-8 py-5">Full Identity</th>
                                <th class="px-8 py-5">Auth Credentials</th>
                                <th class="px-8 py-5">Contact Node</th>
                                <th class="px-8 py-5 text-center">Protocol Role</th>
                                <th class="px-8 py-5 text-right">System Control</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <?php if (empty($users_list)): ?>
                                <tr>
                                    <td colspan="5"
                                        class="px-8 py-20 text-center text-gray-700 italic font-black uppercase tracking-widest text-[10px]">
                                        No authorized users found matching criteria</td>
                                </tr>
                            <?php else:
                                foreach ($users_list as $row):
                                    $roleClean = strtolower($row['role'] ?? 'member');
                                    ?>
                                    <tr class="hover:bg-white/[0.02] group transition-colors">
                                        <td class="px-8 py-6">
                                            <p
                                                class="text-[13px] font-black italic uppercase text-white group-hover:text-primary transition-colors">
                                                <?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?></p>
                                            <p class="text-[10px] font-black uppercase text-gray-500 tracking-tighter mt-0.5">
                                                <?= date('M d, Y', strtotime($row['created_at'])) ?></p>
                                        </td>
                                        <td class="px-8 py-6">
                                            <p class="text-[12px] font-bold text-gray-300">
                                                @<?= htmlspecialchars($row['username']) ?></p>
                                            <p class="text-[9px] font-black uppercase text-gray-600 tracking-widest mt-0.5">ID:
                                                <?= str_pad($row['id'], 5, '0', STR_PAD_LEFT) ?></p>
                                        </td>
                                        <td class="px-8 py-6">
                                            <p class="text-[12px] font-medium text-gray-400">
                                                <?= htmlspecialchars($row['email']) ?></p>
                                            <p class="text-[10px] font-black text-gray-600 mt-1 uppercase">
                                                <?= htmlspecialchars($row['contact_number'] ?? 'N/A') ?></p>
                                        </td>
                                        <td class="px-8 py-6 text-center">
                                            <span
                                                class="inline-block px-4 py-1.5 rounded-full text-[9px] font-black uppercase italic tracking-widest border border-white/5 bg-white/5 <?= ($roleClean === 'coach' ? 'text-amber-500 border-amber-500/20' : ($roleClean === 'staff' ? 'text-primary border-primary/20' : 'text-emerald-500 border-emerald-500/20')) ?>">
                                                <?= $row['role'] ?>
                                            </span>
                                        </td>
                                        <td class="px-8 py-6 text-right">
                                            <div
                                                class="flex justify-end gap-3 translate-x-4 opacity-0 group-hover:opacity-100 group-hover:translate-x-0 transition-all duration-300">
                                                <button onclick="viewUserProfile(<?= $row['id'] ?>)"
                                                    class="size-9 rounded-xl bg-white/5 flex items-center justify-center hover:bg-primary hover:text-white text-gray-400 transition-all active:scale-90"
                                                    title="View Detailed Profile">
                                                    <span class="material-symbols-outlined text-lg">visibility</span>
                                                </button>
                                                <button
                                                    class="size-9 rounded-xl bg-white/5 flex items-center justify-center hover:bg-white/10 text-gray-500 transition-all cursor-not-allowed opacity-30"
                                                    title="Restricted Control">
                                                    <span class="material-symbols-outlined text-lg">lock</span>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Enhanced Pagination Footer -->
                <?php if ($total_pages > 1): ?>
                    <div
                        class="px-8 py-6 border-t border-white/5 bg-white/[0.01] flex flex-row items-center justify-between">
                        <p class="text-[10px] font-black uppercase tracking-widest text-gray-500">
                            Page <span class="text-white"><?= $page ?></span> of <span
                                class="text-white"><?= $total_pages ?></span>
                            <span class="mx-3 text-gray-700">•</span> Total <span
                                class="text-white"><?= $total_records ?></span> Records
                        </p>
                        <div class="flex items-center gap-2">
                            <!-- Previous Button -->
                            <button onclick="changePage(<?= max(1, $page - 1) ?>)"
                                class="size-10 rounded-xl bg-white/5 flex items-center justify-center text-gray-500 hover:bg-primary hover:text-white transition-all <?= ($page <= 1) ? 'opacity-20 pointer-events-none' : '' ?>">
                                <span class="material-symbols-outlined text-xl">chevron_left</span>
                            </button>

                            <!-- Page Numbers -->
                            <div class="flex items-center gap-1">
                                <?php
                                $start_p = max(1, $page - 2);
                                $end_p = min($total_pages, $start_p + 4);
                                if ($end_p - $start_p < 4)
                                    $start_p = max(1, $end_p - 4);

                                for ($i = $start_p; $i <= $end_p; $i++):
                                    ?>
                                    <button onclick="changePage(<?= $i ?>)"
                                        class="size-10 rounded-xl flex items-center justify-center text-[11px] font-black transition-all <?= ($i === $page) ? 'bg-primary text-white shadow-lg shadow-primary/20' : 'bg-white/5 text-gray-500 hover:bg-white/10 hover:text-white' ?>">
                                        <?= $i ?>
                                    </button>
                                <?php endfor; ?>
                            </div>

                            <!-- Next Button -->
                            <button onclick="changePage(<?= min($total_pages, $page + 1) ?>)"
                                class="size-10 rounded-xl bg-white/5 flex items-center justify-center text-gray-500 hover:bg-primary hover:text-white transition-all <?= ($page >= $total_pages) ? 'opacity-20 pointer-events-none' : '' ?>">
                                <span class="material-symbols-outlined text-xl">chevron_right</span>
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Glassmorphism Profile Modal -->
    <div id="userModal"
        class="hidden fixed inset-0 z-[100] items-center justify-center p-6 md:p-12 pl-[150px] overflow-hidden">
        <!-- Clickable Backdrop -->
        <div class="absolute inset-0 bg-black/60 backdrop-blur-md" onclick="closeUserModal()"></div>

        <!-- Modal Container -->
        <div
            class="relative w-full max-w-4xl max-h-[90vh] overflow-y-auto no-scrollbar glass-card border-white/10 shadow-[0_0_100px_rgba(0,0,0,0.5)] backdrop-blur-3xl p-10 md:p-14 animate-in fade-in zoom-in duration-300">
            <div id="modalContent">
                <!-- Loaded via AJAX -->
            </div>
        </div>
    </div>

</body>

</html>