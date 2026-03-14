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
    $page_slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $_POST['page_slug']));
    $page_title = $_POST['page_title'] ?? $gym['gym_name'];
    $theme_color = $_POST['theme_color'] ?? '#8c2bee';
    $secondary_color = $_POST['secondary_color'] ?? '#ffffff';
    $font_family = $_POST['font_family'] ?? 'Lexend';
    $button_shape = $_POST['button_shape'] ?? 'rounded-2xl';
    $theme_mode = $_POST['theme_mode'] ?? 'dark';
    $font_size = $_POST['font_size'] ?? 'base';
    $about_text = $_POST['about_text'] ?? '';
    $contact_text = $_POST['contact_text'] ?? '';
    $app_link = $_POST['app_download_link'] ?? '';
    $now = date('Y-m-d H:i:s');

    // Handle Logo Upload (Store as Base64 in Database)
    $logo_path = $page['logo_path'] ?? '';
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] == 0) {
        $type = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
        $data = file_get_contents($_FILES['logo']['tmp_name']);
        $logo_path = 'data:image/' . $type . ';base64,' . base64_encode($data);
    }

    try {
        // Ensure columns exist (Self-healing)
        $pdo->exec("ALTER TABLE tenant_pages ADD COLUMN IF NOT EXISTS theme_mode VARCHAR(20) DEFAULT 'dark'");
        $pdo->exec("ALTER TABLE tenant_pages ADD COLUMN IF NOT EXISTS font_size VARCHAR(20) DEFAULT 'base'");
        $pdo->exec("ALTER TABLE tenant_pages ADD COLUMN IF NOT EXISTS secondary_color VARCHAR(100) DEFAULT '#ffffff'");

        if ($page) {
            $stmtUpdate = $pdo->prepare("UPDATE tenant_pages SET page_slug = ?, page_title = ?, logo_path = ?, theme_color = ?, secondary_color = ?, font_family = ?, button_shape = ?, theme_mode = ?, font_size = ?, about_text = ?, contact_text = ?, app_download_link = ?, updated_at = ?, is_active = 1 WHERE gym_id = ?");
            $stmtUpdate->execute([$page_slug, $page_title, $logo_path, $theme_color, $secondary_color, $font_family, $button_shape, $theme_mode, $font_size, $about_text, $contact_text, $app_link, $now, $gym_id]);
        } else {
            $stmtInsert = $pdo->prepare("INSERT INTO tenant_pages (gym_id, page_slug, page_title, logo_path, theme_color, secondary_color, font_family, button_shape, theme_mode, font_size, about_text, contact_text, app_download_link, updated_at, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
            $stmtInsert->execute([$gym_id, $page_slug, $page_title, $logo_path, $theme_color, $secondary_color, $font_family, $button_shape, $theme_mode, $font_size, $about_text, $contact_text, $app_link, $now]);
        }
        $_SESSION['success_msg'] = "Portal settings updated successfully!";
        header("Location: tenant_settings.php");
        exit;
    } catch (Exception $e) {
        $error = "Update failed: " . $e->getMessage();
    }
}

$primary_color = $page['theme_color'] ?? '#8c2bee';
$secondary_color = $page['secondary_color'] ?? '#ffffff';
$page_title = "Page Customizer";
$active_page = "settings";
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title><?= $page_title ?> | Horizon Partners</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "#8c2bee", "background-dark": "#0a090d", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)"}}}
        }
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        .nav-link { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; transition: all 0.2s; white-space: nowrap; }
        .active-nav { color: #8c2bee !important; position: relative; }
        .active-nav::after { content: ''; position: absolute; right: -32px; top: 0; width: 4px; height: 100%; background: #8c2bee; border-radius: 2px; }
        .nav-link:hover:not(.active-nav) { color: white; }

        .tab-btn { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; padding: 10px 20px; border-radius: 12px; transition: all 0.2s; border: 1px solid transparent; color: #64748b; }
        .tab-btn.active { background: rgba(140, 43, 238, 0.1); border-color: rgba(140, 43, 238, 0.2); color: #8c2bee; }
        .tab-content { display: none; animation: fadeIn 0.3s ease-in-out; }
        .tab-content.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }

        /* Mockup Styles */
        .mockup-container { width: 300px; height: 600px; border: 12px solid #1a1a1a; border-radius: 40px; background: black; position: relative; overflow: hidden; box-shadow: 0 40px 100px -20px rgba(0,0,0,0.5); }
        .mockup-inner { height: 100%; width: 100%; overflow-y: auto; overflow-x: hidden; }
        .mockup-inner::-webkit-scrollbar { width: 0; }
        .preview-header { padding: 20px; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .switch-btn { font-size: 9px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; padding: 6px 12px; border-radius: 8px; transition: all 0.2s; color: #64748b; }
        .switch-btn.active { background: white; color: black; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .preview-toggle { background: rgba(255,255,255,0.05); padding: 4px; border-radius: 12px; display: flex; gap: 4px; }

        .dashboard-preview { display: none; }
        .dashboard-preview.active { display: block; }
        .login-preview.active { display: block; }
        .login-preview { display: none; }
    </style>
</head>
<body class="antialiased flex flex-row min-h-screen">

<nav class="flex flex-col w-64 bg-[#0a090d] border-r border-white/5 sticky top-0 h-screen p-8 z-50 shrink-0">
    <div class="mb-12">
        <div class="flex items-center gap-4 mb-6">
            <div class="size-10 rounded-xl bg-primary flex items-center justify-center shadow-lg shrink-0">
                <span class="material-symbols-outlined text-white text-2xl">bolt</span>
            </div>
            <h1 class="text-xl font-black italic uppercase tracking-tighter text-white"><?= htmlspecialchars($gym['gym_name']) ?></h1>
        </div>
    </div>
    
    <div class="flex flex-col gap-2 flex-1 overflow-y-auto pr-2">
        <a href="tenant_dashboard.php" class="nav-link flex items-center gap-3 <?= ($active_page == 'dashboard') ? 'active-nav text-primary' : 'text-gray-400' ?>">
            <span class="material-symbols-outlined text-xl">grid_view</span> 
            <span class="tracking-tight">Dashboard Overview</span>
        </a>
        <a href="tenant_settings.php" class="nav-link flex items-center gap-3 <?= ($active_page == 'settings') ? 'active-nav text-primary' : 'text-gray-400' ?>">
            <span class="material-symbols-outlined text-xl">tune</span> 
            <span class="tracking-tight">Page Customizer</span>
        </a>
        <a href="add_staff.php" class="nav-link flex items-center gap-3 text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl">badge</span> 
            <span class="tracking-tight">Staff Roster</span>
        </a>
        <a href="#" class="nav-link flex items-center gap-3 text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl">group</span> 
            <span class="tracking-tight">Member Directory</span>
        </a>
        <div class="mt-auto pt-8 border-t border-white/5">
            <a href="../logout.php" class="nav-link flex items-center gap-3 text-gray-500 hover:text-red-400">
                <span class="material-symbols-outlined">logout</span>
                <span class="tracking-tight">Sign Out</span>
            </a>
        </div>
    </div>
</nav>

<div class="flex-1 p-10 max-w-[1400px] w-full mx-auto overflow-y-auto">
    <header class="mb-8 flex justify-between items-end">
        <div>
            <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white">Page <span class="text-primary">Customizer</span></h2>
            <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-1">Enhance your facility's digital identity</p>
        </div>
        <div class="flex gap-4">
            <button type="submit" form="settings-form" class="h-11 px-8 rounded-xl bg-primary hover:bg-primary-hover shadow-lg shadow-primary/20 transition-all text-[10px] font-black uppercase tracking-[0.2em] flex items-center gap-2">
                <span class="material-symbols-outlined text-sm">save</span> Save Changes
            </button>
            <a target="_blank" href="../portal.php?gym=<?= htmlspecialchars($page['page_slug'] ?? '') ?>" class="h-11 px-6 rounded-xl bg-white/5 text-gray-400 border border-white/10 hover:bg-white/10 hover:text-white transition-all text-[9px] font-black uppercase tracking-widest flex items-center gap-2">
               <span class="material-symbols-outlined text-xs">visibility</span> Preview
            </a>
        </div>
    </header>

    <?php if (isset($_SESSION['success_msg'])): ?>
        <div class="mb-8 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-xs font-bold uppercase tracking-widest italic">
            <?= $_SESSION['success_msg']; unset($_SESSION['success_msg']); ?>
        </div>
    <?php endif; ?>

    <div class="flex flex-col xl:flex-row gap-10">
        <!-- Form Section -->
        <div class="flex-1 space-y-8">
            <!-- Tab Navigation -->
            <div class="flex gap-2 p-1.5 bg-background-dark border border-white/5 rounded-2xl w-fit mb-8">
                <button type="button" class="tab-btn active" onclick="switchTab('hero')">Hero Section</button>
                <button type="button" class="tab-btn" onclick="switchTab('branding')">Branding</button>
                <button type="button" class="tab-btn" onclick="switchTab('content')">Content</button>
                <button type="button" class="tab-btn" onclick="switchTab('app')">App Link</button>
            </div>

            <form id="settings-form" method="POST" enctype="multipart/form-data" class="space-y-8">
                <!-- Hero Tab -->
                <div id="tab-hero" class="tab-content active space-y-8">
                    <div class="glass-card p-8">
                        <h4 class="text-sm font-black italic uppercase tracking-tighter mb-8 flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary">view_quilt</span> Hero Configuration
                        </h4>
                        <div class="space-y-6">
                            <div class="flex items-center gap-8 p-6 bg-background-dark/50 rounded-2xl border border-white/5">
                                <div class="size-24 rounded-3xl bg-background-dark border-2 border-dashed border-white/5 flex items-center justify-center overflow-hidden relative group shrink-0">
                                    <?php 
                                    $logo_src = $page['logo_path'] ?? '';
                                    if ($logo_src && strpos($logo_src, 'data:') !== 0) {
                                        $logo_src = '../' . $logo_src;
                                    }
                                    ?>
                                    <?php if($logo_src): ?>
                                        <img id="logo-preview" src="<?= htmlspecialchars($logo_src) ?>" class="w-full h-full object-contain">
                                    <?php else: ?>
                                        <span id="logo-placeholder" class="material-symbols-outlined text-3xl text-gray-800">add_photo_alternate</span>
                                    <?php endif; ?>
                                    <input type="file" name="logo" accept="image/*" class="absolute inset-0 opacity-0 cursor-pointer" onchange="previewImage(this)">
                                </div>
                                <div class="space-y-1">
                                    <label class="text-[10px] font-black uppercase tracking-widest text-white">Facility Logo</label>
                                    <p class="text-[9px] text-gray-500 italic">Recommended: Transparent PNG, 512x512px</p>
                                    <button type="button" onclick="this.parentElement.previousElementSibling.querySelector('input').click()" class="text-[9px] font-black uppercase text-primary mt-2 hover:text-white transition-colors">Replace Image</button>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 pt-4">
                                <div class="space-y-1.5">
                                    <label class="text-[9px] font-black uppercase tracking-widest text-gray-500 ml-1">Gym Display Name</label>
                                    <input type="text" name="page_title" data-preview="#p-title" required value="<?= htmlspecialchars($page['page_title'] ?? $gym['gym_name']) ?>" class="w-full h-12 bg-background-dark border border-white/5 rounded-xl px-4 text-xs focus:border-primary outline-none transition-all">
                                </div>
                                <div class="space-y-1.5">
                                    <label class="text-[9px] font-black uppercase tracking-widest text-gray-500 ml-1">URL Reference Slug</label>
                                    <input type="text" name="page_slug" required value="<?= htmlspecialchars($page['page_slug'] ?? '') ?>" class="w-full h-12 bg-background-dark border border-white/5 rounded-xl px-4 text-xs focus:border-primary outline-none transition-all">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Branding Tab -->
                <div id="tab-branding" class="tab-content space-y-8">
                    <div class="glass-card p-8">
                        <h4 class="text-sm font-black italic uppercase tracking-tighter mb-8 flex items-center gap-2">
                             <span class="material-symbols-outlined text-primary">brush</span> Visual Identity
                        </h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-12 gap-y-8">
                            <div class="space-y-3">
                                <label class="text-[9px] font-black uppercase tracking-widest text-gray-500">Primary Color</label>
                                <div class="flex items-center gap-4 bg-background-dark p-3 rounded-2xl border border-white/5">
                                    <input type="color" name="theme_color" value="<?= htmlspecialchars($page['theme_color'] ?? '#8c2bee') ?>" class="size-10 rounded-lg cursor-pointer bg-transparent border-none" oninput="updateColorPreview(this.value, 'primary')">
                                    <span class="text-xs font-black italic uppercase"><?= htmlspecialchars($page['theme_color'] ?? '#8c2bee') ?></span>
                                </div>
                            </div>

                            <div class="space-y-3">
                                <label class="text-[9px] font-black uppercase tracking-widest text-gray-500">Secondary Color</label>
                                <div class="flex items-center gap-4 bg-background-dark p-3 rounded-2xl border border-white/5">
                                    <input type="color" name="secondary_color" value="<?= htmlspecialchars($page['secondary_color'] ?? '#ffffff') ?>" class="size-10 rounded-lg cursor-pointer bg-transparent border-none" oninput="updateColorPreview(this.value, 'secondary')">
                                    <span class="text-xs font-black italic uppercase"><?= htmlspecialchars($page['secondary_color'] ?? '#ffffff') ?></span>
                                </div>
                            </div>

                            <div class="space-y-3">
                                <label class="text-[9px] font-black uppercase tracking-widest text-gray-500">Active Mode</label>
                                <div class="flex gap-2 p-1 bg-background-dark border border-white/5 rounded-2xl">
                                    <label class="flex-1 cursor-pointer group">
                                        <input type="radio" name="theme_mode" value="dark" class="peer" hidden <?= ($page['theme_mode'] ?? 'dark') == 'dark' ? 'checked' : '' ?> onchange="updateModePreview('dark')">
                                        <div class="h-10 flex items-center justify-center gap-2 text-[9px] font-black uppercase tracking-widest rounded-xl transition-all peer-checked:bg-primary peer-checked:text-white peer-checked:shadow-[0_0_20px_rgba(140,43,238,0.3)] text-gray-500 group-hover:bg-white/5">
                                            <span class="material-symbols-outlined text-sm">dark_mode</span> Dark
                                        </div>
                                    </label>
                                    <label class="flex-1 cursor-pointer group">
                                        <input type="radio" name="theme_mode" value="light" class="peer" hidden <?= ($page['theme_mode'] ?? '') == 'light' ? 'checked' : '' ?> onchange="updateModePreview('light')">
                                        <div class="h-10 flex items-center justify-center gap-2 text-[9px] font-black uppercase tracking-widest rounded-xl transition-all peer-checked:bg-primary peer-checked:text-white peer-checked:shadow-[0_0_20px_rgba(140,43,238,0.3)] text-gray-500 group-hover:bg-white/5">
                                            <span class="material-symbols-outlined text-sm">light_mode</span> Light
                                        </div>
                                    </label>
                                </div>
                            </div>

                            <div class="space-y-3">
                                <label class="text-[9px] font-black uppercase tracking-widest text-gray-500">Font Family</label>
                                <select name="font_family" class="w-full h-12 bg-background-dark border border-white/5 rounded-xl px-4 text-xs focus:border-primary outline-none transition-all appearance-none" onchange="updateFontPreview(this.value)">
                                    <option value="Lexend" <?= ($page['font_family'] ?? '') == 'Lexend' ? 'selected' : '' ?>>Lexend (Default)</option>
                                    <option value="Inter" <?= ($page['font_family'] ?? '') == 'Inter' ? 'selected' : '' ?>>Inter</option>
                                    <option value="Outfit" <?= ($page['font_family'] ?? '') == 'Outfit' ? 'selected' : '' ?>>Outfit</option>
                                    <option value="Roboto" <?= ($page['font_family'] ?? '') == 'Roboto' ? 'selected' : '' ?>>Roboto</option>
                                </select>
                            </div>

                            <div class="space-y-3">
                                <label class="text-[9px] font-black uppercase tracking-widest text-gray-500">Base Font Size</label>
                                <select name="font_size" class="w-full h-12 bg-background-dark border border-white/5 rounded-xl px-4 text-xs focus:border-primary outline-none transition-all appearance-none">
                                    <option value="small" <?= ($page['font_size'] ?? '') == 'small' ? 'selected' : '' ?>>Small</option>
                                    <option value="base" <?= ($page['font_size'] ?? 'base') == 'base' ? 'selected' : '' ?>>Normal (Default)</option>
                                    <option value="large" <?= ($page['font_size'] ?? '') == 'large' ? 'selected' : '' ?>>Large</option>
                                    <option value="xlarge" <?= ($page['font_size'] ?? '') == 'xlarge' ? 'selected' : '' ?>>Extra Large</option>
                                </select>
                            </div>

                            <div class="space-y-3">
                                <label class="text-[9px] font-black uppercase tracking-widest text-gray-500">Button Curvature</label>
                                <div class="grid grid-cols-4 gap-2">
                                    <?php 
                                    $shapes = [
                                        'rounded-none' => 'Sharp',
                                        'rounded-xl' => 'Soft',
                                        'rounded-2xl' => 'Round',
                                        'rounded-full' => 'Pill'
                                    ];
                                    foreach($shapes as $val => $label):
                                    ?>
                                        <label class="cursor-pointer group">
                                            <input type="radio" name="button_shape" value="<?= $val ?>" class="peer" hidden <?= ($page['button_shape'] ?? 'rounded-2xl') == $val ? 'checked' : '' ?> onchange="updateShapePreview('<?= $val ?>')">
                                            <div class="h-10 flex items-center justify-center text-[8px] font-black uppercase border border-white/5 rounded-xl text-gray-500 transition-all group-hover:bg-white/5 peer-checked:border-primary peer-checked:text-white peer-checked:bg-primary peer-checked:shadow-[0_0_15px_rgba(140,43,238,0.3)]"><?= $label ?></div>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Content Tab -->
                <div id="tab-content" class="tab-content space-y-8">
                    <div class="glass-card p-8">
                        <h4 class="text-sm font-black italic uppercase tracking-tighter mb-8 flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary">description</span> Public Content
                        </h4>
                        <div class="space-y-6">
                            <div class="space-y-1.5">
                                <label class="text-[9px] font-black uppercase tracking-widest text-gray-500 ml-1">About the Facility</label>
                                <textarea name="about_text" rows="5" class="w-full bg-background-dark border border-white/5 rounded-xl p-4 text-xs focus:border-primary outline-none transition-all"><?= htmlspecialchars($page['about_text'] ?? '') ?></textarea>
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-[9px] font-black uppercase tracking-widest text-gray-500 ml-1">Contact Details & Footnotes</label>
                                <textarea name="contact_text" rows="3" class="w-full bg-background-dark border border-white/5 rounded-xl p-4 text-xs focus:border-primary outline-none transition-all"><?= htmlspecialchars($page['contact_text'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- App Link Tab -->
                <div id="tab-app" class="tab-content space-y-8">
                    <div class="glass-card p-8">
                        <h4 class="text-sm font-black italic uppercase tracking-tighter mb-8 flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary">download</span> Digital Expansion
                        </h4>
                        <div class="space-y-1.5">
                            <label class="text-[9px] font-black uppercase tracking-widest text-gray-500 ml-1">Mobile App Download URL (Android APK)</label>
                            <input type="url" name="app_download_link" placeholder="https://drive.google.com/..." value="<?= htmlspecialchars($page['app_download_link'] ?? '') ?>" class="w-full h-12 bg-background-dark border border-white/5 rounded-xl px-4 text-xs focus:border-primary outline-none transition-all">
                            <p class="text-[10px] text-gray-500 italic mt-2">Connect your internal APK to allow direct downloads from your portal.</p>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <!-- Preview Section -->
        <div class="w-full xl:w-[400px] flex flex-col items-center">
            <div class="sticky top-10">
                <p class="text-[10px] font-black uppercase tracking-widest text-gray-500 mb-6 text-center">Real-Time Portal Preview</p>
                <div class="preview-toggle mb-6">
                    <button type="button" class="switch-btn active" onclick="switchPreview('login')">Login Screen</button>
                </div>

                <div class="mockup-container" id="mockup">
                    <div id="mockup-inner" class="mockup-inner <?= $page['theme_mode'] ?? 'dark' ?>" style="background: <?= ($page['theme_mode'] ?? 'dark') == 'light' ? 'white' : 'black' ?>; font-family: '<?= $page['font_family'] ?? 'Lexend' ?>', sans-serif;">
                        <!-- Login Preview -->
                        <div id="prev-login" class="login-preview active h-full">
                            <header class="preview-header flex flex-col items-center pt-10">
                                <div id="prev-logo-wrap" class="size-16 rounded-2xl bg-primary flex items-center justify-center mb-4">
                                    <?php if($logo_src): ?>
                                        <img id="prev-logo" src="<?= htmlspecialchars($logo_src) ?>" class="w-full h-full object-contain p-2">
                                    <?php else: ?>
                                        <span class="material-symbols-outlined text-white">bolt</span>
                                    <?php endif; ?>
                                </div>
                                <h3 id="prev-title" class="text-xl font-black italic uppercase tracking-tighter <?= ($page['theme_mode'] ?? 'dark') == 'light' ? 'text-black' : 'text-white' ?>">
                                    <?= htmlspecialchars($page['page_title'] ?? $gym['gym_name']) ?>
                                </h3>
                                <div class="h-0.5 w-10 bg-primary mt-3"></div>
                            </header>
                            
                            <main class="p-6 text-center space-y-6">
                                <h2 class="text-3xl font-black italic uppercase tracking-tight leading-none <?= ($page['theme_mode'] ?? 'dark') == 'light' ? 'text-black' : 'text-white' ?>">Build Your <span class="text-primary italic">Legacy</span></h2>
                                <p class="text-[10px] text-gray-500 font-medium px-4">Experience a modern multi-tenant fitness sanctuary.</p>
                                
                                <div class="space-y-3 pt-4">
                                    <div id="prev-btn-1" class="h-14 flex items-center justify-center text-[10px] font-black uppercase text-white shadow-lg <?= $page['button_shape'] ?? 'rounded-2xl' ?>" style="background: <?= $primary_color ?>;">Join The Community</div>
                                    <div id="prev-btn-2" class="h-14 flex items-center justify-center text-[10px] font-black uppercase border border-white/10 <?= ($page['theme_mode'] ?? 'dark') == 'light' ? 'text-black bg-black/5' : 'text-white bg-white/5' ?> <?= $page['button_shape'] ?? 'rounded-2xl' ?>" style="color: <?= $secondary_color ?>; border-color: <?= $secondary_color ?>44;">Staff Login</div>
                                </div>
                            </main>
                        </div>

                        <!-- Dashboard Preview -->
                        <div id="prev-dashboard" class="dashboard-preview h-full p-4">
                            <div class="flex items-center gap-3 mb-8">
                                <div class="size-8 rounded-lg bg-primary flex items-center justify-center shrink-0">
                                    <span class="material-symbols-outlined text-white text-[14px]">bolt</span>
                                </div>
                                <div class="h-2 w-24 bg-gray-500/20 rounded-full"></div>
                            </div>
                            <div class="grid grid-cols-2 gap-3 mb-6">
                                <div class="h-20 glass-card p-3 flex flex-col justify-between" style="border-radius: 12px; background: rgba(255,255,255,0.02);">
                                    <div class="h-1.5 w-8 bg-primary rounded-full"></div>
                                    <div class="h-2 w-12 bg-gray-500/20 rounded-full"></div>
                                </div>
                                <div class="h-20 glass-card p-3 flex flex-col justify-between" style="border-radius: 12px; background: rgba(255,255,255,0.02);">
                                    <div class="h-1.5 w-8 bg-emerald-500 rounded-full"></div>
                                    <div class="h-2 w-12 bg-gray-500/20 rounded-full"></div>
                                </div>
                            </div>
                            <div class="space-y-4">
                                <div class="h-32 glass-card p-4" style="border-radius: 16px; background: rgba(255,255,255,0.02);">
                                    <div id="dash-accent" class="h-1.5 w-20 bg-primary/20 rounded-full mb-3"></div>
                                    <div class="space-y-2">
                                        <div class="h-1.5 w-full bg-gray-500/10 rounded-full"></div>
                                        <div class="h-1.5 w-2/3 bg-gray-500/10 rounded-full"></div>
                                    </div>
                                </div>
                                <div id="dash-btn" class="h-10 w-full flex items-center justify-center text-[8px] font-black uppercase text-white <?= $page['button_shape'] ?? 'rounded-2xl' ?>" style="background: <?= $primary_color ?>;">Action Button</div>
                            </div>
                        </div>
                    </div>
                    <div class="absolute bottom-4 left-1/2 -translate-x-1/2 h-1 w-20 bg-white/20 rounded-full"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/image_viewer.php'; ?>

<script>
function switchTab(tabId) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
    
    document.getElementById('tab-' + tabId).classList.add('active');
    event.currentTarget.classList.add('active');
}

function updateColorPreview(val, type) {
    const mockup = document.getElementById('mockup-inner');
    if(type === 'primary') {
        mockup.querySelectorAll('.text-primary').forEach(el => el.style.color = val);
        mockup.querySelectorAll('[id^="prev-btn-1"]').forEach(el => el.style.backgroundColor = val);
        document.getElementById('dash-btn').style.backgroundColor = val; 
        mockup.querySelectorAll('[id^="prev-logo-wrap"]').forEach(el => el.style.backgroundColor = val);
        mockup.querySelectorAll('.bg-primary').forEach(el => el.style.backgroundColor = val);
    } else {
        mockup.querySelectorAll('[id^="prev-btn-2"]').forEach(el => {
            el.style.color = val;
            el.style.borderColor = val + '44';
        });
    }
    event.target.nextElementSibling.textContent = val.toUpperCase();
}

function updateModePreview(mode) {
    const mockup = document.getElementById('mockup-inner');
    const labels = mockup.querySelectorAll('h3, h2, #prev-btn-2');
    
    if(mode === 'light') {
        mockup.style.background = 'white';
        labels.forEach(el => {
            el.style.color = 'black';
            if(el.id === 'prev-btn-2') el.style.backgroundColor = 'rgba(0,0,0,0.05)';
        });
    } else {
        mockup.style.background = 'black';
        labels.forEach(el => {
            el.style.color = 'white';
            if(el.id === 'prev-btn-2') el.style.backgroundColor = 'rgba(255,255,255,0.05)';
        });
    }
}

function updateFontPreview(font) {
    document.getElementById('mockup-inner').style.fontFamily = font + ', sans-serif';
}

function updateShapePreview(shape) {
    document.getElementById('prev-btn-1').className = 'h-14 flex items-center justify-center text-[10px] font-black uppercase text-white shadow-lg ' + shape;
    document.getElementById('prev-btn-2').className = 'h-14 flex items-center justify-center text-[10px] font-black uppercase border border-white/10 ' + shape;
    document.getElementById('dash-btn').className = 'h-10 w-full flex items-center justify-center text-[8px] font-black uppercase text-white ' + shape;
}

function switchPreview(type) {
    document.querySelectorAll('.switch-btn').forEach(btn => btn.classList.remove('active'));
    event.currentTarget.classList.add('active');

    if(type === 'login') {
        document.getElementById('prev-login').classList.add('active');
        document.getElementById('prev-dashboard').classList.remove('active');
    } else {
        document.getElementById('prev-login').classList.remove('active');
        document.getElementById('prev-dashboard').classList.add('active');
    }
}

// Live Text Sync
document.querySelector('input[name="page_title"]').oninput = function(e) {
    document.getElementById('prev-title').textContent = e.target.value;
};

function previewImage(input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            // Main editor preview
            let preview = document.getElementById('logo-preview');
            if(!preview) {
                preview = document.createElement('img');
                preview.id = 'logo-preview';
                preview.className = 'w-full h-full object-contain';
                document.getElementById('logo-placeholder').replaceWith(preview);
            }
            preview.src = e.target.result;

            // Mockup preview
            let prevLogoWrap = document.getElementById('prev-logo-wrap');
            let prevLogo = document.getElementById('prev-logo');
            if(!prevLogo) {
                prevLogo = document.createElement('img');
                prevLogo.id = 'prev-logo';
                prevLogo.className = 'w-full h-full object-contain p-2';
                prevLogoWrap.innerHTML = '';
                prevLogoWrap.appendChild(prevLogo);
            }
            prevLogo.src = e.target.result;
        }
        reader.readAsDataURL(input.files[0]);
    }
}
</script>

</body>
</html>
