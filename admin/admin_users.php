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

// ── 4-Color Elite Branding System Implementation ─────────────────────────────
if (!function_exists('hexToRgb')) {
    function hexToRgb($hex) {
        if (!$hex) return "0, 0, 0";
        $hex = str_replace("#", "", $hex);
        if (strlen($hex) == 3) {
            $r = hexdec(substr($hex, 0, 1) . substr($hex, 0, 1));
            $g = hexdec(substr($hex, 1, 1) . substr($hex, 1, 1));
            $b = hexdec(substr($hex, 2, 1) . substr($hex, 2, 1));
        } else {
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
        }
        return "$r, $g, $b";
    }
}

// Fetch Gym & Owner Details for Branding
$stmtGymBranding = $pdo->prepare("SELECT owner_user_id, gym_name FROM gyms WHERE gym_id = ?");
$stmtGymBranding->execute([$gym_id]);
$gym_data = $stmtGymBranding->fetch();
$owner_user_id = $gym_data['owner_user_id'] ?? 0;
$gym_name = $gym_data['gym_name'] ?? 'Horizon Gym';

$configs = [
    'system_name'     => $gym_name,
    'system_logo'     => '',
    'theme_color'     => '#8c2bee',
    'secondary_color' => '#a1a1aa',
    'text_color'      => '#d1d5db',
    'bg_color'        => '#0a090d',
    'card_color'      => '#141216',
    'auto_card_theme' => '1',
    'font_family'     => 'Lexend',
];

// 1. Merge global settings (user_id = 0)
$stmtGlobal = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = 0");
$stmtGlobal->execute();
foreach (($stmtGlobal->fetchAll(PDO::FETCH_KEY_PAIR) ?: []) as $k => $v) {
    if ($v !== null && $v !== '') $configs[$k] = $v;
}

// 2. Merge tenant-specific settings (user_id = owner_user_id)
$stmtTenant = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = ?");
$stmtTenant->execute([$owner_user_id]);
foreach (($stmtTenant->fetchAll(PDO::FETCH_KEY_PAIR) ?: []) as $k => $v) {
    if ($v !== null && $v !== '') $configs[$k] = $v;
}

// 3. Resolved branding tokens
$theme_color     = $configs['theme_color'];
$highlight_color = $configs['secondary_color'];
$text_color      = $configs['text_color'];
$bg_color        = $configs['bg_color'];
$font_family     = $configs['font_family'] ?? 'Lexend';
$auto_card_theme = $configs['auto_card_theme'] ?? '1';
$card_color      = $configs['card_color'];

$primary_rgb   = hexToRgb($theme_color);
$highlight_rgb = hexToRgb($highlight_color);
$card_bg_css   = ($auto_card_theme === '1') ? "rgba({$primary_rgb}, 0.05)" : $card_color;

$system_logo = $configs['system_logo'] ?: ($gym_data['profile_picture'] ?? '');
// ─────────────────────────────────────────────────────────────────────────────

// --- PAGINATION & FILTERING LOGIC ---
$limit = 10;
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int) $_GET['page'] : 1;
if ($current_page < 1)
    $current_page = 1;
$offset = ($current_page - 1) * $limit;

$search = $_GET['search'] ?? '';
$filter_role = $_GET['role'] ?? '';
$filter_status = $_GET['status'] ?? '';
$sort_by = $_GET['sort'] ?? 'newest';

// 1. Base Query Structure
$where_parts = ["ur.gym_id = :gym_id", "r.role_name IN ('Member', 'Coach')"];
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

// --- ACCOUNT STATUS TOGGLE HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status_id'])) {
    $target_uid = (int) $_POST['toggle_status_id'];
    
    // Safety check: verify user exists in this gym and is NOT a tenant/superadmin
    $stmt = $pdo->prepare("
        SELECT u.is_active, r.role_name 
        FROM users u 
        JOIN user_roles ur ON u.user_id = ur.user_id 
        JOIN roles r ON ur.role_id = r.role_id 
        WHERE u.user_id = ? AND ur.gym_id = ? 
        LIMIT 1
    ");
    $stmt->execute([$target_uid, $gym_id]);
    $user_data = $stmt->fetch();
    
    if ($user_data && strtolower($user_data['role_name']) !== 'tenant' && strtolower($user_data['role_name']) !== 'superadmin') {
        $new_status = $user_data['is_active'] ? 0 : 1;
        $stmtUpdate = $pdo->prepare("UPDATE users SET is_active = ?, updated_at = NOW() WHERE user_id = ?");
        $stmtUpdate->execute([$new_status, $target_uid]);
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'The target account is a protected system node and cannot be restricted.']);
    }
    exit;
}

// --- AJAX USER PROFILE FETCH ---
if (isset($_GET['ajax_user_id'])) {
    $uid = (int) $_GET['ajax_user_id'];
    
    // First, determine role to join correct detail tables
    $stmtRoleCheck = $pdo->prepare("SELECT r.role_name FROM user_roles ur JOIN roles r ON ur.role_id = r.role_id WHERE ur.user_id = ? AND ur.gym_id = ? LIMIT 1");
    $stmtRoleCheck->execute([$uid, $gym_id]);
    $role_name = strtolower($stmtRoleCheck->fetchColumn() ?: '');

    $sql = "SELECT u.*, r.role_name as role, ur.role_status ";
    if ($role_name === 'member') {
        $sql .= ", m.member_code, u.birth_date, u.sex, m.occupation, a.address_line, m.medical_history, m.emergency_contact_name, m.emergency_contact_number ";
        $sql .= " FROM users u JOIN user_roles ur ON u.user_id = ur.user_id JOIN roles r ON ur.role_id = r.role_id LEFT JOIN members m ON u.user_id = m.user_id LEFT JOIN addresses a ON m.address_id = a.address_id ";
    } elseif ($role_name === 'staff' || $role_name === 'coach') {
        $sql .= ", s.staff_role, s.employment_type, s.hire_date, s.status as staff_status ";
        $sql .= " FROM users u JOIN user_roles ur ON u.user_id = ur.user_id JOIN roles r ON ur.role_id = r.role_id LEFT JOIN staff s ON u.user_id = s.user_id AND s.gym_id = ur.gym_id ";
    } elseif ($role_name === 'tenant') {
        $sql .= ", g.gym_name, g.business_name, g.tenant_code, g.status as gym_status, g.email as gym_email, g.contact_number as gym_contact ";
        $sql .= " FROM users u JOIN user_roles ur ON u.user_id = ur.user_id JOIN roles r ON ur.role_id = r.role_id LEFT JOIN gyms g ON u.user_id = g.owner_user_id ";
    } else {
        $sql .= " FROM users u JOIN user_roles ur ON u.user_id = ur.user_id JOIN roles r ON ur.role_id = r.role_id ";
    }
    $sql .= " WHERE u.user_id = ? AND ur.gym_id = ? LIMIT 1";

    $stmtUser = $pdo->prepare($sql);
    $stmtUser->execute([$uid, $gym_id]);
    $u = $stmtUser->fetch();

    if ($u): ?>
        <div class="space-y-8 animate-in fade-in slide-in-from-bottom-4 duration-500 pb-6">
            <!-- Professional Header -->
            <header class="flex justify-between items-center border-b border-white/5 pb-8">
                <div class="flex items-center gap-6">
                    <div class="size-16 rounded-2xl bg-primary/10 border border-primary/20 flex items-center justify-center text-primary font-bold text-2xl uppercase">
                        <?= substr($u['first_name'], 0, 1) ?>
                    </div>
                    <div>
                        <h2 class="text-2xl font-bold uppercase tracking-tight text-[--text-main]">
                            <?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?>
                        </h2>
                        <div class="flex items-center gap-3 mt-1">
                            <span class="text-[10px] text-primary font-black uppercase tracking-widest px-2 py-0.5 rounded bg-primary/5 border border-primary/10">
                                <?= $u['role'] ?>
                            </span>
                        </div>
                    </div>
                </div>
                <button onclick="closeUserModal()" class="size-10 rounded-xl bg-white/5 hover:bg-white/10 flex items-center justify-center text-[--text-main]/40 transition-all">
                    <span class="material-symbols-rounded text-xl">close</span>
                </button>
            </header>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <!-- Data Nodes -->
                <div class="space-y-8">
                    <section class="space-y-4">
                        <h4 class="text-[9px] font-black uppercase text-[--text-main]/40 tracking-widest">Identity & Access</h4>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="p-4 rounded-2xl bg-white/[0.02] border border-white/5">
                                <p class="text-[8px] font-black uppercase text-[--text-main]/30 tracking-widest mb-1">Username</p>
                                <p class="text-xs font-bold text-[--text-main]">@<?= htmlspecialchars($u['username']) ?></p>
                            </div>
                            <div class="p-4 rounded-2xl bg-white/[0.02] border border-white/5">
                                <p class="text-[8px] font-black uppercase text-[--text-main]/30 tracking-widest mb-1">Status</p>
                                <p class="text-xs font-bold uppercase <?= $u['is_active'] ? 'text-emerald-500' : 'text-rose-500' ?>">
                                    <?= $u['is_active'] ? 'Authorized' : 'Restricted' ?>
                                </p>
                            </div>
                        </div>
                    </section>

                    <section class="space-y-4">
                        <h4 class="text-[9px] font-black uppercase text-[--text-main]/40 tracking-widest">Contact Information</h4>
                        <div class="space-y-3">
                            <div class="flex items-center justify-between p-4 rounded-2xl bg-white/[0.02] border border-white/5">
                                <span class="text-[9px] font-black uppercase text-[--text-main]/30 tracking-widest">Direct Mail</span>
                                <span class="text-xs font-medium text-[--text-main]/70 truncate max-w-[180px]"><?= htmlspecialchars($u['email']) ?></span>
                            </div>
                            <div class="flex items-center justify-between p-4 rounded-2xl bg-white/[0.02] border border-white/5">
                                <span class="text-[9px] font-black uppercase text-[--text-main]/30 tracking-widest">Mobile Signal</span>
                                <span class="text-xs font-bold text-[--text-main] tracking-widest"><?= htmlspecialchars($u['contact_number'] ?: 'N/A') ?></span>
                            </div>
                        </div>
                    </section>
                </div>

                <div class="space-y-8">
                    <?php if ($role_name === 'member'): ?>
                        <section class="space-y-4">
                            <h4 class="text-[9px] font-black uppercase text-[--text-main]/40 tracking-widest">Registry Profile</h4>
                            <div class="grid grid-cols-2 gap-3">
                                <div class="p-4 rounded-2xl bg-white/[0.02] border border-white/5">
                                    <p class="text-[8px] font-black uppercase text-[--text-main]/30 tracking-widest mb-1">Gender</p>
                                    <p class="text-xs font-bold text-[--text-main] uppercase"><?= $u['sex'] ?: 'N/A' ?></p>
                                </div>
                                <div class="p-4 rounded-2xl bg-white/[0.02] border border-white/5">
                                    <p class="text-[8px] font-black uppercase text-[--text-main]/30 tracking-widest mb-1">Birth Registry</p>
                                    <p class="text-xs font-bold text-[--text-main]"><?= $u['birth_date'] ? date('M d, Y', strtotime($u['birth_date'])) : 'N/A' ?></p>
                                </div>
                            </div>
                        </section>
                    <?php endif; ?>

                    <?php if (!empty($u['emergency_contact_name'])): ?>
                        <section class="space-y-4">
                            <h4 class="text-[9px] font-black uppercase text-amber-500/60 tracking-widest">Emergency Protocol</h4>
                            <div class="p-5 rounded-2xl bg-amber-500/[0.03] border border-amber-500/10">
                                <p class="text-[10px] font-black text-[--text-main] uppercase mb-1"><?= htmlspecialchars($u['emergency_contact_name']) ?></p>
                                <p class="text-xs font-bold text-amber-500 tracking-widest"><?= htmlspecialchars($u['emergency_contact_number']) ?></p>
                            </div>
                        </section>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if ($role_name === 'member' && !empty($u['medical_history'])): ?>
                <section class="space-y-4 pt-4 border-t border-white/5">
                    <h4 class="text-[9px] font-black uppercase text-rose-500/60 tracking-widest">Medical Registry</h4>
                    <p class="text-[11px] leading-relaxed text-[--text-main]/50 bg-black/20 p-5 rounded-2xl border border-white/5 italic">
                        <?= nl2br(htmlspecialchars($u['medical_history'])) ?>
                    </p>
                </section>
            <?php endif; ?>
        </div>
    <?php endif;
    exit;
}

$active_page = "users";
$page = [
    'logo_path' => $system_logo,
    'system_name' => $configs['system_name'] ?? 'Horizon Staff'
];
?>
<!DOCTYPE html>
<html class="dark" lang="en">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>User Database | Horizon Partners</title>
    <link href="https://fonts.googleapis.com/css2?family=<?= urlencode($font_family) ?>:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet" />
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "var(--primary)",
                        "background": "var(--background)",
                        "card-bg": "var(--card-bg)",
                        "text-main": "var(--text-main)",
                        "highlight": "var(--highlight)"
                    }
                }
            }
        } 
    </script>
    <style>
        :root {
            --primary:       <?= $theme_color ?>;
            --primary-rgb:   <?= $primary_rgb ?>;
            --highlight:     <?= $highlight_color ?>;
            --highlight-rgb: <?= $highlight_rgb ?>;
            --text-main:     <?= $text_color ?>;
            --background:    <?= $bg_color ?>;
            --card-bg:       <?= $card_bg_css ?>;
            --card-blur:     20px;
        }

        body {
            font-family: '<?= $font_family ?>', sans-serif;
            background-color: var(--background);
            color: var(--text-main);
            display: flex;
            flex-direction: row;
            min-height: 100vh;
            overflow: hidden;
        }

        .glass-card {
            background: var(--card-bg);
            border: 1px solid rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(var(--card-blur));
            border-radius: 24px;
        }

        /* Sidebar Hover Logic */
        :root { --nav-width: 110px; }
        body:has(.side-nav:hover) { --nav-width: 300px; }

        .side-nav {
            width: var(--nav-width);
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            position: fixed;
            left: 0;
            top: 0;
            height: 100vh;
            z-index: 110; /* Sidebar always on top */
        }

        .main-content {
            margin-left: var(--nav-width);
            flex: 1;
            min-width: 0;
            transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow-y: auto;
            overflow-x: hidden;
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
                <?= $theme_color ?>
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
                <?= $theme_color ?>
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
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            color: var(--text-main);
            padding: 12px 16px;
            font-size: 13px;
            font-weight: 500;
            outline: none;
            transition: all 0.2s;
        }

        .input-box:focus {
            border-color: var(--primary);
            background: rgba(255, 255, 255, 0.08);
        }

        .input-box::placeholder { color: rgba(var(--text-main-rgb, 209, 213, 219), 0.3); }
        
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

        let confirmTargetUserId = null;

        function toggleAccountStatus(userId) {
            confirmTargetUserId = userId;
            const modal = document.getElementById('confirmModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeConfirmModal() {
            const modal = document.getElementById('confirmModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            confirmTargetUserId = null;
        }

        async function executeAccountToggle() {
            if (!confirmTargetUserId) return;
            const userId = confirmTargetUserId;
            closeConfirmModal();

            try {
                const formData = new FormData();
                formData.append('toggle_status_id', userId);

                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();

                if (result.success) {
                    reactiveFilter();
                } else {
                    alert(result.message || 'Access protocol update failed.');
                }
            } catch (error) {
                console.error('Status Update Error:', error);
                alert('A synchronisation error occurred in the gateway.');
            }
        }
    </script>
</head>

<body class="antialiased flex h-screen overflow-hidden">

    <!-- Dynamic Admin Sidebar -->
    <?php include '../includes/admin_sidebar.php'; ?>

    <div class="main-content flex-1 overflow-y-auto no-scrollbar">
        <main class="p-10 max-w-[1400px] mx-auto pb-20">
            <header class="mb-12 flex flex-row justify-between items-end gap-6">
                <div>
                    <h2
                        class="text-3xl font-black italic uppercase text-[--text-main] leading-none tracking-tight">
                        User <span class="text-primary">Masterlist</span></h2>
                    <p class="text-[--text-main]/40 text-xs font-bold uppercase tracking-widest mt-2 px-1">Verified
                        Access Accounts • <?= number_format($total_records) ?> Total</p>
                </div>
                <div class="flex items-end gap-8 text-right shrink-0">
                    <div class="flex flex-col items-end">
                        <p id="headerClock" class="text-[--text-main] font-black italic text-2xl leading-none tracking-tighter">
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
                                    class="material-symbols-rounded absolute left-4 top-1/2 -translate-y-1/2 text-[--text-main]/40 text-lg">search</span>
                                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                                    placeholder="Filter user registry..." oninput="reactiveFilter()"
                                    class="input-box pl-12 w-full">
                            </div>
                        </div>

                        <div class="space-y-2">
                            <p class="text-[10px] font-black uppercase tracking-widest text-[--text-main]/60 ml-1">Authority
                                Filter</p>
                            <select name="role" onchange="reactiveFilter()" class="input-box w-full pr-10">
                                <option value="">All Protocol Roles</option>
                                <option value="Member" <?= ($filter_role === 'Member') ? 'selected' : '' ?>>Member</option>
                                <option value="Coach" <?= ($filter_role === 'Coach') ? 'selected' : '' ?>>Coach</option>
                            </select>
                        </div>

                        <div class="space-y-2">
                            <p class="text-[10px] font-black uppercase tracking-widest text-[--text-main]/60 ml-1">Connection
                                Protocol</p>
                            <select name="status" onchange="reactiveFilter()" class="input-box w-full pr-10">
                                <option value="">All Status</option>
                                <option value="1" <?= ($filter_status === '1') ? 'selected' : '' ?>>Active</option>
                                <option value="0" <?= ($filter_status === '0') ? 'selected' : '' ?>>Restricted</option>
                            </select>
                        </div>

                        <div class="space-y-2">
                            <p class="text-[10px] font-black uppercase tracking-widest text-[--text-main]/60 ml-1">Registry Sort
                            </p>
                            <div class="flex items-center gap-3">
                                <select name="sort" onchange="reactiveFilter()" class="input-box w-full pr-10">
                                    <option value="newest" <?= ($sort_by === 'newest') ? 'selected' : '' ?>>Newest</option>
                                    <option value="oldest" <?= ($sort_by === 'oldest') ? 'selected' : '' ?>>Oldest</option>
                                    <option value="name_asc" <?= ($sort_by === 'name_asc') ? 'selected' : '' ?>>A-Z
                                    </option>
                                </select>
                                <button type="button" onclick="clearFilters()"
                                    class="size-[46px] rounded-xl bg-white/5 border border-white/5 flex items-center justify-center text-[--text-main]/40 hover:bg-rose-500/10 hover:text-rose-500 transition-all active:scale-95 group"
                                    title="Clear All Filters">
                                    <span
                                        class="material-symbols-rounded text-xl group-hover:rotate-180 transition-transform">restart_alt</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <div class="flex justify-between items-center mb-6">
                <p class="text-[10px] font-black uppercase tracking-widest text-[--text-main]/40">Live Connection Database —
                    <span class="text-[--text-main]">Active Feed</span></p>
                <a href="register_member.php"
                    class="bg-primary hover:bg-primary/90 text-white px-8 h-[46px] rounded-xl text-[10px] font-black uppercase tracking-widest transition-all shadow-lg shadow-primary/20 flex items-center gap-3 active:scale-95">
                    <span class="material-symbols-rounded text-lg">person_add</span> Enlist Walk-in Member
                </a>
            </div>

            <div id="usersTableContainer" class="glass-card shadow-2xl overflow-hidden border border-white/5">
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr
                                class="text-[10px] font-black uppercase tracking-widest text-[--text-main]/40 border-b border-white/5 bg-white/[0.01]">
                                <th class="px-8 py-5">Full Identity</th>
                                <th class="px-8 py-5">Email Protocol</th>
                                <th class="px-8 py-5">Mobile Signal</th>
                                <th class="px-8 py-5 text-center">Protocol Role</th>
                                <th class="px-8 py-5 text-right">System Control</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <?php if (empty($users_list)): ?>
                                <tr>
                                    <td colspan="5"
                                        class="px-8 py-20 text-center text-[--text-main]/30 italic font-black uppercase tracking-widest text-[10px]">
                                        No authorized users found matching criteria</td>
                                </tr>
                            <?php else:
                                foreach ($users_list as $row):
                                    $roleClean = strtolower($row['role'] ?? 'member');
                                    ?>
                                    <tr class="hover:bg-white/[0.02] group transition-colors">
                                        <td class="px-8 py-6">
                                            <p
                                                class="text-[13px] font-black italic uppercase text-[--text-main] group-hover:text-primary transition-colors">
                                                <?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?></p>
                                        </td>
                                        <td class="px-8 py-6">
                                            <p class="text-[12px] font-medium text-[--text-main]/60">
                                                <?= htmlspecialchars($row['email']) ?></p>
                                        </td>
                                        <td class="px-8 py-6">
                                            <p class="text-[10px] font-black text-[--text-main]/30 tracking-widest uppercase">
                                                <?= htmlspecialchars($row['contact_number'] ?? 'OFFLINE') ?></p>
                                        </td>
                                        <td class="px-8 py-6 text-center">
                                            <span
                                                class="inline-block px-4 py-1.5 rounded-full text-[9px] font-black uppercase italic tracking-widest border border-white/5 bg-white/5 <?= ($roleClean === 'coach' ? 'text-amber-500 border-amber-500/20' : ($roleClean === 'staff' ? 'text-primary border-primary/20' : 'text-emerald-500 border-emerald-500/20')) ?>">
                                                <?= $row['role'] ?>
                                            </span>
                                        </td>
                                        <td class="px-8 py-6 text-right">
                                            <div
                                                class="flex justify-end gap-3 transition-all duration-300">
                                                <button onclick="viewUserProfile(<?= $row['id'] ?>)"
                                                    class="size-9 rounded-xl bg-white/5 flex items-center justify-center hover:bg-primary hover:text-white text-[--text-main]/40 transition-all active:scale-90"
                                                    title="View Detailed Profile">
                                                    <span class="material-symbols-rounded text-lg">visibility</span>
                                                </button>
                                                
                                                <?php if(strtolower($row['role']) === 'tenant'): ?>
                                                    <button class="size-9 rounded-xl bg-white/5 flex items-center justify-center text-[--text-main]/20 opacity-20 cursor-not-allowed" title="System Security: Owner accounts cannot be restricted">
                                                        <span class="material-symbols-rounded text-lg">verified_user</span>
                                                    </button>
                                                <?php else: ?>
                                                    <?php if($row['is_active']): ?>
                                                        <button onclick="toggleAccountStatus(<?= $row['id'] ?>)" 
                                                            class="size-9 rounded-xl bg-white/5 flex items-center justify-center hover:bg-rose-500/10 hover:text-rose-500 text-[--text-main]/30 transition-all active:scale-90" 
                                                            title="Lock / Restrict Account Access">
                                                            <span class="material-symbols-rounded text-lg">lock</span>
                                                        </button>
                                                    <?php else: ?>
                                                        <button onclick="toggleAccountStatus(<?= $row['id'] ?>)" 
                                                            class="size-9 rounded-xl bg-rose-500/10 border border-rose-500/20 flex items-center justify-center hover:bg-emerald-500/10 hover:text-emerald-500 text-rose-500 transition-all active:scale-90" 
                                                            title="Unlock / Restore Account Access">
                                                            <span class="material-symbols-rounded text-lg">lock_open</span>
                                                        </button>
                                                    <?php endif; ?>
                                                <?php endif; ?>
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
                        <p class="text-[10px] font-black uppercase tracking-widest text-[--text-main]/40">
                            Page <span class="text-[--text-main]"><?= $current_page ?></span> of <span
                                class="text-[--text-main]"><?= $total_pages ?></span>
                            <span class="mx-3 text-[--text-main]/20">•</span> Total <span
                                class="text-[--text-main]"><?= $total_records ?></span> Records
                        </p>
                        <div class="flex items-center gap-2">
                            <!-- Previous Button -->
                            <button onclick="changePage(<?= max(1, $current_page - 1) ?>)"
                                class="size-10 rounded-xl bg-white/5 flex items-center justify-center text-[--text-main]/40 hover:bg-primary hover:text-white transition-all <?= ($current_page <= 1) ? 'opacity-20 pointer-events-none' : '' ?>">
                                <span class="material-symbols-rounded text-xl">chevron_left</span>
                            </button>

                            <!-- Page Numbers -->
                            <div class="flex items-center gap-1">
                                <?php
                                $start_p = max(1, $current_page - 2);
                                $end_p = min($total_pages, $start_p + 4);
                                if ($end_p - $start_p < 4)
                                    $start_p = max(1, $end_p - 4);

                                for ($i = $start_p; $i <= $end_p; $i++):
                                    ?>
                                    <button onclick="changePage(<?= $i ?>)"
                                        class="size-10 rounded-xl flex items-center justify-center text-[11px] font-black transition-all <?= ($i === $current_page) ? 'bg-primary text-white shadow-lg shadow-primary/20' : 'bg-white/5 text-[--text-main]/40 hover:bg-white/10 hover:text-[--text-main]' ?>">
                                        <?= $i ?>
                                    </button>
                                <?php endfor; ?>
                            </div>

                            <!-- Next Button -->
                            <button onclick="changePage(<?= min($total_pages, $current_page + 1) ?>)"
                                class="size-10 rounded-xl bg-white/5 flex items-center justify-center text-[--text-main]/40 hover:bg-primary hover:text-white transition-all <?= ($current_page >= $total_pages) ? 'opacity-20 pointer-events-none' : '' ?>">
                                <span class="material-symbols-rounded text-xl">chevron_right</span>
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Glassmorphism Profile Modal -->
    <div id="userModal"
        class="hidden fixed inset-0 z-[100] items-center justify-center p-6 md:p-12 overflow-hidden transition-all duration-400" 
        style="left: var(--nav-width);">
        <!-- Clickable Backdrop -->
        <div class="absolute inset-0 bg-black/60 backdrop-blur-md" onclick="closeUserModal()"></div>

        <!-- Modal Container -->
        <div
            class="relative w-full max-w-2xl max-h-[90vh] overflow-y-auto no-scrollbar glass-card border-white/10 shadow-[0_0_100px_rgba(0,0,0,0.5)] backdrop-blur-3xl p-8 animate-in fade-in zoom-in duration-300">
            <div id="modalContent">
                <!-- Loaded via AJAX -->
            </div>
        </div>
    </div>

    <!-- Custom Confirmation Modal -->
    <div id="confirmModal"
        class="hidden fixed inset-0 z-[110] items-center justify-center p-6 overflow-hidden transition-all duration-400">
        <!-- Backdrop -->
        <div class="absolute inset-0 bg-black/80 backdrop-blur-sm" onclick="closeConfirmModal()"></div>

        <!-- Confirm Box -->
        <div class="relative w-full max-w-md glass-card border-white/10 shadow-2xl p-8 animate-in fade-in zoom-in duration-300 text-center">
            <div class="size-16 rounded-2xl bg-amber-500/10 border border-amber-500/20 flex items-center justify-center text-amber-500 mx-auto mb-6">
                <span class="material-symbols-rounded text-3xl">gpp_maybe</span>
            </div>
            
            <h3 class="text-xl font-black italic uppercase tracking-tight text-[--text-main] mb-3">Security Protocol</h3>
            <p class="text-sm text-[--text-main]/50 leading-relaxed mb-8">
                Proceed with account status update? This will immediately modify the user's registry access permissions.
            </p>

            <div class="flex gap-3">
                <button onclick="closeConfirmModal()" 
                    class="flex-1 h-12 rounded-xl bg-white/5 border border-white/5 text-[10px] font-black uppercase tracking-widest text-[--text-main]/40 hover:bg-white/10 hover:text-[--text-main] transition-all">
                    Abort Access
                </button>
                <button onclick="executeAccountToggle()" 
                    class="flex-1 h-12 rounded-xl bg-primary text-white text-[10px] font-black uppercase tracking-widest shadow-lg shadow-primary/20 hover:opacity-90 transition-all active:scale-95">
                    Confirm Registry Update
                </button>
            </div>
        </div>
    </div>

</body>

</html>