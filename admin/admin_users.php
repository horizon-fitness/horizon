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

if (!in_array($filter_role, ['', 'Member', 'Coach'], true)) {
    $filter_role = '';
}

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
    SELECT u.user_id as id, u.first_name, u.last_name, u.username, u.email, u.contact_number, COALESCE(m.profile_picture, u.profile_picture) as profile_picture, r.role_name as role, u.created_at, u.is_active 
    FROM users u 
    JOIN user_roles ur ON u.user_id = ur.user_id 
    JOIN roles r ON ur.role_id = r.role_id 
    LEFT JOIN members m ON u.user_id = m.user_id AND m.gym_id = ur.gym_id
    $where_clause 
    GROUP BY u.user_id, r.role_name
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

$stmtMemberTotal = $pdo->prepare("
    SELECT COUNT(DISTINCT u.user_id)
    FROM users u
    JOIN user_roles ur ON u.user_id = ur.user_id
    JOIN roles r ON ur.role_id = r.role_id
    WHERE ur.gym_id = ? AND r.role_name = 'Member'
");
$stmtMemberTotal->execute([$gym_id]);
$total_members = (int) $stmtMemberTotal->fetchColumn();

$stmtCoachTotal = $pdo->prepare("
    SELECT COUNT(DISTINCT u.user_id)
    FROM users u
    JOIN user_roles ur ON u.user_id = ur.user_id
    JOIN roles r ON ur.role_id = r.role_id
    WHERE ur.gym_id = ? AND r.role_name = 'Coach'
");
$stmtCoachTotal->execute([$gym_id]);
$total_coaches = (int) $stmtCoachTotal->fetchColumn();

function getAdminUserAvatarPath($path) {
    if (empty($path)) return '';
    if (strpos($path, 'data:') === 0 || strpos($path, 'http') === 0) return $path;
    $cleanPath = ltrim($path, './');
    if (strpos($cleanPath, 'uploads/') === 0) return '../' . $cleanPath;
    return '../uploads/profile_pics/' . $cleanPath;
}

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

    if (!in_array($role_name, ['member', 'coach'], true)) {
        exit;
    }

    $sql = "SELECT u.*, r.role_name as role, ur.role_status ";
    if ($role_name === 'member') {
        $sql .= ", COALESCE(m.profile_picture, u.profile_picture) as profile_picture, m.member_code, u.birth_date, u.sex, m.occupation, a.address_line, m.emergency_contact_name, m.emergency_contact_number, m.parent_name, m.parent_contact, m.registration_source, m.member_status ";
        $sql .= " FROM users u JOIN user_roles ur ON u.user_id = ur.user_id JOIN roles r ON ur.role_id = r.role_id LEFT JOIN members m ON u.user_id = m.user_id AND m.gym_id = ur.gym_id LEFT JOIN addresses a ON m.address_id = a.address_id ";
    } elseif ($role_name === 'coach') {
        $sql .= ", ca.coach_type as employment_type, 'Coach' as staff_role, c.hire_date, c.status as staff_status ";
        $sql .= " FROM users u JOIN user_roles ur ON u.user_id = ur.user_id JOIN roles r ON ur.role_id = r.role_id LEFT JOIN coaches c ON u.user_id = c.user_id AND c.gym_id = ur.gym_id LEFT JOIN coach_applications ca ON c.coach_application_id = ca.coach_application_id ";
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
                    <div class="size-16 rounded-2xl bg-primary/10 flex items-center justify-center overflow-hidden">
                        <?php if (!empty($u['profile_picture'])): 
                            $pfp_src = getAdminUserAvatarPath($u['profile_picture']);
                        ?>
                            <img src="<?= htmlspecialchars($pfp_src) ?>" class="size-full object-cover" alt="">
                        <?php else: ?>
                            <div class="text-primary font-bold text-2xl uppercase">
                                <?= substr($u['first_name'], 0, 1) ?>
                            </div>
                        <?php endif; ?>
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
                        <h4 class="text-[9px] font-black uppercase text-[--text-main]/40 tracking-widest">Account Details</h4>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="p-4 rounded-2xl bg-white/[0.02] border border-white/5">
                                <p class="text-[8px] font-black uppercase text-[--text-main]/30 tracking-widest mb-1">Username</p>
                                <p class="text-xs font-bold text-[--text-main]">@<?= htmlspecialchars($u['username']) ?></p>
                            </div>
                            <div class="p-4 rounded-2xl bg-white/[0.02] border border-white/5">
                                <p class="text-[8px] font-black uppercase text-[--text-main]/30 tracking-widest mb-1">Status</p>
                                <p class="text-xs font-bold uppercase <?= $u['is_active'] ? 'text-emerald-500' : 'text-rose-500' ?>">
                                    <?= $u['is_active'] ? 'Active' : 'Deactivated' ?>
                                </p>
                            </div>
                        </div>
                    </section>

                    <section class="space-y-4">
                        <h4 class="text-[9px] font-black uppercase text-[--text-main]/40 tracking-widest">Contact Information</h4>
                        <div class="space-y-3">
                            <div class="flex items-center justify-between p-4 rounded-2xl bg-white/[0.02] border border-white/5">
                                <span class="text-[9px] font-black uppercase text-[--text-main]/30 tracking-widest">Email Address</span>
                                <span class="text-xs font-medium text-[--text-main]/70 truncate max-w-[180px]"><?= htmlspecialchars($u['email']) ?></span>
                            </div>
                            <div class="flex items-center justify-between p-4 rounded-2xl bg-white/[0.02] border border-white/5">
                                <span class="text-[9px] font-black uppercase text-[--text-main]/30 tracking-widest">Phone Number</span>
                                <span class="text-xs font-bold text-[--text-main] tracking-widest"><?= htmlspecialchars($u['contact_number'] ?: 'N/A') ?></span>
                            </div>
                        </div>
                    </section>
                </div>

                <div class="space-y-8">
                    <?php if ($role_name === 'member'): ?>
                        <section class="space-y-4">
                            <h4 class="text-[9px] font-black uppercase text-[--text-main]/40 tracking-widest">Member Profile</h4>
                            <div class="grid grid-cols-2 gap-3">
                                <div class="p-4 rounded-2xl bg-white/[0.02] border border-white/5">
                                    <p class="text-[8px] font-black uppercase text-[--text-main]/30 tracking-widest mb-1">Gender</p>
                                    <p class="text-xs font-bold text-[--text-main] uppercase"><?= $u['sex'] ?: 'N/A' ?></p>
                                </div>
                                <div class="p-4 rounded-2xl bg-white/[0.02] border border-white/5">
                                    <p class="text-[8px] font-black uppercase text-[--text-main]/30 tracking-widest mb-1">Birth Date</p>
                                    <p class="text-xs font-bold text-[--text-main]"><?= $u['birth_date'] ? date('M d, Y', strtotime($u['birth_date'])) : 'N/A' ?></p>
                                </div>
                            </div>
                        </section>
                    <?php endif; ?>

                    <?php if ($role_name === 'coach'): ?>
                        <section class="space-y-4">
                            <h4 class="text-[9px] font-black uppercase text-[--text-main]/40 tracking-widest">Coach Profile</h4>
                            <div class="grid grid-cols-2 gap-3">
                                <div class="p-4 rounded-2xl bg-white/[0.02] border border-white/5">
                                    <p class="text-[8px] font-black uppercase text-[--text-main]/30 tracking-widest mb-1">Coach Type</p>
                                    <p class="text-xs font-bold text-[--text-main] uppercase"><?= htmlspecialchars($u['employment_type'] ?: 'N/A') ?></p>
                                </div>
                                <div class="p-4 rounded-2xl bg-white/[0.02] border border-white/5">
                                    <p class="text-[8px] font-black uppercase text-[--text-main]/30 tracking-widest mb-1">Date Joined</p>
                                    <p class="text-xs font-bold text-[--text-main]"><?= $u['hire_date'] ? date('M d, Y', strtotime($u['hire_date'])) : 'N/A' ?></p>
                                </div>
                            </div>
                        </section>
                    <?php endif; ?>

                    <?php if (!empty($u['emergency_contact_name'])): ?>
                        <section class="space-y-4">
                            <h4 class="text-[9px] font-black uppercase text-amber-500/60 tracking-widest">Emergency Contact</h4>
                            <div class="p-5 rounded-2xl bg-amber-500/[0.03] border border-amber-500/10">
                                <p class="text-[10px] font-black text-[--text-main] uppercase mb-1"><?= htmlspecialchars($u['emergency_contact_name']) ?></p>
                                <p class="text-xs font-bold text-amber-500 tracking-widest"><?= htmlspecialchars($u['emergency_contact_number']) ?></p>
                            </div>
                        </section>
                    <?php endif; ?>

                    <?php if (!empty($u['parent_name'])): ?>
                        <section class="space-y-4">
                            <h4 class="text-[9px] font-black uppercase text-sky-500/60 tracking-widest">Parent / Guardian</h4>
                            <div class="p-5 rounded-2xl bg-sky-500/[0.03] border border-sky-500/10">
                                <p class="text-[10px] font-black text-[--text-main] uppercase mb-1"><?= htmlspecialchars($u['parent_name']) ?></p>
                                <p class="text-xs font-bold text-sky-500 tracking-widest"><?= htmlspecialchars($u['parent_contact']) ?></p>
                            </div>
                        </section>
                    <?php endif; ?>
                </div>
            </div>
            

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
            --background-rgb: <?= hexToRgb($bg_color) ?>;
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

        .label-muted {
            color: var(--text-main);
            opacity: 0.5;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.15em;
        }

        .table-header-alt {
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.25em;
            color: var(--text-main);
            opacity: 0.5;
        }

        .status-card-primary {
            border: 1px solid rgba(var(--primary-rgb), 0.3);
            background: linear-gradient(135deg, rgba(var(--primary-rgb), 0.05) 0%, rgba(var(--primary-rgb), 0.01) 100%);
        }

        .status-card-green {
            border: 1px solid rgba(16, 185, 129, 0.3);
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.05) 0%, rgba(16, 185, 129, 0.01) 100%);
        }

        .status-card-yellow {
            border: 1px solid rgba(245, 158, 11, 0.3);
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.05) 0%, rgba(245, 158, 11, 0.01) 100%);
        }

        .pagination-btn {
            padding: 8px 16px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.05);
            color: var(--text-main);
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            transition: all 0.2s ease;
        }

        .pagination-btn:hover:not(.disabled) {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }

        .pagination-btn.active {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }

        .pagination-btn.disabled {
            opacity: 0.2;
            pointer-events: none;
        }

        .selected-option {
            background-color: var(--primary) !important;
            color: #ffffff !important;
        }

        .custom-select-dropdown {
            background-color: #141216;
            border: 1px solid rgba(255, 255, 255, 0.10);
            box-shadow: 0 18px 45px rgba(0, 0, 0, 0.45);
        }

        #userModal {
            left: 110px;
            transition: left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .side-nav:hover~#userModal {
            left: 300px;
        }

        #userModal.flex {
            display: flex !important;
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
            const backdrop = document.getElementById('user-modal-backdrop');
            const panel = document.getElementById('user-modal-content');
            const content = document.getElementById('modalContent');

            modal.classList.add('flex');
            modal.classList.remove('hidden');
            setTimeout(() => {
                backdrop.classList.remove('opacity-0');
                panel.classList.remove('scale-90', 'opacity-0');
                panel.classList.add('scale-100', 'opacity-100');
            }, 10);
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
            const backdrop = document.getElementById('user-modal-backdrop');
            const panel = document.getElementById('user-modal-content');

            backdrop.classList.add('opacity-0');
            panel.classList.remove('scale-100', 'opacity-100');
            panel.classList.add('scale-90', 'opacity-0');

            setTimeout(() => {
                modal.classList.remove('flex');
                modal.classList.add('hidden');
            }, 300);
        }

        function toggleCustomDropdown(trigger, event) {
            event.stopPropagation();
            const dropdown = trigger.nextElementSibling;
            const container = trigger.closest('.custom-select-container');

            document.querySelectorAll('.custom-select-dropdown').forEach((item) => {
                if (item !== dropdown) item.classList.add('hidden');
            });
            document.querySelectorAll('.custom-select-container').forEach((item) => {
                if (item !== container) item.classList.remove('is-open');
            });

            dropdown.classList.toggle('hidden');
            container.classList.toggle('is-open', !dropdown.classList.contains('hidden'));
        }

        document.addEventListener('click', (event) => {
            const customOption = event.target.closest('.custom-option');

            if (customOption) {
                event.stopPropagation();
                const container = customOption.closest('.custom-select-container');
                const hiddenInput = container.querySelector('input[type="hidden"]');
                const displayInput = container.querySelector('input[type="text"]');
                const dropdown = container.querySelector('.custom-select-dropdown');

                hiddenInput.value = customOption.dataset.value;
                displayInput.value = customOption.textContent.trim();

                container.querySelectorAll('.custom-option').forEach((option) => {
                    option.classList.remove('selected-option');
                    option.classList.add('text-white/60');
                });

                customOption.classList.add('selected-option');
                customOption.classList.remove('text-white/60');
                dropdown.classList.add('hidden');
                container.classList.remove('is-open');
                reactiveFilter();
                return;
            }

            if (!event.target.closest('.custom-select-container')) {
                document.querySelectorAll('.custom-select-dropdown').forEach((item) => item.classList.add('hidden'));
                document.querySelectorAll('.custom-select-container').forEach((item) => item.classList.remove('is-open'));
            }
        });

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

    <!-- Dynamic Admin Sidebar -->
    <?php include '../includes/admin_sidebar.php'; ?>

    <div class="main-content flex-1 overflow-y-auto no-scrollbar">
        <main class="p-10 max-w-[1400px] mx-auto pb-20">
            <header class="mb-10 flex justify-between items-end gap-6">
                <div>
                    <h2 class="text-3xl font-black uppercase tracking-tighter italic" style="color:var(--text-main)">
                        All <span style="color:var(--primary)" class="italic">Users</span>
                    </h2>
                    <p class="label-muted mt-1 italic"><?= htmlspecialchars($gym_name) ?> Members and Coaches</p>
                </div>

                <div class="text-right shrink-0">
                    <p id="headerClock" class="font-black italic text-2xl leading-none tracking-tighter" style="color:var(--text-main)">00:00:00 AM</p>
                    <p class="text-[10px] font-bold uppercase tracking-widest mt-2 px-1 opacity-80" style="color:var(--primary)"><?= date('l, M d, Y') ?></p>
                </div>
            </header>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">
                <div class="glass-card p-8 status-card-primary relative overflow-hidden group hover:scale-[1.02] transition-all">
                    <span class="material-symbols-rounded absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform" style="color:var(--primary)">group</span>
                    <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">All People</p>
                    <h3 class="text-3xl font-black italic uppercase" style="color:var(--text-main)"><?= $total_members + $total_coaches ?></h3>
                    <p class="text-[10px] font-black uppercase mt-2 italic" style="color:var(--primary)">Members and Coaches</p>
                </div>

                <div class="glass-card p-8 status-card-green relative overflow-hidden group hover:scale-[1.02] transition-all">
                    <span class="material-symbols-rounded absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-emerald-500">how_to_reg</span>
                    <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">Gym Members</p>
                    <h3 class="text-3xl font-black italic uppercase" style="color:var(--text-main)"><?= $total_members ?></h3>
                    <p class="text-emerald-500 text-[10px] font-black uppercase mt-2 italic">Members List</p>
                </div>

                <div class="glass-card p-8 status-card-yellow relative overflow-hidden group hover:scale-[1.02] transition-all">
                    <span class="material-symbols-rounded absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-amber-500">workspace_premium</span>
                    <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">Coaches</p>
                    <h3 class="text-3xl font-black italic uppercase" style="color:var(--text-main)"><?= $total_coaches ?></h3>
                    <p class="text-amber-500 text-[10px] font-black uppercase mt-2 italic">Coaches List</p>
                </div>
            </div>

            <div id="usersTableContainer" class="glass-card overflow-hidden flex flex-col">
                <div class="p-8 border-b border-white/5 bg-white/[0.01]">
                    <form id="filterForm" method="GET" onsubmit="event.preventDefault(); reactiveFilter();"
                        class="flex flex-wrap items-center gap-5 relative">
                        <div class="flex-1 min-w-[280px] relative group">
                            <span class="material-symbols-rounded absolute left-4 top-1/2 -translate-y-1/2 text-base text-primary/50 transition-transform group-hover:scale-110">search</span>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                                placeholder="Search records..." oninput="reactiveFilter()" autocomplete="off"
                                class="w-full h-[52px] bg-white/5 border border-white/10 rounded-2xl pl-12 pr-4 text-[10px] font-black uppercase tracking-widest outline-none text-white hover:border-white/20 transition-all focus:border-primary">
                        </div>

                        <div class="w-[180px] relative group shrink-0 custom-select-container">
                            <?php
                            $roleDisplay = 'All Roles';
                            if ($filter_role === 'Member') $roleDisplay = 'Members';
                            if ($filter_role === 'Coach') $roleDisplay = 'Coach';
                            ?>
                            <input type="hidden" name="role" value="<?= htmlspecialchars($filter_role) ?>">
                            <div class="relative custom-select-trigger cursor-pointer" onclick="toggleCustomDropdown(this, event)">
                                <input type="text" readonly value="<?= htmlspecialchars($roleDisplay) ?>"
                                    class="w-full h-[52px] bg-white/5 border border-white/10 rounded-2xl pl-5 pr-11 text-[10px] font-black uppercase tracking-widest outline-none text-white hover:border-white/20 transition-all cursor-pointer pointer-events-none">
                                <span class="material-symbols-rounded absolute right-4 top-1/2 -translate-y-1/2 text-primary/70 text-base pointer-events-none transition-transform group-hover:scale-110">expand_more</span>
                            </div>
                            <div class="absolute left-0 right-0 top-full mt-2 z-[100] rounded-xl p-1.5 space-y-0.5 custom-select-dropdown hidden max-h-64 overflow-y-auto">
                                <div class="custom-option px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-all <?= $filter_role === '' ? 'selected-option' : 'text-white/60' ?>" data-value="">All Roles</div>
                                <div class="custom-option px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-all <?= $filter_role === 'Member' ? 'selected-option' : 'text-white/60' ?>" data-value="Member">Members</div>
                                <div class="custom-option px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-all <?= $filter_role === 'Coach' ? 'selected-option' : 'text-white/60' ?>" data-value="Coach">Coach</div>
                            </div>
                        </div>

                        <div class="w-[170px] relative group shrink-0 custom-select-container">
                            <?php
                            $statusDisplay = 'All Status';
                            if ($filter_status === '1') $statusDisplay = 'Active';
                            if ($filter_status === '0') $statusDisplay = 'Restricted';
                            ?>
                            <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>">
                            <div class="relative custom-select-trigger cursor-pointer" onclick="toggleCustomDropdown(this, event)">
                                <input type="text" readonly value="<?= htmlspecialchars($statusDisplay) ?>"
                                    class="w-full h-[52px] bg-white/5 border border-white/10 rounded-2xl pl-5 pr-11 text-[10px] font-black uppercase tracking-widest outline-none text-white hover:border-white/20 transition-all cursor-pointer pointer-events-none">
                                <span class="material-symbols-rounded absolute right-4 top-1/2 -translate-y-1/2 text-primary/70 text-base pointer-events-none transition-transform group-hover:scale-110">expand_more</span>
                            </div>
                            <div class="absolute left-0 right-0 top-full mt-2 z-[100] rounded-xl p-1.5 space-y-0.5 custom-select-dropdown hidden max-h-64 overflow-y-auto">
                                <div class="custom-option px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-all <?= $filter_status === '' ? 'selected-option' : 'text-white/60' ?>" data-value="">All Status</div>
                                <div class="custom-option px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-all <?= $filter_status === '1' ? 'selected-option' : 'text-white/60' ?>" data-value="1">Active</div>
                                <div class="custom-option px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-all <?= $filter_status === '0' ? 'selected-option' : 'text-white/60' ?>" data-value="0">Restricted</div>
                            </div>
                        </div>

                        <div class="w-[180px] relative group shrink-0 custom-select-container">
                            <?php
                            $sortDisplay = 'Newest';
                            if ($sort_by === 'oldest') $sortDisplay = 'Oldest';
                            if ($sort_by === 'name_asc') $sortDisplay = 'Name A-Z';
                            if ($sort_by === 'name_desc') $sortDisplay = 'Name Z-A';
                            ?>
                            <input type="hidden" name="sort" value="<?= htmlspecialchars($sort_by) ?>">
                            <div class="relative custom-select-trigger cursor-pointer" onclick="toggleCustomDropdown(this, event)">
                                <input type="text" readonly value="<?= htmlspecialchars($sortDisplay) ?>"
                                    class="w-full h-[52px] bg-white/5 border border-white/10 rounded-2xl pl-5 pr-11 text-[10px] font-black uppercase tracking-widest outline-none text-white hover:border-white/20 transition-all cursor-pointer pointer-events-none">
                                <span class="material-symbols-rounded absolute right-4 top-1/2 -translate-y-1/2 text-primary/70 text-base pointer-events-none transition-transform group-hover:scale-110">expand_more</span>
                            </div>
                            <div class="absolute left-0 right-0 top-full mt-2 z-[100] rounded-xl p-1.5 space-y-0.5 custom-select-dropdown hidden max-h-64 overflow-y-auto">
                                <div class="custom-option px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-all <?= $sort_by === 'newest' ? 'selected-option' : 'text-white/60' ?>" data-value="newest">Newest</div>
                                <div class="custom-option px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-all <?= $sort_by === 'oldest' ? 'selected-option' : 'text-white/60' ?>" data-value="oldest">Oldest</div>
                                <div class="custom-option px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-all <?= $sort_by === 'name_asc' ? 'selected-option' : 'text-white/60' ?>" data-value="name_asc">Name A-Z</div>
                                <div class="custom-option px-4 py-3 rounded-lg text-[10px] font-black uppercase tracking-wider cursor-pointer hover:bg-white/5 transition-all <?= $sort_by === 'name_desc' ? 'selected-option' : 'text-white/60' ?>" data-value="name_desc">Name Z-A</div>
                            </div>
                        </div>

                        <button type="button" onclick="clearFilters()"
                            class="h-[52px] w-[52px] rounded-2xl bg-white/5 border border-white/5 flex items-center justify-center text-white/30 hover:text-white hover:bg-white/10 transition-all"
                            title="Reset filters">
                            <span class="material-symbols-rounded text-lg">refresh</span>
                        </button>
                    </form>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="bg-white/5 border-b border-white/5">
                                <th class="px-8 py-5 table-header-alt">ID</th>
                                <th class="px-8 py-5 table-header-alt">Name</th>
                                <th class="px-8 py-5 table-header-alt">Role</th>
                                <th class="px-8 py-5 table-header-alt">Email</th>
                                <th class="px-8 py-5 table-header-alt">Phone Number</th>
                                <th class="px-8 py-5 table-header-alt">Joined Date</th>
                                <th class="px-8 py-5 table-header-alt text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody id="usersTableBody" class="divide-y divide-white/5 text-sm font-medium">
                            <?php if (empty($users_list)): ?>
                                <tr class="no-pagination">
                                    <td colspan="7" class="px-8 py-24 text-center text-[11px] font-black italic uppercase tracking-[0.3em] text-[--text-main] opacity-20">
                                        No users found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($users_list as $u): ?>
                                    <tr class="group hover:bg-white/[0.02] transition-colors">
                                        <td class="px-8 py-6 align-middle">
                                            <p class="text-[11px] font-black text-[--text-main]/60 tracking-widest">
                                                ID-<?= str_pad($u['id'], 5, '0', STR_PAD_LEFT) ?>
                                            </p>
                                        </td>
                                        <td class="px-8 py-6 align-middle">
                                            <div class="flex items-center gap-4">
                                                <?php $hasPic = !empty($u['profile_picture']); ?>
                                                <div class="size-11 rounded-full flex items-center justify-center font-black text-[11px] border border-white/10 shrink-0 overflow-hidden shadow-inner relative"
                                                    style="background:rgba(var(--primary-rgb), 0.1); color:var(--primary)">
                                                    <img src="<?= $hasPic ? getAdminUserAvatarPath($u['profile_picture']) : '' ?>"
                                                        class="size-full object-cover<?= $hasPic ? '' : ' hidden' ?>"
                                                        onerror="this.classList.add('hidden'); this.nextElementSibling.classList.remove('hidden'); this.nextElementSibling.style.display='flex';">
                                                    <div class="<?= $hasPic ? 'hidden' : '' ?> size-full flex items-center justify-center">
                                                        <?= strtoupper(substr($u['first_name'] ?? 'U', 0, 1) . substr($u['last_name'] ?? '', 0, 1)) ?>
                                                    </div>
                                                </div>
                                                <p class="text-[13px] font-bold tracking-wider" style="color:var(--text-main)">
                                                    <?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?>
                                                </p>
                                            </div>
                                        </td>
                                        <td class="px-8 py-6 align-middle">
                                            <span class="text-[13px] font-bold tracking-wider <?= strtolower($u['role']) === 'coach' ? 'text-amber-500' : 'text-emerald-500' ?>">
                                                <?= htmlspecialchars($u['role']) ?>
                                            </span>
                                        </td>
                                        <td class="px-8 py-6 align-middle">
                                            <p class="text-[12px] font-medium opacity-60 tracking-wide" style="color:var(--text-main)">
                                                <?= htmlspecialchars($u['email']) ?>
                                            </p>
                                        </td>
                                        <td class="px-8 py-6 align-middle">
                                            <p class="text-[12px] font-medium opacity-60 tracking-wide" style="color:var(--text-main)">
                                                <?= htmlspecialchars($u['contact_number'] ?: 'None') ?>
                                            </p>
                                        </td>
                                        <td class="px-8 py-6 align-middle">
                                            <p class="text-[12px] font-bold tracking-wider" style="color:var(--primary)">
                                                <?= date('M d, Y', strtotime($u['created_at'])) ?>
                                            </p>
                                        </td>
                                        <td class="px-8 py-6 text-center align-middle">
                                            <button onclick="viewUserProfile(<?= $u['id'] ?>)"
                                                class="size-8 rounded-lg bg-white/5 hover:bg-primary transition-all flex items-center justify-center opacity-40 hover:opacity-100 inline-flex border border-white/5"
                                                title="View profile">
                                                <span class="material-symbols-rounded text-lg">visibility</span>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_pages > 1): ?>
                    <div class="px-8 py-5 border-t border-white/5 bg-white/[0.01] flex justify-between items-center">
                        <p class="text-[10px] font-black uppercase tracking-widest text-[--text-main]/40">
                            Showing page <span class="text-[--text-main]"><?= $current_page ?></span> of
                            <span class="text-[--text-main]"><?= $total_pages ?></span>
                        </p>
                        <div class="flex items-center gap-2">
                            <button onclick="changePage(<?= max(1, $current_page - 1) ?>)" class="pagination-btn <?= ($current_page <= 1) ? 'disabled' : '' ?>">Prev</button>
                            <?php
                            $start_p = max(1, $current_page - 2);
                            $end_p = min($total_pages, $start_p + 4);
                            if ($end_p - $start_p < 4) $start_p = max(1, $end_p - 4);
                            for ($i = $start_p; $i <= $end_p; $i++):
                            ?>
                                <button onclick="changePage(<?= $i ?>)" class="pagination-btn <?= ($i === $current_page) ? 'active' : '' ?>"><?= $i ?></button>
                            <?php endfor; ?>
                            <button onclick="changePage(<?= min($total_pages, $current_page + 1) ?>)" class="pagination-btn <?= ($current_page >= $total_pages) ? 'disabled' : '' ?>">Next</button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    <div id="userModal"
        class="hidden fixed top-0 right-0 bottom-0 z-[2000] items-center justify-center p-6 md:p-12 overflow-hidden pointer-events-none">
        <div id="user-modal-backdrop"
            class="absolute inset-0 transition-opacity duration-300 opacity-0 bg-[rgba(var(--background-rgb),0.4)] backdrop-blur-[20px] backdrop-saturate-[180%] pointer-events-auto"
            onclick="closeUserModal()"></div>

        <div id="user-modal-content"
            class="relative z-10 bg-[--card-bg] w-full max-w-2xl max-h-[90vh] overflow-y-auto no-scrollbar rounded-[28px] shadow-2xl border border-white/5 backdrop-blur-3xl p-8 transform transition-all duration-300 scale-90 opacity-0 pointer-events-auto">
            <div id="modalContent">
                <!-- Loaded via AJAX -->
            </div>
        </div>
    </div>

</body>

</html>
