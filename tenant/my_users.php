<?php
session_start();
require_once '../db.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

// ── 4-Color Elite Branding System ─────────────────────────────────────────────
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

// 1. Hard defaults
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

// 2. Merge global settings (user_id = 0)
$stmtGlobal = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = 0");
$stmtGlobal->execute();
foreach (($stmtGlobal->fetchAll(PDO::FETCH_KEY_PAIR) ?: []) as $k => $v) {
    if ($v !== null && $v !== '') $configs[$k] = $v;
}

// 3. Merge tenant-specific settings (user_id = ?)
$stmtTenant = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = ?");
$stmtTenant->execute([$user_id]);
foreach (($stmtTenant->fetchAll(PDO::FETCH_KEY_PAIR) ?: []) as $k => $v) {
    if ($v !== null && $v !== '') $configs[$k] = $v;
}

// 4. Resolved branding tokens
$theme_color     = $configs['theme_color'];
$highlight_color = $configs['secondary_color'];
$text_color      = $configs['text_color'];
$bg_color        = $configs['bg_color'];
$font_family     = $configs['font_family'] ?? 'Lexend';
$auto_card_theme = $configs['auto_card_theme'] ?? '1';
$card_color      = $configs['card_color'];

$primary_rgb   = hexToRgb($theme_color);
$highlight_rgb = hexToRgb($highlight_color);
$card_bg_css   = ($auto_card_theme === '1')
    ? "rgba({$primary_rgb}, 0.05)"
    : $card_color;

// 5. $page convenience array for sidebar
$page = [
    'logo_path'   => $configs['system_logo'] ?? '',
    'theme_color' => $theme_color,
    'bg_color'    => $bg_color,
    'system_name' => $configs['system_name'] ?? $gym_name,
];

// Fetch Active Subscription / Plan (Updated with LEFT JOIN for broad coverage)
$stmtSub = $pdo->prepare("
    SELECT cs.subscription_status, wp.plan_name 
    FROM client_subscriptions cs 
    LEFT JOIN website_plans wp ON cs.website_plan_id = wp.website_plan_id 
    WHERE cs.gym_id = ? 
    ORDER BY cs.created_at DESC LIMIT 1
");
$stmtSub->execute([$gym_id]);
$subscription = $stmtSub->fetch();

$plan_name = $subscription['plan_name'] ?? 'No Plan';
$sub_status = $subscription['subscription_status'] ?? 'None';
$is_sub_active = (strtolower($sub_status) === 'active');

// Determine if we show the restriction modal (Only for non-active)
$is_restricted = (!$is_sub_active);

// Avatar Path Helper
function getAvatarPath($path) {
    if (empty($path)) return '';
    // Handle base64 or absolute URLs
    if (strpos($path, 'data:') === 0 || strpos($path, 'http') === 0) return $path;
    // Clean redundant relative prefixes
    $cleanPath = ltrim($path, './');
    if (strpos($cleanPath, 'uploads/') === 0) return '../' . $cleanPath;
    return '../uploads/profile_pics/' . $cleanPath;
}

// --- AJAX USER PROFILE FETCH (View Details) ---
if (isset($_GET['ajax_user_id'])) {
    $uid = (int) $_GET['ajax_user_id'];

    // 1. First fetch basic info to determine role
    $stmtBasic = $pdo->prepare("SELECT r.role_name FROM users u JOIN user_roles ur ON u.user_id = ur.user_id JOIN roles r ON ur.role_id = r.role_id WHERE u.user_id = ? AND ur.gym_id = ? LIMIT 1");
    $stmtBasic->execute([$uid, $gym_id]);
    $basic_role = $stmtBasic->fetchColumn() ?: '';
    $role_name_lc = strtolower($basic_role);

    // 2. Fetch full user data based on role
    $sql = "SELECT u.*, r.role_name as role, ur.role_status ";
    if ($role_name_lc === 'member') {
        $sql .= ", m.member_code, u.birth_date, u.sex, m.occupation, a.address_line, m.medical_history, m.emergency_contact_name, m.emergency_contact_number ";
        $sql .= " FROM users u JOIN user_roles ur ON u.user_id = ur.user_id JOIN roles r ON ur.role_id = r.role_id LEFT JOIN members m ON u.user_id = m.user_id LEFT JOIN addresses a ON m.address_id = a.address_id ";
    } elseif ($role_name_lc === 'staff') {
        $sql .= ", s.staff_role, s.employment_type, s.hire_date, s.status as staff_status ";
        $sql .= " FROM users u JOIN user_roles ur ON u.user_id = ur.user_id JOIN roles r ON ur.role_id = r.role_id LEFT JOIN staff s ON u.user_id = s.user_id AND s.gym_id = ur.gym_id ";
    } elseif ($role_name_lc === 'coach') {
        $sql .= ", ca.coach_type as employment_type, 'Coach' as staff_role, c.hire_date, c.status as staff_status ";
        $sql .= " FROM users u 
                  JOIN user_roles ur ON u.user_id = ur.user_id 
                  JOIN roles r ON ur.role_id = r.role_id 
                  LEFT JOIN coaches c ON u.user_id = c.user_id AND c.gym_id = ur.gym_id 
                  LEFT JOIN coach_applications ca ON c.coach_application_id = ca.coach_application_id ";
    } else {
        $sql .= " FROM users u JOIN user_roles ur ON u.user_id = ur.user_id JOIN roles r ON ur.role_id = r.role_id ";
    }
    $sql .= " WHERE u.user_id = ? AND ur.gym_id = ? LIMIT 1";

    $stmtUser = $pdo->prepare($sql);
    $stmtUser->execute([$uid, $gym_id]);
    $u = $stmtUser->fetch();

    if ($u): ?>
        <div class="animate-in fade-in slide-in-from-bottom-4 duration-500">
            <header class="flex justify-between items-center mb-8 border-b border-white/5 pb-6">
                <div class="flex items-center gap-4">
                    <div class="size-20 rounded-2xl flex items-center justify-center font-bold text-xl uppercase border-2 overflow-hidden shadow-lg relative"
                         style="background:rgba(var(--primary-rgb), 0.1); border-color:rgba(var(--primary-rgb), 0.3); color:var(--primary)">
                        <?php $hasPic = !empty($u['profile_picture']); ?>
                        <img src="<?= $hasPic ? getAvatarPath($u['profile_picture']) : '' ?>" 
                             class="size-full object-cover<?= $hasPic ? '' : ' hidden' ?>" 
                             onerror="this.classList.add('hidden'); this.nextElementSibling.classList.remove('hidden'); this.nextElementSibling.style.display='flex';">
                        <div class="<?= $hasPic ? 'hidden' : '' ?> size-full flex items-center justify-center">
                            <?= strtoupper(substr($u['first_name'] ?? 'U', 0, 1) . substr($u['last_name'] ?? '', 0, 1)) ?>
                        </div>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold text-white leading-tight">
                            <?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?>
                        </h2>
                        <p class="text-[11px] font-bold opacity-40 tracking-widest mt-1"><?= htmlspecialchars($u['email']) ?></p>
                    </div>
                </div>
                <button onclick="closeUserModal()"
                    class="size-8 rounded-lg bg-white/5 hover:bg-white/10 flex items-center justify-center opacity-50 hover:opacity-100 transition-all border border-white/5">
                    <span class="material-symbols-outlined text-lg">close</span>
                </button>
            </header>

            <div class="space-y-6">
                <!-- Section 1: Core Details -->
                <section class="grid grid-cols-2 gap-6 bg-white/[0.02] p-6 rounded-2xl border border-white/5">
                    <div class="space-y-1">
                        <p class="text-[10px] font-bold uppercase tracking-widest opacity-40">Full Name</p>
                        <p class="text-base font-medium text-white"><?= htmlspecialchars($u['first_name'] . ($u['middle_name'] ? ' ' . $u['middle_name'] : '') . ' ' . $u['last_name']) ?></p>
                    </div>
                    <div class="space-y-1">
                        <p class="text-[10px] font-bold uppercase tracking-widest opacity-40">System Role</p>
                        <p class="text-base font-medium text-white"><?= $u['role'] ?></p>
                    </div>
                    <div class="space-y-1">
                        <p class="text-[10px] font-bold uppercase tracking-widest opacity-40">Contact Number</p>
                        <p class="text-base font-medium text-white"><?= htmlspecialchars($u['contact_number'] ?: 'None') ?></p>
                    </div>
                    <?php if ($role_name_lc === 'member'): ?>
                        <div class="space-y-1">
                            <p class="text-[10px] font-bold uppercase tracking-widest opacity-40">Sex</p>
                            <p class="text-base font-medium text-white"><?= $u['sex'] ?: 'N/A' ?></p>
                        </div>
                    <?php endif; ?>
                </section>

                <!-- Section 2: Extended Details -->
                <section class="grid grid-cols-1 gap-6">
                    <?php if ($role_name_lc === 'member' && !empty($u['address_line'])): ?>
                        <div class="bg-white/[0.02] p-6 rounded-2xl border border-white/5 space-y-1">
                            <p class="text-[9px] font-bold uppercase tracking-widest opacity-40">Home Address</p>
                            <p class="text-sm font-medium text-white leading-relaxed"><?= htmlspecialchars($u['address_line']) ?></p>
                        </div>
                    <?php endif; ?>

                    <?php if ($role_name_lc === 'staff' || $role_name_lc === 'coach'): ?>
                        <div class="bg-white/[0.02] p-6 rounded-2xl border border-white/5 grid grid-cols-2 gap-6">
                            <div class="space-y-1">
                                <p class="text-[9px] font-bold uppercase tracking-widest opacity-40">Job Type</p>
                                <p class="text-sm font-medium text-white"><?= $u['employment_type'] ?: 'N/A' ?></p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-[9px] font-bold uppercase tracking-widest opacity-40">Date Joined</p>
                                <p class="text-sm font-medium text-white"><?= $u['hire_date'] ? date('M d, Y', strtotime($u['hire_date'])) : 'N/A' ?></p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($u['medical_history'])): ?>
                        <div class="bg-white/[0.02] p-6 rounded-2xl border border-white/5 space-y-2">
                            <p class="text-[9px] font-bold uppercase tracking-widest opacity-40">Medical Notes</p>
                            <p class="text-xs font-medium opacity-70 leading-relaxed"><?= nl2br(htmlspecialchars($u['medical_history'])) ?></p>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($u['emergency_contact_name'])): ?>
                        <div class="bg-amber-500/5 p-6 rounded-2xl border border-amber-500/10 grid grid-cols-2 gap-6">
                            <div class="space-y-1">
                                <p class="text-[10px] font-bold uppercase tracking-widest text-amber-500/60">Emergency Contact</p>
                                <p class="text-base font-bold text-amber-500"><?= htmlspecialchars($u['emergency_contact_name']) ?></p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-[10px] font-bold uppercase tracking-widest text-amber-500/60">Contact Phone</p>
                                <p class="text-base font-bold text-amber-500"><?= htmlspecialchars($u['emergency_contact_number']) ?></p>
                            </div>
                        </div>
                    <?php endif; ?>
                </section>

                <footer class="flex justify-between items-center pt-4 opacity-40">
                    <div class="flex items-center gap-2">
                        <span class="size-1.5 rounded-full <?= $u['is_active'] ? 'bg-emerald-500' : 'bg-rose-500' ?>"></span>
                        <p class="text-[8px] font-bold uppercase tracking-[0.2em]"><?= $u['is_active'] ? 'Account Active' : 'Account Blocked' ?></p>
                    </div>
                </footer>
            </div>
        </div>
<?php endif;
    exit;
}

if (isset($_GET['ajax_search'])) {
    $search = trim($_GET['ajax_search']);
    
    if (empty($search)) {
        // Show "Recent Activity" (Top 5 newest users)
        $stmt = $pdo->prepare("
            SELECT u.user_id, u.first_name, u.last_name, u.email, u.profile_picture, r.role_name as role
            FROM users u
            JOIN user_roles ur ON u.user_id = ur.user_id
            JOIN roles r ON ur.role_id = r.role_id
            WHERE ur.gym_id = ?
            ORDER BY u.created_at DESC
            LIMIT 5
        ");
        $stmt->execute([$gym_id]);
        $matches = $stmt->fetchAll();
        $is_recent = true;
    } else {
        $stmt = $pdo->prepare("
            SELECT u.user_id, u.first_name, u.last_name, u.email, u.profile_picture, r.role_name as role
            FROM users u
            JOIN user_roles ur ON u.user_id = ur.user_id
            JOIN roles r ON ur.role_id = r.role_id
            WHERE ur.gym_id = ? 
            AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)
            LIMIT 6
        ");
        $stmt->execute([$gym_id, "%$search%", "%$search%", "%$search%"]);
        $matches = $stmt->fetchAll();
        $is_recent = false;
    }

    if ($matches): ?>
        <div class="p-2 space-y-1">
            <?php if ($is_recent): ?>
                <div class="px-4 py-3 flex items-center justify-between">
                    <p class="text-[9px] font-black uppercase tracking-[0.2em] text-primary opacity-80">Recent Activity</p>
                    <span class="size-1.5 rounded-full bg-primary animate-pulse"></span>
                </div>
            <?php endif; ?>
            <?php foreach ($matches as $m): ?>
                <button type="button" 
                    onclick="const inp = document.querySelector('input[name=\'search\']'); inp.value = '<?= addslashes($m['first_name'] . ' ' . $m['last_name']) ?>'; reactiveFilter(); document.getElementById('searchResults').classList.add('hidden'); viewUserProfile(<?= $m['user_id'] ?>);"
                    class="w-full flex items-center gap-3 p-2.5 rounded-xl hover:bg-white/5 transition-all text-left group border border-transparent hover:border-white/5">
                    <div class="size-10 rounded-full flex items-center justify-center font-black italic text-[10px] border border-white/10 shrink-0 overflow-hidden shadow-inner relative bg-primary/10 text-primary">
                        <?php if (!empty($m['profile_picture'])): ?>
                            <img src="<?= getAvatarPath($m['profile_picture']) ?>" class="size-full object-cover">
                        <?php else: ?>
                            <?= strtoupper(substr($m['first_name'], 0, 1) . substr($m['last_name'], 0, 1)) ?>
                        <?php endif; ?>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-[13px] font-bold text-white group-hover:text-primary transition-colors truncate">
                            <?= htmlspecialchars($m['first_name'] . ' ' . $m['last_name']) ?>
                        </p>
                        <p class="text-[10px] opacity-40 font-medium truncate uppercase tracking-widest">
                            <?= htmlspecialchars($m['role']) ?> • <?= htmlspecialchars($m['email']) ?>
                        </p>
                    </div>
                    <span class="material-symbols-outlined text-sm opacity-0 group-hover:opacity-100 transition-all text-primary">arrow_forward</span>
                </button>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="p-6 text-center">
            <p class="text-[10px] font-bold uppercase tracking-widest opacity-30">No results found for "<?= htmlspecialchars($search) ?>"</p>
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
if ($sort_by === 'name_desc')
    $order = "ORDER BY u.first_name DESC";

$where_sql = "WHERE " . implode(" AND ", $where);

// Fetch Filtered Users
$stmtUsers = $pdo->prepare("
    SELECT u.user_id, u.first_name, u.last_name, u.email, u.contact_number, 
           u.profile_picture, 
           r.role_name as role, u.is_active, u.created_at,
           CASE 
               WHEN r.role_name = 'Member' THEN m.member_status 
               WHEN r.role_name = 'Staff' THEN s.status
               ELSE c.status 
           END as active_status,
           CASE 
               WHEN r.role_name = 'Member' THEN IFNULL(mp.plan_name, 'No Plan') 
               WHEN r.role_name = 'Staff' THEN s.staff_role
               ELSE ca.coach_type 
           END as detail_info
    FROM users u
    JOIN user_roles ur ON u.user_id = ur.user_id
    JOIN roles r ON ur.role_id = r.role_id
    LEFT JOIN members m ON u.user_id = m.user_id
    LEFT JOIN staff s ON u.user_id = s.user_id AND s.gym_id = ur.gym_id
    LEFT JOIN coaches c ON u.user_id = c.user_id AND c.gym_id = ur.gym_id
    LEFT JOIN coach_applications ca ON c.coach_application_id = ca.coach_application_id
    LEFT JOIN member_subscriptions ms ON m.member_id = ms.member_id AND ms.subscription_status = 'Active'
    LEFT JOIN membership_plans mp ON ms.membership_plan_id = mp.membership_plan_id
    $where_sql
    $order
");
$stmtUsers->execute($params);
$users_list = $stmtUsers->fetchAll();

// Statistics (Unfiltered) - Fixed SQL Injection
$total_members = $pdo->prepare("SELECT COUNT(*) FROM members WHERE gym_id = ?");
$total_members->execute([$gym_id]);
$total_members = $total_members->fetchColumn();

$total_coaches = $pdo->prepare("SELECT COUNT(*) FROM coaches WHERE gym_id = ?");
$total_coaches->execute([$gym_id]);
$total_coaches = $total_coaches->fetchColumn();

$page_title = "User Database";
?>

<!DOCTYPE html>
<html class="dark" lang="en">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title><?= htmlspecialchars($page_title) ?> | <?= htmlspecialchars($gym_name) ?></title>

    <link href="https://fonts.googleapis.com/css2?family=<?= urlencode($font_family) ?>:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>

    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: {
                "primary":       "var(--primary)",
                "background-dark":"var(--background)",
                "surface-dark":  "var(--card-bg)",
                "border-subtle": "rgba(255,255,255,0.05)"
            }}}
        }
    </script>

    <style>
        /* ── Elite 4-Color CSS Variable System ── */
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
            overflow: hidden;
        }

        /* Glass Card */
        .glass-card {
            background: var(--card-bg);
            border: 1px solid rgba(255,255,255,0.05);
            border-radius: 24px;
            backdrop-filter: blur(var(--card-blur));
            transition: all 0.4s cubic-bezier(0.4,0,0.2,1);
        }
        .hover-lift:hover {
            transform: translateY(-6px);
            border-color: rgba(var(--primary-rgb),0.25);
        }

        /* Sidebar */
        .side-nav {
            width: 110px;
            transition: width 0.4s cubic-bezier(0.4,0,0.2,1);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            position: fixed;
            left: 0; top: 0;
            height: 100vh;
            z-index: 50;
            background-color: var(--background);
            border-right: 1px solid rgba(255,255,255,0.05);
        }
        .side-nav:hover { width: 300px; }

        .main-content {
            margin-left: 110px;
            flex: 1;
            min-width: 0;
            transition: margin-left 0.4s cubic-bezier(0.4,0,0.2,1);
        }
        .side-nav:hover ~ .main-content { margin-left: 300px; }

        .nav-label {
            opacity: 0;
            transform: translateX(-15px);
            transition: all 0.3s ease-in-out;
            white-space: nowrap;
            pointer-events: none;
            color: var(--text-main);
        }
        .side-nav:hover .nav-label { opacity: 1; transform: translateX(0); pointer-events: auto; }

        .nav-section-label {
            max-height: 0; opacity: 0; overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4,0,0.2,1);
            margin: 0 !important; pointer-events: none;
        }
        .side-nav:hover .nav-section-label {
            max-height: 20px; opacity: 1;
            margin-bottom: 8px !important; pointer-events: auto;
        }

        /* Nav items — no background flash, subtle opacity/scale only */
        .nav-item {
            display: flex; align-items: center; gap: 16px;
            padding: 10px 38px;
            transition: opacity 0.2s ease, color 0.2s ease;
            text-decoration: none; white-space: nowrap;
            font-size: 11px; font-weight: 800;
            text-transform: uppercase; letter-spacing: 0.05em;
            color: color-mix(in srgb, var(--text-main) 45%, transparent);
        }
        .nav-item:hover { color: var(--text-main); }
        .nav-item .material-symbols-outlined {
            color: var(--highlight);
            transition: transform 0.2s ease;
        }
        .nav-item:hover .material-symbols-outlined { transform: scale(1.12); }
        .nav-item.active { color: var(--primary) !important; position: relative; }
        .nav-item.active .material-symbols-outlined { color: var(--primary); }
        .nav-item.active::after {
            content: ''; position: absolute;
            right: 0; top: 50%; transform: translateY(-50%);
            width: 4px; height: 24px;
            background: var(--primary); border-radius: 4px 0 0 4px;
        }

        /* Invisible scroll */
        *::-webkit-scrollbar { display: none !important; }
        * { -ms-overflow-style: none !important; scrollbar-width: none !important; }

        /* Muted label utility */
        .label-muted {
            color: var(--text-main); opacity: 0.5;
            font-size: 10px; font-weight: 800;
            text-transform: uppercase; letter-spacing: 0.15em;
        }

        /* Inputs */
        .input-box {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            color: var(--text-main);
            padding: 12px 18px;
            font-size: 11px;
            font-weight: 600;
            outline: none;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .input-box:focus { 
            border-color: var(--primary); 
            background-color: rgba(255, 255, 255, 0.08);
            box-shadow: 0 0 0 4px rgba(var(--primary-rgb), 0.1);
        }
        .input-box option { background: #14121a; color: white; }
        select.input-box {
            cursor: pointer; 
            color-scheme: dark; 
            appearance: none !important;
            -webkit-appearance: none !important;
            -moz-appearance: none !important;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='white'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E");
            background-repeat: no-repeat !important;
            background-position: right 1.25rem center !important;
            background-size: 0.85em !important;
            padding-right: 3rem !important;
        }

        /* Status Cards (Superadmin Sync) */
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

        /* Sidebar-Aware Modals (Superadmin Pattern) */
        #profileModal {
            position: fixed;
            top: 0; right: 0; bottom: 0;
            left: 110px;
            background: rgba(var(--background-rgb), 0.4);
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            transition: left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        #profileModal.active { display: flex; }
        
        .side-nav:hover ~ #profileModal,
        .side-nav:hover ~ #subModal {
            left: 300px;
        }

        .modal-container {
            position: relative;
            width: 100%;
            max-width: 650px;
            max-height: 85vh;
            overflow-y: auto;
            z-index: 10;
            background: var(--card-bg);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 32px;
        }

        .modal-backdrop { display: none; } /* Handled by parent padding/bg now */

        /* Restriction Modal (Sidebar-Aware) */
        #subModal { 
            position: fixed; top: 0; right: 0; bottom: 0; left: 110px; 
            z-index: 200; display: none !important; 
            align-items: center; justify-content: center; 
            padding: 24px; background: rgba(var(--background-rgb), 0.4); 
            backdrop-filter: blur(20px) saturate(180%); 
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            transition: left 0.4s cubic-bezier(0.4, 0, 0.2, 1); 
        }
        #subModal.active { display: flex !important; }
        .side-nav:hover ~ #subModal { left: 300px; }
    </style>

    <script>
        function showSubWarning() { document.getElementById('subModal').classList.add('active'); }
        function closeSubModal() { document.getElementById('subModal').classList.remove('active'); }

        window.addEventListener('DOMContentLoaded', () => {
            <?php if ($is_restricted): ?>
            showSubWarning();
            <?php endif; ?>
        });

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
        let searchTimeout;

        function reactiveFilter() {
            clearTimeout(filterTimeout);
            clearTimeout(searchTimeout);

            const searchInput = document.querySelector('input[name="search"]');
            const query = searchInput.value.trim();
            const resultsBox = document.getElementById('searchResults');

            searchTimeout = setTimeout(async () => {
                try {
                    const res = await fetch(`?ajax_search=${encodeURIComponent(query)}`);
                    const html = await res.text();
                    if (html.trim()) {
                        resultsBox.innerHTML = html;
                        resultsBox.classList.remove('hidden');
                    } else {
                        resultsBox.classList.add('hidden');
                    }
                } catch (e) { console.error(e); }
            }, 150);

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
                        const table = doc.getElementById('tableContainer');
                        if (table) {
                            document.getElementById('tableContainer').innerHTML = table.innerHTML;
                        }
                    });
            }, 400);
        }

        window.addEventListener('DOMContentLoaded', () => {
            const searchInput = document.querySelector('input[name="search"]');
            if (searchInput) {
                searchInput.addEventListener('focus', reactiveFilter);
            }
        });

        // Close search results when clicking outside
        document.addEventListener('click', (e) => {
            const resultsBox = document.getElementById('searchResults');
            const searchInput = document.querySelector('input[name="search"]');
            if (!resultsBox.contains(e.target) && e.target !== searchInput) {
                resultsBox.classList.add('hidden');
            }
        });

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

    <?php 
    $active_page = 'users';
    include '../includes/tenant_sidebar.php'; 
    ?>

    <main class="main-content flex-1 p-10 overflow-y-auto no-scrollbar">


        <header class="mb-10 flex justify-between items-end">
            <div>
                <h2 class="text-3xl font-black uppercase tracking-tighter italic" style="color:var(--text-main)">
                    All <span style="color:var(--primary)" class="italic">Users</span>
                </h2>
                <p class="label-muted mt-1 italic"><?= htmlspecialchars($gym_name) ?> User List</p>
            </div>

            <div class="text-right">
                <p id="topClock" class="font-black italic text-2xl leading-none tracking-tighter" style="color:var(--text-main)">00:00:00 AM</p>
                <p class="text-[10px] font-bold uppercase tracking-widest mt-2 px-1 opacity-80" style="color:var(--primary)"><?= date('l, M d, Y') ?></p>
            </div>
        </header>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">
            <!-- Total Community -->
            <div class="glass-card p-8 status-card-primary relative overflow-hidden group hover:scale-[1.02] transition-all">
                <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform" style="color:var(--primary)">group</span>
                <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">All People</p>
                <h3 class="text-3xl font-black italic uppercase" style="color:var(--text-main)">
                    <?= $total_members + $total_coaches ?>
                </h3>
                <p class="text-[10px] font-black uppercase mt-2 italic" style="color:var(--primary)">Total Users</p>
            </div>

            <!-- Active Members -->
            <div class="glass-card p-8 status-card-green relative overflow-hidden group hover:scale-[1.02] transition-all">
                <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-emerald-500">how_to_reg</span>
                <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">Gym Members</p>
                <h3 class="text-3xl font-black italic uppercase" style="color:var(--text-main)">
                    <?= $total_members ?>
                </h3>
                <p class="text-emerald-500 text-[10px] font-black uppercase mt-2 italic">Active Members</p>
            </div>

            <!-- Expert Coaches -->
            <div class="glass-card p-8 status-card-yellow relative overflow-hidden group hover:scale-[1.02] transition-all">
                <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-amber-500">workspace_premium</span>
                <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">Coaches</p>
                <h3 class="text-3xl font-black italic uppercase" style="color:var(--text-main)">
                    <?= $total_coaches ?>
                </h3>
                <p class="text-amber-500 text-[10px] font-black uppercase mt-2 italic">Coaches List</p>
            </div>
        </div>

        <!-- SEARCH AND FILTERS -->
        <div class="mb-10">
            <form id="filterForm" onsubmit="event.preventDefault(); reactiveFilter();"
                class="glass-card px-8 py-5 flex flex-wrap items-center gap-5 relative">
                
                <!-- Search Input -->
                <div class="flex-1 min-w-[280px] relative group">
                    <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-sm opacity-30 group-focus-within:opacity-100 group-focus-within:text-primary transition-all">search</span>
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                        placeholder="Search users by name or email..." oninput="reactiveFilter()"
                        autocomplete="off"
                        class="input-box pl-12 w-full">
                    
                    <!-- Result Dropdown -->
                    <div id="searchResults" class="absolute top-[calc(100%+8px)] left-0 right-0 glass-card z-[100] hidden overflow-hidden shadow-[0_20px_50px_rgba(0,0,0,0.5)] border-white/10">
                        <!-- Content via AJAX -->
                    </div>
                </div>

                <!-- Role Filter -->
                <div class="w-[180px] relative">
                    <select name="role" onchange="reactiveFilter()" class="input-box w-full">
                        <option value="">All Roles</option>
                        <option value="Member" <?= ($filter_role == 'Member') ? 'selected' : '' ?>>Members</option>
                        <option value="Staff" <?= ($filter_role == 'Staff') ? 'selected' : '' ?>>Staff</option>
                        <option value="Coach" <?= ($filter_role == 'Coach') ? 'selected' : '' ?>>Coach</option>
                    </select>
                </div>

                <!-- Status Filter -->
                <div class="w-[160px] relative">
                    <select name="status" onchange="reactiveFilter()" class="input-box w-full">
                        <option value="">All Status</option>
                        <option value="1" <?= ($filter_status == '1') ? 'selected' : '' ?>>Active</option>
                        <option value="0" <?= ($filter_status == '0') ? 'selected' : '' ?>>Restricted</option>
                    </select>
                </div>

                <!-- Sort Filter -->
                <div class="w-[160px] relative">
                    <select name="sort" onchange="reactiveFilter()" class="input-box w-full">
                        <option value="newest" <?= ($sort_by == 'newest') ? 'selected' : '' ?>>Newest</option>
                        <option value="oldest" <?= ($sort_by == 'oldest') ? 'selected' : '' ?>>Oldest</option>
                        <option value="name_asc" <?= ($sort_by == 'name_asc') ? 'selected' : '' ?>>Name A-Z</option>
                        <option value="name_desc" <?= ($sort_by == 'name_desc') ? 'selected' : '' ?>>Name Z-A</option>
                    </select>
                </div>

                <!-- Reset Button -->
                <a href="my_users.php" class="size-11 rounded-[14px] bg-white/5 border border-white/5 flex items-center justify-center text-white/30 hover:text-white hover:bg-white/10 transition-all">
                    <span class="material-symbols-outlined text-lg">refresh</span>
                </a>
            </form>
        </div>

        <div id="tableContainer" class="glass-card overflow-hidden">
            <table class="w-full text-left">
                <thead>
                    <tr class="bg-black/20 border-b border-white/5">
                        <th class="px-8 py-5 label-muted">Name</th>
                        <th class="px-8 py-5 label-muted">Role</th>
                        <th class="px-8 py-5 label-muted">Email</th>
                        <th class="px-8 py-5 label-muted">Phone Number</th>
                        <th class="px-8 py-5 label-muted">Joined Date</th>
                        <th class="px-8 py-5 label-muted text-center">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php if (empty($users_list)): ?>
                        <tr>
                            <td colspan="5" class="px-8 py-10 text-center label-muted italic">
                                No users found
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users_list as $u): ?>
                            <tr class="group hover:bg-white/[0.02] transition-colors">
                                <td class="px-8 py-6 align-middle">
                                    <div class="flex items-center gap-4">
                                        <?php $hasPic = !empty($u['profile_picture']); ?>
                                        <div class="size-11 rounded-full flex items-center justify-center font-black italic text-[11px] border border-white/10 shrink-0 overflow-hidden shadow-inner relative"
                                             style="background:rgba(var(--primary-rgb), 0.1); color:var(--primary)">
                                            <img src="<?= $hasPic ? getAvatarPath($u['profile_picture']) : '' ?>" 
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
                                    <span class="text-[13px] font-bold tracking-wider" style="color: var(--text-main);">
                                        <?= $u['role'] ?>
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
                                    <button onclick="viewUserProfile(<?= $u['user_id'] ?>)"
                                        class="size-8 rounded-lg bg-white/5 hover:bg-primary transition-all flex items-center justify-center opacity-40 hover:opacity-100 inline-flex border border-white/5">
                                        <span class="material-symbols-outlined text-lg">visibility</span>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </main>

    <script>
        function updateTopClock() {
            const now = new Date();
            const clockEl = document.getElementById('topClock');
            if (clockEl) {
                clockEl.textContent = now.toLocaleTimeString('en-US', {
                    hour: '2-digit', minute: '2-digit', second: '2-digit'
                });
            }
        }
        setInterval(updateTopClock, 1000);
        updateTopClock();

        function reactiveFilter() {
            const form = document.getElementById('filterForm');
            const formData = new FormData(form);
            const params = new URLSearchParams(formData).toString();
            
            // Show loading state
            document.getElementById('tableContainer').style.opacity = '0.5';
            
            fetch(`my_users.php?${params}`)
                .then(res => res.text())
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    const newTable = doc.getElementById('tableContainer').innerHTML;
                    document.getElementById('tableContainer').innerHTML = newTable;
                    document.getElementById('tableContainer').style.opacity = '1';
                });
        }

        function viewUserProfile(uid) {
            const modal = document.getElementById('profileModal');
            modal.classList.add('active');
            document.getElementById('modalContent').innerHTML = `
                <div class="flex items-center justify-center p-20">
                    <span class="material-symbols-outlined animate-spin text-4xl" style="color:var(--primary)">progress_activity</span>
                </div>
            `;
            
            fetch(`my_users.php?ajax_user_id=${uid}`)
                .then(res => res.text())
                .then(html => {
                    document.getElementById('modalContent').innerHTML = html;
                });
        }

        function closeUserModal() {
            document.getElementById('profileModal').classList.remove('active');
        }

        // Auto-show restriction if needed
        <?php if ($is_restricted): ?>
        window.addEventListener('DOMContentLoaded', () => {
            document.getElementById('subModal').classList.add('active');
        });
        <?php endif; ?>
    </script>

    <!-- USER PROFILE MODAL -->
    <div id="profileModal" onclick="if(event.target === this) closeUserModal()">
        <div class="modal-container glass-card border-primary/10 animate-in fade-in zoom-in duration-300">
            <div id="modalContent" class="p-8">
                <!-- Content loaded via AJAX -->
            </div>
        </div>
    </div>

    <!-- Restriction Modal (Sidebar-Aware) -->
    <div id="subModal">
        <div class="glass-card max-w-md w-full p-8 text-center animate-in zoom-in duration-300 relative border-primary/20">
            <div class="size-20 rounded-3xl flex items-center justify-center mx-auto mb-8 border"
                 style="background:rgba(var(--primary-rgb), 0.1); border-color:rgba(var(--primary-rgb), 0.2)">
                <span class="material-symbols-outlined text-4xl" style="color:var(--primary)">lock</span>
            </div>
            <h3 class="text-2xl font-black italic uppercase tracking-tighter mb-3" style="color:var(--text-main)">You Need to Pay First</h3>
            <p class="label-muted mb-10 leading-relaxed italic px-4">
                You cannot see users or stats right now. Your status is <span class="italic animate-pulse" style="color:var(--primary)"><?= $sub_status ?></span>. Please buy a plan to continue.
            </p>
            <div class="flex flex-col gap-4">
                <?php if (strpos($sub_status, 'Pending') !== false): ?>
                    <a href="tenant_dashboard.php" class="h-14 rounded-2xl text-white text-[11px] font-black uppercase italic tracking-[0.2em] flex items-center justify-center gap-3 hover:scale-[1.02] active:scale-95 transition-all group"
                       style="background:var(--primary)">
                        <span class="material-symbols-outlined text-xl group-hover:scale-110 transition-transform">grid_view</span>
                        Go Back
                    </a>
                <?php else: ?>
                    <a href="subscription_plan.php" class="h-14 rounded-2xl text-white text-[11px] font-black uppercase italic tracking-[0.2em] flex items-center justify-center gap-3 hover:scale-[1.02] active:scale-95 transition-all group"
                       style="background:var(--primary)">
                        <span class="material-symbols-outlined text-xl group-hover:scale-110 transition-transform">payments</span>
                        Choose a Plan
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

</body>

</html>