<?php
session_start();
require_once '../db.php';

// Security Check: Only Tenants/Admins/Staff
$role = strtolower($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || ($role !== 'tenant' && $role !== 'admin' && $role !== 'staff' && $role !== 'coach')) {
    header("Location: ../login.php");
    exit;
}

$gym_id = $_SESSION['gym_id'];
$user_id = $_SESSION['user_id'];

// Check Subscription
$stmtSub = $pdo->prepare("SELECT ws.plan_name FROM client_subscriptions cs JOIN website_plans ws ON cs.website_plan_id = ws.website_plan_id WHERE cs.gym_id = ? AND cs.subscription_status = 'Active' LIMIT 1");
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
    $app_download_link = $_POST['app_download_link'] ?? '';
    $about_text = $_POST['about_text'] ?? '';
    $contact_text = $_POST['contact_text'] ?? '';
    $now = date('Y-m-d H:i:s');

    // Handle Logo Upload
    $logo_path = $page['logo_path'] ?? '';
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] == 0) {
        $type = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
        $data = file_get_contents($_FILES['logo']['tmp_name']);
        $logo_path = 'data:image/' . $type . ';base64,' . base64_encode($data);
    }

    // Handle Banner Upload
    $banner_image = $page['banner_image'] ?? '';
    if (isset($_FILES['banner']) && $_FILES['banner']['error'] == 0) {
        $type = pathinfo($_FILES['banner']['name'], PATHINFO_EXTENSION);
        $data = file_get_contents($_FILES['banner']['tmp_name']);
        $banner_image = 'data:image/' . $type . ';base64,' . base64_encode($data);
    }

    try {
        if ($page) {
            $stmtUpdate = $pdo->prepare("UPDATE tenant_pages SET page_title = ?, logo_path = ?, banner_image = ?, theme_color = ?, bg_color = ?, font_family = ?, app_download_link = ?, about_text = ?, contact_text = ?, updated_at = ? WHERE gym_id = ?");
            $stmtUpdate->execute([$page_title, $logo_path, $banner_image, $theme_color, $bg_color, $font_family, $app_download_link, $about_text, $contact_text, $now, $gym_id]);
        } else {
            $page_slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $gym['gym_name']));
            $stmtInsert = $pdo->prepare("INSERT INTO tenant_pages (gym_id, page_slug, page_title, logo_path, banner_image, theme_color, bg_color, font_family, app_download_link, about_text, contact_text, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtInsert->execute([$gym_id, $page_slug, $page_title, $logo_path, $banner_image, $theme_color, $bg_color, $font_family, $app_download_link, $about_text, $contact_text, $now]);
        }
        $_SESSION['success_msg'] = "Portal settings saved!";
        header("Location: tenant_settings.php");
        exit;
    } catch (Exception $e) { $error = $e->getMessage(); }
}

$page_title = "Page Customize";
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
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
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
        
        .input-dark { background: #0a090d; border: 1px solid rgba(255,255,255,0.05); border-radius: 12px; color: white; padding: 0.75rem 1rem; font-size: 0.75rem; width: 100%; outline: none; transition: border-color 0.2s; }
        .input-dark:focus { border-color: #8c2bee; }
        
        /* High-Fidelity Emulator Frame */
        .phone-mockup {
            width: 380px;
            height: 820px;
            background: #1A1A1D;
            border-radius: 54px;
            padding: 12px;
            position: relative;
            box-shadow: 0 40px 100px rgba(0,0,0,0.6), 0 0 0 1px rgba(255,255,255,0.05);
            overflow: visible;
            transform-origin: top center;
            transform: scale(0.75); /* Default scale to fit standard screens */
            margin-bottom: -180px; /* Pull content up to compensate for scale gap */
        }
        
        @media (min-height: 900px) {
            .phone-mockup { transform: scale(0.85); margin-bottom: -100px; }
        }
        @media (min-height: 1000px) {
            .phone-mockup { transform: scale(0.95); margin-bottom: -20px; }
        }

        /* Punch Hole Camera */
        .phone-mockup::before {
            content: '';
            position: absolute;
            top: 24px;
            left: 50%;
            transform: translateX(-50%);
            width: 28px;
            height: 28px;
            background: #050505;
            border-radius: 50%;
            z-index: 20;
            box-shadow: inset 0 0 5px rgba(255,255,255,0.1);
        }
        /* Side Buttons */
        .phone-mockup::after {
            content: '';
            position: absolute;
            right: -3px;
            top: 160px;
            width: 3px;
            height: 60px;
            background: #1A1A1D;
            border-radius: 0 4px 4px 0;
            box-shadow: 2px 0 5px rgba(0,0,0,0.5);
        }

        .phone-screen {
            width: 100%;
            height: 100%;
            border-radius: 42px;
            overflow: hidden;
            background: #000;
            position: relative;
        }
    </style>
</head>
<body class="antialiased flex h-screen overflow-hidden">

<nav class="flex flex-col w-64 lg:w-72 bg-[#0a090d] border-r border-white/5 sticky top-0 h-screen p-8 z-50 shrink-0">
    <div class="mb-12">
        <div class="flex items-center gap-3 mb-6">
            <div class="size-10 rounded-xl bg-primary flex items-center justify-center shadow-lg shrink-0 overflow-hidden">
                <?php if (!empty($page['logo_path'])): ?>
                    <img id="sidebar-logo" src="<?= $page['logo_path'] ?>" class="size-full object-contain">
                <?php else: ?>
                    <span id="sidebar-icon" class="material-symbols-outlined text-white text-2xl">bolt</span>
                <?php endif; ?>
            </div>
            <h1 class="text-lg font-black italic uppercase tracking-tighter text-white leading-none break-words line-clamp-2 gym-name-display"><?= htmlspecialchars($page['page_title'] ?? $gym['gym_name']) ?></h1>
        </div>
        <div class="p-4 rounded-2xl bg-white/5 border border-white/5">
            <div class="mb-2">
                <p id="sidebarClock" class="text-white font-black italic text-base leading-none">00:00:00 AM</p>
            </div>
            <p class="text-[9px] font-black uppercase text-gray-500 tracking-[0.2em] leading-none mb-1"><?= date('l, M d') ?></p>
            <div class="pt-2 border-t border-white/5 mt-2">
                <p class="text-[8px] font-black uppercase text-gray-600 tracking-widest mb-1">Current Plan</p>
                <div class="flex items-center justify-between">
                    <p class="text-[10px] font-black uppercase text-white italic tracking-tighter"><?= htmlspecialchars($sub['plan_name'] ?? 'Standard Plan') ?></p>
                    <span class="px-2 py-0.5 rounded-md bg-primary/20 text-primary text-[8px] font-black uppercase tracking-widest">Active</span>
                </div>
            </div>
        </div>
    </div>
    
    <div class="flex flex-col gap-5 flex-1 overflow-y-auto no-scrollbar pr-2">
        <a href="tenant_dashboard.php" class="nav-link flex items-center gap-3 <?= ($active_page == 'dashboard') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl">grid_view</span> Dashboard
        </a>
        <a href="tenant_settings.php" class="nav-link flex items-center gap-3 <?= ($active_page == 'settings') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl">palette</span> Page Customize
        </a>
        <a href="staff_management.php" class="nav-link flex items-center gap-3 <?= ($active_page == 'staff') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl">group</span> Staff Management
        </a>
    </div>

    <div class="mt-auto pt-8 border-t border-white/10 flex flex-col gap-5">
        <a href="#" class="text-gray-400 hover:text-white transition-colors flex items-center gap-3 group">
            <span class="material-symbols-outlined transition-transform group-hover:text-primary">person</span>
            <span class="nav-link">Profile</span>
        </a>
        <a href="../logout.php" class="text-gray-400 hover:text-red-500 transition-colors flex items-center gap-3 group">
            <span class="material-symbols-outlined group-hover:translate-x-1 transition-transform">logout</span>
            <span class="nav-link">Sign Out</span>
        </a>
    </div>
</nav>

<div class="flex-1 p-10 max-w-[1400px] w-full mx-auto overflow-y-auto no-scrollbar">
        <header class="mb-10 flex flex-col md:flex-row justify-between items-start md:items-end gap-6">
            <div>
                <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white">Page <span class="text-primary">Customize</span></h2>
                <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-1">Operational Brand Management</p>
            </div>
            <div class="flex gap-2">
                <a target="_blank" href="../portal.php?gym=<?= htmlspecialchars($page['page_slug'] ?? '') ?>" class="px-5 h-10 rounded-xl bg-white/5 border border-white/10 flex items-center gap-2 text-[10px] font-black uppercase tracking-widest hover:bg-white/10 transition-all">
                    <span class="material-symbols-outlined text-sm">open_in_new</span> Full Web Portal
                </a>
            </div>
        </header>

        <?php if ($error): ?>
        <div class="mb-8 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-xs font-bold flex items-center gap-3">
            <span class="material-symbols-outlined">report</span> <?= $error ?>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['success_msg'])): ?>
        <div class="mb-8 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-xs font-bold flex items-center gap-3">
            <span class="material-symbols-outlined">check_circle</span> <?= $_SESSION['success_msg']; unset($_SESSION['success_msg']); ?>
        </div>
        <?php endif; ?>

        <form id="customizeForm" method="POST" enctype="multipart/form-data" class="grid grid-cols-1 lg:grid-cols-2 gap-10">
            <!-- Left: Controls -->
            <div class="space-y-8">
                <div class="glass-card p-8">
                    <h4 class="text-sm font-black italic uppercase tracking-tighter mb-6 flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">brush</span> Branding & Colors
                    </h4>
                    <div class="space-y-6">
                        <div class="flex items-center gap-6">
                            <div class="size-24 rounded-2xl bg-background-dark border-2 border-dashed border-white/5 flex items-center justify-center overflow-hidden relative group">
                                <img id="logo-preview" src="<?= !empty($page['logo_path']) ? $page['logo_path'] : '' ?>" class="size-full object-contain <?= empty($page['logo_path']) ? 'hidden' : '' ?>">
                                <span id="logo-placeholder" class="material-symbols-outlined text-gray-700 <?= !empty($page['logo_path']) ? 'hidden' : '' ?>">add_photo_alternate</span>
                                <input type="file" name="logo" class="absolute inset-0 opacity-0 cursor-pointer" onchange="previewLogo(this)">
                            </div>
                            <div class="flex-1 space-y-4">
                                <div class="space-y-1.5">
                                    <label class="text-[9px] font-black uppercase tracking-widest text-gray-500 ml-1">PORTAL DISPLAY NAME</label>
                                    <input type="text" name="page_title" oninput="updatePreview()" value="<?= htmlspecialchars($page['page_title'] ?? $gym['gym_name']) ?>" class="input-dark">
                                </div>
                                <div class="space-y-1.5">
                                    <label class="text-[9px] font-black uppercase tracking-widest text-gray-500 ml-1">HERO BANNER IMAGE</label>
                                    <div class="flex items-center gap-3">
                                        <div class="h-10 w-20 rounded-lg bg-background-dark border border-white/5 overflow-hidden">
                                             <img id="banner-preview-small" src="<?= !empty($page['banner_image']) ? $page['banner_image'] : '' ?>" class="size-full object-cover <?= empty($page['banner_image']) ? 'hidden' : '' ?>">
                                        </div>
                                        <input type="file" name="banner" class="text-[10px] text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-[10px] file:font-black file:bg-primary/10 file:text-primary hover:file:bg-primary/20 cursor-pointer" onchange="previewBanner(this)">
                                    </div>
                                </div>
                                <div class="space-y-1.5">
                                    <label class="text-[9px] font-black uppercase tracking-widest text-gray-500 ml-1">FONT FAMILY</label>
                                    <select name="font_family" onchange="updatePreview()" class="input-dark bg-background-dark">
                                        <option value="Lexend" <?= ($page['font_family'] ?? '') == 'Lexend' ? 'selected' : '' ?>>Lexend (Sporty)</option>
                                        <option value="Inter" <?= ($page['font_family'] ?? '') == 'Inter' ? 'selected' : '' ?>>Inter (Modern)</option>
                                        <option value="Outfit" <?= ($page['font_family'] ?? '') == 'Outfit' ? 'selected' : '' ?>>Outfit (Premium)</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-2 gap-6 pt-4 border-t border-white/5">
                            <div class="space-y-2">
                                <label class="text-[9px] font-black uppercase tracking-widest text-gray-500 ml-1">Primary Color</label>
                                <div class="flex items-center gap-4 bg-background-dark p-2 rounded-xl border border-white/5">
                                    <input type="color" name="theme_color" oninput="updatePreview()" value="<?= htmlspecialchars($page['theme_color'] ?? '#8c2bee') ?>" class="size-10 rounded-lg cursor-pointer bg-transparent border-none">
                                    <span class="text-[10px] font-black italic uppercase text-gray-400" id="primary-hex">#8c2bee</span>
                                </div>
                            </div>
                            <div class="space-y-2">
                                <label class="text-[9px] font-black uppercase tracking-widest text-gray-500 ml-1">Background Color</label>
                                <div class="flex items-center gap-4 bg-background-dark p-2 rounded-xl border border-white/5">
                                    <input type="color" name="bg_color" oninput="updatePreview()" value="<?= htmlspecialchars($page['bg_color'] ?? '#0a090d') ?>" class="size-10 rounded-lg cursor-pointer bg-transparent border-none">
                                    <span class="text-[10px] font-black italic uppercase text-gray-400" id="bg-hex">#0a090d</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="glass-card p-8">
                    <h4 class="text-sm font-black italic uppercase tracking-tighter mb-6 flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">info</span> Content & Links
                    </h4>
                    <div class="space-y-6">
                        <div class="space-y-1.5">
                            <label class="text-[9px] font-black uppercase tracking-widest text-gray-500 ml-1">App Download Link (APK URL)</label>
                            <input type="url" name="app_download_link" oninput="updatePreview()" value="<?= htmlspecialchars($page['app_download_link'] ?? '') ?>" placeholder="Empty defaults to horizon.apk" class="input-dark">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[9px] font-black uppercase tracking-widest text-gray-500 ml-1">About the Facility</label>
                            <textarea name="about_text" oninput="updatePreview()" rows="3" class="input-dark h-24"><?= htmlspecialchars($page['about_text'] ?? '') ?></textarea>
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[9px] font-black uppercase tracking-widest text-gray-500 ml-1">Contact / Location Details</label>
                            <textarea name="contact_text" oninput="updatePreview()" rows="2" class="input-dark h-20"><?= htmlspecialchars($page['contact_text'] ?? '') ?></textarea>
                        </div>
                        <div class="space-y-1.5 pt-6 border-t border-white/5">
                            <label class="text-[9px] font-black uppercase tracking-widest text-primary ml-1">Your Unique Portal URL</label>
                            <div class="flex items-center gap-3 bg-primary/5 border border-primary/20 rounded-xl p-4 group">
                                <div class="flex-1 overflow-hidden">
                                     <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Share this with your members:</p>
                                     <p id="portal-url" class="text-xs font-black text-white italic truncate">
                                        <?= (empty($_SERVER['HTTPS']) ? 'http' : 'https') . "://" . $_SERVER['HTTP_HOST'] . dirname(dirname($_SERVER['REQUEST_URI'])) . "/portal.php?gym=" . ($page['page_slug'] ?? '') ?>
                                     </p>
                                </div>
                                <button type="button" onclick="copyPortalURL()" class="size-10 rounded-lg bg-primary/20 text-primary flex items-center justify-center hover:bg-primary transition-all hover:text-white shrink-0 shadow-lg shadow-primary/10">
                                    <span class="material-symbols-outlined text-lg">content_copy</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <button type="submit" class="w-full h-16 rounded-2xl bg-primary hover:bg-primary-hover shadow-lg shadow-primary/20 transition-all text-xs font-black uppercase tracking-[0.2em] flex items-center justify-center gap-3">
                    <span class="material-symbols-outlined">save</span> Save & Publish Changes
                </button>
            </div>

            <!-- Right: High-Fidelity App Preview (Sticky) -->
            <div class="relative">
                <div class="sticky top-0 pt-4">
                    <div class="absolute inset-0 bg-primary/5 rounded-full blur-[100px] -z-10 animate-pulse"></div>
                    <div class="phone-mockup mx-auto">
                        <div class="phone-screen">
                            <iframe id="previewIframe" src="../mobile_app_preview.php?gym=<?= htmlspecialchars($page['page_slug'] ?? '') ?>" class="w-full h-full border-none"></iframe>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

<script>
    function copyPortalURL() {
        const url = document.getElementById('portal-url').innerText.trim();
        navigator.clipboard.writeText(url).then(() => {
            alert('Portal URL copied to clipboard!');
        });
    }

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

    function previewLogo(input) {
        if (input.files && input.files[0]) {
            var reader = new FileReader();
            reader.onload = function(e) {
                // Update Main Preview Logo
                const logoPreview = document.getElementById('logo-preview');
                logoPreview.src = e.target.result;
                logoPreview.classList.remove('hidden');
                document.getElementById('logo-placeholder').classList.add('hidden');
                
                // Update Sidebar Logo
                const sidebarLogo = document.getElementById('sidebar-logo');
                const sidebarIcon = document.getElementById('sidebar-icon');
                if (sidebarLogo) {
                    sidebarLogo.src = e.target.result;
                } else if (sidebarIcon) {
                    const newImg = document.createElement('img');
                    newImg.id = "sidebar-logo";
                    newImg.src = e.target.result;
                    newImg.className = "size-full object-contain";
                    sidebarIcon.replaceWith(newImg);
                }

                updatePreview(e.target.result);
            }
            reader.readAsDataURL(input.files[0]);
        }
    }

    function previewBanner(input) {
        if (input.files && input.files[0]) {
            var reader = new FileReader();
            reader.onload = function(e) {
                const bannerPreview = document.getElementById('banner-preview-small');
                bannerPreview.src = e.target.result;
                bannerPreview.classList.remove('hidden');
                updatePreview();
            }
            reader.readAsDataURL(input.files[0]);
        }
    }

    function updatePreview(logoData = null) {
        const form = document.getElementById('customizeForm');
        const formData = new FormData(form);
        const data = {};
        formData.forEach((value, key) => {
            if (key !== 'logo' && key !== 'banner') data[key] = value;
        });
        
        if (logoData) {
            data.logo_preview = logoData;
        } else {
            const currentLogo = document.getElementById('logo-preview');
            if (currentLogo && !currentLogo.classList.contains('hidden')) {
                data.logo_preview = currentLogo.src;
            }
        }

        // Add Banner Preview
        const currentBanner = document.getElementById('banner-preview-small');
        if (currentBanner && !currentBanner.classList.contains('hidden')) {
            data.banner_preview = currentBanner.src;
        }

        const primaryHex = document.getElementById('primary-hex');
        const bgHex = document.getElementById('bg-hex');
        if (primaryHex) primaryHex.innerText = (data.theme_color || '#8c2bee').toUpperCase();
        if (bgHex) bgHex.innerText = (data.bg_color || '#0a090d').toUpperCase();
        
        const iframe = document.getElementById('previewIframe');
        if (iframe && iframe.contentWindow) {
            iframe.contentWindow.postMessage({ type: 'updateStyles', data: data }, '*');
        }
    }

    window.addEventListener('message', (event) => {
        if (event.data.type === 'previewReady') {
            updatePreview();
        }
    });

    window.addEventListener('DOMContentLoaded', () => {
        updateSidebarClock();
        setTimeout(updatePreview, 1000);
    });
</script>

</body>
</html>
