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
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { 
                extend: { 
                    colors: { 
                        "primary": "<?= $primary_color ?>", 
                        "background-dark": "<?= $bg_color ?>", 
                        "surface-dark": "<?= $bg_color ?>" // Simplified to use same as BG for flat premium look
                    },
                    fontFamily: { "display": ["<?= $font_family ?>", "sans-serif"] }
                }
            }
        }
    </script>
    <style id="dynamic-styles">
        body { font-family: '<?= $font_family ?>', sans-serif; background-color: <?= $bg_color ?>; color: white; scroll-behavior: smooth; }
        .glass-card { background: rgba(255, 255, 255, 0.03); border: 1px solid rgba(255,255,255,0.05); border-radius: 32px; backdrop-filter: blur(20px); }
        .btn-primary { 
            background: linear-gradient(135deg, <?= $primary_color ?>, <?= $primary_color ?>dd); 
            color: white; 
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); 
            box-shadow: 0 10px 40px -15px <?= $primary_color ?>66;
        }
        .btn-primary:hover { transform: translateY(-4px) scale(1.02); box-shadow: 0 20px 40px -10px <?= $primary_color ?>aa; }
        .text-glow { text-shadow: 0 0 30px <?= $primary_color ?>44; }
        .hero-gradient { background: radial-gradient(circle at center, <?= $primary_color ?>11 0%, transparent 70%); }
        
        /* APK App Mode Adjustments */
        <?php if (isset($_GET['preview'])): ?>
        header { display: none !important; }
        main { padding-top: 3rem !important; }
        .fixed { pointer-events: none; }
        <?php endif; ?>
    </style>
</head>
<body class="antialiased min-h-screen flex flex-col font-display selection:bg-primary/30 selection:text-white">

    <header class="w-full px-8 md:px-12 py-8 flex justify-between items-center bg-background-dark/40 backdrop-blur-2xl fixed top-0 z-50 border-b border-white/5 transition-all duration-500 overflow-hidden">
        <div class="absolute inset-0 bg-gradient-to-r from-primary/5 via-transparent to-transparent opacity-50"></div>
        <div class="relative flex items-center gap-5">
            <?php if($page['logo_path']): ?>
                <img src="<?= htmlspecialchars($page['logo_path']) ?>" alt="Logo" class="h-10 w-auto filter drop-shadow-2xl">
            <?php else: ?>
                <div class="size-10 rounded-2xl bg-primary flex items-center justify-center shadow-lg shadow-primary/20">
                    <span class="material-symbols-outlined text-white text-2xl">bolt</span>
                </div>
            <?php endif; ?>
            <h1 class="text-2xl font-black italic uppercase tracking-tighter text-white"><?= htmlspecialchars($page['gym_name']) ?></h1>
        </div>
        <div class="relative hidden md:flex items-center gap-10">
            <nav class="flex items-center gap-8">
                <a href="#about" class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 hover:text-primary transition-all">About</a>
                <a href="#register" class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 hover:text-primary transition-all">Join Us</a>
                <a href="#contact" class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-500 hover:text-primary transition-all">Contact</a>
            </nav>
            <div class="h-6 w-px bg-white/10 mx-2"></div>
            <a href="login.php?gym=<?= $gym_slug ?>" class="h-12 px-8 rounded-2xl border border-white/10 hover:bg-white/5 flex items-center text-[10px] font-black uppercase tracking-[0.2em] transition-all group">
                Portal Login
                <span class="material-symbols-outlined text-sm ml-2 group-hover:translate-x-1 transition-transform">arrow_forward</span>
            </a>
        </div>
    </header>

    <main class="flex-1 flex flex-col items-center <?= isset($_GET['preview']) ? 'pt-12' : 'pt-40' ?> pb-20 px-6 relative overflow-visible">
        <!-- Premium Ambient Glows -->
        <div class="fixed top-[-20%] right-[-10%] size-[800px] bg-primary/10 rounded-full blur-[160px] -z-10 pointer-events-none"></div>
        <div class="fixed bottom-[-10%] left-[-10%] size-[600px] bg-primary/5 rounded-full blur-[140px] -z-10 pointer-events-none"></div>

        <section class="max-w-6xl w-full text-center relative mb-32">
            <div class="inline-flex items-center gap-2 px-6 py-2 rounded-full border border-primary/20 bg-primary/5 mb-8 animate-pulse">
                <span class="size-1.5 rounded-full bg-primary"></span>
                <span class="text-[9px] font-black uppercase tracking-[0.2em] text-primary">Now Open for Recruitment</span>
            </div>
            <h2 class="text-6xl md:text-[110px] font-black italic uppercase tracking-tighter text-white mb-8 leading-[0.9] text-glow [text-wrap:balance]">
                Build Your <span class="text-primary">Legacy</span> <br class="hidden lg:block"/> At <?= htmlspecialchars($page['gym_name']) ?>
            </h2>
            <p class="text-gray-400 text-lg md:text-2xl font-medium max-w-3xl mx-auto leading-relaxed mb-12 opacity-80 italic">
                More than just a gym. Experience a modern multi-tenant fitness sanctuary powered by elite tech and world-class coaching.
            </p>
            <div class="flex flex-col sm:flex-row items-center justify-center gap-5">
                <a href="#register" class="h-16 px-12 rounded-3xl btn-primary flex items-center justify-center text-xs font-black uppercase tracking-[0.2em]">Start Your Journey</a>
                <a href="<?= htmlspecialchars($page['app_download_link'] ?? '#') ?>" class="h-16 px-12 rounded-3xl bg-white/5 border border-white/10 hover:bg-white/10 flex items-center justify-center text-xs font-black uppercase tracking-[0.2em] group">
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
                <div class="size-20 rounded-[28px] bg-primary/10 text-primary flex items-center justify-center mb-10 shadow-inner group-hover:scale-110 transition-transform duration-500">
                    <span class="material-symbols-outlined text-4xl">person_add</span>
                </div>
                <h3 class="text-3xl font-black italic uppercase tracking-tighter mb-4 text-white">Join the Community</h3>
                <p class="text-gray-500 text-sm mb-10 leading-relaxed font-medium">Become a member today to unlock exclusive access to classes, real-time tracking, and our premium mobile experience.</p>
                <a href="member_registration.php?gym=<?= $page['gym_id'] ?>" class="h-16 rounded-2xl btn-primary flex items-center justify-center text-xs font-black uppercase tracking-[0.2em] mt-auto">
                    Create Member Account
                </a>
            </div>

            <div class="glass-card p-12 flex flex-col hover:border-emerald-500/20 transition-all group overflow-hidden relative">
                 <div class="absolute top-0 right-0 p-8 opacity-5 group-hover:opacity-10 transition-opacity">
                    <span class="material-symbols-outlined text-[120px] translate-x-12 -translate-y-12 text-emerald-500">token</span>
                </div>
                <div class="size-20 rounded-[28px] bg-emerald-500/10 text-emerald-500 flex items-center justify-center mb-10 shadow-inner group-hover:scale-110 transition-transform duration-500">
                    <span class="material-symbols-outlined text-4xl">verified_user</span>
                </div>
                <h3 class="text-3xl font-black italic uppercase tracking-tighter mb-4 text-white">Coach & Staff</h3>
                <p class="text-gray-500 text-sm mb-10 leading-relaxed font-medium">Looking to join our elite roster? Access the staff portal or download the dedicated management app below.</p>
                <div class="grid grid-cols-2 gap-4 mt-auto">
                    <a href="login.php?gym=<?= $gym_slug ?>" class="h-16 rounded-2xl bg-white/5 border border-white/10 hover:bg-white/10 flex items-center justify-center text-[10px] font-black uppercase tracking-[0.2em]">Web Login</a>
                    <a href="<?= htmlspecialchars($page['app_download_link'] ?? '#') ?>" class="h-16 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-500 hover:bg-emerald-500/20 flex items-center justify-center text-[10px] font-black uppercase tracking-[0.2em]">Android App</a>
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
    
    <script>
        // Real-time Preview Listener
        window.addEventListener('message', function(event) {
            if (event.data.type === 'updateStyles') {
                const data = event.data.data;
                const primary = data.theme_color || '<?= $primary_color ?>';
                const bg = data.bg_color || '<?= $bg_color ?>';
                const font = data.font_family || '<?= $font_family ?>';
                
                // Update CSS variables/styles
                const styleEl = document.getElementById('dynamic-styles');
                styleEl.innerHTML = `
                    body { font-family: '${font}', sans-serif; background-color: ${bg}; color: white; scroll-behavior: smooth; }
                    .glass-card { background: rgba(255, 255, 255, 0.03); border: 1px solid rgba(255,255,255,0.05); border-radius: 32px; backdrop-filter: blur(20px); }
                    .btn-primary { 
                        background: linear-gradient(135deg, ${primary}, ${primary}dd); 
                        color: white; 
                        transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); 
                        box-shadow: 0 10px 40px -15px ${primary}66;
                    }
                `;
                
                // Update Logo
                if (data.logo_preview) {
                    const logoImg = document.querySelector('header img');
                    if (logoImg) logoImg.src = data.logo_preview;
                }
                
                // Update Gym Name/Title
                if (data.page_title) {
                    document.title = data.page_title + " | Horizon Systems";
                    const headers = document.querySelectorAll('h1, h2 span.gym-name');
                    headers.forEach(h => {
                        if (h.tagName === 'H1') h.innerText = data.page_title;
                    });
                }
            }
        });
    </script>
</body>
</html>
