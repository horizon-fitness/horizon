<?php
session_start();
require_once 'db.php';

// Handle AJAX Theme Toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_theme') {
    $newTheme = in_array($_POST['theme'], ['dark', 'light', 'system']) ? $_POST['theme'] : 'light';
    $userId = $_SESSION['user_id'] ?? 0;
    
    $stmt = $pdo->prepare("INSERT INTO system_settings (user_id, setting_key, setting_value) 
                           VALUES (?, 'theme_preference', ?) 
                           ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->execute([$userId, $newTheme, $newTheme]);
    
    setcookie('theme_preference', $newTheme, time() + (86400 * 30), "/");
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'theme' => $newTheme]);
    exit;
}

// Fetch Theme Preference
$currentTheme = 'light';
if (isset($_SESSION['user_id'])) {
    $stmtTheme = $pdo->prepare("SELECT setting_value FROM system_settings WHERE user_id = ? AND setting_key = 'theme_preference'");
    $stmtTheme->execute([$_SESSION['user_id']]);
    $currentTheme = $stmtTheme->fetchColumn() ?: 'light';
} elseif (isset($_COOKIE['theme_preference'])) {
    $currentTheme = $_COOKIE['theme_preference'];
} else {
    $stmtTheme = $pdo->prepare("SELECT setting_value FROM system_settings WHERE user_id = 0 AND setting_key = 'theme_preference'");
    $stmtTheme->execute();
    $currentTheme = $stmtTheme->fetchColumn() ?: 'light';
}

// Fetch Active Website Plans
$stmtPlans = $pdo->prepare("SELECT * FROM website_plans WHERE is_active = 1 ORDER BY sort_order ASC, price ASC");
$stmtPlans->execute();
$plans = $stmtPlans->fetchAll();

// Fetch features for each plan (3NF Compatibility)
foreach ($plans as &$p) {
    $stmtF = $pdo->prepare("SELECT feature_name FROM website_plan_features WHERE website_plan_id = ?");
    $stmtF->execute([$p['website_plan_id']]);
    $p['features'] = $stmtF->fetchAll(PDO::FETCH_COLUMN);
}

// Fetch Affiliated Gyms (Registered Tenants)
$stmtGyms = $pdo->prepare("SELECT gym_name, profile_picture FROM gyms WHERE status IN ('Active', 'Approved') ORDER BY created_at ASC LIMIT 15");
$stmtGyms->execute();
$affiliatedGyms = $stmtGyms->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script>
        // Synchronously apply theme settings to prevent flash of incorrect theme
        (function() {
            const savedTheme = <?= json_encode($currentTheme) ?>;
            const html = document.documentElement;
            if (savedTheme === 'system') {
                const systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                if (systemDark) {
                    html.classList.add('dark');
                    html.classList.remove('light');
                } else {
                    html.classList.add('light');
                    html.classList.remove('dark');
                }
                html.setAttribute('data-theme-preference', 'system');
            } else {
                if (savedTheme === 'dark') {
                    html.classList.add('dark');
                    html.classList.remove('light');
                } else {
                    html.classList.add('light');
                    html.classList.remove('dark');
                }
                html.setAttribute('data-theme-preference', savedTheme);
            }
        })();
    </script>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" name="viewport"/>
    <title>Horizon | Multi-Tenant Management System</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com"/>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin=""/>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@300;400;500;600&family=Lexend:wght@300;400;500;700;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "#7f13ec",
                        "primary-dark": "#5e0eb3",
                        "background-dark": "#050505", 
                        "surface-dark": "rgba(21, 21, 24, 0.4)",
                        "text-secondary": "#ab9db9"
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
    <style>
        :root {
            --bg-color: #f8fafc;
            --surface-color: #ffffff;
            --text-main: #0f172a;
            --text-secondary: #334155;
            --nav-bg: rgba(248, 250, 252, 0.8);
            --border-color: rgba(0, 0, 0, 0.08);
            --card-bg: #ffffff;
            --dashboard-bg: #ffffff;
            --primary: #7f13ec;
            --primary-rgb: 127, 19, 236;
        }

        .dark {
            --bg-color: #050505;
            --surface-color: rgba(21, 21, 24, 0.4);
            --text-main: #f3f4f6;
            --text-secondary: #ab9db9;
            --nav-bg: rgba(5, 5, 5, 0.8);
            --border-color: rgba(255, 255, 255, 0.05);
            --card-bg: #0d0d10;
            --dashboard-bg: #08080a;
            --primary: #7f13ec;
            --primary-rgb: 127, 19, 236;
        }

        /* Invisible Scroll System (CSS Reset) */
        *::-webkit-scrollbar { display: none; }
        * { -ms-overflow-style: none; scrollbar-width: none; }

        /* Premium Visible Scrollbar Override */
        .custom-scrollbar::-webkit-scrollbar {
            display: block !important;
            height: 4px;
        }
        .custom-scrollbar::-webkit-scrollbar-track {
            background: var(--border-color);
            border-radius: 20px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: var(--text-secondary);
            opacity: 0.2;
            border-radius: 20px;
            border: 1px solid var(--border-color);
        }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            opacity: 0.4;
        }
        .custom-scrollbar {
            scrollbar-width: thin !important;
            scrollbar-color: var(--text-secondary) transparent !important;
        }

        #plansSlider { cursor: grab; user-select: none; }
        #plansSlider:active { cursor: grabbing; }

        html { 
            scroll-behavior: smooth; 
            scroll-padding-top: 80px;
        }
        body { 
            background-color: var(--bg-color); 
            color: var(--text-main);
            transition: background-color 0.4s ease, color 0.4s ease;
        }
        
        .glass-nav {
            background: transparent;
            border-bottom: 1px solid transparent;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .glass-nav.scrolled {
            background: var(--nav-bg);
            backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--border-color);
            padding-top: 0.75rem;
            padding-bottom: 0.75rem;
        }

        .nav-link {
            position: relative;
            transition: color 0.3s ease;
            color: var(--text-secondary);
        }
        .nav-link::after {
            content: '';
            position: absolute;
            width: 0;
            height: 2px;
            bottom: -4px;
            left: 0;
            background-color: #7f13ec;
            transition: width 0.3s ease;
        }
        .nav-link:hover::after, .nav-link:focus::after {
            width: 100%;
        }
        .nav-link:hover {
            color: var(--text-main);
        }

        .dashboard-window {
            background: var(--dashboard-bg);
            border: 1px solid var(--border-color);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            min-height: 400px;
            width: 100%;
            border-radius: 2rem;
            padding: 1.25rem;
            position: relative;
            z-index: 10;
        }
        @media (min-width: 768px) {
            .dashboard-window {
                padding: 2rem;
            }
        }

        .metric-card {
            background: var(--surface-color);
            border: 1px solid var(--border-color);
            padding: 1.25rem;
            border-radius: 1rem;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .metric-card:hover {
            transform: translateY(-2px);
            border-color: #7f13ec;
        }
        .metric-icon {
            position: absolute;
            right: -10px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 4rem;
            opacity: 0.05;
            pointer-events: none;
        }
        .status-pill {
            padding: 2px 8px;
            border-radius: 99px;
            font-size: 8px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .hero-visual-wrapper {
            position: relative;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            transform: translateY(0);
        }
        @media (min-width: 1024px) {
            .hero-visual-wrapper {
                transform: translateY(-140px);
            }
        }

        .model-cutout {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 500px;
            filter: drop-shadow(0 20px 50px rgba(0,0,0,0.3));
            border: none;
            outline: none;
            display: block;
            margin: 0 auto;
            transition: transform 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .hero-visual-wrapper:hover .model-cutout {
            transform: scale(1.02);
        }

        .hero-glow-back {
            position: absolute;
            width: 480px;
            height: 480px;
            background: radial-gradient(circle, rgba(var(--primary-rgb), 0.2) 0%, rgba(var(--primary-rgb), 0.05) 50%, transparent 70%);
            border: none;
            border-radius: 50%;
            z-index: 1;
            filter: blur(40px);
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }

        .floating-card {
            position: absolute;
            z-index: 20;
            background: rgba(255, 255, 255, 0.03);
            backdrop-filter: blur(15px);
            -webkit-backdrop-filter: blur(15px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            padding: 1.25rem 2rem;
            border-radius: 2rem;
            display: flex;
            flex-direction: column;
            gap: 2px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
            min-width: 180px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        html:not(.dark) .floating-card {
            background: rgba(255, 255, 255, 0.8);
            border-color: rgba(var(--primary-rgb), 0.1);
            box-shadow: 0 15px 35px rgba(var(--primary-rgb), 0.05);
        }
        
        .floating-card:hover {
            transform: translateY(-5px);
            background: rgba(var(--primary-rgb), 0.1);
            border-color: var(--primary);
        }

        .floating-card .value {
            font-family: 'Lexend', sans-serif;
            font-weight: 900;
            font-size: 1.75rem;
            font-style: italic;
            color: var(--text-main);
            line-height: 1;
        }

        .floating-card .label {
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            color: var(--primary);
        }
        
        .floating-card.c1 { top: 12%; left: -8%; }
        .floating-card.c2 { top: 18%; right: -8%; }
        .floating-card.c3 { bottom: 18%; left: -12%; }
        .floating-card.c4 { bottom: 22%; right: -12%; }

        /* Why Choose Us Styles */
        .why-us-card {
            background: var(--card-bg);
            border-radius: 1rem;
            padding: 2.5rem;
            position: relative;
            transition: all 0.3s ease;
            border: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 16px;
        }
        .why-us-card:hover {
            border-color: var(--primary) !important;
            transform: translateY(-5px);
            z-index: 10;
        }
        .why-us-card.featured {
            background: rgba(var(--primary-rgb), 0.05) !important;
            border: 1px solid var(--primary) !important;
            box-shadow: 0 25px 50px -12px rgba(var(--primary-rgb), 0.3);
            transform: scale(1.05);
        }
        .why-us-card.featured:hover {
            transform: translateY(-5px) scale(1.05);
        }
        .why-us-card h3 {
            font-family: 'Lexend', sans-serif;
            font-weight: 900;
            text-transform: uppercase;
            font-style: italic;
            font-size: 1.25rem;
            letter-spacing: -0.01em;
            color: var(--text-main);
        }
        .why-us-card.featured h3 {
            color: var(--primary);
        }
        .why-us-card p {
            font-size: 0.8rem;
            line-height: 1.5;
            color: var(--text-secondary);
        }

        .why-us-card .material-symbols-outlined {
            color: var(--primary) !important;
            opacity: 1;
            transition: all 0.3s ease;
        }
        .why-us-card:hover .material-symbols-outlined {
            transform: none;
        }

        .dot-indicator {
            height: 4px;
            border-radius: 99px;
            transition: all 0.4s ease;
            cursor: pointer;
            background: rgba(var(--primary-rgb), 0.1);
        }
        .dot-indicator.active {
            width: 48px;
            background: var(--primary);
        }
        .dot-indicator:not(.active) {
            width: 8px;
        }

        .text-gradient {
            background: linear-gradient(to right, var(--text-main) 10%, #bf80ff 50%, #7f13ec 95%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            display: inline-block;
            padding-right: 0.25em;
            margin-right: -0.25em;
            filter: drop-shadow(0 0 25px rgba(127, 19, 236, 0.4));
        }

        .hero-glow {
            background: radial-gradient(circle at 50% -10%, rgba(127, 19, 236, 0.1), transparent 70%);
        }

        .plan-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }
        .plan-card:hover {
            border-color: #7f13ec;
            transform: translateY(-5px);
        }

        .theme-toggle-btn {
            position: relative;
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--surface-color);
            border: 1px solid var(--border-color);
            color: var(--text-main);
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
        }
        .theme-toggle-btn:hover {
            transform: translateY(-1px);
            border-color: #7f13ec;
            background: rgba(127, 19, 236, 0.05);
        }
        .theme-toggle-btn .icon {
            position: absolute;
            font-size: 18px;
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Infinite Marquee Engine */
        @keyframes marquee {
            0% { transform: translateX(0); }
            100% { transform: translateX(-50%); }
        }
        .marquee-wrapper {
            mask-image: linear-gradient(to right, transparent, black 15%, black 85%, transparent);
            -webkit-mask-image: linear-gradient(to right, transparent, black 15%, black 85%, transparent);
        }
        .marquee-content {
            display: flex;
            gap: 2.5rem;
            flex-wrap: wrap;
        }
        .marquee-content:hover {
            animation-play-state: paused;
        }
        .gym-logo-item {
            transition: all 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .gym-logo-item:hover {
            filter: contrast(1.1) opacity(1);
        }
        .gym-card-inner {
            background: linear-gradient(135deg, rgba(255,255,255,0.05) 0%, rgba(255,255,255,0.02) 100%);
            border: none;
            position: relative;
        }
        .gym-card-inner::before {
            content: '';
            position: absolute;
            inset: 0;
            background: url('https://www.transparenttextures.com/patterns/carbon-fibre.png');
            opacity: 0.03;
            pointer-events: none;
        }
        .dark .sun-icon { transform: translateY(100%); opacity: 0; }
        .dark .moon-icon { transform: translateY(0); opacity: 1; }
        .sun-icon { transform: translateY(0); opacity: 1; }
        .moon-icon { transform: translateY(-100%); opacity: 0; }

        /* Mobile & Tablet Optimizations */
        @media (max-width: 768px) {
            .floating-card {
                padding: 0.75rem 1.25rem !important;
                min-width: 130px !important;
            }
            .floating-card .value {
                font-size: 1.25rem !important;
            }
            .floating-card .label {
                font-size: 8px !important;
            }
            .floating-card.c1 { top: 12%; left: -2%; }
            .floating-card.c2 { top: 18%; right: -2%; }
            .floating-card.c3 { bottom: 18%; left: -4%; }
            .floating-card.c4 { bottom: 22%; right: -4%; }
        }

        /* Theme Tabs Segmented Control Styling */
        .theme-tab-btn {
            color: var(--text-secondary);
            background: transparent;
            border: none;
            cursor: pointer;
            position: relative;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .theme-tab-btn.active {
            color: #ffffff !important;
            background: var(--primary) !important;
            box-shadow: 0 4px 12px rgba(127, 19, 236, 0.2);
        }
        .theme-tab-btn:not(.active):hover {
            color: var(--text-main);
            background: rgba(127, 19, 236, 0.05);
        }
    </style>
</head>
<body class="font-sans antialiased overflow-x-hidden">

    <nav id="topNav" class=" glass-nav fixed top-0 w-full z-50">
        <div class="max-w-7xl mx-auto px-6 py-4 transition-all duration-300">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-12">
                    <div class="flex items-center gap-3">
                        <div class="size-10 bg-primary/20 rounded-lg flex items-center justify-center border border-primary/30 overflow-hidden">
                            <img src="assests/horizon logo.png?v=<?= time() ?>" alt="Horizon Logo" class="size-full object-contain rounded-lg">
                        </div>
                        <h2 class="text-xl font-display font-bold text-gray-900 dark:text-white uppercase italic tracking-tighter">Horizon <span class="text-primary">System</span></h2>
                    </div>

                    <div class="hidden md:flex items-center gap-8 text-[11px] font-display font-bold uppercase tracking-widest text-gray-500">
                        <a href="#" class="nav-link">Home</a>
                        <a href="#why-choose-us" class="nav-link">Why Choose Us</a>
                        <a href="#about" class="nav-link">About Us</a>
                        <a href="#plans" class="nav-link">Plan</a>
                        <a href="#contact" class="nav-link">Contact</a>
                    </div>
                </div>

                <div class="flex items-center gap-4">
                    <button id="themeToggle" class="hidden lg:flex theme-toggle-btn" aria-label="Toggle Theme">
                        <span class="material-symbols-outlined icon sun-icon">light_mode</span>
                        <span class="material-symbols-outlined icon moon-icon">dark_mode</span>
                    </button>
                    <a href="login.php" class="hidden md:inline-flex font-display bg-black/5 dark:bg-white/5 hover:bg-black/10 dark:hover:bg-white/10 text-gray-900 dark:text-white border border-black/10 dark:border-white/10 px-5 py-2.5 rounded-custom text-[11px] font-bold uppercase tracking-widest transition-all">
                        Staff Login
                    </a>
                    <a href="tenant/tenant_application.php" class="hidden md:inline-flex font-display bg-primary hover:bg-primary-dark text-white px-5 py-2.5 rounded-custom text-[11px] font-bold uppercase tracking-widest transition-all shadow-lg shadow-primary/20">
                        Apply Now
                    </a>
                    <button id="mobileMenuToggle" class="md:hidden theme-toggle-btn" aria-label="Open Menu">
                        <span class="material-symbols-outlined">menu</span>
                    </button>
                </div>
            </div>
        </div>
    </nav>

    <!-- Mobile Menu Drawer (Overlay) -->
    <div id="mobileMenu" class="fixed inset-0 z-[60] opacity-0 pointer-events-none md:hidden">
        <!-- Backdrop -->
        <div id="mobileMenuBackdrop" class="absolute inset-0 bg-black/60 backdrop-blur-sm opacity-0 transition-opacity duration-500 ease-in-out"></div>
        <!-- Drawer Content -->
        <div id="mobileMenuDrawer" class="absolute right-0 top-0 bottom-0 w-80 max-w-[85vw] bg-white dark:bg-[#0d0d10] border-l border-black/10 dark:border-white/10 p-8 flex flex-col justify-between shadow-2xl z-10 translate-x-full transition-transform duration-300 ease-in-out">
            <div>
                <div class="flex items-center justify-between mb-12">
                    <span class="text-xs font-display font-black text-gray-500 uppercase tracking-[0.2em]">Navigation</span>
                    <button id="closeMobileMenu" class="theme-toggle-btn" aria-label="Close Menu">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                
                <nav class="flex flex-col gap-6 text-sm font-display font-bold uppercase tracking-widest text-gray-600 dark:text-gray-400">
                    <a href="#" class="mobile-nav-link hover:text-primary transition-colors">Home</a>
                    <a href="#why-choose-us" class="mobile-nav-link hover:text-primary transition-colors">Why Choose Us</a>
                    <a href="#about" class="mobile-nav-link hover:text-primary transition-colors">About Us</a>
                    <a href="#plans" class="mobile-nav-link hover:text-primary transition-colors">Plan</a>
                    <a href="#contact" class="mobile-nav-link hover:text-primary transition-colors">Contact</a>
                </nav>
            </div>
            
            <div class="flex flex-col gap-4 pt-8 border-t border-black/5 dark:border-white/5">
                <a href="login.php" class="font-display block text-center bg-black/5 dark:bg-white/5 hover:bg-black/10 dark:hover:bg-white/10 text-gray-900 dark:text-white border border-black/10 dark:border-white/10 px-5 py-3.5 rounded-custom text-[11px] font-bold uppercase tracking-widest transition-all">
                    Staff Login
                </a>
                <a href="tenant/tenant_application.php" class="font-display block text-center bg-primary hover:bg-primary-dark text-white px-5 py-3.5 rounded-custom text-[11px] font-bold uppercase tracking-widest transition-all shadow-lg shadow-primary/20">
                    Apply Now
                </a>
            </div>
        </div>
    </div>

    <main class="hero-glow">
        <section class="relative pt-20 pb-10 md:pt-32 md:pb-12 px-6 flex items-center justify-center">
            <div class="max-w-7xl w-full grid lg:grid-cols-2 gap-x-16 gap-y-0 items-center relative z-10">
                <!-- Sibling 1: Title, paragraph, and Apply Now button -->
                <div class="text-center lg:text-left lg:col-start-1 lg:row-start-1 lg:self-end">
                    <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-black/5 dark:bg-white/5 border border-black/10 dark:border-white/10 text-primary text-[10px] font-black uppercase tracking-[0.2em] mb-6 md:mb-8">
                        <span class="material-symbols-outlined text-sm">hub</span>
                        Next-Gen Multi-Tenant Platform
                    </div>
                    
                    <h1 class="text-5xl md:text-8xl font-display font-black leading-[0.85] tracking-tighter text-gray-900 dark:text-white uppercase italic mb-6 md:mb-8">
                        Expand Your <br/>
                        <span class="text-gradient">Horizon</span>
                    </h1>
                    
                    <p class="text-sm sm:text-base md:text-lg text-gray-700 dark:text-gray-500 font-medium leading-relaxed max-w-md mx-auto lg:mx-0 mb-6 md:mb-10 italic">
                        Together with <span class="text-gray-900 dark:text-white font-bold">HORIZON</span>, your fitness business will really form and scale. Interested? Join now!
                    </p>
                    
                    <div class="flex flex-wrap justify-center lg:justify-start gap-4 mb-6 md:mb-16">
                        <a href="tenant/tenant_application.php" class="font-display h-14 md:h-16 px-8 md:px-10 bg-primary text-white font-bold rounded-custom text-[10px] md:text-xs uppercase tracking-widest hover:scale-105 transition-all shadow-xl shadow-primary/20 flex items-center justify-center">
                            Apply Now
                        </a>
                    </div>
                </div>

                <!-- Sibling 2: The Model Portrait (under Apply Now on mobile, right column on desktop) -->
                <div class="hero-visual-wrapper lg:col-start-2 lg:row-start-1 lg:row-span-2 lg:self-center mt-6 mb-12 lg:my-0">
                    <!-- Glow behind the model -->
                    <div class="hero-glow-back"></div>
                    
                    <!-- Floating Cards -->
                    <div class="floating-card c1">
                        <span class="value">980+</span>
                        <span class="label">Happy Members</span>
                    </div>
                    <div class="floating-card c2">
                        <span class="value">180+</span>
                        <span class="label">Expert Coaches</span>
                    </div>
                    <div class="floating-card c3">
                        <span class="value">28+</span>
                        <span class="label">Daily Classes</span>
                    </div>
                    <div class="floating-card c4">
                        <span class="value">99%</span>
                        <span class="label">Satisfaction</span>
                    </div>

                    <!-- The Model Portrait -->
                    <img src="./assests/model.png?v=<?php echo time(); ?>" alt="Fitness Training" class="model-cutout">
                    
                    <!-- Decorative blur -->
                    <div class="absolute -bottom-10 -left-10 w-64 h-64 bg-primary/20 blur-[100px] rounded-full pointer-events-none"></div>
                </div>

                <!-- Sibling 3: Stats and Partnered Gyms -->
                <div class="lg:col-start-1 lg:row-start-2 lg:self-start w-full">
                    <div class="flex justify-center lg:justify-start gap-12 border-t border-black/5 dark:border-white/5 pt-10">
                        <div class="text-center lg:text-left">
                            <h3 class="text-3xl font-display font-black text-primary">28</h3>
                            <p class="text-[10px] text-gray-600 dark:text-gray-400 uppercase font-black tracking-widest mt-1">Exercise Programs</p>
                        </div>
                        <div class="text-center lg:text-left">
                            <h3 class="text-3xl font-display font-black text-gray-900 dark:text-white">980+</h3>
                            <p class="text-[10px] text-gray-600 dark:text-gray-400 uppercase font-black tracking-widest mt-1">Total Members</p>
                        </div>
                        <div class="text-center lg:text-left">
                            <h3 class="text-3xl font-display font-black text-gray-900 dark:text-white">180+</h3>
                            <p class="text-[10px] text-gray-600 dark:text-gray-400 uppercase font-black tracking-widest mt-1">Professional Coaches</p>
                        </div>
                    </div>

                    <!-- Compact Affiliated Gyms Marquee -->
                    <div class="mt-12 pt-8 text-center lg:text-left">
                        <p class="text-[9px] text-black dark:text-gray-300 font-black uppercase tracking-[0.4em] mb-6">Partnered Fitness center</p>
                        <div class="flex flex-wrap justify-center lg:justify-start gap-8 items-center py-2">
                            <?php foreach ($affiliatedGyms as $gym): ?>
                                <div class="flex items-center gap-3 group opacity-100 transition-all duration-500 cursor-pointer shrink-0">
                                    <div class="gym-card-inner size-12 rounded-xl overflow-hidden border border-black/5 dark:border-white/10 shadow-sm group-hover:shadow-primary/20 transition-all">
                                        <?php if (!empty($gym['profile_picture'])): ?>
                                            <img src="<?= htmlspecialchars($gym['profile_picture']) ?>" alt="<?= htmlspecialchars($gym['gym_name']) ?>" class="gym-logo-item w-full h-full object-cover transition-all">
                                        <?php else: ?>
                                            <div class="gym-logo-item w-full h-full flex items-center justify-center bg-gray-100 dark:bg-white/10 text-gray-900 dark:text-white font-black text-[14px]"><?= strtoupper(substr($gym['gym_name'], 0, 1)) ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <span class="text-[11px] font-display font-bold uppercase tracking-widest text-gray-700 dark:text-gray-300 group-hover:text-primary transition-colors"><?= htmlspecialchars($gym['gym_name']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Why Choose Us Section -->
        <section id="why-choose-us" class="pt-12 pb-32 px-6 overflow-hidden">
            <div class="max-w-7xl mx-auto">
                <div class="flex flex-col md:flex-row md:items-end justify-between gap-8 mb-16">
                    <div>
                        <p class="text-primary text-[10px] font-black uppercase tracking-[0.4em] mb-4">Core Features</p>
                        <h2 class="text-3xl md:text-5xl font-display font-black text-gray-900 dark:text-white uppercase italic tracking-tighter leading-none">
                            Why <span class="text-gradient">Choose Us</span>
                        </h2>
                    </div>
                    <div class="hidden md:flex gap-4">
                        <button onclick="scrollFeatures('left')" class="size-12 rounded-full border border-black/10 dark:border-white/10 flex items-center justify-center text-gray-900 dark:text-white hover:border-primary hover:text-primary transition-all">
                            <span class="material-symbols-outlined">west</span>
                        </button>
                        <button onclick="scrollFeatures('right')" class="size-12 rounded-full border border-black/10 dark:border-white/10 flex items-center justify-center text-gray-900 dark:text-white hover:border-primary hover:text-primary transition-all">
                            <span class="material-symbols-outlined">east</span>
                        </button>
                    </div>
                </div>

                <div id="featuresContainer" class="flex gap-6 overflow-x-auto snap-x snap-mandatory hide-scrollbar py-12 px-6 scroll-smooth w-full">
                    <!-- Feature 1: Management -->
                    <div class="why-us-card min-w-[calc(100vw-3rem)] lg:min-w-[400px] lg:w-[400px] snap-center">
                        <div class="h-32 flex items-center justify-start">
                            <span class="material-symbols-outlined text-6xl text-primary">fitness_center</span>
                        </div>
                        <div class="flex-grow flex flex-col justify-end">
                            <h3>Smart Management</h3>
                            <p class="mt-4">Take full control of your facility. Manage staff, schedules, and member access with one unified, high-performance interface.</p>
                        </div>
                    </div>

                    <!-- Feature 2: BMI & Member Analytics -->
                    <div class="why-us-card min-w-[calc(100vw-3rem)] lg:min-w-[400px] lg:w-[400px] snap-center">
                        <div class="h-32 flex items-center justify-start">
                            <span class="material-symbols-outlined text-6xl text-primary">query_stats</span>
                        </div>
                        <div class="flex-grow flex flex-col justify-end">
                            <h3>Member Analytics</h3>
                            <p class="mt-4">Offer professional-grade health tracking. Integrated BMI and performance metrics to keep your members motivated and loyal.</p>
                        </div>
                    </div>

                    <!-- Feature 3: Financial Growth -->
                    <div class="why-us-card min-w-[calc(100vw-3rem)] lg:min-w-[400px] lg:w-[400px] snap-center">
                        <div class="h-32 flex items-center justify-start">
                            <span class="material-symbols-outlined text-6xl text-primary">account_balance_wallet</span>
                        </div>
                        <div class="flex-grow flex flex-col justify-end">
                            <h3>Financial Growth</h3>
                            <p class="mt-4">Streamline your cash flow. Automated billing, secure tenant isolation, and real-time revenue reports to scale your business.</p>
                        </div>
                    </div>

                    <!-- Feature 4: Multi-Branch Control -->
                    <div class="why-us-card min-w-[calc(100vw-3rem)] lg:min-w-[400px] lg:w-[400px] snap-center">
                        <div class="h-32 flex items-center justify-start">
                            <span class="material-symbols-outlined text-6xl text-primary">hub</span>
                        </div>
                        <div class="flex-grow flex flex-col justify-end">
                            <h3>Multi-Branch Hub</h3>
                            <p class="mt-4">Scale your fitness empire. Manage multiple locations, shared staff, and cross-gym memberships from a single master account.</p>
                        </div>
                    </div>

                    <!-- Feature 5: Automated Reporting -->
                    <div class="why-us-card min-w-[calc(100vw-3rem)] lg:min-w-[400px] lg:w-[400px] snap-center">
                        <div class="h-32 flex items-center justify-start">
                            <span class="material-symbols-outlined text-6xl text-primary">assessment</span>
                        </div>
                        <div class="flex-grow flex flex-col justify-end">
                            <h3>Business Intelligence</h3>
                            <p class="mt-4">Stop guessing. Get automated daily and monthly reports on revenue, member retention, and peak usage hours.</p>
                        </div>
                    </div>

                    <!-- Feature 6: Private Security -->
                    <div class="why-us-card min-w-[calc(100vw-3rem)] lg:min-w-[400px] lg:w-[400px] snap-center">
                        <div class="h-32 flex items-center justify-start">
                            <span class="material-symbols-outlined text-6xl text-primary">verified_user</span>
                        </div>
                        <div class="flex-grow flex flex-col justify-end">
                            <h3>Elite Security</h3>
                            <p class="mt-4">Your data is locked. Advanced tenant isolation and end-to-end encryption ensure your business secrets stay private.</p>
                        </div>
                    </div>
                </div>

                <div class="flex justify-center gap-3 mt-8">
                    <div onclick="scrollToFeature(0)" class="dot-indicator active"></div>
                    <div onclick="scrollToFeature(1)" class="dot-indicator"></div>
                    <div onclick="scrollToFeature(2)" class="dot-indicator"></div>
                    <div onclick="scrollToFeature(3)" class="dot-indicator"></div>
                    <div onclick="scrollToFeature(4)" class="dot-indicator"></div>
                    <div onclick="scrollToFeature(5)" class="dot-indicator"></div>
                </div>
            </div>
        </section>

        <section id="about" class="py-24 px-6 relative border-t border-black/5 dark:border-white/5 bg-white dark:bg-transparent">
            <div class="max-w-7xl mx-auto">
                <div class="grid lg:grid-cols-2 gap-20 items-center">
                    <div>
                        <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-black/5 dark:bg-white/5 border border-black/10 dark:border-white/10 text-primary text-[10px] font-black uppercase tracking-[0.2em] mb-6">
                            Behind the System
                        </div>
                        <h2 class="text-4xl md:text-5xl font-display font-black text-gray-900 dark:text-white uppercase italic leading-tight mb-8">
                            One Platform.<br/>
                            <span class="text-gradient">Infinite Gyms.</span>
                        </h2>
                        <div class="space-y-6 text-gray-600 dark:text-gray-400 italic leading-relaxed text-justify">
                            <p>
                                Horizon is more than just a management tool; it is a multi-tenant ecosystem designed to revolutionize how fitness centers operate. We provide the digital backbone that allows gym owners to automate their workflow.
                            </p>
                            <p>
                                Our architecture ensures that every gym enjoys a private, secure environment with custom analytics and dedicated resources, all while operating under the powerful Horizon umbrella.
                            </p>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 mt-12">
                            <div class="p-5 rounded-xl bg-black/[0.01] dark:bg-white/[0.02] border border-black/5 dark:border-white/5">
                                <span class="material-symbols-outlined text-primary mb-3 text-2xl">security</span>
                                <h4 class="text-gray-900 dark:text-white text-sm font-black uppercase tracking-widest mb-2 italic">Data Isolation</h4>
                                <p class="text-xs text-gray-600 dark:text-gray-500 leading-relaxed">Your gym's data is strictly yours. Secure tenant separation at every layer.</p>
                            </div>
                            <div class="p-5 rounded-xl bg-black/[0.01] dark:bg-white/[0.02] border border-black/5 dark:border-white/5">
                                <span class="material-symbols-outlined text-primary mb-3 text-2xl">speed</span>
                                <h4 class="text-gray-900 dark:text-white text-sm font-black uppercase tracking-widest mb-2 italic">High Velocity</h4>
                                <p class="text-xs text-gray-600 dark:text-gray-500 leading-relaxed">Optimized for real-time check-ins and instant membership updates.</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="relative lg:translate-y-8">
                        <div class="dashboard-window w-full overflow-hidden">
                            <header class="flex flex-col sm:flex-row sm:justify-between sm:items-end gap-4 mb-8 text-left">
                                <div>
                                    <h3 class="text-xl font-display font-black text-gray-900 dark:text-white uppercase italic tracking-tighter leading-none">
                                        Welcome Back, <span class="text-primary italic">Tenant</span>
                                    </h3>
                                    <p class="text-[9px] text-gray-600 dark:text-gray-400 font-bold uppercase tracking-[0.3em] mt-1">Elite Fitness Management System</p>
                                </div>
                                <div class="text-left sm:text-right">
                                    <p class="text-lg font-black italic text-gray-900 dark:text-white leading-none uppercase tracking-tighter">09:12:45 AM</p>
                                    <p class="text-primary text-[8px] font-black uppercase tracking-widest mt-1">Wednesday, May 06</p>
                                </div>
                            </header>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                                <div class="metric-card" style="border-color:rgba(127,19,236,0.2); background:linear-gradient(135deg,rgba(127,19,236,0.05) 0%,transparent 100%)">
                                    <span class="material-symbols-outlined metric-icon text-primary">badge</span>
                                    <p class="text-[9px] text-gray-500 uppercase font-black mb-1 tracking-widest">Total Staff</p>
                                    <h4 class="text-xl font-black italic text-gray-900 dark:text-white">12</h4>
                                    <span class="status-pill bg-primary/10 text-primary mt-2 inline-block">Active Personnel</span>
                                </div>

                                <div class="metric-card" style="border-color:rgba(16,185,129,0.2); background:linear-gradient(135deg,rgba(16,185,129,0.05) 0%,transparent 100%)">
                                    <span class="material-symbols-outlined metric-icon text-emerald-500">group</span>
                                    <p class="text-[9px] text-gray-500 uppercase font-black mb-1 tracking-widest">Active Members</p>
                                    <h4 class="text-xl font-black italic text-gray-900 dark:text-white">482</h4>
                                    <span class="status-pill bg-emerald-500/10 text-emerald-500 mt-2 inline-block">Currently Enrolled</span>
                                </div>
                            </div>

                            <div class="metric-card border-black/5 dark:border-white/5 bg-black/[0.01] dark:bg-white/[0.01]">
                                <div class="flex justify-between items-center mb-4">
                                    <p class="text-[9px] text-gray-600 dark:text-gray-400 font-bold uppercase tracking-widest">Member Growth Trends</p>
                                    <div class="flex items-center gap-2">
                                        <div class="w-2 h-2 rounded-full bg-primary"></div>
                                        <span class="text-[8px] text-gray-500 font-bold uppercase tracking-tighter">Signups</span>
                                    </div>
                                </div>
                                <div class="relative h-20 flex items-end">
                                    <svg class="w-full h-full" preserveAspectRatio="none" viewBox="0 0 100 100">
                                        <path d="M0 80 Q 20 60, 40 70 T 80 30 T 100 20" fill="none" stroke="#7f13ec" stroke-width="3" stroke-linecap="round"/>
                                        <path d="M0 80 Q 20 60, 40 70 T 80 30 T 100 20 V 100 H 0 Z" fill="url(#grad)" opacity="0.1"/>
                                        <defs>
                                            <linearGradient id="grad" x1="0%" y1="0%" x2="0%" y2="100%">
                                                <stop offset="0%" style="stop-color:#7f13ec;stop-opacity:1" />
                                                <stop offset="100%" style="stop-color:#7f13ec;stop-opacity:0" />
                                            </linearGradient>
                                        </defs>
                                    </svg>
                                </div>
                            </div>
                        </div>
                        <div class="absolute -top-6 -right-6 w-32 h-32 bg-primary/30 blur-3xl rounded-full"></div>
                    </div>
                </div>
            </div>
        </section>

        <section id="mission-vision" class="py-24 px-6 relative border-t border-black/5 dark:border-white/5 bg-slate-50 dark:bg-[#08080a] overflow-hidden">
            <div class="absolute inset-0 bg-[radial-gradient(circle_at_20%_20%,rgba(127,19,236,0.12),transparent_35%),radial-gradient(circle_at_80%_80%,rgba(127,19,236,0.08),transparent_35%)] pointer-events-none"></div>
            <div class="max-w-7xl mx-auto relative z-10">
                <div class="text-center max-w-3xl mx-auto mb-14">
                    <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-primary/10 border border-primary/20 text-primary text-[10px] font-black uppercase tracking-[0.2em] mb-6">
                        System Direction
                    </div>
                    <h2 class="text-4xl md:text-5xl font-display font-black text-gray-900 dark:text-white uppercase italic tracking-tighter mb-4">
                        Built With <span class="text-gradient">Purpose</span>
                    </h2>
                    <p class="text-[10px] text-gray-600 dark:text-gray-400 font-bold uppercase tracking-[0.3em] leading-relaxed">
                        Connecting web administration and mobile member access in one secure platform
                    </p>
                </div>

                <div class="grid md:grid-cols-2 gap-6 lg:gap-10">
                    <div class="plan-card rounded-2xl p-8 md:p-10 text-left bg-white dark:bg-white/[0.02] border-black/5 dark:border-white/5">
                        <div class="size-12 rounded-xl bg-primary/10 border border-primary/20 flex items-center justify-center mb-8">
                            <span class="material-symbols-outlined text-primary text-2xl">flag</span>
                        </div>
                        <p class="text-[10px] text-primary font-black uppercase tracking-[0.3em] mb-4">Mission</p>
                        <h3 class="text-2xl font-display font-black text-gray-900 dark:text-white uppercase italic tracking-tighter mb-6">
                            Simplify Fitness Operations
                        </h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400 leading-relaxed italic text-justify">
                            To empower fitness facilities with a unified web and mobile management platform that simplifies gym operations, improves member accessibility, and automates essential processes such as registration, scheduling, attendance tracking, payments, and reporting while maintaining secure tenant-based data separation.
                        </p>
                    </div>

                    <div class="plan-card rounded-2xl p-8 md:p-10 text-left border-primary/40 bg-primary/5 shadow-2xl shadow-primary/10">
                        <div class="size-12 rounded-xl bg-primary/10 border border-primary/20 flex items-center justify-center mb-8">
                            <span class="material-symbols-outlined text-primary text-2xl">visibility</span>
                        </div>
                        <p class="text-[10px] text-primary font-black uppercase tracking-[0.3em] mb-4">Vision</p>
                        <h3 class="text-2xl font-display font-black text-gray-900 dark:text-white uppercase italic tracking-tighter mb-6">
                            One Connected Fitness Network
                        </h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400 leading-relaxed italic text-justify">
                            To become a trusted digital fitness management solution for gyms and wellness centers by connecting administrators, staff, coaches, and members through one scalable platform that delivers efficient operations, real-time mobile access, accurate records, and a better fitness service experience.
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <section id="plans" class="pt-16 pb-32 px-6 relative border-t border-black/5 dark:border-white/5">
            <div class="max-w-7xl mx-auto text-center">
                <div class="mb-16">
                    <div class="inline-flex items-center justify-center p-3 rounded-xl bg-primary/10 border border-primary/20 mb-6">
                        <span class="material-symbols-outlined text-primary">workspace_premium</span>
                    </div>
                    <h2 class="text-4xl md:text-5xl font-display font-black text-gray-900 dark:text-white uppercase italic tracking-tighter mb-4">
                        Choose Your <span class="text-primary">Growth Plan</span>
                    </h2>
                    <p class="text-[10px] text-gray-600 dark:text-gray-400 font-bold uppercase tracking-[0.3em]">Select a plan to activate your gym's digital infrastructure</p>
                </div>

                <div id="plansSlider" class="overflow-x-auto snap-x snap-mandatory custom-scrollbar scroll-smooth w-full">
                    <!-- Centering Wrapper: Centers cards on screen if they fit; aligns left and scrolls normally if they overflow -->
                    <div class="flex justify-start items-stretch gap-6 md:gap-10 py-12 px-6 md:px-10 mx-auto w-max max-w-full">
                        <?php foreach ($plans as $plan): 
                            $hasBadge = !empty($plan['badge_text']);
                        ?>
                        <div class="plan-card rounded-2xl p-6 md:p-10 flex flex-col text-left shrink-0 w-[calc(100vw-3rem)] lg:w-[400px] snap-start <?= $hasBadge ? 'border-primary/50 bg-primary/5 scale-105 shadow-2xl shadow-primary/20' : '' ?>">
                            <h3 class="text-xl font-display font-black text-gray-900 dark:text-white uppercase italic mb-1"><?= htmlspecialchars($plan['plan_name']) ?></h3>
                            <p class="text-[9px] <?= $hasBadge ? 'text-primary' : 'text-gray-600' ?> font-bold uppercase tracking-widest mb-8">
                                <?= $hasBadge ? htmlspecialchars($plan['badge_text']) : htmlspecialchars($plan['billing_cycle']) ?>
                            </p>
                            <div class="mb-10">
                                <span class="text-4xl font-display font-black text-gray-900 dark:text-white">₱<?= number_format($plan['price']) ?></span>
                                <span class="text-[10px] text-gray-600 dark:text-gray-400 font-bold uppercase tracking-widest">/ <?= ($plan['duration_months'] == 12) ? 'Yr' : 'Term' ?></span>
                            </div>
                            <ul class="space-y-4 mb-12 flex-grow">
                                <?php foreach ($plan['features'] as $feature): ?>
                                <li class="flex items-center gap-3 text-xs text-gray-600 dark:text-gray-400 font-medium">
                                    <span class="material-symbols-outlined text-primary text-sm">check_circle</span> <?= htmlspecialchars(trim($feature)) ?>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </section>


    </main>

    <section class="w-full bg-slate-50 dark:bg-[#0a0a0c] border-y border-black/5 dark:border-white/5 py-20 px-6 relative overflow-hidden">
        <div class="absolute inset-0 bg-gradient-to-r from-primary/10 via-transparent to-primary/10 opacity-30"></div>
        <div class="max-w-7xl mx-auto flex flex-col md:flex-row items-center justify-between gap-8 relative z-10">
            <div class="text-center md:text-left">
                <h2 class="text-3xl md:text-5xl font-display font-black text-gray-900 dark:text-white uppercase italic tracking-tighter mb-4">Ready to transform?</h2>
                <p class="text-sm text-gray-600 dark:text-gray-400 font-medium italic">Activate your gym's digital infrastructure today.</p>
            </div>
            <a href="tenant/tenant_application.php" class="bg-primary hover:bg-primary-dark text-white px-10 py-5 rounded-xl font-display font-bold uppercase tracking-widest text-[11px] transition-all shadow-2xl hover:scale-105 active:scale-95">Apply Now</a>
        </div>
    </section>

    <footer id="contact" class="bg-white dark:bg-[#08080a] border-t border-black/5 dark:border-white/5 pt-24 pb-12 px-6">
        <div class="max-w-7xl mx-auto">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-16 mb-24">
                <div class="space-y-8">
                    <div>
                        <div class="flex items-center gap-3 mb-6">
                            <div class="size-12 bg-primary/20 rounded-lg flex items-center justify-center border border-primary/30 overflow-hidden">
                                <img src="assests/horizon logo.png?v=<?= time() ?>" alt="Horizon Logo" class="size-full object-contain rounded-lg">
                            </div>
                            <h2 class="text-2xl font-display font-bold text-gray-900 dark:text-white uppercase italic tracking-tighter">Horizon <span class="text-primary">System</span></h2>
                        </div>
                        <p class="text-[10px] text-primary font-black uppercase tracking-[0.4em] mb-6">Expand Your Horizon</p>
                        <p class="text-xs text-gray-500 font-medium leading-relaxed italic max-w-sm">
                            Together with HORIZON, your fitness business will really form and scale.
                        </p>
                    </div>
                                      <div class="flex gap-4">
                        <a href="#" class="size-10 rounded-full bg-black/5 dark:bg-white/5 border border-black/10 dark:border-white/10 flex items-center justify-center text-gray-500 dark:text-gray-400 hover:text-primary dark:hover:text-white hover:bg-primary/5 dark:hover:bg-primary/20 hover:border-primary/30 dark:hover:border-primary/50 transition-all group">
                            <svg class="w-4 h-4 fill-current group-hover:scale-110 transition-transform" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                        </a>
                        <a href="#" class="size-10 rounded-full bg-black/5 dark:bg-white/5 border border-black/10 dark:border-white/10 flex items-center justify-center text-gray-500 dark:text-gray-400 hover:text-primary dark:hover:text-white hover:bg-primary/5 dark:hover:bg-primary/20 hover:border-primary/30 dark:hover:border-primary/50 transition-all group">
                            <svg class="w-4 h-4 fill-current group-hover:scale-110 transition-transform" viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 1.366.062 2.633.332 3.608 1.308.975.975 1.245 2.242 1.308 3.608.058 1.266.07 1.646.07 4.85s-.012 3.584-.07 4.85c-.063 1.366-.333 2.633-1.308 3.608-.975.975-2.242 1.246-3.608 1.308-1.266.058-1.646.07-4.85.07s-3.584-.012-4.85-.07c-1.366-.062-2.633-.332-3.608-1.308-.975-.975-1.245-2.242-1.308-3.608-.058-1.266-.07-1.646-.07-4.85s.012-3.584.07-4.85c.062-1.366.332-2.633 1.308-3.608.975-.975 2.242-1.245 3.608-1.308 1.266-.058 1.646-.07 4.85-.07zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948s.014 3.667.072 4.947c.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072s3.667-.014 4.947-.072c4.358-.2 6.78-2.618 6.98-6.98.058-1.281.072-1.689.072-4.948s-.014-3.667-.072-4.947c-.2-4.358-2.618-6.78-6.98-6.98-1.28-.059-1.688-.073-4.947-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>
                        </a>
                    </div>
                </div>

                <div class="flex flex-col gap-8">
                    <h4 class="text-sm font-display font-black text-gray-900 dark:text-white uppercase italic tracking-[0.2em] relative inline-block">
                        Quick Links
                        <span class="absolute -bottom-2 left-0 w-8 h-0.5 bg-primary"></span>
                    </h4>
                    <div class="flex flex-col gap-6 text-xs font-bold text-gray-500 uppercase tracking-widest">
                        <a href="#" class="hover:text-primary transition-all flex items-center gap-2 group">Home</a>
                        <a href="#why-choose-us" class="hover:text-primary transition-all flex items-center gap-2 group">Why Choose Us</a>
                        <a href="#about" class="hover:text-primary transition-all flex items-center gap-2 group">About Us</a>
                        <a href="#plans" class="hover:text-primary transition-all flex items-center gap-2 group">Plan</a>
                        <a href="#contact" class="hover:text-primary transition-all flex items-center gap-2 group">Contact</a>
                    </div>
                </div>

                <div class="flex flex-col gap-8">
                    <h4 class="text-sm font-display font-black text-gray-900 dark:text-white uppercase italic tracking-[0.2em] relative inline-block">
                        Contact Us
                        <span class="absolute -bottom-2 left-0 w-8 h-0.5 bg-primary"></span>
                    </h4>
                    <div class="space-y-8">
                        <div class="flex items-start gap-4 group">
                            <div class="size-10 rounded-xl bg-black/5 dark:bg-white/5 border border-black/10 dark:border-white/10 flex items-center justify-center text-primary group-hover:bg-primary group-hover:text-white transition-all duration-500">
                                <span class="material-symbols-outlined text-xl">location_on</span>
                            </div>
                            <p class="text-xs text-gray-600 dark:text-gray-500 font-medium leading-relaxed italic">
                                Baliwag, Bulacan,<br/>Philippines, 3006
                            </p>
                        </div>
                        <div class="flex items-center gap-4 group">
                            <div class="size-10 rounded-xl bg-black/5 dark:bg-white/5 border border-black/10 dark:border-white/10 flex items-center justify-center text-primary group-hover:bg-primary group-hover:text-white transition-all duration-500">
                                <span class="material-symbols-outlined text-xl">call</span>
                            </div>
                            <p class="text-xs text-gray-600 dark:text-gray-500 font-medium uppercase tracking-widest">0976-241-1986</p>
                        </div>
                        <div class="flex items-center gap-4 group">
                            <div class="size-10 rounded-xl bg-black/5 dark:bg-white/5 border border-black/10 dark:border-white/10 flex items-center justify-center text-primary group-hover:bg-primary group-hover:text-white transition-all duration-500">
                                <span class="material-symbols-outlined text-xl">mail</span>
                            </div>
                            <p class="text-xs text-gray-600 dark:text-gray-500 font-medium lowercase tracking-wider">horizonfitnesscorp@gmail.com</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Theme Selector for Mobile & Tablet -->
            <div class="flex lg:hidden justify-center mb-8">
                <div class="inline-flex p-1 rounded-xl bg-black/5 dark:bg-white/5 border border-black/10 dark:border-white/10 relative">
                    <button onclick="setThemeMode('light')" data-mode="light" class="theme-tab-btn flex items-center justify-center size-10 rounded-lg <?= ($currentTheme === 'light') ? 'active' : '' ?>" aria-label="Light Mode">
                        <span class="material-symbols-outlined text-[20px]">light_mode</span>
                    </button>
                    <button onclick="setThemeMode('dark')" data-mode="dark" class="theme-tab-btn flex items-center justify-center size-10 rounded-lg <?= ($currentTheme !== 'light') ? 'active' : '' ?>" aria-label="Dark Mode">
                        <span class="material-symbols-outlined text-[20px]">dark_mode</span>
                    </button>
                </div>
            </div>

            <div class="pt-10 border-t border-black/5 dark:border-white/5 text-center">
                <p class="text-[9px] font-bold text-gray-700 uppercase tracking-[0.5em]">
                    © 2026 HORIZON SYSTEM. SECURE ENVIRONMENT. ALL RIGHTS RESERVED.
                </p>
            </div>
        </div>
    </footer>

    <script>
        // --- Drag-to-Scroll Engine ---
        const slider = document.getElementById('plansSlider');
        let isDown = false;
        let startX;
        let scrollLeft;

        slider.addEventListener('mousedown', (e) => {
            isDown = true;
            slider.style.scrollSnapType = 'none'; // Temporarily disable snapping for smooth dragging
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

        const nav = document.getElementById('topNav');
        window.onscroll = function() {
            if (window.scrollY > 50) {
                nav.classList.add('scrolled');
            } else {
                nav.classList.remove('scrolled');
            }
        };

        // --- Theme Engine (Light, Dark, Orientation) ---
        const html = document.documentElement;
        const systemMedia = window.matchMedia('(prefers-color-scheme: dark)');

        function handleSystemThemeChange(e) {
            const themePref = html.getAttribute('data-theme-preference');
            if (themePref === 'system') {
                if (e.matches) {
                    html.classList.add('dark');
                    html.classList.remove('light');
                } else {
                    html.classList.remove('dark');
                    html.classList.add('light');
                }
            }
        }

        systemMedia.addEventListener('change', handleSystemThemeChange);

        function setThemeMode(mode) {
            html.setAttribute('data-theme-preference', mode);

            // Apply light/dark classes dynamically
            if (mode === 'system') {
                if (systemMedia.matches) {
                    html.classList.add('dark');
                    html.classList.remove('light');
                } else {
                    html.classList.remove('dark');
                    html.classList.add('light');
                }
            } else if (mode === 'dark') {
                html.classList.add('dark');
                html.classList.remove('light');
            } else {
                html.classList.remove('dark');
                html.classList.add('light');
            }

            // Highlight the active button in the footer segmented control
            document.querySelectorAll('.theme-tab-btn').forEach(btn => {
                if (btn.getAttribute('data-mode') === mode) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });

            // Save choice via AJAX
            const formData = new FormData();
            formData.append('action', 'toggle_theme');
            formData.append('theme', mode);

            fetch('index.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                console.log('Theme saved:', data.theme);
            })
            .catch(error => console.error('Error saving theme:', error));
        }

        // Initialize active state of bottom tabs
        document.addEventListener('DOMContentLoaded', () => {
            const activePref = html.classList.contains('dark') ? 'dark' : 'light';
            document.querySelectorAll('.theme-tab-btn').forEach(btn => {
                if (btn.getAttribute('data-mode') === activePref) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });
        });

        // Desktop header button fallback toggle
        const desktopThemeToggle = document.getElementById('themeToggle');
        if (desktopThemeToggle) {
            desktopThemeToggle.addEventListener('click', () => {
                const currentPref = html.getAttribute('data-theme-preference') || 'dark';
                let currentActual = currentPref;
                if (currentPref === 'system') {
                    currentActual = systemMedia.matches ? 'dark' : 'light';
                }
                const newTheme = currentActual === 'dark' ? 'light' : 'dark';
                setThemeMode(newTheme);
            });
        }
        function scrollFeatures(direction) {
            const container = document.getElementById('featuresContainer');
            const cardWidth = container.querySelector('.why-us-card').offsetWidth;
            const gap = 24;
            const scrollAmount = cardWidth + gap;
            
            if (direction === 'left') {
                container.scrollBy({ left: -scrollAmount, behavior: 'smooth' });
            } else {
                container.scrollBy({ left: scrollAmount, behavior: 'smooth' });
            }
        }

        function scrollToFeature(index) {
            const container = document.getElementById('featuresContainer');
            const cardWidth = container.querySelector('.why-us-card').offsetWidth;
            const gap = 24;
            container.scrollTo({ left: index * (cardWidth + gap), behavior: 'smooth' });
        }

        // Update dots on scroll
        const featuresContainer = document.getElementById('featuresContainer');
        const dots = document.querySelectorAll('.dot-indicator');
        
        featuresContainer.addEventListener('scroll', () => {
            const maxScroll = featuresContainer.scrollWidth - featuresContainer.clientWidth;
            const scrollPercent = featuresContainer.scrollLeft / maxScroll;
            const index = Math.round(scrollPercent * (dots.length - 1));
            
            dots.forEach((dot, i) => {
                if (i === index) dot.classList.add('active');
                else dot.classList.remove('active');
            });
        });

        // --- Mobile Menu Engine ---
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const closeMobileMenu = document.getElementById('closeMobileMenu');
        const mobileMenu = document.getElementById('mobileMenu');
        const mobileMenuDrawer = document.getElementById('mobileMenuDrawer');
        const mobileMenuBackdrop = document.getElementById('mobileMenuBackdrop');
        const mobileNavLinks = document.querySelectorAll('.mobile-nav-link');

        function openMenu() {
            // Instantly activate parent layout wrapper
            mobileMenu.classList.remove('opacity-0', 'pointer-events-none');
            mobileMenu.classList.add('opacity-100', 'pointer-events-auto');
            
            // Slide in sidebar immediately
            mobileMenuDrawer.classList.remove('translate-x-full');
            mobileMenuDrawer.classList.add('translate-x-0');
            
            // Fade in blurred backdrop with a delay
            mobileMenuBackdrop.classList.add('delay-150');
            mobileMenuBackdrop.classList.remove('opacity-0');
            mobileMenuBackdrop.classList.add('opacity-100');
            
            document.body.style.overflow = 'hidden'; // prevent page scroll when menu is open
        }

        function closeMenu() {
            // Slide out sidebar immediately
            mobileMenuDrawer.classList.remove('translate-x-0');
            mobileMenuDrawer.classList.add('translate-x-full');
            
            // Fade out backdrop instantly (no delay)
            mobileMenuBackdrop.classList.remove('delay-150');
            mobileMenuBackdrop.classList.remove('opacity-100');
            mobileMenuBackdrop.classList.add('opacity-0');
            
            // Deactivate wrapper layout after drawer completes translation
            setTimeout(() => {
                if (mobileMenuDrawer.classList.contains('translate-x-full')) {
                    mobileMenu.classList.remove('opacity-100', 'pointer-events-auto');
                    mobileMenu.classList.add('opacity-0', 'pointer-events-none');
                }
            }, 300);
            
            document.body.style.overflow = ''; // restore page scroll
        }

        mobileMenuToggle.addEventListener('click', openMenu);
        closeMobileMenu.addEventListener('click', closeMenu);
        mobileMenuBackdrop.addEventListener('click', closeMenu);

        mobileNavLinks.forEach(link => {
            link.addEventListener('click', closeMenu);
        });
    </script>
</body>
</html>
