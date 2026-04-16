<?php
require_once 'db.php';

$gym_slug = $_GET['gym'] ?? '';
$is_preview = isset($_GET['preview']);

if (empty($gym_slug) && !$is_preview) {
    header("Location: index.php");
    exit;
}

// Fetch Tenant Page Data (Support Preview Mode without Slug)
if (empty($gym_slug) && isset($_GET['preview'])) {
    $page = [
        'page_title' => 'Sample Gym Name',
        'theme_color' => '#8c2bee',
        'secondary_color' => '#a1a1aa',
        'bg_color' => '#0a090d',
        'font_family' => 'Lexend',
        'about_text' => 'Enter your facility description to see it here...',
        'contact_text' => 'Your location will be displayed here.',
        'gym_email' => 'contact@gym.com',
        'gym_contact' => '123-456-7890'
    ];
    $configs = [];
    $gym_details = [
        'opening_time' => '',
        'closing_time' => '',
        'max_capacity' => 0,
        'has_lockers' => 0,
        'has_shower' => 0,
        'has_parking' => 0,
        'has_wifi' => 0
    ];
} else {
    // Fetch User ID from page_slug in system_settings
    $stmtSlug = $pdo->prepare("SELECT user_id FROM system_settings WHERE setting_key = 'page_slug' AND setting_value = ?");
    $stmtSlug->execute([$gym_slug]);
    $user_id = $stmtSlug->fetchColumn();

    if (!$user_id) {
        die("Gym page not found or is currently inactive.");
    }

    // Fetch All Settings for this User
    $stmtSettings = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE user_id = ?");
    $stmtSettings->execute([$user_id]);
    $configs = $stmtSettings->fetchAll(PDO::FETCH_KEY_PAIR);

    // Fetch Gym Details linked to this owner
    $stmtGym = $pdo->prepare("SELECT *, profile_picture as gym_logo, email as gym_email, contact_number as gym_contact FROM gyms WHERE owner_user_id = ? LIMIT 1");
    $stmtGym->execute([$user_id]);
    $gym_info = $stmtGym->fetch();

    if (!$gym_info) {
        die("Gym details not found.");
    }

    // Map system_settings to the $page structure used by existing logic
    $page = [
        'gym_id' => $gym_info['gym_id'],
        'page_slug' => $gym_slug,
        'page_title' => $configs['system_name'] ?? $gym_info['gym_name'],
        'logo_path' => $configs['system_logo'] ?? $gym_info['gym_logo'],
        'theme_color' => $configs['theme_color'] ?? '#8c2bee',
        'secondary_color' => $configs['secondary_color'] ?? '#a1a1aa',
        'text_color' => $configs['text_color'] ?? '#d1d5db',
        'bg_color' => $configs['bg_color'] ?? '#0a090d',
        'font_family' => $configs['font_family'] ?? 'Lexend',
        'about_text' => $gym_info['description'] ?? '',
        'app_download_link' => $configs['app_download_link'] ?? '',
        'gym_logo' => $gym_info['gym_logo'],
        'gym_email' => $gym_info['gym_email'],
        'gym_contact' => $gym_info['gym_contact'],
        'gym_name' => $gym_info['gym_name']
    ];

    $portal_suspended = false;

    // 1. Check Technical Inactive Setting
    if (($configs['is_active'] ?? '1') === '0') {
        $portal_suspended = true;
    }

    // 2. Check Gym Table Status (New: Explicit check for Suspended/Deactivated)
    if (isset($gym_info['status']) && in_array($gym_info['status'], ['Suspended', 'Deactivated', 'Deleted'])) {
        $portal_suspended = true;
    }

    // 3. Subscription Verification Logic (Takedown Check)
    if (!$portal_suspended) {
        $stmtSub = $pdo->prepare("SELECT subscription_status, next_billing_date, payment_term FROM client_subscriptions WHERE gym_id = ? AND subscription_status IN ('Active', 'Suspended') ORDER BY created_at DESC LIMIT 1");
        $stmtSub->execute([$page['gym_id']]);
        $sub = $stmtSub->fetch();

        if (!$sub || strtolower($sub['subscription_status']) !== 'active') {
            $portal_suspended = true;
        } else {
            // Redundant check for safety: Even if status is "Active", check if payment is >3 days overdue
            if ($sub['payment_term'] === 'Monthly' && $sub['next_billing_date']) {
                $now = strtotime('today');
                $due = strtotime($sub['next_billing_date']);
                if (($now - $due) / (60 * 60 * 24) > 3) {
                    $portal_suspended = true;
                }
            }
        }
    }

    if ($portal_suspended) {
        $theme_color = $configs['theme_color'] ?? '#8c2bee';
        $bg_color = '#050508'; 
        
        $hex = str_replace("#", "", $theme_color);
        if(strlen($hex) == 3) {
            $r = hexdec(substr($hex,0,1).substr($hex,0,1));
            $g = hexdec(substr($hex,1,1).substr($hex,1,1));
            $b = hexdec(substr($hex,2,1).substr($hex,2,1));
        } else {
            $r = hexdec(substr($hex,0,2));
            $g = hexdec(substr($hex,2,2));
            $b = hexdec(substr($hex,4,2));
        }
        $theme_rgb = "$r, $g, $b";
        ?>
        <!DOCTYPE html>
        <html class="dark" lang="en">
        <head>
            <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
            <title>Access Restricted | Horizon Systems</title>
            <script src="https://cdn.tailwindcss.com"></script>
            <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;600;700;900&display=swap" rel="stylesheet"/>
            <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
            <style>
                body { 
                    font-family: 'Lexend', sans-serif; 
                    background-color: <?= $bg_color ?> !important; 
                    color: white;
                    margin: 0;
                    overflow: hidden;
                    position: relative;
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }
                .dark-overlay {
                    position: fixed;
                    inset: 0;
                    background: radial-gradient(circle at 50% 50%, rgba(<?= $theme_rgb ?>, 0.08) 0%, #050508 100%);
                    z-index: 0;
                }
                .hero-glow { 
                    position: fixed;
                    inset: 0;
                    background: radial-gradient(circle at 50% 0%, rgba(<?= $theme_rgb ?>, 0.15) 0%, transparent 50%);
                    z-index: 1;
                }
                .glass-card { 
                    background: rgba(255, 255, 255, 0.03); 
                    backdrop-filter: blur(32px); 
                    border: 1px solid rgba(255, 255, 255, 0.08); 
                    border-radius: 32px;
                    box-shadow: 0 30px 60px -15px rgba(0, 0, 0, 0.5);
                }
            </style>
        </head>
        <body class="p-6 text-center">
            <div class="dark-overlay"></div>
            <div class="hero-glow"></div>

            <div class="fixed top-[-10%] left-[-10%] size-[500px] rounded-full blur-[130px] pointer-events-none opacity-30" style="background-color: rgb(<?= $theme_rgb ?>); z-index: 2;"></div>
            <div class="fixed bottom-[-10%] right-[-10%] size-[400px] rounded-full blur-[100px] pointer-events-none opacity-20" style="background-color: rgb(<?= $theme_rgb ?>); z-index: 2;"></div>
            
            <div class="max-w-lg w-full text-center relative" style="z-index: 10;">
                <div class="mb-8">
                    <div class="size-16 rounded-2xl bg-white/5 border border-white/10 flex items-center justify-center mx-auto relative shadow-xl">
                        <div class="absolute inset-0 blur-2xl rounded-full opacity-40" style="background-color: rgb(<?= $theme_rgb ?>)"></div>
                        <span class="material-symbols-outlined text-3xl relative z-10" style="color: <?= $theme_color ?>; font-variation-settings: 'FILL' 1, 'wght' 200;">lock</span>
                    </div>
                </div>

                <h1 class="text-4xl md:text-5xl font-black text-white uppercase italic tracking-tighter mb-8 leading-none">
                    SERVICE <span style="color: <?= $theme_color ?>" class="italic">RESTRICTED</span>
                </h1>
                
                <div class="glass-card p-8 md:p-10 mb-10">
                    <p class="text-[10px] font-black uppercase tracking-[0.3em] mb-6 opacity-80" style="color: <?= $theme_color ?>">Portal Notification</p>
                    <p class="text-xs md:text-sm text-gray-300 font-medium leading-relaxed italic mx-auto">
                        This gym portal is currently undergoing administrative maintenance or the professional subscription has been suspended. 
                        Full access for registration and member login is temporarily unavailable.
                    </p>
                </div>

                <div class="flex flex-col items-center gap-8">
                    <p class="text-[9px] text-gray-500 font-bold uppercase tracking-[0.3em] mb-[-12px]">If you have concerns, you may contact:</p>
                    <div class="flex flex-wrap items-center justify-center gap-8 text-[10px] font-bold uppercase tracking-widest text-gray-400">
                        <?php if (!empty($page['gym_email'])): ?>
                            <a href="mailto:<?= htmlspecialchars($page['gym_email']) ?>" class="flex items-center gap-2 hover:text-white transition-all">
                                <span class="material-symbols-outlined text-base">mail</span>
                                <?= htmlspecialchars($page['gym_email']) ?>
                            </a>
                        <?php endif; ?>
                        <?php if (!empty($page['gym_contact'])): ?>
                            <a href="tel:<?= htmlspecialchars($page['gym_contact']) ?>" class="flex items-center gap-2 hover:text-white transition-all">
                                <span class="material-symbols-outlined text-base">call</span>
                                <?= htmlspecialchars($page['gym_contact']) ?>
                            </a>
                        <?php endif; ?>
                    </div>

                    <div class="h-px w-24 bg-gradient-to-r from-transparent via-white/20 to-transparent"></div>
                    
                    <p class="text-[9px] font-black text-white/50 uppercase tracking-[0.6em] transition-all">
                        &copy; 2026 HORIZON SYSTEM
                    </p>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    // Fetch Operational Details
    $stmtDetails = $pdo->prepare("SELECT * FROM gym_details WHERE gym_id = ?");
    $stmtDetails->execute([$page['gym_id']]);
    $gym_details = $stmtDetails->fetch() ?: [
        'opening_time' => '',
        'closing_time' => '',
        'max_capacity' => 0,
        'has_lockers' => 0,
        'has_shower' => 0,
        'has_parking' => 0,
        'has_wifi' => 0
    ];

    // Fetch Gym Membership Plans (Specific to Gym or Global)
    $stmtMembership = $pdo->prepare("SELECT mp.*, mpt.type_name 
                                    FROM membership_plans mp 
                                    JOIN membership_plan_types mpt ON mp.plan_type_id = mpt.plan_type_id 
                                    WHERE mp.gym_id = ? AND mp.is_active = 1 
                                    ORDER BY mp.sort_order ASC, mp.price ASC");
    $stmtMembership->execute([$page['gym_id']]);
    $membership_plans = $stmtMembership->fetchAll();
}

// Map CMS Content with Fallbacks
$cms = [
    'hero_title' => $configs['portal_hero_title'] ?? ('Elevate Your <br /> <span class="text-gradient px-4 -ml-4">Fitness</span> at <br class="md:hidden" /> ' . htmlspecialchars($page['page_title'] ?? $page['gym_name'])),
    'hero_subtitle' => $configs['portal_hero_subtitle'] ?? 'Discover a premium workout experience powered by Horizon\'s elite technology and world-class coaching staff.',
    'features_title' => $configs['portal_features_title'] ?? 'Premium Training.<br /> <span class="text-gradient">Elite Management.</span>',
    'features_desc' => $configs['portal_features_desc'] ?? 'Access our elite workout tracking and world-class management platform. For membership inquiries and registrations, please visit our front desk or register online to get started.',
    'philosophy_title' => $configs['portal_philosophy_title'] ?? ('Modern technology meets <br /> <span class="text-gradient">' . (strpos($configs['portal_philosophy_title'] ?? '', 'dedication') !== false ? '' : 'unwavering dedication.') . '</span>'),
    'philosophy_desc' => $configs['portal_philosophy_desc'] ?? 'Experience fitness like never before with our cutting-edge multi-tenant facility.',
    'hero_label' => $configs['portal_hero_label'] ?? 'Open for Membership',
    'features_label' => $configs['portal_features_label'] ?? 'Experience the Difference',
    'philosophy_label' => $configs['portal_philosophy_label'] ?? 'The Philosophy',
    'plans_title' => $configs['portal_plans_title'] ?? 'Membership Plans',
    'plans_subtitle' => $configs['portal_plans_subtitle'] ?? ('Select a plan to start your journey at ' . htmlspecialchars($page['gym_name'])),
    'footer_links_title' => $configs['portal_footer_links_title'] ?? 'Quick Links',
    'footer_contact_title' => $configs['portal_footer_contact_title'] ?? 'Contact Facility',
    'footer_app_title' => $configs['portal_footer_app_title'] ?? 'Get the App'
];

// Refined Logic for Philosophy Title Fallback
if (empty($configs['portal_philosophy_title'])) {
    $cms['philosophy_title'] = 'Modern technology meets <br /> <span class="text-gradient">unwavering dedication.</span>';
} else {
    $cms['philosophy_title'] = nl2br(htmlspecialchars($configs['portal_philosophy_title']));
}

if (empty($configs['portal_hero_title'])) {
    $cms['hero_title'] = 'Elevate Your <br /> <span class="text-gradient px-4 -ml-4">Fitness</span> at <br class="md:hidden" /> ' . htmlspecialchars($page['page_title'] ?? $page['gym_name']);
} else {
    $cms['hero_title'] = nl2br($configs['portal_hero_title']);
}

if (empty($configs['portal_features_title'])) {
    $cms['features_title'] = 'Premium Training.<br /> <span class="text-gradient">Elite Management.</span>';
} else {
    $cms['features_title'] = nl2br($configs['portal_features_title']);
}

$primary_color = $page['theme_color'] ?? '#8c2bee';
$secondary_color = $page['secondary_color'] ?? '#a1a1aa';
$bg_color = $page['bg_color'] ?? '#0a090d';
$font_family = $page['font_family'] ?? 'Lexend';

// Local APK Auto-Download Logic
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
$script_path = $_SERVER['SCRIPT_NAME'];
$script_dir = dirname($script_path);
$base_url = $protocol . "://" . $host . ($script_dir === '/' || $script_dir === '\\' ? '' : $script_dir);

// GitHub Hosted APK (Link to your actual repository's latest release)
$github_download_url = "https://github.com/horizon-fitness/HorizonSystems/releases/latest/download/app-debug.apk";
$final_download_link = (!empty($page['app_download_link']) && strpos($page['app_download_link'], 'drive.google.com') === false)
    ? $page['app_download_link']
    : $github_download_url;

function hexToRgb($hex)
{
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
$primary_rgb = hexToRgb($primary_color);
?>
<!DOCTYPE html>
<html class="dark" lang="en">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title><?= htmlspecialchars($page['page_title'] ?? $page['gym_name']) ?> | Horizon Systems</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin="" />
    <link
        href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@300;400;500;600&family=Lexend:wght@300;400;500;700;900&display=swap"
        rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap"
        rel="stylesheet" />
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "<?= $primary_color ?>",
                        "primary-dark": "<?= $page['theme_color'] ?? '#5e0eb3' ?>",
                        "background-dark": "<?= $bg_color ?>",
                        "surface-dark": "rgba(21, 21, 24, 0.4)",
                        "text-secondary": "<?= $secondary_color ?>"
                    },
                    fontFamily: {
                        "display": ["Lexend", "sans-serif"],
                        "sans": ["Plus Jakarta Sans", "Inter", "sans-serif"]
                    },
                    borderRadius: { 'custom': '12px' }
                },
            },
        }
    </script>
    <style id="dynamic-styles">
        :root {
            --pg-bg:
                <?= $bg_color ?>
            ;
            --pg-primary:
                <?= $primary_color ?>
            ;
            --pg-secondary:
                <?= $secondary_color ?>
            ;
            --pg-font: '<?= $font_family ?>', 'Plus Jakarta Sans', sans-serif;
        }

        html {
            scroll-behavior: smooth;
            scrollbar-width: none; /* Firefox */
            -ms-overflow-style: none; /* IE/Edge */
        }

        /* Invisible Scroll System */
        * {
            scrollbar-width: none;
            -ms-overflow-style: none;
        }

        *::-webkit-scrollbar {
            display: none;
        }

        body {
            background-color: var(--pg-bg);
            color: #f3f4f6;
            font-family: var(--pg-font);
        }

        .glass-nav {
            background: rgba(5, 5, 5, 0.2);
            backdrop-filter: blur(8px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.02);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .glass-nav.scrolled {
            background: rgba(5, 5, 5, 0.9);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            padding-top: 0.75rem;
            padding-bottom: 0.75rem;
        }

        .dashboard-window {
            background: #08080a;
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 40px 100px -20px rgba(0, 0, 0, 1);
        }

        .metric-card {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 12px;
            padding: 1.25rem;
            position: relative;
        }

        .text-gradient {
            background: linear-gradient(to right, #ffffff 10%, #bf80ff 50%, var(--pg-primary) 95%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            display: inline-block;
            filter: drop-shadow(0 0 25px rgba(<?= $primary_rgb ?>, 0.4));
        }

        .hero-glow {
            background: radial-gradient(circle at 50% -10%, rgba(<?= $primary_rgb ?>, 0.18), transparent 70%);
        }

        /* Static Elite Mobile Mockup (iPhone 15 Pro Style) */
        .showcase-container {
            position: relative;
            width: 320px;
            height: 640px;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .mobile-frame {
            width: 300px;
            height: 610px;
            background: #08080a;
            border-radius: 48px;
            padding: 12px;
            position: relative;
            box-shadow:
                0 0 0 2px #1a1a1a,
                0 30px 60px -12px rgba(0, 0, 0, 0.8),
                inset 0 0 2px 1px rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        .mobile-screen {
            width: 100%;
            height: 100%;
            background: #000;
            border-radius: 38px;
            overflow: hidden;
            position: relative;
            display: flex;
            flex-direction: column;
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        .mobile-island {
            position: absolute;
            top: 18px;
            left: 50%;
            transform: translateX(-50%);
            width: 90px;
            height: 28px;
            background: #000;
            border-radius: 20px;
            z-index: 50;
            border: 0.5px solid rgba(255, 255, 255, 0.05);
        }

        .floating-action-card {
            position: absolute;
            background: rgba(15, 15, 18, 0.6);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 20px;
            padding: 16px;
            z-index: 60;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }

        /* APK App Mode Adjustments (High-Fidelity Mobile Landing Replica) */
        <?php if (isset($_GET['preview'])): ?>
            header {
                display: none !important;
            }

            main {
                padding-top: 0 !important;
            }

            .hero-section {
                text-align: center;
            }

            .banner-card {
                width: 100%;
                height: 200px;
                border-radius: 20px;
                margin-bottom: 24px;
                overflow: hidden;
                box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.5);
                background: linear-gradient(135deg, var(--pg-primary), transparent);
                display: flex;
                items-center: center;
                justify-content: center;
                border: 1px solid rgba(255, 255, 255, 0.05);
            }

            /* Android UI Elements */
            .android-status-bar {
                height: 38px;
                padding: 0 20px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                font-size: 11px;
                font-weight: 700;
                color: white;
                z-index: 100;
                width: 100%;
                background: transparent;
            }

            .android-nav-bar {
                height: 24px;
                display: flex;
                justify-content: center;
                align-items: center;
                width: 100%;
                position: fixed;
                bottom: 0;
                background: transparent;
                z-order: 100;
            }

            .android-nav-line {
                width: 80px;
                height: 3px;
                background: rgba(255, 255, 255, 0.4);
                border-radius: 99px;
            }

        <?php endif; ?>
        /* Modal Styles */
        #qr-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 2000;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(16px);
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: all 0.4s ease;
        }

        #qr-modal.active {
            display: flex;
            opacity: 1;
        }

        .modal-content {
            background: rgba(15, 15, 20, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            /* Reduced from 24px for a sharper, modern edge */
            box-shadow: 0 40px 100px -20px rgba(0, 0, 0, 0.8),
                0 0 80px -10px rgba(<?= $primary_rgb ?>, 0.15);
            transform: scale(0.9);
            transition: all 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
            overflow: hidden;
            max-height: 85vh;
            /* Slightly more restrictive to ensure space */
            overflow-y: auto;
        }

        #qr-modal.active .modal-content {
            transform: translateY(0) scale(1);
        }

        .qr-glow {
            position: relative;
        }

        .qr-glow::before {
            content: '';
            position: absolute;
            inset: -20px;
            background: radial-gradient(circle, rgba(<?= $primary_rgb ?>, 0.3) 0%, transparent 70%);
            filter: blur(30px);
            opacity: 0.6;
            z-index: -1;
            animation: pulse-glow 4s ease-in-out infinite;
        }

        @keyframes pulse-glow {

            0%,
            100% {
                opacity: 0.4;
                transform: scale(1);
            }

            50% {
                opacity: 0.7;
                transform: scale(1.1);
            }
        }

        /* Premium Visible Scrollbar Override (from index) */
        .custom-scrollbar::-webkit-scrollbar {
            display: block !important;
            height: 4px;
        }
        .custom-scrollbar::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.01);
            border-radius: 20px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, 0.02);
            transition: all 0.3s ease;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: rgba(<?= $primary_rgb ?>, 0.3);
        }
        .custom-scrollbar {
            scrollbar-width: thin !important;
            scrollbar-color: rgba(255, 255, 255, 0.05) transparent !important;
            -ms-overflow-style: none;
        }

        #membership-plans-grid { cursor: grab; user-select: none; }
        #membership-plans-grid:active { cursor: grabbing; }

        .btn-modern {
            background: linear-gradient(135deg, var(--pg-primary) 0%, rgba(<?= $primary_rgb ?>, 0.8) 100%);
            box-shadow: 0 10px 30px -5px rgba(<?= $primary_rgb ?>, 0.4);
            transition: all 0.3s ease;
        }

        .btn-modern:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 40px -5px rgba(<?= $primary_rgb ?>, 0.6);
        }

        /* Mockup Login Field Styles */
        .mockup-input-container {
            position: relative;
            margin-bottom: 1.25rem;
        }

        .mockup-input-container label {
            display: block;
            font-size: 10px;
            font-weight: 700;
            color: #fff;
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .mockup-input-wrapper {
            position: relative;
        }

        .mockup-input {
            width: 100%;
            height: 48px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.03);
            border-radius: 14px;
            padding: 0 16px 0 44px;
            color: #fff;
            font-size: 12px;
            transition: all 0.3s ease;
        }

        .mockup-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(255, 255, 255, 0.3);
            font-size: 18px;
        }

        .mockup-btn {
            width: 100%;
            height: 52px;
            border-radius: 26px;
            background: var(--pg-primary);
            color: #fff;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 0.01em;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 10px 25px -5px rgba(<?= $primary_rgb ?>, 0.4);
            margin-top: 1.5rem;
        }

        .mockup-separator {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 1.5rem 0;
            color: rgba(255, 255, 255, 0.2);
            font-size: 10px;
            font-weight: 700;
        }

        .mockup-separator::before,
        .mockup-separator::after {
            content: "";
            flex: 1;
            height: 1px;
            background: rgba(255, 255, 255, 0.05);
        }

        .mockup-footer-btn {
            width: 100%;
            height: 48px;
            border-radius: 14px;
            border: 1px solid rgba(255, 255, 255, 0.05);
            background: rgba(255, 255, 255, 0.01);
            color: #fff;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
    </style>
</head>

<body class="antialiased min-h-screen flex flex-col font-display selection:bg-primary/30 selection:text-white">

    <nav id="topNav" class="glass-nav fixed top-0 w-full z-50">
        <div class="max-w-7xl mx-auto px-6 py-4 transition-all duration-300">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-12">
                    <div class="flex items-center gap-3">
                        <div
                            class="size-10 bg-primary/20 rounded-lg flex items-center justify-center border border-primary/30 overflow-hidden">
                            <?php
                            $logo_src = !empty($page['logo_path']) ? $page['logo_path'] : ($page['gym_logo'] ?? '');
                            ?>
                            <?php if (!empty($logo_src)): ?>
                                <img src="<?= htmlspecialchars($logo_src) ?>" class="size-full object-cover">
                            <?php else: ?>
                                <span class="material-symbols-outlined text-primary">blur_on</span>
                            <?php endif; ?>
                        </div>
                        <h2 class="text-xl font-display font-bold text-white uppercase italic tracking-tighter">
                            <?= htmlspecialchars($page['page_title'] ?? $page['gym_name']) ?></h2>
                    </div>

                    <div
                        class="hidden md:flex items-center gap-8 text-[11px] font-display font-bold uppercase tracking-widest text-gray-500">
                        <a href="#" class="hover:text-white transition-all">Home</a>
                        <a href="#about" class="hover:text-white transition-all">About</a>
                        <a href="#contact" class="hover:text-white transition-all">Contact</a>
                    </div>
                </div>

                <div class="flex items-center gap-4">
                    <a href="member/member_register.php?gym=<?= $gym_slug ?>"
                        class="font-display bg-primary hover:bg-primary-dark text-white px-5 py-2.5 rounded-custom text-[11px] font-bold uppercase tracking-widest transition-all shadow-lg shadow-primary/20">
                        Register
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <main class="hero-glow flex-1">
        <!-- Premium Ambient Glows -->
        <div
            class="fixed top-[-10%] right-[-5%] size-[600px] bg-primary/10 rounded-full blur-[120px] -z-10 pointer-events-none">
        </div>
        <div
            class="fixed bottom-[10%] left-[-5%] size-[500px] bg-primary/5 rounded-full blur-[100px] -z-10 pointer-events-none">
        </div>

        <section class="relative pt-20 pb-32 md:pt-32 md:pb-40 px-6 flex items-center justify-center">
            <div
                class="max-w-7xl w-full grid md:grid-cols-2 gap-20 items-center relative z-10 text-center md:text-left">
                <div>
                    <div id="hero-label-display"
                        class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-white/5 border border-white/10 text-primary text-[10px] font-black uppercase tracking-[0.2em] mb-8">
                        <span class="relative flex h-2 w-2">
                            <span
                                class="animate-ping absolute inline-flex h-full w-full rounded-full bg-primary opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2 w-2 bg-primary"></span>
                        </span>
                        <?= htmlspecialchars($cms['hero_label']) ?>
                    </div>

                    <h1 id="hero-title-display"
                        class="text-6xl md:text-8xl font-display font-black leading-[0.95] tracking-tighter text-white uppercase italic mb-8">
                        <?= $cms['hero_title'] ?>
                    </h1>

                    <p id="hero-subtitle-display" class="text-lg text-gray-400 font-medium leading-relaxed max-w-2xl mx-auto md:mx-0 mb-10 italic">
                        <?= htmlspecialchars($cms['hero_subtitle']) ?>
                    </p>

                    <div class="flex flex-wrap items-center justify-center md:justify-start gap-4">
                        <a href="member/member_register.php?gym=<?= $gym_slug ?>"
                            class="font-display h-16 px-10 bg-primary text-white font-bold rounded-custom text-xs uppercase tracking-widest hover:scale-105 transition-all shadow-xl shadow-primary/20 flex items-center justify-center">
                            Join Now
                        </a>
                        <button onclick="openQRModal()"
                            class="font-display h-16 px-10 bg-white/5 hover:bg-white/10 text-white border border-white/10 font-bold rounded-custom text-xs uppercase tracking-widest transition-all flex items-center justify-center gap-3">
                            <span class="material-symbols-outlined text-xl">smartphone</span>
                            Get the App
                        </button>
                    </div>
                </div>

                <!-- Static Elite Mobile Mockup (iPhone 15 Pro) -->
                <div class="hidden md:flex justify-center md:justify-end pr-10">
                    <div class="showcase-container">
                        <!-- Floating Card 1: Check-in (Static) -->
                        <div class="floating-action-card -top-4 -left-20">
                            <div class="flex items-center gap-3">
                                <div
                                    class="size-10 rounded-full bg-emerald-500/20 text-emerald-500 flex items-center justify-center">
                                    <span class="material-symbols-outlined text-lg">verified_user</span>
                                </div>
                                <div class="pr-4">
                                    <p class="text-[9px] font-black uppercase text-white tracking-widest">Secure Access
                                    </p>
                                    <p class="text-[8px] text-gray-500 font-medium">Biometric Ready</p>
                                </div>
                            </div>
                        </div>

                        <!-- Main Device: iPhone 15 Pro Style -->
                        <div class="mobile-frame">
                            <div class="mobile-island"></div>
                            <div class="mobile-screen">
                                <!-- Status Bar -->
                                <div
                                    class="flex justify-between items-center px-8 pt-6 pb-2 text-[11px] font-bold text-white/50">
                                    <span>9:41</span>
                                    <div class="flex gap-2 items-center">
                                        <span class="material-symbols-outlined text-[14px]">signal_cellular_4_bar</span>
                                        <span class="material-symbols-outlined text-[14px]">wifi</span>
                                        <div class="w-6 h-3 rounded-sm border border-white/20 relative">
                                            <div class="absolute inset-[1.5px] bg-white rounded-[0.5px] w-[80%]"></div>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex-1 px-8 pt-8 flex flex-col overflow-hidden">
                                    <!-- Branding -->
                                    <div class="text-center mb-10">
                                        <h5 class="text-[12px] font-black tracking-[0.2em] text-white/90 uppercase mb-6">
                                            <?= htmlspecialchars($page['page_title'] ?? $page['gym_name']) ?>
                                        </h5>

                                        <!-- Circular Logo with lightning/diag icon -->
                                        <div class="size-20 rounded-full mx-auto relative flex items-center justify-center border border-white/5 shadow-2xl mb-8">
                                            <div class="absolute inset-0 bg-gradient-to-br from-primary via-primary/80 to-transparent rounded-full opacity-20"></div>
                                            <div class="size-16 rounded-full border border-primary/40 flex items-center justify-center bg-black/40 backdrop-blur-md">
                                                <span class="material-symbols-outlined text-primary text-3xl font-light" style="font-variation-settings: 'wght' 200;">bolt</span>
                                            </div>
                                        </div>

                                        <h4 class="text-3xl font-display font-black text-white leading-none tracking-tight uppercase mb-3">
                                            WELCOME BACK
                                        </h4>
                                        <p class="text-[11px] text-gray-500 font-medium tracking-tight">
                                            Enter your credentials to access your account.
                                        </p>
                                    </div>

                                    <!-- Mockup Login Form -->
                                    <div class="mockup-form">
                                        <div class="mockup-input-container">
                                            <label>Username:</label>
                                            <div class="mockup-input-wrapper">
                                                <span class="material-symbols-outlined mockup-icon">person</span>
                                                <div class="mockup-input flex items-center text-white/30">Enter username</div>
                                            </div>
                                        </div>

                                        <div class="mockup-input-container">
                                            <label>Password:</label>
                                            <div class="mockup-input-wrapper">
                                                <span class="material-symbols-outlined mockup-icon">lock</span>
                                                <div class="mockup-input flex items-center text-white/30">Enter password</div>
                                                <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-white/20 text-lg">visibility</span>
                                            </div>
                                        </div>

                                        <div class="flex items-center justify-between mt-2">
                                            <div class="flex items-center gap-2">
                                                <div class="size-4 rounded border border-primary bg-primary/20 flex items-center justify-center">
                                                    <span class="material-symbols-outlined text-[12px] text-white">check</span>
                                                </div>
                                                <span class="text-[10px] font-bold text-gray-500">Remember Me</span>
                                            </div>
                                            <div class="text-[10px] font-bold text-gray-400">Forgot Password?</div>
                                        </div>

                                        <div class="mockup-btn">
                                            Login
                                        </div>

                                        <div class="mockup-separator">OR</div>

                                        <div class="mockup-footer-btn">
                                            <span class="material-symbols-outlined text-lg opacity-40">apps</span>
                                            <span>Switch Gym</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Nav indicator bar (iPhone style) -->
                                <div class="h-6 flex items-center justify-center pb-2">
                                    <div class="w-20 h-1 bg-white/10 rounded-full"></div>
                                </div>
                            </div>
                        </div>

                        <!-- Floating Card 2: Upcoming Session (Static) -->
                        <div class="floating-action-card -bottom-6 -right-16 overflow-hidden">
                            <div class="absolute inset-0 bg-primary/5 -z-10"></div>
                            <div class="flex items-center gap-4">
                                <div
                                    class="size-10 rounded-xl bg-primary/20 text-primary flex items-center justify-center border border-primary/20">
                                    <span class="material-symbols-outlined text-xl">token</span>
                                </div>
                                <div class="pr-6">
                                    <p class="text-[9px] font-black uppercase text-white tracking-widest">Smart Login</p>
                                    <p class="text-[8px] text-primary font-bold uppercase tracking-tighter">Session
                                        Active</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>


        <section class="max-w-7xl mx-auto px-6 pb-32" id="portal-info">
            <div class="dashboard-window w-full rounded-2xl p-8 md:p-12 overflow-hidden relative mb-12">
                <div
                    class="absolute -top-24 -right-24 w-64 h-64 bg-primary/10 blur-[100px] rounded-full pointer-events-none">
                </div>

                <div class="grid lg:grid-cols-2 gap-16 items-center relative z-10">
                    <div>
                        <div id="features-label-display"
                            class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-white/5 border border-white/10 text-primary text-[10px] font-black uppercase tracking-[0.2em] mb-6">
                            <?= htmlspecialchars($cms['features_label']) ?>
                        </div>
                        <h2 id="features-title-display" class="text-4xl font-display font-black text-white uppercase italic leading-tight mb-8">
                            <?= $cms['features_title'] ?>
                        </h2>
                        <p id="features-desc-display" class="text-gray-400 italic leading-relaxed mb-10">
                            <?= nl2br(htmlspecialchars($cms['features_desc'])) ?>
                        </p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div class="metric-card border border-primary/20">
                            <p class="text-[9px] text-gray-500 uppercase font-black mb-3 tracking-widest text-primary">
                                Opening Hours</p>
                            <div class="flex justify-between items-center">
                                <span id="opening-time-display"
                                    class="text-2xl font-black text-white italic"><?= !empty($gym_details['opening_time']) ? date('h:i A', strtotime($gym_details['opening_time'])) : '--:--' ?></span>
                                <span class="material-symbols-outlined text-primary/40 text-2xl">schedule</span>
                            </div>
                            <p class="text-[8px] text-gray-600 uppercase font-bold mt-3">Until
                                <?= !empty($gym_details['closing_time']) ? date('h:i A', strtotime($gym_details['closing_time'])) : '--:--' ?></p>
                        </div>

                        <div class="metric-card border border-primary/20">
                            <p class="text-[9px] text-gray-500 uppercase font-black mb-3 tracking-widest text-primary">
                                Member Capacity</p>
                            <div class="flex justify-between items-center">
                                <span id="max-capacity-display"
                                    class="text-2xl font-black text-white italic"><?= $gym_details['max_capacity'] ?: 'N/A' ?></span>
                                <span class="material-symbols-outlined text-primary/40 text-2xl">groups</span>
                            </div>
                            <p class="text-[8px] text-gray-600 uppercase font-bold mt-3 text-emerald-500">Live
                                Availability</p>
                        </div>

                        <div class="metric-card md:col-span-2 border border-white/5">
                            <p class="text-[9px] text-gray-500 uppercase font-black mb-4 tracking-widest">Available
                                Amenities</p>
                            <div class="flex flex-wrap gap-3">
                                <div id="locker-tag"
                                    class="flex items-center gap-2 px-3 py-2 rounded-lg bg-white/5 border border-white/5 <?= ($gym_details['has_lockers']) ? '' : 'opacity-20 grayscale' ?>">
                                    <span class="material-symbols-outlined text-xs text-primary">lock</span>
                                    <span
                                        class="text-[9px] font-black uppercase tracking-widest text-gray-300">Lockers</span>
                                </div>
                                <div id="shower-tag"
                                    class="flex items-center gap-2 px-3 py-2 rounded-lg bg-white/5 border border-white/5 <?= ($gym_details['has_shower']) ? '' : 'opacity-20 grayscale' ?>">
                                    <span class="material-symbols-outlined text-xs text-primary">shower</span>
                                    <span
                                        class="text-[9px] font-black uppercase tracking-widest text-gray-300">Showers</span>
                                </div>
                                <div id="parking-tag"
                                    class="flex items-center gap-2 px-3 py-2 rounded-lg bg-white/5 border border-white/5 <?= ($gym_details['has_parking']) ? '' : 'opacity-20 grayscale' ?>">
                                    <span class="material-symbols-outlined text-xs text-primary">local_parking</span>
                                    <span
                                        class="text-[9px] font-black uppercase tracking-widest text-gray-300">Parking</span>
                                </div>
                                <div id="wifi-tag"
                                    class="flex items-center gap-2 px-3 py-2 rounded-lg bg-white/5 border border-white/5 <?= ($gym_details['has_wifi']) ? '' : 'opacity-20 grayscale' ?>">
                                    <span class="material-symbols-outlined text-xs text-primary">wifi</span>
                                    <span
                                        class="text-[9px] font-black uppercase tracking-widest text-gray-300">Wi-Fi</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="about"
            class="py-32 px-6 relative border-t border-white/5 bg-gradient-to-b from-transparent to-black/50">
            <div class="max-w-7xl mx-auto text-center">
                <div id="philosophy-label-display"
                    class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-white/5 border border-white/10 text-primary text-[10px] font-black uppercase tracking-[0.2em] mb-12">
                    <?= htmlspecialchars($cms['philosophy_label']) ?>
                </div>
                <h2 id="philosophy-title-display"
                    class="text-5xl md:text-7xl font-display font-black text-white uppercase italic leading-tight mb-12">
                    <?= $cms['philosophy_title'] ?>
                </h2>
                <div class="max-w-3xl mx-auto text-gray-400 italic text-xl leading-relaxed">
                    <p id="philosophy-desc-display">
                        <?= nl2br(htmlspecialchars($cms['philosophy_desc'])) ?>
                    </p>
                </div>
            </div>
        </section>

        <!-- New Section: Elite Membership Plans -->
        <section id="plans" class="py-32 px-6 relative border-t border-white/5">
            <div class="max-w-7xl mx-auto text-center">
                <div class="mb-16">
                    <div class="inline-flex items-center justify-center p-3 rounded-xl bg-primary/10 border border-primary/20 mb-6">
                        <span class="material-symbols-outlined text-primary">workspace_premium</span>
                    </div>
                    <h2 id="plans-title-display" class="text-4xl md:text-5xl font-display font-black text-white uppercase italic tracking-tighter mb-4">
                        <?= $cms['plans_title'] ?>
                    </h2>
                    <p id="plans-subtitle-display" class="text-[10px] text-gray-500 font-bold uppercase tracking-[0.3em]"><?= htmlspecialchars($cms['plans_subtitle']) ?></p>
                </div>

                <div id="membership-plans-grid" class="flex overflow-x-auto snap-x snap-mandatory gap-10 pt-14 pb-12 px-10 no-scrollbar custom-scrollbar scroll-smooth <?= count($membership_plans) <= 2 ? 'justify-center' : '' ?>">
                    <?php if (empty($membership_plans)): ?>
                        <div class="w-full max-w-2xl py-20 dashboard-window rounded-3xl opacity-50 text-center shrink-0">
                            <span class="material-symbols-outlined text-4xl mb-4">info</span>
                            <p class="text-xs font-bold uppercase tracking-widest">No membership plans available at this time.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($membership_plans as $plan): 
                            $badgeText = !empty($plan['featured_badge_text']) ? $plan['featured_badge_text'] : ( (strpos(strtoupper($plan['plan_name'] ?? ''), 'ELITE') !== false || strpos(strtoupper($plan['plan_name'] ?? ''), 'VIP') !== false) ? 'Recommended' : '' );
                        ?>
                        <div class="dashboard-window rounded-2xl p-10 flex flex-col text-left transition-all hover:scale-[1.02] hover:border-primary/40 duration-500 shrink-0 w-[85%] md:w-[400px] snap-start <?= !empty($badgeText) ? 'border-primary/30 relative' : '' ?>">
                            <?php if (!empty($badgeText)): ?>
                                <div class="absolute -top-3 right-8 bg-primary text-white text-[8px] font-black uppercase tracking-[0.2em] px-3 py-1 rounded-full shadow-lg shadow-primary/20"><?= htmlspecialchars($badgeText) ?></div>
                            <?php endif; ?>
                            
                            <h3 class="text-xl font-display font-black text-white uppercase italic mb-1"><?= htmlspecialchars($plan['plan_name']) ?></h3>
                            <p class="text-[9px] text-primary font-black uppercase tracking-widest mb-8"><?= htmlspecialchars($plan['type_name']) ?></p>
                            
                            <div class="mb-10">
                                <span class="text-4xl font-display font-black text-white">₱<?= number_format($plan['price'], 2) ?></span>
                                <span class="text-[10px] text-gray-600 font-bold uppercase tracking-widest">/ <?= !empty($plan['billing_cycle_text']) ? htmlspecialchars($plan['billing_cycle_text']) : htmlspecialchars($plan['duration_value']) . ' Days' ?></span>
                            </div>
                            
                            <ul class="space-y-4 mb-12 flex-grow">
                                <?php 
                                if (!empty($plan['features'])) {
                                    $featuresList = explode("\n", str_replace(["\r\n", "\r"], "\n", $plan['features']));
                                    foreach ($featuresList as $feature) {
                                        $feature = trim($feature);
                                        if (empty($feature)) continue;
                                        ?>
                                        <li class="flex items-center gap-3 text-xs text-gray-400 font-medium italic">
                                            <span class="material-symbols-outlined text-primary text-sm">check_circle</span> 
                                            <?= htmlspecialchars($feature) ?>
                                        </li>
                                        <?php
                                    }
                                } else {
                                    ?>
                                    <li class="flex items-center gap-3 text-xs text-gray-400 font-medium italic">
                                        <span class="material-symbols-outlined text-primary text-sm">check_circle</span> 
                                        <?= !empty($plan['description']) ? htmlspecialchars($plan['description']) : 'Full access to all facility equipment and amenities.' ?>
                                    </li>
                                    <?php
                                }
                                ?>
                                <?php if ($plan['session_limit']): ?>
                                <li class="flex items-center gap-3 text-xs text-gray-400 font-medium italic">
                                    <span class="material-symbols-outlined text-primary text-sm opacity-60">verified</span> 
                                    <?= htmlspecialchars($plan['session_limit']) ?> Total Sessions included
                                </li>
                                <?php endif; ?>
                            </ul>
                            
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </main>

    <footer id="contact" class="bg-[#08080a] border-t border-white/5 pt-24 pb-12 px-6">
        <div class="max-w-7xl mx-auto">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-16 mb-24">
                <div class="space-y-8">
                    <div>
                        <div class="flex items-center gap-3 mb-6">
                            <span class="material-symbols-outlined text-primary text-3xl">blur_on</span>
                            <h2
                                class="text-2xl font-display font-bold text-white uppercase italic tracking-tighter transition-all gym-name-display">
                                <?= htmlspecialchars($page['page_title'] ?? $page['gym_name']) ?></h2>
                        </div>
                        <p class="text-[10px] text-primary font-black uppercase tracking-[0.4em] mb-6">Expand Your
                            Horizon</p>
                        <p class="text-xs text-gray-500 font-medium leading-relaxed italic max-w-sm">
                            Powered by Horizon Systems. Elevating fitness center management through cutting-edge
                            technology.
                        </p>
                    </div>
                </div>

                <div class="flex flex-col gap-8">
                    <h4 id="footer-links-title"
                        class="text-sm font-display font-black text-white uppercase italic tracking-[0.2em] relative inline-block">
                        <?= htmlspecialchars($cms['footer_links_title']) ?>
                        <span class="absolute -bottom-2 left-0 w-8 h-0.5 bg-primary"></span>
                    </h4>
                    <div class="flex flex-col gap-6 text-xs font-bold text-gray-500 uppercase tracking-widest">
                        <a href="#" class="hover:text-primary transition-all">Home</a>
                        <a href="#about" class="hover:text-primary transition-all">About Us</a>
                        <a href="member/member_register.php?gym=<?= $gym_slug ?>"
                            class="hover:text-primary transition-all">Register</a>
                        <a href="#contact" class="hover:text-primary transition-all">Contact</a>
                    </div>
                </div>

                <div class="flex flex-col gap-8">
                    <h4 id="footer-contact-title"
                        class="text-sm font-display font-black text-white uppercase italic tracking-[0.2em] relative inline-block">
                        <?= htmlspecialchars($cms['footer_contact_title']) ?>
                        <span class="absolute -bottom-2 left-0 w-8 h-0.5 bg-primary"></span>
                    </h4>
                    <div class="space-y-8">
                        <div class="flex items-start gap-4">
                            <div
                                class="size-10 rounded-xl bg-white/5 border border-white/10 flex items-center justify-center text-primary transition-all duration-500">
                                <span class="material-symbols-outlined text-xl">location_on</span>
                            </div>
                            <p id="contact-text-display"
                                class="text-xs text-gray-500 font-medium leading-relaxed italic">
                                <?= nl2br(htmlspecialchars($page['contact_text'] ?? 'Visit us at our primary training facility.')) ?>
                            </p>
                        </div>
                        <div class="flex items-center gap-4">
                            <div
                                class="size-10 rounded-xl bg-white/5 border border-white/10 flex items-center justify-center text-primary transition-all duration-500">
                                <span class="material-symbols-outlined text-xl">call</span>
                            </div>
                            <p class="text-xs text-gray-500 font-medium uppercase tracking-widest">
                                <?= htmlspecialchars($page['gym_contact']) ?></p>
                        </div>
                        <div class="flex items-center gap-4">
                            <div
                                class="size-10 rounded-xl bg-white/5 border border-white/10 flex items-center justify-center text-primary transition-all duration-500">
                                <span class="material-symbols-outlined text-xl">mail</span>
                            </div>
                            <p class="text-xs text-gray-500 font-medium lowercase tracking-wider">
                                <?= htmlspecialchars($page['gym_email']) ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="pt-10 border-t border-white/5 text-center">
                <p class="text-[9px] font-bold text-gray-700 uppercase tracking-[0.5em]">
                    © 2026 <?= strtoupper(htmlspecialchars($page['page_title'] ?? $page['gym_name'])) ?>. SECURE
                    ENVIRONMENT. ALL RIGHTS RESERVED.
                </p>
            </div>
        </div>
    </footer>

    <!-- QR Download Modal -->
    <div id="qr-modal" class="fixed inset-0" onclick="event.target === this && closeQRModal()">
        <div
            class="modal-content dashboard-window max-w-[420px] w-full mx-4 rounded-2xl overflow-y-auto transform scale-95 transition-all duration-500">
            <div class="p-10 flex flex-col items-center relative">
                <button onclick="closeQRModal()"
                    class="absolute top-6 right-6 size-10 rounded-full bg-white/5 border border-white/10 flex items-center justify-center text-gray-400 hover:text-white transition-all hover:rotate-90">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>

                <div
                    class="size-16 rounded-2xl bg-primary/10 text-primary flex items-center justify-center mb-6 border border-primary/20">
                    <span class="material-symbols-outlined text-4xl relative">qr_code_scanner</span>
                </div>

                <h3 id="footer-app-title"
                    class="text-3xl font-display font-black text-white mb-3 tracking-tighter text-center uppercase italic">
                    <?= htmlspecialchars($cms['footer_app_title']) ?></h3>
                <p class="text-gray-500 text-sm mb-10 leading-relaxed text-center italic">
                    Scan the code below to unlock your premium training experience on the go.
                </p>

                <div class="p-6 bg-white rounded-3xl shadow-2xl transition-transform hover:scale-[1.02] duration-500">
                    <img id="qr-code-modal-img"
                        src="https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=<?= urlencode($final_download_link) ?>"
                        alt="QR Code" class="size-52 rounded-xl">
                </div>

                <!-- Manual Link Divider -->
                <div class="w-full flex items-center gap-4 mt-12 mb-6">
                    <div class="h-px flex-1 bg-white/5"></div>
                    <span class="text-[9px] font-black uppercase tracking-[0.3em] text-gray-700">Manual Link</span>
                    <div class="h-px flex-1 bg-white/5"></div>
                </div>

                <div class="w-full space-y-4">
                    <a href="<?= $final_download_link ?>" download
                        class="w-full h-16 bg-primary hover:bg-primary-dark rounded-xl text-white text-[11px] font-black uppercase tracking-[0.2em] flex items-center justify-center gap-4 transition-all shadow-[0_10px_40px_-10px_rgba(<?= $primary_rgb ?>,0.5)]">
                        <span class="material-symbols-outlined text-xl">download</span>
                        Direct Download APK
                    </a>
                    <p class="text-[9px] text-gray-600 font-bold uppercase tracking-[0.3em] text-center">Version 2.0.4 • 42.5 MB</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        const nav = document.getElementById('topNav');
        window.onscroll = function () {
            if (window.scrollY > 50) {
                nav.classList.add('scrolled');
            } else {
                nav.classList.remove('scrolled');
            }
        };

        function openQRModal() {
            const modal = document.getElementById('qr-modal');
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeQRModal() {
            const modal = document.getElementById('qr-modal');
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeQRModal();
        });

        // --- Drag-to-Scroll Engine (Matching Index) ---
        const slider = document.getElementById('membership-plans-grid');
        let isDown = false;
        let startX;
        let scrollLeft;

        if (slider) {
            slider.addEventListener('mousedown', (e) => {
                isDown = true;
                slider.style.scrollSnapType = 'none'; 
                slider.style.scrollBehavior = 'auto'; 
                startX = e.pageX - slider.offsetLeft;
                scrollLeft = slider.scrollLeft;
            });
            slider.addEventListener('mouseleave', () => {
                isDown = false;
                slider.style.scrollSnapType = 'x mandatory';
                slider.style.scrollBehavior = 'smooth';
            });
            slider.addEventListener('mouseup', () => {
                isDown = false;
                slider.style.scrollSnapType = 'x mandatory';
                slider.style.scrollBehavior = 'smooth';
            });
            slider.addEventListener('mousemove', (e) => {
                if (!isDown) return;
                e.preventDefault();
                const x = e.pageX - slider.offsetLeft;
                const walk = (x - startX) * 2; 
                slider.scrollLeft = scrollLeft - walk;
            });
        }

        // Real-time Preview Listener
        window.addEventListener('message', function (event) {
            if (event.data.type === 'updateStyles') {
                const data = event.data.data;
                const primary = data.theme_color || '<?= $primary_color ?>';
                const bg = data.bg_color || '<?= $bg_color ?>';
                const font = data.font_family || '<?= $font_family ?>';

                // Update CSS variables
                document.documentElement.style.setProperty('--pg-bg', bg);
                document.documentElement.style.setProperty('--pg-primary', primary);
                document.documentElement.style.setProperty('--pg-font', `'${font}', 'Plus Jakarta Sans', sans-serif`);

                if (data.page_title) {
                    document.title = data.page_title + " | Horizon Systems";
                    const names = document.querySelectorAll('.gym-name-display');
                    names.forEach(n => n.innerText = data.page_title);
                }

                if (data.app_download_link) {
                    const qrImg = document.getElementById('qr-code-modal-img');
                    if (qrImg) {
                        qrImg.src = `https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=${encodeURIComponent(data.app_download_link)}`;
                    }
                }

                if (data.about_text !== undefined) {
                    const aboutDisp = document.getElementById('about-text-display');
                    if (aboutDisp) aboutDisp.innerText = data.about_text;
                }

                if (data.contact_text !== undefined) {
                    const contactDisp = document.getElementById('contact-text-display');
                    if (contactDisp) contactDisp.innerText = data.contact_text;
                }

                // CMS Content Real-time Sync
                if (data.portal_hero_title !== undefined) {
                    const el = document.getElementById('hero-title-display');
                    if (el) el.innerText = data.portal_hero_title || 'Elevate Your Fitness at ' + (data.page_title || 'Facility');
                }
                if (data.portal_hero_subtitle !== undefined) {
                    const el = document.getElementById('hero-subtitle-display');
                    if (el) el.innerText = data.portal_hero_subtitle || 'Discover a premium workout experience powered by Horizon\'s elite technology and world-class coaching staff.';
                }
                if (data.portal_features_title !== undefined) {
                    const el = document.getElementById('features-title-display');
                    if (el) el.innerText = data.portal_features_title || 'Premium Training. Elite Management.';
                }
                if (data.portal_features_desc !== undefined) {
                    const el = document.getElementById('features-desc-display');
                    if (el) el.innerText = data.portal_features_desc || 'Access our elite workout tracking and world-class management platform...';
                }
                if (data.portal_philosophy_title !== undefined) {
                    const el = document.getElementById('philosophy-title-display');
                    if (el) el.innerText = data.portal_philosophy_title || 'Modern technology meets unwavering dedication.';
                }
                if (data.portal_philosophy_desc !== undefined) {
                    const el = document.getElementById('philosophy-desc-display');
                    if (el) el.innerText = data.portal_philosophy_desc || 'Experience fitness like never before with our cutting-edge multi-tenant facility.';
                }
                if (data.portal_hero_label !== undefined) {
                    const el = document.getElementById('hero-label-display');
                    if (el) {
                        const span = el.querySelector('span.relative');
                        el.innerText = data.portal_hero_label || 'Open for Membership';
                        if (span) el.prepend(span);
                    }
                }
                if (data.portal_features_label !== undefined) {
                    const el = document.getElementById('features-label-display');
                    if (el) el.innerText = data.portal_features_label || 'Experience the Difference';
                }
                if (data.portal_philosophy_label !== undefined) {
                    const el = document.getElementById('philosophy-label-display');
                    if (el) el.innerText = data.portal_philosophy_label || 'The Philosophy';
                }
                if (data.portal_plans_title !== undefined) {
                    const el = document.getElementById('plans-title-display');
                    if (el) el.innerText = data.portal_plans_title || 'Membership Plans';
                }
                if (data.portal_plans_subtitle !== undefined) {
                    const el = document.getElementById('plans-subtitle-display');
                    if (el) el.innerText = data.portal_plans_subtitle || 'Select a plan to start your journey...';
                }
                if (data.portal_footer_links_title !== undefined) {
                    const el = document.getElementById('footer-links-title');
                    if (el) el.innerText = data.portal_footer_links_title || 'Quick Links';
                }
                if (data.portal_footer_contact_title !== undefined) {
                    const el = document.getElementById('footer-contact-title');
                    if (el) el.innerText = data.portal_footer_contact_title || 'Contact Facility';
                }
                if (data.portal_footer_app_title !== undefined) {
                    const el = document.getElementById('footer-app-title');
                    if (el) el.innerText = data.portal_footer_app_title || 'Get the App';
                }

                if (data.opening_time) {
                    const el = document.getElementById('opening-time-display');
                    if (el) {
                        try {
                            const [hours, minutes] = data.opening_time.split(':');
                            const h = parseInt(hours);
                            const ampm = h >= 12 ? 'PM' : 'AM';
                            const h12 = h % 12 || 12;
                            el.innerText = `${h12.toString().padStart(2, '0')}:${minutes} ${ampm}`;
                        } catch (e) {
                            el.innerText = data.opening_time;
                        }
                    }
                } else {
                    const el = document.getElementById('opening-time-display');
                    if (el) el.innerText = '--:--';
                }

                if (data.max_capacity !== undefined) {
                    const el = document.getElementById('max-capacity-display');
                    if (el) el.innerText = data.max_capacity || 'N/A';
                }

                const amenMap = {
                    'has_lockers': 'locker-tag',
                    'has_shower': 'shower-tag',
                    'has_parking': 'parking-tag',
                    'has_wifi': 'wifi-tag'
                };
                Object.keys(amenMap).forEach(key => {
                    const el = document.getElementById(amenMap[key]);
                    if (el && data[key] !== undefined) {
                        if (data[key]) {
                            el.classList.remove('opacity-20', 'grayscale');
                        } else {
                            el.classList.add('opacity-20', 'grayscale');
                        }
                    }
                });

                if (data.plans && Array.isArray(data.plans)) {
                    const grid = document.getElementById('membership-plans-grid');
                    if (grid) {
                        if (data.plans.length === 0) {
                            grid.classList.add('justify-center');
                            grid.innerHTML = `
                                <div class="w-full max-w-2xl py-20 dashboard-window rounded-3xl opacity-50 text-center shrink-0">
                                    <span class="material-symbols-outlined text-4xl mb-4">info</span>
                                    <p class="text-xs font-bold uppercase tracking-widest">No membership plans available at this time.</p>
                                </div>
                            `;
                        } else {
                            if (data.plans.length <= 2) {
                                grid.classList.add('justify-center');
                            } else {
                                grid.classList.remove('justify-center');
                            }
                            grid.innerHTML = data.plans.map(plan => {
                                const name = plan.name || 'Unnamed Tier';
                                const type = plan.type || 'Standard';
                                const price = parseFloat(plan.price || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                                const duration = plan.duration || 1;
                                const billing = plan.billing || (duration + ' Days');
                                const badgeText = plan.badge || (
                                    (name.toUpperCase().includes('ELITE') || name.toUpperCase().includes('VIP')) ? 'Recommended' : ''
                                );
                                
                                let featuresHtml = '';
                                if (plan.features) {
                                    const featuresList = plan.features.split('\n').map(f => f.trim()).filter(f => f);
                                    featuresHtml = featuresList.map(f => `
                                        <li class="flex items-center gap-3 text-xs text-gray-400 font-medium italic">
                                            <span class="material-symbols-outlined text-primary text-sm">check_circle</span> 
                                            ${f}
                                        </li>
                                    `).join('');
                                } else {
                                    featuresHtml = `
                                        <li class="flex items-center gap-3 text-xs text-gray-400 font-medium italic">
                                            <span class="material-symbols-outlined text-primary text-sm">check_circle</span> 
                                            Full access to all facility equipment and amenities.
                                        </li>
                                    `;
                                }

                                return `
                                    <div class="dashboard-window rounded-2xl p-10 flex flex-col text-left transition-all hover:scale-[1.02] hover:border-primary/40 duration-500 shrink-0 w-[85%] md:w-[400px] snap-start ${badgeText ? 'border-primary/30 relative' : ''}">
                                        ${badgeText ? `<div class="absolute -top-3 right-8 bg-primary text-white text-[8px] font-black uppercase tracking-[0.2em] px-3 py-1 rounded-full shadow-lg shadow-primary/20">${badgeText}</div>` : ''}
                                        
                                        <h3 class="text-xl font-display font-black text-white uppercase italic mb-1">${name}</h3>
                                        <p class="text-[9px] text-primary font-black uppercase tracking-widest mb-8">${type}</p>
                                        
                                        <div class="mb-10">
                                            <span class="text-4xl font-display font-black text-white">₱${price}</span>
                                            <span class="text-[10px] text-gray-600 font-bold uppercase tracking-widest">/ ${billing}</span>
                                        </div>
                                        
                                        <ul class="space-y-4 mb-12 flex-grow">
                                            ${featuresHtml}
                                        </ul>
                                    </div>
                                `;
                            }).join('');
                        }
                    }
                }
            }
        });
    </script>
</body>

</html>