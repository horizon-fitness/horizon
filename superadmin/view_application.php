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
           r.first_name as reviewer_first, r.last_name as reviewer_last
    FROM gym_owner_applications a
    JOIN users u ON a.user_id = u.user_id
    JOIN gym_addresses ad ON a.address_id = ad.address_id
    LEFT JOIN users r ON a.reviewed_by = r.user_id
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

$page_title = "Application Details: " . $app['gym_name'];
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
                <?php foreach ($documents as $doc): ?>
                    <div class="group relative bg-white/5 border border-white/5 rounded-2xl overflow-hidden aspect-video">
                        <img src="<?= htmlspecialchars($doc['file_path']) ?>" alt="<?= htmlspecialchars($doc['document_type']) ?>" class="w-full h-full object-cover opacity-60 group-hover:opacity-100 transition-opacity viewable">
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
