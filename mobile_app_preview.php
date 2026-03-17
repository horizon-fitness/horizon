<?php
require_once 'db.php';

$gym_slug = $_GET['gym'] ?? '';
$page = null;

if (!empty($gym_slug)) {
    $stmt = $pdo->prepare("SELECT tp.*, g.gym_name FROM tenant_pages tp JOIN gyms g ON tp.gym_id = g.gym_id WHERE tp.page_slug = ? LIMIT 1");
    $stmt->execute([$gym_slug]);
    $page = $stmt->fetch();
}

// Fallback if not found or no slug
if (!$page) {
    $page = [
        'page_title' => 'Horizon Systems',
        'gym_name' => 'Horizon Systems',
        'theme_color' => '#1a73e8',
        'bg_color' => '#0a090d',
        'font_family' => 'Inter',
        'logo_path' => 'assets/default_logo.png',
        'banner_image' => 'https://images.unsplash.com/photo-1540497077202-7c8a3999166f?q=80&w=2070&auto=format&fit=crop',
        'about_text' => 'Welcome to Horizon Systems. Your fitness journey starts here.',
        'contact_text' => ''
    ];
}

$primary = $page['theme_color'];
$bg = $page['bg_color'];
$font = $page['font_family'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&family=Inter:wght@400;700&family=Outfit:wght@400;700&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <style>
        :root {
            --primary: <?= $primary ?>;
            --bg: <?= $bg ?>;
            --font: '<?= $font ?>', sans-serif;
        }
        body {
            background-color: var(--bg);
            color: white;
            font-family: var(--font);
            margin: 0;
            padding: 0;
            overflow: hidden;
            height: 100vh;
            display: flex;
            flex-direction: column;
        }
        /* Hide scrollbars for true App feel */
        ::-webkit-scrollbar { display: none; }
        * { -ms-overflow-style: none; scrollbar-width: none; }

        .android-content-area {
            flex: 1;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }
        .android-status-bar {
            height: 32px;
            padding: 0 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 11px;
            font-weight: 500;
            color: white;
            opacity: 0.9;
        }
        .header-logo-card {
            width: 36px;
            height: 36px;
            background-color: var(--primary);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .banner-card {
            width: calc(100% - 32px);
            height: 200px;
            margin: 12px auto;
            border-radius: 20px;
            overflow: hidden;
            position: relative;
            border: 1px solid rgba(255,255,255,0.1);
        }
        .banner-card img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .btn-primary {
            background-color: var(--primary);
            height: 56px;
            width: calc(100% - 64px);
            margin: 0 auto;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 15px;
            color: white;
            box-shadow: 0 8px 20px -6px var(--primary);
        }
        .btn-outline {
            background: #0d0d0d;
            border: 1px solid rgba(255,255,255,0.05);
            height: 56px;
            width: calc(100% - 64px);
            margin: 0 auto;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 15px;
            color: #888;
        }
        .badge {
            background: rgba(255,255,255,0.03);
            background: #111;
            padding: 6px 16px;
            border-radius: 99px;
            font-size: 10.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--primary);
            display: inline-flex;
            align-items: center;
            gap: 12px;
        }
        .dot {
            width: 6px;
            height: 6px;
            background-color: var(--primary);
            border-radius: 50%;
        }
    </style>
</head>
<body class="no-scrollbar">

    <!-- App Content -->
    <div class="flex-1 flex flex-col pt-1">
        
        <!-- Status Bar -->
        <div class="android-status-bar">
            <span>7:06</span>
            <div class="flex items-center gap-1.5 opacity-60">
                <span class="material-symbols-outlined text-[14px]">signal_cellular_4_bar</span>
                <span class="material-symbols-outlined text-[14px]">wifi</span>
                <span class="material-symbols-outlined text-[14px]">battery_full</span>
            </div>
        </div>

        <!-- Native Header -->
        <div class="px-5 h-[56px] flex items-center gap-3.5">
            <div id="header-logo-container" class="header-logo-card">
                <img id="logo-img" src="<?= !empty($page['logo_path']) ? $page['logo_path'] : '' ?>" class="size-5 object-contain <?= empty($page['logo_path']) ? 'hidden' : '' ?>">
                <span id="logo-icon" class="material-symbols-outlined text-white text-lg <?= !empty($page['logo_path']) ? 'hidden' : '' ?>">bolt</span>
            </div>
            <span class="text-[16px] font-bold tracking-[0.05em] text-white uppercase gym-name-display"><?= htmlspecialchars($page['page_title'] ?? $page['gym_name']) ?></span>
        </div>

        <div class="android-content-area no-scrollbar">
            <!-- Banner Section -->
            <div class="banner-card">
                <img id="banner-img" src="<?= !empty($page['banner_image']) ? $page['banner_image'] : 'https://images.unsplash.com/photo-1540497077202-7c8a3999166f?q=80&w=2070&auto=format&fit=crop' ?>">
            </div>

            <!-- Hero Section -->
            <div class="text-center mt-5">
                <div class="badge mx-auto bg-[#131217]">
                    <span class="dot"></span>
                    <span>Open for Membership</span>
                </div>

                <h1 id="hero-title" class="text-[30px] font-bold text-white mt-6 px-4 leading-[1.2]">
                    Elevate Your Fitness at <br/> <span class="gym-name-display"><?= htmlspecialchars($page['page_title'] ?? $page['gym_name']) ?></span>
                </h1>

                <p id="about-text" class="text-[14px] font-medium text-white/50 mt-4 px-8 leading-relaxed">
                    <?= htmlspecialchars($page['about_text'] ?? 'Welcome to Horizon Systems. Your fitness journey starts here.') ?>
                </p>
            </div>

            <!-- Buttons Row -->
            <div class="mt-8 flex flex-col gap-3.5 pb-12">
                <div class="btn-primary">Sign In</div>
                <div class="btn-primary">Register</div>
                <div class="btn-outline">Get Member APK</div>
                
                <!-- Footer Contact (Matching XML exactly) -->
                <div id="contact-text" class="mt-8 text-[11px] font-bold uppercase tracking-[0.05em] text-white/20 text-center px-10 leading-loose">
                    <?= htmlspecialchars($page['contact_text'] ?? '') ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Nav Bar -->
    <div class="android-nav-bar">
        <div class="nav-line"></div>
    </div>

    <script>
        window.addEventListener('message', function(event) {
            if (event.data.type === 'updateStyles') {
                const data = event.data.data;
                
                // Colors
                if (data.theme_color) document.documentElement.style.setProperty('--primary', data.theme_color);
                if (data.bg_color) document.documentElement.style.setProperty('--bg', data.bg_color);
                if (data.font_family) document.documentElement.style.setProperty('--font', `'${data.font_family}', sans-serif`);
                
                // Text
                if (data.page_title) {
                    document.querySelectorAll('.gym-name-display').forEach(n => n.innerText = data.page_title);
                }
                if (data.about_text !== undefined) document.getElementById('about-text').innerText = data.about_text;
                if (data.contact_text !== undefined) document.getElementById('contact-text').innerText = data.contact_text;
                
                // Media
                if (data.logo_preview) {
                    const img = document.getElementById('logo-img');
                    const icon = document.getElementById('logo-icon');
                    img.src = data.logo_preview;
                    img.classList.remove('hidden');
                    icon.classList.add('hidden');
                }
                if (data.banner_preview) {
                    document.getElementById('banner-img').src = data.banner_preview;
                }
            }
        });
    </script>
</body>
</html>
