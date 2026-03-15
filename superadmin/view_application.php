<?php
session_start();
require_once '../db.php';

// Security Check: Only Superadmin can access
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'superadmin') {
    header("Location: ../login.php");
    exit;
}

if (!isset($_GET['id'])) {
    header("Location: superadmin_dashboard.php");
    exit;
}

$app_id = (int)$_GET['id'];

// Fetch detailed application data
$stmt = $pdo->prepare("
    SELECT a.*, 
           u.first_name, u.middle_name, u.last_name, u.email as owner_email, u.contact_number as owner_contact,
           ad.address_line, ad.barangay, ad.city, ad.province, ad.region,
           r.first_name as reviewer_first, r.last_name as reviewer_last,
           g.gym_id -- Get gym ID if it exists
    FROM gym_owner_applications a
    JOIN users u ON a.user_id = u.user_id
    JOIN gym_addresses ad ON a.address_id = ad.address_id
    LEFT JOIN users r ON a.reviewed_by = r.user_id
    LEFT JOIN gyms g ON a.application_id = g.application_id
    WHERE a.application_id = ?
");
$stmt->execute([$app_id]);
$app = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$app) {
    die("Application not found.");
}

// Fetch documents
$stmtDocs = $pdo->prepare("SELECT * FROM application_documents WHERE application_id = ?");
$stmtDocs->execute([$app_id]);
$documents = $stmtDocs->fetchAll(PDO::FETCH_ASSOC);

// Formatting Helper
$friendlyNames = [
    'sole_proprietorship' => 'Sole Proprietorship',
    'partnership' => 'Partnership',
    'corporation' => 'Corporation',
    'passport' => 'Passport',
    'drivers_license' => "Driver's License",
    'national_id' => 'National ID',
    'tin_id' => 'TIN ID',
    'deduct_from_payout' => 'Deduct from Payout',
    'bill_separately' => 'Bill Separately'
];

function formatLabel($key, $map) {
    return $map[$key] ?? ucwords(str_replace('_', ' ', $key));
}

$is_ajax = isset($_GET['ajax']);
$page_title = "Application Details: " . $app['gym_name'];

if ($is_ajax): ?>
    <!-- AJAX Modal Content Only -->
    <div class="space-y-8 max-h-[80vh] overflow-y-auto px-1 pr-3 custom-scrollbar">
        <header class="flex justify-between items-start border-b border-white/5 pb-6">
            <div>
                <h2 class="text-2xl font-black italic uppercase tracking-tighter text-white"><?= htmlspecialchars($app['gym_name']) ?></h2>
                <div class="flex items-center gap-3 mt-2">
                    <?php if ($app['application_status'] === 'Pending'): ?>
                        <span class="px-2 py-0.5 rounded-full bg-amber-500/10 border border-amber-500/20 text-[9px] text-amber-500 font-bold uppercase tracking-wider">Pending</span>
                    <?php elseif ($app['application_status'] === 'Approved'): ?>
                        <span class="px-2 py-0.5 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-[9px] text-emerald-500 font-bold uppercase tracking-wider">Approved</span>
                    <?php else: ?>
                        <span class="px-2 py-0.5 rounded-full bg-red-500/10 border border-red-500/20 text-[9px] text-red-500 font-bold uppercase tracking-wider">Rejected</span>
                    <?php endif; ?>
                    <span class="text-[9px] text-gray-500 font-bold uppercase tracking-wider italic">Submitted: <?= date('M d, Y', strtotime($app['submitted_at'])) ?></span>
                </div>
            </div>
            <button onclick="closeApplicationModal()" class="size-8 rounded-full bg-white/5 hover:bg-white/10 flex items-center justify-center text-gray-400 group transition-all">
                <span class="material-symbols-outlined text-xl group-hover:rotate-90 transition-transform">close</span>
            </button>
        </header>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-12 gap-y-8">
            <!-- Owner Column -->
            <div class="space-y-6">
                <h4 class="text-[10px] font-black uppercase text-primary tracking-[0.2em] border-l-2 border-primary pl-3">Owner Contact</h4>
                <div class="grid grid-cols-1 gap-4">
                    <div class="bg-white/[0.02] p-4 rounded-2xl border border-white/5">
                        <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-1">Owner Name</p>
                        <p class="text-sm font-bold text-white"><?= htmlspecialchars($app['first_name'] . ' ' . $app['middle_name'] . ' ' . $app['last_name']) ?></p>
                    </div>
                    <div class="bg-white/[0.02] p-4 rounded-2xl border border-white/5">
                        <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-1">Email / Contact</p>
                        <p class="text-sm font-bold text-white"><?= htmlspecialchars($app['owner_email']) ?></p>
                        <p class="text-[11px] text-gray-400 mt-1 font-medium"><?= htmlspecialchars($app['owner_contact']) ?></p>
                    </div>
                    <div class="bg-white/[0.02] p-4 rounded-2xl border border-white/5">
                        <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-1">Identity Verification</p>
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary text-sm">badge</span>
                            <p class="text-sm font-bold text-white"><?= formatLabel($app['owner_valid_id_type'], $friendlyNames) ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Business Column -->
            <div class="space-y-6">
                <h4 class="text-[10px] font-black uppercase text-primary tracking-[0.2em] border-l-2 border-primary pl-3">Business Profile</h4>
                <div class="grid grid-cols-1 gap-4">
                    <div class="bg-white/[0.02] p-4 rounded-2xl border border-white/5">
                        <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-1">Legal Entity / Type</p>
                        <p class="text-sm font-bold text-white"><?= htmlspecialchars($app['business_name']) ?></p>
                        <p class="text-[11px] text-primary mt-1 font-black uppercase italic"><?= formatLabel($app['business_type'], $friendlyNames) ?></p>
                    </div>
                    <div class="bg-white/[0.02] p-4 rounded-2xl border border-white/5">
                        <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-1">Facility Address</p>
                        <p class="text-sm font-bold text-white leading-relaxed">
                            <?= htmlspecialchars($app['address_line']) ?><br>
                            <span class="text-[11px] text-gray-400 font-medium">
                                <?= htmlspecialchars($app['barangay'] . ', ' . $app['city'] . ', ' . $app['province']) ?>
                            </span>
                        </p>
                    </div>
                    <div class="bg-white/[0.02] p-4 rounded-2xl border border-white/5">
                        <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-1">Tax / Registration</p>
                        <div class="flex justify-between items-center">
                            <div>
                                <p class="text-[8px] text-gray-500 uppercase font-black">TIN</p>
                                <p class="text-xs font-bold"><?= htmlspecialchars($app['bir_number']) ?></p>
                            </div>
                            <div class="text-right">
                                <p class="text-[8px] text-gray-500 uppercase font-black">BP No.</p>
                                <p class="text-xs font-bold"><?= htmlspecialchars($app['business_permit_no']) ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Financial & Facility Section -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
            <div class="bg-primary/5 border border-primary/10 rounded-2xl p-6">
                <h4 class="text-[9px] font-black uppercase text-primary tracking-widest mb-4">Payout Information</h4>
                <div class="space-y-3">
                    <div class="flex justify-between">
                        <span class="text-[10px] text-gray-400 uppercase font-bold">Bank Name</span>
                        <span class="text-xs font-bold text-white"><?= htmlspecialchars($app['bank_name'] ?: 'N/A') ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-[10px] text-gray-400 uppercase font-bold">Account</span>
                        <span class="text-xs font-bold text-white"><?= htmlspecialchars($app['account_number'] ?: 'N/A') ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-[10px] text-gray-400 uppercase font-bold">Billing</span>
                        <span class="text-[10px] font-black uppercase text-primary italic"><?= formatLabel($app['platform_fee_preference'], $friendlyNames) ?></span>
                    </div>
                </div>
            </div>
            
            <div class="bg-white/[0.02] border border-white/5 rounded-2xl p-6">
                <h4 class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-4">Facility Capacity</h4>
                <div class="flex items-end gap-2">
                    <p class="text-3xl font-black italic text-white leading-none"><?= htmlspecialchars($app['max_capacity'] ?: '0') ?></p>
                    <p class="text-[10px] font-black uppercase text-gray-600 pb-1 italic">Maximum Pax</p>
                </div>
                <div class="flex gap-2 mt-4">
                    <span class="px-2 py-1 rounded bg-white/5 text-[9px] font-bold uppercase tracking-tighter <?= $app['has_shower'] ? 'text-primary' : 'text-gray-600 line-through' ?>">Shower</span>
                    <span class="px-2 py-1 rounded bg-white/5 text-[9px] font-bold uppercase tracking-tighter <?= $app['has_parking'] ? 'text-primary' : 'text-gray-600 line-through' ?>">Parking</span>
                    <span class="px-2 py-1 rounded bg-white/5 text-[9px] font-bold uppercase tracking-tighter <?= $app['has_wifi'] ? 'text-primary' : 'text-gray-600 line-through' ?>">Wi-Fi</span>
                </div>
            </div>
        </div>

        <!-- Documents -->
        <div>
            <h4 class="text-[10px] font-black uppercase text-primary tracking-[0.2em] border-l-2 border-primary pl-3 mb-4">Attachments</h4>
            <div class="grid grid-cols-3 gap-4">
                <?php foreach ($documents as $doc): 
                    $docPath = $doc['file_path'];
                    if (!str_starts_with($docPath, 'data:image')) {
                        if (str_starts_with($docPath, 'uploads/')) {
                            $docPath = '../' . $docPath;
                        } elseif (!str_starts_with($docPath, '../') && !str_starts_with($docPath, 'http')) {
                            $docPath = '../uploads/applications/' . $docPath;
                        }
                    }
                ?>
                    <div class="group relative bg-white/5 border border-white/5 rounded-xl overflow-hidden aspect-video cursor-zoom-in modal-img-preview" data-src="<?= htmlspecialchars($docPath) ?>">
                        <img src="<?= htmlspecialchars($docPath) ?>" class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-500 opacity-60 group-hover:opacity-100">
                        <div class="absolute inset-0 bg-black/40 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
                             <span class="material-symbols-outlined text-white">zoom_in</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($app['application_status'] === 'Pending'): ?>
            <div class="flex gap-3 pt-6 border-t border-white/5">
                <form method="POST" action="../action/process_application.php" class="flex-1">
                    <input type="hidden" name="application_id" value="<?= $app_id ?>">
                    <button type="submit" name="action" value="approve" class="w-full py-3 rounded-xl bg-emerald-500 hover:bg-emerald-600 text-white text-[10px] font-black uppercase tracking-widest shadow-lg shadow-emerald-500/20 transition-all">
                        Approve Now
                    </button>
                </form>
                <form method="POST" action="../action/process_application.php" class="flex-1">
                    <input type="hidden" name="application_id" value="<?= $app_id ?>">
                    <button type="submit" name="action" value="reject" class="w-full py-3 rounded-xl bg-red-500/10 hover:bg-red-500/20 border border-red-500/20 text-red-500 text-[10px] font-black uppercase tracking-widest transition-all">
                        Reject
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>
<?php 
    exit;
endif;
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title><?= $page_title ?> | Herdoza Fitness</title>
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
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: #0a090d; }
        ::-webkit-scrollbar-thumb { background: #14121a; border-radius: 10px; }
    </style>
</head>
<body class="antialiased min-h-screen p-6 md:p-10">

    <div class="max-w-4xl mx-auto">
        <a href="javascript:history.back()" class="inline-flex items-center gap-2 text-gray-500 hover:text-white transition-colors mb-8 group">
            <span class="material-symbols-outlined transition-transform group-hover:-translate-x-1">arrow_back</span>
            <span class="text-xs font-black uppercase tracking-widest">Back to Management</span>
        </a>

        <header class="mb-10 flex flex-col md:flex-row justify-between items-start md:items-end gap-6">
            <div>
                <div class="flex items-center gap-3 mb-2">
                    <span class="px-3 py-1 rounded-full bg-primary/10 border border-primary/20 text-[10px] text-primary font-black uppercase italic tracking-widest">Application Details</span>
                    <?php if ($app['application_status'] === 'Pending'): ?>
                        <span class="px-3 py-1 rounded-full bg-amber-500/10 border border-amber-500/20 text-[10px] text-amber-500 font-black uppercase italic tracking-widest">Status: Pending</span>
                    <?php elseif ($app['application_status'] === 'Approved'): ?>
                        <span class="px-3 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-[10px] text-emerald-500 font-black uppercase italic tracking-widest">Status: Approved</span>
                    <?php else: ?>
                        <span class="px-3 py-1 rounded-full bg-red-500/10 border border-red-500/20 text-[10px] text-red-500 font-black uppercase italic tracking-widest">Status: Rejected</span>
                    <?php endif; ?>
                </div>
                <h2 class="text-4xl font-black italic uppercase tracking-tighter text-white"><?= htmlspecialchars($app['gym_name']) ?></h2>
                <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-1">Submitted on <?= date('M d, Y h:i A', strtotime($app['submitted_at'])) ?></p>
            </div>
        </header>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-10">
            <!-- Owner Information -->
            <div class="glass-card p-8">
                <h3 class="text-sm font-black italic uppercase tracking-widest mb-6 text-primary border-b border-white/5 pb-4">Owner Profile</h3>
                <div class="space-y-4">
                    <div>
                        <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Full Name</p>
                        <p class="text-sm font-bold"><?= htmlspecialchars($app['first_name'] . ' ' . $app['middle_name'] . ' ' . $app['last_name']) ?></p>
                    </div>
                    <div>
                        <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Email Address</p>
                        <p class="text-sm font-bold"><?= htmlspecialchars($app['owner_email']) ?></p>
                    </div>
                    <div>
                        <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Contact Number</p>
                        <p class="text-sm font-bold"><?= htmlspecialchars($app['owner_contact']) ?></p>
                    </div>
                </div>
            </div>

            <!-- Business Information -->
            <div class="glass-card p-8">
                <h3 class="text-sm font-black italic uppercase tracking-widest mb-6 text-primary border-b border-white/5 pb-4">Business Details</h3>
                <div class="space-y-4">
                    <div>
                        <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Registered Entity Name</p>
                        <p class="text-sm font-bold"><?= htmlspecialchars($app['business_name']) ?></p>
                    </div>
                    <div>
                        <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Business Type</p>
                        <p class="text-sm font-bold"><?= htmlspecialchars($app['business_type']) ?></p>
                    </div>
                    <div>
                        <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Address</p>
                        <p class="text-sm font-bold"><?= htmlspecialchars($app['address_line'] . ', ' . $app['barangay'] . ', ' . $app['city'] . ', ' . $app['province']) ?></p>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($subscription): ?>
        <!-- Subscription Information -->
        <div class="glass-card p-8 mb-10 border border-primary/20 bg-primary/5">
            <h3 class="text-sm font-black italic uppercase tracking-widest mb-6 text-primary border-b border-white/5 pb-4 flex items-center gap-2">
                <span class="material-symbols-outlined">card_membership</span>
                Gym Subscription
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                <div>
                    <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Current Plan</p>
                    <p class="text-lg font-black italic uppercase text-white"><?= htmlspecialchars($subscription['plan_name']) ?></p>
                    <p class="text-[10px] text-primary font-bold uppercase tracking-widest mt-1">₱<?= number_format($subscription['price'], 2) ?> / <?= htmlspecialchars($subscription['billing_cycle']) ?></p>
                </div>
                <div>
                    <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Status</p>
                    <?php if ($subscription['subscription_status'] === 'Active'): ?>
                        <span class="px-3 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-[10px] text-emerald-500 font-black uppercase italic tracking-widest">Active</span>
                    <?php else: ?>
                        <span class="px-3 py-1 rounded-full bg-red-500/10 border border-red-500/20 text-[10px] text-red-500 font-black uppercase italic tracking-widest"><?= htmlspecialchars($subscription['subscription_status']) ?></span>
                    <?php endif; ?>
                </div>
                <div>
                    <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Start Date</p>
                    <p class="text-sm font-bold"><?= date('M d, Y', strtotime($subscription['start_date'])) ?></p>
                </div>
                <div>
                    <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">End Date</p>
                    <p class="text-sm font-bold"><?= $subscription['end_date'] ? date('M d, Y', strtotime($subscription['end_date'])) : 'N/A' ?></p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Compliance and Remarks -->
        <div class="glass-card p-8 mb-10">
            <h3 class="text-sm font-black italic uppercase tracking-widest mb-6 text-primary border-b border-white/5 pb-4">Compliance Information</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                <div>
                    <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">BIR Number</p>
                    <p class="text-sm font-bold"><?= htmlspecialchars($app['bir_number']) ?></p>
                </div>
                <div>
                    <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Business Permit No.</p>
                    <p class="text-sm font-bold"><?= htmlspecialchars($app['business_permit_no']) ?></p>
                </div>
                <div>
                    <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Valid ID Type</p>
                    <p class="text-sm font-bold"><?= htmlspecialchars($app['owner_valid_id_type']) ?></p>
                </div>
            </div>
            <div>
                <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-2">Remarks / Facility Metadata</p>
                <pre class="text-xs text-gray-400 bg-white/5 p-4 rounded-xl border border-white/5 font-sans whitespace-pre-wrap"><?= htmlspecialchars($app['remarks']) ?></pre>
            </div>
        </div>

        <!-- Uploaded Documents -->
        <div class="glass-card p-8 mb-10">
            <h3 class="text-sm font-black italic uppercase tracking-widest mb-6 text-primary border-b border-white/5 pb-4">Uploaded Documents</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                <?php foreach ($documents as $doc): 
                    $docPath = $doc['file_path'];
                    // Logic to ensure path is correct
                    // 1. If it's Base64 (starts with data:image), use as is.
                    // 2. If it's a legacy file path (starts with uploads/), fix relative path.
                    // 3. If it's just a filename, assume legacy location.
                    if (!str_starts_with($docPath, 'data:image')) {
                        if (str_starts_with($docPath, 'uploads/')) {
                            $docPath = '../' . $docPath;
                        } elseif (!str_starts_with($docPath, '../') && !str_starts_with($docPath, 'http')) {
                            $docPath = '../uploads/applications/' . $docPath;
                        }
                    }
                ?>
                    <div class="group relative bg-white/5 border border-white/5 rounded-2xl overflow-hidden aspect-video">
                        <img src="<?= htmlspecialchars($docPath) ?>" alt="<?= htmlspecialchars($doc['document_type']) ?>" class="w-full h-full object-cover opacity-60 group-hover:opacity-100 transition-opacity viewable">
                        <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-transparent to-transparent flex flex-col justify-end p-4 pointer-events-none">
                            <p class="text-[10px] font-black uppercase text-primary tracking-widest"><?= htmlspecialchars($doc['document_type']) ?></p>
                            <p class="text-[10px] font-bold text-white mt-1">Click to expand</p>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($documents)): ?>
                    <p class="text-xs italic text-gray-500 uppercase font-black">No documents uploaded.</p>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($app['application_status'] === 'Pending'): ?>
            <div class="flex flex-col sm:flex-row gap-4">
                <form method="POST" action="../action/process_application.php" class="flex-1">
                    <input type="hidden" name="application_id" value="<?= $app_id ?>">
                    <button type="submit" name="action" value="approve" class="w-full py-4 rounded-2xl bg-emerald-500 hover:bg-emerald-600 text-white font-black italic uppercase tracking-widest shadow-lg shadow-emerald-500/20 transition-all flex items-center justify-center gap-2">
                        <span class="material-symbols-outlined">verified</span> Approve Application
                    </button>
                </form>
                <form method="POST" action="../action/process_application.php" class="flex-1">
                    <input type="hidden" name="application_id" value="<?= $app_id ?>">
                    <button type="submit" name="action" value="reject" class="w-full py-4 rounded-2xl bg-red-500/10 hover:bg-red-500/20 border border-red-500/20 text-red-500 font-black italic uppercase tracking-widest transition-all flex items-center justify-center gap-2">
                        <span class="material-symbols-outlined">cancel</span> Reject
                    </button>
                </form>
            </div>
        <?php else: ?>
            <div class="glass-card p-6 border-white/10 bg-white/5 flex items-center justify-between">
                <div>
                    <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Reviewed By</p>
                    <p class="text-sm font-black italic uppercase"><?= htmlspecialchars($app['reviewer_first'] . ' ' . $app['reviewer_last']) ?></p>
                </div>
                <div class="text-right">
                    <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Reviewed At</p>
                    <p class="text-sm font-black italic uppercase"><?= date('M d, Y h:i A', strtotime($app['reviewed_at'])) ?></p>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <?php include '../includes/image_viewer.php'; ?>
</body>
</html>
