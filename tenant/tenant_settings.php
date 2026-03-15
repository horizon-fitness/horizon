<?php
session_start();
require_once '../db.php';

// Security Check: Only Tenants/Admins
$role = strtolower($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || ($role !== 'tenant' && $role !== 'admin')) {
    header("Location: ../login.php");
    exit;
}

$gym_id = $_SESSION['gym_id'];
$user_id = $_SESSION['user_id'];

// Check Subscription
$stmtSub = $pdo->prepare("SELECT * FROM client_subscriptions WHERE gym_id = ? AND subscription_status = 'Active' LIMIT 1");
$stmtSub->execute([$gym_id]);
$sub = $stmtSub->fetch();
if (!$sub) { header("Location: subscription_plan.php"); exit; }

// Fetch Gym Details
$stmtGym = $pdo->prepare("SELECT * FROM gyms WHERE gym_id = ?");
$stmtGym->execute([$gym_id]);
$gym = $stmtGym->fetch();

// Fetch CMS Page
$stmtPage = $pdo->prepare("SELECT * FROM tenant_pages WHERE gym_id = ? LIMIT 1");
$stmtPage->execute([$gym_id]);
$page = $stmtPage->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $page_title = $_POST['page_title'];
    $theme_color = $_POST['theme_color'];
    $bg_color = $_POST['bg_color'];
    $font_family = $_POST['font_family'];
    $now = date('Y-m-d H:i:s');

    // Handle Logo Upload (Store as Base64 in Database)
    $logo_path = $page['logo_path'] ?? '';
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] == 0) {
        $type = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
        $data = file_get_contents($_FILES['logo']['tmp_name']);
        $logo_path = 'data:image/' . $type . ';base64,' . base64_encode($data);
    }

    try {
        if ($page) {
            $stmtUpdate = $pdo->prepare("UPDATE tenant_pages SET page_title = ?, logo_path = ?, theme_color = ?, bg_color = ?, font_family = ?, updated_at = ? WHERE gym_id = ?");
            $stmtUpdate->execute([$page_title, $logo_path, $theme_color, $bg_color, $font_family, $now, $gym_id]);
        } else {
            // If page doesn't exist, create a slug based on gym name
            $page_slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $gym['gym_name']));
            $stmtInsert = $pdo->prepare("INSERT INTO tenant_pages (gym_id, page_slug, page_title, logo_path, theme_color, bg_color, font_family, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtInsert->execute([$gym_id, $page_slug, $page_title, $logo_path, $theme_color, $bg_color, $font_family, $now]);
        }
        $_SESSION['success_msg'] = "Portal customized successfully!";
        header("Location: tenant_settings.php");
        exit;
    } catch (Exception $e) {
        $error = "Update failed: " . $e->getMessage();
    }
}

$page_title = "Portal Customize";
$active_page = "settings";
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title><?= $page_title ?> | Horizon Partners</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&family=Inter:wght@400;700&family=Outfit:wght@400;700&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "#8c2bee", "background-dark": "#0a090d", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)"}}}
        }
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; overflow: hidden; }
        .glass-sidebar { background: #0a090d; border-right: 1px solid rgba(255,255,255,0.05); }
        .nav-link { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; transition: all 0.2s; white-space: nowrap; }
        .active-nav { color: #8c2bee !important; position: relative; }
        .active-nav::after { 
            content: ''; 
            position: absolute; 
            right: -32px; 
            top: 50%;
            transform: translateY(-50%);
            width: 4px; 
            height: 20px; 
            background: #8c2bee; 
            border-radius: 99px; 
        }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        
        /* Layout Adjustments */
        .preview-container { background: #1a1824; border-radius: 40px; border: 8px solid #2a2835; transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1); box-shadow: 0 50px 100px -20px rgba(0,0,0,0.5); }
        .preview-mobile { width: 375px; height: 667px; margin: auto; }
        .input-dark { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 12px; color: white; padding: 0.75rem 1rem; font-size: 0.75rem; width: 100%; outline: none; transition: border-color 0.2s; }
        .input-dark:focus { border-color: #8c2bee; }
    </style>
</head>
<body class="antialiased flex h-screen overflow-hidden">

<nav class="flex flex-col w-64 lg:w-72 bg-[#0a090d] border-r border-white/5 sticky top-0 h-screen p-8 z-50 shrink-0 overflow-x-hidden">
    <div class="mb-12">
        <div class="flex items-center gap-4 mb-6">
            <div class="size-10 rounded-xl bg-primary flex items-center justify-center shadow-lg shrink-0">
                <span class="material-symbols-outlined text-white text-2xl">bolt</span>
            </div>
            <h1 class="text-xl font-black italic uppercase tracking-tighter text-white"><?= htmlspecialchars($gym['gym_name']) ?></h1>
        </div>
        <div class="p-4 rounded-2xl bg-white/5 border border-white/5">
            <div class="flex items-center justify-between mb-2">
                <p id="sidebarClock" class="text-white font-black italic text-base leading-none">00:00:00 AM</p>
                <span class="px-2 py-0.5 rounded-md bg-primary/20 text-primary text-[8px] font-black uppercase tracking-widest">Active</span>
            </div>
            <p class="text-[9px] font-black uppercase text-gray-500 tracking-[0.2em] leading-none mb-1"><?= date('l, M d') ?></p>
        </div>
    </div>
    
    <div class="flex flex-col gap-5 flex-1 overflow-y-auto pr-2 no-scrollbar text-gray-400">
        <a href="tenant_dashboard.php" class="nav-link flex items-center gap-3 hover:text-white">
            <span class="material-symbols-outlined text-xl">dashboard</span> Dashboard
        </a>
        <a href="tenant_settings.php" class="nav-link flex items-center gap-3 active-nav text-primary">
            <span class="material-symbols-outlined text-xl">palette</span> Page Customize
        </a>
        <a href="#" class="nav-link flex items-center gap-3 hover:text-white">
            <span class="material-symbols-outlined text-xl">group</span> Staff Management
        </a>
    </div>

    <div class="mt-auto pt-8 border-t border-white/10 shrink-0">
        <a href="../logout.php" class="text-gray-400 hover:text-red-500 transition-colors flex items-center gap-3 group">
            <span class="material-symbols-outlined group-hover:translate-x-1 transition-transform">logout</span>
            <span class="nav-link">Sign Out</span>
        </a>
    </div>
</nav>

<div class="flex-1 flex overflow-hidden">
    <!-- Left: Controls Panel (50%) -->
    <div class="w-1/2 glass-sidebar overflow-y-auto p-12 no-scrollbar flex flex-col">
        <header class="mb-10">
            <h2 class="text-3xl font-black italic uppercase tracking-tighter">Portal <span class="text-primary">Customize</span></h2>
            <p class="text-[10px] text-gray-500 font-bold uppercase tracking-widest mt-1">Industrial Brand Management</p>
        </header>

        <?php if (isset($_SESSION['success_msg'])): ?>
            <div class="mb-8 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-[10px] font-black uppercase tracking-widest italic">
                <?= $_SESSION['success_msg']; unset($_SESSION['success_msg']); ?>
            </div>
        <?php endif; ?>

        <form id="customizeForm" method="POST" enctype="multipart/form-data" class="flex-1 space-y-8">
            <div class="space-y-6">
                <!-- Branding -->
                <div class="space-y-4">
                    <label class="text-[10px] font-black uppercase tracking-widest text-primary border-b border-primary/20 pb-2 block">Branding Assets</label>
                    <div class="flex items-center gap-6">
                        <div class="relative group size-24 rounded-2xl bg-white/5 border border-dashed border-white/10 flex items-center justify-center overflow-hidden">
                            <img id="logo-preview" src="<?= !empty($page['logo_path']) ? $page['logo_path'] : '' ?>" class="size-full object-contain <?= empty($page['logo_path']) ? 'hidden' : '' ?>">
                            <span id="logo-placeholder" class="material-symbols-outlined text-gray-600 <?= !empty($page['logo_path']) ? 'hidden' : '' ?>">add_photo_alternate</span>
                            <input type="file" name="logo" class="absolute inset-0 opacity-0 cursor-pointer" onchange="previewLogo(this)">
                        </div>
                        <div class="flex-1 space-y-2">
                             <label class="text-[9px] font-black uppercase tracking-widest text-gray-500 block">Portal Display Name</label>
                             <input type="text" name="page_title" value="<?= htmlspecialchars($page['page_title'] ?? $gym['gym_name']) ?>" class="input-dark" placeholder="Gym Name">
                        </div>
                    </div>
                </div>

                <!-- Visuals -->
                <div class="space-y-4">
                    <label class="text-[10px] font-black uppercase tracking-widest text-primary border-b border-primary/20 pb-2 block">Visual Identity</label>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase tracking-widest text-gray-500 block">Font Family</label>
                            <select name="font_family" onchange="updatePreview()" class="input-dark bg-surface-dark">
                                <option value="Lexend" <?= ($page['font_family'] ?? '') == 'Lexend' ? 'selected' : '' ?>>Lexend (Sporty)</option>
                                <option value="Inter" <?= ($page['font_family'] ?? '') == 'Inter' ? 'selected' : '' ?>>Inter (Modern)</option>
                                <option value="Outfit" <?= ($page['font_family'] ?? '') == 'Outfit' ? 'selected' : '' ?>>Outfit (Premium)</option>
                            </select>
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase tracking-widest text-gray-500 block">Primary Color</label>
                            <div class="flex items-center gap-3 bg-white/5 rounded-xl border border-white/5 p-2 px-3">
                                <input type="color" name="theme_color" oninput="updatePreview()" value="<?= $page['theme_color'] ?? '#8c2bee' ?>" class="size-8 rounded-lg bg-transparent border-none cursor-pointer">
                                <span id="primary-hex" class="text-[10px] font-bold uppercase text-gray-400">#8C2BEE</span>
                            </div>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <label class="text-[9px] font-black uppercase tracking-widest text-gray-500 block">Background Color</label>
                        <div class="flex items-center gap-3 bg-white/5 rounded-xl border border-white/5 p-2 px-3 w-1/2">
                            <input type="color" name="bg_color" oninput="updatePreview()" value="<?= $page['bg_color'] ?? '#0a090d' ?>" class="size-8 rounded-lg bg-transparent border-none cursor-pointer">
                            <span id="bg-hex" class="text-[10px] font-bold uppercase text-gray-400">#0A090D</span>
                        </div>
                    </div>
                </div>
            </div>

            <button type="submit" class="w-full h-16 rounded-2xl bg-primary hover:bg-primary-hover shadow-lg shadow-primary/20 transition-all text-xs font-black uppercase tracking-[0.2em] flex items-center justify-center gap-3">
                <span class="material-symbols-outlined">auto_fix_high</span> Apply Customizations
            </button>
        </form>
        
        <div class="mt-8 pt-6 border-t border-white/5">
             <a href="../portal.php?gym=<?= htmlspecialchars($page['page_slug'] ?? '') ?>" target="_blank" class="text-[9px] font-black uppercase tracking-widest text-gray-500 hover:text-primary transition-all flex items-center gap-2">
                 <span class="material-symbols-outlined text-sm">open_in_new</span> Launch Live Portal
             </a>
        </div>
    </div>

    <!-- Right: Preview Area (50%) -->
    <div class="flex-1 bg-[#121118] p-10 flex flex-col relative overflow-hidden">
        <div class="absolute top-10 right-10 z-10">
             <div class="px-4 py-2 rounded-full border border-primary/20 bg-primary/5 flex items-center gap-2">
                <span class="size-1.5 rounded-full bg-primary animate-pulse"></span>
                <span class="text-[8px] font-black uppercase tracking-[0.2em] text-primary">Live Mobile Preview</span>
            </div>
        </div>

        <div class="flex-1 flex items-center justify-center">
            <div id="previewFrameContainer" class="preview-container preview-mobile overflow-hidden relative">
                <iframe id="previewIframe" src="../portal.php?gym=<?= htmlspecialchars($page['page_slug'] ?? '') ?>&preview=1" class="w-full h-full border-none"></iframe>
            </div>
        </div>
    </div>
</div>

<script>
    function previewLogo(input) {
        if (input.files && input.files[0]) {
            var reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('logo-preview').src = e.target.result;
                document.getElementById('logo-preview').classList.remove('hidden');
                document.getElementById('logo-placeholder').classList.add('hidden');
                updatePreview(e.target.result);
            }
            reader.readAsDataURL(input.files[0]);
        }
    }

    function updatePreview(logoData = null) {
        const form = document.getElementById('customizeForm');
        const formData = new FormData(form);
        const data = {};
        formData.forEach((value, key) => {
            if (key !== 'logo') data[key] = value;
        });
        
        if (logoData) {
            data.logo_preview = logoData;
        } else {
            const currentLogo = document.getElementById('logo-preview');
            if (currentLogo && !currentLogo.classList.contains('hidden')) {
                data.logo_preview = currentLogo.src;
            }
        }

        // Update HEX labels
        const primaryHex = document.getElementById('primary-hex');
        const bgHex = document.getElementById('bg-hex');
        if (primaryHex) primaryHex.innerText = data.theme_color.toUpperCase();
        if (bgHex) bgHex.innerText = data.bg_color.toUpperCase();
        
        // Send to iframe
        const iframe = document.getElementById('previewIframe');
        if (iframe && iframe.contentWindow) {
            iframe.contentWindow.postMessage({ type: 'updateStyles', data: data }, '*');
        }
    }

    // Live listening
    document.querySelectorAll('#customizeForm input, #customizeForm select').forEach(el => {
        el.addEventListener('input', () => updatePreview());
    });

    function updateSidebarClock() {
        const now = new Date();
        const clockEl = document.getElementById('sidebarClock');
        if (clockEl) {
            clockEl.textContent = now.toLocaleTimeString('en-US', { 
                hour: '2-digit', minute: '2-digit', second: '2-digit' 
            });
        }
    }
    setInterval(updateSidebarClock, 1000);
    window.addEventListener('DOMContentLoaded', () => {
        updateSidebarClock();
        setTimeout(updatePreview, 1000);
    });
</script>

</body>
</html>
