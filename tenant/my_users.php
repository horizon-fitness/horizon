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

// --- AJAX USER PROFILE FETCH (View Details) ---
if (isset($_GET['ajax_user_id'])) {
    $uid = (int) $_GET['ajax_user_id'];

    // Determine role to join correct detail tables
    $stmtRoleCheck = $pdo->prepare("SELECT r.role_name FROM user_roles ur JOIN roles r ON ur.role_id = r.role_id WHERE ur.user_id = ? AND ur.gym_id = ? LIMIT 1");
    $stmtRoleCheck->execute([$uid, $gym_id]);
    $role_name = strtolower($stmtRoleCheck->fetchColumn() ?: '');

    $sql = "SELECT u.*, r.role_name as role, ur.role_status ";
    if ($role_name === 'member') {
        $sql .= ", m.member_code, u.birth_date, u.sex, m.occupation, a.address_line, m.medical_history, m.emergency_contact_name, m.emergency_contact_number ";
        $sql .= " FROM users u JOIN user_roles ur ON u.user_id = ur.user_id JOIN roles r ON ur.role_id = r.role_id LEFT JOIN members m ON u.user_id = m.user_id LEFT JOIN addresses a ON m.address_id = a.address_id ";
    } elseif ($role_name === 'staff') {
        $sql .= ", s.staff_role, s.employment_type, s.hire_date, s.status as staff_status ";
        $sql .= " FROM users u JOIN user_roles ur ON u.user_id = ur.user_id JOIN roles r ON ur.role_id = r.role_id LEFT JOIN staff s ON u.user_id = s.user_id AND s.gym_id = ur.gym_id ";
    } elseif ($role_name === 'coach') {
        $sql .= ", ca.coach_type as employment_type, ca.specialization as staff_role, c.hire_date, c.status as staff_status ";
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
        <div class="space-y-8 animate-in fade-in slide-in-from-bottom-4 duration-500 pb-6">
            <header class="flex justify-between items-start border-b border-white/5 pb-8">
                <div class="flex items-center gap-6">
                    <div class="size-20 rounded-[24px] flex items-center justify-center font-black italic text-2xl uppercase border-2 shadow-lg"
                         style="background:rgba(var(--primary-rgb), 0.1); border-color:rgba(var(--primary-rgb), 0.2); color:var(--primary); box-shadow:0 8px 24px rgba(var(--primary-rgb),0.2)">
                        <?= substr($u['first_name'], 0, 1) ?>
                    </div>
                    <div>
                        <div class="flex items-center gap-3 mb-1">
                            <h2 class="text-2xl font-black italic uppercase tracking-tighter leading-tight" style="color:var(--text-main)">
                                <?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?>
                            </h2>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="px-3 py-1 rounded-full text-[9px] font-black uppercase italic tracking-[0.15em] border"
                                  style="background:rgba(var(--primary-rgb), 0.1); border-color:rgba(var(--primary-rgb), 0.2); color:var(--primary)">
                                <?= $u['role'] ?>
                            </span>
                            <span class="text-[9px] font-bold opacity-40 uppercase tracking-widest italic"><?= htmlspecialchars($u['email']) ?></span>
                        </div>
                    </div>
                </div>
                <button onclick="closeUserModal()"
                    class="size-10 rounded-xl bg-white/5 hover:bg-white/10 flex items-center justify-center opacity-50 hover:opacity-100 group transition-all border border-white/5">
                    <span class="material-symbols-outlined text-xl group-hover:rotate-90 transition-transform">close</span>
                </button>
            </header>

            <div class="grid grid-cols-1 xl:grid-cols-3 gap-10">
                <div class="xl:col-span-2 space-y-10">
                    <!-- Section 1: Personal Information -->
                    <section class="glass-card p-8">
                        <h4 class="text-base font-black italic uppercase tracking-tighter mb-8 flex items-center gap-3" style="color:var(--primary)">
                            <span class="material-symbols-outlined p-2 rounded-xl text-xl" style="background:rgba(var(--primary-rgb), 0.1)">person</span> 
                            Personal Information
                        </h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            <div class="space-y-1">
                                <p class="label-muted ml-1">First Name</p>
                                <p class="text-sm font-black italic uppercase" style="color:var(--text-main)"><?= htmlspecialchars($u['first_name']) ?></p>
                            </div>
                            <?php if ($role_name === 'member'): ?>
                                <div class="space-y-1">
                                    <p class="label-muted ml-1">Middle Name</p>
                                    <p class="text-sm font-black italic uppercase" style="color:var(--text-main)"><?= htmlspecialchars($u['middle_name'] ?: 'N/A') ?></p>
                                </div>
                            <?php endif; ?>
                            <div class="space-y-1">
                                <p class="label-muted ml-1">Last Name</p>
                                <p class="text-sm font-black italic uppercase" style="color:var(--text-main)"><?= htmlspecialchars($u['last_name']) ?></p>
                            </div>
                            <?php if ($role_name === 'member'): ?>
                                <div class="space-y-1">
                                    <p class="label-muted ml-1">Sex</p>
                                    <p class="text-sm font-black italic uppercase" style="color:var(--text-main)"><?= $u['sex'] ?: 'N/A' ?></p>
                                </div>
                                <div class="space-y-1">
                                    <p class="label-muted ml-1">Birth Date</p>
                                    <p class="text-sm font-black italic" style="color:var(--text-main)">
                                        <?= $u['birth_date'] ? date('M d, Y', strtotime($u['birth_date'])) : 'N/A' ?>
                                    </p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>

                    <!-- Section 2: Contact Information -->
                    <section class="glass-card p-8">
                        <h4 class="text-base font-black italic uppercase tracking-tighter mb-8 flex items-center gap-3" style="color:var(--primary)">
                            <span class="material-symbols-outlined p-2 rounded-xl text-xl" style="background:rgba(var(--primary-rgb), 0.1)">alternate_email</span>
                            Contact Information
                        </h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="space-y-1">
                                <p class="label-muted ml-1">Email Address</p>
                                <p class="text-sm font-black italic truncate" style="color:var(--text-main)"><?= htmlspecialchars($u['email']) ?></p>
                            </div>
                            <div class="space-y-1">
                                <p class="label-muted ml-1">Contact Number</p>
                                <p class="text-sm font-black italic tracking-widest" style="color:var(--text-main)"><?= htmlspecialchars($u['contact_number'] ?: 'UNKNOWN') ?></p>
                            </div>
                            <?php if ($role_name === 'member' && !empty($u['address_line'])): ?>
                                <div class="space-y-1 md:col-span-2">
                                    <p class="label-muted ml-1">Home Address</p>
                                    <p class="text-sm font-black italic" style="color:var(--text-main)"><?= htmlspecialchars($u['address_line']) ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>

                    <?php if ($role_name === 'member'): ?>
                        <!-- Section 3: Health & Profile -->
                        <section class="glass-card p-8">
                            <h4 class="text-base font-black italic uppercase tracking-tighter mb-8 flex items-center gap-3" style="color:var(--primary)">
                                <span class="material-symbols-outlined p-2 rounded-xl text-xl" style="background:rgba(var(--primary-rgb), 0.1)">medical_information</span>
                                Health & Profile
                            </h4>
                            <div class="space-y-6">
                                <div class="space-y-1">
                                    <p class="label-muted ml-1">Occupation</p>
                                    <p class="text-sm font-black italic uppercase" style="color:var(--text-main)"><?= htmlspecialchars($u['occupation'] ?: 'N/A') ?></p>
                                </div>
                                <div class="space-y-1">
                                    <p class="label-muted ml-1">Medical History</p>
                                    <div class="text-sm font-medium opacity-50 italic leading-relaxed bg-black/20 p-6 rounded-2xl border border-white/5" style="color:var(--text-main)">
                                        <?= nl2br(htmlspecialchars($u['medical_history'] ?: 'No physical medical history recorded.')) ?>
                                    </div>
                                </div>
                            </div>
                        </section>
                    <?php endif; ?>

                    <?php if ($role_name === 'staff' || $role_name === 'coach'): ?>
                        <!-- Section 3: Professional Registry -->
                        <section class="glass-card p-8">
                            <h4 class="text-base font-black italic uppercase tracking-tighter mb-8 flex items-center gap-3" style="color:var(--primary)">
                                <span class="material-symbols-outlined p-2 rounded-xl text-xl" style="background:rgba(var(--primary-rgb), 0.1)">badge</span>
                                Professional Profile
                            </h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                                <div class="space-y-1">
                                    <p class="label-muted ml-1">System Role</p>
                                    <p class="text-sm font-black italic uppercase" style="color:var(--text-main)"><?= $u['staff_role'] ?: $u['role'] ?></p>
                                </div>
                                <div class="space-y-1">
                                    <p class="label-muted ml-1">Class</p>
                                    <p class="text-sm font-black italic uppercase" style="color:var(--text-main)"><?= $u['employment_type'] ?: 'N/A' ?></p>
                                </div>
                                <div class="space-y-1">
                                    <p class="label-muted ml-1">Registry Date</p>
                                    <p class="text-sm font-black italic" style="color:var(--text-main)">
                                        <?= $u['hire_date'] ? date('M d, Y', strtotime($u['hire_date'])) : 'N/A' ?>
                                    </p>
                                </div>
                            </div>
                        </section>
                    <?php endif; ?>
                </div>

                <div class="space-y-10">
                    <section class="glass-card p-8">
                        <h4 class="text-[10px] font-black uppercase tracking-[0.3em] border-l-4 pl-4 mb-6" style="color:var(--primary); border-color:var(--primary)">
                            Security Registry
                        </h4>
                        <div class="p-6 rounded-2xl bg-white/[0.02] border border-white/5">
                            <p class="text-[9px] font-black uppercase opacity-40 tracking-widest mb-4">Authentication Status</p>
                            <div class="flex items-center gap-4">
                                <span class="size-2 rounded-full <?= $u['is_active'] ? 'bg-emerald-500' : 'bg-red-500' ?>"></span>
                                <p class="text-xs font-bold uppercase tracking-[0.1em] <?= $u['is_active'] ? 'text-emerald-500' : 'text-red-500' ?>">
                                    <?= $u['is_active'] ? 'System Authorized' : 'Access Restricted' ?>
                                </p>
                            </div>
                        </div>
                    </section>

                    <?php if ($role_name === 'member' || !empty($u['emergency_contact_name'])): ?>
                        <!-- Sidebar section: Emergency Support -->
                        <section class="glass-card p-8">
                            <h4 class="text-[10px] font-black uppercase tracking-[0.3em] border-l-4 border-amber-500 pl-4 mb-6" style="color:var(--highlight)">
                                Emergency Protocol
                            </h4>
                            <div class="p-8 rounded-[32px] border border-amber-500/10" style="background:rgba(var(--highlight-rgb), 0.03)">
                                <div class="space-y-6">
                                    <div>
                                        <p class="text-[9px] font-black uppercase opacity-50 tracking-widest mb-2">Primary Contact</p>
                                        <p class="text-base font-black italic uppercase" style="color:var(--text-main)">
                                            <?= htmlspecialchars($u['emergency_contact_name'] ?: 'NOT LISTED') ?>
                                        </p>
                                    </div>
                                    <div>
                                        <p class="text-[9px] font-black uppercase opacity-50 tracking-widest mb-2">Contact Signal</p>
                                        <p class="text-base font-bold tracking-widest text-amber-500">
                                            <?= htmlspecialchars($u['emergency_contact_number'] ?: 'OFFLINE') ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </section>
                    <?php endif; ?>
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
           CASE WHEN r.role_name = 'Member' THEN IFNULL(mp.plan_name, 'No Plan') ELSE ca.specialization END as detail_info
    FROM users u
    JOIN user_roles ur ON u.user_id = ur.user_id
    JOIN roles r ON ur.role_id = r.role_id
    LEFT JOIN members m ON u.user_id = m.user_id
    LEFT JOIN coaches c ON u.user_id = c.user_id
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
            box-shadow: 0 20px 40px -20px rgba(var(--primary-rgb),0.3);
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
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            color: var(--text-main);
            padding: 10px 16px;
            font-size: 11px;
            font-weight: 500;
            outline: none;
            transition: all 0.2s;
        }
        .input-box:focus { border-color: var(--primary); background: rgba(255, 255, 255, 0.08); }
        .input-box option { background: #14121a; color: white; }
        select.input-box {
            cursor: pointer; color-scheme: dark; appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='white'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 0.75rem center; background-size: 0.85em; padding-right: 2.5rem;
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
        #profileModal, #subModal {
            position: fixed; top: 0; right: 0; bottom: 0;
            left: 110px; z-index: 200;
            display: none; align-items: center; justify-content: center;
            padding: 40px;
            background: rgba(0, 0, 0, 0.82);
            backdrop-filter: blur(12px);
            transition: left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        #profileModal.active, #subModal.active { display: flex; }
        
        .side-nav:hover ~ #profileModal,
        .side-nav:hover ~ #subModal {
            left: 300px;
        }

        .modal-container {
            position: relative;
            width: 100%;
            max-width: 900px;
            max-height: 85vh;
            overflow-y: auto;
            z-index: 10;
        }

        .modal-backdrop { display: none; } /* Handled by parent padding/bg now */

        /* Restriction Modal (Sidebar-Aware) */
        #subModal { 
            position: fixed; top: 0; right: 0; bottom: 0; left: 110px; 
            z-index: 200; display: none !important; 
            align-items: center; justify-content: center; 
            padding: 24px; background: rgba(0, 0, 0, 0.8); 
            backdrop-filter: blur(12px); 
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

    <?php 
    $active_page = 'users';
    include '../includes/tenant_sidebar.php'; 
    ?>

    <main class="main-content flex-1 p-10 overflow-y-auto no-scrollbar">


        <header class="mb-10 flex justify-between items-end">
            <div>
                <h2 class="text-3xl font-black uppercase tracking-tighter italic" style="color:var(--text-main)">
                    User <span style="color:var(--primary)" class="italic">Database</span>
                </h2>
                <p class="label-muted mt-1 italic"><?= htmlspecialchars($gym_name) ?> Community Roster</p>
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
                <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">Registry Growth</p>
                <h3 class="text-3xl font-black italic uppercase" style="color:var(--text-main)">
                    <?= $total_members + $total_coaches ?>
                </h3>
                <p class="text-[10px] font-black uppercase mt-2 italic" style="color:var(--primary)">Total Community</p>
            </div>

            <!-- Active Members -->
            <div class="glass-card p-8 status-card-green relative overflow-hidden group hover:scale-[1.02] transition-all">
                <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-emerald-500">how_to_reg</span>
                <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">Member Nodes</p>
                <h3 class="text-3xl font-black italic uppercase" style="color:var(--text-main)">
                    <?= $total_members ?>
                </h3>
                <p class="text-emerald-500 text-[10px] font-black uppercase mt-2 italic">Active Members</p>
            </div>

            <!-- Expert Coaches -->
            <div class="glass-card p-8 status-card-yellow relative overflow-hidden group hover:scale-[1.02] transition-all">
                <span class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-amber-500">workspace_premium</span>
                <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">Vetted Talent</p>
                <h3 class="text-3xl font-black italic uppercase" style="color:var(--text-main)">
                    <?= $total_coaches ?>
                </h3>
                <p class="text-amber-500 text-[10px] font-black uppercase mt-2 italic">Expert Coaches</p>
            </div>
        </div>

        <!-- ADVANCED FILTERS -->
        <div class="mb-10">
            <form id="filterForm" onsubmit="event.preventDefault(); reactiveFilter();"
                class="glass-card p-8 relative overflow-hidden">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6">
                    <div class="space-y-2 lg:col-span-2">
                        <p class="text-[9px] font-black uppercase tracking-widest ml-1" style="color:var(--primary)">Search</p>
                        <div class="relative">
                            <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-lg opacity-40">search</span>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                                placeholder="Filter user registry..." oninput="reactiveFilter()"
                                class="input-box pl-12 w-full">
                        </div>
                    </div>
                    <div class="space-y-2">
                        <p class="label-muted ml-1">Authority Filter</p>
                        <select name="role" onchange="reactiveFilter()" class="input-box w-full">
                            <option value="">All Roles</option>
                            <option value="Member" <?= ($filter_role == 'Member') ? 'selected' : '' ?>>Members</option>
                            <option value="Staff" <?= ($filter_role == 'Staff') ? 'selected' : '' ?>>Staff</option>
                            <option value="Coach" <?= ($filter_role == 'Coach') ? 'selected' : '' ?>>Coach</option>
                        </select>
                    </div>
                    <div class="space-y-2">
                        <p class="label-muted ml-1">Account Protocol</p>
                        <select name="status" onchange="reactiveFilter()" class="input-box w-full">
                            <option value="">All Status</option>
                            <option value="1" <?= ($filter_status == '1') ? 'selected' : '' ?>>Active</option>
                            <option value="0" <?= ($filter_status == '0') ? 'selected' : '' ?>>Restricted</option>
                        </select>
                    </div>
                    <div class="space-y-2">
                        <p class="label-muted ml-1">Sort Registry</p>
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
                    <tr class="bg-black/20 border-b border-white/5">
                        <th class="px-8 py-5 label-muted">Individual</th>
                        <th class="px-8 py-5 label-muted">Role / Protocol</th>
                        <th class="px-8 py-5 label-muted">Detail Info</th>
                        <th class="px-8 py-5 label-muted text-right">System Access</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php if (empty($users_list)): ?>
                        <tr>
                            <td colspan="4" class="px-8 py-10 text-center label-muted italic">
                                No matching users found in registry
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users_list as $u): ?>
                            <tr class="group hover:bg-white/[0.02] transition-colors">
                                <td class="px-8 py-6 flex items-center gap-4">
                                    <div class="size-10 rounded-full flex items-center justify-center font-black italic text-xs border border-white/5"
                                         style="background:rgba(var(--primary-rgb), 0.1); color:var(--primary)">
                                        <?= strtoupper(substr($u['first_name'] ?? 'U', 0, 1)) ?>
                                    </div>
                                    <div>
                                        <p class="text-sm font-black italic uppercase tracking-tighter" style="color:var(--text-main)">
                                            <?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?>
                                        </p>
                                        <p class="text-[10px] font-bold opacity-50">
                                            <?= htmlspecialchars($u['email']) ?>
                                        </p>
                                    </div>
                                </td>
                                <td class="px-8 py-6">
                                    <div class="flex flex-col gap-1">
                                        <span class="text-[10px] font-black uppercase italic tracking-widest" style="color:var(--primary)">
                                            <?= $u['role'] ?>
                                        </span>
                                        <span class="text-[8px] font-bold uppercase tracking-widest <?= ($u['is_active']) ? 'text-emerald-500' : 'text-rose-500' ?>">
                                            <?= ($u['is_active']) ? 'Authorized' : 'Restricted' ?>
                                        </span>
                                    </div>
                                </td>
                                <td class="px-8 py-6">
                                    <p class="text-[10px] font-black uppercase opacity-50 italic tracking-widest group-hover:opacity-100 transition-opacity truncate max-w-[200px]" style="color:var(--text-main)">
                                        <?= htmlspecialchars($u['detail_info'] ?: 'None') ?>
                                    </p>
                                </td>
                                <td class="px-8 py-6 text-right">
                                    <button onclick="viewUserProfile(<?= $u['user_id'] ?>)"
                                        class="size-9 rounded-xl bg-white/5 hover:bg-primary transition-all flex items-center justify-center opacity-50 hover:opacity-100 inline-flex border border-white/5 shadow-sm group-hover:shadow-primary/20">
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
        <div class="modal-container glass-card shadow-[0_0_100px_rgba(0,0,0,0.5)] border-primary/10 animate-in fade-in zoom-in duration-300">
            <div id="modalContent" class="p-8">
                <!-- Content loaded via AJAX -->
            </div>
        </div>
    </div>

    <!-- Restriction Modal (Sidebar-Aware) -->
    <div id="subModal">
        <div class="glass-card max-w-md w-full p-8 text-center animate-in zoom-in duration-300 relative border-primary/20" 
             style="box-shadow:0 0 80px rgba(var(--primary-rgb),0.15)">
            <div class="size-20 rounded-3xl flex items-center justify-center mx-auto mb-8 border"
                 style="background:rgba(var(--primary-rgb), 0.1); border-color:rgba(var(--primary-rgb), 0.2)">
                <span class="material-symbols-outlined text-4xl" style="color:var(--primary)">lock</span>
            </div>
            <h3 class="text-2xl font-black italic uppercase tracking-tighter mb-3" style="color:var(--text-main)">Subscription Required</h3>
            <p class="label-muted mb-10 leading-relaxed italic px-4">
                Access to user management and platform analytics is restricted. Your status is <span class="italic animate-pulse" style="color:var(--primary)"><?= $sub_status ?></span>. Please activate a growth plan to unlock.
            </p>
            <div class="flex flex-col gap-4">
                <?php if (strpos($sub_status, 'Pending') !== false): ?>
                    <a href="tenant_dashboard.php" class="h-14 rounded-2xl text-white text-[11px] font-black uppercase italic tracking-[0.2em] flex items-center justify-center gap-3 hover:scale-[1.02] active:scale-95 transition-all group"
                       style="background:var(--primary); box-shadow:0 8px 24px rgba(var(--primary-rgb),0.25)">
                        <span class="material-symbols-outlined text-xl group-hover:scale-110 transition-transform">grid_view</span>
                        Back to Dashboard
                    </a>
                <?php else: ?>
                    <a href="subscription_plan.php" class="h-14 rounded-2xl text-white text-[11px] font-black uppercase italic tracking-[0.2em] flex items-center justify-center gap-3 hover:scale-[1.02] active:scale-95 transition-all group"
                       style="background:var(--primary); box-shadow:0 8px 24px rgba(var(--primary-rgb),0.25)">
                        <span class="material-symbols-outlined text-xl group-hover:scale-110 transition-transform">payments</span>
                        Select Growth Plan
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

</body>

</html>