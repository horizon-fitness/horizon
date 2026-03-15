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

// Fetch Subscription
$stmtSub = $pdo->prepare("SELECT * FROM client_subscriptions WHERE gym_id = ? AND subscription_status = 'Active' LIMIT 1");
$stmtSub->execute([$gym_id]);
$sub = $stmtSub->fetch();
if (!$sub) { header("Location: subscription_plan.php"); exit; }

// Fetch Gym Details
$stmtGym = $pdo->prepare("SELECT * FROM gyms WHERE gym_id = ?");
$stmtGym->execute([$gym_id]);
$gym = $stmtGym->fetch();

// Fetch Page Data
$stmtPage = $pdo->prepare("SELECT * FROM tenant_pages WHERE gym_id = ? LIMIT 1");
$stmtPage->execute([$gym_id]);
$page = $stmtPage->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $page_slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $_POST['page_slug']));
    $page_title = $_POST['page_title'];
    $primary_color = $_POST['theme_color'];
    $secondary_color = $_POST['secondary_color'];
    $font_family = $_POST['font_family'];
    $border_radius = $_POST['border_radius'];
    $theme_mode = $_POST['theme_mode'];
    $about_text = $_POST['about_text'];
    $contact_text = $_POST['contact_text'];
    $footer_text = $_POST['footer_text'];
    $app_link = $_POST['app_download_link'];
    $home_title = $_POST['home_title'];
    $home_subtitle = $_POST['home_subtitle'];
    $portal_tab_text = $_POST['portal_tab_text'];
    
    // Handle Logo Upload
    $logo_path = $page['logo_path'] ?? '';
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] == 0) {
        $type = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
        $data = file_get_contents($_FILES['logo']['tmp_name']);
        $logo_path = 'data:image/' . $type . ';base64,' . base64_encode($data);
    }

    try {
        if ($page) {
            $stmt = $pdo->prepare("UPDATE tenant_pages SET 
                page_slug = ?, page_title = ?, logo_path = ?, theme_color = ?, 
                secondary_color = ?, font_family = ?, border_radius = ?, theme_mode = ?,
                about_text = ?, contact_text = ?, footer_text = ?, app_download_link = ?,
                home_title = ?, home_subtitle = ?, portal_tab_text = ?, updated_at = NOW() 
                WHERE gym_id = ?");
            $stmt->execute([
                $page_slug, $page_title, $logo_path, $primary_color,
                $secondary_color, $font_family, $border_radius, $theme_mode,
                $about_text, $contact_text, $footer_text, $app_link,
                $home_title, $home_subtitle, $portal_tab_text, $gym_id
            ]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO tenant_pages (
                gym_id, page_slug, page_title, logo_path, theme_color,
                secondary_color, font_family, border_radius, theme_mode,
                about_text, contact_text, footer_text, app_download_link,
                home_title, home_subtitle, portal_tab_text, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([
                $gym_id, $page_slug, $page_title, $logo_path, $primary_color,
                $secondary_color, $font_family, $border_radius, $theme_mode,
                $about_text, $contact_text, $footer_text, $app_link,
                $home_title, $home_subtitle, $portal_tab_text
            ]);
        }
        $_SESSION['success_msg'] = "Customizer settings saved!";
        header("Location: page_customizer.php");
        exit;
    } catch (Exception $e) { $error = $e->getMessage(); }
}

$page_title = "Page Customizer";
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
    <script>
        function updateSidebarClock() {
            const now = new Date();
            const clockEl = document.getElementById('sidebarClock');
            if (clockEl) {
                clockEl.textContent = now.toLocaleTimeString('en-US', { 
                    hour: '2-digit', 
                    minute: '2-digit', 
                    second: '2-digit' 
                });
            }
        }
        setInterval(updateSidebarClock, 1000);
        window.addEventListener('DOMContentLoaded', updateSidebarClock);
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: #0c0b10; color: white; overflow: hidden; }
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
        @media (max-width: 1023px) { .active-nav::after { display: none; } }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        
        /* Customizer Specific */
        .control-group { border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 1.5rem; margin-bottom: 1.5rem; }
        .preview-container { background: #1a1824; border-radius: 40px; border: 8px solid #2a2835; transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1); box-shadow: 0 50px 100px -20px rgba(0,0,0,0.5); }
        .preview-web { width: 100%; height: 100%; }
        .preview-mobile { width: 375px; height: 667px; margin: auto; }
        .input-dark { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 12px; color: white; padding: 0.75rem 1rem; font-size: 0.75rem; width: 100%; outline: none; transition: border-color 0.2s; }
        .input-dark:focus { border-color: #8c2bee; }
        .accordion-content { max-height: 0; overflow: hidden; transition: max-height 0.3s ease-out; }
        .accordion-open .accordion-content { max-height: 1000px; }
        .tab-btn { transition: all 0.3s; }
        .tab-btn.active { background: #8c2bee; color: white; }
    </style>
</head>
<body class="antialiased flex h-screen overflow-hidden">

<!-- Sidebar Navigation (Same as Dashboard) -->
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
            <div class="pt-2 border-t border-white/5 mt-2">
                <p class="text-[8px] font-black uppercase text-gray-600 tracking-widest mb-1">Current Plan</p>
                <p class="text-[10px] font-black uppercase text-white italic tracking-tighter"><?= htmlspecialchars($sub['plan_name'] ?? 'Standard Plan') ?></p>
            </div>
        </div>
    </div>
    
    <div class="flex flex-col gap-5 flex-1 overflow-y-auto pr-2 no-scrollbar">
        <a href="tenant_dashboard.php" class="nav-link flex items-center gap-3 text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl">dashboard</span> Dashboard
        </a>
        <a href="page_customizer.php" class="nav-link flex items-center gap-3 active-nav text-primary">
            <span class="material-symbols-outlined text-xl">palette</span> Page Customizer
        </a>
        <a href="#" class="nav-link flex items-center gap-3 text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl">group</span> Staff Management
        </a>
        <a href="#" class="nav-link flex items-center gap-3 text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl">person_search</span> Member Directory
        </a>
    </div>

    <div class="mt-auto pt-8 border-t border-white/10 shrink-0">
        <a href="../logout.php" class="text-gray-400 hover:text-red-500 transition-colors flex items-center gap-3 group">
            <span class="material-symbols-outlined group-hover:translate-x-1 transition-transform">logout</span>
            <span class="nav-link">Sign Out</span>
        </a>
    </div>
</nav>

<!-- Page Content: Customizer Side-by-Side -->
<div class="flex-1 flex overflow-hidden">
    <!-- Left: Controls Panel -->
    <div class="w-96 glass-sidebar overflow-y-auto p-8 no-scrollbar flex flex-col">
        <header class="mb-8">
            <h2 class="text-2xl font-black italic uppercase tracking-tighter">Page <span class="text-primary">Customizer</span></h2>
            <p class="text-[10px] text-gray-500 font-bold uppercase tracking-widest mt-1">Design Your Brand Experience</p>
        </header>

        <form id="customizerForm" method="POST" enctype="multipart/form-data" class="flex-1 space-y-6">
            <!-- Global Styling Section -->
            <div class="accordion-item accordion-open">
                <button type="button" onclick="toggleAccordion(this)" class="w-full flex justify-between items-center py-3 text-[10px] font-black uppercase tracking-[0.2em] text-primary border-b border-white/5 mb-4">
                    Branding & Global
                    <span class="material-symbols-outlined transition-transform">expand_more</span>
                </button>
                <div class="accordion-content">
                    <div class="control-group">
                        <label class="text-[9px] font-black uppercase tracking-widest text-gray-500 mb-3 block">Gym Logo</label>
                        <div class="relative group size-20 rounded-2xl bg-white/5 border border-dashed border-white/10 flex items-center justify-center overflow-hidden">
                            <img id="logo-preview" src="<?= !empty($page['logo_path']) ? $page['logo_path'] : '' ?>" class="size-full object-contain <?= empty($page['logo_path']) ? 'hidden' : '' ?>">
                            <span id="logo-placeholder" class="material-symbols-outlined text-gray-600 <?= !empty($page['logo_path']) ? 'hidden' : '' ?>">add_photo_alternate</span>
                            <input type="file" name="logo" class="absolute inset-0 opacity-0 cursor-pointer" onchange="previewLogo(this)">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4 control-group">
                        <div>
                            <label class="text-[9px] font-black uppercase tracking-widest text-gray-500 mb-2 block">Primary</label>
                            <input type="color" name="theme_color" oninput="updatePreview()" value="<?= $page['theme_color'] ?? '#8c2bee' ?>" class="w-full h-10 rounded-lg bg-white/5 border-none cursor-pointer">
                        </div>
                        <div>
                            <label class="text-[9px] font-black uppercase tracking-widest text-gray-500 mb-2 block">Secondary</label>
                            <input type="color" name="secondary_color" oninput="updatePreview()" value="<?= $page['secondary_color'] ?? '#14121a' ?>" class="w-full h-10 rounded-lg bg-white/5 border-none cursor-pointer">
                        </div>
                    </div>

                    <div class="control-group">
                        <label class="text-[9px] font-black uppercase tracking-widest text-gray-500 mb-2 block">URL Slug & Portal Name</label>
                        <div class="space-y-3">
                            <div class="relative">
                                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-500 text-[10px] font-bold">gym=</span>
                                <input type="text" name="page_slug" oninput="updatePreview()" value="<?= $page['page_slug'] ?? '' ?>" class="input-dark pl-14" placeholder="gym-name">
                            </div>
                            <input type="text" name="portal_tab_text" oninput="updatePreview()" value="<?= $page['portal_tab_text'] ?? 'Gym Portal' ?>" class="input-dark" placeholder="Browser Tab Title">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Typography & Shapes -->
            <div class="accordion-item">
                <button type="button" onclick="toggleAccordion(this)" class="w-full flex justify-between items-center py-3 text-[10px] font-black uppercase tracking-[0.2em] text-gray-400 border-b border-white/5 mb-4 hover:text-primary transition-colors">
                    Typography & Shapes
                    <span class="material-symbols-outlined transition-transform">expand_more</span>
                </button>
                <div class="accordion-content">
                    <div class="control-group">
                        <label class="text-[9px] font-black uppercase tracking-widest text-gray-500 mb-2 block">Font Family</label>
                        <select name="font_family" onchange="updatePreview()" class="input-dark">
                            <option value="Lexend" <?= ($page['font_family'] ?? '') == 'Lexend' ? 'selected' : '' ?>>Lexend (Sporty)</option>
                            <option value="Inter" <?= ($page['font_family'] ?? '') == 'Inter' ? 'selected' : '' ?>>Inter (Modern)</option>
                            <option value="Outfit" <?= ($page['font_family'] ?? '') == 'Outfit' ? 'selected' : '' ?>>Outfit (Premium)</option>
                        </select>
                    </div>
                    <div class="control-group">
                        <label class="text-[9px] font-black uppercase tracking-widest text-gray-500 mb-2 block">Rounding (Border Radius)</label>
                        <div class="grid grid-cols-4 gap-2">
                            <button type="button" onclick="setRadius('0px', this)" class="h-10 rounded bg-white/5 border border-white/10 text-[10px] uppercase font-bold hover:bg-white/10">Sq</button>
                            <button type="button" onclick="setRadius('12px', this)" class="h-10 rounded-md bg-white/5 border border-white/10 text-[10px] uppercase font-bold hover:bg-white/10">Md</button>
                            <button type="button" onclick="setRadius('24px', this)" class="h-10 rounded-2xl bg-white/5 border border-white/10 text-[10px] uppercase font-bold hover:bg-white/10">Lg</button>
                            <button type="button" onclick="setRadius('999px', this)" class="h-10 rounded-full bg-white/5 border border-white/10 text-[10px] uppercase font-bold hover:bg-white/10">Full</button>
                            <input type="hidden" name="border_radius" id="border_radius_val" value="<?= $page['border_radius'] ?? '24px' ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section Content -->
            <div class="accordion-item">
                <button type="button" onclick="toggleAccordion(this)" class="w-full flex justify-between items-center py-3 text-[10px] font-black uppercase tracking-[0.2em] text-gray-400 border-b border-white/5 mb-4 hover:text-primary transition-colors">
                    Content Blocks
                    <span class="material-symbols-outlined transition-transform">expand_more</span>
                </button>
                <div class="accordion-content">
                    <div class="control-group">
                        <label class="text-[9px] font-black uppercase tracking-widest text-gray-500 mb-2 block">Hero Section</label>
                        <input type="text" name="page_title" oninput="updatePreview()" value="<?= $page['page_title'] ?? $gym['gym_name'] ?>" class="input-dark mb-2" placeholder="Gym Display Name">
                        <input type="text" name="home_title" oninput="updatePreview()" value="<?= $page['home_title'] ?? 'Build Your Legacy' ?>" class="input-dark mb-2" placeholder="Main Heading">
                        <textarea name="home_subtitle" oninput="updatePreview()" class="input-dark h-24" placeholder="Hero Description"><?= $page['home_subtitle'] ?? 'More than just a gym. Experience a modern sanctuary.' ?></textarea>
                    </div>
                    <div class="control-group">
                        <label class="text-[9px] font-black uppercase tracking-widest text-gray-500 mb-2 block">About Us</label>
                        <textarea name="about_text" oninput="updatePreview()" class="input-dark h-24" placeholder="Describe your gym's philosophy..."><?= $page['about_text'] ?? '' ?></textarea>
                    </div>
                    <div class="control-group">
                        <label class="text-[9px] font-black uppercase tracking-widest text-gray-500 mb-2 block">Footer & App</label>
                        <textarea name="footer_text" oninput="updatePreview()" class="input-dark h-20 mb-2" placeholder="Footer Copyright / Info"><?= $page['footer_text'] ?? 'Official Horizon Partner' ?></textarea>
                        <input type="url" name="app_download_link" oninput="updatePreview()" value="<?= $page['app_download_link'] ?? '' ?>" class="input-dark" placeholder="App Download URL">
                    </div>
                </div>
            </div>

            <!-- Theme Toggle -->
            <div class="flex items-center justify-between p-4 bg-white/5 rounded-2xl border border-white/5">
                <div class="flex items-center gap-3">
                    <span class="material-symbols-outlined text-primary">dark_mode</span>
                    <span class="text-[10px] font-black uppercase tracking-widest">Theme Mode</span>
                </div>
                <select name="theme_mode" onchange="updatePreview()" class="bg-transparent text-[10px] font-black uppercase text-primary outline-none cursor-pointer">
                    <option value="dark" class="bg-[#0a090d]" <?= ($page['theme_mode'] ?? 'dark') == 'dark' ? 'selected' : '' ?>>Dark</option>
                    <option value="light" class="bg-[#0a090d]" <?= ($page['theme_mode'] ?? '') == 'light' ? 'selected' : '' ?>>Light</option>
                </select>
            </div>

            <button type="submit" class="w-full h-16 rounded-2xl bg-primary hover:bg-primary-hover shadow-lg shadow-primary/20 transition-all text-xs font-black uppercase tracking-[0.2em] flex items-center justify-center gap-3">
                <span class="material-symbols-outlined">auto_fix_high</span> Save & Publish
            </button>
        </form>
    </div>

    <!-- Right: Preview Area -->
    <div class="flex-1 bg-[#121118] p-10 flex flex-col relative overflow-hidden">
        <!-- Floating Device Toggles -->
        <div class="absolute top-10 left-10 flex gap-2 z-10">
            <button onclick="setPreviewMode('web')" id="webBtn" class="tab-btn size-10 rounded-xl bg-white/5 border border-white/10 flex items-center justify-center text-gray-500 hover:text-white transition-all active">
                <span class="material-symbols-outlined">desktop_windows</span>
            </button>
            <button onclick="setPreviewMode('mobile')" id="mobileBtn" class="tab-btn size-10 rounded-xl bg-white/5 border border-white/10 flex items-center justify-center text-gray-500 hover:text-white transition-all">
                <span class="material-symbols-outlined">smartphone</span>
            </button>
        </div>

        <div class="absolute top-10 right-10 z-10">
             <div class="px-4 py-2 rounded-full border border-primary/20 bg-primary/5 flex items-center gap-2">
                <span class="size-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                <span class="text-[8px] font-black uppercase tracking-[0.2em] text-primary">Live Preview</span>
            </div>
        </div>

        <div class="flex-1 flex items-center justify-center">
            <div id="previewFrameContainer" class="preview-container preview-web overflow-hidden relative">
                <iframe id="previewIframe" src="../portal.php?gym=<?= $page['page_slug'] ?? '' ?>&preview=1" class="w-full h-full border-none"></iframe>
            </div>
        </div>
    </div>
</div>

<script>
    function toggleAccordion(btn) {
        const item = btn.parentElement;
        item.classList.toggle('accordion-open');
        btn.querySelector('span').innerText = item.classList.contains('accordion-open') ? 'expand_less' : 'expand_more';
    }

    function setPreviewMode(mode) {
        const container = document.getElementById('previewFrameContainer');
        document.getElementById('webBtn').classList.toggle('active', mode === 'web');
        document.getElementById('mobileBtn').classList.toggle('active', mode === 'mobile');
        
        if (mode === 'web') {
            container.className = 'preview-container preview-web overflow-hidden relative';
        } else {
            container.className = 'preview-container preview-mobile overflow-hidden relative';
        }
    }

    function setRadius(val, btn) {
        document.getElementById('border_radius_val').value = val;
        updatePreview();
    }

    function previewLogo(input) {
        if (input.files && input.files[0]) {
            var reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('logo-preview').src = e.target.result;
                document.getElementById('logo-preview').classList.remove('hidden');
                document.getElementById('logo-placeholder').classList.add('hidden');
                updatePreview();
            }
            reader.readAsDataURL(input.files[0]);
        }
    }

    function updatePreview() {
        const form = document.getElementById('customizerForm');
        const formData = new FormData(form);
        const data = {};
        formData.forEach((value, key) => data[key] = value);
        
        // Send to iframe via postMessage
        const iframe = document.getElementById('previewIframe');
        iframe.contentWindow.postMessage({ type: 'updateStyles', data: data }, '*');
    }

    // Debounce updates for better performance
    let timeout = null;
    document.getElementById('customizerForm').addEventListener('input', () => {
        clearTimeout(timeout);
        timeout = setTimeout(updatePreview, 100);
    });

    window.onload = () => {
        setTimeout(updatePreview, 1000); // Initial sync
    }
</script>

</body>
</html>
