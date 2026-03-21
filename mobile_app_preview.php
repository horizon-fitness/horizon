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
        'page_title' => 'WELCOME BACK',
        'gym_name' => 'Horizon Systems',
        'theme_color' => '#1a73e8',
        'bg_color' => '#0a090d',
        'font_family' => 'Inter',
        'logo_path' => 'assets/default_logo.png',
        'banner_image' => 'https://images.unsplash.com/photo-1540497077202-7c8a3999166f?q=80&w=2070&auto=format&fit=crop',
        'about_text' => 'AUTHORIZED PERSONNEL ONLY',
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
            --primary: <?= $page['theme_color'] ?? '#A133FF' ?>;
            --bg: <?= $page['bg_color'] ?? '#08080A' ?>;
            --field-bg: #121214;
            --font: '<?= $page['font_family'] ?? 'Lexend' ?>', sans-serif;
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
        ::-webkit-scrollbar { display: none; }
        * { -ms-overflow-style: none; scrollbar-width: none; box-sizing: border-box; }

        .android-status-bar {
            height: 48px;
            padding: 0 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 14px;
            font-weight: 600;
            color: white;
            z-index: 20;
            margin-top: 8px;
        }

        .android-content-area {
            flex: 1;
            padding: 32px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            position: relative;
        }

        .hero-section { width: 100%; margin-bottom: 48px; text-align: left; }
        .hero-title { font-size: 42px; font-weight: 800; letter-spacing: -0.01em; margin: 0; color: white; line-height: 1.1; }
        .hero-subtitle { font-size: 11px; font-weight: 700; letter-spacing: 0.1em; color: rgba(255,255,255,0.3); margin-top: 12px; text-transform: uppercase; }

        .badge-secure { align-self: flex-start; border: 1px solid rgba(255,255,255,0.2); padding: 6px 18px; border-radius: 99px; font-size: 10px; font-weight: 700; letter-spacing: 0.15em; color: rgba(255,255,255,0.5); margin-top: 42px; margin-bottom: 42px; text-transform: uppercase; }

        .login-form { width: 100%; display: flex; flex-direction: column; }
        .input-group { width: 100%; margin-bottom: 36px; }
        .input-label { font-size: 11px; font-weight: 800; letter-spacing: 0.15em; color: rgba(255,255,255,0.5); margin-bottom: 16px; display: block; }
        .input-box { width: 100%; height: 64px; background: var(--field-bg); border-radius: 14px; display: flex; align-items: center; padding: 0 20px; gap: 16px; }
        .input-icon { font-size: 24px; color: rgba(255,255,255,0.4); }
        .input-placeholder { font-size: 16px; color: rgba(255,255,255,0.15); font-weight: 500; }

        .btn-authorize { width: 100%; height: 78px; background: var(--primary); border-radius: 20px; margin-top: 12px; display: flex; align-items: center; justify-content: center; color: white; font-weight: 800; font-size: 17px; letter-spacing: 0.05em; gap: 12px; box-shadow: 0 16px 32px rgba(157, 53, 255, 0.2); }

        .form-footer { margin-top: 54px; font-size: 10px; font-weight: 800; letter-spacing: 0.05em; text-align: center; color: rgba(255,255,255,0.4); }
        .form-footer span { color: var(--primary); margin-left: 4px; }

        .nav-pill {
            width: 120px;
            height: 4px;
            background: rgba(255,255,255,0.2);
            border-radius: 99px;
            margin: 12px auto;
        }
    </style>
</head>
<body class="no-scrollbar">

    <!-- App Content -->
    <div class="flex-1 flex flex-col h-full">
        <!-- Status Bar -->
        <div class="android-status-bar">
            <span id="android-clock">--:--</span>
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-[20px]">signal_cellular_4_bar</span>
                <span class="material-symbols-outlined text-[20px]">wifi</span>
                <span class="material-symbols-outlined text-[20px]">battery_full</span>
            </div>
        </div>

        <div class="android-content-area">
            
            <div class="hero-section">
                <h1 id="hero-title-display" class="hero-title"><?= htmlspecialchars($page['page_title'] ?? 'WELCOME BACK') ?></h1>
                <p id="hero-subtitle-display" class="hero-subtitle"><?= htmlspecialchars($page['about_text'] ?? 'AUTHORIZED PERSONNEL ONLY') ?></p>
                
                <div class="badge-secure">Secure Access</div>
            </div>

            <div class="login-form">
                <div class="input-group">
                    <span class="input-label">IDENTITY</span>
                    <div class="input-box">
                        <span class="material-symbols-outlined input-icon">person_filled</span>
                        <span class="input-placeholder">Username</span>
                    </div>
                </div>

                <div class="input-group">
                    <div class="flex justify-between items-center mb-4">
                        <span class="input-label mb-0">SECURITY KEY</span>
                        <span style="color: var(--primary); font-size: 11px; font-weight: 900; letter-spacing: 0.05em;">FORGOT?</span>
                    </div>
                    <div class="input-box">
                        <span class="material-symbols-outlined input-icon">lock</span>
                        <span class="input-placeholder flex-1">••••••••</span>
                        <span class="material-symbols-outlined input-icon" style="opacity: 0.6">visibility</span>
                    </div>
                </div>

                <div class="btn-authorize">
                    AUTHORIZE ENTRY
                    <span class="material-symbols-outlined">arrow_forward</span>
                </div>

                <div class="form-footer">
                    NEW TO THE FAMILY? <span>CREATE ACCOUNT</span>
                </div>
            </div>
        </div>

        <!-- Bottom Pill -->
        <div class="nav-pill"></div>
    </div>


    <!-- Nav Bar -->
    <div class="android-nav-bar">
        <div class="nav-pill"></div>
    </div>

    <script>
        function updateClock() {
            const now = new Date();
            const timeStr = now.getHours().toString().padStart(2, '0') + ':' + now.getMinutes().toString().padStart(2, '0');
            const clockEl = document.getElementById('android-clock');
            if (clockEl) clockEl.innerText = timeStr;
        }
        setInterval(updateClock, 1000);
        updateClock();

        window.addEventListener('message', function(event) {
            if (event.data.type === 'updateStyles') {
                const data = event.data.data;
                
                // Colors
                if (data.theme_color) document.documentElement.style.setProperty('--primary', data.theme_color);
                if (data.bg_color) document.documentElement.style.setProperty('--bg', data.bg_color);
                if (data.font_family) document.documentElement.style.setProperty('--font', `'${data.font_family}', sans-serif`);
                
                // Text - Map form fields to display IDs
                if (data.page_title) {
                    const title = document.getElementById('hero-title-display');
                    if (title) title.innerText = data.page_title;
                }
                if (data.about_text) {
                    const subtitle = document.getElementById('hero-subtitle-display');
                    if (subtitle) subtitle.innerText = data.about_text;
                }
                
                // Media
                if (data.logo_preview) {
                    const img = document.getElementById('logo-img');
                    if (img) {
                        img.src = data.logo_preview;
                        img.classList.remove('hidden');
                    }
                }
            }
        });

        // Notify parent that preview is ready
        window.parent.postMessage({ type: 'previewReady' }, '*');
    </script>
</body>
</html>
