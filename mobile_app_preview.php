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
        'theme_color' => '#8c2bee',
        'bg_color' => '#0a090d',
        'font_family' => 'Lexend',
        'logo_path' => 'assets/default_logo.png',
        'banner_image' => 'https://images.unsplash.com/photo-1534438327276-14e5300c3a48?q=80&w=2070&auto=format&fit=crop',
        'about_text' => 'Welcome to Horizon Systems. Your fitness journey starts here.',
        'contact_text' => 'Visit us at our primary training facility.'
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
            height: 38px;
            padding: 0 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 11px;
            font-weight: 700;
        }
        .android-nav-bar {
            height: 48px;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .nav-line {
            width: 80px;
            height: 3px;
            background: rgba(255,exp255,255,0.3);
            border-radius: 99px;
        }
        .header-logo-card {
            width: 40px;
            height: 40px;
            background-color: var(--primary);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .banner-card {
            width: calc(100% - 32px);
            height: 220px;
            margin: 16px auto;
            border-radius: 24px;
            overflow: hidden;
            position: relative;
        }
        .banner-card img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .banner-overlay {
            position: absolute;
            inset: 0;
            background: rgba(0,0,0,0.1);
        }
        .btn-primary {
            background-color: var(--primary);
            height: 60px;
            width: calc(100% - 64px);
            margin: 0 auto;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            text-transform: none;
            font-size: 16px;
        }
        .btn-outline {
            border: 1px solid rgba(255,255,255,0.1);
            height: 60px;
            width: calc(100% - 64px);
            margin: 0 auto;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 16px;
            color: white;
        }
        .badge {
            background: rgba(255,255,255,0.03);
            padding: 8px 18px;
            border-radius: 99px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            color: var(--primary);
            display: inline-flex;
            align-items: center;
            gap: 8px;
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

    <!-- App Content (No Status Bar as requested) -->
    <div class="flex-1 flex flex-col">
        <!-- Native Header (Matching activity_landing.xml) -->
        <div class="px-6 h-[72px] flex items-center gap-[14px]">
            <div id="header-logo-container" class="header-logo-card">
                <img id="logo-img" src="<?= !empty($page['logo_path']) ? $page['logo_path'] : '' ?>" class="size-6 object-contain <?= empty($page['logo_path']) ? 'hidden' : '' ?>">
                <span id="logo-icon" class="material-symbols-outlined text-white text-xl <?= !empty($page['logo_path']) ? 'hidden' : '' ?>">bolt</span>
            </div>
            <span class="text-[16px] font-bold tracking-[0.1em] text-white uppercase gym-name-display"><?= htmlspecialchars($page['page_title'] ?? $page['gym_name']) ?></span>
        </div>

        <div class="android-content-area no-scrollbar">
            <!-- Banner Section -->
            <div class="banner-card">
                <img id="banner-img" src="<?= !empty($page['banner_image']) ? $page['banner_image'] : 'https://images.unsplash.com/photo-1534438327276-14e5300c3a48?q=80&w=2070&auto=format&fit=crop' ?>">
                <div class="banner-overlay"></div>
            </div>

            <!-- Hero Section -->
            <div class="text-center mt-6">
                <div class="badge mx-auto">
                    <span class="dot"></span>
                    <span>Open for Registration</span>
                </div>

                <h1 class="text-[36px] font-bold uppercase text-white mt-5 px-6 leading-[1.1]">
                    Your Vision,<br/>Our Platform.
                </h1>

                <p id="about-text" class="text-[14px] font-medium text-white/50 mt-4 px-10 leading-relaxed">
                    <?= htmlspecialchars($page['about_text'] ?? 'Empowering fitness owners with Horizon\'s industry-leading technology.') ?>
                </p>
            </div>

            <!-- Buttons Row (Exact match for Material Design) -->
            <div class="mt-8 flex flex-col gap-3.5 pb-12">
                <div class="btn-primary">Sign In</div>
                <div class="btn-primary">Register</div>
                <div class="btn-outline">Get Member APK</div>
                
                <!-- Footer Contact (Matching XML exactly) -->
                <div id="contact-text" class="mt-8 text-[10px] font-bold uppercase tracking-[0.1em] text-white/20 text-center px-8 leading-loose">
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
