<?php
require_once 'db.php';

$gym_slug = $_GET['gym'] ?? '';

if (empty($gym_slug)) {
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
    $gym_details = [
        'opening_time' => '08:00',
        'closing_time' => '22:00',
        'max_capacity' => 50,
        'has_lockers' => 0,
        'has_shower' => 0,
        'has_parking' => 0,
        'has_wifi' => 0
    ];
} else {
    $stmtPage = $pdo->prepare("SELECT tp.*, g.gym_name, g.gym_id, g.profile_picture as gym_logo, g.email as gym_email, g.contact_number as gym_contact 
                               FROM tenant_pages tp 
                               JOIN gyms g ON tp.gym_id = g.gym_id 
                               WHERE tp.page_slug = ? AND tp.is_active = 1 LIMIT 1");
    $stmtPage->execute([$gym_slug]);
    $page = $stmtPage->fetch();

    if (!$page) {
        die("Gym page not found or is currently inactive.");
    }

    // Fetch Operational Details
    $stmtDetails = $pdo->prepare("SELECT * FROM gym_details WHERE gym_id = ?");
    $stmtDetails->execute([$page['gym_id']]);
    $gym_details = $stmtDetails->fetch() ?: [
        'opening_time' => '08:00',
        'closing_time' => '22:00',
        'max_capacity' => 0,
        'has_lockers' => 0,
        'has_shower' => 0,
        'has_parking' => 0,
        'has_wifi' => 0
    ];
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
    <title><?= htmlspecialchars($page['page_title']) ?> | Horizon Systems</title>
    <link href="https://fonts.googleapis.com" rel="preconnect" />
    <link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect" />
    <link
        href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&family=Inter:wght@400;700&family=Outfit:wght@300;400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap"
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
                        "primary": "var(--pg-primary)",
                        "background-dark": "var(--pg-bg)",
                        "surface-dark": "rgba(255, 255, 255, 0.02)",
                        "border-light": "rgba(255, 255, 255, 0.08)"
                    }
                }
            }
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

        body {
            font-family: var(--pg-font);
            background-color: var(--pg-bg);
            color: #e2e8f0;
            scroll-behavior: smooth;
        }

        /* Invisible Scroll System */
        * {
            scrollbar-width: none;
            -ms-overflow-style: none;
        }

        *::-webkit-scrollbar {
            display: none;
        }

        .font-display {
            font-family: var(--pg-font);
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 24px;
            backdrop-filter: blur(16px);
            transition: all 0.3s ease;
        }

        .btn-premium {
            background: var(--pg-primary);
            position: relative;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.23, 1, 0.32, 1);
        }

        .btn-premium::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(rgba(255, 255, 255, 0.2), transparent);
            opacity: 0;
            transition: opacity 0.3s;
        }

        .btn-premium:hover::after {
            opacity: 1;
        }

        .btn-premium:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px var(--pg-primary);
        }

        .text-gradient {
            background: linear-gradient(to right, #fff, var(--pg-secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
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
            z-index: 1000;
            background: rgba(0, 0, 0, 0.4); /* Darker backdrop */
            backdrop-filter: blur(12px);
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: all 0.4s ease;
            padding: 140px 24px 80px 24px; /* Increased vertical padding */
        }

        #qr-modal.active {
            display: flex;
            opacity: 1;
        }

        .modal-content {
            background: rgba(15, 15, 20, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px; /* Reduced from 24px for a sharper, modern edge */
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

        .btn-modern {
            background: linear-gradient(135deg, var(--pg-primary) 0%, rgba(<?= $primary_rgb ?>, 0.8) 100%);
            box-shadow: 0 10px 30px -5px rgba(<?= $primary_rgb ?>, 0.4);
            transition: all 0.3s ease;
        }

        .btn-modern:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 40px -5px rgba(<?= $primary_rgb ?>, 0.6);
        }
    </style>
</head>

<body class="antialiased min-h-screen flex flex-col font-display selection:bg-primary/30 selection:text-white">

    <header
        class="w-full px-6 md:px-12 py-5 flex justify-between items-center bg-background-dark/80 backdrop-blur-xl fixed top-0 z-[1100] border-b border-white/5 transition-all">
        <div class="flex items-center gap-4">
            <div id="header-logo-container"
                class="size-9 rounded-xl bg-primary flex items-center justify-center shadow-lg shadow-primary/20 overflow-hidden">
                <?php 
                    $logo_src = !empty($page['logo_path']) ? $page['logo_path'] : ($page['gym_logo'] ?? '');
                ?>
                <?php if (!empty($logo_src)): ?>
                    <img id="header-logo-img" src="<?= htmlspecialchars($logo_src) ?>"
                        class="size-full object-cover">
                <?php else: ?>
                    <span id="header-logo-icon" class="material-symbols-outlined text-white text-xl font-bold">bolt</span>
                <?php endif; ?>
            </div>
            <h1
                class="text-xl font-bold tracking-tight text-white font-display uppercase tracking-widest gym-name-display">
                <?= htmlspecialchars($page['page_title'] ?? $page['gym_name']) ?>
            </h1>
        </div>
        <div class="flex items-center gap-4 md:gap-8">
            <nav class="hidden md:flex items-center gap-6">
                <a href="#about" class="text-xs font-medium text-gray-400 hover:text-white transition-colors">About</a>
                <a href="#contact"
                    class="text-xs font-medium text-gray-400 hover:text-white transition-colors">Contact</a>
            </nav>
            <div class="hidden md:block h-4 w-px bg-white/10"></div>
            <a href="member/member_register.php?gym=<?= $gym_slug ?>"
                class="font-display bg-white/5 md:bg-white/[0.03] hover:bg-primary/20 text-white border border-white/10 px-6 py-2.5 rounded-xl text-[10px] font-black uppercase tracking-[0.2em] transition-all mb-4 md:mb-0">
                Register
            </a>
        </div>
    </header>

    <main class="flex-1 flex flex-col items-center pt-32 pb-20 px-6 relative overflow-hidden">
        <!-- Premium Ambient Glows -->
        <div
            class="fixed top-[-10%] right-[-5%] size-[600px] bg-primary/10 rounded-full blur-[120px] -z-10 pointer-events-none">
        </div>
        <div
            class="fixed bottom-[10%] left-[-5%] size-[500px] bg-primary/5 rounded-full blur-[100px] -z-10 pointer-events-none">
        </div>

        <section class="max-w-5xl w-full text-center relative mb-24 mt-10 md:mt-20">
            <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full border border-white/10 bg-white/5 mb-8">
                <span class="relative flex h-2 w-2">
                    <span
                        class="animate-ping absolute inline-flex h-full w-full rounded-full bg-primary opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2 w-2 bg-primary"></span>
                </span>
                <span class="text-[10px] font-semibold uppercase tracking-wider text-gray-300">Open for
                    Membership</span>
            </div>

            <h2 class="text-5xl md:text-8xl font-bold tracking-tight text-white mb-8 leading-[1.1] font-display">
                Elevate Your <span class="text-primary">Fitness</span> <br class="hidden md:block" /> at <span
                    class="text-gradient gym-name-display"><?= htmlspecialchars($page['page_title'] ?? $page['gym_name']) ?></span>
            </h2>

            <p id="hero-description-display"
                class="text-gray-400 text-base md:text-xl max-w-2xl mx-auto leading-relaxed mb-12 font-body font-light">
                <?= htmlspecialchars($page['about_text'] ?? 'Discover a premium workout experience powered by Horizon\'s elite technology and world-class coaching staff.') ?>
            </p>

            <div class="flex flex-col sm:flex-row items-center justify-center gap-6">
                <button id="app-download-btn" onclick="openQRModal()"
                    class="h-14 px-10 rounded-2xl bg-white/5 border border-white/10 hover:bg-white/10 flex items-center justify-center text-sm font-semibold text-white transition-all group cursor-pointer">
                    <span class="material-symbols-outlined text-xl mr-2.5">smartphone</span>
                    Download App
                </button>
            </div>
        </section>

        <section class="max-w-5xl w-full" id="portal-info">
            <!-- Information Card -->
            <div class="glass-card p-10 flex flex-col group overflow-hidden relative text-center">
                <div
                    class="absolute -top-6 -right-6 p-8 opacity-[0.03] group-hover:opacity-[0.06] transition-opacity pointer-events-none">
                    <span class="material-symbols-outlined text-[140px]">fitness_center</span>
                </div>
                <div
                    class="size-14 rounded-2xl bg-primary/10 text-primary flex items-center justify-center mb-8 mx-auto">
                    <span class="material-symbols-outlined text-3xl font-bold">bolt</span>
                </div>
                <h3 class="text-2xl font-bold text-white mb-4 font-display text-center">Experience the Difference</h3>
                <p class="text-gray-400 text-sm mb-10 leading-relaxed font-body font-light max-w-2xl mx-auto">
                    Access our elite workout tracking and world-class management platform. For membership inquiries and
                    registrations, please visit our front desk.
                </p>

            </div>

            <!-- Operational Status (Live Sync) -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6">
                <div class="glass-card p-8 text-left relative overflow-hidden group">
                    <div class="flex items-center gap-4 mb-6">
                        <div class="size-10 rounded-xl bg-primary/10 flex items-center justify-center text-primary">
                            <span class="material-symbols-outlined">schedule</span>
                        </div>
                        <h4 class="text-xs font-black uppercase tracking-widest text-white italic">Operational Status
                        </h4>
                    </div>
                    <div class="space-y-4">
                        <div
                            class="flex justify-between items-center text-[11px] font-bold uppercase tracking-widest text-gray-500">
                            <span>Opening Time</span>
                            <span id="opening-time-display"
                                class="text-white italic"><?= date('h:i A', strtotime($gym_details['opening_time'])) ?></span>
                        </div>
                        <div
                            class="flex justify-between items-center text-[11px] font-bold uppercase tracking-widest text-gray-500">
                            <span>Closing Time</span>
                            <span id="closing-time-display"
                                class="text-white italic"><?= date('h:i A', strtotime($gym_details['closing_time'])) ?></span>
                        </div>
                        <div
                            class="flex justify-between items-center text-[11px] font-bold uppercase tracking-widest text-gray-500">
                            <span>Max Capacity</span>
                            <span id="max-capacity-display"
                                class="text-primary italic animate-pulse"><?= $gym_details['max_capacity'] ?: 'N/A' ?>
                                Members</span>
                        </div>
                    </div>
                </div>

                <div class="glass-card p-8 text-left relative overflow-hidden group">
                    <div class="flex items-center gap-4 mb-6">
                        <div class="size-10 rounded-xl bg-primary/10 flex items-center justify-center text-primary">
                            <span class="material-symbols-outlined">star</span>
                        </div>
                        <h4 class="text-xs font-black uppercase tracking-widest text-white italic">Amenities</h4>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div id="locker-tag"
                            class="flex items-center gap-2 p-3 rounded-lg bg-white/5 border border-white/5 <?= ($gym_details['has_lockers']) ? '' : 'opacity-20 grayscale' ?>">
                            <span class="material-symbols-outlined text-sm text-primary">lock</span>
                            <span class="text-[9px] font-black uppercase tracking-widest text-gray-300">Lockers</span>
                        </div>
                        <div id="shower-tag"
                            class="flex items-center gap-2 p-3 rounded-lg bg-white/5 border border-white/5 <?= ($gym_details['has_shower']) ? '' : 'opacity-20 grayscale' ?>">
                            <span class="material-symbols-outlined text-sm text-primary">shower</span>
                            <span class="text-[9px] font-black uppercase tracking-widest text-gray-300">Showers</span>
                        </div>
                        <div id="parking-tag"
                            class="flex items-center gap-2 p-3 rounded-lg bg-white/5 border border-white/5 <?= ($gym_details['has_parking']) ? '' : 'opacity-20 grayscale' ?>">
                            <span class="material-symbols-outlined text-sm text-primary">local_parking</span>
                            <span class="text-[9px] font-black uppercase tracking-widest text-gray-300">Parking</span>
                        </div>
                        <div id="wifi-tag"
                            class="flex items-center gap-2 p-3 rounded-lg bg-white/5 border border-white/5 <?= ($gym_details['has_wifi']) ? '' : 'opacity-20 grayscale' ?>">
                            <span class="material-symbols-outlined text-sm text-primary">wifi</span>
                            <span class="text-[9px] font-black uppercase tracking-widest text-gray-300">Wi-Fi</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="about" class="max-w-4xl w-full mt-32 py-20 text-center relative border-y border-white/5">
            <span class="text-[10px] font-bold uppercase text-primary tracking-[0.4em] mb-8 block font-display">The
                Philosophy</span>
            <p class="text-white text-3xl md:text-5xl font-bold leading-[1.2] mb-10 font-display">
                "Modern technology meets <br /> <span class="text-primary">unwavering dedication</span>."
            </p>
            <p id="about-text-display"
                class="text-gray-400 text-lg italic leading-relaxed font-body font-light opacity-90 max-w-2xl mx-auto">
                <?= nl2br(htmlspecialchars($page['about_text'] ?? 'Experience fitness like never before with our cutting-edge multi-tenant facility.')) ?>
            </p>
        </section>
    </main>

    <footer id="contact" class="w-full py-20 px-6 md:px-12 bg-white/[0.01] border-t border-white/5 mt-10">
        <div class="max-w-6xl mx-auto flex flex-col md:flex-row justify-between gap-16">
            <div class="flex-1 space-y-8">
                <div class="flex items-center gap-3">
                    <div
                        class="size-8 rounded-lg bg-primary flex items-center justify-center shadow-lg shadow-primary/20">
                        <?php 
                            $footer_logo = !empty($page['logo_path']) ? $page['logo_path'] : ($page['gym_logo'] ?? '');
                        ?>
                        <?php if (!empty($footer_logo)): ?>
                            <img src="<?= htmlspecialchars($footer_logo) ?>" class="size-full object-contain p-1">
                        <?php else: ?>
                            <span class="material-symbols-outlined text-white text-lg font-bold">bolt</span>
                        <?php endif; ?>
                    </div>
                    <h1
                        class="text-xl font-bold tracking-tight text-white font-display uppercase tracking-wider gym-name-display">
                        <?= htmlspecialchars($page['page_title'] ?? $page['gym_name']) ?>
                    </h1>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-10">
                    <div class="space-y-4">
                        <p class="text-[10px] font-bold uppercase text-primary tracking-[0.3em] font-display">Contact
                        </p>
                        <p class="text-gray-400 text-sm font-medium flex items-center gap-3 font-body">
                            <span class="material-symbols-outlined text-lg text-primary/60">mail</span>
                            <?= htmlspecialchars($page['gym_email']) ?>
                        </p>
                        <p class="text-gray-400 text-sm font-medium flex items-center gap-3 font-body">
                            <span class="material-symbols-outlined text-lg text-primary/60">call</span>
                            <?= htmlspecialchars($page['gym_contact']) ?>
                        </p>
                    </div>
                    <div class="space-y-4">
                        <p class="text-[10px] font-bold uppercase text-primary tracking-[0.3em] font-display">Location
                        </p>
                        <p id="contact-text-display"
                            class="text-gray-400 text-sm italic leading-relaxed font-body font-light">
                            <?= nl2br(htmlspecialchars($page['contact_text'] ?? 'Visit us at our primary training facility.')) ?>
                        </p>
                    </div>
                </div>
            </div>

            <div class="flex flex-col justify-end items-start md:items-end text-left md:text-right">
                <p class="text-[10px] font-bold uppercase tracking-[0.4em] text-gray-600 mb-2 font-display">Horizon
                    Partner</p>
                <p class="text-primary/40 text-[9px] font-bold uppercase tracking-[0.2em] font-display">Enterprise
                    Fitness v2.0</p>
                <div class="mt-8 flex gap-4">
                    <div
                        class="size-10 rounded-xl bg-white/5 border border-white/10 flex items-center justify-center text-gray-500 hover:text-white transition-all cursor-pointer">
                        <span class="material-symbols-outlined text-xl">brand_awareness</span>
                    </div>
                    <div
                        class="size-10 rounded-xl bg-white/5 border border-white/10 flex items-center justify-center text-gray-500 hover:text-white transition-all cursor-pointer">
                        <span class="material-symbols-outlined text-xl">social_leaderboard</span>
                    </div>
                </div>
            </div>
        </div>
    </footer>

    <!-- QR Download Modal -->
    <div id="qr-modal" onclick="event.target === this && closeQRModal()">
        <div class="modal-content p-2 max-w-[420px] w-full relative mx-4 shadow-2xl">
            <!-- Decorative inner ring -->
            <div class="absolute inset-0 border border-white/5 rounded-[22px] pointer-events-none"></div>

            <div class="p-10 flex flex-col items-center">
                <button onclick="closeQRModal()"
                    class="absolute top-8 right-8 size-10 rounded-full bg-white/5 border border-white/10 flex items-center justify-center text-gray-400 hover:text-white transition-all hover:rotate-90">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>

                <div
                    class="size-20 rounded-3xl bg-primary/10 text-primary flex items-center justify-center mb-8 relative">
                    <div class="absolute inset-0 bg-primary/20 rounded-3xl blur-xl opacity-50 animate-pulse"></div>
                    <span class="material-symbols-outlined text-4xl relative">qr_code_scanner</span>
                </div>

                <h3 class="text-3xl font-black text-white mb-3 font-display tracking-tightest text-center">Get the <span
                        class="text-primary italic">App</span></h3>
                <p class="text-gray-400 text-sm mb-10 leading-relaxed max-w-[280px] font-medium opacity-80 text-center mx-auto">
                    Scan the code below to unlock your premium training experience on the go.
                </p>

                <div class="qr-glow group/qr">
                    <div
                        class="relative p-6 bg-white rounded-[2rem] shadow-[0_20px_50px_-10px_rgba(0,0,0,0.3)] transition-transform duration-500 group-hover/qr:scale-[1.02]">
                        <div
                            class="absolute inset-0 border-4 border-primary/10 rounded-[2rem] pointer-events-none group-hover/qr:border-primary/30 transition-colors">
                        </div>
                        <img id="qr-code-modal-img"
                            src="https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=<?= urlencode($final_download_link) ?>"
                            alt="QR Code" class="size-52 sm:size-60 rounded-2xl">
                    </div>
                </div>

                <div class="mt-12 w-full flex flex-col gap-5">
                    <div class="flex items-center gap-3 px-4">
                        <div class="h-px flex-1 bg-white/5"></div>
                        <span class="text-[9px] font-black uppercase tracking-[0.3em] text-gray-600">Manual Link</span>
                        <div class="h-px flex-1 bg-white/5"></div>
                    </div>

                    <a href="<?= $final_download_link ?>" download
                        class="btn-modern w-full py-5 rounded-[2rem] text-white text-[11px] font-black uppercase tracking-[0.25em] flex items-center justify-center gap-4 group/dl-btn overflow-hidden relative">
                        <div
                            class="absolute inset-x-0 bottom-0 h-1 bg-white/20 transform scale-x-0 group-hover/dl-btn:scale-x-100 transition-transform origin-left">
                        </div>
                        <span
                            class="material-symbols-outlined text-xl group-hover/dl-btn:translate-y-1 transition-transform">download</span>
                        Direct Download APK
                    </a>

                    <p
                        class="text-[9px] text-gray-500 font-bold uppercase tracking-widest flex items-center justify-center gap-2">
                        <span class="size-1 rounded-full bg-primary/40"></span>
                        Version 2.0.4 • 42.5 MB
                        <span class="size-1 rounded-full bg-primary/40"></span>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <script>
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

        // Close on Escape
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeQRModal();
        });

        // Real-time Preview Listener
        window.addEventListener('message', function (event) {
            if (event.data.type === 'updateStyles') {
                const data = event.data.data;
                const primary = data.theme_color || '<?= $primary_color ?>';
                const secondary = data.secondary_color || '<?= $secondary_color ?>';
                const bg = data.bg_color || '<?= $bg_color ?>';
                const font = data.font_family || '<?= $font_family ?>';

                // Update CSS variables
                document.documentElement.style.setProperty('--pg-bg', bg);
                document.documentElement.style.setProperty('--pg-primary', primary);
                document.documentElement.style.setProperty('--pg-secondary', secondary);
                document.documentElement.style.setProperty('--pg-font', `'${font}', 'Plus Jakarta Sans', sans-serif`);

                // Update Gym Name/Title
                if (data.page_title) {
                    document.title = data.page_title + " | Horizon Systems";
                    const names = document.querySelectorAll('.gym-name-display');
                    names.forEach(n => n.innerText = data.page_title);
                }

                // Update App Download Links & QR Code
                if (data.app_download_link) {
                    const qrImg = document.getElementById('qr-code-modal-img');
                    if (qrImg) {
                        qrImg.src = `https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=${encodeURIComponent(data.app_download_link)}`;
                    }
                }

                // Update About Text
                if (data.about_text !== undefined) {
                    const aboutDisp = document.getElementById('about-text-display');
                    if (aboutDisp) aboutDisp.innerText = data.about_text;
                }

                // Update Contact Text
                if (data.contact_text !== undefined) {
                    const contactDisp = document.getElementById('contact-text-display');
                    if (contactDisp) contactDisp.innerText = data.contact_text;
                    const contactMobile = document.getElementById('contact-text-mobile');
                    if (contactMobile) contactMobile.innerText = data.contact_text;
                }

                // Update About/Hero Description
                if (data.about_text !== undefined) {
                    const heroDesc = document.getElementById('hero-description-display');
                    if (heroDesc) heroDesc.innerText = data.about_text;
                }

                // Update Logo Real-time
                if (data.logo_url) {
                    const headerImg = document.getElementById('header-logo-img');
                    const headerIcon = document.getElementById('header-logo-icon');
                    if (headerImg) {
                        headerImg.src = data.logo_url;
                        headerImg.classList.remove('hidden');
                        if (headerIcon) headerIcon.classList.add('hidden');
                    } else {
                        const container = document.getElementById('header-logo-container');
                        if (container) {
                            container.innerHTML = `<img id="header-logo-img" src="${data.logo_url}" class="size-full object-cover">`;
                        }
                    }
                }

                // Update Operational Data (Hours, Capacity)
                if (data.opening_time) {
                    const el = document.getElementById('opening-time-display');
                    if (el) el.innerText = data.opening_time;
                }
                if (data.closing_time) {
                    const el = document.getElementById('closing-time-display');
                    if (el) el.innerText = data.closing_time;
                }
                if (data.max_capacity !== undefined) {
                    const el = document.getElementById('max-capacity-display');
                    if (el) el.innerText = (data.max_capacity || 'N/A') + ' Members';
                }

                // Update Amenity Tags
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
            }
        });
    </script>
</body>

</html>