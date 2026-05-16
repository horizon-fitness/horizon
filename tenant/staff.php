<?php
session_start();
require_once '../db.php';
require_once '../includes/mailer.php';

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

// --- SCHEMA MIGRATION: Auto-Heal Coach Applications ---
try {
    $resRate = $pdo->query("SHOW COLUMNS FROM coach_applications LIKE 'session_rate'");
    if (!$resRate->fetch()) {
        $pdo->exec("ALTER TABLE coach_applications ADD COLUMN session_rate DECIMAL(10,2) AFTER license_number");
    }
} catch (Exception $e) { /* Silently proceed */
}

$active_page = 'staff';
$active_tab = $_GET['tab'] ?? 'team';

// --- AJAX APPLICATION DETAILS ---
if (isset($_GET['ajax']) && isset($_GET['application_id'])) {
    $app_id = (int) $_GET['application_id'];
    $stmt = $pdo->prepare("
        SELECT ca.*, u.first_name, u.middle_name, u.last_name, u.email, u.contact_number, u.birth_date, u.sex, u.profile_picture
        FROM coach_applications ca
        JOIN users u ON ca.user_id = u.user_id
        WHERE ca.coach_application_id = ? AND ca.gym_id = ?
    ");
    $stmt->execute([$app_id, $gym_id]);
    $app = $stmt->fetch();

    if (!$app) {
        echo "<div class='p-10 text-center font-black italic uppercase text-xs tracking-widest text-gray-500'>Critical Error: Application parameters not found.</div>";
        exit;
    }

    $isPdf = (strtolower(pathinfo($app['certification_file'], PATHINFO_EXTENSION)) === 'pdf') || str_starts_with(strtolower($app['certification_file']), 'data:application/pdf');
    $initials = strtoupper(substr($app['first_name'], 0, 1) . substr($app['last_name'], 0, 1));
    ?>
    <div class="space-y-8 animate-in fade-in slide-in-from-bottom-4 duration-500">
        <!-- Header Profile -->
        <div class="flex items-center gap-6 p-8 rounded-[32px] bg-white/[0.02] border border-white/5 shadow-inner">
            <div
                class="size-20 rounded-3xl bg-primary/10 border border-primary/20 overflow-hidden flex items-center justify-center relative shadow-2xl shadow-primary/10">
                <?php if (!empty($app['profile_picture'])): ?>
                    <img src="<?= htmlspecialchars('../' . $app['profile_picture']) ?>" class="size-full object-cover">
                <?php else: ?>
                    <span class="text-primary font-black text-2xl italic tracking-tighter"><?= $initials ?></span>
                <?php endif; ?>
                <div class="absolute inset-0 bg-gradient-to-t from-primary/20 to-transparent"></div>
            </div>
            <div>
                <h3 class="text-3xl font-black italic uppercase tracking-tighter text-white leading-none mb-2">
                    <?= htmlspecialchars($app['first_name'] . ' ' . $app['last_name']) ?></h3>
                <div class="flex items-center gap-3">
                    <span
                        class="px-3 py-1 rounded-full bg-primary/10 border border-primary/20 text-[9px] text-primary font-black uppercase tracking-widest italic"><?= htmlspecialchars($app['coach_type']) ?>
                        Applicant</span>
                    <span
                        class="text-[9px] text-gray-500 font-bold uppercase tracking-widest italic flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-[12px]">schedule</span>
                        Submitted: <?= date('M d, Y', strtotime($app['submitted_at'])) ?>
                    </span>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Bio & Contact -->
            <div class="space-y-6">
                <div class="grid grid-cols-2 gap-4">
                    <div class="bg-white/[0.03] p-5 rounded-2xl border border-white/5">
                        <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-1.5">Email Address</p>
                        <p class="text-sm font-bold text-white tracking-tight italic"><?= htmlspecialchars($app['email']) ?>
                        </p>
                    </div>
                    <div class="bg-white/[0.03] p-5 rounded-2xl border border-white/5">
                        <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-1.5">Contact Number</p>
                        <p class="text-sm font-bold text-white tracking-tight italic">
                            <?= htmlspecialchars($app['contact_number'] ?: '---') ?></p>
                    </div>
                    <div class="bg-white/[0.03] p-5 rounded-2xl border border-white/5">
                        <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-1.5">Date of Birth</p>
                        <p class="text-sm font-bold text-white tracking-tight italic">
                            <?= $app['birth_date'] ? date('M d, Y', strtotime($app['birth_date'])) : '---' ?></p>
                    </div>
                    <div class="bg-white/[0.03] p-5 rounded-2xl border border-white/5">
                        <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-1.5">Gender</p>
                        <p class="text-sm font-bold text-white tracking-tight italic uppercase">
                            <?= htmlspecialchars($app['sex'] ?: '---') ?></p>
                    </div>
                </div>

                <div class="bg-primary/5 p-6 rounded-[24px] border border-primary/10">
                    <h4
                        class="text-[10px] font-black uppercase text-primary tracking-[0.2em] mb-4 flex items-center gap-2 italic">
                        <span class="material-symbols-outlined text-sm">verified_user</span>
                        License Details
                    </h4>
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-1">Coach License ID
                            </p>
                            <p class="text-lg font-black text-white italic tracking-tighter">
                                <?= htmlspecialchars($app['license_number'] ?: 'UNREGISTERED') ?></p>
                        </div>
                        <div
                            class="size-12 rounded-xl bg-primary/20 border border-primary/20 flex items-center justify-center">
                            <span class="material-symbols-outlined text-primary text-2xl">license</span>
                        </div>
                    </div>
                </div>

                <div class="bg-white/[0.01] p-6 rounded-[24px] border border-white/5 border-dashed">
                    <p class="text-[10px] font-black uppercase text-gray-500 tracking-[0.2em] mb-2 italic">Reviewer Notes
                    </p>
                    <p class="text-xs text-gray-400 italic leading-relaxed">
                        <?= !empty($app['remarks']) ? nl2br(htmlspecialchars($app['remarks'])) : "No notes provided for this hiring period." ?>
                    </p>
                </div>
            </div>

            <!-- Document Viewer -->
            <div class="space-y-4">
                <div class="flex items-center justify-between px-2">
                    <h4 class="text-[10px] font-black uppercase text-primary tracking-[0.2em] italic">Skills & Files</h4>
                    <a href="<?= $app['certification_file'] ?>" target="_blank"
                        class="text-[9px] font-black uppercase tracking-widest text-primary flex items-center gap-1.5 hover:underline">
                        <span class="material-symbols-outlined text-xs">open_in_new</span> Full Resolution
                    </a>
                </div>
                <div
                    class="relative bg-black/40 border border-white/5 rounded-[32px] overflow-hidden aspect-[4/5] shadow-2xl group transition-all">
                    <div class="absolute inset-0 p-4">
                        <div class="w-full h-full rounded-2xl overflow-hidden bg-white/[0.02] relative">
                            <?php if ($isPdf): ?>
                                <iframe
                                    src="<?= htmlspecialchars($app['certification_file']) ?>#toolbar=0&navpanes=0&scrollbar=0"
                                    class="w-full h-full border-none opacity-80 group-hover:opacity-100 transition-opacity"
                                    scrolling="yes"></iframe>
                                <div
                                    class="absolute inset-0 bg-transparent z-10 pointer-events-none group-hover:hidden transition-all">
                                </div>
                            <?php else: ?>
                                <img src="<?= htmlspecialchars($app['certification_file']) ?>"
                                    class="w-full h-full object-cover opacity-80 group-hover:opacity-100 transition-all duration-700 group-hover:scale-105">
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <p class="text-[8px] font-extrabold text-gray-600 uppercase tracking-[0.3em] text-center italic">Verified
                    Document</p>
            </div>
        </div>

        <!-- Inline Actions -->
        <div class="flex gap-4 pt-6 mt-4 border-t border-white/5">
            <button
                onclick='approveApplication(<?= json_encode(["coach_application_id" => $app["coach_application_id"], "first_name" => $app["first_name"], "last_name" => $app["last_name"], "session_rate" => $app["session_rate"], "coach_type" => $app["coach_type"]]) ?>)'
                class="flex-1 h-14 rounded-2xl bg-primary text-white text-[11px] font-black uppercase italic tracking-[0.2em] shadow-xl shadow-primary/20 hover:scale-[1.02] active:scale-95 transition-all">
                Hire Now
            </button>
            <button onclick="rejectApplication(<?= $app['coach_application_id'] ?>)"
                class="px-8 h-14 rounded-2xl bg-white/5 border border-white/10 text-rose-500 text-[11px] font-black uppercase italic tracking-widest hover:bg-rose-500/10 hover:border-rose-500/50 transition-all">
                Decline
            </button>
        </div>
    </div>
    <?php
    exit;
}

// --- APPLICATION REVIEW LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['approve_coach_app', 'reject_coach_app'])) {
    header('Content-Type: application/json');
    $app_id = $_POST['application_id'] ?? null;

    if (!$app_id) {
        echo json_encode(['success' => false, 'message' => "Protocol Error: Application identifier missing."]);
        exit;
    }

    try {
        if ($_POST['action'] === 'reject_coach_app') {
            // Standard rejection only - No deletion allowed
            $stmtReject = $pdo->prepare("UPDATE coach_applications SET application_status = 'Rejected', reviewed_by = ?, reviewed_at = NOW(), remarks = ? WHERE coach_application_id = ? AND gym_id = ?");
            $stmtReject->execute([$user_id, $_POST['remarks'] ?? 'Declined via Management Portal', $app_id, $gym_id]);
            echo json_encode(['success' => true, 'message' => "Application has been successfully declined."]);
            exit;
        }

        if ($_POST['action'] === 'approve_coach_app') {
            $session_rate = $_POST['session_rate'] ?? 0.00;
            $employment = $_POST['employment'] ?? 'PART-TIME';

            // 1. Get Application & User Details
            $stmtAppDetails = $pdo->prepare("SELECT ca.*, u.email, u.first_name, u.last_name FROM coach_applications ca JOIN users u ON ca.user_id = u.user_id WHERE ca.coach_application_id = ? AND ca.gym_id = ?");
            $stmtAppDetails->execute([$app_id, $gym_id]);
            $app = $stmtAppDetails->fetch();

            if (!$app) {
                echo json_encode(['success' => false, 'message' => "Validation Error: Application record not found."]);
                exit;
            }

            $pdo->beginTransaction();

            // 2. Update Application Status
            $stmtUpdateApp = $pdo->prepare("UPDATE coach_applications SET application_status = 'Approved', reviewed_by = ?, reviewed_at = NOW() WHERE coach_application_id = ?");
            $stmtUpdateApp->execute([$user_id, $app_id]);

            // 3. Assign Role (Coach)
            $stmtRoleLookup = $pdo->prepare("SELECT role_id FROM roles WHERE role_name = 'Coach' LIMIT 1");
            $stmtRoleLookup->execute();
            $role_id = $stmtRoleLookup->fetchColumn();

            if (!$role_id) {
                $stmtAddRole = $pdo->prepare("INSERT INTO roles (role_name) VALUES ('Coach')");
                $stmtAddRole->execute();
                $role_id = $pdo->lastInsertId();
            }

            $stmtUserRole = $pdo->prepare("INSERT INTO user_roles (user_id, role_id, gym_id, role_status, assigned_at) VALUES (?, ?, ?, 'Active', NOW()) ON DUPLICATE KEY UPDATE role_status = 'Active'");
            $stmtUserRole->execute([$app['user_id'], $role_id, $gym_id]);

            // 4. Insert into Staff
            $stmtStaffAdd = $pdo->prepare("INSERT INTO staff (user_id, gym_id, staff_role, employment_type, hire_date, status, created_at, updated_at) VALUES (?, ?, 'Coach', ?, CURRENT_DATE, 'Active', NOW(), NOW())");
            $stmtStaffAdd->execute([$app['user_id'], $gym_id, $employment]);

            // 5. Insert into Coaches
            $stmtCoachAdd = $pdo->prepare("INSERT INTO coaches (user_id, gym_id, coach_application_id, hire_date, session_rate, status, created_at, updated_at) VALUES (?, ?, ?, CURRENT_DATE, ?, 'Active', NOW(), NOW())");
            $stmtCoachAdd->execute([$app['user_id'], $gym_id, $app_id, $session_rate]);

            $pdo->commit();

            // 6. Notify Coach
            $subject = "Application Approved!";
            $content = "
                <p>Hello <strong>" . htmlspecialchars($app['first_name']) . "</strong>,</p>
                <p>Great news! Your coach application has been <strong>Approved</strong>. You are now officially part of our team.</p>
                <p>Please log in to your account to view your updated dashboard and start managing your sessions.</p>
            ";
            try {
                sendSystemEmail($app['email'], $subject, getEmailTemplate("Welcome Aboard!", $content));
            } catch (Exception $e) {
            }

            echo json_encode(['success' => true, 'message' => "Coach " . $app['first_name'] . " has been officially hired and notified."]);
            exit;
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => "System Exception: " . $e->getMessage()]);
        exit;
    }
}

// --- SUBSCRIPTION CHECK ---
$stmtSubStatus = $pdo->prepare("SELECT subscription_status FROM client_subscriptions WHERE gym_id = ? ORDER BY created_at DESC LIMIT 1");
$stmtSubStatus->execute([$gym_id]);
$sub_status = $stmtSubStatus->fetchColumn() ?: 'None';
$is_sub_active = (strtolower($sub_status) === 'active');
$is_restricted = (!$is_sub_active);

// Helper function for Base64 conversion
if (!function_exists('convertFileToBase64')) {
    function convertFileToBase64($fileInputName)
    {
        if (isset($_FILES[$fileInputName]) && $_FILES[$fileInputName]['error'] == 0) {
            $tmpPath = $_FILES[$fileInputName]['tmp_name'];
            $fileType = $_FILES[$fileInputName]['type'];
            $fileData = file_get_contents($tmpPath);
            return 'data:' . $fileType . ';base64,' . base64_encode($fileData);
        }
        return null;
    }
}

// --- ADD STAFF LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_staff') {
    header('Content-Type: application/json');
    if (!$is_sub_active) {
        echo json_encode(['success' => false, 'message' => "Action restricted. Your subscription is currently $sub_status."]);
        exit;
    } else {
        // --- MAX STAFF LIMIT CHECK ---
        $stmtMaxStaff = $pdo->query("SELECT setting_value FROM system_settings WHERE user_id = 0 AND setting_key = 'max_staff'");
        $max_staff = (int) $stmtMaxStaff->fetchColumn();
        if ($max_staff <= 0)
            $max_staff = 10;

        $stmtCurrentStaff = $pdo->prepare("SELECT COUNT(*) FROM staff WHERE gym_id = ?");
        $stmtCurrentStaff->execute([$gym_id]);
        $current_staff_count = (int) $stmtCurrentStaff->fetchColumn();

        if ($current_staff_count >= $max_staff) {
            echo json_encode(['success' => false, 'message' => "Action restricted. Limit of $max_staff staff reached."]);
            exit;
        } else {
            $fname = $_POST['first_name'] ?? '';
            $mname = $_POST['middle_name'] ?? '';
            $lname = $_POST['last_name'] ?? '';
            $email = $_POST['email'] ?? '';
            $contact = $_POST['contact_number'] ?? '0000000000';
            $bdate = !empty($_POST['birth_date']) ? $_POST['birth_date'] : null;
            $sex = $_POST['sex'] ?? 'Prefer not to say';
            $session_rate = $_POST['session_rate'] ?? 0.00;
            $license_number = trim($_POST['license_number'] ?? '');
            $cert_file = convertFileToBase64('certification_file');

            // Age Validation (18+)
            if ($bdate) {
                $birthDateObj = new DateTime($bdate);
                $today = new DateTime();
                $age = $today->diff($birthDateObj)->y;
                if ($birthDateObj > $today) {
                    echo json_encode(['success' => false, 'message' => "Validation Error: Birthdate cannot be in the future protocol."]);
                    exit;
                }
                if ($age < 18) {
                    echo json_encode(['success' => false, 'message' => "Error: Staff must be at least 18 years old."]);
                    exit;
                }
            }
            $role = $_POST['role'] ?? 'Coach';
            $employment = $_POST['employment'] ?? 'FULL-TIME';

            $password = bin2hex(random_bytes(4));

            if (empty($fname) || empty($lname) || empty($email) || empty($role)) {
                echo json_encode(['success' => false, 'message' => "Error: Missing required information."]);
                exit;
            } else {
                // Global Email Uniqueness Check
                $stmtEmailCheck = $pdo->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
                $stmtEmailCheck->execute([$email]);
                if ($stmtEmailCheck->fetch()) {
                    echo json_encode(['success' => false, 'message' => "The email address '$email' is already registered in the system."]);
                    exit;
                }

                $base_username = strtolower(substr($fname, 0, 1) . $lname);
                $username = $base_username;
                $count = 1;
                while (true) {
                    $stmtCheck = $pdo->prepare("SELECT user_id FROM users WHERE username = ? LIMIT 1");
                    $stmtCheck->execute([$username]);
                    if (!$stmtCheck->fetch())
                        break;
                    $username = $base_username . $count++;
                }

                $pass_hash = password_hash($password, PASSWORD_DEFAULT);

                try {
                    $pdo->beginTransaction();

                    // 1. Insert into Users
                    $stmtUser = $pdo->prepare("INSERT INTO users (username, email, password_hash, first_name, middle_name, last_name, contact_number, birth_date, sex, is_verified, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, NOW(), NOW())");
                    $stmtUser->execute([$username, $email, $pass_hash, $fname, $mname, $lname, $contact, $bdate, $sex]);
                    $new_user_id = $pdo->lastInsertId();

                    // 2. Insert into user_roles
                    $role_name = (strtolower($role) === 'coach') ? 'Coach' : 'Staff';
                    $stmtRoleLookup = $pdo->prepare("SELECT role_id FROM roles WHERE role_name = ? LIMIT 1");
                    $stmtRoleLookup->execute([$role_name]);
                    $role_row = $stmtRoleLookup->fetch();

                    if (!$role_row) {
                        $stmtAddRole = $pdo->prepare("INSERT INTO roles (role_name) VALUES (?)");
                        $stmtAddRole->execute([$role_name]);
                        $role_id = $pdo->lastInsertId();
                    } else {
                        $role_id = $role_row['role_id'];
                    }

                    $stmtUserRole = $pdo->prepare("INSERT INTO user_roles (user_id, role_id, gym_id, role_status, assigned_at) VALUES (?, ?, ?, 'Active', NOW())");
                    $stmtUserRole->execute([$new_user_id, $role_id, $gym_id]);

                    // 3. Insert into Staff
                    $stmtStaffAdd = $pdo->prepare("INSERT INTO staff (user_id, gym_id, staff_role, employment_type, hire_date, status, created_at, updated_at) VALUES (?, ?, ?, ?, CURRENT_DATE, 'Active', NOW(), NOW())");
                    $stmtStaffAdd->execute([$new_user_id, $gym_id, $role, $employment]);

                    // 4. Insert into Coaches if applicable (3NF Specialized Entity)
                    if (strtolower($role) === 'coach' || strtolower($role) === 'trainer') {
                        $coach_app_id = null;

                        // Create a "Shadow Application" if credentials provided
                        if (!empty($license_number) || $cert_file) {
                            $stmtShadowApp = $pdo->prepare("INSERT INTO coach_applications (user_id, gym_id, coach_type, license_number, certification_file, application_status, submitted_at, remarks) VALUES (?, ?, ?, ?, ?, 'Approved', NOW(), 'Manually registered by Staff')");
                            $stmtShadowApp->execute([$new_user_id, $gym_id, $employment, $license_number, $cert_file ?: '']);
                            $coach_app_id = $pdo->lastInsertId();
                        }

                        $stmtCoachAdd = $pdo->prepare("INSERT INTO coaches (user_id, gym_id, coach_application_id, hire_date, session_rate, status, created_at, updated_at) VALUES (?, ?, ?, CURRENT_DATE, ?, 'Active', NOW(), NOW())");
                        $stmtCoachAdd->execute([$new_user_id, $gym_id, $coach_app_id, $session_rate]);
                    }

                    $pdo->commit();

                    // Send Welcome Email
                    $subject = "Welcome to the Team!";
                    $login_url = "https://" . $_SERVER['HTTP_HOST'] . "/login.php";
                    $content = "
                        <p>Hello <strong>" . htmlspecialchars($fname) . "</strong>,</p>
                        <p>Your staff account has been successfully created.</p>
                        <div style='background: #f8f8f8; padding: 20px; border-radius: 10px; margin: 20px 0;'>
                            <p style='margin: 0;'><strong>Username:</strong> " . htmlspecialchars($username) . "</p>
                            <p style='margin: 5px 0 0 0;'><strong>Password:</strong> " . htmlspecialchars($password) . "</p>
                        </div>
                        <p>You can access the portal here: <a href='$login_url'>$login_url</a></p>
                    ";
                    try {
                        sendSystemEmail($email, $subject, getEmailTemplate("Welcome to the Team!", $content));
                    } catch (Exception $e) {
                    }

                    echo json_encode(['success' => true, 'message' => "Staff account for $fname $lname has been successfully initialized."]);
                    exit;
                } catch (Exception $e) {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'message' => "Internal Error: " . $e->getMessage()]);
                    exit;
                }
            }
        }
    }
}

// ── 4-Color Elite Branding System ─────────────────────────────────────────────
if (!function_exists('hexToRgb')) {
    function hexToRgb($hex)
    {
        if (!$hex)
            return "0, 0, 0";
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

$configs = [
    'system_name' => !empty($gym['gym_name']) ? $gym['gym_name'] : 'Owner Portal',
    'system_logo' => !empty($gym['logo_path']) ? $gym['logo_path'] : '',
    'theme_color' => '#8c2bee',
    'secondary_color' => '#a1a1aa',
    'text_color' => '#d1d5db',
    'bg_color' => '#0a090d',
    'card_color' => '#141216',
    'auto_card_theme' => '1',
    'font_family' => 'Lexend',
];

$stmtGlobal = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = 0");
$stmtGlobal->execute();
foreach (($stmtGlobal->fetchAll(PDO::FETCH_KEY_PAIR) ?: []) as $k => $v) {
    if ($v !== null && $v !== '')
        $configs[$k] = $v;
}

$stmtTenant = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = ?");
$stmtTenant->execute([$user_id]);
foreach (($stmtTenant->fetchAll(PDO::FETCH_KEY_PAIR) ?: []) as $k => $v) {
    if ($v !== null && $v !== '')
        $configs[$k] = $v;
}

$theme_color = $configs['theme_color'];
$highlight_color = $configs['secondary_color'];
$text_color = $configs['text_color'];
$bg_color = $configs['bg_color'];
$font_family = $configs['font_family'] ?? 'Lexend';
$auto_card_theme = $configs['auto_card_theme'] ?? '1';
$card_color = $configs['card_color'];

$primary_rgb = hexToRgb($theme_color);
$highlight_rgb = hexToRgb($highlight_color);
$card_bg_css = ($auto_card_theme === '1')
    ? "rgba({$primary_rgb}, 0.05)"
    : $card_color;

$page = [
    'logo_path' => !empty($configs['system_logo']) ? $configs['system_logo'] : (!empty($gym['logo_path']) ? $gym['logo_path'] : ''),
    'theme_color' => $theme_color,
    'bg_color' => $bg_color,
    'system_name' => !empty($configs['system_name']) ? $configs['system_name'] : (!empty($gym['gym_name']) ? $gym['gym_name'] : 'Owner Portal'),
];

$stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM staff WHERE gym_id = ?");
$stmtTotal->execute([$gym_id]);
$total_staff = (int) $stmtTotal->fetchColumn();

$stmtActive = $pdo->prepare("SELECT COUNT(*) FROM staff WHERE gym_id = ? AND status = 'Active'");
$stmtActive->execute([$gym_id]);
$active_personnel = (int) $stmtActive->fetchColumn();

$search = $_GET['search'] ?? '';
$f_role = $_GET['f_role'] ?? '';
$f_status = $_GET['f_status'] ?? '';

$where = "s.gym_id = :gym_id";
$params = [':gym_id' => $gym_id];

if (!empty($search)) {
    $where .= " AND (u.first_name LIKE :s1 OR u.last_name LIKE :s2 OR s.staff_role LIKE :s3)";
    $params[':s1'] = "%$search%";
    $params[':s2'] = "%$search%";
    $params[':s3'] = "%$search%";
}

if (!empty($f_role)) {
    $where .= " AND s.staff_role = :role";
    $params[':role'] = $f_role;
}

if (!empty($f_status)) {
    $where .= " AND s.status = :status";
    $params[':status'] = $f_status;
}

$stmtStaff = $pdo->prepare("
    SELECT s.*, u.first_name, u.last_name, u.email, u.profile_picture, c.session_rate
    FROM staff s
    JOIN users u ON s.user_id = u.user_id
    LEFT JOIN coaches c ON s.user_id = c.user_id AND s.gym_id = c.gym_id
    WHERE $where
    ORDER BY s.created_at DESC
");
$stmtStaff->execute($params);
$staff_list = $stmtStaff->fetchAll();

$stmtRoles = $pdo->prepare("SELECT DISTINCT staff_role FROM staff WHERE gym_id = ?");
$stmtRoles->execute([$gym_id]);
$roles = $stmtRoles->fetchAll(PDO::FETCH_COLUMN);

// --- PENDING APPLICATIONS ---
$stmtPending = $pdo->prepare("
    SELECT ca.*, u.first_name, u.last_name, u.email, u.profile_picture, u.contact_number
    FROM coach_applications ca
    JOIN users u ON ca.user_id = u.user_id
    WHERE ca.gym_id = ? AND ca.application_status = 'Pending'
    ORDER BY ca.submitted_at DESC
");
$stmtPending->execute([$gym_id]);
$pending_apps = $stmtPending->fetchAll();
$pending_apps_count = count($pending_apps);
?>

<!DOCTYPE html>
<html class="dark" lang="en">

<head>
    <meta charset="utf-8">
    <title>Team Management | Horizon</title>
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
                        "surface-dark": "var(--card-bg)",
                        "border-subtle": "rgba(255,255,255,0.05)"
                    }
                }
            }
        }
    </script>
    <style>
        :root {
            --primary:
                <?= $theme_color ?>
            ;
            --primary-rgb:
                <?= $primary_rgb ?>
            ;
            --highlight:
                <?= $highlight_color ?>
            ;
            --highlight-rgb:
                <?= $highlight_rgb ?>
            ;
            --text-main:
                <?= $text_color ?>
            ;
            --background:
                <?= $bg_color ?>
            ;
            --background-rgb:
                <?= hexToRgb($bg_color) ?>
            ;
            --card-bg:
                <?= $card_bg_css ?>
            ;
            --card-blur: 20px;
        }

        body {
            font-family: '<?= $font_family ?>', sans-serif;
            background-color: var(--background);
            color: var(--text-main);
            overflow: hidden;
            color-scheme: dark;
        }

        .glass-card {
            background: var(--card-bg);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 24px;
            backdrop-filter: blur(var(--card-blur));
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

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
            background-color: var(--background);
            border-right: 1px solid rgba(255, 255, 255, 0.05);
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
            color: var(--text-main);
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
            transition: opacity 0.2s ease, color 0.2s ease;
            text-decoration: none;
            white-space: nowrap;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: color-mix(in srgb, var(--text-main) 45%, transparent);
        }

        .nav-item:hover {
            color: var(--text-main);
        }

        .nav-item .material-symbols-outlined {
            color: var(--highlight);
            transition: transform 0.2s ease;
        }

        .nav-item:hover .material-symbols-outlined {
            transform: scale(1.12);
        }

        .nav-item.active {
            color: var(--primary) !important;
            position: relative;
        }

        .nav-item.active .material-symbols-outlined {
            color: var(--primary);
        }

        .nav-item.active::after {
            content: '';
            position: absolute;
            right: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 4px;
            height: 24px;
            background: var(--primary);
            border-radius: 4px 0 0 4px;
        }

        .label-muted {
            color: var(--text-main);
            opacity: 0.6;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.15em;
        }

        .status-card-primary {
            border: 1px solid rgba(var(--primary-rgb), 0.3);
            background: linear-gradient(135deg, rgba(var(--primary-rgb), 0.05) 0%, rgba(var(--primary-rgb), 0.01) 100%);
        }

        .status-card-green {
            border: 1px solid rgba(16, 185, 129, 0.3);
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.05) 0%, rgba(16, 185, 129, 0.01) 100%);
        }

        *::-webkit-scrollbar {
            display: none !important;
        }

        * {
            -ms-overflow-style: none !important;
            scrollbar-width: none !important;
        }

        .modal-overlay {
            background: rgba(var(--background-rgb), 0.4);
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            display: none !important;
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            left: 110px;
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 24px;
            transition: left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .side-nav:hover~.modal-overlay {
            left: 300px;
        }

        .modal-overlay.active {
            display: flex !important;
        }

        .modal-content {
            background: var(--card-bg);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 28px;
            width: 100%;
            max-width: 500px;
            transform: scale(0.95);
            opacity: 0;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .modal-overlay.active .modal-content {
            transform: scale(1);
            opacity: 1;
        }

        .filter-input {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            padding: 14px 22px;
            color: var(--text-main);
            font-size: 13px;
            font-weight: 700;
            outline: none;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            appearance: none;
        }

        .filter-input:focus {
            border-color: var(--primary);
            background: rgba(var(--primary-rgb), 0.08);
            box-shadow: 0 0 0 4px rgba(var(--primary-rgb), 0.1);
        }

        select.filter-input {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%23<?= str_replace('#', '', $theme_color) ?>'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='3' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 1em;
            padding-right: 2.5rem;
            cursor: pointer;
        }

        select.filter-input option {
            background-color: #141216;
            color: var(--text-main);
            padding: 15px;
            font-weight: 600;
        }

        .table-header-alt {
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.25em;
            color: var(--text-main);
            opacity: 0.5;
        }

        .blur-overlay {
            position: relative;
        }

        .blur-overlay-content {
            filter: blur(12px);
            pointer-events: none;
            user-select: none;
        }

        #subModal {
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            left: 110px;
            z-index: 2000;
            display: none !important;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: rgba(var(--background-rgb), 0.4);
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            transition: left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        #subModal.active {
            display: flex !important;
        }

        .side-nav:hover~#subModal {
            left: 300px;
        }

        .strength-bar {
            height: 100%;
            width: 0%;
            transition: all 0.3s ease;
        }

        .strength-weak {
            width: 33%;
            background: #f43f5e;
        }

        .strength-medium {
            width: 66%;
            background: #f59e0b;
        }

        .strength-strong {
            width: 100%;
            background: #10b981;
        }

        /* Elite Notification System */
        .elite-notify {
            position: fixed;
            top: 40px;
            right: 40px;
            z-index: 9999;
            background: rgba(20, 18, 22, 0.85);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 20px;
            padding: 16px 24px;
            min-width: 320px;
            max-width: 450px;
            transform: translateX(120%);
            transition: transform 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            gap: 16px;
            pointer-events: none;
        }

        .elite-notify.active {
            transform: translateX(0);
            pointer-events: auto;
        }

        .elite-notify-icon {
            size: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            shrink-0;
            color: white;
        }

        .elite-notify-success .elite-notify-icon {
            background: rgba(16, 185, 129, 0.15);
            color: #10b981;
        }

        .elite-notify-error .elite-notify-icon {
            background: rgba(244, 63, 94, 0.15);
            color: #f43f5e;
        }

        .elite-notify-content {
            flex: 1;
        }

        .elite-notify-title {
            font-size: 11px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            margin-bottom: 2px;
        }

        .elite-notify-msg {
            font-size: 10px;
            font-weight: 600;
            color: var(--text-main);
            opacity: 0.6;
            line-height: 1.5;
        }
    </style>
    <script>
        function showSubWarning() { document.getElementById('subModal').classList.add('active'); }
        function closeSubModal() { document.getElementById('subModal').classList.remove('active'); }

        window.addEventListener('DOMContentLoaded', () => {
            <?php if ($is_restricted): ?>
                showSubWarning();
            <?php endif; ?>
        });
    </script>
</head>

<body class="flex h-screen overflow-hidden">

    <?php
    include '../includes/tenant_sidebar.php';
    ?>

    <main
        class="main-content flex-1 p-10 overflow-y-auto no-scrollbar pb-10 <?= $is_restricted ? 'blur-overlay' : '' ?>">
        <div class="<?= $is_restricted ? 'blur-overlay-content' : '' ?>">

            <header class="mb-10 flex flex-row justify-between items-end gap-6">
                <div>
                    <h2 class="text-3xl font-black italic uppercase tracking-tighter leading-none"
                        style="color:var(--text-main)">Our <span class="text-primary">Team</span></h2>
                    <p class="text-[--text-main] opacity-40 text-xs font-bold uppercase tracking-widest mt-2">
                        <?= htmlspecialchars($gym['gym_name'] ?? 'Horizon Gym') ?> Staff List</p>
                </div>
                <div class="text-right shrink-0">
                    <p id="topClock"
                        class="text-[--text-main] font-black italic text-2xl tracking-tighter leading-none mb-2">
                        00:00:00 AM</p>
                    <p class="text-primary text-[10px] font-black uppercase tracking-[0.2em] leading-none opacity-80">
                        <?= date('l, M d, Y') ?></p>
                </div>
            </header>

            <?php if (isset($_GET['success'])): ?>
                <div
                    class="mb-8 p-4 bg-emerald-500/10 border border-emerald-500/20 rounded-2xl flex items-center gap-3 animate-pulse">
                    <span class="material-symbols-outlined text-emerald-500">check_circle</span>
                    <p class="text-[10px] font-black uppercase tracking-widest text-emerald-400 italic">Staff added
                        successfully!</p>
                </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="mb-8 p-4 bg-rose-500/10 border border-rose-500/20 rounded-2xl flex items-center gap-3">
                    <span class="material-symbols-outlined text-rose-500">error_outline</span>
                    <p class="text-[10px] font-black uppercase tracking-widest text-rose-400 italic">
                        <?= htmlspecialchars($error) ?>
                    </p>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-10">
                <div
                    class="glass-card p-8 status-card-primary relative overflow-hidden group hover:scale-[1.02] transition-all">
                    <span
                        class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform"
                        style="color:var(--primary)">groups</span>
                    <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">Total
                        Staff</p>
                    <h3 class="text-2xl font-black italic uppercase" style="color:var(--text-main)">
                        <?= number_format($total_staff) ?> <span class="text-xs opacity-40">Staff Members</span></h3>
                    <p class="text-primary text-[10px] font-black uppercase mt-2 italic shadow-sm">Total Team Size
                    </p>
                </div>

                <div
                    class="glass-card p-8 status-card-green relative overflow-hidden group hover:scale-[1.02] transition-all">
                    <span
                        class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform text-emerald-500">how_to_reg</span>
                    <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">
                        Active Staff</p>
                    <h3 class="text-2xl font-black italic uppercase text-emerald-400">
                        <?= number_format($active_personnel) ?> <span class="text-xs opacity-40">Active Staff</span></h3>
                    <p class="text-emerald-500/60 text-[10px] font-black uppercase mt-2 italic flex items-center gap-2">
                        <span class="size-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                        Currently Active
                    </p>
                </div>

                <div
                    class="glass-card p-8 status-card-primary relative overflow-hidden group hover:scale-[1.02] transition-all lg:hidden xl:block">
                    <span
                        class="material-symbols-outlined absolute right-8 top-1/2 -translate-y-1/2 text-6xl opacity-10 group-hover:scale-110 transition-transform"
                        style="color:var(--primary)">badge</span>
                    <p class="text-[10px] font-black uppercase text-[--text-main] opacity-60 mb-2 tracking-widest">Total
                        Roles</p>
                    <h3 class="text-2xl font-black italic uppercase" style="color:var(--text-main)"><?= count($roles) ?>
                        <span class="text-xs opacity-40">Active Roles</span></h3>
                    <p class="text-[10px] font-black uppercase mt-2 italic" style="color:var(--primary)">Roles Breakdown</p>
                </div>
            </div>

            <!-- Superadmin Style Underline Tabs -->
            <div class="flex items-center gap-12 mb-10 border-b border-white/5 px-2">
                <a href="?tab=team"
                    class="pb-5 relative transition-all duration-300 group">
                    <span class="text-[10px] font-black uppercase tracking-[0.3em] <?= $active_tab === 'team' ? 'text-primary' : 'text-white/30 group-hover:text-white/50' ?>">
                        Staff List
                    </span>
                    <?php if ($active_tab === 'team'): ?>
                        <div class="absolute bottom-0 left-0 w-full h-[2px] bg-primary shadow-[0_0_10px_rgba(var(--primary-rgb),0.3)]"></div>
                    <?php endif; ?>
                </a>
                <a href="?tab=requests"
                    class="pb-5 relative transition-all duration-300 group">
                    <span class="text-[10px] font-black uppercase tracking-[0.3em] <?= $active_tab === 'requests' ? 'text-primary' : 'text-white/30 group-hover:text-white/50' ?>">
                        Join Requests
                    </span>
                    <?php if ($pending_apps_count > 0): ?>
                        <span class="absolute top-[-2px] right-[-12px] flex h-1.5 w-1.5">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-1.5 w-1.5 bg-amber-500"></span>
                        </span>
                    <?php endif; ?>
                    <?php if ($active_tab === 'requests'): ?>
                        <div class="absolute bottom-0 left-0 w-full h-[2px] bg-primary shadow-[0_0_10px_rgba(var(--primary-rgb),0.3)]"></div>
                    <?php endif; ?>
                </a>
            </div>

            <div class="glass-card overflow-hidden shadow-2xl border border-white/5">
                <!-- Elite Filter Bar -->
                <div class="p-8 border-b border-white/5 bg-white/[0.01]">
                    <form method="GET" class="flex flex-wrap items-center gap-4">
                        <input type="hidden" name="tab" value="<?= $active_tab ?>">

                        <!-- Search -->
                        <div class="flex-1 min-w-[280px] relative group">
                            <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-primary/50 text-base transition-transform group-focus-within:scale-110">search</span>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                                   placeholder="Search by name, role or email..." 
                                   class="w-full h-[52px] bg-white/5 border border-white/10 rounded-2xl pl-12 pr-4 text-[11px] font-bold uppercase tracking-wider text-white outline-none focus:border-primary/50 transition-all">
                        </div>

                        <!-- Role Filter -->
                        <div class="w-56 shrink-0 relative">
                            <select name="f_role" onchange="this.form.submit()"
                                    class="w-full h-[52px] bg-white/5 border border-white/10 rounded-2xl px-5 text-[10px] font-black uppercase tracking-widest text-white/60 outline-none hover:border-white/20 transition-all appearance-none">
                                <option value="">All Roles</option>
                                <?php foreach ($roles as $r): ?>
                                    <option value="<?= htmlspecialchars($r) ?>" <?= $f_role === $r ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($r) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-white/20 pointer-events-none">expand_more</span>
                        </div>

                        <!-- Status Filter -->
                        <div class="w-44 shrink-0 relative">
                            <select name="f_status" onchange="this.form.submit()"
                                    class="w-full h-[52px] bg-white/5 border border-white/10 rounded-2xl px-5 text-[10px] font-black uppercase tracking-widest text-white/60 outline-none hover:border-white/20 transition-all appearance-none">
                                <option value="">All Status</option>
                                <option value="Active" <?= $f_status === 'Active' ? 'selected' : '' ?>>Active Members</option>
                                <option value="Inactive" <?= $f_status === 'Inactive' ? 'selected' : '' ?>>Inactive Members</option>
                            </select>
                            <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-white/20 pointer-events-none">expand_more</span>
                        </div>

                        <!-- Clear & Add -->
                        <div class="flex items-center gap-3">
                            <a href="staff.php?tab=<?= $active_tab ?>" 
                               class="h-[52px] w-[52px] flex items-center justify-center rounded-2xl bg-white/[0.03] border border-white/10 text-white/40 hover:text-white transition-all active:scale-95" title="Clear Filters">
                                <span class="material-symbols-outlined text-xl">restart_alt</span>
                            </a>
                            <button type="button" onclick="<?= $is_sub_active ? 'toggleAddModal()' : 'showSubWarning()' ?>"
                                    class="h-[52px] px-8 rounded-2xl bg-primary text-white text-[10px] font-black uppercase tracking-widest transition-all hover:scale-[1.02] active:scale-95 shadow-2xl shadow-primary/20 flex items-center gap-3">
                                <span class="material-symbols-outlined text-lg">person_add</span>
                                Add Staff
                            </button>
                        </div>
                    </form>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                            <thead>
                                <tr class="bg-white/5 border-b border-white/5 text-[10px] font-black uppercase tracking-[0.25em]">
                                    <?php if ($active_tab === 'requests'): ?>
                                        <th class="px-8 py-5 table-header-alt w-[40%]">Name & Email</th>
                                        <th class="px-8 py-5 table-header-alt w-[15%]">Role</th>
                                        <th class="px-8 py-5 table-header-alt text-center w-[15%]">Status</th>
                                        <th class="px-8 py-5 table-header-alt text-center w-[20%]">Join Date</th>
                                        <th class="px-8 py-5 table-header-alt text-center w-[10%]">Action</th>
                                    <?php else: ?>
                                        <th class="px-8 py-5 table-header-alt w-[40%]">Name & Email</th>
                                        <th class="px-8 py-5 table-header-alt w-[15%]">Role</th>
                                        <th class="px-8 py-5 table-header-alt text-center w-[15%]">Status</th>
                                        <th class="px-8 py-5 table-header-alt text-center w-[20%]">Join Date</th>
                                        <th class="px-8 py-5 table-header-alt text-center w-[10%]">Action</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                        <tbody class="divide-y divide-white/5">
                            <?php if ($active_tab === 'requests'): ?>
                                <?php if (empty($pending_apps)): ?>
                                    <tr>
                                        <td colspan="5"
                                            class="px-8 py-24 text-center text-[11px] font-black italic uppercase tracking-[0.3em] text-[--text-main] opacity-20">
                                            No staff members found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($pending_apps as $app): ?>
                                        <tr class="hover:bg-white/5 transition-all text-[--text-main]">
                                            <td class="px-8 py-5">
                                                <div class="flex items-center gap-4">
                                                    <div class="size-11 rounded-full border border-white/10 flex items-center justify-center font-black italic text-primary text-[11px] shrink-0 overflow-hidden shadow-inner relative"
                                                         style="background:rgba(var(--primary-rgb), 0.1)">
                                                        <?= strtoupper(substr($app['first_name'], 0, 1) . substr($app['last_name'], 0, 1)) ?>
                                                    </div>
                                                    <div>
                                                        <p class="text-[13px] font-bold tracking-wider text-white">
                                                            <?= htmlspecialchars($app['first_name'] . ' ' . $app['last_name']) ?>
                                                        </p>
                                                        <p class="text-[12px] font-medium opacity-60 tracking-wide text-white">
                                                            <?= htmlspecialchars($app['email']) ?>
                                                        </p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-8 py-5">
                                                <p class="text-[13px] font-bold tracking-wider text-primary">
                                                    <?= htmlspecialchars($app['coach_type']) ?>
                                                </p>
                                                <p class="text-[11px] font-medium opacity-40 tracking-wide mt-1">
                                                    <?= $app['license_number'] ?: 'No License' ?>
                                                </p>
                                            </td>
                                            <td class="px-8 py-5 text-center">
                                                <span class="px-2.5 py-1 rounded-lg bg-amber-500/10 border border-amber-500/20 text-[9px] text-amber-500 font-black uppercase tracking-wider italic">
                                                    Pending
                                                </span>
                                            </td>
                                            <td class="px-8 py-5 text-center">
                                                <p class="text-[12px] font-bold tracking-wider text-white">
                                                    <?= date('M d, Y', strtotime($app['submitted_at'])) ?>
                                                </p>
                                                <p class="text-[10px] font-medium opacity-40 tracking-wide mt-0.5">
                                                    <?= date('h:i A', strtotime($app['submitted_at'])) ?>
                                                </p>
                                            </td>
                                            <td class="px-8 py-5 text-center">
                                                <div class="flex justify-center gap-2">
                                                    <button onclick="openAppDetails(<?= $app['coach_application_id'] ?>)"
                                                            class="size-9 rounded-xl bg-white/5 border border-white/10 text-white hover:bg-primary transition-all active:scale-95 shadow-lg flex items-center justify-center group/btn" title="Review Application">
                                                        <span class="material-symbols-outlined text-lg group-hover/btn:scale-110 transition-transform">visibility</span>
                                                    </button>
                                                    <button onclick="rejectApplication(<?= $app['coach_application_id'] ?>)"
                                                            class="size-9 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-500 hover:bg-rose-500 hover:text-white transition-all flex items-center justify-center">
                                                        <span class="material-symbols-outlined text-sm">close</span>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <!-- Staff List Logic -->
                                <?php if (empty($staff_list)): ?>
                                    <tr>
                                        <td colspan="5"
                                            class="px-8 py-24 text-center text-[11px] font-black italic uppercase tracking-[0.3em] text-[--text-main] opacity-20">
                                            No staff found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($staff_list as $s): ?>
                                        <tr class="hover:bg-white/5 transition-all text-[--text-main]">
                                            <td class="px-8 py-5">
                                                <div class="flex items-center gap-4">
                                                    <?php $initials = strtoupper(substr($s['first_name'] ?? '', 0, 1) . substr($s['last_name'] ?? '', 0, 1)); ?>
                                                    <div class="size-11 rounded-full border border-white/10 flex items-center justify-center font-black italic text-primary text-[11px] shrink-0 overflow-hidden shadow-inner relative"
                                                         style="background:rgba(var(--primary-rgb), 0.1)">
                                                        <?php if (!empty($s['profile_picture'])): ?>
                                                            <img src="<?= htmlspecialchars('../' . $s['profile_picture']) ?>"
                                                                class="size-full object-cover"
                                                                onerror="this.outerHTML='<span class=\'text-primary font-black italic text-[11px]\'><?= $initials ?></span>'">
                                                        <?php else: ?>
                                                            <span class="text-primary font-black italic text-[11px]"><?= $initials ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div>
                                                        <p class="text-[13px] font-bold tracking-wider text-white">
                                                            <?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?>
                                                        </p>
                                                        <p class="text-[12px] font-medium opacity-60 tracking-wide text-white">
                                                            <?= htmlspecialchars($s['email']) ?>
                                                        </p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-8 py-5">
                                                <p class="text-[13px] font-bold tracking-wider text-primary">
                                                    <?= htmlspecialchars($s['staff_role']) ?>
                                                </p>
                                                <p class="text-[11px] font-medium opacity-40 tracking-wide mt-1">
                                                    <?= $s['employment_type'] ?>
                                                </p>
                                            </td>
                                            <td class="px-8 py-5 text-center">
                                                <?php 
                                                $sc = $s['status'] === 'Active' ? 'emerald-500' : 'rose-500';
                                                ?>
                                                <span class="px-2.5 py-1 rounded-lg bg-<?= $sc ?>/10 border border-<?= $sc ?>/20 text-[9px] text-<?= $sc ?> font-black uppercase tracking-wider italic">
                                                    <?= $s['status'] ?>
                                                </span>
                                            </td>
                                            <td class="px-8 py-5 text-center">
                                                <p class="text-[12px] font-bold tracking-wider text-white">
                                                    <?= date('M d, Y', strtotime($s['created_at'] ?? 'now')) ?>
                                                </p>
                                            </td>
                                            <td class="px-8 py-5 text-center">
                                                <div class="flex justify-center">
                                                    <button data-staff='<?= htmlspecialchars(json_encode($s), ENT_QUOTES, 'UTF-8') ?>'
                                                            onclick="openViewModal(this)"
                                                            class="size-9 rounded-xl bg-white/5 border border-white/10 text-white hover:bg-primary transition-all active:scale-95 shadow-lg flex items-center justify-center group/btn" title="View Details">
                                                        <span class="material-symbols-outlined text-lg group-hover/btn:scale-110 transition-transform">visibility</span>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

    </main>

    <!-- View Staff Modal -->
    <div id="viewStaffModal" class="modal-overlay">
        <div class="modal-content overflow-hidden max-w-[600px]">
            <div class="p-8 border-b border-white/5 flex justify-between items-center bg-white/[0.02]">
                <div class="flex items-center gap-5">
                    <div id="view_avatar" class="size-20 rounded-2xl bg-white/5 border border-white/10 overflow-hidden flex items-center justify-center shadow-lg relative">
                        <!-- Profile/Initials injected here -->
                    </div>
                    <div>
                        <h4 id="view_full_name" class="text-xl font-black uppercase tracking-tight text-white leading-tight">Staff Member</h4>
                        <div class="flex items-center gap-2 mt-1">
                            <span id="view_status_badge" class="px-2 py-0.5 rounded-md text-[8px] font-black uppercase tracking-[0.2em] italic border">ACTIVE</span>
                            <span id="view_role_badge" class="text-[9px] font-bold uppercase tracking-widest text-primary opacity-80">COACH</span>
                        </div>
                    </div>
                </div>
                <button onclick="hideViewModal()" class="size-10 rounded-xl bg-white/5 hover:bg-rose-500/20 hover:text-rose-500 transition-all flex items-center justify-center border border-white/5">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </div>

            <div class="p-8 space-y-6 text-left max-h-[70vh] overflow-y-auto no-scrollbar">
                <!-- Section 1: Job Details -->
                <section class="grid grid-cols-2 gap-6 bg-white/[0.02] p-6 rounded-2xl border border-white/5">
                    <div class="space-y-1">
                        <p class="text-[10px] font-bold uppercase tracking-widest opacity-40">Position / Role</p>
                        <p id="view_detailed_role" class="text-sm font-bold text-white uppercase italic tracking-wider">Department Lead</p>
                    </div>
                    <div class="space-y-1">
                        <p class="text-[10px] font-bold uppercase tracking-widest opacity-40">Employment Type</p>
                        <p id="view_employment" class="text-sm font-bold text-white uppercase italic tracking-wider">Full-Time</p>
                    </div>
                </section>

                <!-- Section 2: Contact Info -->
                <section class="grid grid-cols-1 gap-6 bg-white/[0.02] p-6 rounded-2xl border border-white/5">
                    <div class="grid grid-cols-2 gap-6">
                        <div class="space-y-1">
                            <p class="text-[10px] font-bold uppercase tracking-widest opacity-40">Email Address</p>
                            <p id="view_email" class="text-sm font-medium text-white truncate">staff@horizon.com</p>
                        </div>
                        <div class="space-y-1">
                            <p class="text-[10px] font-bold uppercase tracking-widest opacity-40">Contact Number</p>
                            <p id="view_contact" class="text-sm font-medium text-white">09XX-XXX-XXXX</p>
                        </div>
                    </div>
                </section>

                <!-- Section 3: Personal & Bio -->
                <section class="grid grid-cols-3 gap-6">
                    <div class="bg-white/[0.02] p-5 rounded-2xl border border-white/5 space-y-1">
                        <p class="text-[9px] font-bold uppercase tracking-widest opacity-40">Gender</p>
                        <p id="view_sex" class="text-xs font-bold text-white">N/A</p>
                    </div>
                    <div class="bg-white/[0.02] p-5 rounded-2xl border border-white/5 space-y-1">
                        <p class="text-[9px] font-bold uppercase tracking-widest opacity-40">Birthdate</p>
                        <p id="view_birthdate" class="text-xs font-bold text-white">N/A</p>
                    </div>
                    <div class="bg-white/[0.02] p-5 rounded-2xl border border-white/5 space-y-1">
                        <p class="text-[9px] font-bold uppercase tracking-widest opacity-40">Joined On</p>
                        <p id="view_hire_date" class="text-xs font-bold text-white">N/A</p>
                    </div>
                </section>

                <!-- Special: Rate (Conditional) -->
                <section id="view_session_rate_container" class="bg-primary/5 p-6 rounded-2xl border border-primary/10 flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <span class="material-symbols-outlined text-primary text-2xl">payments</span>
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-widest text-primary/60">Session Yield</p>
                            <p class="text-[9px] font-black uppercase tracking-[0.2em] text-white/30">Coach Compensation Rate</p>
                        </div>
                    </div>
                    <p id="view_session_rate" class="text-2xl font-black italic text-primary tracking-tighter">₱0.00</p>
                </section>
            </div>


        </div>
    </div>

    <!-- Add Staff Modal -->
    <div id="addStaffModal" class="modal-overlay">
        <div class="modal-content overflow-hidden max-w-[550px] max-h-[90vh] flex flex-col shadow-2xl border-white/10">
            <div class="px-10 py-8 border-b border-white/5 flex justify-between items-center bg-white/[0.02]">
                <div>
                    <h4 class="font-black italic uppercase text-base tracking-[0.2em] flex items-center gap-3"
                        style="color:var(--text-main)">
                        <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">person_add</span>
                        New Staff Identity
                    </h4>
                    <p class="text-[9px] font-bold uppercase tracking-widest text-white/30 mt-1 italic ml-8">Onboarding Professional Personnel</p>
                </div>
                <button onclick="toggleAddModal()"
                    class="size-10 rounded-xl bg-white/5 hover:bg-rose-500/20 hover:text-rose-500 transition-all flex items-center justify-center border border-white/5">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </div>

            <form method="POST" class="overflow-y-auto no-scrollbar flex-1 text-left" autocomplete="off" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_staff">
                <div class="p-10 space-y-10">
                    <!-- Identity Group -->
                    <section class="space-y-6">
                        <div class="flex items-center gap-4 opacity-40">
                            <div class="h-px flex-1 bg-white/5"></div>
                            <span class="text-[9px] font-black uppercase tracking-[0.3em]">Personal Identity</span>
                            <div class="h-px flex-1 bg-white/5"></div>
                        </div>
                        <div class="grid grid-cols-2 gap-x-8 gap-y-6">
                            <div class="space-y-2.5">
                                <label class="label-muted ml-1">First Name</label>
                                <input type="text" name="first_name" required placeholder="Ex. John" class="filter-input w-full" autocomplete="off">
                            </div>
                            <div class="space-y-2.5">
                                <label class="label-muted ml-1">Middle Name</label>
                                <input type="text" name="middle_name" placeholder="Ex. Quincey" class="filter-input w-full" autocomplete="off">
                            </div>
                            <div class="space-y-2.5">
                                <label class="label-muted ml-1">Last Name</label>
                                <input type="text" name="last_name" required placeholder="Ex. Doe" class="filter-input w-full" autocomplete="off">
                            </div>
                            <div class="space-y-2.5">
                                <label class="label-muted ml-1">Sex</label>
                                <select name="sex" class="filter-input w-full italic">
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                    <option value="Prefer not to say">Prefer not to say</option>
                                </select>
                            </div>
                            <div class="space-y-2.5">
                                <label class="label-muted ml-1">Birthdate</label>
                                <input type="date" name="birth_date" id="birth_date" required max="<?= date('Y-m-d', strtotime('-18 years')) ?>" class="filter-input w-full [color-scheme:dark]" autocomplete="off">
                            </div>
                            <div class="space-y-2.5">
                                <label class="label-muted ml-1">Contact No.</label>
                                <input type="text" name="contact_number" id="contact_number" required placeholder="09XX-XXX-XXXX" class="filter-input w-full" autocomplete="off">
                            </div>
                        </div>
                    </section>

                    <!-- Professional Placement -->
                    <section class="space-y-6">
                        <div class="flex items-center gap-4 opacity-40">
                            <div class="h-px flex-1 bg-white/5"></div>
                            <span class="text-[9px] font-black uppercase tracking-[0.3em]">Job Placement</span>
                            <div class="h-px flex-1 bg-white/5"></div>
                        </div>
                        <div class="grid grid-cols-2 gap-x-8 gap-y-6">
                            <div class="space-y-2.5">
                                <label class="label-muted ml-1">Staff Role</label>
                                <select name="role" required class="filter-input w-full italic" onchange="handleRoleChange()">
                                    <option value="">Choose Position...</option>
                                    <option value="Team Lead">Team Lead</option>
                                    <option value="Coach">Coach</option>
                                    <option value="Personal Trainer">Personal Trainer</option>
                                    <option value="Receptionist">Receptionist</option>
                                    <option value="Support Staff">Support Staff</option>
                                </select>
                            </div>
                            <div class="space-y-2.5">
                                <label class="label-muted ml-1">Employment</label>
                                <select name="employment_type" required class="filter-input w-full italic">
                                    <option value="Full-Time">Full-Time</option>
                                    <option value="Part-Time">Part-Time</option>
                                    <option value="Contractor">Contractor</option>
                                </select>
                            </div>
                        </div>
                    </section>

                    <!-- Contact & Financials -->
                    <section class="space-y-6">
                        <div class="flex items-center gap-4 opacity-40">
                            <div class="h-px flex-1 bg-white/5"></div>
                            <span class="text-[9px] font-black uppercase tracking-[0.3em]">Communication & Rates</span>
                            <div class="h-px flex-1 bg-white/5"></div>
                        </div>
                        <div class="grid grid-cols-2 gap-x-8 gap-y-6">
                            <div class="space-y-2.5">
                                <label class="label-muted ml-1">Email Address</label>
                                <input type="email" name="email" id="email" required placeholder="name@example.com" class="filter-input w-full" autocomplete="off">
                            </div>
                            <div class="space-y-2.5 col-span-2 hidden animate-in slide-in-from-top-2" id="session_rate_field">
                                <label class="label-muted ml-1">Session Rate (₱)</label>
                                <input type="number" step="0.01" name="session_rate" placeholder="0.00" class="filter-input w-full font-black text-primary">
                            </div>
                        </div>
                    </section>

                    <!-- Profile Image -->
                    <section class="space-y-6">
                        <div class="flex items-center gap-4 opacity-40">
                            <div class="h-px flex-1 bg-white/5"></div>
                            <span class="text-[9px] font-black uppercase tracking-[0.3em]">Avatar Profile</span>
                            <div class="h-px flex-1 bg-white/5"></div>
                        </div>
                        <div class="space-y-2.5">
                            <label class="label-muted ml-1">Profile Image (Optional)</label>
                            <label class="w-full h-16 border-2 border-dashed border-white/5 rounded-2xl flex items-center justify-center gap-3 cursor-pointer hover:border-primary/20 hover:bg-primary/5 transition-all group">
                                <span class="material-symbols-outlined text-primary/40 group-hover:text-primary transition-colors">image</span>
                                <span class="text-[10px] font-black uppercase tracking-widest text-white/30 file-name-label group-hover:text-white/60 transition-colors">Choose profile image...</span>
                                <input type="file" name="certification_file" class="hidden" accept="image/*">
                            </label>
                        </div>
                    </section>

                    <div class="p-6 rounded-2xl bg-white/[0.02] border-l-2 border-primary/50 flex flex-col gap-3">
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined text-primary text-xl opacity-80">verified_user</span>
                            <p class="text-[10px] font-black text-white/90 uppercase tracking-widest">Security</p>
                        </div>
                        <p class="text-[9px] font-medium text-white/40 uppercase leading-relaxed italic">For security, the account username and password will be automatically generated and securely delivered to the recipient's email address upon confirmation.</p>
                    </div>
                </div>

                <div class="p-10 pt-0 flex gap-4">
                    <button type="submit" class="flex-1 h-14 rounded-2xl text-white text-[13px] font-black uppercase italic tracking-[0.2em] transition-all hover:scale-[1.02] active:scale-95 shadow-xl shadow-primary/20 group" style="background:var(--primary)">
                        Create Account
                    </button>
                    <button type="button" onclick="toggleAddModal()" class="flex-1 h-14 bg-white/5 border border-white/10 text-gray-400 rounded-2xl font-black italic uppercase tracking-widest text-[13px] hover:bg-white/10 transition-all active:scale-95">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>

        function updateClock() {
            const now = new Date();
            const clock = document.getElementById('topClock');
            if (clock) clock.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }
        setInterval(updateClock, 1000);
        updateClock();

        function toggleAddModal() {
            const modal = document.getElementById('addStaffModal');
            modal.classList.toggle('active');
            if (modal.classList.contains('active')) {
                handleRoleChange(); // Initial check
            }
        }

        function handleRoleChange() {
            const roleSelect = document.querySelector('select[name="role"]');
            const rateField = document.getElementById('session_rate_field');
            if (!roleSelect || !rateField) return;

            const isCoach = roleSelect.value.toLowerCase().includes('coach') || roleSelect.value.toLowerCase().includes('trainer');
            if (isCoach) {
                rateField.style.display = 'block';
            } else {
                rateField.style.display = 'none';
            }
        }

        document.querySelector('select[name="role"]').addEventListener('change', handleRoleChange);

        // --- VALIDATION LOGIC ---
        const phoneInput = document.getElementById('contact_number');
        if (phoneInput) {
            phoneInput.addEventListener('input', function (e) {
                let x = e.target.value.replace(/\D/g, '').match(/(\d{0,4})(\d{0,3})(\d{0,4})/);
                e.target.value = !x[2] ? x[1] : x[1] + '-' + x[2] + (x[3] ? '-' + x[3] : '');
            });
        }

        // File name display listener
        document.querySelector('input[name="certification_file"]').addEventListener('change', function (e) {
            const fileName = e.target.files[0]?.name || 'Choose File...';
            this.parentElement.querySelector('.file-name-label').textContent = fileName;
        });

        // --- ELITE NOTIFICATION SYSTEM ---
        function showNotification(msg, type = 'success') {
            const existing = document.querySelector('.elite-notify');
            if (existing) existing.remove();

            const notify = document.createElement('div');
            notify.className = `elite-notify elite-notify-${type}`;
            const icon = type === 'success' ? 'check_circle' : 'error';
            const title = type === 'success' ? 'Success' : 'Error';

            notify.innerHTML = `
                <div class="elite-notify-icon">
                    <span class="material-symbols-outlined">${icon}</span>
                </div>
                <div class="elite-notify-content">
                    <div class="elite-notify-title">${title}</div>
                    <div class="elite-notify-msg">${msg}</div>
                </div>
            `;

            document.body.appendChild(notify);
            setTimeout(() => notify.classList.add('active'), 10);

            setTimeout(() => {
                notify.classList.remove('active');
                setTimeout(() => notify.remove(), 600);
            }, 5000);
        }

        document.querySelector('#addStaffModal form').addEventListener('submit', function (e) {
            e.preventDefault();

            const btn = e.target.querySelector('button[type="submit"]');
            const originalText = btn.innerHTML;
            const email = document.getElementById('email').value.toLowerCase();
            const phone = document.getElementById('contact_number').value;
            const bdate = document.getElementById('birth_date').value;
            const phoneRegex = /^09\d{2}-\d{3}-\d{4}$/;

            if (bdate) {
                const birthDate = new Date(bdate);
                const today = new Date();
                let age = today.getFullYear() - birthDate.getFullYear();
                const m = today.getMonth() - birthDate.getMonth();
                if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) {
                    age--;
                }

                if (birthDate > today) {
                    showNotification('Validation Error: Birthdate cannot be in the future.', 'error');
                    return;
                }
                if (age < 18) {
                    showNotification('Error: Staff must be at least 18 years old.', 'error');
                    return;
                }
            }

            if (!email.endsWith('@gmail.com')) {
                showNotification('Error: Only official @gmail.com addresses are allowed.', 'error');
                return;
            }

            if (!phoneRegex.test(phone)) {
                showNotification('Validation Error: Use the official 09XX-XXX-XXXX format.', 'error');
                return;
            }

            // Lock & Load
            btn.disabled = true;
            btn.innerHTML = `<span class="material-symbols-outlined animate-spin text-sm">sync</span> Adding...`;

            const formData = new FormData(this);
            fetch('staff.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        showNotification(data.message, 'success');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showNotification(data.message, 'error');
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                    }
                })
                .catch(err => {
                    showNotification('Error: Failed to send data.', 'error');
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                });
        });

        function openViewModal(btn) {
            let parsedArgs = btn;
            try { parsedArgs = JSON.parse(btn.getAttribute('data-staff')); } catch (e) { }
            const s = parsedArgs;
            const modal = document.getElementById('viewStaffModal');

            // Set Avatar
            const avatarDiv = document.getElementById('view_avatar');
            const fNameStr = s.first_name || '';
            const lNameStr = s.last_name || '';
            const initials = ((fNameStr[0] || '') + (lNameStr[0] || '')).toUpperCase() || '?';

            if (s.profile_picture) {
                avatarDiv.innerHTML = `<img src="../${s.profile_picture}" class="size-full object-cover shadow-inner group-hover:scale-110 transition-transform duration-500" onerror="this.outerHTML='<span class=\\'text-primary/40 font-black italic text-3xl tracking-tighter\\'>${initials}</span>'">`;
            } else {
                avatarDiv.innerHTML = `<span class="text-primary/40 font-black italic text-3xl tracking-tighter">${initials}</span>`;
            }

            document.getElementById('view_full_name').innerText = s.first_name + (s.middle_name ? ' ' + s.middle_name : '') + ' ' + s.last_name;
            document.getElementById('view_detailed_role').innerText = s.staff_role;
            document.getElementById('view_role_badge').innerText = (s.staff_role.toLowerCase().includes('coach') || s.staff_role.toLowerCase().includes('trainer')) ? 'COACH' : 'STAFF';
            document.getElementById('view_email').innerText = s.email;
            document.getElementById('view_contact').innerText = s.contact_number || 'N/A';
            document.getElementById('view_employment').innerText = s.employment_type;
            document.getElementById('view_sex').innerText = s.sex || 'N/A';

            // Status Badge Colors
            const statusBadge = document.getElementById('view_status_badge');
            statusBadge.innerText = s.status.toUpperCase();
            if (s.status.toLowerCase() === 'active') {
                statusBadge.className = 'px-2 py-0.5 rounded-md text-[8px] font-black uppercase tracking-[0.2em] italic border border-emerald-500/30 bg-emerald-500/10 text-emerald-500';
            } else {
                statusBadge.className = 'px-2 py-0.5 rounded-md text-[8px] font-black uppercase tracking-[0.2em] italic border border-rose-500/30 bg-rose-500/10 text-rose-500';
            }

            // Format Rates
            const srate = parseFloat(s.session_rate || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            document.getElementById('view_session_rate').innerText = '₱' + srate;

            const rateContainer = document.getElementById('view_session_rate_container');
            if (s.staff_role.toLowerCase().includes('coach') || s.staff_role.toLowerCase().includes('trainer')) {
                rateContainer.classList.remove('hidden');
                rateContainer.classList.add('flex');
            } else {
                rateContainer.classList.add('hidden');
                rateContainer.classList.remove('flex');
            }

            // Format Dates
            const options = { year: 'numeric', month: 'short', day: 'numeric' };
            if (s.birth_date) {
                document.getElementById('view_birthdate').innerText = new Date(s.birth_date).toLocaleDateString('en-US', options);
            } else {
                document.getElementById('view_birthdate').innerText = 'N/A';
            }

            if (s.created_at) {
                document.getElementById('view_hire_date').innerText = new Date(s.created_at).toLocaleDateString('en-US', options);
            } else {
                document.getElementById('view_hire_date').innerText = 'N/A';
            }

            modal.classList.add('active');
        }

        function hideViewModal() {
            document.getElementById('viewStaffModal').classList.remove('active');
        }

        // --- REQUESTS LOGIC ---
        let currentAppId = null;

        function openAppDetails(id) {
            const modal = document.getElementById('appDetailsModal');
            const container = document.getElementById('appDetailsContent');

            modal.classList.add('active');
            container.innerHTML = `
                <div class="flex flex-col items-center justify-center py-24 space-y-4">
                    <span class="material-symbols-outlined text-4xl text-primary animate-spin">sync</span>
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-500 italic">Loading staff details...</p>
                </div>
            `;

            fetch(`staff.php?ajax=1&application_id=${id}`)
                .then(res => res.text())
                .then(html => {
                    container.innerHTML = html;
                })
                .catch(() => {
                    container.innerHTML = `<div class='p-10 text-center text-rose-500 font-bold'>Connection Error: Failed to load profile.</div>`;
                });
        }

        function toggleDetailsModal() {
            document.getElementById('appDetailsModal').classList.remove('active');
        }

        function approveApplication(app) {
            currentAppId = app.coach_application_id;

            // Auto-Approve if rate is pre-defined
            if (app.session_rate && app.session_rate > 0) {
                showCustomConfirm(
                    'Confirm',
                    'Hiring',
                    `Are you sure you want to hire ${app.first_name} ${app.last_name} with their registered rate of ₱${app.session_rate}?`,
                    'verified',
                    'text-primary',
                    'bg-primary shadow-primary/20',
                    () => {
                        const formData = new FormData();
                        formData.append('action', 'approve_coach_app');
                        formData.append('application_id', app.coach_application_id);
                        formData.append('session_rate', app.session_rate);
                        formData.append('employment', (app.coach_type || 'PART-TIME').toUpperCase());

                        const btn = typeof event !== 'undefined' ? event.currentTarget : null;
                        const original = btn ? btn.innerHTML : '';
                        if (btn) btn.innerHTML = `<span class="material-symbols-outlined animate-spin text-sm">sync</span> Processing...`;

                        fetch('staff.php', { method: 'POST', body: formData })
                            .then(res => res.text())
                            .then(text => {
                                try {
                                    const data = JSON.parse(text);
                                    if (data.success) {
                                        showNotification(data.message, 'success');
                                        setTimeout(() => location.href = 'staff.php?tab=team', 1500);
                                    } else {
                                        showNotification(data.message, 'error');
                                        if (btn) btn.innerHTML = original;
                                    }
                                } catch(e) {
                                    console.error(text);
                                    showNotification('System Error: Refresh and try again.', 'error');
                                    if (btn) btn.innerHTML = original;
                                }
                            }).catch(err => {
                                showNotification('Connection Exception: Protocol failed.', 'error');
                                if (btn) btn.innerHTML = original;
                            });
                    }
                );
                return;
            }

            const modal = document.getElementById('approveAppModal');
            document.getElementById('approve_name').innerText = app.first_name + ' ' + app.last_name;
            document.getElementById('app_id_field').value = app.coach_application_id;

            // Auto close details modal if open
            toggleDetailsModal();
            modal.classList.add('active');
        }

        function toggleApproveModal() {
            document.getElementById('approveAppModal').classList.remove('active');
        }

        function rejectApplication(id) {
            showCustomConfirm(
                'Decline',
                'Application',
                'Are you sure you want to decline this coach application? This will permanently mark their application as declined.',
                'close',
                'text-rose-500',
                'bg-rose-500 shadow-rose-500/20',
                () => {
                    const formData = new FormData();
                    formData.append('action', 'reject_coach_app');
                    formData.append('application_id', id);
                    formData.append('remarks', 'Declined via staff list');

                    fetch('staff.php', {
                        method: 'POST',
                        body: formData
                    })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                showNotification(data.message, 'success');
                                setTimeout(() => location.reload(), 1000);
                            } else {
                                showNotification(data.message, 'error');
                            }
                        });
                }
            );
        }

        document.getElementById('approveAppForm').addEventListener('submit', function (e) {
            e.preventDefault();
            const btn = this.querySelector('button[type="submit"]');
            const originalText = btn.innerHTML;

            btn.disabled = true;
            btn.innerHTML = `<span class="material-symbols-outlined animate-spin text-sm">sync</span> Processing...`;

            const formData = new FormData(this);
            formData.append('action', 'approve_coach_app');

            fetch('staff.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        showNotification(data.message, 'success');
                        setTimeout(() => location.href = 'staff.php?tab=team', 1500);
                    } else {
                        showNotification(data.message, 'error');
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                    }
                })
                .catch(() => {
                    showNotification('Connection Exception: Protocol failed.', 'error');
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                });
        });

    </script>

    <!-- Application Details Modal -->
    <div id="appDetailsModal" class="modal-overlay">
        <div class="modal-content overflow-hidden max-w-[1000px] w-[95%]">
            <div
                class="px-10 py-6 border-b border-white/5 flex justify-between items-center bg-white/[0.02] sticky top-0 z-50 backdrop-blur-xl">
                <h4 class="font-black italic uppercase text-sm tracking-widest flex items-center gap-3"
                    style="color:var(--text-main)">
                    <span class="material-symbols-outlined" style="color:var(--primary)">person_search</span>
                    Applicant Details
                </h4>
                <button onclick="toggleDetailsModal()"
                    class="size-10 rounded-xl bg-white/5 hover:bg-rose-500/20 hover:text-rose-500 transition-all flex items-center justify-center">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </div>
            <div id="appDetailsContent" class="p-10 max-h-[85vh] overflow-y-auto no-scrollbar">
                <!-- AJAX Content -->
            </div>
        </div>
    </div>

    <!-- Approve Application Modal -->
    <div id="approveAppModal" class="modal-overlay">
        <div class="modal-content overflow-hidden max-w-[480px]">
            <div class="px-10 py-8 border-b border-white/5 flex justify-between items-center bg-white/[0.02]">
                <h4 class="font-black italic uppercase text-sm tracking-widest flex items-center gap-3"
                    style="color:var(--text-main)">
                    <span class="material-symbols-outlined" style="color:var(--primary)">verified</span> Confirm Hiring
                </h4>
                <button onclick="toggleApproveModal()"
                    class="size-10 rounded-xl bg-white/5 hover:bg-rose-500/20 hover:text-rose-500 transition-all flex items-center justify-center">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </div>
            <form id="approveAppForm" class="p-8 space-y-8 text-left">
                <input type="hidden" name="application_id" id="app_id_field">

                <div class="p-6 rounded-2xl bg-primary/5 border border-primary/20">
                    <p class="text-[9px] font-black uppercase tracking-widest text-primary/60 mb-1">New Coach</p>
                    <h5 id="approve_name" class="text-xl font-black italic uppercase tracking-tighter text-white">Coach
                        Name</h5>
                </div>

                <div class="space-y-6">
                    <div class="space-y-2.5">
                        <label class="label-muted ml-1">Assigned Session Rate (₱)</label>
                        <input type="number" name="session_rate" step="0.01" required placeholder="0.00"
                            class="filter-input w-full">
                    </div>
                    <div class="space-y-2.5">
                        <label class="label-muted ml-1">Employment Type</label>
                        <select name="employment" class="filter-input w-full italic">
                            <option value="FULL-TIME">Full-time</option>
                            <option value="PART-TIME" selected>Part-time</option>
                            <option value="ON-CALL">On-call / Freelance</option>
                        </select>
                    </div>
                </div>

                <div class="flex gap-4 pt-4">
                    <button type="submit"
                        class="flex-1 h-14 rounded-2xl bg-primary text-white text-[11px] font-black uppercase italic tracking-[0.2em] transition-all hover:scale-[1.02] active:scale-95 shadow-xl shadow-primary/20">
                        Confirm Hire
                    </button>
                    <button type="button" onclick="toggleApproveModal()"
                        class="flex-1 h-14 bg-white/5 border border-white/10 text-gray-400 rounded-2xl font-black italic uppercase tracking-widest text-[11px] hover:bg-white/10 transition-all">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>


    <!-- Reusable Confirm Modal -->
    <div id="customConfirmModal" class="modal-overlay">
        <div class="modal-content overflow-hidden max-w-[400px] text-center">
            <div class="p-10 relative z-10 w-full space-y-6 text-center">
                <div
                    class="absolute -top-24 -right-24 w-48 h-48 bg-primary/10 blur-[60px] rounded-full pointer-events-none">
                </div>

                <div id="confirmModalIcon"
                    class="size-20 bg-white/5 border border-white/10 rounded-full flex items-center justify-center mx-auto mb-6">
                    <span class="material-symbols-outlined text-4xl text-primary">help</span>
                </div>

                <h3 id="confirmModalTitle" class="text-2xl font-black text-white uppercase italic tracking-tight mb-2">
                    Confirm <span class="text-primary">Action</span>
                </h3>

                <p id="confirmModalText"
                    class="text-[12px] text-gray-400 font-bold uppercase tracking-widest leading-relaxed mb-8">
                    Are you sure?
                </p>

                <div class="flex gap-4">
                    <button type="button" id="confirmModalBtn"
                        class="flex-1 h-14 rounded-2xl bg-primary text-white text-[11px] font-black uppercase italic tracking-[0.2em] transition-all hover:scale-[1.02] active:scale-95 shadow-xl shadow-primary/20">
                        Confirm
                    </button>
                    <button type="button" onclick="closeCustomConfirm()"
                        class="flex-1 h-14 bg-white/5 border border-white/10 text-gray-400 rounded-2xl font-black italic uppercase tracking-widest text-[11px] hover:bg-white/10 transition-all">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        let customConfirmCallback = null;

        function showCustomConfirm(title1, title2, text, icon, iconColorClass, btnColorClass, callback) {
            document.getElementById('confirmModalTitle').innerHTML = `${title1} <span class="${iconColorClass}">${title2}</span>`;
            document.getElementById('confirmModalText').innerText = text;

            const iconDiv = document.getElementById('confirmModalIcon');
            iconDiv.className = `size-20 bg-white/5 rounded-full flex items-center justify-center border border-white/10 mx-auto mb-6`;
            iconDiv.innerHTML = `<span class="material-symbols-outlined text-4xl ${iconColorClass}">${icon}</span>`;

            const btn = document.getElementById('confirmModalBtn');
            btn.className = `flex-1 h-14 rounded-2xl text-white text-[11px] font-black uppercase italic tracking-[0.2em] transition-all hover:scale-[1.02] active:scale-95 shadow-xl ${btnColorClass}`;

            customConfirmCallback = callback;
            document.getElementById('customConfirmModal').classList.add('active');
        }

        function closeCustomConfirm() {
            document.getElementById('customConfirmModal').classList.remove('active');
            customConfirmCallback = null;
        }

        document.getElementById('confirmModalBtn').addEventListener('click', function () {
            if (customConfirmCallback) {
                const cb = customConfirmCallback;
                closeCustomConfirm();
                cb();
            }
        });
    </script>

    <!-- Restriction Modal (Sidebar-Aware) -->

    <div id="subModal">
        <div
            class="glass-card max-w-md w-full p-10 text-center animate-in zoom-in duration-300 relative shadow-[0_0_100px_rgba(140,43,238,0.15)] border-primary/20">
            <div
                class="size-20 rounded-3xl bg-primary/10 border border-primary/20 flex items-center justify-center mx-auto mb-8">
                <span class="material-symbols-outlined text-4xl text-primary">lock</span>
            </div>
            <h3 class="text-2xl font-black italic uppercase tracking-tighter text-white mb-3">Subscription Required</h3>
            <p
                class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 mb-10 leading-relaxed italic px-4">
                Access to staff management and records is restricted. Your status is <span
                    class="text-primary italic animate-pulse"><?= $sub_status ?></span>. Please activate a growth plan
                to unlock.
            </p>
            <div class="flex flex-col gap-4">
                <?php if (strpos($sub_status, 'Pending') !== false): ?>
                    <a href="tenant_dashboard.php"
                        class="h-14 rounded-2xl bg-primary text-white text-[11px] font-black uppercase italic tracking-[0.2em] flex items-center justify-center gap-3 hover:scale-[1.02] active:scale-95 transition-all shadow-xl shadow-primary/20 group">
                        <span
                            class="material-symbols-outlined text-xl group-hover:scale-110 transition-transform">grid_view</span>
                        Back to Dashboard
                    </a>
                <?php else: ?>
                    <a href="subscription_plan.php"
                        class="h-14 rounded-2xl bg-primary text-white text-[11px] font-black uppercase italic tracking-[0.2em] flex items-center justify-center gap-3 hover:scale-[1.02] active:scale-95 transition-all shadow-xl shadow-primary/20 group">
                        <span
                            class="material-symbols-outlined text-xl group-hover:scale-110 transition-transform">payments</span>
                        Select Growth Plan
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

</body>

</html>