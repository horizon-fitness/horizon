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
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title><?= htmlspecialchars($page['page_title']) ?> | Horizon Systems</title>
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
                        "background-dark": "#0a090d", 
                        "surface-dark": "#14121a"
                    }
                }
            }
        }
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        .btn-primary { background-color: <?= $primary_color ?>; color: white; transition: all 0.3s; }
        .btn-primary:hover { opacity: 0.9; transform: translateY(-2px); box-shadow: 0 10px 20px -10px <?= $primary_color ?>; }
        .text-primary-custom { color: <?= $primary_color ?>; }
    </style>
</head>
<body class="antialiased min-h-screen flex flex-col">

    <header class="w-full px-8 py-6 flex justify-between items-center bg-background-dark/80 backdrop-blur-md sticky top-0 z-50 border-b border-white/5">
        <div class="flex items-center gap-4">
            <?php if($page['logo_path']): ?>
                <img src="<?= htmlspecialchars($page['logo_path']) ?>" alt="Logo" class="h-10 w-auto">
            <?php else: ?>
                <div class="size-10 rounded-xl bg-primary flex items-center justify-center">
                    <span class="material-symbols-outlined text-white">bolt</span>
                </div>
            <?php endif; ?>
            <h1 class="text-xl font-black italic uppercase tracking-tighter text-white"><?= htmlspecialchars($page['gym_name']) ?></h1>
        </div>
        <div class="hidden md:flex items-center gap-8">
            <a href="#about" class="text-[10px] font-black uppercase tracking-widest text-gray-400 hover:text-white transition-all">About</a>
            <a href="#register" class="text-[10px] font-black uppercase tracking-widest text-gray-400 hover:text-white transition-all">Join Us</a>
            <a href="login.php" class="h-10 px-6 rounded-xl border border-white/10 hover:bg-white/5 flex items-center text-[10px] font-black uppercase tracking-widest transition-all">Staff Login</a>
        </div>
    </header>

    <main class="flex-1 flex flex-col items-center justify-center py-20 px-6 relative overflow-hidden">
        <!-- Glow effects -->
        <div class="absolute top-1/4 left-1/4 size-96 bg-primary/10 rounded-full blur-[120px] -z-10"></div>
        <div class="absolute bottom-1/4 right-1/4 size-96 bg-primary/5 rounded-full blur-[100px] -z-10"></div>

        <div class="max-w-4xl w-full text-center mb-16">
            <h2 class="text-6xl md:text-8xl font-black italic uppercase tracking-tighter text-white mb-6 leading-none">
                Elevate Your <span class="text-primary-custom">Fitness</span>
            </h2>
            <p class="text-gray-400 text-lg md:text-xl font-medium max-w-2xl mx-auto leading-relaxed">
                Welcome to <?= htmlspecialchars($page['gym_name']) ?>. Experience elite coaching and premium facilities at our <?= htmlspecialchars($page['page_slug']) ?> location.
            </p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-8 max-w-5xl w-full" id="register">
            <div class="glass-card p-10 flex flex-col items-center text-center">
                <div class="size-16 rounded-2xl bg-primary/10 text-primary-custom flex items-center justify-center mb-6">
                    <span class="material-symbols-outlined text-4xl">person_add</span>
                </div>
                <h3 class="text-2xl font-black italic uppercase tracking-tighter mb-4">Customer Membership</h3>
                <p class="text-gray-500 text-sm mb-8">Register as a member to book classes and track your progress through our mobile application.</p>
                <a href="member_registration.php?gym=<?= $page['gym_id'] ?>" class="w-full h-14 rounded-2xl btn-primary flex items-center justify-center text-xs font-black uppercase tracking-widest">
                    Apply for Membership
                </a>
            </div>

            <div class="glass-card p-10 flex flex-col items-center text-center">
                <div class="size-16 rounded-2xl bg-emerald-500/10 text-emerald-500 flex items-center justify-center mb-6">
                    <span class="material-symbols-outlined text-4xl">smartphone</span>
                </div>
                <h3 class="text-2xl font-black italic uppercase tracking-tighter mb-4">Mobile Experience</h3>
                <p class="text-gray-500 text-sm mb-8">Download our official mobile app for the best experience. Manage your routine on the go.</p>
                <a href="<?= htmlspecialchars($page['app_download_link'] ?? '#') ?>" class="w-full h-14 rounded-2xl bg-white/5 border border-white/10 hover:bg-white/10 flex items-center justify-center text-xs font-black uppercase tracking-widest gap-2">
                    <span class="material-symbols-outlined text-lg">download</span> Download APK
                </a>
            </div>
        </div>

        <section id="about" class="max-w-4xl w-full mt-32 py-20 border-t border-white/5 text-center">
            <h4 class="text-xs font-black uppercase text-primary-custom tracking-[0.3em] mb-8 italic">Dedicated to Excellence</h4>
            <p class="text-gray-400 text-base italic leading-loose">
                <?= nl2br(htmlspecialchars($page['about_text'] ?? 'No description available.')) ?>
            </p>
        </section>
    </main>

    <footer class="w-full py-16 px-8 bg-surface-dark border-t border-white/5">
        <div class="max-w-7xl mx-auto grid grid-cols-1 md:grid-cols-3 gap-12">
            <div>
                 <div class="flex items-center gap-3 mb-6">
                    <div class="size-8 rounded-lg bg-primary flex items-center justify-center">
                        <span class="material-symbols-outlined text-white text-sm">bolt</span>
                    </div>
                    <h1 class="text-lg font-black italic uppercase tracking-tighter text-white"><?= htmlspecialchars($page['gym_name']) ?></h1>
                </div>
                <p class="text-gray-500 text-xs leading-loose italic">
                    <?= nl2br(htmlspecialchars($page['contact_text'] ?? "Email: {$page['gym_email']}\nContact: {$page['gym_contact']}")) ?>
                </p>
            </div>
            <div class="md:col-span-2 flex flex-col md:items-end justify-center">
                <p class="text-[9px] font-black uppercase tracking-[0.3em] text-gray-700">Powered by Horizon Multi-Tenant Systems</p>
            </div>
        </div>
    </footer>

</body>
</html>
