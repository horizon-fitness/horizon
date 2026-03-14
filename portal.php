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

if (!$page) die("Gym page not found or is currently inactive.");

// Theme Configuration
$primary_color = $page['theme_color'] ?? '#8c2bee';
$secondary_color = $page['secondary_color'] ?? '#14121a';
$radius = $page['border_radius'] ?? '24px';
$font = $page['font_family'] ?? 'Lexend';
$theme_mode = $page['theme_mode'] ?? 'dark';
$is_preview = isset($_GET['preview']);

// Helper for Hex to RGB
function hex2rgb($hex) {
    $hex = str_replace("#", "", $hex);
    if(strlen($hex) == 3) {
        $r = hexdec(substr($hex, 0, 1).substr($hex, 0, 1));
        $g = hexdec(substr($hex, 1, 1).substr($hex, 1, 1));
        $b = hexdec(substr($hex, 2, 1).substr($hex, 2, 1));
    } else {
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
    }
    return "$r, $g, $b";
}
$secondary_rgb = hex2rgb($secondary_color);
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title><?= htmlspecialchars($page['portal_tab_text'] ?? $page['page_title']) ?> | Horizon Systems</title>
    <link href="https://fonts.googleapis.com" rel="preconnect"/>
    <link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&family=Inter:wght@400;700&family=Outfit:wght@400;700&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { 
                extend: { 
                    colors: { 
                        "primary": "<?= $primary_color ?>", 
                        "secondary": "<?= $secondary_color ?>",
                        "background-dark": "<?= ($theme_mode == 'dark') ? '#0a090d' : '#f8f9fa' ?>", 
                        "surface-dark": "<?= ($theme_mode == 'dark') ? '#121017' : '#ffffff' ?>"
                    },
                    fontFamily: { "display": ["<?= $font ?>", "sans-serif"] },
                    borderRadius: { "custom": "<?= $radius ?>" }
                }
            }
        }
    </script>
    <style id="dynamicStyles">
        :root {
            --primary: <?= $primary_color ?>;
            --secondary: <?= $secondary_color ?>;
            --secondary-rgb: <?= $secondary_rgb ?>;
            --radius: <?= $radius ?>;
            --font-display: '<?= $font ?>', sans-serif;
            --bg: <?= ($theme_mode == 'dark') ? '#0a090d' : '#f8f9fa' ?>;
            --text: <?= ($theme_mode == 'dark') ? '#ffffff' : '#0a090d' ?>;
        }
        body { font-family: var(--font-display); background-color: var(--bg); color: var(--text); scroll-behavior: smooth; }
        
        .glass-card { 
            background: <?= ($theme_mode == 'dark') ? 'rgba(var(--secondary-rgb, 20, 18, 26), 0.4)' : 'rgba(255,255,255,0.8)' ?>; 
            border: 1px solid rgba(255,255,255,0.05); 
            border-radius: var(--radius); 
            backdrop-filter: blur(20px); 
            transition: all 0.3s ease;
        }
        
        .rounded-custom { border-radius: var(--radius) !important; }
        .rounded-3xl, .rounded-2xl { border-radius: var(--radius) !important; }
        
        .btn-primary { 
            background: linear-gradient(135deg, var(--primary), var(--primary)dd); 
            color: white; 
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); 
            box-shadow: 0 10px 40px -15px var(--primary)66;
            border-radius: var(--radius) !important;
        }
        .btn-primary:hover { transform: translateY(-4px) scale(1.02); box-shadow: 0 20px 40px -10px var(--primary)aa; }
        
        .btn-secondary {
            background: var(--secondary);
            color: white;
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: var(--radius) !important;
            transition: all 0.3s ease;
        }
        .btn-secondary:hover { background: var(--secondary); opacity: 0.8; transform: translateY(-2px); }

        .text-glow { text-shadow: 0 0 30px var(--primary)44; }
        .hero-gradient { background: radial-gradient(circle at center, var(--primary)11 0%, transparent 70%); }
        .text-primary { color: var(--primary) !important; }
        
        /* Navigation Color Fix */
        header .nav-link { color: <?= ($theme_mode == 'dark') ? '#6b7280' : '#4b5563' ?>; }
        header .nav-link:hover { color: var(--primary); }
    </style>
</head>
<body class="antialiased min-h-screen flex flex-col font-display selection:bg-primary/30 selection:text-white">

    <header class="w-full px-8 md:px-12 py-8 flex justify-between items-center bg-background-dark/40 backdrop-blur-2xl fixed top-0 z-50 border-b border-white/5 transition-all duration-500 overflow-hidden">
        <div class="absolute inset-0 bg-gradient-to-r from-primary/5 via-transparent to-transparent opacity-50"></div>
        <div class="relative flex items-center gap-5">
            <?php if($page['logo_path']): ?>
                <img id="prevLogo" src="<?= htmlspecialchars($page['logo_path']) ?>" alt="Logo" class="h-10 w-auto filter drop-shadow-2xl viewable object-contain">
            <?php else: ?>
                <div id="prevLogoPlaceholder" class="size-10 rounded-2xl bg-primary flex items-center justify-center shadow-lg shadow-primary/20 shrink-0">
                    <span class="material-symbols-outlined text-white text-2xl">bolt</span>
                </div>
            <?php endif; ?>
            <h1 id="prevGymName" class="text-2xl font-black italic uppercase tracking-tighter text-white"><?= htmlspecialchars($page['page_title'] ?? $page['gym_name']) ?></h1>
        </div>
        <div class="relative hidden md:flex items-center gap-10">
            <nav class="flex items-center gap-8">
                <a href="#about" class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 hover:text-primary transition-all">About</a>
                <a href="#register" class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 hover:text-primary transition-all">Join Us</a>
                <a href="#contact" class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 hover:text-primary transition-all">Contact</a>
            </nav>
            <div class="h-6 w-px bg-white/10 mx-2"></div>
            <a href="login.php?gym=<?= $gym_slug ?>" class="h-12 px-8 rounded-custom border border-white/10 hover:bg-white/5 flex items-center text-[10px] font-black uppercase tracking-[0.2em] transition-all group">
                Portal Login
                <span class="material-symbols-outlined text-sm ml-2 group-hover:translate-x-1 transition-transform">arrow_forward</span>
            </a>
        </div>
    </header>

    <main class="flex-1 flex flex-col items-center pt-40 pb-20 px-6 relative overflow-visible">
        <!-- Premium Ambient Glows -->
        <div class="fixed top-[-20%] right-[-10%] size-[800px] bg-primary/10 rounded-full blur-[160px] -z-10 pointer-events-none"></div>
        <div class="fixed bottom-[-10%] left-[-10%] size-[600px] bg-primary/5 rounded-full blur-[140px] -z-10 pointer-events-none"></div>

        <section class="max-w-6xl w-full text-center relative mb-32">
            <div class="inline-flex items-center gap-2 px-6 py-2 rounded-full border border-primary/20 bg-primary/5 mb-8 animate-pulse">
                <span class="size-1.5 rounded-full bg-primary"></span>
                <span class="text-[9px] font-black uppercase tracking-[0.2em] text-primary">Now Open for Recruitment</span>
            </div>
            <h2 id="prevHomeTitle" class="text-6xl md:text-[110px] font-black italic uppercase tracking-tighter text-white mb-8 leading-[0.9] text-glow [text-wrap:balance]">
                <?= htmlspecialchars($page['home_title'] ?? 'Build Your') ?> <span class="text-primary">Legacy</span> <br class="hidden lg:block"/> At <?= htmlspecialchars($page['page_title'] ?? $page['gym_name']) ?>
            </h2>
            <p id="prevHomeSubtitle" class="text-gray-400 text-lg md:text-2xl font-medium max-w-3xl mx-auto leading-relaxed mb-12 opacity-80 italic">
                <?= nl2br(htmlspecialchars($page['home_subtitle'] ?? 'More than just a gym. Experience a modern multi-tenant fitness sanctuary powered by elite tech and world-class coaching.')) ?>
            </p>
            <div class="flex flex-col sm:flex-row items-center justify-center gap-5">
                <a href="#register" class="h-16 px-12 rounded-custom btn-primary flex items-center justify-center text-xs font-black uppercase tracking-[0.2em]">Start Your Journey</a>
                <a href="<?= htmlspecialchars($page['app_download_link'] ?? '#') ?>" class="h-16 px-12 rounded-custom btn-secondary flex items-center justify-center text-xs font-black uppercase tracking-[0.2em] group">
                    <span class="material-symbols-outlined text-lg mr-3">smartphone</span>
                    Get the App
                </a>
            </div>
        </section>

        <section class="grid grid-cols-1 md:grid-cols-2 gap-10 max-w-6xl w-full" id="register">
            <div class="glass-card p-12 flex flex-col hover:border-primary/20 transition-all group overflow-hidden relative">
                <div class="absolute top-0 right-0 p-8 opacity-5 group-hover:opacity-10 transition-opacity">
                    <span class="material-symbols-outlined text-[120px] translate-x-12 -translate-y-12">fitness_center</span>
                </div>
                <div class="size-20 rounded-custom bg-primary/10 text-primary flex items-center justify-center mb-10 shadow-inner group-hover:scale-110 transition-transform duration-500">
                    <span class="material-symbols-outlined text-4xl">person_add</span>
                </div>
                <h3 class="text-3xl font-black italic uppercase tracking-tighter mb-4 text-white">Join the Community</h3>
                <p class="text-gray-500 text-sm mb-10 leading-relaxed font-medium">Become a member today to unlock exclusive access to classes, real-time tracking, and our premium mobile experience.</p>
                <a href="member/member_registration.php?gym=<?= $page['gym_id'] ?>" class="h-16 rounded-custom btn-primary flex items-center justify-center text-xs font-black uppercase tracking-[0.2em] mt-auto">
                    Create Member Account
                </a>
            </div>

            <div class="glass-card p-12 flex flex-col hover:border-emerald-500/20 transition-all group overflow-hidden relative">
                 <div class="absolute top-0 right-0 p-8 opacity-5 group-hover:opacity-10 transition-opacity">
                    <span class="material-symbols-outlined text-[120px] translate-x-12 -translate-y-12 text-emerald-500">token</span>
                </div>
                <div class="size-20 rounded-custom bg-emerald-500/10 text-emerald-500 flex items-center justify-center mb-10 shadow-inner group-hover:scale-110 transition-transform duration-500">
                    <span class="material-symbols-outlined text-4xl">verified_user</span>
                </div>
                <h3 class="text-3xl font-black italic uppercase tracking-tighter mb-4 text-white">Coach & Staff</h3>
                <p class="text-gray-500 text-sm mb-10 leading-relaxed font-medium">Looking to join our elite roster? Access the staff portal or download the dedicated management app below.</p>
                <div class="grid grid-cols-2 gap-4 mt-auto">
                    <a href="login.php?gym=<?= $gym_slug ?>" class="h-16 rounded-custom btn-secondary flex items-center justify-center text-[10px] font-black uppercase tracking-[0.2em]">Web Login</a>
                    <a id="prevAppBtn" href="<?= htmlspecialchars($page['app_download_link'] ?? '#') ?>" class="h-16 rounded-custom bg-emerald-500/10 border border-emerald-500/20 text-emerald-500 hover:bg-emerald-500/20 flex items-center justify-center text-[10px] font-black uppercase tracking-[0.2em]">Android App</a>
                </div>
            </div>
        </section>

        <section id="about" class="max-w-4xl w-full mt-40 py-24 text-center relative">
            <div class="absolute inset-0 hero-gradient -z-10"></div>
            <span class="text-[10px] font-black uppercase text-primary tracking-[0.4em] mb-10 block">The Horizon Philosophy</span>
            <p class="text-white text-3xl md:text-5xl font-extrabold italic leading-tight [text-wrap:balance] mb-12">
                "We provide the technology, <br/> You provide the <span class="text-primary">Grit</span>."
            </p>
            <p class="text-gray-400 text-lg md:text-xl italic leading-relaxed font-medium opacity-90 underline decoration-primary/20 decoration-2 underline-offset-8">
                <?= nl2br(htmlspecialchars($page['about_text'] ?? 'Experience fitness like never before with our cutting-edge multi-tenant facility.')) ?>
            </p>
        </section>
    </main>

    <footer id="contact" class="w-full py-24 px-8 md:px-12 bg-surface-dark border-t border-white/5 mt-20 relative overflow-hidden">
        <div class="absolute bottom-0 right-0 w-1/3 h-full bg-gradient-to-l from-primary/5 to-transparent"></div>
        <div class="max-w-7xl mx-auto grid grid-cols-1 lg:grid-cols-4 gap-20 relative">
            <div class="lg:col-span-2">
                 <div class="flex items-center gap-4 mb-10">
                    <div class="size-10 rounded-2xl bg-primary flex items-center justify-center shadow-lg">
                        <span class="material-symbols-outlined text-white text-xl">bolt</span>
                    </div>
                    <h1 class="text-3xl font-black italic uppercase tracking-tighter text-white"><?= htmlspecialchars($page['gym_name']) ?></h1>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-10">
                    <div class="space-y-4">
                        <p class="text-[10px] font-black uppercase text-primary tracking-[0.3em] mb-6">Contact Us</p>
                        <p class="text-gray-400 text-sm font-bold flex items-center gap-3 italic">
                            <span class="material-symbols-outlined text-lg text-primary">mail</span>
                            <?= htmlspecialchars($page['gym_email']) ?>
                        </p>
                        <p class="text-gray-400 text-sm font-bold flex items-center gap-3 italic">
                            <span class="material-symbols-outlined text-lg text-primary">call</span>
                            <?= htmlspecialchars($page['gym_contact']) ?>
                        </p>
                    </div>
                    <div class="space-y-4">
                        <p class="text-[10px] font-black uppercase text-primary tracking-[0.3em] mb-6">Location Details</p>
                        <p class="text-gray-500 text-sm italic leading-relaxed font-medium">
                            <?= nl2br(htmlspecialchars($page['contact_text'] ?? 'Visit us at our primary training facility.')) ?>
                        </p>
                    </div>
                </div>
            </div>
            
            <div class="lg:col-span-2 flex flex-col justify-end items-end text-right">
                <p class="text-[10px] font-black uppercase tracking-[0.4em] text-gray-700 mb-4">Official Horizon Partner</p>
                <p class="text-primary text-[9px] font-black uppercase tracking-[0.5em] opacity-40">Horizon Multi-Tenant System v2.0</p>
                <div class="mt-12 flex gap-6">
                    <div class="size-12 rounded-2xl bg-white/5 border border-white/10 flex items-center justify-center text-gray-500 hover:text-primary transition-all cursor-pointer">
                        <span class="material-symbols-outlined">brand_awareness</span>
                    </div>
                    <div class="size-12 rounded-2xl bg-white/5 border border-white/10 flex items-center justify-center text-gray-500 hover:text-primary transition-all cursor-pointer">
                        <span class="material-symbols-outlined">social_leaderboard</span>
                    </div>
                </div>
            </div>
        </div>
    </footer>

    <?php include 'includes/image_viewer.php'; ?>

    <script>
    // Helper to convert hex to RGB for background-color alpha usage
    function hexToRgb(hex) {
        var result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
        return result ? `${parseInt(result[1], 16)}, ${parseInt(result[2], 16)}, ${parseInt(result[3], 16)}` : "20, 18, 26";
    }

    // Live Preview Message Listener
    window.addEventListener('message', function(event) {
        if (event.data.type === 'updateStyles') {
            const data = event.data.data;
            const root = document.documentElement;
            
            // Update CSS Variables Instantly
            root.style.setProperty('--primary', data.theme_color);
            root.style.setProperty('--secondary', data.secondary_color);
            root.style.setProperty('--secondary-rgb', hexToRgb(data.secondary_color));
            root.style.setProperty('--radius', data.border_radius);
            root.style.setProperty('--font-display', data.font_family + ', sans-serif');
            
            // Theme Mode
            const isDark = (data.theme_mode === 'dark');
            root.style.setProperty('--bg', isDark ? '#0a090d' : '#f8f9fa');
            root.style.setProperty('--text', isDark ? '#ffffff' : '#0a090d');
            
            // Text Content
            if (document.getElementById('prevGymName')) document.getElementById('prevGymName').innerText = data.page_title;
            if (document.getElementById('prevHomeTitle')) document.getElementById('prevHomeTitle').innerHTML = `${data.home_title} <span class="text-primary">Legacy</span> <br class="hidden lg:block"/> At ${data.page_title}`;
            if (document.getElementById('prevHomeSubtitle')) document.getElementById('prevHomeSubtitle').innerText = data.home_subtitle;
            if (data.app_download_link) {
                const appBtns = [document.getElementById('prevAppBtn')];
                appBtns.forEach(btn => { if(btn) btn.href = data.app_download_link; });
            }
        }
    });
    </script>
</body>
</html>
