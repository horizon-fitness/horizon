<?php
require_once 'db.php';

$gym_slug = $_GET['gym'] ?? '';

if (empty($gym_slug)) {
    header("Location: index.php");
    exit;
}

// Fetch Tenant Page Data
$stmtPage = $pdo->prepare("SELECT tp.*, g.gym_name, g.gym_id, g.email as gym_email, g.contact_number as gym_contact 
                           FROM tenant_pages tp 
                           JOIN gyms g ON tp.gym_id = g.gym_id 
                           WHERE tp.page_slug = ? AND tp.is_active = 1 LIMIT 1");
$stmtPage->execute([$gym_slug]);
$page = $stmtPage->fetch();

if (!$page) {
    die("Gym page not found or is currently inactive.");
}

$primary_color = $page['theme_color'] ?? '#8c2bee';
$bg_color = $page['bg_color'] ?? '#0a090d';
$font_family = $page['font_family'] ?? 'Lexend';
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title><?= htmlspecialchars($page['page_title']) ?> | Horizon Systems</title>
    <link href="https://fonts.googleapis.com" rel="preconnect"/>
    <link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&family=Inter:wght@400;700&family=Outfit:wght@300;400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
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
            --pg-bg: <?= $bg_color ?>;
            --pg-primary: <?= $primary_color ?>;
            --pg-font: '<?= $font_family ?>', 'Plus Jakarta Sans', sans-serif;
        }
        body { 
            font-family: var(--pg-font); 
            background-color: var(--pg-bg); 
            color: #e2e8f0; 
            scroll-behavior: smooth; 
        }
        .font-display { font-family: var(--pg-font); }
        
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
            background: linear-gradient(rgba(255,255,255,0.2), transparent);
            opacity: 0;
            transition: opacity 0.3s;
        }
        .btn-premium:hover::after { opacity: 1; }
        .btn-premium:hover { transform: translateY(-2px); box-shadow: 0 10px 25px -5px var(--pg-primary); }
        
        .text-gradient {
            background: linear-gradient(to right, #fff, #a1a1aa);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        /* APK App Mode Adjustments (High-Fidelity Mobile Landing Replica) */
        <?php if (isset($_GET['preview'])): ?>
        header { display: none !important; }
        main { padding-top: 0 !important; }
        .hero-section { text-align: center; }
        .banner-card { 
            width: 100%; 
            height: 200px; 
            border-radius: 20px; 
            margin-bottom: 24px; 
            overflow: hidden; 
            box-shadow: 0 10px 30px -10px rgba(0,0,0,0.5);
        }
        .banner-card img { width: 100%; height: 100%; object-fit: cover; }
        
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
            background: rgba(255,255,255,0.4);
            border-radius: 99px;
        }
        <?php endif; ?>
    </style>
</head>
<body class="antialiased min-h-screen flex flex-col font-display selection:bg-primary/30 selection:text-white">

    <header class="w-full px-6 md:px-12 py-5 flex justify-between items-center bg-background-dark/80 backdrop-blur-xl fixed top-0 z-50 border-b border-white/5 transition-all">
        <div class="flex items-center gap-4">
            <?php if($page['logo_path']): ?>
                <img src="<?= htmlspecialchars($page['logo_path']) ?>" alt="Logo" class="h-9 w-auto">
            <?php else: ?>
                <div class="size-9 rounded-xl bg-primary flex items-center justify-center shadow-lg shadow-primary/20">
                    <span class="material-symbols-outlined text-white text-xl">bolt</span>
                </div>
            <?php endif; ?>
            <h1 class="text-xl font-bold tracking-tight text-white font-display uppercase tracking-widest gym-name-display"><?= htmlspecialchars($page['page_title'] ?? $page['gym_name']) ?></h1>
        </div>
        <div class="hidden md:flex items-center gap-8">
            <nav class="flex items-center gap-6">
                <a href="#about" class="text-xs font-medium text-gray-400 hover:text-white transition-colors">About</a>
                <a href="#contact" class="text-xs font-medium text-gray-400 hover:text-white transition-colors">Contact</a>
            </nav>
            <div class="h-4 w-px bg-white/10"></div>
            <a href="login.php?gym=<?= $gym_slug ?>" class="h-10 px-6 rounded-xl border border-white/10 hover:bg-white/5 flex items-center text-xs font-semibold text-white transition-all">
                Member Login
            </a>
        </div>
    </header>

    <main class="flex-1 flex flex-col items-center <?= isset($_GET['preview']) ? 'pt-4' : 'pt-32' ?> pb-20 px-6 relative overflow-hidden">
        <!-- Premium Ambient Glows -->
        <div class="fixed top-[-10%] right-[-5%] size-[600px] bg-primary/10 rounded-full blur-[120px] -z-10 pointer-events-none"></div>
        <div class="fixed bottom-[10%] left-[-5%] size-[500px] bg-primary/5 rounded-full blur-[100px] -z-10 pointer-events-none"></div>

        <?php if (isset($_GET['preview'])): ?>
        <!-- Android Status Bar -->
        <div class="android-status-bar">
            <span>6:42</span>
            <div class="flex items-center gap-1.5 opacity-80">
                <span class="material-symbols-outlined text-[14px]">signal_cellular_4_bar</span>
                <span class="material-symbols-outlined text-[14px]">wifi</span>
                <span class="material-symbols-outlined text-[14px]">battery_full</span>
            </div>
        </div>

        <!-- Mobile Landing Page Replica -->
        <div class="w-full max-w-[340px] flex flex-col items-center pt-2">
            <!-- Header Row (Logo + Title) -->
            <div class="flex items-center gap-3 mb-6 w-full px-4 pt-2">
                <div id="preview-logo-container" class="size-9 rounded-xl bg-primary flex items-center justify-center">
                    <img id="logo-image-display" src="<?= !empty($page['logo_path']) ? $page['logo_path'] : '' ?>" alt="Logo" class="size-6 object-contain <?= empty($page['logo_path']) ? 'hidden' : '' ?>">
                    <span id="logo-placeholder-icon" class="material-symbols-outlined text-white text-lg <?= !empty($page['logo_path']) ? 'hidden' : '' ?>">bolt</span>
                </div>
                <h1 class="text-[15px] font-black tracking-widest text-white uppercase gym-name-display"><?= htmlspecialchars($page['page_title'] ?? $page['gym_name']) ?></h1>
            </div>

            <!-- Banner Card -->
            <div class="banner-card mx-4">
                <img id="banner-image-display" src="<?= !empty($page['banner_image']) ? $page['banner_image'] : 'https://images.unsplash.com/photo-1534438327276-14e5300c3a48?q=80&w=2070&auto=format&fit=crop' ?>" alt="Banner">
            </div>

            <div class="inline-flex items-center gap-2 px-5 py-2 rounded-full bg-white/[0.03] border border-white/[0.02] mb-8 text-primary shadow-inner">
                <span class="size-1.5 rounded-full bg-primary animate-pulse"></span>
                <span class="text-[10px] font-black uppercase tracking-[0.2em]">Open for Membership</span>
            </div>

            <h2 class="text-4xl font-black uppercase tracking-tighter text-white mb-4 leading-[1.1] text-center px-4">
                Elevate Your <br/> Fitness at <br/> <span class="gym-name-display"><?= htmlspecialchars($page['page_title'] ?? $page['gym_name']) ?></span>
            </h2>

            <p id="hero-description-display" class="text-gray-400 text-[13px] font-medium leading-relaxed mb-12 px-6 text-center opacity-70">
                <?= htmlspecialchars($page['about_text'] ?? 'Welcome to Horizon Systems. Your fitness journey starts here.') ?>
            </p>

            <div class="w-full space-y-3.5 px-4 mb-10">
                <div class="h-[60px] rounded-2xl bg-primary flex items-center justify-center text-[13px] font-black uppercase tracking-widest text-white shadow-xl shadow-primary/10 transition-transform active:scale-95">Sign In</div>
                <div class="h-[60px] rounded-2xl bg-primary flex items-center justify-center text-[13px] font-black uppercase tracking-widest text-white shadow-xl shadow-primary/10 transition-transform active:scale-95">Register</div>
            </div>

            <div id="contact-text-mobile" class="mt-8 text-[10px] font-bold uppercase tracking-[0.2em] text-gray-500 text-center px-8 leading-loose opacity-60">
                <?= htmlspecialchars($page['contact_text'] ?? '') ?>
            </div>
        </div>

        <!-- Android Nav Bar -->
        <div class="android-nav-bar">
            <div class="android-nav-line"></div>
        </div>
        <?php else: ?>
        <section class="max-w-5xl w-full text-center relative mb-24 mt-10 md:mt-20">
            <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full border border-white/10 bg-white/5 mb-8">
                <span class="relative flex h-2 w-2">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-primary opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2 w-2 bg-primary"></span>
                </span>
                <span class="text-[10px] font-semibold uppercase tracking-wider text-gray-300">Open for Membership</span>
            </div>
            
            <h2 class="text-5xl md:text-8xl font-bold tracking-tight text-white mb-8 leading-[1.1] font-display">
                Elevate Your <span class="text-primary">Fitness</span> <br class="hidden md:block"/> at <span class="text-gradient gym-name-display"><?= htmlspecialchars($page['page_title'] ?? $page['gym_name']) ?></span>
            </h2>
            
            <p class="text-gray-400 text-base md:text-xl max-w-2xl mx-auto leading-relaxed mb-12 font-body font-light">
                Discover a premium workout experience powered by Horizon's elite technology and world-class coaching staff.
            </p>
            
            <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
                <a href="login.php?gym=<?= $gym_slug ?>" class="h-14 px-10 rounded-2xl btn-premium flex items-center justify-center text-sm font-bold text-white">
                    Member Login
                </a>
                <!-- Automatic APK Download Logic -->
                <?php 
                    $final_apk_link = !empty($page['app_download_link']) ? $page['app_download_link'] : (file_exists('app-debug.apk') ? 'app-debug.apk' : '#');
                ?>
                <a id="app-download-btn" href="<?= htmlspecialchars($final_apk_link) ?>" class="h-14 px-10 rounded-2xl bg-white/5 border border-white/10 hover:bg-white/10 flex items-center justify-center text-sm font-bold text-white transition-all group relative overflow-hidden">
                    <span class="absolute inset-0 bg-primary/20 animate-pulse"></span>
                    <span class="relative flex items-center">
                        <span class="material-symbols-outlined text-xl mr-2.5">download</span>
                        Get App (app-debug.apk)
                    </span>
                </a>
            </div>
        </section>
        <?php endif; ?>

        <section class="max-w-5xl w-full" id="portal-info">
            <!-- Information Card -->
            <div class="glass-card p-10 flex flex-col group overflow-hidden relative text-center">
                <div class="absolute -top-6 -right-6 p-8 opacity-[0.03] group-hover:opacity-[0.06] transition-opacity pointer-events-none">
                    <span class="material-symbols-outlined text-[140px]">fitness_center</span>
                </div>
                <div class="size-14 rounded-2xl bg-primary/10 text-primary flex items-center justify-center mb-8 mx-auto">
                    <span class="material-symbols-outlined text-3xl">bolt</span>
                </div>
                <h3 class="text-2xl font-bold text-white mb-4 font-display text-center">Experience the Difference</h3>
                <p class="text-gray-400 text-sm mb-10 leading-relaxed font-body font-light max-w-2xl mx-auto">
                    Access our elite workout tracking and world-class management platform. For membership inquiries and registrations, please visit our front desk.
                </p>
                <div class="flex items-center justify-center gap-4">
                    <a href="login.php?gym=<?= $gym_slug ?>" class="h-14 px-10 rounded-xl btn-premium flex items-center justify-center text-sm font-bold text-white">
                        Access Portal
                    </a>
                </div>
            </div>
        </section>

        <section id="about" class="max-w-4xl w-full mt-32 py-20 text-center relative border-y border-white/5">
            <span class="text-[10px] font-bold uppercase text-primary tracking-[0.4em] mb-8 block font-display">The Philosophy</span>
            <p class="text-white text-3xl md:text-5xl font-bold leading-[1.2] mb-10 font-display">
                "Modern technology meets <br/> <span class="text-primary">unwavering dedication</span>."
            </p>
            <p id="about-text-display" class="text-gray-400 text-lg italic leading-relaxed font-body font-light opacity-90 max-w-2xl mx-auto">
                <?= nl2br(htmlspecialchars($page['about_text'] ?? 'Experience fitness like never before with our cutting-edge multi-tenant facility.')) ?>
            </p>
        </section>
    </main>

    <footer id="contact" class="w-full py-20 px-6 md:px-12 bg-white/[0.01] border-t border-white/5 mt-10">
        <div class="max-w-6xl mx-auto flex flex-col md:flex-row justify-between gap-16">
            <div class="flex-1 space-y-8">
                 <div class="flex items-center gap-3">
                    <div class="size-8 rounded-lg bg-primary flex items-center justify-center shadow-lg shadow-primary/20">
                        <span class="material-symbols-outlined text-white text-lg">bolt</span>
                    </div>
                    <h1 class="text-xl font-bold tracking-tight text-white font-display uppercase tracking-wider gym-name-display"><?= htmlspecialchars($page['page_title'] ?? $page['gym_name']) ?></h1>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-10">
                    <div class="space-y-4">
                        <p class="text-[10px] font-bold uppercase text-primary tracking-[0.3em] font-display">Contact</p>
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
                        <p class="text-[10px] font-bold uppercase text-primary tracking-[0.3em] font-display">Location</p>
                        <p id="contact-text-display" class="text-gray-400 text-sm italic leading-relaxed font-body font-light">
                            <?= nl2br(htmlspecialchars($page['contact_text'] ?? 'Visit us at our primary training facility.')) ?>
                        </p>
                    </div>
                </div>
            </div>
            
            <div class="flex flex-col justify-end items-start md:items-end text-left md:text-right">
                <p class="text-[10px] font-bold uppercase tracking-[0.4em] text-gray-600 mb-2 font-display">Horizon Partner</p>
                <p class="text-primary/40 text-[9px] font-bold uppercase tracking-[0.2em] font-display">Enterprise Fitness v2.0</p>
                <div class="mt-8 flex gap-4">
                    <div class="size-10 rounded-xl bg-white/5 border border-white/10 flex items-center justify-center text-gray-500 hover:text-white transition-all cursor-pointer">
                        <span class="material-symbols-outlined text-xl">brand_awareness</span>
                    </div>
                    <div class="size-10 rounded-xl bg-white/5 border border-white/10 flex items-center justify-center text-gray-500 hover:text-white transition-all cursor-pointer">
                        <span class="material-symbols-outlined text-xl">social_leaderboard</span>
                    </div>
                </div>
            </div>
        </div>
    </footer>
    
    <script>
        // Real-time Preview Listener
        window.addEventListener('message', function(event) {
            if (event.data.type === 'updateStyles') {
                const data = event.data.data;
                const primary = data.theme_color || '<?= $primary_color ?>';
                const bg = data.bg_color || '<?= $bg_color ?>';
                const font = data.font_family || '<?= $font_family ?>';
                
                // Update CSS variables
                document.documentElement.style.setProperty('--pg-bg', bg);
                document.documentElement.style.setProperty('--pg-primary', primary);
                document.documentElement.style.setProperty('--pg-font', `'${font}', 'Plus Jakarta Sans', sans-serif`);
                
                // Update Logo
                if (data.logo_preview) {
                    // Update Header Logo
                    let logoContainer = document.querySelector('header .flex.items-center.gap-4');
                    if (logoContainer) {
                        let logoImg = logoContainer.querySelector('img');
                        if (logoImg) {
                            logoImg.src = data.logo_preview;
                        } else {
                            let placeholder = logoContainer.querySelector('div.size-9');
                            if (placeholder) {
                                const newImg = document.createElement('img');
                                newImg.src = data.logo_preview;
                                newImg.alt = "Logo";
                                newImg.className = "h-9 w-auto";
                                placeholder.replaceWith(newImg);
                            }
                        }
                    }
                    
                    // Update Mobile Preview Logo
                    const mobileLogoImg = document.getElementById('logo-image-display');
                    const mobileLogoPlaceholder = document.getElementById('logo-placeholder-icon');
                    if (mobileLogoImg) {
                        mobileLogoImg.src = data.logo_preview;
                        mobileLogoImg.classList.remove('hidden');
                    }
                    if (mobileLogoPlaceholder) {
                        mobileLogoPlaceholder.classList.add('hidden');
                    }
                }
                
                // Update Gym Name/Title
                if (data.page_title) {
                    document.title = data.page_title + " | Horizon Systems";
                    const names = document.querySelectorAll('.gym-name-display');
                    names.forEach(n => n.innerText = data.page_title);
                }

                // Update App Download Links
                if (data.app_download_link) {
                    const downloadBtns = [document.getElementById('app-download-btn'), document.getElementById('staff-app-btn')];
                    downloadBtns.forEach(btn => { if(btn) btn.href = data.app_download_link; });
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

                // Update Banner Preview
                if (data.banner_preview) {
                    const bannerImg = document.getElementById('banner-image-display');
                    if (bannerImg) bannerImg.src = data.banner_preview;
                }

                // Update About/Hero Description
                if (data.about_text !== undefined) {
                    const heroDesc = document.getElementById('hero-description-display');
                    if (heroDesc) heroDesc.innerText = data.about_text;
                }
            }
        });
    </script>
</body>
</html>
