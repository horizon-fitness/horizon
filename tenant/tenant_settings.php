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
    $page_title = $_POST['page_title'];
    $theme_color = $_POST['theme_color'];
    $about_text = $_POST['about_text'];
    $contact_text = $_POST['contact_text'];
    $app_link = $_POST['app_download_link'];
    $now = date('Y-m-d H:i:s');

    // Handle Logo Upload
    $logo_path = $page['logo_path'] ?? '';
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] == 0) {
        $uploadDir = '../uploads/cms/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        $fileName = time() . '_' . basename($_FILES['logo']['name']);
        $targetPath = $uploadDir . $fileName;
        if (move_uploaded_file($_FILES['logo']['tmp_name'], $targetPath)) {
            $logo_path = 'uploads/cms/' . $fileName;
        }
    }

    try {
        if ($page) {
            $stmtUpdate = $pdo->prepare("UPDATE tenant_pages SET page_slug = ?, page_title = ?, logo_path = ?, theme_color = ?, about_text = ?, contact_text = ?, app_download_link = ?, updated_at = ? WHERE gym_id = ?");
            $stmtUpdate->execute([$page_slug, $page_title, $logo_path, $theme_color, $about_text, $contact_text, $app_link, $now, $gym_id]);
        } else {
            $stmtInsert = $pdo->prepare("INSERT INTO tenant_pages (gym_id, page_slug, page_title, logo_path, theme_color, about_text, contact_text, app_download_link, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtInsert->execute([$gym_id, $page_slug, $page_title, $logo_path, $theme_color, $about_text, $contact_text, $app_link, $now]);
        }
        $_SESSION['success_msg'] = "Portal settings updated successfully!";
        header("Location: tenant_settings.php");
        exit;
    } catch (Exception $e) {
        $error = "Update failed: " . $e->getMessage();
    }
}

$page_title = "CMS Customization";
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
    
    <div class="flex flex-col gap-5 flex-1 overflow-y-auto pr-2">
        <a href="tenant_dashboard.php" class="nav-link flex items-center gap-3 <?= ($active_page == 'dashboard') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl">dashboard</span> Dashboard
        </a>
        <a href="tenant_settings.php" class="nav-link flex items-center gap-3 <?= ($active_page == 'settings') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
            <span class="material-symbols-outlined text-xl">palette</span> CMS Customization
        </a>
        <a href="#" class="nav-link flex items-center gap-3 text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl">group</span> Staff Management
        </a>
        <a href="#" class="nav-link flex items-center gap-3 text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl">person_search</span> Member Directory
        </a>
        <div class="mt-auto pt-8 border-t border-white/10">
            <a href="../logout.php" class="text-gray-400 hover:text-red-500 transition-colors flex items-center gap-3 group">
                <span class="material-symbols-outlined group-hover:translate-x-1 transition-transform">logout</span>
                <span class="nav-link">Sign Out</span>
            </a>
        </div>
    </div>
</nav>

<div class="flex-1 p-10 max-w-[1200px] w-full mx-auto overflow-y-auto">
    <header class="mb-10 flex justify-between items-end">
        <div>
            <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white">CMS <span class="text-primary">Settings</span></h2>
            <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-1">Customize your gym's public landing portal</p>
        </div>
        <a target="_blank" href="../portal.php?gym=<?= htmlspecialchars($page['page_slug'] ?? '') ?>" class="h-10 px-6 rounded-xl bg-primary/10 text-primary border border-primary/20 hover:bg-primary hover:text-white transition-all text-[9px] font-black uppercase tracking-widest flex items-center gap-2">
           <span class="material-symbols-outlined text-xs">visibility</span> Preview Portal
        </a>
    </header>

    <?php if (isset($_SESSION['success_msg'])): ?>
        <div class="mb-8 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-xs font-bold uppercase tracking-widest italic">
            <?= $_SESSION['success_msg']; unset($_SESSION['success_msg']); ?>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <div class="lg:col-span-2 space-y-8">
            <div class="glass-card p-8">
                <h4 class="text-sm font-black italic uppercase tracking-tighter mb-6 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary">id_card</span> Basic Information
                </h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="space-y-1.5">
                        <label class="text-[9px] font-black uppercase tracking-widest text-gray-500 ml-1">Portal Page Title</label>
                        <input type="text" name="page_title" required value="<?= htmlspecialchars($page['page_title'] ?? $gym['gym_name']) ?>" class="w-full h-12 bg-background-dark border border-white/5 rounded-xl px-4 text-xs focus:border-primary outline-none transition-all">
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-[9px] font-black uppercase tracking-widest text-gray-500 ml-1">Portal URL Slug</label>
                        <div class="relative">
                            <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-600 text-[10px] font-bold">portal.php?gym=</span>
                            <input type="text" name="page_slug" required value="<?= htmlspecialchars($page['page_slug'] ?? '') ?>" class="w-full h-12 bg-background-dark border border-white/5 rounded-xl pl-[88px] pr-4 text-xs focus:border-primary outline-none transition-all">
                        </div>
                    </div>
                </div>
            </div>

            <div class="glass-card p-8">
                <h4 class="text-sm font-black italic uppercase tracking-tighter mb-6 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary">description</span> Public Content
                </h4>
                <div class="space-y-6">
                    <div class="space-y-1.5">
                        <label class="text-[9px] font-black uppercase tracking-widest text-gray-500 ml-1">About Us Description</label>
                        <textarea name="about_text" rows="4" class="w-full bg-background-dark border border-white/5 rounded-xl p-4 text-xs focus:border-primary outline-none transition-all"><?= htmlspecialchars($page['about_text'] ?? '') ?></textarea>
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-[9px] font-black uppercase tracking-widest text-gray-500 ml-1">Contact Details (Footer)</label>
                        <textarea name="contact_text" rows="3" class="w-full bg-background-dark border border-white/5 rounded-xl p-4 text-xs focus:border-primary outline-none transition-all"><?= htmlspecialchars($page['contact_text'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>

            <div class="glass-card p-8">
                <h4 class="text-sm font-black italic uppercase tracking-tighter mb-6 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary">download</span> App Distribution
                </h4>
                <div class="space-y-1.5">
                    <label class="text-[9px] font-black uppercase tracking-widest text-gray-500 ml-1">Mobile App APK Link</label>
                    <input type="url" name="app_download_link" placeholder="https://drive.google.com/..." value="<?= htmlspecialchars($page['app_download_link'] ?? '') ?>" class="w-full h-12 bg-background-dark border border-white/5 rounded-xl px-4 text-xs focus:border-primary outline-none transition-all">
                    <p class="text-[9px] text-gray-600 italic">Provide the direct link where your staff and members can download the Android APK.</p>
                </div>
            </div>
        </div>

        <div class="space-y-8">
            <div class="glass-card p-8">
                <h4 class="text-sm font-black italic uppercase tracking-tighter mb-6 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary">brush</span> Branding
                </h4>
                <div class="space-y-6 text-center">
                    <div class="space-y-3">
                        <label class="text-[9px] font-black uppercase tracking-widest text-gray-500">Gym Logo</label>
                        <div class="size-32 mx-auto rounded-3xl bg-background-dark border-2 border-dashed border-white/5 flex items-center justify-center overflow-hidden relative group">
                            <?php if($page && $page['logo_path']): ?>
                                <img id="logo-preview" src="../<?= htmlspecialchars($page['logo_path']) ?>" class="w-full h-full object-contain">
                            <?php else: ?>
                                <span id="logo-placeholder" class="material-symbols-outlined text-3xl text-gray-800">add_photo_alternate</span>
                            <?php endif; ?>
                            <input type="file" name="logo" accept="image/*" class="absolute inset-0 opacity-0 cursor-pointer" onchange="previewImage(this)">
                        </div>
                        <p class="text-[9px] text-gray-600 italic">Click to upload (Transparent PNG recommended)</p>
                    </div>

                    <div class="space-y-3 pt-4 border-t border-white/5">
                        <label class="text-[9px] font-black uppercase tracking-widest text-gray-500">Theme Primary Color</label>
                        <div class="flex items-center gap-4 bg-background-dark p-2 rounded-xl">
                            <input type="color" name="theme_color" value="<?= htmlspecialchars($page['theme_color'] ?? '#8c2bee') ?>" class="size-10 rounded-lg cursor-pointer bg-transparent border-none">
                            <span class="text-xs font-black italic uppercase"><?= htmlspecialchars($page['theme_color'] ?? '#8c2bee') ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <button type="submit" class="w-full h-16 rounded-2xl bg-primary hover:bg-primary-hover shadow-lg shadow-primary/20 transition-all text-xs font-black uppercase tracking-[0.2em] flex items-center justify-center gap-3">
                <span class="material-symbols-outlined">save</span> Save All Settings
            </button>
        </div>
    </form>
</div>

<script>
function previewImage(input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            let preview = document.getElementById('logo-preview');
            if(!preview) {
                preview = document.createElement('img');
                preview.id = 'logo-preview';
                preview.className = 'w-full h-full object-contain';
                document.getElementById('logo-placeholder').replaceWith(preview);
            }
            preview.src = e.target.result;
        }
        reader.readAsDataURL(input.files[0]);
    }
}
</script>

</body>
</html>
