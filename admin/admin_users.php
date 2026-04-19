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

$owner_user_id = $gym['owner_user_id'] ?? 0;
$stmtSettings = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = ? OR user_id = 0 ORDER BY user_id ASC");
$stmtSettings->execute([$owner_user_id]);
$configs = $stmtSettings->fetchAll(PDO::FETCH_KEY_PAIR);

$theme_color = $configs['theme_color'] ?? '#8c2bee';
$bg_color = $configs['bg_color'] ?? '#0a090d';
$font_family = $configs['font_family'] ?? 'Lexend';
$system_logo = $configs['system_logo'] ?? $gym['profile_picture'] ?? '';

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
        <div class="space-y-10 animate-in fade-in slide-in-from-bottom-6 duration-500 pb-10">
            <!-- Header Identity Wrapper -->
            <header class="flex justify-between items-start border-b border-white/5 pb-10">
                <div class="flex items-center gap-8">
                    <div class="size-24 rounded-[32px] bg-primary/10 border-2 border-primary/20 flex items-center justify-center text-primary font-black italic text-4xl uppercase">
                        <?= substr($u['first_name'], 0, 1) ?>
                    </div>
                    <div>
                        <div class="flex items-center gap-4 mb-2">
                             <h2 class="text-4xl font-black italic uppercase tracking-tighter text-white leading-none">
                                <?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?>
                             </h2>
                             <?php if(strtolower($u['role']) === 'tenant'): ?>
                                <span class="px-3 py-1 rounded-[10px] bg-amber-500/10 border border-amber-500/20 text-[10px] text-amber-500 font-extrabold uppercase italic tracking-widest flex items-center gap-2">
                                    <span class="material-symbols-outlined text-[14px]">stars</span> SYSTEM OWNER
                                </span>
                             <?php endif; ?>
                        </div>
                        <div class="flex items-center gap-4">
                            <span class="px-4 py-1.5 rounded-full bg-primary/10 border border-primary/20 text-[10px] text-primary font-black uppercase italic tracking-[0.1em]"><?= $u['role'] ?></span>
                        </div>
                    </div>
                </div>
                <button onclick="closeUserModal()" class="size-12 rounded-2xl bg-white/5 hover:bg-white/10 flex items-center justify-center text-gray-400 group transition-all">
                    <span class="material-symbols-outlined text-2xl group-hover:rotate-90 transition-transform">close</span>
                </button>
            </header>

            <div class="grid grid-cols-1 xl:grid-cols-3 gap-10">
                <div class="xl:col-span-2 space-y-10">
                    <?php if ($role_name === 'tenant'): ?>
                        <!-- Tenant / Business Data Section -->
                        <section class="space-y-6">
                            <h4 class="text-[10px] font-black uppercase text-amber-500 tracking-[0.3em] border-l-4 border-amber-500 pl-4">Business Application Registry</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="bg-white/[0.03] p-6 rounded-3xl border border-white/5">
                                    <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-2 flex items-center gap-2"><span class="material-symbols-outlined text-[14px] text-amber-500">store</span> Gym Branding</p>
                                    <p class="text-base font-black text-white italic truncate"><?= htmlspecialchars($u['gym_name'] ?: 'HORIZON SYSTEMS BUSINESS') ?></p>
                                </div>
                                <div class="bg-white/[0.03] p-6 rounded-3xl border border-white/5">
                                    <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-2 flex items-center gap-2"><span class="material-symbols-outlined text-[14px] text-amber-500">qr_code</span> Tenant Code</p>
                                    <p class="text-base font-bold text-white tracking-widest"><?= htmlspecialchars($u['tenant_code'] ?: 'PENDING_ONBOARD') ?></p>
                                </div>
                                <div class="bg-white/[0.03] p-6 rounded-3xl border border-white/5">
                                    <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-2 flex items-center gap-2"><span class="material-symbols-outlined text-[14px] text-amber-500">apartment</span> Registered Business Name</p>
                                    <p class="text-sm font-bold text-gray-300"><?= htmlspecialchars($u['business_name'] ?: 'PRIVATE BUSINESS ENTITY') ?></p>
                                </div>
                                <div class="bg-white/[0.03] p-6 rounded-3xl border border-white/5">
                                    <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-2 flex items-center gap-2"><span class="material-symbols-outlined text-[14px] text-amber-500">verified</span> Business Approval Status</p>
                                    <p class="text-sm font-bold text-emerald-500 uppercase tracking-widest"><?= htmlspecialchars($u['gym_status'] ?: 'ACTIVE') ?></p>
                                </div>
                            </div>
                        </section>
                    <?php endif; ?>

                    <?php if ($role_name === 'staff' || $role_name === 'coach'): ?>
                        <!-- Staff / Coach Registry Section -->
                        <section class="space-y-6">
                            <h4 class="text-[10px] font-black uppercase text-primary tracking-[0.3em] border-l-4 border-primary pl-4">Staff Registry Handlers</h4>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div class="bg-white/[0.03] p-6 rounded-3xl border border-white/5">
                                    <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-2">Internal Role</p>
                                    <p class="text-sm font-black text-white italic uppercase"><?= $u['staff_role'] ?: $u['role'] ?></p>
                                </div>
                                <div class="bg-white/[0.03] p-6 rounded-3xl border border-white/5">
                                    <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-2">Shift Class</p>
                                    <p class="text-sm font-black text-white italic uppercase"><?= $u['employment_type'] ?: 'ON-SITE' ?></p>
                                </div>
                                <div class="bg-white/[0.03] p-6 rounded-3xl border border-white/5">
                                    <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-2">Hire Registry</p>
                                    <p class="text-sm font-black text-white italic"><?= $u['hire_date'] ? date('M d, Y', strtotime($u['hire_date'])) : 'N/A' ?></p>
                                </div>
                            </div>
                        </section>
                    <?php endif; ?>

                    <?php if ($role_name === 'member'): ?>
                        <!-- Member Registry Section (Historical) -->
                        <section class="space-y-6">
                            <h4 class="text-[10px] font-black uppercase text-primary tracking-[0.3em] border-l-4 border-primary pl-4">Biological ID & Identity</h4>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div class="bg-white/[0.03] p-6 rounded-3xl border border-white/5">
                                    <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-2 flex items-center gap-2">Biological Sex</p>
                                    <p class="text-sm font-black text-white italic uppercase"><?= $u['sex'] ?: 'N/A' ?></p>
                                </div>
                                <div class="bg-white/[0.03] p-6 rounded-3xl border border-white/5">
                                    <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-2">Birth Date</p>
                                    <p class="text-sm font-black text-white italic"><?= $u['birth_date'] ? date('M d, Y', strtotime($u['birth_date'])) : 'N/A' ?></p>
                                </div>
                                <div class="bg-white/[0.03] p-6 rounded-3xl border border-white/5">
                                    <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-2">Occupation</p>
                                    <p class="text-sm font-black text-white italic uppercase truncate"><?= $u['occupation'] ?: 'N/A' ?></p>
                                </div>
                            </div>
                        </section>

                        <!-- Health & Medical Profile -->
                        <section class="space-y-6">
                            <h4 class="text-[10px] font-black uppercase text-rose-500 tracking-[0.3em] border-l-4 border-rose-500 pl-4">Synthetic Health Profile</h4>
                            <div class="bg-rose-500/[0.03] p-8 rounded-[32px] border border-rose-500/10">
                                <p class="text-[9px] font-black uppercase text-rose-500/60 tracking-widest mb-2">Medical History & Anomalies</p>
                                <div class="text-sm font-medium text-gray-400 italic leading-relaxed bg-black/20 p-6 rounded-2xl border border-white/5">
                                    <?= nl2br(htmlspecialchars($u['medical_history'] ?: 'No physical medical history recorded in system.')) ?>
                                </div>
                            </div>
                        </section>
                    <?php endif; ?>

                    <!-- Common Contact Network -->
                    <section class="space-y-6">
                        <h4 class="text-[10px] font-black uppercase text-primary tracking-[0.3em] border-l-4 border-primary pl-4">Primary Contact Protocols</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="bg-white/[0.03] p-6 rounded-3xl border border-white/5">
                                <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-2 flex items-center gap-2"><span class="material-symbols-outlined text-[14px] text-primary">mail</span> Mail Frequency</p>
                                <p class="text-base font-bold text-white"><?= htmlspecialchars($u['email']) ?></p>
                            </div>
                            <div class="bg-white/[0.03] p-6 rounded-3xl border border-white/5">
                                <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-2 flex items-center gap-2"><span class="material-symbols-outlined text-[14px] text-primary">call</span> Mobile Signal</p>
                                <p class="text-base font-bold text-white tracking-widest"><?= htmlspecialchars($u['contact_number'] ?: 'UNKNOWN') ?></p>
                            </div>
                            <?php if ($role_name === 'member' && !empty($u['address_line'])): ?>
                                <div class="bg-white/[0.03] p-6 rounded-3xl border border-white/5 md:col-span-2">
                                    <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-2 flex items-center gap-2"><span class="material-symbols-outlined text-[14px] text-primary">location_on</span> Registered Address</p>
                                    <p class="text-sm font-bold text-gray-300"><?= htmlspecialchars($u['address_line']) ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>
                </div>

                <!-- Sidebar Content -->
                <div class="space-y-10">
                    <?php if ($role_name === 'member' || !empty($u['emergency_contact_name'])): ?>
                        <!-- Emergency Protocol -->
                        <section class="space-y-6">
                            <h4 class="text-[10px] font-black uppercase text-amber-500 tracking-[0.3em] border-l-4 border-amber-500 pl-4">Emergency Protocol</h4>
                            <div class="bg-amber-500/[0.03] p-8 rounded-[32px] border border-amber-500/10">
                                <div class="space-y-6">
                                    <div>
                                        <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-2">Primary Contact</p>
                                        <p class="text-base font-black text-white italic uppercase"><?= htmlspecialchars($u['emergency_contact_name'] ?: 'NOT LISTED') ?></p>
                                    </div>
                                    <div>
                                        <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-2">Emergency Signal</p>
                                        <p class="text-base font-bold text-amber-500 tracking-widest"><?= htmlspecialchars($u['emergency_contact_number'] ?: 'OFFLINE') ?></p>
                                    </div>
                                </div>
                            </div>
                        </section>
                    <?php endif; ?>

                    <!-- Access & Auth -->
                    <section class="space-y-6">
                        <h4 class="text-[10px] font-black uppercase text-primary tracking-[0.3em] border-l-4 border-primary pl-4">Access Credentials</h4>
                        <div class="space-y-4">
                            <div class="bg-white/[0.03] p-6 rounded-3xl border border-white/5">
                                <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-2">Username Alias</p>
                                <p class="text-lg font-black text-white italic">@<?= htmlspecialchars($u['username']) ?></p>
                            </div>
                            <div class="p-8 rounded-[32px] bg-gradient-to-br from-white/5 to-transparent border border-white/10">
                                <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-3">Node Status</p>
                                <div class="flex items-center gap-4">
                                     <span class="size-3 rounded-full <?= $u['is_active'] ? 'bg-emerald-500 animate-pulse' : 'bg-red-500' ?>"></span>
                                     <p class="text-sm font-black uppercase italic tracking-widest <?= $u['is_active'] ? 'text-emerald-500' : 'text-red-500' ?>">
                                        <?= $u['is_active'] ? 'Authorized' : 'Deactivated' ?>
                                     </p>
                                </div>
                            </div>
                        </div>
                    </section>
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
                        "primary": "<?= $theme_color ?>",
                        "background-dark": "<?= $bg_color ?>",
                        "surface-dark": "#14121a",
                        "border-subtle": "rgba(255,255,255,0.05)"
                    }
                }
            }
        } 
    </script>
    <style>
        body {
            font-family: '<?= $font_family ?>', sans-serif;
            background-color:
                <?= $bg_color ?>
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
                <?= $theme_color ?>
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

        async function toggleAccountStatus(userId) {
            if (!confirm('Proceed with account status protocol? User access will be updated immediately.')) return;

            try {
                const formData = new FormData();
                formData.append('toggle_status_id', userId);

                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                // This will fail if the response isn't JSON, e.g. error redirect
                const result = await response.json();

                if (result.success) {
                    // Update table reactively
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

    <nav class="side-nav flex flex-col fixed left-0 top-0 h-screen bg-background-dark border-r border-white/5">
        <div class="px-7 py-8 mb-4 shrink-0">
            <div class="flex items-center gap-4">
                <div
                    class="size-10 rounded-xl bg-primary flex items-center justify-center shadow-lg shrink-0 overflow-hidden">
                    <?php if (!empty($system_logo)):
                        $logo_src = (strpos($system_logo, 'data:image') === 0) ? $system_logo : '../' . $system_logo;
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
            <a href="admin_report.php" class="nav-item text-gray-400 hover:text-white"><span
                    class="material-symbols-outlined text-xl shrink-0">description</span><span
                    class="nav-label">Reports</span></a>
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
                                                
                                                <?php if(strtolower($row['role']) === 'tenant'): ?>
                                                    <button class="size-9 rounded-xl bg-white/5 flex items-center justify-center text-gray-600 opacity-20 cursor-not-allowed" title="System Security: Owner accounts cannot be restricted">
                                                        <span class="material-symbols-outlined text-lg">verified_user</span>
                                                    </button>
                                                <?php else: ?>
                                                    <?php if($row['is_active']): ?>
                                                        <button onclick="toggleAccountStatus(<?= $row['id'] ?>)" 
                                                            class="size-9 rounded-xl bg-white/5 flex items-center justify-center hover:bg-rose-500/10 hover:text-rose-500 text-gray-500 transition-all active:scale-90" 
                                                            title="Lock / Restrict Account Access">
                                                            <span class="material-symbols-outlined text-lg">lock</span>
                                                        </button>
                                                    <?php else: ?>
                                                        <button onclick="toggleAccountStatus(<?= $row['id'] ?>)" 
                                                            class="size-9 rounded-xl bg-rose-500/10 border border-rose-500/20 flex items-center justify-center hover:bg-emerald-500/10 hover:text-emerald-500 text-rose-500 transition-all active:scale-90" 
                                                            title="Unlock / Restore Account Access">
                                                            <span class="material-symbols-outlined text-lg">lock_open</span>
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
        class="hidden fixed inset-0 z-[100] items-center justify-center p-6 md:p-12 overflow-hidden transition-all duration-400" 
        style="left: var(--nav-width);">
        <!-- Clickable Backdrop -->
        <div class="absolute inset-0 bg-black/60 backdrop-blur-md" onclick="closeUserModal()"></div>

        <!-- Modal Container -->
        <div
            class="relative w-full max-w-5xl max-h-[90vh] overflow-y-auto no-scrollbar glass-card border-white/10 shadow-[0_0_100px_rgba(0,0,0,0.5)] backdrop-blur-3xl p-10 md:p-14 animate-in fade-in zoom-in duration-300">
            <div id="modalContent">
                <!-- Loaded via AJAX -->
            </div>
        </div>
    </div>

</body>

</html>