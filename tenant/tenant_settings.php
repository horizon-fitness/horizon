<?php
session_start();
require_once '../db.php';

// Security Check: Only Tenants/Admins/Staff/Coaches
$role = strtolower($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || ($role !== 'tenant' && $role !== 'admin' && $role !== 'staff' && $role !== 'coach')) {
    header("Location: ../login.php");
    exit;
}

$gym_id = $_SESSION['gym_id'];
$user_id = $_SESSION['user_id'];
$active_page = "settings";

// --- Database Refresh (Rules_text exist in gyms) ---
try {
    $pdo->exec("ALTER TABLE gyms ADD COLUMN rules_text TEXT AFTER profile_picture");
} catch (Exception $e) { /* Column already exists */
}

// Fetch Gym Data (Consolidated)
$stmtGym = $pdo->prepare("
    SELECT *
    FROM gyms
    WHERE gym_id = ?
");
$stmtGym->execute([$gym_id]);
$gym = $stmtGym->fetch();

if (!$gym) {
    die("Gym profile not found. Please contact support.");
}

// --- Database Migration: Ensure portal_settings table exists ---
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS portal_settings (
            gym_id INT NOT NULL PRIMARY KEY,
            hero_label TEXT,
            hero_title TEXT,
            hero_subtitle TEXT,
            features_label TEXT,
            features_title TEXT,
            features_desc TEXT,
            philosophy_label TEXT,
            philosophy_title TEXT,
            philosophy_desc TEXT,
            plans_title TEXT,
            plans_subtitle TEXT,
            services_title TEXT,
            services_subtitle TEXT,
            footer_label TEXT,
            footer_desc TEXT,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
} catch (Exception $e) { /* Already exists */
}

// Fetch Branding Logic with Fallbacks
try {
    // Fetch Global Branding Defaults (user_id = 0)
    $stmtGlobal = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = 0");
    $stmtGlobal->execute();
    $global_configs = $stmtGlobal->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

    // Fetch Tenant Branding Settings (user_id = ?)
    $stmtTenant = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = ?");
    $stmtTenant->execute([$user_id]);
    $tenant_configs = $stmtTenant->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

    // Fetch Portal Customization (Normalized Table - 3NF)
    $stmtPortal = $pdo->prepare("SELECT * FROM portal_settings WHERE gym_id = ?");
    $stmtPortal->execute([$gym_id]);
    $portal_data = $stmtPortal->fetch(PDO::FETCH_ASSOC);

    // AUTO-SEED: If no data exists, create a default row for this gym
    if (!$portal_data) {
        $default_portal = [
            'gym_id' => $gym_id,
            'hero_label' => 'Open for Membership',
            'hero_title' => 'Elevate Your Fitness at ' . ($gym['gym_name'] ?? 'Corsano Fitness'),
            'hero_subtitle' => "Discover a premium workout experience powered by Horizon's elite technology. Join our community and transform your life with the support of our world-class trainers and cutting-edge facilities.",
            'features_label' => 'Experience the Difference',
            'features_title' => 'Premium Training. Elite Management.',
            'features_desc' => 'Access our elite workout tracking and world-class management platform to streamline your fitness journey.',
            'philosophy_label' => 'The Philosophy',
            'philosophy_title' => 'Modern technology meets unwavering dedication.',
            'philosophy_desc' => 'Experience fitness like never before with our cutting-edge multi-tenant facility management system.',
            'plans_title' => 'Membership Plans',
            'plans_subtitle' => 'Select a plan to start your journey towards a healthier, stronger you.',
            'services_title' => 'SERVICES & SESSION RATES',
            'services_subtitle' => 'SPECIALIZED SESSIONS AND PER-SESSION PRICING FOR ' . ($gym['gym_name'] ?? 'CORSANO FITNESS'),
            'footer_label' => 'Expand Your Horizon',
            'footer_desc' => 'Powered by Horizon Systems. Elevating fitness center management through cutting-edge technology.'
        ];

        try {
            $cols = implode(', ', array_keys($default_portal));
            $placeholders = implode(', ', array_fill(0, count($default_portal), '?'));
            $stmtSeed = $pdo->prepare("INSERT INTO portal_settings ($cols) VALUES ($placeholders)");
            $stmtSeed->execute(array_values($default_portal));
            $portal_data = $default_portal;
        } catch (Exception $e) { /* Fallback to runtime defaults */
        }
    }

    // Clean up nulls and convert to configs
    if ($portal_data) {
        foreach ($portal_data as $pk => $pv) {
            if ($pk !== 'gym_id' && $pk !== 'updated_at' && ($pv !== null)) {
                $tenant_configs['portal_' . $pk] = $pv;
            }
        }
    }
} catch (Exception $e) {
    if (!isset($global_configs))
        $global_configs = [];
    $tenant_configs = [];
}

// Initialize with Hard Defaults (Fallbacks)
// We prioritize individual Gym Identity (Name/Logo) to ensure every tenant is unique by default.
$configs = [
    'system_name' => $gym['gym_name'] ?? 'Horizon System',
    'system_logo' => $gym['profile_picture'] ?? '',
    'theme_color' => '#8c2bee',
    'secondary_color' => '#a1a1aa',
    'text_color' => '#d1d5db',
    'bg_color' => '#0a090d',
    'card_color' => '#141216',
    'auto_card_theme' => '1',
    'font_family' => 'Lexend'
];

// Merge with Priority: Hard Defaults -> Global Defaults (non-empty) -> Tenant Overrides (non-empty)
foreach ($global_configs as $k => $v) {
    if (!empty($v) || $v === '0')
        $configs[$k] = $v;
}
foreach ($tenant_configs as $k => $v) {
    if (!empty($v) || $v === '0')
        $configs[$k] = $v;
}


// Map common keys for convenience
$page['logo_path'] = $configs['system_logo'] ?? '';
$page['theme_color'] = $configs['theme_color'];
$page['bg_color'] = $configs['bg_color'];
$page['page_slug'] = $configs['page_slug'] ?? '';
$page['page_title'] = $configs['system_name'];
$page['text_color'] = $configs['text_color'];

$stmtSub = $pdo->prepare("
    SELECT ws.plan_name 
    FROM client_subscriptions cs 
    JOIN website_plans ws ON cs.website_plan_id = ws.website_plan_id 
    WHERE cs.gym_id = ? AND cs.subscription_status = 'Active' 
    LIMIT 1
");
$stmtSub->execute([$gym_id]);
$sub = $stmtSub->fetch();
$plan_name = $sub['plan_name'] ?? 'Standard Plan';

// --- SUBSCRIPTION CHECK FOR RESTRICTION ---
$stmtSubStatus = $pdo->prepare("SELECT subscription_status FROM client_subscriptions WHERE gym_id = ? ORDER BY created_at DESC LIMIT 1");
$stmtSubStatus->execute([$gym_id]);
$sub_status = $stmtSubStatus->fetchColumn() ?: 'None';
$is_sub_active = (strtolower($sub_status) === 'active');

// Determine if we show the restriction modal (Only for non-active AND non-pending)
$is_restricted = (!$is_sub_active);

// Hex to RGB helper
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

$error = null;
$success = $_SESSION['success_msg'] ?? null;
unset($_SESSION['success_msg']);

// --- AUTO-MIGRATION: MEMBERSHIP SORT ORDER & FEATURES ---
try {
    $pdo->exec("ALTER TABLE membership_plans ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER price");
} catch (Exception $e) { /* Column already exists */
}

try {
    $pdo->exec("ALTER TABLE membership_plans ADD COLUMN features TEXT AFTER description");
} catch (Exception $e) { /* Column already exists */
}

try {
    $pdo->exec("ALTER TABLE membership_plans ADD COLUMN billing_cycle_text VARCHAR(255) AFTER duration_value");
    $pdo->exec("ALTER TABLE membership_plans ADD COLUMN featured_badge_text VARCHAR(255) AFTER billing_cycle_text");
} catch (Exception $e) { /* Column already exists */
}

// --- AJAX HANDLER: SAVE MEMBERSHIP ORDER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_membership_order') {
    try {
        $order = $_POST['order'] ?? [];
        if (!empty($order)) {
            // Only update plans owned by the gym.
            $stmtSort = $pdo->prepare("UPDATE membership_plans SET sort_order = ? WHERE membership_plan_id = ? AND gym_id = ?");
            foreach ($order as $index => $id) {
                $stmtSort->execute([$index, (int) $id, $gym_id]);
            }
            echo json_encode(['success' => true]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// --- AJAX HANDLER: TOGGLE PLAN STATUS (Archive/Restore) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_plan_status') {
    try {
        $id = (int) $_POST['id'];
        $new_status = (int) $_POST['is_active'];

        $stmtStatus = $pdo->prepare("UPDATE membership_plans SET is_active = ?, updated_at = NOW() WHERE membership_plan_id = ? AND gym_id = ?");
        $stmtStatus->execute([$new_status, $id, $gym_id]);

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// --- AJAX HANDLER: CREATE MEMBERSHIP PLAN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_membership_plan') {
    try {
        $stmtMax = $pdo->prepare("SELECT MAX(sort_order) FROM membership_plans WHERE gym_id = ?");
        $stmtMax->execute([$gym_id]);
        $max_order = (int) $stmtMax->fetchColumn();

        $stmtIns = $pdo->prepare("INSERT INTO membership_plans (gym_id, plan_name, price, duration_value, billing_cycle_text, featured_badge_text, description, features, is_active, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), NOW())");
        $stmtIns->execute([
            $gym_id,
            trim($_POST['name']),
            (float) $_POST['price'],
            (int) $_POST['duration'],
            $_POST['billing_cycle'] ?? null,
            $_POST['badge'] ?? null,
            $_POST['description'] ?? '',
            $_POST['features'] ?? '',
            $max_order + 1
        ]);

        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// --- AJAX HANDLER: UPDATE MEMBERSHIP PLAN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_membership_plan') {
    try {
        $id = (int) $_POST['id'];
        $stmtUpdate = $pdo->prepare("UPDATE membership_plans SET plan_name = ?, price = ?, duration_value = ?, billing_cycle_text = ?, featured_badge_text = ?, description = ?, features = ?, updated_at = NOW() WHERE membership_plan_id = ? AND gym_id = ?");
        $stmtUpdate->execute([
            trim($_POST['name']),
            (float) $_POST['price'],
            (int) $_POST['duration'],
            $_POST['billing_cycle'] ?? null,
            $_POST['badge'] ?? null,
            $_POST['description'] ?? '',
            $_POST['features'] ?? '',
            $id,
            $gym_id
        ]);

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// --- AJAX HANDLER: RESET PORTAL CONTENT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_portal_content') {
    try {
        $stmtReset = $pdo->prepare("DELETE FROM portal_settings WHERE gym_id = ?");
        $stmtReset->execute([$gym_id]);
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// --- AJAX HANDLER: RESET BRANDING ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_branding') {
    try {
        $keys_to_reset = ['system_name', 'system_logo', 'theme_color', 'secondary_color', 'text_color', 'bg_color', 'card_color', 'auto_card_theme', 'font_family'];
        $placeholders = implode(',', array_fill(0, count($keys_to_reset), '?'));
        $stmtReset = $pdo->prepare("DELETE FROM system_settings WHERE user_id = ? AND setting_key IN ($placeholders)");
        $stmtReset->execute(array_merge([$user_id], $keys_to_reset));

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Default Branding Values
$bg_color = $page['bg_color'] ?? '#0a090d';
$theme_color = $page['theme_color'] ?? '#8c2bee';
$secondary_color = $page['secondary_color'] ?? '#a1a1aa';

// --- DATA FETCHING FOR MEMBERSHIP PLANS ---
$all_membership_plans = $pdo->prepare("SELECT * FROM membership_plans WHERE gym_id = ? ORDER BY is_active DESC, sort_order ASC, price ASC");
$all_membership_plans->execute([$gym_id]);
$all_membership_plans = $all_membership_plans->fetchAll();

$active_plans = [];
$archived_plans = [];
foreach ($all_membership_plans as $p) {
    if ($p['is_active'])
        $active_plans[] = $p;
    else
        $archived_plans[] = $p;
}

// --- DATA FETCHING FOR SERVICES ---
$services_stmt = $pdo->prepare("SELECT * FROM service_catalog WHERE gym_id = ? ORDER BY is_active DESC, service_name ASC");
$services_stmt->execute([$gym_id]);
$all_services = $services_stmt->fetchAll();

$active_services = [];
$archived_services = [];
foreach ($all_services as $s) {
    if ($s['is_active'])
        $active_services[] = $s;
    else
        $archived_services[] = $s;
}


// --- UNIFIED POST HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    // We allow settings updates even if not active, so users can brand their gym during onboarding.
    if (false) { // Relaxed restriction
        $error = "Action restricted. Your subscription is currently $sub_status.";
    } else {
        // Only update branding/portal/facility if no specific horizontal sub-action is provided
        $action = $_POST['action'] ?? '';

        if (empty($action)) {
            // 1. Branding Settings (Key-Value system_settings)
            $branding_keys = [
                'system_name' => $_POST['system_name'] ?? $configs['system_name'],
                'theme_color' => $_POST['theme_color'] ?? $configs['theme_color'],
                'secondary_color' => $_POST['secondary_color'] ?? $configs['secondary_color'],
                'text_color' => $_POST['text_color'] ?? $configs['text_color'],
                'bg_color' => $_POST['bg_color'] ?? $configs['bg_color'],
                'card_color' => $_POST['card_color'] ?? $configs['card_color'],
                'auto_card_theme' => $_POST['auto_card_theme'] ?? '0',
                'is_active' => '1',
                'page_slug' => $page['page_slug'] ?? strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $gym['gym_name']))
            ];

            // 2. Portal Content (Normalized portal_settings - 3NF)
            $portal_keys = [
                'hero_title' => $_POST['portal_hero_title'] ?? $configs['portal_hero_title'] ?? '',
                'hero_subtitle' => $_POST['portal_hero_subtitle'] ?? $configs['portal_hero_subtitle'] ?? '',
                'features_title' => $_POST['portal_features_title'] ?? $configs['portal_features_title'] ?? '',
                'features_desc' => $_POST['portal_features_desc'] ?? $configs['portal_features_desc'] ?? '',
                'philosophy_title' => $_POST['portal_philosophy_title'] ?? $configs['portal_philosophy_title'] ?? '',
                'philosophy_desc' => $_POST['portal_philosophy_desc'] ?? $configs['portal_philosophy_desc'] ?? '',
                'hero_label' => $_POST['portal_hero_label'] ?? $configs['portal_hero_label'] ?? '',
                'features_label' => $_POST['portal_features_label'] ?? $configs['portal_features_label'] ?? '',
                'philosophy_label' => $_POST['portal_philosophy_label'] ?? $configs['portal_philosophy_label'] ?? '',
                'plans_title' => $_POST['portal_plans_title'] ?? $configs['portal_plans_title'] ?? '',
                'plans_subtitle' => $_POST['portal_plans_subtitle'] ?? $configs['portal_plans_subtitle'] ?? '',
                'services_title' => $_POST['portal_services_title'] ?? $configs['portal_services_title'] ?? '',
                'services_subtitle' => $_POST['portal_services_subtitle'] ?? $configs['portal_services_subtitle'] ?? '',
                'footer_label' => $_POST['portal_footer_label'] ?? $configs['portal_footer_label'] ?? '',
                'footer_desc' => $_POST['portal_footer_desc'] ?? $configs['portal_footer_desc'] ?? ''
            ];

            // Facility Data
            $opening_time = !empty($_POST['opening_time']) ? $_POST['opening_time'] : ($gym['opening_time'] ?? '00:00:00');
            $closing_time = !empty($_POST['closing_time']) ? $_POST['closing_time'] : ($gym['closing_time'] ?? '23:59:59');
            $max_capacity = !empty($_POST['max_capacity']) ? (int) $_POST['max_capacity'] : (int) ($gym['max_capacity'] ?? 0);
            $rules_text = $_POST['rules_text'] ?? ($gym['rules_text'] ?? '');

            $has_lockers = isset($_POST['has_lockers']) ? 1 : ($gym['has_lockers'] ?? 0);
            $has_shower = isset($_POST['has_shower']) ? 1 : ($gym['has_shower'] ?? 0);
            $has_parking = isset($_POST['has_parking']) ? 1 : ($gym['has_parking'] ?? 0);
            $has_wifi = isset($_POST['has_wifi']) ? 1 : ($gym['has_wifi'] ?? 0);
        }

        $now = date('Y-m-d H:i:s');

        try {
            $pdo->beginTransaction();

            if (empty($action)) {
                // A. Update Branding Settings
                $stmtUpdateBranding = $pdo->prepare("INSERT INTO system_settings (user_id, setting_key, setting_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                foreach ($branding_keys as $key => $value) {
                    $stmtUpdateBranding->execute([$user_id, $key, $value]);
                }

                // B. Update Portal Content (Single Row per Gym)
                $portal_cols = implode(', ', array_keys($portal_keys));
                $portal_placeholders = implode(', ', array_fill(0, count($portal_keys), '?'));
                $portal_updates = implode(', ', array_map(fn($k) => "$k = VALUES($k)", array_keys($portal_keys)));

                $stmtUpdatePortal = $pdo->prepare("INSERT INTO portal_settings (gym_id, $portal_cols) VALUES (?, $portal_placeholders) ON DUPLICATE KEY UPDATE $portal_updates");
                $stmtUpdatePortal->execute(array_merge([$gym_id], array_values($portal_keys)));

                // Update local configs immediately
                $configs = array_merge($configs, $branding_keys);
                foreach ($portal_keys as $pk => $pv) {
                    $configs['portal_' . $pk] = $pv;
                }

                // 3. Update Gym Details (Directly in gyms table)
                $stmtUpdateGymDetails = $pdo->prepare("UPDATE gyms SET opening_time = ?, closing_time = ?, max_capacity = ?, has_lockers = ?, has_shower = ?, has_parking = ?, has_wifi = ?, rules_text = ?, updated_at = ? WHERE gym_id = ?");
                $stmtUpdateGymDetails->execute([$opening_time, $closing_time, $max_capacity, $has_lockers, $has_shower, $has_parking, $has_wifi, $rules_text, $now, $gym_id]);

                if (isset($_POST['membership_plans']) && is_array($_POST['membership_plans'])) {
                    $stmtUpdateMPlan = $pdo->prepare("UPDATE membership_plans SET plan_name = ?, price = ?, duration_value = ?, billing_cycle_text = ?, featured_badge_text = ?, description = ?, features = ?, updated_at = NOW() WHERE membership_plan_id = ? AND gym_id = ?");
                    foreach ($_POST['membership_plans'] as $id => $data) {
                        $stmtUpdateMPlan->execute([
                            $data['name'],
                            (float) ($data['price'] ?? 0),
                            (int) ($data['duration'] ?? 1),
                            $data['billing_cycle'] ?? null,
                            $data['badge'] ?? null,
                            $data['description'] ?? '',
                            $data['features'] ?? '',
                            $id,
                            $gym_id
                        ]);
                    }
                }

                if (isset($_POST['new_membership_plans']) && is_array($_POST['new_membership_plans'])) {
                    // Fetch current max sort order for this gym
                    $stmtMax = $pdo->prepare("SELECT MAX(sort_order) FROM membership_plans WHERE gym_id = ?");
                    $stmtMax->execute([$gym_id]);
                    $max_order = (int) $stmtMax->fetchColumn();

                    $stmtInsMPlan = $pdo->prepare("INSERT INTO membership_plans (gym_id, plan_name, price, duration_value, billing_cycle_text, featured_badge_text, description, features, is_active, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), NOW())");
                    foreach ($_POST['new_membership_plans'] as $data) {
                        if (empty($data['name']))
                            continue;
                        $max_order++;
                        $stmtInsMPlan->execute([
                            $gym_id,
                            $data['name'],
                            (float) ($data['price'] ?? 0),
                            (int) ($data['duration'] ?? 1),
                            $data['billing_cycle'] ?? null,
                            $data['badge'] ?? null,
                            $data['description'] ?? '',
                            $data['features'] ?? '',
                            $max_order
                        ]);
                    }
                }

                // 6. Handle Archive/Restore (Main Form Only)
                if (isset($_POST['archive_plan_id'])) {
                    $stmtArchive = $pdo->prepare("UPDATE membership_plans SET is_active = 0 WHERE membership_plan_id = ? AND gym_id = ?");
                    $stmtArchive->execute([$_POST['archive_plan_id'], $gym_id]);
                }
                if (isset($_POST['restore_plan_id'])) {
                    $stmtRestore = $pdo->prepare("UPDATE membership_plans SET is_active = 1 WHERE membership_plan_id = ? AND gym_id = ?");
                    $stmtRestore->execute([$_POST['restore_plan_id'], $gym_id]);
                }
            }

            // 7. Handle Service Catalog Actions
            if (isset($_POST['action'])) {
                if ($_POST['action'] === 'add_catalog_service') {
                    $name = $_POST['service_name'] ?? '';
                    $price = (float) ($_POST['price'] ?? 0);
                    $desc = $_POST['description'] ?? '';

                    if (!empty($name)) {
                        $stmtAddService = $pdo->prepare("INSERT INTO service_catalog (gym_id, service_name, price, description, is_active, created_at) VALUES (?, ?, ?, ?, 1, NOW())");
                        $stmtAddService->execute([$gym_id, $name, $price, $desc]);

                        // Handle AJAX response if needed
                        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                            $pdo->commit();
                            echo json_encode(['success' => true]);
                            exit;
                        }
                    }
                } elseif ($_POST['action'] === 'archive_catalog_service') {
                    $sid = (int) $_POST['service_id'];
                    $stmtArchiveService = $pdo->prepare("UPDATE service_catalog SET is_active = 0 WHERE catalog_service_id = ? AND gym_id = ?");
                    $stmtArchiveService->execute([$sid, $gym_id]);
                } elseif ($_POST['action'] === 'restore_catalog_service') {
                    $sid = (int) $_POST['service_id'];
                    $stmtRestoreService = $pdo->prepare("UPDATE service_catalog SET is_active = 1 WHERE catalog_service_id = ? AND gym_id = ?");
                    $stmtRestoreService->execute([$sid, $gym_id]);
                }
            }

            $pdo->commit();
            $_SESSION['success_msg'] = "All configurations saved and synchronized successfully!";

            // Redirect back to the same tab to prevent resubmission
            $active_tab = $_POST['active_tab'] ?? 'branding';
            header("Location: " . $_SERVER['PHP_SELF'] . "?tab=" . $active_tab);
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Update Error: " . $e->getMessage();
            $_SESSION['error_msg'] = $error;
        }
    }
}
?>

<!DOCTYPE html>
<html class="dark" lang="en">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>Gym Settings | Horizon</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap"
        rel="stylesheet" />
    <link
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200"
        rel="stylesheet" />
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "var(--primary)", "background-dark": "var(--background)", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)" } } }
        }
    </script>
    <style>
        :root {
            --primary:
                <?= $configs['theme_color'] ?? '#8c2bee' ?>
            ;
            --primary-rgb:
                <?= hexToRgb($configs['theme_color'] ?? '#8c2bee') ?>
            ;
            --background:
                <?= $configs['bg_color'] ?? '#0a090d' ?>
            ;
            --highlight:
                <?= $configs['secondary_color'] ?? '#a1a1aa' ?>
            ;
            --text-main:
                <?= $configs['text_color'] ?? '#d1d5db' ?>
            ;
            --card-blur: 20px;
            --card-bg:
                <?= ($configs['auto_card_theme'] ?? '1') === '1' ? 'rgba(' . hexToRgb($configs['theme_color'] ?? '#8c2bee') . ', 0.05)' : ($configs['card_color'] ?? '#14121a') ?>
            ;
        }

        body {
            font-family: '<?= $configs['font_family'] ?? 'Lexend' ?>', sans-serif;
            background-color: var(--background);
            color: var(--text-main);
            overflow: hidden;
        }

        .glass-card {
            background: var(--card-bg);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 24px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(var(--card-blur));
        }

        .glass-card:hover {
            border-color: rgba(var(--primary-rgb), 0.2);
        }

        label {
            color: var(--text-main);
            opacity: 0.6;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            font-style: italic;
        }

        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            left: 110px;
            background: rgba(10, 9, 13, 0.85);
            backdrop-filter: blur(12px);
            z-index: 200;
            align-items: center;
            justify-content: center;
            padding: 40px;
            transition: left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .side-nav:hover~.modal-overlay {
            left: 300px;
        }

        .modal-overlay.flex {
            display: flex !important;
        }

        .modal-content-scroll {
            max-height: 60vh;
            overflow-y: auto;
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        .modal-content-scroll::-webkit-scrollbar {
            display: none;
        }

        .plan-card-elite {
            background: rgba(var(--primary-rgb), 0.03);
            border: 1px solid rgba(255, 255, 255, 0.05);
            position: relative;
            transition: all 0.3s ease;
        }

        .plan-card-elite:hover {
            border-color: var(--primary);
            box-shadow: 0 0 30px rgba(var(--primary-rgb), 0.1);
        }

        .shimmer {
            position: relative;
            overflow: hidden;
        }

        .shimmer::after {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg,
                    transparent 20%,
                    rgba(255, 255, 255, 0.05) 50%,
                    transparent 80%);
            animation: shimmer 4s infinite linear;
        }

        @keyframes shimmer {
            0% {
                transform: translate(-30%, -30%) rotate(0deg);
            }

            100% {
                transform: translate(30%, 30%) rotate(0deg);
            }
        }

        .sortable-ghost {
            opacity: 0.3;
            transform: scale(0.95);
        }

        .sortable-drag {
            cursor: grabbing;
        }

        /* MATCH IMAGE LOOK */
        .plan-header-bar {
            background: var(--card-bg);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 20px;
            padding: 24px 32px;
            backdrop-filter: blur(10px);
        }

        .elite-red-card {
            background: var(--card-bg);
            border-radius: 32px;
            border: 1px solid rgba(255, 255, 255, 0.05);
            transition: all 0.3s ease;
            backdrop-filter: blur(var(--card-blur));
        }

        .elite-red-card:hover {
            border-color: var(--primary);
            box-shadow: 0 0 40px rgba(var(--primary-rgb), 0.05);
        }

        .input-dark-elite {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 13px;
            font-weight: 700;
            color: white;
            width: 100%;
            transition: all 0.3s ease;
            outline: none;
        }

        .input-dark-elite:focus {
            border-color: var(--primary);
            background: rgba(var(--primary-rgb), 0.05);
            box-shadow: 0 0 20px rgba(var(--primary-rgb), 0.1);
        }

        /* Plan Card View/Edit State Toggle */
        .plan-edit-state {
            display: none !important;
        }

        .elite-red-card.is-editing .plan-edit-state {
            display: grid !important;
        }

        .elite-red-card.is-editing .plan-preview-state {
            display: none !important;
        }

        .view-box {
            background: var(--card-bg);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 12px;
            padding: 12px 16px;
            color: white;
            font-size: 13px;
            font-weight: 700;
            min-height: 48px;
            display: flex;
            align-items: center;
            transition: all 0.3s ease;
        }

        .view-box-long {
            align-items: flex-start;
            line-height: 1.6;
            min-height: 100px;
        }

        .label-elite {
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.2em;
            color: #64748b;
            margin-bottom: 8px;
            display: block;
        }

        .tab-btn-match {
            padding: 8px 16px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            transition: all 0.3s ease;
        }

        .tab-btn-match.active {
            background: var(--primary);
            color: white;
        }

        .tab-btn-match.inactive {
            color: #666;
        }

        /* Sidebar */
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

        /* Nav items — dashboard parity */
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





        .input-dark {
            background: var(--card-bg);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            color: white;
            padding: 12px 16px;
            font-size: 12px;
            width: 100%;
            outline: none;
            transition: all 0.2s;
            backdrop-filter: blur(10px);
        }

        .input-dark:focus {
            border-color: var(--primary);
            background: rgba(var(--primary-rgb), 0.05);
            box-shadow: 0 0 20px rgba(var(--primary-rgb), 0.1);
        }

        .input-dark option {
            background-color: #0d0c12;
            color: white;
        }

        /* Invisible Scroll System */
        *::-webkit-scrollbar {
            display: none !important;
        }

        * {
            -ms-overflow-style: none !important;
            scrollbar-width: none !important;
        }

        #portalFrame {
            width: 1600px;
            height: 2000px;
            border: none;
            position: absolute;
            top: 0;
            left: 0;
            transform-origin: top left;
        }

        /* Tabs Styling */
        .tab-btn {
            position: relative;
            padding: 12px 24px;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            color: #94a3b8;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 14px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .tab-btn:hover {
            background: rgba(255, 255, 255, 0.05);
            color: white;
        }

        .tab-btn.active {
            background: rgba(var(--primary-rgb), 0.1);
            border-color: var(--primary);
            color: white;
            box-shadow: 0 10px 30px -10px rgba(var(--primary-rgb), 0.3);
        }

        .tab-panel {
            display: none;
            animation: tabFadeIn 0.5s ease;
        }

        .tab-panel.active {
            display: block;
        }

        @keyframes tabFadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* RESTRICTION BLUR */
        .blur-overlay {
            position: relative;
        }

        .blur-overlay-content {
            filter: blur(12px);
            pointer-events: none;
            user-select: none;
        }

        /* Sidebar-Aware Sub Modal */
        #subModal {
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            left: 110px;
            z-index: 200;
            display: none !important;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(12px);
            transition: left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        #subModal.active {
            display: flex !important;
        }

        .side-nav:hover~#subModal {
            left: 300px;
        }
    </style>
    <script>
        function showSubWarning() { document.getElementById('subModal').classList.add('active'); }
        function closeSubModal() { document.getElementById('subModal').classList.remove('active'); }

        window.addEventListener('DOMContentLoaded', () => {
            <?php if ($is_restricted): ?>
                showSubWarning();
            <?php endif; ?>

            <?php if ($success): ?>
                showEliteToast('<?= addslashes($success) ?>', 'check_circle', 'bg-emerald-500');
            <?php endif; ?>
            <?php if ($error): ?>
                showEliteToast('<?= addslashes($error) ?>', 'error', 'bg-rose-500');
            <?php endif; ?>
        });

        function showEliteToast(msg, icon, colorClass) {
            const area = document.getElementById('notificationArea');
            if (!area) return;

            const iconMap = {
                'check_circle': 'check_circle',
                'error': 'warning',
                'reorder': 'swap_vert',
                'info': 'info'
            };

            const resolvedIcon = iconMap[icon] || icon;
            const id = 'notif-' + Date.now();

            const alert = document.createElement('div');
            alert.id = id;
            alert.className = `px-8 h-[46px] rounded-xl flex items-center justify-between transition-all duration-700 select-none animate-in slide-in-from-top-4 ${colorClass}/10`;

            // Text color logic
            let textColor = 'text-white';
            if (colorClass.includes('emerald')) textColor = 'text-emerald-400';
            else if (colorClass.includes('rose')) textColor = 'text-rose-400';
            else if (colorClass.includes('primary')) textColor = 'text-primary';

            alert.innerHTML = `
                <div class="flex items-center gap-3">
                    <span class="material-symbols-outlined text-sm ${textColor.replace('400', '500')}">${resolvedIcon}</span>
                    <span class="${textColor} text-[12px] font-bold">${msg}</span>
                </div>
                <button type="button" onclick="const a = document.getElementById('${id}'); if(a){ a.style.opacity='0'; a.style.transform='translateY(-10px)'; setTimeout(()=>a.remove(), 500); }"
                    class="${textColor.replace('400', '500')}/50 hover:${textColor.replace('400', '500')} transition-colors p-2 shrink-0 outline-none">
                    <span class="material-symbols-outlined text-base">close</span>
                </button>
            `;

            area.appendChild(alert);

            setTimeout(() => {
                const a = document.getElementById(id);
                if (a) {
                    a.style.opacity = '0';
                    a.style.transform = 'translateY(-10px)';
                    setTimeout(() => a.remove(), 500);
                }
            }, 10000); // 10 seconds auto-hide
        }
    </script>
</head>

<body class="flex h-screen overflow-hidden">

    <?php require_once '../includes/tenant_sidebar.php'; ?>


    <main class="main-content flex-1 p-8 overflow-y-auto no-scrollbar <?= $is_restricted ? 'blur-overlay' : '' ?>">
        <div class="<?= $is_restricted ? 'blur-overlay-content' : '' ?>">
            <!-- Header synchronized with my_users.php -->
            <header class="mb-6 flex justify-between items-end px-2">
                <div>
                    <h2 class="text-3xl font-black uppercase tracking-tighter text-white italic">Tenant <span
                            class="text-primary italic">Settings</span></h2>
                    <p class="text-gray-500 text-[10px] font-black uppercase tracking-widest mt-1 italic">
                        <?= htmlspecialchars($gym['gym_name']) ?> Branding and Operations Configuration
                    </p>
                </div>

                <div class="flex items-center gap-8">

                    <div class="text-right">
                        <p id="topClock" class="font-black italic text-2xl leading-none tracking-tighter"
                            style="color: var(--text-main);">00:00:00 AM</p>
                        <p id="topDate"
                            class="text-[--primary] text-[10px] font-bold uppercase tracking-widest mt-2 px-1 opacity-80 italic">
                            <?= date('l, M d, Y') ?>
                        </p>
                    </div>
                </div>
            </header>
            <div id="notificationArea" class="space-y-4 mb-6">
                <?php if ($error): ?>
                    <div id="errorAlert"
                        class="px-8 h-[46px] bg-rose-500/10 text-rose-400 text-[12px] font-bold rounded-xl flex items-center justify-between transition-all duration-700 select-none animate-in slide-in-from-top-4">
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined text-sm text-rose-500">warning</span>
                            <span><?= $error ?></span>
                        </div>
                        <button type="button"
                            onclick="const a = document.getElementById('errorAlert'); if(a){ a.style.opacity='0'; a.style.transform='translateY(-10px)'; setTimeout(()=>a.remove(), 500); }"
                            class="text-rose-500/50 hover:text-rose-500 transition-colors p-2 shrink-0 outline-none">
                            <span class="material-symbols-outlined text-base">close</span>
                        </button>
                    </div>
                    <script>
                        setTimeout(() => {
                            const a = document.getElementById('errorAlert');
                            if (a) { a.style.opacity = '0'; a.style.transform = 'translateY(-10px)'; setTimeout(() => a.remove(), 500); }
                        }, 10000);
                    </script>
                <?php endif; ?>
            </div>

            <!-- TOP: LIVE PREVIEW TERMINAL (ALWAYS VISIBLE) -->
            <div class="space-y-6 mb-8">
                <div class="flex items-center justify-between px-4">
                    <div class="flex items-center gap-3">
                        <div class="size-8 rounded-lg bg-primary/20 flex items-center justify-center">
                            <span class="material-symbols-outlined text-primary text-lg font-bold">visibility</span>
                        </div>
                        <h4 class="text-[12px] font-black italic uppercase tracking-widest text-white">Live Gym Portal
                            Preview</h4>
                    </div>
                    <a href="../portal.php?gym=<?= htmlspecialchars($page['page_slug'] ?? '') ?>" target="_blank"
                        class="h-10 px-6 rounded-xl bg-white/5 border border-white/10 flex items-center gap-3 hover:bg-white/10 transition-all text-gray-400 hover:text-white group mt-[-4px]">
                        <span
                            class="material-symbols-outlined text-[18px] group-hover:scale-110 transition-transform">open_in_new</span>
                        <span class="text-[10px] font-black uppercase tracking-widest">View Website</span>
                    </a>
                </div>

                <div class="glass-card p-1.5 overflow-hidden shadow-2xl relative max-w-[1300px] mx-auto">
                    <div
                        class="absolute top-8 left-8 flex items-center gap-1.5 z-10 p-2 rounded-full bg-black/40 backdrop-blur-md border border-white/10">
                        <div class="size-2.5 rounded-full bg-red-400"></div>
                        <div class="size-2.5 rounded-full bg-amber-400"></div>
                        <div class="size-2.5 rounded-full bg-green-400"></div>
                    </div>

                    <div id="portalContainer"
                        class="w-full relative border border-white/[0.03] rounded-3xl overflow-y-auto bg-black origin-top no-scrollbar">
                        <!-- High-Fidelity Desktop Mockup (Always Use portal.php?preview=1) -->
                        <iframe id="portalFrame" src="../portal.php?gym=<?= $page['page_slug'] ?? '' ?>&preview=1"
                            class="absolute top-0 left-0 w-[1600px] h-[2000px] border-none origin-top-left"
                            onload="updateMockup()"></iframe>
                    </div>
                </div>
            </div>

            <div class="<?= !$is_sub_active ? 'blur-overlay' : '' ?>">
                <?php if (!$is_sub_active): ?>
                    <!-- Premium Modal shown via JS on load -->
                <?php endif; ?>

                <form id="gymSettingsForm" method="POST" enctype="multipart/form-data"
                    onsubmit="return handleFormSubmit(this, event)"
                    class="space-y-6 pb-20 max-w-[1700px] mx-auto <?= !$is_sub_active ? 'blur-overlay-content' : '' ?>">
                    <input type="hidden" name="active_tab" id="activeTabInput" value="branding">
                    <input type="hidden" name="save_settings" value="1">

                    <!-- TAB NAVIGATION + SAVE BUTTON -->
                    <div
                        class="sticky top-0 z-[100] bg-background-dark/80 backdrop-blur-xl border-b border-white/5 py-3 flex items-center justify-between gap-6 px-2">
                        <div class="flex items-center gap-3">
                            <button type="button" onclick="switchTab('branding')" class="tab-btn active"
                                data-tab="branding">
                                <span class="material-symbols-outlined text-base">brush</span>
                                Branding & Appearance
                            </button>
                            <button type="button" onclick="switchTab('operations')" class="tab-btn"
                                data-tab="operations">
                                <span class="material-symbols-outlined text-base">schedule</span>
                                Operational Rules
                            </button>
                            <button type="button" onclick="switchTab('portal')" class="tab-btn" data-tab="portal">
                                <span class="material-symbols-outlined text-base">edit_document</span>
                                Portal Content
                            </button>
                            <button type="button" onclick="switchTab('membership')" class="tab-btn"
                                data-tab="membership">
                                <span class="material-symbols-outlined text-base">card_membership</span>
                                Membership Plans
                            </button>
                            <button type="button" onclick="switchTab('services')" class="tab-btn" data-tab="services">
                                <span class="material-symbols-outlined text-base">exercise</span>
                                Services Offered
                            </button>

                        </div>

                        <button type="button" onclick="confirmSaveSettings()"
                            class="h-11 px-8 rounded-xl bg-primary text-white text-[10px] font-black uppercase italic tracking-[0.2em] shadow-lg shadow-primary/20 hover:scale-[1.05] active:scale-95 transition-all flex items-center gap-3">
                            <span class="material-symbols-outlined text-base">save</span>
                            Save Changes
                        </button>
                    </div>

                    <div id="tabBranding" class="tab-panel active mt-4">
                        <!-- System Appearance Panel (Sync with Superadmin) -->
                        <div class="glass-card p-8 max-w-5xl mx-auto">
                            <div class="flex items-center justify-between mb-8 text-primary">
                                <div class="flex items-center gap-4">
                                    <div class="size-10 rounded-xl bg-primary/10 flex items-center justify-center">
                                        <span class="material-symbols-outlined text-primary">brush</span>
                                    </div>
                                    <div>
                                        <h3 class="text-sm font-black italic uppercase tracking-widest text-primary">1.
                                            System Appearance</h3>
                                        <p
                                            class="text-[10px] text-[--text-main] opacity-70 font-bold uppercase tracking-tight line-clamp-1">
                                            Brand identity & glassmorphism</p>
                                    </div>
                                </div>
                                <button type="button" onclick="resetBranding()"
                                    class="h-9 px-6 rounded-lg bg-rose-500/10 border border-rose-500/20 text-rose-400 hover:bg-rose-500 hover:text-white flex items-center gap-2 text-[9px] font-black uppercase tracking-widest transition-all active:scale-95">
                                    <span class="material-symbols-outlined text-base">restart_alt</span>
                                    Reset to Defaults
                                </button>
                            </div>

                            <div class="space-y-8">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div class="flex flex-col gap-1.5">
                                        <label class="ml-1">Gym Name</label>
                                        <input type="text" name="system_name" oninput="updateMockup()"
                                            value="<?= htmlspecialchars($configs['system_name'] ?? $gym['gym_name']) ?>"
                                            class="input-dark">
                                    </div>
                                </div>

                                <div class="grid grid-cols-2 gap-6">
                                    <div class="flex flex-col gap-1.5">
                                        <label class="ml-1">Main Color</label>
                                        <div
                                            class="flex items-center gap-4 bg-white/5 p-2 rounded-xl border border-white/5">
                                            <input type="color" name="theme_color" oninput="updateMockup()"
                                                value="<?= htmlspecialchars($configs['theme_color'] ?? '#8c2bee') ?>"
                                                class="size-10 rounded-lg cursor-pointer bg-transparent border-none">
                                            <span id="colorHex"
                                                class="text-[10px] font-black uppercase text-gray-400"><?= $configs['theme_color'] ?? '#8c2bee' ?></span>
                                        </div>
                                    </div>
                                    <div class="flex flex-col gap-1.5">
                                        <label class="ml-1">Icon Color</label>
                                        <div
                                            class="flex items-center gap-4 bg-white/5 p-2 rounded-xl border border-white/5">
                                            <input type="color" name="secondary_color" oninput="updateMockup()"
                                                value="<?= htmlspecialchars($configs['secondary_color'] ?? '#a1a1aa') ?>"
                                                class="size-10 rounded-lg cursor-pointer bg-transparent border-none">
                                            <span id="secondaryHex"
                                                class="text-[10px] font-black uppercase text-gray-400"><?= $configs['secondary_color'] ?? '#a1a1aa' ?></span>
                                        </div>
                                    </div>
                                    <div class="flex flex-col gap-1.5">
                                        <label class="ml-1">Text Color</label>
                                        <div
                                            class="flex items-center gap-4 bg-white/5 p-2 rounded-xl border border-white/5">
                                            <input type="color" name="text_color" oninput="updateMockup()"
                                                value="<?= htmlspecialchars($configs['text_color'] ?? '#d1d5db') ?>"
                                                class="size-10 rounded-lg cursor-pointer bg-transparent border-none">
                                            <span id="textHex"
                                                class="text-[10px] font-black uppercase text-gray-400"><?= $configs['text_color'] ?? '#d1d5db' ?></span>
                                        </div>
                                    </div>
                                    <div class="flex flex-col gap-1.5">
                                        <label class="ml-1">Background Color</label>
                                        <div
                                            class="flex items-center gap-4 bg-white/5 p-2 rounded-xl border border-white/5">
                                            <input type="color" name="bg_color" oninput="updateMockup()"
                                                value="<?= htmlspecialchars($configs['bg_color'] ?? '#0a090d') ?>"
                                                class="size-10 rounded-lg cursor-pointer bg-transparent border-none">
                                            <span id="bgHex"
                                                class="text-[10px] font-black uppercase text-gray-400"><?= $configs['bg_color'] ?? '#0a090d' ?></span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Card Appearance Section -->
                                <div class="mt-6 pt-6 border-t border-white/5 space-y-4">
                                    <div class="flex items-center justify-between">
                                        <h4 class="text-[9px] font-black uppercase tracking-[0.2em] text-primary">Card
                                            Appearance</h4>
                                        <label class="flex items-center gap-3 cursor-pointer group">
                                            <span
                                                class="text-[8px] font-bold uppercase tracking-widest text-[#d1d5db] opacity-70 group-hover:text-primary transition-colors">Sync
                                                Theme</span>
                                            <div class="relative inline-flex items-center">
                                                <input type="hidden" name="auto_card_theme" value="0">
                                                <input type="checkbox" name="auto_card_theme" value="1"
                                                    onchange="updateMockup()" <?= ($configs['auto_card_theme'] ?? '1') === '1' ? 'checked' : '' ?> class="sr-only peer">
                                                <div
                                                    class="w-10 h-5 bg-white/5 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white/20 after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-primary/30 peer-checked:after:bg-primary transition-all border border-white/5">
                                                </div>
                                            </div>
                                        </label>
                                    </div>

                                    <div class="grid grid-cols-2 gap-6">
                                        <div class="flex flex-col gap-1.5">
                                            <label class="ml-1">Surface Color</label>
                                            <div
                                                class="flex items-center gap-4 bg-white/5 p-2 rounded-xl border border-white/5">
                                                <input type="color" name="card_color" oninput="updateMockup()"
                                                    value="<?= htmlspecialchars($configs['card_color'] ?? '#141216') ?>"
                                                    class="size-10 rounded-lg cursor-pointer bg-transparent border-none">
                                                <span id="cardHex"
                                                    class="text-[10px] font-black uppercase text-gray-400"><?= $configs['card_color'] ?? '#141216' ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="tabOperations" class="tab-panel mt-4">
                        <!-- Operations Panel -->
                        <div class="glass-card p-8 max-w-5xl mx-auto">
                            <h4
                                class="text-[12px] font-black italic uppercase tracking-widest text-primary mb-6 flex items-center gap-4">
                                <span class="material-symbols-outlined text-xl">schedule</span> 2. Operational Rules
                            </h4>
                            <div class="space-y-8">
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                                    <div class="space-y-2">
                                        <label
                                            class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Opening
                                            Time</label>
                                        <input type="time" name="opening_time" oninput="updateMockup()"
                                            value="<?= htmlspecialchars($gym['opening_time'] ?? '') ?>"
                                            class="input-dark">
                                    </div>
                                    <div class="space-y-2">
                                        <label
                                            class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Closing
                                            Time</label>
                                        <input type="time" name="closing_time" oninput="updateMockup()"
                                            value="<?= htmlspecialchars($gym['closing_time'] ?? '') ?>"
                                            class="input-dark">
                                    </div>
                                    <div class="space-y-2">
                                        <label
                                            class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Max
                                            Member Capacity</label>
                                        <input type="number" name="max_capacity" oninput="updateMockup()"
                                            value="<?= htmlspecialchars($gym['max_capacity'] ?? '') ?>"
                                            class="input-dark" placeholder="Enter capacity (e.g. 50)">
                                    </div>
                                </div>

                                <div class="pt-8 border-t border-white/5">
                                    <label
                                        class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 mb-6 block italic">Amenities
                                        & Services</label>
                                    <div class="grid grid-cols-2 gap-4">
                                        <label
                                            class="flex items-center gap-4 p-4 rounded-2xl bg-[var(--card-bg)] border border-white/5 cursor-pointer hover:border-primary/40 transition-all group">
                                            <input type="checkbox" name="has_lockers" onchange="updateMockup()"
                                                <?= ($gym['has_lockers'] ?? 0) ? 'checked' : '' ?>
                                                class="size-5 rounded-md border-white/10 bg-white/5 text-primary focus:ring-primary focus:ring-offset-0 transition-all">
                                            <span
                                                class="text-[11px] font-black uppercase tracking-widest text-gray-400 group-hover:text-white">Lockers</span>
                                        </label>
                                        <label
                                            class="flex items-center gap-4 p-4 rounded-2xl bg-[var(--card-bg)] border border-white/5 cursor-pointer hover:border-primary/40 transition-all group">
                                            <input type="checkbox" name="has_shower" onchange="updateMockup()"
                                                <?= ($gym['has_shower'] ?? 0) ? 'checked' : '' ?>
                                                class="size-5 rounded-md border-white/10 bg-white/5 text-primary focus:ring-primary focus:ring-offset-0 transition-all">
                                            <span
                                                class="text-[11px] font-black uppercase tracking-widest text-gray-400 group-hover:text-white">Showers</span>
                                        </label>
                                        <label
                                            class="flex items-center gap-4 p-4 rounded-2xl bg-[var(--card-bg)] border border-white/5 cursor-pointer hover:border-primary/40 transition-all group">
                                            <input type="checkbox" name="has_parking" onchange="updateMockup()"
                                                <?= ($gym['has_parking'] ?? 0) ? 'checked' : '' ?>
                                                class="size-5 rounded-md border-white/10 bg-white/5 text-primary focus:ring-primary focus:ring-offset-0 transition-all">
                                            <span
                                                class="text-[11px] font-black uppercase tracking-widest text-gray-400 group-hover:text-white">Parking</span>
                                        </label>
                                        <label
                                            class="flex items-center gap-4 p-4 rounded-2xl bg-[var(--card-bg)] border border-white/5 cursor-pointer hover:border-primary/40 transition-all group">
                                            <input type="checkbox" name="has_wifi" onchange="updateMockup()"
                                                <?= ($gym['has_wifi'] ?? 0) ? 'checked' : '' ?>
                                                class="size-5 rounded-md border-white/10 bg-white/5 text-primary focus:ring-primary focus:ring-offset-0 transition-all">
                                            <span
                                                class="text-[11px] font-black uppercase tracking-widest text-gray-400 group-hover:text-white">Wi-Fi</span>
                                        </label>
                                    </div>
                                </div>

                                <div class="pt-8 border-t border-white/5 space-y-2">
                                    <label
                                        class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Gym
                                        House Rules / TOS</label>
                                    <textarea name="rules_text" rows="5" class="input-dark"
                                        placeholder="Enter terms of service..."><?= htmlspecialchars($gym['rules_text'] ?? '') ?></textarea>
                                </div>

                            </div>
                        </div>
                    </div>

                        <div id="tabPortal" class="tab-panel mt-4">
                            <!-- Section 3: Portal Content Customization -->
                            <div class="glass-card p-8 max-w-5xl mx-auto">
                                <div class="flex items-center justify-between mb-8 text-primary">
                                    <div class="flex items-center gap-4">
                                        <div class="size-10 rounded-xl bg-primary/10 flex items-center justify-center">
                                            <span class="material-symbols-outlined text-primary">edit_document</span>
                                        </div>
                                        <div>
                                            <h3 class="text-sm font-black italic uppercase tracking-widest text-primary">3. Portal Content Customization</h3>
                                            <p class="text-[10px] text-[--text-main] opacity-70 font-bold uppercase tracking-tight line-clamp-1">Personalize your public website's messaging</p>
                                        </div>
                                    </div>
                                    <button type="button" onclick="resetPortalContent()"
                                        class="h-9 px-6 rounded-lg bg-rose-500/10 border border-rose-500/20 text-rose-400 hover:bg-rose-500 hover:text-white flex items-center gap-2 text-[9px] font-black uppercase tracking-widest transition-all active:scale-95">
                                        <span class="material-symbols-outlined text-base">restart_alt</span>
                                        Reset to Defaults
                                    </button>
                                </div>

                            <div class="grid grid-cols-1 gap-10">
                                <!-- Hero Section -->
                                <div class="space-y-6">
                                    <h5
                                        class="text-[9px] font-black uppercase tracking-[0.2em] text-gray-500 italic border-b border-white/5 pb-2">
                                        Hero Section</h5>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <div class="space-y-2">
                                            <label
                                                class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Hero
                                                Label</label>
                                            <textarea name="portal_hero_label" oninput="updateMockup()" rows="3"
                                                class="input-dark"
                                                placeholder="Open for Membership"><?= htmlspecialchars($configs['portal_hero_label'] ?? '') ?></textarea>
                                        </div>
                                        <div class="space-y-2">
                                            <label
                                                class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Hero
                                                Title</label>
                                            <textarea name="portal_hero_title" oninput="updateMockup()" rows="3"
                                                class="input-dark"
                                                placeholder="Elevate Your Fitness at <?= htmlspecialchars($gym['gym_name']) ?>"><?= htmlspecialchars($configs['portal_hero_title'] ?? '') ?></textarea>
                                        </div>
                                        <div class="space-y-2">
                                            <label
                                                class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Hero
                                                Subtitle</label>
                                            <textarea name="portal_hero_subtitle" oninput="updateMockup()" rows="3"
                                                class="input-dark"
                                                placeholder="Discover a premium workout experience powered by Horizon's elite technology..."><?= htmlspecialchars($configs['portal_hero_subtitle'] ?? '') ?></textarea>
                                        </div>
                                    </div>
                                </div>

                                <!-- Features Section -->
                                <div class="space-y-6">
                                    <h5
                                        class="text-[9px] font-black uppercase tracking-[0.2em] text-gray-500 italic border-b border-white/5 pb-2">
                                        Features Highlight</h5>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <div class="space-y-2">
                                            <label
                                                class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Features
                                                Label</label>
                                            <textarea name="portal_features_label" oninput="updateMockup()" rows="3"
                                                class="input-dark"
                                                placeholder="Experience the Difference"><?= htmlspecialchars($configs['portal_features_label'] ?? '') ?></textarea>
                                        </div>
                                        <div class="space-y-2">
                                            <label
                                                class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Features
                                                Title</label>
                                            <textarea name="portal_features_title" oninput="updateMockup()" rows="3"
                                                class="input-dark"
                                                placeholder="Premium Training. Elite Management."><?= htmlspecialchars($configs['portal_features_title'] ?? '') ?></textarea>
                                        </div>
                                        <div class="space-y-2">
                                            <label
                                                class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Features
                                                Description</label>
                                            <textarea name="portal_features_desc" oninput="updateMockup()" rows="3"
                                                class="input-dark"
                                                placeholder="Access our elite workout tracking and world-class management platform..."><?= htmlspecialchars($configs['portal_features_desc'] ?? '') ?></textarea>
                                        </div>
                                    </div>
                                </div>

                                <!-- Philosophy Section -->
                                <div class="space-y-6">
                                    <h5
                                        class="text-[9px] font-black uppercase tracking-[0.2em] text-gray-500 italic border-b border-white/5 pb-2">
                                        Facility Philosophy</h5>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <div class="space-y-2">
                                            <label
                                                class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Philosophy
                                                Label</label>
                                            <textarea name="portal_philosophy_label" oninput="updateMockup()" rows="3"
                                                class="input-dark"
                                                placeholder="The Philosophy"><?= htmlspecialchars($configs['portal_philosophy_label'] ?? '') ?></textarea>
                                        </div>
                                        <div class="space-y-2">
                                            <label
                                                class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Philosophy
                                                Title</label>
                                            <textarea name="portal_philosophy_title" oninput="updateMockup()" rows="3"
                                                class="input-dark"
                                                placeholder="Modern technology meets unwavering dedication."><?= htmlspecialchars($configs['portal_philosophy_title'] ?? '') ?></textarea>
                                        </div>
                                        <div class="space-y-2">
                                            <label
                                                class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Philosophy
                                                Description</label>
                                            <textarea name="portal_philosophy_desc" oninput="updateMockup()" rows="3"
                                                class="input-dark"
                                                placeholder="Experience fitness like never before with our cutting-edge multi-tenant facility."><?= htmlspecialchars($configs['portal_philosophy_desc'] ?? '') ?></textarea>
                                        </div>
                                    </div>
                                </div>

                                <!-- Membership Plans Section -->
                                <div class="space-y-6">
                                    <h5
                                        class="text-[9px] font-black uppercase tracking-[0.2em] text-gray-500 italic border-b border-white/5 pb-2">
                                        Membership Plans Content</h5>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <div class="space-y-2">
                                            <label
                                                class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Plans
                                                Section Title</label>
                                            <textarea name="portal_plans_title" oninput="updateMockup()" rows="3"
                                                class="input-dark"
                                                placeholder="Membership Plans"><?= htmlspecialchars($configs['portal_plans_title'] ?? '') ?></textarea>
                                        </div>
                                        <div class="space-y-2">
                                            <label
                                                class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Plans
                                                Section Subtitle</label>
                                            <textarea name="portal_plans_subtitle" oninput="updateMockup()" rows="3"
                                                class="input-dark"
                                                placeholder="Select a plan to start your journey..."><?= htmlspecialchars($configs['portal_plans_subtitle'] ?? '') ?></textarea>
                                        </div>
                                    </div>
                                </div>

                                <!-- Services & Rates Section -->
                                <div class="space-y-6">
                                    <h5
                                        class="text-[9px] font-black uppercase tracking-[0.2em] text-gray-500 italic border-b border-white/5 pb-2">
                                        Services & Session Rates Content</h5>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <div class="space-y-2">
                                            <label
                                                class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Services
                                                Section Title</label>
                                            <textarea name="portal_services_title" oninput="updateMockup()" rows="3"
                                                class="input-dark"
                                                placeholder="SERVICES & SESSION RATES"><?= htmlspecialchars($configs['portal_services_title'] ?? '') ?></textarea>
                                        </div>
                                        <div class="space-y-2">
                                            <label
                                                class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Services
                                                Section Subtitle</label>
                                            <textarea name="portal_services_subtitle" oninput="updateMockup()" rows="3"
                                                class="input-dark"
                                                placeholder="SPECIALIZED SESSIONS AND PER-SESSION PRICING"><?= htmlspecialchars($configs['portal_services_subtitle'] ?? '') ?></textarea>
                                        </div>
                                    </div>
                                </div>

                                <!-- Footer Mission Section -->
                                <div class="space-y-6">
                                    <h5
                                        class="text-[9px] font-black uppercase tracking-[0.2em] text-gray-500 italic border-b border-white/5 pb-2">
                                        Footer Mission & Description</h5>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <div class="space-y-2">
                                            <label
                                                class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Footer
                                                Headline</label>
                                            <textarea name="portal_footer_label" oninput="updateMockup()" rows="3"
                                                class="input-dark"
                                                placeholder="Expand Your Horizon"><?= htmlspecialchars($configs['portal_footer_label'] ?? '') ?></textarea>
                                        </div>
                                        <div class="space-y-2">
                                            <label
                                                class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1 italic">Mission
                                                Statement / Description</label>
                                            <textarea name="portal_footer_desc" oninput="updateMockup()" rows="3"
                                                class="input-dark"
                                                placeholder="Powered by Horizon Systems. Elevating fitness center management..."><?= htmlspecialchars($configs['portal_footer_desc'] ?? '') ?></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="tabMembership" class="tab-panel mt-4">
                        <!-- MATCH HEADER BAR -->
                        <div class="plan-header-bar flex flex-col md:flex-row items-center justify-between gap-6 mb-12">
                            <div>
                                <h4 class="text-xl font-black italic uppercase tracking-tighter text-white mb-1">System
                                    Subscription Plans</h4>
                                <p class="text-[9px] text-gray-500 font-bold uppercase tracking-[0.2em]">Manage what
                                    plans are available for new gyms</p>
                            </div>

                            <div class="flex items-center gap-6">
                                <button type="button" onclick="addNewMembershipPlanCard()"
                                    class="flex items-center gap-2 text-[10px] font-black uppercase tracking-widest text-primary hover:opacity-80 transition-all">
                                    <span class="material-symbols-outlined text-lg">add_circle</span> Add New Plan
                                </button>

                                <div class="bg-black/40 p-1 rounded-xl flex items-center border border-white/5">
                                    <button type="button" onclick="togglePlanView('active')" id="activeTabBtn"
                                        class="tab-btn-match active px-6 h-9">Active</button>
                                    <button type="button" onclick="togglePlanView('archived')" id="archivedTabBtn"
                                        class="tab-btn-match inactive px-6 h-9">Archived
                                        (<?= count($archived_plans) ?>)</button>
                                </div>
                            </div>
                        </div>


                        <!-- ACTIVE MATCH CARDS GRID -->
                        <div id="activePlansContainer" class="grid grid-cols-1 lg:grid-cols-2 2xl:grid-cols-3 gap-10">
                            <div id="activePlansEmptyState"
                                class="col-span-full py-20 flex flex-col items-center justify-center opacity-40 <?= !empty($active_plans) ? 'hidden' : '' ?>">
                                <span class="material-symbols-outlined text-4xl mb-4">info</span>
                                <p class="text-[10px] font-black uppercase tracking-[0.2em]">No active plans found.</p>
                                <p class="text-[8px] text-gray-600 mt-2 uppercase tracking-widest italic">Create a new
                                    tier to get started</p>
                            </div>

                            <?php if (!empty($active_plans)): ?>
                                <?php foreach ($active_plans as $plan): ?>
                                    <div class="elite-red-card p-10 group relative overflow-hidden transition-all duration-300"
                                        id="plan-card-<?= $plan['membership_plan_id'] ?>"
                                        data-id="<?= $plan['membership_plan_id'] ?>"
                                        data-name="<?= htmlspecialchars($plan['plan_name']) ?>"
                                        data-price="<?= (int) $plan['price'] ?>"
                                        data-duration="<?= (int) ($plan['duration_value'] ?? 1) ?>"
                                        data-billing="<?= htmlspecialchars($plan['billing_cycle_text'] ?? 'Default') ?>"
                                        data-description="<?= htmlspecialchars($plan['description'] ?? '') ?>">

                                        <!-- Drag Handle -->
                                        <div class="absolute top-2 right-1/2 translate-x-1/2 opacity-0 group-hover:opacity-30 hover:!opacity-100 transition-all cursor-grab active:cursor-grabbing drag-handle py-1 px-4 rounded-full bg-white/5 active:bg-primary/20"
                                            title="Drag to reorder">
                                            <span class="material-symbols-outlined text-sm">drag_handle</span>
                                        </div>

                                        <div class="flex items-center justify-between mb-8">
                                            <div class="flex items-center gap-4">
                                                <div class="size-10 rounded-xl bg-primary/10 flex items-center justify-center">
                                                    <span
                                                        class="material-symbols-outlined text-primary text-2xl">workspace_premium</span>
                                                </div>
                                                <h5
                                                    class="text-sm font-black italic uppercase tracking-widest text-primary plan-title-preview">
                                                    <?= htmlspecialchars($plan['plan_name'] ?: 'Unnamed Plan') ?>
                                                </h5>
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <button type="button"
                                                    onclick="togglePlanEdit(<?= $plan['membership_plan_id'] ?>)"
                                                    class="size-8 rounded-lg bg-primary/10 text-primary flex items-center justify-center hover:bg-primary hover:text-white transition-all active:scale-95 edit-btn"
                                                    title="Edit Plan">
                                                    <span class="material-symbols-outlined text-lg">edit</span>
                                                </button>
                                                <button type="button"
                                                    onclick="confirmArchiveMPlan(<?= $plan['membership_plan_id'] ?>)"
                                                    class="size-8 rounded-lg bg-primary/10 text-gray-500 flex items-center justify-center hover:bg-rose-500 hover:text-white transition-all active:scale-95"
                                                    title="Archive Plan">
                                                    <span class="material-symbols-outlined text-lg">archive</span>
                                                </button>
                                            </div>
                                        </div>

                                        <!-- PREVIEW STATE -->
                                        <div
                                            class="plan-preview-state grid grid-cols-2 gap-x-6 gap-y-6 animate-in fade-in duration-500">
                                            <div class="col-span-2 flex flex-col gap-1.5">
                                                <label
                                                    class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Plan
                                                    Name</label>
                                                <div class="view-box text-white"><?= htmlspecialchars($plan['plan_name']) ?>
                                                </div>
                                            </div>

                                            <div class="col-span-1 flex flex-col gap-1.5">
                                                <label
                                                    class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Price
                                                    (₱)</label>
                                                <div class="view-box font-bold">₱<?= number_format($plan['price'], 2) ?></div>
                                            </div>

                                            <div class="col-span-1 flex flex-col gap-1.5">
                                                <label
                                                    class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Duration</label>
                                                <div class="view-box font-bold"><?= $plan['duration_value'] ?> Months</div>
                                            </div>

                                            <div class="col-span-1 flex flex-col gap-1.5">
                                                <label
                                                    class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Billing
                                                    Cycle</label>
                                                <div class="view-box text-gray-400 capitalize">
                                                    <?= htmlspecialchars($plan['billing_cycle_text'] ?: 'Default') ?>
                                                </div>
                                            </div>

                                            <div class="col-span-1 flex flex-col gap-1.5">
                                                <label
                                                    class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Badge</label>
                                                <div class="view-box italic opacity-60">
                                                    <?= htmlspecialchars($plan['featured_badge_text'] ?: 'None') ?>
                                                </div>
                                            </div>

                                            <div class="col-span-2 flex flex-col gap-1.5">
                                                <label
                                                    class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Features</label>
                                                <div class="view-box view-box-long text-gray-400 font-medium">
                                                    <?= nl2br(htmlspecialchars($plan['features'] ?: $plan['description'] ?: 'No features listed')) ?>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- EDIT STATE -->
                                        <div
                                            class="plan-edit-state grid grid-cols-2 gap-x-6 gap-y-6 animate-in fade-in duration-300">
                                            <div class="col-span-2 flex flex-col gap-1.5">
                                                <label
                                                    class="text-[9px] font-black uppercase text-primary tracking-widest ml-1">Plan
                                                    Name</label>
                                                <input type="text"
                                                    name="membership_plans[<?= $plan['membership_plan_id'] ?>][name]"
                                                    value="<?= htmlspecialchars($plan['plan_name']) ?>"
                                                    class="input-dark-elite italic tracking-tight uppercase"
                                                    placeholder="e.g. Bronze Access" required
                                                    oninput="this.closest('.elite-red-card').querySelector('.plan-title-preview').innerText = this.value">
                                            </div>

                                            <div class="col-span-1 flex flex-col gap-1.5">
                                                <label
                                                    class="text-[9px] font-black uppercase text-primary tracking-widest ml-1">Price
                                                    (₱)</label>
                                                <input type="number"
                                                    name="membership_plans[<?= $plan['membership_plan_id'] ?>][price]"
                                                    value="<?= (int) $plan['price'] ?>" class="input-dark-elite font-bold"
                                                    placeholder="1000" required>
                                            </div>

                                            <div class="col-span-1 flex flex-col gap-1.5">
                                                <label
                                                    class="text-[9px] font-black uppercase text-primary tracking-widest ml-1">Duration
                                                    (Months)</label>
                                                <input type="number"
                                                    name="membership_plans[<?= $plan['membership_plan_id'] ?>][duration]"
                                                    value="<?= $plan['duration_value'] ?>" class="input-dark-elite font-bold"
                                                    placeholder="1" required>
                                            </div>

                                            <div class="col-span-2 flex flex-col gap-1.5">
                                                <label
                                                    class="text-[9px] font-black uppercase text-primary tracking-widest ml-1">Billing
                                                    Cycle Text</label>
                                                <input type="text"
                                                    name="membership_plans[<?= $plan['membership_plan_id'] ?>][billing_cycle]"
                                                    value="<?= htmlspecialchars($plan['billing_cycle_text'] ?? '') ?>"
                                                    class="input-dark-elite font-bold" placeholder="e.g. per month">
                                            </div>

                                            <div class="col-span-2 flex flex-col gap-1.5">
                                                <label
                                                    class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Featured
                                                    Badge Text</label>
                                                <input type="text"
                                                    name="membership_plans[<?= $plan['membership_plan_id'] ?>][badge]"
                                                    value="<?= htmlspecialchars($plan['featured_badge_text'] ?? '') ?>"
                                                    class="input-dark-elite font-bold italic opacity-60"
                                                    placeholder="e.g. Popular">
                                            </div>

                                            <div class="col-span-2 flex flex-col gap-1.5">
                                                <label
                                                    class="text-[9px] font-black uppercase text-primary tracking-widest ml-1">Features
                                                    (Comma-separated)</label>
                                                <textarea name="membership_plans[<?= $plan['membership_plan_id'] ?>][features]"
                                                    rows="3"
                                                    class="input-dark-elite !bg-white/[0.01] resize-none leading-relaxed font-bold"
                                                    placeholder="List the features..."><?= htmlspecialchars($plan['features'] ?: $plan['description'] ?: '') ?></textarea>
                                            </div>

                                            <div class="col-span-2 pt-4 border-t border-white/5">
                                                <p
                                                    class="text-[8px] font-black uppercase tracking-widest text-primary opacity-60 italic">
                                                    Manual save required via 'Save Changes'</p>
                                            </div>
                                        </div>

                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <!-- NEW PLANS GO HERE -->
                            <div id="newPlansContainer" class="contents"></div>
                        </div>

                        <!-- ARCHIVED VIEW (Modern Table UI) -->
                        <div id="archivedPlansContainer" class="hidden space-y-6">
                            <!-- Filter Bar Guide (Inspired by Superadmin) -->
                            <div
                                class="glass-card px-8 py-6 border-b border-white/5 flex flex-col md:flex-row items-center gap-4 bg-white/[0.01]">
                                <div class="relative flex-1">
                                    <span
                                        class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-gray-500 text-sm">search</span>
                                    <input type="text" id="archivedSearch" oninput="filterArchivedPlans()"
                                        placeholder="Search archived plans..."
                                        class="input-dark !h-[44px] !pl-12 !text-[11px] font-medium w-full">
                                </div>

                                <div class="w-full lg:w-fit flex items-center gap-2">
                                    <div class="relative w-full lg:w-64">
                                        <select id="archivedSortDate" onchange="filterArchivedPlans()"
                                            class="input-dark !h-[44px] !pr-10 !pl-4 !text-[10px] font-bold appearance-none cursor-pointer hover:border-primary/50 transition-all !bg-white/[0.02]">
                                            <option value="newest">Newest First</option>
                                            <option value="oldest">Oldest First</option>
                                        </select>
                                        <span
                                            class="material-symbols-outlined absolute right-3 top-1/2 -translate-y-1/2 text-gray-600 text-sm pointer-events-none">expand_more</span>
                                    </div>

                                    <div class="relative w-full lg:w-64">
                                        <select id="archivedSortPrice" onchange="filterArchivedPlans()"
                                            class="input-dark !h-[44px] !pr-10 !pl-4 !text-[10px] font-bold appearance-none cursor-pointer hover:border-primary/50 transition-all !bg-white/[0.02]">
                                            <option value="default">Any Price</option>
                                            <option value="low">Lowest Price</option>
                                            <option value="high">Highest Price</option>
                                        </select>
                                        <span
                                            class="material-symbols-outlined absolute right-3 top-1/2 -translate-y-1/2 text-gray-600 text-sm pointer-events-none">expand_more</span>
                                    </div>

                                    <button type="button" onclick="resetArchivedFilters()"
                                        class="size-[44px] rounded-xl bg-white/5 flex items-center justify-center text-gray-500 hover:text-white hover:bg-white/10 transition-all border border-white/5 group active:scale-95"
                                        title="Reset Filters">
                                        <span
                                            class="material-symbols-outlined text-lg group-hover:rotate-180 transition-transform duration-500">restart_alt</span>
                                    </button>
                                </div>
                            </div>

                            <div class="glass-card overflow-hidden border-white/5">
                                <div class="overflow-x-auto no-scrollbar">
                                    <table class="w-full text-left border-collapse">
                                        <thead>
                                            <tr class="border-b border-white/5 bg-white/[0.02]">
                                                <th
                                                    class="px-8 py-5 text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">
                                                    Plan Name</th>
                                                <th
                                                    class="px-8 py-5 text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 text-center">
                                                    Price (₱)</th>
                                                <th
                                                    class="px-8 py-5 text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 text-center">
                                                    Duration</th>
                                                <th
                                                    class="px-8 py-5 text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 text-center">
                                                    Billing Cycle</th>
                                                <th
                                                    class="px-8 py-5 text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 text-center">
                                                    Action</th>
                                            </tr>
                                        </thead>
                                        <tbody id="archivedTableBody">
                                            <?php if (empty($archived_plans)): ?>
                                                <tr class="no-data-row animate-in fade-in duration-500">
                                                    <td colspan="5" class="px-8 py-20 text-center">
                                                        <div class="flex flex-col items-center justify-center opacity-40">
                                                            <span
                                                                class="material-symbols-outlined text-4xl mb-4">history</span>
                                                            <p class="text-[10px] font-black uppercase tracking-[0.2em]">No
                                                                archived plans found.</p>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($archived_plans as $p): ?>
                                                    <tr class="archived-row group border-b border-white/5 hover:bg-white/[0.02] transition-colors"
                                                        data-id="<?= $p['membership_plan_id'] ?>"
                                                        data-name="<?= strtolower(htmlspecialchars($p['plan_name'])) ?>"
                                                        data-raw-name="<?= htmlspecialchars($p['plan_name']) ?>"
                                                        data-price="<?= (int) $p['price'] ?>"
                                                        data-duration="<?= (int) ($p['duration_value'] ?? 1) ?>"
                                                        data-billing="<?= htmlspecialchars($p['billing_cycle_text'] ?? 'Default') ?>"
                                                        data-date="<?= strtotime($p['created_at']) ?>">
                                                        <td class="px-8 py-5">
                                                            <div>
                                                                <p
                                                                    class="text-xs font-black italic uppercase tracking-widest text-gray-400">
                                                                    <?= htmlspecialchars($p['plan_name']) ?>
                                                                </p>
                                                            </div>
                                                        </td>
                                                        <td class="px-8 py-5 text-center">
                                                            <span
                                                                class="text-xs font-bold text-gray-400">₱<?= number_format($p['price']) ?></span>
                                                        </td>
                                                        <td class="px-8 py-5 text-center">
                                                            <span
                                                                class="px-3 py-1 rounded-lg bg-white/5 text-[9px] font-black text-gray-500 uppercase tracking-widest">
                                                                <?= (int) ($p['duration_value'] ?? 1) ?> Months
                                                            </span>
                                                        </td>
                                                        <td class="px-8 py-5 text-center">
                                                            <span
                                                                class="text-[10px] font-medium text-gray-500"><?= htmlspecialchars($p['billing_cycle_text'] ?? 'Default') ?></span>
                                                        </td>
                                                        <td class="px-8 py-5 text-center">
                                                            <button type="button"
                                                                onclick="confirmRestoreMPlan(<?= $p['membership_plan_id'] ?>)"
                                                                class="h-9 px-4 rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-emerald-500 hover:bg-emerald-500 hover:text-white flex items-center justify-center gap-2 text-[9px] font-black uppercase tracking-widest mx-auto transition-all active:scale-95">
                                                                <span
                                                                    class="material-symbols-outlined text-base">settings_backup_restore</span>
                                                                Restore
                                                            </button>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div id="tabServices" class="tab-panel mt-4">
                        <!-- SERVICE HEADER BAR (MATCHING REFERENCE) -->
                        <div
                            class="service-header-bar flex flex-col md:flex-row items-center justify-between gap-6 mb-12">
                            <div>
                                <h4 class="text-xl font-black italic uppercase tracking-tighter text-white mb-1">SERVICE
                                    CATALOG</h4>
                                <p class="text-[9px] text-gray-500 font-bold uppercase tracking-[0.2em]">MANAGE YOUR
                                    AVAILABLE GYM SERVICES AND OFFERINGS</p>
                            </div>

                            <div class="flex items-center gap-6">
                                <button type="button" onclick="openAddServiceModal()"
                                    class="flex items-center gap-2 text-[10px] font-black uppercase tracking-widest text-primary hover:opacity-80 transition-all group">
                                    <span
                                        class="material-symbols-outlined text-lg group-hover:scale-110 transition-transform">add_circle</span>
                                    ADD NEW SERVICE
                                </button>

                                <div class="bg-black/40 p-1 rounded-xl flex items-center border border-white/5">
                                    <button type="button" onclick="toggleServiceView('active')" id="activeServiceTabBtn"
                                        class="tab-btn-match active px-6 h-9">ACTIVE
                                        (<?= count($active_services) ?>)</button>
                                    <button type="button" onclick="toggleServiceView('archived')"
                                        id="archivedServiceTabBtn" class="tab-btn-match inactive px-6 h-9">ARCHIVED
                                        (<?= count($archived_services) ?>)</button>
                                </div>
                            </div>
                        </div>

                        <!-- ADVANCED FILTERS (ALIGNED TO REF) -->
                        <div class="glass-card px-8 py-4 flex flex-row items-center gap-4 bg-white/[0.01] mb-10">
                            <div class="relative flex-1">
                                <span
                                    class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-gray-500 text-sm">search</span>
                                <input type="text" id="servicesSearch" oninput="filterServices()"
                                    placeholder="Search archived plans..."
                                    class="input-dark !h-[44px] !pl-11 !text-[11px] font-medium w-full !bg-white/[0.03] !border-white/10 !rounded-xl">
                            </div>

                            <div class="flex items-center gap-3">
                                <select id="servicesSortDate" onchange="filterServices()"
                                    class="input-dark !h-[44px] !px-6 !text-[10px] font-bold uppercase tracking-widest cursor-pointer !bg-white/[0.03] !border-white/10 !rounded-xl min-w-[160px]">
                                    <option value="newest">Newest First</option>
                                    <option value="oldest">Oldest First</option>
                                </select>

                                <select id="servicesSortPrice" onchange="filterServices()"
                                    class="input-dark !h-[44px] !px-6 !text-[10px] font-bold uppercase tracking-widest cursor-pointer !bg-white/[0.03] !border-white/10 !rounded-xl min-w-[160px]">
                                    <option value="default">Any Price</option>
                                    <option value="low">Price: Low to High</option>
                                    <option value="high">Price: High to Low</option>
                                </select>

                                <button type="button" onclick="resetServiceFilters()"
                                    class="size-[44px] rounded-xl bg-white/[0.03] border border-white/10 text-gray-500 hover:text-white hover:bg-white/10 transition-all flex items-center justify-center group"
                                    title="Reset Filters">
                                    <span
                                        class="material-symbols-outlined text-xl group-hover:rotate-180 transition-transform duration-500">refresh</span>
                                </button>
                            </div>
                        </div>

                        <!-- ACTIVE SERVICES GRID (CARD VIEW) -->
                        <div id="activeServicesContainer"
                            class="grid grid-cols-1 lg:grid-cols-2 2xl:grid-cols-3 gap-10">
                            <?php if (empty($active_services)): ?>
                                <div class="col-span-full py-20 flex flex-col items-center justify-center opacity-40">
                                    <span class="material-symbols-outlined text-4xl mb-4">info</span>
                                    <p class="text-[10px] font-black uppercase tracking-[0.2em]">No active services found.
                                    </p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($active_services as $s): ?>
                                    <div class="service-card elite-red-card p-10 group relative transition-all duration-300"
                                        data-id="<?= $s['catalog_service_id'] ?>"
                                        data-name="<?= strtolower(htmlspecialchars($s['service_name'])) ?>"
                                        data-price="<?= (float) $s['price'] ?>" data-date="<?= strtotime($s['created_at']) ?>">

                                        <div class="flex items-center justify-between mb-8">
                                            <div class="flex items-center gap-4">
                                                <div
                                                    class="size-12 rounded-2xl bg-primary/10 flex items-center justify-center text-primary">
                                                    <span
                                                        class="material-symbols-outlined text-2xl font-bold">shopping_basket</span>
                                                </div>
                                                <div>
                                                    <h5
                                                        class="text-sm font-black italic uppercase tracking-widest text-primary">
                                                        <?= htmlspecialchars($s['service_name']) ?></h5>
                                                </div>
                                            </div>
                                            <button type="button"
                                                onclick="confirmArchiveService(<?= $s['catalog_service_id'] ?>)"
                                                class="size-10 rounded-xl bg-white/5 text-gray-500 flex items-center justify-center hover:bg-rose-500 hover:text-white transition-all active:scale-95 border border-white/5"
                                                title="Archive Service">
                                                <span class="material-symbols-outlined text-lg">archive</span>
                                            </button>
                                        </div>

                                        <div class="space-y-6">
                                            <div class="flex flex-col gap-1.5">
                                                <label class="label-elite">Price Tag</label>
                                                <div class="view-box font-black text-white text-base justify-between">
                                                    <span>₱<?= number_format($s['price'], 2) ?></span>
                                                    <span
                                                        class="px-2 py-0.5 rounded bg-primary/20 text-primary text-[8px] uppercase font-black tracking-widest">Premium</span>
                                                </div>
                                            </div>
                                            <div class="flex flex-col gap-1.5">
                                                <label class="label-elite">Description</label>
                                                <div class="view-box view-box-long text-gray-400 italic">
                                                    <?= !empty($s['description']) ? htmlspecialchars($s['description']) : 'No official description provided for this catalog offering.' ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <!-- ARCHIVED SERVICES TABLE (STAYS AS TABLE TO MATCH ARCHIVED PLANS) -->
                        <div id="archivedServicesContainer" class="glass-card overflow-hidden border-white/5 hidden">
                            <div class="overflow-x-auto no-scrollbar">
                                <table class="w-full text-left border-collapse">
                                    <thead>
                                        <tr class="border-b border-white/5 bg-white/[0.02]">
                                            <th
                                                class="px-8 py-5 text-[10px] font-black uppercase tracking-[0.2em] text-gray-500">
                                                Service Name</th>
                                            <th
                                                class="px-8 py-5 text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 text-center">
                                                Price</th>
                                            <th
                                                class="px-8 py-5 text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 text-center">
                                                Status</th>
                                            <th
                                                class="px-8 py-5 text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 text-center">
                                                Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="archivedServicesTableBody">
                                        <?php if (empty($archived_services)): ?>
                                            <tr class="no-data-row">
                                                <td colspan="5" class="px-8 py-20 text-center">
                                                    <div class="flex flex-col items-center justify-center opacity-40">
                                                        <span class="material-symbols-outlined text-4xl mb-4">history</span>
                                                        <p class="text-[10px] font-black uppercase tracking-[0.2em]">No
                                                            archived services found.</p>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($archived_services as $s): ?>
                                                <tr class="service-row archived-row border-b border-white/5 hover:bg-white/[0.02] transition-colors"
                                                    data-id="<?= $s['catalog_service_id'] ?>"
                                                    data-name="<?= strtolower(htmlspecialchars($s['service_name'])) ?>"
                                                    data-price="<?= (float) $s['price'] ?>"
                                                    data-date="<?= strtotime($s['created_at']) ?>">
                                                    <td class="px-8 py-5">
                                                        <span
                                                            class="text-xs font-black italic uppercase tracking-widest text-gray-500"><?= htmlspecialchars($s['service_name']) ?></span>
                                                    </td>
                                                    <td class="px-8 py-5 text-center">
                                                        <span
                                                            class="text-xs font-bold text-gray-600">₱<?= number_format($s['price'], 2) ?></span>
                                                    </td>
                                                    <td class="px-8 py-5 text-center">
                                                        <span
                                                            class="px-3 py-1 rounded-full bg-rose-500/10 text-rose-500 text-[8px] font-black uppercase tracking-widest border border-rose-500/20">Archived</span>
                                                    </td>
                                                    <td class="px-8 py-5 text-center">
                                                        <button type="button"
                                                            onclick="confirmRestoreService(<?= $s['catalog_service_id'] ?>)"
                                                            class="h-9 px-4 rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-emerald-500 hover:bg-emerald-500 hover:text-white transition-all flex items-center justify-center gap-2 text-[9px] font-black uppercase tracking-widest mx-auto active:scale-95">
                                                            <span
                                                                class="material-symbols-outlined text-base">settings_backup_restore</span>
                                                            Restore
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </form>

            </div>
        </div>

        <!-- ELITE MODALS (SIDEBAR-AWARE) -->


        <!-- Elite Confirmation Modal (Universal) -->
        <div id="confirmActionModal" class="modal-overlay"
            onclick="if(event.target === this) closeEliteModal('confirmActionModal')">
            <div class="glass-card w-full max-w-sm p-8 text-center animate-in zoom-in duration-300">
                <div id="confirmIconBox"
                    class="size-16 rounded-2xl bg-primary/10 border border-primary/20 flex items-center justify-center mx-auto mb-6">
                    <span id="confirmIcon"
                        class="material-symbols-outlined text-3xl text-primary font-bold">warning</span>
                </div>
                <h3 id="confirmTitle" class="text-xl font-black italic uppercase tracking-tighter text-white mb-2">
                    Confirm Action</h3>
                <p id="confirmDesc"
                    class="text-[10px] font-black uppercase tracking-widest text-gray-500 mb-8 leading-relaxed italic">
                    Are you sure you want to proceed with this action?</p>

                <div class="grid grid-cols-2 gap-4">
                    <button type="button" onclick="closeEliteModal('confirmActionModal')"
                        class="h-12 rounded-xl border border-white/5 text-[10px] font-black uppercase tracking-widest text-gray-500 hover:text-white transition-all">Cancel</button>
                    <button type="button" id="confirmActionBtn"
                        class="h-12 rounded-xl bg-primary text-white text-[10px] font-black uppercase italic tracking-widest shadow-xl shadow-primary/20 hover:scale-[1.02] active:scale-95 transition-all">Confirm
                        Now</button>
                </div>
            </div>
        </div>

        <!-- Add Service Modal -->
        <div id="addServiceModal" class="modal-overlay"
            onclick="if(event.target === this) closeEliteModal('addServiceModal')">
            <div class="glass-card w-full max-w-lg p-10 animate-in zoom-in duration-300 relative overflow-hidden">
                <div class="absolute top-0 right-0 p-4">
                    <button type="button" onclick="closeEliteModal('addServiceModal')"
                        class="text-gray-500 hover:text-white transition-colors">
                        <span class="material-symbols-outlined text-2xl">close</span>
                    </button>
                </div>

                <div class="flex items-center gap-4 mb-10">
                    <div class="size-14 rounded-2xl bg-primary/10 flex items-center justify-center text-primary">
                        <span class="material-symbols-outlined text-3xl">add_shopping_cart</span>
                    </div>
                    <div>
                        <h3 class="text-2xl font-black italic uppercase tracking-tighter text-white">New Service</h3>
                        <p class="text-[9px] text-gray-500 font-bold uppercase tracking-[0.2em] mt-1">Expansion of your
                            gym's catalog</p>
                    </div>
                </div>

                <form id="addServiceForm" class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="md:col-span-2 space-y-2">
                        <label class="text-[10px] font-black uppercase tracking-[0.2em] text-primary ml-1">Service
                            Name</label>
                        <input type="text" name="service_name" class="input-dark-elite uppercase italic"
                            placeholder="e.g. Personal Training" required>
                    </div>

                    <div class="md:col-span-2 space-y-2">
                        <label class="text-[10px] font-black uppercase tracking-[0.2em] text-primary ml-1">Price
                            (₱)</label>
                        <input type="number" name="price" step="0.01" class="input-dark-elite font-bold"
                            placeholder="0.00" required>
                    </div>

                    <div class="md:col-span-2 space-y-2">
                        <label class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 ml-1">Description
                            / Features</label>
                        <textarea name="description" rows="3" class="input-dark-elite !bg-white/[0.01] resize-none"
                            placeholder="What does this service include?"></textarea>
                    </div>

                    <div class="md:col-span-2 pt-6 flex gap-4">
                        <button type="button" onclick="closeEliteModal('addServiceModal')"
                            class="flex-1 h-14 rounded-2xl border border-white/5 text-[11px] font-black uppercase tracking-[0.2em] text-gray-500 hover:text-white transition-all">Discard</button>
                        <button type="submit"
                            class="flex-[1.5] h-14 rounded-2xl bg-primary text-white text-[11px] font-black uppercase italic tracking-[0.2em] shadow-xl shadow-primary/20 hover:scale-[1.02] active:scale-95 transition-all">Create
                            Service</button>
                    </div>
                </form>
            </div>
        </div>


    </main>

    <script>
        function showEliteToast(message, icon = 'info', bgColor = 'bg-primary') {
            const toast = document.createElement('div');
            toast.className = `fixed bottom-8 right-8 z-[1000] ${bgColor} text-white px-6 py-4 rounded-2xl shadow-2xl flex items-center gap-4 animate-in slide-in-from-right-10 duration-500`;
            toast.innerHTML = `
                <div class="size-10 rounded-xl bg-white/20 flex items-center justify-center">
                    <span class="material-symbols-outlined text-xl font-bold">${icon}</span>
                </div>
                <div>
                    <p class="text-[10px] font-black uppercase tracking-widest leading-none mb-1">System Notification</p>
                    <p class="text-[12px] font-bold">${message}</p>
                </div>
            `;
            document.body.appendChild(toast);
            setTimeout(() => {
                toast.classList.add('animate-out', 'fade-out', 'slide-out-to-right-10');
                setTimeout(() => toast.remove(), 500);
            }, 4000);
        }



        function updateTopClock() {
            const clockEl = document.getElementById('topClock');
            const dateEl = document.getElementById('topDate');
            if (!clockEl || !dateEl) return;
            const now = new Date();
            clockEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            dateEl.textContent = now.toLocaleDateString('en-US', { weekday: 'long', month: 'short', day: '2-digit', year: 'numeric' });
        }
        setInterval(updateTopClock, 1000);
        window.addEventListener('DOMContentLoaded', updateTopClock);


        function previewImg(input, targetId, placeholderId) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function (e) {
                    const img = document.getElementById(targetId);
                    const placeholder = document.getElementById(placeholderId);
                    img.src = e.target.result;
                    img.classList.remove('hidden');
                    if (placeholder) placeholder.classList.add('hidden');
                    updateMockup();
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        function handleFormSubmit(form, event) {
            if (event) event.preventDefault();

            // 1. UNIQUE NAME CHECK
            const planNames = Array.from(form.querySelectorAll('input[name*="[name]"]')).map(i => i.value.trim().toLowerCase());
            const uniqueNames = new Set(planNames);
            if (uniqueNames.size !== planNames.length) {
                showEliteToast('Duplicate plan names are not allowed!', 'warning', 'bg-red-500');
                return false;
            }

            // Sync active tab
            const activeTabInput = document.getElementById('activeTabInput');
            const currentTab = document.querySelector('.tab-btn.active')?.dataset.tab || 'branding';
            if (activeTabInput) activeTabInput.value = currentTab;

            const btn = form.querySelector('button[onclick="confirmSaveSettings()"]');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = `<span class="material-symbols-outlined text-base animate-spin">sync</span> Saving...`;
                btn.classList.add('opacity-70', 'cursor-not-allowed');
            }

            // Add hidden input for flag
            const flag = document.createElement('input');
            flag.type = 'hidden';
            flag.name = 'save_settings';
            flag.value = '1';
            form.appendChild(flag);

            form.submit();
            return true;
        }
        function resetBranding() {
            setConfirmModal('reset', null, 'Reset Branding', 'restart_alt', 'Confirm Reset', 'bg-rose-500');
        }

        function resetPortalContent() {
            setConfirmModal('reset_portal', null, 'Reset Portal Content', 'restart_alt', 'Confirm Reset', 'bg-rose-500');
        }

        async function performPortalReset() {
            const formData = new FormData();
            formData.append('action', 'reset_portal_content');

            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();

                if (result.success) {
                    showEliteToast('Portal Content Reset to Defaults!', 'check_circle', 'bg-emerald-500');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    throw new Error(result.error);
                }
            } catch (error) {
                showEliteToast(error.message || 'Reset failed.', 'error', 'bg-red-500');
            }
        }

        async function performBrandingReset() {
            const formData = new FormData();
            formData.append('action', 'reset_branding');

            try {
                const response = await fetch('tenant_settings.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();

                if (result.success) {
                    showEliteToast('Branding Reset to Defaults!', 'check_circle', 'bg-emerald-500');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    throw new Error(result.error);
                }
            } catch (error) {
                showEliteToast(error.message || 'Reset failed.', 'error', 'bg-red-500');
            }
        }

        function confirmSaveSettings() {
            setConfirmModal('save', null, 'Save Changes', 'save', 'Confirm & Save', 'bg-primary');
        }

        function setConfirmModal(type, id, title, icon, btnText, btnClass) {
            const modal = document.getElementById('confirmActionModal');
            const btn = document.getElementById('confirmActionBtn');
            const iconBox = document.getElementById('confirmIconBox');
            const iconEl = document.getElementById('confirmIcon');

            document.getElementById('confirmTitle').textContent = title;
            iconEl.textContent = icon;
            btn.textContent = btnText;

            // Dynamic Styling (Tailwind Safe)
            const baseColor = btnClass.replace('bg-', '');
            const shadowColor = baseColor.includes('primary') ? 'primary' : baseColor.split('-')[0];

            iconBox.className = `size-16 rounded-2xl border flex items-center justify-center mx-auto mb-6 ${btnClass}/10 border-${baseColor}${baseColor.includes('-') ? '' : '/20'}`;
            iconEl.className = `material-symbols-outlined text-3xl font-bold ${btnClass.replace('bg-', 'text-')}`;
            btn.className = `h-12 rounded-xl text-white text-[10px] font-black uppercase italic tracking-widest shadow-xl transition-all hover:scale-[1.02] ${btnClass} shadow-${shadowColor}/20`;

            // Message Mapping
            const descEl = document.getElementById('confirmDesc');
            if (type === 'save') {
                descEl.textContent = 'Update gym configurations? This will apply new branding and membership rules across all platforms immediately.';
                btn.onclick = () => handleFormSubmit(document.getElementById('gymSettingsForm'));
            } else if (type === 'archive') {
                descEl.textContent = 'Are you sure? This plan will be hidden from members but kept in history.';
                btn.onclick = () => autoTogglePlanStatus(id, type);
            } else if (type === 'restore') {
                descEl.textContent = 'Restore this plan to active status? It will be visible to members again.';
                btn.onclick = () => autoTogglePlanStatus(id, type);
            } else if (type === 'reset') {
                descEl.textContent = 'Are you sure you want to reset your gym branding to default system values? This will erase your custom colors and logo.';
                btn.onclick = () => performBrandingReset();
            } else if (type === 'reset_portal') {
                descEl.textContent = 'Are you sure you want to reset your portal website content to default messaging? This will erase all custom titles and descriptions.';
                btn.onclick = () => performPortalReset();
            }

            openEliteModal('confirmActionModal');
        }

        // Tab Switching Logic (Preserve in URL for Redirects)
        function switchTab(tabId) {
            // Panels
            document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
            const targetPanel = document.getElementById('tab' + tabId.charAt(0).toUpperCase() + tabId.slice(1));
            if (targetPanel) targetPanel.classList.add('active');

            // Buttons
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            const targetBtn = document.querySelector(`[onclick="switchTab('${tabId}')"]`);
            if (targetBtn) targetBtn.classList.add('active');

            // Update hidden input for POST
            const tabInput = document.getElementById('activeTabInput');
            if (tabInput) tabInput.value = tabId;

            // Update URL without reloading
            const url = new URL(window.location);
            url.searchParams.set('tab', tabId);
            window.history.replaceState({}, '', url);

            // Save to localStorage as fallback
            localStorage.setItem('activeSettingsTab', tabId);
        }

        // Restore tab on load
        window.addEventListener('load', () => {
            const urlParams = new URLSearchParams(window.location.search);
            let tab = urlParams.get('tab');
            if (!tab) tab = localStorage.getItem('activeSettingsTab') || 'branding';

            // Clean up input if needed
            tab = tab.replace('tab', '').toLowerCase();
            switchTab(tab);
        });

        function updateMockup() {
            const titleInput = document.querySelector('input[name="system_name"]');
            const colorInput = document.querySelector('input[name="theme_color"]');
            const secondaryInput = document.querySelector('input[name="secondary_color"]');
            const textInput = document.querySelector('input[name="text_color"]');
            const bgInput = document.querySelector('input[name="bg_color"]');
            const cardInput = document.querySelector('input[name="card_color"]');
            const syncInput = document.querySelector('input[name="auto_card_theme"][value="1"]');

            if (!colorInput) return;

            // Convert Hex to RGB manually for the preview
            const hexToRgbVals = (hex) => {
                let h = hex.replace('#', '');
                if (h.length === 3) h = h.split('').map(x => x + x).join('');
                return `${parseInt(h.substr(0, 2), 16)}, ${parseInt(h.substr(2, 2), 16)}, ${parseInt(h.substr(4, 2), 16)}`;
            };

            // REAL-TIME DASHBOARD SYNC
            document.documentElement.style.setProperty('--primary', colorInput.value);
            document.documentElement.style.setProperty('--primary-rgb', hexToRgbVals(colorInput.value));

            if (titleInput) {
                const sidebarName = document.getElementById('sidebarSystemName');
                if (sidebarName) sidebarName.innerText = titleInput.value || 'Owner Portal';
            }

            const logoContainer = document.getElementById('sidebarLogoContainer');
            if (logoContainer && !logoContainer.querySelector('img')) {
                logoContainer.style.backgroundColor = colorInput.value;
            }

            if (secondaryInput) {
                document.documentElement.style.setProperty('--highlight', secondaryInput.value);
            }

            if (textInput) {
                document.documentElement.style.setProperty('--text-main', textInput.value);
                document.body.style.color = textInput.value;
            }

            if (bgInput) {
                document.documentElement.style.setProperty('--background', bgInput.value);
                document.body.style.backgroundColor = bgInput.value;
            }

            if (cardInput && syncInput) {
                if (syncInput.checked) {
                    document.documentElement.style.setProperty('--card-bg', `rgba(${hexToRgbVals(colorInput.value)}, 0.05)`);
                } else {
                    document.documentElement.style.setProperty('--card-bg', cardInput.value);
                }
            }

            const data = {
                page_title: titleInput ? titleInput.value : '',
                theme_color: colorInput ? colorInput.value : '#8c2bee',
                secondary_color: secondaryInput ? secondaryInput.value : '#a1a1aa',
                text_color: textInput ? textInput.value : '#d1d5db',
                bg_color: bgInput ? bgInput.value : '#0a090d',
                card_color: cardInput ? cardInput.value : '#141216',
                auto_card_theme: syncInput ? syncInput.value : '0',
                // Operational Data Sync
                opening_time: document.querySelector('input[name="opening_time"]')?.value || '',
                closing_time: document.querySelector('input[name="closing_time"]')?.value || '',
                max_capacity: document.querySelector('input[name="max_capacity"]')?.value || '',
                has_lockers: document.querySelector('input[name="has_lockers"]')?.checked ? 1 : 0,
                has_shower: document.querySelector('input[name="has_shower"]')?.checked ? 1 : 0,
                has_parking: document.querySelector('input[name="has_parking"]')?.checked ? 1 : 0,
                has_wifi: document.querySelector('input[name="has_wifi"]')?.checked ? 1 : 0,
                rules_text: document.querySelector('textarea[name="rules_text"]')?.value || '',
                // CMS Content Sync
                portal_hero_title: document.querySelector('[name="portal_hero_title"]')?.value || '',
                portal_hero_subtitle: document.querySelector('[name="portal_hero_subtitle"]')?.value || '',
                portal_features_title: document.querySelector('[name="portal_features_title"]')?.value || '',
                portal_features_desc: document.querySelector('[name="portal_features_desc"]')?.value || '',
                portal_philosophy_title: document.querySelector('[name="portal_philosophy_title"]')?.value || '',
                portal_philosophy_desc: document.querySelector('[name="portal_philosophy_desc"]')?.value || '',
                // Expanded CMS Content Sync
                portal_hero_label: document.querySelector('[name="portal_hero_label"]')?.value || '',
                portal_features_label: document.querySelector('[name="portal_features_label"]')?.value || '',
                portal_philosophy_label: document.querySelector('[name="portal_philosophy_label"]')?.value || '',
                portal_plans_title: document.querySelector('[name="portal_plans_title"]')?.value || '',
                portal_plans_subtitle: document.querySelector('[name="portal_plans_subtitle"]')?.value || '',
                portal_services_title: document.querySelector('[name="portal_services_title"]')?.value || '',
                portal_services_subtitle: document.querySelector('[name="portal_services_subtitle"]')?.value || '',
                portal_footer_label: document.querySelector('[name="portal_footer_label"]')?.value || '',
                portal_footer_desc: document.querySelector('[name="portal_footer_desc"]')?.value || '',
                logo_url: document.getElementById('sidebarLogoImg')?.src || ''
            };

            // Update Hex Displays
            const phex = document.getElementById('colorHex');
            if (phex) phex.textContent = data.theme_color.toUpperCase();
            const shex = document.getElementById('secondaryHex');
            if (shex && data.secondary_color) shex.textContent = data.secondary_color.toUpperCase();
            const thex = document.getElementById('textHex');
            if (thex && data.text_color) thex.textContent = data.text_color.toUpperCase();
            const bhex = document.getElementById('bgHex');
            if (bhex && data.bg_color) bhex.textContent = data.bg_color.toUpperCase();
            const chex = document.getElementById('cardHex');
            if (chex && data.card_color) chex.textContent = data.card_color.toUpperCase();

            // Scrape Membership Plans for Real-time Sync (Ensuring precise DOM order)
            const activeContainer = document.getElementById('activePlansContainer');
            const newContainer = document.getElementById('newPlansContainer');
            const planCards = [
                ...(activeContainer ? Array.from(activeContainer.querySelectorAll('.elite-red-card')) : []),
                ...(newContainer ? Array.from(newContainer.querySelectorAll('.elite-red-card')) : [])
            ];

            data.plans = planCards.map(card => {
                const inputs = card.querySelectorAll('input, textarea');
                const planData = {};

                inputs.forEach(input => {
                    if (input.name.includes('[name]')) planData.name = input.value;
                    if (input.name.includes('[price]')) planData.price = input.value;
                    if (input.name.includes('[duration]')) planData.duration = input.value;
                    if (input.name.includes('[billing_cycle]')) planData.billing = input.value;
                    if (input.name.includes('[badge]')) planData.badge = input.value;
                    if (input.name.includes('[description]')) planData.description = input.value;
                    if (input.name.includes('[features]')) planData.features = input.value;
                });

                planData.type = card.getAttribute('data-type') || 'Standard';
                return planData;
            });

            const portalFrame = document.getElementById('portalFrame');
            if (portalFrame && portalFrame.contentWindow) {
                portalFrame.contentWindow.postMessage({ type: 'updateStyles', data: data }, '*');
            }
        }

        function handleResize() {
            const container = document.getElementById('portalContainer');
            const frame = document.getElementById('portalFrame');
            if (container && frame) {
                const scale = container.offsetWidth / 1600;
                frame.style.transform = `scale(${scale})`;
                container.style.height = '650px';
            }
        }
        window.onload = function () {
            handleResize();
            updateMockup();
        };
        window.addEventListener('resize', handleResize);

        // --- ELITE MATCH: MEMBERSHIP PLAN LOGIC ---

        function togglePlanView(view) {
            const activeContainer = document.getElementById('activePlansContainer');
            const archivedContainer = document.getElementById('archivedPlansContainer');
            const activeBtn = document.getElementById('activeTabBtn');
            const archivedBtn = document.getElementById('archivedTabBtn');

            if (view === 'active') {
                activeContainer.classList.remove('hidden');
                archivedContainer.classList.add('hidden');
                activeBtn.className = 'tab-btn-match active px-6 h-9';
                archivedBtn.className = 'tab-btn-match inactive px-6 h-9';
            } else {
                activeContainer.classList.add('hidden');
                archivedContainer.classList.remove('hidden');
                activeBtn.className = 'tab-btn-match inactive px-6 h-9';
                archivedBtn.className = 'tab-btn-match active px-6 h-9';
            }
        }

        function checkActivePlansEmptyState() {
            const container = document.getElementById('activePlansContainer');
            const emptyState = document.getElementById('activePlansEmptyState');
            if (!emptyState || !container) return;

            const cards = container.querySelectorAll('.elite-red-card:not(.animate-out)');
            if (cards.length > 0) {
                emptyState.classList.add('hidden');
            } else {
                emptyState.classList.remove('hidden');
            }
        }

        function addNewMembershipPlanCard() {
            const container = document.getElementById('activePlansContainer');
            const emptyState = document.getElementById('activePlansEmptyState');
            if (emptyState) emptyState.classList.add('hidden');

            const draftId = `draft_${Date.now()}`;
            const cardHtml = `
            <div class="elite-red-card is-editing p-10 animate-in zoom-in duration-300 border-dashed border-primary/40 relative overflow-hidden" id="card-${draftId}">
                <div class="absolute top-0 right-0 px-4 py-1.5 bg-white/5 text-gray-400 text-[8px] font-black uppercase tracking-widest rounded-bl-xl shadow-lg ring-1 ring-white/10">Draft Tier</div>
                
                <div class="flex items-center justify-between mb-10">
                    <div class="flex items-center gap-4">
                        <div class="size-10 rounded-xl bg-primary/10 flex items-center justify-center">
                            <span class="material-symbols-outlined text-primary text-2xl">workspace_premium</span>
                        </div>
                        <h5 class="text-sm font-black italic uppercase tracking-widest text-primary plan-title-preview">New Draft Plan</h5>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" onclick="this.closest('.elite-red-card').remove(); checkActivePlansEmptyState(); updateMockup();" class="size-8 rounded-lg bg-rose-500/10 text-rose-500 flex items-center justify-center hover:bg-rose-500 hover:text-white transition-all active:scale-95" title="Remove Draft">
                            <span class="material-symbols-outlined text-lg">delete</span>
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-x-6 gap-y-6">
                    <div class="col-span-2 flex flex-col gap-1.5">
                        <label class="text-[10px] font-black uppercase text-primary tracking-widest ml-1">Plan Name</label>
                        <input type="text" name="new_membership_plans[${draftId}][name]" class="input-dark-elite italic tracking-tight uppercase" placeholder="e.g. Bronze Access" required oninput="this.closest('.elite-red-card').querySelector('.plan-title-preview').innerText = this.value || 'New Draft Plan'">
                    </div>

                    <div class="col-span-1 flex flex-col gap-1.5">
                        <label class="text-[10px] font-black uppercase text-primary tracking-widest ml-1">Price (₱)</label>
                        <input type="number" name="new_membership_plans[${draftId}][price]" class="input-dark-elite font-bold" placeholder="1000" required>
                    </div>

                    <div class="col-span-1 flex flex-col gap-1.5">
                        <label class="text-[10px] font-black uppercase text-primary tracking-widest ml-1">Duration (Months)</label>
                        <input type="number" name="new_membership_plans[${draftId}][duration]" class="input-dark-elite font-bold" placeholder="1" required>
                    </div>

                    <div class="col-span-2 flex flex-col gap-1.5">
                        <label class="text-[10px] font-black uppercase text-primary tracking-widest ml-1 text-gray-500">Billing Cycle</label>
                        <input type="text" name="new_membership_plans[${draftId}][billing_cycle]" class="input-dark-elite font-bold" placeholder="e.g. per month">
                    </div>

                    <div class="col-span-2 flex flex-col gap-1.5">
                        <label class="text-[10px] font-black uppercase text-primary tracking-widest ml-1 text-gray-500">Badge</label>
                        <input type="text" name="new_membership_plans[${draftId}][badge]" class="input-dark-elite font-bold italic opacity-60" placeholder="e.g. Popular">
                    </div>

                    <div class="col-span-2 flex flex-col gap-1.5">
                        <label class="text-[10px] font-black uppercase text-primary tracking-widest ml-1">Features (Detailed)</label>
                        <textarea name="new_membership_plans[${draftId}][features]" rows="3" class="input-dark-elite !bg-white/[0.01] resize-none leading-relaxed font-bold" placeholder="List the benefits of this tier..."></textarea>
                    </div>
                </div>

                <div class="mt-10 pt-8 border-t border-white/5">
                    <p class="text-[8px] font-black uppercase tracking-widest text-primary opacity-60 italic">Manual save required via 'Save Changes'</p>
                </div>
            </div>
            `;
            container.insertAdjacentHTML('afterbegin', cardHtml);

            // Scroll to the new draft card
            const newCard = document.getElementById(`card-${draftId}`);
            if (newCard) {
                newCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            updateMockup();
        }


        function createRealPlanCard(plan) {
            const container = document.getElementById('activePlansContainer');
            const emptyState = document.getElementById('activePlansEmptyState');
            if (emptyState) emptyState.classList.add('hidden');

            const cardHtml = `
            <div class="elite-red-card p-10 group relative overflow-hidden transition-all duration-300 animate-in zoom-in" 
                 id="plan-card-${plan.membership_plan_id}"
                 data-id="${plan.membership_plan_id}"
                 data-name="${plan.plan_name}"
                 data-price="${plan.price}"
                 data-duration="${plan.duration_value}"
                 data-billing="${plan.billing_cycle_text || 'Default'}">
                 
                 <div class="flex items-center justify-between mb-8">
                    <div class="flex items-center gap-4">
                        <div class="size-10 rounded-xl bg-primary/10 flex items-center justify-center">
                            <span class="material-symbols-outlined text-primary text-2xl">workspace_premium</span>
                        </div>
                        <h5 class="text-sm font-black italic uppercase tracking-widest text-primary plan-title-preview">${plan.plan_name}</h5>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" onclick="togglePlanEdit(${plan.membership_plan_id})" class="size-8 rounded-lg bg-primary/10 text-primary flex items-center justify-center hover:bg-primary hover:text-white transition-all active:scale-95 edit-btn" title="Edit Plan">
                            <span class="material-symbols-outlined text-lg">edit</span>
                        </button>
                        <button type="button" onclick="confirmArchiveMPlan(${plan.membership_plan_id})" class="size-8 rounded-lg bg-primary/10 text-gray-500 flex items-center justify-center hover:bg-rose-500 hover:text-white transition-all active:scale-95" title="Archive Plan">
                            <span class="material-symbols-outlined text-lg">archive</span>
                        </button>
                    </div>
                </div>

                <div class="plan-preview-state grid grid-cols-2 gap-x-6 gap-y-6 animate-in fade-in duration-500">
                    <div class="col-span-2 flex flex-col gap-1.5">
                        <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Plan Name</label>
                        <div class="view-box text-white">${plan.plan_name}</div>
                    </div>
                    <div class="col-span-1 flex flex-col gap-1.5">
                        <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Price (₱)</label>
                        <div class="view-box font-bold">₱${parseFloat(plan.price).toLocaleString()}</div>
                    </div>
                    <div class="col-span-1 flex flex-col gap-1.5">
                        <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Duration</label>
                        <div class="view-box font-bold">${plan.duration_value} Months</div>
                    </div>
                    <div class="col-span-2 flex flex-col gap-1.5">
                        <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Features Summary</label>
                        <div class="view-box view-box-long text-gray-400 font-medium">${plan.features || 'No features listed'}</div>
                    </div>
                </div>

                <div class="plan-edit-state grid grid-cols-2 gap-x-6 gap-y-6 animate-in fade-in duration-300">
                    <div class="col-span-2 flex flex-col gap-1.5">
                        <label class="text-[9px] font-black uppercase text-primary tracking-widest ml-1">Plan Name</label>
                        <input type="text" name="membership_plans[${plan.membership_plan_id}][name]" value="${plan.plan_name}" class="input-dark-elite italic tracking-tight uppercase" required oninput="this.closest('.elite-red-card').querySelector('.plan-title-preview').innerText = this.value">
                    </div>
                    <div class="col-span-1 flex flex-col gap-1.5">
                        <label class="text-[9px] font-black uppercase text-primary tracking-widest ml-1">Price (₱)</label>
                        <input type="number" name="membership_plans[${plan.membership_plan_id}][price]" value="${plan.price}" class="input-dark-elite font-bold" required>
                    </div>
                    <div class="col-span-1 flex flex-col gap-1.5">
                        <label class="text-[9px] font-black uppercase text-primary tracking-widest ml-1">Duration (Months)</label>
                        <input type="number" name="membership_plans[${plan.membership_plan_id}][duration]" value="${plan.duration_value}" class="input-dark-elite font-bold" required>
                    </div>
                    <div class="col-span-1 flex flex-col gap-1.5">
                        <label class="text-[9px] font-black uppercase text-primary tracking-widest ml-1">Billing Cycle</label>
                        <input type="text" name="membership_plans[${plan.membership_plan_id}][billing_cycle]" value="${plan.billing_cycle_text || ''}" class="input-dark-elite font-bold" placeholder="e.g. per month">
                    </div>
                    <div class="col-span-1 flex flex-col gap-1.5">
                        <label class="text-[9px] font-black uppercase text-gray-500 tracking-widest ml-1">Featured Badge</label>
                        <input type="text" name="membership_plans[${plan.membership_plan_id}][badge]" value="${plan.featured_badge_text || ''}" class="input-dark-elite font-bold italic opacity-60" placeholder="e.g. Popular">
                    </div>
                    <div class="col-span-2 flex flex-col gap-1.5">
                        <label class="text-[9px] font-black uppercase text-primary tracking-widest ml-1">Features (Comma-separated)</label>
                        <textarea name="membership_plans[${plan.membership_plan_id}][features]" rows="3" class="input-dark-elite !bg-white/[0.01] resize-none leading-relaxed font-bold">${plan.features || ''}</textarea>
                    </div>
                </div>
            </div>
            `;
            container.insertAdjacentHTML('afterbegin', cardHtml);
            updateMockup();
        }

        function removeProposedPlan(btn) {
            const card = btn.closest('.elite-red-card');
            card.classList.add('scale-75', 'opacity-0');
            setTimeout(() => {
                card.remove();
                checkActivePlansEmptyState();
                updateMockup();
            }, 300);
        }

        function confirmSaveSettings() {
            const modal = document.getElementById('confirmActionModal');
            const btn = document.getElementById('confirmActionBtn');

            document.getElementById('confirmTitle').textContent = 'Sync & Update Settings?';
            document.getElementById('confirmIcon').textContent = 'sync';
            btn.textContent = 'Sync & Update';
            btn.className = `h-12 rounded-xl text-white text-[10px] font-black uppercase italic tracking-widest shadow-xl transition-all hover:scale-[1.02] bg-primary px-8`;

            btn.onclick = () => {
                btn.disabled = true;
                btn.innerHTML = '<span class="animate-spin material-symbols-outlined text-base">sync</span>';
                document.getElementById('gymSettingsForm').submit();
            };

            openEliteModal('confirmActionModal');
        }

        function handleFormSubmit(form, event) {
            return true;
        }

        function openEliteModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.add('flex');
                document.body.style.overflow = 'hidden';
            }
        }

        function closeEliteModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.remove('flex');
                document.body.style.overflow = '';
            }
        }

        function confirmArchiveMPlan(id) {
            setConfirmModal('archive', id, 'Archive Tier', 'archive', 'Archive Now', 'bg-orange-500');
        }

        function confirmRestoreMPlan(id) {
            setConfirmModal('restore', id, 'Restore Tier', 'settings_backup_restore', 'Restore Now', 'bg-emerald-500');
        }

        function setConfirmModal(type, id, title, icon, btnText, btnClass) {
            const modal = document.getElementById('confirmActionModal');
            const btn = document.getElementById('confirmActionBtn');

            document.getElementById('confirmTitle').textContent = title;
            document.getElementById('confirmIcon').textContent = icon;
            btn.textContent = btnText;
            btn.className = `h-12 rounded-xl text-white text-[10px] font-black uppercase italic tracking-widest shadow-xl transition-all hover:scale-[1.02] ${btnClass}`;

            // Attach AJAX Action
            btn.onclick = () => autoTogglePlanStatus(id, type);

            openEliteModal('confirmActionModal');
        }

        function autoTogglePlanStatus(id, action) {
            const btn = document.getElementById('confirmActionBtn');
            const isActive = action === 'restore' ? 1 : 0;

            btn.disabled = true;
            btn.innerHTML = '<span class="animate-spin material-symbols-outlined text-base">sync</span>';

            const formData = new FormData();
            formData.append('action', 'toggle_plan_status');
            formData.append('id', id);
            formData.append('is_active', isActive);

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        closeEliteModal('confirmActionModal');
                        movePlanUI(id, action);
                    } else {
                        alert(data.error || 'Failed to update plan status.');
                    }
                })
                .catch(err => {
                    console.error('AJAX Error:', err);
                    alert('Something went wrong. Please try again.');
                })
                .finally(() => {
                    btn.disabled = false;
                });
        }

        function movePlanUI(id, action) {
            const activeContainer = document.getElementById('activePlansContainer');
            const archivedBody = document.getElementById('archivedTableBody');

            if (action === 'archive') {
                const card = activeContainer.querySelector(`[data-id="${id}"]`);
                if (!card) return;

                // Extract data for table row
                const data = {
                    name: card.getAttribute('data-name'),
                    rawName: card.getAttribute('data-name'),
                    price: card.getAttribute('data-price'),
                    duration: card.getAttribute('data-duration'),
                    billing: card.getAttribute('data-billing') || 'Default'
                };

                // Animate out
                card.classList.add('scale-75', 'opacity-0');
                setTimeout(() => {
                    card.remove();

                    // Add to table
                    const row = document.createElement('tr');
                    row.className = 'archived-row group border-b border-white/5 hover:bg-white/[0.02] transition-colors animate-in slide-in-from-left duration-500';
                    row.setAttribute('data-id', id);
                    row.setAttribute('data-name', data.name.toLowerCase());
                    row.setAttribute('data-raw-name', data.name);
                    row.setAttribute('data-price', data.price);
                    row.setAttribute('data-duration', data.duration);
                    row.setAttribute('data-billing', data.billing);
                    row.setAttribute('data-date', Math.floor(Date.now() / 1000));

                    row.innerHTML = `
                    <td class="px-8 py-5">
                        <div>
                            <p class="text-xs font-black italic uppercase tracking-widest text-gray-400">${data.rawName}</p>
                        </div>
                    </td>
                    <td class="px-8 py-5 text-center">
                        <span class="text-xs font-bold text-gray-400">₱${parseFloat(data.price).toLocaleString()}</span>
                    </td>
                    <td class="px-8 py-5 text-center">
                        <span class="px-3 py-1 rounded-lg bg-white/5 text-[9px] font-black text-gray-500 uppercase tracking-widest">
                            ${data.duration} Months
                        </span>
                    </td>
                    <td class="px-8 py-5 text-center">
                        <span class="text-[10px] font-medium text-gray-500">${data.billing}</span>
                    </td>
                    <td class="px-8 py-5 text-center">
                        <button type="button" onclick="confirmRestoreMPlan(${id})" class="h-9 px-4 rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-emerald-500 hover:bg-emerald-500 hover:text-white flex items-center justify-center gap-2 text-[9px] font-black uppercase tracking-widest mx-auto transition-all active:scale-95">
                            <span class="material-symbols-outlined text-base">settings_backup_restore</span> Restore
                        </button>
                    </td>
                `;

                    const emptyState = archivedBody.querySelector('.no-data-row');
                    if (emptyState) emptyState.remove();

                    archivedBody.insertBefore(row, archivedBody.firstChild);
                    checkActivePlansEmptyState();
                }, 300);

            } else if (action === 'restore') {
                const row = archivedBody.querySelector(`[data-id="${id}"]`);
                if (!row) return;

                // Extract data for card
                const data = {
                    id: id,
                    name: row.getAttribute('data-raw-name'),
                    price: row.getAttribute('data-price'),
                    duration: row.getAttribute('data-duration'),
                    billing: row.getAttribute('data-billing') || 'Default',
                    features: 'Plan Restored. Update setings and Save Changes.'
                };

                // Animate out
                row.classList.add('opacity-0', '-translate-x-10');
                setTimeout(() => {
                    row.remove();

                    const cardHtml = `
                    <div class="elite-red-card p-10 group relative overflow-hidden transition-all duration-300 animate-in zoom-in" 
                         id="plan-card-${id}"
                         data-id="${id}"
                         data-name="${data.name}"
                         data-price="${data.price}"
                         data-duration="${data.duration}"
                         data-billing="${data.billing}">
                         
                         <div class="flex items-center justify-between mb-10">
                            <div class="flex items-center gap-4">
                                <span class="material-symbols-outlined text-gray-700 hover:text-primary transition-all cursor-grab active:cursor-grabbing drag-handle">drag_indicator</span>
                                <div class="size-10 rounded-xl bg-primary/10 flex items-center justify-center">
                                    <span class="material-symbols-outlined text-primary text-2xl">workspace_premium</span>
                                </div>
                                <h5 class="text-xs font-black italic uppercase tracking-widest text-primary plan-title-preview">${data.name}</h5>
                            </div>
                            <div class="flex items-center gap-2">
                                <button type="button" onclick="togglePlanEdit(${id})" class="size-8 rounded-lg bg-primary/10 text-primary flex items-center justify-center hover:bg-primary hover:text-white transition-all active:scale-95 edit-btn" title="Edit Plan">
                                    <span class="material-symbols-outlined text-lg">edit</span>
                                </button>
                                <button type="button" onclick="confirmArchiveMPlan(${id})" class="size-8 rounded-lg bg-primary/10 text-gray-500 flex items-center justify-center hover:bg-rose-500 hover:text-white transition-all active:scale-95" title="Archive Plan">
                                    <span class="material-symbols-outlined text-lg">archive</span>
                                </button>
                            </div>
                        </div>

                        <!-- PREVIEW STATE -->
                        <div class="plan-preview-state grid grid-cols-2 gap-x-6 gap-y-6 animate-in fade-in duration-500">
                            <div class="col-span-2 flex flex-col gap-1.5">
                                <label class="label-elite ml-1">Plan Name</label>
                                <div class="view-box text-white">${data.name}</div>
                            </div>
                            <div class="col-span-1 flex flex-col gap-1.5">
                                <label class="label-elite ml-1">Price (₱)</label>
                                <div class="view-box font-bold">₱${parseFloat(data.price).toLocaleString()}</div>
                            </div>
                            <div class="col-span-1 flex flex-col gap-1.5">
                                <label class="label-elite ml-1">Duration</label>
                                <div class="view-box font-bold">${data.duration} Months</div>
                            </div>
                            <div class="col-span-2 flex flex-col gap-1.5">
                                <label class="label-elite ml-1">Features Summary</label>
                                <div class="view-box view-box-long text-gray-400 font-medium">${data.features}</div>
                            </div>
                        </div>

                        <!-- EDIT STATE -->
                        <div class="plan-edit-state grid grid-cols-2 gap-x-6 gap-y-6 animate-in fade-in duration-300">
                            <div class="col-span-2 flex flex-col gap-1.5">
                                <label class="label-elite ml-1 text-primary">Plan Name</label>
                                <input type="text" name="membership_plans[${id}][name]" value="${data.name}" class="input-dark-elite italic tracking-tight uppercase" required oninput="this.closest('.elite-red-card').querySelector('.plan-title-preview').innerText = this.value">
                            </div>
                            <div class="col-span-1 flex flex-col gap-1.5">
                                <label class="label-elite ml-1 text-primary">Price (₱)</label>
                                <input type="number" name="membership_plans[${id}][price]" value="${data.price}" class="input-dark-elite font-bold" required>
                            </div>
                            <div class="col-span-1 flex flex-col gap-1.5">
                                <label class="label-elite ml-1 text-primary">Duration (Months)</label>
                                <input type="number" name="membership_plans[${id}][duration]" value="${data.duration}" class="input-dark-elite font-bold" required>
                            </div>
                            <div class="col-span-2 flex flex-col gap-1.5">
                                <label class="label-elite ml-1 text-primary">Features (Detailed)</label>
                                <textarea name="membership_plans[${id}][features]" rows="3" class="input-dark-elite !bg-white/[0.01] resize-none leading-relaxed font-bold">${data.features}</textarea>
                            </div>
                        </div>
                    </div>
                `;

                    activeContainer.insertAdjacentHTML('afterbegin', cardHtml);
                    checkActivePlansEmptyState();
                    updateMockup();
                    showEliteToast('Plan restored to active grid.', 'restore', 'bg-emerald-500');
                }, 300);
            }
        }

        function togglePlanEdit(idOrBtn) {
            let card, btn, id;

            if (typeof idOrBtn === 'number' || typeof idOrBtn === 'string') {
                id = idOrBtn;
                card = document.getElementById(`plan-card-${id}`);
                btn = card.querySelector('.edit-btn');
            } else {
                btn = idOrBtn;
                card = btn.closest('.elite-red-card');
                id = card.getAttribute('data-id');
            }

            if (!card) return;

            const isEditing = card.classList.toggle('is-editing');
            const icon = btn.querySelector('.material-symbols-outlined');

            if (isEditing) {
                icon.textContent = 'check';
                btn.classList.add('bg-emerald-500/10', 'text-emerald-500');
                btn.classList.remove('bg-primary/10', 'text-primary');

                // Select first input
                setTimeout(() => {
                    const firstInput = card.querySelector('input');
                    if (firstInput) firstInput.focus();
                }, 100);
            } else {
                // SAVE CHANGES VIA AJAX
                const originalIcon = icon.textContent;
                btn.disabled = true;
                icon.textContent = 'sync';
                icon.classList.add('animate-spin');

                const formData = new FormData();
                formData.append('action', 'update_membership_plan');
                formData.append('id', id);
                formData.append('name', card.querySelector(`[name*="[name]"]`).value);
                formData.append('price', card.querySelector(`[name*="[price]"]`).value);
                formData.append('duration', card.querySelector(`[name*="[duration]"]`).value);
                formData.append('features', card.querySelector(`[name*="[features]"]`).value);
                // Badge and Billing if exists
                const billing = card.querySelector(`[name*="[billing_cycle]"]`);
                const badge = card.querySelector(`[name*="[badge]"]`);
                if (billing) formData.append('billing_cycle', billing.value);
                if (badge) formData.append('badge', badge.value);

                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showEliteToast('Plan Updated Successfully!', 'verified', 'bg-emerald-600');

                            // Update Preview Viewboxes
                            const views = card.querySelectorAll('.view-box');
                            if (views[0]) views[0].textContent = card.querySelector(`[name*="[name]"]`).value;
                            if (views[1]) views[1].textContent = '₱' + parseFloat(card.querySelector(`[name*="[price]"]`).value).toLocaleString();
                            if (views[2]) views[2].textContent = card.querySelector(`[name*="[duration]"]`).value + ' Months';
                            if (views[3]) views[3].textContent = card.querySelector(`[name*="[billing_cycle]"]`)?.value || 'Default';
                            if (views[4]) views[4].textContent = card.querySelector(`[name*="[badge]"]`)?.value || 'None';
                            if (views[5]) views[5].textContent = card.querySelector(`[name*="[features]"]`).value || 'No features listed';

                            icon.textContent = 'edit';
                            btn.classList.remove('bg-emerald-500/10', 'text-emerald-500');
                            btn.classList.add('bg-primary/10', 'text-primary');
                            updateMockup();
                        } else {
                            showEliteToast(data.error || 'Failed to update plan.', 'error', 'bg-rose-600');
                            card.classList.add('is-editing'); // Revert state
                        }
                    })
                    .catch(err => {
                        console.error('AJAX Error:', err);
                        showEliteToast('Failed to save changes.', 'error', 'bg-rose-600');
                        card.classList.add('is-editing');
                    })
                    .finally(() => {
                        btn.disabled = false;
                        icon.classList.remove('animate-spin');
                    });
            }
        }

        // --- ARCHIVED PLANS FILTERING & SORTING ---
        function filterArchivedPlans() {
            const query = document.getElementById('archivedSearch').value.toLowerCase();
            const dateSort = document.getElementById('archivedSortDate').value;
            const priceSort = document.getElementById('archivedSortPrice').value;
            const tbody = document.getElementById('archivedTableBody');
            const rows = Array.from(tbody.querySelectorAll('.archived-row'));

            let filteredRows = rows.filter(row => {
                const name = row.getAttribute('data-name');
                return name.includes(query);
            });

            // Sorting Logic
            filteredRows.sort((a, b) => {
                // Sort by Date
                const dateA = parseInt(a.getAttribute('data-date'));
                const dateB = parseInt(b.getAttribute('data-date'));

                if (dateSort === 'newest') {
                    if (dateB !== dateA) return dateB - dateA;
                } else {
                    if (dateA !== dateB) return dateA - dateB;
                }

                // Sort by Price (as secondary or if date is same)
                const priceA = parseInt(a.getAttribute('data-price'));
                const priceB = parseInt(b.getAttribute('data-price'));

                if (priceSort === 'low') return priceA - priceB;
                if (priceSort === 'high') return priceB - priceA;

                return 0;
            });

            // Toggle visibility and re-append sorted rows
            rows.forEach(row => row.classList.add('hidden'));
            filteredRows.forEach(row => {
                row.classList.remove('hidden');
                tbody.appendChild(row);
            });

            // Handle empty state
            const existingNoData = tbody.querySelector('.no-data-row');
            if (filteredRows.length === 0) {
                if (!existingNoData) {
                    const tr = document.createElement('tr');
                    tr.className = 'no-data-row';
                    tr.innerHTML = `
                    <td colspan="5" class="px-8 py-20 text-center">
                        <div class="flex flex-col items-center justify-center opacity-40">
                            <span class="material-symbols-outlined text-4xl mb-4">search_off</span>
                            <p class="text-[10px] font-black uppercase tracking-[0.2em]">No matching plans found.</p>
                        </div>
                    </td>
                `;
                    tbody.appendChild(tr);
                }
            } else if (existingNoData) {
                existingNoData.remove();
            }
        }

        function resetArchivedFilters() {
            document.getElementById('archivedSearch').value = '';
            document.getElementById('archivedSortDate').value = 'newest';
            document.getElementById('archivedSortPrice').value = 'default';
            filterArchivedPlans();
        }

        // --- SORTABLE JS INITIALIZATION ---
        document.addEventListener('DOMContentLoaded', function () {
            const container = document.getElementById('activePlansContainer');
            if (container) {
                new Sortable(container, {
                    handle: '.drag-handle',
                    animation: 150,
                    ghostClass: 'opacity-50',
                    onEnd: function () {
                        const order = Array.from(container.querySelectorAll('.elite-red-card'))
                            .map(card => card.getAttribute('data-id'))
                            .filter(id => id); // Exclude new unsaved plans

                        if (order.length > 0) {
                            const formData = new FormData();
                            formData.append('action', 'save_membership_order');
                            order.forEach(id => formData.append('order[]', id));

                            fetch(window.location.href, {
                                method: 'POST',
                                body: formData
                            })
                                .then(response => response.json())
                                .then(data => {
                                    if (data.success) {
                                        console.log('Order saved successfully');
                                        showEliteToast('New plan order saved', 'reorder', 'bg-primary');
                                    }
                                })
                                .catch(err => console.error('Error saving order:', err));
                        }
                        setTimeout(() => {
                            updateMockup();
                        }, 50);
                    }
                });
            }

            // --- REAL-TIME PLAN SYNC LISTENER ---
            const planParent = document.getElementById('activePlansContainer')?.parentElement;
            if (planParent) {
                planParent.addEventListener('input', (e) => {
                    if (e.target.closest('.elite-red-card')) {
                        updateMockup();
                    }
                });
            }
        });

        // --- SERVICES CATALOG FILTERING ---
        function filterServices() {
            const query = document.getElementById('servicesSearch').value.toLowerCase();
            const dateSort = document.getElementById('servicesSortDate').value;
            const priceSort = document.getElementById('servicesSortPrice').value;

            const activeVisible = !document.getElementById('activeServicesContainer').classList.contains('hidden');
            const container = activeVisible
                ? document.getElementById('activeServicesContainer')
                : document.getElementById('archivedServicesTableBody');

            // Select either .service-card (grid) or .service-row (table row)
            const items = Array.from(container.querySelectorAll('.service-card, .service-row'));

            // 1. Filtering
            let filteredItems = items.filter(item => {
                const name = item.getAttribute('data-name');
                const matches = name.includes(query);
                item.style.display = matches ? '' : 'none';
                return matches;
            });

            // 2. Sorting
            filteredItems.sort((a, b) => {
                // Primary Sort: Date
                const dateA = parseInt(a.getAttribute('data-date')) || 0;
                const dateB = parseInt(b.getAttribute('data-date')) || 0;

                if (dateSort === 'newest') {
                    if (dateB !== dateA) return dateB - dateA;
                } else if (dateSort === 'oldest') {
                    if (dateA !== dateB) return dateA - dateB;
                }

                // Secondary Sort: Price
                const priceA = parseFloat(a.getAttribute('data-price')) || 0;
                const priceB = parseFloat(b.getAttribute('data-price')) || 0;

                if (priceSort === 'low') return priceA - priceB;
                if (priceSort === 'high') return priceB - priceA;

                return 0;
            });

            // 3. Re-append sorted results without flickering
            filteredItems.forEach(item => container.appendChild(item));

            // Handle Empty State
            const existingNoData = container.querySelector('.no-data-search-row, .no-data-row');
            if (filteredItems.length === 0) {
                if (!existingNoData) {
                    const emptyHtml = activeVisible
                        ? `<div class="col-span-full py-20 flex flex-col items-center justify-center opacity-40 no-data-search-row animate-in fade-in duration-300">
                             <span class="material-symbols-outlined text-4xl mb-4 text-primary">search_off</span>
                             <p class="text-[10px] font-black uppercase tracking-[0.2em]">No matching services found.</p>
                           </div>`
                        : `<tr class="no-data-search-row animate-in fade-in duration-300">
                             <td colspan="5" class="px-8 py-20 text-center">
                               <div class="flex flex-col items-center justify-center opacity-40">
                                 <span class="material-symbols-outlined text-4xl mb-4 text-primary">search_off</span>
                                 <p class="text-[10px] font-black uppercase tracking-[0.2em]">No matching archived offerings.</p>
                               </div>
                             </td>
                           </tr>`;

                    if (activeVisible) {
                        container.insertAdjacentHTML('beforeend', emptyHtml);
                    } else {
                        container.innerHTML = emptyHtml;
                    }
                }
            } else if (existingNoData && existingNoData.classList.contains('no-data-search-row')) {
                existingNoData.remove();
            }
        }

        function resetServiceFilters() {
            document.getElementById('servicesSearch').value = '';
            document.getElementById('servicesSortDate').value = 'newest';
            document.getElementById('servicesSortPrice').value = 'default';
            filterServices();
        }

        function toggleServiceView(view) {
            const activeContainer = document.getElementById('activeServicesContainer');
            const archivedContainer = document.getElementById('archivedServicesContainer');
            const activeBtn = document.getElementById('activeServiceTabBtn');
            const archivedBtn = document.getElementById('archivedServiceTabBtn');

            if (view === 'active') {
                activeContainer.classList.remove('hidden');
                archivedContainer.classList.add('hidden');
                activeBtn.classList.add('active');
                activeBtn.classList.remove('inactive');
                archivedBtn.classList.add('inactive');
                archivedBtn.classList.remove('active');
            } else {
                activeContainer.classList.add('hidden');
                archivedContainer.classList.remove('hidden');
                activeBtn.classList.remove('active');
                activeBtn.classList.add('inactive');
                archivedBtn.classList.remove('inactive');
                archivedBtn.classList.add('active');
            }
            filterServices();
        }

        function openAddServiceModal() {
            document.getElementById('addServiceModal').classList.add('flex');
            document.getElementById('addServiceForm').reset();
        }

        function confirmArchiveService(id) {
            const confirmBtn = document.getElementById('confirmActionBtn');
            const modalTitle = document.getElementById('confirmTitle');
            const modalDesc = document.getElementById('confirmDesc');
            const iconBox = document.getElementById('confirmIconBox');
            const icon = document.getElementById('confirmIcon');

            modalTitle.innerText = "Archive Service";
            modalDesc.innerText = "Are you sure you want to archive this service? It will no longer be visible to members.";

            icon.innerText = "archive";
            iconBox.className = "size-16 rounded-2xl bg-rose-500/10 border border-rose-500/20 flex items-center justify-center mx-auto mb-6";
            icon.className = "material-symbols-outlined text-3xl text-rose-500 font-bold";

            confirmBtn.className = "h-12 rounded-xl bg-rose-500 text-white text-[10px] font-black uppercase italic tracking-widest shadow-xl shadow-rose-500/20 hover:scale-[1.02] active:scale-95 transition-all";

            confirmBtn.onclick = function () {
                const formData = new FormData();
                formData.append('save_settings', '1');
                formData.append('action', 'archive_catalog_service');
                formData.append('service_id', id);
                formData.append('active_tab', 'services');

                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                }).then(() => window.location.reload());
            };

            document.getElementById('confirmActionModal').classList.add('flex');
        }

        function confirmRestoreService(id) {
            const confirmBtn = document.getElementById('confirmActionBtn');
            document.getElementById('confirmTitle').innerText = "Restore Service";
            document.getElementById('confirmDesc').innerText = "Return this service to the active catalog?";

            confirmBtn.onclick = function () {
                const formData = new FormData();
                formData.append('save_settings', '1');
                formData.append('action', 'restore_catalog_service');
                formData.append('service_id', id);
                formData.append('active_tab', 'services');

                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                }).then(() => window.location.reload());
            };

            document.getElementById('confirmActionModal').classList.add('flex');
        }

        // Add Service Form Handler
        document.getElementById('addServiceForm')?.addEventListener('submit', function (e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('save_settings', '1');
            formData.append('action', 'add_catalog_service');
            formData.append('active_tab', 'services');

            fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        window.location.reload();
                    }
                });
        });
    </script>

    <!-- Restriction Modal (Sidebar-Aware) -->
    <div id="subModal" class="<?= $is_restricted ? 'active hard-lock' : '' ?>">
        <div
            class="glass-card max-w-md w-full p-10 text-center animate-in zoom-in duration-300 relative shadow-[0_0_100px_rgba(140,43,238,0.15)] border-primary/20">
            <div
                class="size-20 rounded-3xl bg-primary/10 border border-primary/20 flex items-center justify-center mx-auto mb-8">
                <span class="material-symbols-outlined text-4xl text-primary">lock</span>
            </div>
            <h3 class="text-2xl font-black italic uppercase tracking-tighter text-white mb-3">Subscription Required</h3>
            <p
                class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 mb-10 leading-relaxed italic px-4">
                Access to branding and facility configuration is restricted. Your status is <span
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